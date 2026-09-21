<?php
declare(strict_types=1);

/**
 * Fixed-asset register, depreciation and disposal (1.3 M14; issue #95).
 *
 * The register is a subsidiary ledger. It does not hold balances of its own: every
 * figure it reports is the sum of `pl_asset_events` rows, and every event row is
 * backed by exactly one journal posted through `pl_post_journal()`. That is the whole
 * reconciliation design, and it is why the event amounts are **signed** rather than
 * kept as separate "additions" and "disposals" columns:
 *
 *     cost of an asset         = SUM(cost_amount)          over its events
 *     accumulated depreciation = SUM(depreciation_amount)  over its events
 *     net book value           = cost - accumulated
 *
 * An acquisition writes `cost_amount = +cost`; a depreciation charge writes
 * `depreciation_amount = +charge`; a disposal writes `cost_amount = -cost` and
 * `depreciation_amount = -accumulated`, which is exactly what its journal does to the
 * two control accounts. The register therefore equals the ledger by construction, and
 * `pl_asset_register()` proves it per control account rather than asserting it.
 *
 * Five schema facts are introduced here.
 *
 * 1. `pl_asset_accounts` — the module's own four account pointers per book, keyed by a
 *    `purpose` this module owns. It is deliberately **not** `pl_accounts.semantic_key`:
 *    that column is written in exactly two places (`pl_create_company()` from the
 *    bundled starter template, and `pl_confirm_existing_setup()` where an owner maps a
 *    prior chart onto the starter purposes), and migration 040 depends on that being
 *    true. An optional module must not add a third writer, and it must not add a
 *    fourteenth starter purpose that every converted book would then have to map before
 *    it could finish its review. The accumulated-depreciation pointer still *prefers*
 *    the chart's own `core.asset.accumulated_depreciation` account when the book has
 *    one — it reads that key, it never writes it.
 *
 * 2. `pl_asset_classes` — the depreciation policy. Method, useful life and the four
 *    accounts a class posts to. The accumulated-depreciation account of a class must be
 *    a contra asset: `pl_save_asset_class()` refuses anything else, so the deduction
 *    presentation `pl_balance_sheet()` already gives a contra account (B60, the reserved
 *    1-900 group, which stays "Accumulated Depreciation and Impairment" and is a contra
 *    group rather than an asset group) is what the register relies on. Nothing new was
 *    invented for it.
 *
 *    There is deliberately **no `convention` column**. The owner settled the convention
 *    for the whole module on 21 September 2026: a full month's depreciation in the month
 *    an asset enters service, and none in the month it is disposed of. That is standard,
 *    acceptable practice for a simplified asset schedule and it avoids fractional
 *    day-weighting, so it is the module's stated behaviour rather than a per-class choice
 *    somebody can get wrong. An earlier draft of this migration carried
 *    `ENUM('full_period','half_period','pro_rata_days')` and weighted periods by days;
 *    that is gone, not defaulted. A second convention later is a new column and a new
 *    decision, not a value in an enum nobody reviewed.
 *
 * 3. `pl_assets` — the register itself: cost, acquisition and in-service dates,
 *    residual value, class, an optional life or rate override, location, supplier and
 *    reference. `cost` and the dates are fixed once recorded, because the acquisition
 *    journal behind them is immutable; the way to change one is the ordinary way, a
 *    linked reversal and a fresh asset.
 *
 * 4. `pl_asset_depreciation_runs` — one row per posted run, one journal per run, one
 *    row per reversal. `uq_asset_run_key` makes a retried request return the run it
 *    already made instead of posting a second one.
 *
 * 5. `pl_asset_events` — append-only, enforced by triggers rather than by convention,
 *    for the same reason `pl_journal_lines` is: a report that can be edited behind the
 *    ledger's back is not a reconciliation. A correction is a mirror row with
 *    `is_reversal = 1`, `reverses_event_id` pointing at what it undoes and every amount
 *    negated, written beside the linked reversal journal that undoes the posting. The
 *    period of a reversal row is the period of the row it reverses, so re-running a
 *    corrected period computes the difference and never a duplicate.
 *
 * No account is created here. A book that never enables this module never gains one:
 * `pl_asset_provision_accounts()` creates what is missing, through `pl_save_account()`
 * with its ordinary audit row, the first time a class is saved. The bundled starter
 * chart is untouched, so no company is asked to re-review its chart and
 * `pl_confirm_existing_setup()` still asks for the same thirteen mappings.
 *
 * Reversal path, in full:
 *
 *   DROP TRIGGER pl_asset_events_no_update;
 *   DROP TRIGGER pl_asset_events_no_delete;
 *   DROP TABLE pl_asset_events;
 *   DROP TABLE pl_asset_depreciation_runs;
 *   DROP TABLE pl_assets;
 *   DROP TABLE pl_asset_classes;
 *   DROP TABLE pl_asset_accounts;
 *   ALTER TABLE pl_core_audit MODIFY entity_type ENUM(... the 041 list ...) NOT NULL;
 *
 * The journals stay, as posted journals always do; the way to undo those is the linked
 * reversal the module already provides, before the tables are dropped. The accounts
 * `pl_asset_provision_accounts()` created stay too, and are ordinary chart accounts.
 */

return [
    // ------------------------------------------------------------------ account pointers
    <<<'SQL'
CREATE TABLE pl_asset_accounts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    book_id BIGINT UNSIGNED NOT NULL,
    purpose ENUM('cost','accumulated_depreciation','depreciation_expense','disposal') NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_asset_account_purpose (book_id, purpose),
    CONSTRAINT fk_asset_account_book FOREIGN KEY (book_id, company_id) REFERENCES pl_books (id, company_id),
    CONSTRAINT fk_asset_account_account FOREIGN KEY (account_id, company_id, book_id) REFERENCES pl_accounts (id, company_id, book_id),
    CONSTRAINT fk_asset_account_actor FOREIGN KEY (created_by) REFERENCES pl_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,

    // ------------------------------------------------------------------------- classes
    // `annual_rate` is the reducing-balance rate as a fraction of one, so 15% is
    // 0.150000. It is NULL for straight line and required for reducing balance; the
    // useful life is required for both, because reducing balance never reaches residual
    // value by itself and the schedule writes the remainder off in the last period of
    // the life (the ordinary true-up, and what makes the charges sum exactly).
    <<<'SQL'
CREATE TABLE pl_asset_classes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    book_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(20) NOT NULL,
    name VARCHAR(120) NOT NULL,
    method ENUM('straight_line','reducing_balance') NOT NULL DEFAULT 'straight_line',
    useful_life_months SMALLINT UNSIGNED NOT NULL,
    annual_rate DECIMAL(9,6) NULL,
    asset_account_id BIGINT UNSIGNED NOT NULL,
    accumulated_account_id BIGINT UNSIGNED NOT NULL,
    expense_account_id BIGINT UNSIGNED NOT NULL,
    disposal_account_id BIGINT UNSIGNED NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    creation_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_asset_class_code (book_id, code),
    UNIQUE KEY uq_asset_class_scope (id, company_id, book_id),
    UNIQUE KEY uq_asset_class_creation (book_id, creation_key),
    CONSTRAINT ck_asset_class_life CHECK (useful_life_months BETWEEN 1 AND 1200),
    CONSTRAINT ck_asset_class_rate CHECK (
        (method = 'straight_line' AND annual_rate IS NULL)
        OR (method = 'reducing_balance' AND annual_rate IS NOT NULL AND annual_rate > 0 AND annual_rate < 1)
    ),
    CONSTRAINT fk_asset_class_book FOREIGN KEY (book_id, company_id) REFERENCES pl_books (id, company_id),
    CONSTRAINT fk_asset_class_asset_account FOREIGN KEY (asset_account_id, company_id, book_id) REFERENCES pl_accounts (id, company_id, book_id),
    CONSTRAINT fk_asset_class_accum_account FOREIGN KEY (accumulated_account_id, company_id, book_id) REFERENCES pl_accounts (id, company_id, book_id),
    CONSTRAINT fk_asset_class_expense_account FOREIGN KEY (expense_account_id, company_id, book_id) REFERENCES pl_accounts (id, company_id, book_id),
    CONSTRAINT fk_asset_class_disposal_account FOREIGN KEY (disposal_account_id, company_id, book_id) REFERENCES pl_accounts (id, company_id, book_id),
    CONSTRAINT fk_asset_class_actor FOREIGN KEY (created_by) REFERENCES pl_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,

    // -------------------------------------------------------------------------- assets
    // `status` is maintained by the services under the book lock and is also derivable
    // from the events, which is what tests/asset_test.php checks after a reversal:
    // reversing a disposal returns the asset to 'active', it does not leave a flag behind.
    <<<'SQL'
CREATE TABLE pl_assets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    book_id BIGINT UNSIGNED NOT NULL,
    class_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(40) NOT NULL,
    name VARCHAR(160) NOT NULL,
    acquisition_date DATE NOT NULL,
    in_service_date DATE NOT NULL,
    cost DECIMAL(20,4) NOT NULL,
    residual_value DECIMAL(20,4) NOT NULL DEFAULT 0,
    useful_life_months SMALLINT UNSIGNED NULL,
    annual_rate DECIMAL(9,6) NULL,
    location VARCHAR(160) NOT NULL DEFAULT '',
    supplier VARCHAR(160) NOT NULL DEFAULT '',
    reference VARCHAR(120) NOT NULL DEFAULT '',
    status ENUM('active','disposed','reversed') NOT NULL DEFAULT 'active',
    disposal_date DATE NULL,
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    creation_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_asset_code (book_id, code),
    UNIQUE KEY uq_asset_scope (id, company_id, book_id),
    UNIQUE KEY uq_asset_creation (book_id, creation_key),
    KEY ix_asset_class (book_id, class_id, status),
    CONSTRAINT ck_asset_cost CHECK (cost > 0),
    CONSTRAINT ck_asset_residual CHECK (residual_value >= 0 AND residual_value <= cost),
    CONSTRAINT ck_asset_service_date CHECK (in_service_date >= acquisition_date),
    CONSTRAINT ck_asset_life CHECK (useful_life_months IS NULL OR useful_life_months BETWEEN 1 AND 1200),
    CONSTRAINT ck_asset_rate CHECK (annual_rate IS NULL OR (annual_rate > 0 AND annual_rate < 1)),
    CONSTRAINT ck_asset_disposal_date CHECK (
        (status = 'disposed' AND disposal_date IS NOT NULL) OR (status <> 'disposed' AND disposal_date IS NULL)
    ),
    CONSTRAINT fk_asset_book FOREIGN KEY (book_id, company_id) REFERENCES pl_books (id, company_id),
    CONSTRAINT fk_asset_class FOREIGN KEY (class_id, company_id, book_id) REFERENCES pl_asset_classes (id, company_id, book_id),
    CONSTRAINT fk_asset_actor FOREIGN KEY (created_by) REFERENCES pl_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,

    // -------------------------------------------------------------- depreciation runs
    <<<'SQL'
CREATE TABLE pl_asset_depreciation_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    book_id BIGINT UNSIGNED NOT NULL,
    period_id BIGINT UNSIGNED NOT NULL,
    run_date DATE NOT NULL,
    journal_id BIGINT UNSIGNED NOT NULL,
    total_amount DECIMAL(20,4) NOT NULL,
    asset_count INT UNSIGNED NOT NULL,
    is_reversal TINYINT(1) NOT NULL DEFAULT 0,
    reverses_run_id BIGINT UNSIGNED NULL,
    reason VARCHAR(500) NOT NULL,
    request_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    run_by BIGINT UNSIGNED NOT NULL,
    run_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_asset_run_key (book_id, is_reversal, request_key),
    UNIQUE KEY uq_asset_run_journal (journal_id),
    UNIQUE KEY uq_asset_run_reverses (reverses_run_id),
    UNIQUE KEY uq_asset_run_scope (id, company_id, book_id),
    KEY ix_asset_run_period (book_id, period_id, id),
    CONSTRAINT ck_asset_run_reversal CHECK (
        (is_reversal = 1 AND reverses_run_id IS NOT NULL) OR (is_reversal = 0 AND reverses_run_id IS NULL)
    ),
    CONSTRAINT fk_asset_run_book FOREIGN KEY (book_id, company_id) REFERENCES pl_books (id, company_id),
    CONSTRAINT fk_asset_run_period FOREIGN KEY (period_id, company_id, book_id) REFERENCES pl_periods (id, company_id, book_id),
    CONSTRAINT fk_asset_run_journal FOREIGN KEY (journal_id) REFERENCES pl_journals (id),
    CONSTRAINT fk_asset_run_reverses FOREIGN KEY (reverses_run_id) REFERENCES pl_asset_depreciation_runs (id),
    CONSTRAINT fk_asset_run_actor FOREIGN KEY (run_by) REFERENCES pl_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,

    // -------------------------------------------------------------------- asset events
    <<<'SQL'
CREATE TABLE pl_asset_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    book_id BIGINT UNSIGNED NOT NULL,
    asset_id BIGINT UNSIGNED NOT NULL,
    kind ENUM('acquisition','depreciation','disposal') NOT NULL,
    is_reversal TINYINT(1) NOT NULL DEFAULT 0,
    reverses_event_id BIGINT UNSIGNED NULL,
    event_date DATE NOT NULL,
    period_id BIGINT UNSIGNED NOT NULL,
    run_id BIGINT UNSIGNED NULL,
    journal_id BIGINT UNSIGNED NOT NULL,
    cost_amount DECIMAL(20,4) NOT NULL DEFAULT 0,
    depreciation_amount DECIMAL(20,4) NOT NULL DEFAULT 0,
    proceeds_amount DECIMAL(20,4) NOT NULL DEFAULT 0,
    gain_loss_amount DECIMAL(20,4) NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_asset_event_reverses (reverses_event_id),
    UNIQUE KEY uq_asset_event_scope (id, company_id, book_id),
    KEY ix_asset_event_asset (asset_id, id),
    KEY ix_asset_event_period (book_id, period_id, asset_id),
    KEY ix_asset_event_kind (book_id, kind, asset_id),
    CONSTRAINT ck_asset_event_reversal CHECK (
        (is_reversal = 1 AND reverses_event_id IS NOT NULL) OR (is_reversal = 0 AND reverses_event_id IS NULL)
    ),
    CONSTRAINT fk_asset_event_asset FOREIGN KEY (asset_id, company_id, book_id) REFERENCES pl_assets (id, company_id, book_id),
    CONSTRAINT fk_asset_event_period FOREIGN KEY (period_id, company_id, book_id) REFERENCES pl_periods (id, company_id, book_id),
    CONSTRAINT fk_asset_event_run FOREIGN KEY (run_id) REFERENCES pl_asset_depreciation_runs (id),
    CONSTRAINT fk_asset_event_journal FOREIGN KEY (journal_id) REFERENCES pl_journals (id),
    CONSTRAINT fk_asset_event_reverses FOREIGN KEY (reverses_event_id) REFERENCES pl_asset_events (id),
    CONSTRAINT fk_asset_event_actor FOREIGN KEY (created_by) REFERENCES pl_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,

    // The register is a subsidiary ledger of immutable postings. An edit or a delete here
    // would move a reported figure without moving the journal behind it, which is exactly
    // the defect the reconciliation exists to catch; refuse both in the database, so no
    // future screen, import or repair script can do it by accident.
    "CREATE TRIGGER pl_asset_events_no_update BEFORE UPDATE ON pl_asset_events FOR EACH ROW BEGIN "
    . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A recorded asset event cannot be edited. Record a linked reversal instead.'; END",
    "CREATE TRIGGER pl_asset_events_no_delete BEFORE DELETE ON pl_asset_events FOR EACH ROW BEGIN "
    . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A recorded asset event cannot be deleted. Record a linked reversal instead.'; END",

    // ------------------------------------------------------------------- core audit enum
    // A MODIFY replaces the whole ENUM list, so this carries the union of every value any
    // earlier migration added (037 and 038 collided here once already, and 041 records the
    // rule) plus this one's two.
    "ALTER TABLE pl_core_audit MODIFY entity_type ENUM('account','general_journal','product','warehouse','document_series',"
    . "'product_pack','sales_staff','area','company_profile','trading_policy',"
    . "'stock_document','gate_pass','van_settlement',"
    . "'report_cost_setting',"
    . "'asset','asset_class') NOT NULL",
];
