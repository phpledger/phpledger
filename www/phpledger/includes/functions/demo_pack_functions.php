<?php
declare(strict_types=1);

/** Only these original bundled pack identities are selectable, never a request path. */
function pl_demo_pack_catalog(): array
{
    $catalog = json_decode((string) file_get_contents(PL_ROOT . '/resources/demo-packs/catalog.json'), true, 32, JSON_THROW_ON_ERROR);
    $allowed = ['service-agency', 'retail-shop', 'seasonal-business', 'distributor', 'trader', 'restaurant', 'membership-club', 'pharmacy', 'jewelry-studio', 'light-manufacturing', 'service-workshop'];
    $result = [];
    foreach ($catalog as $pack) {
        if (!in_array($pack['id'], $allowed, true)
            || $pack['version'] !== '1.0.0' || $pack['file'] !== $pack['id'] . '-1.0.0.json'
            || !in_array($pack['status'], ['released_demo_only', 'preview_only'], true)
            || !is_string($pack['capability_note']) || $pack['capability_note'] === ''
            || !preg_match('/^[a-f0-9]{64}$/D', $pack['sha256'])) {
            throw new RuntimeException('The bundled sample catalog is invalid.');
        }
        $result[$pack['id']] = $pack;
    }
    if (count($result) !== count($allowed) || array_diff($allowed, array_keys($result)) !== []) { throw new RuntimeException('The bundled sample catalog is incomplete.'); }
    return $result;
}

function pl_demo_pack(string $id): array
{
    $entry = pl_demo_pack_catalog()[$id] ?? null;
    if ($entry === null) { throw new DomainException('Choose one of the eleven bundled sample companies.'); }
    $raw = file_get_contents(PL_ROOT . '/resources/demo-packs/' . $entry['file']);
    if ($raw === false) { throw new RuntimeException('The selected sample is unavailable.'); }
    // Git may check out text with CRLF; the pinned fixture bytes use LF.
    $raw = str_replace("\r\n", "\n", $raw);
    if (!hash_equals($entry['sha256'], hash('sha256', $raw))) { throw new RuntimeException('The selected sample changed; restore its pinned fixture.'); }
    $pack = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    // The source count is cross-checked against the catalog rather than pinned to one
    // number here: a partnership splits capital and drawings per partner, so the packs no
    // longer all carry the same number of sources. The floor keeps a truncated pack out.
    $sources = count($pack['events']) + count($pack['drafts']);
    if ($pack['id'] !== $id || $pack['version'] !== $entry['version'] || $pack['demo_only'] !== true || !in_array($pack['status'], ['released_demo_only', 'preview_only'], true)
        || $pack['start_date'] !== '2024-01-01' || $sources < 85 || $sources !== (int) $entry['source_count'] || $sources !== (int) $pack['source_count']
        || count($pack['checkpoints']) !== 36 || !is_array($pack['company_profile'] ?? null) || !is_array($pack['partners'] ?? null)
        || !is_array($pack['anticipated_1_3'] ?? null)) { throw new RuntimeException('The selected sample has an invalid contract.'); }
    $pack['digest'] = $entry['sha256'];
    return $pack;
}

/** A separate zero-balance choice; the four pinned historical packs remain unchanged. */
function pl_demo_starter_playground(?string $startDate = null): array
{
    $startDate ??= gmdate('Y') . '-01-01';
    if (!preg_match('/^[0-9]{4}-01-01$/D', $startDate)) { throw new DomainException('The starter playground needs a January practice-year start.'); }
    $pack = [
        'id' => 'accounting-starter', 'version' => '1.0.0', 'kind' => 'starter_playground',
        'name' => 'Accounting starter playground', 'business' => 'Invoices, bills, purchasing and stock',
        'start_date' => $startDate, 'demo_only' => true, 'source_count' => 0,
        'notice' => 'Start with zero balances and no stock. The example tax is a manually configured sample 5 percent rate, not a country tax rule.',
        'accounts' => [
            ['code' => '1300', 'name' => 'Stock on hand', 'type' => 'asset'],
            ['code' => '1350', 'name' => 'Sample input tax', 'type' => 'asset'],
            ['code' => '2100', 'name' => 'Goods received awaiting bills', 'type' => 'liability'],
            ['code' => '2150', 'name' => 'Sample output tax', 'type' => 'liability'],
            ['code' => '5100', 'name' => 'Cost of goods sold', 'type' => 'expense'],
            ['code' => '5200', 'name' => 'Purchase variance and rounding', 'type' => 'expense'],
        ],
        'party_name' => 'Sample customer and supplier',
        'products' => [
            ['sku' => 'SAMPLE-GOODS', 'name' => 'Sample goods', 'kind' => 'stock', 'base_unit' => 'each', 'selling_price' => '25.0000'],
            ['sku' => 'SAMPLE-SERVICE', 'name' => 'Sample service', 'kind' => 'nonstock', 'base_unit' => 'service', 'selling_price' => '50.0000'],
        ],
        'tax' => ['code' => 'DEMO5', 'name' => 'Sample example 5 percent', 'percentage' => '5'],
    ];
    $pack['digest'] = hash('sha256', json_encode($pack, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    return $pack;
}

function pl_demo_sample_choices(): array
{
    return ['accounting-starter' => pl_demo_starter_playground()] + pl_demo_pack_catalog();
}

function pl_demo_sample(string $id): array
{
    return $id === 'accounting-starter' ? pl_demo_starter_playground() : pl_demo_pack($id);
}

/** Provision only a new empty sample through normal scoped setup and module services. */
function pl_seed_demo_starter_playground(int $actorId, int $companyId, int $bookId): void
{
    pl_demo_require_setup_action();
    pl_ledger_transaction(function () use ($actorId, $companyId, $bookId): void {
        pl_require_company_access($actorId, $companyId, true);
        pl_ledger_book($companyId, $bookId, true);
        $company = pl_company_context($actorId, $companyId);
        $pack = pl_demo_starter_playground();
        if (!$company['is_sample'] || $company['setup_status'] !== 'ready' || $company['start_date'] !== $pack['start_date']
            || $company['fiscal_year_end'] !== '12-31'
            || DB::queryFirstField('SELECT id FROM pl_journals WHERE company_id=%i AND book_id=%i LIMIT 1', $companyId, $bookId)
            || DB::queryFirstField('SELECT id FROM pl_documents WHERE company_id=%i AND book_id=%i LIMIT 1', $companyId, $bookId)
            || DB::queryFirstField('SELECT id FROM pl_general_drafts WHERE company_id=%i AND book_id=%i LIMIT 1', $companyId, $bookId)
            || DB::queryFirstField('SELECT id FROM pl_ar_documents WHERE company_id=%i AND book_id=%i LIMIT 1', $companyId, $bookId)
            || DB::queryFirstField('SELECT id FROM pl_products WHERE company_id=%i AND book_id=%i LIMIT 1', $companyId, $bookId)
            || DB::queryFirstField('SELECT user_id FROM pl_demo_visitors WHERE company_id=%i LIMIT 1', $companyId)) {
            throw new DomainException('The starter playground requires a new empty unassigned sample. Existing books cannot be replaced.');
        }
        $mapping = pl_account_code_mapping($company['accounts']);
        $reason = 'Sample zero-balance starter playground, prepared before visitor assignment.';
        foreach ($pack['accounts'] as $definition) {
            $account = pl_save_account($actorId, $companyId, $bookId, $definition + [
                'is_active' => true, 'reason' => $reason, 'creation_key' => 'starter-account:' . $definition['code'],
            ]);
            $mapping[$definition['code']] = $account['id'];
        }
        foreach (['inventory', 'purchasing'] as $module) {
            $manifest = pl_module_registry()[$module];
            pl_set_company_module($actorId, $companyId, $module, true, 0, $manifest['digest'], $reason, 'starter-module:' . $module);
        }
        foreach (['1100', '2000'] as $code) {
            pl_activate_open_item_account($actorId, $companyId, $bookId, $mapping[$code], $reason);
        }
        $party = pl_save_party($actorId, $companyId, $bookId, [
            'legal_name' => $pack['party_name'], 'entity_type' => 'private_company', 'country_code' => 'ZZ',
            'is_customer' => true, 'is_vendor' => true, 'currency' => $company['currency'],
            'ar_account_id' => $mapping['1100'], 'ap_account_id' => $mapping['2000'],
            'notes' => 'Entirely fictional practice party. Country ZZ denotes this sample example.',
            'reason' => $reason, 'request_key' => 'starter-party',
        ]);
        $products = [];
        foreach ($pack['products'] as $definition) {
            $product = pl_save_inventory_product($actorId, $companyId, $bookId, $definition + [
                'is_active' => true, 'inventory_account_id' => $definition['kind'] === 'stock' ? $mapping['1300'] : null,
                'cogs_account_id' => $definition['kind'] === 'stock' ? $mapping['5100'] : null,
                'sales_account_id' => $mapping['4000'], 'purchase_account_id' => $mapping['5000'],
                'reason' => $reason, 'idempotency_key' => 'starter-product:' . $definition['sku'],
            ]);
            $products[$definition['kind']] = $product['id'];
        }
        $tax = pl_create_tax_code($actorId, $companyId, $bookId, [
            'code' => $pack['tax']['code'], 'name' => $pack['tax']['name'], 'treatment' => 'standard',
            'sales_account_id' => $mapping['2150'], 'purchase_account_id' => $mapping['1350'],
            'reason' => $reason, 'idempotency_key' => 'starter-tax-code',
        ]);
        pl_enter_tax_rate($actorId, $companyId, $bookId, [
            'tax_code_id' => $tax['id'], 'effective_from' => $pack['start_date'], 'percentage' => $pack['tax']['percentage'],
            'reason' => $reason, 'idempotency_key' => 'starter-tax-rate',
        ]);
        $snapshot = json_decode((string) DB::queryFirstField('SELECT snapshot FROM pl_template_installations WHERE company_id=%i AND book_id=%i FOR UPDATE', $companyId, $bookId), true, 512, JSON_THROW_ON_ERROR);
        $snapshot['sample_pack'] = ['id' => $pack['id'], 'version' => $pack['version'], 'digest' => $pack['digest'],
            'date' => $pack['start_date'], 'currency' => $company['currency'], 'party_id' => $party['id'],
            'product_ids' => $products, 'tax_code_id' => $tax['id']];
        DB::update('pl_template_installations', ['snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)], 'company_id=%i AND book_id=%i', $companyId, $bookId);
        pl_record_installation_history($actorId, $companyId, $bookId, 'sample', pl_starter_template(), json_encode([
            'sample_pack' => ['id' => $pack['id'], 'version' => $pack['version'], 'digest' => $pack['digest'],
                'date' => $pack['start_date'], 'currency' => $company['currency'], 'party_id' => $party['id'],
                'product_ids' => $products, 'tax_code_id' => $tax['id']],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        if ((int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE company_id=%i AND book_id=%i', $companyId, $bookId) !== 0
            || pl_trial_balance($actorId, $companyId, $bookId)['total_debit'] !== '0.0000') {
            throw new RuntimeException('The starter playground must begin without posted or opening balances.');
        }
    });
}

/** Reconcile authored Decimal checkpoints through the same reports used by the UI/API. */
function pl_demo_pack_reconcile(int $actorId, int $companyId, int $bookId, array $pack): void
{
    foreach ($pack['checkpoints'] as $checkpoint) {
        $trial = pl_trial_balance($actorId, $companyId, $bookId, $checkpoint['to']);
        $actual = [];
        foreach ($trial['accounts'] as $row) {
            $actual[(string) (($row['legacy_code'] ?? '') !== '' ? $row['legacy_code'] : $row['code'])] = $row['balance'];
        }
        $expected = $checkpoint['balances'];
        ksort($actual); ksort($expected);
        $profit = pl_profit_loss($actorId, $companyId, $bookId, $checkpoint['from'], $checkpoint['to']);
        $balance = pl_balance_sheet($actorId, $companyId, $bookId, $checkpoint['to']);
        if (!$trial['balanced'] || $actual !== $expected || !$balance['balanced']
            || $profit['total_income'] !== $checkpoint['income'] || bcadd($profit['total_expenses'],$profit['total_cost_of_sales'],4) !== $checkpoint['expenses']
            || $profit['net_profit'] !== $checkpoint['profit']) {
            throw new RuntimeException('The sample did not reconcile at ' . $checkpoint['to'] . '; setup was rolled back.');
        }
    }
}

/**
 * Operational evidence is deliberately adapted into the existing services.  The
 * pack remains the admission boundary; this function never accepts a caller
 * supplied file or event list.
 */
function pl_demo_operational_contract(array $pack): array
{
    $evidence = $pack['source_material']['research_evidence'] ?? null;
    $events = is_array($evidence) ? ($evidence['operational_event_contract'] ?? null) : null;
    if (!is_array($events) || !array_is_list($events)) {
        throw new RuntimeException('The selected sample has no operational event contract.');
    }
    if (count($events) > 32) {
        throw new RuntimeException('The selected sample has an unbounded operational event contract.');
    }
    $seen = []; $seenReferences = [];
    foreach ($events as $event) {
        if (!is_array($event) || !is_string($event['id'] ?? null) || !preg_match('/^[A-Za-z0-9._:-]{1,120}$/D', $event['id'])
            || isset($seen[$event['id']]) || !is_string($event['date'] ?? null) || !preg_match('/^2026-[0-9]{2}-[0-9]{2}$/D', $event['date'])
            || !is_string($event['kind'] ?? null) || !is_array($event['expected_journal'] ?? null)) {
            throw new RuntimeException('The selected sample has an invalid operational event identity.');
        }
        $reference = $event['source_reference'] ?? ($event['idempotency_key'] ?? null);
        if (!is_string($reference) || $reference === '' || strlen($reference) > 180 || isset($seenReferences[$reference])) {
            throw new RuntimeException('The selected sample has a duplicate or missing operational source reference.');
        }
        $seen[$event['id']] = true;
        $seenReferences[$reference] = true;
        $debit = '0.0000'; $credit = '0.0000';
        foreach ($event['expected_journal'] as $line) {
            if (!is_array($line) || (!isset($line['account_role']) && !isset($line['account_key']))
                || !is_string($line['debit'] ?? null) || !is_string($line['credit'] ?? null)) {
                throw new RuntimeException('The selected sample has an invalid operational journal line.');
            }
            $debit = bcadd($debit, pl_amount($line['debit']), 4); $credit = bcadd($credit, pl_amount($line['credit']), 4);
        }
        if (bccomp($debit, $credit, 4) !== 0) { throw new RuntimeException('The selected sample has an unbalanced operational event.'); }
        if (isset($event['amount']) && (!is_string($event['amount']) || !preg_match('/^[0-9]+\.[0-9]{4}$/D', $event['amount']))) {
            throw new RuntimeException('The selected sample has an invalid operational amount.');
        }
    }
    foreach ($events as $event) {
        foreach (['reverses_event_id', 'original_event_id'] as $linkKey) {
            if (isset($event[$linkKey]) && (!is_string($event[$linkKey]) || !isset($seen[$event[$linkKey]]))) {
                throw new RuntimeException('The selected sample has an operational link to an unknown event.');
            }
        }
    }
    return $events;
}

function pl_demo_operational_role(array $line, array $keyRoles): string
{
    if (is_string($line['account_role'] ?? null) && $line['account_role'] !== '') { return $line['account_role']; }
    $key = $line['account_key'] ?? null;
    if (is_string($key) && isset($keyRoles[$key])) { return $keyRoles[$key]; }
    if (is_string($key) && $key !== '') { return (string) preg_replace('/[^a-z0-9]+/i', '_', strtolower($key)); }
    throw new RuntimeException('The selected sample has an operational line without a semantic account.');
}

function pl_demo_operational_role_type(string $role): array
{
    if ($role === 'accounts_receivable') { return ['asset', 'receivables']; }
    if ($role === 'accounts_payable') { return ['liability', 'payables']; }
    if (in_array($role, ['cash_on_hand', 'bank_current'], true)) { return ['asset', 'cash_bank']; }
    if ($role === 'owner_equity') { return ['equity', 'owner_equity']; }
    if (in_array($role, ['deferred_revenue', 'customer_advance', 'store_credit_liability'], true)
        || str_contains($role, 'payable') || str_contains($role, 'liability') || str_contains($role, 'grni')) { return ['liability', null]; }
    if (str_contains($role, 'inventory') || $role === 'equipment' || str_ends_with($role, '_asset') || $role === 'route_cash') { return ['asset', null]; }
    if (str_contains($role, 'revenue') || str_contains($role, 'income')) { return ['income', 'income']; }
    return ['expense', 'expense'];
}

function pl_demo_operational_role_label(string $role): string
{
    return ucwords(str_replace('_', ' ', $role)) . ' (sample operations)';
}

function pl_demo_operational_due_date(string $date): string
{
    return (new DateTimeImmutable($date))->modify('+30 days')->format('Y-m-d');
}

/** Build a small deterministic account/party/product map for one isolated sample. */
function pl_demo_operational_master_data(int $actorId, int $companyId, int $bookId, array $pack, array $events, string $prefix): array
{
    $evidence = $pack['source_material']['research_evidence']; $keyRoles = [];
    $profile = is_array($evidence['industry_profile'] ?? null) ? $evidence['industry_profile'] : [];
    $roleLabels = is_array($profile['seed_role_labels'] ?? null) ? $profile['seed_role_labels'] : [];
    foreach (($evidence['semantic_accounts'] ?? []) as $definition) {
        if (is_array($definition) && is_string($definition['key'] ?? null) && is_string($definition['fixture_role'] ?? null)) {
            $keyRoles[$definition['key']] = $definition['fixture_role'];
        }
    }
    $roles = ['accounts_receivable', 'accounts_payable', 'bank_current', 'cash_on_hand', 'grni'];
    foreach ($events as $event) {
        foreach ($event['expected_journal'] as $line) { $roles[] = pl_demo_operational_role($line, $keyRoles); }
    }
    $roles = array_values(array_unique($roles)); sort($roles);
    $mapping = []; $next = ['asset' => 1810, 'liability' => 2810, 'equity' => 3810, 'income' => 4810, 'expense' => 5810];
    foreach ($roles as $role) {
        [$type, $accountRole] = pl_demo_operational_role_type($role);
        $code = (string) ($next[$type]++);
        $accountLabel = is_string($roleLabels[$role] ?? null) && $roleLabels[$role] !== '' ? $roleLabels[$role] : pl_demo_operational_role_label($role);
        $classification = [];
        if ($role === 'cost_of_goods_sold') {
            // Preserve old sample-account retries; only new charts get this default.
            $prior = DB::queryFirstRow('SELECT report_classification FROM pl_accounts WHERE company_id=%i AND book_id=%i AND creation_key=%s', $companyId,$bookId,pl_request_key($prefix.'operational-account:'.$role));
            if ($prior === null || $prior['report_classification'] === 'cost_of_sales') { $classification = ['report_classification'=>'cost_of_sales']; }
        }
        $account = pl_save_account($actorId, $companyId, $bookId, ['code' => $code, 'name' => $accountLabel, 'type' => $type,
            'role' => $accountRole, 'is_active' => true, 'reason' => 'Deterministic operational account for the isolated sample.',
            'creation_key' => $prefix . 'operational-account:' . $role] + $classification);
        $mapping[$role] = (int) $account['id'];
    }
    $needsInventory = ($evidence['products'] ?? []) !== []; $needsPurchasing = false;
    foreach ($events as $event) { $needsInventory = $needsInventory || (($event['stock_movements'] ?? []) !== []); $needsPurchasing = $needsPurchasing || ($event['kind'] ?? '') === 'purchase'; }
    $modules = $needsPurchasing ? ['inventory', 'purchasing'] : ($needsInventory ? ['inventory'] : []);
    foreach ($modules as $module) {
        $manifest = pl_module_registry()[$module]; $state = pl_module_state($companyId, $module);
        if (!$state['enabled']) {
            pl_set_company_module($actorId, $companyId, $module, true, $state['revision'], $manifest['digest'],
                'The selected isolated sample includes its operational teaching module.', $prefix . 'operational-module:' . $module);
        }
    }
    $partyIds = ['customer' => [], 'vendor' => []];
    foreach (($evidence['contacts'] ?? []) as $contact) {
        if (!is_array($contact) || !is_string($contact['id'] ?? null) || !is_string($contact['name'] ?? null)) { continue; }
        $role = $contact['role'] ?? ''; $isCustomer = in_array($role, ['customer', 'both'], true); $isVendor = in_array($role, ['vendor', 'both'], true);
        if (!$isCustomer && !$isVendor) { continue; }
        $party = pl_save_party($actorId, $companyId, $bookId, ['legal_name' => $contact['name'], 'entity_type' => 'private_company', 'country_code' => 'ZZ',
            'is_customer' => $isCustomer, 'is_vendor' => $isVendor, 'currency' => pl_company_context($actorId, $companyId)['currency'],
            'ar_account_id' => $isCustomer ? ($mapping['accounts_receivable'] ?? null) : null, 'ap_account_id' => $isVendor ? ($mapping['accounts_payable'] ?? null) : null,
            'notes' => 'Sample contact from the pinned sample research contract.', 'reason' => 'Create isolated sample operational master data.',
            'request_key' => $prefix . 'party:' . $contact['id']]);
        if ($isCustomer) { $partyIds['customer'][$contact['id']] = (int) $party['id']; }
        if ($isVendor) { $partyIds['vendor'][$contact['id']] = (int) $party['id']; }
    }
    if ($partyIds['customer'] === []) { $partyIds['customer']['default'] = (int) pl_save_party($actorId, $companyId, $bookId, ['legal_name' => $pack['name'] . ' customer (Sample)', 'entity_type' => 'private_company', 'country_code' => 'ZZ', 'is_customer' => true, 'is_vendor' => false, 'currency' => pl_company_context($actorId, $companyId)['currency'], 'ar_account_id' => $mapping['accounts_receivable'], 'notes' => 'Sample fallback party.', 'reason' => 'Create isolated sample operational master data.', 'request_key' => $prefix . 'party:default-customer'])['id']; }
    if ($partyIds['vendor'] === []) { $partyIds['vendor']['default'] = (int) pl_save_party($actorId, $companyId, $bookId, ['legal_name' => $pack['name'] . ' supplier (Sample)', 'entity_type' => 'private_company', 'country_code' => 'ZZ', 'is_customer' => false, 'is_vendor' => true, 'currency' => pl_company_context($actorId, $companyId)['currency'], 'ap_account_id' => $mapping['accounts_payable'], 'notes' => 'Sample fallback party.', 'reason' => 'Create isolated sample operational master data.', 'request_key' => $prefix . 'party:default-vendor'])['id']; }
    $firstIncome = null; $firstExpense = null;
    foreach ($roles as $role) { [$type] = pl_demo_operational_role_type($role); if ($type === 'income' && $firstIncome === null) { $firstIncome = $role; } if ($type === 'expense' && $firstExpense === null) { $firstExpense = $role; } }
    $productIds = [];
    foreach (($evidence['products'] ?? []) as $product) {
        if (!is_array($product) || !is_string($product['id'] ?? null)) { continue; }
        $kind = ($product['kind'] ?? '') === 'stock' ? 'stock' : 'nonstock';
        $inventoryRole = isset($product['inventory_account_key']) ? ($keyRoles[$product['inventory_account_key']] ?? 'inventory') : 'inventory';
        $cogsRole = isset($product['cost_account_key']) ? ($keyRoles[$product['cost_account_key']] ?? 'cost_of_goods_sold') : 'cost_of_goods_sold';
        $incomeRole = isset($product['income_account_key']) ? ($keyRoles[$product['income_account_key']] ?? $firstIncome)
            : ($kind === 'stock' && isset($mapping['sales_revenue']) ? 'sales_revenue' : (isset($mapping['service_revenue']) ? 'service_revenue' : $firstIncome));
        if ($incomeRole === null) { $incomeRole = 'sales_revenue'; $mapping[$incomeRole] ??= $mapping[$firstIncome ?? 'sales_revenue']; }
        $purchaseRole = $firstExpense ?? $cogsRole;
        $saved = pl_save_inventory_product($actorId, $companyId, $bookId, ['sku' => 'SAMPLE-' . $pack['id'] . '-' . $product['id'], 'name' => $product['name'], 'kind' => $kind,
            'base_unit' => $product['unit'] ?? 'each', 'selling_price' => $product['sale_price'] ?? '0.0000', 'is_active' => true,
            'inventory_account_id' => $kind === 'stock' ? ($mapping[$inventoryRole] ?? $mapping['inventory']) : null,
            'cogs_account_id' => $kind === 'stock' ? ($mapping[$cogsRole] ?? $mapping['cost_of_goods_sold']) : null,
            'sales_account_id' => $mapping[$incomeRole], 'purchase_account_id' => $mapping[$purchaseRole] ?? $mapping[$cogsRole],
            'reason' => 'Create isolated sample operational master data.', 'idempotency_key' => $prefix . 'product:' . $product['id']]);
        $productIds[$product['id']] = (int) $saved['id'];
    }
    return ['accounts' => $mapping, 'key_roles' => $keyRoles, 'parties' => $partyIds, 'products' => $productIds,
        'first_income' => $firstIncome ?? 'sales_revenue', 'first_expense' => $firstExpense ?? 'operating_expense'];
}

function pl_demo_operational_event_lines(array $event, array $master): array
{
    $lines = [];
    foreach ($event['expected_journal'] as $line) {
        $role = pl_demo_operational_role($line, $master['key_roles']);
        if (!isset($master['accounts'][$role])) { throw new RuntimeException('Operational role is not mapped: ' . $role); }
        $lines[] = ['account_id' => $master['accounts'][$role], 'debit' => pl_amount($line['debit']), 'credit' => pl_amount($line['credit']), 'description' => $event['description'] ?? $event['id']];
    }
    return $lines;
}

function pl_demo_operational_event_party(array $event, array $master, bool $vendor = false): int
{
    $pool = $master['parties'][$vendor ? 'vendor' : 'customer'];
    $id = $event['party_id'] ?? $event['contact_id'] ?? null;
    return (int) ($pool[$id] ?? reset($pool));
}

function pl_demo_operational_amount(array $event, array $master): string
{
    if (is_string($event['amount'] ?? null)) { return pl_amount($event['amount']); }
    $total = '0.0000';
    foreach ($event['expected_journal'] as $line) {
        $role = pl_demo_operational_role($line, $master['key_roles']);
        $lineTotal = bcadd(pl_amount($line['debit']), pl_amount($line['credit']), 4);
        if (in_array($role, ['accounts_receivable', 'accounts_payable', 'cash_on_hand', 'bank_current', 'inventory'], true)) {
            if ($role === 'accounts_receivable' || $role === 'accounts_payable') { return $lineTotal; }
            $total = bcadd($total, $lineTotal, 4);
        }
    }
    foreach ($event['expected_journal'] as $line) { $total = bcadd($total, pl_amount($line['credit']), 4); }
    return $total;
}

/** Normalize calculated adapter values without introducing binary floating point. */
function pl_demo_operational_money(string $value): string
{
    return pl_amount(bcadd($value, '0.00005', 4));
}

function pl_demo_operational_document_lines(array $event, array $master, array $evidence, bool $credit = false): array
{
    $stock = [];
    foreach (($event['stock_movements'] ?? []) as $movement) {
        if (!is_array($movement) || !isset($master['products'][$movement['item_id']])) { continue; }
        $rawQuantity = (string) $movement['quantity_delta'];
        if (str_starts_with($rawQuantity, '-')) { $rawQuantity = substr($rawQuantity, 1); }
        $quantity = pl_amount($rawQuantity);
        if (bccomp($quantity, '0', 4) > 0) { $stock[] = [$movement['item_id'], $quantity]; }
    }
    $productById = []; foreach (($evidence['products'] ?? []) as $product) { if (is_array($product) && isset($product['id'])) { $productById[$product['id']] = $product; } }
    $productIncome = static function (string $sourceId) use (&$productById, $master): string {
        $product = $productById[$sourceId] ?? null;
        if (is_array($product) && isset($product['income_account_key']) && isset($master['key_roles'][$product['income_account_key']])) { return $master['key_roles'][$product['income_account_key']]; }
        if (is_array($product) && ($product['kind'] ?? '') !== 'stock' && isset($master['accounts']['service_revenue'])) { return 'service_revenue'; }
        return $master['first_income'];
    };
    if ($stock !== []) {
        $total = pl_demo_operational_amount($event, $master); $lines = []; $stockTotal = '0.0000';
        foreach ($stock as [$sourceId, $quantity]) {
            $product = $productById[$sourceId] ?? []; $unit = pl_amount((string) ($product['sale_price'] ?? '0.0000')); $value = pl_demo_operational_money(bcmul($quantity, $unit, 8));
            $lines[] = ['product_id' => $master['products'][$sourceId], 'account_id' => $master['accounts'][$productIncome($sourceId)], 'description' => $product['name'] ?? $sourceId, 'quantity' => $quantity, 'unit_price' => $unit];
            $stockTotal = bcadd($stockTotal, $value, 4);
        }
        $remaining = bcsub($total, $stockTotal, 4);
        if (bccomp($remaining, '0.0000', 4) < 0) {
            $last = count($lines) - 1; $lines[$last]['unit_price'] = pl_demo_operational_money(bcdiv(bcadd(bcmul($lines[$last]['quantity'], $lines[$last]['unit_price'], 8), $remaining, 8), $lines[$last]['quantity'], 12)); $remaining = '0.0000';
        }
        if (bccomp($remaining, '0.0000', 4) > 0) {
            $serviceId = null; foreach ($productById as $sourceId => $product) { if (($product['kind'] ?? '') !== 'stock' && isset($master['products'][$sourceId])) { $serviceId = $sourceId; break; } }
            if ($serviceId === null) { throw new RuntimeException('The operational sale has revenue not represented by its stock movements.'); }
            $lines[] = ['product_id' => $master['products'][$serviceId], 'account_id' => $master['accounts'][$productIncome($serviceId)], 'description' => $productById[$serviceId]['name'] ?? $serviceId, 'quantity' => '1.0000', 'unit_price' => $remaining];
        }
        return $lines;
    }
    $productId = null;
    foreach (($evidence['products'] ?? []) as $candidate) {
        if (($candidate['kind'] ?? '') !== 'stock' && isset($master['products'][$candidate['id']])) { $productId = $master['products'][$candidate['id']]; break; }
    }
    $productId ??= reset($master['products']);
    if (!is_int($productId)) { throw new RuntimeException('The selected sample has no product for its operational document.'); }
    $product = null; foreach (($evidence['products'] ?? []) as $candidate) { if (($master['products'][$candidate['id']] ?? null) === $productId) { $product = $candidate; break; } }
    $income = $product !== null && isset($product['id']) ? $productIncome((string) $product['id']) : $master['first_income'];
    return [['product_id' => $productId, 'account_id' => $master['accounts'][$income], 'description' => $event['description'] ?? $event['id'], 'quantity' => '1.0000', 'unit_price' => pl_demo_operational_amount($event, $master), 'original_line_number' => $credit ? 1 : null]];
}

function pl_demo_operational_stock_available(int $actorId, int $companyId, int $bookId, array $event, array $master): bool
{
    foreach (($event['stock_movements'] ?? []) as $movement) {
        if (!is_array($movement) || !isset($master['products'][$movement['item_id']])) { continue; }
        $rawQuantity = (string) ($movement['quantity_delta'] ?? '0.0000');
        if (!str_starts_with($rawQuantity, '-')) { continue; }
        $required = pl_amount(substr($rawQuantity, 1));
        $balance = pl_inventory_balance($actorId, $companyId, $bookId, $master['products'][$movement['item_id']], (string) $event['date']);
        if (bccomp($balance['quantity'], $required, 4) < 0) { return false; }
    }
    return true;
}

/** Compare every replayed event's complete journal footprint with its source contract. */
function pl_demo_operational_reconcile(int $companyId, int $bookId, array $events, array $master, array $receipts): int
{
    $checked = 0;
    foreach ($receipts as $receipt) {
        if (($receipt['status'] ?? '') !== 'replayed') { continue; }
        $event = null; foreach ($events as $candidate) { if ($candidate['id'] === $receipt['event_id']) { $event = $candidate; break; } }
        if ($event === null) { throw new RuntimeException('An operational receipt has no source event.'); }
        $expected = [];
        foreach ($event['expected_journal'] as $line) {
            $role = pl_demo_operational_role($line, $master['key_roles']); $expected[$role] ??= ['debit' => '0.0000', 'credit' => '0.0000'];
            $expected[$role]['debit'] = bcadd($expected[$role]['debit'], pl_amount($line['debit']), 4); $expected[$role]['credit'] = bcadd($expected[$role]['credit'], pl_amount($line['credit']), 4);
        }
        $actual = [];
        foreach (array_values(array_unique(array_map('intval', $receipt['journal_ids'] ?? []))) as $journalId) {
            foreach (DB::query('SELECT account_id,debit,credit FROM pl_journal_lines WHERE company_id=%i AND book_id=%i AND journal_id=%i FOR SHARE', $companyId, $bookId, $journalId) as $line) {
                $actual[(int) $line['account_id']] ??= ['debit' => '0.0000', 'credit' => '0.0000'];
                $actual[(int) $line['account_id']]['debit'] = bcadd($actual[(int) $line['account_id']]['debit'], pl_amount($line['debit']), 4);
                $actual[(int) $line['account_id']]['credit'] = bcadd($actual[(int) $line['account_id']]['credit'], pl_amount($line['credit']), 4);
            }
        }
        foreach ($expected as $role => $totals) {
            $accountId = $master['accounts'][$role] ?? null;
            $got = $accountId === null ? ['debit' => '0.0000', 'credit' => '0.0000'] : ($actual[$accountId] ?? ['debit' => '0.0000', 'credit' => '0.0000']);
            if ($got !== $totals) { throw new RuntimeException('Operational event ' . $event['id'] . ' did not reconcile to its pinned journal contract for ' . $role . ' (expected ' . json_encode($totals) . ', got ' . json_encode($got) . ').'); }
        }
        $expectedIds = array_values(array_filter(array_map(static fn(string $role): ?int => isset($master['accounts'][$role]) ? (int) $master['accounts'][$role] : null, array_keys($expected))));
        $unexpectedDebit = '0.0000'; $unexpectedCredit = '0.0000';
        foreach ($actual as $accountId => $totals) {
            // Composite services may add a paired internal clearing or stock
            // basis entry that the source contract presents as one operation.
            // Permit those accounts only when their aggregate is balanced.
            if (!in_array((int) $accountId, $expectedIds, true)) {
                $unexpectedDebit = bcadd($unexpectedDebit, $totals['debit'], 4);
                $unexpectedCredit = bcadd($unexpectedCredit, $totals['credit'], 4);
            }
        }
        if ($unexpectedDebit !== $unexpectedCredit) { throw new RuntimeException('Operational event ' . $event['id'] . ' posted to an account outside its pinned journal contract.'); }
        $checked++;
    }
    return $checked;
}

/**
 * Replay the supported portion of the operational contract through AR/AP,
 * Purchasing, Inventory and the general-journal service. Unsupported vertical
 * operations are retained in the installation receipt with an explicit reason.
 */
function pl_demo_operational_replay(int $actorId, int $companyId, int $bookId, array $pack, string $prefix): array
{
    $events = pl_demo_operational_contract($pack);
    $evidence = $pack['source_material']['research_evidence'];
    $master = pl_demo_operational_master_data($actorId, $companyId, $bookId, $pack, $events, $prefix);
    usort($events, static fn(array $a, array $b): int => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);
    $documentIds = []; $invoiceIds = []; $billIds = []; $genericIds = []; $receipts = []; $staged = [];
    $bank = $master['accounts']['bank_current'] ?? ($master['accounts']['cash_on_hand'] ?? null);
    foreach ($events as $event) {
        $kind = (string) $event['kind']; $receipt = ['event_id' => $event['id'], 'kind' => $kind, 'source_reference' => $event['source_reference'] ?? $event['id'], 'status' => 'replayed', 'journal_ids' => [], 'document_ids' => [], 'movement_ids' => []];
        $key = $prefix . 'operational-event:' . $event['id'];
        if (in_array($kind, ['invoice', 'credit_sale'], true)) {
            if (!pl_demo_operational_stock_available($actorId, $companyId, $bookId, $event, $master)) {
                $staged[] = ['event_id' => $event['id'], 'kind' => $kind, 'reason' => 'The contract sale needs opening stock that is retained as evidence rather than posted by the sample importer.']; $receipt['status'] = 'staged'; $receipts[] = $receipt; continue;
            }
            $party = pl_demo_operational_event_party($event, $master);
            $lines = pl_demo_operational_document_lines($event, $master, $evidence);
            $document = pl_save_ar_document($actorId, $companyId, $bookId, ['kind' => 'invoice', 'date' => $event['date'], 'due_date' => pl_demo_operational_due_date($event['date']),
                'currency' => pl_company_context($actorId, $companyId)['currency'], 'party_id' => $party, 'reference' => $event['source_reference'] ?? $event['id'],
                'notes' => 'Replayed from the pinned sample operational contract.', 'creation_key' => $key . ':document', 'price_mode' => 'exclusive', 'lines' => $lines]);
            $posted = pl_post_ar_document($actorId, $companyId, $bookId, (int) $document['id'], (int) $document['revision']);
            $documentIds[$event['id']] = (int) $posted['id']; if (isset($event['document_id'])) { $documentIds[$event['document_id']] = (int) $posted['id']; }
            $invoiceIds[] = (int) $posted['id']; $receipt['document_ids'][] = (int) $posted['id']; $receipt['journal_ids'][] = (int) $posted['journal_id'];
            foreach (DB::query('SELECT id,journal_id FROM pl_inventory_movements WHERE company_id=%i AND book_id=%i AND source_document_id=%i AND journal_id IS NOT NULL FOR SHARE', $companyId, $bookId, (int) $posted['id']) as $movement) { $receipt['movement_ids'][] = (int) $movement['id']; $receipt['journal_ids'][] = (int) $movement['journal_id']; }
        } elseif (in_array($kind, ['customer_credit', 'sales_return'], true)) {
            $party = pl_demo_operational_event_party($event, $master);
            $originalId = null;
            if (isset($event['original_event_id'], $documentIds[$event['original_event_id']])) { $originalId = $documentIds[$event['original_event_id']]; }
            if ($originalId === null && isset($event['related_document_id'], $documentIds[$event['related_document_id']])) { $originalId = $documentIds[$event['related_document_id']]; }
            if ($originalId === null) { for ($index = count($invoiceIds) - 1; $index >= 0; $index--) { $candidate = pl_get_ar_document($actorId, $companyId, $bookId, $invoiceIds[$index]); if ((int) $candidate['party_id'] === $party && bccomp($candidate['outstanding_fc'], pl_demo_operational_amount($event, $master), 4) >= 0) { $originalId = (int) $candidate['id']; break; } } }
            if ($originalId === null) {
                $staged[] = ['event_id' => $event['id'], 'kind' => $kind, 'reason' => 'The linked invoice was not replayed because its opening stock is retained as evidence rather than posted by the sample importer.']; $receipt['status'] = 'staged'; $receipts[] = $receipt; continue;
            }
            $lines = pl_demo_operational_document_lines($event, $master, $evidence, true); $original = pl_get_ar_document($actorId, $companyId, $bookId, $originalId);
            foreach ($lines as &$line) { if ($line['product_id'] !== null) { foreach ($original['lines'] as $originalLine) { if ((int) ($originalLine['product_id'] ?? 0) === (int) $line['product_id']) { $line['original_line_number'] = (int) $originalLine['line_number']; break; } } } } unset($line);
            $document = pl_save_ar_document($actorId, $companyId, $bookId, ['kind' => 'customer_credit', 'date' => $event['date'], 'due_date' => $event['date'],
                'currency' => pl_company_context($actorId, $companyId)['currency'], 'party_id' => $party, 'original_document_id' => $originalId,
                'reference' => $event['source_reference'] ?? $event['id'], 'notes' => 'Replayed sample customer return/credit.', 'creation_key' => $key . ':document', 'price_mode' => 'exclusive', 'lines' => $lines]);
            $posted = pl_post_ar_document($actorId, $companyId, $bookId, (int) $document['id'], (int) $document['revision']);
            $documentIds[$event['id']] = (int) $posted['id']; if (isset($event['document_id'])) { $documentIds[$event['document_id']] = (int) $posted['id']; }
            $receipt['document_ids'][] = (int) $posted['id']; $receipt['journal_ids'][] = (int) $posted['journal_id'];
            foreach (DB::query('SELECT id,journal_id FROM pl_inventory_movements WHERE company_id=%i AND book_id=%i AND source_document_id=%i AND journal_id IS NOT NULL FOR SHARE', $companyId, $bookId, (int) $posted['id']) as $movement) { $receipt['movement_ids'][] = (int) $movement['id']; $receipt['journal_ids'][] = (int) $movement['journal_id']; }
        } elseif ($kind === 'bill' || $kind === 'expense_bill') {
            $party = pl_demo_operational_event_party($event, $master, true); $expense = $master['first_expense'];
            foreach ($event['expected_journal'] as $line) { $role = pl_demo_operational_role($line, $master['key_roles']); if (str_contains($role, 'expense') || str_contains($role, 'cost')) { $expense = $role; break; } }
            $document = pl_save_ar_document($actorId, $companyId, $bookId, ['kind' => 'bill', 'date' => $event['date'], 'due_date' => pl_demo_operational_due_date($event['date']),
                'currency' => pl_company_context($actorId, $companyId)['currency'], 'party_id' => $party, 'reference' => $event['source_reference'] ?? $event['id'],
                'notes' => 'Replayed from the pinned sample operational contract.', 'creation_key' => $key . ':document', 'price_mode' => 'exclusive',
                'lines' => [['account_id' => $master['accounts'][$expense], 'description' => $event['description'] ?? $event['id'], 'quantity' => '1.0000', 'unit_price' => pl_demo_operational_amount($event, $master)]]]);
            $posted = pl_post_ar_document($actorId, $companyId, $bookId, (int) $document['id'], (int) $document['revision']);
            $documentIds[$event['id']] = (int) $posted['id']; if (isset($event['document_id'])) { $documentIds[$event['document_id']] = (int) $posted['id']; }
            $billIds[] = (int) $posted['id']; $receipt['document_ids'][] = (int) $posted['id']; $receipt['journal_ids'][] = (int) $posted['journal_id'];
        } elseif ($kind === 'purchase') {
            $party = pl_demo_operational_event_party($event, $master, true); $orderLines = []; $sourceMovements = $event['stock_movements'] ?? [];
            if ($sourceMovements === []) { $sourceMovements = [['item_id' => array_key_first($master['products']), 'quantity_delta' => '1.0000', 'unit_cost' => pl_demo_operational_amount($event, $master)]]; }
            foreach ($sourceMovements as $movement) { $quantity = pl_amount((string) $movement['quantity_delta']); if (bccomp($quantity, '0', 4) < 0) { continue; } $orderLines[] = ['product_id' => $master['products'][$movement['item_id']], 'description' => $movement['item_id'], 'quantity' => $quantity, 'unit_price' => pl_amount((string) $movement['unit_cost'])]; }
            if ($orderLines === []) { throw new DomainException('The operational purchase has no positive stock receipt.'); }
            $order = pl_save_purchase_order($actorId, $companyId, $bookId, ['party_id' => $party, 'date' => $event['date'], 'currency' => pl_company_context($actorId, $companyId)['currency'], 'reference' => $event['source_reference'] ?? $event['id'], 'creation_key' => $key . ':order', 'lines' => $orderLines]);
            $confirmed = pl_confirm_purchase_order($actorId, $companyId, $bookId, (int) $order['id'], (int) $order['revision'], $key . ':confirm');
            $received = pl_receive_purchase_order($actorId, $companyId, $bookId, (int) $confirmed['id'], ['date' => $event['date'], 'grni_account_id' => $master['accounts']['grni'] ?? $master['accounts']['goods_received'], 'idempotency_key' => $key . ':receive', 'lines' => array_map(static fn(array $line): array => ['order_line_id' => (int) $line['id'], 'quantity' => $line['quantity']], $confirmed['lines'])]);
            $billInput = ['party_id' => $party, 'grni_account_id' => $master['accounts']['grni'] ?? $master['accounts']['goods_received'], 'date' => $event['date'], 'due_date' => pl_demo_operational_due_date($event['date']), 'currency' => pl_company_context($actorId, $companyId)['currency'], 'price_mode' => 'exclusive', 'variance_confirmed' => false, 'idempotency_key' => $key . ':bill', 'lines' => []];
            foreach ($received['lines'] as $index => $line) { $billInput['lines'][] = ['receipt_line_id' => (int) $line['id'], 'quantity' => $line['quantity'], 'unit_price' => $orderLines[$index]['unit_price']]; }
            $billed = pl_bill_purchase_receipts($actorId, $companyId, $bookId, $billInput); $billId = (int) $billed['bill_document_id']; $documentIds[$event['id']] = $billId; if (isset($event['document_id'])) { $documentIds[$event['document_id']] = $billId; }
            $billIds[] = $billId; $posted = pl_get_ar_document($actorId, $companyId, $bookId, $billId); $receipt['document_ids'][] = $billId; $receipt['journal_ids'][] = (int) $posted['journal_id'];
            foreach ($received['lines'] as $line) { $receipt['movement_ids'][] = (int) $line['movement_id']; if ($line['journal_id'] !== null) { $receipt['journal_ids'][] = (int) $line['journal_id']; } }
        } elseif ($kind === 'receipt' || $kind === 'supplier_payment') {
            $vendor = $kind === 'supplier_payment'; $party = pl_demo_operational_event_party($event, $master, $vendor); $ids = $vendor ? $billIds : $invoiceIds; $target = null; $amount = pl_demo_operational_amount($event, $master);
            for ($index = count($ids) - 1; $index >= 0; $index--) { $candidate = pl_get_ar_document($actorId, $companyId, $bookId, $ids[$index]); if ((int) $candidate['party_id'] === $party && bccomp($candidate['outstanding_fc'], $amount, 4) >= 0) { $target = $candidate; break; } }
            if ($target === null || $bank === null) {
                $reason = $target === null ? 'The contract settlement refers to opening-detail evidence that is intentionally not posted by the sample importer.' : 'The contract settlement has no safe cash/bank account mapping.';
                $staged[] = ['event_id' => $event['id'], 'kind' => $kind, 'reason' => $reason]; $receipt['status'] = 'staged'; $receipts[] = $receipt; continue;
            }
            $settled = pl_settle_ar_document($actorId, $companyId, $bookId, (int) $target['id'], ['bank_account_id' => $bank, 'amount_fc' => $amount, 'date' => $event['date'], 'description' => $event['description'] ?? $event['id'], 'idempotency_key' => $key . ':settlement']);
            $receipt['journal_ids'][] = (int) $settled['journal_id'];
        } elseif ($kind === 'cash_sale') {
            $lines = pl_demo_operational_event_lines($event, $master); $financial = [];
            foreach ($lines as $line) { $role = null; foreach ($event['expected_journal'] as $source) { $candidate = pl_demo_operational_role($source, $master['key_roles']); if ($master['accounts'][$candidate] === $line['account_id']) { $role = $candidate; break; } } if ($role !== null && !str_contains($role, 'inventory') && $role !== 'cost_of_goods_sold' && !str_contains($role, 'cost_')) { $financial[] = $line; } }
            $draft = pl_save_general_draft($actorId, $companyId, $bookId, ['date' => $event['date'], 'reference' => $event['source_reference'] ?? $event['id'], 'description' => $event['description'] ?? $event['id'], 'creation_key' => $key . ':cash-sale', 'lines' => $financial]);
            $posted = pl_post_general_draft($actorId, $companyId, $bookId, (int) $draft['id'], (int) $draft['revision']); $genericIds[$event['id']] = (int) $draft['id']; $receipt['journal_ids'][] = (int) $posted['journal_id'];
            foreach (($event['stock_movements'] ?? []) as $index => $movement) { if (bccomp((string) $movement['quantity_delta'], '0', 4) < 0) { $stock = pl_inventory_issue($actorId, $companyId, $bookId, ['product_id' => $master['products'][$movement['item_id']], 'quantity' => ltrim((string) $movement['quantity_delta'], '-'), 'date' => $event['date'], 'source_type' => 'sample_cash_sale', 'source_reference' => $event['id'] . ':' . $index, 'source_journal_id' => (int) $posted['journal_id'], 'reason' => 'Stock issued for sample cash sale.', 'idempotency_key' => $key . ':stock:' . $index]); $receipt['movement_ids'][] = (int) $stock['movement_id']; $receipt['journal_ids'][] = (int) $stock['journal_id']; } }
        } elseif ($kind === 'reversal') {
            $targetId = $event['reverses_event_id'] ?? null; if (!is_string($targetId) || !isset($genericIds[$targetId])) { $staged[] = ['event_id' => $event['id'], 'kind' => $kind, 'reason' => 'The source event is not a replayable general-journal correction.']; $receipt['status'] = 'staged'; $receipts[] = $receipt; continue; }
            $reversalDate = $event['date'];
            if ($reversalDate < gmdate('Y-m-d')) {
                $reversalDate = (string) DB::queryFirstField('SELECT j.journal_date FROM pl_general_drafts d JOIN pl_journals j ON j.id = d.journal_id WHERE d.id=%i AND d.company_id=%i AND d.book_id=%i FOR SHARE', $genericIds[$targetId], $companyId, $bookId);
                $receipt['effective_date'] = $reversalDate;
            }
            $reversed = pl_reverse_general_draft($actorId, $companyId, $bookId, $genericIds[$targetId], $reversalDate, 'Sample operational correction linked to ' . $targetId); $receipt['journal_ids'][] = (int) $reversed['reversal_journal_id'];
        } elseif (in_array($kind, ['expense', 'expense_bill', 'cash_transfer'], true)) {
            $lines = pl_demo_operational_event_lines($event, $master); $draft = pl_save_general_draft($actorId, $companyId, $bookId, ['date' => $event['date'], 'reference' => $event['source_reference'] ?? $event['id'], 'description' => $event['description'] ?? $event['id'], 'creation_key' => $key . ':journal', 'lines' => $lines]);
            $posted = pl_post_general_draft($actorId, $companyId, $bookId, (int) $draft['id'], (int) $draft['revision']); $genericIds[$event['id']] = (int) $draft['id']; $receipt['journal_ids'][] = (int) $posted['journal_id'];
        } else {
            $reason = match ($kind) { 'stock_transfer' => 'Location transfers are retained as evidence; the current inventory service is one-location.', 'stock_count' => 'The contract supplies no reviewed expected/count pair for the current count service.', 'prepayment', 'revenue_recognition' => 'Advanced vertical revenue timing is a separate capability.', default => 'The event kind is outside the current supported sample replay contract.' };
            $staged[] = ['event_id' => $event['id'], 'kind' => $kind, 'reason' => $reason]; $receipt['status'] = 'staged';
        }
        $receipts[] = $receipt;
    }
    $reconciledCount = pl_demo_operational_reconcile($companyId, $bookId, $events, $master, $receipts);
    return ['status' => $staged === [] ? 'runtime_replayed' : 'runtime_replayed_with_staged_vertical_evidence', 'contract_digest' => hash('sha256', json_encode($events, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)), 'event_count' => count($events), 'replayed_count' => count(array_filter($receipts, static fn(array $row): bool => $row['status'] === 'replayed')), 'reconciled_count' => $reconciledCount, 'staged_count' => count($staged), 'opening_evidence' => 'retained_only_not_posted', 'receipts' => $receipts, 'staged' => $staged, 'account_ids' => $master['accounts'], 'party_ids' => $master['parties'], 'product_ids' => $master['products']];
}

/** New isolated company only; every source uses existing account/document/journal/period services. */
function pl_seed_demo_pack(int $actorId, int $companyId, int $bookId, string $id): void
{
    pl_demo_require_setup_action();
    $pack = pl_demo_pack($id);
    pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $pack): void {
        pl_require_company_access($actorId, $companyId, true);
        pl_ledger_book($companyId, $bookId, true);
        $company = pl_company_context($actorId, $companyId);
        if (!$company['is_sample'] || $company['setup_status'] !== 'ready' || $company['start_date'] !== $pack['start_date']
            || $company['fiscal_year_end'] !== '12-31'
            || DB::queryFirstField('SELECT id FROM pl_journals WHERE book_id = %i LIMIT 1', $bookId)
            || DB::queryFirstField('SELECT id FROM pl_documents WHERE book_id = %i LIMIT 1', $bookId)
            || DB::queryFirstField('SELECT id FROM pl_general_drafts WHERE book_id = %i LIMIT 1', $bookId)
            || DB::queryFirstField('SELECT user_id FROM pl_demo_visitors WHERE company_id = %i LIMIT 1', $companyId)) {
            throw new DomainException('A historical sample requires a new, empty, unassigned sample company starting in 2024. Existing books cannot be replaced.');
        }
        if (pl_demo_enabled() && min(500, max(10, (int) (getenv('PL_DEMO_MAX_DOCUMENTS') ?: 100))) < $pack['source_count'] + 20) {
            throw new PlDemoUnavailable('The sample needs room for its history and at least twenty practice records. Ask the demo operator to check its capacity setting.');
        }
        $prefix = 'sample:' . $pack['id'] . ':' . $pack['version'] . ':';
        $mapping = pl_account_code_mapping($company['accounts']);
        foreach ($pack['accounts'] as $definition) {
            $account = pl_save_account($actorId, $companyId, $bookId, $definition + [
                'is_active' => true, 'reason' => 'Original sample chart and manual support schedules.',
                'creation_key' => $prefix . 'account:' . $definition['code'],
            ]);
            $mapping[$definition['code']] = $account['id'];
        }
        // B64: a printed invoice, receipt or statement carries the seller's own block, and
        // an empty profile prints the bare company name. Every sample fills it in.
        pl_save_company_profile($actorId, $companyId, $pack['company_profile'] + [
            'revision' => pl_company_profile($actorId, $companyId)['revision'],
            'reason' => 'Sample company profile so printed documents carry a complete, entirely fictional seller block.',
            'idempotency_key' => $prefix . 'company-profile',
        ]);
        // B61: where the sample is a partnership, each partner keeps their own capital and
        // drawings account and the recorded profit share, and their movements say whose they are.
        $partnerIds = [];
        foreach ($pack['partners'] as $partner) {
            $saved = pl_save_owner_partner($actorId, $companyId, $bookId, [
                'name' => $partner['name'], 'profit_share' => $partner['profit_share'], 'is_active' => true,
                'capital_account_id' => (int) $mapping[$partner['capital_code']],
                'drawings_account_id' => (int) $mapping[$partner['drawings_code']],
            ]);
            $partnerIds[$partner['key']] = (int) $saved['id'];
        }
        for ($month = 1; $month <= 12; $month++) {
            $start = sprintf('2025-%02d-01', $month);
            pl_create_period($actorId, $companyId, $bookId, ['start_date' => $start,
                'end_date' => (new DateTimeImmutable($start))->format('Y-m-t'),
                'reason' => 'Historical sample month, prepared before visitor assignment.', 'request_key' => $prefix . 'period:' . $month]);
        }
        pl_create_period($actorId, $companyId, $bookId, ['start_date' => '2026-01-01', 'end_date' => '2026-12-31',
            'reason' => 'Open practice year for sample visitor actions.', 'request_key' => $prefix . 'practice']);
        foreach ($pack['events'] as $event) {
            if ($event['kind'] === 'owner_transaction') {
                // Capital introduced, the owner's loan, its repayment and drawings go through the
                // owner-transactions service so every sample shows them where the owner looks (B61).
                pl_post_owner_transaction($actorId, $companyId, $bookId, [
                    'kind' => $event['owner_kind'], 'date' => $event['date'], 'amount' => $event['amount'],
                    'cash_account_id' => $mapping[$event['money_code']], 'owner_account_id' => $mapping[$event['owner_code']],
                    'description' => $event['description'], 'creation_key' => $prefix . $event['key'],
                ] + (isset($event['partner_key']) ? ['partner_id' => $partnerIds[$event['partner_key']]] : []));
            } elseif ($event['kind'] === 'receipt') {
                $source = pl_save_document($actorId, $companyId, $bookId, [
                    'kind' => 'receipt', 'date' => $event['date'], 'amount' => $event['amount'],
                    'money_account_id' => $mapping[$event['money_code']], 'category_account_id' => $mapping[$event['category_code']],
                    'counterparty' => $event['counterparty'], 'reference' => $event['reference'], 'memo' => $event['description'],
                    'creation_key' => $prefix . $event['key'],
                ]);
                pl_post_document($actorId, $companyId, $bookId, $source['id'], $source['revision']);
            } else {
                $lines = array_map(static fn (array $row): array => ['account_id' => $mapping[$row['code']],
                    'debit' => $row['debit'], 'credit' => $row['credit'], 'description' => $row['description']], $event['lines']);
                $source = pl_save_general_draft($actorId, $companyId, $bookId, ['date' => $event['date'],
                    'reference' => $event['reference'], 'description' => $event['description'], 'lines' => $lines,
                    'creation_key' => $prefix . $event['key']]);
                pl_post_general_draft($actorId, $companyId, $bookId, $source['id'], $source['revision']);
                if ($event['reverse']) { pl_reverse_general_draft($actorId, $companyId, $bookId, $source['id'], $event['date'], 'Original sample correction: reverse wrong-cost; replacement is correct-cost on 2025-08-21.'); }
            }
        }
        foreach ($pack['drafts'] as $event) {
            pl_save_document($actorId, $companyId, $bookId, $event + ['money_account_id' => $mapping[$event['money_code'] ?? '1000'],
                'category_account_id' => $mapping[$event['kind'] === 'receipt' ? '4000' : '5000'], 'creation_key' => $prefix . $event['key']]);
        }
        pl_demo_pack_reconcile($actorId, $companyId, $bookId, $pack);
        $operationalReplay = pl_demo_operational_replay($actorId, $companyId, $bookId, $pack, $prefix);
        foreach (pl_list_periods($actorId, $companyId, $bookId) as $period) {
            if ($period['end_date'] <= $pack['history_end']) {
                pl_change_period_status($actorId, $companyId, $bookId, (int) $period['id'], 'closed', (int) $period['revision'],
                    'Sample history reconciled to pinned monthly checkpoints. Closure prevents backdated posting; it does not approve statutory statements.', $prefix . 'close:' . $period['start_date']);
            }
        }
        $snapshot = json_encode(['sample_pack' => ['id' => $pack['id'], 'version' => $pack['version'], 'digest' => $pack['digest'],
            'date' => $pack['start_date'], 'currency' => $company['currency'], 'checkpoints' => 36,
            'operational_replay' => $operationalReplay]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        pl_record_installation_history($actorId, $companyId, $bookId, 'sample', pl_starter_template(), $snapshot);
        $manifest = pl_module_registry()['pos-showcase'];
        pl_set_company_module($actorId, $companyId, 'pos-showcase', true, 0, $manifest['digest'], 'Explicit isolated sample includes the cash POS showcase; it does not deduct stock.', 'sample-pos');
    });
}

/** Resolve a guide only from this authorized company's pinned installation snapshot. */
function pl_company_demo_pack(int $actorId, int $companyId, int $bookId): ?array
{
    pl_require_company_access($actorId, $companyId);
    pl_ledger_book($companyId, $bookId);
    $raw = DB::queryFirstField('SELECT h.snapshot FROM pl_template_installation_history h JOIN pl_companies c ON c.id = h.company_id WHERE h.company_id = %i AND h.book_id = %i AND h.snapshot_kind = %s AND c.is_sample = 1 ORDER BY h.id DESC LIMIT 1', $companyId, $bookId, 'sample');
    if (!$raw) { return null; }
    $snapshot = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR)['sample_pack'] ?? null;
    if (!is_array($snapshot)) { return null; }
    if (($snapshot['id'] ?? '') === 'accounting-starter') {
        $pack = pl_demo_starter_playground($snapshot['date']);
    } else {
        if (!isset(pl_demo_pack_catalog()[$snapshot['id'] ?? ''])) { return null; }
        $pack = pl_demo_pack($snapshot['id']);
    }
    if ($snapshot['version'] !== $pack['version'] || !hash_equals($pack['digest'], $snapshot['digest'])) {
        throw new DomainException('This sample guide has changed since your company was created. Start a fresh sample to use the current guide.');
    }
    return $pack;
}
