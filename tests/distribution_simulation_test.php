<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/www/phpledger/includes/functions/web_functions.php';
require_once dirname(__DIR__) . '/tools/distribution-scenario.php';

/*
 * The distribution simulation: one distributor's full driver day, end to end (1.2 M9).
 *
 * The day itself is tools/distribution-scenario.php, shared with tools/simulate-distribution.php
 * so the printed walkthrough and this proof are the same day. What is here is the arithmetic.
 *
 * The figures are all decided by the fixture and are stated, not derived, so a change anywhere
 * in the stock, invoice, settlement or credit services moves one of them and fails here:
 *
 *   Product A  carrying value 2.0000 a unit, sold at 25.0000
 *   Product B  carrying value 3.0000 a unit, sold at 40.0000
 *
 *   loaded      60 A + 40 B in the morning, 20 A at the mid-day re-issue
 *   sold        20 A cash, then 10 A + 5 B and 10 B on credit
 *   returned    5 A back from a customer, into the van; the rest back to the warehouse
 *
 * The identity the sheet must satisfy, per item:
 *   opening + loaded + customer returns - sold - returned = closing
 */

function distribution_fixture(): array
{
    $f = counter_located_fixture();
    $args = [$f['actor_id'], $f['company_id'], $f['book_id']];
    $van = pl_save_inventory_warehouse(...array_merge($args, [['code' => 'VAN-SIM', 'name' => 'Route van 1', 'kind' => 'mobile',
        'driver_employee_id' => sample_assignment_employee($f['actor_id'],$f['company_id']), 'driver_name' => 'Sample driver', 'vehicle_reference' => 'SAMPLE-4471', 'route_name' => 'Sample route',
        'is_active' => true, 'reason' => 'Sample distributor van', 'idempotency_key' => bin2hex(random_bytes(16))]]));
    // Product B, at a carrying value of 3.0000: 100 units received into the warehouse a week
    // before the day, so the van's opening balance on the day itself is nil.
    $second = pl_save_inventory_product(...array_merge($args, [array_replace(inventory_product_input($f),
        ['sku' => 'SYNTH-B-' . bin2hex(random_bytes(4)), 'name' => 'Sample second line', 'selling_price' => '40'])]));
    pl_inventory_receive(...array_merge($args, [array_replace(inventory_move_input($f, '100', '300', '2026-01-05'), ['product_id' => (int) $second['id']])]));
    $customer = static fn (string $name, ?string $limit): int => (int) pl_save_party(...array_merge($args, [[
        'legal_name' => $name, 'entity_type' => 'private_company', 'country_code' => 'GB',
        'is_customer' => true, 'is_vendor' => false, 'currency' => 'USD',
    ] + ($limit === null ? [] : ['credit_limit' => $limit]) + [
        'request_key' => bin2hex(random_bytes(16)), 'reason' => 'Sample distribution customer',
    ]]))['id'];
    return $f + [
        'van_id' => (int) $van['id'], 'product_b' => (int) $second['id'],
        'cash_customer_id' => $customer('Sample walk-in shop', null),
        'credit_customer_id' => $customer('Sample credit shop', '600'),
    ];
}

function distribution_context(array $f): array
{
    return ['actor_id' => $f['actor_id'], 'company_id' => $f['company_id'], 'book_id' => $f['book_id'],
        'warehouse_id' => (int) $f['default_warehouse_id'], 'van_id' => (int) $f['van_id'],
        'product_a' => (int) $f['product_id'], 'product_b' => (int) $f['product_b'],
        'cash_account_id' => (int) $f['accounts']['1000'],
        'cash_customer_id' => (int) $f['cash_customer_id'], 'credit_customer_id' => (int) $f['credit_customer_id'],
        'date' => '2026-01-12'];
}

/** One product's row of the driver's day, found by its identity rather than its position. */
function distribution_row(array $day, int $productId): array
{
    foreach ($day['products'] as $product) {
        if ((int) $product['product_id'] === $productId) { return $product; }
    }
    throw new RuntimeException('The van day has no row for product ' . $productId . '.');
}

test('the distributor day loads, sells, re-issues, takes a return and comes back empty', function (): void {
    $f = distribution_fixture();
    $c = distribution_context($f);
    $args = [$c['actor_id'], $c['company_id'], $c['book_id']];
    $run = pl_distribution_scenario($c);

    // The documents each took their own per-kind series (research decision 1).
    assert_same('ISS-2026-000001', $run['issue']['document_number']);
    assert_same('RISS-2026-000001', $run['reissue']['document_number']);
    assert_same('RTN-2026-000001', $run['return']['document_number']);
    assert_same('GP-2026-000001', $run['gate_pass']['document_number']);
    assert_same((int) $run['issue']['id'], (int) $run['reissue']['original_document_id'], 'A re-issue names the issue it tops up.');

    $day = $run['day'];
    $a = distribution_row($day, $c['product_a']);
    $b = distribution_row($day, $c['product_b']);

    // Product A: nothing at dawn, 60 + 20 loaded, 20 + 10 sold, 5 handed back, 55 returned.
    assert_same('0.0000', $a['opening']);
    assert_same('80.0000', $a['loaded']);
    assert_same('30.0000', $a['sold']);
    assert_same('5.0000', $a['customer_returns']);
    assert_same('55.0000', $a['returned']);
    assert_same('0.0000', $a['expected_closing'], '0 + 80 + 5 - 30 - 55 = 0.');
    assert_same('0.0000', $a['closing']);
    assert_same('0.0000', $a['variance']);
    assert_true($a['reconciles']);

    // Product B: 40 loaded, 15 sold, 25 returned.
    assert_same('0.0000', $b['opening']);
    assert_same('40.0000', $b['loaded']);
    assert_same('15.0000', $b['sold']);
    assert_same('0.0000', $b['customer_returns']);
    assert_same('25.0000', $b['returned']);
    assert_same('0.0000', $b['expected_closing'], '0 + 40 + 0 - 15 - 25 = 0.');
    assert_same('0.0000', $b['closing']);
    assert_same('0.0000', $b['variance']);

    assert_true($day['reconciles'], 'The driver\'s day does not balance.');
    assert_same('0.0000', $day['totals']['opening']);
    assert_same('120.0000', $day['totals']['loaded']);
    assert_same('45.0000', $day['totals']['sold']);
    assert_same('5.0000', $day['totals']['customer_returns']);
    assert_same('80.0000', $day['totals']['returned']);
    assert_same('0.0000', $day['totals']['closing']);
    // The identity, written out: 0 + 120 + 5 - 45 - 80 = 0.
    assert_same($day['totals']['closing'], bcsub(bcadd(bcadd($day['totals']['opening'], $day['totals']['loaded'], 4),
        $day['totals']['customer_returns'], 4), bcadd($day['totals']['sold'], $day['totals']['returned'], 4), 4));

    // Carrying value: 80 A at 2.0000 and 40 B at 3.0000 went out, 30 A and 15 B were sold,
    // 55 A and 25 B came back. The van holds nothing and is worth nothing.
    assert_same('280.0000', $day['totals']['loaded_cost'], '80 x 2.0000 plus 40 x 3.0000.');
    assert_same('105.0000', $day['totals']['sold_cost'], '30 x 2.0000 plus 15 x 3.0000.');
    assert_same('185.0000', $day['totals']['returned_cost'], '55 x 2.0000 plus 25 x 3.0000.');
    foreach ([$c['product_a'], $c['product_b']] as $productId) {
        $balance = pl_inventory_balance(...array_merge($args, [$productId, $c['date'], $c['van_id']]));
        assert_same('0.0000', $balance['quantity'], 'The van did not come back empty.');
        assert_same('0.0000', $balance['value_base'], 'The van came back empty but still carries value.');
    }

    // No stock document wrote a journal: a van and a warehouse are both the company's own
    // stock, so carrying value follows the goods and nothing is recognised. Asserted on the
    // movements the documents actually produced, not on a reference pattern that could simply
    // fail to match.
    $movements = (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_inventory_movements m'
        . ' JOIN pl_stock_document_lines l ON l.out_movement_id=m.id OR l.in_movement_id=m.id'
        . ' WHERE m.book_id=%i', $c['book_id']);
    assert_same(10, $movements, 'Two lines out, two back, and one more in the middle: five transfer pairs, ten legs.');
    assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_inventory_movements m'
        . ' JOIN pl_stock_document_lines l ON l.out_movement_id=m.id OR l.in_movement_id=m.id'
        . ' WHERE m.book_id=%i AND m.journal_id IS NOT NULL', $c['book_id']),
        'A stock document wrote a journal; a transfer between the company\'s own locations must not.');
    // The gate pass was stamped out and back in, and moved nothing at all.
    $stamped = pl_get_stock_document(...array_merge($args, [(int) $run['gate_pass']['id']]));
    assert_true($stamped['gate_pass']['security_out_at'] !== null && $stamped['gate_pass']['security_in_at'] !== null);
    assert_same([], $stamped['lines']);
});

test('the driver\'s money is the sale documents, split into what was paid and what was not', function (): void {
    $f = distribution_fixture();
    $c = distribution_context($f);
    $run = pl_distribution_scenario($c);
    $sales = $run['day']['sales'];

    assert_same(3, $sales['totals']['documents'], 'Three sales were raised from the van.');
    assert_same('1350.0000', $sales['totals']['sales'], '500 cash plus 450 and 400 on credit.');
    assert_same('500.0000', $sales['totals']['cash']);
    assert_same('850.0000', $sales['totals']['credit']);
    assert_same(1, $sales['totals']['cash_documents']);
    assert_same(2, $sales['totals']['credit_documents']);
    assert_same('125.0000', $sales['totals']['returns'], 'Five units of A at 25 came back on a credit note.');
    assert_same(1, $sales['totals']['return_documents']);
    $tenders = array_map(static fn (array $row): string => $row['tender'], $sales['documents']);
    assert_same(['cash', 'credit', 'credit', 'return'], $tenders);

    // The cash sale settled itself as it posted; the credit sales did not.
    assert_same('0.0000', $run['cash_sale']['outstanding_fc']);
    assert_true($run['cash_sale']['cash_settlement_journal_id'] !== null);
    assert_same('325.0000', pl_get_ar_document($c['actor_id'], $c['company_id'], $c['book_id'], (int) $run['credit_sale']['id'])['outstanding_fc'],
        'The 450 credit sale less the 125 credit note.');
    assert_same('400.0000', pl_get_ar_document($c['actor_id'], $c['company_id'], $c['book_id'], (int) $run['second_credit_sale']['id'])['outstanding_fc']);
});

test('a credit limit broken on the road stops the settlement, and a recovery releases it', function (): void {
    $f = distribution_fixture();
    $c = distribution_context($f);
    $args = [$c['actor_id'], $c['company_id'], $c['book_id']];
    $run = pl_distribution_scenario($c);
    $credit = $run['day']['credit'];

    assert_same('settlement', $credit['checked_at'], 'A van may be offline; the limit is checked at settlement.');
    assert_same(1, count($credit['parties']), 'Only the customer who took credit is checked.');
    $party = $credit['parties'][0];
    assert_same($c['credit_customer_id'], $party['party_id']);
    assert_same('850.0000', $party['credit_today_base'], '450 and 400 were extended on the road today.');
    assert_same('725.0000', $party['exposure_base'], '850 owed, less the 125 credit note.');
    assert_same('600.0000', $party['credit_limit']);
    assert_same('125.0000', $party['excess_base'], '725 against a limit of 600.');
    assert_same('-125.0000', $party['available_base']);
    assert_same('over', $party['status']);
    assert_same(1, $credit['breaches']);
    assert_true(!$credit['within_limits']);

    // The whole settlement walk: refused on the breach, refused again as stale, then approved.
    $settlement = pl_distribution_settlement($c, '125', (int) $run['second_credit_sale']['id']);
    assert_true($settlement['breach_refusal'] !== null, 'A broken credit limit must stop the approval.');
    assert_true(str_contains((string) $settlement['breach_refusal'], 'Sample credit shop'), 'The refusal names the customer.');
    assert_true(str_contains((string) $settlement['breach_refusal'], '725.0000') && str_contains((string) $settlement['breach_refusal'], '600.0000'),
        'The refusal states the exposure and the limit.');
    assert_same('reviewed', $settlement['first_review']['status']);
    assert_true($settlement['stale_refusal'] !== null && str_contains((string) $settlement['stale_refusal'], 'Review it again'),
        'A day that moved since it was reviewed cannot be approved on the old sheet.');
    assert_same('approved', $settlement['approved']['status']);
    assert_same((int) $settlement['first_review']['id'], (int) $settlement['second_review']['id'], 'One van day has one settlement record.');
    assert_true($settlement['approved']['reconciles']);

    // After the recovery the customer is exactly at the limit, which is inside it.
    $after = pl_van_day(...array_merge($args, [$c['van_id'], $c['date']]))['credit'];
    assert_same('600.0000', $after['parties'][0]['exposure_base']);
    assert_same('0.0000', $after['parties'][0]['available_base']);
    assert_same('within', $after['parties'][0]['status']);
    assert_true($after['within_limits']);
    // A limit of zero is a real limit; no limit at all is not a breach. The cash customer has
    // none recorded and took no credit, so it is never asked about.
    assert_same(null, pl_party_credit_limit($c['company_id'], $c['book_id'], $c['cash_customer_id']));
    assert_same('0.0000', pl_party_receivable_exposure($c['company_id'], $c['book_id'], $c['cash_customer_id'], $c['date']));
    // An approved settlement is immutable, and a second approval is refused.
    assert_throws(fn () => pl_approve_van_settlement(...array_merge($args, [(int) $settlement['approved']['id'], 'Again', bin2hex(random_bytes(16))])),
        DomainException::class, 'already approved');
    assert_throws(fn () => pl_review_van_settlement(...array_merge($args, [$c['van_id'], $c['date'], 'Reopen', bin2hex(random_bytes(16))])),
        DomainException::class, 'already approved');
});

test('approving the driver\'s day needs the settlement permission, and cost needs its own', function (): void {
    $f = distribution_fixture();
    $c = distribution_context($f);
    $args = [$c['actor_id'], $c['company_id'], $c['book_id']];
    $run = pl_distribution_scenario($c);
    pl_settle_ar_document(...array_merge($args, [(int) $run['second_credit_sale']['id'], [
        'bank_account_id' => $c['cash_account_id'], 'amount_fc' => '125', 'date' => $c['date'],
        'description' => 'Recovery', 'idempotency_key' => bin2hex(random_bytes(16))]]));
    $review = pl_review_van_settlement(...array_merge($args, [$c['van_id'], $c['date'], 'Reviewed', bin2hex(random_bytes(16))]));

    $suffix = bin2hex(random_bytes(8));
    $accountant = pl_create_user('sim-accountant-' . $suffix . '@example.test', 'Sample settlement accountant', 'Sample-test-password-' . $suffix);
    DB::insert('pl_company_members', ['company_id' => $c['company_id'], 'user_id' => $accountant, 'role' => 'accountant']);
    assert_true(!pl_van_settlement_can_approve($accountant, $c['company_id']), 'An accountant does not hold settlement.approve.');
    assert_true(pl_van_settlement_can_approve($c['actor_id'], $c['company_id']), 'The owner holds it by default.');
    assert_throws(fn () => pl_approve_van_settlement($accountant, $c['company_id'], $c['book_id'], (int) $review['id'], 'Approve', bin2hex(random_bytes(16))),
        DomainException::class, 'approval permission');

    // Owner decision B58: the settlement's cost totals are withheld from a reader without
    // cost.view — in the read, not only on the screen — while the quantities never are.
    $owner = pl_van_day(...array_merge($args, [$c['van_id'], $c['date']]));
    assert_true($owner['cost_visible']);
    assert_same('280.0000', $owner['totals']['loaded_cost']);
    $hidden = pl_van_day($accountant, $c['company_id'], $c['book_id'], $c['van_id'], $c['date']);
    assert_true(!$hidden['cost_visible']);
    assert_same(null, $hidden['totals']['loaded_cost']);
    assert_same(null, $hidden['totals']['sold_cost']);
    assert_same(null, $hidden['totals']['returned_cost']);
    assert_same('120.0000', $hidden['totals']['loaded'], 'Hiding cost changed a quantity.');
    assert_same($owner['reconciles'], $hidden['reconciles'], 'Hiding cost changed the reconciliation.');
    foreach ($hidden['products'] as $product) {
        assert_same(null, $product['loaded_cost']);
        assert_same(null, $product['sold_cost']);
        assert_same(null, $product['returned_cost']);
    }
    // The money on the sheet is not cost and is never withheld: it is what the driver collected.
    assert_same('850.0000', $hidden['sales']['totals']['credit']);
    assert_same($owner['credit']['parties'][0]['exposure_base'], $hidden['credit']['parties'][0]['exposure_base']);

    // The stored settlement sheet is unmasked, so who reviewed it cannot decide whether the
    // approval still matches. The accountant reads a masked copy of the same record.
    $read = pl_get_van_settlement($accountant, $c['company_id'], $c['book_id'], (int) $review['id']);
    assert_true(!$read['cost_visible']);
    assert_same(null, $read['summary']['totals']['loaded_cost']);
    assert_same('120.0000', $read['summary']['totals']['loaded']);
    $approved = pl_approve_van_settlement(...array_merge($args, [(int) $review['id'], 'Approved after the accountant reviewed it', bin2hex(random_bytes(16))]));
    assert_same('approved', $approved['status'], 'A sheet reviewed by someone without cost.view could not be approved.');
    assert_same('280.0000', $approved['summary']['totals']['loaded_cost'], 'The owner still reads the cost the sheet stored.');
});

test('the simulation is read-only to the rest of the book and its identity table adds up', function (): void {
    $f = distribution_fixture();
    $c = distribution_context($f);
    $args = [$c['actor_id'], $c['company_id'], $c['book_id']];
    $run = pl_distribution_scenario($c);
    foreach (pl_distribution_identity($run['day']) as $row) {
        assert_same('0.0000', $row['variance'], 'Item ' . $row['sku'] . ' does not reconcile: ' . $row['identity']);
        assert_same($row['closing'], $row['expected_closing'], $row['identity']);
    }
    // Stock value across every location still ties to the inventory control accounts after a
    // whole day of van movements, sales, a return and a settlement.
    $aggregate = pl_stock_location_aggregate(...array_merge($args, [$c['date']]));
    assert_true($aggregate['reconciles'], 'Per-location value and the aggregate valuation disagree.');
    assert_same($aggregate['total_value_base'], $aggregate['per_location_value_base']);
    foreach ($aggregate['accounts'] as $account) {
        assert_same('0.0000', $account['difference'], 'Stock value and its ledger account disagree after the driver\'s day.');
    }
    $report = pl_stock_by_location(...array_merge($args, [$c['date']]));
    assert_true($report['reconciles']);
    assert_same('0.0000', $report['difference']);
    // The van appears as its own location, holding nothing at the end of the day.
    $vanGroup = null;
    foreach ($report['groups'] as $group) { if ((int) $group['warehouse']['id'] === $c['van_id']) { $vanGroup = $group; } }
    assert_true($vanGroup !== null, 'The van is missing from the by-location report.');
    assert_same('0.0000', $vanGroup['totals']['quantity']);
    assert_same('0.0000', $vanGroup['totals']['value_base']);
    // A settlement for a day with no van activity is empty rather than wrong.
    $quiet = pl_van_day(...array_merge($args, [$c['van_id'], '2026-01-20']));
    assert_true($quiet['reconciles']);
    assert_same([], $quiet['sales']['documents']);
    assert_same([], $quiet['credit']['parties']);
    assert_true($quiet['credit']['within_limits']);
    // The settlement sheet is for a van, never for a fixed warehouse.
    assert_throws(fn () => pl_van_day(...array_merge($args, [$c['warehouse_id'], $c['date']])), DomainException::class, 'mobile stock location');
});
