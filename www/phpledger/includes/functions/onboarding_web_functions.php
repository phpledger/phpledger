<?php
declare(strict_types=1);

require_once __DIR__ . '/legal_form_functions.php';

/**
 * The "onboard a business" wizard, as reviewed by the owner on 25-26 September 2026 (frames in
 * docs/design/setup-1.4.5; decision B50 for the skeleton it still offers).
 *
 * Five stages, one question each, in the installer's own column: Start (what are you starting
 * from, with the sample gallery), Business (name, country, legal form in that country's words,
 * currency, year end, start date and what is printed on invoices), Owners & money (who owns it,
 * and the named bank, till and petty-cash accounts under the "Cash and cash equivalents" group,
 * with optional opening money), Features & accounts (what to switch on, and the account names),
 * Review. Nothing is created until Review is confirmed; every stage keeps its answers in the
 * session, Back keeps what was typed even when it does not validate yet, and installing a sample
 * from the directory happens on the Start stage itself and comes straight back to it.
 *
 * What the chosen source brings in reaches the screen as `sourceView`, never `view`: pl_render()
 * extracts a route's data with EXTR_SKIP while already holding `$view` as the template's own name.
 */

/** The tray, in order. The keys are what `?stage=` accepts. */
function pl_onboarding_stages(): array
{
    return ['start' => 'Start', 'business' => 'Business', 'owners' => 'Owners & money',
        'features' => 'Features & accounts', 'review' => 'Review', 'ready' => 'Ready'];
}

/** @return array<string, mixed> the draft this session is building, empty before the first stage */
function pl_onboarding_draft(): array
{
    $draft = $_SESSION['onboarding'] ?? null;
    return is_array($draft) && is_array($draft['input'] ?? null) ? $draft : ['input' => [], 'request_key' => ''];
}

function pl_onboarding_store(array $input): void
{
    $_SESSION['onboarding'] = ['input' => $input,
        'request_key' => (string) (pl_onboarding_draft()['request_key'] ?: bin2hex(random_bytes(24)))];
}

/**
 * The four starting points the first stage offers, and what each one means to pl_setup_company().
 *
 * @return array<string, array{start_mode: string, source: string}>
 */
function pl_onboarding_start_choices(): array
{
    $choices = ['fresh' => ['start_mode' => 'fresh', 'source' => 'blank'],
        'structure' => ['start_mode' => 'fresh', 'source' => 'skeleton'],
        'existing' => ['start_mode' => 'existing', 'source' => 'blank']];
    if (pl_sample_companies_allowed()) {
        $choices['sample'] = ['start_mode' => 'sample', 'source' => 'full'];
    }
    return $choices;
}

/**
 * Which sources this starting point may choose between; kept for callers of the 1.2 contract.
 *
 * @return list<string>
 */
function pl_onboarding_sources(string $startMode): array
{
    return match ($startMode) {
        'sample' => ['skeleton', 'full'],
        'existing' => ['blank'],
        default => ['blank', 'skeleton'],
    };
}

/** The stage a draft is allowed to be on, so a bookmarked later stage cannot skip a decision. */
function pl_onboarding_reached(array $input): string
{
    if (!isset($input['start_mode'], $input['source'])) { return 'start'; }
    if (!isset($input['name'])) { return 'business'; }
    if (!isset($input['money_accounts'])) { return 'owners'; }
    if (!isset($input['features'])) { return 'features'; }
    return 'review';
}

/**
 * The sample companies the Start stage shows as cards: every company the bundled catalogue and the
 * fetched directory know, in the catalogue's order (approved frame o1-start.html). A company this
 * copy has, installed or bundled, carries its own story and what its structure brings and can be
 * chosen; the others show the snapshot's story and install in one click for an installation
 * administrator. The neutral starter is the blank start's chart, not a company, so it is not a card.
 *
 * @return list<array<string, mixed>>
 */
function pl_onboarding_gallery(bool $administers): array
{
    $cards = [];
    $structureIds = pl_sample_structure_ids();
    $catalogue = pl_sample_catalogue();
    $installable = $administers && !pl_sample_packages_readonly() && extension_loaded('curl');
    foreach (pl_demo_pack_catalog() as $id => $entry) {
        $id = (string) $id;
        try {
            $sample = pl_demo_sample($id);
        } catch (Throwable) {
            continue;
        }
        $story = is_array($sample['learning_story'] ?? null) ? $sample['learning_story'] : [];
        $snapshot = $catalogue[$id] ?? null;
        $structure = in_array($id, $structureIds, true) ? pl_sample_structure_read($id) : null;
        $summary = $structure === null ? null : pl_sample_structure_summary($structure);
        $logo = is_array($story['logo'] ?? null) ? (string) ($story['logo']['path'] ?? '') : '';
        $cards[$id] = ['id' => $id, 'name' => (string) $sample['name'], 'business' => (string) ($sample['business'] ?? ($entry['business'] ?? ($snapshot['business'] ?? ''))),
            'story' => (string) ($story['origin'] ?? ($snapshot['story'] ?? '')), 'logo' => $logo !== '' ? $logo : (string) ($snapshot['logo'] ?? ''),
            'installed' => true, 'skeleton' => $structure !== null,
            'accounts' => $summary === null ? (int) ($snapshot['accounts'] ?? 0) : (int) $summary['accounts'], 'parties' => $summary === null ? (int) ($snapshot['parties'] ?? 0) : (int) $summary['parties'],
            'modules' => $summary === null ? [] : $summary['modules'], 'version' => (string) ($sample['version'] ?? ''), 'slug' => 'sample-' . $id, 'description' => '', 'installable' => false];
    }
    foreach (pl_sample_directory_offer() as $slug => $entry) {
        $id = substr((string) $slug, strlen('sample-'));
        if (isset($cards[$id])) { continue; }
        $cards[$id] = ['id' => $id, 'name' => (string) $entry['name'], 'business' => (string) $entry['business'], 'story' => (string) $entry['story'], 'logo' => (string) $entry['logo'],
            'installed' => false, 'skeleton' => false, 'accounts' => (int) $entry['accounts'], 'parties' => (int) $entry['parties'], 'modules' => [],
            'version' => (string) $entry['version'], 'slug' => (string) $slug, 'description' => (string) $entry['description'], 'installable' => $installable];
    }
    $order = array_flip(array_keys($catalogue));
    uksort($cards, static fn (string $a, string $b): int => (($order[$a] ?? PHP_INT_MAX) <=> ($order[$b] ?? PHP_INT_MAX)) ?: strcmp($a, $b));
    return array_values($cards);
}

/**
 * The bank and cash accounts a skeleton already carries, offered as rows to rename rather than
 * duplicated. A blank start offers the starter's one "Cash and bank" leaf the same way.
 *
 * @return list<array{code: string, name: string, kind: string}>
 */
function pl_onboarding_existing_money(array $input, array $template): array
{
    if (($input['source'] ?? 'blank') === 'skeleton' && isset($input['sample_pack'])) {
        $structure = pl_sample_structure_read((string) $input['sample_pack']);
        $rows = [];
        // The starter's own leaf comes first: a skeleton keeps it, so it is offered for renaming
        // rather than left behind as a generic "Cash and bank" beside the sample's named accounts.
        foreach ($template['accounts'] as $account) {
            if (($account['semantic_key'] ?? '') === 'core.cash_bank') {
                $kind = ($structure['money_account_kinds'][(string) $account['code']] ?? 'bank') === 'physical' ? 'till' : 'bank';
                $rows[] = ['code' => (string) $account['code'], 'name' => (string) $account['name'], 'kind' => $kind];
                break;
            }
        }
        foreach ($structure['accounts'] ?? [] as $account) {
            if (($account['role'] ?? null) !== 'cash_bank') { continue; }
            $kind = (($account['money_kind'] ?? '') === 'bank' || ($structure['money_account_kinds'][$account['code']] ?? '') === 'bank') ? 'bank' : 'till';
            $rows[] = ['code' => (string) $account['code'], 'name' => (string) $account['name'], 'kind' => $kind];
        }
        if ($rows !== []) { return $rows; }
    }
    if (($input['source'] ?? 'blank') === 'full') { return []; }
    foreach ($template['accounts'] as $account) {
        if (($account['semantic_key'] ?? '') === 'core.cash_bank') {
            return [['code' => (string) $account['code'], 'name' => (string) $account['name'], 'kind' => 'bank']];
        }
    }
    return [];
}

/** The optional modules the Features stage offers, with what a skeleton locks on. @return list<array<string, mixed>> */
function pl_onboarding_features(array $input): array
{
    $required = [];
    if (($input['source'] ?? '') === 'skeleton' && isset($input['sample_pack'])) {
        $required = pl_sample_structure_read((string) $input['sample_pack'])['modules'] ?? [];
    }
    $chosen = is_array($input['features'] ?? null) ? $input['features'] : ['ar', 'ap'];
    $out = [];
    foreach (pl_module_registry() as $id => $manifest) {
        if (!$manifest['optional']) { continue; }
        $problem = '';
        try { pl_module_installed($manifest); } catch (DomainException $error) { $problem = $error->getMessage(); }
        $out[] = ['id' => (string) $id, 'name' => (string) $manifest['name'], 'description' => (string) ($manifest['description'] ?? ''),
            'requires' => array_keys($manifest['requires'] ?? []), 'required' => in_array($id, $required, true),
            'checked' => in_array($id, $required, true) || in_array($id, $chosen, true), 'problem' => $problem];
    }
    return $out;
}

/** The accounts the Features stage lets the owner rename: the starter chart, plus a skeleton's own. @return list<array<string, mixed>> */
function pl_onboarding_chart(array $input, array $template): array
{
    $rows = [];
    foreach ($template['headings'] as $heading) {
        $rows[] = ['code' => (string) $heading['code'], 'name' => (string) $heading['name'], 'type' => (string) $heading['type'], 'heading' => true];
    }
    $accounts = $template['accounts'];
    if (($input['source'] ?? '') === 'skeleton' && isset($input['sample_pack'])) {
        $accounts = array_merge($accounts, pl_sample_structure_read((string) $input['sample_pack'])['accounts'] ?? []);
    }
    foreach ($accounts as $account) {
        $rows[] = ['code' => (string) $account['code'], 'name' => (string) $account['name'], 'type' => (string) $account['type'], 'heading' => false];
    }
    usort($rows, static fn (array $a, array $b): int => strcmp($a['code'], $b['code']));
    // A bank or cash account named on the Owners stage shows that name here, read-only: it is
    // named in one place. The first row without a code becomes the starter's own leaf.
    $money = [];
    $starterCode = '';
    foreach ($template['accounts'] as $account) {
        if (($account['semantic_key'] ?? '') === 'core.cash_bank') { $starterCode = (string) $account['code']; break; }
    }
    $claimed = false;
    foreach ($input['money_accounts'] ?? [] as $row) {
        if (($row['code'] ?? '') !== '') { $money[(string) $row['code']] = (string) $row['name']; }
    }
    if ($starterCode !== '' && !isset($money[$starterCode])) {
        foreach ($input['money_accounts'] ?? [] as $row) {
            if (($row['code'] ?? '') === '') { $money[$starterCode] = (string) $row['name']; break; }
        }
    }
    foreach ($rows as &$row) {
        if (isset($money[$row['code']])) { $row['name'] = $money[$row['code']]; $row['money'] = true; }
    }
    unset($row);
    return $rows;
}

/**
 * Read one stage's answers into the draft, or throw with the sentence the person needs to fix.
 * With $strict false (Back, or a link out) whatever parses is kept and nothing throws.
 *
 * @param array<string, mixed> $input the draft so far
 * @return array<string, mixed> the draft with this stage's answers in it
 */
function pl_onboarding_read_stage(string $stage, array $post, array $input, bool $strict = true): array
{
    try {
        return pl_onboarding_read_stage_strict($stage, $post, $input);
    } catch (DomainException $error) {
        if ($strict) { throw $error; }
        // The lenient reader keeps the raw answers under the stage's own keys so the screen shows
        // them again; the strict reader replaces them when the stage is submitted for real.
        $input['unchecked'][$stage] = array_filter($post, static fn ($value, $key): bool => (is_string($value) || is_array($value)) && !in_array($key, ['csrf', 'action', 'stage'], true), ARRAY_FILTER_USE_BOTH);
        return $input;
    }
}

function pl_onboarding_read_stage_strict(string $stage, array $post, array $input): array
{
    unset($input['unchecked'][$stage]);
    if ($stage === 'start') {
        $choices = pl_onboarding_start_choices();
        $choice = pl_web_text($post, 'start');
        if (!isset($choices[$choice])) {
            throw new DomainException(pl_t('Choose what you are starting from.'));
        }
        $meaning = $choices[$choice];
        if ($meaning['start_mode'] === 'sample') { pl_require_sample_companies_allowed(); }
        $samplePack = null;
        if ($meaning['source'] !== 'blank') {
            $samplePack = pl_web_text($post, 'sample_pack');
            $allowed = $meaning['source'] === 'skeleton' ? pl_sample_structure_ids() : array_map('strval', array_keys(pl_demo_sample_choices()));
            if (!in_array($samplePack, $allowed, true)) {
                throw new DomainException(pl_t('Choose which sample company this business starts from.'));
            }
            if ($meaning['source'] === 'skeleton' && pl_sample_structure_read($samplePack) === null) {
                throw new DomainException(pl_t('This sample company does not publish a structure yet, so it cannot start a business. Choose another.'));
            }
        }
        if (($input['start_choice'] ?? '') !== $choice || ($input['sample_pack'] ?? null) !== $samplePack) {
            // A different starting point brings different accounts and modules, so answers that
            // named them are dropped rather than carried into a chart that does not have them.
            unset($input['money_accounts'], $input['features'], $input['account_names']);
        }
        $input['start_choice'] = $choice;
        $input['start_mode'] = $meaning['start_mode'];
        $input['source'] = $meaning['source'];
        $input['chart_choice'] = 'neutral';
        $input['zero_balances_confirmed'] = $meaning['start_mode'] === 'fresh';
        if ($samplePack === null) { unset($input['sample_pack']); } else { $input['sample_pack'] = $samplePack; }
        return $input;
    }
    if ($stage === 'business') {
        $input['name'] = pl_web_text($post, 'name');
        pl_ledger_text($input['name'], 'Business name', 160);
        $country = strtoupper(pl_web_text($post, 'country_code'));
        if ($country !== '' && !isset(pl_country_options()[$country])) {
            throw new DomainException(pl_t('Choose a country from the list, or leave it empty.'));
        }
        $input['country_code'] = $country;
        $form = pl_web_text($post, 'legal_form');
        if ($form !== '' && !isset(pl_legal_forms_for($country)[$form]) && !isset(pl_legal_forms()[$form])) {
            throw new DomainException(pl_t('Choose a legal form from the list for this country, or leave it empty.'));
        }
        $input['legal_form'] = $form;
        $input['currency'] = pl_web_text($post, 'currency');
        if (!isset(pl_base_currency_options()[$input['currency']])) {
            throw new DomainException(pl_t('Choose one of the supported base currencies.'));
        }
        $input['start_date'] = pl_web_text($post, 'start_date');
        pl_ledger_date($input['start_date']);
        // `entity_type` was the 1.2 question this stage asked; a caller that still sends it is not refused.
        $input['entity_type'] = pl_web_text($post, 'entity_type', 'other');
        $choice = pl_web_text($post, 'fiscal_year_end_choice');
        $input['fiscal_year_end_choice'] = $choice;
        $input['fiscal_year_end_custom'] = pl_web_text($post, 'fiscal_year_end_custom');
        if ($choice === 'custom') {
            $input['fiscal_year_end'] = $input['fiscal_year_end_custom'];
        } elseif (array_key_exists($choice, pl_fiscal_year_end_options())) {
            $input['fiscal_year_end'] = $choice;
        } else {
            throw new DomainException(pl_t('Choose a listed year-end option or enter a custom year end.'));
        }
        pl_ledger_date('2001-' . $input['fiscal_year_end']);
        $profile = [];
        $profileFields = ['legal_name' => [pl_t('Legal name'), 200], 'registration_number' => [pl_t('Registration number'), 80], 'registration_authority' => [pl_t('Registration authority'), 160],
            'address_line1' => [pl_t('Address'), 200], 'address_line2' => [pl_t('Address line 2'), 200], 'phone' => [pl_t('Phone'), 80], 'email' => [pl_t('Email'), 190]];
        foreach ($profileFields as $field => [$label, $limit]) {
            $value = pl_web_text($post, $field);
            if (mb_strlen($value, 'UTF-8') > $limit) {
                throw new DomainException(pl_t('{field} is too long.', ['field' => $label]));
            }
            $profile[$field] = $value;
        }
        if ($profile['email'] !== '' && filter_var($profile['email'], FILTER_VALIDATE_EMAIL) === false) {
            throw new DomainException(pl_t('Enter a valid email address for documents, or leave it empty.'));
        }
        // The two tax numbers keep the country's own labels in the profile's free-text registrations.
        $labels = pl_legal_form_country_profile($country)['labels'];
        $registrations = [];
        foreach (['tax1', 'tax2'] as $slot) {
            $value = pl_web_text($post, $slot);
            if ($value !== '' && ($labels[$slot] ?? '') !== '') {
                $registrations[] = $labels[$slot] . ' ' . $value;
            }
        }
        $profile['tax_registrations'] = implode('; ', $registrations);
        $profile['tax1'] = pl_web_text($post, 'tax1');
        $profile['tax2'] = pl_web_text($post, 'tax2');
        if (mb_strlen($profile['tax_registrations'], 'UTF-8') > 300) {
            throw new DomainException(pl_t('The tax numbers are too long.'));
        }
        $input['profile'] = $profile;
        return $input;
    }
    if ($stage === 'owners') {
        $owners = [];
        $names = is_array($post['owner_name'] ?? null) ? $post['owner_name'] : [];
        foreach ($names as $index => $name) {
            $name = is_string($name) ? trim($name) : '';
            if ($name === '') { continue; }
            $row = static fn (string $field): string => is_string($post[$field][$index] ?? null) ? trim($post[$field][$index]) : '';
            $share = $row('owner_share');
            if ($share !== '' && !preg_match('/^(?:100(?:\.0+)?|[0-9]{1,2}(?:\.[0-9]{1,4})?)$/D', $share)) {
                throw new DomainException(pl_t('Enter an ownership share between 0 and 100 for {name}, or leave it empty.', ['name' => $name]));
            }
            $owners[] = ['name' => mb_substr($name, 0, 160), 'kind' => $row('owner_kind') === 'entity' ? 'entity' : 'person',
                'role' => mb_substr($row('owner_role'), 0, 80), 'share' => $share];
        }
        if (count($owners) > 20) { throw new DomainException(pl_t('Register up to twenty owners here; more can be added later.')); }
        $accounts = [];
        $moneyNames = is_array($post['money_name'] ?? null) ? $post['money_name'] : [];
        foreach ($moneyNames as $index => $name) {
            $row = static fn (string $field): string => is_string($post[$field][$index] ?? null) ? trim($post[$field][$index]) : '';
            $name = is_string($name) ? trim($name) : '';
            $code = $row('money_code');
            if ($name === '' && $row('money_amount') === '') { continue; }
            if ($name === '') { throw new DomainException(pl_t('Give every bank or cash account a name.')); }
            $kind = $row('money_kind');
            if (!in_array($kind, ['bank', 'till', 'petty'], true)) { throw new DomainException(pl_t('Say whether each money account is a bank account, a till or petty cash.')); }
            $amount = str_replace([',', ' '], '', $row('money_amount'));
            if ($amount !== '' && !preg_match('/^(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,4})?$/D', $amount)) {
                throw new DomainException(pl_t('Enter the opening amount for {name} as a plain number, or leave it empty.', ['name' => $name]));
            }
            $source = $row('money_source');
            if ($amount !== '' && bccomp($amount, '0', 4) > 0) {
                if (!in_array($source, ['capital_introduced', 'owner_loan_received'], true)) {
                    throw new DomainException(pl_t('Say where the opening money in {name} comes from: capital introduced, or an owner loan.', ['name' => $name]));
                }
                if (($input['start_mode'] ?? '') === 'existing') {
                    throw new DomainException(pl_t('Past records bring their balances through the opening cutover, so leave the opening amounts empty here.'));
                }
            } else {
                $amount = '';
                $source = '';
            }
            $accounts[] = ['kind' => $kind, 'code' => $code, 'name' => mb_substr($name, 0, 120), 'custodian' => mb_substr($row('money_custodian'), 0, 80),
                'opening_amount' => $amount, 'opening_source' => $source];
        }
        if (count($accounts) > 20) { throw new DomainException(pl_t('Name up to twenty bank and cash accounts here; more can be added later.')); }
        if ($accounts !== [] && ($input['source'] ?? 'blank') === 'full') {
            throw new DomainException(pl_t('A full sample brings its own bank and cash accounts; leave this table empty.'));
        }
        if ($accounts === [] && ($input['source'] ?? 'blank') !== 'full') {
            throw new DomainException(pl_t('Name at least one bank account, till or petty cash: postings go to a specific account, never to the "Cash and cash equivalents" group.'));
        }
        $codes = array_filter(array_column($accounts, 'code'));
        if (count($codes) !== count(array_unique($codes))) {
            throw new DomainException(pl_t('Two rows name the same account; keep one of them.'));
        }
        $generic = pl_setup_generic_money_row($accounts);
        if ($generic !== null) {
            throw new DomainException(pl_t('Give the starter\'s "{name}" account the name of a real account, for example the bank it is.', ['name' => $generic]));
        }
        if ($owners !== [] && ($input['source'] ?? 'blank') !== 'full' && pl_legal_form_has_partners(pl_legal_form_family((string) ($input['legal_form'] ?? '')))) {
            // A half-entered set of shares or a total above 100 percent is refused here, not at confirm.
            pl_setup_partner_shares($owners);
        }
        $input['owners'] = $owners;
        $input['money_accounts'] = $accounts;
        return $input;
    }
    if ($stage === 'features') {
        $features = $post['features'] ?? [];
        if (!is_array($features)) { throw new DomainException(pl_t('Choose valid features.')); }
        $registry = pl_module_registry();
        $chosen = [];
        foreach ($features as $id) {
            if (!is_string($id) || !isset($registry[$id]) || !$registry[$id]['optional']) { throw new DomainException(pl_t('Choose a known optional feature.')); }
            $chosen[] = $id;
        }
        foreach (pl_onboarding_features($input) as $feature) {
            if ($feature['required'] && !in_array($feature['id'], $chosen, true)) { $chosen[] = $feature['id']; }
        }
        $input['features'] = array_values(array_unique($chosen));
        $names = $post['account_name'] ?? [];
        if (!is_array($names) || count($names) > 500) { throw new DomainException(pl_t('Review the account names before continuing.')); }
        $renames = [];
        foreach ($names as $code => $name) {
            if (!is_string($code) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,19}$/D', $code) || !is_string($name)) {
                throw new DomainException(pl_t('Review the account names before continuing.'));
            }
            $name = trim($name);
            if ($name === '') { throw new DomainException(pl_t('Account {code} needs a name.', ['code' => $code])); }
            if (mb_strlen($name, 'UTF-8') > 120) { throw new DomainException(pl_t('The name for account {code} is too long.', ['code' => $code])); }
            $renames[$code] = $name;
        }
        $input['account_names'] = $renames;
        return $input;
    }
    throw new DomainException('Choose a valid setup stage.');
}

/**
 * Turn the draft into the payload `pl_setup_company()` accepts.
 *
 * A full sample replays a pinned history, so its book has to begin where that history begins
 * and the Review stage says so. A skeleton replays nothing, so it keeps the dates that were
 * chosen on the Business stage.
 *
 * @return array<string, mixed>
 */
function pl_onboarding_payload(array $input, string $templateDigest): array
{
    $payload = [
        'name' => $input['name'] ?? '', 'currency' => $input['currency'] ?? '',
        'start_date' => $input['start_date'] ?? '', 'fiscal_year_end' => $input['fiscal_year_end'] ?? '12-31',
        'start_mode' => $input['start_mode'] ?? 'fresh', 'chart_choice' => $input['chart_choice'] ?? 'neutral',
        'source' => $input['source'] ?? 'blank', 'template_digest' => $templateDigest,
        'zero_balances_confirmed' => ($input['zero_balances_confirmed'] ?? false) === true,
    ];
    if (isset($input['sample_pack'])) {
        $payload['sample_pack'] = $input['sample_pack'];
        if ($payload['source'] === 'full') {
            $pack = pl_demo_sample((string) $input['sample_pack']);
            $payload['start_date'] = (string) $pack['start_date'];
            $payload['fiscal_year_end'] = '12-31';
        }
    }
    // The 1.4.5 stages. Each is optional to the service, so the sample chooser and the demo,
    // which never ask them, keep working unchanged.
    $profile = is_array($input['profile'] ?? null) ? $input['profile'] : [];
    unset($profile['tax1'], $profile['tax2']);
    $payload['country_code'] = (string) ($input['country_code'] ?? '');
    $payload['legal_form'] = (string) ($input['legal_form'] ?? '');
    $payload['profile'] = $profile;
    $payload['owners'] = is_array($input['owners'] ?? null) ? $input['owners'] : [];
    $payload['money_accounts'] = $payload['source'] === 'full' ? [] : (is_array($input['money_accounts'] ?? null) ? $input['money_accounts'] : []);
    $payload['features'] = is_array($input['features'] ?? null) ? $input['features'] : [];
    $payload['account_names'] = is_array($input['account_names'] ?? null) ? $input['account_names'] : [];
    // Money can leave a bank or cash account only when it is there (owner decision, 26 September 2026).
    $payload['cash_policy'] = $payload['start_mode'] === 'sample' ? 'warning' : 'strict';
    return $payload;
}

/**
 * Everything the Review stage shows about the chosen source, read once so the summary and the
 * contents list cannot describe it differently.
 *
 * @return array<string, mixed>
 */
function pl_onboarding_source_view(array $input): array
{
    $source = $input['source'] ?? 'blank';
    $view = ['source' => $source, 'sample' => null, 'structure' => null, 'summary' => [], 'accounts' => []];
    if (!isset($input['sample_pack'])) {
        return $view;
    }
    $sample = pl_demo_sample((string) $input['sample_pack']);
    $view['sample'] = ['id' => (string) $sample['id'], 'name' => (string) $sample['name'],
        'business' => (string) ($sample['business'] ?? ''), 'start_date' => (string) $sample['start_date'],
        'journals' => (int) ($sample['journal_count'] ?? 0), 'drafts' => (int) ($sample['draft_count'] ?? 0)];
    if ($source === 'skeleton') {
        $structure = pl_sample_structure_read((string) $input['sample_pack']);
        if ($structure !== null) {
            $view['structure'] = $structure;
            $view['summary'] = pl_sample_structure_summary($structure);
            $view['accounts'] = $structure['accounts'];
        }
    }
    return $view;
}

/** Current scoped totals, including resumed setup after later activity. */
function pl_onboarding_book_counts(array $company): array
{
    return [
        'journals' => (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE company_id=%i AND book_id=%i', (int) $company['id'], (int) $company['book_id']),
        'drafts' => (int) DB::queryFirstField("SELECT COUNT(*) FROM pl_documents WHERE company_id=%i AND book_id=%i AND journal_id IS NULL", (int) $company['id'], (int) $company['book_id'])
            + (int) DB::queryFirstField("SELECT COUNT(*) FROM pl_ar_documents WHERE company_id=%i AND book_id=%i AND status='draft'", (int) $company['id'], (int) $company['book_id'])
            + (int) DB::queryFirstField("SELECT COUNT(*) FROM pl_general_drafts WHERE company_id=%i AND book_id=%i AND journal_id IS NULL", (int) $company['id'], (int) $company['book_id']),
    ];
}

/**
 * The plain facts the Ready stage lists, in the installer's own manifest style: what exists now,
 * stated once each, with no congratulation.
 *
 * @param array<string, mixed> $setup what the wizard itself added: owners, money accounts, opening money
 * @return list<string>
 */
function pl_onboarding_manifest(array $company, ?array $receipt, array $packages, array $setup = []): array
{
    $lines = [];
    $accounts = count(array_filter($company['accounts'], static fn (array $row): bool => $row['level'] === 'account'));
    if ($receipt !== null) {
        $created = $receipt['created'];
        $lines[] = pl_t('{total} accounts, {brought} of them brought in from the sample structure', [
            'total' => $accounts, 'brought' => (int) $created['accounts']]);
        if ($created['modules'] !== []) {
            $lines[] = pl_t('Modules active: {modules}', ['modules' => implode(', ', array_map('pl_module_label', $created['modules']))]);
        }
        if ((int) $created['parties'] > 0 || (int) $created['products'] > 0) {
            $lines[] = pl_t('{parties} customer and supplier records and {products} products, imported at zero', [
                'parties' => (int) $created['parties'], 'products' => (int) $created['products']]);
        }
        if ($created['number_series'] !== []) {
            $lines[] = pl_tn('Numbering ready for {count} document type', 'Numbering ready for {count} document types',
                count($created['number_series']), ['count' => count($created['number_series'])]);
        }
    } else {
        $lines[] = pl_tn('{count} account created', '{count} accounts created', $accounts, ['count' => $accounts]);
    }
    if (($setup['owners'] ?? 0) > 0) {
        $lines[] = pl_tn('{count} owner registered', '{count} owners registered', (int) $setup['owners'], ['count' => (int) $setup['owners']]);
    }
    if (($setup['money_accounts'] ?? 0) > 0) {
        $lines[] = pl_tn('{count} bank or cash account named', '{count} bank and cash accounts named', (int) $setup['money_accounts'], ['count' => (int) $setup['money_accounts']]);
    }
    if (($setup['opening_total'] ?? '') !== '' && bccomp((string) $setup['opening_total'], '0', 4) > 0) {
        $journals = (int) ($setup['opening_journals'] ?? 0);
        $lines[] = pl_tn('{currency} {amount} opening money posted in one journal', '{currency} {amount} opening money posted in {count} journals', $journals,
            ['currency' => (string) $company['currency'], 'amount' => pl_money((string) $setup['opening_total']), 'count' => $journals]);
    }
    // Features the owner chose beyond what the sample structure already switched on.
    $chosen = array_values(array_diff($setup['features'] ?? [], $receipt['created']['modules'] ?? []));
    if ($chosen !== []) {
        $lines[] = pl_t('Features on: {modules}', ['modules' => implode(', ', array_map('pl_module_label', $chosen))]);
    }
    foreach ($packages as $slug => $outcome) {
        $lines[] = match ($outcome) {
            'activated', 'already_active' => pl_t('Package {slug} is active', ['slug' => $slug]),
            'not_installed' => pl_t('Package {slug} is not installed on this server; install it in Admin > Packages', ['slug' => $slug]),
            'refused' => pl_t('Package {slug} could not be activated; Admin > Packages says why', ['slug' => $slug]),
            default => pl_t('Package {slug} is named by this sample but this release has no package runtime', ['slug' => $slug]),
        };
    }
    $lines[] = pl_t('Financial year ends {date}', ['date' => pl_fiscal_year_end_label((string) $company['fiscal_year_end'])]);
    $counts = pl_onboarding_book_counts($company);
    if ($counts['journals'] > 0 || $counts['drafts'] > 0) {
        $lines[] = ($setup['opening_journals'] ?? 0) > 0 && $counts['journals'] === (int) $setup['opening_journals'] && $counts['drafts'] === 0
            ? pl_t('No sales, purchases or expenses yet: only the opening money is posted')
            : pl_t('{journals} posted journals and {drafts} open drafts are recorded in these books.', $counts);
    } else {
        $lines[] = $company['setup_status'] === 'opening_required'
            ? pl_t('Opening balances and unpaid documents are still to be brought over')
            : pl_t('Zero transactions: these books start empty');
    }
    return $lines;
}

/** A module's own manifest name, so a screen never invents a label for one. */
function pl_module_label(string $id): string
{
    return (string) (pl_module_registry()[$id]['name'] ?? $id);
}

/** Render one stage, or handle one submission. */
function pl_web_onboarding(int $actorId, array $user, string $method): never
{
    $template = pl_starter_template();
    $stages = pl_onboarding_stages();
    $order = array_keys($stages);
    require_once __DIR__ . '/plugin_functions.php';
    pl_plugin_bootstrap_initial_owner($actorId);
    $administers = pl_user_can($actorId, 0, 'installation.admin');
    if ($method === 'POST') {
        $action = pl_web_text($_POST, 'action');
        $draft = pl_onboarding_draft();
        $stage = pl_web_text($_POST, 'stage');
        $known = in_array($stage, ['start', 'business', 'owners', 'features'], true);
        if ($action === 'next') {
            if (!$known) { throw new DomainException('Choose a valid setup stage.'); }
            try {
                pl_onboarding_store(pl_onboarding_read_stage($stage, $_POST, $draft['input']));
                pl_redirect('/onboarding?stage=' . $order[(int) array_search($stage, $order, true) + 1]);
            } catch (DomainException $error) {
                pl_form_failure('/onboarding?stage=' . $stage, $_POST, $error->getMessage());
            }
        }
        if ($action === 'back' || $action === 'leave') {
            // Back and every link out keep what was typed, checked or not, so nothing is lost by
            // looking something up on another screen (owner note, 25 September 2026).
            if (!$known) { throw new DomainException('Choose a valid setup stage.'); }
            pl_onboarding_store(pl_onboarding_read_stage($stage, $_POST, $draft['input'], false));
            if ($action === 'back') {
                pl_redirect('/onboarding?stage=' . $order[max(0, (int) array_search($stage, $order, true) - 1)]);
            }
            $destination = pl_web_text($_POST, 'go');
            pl_redirect(in_array($destination, ['/packages', '/modules', '/companies'], true) ? $destination . '?return=onboarding' : '/onboarding?stage=' . $stage);
        }
        if ($action === 'sample_refresh' || $action === 'sample_install') {
            // Installing a sample happens on the Start stage and comes straight back to it.
            try {
                if ($action === 'sample_refresh') {
                    pl_sample_directory_refresh($actorId);
                    pl_notice(pl_t('Sample directory refreshed.'));
                } else {
                    pl_sample_directory_install($actorId, pl_web_text($_POST, 'slug'), pl_web_text($_POST, 'request_key'));
                    pl_notice(pl_t('Sample installed. Choose it below.'));
                }
            } catch (DomainException $error) {
                pl_form_failure('/onboarding?stage=start', [], $error->getMessage());
            }
            pl_redirect('/onboarding?stage=start');
        }
        if ($action === 'preview') {
            // The single-request path an existing caller may still post. It lands on Review,
            // which is the only place a business is ever created from.
            try {
                pl_onboarding_store(pl_onboarding_preview_input($_POST, (string) $template['digest']) + ['money_accounts' => [], 'features' => [], 'owners' => [], 'account_names' => []]);
                pl_redirect('/onboarding?stage=review');
            } catch (DomainException $error) {
                pl_form_failure('/onboarding?stage=business', $_POST, $error->getMessage());
            }
        }
        if ($action === 'confirm') {
            if ($draft['request_key'] === '') {
                pl_notice(pl_t('Please review your business details again before confirming.'));
                pl_redirect('/onboarding');
            }
            try {
                $payload = pl_onboarding_payload($draft['input'], (string) $template['digest']);
                // The single-request `preview` path skips the Owners stage; a real business still
                // needs a named account before anything is created.
                if (($payload['source'] ?? 'blank') !== 'full' && $payload['money_accounts'] === []) {
                    pl_notice(pl_t('Name at least one bank account, till or petty cash before creating the business.'));
                    pl_redirect('/onboarding?stage=owners');
                }
                $created = pl_setup_company($actorId, $payload, $draft['request_key']);
                $companyId = (int) $created['id'];
                $bookId = (int) $created['book_id'];
                // Packages are activated after the company's own transaction has committed: a
                // package runs its own migrations, and schema changes do not roll back.
                $structure = ($payload['source'] ?? 'blank') === 'skeleton'
                    ? pl_sample_structure_read((string) $payload['sample_pack']) : null;
                $packages = $structure === null ? [] : pl_sample_structure_activate_packages(
                    $actorId, $structure['packages'],
                    'Required by the ' . $structure['id'] . ' sample structure this business was created from.',
                    'skeleton:' . $companyId . ':package:'
                );
                $openingTotal = '0.0000';
                $openingJournals = 0;
                // A partnership posts capital introduced once per partner (B99), so the manifest counts what was posted.
                $partnerSplit = ($payload['source'] ?? 'blank') !== 'full' && pl_legal_form_has_partners(pl_legal_form_family((string) ($payload['legal_form'] ?? '')))
                    && ($payload['owners'] ?? []) !== [] ? pl_setup_partner_shares($payload['owners']) : [];
                foreach ($payload['money_accounts'] as $row) {
                    if (($row['opening_amount'] ?? '') !== '' && bccomp((string) $row['opening_amount'], '0', 4) > 0) {
                        $openingTotal = bcadd($openingTotal, (string) $row['opening_amount'], 4);
                        $openingJournals += ($row['opening_source'] ?? '') === 'capital_introduced' && $partnerSplit !== []
                            ? count(array_filter(pl_setup_split_amount((string) $row['opening_amount'], $partnerSplit), static fn (string $portion): bool => bccomp($portion, '0', 4) > 0))
                            : 1;
                    }
                }
                $_SESSION['company_id'] = $companyId;
                $_SESSION['onboarding_done'] = ['company_id' => $companyId, 'packages' => $packages,
                    'setup' => ['owners' => count($payload['owners']), 'money_accounts' => count($payload['money_accounts']),
                        'opening_total' => $openingTotal, 'opening_journals' => $openingJournals, 'features' => $payload['features']]];
                unset($_SESSION['onboarding']);
                pl_redirect('/onboarding?stage=ready');
            } catch (DomainException $error) {
                pl_form_failure('/onboarding?stage=review', [], $error->getMessage());
            }
        }
        throw new DomainException('Choose a valid setup action.');
    }

    $requested = pl_web_text($_GET, 'stage');
    // `?step=N` is what 1.2 and earlier linked to, and `source` was the 1.2.1-1.4 stage that the
    // Start stage now asks. Both keep working and land on the stage that asks the same question.
    $legacy = ['1' => 'start', '2' => 'business', '3' => 'business', '4' => 'start', '5' => 'review', 'preview' => 'review'];
    $stage = isset($stages[$requested]) ? $requested : ($requested === 'source' ? 'start' : ($legacy[pl_web_text($_GET, 'step')] ?? 'start'));

    if ($stage === 'ready') {
        $done = $_SESSION['onboarding_done'] ?? null;
        if (!is_array($done)) { pl_redirect('/onboarding'); }
        $company = pl_company_context($actorId, (int) $done['company_id']);
        $receipt = pl_company_sample_import($actorId, (int) $company['id'], (int) $company['book_id']);
        pl_render('onboarding', ['title' => pl_t('Your business is ready'), 'user' => $user, 'stage' => 'ready',
            'stages' => $stages, 'company' => null, 'created' => $company, 'receipt' => $receipt,
            'manifest' => pl_onboarding_manifest($company, $receipt, is_array($done['packages'] ?? null) ? $done['packages'] : [], is_array($done['setup'] ?? null) ? $done['setup'] : []),
            'form' => ['input' => [], 'message' => ''], 'input' => [], 'template' => $template, 'administers' => $administers,
            'sourceView' => [], 'gallery' => [], 'countries' => [], 'legalForms' => [], 'legalFormsJson' => '{}', 'moneyExisting' => [], 'features' => [], 'chart' => []]);
    }

    $form = pl_form_state('/onboarding?stage=' . $stage);
    $draft = pl_onboarding_draft();
    $input = $form['input'] !== [] ? $form['input'] + $draft['input'] : $draft['input'];
    if ($form['input'] === [] && is_array($draft['input']['unchecked'][$stage] ?? null)) {
        // What Back or a link out kept: shown again, checked only when the stage is submitted.
        $input = $draft['input']['unchecked'][$stage] + $input;
    }
    $reached = pl_onboarding_reached($draft['input']);
    if ((int) array_search($stage, $order, true) > (int) array_search($reached, $order, true)) {
        pl_redirect('/onboarding?stage=' . $reached);
    }
    $country = strtoupper((string) ($input['country_code'] ?? ''));
    if ($country === '' && $stage === 'business') {
        $country = strtoupper((string) (pl_regional_suggestion()['country_code'] ?? ''));
    }
    pl_render('onboarding', ['title' => pl_t('Set up a business'), 'user' => $user, 'stage' => $stage,
        'stages' => $stages, 'company' => null, 'created' => null, 'receipt' => null, 'manifest' => [],
        'form' => $form, 'input' => $input + ['country_code' => $country], 'template' => $template, 'administers' => $administers,
        'gallery' => $stage === 'start' ? pl_onboarding_gallery($administers) : [],
        'countries' => $stage === 'business' ? pl_country_options() : [],
        'legalForms' => $stage === 'business' ? pl_legal_forms_for($country) : [],
        'legalFormsJson' => $stage === 'business' ? json_encode(pl_legal_form_catalogue_for_script(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) : '{}',
        'moneyExisting' => $stage === 'owners' ? pl_onboarding_existing_money($input, $template) : [],
        'features' => in_array($stage, ['features', 'review'], true) ? pl_onboarding_features($input) : [],
        'chart' => $stage === 'features' ? pl_onboarding_chart($input, $template) : [],
        'sourceView' => $stage === 'review' ? pl_onboarding_source_view($input) : []]);
}
