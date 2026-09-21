<?php
declare(strict_types=1);

/**
 * Advances, refunds and orphan credit notes (1.2 M5; owner decisions B39, B57, B59).
 *
 * Unapplied customer money is **not** a credit balance sitting inside receivables and
 * it is **not** a suspense account (B59). It is an advance received from that customer:
 * an ordinary liability control account with the new `customer_advances` role, tracked
 * as its own open item so the money keeps a party, a currency, a frozen rate and an
 * audit trail, exactly like an invoice does. The supplier mirror, `supplier_advances`,
 * is an asset: money paid to a supplier before their bill arrives.
 *
 * Four schema facts are introduced here.
 *
 * 1. `pl_advance_accounts` — the registry of advances control accounts. A control is
 *    registered once, by the posting service, after it has checked the account's role,
 *    type and book. The registry exists so the tie in point 2 can be a real foreign key
 *    rather than a trigger: a MySQL/MariaDB CHECK cannot contain a subquery, so it
 *    cannot look at `pl_accounts.role` by itself.
 *
 * 2. `pl_open_items.nature` — nullable, `document` or `advance`. NULL means `document`
 *    and is what every row written before this migration keeps; nothing is rewritten.
 *    `advance_control_account_id` is the FK half of the tie: a CHECK requires it to be
 *    present exactly when the item is an advance, and to equal `control_account_id`,
 *    while its foreign key requires that account to be a registered advances control.
 *    Together: an advance open item can only sit on an advances control, and a document
 *    open item can never sit on one.
 *
 *    `direction` is deliberately NOT extended. It is read arithmetically everywhere
 *    (`receivable` = debit-normal, `payable` = credit-normal) — in ageing, in the
 *    settlement plan, in the batch validator and in `pl_open_item_state()`. A customer
 *    advance is credit-normal, so it is a `payable` item on the customer-advances
 *    control; a supplier advance is debit-normal, so it is a `receivable` item on the
 *    supplier-advances control. Every existing sum keeps working unchanged, and
 *    `nature` is what distinguishes "we owe this customer money" from "this supplier
 *    has billed us".
 *
 * 3. Two new entry kinds, `application` and `application_reversal`, on
 *    `pl_open_item_entries`. Applying unapplied credit to an invoice moves money
 *    between two controls with no bank line at all, so it is not a settlement; it needs
 *    its own kind so that B7's reversal rules can tell an application apart from a
 *    receipt. Arithmetically `application` behaves like `allocation` and
 *    `application_reversal` like `allocation_reversal`.
 *
 * 4. The two control accounts themselves, added to every existing book that does not
 *    have them, in the next free group of their class (the bundled starter chart in
 *    `resources/coa/core-starter-1.1.0.json` carries the same two accounts, so a book
 *    created on 1.2 is born with them). `is_monetary` is 1 for both, matching the
 *    role-driven mapping migration 013 established and `pl_currency_account_properties()`
 *    now extends.
 *
 * Reversal path: `DELETE FROM pl_advance_accounts` then drop the two columns and the
 * two accounts, provided no advance open item exists. Once an advance has been
 * recognised the postings are immutable like any other, and the way back is the ordinary
 * one — reverse the application, then refund or reverse the advance recognition.
 */

$statements = [];

// 1. The registry of advances control accounts. Written by the posting service only.
$statements[] = <<<'SQL'
CREATE TABLE pl_advance_accounts (
    account_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    book_id BIGINT UNSIGNED NOT NULL,
    side ENUM('customer','supplier') NOT NULL,
    registered_by BIGINT UNSIGNED NOT NULL,
    reason VARCHAR(500) NOT NULL,
    registered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_advance_account_scope (account_id, company_id, book_id),
    KEY ix_advance_account_book (company_id, book_id, side),
    CONSTRAINT fk_advance_account FOREIGN KEY (account_id, company_id, book_id) REFERENCES pl_accounts (id, company_id, book_id),
    CONSTRAINT fk_advance_account_actor FOREIGN KEY (registered_by) REFERENCES pl_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL;

foreach (['UPDATE', 'DELETE'] as $action) {
    $statements[] = 'CREATE TRIGGER pl_advance_accounts_no_' . strtolower($action)
        . " BEFORE {$action} ON pl_advance_accounts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'An advances control registration is permanent'";
}

// 2. Nature, and the CHECK + FK pair that ties an advance to an advances control.
$statements[] = "ALTER TABLE pl_open_items
    ADD nature ENUM('document','advance') NULL,
    ADD advance_control_account_id BIGINT UNSIGNED NULL,
    ADD CONSTRAINT ck_open_item_advance_control CHECK (
        (COALESCE(nature, 'document') = 'advance') = (advance_control_account_id IS NOT NULL)
        AND (advance_control_account_id IS NULL OR advance_control_account_id = control_account_id)
    ),
    ADD CONSTRAINT fk_open_item_advance_control FOREIGN KEY (advance_control_account_id, company_id, book_id)
        REFERENCES pl_advance_accounts (account_id, company_id, book_id)";

// 2b. The CHECK above says an advance is on an advances control. The other half of the
//     rule — that a document item is *never* on one — needs to look at another table,
//     which a MySQL/MariaDB CHECK may not do, so it is a BEFORE INSERT trigger. Together
//     they make "advance" and "on an advances control" the same statement, in the
//     database, whatever posts the row.
$statements[] = <<<'SQL'
CREATE TRIGGER pl_open_items_nature_control BEFORE INSERT ON pl_open_items FOR EACH ROW
BEGIN
    IF (COALESCE(NEW.nature, 'document') = 'advance')
        <> EXISTS (SELECT 1 FROM pl_advance_accounts WHERE account_id = NEW.control_account_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unapplied credit belongs on an advances control account, and a document never does';
    END IF;
END
SQL;

// 3. Application and its reversal. Existing rows keep their kind unchanged.
$statements[] = "ALTER TABLE pl_open_item_entries MODIFY kind
    ENUM('recognition','allocation','recognition_reversal','allocation_reversal','application','application_reversal') NOT NULL";

// 4. The two control accounts in every existing book that lacks them. The group number
//    is the next free ten-step below the 900 contra band (B60) of that class, so a book
//    converted by migration 036 and a book born from the bundled chart both land on a
//    free code. A book that already has an account with the role or the semantic key is
//    left alone, which also makes re-running this statement a no-op.
foreach ([
    ['customer_advances', 'liability', 2, 'core.liability.customer_advances', 'Customer advances (unapplied credit)'],
    ['supplier_advances', 'asset', 1, 'core.asset.supplier_advances', 'Supplier advances (prepayments)'],
] as [$role, $type, $class, $semanticKey, $name]) {
    $statements[] = <<<SQL
INSERT INTO pl_accounts (company_id, book_id, code, name, type, role, semantic_key, is_active, is_contra, is_monetary)
SELECT b.company_id, b.id,
    CONCAT('{$class}-', LPAD(COALESCE((
        SELECT MAX(CAST(SUBSTRING(x.code, 3, 3) AS UNSIGNED))
        FROM pl_accounts x
        WHERE x.book_id = b.id AND x.type = '{$type}'
          AND x.code LIKE '_-___-_____-__'
          AND CAST(SUBSTRING(x.code, 3, 3) AS UNSIGNED) < 900
    ), 90) + 10, 3, '0'), '-10001-00'),
    '{$name}', '{$type}', '{$role}', '{$semanticKey}', 1, 0, 1
FROM pl_books b
WHERE NOT EXISTS (SELECT 1 FROM pl_accounts a WHERE a.book_id = b.id AND a.role = '{$role}')
  AND NOT EXISTS (SELECT 1 FROM pl_accounts a WHERE a.book_id = b.id AND a.semantic_key = '{$semanticKey}')
  AND COALESCE((
        SELECT MAX(CAST(SUBSTRING(x.code, 3, 3) AS UNSIGNED))
        FROM pl_accounts x
        WHERE x.book_id = b.id AND x.type = '{$type}'
          AND x.code LIKE '_-___-_____-__'
          AND CAST(SUBSTRING(x.code, 3, 3) AS UNSIGNED) < 900
    ), 90) + 10 <= 890
SQL;
}

// 5. The role-driven monetary mapping migration 013 set up. Both new roles are monetary:
//    they are claims and obligations settled in a fixed number of currency units.
$statements[] = "UPDATE pl_accounts SET is_monetary = 1 WHERE role IN ('customer_advances','supplier_advances') AND is_monetary IS NULL";

return $statements;
