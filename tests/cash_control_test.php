<?php
declare(strict_types=1);

function cash_control_fixture(?string $kind = 'physical'): array
{
    $f = ledger_fixture();
    $policy = pl_trading_policies($f['actor_id'], $f['company_id'], $f['book_id']);
    pl_save_trading_policies($f['actor_id'], $f['company_id'], $f['book_id'], array_merge($policy, ['cash_shortfall_policy' => 'strict', 'reason' => 'Exercise opted-in strict cash controls', 'idempotency_key' => 'strict-cash-fixture']));
    DB::update('pl_accounts', ['money_kind' => $kind, 'overdraft_enabled' => 0, 'overdraft_limit' => '0.0000'], 'id=%i', $f['accounts']['1000']);
    return $f;
}

function cash_control_payload(array $f, string $amount, string $date, bool $payment = false, ?string $key = null): array
{
    $payload = ledger_payload($f, $amount, $key);
    $payload['date'] = $date;
    $payload['source_type'] = 'general_journal';
    $payload['source_reference'] = 'cash-control:' . $payload['idempotency_key'];
    if ($payment) {
        foreach ($payload['lines'] as &$line) { [$line['debit'], $line['credit']] = [$line['credit'], $line['debit']]; }
        unset($line);
    }
    return $payload;
}

function cash_control_post(array $f, string $amount, string $date, bool $payment = false, ?string $key = null): array
{
    return pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], cash_control_payload($f, $amount, $date, $payment, $key));
}

function cash_control_fx_payload(array $f, string $units, string $rate, string $date, bool $payment = false): array
{
    $payload = cash_control_payload($f, pl_fx_convert($units, $rate), $date, $payment);
    $payload['lines'][0] += ['currency' => 'EUR', 'amount_fc' => $units, 'rate' => $rate];
    return $payload;
}

function cash_control_set_overdraft(array $f, ?string $limit): array
{
    $account = pl_get_account($f['actor_id'], $f['company_id'], $f['book_id'], $f['accounts']['1000']);
    return pl_save_account($f['actor_id'], $f['company_id'], $f['book_id'], array_replace($account, [
        'overdraft_enabled' => $limit !== null, 'overdraft_limit' => $limit ?? '0', 'reason' => 'Record the explicit fictional agreed credit facility for this test.',
    ]), $account['id'], $account['revision']);
}

test('physical foreign cash cannot overdraw units behind a positive translated balance', function (): void {
    $f = cash_control_fixture();
    $post = fn(array $p): array => pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], $p);
    $post(cash_control_fx_payload($f, '100', '2', '2026-01-01'));
    // USD 200 carrying value minus USD 110 remains positive, but EUR 110 exceeds EUR 100.
    assert_throws(fn() => $post(cash_control_fx_payload($f, '110', '1', '2026-01-02', true)), DomainException::class, '(EUR)');
    $post(cash_control_fx_payload($f, '90', '1', '2026-01-02', true));
    cash_control_post($f, '100', '2026-01-02');
    // A currency-neutral account may hold several currencies; dollars cannot supply euros.
    assert_throws(fn() => $post(cash_control_fx_payload($f, '11', '1', '2026-01-03', true)), DomainException::class, '(EUR)');
    // The base-carrying constraint is retained even when sufficient foreign units exist.
    assert_throws(fn() => $post(cash_control_fx_payload($f, '10', '30', '2026-01-03', true)), DomainException::class, 'would leave');
    $snapshot = pl_cash_timeline($f['company_id'], $f['book_id'], [$f['accounts']['1000']]);
    assert_same('100.0000', $snapshot[$f['accounts']['1000']]['currency_daily']['EUR']['2026-01-01']);
    assert_same('-90.0000', $snapshot[$f['accounts']['1000']]['currency_daily']['EUR']['2026-01-02']);
    // Existing normalization intentionally rejects valuation-only zero-unit cash lines.
    $zero = cash_control_fx_payload($f, '1', '1', '2026-01-04'); $zero['lines'][0]['amount_fc'] = '0';
    assert_throws(fn() => $post($zero), DomainException::class, 'Invalid currency amount');
});

test('atomic correction preserves foreign units and rejects a final FX shortfall', function (): void {
    $f = cash_control_fixture();
    DB::update('pl_accounts', ['currency' => 'EUR'], 'id=%i', $f['accounts']['1000']);
    $input = core_general_input($f); $input['date'] = '2026-01-01';
    $input['lines'] = cash_control_fx_payload($f, '100', '2', '2026-01-01')['lines'];
    $source = pl_save_general_draft($f['actor_id'], $f['company_id'], $f['book_id'], $input);
    $source = pl_post_general_draft($f['actor_id'], $f['company_id'], $f['book_id'], $source['id'], $source['revision']);
    pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], cash_control_fx_payload($f, '90', '1', '2026-01-02', true));
    $input['description'] = 'Reviewed foreign funding description';
    $corrected = pl_correct_source($f['actor_id'], $f['company_id'], $f['book_id'], 'general_journal', $source['id'], $source['revision'], $input, '2026-01-01', 'fx-funding-correction', 'Fix description without changing funds');
    $before = (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id=%i', $f['book_id']);
    $input['lines'] = cash_control_fx_payload($f, '80', '2', '2026-01-01')['lines'];
    assert_throws(fn() => pl_correct_source($f['actor_id'], $f['company_id'], $f['book_id'], 'general_journal', $source['id'], $corrected['revision'], $input, '2026-01-01', 'fx-funding-shortfall', 'Reduce foreign funds'), DomainException::class, '(EUR)');
    assert_same($before, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id=%i', $f['book_id']));
});

test('cash daily comparison never nets separate currency buckets', function (): void {
    $before = [1 => ['name' => 'Mixed notes', 'money_kind' => 'physical', 'daily' => ['2026-01-01' => '300.0000'],
        'currency_daily' => ['EUR' => ['2026-01-01' => '100.0000'], 'USD' => ['2026-01-01' => '100.0000']]]];
    $after = $before; $after[1]['daily']['2026-01-02'] = '-110.0000';
    $after[1]['currency_daily']['EUR']['2026-01-02'] = '-110.0000';
    assert_throws(fn() => pl_cash_assert_timeline($before, $after), DomainException::class, '(EUR)');
});

test('money classification is explicit scoped audited and preserves omitted values and retries', function (): void {
    $f = ledger_fixture();
    $newCompany = pl_create_company($f['actor_id'], 'Unclassified starter example', 'USD', '2026-01-01');
    assert_same(null, pl_get_account($f['actor_id'], $newCompany['company_id'], $newCompany['book_id'], $newCompany['accounts']['1000'])['money_kind']);
    $input = ['code' => 'CASH-EXAMPLE', 'name' => 'Petty cash', 'type' => 'asset', 'role' => 'cash_bank', 'money_kind' => 'physical', 'is_active' => true, 'reason' => 'Identify actual notes and coins', 'creation_key' => 'cash-kind-create'];
    $account = pl_save_account($f['actor_id'], $f['company_id'], $f['book_id'], $input);
    assert_same('physical', $account['money_kind']);
    assert_same($account['id'], pl_save_account($f['actor_id'], $f['company_id'], $f['book_id'], $input)['id']);
    $changedRetry = $input; $changedRetry['money_kind'] = 'bank';
    assert_throws(fn() => pl_save_account($f['actor_id'], $f['company_id'], $f['book_id'], $changedRetry), DomainException::class, 'different account');
    unset($input['money_kind']); $input['name'] = 'Cash box';
    $updated = pl_save_account($f['actor_id'], $f['company_id'], $f['book_id'], $input, $account['id'], $account['revision']);
    assert_same('physical', $updated['money_kind']);
    $input['money_kind'] = 'bank'; $input['reason'] = 'Correct a reviewed classification decision';
    $reclassified = pl_save_account($f['actor_id'], $f['company_id'], $f['book_id'], $input, $account['id'], $updated['revision']);
    assert_same('bank', $reclassified['money_kind']);
    assert_true(count(pl_core_history($f['actor_id'], $f['company_id'], $f['book_id'], 'account', $account['id'])) >= 3);
    assert_throws(fn() => pl_save_account($f['actor_id'], $f['company_id'], $f['book_id'], $input, $account['id'], $updated['revision']), DomainException::class, 'Someone changed');
    $expense = core_account_input(); $expense['money_kind'] = 'physical';
    assert_throws(fn() => pl_save_account($f['actor_id'], $f['company_id'], $f['book_id'], $expense), DomainException::class, 'only for a cash / bank');
    assert_throws(fn() => DB::update('pl_accounts', ['money_kind' => 'physical'], 'id=%i', $f['accounts']['4000']));
});

test('cash daily comparison allows old deficits to improve but never worsen', function (): void {
    $before = [1 => ['name' => 'Physical till', 'money_kind' => 'physical', 'daily' => ['2026-01-01' => '-10.0000', '2026-01-03' => '20.0000']]];
    $after = $before; $after[1]['daily']['2026-01-02'] = '5.0000';
    pl_cash_assert_timeline($before, $after);
    $after[1]['daily']['2026-01-02'] = '-1.0000';
    assert_throws(fn() => pl_cash_assert_timeline($before, $after), DomainException::class, '2026-01-02');
});

test('cash control rejects zero and insufficient funds but permits exact payment and exact retry', function (): void {
    $f = cash_control_fixture();
    assert_throws(fn() => cash_control_post($f, '1', '2026-01-02', true), DomainException::class, 'keep the document as a draft');
    assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id=%i', $f['book_id']));
    cash_control_post($f, '10', '2026-01-01');
    assert_throws(fn() => cash_control_post($f, '10.0001', '2026-01-02', true), DomainException::class);
    $paid = cash_control_post($f, '10', '2026-01-02', true, 'exact-payment');
    assert_same($paid['id'], cash_control_post($f, '10', '2026-01-02', true, 'exact-payment')['id']);
    assert_same('0.0000', pl_cash_payment_preview($f['actor_id'], $f['company_id'], $f['book_id'], $f['accounts']['1000'], '2026-01-02', '0')['balance']);
});

test('future funding cannot pay an earlier expense and backdating cannot break a later closing balance', function (): void {
    $f = cash_control_fixture();
    cash_control_post($f, '10', '2026-01-03');
    assert_throws(fn() => cash_control_post($f, '5', '2026-01-02', true), DomainException::class, '2026-01-02');
    cash_control_post($f, '10', '2026-01-01');
    cash_control_post($f, '20', '2026-01-04', true);
    // January 2 itself has enough money, but spending it would overdraw January 4.
    assert_throws(fn() => cash_control_post($f, '1', '2026-01-02', true), DomainException::class, '2026-01-04');
    $preview = pl_cash_payment_preview($f['actor_id'], $f['company_id'], $f['book_id'], $f['accounts']['1000'], '2026-01-02', '1');
    assert_same('9.0000', $preview['projected_balance']); assert_same(true, $preview['insufficient_cash']);
    assert_true(str_contains($preview['warning'], '2026-01-04'));
});

test('same-day cash and a bank default to zero while an explicit facility permits its exact floor', function (): void {
    $f = cash_control_fixture();
    cash_control_post($f, '10', '2026-01-02');
    cash_control_post($f, '10', '2026-01-02', true);
    $bank = cash_control_fixture('bank');
    assert_throws(fn() => cash_control_post($bank, '1', '2026-01-01', true), DomainException::class, 'permitted floor of 0.0000');
    cash_control_set_overdraft($bank, '100');
    cash_control_post($bank, '100', '2026-01-01', true);
    $preview = pl_cash_payment_preview($bank['actor_id'], $bank['company_id'], $bank['book_id'], $bank['accounts']['1000'], '2026-01-01', '10');
    assert_same('-100.0000', $preview['balance']); assert_same(true, $preview['blocked_payment']);
    assert_same('-100.0000', $preview['permitted_floor']); assert_same('USD', $preview['facility_currency']);
    assert_throws(fn() => cash_control_post($bank, '0.0001', '2026-01-02', true), DomainException::class);
});

test('unclassified cash-bank accounts require an explicit decision before money leaves', function (): void {
    $f = cash_control_fixture(null);
    cash_control_post($f, '10', '2026-01-01');
    assert_throws(fn() => cash_control_post($f, '1', '2026-01-02', true), DomainException::class, 'Choose physical cash or bank');
});

test('cash preview uses posted dated balances and rejects other-company accounts', function (): void {
    $f = cash_control_fixture(); $other = cash_control_fixture();
    cash_control_post($f, '10', '2026-01-01'); cash_control_post($f, '30', '2026-01-03');
    $preview = pl_cash_payment_preview($f['actor_id'], $f['company_id'], $f['book_id'], $f['accounts']['1000'], '2026-01-02', '11');
    assert_same('10.0000', $preview['balance']); assert_same('-1.0000', $preview['projected_balance']); assert_same(true, $preview['insufficient_cash']);
    assert_throws(fn() => pl_cash_payment_preview($f['actor_id'], $f['company_id'], $f['book_id'], $other['accounts']['1000'], '2026-01-02', '1'), DomainException::class);
});

test('standalone funding reversal cannot leave spent physical cash unfunded', function (): void {
    $f = cash_control_fixture();
    $funding = cash_control_post($f, '10', '2026-01-01');
    cash_control_post($f, '10', '2026-01-02', true);
    assert_throws(fn() => pl_reverse_journal($f['actor_id'], $f['company_id'], $f['book_id'], $funding['id'], '2026-01-01', 'reverse-spent-funding', 'Cash funding correction'), DomainException::class, '2026-01-02');
});

test('atomic correction evaluates final cash and rolls back a net shortfall', function (): void {
    $f = cash_control_fixture();
    $input = core_general_input($f);
    $input['date'] = '2026-01-01';
    $input['lines'] = cash_control_payload($f, '10', '2026-01-01')['lines'];
    $source = pl_save_general_draft($f['actor_id'], $f['company_id'], $f['book_id'], $input);
    $source = pl_post_general_draft($f['actor_id'], $f['company_id'], $f['book_id'], $source['id'], $source['revision']);
    cash_control_post($f, '10', '2026-01-02', true);
    $input['description'] = 'Corrected funding description';
    $result = pl_correct_source($f['actor_id'], $f['company_id'], $f['book_id'], 'general_journal', $source['id'], $source['revision'], $input, '2026-01-01', 'funding-description-correction', 'Correct description');
    assert_true($result['journal_id'] > 0);
    $before = (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id=%i', $f['book_id']);
    $input['lines'] = cash_control_payload($f, '9', '2026-01-01')['lines'];
    assert_throws(fn() => pl_correct_source($f['actor_id'], $f['company_id'], $f['book_id'], 'general_journal', $source['id'], $result['revision'], $input, '2026-01-01', 'funding-shortfall-correction', 'Correct amount'), DomainException::class, '2026-01-02');
    assert_same($before, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id=%i', $f['book_id']));
    assert_same([], pl_cash_atomic_scope());
});

test('cash history already below zero can be funded without rewriting old journals', function (): void {
    // A bank fixture models legacy history before an owner explicitly classifies physical cash.
    $f = cash_control_fixture('bank'); cash_control_set_overdraft($f, '10'); cash_control_post($f, '10', '2026-01-01', true);
    DB::update('pl_accounts', ['money_kind' => 'physical', 'overdraft_enabled' => 0, 'overdraft_limit' => '0.0000'], 'id=%i', $f['accounts']['1000']);
    cash_control_post($f, '5', '2026-01-02');
    assert_throws(fn() => cash_control_post($f, '1', '2026-01-03', true), DomainException::class);
    cash_control_post($f, '5', '2026-01-03');
});

test('concurrent payments recheck fresh cash even when a caller has an earlier read snapshot', function (): void {
    $f = cash_control_fixture(); cash_control_post($f, '10', '2026-01-01');
    $results = ledger_race([
        ['mode' => 'cash_post', 'fixture' => $f, 'payload' => cash_control_payload($f, '7', '2026-01-02', true, 'cash-race-a')],
        ['mode' => 'cash_post', 'fixture' => $f, 'payload' => cash_control_payload($f, '7', '2026-01-02', true, 'cash-race-b')],
    ]);
    assert_same(1, count(array_filter($results, fn(array $r): bool => $r['id'] > 0)));
    assert_same('3.0000', pl_cash_payment_preview($f['actor_id'], $f['company_id'], $f['book_id'], $f['accounts']['1000'], '2026-01-02', '0')['balance']);
});

test('bank facility changes preserve history and retries while preventing worsened excess borrowing', function (): void {
    $f = cash_control_fixture('bank'); cash_control_set_overdraft($f, '100');
    $paid = cash_control_post($f, '100', '2026-01-01', true, 'facility-paid');
    cash_control_set_overdraft($f, '50');
    assert_same($paid['id'], cash_control_post($f, '100', '2026-01-01', true, 'facility-paid')['id']);
    cash_control_post($f, '25', '2026-01-02'); // Improves an existing excess without rewriting it.
    assert_throws(fn() => cash_control_post($f, '1', '2026-01-03', true), DomainException::class, 'permitted floor of -50.0000');
    cash_control_set_overdraft($f, null);
    cash_control_post($f, '75', '2026-01-03');
    assert_throws(fn() => cash_control_post($f, '1', '2026-01-04', true), DomainException::class, 'permitted floor of 0.0000');
});

test('bank backdating and atomic corrections respect subsequent facility floors', function (): void {
    $f = cash_control_fixture('bank'); cash_control_set_overdraft($f, '10');
    $input = core_general_input($f); $input['date'] = '2026-01-01'; $input['lines'] = cash_control_payload($f, '100', '2026-01-01')['lines'];
    $source = pl_save_general_draft($f['actor_id'], $f['company_id'], $f['book_id'], $input);
    $source = pl_post_general_draft($f['actor_id'], $f['company_id'], $f['book_id'], $source['id'], $source['revision']);
    cash_control_post($f, '110', '2026-01-03', true);
    assert_throws(fn() => cash_control_post($f, '1', '2026-01-02', true), DomainException::class, '2026-01-03');
    assert_throws(fn() => pl_reverse_journal($f['actor_id'], $f['company_id'], $f['book_id'], $source['journal_id'], '2026-01-01', 'bank-funding-reverse', 'Reverse funding'), DomainException::class);
    $input['description'] = 'Reviewed bank funding';
    $corrected = pl_correct_source($f['actor_id'], $f['company_id'], $f['book_id'], 'general_journal', $source['id'], $source['revision'], $input, '2026-01-01', 'bank-funding-correction', 'Fix description');
    $count = (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id=%i', $f['book_id']);
    $input['lines'] = cash_control_payload($f, '99', '2026-01-01')['lines'];
    assert_throws(fn() => pl_correct_source($f['actor_id'], $f['company_id'], $f['book_id'], 'general_journal', $source['id'], $corrected['revision'], $input, '2026-01-01', 'bank-funding-too-low', 'Reduce funding'), DomainException::class, 'permitted floor');
    assert_same($count, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id=%i', $f['book_id']));
});

test('foreign bank facilities are in designated units and cannot subsidize other currency buckets', function (): void {
    $f = cash_control_fixture('bank');
    DB::update('pl_accounts', ['currency' => 'EUR'], 'id=%i', $f['accounts']['1000']);
    cash_control_set_overdraft($f, '10');
    $post = fn(array $p): array => pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], $p);
    $post(cash_control_fx_payload($f, '100', '2', '2026-01-01'));
    $post(cash_control_fx_payload($f, '110', '3', '2026-01-02', true)); // EUR -10 allowed despite USD -130 carrying value.
    assert_throws(fn() => $post(cash_control_fx_payload($f, '0.0001', '1', '2026-01-03', true)), DomainException::class, 'permitted floor of -10.0000');
    $neutral = cash_control_fixture('bank'); cash_control_set_overdraft($neutral, '100');
    pl_post_journal($neutral['actor_id'], $neutral['company_id'], $neutral['book_id'], cash_control_fx_payload($neutral, '10', '2', '2026-01-01'));
    assert_throws(fn() => pl_post_journal($neutral['actor_id'], $neutral['company_id'], $neutral['book_id'], cash_control_fx_payload($neutral, '11', '1', '2026-01-02', true)), DomainException::class, '(EUR)');
    cash_control_post($neutral, '100', '2026-01-02', true); // Only the book-currency facility is usable.
});

test('bank facilities require exact positive limits and preserve omitted configuration with audit', function (): void {
    $f = cash_control_fixture('bank');
    assert_throws(fn() => cash_control_set_overdraft($f, '0'), DomainException::class, 'positive agreed limit');
    $account = cash_control_set_overdraft($f, '123.4567');
    $input = $account; unset($input['overdraft_enabled'], $input['overdraft_limit']); $input['reason'] = 'Rename without changing facility'; $input['name'] = 'Reviewed bank';
    $updated = pl_save_account($f['actor_id'], $f['company_id'], $f['book_id'], $input, $account['id'], $account['revision']);
    assert_same(true, $updated['overdraft_enabled']); assert_same('123.4567', $updated['overdraft_limit']);
    $input['overdraft_limit'] = 1.2;
    assert_throws(fn() => pl_save_account($f['actor_id'], $f['company_id'], $f['book_id'], $input, $account['id'], $updated['revision']), TypeError::class);
    $physical = cash_control_fixture(); assert_throws(fn() => cash_control_set_overdraft($physical, '100'), DomainException::class, 'bank account');
    assert_throws(fn() => DB::update('pl_accounts', ['overdraft_enabled' => 1, 'overdraft_limit' => '100'], 'id=%i', $physical['accounts']['1000']));
    $account = cash_control_set_overdraft($f, null);
    assert_same(false, $account['overdraft_enabled']); assert_same('0.0000', $account['overdraft_limit']);
    $create = ['code' => 'BANK-FACILITY', 'name' => 'Fictional agreed bank facility', 'type' => 'asset', 'role' => 'cash_bank', 'money_kind' => 'bank',
        'overdraft_enabled' => true, 'overdraft_limit' => '20', 'is_active' => true, 'reason' => 'Agreed sample facility', 'creation_key' => 'bank-facility-create'];
    $created = pl_save_account($f['actor_id'], $f['company_id'], $f['book_id'], $create);
    assert_same($created['id'], pl_save_account($f['actor_id'], $f['company_id'], $f['book_id'], $create)['id']);
    $create['overdraft_limit'] = '21';
    assert_throws(fn() => pl_save_account($f['actor_id'], $f['company_id'], $f['book_id'], $create), DomainException::class, 'different account');
});

test('simultaneous bank withdrawals cannot both consume the same agreed facility', function (): void {
    $f = cash_control_fixture('bank'); cash_control_set_overdraft($f, '10');
    $results = ledger_race([
        ['mode' => 'cash_post', 'fixture' => $f, 'payload' => cash_control_payload($f, '7', '2026-01-02', true, 'bank-race-a')],
        ['mode' => 'cash_post', 'fixture' => $f, 'payload' => cash_control_payload($f, '7', '2026-01-02', true, 'bank-race-b')],
    ]);
    assert_same(1, count(array_filter($results, fn(array $r): bool => $r['id'] > 0)));
});
