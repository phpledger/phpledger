<?php
declare(strict_types=1);

function opening_fixture(): array
{
    $f = ledger_fixture('USD', '2026-09-01');
    DB::update('pl_companies', ['setup_status' => 'opening_required'], 'id = %i', $f['company_id']);
    return $f;
}

function opening_input(): array
{
    return ['cutover_date' => '2026-09-01', 'source' => 'Sample reviewed cutover', 'balances' => [
        ['account_code' => '1000', 'debit' => '1000', 'credit' => '0'],
        ['account_code' => '1100', 'debit' => '300', 'credit' => '0'],
        ['account_code' => '2000', 'debit' => '0', 'credit' => '200'],
        ['account_code' => '3000', 'debit' => '0', 'credit' => '1100'],
    ], 'unpaid_documents' => [
        ['kind' => 'receivable', 'account_code' => '1100', 'party' => 'Sample customer', 'reference' => 'INV-01', 'document_date' => '2026-08-15', 'due_date' => '2026-09-15', 'outstanding' => '300'],
        ['kind' => 'payable', 'account_code' => '2000', 'party' => 'Sample supplier', 'reference' => 'BILL-01', 'document_date' => '2026-08-20', 'due_date' => '2026-09-20', 'outstanding' => '200'],
    ]];
}

function opening_preview(array $f, ?array $input = null): array
{
    return pl_preview_opening($f['actor_id'], $f['company_id'], $f['book_id'], $input ?? opening_input(), bin2hex(random_bytes(16)));
}

function opening_confirm(array $f, array $preview): array
{
    return pl_confirm_opening($f['actor_id'], $f['company_id'], $f['book_id'], (int) $preview['id'], $preview['payload_hash'], true);
}

test('opening restart preserves saved general journals from the merged core workflow', function (): void {
    $f = opening_fixture();
    $cutover = opening_confirm($f, opening_preview($f));
    pl_save_general_draft($f['actor_id'], $f['company_id'], $f['book_id'], [
        'date' => '2026-09-02', 'reference' => 'Merged core draft', 'description' => 'Sample journal',
        'creation_key' => bin2hex(random_bytes(16)),
        'lines' => [
            ['account_id' => $f['accounts']['1000'], 'debit' => '10', 'credit' => '0', 'description' => 'Cash'],
            ['account_id' => $f['accounts']['4000'], 'debit' => '0', 'credit' => '10', 'description' => 'Income'],
        ],
    ]);
    assert_throws(fn() => pl_reverse_opening($f['actor_id'], $f['company_id'], $f['book_id'], (int) $cutover['id'], 'Correct reviewed opening'), DomainException::class, 'business activity');
    assert_same('ready', pl_company_context($f['actor_id'], $f['company_id'])['setup_status']);
});

test('opening preview preserves readiness and confirmation posts reconciled controls exactly once', function (): void {
    $f = opening_fixture();
    $preview = opening_preview($f);
    assert_same('opening_required', pl_company_context($f['actor_id'], $f['company_id'])['setup_status']);
    assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id = %i', $f['book_id']));
    assert_same('1300.0000', $preview['payload']['total_debit']);
    $cutover = opening_confirm($f, $preview);
    assert_same($cutover['id'], opening_confirm($f, $preview)['id']);
    assert_same(1, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id = %i', $f['book_id']));
    assert_same(2, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_opening_documents WHERE book_id = %i', $f['book_id']));
    assert_same('1300.0000', pl_trial_balance($f['actor_id'], $f['company_id'], $f['book_id'])['total_debit']);
    assert_same('1000.0000', pl_cash_balance($f['actor_id'], $f['company_id'], $f['book_id'], '2026-09-01'));
    assert_same('ready', pl_company_context($f['actor_id'], $f['company_id'])['setup_status']);
});

test('opening refuses unbalanced, unreconciled, duplicated, guessed and future document data', function (): void {
    $f = opening_fixture();
    $bad = opening_input(); $bad['balances'][0]['debit'] = '1001';
    assert_throws(fn() => opening_preview($f, $bad), DomainException::class, 'do not balance');
    $bad = opening_input(); $bad['unpaid_documents'][0]['outstanding'] = '299.9999';
    assert_throws(fn() => opening_preview($f, $bad), DomainException::class, 'must exactly equal');
    $bad = opening_input(); $bad['unpaid_documents'][] = $bad['unpaid_documents'][0];
    assert_throws(fn() => opening_preview($f, $bad), DomainException::class, 'Duplicate');
    $bad = opening_input(); $bad['balances'][0]['debit'] = '1,000';
    assert_throws(fn() => opening_preview($f, $bad), DomainException::class);
    $bad = opening_input(); $bad['unpaid_documents'][0]['document_date'] = '2026-09-02';
    assert_throws(fn() => opening_preview($f, $bad), DomainException::class, 'exist by cutover');
    $bad = opening_input(); $bad['balances'][0]['account_code'] = 'not-here';
    assert_throws(fn() => opening_preview($f, $bad), DomainException::class, 'active account');
    $bad = opening_input(); $bad['balances'][] = $bad['balances'][0];
    assert_throws(fn() => opening_preview($f, $bad), DomainException::class, 'only once');
    assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_opening_previews WHERE book_id = %i', $f['book_id']));
});

test('opening confirmation requires expected source digest, explicit consent and a current chart', function (): void {
    $f = opening_fixture(); $preview = opening_preview($f);
    assert_throws(fn() => pl_confirm_opening($f['actor_id'], $f['company_id'], $f['book_id'], (int) $preview['id'], str_repeat('0', 64), true), DomainException::class, 'identity');
    assert_throws(fn() => pl_confirm_opening($f['actor_id'], $f['company_id'], $f['book_id'], (int) $preview['id'], $preview['payload_hash'], false), DomainException::class, 'Confirm');
    DB::update('pl_accounts', ['is_active' => 0], 'id = %i', $f['accounts']['1000']);
    assert_throws(fn() => opening_confirm($f, $preview), DomainException::class, 'active account');
    assert_same('opening_required', pl_company_context($f['actor_id'], $f['company_id'])['setup_status']);
});

test('opening preview idempotency rejects changed data and competing confirmations cannot double count', function (): void {
    $f = opening_fixture(); $key = bin2hex(random_bytes(12));
    $p = pl_preview_opening($f['actor_id'], $f['company_id'], $f['book_id'], opening_input(), $key);
    assert_same($p['id'], pl_preview_opening($f['actor_id'], $f['company_id'], $f['book_id'], opening_input(), $key)['id']);
    $changed = opening_input(); $changed['source'] = 'Different source';
    assert_throws(fn() => pl_preview_opening($f['actor_id'], $f['company_id'], $f['book_id'], $changed, $key), DomainException::class, 'different data');
    $other = opening_preview($f, $changed);
    opening_confirm($f, $p);
    assert_throws(fn() => opening_confirm($f, $other), DomainException::class, 'already completed');
});

test('opening zero cutover has a durable receipt and requires an open period without a fake journal', function (): void {
    $f = opening_fixture();
    $input = ['cutover_date' => '2026-09-01', 'source' => 'Sample zero confirmation', 'balances' => [], 'unpaid_documents' => []];
    assert_throws(fn() => opening_preview($f, $input), DomainException::class, 'Explicitly confirm');
    $input['zero_confirmed'] = true;
    $p = opening_preview($f, $input);
    DB::update('pl_periods', ['status' => 'closed'], 'id = %i', $f['period_id']);
    assert_throws(fn() => opening_confirm($f, $p), DomainException::class, 'open accounting period');
    DB::update('pl_periods', ['status' => 'open'], 'id = %i', $f['period_id']);
    $c = opening_confirm($f, $p);
    assert_same(null, $c['journal_id']);
    assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id = %i', $f['book_id']));
    pl_reverse_opening($f['actor_id'], $f['company_id'], $f['book_id'], (int) $c['id'], 'Sample restart');
    assert_same('opening_required', pl_company_context($f['actor_id'], $f['company_id'])['setup_status']);
});

test('opening access is scoped and readonly viewers cannot prepare or confirm', function (): void {
    $f = opening_fixture(); $other = opening_fixture(); $p = opening_preview($f);
    assert_throws(fn() => pl_get_opening_preview($other['actor_id'], $other['company_id'], $other['book_id'], (int) $p['id']), DomainException::class, 'not available');
    sample_membership_insert(['company_id' => $f['company_id'], 'user_id' => $other['actor_id'], 'role' => 'viewer']);
    assert_same($p['id'], pl_get_opening_preview($other['actor_id'], $f['company_id'], $f['book_id'], (int) $p['id'])['id']);
    assert_throws(fn() => pl_confirm_opening($other['actor_id'], $f['company_id'], $f['book_id'], (int) $p['id'], $p['payload_hash'], true), DomainException::class);
    assert_throws(fn() => pl_preview_opening($other['actor_id'], $f['company_id'], $f['book_id'], opening_input(), 'viewer'), DomainException::class);
});

test('opening forbids pre-cutover posting and generic opening reversal but supports linked restart', function (): void {
    $f = opening_fixture(); $p = opening_preview($f); $c = opening_confirm($f, $p);
    $entry = ledger_payload($f); $entry['date'] = '2026-09-01';
    assert_throws(fn() => pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], $entry), DomainException::class, 'after');
    assert_throws(fn() => pl_reverse_journal($f['actor_id'], $f['company_id'], $f['book_id'], (int) $c['journal_id'], '2026-09-02', 'generic', 'Sample'), DomainException::class, 'cutover correction');
    $reversed = pl_reverse_opening($f['actor_id'], $f['company_id'], $f['book_id'], (int) $c['id'], 'Correct sample source');
    assert_true($reversed['reversed_journal_id'] !== null);
    assert_same($reversed['id'], pl_reverse_opening($f['actor_id'], $f['company_id'], $f['book_id'], (int) $c['id'], 'Correct sample source')['id']);
    assert_same('0.0000', pl_trial_balance($f['actor_id'], $f['company_id'], $f['book_id'])['total_debit']);
    $new = opening_preview($f); opening_confirm($f, $new);
    assert_same('1300.0000', pl_trial_balance($f['actor_id'], $f['company_id'], $f['book_id'])['total_debit']);
    $entry['date'] = '2026-09-02'; pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], $entry);
    $active = pl_opening_history($f['actor_id'], $f['company_id'], $f['book_id'])[0];
    assert_throws(fn() => pl_reverse_opening($f['actor_id'], $f['company_id'], $f['book_id'], (int) $active['id'], 'Too late'), DomainException::class, 'business activity');
});

test('opening posting failure rolls back readiness, receipt, journal and unpaid register', function (): void {
    $f = opening_fixture(); $p = opening_preview($f);
    DB::query("CREATE TRIGGER pl_opening_test_fail BEFORE INSERT ON pl_opening_documents FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sample cutover failure'");
    try {
        assert_throws(fn() => opening_confirm($f, $p));
    } finally {
        DB::query('DROP TRIGGER pl_opening_test_fail');
    }
    assert_same('opening_required', pl_company_context($f['actor_id'], $f['company_id'])['setup_status']);
    assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id = %i', $f['book_id']));
    assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_opening_cutovers WHERE book_id = %i', $f['book_id']));
});

test('opening immutable previews and document records cannot be overwritten', function (): void {
    $f = opening_fixture(); $p = opening_preview($f); $c = opening_confirm($f, $p);
    assert_throws(fn() => DB::update('pl_opening_previews', ['payload_hash' => str_repeat('f', 64)], 'id = %i', $p['id']));
    assert_throws(fn() => DB::delete('pl_opening_previews', 'id = %i', $p['id']));
    assert_throws(fn() => DB::update('pl_opening_documents', ['outstanding' => '1'], 'cutover_id = %i', $c['id']));
    assert_throws(fn() => DB::delete('pl_opening_cutovers', 'id = %i', $c['id']));
});

test('opening CSV handles quoted commas and refuses wrong headers, malformed widths and oversize input', function (): void {
    $rows = pl_opening_csv("account_code,debit,credit\r\n1000,1.2345,0\r\n3000,0,1.2345\r\n", ['account_code', 'debit', 'credit']);
    assert_same('1.2345', $rows[0]['debit']);
    $rows = pl_opening_csv("party,reference\n\"Sample, Shop\",A1\n", ['party', 'reference']);
    assert_same('Sample, Shop', $rows[0]['party']);
    assert_throws(fn() => pl_opening_csv("code,debit,credit\n1000,1,0", ['account_code', 'debit', 'credit']), DomainException::class, 'columns');
    assert_throws(fn() => pl_opening_csv("a,b\n1,2,3", ['a', 'b']), DomainException::class, 'same columns');
    assert_throws(fn() => pl_opening_csv(str_repeat('x', 524289), ['a']), DomainException::class, '512 KiB');
    assert_throws(fn() => pl_opening_csv("account_code,debit,credit\n1000,1,\"0", ['account_code', 'debit', 'credit']), DomainException::class, 'unterminated');
});

test('simultaneous opening confirmations share one durable journal and control register', function (): void {
    $f = opening_fixture(); $p = opening_preview($f);
    $job = ['mode' => 'opening_confirm', 'fixture' => $f, 'preview_id' => (int) $p['id'], 'hash' => $p['payload_hash']];
    $results = ledger_race([$job, $job]);
    assert_same($results[0]['id'], $results[1]['id']);
    assert_same(1, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id = %i', $f['book_id']));
    assert_same(2, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_opening_documents WHERE book_id = %i', $f['book_id']));
});

test('opening cutover date gate uses current state despite an older caller snapshot', function (): void {
    $f = opening_fixture();
    $p = opening_preview($f, ['cutover_date' => '2026-09-01', 'source' => 'Sample old snapshot', 'balances' => [], 'zero_confirmed' => true]);
    DB::startTransaction();
    try {
        assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_opening_cutovers WHERE book_id = %i', $f['book_id']));
        ledger_race([['mode' => 'opening_confirm', 'fixture' => $f, 'preview_id' => (int) $p['id'], 'hash' => $p['payload_hash']]]);
        assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_opening_cutovers WHERE book_id = %i', $f['book_id']));
        $payload = ledger_payload($f); $payload['date'] = '2026-09-01';
        assert_throws(fn() => pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], $payload), DomainException::class, 'after');
    } finally {
        if (DB::transactionDepth() > 0) { DB::rollback(); }
    }
});
