<?php
declare(strict_types=1);

test('structured account codes parse, format and refuse shapes that are not X-XXX-XXXXX-XX', function (): void {
    $parsed = pl_account_code_parse('1-100-10001-00');
    assert_same(['code' => '1-100-10001-00', 'class' => 1, 'group' => 100, 'account' => 10001, 'sub' => 0], $parsed);
    assert_same('1-100-10001-00', pl_account_code_format(1, 100, 10001, 0));
    assert_same('3-900-10007-05', pl_account_code_format(3, 900, 10007, 5));
    assert_same('5-000-00000-00', pl_account_code_format(5, 0, 0, 0));
    assert_same(99, pl_account_code_parse('4-999-99999-99')['sub']);
    foreach (['1000', '1-100-10001', '1-100-10001-0', '0-100-10001-00', '6-100-10001-00', '1-10-10001-00',
              '1-100-1001-00', '1-100-10001-000', 'a-100-10001-00', '1_100_10001_00', '1-000-10001-00',
              '1-100-00000-01', '', ' 1-100-10001-00'] as $bad) {
        assert_same(false, pl_account_code_is_valid($bad), 'Accepted ' . $bad);
        assert_throws(fn () => pl_account_code_parse($bad), DomainException::class);
    }
    assert_throws(fn () => pl_account_code_format(1, 1000, 1, 0), DomainException::class);
    assert_throws(fn () => pl_account_code_format(1, 100, 100000, 0), DomainException::class);
    assert_throws(fn () => pl_account_code_format(1, 100, 1, 100), DomainException::class);
});

test('a code carries its own hierarchy, level and class so no parent identifier is stored', function (): void {
    assert_same('class', pl_account_code_level('2-000-00000-00'));
    assert_same('group', pl_account_code_level('2-110-00000-00'));
    assert_same('account', pl_account_code_level('2-110-10001-00'));
    assert_same('sub_account', pl_account_code_level('2-110-10001-07'));
    assert_same([0, 1, 2, 3], array_map('pl_account_code_depth', ['2-000-00000-00', '2-110-00000-00', '2-110-10001-00', '2-110-10001-07']));
    assert_same(true, pl_account_code_is_heading('2-110-00000-00'));
    assert_same(false, pl_account_code_is_heading('2-110-10001-00'));
    assert_same(null, pl_account_code_parent('2-000-00000-00'));
    assert_same('2-000-00000-00', pl_account_code_parent('2-110-00000-00'));
    assert_same('2-110-00000-00', pl_account_code_parent('2-110-10001-00'));
    assert_same('2-110-10001-00', pl_account_code_parent('2-110-10001-07'));
    assert_same(['2-000-00000-00', '2-110-00000-00', '2-110-10001-00'], pl_account_code_ancestors('2-110-10001-07'));
    assert_same([], pl_account_code_ancestors('2-000-00000-00'));
    assert_same('2', pl_account_code_short('2-000-00000-00'));
    assert_same('2-110', pl_account_code_short('2-110-00000-00'));
    assert_same('2-110-10001-00', pl_account_code_short('2-110-10001-00'));
    assert_same('1-100-00000-00', pl_account_code_at_depth('1-100-10002-03', 1));
    foreach (['asset' => 1, 'liability' => 2, 'equity' => 3, 'income' => 4, 'expense' => 5] as $type => $class) {
        assert_same($class, pl_account_code_class_for_type($type));
        assert_same($type, pl_account_code_type_for_class($class));
        assert_same(true, pl_account_code_matches_type(pl_account_code_format($class, 100, 10001), $type));
    }
    assert_same(false, pl_account_code_matches_type('1-100-10001-00', 'expense'));
    assert_throws(fn () => pl_account_code_class_for_type('contra'), DomainException::class);
    assert_throws(fn () => pl_account_code_type_for_class(7), DomainException::class);
});

test('the contra band is reserved above every group the conversion may allocate', function (): void {
    assert_same(100, pl_account_code_first_group());
    assert_same(899, pl_account_code_last_group());
    foreach (pl_account_contra_groups() as $class => $groups) {
        foreach (array_keys($groups) as $group) {
            assert_same(true, pl_account_code_group_is_reserved($class, $group), 'Group ' . $group . ' is not reserved.');
        }
    }
    assert_same(false, pl_account_code_group_is_reserved(1, 899));
    assert_same(['Drawings'], array_values(pl_account_contra_groups()[3]));
});

test('the bundled starter chart is numbered, keeps every old number and carries the owner accounts', function (): void {
    $template = pl_starter_template();
    assert_same('core-starter', $template['id']);
    assert_same('1.2.0', $template['version']);
    $legacy = [];
    foreach ($template['accounts'] as $definition) {
        assert_same(true, pl_account_code_is_valid($definition['code']), $definition['code'] . ' is not a structured code.');
        assert_same(true, pl_account_code_matches_type($definition['code'], $definition['type']));
        assert_same('account', pl_account_code_level($definition['code']));
        if (isset($definition['legacy_code'])) { $legacy[$definition['legacy_code']] = $definition['code']; }
    }
    // Every account of the pre-conversion chart is still reachable by the number it had.
    assert_same(['1000', '1100', '2000', '3000', '4000', '5000'], array_map('strval', array_keys($legacy)));
    $semantic = array_column($template['accounts'], 'semantic_key');
    foreach (['core.equity.owner', 'core.equity.drawings', 'core.liability.owner_loan',
              'core.asset.accumulated_depreciation', 'core.income.sales_returns', 'core.expense.purchase_returns'] as $key) {
        assert_true(in_array($key, $semantic, true), 'The starter chart is missing ' . $key . '.');
    }
    $contra = array_values(array_filter($template['accounts'], static fn (array $a): bool => ($a['is_contra'] ?? false) === true));
    assert_same(4, count($contra));
    foreach ($contra as $account) {
        assert_true(pl_account_code_parse($account['code'])['group'] >= 900, $account['code'] . ' is not in the reserved contra band.');
    }
});

test('a new book is born numbered and keeps each account reachable by its 1.0.0 number', function (): void {
    $f = ledger_fixture();
    // A book created on 1.2 was never converted, so it has no conversion records; the old number
    // stays on the account row and resolves through the shared mapping helper.
    assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_account_code_map WHERE company_id = %i', $f['company_id']));
    $accounts = DB::query('SELECT id, code, legacy_code FROM pl_accounts WHERE company_id = %i ORDER BY code', $f['company_id']);
    $mapping = pl_account_code_mapping($accounts);
    assert_same($mapping['1-100-10001-00'], $mapping['1000']);
    // Thirteen posting accounts, nineteen class and group headings, and the six numbers the 1.0.0
    // chart used, which still resolve.
    assert_same(14 + 19 + 6, count($mapping));
    foreach ($accounts as $row) {
        assert_same(true, pl_account_code_is_valid((string) $row['code']));
    }
});

test('headings and sub-accounts are created from the chart screen and only leaves receive postings', function (): void {
    $f = ledger_fixture();
    $reason = 'Structured chart test.';
    $make = static function (array $f, string $code, string $name, string $type, array $extra = []) use ($reason): array {
        return pl_save_account($f['actor_id'], $f['company_id'], $f['book_id'], $extra + [
            'code' => $code, 'name' => $name, 'type' => $type, 'role' => null, 'is_active' => true,
            'reason' => $reason, 'creation_key' => bin2hex(random_bytes(16))]);
    };
    // The class heading arrives with the chart now, named, so the screen never has to create it —
    // and asking for the same code again is refused rather than duplicated.
    $class = pl_get_account($f['actor_id'], $f['company_id'], $f['book_id'], (int) DB::queryFirstField(
        'SELECT id FROM pl_accounts WHERE company_id = %i AND book_id = %i AND code = %s', $f['company_id'], $f['book_id'], '5-000-00000-00'));
    assert_same('class', $class['level']);
    assert_same(false, $class['is_postable']);
    assert_same('Expenses', $class['name']);
    assert_throws(fn () => $make($f, '5-000-00000-00', 'Expenses again', 'expense'), DomainException::class, 'already in use');
    $group = $make($f, '5-200-00000-00', 'Vehicle running costs', 'expense');
    assert_same('group', $group['level']);
    assert_same(false, $group['is_postable']);
    $account = $make($f, '5-200-10001-00', 'Fuel', 'expense');
    assert_same('account', $account['level']);
    assert_same(true, $account['is_postable']);

    // A leaf accepts a posting; a heading does not, in the one central funnel.
    $payload = ledger_payload($f);
    $payload['lines'][0]['account_id'] = $account['id'];
    $payload['lines'][1]['account_id'] = $f['accounts']['1000'];
    $payload['lines'][0]['debit'] = '12.3400'; $payload['lines'][0]['credit'] = '0';
    $payload['lines'][1]['debit'] = '0'; $payload['lines'][1]['credit'] = '12.3400';
    assert_same(2, count(pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], $payload)['lines']));
    $blocked = ledger_payload($f);
    $blocked['lines'][0]['account_id'] = $group['id'];
    assert_throws(fn () => pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], $blocked), DomainException::class, 'lowest account');

    // Adding a sub-account turns its parent into an aggregate; the parent stops accepting postings.
    assert_throws(fn () => $make($f, '5-300-10009-01', 'Orphan sub-account', 'expense'), DomainException::class, 'Create the parent account');
    $sub = $make($f, '5-200-10001-01', 'Fuel — van', 'expense');
    assert_same('sub_account', $sub['level']);
    assert_same(true, $sub['is_postable']);
    assert_same(false, pl_get_account($f['actor_id'], $f['company_id'], $f['book_id'], $account['id'])['is_postable']);
    $now = ledger_payload($f);
    $now['lines'][0]['account_id'] = $account['id'];
    assert_throws(fn () => pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], $now), DomainException::class, 'lowest account');

    assert_throws(fn () => $make($f, '1-200-10001-00', 'Wrong class digit', 'expense'), DomainException::class, 'first digit');
    assert_throws(fn () => $make($f, '5-300-00000-00', 'Heading with a purpose', 'expense', ['role' => 'expense']), DomainException::class, 'heading');
    assert_throws(fn () => $make($f, '2-200-10001-00', 'Contra liability', 'liability', ['is_contra' => true]), DomainException::class, 'not presented as a deduction');
    // A legacy number is still accepted so a converted book can keep adding accounts as before.
    assert_same('account', $make($f, '6100', 'Legacy numbered account', 'expense')['level']);
});

test('the conversion migration renumbers a populated chart without touching identity or postings', function (): void {
    $f = ledger_fixture();
    $company = $f['company_id'];
    $book = $f['book_id'];
    // Migration 047's provision cannot exist before migration 036 in a real upgrade.
    // Remove that later-only row before reconstructing the historical chart.
    DB::delete('pl_accounts', 'company_id = %i AND semantic_key = %s', $company, 'core.expense.cash_over_short');
    // Put the book back the way a 1.1.x installation actually looks: the six chart accounts that
    // existed before 1.2 carry their old numbers and nothing has been converted yet.
    DB::query('UPDATE pl_accounts SET code = legacy_code, legacy_code = NULL WHERE company_id = %i AND legacy_code IS NOT NULL', $company);
    $legacy = ['1010' => 'asset', '1020' => 'asset', '1150' => 'asset', '2050' => 'liability', '5010' => 'expense'];
    $ids = [];
    foreach ($legacy as $code => $type) {
        $code = (string) $code;
        $ids[$code] = pl_save_account($f['actor_id'], $company, $book, ['code' => $code, 'name' => 'Legacy ' . $code,
            'type' => $type, 'role' => null, 'is_active' => true, 'reason' => 'Conversion fixture.',
            'creation_key' => bin2hex(random_bytes(16))])['id'];
    }
    $payload = ledger_payload($f, '250.0000');
    $payload['lines'][0]['account_id'] = $ids['1010'];
    $payload['lines'][1]['account_id'] = $ids['2050'];
    $journal = pl_post_journal($f['actor_id'], $company, $book, $payload);
    $before = pl_trial_balance($f['actor_id'], $company, $book);
    $beforeBalances = [];
    foreach ($before['accounts'] as $row) { $beforeBalances[(int) $row['id']] = $row['balance']; }
    ksort($beforeBalances);
    $beforeRows = DB::query('SELECT id, type, role, is_active, revision, currency FROM pl_accounts WHERE company_id = %i ORDER BY id', $company);
    $beforeLines = DB::query('SELECT id, journal_id, account_id, debit, credit FROM pl_journal_lines WHERE company_id = %i ORDER BY id', $company);

    // Replay the migration's own conversion statements, scoped to this fixture's book so the rest
    // of the test database is untouched. Only the first statement selects from pl_accounts.
    $statements = require dirname(__DIR__) . '/www/phpledger/install/migrations/036_structured_account_codes.php';
    $conversion = array_values(array_filter($statements, static fn (string $sql): bool =>
        str_contains($sql, 'pl_account_code_conversion') || str_contains($sql, 'pl_account_code_allocation')));
    assert_true(count($conversion) >= 8, 'The migration has no conversion statements.');
    foreach ($conversion as $sql) {
        // Only the first statement reads pl_accounts; scope it to this book so the rest of the
        // shared test database keeps the numbers its own suites expect.
        if (str_contains($sql, "a.code NOT LIKE '_-___-_____-__'")) {
            DB::query(str_replace("a.code NOT LIKE '_-___-_____-__'", 'a.code NOT LIKE %s AND a.book_id = %i', $sql), '_-___-_____-__', $book);
            continue;
        }
        DB::query($sql);
    }

    $mapping = [];
    foreach (DB::query('SELECT account_id, legacy_code, structured_code FROM pl_account_code_map WHERE book_id = %i', $book) as $row) {
        $mapping[(string) $row['legacy_code']] = (string) $row['structured_code'];
    }
    // Every converted account has a mapping row and a valid code; old numbers are never lost.
    assert_same(11, count($mapping));
    foreach ($legacy as $code => $type) {
        $code = (string) $code;
        assert_true(isset($mapping[$code]), 'No mapping for ' . $code);
        assert_same(true, pl_account_code_is_valid($mapping[$code]));
        assert_same(pl_account_code_class_for_type($type), pl_account_code_parse($mapping[$code])['class']);
        assert_same($code, DB::queryFirstField('SELECT legacy_code FROM pl_accounts WHERE id = %i', $ids[$code]));
    }
    // Accounts that shared a group before share one now; different groups stay apart.
    assert_same(pl_account_code_parse($mapping['1010'])['group'], pl_account_code_parse($mapping['1020'])['group']);
    assert_true(pl_account_code_parse($mapping['1010'])['group'] !== pl_account_code_parse($mapping['1150'])['group']);
    foreach ($mapping as $code) {
        assert_true(pl_account_code_parse($code)['group'] <= pl_account_code_last_group(), $code . ' reached the reserved contra band.');
        assert_same('account', pl_account_code_level($code));
    }
    // Identity, classification, balances and posted lines are untouched.
    assert_same($beforeRows, DB::query('SELECT id, type, role, is_active, revision, currency FROM pl_accounts WHERE company_id = %i ORDER BY id', $company));
    assert_same($beforeLines, DB::query('SELECT id, journal_id, account_id, debit, credit FROM pl_journal_lines WHERE company_id = %i ORDER BY id', $company));
    $after = pl_trial_balance($f['actor_id'], $company, $book);
    $afterBalances = [];
    foreach ($after['accounts'] as $row) { $afterBalances[(int) $row['id']] = $row['balance']; }
    ksort($afterBalances);
    assert_same($beforeBalances, $afterBalances);
    assert_same($before['total_debit'], $after['total_debit']);
    assert_true($after['balanced']);
    assert_same($journal['id'], pl_get_journal($f['actor_id'], $company, $book, $journal['id'])['id']);
});
