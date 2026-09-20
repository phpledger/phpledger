<?php
declare(strict_types=1);

/**
 * Structured account codes (B56, issue #76), contra marking (B60) and the
 * owner/partner register (B61, A3).
 *
 * The conversion rewrites every account's `code` into the `X-XXX-XXXXX-XX`
 * shape using the chart's *current* groups: the first two characters of the
 * legacy number are the group, so accounts that shared a group before still
 * share one afterwards. The old number is kept twice — on the account row as
 * `legacy_code` and in the immutable `pl_account_code_map` — so it can never
 * be lost. Nothing else about an account changes: the primary key, type, role,
 * currency, status and every posted journal line stay exactly as they were.
 *
 * Group numbers are allocated 100, 110, 120 … inside each class, leaving the
 * 900 band of every class reserved for the contra groups B60 requires. The
 * staging table's CHECK refuses a chart wide enough to reach the reserved
 * band rather than silently colliding with it.
 *
 * Reversal path: `UPDATE pl_accounts a JOIN pl_account_code_map m ON
 * m.account_id = a.id SET a.code = m.legacy_code` restores the old numbers;
 * the map row records what each account was converted from.
 */

$statements = [];

// 1. Account-row columns. `legacy_code` is the number this chart used before.
$statements[] = "ALTER TABLE pl_accounts
    ADD legacy_code VARCHAR(20) NULL,
    ADD is_contra TINYINT(1) NOT NULL DEFAULT 0,
    ADD CONSTRAINT ck_account_contra CHECK (is_contra IN (0,1))";

// 2. The immutable mapping. One row per account, written once, never changed.
$statements[] = <<<'SQL'
CREATE TABLE pl_account_code_map (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    book_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    legacy_code VARCHAR(20) NOT NULL,
    structured_code VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    converted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_account_code_map_account (account_id),
    UNIQUE KEY uq_account_code_map_structured (book_id, structured_code),
    KEY ix_account_code_map_legacy (book_id, legacy_code),
    CONSTRAINT fk_account_code_map_account FOREIGN KEY (account_id, company_id, book_id) REFERENCES pl_accounts (id, company_id, book_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL;

$statements[] = "CREATE TRIGGER pl_account_code_map_no_update BEFORE UPDATE ON pl_account_code_map FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The account code mapping is immutable'";
$statements[] = "CREATE TRIGGER pl_account_code_map_no_delete BEFORE DELETE ON pl_account_code_map FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The account code mapping cannot be deleted'";

// 3. Staging tables. Kept as real tables so an interrupted migration can be
//    resumed at the statement that failed; both are dropped at the end.
$statements[] = <<<'SQL'
CREATE TABLE pl_account_code_conversion (
    account_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    book_id BIGINT UNSIGNED NOT NULL,
    legacy_code VARCHAR(20) NOT NULL,
    class TINYINT UNSIGNED NOT NULL,
    group_key VARCHAR(20) NOT NULL,
    group_no SMALLINT UNSIGNED NULL,
    account_no INT UNSIGNED NULL,
    structured_code VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL,
    CONSTRAINT ck_account_conversion_group CHECK (group_no IS NULL OR (group_no >= 100 AND group_no <= 899)),
    CONSTRAINT ck_account_conversion_account CHECK (account_no IS NULL OR (account_no >= 10001 AND account_no <= 99999))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL;

$statements[] = <<<'SQL'
CREATE TABLE pl_account_code_allocation (
    account_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    group_no SMALLINT UNSIGNED NULL,
    account_no INT UNSIGNED NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL;

// 4. One staging row per existing account. The group key is the chart's own
//    group: the first two characters of the number it already uses.
$statements[] = <<<'SQL'
INSERT INTO pl_account_code_conversion (account_id, company_id, book_id, legacy_code, class, group_key)
SELECT a.id, a.company_id, a.book_id, a.code,
    CASE a.type WHEN 'asset' THEN 1 WHEN 'liability' THEN 2 WHEN 'equity' THEN 3 WHEN 'income' THEN 4 ELSE 5 END,
    UPPER(LEFT(a.code, 2))
FROM pl_accounts a
WHERE a.code NOT LIKE '_-___-_____-__'
SQL;

// 5. Group numbers: 100, 110, 120 … per book and class, in legacy-code order.
$statements[] = <<<'SQL'
INSERT INTO pl_account_code_allocation (account_id, group_no)
SELECT c.account_id, g.group_no
FROM pl_account_code_conversion c
JOIN (
    SELECT book_id, class, group_key,
        100 + 10 * (ROW_NUMBER() OVER (PARTITION BY book_id, class ORDER BY group_key) - 1) AS group_no
    FROM (SELECT DISTINCT book_id, class, group_key FROM pl_account_code_conversion) d
) g ON g.book_id = c.book_id AND g.class = c.class AND g.group_key = c.group_key
SQL;

$statements[] = "UPDATE pl_account_code_conversion c JOIN pl_account_code_allocation g ON g.account_id = c.account_id SET c.group_no = g.group_no";

// 6. Account numbers: 10001 upward inside each group, in legacy-code order.
$statements[] = <<<'SQL'
UPDATE pl_account_code_allocation a
JOIN (
    SELECT account_id,
        10000 + ROW_NUMBER() OVER (PARTITION BY book_id, class, group_no ORDER BY legacy_code, account_id) AS account_no
    FROM pl_account_code_conversion
) n ON n.account_id = a.account_id
SET a.account_no = n.account_no
SQL;

$statements[] = "UPDATE pl_account_code_conversion c JOIN pl_account_code_allocation a ON a.account_id = c.account_id SET c.account_no = a.account_no";

// 7. Compose the code. Every converted account is a leaf, sub-account 00.
$statements[] = "UPDATE pl_account_code_conversion SET structured_code = CONCAT(class, '-', LPAD(group_no, 3, '0'), '-', LPAD(account_no, 5, '0'), '-00')";

// 8. Record the mapping before the chart is touched.
$statements[] = <<<'SQL'
INSERT INTO pl_account_code_map (company_id, book_id, account_id, legacy_code, structured_code)
SELECT company_id, book_id, account_id, legacy_code, structured_code FROM pl_account_code_conversion
SQL;

// 9. Apply. Identity, type, role, currency, status and postings are untouched.
$statements[] = <<<'SQL'
UPDATE pl_accounts a
JOIN pl_account_code_conversion c ON c.account_id = a.id
SET a.legacy_code = c.legacy_code, a.code = c.structured_code
SQL;

$statements[] = "DROP TABLE pl_account_code_allocation";
$statements[] = "DROP TABLE pl_account_code_conversion";

// 10. Owner and partner register (B61, A3). Capital and drawings accounts are
//     ordinary chart accounts; this table only records who they belong to and
//     the partner's profit-sharing ratio. Profit *allocation* is deliberately
//     not posted here — see docs/accounting/OWNER-TRANSACTIONS.md.
$statements[] = <<<'SQL'
CREATE TABLE pl_owner_partners (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    book_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    capital_account_id BIGINT UNSIGNED NOT NULL,
    drawings_account_id BIGINT UNSIGNED NULL,
    loan_account_id BIGINT UNSIGNED NULL,
    profit_share DECIMAL(9,6) NOT NULL DEFAULT 0.000000,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_owner_partner_name (book_id, name),
    UNIQUE KEY uq_owner_partner_capital (capital_account_id),
    KEY ix_owner_partner_book (company_id, book_id, is_active),
    CONSTRAINT ck_owner_partner_share CHECK (profit_share >= 0 AND profit_share <= 1),
    CONSTRAINT fk_owner_partner_book FOREIGN KEY (book_id, company_id) REFERENCES pl_books (id, company_id),
    CONSTRAINT fk_owner_partner_capital FOREIGN KEY (capital_account_id, company_id, book_id) REFERENCES pl_accounts (id, company_id, book_id),
    CONSTRAINT fk_owner_partner_drawings FOREIGN KEY (drawings_account_id, company_id, book_id) REFERENCES pl_accounts (id, company_id, book_id),
    CONSTRAINT fk_owner_partner_loan FOREIGN KEY (loan_account_id, company_id, book_id) REFERENCES pl_accounts (id, company_id, book_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL;

return $statements;
