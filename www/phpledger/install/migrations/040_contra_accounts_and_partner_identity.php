<?php
declare(strict_types=1);

/**
 * Contra accounts on a converted chart, and one account per partner per role
 * (internal accounting review of 1.2, findings 4 and 5).
 *
 * Migration 036 is **not** edited. It is already applied in development and
 * test databases and the migration runner pins a content checksum receipt in
 * `pl_schema_migrations`, so changing it would refuse every existing database
 * with "Migration checksum mismatch". This migration is the follow-up.
 *
 * 1. `is_contra` from `semantic_key`, where one is present.
 *
 *    036 added `is_contra TINYINT(1) NOT NULL DEFAULT 0` and never set it for
 *    an existing row, while the bundled starter chart carries it on 1-900,
 *    3-900, 4-900 and 5-900. A book born on 1.2 is therefore right and a book
 *    upgraded to 1.2 is not: `pl_owner_accounts()` classifies an equity account
 *    as drawings only when `is_contra` is true, so a converted chart has no
 *    drawings account at all and drawings cannot be recorded; the chart's own
 *    "Drawings" account instead appears among the capital candidates; and
 *    accumulated depreciation, sales returns and purchase returns lose their
 *    deduction presentation in `pl_balance_sheet()` and `pl_profit_loss()`.
 *
 *    `semantic_key` is not a guess. It is written in exactly two places -
 *    `pl_create_company()` from the bundled starter template, and
 *    `pl_confirm_existing_setup()` where the owner maps each starter purpose
 *    onto an existing account - and `uq_account_semantic (book_id,
 *    semantic_key)` keeps one account per purpose per book. The four keys
 *    below are the four the template marks contra; `pl_contra_semantic_keys()`
 *    reads the same four from the template at runtime and
 *    `tests/owner_test.php` asserts the two lists agree, so this frozen SQL
 *    cannot drift from the chart it was derived from. Nothing is derived from
 *    an account's *name*: the project forbids that, and it is exactly how a
 *    "Drawings" heading or a "Drawings - vehicle" expense would be miscarried.
 *
 * 2. The accounts with no semantic key are asked about, not guessed.
 *
 *    An account the owner added carries no key, so nothing deterministic can
 *    be said about it. `pl_contra_confirmations` holds one row per **converted**
 *    book - a book with rows in `pl_account_code_map`, which is written only by
 *    036's conversion - and `pl_contra_require_confirmed()` refuses the owner
 *    services and the owner screen while that row is `pending`, the way
 *    `pl_require_book_ready()` refuses posting while an opening cutover is
 *    unfinished. A book born on 1.2 was never converted, gets no row, and is
 *    never gated. Answering the step is `pl_confirm_contra_accounts()`, which
 *    writes the ordinary `pl_core_audit` row per account it changes.
 *
 *    Rejected alternatives, per owner decision B53:
 *      - *Default the whole chart to no contra accounts and say nothing.* That
 *        is the current behaviour and it is the defect: drawings cannot be
 *        recorded and every accumulated-depreciation presentation is silently
 *        wrong.
 *      - *Infer from the account name* ("Drawings", "Accumulated depreciation",
 *        "Sales returns"). Rejected: `AGENTS.md` forbids guessing missing
 *        accounting data, the words differ by language and by book, and a
 *        wrong guess changes how a balance is presented with nobody asked.
 *      - *Infer from the 900 band 036 reserved.* Rejected: 036 allocates
 *        converted groups strictly in 100-899, so no converted account is ever
 *        in the reserved band; the band would find nothing.
 *      - *Refuse the whole upgrade until answered.* Rejected: the presentation
 *        attribute affects owner transactions and report presentation, not the
 *        ledger, so holding invoicing and receipts hostage to it is out of
 *        proportion. The gate is on the screens that actually depend on it.
 *
 * 3. One account per partner per role.
 *
 *    `pl_owner_partners` was unique on `capital_account_id` only, so two
 *    partners could name one drawings or loan account, and
 *    `pl_owner_partner_positions()` reads a position by account balance: each
 *    partner was shown the whole of a shared account. Two partners sharing one
 *    drawings account carrying 40,000 were each reported with 40,000 of
 *    drawings, so the partners' statement showed 80,000 against 40,000 posted.
 *    The equity total stays right, but the partner-level figure is the basis of
 *    settlement between partners (Partnership Act 1932, s.13 and s.4). The two
 *    unique keys below carry the rule per column; the cross-role half - one
 *    partner's capital account being another's drawings account - spans three
 *    columns and every row, which no MySQL constraint can express, so
 *    `pl_owner_require_own_accounts()` enforces it under the book lock.
 *
 *    If an existing database already shares an account between two partners,
 *    the ALTER below stops with a duplicate-key error naming the index. That is
 *    the correct outcome: which partner owns the account is an accounting fact
 *    the operator holds and the software must not choose. Clear it by giving
 *    each partner their own account and re-pointing the partner record, then
 *    run the migration again. 1.2 has not been released, so no supported
 *    upgrade path can contain one.
 *
 * Reversal path, in full:
 *
 *   UPDATE pl_contra_confirmations SET status='pending', answer_json=NULL,
 *       request_key=NULL, payload_hash=NULL, reason=NULL, confirmed_by=NULL,
 *       confirmed_at=NULL WHERE book_id = ?;            -- reopen one book's step
 *   DELETE FROM pl_contra_confirmations;                -- remove the step entirely
 *   UPDATE pl_accounts SET is_contra = 0 WHERE semantic_key IN (the four keys);
 *   ALTER TABLE pl_owner_partners DROP INDEX uq_owner_partner_drawings,
 *       DROP INDEX uq_owner_partner_loan;
 *   DROP TABLE pl_contra_confirmations;
 *
 * Accounts marked by the confirm step are listed in that book's `answer_json`,
 * so undoing a reviewed answer is `UPDATE pl_accounts SET is_contra = 0 WHERE
 * id IN (...)` over exactly the ids it recorded. Contra marking is presentation
 * only: no journal line, balance or open item is touched anywhere above.
 */

$statements = [];

// 1. The four contra purposes the bundled starter chart defines. Frozen here on
//    purpose: a migration's statements must not change with a resource file.
$contraKeys = [
    'core.asset.accumulated_depreciation',
    'core.equity.drawings',
    'core.expense.purchase_returns',
    'core.income.sales_returns',
];
$statements[] = "UPDATE pl_accounts SET is_contra = 1
    WHERE is_contra = 0 AND semantic_key IN ('" . implode("','", $contraKeys) . "')";

// 2. One account per partner per role. Both columns are nullable and MySQL
//    allows repeated NULLs in a unique key, so a partner with no drawings or no
//    loan account is unaffected.
$statements[] = 'ALTER TABLE pl_owner_partners
    ADD UNIQUE KEY uq_owner_partner_drawings (drawings_account_id),
    ADD UNIQUE KEY uq_owner_partner_loan (loan_account_id)';

// 3. The reviewed answer, one row per converted book.
$statements[] = <<<'SQL'
CREATE TABLE pl_contra_confirmations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    book_id BIGINT UNSIGNED NOT NULL,
    status ENUM('pending','confirmed') NOT NULL DEFAULT 'pending',
    derived_contra_count INT UNSIGNED NOT NULL DEFAULT 0,
    candidate_count INT UNSIGNED NOT NULL DEFAULT 0,
    answer_json JSON NULL,
    reason VARCHAR(500) NULL,
    request_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
    payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    confirmed_by BIGINT UNSIGNED NULL,
    confirmed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_contra_confirmation_book (company_id, book_id),
    CONSTRAINT ck_contra_confirmation_answer CHECK (
        (status = 'pending' AND answer_json IS NULL AND confirmed_by IS NULL AND confirmed_at IS NULL)
        OR (status = 'confirmed' AND answer_json IS NOT NULL AND confirmed_by IS NOT NULL AND confirmed_at IS NOT NULL)
    ),
    CONSTRAINT fk_contra_confirmation_book FOREIGN KEY (book_id, company_id) REFERENCES pl_books (id, company_id),
    CONSTRAINT fk_contra_confirmation_actor FOREIGN KEY (confirmed_by) REFERENCES pl_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL;

// A recorded answer cannot be edited afterwards. Reopening the step is allowed
// and is the documented reversal: it must clear the answer with it, so a
// confirmed answer can never be quietly rewritten in place.
$statements[] = <<<'SQL'
CREATE TRIGGER pl_contra_confirmations_answer_immutable BEFORE UPDATE ON pl_contra_confirmations FOR EACH ROW
BEGIN
    IF OLD.status = 'confirmed' AND NOT (NEW.status = 'pending' AND NEW.answer_json IS NULL
        AND NEW.confirmed_by IS NULL AND NEW.confirmed_at IS NULL) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A recorded contra-account confirmation cannot be edited. Reopen the step, or change an account in the chart of accounts.';
    END IF;
END
SQL;

// 4. One pending row per converted book. `pl_account_code_map` is written only
//    by 036's conversion, so a book born on 1.2 has none and is never gated.
//    The two counts are what the step will show: how many accounts carry a
//    contra purpose from statement 1, and how many keyless accounts are left
//    for the reviewer to decide about.
$statements[] = <<<'SQL'
INSERT INTO pl_contra_confirmations (company_id, book_id, status, derived_contra_count, candidate_count)
SELECT b.company_id, b.id, 'pending',
    (SELECT COUNT(*) FROM pl_accounts a WHERE a.company_id = b.company_id AND a.book_id = b.id AND a.is_contra = 1),
    (SELECT COUNT(*) FROM pl_accounts a WHERE a.company_id = b.company_id AND a.book_id = b.id
        AND a.is_active = 1 AND a.type <> 'liability' AND a.is_contra = 0
        AND (a.semantic_key IS NULL OR a.semantic_key = ''))
FROM pl_books b
WHERE EXISTS (SELECT 1 FROM pl_account_code_map m WHERE m.company_id = b.company_id AND m.book_id = b.id)
SQL;

return $statements;
