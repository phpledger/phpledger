<?php
declare(strict_types=1);

test('core two-period business reconciles cutover journals bank balances earnings and closed periods', function (): void {
    $f = ledger_fixture('USD', '2024-01-01');
    DB::update('pl_companies', ['setup_status' => 'opening_required'], 'id = %i', $f['company_id']);
    $opening = pl_preview_opening($f['actor_id'], $f['company_id'], $f['book_id'], [
        'cutover_date' => '2024-01-01', 'source' => 'Sample core-only opening',
        'balances' => [['account_code' => '1000', 'debit' => '1000', 'credit' => '0'], ['account_code' => '3000', 'debit' => '0', 'credit' => '1000']], 'unpaid_documents' => [],
    ], 'two-period-opening');
    pl_confirm_opening($f['actor_id'], $f['company_id'], $f['book_id'], (int) $opening['id'], $opening['payload_hash'], true);
    pl_create_period($f['actor_id'], $f['company_id'], $f['book_id'], ['start_date' => '2025-01-01', 'end_date' => '2025-12-31', 'reason' => 'Next sample year', 'request_key' => 'next-year']);
    $openingBalance = '1000.0000';
    foreach ([['2024', '100', '25', '1075.0000', '75.0000'], ['2025', '50', '5', '1120.0000', '45.0000']] as [$year, $income, $expense, $closing, $profit]) {
        $journals = [];
        foreach ([['09-10', $income, '0', '4000'], ['09-11', '0', $expense, '5000']] as [$day, $debit, $credit, $contra]) {
            $draft = pl_save_general_draft($f['actor_id'], $f['company_id'], $f['book_id'], [
                'date' => $year . '-' . $day, 'reference' => 'Two-period fixture', 'description' => 'Sample core activity', 'creation_key' => $year . '-' . $day,
                'lines' => [
                    ['account_id' => $f['accounts']['1000'], 'debit' => $debit, 'credit' => $credit],
                    ['account_id' => $f['accounts'][$contra], 'debit' => $credit, 'credit' => $debit],
                ],
            ]);
            $posted = pl_post_general_draft($f['actor_id'], $f['company_id'], $f['book_id'], $draft['id'], 1);
            $journals[] = (int) $posted['journal_id'];
        }
        $start = $year === '2024' ? '2024-01-02' : '2025-01-01';
        $input = ['account_id' => $f['accounts']['1000'], 'reference' => 'Statement-' . $year, 'start_date' => $start, 'end_date' => $year . '-12-31', 'opening_balance' => $openingBalance, 'closing_balance' => $closing, 'baseline_confirmed' => true,
            'rows' => [bank_fixture_row('income-' . $year, $income, '0', $year . '-09-10'), bank_fixture_row('expense-' . $year, '0', $expense, $year . '-09-11')]];
        $statement = bank_fixture_import($f, $input);
        foreach ($statement['rows'] as $i => $row) {
            $statement = pl_bank_match_row($f['actor_id'], $f['company_id'], $f['book_id'], (int) $statement['id'], (int) $row['id'], bank_fixture_line($journals[$i], $f['accounts']['1000']), (int) $statement['revision']);
        }
        $done = pl_bank_complete_statement($f['actor_id'], $f['company_id'], $f['book_id'], (int) $statement['id'], (int) $statement['revision'], 'complete-' . $year);
        assert_same($closing, $done['ledger_balance']);
        assert_same('0.0000', $done['outstanding_balance']);
        $activity = pl_account_activity($f['actor_id'], $f['company_id'], $f['book_id'], $f['accounts']['1000'], $year . '-12-31', 1, $start);
        assert_same($openingBalance, $activity['opening_balance']);
        assert_same($closing, $activity['closing_balance']);
        assert_same($profit, pl_profit_loss($f['actor_id'], $f['company_id'], $f['book_id'], $start, $year . '-12-31')['net_profit']);
        $period = DB::queryFirstRow('SELECT id, revision FROM pl_periods WHERE book_id = %i AND end_date = %s', $f['book_id'], $year . '-12-31');
        pl_change_period_status($f['actor_id'], $f['company_id'], $f['book_id'], (int) $period['id'], 'closed', (int) $period['revision'], 'Sample balances reconciled', 'close-' . $year);
        $openingBalance = $closing;
    }
    $sheet = pl_balance_sheet($f['actor_id'], $f['company_id'], $f['book_id'], '2025-12-31');
    assert_same('1000.0000', $sheet['recorded_equity']);
    assert_same('120.0000', $sheet['earned_profit']);
    assert_same('1120.0000', $sheet['total_assets']);
    assert_true($sheet['balanced']);
    foreach (pl_trial_balance($f['actor_id'], $f['company_id'], $f['book_id'], '2025-12-31')['accounts'] as $account) {
        assert_same($account['balance'], pl_account_activity($f['actor_id'], $f['company_id'], $f['book_id'], $account['id'], '2025-12-31')['closing_balance']);
    }
    assert_same(5, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id = %i', $f['book_id']));
});

test('core CSV exports include all statement pages exact balances safe text and scoped access', function (): void {
    $f = ledger_fixture();
    for ($i = 0; $i < 51; ++$i) {
        $payload = ledger_payload($f, '0.1001', 'export-' . $i);
        $payload['description'] = '=HYPERLINK("https://example.invalid")';
        pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], $payload);
    }
    $export = pl_export_report($f['actor_id'], $f['company_id'], $f['book_id'], 'account', '2026-12-31', '2026-01-01', $f['accounts']['1000']);
    assert_same(51, substr_count($export['csv'], 'PL-'));
    assert_true(str_contains($export['csv'], "'=HYPERLINK"));
    assert_true(str_contains($export['csv'], '5.1051'));
    foreach (['trial-balance', 'profit-loss', 'balance-sheet'] as $kind) {
        $result = pl_export_report($f['actor_id'], $f['company_id'], $f['book_id'], $kind, '2026-12-31', '2026-01-01');
        assert_true(str_contains($result['csv'], '5.1051'));
    }
    $other = ledger_fixture();
    assert_throws(fn() => pl_export_report($other['actor_id'], $f['company_id'], $f['book_id'], 'trial-balance', '2026-12-31'), DomainException::class);
    sample_membership_insert(['company_id' => $f['company_id'], 'user_id' => $other['actor_id'], 'role' => 'viewer']);
    assert_true(str_contains(pl_export_report($other['actor_id'], $f['company_id'], $f['book_id'], 'trial-balance', '2026-12-31')['csv'], '5.1051'));
    assert_throws(fn() => pl_export_report($f['actor_id'], $f['company_id'], $f['book_id'], 'account', '2026-12-31', null, $other['accounts']['1000']), DomainException::class);
    assert_throws(fn() => pl_export_report($f['actor_id'], $f['company_id'], $f['book_id'], 'profit-loss', '2026-01-01', '2026-12-31'), DomainException::class);
    foreach (['=1', '+1', '-formula', '@SUM(1)', "\t=1", '  =1'] as $input) { assert_same("'" . $input, pl_csv_text($input)); }
    assert_same('ordinary text', pl_csv_text('ordinary text'));
});
