<?php
declare(strict_types=1);

/*
 * Fixed-asset register, depreciation and disposal (1.3 M14, issue #95).
 *
 * The register is a subsidiary ledger, not a second set of books. Nothing here writes a
 * journal row: every posting goes through `pl_post_journal()`, the one posting service,
 * which is what applies the closed-period refusal, the immutability rule, the request-key
 * idempotency and the balance check. What this file adds is the asset, the policy that
 * decides how much of it is consumed in a period, and the event trail that makes the
 * register reconcile to its control accounts instead of merely agreeing with them on a
 * good day.
 *
 * Three rules are worth reading before changing anything in here.
 *
 * 1. **Every reported figure is a sum of `pl_asset_events` rows, and every event row is
 *    backed by one journal.** Cost is `SUM(cost_amount)`, accumulated depreciation is
 *    `SUM(depreciation_amount)`, and both are signed, so a disposal that credits the cost
 *    account by 1,000 writes `cost_amount = -1000`. `pl_asset_register()` then compares
 *    those sums with the trial balance of the control accounts and reports the difference
 *    as a figure, rather than asserting there is none.
 *
 * 2. **A correction is a linked reversal, never an edit.** The events table refuses UPDATE
 *    and DELETE in the database. Undoing a depreciation run posts `pl_reverse_journal()`
 *    against the run's journal and writes mirror events with every amount negated and the
 *    period of the row they reverse. Re-running that period then computes
 *    `scheduled - posted` and posts the difference, which after a full reversal is the
 *    whole charge again and after a partial change is only the change.
 *
 * 3. **The charges over an asset's life sum to cost less residual value, exactly.** Not
 *    approximately, and not with a rounding difference parked in the last period by
 *    accident. Straight line allocates the *cumulative* figure and takes each period's
 *    charge as the difference between consecutive cumulative amounts, so the rounding of
 *    period k never compounds into period k+1 and the final cumulative is the depreciable
 *    amount by construction. Reducing balance caps each charge at the remaining amount
 *    above residual value and writes the remainder off in the last period of the useful
 *    life, which is the ordinary true-up and the only way a reducing balance ever reaches
 *    a residual value at all. `tests/asset_test.php` proves both, including for a life
 *    that does not divide evenly into the periods.
 *
 * Methods implemented: straight line and reducing balance. Deliberately not implemented
 * in 1.3: units of production, sum-of-the-years-digits, double-declining with an automatic
 * switch to straight line, componentisation, revaluation, and a separate tax-depreciation
 * basis (issue #95 wants that last one as data for a country tax plugin; the schedule
 * engine below is already parameterised for it, but nothing stores or exposes a second
 * basis yet).
 */

/** The depreciation methods this release computes. */
function pl_asset_methods(): array
{
    return [
        'straight_line' => 'Straight line',
        'reducing_balance' => 'Reducing balance (written-down value)',
    ];
}

/**
 * The depreciation convention, which is the module's and not a per-class choice.
 *
 * Owner decision, 21 September 2026: **a full month in the month an asset enters service,
 * and none in the month it is disposed of.** It is standard, acceptable practice for a
 * simplified asset schedule and it avoids fractional day-weighting, which is why an earlier
 * draft that weighted accounting periods by days was replaced rather than kept as an option.
 *
 * Two consequences worth stating plainly, because they are what an accountant will check:
 *
 *  - A month is charged to the accounting period that contains **the last day of that
 *    month**, so a stub first fiscal period gets the months that end inside it and no
 *    fraction of a month is ever split across two periods.
 *  - "None in the month of disposal" does not mean "none in the period of disposal". An
 *    asset sold in June of an annual book is still charged for January to May, and
 *    `pl_dispose_asset()` posts exactly that, dated on the day of disposal, before it
 *    removes the asset — so the gain or loss is measured against a carrying amount that is
 *    right on the day.
 *
 * The date the months are counted from is the **in-service** date, which defaults to the
 * acquisition date, so for an asset that is usable when it is bought the two are the same.
 */
function pl_asset_convention_label(): string
{
    return 'A full month in the month it enters service, and none in the month it is disposed of';
}

/**
 * The chart heading the cost account belongs under, named by its `semantic_key`.
 *
 * Not by its display name, which differs by language and by book, and not by its number,
 * which would make this module the second place that knows what that number is. Matching an
 * account by key is what `pos_functions.php` does for cash and sales income, what
 * `owner_functions.php` does for the owner loan and the contra accounts, and what
 * `demo_pack_functions.php` does for sales returns; nothing in this repository matches an
 * account by display name and nothing should start.
 *
 * The key itself is owned by `m15/chart-headings`, which is adding Property, Plant and
 * Equipment as a new heading and following the convention in
 * `resources/coa/core-starter-1.1.0.json`. This is the one string to change when that branch
 * reports its fourteen keys back; everything else here reads the group number off whichever
 * account carries it.
 */
function pl_asset_cost_group_key(): string
{
    return 'core.group.asset.ppe';
}

/** The four accounts this module needs in a book, and how to create one that is missing. */
function pl_asset_account_purposes(): array
{
    return [
        // `group_key` is the heading this account belongs under, named by semantic key. When
        // the chart has no account carrying it - a hand-built chart, or one that predates the
        // headings work, where `owner_functions.php` records that semantic keys are written
        // only by the starter template at company creation - the allocation falls back to the
        // next free group, exactly as migration 039 allocated the two advances controls, and
        // nothing about the module changes.
        'cost' => ['name' => 'Fixed assets at cost', 'type' => 'asset', 'contra' => false, 'semantic_key' => null, 'group_key' => pl_asset_cost_group_key()],
        // Read, never written: `semantic_key` has exactly two writers and migration 040
        // depends on that. If the book's chart already carries the accumulated-depreciation
        // purpose, that account is used; otherwise one is created in the reserved contra group.
        'accumulated_depreciation' => ['name' => 'Accumulated depreciation', 'type' => 'asset', 'contra' => true, 'semantic_key' => 'core.asset.accumulated_depreciation', 'group_key' => null],
        'depreciation_expense' => ['name' => 'Depreciation', 'type' => 'expense', 'contra' => false, 'semantic_key' => null, 'group_key' => null],
        // Owner decision, 21 September 2026: the gain or loss on disposal goes to a single
        // **non-operating income** account, with both signs sharing it — a gain is a credit
        // in it and a loss is a debit. Routing it through operating expenses, which is where
        // the sample packs pointed it, distorts gross margin and operating profit: selling a
        // van is not a cost of trading. One account keeps the two sides of the same event
        // together and keeps the presentation below the operating result.
        'disposal' => ['name' => 'Other income: gain or loss on disposal of assets', 'type' => 'income', 'contra' => false, 'semantic_key' => null, 'group_key' => null],
    ];
}

/**
 * Half-up to the ledger's four decimal places, away from zero for a negative value.
 *
 * `pl_amount()` is the validator for an amount that arrives from outside and refuses a
 * negative, which is right for an input and wrong for an intermediate: a correction and a
 * loss on disposal are both legitimately negative inside this module.
 */
function pl_asset_round(string $value): string
{
    $rounded = str_starts_with($value, '-') ? bcsub($value, '0.00005', 4) : bcadd($value, '0.00005', 4);
    return bccomp($rounded, '0', 4) === 0 ? '0.0000' : $rounded;
}

/** Calendar month arithmetic that clamps to the last day, so 31 January plus one month is 28 February. */
function pl_asset_add_months(string $date, int $months): string
{
    $parts = explode('-', $date);
    $total = ((int) $parts[0] * 12) + ((int) $parts[1] - 1) + $months;
    $year = intdiv($total, 12);
    $month = ($total % 12) + 1;
    $last = (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), new DateTimeZone('UTC')))->format('t');
    return sprintf('%04d-%02d-%02d', $year, $month, min((int) $parts[2], $last));
}

function pl_asset_previous_day(string $date): string
{
    return (new DateTimeImmutable($date, new DateTimeZone('UTC')))->modify('-1 day')->format('Y-m-d');
}

function pl_asset_next_day(string $date): string
{
    return (new DateTimeImmutable($date, new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
}

/** The first day of the month a date falls in. */
function pl_asset_month_start(string $date): string
{
    return substr($date, 0, 7) . '-01';
}

/** The last day of the month a date falls in. */
function pl_asset_month_end(string $date): string
{
    return (new DateTimeImmutable(pl_asset_month_start($date), new DateTimeZone('UTC')))->format('Y-m-t');
}

/** Which month of the schedule a date falls in, counting the starting month as 1. */
function pl_asset_month_index(string $from, string $to): int
{
    $start = explode('-', $from);
    $end = explode('-', $to);
    return (((int) $end[0] - (int) $start[0]) * 12) + ((int) $end[1] - (int) $start[1]) + 1;
}

/** Inclusive day count: a single day is one day, not zero. */
function pl_asset_days(string $from, string $to): int
{
    $start = new DateTimeImmutable($from, new DateTimeZone('UTC'));
    $end = new DateTimeImmutable($to, new DateTimeZone('UTC'));
    return (int) $start->diff($end)->days + 1;
}

/** The depreciation policy in force for one asset: its class, with the asset's own overrides applied. */
function pl_asset_plan(array $asset, array $class): array
{
    return [
        'in_service_date' => (string) $asset['in_service_date'],
        'cost' => (string) $asset['cost'],
        'residual_value' => (string) $asset['residual_value'],
        'method' => (string) $class['method'],
        'useful_life_months' => (int) ($asset['useful_life_months'] ?? 0) ?: (int) $class['useful_life_months'],
        'annual_rate' => $asset['annual_rate'] !== null ? (string) $asset['annual_rate'] : ($class['annual_rate'] !== null ? (string) $class['annual_rate'] : null),
        // A disposed asset's schedule stops at the month before the one it left in, so every
        // read — the register, the statement, the run preview — sees the same truncation and
        // no caller has to remember to apply it.
        'disposal_date' => ($asset['disposal_date'] ?? null) === null ? null : (string) $asset['disposal_date'],
    ];
}

/**
 * The whole depreciation schedule for one asset, over the accounting periods a book has.
 *
 * A pure function: no database, no clock, no session. It is the piece that has to be right,
 * so it is the piece that can be tested with a table of numbers.
 *
 * **Whole months, by owner decision.** The useful life is `useful_life_months` calendar
 * months beginning with the month the asset enters service — a full month in that month,
 * whatever day of it the asset arrived. Each of those months is charged to the accounting
 * period that contains **the last day of the month**, so a month is never split across two
 * periods and a stub first fiscal period simply gets the months that end inside it. If the
 * asset has been disposed of, the schedule stops at the month **before** the month of
 * disposal, because no depreciation is charged in the month an asset leaves.
 *
 * **The allocation is cumulative, and the denominator is the whole life.** Straight line
 * computes `round(depreciable x months so far / life)` and takes each period's charge as the
 * difference between consecutive cumulative amounts. Two things follow, and both matter:
 * four-decimal error never compounds along the life, so the charges sum to exactly cost less
 * residual value even for a life that does not divide evenly; and opening a later accounting
 * period never rewrites an earlier period's charge, so a posted charge cannot come to
 * disagree with the schedule that produced it.
 *
 * Reducing balance takes `carrying amount x rate x months in the period / 12` and writes the
 * remainder off in the period holding the last month of the life, which is the ordinary
 * true-up and the only way a reducing balance reaches a residual value at all.
 *
 * @param array $plan    from pl_asset_plan()
 * @param array $periods list of ['id','start_date','end_date'], ascending, non-overlapping
 * @return list<array{period_id:int,start_date:string,end_date:string,months:int,charge:string,accumulated:string,closing_nbv:string}>
 */
function pl_asset_schedule(array $plan, array $periods): array
{
    $cost = pl_amount((string) $plan['cost']);
    $residual = pl_amount((string) $plan['residual_value']);
    $depreciable = bcsub($cost, $residual, 4);
    $life = (int) $plan['useful_life_months'];
    $firstMonth = pl_asset_month_start((string) $plan['in_service_date']);
    $lastMonth = $life;
    if (($plan['disposal_date'] ?? null) !== null) {
        // None in the month of disposal: the last month charged is the one before it.
        $lastMonth = min($life, pl_asset_month_index($firstMonth, (string) $plan['disposal_date']) - 1);
    }
    if ($lastMonth < 1) { return []; }

    // Each month of the life belongs to the period containing its last day. A month with no
    // period is not charged at all: the books have not been opened that far, and nothing here
    // invents a period to post into.
    $byPeriod = [];
    for ($month = 1; $month <= $lastMonth; $month++) {
        $monthEnd = pl_asset_month_end(pl_asset_add_months($firstMonth, $month - 1));
        foreach ($periods as $index => $period) {
            if ((string) $period['start_date'] <= $monthEnd && $monthEnd <= (string) $period['end_date']) {
                $byPeriod[$index] ??= ['period' => $period, 'months' => 0, 'last_month' => 0];
                $byPeriod[$index]['months']++;
                $byPeriod[$index]['last_month'] = $month;
                break;
            }
        }
    }
    if ($byPeriod === []) { return []; }
    ksort($byPeriod);

    $rows = [];
    $accumulated = '0.0000';
    $nbv = $cost;
    $cumulative = 0;
    $rate = $plan['annual_rate'] === null ? null : bcadd((string) $plan['annual_rate'], '0', 6);
    foreach ($byPeriod as $entry) {
        $cumulative += $entry['months'];
        // The period that holds the final month of the useful life is the one that trues up,
        // for either method. A schedule truncated by a disposal has no such period, and the
        // rest of the carrying amount leaves through the disposal entry instead.
        $isLifeEnd = $entry['last_month'] === $life;
        if ($plan['method'] === 'reducing_balance') {
            if ($isLifeEnd) {
                $charge = bcsub($nbv, $residual, 4);
            } else {
                $charge = pl_asset_round(bcdiv(bcmul(bcmul($nbv, (string) $rate, 12), (string) $entry['months'], 12), '12', 12));
                $room = bcsub($nbv, $residual, 4);
                if (bccomp($charge, $room, 4) > 0) { $charge = $room; }
            }
            if (bccomp($charge, '0', 4) < 0) { $charge = '0.0000'; }
            $accumulated = bcadd($accumulated, $charge, 4);
        } else {
            $target = $isLifeEnd
                ? $depreciable
                : pl_asset_round(bcdiv(bcmul($depreciable, (string) $cumulative, 12), (string) $life, 12));
            $charge = bcsub($target, $accumulated, 4);
            $accumulated = $target;
        }
        $nbv = bcsub($cost, $accumulated, 4);
        $rows[] = [
            'period_id' => (int) $entry['period']['id'],
            'start_date' => (string) $entry['period']['start_date'],
            'end_date' => (string) $entry['period']['end_date'],
            'months' => $entry['months'],
            'charge' => $charge,
            'accumulated' => $accumulated,
            'closing_nbv' => $nbv,
        ];
    }
    return $rows;
}

/** Every accounting period of a book, ascending. The schedule is built over these and no others. */
function pl_asset_book_periods(int $companyId, int $bookId): array
{
    $rows = DB::query('SELECT id, start_date, end_date, status FROM pl_periods WHERE company_id = %i AND book_id = %i ORDER BY start_date', $companyId, $bookId);
    foreach ($rows as &$row) { $row['id'] = (int) $row['id']; }
    unset($row);
    return $rows;
}

// ---------------------------------------------------------------------------- accounts

/**
 * The book's four asset accounts, creating any that are missing.
 *
 * Nothing is created by the migration: a book that never enables this module never gains
 * an account it has no use for, and the bundled starter chart is left alone so no company
 * is asked to re-review its chart and `pl_confirm_existing_setup()` still asks for the
 * same thirteen mappings. Each account is created through `pl_save_account()`, so it gets
 * the ordinary `pl_core_audit` row a chart addition gets.
 */
function pl_asset_provision_accounts(int $actorId, int $companyId, int $bookId): array
{
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId): array {
        pl_require_company_access($actorId, $companyId, true);
        pl_ledger_book($companyId, $bookId, true);
        $result = [];
        foreach (pl_asset_account_purposes() as $purpose => $definition) {
            $existing = DB::queryFirstField('SELECT account_id FROM pl_asset_accounts WHERE book_id = %i AND purpose = %s FOR UPDATE', $bookId, $purpose);
            if ($existing !== null) { $result[$purpose] = (int) $existing; continue; }
            $accountId = null;
            if ($definition['semantic_key'] !== null) {
                $found = DB::queryFirstField('SELECT id FROM pl_accounts WHERE company_id = %i AND book_id = %i AND semantic_key = %s AND is_active = 1 FOR SHARE',
                    $companyId, $bookId, $definition['semantic_key']);
                if ($found !== null) { $accountId = (int) $found; }
            }
            if ($accountId === null) {
                $account = pl_save_account($actorId, $companyId, $bookId, [
                    'code' => pl_asset_next_account_code($companyId, $bookId, (string) $definition['type'], (bool) $definition['contra'], $definition['group_key'] ?? null),
                    'name' => (string) $definition['name'],
                    'type' => (string) $definition['type'],
                    'is_active' => true,
                    'is_contra' => (bool) $definition['contra'],
                    'is_monetary' => false,
                    'reason' => 'Fixed assets module provisioned its ' . str_replace('_', ' ', $purpose) . ' account',
                    'creation_key' => 'fixed-assets:' . $bookId . ':' . $purpose,
                ]);
                $accountId = (int) $account['id'];
            }
            DB::insert('pl_asset_accounts', ['company_id' => $companyId, 'book_id' => $bookId, 'purpose' => $purpose, 'account_id' => $accountId, 'created_by' => $actorId]);
            $result[$purpose] = $accountId;
        }
        return $result;
    });
}

/**
 * The next free structured code for a provisioned account.
 *
 * A contra asset goes in group 900, the group `pl_account_contra_groups()` reserves for
 * accumulated depreciation (B60). Everything else takes the next free ten-step below the
 * reserved band of its class, the same allocation migration 039 used for the two advances
 * controls, so a converted chart and a chart born on 1.2 both land on a free code.
 */
function pl_asset_next_account_code(int $companyId, int $bookId, string $type, bool $contra, ?string $groupKey = null): string
{
    $class = pl_account_code_class_for_type($type);
    $inGroup = static function (int $group) use ($companyId, $bookId, $class): string {
        $used = DB::queryFirstField('SELECT MAX(CAST(SUBSTRING(code, 7, 5) AS UNSIGNED)) FROM pl_accounts WHERE company_id = %i AND book_id = %i AND code LIKE %s',
            $companyId, $bookId, $class . '-' . str_pad((string) $group, 3, '0', STR_PAD_LEFT) . '-%');
        return pl_account_code_format($class, $group, ((int) $used ?: 10000) + 1, 0);
    };
    if ($contra) {
        return $inGroup(array_key_first(pl_account_contra_groups()[$class] ?? []) ?? 900);
    }
    // When the chart carries the heading this account belongs under, put the account inside
    // it rather than opening a group of its own. The heading is found by its semantic key and
    // the group number is then read off whatever that key points at, so this module never
    // knows the number and a chart that moves the heading moves the account with it.
    //
    // The lookup matches on key, classification and active status and deliberately **not** on
    // `role`. No heading account carries one: `pl_ar_control()` and `pl_advance_control()` each
    // require exactly one active account per role, so a heading claiming a role would make them
    // refuse every document in the book. Do not "tighten" this query by adding one.
    if ($groupKey !== null) {
        $heading = DB::queryFirstField('SELECT code FROM pl_accounts WHERE company_id = %i AND book_id = %i AND semantic_key = %s AND type = %s AND is_active = 1 FOR SHARE',
            $companyId, $bookId, $groupKey, $type);
        if ($heading !== null && pl_account_code_is_valid((string) $heading) && pl_account_code_level((string) $heading) === 'group') {
            return $inGroup(pl_account_code_parse((string) $heading)['group']);
        }
    }
    $used = DB::queryFirstField('SELECT MAX(CAST(SUBSTRING(code, 3, 3) AS UNSIGNED)) FROM pl_accounts
        WHERE company_id = %i AND book_id = %i AND type = %s AND code LIKE %s AND CAST(SUBSTRING(code, 3, 3) AS UNSIGNED) < %i',
        $companyId, $bookId, $type, '_-___-_____-__', pl_account_code_last_group() + 1);
    $group = ((int) $used ?: 90) + 10;
    if ($group > pl_account_code_last_group()) {
        throw new DomainException('This chart has no free group left below the reserved contra band. Choose existing accounts for the asset class instead.');
    }
    return pl_account_code_format($class, $group, 10001, 0);
}

// ----------------------------------------------------------------------- asset classes

/** Validated class input. Account identifiers are optional: an omitted one takes the provisioned default. */
function pl_asset_class_input(array $input): array
{
    $code = pl_ledger_text($input['code'] ?? null, 'Class code', 20);
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,19}$/D', $code)) {
        throw new DomainException('Use letters, digits, dots, dashes or underscores for the asset class code.');
    }
    $method = $input['method'] ?? 'straight_line';
    if (!is_string($method) || !isset(pl_asset_methods()[$method])) {
        throw new DomainException('Choose a depreciation method this release computes.');
    }
    $life = $input['useful_life_months'] ?? null;
    if (!is_int($life) || $life < 1 || $life > 1200) {
        throw new DomainException('Give a useful life between 1 and 1,200 months. Reducing balance needs one too: it is when the remaining value is written down to the residual value.');
    }
    $rate = null;
    if ($method === 'reducing_balance') {
        $rate = pl_asset_rate_input($input['annual_rate'] ?? null);
    } elseif (($input['annual_rate'] ?? null) !== null && $input['annual_rate'] !== '') {
        throw new DomainException('A straight-line class is driven by its useful life, not by a rate.');
    }
    if (!is_bool($input['is_active'] ?? null)) { throw new DomainException('Choose whether this asset class is active.'); }
    return [
        'code' => $code,
        'name' => pl_ledger_text($input['name'] ?? null, 'Class name', 120),
        'method' => $method,
        'useful_life_months' => $life,
        'annual_rate' => $rate,
        'is_active' => $input['is_active'],
    ];
}

/** A reducing-balance rate as a fraction of one, to six places: 15% is 0.150000. */
function pl_asset_rate_input(mixed $value): string
{
    // One sentence for every way the rate can be wrong, including missing: a reducing-balance
    // class without a rate is not a type error to the person filling the form in.
    $raw = is_string($value) || is_int($value) ? trim((string) $value) : '';
    if ($raw === '' || strlen($raw) > 12 || !preg_match('/^0(\.[0-9]{1,6})?$|^0?\.[0-9]{1,6}$/D', $raw)) {
        throw new DomainException('Give the reducing-balance rate as a fraction of one above 0 and below 1, for example 0.15 for 15 per cent.');
    }
    $rate = bcadd($raw, '0', 6);
    if (bccomp($rate, '0', 6) <= 0 || bccomp($rate, '1', 6) >= 0) {
        throw new DomainException('Give the reducing-balance rate as a fraction of one above 0 and below 1, for example 0.15 for 15 per cent.');
    }
    return $rate;
}

function pl_get_asset_class(int $actorId, int $companyId, int $bookId, int $id): array
{
    pl_require_company_access($actorId, $companyId);
    pl_ledger_book($companyId, $bookId);
    $row = DB::queryFirstRow('SELECT * FROM pl_asset_classes WHERE id = %i AND company_id = %i AND book_id = %i FOR SHARE', $id, $companyId, $bookId);
    if (!$row) { throw new DomainException('This asset class is not available in the selected company and book.'); }
    return pl_asset_class_row($row);
}

function pl_asset_class_row(array $row): array
{
    foreach (['id', 'company_id', 'book_id', 'useful_life_months', 'revision', 'asset_account_id', 'accumulated_account_id', 'expense_account_id', 'disposal_account_id'] as $field) {
        $row[$field] = (int) $row[$field];
    }
    $row['is_active'] = (bool) $row['is_active'];
    $row['annual_rate'] = $row['annual_rate'] === null ? null : bcadd((string) $row['annual_rate'], '0', 6);
    unset($row['creation_key']);
    return $row;
}

function pl_list_asset_classes(int $actorId, int $companyId, int $bookId, bool $activeOnly = false): array
{
    pl_require_company_access($actorId, $companyId);
    pl_ledger_book($companyId, $bookId);
    $rows = DB::query('SELECT c.*, aa.code AS asset_account_code, aa.name AS asset_account_name,
            ac.code AS accumulated_account_code, ac.name AS accumulated_account_name,
            ex.code AS expense_account_code, ex.name AS expense_account_name,
            di.code AS disposal_account_code, di.name AS disposal_account_name
        FROM pl_asset_classes c
        JOIN pl_accounts aa ON aa.id = c.asset_account_id
        JOIN pl_accounts ac ON ac.id = c.accumulated_account_id
        JOIN pl_accounts ex ON ex.id = c.expense_account_id
        JOIN pl_accounts di ON di.id = c.disposal_account_id
        WHERE c.company_id = %i AND c.book_id = %i' . ($activeOnly ? ' AND c.is_active = 1' : '') . ' ORDER BY c.code', $companyId, $bookId);
    return array_map(pl_asset_class_row(...), $rows);
}

/**
 * Create or edit an asset class.
 *
 * The method, life and rate may be edited: they are a policy, not a posting,
 * and changing one changes future charges only, because every charge already posted is a
 * journal and journals do not move. What an edit cannot do is change the code or any of the
 * four accounts once the class has assets, because that would silently re-point postings
 * already made through it away from the control accounts they reconcile to.
 */
function pl_save_asset_class(int $actorId, int $companyId, int $bookId, array $input, ?int $id = null, ?int $revision = null): array
{
    $data = pl_asset_class_input($input);
    $reason = pl_ledger_text($input['reason'] ?? null, 'Reason for this change', 500);
    $key = pl_request_key(pl_ledger_text($input['idempotency_key'] ?? $input['creation_key'] ?? null, 'Request identity', 128));
    $accounts = [];
    foreach (['asset_account_id', 'accumulated_account_id', 'expense_account_id', 'disposal_account_id'] as $field) {
        $value = $input[$field] ?? null;
        if ($value !== null && (!is_int($value) || $value < 1)) { throw new DomainException('Choose a valid account for every part of this asset class.'); }
        $accounts[$field] = $value;
    }
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $id, $revision, $data, $reason, $key, $accounts): array {
        pl_require_module($actorId, $companyId, $bookId, 'fixed-assets');
        pl_ledger_book($companyId, $bookId, true);
        $defaults = pl_asset_provision_accounts($actorId, $companyId, $bookId);
        $data['asset_account_id'] = $accounts['asset_account_id'] ?? $defaults['cost'];
        $data['accumulated_account_id'] = $accounts['accumulated_account_id'] ?? $defaults['accumulated_depreciation'];
        $data['expense_account_id'] = $accounts['expense_account_id'] ?? $defaults['depreciation_expense'];
        $data['disposal_account_id'] = $accounts['disposal_account_id'] ?? $defaults['disposal'];
        pl_asset_validate_class_accounts($actorId, $companyId, $bookId, $data);
        if ($id === null) {
            $prior = DB::queryFirstRow('SELECT id FROM pl_asset_classes WHERE book_id = %i AND creation_key = %s FOR UPDATE', $bookId, $key);
            if ($prior) { return pl_get_asset_class($actorId, $companyId, $bookId, (int) $prior['id']); }
            if (DB::queryFirstField('SELECT id FROM pl_asset_classes WHERE book_id = %i AND code = %s FOR SHARE', $bookId, $data['code'])) {
                throw new DomainException('This asset class code is already in use. Choose a different code.');
            }
            DB::insert('pl_asset_classes', $data + ['company_id' => $companyId, 'book_id' => $bookId, 'creation_key' => $key, 'created_by' => $actorId]);
            $id = (int) DB::insertId();
            $before = null;
        } else {
            $before = pl_get_asset_class($actorId, $companyId, $bookId, $id);
            if ($before['revision'] !== $revision) { throw new DomainException('Someone changed this asset class. Reload it before applying your changes.'); }
            if ($before['code'] !== $data['code']) { throw new DomainException('An asset class code is fixed. Create a separate class instead.'); }
            $hasAssets = DB::queryFirstField('SELECT id FROM pl_assets WHERE company_id = %i AND book_id = %i AND class_id = %i LIMIT 1 FOR SHARE', $companyId, $bookId, $id) !== null;
            foreach (['asset_account_id', 'accumulated_account_id', 'expense_account_id', 'disposal_account_id'] as $field) {
                if ($hasAssets && $before[$field] !== $data[$field]) {
                    throw new DomainException('The accounts of an asset class are fixed once it has assets, because entries have already been posted through them. Create a separate class instead.');
                }
            }
            DB::update('pl_asset_classes', $data + ['revision' => $before['revision'] + 1], 'id = %i AND company_id = %i AND book_id = %i', $id, $companyId, $bookId);
        }
        $result = pl_get_asset_class($actorId, $companyId, $bookId, $id);
        pl_core_audit($actorId, $companyId, $bookId, 'asset_class', $id, $before === null ? 'created' : 'updated', $reason, $before, $result);
        return $result;
    });
}

/**
 * The accounts of a class have to be the right kind, and the accumulated-depreciation
 * account has to be a contra asset. That is the whole point of B60's reserved group: the
 * balance sheet already presents a contra account as a deduction, so the register's net
 * book value and the balance sheet's asset section agree without a second mechanism.
 */
function pl_asset_validate_class_accounts(int $actorId, int $companyId, int $bookId, array $data): void
{
    $expected = [
        'asset_account_id' => ['asset', false, 'The asset cost account must be an asset account that is not a contra account.'],
        'accumulated_account_id' => ['asset', true, 'Accumulated depreciation must be a contra asset account, so the balance sheet presents it as a deduction.'],
        'expense_account_id' => ['expense', false, 'The depreciation charge must go to an expense account.'],
        'disposal_account_id' => ['income', false, 'The gain or loss on disposal must go to an income account; a loss is a debit in it.'],
    ];
    $seen = [];
    foreach ($expected as $field => [$type, $contra, $message]) {
        $account = pl_get_account($actorId, $companyId, $bookId, (int) $data[$field]);
        if ($account['type'] !== $type || $account['is_contra'] !== $contra || !$account['is_active']) { throw new DomainException($message); }
        if (!$account['is_postable']) { throw new DomainException('Choose the lowest account in the chart: ' . $account['code'] . ' aggregates the accounts below it.'); }
        if (isset($seen[$account['id']])) { throw new DomainException('Use a separate account for each part of an asset class.'); }
        $seen[$account['id']] = true;
    }
}

// ------------------------------------------------------------------------------ assets

function pl_asset_input(array $input): array
{
    $code = pl_ledger_text($input['code'] ?? null, 'Asset number', 40);
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,39}$/D', $code)) {
        throw new DomainException('Use letters, digits, dots, dashes, slashes or underscores for the asset number.');
    }
    $acquisition = pl_ledger_date(pl_ledger_text($input['acquisition_date'] ?? null, 'Acquisition date', 10));
    $inService = pl_ledger_date(pl_ledger_text($input['in_service_date'] ?? $input['acquisition_date'] ?? null, 'Date in service', 10));
    if ($inService < $acquisition) { throw new DomainException('An asset cannot enter service before it was acquired.'); }
    $cost = pl_amount(pl_ledger_text($input['cost'] ?? null, 'Cost', 21));
    if (bccomp($cost, '0', 4) <= 0) { throw new DomainException('An asset needs a positive cost.'); }
    $residual = pl_amount(pl_ledger_text((string) ($input['residual_value'] ?? '0'), 'Residual value', 21, false) ?: '0');
    if (bccomp($residual, $cost, 4) > 0) { throw new DomainException('The residual value cannot exceed the cost.'); }
    $life = $input['useful_life_months'] ?? null;
    if ($life !== null && (!is_int($life) || $life < 1 || $life > 1200)) {
        throw new DomainException('A useful-life override must be between 1 and 1,200 months.');
    }
    $rate = ($input['annual_rate'] ?? null) === null || $input['annual_rate'] === '' ? null : pl_asset_rate_input($input['annual_rate']);
    if (!is_int($input['class_id'] ?? null) || $input['class_id'] < 1) { throw new DomainException('Choose the asset class this asset belongs to.'); }
    return [
        'class_id' => $input['class_id'],
        'code' => $code,
        'name' => pl_ledger_text($input['name'] ?? null, 'Asset name', 160),
        'acquisition_date' => $acquisition,
        'in_service_date' => $inService,
        'cost' => $cost,
        'residual_value' => $residual,
        'useful_life_months' => $life,
        'annual_rate' => $rate,
        'location' => pl_ledger_text($input['location'] ?? '', 'Location', 160, false),
        'supplier' => pl_ledger_text($input['supplier'] ?? '', 'Supplier', 160, false),
        'reference' => pl_ledger_text($input['reference'] ?? '', 'Reference', 120, false),
    ];
}

function pl_asset_row(array $row): array
{
    foreach (['id', 'company_id', 'book_id', 'class_id', 'revision'] as $field) { $row[$field] = (int) $row[$field]; }
    foreach (['useful_life_months'] as $field) { $row[$field] = $row[$field] === null ? null : (int) $row[$field]; }
    $row['annual_rate'] = $row['annual_rate'] === null ? null : bcadd((string) $row['annual_rate'], '0', 6);
    $row['cost'] = bcadd((string) $row['cost'], '0', 4);
    $row['residual_value'] = bcadd((string) $row['residual_value'], '0', 4);
    unset($row['creation_key']);
    return $row;
}

function pl_get_asset(int $actorId, int $companyId, int $bookId, int $id): array
{
    pl_require_company_access($actorId, $companyId);
    pl_ledger_book($companyId, $bookId);
    $row = DB::queryFirstRow('SELECT * FROM pl_assets WHERE id = %i AND company_id = %i AND book_id = %i FOR SHARE', $id, $companyId, $bookId);
    if (!$row) { throw new DomainException('This asset is not available in the selected company and book.'); }
    $asset = pl_asset_row($row);
    $totals = pl_asset_totals($companyId, $bookId, $id);
    return $asset + $totals;
}

/** Cost, accumulated depreciation and net book value of one asset, from its events alone. */
function pl_asset_totals(int $companyId, int $bookId, int $assetId, ?string $asOf = null): array
{
    $row = DB::queryFirstRow('SELECT COALESCE(SUM(cost_amount), 0) AS cost, COALESCE(SUM(depreciation_amount), 0) AS accumulated,
            COALESCE(SUM(proceeds_amount), 0) AS proceeds, COALESCE(SUM(gain_loss_amount), 0) AS gain_loss
        FROM pl_asset_events WHERE company_id = %i AND book_id = %i AND asset_id = %i AND event_date <= %s',
        $companyId, $bookId, $assetId, $asOf ?? '9999-12-31');
    $cost = bcadd((string) $row['cost'], '0', 4);
    $accumulated = bcadd((string) $row['accumulated'], '0', 4);
    return [
        'posted_cost' => $cost,
        'posted_accumulated' => $accumulated,
        'net_book_value' => bcsub($cost, $accumulated, 4),
        'posted_proceeds' => bcadd((string) $row['proceeds'], '0', 4),
        'posted_gain_loss' => bcadd((string) $row['gain_loss'], '0', 4),
    ];
}

function pl_list_assets(int $actorId, int $companyId, int $bookId, ?int $classId = null, ?string $status = null): array
{
    pl_require_company_access($actorId, $companyId);
    pl_ledger_book($companyId, $bookId);
    $rows = DB::query('SELECT a.*, c.code AS class_code, c.name AS class_name, c.method
        FROM pl_assets a JOIN pl_asset_classes c ON c.id = a.class_id
        WHERE a.company_id = %i AND a.book_id = %i AND (%i = 0 OR a.class_id = %i) AND (%s = %s OR a.status = %s)
        ORDER BY c.code, a.code', $companyId, $bookId, $classId ?? 0, $classId ?? 0, $status ?? '', '', $status ?? '');
    $assets = [];
    foreach ($rows as $row) {
        $assets[] = pl_asset_row($row) + pl_asset_totals($companyId, $bookId, (int) $row['id']);
    }
    return $assets;
}

/**
 * Record an asset and post its acquisition in one reviewed act.
 *
 * The register would not reconcile to anything if the asset and the entry that put it in
 * the books were recorded separately: the ledger would carry a cost the register never
 * heard of, or the other way round. So the cost account is debited here, through the one
 * posting service, and the account that paid for it — bank, payables, the owner's capital
 * account — is named by the caller and credited. Capitalising an existing posted bill line
 * instead of posting a fresh acquisition is issue #95's remaining task and is not in 1.3.
 */
function pl_save_asset(int $actorId, int $companyId, int $bookId, array $input): array
{
    $data = pl_asset_input($input);
    $reason = pl_ledger_text($input['reason'] ?? null, 'Reason for this entry', 500);
    $key = pl_request_key(pl_ledger_text($input['idempotency_key'] ?? $input['creation_key'] ?? null, 'Request identity', 128));
    if (!is_int($input['credit_account_id'] ?? null) || $input['credit_account_id'] < 1) {
        throw new DomainException('Choose the account the asset was paid from: a bank account, a payables account or the owner capital account.');
    }
    $creditAccountId = $input['credit_account_id'];
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $data, $reason, $key, $creditAccountId): array {
        pl_require_module($actorId, $companyId, $bookId, 'fixed-assets');
        $book = pl_ledger_book($companyId, $bookId, true);
        $prior = DB::queryFirstRow('SELECT id FROM pl_assets WHERE book_id = %i AND creation_key = %s FOR UPDATE', $bookId, $key);
        if ($prior) { return pl_get_asset($actorId, $companyId, $bookId, (int) $prior['id']); }
        $class = pl_get_asset_class($actorId, $companyId, $bookId, (int) $data['class_id']);
        if (!$class['is_active']) { throw new DomainException('This asset class is not active. Choose an active class.'); }
        if ($data['annual_rate'] !== null && $class['method'] !== 'reducing_balance') {
            throw new DomainException('A rate override applies only to a reducing-balance class.');
        }
        if (DB::queryFirstField('SELECT id FROM pl_assets WHERE book_id = %i AND code = %s FOR SHARE', $bookId, $data['code'])) {
            throw new DomainException('This asset number is already in use. Choose a different one.');
        }
        $credit = pl_get_account($actorId, $companyId, $bookId, $creditAccountId);
        if ($credit['id'] === $class['asset_account_id']) { throw new DomainException('An acquisition cannot be paid from the asset cost account itself.'); }
        DB::insert('pl_assets', $data + ['company_id' => $companyId, 'book_id' => $bookId, 'creation_key' => $key, 'created_by' => $actorId]);
        $assetId = (int) DB::insertId();
        $description = 'Fixed asset ' . $data['code'] . ' acquired: ' . $data['name'];
        $journal = pl_post_journal($actorId, $companyId, $bookId, [
            'date' => $data['acquisition_date'], 'currency' => (string) $book['currency'],
            'source_type' => 'asset_acquisition', 'source_reference' => 'asset:' . $assetId,
            'idempotency_key' => 'asset-acq:' . hash('sha256', $bookId . ':' . $key),
            'description' => $description,
            'lines' => [
                ['account_id' => $class['asset_account_id'], 'debit' => $data['cost'], 'credit' => '0.0000', 'description' => $description],
                ['account_id' => $credit['id'], 'debit' => '0.0000', 'credit' => $data['cost'], 'description' => $description],
            ],
        ]);
        pl_asset_record_event($actorId, $companyId, $bookId, [
            'asset_id' => $assetId, 'kind' => 'acquisition', 'event_date' => $data['acquisition_date'],
            'period_id' => (int) $journal['period_id'], 'journal_id' => (int) $journal['id'],
            'cost_amount' => $data['cost'],
        ]);
        $result = pl_get_asset($actorId, $companyId, $bookId, $assetId);
        pl_core_audit($actorId, $companyId, $bookId, 'asset', $assetId, 'created', $reason, null, $result);
        return $result;
    });
}

/**
 * Edit the descriptive fields of an asset.
 *
 * Cost, the dates, the class and the depreciation overrides are fixed once recorded: the
 * acquisition journal behind them is immutable, and a schedule that could be edited after
 * charges had been posted against it would be a schedule the ledger disagrees with. The
 * way to correct one of those is the ordinary way — reverse the acquisition and record the
 * asset again.
 */
function pl_update_asset_details(int $actorId, int $companyId, int $bookId, int $assetId, int $revision, array $input): array
{
    $fields = [
        'name' => pl_ledger_text($input['name'] ?? null, 'Asset name', 160),
        'location' => pl_ledger_text($input['location'] ?? '', 'Location', 160, false),
        'supplier' => pl_ledger_text($input['supplier'] ?? '', 'Supplier', 160, false),
        'reference' => pl_ledger_text($input['reference'] ?? '', 'Reference', 120, false),
    ];
    $reason = pl_ledger_text($input['reason'] ?? null, 'Reason for this change', 500);
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $assetId, $revision, $fields, $reason): array {
        pl_require_module($actorId, $companyId, $bookId, 'fixed-assets');
        pl_ledger_book($companyId, $bookId, true);
        $before = pl_get_asset($actorId, $companyId, $bookId, $assetId);
        if ($before['revision'] !== $revision) { throw new DomainException('Someone changed this asset. Reload it before applying your changes.'); }
        DB::update('pl_assets', $fields + ['revision' => $before['revision'] + 1], 'id = %i AND company_id = %i AND book_id = %i', $assetId, $companyId, $bookId);
        $after = pl_get_asset($actorId, $companyId, $bookId, $assetId);
        pl_core_audit($actorId, $companyId, $bookId, 'asset', $assetId, 'updated', $reason, $before, $after);
        return $after;
    });
}

/** One append-only event row. Callers are already inside the book lock and have already posted. */
function pl_asset_record_event(int $actorId, int $companyId, int $bookId, array $event): int
{
    DB::insert('pl_asset_events', [
        'company_id' => $companyId, 'book_id' => $bookId,
        'asset_id' => (int) $event['asset_id'], 'kind' => (string) $event['kind'],
        'is_reversal' => (int) ($event['is_reversal'] ?? 0),
        'reverses_event_id' => $event['reverses_event_id'] ?? null,
        'event_date' => (string) $event['event_date'], 'period_id' => (int) $event['period_id'],
        'run_id' => $event['run_id'] ?? null, 'journal_id' => (int) $event['journal_id'],
        'cost_amount' => (string) ($event['cost_amount'] ?? '0.0000'),
        'depreciation_amount' => (string) ($event['depreciation_amount'] ?? '0.0000'),
        'proceeds_amount' => (string) ($event['proceeds_amount'] ?? '0.0000'),
        'gain_loss_amount' => (string) ($event['gain_loss_amount'] ?? '0.0000'),
        'created_by' => $actorId,
    ]);
    return (int) DB::insertId();
}

/** Every event of one asset, oldest first, with the journal each one is backed by. */
function pl_asset_events(int $actorId, int $companyId, int $bookId, int $assetId): array
{
    pl_require_company_access($actorId, $companyId);
    pl_ledger_book($companyId, $bookId);
    $rows = DB::query('SELECT e.*, j.source_type, j.description FROM pl_asset_events e JOIN pl_journals j ON j.id = e.journal_id
        WHERE e.company_id = %i AND e.book_id = %i AND e.asset_id = %i ORDER BY e.id', $companyId, $bookId, $assetId);
    foreach ($rows as &$row) {
        foreach (['id', 'asset_id', 'period_id', 'journal_id'] as $field) { $row[$field] = (int) $row[$field]; }
        foreach (['run_id', 'reverses_event_id'] as $field) { $row[$field] = $row[$field] === null ? null : (int) $row[$field]; }
        $row['is_reversal'] = (bool) $row['is_reversal'];
        $row['journal_reference'] = 'PL-' . str_pad((string) $row['journal_id'], 8, '0', STR_PAD_LEFT);
        foreach (['cost_amount', 'depreciation_amount', 'proceeds_amount', 'gain_loss_amount'] as $field) { $row[$field] = bcadd((string) $row[$field], '0', 4); }
    }
    unset($row);
    return $rows;
}

// ------------------------------------------------------------------- depreciation runs

/** What a depreciation run for this period would post, asset by asset, without posting it. */
function pl_asset_depreciation_preview(int $actorId, int $companyId, int $bookId, int $periodId): array
{
    pl_require_company_access($actorId, $companyId);
    pl_ledger_book($companyId, $bookId);
    $period = DB::queryFirstRow('SELECT id, start_date, end_date, status FROM pl_periods WHERE id = %i AND company_id = %i AND book_id = %i FOR SHARE', $periodId, $companyId, $bookId);
    if (!$period) { throw new DomainException('This accounting period is not available in the selected company and book.'); }
    $periods = pl_asset_book_periods($companyId, $bookId);
    $classes = [];
    foreach (pl_list_asset_classes($actorId, $companyId, $bookId) as $class) { $classes[$class['id']] = $class; }
    $lines = [];
    $total = '0.0000';
    foreach (pl_list_assets($actorId, $companyId, $bookId) as $asset) {
        if ($asset['status'] === 'reversed') { continue; }
        // A disposed asset is not skipped: its schedule is already truncated at the month
        // before it left, and pl_dispose_asset() has already posted exactly that, so the
        // subtraction below comes out at zero of its own accord. Skipping it by hand would
        // hide a real difference if the disposal were ever reversed.
        $class = $classes[$asset['class_id']] ?? null;
        if ($class === null) { continue; }
        $scheduled = '0.0000';
        foreach (pl_asset_schedule(pl_asset_plan($asset, $class), $periods) as $row) {
            if ($row['period_id'] === (int) $period['id']) { $scheduled = $row['charge']; break; }
        }
        $posted = pl_asset_posted_in_period($companyId, $bookId, (int) $asset['id'], (int) $period['id']);
        $charge = bcsub($scheduled, $posted, 4);
        if (bccomp($charge, '0', 4) === 0) { continue; }
        $lines[] = [
            'asset_id' => (int) $asset['id'], 'asset_code' => (string) $asset['code'], 'asset_name' => (string) $asset['name'],
            'class_id' => (int) $class['id'], 'class_code' => (string) $class['code'], 'class_name' => (string) $class['name'],
            'scheduled' => $scheduled, 'already_posted' => $posted, 'charge' => $charge,
        ];
        $total = bcadd($total, $charge, 4);
    }
    return [
        'period' => ['id' => (int) $period['id'], 'start_date' => (string) $period['start_date'], 'end_date' => (string) $period['end_date'], 'status' => (string) $period['status']],
        'lines' => $lines, 'total' => $total, 'asset_count' => count($lines),
    ];
}

/** Depreciation already posted for one asset in one period, net of reversals. */
function pl_asset_posted_in_period(int $companyId, int $bookId, int $assetId, int $periodId): string
{
    $value = DB::queryFirstField('SELECT COALESCE(SUM(depreciation_amount), 0) FROM pl_asset_events
        WHERE company_id = %i AND book_id = %i AND asset_id = %i AND period_id = %i AND kind = %s',
        $companyId, $bookId, $assetId, $periodId, 'depreciation');
    return bcadd((string) $value, '0', 4);
}

/**
 * Post the depreciation of one accounting period.
 *
 * Idempotent per asset and per period, in the way the issue asks for: the charge for an
 * asset is `scheduled(asset, period) - already posted(asset, period)`, so running a period
 * twice posts nothing the second time, and re-running it after a correction posts the
 * difference rather than a duplicate. A period with nothing left to post is a no-op and
 * writes no journal at all.
 *
 * The lines are per class, and the positive and negative sides of a class are kept apart
 * rather than netted, so an asset whose charge went down and another in the same class
 * whose charge went up both appear, and the journal still balances.
 */
function pl_run_asset_depreciation(int $actorId, int $companyId, int $bookId, array $input): array
{
    if (!is_int($input['period_id'] ?? null) || $input['period_id'] < 1) { throw new DomainException('Choose the accounting period to depreciate.'); }
    $periodId = $input['period_id'];
    $reason = pl_ledger_text($input['reason'] ?? null, 'Reason for this run', 500);
    $key = pl_request_key(pl_ledger_text($input['idempotency_key'] ?? null, 'Request identity', 128));
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $periodId, $reason, $key): array {
        pl_require_module($actorId, $companyId, $bookId, 'fixed-assets');
        $book = pl_ledger_book($companyId, $bookId, true);
        $existing = DB::queryFirstRow('SELECT id FROM pl_asset_depreciation_runs WHERE book_id = %i AND is_reversal = 0 AND request_key = %s FOR UPDATE', $bookId, $key);
        if ($existing) { return pl_get_asset_depreciation_run($actorId, $companyId, $bookId, (int) $existing['id']); }
        $period = DB::queryFirstRow('SELECT id, start_date, end_date, status FROM pl_periods WHERE id = %i AND company_id = %i AND book_id = %i FOR UPDATE', $periodId, $companyId, $bookId);
        if (!$period) { throw new DomainException('This accounting period is not available in the selected company and book.'); }
        // The posting service refuses a closed period anyway. Saying so before the work is
        // done gives the operator the sentence they can act on instead of a posting error.
        if ($period['status'] !== 'open') { throw new DomainException('This accounting period is closed. Reopen it before posting depreciation for it.'); }
        $preview = pl_asset_depreciation_preview($actorId, $companyId, $bookId, $periodId);
        if ($preview['lines'] === []) {
            return ['id' => 0, 'period_id' => $periodId, 'journal_id' => null, 'total_amount' => '0.0000', 'asset_count' => 0,
                'posted' => false, 'message' => 'Every asset is already depreciated for this period. Nothing was posted.'];
        }
        $classes = [];
        foreach (pl_list_asset_classes($actorId, $companyId, $bookId) as $class) { $classes[$class['id']] = $class; }
        $totals = [];
        foreach ($preview['lines'] as $line) {
            $totals[$line['class_id']] ??= ['positive' => '0.0000', 'negative' => '0.0000'];
            $side = bccomp($line['charge'], '0', 4) > 0 ? 'positive' : 'negative';
            $totals[$line['class_id']][$side] = bcadd($totals[$line['class_id']][$side], $side === 'positive' ? $line['charge'] : bcsub('0', $line['charge'], 4), 4);
        }
        $lines = [];
        $total = '0.0000';
        foreach ($totals as $classId => $sides) {
            $class = $classes[$classId];
            $label = 'Depreciation, ' . $class['name'];
            if (bccomp($sides['positive'], '0', 4) > 0) {
                $lines[] = ['account_id' => $class['expense_account_id'], 'debit' => $sides['positive'], 'credit' => '0.0000', 'description' => $label];
                $lines[] = ['account_id' => $class['accumulated_account_id'], 'debit' => '0.0000', 'credit' => $sides['positive'], 'description' => $label];
            }
            if (bccomp($sides['negative'], '0', 4) > 0) {
                $reverseLabel = 'Depreciation adjustment, ' . $class['name'];
                $lines[] = ['account_id' => $class['accumulated_account_id'], 'debit' => $sides['negative'], 'credit' => '0.0000', 'description' => $reverseLabel];
                $lines[] = ['account_id' => $class['expense_account_id'], 'debit' => '0.0000', 'credit' => $sides['negative'], 'description' => $reverseLabel];
            }
            $total = bcadd($total, bcsub($sides['positive'], $sides['negative'], 4), 4);
        }
        $description = 'Depreciation for the period ended ' . $period['end_date'];
        $journal = pl_post_journal($actorId, $companyId, $bookId, [
            'date' => (string) $period['end_date'], 'currency' => (string) $book['currency'],
            'source_type' => 'asset_depreciation', 'source_reference' => 'asset-period:' . $periodId,
            'idempotency_key' => 'asset-depn:' . hash('sha256', $bookId . ':' . $key),
            'description' => $description, 'lines' => $lines,
        ]);
        $hash = hash('sha256', json_encode([$companyId, $bookId, $periodId, $preview['lines']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        DB::insert('pl_asset_depreciation_runs', [
            'company_id' => $companyId, 'book_id' => $bookId, 'period_id' => $periodId,
            'run_date' => (string) $period['end_date'], 'journal_id' => (int) $journal['id'],
            'total_amount' => $total, 'asset_count' => count($preview['lines']),
            'reason' => $reason, 'request_key' => $key, 'payload_hash' => $hash, 'run_by' => $actorId,
        ]);
        $runId = (int) DB::insertId();
        foreach ($preview['lines'] as $line) {
            pl_asset_record_event($actorId, $companyId, $bookId, [
                'asset_id' => $line['asset_id'], 'kind' => 'depreciation', 'event_date' => (string) $period['end_date'],
                'period_id' => $periodId, 'run_id' => $runId, 'journal_id' => (int) $journal['id'],
                'depreciation_amount' => $line['charge'],
            ]);
        }
        return pl_get_asset_depreciation_run($actorId, $companyId, $bookId, $runId);
    });
}

function pl_get_asset_depreciation_run(int $actorId, int $companyId, int $bookId, int $runId): array
{
    pl_require_company_access($actorId, $companyId);
    pl_ledger_book($companyId, $bookId);
    $row = DB::queryFirstRow('SELECT r.*, p.start_date, p.end_date FROM pl_asset_depreciation_runs r
        JOIN pl_periods p ON p.id = r.period_id WHERE r.id = %i AND r.company_id = %i AND r.book_id = %i FOR SHARE', $runId, $companyId, $bookId);
    if (!$row) { throw new DomainException('This depreciation run is not available in the selected company and book.'); }
    foreach (['id', 'period_id', 'journal_id', 'asset_count'] as $field) { $row[$field] = (int) $row[$field]; }
    $row['reverses_run_id'] = $row['reverses_run_id'] === null ? null : (int) $row['reverses_run_id'];
    $row['is_reversal'] = (bool) $row['is_reversal'];
    $row['total_amount'] = bcadd((string) $row['total_amount'], '0', 4);
    $row['journal_reference'] = 'PL-' . str_pad((string) $row['journal_id'], 8, '0', STR_PAD_LEFT);
    $row['reversed'] = DB::queryFirstField('SELECT id FROM pl_asset_depreciation_runs WHERE reverses_run_id = %i FOR SHARE', $runId) !== null;
    $row['posted'] = true;
    unset($row['request_key'], $row['payload_hash']);
    return $row;
}

function pl_list_asset_depreciation_runs(int $actorId, int $companyId, int $bookId): array
{
    pl_require_company_access($actorId, $companyId);
    pl_ledger_book($companyId, $bookId);
    $ids = DB::queryFirstColumn('SELECT id FROM pl_asset_depreciation_runs WHERE company_id = %i AND book_id = %i ORDER BY id DESC LIMIT 200', $companyId, $bookId);
    return array_map(fn (mixed $id): array => pl_get_asset_depreciation_run($actorId, $companyId, $bookId, (int) $id), $ids);
}

/**
 * Undo a depreciation run with a linked reversal.
 *
 * `pl_reverse_journal()` is the core's linked reversal: it posts the exact mirror of the
 * original under `source_type = 'reversal'` with the original journal as its source
 * reference, and refuses a second reversal of the same journal. The run and its events get
 * their own mirror rows here, carrying the period of the rows they reverse, so the next run
 * for that period sees the charge as unposted again and posts the difference.
 */
function pl_reverse_asset_depreciation_run(int $actorId, int $companyId, int $bookId, int $runId, ?string $date, array $input): array
{
    $reason = pl_ledger_text($input['reason'] ?? null, 'Reason for this correction', 400);
    $key = pl_request_key(pl_ledger_text($input['idempotency_key'] ?? null, 'Request identity', 128));
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $runId, $date, $reason, $key): array {
        pl_require_module($actorId, $companyId, $bookId, 'fixed-assets');
        pl_ledger_book($companyId, $bookId, true);
        $existing = DB::queryFirstRow('SELECT id FROM pl_asset_depreciation_runs WHERE book_id = %i AND is_reversal = 1 AND request_key = %s FOR UPDATE', $bookId, $key);
        if ($existing) { return pl_get_asset_depreciation_run($actorId, $companyId, $bookId, (int) $existing['id']); }
        $run = pl_get_asset_depreciation_run($actorId, $companyId, $bookId, $runId);
        if ($run['is_reversal']) { throw new DomainException('A correction cannot itself be corrected. Run the period again instead.'); }
        if ($run['reversed']) { throw new DomainException('This depreciation run has already been reversed.'); }
        $reversal = pl_reverse_journal($actorId, $companyId, $bookId, $run['journal_id'], $date, 'asset-depn-rev:' . hash('sha256', $bookId . ':' . $key), $reason);
        DB::insert('pl_asset_depreciation_runs', [
            'company_id' => $companyId, 'book_id' => $bookId, 'period_id' => $run['period_id'],
            'run_date' => (string) $reversal['journal_date'], 'journal_id' => (int) $reversal['id'],
            'total_amount' => bcsub('0', $run['total_amount'], 4), 'asset_count' => $run['asset_count'],
            'is_reversal' => 1, 'reverses_run_id' => $runId, 'reason' => $reason,
            'request_key' => $key, 'payload_hash' => hash('sha256', 'reversal:' . $runId), 'run_by' => $actorId,
        ]);
        $reversalRunId = (int) DB::insertId();
        $events = DB::query('SELECT id, asset_id, period_id, depreciation_amount FROM pl_asset_events WHERE company_id = %i AND book_id = %i AND run_id = %i AND is_reversal = 0 ORDER BY id', $companyId, $bookId, $runId);
        foreach ($events as $event) {
            pl_asset_record_event($actorId, $companyId, $bookId, [
                'asset_id' => (int) $event['asset_id'], 'kind' => 'depreciation', 'is_reversal' => 1,
                'reverses_event_id' => (int) $event['id'], 'event_date' => (string) $reversal['journal_date'],
                // The period of the row it reverses, not the period it is dated in: what the
                // next run has to see is that this period's charge is outstanding again.
                'period_id' => (int) $event['period_id'], 'run_id' => $reversalRunId, 'journal_id' => (int) $reversal['id'],
                'depreciation_amount' => bcsub('0', bcadd((string) $event['depreciation_amount'], '0', 4), 4),
            ]);
        }
        return pl_get_asset_depreciation_run($actorId, $companyId, $bookId, $reversalRunId);
    });
}

// -------------------------------------------------------------------------- disposal

function pl_asset_disposal_kinds(): array
{
    return ['sale' => 'Sold for proceeds', 'scrap' => 'Scrapped or written off'];
}

/**
 * Dispose of an asset: remove its cost and its accumulated depreciation, recognise the
 * proceeds, and post the difference as the gain or loss.
 *
 * The asset must be depreciated up to the disposal: every period that *ended* before the
 * disposal date must have its charge posted for this asset. Without that guard the
 * difference between the proceeds and an out-of-date carrying amount lands in the gain or
 * loss, which is the classic way a disposal quietly absorbs a year of missing depreciation.
 * The period the disposal falls in takes no charge, which is the convention this module
 * states and keeps.
 */
function pl_dispose_asset(int $actorId, int $companyId, int $bookId, int $assetId, array $input): array
{
    $kind = $input['kind'] ?? 'sale';
    if (!is_string($kind) || !isset(pl_asset_disposal_kinds()[$kind])) { throw new DomainException('Choose whether the asset was sold or scrapped.'); }
    $date = pl_ledger_date(pl_ledger_text($input['date'] ?? null, 'Disposal date', 10));
    $proceeds = $kind === 'scrap' ? '0.0000' : pl_amount(pl_ledger_text((string) ($input['proceeds'] ?? '0'), 'Proceeds', 21, false) ?: '0');
    $reason = pl_ledger_text($input['reason'] ?? null, 'Reason for this entry', 500);
    $key = pl_request_key(pl_ledger_text($input['idempotency_key'] ?? null, 'Request identity', 128));
    $proceedsAccountId = $input['proceeds_account_id'] ?? null;
    if (bccomp($proceeds, '0', 4) > 0 && (!is_int($proceedsAccountId) || $proceedsAccountId < 1)) {
        throw new DomainException('Choose the account the proceeds were received into.');
    }
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $assetId, $kind, $date, $proceeds, $proceedsAccountId, $reason, $key): array {
        pl_require_module($actorId, $companyId, $bookId, 'fixed-assets');
        $book = pl_ledger_book($companyId, $bookId, true);
        $prior = DB::queryFirstRow('SELECT id FROM pl_asset_events WHERE company_id = %i AND book_id = %i AND asset_id = %i AND kind = %s AND is_reversal = 0 FOR UPDATE',
            $companyId, $bookId, $assetId, 'disposal');
        $asset = pl_get_asset($actorId, $companyId, $bookId, $assetId);
        if ($asset['status'] === 'disposed' || $prior) { throw new DomainException('This asset has already been disposed of. Reverse the disposal first if it was wrong.'); }
        if ($asset['status'] === 'reversed') { throw new DomainException('This asset was removed by a reversed acquisition and holds nothing to dispose of.'); }
        if ($date < $asset['in_service_date']) { throw new DomainException('An asset cannot be disposed of before it entered service.'); }
        $class = pl_get_asset_class($actorId, $companyId, $bookId, (int) $asset['class_id']);
        // Depreciate to the day, then dispose. The convention charges a full month in the
        // month an asset enters service and none in the month it leaves, so an asset sold in
        // June of an annual book still owes January to May. Posting that here, dated on the
        // day of disposal and as its own entry, is what makes the gain or loss a measurement
        // against the carrying amount on the day rather than against a year-old one.
        pl_asset_settle_depreciation_to_disposal($actorId, $companyId, $bookId, $asset, $class, (string) $book['currency'], $date, $key);
        $asset = pl_get_asset($actorId, $companyId, $bookId, $assetId);
        $cost = $asset['posted_cost'];
        $accumulated = $asset['posted_accumulated'];
        $carrying = bcsub($cost, $accumulated, 4);
        $gain = bcsub($proceeds, $carrying, 4);
        $description = 'Fixed asset ' . $asset['code'] . ' ' . ($kind === 'scrap' ? 'scrapped' : 'sold') . ': ' . $asset['name'];
        $signed = [
            [$class['accumulated_account_id'], $accumulated, $description],
            [$class['asset_account_id'], bcsub('0', $cost, 4), $description],
            [$class['disposal_account_id'], bcsub('0', $gain, 4), $description],
        ];
        if (bccomp($proceeds, '0', 4) > 0) { $signed[] = [(int) $proceedsAccountId, $proceeds, $description]; }
        $lines = pl_asset_signed_lines($signed);
        if (count($lines) < 2) { throw new DomainException('This disposal moves nothing. Check the asset cost and accumulated depreciation before recording it.'); }
        $journal = pl_post_journal($actorId, $companyId, $bookId, [
            'date' => $date, 'currency' => (string) $book['currency'],
            'source_type' => 'asset_disposal', 'source_reference' => 'asset:' . $assetId,
            'idempotency_key' => 'asset-disp:' . hash('sha256', $bookId . ':' . $key),
            'description' => $description, 'lines' => $lines,
        ]);
        pl_asset_record_event($actorId, $companyId, $bookId, [
            'asset_id' => $assetId, 'kind' => 'disposal', 'event_date' => $date,
            'period_id' => (int) $journal['period_id'], 'journal_id' => (int) $journal['id'],
            'cost_amount' => bcsub('0', $cost, 4), 'depreciation_amount' => bcsub('0', $accumulated, 4),
            'proceeds_amount' => $proceeds, 'gain_loss_amount' => $gain,
        ]);
        DB::update('pl_assets', ['status' => 'disposed', 'disposal_date' => $date, 'revision' => $asset['revision'] + 1],
            'id = %i AND company_id = %i AND book_id = %i', $assetId, $companyId, $bookId);
        $after = pl_get_asset($actorId, $companyId, $bookId, $assetId);
        pl_core_audit($actorId, $companyId, $bookId, 'asset', $assetId, 'disposed', $reason, $asset, $after);
        return $after + ['disposal' => ['journal_id' => (int) $journal['id'], 'journal_reference' => (string) $journal['reference'],
            'kind' => $kind, 'proceeds' => $proceeds, 'carrying_amount' => $carrying, 'gain_loss' => $gain]];
    });
}

/**
 * Bring an asset's depreciation up to the day it is disposed of.
 *
 * Two different things happen here and they are deliberately not the same thing.
 *
 * A period that **ended before** the disposal is a period the operator was supposed to run,
 * and running it now, dated on the disposal day, would post a prior year's charge into this
 * year. So that is refused, with the period named, rather than quietly fixed.
 *
 * The period the disposal **falls inside** has not ended and cannot have been run. Its
 * charge, for the months up to and excluding the month of disposal, is posted here as its
 * own depreciation entry dated on the day of disposal. Afterwards `scheduled - posted` for
 * that period is zero, because the asset's schedule is now truncated by its disposal date,
 * so the ordinary run for that period will not charge it again.
 */
function pl_asset_settle_depreciation_to_disposal(int $actorId, int $companyId, int $bookId, array $asset, array $class, string $currency, string $date, string $key): void
{
    $plan = array_replace(pl_asset_plan($asset, $class), ['disposal_date' => $date]);
    $schedule = pl_asset_schedule($plan, pl_asset_book_periods($companyId, $bookId));
    foreach ($schedule as $row) {
        $posted = pl_asset_posted_in_period($companyId, $bookId, (int) $asset['id'], $row['period_id']);
        if (bccomp($posted, $row['charge'], 4) === 0) { continue; }
        if ($row['end_date'] < $date) {
            throw new DomainException('Run depreciation for the period ended ' . $row['end_date'] . ' before recording this disposal, so the gain or loss is measured against an up-to-date carrying amount.');
        }
        $outstanding = bcsub($row['charge'], $posted, 4);
        $label = 'Depreciation to disposal, ' . $class['name'];
        $journal = pl_post_journal($actorId, $companyId, $bookId, [
            'date' => $date, 'currency' => $currency,
            'source_type' => 'asset_depreciation', 'source_reference' => 'asset-period:' . $row['period_id'],
            'idempotency_key' => 'asset-depn-final:' . hash('sha256', $bookId . ':' . $key),
            'description' => 'Depreciation of ' . $asset['code'] . ' to its disposal on ' . $date,
            'lines' => pl_asset_signed_lines([
                [$class['expense_account_id'], $outstanding, $label],
                [$class['accumulated_account_id'], bcsub('0', $outstanding, 4), $label],
            ]),
        ]);
        pl_asset_record_event($actorId, $companyId, $bookId, [
            'asset_id' => (int) $asset['id'], 'kind' => 'depreciation', 'event_date' => $date,
            'period_id' => $row['period_id'], 'journal_id' => (int) $journal['id'],
            'depreciation_amount' => $outstanding,
        ]);
    }
}

/**
 * Turn signed per-account amounts into journal lines, netting repeats of one account and
 * dropping the zeros. A positive amount is a debit.
 */
function pl_asset_signed_lines(array $signed): array
{
    $byAccount = [];
    $descriptions = [];
    foreach ($signed as [$accountId, $amount, $description]) {
        $accountId = (int) $accountId;
        $byAccount[$accountId] = bcadd($byAccount[$accountId] ?? '0.0000', bcadd((string) $amount, '0', 4), 4);
        $descriptions[$accountId] ??= (string) $description;
    }
    $lines = [];
    foreach ($byAccount as $accountId => $amount) {
        if (bccomp($amount, '0', 4) === 0) { continue; }
        $debit = bccomp($amount, '0', 4) > 0;
        $lines[] = ['account_id' => $accountId, 'debit' => $debit ? $amount : '0.0000',
            'credit' => $debit ? '0.0000' : bcsub('0', $amount, 4), 'description' => $descriptions[$accountId]];
    }
    return $lines;
}

/** Undo a disposal with a linked reversal; the asset returns to the register as it was. */
function pl_reverse_asset_disposal(int $actorId, int $companyId, int $bookId, int $assetId, ?string $date, array $input): array
{
    return pl_asset_reverse_event($actorId, $companyId, $bookId, $assetId, 'disposal', $date, $input, 'active');
}

/**
 * Undo an acquisition with a linked reversal. Only while the asset holds nothing else:
 * an asset that has been depreciated or disposed of has later postings that would be left
 * pointing at a cost that is no longer there.
 */
function pl_reverse_asset_acquisition(int $actorId, int $companyId, int $bookId, int $assetId, ?string $date, array $input): array
{
    return pl_asset_reverse_event($actorId, $companyId, $bookId, $assetId, 'acquisition', $date, $input, 'reversed');
}

function pl_asset_reverse_event(int $actorId, int $companyId, int $bookId, int $assetId, string $kind, ?string $date, array $input, string $status): array
{
    $reason = pl_ledger_text($input['reason'] ?? null, 'Reason for this correction', 400);
    $key = pl_request_key(pl_ledger_text($input['idempotency_key'] ?? null, 'Request identity', 128));
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $assetId, $kind, $date, $reason, $key, $status): array {
        pl_require_module($actorId, $companyId, $bookId, 'fixed-assets');
        pl_ledger_book($companyId, $bookId, true);
        $before = pl_get_asset($actorId, $companyId, $bookId, $assetId);
        $event = DB::queryFirstRow('SELECT * FROM pl_asset_events WHERE company_id = %i AND book_id = %i AND asset_id = %i AND kind = %s AND is_reversal = 0 ORDER BY id DESC LIMIT 1 FOR UPDATE',
            $companyId, $bookId, $assetId, $kind);
        if (!$event) { throw new DomainException('This asset has no ' . $kind . ' to correct.'); }
        if (DB::queryFirstField('SELECT id FROM pl_asset_events WHERE reverses_event_id = %i FOR SHARE', (int) $event['id']) !== null) {
            throw new DomainException('This entry has already been corrected.');
        }
        if ($kind === 'acquisition') {
            $others = DB::queryFirstField('SELECT id FROM pl_asset_events WHERE company_id = %i AND book_id = %i AND asset_id = %i AND kind <> %s LIMIT 1 FOR SHARE',
                $companyId, $bookId, $assetId, 'acquisition');
            if ($others !== null) { throw new DomainException('Reverse the depreciation and the disposal of this asset before correcting its acquisition.'); }
        }
        $reversal = pl_reverse_journal($actorId, $companyId, $bookId, (int) $event['journal_id'], $date, 'asset-rev:' . hash('sha256', $bookId . ':' . $key), $reason);
        pl_asset_record_event($actorId, $companyId, $bookId, [
            'asset_id' => $assetId, 'kind' => $kind, 'is_reversal' => 1, 'reverses_event_id' => (int) $event['id'],
            'event_date' => (string) $reversal['journal_date'], 'period_id' => (int) $event['period_id'],
            'journal_id' => (int) $reversal['id'],
            'cost_amount' => bcsub('0', bcadd((string) $event['cost_amount'], '0', 4), 4),
            'depreciation_amount' => bcsub('0', bcadd((string) $event['depreciation_amount'], '0', 4), 4),
            'proceeds_amount' => bcsub('0', bcadd((string) $event['proceeds_amount'], '0', 4), 4),
            'gain_loss_amount' => bcsub('0', bcadd((string) $event['gain_loss_amount'], '0', 4), 4),
        ]);
        DB::update('pl_assets', ['status' => $status, 'disposal_date' => null, 'revision' => $before['revision'] + 1],
            'id = %i AND company_id = %i AND book_id = %i', $assetId, $companyId, $bookId);
        $after = pl_get_asset($actorId, $companyId, $bookId, $assetId);
        pl_core_audit($actorId, $companyId, $bookId, 'asset', $assetId, 'corrected', $reason, $before, $after);
        return $after;
    });
}

// -------------------------------------------------------------------------- reporting

/**
 * The register, by class, with its reconciliation to the control accounts.
 *
 * The reconciliation is the report's reason to exist. For every account the classes post
 * to, the register's own total is compared with the trial-balance movement of that account
 * at the same date and the difference is stated as a figure. A non-zero difference means
 * something reached the control account without going through the register — a manual
 * journal straight into fixed assets is the usual one — and the report says so instead of
 * quietly presenting a total that does not tie.
 *
 * Accumulated depreciation is a contra asset, so its trial-balance figure is credit-normal
 * and comes back negative from `pl_trial_balance()`; the register's accumulated figure is
 * stated positive, which is why the comparison negates one side.
 */
function pl_asset_register(int $actorId, int $companyId, int $bookId, ?string $asOf = null): array
{
    pl_require_company_access($actorId, $companyId);
    $book = pl_ledger_book($companyId, $bookId);
    $asOf ??= gmdate('Y-m-d');
    pl_ledger_date($asOf);
    $classes = pl_list_asset_classes($actorId, $companyId, $bookId);
    $periods = pl_asset_book_periods($companyId, $bookId);
    $byClass = [];
    foreach ($classes as $class) { $byClass[$class['id']] = $class; }

    $groups = [];
    $totals = ['cost' => '0.0000', 'accumulated' => '0.0000', 'net_book_value' => '0.0000', 'outstanding' => '0.0000'];
    foreach (pl_list_assets($actorId, $companyId, $bookId) as $asset) {
        $class = $byClass[$asset['class_id']] ?? null;
        if ($class === null) { continue; }
        $figures = pl_asset_totals($companyId, $bookId, (int) $asset['id'], $asOf);
        // What the schedule says should already have been charged by this date, less what
        // has been. It is not a ledger figure and is never posted here; it is the register
        // telling the operator which periods still have to be run.
        $outstanding = '0.0000';
        foreach (pl_asset_schedule(pl_asset_plan($asset, $class), $periods) as $row) {
            if ($row['end_date'] > $asOf) { break; }
            $outstanding = bcadd($outstanding, bcsub($row['charge'], pl_asset_posted_in_period($companyId, $bookId, (int) $asset['id'], $row['period_id']), 4), 4);
        }
        $groups[$class['id']] ??= ['class' => $class, 'assets' => [],
            'cost' => '0.0000', 'accumulated' => '0.0000', 'net_book_value' => '0.0000', 'outstanding' => '0.0000'];
        $groups[$class['id']]['assets'][] = $asset + $figures + ['outstanding_depreciation' => $outstanding];
        foreach (['cost' => 'posted_cost', 'accumulated' => 'posted_accumulated', 'net_book_value' => 'net_book_value'] as $key => $field) {
            $groups[$class['id']][$key] = bcadd($groups[$class['id']][$key], $figures[$field], 4);
            $totals[$key] = bcadd($totals[$key], $figures[$field], 4);
        }
        $groups[$class['id']]['outstanding'] = bcadd($groups[$class['id']]['outstanding'], $outstanding, 4);
        $totals['outstanding'] = bcadd($totals['outstanding'], $outstanding, 4);
    }

    $ledger = [];
    foreach (pl_trial_balance($actorId, $companyId, $bookId, $asOf)['accounts'] as $account) {
        $ledger[(int) $account['id']] = ['code' => (string) $account['code'], 'name' => (string) $account['name'], 'balance' => (string) $account['balance']];
    }
    $registerByAccount = [];
    foreach ($groups as $group) {
        $class = $group['class'];
        $registerByAccount[$class['asset_account_id']] = bcadd($registerByAccount[$class['asset_account_id']] ?? '0.0000', $group['cost'], 4);
        $registerByAccount[$class['accumulated_account_id']] = bcadd($registerByAccount[$class['accumulated_account_id']] ?? '0.0000', $group['accumulated'], 4);
    }
    $reconciliation = [];
    $reconciled = true;
    foreach ($classes as $class) {
        foreach ([['asset_account_id', 'cost', '1'], ['accumulated_account_id', 'accumulated_depreciation', '-1']] as [$field, $purpose, $sign]) {
            $accountId = $class[$field];
            if (isset($reconciliation[$accountId])) { continue; }
            $balance = $ledger[$accountId]['balance'] ?? '0.0000';
            // A contra asset carries a credit balance, so the ledger reports it negative.
            $ledgerFigure = $sign === '-1' ? bcsub('0', $balance, 4) : bcadd($balance, '0', 4);
            $registerFigure = $registerByAccount[$accountId] ?? '0.0000';
            $difference = bcsub($ledgerFigure, $registerFigure, 4);
            if (bccomp($difference, '0', 4) !== 0) { $reconciled = false; }
            $reconciliation[$accountId] = [
                'account_id' => $accountId, 'purpose' => $purpose,
                'code' => $ledger[$accountId]['code'] ?? '', 'name' => $ledger[$accountId]['name'] ?? '',
                'ledger' => $ledgerFigure, 'register' => $registerFigure, 'difference' => $difference,
            ];
        }
    }
    return [
        'as_of' => $asOf, 'currency' => (string) $book['currency'],
        'classes' => array_values($groups), 'totals' => $totals,
        'reconciliation' => array_values($reconciliation), 'reconciled' => $reconciled,
    ];
}

/** One asset's own statement: what it is, what it has been charged, and the schedule ahead. */
function pl_asset_statement(int $actorId, int $companyId, int $bookId, int $assetId): array
{
    $asset = pl_get_asset($actorId, $companyId, $bookId, $assetId);
    $class = pl_get_asset_class($actorId, $companyId, $bookId, (int) $asset['class_id']);
    $periods = pl_asset_book_periods($companyId, $bookId);
    $schedule = pl_asset_schedule(pl_asset_plan($asset, $class), $periods);
    foreach ($schedule as &$row) {
        $row['posted'] = pl_asset_posted_in_period($companyId, $bookId, $assetId, $row['period_id']);
        $row['outstanding'] = bcsub($row['charge'], $row['posted'], 4);
    }
    unset($row);
    $depreciable = bcsub($asset['cost'], $asset['residual_value'], 4);
    $scheduledTotal = '0.0000';
    foreach ($schedule as $row) { $scheduledTotal = bcadd($scheduledTotal, $row['charge'], 4); }
    return [
        'asset' => $asset, 'class' => $class, 'events' => pl_asset_events($actorId, $companyId, $bookId, $assetId),
        'schedule' => $schedule, 'depreciable_amount' => $depreciable, 'scheduled_total' => $scheduledTotal,
        // True whenever the book's periods cover the whole useful life; the schedule can only
        // charge periods that exist, so a life running past the last period is short here.
        'schedule_complete' => bccomp($scheduledTotal, $depreciable, 4) === 0,
    ];
}
