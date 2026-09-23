<?php
declare(strict_types=1);

function cash_policy_fixture(?string $kind = 'physical'): array
{
    $f = ledger_fixture();
    DB::update('pl_accounts', ['money_kind' => $kind, 'overdraft_enabled' => 0, 'overdraft_limit' => '0.0000'], 'id=%i', $f['accounts']['1000']);
    return $f;
}

function cash_policy_input(array $f, string $mode): array
{
    return array_replace(pl_trading_policies($f['actor_id'], $f['company_id'], $f['book_id']), [
        'cash_shortfall_policy' => $mode, 'reason' => 'Sample administrator cash policy decision',
        'idempotency_key' => bin2hex(random_bytes(16)),
    ]);
}

function cash_policy_set(array $f, string $mode): array
{
    return pl_save_trading_policies($f['actor_id'], $f['company_id'], $f['book_id'], cash_policy_input($f, $mode));
}

test('cash policy defaults to warning for absent and pre-existing policy rows', function (): void {
    $f = cash_policy_fixture();
    assert_same('warning', pl_trading_policies($f['actor_id'], $f['company_id'], $f['book_id'])['cash_shortfall_policy']);
    assert_same('warning', pl_cash_balance_policy($f['company_id'], $f['book_id']));
    $input = cash_policy_input($f, 'warning'); unset($input['cash_shortfall_policy']);
    $saved = pl_save_trading_policies($f['actor_id'], $f['company_id'], $f['book_id'], $input);
    assert_same('warning', $saved['cash_shortfall_policy']);
    $preview = pl_cash_payment_preview($f['actor_id'], $f['company_id'], $f['book_id'], $f['accounts']['1000'], '2026-01-02', '10');
    assert_same(true, $preview['insufficient_cash']); assert_same(false, $preview['blocked_payment']);
    assert_true(str_contains($preview['warning'], 'Warning only'));
    cash_control_post($f, '10', '2026-01-02', true);
    assert_same('-10.0000', pl_cash_payment_preview($f['actor_id'], $f['company_id'], $f['book_id'], $f['accounts']['1000'], '2026-01-02', '0')['balance']);
});

test('strict cash policy blocks shortfalls while preserving retries and historical entries', function (): void {
    $f = cash_policy_fixture();
    $posted = cash_control_post($f, '10', '2026-01-02', true, 'warning-payment-before-strict');
    cash_policy_set($f, 'strict');
    assert_same($posted['id'], cash_control_post($f, '10', '2026-01-02', true, 'warning-payment-before-strict')['id']);
    $preview = pl_cash_payment_preview($f['actor_id'], $f['company_id'], $f['book_id'], $f['accounts']['1000'], '2026-01-03', '1');
    assert_same('strict', $preview['cash_shortfall_policy']); assert_same(true, $preview['blocked_payment']);
    assert_throws(fn() => cash_control_post($f, '1', '2026-01-03', true), DomainException::class, 'would leave');
    cash_control_post($f, '5', '2026-01-03');
    cash_policy_set($f, 'warning');
    cash_control_post($f, '1', '2026-01-03', true);
    assert_same('-6.0000', pl_cash_payment_preview($f['actor_id'], $f['company_id'], $f['book_id'], $f['accounts']['1000'], '2026-01-03', '0')['balance']);
});

test('warning allows unclassified payouts while strict requires classification', function (): void {
    $f = cash_policy_fixture(null);
    $preview = pl_cash_payment_preview($f['actor_id'], $f['company_id'], $f['book_id'], $f['accounts']['1000'], '2026-01-02', '10');
    assert_same(true, $preview['classification_required']); assert_same(false, $preview['blocked_payment']);
    assert_true(str_contains($preview['warning'], 'Classify'));
    cash_control_post($f, '10', '2026-01-02', true);
    cash_policy_set($f, 'strict');
    assert_throws(fn() => cash_control_post($f, '1', '2026-01-03', true), DomainException::class, 'Choose physical cash or bank');
});

test('cash policy governs atomic corrections and strict failures roll back the entire change', function (): void {
    $f = cash_policy_fixture();
    pl_cash_atomic_change($f['company_id'], $f['book_id'], fn() => cash_control_post($f, '10', '2026-01-02', true));
    cash_policy_set($f, 'strict');
    $count = (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE company_id=%i', $f['company_id']);
    assert_throws(fn() => pl_cash_atomic_change($f['company_id'], $f['book_id'], function () use ($f): void {
        cash_control_post($f, '2', '2026-01-03');
        cash_control_post($f, '3', '2026-01-03', true);
    }), DomainException::class, 'would leave');
    assert_same($count, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE company_id=%i', $f['company_id']));
    assert_same([], pl_cash_atomic_scope());
    pl_cash_atomic_change($f['company_id'], $f['book_id'], function () use ($f): void {
        cash_control_post($f, '3', '2026-01-03', true);
        cash_control_post($f, '4', '2026-01-03');
    });
});

test('cash policy saves are scoped audited idempotent and revision checked', function (): void {
    $f = cash_policy_fixture(); $other = cash_policy_fixture();
    $input = cash_policy_input($f, 'strict');
    $saved = pl_save_trading_policies($f['actor_id'], $f['company_id'], $f['book_id'], $input);
    assert_true($saved == pl_save_trading_policies($f['actor_id'], $f['company_id'], $f['book_id'], $input), 'A retry must return the same policy values regardless of JSON object key order.');
    $audit = DB::queryFirstRow('SELECT before_state,result_json FROM pl_trading_policy_actions WHERE company_id=%i AND request_key=%s', $f['company_id'], $input['idempotency_key']);
    assert_same('warning', json_decode($audit['before_state'], true, 32, JSON_THROW_ON_ERROR)['cash_shortfall_policy']);
    assert_same('strict', json_decode($audit['result_json'], true, 32, JSON_THROW_ON_ERROR)['cash_shortfall_policy']);
    $changed = array_replace($input, ['cash_shortfall_policy' => 'warning']);
    assert_throws(fn() => pl_save_trading_policies($f['actor_id'], $f['company_id'], $f['book_id'], $changed), DomainException::class, 'different content');
    $stale = array_replace($changed, ['idempotency_key' => bin2hex(random_bytes(16))]);
    assert_throws(fn() => pl_save_trading_policies($f['actor_id'], $f['company_id'], $f['book_id'], $stale), DomainException::class, 'Someone changed');
    assert_throws(fn() => pl_save_trading_policies($other['actor_id'], $f['company_id'], $f['book_id'], cash_policy_input($f, 'warning')), DomainException::class);
    assert_same('warning', pl_cash_balance_policy($other['company_id'], $other['book_id']));
    $legacy = cash_policy_input($f, 'warning'); unset($legacy['cash_shortfall_policy']);
    assert_same('strict', pl_save_trading_policies($f['actor_id'], $f['company_id'], $f['book_id'], $legacy)['cash_shortfall_policy']);
    $invalid = cash_policy_input($f, 'invalid');
    assert_throws(fn() => pl_save_trading_policies($f['actor_id'], $f['company_id'], $f['book_id'], $invalid), DomainException::class, 'Choose warning only');
});

test('cash policy remains behind server side administrator permission', function (): void {
    $f = cash_policy_fixture();
    $member = pl_create_user('cash-policy-' . bin2hex(random_bytes(6)) . '@example.test', 'Sample accountant', 'Sample-cash-policy-password');
    $accountantRole = (int) DB::queryFirstField("SELECT id FROM pl_roles WHERE company_id IS NULL AND slug='accountant'");
    pl_assign_company_role($f['actor_id'], $f['company_id'], $member, $accountantRole, 'Sample accountant membership');
    assert_throws(fn() => pl_save_trading_policies($member, $f['company_id'], $f['book_id'], cash_policy_input($f, 'strict')), DomainException::class, 'cannot change');
    assert_same('warning', pl_cash_balance_policy($f['company_id'], $f['book_id']));
});

test('posting reads the committed policy after an earlier repeatable read snapshot in both directions', function (): void {
    foreach ([['warning', 'strict', false], ['strict', 'warning', true]] as [$before, $after, $shouldPost]) {
        $f = cash_policy_fixture();
        cash_policy_set($f, $before);
        $directory = sys_get_temp_dir() . '/pl-cash-policy-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $signals = ['policy_snapshot_ready' => $directory . '/snapshot', 'policy_change_complete' => $directory . '/changed'];
        try {
            $results = ledger_race([
                ['mode' => 'cash_post', 'fixture' => $f, 'payload' => cash_control_payload($f, '1', '2026-01-02', true)] + $signals,
                ['mode' => 'cash_policy_set', 'fixture' => $f, 'policy_input' => cash_policy_input($f, $after)] + $signals,
            ]);
            assert_same($shouldPost, $results[0]['id'] > 0);
            assert_same($after, pl_cash_balance_policy($f['company_id'], $f['book_id']));
        } finally {
            foreach ($signals as $path) { if (is_file($path)) { unlink($path); } }
            rmdir($directory);
        }
    }
});

test('delegated policy administrator can use settings but invalid csrf cannot change the policy', function (): void {
    $f = cash_policy_fixture(); $suffix = bin2hex(random_bytes(6));
    $email = 'policy-http-' . $suffix . '@example.test'; $password = 'Sample-policy-http-' . $suffix;
    $member = pl_create_user($email, 'Sample delegated policy administrator', $password);
    $role = pl_save_role($f['actor_id'], $f['company_id'], ['name' => 'Sample policy administrator', 'reason' => 'Sample delegation', 'capabilities' => ['company.read', 'company.write', 'policy.manage']]);
    pl_assign_company_role($f['actor_id'], $f['company_id'], $member, $role['id'], 'Sample delegated policy administration');
    $port = random_int(20000, 50000); $base = 'http://127.0.0.1:' . $port;
    $log = sys_get_temp_dir() . '/pl-policy-http-' . $suffix . '.log';
    $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', dirname(__DIR__) . '/www/phpledger/public'], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, dirname(__DIR__), array_replace(getenv(), ['PL_SESSION_SECURE' => '0', 'PL_LOCALE' => 'en']));
    if (!is_resource($server)) { throw new RuntimeException('Sample policy HTTP server unavailable.'); }
    fclose($pipes[0]); $cookie = '';
    $request = static function (string $path, ?array $post = null) use ($base, &$cookie): array {
        $headers = ['Connection: close'];
        if ($cookie !== '') { $headers[] = 'Cookie: ' . $cookie; }
        if ($post !== null) { $headers[] = 'Content-Type: application/x-www-form-urlencoded'; }
        $context = stream_context_create(['http' => ['method' => $post === null ? 'GET' : 'POST', 'header' => implode("\r\n", $headers), 'content' => $post === null ? '' : http_build_query($post), 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 15]]);
        $html = @file_get_contents($base . $path, false, $context); $response = $http_response_header ?? [];
        foreach ($response as $header) { if (preg_match('/^Set-Cookie: ([^;]+)/i', $header, $m)) { $cookie = $m[1]; } }
        preg_match('/\s(\d{3})\s/', $response[0] ?? '', $m);
        return [(int) ($m[1] ?? 0), $html === false ? '' : $html];
    };
    $field = static function (string $html, string $name): string {
        preg_match('/name="' . preg_quote($name, '/') . '" value="([^"]*)"/', $html, $m);
        return html_entity_decode($m[1] ?? '', ENT_QUOTES, 'UTF-8');
    };
    try {
        for ($retry = 0; $retry < 50; $retry++) { [$status, $html] = $request('/login'); if ($status) { break; } usleep(20000); }
        assert_same(200, $status);
        [$status] = $request('/login', ['email' => $email, 'password' => $password, 'csrf' => $field($html, 'csrf')]); assert_same(303, $status);
        [, $html] = $request('/companies');
        $request('/company/select', ['company_id' => $f['company_id'], 'csrf' => $field($html, 'csrf')]);
        [$status, $html] = $request('/accounting-policies'); assert_same(200, $status);
        assert_true(str_contains($html, 'id="policy-cash-shortfall"'), 'Delegated administrator cannot see the policy form.');
        $post = ['company_id' => $f['company_id'], 'book_id' => $f['book_id'], 'csrf' => $field($html, 'csrf'), 'revision' => $field($html, 'revision'), 'request_key' => $field($html, 'request_key'), 'discount_posting' => 'net', 'discount_account_id' => '', 'free_goods_account_id' => '', 'free_goods_output_tax' => 'none', 'cash_on_invoice_cap' => '0', 'cash_shortfall_policy' => 'strict', 'reason' => 'Sample delegated strict policy'];
        $request('/accounting-policies', array_replace($post, ['csrf' => 'invalid']));
        assert_same('warning', pl_cash_balance_policy($f['company_id'], $f['book_id']));
        assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_trading_policy_actions WHERE company_id=%i', $f['company_id']));
        [$status] = $request('/accounting-policies', $post); assert_same(303, $status);
        assert_same('strict', pl_cash_balance_policy($f['company_id'], $f['book_id']));
        [$status, $html] = $request('/accounting-policies'); assert_same(200, $status);
        assert_true(str_contains($html, 'value="strict" selected'));
    } finally { proc_terminate($server); proc_close($server); @unlink($log); }
});
