<?php
declare(strict_types=1);

function ledger_fixture(string $currency = 'USD', string $date = '2026-01-01', string $fiscalEnd = '12-31'): array
{
    $suffix = bin2hex(random_bytes(8));
    $actorId = pl_create_user('ledger-' . $suffix . '@example.test', 'Sample ledger tester', 'Sample-test-password-' . $suffix);
    return ['actor_id' => $actorId] + pl_create_company($actorId, 'Sample company ' . $suffix, $currency, $date, $fiscalEnd);
}

function ledger_payload(array $fixture, string $amount = '12.3400', ?string $key = null): array
{
    return [
        'date' => '2026-09-14', 'currency' => 'USD', 'source_type' => 'receipt',
        'source_reference' => 'sample-receipt', 'description' => 'Sample service receipt',
        'idempotency_key' => $key ?? bin2hex(random_bytes(16)),
        'lines' => [
            ['account_id' => $fixture['accounts']['1000'], 'debit' => $amount, 'credit' => '0'],
            ['account_id' => $fixture['accounts']['4000'], 'debit' => '0', 'credit' => $amount],
        ],
    ];
}

test('financial amounts preserve four decimals without float conversion', function (): void {
    assert_same('0.0000', pl_amount('0'));
    assert_same('0.1000', pl_amount('0.1'));
    assert_same('9999999999999999.9999', pl_amount('9999999999999999.9999'));
    foreach (['-1', '+1', '01.00', '.1', '1.', '1.00001', '1,000', '1e3', ' 1', '10000000000000000', 'NaN'] as $bad) {
        assert_throws(fn() => pl_amount($bad), DomainException::class);
    }
    assert_throws(fn() => pl_amount(0.1), TypeError::class);
});

test('a cash tender is held to whole minor units while the ledger keeps four places', function (): void {
    assert_same('0.0000', pl_cash_amount('0'));
    assert_same('20.0000', pl_cash_amount('20'));
    assert_same('20.0000', pl_cash_amount('20.00'));
    // A canonical retry re-submits the stored four-place string; its value is still whole cash.
    assert_same('20.0000', pl_cash_amount('20.0000'));
    assert_same('1262.2500', pl_cash_amount('1262.25'));
    assert_same('9999999999999999.9900', pl_cash_amount('9999999999999999.99'));
    foreach (['1262.2555', '0.001', '0.0001', '12.7499', '0.9999', '1.005'] as $finer) {
        assert_throws(fn() => pl_cash_amount($finer), DomainException::class, 'whole notes and coins');
    }
    foreach (['-1', '+1', '01.00', '.1', '1.', '1,000', '1e3', ' 1', '10000000000000000', 'NaN'] as $bad) {
        assert_throws(fn() => pl_cash_amount($bad), DomainException::class);
    }
    assert_throws(fn() => pl_cash_amount(0.1), TypeError::class);
});

test('company setup creates one book, an owner, an annual period and scoped template', function (): void {
    $fixture = ledger_fixture('PKR', '2026-09-14', '06-30');
    $period = DB::queryFirstRow('SELECT start_date, end_date FROM pl_periods WHERE id = %i', $fixture['period_id']);
    assert_same('2026-09-14', $period['start_date']);
    assert_same('2027-06-30', $period['end_date']);
    // The starter chart is numbered X-XXX-XXXXX-XX and each account is also reachable by the
    // number it carried before the conversion (B56), so the fixture map holds both keys. A book is
    // also born with the chart's nineteen class and group headings: names for the report tree,
    // which take no posting and carry no purpose.
    assert_same(13 + 19, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_accounts WHERE company_id = %i', $fixture['company_id']));
    assert_same(13, (int) DB::queryFirstField("SELECT COUNT(*) FROM pl_accounts WHERE company_id = %i AND code NOT LIKE '_-___-00000-__'", $fixture['company_id']));
    assert_same(13 + 19 + 6, count($fixture['accounts']));
    assert_same($fixture['accounts']['1000'], $fixture['accounts']['1-100-10001-00']);
    assert_same('owner', pl_require_company_access($fixture['actor_id'], $fixture['company_id'], true)['role']);
    assert_same(1, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_books WHERE company_id = %i', $fixture['company_id']));
    assert_throws(fn() => pl_create_company($fixture['actor_id'], 'Bad currency', 'ABC', '2026-01-01'), DomainException::class);
    assert_throws(fn() => pl_create_company($fixture['actor_id'], 'Bad date', 'USD', '2026-02-30'), DomainException::class);
    assert_throws(fn() => pl_create_company($fixture['actor_id'], 'Bad fiscal end', 'USD', '2026-01-01', '02-29'), DomainException::class);
    assert_throws(fn() => pl_create_company(0, 'Missing actor', 'USD', '2026-01-01'), DomainException::class);
});

test('setup exposes safe entity and year-end choices without changing the accounting contract', function (): void {
    assert_same('30 June — common Pakistan year end', pl_fiscal_year_end_label('06-30'));
    assert_same('31 December — calendar year', pl_fiscal_year_end_label('12-31'));
    assert_same('02-28 — custom year end', pl_fiscal_year_end_label('02-28'));
    assert_same('AOP / partnership / association', pl_setup_entity_type_options()['aop']);
    assert_true(array_key_exists('custom', pl_fiscal_year_end_options()));
    assert_throws(fn() => pl_create_company(ledger_fixture()['actor_id'], 'Invalid fiscal end', 'USD', '2026-01-01', '02-29'), DomainException::class);
});

test('balanced receipt posts and report figures drill down to exact source lines', function (): void {
    $fixture = ledger_fixture();
    $payload = ledger_payload($fixture, '9999999999999999.9999');
    $journal = pl_post_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $payload);
    assert_same(2, count($journal['lines']));
    assert_same('sample-receipt', $journal['source_reference']);
    assert_same('9999999999999999.9999', $journal['lines'][0]['debit']);
    $report = pl_trial_balance($fixture['actor_id'], $fixture['company_id'], $fixture['book_id']);
    assert_true($report['balanced']);
    assert_same('9999999999999999.9999', $report['total_debit']);
    assert_same($report['total_debit'], $report['total_credit']);
    $before = pl_trial_balance($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], '2026-09-13');
    assert_same('0.0000', $before['total_debit']);
    assert_same($journal['id'], pl_get_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $journal['id'])['id']);
});

test('unbalanced, zero-sided and malformed posting requests do not write journals', function (): void {
    $fixture = ledger_fixture();
    $payload = ledger_payload($fixture);
    $payload['lines'][1]['credit'] = '12.3399';
    assert_throws(fn() => pl_post_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $payload), DomainException::class, 'balance');
    $payload = ledger_payload($fixture);
    $payload['lines'][0]['credit'] = '1';
    assert_throws(fn() => pl_post_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $payload), DomainException::class);
    $payload = ledger_payload($fixture, '0');
    assert_throws(fn() => pl_post_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $payload), DomainException::class);
    $payload = ledger_payload($fixture);
    $payload['lines'][0]['debit'] = 12.34;
    assert_throws(fn() => pl_post_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $payload), DomainException::class);
    assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id = %i', $fixture['book_id']));
});

test('idempotency returns original for equivalent precision and rejects changed payload', function (): void {
    $fixture = ledger_fixture();
    $payload = ledger_payload($fixture, '12.34');
    $journal = pl_post_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $payload);
    $payload['lines'][0]['debit'] = '12.3400';
    $payload['lines'][1]['credit'] = '12.3400';
    assert_same($journal['id'], pl_post_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $payload)['id']);
    $payload['description'] = 'Different meaning';
    assert_throws(fn() => pl_post_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $payload), DomainException::class, 'different journal');
    assert_same(1, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id = %i', $fixture['book_id']));
});

test('cross-company accounts, books, journals and reporting are denied', function (): void {
    $first = ledger_fixture();
    $other = ledger_fixture();
    $payload = ledger_payload($first);
    $payload['lines'][1]['account_id'] = $other['accounts']['4000'];
    assert_throws(fn() => pl_post_journal($first['actor_id'], $first['company_id'], $first['book_id'], $payload), DomainException::class, 'Every account');
    assert_throws(fn() => pl_post_journal($first['actor_id'], $first['company_id'], $other['book_id'], ledger_payload($first)), DomainException::class, 'book');
    assert_throws(fn() => pl_trial_balance($first['actor_id'], $other['company_id'], $other['book_id']), DomainException::class);
    $journal = pl_post_journal($other['actor_id'], $other['company_id'], $other['book_id'], ledger_payload($other));
    assert_throws(fn() => pl_get_journal($first['actor_id'], $first['company_id'], $first['book_id'], $journal['id']), DomainException::class);
    assert_throws(fn() => pl_reverse_journal($first['actor_id'], $first['company_id'], $first['book_id'], $journal['id'], '2026-09-15', 'wrong-scope', 'Scope test'), DomainException::class);
});

test('viewers can report but cannot post or reverse; inactive actors cannot read', function (): void {
    $fixture = ledger_fixture();
    $viewer = ledger_fixture();
    DB::insert('pl_company_members', ['company_id' => $fixture['company_id'], 'user_id' => $viewer['actor_id'], 'role' => 'viewer']);
    assert_true(pl_trial_balance($viewer['actor_id'], $fixture['company_id'], $fixture['book_id'])['balanced']);
    $journal = pl_post_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], ledger_payload($fixture));
    assert_throws(fn() => pl_post_journal($viewer['actor_id'], $fixture['company_id'], $fixture['book_id'], ledger_payload($fixture)), DomainException::class);
    assert_throws(fn() => pl_reverse_journal($viewer['actor_id'], $fixture['company_id'], $fixture['book_id'], $journal['id'], '2026-09-15', 'viewer-reversal', 'Role test'), DomainException::class);
    DB::update('pl_users', ['is_active' => 0], 'id = %i', $viewer['actor_id']);
    assert_throws(fn() => pl_trial_balance($viewer['actor_id'], $fixture['company_id'], $fixture['book_id']), DomainException::class);
});

test('period boundaries are inclusive, closed periods reject new posts, and retries remain stable', function (): void {
    $fixture = ledger_fixture();
    $payload = ledger_payload($fixture);
    $payload['date'] = '2026-01-01';
    $first = pl_post_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $payload);
    $lastPayload = ledger_payload($fixture);
    $lastPayload['date'] = '2026-12-31';
    pl_post_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $lastPayload);
    $outside = ledger_payload($fixture);
    $outside['date'] = '2027-01-01';
    assert_throws(fn() => pl_post_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $outside), DomainException::class, 'period');
    DB::update('pl_periods', ['status' => 'closed'], 'id = %i', $fixture['period_id']);
    assert_throws(fn() => pl_post_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], ledger_payload($fixture)), DomainException::class, 'period');
    assert_same($first['id'], pl_post_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $payload)['id']);
    assert_throws(fn() => pl_reverse_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $first['id'], '2026-09-15', 'closed-period-reversal', 'Closed period'), DomainException::class);
});

test('currency mismatch, inactive accounts and overlapping periods prevent posting', function (): void {
    $fixture = ledger_fixture();
    $payload = ledger_payload($fixture);
    $payload['currency'] = 'EUR';
    assert_throws(fn() => pl_post_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $payload), DomainException::class, 'currency');
    DB::update('pl_accounts', ['is_active' => 0], 'id = %i', $fixture['accounts']['4000']);
    assert_throws(fn() => pl_post_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], ledger_payload($fixture)), DomainException::class, 'active');
    DB::update('pl_accounts', ['is_active' => 1], 'id = %i', $fixture['accounts']['4000']);
    DB::insert('pl_periods', ['company_id' => $fixture['company_id'], 'book_id' => $fixture['book_id'], 'start_date' => '2026-09-01', 'end_date' => '2026-09-30']);
    assert_throws(fn() => pl_post_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], ledger_payload($fixture)), DomainException::class, 'exactly one');
});

test('reversal is linked, one-time, idempotent and preserves the original', function (): void {
    $fixture = ledger_fixture(); $today = gmdate('Y-m-d');
    $journal = pl_post_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], ledger_payload($fixture));
    $reversal = pl_reverse_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $journal['id'], $today, 'reverse-once', 'Entered twice');
    assert_same($journal['id'], (int) $reversal['reversal_of_id']);
    assert_same($journal['lines'][0]['debit'], $reversal['lines'][0]['credit']);
    assert_same($journal, pl_get_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $journal['id']));
    assert_same($reversal['id'], pl_reverse_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $journal['id'], $today, 'reverse-once', 'Entered twice')['id']);
    assert_throws(fn() => pl_reverse_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $journal['id'], $today, 'reverse-twice', 'Entered twice'), DomainException::class, 'already');
    assert_throws(fn() => pl_reverse_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $journal['id'], $today, 'reverse-once', 'Different reason'), DomainException::class, 'different journal');
    assert_throws(fn() => pl_reverse_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $reversal['id'], '2026-09-16', 'reverse-reversal', 'No'), DomainException::class);
    $report = pl_trial_balance($fixture['actor_id'], $fixture['company_id'], $fixture['book_id']);
    assert_same('0.0000', $report['total_debit']);
    assert_same('0.0000', $report['total_credit']);
    foreach ($report['accounts'] as $account) {
        assert_same('0.0000', $account['balance']);
    }
    $prior = pl_trial_balance($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], '2026-09-14');
    assert_same('12.3400', $prior['total_debit']);
});

test('database refuses edits and deletion of posted headers and lines', function (): void {
    $fixture = ledger_fixture();
    $journal = pl_post_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], ledger_payload($fixture));
    assert_throws(fn() => DB::update('pl_journals', ['description' => 'Changed'], 'id = %i', $journal['id']), Throwable::class, 'immutable');
    assert_throws(fn() => DB::delete('pl_journals', 'id = %i', $journal['id']), Throwable::class, 'immutable');
    assert_throws(fn() => DB::update('pl_journal_lines', ['debit' => '99.0000'], 'journal_id = %i AND line_number = 1', $journal['id']), Throwable::class, 'immutable');
    assert_throws(fn() => DB::delete('pl_journal_lines', 'journal_id = %i', $journal['id']), Throwable::class, 'immutable');
    assert_same($journal, pl_get_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $journal['id']));
});

test('a storage failure on the second line rolls back header and first line', function (): void {
    $fixture = ledger_fixture();
    $failureMarker = 'sample-failure-' . bin2hex(random_bytes(8));
    $trigger = 'pl_test_reject_line_' . bin2hex(random_bytes(6));
    DB::query("CREATE TRIGGER %b BEFORE INSERT ON pl_journal_lines FOR EACH ROW BEGIN IF NEW.description = %s THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sample storage failure'; END IF; END", $trigger, $failureMarker);
    try {
        $payload = ledger_payload($fixture);
        $payload['lines'][1]['description'] = $failureMarker;
        assert_throws(fn() => pl_post_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $payload), Throwable::class, 'Sample storage failure');
        assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id = %i', $fixture['book_id']));
        assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journal_lines WHERE book_id = %i', $fixture['book_id']));
        $payload['lines'][1]['description'] = 'Corrected draft';
        assert_same(2, count(pl_post_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $payload)['lines']));
    } finally {
        DB::query('DROP TRIGGER %b', $trigger);
    }
});

test('outer rollback includes company creation and nested posting transaction', function (): void {
    $fixture = ledger_fixture();
    $companyName = 'Rolled back sample company ' . bin2hex(random_bytes(8));
    assert_throws(function () use ($fixture, $companyName): void {
        pl_ledger_transaction(function () use ($fixture, $companyName): void {
            $company = pl_create_company($fixture['actor_id'], $companyName, 'USD', '2026-01-01');
            pl_post_journal($fixture['actor_id'], $company['company_id'], $company['book_id'], ledger_payload($company));
            throw new DomainException('Sample outer rollback');
        });
    }, DomainException::class, 'outer rollback');
    assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_companies WHERE name = %s', $companyName));
});

test('migration replay verifies checksums and skips the applied foundation', function (): void {
    $result = pl_migrate();
    assert_same([], $result['applied']);
    assert_true(in_array('001_foundation', $result['skipped'], true));
    assert_true(in_array('002_product_slice', $result['skipped'], true));
    assert_true(in_array('003_demo_isolation', $result['skipped'], true));
    assert_true(in_array('005_demo_period_guard', $result['skipped'], true));
});

test('a caller-owned old snapshot cannot retain revoked write permission', function (): void {
    $fixture = ledger_fixture();
    $otherConnection = new MeekroDB();
    DB::startTransaction();
    try {
        assert_same('owner', DB::queryFirstField('SELECT role FROM pl_company_members WHERE company_id = %i AND user_id = %i', $fixture['company_id'], $fixture['actor_id']));
        $otherConnection->update('pl_company_members', ['role' => 'viewer'], 'company_id = %i AND user_id = %i', $fixture['company_id'], $fixture['actor_id']);
        // The old consistent snapshot still says owner, while the service must use current authorization.
        assert_same('owner', DB::queryFirstField('SELECT role FROM pl_company_members WHERE company_id = %i AND user_id = %i', $fixture['company_id'], $fixture['actor_id']));
        assert_throws(fn() => pl_post_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], ledger_payload($fixture)), DomainException::class, 'access');
        assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id = %i', $fixture['book_id']));
    } finally {
        DB::rollback();
        $otherConnection->disconnect();
    }
});
