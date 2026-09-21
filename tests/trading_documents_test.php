<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/www/phpledger/includes/functions/web_functions.php';
require_once dirname(__DIR__) . '/www/phpledger/includes/functions/print_functions.php';

/**
 * Trading documents (release plan 1.2 M3): packs, line discounts, free goods, the sales-staff
 * and area dimensions, cash taken on the invoice, the B37 policies, the B64 company profile
 * and the three print templates.
 */
function trading_fixture(): array
{
    $f = inventory_fixture();
    $manifest = pl_module_registry()['trading-documents'];
    pl_set_company_module($f['actor_id'], $f['company_id'], 'trading-documents', true, 0, $manifest['digest'], 'Sample trading module', bin2hex(random_bytes(16)));
    $party = pl_save_party($f['actor_id'], $f['company_id'], $f['book_id'], ['legal_name' => 'Sample trading customer', 'entity_type' => 'private_company',
        'country_code' => 'GB', 'is_customer' => true, 'is_vendor' => true, 'currency' => 'USD',
        'request_key' => bin2hex(random_bytes(16)), 'reason' => 'Sample trading fixture']);
    $discount = pl_save_account($f['actor_id'], $f['company_id'], $f['book_id'], ['code' => '4910', 'name' => 'Sample discounts allowed', 'type' => 'income',
        'role' => null, 'is_active' => true, 'is_contra' => true, 'reason' => 'Sample contra-income', 'creation_key' => bin2hex(random_bytes(16))]);
    $promotion = pl_save_account($f['actor_id'], $f['company_id'], $f['book_id'], ['code' => '5300', 'name' => 'Sample promotional goods', 'type' => 'expense',
        'role' => null, 'is_active' => true, 'reason' => 'Sample promotion expense', 'creation_key' => bin2hex(random_bytes(16))]);
    // Stock to sell and to give away: 200 base units at a carrying value of 2.00 each.
    pl_inventory_receive($f['actor_id'], $f['company_id'], $f['book_id'], inventory_move_input($f, '200', '400'));
    $staff = pl_save_sales_staff($f['actor_id'], $f['company_id'], $f['book_id'], ['code' => 'BILAL', 'name' => 'Sample van salesman', 'is_active' => true, 'reason' => 'Sample staff']);
    $area = pl_save_area($f['actor_id'], $f['company_id'], $f['book_id'], ['code' => 'GULBERG', 'name' => 'Sample area route', 'is_active' => true, 'reason' => 'Sample area']);
    $pack = pl_save_product_pack($f['actor_id'], $f['company_id'], $f['book_id'], ['product_id' => $f['product_id'], 'code' => 'CTN12',
        'name' => 'Carton of 12', 'units_per_pack' => '12', 'is_active' => true, 'reason' => 'Sample pack']);
    return $f + ['party_id' => $party['id'], 'discount_account_id' => $discount['id'], 'promotion_account_id' => $promotion['id'],
        'staff_id' => $staff['id'], 'area_id' => $area['id'], 'pack_id' => $pack['id']];
}

/** Set one or more policies, always from the book's current revision. */
function trading_set_policies(array $f, array $values): array
{
    $current = pl_trading_policies($f['actor_id'], $f['company_id'], $f['book_id']);
    return pl_save_trading_policies($f['actor_id'], $f['company_id'], $f['book_id'], array_replace([
        'discount_posting' => $current['discount_posting'], 'discount_account_id' => $current['discount_account_id'] ?? '',
        'free_goods_account_id' => $current['free_goods_account_id'] ?? '', 'free_goods_output_tax' => $current['free_goods_output_tax'],
        'cash_on_invoice_cap' => $current['cash_on_invoice_cap'], 'revision' => $current['revision'],
        'reason' => 'Sample policy change', 'idempotency_key' => bin2hex(random_bytes(16)),
    ], $values));
}

function trading_invoice_input(array $f, array $overrides = [], array $lines = []): array
{
    return array_replace([
        'kind' => 'invoice', 'party_id' => $f['party_id'], 'date' => '2026-01-10', 'due_date' => '2026-02-10',
        'currency' => 'USD', 'reference' => 'Sample trading reference', 'creation_key' => bin2hex(random_bytes(16)),
        'lines' => $lines === [] ? [['description' => 'Sample stock sale', 'quantity' => '10', 'unit_price' => '25',
            'account_id' => $f['accounts']['4000'], 'product_id' => $f['product_id']]] : $lines,
    ], $overrides);
}

function trading_post(array $f, array $input): array
{
    $draft = pl_save_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], $input);
    return pl_post_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], $draft['id'], $draft['revision']);
}

/** Every posted journal line of one account, as a signed base movement. */
function trading_account_movement(array $f, int $accountId): string
{
    $total = '0.0000';
    foreach (DB::query('SELECT l.debit,l.credit FROM pl_journal_lines l JOIN pl_journals j ON j.id=l.journal_id WHERE l.company_id=%i AND l.book_id=%i AND l.account_id=%i', $f['company_id'], $f['book_id'], $accountId) as $line) {
        $total = bcadd($total, bcsub((string) $line['debit'], (string) $line['credit'], 4), 4);
    }
    return $total;
}

test('a pack resolves to a base quantity before pricing, tax and stock issue', function (): void {
    assert_same('63.0000', pl_trading_pack_quantity('5', '3', '12'));
    assert_same('12.0000', pl_trading_pack_quantity('1', '0', '12'));
    assert_same('0.5000', pl_trading_pack_quantity('0', '0.5', '12'));
    assert_throws(fn() => pl_trading_pack_quantity('1', '0', '0'), DomainException::class, 'positive number of base units');
    $f = trading_fixture();
    $document = trading_post($f, trading_invoice_input($f, [], [[
        'description' => 'Sample cartons', 'pack_id' => $f['pack_id'], 'pack_quantity' => '5', 'unit_quantity' => '3',
        'unit_price' => '2.5', 'account_id' => $f['accounts']['4000'], 'product_id' => $f['product_id'],
    ]]));
    assert_same('63.0000', $document['lines'][0]['quantity']);
    assert_same('157.5000', $document['lines'][0]['line_total']);
    assert_same('157.5000', $document['total']);
    // The stock ledger issued the resolved base quantity, not the number of cartons.
    assert_same('-63.0000', DB::queryFirstField("SELECT SUM(quantity_delta) FROM pl_inventory_movements WHERE book_id=%i AND source_type='ar_invoice' AND source_document_id=%i", $f['book_id'], $document['id']));
});

test('a pack keeps its product, code and size for ever, in the service and in the database', function (): void {
    $f = trading_fixture();
    $pack = pl_get_product_pack($f['actor_id'], $f['company_id'], $f['book_id'], $f['pack_id']);
    assert_same('12.0000', $pack['units_per_pack']);
    assert_throws(fn() => pl_save_product_pack($f['actor_id'], $f['company_id'], $f['book_id'], ['product_id' => $f['product_id'], 'code' => 'CTN12',
        'name' => 'Carton of 12', 'units_per_pack' => '24', 'is_active' => true, 'reason' => 'Resize'], $f['pack_id'], 1), DomainException::class, 'for ever');
    assert_throws(fn() => DB::update('pl_product_packs', ['units_per_pack' => '24'], 'id=%i', $f['pack_id']), Throwable::class, 'immutable');
    assert_throws(fn() => DB::delete('pl_product_packs', 'id=%i', $f['pack_id']), Throwable::class, 'immutable');
    // The name and the active flag may still change, through the service, with an audit row.
    $renamed = pl_save_product_pack($f['actor_id'], $f['company_id'], $f['book_id'], ['product_id' => $f['product_id'], 'code' => 'CTN12',
        'name' => 'Carton of twelve', 'units_per_pack' => '12', 'is_active' => false, 'reason' => 'Retire the pack'], $f['pack_id'], 1);
    assert_same(false, $renamed['is_active']);
    assert_true(pl_core_history($f['actor_id'], $f['company_id'], $f['book_id'], 'product_pack', $f['pack_id']) !== [], 'The pack change was not audited.');
});

test('a line discount stores its amount and, under the net policy, never reaches the ledger', function (): void {
    assert_same(['gross' => '100.0000', 'discount' => '5.0000', 'net' => '95.0000'], pl_trading_line_discount('100', '5'));
    assert_same(['gross' => '3.3300', 'discount' => '0.3330', 'net' => '2.9970'], pl_trading_line_discount('3.33', '10'));
    assert_throws(fn() => pl_trading_line_discount('100', '101'), DomainException::class, 'between 0 and 100');
    $f = trading_fixture();
    $document = trading_post($f, trading_invoice_input($f, [], [[
        'description' => 'Sample discounted sale', 'quantity' => '10', 'unit_price' => '25', 'discount_percent' => '10',
        'account_id' => $f['accounts']['4000'], 'product_id' => $f['product_id'],
    ]]));
    assert_same('250.0000', $document['lines'][0]['gross_amount']);
    assert_same('25.0000', $document['lines'][0]['discount_amount']);
    assert_same('225.0000', $document['lines'][0]['line_total']);
    assert_same('25.0000', $document['discount_total']);
    assert_same('225.0000', $document['total']);
    assert_same('-225.0000', trading_account_movement($f, $f['accounts']['4000']));
    assert_same('0.0000', trading_account_movement($f, $f['discount_account_id']));
});

test('the gross discount policy recognises the undiscounted sale and the discount as contra-income', function (): void {
    $f = trading_fixture();
    trading_set_policies($f, ['discount_posting' => 'gross', 'discount_account_id' => $f['discount_account_id']]);
    $document = trading_post($f, trading_invoice_input($f, [], [[
        'description' => 'Sample discounted sale', 'quantity' => '10', 'unit_price' => '25', 'discount_percent' => '10',
        'account_id' => $f['accounts']['4000'], 'product_id' => $f['product_id'],
    ]]));
    // The customer still owes the discounted amount; only the split inside income changed.
    assert_same('225.0000', $document['total']);
    assert_same('225.0000', $document['outstanding_fc']);
    assert_same('-250.0000', trading_account_movement($f, $f['accounts']['4000']));
    assert_same('25.0000', trading_account_movement($f, $f['discount_account_id']));
    $journal = pl_get_journal($f['actor_id'], $f['company_id'], $f['book_id'], (int) $document['journal_id']);
    $debits = '0.0000'; $credits = '0.0000';
    foreach ($journal['lines'] as $line) { $debits = bcadd($debits, (string) $line['debit'], 4); $credits = bcadd($credits, (string) $line['credit'], 4); }
    assert_same($debits, $credits, 'The gross-discount journal does not balance.');
});

test('gross discount posting needs a contra-income account and refuses anything else', function (): void {
    $f = trading_fixture();
    assert_throws(fn() => trading_set_policies($f, ['discount_posting' => 'gross']), DomainException::class, 'contra-income account');
    assert_throws(fn() => trading_set_policies($f, ['discount_posting' => 'gross', 'discount_account_id' => $f['accounts']['4000']]), DomainException::class, 'contra-income account');
    assert_throws(fn() => trading_set_policies($f, ['discount_posting' => 'sideways']), DomainException::class, 'net to income');
    assert_throws(fn() => trading_set_policies($f, ['free_goods_account_id' => $f['accounts']['1000']]), DomainException::class, 'general expense account');
});

test('free goods carry no value to the customer and their cost leaves stock to the promotional account', function (): void {
    $f = trading_fixture();
    trading_set_policies($f, ['free_goods_account_id' => $f['promotion_account_id']]);
    $document = trading_post($f, trading_invoice_input($f, [], [
        ['description' => 'Sample paid sale', 'quantity' => '10', 'unit_price' => '25', 'account_id' => $f['accounts']['4000'], 'product_id' => $f['product_id']],
        ['description' => 'Sample 5+1 bonus', 'quantity' => '2', 'is_free_goods' => true, 'account_id' => $f['accounts']['4000'], 'product_id' => $f['product_id']],
    ]));
    assert_same('0.0000', $document['lines'][1]['line_total']);
    assert_same(true, $document['lines'][1]['is_free_goods']);
    assert_same('250.0000', $document['subtotal']);
    assert_same('250.0000', $document['total']);
    // Twelve units left stock: ten sold at cost of sales, two given away to the promotion account.
    assert_same('-12.0000', DB::queryFirstField("SELECT SUM(quantity_delta) FROM pl_inventory_movements WHERE book_id=%i AND source_type='ar_invoice' AND source_document_id=%i", $f['book_id'], $document['id']));
    assert_same('20.0000', trading_account_movement($f, $f['accounts']['5000']));
    assert_same('4.0000', trading_account_movement($f, $f['promotion_account_id']));
    // Income is the paid line only; the bonus earned nothing.
    assert_same('-250.0000', trading_account_movement($f, $f['accounts']['4000']));
});

test('free goods need their promotional account and are refused on a credit note or a bill', function (): void {
    $f = trading_fixture();
    $withFree = trading_invoice_input($f, [], [
        ['description' => 'Sample paid sale', 'quantity' => '10', 'unit_price' => '25', 'account_id' => $f['accounts']['4000'], 'product_id' => $f['product_id']],
        ['description' => 'Sample bonus', 'quantity' => '2', 'is_free_goods' => true, 'account_id' => $f['accounts']['4000'], 'product_id' => $f['product_id']],
    ]);
    $draft = pl_save_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], $withFree);
    assert_throws(fn() => pl_post_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], $draft['id'], 1), DomainException::class, 'promotional expense account');
    assert_same('draft', pl_get_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], $draft['id'])['status']);
    $onlyFree = trading_invoice_input($f, [], [['description' => 'Sample bonus', 'quantity' => '2', 'is_free_goods' => true, 'account_id' => $f['accounts']['4000'], 'product_id' => $f['product_id']]]);
    assert_throws(fn() => pl_normalize_ar_document($onlyFree), DomainException::class, 'at least one valued line');
    $bill = trading_invoice_input($f, ['kind' => 'bill'], [['description' => 'Sample bonus', 'quantity' => '2', 'is_free_goods' => true, 'account_id' => $f['accounts']['5000'], 'product_id' => $f['product_id']]]);
    assert_throws(fn() => pl_normalize_ar_document($bill), DomainException::class, 'accompany a customer invoice');
    // The replaced line CHECK still refuses a zero-valued line that is not flagged as free.
    assert_throws(fn() => DB::insert('pl_ar_document_lines', ['document_id' => $draft['id'], 'company_id' => $f['company_id'], 'book_id' => $f['book_id'],
        'line_number' => 9, 'description' => 'unflagged zero line', 'quantity' => '1', 'unit_price' => '0', 'line_total' => '0']), Throwable::class);
});

test('the open-market-value policy charges output tax on free goods to the business, never to the customer', function (): void {
    $f = trading_fixture();
    $output = pl_save_account($f['actor_id'], $f['company_id'], $f['book_id'], ['code' => '2150', 'name' => 'Sample output tax', 'type' => 'liability',
        'role' => null, 'is_active' => true, 'reason' => 'Sample tax accounts', 'creation_key' => bin2hex(random_bytes(16))]);
    $input = pl_save_account($f['actor_id'], $f['company_id'], $f['book_id'], ['code' => '1350', 'name' => 'Sample input tax', 'type' => 'asset',
        'role' => null, 'is_active' => true, 'reason' => 'Sample tax accounts', 'creation_key' => bin2hex(random_bytes(16))]);
    $code = pl_create_tax_code($f['actor_id'], $f['company_id'], $f['book_id'], ['code' => 'GST', 'name' => 'Sample GST',
        'treatment' => 'standard', 'sales_account_id' => $output['id'], 'purchase_account_id' => $input['id'],
        'reason' => 'Sample tax code', 'idempotency_key' => bin2hex(random_bytes(16))]);
    pl_enter_tax_rate($f['actor_id'], $f['company_id'], $f['book_id'], ['tax_code_id' => $code['id'], 'effective_from' => '2026-01-01',
        'percentage' => '10', 'reason' => 'Sample rate', 'idempotency_key' => bin2hex(random_bytes(16))]);
    trading_set_policies($f, ['free_goods_account_id' => $f['promotion_account_id'], 'free_goods_output_tax' => 'open_market_value']);
    $document = trading_post($f, trading_invoice_input($f, [], [
        ['description' => 'Sample paid sale', 'quantity' => '10', 'unit_price' => '25', 'tax_code_id' => $code['id'], 'account_id' => $f['accounts']['4000'], 'product_id' => $f['product_id']],
        ['description' => 'Sample bonus', 'quantity' => '2', 'unit_price' => '25', 'is_free_goods' => true, 'tax_code_id' => $code['id'], 'account_id' => $f['accounts']['4000'], 'product_id' => $f['product_id']],
    ]));
    assert_same('25.0000', $document['tax_total']);
    assert_same('275.0000', $document['total'], 'The customer was billed for the free goods or their tax.');
    assert_same('5.0000', $document['free_tax_total']);
    // Output tax of 30.00 in all: 25.00 charged to the customer plus 5.00 the business bears.
    assert_same('-30.0000', trading_account_movement($f, (int) $output['id']));
    // The promotional account carries the bonus carrying value of 4.00 plus that 5.00 of tax.
    assert_same('9.0000', trading_account_movement($f, $f['promotion_account_id']));
});

test('every policy value is a per-company setting with one revision at a time and an audit trail', function (): void {
    $f = trading_fixture();
    $defaults = pl_trading_policies($f['actor_id'], $f['company_id'], $f['book_id']);
    assert_same('net', $defaults['discount_posting']);
    assert_same('none', $defaults['free_goods_output_tax']);
    assert_same('0.0000', $defaults['cash_on_invoice_cap']);
    assert_same(0, $defaults['revision']);
    $saved = trading_set_policies($f, ['cash_on_invoice_cap' => '500']);
    assert_same(1, $saved['revision']);
    assert_same('500.0000', pl_trading_policies($f['actor_id'], $f['company_id'], $f['book_id'])['cash_on_invoice_cap']);
    $key = bin2hex(random_bytes(16));
    $again = pl_save_trading_policies($f['actor_id'], $f['company_id'], $f['book_id'], ['discount_posting' => 'net', 'discount_account_id' => '',
        'free_goods_account_id' => '', 'free_goods_output_tax' => 'none', 'cash_on_invoice_cap' => '750', 'revision' => 1,
        'reason' => 'Raise the cap', 'idempotency_key' => $key]);
    $replay = pl_save_trading_policies($f['actor_id'], $f['company_id'], $f['book_id'], ['discount_posting' => 'net', 'discount_account_id' => '',
        'free_goods_account_id' => '', 'free_goods_output_tax' => 'none', 'cash_on_invoice_cap' => '750', 'revision' => 1,
        'reason' => 'Raise the cap', 'idempotency_key' => $key]);
    // The replayed answer is recorded as JSON, which may come back with its keys in another
    // order; what must not differ is the recorded content.
    ksort($again); ksort($replay);
    assert_same($again, $replay, 'A replayed policy request was not idempotent.');
    assert_same(1, (int) DB::queryFirstField("SELECT COUNT(*) FROM pl_trading_policy_actions WHERE company_id=%i AND request_key=%s", $f['company_id'], $key));
    assert_throws(fn() => trading_set_policies(array_replace($f, []), ['cash_on_invoice_cap' => '900', 'revision' => 1]), DomainException::class, 'reload');
    assert_true(count(pl_trading_policy_history($f['actor_id'], $f['company_id'])) >= 2, 'Policy changes were not recorded.');
    assert_throws(fn() => DB::update('pl_trading_policy_actions', ['reason' => 'changed'], 'company_id=%i', $f['company_id']), Throwable::class, 'immutable');
    // Another company's owner cannot read or change this book's policies.
    $other = trading_fixture();
    assert_throws(fn() => pl_trading_policies($other['actor_id'], $f['company_id'], $f['book_id']), DomainException::class);
});

test('cash received on an invoice posts recognition and settles its own portion atomically', function (): void {
    $f = trading_fixture();
    trading_set_policies($f, ['cash_on_invoice_cap' => '500']);
    $document = trading_post($f, trading_invoice_input($f, ['cash_received' => '100', 'cash_account_id' => $f['accounts']['1000'],
        'sales_staff_id' => $f['staff_id'], 'area_id' => $f['area_id']]));
    assert_same('250.0000', $document['total']);
    assert_same('100.0000', $document['cash_received']);
    assert_true($document['cash_settlement_journal_id'] !== null, 'No settlement journal was recorded for the cash taken.');
    assert_same('150.0000', $document['outstanding_fc']);
    assert_same('partially_paid', $document['payment_status']);
    assert_same('100.0000', trading_account_movement($f, $f['accounts']['1000']));
    // Recognition and settlement are two linked journals of one action, not one merged entry.
    assert_same(2, (int) DB::queryFirstField("SELECT COUNT(*) FROM pl_journals WHERE book_id=%i AND source_type IN ('open_item_recognition','open_item_settlement')", $f['book_id']));
});

test('cash on an invoice is refused without a cap, above the cap and above the invoice total', function (): void {
    $f = trading_fixture();
    $input = trading_invoice_input($f, ['cash_received' => '100', 'cash_account_id' => $f['accounts']['1000']]);
    assert_throws(fn() => pl_save_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], $input), DomainException::class, 'not accepted in this book');
    trading_set_policies($f, ['cash_on_invoice_cap' => '50']);
    assert_throws(fn() => pl_save_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], $input), DomainException::class, 'cash-on-invoice cap');
    trading_set_policies($f, ['cash_on_invoice_cap' => '5000']);
    $tooMuch = trading_invoice_input($f, ['cash_received' => '400', 'cash_account_id' => $f['accounts']['1000']]);
    assert_throws(fn() => pl_save_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], $tooMuch), DomainException::class, 'cannot exceed the invoice total');
    $wrongAccount = trading_invoice_input($f, ['cash_received' => '100', 'cash_account_id' => $f['accounts']['4000']]);
    assert_throws(fn() => pl_save_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], $wrongAccount), DomainException::class, 'active cash or bank account');
    assert_same(0, (int) DB::queryFirstField("SELECT COUNT(*) FROM pl_journals WHERE book_id=%i AND source_type IN ('open_item_recognition','open_item_settlement')", $f['book_id']));
    assert_same('0.0000', trading_account_movement($f, $f['accounts']['1000']));
});

test('reversing a cash invoice releases the cash and the receivable together, and correction says so', function (): void {
    $f = trading_fixture();
    trading_set_policies($f, ['cash_on_invoice_cap' => '500']);
    $document = trading_post($f, trading_invoice_input($f, ['cash_received' => '100', 'cash_account_id' => $f['accounts']['1000']]));
    $input = trading_invoice_input($f, ['cash_received' => '100', 'cash_account_id' => $f['accounts']['1000'], 'date' => gmdate('Y-m-d'), 'due_date' => gmdate('Y-m-d')]);
    assert_throws(fn() => pl_correct_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], $document['id'], $input, 1, bin2hex(random_bytes(16)), 'Fix the price'),
        DomainException::class, 'Reverse it and enter a corrected invoice');
    $reversed = pl_reverse_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], $document['id'], null, 'Wrong customer');
    assert_same('reversed', $reversed['payment_status']);
    assert_same('0.0000', trading_account_movement($f, $f['accounts']['1000']), 'The cash was not released by the reversal.');
    assert_same('0.0000', trading_account_movement($f, $f['accounts']['4000']), 'The income was not released by the reversal.');
    assert_same('0.0000', trading_account_movement($f, (int) $document['open_item']['control_account_id']));
});

test('the sales-staff and area dimensions are validated, audited and kept on the revision snapshot', function (): void {
    $f = trading_fixture();
    $document = trading_post($f, trading_invoice_input($f, ['sales_staff_id' => $f['staff_id'], 'area_id' => $f['area_id']]));
    assert_same($f['staff_id'], $document['sales_staff_id']);
    assert_same($f['area_id'], $document['area_id']);
    $snapshot = json_decode((string) DB::queryFirstField('SELECT source_snapshot FROM pl_ar_document_revisions WHERE document_id=%i ORDER BY revision DESC LIMIT 1', $document['id']), true, 512, JSON_THROW_ON_ERROR);
    assert_same($f['staff_id'], (int) $snapshot['sales_staff_id']);
    assert_same($f['area_id'], (int) $snapshot['area_id']);
    // A correction keeps the source identity and carries the dimensions into the new revision.
    $corrected = pl_correct_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], $document['id'],
        trading_invoice_input($f, ['sales_staff_id' => $f['staff_id'], 'area_id' => $f['area_id'], 'date' => gmdate('Y-m-d'), 'due_date' => gmdate('Y-m-d'),
            'reference' => (string) $document['reference']]), 1, bin2hex(random_bytes(16)), 'Correct the route');
    assert_same(2, $corrected['revision']);
    assert_same($f['area_id'], $corrected['area_id']);
    // A retired dimension cannot be used on a new document; historical ones stay readable.
    pl_save_area($f['actor_id'], $f['company_id'], $f['book_id'], ['code' => 'GULBERG', 'name' => 'Sample area route', 'is_active' => false, 'reason' => 'Route closed'], $f['area_id'], 1);
    assert_same($f['area_id'], pl_get_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], (int) $document['id'])['area_id'], 'A retired route stopped a posted document from reading.');
    assert_throws(fn() => pl_save_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], trading_invoice_input($f, ['area_id' => $f['area_id']])), DomainException::class, 'active area or route');
    // Another book's route is not in this book's scope at all.
    $other = trading_fixture();
    assert_throws(fn() => pl_save_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], trading_invoice_input($f, ['area_id' => $other['area_id']])), DomainException::class, 'not available');
});

test('the company profile is per company, empty by default, owner-only and audited', function (): void {
    $f = trading_fixture();
    $empty = pl_company_profile($f['actor_id'], $f['company_id']);
    assert_same(true, $empty['is_empty']);
    assert_same(0, $empty['revision']);
    foreach (pl_company_profile_fields() as $field) { assert_same('', $empty[$field]); }
    $saved = pl_save_company_profile($f['actor_id'], $f['company_id'], ['legal_name' => 'Sample Distributors (Pvt) Ltd',
        'address_line1' => 'Plot 14, Industrial Area', 'address_line2' => 'Sample City', 'address_line3' => '',
        'phone' => '042-111-556-778', 'email' => 'sales@example.test', 'tax_registrations' => 'NTN 3345678-9',
        'footer_terms' => 'Goods are exchangeable within seven days.', 'revision' => 0,
        'reason' => 'Record the letterhead', 'idempotency_key' => bin2hex(random_bytes(16))]);
    assert_same(false, $saved['is_empty']);
    assert_same('Sample Distributors (Pvt) Ltd', $saved['legal_name']);
    assert_throws(fn() => pl_save_company_profile($f['actor_id'], $f['company_id'], ['legal_name' => 'X', 'email' => 'not-an-email',
        'revision' => 1, 'reason' => 'Bad email', 'idempotency_key' => bin2hex(random_bytes(16))]), DomainException::class, 'valid email address');
    // Another business's profile is untouched: the profile is per company, not per installation.
    $other = trading_fixture();
    assert_same(true, pl_company_profile($other['actor_id'], $other['company_id'])['is_empty']);
    assert_throws(fn() => pl_company_profile($other['actor_id'], $f['company_id']), DomainException::class);
    $letterhead = pl_print_letterhead(['name' => 'Fallback name', 'book_name' => 'Primary', 'currency' => 'USD'], $saved);
    assert_same('Sample Distributors (Pvt) Ltd', $letterhead['name']);
    assert_same(2, count($letterhead['address']));
    assert_same('NTN 3345678-9', $letterhead['registrations']);
    // An empty profile never invents a letterhead line.
    $blank = pl_print_letterhead(['name' => 'Fallback name', 'book_name' => 'Primary', 'currency' => 'USD'], pl_company_profile($other['actor_id'], $other['company_id']));
    assert_same('Fallback name', $blank['name']);
    assert_same([], $blank['address']);
});

test('the invoice and statement print templates are registered with existing views and formats', function (): void {
    $templates = pl_print_templates();
    foreach (['invoice' => ['a4', '80mm'], 'statement' => ['a4']] as $type => $formats) {
        assert_true(isset($templates[$type]), 'The ' . $type . ' template is not registered.');
        assert_true(is_callable($templates[$type]['loader']) && is_callable($templates[$type]['reference']), $type . ' has no callable loader.');
        foreach ($formats as $format) {
            $resolved = pl_print_template($type, $format);
            assert_true(is_file(dirname(__DIR__) . '/www/phpledger/templates/print/' . $resolved['view']), 'Missing view for ' . $type . '/' . $format . '.');
        }
    }
    assert_throws(fn() => pl_print_template('statement', '80mm'), DomainException::class, 'paper formats');
    // The print route itself is unchanged: still one read-only GET route driven by the registry.
    $router = (string) file_get_contents(dirname(__DIR__) . '/www/phpledger/public/index.php');
    assert_true(str_contains($router, "^/print/([a-z0-9-]{1,40})/([0-9]{1,18})$"), 'The print route pattern changed.');
});

test('printing an invoice follows the record screen: owner, accountant and viewer read, others do not', function (): void {
    $f = trading_fixture();
    trading_set_policies($f, ['cash_on_invoice_cap' => '500']);
    $document = trading_post($f, trading_invoice_input($f, ['cash_received' => '100', 'cash_account_id' => $f['accounts']['1000'],
        'sales_staff_id' => $f['staff_id'], 'area_id' => $f['area_id']], [[
            'description' => 'Sample discounted sale', 'quantity' => '10', 'unit_price' => '25', 'discount_percent' => '10',
            'account_id' => $f['accounts']['4000'], 'product_id' => $f['product_id']]]));
    $suffix = bin2hex(random_bytes(8));
    $readers = [];
    foreach (['accountant', 'viewer'] as $role) {
        $readers[$role] = pl_create_user('trading-' . $role . '-' . $suffix . '@example.test', 'Sample trading ' . $role, 'Sample-test-password-' . $suffix);
        DB::insert('pl_company_members', ['company_id' => $f['company_id'], 'user_id' => $readers[$role], 'role' => $role]);
    }
    $stranger = pl_create_user('trading-stranger-' . $suffix . '@example.test', 'Sample trading stranger', 'Sample-test-password-' . $suffix);
    foreach (['owner' => $f['actor_id']] + $readers as $role => $readerId) {
        $print = pl_trading_invoice_print($readerId, $f['company_id'], $f['book_id'], (int) $document['id']);
        assert_same('225.0000', (string) $print['document']['total'], 'A ' . $role . ' could not print what the record screen shows.');
        assert_same('125.0000', (string) $print['balance_due']);
        assert_same($document['number'], pl_print_invoice_reference($print));
        $statement = pl_trading_statement_print($readerId, $f['company_id'], $f['book_id'], $f['party_id']);
        assert_true(str_contains(pl_print_statement_reference($statement), 'Sample trading customer'), 'The statement reference names no party.');
    }
    assert_throws(fn() => pl_trading_invoice_print($stranger, $f['company_id'], $f['book_id'], (int) $document['id']), DomainException::class, 'do not have access');
    assert_throws(fn() => pl_trading_statement_print($stranger, $f['company_id'], $f['book_id'], $f['party_id']), DomainException::class, 'do not have access');
    $other = trading_fixture();
    assert_throws(fn() => pl_trading_invoice_print($other['actor_id'], $other['company_id'], $other['book_id'], (int) $document['id']), DomainException::class, 'not available');
    // A draft has no number yet, and only a customer document prints on this form.
    $draft = pl_save_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], trading_invoice_input($f));
    assert_throws(fn() => pl_trading_invoice_print($f['actor_id'], $f['company_id'], $f['book_id'], (int) $draft['id']), DomainException::class, 'Post the document');
    $bill = trading_post($f, trading_invoice_input($f, ['kind' => 'bill'], [['description' => 'Sample bill', 'quantity' => '1', 'unit_price' => '50', 'account_id' => $f['accounts']['5000']]]));
    assert_throws(fn() => pl_trading_invoice_print($f['actor_id'], $f['company_id'], $f['book_id'], (int) $bill['id']), DomainException::class, 'customer invoice or credit note');
});

test('the party statement reconciles to the ledger control account over its range', function (): void {
    $f = trading_fixture();
    $first = trading_post($f, trading_invoice_input($f, ['date' => '2026-01-10', 'due_date' => '2026-02-10']));
    pl_settle_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], (int) $first['id'], ['bank_account_id' => $f['accounts']['1000'],
        'amount_fc' => '100', 'date' => '2026-01-20', 'description' => 'Sample part payment', 'idempotency_key' => bin2hex(random_bytes(16))]);
    $second = trading_post($f, trading_invoice_input($f, ['date' => '2026-02-05', 'due_date' => '2026-03-05']));
    $statement = pl_party_statement($f['actor_id'], $f['company_id'], $f['book_id'], $f['party_id'], '2026-01-01', '2026-01-31');
    assert_same('0.0000', $statement['opening_balance']);
    assert_same('250.0000', $statement['invoiced']);
    assert_same('100.0000', $statement['received']);
    assert_same('150.0000', $statement['closing_balance']);
    assert_same(true, $statement['reconciles']);
    // The February statement opens on January's close and ends on the ledger's control balance.
    $february = pl_party_statement($f['actor_id'], $f['company_id'], $f['book_id'], $f['party_id'], '2026-02-01', '2026-02-28');
    assert_same('150.0000', $february['opening_balance']);
    assert_same('400.0000', $february['closing_balance']);
    assert_same(true, $february['reconciles']);
    $control = (int) pl_get_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], (int) $second['id'])['open_item']['control_account_id'];
    assert_same(trading_account_movement($f, $control), $february['closing_balance'], 'The statement does not equal the control account.');
    assert_throws(fn() => pl_party_statement($f['actor_id'], $f['company_id'], $f['book_id'], $f['party_id'], '2026-03-01', '2026-02-01'), DomainException::class, 'on or before');
});

test('a trading document keeps working when the module is disabled and is refused for new entry', function (): void {
    $f = trading_fixture();
    $document = trading_post($f, trading_invoice_input($f, ['sales_staff_id' => $f['staff_id']]));
    $manifest = pl_module_registry()['trading-documents'];
    pl_set_company_module($f['actor_id'], $f['company_id'], 'trading-documents', false, 1, $manifest['digest'], 'Retire trading entry', bin2hex(random_bytes(16)));
    // The posted document, its dimensions and its print stay readable.
    assert_same($f['staff_id'], pl_get_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], (int) $document['id'])['sales_staff_id']);
    assert_same('250.0000', (string) pl_trading_invoice_print($f['actor_id'], $f['company_id'], $f['book_id'], (int) $document['id'])['document']['total']);
    // New reference data needs the enabled module.
    assert_throws(fn() => pl_save_area($f['actor_id'], $f['company_id'], $f['book_id'], ['code' => 'NEW', 'name' => 'Sample new area', 'is_active' => true, 'reason' => 'After disabling']), DomainException::class, 'disabled for this company');
});

test('the trading module manifest declares its dependencies and its one migration', function (): void {
    $manifest = pl_module_registry()['trading-documents'];
    assert_same('trading-documents', $manifest['id']);
    assert_same(true, $manifest['optional']);
    assert_same(1, $manifest['contract']);
    assert_same(['core' => '1.0.0', 'ar' => '1.0.0', 'inventory' => '1.0.0'], $manifest['requires']);
    assert_same(['037_trading_documents'], $manifest['migrations']);
    assert_true(is_file(dirname(__DIR__) . '/www/phpledger/install/migrations/037_trading_documents.php'), 'Migration 037 is missing.');
});

/*
 * Screen-path tests. These drive the same adapters the browser posts into —
 * pl_starter_lines(), pl_web_ar_editor_input() and pl_web_ar_editor_preview() — so the
 * controls added to the editor are proved to reach the services, not just the services.
 */
require_once dirname(__DIR__) . '/www/phpledger/includes/functions/starter_web_functions.php';
require_once dirname(__DIR__) . '/www/phpledger/includes/functions/starter_ar_web_functions.php';
require_once dirname(__DIR__) . '/www/phpledger/templates/partials/ui/components.php';
require_once dirname(__DIR__) . '/www/phpledger/templates/partials/ui/commercial-lines.php';

/** One POST body exactly as the invoice editor submits it. */
function trading_editor_post(array $f, array $header = [], array $lines = []): array
{
    return array_replace([
        'action' => 'save', 'kind' => 'invoice', 'party_id' => (string) $f['party_id'],
        'date' => '2026-01-10', 'due_date' => '2026-02-10', 'currency' => 'USD',
        'reference' => 'Sample screen reference', 'terms' => '', 'notes' => '',
        'price_mode' => 'exclusive', 'rounding_account_id' => '', 'rate' => '',
        'request_key' => bin2hex(random_bytes(16)),
        'lines' => $lines === [] ? [['description' => 'Sample screen sale', 'quantity' => '10', 'unit_price' => '25',
            'account_id' => (string) $f['accounts']['4000'], 'product_id' => (string) $f['product_id']]] : $lines,
    ], $header);
}

test('the editor screen path carries a pack line through to a resolved base quantity', function (): void {
    $f = trading_fixture();
    $post = trading_editor_post($f, [], [[
        'description' => 'Sample cartons', 'pack_id' => (string) $f['pack_id'], 'pack_quantity' => '5', 'unit_quantity' => '3',
        'quantity' => '', 'unit_price' => '2.5', 'account_id' => (string) $f['accounts']['4000'], 'product_id' => (string) $f['product_id'],
    ]]);
    $line = pl_starter_lines($post)[0];
    assert_same($f['pack_id'], $line['pack_id'], 'The pack selection did not survive the screen adapter.');
    assert_same('5', $line['pack_quantity']);
    assert_same('3', $line['unit_quantity']);
    $input = pl_web_ar_editor_input($post, 'invoice');
    $document = trading_post($f, $input);
    assert_same('63.0000', $document['lines'][0]['quantity'], '5 cartons of 12 plus 3 loose units is 63 base units.');
    assert_same('157.5000', $document['total']);
});

test('the editor screen path carries a line discount and shows the amount it computes', function (): void {
    $f = trading_fixture();
    $post = trading_editor_post($f, [], [[
        'description' => 'Sample discounted sale', 'quantity' => '10', 'unit_price' => '25', 'discount_percent' => '10',
        'account_id' => (string) $f['accounts']['4000'], 'product_id' => (string) $f['product_id'],
    ]]);
    assert_same('10', pl_starter_lines($post)[0]['discount_percent']);
    $document = trading_post($f, pl_web_ar_editor_input($post, 'invoice'));
    assert_same('25.0000', $document['discount_total']);
    assert_same('225.0000', $document['total']);
    ob_start();
    pl_ui_commercial_lines([['description' => 'Sample discounted sale', 'quantity' => '10', 'unit_price' => '25', 'discount_percent' => '10']],
        [], false, false, [], ['enabled' => true, 'packs' => [], 'policies' => pl_trading_policy_defaults()]);
    $html = (string) ob_get_clean();
    assert_true(str_contains($html, 'name="lines[0][discount_percent]"'), 'The editor has no line discount control.');
    assert_true(str_contains($html, '225.00'), 'The row does not show the net amount the line will post.');
    assert_true(str_contains($html, '25.00'), 'The row does not show the discount amount it computed.');
});

test('the editor screen path marks a free-goods line and keeps it out of the money columns', function (): void {
    $f = trading_fixture();
    trading_set_policies($f, ['free_goods_account_id' => $f['promotion_account_id']]);
    $post = trading_editor_post($f, [], [
        ['description' => 'Sample paid sale', 'quantity' => '10', 'unit_price' => '25', 'account_id' => (string) $f['accounts']['4000'], 'product_id' => (string) $f['product_id']],
        ['description' => 'Sample bonus', 'quantity' => '2', 'unit_price' => '', 'is_free_goods' => '1', 'account_id' => (string) $f['accounts']['4000'], 'product_id' => (string) $f['product_id']],
    ]);
    $lines = pl_starter_lines($post);
    assert_same(false, $lines[0]['is_free_goods']);
    assert_same(true, $lines[1]['is_free_goods'], 'The free-goods checkbox did not survive the screen adapter.');
    $document = trading_post($f, pl_web_ar_editor_input($post, 'invoice'));
    assert_same('250.0000', $document['total'], 'The bonus line was charged to the customer.');
    assert_same('4.0000', trading_account_movement($f, $f['promotion_account_id']));
    ob_start();
    pl_ui_commercial_lines([['description' => 'Sample bonus', 'quantity' => '2', 'is_free_goods' => '1']],
        [], false, false, [], ['enabled' => true, 'packs' => [], 'policies' => pl_trading_policy_defaults()]);
    $html = (string) ob_get_clean();
    assert_true(str_contains($html, 'doc-line-free'), 'A free-goods row is not marked as one.');
    assert_true(str_contains($html, 'Bonus, not charged'), 'The free row does not say why its amount is nothing.');
    assert_true(str_contains($html, 'name="lines[0][is_free_goods]"'), 'The editor has no free-goods control.');
});

test('the screen refuses a free-goods line on a credit note and cash above the cap', function (): void {
    $f = trading_fixture();
    $invoice = trading_post($f, trading_invoice_input($f));
    $creditPost = trading_editor_post($f, ['kind' => 'customer_credit', 'original_document_id' => (string) $invoice['id'],
        'date' => '2026-02-01', 'due_date' => '2026-02-01'], [
        ['description' => 'Sample returned bonus', 'quantity' => '1', 'unit_price' => '', 'is_free_goods' => '1',
            'account_id' => (string) $f['accounts']['4000'], 'product_id' => (string) $f['product_id'], 'original_line_number' => '1'],
    ]);
    $creditInput = pl_web_ar_editor_input($creditPost, 'customer_credit');
    assert_throws(fn() => pl_save_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], $creditInput), DomainException::class, 'accompany a customer invoice');
    trading_set_policies($f, ['cash_on_invoice_cap' => '50']);
    $cashPost = trading_editor_post($f, ['cash_received' => '100', 'cash_account_id' => (string) $f['accounts']['1000']]);
    $cashInput = pl_web_ar_editor_input($cashPost, 'invoice');
    assert_same('100', $cashInput['cash_received'], 'Cash received did not survive the screen adapter.');
    assert_same($f['accounts']['1000'], $cashInput['cash_account_id']);
    assert_throws(fn() => pl_save_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], $cashInput), DomainException::class, 'cash-on-invoice cap');
    assert_throws(fn() => pl_web_ar_editor_preview($f['actor_id'], $f['company_id'], $f['book_id'], 0, $cashPost, 'invoice'), DomainException::class, 'cash-on-invoice cap');
    assert_same('0.0000', trading_account_movement($f, $f['accounts']['1000']));
    trading_set_policies($f, ['cash_on_invoice_cap' => '500']);
    $preview = pl_web_ar_editor_preview($f['actor_id'], $f['company_id'], $f['book_id'], 0, $cashPost, 'invoice');
    assert_same('100.0000', (string) $preview['document']['cash_received']);
    $posted = trading_post($f, pl_web_ar_editor_input($cashPost, 'invoice'));
    assert_true($posted['cash_settlement_journal_id'] !== null, 'The screen path did not settle the cash it recorded.');
    assert_same('150.0000', $posted['outstanding_fc']);
});

test('the editor screen carries the dimensions and offers only what the policies allow', function (): void {
    $f = trading_fixture();
    $post = trading_editor_post($f, ['sales_staff_id' => (string) $f['staff_id'], 'area_id' => (string) $f['area_id']]);
    $input = pl_web_ar_editor_input($post, 'invoice');
    assert_same($f['staff_id'], $input['sales_staff_id'], 'The sales-staff selector did not survive the screen adapter.');
    assert_same($f['area_id'], $input['area_id']);
    $document = trading_post($f, $input);
    assert_same($f['staff_id'], $document['sales_staff_id']);
    $context = pl_trading_editor_context($f['actor_id'], $f['company_id'], $f['book_id']);
    assert_same(true, $context['enabled']);
    assert_same(1, count($context['sales_staff']));
    assert_same(1, count($context['areas']));
    assert_same(1, count($context['packs']));
    assert_same('0.0000', $context['policies']['cash_on_invoice_cap'], 'The cash panel would be offered without a cap.');
    $manifest = pl_module_registry()['trading-documents'];
    pl_set_company_module($f['actor_id'], $f['company_id'], 'trading-documents', false, 1, $manifest['digest'], 'Retire trading entry', bin2hex(random_bytes(16)));
    $off = pl_trading_editor_context($f['actor_id'], $f['company_id'], $f['book_id']);
    assert_same(false, $off['enabled']);
    assert_same([], $off['packs']);
    assert_same([], $off['sales_staff']);
    assert_same('net', $off['policies']['discount_posting']);
});

test('the readout strip reads the customer balance and stock without posting anything', function (): void {
    $f = trading_fixture();
    $invoice = trading_post($f, trading_invoice_input($f));
    $before = (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id=%i', $f['book_id']);
    $readout = pl_trading_editor_readout($f['actor_id'], $f['company_id'], $f['book_id'], $f['party_id'], $f['product_id'], null);
    assert_same('250.0000', $readout['party_balance'], 'The strip does not show what the customer owes.');
    assert_same('190.0000', $readout['book_stock']['quantity']);
    assert_same('190.0000', $readout['warehouse_stock']['quantity']);
    assert_same('Sample stock item', $readout['product']['name']);
    assert_same(null, $readout['van_stock'], 'Van stock is not available until vans are a warehouse kind.');
    assert_same($before, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id=%i', $f['book_id']), 'The readout strip posted something.');
    $empty = pl_trading_editor_readout($f['actor_id'], $f['company_id'], $f['book_id'], null, null, null);
    assert_same(null, $empty['party_balance']);
    assert_same(null, $empty['book_stock']);
    assert_same((string) $invoice['currency'], $empty['currency']);
});
