<?php
declare(strict_types=1);

// Fixed assets (1.3 M14, issue #95). Registered after users_test.php by tests/run.php.

/** Synthetic accounting periods for the pure schedule tests: no database, no clock. */
function asset_synthetic_periods(string $from, int $lengthMonths, int $count, int $firstId = 1): array
{
    $periods = [];
    $start = $from;
    for ($index = 0; $index < $count; $index++) {
        $end = pl_asset_previous_day(pl_asset_add_months($start, $lengthMonths));
        $periods[] = ['id' => $firstId + $index, 'start_date' => $start, 'end_date' => $end];
        $start = pl_asset_next_day($end);
    }
    return $periods;
}

function asset_fixture(): array
{
    $f = ledger_fixture('USD', '2026-01-01', '12-31');
    $manifest = pl_module_registry()['fixed-assets'];
    pl_set_company_module($f['actor_id'], $f['company_id'], 'fixed-assets', true, 0, $manifest['digest'], 'Sample fixed assets module', bin2hex(random_bytes(16)));
    foreach ([['2027-01-01', '2027-12-31'], ['2028-01-01', '2028-12-31'], ['2029-01-01', '2029-12-31']] as [$start, $end]) {
        pl_create_period($f['actor_id'], $f['company_id'], $f['book_id'], ['start_date' => $start, 'end_date' => $end, 'reason' => 'Sample later period', 'request_key' => bin2hex(random_bytes(16))]);
    }
    return $f;
}

function asset_args(array $f): array
{
    return [$f['actor_id'], $f['company_id'], $f['book_id']];
}

function asset_class(array $f, array $overrides = []): array
{
    return pl_save_asset_class($f['actor_id'], $f['company_id'], $f['book_id'], array_replace([
        'code' => 'CL' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6)), 'name' => 'Sample asset class',
        'method' => 'straight_line', 'useful_life_months' => 24,
        'annual_rate' => null, 'is_active' => true, 'reason' => 'Sample asset class',
        'idempotency_key' => bin2hex(random_bytes(16)),
    ], $overrides));
}

function asset_record(array $f, int $classId, array $overrides = []): array
{
    return pl_save_asset($f['actor_id'], $f['company_id'], $f['book_id'], array_replace([
        'class_id' => $classId, 'code' => 'A' . strtoupper(substr(bin2hex(random_bytes(5)), 0, 8)),
        'name' => 'Sample asset', 'acquisition_date' => '2026-01-01', 'in_service_date' => '2026-01-01',
        'cost' => '24000.0000', 'residual_value' => '0', 'credit_account_id' => $f['accounts']['1000'],
        'reason' => 'Sample acquisition', 'idempotency_key' => bin2hex(random_bytes(16)),
    ], $overrides));
}

function asset_period(array $f, string $endDate): array
{
    foreach (pl_list_periods(...asset_args($f)) as $period) {
        if ((string) $period['end_date'] === $endDate) { return $period; }
    }
    throw new RuntimeException('No period ends on ' . $endDate);
}

function asset_run(array $f, string $endDate): array
{
    return pl_run_asset_depreciation($f['actor_id'], $f['company_id'], $f['book_id'],
        ['period_id' => (int) asset_period($f, $endDate)['id'], 'reason' => 'Sample depreciation run', 'idempotency_key' => bin2hex(random_bytes(16))]);
}

/** The balance of one account in the trial balance, debit positive. */
function asset_balance(array $f, int $accountId, ?string $asOf = null): string
{
    foreach (pl_trial_balance($f['actor_id'], $f['company_id'], $f['book_id'], $asOf)['accounts'] as $account) {
        if ((int) $account['id'] === $accountId) { return (string) $account['balance']; }
    }
    return '0.0000';
}

test('the periodic charges add up to exactly cost less residual value, for every method and period shape', function (): void {
    // The pure schedule engine, with no database and no clock. This is the arithmetic that
    // has to be right: an asset whose charges sum to 999.9999 instead of 1,000.0000 leaves a
    // cent of net book value behind for ever and the register stops tying to the ledger.
    $shapes = [
        'annual' => asset_synthetic_periods('2026-01-01', 12, 14),
        'monthly' => asset_synthetic_periods('2026-01-01', 1, 160),
        'quarterly' => asset_synthetic_periods('2026-01-01', 3, 56),
        // The shape pl_create_company() actually opens a book with: a stub first year.
        'stub first year' => array_merge(
            [['id' => 900, 'start_date' => '2026-03-15', 'end_date' => '2026-12-31']],
            asset_synthetic_periods('2027-01-01', 12, 13, 901)),
    ];
    // Lives that do not divide evenly into any of the period shapes are the point: 7, 11, 13,
    // 37, 41 and 100 months, and amounts whose thirds and sevenths do not land on four decimals.
    $cases = [
        ['1000.0000', '0.0000', 36], ['1000.0000', '0.0000', 7], ['10000.0000', '1234.5600', 37],
        ['999.9900', '0.0100', 13], ['100000.0000', '10000.0000', 100], ['3.0000', '0.0000', 3],
        ['1.0000', '0.0000', 11], ['77777.7700', '11111.1100', 41],
    ];
    $checked = 0;
    foreach ($shapes as $shape => $periods) {
        foreach (['straight_line', 'reducing_balance'] as $method) {
            foreach ($cases as [$cost, $residual, $life]) {
                // A day that is neither the first nor the last of its month, because the
                // convention charges a full month whatever day the asset arrived.
                $plan = [
                    'in_service_date' => $shape === 'stub first year' ? '2026-05-20' : '2026-02-10',
                    'cost' => $cost, 'residual_value' => $residual, 'method' => $method,
                    'useful_life_months' => $life, 'disposal_date' => null,
                    'annual_rate' => $method === 'reducing_balance' ? '0.250000' : null,
                ];
                $rows = pl_asset_schedule($plan, $periods);
                $label = $shape . '/' . $method . '/' . $cost . '/' . $life;
                assert_true($rows !== [], 'No schedule at all for ' . $label);
                $sum = '0.0000';
                $months = 0;
                $previous = '0.0000';
                foreach ($rows as $row) {
                    assert_true(bccomp($row['charge'], '0', 4) >= 0, 'A negative charge in ' . $label);
                    assert_true($row['months'] >= 1, 'A period with no months in ' . $label);
                    $months += $row['months'];
                    $sum = bcadd($sum, $row['charge'], 4);
                    assert_same($sum, $row['accumulated'], 'Accumulated is not the running sum in ' . $label);
                    assert_true(bccomp($row['accumulated'], $previous, 4) >= 0, 'Accumulated went backwards in ' . $label);
                    assert_true(bccomp($row['closing_nbv'], $residual, 4) >= 0, 'Net book value fell below residual in ' . $label);
                    $previous = $row['accumulated'];
                }
                assert_same($life, $months, 'The schedule does not charge exactly the useful life in months in ' . $label);
                assert_same(bcsub($cost, $residual, 4), $sum, 'The charges do not sum to cost less residual in ' . $label);
                assert_same($residual, $rows[count($rows) - 1]['closing_nbv'], 'The asset does not end at its residual value in ' . $label);
                ++$checked;
            }
        }
    }
    assert_same(64, $checked, 'The exactness matrix stopped covering what it claims to cover.');
});

test('a schedule stops where the book stops, and does not write the asset off in the last period that exists', function (): void {
    // The defect this guards: taking the denominator from the periods that happen to exist
    // and then forcing the last of them to the remainder writes a ten-year asset off in year
    // three, because year three is as far as anyone has opened the books.
    $plan = ['in_service_date' => '2026-01-01', 'cost' => '12000.0000', 'residual_value' => '0.0000',
        'method' => 'straight_line', 'useful_life_months' => 120, 'annual_rate' => null, 'disposal_date' => null];
    $short = pl_asset_schedule($plan, asset_synthetic_periods('2026-01-01', 12, 3));
    assert_same(3, count($short));
    $sum = '0.0000';
    foreach ($short as $row) { $sum = bcadd($sum, $row['charge'], 4); }
    assert_same('3600.0000', $sum, 'Three years of a ten-year 12,000 asset is 36 of 120 months.');
    assert_same('8400.0000', $short[2]['closing_nbv'], 'The asset was written down too far.');
    // Open the rest of the books and the same asset finishes exactly on its cost.
    $full = pl_asset_schedule($plan, asset_synthetic_periods('2026-01-01', 12, 11));
    $sum = '0.0000';
    foreach ($full as $row) { $sum = bcadd($sum, $row['charge'], 4); }
    assert_same('12000.0000', $sum);
    // The first three periods are unchanged by opening the later ones: a schedule is not
    // rewritten by the arrival of a new period, so a posted charge never disagrees with it.
    foreach ([0, 1, 2] as $index) { assert_same($short[$index]['charge'], $full[$index]['charge']); }
});

test('a full month is charged in the month an asset enters service, whatever day of it that was', function (): void {
    $periods = asset_synthetic_periods('2026-01-01', 12, 6);
    $base = ['cost' => '36000.0000', 'residual_value' => '0.0000', 'method' => 'straight_line',
        'useful_life_months' => 36, 'annual_rate' => null, 'disposal_date' => null];
    // The first day, the middle and the last day of July all give the same six months of 2026.
    foreach (['2026-07-01', '2026-07-15', '2026-07-31'] as $inService) {
        $rows = pl_asset_schedule(array_replace($base, ['in_service_date' => $inService]), $periods);
        assert_same(6, $rows[0]['months'], $inService . ' did not charge July to December.');
        assert_same('6000.0000', $rows[0]['charge'], $inService . ' did not charge six of thirty-six months.');
    }
    // One day earlier is one month more, because June is then the month it entered service.
    $june = pl_asset_schedule(array_replace($base, ['in_service_date' => '2026-06-30']), $periods);
    assert_same(7, $june['0']['months']);
    assert_same('7000.0000', $june[0]['charge']);
    // None in the month of disposal, and the months before it in that period are still charged.
    $disposed = pl_asset_schedule(array_replace($base, ['in_service_date' => '2026-07-01', 'disposal_date' => '2027-04-20']), $periods);
    assert_same(2, count($disposed), 'A disposal in 2027 leaves 2026 and part of 2027.');
    assert_same(6, $disposed[0]['months']);
    assert_same(3, $disposed[1]['months'], 'January to March 2027; April is the month of disposal and takes nothing.');
    assert_same('3000.0000', $disposed[1]['charge']);
    // Disposal in the very month it entered service: nothing is ever charged.
    assert_same([], pl_asset_schedule(array_replace($base, ['in_service_date' => '2026-07-01', 'disposal_date' => '2026-07-20']), $periods));
});

test('recording an asset posts its acquisition through the one posting service and the register ties to the ledger', function (): void {
    $f = asset_fixture();
    $class = asset_class($f);
    $asset = asset_record($f, $class['id'], ['cost' => '24000.0000']);
    assert_same('24000.0000', $asset['posted_cost']);
    assert_same('0.0000', $asset['posted_accumulated']);
    assert_same('24000.0000', $asset['net_book_value']);
    assert_same('active', $asset['status']);
    // One journal, through the central funnel, with a durable source reference.
    $events = pl_asset_events(...array_merge(asset_args($f), [$asset['id']]));
    assert_same(1, count($events));
    assert_same('acquisition', $events[0]['kind']);
    $journal = pl_get_journal(...array_merge(asset_args($f), [$events[0]['journal_id']]));
    assert_same('asset_acquisition', $journal['source_type']);
    assert_same('asset:' . $asset['id'], $journal['source_reference']);
    assert_same(2, count($journal['lines']));
    assert_same('24000.0000', asset_balance($f, $class['asset_account_id']));
    assert_same('-24000.0000', asset_balance($f, $f['accounts']['1000']));
    $register = pl_asset_register(...asset_args($f));
    assert_same(true, $register['reconciled'], 'The register does not tie to its control accounts after an acquisition.');
    assert_same('24000.0000', $register['totals']['cost']);
    // A retried request returns the asset it already made instead of a second one.
    $key = bin2hex(random_bytes(16));
    $once = asset_record($f, $class['id'], ['idempotency_key' => $key, 'code' => 'RETRY-1']);
    $twice = asset_record($f, $class['id'], ['idempotency_key' => $key, 'code' => 'RETRY-1']);
    assert_same($once['id'], $twice['id']);
    assert_same(2, count(pl_list_assets(...asset_args($f))));
});

test('a depreciation run posts once per period, is idempotent, and reconciles across two periods', function (): void {
    $f = asset_fixture();
    // The worked example issue #95 asks an accounting reviewer for: a straight-line asset in
    // service from the first day, and a second asset bought mid-year and charged pro rata.
    $straight = asset_class($f, ['code' => 'VEH', 'name' => 'Vehicles', 'useful_life_months' => 24]);
    $prorata = asset_class($f, ['code' => 'MCH', 'name' => 'Machinery', 'useful_life_months' => 60]);
    $van = asset_record($f, $straight['id'], ['code' => 'VAN-1', 'name' => 'Delivery van', 'cost' => '24000.0000']);
    $machine = asset_record($f, $prorata['id'], ['code' => 'MCH-1', 'name' => 'Packing machine', 'cost' => '10000.0000',
        'residual_value' => '1000.0000', 'acquisition_date' => '2026-07-01', 'in_service_date' => '2026-07-01']);

    // A full accounting year for the van; 1 July to 31 December for the machine.
    $first = asset_run($f, '2026-12-31');
    assert_same(2, $first['asset_count']);
    $vanFirst = pl_asset_totals($f['company_id'], $f['book_id'], (int) $van['id'])['posted_accumulated'];
    assert_same('12000.0000', $vanFirst, 'A 24-month straight-line asset should bear half its cost in a full first year.');
    $machineFirst = pl_asset_totals($f['company_id'], $f['book_id'], (int) $machine['id'])['posted_accumulated'];
    assert_same('900.0000', $machineFirst, 'Six of sixty months of a 9,000 depreciable amount.');
    assert_same(bcadd($vanFirst, $machineFirst, 4), $first['total_amount']);
    assert_same('-' . $first['total_amount'], asset_balance($f, $straight['accumulated_account_id'], '2026-12-31'),
        'Accumulated depreciation is a credit balance on a contra asset.');
    assert_same($first['total_amount'], asset_balance($f, $straight['expense_account_id'], '2026-12-31'));
    assert_same(true, pl_asset_register(...array_merge(asset_args($f), ['2026-12-31']))['reconciled']);

    // Running the same period again posts nothing at all: idempotent per asset and period.
    $again = asset_run($f, '2026-12-31');
    assert_same(0, $again['id'], 'A second run of the same period posted a duplicate journal.');
    assert_same(false, $again['posted']);
    assert_same($first['total_amount'], bcsub('0', asset_balance($f, $straight['accumulated_account_id'], '2026-12-31'), 4));

    // ...and a retried request with the same key returns the run it already made.
    $key = bin2hex(random_bytes(16));
    $periodId = (int) asset_period($f, '2027-12-31')['id'];
    $once = pl_run_asset_depreciation(...array_merge(asset_args($f), [['period_id' => $periodId, 'reason' => 'Sample', 'idempotency_key' => $key]]));
    $twice = pl_run_asset_depreciation(...array_merge(asset_args($f), [['period_id' => $periodId, 'reason' => 'Sample', 'idempotency_key' => $key]]));
    assert_same($once['id'], $twice['id']);

    // The second year completes the van's life exactly and continues the machine pro rata.
    assert_same('24000.0000', pl_asset_totals($f['company_id'], $f['book_id'], (int) $van['id'])['posted_accumulated']);
    assert_same('0.0000', pl_get_asset(...array_merge(asset_args($f), [$van['id']]))['net_book_value']);
    assert_same('2700.0000', pl_asset_totals($f['company_id'], $f['book_id'], (int) $machine['id'])['posted_accumulated']);

    // Two periods in, the register still equals the ledger on both control accounts, and the
    // balance sheet presents accumulated depreciation as a deduction because it is contra.
    $register = pl_asset_register(...array_merge(asset_args($f), ['2027-12-31']));
    assert_same(true, $register['reconciled']);
    assert_same('34000.0000', $register['totals']['cost']);
    assert_same('26700.0000', $register['totals']['accumulated']);
    assert_same('7300.0000', $register['totals']['net_book_value']);
    assert_same('0.0000', $register['totals']['outstanding'], 'Both periods have been run, so nothing is outstanding.');
    foreach ($register['reconciliation'] as $row) { assert_same('0.0000', $row['difference'], $row['purpose'] . ' does not tie.'); }
    $sheet = pl_balance_sheet(...array_merge(asset_args($f), ['2027-12-31']));
    $accumulated = null;
    foreach ($sheet['assets'] as $row) { if ((int) $row['id'] === $straight['accumulated_account_id']) { $accumulated = $row; } }
    assert_true($accumulated !== null, 'Accumulated depreciation is missing from the balance sheet.');
    assert_same(true, $accumulated['is_contra'], 'Accumulated depreciation must be presented as a deduction.');
    assert_same('-26700.0000', $accumulated['amount']);
    assert_same(true, $sheet['balanced']);
});

test('a depreciation run refuses a closed period and a correction is a linked reversal, not an edit', function (): void {
    $f = asset_fixture();
    $class = asset_class($f);
    $asset = asset_record($f, $class['id']);
    $period = asset_period($f, '2026-12-31');

    // A closed period refuses the run, and says so before anything is computed.
    pl_change_period_status(...array_merge(asset_args($f), [(int) $period['id'], 'closed', (int) $period['revision'], 'Sample close', bin2hex(random_bytes(16))]));
    assert_throws(fn () => asset_run($f, '2026-12-31'), DomainException::class, 'closed');
    assert_same('0.0000', pl_asset_totals($f['company_id'], $f['book_id'], (int) $asset['id'])['posted_accumulated']);
    pl_change_period_status(...array_merge(asset_args($f), [(int) $period['id'], 'open', (int) $period['revision'] + 1, 'Sample reopen', bin2hex(random_bytes(16))]));

    $run = asset_run($f, '2026-12-31');
    assert_same('12000.0000', $run['total_amount']);
    $original = (int) $run['journal_id'];

    // The correction is the core's linked reversal: the exact mirror of the original journal,
    // under the original journal as its source reference. Nothing is edited anywhere.
    $reversal = pl_reverse_asset_depreciation_run(...array_merge(asset_args($f), [(int) $run['id'], '2026-12-31',
        ['reason' => 'Sample correction', 'idempotency_key' => bin2hex(random_bytes(16))]]));
    assert_same(true, $reversal['is_reversal']);
    $reversalJournal = pl_get_journal(...array_merge(asset_args($f), [(int) $reversal['journal_id']]));
    assert_same('reversal', $reversalJournal['source_type']);
    assert_same((string) $original, $reversalJournal['source_reference']);
    assert_same($original, (int) $reversalJournal['reversal_of_id']);
    assert_same('0.0000', pl_asset_totals($f['company_id'], $f['book_id'], (int) $asset['id'])['posted_accumulated']);
    assert_same('0.0000', asset_balance($f, $class['accumulated_account_id']));
    assert_same(true, pl_get_asset_depreciation_run(...array_merge(asset_args($f), [(int) $run['id']]))['reversed']);
    assert_throws(fn () => pl_reverse_asset_depreciation_run(...array_merge(asset_args($f), [(int) $run['id'], '2026-12-31',
        ['reason' => 'Sample', 'idempotency_key' => bin2hex(random_bytes(16))]])), DomainException::class, 'already been reversed');

    // Running the period again posts the difference, which after a full reversal is the whole
    // charge again — and once, not twice.
    $rerun = asset_run($f, '2026-12-31');
    assert_same('12000.0000', $rerun['total_amount']);
    assert_same('12000.0000', pl_asset_totals($f['company_id'], $f['book_id'], (int) $asset['id'])['posted_accumulated']);
    assert_same('-12000.0000', asset_balance($f, $class['accumulated_account_id']));
    assert_same(0, (int) asset_run($f, '2026-12-31')['id'], 'The re-run was not idempotent afterwards.');
    assert_same(true, pl_asset_register(...array_merge(asset_args($f), ['2026-12-31']))['reconciled']);
    // Three journals against that period's depreciation: the run, its reversal and the re-run.
    assert_same(3, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id = %i AND (source_type = %s OR reversal_of_id = %i)',
        $f['book_id'], 'asset_depreciation', $original));
});

test('a disposal removes the cost and the accumulated depreciation and posts the gain or loss', function (): void {
    $f = asset_fixture();
    $class = asset_class($f, ['useful_life_months' => 60]);
    $machine = asset_record($f, $class['id'], ['code' => 'MCH-2', 'cost' => '10000.0000', 'residual_value' => '1000.0000',
        'acquisition_date' => '2026-07-01', 'in_service_date' => '2026-07-01']);

    // A disposal is refused while a period that ended before it still owes a charge: the gain
    // or loss would otherwise quietly absorb a year of missing depreciation.
    assert_throws(fn () => pl_dispose_asset(...array_merge(asset_args($f), [(int) $machine['id'],
        ['kind' => 'sale', 'date' => '2028-06-30', 'proceeds' => '5000', 'proceeds_account_id' => $f['accounts']['1000'],
            'reason' => 'Sample', 'idempotency_key' => bin2hex(random_bytes(16))]])), DomainException::class, 'Run depreciation');

    asset_run($f, '2026-12-31');
    asset_run($f, '2027-12-31');
    assert_same('7300.0000', pl_get_asset(...array_merge(asset_args($f), [$machine['id']]))['net_book_value']);

    // Sold below its carrying amount: a loss, posted as a debit in the disposal account.
    $before2028 = pl_asset_posted_in_period($f['company_id'], $f['book_id'], (int) $machine['id'], (int) asset_period($f, '2028-12-31')['id']);
    $disposed = pl_dispose_asset(...array_merge(asset_args($f), [(int) $machine['id'],
        ['kind' => 'sale', 'date' => '2028-06-30', 'proceeds' => '5000', 'proceeds_account_id' => $f['accounts']['1000'],
            'reason' => 'Sample sale at a loss', 'idempotency_key' => bin2hex(random_bytes(16))]]));
    $catchUp = bcsub(pl_asset_posted_in_period($f['company_id'], $f['book_id'], (int) $machine['id'], (int) asset_period($f, '2028-12-31')['id']), $before2028, 4);
    assert_same('disposed', $disposed['status']);
    assert_same('2028-06-30', (string) $disposed['disposal_date']);
    // Depreciated to the day first: January to May 2028 is five more months, 750.0000, so the
    // carrying amount is 6,550.0000 and not the 7,300.0000 it stood at on 31 December 2027.
    assert_same('750.0000', $catchUp, 'Depreciation was not brought up to the day of disposal.');
    assert_same('6550.0000', (string) $disposed['disposal']['carrying_amount']);
    assert_same('-1550.0000', (string) $disposed['disposal']['gain_loss']);
    assert_same('0.0000', $disposed['posted_cost'], 'The cost was not removed from the register.');
    assert_same('0.0000', $disposed['posted_accumulated'], 'The accumulated depreciation was not removed.');
    assert_same('0.0000', asset_balance($f, $class['asset_account_id']));
    assert_same('0.0000', asset_balance($f, $class['accumulated_account_id']));
    assert_same('1550.0000', asset_balance($f, $class['disposal_account_id']), 'A loss is a debit in the disposal account.');
    $journal = pl_get_journal(...array_merge(asset_args($f), [(int) $disposed['disposal']['journal_id']]));
    assert_same('asset_disposal', $journal['source_type']);
    assert_same(4, count($journal['lines']));
    assert_same(true, pl_asset_register(...array_merge(asset_args($f), ['2028-12-31']))['reconciled']);
    assert_same(true, pl_trial_balance(...array_merge(asset_args($f), ['2028-12-31']))['balanced']);
    // The period the disposal falls in takes no charge, which is the stated convention.
    assert_same(0, (int) asset_run($f, '2028-12-31')['id']);
    assert_throws(fn () => pl_dispose_asset(...array_merge(asset_args($f), [(int) $machine['id'],
        ['kind' => 'scrap', 'date' => '2028-07-01', 'reason' => 'Sample', 'idempotency_key' => bin2hex(random_bytes(16))]])),
        DomainException::class, 'already been disposed');

    // Reversing the disposal restores the asset exactly as it was.
    pl_reverse_asset_disposal(...array_merge(asset_args($f), [(int) $machine['id'], '2028-06-30',
        ['reason' => 'Sample correction', 'idempotency_key' => bin2hex(random_bytes(16))]]));
    $restored = pl_get_asset(...array_merge(asset_args($f), [$machine['id']]));
    assert_same('active', $restored['status']);
    assert_same(null, $restored['disposal_date']);
    assert_same('10000.0000', $restored['posted_cost']);
    // The depreciation posted to the day of disposal stays posted: reversing a disposal undoes
    // the disposal, not the months the asset genuinely wore out before it.
    assert_same('3450.0000', $restored['posted_accumulated']);
    assert_same('0.0000', asset_balance($f, $class['disposal_account_id']));
    assert_same(true, pl_asset_register(...array_merge(asset_args($f), ['2028-12-31']))['reconciled']);
});

test('a sale above the carrying amount is a gain, and a scrapped asset is a loss of its whole carrying amount', function (): void {
    $f = asset_fixture();
    $class = asset_class($f, ['useful_life_months' => 24]);
    $sold = asset_record($f, $class['id'], ['code' => 'GAIN-1', 'cost' => '24000.0000']);
    $scrapped = asset_record($f, $class['id'], ['code' => 'SCRAP-1', 'cost' => '12000.0000']);
    asset_run($f, '2026-12-31');

    $gain = pl_dispose_asset(...array_merge(asset_args($f), [(int) $sold['id'],
        ['kind' => 'sale', 'date' => '2027-06-30', 'proceeds' => '15000', 'proceeds_account_id' => $f['accounts']['1000'],
            'reason' => 'Sample sale at a gain', 'idempotency_key' => bin2hex(random_bytes(16))]]));
    // Seventeen of twenty-four months to the end of May 2027 (June is the month of disposal and
    // takes nothing), so 17,000 accumulated, a 7,000 carrying amount, and 15,000 of proceeds.
    assert_same('7000.0000', (string) $gain['disposal']['carrying_amount']);
    assert_same('8000.0000', (string) $gain['disposal']['gain_loss']);
    assert_same('-8000.0000', asset_balance($f, $class['disposal_account_id']), 'A gain is a credit in the disposal account.');

    $loss = pl_dispose_asset(...array_merge(asset_args($f), [(int) $scrapped['id'],
        ['kind' => 'scrap', 'date' => '2027-06-30', 'reason' => 'Sample scrapping', 'idempotency_key' => bin2hex(random_bytes(16))]]));
    // 8,500 accumulated on the same seventeen months, a 3,500 carrying amount, scrapped for
    // nothing: the whole carrying amount is the loss.
    assert_same('-3500.0000', (string) $loss['disposal']['gain_loss']);
    assert_same('0.0000', (string) $loss['disposal']['proceeds']);
    assert_same(3, count(pl_get_journal(...array_merge(asset_args($f), [(int) $loss['disposal']['journal_id']]))['lines']),
        'A scrapping has no proceeds line.');
    assert_same('-4500.0000', asset_balance($f, $class['disposal_account_id']), 'Net of an 8,000 gain and a 3,500 loss.');
    assert_same(true, pl_trial_balance(...array_merge(asset_args($f), ['2027-12-31']))['balanced']);
    assert_same(true, pl_asset_register(...array_merge(asset_args($f), ['2027-12-31']))['reconciled']);
    assert_same('0.0000', pl_asset_register(...array_merge(asset_args($f), ['2027-12-31']))['totals']['cost']);
});

test('accumulated depreciation has to be a contra asset, and the module reuses the reserved contra group', function (): void {
    $f = asset_fixture();
    $class = asset_class($f);
    // The account the module provisioned is the chart's own accumulated-depreciation account,
    // found by the semantic key the starter chart writes; nothing here writes a semantic key.
    $accumulated = pl_get_account(...array_merge(asset_args($f), [$class['accumulated_account_id']]));
    assert_same(true, $accumulated['is_contra']);
    assert_same('asset', $accumulated['type']);
    assert_same('core.asset.accumulated_depreciation', (string) $accumulated['semantic_key']);
    assert_true(in_array((string) $accumulated['semantic_key'], pl_contra_semantic_keys(), true),
        'The module points at an account the chart does not treat as contra.');
    assert_same(900, pl_account_code_parse((string) $accumulated['code'])['group'], 'B60 reserves 1-900 for accumulated depreciation.');
    // The other three are ordinary accounts in the numbered band below the reserved one.
    foreach (['asset_account_id' => 'asset', 'expense_account_id' => 'expense', 'disposal_account_id' => 'income'] as $field => $type) {
        $account = pl_get_account(...array_merge(asset_args($f), [$class[$field]]));
        assert_same($type, $account['type']);
        assert_same(false, $account['is_contra']);
        assert_same(true, $account['is_postable']);
        assert_true(pl_account_code_parse((string) $account['code'])['group'] <= pl_account_code_last_group());
    }
    // A class cannot point accumulated depreciation at an account that is not contra, or the
    // expense at an income account, or two parts at one account.
    assert_throws(fn () => asset_class($f, ['accumulated_account_id' => $f['accounts']['1000']]), DomainException::class, 'contra');
    assert_throws(fn () => asset_class($f, ['expense_account_id' => $f['accounts']['4000']]), DomainException::class, 'expense account');
    assert_throws(fn () => asset_class($f, ['asset_account_id' => $class['accumulated_account_id']]), DomainException::class, 'not a contra');
    assert_throws(fn () => asset_class($f, ['disposal_account_id' => $class['expense_account_id']]), DomainException::class, 'income account');
    // A second class reuses the same four accounts rather than provisioning a second set.
    $second = asset_class($f, ['code' => 'SECOND']);
    assert_same($class['accumulated_account_id'], $second['accumulated_account_id']);
    assert_same($class['asset_account_id'], $second['asset_account_id']);
    assert_same(4, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_asset_accounts WHERE book_id = %i', $f['book_id']));
});

test('the cost account lands under the chart heading its semantic key names, wherever that is', function (): void {
    $f = asset_fixture();
    $args = asset_args($f);
    // The bundled chart carries the Property, Plant and Equipment heading (M15), so this is the
    // real path and not a fixture-only one. The group number is read out of the chart here for
    // the same reason the module reads it: neither of us is allowed to know what it is.
    $headingCode = DB::queryFirstField('SELECT code FROM pl_accounts WHERE company_id = %i AND book_id = %i AND semantic_key = %s',
        $f['company_id'], $f['book_id'], pl_asset_cost_group_key());
    assert_true($headingCode !== null, 'The bundled chart no longer carries ' . pl_asset_cost_group_key() . '.');
    $heading = pl_get_account(...array_merge($args, [(int) DB::queryFirstField('SELECT id FROM pl_accounts WHERE book_id = %i AND code = %s', $f['book_id'], $headingCode)]));
    assert_same('group', (string) $heading['level'], 'The account carrying the key is not a heading.');
    assert_same(false, $heading['is_postable'], 'A heading never receives a posting.');
    // No heading carries a role, and the module must not look for one: pl_ar_control() and
    // pl_advance_control() each require exactly one active account per role, so a heading with
    // a role would make them refuse every document in the book.
    assert_same(null, $heading['role'], 'A heading must carry no operational role.');

    $class = asset_class($f);
    $cost = pl_get_account(...array_merge($args, [$class['asset_account_id']]));
    assert_same(pl_account_code_parse((string) $headingCode)['group'], pl_account_code_parse((string) $cost['code'])['group'],
        'The cost account did not land under the heading its key names.');
    assert_same($headingCode, pl_account_code_parent((string) $cost['code']));
    assert_same(true, $cost['is_postable'], 'The account under the heading must still take postings.');
    assert_same(null, $cost['semantic_key'], 'The module must not write a semantic key of its own.');
    // It is a real account under a real heading, so the acquisition posts and the register ties.
    assert_same('24000.0000', asset_record($f, $class['id'])['posted_cost']);
    assert_same(true, pl_asset_register(...$args)['reconciled']);

    // A chart with no such key still works. That is a hand-built chart, or any book created
    // before the headings shipped, and it is why the fallback exists rather than a guess at a
    // number: clearing the key here is exactly what such a book looks like.
    $plain = asset_fixture();
    DB::update('pl_accounts', ['semantic_key' => null], 'company_id = %i AND book_id = %i AND semantic_key = %s',
        $plain['company_id'], $plain['book_id'], pl_asset_cost_group_key());
    $plainCost = pl_get_account(...array_merge(asset_args($plain), [asset_class($plain)['asset_account_id']]));
    assert_true(pl_account_code_parse((string) $plainCost['code'])['group'] <= pl_account_code_last_group(),
        'The fallback allocated inside the reserved contra band.');
    assert_same('account', (string) $plainCost['level'], 'The fallback must allocate a postable account, not a heading.');
    assert_same(true, $plainCost['is_postable']);
    assert_same('24000.0000', asset_record($plain, asset_class($plain, ['code' => 'FALLBACK'])['id'])['posted_cost']);
});

test('an asset event cannot be edited or deleted, and an asset keeps its posted figures fixed', function (): void {
    $f = asset_fixture();
    $class = asset_class($f);
    $asset = asset_record($f, $class['id']);
    $eventId = (int) pl_asset_events(...array_merge(asset_args($f), [$asset['id']]))[0]['id'];
    assert_throws(fn () => DB::update('pl_asset_events', ['depreciation_amount' => '1.0000'], 'id = %i', $eventId), MeekroDBException::class);
    assert_throws(fn () => DB::query('DELETE FROM pl_asset_events WHERE id = %i', $eventId), MeekroDBException::class);
    assert_same('24000.0000', pl_get_asset(...array_merge(asset_args($f), [$asset['id']]))['posted_cost']);
    // The descriptive fields may be edited under an optimistic revision; the numbers may not.
    $updated = pl_update_asset_details(...array_merge(asset_args($f), [(int) $asset['id'], (int) $asset['revision'],
        ['name' => 'Renamed van', 'location' => 'Depot 2', 'supplier' => 'Sample supplier', 'reference' => 'INV-9', 'reason' => 'Sample correction']]));
    assert_same('Renamed van', (string) $updated['name']);
    assert_same('24000.0000', $updated['posted_cost']);
    assert_throws(fn () => pl_update_asset_details(...array_merge(asset_args($f), [(int) $asset['id'], (int) $asset['revision'],
        ['name' => 'Again', 'reason' => 'Sample']])), DomainException::class, 'Reload');
    // Correcting a cost is the ordinary way: reverse the acquisition, record the asset again.
    pl_reverse_asset_acquisition(...array_merge(asset_args($f), [(int) $asset['id'], '2026-01-01',
        ['reason' => 'Sample cost correction', 'idempotency_key' => bin2hex(random_bytes(16))]]));
    $reversed = pl_get_asset(...array_merge(asset_args($f), [$asset['id']]));
    assert_same('reversed', $reversed['status']);
    assert_same('0.0000', $reversed['posted_cost']);
    assert_same('0.0000', asset_balance($f, $class['asset_account_id']));
    assert_same(true, pl_asset_register(...asset_args($f))['reconciled']);
    // An asset that has already been depreciated cannot have its acquisition reversed.
    $other = asset_record($f, $class['id'], ['code' => 'KEEP-1']);
    asset_run($f, '2026-12-31');
    assert_throws(fn () => pl_reverse_asset_acquisition(...array_merge(asset_args($f), [(int) $other['id'], '2026-12-31',
        ['reason' => 'Sample', 'idempotency_key' => bin2hex(random_bytes(16))]])), DomainException::class, 'Reverse the depreciation');
});

test('fixed assets: the module can be disabled and re-enabled with assets present', function (): void {
    $f = asset_fixture();
    $class = asset_class($f);
    $asset = asset_record($f, $class['id']);
    asset_run($f, '2026-12-31');
    $manifest = pl_module_registry()['fixed-assets'];
    $revision = pl_module_state($f['company_id'], 'fixed-assets')['revision'];
    pl_set_company_module($f['actor_id'], $f['company_id'], 'fixed-assets', false, $revision, $manifest['digest'], 'Sample disable with assets', bin2hex(random_bytes(16)));

    // Nothing is deleted, hidden or recomputed: every historical read still works.
    assert_same('24000.0000', pl_get_asset(...array_merge(asset_args($f), [$asset['id']]))['posted_cost']);
    assert_same('12000.0000', pl_get_asset(...array_merge(asset_args($f), [$asset['id']]))['posted_accumulated']);
    assert_same(1, count(pl_list_assets(...asset_args($f))));
    assert_same(1, count(pl_list_asset_classes(...asset_args($f))));
    assert_same(2, count(pl_asset_events(...array_merge(asset_args($f), [$asset['id']]))), 'An acquisition and one charge.');
    assert_same(1, count(pl_list_asset_depreciation_runs(...asset_args($f))));
    assert_same(true, pl_asset_register(...array_merge(asset_args($f), ['2026-12-31']))['reconciled']);
    assert_same(2, count(pl_asset_statement(...array_merge(asset_args($f), [$asset['id']]))['schedule']));

    // Every write is refused while it is off.
    assert_throws(fn () => asset_record($f, $class['id'], ['code' => 'OFF-1']), DomainException::class, 'disabled');
    assert_throws(fn () => asset_run($f, '2027-12-31'), DomainException::class, 'disabled');
    assert_throws(fn () => asset_class($f, ['code' => 'OFF']), DomainException::class, 'disabled');
    assert_throws(fn () => pl_dispose_asset(...array_merge(asset_args($f), [(int) $asset['id'],
        ['kind' => 'scrap', 'date' => '2027-06-30', 'reason' => 'Sample', 'idempotency_key' => bin2hex(random_bytes(16))]])), DomainException::class, 'disabled');
    // Core accounting is untouched by a disabled optional module.
    $journal = pl_post_journal(...array_merge(asset_args($f), [['date' => '2027-06-30', 'currency' => 'USD', 'source_type' => 'receipt',
        'source_reference' => 'sample', 'description' => 'Sample receipt', 'idempotency_key' => bin2hex(random_bytes(16)),
        'lines' => [['account_id' => $f['accounts']['1000'], 'debit' => '10.0000', 'credit' => '0'],
            ['account_id' => $f['accounts']['4000'], 'debit' => '0', 'credit' => '10.0000']]]]));
    assert_true($journal['id'] > 0);
    assert_same(true, pl_trial_balance(...asset_args($f))['balanced']);

    // Re-enabling restores write access and changes nothing that was recorded while it was off.
    pl_set_company_module($f['actor_id'], $f['company_id'], 'fixed-assets', true, $revision + 1, $manifest['digest'], 'Sample re-enable', bin2hex(random_bytes(16)));
    assert_same('12000.0000', pl_get_asset(...array_merge(asset_args($f), [$asset['id']]))['posted_accumulated']);
    $second = asset_record($f, $class['id'], ['code' => 'BACK-1', 'cost' => '6000.0000']);
    assert_same('6000.0000', $second['posted_cost']);
    assert_same(true, pl_asset_register(...asset_args($f))['reconciled']);
    // The period that could not be run while the module was off runs now, and for both assets.
    assert_same(2, asset_run($f, '2027-12-31')['asset_count']);
    assert_same(true, pl_asset_register(...array_merge(asset_args($f), ['2027-12-31']))['reconciled']);
});

test('the fixed-assets manifest declares what it ships and refuses a changed migration', function (): void {
    $manifest = pl_module_registry()['fixed-assets'];
    assert_same(true, $manifest['optional']);
    assert_same(1, $manifest['contract']);
    assert_same(['core' => '1.0.0'], $manifest['requires'], 'A bundled module pins its dependency versions exactly.');
    assert_same(['046_fixed_assets'], $manifest['migrations']);
    assert_same(['asset-register'], $manifest['reports']);
    foreach (['/fixed-assets', '/fixed-assets/detail', '/fixed-assets/depreciation', '/reports/asset-register'] as $route) {
        assert_true(in_array($route, $manifest['routes'], true), 'The manifest does not declare ' . $route);
    }
    // Every declared route is one the router answers and one pl_render() will render, which is
    // how two 1.2 screens shipped unreachable.
    $router = file_get_contents(PL_APP . '/public/index.php');
    $render = file_get_contents(PL_APP . '/includes/functions/web_functions.php');
    foreach ($manifest['routes'] as $route) {
        assert_true(str_contains((string) $router, "'" . $route . "' =>"), 'The router has no entry for ' . $route);
        // A route whose first segment is a real directory under the document root is answered
        // by the web server's static handler and never reaches index.php. This module's screens
        // were on /assets until the pseudo-locale sweep caught it: www/phpledger/public/assets
        // holds app.css, app.js and the brand images, so /assets was the asset directory and
        // not a screen at all. The sweep catches it for a route it happens to request; this
        // catches it for every route the manifest declares, before anyone has to notice.
        $segment = explode('/', ltrim($route, '/'))[0];
        assert_true(!is_dir(PL_APP . '/public/' . $segment),
            'Route ' . $route . ' collides with the static directory www/phpledger/public/' . $segment . ', so the web server answers it instead of the application.');
    }
    foreach (['assets', 'asset-detail', 'asset-depreciation', 'asset-register'] as $view) {
        assert_true(str_contains((string) $render, "'" . $view . "'"), 'pl_render() does not allow the ' . $view . ' template.');
        assert_true(is_file(PL_APP . '/templates/views/' . $view . '.php'), 'The ' . $view . ' template is missing.');
    }
    // A migration file that no longer matches its applied receipt makes the module unusable
    // rather than half-usable against a schema nobody reviewed.
    $f = asset_fixture();
    DB::startTransaction();
    try {
        DB::update('pl_schema_migrations', ['checksum' => str_repeat('a', 64)], 'version = %s', '046_fixed_assets');
        assert_throws(fn () => pl_require_module(...array_merge(asset_args($f), ['fixed-assets'])), DomainException::class, 'incompatible');
        assert_throws(fn () => asset_class($f), DomainException::class, 'incompatible');
    } finally { DB::rollback(); }
    assert_true(asset_class($f)['id'] > 0, 'The module stopped working after the checksum was restored.');
});

test('the register reports outstanding depreciation and names a control account the ledger disagrees with', function (): void {
    $f = asset_fixture();
    $class = asset_class($f);
    $asset = asset_record($f, $class['id']);
    // Nothing run yet: a full year of the schedule is outstanding, and it is not a ledger figure.
    $register = pl_asset_register(...array_merge(asset_args($f), ['2026-12-31']));
    assert_same(true, $register['reconciled']);
    assert_same('12000.0000', $register['totals']['outstanding']);
    assert_same('0.0000', $register['totals']['accumulated']);
    asset_run($f, '2026-12-31');
    assert_same('0.0000', pl_asset_register(...array_merge(asset_args($f), ['2026-12-31']))['totals']['outstanding']);

    // A manual journal straight into the cost account is exactly what the reconciliation is
    // for: the ledger moves, the register does not, and the report says which account and by
    // how much instead of presenting a total that does not tie.
    pl_post_journal(...array_merge(asset_args($f), [['date' => '2026-12-31', 'currency' => 'USD', 'source_type' => 'expense',
        'source_reference' => 'sample-manual', 'description' => 'Sample manual capitalisation', 'idempotency_key' => bin2hex(random_bytes(16)),
        'lines' => [['account_id' => $class['asset_account_id'], 'debit' => '500.0000', 'credit' => '0'],
            ['account_id' => $f['accounts']['1000'], 'debit' => '0', 'credit' => '500.0000']]]]));
    $broken = pl_asset_register(...array_merge(asset_args($f), ['2026-12-31']));
    assert_same(false, $broken['reconciled']);
    $difference = null;
    foreach ($broken['reconciliation'] as $row) {
        if ($row['purpose'] === 'cost') { $difference = $row; }
        if ($row['purpose'] === 'accumulated_depreciation') { assert_same('0.0000', $row['difference']); }
    }
    assert_true($difference !== null);
    assert_same('500.0000', $difference['difference'], 'The report does not name what reached the control account without the register.');
    assert_same('24500.0000', $difference['ledger']);
    assert_same('24000.0000', $difference['register']);
});

test('reducing balance writes down the carrying amount and trues up to the residual value in the last period', function (): void {
    $f = asset_fixture();
    $class = asset_class($f, ['method' => 'reducing_balance', 'annual_rate' => '0.25', 'useful_life_months' => 48]);
    assert_same('0.250000', (string) $class['annual_rate']);
    $asset = asset_record($f, $class['id'], ['cost' => '100000.0000', 'residual_value' => '10000.0000']);
    $schedule = pl_asset_statement(...array_merge(asset_args($f), [$asset['id']]))['schedule'];
    assert_same(4, count($schedule));
    // 25% of the carrying amount each year, then whatever is left in the last year of the life.
    assert_same('25000.0000', $schedule[0]['charge']);
    assert_same('18750.0000', $schedule[1]['charge']);
    assert_same('14062.5000', $schedule[2]['charge']);
    assert_same('32187.5000', $schedule[3]['charge'], 'The last period of the life writes the balance down to the residual value.');
    $sum = '0.0000';
    foreach ($schedule as $row) { $sum = bcadd($sum, $row['charge'], 4); }
    assert_same('90000.0000', $sum);
    assert_same('10000.0000', $schedule[3]['closing_nbv']);
    // A straight-line class has no rate, and a reducing-balance class cannot be left without one.
    assert_throws(fn () => asset_class($f, ['method' => 'reducing_balance', 'annual_rate' => null]), DomainException::class, 'fraction of one');
    assert_throws(fn () => asset_class($f, ['method' => 'reducing_balance', 'annual_rate' => '1.5']), DomainException::class, 'fraction of one');
    assert_throws(fn () => asset_class($f, ['method' => 'straight_line', 'annual_rate' => '0.25']), DomainException::class, 'useful life');
    assert_throws(fn () => asset_class($f, ['method' => 'units_of_production']), DomainException::class, 'method');
    // Posting it follows the schedule exactly, and the register still ties.
    assert_same('25000.0000', asset_run($f, '2026-12-31')['total_amount']);
    assert_same('18750.0000', asset_run($f, '2027-12-31')['total_amount']);
    assert_same(true, pl_asset_register(...array_merge(asset_args($f), ['2027-12-31']))['reconciled']);
    assert_same('56250.0000', pl_get_asset(...array_merge(asset_args($f), [$asset['id']]))['net_book_value']);
});

test('an asset refuses figures that are not accounting figures', function (): void {
    $f = asset_fixture();
    $class = asset_class($f);
    assert_throws(fn () => asset_record($f, $class['id'], ['cost' => '0']), DomainException::class, 'positive cost');
    assert_throws(fn () => asset_record($f, $class['id'], ['cost' => '-100']), DomainException::class);
    assert_throws(fn () => asset_record($f, $class['id'], ['cost' => '100.00001']), DomainException::class);
    assert_throws(fn () => asset_record($f, $class['id'], ['residual_value' => '30000']), DomainException::class, 'residual value cannot exceed');
    assert_throws(fn () => asset_record($f, $class['id'], ['in_service_date' => '2025-12-31']), DomainException::class, 'before it was acquired');
    assert_throws(fn () => asset_record($f, $class['id'], ['useful_life_months' => 0]), DomainException::class, 'override');
    assert_throws(fn () => asset_record($f, $class['id'], ['credit_account_id' => $class['asset_account_id']]), DomainException::class, 'cost account itself');
    assert_throws(fn () => asset_record($f, $class['id'], ['acquisition_date' => '2030-06-30', 'in_service_date' => '2030-06-30']), DomainException::class, 'open accounting period');
    $first = asset_record($f, $class['id'], ['code' => 'UNIQUE-1']);
    assert_throws(fn () => asset_record($f, $class['id'], ['code' => 'UNIQUE-1']), DomainException::class, 'already in use');
    assert_true($first['id'] > 0);
    // An asset in a class that is not active, and a rate override on a straight-line class.
    assert_throws(fn () => asset_record($f, $class['id'], ['annual_rate' => '0.2']), DomainException::class, 'reducing-balance class');
    assert_throws(fn () => asset_record($f, 0), DomainException::class);
});
