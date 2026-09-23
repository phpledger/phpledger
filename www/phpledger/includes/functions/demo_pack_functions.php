<?php
declare(strict_types=1);

/** Classify explicitly authored sample money accounts, never a name/code heuristic. */
function pl_demo_classify_money_accounts(int $actorId, int $companyId, int $bookId, array $mapping, array $kinds): void
{
    foreach ($kinds as $code => $kind) {
        if (!isset($mapping[$code]) || !in_array($kind, ['physical', 'bank'], true)) { throw new DomainException('The sample has an invalid money-account classification.'); }
        $account = pl_get_account($actorId, $companyId, $bookId, (int) $mapping[$code]);
        if (($account['money_kind'] ?? null) === $kind) { continue; }
        pl_save_account($actorId, $companyId, $bookId, array_replace($account, [
            'money_kind'=>$kind, 'is_active'=>true, 'reason'=>'Use the explicit bank or physical-cash classification authored in the sample contract.',
        ]), (int) $account['id'], (int) $account['revision']);
    }
}

/** Only these original bundled pack identities are selectable, never a request path. */
function pl_demo_pack_catalog(): array
{
    $installed = [];
    foreach (pl_sample_installed_packages() as $package) { $entry=$package['manifest']['sample']; $entry['package_path']=$package['path']; $entry['package_slug']=$package['slug']; $installed[$entry['id']]=$entry; }
    if (!pl_sample_repository_fallback()) { return $installed; }
    $catalogPath=pl_sample_repository_directory('demo-packs').'/catalog.json';
    if (!is_file($catalogPath)) { return $installed; }
    $catalog = json_decode((string) file_get_contents($catalogPath), true, 32, JSON_THROW_ON_ERROR);
    $allowed = ['service-agency', 'retail-shop', 'seasonal-business', 'distributor', 'trader', 'restaurant', 'membership-club', 'pharmacy', 'jewelry-studio', 'light-manufacturing', 'service-workshop'];
    $result = [];
    foreach ($catalog as $pack) {
        if (!in_array($pack['id'], $allowed, true)
            || !in_array($pack['version'], ['1.0.0', '1.1.0'], true) || $pack['file'] !== $pack['id'] . '-' . $pack['version'] . '.json'
            || !in_array($pack['status'], ['released_demo_only', 'preview_only'], true)
            || !is_string($pack['capability_note']) || $pack['capability_note'] === ''
            || !preg_match('/^[a-f0-9]{64}$/D', $pack['sha256'])) {
            throw new RuntimeException('The bundled sample catalog is invalid.');
        }
        $result[$pack['id']] = $pack;
    }
    if (count($result) !== count($allowed) || array_diff($allowed, array_keys($result)) !== []) { throw new RuntimeException('The bundled sample catalog is incomplete.'); }
    return $installed + $result;
}

function pl_demo_pack(string $id): array
{
    $entry = pl_demo_pack_catalog()[$id] ?? null;
    if ($entry === null) { throw new DomainException('Choose an installed sample company.'); }
    $raw = file_get_contents(isset($entry['package_path']) ? $entry['package_path'].'/pack.json' : pl_sample_repository_directory('demo-packs').'/'.$entry['file']);
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
    $pack['version'] = '1.1.0';
    $pack['account_code_format'] = 'structured';
    $pack['money_account_kinds'] = ['1-100-10001-00' => 'bank'];
    $pack['code_aliases'] = ['1300'=>'1-130-21300-00','1350'=>'1-110-21350-00','2100'=>'2-100-22100-00',
        '2150'=>'2-100-22150-00','5100'=>'5-200-25100-00','5200'=>'5-100-25200-00'];
    $pack['account_headings'] = [['code'=>'1-130-00000-00','name'=>'Inventory','type'=>'asset'],
        ['code'=>'5-200-00000-00','name'=>'Cost of Sales','type'=>'expense']];
    foreach ($pack['accounts'] as &$account) {
        $oldCode = $account['code']; $account['code'] = $pack['code_aliases'][$oldCode];
        if ($oldCode === '5100') { $account['report_classification'] = 'cost_of_sales'; }
    } unset($account);
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
        foreach (array_merge($pack['account_headings'] ?? [], $pack['accounts']) as $definition) {
            $account = pl_save_account($actorId, $companyId, $bookId, $definition + [
                'is_active' => true, 'reason' => $reason, 'creation_key' => 'starter-account:' . $definition['code'],
            ]);
            $mapping[$definition['code']] = $account['id'];
        }
        foreach (($pack['code_aliases'] ?? []) as $old => $code) { $mapping[$old] = $mapping[$code]; }
        pl_demo_classify_money_accounts($actorId, $companyId, $bookId, $mapping, $pack['money_account_kinds']);
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
            // A class or group heading is a name on the chart, not a balance: it takes no posting,
            // so an authored checkpoint has nothing to say about it and an exact comparison must
            // not demand one. Every account that can carry a figure is still compared.
            if (in_array((string) ($row['level'] ?? 'account'), ['class', 'group'], true)) { continue; }
            $actual[(string) (($pack['account_code_format'] ?? '') !== 'structured' && ($row['legacy_code'] ?? '') !== '' ? $row['legacy_code'] : $row['code'])] = $row['balance'];
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
    if (in_array($role, ['cash_on_hand', 'bank_current', 'route_cash'], true)) { return ['asset', 'cash_bank']; }
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
        $legacyCode = (string) ($next[$type]++);
        $code = $legacyCode;
        if (($pack['account_code_format'] ?? '') === 'structured') {
            $group = $type === 'asset' ? (in_array($role, ['cash_on_hand', 'bank_current', 'route_cash'], true) ? 100 : (str_contains($role, 'inventory') ? 130 : 110)) : ($type === 'liability' && in_array($role, ['deferred_revenue', 'store_credit_liability'], true) ? 120 : ($role === 'cost_of_goods_sold' ? 200 : 100));
            $code = pl_account_code_format(['asset'=>1,'liability'=>2,'equity'=>3,'income'=>4,'expense'=>5][$type], $group, 40000 + (int) $legacyCode);
            $headingCode = substr($code, 0, 5) . '-00000-00';
            if (!DB::queryFirstField('SELECT id FROM pl_accounts WHERE company_id=%i AND book_id=%i AND code=%s', $companyId, $bookId, $headingCode)) {
                pl_save_account($actorId, $companyId, $bookId, ['code'=>$headingCode, 'name'=>$group === 130 ? 'Inventory' : 'Cost of Sales', 'type'=>$type, 'is_active'=>true, 'reason'=>'Explicit sample accounting heading.', 'creation_key'=>$prefix.'heading:'.$headingCode]);
            }
        }
        $accountLabel = is_string($roleLabels[$role] ?? null) && $roleLabels[$role] !== '' ? $roleLabels[$role] : pl_demo_operational_role_label($role);
        $classification = [];
        if ($role === 'cost_of_goods_sold') {
            // Preserve old sample-account retries; only new charts get this default.
            $prior = DB::queryFirstRow('SELECT report_classification FROM pl_accounts WHERE company_id=%i AND book_id=%i AND creation_key=%s', $companyId,$bookId,pl_request_key($prefix.'operational-account:'.$role));
            if ($prior === null || $prior['report_classification'] === 'cost_of_sales') { $classification = ['report_classification'=>'cost_of_sales']; }
        }
        $account = pl_save_account($actorId, $companyId, $bookId, ['code' => $code, 'name' => $accountLabel, 'type' => $type,
            'role' => $accountRole, 'money_kind' => ['cash_on_hand'=>'physical','route_cash'=>'physical','bank_current'=>'bank'][$role] ?? null, 'is_active' => true, 'reason' => 'Deterministic operational account for the isolated sample.',
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
        $validationIssue = $event['validation_issue'] ?? null;
        if (is_array($validationIssue)) {
            if ($validationIssue['code'] === 'bank_shortfall') {
                if ($bank === null) { throw new RuntimeException('The reviewed bank shortfall has no bank account.'); }
                $preview = pl_cash_payment_preview($actorId, $companyId, $bookId, (int) $bank, $event['date'], $validationIssue['required']);
                // The authored warning is checked against real posted records under the
                // seed's book lock. A changed scenario must be reviewed, never silently
                // assigned invented funding, a facility or an inaccurate warning.
                if (!$preview['insufficient_cash'] || $preview['balance'] !== $validationIssue['available']
                    || $preview['overdraft_limit'] !== $validationIssue['overdraft_limit']) {
                    throw new RuntimeException('The bank shortfall evidence needs review for ' . $event['id'] . ': posted balance ' . $preview['balance'] . ', authored balance ' . $validationIssue['available'] . ', recorded limit ' . $preview['overdraft_limit'] . ', insufficient ' . ($preview['insufficient_cash'] ? 'yes' : 'no') . '. Earlier staged events: ' . implode(', ', array_column($staged, 'event_id')) . '.');
                }
                $validationIssue['posted_balance_verified'] = true;
                if ($kind === 'supplier_payment') {
                    $partyId = pl_demo_operational_event_party($event, $master, true);
                    foreach (array_reverse($billIds) as $billId) {
                        $bill = pl_get_ar_document($actorId, $companyId, $bookId, $billId);
                        if ((int) $bill['party_id'] === $partyId && bccomp($bill['outstanding_fc'], $validationIssue['required'], 4) >= 0) { $receipt['unpaid_document_id'] = $billId; break; }
                    }
                    if (!isset($receipt['unpaid_document_id'])) { throw new RuntimeException('The staged supplier payment needs review: no matching unpaid bill supports its amount.'); }
                }
            } elseif ($validationIssue['code'] === 'source_not_posted' && isset($genericIds[$validationIssue['related_event_id']])) {
                throw new RuntimeException('The staged reversal has a posted source and its sample evidence needs review.');
            }
            $staged[] = ['event_id' => $event['id'], 'kind' => $kind, 'reason' => $validationIssue['reason'], 'validation_issue' => $validationIssue];
            $receipt['status'] = 'staged'; $receipt['validation_issue'] = $validationIssue; $receipts[] = $receipt; continue;
        }
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
    foreach (($evidence['bank_projection_excludes_unposted_sources'] ?? []) as $excludedId) {
        $excluded = array_values(array_filter($receipts, static fn (array $row): bool => $row['event_id'] === $excludedId));
        if (count($excluded) !== 1 || $excluded[0]['status'] !== 'staged' || $excluded[0]['journal_ids'] !== []) {
            throw new RuntimeException('The sample bank projection excludes a source that is no longer unposted: ' . $excludedId . '. Review its funding evidence.');
        }
    }
    $reconciledCount = pl_demo_operational_reconcile($companyId, $bookId, $events, $master, $receipts);
    // The contract above is what this business did; the showcase below is what 1.2.0 can
    // do with it. It runs after the reconcile so a showcase posting can never be mistaken
    // for part of the pinned contract, and it uses the same master data.
    $showcase = pl_demo_showcase_1_2($actorId, $companyId, $bookId, $pack, $master, $prefix);
    return ['status' => $staged === [] ? 'runtime_replayed' : 'runtime_replayed_with_staged_vertical_evidence', 'contract_digest' => hash('sha256', json_encode($events, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)), 'event_count' => count($events), 'replayed_count' => count(array_filter($receipts, static fn(array $row): bool => $row['status'] === 'replayed')), 'reconciled_count' => $reconciledCount, 'staged_count' => count($staged), 'opening_evidence' => 'retained_only_not_posted', 'receipts' => $receipts, 'staged' => $staged, 'account_ids' => $master['accounts'], 'party_ids' => $master['parties'], 'product_ids' => $master['products'], 'showcase_1_2' => $showcase];
}

/* ------------------------------------------------- the 1.2.0 sample showcase */

/**
 * What each sample demonstrates from 1.2.0, beyond its own pinned history.
 *
 * No pack shows everything: one that did would be a feature list rather than a business.
 * The four trading packs between them cover both discount policies and both price modes,
 * the distributor keeps the van day and the second person, and the trader keeps advances
 * and refunds. Every one of these posts through the same service a screen calls.
 */
function pl_demo_showcase_plan(string $packId): array
{
    $trading = [
        'retail-shop' => ['discount_posting' => 'net', 'price_mode' => 'exclusive', 'free_goods_output_tax' => 'none'],
        'trader' => ['discount_posting' => 'net', 'price_mode' => 'inclusive', 'free_goods_output_tax' => 'none'],
        'distributor' => ['discount_posting' => 'gross', 'price_mode' => 'exclusive', 'free_goods_output_tax' => 'open_market_value'],
        'pharmacy' => ['discount_posting' => 'gross', 'price_mode' => 'inclusive', 'free_goods_output_tax' => 'open_market_value'],
    ];
    return [
        'trading' => $trading[$packId] ?? null,
        'van_day' => $packId === 'distributor',
        'people' => $packId === 'distributor',
        'advances' => $packId === 'trader',
    ];
}

/**
 * Give every sample its own numbering, before a single document posts.
 *
 * A book provisions the recommended default series on first use, so documents would carry
 * a series number anyway. Setting them here makes the series a deliberate, visible choice
 * with its own width and reset rule, which is what Admin > Numbering actually does.
 *
 * @return array<string,string> document type => the number its next document will take
 */
function pl_demo_showcase_numbering(int $actorId, int $companyId, int $bookId): array
{
    $numbers = [];
    foreach (pl_document_series_types() as $type => $definition) {
        $current = pl_document_series_view(pl_document_series_row($actorId, $companyId, $bookId, $type));
        $series = pl_save_document_series($actorId, $companyId, $bookId, $type, [
            'prefix' => $definition['prefix'], 'padding' => 5, 'year_segment' => true, 'reset_rule' => 'yearly',
            'next_number' => max(1, (int) $current['next_number']), 'revision' => (int) $current['revision'],
            'reason' => 'Sample numbering: one series per document type, five digits, reset every year.',
        ]);
        $numbers[$type] = (string) $series['example'];
    }
    return $numbers;
}

/** A showcase-only account, outside the operational role block, created once per sample. */
function pl_demo_showcase_account(int $actorId, int $companyId, int $bookId, string $prefix, string $code, string $name, string $type): int
{
    if (str_contains($prefix, ':1.1.0:')) {
        $code = pl_account_code_format(['asset'=>1,'liability'=>2,'equity'=>3,'income'=>4,'expense'=>5][$type], $type === 'asset' ? 110 : 100, 50000 + (int) $code);
    }
    return (int) pl_save_account($actorId, $companyId, $bookId, ['code' => $code, 'name' => $name, 'type' => $type,
        'role' => null, 'is_active' => true, 'reason' => 'Sample account for the 1.2.0 showcase.',
        'creation_key' => $prefix . 'showcase-account:' . $code])['id'];
}

function pl_demo_showcase_module(int $actorId, int $companyId, string $module, string $prefix): void
{
    $state = pl_module_state($companyId, $module);
    if ($state['enabled']) { return; }
    pl_set_company_module($actorId, $companyId, $module, true, (int) $state['revision'], pl_module_registry()[$module]['digest'],
        'The sample includes this module so its 1.2.0 documents are the ones the application posts.', $prefix . 'showcase-module:' . $module);
}

/** The first stock product this sample's contract names, or null when it sells nothing. */
function pl_demo_showcase_stock_product(array $pack, array $master): ?int
{
    foreach (($pack['source_material']['research_evidence']['products'] ?? []) as $product) {
        if (is_array($product) && ($product['kind'] ?? '') === 'stock' && isset($master['products'][$product['id']])) {
            return (int) $master['products'][$product['id']];
        }
    }
    return null;
}

/**
 * One trading invoice carrying the whole of the trading-documents module: a pack line
 * entered as cases and loose units, a per-line discount, a free-goods line the customer is
 * not billed for, the sales-staff and area dimensions, cash taken at the counter, and the
 * warehouse the goods left from.
 */
function pl_demo_showcase_trading(int $actorId, int $companyId, int $bookId, array $pack, array $master, array $policy, string $prefix): array
{
    $productId = pl_demo_showcase_stock_product($pack, $master);
    if ($productId === null) { return ['status' => 'skipped', 'reason' => 'The sample has no stock product to sell in packs.']; }
    // Only optional modules are turned on here: `ar` is a required module and every book
    // already has it, which is why the contract replay can post an invoice at all.
    foreach (['inventory', 'inventory-locations', 'trading-documents'] as $module) {
        pl_demo_showcase_module($actorId, $companyId, $module, $prefix);
    }
    $outputTax = pl_demo_showcase_account($actorId, $companyId, $bookId, $prefix, '2500', 'Sample output tax', 'liability');
    $inputTax = pl_demo_showcase_account($actorId, $companyId, $bookId, $prefix, '1550', 'Sample input tax', 'asset');
    $freeGoods = pl_demo_showcase_account($actorId, $companyId, $bookId, $prefix, '5850', 'Free goods and promotions (sample)', 'expense');
    $tax = pl_create_tax_code($actorId, $companyId, $bookId, ['code' => 'DEMO17', 'name' => 'Sample example 17 percent',
        'treatment' => 'standard', 'sales_account_id' => $outputTax, 'purchase_account_id' => $inputTax,
        'reason' => 'Sample example rate. It is a manually configured demonstration rate, not a country tax rule.',
        'idempotency_key' => $prefix . 'showcase-tax-code']);
    pl_enter_tax_rate($actorId, $companyId, $bookId, ['tax_code_id' => (int) $tax['id'], 'effective_from' => '2026-01-01',
        'percentage' => '17', 'reason' => 'Sample example rate. It is a manually configured demonstration rate, not a country tax rule.',
        'idempotency_key' => $prefix . 'showcase-tax-rate']);
    $settings = pl_tax_settings($actorId, $companyId, $bookId);
    if ($settings['price_mode'] !== $policy['price_mode']) {
        pl_set_tax_price_mode($actorId, $companyId, $bookId, $policy['price_mode'], (int) $settings['revision'],
            'Sample price entry mode, so the discount and free-goods examples are read the way this book prices.',
            $prefix . 'showcase-price-mode');
    }
    $discountAccount = $policy['discount_posting'] === 'gross'
        ? (int) DB::queryFirstField('SELECT id FROM pl_accounts WHERE company_id=%i AND book_id=%i AND semantic_key=%s',
            $companyId, $bookId, 'core.income.sales_returns')
        : null;
    $policies = pl_trading_policies($actorId, $companyId, $bookId);
    pl_save_trading_policies($actorId, $companyId, $bookId, [
        'discount_posting' => $policy['discount_posting'], 'discount_account_id' => $discountAccount,
        'free_goods_account_id' => $freeGoods, 'free_goods_output_tax' => $policy['free_goods_output_tax'],
        'cash_on_invoice_cap' => '500.0000', 'revision' => (int) $policies['revision'],
        'reason' => 'Sample accounting policy for this sample\'s trading-document examples.',
        'idempotency_key' => $prefix . 'showcase-trading-policy']);
    $staff = pl_save_sales_staff($actorId, $companyId, $bookId, ['employee_id' => pl_save_employee($actorId,$companyId,['full_name'=>'Sample sales representative','employment_type'=>'full_time','employment_status'=>'active','hire_date'=>'2026-01-01','reason'=>'Explicit fictional demo employment'])['id'], 'code' => 'SS01', 'name' => 'Sample sales representative',
        'is_active' => true, 'reason' => 'Sample sales-staff dimension.']);
    $area = pl_save_area($actorId, $companyId, $bookId, ['code' => 'NORTH', 'name' => 'Sample northern area',
        'is_active' => true, 'reason' => 'Sample area dimension.']);
    $productPack = pl_save_product_pack($actorId, $companyId, $bookId, ['product_id' => $productId, 'code' => 'CASE12',
        'name' => 'Case of 12 (sample)', 'units_per_pack' => '12', 'is_active' => true, 'reason' => 'Sample product pack.']);
    $warehouse = pl_inventory_default_warehouse($actorId, $companyId, $bookId);
    $incomeAccount = (int) ($master['accounts']['sales_revenue'] ?? $master['accounts'][$master['first_income']]);
    // Stock the sale from a receipt of its own, so the showcase never depends on whatever
    // the contract replay happened to leave behind.
    pl_inventory_receive($actorId, $companyId, $bookId, ['product_id' => $productId, 'quantity' => '60', 'amount_base' => '600.0000',
        'date' => '2026-10-01', 'source_type' => 'sample_showcase_receipt', 'source_reference' => $prefix . 'showcase-receipt',
        'offset_account_id' => (int) ($master['accounts']['grni'] ?? $master['accounts']['accounts_payable']),
        'warehouse_id' => (int) $warehouse['id'], 'reason' => 'Sample goods received for the trading-document example.',
        'idempotency_key' => $prefix . 'showcase-goods-received']);
    $document = pl_save_ar_document($actorId, $companyId, $bookId, [
        'kind' => 'invoice', 'date' => '2026-10-05', 'due_date' => '2026-11-04',
        'currency' => pl_company_context($actorId, $companyId)['currency'],
        'party_id' => pl_demo_operational_event_party([], $master),
        'reference' => 'SAMPLE-TRADING-1', 'price_mode' => $policy['price_mode'],
        'notes' => 'Sample trading invoice: cases and loose units, a line discount, a free-goods line, staff and area, cash at the counter.',
        'sales_staff_id' => (int) $staff['id'], 'area_id' => (int) $area['id'], 'warehouse_id' => (int) $warehouse['id'],
        'cash_received' => '100.0000', 'cash_account_id' => (int) ($master['accounts']['cash_on_hand'] ?? $master['accounts']['bank_current']),
        'creation_key' => $prefix . 'showcase-trading-invoice',
        'lines' => [
            ['product_id' => $productId, 'account_id' => $incomeAccount, 'pack_id' => (int) $productPack['id'],
             'pack_quantity' => '2', 'unit_quantity' => '3',
             'unit_price' => '25.0000', 'discount_percent' => '10', 'tax_code_id' => (int) $tax['id'],
             'description' => 'Two cases of twelve and three loose units, less a ten per cent line discount'],
            ['product_id' => $productId, 'account_id' => $incomeAccount, 'quantity' => '2', 'unit_price' => '25.0000',
             'is_free_goods' => true, 'tax_code_id' => (int) $tax['id'],
             'description' => 'Two free with the order; the customer is not billed for them'],
        ]]);
    $posted = pl_post_ar_document($actorId, $companyId, $bookId, (int) $document['id'], (int) $document['revision']);
    $read = pl_get_ar_document($actorId, $companyId, $bookId, (int) $posted['id']);
    return ['status' => 'posted', 'document_id' => (int) $read['id'], 'document_number' => $read['document_number'],
        'discount_posting' => $policy['discount_posting'], 'price_mode' => $policy['price_mode'],
        'free_goods_output_tax' => $policy['free_goods_output_tax'],
        'total_fc' => $read['total_fc'] ?? null, 'discount_total' => $read['discount_total'] ?? null,
        'free_tax_total' => $read['free_tax_total'] ?? null, 'cash_received' => $read['cash_received'] ?? null,
        'cash_settlement_journal_id' => $read['cash_settlement_journal_id'],
        'sales_staff_id' => $read['sales_staff_id'], 'area_id' => $read['area_id'], 'warehouse_id' => $read['warehouse_id'],
        'pack_id' => (int) $productPack['id'], 'units_per_pack' => '12.0000',
        'billed_quantity' => (string) $read['lines'][0]['quantity'],
        'free_line_is_free' => (bool) $read['lines'][1]['is_free_goods'],
        'open_item_id' => $read['open_item_id']];
}

/**
 * The distributor's driver day, end to end: load the van, sell from it, take a sellable
 * customer return back into it, top it up at midday, raise a gate pass that moves nothing
 * and posts nothing, bring the rest back, adjust what the warehouse count disagrees with,
 * and settle the day under review.
 */
function pl_demo_showcase_van_day(int $actorId, int $companyId, int $bookId, array $pack, array $master, string $prefix, ?int $approverId): array
{
    $productId = pl_demo_showcase_stock_product($pack, $master);
    if ($productId === null) { return ['status' => 'skipped', 'reason' => 'The sample has no stock to load onto a van.']; }
    foreach (['inventory', 'inventory-locations'] as $module) { pl_demo_showcase_module($actorId, $companyId, $module, $prefix); }
    $day = '2026-10-12';
    $warehouse = pl_inventory_default_warehouse($actorId, $companyId, $bookId);
    $van = pl_save_inventory_warehouse($actorId, $companyId, $bookId, ['code' => 'VAN01', 'name' => 'Sample route van',
        'kind' => 'mobile', 'driver_employee_id' => pl_save_employee($actorId,$companyId,['full_name'=>'Sample driver','employment_type'=>'full_time','employment_status'=>'active','hire_date'=>'2026-01-01','reason'=>'Explicit fictional demo employment'])['id'], 'driver_name' => 'Sample driver', 'vehicle_reference' => 'SAMPLE-0001',
        'route_name' => 'Sample northern route', 'is_active' => true, 'reason' => 'Sample van as a mobile stock location.',
        'idempotency_key' => $prefix . 'showcase-van']);
    pl_inventory_receive($actorId, $companyId, $bookId, ['product_id' => $productId, 'quantity' => '100', 'amount_base' => '1000.0000',
        'date' => '2026-10-11', 'source_type' => 'sample_showcase_receipt', 'source_reference' => $prefix . 'showcase-van-receipt',
        'offset_account_id' => (int) ($master['accounts']['grni'] ?? $master['accounts']['accounts_payable']),
        'warehouse_id' => (int) $warehouse['id'], 'reason' => 'Sample goods received the day before the van day.',
        'idempotency_key' => $prefix . 'showcase-van-goods-received']);
    $load = pl_post_stock_document($actorId, $companyId, $bookId, ['kind' => 'stock_issue', 'date' => $day,
        'from_warehouse_id' => (int) $warehouse['id'], 'to_warehouse_id' => (int) $van['id'],
        'reference' => 'Morning load', 'reason' => 'Sample morning load onto the van.',
        'lines' => [['product_id' => $productId, 'quantity' => '40']], 'idempotency_key' => $prefix . 'showcase-van-load']);
    $gatePass = pl_issue_gate_pass($actorId, $companyId, $bookId, ['date' => $day, 'warehouse_id' => (int) $warehouse['id'],
        'direction' => 'out', 'is_returnable' => true, 'expected_return_date' => $day, 'covers_document_id' => (int) $load['id'],
        'party_name' => 'Sample carrier', 'vehicle_reference' => 'SAMPLE-0001', 'driver_name' => 'Sample driver',
        'purpose' => 'Sample outward returnable pass covering the morning load',
        'reason' => 'Sample gate pass: it moves no stock and posts nothing.', 'idempotency_key' => $prefix . 'showcase-gate-pass']);
    $stamped = pl_stamp_gate_pass($actorId, $companyId, $bookId, (int) $gatePass['id'], 'out');
    $sale = pl_inventory_issue($actorId, $companyId, $bookId, ['product_id' => $productId, 'quantity' => '22', 'date' => $day,
        'warehouse_id' => (int) $van['id'], 'source_type' => 'sample_van_sale', 'source_reference' => $prefix . 'showcase-van-sale',
        'reason' => 'Sample sale out of the van.', 'idempotency_key' => $prefix . 'showcase-van-sale']);
    pl_inventory_return($actorId, $companyId, $bookId, ['original_movement_id' => (int) $sale['movement_id'], 'quantity' => '2',
        'date' => $day, 'warehouse_id' => (int) $van['id'], 'source_type' => 'sample_van_return',
        'source_reference' => $prefix . 'showcase-van-customer-return',
        'reason' => 'Sample sellable customer return, straight back into van stock.',
        'idempotency_key' => $prefix . 'showcase-van-customer-return']);
    $reissue = pl_post_stock_document($actorId, $companyId, $bookId, ['kind' => 'stock_reissue', 'date' => $day,
        'from_warehouse_id' => (int) $warehouse['id'], 'to_warehouse_id' => (int) $van['id'],
        'original_document_id' => (int) $load['id'], 'reference' => 'Midday top-up',
        'reason' => 'Sample re-issue against the morning load.',
        'lines' => [['product_id' => $productId, 'quantity' => '10']], 'idempotency_key' => $prefix . 'showcase-van-reissue']);
    $return = pl_post_stock_document($actorId, $companyId, $bookId, ['kind' => 'stock_return', 'date' => $day,
        'from_warehouse_id' => (int) $van['id'], 'to_warehouse_id' => (int) $warehouse['id'],
        'reference' => 'End of route', 'reason' => 'Sample return of unsold stock at the end of the route.',
        'lines' => [['product_id' => $productId, 'quantity' => '30']], 'idempotency_key' => $prefix . 'showcase-van-return']);
    // The warehouse count is one unit short of the book. Adjusting it is the only thing in
    // this day that posts a journal, and it posts through the ordinary inventory service.
    $warehouseBalance = pl_inventory_balance($actorId, $companyId, $bookId, $productId, $day, (int) $warehouse['id']);
    $adjust = pl_inventory_adjust_count($actorId, $companyId, $bookId, ['product_id' => $productId,
        'counted_quantity' => bcsub((string) $warehouseBalance['quantity'], '1', 4),
        'expected_quantity' => (string) $warehouseBalance['quantity'],
        'offset_account_id' => (int) ($master['accounts']['cost_of_goods_sold'] ?? $master['accounts'][$master['first_expense']]),
        'date' => $day, 'warehouse_id' => (int) $warehouse['id'], 'source_type' => 'sample_stock_count',
        'source_reference' => $prefix . 'showcase-stock-adjustment',
        'reason' => 'Sample stock count: the warehouse is one unit short of the book.',
        'idempotency_key' => $prefix . 'showcase-stock-adjustment']);
    $sheet = pl_van_day($actorId, $companyId, $bookId, (int) $van['id'], $day);
    $review = pl_review_van_settlement($actorId, $companyId, $bookId, (int) $van['id'], $day,
        'Sample review of the driver\'s day before anyone approves it.', $prefix . 'showcase-van-review');
    // Recording and approving are separate acts: the owner reviews, and the person holding
    // the custom role that can approve a settlement is the one who approves it.
    $approved = pl_approve_van_settlement($approverId ?? $actorId, $companyId, $bookId, (int) $review['id'],
        'Sample approval of a reconciled driver day.', $prefix . 'showcase-van-approve');
    return ['status' => 'posted', 'van_id' => (int) $van['id'], 'day' => $day,
        'issue_number' => $load['document_number'], 'reissue_number' => $reissue['document_number'],
        'return_number' => $return['document_number'], 'gate_pass_number' => $gatePass['document_number'],
        'gate_pass_moves_stock' => (bool) $gatePass['moves_stock'],
        'gate_pass_stamped_out' => $stamped['gate_pass']['security_out_at'] !== null,
        'adjustment_movement_id' => (int) $adjust['movement_id'],
        'totals' => $sheet['totals'], 'reconciles' => (bool) $review['reconciles'],
        'settlement_id' => (int) $review['id'], 'settlement_status' => (string) $approved['status'],
        'approved_by' => (int) $approved['approved_by'], 'reviewed_by_owner' => $actorId];
}

/**
 * Advances and refunds, the whole of it in one sample: money on account with a remainder
 * held as unapplied credit in its own control account, that credit applied later with no
 * bank line, a cash refund of what is left, a credit note with no original invoice behind
 * it, an advance paid to a supplier, and a batch that posts one voucher per customer.
 */
function pl_demo_showcase_advances(int $actorId, int $companyId, int $bookId, array $master, string $prefix): array
{
    $currency = (string) pl_company_context($actorId, $companyId)['currency'];
    $bank = (int) ($master['accounts']['bank_current'] ?? $master['accounts']['cash_on_hand']);
    $customerPool = $master['parties']['customer']; $vendorPool = $master['parties']['vendor'];
    $customer = (int) reset($customerPool); $vendor = (int) reset($vendorPool);
    $receivable = (int) $master['accounts']['accounts_receivable'];
    $payable = (int) $master['accounts']['accounts_payable'];
    // The replay's own invoices and bills normally activate both controls; a sample whose
    // contract posted neither would otherwise have no open-item ledger to work in.
    foreach ([$receivable, $payable] as $control) {
        if (!DB::queryFirstField('SELECT account_id FROM pl_open_item_accounts WHERE account_id=%i FOR SHARE', $control)) {
            pl_activate_open_item_account($actorId, $companyId, $bookId, $control, 'Sample open-item control for the advances and refunds examples.');
        }
    }
    $customerAdvances = pl_advance_control($actorId, $companyId, $bookId, 'customer');
    $supplierAdvances = pl_advance_control($actorId, $companyId, $bookId, 'supplier');
    $income = (int) $master['accounts'][$master['first_income']];
    $balance = pl_open_item_recognize($actorId, $companyId, $bookId, ['party_id' => $customer,
        'control_account_id' => $receivable, 'offset_account_id' => $income, 'currency' => $currency,
        'amount_fc' => '120.0000', 'date' => '2026-10-02', 'source_reference' => $prefix . 'showcase-advance-balance',
        'description' => 'Sample customer balance the on-account receipt settles first.',
        'idempotency_key' => $prefix . 'showcase-advance-balance']);
    // 1. Money on account: part settles the balance and the rest is held as unapplied
    //    credit in its own control account, not netted away inside receivables.
    $onAccount = pl_settle_open_items($actorId, $companyId, $bookId, ['direction' => 'receivable', 'party_id' => $customer,
        'bank_account_id' => $bank, 'amount_fc' => '200.0000', 'currency' => $currency, 'date' => '2026-10-20',
        'description' => 'Sample on-account receipt; the remainder stays as unapplied credit.',
        'advance_account_id' => $customerAdvances, 'idempotency_key' => $prefix . 'showcase-on-account',
        'allocations' => [['item_id' => (int) $balance['item_id'], 'amount_fc' => '120.0000']]]);
    // 2. The credit applied later, to a balance recognised after the receipt.
    $later = pl_open_item_recognize($actorId, $companyId, $bookId, ['party_id' => $customer,
        'control_account_id' => $receivable, 'offset_account_id' => $income, 'currency' => $currency,
        'amount_fc' => '50.0000', 'date' => '2026-10-22', 'source_reference' => $prefix . 'showcase-later-balance',
        'description' => 'Sample later balance, settled from credit the customer already holds.',
        'idempotency_key' => $prefix . 'showcase-later-balance']);
    $applied = pl_apply_unapplied_credit($actorId, $companyId, $bookId, ['advance_item_id' => (int) $onAccount['advance']['item_id'],
        'date' => '2026-10-25', 'description' => 'Sample application of unapplied credit; no bank line.',
        'idempotency_key' => $prefix . 'showcase-apply-credit',
        'allocations' => [['item_id' => (int) $later['item_id'], 'amount_fc' => '50.0000']]]);
    // 3. What is still unapplied is refunded in cash, out of its own control account.
    $refund = pl_refund_unapplied_credit($actorId, $companyId, $bookId, ['item_id' => (int) $onAccount['advance']['item_id'],
        'bank_account_id' => $bank, 'amount_fc' => '30.0000', 'date' => '2026-10-28',
        'description' => 'Sample cash refund of the credit the customer never used.',
        'idempotency_key' => $prefix . 'showcase-refund-credit']);
    // 4. A credit note with no original invoice, offset to the reserved contra-income group.
    $salesReturns = (int) DB::queryFirstField('SELECT id FROM pl_accounts WHERE company_id=%i AND book_id=%i AND semantic_key=%s',
        $companyId, $bookId, 'core.income.sales_returns');
    $orphan = pl_recognize_unapplied_credit($actorId, $companyId, $bookId, ['side' => 'customer', 'party_id' => $customer,
        'offset_account_id' => $salesReturns, 'currency' => $currency, 'amount_fc' => '18.0000', 'date' => '2026-10-29',
        'source_reference' => $prefix . 'showcase-orphan-credit',
        'description' => 'Sample credit note with no original invoice behind it.',
        'advance_account_id' => $customerAdvances, 'idempotency_key' => $prefix . 'showcase-orphan-credit']);
    // 5. An advance paid to a supplier: the mirror of the customer side, held as an asset.
    $supplierAdvance = pl_settle_open_items($actorId, $companyId, $bookId, ['direction' => 'payable', 'party_id' => $vendor,
        'bank_account_id' => $bank, 'amount_fc' => '75.0000', 'currency' => $currency, 'date' => '2026-10-30',
        'description' => 'Sample advance paid to a supplier before any bill arrives.',
        'advance_account_id' => $supplierAdvances, 'idempotency_key' => $prefix . 'showcase-supplier-advance',
        'allocations' => []]);
    // 6. An end-of-day batch: one voucher per customer, each individually reversible.
    $second = pl_save_party($actorId, $companyId, $bookId, ['legal_name' => 'Second sample counter customer (Sample)',
        'entity_type' => 'private_company', 'country_code' => 'ZZ', 'is_customer' => true, 'is_vendor' => false,
        'currency' => $currency, 'ar_account_id' => $receivable, 'notes' => 'Sample party for the batch-receipt example.',
        'reason' => 'Sample batch-receipt party.', 'request_key' => $prefix . 'showcase-batch-party']);
    $batch = pl_post_batch_receipts($actorId, $companyId, $bookId, ['direction' => 'receivable', 'bank_account_id' => $bank,
        'date' => '2026-10-31', 'description' => 'Sample end-of-day collection, one voucher per customer.',
        'idempotency_key' => $prefix . 'showcase-batch',
        'rows' => [
            ['party_id' => $customer, 'amount_fc' => '40.0000', 'currency' => $currency, 'advance_account_id' => $customerAdvances, 'allocations' => []],
            ['party_id' => (int) $second['id'], 'amount_fc' => '25.0000', 'currency' => $currency, 'advance_account_id' => $customerAdvances, 'allocations' => []],
        ]]);
    $unapplied = pl_unapplied_credit($actorId, $companyId, $bookId, 'customer', '2026-12-31');
    return ['status' => 'posted', 'payable_control_account_id' => $payable,
        'on_account_remainder' => $onAccount['remainder_fc'], 'applied_fc' => $applied['applied_fc'],
        'refund_side' => $refund['refund_side'], 'refund_journal_id' => (int) $refund['journal_id'],
        'orphan_credit_item_id' => (int) $orphan['item_id'], 'orphan_offset_account_id' => (int) $orphan['offset_account_id'],
        'orphan_offset_override' => (bool) $orphan['offset_override'],
        'supplier_advance_remainder' => $supplierAdvance['remainder_fc'],
        'batch_voucher_count' => (int) $batch['voucher_count'], 'batch_total_fc' => $batch['total_fc'],
        'customer_advances_account_id' => $customerAdvances, 'supplier_advances_account_id' => $supplierAdvances,
        'unapplied_customer_credit_base' => $unapplied['total_base'], 'unapplied_customer_items' => (int) $unapplied['count'],
        'unapplied_reconciles_to_control' => (bool) $unapplied['reconciled']];
}

/**
 * A second person and a custom role, so the capability system is something a visitor can
 * see: the van day is reviewed by the owner and approved by someone whose only authority
 * beyond reading is approving it.
 */
function pl_demo_showcase_people(int $actorId, int $companyId, string $prefix): array
{
    $email = 'route.supervisor.' . substr(hash('sha256', $prefix . (string) $companyId), 0, 16) . '@example.invalid';
    $userId = pl_create_user($email, 'Sample route supervisor', bin2hex(random_bytes(16)));
    // `company.write` is here because approving a settlement is a write on the company;
    // what this role has that an ordinary recorder does not is `settlement.approve`.
    $capabilities = ['company.read', 'company.write', 'cost.view', 'settlement.approve'];
    $role = pl_save_role($actorId, $companyId, ['name' => 'Route supervisor (sample)',
        'description' => 'Reads this company, records entries, sees cost and approves a reconciled van day. No administration of any kind.',
        'capabilities' => $capabilities,
        'reason' => 'Sample custom role, so the capability system is visible in the sample.']);
    pl_assign_company_role($actorId, $companyId, $userId, (int) $role['id'], 'Sample second person for this sample company.');
    return ['user_id' => $userId, 'role_id' => (int) $role['id'], 'role_name' => (string) $role['name'],
        'capabilities' => $capabilities,
        'note' => 'A sample account with a random password that is never shown and a mailbox at example.invalid that can never receive mail. It exists so a visitor can see a custom role doing something.'];
}

/** Post the parts of 1.2.0 this sample demonstrates, after its contract replay. */
function pl_demo_showcase_1_2(int $actorId, int $companyId, int $bookId, array $pack, array $master, string $prefix): array
{
    $plan = pl_demo_showcase_plan((string) $pack['id']);
    $receipt = ['plan' => $plan];
    $approverId = null;
    if ($plan['people']) {
        $receipt['people'] = pl_demo_showcase_people($actorId, $companyId, $prefix);
        $approverId = (int) $receipt['people']['user_id'];
    }
    if ($plan['trading'] !== null) {
        $receipt['trading'] = pl_demo_showcase_trading($actorId, $companyId, $bookId, $pack, $master, $plan['trading'], $prefix);
    }
    if ($plan['van_day']) {
        $receipt['van_day'] = pl_demo_showcase_van_day($actorId, $companyId, $bookId, $pack, $master, $prefix, $approverId);
    }
    if ($plan['advances']) {
        $receipt['advances'] = pl_demo_showcase_advances($actorId, $companyId, $bookId, $master, $prefix);
    }
    $trial = pl_trial_balance($actorId, $companyId, $bookId, '2026-12-31');
    $balance = pl_balance_sheet($actorId, $companyId, $bookId, '2026-12-31');
    if (!$trial['balanced'] || !$balance['balanced']) {
        throw new RuntimeException('The sample 1.2.0 showcase left the ledger unbalanced; setup was rolled back.');
    }
    $receipt['trial_balance_total_debit'] = $trial['total_debit'];
    $receipt['reports_agree'] = true;
    return $receipt;
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
        // The one shared limit, not a second copy of it: this check kept the pre-1.1.0
        // default of 100 while the runtime ceiling moved to 250, so an 85-source pack was
        // refused in the demo by a number no longer in force anywhere else.
        if (pl_demo_enabled() && pl_demo_document_limit() < $pack['source_count'] + 20) {
            throw new PlDemoUnavailable('The sample needs room for its history and at least twenty practice records. Ask the demo operator to check its capacity setting.');
        }
        $prefix = 'sample:' . $pack['id'] . ':' . $pack['version'] . ':';
        $mapping = pl_account_code_mapping($company['accounts']);
        foreach (array_merge($pack['account_headings'] ?? [], $pack['accounts']) as $definition) {
            $account = pl_save_account($actorId, $companyId, $bookId, $definition + [
                'is_active' => true, 'reason' => 'Original sample chart and manual support schedules.',
                'creation_key' => $prefix . 'account:' . $definition['code'],
            ]);
            $mapping[$definition['code']] = $account['id'];
        }
        // Keep old guide aliases local to this new pack; existing books are never renumbered.
        foreach (($pack['code_aliases'] ?? []) as $old => $code) { $mapping[$old] = $mapping[$code]; }
        pl_demo_classify_money_accounts($actorId, $companyId, $bookId, $mapping, $pack['money_account_kinds'] ?? ['1000'=>'bank','1010'=>'bank','1020'=>'physical','1030'=>'physical']);
        $documentParties = [];
        foreach (($pack['document_parties'] ?? []) as $definition) {
            $customer = $definition['role'] === 'customer';
            $party = pl_save_party($actorId, $companyId, $bookId, [
                'legal_name' => $definition['name'], 'entity_type' => 'private_company', 'country_code' => 'ZZ',
                'is_customer' => $customer, 'is_vendor' => !$customer, 'currency' => $company['currency'],
                'ar_account_id' => $customer ? $mapping[$definition['key'] === 'design-client' && isset($mapping['1150']) ? '1150' : '1100'] : null, 'ap_account_id' => !$customer ? $mapping['2000'] : null,
                'notes' => 'Fictional party explicitly linked to the versioned sample sources.',
                'reason' => 'Prepare linked sample receipt and payment parties.', 'request_key' => $prefix . 'document-party:' . $definition['key'],
            ]);
            $documentParties[$definition['key']] = (int) $party['id'];
        }
        $storyInvoices = []; $storySources = [];
        // B64: a printed invoice, receipt or statement carries the seller's own block, and
        // an empty profile prints the bare company name. Every sample fills it in.
        pl_save_company_profile($actorId, $companyId, $pack['company_profile'] + [
            'revision' => pl_company_profile($actorId, $companyId)['revision'],
            'reason' => 'Sample company profile so printed documents carry a complete, entirely fictional seller block.',
            'idempotency_key' => $prefix . 'company-profile',
        ]);
        // B61: where the sample is a partnership, each partner keeps their own capital and
        // drawings account and the recorded profit share, and their movements say whose they are.
        // Numbering is set before a single document posts, so every document in the sample
        // carries a real series number rather than a formatted id (B37/B55).
        $numbering = pl_demo_showcase_numbering($actorId, $companyId, $bookId);
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
            $postedJournalId = null;
            if ($event['kind'] === 'owner_transaction') {
                // Capital introduced, the owner's loan, its repayment and drawings go through the
                // owner-transactions service so every sample shows them where the owner looks (B61).
                $ownerPosted = pl_post_owner_transaction($actorId, $companyId, $bookId, [
                    'kind' => $event['owner_kind'], 'date' => $event['date'], 'amount' => $event['amount'],
                    'cash_account_id' => $mapping[$event['money_code']], 'owner_account_id' => $mapping[$event['owner_code']],
                    'description' => $event['description'], 'creation_key' => $prefix . $event['key'],
                ] + (isset($event['partner_key']) ? ['partner_id' => $partnerIds[$event['partner_key']]] : []));
                $postedJournalId = (int) $ownerPosted['id'];
            } elseif ($event['kind'] === 'story_invoice') {
                $invoice = pl_save_ar_document($actorId, $companyId, $bookId, [
                    'kind' => 'invoice', 'date' => $event['date'], 'due_date' => pl_demo_operational_due_date($event['date']),
                    'currency' => $company['currency'], 'party_id' => $documentParties[$event['party_key']],
                    'reference' => $event['reference'], 'notes' => $event['description'], 'creation_key' => $prefix . $event['key'],
                    'price_mode' => 'exclusive', 'lines' => [['account_id' => $mapping['4000'], 'description' => 'Design review (Sample)',
                        'quantity' => '1.0000', 'unit_price' => $event['amount']]],
                ]);
                $posted = pl_post_ar_document($actorId, $companyId, $bookId, (int) $invoice['id'], (int) $invoice['revision']);
                $storyInvoices[$event['key']] = (int) $posted['id'];
                $postedJournalId = (int) $posted['journal_id'];
            } elseif ($event['kind'] === 'story_collection') {
                $settled = pl_settle_ar_document($actorId, $companyId, $bookId, $storyInvoices[$event['invoice_key']], [
                    'bank_account_id' => $mapping['1000'], 'amount_fc' => $event['amount'], 'date' => $event['date'],
                    'description' => $event['description'], 'idempotency_key' => $prefix . $event['key'],
                ]);
                $postedJournalId = (int) $settled['journal_id'];
            } elseif (in_array($event['kind'], ['receipt', 'expense'], true)) {
                $source = pl_save_document($actorId, $companyId, $bookId, [
                    'kind' => $event['kind'], 'date' => $event['date'], 'amount' => $event['amount'],
                    'party_id' => isset($event['party_key']) ? $documentParties[$event['party_key']] : null,
                    'money_account_id' => $mapping[$event['money_code']], 'category_account_id' => $mapping[$event['category_code']],
                    'counterparty' => $event['counterparty'], 'reference' => $event['reference'], 'memo' => $event['description'],
                    'creation_key' => $prefix . $event['key'],
                ]);
                $postedDocument = pl_post_document($actorId, $companyId, $bookId, $source['id'], $source['revision']);
                $postedJournalId = (int) $postedDocument['journal_id'];
            } else {
                $lines = array_map(static fn (array $row): array => ['account_id' => $mapping[$row['code']],
                    'debit' => $row['debit'], 'credit' => $row['credit'], 'description' => $row['description']], $event['lines']);
                $source = pl_save_general_draft($actorId, $companyId, $bookId, ['date' => $event['date'],
                    'reference' => $event['reference'], 'description' => $event['description'], 'lines' => $lines,
                    'creation_key' => $prefix . $event['key']]);
                $postedGeneral = pl_post_general_draft($actorId, $companyId, $bookId, $source['id'], $source['revision']);
                $postedJournalId = (int) $postedGeneral['journal_id'];
                if ($event['reverse']) { pl_reverse_general_draft($actorId, $companyId, $bookId, $source['id'], $event['date'], 'Original sample correction: reverse wrong-cost; replacement is correct-cost on 2025-08-21.'); }
            }
            $storySources[$event['key']] = $postedJournalId;
        }
        foreach ($pack['drafts'] as $event) {
            pl_save_document($actorId, $companyId, $bookId, $event + ['party_id' => isset($event['party_key']) ? $documentParties[$event['party_key']] : null, 'money_account_id' => $mapping[$event['money_code'] ?? '1000'],
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
            'numbering' => $numbering, 'partner_ids' => $partnerIds, 'story_sources' => $storySources,
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
    $pack['story_sources'] = $snapshot['story_sources'] ?? [];
    return $pack;
}
