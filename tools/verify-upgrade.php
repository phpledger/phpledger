<?php
declare(strict_types=1);

// This destructive cleanup is confined to a new random database in the disposable test service.
if (PHP_SAPI !== 'cli' || getenv('PL_ENV') !== 'test' || getenv('PL_DB_HOST') !== 'db_test'
    || getenv('PL_DB_NAME') !== 'phpledger_test' || getenv('PL_DB_USER') !== 'root') {
    fwrite(STDERR, "Upgrade verification requires the disposable db_test service and its local root account.\n");
    exit(2);
}
require dirname(__DIR__) . '/www/phpledger/includes/bootstrap.php';
require dirname(__DIR__) . '/www/phpledger/install/migrate.php';
$baseline = $argv[1] ?? 'foundation';
if (!in_array($baseline, ['foundation', 'core-0.1.2', 'opening-local', 'preview-0.2.1', 'preview-0.5.0', 'stable-1.1.1', 'fresh'], true)) {
    throw new DomainException('Choose foundation, core-0.1.2, opening-local, preview-0.2.1, preview-0.5.0, stable-1.1.1 or fresh.');
}
$allFiles = glob(PL_APP . '/install/migrations/*.php') ?: [];
sort($allFiles, SORT_STRING);
$allVersions = array_map(static fn(string $path): string => basename($path, '.php'), $allFiles);
$baseVersions = match ($baseline) {
    'foundation' => ['001_foundation'],
    'core-0.1.2' => array_values(array_filter($allVersions, static fn(string $v): bool => $v <= '006_core_accounts_journals')),
    'opening-local' => array_values(array_filter($allVersions, static fn(string $v): bool => $v <= '009_bank_draft_cancellation' && $v !== '006_core_accounts_journals')),
    'preview-0.2.1' => array_values(array_filter($allVersions, static fn(string $v): bool => $v <= '012_demo_history_periods')),
    'preview-0.5.0' => array_values(array_filter($allVersions, static fn(string $v): bool => $v <= '028_ar_ap_upgrade_completion')),
    'stable-1.1.1' => array_values(array_filter($allVersions, static fn(string $v): bool => $v <= '034_inventory_locations')),
    'fresh' => [],
};
$upgradeDatabase = 'phpledger_upgrade_verify_' . bin2hex(random_bytes(12));
$created = false;
try {
    if (!preg_match('/^phpledger_upgrade_verify_[a-f0-9]{24}$/D', $upgradeDatabase)
        || (int) DB::queryFirstField('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = %s', $upgradeDatabase) !== 0) {
        throw new RuntimeException('Refusing to reuse an upgrade database.');
    }
    DB::query('CREATE DATABASE %b CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci', $upgradeDatabase);
    $created = true;
    DB::useDB($upgradeDatabase);
    if ($baseline === 'fresh') {
        $fresh = pl_migrate();
        if ($fresh['applied'] !== $allVersions) { throw new RuntimeException('Fresh migration chain differs.'); }
        $owner = pl_create_user('fresh@example.invalid', 'Sample fresh owner', bin2hex(random_bytes(24)));
        $company = pl_create_company($owner, 'Sample fresh installation', 'USD', '2026-01-01');
        pl_post_journal($owner, $company['company_id'], $company['book_id'], [
            'date' => '2026-09-14', 'currency' => 'USD', 'source_type' => 'receipt', 'source_reference' => 'fresh',
            'idempotency_key' => 'fresh', 'description' => 'Sample fresh posting', 'lines' => [
                ['account_id' => $company['accounts']['1000'], 'debit' => '125', 'credit' => '0'],
                ['account_id' => $company['accounts']['4000'], 'debit' => '0', 'credit' => '125'],
            ],
        ]);
        if (pl_trial_balance($owner, $company['company_id'], $company['book_id'])['total_debit'] !== '125.0000' || pl_migrate()['applied'] !== []
            || pl_module_state($company['company_id'], 'pos-showcase')['enabled']) {
            throw new RuntimeException('Fresh setup/posting/replay failed.');
        }
        echo 'Fresh installation passed: ' . count($allVersions) . " migrations, user/company, central posting, balanced report and replay.\n";
        return;
    }
    DB::query("CREATE TABLE pl_schema_migrations (version VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, status ENUM('applying','applied') NOT NULL, applied_at DATETIME NULL) ENGINE=InnoDB");
    foreach ($baseVersions as $version) {
        $file = PL_APP . '/install/migrations/' . $version . '.php';
        foreach (require $file as $statement) { DB::query($statement); }
        DB::insert('pl_schema_migrations', ['version' => $version, 'checksum' => hash_file('sha256', $file), 'status' => 'applied', 'applied_at' => gmdate('Y-m-d H:i:s')]);
    }
    $actor = pl_create_user('upgrade@example.invalid', 'Sample upgrade owner', 'Sample upgrade passphrase 471!');
    if ($baseline === 'preview-0.5.0') {
        $f = pl_create_company($actor, 'Sample 0.5 upgrade company', 'USD', '2026-01-01');
        $journal = pl_post_journal($actor, $f['company_id'], $f['book_id'], [
            'date'=>'2026-09-17','currency'=>'USD','source_type'=>'receipt','source_reference'=>'sample-upgrade-0.5',
            'description'=>'Prior 0.5 sample receipt','idempotency_key'=>'sample-upgrade-0.5',
            'lines'=>[['account_id'=>$f['accounts']['1000'],'debit'=>'125.0000','credit'=>'0'],['account_id'=>$f['accounts']['4000'],'debit'=>'0','credit'=>'125.0000']],
        ]);
        DB::insert('pl_connection_clients',['client_id'=>'sample-upgrade','name'=>'Prior sample client','redirect_uris'=>'[]','source'=>'personal']);
        $connectionId = bin2hex(random_bytes(16));
        DB::insert('pl_connections',['id'=>$connectionId,'actor_id'=>$actor,'client_id'=>'sample-upgrade','name'=>'Prior sample grant','kind'=>'personal','oauth_scopes'=>'ledger.read','resource'=>'https://sample.invalid/mcp','expires_at'=>'2026-10-17 00:00:00']);
        $before = pl_get_journal($actor,$f['company_id'],$f['book_id'],$journal['id']);
        $beforeAccounts = DB::query('SELECT * FROM pl_accounts ORDER BY id');
        $migration = pl_migrate();
        if ($migration['applied'] !== array_values(array_diff($allVersions,$baseVersions))
            || $before !== pl_get_journal($actor,$f['company_id'],$f['book_id'],$journal['id'])
            || $beforeAccounts !== array_map(static fn(array $row): array=>array_intersect_key($row,$beforeAccounts[0]), DB::query('SELECT * FROM pl_accounts ORDER BY id'))
            || (int)DB::queryFirstField('SELECT COUNT(*) FROM pl_accounts WHERE report_classification IS NOT NULL') !== 0
            || DB::queryFirstField('SELECT access_mode FROM pl_connections WHERE id=%s',$connectionId) !== 'full'
            || pl_company_context($actor,$f['company_id'])['setup_status'] !== 'ready'
            || pl_trial_balance($actor,$f['company_id'],$f['book_id'])['total_debit'] !== '125.0000'
            || pl_migrate()['applied'] !== []) {
            throw new RuntimeException('0.5 upgrade changed historical records, access, setup or repeatability.');
        }
        echo "Upgrade passed: 0.5 schema through 028, preserved posted journal/accounts/setup, existing full-read connection, balanced report and replay.\n";
        return;
    }
    if ($baseline === 'stable-1.1.1') {
        // 034_inventory_locations is the last migration v1.1.1 shipped; this baseline proves a real 1.1.1 install
        // (schema already at 034, Stock locations enabled with a van and a transfer already recorded) upgrades cleanly.
        $f = pl_create_company($actor, 'Sample 1.1.1 upgrade company', 'USD', '2026-01-01');
        foreach (['inventory' => ['1300', 'asset'], 'grni' => ['2100', 'liability']] as $name => [$code, $type]) {
            $account = pl_save_account($actor, $f['company_id'], $f['book_id'], ['code' => $code, 'name' => 'Sample ' . $name, 'type' => $type, 'role' => null,
                'is_active' => true, 'reason' => 'Sample 1.1.1 upgrade chart', 'creation_key' => bin2hex(random_bytes(16))]);
            $f[$name . '_account_id'] = $account['id'];
        }
        $inventoryManifest = pl_module_registry()['inventory'];
        pl_set_company_module($actor, $f['company_id'], 'inventory', true, 0, $inventoryManifest['digest'], 'Sample 1.1.1 inventory module', 'sample-upgrade-1.1.1-inventory');
        $locationsManifest = pl_module_registry()['inventory-locations'];
        pl_set_company_module($actor, $f['company_id'], 'inventory-locations', true, 0, $locationsManifest['digest'], 'Sample 1.1.1 locations module', 'sample-upgrade-1.1.1-locations');
        $product = pl_save_inventory_product($actor, $f['company_id'], $f['book_id'], ['sku' => 'SAMPLE-034', 'name' => 'Sample 1.1.1 product', 'kind' => 'stock', 'base_unit' => 'each', 'selling_price' => '25', 'is_active' => true,
            'inventory_account_id' => $f['inventory_account_id'], 'cogs_account_id' => $f['accounts']['5000'], 'sales_account_id' => $f['accounts']['4000'], 'purchase_account_id' => $f['accounts']['5000'],
            'reason' => 'Sample 1.1.1 upgrade product', 'idempotency_key' => bin2hex(random_bytes(16))]);
        $default = pl_inventory_default_warehouse($actor, $f['company_id'], $f['book_id']);
        pl_inventory_receive($actor, $f['company_id'], $f['book_id'], ['product_id' => $product['id'], 'quantity' => '10', 'amount_base' => '100', 'date' => '2026-01-05',
            'offset_account_id' => $f['grni_account_id'], 'source_type' => 'sample_upgrade', 'source_reference' => 'sample-upgrade-1.1.1',
            'reason' => 'Sample 1.1.1 upgrade receipt', 'idempotency_key' => 'sample-upgrade-1.1.1-receipt']);
        $van = pl_save_inventory_warehouse($actor, $f['company_id'], $f['book_id'], ['code' => 'SAMPLE-VAN', 'name' => 'Sample upgrade van', 'is_active' => true,
            'reason' => 'Sample 1.1.1 upgrade van', 'idempotency_key' => bin2hex(random_bytes(16))]);
        $transfer = pl_inventory_transfer($actor, $f['company_id'], $f['book_id'], ['product_id' => $product['id'], 'from_warehouse_id' => $default['id'], 'to_warehouse_id' => $van['id'],
            'quantity' => '4', 'date' => '2026-01-06', 'source_reference' => 'sample-upgrade-1.1.1-transfer', 'reason' => 'Sample 1.1.1 upgrade transfer', 'idempotency_key' => 'sample-upgrade-1.1.1-transfer']);
        $beforeDefaultBalance = pl_inventory_balance($actor, $f['company_id'], $f['book_id'], $product['id'], null, $default['id']);
        $beforeVanBalance = pl_inventory_balance($actor, $f['company_id'], $f['book_id'], $product['id'], null, $van['id']);
        $beforeValuation = pl_inventory_valuation($actor, $f['company_id'], $f['book_id']);
        $beforeWarehouses = pl_list_inventory_warehouses($actor, $f['company_id'], $f['book_id']);
        $migration = pl_migrate();
        if ($migration['applied'] !== array_values(array_diff($allVersions, $baseVersions))
            || $beforeDefaultBalance !== pl_inventory_balance($actor, $f['company_id'], $f['book_id'], $product['id'], null, $default['id'])
            || $beforeVanBalance !== pl_inventory_balance($actor, $f['company_id'], $f['book_id'], $product['id'], null, $van['id'])
            || $beforeValuation !== pl_inventory_valuation($actor, $f['company_id'], $f['book_id'])
            || $beforeWarehouses !== pl_list_inventory_warehouses($actor, $f['company_id'], $f['book_id'])
            || count(array_filter($beforeWarehouses, static fn(array $w): bool => $w['is_default'])) !== 1
            || !pl_module_state($f['company_id'], 'inventory-locations')['enabled']
            || $transfer['journal_created'] !== false
            || pl_trial_balance($actor, $f['company_id'], $f['book_id'])['total_debit'] !== '100.0000'
            || pl_migrate()['applied'] !== []) {
            throw new RuntimeException('1.1.1 upgrade changed historical stock, warehouses, module state or reconciliation.');
        }
        echo "Upgrade passed: 1.1.1 schema through 034, preserved warehouse balances/valuation, one default warehouse, enabled locations module, reconciled report and replay.\n";
        return;
    }
    DB::insert('pl_companies', ['name' => 'Prior foundation sample company', 'currency' => 'USD', 'start_date' => '2026-01-01', 'fiscal_year_end' => '12-31', 'created_by' => $actor]);
    $company = (int) DB::insertId();
    DB::insert('pl_company_members', ['company_id' => $company, 'user_id' => $actor, 'role' => 'owner']);
    DB::insert('pl_books', ['company_id' => $company, 'name' => 'Primary book']);
    $book = (int) DB::insertId();
    DB::insert('pl_periods', ['company_id' => $company, 'book_id' => $book, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
    $period = (int) DB::insertId();
    $accounts = [];
    foreach (pl_starter_template()['accounts'] as $definition) {
        DB::insert('pl_accounts', ['company_id' => $company, 'book_id' => $book, 'code' => $definition['code'], 'name' => $definition['code'] === '1000' ? 'Preserved custom bank name' : $definition['name'], 'type' => $definition['type']]);
        $accounts[$definition['semantic_key']] = (int) DB::insertId();
    }
    DB::insert('pl_journals', ['company_id' => $company, 'book_id' => $book, 'period_id' => $period, 'journal_date' => '2026-09-14', 'currency' => 'USD', 'description' => 'Prior sample receipt', 'source_type' => 'receipt', 'source_reference' => 'upgrade-fixture', 'idempotency_key' => 'upgrade-fixture', 'payload_hash' => hash('sha256', 'sample-old-fixture'), 'posted_by' => $actor]);
    $journal = (int) DB::insertId();
    foreach ([['core.cash_bank', '125.0000', '0.0000'], ['core.income.sales', '0.0000', '125.0000']] as $index => [$key, $debit, $credit]) {
        DB::insert('pl_journal_lines', ['journal_id' => $journal, 'company_id' => $company, 'book_id' => $book, 'line_number' => $index + 1, 'account_id' => $accounts[$key], 'description' => 'Prior sample line', 'debit' => $debit, 'credit' => $credit]);
    }
    $beforeHeader = DB::queryFirstRow('SELECT * FROM pl_journals WHERE id = %i', $journal);
    $beforeLines = DB::query('SELECT * FROM pl_journal_lines WHERE journal_id = %i ORDER BY line_number', $journal);
    $beforeAccounts = DB::query('SELECT id, company_id, book_id, code, name, type, is_active FROM pl_accounts ORDER BY id');
    $migration = pl_migrate();
    if ($migration['applied'] !== array_values(array_diff($allVersions, $baseVersions)) || $migration['skipped'] !== $baseVersions) {
        throw new RuntimeException('Unexpected upgrade migration receipt.');
    }
    $afterHeader = DB::queryFirstRow('SELECT * FROM pl_journals WHERE id = %i', $journal);
    $afterLines = DB::query('SELECT * FROM pl_journal_lines WHERE journal_id = %i ORDER BY line_number', $journal);
    $originalLineFields = array_fill_keys(array_keys($beforeLines[0]), true);
    if ($beforeHeader !== array_intersect_key($afterHeader, $beforeHeader)
        || $beforeLines !== array_map(static fn(array $line): array => array_intersect_key($line, $originalLineFields), $afterLines)
        || $beforeAccounts !== DB::query('SELECT id, company_id, book_id, code, name, type, is_active FROM pl_accounts ORDER BY id')) {
        throw new RuntimeException('The additive upgrade changed prior accounting records.');
    }
    foreach ($afterLines as $line) {
        if ($line['currency'] !== 'USD' || $line['amount_fc'] !== bcadd($line['debit'], $line['credit'], 4)
            || $line['amount_base'] !== $line['amount_fc'] || $line['rate'] !== '1.000000000000'
            || $line['rate_type'] !== 'spot' || (int) $line['rate_is_stale'] !== 0
            || $line['rate_source_id'] !== null || $line['ic_counterparty_entity_id'] !== null) {
            throw new RuntimeException('Historical domestic currency augmentation is invalid.');
        }
    }
    $context = pl_company_context($actor, $company);
    if (pl_module_state($company, 'pos-showcase')['enabled'] || (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_module_actions') !== 0) {
        throw new RuntimeException('Installing modules must not enable an existing company or invent an owner decision.');
    }
    if ($context['setup_status'] !== 'review_required' || $context['template'] !== null
        || count(array_filter($context['accounts'], static fn (array $account): bool => $account['role'] !== null)) !== 0) {
        throw new RuntimeException('Prior companies were not safely held for chart/opening review.');
    }
    pl_confirm_existing_setup($actor, $company, $book, $accounts, true);
    if (pl_trial_balance($actor, $company, $book)['total_debit'] !== '125.0000'
        || pl_migrate()['applied'] !== [] || DB::queryFirstField('SELECT @@session.time_zone') !== '+00:00') {
        throw new RuntimeException('Review/replay/UTC validation failed.');
    }
    echo "Upgrade passed: {$baseline} -> complete supplied chain, preserved six accounts and posted journal/lines, required review, explicit role mapping, reconciled report, replay and UTC session.\n";
} finally {
    DB::useDB('phpledger_test');
    if ($created && preg_match('/^phpledger_upgrade_verify_[a-f0-9]{24}$/D', $upgradeDatabase)) {
        DB::query('DROP DATABASE %b', $upgradeDatabase);
        echo "Removed only this run's isolated upgrade database; phpledger_test and development data remain intact.\n";
    }
}
