<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/www/phpledger/includes/functions/web_functions.php';

test('product register pages and filters without changing valuation scope',function(): void {
    $f=inventory_fixture();
    for ($i=0;$i<26;$i++) {
        $input=inventory_product_input($f); $input['name']='Paged '.str_pad((string)$i,2,'0',STR_PAD_LEFT); $input['sku']='PAGE-'.$i;
        $input['is_active']=$i%2===0; pl_save_inventory_product($f['actor_id'],$f['company_id'],$f['book_id'],$input);
    }
    $args=[$f['actor_id'],$f['company_id'],$f['book_id']];
    pl_inventory_receive(...array_merge($args,[inventory_move_input($f,'3','10')]));
    $before=pl_inventory_valuation(...$args);
    $run=fn(array $q):array=>pl_list_query(...array_merge($args,['inventory',$q]));
    $first=$run(['q'=>'Paged']); $last=$run(['q'=>'Paged','page'=>'999']);
    assert_same(26,$first['total']); assert_same(25,count($first['rows'])); assert_same(2,$last['page']); assert_same(1,count($last['rows']));
    assert_same('Paged 25',$last['rows'][0]['name']); assert_same(13,$run(['q'=>'Paged','status'=>'inactive'])['total']);
    assert_same(0,$run(['kind'=>'nonstock'])['total']); assert_same(0,$run(['q'=>'%'])['total']);
    assert_same('Paged 25',$run(['q'=>'Paged','dir'=>'desc'])['rows'][0]['name']);
    foreach ([['sort'=>'name DESC'],['kind'=>'service'],['status'=>'posted'],['as_of'=>'invalid']] as $bad) { assert_throws(fn()=>$run($bad),DomainException::class); }
    assert_same($before,pl_inventory_valuation(...$args));
    $other=ledger_fixture(); assert_throws(fn()=>pl_page_inventory_products($other['actor_id'],$f['company_id'],$f['book_id'],[]),DomainException::class);
});

function inventory_fixture(): array
{
    $f = ledger_fixture();
    $manifest = pl_module_registry()['inventory'];
    pl_set_company_module($f['actor_id'], $f['company_id'], 'inventory', true, 0, $manifest['digest'], 'Sample stock module', bin2hex(random_bytes(16)));
    foreach (['inventory' => ['1300','asset'], 'grni' => ['2100','liability'], 'variance' => ['5200','expense']] as $name => [$code,$type]) {
        $account = pl_save_account($f['actor_id'], $f['company_id'], $f['book_id'], ['code' => $code, 'name' => 'Sample ' . $name, 'type' => $type, 'role' => null,
            'is_active' => true, 'reason' => 'Sample inventory chart', 'creation_key' => bin2hex(random_bytes(16))]);
        $f[$name . '_account_id'] = $account['id'];
    }
    $p = pl_save_inventory_product($f['actor_id'], $f['company_id'], $f['book_id'], inventory_product_input($f));
    return $f + ['product_id' => $p['id']];
}

function inventory_product_input(array $f): array
{
    return ['sku' => 'SYNTH-' . bin2hex(random_bytes(4)), 'name' => 'Sample stock item', 'kind' => 'stock', 'base_unit' => 'each', 'selling_price' => '25', 'is_active' => true,
        'inventory_account_id' => $f['inventory_account_id'], 'cogs_account_id' => $f['accounts']['5000'], 'sales_account_id' => $f['accounts']['4000'], 'purchase_account_id' => $f['accounts']['5000'],
        'reason' => 'Sample product fixture', 'idempotency_key' => bin2hex(random_bytes(16))];
}

function inventory_move_input(array $f, string $quantity = '10', string $value = '100', string $date = '2026-01-05'): array
{
    return ['product_id' => $f['product_id'], 'quantity' => $quantity, 'amount_base' => $value, 'date' => $date, 'offset_account_id' => $f['grni_account_id'],
        'source_type' => 'test_stock', 'source_reference' => bin2hex(random_bytes(16)), 'reason' => 'Sample inventory movement', 'idempotency_key' => bin2hex(random_bytes(16))];
}

test('inventory weighted average issues exact carrying residual and reconciles its GL', function (): void {
    $f = inventory_fixture();
    pl_inventory_receive($f['actor_id'], $f['company_id'], $f['book_id'], inventory_move_input($f, '6', '60'));
    pl_inventory_receive($f['actor_id'], $f['company_id'], $f['book_id'], inventory_move_input($f, '4', '60', '2026-01-06'));
    $input = inventory_move_input($f, '3', '0', '2026-01-07'); unset($input['offset_account_id']);
    $issue = pl_inventory_issue($f['actor_id'], $f['company_id'], $f['book_id'], $input);
    assert_same('36.0000', $issue['value_base']);
    assert_true($issue == pl_inventory_issue($f['actor_id'], $f['company_id'], $f['book_id'], $input));
    $report = pl_inventory_valuation($f['actor_id'], $f['company_id'], $f['book_id'], '2026-01-07');
    assert_same('84.0000', $report['total_value_base']); assert_same('0.0000', $report['accounts'][0]['difference']);
    $input = inventory_move_input($f, '7', '0', '2026-01-08'); unset($input['offset_account_id']);
    assert_same('84.0000', pl_inventory_issue($f['actor_id'], $f['company_id'], $f['book_id'], $input)['value_base']);
    assert_same('0.0000', pl_inventory_balance($f['actor_id'], $f['company_id'], $f['book_id'], $f['product_id'])['value_base']);
    assert_same('120.0000', pl_inventory_valuation($f['actor_id'], $f['company_id'], $f['book_id'], '2026-01-06')['total_value_base']);
});

test('inventory precise thirds never leave value without stock', function (): void {
    assert_same('0.3333', pl_inventory_cost('1', '3', '1'));
    $f = inventory_fixture(); pl_inventory_receive($f['actor_id'], $f['company_id'], $f['book_id'], inventory_move_input($f, '3', '1'));
    $float = inventory_move_input($f); $float['quantity'] = 0.1;
    assert_throws(fn() => pl_inventory_receive($f['actor_id'], $f['company_id'], $f['book_id'], $float), DomainException::class, 'text');
    $values = [];
    for ($i = 0; $i < 3; $i++) { $values[] = pl_inventory_issue($f['actor_id'], $f['company_id'], $f['book_id'], inventory_move_input($f, '1', '0', '2026-01-06'))['value_base']; }
    assert_same(['0.3333','0.3334','0.3333'], $values);
    assert_same('0.0000', pl_inventory_balance($f['actor_id'], $f['company_id'], $f['book_id'], $f['product_id'])['value_base']);
});

test('partial purchase and customer returns use original costs and reject excess returns', function (): void {
    $f = inventory_fixture(); $receipt = pl_inventory_receive($f['actor_id'], $f['company_id'], $f['book_id'], inventory_move_input($f));
    $issue = pl_inventory_issue($f['actor_id'], $f['company_id'], $f['book_id'], inventory_move_input($f, '4', '0', '2026-01-06'));
    $returned = inventory_move_input($f, '2', '0', '2026-01-07') + ['original_movement_id' => $issue['movement_id']];
    assert_same('20.0000', pl_inventory_return($f['actor_id'], $f['company_id'], $f['book_id'], $returned)['value_base']);
    $purchase = inventory_move_input($f, '3', '0', '2026-01-08') + ['original_movement_id' => $receipt['movement_id']];
    assert_same('-30.0000', pl_inventory_return($f['actor_id'], $f['company_id'], $f['book_id'], $purchase)['value_delta']);
    $returned['quantity'] = '3'; $returned['idempotency_key'] = bin2hex(random_bytes(16)); $returned['source_reference'] = bin2hex(random_bytes(16));
    assert_throws(fn() => pl_inventory_return($f['actor_id'], $f['company_id'], $f['book_id'], $returned), DomainException::class, 'available');
    $report = pl_inventory_valuation($f['actor_id'], $f['company_id'], $f['book_id'], '2026-01-08');
    assert_same('50.0000', $report['total_value_base']); assert_same('0.0000', $report['accounts'][0]['difference']);
});

test('stock adjustments require a fresh count and reviewed positive increase cost', function (): void {
    $f = inventory_fixture(); pl_inventory_receive($f['actor_id'], $f['company_id'], $f['book_id'], inventory_move_input($f));
    $input = inventory_move_input($f, '0', '0', '2026-01-06') + ['expected_quantity' => '10', 'counted_quantity' => '8'];
    $input['offset_account_id'] = $f['variance_account_id'];
    assert_same('-20.0000', pl_inventory_adjust_count($f['actor_id'], $f['company_id'], $f['book_id'], $input)['value_delta']);
    $input['idempotency_key'] = bin2hex(random_bytes(16)); $input['source_reference'] = bin2hex(random_bytes(16));
    assert_throws(fn() => pl_inventory_adjust_count($f['actor_id'], $f['company_id'], $f['book_id'], $input), DomainException::class, 'changed');
    $input['expected_quantity'] = '8'; $input['counted_quantity'] = '9';
    assert_throws(fn() => pl_inventory_adjust_count($f['actor_id'], $f['company_id'], $f['book_id'], $input), DomainException::class, 'reviewed');
    $input['unit_cost'] = '12';
    assert_same('12.0000', pl_inventory_adjust_count($f['actor_id'], $f['company_id'], $f['book_id'], $input)['value_delta']);
});

test('reviewed value adjustments preserve quantity and reject stale value snapshots', function (): void {
    $f = inventory_fixture(); pl_inventory_receive($f['actor_id'], $f['company_id'], $f['book_id'], inventory_move_input($f));
    $input = inventory_move_input($f, '0', '-10', '2026-01-06') + ['expected_quantity' => '10', 'expected_value_base' => '100'];
    $input['offset_account_id'] = $f['variance_account_id'];
    $result = pl_inventory_value_adjustment($f['actor_id'], $f['company_id'], $f['book_id'], $input);
    assert_same('-10.0000', $result['value_delta']); assert_same('0.0000', $result['quantity_delta']);
    assert_true($result == pl_inventory_value_adjustment($f['actor_id'], $f['company_id'], $f['book_id'], $input));
    $input['idempotency_key'] = bin2hex(random_bytes(16)); $input['source_reference'] = bin2hex(random_bytes(16));
    assert_throws(fn() => pl_inventory_value_adjustment($f['actor_id'], $f['company_id'], $f['book_id'], $input), DomainException::class, 'changed');
    assert_same('10.0000', pl_inventory_balance($f['actor_id'], $f['company_id'], $f['book_id'], $f['product_id'])['quantity']);
    assert_same('90.0000', pl_inventory_valuation($f['actor_id'], $f['company_id'], $f['book_id'], '2026-01-06')['total_value_base']);
});

test('stock rejects negative balances backdating closed periods duplicate sources and outer failures atomically', function (): void {
    $f = inventory_fixture(); $input = inventory_move_input($f); $r = pl_inventory_receive($f['actor_id'], $f['company_id'], $f['book_id'], $input);
    $input['idempotency_key'] = bin2hex(random_bytes(16));
    assert_throws(fn() => pl_inventory_receive($f['actor_id'], $f['company_id'], $f['book_id'], $input), DomainException::class, 'source already');
    assert_throws(fn() => pl_inventory_issue($f['actor_id'], $f['company_id'], $f['book_id'], inventory_move_input($f, '11')), DomainException::class, 'available');
    assert_throws(fn() => pl_inventory_receive($f['actor_id'], $f['company_id'], $f['book_id'], inventory_move_input($f, '1', '1', '2026-01-04')), DomainException::class, 'backdated');
    $before = (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id=%i', $f['book_id']);
    assert_throws(function () use ($f): void { pl_ledger_transaction(function () use ($f): void { pl_inventory_receive($f['actor_id'], $f['company_id'], $f['book_id'], inventory_move_input($f)); throw new DomainException('Sample outer rollback'); }); });
    assert_same($before, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id=%i', $f['book_id']));
    DB::update('pl_periods', ['status' => 'closed'], 'id=%i', $f['period_id']);
    assert_throws(fn() => pl_inventory_issue($f['actor_id'], $f['company_id'], $f['book_id'], inventory_move_input($f, '1')), DomainException::class, 'open accounting period');
    DB::update('pl_periods', ['status' => 'open'], 'id=%i', $f['period_id']);
    assert_throws(fn() => pl_reverse_journal($f['actor_id'], $f['company_id'], $f['book_id'], $r['journal_id'], gmdate('Y-m-d'), bin2hex(random_bytes(16)), 'Incorrect standalone reversal'), DomainException::class, 'inventory');
    assert_throws(fn() => DB::update('pl_inventory_movements', ['value_delta' => '1'], 'id=%i', $r['movement_id']), Throwable::class, 'immutable');
});

test('inventory enabled writes and historical reads enforce company permissions', function (): void {
    $f = inventory_fixture(); $other = ledger_fixture();
    $input = inventory_move_input($f); pl_inventory_receive($f['actor_id'], $f['company_id'], $f['book_id'], $input);
    assert_throws(fn() => pl_inventory_receive($other['actor_id'], $f['company_id'], $f['book_id'], $input), DomainException::class);
    assert_throws(fn() => pl_inventory_valuation($other['actor_id'], $f['company_id'], $f['book_id']), DomainException::class);
    sample_membership_insert(['company_id' => $f['company_id'], 'user_id' => $other['actor_id'], 'role' => 'viewer']);
    assert_throws(fn() => pl_inventory_receive($other['actor_id'], $f['company_id'], $f['book_id'], $input), DomainException::class);
    assert_same(1, count(pl_inventory_history($other['actor_id'], $f['company_id'], $f['book_id'])));
    pl_set_company_module($f['actor_id'], $f['company_id'], 'inventory', false, 1, pl_module_registry()['inventory']['digest'], 'Sample disable', bin2hex(random_bytes(16)));
    assert_throws(fn() => pl_inventory_issue($f['actor_id'], $f['company_id'], $f['book_id'], inventory_move_input($f, '1')), DomainException::class, 'disabled');
    assert_same('100.0000', pl_inventory_valuation($f['actor_id'], $f['company_id'], $f['book_id'], '2026-01-05')['total_value_base']);
});

test('inventory simultaneous duplicate and competing issues serialize the remaining stock', function (): void {
    $f = inventory_fixture(); pl_inventory_receive($f['actor_id'], $f['company_id'], $f['book_id'], inventory_move_input($f));
    $job = ['mode' => 'inventory_issue', 'fixture' => $f, 'inventory_input' => inventory_move_input($f, '6', '0', '2026-01-06')];
    $same = ledger_race([$job,$job]); assert_same($same[0]['id'], $same[1]['id']);
    $one = $job; $one['allow_domain_failure'] = true; $one['inventory_input'] = inventory_move_input($f, '3', '0', '2026-01-06');
    $two = $one; $two['inventory_input'] = inventory_move_input($f, '3', '0', '2026-01-06');
    $race = ledger_race([$one,$two]); assert_same(1, count(array_filter($race, static fn (array $row): bool => $row['id'] > 0)));
    assert_same('1.0000', pl_inventory_balance($f['actor_id'], $f['company_id'], $f['book_id'], $f['product_id'])['quantity']);
});

test('reviewed stock opening conversion links existing value without reposting the GL', function (): void {
    $f = inventory_fixture();
    DB::update('pl_companies', ['setup_status' => 'opening_required'], 'id=%i', $f['company_id']);
    $opening = pl_preview_opening($f['actor_id'], $f['company_id'], $f['book_id'], ['cutover_date' => '2026-01-01', 'source' => 'Sample stock cutover', 'unpaid_documents' => [],
        'balances' => [['account_code' => '1300', 'debit' => '100', 'credit' => '0'], ['account_code' => '3000', 'debit' => '0', 'credit' => '100']]], bin2hex(random_bytes(16)));
    $cutover = pl_confirm_opening($f['actor_id'], $f['company_id'], $f['book_id'], (int) $opening['id'], $opening['payload_hash'], true);
    $input = ['date' => '2026-01-01', 'reason' => 'Sample reviewed stock count and invoice evidence', 'lines' => [['product_id' => $f['product_id'], 'quantity' => '10', 'amount_base' => '100']]];
    $bad = $input; $bad['lines'][0]['amount_base'] = '99';
    assert_throws(fn() => pl_preview_inventory_opening($f['actor_id'], $f['company_id'], $f['book_id'], $bad), DomainException::class, 'exactly equal');
    $preview = pl_preview_inventory_opening($f['actor_id'], $f['company_id'], $f['book_id'], $input);
    $key = bin2hex(random_bytes(16));
    assert_throws(fn() => pl_confirm_inventory_opening($f['actor_id'], $f['company_id'], $f['book_id'], $preview['id'], $preview['payload_hash'], false, $key), DomainException::class, 'Confirm');
    $result = pl_confirm_inventory_opening($f['actor_id'], $f['company_id'], $f['book_id'], $preview['id'], $preview['payload_hash'], true, $key);
    assert_same(false, $result['journal_created']);
    assert_true($result == pl_confirm_inventory_opening($f['actor_id'], $f['company_id'], $f['book_id'], $preview['id'], $preview['payload_hash'], true, $key));
    assert_same(1, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id=%i', $f['book_id']));
    assert_same('0.0000', pl_inventory_valuation($f['actor_id'], $f['company_id'], $f['book_id'], '2026-01-01')['accounts'][0]['difference']);
    assert_throws(fn() => pl_preview_inventory_opening($f['actor_id'], $f['company_id'], $f['book_id'], $input), DomainException::class, 'precede');
    assert_throws(fn() => pl_inventory_assert_reversal_allowed($f['company_id'], $f['book_id'], (int) $cutover['journal_id']), DomainException::class, 'inventory');
    assert_throws(fn() => pl_reverse_opening($f['actor_id'], $f['company_id'], $f['book_id'], (int) $cutover['id'], 'Sample forbidden opening restart'), DomainException::class, 'inventory');
});

test('invoice stock adapter is atomic and its reversal restores original issue cost', function (): void {
    $f = inventory_fixture(); pl_inventory_receive($f['actor_id'], $f['company_id'], $f['book_id'], inventory_move_input($f));
    $journal = pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], ledger_payload($f, '75'));
    $document = ['id' => 123, 'document_date' => '2026-09-14', 'journal_id' => $journal['id'], 'lines' => [['product_id' => $f['product_id'], 'quantity' => '3']]];
    $issued = pl_inventory_issue_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], $document, $journal['id'], 'sample-invoice-123');
    assert_same('30.0000', $issued[0]['value_base']);
    assert_throws(fn() => pl_inventory_assert_reversal_allowed($f['company_id'], $f['book_id'], $journal['id']), DomainException::class, 'inventory-aware');
    pl_inventory_reverse_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], $document, '2026-09-15', 'sample-reverse-123', 'Sample invoice correction');
    pl_inventory_assert_reversal_allowed($f['company_id'], $f['book_id'], $journal['id']);
    assert_same('10.0000', pl_inventory_balance($f['actor_id'], $f['company_id'], $f['book_id'], $f['product_id'])['quantity']);
    assert_same('100.0000', pl_inventory_balance($f['actor_id'], $f['company_id'], $f['book_id'], $f['product_id'])['value_base']);
});

test('reversing a customer credit restores the invoice returnable basis without rewriting movements', function (): void {
    $f = inventory_fixture(); pl_inventory_receive($f['actor_id'], $f['company_id'], $f['book_id'], inventory_move_input($f));
    $journal = pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], ledger_payload($f, '75'));
    $document = ['id' => 123, 'document_date' => '2026-09-14', 'journal_id' => $journal['id'], 'lines' => [['product_id' => $f['product_id'], 'quantity' => '3']]];
    pl_inventory_issue_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], $document, $journal['id'], 'sample-invoice-123');
    $creditJournal = pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], ledger_payload($f, '25'));
    $credit = ['id' => 124, 'kind' => 'customer_credit', 'document_date' => '2026-09-14', 'journal_id' => $creditJournal['id'], 'lines' => [['product_id' => $f['product_id'], 'quantity' => '1']]];
    pl_inventory_credit_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], $credit, $document, 'sample-credit-124');
    assert_same('8.0000', pl_inventory_balance($f['actor_id'], $f['company_id'], $f['book_id'], $f['product_id'])['quantity']);
    pl_inventory_reverse_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], $credit, '2026-09-15', 'sample-reverse-credit-124', 'Sample credit correction');
    assert_same('7.0000', pl_inventory_balance($f['actor_id'], $f['company_id'], $f['book_id'], $f['product_id'])['quantity']);
    pl_inventory_reverse_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], $document, '2026-09-15', 'sample-reverse-invoice-123', 'Sample invoice correction');
    assert_same('10.0000', pl_inventory_balance($f['actor_id'], $f['company_id'], $f['book_id'], $f['product_id'])['quantity']);
});

test('real stock invoices and credits post and reverse financial and physical effects together', function (): void {
    $f = inventory_fixture();
    $party = pl_save_party($f['actor_id'], $f['company_id'], $f['book_id'], ['legal_name' => 'Sample stock customer', 'entity_type' => 'private_company', 'country_code' => 'GB', 'is_customer' => true, 'is_vendor' => false, 'currency' => 'USD', 'request_key' => bin2hex(random_bytes(16)), 'reason' => 'Sample stock invoice test']);
    pl_inventory_receive($f['actor_id'], $f['company_id'], $f['book_id'], inventory_move_input($f));
    $input = ['kind' => 'invoice', 'party_id' => $party['id'], 'date' => '2026-01-06', 'due_date' => '2026-02-06', 'currency' => 'USD', 'creation_key' => bin2hex(random_bytes(16)),
        'lines' => [['description' => 'Sample stock sale', 'product_id' => $f['product_id'], 'quantity' => '3', 'unit_price' => '25', 'account_id' => $f['accounts']['4000']]]];
    $invoice = pl_save_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], $input);
    $invoice = pl_post_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], $invoice['id'], 1);
    assert_same('75.0000', $invoice['outstanding_fc']);
    assert_same('7.0000', pl_inventory_balance($f['actor_id'], $f['company_id'], $f['book_id'], $f['product_id'])['quantity']);
    assert_same('70.0000', pl_inventory_balance($f['actor_id'], $f['company_id'], $f['book_id'], $f['product_id'])['value_base']);
    assert_throws(fn() => pl_reverse_journal($f['actor_id'], $f['company_id'], $f['book_id'], $invoice['journal_id'], null, bin2hex(random_bytes(16)), 'Forbidden inventory bypass'), DomainException::class, 'inventory-aware');
    $input['kind'] = 'customer_credit'; $input['original_document_id'] = $invoice['id']; $input['creation_key'] = bin2hex(random_bytes(16));
    $input['date'] = '2026-01-07'; $input['due_date'] = '2026-01-07'; $input['lines'][0]['quantity'] = '1'; $input['lines'][0]['original_line_number'] = 1;
    $credit = pl_save_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], $input);
    $credit = pl_post_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], $credit['id'], 1);
    assert_same('8.0000', pl_inventory_balance($f['actor_id'], $f['company_id'], $f['book_id'], $f['product_id'])['quantity']);
    assert_same('50.0000', pl_get_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], $invoice['id'])['outstanding_fc']);
    pl_reverse_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], $credit['id'], null, 'Sample credit cancellation');
    assert_same('7.0000', pl_inventory_balance($f['actor_id'], $f['company_id'], $f['book_id'], $f['product_id'])['quantity']);
    pl_reverse_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], $invoice['id'], null, 'Sample invoice cancellation');
    assert_same('10.0000', pl_inventory_balance($f['actor_id'], $f['company_id'], $f['book_id'], $f['product_id'])['quantity']);
    assert_same('0.0000', pl_inventory_valuation($f['actor_id'], $f['company_id'], $f['book_id'])['accounts'][0]['difference']);
    assert_same('0.0000', pl_ar_ap_open_items($f['actor_id'], $f['company_id'], $f['book_id'], 'receivable')['total_base']);
});
