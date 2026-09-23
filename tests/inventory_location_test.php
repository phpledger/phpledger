<?php
declare(strict_types=1);

// Stock locations module. Registered after inventory_test.php by tests/run.php. Invoked directly only as a transfer race worker.
if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if (getenv('PL_ENV') !== 'test' || getenv('PL_DB_NAME') !== 'phpledger_test' || ($argv[1] ?? '') !== '--transfer-worker') { exit(2); }
    require_once dirname(__DIR__) . '/www/phpledger/includes/bootstrap.php';
    $job = json_decode((string) fgets(STDIN), true, 32, JSON_THROW_ON_ERROR);
    // Every worker finishes bootstrapping before the caller releases the start pipes.
    echo "ready\n"; flush(); fgets(STDIN);
    try {
        $f = $job['fixture'];
        $result = pl_inventory_transfer($f['actor_id'], $f['company_id'], $f['book_id'], $job['input']);
        echo json_encode(['id' => $result['out']['movement_id']], JSON_THROW_ON_ERROR);
    } catch (DomainException $error) {
        if (!($job['allow_domain_failure'] ?? false)) { throw $error; }
        echo json_encode(['id' => 0], JSON_THROW_ON_ERROR);
    }
    exit(0);
}

function location_warehouse_input(string $code = 'SYNTH-VAN'): array
{
    return ['code' => $code, 'name' => 'Sample van', 'is_active' => true, 'reason' => 'Sample warehouse test', 'idempotency_key' => bin2hex(random_bytes(16))];
}

function location_enable(array $f): void
{
    $manifest = pl_module_registry()['inventory-locations'];
    pl_set_company_module($f['actor_id'], $f['company_id'], 'inventory-locations', true, 0, $manifest['digest'], 'Sample stock locations module', bin2hex(random_bytes(16)));
}

function location_fixture(): array
{
    $f = inventory_fixture(); location_enable($f);
    $default = pl_inventory_default_warehouse($f['actor_id'], $f['company_id'], $f['book_id']);
    $van = pl_save_inventory_warehouse($f['actor_id'], $f['company_id'], $f['book_id'], location_warehouse_input());
    return $f + ['default_warehouse_id' => $default['id'], 'van_warehouse_id' => $van['id']];
}

function location_transfer_input(array $f, string $quantity = '1', string $date = '2026-01-06'): array
{
    return ['product_id' => $f['product_id'], 'from_warehouse_id' => $f['default_warehouse_id'], 'to_warehouse_id' => $f['van_warehouse_id'],
        'quantity' => $quantity, 'date' => $date, 'source_reference' => bin2hex(random_bytes(16)), 'reason' => 'Sample carrying value transfer', 'idempotency_key' => bin2hex(random_bytes(16))];
}

function location_balance(array $f, int $warehouseId, ?string $date = null): array
{
    return pl_inventory_balance($f['actor_id'], $f['company_id'], $f['book_id'], $f['product_id'], $date, $warehouseId);
}

/** MySQL JSON objects reorder keys; retries must retain every value and its exact type. */
function location_assert_retry(array $expected, array $actual): void
{
    ksort($expected); ksort($actual); assert_same(array_keys($expected), array_keys($actual));
    foreach ($expected as $key => $value) {
        if (is_array($value)) { assert_true(is_array($actual[$key])); location_assert_retry($value, $actual[$key]); }
        else { assert_same($value, $actual[$key]); }
    }
}

/** Independent PHP connections share a pipe barrier so both transfers hit the book lock together. */
function location_transfer_race(array $jobs): array
{
    $workers = [];
    try {
        foreach ($jobs as $job) {
            $pipes = [];
            $process = proc_open([PHP_BINARY, __FILE__, '--transfer-worker'], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
            if (!is_resource($process)) { throw new RuntimeException('Cannot start the transfer worker.'); }
            foreach ($pipes as $pipe) { stream_set_timeout($pipe, 20); }
            $workers[] = ['process' => $process, 'pipes' => $pipes];
            fwrite($pipes[0], json_encode($job, JSON_THROW_ON_ERROR) . "\n"); fflush($pipes[0]);
        }
        foreach ($workers as $worker) { assert_same("ready\n", fgets($worker['pipes'][1]), 'Worker startup failed.'); }
        foreach ($workers as $worker) { fwrite($worker['pipes'][0], "go\n"); fflush($worker['pipes'][0]); }
        $results = [];
        foreach ($workers as $worker) {
            $stdout = stream_get_contents($worker['pipes'][1]); $stderr = stream_get_contents($worker['pipes'][2]);
            assert_same('', $stderr, 'Transfer worker failed.');
            $results[] = json_decode($stdout, true, 32, JSON_THROW_ON_ERROR);
        }
        return $results;
    } finally {
        foreach ($workers as $worker) {
            $status = proc_get_status($worker['process']);
            if ($status['running']) { proc_terminate($worker['process']); }
            foreach ($worker['pipes'] as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
            proc_close($worker['process']);
        }
    }
}

test('stock locations: the default warehouse works without the module and other warehouses require it', function (): void {
    $f = inventory_fixture();
    $default = pl_inventory_default_warehouse($f['actor_id'], $f['company_id'], $f['book_id']);
    assert_same(true, $default['is_default']); assert_same('DEFAULT', $default['code']);
    assert_same($default['id'], pl_inventory_default_warehouse($f['actor_id'], $f['company_id'], $f['book_id'])['id']);
    $receipt = pl_inventory_receive($f['actor_id'], $f['company_id'], $f['book_id'], inventory_move_input($f));
    assert_same($default['id'], $receipt['warehouse_id']);
    assert_same('100.0000', location_balance($f, $default['id'])['value_base']);
    assert_same($default['id'], (int) pl_inventory_history($f['actor_id'], $f['company_id'], $f['book_id'])[0]['warehouse_id']);
    assert_throws(fn() => pl_save_inventory_warehouse($f['actor_id'], $f['company_id'], $f['book_id'], location_warehouse_input()), DomainException::class, 'disabled');
    assert_throws(fn() => pl_inventory_transfer($f['actor_id'], $f['company_id'], $f['book_id'], ['product_id' => $f['product_id'], 'from_warehouse_id' => $default['id'], 'to_warehouse_id' => $default['id'] + 1, 'quantity' => '1', 'date' => '2026-01-06', 'source_reference' => 'x', 'reason' => 'x', 'idempotency_key' => 'x']), DomainException::class);
    assert_same(1, count(pl_list_inventory_warehouses($f['actor_id'], $f['company_id'], $f['book_id'])));
    location_enable($f);
    $van = pl_save_inventory_warehouse($f['actor_id'], $f['company_id'], $f['book_id'], location_warehouse_input());
    assert_same(false, $van['is_default']); assert_same(2, count(pl_list_inventory_warehouses($f['actor_id'], $f['company_id'], $f['book_id'])));
    $manifest = pl_module_registry()['inventory-locations'];
    pl_set_company_module($f['actor_id'], $f['company_id'], 'inventory-locations', false, 1, $manifest['digest'], 'Sample disable', bin2hex(random_bytes(16)));
    assert_throws(fn() => pl_inventory_receive($f['actor_id'], $f['company_id'], $f['book_id'], inventory_move_input($f, '1', '10', '2026-01-06') + ['warehouse_id' => $van['id']]), DomainException::class, 'disabled');
    assert_same('10.0000', pl_inventory_receive($f['actor_id'], $f['company_id'], $f['book_id'], inventory_move_input($f, '1', '10', '2026-01-06'))['value_base']);
    assert_same('110.0000', location_balance($f, $default['id'])['value_base']);
});

test('stock locations: warehouse masters enforce scope revisions retries and immutable identity', function (): void {
    $f = location_fixture(); $other = location_fixture();
    assert_same($f['default_warehouse_id'], pl_inventory_default_warehouse($f['actor_id'], $f['company_id'], $f['book_id'])['id']);
    assert_same(2, count(pl_list_inventory_warehouses($f['actor_id'], $f['company_id'], $f['book_id'])));
    $input = location_warehouse_input('SYNTH-SECOND');
    $warehouse = pl_save_inventory_warehouse($f['actor_id'], $f['company_id'], $f['book_id'], $input);
    location_assert_retry($warehouse, pl_save_inventory_warehouse($f['actor_id'], $f['company_id'], $f['book_id'], $input));
    $input['name'] = 'Sample renamed van';
    assert_throws(fn() => pl_save_inventory_warehouse($f['actor_id'], $f['company_id'], $f['book_id'], $input), DomainException::class, 'different content');
    $input['idempotency_key'] = bin2hex(random_bytes(16));
    $edited = pl_save_inventory_warehouse($f['actor_id'], $f['company_id'], $f['book_id'], $input, $warehouse['id'], 1);
    assert_same(2, $edited['revision']); assert_same('Sample renamed van', $edited['name']);
    location_assert_retry($edited, pl_save_inventory_warehouse($f['actor_id'], $f['company_id'], $f['book_id'], $input, $warehouse['id'], 1));
    $input['idempotency_key'] = bin2hex(random_bytes(16));
    assert_throws(fn() => pl_save_inventory_warehouse($f['actor_id'], $f['company_id'], $f['book_id'], $input, $warehouse['id'], 1), DomainException::class, 'revision');
    assert_throws(fn() => pl_get_inventory_warehouse($f['actor_id'], $f['company_id'], $f['book_id'], $other['van_warehouse_id']), DomainException::class, 'warehouse');
    assert_throws(fn() => pl_list_inventory_warehouses($other['actor_id'], $f['company_id'], $f['book_id']), DomainException::class);
    assert_throws(fn() => pl_get_inventory_warehouse($f['actor_id'], $f['company_id'], $other['book_id'], $f['van_warehouse_id']), DomainException::class);
    $input['code'] = 'CHANGED';
    assert_throws(fn() => pl_save_inventory_warehouse($f['actor_id'], $f['company_id'], $f['book_id'], $input, $warehouse['id'], 2), DomainException::class, 'identity');
    $input = location_warehouse_input('DEFAULT'); $input['is_active'] = false;
    assert_throws(fn() => pl_save_inventory_warehouse($f['actor_id'], $f['company_id'], $f['book_id'], $input, $f['default_warehouse_id'], 1), DomainException::class, 'remain active');
    assert_throws(fn() => DB::update('pl_inventory_warehouses', ['is_default' => 0], 'id=%i', $f['default_warehouse_id']), Throwable::class, 'immutable');
    sample_membership_insert(['company_id' => $f['company_id'], 'user_id' => $other['actor_id'], 'role' => 'viewer']);
    assert_same(3, count(pl_list_inventory_warehouses($other['actor_id'], $f['company_id'], $f['book_id'])));
    assert_throws(fn() => pl_save_inventory_warehouse($other['actor_id'], $f['company_id'], $f['book_id'], location_warehouse_input('NO-WRITE')), DomainException::class);
});

test('stock locations: movements balances history and valuation are scoped per warehouse', function (): void {
    $f = location_fixture();
    pl_inventory_receive($f['actor_id'], $f['company_id'], $f['book_id'], inventory_move_input($f));
    pl_inventory_receive($f['actor_id'], $f['company_id'], $f['book_id'], inventory_move_input($f, '4', '80', '2026-01-05') + ['warehouse_id' => $f['van_warehouse_id']]);
    assert_same('80.0000', location_balance($f, $f['van_warehouse_id'], '2026-01-05')['value_base']);
    assert_same('10.0000', location_balance($f, $f['default_warehouse_id'], '2026-01-05')['quantity']);
    assert_same('180.0000', pl_inventory_balance($f['actor_id'], $f['company_id'], $f['book_id'], $f['product_id'])['value_base']);
    $input = inventory_move_input($f, '1', '0', '2026-01-06') + ['warehouse_id' => $f['van_warehouse_id']]; unset($input['offset_account_id']);
    $issue = pl_inventory_issue($f['actor_id'], $f['company_id'], $f['book_id'], $input);
    assert_same('20.0000', $issue['value_base']); assert_same($f['van_warehouse_id'], $issue['warehouse_id']);
    location_assert_retry($issue, pl_inventory_issue($f['actor_id'], $f['company_id'], $f['book_id'], $input));
    $input['warehouse_id'] = $f['default_warehouse_id'];
    assert_throws(fn() => pl_inventory_issue($f['actor_id'], $f['company_id'], $f['book_id'], $input), DomainException::class, 'different content');
    assert_throws(fn() => pl_inventory_receive($f['actor_id'], $f['company_id'], $f['book_id'], inventory_move_input($f, '1', '1', '2026-01-05') + ['warehouse_id' => $f['van_warehouse_id']]), DomainException::class, 'backdated');
    assert_same(2, count(pl_inventory_history($f['actor_id'], $f['company_id'], $f['book_id'], null, $f['van_warehouse_id'])));
    assert_same(1, count(pl_inventory_history($f['actor_id'], $f['company_id'], $f['book_id'], null, $f['default_warehouse_id'])));
    assert_same('SYNTH-VAN', pl_inventory_history($f['actor_id'], $f['company_id'], $f['book_id'], null, $f['van_warehouse_id'])[0]['warehouse_code']);
    $van = pl_inventory_valuation($f['actor_id'], $f['company_id'], $f['book_id'], '2026-01-20', $f['van_warehouse_id']);
    assert_same('60.0000', $van['total_value_base']); assert_same(false, $van['gl_reconciliation_available']);
    assert_same(null, $van['accounts'][0]['ledger_value']); assert_same(null, $van['accounts'][0]['difference']);
    $total = pl_inventory_valuation($f['actor_id'], $f['company_id'], $f['book_id'], '2026-01-20');
    assert_same('160.0000', $total['total_value_base']); assert_same(true, $total['gl_reconciliation_available']); assert_same('0.0000', $total['accounts'][0]['difference']);
    assert_throws(fn() => pl_inventory_issue($f['actor_id'], $f['company_id'], $f['book_id'], inventory_move_input($f, '4', '0', '2026-01-21') + ['warehouse_id' => $f['van_warehouse_id']]), DomainException::class, 'available');
});

test('stock locations: transfers pair carrying value preserve the GL and consume the exact final residual', function (): void {
    $f = location_fixture();
    pl_inventory_receive($f['actor_id'], $f['company_id'], $f['book_id'], inventory_move_input($f, '3', '1'));
    $values = []; $result = [];
    for ($i = 0; $i < 3; $i++) {
        $input = location_transfer_input($f);
        $result = pl_inventory_transfer($f['actor_id'], $f['company_id'], $f['book_id'], $input);
        $values[] = $result['value_base'];
        location_assert_retry($result, pl_inventory_transfer($f['actor_id'], $f['company_id'], $f['book_id'], $input));
        assert_same('0.0000', bcadd($result['out']['quantity_delta'], $result['in']['quantity_delta'], 4));
        assert_same('0.0000', bcadd($result['out']['value_delta'], $result['in']['value_delta'], 4));
        assert_same(false, $result['journal_created']); assert_same(null, $result['out']['journal_id']); assert_same(null, $result['in']['journal_id']);
        assert_same($result['out']['movement_id'], (int) DB::queryFirstField('SELECT original_movement_id FROM pl_inventory_movements WHERE id=%i', $result['in']['movement_id']));
    }
    assert_same(['0.3333','0.3334','0.3333'], $values);
    assert_same('0.0000', location_balance($f, $f['default_warehouse_id'])['value_base']);
    assert_same('0.0000', location_balance($f, $f['default_warehouse_id'])['quantity']);
    assert_same('1.0000', location_balance($f, $f['van_warehouse_id'])['value_base']);
    assert_same(1, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id=%i', $f['book_id']));
    assert_same('0.0000', pl_inventory_valuation($f['actor_id'], $f['company_id'], $f['book_id'], '2026-01-06')['accounts'][0]['difference']);
    assert_throws(fn() => DB::update('pl_inventory_movements', ['warehouse_id' => $f['van_warehouse_id']], 'id=%i', $result['out']['movement_id']), Throwable::class, 'immutable');
    $input['quantity'] = '2';
    assert_throws(fn() => pl_inventory_transfer($f['actor_id'], $f['company_id'], $f['book_id'], $input), DomainException::class, 'different content');
});

test('stock locations: a failed second transfer leg rolls back and the same request can recover', function (): void {
    $f = location_fixture();
    pl_inventory_receive($f['actor_id'], $f['company_id'], $f['book_id'], inventory_move_input($f));
    pl_inventory_receive($f['actor_id'], $f['company_id'], $f['book_id'], inventory_move_input($f, '1', '20', '2026-01-20') + ['warehouse_id' => $f['van_warehouse_id']]);
    $commands = (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_inventory_commands WHERE book_id=%i', $f['book_id']);
    $input = location_transfer_input($f, '2');
    assert_throws(fn() => pl_inventory_transfer($f['actor_id'], $f['company_id'], $f['book_id'], $input), DomainException::class, 'backdated');
    assert_same(2, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_inventory_movements WHERE book_id=%i', $f['book_id']));
    assert_same($commands, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_inventory_commands WHERE book_id=%i', $f['book_id']));
    assert_same('10.0000', location_balance($f, $f['default_warehouse_id'])['quantity']);
    $input['date'] = '2026-01-20';
    $result = pl_inventory_transfer($f['actor_id'], $f['company_id'], $f['book_id'], $input);
    assert_same('20.0000', $result['value_base']);
    assert_same('40.0000', location_balance($f, $f['van_warehouse_id'])['value_base']);
    assert_same('13.333333333333', location_balance($f, $f['van_warehouse_id'])['average_cost']);
    $input['idempotency_key'] = bin2hex(random_bytes(16));
    assert_throws(fn() => pl_inventory_transfer($f['actor_id'], $f['company_id'], $f['book_id'], $input), DomainException::class, 'source already');
    $input = location_transfer_input($f, '2', '2026-01-21');
    assert_throws(function () use ($f, $input): void {
        pl_ledger_transaction(function () use ($f, $input): void { pl_inventory_transfer($f['actor_id'], $f['company_id'], $f['book_id'], $input); throw new DomainException('Synthetic outer failure'); });
    }, DomainException::class, 'Synthetic outer failure');
    assert_same(4, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_inventory_movements WHERE book_id=%i', $f['book_id']));
    assert_same($commands + 1, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_inventory_commands WHERE book_id=%i', $f['book_id']));
    assert_same('8.0000', location_balance($f, $f['default_warehouse_id'])['quantity']);
});

test('stock locations: transfers enforce access scope active warehouses periods and stock guards', function (): void {
    $f = location_fixture(); $other = location_fixture();
    pl_inventory_receive($f['actor_id'], $f['company_id'], $f['book_id'], inventory_move_input($f));
    $input = location_transfer_input($f);
    assert_throws(fn() => pl_inventory_transfer($other['actor_id'], $f['company_id'], $f['book_id'], $input), DomainException::class);
    sample_membership_insert(['company_id' => $f['company_id'], 'user_id' => $other['actor_id'], 'role' => 'viewer']);
    assert_throws(fn() => pl_inventory_transfer($other['actor_id'], $f['company_id'], $f['book_id'], $input), DomainException::class);
    foreach (['from_warehouse_id','to_warehouse_id'] as $field) {
        $bad = array_replace($input, [$field => $other['van_warehouse_id']]);
        assert_throws(fn() => pl_inventory_transfer($f['actor_id'], $f['company_id'], $f['book_id'], $bad), DomainException::class, 'warehouse');
    }
    $bad = array_replace($input, ['product_id' => $other['product_id']]);
    assert_throws(fn() => pl_inventory_transfer($f['actor_id'], $f['company_id'], $f['book_id'], $bad), DomainException::class, 'product');
    foreach (['0','11', 0.1] as $quantity) {
        $bad = array_replace($input, ['quantity' => $quantity]);
        assert_throws(fn() => pl_inventory_transfer($f['actor_id'], $f['company_id'], $f['book_id'], $bad), DomainException::class);
    }
    $bad = array_replace($input, ['to_warehouse_id' => $f['default_warehouse_id']]);
    assert_throws(fn() => pl_inventory_transfer($f['actor_id'], $f['company_id'], $f['book_id'], $bad), DomainException::class, 'different warehouses');
    DB::update('pl_periods', ['status' => 'closed'], 'id=%i', $f['period_id']);
    try { assert_throws(fn() => pl_inventory_transfer($f['actor_id'], $f['company_id'], $f['book_id'], $input), DomainException::class, 'open accounting period'); }
    finally { DB::update('pl_periods', ['status' => 'open'], 'id=%i', $f['period_id']); }
    $edit = location_warehouse_input(); $edit['is_active'] = false;
    pl_save_inventory_warehouse($f['actor_id'], $f['company_id'], $f['book_id'], $edit, $f['van_warehouse_id'], 1);
    assert_throws(fn() => pl_inventory_transfer($f['actor_id'], $f['company_id'], $f['book_id'], $input), DomainException::class, 'active warehouse');
    assert_same('0.0000', location_balance($f, $f['van_warehouse_id'])['quantity']);
    assert_throws(fn() => location_balance($f, $other['van_warehouse_id']), DomainException::class);
    assert_throws(fn() => pl_inventory_valuation($f['actor_id'], $f['company_id'], $f['book_id'], null, $other['van_warehouse_id']), DomainException::class);
    assert_same(1, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_inventory_movements WHERE book_id=%i', $f['book_id']));
});

test('stock locations: returns credits and reversals stay in the original warehouse at original cost', function (): void {
    $f = location_fixture();
    pl_inventory_receive($f['actor_id'], $f['company_id'], $f['book_id'], inventory_move_input($f, '10', '100', '2026-01-20'));
    $receipt = pl_inventory_receive($f['actor_id'], $f['company_id'], $f['book_id'], inventory_move_input($f, '10', '200') + ['warehouse_id' => $f['van_warehouse_id']]);
    $journal = pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], ledger_payload($f, '75'));
    $doc = ['id' => 9123, 'journal_id' => $journal['id'], 'document_date' => '2026-01-06', 'warehouse_id' => $f['van_warehouse_id'], 'lines' => [['product_id' => $f['product_id'], 'quantity' => '3']]];
    $issue = pl_inventory_issue_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], $doc, $journal['id'], 'sample-warehouse-invoice')[0];
    assert_same($f['van_warehouse_id'], $issue['warehouse_id']); assert_same('60.0000', $issue['value_base']);
    $return = inventory_move_input($f, '1', '0', '2026-01-07') + ['original_movement_id' => $issue['movement_id'], 'warehouse_id' => $f['default_warehouse_id']];
    assert_throws(fn() => pl_inventory_return($f['actor_id'], $f['company_id'], $f['book_id'], $return), DomainException::class, 'original warehouse');
    $credit = ['id' => 9124, 'kind' => 'customer_credit', 'journal_id' => $journal['id'], 'document_date' => '2026-01-07', 'lines' => [['product_id' => $f['product_id'], 'quantity' => '1']]];
    $credited = pl_inventory_credit_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], $credit, $doc, 'sample-warehouse-credit');
    assert_same($f['van_warehouse_id'], $credited[0]['warehouse_id']); assert_same('20.0000', $credited[0]['value_base']);
    pl_inventory_reverse_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], $credit, '2026-01-08', 'sample-credit-reverse', 'Sample reversal');
    pl_inventory_reverse_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], $doc, '2026-01-08', 'sample-invoice-reverse', 'Sample reversal');
    assert_same('200.0000', location_balance($f, $f['van_warehouse_id'])['value_base']);
    assert_same('100.0000', location_balance($f, $f['default_warehouse_id'])['value_base']);
    $purchaseReturn = inventory_move_input($f, '1', '0', '2026-01-09') + ['original_movement_id' => $receipt['movement_id']];
    assert_same($f['van_warehouse_id'], pl_inventory_return($f['actor_id'], $f['company_id'], $f['book_id'], $purchaseReturn)['warehouse_id']);
    assert_same('180.0000', location_balance($f, $f['van_warehouse_id'])['value_base']);
});

test('stock locations: reviewed count and value changes use the selected warehouse snapshot', function (): void {
    $f = location_fixture();
    pl_inventory_receive($f['actor_id'], $f['company_id'], $f['book_id'], inventory_move_input($f));
    pl_inventory_receive($f['actor_id'], $f['company_id'], $f['book_id'], inventory_move_input($f, '2', '40') + ['warehouse_id' => $f['van_warehouse_id']]);
    $input = inventory_move_input($f, '0', '0', '2026-01-06') + ['warehouse_id' => $f['van_warehouse_id'], 'expected_quantity' => '2', 'counted_quantity' => '1'];
    $preview = pl_preview_inventory_count($f['actor_id'], $f['company_id'], $f['book_id'], $input);
    assert_same('-20.0000', $preview['effect']['value_delta']); assert_same('2.0000', $preview['recorded']['quantity']);
    $confirmed = pl_confirm_inventory_count($f['actor_id'], $f['company_id'], $f['book_id'], $input, hash('sha256', json_encode($preview, JSON_THROW_ON_ERROR)));
    assert_same('-20.0000', $confirmed['value_delta']); assert_same($f['van_warehouse_id'], $confirmed['warehouse_id']);
    $input = inventory_move_input($f, '0', '-5', '2026-01-07') + ['warehouse_id' => $f['van_warehouse_id'], 'expected_quantity' => '1', 'expected_value_base' => '20'];
    assert_same('-5.0000', pl_inventory_value_adjustment($f['actor_id'], $f['company_id'], $f['book_id'], $input)['value_delta']);
    assert_same('15.0000', location_balance($f, $f['van_warehouse_id'])['value_base']);
    assert_same('100.0000', location_balance($f, $f['default_warehouse_id'])['value_base']);
});

test('stock locations: racing transfer retries and competing transfers retain one stock balance', function (): void {
    $f = location_fixture();
    pl_inventory_receive($f['actor_id'], $f['company_id'], $f['book_id'], inventory_move_input($f));
    $job = ['fixture' => $f, 'input' => location_transfer_input($f, '6')];
    $results = location_transfer_race([$job, $job]); assert_same($results[0]['id'], $results[1]['id']);
    $one = ['fixture' => $f, 'input' => location_transfer_input($f, '3'), 'allow_domain_failure' => true];
    $two = ['fixture' => $f, 'input' => location_transfer_input($f, '3'), 'allow_domain_failure' => true];
    $results = location_transfer_race([$one, $two]);
    assert_same(1, count(array_filter($results, static fn (array $r): bool => $r['id'] > 0)));
    assert_same('1.0000', location_balance($f, $f['default_warehouse_id'])['quantity']);
    assert_same('9.0000', location_balance($f, $f['van_warehouse_id'])['quantity']);
    assert_same('0.0000', pl_inventory_valuation($f['actor_id'], $f['company_id'], $f['book_id'], '2026-01-07')['accounts'][0]['difference']);
});

test('stock locations: movements and retry hashes recorded before the module keep the default warehouse', function (): void {
    $f = location_fixture();
    $input = inventory_move_input($f);
    $data = pl_inventory_movement_input($input);
    assert_true(!array_key_exists('warehouse_id', $data));
    // Pre-module shape: no warehouse column value and the old command result and hash.
    $journal = pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], ['date' => $input['date'], 'currency' => 'USD', 'source_type' => 'inventory_movement',
        'source_reference' => 'sample-legacy-stock', 'idempotency_key' => bin2hex(random_bytes(16)), 'description' => 'Sample legacy stock fixture',
        'lines' => [['account_id' => $f['inventory_account_id'], 'debit' => '100', 'credit' => '0'], ['account_id' => $f['grni_account_id'], 'debit' => '0', 'credit' => '100']]]);
    DB::insert('pl_inventory_movements', ['company_id' => $f['company_id'], 'book_id' => $f['book_id'], 'product_id' => $f['product_id'], 'movement_date' => $input['date'], 'kind' => 'receipt',
        'quantity_delta' => '10.0000', 'value_delta' => '100.0000', 'inventory_account_id' => $f['inventory_account_id'], 'offset_account_id' => $f['grni_account_id'], 'journal_id' => $journal['id'],
        'source_type' => $input['source_type'], 'source_reference' => $input['source_reference'], 'reason' => $input['reason'], 'created_by' => $f['actor_id']]);
    $id = (int) DB::insertId();
    $oldResult = ['movement_id' => $id, 'journal_id' => $journal['id']];
    DB::insert('pl_inventory_commands', ['company_id' => $f['company_id'], 'book_id' => $f['book_id'], 'request_key' => $input['idempotency_key'],
        'payload_hash' => hash('sha256', json_encode([$f['actor_id'], ['receipt', $data, '10.0000', '100.0000']], JSON_THROW_ON_ERROR)), 'result_json' => json_encode($oldResult, JSON_THROW_ON_ERROR), 'actor_id' => $f['actor_id']]);
    location_assert_retry($oldResult, pl_inventory_receive($f['actor_id'], $f['company_id'], $f['book_id'], $input));
    assert_same('100.0000', location_balance($f, $f['default_warehouse_id'])['value_base']);
    assert_same('0.0000', location_balance($f, $f['van_warehouse_id'])['value_base']);
    $history = pl_inventory_history($f['actor_id'], $f['company_id'], $f['book_id']);
    assert_same($f['default_warehouse_id'], (int) $history[0]['warehouse_id']);
    assert_same('10.0000', pl_inventory_transfer($f['actor_id'], $f['company_id'], $f['book_id'], location_transfer_input($f))['value_base']);
    assert_same(null, DB::queryFirstField('SELECT warehouse_id FROM pl_inventory_movements WHERE id=%i', $id));
    assert_same('0.0000', pl_inventory_valuation($f['actor_id'], $f['company_id'], $f['book_id'], '2026-01-06')['accounts'][0]['difference']);
});

test('stock locations: opening allocations split one product across warehouses without a second GL', function (): void {
    $f = location_fixture();
    DB::update('pl_companies', ['setup_status' => 'opening_required'], 'id=%i', $f['company_id']);
    $opening = pl_preview_opening($f['actor_id'], $f['company_id'], $f['book_id'], ['cutover_date' => '2026-01-01', 'source' => 'Sample warehouse cutover', 'unpaid_documents' => [],
        'balances' => [['account_code' => '1300', 'debit' => '100', 'credit' => '0'], ['account_code' => '3000', 'debit' => '0', 'credit' => '100']]], bin2hex(random_bytes(16)));
    pl_confirm_opening($f['actor_id'], $f['company_id'], $f['book_id'], (int) $opening['id'], $opening['payload_hash'], true);
    $input = ['date' => '2026-01-01', 'reason' => 'Sample warehouse opening evidence', 'lines' => [
        ['product_id' => $f['product_id'], 'warehouse_id' => $f['default_warehouse_id'], 'quantity' => '6', 'amount_base' => '40'],
        ['product_id' => $f['product_id'], 'warehouse_id' => $f['van_warehouse_id'], 'quantity' => '4', 'amount_base' => '60']]];
    $preview = pl_preview_inventory_opening($f['actor_id'], $f['company_id'], $f['book_id'], $input);
    $result = pl_confirm_inventory_opening($f['actor_id'], $f['company_id'], $f['book_id'], $preview['id'], $preview['payload_hash'], true, bin2hex(random_bytes(16)));
    assert_same(2, count($result['movement_ids'])); assert_same(false, $result['journal_created']);
    assert_same('40.0000', location_balance($f, $f['default_warehouse_id'])['value_base']);
    assert_same('60.0000', location_balance($f, $f['van_warehouse_id'])['value_base']);
    assert_same(1, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id=%i', $f['book_id']));
    assert_same('0.0000', pl_inventory_valuation($f['actor_id'], $f['company_id'], $f['book_id'], '2026-01-01')['accounts'][0]['difference']);
});

test('stock locations: transfer retries reject a changed source destination date or metadata', function (): void {
    $f = location_fixture();
    pl_inventory_receive($f['actor_id'], $f['company_id'], $f['book_id'], inventory_move_input($f));
    $third = pl_save_inventory_warehouse($f['actor_id'], $f['company_id'], $f['book_id'], location_warehouse_input('THIRD'));
    $input = location_transfer_input($f);
    pl_inventory_transfer($f['actor_id'], $f['company_id'], $f['book_id'], $input);
    foreach (['from_warehouse_id' => $third['id'], 'to_warehouse_id' => $third['id'], 'date' => '2026-01-07', 'quantity' => '2', 'source_reference' => 'changed-source', 'reason' => 'Changed explanation'] as $field => $value) {
        $changed = array_replace($input, [$field => $value]);
        assert_throws(fn() => pl_inventory_transfer($f['actor_id'], $f['company_id'], $f['book_id'], $changed), DomainException::class, 'different content');
    }
});
