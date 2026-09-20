<?php
declare(strict_types=1);

function pl_starter_template(): array
{
    $file = PL_ROOT . '/resources/coa/core-starter-1.1.0.json';
    $source = file_get_contents($file);
    if ($source === false) {
        throw new RuntimeException('The bundled starter chart is unavailable.');
    }
    $template = json_decode($source, true, 512, JSON_THROW_ON_ERROR);
    $template['digest'] = hash('sha256', $source);
    return $template;
}

/** Entity choices guide setup copy only; they do not enable regional tax rules. */
function pl_setup_entity_type_options(): array
{
    return [
        'individual' => 'Individual / sole proprietor',
        'aop' => 'AOP / partnership / association',
        'company' => 'Company / incorporated business',
        'other' => 'Other or not sure',
    ];
}

/** Common year-end choices keep the stored accounting contract as MM-DD. */
function pl_fiscal_year_end_options(): array
{
    return [
        '06-30' => '30 June — common Pakistan year end',
        '12-31' => '31 December — calendar year',
        '03-31' => '31 March — alternative year end',
        '09-30' => '30 September — alternative year end',
        'custom' => 'Another year end — enter a custom date',
    ];
}

function pl_fiscal_year_end_label(string $fiscalYearEnd): string
{
    $options = pl_fiscal_year_end_options();
    return $options[$fiscalYearEnd] ?? $fiscalYearEnd . ' — custom year end';
}

function pl_request_key(string $key): string
{
    if (!preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $key)) {
        throw new DomainException('The request identity is missing or invalid. Refresh the form.');
    }
    return $key;
}

/** A durable current read makes readiness enforceable outside browser routes too. */
function pl_require_book_ready(int $companyId): void
{
    $state = DB::queryFirstField('SELECT setup_status FROM pl_companies WHERE id = %i FOR SHARE', $companyId);
    if ($state !== 'ready') {
        throw new DomainException($state === 'opening_required'
            ? 'Opening balances and any unpaid invoices or bills must be reconciled through the opening cutover preview before posting.'
            : 'Review the existing chart and opening balances before posting.');
    }
}

function pl_install_template_snapshot(int $actorId, int $companyId, int $bookId, array $template, array $mapping): void
{
    $snapshot = json_encode(['template' => $template, 'account_mapping' => $mapping], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    DB::insert('pl_template_installations', [
        'company_id' => $companyId, 'book_id' => $bookId,
        'template_id' => $template['id'], 'template_version' => $template['version'], 'template_digest' => $template['digest'],
        'snapshot' => $snapshot,
        'confirmed_by' => $actorId,
    ]);
    pl_record_installation_history($actorId, $companyId, $bookId, 'chart', $template, $snapshot);
}

/** Record a complete installation fact without changing the original chart row. */
function pl_record_installation_history(int $actorId, int $companyId, int $bookId, string $kind, array $template, string $snapshot): void
{
    if (!in_array($kind, ['chart', 'sample'], true)) {
        throw new DomainException('Unknown installation history snapshot kind.');
    }
    $digest = hash('sha256', $snapshot);
    DB::query(
        'INSERT IGNORE INTO pl_template_installation_history (company_id,book_id,snapshot_kind,template_id,template_version,template_digest,snapshot,snapshot_digest,confirmed_by) VALUES (%i,%i,%s,%s,%s,%s,%s,%s,%i)',
        $companyId, $bookId, $kind, $template['id'], $template['version'], $template['digest'], $snapshot, $digest, $actorId
    );
}

function pl_company_context(int $actorId, int $companyId): array
{
    $membership = pl_require_company_access($actorId, $companyId);
    $company = DB::queryFirstRow('SELECT c.*, b.id AS book_id, b.name AS book_name FROM pl_companies c JOIN pl_books b ON b.company_id = c.id WHERE c.id = %i', $companyId);
    if (!$company) {
        throw new DomainException('This company is not available.');
    }
    $company['id'] = (int) $company['id'];
    $company['book_id'] = (int) $company['book_id'];
    $company['is_sample'] = (bool) $company['is_sample'];
    $company['role'] = $membership['role'];
    unset($company['setup_request_key'], $company['setup_payload_hash']);
    $installed = DB::queryFirstRow('SELECT template_id AS id, template_version AS version, template_digest AS digest FROM pl_template_installations WHERE company_id = %i', $companyId);
    $company['template'] = $installed ?: null;
    $company['accounts'] = DB::query('SELECT id, code, legacy_code, name, type, semantic_key, role, is_active, is_contra FROM pl_accounts WHERE company_id = %i AND book_id = %i ORDER BY code', $companyId, $company['book_id']);
    foreach ($company['accounts'] as &$account) {
        $account['id'] = (int) $account['id'];
        $account['is_active'] = (bool) $account['is_active'];
        $account['is_contra'] = (bool) $account['is_contra'];
        $account['level'] = pl_account_code_is_valid((string) $account['code']) ? pl_account_code_level((string) $account['code']) : 'account';
    }
    unset($account);
    return $company;
}

function pl_list_companies(int $actorId): array
{
    $ids = DB::queryFirstColumn('SELECT m.company_id FROM pl_company_members m JOIN pl_users u ON u.id = m.user_id JOIN pl_companies c ON c.id = m.company_id WHERE m.user_id = %i AND u.is_active = 1 ORDER BY c.is_sample, c.name, c.id', $actorId);
    return array_map(static fn ($id): array => pl_company_context($actorId, (int) $id), $ids);
}

function pl_setup_company(int $actorId, array $input, string $requestKey): array
{
    pl_demo_require_setup_action();
    pl_request_key($requestKey);
    $template = pl_starter_template();
    $mode = $input['start_mode'] ?? '';
    if (!in_array($mode, ['fresh', 'existing', 'sample'], true)) {
        throw new DomainException('Choose a new business, existing business, or isolated sample.');
    }
    if ($mode === 'sample') {
        // Whatever form or request supplied the draft, production never creates sample books.
        pl_require_sample_companies_allowed();
    }
    if (!is_string($input['template_digest'] ?? null) || !hash_equals($template['digest'], $input['template_digest'])) {
        throw new DomainException('The starter chart changed. Review its latest preview before confirming.');
    }
    if ($mode === 'fresh' && ($input['zero_balances_confirmed'] ?? false) !== true) {
        throw new DomainException('Confirm this is a new business with no opening balances or unpaid documents.');
    }
    $chartChoice = $input['chart_choice'] ?? 'neutral';
    if (!in_array($chartChoice, ['neutral', 'bring_own'], true)
        || ($chartChoice === 'bring_own' && $mode !== 'existing')) {
        throw new DomainException('Choose the neutral starter chart, or bring an existing chart with past records.');
    }
    $canonical = [
        'name' => pl_ledger_text($input['name'] ?? null, 'Business name', 160),
        'currency' => pl_ledger_text($input['currency'] ?? null, 'Currency', 3),
        'start_date' => pl_ledger_date(pl_ledger_text($input['start_date'] ?? null, 'Accounting start date', 10)),
        'fiscal_year_end' => pl_ledger_text($input['fiscal_year_end'] ?? '12-31', 'Fiscal year end', 5),
        'start_mode' => $mode, 'chart_choice' => $chartChoice, 'template_digest' => $template['digest'],
    ];
    if (isset($input['sample_pack'])) {
        if ($mode !== 'sample' || !is_string($input['sample_pack'])) { throw new DomainException('Choose a sample pack only for a new isolated sample.'); }
        $pack = pl_demo_sample($input['sample_pack']);
        if ($canonical['start_date'] !== $pack['start_date'] || $canonical['fiscal_year_end'] !== '12-31') {
            throw new DomainException($pack['id'] === 'accounting-starter'
                ? 'The starter playground uses the current January practice-year start and December year end.'
                : 'Historical samples use the pinned 2024 start and December year end.');
        }
        $canonical['sample_pack'] = $pack['id'];
        $canonical['sample_digest'] = $pack['digest'];
    }
    $hash = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    return pl_ledger_transaction(function () use ($actorId, $canonical, $requestKey, $hash): array {
        // Serialize setup requests by actor, including simultaneous first submissions.
        if (!DB::queryFirstRow('SELECT id FROM pl_users WHERE id = %i AND is_active = 1 FOR UPDATE', $actorId)) {
            throw new DomainException('Sign in with an active account to create a company.');
        }
        $existing = DB::queryFirstRow('SELECT id, setup_payload_hash FROM pl_companies WHERE created_by = %i AND setup_request_key = %s FOR UPDATE', $actorId, $requestKey);
        if ($existing) {
            if (!hash_equals((string) $existing['setup_payload_hash'], $hash)) {
                throw new DomainException('This setup request was already used with different business details. Start a new setup.');
            }
            return pl_company_context($actorId, (int) $existing['id']);
        }
        $created = pl_create_company($actorId, $canonical['name'], $canonical['currency'], $canonical['start_date'], $canonical['fiscal_year_end']);
        DB::update('pl_companies', [
            'is_sample' => $canonical['start_mode'] === 'sample' ? 1 : 0,
            'setup_status' => $canonical['start_mode'] === 'existing' ? 'opening_required' : 'ready',
            'setup_request_key' => $requestKey, 'setup_payload_hash' => $hash,
        ], 'id = %i', $created['company_id']);
        if ($canonical['start_mode'] === 'sample') {
            if (($canonical['sample_pack'] ?? '') === 'accounting-starter') { pl_seed_demo_starter_playground($actorId, $created['company_id'], $created['book_id']); }
            elseif (isset($canonical['sample_pack'])) { pl_seed_demo_pack($actorId, $created['company_id'], $created['book_id'], $canonical['sample_pack']); }
            else { pl_seed_core_sample($actorId, $created['company_id'], $created['book_id']); }
        }
        return pl_company_context($actorId, $created['company_id']);
    });
}

/** Prior proof records retain IDs, names and posted entries; only explicit role bindings are added. */
function pl_confirm_existing_setup(int $actorId, int $companyId, int $bookId, array $roleAssignments, bool $balancesReviewed): array
{
    pl_demo_require_setup_action();
    if (!$balancesReviewed) {
        throw new DomainException('Confirm that you reviewed the existing books and opening balances.');
    }
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $roleAssignments): array {
        pl_require_company_access($actorId, $companyId, true);
        pl_ledger_book($companyId, $bookId, true);
        $company = DB::queryFirstRow('SELECT setup_status FROM pl_companies WHERE id = %i FOR UPDATE', $companyId);
        if (!$company || $company['setup_status'] !== 'review_required') {
            throw new DomainException('This company does not need the prior-foundation review. Opening-data migration cannot be skipped here.');
        }
        $template = pl_starter_template();
        if (count($roleAssignments) !== count($template['accounts']) || count(array_unique($roleAssignments)) !== count($roleAssignments)) {
            throw new DomainException('Map each starter purpose to a separate existing account.');
        }
        $mapping = [];
        foreach ($template['accounts'] as $definition) {
            $id = $roleAssignments[$definition['semantic_key']] ?? null;
            if (!is_int($id) || !DB::queryFirstRow('SELECT id FROM pl_accounts WHERE id = %i AND company_id = %i AND book_id = %i AND type = %s AND is_active = 1 FOR UPDATE', $id, $companyId, $bookId, $definition['type'])) {
                throw new DomainException('Every mapped account must be active, have the correct type, and belong to this book.');
            }
            $mapping[$definition['semantic_key']] = $id;
        }
        foreach ($template['accounts'] as $definition) {
            DB::update('pl_accounts', ['semantic_key' => $definition['semantic_key'], 'role' => $definition['role']], 'id = %i', $mapping[$definition['semantic_key']]);
        }
        pl_install_template_snapshot($actorId, $companyId, $bookId, $template, $mapping);
        DB::update('pl_companies', ['setup_status' => 'ready'], 'id = %i', $companyId);
        return pl_company_context($actorId, $companyId);
    });
}

/** Loads only into a newly created, empty, explicitly isolated sample context. */
function pl_seed_core_sample(int $actorId, int $companyId, int $bookId): void
{
    pl_ledger_transaction(function () use ($actorId, $companyId, $bookId): void {
        pl_require_company_access($actorId, $companyId, true);
        pl_ledger_book($companyId, $bookId, true);
        $company = pl_company_context($actorId, $companyId);
        if (!$company['is_sample'] || $company['setup_status'] !== 'ready') {
            throw new DomainException('Sample data can only be loaded into an isolated sample company.');
        }
        if ((int) DB::queryFirstField('SELECT COUNT(*) FROM pl_documents WHERE book_id = %i', $bookId) > 0
            || (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id = %i', $bookId) > 0) {
            throw new DomainException('A sample must be created in a new empty company. Existing records cannot be replaced.');
        }
        $path = PL_ROOT . '/resources/core-samples/core-accounting-1.0.0.json';
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('The core sample pack is unavailable.');
        }
        $sample = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (($sample['demo_only'] ?? false) !== true || ($sample['id'] ?? '') !== 'core-accounting' || ($sample['version'] ?? '') !== '1.0.0') {
            throw new RuntimeException('The core sample identity is invalid.');
        }
        $accounts = array_column($company['accounts'], 'id', 'semantic_key');
        foreach (['posted', 'drafts'] as $group) {
            foreach ($sample[$group] as $item) {
                $document = pl_save_document($actorId, $companyId, $bookId, [
                    'kind' => $item['kind'], 'date' => $company['start_date'], 'amount' => $item['amount'],
                    'money_account_id' => $accounts['core.cash_bank'],
                    'category_account_id' => $accounts[$item['kind'] === 'receipt' ? 'core.income.sales' : 'core.expense.general'],
                    'counterparty' => $item['counterparty'], 'reference' => $item['reference'], 'memo' => $item['memo'],
                    'creation_key' => 'sample:' . $sample['id'] . ':' . $sample['version'] . ':' . $item['key'],
                ]);
                if ($group === 'posted') {
                    pl_post_document($actorId, $companyId, $bookId, $document['id'], $document['revision']);
                }
            }
        }
        $actual = pl_trial_balance($actorId, $companyId, $bookId);
        $balances = array_column($actual['accounts'], 'balance', 'id');
        $drafts = pl_list_documents($actorId, $companyId, $bookId, ['status' => 'draft']);
        if (!$actual['balanced'] || $balances[$accounts['core.cash_bank']] !== $sample['expected']['bank']
            || bcsub('0', $balances[$accounts['core.income.sales']], 4) !== $sample['expected']['income_credit']
            || $balances[$accounts['core.expense.general']] !== $sample['expected']['expense_debit']
            || $drafts['total_amount'] !== $sample['expected']['draft_total'] || $drafts['total'] !== count($sample['drafts'])) {
            throw new RuntimeException('The sample pack did not reconcile; the entire setup was rolled back.');
        }
        // Pin the exact sample pack in append-only installation history;
        // the original chart snapshot remains unchanged.
        $snapshot = json_encode(['sample_pack' => ['id' => $sample['id'], 'version' => $sample['version'], 'digest' => hash('sha256', $contents), 'date' => $company['start_date'], 'currency' => $company['currency']]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        pl_record_installation_history($actorId, $companyId, $bookId, 'sample', pl_starter_template(), $snapshot);
        $manifest = pl_module_registry()['pos-showcase'];
        pl_set_company_module($actorId, $companyId, 'pos-showcase', true, 0, $manifest['digest'], 'Explicit isolated sample includes the cash POS showcase.', 'sample-pos');
    });
}
