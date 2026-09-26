<?php
declare(strict_types=1);

/**
 * The bundled chart every new book is created from.
 *
 * A chart package has two lists and they are not interchangeable. `accounts`
 * are the postable accounts: each carries a `semantic_key`, so the setup review
 * can map a starter purpose onto an existing account and `pl_owner_accounts()`
 * can find capital and drawings. `headings` are the class and group names —
 * `X-000-00000-00` and `X-GGG-00000-00` — which receive no posting and exist so
 * a report says "Cash and Cash Equivalents" where it used to say "Group 1-100".
 * Keeping the two lists apart is what stops a heading appearing among the
 * thirteen purposes the prior-foundation review asks the owner to map.
 *
 * A heading carries a `semantic_key` of its own (`core.group.asset.ppe`), which
 * is how a module finds the group it needs — never by the heading's name, which
 * a translation or a rename changes, and never by its number, which migration
 * 036 allocated from each chart's own groups and which therefore differs book by
 * book. `pl_account_heading_by_key()` is the one resolver, and it says how it
 * found what it returned. A heading carries no `role`: a role names where a
 * posting goes, `pl_save_account()` refuses one on a heading code, and a second
 * active account with `receivables`, `payables` or an advances role would make
 * `pl_ar_control()` and `pl_advance_control()` refuse every document for want of
 * "one unambiguous default".
 *
 * `core-starter-1.1.0.json` and `core-starter-1.0.0.json` are left byte for byte
 * as they shipped: a book records the digest of the chart it was installed from,
 * so a chart's bytes are never rewritten under a version number that is already
 * in someone's `pl_template_installations`. Headings therefore arrive as a new
 * chart version for a new book, and as migration 045 for an existing one.
 */
function pl_starter_template(): array
{
    $file = PL_ROOT . '/resources/coa/core-starter-1.2.0.json';
    $source = file_get_contents($file);
    if ($source === false) {
        throw new RuntimeException('The bundled starter chart is unavailable.');
    }
    $template = json_decode($source, true, 512, JSON_THROW_ON_ERROR);
    $template['headings'] = pl_starter_template_headings($template);
    $template['provisions'] = pl_starter_template_provisions($template);
    $template['digest'] = hash('sha256', $source);
    return $template;
}

/**
 * The chart package's provisioned accounts: postable leaves a module posts to, that are not
 * starter purposes an owner is asked to map.
 *
 * A package has three lists, and the difference between them is what each one is for.
 * `accounts` are the starter purposes — the thirteen places the core itself posts, which
 * `pl_confirm_existing_setup()` makes the owner of a prior-foundation book map one by one onto
 * an account that book already has. `headings` are names (1.3 M15). `provisions` is the third:
 * a real postable account a module needs somewhere to post, which a prior-foundation chart has
 * no equivalent of and which it would therefore be wrong to demand a mapping for. Cash over and
 * short is the first: an existing set of books has no such account, and forcing it onto General
 * expenses in the review would silently misclassify every drawer difference from then on.
 *
 * They are created with a new book the same way the starter purposes are, they carry their own
 * `semantic_key` (B88) and they stay out of the starter-purpose `$mapping`. A book that has none
 * — a prior-foundation chart, or a hand-built one — provisions it on demand instead, the way
 * `pl_asset_provision_accounts()` does.
 *
 * @param array<string, mixed> $template
 * @return array<int, array{code: string, name: string, type: string, semantic_key: string}>
 */
function pl_starter_template_provisions(array $template): array
{
    $provisions = [];
    $keys = array_merge(array_column($template['accounts'] ?? [], 'semantic_key'), array_column($template['headings'] ?? [], 'semantic_key'));
    foreach ($template['provisions'] ?? [] as $definition) {
        $code = (string) ($definition['code'] ?? '');
        $name = trim((string) ($definition['name'] ?? ''));
        $type = (string) ($definition['type'] ?? '');
        $key = (string) ($definition['semantic_key'] ?? '');
        // The opposite check to a heading's: this one IS posted to, so a heading code here would
        // be refused by pl_account_is_postable() at every posting rather than at install time.
        if (!pl_account_code_is_valid($code) || pl_account_code_is_heading($code) || !pl_account_code_matches_type($code, $type)) {
            throw new RuntimeException('The bundled chart provisions ' . $code . ', which is not a postable account code of its classification.');
        }
        if ($name === '' || $key === '' || in_array($key, $keys, true)) {
            throw new RuntimeException('The bundled chart provision ' . $code . ' needs a name and a semantic key of its own; a module finds it by that key, never by its name or its number.');
        }
        // B88 keeps `core.group.*` for headings, and a provisioned account is a leaf.
        if (str_starts_with($key, 'core.group.') || str_starts_with($key, 'core.class.')) {
            throw new RuntimeException('The bundled chart provision ' . $code . ' claims a heading namespace. A leaf account takes a leaf key.');
        }
        if (($definition['role'] ?? null) !== null) {
            throw new RuntimeException('The bundled chart provision ' . $code . ' carries an operational role. pl_ar_control() and pl_advance_control() need exactly one account per role; a provisioned account is found by its key.');
        }
        $keys[] = $key;
        $provisions[] = ['code' => $code, 'name' => $name, 'type' => $type, 'semantic_key' => $key];
    }
    return $provisions;
}

/**
 * The chart package's heading rows, checked to be what they claim to be.
 *
 * A heading that is not a heading code, or whose class digit disagrees with its
 * classification, would be installed as an ordinary account and could then be
 * posted to. That is a chart-package defect, so it stops here rather than
 * reaching a book.
 *
 * @param array<string, mixed> $template
 * @return array<int, array{code: string, name: string, type: string, semantic_key: string}>
 */
function pl_starter_template_headings(array $template): array
{
    $headings = [];
    $keys = array_column($template['accounts'] ?? [], 'semantic_key');
    foreach ($template['headings'] ?? [] as $definition) {
        $code = (string) ($definition['code'] ?? '');
        $name = trim((string) ($definition['name'] ?? ''));
        $type = (string) ($definition['type'] ?? '');
        $key = (string) ($definition['semantic_key'] ?? '');
        if (!pl_account_code_is_valid($code) || !pl_account_code_is_heading($code)) {
            throw new RuntimeException('The bundled chart lists ' . $code . ' as a heading, but it is not a class or group code.');
        }
        if ($name === '' || !pl_account_code_matches_type($code, $type)) {
            throw new RuntimeException('The bundled chart heading ' . $code . ' needs a name and a matching classification.');
        }
        // Without a key the heading is invisible to every module that looks for its group, and
        // `uq_account_semantic (book_id, semantic_key)` means a key used twice cannot be installed
        // at all. Both are chart-package defects, so they stop here rather than reaching a book.
        if ($key === '' || in_array($key, $keys, true)) {
            throw new RuntimeException('The bundled chart heading ' . $code . ' needs a semantic key of its own; a module finds a group by that key, never by its name or its number.');
        }
        if (($definition['role'] ?? null) !== null) {
            throw new RuntimeException('The bundled chart heading ' . $code . ' carries an operational role. A role names where a posting goes, and a heading takes none.');
        }
        $keys[] = $key;
        $headings[] = ['code' => $code, 'name' => $name, 'type' => $type, 'semantic_key' => $key];
    }
    return $headings;
}

/**
 * The class or group heading one module is looking for, or null, saying how it was found.
 *
 * The recognition mechanism is `semantic_key`, because it is the only one that survives what
 * actually happens to a chart: a name is translated or edited by its owner, and a group *number*
 * was allocated by migration 036 from each chart's own groups, so `1-200` means Property, Plant
 * and Equipment in a book born on the bundled chart and something else entirely in a converted one.
 *
 * It falls back rather than refusing, because a chart may legitimately have no keyed heading: one
 * built by hand, one from a package older than these keys, or one whose group migration 045 would
 * not name because two starter purposes shared it. The fallback is the code the *bundled* chart
 * uses for that key, which is right for a book created from it and is skipped for anything else —
 * and `matched_by` says which of the two happened, so a caller can tell the owner what it assumed
 * instead of assuming it silently. A caller that gets null asks the owner to name the group; it
 * does not stop working.
 *
 * @return array{id: int, code: string, name: string, matched_by: string}|null
 */
function pl_account_heading_by_key(int $companyId, int $bookId, string $semanticKey): ?array
{
    $row = DB::queryFirstRow('SELECT id, code, name FROM pl_accounts WHERE company_id = %i AND book_id = %i AND semantic_key = %s',
        $companyId, $bookId, $semanticKey);
    $matchedBy = 'semantic_key';
    if (!$row) {
        $bundled = null;
        foreach (pl_starter_template()['headings'] as $heading) {
            if ($heading['semantic_key'] === $semanticKey) { $bundled = $heading['code']; break; }
        }
        if ($bundled === null) { return null; }
        $row = DB::queryFirstRow('SELECT id, code, name FROM pl_accounts WHERE company_id = %i AND book_id = %i AND code = %s AND semantic_key IS NULL',
            $companyId, $bookId, $bundled);
        $matchedBy = 'bundled_code';
    }
    if (!$row || !pl_account_code_is_valid((string) $row['code']) || !pl_account_code_is_heading((string) $row['code'])) {
        return null;
    }
    return ['id' => (int) $row['id'], 'code' => (string) $row['code'], 'name' => (string) $row['name'], 'matched_by' => $matchedBy];
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
    // 'skeleton' arrived with migration 043: a business started from a sample's structure with
    // none of its transactions. It is a third way a book's structure can arrive, beside the
    // installed chart and a seeded sample pack.
    if (!in_array($kind, ['chart', 'sample', 'skeleton'], true)) {
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
    // A chart now carries its class and group headings, which are names rather than places to post.
    // `pl_post_journal()` refuses one in the central funnel; `is_postable` is what lets a screen
    // leave it out of an account picker instead of offering a choice the server will reject.
    $postable = pl_account_code_postable_map(array_map(static fn (array $row): string => (string) $row['code'], $company['accounts']));
    foreach ($company['accounts'] as &$account) {
        $account['id'] = (int) $account['id'];
        $account['is_active'] = (bool) $account['is_active'];
        $account['is_contra'] = (bool) $account['is_contra'];
        $account['level'] = pl_account_code_is_valid((string) $account['code']) ? pl_account_code_level((string) $account['code']) : 'account';
        $account['is_postable'] = $postable[(string) $account['code']] ?? true;
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
        // Ordinary installations create isolated practice books only from installed validated packages.
        pl_require_sample_companies_allowed();
        if (!pl_sample_repository_fallback() && !isset(pl_demo_pack_catalog()[(string)($input['sample_pack'] ?? '')]['package_slug'])) { throw new DomainException('Choose a validated installed sample package for a practice company.'); }
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
    // How much of a sample company this business starts from (B50). `blank` is the neutral
    // starter chart and nothing else, which is what every setup did before 1.2.1. `skeleton`
    // brings one sample's structure with none of its transactions, so it is a legitimate start
    // for a real business. `full` brings the structure and the fictional history with it, which
    // only ever belongs in an isolated sample company.
    //
    // A caller that names a sample without naming a source is the pre-1.2.1 contract — the
    // sample chooser and the public demo both do — and means the full sample.
    $source = $input['source'] ?? (isset($input['sample_pack']) ? 'full' : 'blank');
    if (!in_array($source, ['blank', 'skeleton', 'full'], true)) {
        throw new DomainException('Choose a blank chart, a sample company\'s structure, or a full sample company.');
    }
    if ($source === 'blank' && isset($input['sample_pack'])) {
        throw new DomainException('A blank business starts from the neutral chart, without a sample company.');
    }
    if ($source !== 'blank' && !is_string($input['sample_pack'] ?? null)) {
        throw new DomainException('Choose which sample company this business starts from.');
    }
    if ($source === 'full' && $mode !== 'sample') {
        throw new DomainException('A full sample carries fictional transactions, so it is only created as a separate sample company.');
    }
    if ($source === 'skeleton' && $mode === 'existing') {
        throw new DomainException('Past records bring their own chart through the opening cutover, so they start from a blank chart here.');
    }
    $canonical = [
        'name' => pl_ledger_text($input['name'] ?? null, 'Business name', 160),
        'currency' => pl_ledger_text($input['currency'] ?? null, 'Currency', 3),
        'start_date' => pl_ledger_date(pl_ledger_text($input['start_date'] ?? null, 'Accounting start date', 10)),
        'fiscal_year_end' => pl_ledger_text($input['fiscal_year_end'] ?? '12-31', 'Fiscal year end', 5),
        'start_mode' => $mode, 'chart_choice' => $chartChoice, 'source' => $source,
        'template_digest' => $template['digest'],
    ];
    if (isset($input['sample_pack'])) {
        // The source checks above have already refused a sample_pack that is not a string, and
        // refused a source that names no sample at all.
        $pack = pl_demo_sample((string) $input['sample_pack']);
        // A full sample replays a pinned history, so its book has to start where that history
        // starts. A skeleton replays nothing, so this business keeps the dates its owner chose.
        if ($source === 'full' && ($canonical['start_date'] !== $pack['start_date'] || $canonical['fiscal_year_end'] !== '12-31')) {
            throw new DomainException($pack['id'] === 'accounting-starter'
                ? 'The starter playground uses the current January practice-year start and December year end.'
                : 'Historical samples use the pinned 2024 start and December year end.');
        }
        if ($source === 'skeleton' && pl_sample_structure_read($pack['id']) === null) {
            throw new DomainException('This sample company does not publish a structure yet, so it cannot start a business.');
        }
        $canonical['sample_pack'] = $pack['id'];
        $canonical['sample_digest'] = $pack['digest'];
    }
    // The 1.4.5 wizard's additions (owner review of 25-26 September 2026): the registration
    // profile, the owners, the named bank and cash accounts with optional opening money, the
    // features to switch on, the account names to change and the cash policy. Every part is
    // optional to a caller, so the sample chooser and the demo keep their old contract; each is
    // checked here and applied after the book exists, inside the same transaction, so a refused
    // row rolls the whole setup back.
    $canonical['setup'] = pl_setup_extras($input, $canonical);
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
        if ($canonical['source'] === 'skeleton') {
            // Structure only. The importer proves the book is still empty and balanced at zero
            // before this transaction commits, for a real business and a sample alike.
            pl_import_sample_skeleton($actorId, $created['company_id'], $created['book_id'], (string) $canonical['sample_pack'], $requestKey);
        } elseif ($canonical['start_mode'] === 'sample') {
            if (($canonical['sample_pack'] ?? '') === 'accounting-starter') { pl_seed_demo_starter_playground($actorId, $created['company_id'], $created['book_id']); }
            elseif (isset($canonical['sample_pack'])) { pl_seed_demo_pack($actorId, $created['company_id'], $created['book_id'], $canonical['sample_pack']); }
            else { pl_seed_core_sample($actorId, $created['company_id'], $created['book_id']); }
        }
        pl_setup_apply_extras($actorId, (int) $created['company_id'], (int) $created['book_id'], $canonical, $requestKey);
        return pl_company_context($actorId, $created['company_id']);
    });
}

/**
 * Check the wizard's additions before anything is created. Blank rows are skipped; a row that
 * says something is held to it.
 *
 * @return array<string, mixed>
 */
function pl_setup_extras(array $input, array $canonical): array
{
    require_once __DIR__ . '/legal_form_functions.php';
    $extras = ['country_code' => '', 'legal_form' => '', 'profile' => [], 'owners' => [], 'money_accounts' => [],
        'features' => [], 'account_names' => [], 'cash_policy' => 'warning'];
    $country = strtoupper(pl_ledger_text($input['country_code'] ?? '', 'Country', 2, false));
    if ($country !== '' && !isset(pl_country_options()[$country])) {
        throw new DomainException('Choose a country from the list, or leave it empty.');
    }
    $form = pl_ledger_text($input['legal_form'] ?? '', 'Legal form', 40, false);
    if ($form !== '' && !pl_legal_form_known($form)) {
        throw new DomainException('Choose a legal form from the list, or leave it empty.');
    }
    $extras['country_code'] = $country;
    $extras['legal_form'] = $form;
    $profile = is_array($input['profile'] ?? null) ? $input['profile'] : [];
    $profileFields = ['legal_name' => ['Legal name', 200], 'registration_number' => ['Registration number', 80], 'registration_authority' => ['Registration authority', 160],
        'address_line1' => ['Address line 1', 200], 'address_line2' => ['Address line 2', 200], 'address_line3' => ['Address line 3', 200], 'phone' => ['Phone', 80],
        'email' => ['Email', 190], 'tax_registrations' => ['Tax registrations', 300], 'footer_terms' => ['Footer terms', 2000]];
    foreach ($profileFields as $field => [$label, $limit]) {
        $value = pl_ledger_text($profile[$field] ?? '', $label, $limit, false);
        if ($value !== '') { $extras['profile'][$field] = $value; }
    }
    $owners = $input['owners'] ?? [];
    if (!is_array($owners) || count($owners) > 20) {
        throw new DomainException('Register up to twenty owners during setup; more can be added later.');
    }
    foreach ($owners as $owner) {
        if (!is_array($owner)) { throw new DomainException('Review the owners before continuing.'); }
        $name = pl_ledger_text($owner['name'] ?? '', 'Owner name', 160, false);
        if ($name === '') { continue; }
        $share = pl_ledger_text($owner['share'] ?? '', 'Ownership share', 12, false);
        if ($share !== '' && !preg_match('/^(?:100(?:\.0+)?|[0-9]{1,2}(?:\.[0-9]{1,4})?)$/D', $share)) {
            throw new DomainException('Enter an ownership share between 0 and 100 for ' . $name . ', or leave it empty.');
        }
        $extras['owners'][] = ['name' => $name, 'kind' => ($owner['kind'] ?? 'person') === 'entity' ? 'entity' : 'person',
            'role' => pl_ledger_text($owner['role'] ?? '', 'Owner role', 80, false), 'share' => $share];
    }
    $rows = $input['money_accounts'] ?? [];
    if (!is_array($rows) || count($rows) > 20) {
        throw new DomainException('Name up to twenty bank and cash accounts during setup; more can be added later.');
    }
    if ($rows !== [] && $canonical['source'] === 'full') {
        throw new DomainException('A full sample brings its own bank and cash accounts.');
    }
    foreach ($rows as $row) {
        if (!is_array($row)) { throw new DomainException('Review the bank and cash accounts before continuing.'); }
        $name = pl_ledger_text($row['name'] ?? '', 'Account name', 120, false);
        $code = pl_ledger_text($row['code'] ?? '', 'Account code', 20, false);
        if ($name === '' && $code === '') { continue; }
        if ($name === '') { throw new DomainException('Give every bank or cash account a name.'); }
        $kind = $row['kind'] ?? 'bank';
        if (!in_array($kind, ['bank', 'till', 'petty'], true)) {
            throw new DomainException('Say whether each money account is a bank account, a till or petty cash.');
        }
        // A petty cash float belongs to a person, and a till to a place; the custodian is part of
        // the name, because the name is what every screen and report shows.
        $custodian = pl_ledger_text($row['custodian'] ?? '', 'Custodian', 80, false);
        if ($custodian !== '' && mb_stripos($name, $custodian, 0, 'UTF-8') === false) {
            $name = $name . ' — ' . $custodian;
        }
        if (mb_strlen($name, 'UTF-8') > 120) { throw new DomainException('The account name ' . $name . ' is too long.'); }
        $amountRaw = str_replace([',', ' '], '', pl_ledger_text($row['opening_amount'] ?? '', 'Opening amount', 30, false));
        $amount = $amountRaw === '' ? '0.0000' : pl_amount($amountRaw);
        $source = pl_ledger_text($row['opening_source'] ?? '', 'Opening source', 40, false);
        if (bccomp($amount, '0', 4) > 0) {
            if (!in_array($source, ['capital_introduced', 'owner_loan_received'], true)) {
                throw new DomainException('Say where the opening money in ' . $name . ' comes from: capital introduced, or an owner loan.');
            }
            if ($canonical['start_mode'] === 'existing') {
                throw new DomainException('Past records bring their balances through the opening cutover, so setup does not post opening money.');
            }
        } else {
            $source = '';
        }
        $extras['money_accounts'][] = ['kind' => $kind, 'code' => $code, 'name' => $name, 'opening_amount' => $amount, 'opening_source' => $source];
    }
    $codes = array_filter(array_column($extras['money_accounts'], 'code'));
    if (count($codes) !== count(array_unique($codes))) {
        throw new DomainException('Two bank or cash rows name the same account; keep one of them.');
    }
    // The starter's generic leaf is never left as a posting target under its generic name (owner
    // decision, 26 September 2026): the row that becomes it, whether by its code or as the first
    // row without one, must give it the name of a real account.
    $generic = pl_setup_generic_money_row($extras['money_accounts']);
    if ($generic !== null) {
        throw new DomainException('Give the starter\'s "' . $generic . '" account the name of a real account, for example the bank it is.');
    }
    $features = $input['features'] ?? [];
    if (!is_array($features) || !array_is_list($features)) { throw new DomainException('Choose valid features.'); }
    $registry = pl_module_registry();
    foreach ($features as $id) {
        if (!is_string($id) || !isset($registry[$id]) || !$registry[$id]['optional']) {
            throw new DomainException('Choose a known optional feature.');
        }
    }
    $extras['features'] = pl_setup_feature_closure($features, $registry);
    $names = $input['account_names'] ?? [];
    if (!is_array($names) || count($names) > 500) { throw new DomainException('Review the account names before creating this business.'); }
    foreach ($names as $code => $name) {
        if (!is_string($code) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,19}$/D', $code) || !is_string($name)) {
            throw new DomainException('An account name is incomplete.');
        }
        if (trim($name) === '') { continue; }
        $extras['account_names'][$code] = pl_ledger_text($name, 'Account name', 120);
    }
    ksort($extras['account_names']);
    $policy = $input['cash_policy'] ?? 'warning';
    if (!in_array($policy, ['warning', 'strict'], true)) {
        throw new DomainException('Choose warning only or strict cash and bank balance controls.');
    }
    $extras['cash_policy'] = $canonical['start_mode'] === 'sample' ? 'warning' : $policy;
    return $extras;
}

/**
 * The generic name of the starter's cash-and-bank leaf when a money row would keep it, else null.
 * Which row becomes the leaf follows pl_setup_apply_extras(): the row carrying its code, or
 * failing that the first row without a code.
 *
 * @param list<array{code:string, name:string}> $rows
 */
function pl_setup_generic_money_row(array $rows): ?string
{
    if ($rows === []) { return null; }
    $leaf = null;
    foreach (pl_starter_template()['accounts'] as $account) {
        if (($account['semantic_key'] ?? '') === 'core.cash_bank') { $leaf = $account; break; }
    }
    if ($leaf === null) { return null; }
    $claimant = null;
    foreach ($rows as $row) {
        if ($row['code'] === (string) $leaf['code']) { $claimant = $row; break; }
    }
    if ($claimant === null) {
        foreach ($rows as $row) {
            if ($row['code'] === '') { $claimant = $row; break; }
        }
    }
    if ($claimant !== null && mb_strtolower(trim($claimant['name']), 'UTF-8') === mb_strtolower((string) $leaf['name'], 'UTF-8')) {
        return (string) $leaf['name'];
    }
    return null;
}

/**
 * The chosen features plus every optional module they depend on, in registry order, which is
 * dependency order: enabling a module whose dependency is still off is refused by the service.
 *
 * @param list<string> $ids
 * @return list<string>
 */
function pl_setup_feature_closure(array $ids, array $registry): array
{
    $wanted = array_fill_keys($ids, true);
    do {
        $added = false;
        foreach (array_keys($wanted) as $id) {
            foreach (array_keys($registry[$id]['requires'] ?? []) as $dependency) {
                if (isset($registry[$dependency]) && $registry[$dependency]['optional'] && !isset($wanted[$dependency])) {
                    $wanted[$dependency] = true;
                    $added = true;
                }
            }
        }
    } while ($added);
    return array_values(array_filter(array_keys($registry), static fn (string $id): bool => isset($wanted[$id])));
}

/** The next free postable code under a group, so a named bank account lands beside the others. */
function pl_setup_next_account_code(int $companyId, int $bookId, string $group): string
{
    $codes = DB::queryFirstColumn('SELECT code FROM pl_accounts WHERE company_id = %i AND book_id = %i AND code LIKE %s', $companyId, $bookId, $group . '-%');
    $highest = 10000;
    foreach ($codes as $code) {
        if (preg_match('/^' . preg_quote($group, '/') . '-([0-9]{5})-00$/D', (string) $code, $match)) {
            $highest = max($highest, (int) $match[1]);
        }
    }
    if ($highest >= 99999) { throw new DomainException('The ' . $group . ' group has no free account number left.'); }
    return sprintf('%s-%05d-00', $group, $highest + 1);
}

/**
 * A partnership's profit-sharing ratios from the Owners stage, as six-decimal fractions keyed like
 * the owners: the shares as entered when every partner has one and they total at most 100, or an
 * equal split when none was entered. Half-entered shares are refused rather than guessed.
 *
 * @param list<array{name:string,kind:string,role:string,share:string}> $owners
 * @return array<int,string>
 */
function pl_setup_partner_shares(array $owners): array
{
    $count = count($owners);
    if ($count === 0) { return []; }
    $entered = array_filter($owners, static fn (array $owner): bool => $owner['share'] !== '');
    if ($entered === []) {
        $base = bcdiv('1', (string) $count, 6);
        $shares = array_fill(0, $count, $base);
        $shares[0] = bcsub('1', bcmul($base, (string) ($count - 1), 6), 6);
        return $shares;
    }
    if (count($entered) !== $count) {
        throw new DomainException('Enter a share for every partner, or leave all of them empty for an equal split.');
    }
    $shares = [];
    $total = '0';
    foreach ($owners as $index => $owner) {
        $shares[$index] = bcdiv($owner['share'], '100', 6);
        $total = bcadd($total, $shares[$index], 6);
    }
    if (bccomp($total, '1', 6) > 0) { throw new DomainException('The partners\' shares total more than 100%.'); }
    return $shares;
}

/**
 * Split an amount in proportion to the shares (share over the total of the shares, so a set that
 * totals less than 100 percent still splits the whole amount), to two decimal places; only what
 * rounding leaves over goes to the partner with the largest share, so the portions add up to the
 * amount exactly and no partner is charged another's silence.
 *
 * @param array<int,string> $shares
 * @return array<int,string>
 */
function pl_setup_split_amount(string $amount, array $shares): array
{
    $total = '0';
    $largest = null;
    foreach ($shares as $index => $share) {
        $total = bcadd($total, $share, 6);
        if ($largest === null || bccomp($share, $shares[$largest], 6) > 0) { $largest = $index; }
    }
    if ($largest === null || bccomp($total, '0', 6) <= 0) { throw new DomainException('At least one partner needs a share above zero.'); }
    $portions = [];
    $allocated = '0';
    foreach ($shares as $index => $share) {
        if ($index === $largest) { continue; }
        // Round half up to two decimals: bcdiv truncates, so add half a cent first.
        $portion = bcdiv(bcadd(bcmul(bcmul($amount, bcdiv($share, $total, 8), 8), '100', 8), '0.5', 8), '100', 2);
        $portions[$index] = $portion;
        $allocated = bcadd($allocated, $portion, 4);
    }
    $portions[$largest] = bcsub($amount, $allocated, 4);
    ksort($portions);
    return $portions;
}

/**
 * A partner's own capital or drawings account: the starter's generic leaf renamed for the first
 * partner (its semantic key survives, as the first bank account keeps the starter's), a new leaf
 * under the same group for every other partner.
 */
function pl_setup_partner_account(int $actorId, int $companyId, int $bookId, ?array $starterLeaf, string $group, string $name, bool $contra, string $reason, string $creationKey, ?string $genericName = null): int
{
    $name = mb_substr($name, 0, 120);
    if ($starterLeaf !== null) {
        $account = pl_get_account($actorId, $companyId, $bookId, (int) $starterLeaf['id']);
        // The partner's name replaces the starter's generic one; a name the owner chose for this leaf
        // on the Features stage, or a sample gave it, stands.
        if ($account['name'] !== $name && ($genericName === null || $account['name'] === $genericName)) {
            $account = pl_save_account($actorId, $companyId, $bookId, array_replace($account, ['name' => $name, 'reason' => $reason . ': partner account named by its owner']),
                (int) $account['id'], (int) $account['revision']);
        }
        return (int) $account['id'];
    }
    $account = pl_save_account($actorId, $companyId, $bookId, [
        'code' => pl_setup_next_account_code($companyId, $bookId, $group), 'name' => $name, 'type' => 'equity', 'role' => $contra ? null : 'owner_equity',
        'is_active' => true, 'is_contra' => $contra, 'reason' => $reason . ': partner account named by its owner', 'creation_key' => $creationKey,
    ]);
    return (int) $account['id'];
}

/**
 * Apply the wizard's additions to a book that now exists, inside the setup transaction. The
 * caller has already created the company, its starter chart and any sample structure.
 */
function pl_setup_apply_extras(int $actorId, int $companyId, int $bookId, array $canonical, string $requestKey): void
{
    require_once __DIR__ . '/owner_functions.php';
    require_once __DIR__ . '/ownership_functions.php';
    require_once __DIR__ . '/trading_functions.php';
    require_once __DIR__ . '/module_functions.php';
    $extras = $canonical['setup'] ?? null;
    if (!is_array($extras)) { return; }
    // The actor's memberships may have been read (and cached) before this company existed.
    pl_capability_cache_reset();
    $reason = 'Business setup';
    $prefix = 'st:' . $requestKey . ':';

    // 1. The registration profile: what invoices and receipts print.
    if ($extras['legal_form'] !== '' || $extras['country_code'] !== '' || $extras['profile'] !== []) {
        $before = pl_company_profile($actorId, $companyId);
        pl_save_company_profile($actorId, $companyId, $extras['profile'] + ['legal_form' => $extras['legal_form'], 'country_code' => $extras['country_code'],
            'revision' => (int) $before['revision'], 'reason' => $reason, 'idempotency_key' => $prefix . 'profile']);
    }

    // 2. Account names the owner changed on the Features stage. Codes and classifications stay.
    foreach ($extras['account_names'] as $code => $name) {
        $row = DB::queryFirstRow('SELECT id, name FROM pl_accounts WHERE company_id = %i AND book_id = %i AND code = %s FOR SHARE', $companyId, $bookId, $code);
        if (!$row) { throw new DomainException('The starting chart changed: account ' . $code . ' is not in it. Review the accounts before confirming.'); }
        if ((string) $row['name'] === $name) { continue; }
        $account = pl_get_account($actorId, $companyId, $bookId, (int) $row['id']);
        pl_save_account($actorId, $companyId, $bookId, array_replace($account, ['name' => $name, 'reason' => $reason . ': account named by its owner']),
            (int) $account['id'], (int) $account['revision']);
    }

    // 3. The named bank, till and petty-cash accounts. Postings go to these, never to the group
    //    above them (owner decision, 26 September 2026). The starter's generic leaf is renamed
    //    into the first account that names no existing code, so its semantic key survives; a
    //    skeleton's own money accounts are renamed in place; anything else is a new leaf under
    //    the same group with the next free number.
    $moneyIds = [];
    $usedIds = [];
    $starter = DB::queryFirstRow('SELECT id, name FROM pl_accounts WHERE company_id = %i AND book_id = %i AND semantic_key = %s AND is_active = 1 FOR SHARE', $companyId, $bookId, 'core.cash_bank');
    $starterClaimed = false;
    foreach ($extras['money_accounts'] as $index => $row) {
        $moneyKind = $row['kind'] === 'bank' ? 'bank' : 'physical';
        $existingId = null;
        if ($row['code'] !== '') {
            $existingId = DB::queryFirstField('SELECT id FROM pl_accounts WHERE company_id = %i AND book_id = %i AND code = %s AND role = %s FOR SHARE', $companyId, $bookId, $row['code'], 'cash_bank');
            if ($existingId === null) { throw new DomainException('The starting chart changed: ' . $row['code'] . ' is not a bank or cash account in it.'); }
        } elseif ($starter && !$starterClaimed) {
            $existingId = $starter['id'];
            $starterClaimed = true;
        }
        if ($existingId !== null) {
            // A row that names the starter leaf by its code claims it too, so the next row without
            // a code cannot take the same account (review finding, 26 September 2026).
            if ($starter && (int) $existingId === (int) $starter['id']) { $starterClaimed = true; }
            if (isset($usedIds[(int) $existingId])) {
                throw new DomainException('Two bank or cash rows resolve to the same account (' . $row['name'] . '); keep one of them.');
            }
            $usedIds[(int) $existingId] = true;
            $account = pl_get_account($actorId, $companyId, $bookId, (int) $existingId);
            if ($account['name'] !== $row['name'] || ($account['money_kind'] ?? null) !== $moneyKind) {
                $account = pl_save_account($actorId, $companyId, $bookId, array_replace($account, ['name' => $row['name'], 'money_kind' => $moneyKind,
                    'reason' => $reason . ': bank or cash account named by its owner']), (int) $account['id'], (int) $account['revision']);
            }
            $moneyIds[$index] = (int) $account['id'];
            continue;
        }
        $account = pl_save_account($actorId, $companyId, $bookId, [
            'code' => pl_setup_next_account_code($companyId, $bookId, '1-100'), 'name' => $row['name'], 'type' => 'asset', 'role' => 'cash_bank',
            'money_kind' => $moneyKind, 'is_active' => true, 'is_contra' => false,
            'reason' => $reason . ': bank or cash account named by its owner', 'creation_key' => $prefix . 'money:' . $index,
        ]);
        $moneyIds[$index] = (int) $account['id'];
        $usedIds[(int) $account['id']] = true;
    }

    // 4. The owners, in the ownership register, from the start date. A partnership's partners also
    //    get a capital account and a drawings account each, tied together by a partner record:
    //    capital introduced is personal to a partner (Partnership Act 1932 s.13), so the balance
    //    sheet shows each partner's capital, never one pooled "Owner equity" (owner, 26 September 2026).
    $family = pl_legal_form_family($extras['legal_form']);
    $hasPartners = pl_legal_form_has_partners($family);
    // A full sample brings its own partners and history; a book that already records partners keeps them.
    $partnership = $hasPartners && ($canonical['source'] ?? 'blank') !== 'full'
        && (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_owner_partners WHERE company_id = %i AND book_id = %i', $companyId, $bookId) === 0;
    $partnerShares = $partnership ? pl_setup_partner_shares($extras['owners']) : [];
    $partnerIds = [];
    // The starter's generic leaf names ("Owner equity", "Owner drawings"): a leaf still carrying one
    // becomes the first partner's; a name the owner or a sample gave it stands.
    $templateNames = [];
    foreach (pl_starter_template()['accounts'] as $templateAccount) {
        if (($templateAccount['semantic_key'] ?? '') !== '') { $templateNames[(string) $templateAccount['semantic_key']] = (string) $templateAccount['name']; }
    }
    $capitalLeaf = $partnership ? DB::queryFirstRow('SELECT id, code FROM pl_accounts WHERE company_id = %i AND book_id = %i AND semantic_key = %s AND is_active = 1 FOR SHARE', $companyId, $bookId, 'core.equity.owner') : null;
    $drawingsLeaf = $partnership ? DB::queryFirstRow('SELECT id, code FROM pl_accounts WHERE company_id = %i AND book_id = %i AND semantic_key = %s AND is_active = 1 FOR SHARE', $companyId, $bookId, 'core.equity.drawings') : null;
    foreach ($extras['owners'] as $index => $owner) {
        $note = trim($owner['role'] . ($owner['share'] !== '' ? ' · ' . $owner['share'] . '%' : ''), ' ·');
        $party = pl_save_ownership_party($actorId, $companyId, ['kind' => $owner['kind'], 'name' => $owner['name'], 'is_active' => true,
            'note' => $note, 'reason' => $reason]);
        pl_save_ownership_member($actorId, $companyId, ['ownership_party_id' => (int) $party['id'], 'effective_from' => $canonical['start_date'],
            'profit_share' => $partnership ? $partnerShares[$index] : ($hasPartners && $owner['share'] !== '' ? bcdiv($owner['share'], '100', 6) : ''),
            'note' => $note, 'reason' => $reason]);
        if (!$partnership) { continue; }
        $capitalId = pl_setup_partner_account($actorId, $companyId, $bookId, $index === 0 ? $capitalLeaf : null, '3-100', 'Partner capital - ' . $owner['name'], false, $reason, $prefix . 'pcap:' . $index, $templateNames['core.equity.owner'] ?? null);
        $drawingsId = pl_setup_partner_account($actorId, $companyId, $bookId, $index === 0 ? $drawingsLeaf : null, '3-900', 'Partner drawings - ' . $owner['name'], true, $reason, $prefix . 'pdraw:' . $index, $templateNames['core.equity.drawings'] ?? null);
        $partner = pl_save_owner_partner($actorId, $companyId, $bookId, ['name' => $owner['name'], 'profit_share' => $partnerShares[$index], 'is_active' => true,
            'capital_account_id' => $capitalId, 'drawings_account_id' => $drawingsId, 'loan_account_id' => null]);
        pl_link_ownership_partner($actorId, $companyId, $bookId, (int) $party['id'], (int) $partner['id'], $reason);
        $partnerIds[$index] = (int) $partner['id'];
    }

    // 5. Features, in dependency order; a skeleton has already switched on what it needs.
    $registry = pl_module_registry();
    foreach ($extras['features'] as $id) {
        $state = pl_module_state($companyId, $id);
        if ($state['enabled']) { continue; }
        pl_set_company_module($actorId, $companyId, $id, true, (int) $state['revision'], (string) $registry[$id]['digest'], $reason . ': feature chosen', $prefix . 'module:' . $id);
    }

    // 6. Opening money, posted on the start date through the owner-transaction service: capital
    //    introduced credits the owner's equity, an owner loan credits the owner's loan account.
    //    In a partnership, capital introduced is split between the partners by their ratio and
    //    posted to each partner's own capital account, so the balance sheet shows every share.
    foreach ($extras['money_accounts'] as $index => $row) {
        if ($row['opening_source'] === '' || bccomp($row['opening_amount'], '0', 4) <= 0) { continue; }
        if ($row['opening_source'] === 'capital_introduced' && $partnerIds !== []) {
            foreach (pl_setup_split_amount($row['opening_amount'], $partnerShares) as $ownerIndex => $portion) {
                if (bccomp($portion, '0', 4) <= 0) { continue; }
                pl_post_owner_transaction($actorId, $companyId, $bookId, [
                    'kind' => 'capital_introduced', 'date' => $canonical['start_date'], 'amount' => $portion,
                    'cash_account_id' => $moneyIds[$index], 'partner_id' => $partnerIds[$ownerIndex],
                    'creation_key' => $prefix . 'm' . $index . ':p' . $ownerIndex,
                    'description' => 'Opening money: capital introduced into ' . $row['name'] . ' by ' . $extras['owners'][$ownerIndex]['name'],
                ]);
            }
            continue;
        }
        $ownerKey = $row['opening_source'] === 'capital_introduced' ? 'core.equity.owner' : 'core.liability.owner_loan';
        $ownerAccount = DB::queryFirstField('SELECT id FROM pl_accounts WHERE company_id = %i AND book_id = %i AND semantic_key = %s AND is_active = 1', $companyId, $bookId, $ownerKey);
        pl_post_owner_transaction($actorId, $companyId, $bookId, [
            'kind' => $row['opening_source'], 'date' => $canonical['start_date'], 'amount' => $row['opening_amount'],
            'cash_account_id' => $moneyIds[$index], 'owner_account_id' => $ownerAccount === null ? null : (int) $ownerAccount,
            'creation_key' => $prefix . 'm' . $index,
            'description' => 'Opening money: ' . pl_owner_transaction_kinds()[$row['opening_source']]['label'] . ' into ' . $row['name'],
        ]);
    }

    // 7. Money leaves a bank or cash account only when it is there (owner decision, 26 September 2026).
    if ($canonical['start_mode'] !== 'sample') {
        $policies = pl_trading_policies($actorId, $companyId, $bookId);
        if ($policies['cash_shortfall_policy'] !== $extras['cash_policy']) {
            pl_save_trading_policies($actorId, $companyId, $bookId, ['cash_shortfall_policy' => $extras['cash_policy'], 'revision' => (int) $policies['revision'],
                'reason' => $reason . ': cash policy', 'idempotency_key' => $prefix . 'policy'] + array_intersect_key($policies, pl_trading_policy_defaults()));
        }
    }
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
            // A starter purpose carries its presentation with it: an account mapped onto a contra
            // purpose is a contra account. Without this, mapping an existing chart leaves a
            // drawings account that `pl_owner_accounts()` cannot see, which is the same defect
            // migration 040 repairs for a converted chart (internal review finding 5).
            DB::update('pl_accounts', ['semantic_key' => $definition['semantic_key'], 'role' => $definition['role'],
                'is_contra' => ($definition['is_contra'] ?? false) === true], 'id = %i', $mapping[$definition['semantic_key']]);
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
        // The identity-checked, empty-company core sample explicitly reconciles its money
        // account as `expected.bank`. This authored fixture decision is not a rule for real charts.
        $sampleBank = pl_get_account($actorId, $companyId, $bookId, (int) $accounts['core.cash_bank']);
        pl_save_account($actorId, $companyId, $bookId, array_replace($sampleBank, [
            'money_kind' => 'bank', 'reason' => 'The authored core-accounting sample reconciles this account as its bank balance.',
        ]), $sampleBank['id'], $sampleBank['revision']);
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
