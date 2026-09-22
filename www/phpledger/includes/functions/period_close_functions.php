<?php
declare(strict_types=1);

/**
 * 1.3 M17, issue #93: the close checklist and the reversing-journal trigger.
 *
 * ---------------------------------------------------------------------------
 * Why the trigger is period opening, and not a worker
 * ---------------------------------------------------------------------------
 *
 * The issue says a scheduled reversal posts "from the concurrency worker or on the first posting
 * in that period". Owner decision **B89** overrides that sentence and this file implements B89.
 *
 * This feature adds no background worker; `tests/
 * concurrency_worker.php` is a barrier-file test harness that exits unless PL_ENV is `test`.
 * "The first posting in that period" is worse than no trigger at all: if nobody posts, the
 * accrual sits unreversed, a trial balance run mid-month is simply wrong, and this very
 * checklist reads "reversals pending" for ever.
 *
 * So there are exactly two paths, and both are an explicit act by a named person at a
 * transaction boundary:
 *
 *   1. **Period opening.** pl_create_period() and a reopen through pl_change_period_status()
 *      call pl_post_due_reversals() while they still hold the book lock they took to change the
 *      period. Every reversal is therefore in the same transaction as the act that made it due.
 *   2. **Scheduling into an already-open period.** pl_schedule_journal_reversal() posts
 *      immediately when the period holding `reverse_on` is open when the date is recorded.
 *
 * The idempotency is the one that already exists. pl_reverse_journal() looks up `reversal_of_id`
 * FOR UPDATE before it builds anything, and pl_post_journal_locked() looks up the idempotency
 * key FOR UPDATE before it inserts; the key here is derived from the original journal's id, so a
 * second run of either path returns the reversal the first run posted. No second reversal
 * service is written and no key column is added.
 *
 * ---------------------------------------------------------------------------
 * Hard and soft, and what this file does NOT decide
 * ---------------------------------------------------------------------------
 *
 * "Close refuses on hard items and warns on soft ones" is the mechanism the issue specifies.
 * *Which* computed item is hard is a judgement the issue does not settle, and inventing one
 * silently in code would be the wrong place to put it. So severity is **data**: the defaults
 * below are defaults, `pl_period_checklist_settings` overrides any of them per book, and the
 * accounting reviewer changes a setting rather than this file. The reasoning behind each
 * default is on the item, and the open question is written down in
 * docs/design/1.2-2026-09/PERIOD-CLOSE.md for the reviewer rather than treated as settled.
 */

/** Severity is per book and overridable; these are the defaults, not a policy. */
const PL_PERIOD_ITEM_SEVERITIES = ['hard', 'soft'];

/**
 * The items core computes, in the order an accountant works through them.
 *
 * `computed` is false for an item core cannot honestly compute. That is not laziness: see
 * `core.stock_counts` below, where the data the issue assumes simply is not recorded.
 *
 * @return array<string, array{label:string, hint:string, severity:string, computed:bool}>
 */
function pl_period_core_checklist_items(): array
{
    return [
        // HARD. A draft dated inside the period is work that was started and not finished; if
        // the period closes it can never be posted, because a closed period refuses postings.
        // Closing over one destroys the only thing that could still be done about it.
        'core.drafts' => [
            'label' => 'No unposted drafts are dated in this period',
            'hint' => 'A draft dated inside a closed period can never be posted, because a closed period refuses postings.',
            'severity' => 'hard',
            'computed' => true,
        ],
        // HARD. An unreversed accrual is a figure that is simply wrong in every report drawn
        // after its reversal date, and the reversal cannot be posted once this period closes.
        'core.reversals' => [
            'label' => 'Every scheduled reversal due in this period has posted',
            'hint' => 'A journal carrying a reversal date inside this period must have its linked reversal posted before the period closes.',
            'severity' => 'hard',
            'computed' => true,
        ],
        // SOFT by default. An unreconciled bank account is a real defect, but it is routinely
        // outstanding when a month is closed on time and reconciled when the statement arrives,
        // and the honest remedy is the reason on a skip rather than a refusal to close. A
        // business that wants the refusal sets this to hard; that is what the override is for.
        'core.cash_bank_reconciled' => [
            'label' => 'Every cash and bank account is reconciled to the period end',
            'hint' => 'A money account is reconciled when a completed bank statement reaches the period end date. Tick with a reason to record one that was deliberately skipped.',
            'severity' => 'soft',
            'computed' => true,
        ],
        // SOFT, and NOT computed. See pl_period_stock_count_locations(): a stock count that
        // agrees records nothing at all, so "counts agreed for every location" cannot be
        // computed from the books. It is attested with a reason instead, and the locations are
        // listed so none is forgotten.
        'core.payroll' => ['label' => 'Payroll posted for this period', 'hint' => 'Confirm all payroll totals, or record why payroll is not applicable. Unpaid payroll liabilities carry forward normally.', 'severity' => 'soft', 'computed' => false],
        'core.stock_counts' => [
            'label' => 'Stock has been counted and agreed for every location',
            'hint' => 'Recorded by attestation: a count that agrees with the book quantity posts nothing, so the books hold no evidence that it happened.',
            'severity' => 'soft',
            'computed' => false,
        ],
        // HARD. An opening cutover that was confirmed but never posted means the book's opening
        // position is not in the ledger; every figure in the period is drawn on incomplete books.
        'core.opening_cutover' => [
            'label' => 'The opening cutover is confirmed and posted',
            'hint' => 'A confirmed opening cutover whose journal has not posted leaves the book without its opening position.',
            'severity' => 'hard',
            'computed' => true,
        ],
    ];
}

/** The book's severity overrides, keyed by item. */
function pl_period_severity_overrides(int $companyId, int $bookId): array
{
    $rows = DB::query('SELECT item_key, severity FROM pl_period_checklist_settings WHERE company_id = %i AND book_id = %i FOR SHARE', $companyId, $bookId);
    $overrides = [];
    foreach ($rows as $row) {
        $overrides[(string) $row['item_key']] = (string) $row['severity'];
    }
    return $overrides;
}

/** The one period row a checklist is about, locked or shared as the caller needs. */
function pl_period_row(int $companyId, int $bookId, int $periodId, bool $lock = false): array
{
    $period = DB::queryFirstRow('SELECT id, start_date, end_date, status, revision FROM pl_periods WHERE id = %i AND company_id = %i AND book_id = %i' . ($lock ? ' FOR UPDATE' : ' FOR SHARE'), $periodId, $companyId, $bookId);
    if (!$period) {
        throw new DomainException('This period is not available in the selected company and book.');
    }
    $period['id'] = (int) $period['id'];
    $period['revision'] = (int) $period['revision'];
    return $period;
}

// ---------------------------------------------------------------------------------------------
// The computed items
// ---------------------------------------------------------------------------------------------

/** Unposted drafts dated inside the period, across the two surfaces that carry one. */
function pl_period_pending_drafts(int $companyId, int $bookId, array $period): array
{
    $drafts = DB::query('SELECT id, document_date, reference, description FROM pl_general_drafts WHERE company_id = %i AND book_id = %i AND journal_id IS NULL AND document_date BETWEEN %s AND %s ORDER BY document_date, id FOR SHARE',
        $companyId, $bookId, $period['start_date'], $period['end_date']);
    $pending = [];
    foreach ($drafts as $draft) {
        $pending[] = ['kind' => 'general', 'id' => (int) $draft['id'], 'date' => (string) $draft['document_date'],
            'reference' => (string) $draft['reference'], 'description' => (string) $draft['description']];
    }
    // The receivables and payables documents keep their own draft status. A quote is not a
    // draft invoice and never posts, so it is not a close blocker and is left out by name.
    foreach (DB::query("SELECT id, kind, document_date, document_number FROM pl_ar_documents WHERE company_id = %i AND book_id = %i AND status = 'draft' AND kind <> 'quote' AND document_date BETWEEN %s AND %s ORDER BY document_date, id FOR SHARE",
        $companyId, $bookId, $period['start_date'], $period['end_date']) as $document) {
        $pending[] = ['kind' => (string) $document['kind'], 'id' => (int) $document['id'], 'date' => (string) $document['document_date'],
            'reference' => (string) $document['document_number'], 'description' => ''];
    }
    return $pending;
}

/**
 * Every active money account, with the date it is reconciled to.
 *
 * `cash_bank` is the only money role this schema has. There is no separate `cash` role and no
 * separate `bank` role — pl_save_account() accepts eight role names and `cash_bank` is the one
 * that covers a till, a petty-cash tin, van cash and a bank account alike — so the issue's
 * "cash- and bank-role accounts" is this one set. "Reconciled to" is the end date of the latest
 * completed statement, which is the same fact pl_reconciliation_assert_posting_allowed() uses
 * to refuse a posting before it.
 */
function pl_period_money_accounts(int $companyId, int $bookId): array
{
    $rows = DB::query("SELECT a.id, a.code, a.name,
            (SELECT MAX(s.end_date) FROM pl_bank_statements s WHERE s.account_id = a.id AND s.company_id = a.company_id AND s.book_id = a.book_id AND s.status = 'completed') AS reconciled_to
        FROM pl_accounts a
        WHERE a.company_id = %i AND a.book_id = %i AND a.role = 'cash_bank' AND a.type = 'asset' AND a.is_active = 1
        ORDER BY a.code FOR SHARE", $companyId, $bookId);
    $accounts = [];
    foreach ($rows as $row) {
        $accounts[] = ['id' => (int) $row['id'], 'code' => (string) $row['code'], 'name' => (string) $row['name'],
            'reconciled_to' => $row['reconciled_to'] === null ? null : (string) $row['reconciled_to']];
    }
    return $accounts;
}

/**
 * The stock locations a count would have to cover, or an empty list when locations are off.
 *
 * **This is where the issue is wrong against the code, and it is recorded rather than papered
 * over.** `pl_inventory_adjust_count()` records a count as an ordinary `adjustment` movement,
 * indistinguishable from a manual quantity or value adjustment, and `pl_inventory_count_effect()`
 * refuses outright when the count matches ("The count already matches stock; no adjustment is
 * needed"). So a count that *agrees* — the outcome the checklist is asking about — writes
 * nothing at all. There is no stock-count document to compute agreement from. Inventing one
 * belongs to the inventory module and not to a period-close branch, so this item is attested:
 * the locations are listed so none is missed, and the tick carries the reason.
 */
function pl_period_stock_count_locations(int $actorId, int $companyId, int $bookId): array
{
    if (!function_exists('pl_module_available') || !pl_module_available($actorId, $companyId, $bookId, 'inventory-locations')) {
        return [];
    }
    $rows = DB::query('SELECT id, code, name FROM pl_inventory_warehouses WHERE company_id = %i AND book_id = %i AND is_active = 1 ORDER BY code FOR SHARE', $companyId, $bookId);
    $locations = [];
    foreach ($rows as $row) {
        $locations[] = ['id' => (int) $row['id'], 'code' => (string) $row['code'], 'name' => (string) $row['name']];
    }
    return $locations;
}

/** A confirmed cutover whose journal never posted, or null when the book's opening is settled. */
function pl_period_opening_gap(int $companyId, int $bookId): ?array
{
    $row = DB::queryFirstRow("SELECT c.id, p.cutover_date FROM pl_opening_cutovers c JOIN pl_opening_previews p ON p.id = c.preview_id WHERE c.company_id = %i AND c.book_id = %i AND c.status = 'confirmed' AND c.journal_id IS NULL FOR SHARE", $companyId, $bookId);
    return $row === null ? null : ['id' => (int) $row['id'], 'cutover_date' => (string) $row['cutover_date']];
}

/**
 * Journals whose scheduled reversal is due in this period and has not posted.
 *
 * The reversal is found by the join every other reader in this tree uses — a journal whose
 * `reversal_of_id` is the original — rather than by a denormalised id column that could one day
 * disagree with it.
 */
function pl_period_pending_reversals(int $companyId, int $bookId, array $period): array
{
    $rows = DB::query('SELECT j.id, j.journal_date, s.reverse_on, j.description
        FROM pl_journals j
        JOIN pl_journal_reversal_schedules s ON s.journal_id = j.id AND s.company_id = j.company_id AND s.book_id = j.book_id
        LEFT JOIN pl_journals r ON r.reversal_of_id = j.id AND r.company_id = j.company_id AND r.book_id = j.book_id
        WHERE j.company_id = %i AND j.book_id = %i AND s.reverse_on BETWEEN %s AND %s AND r.id IS NULL
        ORDER BY s.reverse_on, j.id FOR SHARE', $companyId, $bookId, $period['start_date'], $period['end_date']);
    $pending = [];
    foreach ($rows as $row) {
        $pending[] = ['id' => (int) $row['id'], 'reference' => 'PL-' . str_pad((string) (int) $row['id'], 8, '0', STR_PAD_LEFT),
            'journal_date' => (string) $row['journal_date'], 'reverse_on' => (string) $row['reverse_on'],
            'description' => (string) $row['description']];
    }
    return $pending;
}

// ---------------------------------------------------------------------------------------------
// The checklist itself
// ---------------------------------------------------------------------------------------------

/** The recorded ticks for one period, keyed by item. */
function pl_period_ticks(int $companyId, int $bookId, int $periodId): array
{
    $rows = DB::query('SELECT t.item_key, t.state, t.reason, t.ticked_at, u.display_name AS actor_name FROM pl_period_checklist_ticks t JOIN pl_users u ON u.id = t.actor_id WHERE t.company_id = %i AND t.book_id = %i AND t.period_id = %i FOR SHARE', $companyId, $bookId, $periodId);
    $ticks = [];
    foreach ($rows as $row) {
        $ticks[(string) $row['item_key']] = ['state' => (string) $row['state'], 'reason' => (string) $row['reason'],
            'ticked_at' => (string) $row['ticked_at'], 'actor_name' => (string) $row['actor_name']];
    }
    return $ticks;
}

/**
 * The whole checklist for one period: core items, the company's own, and whatever a package adds.
 *
 * This is a read, so no book row is locked and the `period.checklist` filter can run. A close
 * calls it *before* it opens its transaction, for exactly that reason: pl_hook_assert_unlocked()
 * refuses to run a hook while a book row is held, and the ownership register already paid for
 * learning that the hard way.
 */
function pl_period_checklist(int $actorId, int $companyId, int $bookId, int $periodId): array
{
    $result = pl_period_checklist_build($actorId, $companyId, $bookId, $periodId, true);
    pl_period_package_item_keys(array_column(array_filter($result['items'], fn(array $item): bool => $item['source'] === 'package'), 'key'));
    return $result;
}

/**
 * @param bool $withPackages false inside a lock, where no hook may run; the core items are then
 *                           authoritative and a package's items are the caller's pre-lock copy.
 */
function pl_period_checklist_build(int $actorId, int $companyId, int $bookId, int $periodId, bool $withPackages): array
{
    $core = pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $periodId): array {
        pl_require_company_access($actorId, $companyId);
        pl_ledger_book($companyId, $bookId);
        $period = pl_period_row($companyId, $bookId, $periodId);
        $overrides = pl_period_severity_overrides($companyId, $bookId);
        $ticks = pl_period_ticks($companyId, $bookId, $periodId);
        $drafts = pl_period_pending_drafts($companyId, $bookId, $period);
        $money = pl_period_money_accounts($companyId, $bookId);
        $locations = pl_period_stock_count_locations($actorId, $companyId, $bookId);
        $opening = pl_period_opening_gap($companyId, $bookId);
        $reversals = pl_period_pending_reversals($companyId, $bookId, $period);
        $unreconciled = [];
        foreach ($money as $account) {
            if ($account['reconciled_to'] === null || $account['reconciled_to'] < $period['end_date']) {
                $unreconciled[] = $account;
            }
        }
        $facts = [
            'core.drafts' => ['satisfied' => $drafts === [], 'detail' => $drafts],
            'core.reversals' => ['satisfied' => $reversals === [], 'detail' => $reversals],
            'core.cash_bank_reconciled' => ['satisfied' => $unreconciled === [], 'detail' => $unreconciled],
            'core.payroll' => ['satisfied' => false, 'detail' => [pl_payroll_close_review($actorId,$companyId,$bookId,$period['start_date'],$period['end_date'])]],
            'core.stock_counts' => ['satisfied' => false, 'detail' => $locations],
            'core.opening_cutover' => ['satisfied' => $opening === null, 'detail' => $opening === null ? [] : [$opening]],
        ];
        $items = [];
        foreach (pl_period_core_checklist_items() as $key => $definition) {
            // A stock-count item on a book with no locations module is about nothing: there is
            // one place stock lives and the ordinary count covers it. It drops out rather than
            // standing there permanently outstanding.
            if ($key === 'core.stock_counts' && $facts[$key]['detail'] === []) {
                continue;
            }
            $items[] = pl_period_checklist_entry($key, $definition['label'], $definition['hint'], 'core',
                $definition['computed'], $overrides[$key] ?? $definition['severity'], $facts[$key]['satisfied'],
                $facts[$key]['detail'], $ticks[$key] ?? null);
        }
        foreach (DB::query('SELECT item_key, label, severity FROM pl_period_checklist_items WHERE company_id = %i AND book_id = %i AND is_active = 1 ORDER BY id FOR SHARE', $companyId, $bookId) as $row) {
            $key = (string) $row['item_key'];
            $items[] = pl_period_checklist_entry($key, (string) $row['label'], '', 'company', false,
                $overrides[$key] ?? (string) $row['severity'], false, [], $ticks[$key] ?? null);
        }
        return ['period' => $period, 'items' => $items, 'ticks' => $ticks, 'overrides' => $overrides];
    });
    if ($withPackages && pl_hook_lock_held() && pl_hook_callbacks('period.checklist') !== []) {
        throw new LogicException('Read package close checks before acquiring the book lock.');
    }
    if (!$withPackages || pl_hook_callbacks('period.checklist') === []) {
        return pl_period_checklist_result($core['period'], $core['items']);
    }
    // M8 extension point. A package adds computed items of its own; core re-validates every
    // entry it gets back, so a package can neither forge a tick, claim a core key, nor make an
    // item satisfied that core computed as outstanding.
    $filtered = pl_apply_filters('period.checklist', $core['items'], [['actor_id' => $actorId,
        'company_id' => $companyId, 'book_id' => $bookId, 'period' => $core['period']]]);
    if (!is_array($filtered) || !array_is_list($filtered)) {
        throw new DomainException('A package returned something that is not a checklist from the period filter.');
    }
    $items = [];
    $seen = [];
    $coreKeys = array_column($core['items'], 'key');
    foreach ($filtered as $entry) {
        if (!is_array($entry)) {
            throw new DomainException('A package returned a checklist entry that is not an item.');
        }
        $key = (string) ($entry['key'] ?? '');
        $original = null;
        foreach ($core['items'] as $candidate) {
            if ($candidate['key'] === $key) { $original = $candidate; break; }
        }
        if ($original !== null) {
            // A core or company item passes through untouched whatever the package did to it.
            if (!in_array($key, $seen, true)) { $items[] = $original; $seen[] = $key; }
            continue;
        }
        if ($key === '' || in_array($key, $seen, true) || in_array($key, $coreKeys, true) || !preg_match('/^[a-z][a-z0-9_-]{0,20}\.[a-z0-9_.-]{1,38}$/D', $key) || str_starts_with($key, 'core.')) {
            throw new DomainException('A package checklist item needs a key of its own, in its own namespace and not core\'s.');
        }
        $items[] = pl_period_checklist_entry($key, (string) ($entry['label'] ?? $key), (string) ($entry['hint'] ?? ''), 'package',
            true, ($core['overrides'][$key] ?? null) ?? (in_array($entry['severity'] ?? '', PL_PERIOD_ITEM_SEVERITIES, true) ? (string) $entry['severity'] : 'soft'),
            ($entry['satisfied'] ?? false) === true, is_array($entry['detail'] ?? null) ? $entry['detail'] : [],
            $core['ticks'][$key] ?? null);
        $seen[] = $key;
    }
    foreach ($core['items'] as $original) {
        if (!in_array($original['key'], $seen, true)) { $items[] = $original; }
    }
    return pl_period_checklist_result($core['period'], $items);
}

/** One normalized checklist row. An item is resolved when it passes or when it was ticked. */
function pl_period_checklist_entry(string $key, string $label, string $hint, string $source, bool $computed, string $severity, bool $satisfied, array $detail, ?array $tick): array
{
    $severity = in_array($severity, PL_PERIOD_ITEM_SEVERITIES, true) ? $severity : 'soft';
    return ['key' => $key, 'label' => $label, 'hint' => $hint, 'source' => $source, 'computed' => $computed,
        'severity' => $severity, 'satisfied' => $satisfied, 'detail' => $detail, 'tick' => $tick,
        'resolved' => $satisfied || ($tick !== null && !($computed && $severity === 'hard'))];
}

function pl_period_checklist_result(array $period, array $items): array
{
    $blocking = [];
    $warnings = [];
    foreach ($items as $item) {
        if ($item['resolved']) { continue; }
        if ($item['severity'] === 'hard') { $blocking[] = $item; } else { $warnings[] = $item; }
    }
    return ['period' => $period, 'items' => $items, 'blocking' => $blocking, 'warnings' => $warnings,
        'ready' => $blocking === []];
}

/** The refusal a close raises, naming every hard item that is still outstanding. */
function pl_period_close_refusal(array $blocking): string
{
    $labels = [];
    foreach ($blocking as $item) {
        $labels[] = $item['label'];
    }
    return 'This period cannot be closed yet. ' . count($labels) . ' required item(s) on the close checklist are outstanding: '
        . implode('; ', $labels) . '. Resolve computed blockers and complete required attestations.';
}

// ---------------------------------------------------------------------------------------------
// Ticks, free items and severity
// ---------------------------------------------------------------------------------------------

function pl_period_item_key(mixed $key): string
{
    $key = pl_ledger_text($key, 'Checklist item', 60);
    if (!preg_match('/^[a-z][a-z0-9_-]{0,20}\.[a-z0-9_.-]{1,38}$/D', $key)) {
        throw new DomainException('A checklist item key is a namespace and a name, like "company.vat_return".');
    }
    return $key;
}

/**
 * Tick a checklist item, or clear the tick.
 *
 * Every tick is also a row in `pl_period_actions`, which migration 007 protected with triggers
 * that refuse UPDATE and DELETE — so actor, time and reason survive a retick, which is what the
 * issue means by recording a tick into the period action log. A tick carries a reason even when
 * the item is satisfied: "counted and agreed" is evidence, and "skipped because the statement
 * has not arrived" is the one that matters at all.
 */
function pl_period_tick_item(int $actorId, int $companyId, int $bookId, int $periodId, string $itemKey, string $state, string $reason, string $requestKey): array
{
    $itemKey = pl_period_item_key($itemKey);
    if (!in_array($state, ['done', 'skipped', 'cleared'], true)) {
        throw new DomainException('A checklist item is ticked as done or skipped, or its tick is cleared.');
    }
    $reason = pl_ledger_text($reason, 'Reason', 500);
    $key = pl_period_request_key($requestKey);
    $action = $state === 'cleared' ? 'untick' : 'tick';
    pl_period_checklist($actorId, $companyId, $bookId, $periodId);
    $hash = hash('sha256', json_encode([$actorId, $companyId, $bookId, $periodId, $action, $itemKey, $state, $reason, $key], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $periodId, $itemKey, $state, $reason, $key, $action, $hash): array {
        pl_period_require_write($actorId, $companyId);
        pl_ledger_book($companyId, $bookId, true);
        $existing = pl_period_existing_receipt($companyId, $bookId, $key, $hash);
        if ($existing !== null) {
            return $existing;
        }
        $period = pl_period_row($companyId, $bookId, $periodId, true);
        // A closed period's checklist is the record of how it was closed. Changing a tick after
        // the fact would rewrite that record; reopen the period if the record is wrong.
        if ($period['status'] !== 'open') {
            throw new DomainException('This period is closed. Reopen it before changing its close checklist.');
        }
        if (!pl_period_item_exists($actorId, $companyId, $bookId, $periodId, $itemKey)) {
            throw new DomainException('This close checklist has no item by that name. Reload the period before ticking.');
        }
        DB::query('DELETE FROM pl_period_checklist_ticks WHERE company_id = %i AND book_id = %i AND period_id = %i AND item_key = %s', $companyId, $bookId, $periodId, $itemKey);
        if ($state !== 'cleared') {
            DB::insert('pl_period_checklist_ticks', ['company_id' => $companyId, 'book_id' => $bookId, 'period_id' => $periodId,
                'item_key' => $itemKey, 'state' => $state, 'reason' => $reason, 'actor_id' => $actorId]);
        }
        return pl_period_record_action($actorId, $companyId, $bookId, $period, $action, $period['status'], $reason, $key, $hash,
            ['item_key' => $itemKey, 'item_state' => $state]);
    });
}

/** Does this checklist hold that item? Asked under the lock, so packages are not consulted. */
function pl_period_item_exists(int $actorId, int $companyId, int $bookId, int $periodId, string $itemKey): bool
{
    if (isset(pl_period_core_checklist_items()[$itemKey])) {
        return true;
    }
    if (DB::queryFirstField('SELECT id FROM pl_period_checklist_items WHERE company_id = %i AND book_id = %i AND item_key = %s AND is_active = 1 FOR SHARE', $companyId, $bookId, $itemKey) !== null) {
        return true;
    }
    // A package's item. It cannot be recomputed here — a hook may not run while the book row is
    // locked — so a tick is allowed for any item the period already carries a tick for, plus any
    // key a package claimed while this request was still outside the lock.
    return in_array($itemKey, pl_period_package_item_keys(), true);
}

/**
 * The package checklist keys seen in this request, captured before any lock was taken.
 *
 * pl_hook_assert_unlocked() refuses to run a hook while a book row is held, so a tick — which
 * takes the lock before it validates — cannot ask a package whether it owns an item. The web
 * and API entry points read the checklist first, which fills this, and a tick for a key nothing
 * claimed is refused.
 */
function pl_period_package_item_keys(?array $set = null): array
{
    static $keys = [];
    if ($set !== null) {
        $keys = array_values(array_unique($set));
    }
    return $keys;
}

/** A free item a company adds to its own close checklist. */
function pl_period_save_checklist_item(int $actorId, int $companyId, int $bookId, array $input): array
{
    $itemKey = pl_period_item_key($input['item_key'] ?? null);
    if (str_starts_with($itemKey, 'core.')) {
        throw new DomainException('The "core." namespace belongs to the items the application computes. Choose another namespace, such as "company.".');
    }
    $label = pl_ledger_text($input['label'] ?? null, 'Label', 200);
    $severity = (string) ($input['severity'] ?? 'soft');
    if (!in_array($severity, PL_PERIOD_ITEM_SEVERITIES, true)) {
        throw new DomainException('A checklist item either refuses a close or warns on one.');
    }
    $active = ($input['is_active'] ?? true) !== false;
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $itemKey, $label, $severity, $active): array {
        pl_period_require_write($actorId, $companyId);
        pl_ledger_book($companyId, $bookId, true);
        $existing = DB::queryFirstRow('SELECT id, revision FROM pl_period_checklist_items WHERE company_id = %i AND book_id = %i AND item_key = %s FOR UPDATE', $companyId, $bookId, $itemKey);
        if ($existing) {
            DB::update('pl_period_checklist_items', ['label' => $label, 'severity' => $severity, 'is_active' => $active ? 1 : 0,
                'revision' => (int) $existing['revision'] + 1], 'id = %i', (int) $existing['id']);
            return ['id' => (int) $existing['id'], 'item_key' => $itemKey, 'label' => $label, 'severity' => $severity, 'is_active' => $active];
        }
        DB::insert('pl_period_checklist_items', ['company_id' => $companyId, 'book_id' => $bookId, 'item_key' => $itemKey,
            'label' => $label, 'severity' => $severity, 'is_active' => $active ? 1 : 0, 'created_by' => $actorId]);
        return ['id' => (int) DB::insertId(), 'item_key' => $itemKey, 'label' => $label, 'severity' => $severity, 'is_active' => $active];
    });
}

/** Make one item hard or soft for this book. This is the setting the accountant turns. */
function pl_period_set_item_severity(int $actorId, int $companyId, int $bookId, string $itemKey, string $severity): array
{
    $itemKey = pl_period_item_key($itemKey);
    if (!in_array($severity, PL_PERIOD_ITEM_SEVERITIES, true)) {
        throw new DomainException('A checklist item either refuses a close or warns on one.');
    }
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $itemKey, $severity): array {
        pl_period_require_write($actorId, $companyId);
        pl_ledger_book($companyId, $bookId, true);
        $existing = DB::queryFirstField('SELECT id FROM pl_period_checklist_settings WHERE company_id = %i AND book_id = %i AND item_key = %s FOR UPDATE', $companyId, $bookId, $itemKey);
        if ($existing !== null) {
            DB::update('pl_period_checklist_settings', ['severity' => $severity, 'set_by' => $actorId, 'set_at' => date('Y-m-d H:i:s')], 'id = %i', (int) $existing);
        } else {
            DB::insert('pl_period_checklist_settings', ['company_id' => $companyId, 'book_id' => $bookId,
                'item_key' => $itemKey, 'severity' => $severity, 'set_by' => $actorId]);
        }
        return ['item_key' => $itemKey, 'severity' => $severity];
    });
}

// ---------------------------------------------------------------------------------------------
// Reversing journals (B89)
// ---------------------------------------------------------------------------------------------

/** The deterministic key one original journal's scheduled reversal always posts under. */
function pl_scheduled_reversal_key(int $journalId): string
{
    return 'reverse-on:' . $journalId;
}

/**
 * Record — or clear — the date a posted journal reverses on, and post it if that period is open.
 *
 * This is B89's second path. `reverse_on` is deliberately not part of the journal payload:
 * pl_normalize_journal() builds the canonical array that becomes `payload_hash`, so a new key in
 * it would change the hash of every journal ever posted and break the upgrade verifiers for
 * nothing. A reversal date is a decision about a journal, not part of its identity.
 */
function pl_schedule_journal_reversal(int $actorId, int $companyId, int $bookId, int $journalId, ?string $reverseOn, string $reason): array
{
    if ($reverseOn !== null) {
        $reverseOn = pl_ledger_date(pl_ledger_text($reverseOn, 'Reversal date', 10));
    }
    $reason = pl_ledger_text($reason, 'Reason', 500);
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $journalId, $reverseOn, $reason): array {
        pl_require_company_access($actorId, $companyId, true);
        pl_ledger_book($companyId, $bookId, true);
        $journal = DB::queryFirstRow('SELECT id, journal_date, source_type, reversal_of_id FROM pl_journals WHERE id = %i AND company_id = %i AND book_id = %i FOR UPDATE', $journalId, $companyId, $bookId);
        if (!$journal) {
            throw new DomainException('This journal is not available in the selected company and book.');
        }
        if ($journal['reversal_of_id'] !== null) {
            throw new DomainException('A reversal cannot itself be scheduled to reverse. Record a new correcting journal.');
        }
        if (!in_array($journal['source_type'], ['general', 'general_journal', 'receipt', 'payment', 'adjustment'], true)) {
            throw new DomainException("Use this document's correction action; only standalone journals can be scheduled to reverse.");
        }
        if (DB::queryFirstField('SELECT id FROM pl_journals WHERE reversal_of_id = %i FOR UPDATE', $journalId) !== null) {
            throw new DomainException('This journal has already been reversed.');
        }
        if ($reverseOn !== null && $reverseOn <= (string) $journal['journal_date']) {
            throw new DomainException('A scheduled reversal must be dated after the journal it reverses.');
        }
        if ($reverseOn !== null && $reverseOn < gmdate('Y-m-d') && !pl_user_can($actorId, $companyId, 'journal.reverse_backdated')) {
            throw new DomainException('Scheduling a past reversal date requires the backdated-reversal permission.');
        }
        DB::query('INSERT INTO pl_journal_reversal_schedules (journal_id, company_id, book_id, reverse_on) VALUES (%i, %i, %i, %s) ON DUPLICATE KEY UPDATE reverse_on = VALUES(reverse_on)', $journalId, $companyId, $bookId, $reverseOn);
        $period = $reverseOn === null ? null : DB::queryFirstRow('SELECT id, status FROM pl_periods WHERE company_id = %i AND book_id = %i AND start_date <= %s AND end_date >= %s FOR UPDATE', $companyId, $bookId, $reverseOn, $reverseOn);
        DB::insert('pl_journal_reversal_events', ['company_id' => $companyId, 'book_id' => $bookId, 'journal_id' => $journalId,
            'event' => $reverseOn === null ? 'cleared' : 'scheduled', 'trigger_source' => 'schedule', 'reverse_on' => $reverseOn,
            'period_id' => $period === null ? null : (int) $period['id'], 'reason' => $reason, 'actor_id' => $actorId]);
        $result = ['journal_id' => $journalId, 'reverse_on' => $reverseOn, 'reversal' => null, 'period_open' => false];
        // The period holding the date is already open, so the reversal is due now. Posting it
        // here keeps the whole decision in one transaction and one audit trail.
        if ($period !== null && $period['status'] === 'open') {
            $result['period_open'] = true;
            $result['reversal'] = pl_post_scheduled_reversal($actorId, $companyId, $bookId, $journalId, $reverseOn, (int) $period['id'], 'schedule', $reason);
        }
        return $result;
    });
}

/**
 * Post one scheduled reversal through the existing reversal service, once.
 *
 * `$scheduled` is passed to pl_reverse_journal() and is the one narrow carve-out this branch
 * makes in ledger_functions.php. The backdated-reversal guard exists to stop somebody quietly
 * reversing an entry into an earlier open period after the fact; a scheduled reversal is the
 * opposite case — its date was recorded in advance, in an immutable receipt, and the period it
 * lands in was opened by a named person. Without the carve-out an accrual dated 31 October and
 * reversing on 1 November could never post at all on 5 November, which is when a month is
 * actually opened.
 */
function pl_post_scheduled_reversal(int $actorId, int $companyId, int $bookId, int $journalId, string $reverseOn, int $periodId, string $trigger, string $reason): array
{
    $reversal = pl_reverse_journal($actorId, $companyId, $bookId, $journalId, $reverseOn,
        pl_scheduled_reversal_key($journalId), $reason, true);
    DB::insert('pl_journal_reversal_events', ['company_id' => $companyId, 'book_id' => $bookId, 'journal_id' => $journalId,
        'event' => 'posted', 'trigger_source' => $trigger, 'reverse_on' => $reverseOn, 'period_id' => $periodId,
        'reversal_journal_id' => (int) $reversal['id'], 'reason' => $reason, 'actor_id' => $actorId]);
    return ['id' => (int) $reversal['id'], 'reference' => (string) $reversal['reference'], 'date' => (string) $reversal['journal_date']];
}

/**
 * B89's first path: post every reversal that the period just opened has made due.
 *
 * Called from inside pl_create_period() and pl_change_period_status(), which already hold the
 * book lock, so every reversal commits with the act that made it due or with none of it.
 *
 * A single journal that cannot be reversed — its account was deactivated, the window is
 * reconciled, an inventory or purchasing rule refuses it — is recorded and skipped rather than
 * failing the whole period opening. Each reversal posts in its own nested transaction, so a
 * refusal rolls back to that savepoint and leaves the ones before it standing. The checklist
 * keeps reading "reversals pending" for whatever did not post, which is the honest answer.
 *
 * @return array{posted:list<array<string,mixed>>, refused:list<array{reference:string, message:string}>}
 */
function pl_post_due_reversals(int $actorId, int $companyId, int $bookId, array $period, string $trigger): array
{
    $posted = [];
    $refused = [];
    foreach (pl_period_pending_reversals($companyId, $bookId, $period) as $due) {
        try {
            $posted[] = pl_post_scheduled_reversal($actorId, $companyId, $bookId, $due['id'], $due['reverse_on'], (int) $period['id'], $trigger,
                'Scheduled reversal of ' . $due['reference']) + ['original' => $due['reference']];
        } catch (DomainException $error) {
            $refused[] = ['reference' => $due['reference'], 'message' => $error->getMessage()];
        }
    }
    return ['posted' => $posted, 'refused' => $refused];
}

/** The receipt trail for one journal's scheduled reversal. */
function pl_journal_reversal_history(int $actorId, int $companyId, int $bookId, int $journalId): array
{
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $journalId): array {
        pl_require_company_access($actorId, $companyId);
        pl_ledger_book($companyId, $bookId);
        return DB::query('SELECT e.id, e.event, e.trigger_source, e.reverse_on, e.reversal_journal_id, e.reason, e.created_at, u.display_name AS actor_name FROM pl_journal_reversal_events e JOIN pl_users u ON u.id = e.actor_id WHERE e.company_id = %i AND e.book_id = %i AND e.journal_id = %i ORDER BY e.id FOR SHARE', $companyId, $bookId, $journalId);
    });
}
