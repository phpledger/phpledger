<?php
declare(strict_types=1);

/*
 * Run one distributor's driver day and print it (1.2 M9).
 *
 * The day is tools/distribution-scenario.php, the same file tests/distribution_simulation_test.php
 * asserts, so what this prints is what the suite proves. It exists because a reconciliation
 * identity is easier to trust when you can watch it happen with real numbers, and because
 * whoever authors the distributor demo pack needs to see exactly which records a van day needs.
 *
 * It builds its own throwaway company in a new random database inside the disposable test
 * service, and drops it again. It never touches an existing book, and it does not ship.
 *
 *   PL_DB_USER=root ... php tools/simulate-distribution.php
 */

if (PHP_SAPI !== 'cli' || getenv('PL_ENV') !== 'test' || getenv('PL_DB_HOST') !== 'db_test'
    || getenv('PL_DB_NAME') !== 'phpledger_test' || getenv('PL_DB_USER') !== 'root') {
    fwrite(STDERR, "The distribution simulation creates and drops its own database, so it requires the disposable db_test service and its local root account.\n");
    exit(2);
}
require dirname(__DIR__) . '/www/phpledger/includes/bootstrap.php';
require dirname(__DIR__) . '/www/phpledger/install/migrate.php';
require __DIR__ . '/distribution-scenario.php';

$database = 'phpledger_distribution_sim_' . bin2hex(random_bytes(12));
$created = false;
$money = static fn (?string $value): string => $value === null ? 'withheld' : $value;
$row = static function (array $cells, array $widths): string {
    $out = [];
    foreach ($cells as $index => $cell) { $out[] = str_pad((string) $cell, $widths[$index]); }
    return '  ' . rtrim(implode('  ', $out));
};
try {
    if ((int) DB::queryFirstField('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = %s', $database) !== 0) {
        throw new RuntimeException('Refusing to reuse a simulation database.');
    }
    DB::query('CREATE DATABASE %b CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci', $database);
    $created = true;
    DB::useDB($database);
    pl_migrate();

    /* ------------------------------------------------------------------ the business */
    $owner = pl_create_user('distribution-sim@example.invalid', 'Sample distribution owner', bin2hex(random_bytes(24)));
    $company = pl_create_company($owner, 'Sample distribution business', 'USD', '2026-01-01');
    $companyId = (int) $company['company_id'];
    $bookId = (int) $company['book_id'];
    $args = [$owner, $companyId, $bookId];
    foreach (['inventory', 'inventory-locations', 'trading-documents'] as $module) {
        $manifest = pl_module_registry()[$module];
        pl_set_company_module($owner, $companyId, $module, true, 0, $manifest['digest'], 'Distribution simulation', bin2hex(random_bytes(16)));
    }
    // Cash on an invoice needs a cap before a till can take any (owner decision B37).
    pl_save_trading_policies(...array_merge($args, [['discount_posting' => 'net', 'discount_account_id' => '', 'free_goods_account_id' => '',
        'free_goods_output_tax' => 'none', 'cash_on_invoice_cap' => '100000', 'revision' => 0,
        'reason' => 'Distribution simulation', 'idempotency_key' => bin2hex(random_bytes(16))]]));
    $account = static function (string $code, string $name, string $type) use ($args): int {
        return (int) pl_save_account(...array_merge($args, [['code' => $code, 'name' => $name, 'type' => $type, 'role' => null,
            'is_active' => true, 'reason' => 'Distribution simulation', 'creation_key' => bin2hex(random_bytes(16))]]))['id'];
    };
    $inventoryAccount = $account('1300', 'Stock on hand', 'asset');
    $grni = $account('2100', 'Goods received not invoiced', 'liability');
    $product = static function (string $sku, string $name, string $price) use ($args, $company, $inventoryAccount): int {
        return (int) pl_save_inventory_product(...array_merge($args, [['sku' => $sku, 'name' => $name, 'kind' => 'stock', 'base_unit' => 'each',
            'selling_price' => $price, 'is_active' => true, 'inventory_account_id' => $inventoryAccount,
            'cogs_account_id' => $company['accounts']['5000'], 'sales_account_id' => $company['accounts']['4000'],
            'purchase_account_id' => $company['accounts']['5000'], 'reason' => 'Distribution simulation',
            'idempotency_key' => bin2hex(random_bytes(16))]]))['id'];
    };
    $productA = $product('COLA-500', 'Cola 500ml', '25');
    $productB = $product('JUICE-1L', 'Juice 1 litre', '40');
    $receive = static function (int $productId, string $quantity, string $value) use ($args, $grni): void {
        pl_inventory_receive(...array_merge($args, [['product_id' => $productId, 'quantity' => $quantity, 'amount_base' => $value,
            'date' => '2026-01-05', 'offset_account_id' => $grni, 'source_type' => 'simulation',
            'source_reference' => bin2hex(random_bytes(8)), 'reason' => 'Opening purchase for the simulation',
            'idempotency_key' => bin2hex(random_bytes(16))]]));
    };
    $receive($productA, '200', '400');
    $receive($productB, '100', '300');
    pl_save_product_pack(...array_merge($args, [['product_id' => $productA, 'code' => 'CTN12', 'name' => 'Carton of 12',
        'units_per_pack' => '12', 'is_active' => true, 'reason' => 'Distribution simulation']]));
    $warehouse = pl_inventory_default_warehouse(...$args);
    $van = pl_save_inventory_warehouse(...array_merge($args, [['code' => 'VAN-1', 'name' => 'Route van 1', 'kind' => 'mobile', 'driver_employee_id' => pl_save_employee($owner,$companyId,['full_name'=>'Sample driver','employment_type'=>'full_time','employment_status'=>'active','hire_date'=>'2026-01-01','reason'=>'Explicit fictional simulation employment'])['id'],
        'driver_name' => 'Sample driver', 'vehicle_reference' => 'SAMPLE-4471', 'route_name' => 'Sample route',
        'is_active' => true, 'reason' => 'Distribution simulation', 'idempotency_key' => bin2hex(random_bytes(16))]]));
    $customer = static function (string $name, ?string $limit) use ($args): int {
        return (int) pl_save_party(...array_merge($args, [['legal_name' => $name, 'entity_type' => 'private_company', 'country_code' => 'GB',
            'is_customer' => true, 'is_vendor' => false, 'currency' => 'USD']
            + ($limit === null ? [] : ['credit_limit' => $limit])
            + ['request_key' => bin2hex(random_bytes(16)), 'reason' => 'Distribution simulation']]))['id'];
    };
    $context = ['actor_id' => $owner, 'company_id' => $companyId, 'book_id' => $bookId,
        'warehouse_id' => (int) $warehouse['id'], 'van_id' => (int) $van['id'],
        'product_a' => $productA, 'product_b' => $productB,
        'cash_account_id' => (int) $company['accounts']['1000'],
        'cash_customer_id' => $customer('Corner shop (walk-in)', null),
        'credit_customer_id' => $customer('Ali Traders', '600'),
        'date' => '2026-01-12'];

    /* ------------------------------------------------------------------- the day */
    echo "PHP Ledger distribution simulation\n";
    echo 'One driver, one van, one day: ' . $context['date'] . "\n\n";
    $run = pl_distribution_scenario($context);
    echo "Documents raised\n";
    foreach ([['Morning load', $run['issue']], ['Gate pass (outward, returnable)', $run['gate_pass']],
        ['Mid-day re-issue', $run['reissue']], ['Unsold stock returned', $run['return']]] as [$label, $document]) {
        echo $row([$label, (string) $document['document_number'], $document['total_quantity'] . ' units'], [34, 20, 16]) . "\n";
    }
    foreach ([['Van sale, cash', $run['cash_sale']], ['Van sale, on account', $run['credit_sale']],
        ['Van sale, on account', $run['second_credit_sale']], ['Credit note, goods returned', $run['credit_note']]] as [$label, $document]) {
        echo $row([$label, (string) $document['document_number'], $document['total'], (string) $document['party']['legal_name']], [34, 20, 16, 30]) . "\n";
    }

    $day = $run['day'];
    echo "\nThe driver's day, item by item\n";
    echo $row(['Item', 'Opening', 'Loaded', 'Returns in', 'Sold', 'Returned', 'Expected', 'Closing', 'Variance'], [12, 10, 10, 11, 10, 10, 10, 10, 10]) . "\n";
    foreach (pl_distribution_identity($day) as $line) {
        echo $row([$line['sku'], $line['opening'], $line['loaded'], $line['customer_returns'], $line['sold'],
            $line['returned'], $line['expected_closing'], $line['closing'], $line['variance']], [12, 10, 10, 11, 10, 10, 10, 10, 10]) . "\n";
    }
    $t = $day['totals'];
    echo $row(['TOTAL', $t['opening'], $t['loaded'], $t['customer_returns'], $t['sold'], $t['returned'], '', $t['closing'], ''], [12, 10, 10, 11, 10, 10, 10, 10, 10]) . "\n";
    echo "\n  The reconciliation identity, on the totals:\n";
    echo '    ' . $t['opening'] . ' + ' . $t['loaded'] . ' + ' . $t['customer_returns']
        . ' - ' . $t['sold'] . ' - ' . $t['returned'] . ' = '
        . bcsub(bcadd(bcadd($t['opening'], $t['loaded'], 4), $t['customer_returns'], 4), bcadd($t['sold'], $t['returned'], 4), 4)
        . ' and the van holds ' . $t['closing'] . "\n";
    echo '    ' . ($day['reconciles'] ? 'The van balances.' : 'THE VAN DOES NOT BALANCE.') . "\n";
    echo "\n  Carrying value moved: loaded " . $money($t['loaded_cost']) . ', sold ' . $money($t['sold_cost'])
        . ', returned ' . $money($t['returned_cost']) . "\n";

    echo "\nWhat the driver collected\n";
    foreach ($day['sales']['documents'] as $sale) {
        echo $row([$sale['number'], $sale['party_name'], $sale['tender'], $sale['total_fc'], $sale['cash_fc'], $sale['credit_fc']], [18, 26, 10, 14, 14, 14]) . "\n";
    }
    $s = $day['sales']['totals'];
    echo $row(['TOTAL', $s['documents'] . ' sales', '', $s['sales'], $s['cash'], $s['credit']], [18, 26, 10, 14, 14, 14]) . "\n";
    echo '  Customer returns on credit notes: ' . $s['returns'] . "\n";

    echo "\nCredit limits, checked at settlement\n";
    foreach ($day['credit']['parties'] as $party) {
        echo $row([$party['party_name'], 'today ' . $party['credit_today_base'], 'owes ' . $party['exposure_base'],
            'limit ' . ($party['credit_limit'] ?? 'none'), $party['status'] === 'over' ? 'OVER by ' . $party['excess_base'] : $party['status']], [26, 20, 20, 18, 22]) . "\n";
    }

    /* ------------------------------------------------------- reviewing and approving */
    echo "\nSettlement\n";
    $settlement = pl_distribution_settlement($context, '125', (int) $run['second_credit_sale']['id']);
    echo '  Reviewed: settlement ' . (int) $settlement['first_review']['id'] . ', status ' . $settlement['first_review']['status'] . "\n";
    echo '  Approval refused: ' . ($settlement['breach_refusal'] ?? 'NOT REFUSED — the credit limit did not bind') . "\n";
    echo '  Recovery posted: ' . (string) $settlement['receipt']['allocated_fc'] . ' received, journal ' . (int) $settlement['receipt']['journal_id'] . "\n";
    echo '  Approval refused again: ' . ($settlement['stale_refusal'] ?? 'NOT REFUSED — a stale sheet was approvable') . "\n";
    echo '  Reviewed again, then approved: status ' . $settlement['approved']['status']
        . ', reconciles ' . ($settlement['approved']['reconciles'] ? 'yes' : 'no') . "\n";

    /* --------------------------------------------------------------- the books agree */
    $aggregate = pl_stock_location_aggregate(...array_merge($args, [$context['date']]));
    echo "\nStock value after the day\n";
    echo '  Across every location: ' . $money($aggregate['total_value_base'])
        . '; summed per location: ' . $money($aggregate['per_location_value_base'])
        . '; they ' . ($aggregate['reconciles'] ? 'agree' : 'DISAGREE') . "\n";
    foreach ($aggregate['accounts'] as $control) {
        echo '  Stock control account ' . (int) $control['account_id'] . ': stock ' . $control['stock_value']
            . ', ledger ' . (string) $control['ledger_value'] . ', difference ' . (string) $control['difference'] . "\n";
    }
    echo "\nWhat a demo pack needs to reproduce this day\n";
    echo "  - two stock locations: one fixed warehouse and one location of kind 'mobile' with a driver name\n";
    echo "  - at least two stock products with a selling price, and one pack size for pack-and-unit entry\n";
    echo "  - opening stock in the fixed warehouse, dated before the van day\n";
    echo "  - two customers: one that pays cash, and one with a credit_limit that the day's credit exceeds\n";
    echo "  - a cash-on-invoice cap in the trading policies, or no sale can be paid for in cash\n";
    echo "  - the day itself: a stock issue, sales from the van, a re-issue, a credit note, a stock return\n";
} finally {
    if ($created) {
        DB::useDB('phpledger_test');
        DB::query('DROP DATABASE %b', $database);
    }
}
