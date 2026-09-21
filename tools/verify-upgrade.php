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
if (!in_array($baseline, ['foundation', 'core-0.1.2', 'opening-local', 'preview-0.2.1', 'preview-0.5.0', 'stable-1.1.1', 'converted-chart', 'fresh'], true)) {
    throw new DomainException('Choose foundation, core-0.1.2, opening-local, preview-0.2.1, preview-0.5.0, stable-1.1.1, converted-chart or fresh.');
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
    // The last migration before the chart conversion: this baseline starts with a chart that
    // still uses its own account numbers, which is what 036 converts.
    'converted-chart' => array_values(array_filter($allVersions, static fn(string $v): bool => $v <= '035_document_series')),
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
    // Every baseline below starts on a schema older than `032_user_names`, the migration that adds
    // `pl_users.username`, so the actor is seeded the way an installation of that era held one
    // rather than through `pl_create_user()`, whose insert names a column that does not exist yet.
    // The row is also exactly what a real upgraded account looks like afterwards: 032 adds the
    // column as NULL and existing accounts keep signing in by email. The password is still hashed
    // by the application's own helper, which depends on no schema.
    DB::insert('pl_users', ['email' => 'upgrade@example.invalid', 'display_name' => 'Sample upgrade owner',
        'password_hash' => pl_hash_password('Sample upgrade passphrase 471!'), 'is_active' => 1]);
    $actor = (int) DB::insertId();
    // A company as a release before `036_structured_account_codes` held one: the bundled 1.0.0
    // chart, which is still in the tree because it is what 1.0.0 and every 1.1.x installed, with
    // its own account numbers, written straight into the tables of the era. `pl_create_company()`
    // cannot build this. It inserts today's chart, whose `legacy_code` and `is_contra` columns 036
    // has not added yet, and whose accounts are already numbered in the shape 036 converts to, so
    // the conversion these baselines have to survive would have nothing to do. Every column written
    // here exists from `013_currency_foundation` onward, which both callers have applied.
    $seedPriorCompany = static function (int $actorId, string $name, string $currency, string $startDate, string $endDate): array {
        DB::insert('pl_companies', ['name' => $name, 'currency' => $currency, 'functional_currency' => $currency,
            'presentation_currency' => $currency, 'start_date' => $startDate, 'fiscal_year_end' => '12-31',
            'created_by' => $actorId, 'setup_status' => 'ready']);
        $companyId = (int) DB::insertId();
        DB::insert('pl_company_members', ['company_id' => $companyId, 'user_id' => $actorId, 'role' => 'owner']);
        DB::insert('pl_books', ['company_id' => $companyId, 'name' => 'Primary book',
            'functional_currency' => $currency, 'presentation_currency' => $currency]);
        $bookId = (int) DB::insertId();
        DB::insert('pl_periods', ['company_id' => $companyId, 'book_id' => $bookId, 'start_date' => $startDate,
            'end_date' => $endDate, 'status' => 'open']);
        $periodId = (int) DB::insertId();
        $source = file_get_contents(PL_ROOT . '/resources/coa/core-starter-1.0.0.json');
        if ($source === false) { throw new RuntimeException('The 1.0.0 starter chart is unavailable.'); }
        $template = json_decode($source, true, 512, JSON_THROW_ON_ERROR);
        $template['digest'] = hash('sha256', $source);
        $accounts = [];
        $mapping = [];
        foreach ($template['accounts'] as $definition) {
            DB::insert('pl_accounts', ['company_id' => $companyId, 'book_id' => $bookId, 'code' => $definition['code'],
                'name' => $definition['name'], 'type' => $definition['type'], 'role' => $definition['role'],
                'semantic_key' => $definition['semantic_key'], 'is_active' => 1] + pl_currency_account_properties($definition));
            $accounts[$definition['code']] = (int) DB::insertId();
            $mapping[$definition['semantic_key']] = $accounts[$definition['code']];
        }
        // The installed company of that era also carried its chart snapshot; the helper that writes
        // it touches only tables migration 026 and earlier created, so it is not newer than either
        // schema simulated here.
        pl_install_template_snapshot($actorId, $companyId, $bookId, $template, $mapping);
        return ['company_id' => $companyId, 'book_id' => $bookId, 'period_id' => $periodId, 'accounts' => $accounts];
    };
    // `036_structured_account_codes` renumbers a prior chart and keeps the old number twice: on the
    // account row as `legacy_code` and in the immutable `pl_account_code_map`. Proving that for every
    // seeded account is what lets the comparisons below leave `code` out and stay as strict as they
    // were: the field is not ignored, it is checked here instead.
    $requireConvertedChart = static function (array $accounts): void {
        foreach ($accounts as $oldCode => $accountId) {
            $row = DB::queryFirstRow('SELECT code, legacy_code FROM pl_accounts WHERE id = %i', $accountId);
            if (!pl_account_code_is_valid((string) $row['code']) || (string) $row['legacy_code'] !== (string) $oldCode
                || (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_account_code_map WHERE account_id = %i AND legacy_code = %s', $accountId, (string) $oldCode) !== 1) {
                throw new RuntimeException('The chart conversion did not record the old number of account ' . $oldCode . '.');
            }
        }
    };
    // Migration 045 names the classes and groups of a converted chart. Those rows are headings:
    // they hold no posting and carry no purpose, so they are checked separately from the accounts
    // a migration provisions, and an unexplained new *account* still fails the check below.
    $requireNamedHeadings = static function (int $companyId, int $bookId, array $rows): array {
        $accounts = [];
        $headings = [];
        foreach ($rows as $row) {
            if (!pl_account_code_is_valid((string) $row['code']) || !pl_account_code_is_heading((string) $row['code'])) {
                $accounts[] = $row;
                continue;
            }
            if ((string) ($row['creation_key'] ?? '') !== 'migration-045-' . (string) $row['code']
                || $row['semantic_key'] !== null || $row['role'] !== null || (int) $row['is_active'] !== 1) {
                throw new RuntimeException('A chart heading in an upgraded book was not the one migration 045 writes: ' . $row['code']);
            }
            $headings[] = (string) $row['code'];
        }
        // Every class the book uses is named, because the class digit is the classification and
        // nothing has to be inferred to say "Assets".
        $classes = DB::queryFirstColumn("SELECT DISTINCT CONCAT(LEFT(code, 1), '-000-00000-00') FROM pl_accounts
            WHERE company_id = %i AND book_id = %i AND code LIKE '_-___-_____-__' AND code NOT LIKE '_-000-00000-__' ORDER BY 1", $companyId, $bookId);
        foreach ($classes as $class) {
            if (!in_array((string) $class, $headings, true)) {
                throw new RuntimeException('The upgrade left class ' . $class . ' unnamed, so its reports still read as a code.');
            }
        }
        return $accounts;
    };
    // Migration 039 provisions the two advances controls in every book that has neither the role nor
    // the semantic key, so a prior book's chart grows by exactly those two accounts and by the
    // heading names 045 writes, and by nothing else.
    $requireProvisionedAdvances = static function (int $companyId, int $bookId, array $seededIds) use ($requireNamedHeadings): void {
        $rows = DB::query('SELECT id, code, role, semantic_key, is_active, creation_key FROM pl_accounts WHERE company_id = %i AND book_id = %i AND id NOT IN %li ORDER BY semantic_key', $companyId, $bookId, $seededIds);
        $added = $requireNamedHeadings($companyId, $bookId, $rows);
        if (array_column($added, 'semantic_key') !== ['core.asset.supplier_advances', 'core.liability.customer_advances']
            || array_column($added, 'role') !== ['supplier_advances', 'customer_advances']
            || array_filter($added, static fn(array $row): bool => (int) $row['is_active'] !== 1) !== []) {
            throw new RuntimeException('The upgrade did not provision exactly the two active advances control accounts.');
        }
    };
    if ($baseline === 'preview-0.5.0') {
        $f = $seedPriorCompany($actor, 'Sample 0.5 upgrade company', 'USD', '2026-01-01', '2026-12-31');
        $journal = pl_post_journal($actor, $f['company_id'], $f['book_id'], [
            'date'=>'2026-09-17','currency'=>'USD','source_type'=>'receipt','source_reference'=>'sample-upgrade-0.5',
            'description'=>'Prior 0.5 sample receipt','idempotency_key'=>'sample-upgrade-0.5',
            'lines'=>[['account_id'=>$f['accounts']['1000'],'debit'=>'125.0000','credit'=>'0'],['account_id'=>$f['accounts']['4000'],'debit'=>'0','credit'=>'125.0000']],
        ]);
        DB::insert('pl_connection_clients',['client_id'=>'sample-upgrade','name'=>'Prior sample client','redirect_uris'=>'[]','source'=>'personal']);
        $connectionId = bin2hex(random_bytes(16));
        DB::insert('pl_connections',['id'=>$connectionId,'actor_id'=>$actor,'client_id'=>'sample-upgrade','name'=>'Prior sample grant','kind'=>'personal','oauth_scopes'=>'ledger.read','resource'=>'https://sample.invalid/mcp','expires_at'=>'2026-10-17 00:00:00']);
        $seededIds = array_values($f['accounts']);
        // `pl_get_journal()` reads each line's account code through a join, so the renumbering
        // legitimately changes what a reader sees. The code is proved separately, against the
        // conversion record, and dropped from the equality so every other field stays exact.
        $withoutCodes = static function (array $journal): array {
            foreach (array_keys($journal['lines']) as $index) { unset($journal['lines'][$index]['code']); }
            return $journal;
        };
        $accountFields = 'id, company_id, book_id, name, type, role, semantic_key, is_active, currency, is_monetary, revaluation_account_id, group_account_id';
        $before = pl_get_journal($actor,$f['company_id'],$f['book_id'],$journal['id']);
        $beforeAccounts = DB::query('SELECT ' . $accountFields . ' FROM pl_accounts WHERE id IN %li ORDER BY id', $seededIds);
        $migration = pl_migrate();
        $requireConvertedChart($f['accounts']);
        $requireProvisionedAdvances($f['company_id'], $f['book_id'], $seededIds);
        $after = pl_get_journal($actor,$f['company_id'],$f['book_id'],$journal['id']);
        // Each line now shows its account's converted number, and the number it showed before the
        // upgrade is the `legacy_code` the conversion kept on that account.
        foreach ($after['lines'] as $index => $line) {
            $row = DB::queryFirstRow('SELECT code, legacy_code FROM pl_accounts WHERE id = %i', $line['account_id']);
            if ($line['code'] !== $row['code'] || $before['lines'][$index]['code'] !== $row['legacy_code']) {
                throw new RuntimeException('A journal line does not show the converted number of its account.');
            }
        }
        if ($migration['applied'] !== array_values(array_diff($allVersions,$baseVersions))
            || $withoutCodes($before) !== $withoutCodes($after)
            || $beforeAccounts !== DB::query('SELECT ' . $accountFields . ' FROM pl_accounts WHERE id IN %li ORDER BY id', $seededIds)
            || (int)DB::queryFirstField('SELECT COUNT(*) FROM pl_accounts WHERE report_classification IS NOT NULL') !== 0
            || DB::queryFirstField('SELECT access_mode FROM pl_connections WHERE id=%s',$connectionId) !== 'full'
            || pl_company_context($actor,$f['company_id'])['setup_status'] !== 'ready'
            || pl_trial_balance($actor,$f['company_id'],$f['book_id'])['total_debit'] !== '125.0000'
            || pl_migrate()['applied'] !== []) {
            throw new RuntimeException('0.5 upgrade changed historical records, access, setup or repeatability.');
        }
        echo "Upgrade passed: 0.5 schema through 028, preserved the posted journal and every account field, renumbered the chart keeping each old number twice, provisioned only the two advances controls, kept setup and the existing full-read connection, balanced report and replay.\n";
        return;
    }
    if ($baseline === 'stable-1.1.1') {
        // 034_inventory_locations is the last migration v1.1.1 shipped. A real 1.1.1 installation had
        // Stock locations enabled with a van and a transfer already recorded, and this baseline proves
        // that database upgrades cleanly. Today's stock services cannot build it, and are right not to:
        // the `inventory-locations` manifest is now 1.1.0 and declares `038_stock_documents`, which a
        // 1.1.1 schema does not have, and every warehouse read names the four columns 038 added. An
        // operator migrates first. So the 1.1.1 state is written here the way 1.1.1 held it, using only
        // columns migration 034 and earlier created, and all of it is read back afterwards through the
        // current services. The `inventory` module is the exception: its manifest is identical to the
        // one v1.1.1 shipped and its migrations are in this baseline, so it is enabled for real.
        $f = $seedPriorCompany($actor, 'Sample 1.1.1 upgrade company', 'USD', '2026-01-01', '2026-12-31');
        // The two accounts a 1.1.1 operator added for stock. `pl_save_account()` is not used for the
        // same reason `pl_create_company()` is not: its insert names `is_contra`, which 036 adds.
        foreach (['inventory' => ['1300', 'asset'], 'grni' => ['2100', 'liability']] as $name => [$code, $type]) {
            DB::insert('pl_accounts', ['company_id' => $f['company_id'], 'book_id' => $f['book_id'], 'code' => $code,
                'name' => 'Sample ' . $name, 'type' => $type, 'role' => null, 'semantic_key' => null, 'is_active' => 1,
                'creation_key' => bin2hex(random_bytes(16))] + pl_currency_account_properties(['role' => null]));
            $f['accounts'][$code] = (int) DB::insertId();
            $f[$name . '_account_id'] = $f['accounts'][$code];
        }
        pl_set_company_module($actor, $f['company_id'], 'inventory', true, 0, pl_module_registry()['inventory']['digest'], 'Sample 1.1.1 inventory module', 'sample-upgrade-1.1.1-inventory');
        // Stock locations as v1.1.1 recorded it. The manifest that tag shipped is reproduced here, so the
        // stored digest is derived the way `pl_module_registry()` derives it rather than asserted as a
        // constant; compare it with `git show v1.1.1:resources/modules/inventory-locations.json`. Today's
        // `pl_set_company_module()` would refuse to write this row, because it validates against the 1.1.0
        // manifest whose migration this schema does not have, which is the state the upgrade must resolve.
        $locationsManifest111 = [
            'id' => 'inventory-locations', 'name' => 'Stock locations', 'version' => '1.0.0', 'contract' => 1, 'optional' => true,
            'requires' => ['core' => '1.0.0', 'inventory' => '1.0.0'],
            'capabilities' => ['inventory.locations', 'inventory.transfers'],
            'migrations' => ['034_inventory_locations'],
            'routes' => [], 'permissions' => ['owner', 'accountant'], 'settings' => [], 'reports' => ['stock-by-location'],
            'api_operations' => [], 'mcp_operations' => [],
            'history' => 'Every book has a permanent default warehouse; movements recorded before this module carry it implicitly. Additional warehouses and vans, per-location carrying values and transfers at carrying value require the enabled module. Historical movements stay readable when it is disabled.',
        ];
        DB::insert('pl_company_modules', ['company_id' => $f['company_id'], 'module_id' => 'inventory-locations', 'enabled' => 1,
            'version' => $locationsManifest111['version'], 'manifest_hash' => hash('sha256', json_encode($locationsManifest111, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            'revision' => 1, 'updated_by' => $actor, 'updated_at' => gmdate('Y-m-d H:i:s')]);
        // The product is seeded too: `pl_save_inventory_product()` validates its accounts through
        // `pl_get_account()`, which reads `legacy_code` and so needs 036 as well.
        DB::insert('pl_products', ['company_id' => $f['company_id'], 'book_id' => $f['book_id'], 'sku' => 'SAMPLE-034',
            'name' => 'Sample 1.1.1 product', 'kind' => 'stock', 'base_unit' => 'each', 'selling_price' => '25.0000', 'is_active' => 1,
            'inventory_account_id' => $f['inventory_account_id'], 'cogs_account_id' => $f['accounts']['5000'],
            'sales_account_id' => $f['accounts']['4000'], 'purchase_account_id' => $f['accounts']['5000'], 'created_by' => $actor]);
        $productId = (int) DB::insertId();
        // Migration 034 gives every book that already exists a default warehouse; this company is created
        // after it ran, so the row is written here exactly as that statement writes it.
        DB::insert('pl_inventory_warehouses', ['company_id' => $f['company_id'], 'book_id' => $f['book_id'], 'code' => 'DEFAULT',
            'name' => 'Default warehouse', 'is_default' => 1, 'created_by' => $actor]);
        $defaultWarehouseId = (int) DB::insertId();
        DB::insert('pl_inventory_warehouses', ['company_id' => $f['company_id'], 'book_id' => $f['book_id'], 'code' => 'SAMPLE-VAN',
            'name' => 'Sample upgrade van', 'is_default' => 0, 'is_active' => 1, 'created_by' => $actor]);
        $vanWarehouseId = (int) DB::insertId();
        // Ten units received at 100.00 and payable through goods received not invoiced. The accounting
        // half goes through the central posting service, which no module gates; only the stock movement
        // row is written directly.
        $receiptJournal = pl_post_journal($actor, $f['company_id'], $f['book_id'], [
            'date' => '2026-01-05', 'currency' => 'USD', 'source_type' => 'sample_upgrade', 'source_reference' => 'sample-upgrade-1.1.1',
            'idempotency_key' => 'sample-upgrade-1.1.1-receipt', 'description' => 'Prior 1.1.1 stock receipt', 'lines' => [
                ['account_id' => $f['inventory_account_id'], 'debit' => '100.0000', 'credit' => '0'],
                ['account_id' => $f['grni_account_id'], 'debit' => '0', 'credit' => '100.0000'],
            ],
        ]);
        DB::insert('pl_inventory_movements', ['company_id' => $f['company_id'], 'book_id' => $f['book_id'], 'product_id' => $productId,
            'warehouse_id' => $defaultWarehouseId, 'movement_date' => '2026-01-05', 'kind' => 'receipt', 'quantity_delta' => '10.0000',
            'value_delta' => '100.0000', 'inventory_account_id' => $f['inventory_account_id'], 'offset_account_id' => $f['grni_account_id'],
            'journal_id' => (int) $receiptJournal['id'], 'source_type' => 'sample_upgrade', 'source_reference' => 'sample-upgrade-1.1.1',
            'reason' => 'Sample 1.1.1 upgrade receipt', 'created_by' => $actor]);
        // Four of the ten units moved to the van at carrying value: 100.0000 over 10 units is 10.0000 each,
        // so 40.0000 leaves the default warehouse and the same 40.0000 arrives in the van. A transfer posts
        // no journal, which is why both rows carry none; the schema's own CHECK enforces that shape.
        DB::insert('pl_inventory_movements', ['company_id' => $f['company_id'], 'book_id' => $f['book_id'], 'product_id' => $productId,
            'warehouse_id' => $defaultWarehouseId, 'movement_date' => '2026-01-06', 'kind' => 'transfer_out', 'quantity_delta' => '-4.0000',
            'value_delta' => '-40.0000', 'inventory_account_id' => $f['inventory_account_id'], 'source_type' => 'inventory_transfer_out',
            'source_reference' => 'sample-upgrade-1.1.1-transfer', 'reason' => 'Sample 1.1.1 upgrade transfer', 'created_by' => $actor]);
        $transferOutId = (int) DB::insertId();
        DB::insert('pl_inventory_movements', ['company_id' => $f['company_id'], 'book_id' => $f['book_id'], 'product_id' => $productId,
            'warehouse_id' => $vanWarehouseId, 'movement_date' => '2026-01-06', 'kind' => 'transfer_in', 'quantity_delta' => '4.0000',
            'value_delta' => '40.0000', 'inventory_account_id' => $f['inventory_account_id'], 'original_movement_id' => $transferOutId,
            'source_type' => 'inventory_transfer_in', 'source_reference' => 'sample-upgrade-1.1.1-transfer',
            'reason' => 'Sample 1.1.1 upgrade transfer', 'created_by' => $actor]);
        $seededIds = array_values($f['accounts']);

        $migration = pl_migrate();
        $requireConvertedChart($f['accounts']);
        $requireProvisionedAdvances($f['company_id'], $f['book_id'], $seededIds);
        // The book still carries the manifest it reviewed on 1.1.1, so a new locations operation is
        // refused until the owner reviews the version the upgrade brought. Reads of what is already
        // recorded are not gated, and must not be.
        $refused = null;
        try {
            pl_inventory_transfer($actor, $f['company_id'], $f['book_id'], ['product_id' => $productId, 'from_warehouse_id' => $defaultWarehouseId,
                'to_warehouse_id' => $vanWarehouseId, 'quantity' => '1', 'date' => '2026-02-01', 'source_reference' => 'sample-upgrade-1.1.1-blocked',
                'reason' => 'Sample blocked transfer', 'idempotency_key' => 'sample-upgrade-1.1.1-blocked']);
        } catch (DomainException $error) { $refused = $error->getMessage(); }
        if ($refused === null || !str_contains($refused, 'Review and apply the module upgrade')) {
            throw new RuntimeException('An upgraded book kept writing through the module version it has not reviewed.');
        }
        pl_set_company_module($actor, $f['company_id'], 'inventory-locations', true, 1, pl_module_registry()['inventory-locations']['digest'],
            'Reviewed the 1.2 Stock locations version', 'sample-upgrade-1.1.1-locations-upgrade');
        // What was recorded on 1.1.1 must read back unchanged, to the unit and to the four decimals:
        // six units worth 60.0000 in the default warehouse, four worth 40.0000 in the van, 100.0000
        // valued in total and reconciling to the ledger, with the van still not the book default.
        $defaultBalance = pl_inventory_balance($actor, $f['company_id'], $f['book_id'], $productId, null, $defaultWarehouseId);
        $vanBalance = pl_inventory_balance($actor, $f['company_id'], $f['book_id'], $productId, null, $vanWarehouseId);
        $valuation = pl_inventory_valuation($actor, $f['company_id'], $f['book_id']);
        $warehouses = pl_list_inventory_warehouses($actor, $f['company_id'], $f['book_id']);
        if ($migration['applied'] !== array_values(array_diff($allVersions, $baseVersions))
            || [$defaultBalance['quantity'], $defaultBalance['value_base'], $defaultBalance['latest_date']] !== ['6.0000', '60.0000', '2026-01-06']
            || [$vanBalance['quantity'], $vanBalance['value_base'], $vanBalance['latest_date']] !== ['4.0000', '40.0000', '2026-01-06']
            || $valuation['total_value_base'] !== '100.0000' || count($valuation['accounts']) !== 1 || $valuation['accounts'][0]['difference'] !== '0.0000'
            || array_column($warehouses, 'code') !== ['DEFAULT', 'SAMPLE-VAN']
            || count(array_filter($warehouses, static fn(array $w): bool => $w['is_default'])) !== 1
            || array_column($warehouses, 'kind') !== ['fixed', 'fixed']
            || !pl_module_state($f['company_id'], 'inventory-locations')['enabled']
            || (int) DB::queryFirstField("SELECT COUNT(*) FROM pl_inventory_movements WHERE book_id = %i AND kind IN ('transfer_out','transfer_in') AND journal_id IS NOT NULL", $f['book_id']) !== 0
            || pl_trial_balance($actor, $f['company_id'], $f['book_id'])['total_debit'] !== '100.0000'
            || pl_migrate()['applied'] !== []) {
            throw new RuntimeException('1.1.1 upgrade changed historical stock, warehouses, module state or reconciliation.');
        }
        echo "Upgrade passed: 1.1.1 schema through 034, renumbered the chart keeping each old number twice, provisioned only the two advances controls, refused a locations write until the owner reviewed the version the upgrade brought, then read back six units worth 60.0000 in the default warehouse and four worth 40.0000 in the van, 100.0000 valued and reconciled to the ledger, one default warehouse, no journal behind a transfer, balanced report and replay.\n";
        return;
    }
    if ($baseline === 'converted-chart') {
        // Internal accounting review of 1.2, finding 5. Migration 036 renumbers an existing chart
        // and adds `is_contra DEFAULT 0` without ever setting it, so a book upgraded to 1.2 has no
        // drawings account and loses every deduction presentation. Migration 040 sets what the
        // chart's own `semantic_key` values determine, and asks about the rest.
        //
        // The chart below is seeded the way a pre-036 installation actually holds one: its own
        // account numbers, `legacy_code` and `is_contra` not yet existing as columns. Two accounts
        // carry a contra purpose, which is what `pl_confirm_existing_setup()` writes when an owner
        // maps their chart onto the starter purposes; the rest carry no key at all, which is what
        // an ordinary upgraded chart looks like. Nothing may be deduced from an account's name,
        // and "Partner drawings", "Sales returns" and "Purchase returns" are here to prove it.
        DB::insert('pl_companies', ['name' => 'Sample converted-chart company', 'currency' => 'USD', 'functional_currency' => 'USD',
            'presentation_currency' => 'USD', 'start_date' => '2026-01-01', 'fiscal_year_end' => '12-31', 'created_by' => $actor, 'setup_status' => 'ready']);
        $company = (int) DB::insertId();
        DB::insert('pl_company_members', ['company_id' => $company, 'user_id' => $actor, 'role' => 'owner']);
        DB::insert('pl_books', ['company_id' => $company, 'name' => 'Primary book', 'functional_currency' => 'USD', 'presentation_currency' => 'USD']);
        $book = (int) DB::insertId();
        DB::insert('pl_periods', ['company_id' => $company, 'book_id' => $book, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        $period = (int) DB::insertId();
        $legacy = [];
        foreach ([
            ['1000', 'Cash and bank', 'asset', 'cash_bank', 'core.cash_bank'],
            ['1100', 'Accounts receivable', 'asset', 'receivables', 'core.receivables.trade'],
            ['1500', 'Accumulated depreciation', 'asset', null, 'core.asset.accumulated_depreciation'],
            ['1600', 'Motor vehicles', 'asset', null, null],
            ['2000', 'Accounts payable', 'liability', 'payables', 'core.payables.trade'],
            ['2100', 'Owner loan account', 'liability', null, 'core.liability.owner_loan'],
            ['3000', 'Owner equity', 'equity', 'owner_equity', 'core.equity.owner'],
            ['3500', 'Drawings', 'equity', null, 'core.equity.drawings'],
            ['3600', 'Partner drawings', 'equity', null, null],
            ['4000', 'Sales and service income', 'income', 'income', 'core.income.sales'],
            ['4500', 'Sales returns', 'income', null, null],
            ['5000', 'General expenses', 'expense', 'expense', 'core.expense.general'],
            ['5500', 'Purchase returns', 'expense', null, null],
        ] as [$code, $name, $type, $role, $key]) {
            $monetary = in_array($role, ['cash_bank', 'receivables', 'payables'], true) ? 1
                : (in_array($role, ['owner_equity', 'income', 'expense'], true) ? 0 : null);
            DB::insert('pl_accounts', ['company_id' => $company, 'book_id' => $book, 'code' => $code, 'name' => $name,
                'type' => $type, 'role' => $role, 'semantic_key' => $key, 'is_active' => 1, 'is_monetary' => $monetary]);
            $legacy[$code] = (int) DB::insertId();
        }
        DB::insert('pl_journals', ['company_id' => $company, 'book_id' => $book, 'period_id' => $period, 'journal_date' => '2026-03-01',
            'currency' => 'USD', 'description' => 'Prior sample receipt', 'source_type' => 'receipt', 'source_reference' => 'converted-chart',
            'idempotency_key' => 'converted-chart', 'payload_hash' => hash('sha256', 'sample-converted-chart'), 'posted_by' => $actor]);
        $journal = (int) DB::insertId();
        foreach ([[$legacy['1000'], '250.0000', '0.0000'], [$legacy['4000'], '0.0000', '250.0000']] as $index => [$accountId, $debit, $credit]) {
            DB::insert('pl_journal_lines', ['journal_id' => $journal, 'company_id' => $company, 'book_id' => $book,
                'line_number' => $index + 1, 'account_id' => $accountId, 'description' => 'Prior sample line',
                'debit' => $debit, 'credit' => $credit, 'currency' => 'USD', 'amount_fc' => bcadd($debit, $credit, 4),
                'amount_base' => bcadd($debit, $credit, 4), 'rate' => '1.000000000000', 'rate_type' => 'spot', 'rate_is_stale' => 0]);
        }
        $beforeHeader = DB::queryFirstRow('SELECT * FROM pl_journals WHERE id = %i', $journal);
        $beforeLines = DB::query('SELECT * FROM pl_journal_lines WHERE journal_id = %i ORDER BY line_number', $journal);
        $seededIds = array_values($legacy);
        $beforeNames = DB::query('SELECT id, name, type, role, semantic_key FROM pl_accounts WHERE id IN %li ORDER BY id', $seededIds);

        $migration = pl_migrate();
        if ($migration['applied'] !== array_values(array_diff($allVersions, $baseVersions)) || $migration['skipped'] !== $baseVersions) {
            throw new RuntimeException('Unexpected upgrade migration receipt.');
        }

        // 1. Every seeded account was renumbered and its old number kept twice. Accounts a later
        //    migration adds to the book (039's two advances controls) are born numbered and
        //    correctly carry no conversion record.
        foreach ($legacy as $oldCode => $accountId) {
            $row = DB::queryFirstRow('SELECT code, legacy_code FROM pl_accounts WHERE id = %i', $accountId);
            if (!pl_account_code_is_valid((string) $row['code']) || (string) $row['legacy_code'] !== (string) $oldCode
                || (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_account_code_map WHERE account_id = %i AND legacy_code = %s', $accountId, (string) $oldCode) !== 1) {
                throw new RuntimeException('The chart conversion did not record the old number of account ' . $oldCode . '.');
            }
        }
        // 2. Exactly the accounts whose semantic key is a contra purpose are marked, and nothing
        //    was deduced from a name: "Partner drawings", "Sales returns" and "Purchase returns"
        //    have no key and are untouched.
        $contra = DB::queryFirstColumn('SELECT legacy_code FROM pl_accounts WHERE book_id = %i AND is_contra = 1 ORDER BY legacy_code', $book);
        if (array_map('strval', $contra) !== ['1500', '3500']) {
            throw new RuntimeException('Migration 040 marked the wrong accounts contra: ' . implode(', ', $contra) . '.');
        }
        // 3. Historical records are unchanged apart from the account code the conversion rewrote.
        $lineFields = array_fill_keys(array_keys($beforeLines[0]), true);
        if ($beforeHeader !== array_intersect_key(DB::queryFirstRow('SELECT * FROM pl_journals WHERE id = %i', $journal), $beforeHeader)
            || $beforeLines !== array_map(static fn(array $line): array => array_intersect_key($line, $lineFields), DB::query('SELECT * FROM pl_journal_lines WHERE journal_id = %i ORDER BY line_number', $journal))
            || $beforeNames !== DB::query('SELECT id, name, type, role, semantic_key FROM pl_accounts WHERE id IN %li ORDER BY id', $seededIds)) {
            throw new RuntimeException('The upgrade changed prior accounting records.');
        }
        // 4. The confirm step exists, is pending, and holds the owner screens shut.
        $state = pl_contra_confirmation($company, $book);
        if ($state === null || $state['status'] !== 'pending' || $state['derived_contra_count'] !== 2 || $state['candidate_count'] !== 4) {
            throw new RuntimeException('The contra confirmation step was not created as expected.');
        }
        $refused = null;
        try {
            pl_post_owner_transaction($actor, $company, $book, ['kind' => 'drawings', 'date' => '2026-06-01', 'amount' => '100.0000',
                'description' => 'Sample drawings', 'creation_key' => 'converted-chart-blocked']);
        } catch (DomainException $error) { $refused = $error->getMessage(); }
        if ($refused === null || !str_contains($refused, 'Confirm which accounts are contra accounts')) {
            throw new RuntimeException('The owner screens were not held shut by the unanswered confirm step.');
        }
        // 5. The step offers exactly the keyless accounts, and marks exactly what it is told to.
        $review = pl_contra_review($actor, $company, $book);
        $offered = [];
        foreach ($review['candidates'] as $candidate) { $offered[] = (string) $candidate['legacy_code']; }
        sort($offered);
        if ($offered !== ['1600', '3600', '4500', '5500'] || count($review['derived']) !== 2) {
            throw new RuntimeException('The confirm step offered the wrong accounts: ' . implode(', ', $offered) . '.');
        }
        pl_confirm_contra_accounts($actor, $company, $book, [$legacy['4500'], $legacy['5500']],
            'Reviewed against the prior chart with the accountant.', 'converted-chart-confirm');
        $after = DB::queryFirstColumn('SELECT legacy_code FROM pl_accounts WHERE book_id = %i AND is_contra = 1 ORDER BY legacy_code', $book);
        if (array_map('strval', $after) !== ['1500', '3500', '4500', '5500']
            || (int) DB::queryFirstField('SELECT is_contra FROM pl_accounts WHERE id = %i', $legacy['3600']) !== 0) {
            throw new RuntimeException('The reviewed answer did not mark exactly the accounts it named.');
        }
        // 6. The owner screens work again, and drawings reach the chart's own drawings account.
        $drawings = pl_post_owner_transaction($actor, $company, $book, ['kind' => 'drawings', 'date' => '2026-06-01',
            'amount' => '100.0000', 'description' => 'Sample drawings', 'creation_key' => 'converted-chart-drawings']);
        if ((int) $drawings['lines'][0]['account_id'] !== $legacy['3500']
            || (int) $drawings['lines'][1]['account_id'] !== $legacy['1000']
            || !pl_trial_balance($actor, $company, $book, '2026-06-01')['balanced']
            || pl_migrate()['applied'] !== []) {
            throw new RuntimeException('Owner transactions, reconciliation or replay failed after the confirm step.');
        }
        echo "Upgrade passed: chart converted by 036 renumbered with its old numbers kept; 040 marked only the two accounts whose semantic key is a contra purpose (1500, 3500) and guessed nothing from a name; the confirm step offered the four keyless accounts (1600, 3600, 4500, 5500), refused the owner screens until answered, marked exactly the two it was told to, and drawings then posted to the chart's own drawings account with a balanced trial balance and a no-op replay.\n";
        return;
    }
    DB::insert('pl_companies', ['name' => 'Prior foundation sample company', 'currency' => 'USD', 'start_date' => '2026-01-01', 'fiscal_year_end' => '12-31', 'created_by' => $actor]);
    $company = (int) DB::insertId();
    DB::insert('pl_company_members', ['company_id' => $company, 'user_id' => $actor, 'role' => 'owner']);
    DB::insert('pl_books', ['company_id' => $company, 'name' => 'Primary book']);
    $book = (int) DB::insertId();
    DB::insert('pl_periods', ['company_id' => $company, 'book_id' => $book, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
    $period = (int) DB::insertId();
    // A prior book holds the chart of its own release, which is why it does not hold the accounts a
    // later migration provisions for it: `039_advances_and_refunds` creates the two advances controls
    // in every book that has neither the role nor the semantic key. Seeding them here would turn that
    // statement into a no-op and hide what the upgrade actually does for an existing book.
    $provisionedKeys = ['core.asset.supplier_advances', 'core.liability.customer_advances'];
    $accounts = [];
    foreach (pl_starter_template()['accounts'] as $definition) {
        if (in_array($definition['semantic_key'], $provisionedKeys, true)) { continue; }
        // The custom name is keyed on the purpose rather than on the number, because the bundled
        // chart was renumbered in 1.2 (B56) and its cash account no longer carries the code 1000.
        DB::insert('pl_accounts', ['company_id' => $company, 'book_id' => $book, 'code' => $definition['code'], 'name' => $definition['semantic_key'] === 'core.cash_bank' ? 'Preserved custom bank name' : $definition['name'], 'type' => $definition['type']]);
        $accounts[$definition['semantic_key']] = (int) DB::insertId();
    }
    DB::insert('pl_journals', ['company_id' => $company, 'book_id' => $book, 'period_id' => $period, 'journal_date' => '2026-09-14', 'currency' => 'USD', 'description' => 'Prior sample receipt', 'source_type' => 'receipt', 'source_reference' => 'upgrade-fixture', 'idempotency_key' => 'upgrade-fixture', 'payload_hash' => hash('sha256', 'sample-old-fixture'), 'posted_by' => $actor]);
    $journal = (int) DB::insertId();
    foreach ([['core.cash_bank', '125.0000', '0.0000'], ['core.income.sales', '0.0000', '125.0000']] as $index => [$key, $debit, $credit]) {
        DB::insert('pl_journal_lines', ['journal_id' => $journal, 'company_id' => $company, 'book_id' => $book, 'line_number' => $index + 1, 'account_id' => $accounts[$key], 'description' => 'Prior sample line', 'debit' => $debit, 'credit' => $credit]);
    }
    $beforeHeader = DB::queryFirstRow('SELECT * FROM pl_journals WHERE id = %i', $journal);
    $beforeLines = DB::query('SELECT * FROM pl_journal_lines WHERE journal_id = %i ORDER BY line_number', $journal);
    $seededIds = array_values($accounts);
    $beforeAccounts = DB::query('SELECT id, company_id, book_id, code, name, type, is_active FROM pl_accounts WHERE id IN %li ORDER BY id', $seededIds);
    $migration = pl_migrate();
    if ($migration['applied'] !== array_values(array_diff($allVersions, $baseVersions)) || $migration['skipped'] !== $baseVersions) {
        throw new RuntimeException('Unexpected upgrade migration receipt.');
    }
    $afterHeader = DB::queryFirstRow('SELECT * FROM pl_journals WHERE id = %i', $journal);
    $afterLines = DB::query('SELECT * FROM pl_journal_lines WHERE journal_id = %i ORDER BY line_number', $journal);
    $originalLineFields = array_fill_keys(array_keys($beforeLines[0]), true);
    if ($beforeHeader !== array_intersect_key($afterHeader, $beforeHeader)
        || $beforeLines !== array_map(static fn(array $line): array => array_intersect_key($line, $originalLineFields), $afterLines)
        || $beforeAccounts !== DB::query('SELECT id, company_id, book_id, code, name, type, is_active FROM pl_accounts WHERE id IN %li ORDER BY id', $seededIds)) {
        throw new RuntimeException('The additive upgrade changed prior accounting records.');
    }
    // The chart may grow by exactly what the chain provisions, plus the class and group names
    // migration 045 writes, and by nothing else. Checking the additions by name keeps the old
    // blanket comparison's strength: an unexplained new account in a prior book still fails here.
    $rows = DB::query('SELECT id, code, role, semantic_key, is_active, creation_key FROM pl_accounts WHERE company_id = %i AND book_id = %i AND id NOT IN %li ORDER BY semantic_key', $company, $book, $seededIds);
    $provisioned = $requireNamedHeadings($company, $book, $rows);
    if (array_column($provisioned, 'semantic_key') !== $provisionedKeys
        || array_column($provisioned, 'role') !== ['supplier_advances', 'customer_advances']
        || array_filter($provisioned, static fn(array $row): bool => (int) $row['is_active'] !== 1) !== []) {
        throw new RuntimeException('The upgrade did not provision exactly the two active advances control accounts.');
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
    // No account of the prior chart may carry a starter purpose before the owner has mapped one;
    // the two accounts the chain provisioned carry theirs by construction and are excluded by id.
    $provisionedIds = array_map(static fn(array $row): int => (int) $row['id'], $provisioned);
    if ($context['setup_status'] !== 'review_required' || $context['template'] !== null
        || count(array_filter($context['accounts'], static fn (array $account): bool => $account['role'] !== null && !in_array($account['id'], $provisionedIds, true))) !== 0) {
        throw new RuntimeException('Prior companies were not safely held for chart/opening review.');
    }
    // The review maps every starter purpose onto a separate existing account, so the two advances
    // purposes are mapped onto the accounts the chain created for them, which is what an owner has
    // in front of them on the review screen.
    foreach ($provisioned as $row) { $accounts[(string) $row['semantic_key']] = (int) $row['id']; }
    pl_confirm_existing_setup($actor, $company, $book, $accounts, true);
    if (pl_trial_balance($actor, $company, $book)['total_debit'] !== '125.0000'
        || pl_migrate()['applied'] !== [] || DB::queryFirstField('SELECT @@session.time_zone') !== '+00:00') {
        throw new RuntimeException('Review/replay/UTC validation failed.');
    }
    echo "Upgrade passed: {$baseline} -> complete supplied chain, preserved " . count($seededIds) . " prior accounts and the posted journal/lines, provisioned only the two advances controls, required review, explicit role mapping, reconciled report, replay and UTC session.\n";
} finally {
    DB::useDB('phpledger_test');
    if ($created && preg_match('/^phpledger_upgrade_verify_[a-f0-9]{24}$/D', $upgradeDatabase)) {
        DB::query('DROP DATABASE %b', $upgradeDatabase);
        echo "Removed only this run's isolated upgrade database; phpledger_test and development data remain intact.\n";
    }
}
