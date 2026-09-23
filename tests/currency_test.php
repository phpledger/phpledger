<?php
declare(strict_types=1);

function currency_rate_fixture(array $f, string $rate = '1.234567890123', string $date = '2026-09-10'): array
{
    return pl_currency_rate_enter($f['actor_id'], $f['company_id'], $f['book_id'], ['from_currency' => 'EUR', 'to_currency' => 'USD', 'rate_date' => $date, 'rate' => $rate, 'source' => 'Sample manual quote', 'note' => 'Sample rate evidence', 'idempotency_key' => bin2hex(random_bytes(16))]);
}

test('currency arithmetic uses twelve-place rates and explicit half-up without floats', function (): void {
    assert_same('1.234567890123', pl_fx_rate('1.234567890123'));
    assert_same('1.0001', pl_fx_convert('1', '1.00005'));
    assert_same('1.0000', pl_fx_convert('1', '1.000049999999'));
    assert_same('559000.0000', pl_fx_convert('2000', '279.50'));
    assert_same('AED', pl_currency_code('AED'));
    foreach (['0','-1','1e4','1.1234567890123','NaN'] as $rate) { assert_throws(fn() => pl_fx_rate($rate), DomainException::class); }
    assert_throws(fn() => pl_fx_rate(1.2), TypeError::class);
    assert_throws(fn() => pl_currency_code('ABC'), DomainException::class);
    assert_throws(fn() => pl_fx_convert('9999999999999999.9999', '2'), DomainException::class);
});

test('domestic snapshots, immutable functional currency and unknown monetary classification', function (): void {
    $f = ledger_fixture();
    $j = pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], ledger_payload($f));
    foreach ($j['lines'] as $line) {
        assert_same('USD', $line['currency']); assert_same('12.3400', $line['amount_fc']);
        assert_same('12.3400', $line['amount_base']); assert_same('1.000000000000', $line['rate']);
        assert_same(false, $line['rate_is_stale']); assert_same(null, $line['rate_source_id']);
    }
    assert_throws(fn() => DB::update('pl_books', ['functional_currency' => 'EUR'], 'id = %i', $f['book_id']), Throwable::class, 'immutable');
    assert_throws(fn() => DB::update('pl_companies', ['functional_currency' => 'EUR','currency' => 'EUR'], 'id = %i', $f['company_id']), Throwable::class, 'immutable');
    assert_same(null, pl_currency_account_properties(['role' => null])['is_monetary']);
    assert_same(true, pl_currency_account_properties(['role' => 'cash_bank'])['is_monetary']);
    assert_same(false, pl_currency_account_properties(['role' => 'owner_equity'])['is_monetary']);
});

test('manual rates append revisions, select prior dates, enforce scope and preserve provenance', function (): void {
    $f = ledger_fixture(); $other = ledger_fixture();
    $input = ['from_currency' => 'EUR','to_currency' => 'USD','rate_date' => '2026-09-10','rate' => '1.25','source' => 'Sample quote','note' => 'Sample evidence','idempotency_key' => bin2hex(random_bytes(16))];
    $a = pl_currency_rate_enter($f['actor_id'], $f['company_id'], $f['book_id'], $input);
    $retry = pl_currency_rate_enter($f['actor_id'], $f['company_id'], $f['book_id'], $input);
    assert_same((string) $a['id'], (string) $retry['id']);
    $input['rate'] = '1.26';
    assert_throws(fn() => pl_currency_rate_enter($f['actor_id'], $f['company_id'], $f['book_id'], $input), DomainException::class);
    $input['idempotency_key'] = bin2hex(random_bytes(16));
    assert_throws(fn() => pl_currency_rate_enter($f['actor_id'], $f['company_id'], $f['book_id'], $input), DomainException::class, 'supersede');
    $input['supersedes_id'] = (int) $a['id'];
    $b = pl_currency_rate_enter($f['actor_id'], $f['company_id'], $f['book_id'], $input);
    assert_same(2, (int) $b['revision']);
    assert_throws(fn() => DB::update('pl_currency_rates', ['rate' => '2'], 'id = %i', $a['id']), Throwable::class, 'append-only');
    currency_rate_fixture($f, '9', '2026-09-20');
    $selected = pl_currency_rate_lookup($f['actor_id'], $f['company_id'], $f['book_id'], 'EUR','USD','2026-09-14','spot','Sample quote');
    assert_same((string) $b['id'], (string) $selected['id']); assert_same(true, $selected['rate_is_stale']);
    assert_same(null, pl_currency_rate_lookup($f['actor_id'], $f['company_id'], $f['book_id'], 'EUR','USD','2026-09-01','spot','Sample quote'));
    assert_throws(fn() => pl_currency_rate_lookup($other['actor_id'], $f['company_id'], $f['book_id'], 'EUR','USD','2026-09-14','spot','Sample quote'), DomainException::class);
});

test('foreign snapshots validate accounts and conversion and reverse frozen original rate metadata', function (): void {
    $f = ledger_fixture(); $r = currency_rate_fixture($f, '1.25', '2026-09-14');
    DB::update('pl_accounts', ['currency' => 'EUR'], 'id = %i', $f['accounts']['1000']);
    $payload = ledger_payload($f, '12.5000');
    $payload['lines'][0] += ['currency' => 'EUR','amount_fc' => '10','rate' => '1.25','rate_source_id' => (int) $r['id']];
    $j = pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], $payload);
    assert_same('10.0000', $j['lines'][0]['amount_fc']);
    // The fixture owner may reverse on the original open-period date. A fixed
    // later date becomes an invalid backdate when the wall clock advances.
    $reverse = pl_reverse_journal($f['actor_id'], $f['company_id'], $f['book_id'], $j['id'], $payload['date'], bin2hex(random_bytes(16)), 'Sample reversal');
    assert_same($j['lines'][0]['rate'], $reverse['lines'][0]['rate']);
    assert_same(false, $reverse['lines'][0]['rate_is_stale']);
    assert_same('12.5000', $reverse['lines'][0]['credit']);
    $payload['idempotency_key'] = bin2hex(random_bytes(16)); $payload['lines'][0]['amount_fc'] = '11';
    assert_throws(fn() => pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], $payload), DomainException::class, 'converted');
    assert_throws(fn() => pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], ledger_payload($f)), DomainException::class, 'designated');
});

test('legacy canonical hash remains reproducible only for unchanged domestic requests', function (): void {
    $f = ledger_fixture(); $raw = ledger_payload($f);
    $normalized = pl_normalize_journal($raw); $legacy = pl_currency_legacy_payload($normalized);
    foreach ($legacy['lines'] as $line) { assert_same(['account_id','debit','credit','description'], array_keys($line)); }
    $changed = $normalized; $changed['lines'][0]['ic_counterparty_entity_id'] = 999;
    assert_same(null, pl_currency_legacy_payload($changed));
});

test('account currency changes see postings committed after the caller snapshot', function (): void {
    $f = ledger_fixture(); $accountId = $f['accounts']['1000'];
    $account = pl_get_account($f['actor_id'], $f['company_id'], $f['book_id'], $accountId);
    $input = ['name'=>$account['name'], 'code'=>$account['code'], 'type'=>$account['type'], 'role'=>$account['role'], 'is_active'=>true,
        'currency'=>'EUR', 'overdraft_enabled'=>true, 'overdraft_limit'=>$account['overdraft_limit'],
        'reason'=>'Sample stale snapshot currency change with explicitly confirmed facility terms'];
    DB::startTransaction();
    try {
        assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journal_lines WHERE account_id=%i', $accountId));
        $posted = ledger_race([['mode'=>'post', 'fixture'=>$f, 'payload'=>ledger_payload($f)]]);
        assert_true($posted[0]['id'] > 0);
        assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journal_lines WHERE account_id=%i', $accountId));
        assert_throws(fn() => pl_save_account($f['actor_id'], $f['company_id'], $f['book_id'], $input, $accountId, $account['revision']), DomainException::class, 'fixed once');
    } finally { DB::rollback(); }
    assert_same($account['currency'], pl_get_account($f['actor_id'], $f['company_id'], $f['book_id'], $accountId)['currency']);
});
