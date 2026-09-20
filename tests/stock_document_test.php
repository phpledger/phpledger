<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/www/phpledger/includes/functions/web_functions.php';
require_once dirname(__DIR__) . '/www/phpledger/includes/functions/print_functions.php';

// Numbered stock documents, vans, the driver's day and the by-location reports (1.2 M4).
// Registered after inventory_location_test.php by tests/run.php.

function stock_document_fixture(): array
{
    $f = location_fixture();
    $args = [$f['actor_id'], $f['company_id'], $f['book_id']];
    // The fixture's SYNTH-VAN is a fixed location; this milestone's van is a mobile one.
    $van = pl_save_inventory_warehouse(...array_merge($args, [[
        'code' => 'VAN-1', 'name' => 'Van 1', 'kind' => 'mobile', 'driver_name' => 'Sample driver',
        'vehicle_reference' => 'SAMPLE-4471', 'route_name' => 'Sample route', 'is_active' => true,
        'reason' => 'Sample van', 'idempotency_key' => bin2hex(random_bytes(16))]]));
    $second = pl_save_inventory_product(...array_merge($args, [array_replace(inventory_product_input($f), ['sku' => 'SYNTH-2ND-' . bin2hex(random_bytes(4)), 'name' => 'Sample second item'])]));
    $f['second_product_id'] = (int) $second['id'];
    // Stock the warehouse so it can issue: both products, dated before the day under test.
    pl_inventory_receive(...array_merge($args, [inventory_move_input($f, '100', '1000', '2026-01-01')]));
    pl_inventory_receive(...array_merge($args, [array_replace(inventory_move_input($f, '40', '800', '2026-01-01'), ['product_id' => $f['second_product_id']])]));
    return $f + ['van_id' => (int) $van['id']];
}

function stock_document_input(array $f, string $kind = 'stock_issue', array $overrides = []): array
{
    $issue = $kind === 'stock_return';
    return array_replace([
        'kind' => $kind, 'date' => '2026-01-06',
        'from_warehouse_id' => $issue ? $f['van_id'] : $f['default_warehouse_id'],
        'to_warehouse_id' => $issue ? $f['default_warehouse_id'] : $f['van_id'],
        'reference' => 'Sample load', 'reason' => 'Sample stock document',
        'lines' => [['product_id' => $f['product_id'], 'quantity' => '10']],
        'idempotency_key' => bin2hex(random_bytes(16)),
    ], $overrides);
}

function stock_document_counts(array $f): array
{
    return [
        'journals' => (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id=%i', $f['book_id']),
        'movements' => (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_inventory_movements WHERE book_id=%i', $f['book_id']),
    ];
}

test('stock documents: each kind takes its own series and a number is never reused', function (): void {
    $f = stock_document_fixture();
    $args = [$f['actor_id'], $f['company_id'], $f['book_id']];
    $first = pl_post_stock_document(...array_merge($args, [stock_document_input($f)]));
    $second = pl_post_stock_document(...array_merge($args, [stock_document_input($f, 'stock_issue', ['lines' => [['product_id' => $f['product_id'], 'quantity' => '5']]])]));
    assert_same('ISS-2026-000001', $first['document_number']);
    assert_same('ISS-2026-000002', $second['document_number']);
    $reissue = pl_post_stock_document(...array_merge($args, [stock_document_input($f, 'stock_reissue', ['original_document_id' => $first['id'], 'lines' => [['product_id' => $f['second_product_id'], 'quantity' => '4']]])]));
    assert_same('RISS-2026-000001', $reissue['document_number'], 'A re-issue must take its own per-kind series.');
    $return = pl_post_stock_document(...array_merge($args, [stock_document_input($f, 'stock_return', ['lines' => [['product_id' => $f['product_id'], 'quantity' => '3']]])]));
    assert_same('RTN-2026-000001', $return['document_number']);
    $pass = pl_issue_gate_pass(...array_merge($args, [['date' => '2026-01-06', 'warehouse_id' => $f['default_warehouse_id'], 'direction' => 'out',
        'is_returnable' => true, 'expected_return_date' => '2026-01-06', 'covers_document_id' => $first['id'], 'purpose' => 'Sample outward pass',
        'reason' => 'Sample gate pass', 'idempotency_key' => bin2hex(random_bytes(16))]]));
    assert_same('GP-2026-000001', $pass['document_number']);
    // Every number is recorded once, in the immutable 035 allocation table, and is unique.
    $numbers = DB::queryFirstColumn('SELECT number FROM pl_document_numbers WHERE book_id=%i ORDER BY number', $f['book_id']);
    assert_same(count($numbers), count(array_unique($numbers)));
    assert_same(['GP-2026-000001','ISS-2026-000001','ISS-2026-000002','RISS-2026-000001','RTN-2026-000001'], $numbers);
    assert_throws(fn() => DB::update('pl_stock_documents', ['document_number' => 'ISS-2026-000099'], 'id=%i', $first['id']), Throwable::class);
    assert_throws(fn() => DB::update('pl_stock_documents', ['document_date' => '2026-01-07'], 'id=%i', $first['id']), Throwable::class, 'immutable');
    assert_throws(fn() => DB::query('DELETE FROM pl_stock_documents WHERE id=%i', $first['id']), Throwable::class);
    assert_throws(fn() => DB::update('pl_stock_document_lines', ['quantity' => '1'], 'document_id=%i', $first['id']), Throwable::class, 'immutable');
    // A retry of the same request returns the same document rather than consuming a number.
    $input = stock_document_input($f, 'stock_issue', ['lines' => [['product_id' => $f['product_id'], 'quantity' => '2']]]);
    $once = pl_post_stock_document(...array_merge($args, [$input]));
    location_assert_retry($once, pl_post_stock_document(...array_merge($args, [$input])));
    assert_same(6, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_document_numbers WHERE book_id=%i', $f['book_id']));
});

test('stock documents: a gate pass moves no stock and posts nothing', function (): void {
    $f = stock_document_fixture();
    $args = [$f['actor_id'], $f['company_id'], $f['book_id']];
    $issue = pl_post_stock_document(...array_merge($args, [stock_document_input($f)]));
    $before = stock_document_counts($f);
    $vanBefore = location_balance($f, $f['van_id']);
    $pass = pl_issue_gate_pass(...array_merge($args, [['date' => '2026-01-06', 'warehouse_id' => $f['default_warehouse_id'], 'direction' => 'out',
        'is_returnable' => true, 'expected_return_date' => '2026-01-07', 'covers_document_id' => $issue['id'],
        'party_name' => 'Sample carrier', 'vehicle_reference' => 'SAMPLE-4471', 'driver_name' => 'Sample driver',
        'purpose' => 'Sample outward returnable pass', 'reason' => 'Sample gate pass', 'idempotency_key' => bin2hex(random_bytes(16))]]));
    assert_same($before, stock_document_counts($f), 'A gate pass created a journal or a stock movement.');
    assert_same($vanBefore, location_balance($f, $f['van_id']));
    assert_same([], $pass['lines']);
    assert_same(false, $pass['moves_stock']);
    assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_stock_document_lines WHERE document_id=%i', $pass['id']));
    assert_same($issue['id'], $pass['covers']['id']);
    assert_same($pass['id'], pl_get_stock_document(...array_merge($args, [$issue['id']]))['gate_passes'][0]['id']);
    // The security desk stamps once, and only in a direction the pass has.
    $stamped = pl_stamp_gate_pass(...array_merge($args, [$pass['id'], 'out']));
    assert_true($stamped['gate_pass']['security_out_at'] !== null);
    assert_throws(fn() => pl_stamp_gate_pass(...array_merge($args, [$pass['id'], 'out'])), DomainException::class, 'already stamped');
    assert_throws(fn() => pl_stamp_gate_pass(...array_merge($args, [$issue['id'], 'out'])), DomainException::class, 'gate pass');
    assert_throws(fn() => pl_stamp_gate_pass(...array_merge($args, [$pass['id'], 'sideways'])), DomainException::class);
    assert_same($before, stock_document_counts($f), 'Stamping a gate pass changed stock or the ledger.');
    // A pass references a moving document at one of its own locations, never another pass.
    assert_throws(fn() => pl_issue_gate_pass(...array_merge($args, [['date' => '2026-01-06', 'warehouse_id' => $f['default_warehouse_id'], 'direction' => 'out',
        'is_returnable' => false, 'covers_document_id' => $pass['id'], 'purpose' => 'x', 'reason' => 'x', 'idempotency_key' => bin2hex(random_bytes(16))]])), DomainException::class, 'not another gate pass');
    assert_throws(fn() => pl_issue_gate_pass(...array_merge($args, [['date' => '2026-01-06', 'warehouse_id' => $f['van_warehouse_id'], 'direction' => 'out',
        'is_returnable' => false, 'covers_document_id' => $issue['id'], 'purpose' => 'x', 'reason' => 'x', 'idempotency_key' => bin2hex(random_bytes(16))]])), DomainException::class, 'one of the locations');
    // A returnable pass states when the goods come back; a non-returnable one never does.
    assert_throws(fn() => pl_issue_gate_pass(...array_merge($args, [['date' => '2026-01-06', 'warehouse_id' => $f['default_warehouse_id'], 'direction' => 'out',
        'is_returnable' => true, 'covers_document_id' => $issue['id'], 'purpose' => 'x', 'reason' => 'x', 'idempotency_key' => bin2hex(random_bytes(16))]])), DomainException::class, 'expected back');
});

test('stock documents: a van is a mobile warehouse and its kind is permanent', function (): void {
    $f = stock_document_fixture();
    $args = [$f['actor_id'], $f['company_id'], $f['book_id']];
    $van = pl_get_inventory_warehouse(...array_merge($args, [$f['van_id']]));
    assert_same('mobile', $van['kind']); assert_same(true, $van['is_mobile']);
    assert_same('Sample driver', $van['driver_name']); assert_same('Sample route', $van['route_name']);
    assert_same(false, $van['is_default']);
    // The book default is unchanged: still fixed, still the implicit warehouse.
    $default = pl_inventory_default_warehouse(...$args);
    assert_same('fixed', $default['kind']); assert_same(true, $default['is_default']); assert_same(null, $default['driver_name']);
    assert_same($default['id'], pl_inventory_receive(...array_merge($args, [inventory_move_input($f, '1', '10', '2026-01-02')]))['warehouse_id']);
    // A van names its driver, and no location changes kind afterwards.
    assert_throws(fn() => pl_save_inventory_warehouse(...array_merge($args, [['code' => 'VAN-2', 'name' => 'Van 2', 'kind' => 'mobile',
        'is_active' => true, 'reason' => 'x', 'idempotency_key' => bin2hex(random_bytes(16))]])), DomainException::class, 'driver');
    assert_throws(fn() => pl_save_inventory_warehouse(...array_merge($args, [['code' => 'VAN-3', 'name' => 'Van 3', 'kind' => 'aircraft',
        'is_active' => true, 'reason' => 'x', 'idempotency_key' => bin2hex(random_bytes(16))]])), DomainException::class, 'warehouse or a van');
    assert_throws(fn() => pl_save_inventory_warehouse(...array_merge($args, [['code' => 'VAN-1', 'name' => 'Van 1', 'kind' => 'fixed',
        'is_active' => true, 'reason' => 'x', 'idempotency_key' => bin2hex(random_bytes(16))], $f['van_id'], 1])), DomainException::class, 'cannot change');
    assert_throws(fn() => DB::update('pl_inventory_warehouses', ['kind' => 'mobile'], 'id=%i', $f['default_warehouse_id']), Throwable::class);
    // A stock issue loads a van, never another warehouse, and a return goes the other way.
    assert_throws(fn() => pl_post_stock_document(...array_merge($args, [stock_document_input($f, 'stock_issue', ['to_warehouse_id' => $f['van_warehouse_id']])])), DomainException::class, 'must be a van');
    assert_throws(fn() => pl_post_stock_document(...array_merge($args, [stock_document_input($f, 'stock_return', ['from_warehouse_id' => $f['default_warehouse_id'], 'to_warehouse_id' => $f['van_warehouse_id']])])), DomainException::class, 'must be a van');
});

test('stock documents: transfers still move carrying value and write no journal', function (): void {
    $f = stock_document_fixture();
    $args = [$f['actor_id'], $f['company_id'], $f['book_id']];
    $journals = stock_document_counts($f)['journals'];
    $warehouseBefore = location_balance($f, $f['default_warehouse_id']);
    $aggregateBefore = pl_inventory_balance(...array_merge($args, [$f['product_id']]));
    $issue = pl_post_stock_document(...array_merge($args, [stock_document_input($f)]));
    assert_same($journals, stock_document_counts($f)['journals'], 'A stock issue posted a journal.');
    assert_same('10.0000', $issue['total_quantity']);
    // 100 units carrying 1000 means 10 per unit: 10 units carry exactly 100.
    assert_same('100.0000', $issue['total_value_base']);
    assert_same('100.0000', location_balance($f, $f['van_id'])['value_base']);
    assert_same(bcsub($warehouseBefore['value_base'], '100.0000', 4), location_balance($f, $f['default_warehouse_id'])['value_base']);
    // The aggregate across every location is untouched: nothing left the company.
    assert_same($aggregateBefore['quantity'], pl_inventory_balance(...array_merge($args, [$f['product_id']]))['quantity']);
    assert_same($aggregateBefore['value_base'], pl_inventory_balance(...array_merge($args, [$f['product_id']]))['value_base']);
    // Each line names the exact movement pair it created, and those are ordinary transfers.
    $line = $issue['lines'][0];
    $out = DB::queryFirstRow('SELECT kind,warehouse_id,journal_id,quantity_delta FROM pl_inventory_movements WHERE id=%i', $line['out_movement_id']);
    $in = DB::queryFirstRow('SELECT kind,warehouse_id,journal_id,quantity_delta FROM pl_inventory_movements WHERE id=%i', $line['in_movement_id']);
    assert_same('transfer_out', $out['kind']); assert_same('transfer_in', $in['kind']);
    assert_same(null, $out['journal_id']); assert_same(null, $in['journal_id']);
    assert_same($f['default_warehouse_id'], (int) $out['warehouse_id']); assert_same($f['van_id'], (int) $in['warehouse_id']);
    assert_same('0.0000', bcadd($out['quantity_delta'], $in['quantity_delta'], 4));
    // More than the source holds is refused, and nothing is left behind.
    $counts = stock_document_counts($f);
    assert_throws(fn() => pl_post_stock_document(...array_merge($args, [stock_document_input($f, 'stock_issue', ['lines' => [['product_id' => $f['product_id'], 'quantity' => '9999']]])])), DomainException::class);
    assert_same($counts, stock_document_counts($f));
    assert_same(1, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_stock_documents WHERE book_id=%i', $f['book_id']));
});

test("stock documents: the driver's day loads, sells, re-issues, returns and settles", function (): void {
    $f = stock_document_fixture();
    $args = [$f['actor_id'], $f['company_id'], $f['book_id']];
    $day = '2026-01-06';
    // Morning load, with its outward gate pass.
    $load = pl_post_stock_document(...array_merge($args, [stock_document_input($f, 'stock_issue', ['date' => $day,
        'lines' => [['product_id' => $f['product_id'], 'quantity' => '20'], ['product_id' => $f['second_product_id'], 'quantity' => '8']]])]));
    pl_issue_gate_pass(...array_merge($args, [['date' => $day, 'warehouse_id' => $f['default_warehouse_id'], 'direction' => 'out',
        'is_returnable' => true, 'expected_return_date' => $day, 'covers_document_id' => $load['id'], 'driver_name' => 'Sample driver',
        'purpose' => 'Morning load', 'reason' => 'Sample outward pass', 'idempotency_key' => bin2hex(random_bytes(16))]]));
    // On the road: sales out of the van, and a sellable customer return back into it.
    $sale = array_replace(inventory_move_input($f, '12', '0', $day), ['warehouse_id' => $f['van_id']]);
    unset($sale['offset_account_id']);
    $sold = pl_inventory_issue(...array_merge($args, [$sale]));
    pl_inventory_return(...array_merge($args, [['original_movement_id' => $sold['movement_id'], 'quantity' => '2', 'date' => $day,
        'warehouse_id' => $f['van_id'], 'source_type' => 'test_van_return', 'source_reference' => bin2hex(random_bytes(16)),
        'reason' => 'Sellable return re-entering van stock', 'idempotency_key' => bin2hex(random_bytes(16))]]));
    // Mid-day re-issue against the morning's load.
    pl_post_stock_document(...array_merge($args, [stock_document_input($f, 'stock_reissue', ['date' => $day,
        'original_document_id' => $load['id'], 'lines' => [['product_id' => $f['product_id'], 'quantity' => '6']]])]));
    // End of route: unsold stock goes back to the warehouse.
    pl_post_stock_document(...array_merge($args, [stock_document_input($f, 'stock_return', ['date' => $day,
        'lines' => [['product_id' => $f['second_product_id'], 'quantity' => '8']]])]));
    $sheet = pl_van_day(...array_merge($args, [$f['van_id'], $day]));
    assert_same(true, $sheet['reconciles'], 'The driver\'s day does not reconcile.');
    assert_same('34.0000', $sheet['totals']['loaded']);
    assert_same('12.0000', $sheet['totals']['sold']);
    assert_same('8.0000', $sheet['totals']['returned']);
    assert_same('2.0000', $sheet['totals']['customer_returns']);
    $first = null;
    foreach ($sheet['products'] as $product) { if ($product['product_id'] === $f['product_id']) { $first = $product; } }
    assert_true($first !== null);
    assert_same('26.0000', $first['loaded']); assert_same('12.0000', $first['sold']); assert_same('0.0000', $first['returned']);
    assert_same('16.0000', $first['closing']); assert_same($first['expected_closing'], $first['closing']);
    assert_same('16.0000', location_balance($f, $f['van_id'], $day)['quantity']);
    // Reviewing and approving post nothing.
    $counts = stock_document_counts($f);
    $review = pl_review_van_settlement(...array_merge($args, [$f['van_id'], $day, 'Sample settlement review', bin2hex(random_bytes(16))]));
    assert_same('reviewed', $review['status']); assert_same(true, $review['reconciles']);
    $approved = pl_approve_van_settlement(...array_merge($args, [$review['id'], 'Sample approval', bin2hex(random_bytes(16))]));
    assert_same('approved', $approved['status']); assert_same($f['actor_id'], $approved['approved_by']);
    assert_same($counts, stock_document_counts($f), 'A settlement posted a journal or moved stock.');
    // An approved settlement is immutable and cannot be reviewed again.
    assert_throws(fn() => pl_review_van_settlement(...array_merge($args, [$f['van_id'], $day, 'Again', bin2hex(random_bytes(16))])), DomainException::class, 'already approved');
    assert_throws(fn() => pl_approve_van_settlement(...array_merge($args, [$review['id'], 'Again', bin2hex(random_bytes(16))])), DomainException::class, 'already approved');
    assert_throws(fn() => DB::update('pl_van_settlements', ['reason' => 'changed'], 'id=%i', $review['id']), Throwable::class, 'immutable');
    // Only a van has a driver's day.
    assert_throws(fn() => pl_van_day(...array_merge($args, [$f['default_warehouse_id'], $day])), DomainException::class, 'mobile stock location');
});

test('stock documents: approving a settlement needs the approval permission and a day that reconciles', function (): void {
    $f = stock_document_fixture();
    $args = [$f['actor_id'], $f['company_id'], $f['book_id']];
    $day = '2026-01-06';
    pl_post_stock_document(...array_merge($args, [stock_document_input($f, 'stock_issue', ['date' => $day])]));
    // An adjustment on the van is a movement the load, sales and returns do not explain.
    pl_inventory_adjust_count(...array_merge($args, [array_replace(inventory_move_input($f, '0', '0', $day), [
        'warehouse_id' => $f['van_id'], 'counted_quantity' => '9', 'expected_quantity' => '10', 'offset_account_id' => $f['variance_account_id'],
        'source_type' => 'test_van_count', 'source_reference' => bin2hex(random_bytes(16)), 'idempotency_key' => bin2hex(random_bytes(16))])]));
    $sheet = pl_van_day(...array_merge($args, [$f['van_id'], $day]));
    assert_same(false, $sheet['reconciles']);
    assert_same('-1.0000', $sheet['products'][0]['variance']);
    $review = pl_review_van_settlement(...array_merge($args, [$f['van_id'], $day, 'Sample short van', bin2hex(random_bytes(16))]));
    assert_same(false, $review['reconciles']);
    assert_throws(fn() => pl_approve_van_settlement(...array_merge($args, [$review['id'], 'Approve anyway', bin2hex(random_bytes(16))])), DomainException::class, 'does not reconcile');
    // An accountant may record stock documents but may not approve the settlement.
    $accountant = pl_create_user('stock-accountant-' . bin2hex(random_bytes(8)) . '@example.test', 'Sample stock accountant', 'Sample-test-password-' . bin2hex(random_bytes(8)));
    DB::insert('pl_company_members', ['company_id' => $f['company_id'], 'user_id' => $accountant, 'role' => 'accountant']);
    $byAccountant = pl_post_stock_document($accountant, $f['company_id'], $f['book_id'], stock_document_input($f, 'stock_issue', ['date' => $day, 'lines' => [['product_id' => $f['second_product_id'], 'quantity' => '2']]]));
    assert_true($byAccountant['document_number'] !== null);
    assert_throws(fn() => pl_approve_van_settlement($accountant, $f['company_id'], $f['book_id'], $review['id'], 'Accountant approval', bin2hex(random_bytes(16))), DomainException::class, 'approval permission');
});

test('stock documents: the location reports reconcile to the movements and to the ledger', function (): void {
    $f = stock_document_fixture();
    $args = [$f['actor_id'], $f['company_id'], $f['book_id']];
    pl_post_stock_document(...array_merge($args, [stock_document_input($f, 'stock_issue', ['date' => '2026-01-06',
        'lines' => [['product_id' => $f['product_id'], 'quantity' => '20'], ['product_id' => $f['second_product_id'], 'quantity' => '8']]])]));
    $report = pl_stock_by_location(...array_merge($args, ['2026-01-06']));
    assert_same(true, $report['cost_visible']);
    assert_same(true, $report['reconciles'], 'The per-location totals do not add up to the aggregate valuation.');
    assert_same('0.0000', $report['difference']);
    // Warehouses come before vans; each group's total is the sum of its own balances.
    $kinds = array_map(static fn (array $group): string => $group['warehouse']['kind'], $report['groups']);
    assert_same(array_values(array_unique($kinds)), array_values(array_filter(['fixed', 'mobile'], static fn (string $kind): bool => in_array($kind, $kinds, true))));
    foreach ($report['groups'] as $group) {
        $quantity = '0.0000'; $value = '0.0000';
        foreach ($group['rows'] as $row) {
            $balance = pl_inventory_balance(...array_merge($args, [$row['product_id'], '2026-01-06', $group['warehouse']['id']]));
            assert_same($balance['quantity'], $row['quantity'], 'A report row differs from the stock balance it reports.');
            assert_same($balance['value_base'], $row['value_base']);
            $quantity = bcadd($quantity, $row['quantity'], 4); $value = bcadd($value, $row['value_base'], 4);
        }
        assert_same($quantity, $group['totals']['quantity']); assert_same($value, $group['totals']['value_base']);
    }
    $valuation = pl_inventory_valuation(...array_merge($args, ['2026-01-06']));
    assert_same($valuation['total_value_base'], $report['totals']['value_base']);
    $aggregate = pl_stock_location_aggregate(...array_merge($args, ['2026-01-06']));
    assert_same(true, $aggregate['reconciles']);
    assert_same($aggregate['total_value_base'], $aggregate['per_location_value_base']);
    // The aggregate is the only view that ties to the inventory control accounts.
    assert_same(true, $aggregate['gl_reconciliation_available']);
    foreach ($aggregate['accounts'] as $account) { assert_same('0.0000', $account['difference'], 'Stock value and its ledger account disagree.'); }
    // Van stock is the same report filtered to mobile locations.
    $vans = pl_van_stock_report(...array_merge($args, ['2026-01-06']));
    assert_same('mobile', $vans['locations']);
    assert_same(false, $vans['reconciliation_available']);
    foreach ($vans['groups'] as $group) { assert_same('mobile', $group['warehouse']['kind']); }
    assert_same('28.0000', $vans['totals']['quantity']);
    foreach ([['locations' => 'orbit'], ['as_of' => 'not-a-date']] as $bad) {
        assert_throws(fn() => pl_stock_by_location(...array_merge($args, [$bad['as_of'] ?? '2026-01-06', $bad['locations'] ?? 'all'])), DomainException::class);
    }
    // Cost is a separate permission: an accountant reads the report without cost columns.
    $accountant = pl_create_user('stock-costs-' . bin2hex(random_bytes(8)) . '@example.test', 'Sample cost reader', 'Sample-test-password-' . bin2hex(random_bytes(8)));
    DB::insert('pl_company_members', ['company_id' => $f['company_id'], 'user_id' => $accountant, 'role' => 'accountant']);
    $hidden = pl_stock_by_location($accountant, $f['company_id'], $f['book_id'], '2026-01-06');
    assert_same(false, $hidden['cost_visible']);
    assert_same(null, $hidden['totals']['value_base']);
    assert_same([], $hidden['accounts']);
    foreach ($hidden['groups'] as $group) {
        assert_same(null, $group['totals']['value_base']);
        foreach ($group['rows'] as $row) { assert_same(null, $row['value_base']); }
    }
    assert_same($report['totals']['quantity'], $hidden['totals']['quantity'], 'Hiding cost changed the quantities.');
});

test('stock documents: the module can be disabled and re-enabled with van stock present', function (): void {
    $f = stock_document_fixture();
    $args = [$f['actor_id'], $f['company_id'], $f['book_id']];
    $issue = pl_post_stock_document(...array_merge($args, [stock_document_input($f, 'stock_issue', ['date' => '2026-01-06'])]));
    $manifest = pl_module_registry()['inventory-locations'];
    $revision = pl_module_state($f['company_id'], 'inventory-locations')['revision'];
    pl_set_company_module($f['actor_id'], $f['company_id'], 'inventory-locations', false, $revision, $manifest['digest'], 'Sample disable with van stock', bin2hex(random_bytes(16)));
    // Nothing is deleted, hidden or moved to the default warehouse.
    assert_same('10.0000', location_balance($f, $f['van_id'])['quantity']);
    assert_same($issue['document_number'], pl_get_stock_document(...array_merge($args, [$issue['id']]))['document_number']);
    assert_same(1, count(pl_list_stock_documents(...array_merge($args, [[]]))));
    assert_same(true, pl_stock_by_location(...array_merge($args, ['2026-01-06']))['reconciles']);
    assert_same(1, count(pl_list_stock_transfers(...array_merge($args, [[]]))), 'Historical transfers stopped being readable.');
    // New writes against the module are refused while it is off.
    assert_throws(fn() => pl_post_stock_document(...array_merge($args, [stock_document_input($f, 'stock_issue', ['date' => '2026-01-07'])])), DomainException::class, 'disabled');
    assert_throws(fn() => pl_review_van_settlement(...array_merge($args, [$f['van_id'], '2026-01-06', 'x', bin2hex(random_bytes(16))])), DomainException::class, 'disabled');
    // Stock that never leaves the default warehouse keeps working without the module.
    assert_same($f['default_warehouse_id'], pl_inventory_receive(...array_merge($args, [inventory_move_input($f, '1', '10', '2026-01-08')]))['warehouse_id']);
    pl_set_company_module($f['actor_id'], $f['company_id'], 'inventory-locations', true, $revision + 1, $manifest['digest'], 'Sample re-enable', bin2hex(random_bytes(16)));
    $again = pl_post_stock_document(...array_merge($args, [stock_document_input($f, 'stock_issue', ['date' => '2026-01-09'])]));
    assert_same('ISS-2026-000002', $again['document_number'], 'The series did not continue after a disable and re-enable.');
    assert_same('20.0000', location_balance($f, $f['van_id'])['quantity']);
});

test('stock documents: print templates follow the record screen and its role rule', function (): void {
    $f = stock_document_fixture();
    $args = [$f['actor_id'], $f['company_id'], $f['book_id']];
    $issue = pl_post_stock_document(...array_merge($args, [stock_document_input($f)]));
    $pass = pl_issue_gate_pass(...array_merge($args, [['date' => '2026-01-06', 'warehouse_id' => $f['default_warehouse_id'], 'direction' => 'out',
        'is_returnable' => true, 'expected_return_date' => '2026-01-07', 'covers_document_id' => $issue['id'],
        'purpose' => 'Sample pass', 'reason' => 'Sample gate pass', 'idempotency_key' => bin2hex(random_bytes(16))]]));
    // Both types are registered with the formats the research decision names.
    $templates = pl_print_templates();
    assert_true(isset($templates['stock-issue']['formats']['a4'], $templates['stock-issue']['formats']['80mm']));
    assert_true(isset($templates['gate-pass']['formats']['a4']));
    assert_same('a4', pl_print_template('stock-issue')['format']);
    assert_same('stock-issue-80mm.php', pl_print_template('stock-issue', '80mm')['view']);
    assert_throws(fn() => pl_print_template('stock-issue', 'a3'), DomainException::class, 'paper formats');
    assert_same($issue['document_number'], pl_print_stock_document_reference(pl_stock_document_print(...array_merge($args, [$issue['id']]))));
    assert_same($pass['document_number'], pl_print_stock_document_reference(pl_gate_pass_print(...array_merge($args, [$pass['id']]))));
    // Each loader refuses the other's documents rather than guessing.
    assert_throws(fn() => pl_stock_document_print(...array_merge($args, [$pass['id']])), DomainException::class, 'gate pass');
    assert_throws(fn() => pl_gate_pass_print(...array_merge($args, [$issue['id']])), DomainException::class, 'not a gate pass');
    // Printing reads only, and follows the record screen: owner, accountant and viewer read.
    $counts = stock_document_counts($f);
    $suffix = bin2hex(random_bytes(8));
    foreach (['accountant', 'viewer'] as $role) {
        $reader = pl_create_user('stock-print-' . $role . '-' . $suffix . '@example.test', 'Sample stock ' . $role, 'Sample-test-password-' . $suffix);
        DB::insert('pl_company_members', ['company_id' => $f['company_id'], 'user_id' => $reader, 'role' => $role]);
        assert_same($issue['document_number'], pl_stock_document_print($reader, $f['company_id'], $f['book_id'], $issue['id'])['document_number'], 'A ' . $role . ' could not print the record screen.');
    }
    $stranger = pl_create_user('stock-print-stranger-' . $suffix . '@example.test', 'Sample stock stranger', 'Sample-test-password-' . $suffix);
    assert_throws(fn() => pl_stock_document_print($stranger, $f['company_id'], $f['book_id'], $issue['id']), DomainException::class);
    $other = stock_document_fixture();
    assert_throws(fn() => pl_stock_document_print($other['actor_id'], $other['company_id'], $other['book_id'], $issue['id']), DomainException::class, 'not available');
    assert_same($counts, stock_document_counts($f), 'Printing changed stock or the ledger.');
});

test('stock documents: warehouses and transfers are readable over the API and MCP contract', function (): void {
    $f = stock_document_fixture();
    $args = [$f['actor_id'], $f['company_id'], $f['book_id']];
    $issue = pl_post_stock_document(...array_merge($args, [stock_document_input($f)]));
    $catalog = pl_read_catalog();
    assert_true(isset($catalog['warehouses'], $catalog['stock_transfers']), 'The read catalogue does not advertise stock locations.');
    // The catalogue drives the API, MCP and OpenAPI alike, so scopes need no second list.
    assert_true(!in_array('warehouses', pl_connection_read_operations(['access_mode' => 'reports']), true), 'A report-only connection can read individual locations.');
    assert_true(in_array('stock_transfers', pl_connection_read_operations(['access_mode' => 'full']), true));
    $warehouses = pl_list_inventory_warehouses(...$args);
    $van = null;
    foreach ($warehouses as $row) { if ($row['id'] === $f['van_id']) { $van = $row; } }
    assert_true($van !== null);
    assert_same('mobile', $van['kind']); assert_same('Sample driver', $van['driver_name']);
    $transfers = pl_list_stock_transfers(...array_merge($args, [[]]));
    assert_same(1, count($transfers));
    assert_same($issue['id'], $transfers[0]['document_id']);
    assert_same($issue['document_number'], $transfers[0]['document_number']);
    assert_same('10.0000', $transfers[0]['quantity']);
    assert_same($f['default_warehouse_id'], $transfers[0]['from_warehouse_id']);
    assert_same($f['van_id'], $transfers[0]['to_warehouse_id']);
    assert_same(0, count(pl_list_stock_transfers(...array_merge($args, [['from' => '2026-02-01']]))));
    assert_same(1, count(pl_list_stock_transfers(...array_merge($args, [['warehouse_id' => $f['van_id']]]))));
    assert_same(0, count(pl_list_stock_transfers(...array_merge($args, [['warehouse_id' => $f['van_warehouse_id']]]))));
    assert_throws(fn() => pl_list_stock_transfers(...array_merge($args, [['from' => 'not-a-date']])), DomainException::class);
    // Another company reads none of it.
    $other = stock_document_fixture();
    assert_throws(fn() => pl_list_stock_transfers($other['actor_id'], $f['company_id'], $f['book_id'], []), DomainException::class);
});

$stockRouter = (string) file_get_contents(dirname(__DIR__) . '/www/phpledger/public/index.php');

test('stock documents: the manifest declares the routes it now has', function () use ($stockRouter): void {
    $manifest = pl_module_registry()['inventory-locations'];
    assert_true(in_array('stock-by-location', $manifest['reports'], true), 'The declared report disappeared.');
    foreach ($manifest['routes'] as $route) {
        assert_true(str_contains($stockRouter, "'" . $route . "'"), 'The manifest declares ' . $route . ' but the router has no such route.');
    }
    assert_true(in_array('/reports/stock-by-location', $manifest['routes'], true), 'The declared stock-by-location report has no route.');
    foreach ($manifest['api_operations'] as $operation) {
        assert_true(isset(pl_read_catalog()[$operation]), 'The manifest declares the API operation ' . $operation . ', which the catalogue does not have.');
    }
    foreach ($manifest['settings'] as $series) {
        assert_true(isset(pl_document_series_types()[$series]), 'The manifest declares the series ' . $series . ', which has no catalogue entry.');
    }
    assert_true(in_array('037_stock_documents', $manifest['migrations'], true));
});
