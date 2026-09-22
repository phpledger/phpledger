<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/www/phpledger/includes/functions/web_functions.php';

/*
 * The counter point of sale over real stock (1.2 M9).
 *
 * Registered after trading_documents_test.php by tests/run.php, whose trading_fixture()
 * supplies the customer, the pack, the sales staff, the area and 200 base units of stock at a
 * carrying value of 2.0000 each.
 *
 * What these tests are actually for: proving that the till is not a parallel path. Every
 * assertion below about stock, cash, receivables and cost of sales is an assertion about the
 * ordinary invoice services, reached through the till.
 */

function counter_fixture(): array
{
    $f = trading_fixture();
    // Cash on an invoice is refused until the book sets a cap (B37); a till needs one.
    trading_set_policies($f, ['cash_on_invoice_cap' => '100000']);
    return $f;
}

function counter_sale_input(array $f, array $overrides = [], array $lines = []): array
{
    return array_replace([
        'tender' => 'cash', 'date' => '2026-01-10', 'warehouse_id' => $f['default_warehouse_id'] ?? null,
        'party_id' => $f['party_id'], 'cash_account_id' => $f['accounts']['1000'],
        'cash_tendered' => '500', 'reference' => 'Sample counter sale',
        'idempotency_key' => bin2hex(random_bytes(16)),
        'lines' => $lines === [] ? [['product_id' => $f['product_id'], 'quantity' => '4', 'unit_price' => '25']] : $lines,
    ], $overrides);
}

/** The till fixture needs the locations module and the book's default warehouse. */
function counter_located_fixture(): array
{
    $f = counter_fixture();
    location_enable($f);
    $default = pl_inventory_default_warehouse($f['actor_id'], $f['company_id'], $f['book_id']);
    return $f + ['default_warehouse_id' => $default['id']];
}

function counter_balance(array $f, ?int $warehouseId = null, string $date = '2026-01-10'): string
{
    return pl_inventory_balance($f['actor_id'], $f['company_id'], $f['book_id'], $f['product_id'], $date, $warehouseId)['quantity'];
}

test('a cash counter sale moves real stock and settles itself as it posts', function (): void {
    $f = counter_located_fixture();
    $args = [$f['actor_id'], $f['company_id'], $f['book_id']];
    $before = counter_balance($f);
    $input = counter_sale_input($f);
    $review = pl_review_counter_sale(...array_merge($args, [$input]));
    assert_same('100.0000', $review['readout']['total'], 'Four units at 25 is 100.');
    assert_same('400.0000', $review['readout']['cash']['change_due'], '500 tendered against 100 leaves 400 change.');
    assert_true($review['readout']['cash']['tenderable']);
    assert_same('200.0000', $review['readout']['stock'][0]['on_hand']);
    assert_same('196.0000', $review['readout']['stock'][0]['on_hand_after']);
    $result = pl_post_counter_sale(...array_merge($args, [$input, $review['review_hash']]));
    $document = $result['document'];
    assert_same('invoice', $document['kind'], 'A counter sale is an ordinary customer invoice.');
    assert_same('posted', $document['status']);
    assert_same('100.0000', $document['total']);
    // The cash portion is the total, not the tender: the change never entered the business.
    assert_same('100.0000', $document['cash_received']);
    assert_same('400.0000', $result['change_due']);
    assert_true($document['cash_settlement_journal_id'] !== null, 'A cash sale settles itself inside the posting action.');
    assert_same('paid', $document['payment_status'], 'Nothing may stand outstanding against money in the drawer.');
    assert_same('0.0000', $document['outstanding_fc']);
    assert_same(bcsub($before, '4', 4), counter_balance($f), 'The till took four units out of the stock ledger.');
    assert_same($f['default_warehouse_id'], $document['warehouse_id'], 'The sale records the location it was served from.');
    // Cost of sales is the carrying value the inventory service computed, not a till figure.
    assert_same('8.0000', trading_account_movement($f, (int) $f['accounts']['5000']), 'Four units at a carrying value of 2.0000 is 8.0000 of cost of sales.');
});

test('a credit counter sale leaves the money on the account and posts no receipt', function (): void {
    $f = counter_located_fixture();
    $args = [$f['actor_id'], $f['company_id'], $f['book_id']];
    $input = counter_sale_input($f, ['tender' => 'credit', 'due_date' => '2026-02-10', 'cash_account_id' => null, 'cash_tendered' => '']);
    $review = pl_review_counter_sale(...array_merge($args, [$input]));
    assert_same(null, $review['readout']['cash']);
    assert_same('0.0000', $review['readout']['credit']['exposure_base'], 'The customer owes nothing yet.');
    assert_same('100.0000', $review['readout']['credit']['exposure_after_base']);
    assert_same(null, $review['readout']['credit']['credit_limit'], 'No limit is recorded on this fixture customer.');
    assert_true(!$review['readout']['credit']['would_exceed'], 'No recorded limit is not a breach.');
    $result = pl_post_counter_sale(...array_merge($args, [$input, $review['review_hash']]));
    assert_same('0.0000', $result['document']['cash_received']);
    assert_same(null, $result['document']['cash_settlement_journal_id']);
    assert_same('100.0000', $result['document']['outstanding_fc']);
    assert_same('2026-02-10', $result['document']['due_date']);
    assert_same('100.0000', pl_party_receivable_exposure($f['company_id'], $f['book_id'], (int) $f['party_id'], '2026-01-10'));
});

test('packs and loose units resolve to one base quantity before pricing, tax and the stock issue', function (): void {
    $f = counter_located_fixture();
    $args = [$f['actor_id'], $f['company_id'], $f['book_id']];
    // Five cartons of twelve plus three loose units is 63 base units.
    $input = counter_sale_input($f, [], [['product_id' => $f['product_id'], 'pack_id' => $f['pack_id'],
        'pack_quantity' => '5', 'unit_quantity' => '3', 'unit_price' => '25']]);
    $input['cash_tendered'] = '2000';
    $review = pl_review_counter_sale(...array_merge($args, [$input]));
    assert_same('63.0000', $review['plan']['document']['lines'][0]['quantity']);
    assert_same('1575.0000', $review['readout']['total'], '63 units at 25 is 1,575.');
    assert_same('425.0000', $review['readout']['cash']['change_due']);
    $result = pl_post_counter_sale(...array_merge($args, [$input, $review['review_hash']]));
    assert_same('63.0000', $result['document']['lines'][0]['quantity']);
    assert_same('5.0000', $result['document']['lines'][0]['pack_quantity']);
    assert_same('3.0000', $result['document']['lines'][0]['unit_quantity']);
    assert_same('137.0000', counter_balance($f), '200 base units less 63 is 137.');
});

test('the till refuses more than the location holds, short cash and an untenderable total', function (): void {
    $f = counter_located_fixture();
    $args = [$f['actor_id'], $f['company_id'], $f['book_id']];
    // The shelf is checked before the invoice is priced, so the cashier is told what is short
    // rather than shown the posting funnel's "would leave negative stock".
    $tooMany = counter_sale_input($f, ['cash_tendered' => '100000'], [['product_id' => $f['product_id'], 'quantity' => '500', 'unit_price' => '25']]);
    assert_throws(fn () => pl_review_counter_sale(...array_merge($args, [$tooMany])), DomainException::class, 'does not hold enough');
    assert_throws(fn () => pl_post_counter_sale(...array_merge($args, [$tooMany, 'no-review'])), DomainException::class, 'does not hold enough');
    // Two lines of the same product draw on one balance: 150 and 100 is 250 against 200.
    $split = counter_sale_input($f, ['cash_tendered' => '100000'], [
        ['product_id' => $f['product_id'], 'quantity' => '150', 'unit_price' => '25'],
        ['product_id' => $f['product_id'], 'pack_id' => $f['pack_id'], 'pack_quantity' => '8', 'unit_quantity' => '4', 'unit_price' => '25']]);
    assert_throws(fn () => pl_review_counter_sale(...array_merge($args, [$split])), DomainException::class, 'does not hold enough');
    $short = counter_sale_input($f, ['cash_tendered' => '50']);
    $shortReview = pl_review_counter_sale(...array_merge($args, [$short]));
    assert_true(!$shortReview['readout']['cash']['sufficient']);
    assert_throws(fn () => pl_post_counter_sale(...array_merge($args, [$short, $shortReview['review_hash']])), DomainException::class, 'less than the sale total');
    // Issue #88's rule on a real till: a total finer than the smallest coin cannot be tendered.
    $odd = counter_sale_input($f, [], [['product_id' => $f['product_id'], 'quantity' => '3', 'unit_price' => '25.3333']]);
    $oddReview = pl_review_counter_sale(...array_merge($args, [$odd]));
    assert_same('75.9999', $oddReview['readout']['total']);
    assert_true(!$oddReview['readout']['cash']['tenderable']);
    assert_throws(fn () => pl_post_counter_sale(...array_merge($args, [$odd, $oddReview['review_hash']])), DomainException::class, 'finer than the smallest coin');
    // A tender with sub-coin precision never reaches the service at all.
    assert_throws(fn () => pl_counter_sale_input(counter_sale_input($f, ['cash_tendered' => '100.0055'])), DomainException::class, 'whole notes and coins');
    assert_same('200.0000', counter_balance($f), 'No refused sale moved any stock.');
});

test('a counter credit sale over the recorded limit is refused at the counter, and a van sale is not', function (): void {
    $f = counter_located_fixture();
    $args = [$f['actor_id'], $f['company_id'], $f['book_id']];
    $party = pl_get_party(...array_merge($args, [(int) $f['party_id']]));
    pl_save_party(...array_merge($args, [[
        'legal_name' => (string) $party['legal_name'], 'entity_type' => (string) $party['entity_type'],
        'country_code' => (string) $party['country_code'], 'is_customer' => true, 'is_vendor' => true,
        'currency' => 'USD', 'credit_limit' => '60', 'reason' => 'Sample credit limit',
        'request_key' => bin2hex(random_bytes(16)),
    ], (int) $f['party_id'], (int) $party['revision']]));
    assert_same('60.0000', pl_party_credit_limit($f['company_id'], $f['book_id'], (int) $f['party_id']));
    $credit = counter_sale_input($f, ['tender' => 'credit', 'cash_account_id' => null, 'cash_tendered' => '']);
    $review = pl_review_counter_sale(...array_merge($args, [$credit]));
    assert_true($review['readout']['credit']['would_exceed'], '100 against a limit of 60 is over.');
    assert_true($review['readout']['credit_enforced_now'], 'A fixed location is a counter: the server is here, so the limit binds now.');
    assert_throws(fn () => pl_post_counter_sale(...array_merge($args, [$credit, $review['review_hash']])), DomainException::class, 'credit limit');
    // The same sale from a van is recorded: the device may be offline, so decision 4 puts the
    // check at settlement. tests/distribution_simulation_test.php proves it binds there.
    $van = pl_save_inventory_warehouse(...array_merge($args, [['code' => 'VAN-COUNTER', 'name' => 'Sample till van', 'kind' => 'mobile',
        'driver_employee_id' => sample_assignment_employee($f['actor_id'],$f['company_id']), 'driver_name' => 'Sample driver', 'is_active' => true, 'reason' => 'Sample van', 'idempotency_key' => bin2hex(random_bytes(16))]]));
    pl_post_stock_document(...array_merge($args, [['kind' => 'stock_issue', 'date' => '2026-01-09',
        'from_warehouse_id' => $f['default_warehouse_id'], 'to_warehouse_id' => (int) $van['id'],
        'reason' => 'Sample load', 'lines' => [['product_id' => $f['product_id'], 'quantity' => '20']],
        'idempotency_key' => bin2hex(random_bytes(16))]]));
    $fromVan = counter_sale_input($f, ['tender' => 'credit', 'cash_account_id' => null, 'cash_tendered' => '', 'warehouse_id' => (int) $van['id']]);
    $vanReview = pl_review_counter_sale(...array_merge($args, [$fromVan]));
    assert_true($vanReview['readout']['credit']['would_exceed']);
    assert_true(!$vanReview['readout']['credit_enforced_now'], 'A van sale is not refused at the point of sale.');
    $posted = pl_post_counter_sale(...array_merge($args, [$fromVan, $vanReview['review_hash']]));
    assert_same('100.0000', $posted['document']['outstanding_fc']);
    assert_same((int) $van['id'], $posted['document']['warehouse_id']);
});

test('the till catalogue reads the chosen location only, and withholds cost without the permission', function (): void {
    $f = counter_located_fixture();
    $args = [$f['actor_id'], $f['company_id'], $f['book_id']];
    $van = pl_save_inventory_warehouse(...array_merge($args, [['code' => 'VAN-CAT', 'name' => 'Sample catalogue van', 'kind' => 'mobile',
        'driver_employee_id' => sample_assignment_employee($f['actor_id'],$f['company_id']), 'driver_name' => 'Sample driver', 'is_active' => true, 'reason' => 'Sample van', 'idempotency_key' => bin2hex(random_bytes(16))]]));
    pl_post_stock_document(...array_merge($args, [['kind' => 'stock_issue', 'date' => '2026-01-09',
        'from_warehouse_id' => $f['default_warehouse_id'], 'to_warehouse_id' => (int) $van['id'],
        'reason' => 'Sample load', 'lines' => [['product_id' => $f['product_id'], 'quantity' => '30']],
        'idempotency_key' => bin2hex(random_bytes(16))]]));
    $counter = pl_counter_pos_catalogue(...array_merge($args, [$f['default_warehouse_id'], '', '2026-01-10']));
    $vanList = pl_counter_pos_catalogue(...array_merge($args, [(int) $van['id'], '', '2026-01-10']));
    $find = static function (array $catalogue, int $productId): array {
        foreach ($catalogue['products'] as $row) { if ($row['product_id'] === $productId) { return $row; } }
        throw new RuntimeException('The product is missing from the till catalogue.');
    };
    assert_same('170.0000', $find($counter, (int) $f['product_id'])['on_hand'], 'The warehouse kept 170 of its 200.');
    assert_same('30.0000', $find($vanList, (int) $f['product_id'])['on_hand'], 'The van is holding the 30 it was issued.');
    assert_same('60.0000', $find($vanList, (int) $f['product_id'])['value_base'], '30 units at a carrying value of 2.0000.');
    assert_same('12.0000', $find($counter, (int) $f['product_id'])['packs'][0]['units_per_pack'], 'The pack the customer buys by is offered on the till.');
    assert_true($counter['cost_visible'], 'The owner holds cost.view and the report ships showing cost.');
    // Owner decision B58: turn the report off and the read withholds carrying value, not only
    // the screen. An API client is refused exactly what the screen is.
    pl_save_report_cost_setting(...array_merge($args, ['counter-pos', false, false, 0, 'Sample cost visibility change']));
    $hidden = pl_counter_pos_catalogue(...array_merge($args, [(int) $van['id'], '', '2026-01-10']));
    assert_true(!$hidden['cost_visible']);
    assert_same(null, $find($hidden, (int) $f['product_id'])['value_base'], 'Carrying value is withheld from the read.');
    assert_same('30.0000', $find($hidden, (int) $f['product_id'])['on_hand'], 'The quantity is never withheld.');
});

test('a reviewed sale that changed is refused, and an identical retry records one sale', function (): void {
    $f = counter_located_fixture();
    $args = [$f['actor_id'], $f['company_id'], $f['book_id']];
    $input = counter_sale_input($f);
    $review = pl_review_counter_sale(...array_merge($args, [$input]));
    $changed = counter_sale_input($f, ['idempotency_key' => $input['idempotency_key']], [['product_id' => $f['product_id'], 'quantity' => '5', 'unit_price' => '25']]);
    assert_throws(fn () => pl_post_counter_sale(...array_merge($args, [$changed, $review['review_hash']])), DomainException::class, 'Review the sale again');
    $first = pl_post_counter_sale(...array_merge($args, [$input, $review['review_hash']]));
    $again = pl_post_counter_sale(...array_merge($args, [$input, $review['review_hash']]));
    assert_same((int) $first['document']['id'], (int) $again['document']['id'], 'An identical retry returns the same sale.');
    assert_true(!$first['replayed'] && $again['replayed'], 'The retry must be recognised as a replay, not rung up again.');
    assert_same($first['change_due'], $again['change_due'], 'A replay must report the same change.');
    assert_same(1, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_ar_documents WHERE book_id=%i AND warehouse_id=%i', $f['book_id'], $f['default_warehouse_id']));
    assert_same('196.0000', counter_balance($f), 'The retry moved no further stock.');
});

test('a counter receipt is the invoice read back, and another company cannot open it', function (): void {
    $f = counter_located_fixture();
    $args = [$f['actor_id'], $f['company_id'], $f['book_id']];
    $input = counter_sale_input($f);
    $review = pl_review_counter_sale(...array_merge($args, [$input]));
    $sale = pl_post_counter_sale(...array_merge($args, [$input, $review['review_hash']]));
    $receipt = pl_counter_sale_receipt(...array_merge($args, [(int) $sale['document']['id']]));
    assert_same('cash', $receipt['tender']);
    assert_same('0.0000', $receipt['outstanding']);
    assert_same($f['default_warehouse_id'], (int) $receipt['warehouse']['id']);
    $other = ledger_fixture();
    assert_throws(fn () => pl_counter_sale_receipt($other['actor_id'], $f['company_id'], $f['book_id'], (int) $sale['document']['id']), DomainException::class);
});
