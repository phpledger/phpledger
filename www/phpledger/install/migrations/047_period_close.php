<?php
declare(strict_types=1);

/**
 * 1.3 M17, issue #93: the period-close checklist, reversing journals and cash counts.
 *
 * Three things, and nothing here is a second copy of something that already exists.
 *
 * 1. THE CHECKLIST. Period close was never greenfield: pl_periods, pl_period_actions and the
 *    rule that a closed period refuses postings have been there since 001 and 007. What was
 *    missing is the list an accountant works through before signing. Core computes most of the
 *    items from the books themselves, so they are code and not rows; what needs a table is
 *    (a) the free items a company adds, (b) the hard/soft severity of any item, which is a
 *    judgement a business is allowed to make for itself, and (c) the ticks.
 *
 *    Every tick is also written to pl_period_actions, which carries the immutability triggers
 *    from 007 — so the audit trail the issue asks for is the log that already exists, and
 *    pl_period_checklist_ticks is only the current state. The action ENUM therefore gains
 *    'tick' and 'untick' and, because MySQL rewrites the whole column, the MODIFY below
 *    carries the union of 007's three values and these two.
 *
 * 2. REVERSING JOURNALS. Owner decision B89: there is no concurrency worker and none is built
 *    here. A journal carries `reverse_on`; the linked reversal posts when the period holding
 *    that date is OPENED, or synchronously as the date is recorded if that period is already
 *    open. Both are explicit acts by a person at a transaction boundary, so both are
 *    attributable. The idempotency is the one that already exists — ledger_functions.php looks
 *    up reversal_of_id FOR UPDATE before creating a reversal — so no key column is added and
 *    no second reversal service is written.
 *
 *    `pl_journal_reversal_events` is the receipt. It is a company-scoped immutable table of its
 *    own, the way 041 and 044 did it, rather than three more values in pl_core_audit's
 *    entity_type ENUM: that ENUM is the one thing three parallel branches collide on, and this
 *    change needs none of it. The reversal itself is found by the ordinary join every other
 *    reader in this tree uses (pl_journals r ON r.reversal_of_id = j.id); no denormalised
 *    "reversal id" column is added that could ever disagree with it.
 *
 * 3. CASH COUNTS. One document, for an account carrying the cash_bank role — which is the only
 *    money role this schema has; there is no separate `cash` role and no separate `bank` role.
 *    The counted amount is compared with the book balance at a moment and the difference is
 *    posted through pl_post_journal() to the over-and-short account seeded below. A count that
 *    agrees posts nothing and is still recorded, because "we counted and it agreed" is the
 *    evidence a close needs.
 *
 * 4. THE OVER-AND-SHORT ACCOUNT. Seeded into the current chart package and back-filled here.
 *    Following B88 it is found by `semantic_key` — `core.expense.cash_over_short`, a leaf key
 *    and never `core.group.*` — and it carries NO `role`: pl_save_account() only accepts the
 *    eight roles core_functions.php declares, and a heading or an unknown role would make the
 *    account unsaveable from the chart screen. The back-fill derives its code from whatever
 *    account already carries `core.expense.general`, so it lands in that book's own operating
 *    expense group whatever number 036 gave it, and both the code and the key are guarded with
 *    NOT EXISTS so an upgrade cannot die on uq_account_semantic.
 */

return [
    // ------------------------------------------------------------ checklist: company items
    // A free item a company adds. `item_key` shares one namespace with the computed items so a
    // tick is keyed the same way whatever produced the item; 'core.' is reserved below.
    <<<'SQL'
CREATE TABLE pl_period_checklist_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    book_id BIGINT UNSIGNED NOT NULL,
    item_key VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    label VARCHAR(200) NOT NULL,
    severity ENUM('hard','soft') NOT NULL DEFAULT 'soft',
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_period_item (book_id, item_key),
    CONSTRAINT ck_period_item_key CHECK (item_key NOT LIKE 'core.%'),
    CONSTRAINT ck_period_item_flags CHECK (is_active IN (0,1)),
    CONSTRAINT fk_period_item_book FOREIGN KEY (book_id, company_id) REFERENCES pl_books (id, company_id),
    CONSTRAINT fk_period_item_actor FOREIGN KEY (created_by) REFERENCES pl_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,

    // ------------------------------------------------------- checklist: severity overrides
    // Whether an item blocks a close or only warns is a policy, not a fact about the books, and
    // the issue does not settle it for the computed items. Keeping it as data means a business
    // (or an accountant reviewing this) changes a setting rather than the code, and the defaults
    // in period_close_functions.php are exactly that: defaults.
    <<<'SQL'
CREATE TABLE pl_period_checklist_settings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    book_id BIGINT UNSIGNED NOT NULL,
    item_key VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    severity ENUM('hard','soft') NOT NULL,
    set_by BIGINT UNSIGNED NOT NULL,
    set_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_period_item_severity (book_id, item_key),
    CONSTRAINT fk_period_severity_book FOREIGN KEY (book_id, company_id) REFERENCES pl_books (id, company_id),
    CONSTRAINT fk_period_severity_actor FOREIGN KEY (set_by) REFERENCES pl_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,

    // ------------------------------------------------------------------- checklist: ticks
    // The current state only. Every write here is also a row in pl_period_actions, which 007
    // made immutable with triggers, so "who ticked what, when and why" survives a retick.
    // A reopen does not touch this table: the issue says reopen keeps the ticks.
    <<<'SQL'
CREATE TABLE pl_period_checklist_ticks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    book_id BIGINT UNSIGNED NOT NULL,
    period_id BIGINT UNSIGNED NOT NULL,
    item_key VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    state ENUM('done','skipped') NOT NULL,
    reason VARCHAR(500) NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    ticked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_period_tick (period_id, item_key),
    KEY ix_period_tick_book (book_id, period_id),
    CONSTRAINT fk_period_tick_period FOREIGN KEY (period_id, company_id, book_id) REFERENCES pl_periods (id, company_id, book_id),
    CONSTRAINT fk_period_tick_actor FOREIGN KEY (actor_id) REFERENCES pl_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,

    // A tick is a period action, so the 007 ENUM gains two values. MySQL rewrites the whole
    // column on a MODIFY, so this list is the union of 007's three and these two; leaving one
    // out would silently truncate every existing row that held it.
    "ALTER TABLE pl_period_actions MODIFY COLUMN action ENUM('create','close','reopen','tick','untick') NOT NULL",

    // ---------------------------------------------------------------- reversing journals
    // `reverse_on` is recorded against a posted journal by pl_schedule_journal_reversal(). It is
    // deliberately NOT part of the journal payload: pl_normalize_journal() builds the canonical
    // array that becomes payload_hash, and adding a key to it would change the hash of every
    // journal in every book and break the upgrade verifiers' baselines for no gain.
    <<<'SQL'
CREATE TABLE pl_journal_reversal_schedules (
    journal_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    book_id BIGINT UNSIGNED NOT NULL,
    reverse_on DATE NULL,
    KEY ix_reversal_schedule_due (book_id, reverse_on),
    CONSTRAINT fk_reversal_schedule_journal FOREIGN KEY (journal_id, company_id, book_id) REFERENCES pl_journals (id, company_id, book_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,

    // The receipt. Append-only, with triggers below; `event` says what happened and
    // `trigger_source` says which of B89's two paths did it.
    <<<'SQL'
CREATE TABLE pl_journal_reversal_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    book_id BIGINT UNSIGNED NOT NULL,
    journal_id BIGINT UNSIGNED NOT NULL,
    event ENUM('scheduled','cleared','posted') NOT NULL,
    trigger_source ENUM('schedule','period_open','period_reopen') NOT NULL,
    reverse_on DATE NULL,
    period_id BIGINT UNSIGNED NULL,
    reversal_journal_id BIGINT UNSIGNED NULL,
    reason VARCHAR(500) NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_reversal_events_journal (book_id, journal_id, id),
    CONSTRAINT fk_reversal_event_journal FOREIGN KEY (journal_id, company_id, book_id) REFERENCES pl_journals (id, company_id, book_id),
    CONSTRAINT fk_reversal_event_reversal FOREIGN KEY (reversal_journal_id, company_id, book_id) REFERENCES pl_journals (id, company_id, book_id),
    CONSTRAINT fk_reversal_event_actor FOREIGN KEY (actor_id) REFERENCES pl_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
    "CREATE TRIGGER pl_journal_reversal_events_no_update BEFORE UPDATE ON pl_journal_reversal_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A reversal receipt is immutable'",
    "CREATE TRIGGER pl_journal_reversal_events_no_delete BEFORE DELETE ON pl_journal_reversal_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A reversal receipt is immutable'",

    // ------------------------------------------------------------------------ cash counts
    // `counted_at` is the moment the drawer was counted and is what the book balance is taken
    // at; `count_date` is the posting date of the difference, which must fall in an open
    // period like every other posting. They are usually the same day and are separate because
    // a drawer counted at 23:50 is often posted on the day it belongs to.
    <<<'SQL'
CREATE TABLE pl_cash_counts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    book_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    count_date DATE NOT NULL,
    counted_at DATETIME NOT NULL,
    counted_amount DECIMAL(20,4) NOT NULL,
    book_balance DECIMAL(20,4) NOT NULL,
    difference DECIMAL(20,4) NOT NULL,
    by_denomination BOOLEAN NOT NULL DEFAULT FALSE,
    note VARCHAR(500) NOT NULL,
    journal_id BIGINT UNSIGNED NULL,
    creation_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    counted_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cash_count_key (book_id, creation_key),
    UNIQUE KEY uq_cash_count_journal (journal_id),
    UNIQUE KEY uq_cash_count_scope (id, company_id, book_id),
    KEY ix_cash_count_history (book_id, account_id, count_date, id),
    CONSTRAINT ck_cash_count_flags CHECK (by_denomination IN (0,1)),
    CONSTRAINT ck_cash_count_signs CHECK (counted_amount >= 0),
    CONSTRAINT fk_cash_count_book FOREIGN KEY (book_id, company_id) REFERENCES pl_books (id, company_id),
    CONSTRAINT fk_cash_count_account FOREIGN KEY (account_id, company_id, book_id) REFERENCES pl_accounts (id, company_id, book_id),
    CONSTRAINT fk_cash_count_journal FOREIGN KEY (journal_id, company_id, book_id) REFERENCES pl_journals (id, company_id, book_id),
    CONSTRAINT fk_cash_count_actor FOREIGN KEY (counted_by) REFERENCES pl_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
    // Optional. A count may be a single figure; when it is counted by denomination these rows
    // are the working and their extended amounts must sum to counted_amount, which the service
    // proves in bcmath before the row above is written.
    <<<'SQL'
CREATE TABLE pl_cash_count_lines (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    cash_count_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    book_id BIGINT UNSIGNED NOT NULL,
    line_number SMALLINT UNSIGNED NOT NULL,
    denomination DECIMAL(20,4) NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    amount DECIMAL(20,4) NOT NULL,
    UNIQUE KEY uq_cash_count_line (cash_count_id, line_number),
    CONSTRAINT ck_cash_count_line CHECK (denomination > 0 AND quantity > 0 AND amount > 0),
    CONSTRAINT fk_cash_count_line_count FOREIGN KEY (cash_count_id, company_id, book_id) REFERENCES pl_cash_counts (id, company_id, book_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
    // A recorded count is evidence. Correcting one means recording another; the difference of
    // the first stays posted and is reversed through the ordinary linked reversal.
    "CREATE TRIGGER pl_cash_counts_no_update BEFORE UPDATE ON pl_cash_counts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A recorded cash count is immutable'",
    "CREATE TRIGGER pl_cash_counts_no_delete BEFORE DELETE ON pl_cash_counts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A recorded cash count is immutable'",
    "CREATE TRIGGER pl_cash_count_lines_no_update BEFORE UPDATE ON pl_cash_count_lines FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A recorded cash count is immutable'",
    "CREATE TRIGGER pl_cash_count_lines_no_delete BEFORE DELETE ON pl_cash_count_lines FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A recorded cash count is immutable'",

    // ------------------------------------------------------- the over-and-short account
    // Derived from whatever account carries core.expense.general in each book, so it lands in
    // that book's own operating-expense group whatever number 036 allocated. Account segment
    // 10002 beside the 10001 the starter purposes use. Both NOT EXISTS clauses matter: the key
    // one because uq_account_semantic (book_id, semantic_key) would refuse a second insert on
    // an upgrade re-run, the code one because a hand-built chart may already hold that number.
    // A book where neither can be satisfied simply gets no account, and
    // pl_cash_over_short_account() then refuses BY NAME rather than guessing somewhere to post.
    // `creation_key` marks the row the way 045 marked its headings, so the rows this migration
    // wrote can be told from the ones the chart package installs, and
    // uq_account_creation (book_id, creation_key) makes a re-run a no-op on its own.
    <<<'SQL'
INSERT INTO pl_accounts (company_id, book_id, code, name, type, role, semantic_key, is_active, is_contra, creation_key)
SELECT g.company_id, g.book_id, CONCAT(LEFT(g.code, 5), '-10002-00'),
       'Cash over and short', g.type, NULL, 'core.expense.cash_over_short', 1, 0, 'migration-047-cash-over-short'
FROM pl_accounts g
WHERE g.semantic_key = 'core.expense.general'
  AND g.code LIKE '_-___-_____-__'
  AND g.code NOT LIKE '_-___-00000-__'
  AND NOT EXISTS (SELECT 1 FROM pl_accounts x WHERE x.book_id = g.book_id AND x.semantic_key = 'core.expense.cash_over_short')
  AND NOT EXISTS (SELECT 1 FROM pl_accounts y WHERE y.book_id = g.book_id AND y.code = CONCAT(LEFT(g.code, 5), '-10002-00'))
SQL,
];
