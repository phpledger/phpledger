<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/www/phpledger/includes/functions/web_functions.php';

test('calendar report presets cover leap months quarter and custom boundaries', function (): void {
    assert_same(['from'=>'2024-02-01','to'=>'2024-02-29'],pl_report_period('last_month','2024-03-31'));
    assert_same(['from'=>'2026-07-01','to'=>'2026-09-18'],pl_report_period('quarter','2026-09-18'));
    assert_same(['from'=>'2026-01-01','to'=>'2026-09-18'],pl_report_period('year','2026-09-18'));
    assert_same(null,pl_report_period('custom','2026-09-18'));
    assert_throws(fn()=>pl_report_period('unknown','2026-09-18'),DomainException::class);
});

test('cost-of-sales classification preserves net profit and immutable entries and is auditable', function (): void {
    $f=ledger_fixture();
    foreach (['receipt'=>'1000','expense'=>'125'] as $kind=>$amount) {
        $draft=pl_save_document($f['actor_id'],$f['company_id'],$f['book_id'],document_input($f,$kind,$amount));
        pl_post_document($f['actor_id'],$f['company_id'],$f['book_id'],$draft['id'],$draft['revision']);
    }
    $before=pl_profit_loss($f['actor_id'],$f['company_id'],$f['book_id'],'2026-01-01','2026-12-31');
    assert_same('0.0000',$before['total_cost_of_sales']); assert_same('1000.0000',$before['gross_profit']);
    $entries=DB::query('SELECT * FROM pl_journal_lines WHERE book_id=%i ORDER BY id',$f['book_id']);
    $account=pl_get_account($f['actor_id'],$f['company_id'],$f['book_id'],$f['accounts']['5000']);
    pl_save_account($f['actor_id'],$f['company_id'],$f['book_id'],array_replace($account,['report_classification'=>'cost_of_sales','reason'=>'Sample reviewed cost classification']),$account['id'],$account['revision']);
    $after=pl_profit_loss($f['actor_id'],$f['company_id'],$f['book_id'],'2026-01-01','2026-12-31');
    assert_same('125.0000',$after['total_cost_of_sales']); assert_same('0.0000',$after['total_expenses']);
    assert_same('875.0000',$after['gross_profit']); assert_same($before['net_profit'],$after['net_profit']);
    assert_same($entries,DB::query('SELECT * FROM pl_journal_lines WHERE book_id=%i ORDER BY id',$f['book_id']));
    assert_true(count(pl_core_history($f['actor_id'],$f['company_id'],$f['book_id'],'account',$account['id']))>0);
    $cash=pl_get_account($f['actor_id'],$f['company_id'],$f['book_id'],$f['accounts']['1000']);
    assert_throws(fn()=>pl_save_account($f['actor_id'],$f['company_id'],$f['book_id'],array_replace($cash,['report_classification'=>'cost_of_sales','reason'=>'Invalid type']),$cash['id'],$cash['revision']),DomainException::class);
});

test('owner statements reconcile posted receipt expense and dated reversal without including drafts', function (): void {
    $f = ledger_fixture(); $today = gmdate('Y-m-d');
    $receipt = pl_save_document($f['actor_id'], $f['company_id'], $f['book_id'], document_input($f, 'receipt', '1000'));
    pl_post_document($f['actor_id'], $f['company_id'], $f['book_id'], $receipt['id'], 1);
    $expense = pl_save_document($f['actor_id'], $f['company_id'], $f['book_id'], document_input($f));
    pl_post_document($f['actor_id'], $f['company_id'], $f['book_id'], $expense['id'], 1);
    pl_save_document($f['actor_id'], $f['company_id'], $f['book_id'], document_input($f, 'expense', '999'));
    $income = pl_profit_loss($f['actor_id'], $f['company_id'], $f['book_id'], '2026-09-01', '2026-09-14');
    assert_same('1000.0000', $income['total_income']);
    assert_same('125.0000', $income['total_expenses']);
    assert_same('875.0000', $income['net_profit']);
    $balance = pl_balance_sheet($f['actor_id'], $f['company_id'], $f['book_id'], '2026-09-14');
    assert_same('875.0000', $balance['total_assets']);
    assert_same('0.0000', $balance['recorded_equity']);
    assert_same('875.0000', $balance['earned_profit']);
    assert_same('875.0000', $balance['total_equity']);
    assert_true($balance['balanced']);
    assert_same('875.0000', pl_cash_balance($f['actor_id'], $f['company_id'], $f['book_id'], '2026-09-14'));
    pl_reverse_document($f['actor_id'], $f['company_id'], $f['book_id'], $expense['id'], $today, 'Sample report correction');
    assert_same('875.0000', pl_balance_sheet($f['actor_id'], $f['company_id'], $f['book_id'], '2026-09-14')['earned_profit']);
    assert_same('1000.0000', pl_balance_sheet($f['actor_id'], $f['company_id'], $f['book_id'], $today)['total_assets']);
    assert_same('-125.0000', pl_profit_loss($f['actor_id'], $f['company_id'], $f['book_id'], $today, $today)['total_expenses']);
    $activity = pl_account_activity($f['actor_id'], $f['company_id'], $f['book_id'], $f['accounts']['5000'], $today, 1, $today);
    assert_same($today, $activity['from']);
    assert_same(1, $activity['total']);
    assert_same('-125.0000', $activity['balance']);
    assert_same('0.0000', $activity['debit_movement']);
    assert_same('125.0000', $activity['credit_movement']);
    assert_same('125.0000', $activity['opening_balance']);
    assert_same('0.0000', $activity['closing_balance']);
    assert_same('0.0000', $activity['movements'][0]['running_balance']);
    assert_same($expense['id'], $activity['movements'][0]['document_id']);
    assert_throws(fn () => pl_account_activity($f['actor_id'], $f['company_id'], $f['book_id'], $f['accounts']['5000'], '2026-09-14', 1, '2026-09-15'), DomainException::class);
});

test('account statements carry opening balances through inclusive dates and exclude later postings', function (): void {
    $f = ledger_fixture();
    $opening = ledger_payload($f, '1000');
    $opening['date'] = '2026-08-31';
    pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], $opening);
    $receipt = ledger_payload($f, '200');
    $receipt['date'] = '2026-09-01';
    pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], $receipt);
    $expense = pl_save_document($f['actor_id'], $f['company_id'], $f['book_id'], document_input($f));
    pl_post_document($f['actor_id'], $f['company_id'], $f['book_id'], $expense['id'], 1);
    $later = ledger_payload($f, '999');
    $later['date'] = '2026-09-15';
    pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], $later);
    $statement = pl_account_activity($f['actor_id'], $f['company_id'], $f['book_id'], $f['accounts']['1000'], '2026-09-14', 1, '2026-09-01');
    assert_same('1000.0000', $statement['opening_balance']);
    assert_same('200.0000', $statement['debit_movement']);
    assert_same('125.0000', $statement['credit_movement']);
    assert_same('1075.0000', $statement['closing_balance']);
    assert_same(['1200.0000', '1075.0000'], array_column($statement['movements'], 'running_balance'));
    assert_same('1000.0000', $statement['page_opening_balance']);
    assert_same('1075.0000', $statement['page_closing_balance']);
    $all = pl_account_activity($f['actor_id'], $f['company_id'], $f['book_id'], $f['accounts']['1000'], '2026-09-14');
    assert_same('0.0000', $all['opening_balance']);
    assert_same('1075.0000', $all['closing_balance']);
});

test('empty account periods retain inactive account history and exact credit balances', function (): void {
    $f = ledger_fixture();
    pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], ledger_payload($f, '1234.5678'));
    DB::update('pl_accounts', ['is_active' => 0], 'id = %i', $f['accounts']['4000']);
    $statement = pl_account_activity($f['actor_id'], $f['company_id'], $f['book_id'], $f['accounts']['4000'], '2026-09-30', 9, '2026-09-15');
    assert_same(false, $statement['account']['is_active']);
    assert_same([], $statement['movements']);
    assert_same(1, $statement['page']);
    assert_same(1, $statement['pages']);
    foreach (['opening_balance', 'closing_balance', 'page_opening_balance', 'page_closing_balance'] as $key) {
        assert_same('-1234.5678', $statement[$key]);
    }
    assert_same('0.0000', $statement['debit_movement']);
    assert_same('0.0000', $statement['credit_movement']);
    $before = pl_account_activity($f['actor_id'], $f['company_id'], $f['book_id'], $f['accounts']['4000'], '2026-09-13', 1, '2026-09-01');
    assert_same('0.0000', $before['opening_balance']);
    assert_same('0.0000', $before['closing_balance']);
});

test('running balances cross debit and credit exactly without assuming an accounts normal side', function (): void {
    $f = ledger_fixture();
    $payload = ledger_payload($f, '0.1001');
    $payload['lines'] = [
        ['account_id' => $f['accounts']['5000'], 'debit' => '0.1001', 'credit' => '0'],
        ['account_id' => $f['accounts']['1000'], 'debit' => '0', 'credit' => '0.1001'],
    ];
    pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], $payload);
    pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], ledger_payload($f, '0.1001'));
    pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], ledger_payload($f, '0.0001'));
    $statement = pl_account_activity($f['actor_id'], $f['company_id'], $f['book_id'], $f['accounts']['1000'], '2026-09-14', 1, '2026-09-14');
    assert_same(['-0.1001', '0.0000', '0.0001'], array_column($statement['movements'], 'running_balance'));
    assert_same('0.0001', $statement['closing_balance']);
});

test('account pagination carries balances across tied lines and backdated journals in ledger order', function (): void {
    $f = ledger_fixture();
    $payload = ledger_payload($f, '51.0051');
    $payload['lines'] = array_fill(0, 51, ['account_id' => $f['accounts']['1000'], 'debit' => '1.0001', 'credit' => '0']);
    $payload['lines'][] = ['account_id' => $f['accounts']['4000'], 'debit' => '0', 'credit' => '51.0051'];
    $bulk = pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], $payload);
    $backdated = ledger_payload($f, '10');
    $backdated['date'] = '2026-09-01';
    $first = pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], $backdated);
    $opening = ledger_payload($f, '100');
    $opening['date'] = '2026-08-31';
    pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], $opening);
    $page1 = pl_account_activity($f['actor_id'], $f['company_id'], $f['book_id'], $f['accounts']['1000'], '2026-09-14', 1, '2026-09-01');
    $page2 = pl_account_activity($f['actor_id'], $f['company_id'], $f['book_id'], $f['accounts']['1000'], '2026-09-14', 99, '2026-09-01');
    assert_same(52, $page1['total']);
    assert_same(2, $page2['page']);
    assert_same($first['id'], $page1['movements'][0]['journal_id']);
    assert_same($bulk['id'], $page1['movements'][1]['journal_id']);
    assert_same('100.0000', $page1['page_opening_balance']);
    assert_same('159.0049', $page1['page_closing_balance']);
    assert_same($page1['page_closing_balance'], $page2['page_opening_balance']);
    assert_same(['160.0050', '161.0051'], array_column($page2['movements'], 'running_balance'));
    assert_same('161.0051', $page1['closing_balance']);
    assert_same($page1['closing_balance'], $page2['closing_balance']);
    assert_same($page2['closing_balance'], $page2['page_closing_balance']);
});

test('every ledger account closing reconciles to the trial balance across all five types', function (): void {
    $f = ledger_fixture();
    $payload = ledger_payload($f, '500');
    $payload['date'] = '2026-08-31';
    $payload['lines'][1]['account_id'] = $f['accounts']['3000'];
    pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], $payload);
    $payload = ledger_payload($f, '200');
    $payload['lines'][1]['account_id'] = $f['accounts']['2000'];
    pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], $payload);
    pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], ledger_payload($f, '100'));
    $expense = pl_save_document($f['actor_id'], $f['company_id'], $f['book_id'], document_input($f));
    pl_post_document($f['actor_id'], $f['company_id'], $f['book_id'], $expense['id'], 1);
    $trial = pl_trial_balance($f['actor_id'], $f['company_id'], $f['book_id'], '2026-09-14');
    $netClosing = '0.0000';
    foreach ($trial['accounts'] as $account) {
        $statement = pl_account_activity($f['actor_id'], $f['company_id'], $f['book_id'], $account['id'], '2026-09-14', 1, '2026-09-01');
        assert_same($account['balance'], $statement['closing_balance'], 'Account ' . $account['code']);
        $netClosing = bcadd($netClosing, $statement['closing_balance'], 4);
    }
    assert_same('0.0000', $netClosing);
    assert_same(5, count(array_unique(array_column($trial['accounts'], 'type'))));
});

test('account statements enforce actor company book and account scope while permitting viewers', function (): void {
    $f = ledger_fixture();
    $other = ledger_fixture();
    pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], ledger_payload($f, '100'));
    assert_throws(fn () => pl_account_activity($other['actor_id'], $f['company_id'], $f['book_id'], $f['accounts']['1000']), DomainException::class);
    assert_throws(fn () => pl_account_activity($f['actor_id'], $f['company_id'], $other['book_id'], $other['accounts']['1000']), DomainException::class);
    assert_throws(fn () => pl_account_activity($f['actor_id'], $f['company_id'], $f['book_id'], $other['accounts']['1000']), DomainException::class);
    assert_throws(fn () => pl_account_activity($f['actor_id'], $f['company_id'], $f['book_id'], $f['accounts']['1000'], '2026-02-30'), DomainException::class);
    assert_throws(fn () => pl_account_activity($f['actor_id'], $f['company_id'], $f['book_id'], $f['accounts']['1000'], '2026-09-14', 1, 'not-a-date'), DomainException::class);
    sample_membership_insert(['company_id' => $f['company_id'], 'user_id' => $other['actor_id'], 'role' => 'viewer']);
    $statement = pl_account_activity($other['actor_id'], $f['company_id'], $f['book_id'], $f['accounts']['1000'], '2026-09-30', 1, '2026-09-15');
    assert_same('100.0000', $statement['opening_balance']);
    assert_same('100.0000', $statement['closing_balance']);
});

test('owner statements include liabilities recorded capital and retained earnings exactly once', function (): void {
    $f = ledger_fixture();
    $payload = ledger_payload($f, '500');
    $payload['lines'][1]['account_id'] = $f['accounts']['3000'];
    pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], $payload);
    $payload = ledger_payload($f, '200');
    $payload['lines'][1]['account_id'] = $f['accounts']['2000'];
    pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], $payload);
    pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], ledger_payload($f, '100'));
    $statement = pl_balance_sheet($f['actor_id'], $f['company_id'], $f['book_id'], '2026-09-14');
    assert_same('800.0000', $statement['total_assets']);
    assert_same('200.0000', $statement['total_liabilities']);
    assert_same('500.0000', $statement['recorded_equity']);
    assert_same('100.0000', $statement['earned_profit']);
    assert_same('600.0000', $statement['total_equity']);
    assert_same('800.0000', $statement['total_liabilities_equity']);
    assert_true($statement['balanced']);
    DB::update('pl_accounts', ['is_active' => 0], 'id = %i', $f['accounts']['1000']);
    assert_same('800.0000', pl_cash_balance($f['actor_id'], $f['company_id'], $f['book_id'], '2026-09-14'));
});

test('owner reports provide zero states valid date boundaries and scoped read permissions', function (): void {
    $f = ledger_fixture();
    $other = ledger_fixture();
    assert_same('0.0000', pl_profit_loss($f['actor_id'], $f['company_id'], $f['book_id'], '2026-01-01', '2026-09-14')['net_profit']);
    assert_true(pl_balance_sheet($f['actor_id'], $f['company_id'], $f['book_id'], '2025-01-01')['balanced']);
    assert_same('0.0000', pl_cash_balance($f['actor_id'], $f['company_id'], $f['book_id'], '2026-09-14'));
    assert_throws(fn () => pl_profit_loss($f['actor_id'], $f['company_id'], $f['book_id'], '2026-09-15', '2026-09-14'), DomainException::class);
    assert_throws(fn () => pl_balance_sheet($f['actor_id'], $f['company_id'], $f['book_id'], '2026-02-30'), DomainException::class);
    assert_throws(fn () => pl_balance_sheet($other['actor_id'], $f['company_id'], $f['book_id'], '2026-09-14'), DomainException::class);
    assert_throws(fn () => pl_profit_loss($f['actor_id'], $f['company_id'], $other['book_id'], '2026-01-01', '2026-09-14'), DomainException::class);
    assert_throws(fn () => pl_cash_balance($other['actor_id'], $f['company_id'], $f['book_id'], '2026-09-14'), DomainException::class);
    sample_membership_insert(['company_id' => $f['company_id'], 'user_id' => $other['actor_id'], 'role' => 'viewer']);
    assert_true(pl_balance_sheet($other['actor_id'], $f['company_id'], $f['book_id'], '2026-09-14')['balanced']);
});

test('cash scenarios use exact signed balances and disclose the first shortfall without writes', function (): void {
    $projection = pl_cash_forecast('875', '100', '200', 12);
    assert_same('875.0000', $projection['opening']);
    assert_same('775.0000', $projection['rows'][0]['closing']);
    assert_same('-325.0000', $projection['closing']);
    assert_same(9, $projection['first_negative_week']);
    assert_same(0, pl_cash_forecast('-0.1000', '0.1000', '0.2000', 1)['first_negative_week']);
    assert_same('-0.2000', pl_cash_forecast('-0.1000', '0.1000', '0.2000', 1)['closing']);
    assert_same('0.3000', pl_cash_forecast('0.1000', '0.2000', '0', 1)['closing']);
    assert_same(null, pl_cash_forecast('0', '0', '0', 12)['first_negative_week']);
    assert_throws(fn () => pl_cash_forecast('0', '-1', '0'), DomainException::class);
    assert_throws(fn () => pl_cash_forecast('0', '0', '0', 53), DomainException::class);
    assert_throws(fn () => pl_cash_forecast('0', '0', '0', 0), DomainException::class);
    assert_throws(fn () => pl_cash_forecast('NaN', '0', '0'), DomainException::class);
});
