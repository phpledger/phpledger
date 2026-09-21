<?php
declare(strict_types=1);

/**
 * Sample structure, separated from sample history (owner decision B50).
 *
 * The owner's report was that business setup "does not allow to choose chart of account and
 * other structure from a sample company". A sample company has always carried two different
 * things in one file: what the business *is* — its chart, the modules it needs, its numbering,
 * its accounting policies, its customers, suppliers and products — and what the business *did*,
 * which is the posted history the demo packs exist to show.
 *
 * A skeleton is the first of those two and nothing of the second. It is read from a declared
 * `structure` section and never derived by filtering `events`, because filtering history to
 * infer structure is how a skeleton silently acquires a balance: an account that only exists
 * because a journal mentioned it, a customer that only exists because an invoice named one.
 * `pl_sample_structure_read()` is the only reader, and it touches `events`, `drafts`,
 * `checkpoints` and `monthly_support` nowhere. `tests/onboarding_skeleton_test.php` holds it to
 * that by handing it a pack whose history would change the answer if it were read.
 *
 * Two places may hold the structure section, in this order:
 *
 *  1. `$pack['structure']` — the sample manifest's own section. This is the contract the
 *     bundled packs take on when `tools/build-demo-packs.py` learns to emit it; once they do,
 *     it wins and step 2 is dead weight.
 *  2. `resources/sample-structures/<id>-<version>.json` — the bundled structure catalogue,
 *     which carries the same section for the packs as they stand today. Each file pins the
 *     digest of the pack it was built from, so a pack that moves makes its structure stale
 *     rather than silently wrong, and `tools/build-sample-structures.py --check` says so.
 *
 * Nothing here posts. A skeleton import creates structure with zero balances, and
 * `pl_import_sample_skeleton()` proves that before it commits: no journal, no document, no
 * draft, and a trial balance that is both empty and balanced.
 */

const PL_SAMPLE_STRUCTURE_CONTRACT = 1;

/** The sample identities that may carry a structure. Never a request path. */
function pl_sample_structure_ids(): array
{
    return array_merge(['accounting-starter'], array_keys(pl_demo_pack_catalog()));
}

/** Where the bundled structure catalogue lives. */
function pl_sample_structure_directory(): string
{
    // The trailing slash is load-bearing, and rtrim puts it back the way it was. The package
    // boundary test reads this literal to decide what the code needs at runtime: written without
    // the slash it asks for a file named 'resources/sample-structures', which is not packaged
    // because the directory's contents are. resources/lang solves the same problem the same way.
    return rtrim(PL_ROOT . '/resources/sample-structures/', '/');
}

/**
 * Resolve one sample's structure section, or null when that sample offers no skeleton.
 *
 * Reads the manifest's own `structure` section first and the bundled catalogue second. It
 * never reads `events`, `drafts`, `checkpoints` or `monthly_support`, and it never infers.
 *
 * @return array<string, mixed>|null
 */
function pl_sample_structure_read(string $id): ?array
{
    if (!in_array($id, pl_sample_structure_ids(), true)) {
        throw new DomainException('Choose one of the bundled sample companies.');
    }
    $sample = pl_demo_sample($id);
    if (is_array($sample['structure'] ?? null)) {
        $structure = pl_sample_structure_validate($sample['structure'], $id, (string) $sample['version'], 'manifest');
        $structure['digest'] = hash('sha256', json_encode($sample['structure'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        return $structure;
    }
    $path = pl_sample_structure_directory() . '/' . $id . '-' . $sample['version'] . '.json';
    if (!is_file($path)) {
        return null;
    }
    $raw = (string) file_get_contents($path);
    // Git may check out text with CRLF; the pinned bytes use LF, like the demo packs.
    $raw = str_replace("\r\n", "\n", $raw);
    try {
        $document = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new DomainException('A bundled sample structure is invalid. Restore the reviewed package.');
    }
    if (!is_array($document)) {
        throw new DomainException('A bundled sample structure is invalid. Restore the reviewed package.');
    }
    if (!pl_sample_structure_is_current($document['source_digest'] ?? null, (string) $sample['digest'])) {
        return null;
    }
    $structure = pl_sample_structure_validate($document, $id, (string) $sample['version'], 'catalogue');
    $structure['digest'] = hash('sha256', $raw);
    return $structure;
}

/**
 * Is a catalogue structure still the one its sample describes?
 *
 * A structure built from a pack records that pack's digest. If the pack has moved since — the
 * packs are machine-generated and are rewritten whole — the structure describes a chart that no
 * longer exists, so the sample stops offering a skeleton instead of offering a wrong one, and
 * `tools/build-sample-structures.py --check` names the file that has to be regenerated.
 *
 * A structure with no pin belongs to a sample that has no pack file to drift from: the starter
 * playground is built in code. Nothing to compare, so nothing to go stale.
 */
function pl_sample_structure_is_current(mixed $pinned, string $sampleDigest): bool
{
    if (!is_string($pinned) || $pinned === '') {
        return true;
    }
    return hash_equals($pinned, $sampleDigest);
}

/**
 * Validate one structure section. Every section is optional except the identity and the
 * contract number, because a sample may legitimately bring only a chart.
 *
 * @return array<string, mixed>
 */
function pl_sample_structure_validate(array $document, string $id, string $version, string $origin): array
{
    if (($document['contract'] ?? null) !== PL_SAMPLE_STRUCTURE_CONTRACT) {
        throw new DomainException('This sample structure needs a newer PHP Ledger. Update before using it.');
    }
    if (($document['id'] ?? $id) !== $id || ($document['version'] ?? $version) !== $version) {
        throw new DomainException('A sample structure does not match the sample it belongs to.');
    }
    // History has no business in a structure section. A sample that puts it here is refused
    // rather than quietly half-imported.
    foreach (['events', 'drafts', 'checkpoints', 'monthly_support', 'journals', 'documents'] as $forbidden) {
        if (array_key_exists($forbidden, $document)) {
            throw new DomainException('A sample structure cannot carry ' . $forbidden . ': structure and history are separate sections.');
        }
    }
    $structure = ['id' => $id, 'version' => $version, 'origin' => $origin, 'digest' => ''];
    foreach (['accounts', 'open_item_accounts', 'modules', 'packages', 'number_series', 'parties', 'products', 'tax_codes'] as $key) {
        $value = $document[$key] ?? [];
        if (!is_array($value) || !array_is_list($value)) {
            throw new DomainException('A sample structure declares ' . $key . ' as a list.');
        }
        $structure[$key] = $value;
    }
    foreach (['policies', 'company_profile'] as $key) {
        $value = $document[$key] ?? [];
        if (!is_array($value)) {
            throw new DomainException('A sample structure declares ' . $key . ' as a map.');
        }
        $structure[$key] = $value;
    }
    pl_sample_structure_check_accounts($structure['accounts']);
    pl_sample_structure_check_modules($structure['modules']);
    foreach ($structure['open_item_accounts'] as $code) {
        if (!is_string($code) || $code === '') { throw new DomainException('A sample structure names open-item accounts by code.'); }
    }
    foreach ($structure['packages'] as $slug) {
        if (!is_string($slug) || !preg_match('/^[a-z][a-z0-9-]{0,59}$/D', $slug)) { throw new DomainException('A sample structure names packages by slug.'); }
    }
    pl_sample_structure_check_series($structure['number_series']);
    pl_sample_structure_check_parties($structure['parties']);
    pl_sample_structure_check_products($structure['products']);
    pl_sample_structure_check_tax_codes($structure['tax_codes']);
    foreach (array_keys($structure['company_profile']) as $field) {
        // Only the wording a printed document carries. A sample's fictional legal name, address
        // and registration are its own identity and are never copied into a real business.
        if ($field !== 'footer_terms') {
            throw new DomainException('A sample structure may only carry the document footer wording, not another business\'s identity.');
        }
    }
    return $structure;
}

/** @param array<int, mixed> $accounts */
function pl_sample_structure_check_accounts(array $accounts): void
{
    $codes = [];
    foreach ($accounts as $definition) {
        if (!is_array($definition) || !is_string($definition['code'] ?? null) || !is_string($definition['name'] ?? null)
            || !in_array($definition['type'] ?? null, ['asset', 'liability', 'equity', 'income', 'expense'], true)) {
            throw new DomainException('Every account in a sample structure needs a code, a name and a classification.');
        }
        foreach (['opening_balance', 'balance', 'debit', 'credit'] as $forbidden) {
            if (array_key_exists($forbidden, $definition)) {
                throw new DomainException('A sample structure cannot give an account a balance: a skeleton starts at zero.');
            }
        }
        if (isset($codes[$definition['code']])) {
            throw new DomainException('A sample structure repeats the account code ' . $definition['code'] . '.');
        }
        $codes[$definition['code']] = true;
    }
}

/** @param array<int, mixed> $modules */
function pl_sample_structure_check_modules(array $modules): void
{
    $registry = pl_module_registry();
    foreach ($modules as $module) {
        if (!is_string($module) || !isset($registry[$module])) {
            throw new DomainException('A sample structure names a module this release does not bundle.');
        }
        if ($registry[$module]['optional'] !== true) {
            throw new DomainException('A required module is always on and is never listed by a sample structure.');
        }
    }
}

/** @param array<int, mixed> $series */
function pl_sample_structure_check_series(array $series): void
{
    $types = pl_document_series_types();
    $seen = [];
    foreach ($series as $definition) {
        if (!is_array($definition) || !is_string($definition['type'] ?? null) || !isset($types[$definition['type']])) {
            throw new DomainException('A sample structure names a document type that has no number series.');
        }
        if (isset($seen[$definition['type']])) {
            throw new DomainException('A sample structure sets the same number series twice.');
        }
        $seen[$definition['type']] = true;
        if (!is_string($definition['prefix'] ?? null) || !is_int($definition['padding'] ?? null)
            || !is_bool($definition['year_segment'] ?? null) || !in_array($definition['reset_rule'] ?? null, ['never', 'yearly'], true)) {
            throw new DomainException('A number series in a sample structure needs a prefix, a width, a year segment and a reset rule.');
        }
    }
}

/** @param array<int, mixed> $parties */
function pl_sample_structure_check_parties(array $parties): void
{
    foreach ($parties as $party) {
        if (!is_array($party) || !is_string($party['legal_name'] ?? null) || $party['legal_name'] === ''
            || !is_bool($party['is_customer'] ?? null) || !is_bool($party['is_vendor'] ?? null)) {
            throw new DomainException('Every customer or supplier in a sample structure needs a name and a role.');
        }
        if (array_key_exists('opening_balance', $party)) {
            throw new DomainException('A sample structure cannot give a customer or supplier an opening balance.');
        }
    }
}

/** @param array<int, mixed> $products */
function pl_sample_structure_check_products(array $products): void
{
    foreach ($products as $product) {
        if (!is_array($product) || !is_string($product['sku'] ?? null) || !is_string($product['name'] ?? null)
            || !in_array($product['kind'] ?? null, ['stock', 'nonstock'], true)) {
            throw new DomainException('Every product in a sample structure needs a code, a name and a kind.');
        }
        foreach (['opening_quantity', 'quantity', 'opening_value'] as $forbidden) {
            if (array_key_exists($forbidden, $product)) {
                throw new DomainException('A sample structure cannot give a product opening stock.');
            }
        }
    }
}

/** @param array<int, mixed> $taxCodes */
function pl_sample_structure_check_tax_codes(array $taxCodes): void
{
    foreach ($taxCodes as $tax) {
        if (!is_array($tax) || !is_string($tax['code'] ?? null) || !is_string($tax['name'] ?? null)
            || !is_string($tax['percentage'] ?? null)
            || !is_string($tax['sales_code'] ?? null) || !is_string($tax['purchase_code'] ?? null)) {
            throw new DomainException('Every tax code in a sample structure needs a code, a name, a rate and its two accounts.');
        }
    }
}

/**
 * What the wizard offers on its Source stage: every sample, whether it can start a skeleton,
 * and a plain count of what that skeleton would bring in.
 *
 * @return array<string, array<string, mixed>>
 */
function pl_sample_skeleton_sources(): array
{
    $sources = [];
    foreach (pl_sample_structure_ids() as $id) {
        $sample = pl_demo_sample($id);
        $structure = pl_sample_structure_read($id);
        $sources[$id] = [
            'id' => $id,
            'name' => (string) $sample['name'],
            'business' => (string) ($sample['business'] ?? ''),
            'start_date' => (string) $sample['start_date'],
            'has_history' => ($sample['kind'] ?? '') !== 'starter_playground',
            'skeleton' => $structure !== null,
            'summary' => $structure === null ? [] : pl_sample_structure_summary($structure),
        ];
    }
    return $sources;
}

/**
 * Plain counts for the Source and Review stages. Reports are not stored by a structure: a
 * report is available because its module is, so the list is read back from the manifests and
 * cannot drift from what the business will actually see.
 *
 * @return array<string, mixed>
 */
function pl_sample_structure_summary(array $structure): array
{
    $registry = pl_module_registry();
    $reports = [];
    foreach ($structure['modules'] as $module) {
        foreach ($registry[$module]['reports'] as $report) { $reports[] = $report; }
    }
    return [
        'accounts' => count($structure['accounts']),
        'modules' => $structure['modules'],
        'packages' => $structure['packages'],
        'reports' => array_values(array_unique($reports)),
        'series' => array_map(static fn (array $s): string => (string) $s['type'], $structure['number_series']),
        'parties' => count($structure['parties']),
        'products' => count($structure['products']),
        'tax_codes' => count($structure['tax_codes']),
        'policies' => $structure['policies'] !== [],
    ];
}

/**
 * Activate the packages a sample's structure needs (decision 5 of the onboarding frames:
 * automatic, because a skeleton whose packages are absent shows routes that go nowhere).
 *
 * This runs OUTSIDE the skeleton's ledger transaction on purpose. `pl_plugin_activate()` runs
 * a package's own migrations before its state transaction, because schema changes cannot be
 * rolled back; calling it inside an open accounting transaction would put DDL inside one.
 *
 * The plugin runtime itself is a separate branch. Until it is present this reports
 * `runtime_unavailable` for each named package rather than guessing, and the caller shows that
 * outcome instead of claiming an activation that did not happen.
 *
 * @param list<string> $slugs
 * @return array<string, string> slug => activated|already_active|not_installed|runtime_unavailable|refused
 */
function pl_sample_structure_activate_packages(int $actorId, array $slugs, string $reason, string $keyPrefix): array
{
    $outcome = [];
    foreach ($slugs as $slug) {
        if (!function_exists('pl_plugin_activate') || !function_exists('pl_plugin_record')) {
            $outcome[$slug] = 'runtime_unavailable';
            continue;
        }
        try {
            $record = pl_plugin_record($slug);
            if ($record === null) {
                $outcome[$slug] = 'not_installed';
                continue;
            }
            if (($record['status'] ?? '') === 'active') {
                $outcome[$slug] = 'already_active';
                continue;
            }
            pl_plugin_activate($actorId, $slug, $reason, $keyPrefix . $slug);
            $outcome[$slug] = 'activated';
        } catch (DomainException) {
            // A package that refuses to activate is not a reason to lose the business that was
            // just created. It is reported, and Admin > Packages is where it is resolved.
            $outcome[$slug] = 'refused';
        }
    }
    return $outcome;
}

/**
 * Create one sample's structure in a new, empty book, with nothing posted.
 *
 * Every write goes through the ordinary service for that thing — accounts through
 * `pl_save_account()`, modules through `pl_set_company_module()`, numbering through
 * `pl_save_document_series()`, parties, products and tax codes through theirs — so a skeleton
 * can only contain what a person could have entered by hand. Nothing is written directly.
 *
 * @return array<string, mixed> the receipt this import recorded
 */
function pl_import_sample_skeleton(int $actorId, int $companyId, int $bookId, string $sampleId, string $requestKey): array
{
    pl_demo_require_setup_action();
    $requestKey = pl_request_key($requestKey);
    $structure = pl_sample_structure_read($sampleId);
    if ($structure === null) {
        throw new DomainException('This sample company does not publish a structure yet, so it cannot start a business.');
    }
    $sample = pl_demo_sample($sampleId);
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $structure, $sample, $requestKey): array {
        pl_require_company_access($actorId, $companyId, true);
        pl_ledger_book($companyId, $bookId, true);
        $company = pl_company_context($actorId, $companyId);
        if ($company['setup_status'] !== 'ready') {
            throw new DomainException('A skeleton is brought into a business whose setup is complete.');
        }
        foreach (['pl_journals', 'pl_documents', 'pl_general_drafts', 'pl_ar_documents', 'pl_products'] as $table) {
            if (DB::queryFirstField('SELECT id FROM %b WHERE company_id = %i AND book_id = %i LIMIT 1', $table, $companyId, $bookId)) {
                throw new DomainException('A skeleton is brought into a new empty business. Existing records are never replaced.');
            }
        }
        $prior = DB::queryFirstRow('SELECT * FROM pl_sample_imports WHERE company_id = %i AND request_key = %s FOR UPDATE', $companyId, $requestKey);
        if ($prior) {
            if (!hash_equals((string) $prior['structure_digest'], (string) $structure['digest'])) {
                throw new DomainException('This setup request already brought in a different sample structure.');
            }
            return pl_sample_import_receipt($prior);
        }
        $reason = 'Structure brought in from the ' . $sample['name'] . ' sample. No transaction and no balance is copied.';
        $prefix = 'skeleton:' . $structure['id'] . ':' . $structure['version'] . ':';
        $mapping = pl_account_code_mapping($company['accounts']);

        foreach ($structure['accounts'] as $definition) {
            // A structure names only what an account IS. `is_active` is decided here, never by
            // the file, so a structure cannot hand a business a chart of disabled accounts.
            $account = pl_save_account($actorId, $companyId, $bookId, [
                'code' => $definition['code'], 'name' => $definition['name'], 'type' => $definition['type'],
                'role' => $definition['role'] ?? null, 'is_contra' => ($definition['is_contra'] ?? false) === true,
                'is_active' => true, 'reason' => $reason, 'creation_key' => $prefix . 'account:' . $definition['code'],
            ] + (isset($definition['report_classification']) ? ['report_classification' => $definition['report_classification']] : []));
            $mapping[(string) $definition['code']] = (int) $account['id'];
        }
        $resolve = static function (string $code) use ($mapping): int {
            if (!isset($mapping[$code])) {
                throw new DomainException('This sample structure refers to the account ' . $code . ', which its own chart does not create.');
            }
            return $mapping[$code];
        };

        // Registry order is dependency order, and enabling a module whose dependency is still
        // off is refused, so the structure's own listing order is never trusted here.
        $registry = pl_module_registry();
        foreach (array_keys($registry) as $module) {
            if (!in_array($module, $structure['modules'], true)) { continue; }
            pl_set_company_module($actorId, $companyId, $module, true, 0, $registry[$module]['digest'], $reason, $prefix . 'module:' . $module);
        }
        foreach ($structure['open_item_accounts'] as $code) {
            pl_activate_open_item_account($actorId, $companyId, $bookId, $resolve((string) $code), $reason);
        }
        foreach ($structure['number_series'] as $series) {
            $current = pl_document_series_view(pl_document_series_row($actorId, $companyId, $bookId, (string) $series['type'], true));
            pl_save_document_series($actorId, $companyId, $bookId, (string) $series['type'], [
                'prefix' => $series['prefix'], 'padding' => $series['padding'], 'year_segment' => $series['year_segment'],
                'reset_rule' => $series['reset_rule'], 'next_number' => $current['next_number'],
                'revision' => $current['revision'], 'reason' => $reason,
            ]);
        }
        if ($structure['policies'] !== []) {
            $policies = pl_trading_policy_defaults();
            foreach ($structure['policies'] as $name => $value) {
                if ($name === 'discount_account_code' || $name === 'free_goods_account_code') { continue; }
                if (!array_key_exists($name, $policies) || str_ends_with($name, '_account_id')) {
                    throw new DomainException('A sample structure sets only the named accounting policies.');
                }
                $policies[$name] = $value;
            }
            foreach (['discount_account_code' => 'discount_account_id', 'free_goods_account_code' => 'free_goods_account_id'] as $key => $field) {
                if (isset($structure['policies'][$key])) { $policies[$field] = $resolve((string) $structure['policies'][$key]); }
            }
            pl_save_trading_policies($actorId, $companyId, $bookId, $policies + [
                'revision' => pl_trading_policies($actorId, $companyId, $bookId)['revision'],
                'reason' => $reason, 'idempotency_key' => $prefix . 'policies',
            ]);
        }
        if (($structure['company_profile']['footer_terms'] ?? '') !== '') {
            $profile = pl_company_profile($actorId, $companyId);
            pl_save_company_profile($actorId, $companyId, array_intersect_key($profile, array_flip(pl_company_profile_fields()))
                + ['footer_terms' => $structure['company_profile']['footer_terms'], 'revision' => $profile['revision'],
                    'reason' => $reason, 'idempotency_key' => $prefix . 'profile']);
        }
        $parties = [];
        foreach ($structure['parties'] as $index => $party) {
            $created = pl_save_party($actorId, $companyId, $bookId, [
                'legal_name' => $party['legal_name'], 'trading_name' => $party['trading_name'] ?? '',
                'entity_type' => $party['entity_type'] ?? 'private_company', 'country_code' => $party['country_code'] ?? 'ZZ',
                'is_customer' => $party['is_customer'], 'is_vendor' => $party['is_vendor'],
                'currency' => $company['currency'],
                'ar_account_id' => isset($party['ar_code']) ? $resolve((string) $party['ar_code']) : null,
                'ap_account_id' => isset($party['ap_code']) ? $resolve((string) $party['ap_code']) : null,
                'notes' => (string) ($party['notes'] ?? ''),
                'reason' => $reason, 'request_key' => $prefix . 'party:' . $index,
            ]);
            $parties[] = (int) $created['id'];
        }
        $products = [];
        foreach ($structure['products'] as $product) {
            $created = pl_save_inventory_product($actorId, $companyId, $bookId, [
                'sku' => $product['sku'], 'name' => $product['name'], 'kind' => $product['kind'],
                'base_unit' => $product['base_unit'] ?? 'each', 'selling_price' => $product['selling_price'] ?? '0.0000',
                'is_active' => true,
                'inventory_account_id' => isset($product['inventory_code']) ? $resolve((string) $product['inventory_code']) : null,
                'cogs_account_id' => isset($product['cogs_code']) ? $resolve((string) $product['cogs_code']) : null,
                'sales_account_id' => isset($product['sales_code']) ? $resolve((string) $product['sales_code']) : null,
                'purchase_account_id' => isset($product['purchase_code']) ? $resolve((string) $product['purchase_code']) : null,
                'reason' => $reason, 'idempotency_key' => $prefix . 'product:' . $product['sku'],
            ]);
            $products[] = (int) $created['id'];
        }
        $taxCodes = [];
        foreach ($structure['tax_codes'] as $tax) {
            $created = pl_create_tax_code($actorId, $companyId, $bookId, [
                'code' => $tax['code'], 'name' => $tax['name'], 'treatment' => $tax['treatment'] ?? 'standard',
                'sales_account_id' => $resolve((string) $tax['sales_code']),
                'purchase_account_id' => $resolve((string) $tax['purchase_code']),
                'reason' => $reason, 'idempotency_key' => $prefix . 'tax:' . $tax['code'],
            ]);
            pl_enter_tax_rate($actorId, $companyId, $bookId, [
                'tax_code_id' => $created['id'], 'effective_from' => $company['start_date'],
                'percentage' => $tax['percentage'], 'reason' => $reason,
                'idempotency_key' => $prefix . 'tax-rate:' . $tax['code'],
            ]);
            $taxCodes[] = (int) $created['id'];
        }

        // The promise this whole feature makes: structure, and not one unit of money.
        pl_sample_skeleton_assert_empty($actorId, $companyId, $bookId);

        $created = [
            'accounts' => count($structure['accounts']), 'modules' => $structure['modules'],
            'packages' => $structure['packages'], 'number_series' => array_column($structure['number_series'], 'type'),
            'parties' => count($parties), 'products' => count($products), 'tax_codes' => count($taxCodes),
            'policies' => $structure['policies'] !== [],
        ];
        DB::insert('pl_sample_imports', [
            'company_id' => $companyId, 'book_id' => $bookId, 'mode' => 'skeleton',
            'sample_id' => $structure['id'], 'sample_version' => $structure['version'],
            'sample_digest' => (string) $sample['digest'], 'structure_origin' => $structure['origin'],
            'structure_digest' => (string) $structure['digest'], 'request_key' => $requestKey,
            'created_json' => json_encode($created, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'imported_by' => $actorId,
        ]);
        // The append-only installation record every other setup fact already lives in.
        pl_record_installation_history($actorId, $companyId, $bookId, 'skeleton', pl_starter_template(), json_encode([
            'sample_skeleton' => ['id' => $structure['id'], 'version' => $structure['version'],
                'digest' => $structure['digest'], 'origin' => $structure['origin'],
                'sample_digest' => $sample['digest'], 'created' => $created, 'transactions' => 0],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        return pl_sample_import_receipt(DB::queryFirstRow('SELECT * FROM pl_sample_imports WHERE company_id = %i AND request_key = %s', $companyId, $requestKey));
    });
}

/**
 * A skeleton posts nothing. This is checked against the book, not against the importer's own
 * bookkeeping, so a service that quietly started posting would be caught here.
 */
function pl_sample_skeleton_assert_empty(int $actorId, int $companyId, int $bookId): void
{
    foreach (['pl_journals', 'pl_documents', 'pl_general_drafts'] as $table) {
        if ((int) DB::queryFirstField('SELECT COUNT(*) FROM %b WHERE company_id = %i AND book_id = %i', $table, $companyId, $bookId) !== 0) {
            throw new RuntimeException('A skeleton import must create no transactions; the entire setup was rolled back.');
        }
    }
    if ((int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journal_lines WHERE book_id = %i', $bookId) !== 0) {
        throw new RuntimeException('A skeleton import must create no journal lines; the entire setup was rolled back.');
    }
    $trial = pl_trial_balance($actorId, $companyId, $bookId);
    if (!$trial['balanced'] || $trial['total_debit'] !== '0.0000' || $trial['total_credit'] !== '0.0000') {
        throw new RuntimeException('A skeleton import must leave every balance at zero; the entire setup was rolled back.');
    }
}

/** @return array<string, mixed> */
function pl_sample_import_receipt(array $row): array
{
    return [
        'id' => (int) $row['id'], 'company_id' => (int) $row['company_id'], 'book_id' => (int) $row['book_id'],
        'mode' => (string) $row['mode'], 'sample_id' => (string) $row['sample_id'],
        'sample_version' => (string) $row['sample_version'], 'structure_origin' => (string) $row['structure_origin'],
        'structure_digest' => (string) $row['structure_digest'],
        'created' => json_decode((string) $row['created_json'], true, 32, JSON_THROW_ON_ERROR),
        'imported_at' => (string) $row['imported_at'],
    ];
}

/** The skeleton a company was started from, for the Ready stage and for support. */
function pl_company_sample_import(int $actorId, int $companyId, int $bookId): ?array
{
    pl_require_company_access($actorId, $companyId);
    pl_ledger_book($companyId, $bookId);
    $row = DB::queryFirstRow('SELECT * FROM pl_sample_imports WHERE company_id = %i AND book_id = %i ORDER BY id DESC LIMIT 1', $companyId, $bookId);
    return $row ? pl_sample_import_receipt($row) : null;
}
