<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/www/phpledger/includes/functions/web_functions.php';

function pos_fixture(): array
{
    $f = ledger_fixture();
    $manifest = pl_module_registry()['pos-showcase'];
    pl_set_company_module($f['actor_id'], $f['company_id'], 'pos-showcase', true, 0, $manifest['digest'], 'Sample POS test', 'enable-pos');
    return $f;
}

function pos_input(): array
{
    return ['checkout_key' => bin2hex(random_bytes(24)), 'catalog_digest' => pl_pos_catalog()['digest'],
        'date' => '2026-09-14', 'items' => [['sku' => 'NOTE-A5', 'quantity' => '2'], ['sku' => 'PEN-BLUE', 'quantity' => '3']],
        'cash_received' => '20.00'];
}

test('POS review prices the cart exactly without posting and matches the confirmed receipt', function (): void {
    $f = pos_fixture();
    $input = pos_input();
    unset($input['cash_received']);
    $input['items'] = array_reverse($input['items']);
    $quote = pl_pos_quote($input);
    assert_same('12.7500', $quote['total']);
    assert_same(5, $quote['units']);
    assert_same($input['checkout_key'], $quote['request']['checkout_key']);
    assert_same($input['catalog_digest'], $quote['catalog_digest']);
    assert_same($input['date'], $quote['request']['date']);
    assert_same([['sku' => 'NOTE-A5', 'quantity' => 2], ['sku' => 'PEN-BLUE', 'quantity' => 3]], $quote['request']['items']);
    assert_true(!array_key_exists('cash_received', $quote['request']));
    assert_same('4.5000', $quote['items'][0]['unit_price']);
    assert_same('9.0000', $quote['items'][0]['line_total']);
    foreach (['pl_documents', 'pl_journals', 'pl_journal_lines', 'pl_pos_sales'] as $table) {
        assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM %b WHERE book_id = %i', $table, $f['book_id']));
    }
    $sale = pl_checkout_pos($f['actor_id'], $f['company_id'], $f['book_id'], $quote['request'] + ['cash_received' => '20.00']);
    foreach ($quote['items'] as $index => $expectedItem) {
        foreach ($expectedItem as $field => $value) { assert_same($value, $sale['items'][$index][$field]); }
    }
    assert_same($quote['total'], $sale['total']);
    assert_same($quote['catalog_id'], $sale['catalog_id']);
    assert_same($quote['catalog_version'], $sale['catalog_version']);
    assert_same($f['actor_id'], $sale['created_by']);
    assert_same('Sample ledger tester', $sale['cashier_name']);
    assert_same('document:' . $sale['document_id'], $sale['document']['journal']['source_reference']);
    assert_same($f['actor_id'], (int) DB::queryFirstField('SELECT created_by FROM pl_documents WHERE id = %i', $sale['document_id']));
});

test('POS review rejects stale catalog forged financial fields and invalid cart without writes', function (): void {
    $f = pos_fixture(); $input = pos_input(); unset($input['cash_received']);
    $badInputs = [];
    $bad = $input; $bad['total'] = '0.01'; $badInputs[] = $bad;
    $bad = $input; $bad['cash_received'] = '20'; $badInputs[] = $bad;
    $bad = $input; $bad['items'][0]['unit_price'] = '0.01'; $badInputs[] = $bad;
    $bad = $input; $bad['catalog_digest'] = str_repeat('0', 64); $badInputs[] = $bad;
    $bad = $input; $bad['items'][0]['sku'] = 'UNKNOWN'; $badInputs[] = $bad;
    $bad = $input; $bad['items'][0]['quantity'] = '1.5'; $badInputs[] = $bad;
    $bad = $input; $bad['items'] = []; $badInputs[] = $bad;
    foreach ($badInputs as $bad) { assert_throws(fn () => pl_pos_quote($bad), DomainException::class); }
    foreach (['pl_documents', 'pl_journals', 'pl_journal_lines', 'pl_pos_sales'] as $table) {
        assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM %b WHERE book_id = %i', $table, $f['book_id']));
    }
});

test('POS correction after insufficient cash and closed period reuses its key for one sale', function (): void {
    $f = pos_fixture(); $input = pos_input();
    assert_throws(fn () => pl_checkout_pos($f['actor_id'], $f['company_id'], $f['book_id'], array_replace($input, ['cash_received' => '12.74'])), DomainException::class, 'Cash received must cover');
    DB::update('pl_periods', ['status' => 'closed'], 'id = %i', $f['period_id']);
    assert_throws(fn () => pl_checkout_pos($f['actor_id'], $f['company_id'], $f['book_id'], $input), DomainException::class, 'open accounting period');
    DB::update('pl_periods', ['status' => 'open'], 'id = %i', $f['period_id']);
    $sale = pl_checkout_pos($f['actor_id'], $f['company_id'], $f['book_id'], $input);
    assert_same($sale['document_id'], pl_checkout_pos($f['actor_id'], $f['company_id'], $f['book_id'], $input)['document_id']);
    foreach (['pl_documents', 'pl_journals', 'pl_pos_sales'] as $table) {
        assert_same(1, (int) DB::queryFirstField('SELECT COUNT(*) FROM %b WHERE book_id = %i', $table, $f['book_id']));
    }
    $trial = pl_trial_balance($f['actor_id'], $f['company_id'], $f['book_id']);
    assert_same('12.7500', $trial['total_debit']);
    assert_same('12.7500', $trial['total_credit']);
    assert_same('7.2500', $sale['change_due']);
});

test('all added base currencies survive sample setup POS posting and reconciled owner reports', function (): void {
    $f = pos_fixture();
    foreach (['MYR', 'BDT', 'LKR', 'NPR', 'SGD'] as $currency) {
        $setup = setup_input('sample');
        $setup['currency'] = $currency;
        $key = bin2hex(random_bytes(16));
        $company = pl_setup_company($f['actor_id'], $setup, $key);
        assert_same($currency, $company['currency']);
        $snapshot = json_decode(DB::queryFirstField('SELECT snapshot FROM pl_template_installation_history WHERE company_id = %i AND snapshot_kind = %s ORDER BY id DESC LIMIT 1', $company['id'], 'sample'), true, 512, JSON_THROW_ON_ERROR);
        assert_same($currency, $snapshot['sample_pack']['currency']);
        $sale = pl_checkout_pos($f['actor_id'], $company['id'], $company['book_id'], pos_input());
        assert_same($currency, $sale['currency']);
        assert_same($currency, $sale['document']['journal_preview']['currency']);
        assert_same($currency, $sale['document']['journal']['currency']);
        assert_same('12.7500', $sale['total']);
        assert_same('7.2500', $sale['change_due']);
        $profit = pl_profit_loss($f['actor_id'], $company['id'], $company['book_id'], '2026-09-14', '2026-09-14');
        $balance = pl_balance_sheet($f['actor_id'], $company['id'], $company['book_id'], '2026-09-14');
        $trial = pl_trial_balance($f['actor_id'], $company['id'], $company['book_id'], '2026-09-14');
        foreach ([$profit, $balance] as $report) { assert_same($currency, $report['currency']); }
        assert_same('1012.7500', $trial['total_debit']);
        assert_same('887.7500', $profit['net_profit']);
        assert_same('887.7500', $balance['total_assets']);
        assert_same('887.7500', pl_cash_balance($f['actor_id'], $company['id'], $company['book_id'], '2026-09-14'));
        assert_true($balance['balanced'] && $trial['balanced']);
        $changed = $setup; $changed['currency'] = 'USD';
        assert_throws(fn () => pl_setup_company($f['actor_id'], $changed, $key), DomainException::class, 'different business');
        assert_same($currency, pl_company_context($f['actor_id'], $company['id'])['currency']);
    }
    $unsupported = setup_input('sample'); $unsupported['currency'] = 'CAD';
    assert_throws(fn () => pl_setup_company($f['actor_id'], $unsupported, bin2hex(random_bytes(16))), DomainException::class, 'supported base currencies');
});

test('POS uses exact server prices and atomically links cash receipt journal and immutable lines', function (): void {
    $f = pos_fixture();
    $input = pos_input();
    $sale = pl_checkout_pos($f['actor_id'], $f['company_id'], $f['book_id'], $input);
    assert_same('12.7500', $sale['total']);
    assert_same('20.0000', $sale['cash_received']);
    assert_same('7.2500', $sale['change_due']);
    assert_same('4.5000', $sale['items'][0]['unit_price']);
    assert_same('9.0000', $sale['items'][0]['line_total']);
    assert_same('posted', $sale['document']['status']);
    assert_same('receipt', $sale['document']['kind']);
    assert_same('document:' . $sale['document_id'], $sale['document']['journal']['source_reference']);
    assert_same('12.7500', pl_trial_balance($f['actor_id'], $f['company_id'], $f['book_id'])['total_debit']);
    assert_same('12.7500', pl_account_activity($f['actor_id'], $f['company_id'], $f['book_id'], $f['accounts']['1000'])['balance']);
    assert_same('-12.7500', pl_account_activity($f['actor_id'], $f['company_id'], $f['book_id'], $f['accounts']['4000'])['balance']);
    assert_throws(fn () => DB::update('pl_pos_sales', ['total' => '1.0000'], 'document_id = %i', $sale['document_id']), MeekroDBException::class, 'Posted POS snapshots are immutable');
    assert_throws(fn () => DB::delete('pl_pos_sales', 'document_id = %i', $sale['document_id']), MeekroDBException::class, 'Posted POS snapshots cannot be deleted');
    assert_same($sale['items'], pl_get_pos_receipt($f['actor_id'], $f['company_id'], $f['book_id'], $sale['document_id'])['items']);
});

test('POS canonical retries return one receipt and changed checkout content conflicts', function (): void {
    $f = pos_fixture(); $input = pos_input();
    $sale = pl_checkout_pos($f['actor_id'], $f['company_id'], $f['book_id'], $input);
    $retry = $input; $retry['items'] = array_reverse($retry['items']); $retry['cash_received'] = '20.0000';
    assert_same($sale['document_id'], pl_checkout_pos($f['actor_id'], $f['company_id'], $f['book_id'], $retry)['document_id']);
    $retry['items'][0]['quantity'] = '4';
    assert_throws(fn () => pl_checkout_pos($f['actor_id'], $f['company_id'], $f['book_id'], $retry), DomainException::class, 'different sale');
    assert_same(1, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_pos_sales WHERE book_id = %i', $f['book_id']));
    assert_same(1, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id = %i', $f['book_id']));
});

test('POS rejects forged prices quantities stale catalog and insufficient cash without writes', function (): void {
    $f = pos_fixture(); $input = pos_input();
    $badInputs = [];
    $bad = $input; $bad['total'] = '0.01'; $badInputs[] = $bad;
    $bad = $input; $bad['items'][0]['unit_price'] = '0.01'; $badInputs[] = $bad;
    foreach (['-1', '1.5', '100', '01'] as $quantity) { $bad = $input; $bad['items'][0]['quantity'] = $quantity; $badInputs[] = $bad; }
    $bad = $input; $bad['items'] = []; $badInputs[] = $bad;
    $bad = $input; $bad['items'] = [['sku' => 'NOTE-A5', 'quantity' => '99'], ['sku' => 'PEN-BLUE', 'quantity' => '99'], ['sku' => 'TEA-80', 'quantity' => '99']]; $badInputs[] = $bad;
    $bad = $input; $bad['items'][1]['sku'] = 'NOTE-A5'; $badInputs[] = $bad;
    $bad = $input; $bad['items'][0]['sku'] = 'UNKNOWN'; $badInputs[] = $bad;
    // 12.7400 stays a whole-coin tender, so this keeps exercising the insufficient-cash guard
    // rather than falling through to the tender-precision one the next line covers.
    $bad = $input; $bad['cash_received'] = '12.7400'; $badInputs[] = $bad;
    $bad = $input; $bad['cash_received'] = '20.0055'; $badInputs[] = $bad;
    $bad = $input; $bad['catalog_digest'] = str_repeat('0', 64); $badInputs[] = $bad;
    foreach ($badInputs as $bad) { assert_throws(fn () => pl_checkout_pos($f['actor_id'], $f['company_id'], $f['book_id'], $bad), DomainException::class); }
    assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_documents WHERE book_id = %i', $f['book_id']));
    assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id = %i', $f['book_id']));
});

test('POS enforces reader writer company book and opening-readiness boundaries', function (): void {
    $f = pos_fixture(); $other = pos_fixture(); $input = pos_input();
    $sale = pl_checkout_pos($f['actor_id'], $f['company_id'], $f['book_id'], $input);
    assert_throws(fn () => pl_get_pos_receipt($other['actor_id'], $f['company_id'], $f['book_id'], $sale['document_id']), DomainException::class);
    assert_throws(fn () => pl_checkout_pos($f['actor_id'], $f['company_id'], $other['book_id'], pos_input()), DomainException::class);
    DB::insert('pl_company_members', ['company_id' => $f['company_id'], 'user_id' => $other['actor_id'], 'role' => 'viewer']);
    DB::update('pl_users', ['display_name'=>'Another receipt viewer'], 'id = %i', $other['actor_id']);
    assert_same('Sample ledger tester', pl_get_pos_receipt($other['actor_id'], $f['company_id'], $f['book_id'], $sale['document_id'])['cashier_name']);
    assert_same($sale['document_id'], pl_get_pos_receipt($other['actor_id'], $f['company_id'], $f['book_id'], $sale['document_id'])['document_id']);
    assert_throws(fn () => pl_checkout_pos($other['actor_id'], $f['company_id'], $f['book_id'], $input), DomainException::class);
    DB::update('pl_companies', ['setup_status' => 'opening_required'], 'id = %i', $other['company_id']);
    assert_throws(fn () => pl_checkout_pos($other['actor_id'], $other['company_id'], $other['book_id'], pos_input()), DomainException::class, 'Opening balances');
});

test('POS closed-period failure rolls back its newly created source', function (): void {
    $f = pos_fixture();
    DB::update('pl_periods', ['status' => 'closed'], 'id = %i', $f['period_id']);
    assert_throws(fn () => pl_checkout_pos($f['actor_id'], $f['company_id'], $f['book_id'], pos_input()), DomainException::class, 'open accounting period');
    assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_documents WHERE book_id = %i', $f['book_id']));
    assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_pos_sales WHERE book_id = %i', $f['book_id']));
});

test('POS snapshot failure rolls back receipt journal and lines together', function (): void {
    $f = pos_fixture();
    DB::query("CREATE TRIGGER pl_test_pos_failure BEFORE INSERT ON pl_pos_sales FOR EACH ROW BEGIN IF NEW.book_id = " . $f['book_id'] . " THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sample POS snapshot failure'; END IF; END");
    try {
        assert_throws(fn () => pl_checkout_pos($f['actor_id'], $f['company_id'], $f['book_id'], pos_input()), MeekroDBException::class, 'Sample POS snapshot failure');
        foreach (['pl_documents', 'pl_journals', 'pl_journal_lines', 'pl_pos_sales'] as $table) {
            assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM %b WHERE book_id = %i', $table, $f['book_id']));
        }
    } finally { DB::query('DROP TRIGGER pl_test_pos_failure'); }
});

test('POS concurrent duplicate checkouts create one receipt and journal', function (): void {
    $f = pos_fixture();
    $job = ['mode' => 'pos_checkout', 'fixture' => $f, 'pos_input' => pos_input()];
    $results = ledger_race([$job, $job]);
    assert_same($results[0]['id'], $results[1]['id']);
    assert_same(1, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_pos_sales WHERE book_id = %i', $f['book_id']));
    assert_same(1, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_documents WHERE book_id = %i', $f['book_id']));
    assert_same(1, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id = %i', $f['book_id']));
});

test('POS linked reversal preserves price snapshots while reversing the report effect', function (): void {
    $f = pos_fixture(); $sale = pl_checkout_pos($f['actor_id'], $f['company_id'], $f['book_id'], pos_input());
    pl_reverse_document($f['actor_id'], $f['company_id'], $f['book_id'], $sale['document_id'], '2026-09-14', 'Sample shop correction');
    $preserved = pl_get_pos_receipt($f['actor_id'], $f['company_id'], $f['book_id'], $sale['document_id']);
    assert_same('reversed', $preserved['document']['status']);
    assert_same($sale['items'], $preserved['items']);
    assert_same('0.0000', pl_trial_balance($f['actor_id'], $f['company_id'], $f['book_id'])['total_debit']);
});


test('POS unresolved recovery preserves exact request and only its matching reviewed snapshot', function (): void {
    $input = pos_input(); $quoteInput = $input; unset($quoteInput['cash_received']);
    $quote = pl_pos_quote($quoteInput);
    $recovery = pl_pos_recovery(123, 456, $input, $quote);
    assert_same(123, $recovery['company_id']); assert_same(456, $recovery['book_id']);
    assert_same(pl_pos_normalize_checkout($input), $recovery['request']);
    assert_same($quote, $recovery['quote']);
    $input['cash_received'] = '999'; $input['items'][0]['quantity'] = '9';
    assert_same('20.0000', $recovery['request']['cash_received']);
    assert_same(2, $recovery['request']['items'][0]['quantity']);
    assert_same(null, pl_pos_recovery(123, 456, $input, $quote)['quote']);
});

test('POS committed identical recovery works when the current catalog is unavailable', function (): void {
    $f = pos_fixture(); $input = pos_input();
    $sale = pl_checkout_pos($f['actor_id'], $f['company_id'], $f['book_id'], $input);
    $pipes = [];
    $process = proc_open([PHP_BINARY, __DIR__ . '/pos-catalog-retry-worker.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    assert_true(is_resource($process), 'Recovery worker starts.');
    fwrite($pipes[0], json_encode(['fixture' => $f, 'input' => $input], JSON_THROW_ON_ERROR)); fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    assert_same(0, proc_close($process), $error);
    $retry = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    assert_same($sale['document_id'], $retry['id']); assert_same('12.7500', $retry['total']);
    assert_same(1, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_pos_sales WHERE book_id = %i', $f['book_id']));
    assert_same(1, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id = %i', $f['book_id']));
});

test('POS refuses a tender finer than cash and shows one precision on the receipt', function (): void {
    $f = pos_fixture();
    // Issue #88: ninety-nine coffee packs at 12.75 make the reported 1,262.25 sale total.
    $cart = ['checkout_key' => bin2hex(random_bytes(24)), 'catalog_digest' => pl_pos_catalog()['digest'],
        'date' => '2026-09-14', 'items' => [['sku' => 'COFFEE-250', 'quantity' => '99']]];
    assert_same('1262.2500', pl_pos_quote($cart)['total']);
    $finer = $cart + ['cash_received' => '1262.2555'];
    assert_throws(fn () => pl_pos_normalize_checkout($finer), DomainException::class, 'whole notes and coins');
    assert_throws(fn () => pl_pos_recovery($f['company_id'], $f['book_id'], $finer), DomainException::class, 'whole notes and coins');
    assert_throws(fn () => pl_checkout_pos($f['actor_id'], $f['company_id'], $f['book_id'], $finer), DomainException::class, 'whole notes and coins');
    foreach (['pl_documents', 'pl_journals', 'pl_pos_sales'] as $table) {
        assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM %b WHERE book_id = %i', $table, $f['book_id']));
    }
    $sale = pl_checkout_pos($f['actor_id'], $f['company_id'], $f['book_id'], $cart + ['cash_received' => '1300']);
    assert_same('1262.2500', $sale['total']);
    assert_same('1300.0000', $sale['cash_received']);
    assert_same('37.7500', $sale['change_due']);
    assert_same(['1,262.25', '1,300.00', '37.75'], array_map('pl_money', [$sale['total'], $sale['cash_received'], $sale['change_due']]));
});

test('POS sample prices stay in whole minor units so every cart total is tenderable', function (): void {
    foreach (pl_pos_catalog()['products'] as $product) {
        $price = pl_amount($product['unit_price']);
        assert_same($price, pl_cash_amount($product['unit_price']), $product['sku'] . ' is priced more finely than cash.');
    }
});
