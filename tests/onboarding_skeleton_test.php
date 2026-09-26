<?php
declare(strict_types=1);

/**
 * Starting a business from a sample company's structure (owner decision B50, 1.2.1 M10).
 *
 * The claim this feature makes is narrow and checkable: a skeleton brings across everything
 * that says what a business *is* and nothing that says what it *did*. So the tests below are
 * mostly about what is absent. After a skeleton import the book has no journal, no journal
 * line, no document and no draft, and its trial balance is both empty and balanced — proved
 * against the book, not against the importer's own count of what it created.
 *
 * The other half is the contract. A structure section is read and never inferred, so a
 * document that carries history, an account with a balance, a party with an opening balance or
 * a product with opening stock is refused rather than partly imported.
 *
 * The last test walks all five stages of the wizard over HTTP and creates a business through
 * them, because a screen that is routed but missing from pl_render()'s allowlist answers
 * "Unknown template" at request time and nothing else in the suite would ask for it.
 */
// The wizard's controller is loaded by its route, not by the bootstrap.
require_once dirname(__DIR__) . '/www/phpledger/includes/functions/onboarding_web_functions.php';

function skeleton_actor(string $label): int
{
    return pl_create_user('m10-' . $label . '-' . bin2hex(random_bytes(6)) . '@example.invalid',
        'Skeleton owner', 'Sample skeleton password 123!');
}

/** @return array{actor:int, company:array, key:string, input:array} */
function skeleton_business(string $sampleId, array $overrides = []): array
{
    $actor = skeleton_actor('biz');
    $input = [
        'name' => 'Skeleton business ' . bin2hex(random_bytes(4)), 'currency' => 'USD',
        'start_date' => '2026-01-01', 'fiscal_year_end' => '12-31',
        'start_mode' => 'fresh', 'chart_choice' => 'neutral', 'source' => 'skeleton',
        'sample_pack' => $sampleId, 'zero_balances_confirmed' => true,
        'template_digest' => pl_starter_template()['digest'],
    ] + $overrides;
    $key = 'm10:' . bin2hex(random_bytes(8));
    return ['actor' => $actor, 'company' => pl_setup_company($actor, $input, $key), 'key' => $key, 'input' => $input];
}

/** No journal, no line, no document, no draft, and a trial balance that is empty and balanced. */
function skeleton_assert_zero(int $actor, array $company, string $where): void
{
    $id = (int) $company['id'];
    $book = (int) $company['book_id'];
    foreach (['pl_journals', 'pl_documents', 'pl_general_drafts', 'pl_ar_documents'] as $table) {
        assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM %b WHERE company_id = %i AND book_id = %i', $table, $id, $book),
            $where . ': ' . $table . ' is not empty.');
    }
    assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journal_lines WHERE book_id = %i', $book), $where . ': a journal line exists.');
    $trial = pl_trial_balance($actor, $id, $book);
    assert_true($trial['balanced'], $where . ': the trial balance does not balance.');
    assert_same('0.0000', $trial['total_debit'], $where . ': the trial balance carries a debit.');
    assert_same('0.0000', $trial['total_credit'], $where . ': the trial balance carries a credit.');
    foreach ($trial['accounts'] as $row) {
        assert_same('0.0000', $row['balance'], $where . ': account ' . $row['code'] . ' has a balance.');
    }
}

test('every bundled sample publishes a structure, and a structure carries no history', function (): void {
    $ids = pl_sample_structure_ids();
    assert_same(12, count($ids), 'The eleven bundled packs and the starter playground are the skeleton sources.');
    foreach ($ids as $id) {
        $structure = pl_sample_structure_read($id);
        assert_true($structure !== null, $id . ' publishes no structure, so it cannot start a business.');
        assert_same($id, $structure['id']);
        assert_true(in_array($structure['origin'], ['manifest', 'catalogue'], true));
        assert_true(preg_match('/^[a-f0-9]{64}$/D', (string) $structure['digest']) === 1, $id . ' has no structure digest.');
        foreach (['events', 'drafts', 'checkpoints', 'monthly_support'] as $history) {
            assert_true(!array_key_exists($history, $structure), $id . ' leaked ' . $history . ' into its structure.');
        }
        $summary = pl_sample_structure_summary($structure);
        assert_true($summary['accounts'] > 0, $id . ' brings in no accounts.');
        // Reports are read back from the module manifests, never stored, so the wizard cannot
        // promise a report the business will not actually have.
        foreach ($summary['reports'] as $report) { assert_true(is_string($report) && $report !== ''); }
    }
    // The catalogue is stale-proof: a structure pinned to a pack digest stops being offered
    // when that pack moves, instead of being offered against a chart it no longer matches.
    // Every committed structure is pinned to the pack that is on disk right now.
    foreach (array_keys(pl_demo_pack_catalog()) as $id) {
        $document = json_decode((string) file_get_contents(pl_sample_structure_directory() . '/' . $id . '-' . pl_demo_pack($id)['version'] . '.json'), true, 64, JSON_THROW_ON_ERROR);
        assert_same(pl_demo_pack($id)['digest'], $document['source_digest'] ?? null,
            'The ' . $id . ' structure is pinned to a different pack than the one on disk; regenerate it with tools/build-sample-structures.py --write.');
        assert_true(pl_sample_structure_is_current($document['source_digest'], pl_demo_pack($id)['digest']));
        assert_same(false, pl_sample_structure_is_current($document['source_digest'], str_repeat('0', 64)),
            'A structure whose pack moved is still treated as current.');
    }
    // The starter playground is built in code and has no pack file to drift from, so its
    // structure is unpinned and stays current by definition.
    assert_true(pl_sample_structure_is_current(null, str_repeat('0', 64)));
    assert_true(pl_sample_structure_is_current('', str_repeat('0', 64)));
});

test('the structure contract accepts its reference document and refuses history, balances and stock', function (): void {
    $reference = json_decode((string) file_get_contents(__DIR__ . '/fixtures/sample-structure-contract.json'), true, 64, JSON_THROW_ON_ERROR);
    $structure = pl_sample_structure_validate($reference, 'contract-reference', '1.0.0', 'manifest');
    assert_same(5, count($structure['accounts']));
    assert_same(['inventory', 'purchasing'], $structure['modules']);
    assert_same(2, count($structure['number_series']));
    assert_same(2, count($structure['parties']));
    assert_same(2, count($structure['products']));
    assert_same(1, count($structure['tax_codes']));

    $reject = static function (array $change, string $why) use ($reference): void {
        assert_throws(fn () => pl_sample_structure_validate(array_replace($reference, $change), 'contract-reference', '1.0.0', 'manifest'),
            DomainException::class, null);
    };
    // History in a structure section is the thing this whole separation exists to prevent.
    foreach (['events', 'drafts', 'checkpoints', 'monthly_support', 'journals', 'documents'] as $history) {
        $reject([$history => []], $history . ' was accepted inside a structure.');
    }
    $reject(['accounts' => [['code' => '1410', 'name' => 'x', 'type' => 'asset', 'opening_balance' => '10.0000']]], 'an account balance');
    $reject(['accounts' => [['code' => '1410', 'name' => 'x', 'type' => 'asset'], ['code' => '1410', 'name' => 'y', 'type' => 'asset']]], 'a repeated code');
    $reject(['accounts' => [['code' => '1410', 'name' => 'x', 'type' => 'nonsense']]], 'an unknown classification');
    $reject(['parties' => [['legal_name' => 'x', 'is_customer' => true, 'is_vendor' => false, 'opening_balance' => '1.0000']]], 'a party balance');
    $reject(['products' => [['sku' => 'X', 'name' => 'x', 'kind' => 'stock', 'opening_quantity' => '5']]], 'opening stock');
    $reject(['modules' => ['core']], 'a required module');
    $reject(['modules' => ['no-such-module']], 'an unknown module');
    $reject(['number_series' => [['type' => 'no-such-type', 'prefix' => 'X', 'padding' => 6, 'year_segment' => true, 'reset_rule' => 'yearly']]], 'an unknown document type');
    $reject(['company_profile' => ['legal_name' => 'Another business']], 'another business\'s identity');
    $reject(['contract' => 2], 'a contract this release does not implement');
    assert_throws(fn () => pl_sample_structure_validate($reference, 'other-sample', '1.0.0', 'manifest'), DomainException::class);
    assert_throws(fn () => pl_sample_structure_read('no-such-sample'), DomainException::class);
});

test('a skeleton gives a real business its structure and not one unit of money', function (): void {
    $fixture = skeleton_business('trader');
    $actor = $fixture['actor'];
    $company = $fixture['company'];
    $id = (int) $company['id'];
    $book = (int) $company['book_id'];
    $structure = pl_sample_structure_read('trader');

    // It is a real business, not an isolated sample: that is the defect B50 names.
    assert_same(false, $company['is_sample'], 'A skeleton created an isolated sample instead of a business.');
    assert_same('ready', $company['setup_status']);
    assert_true(str_starts_with((string) $company['name'], 'Skeleton business '), 'The business kept a different name.');
    assert_same('2026-01-01', (string) $company['start_date'], 'A skeleton did not keep the dates its owner chose.');

    skeleton_assert_zero($actor, $company, 'A skeleton import');

    // The chart is the starter chart plus exactly what the structure declares, and every
    // account it declares is there by its own code.
    $codes = array_column($company['accounts'], 'code');
    $legacy = array_column($company['accounts'], 'legacy_code');
    foreach ($structure['accounts'] as $definition) {
        assert_true(in_array($definition['code'], $codes, true) || in_array($definition['code'], $legacy, true),
            'The skeleton did not create account ' . $definition['code'] . '.');
    }
    // The bundled chart's posting accounts, its class and group headings, and the structure's own
    // accounts: a skeleton adds the sample's chart and nothing else.
    assert_same(count(pl_starter_template()['accounts']) + count(pl_starter_template()['headings']) + count(pl_starter_template()['provisions']) + count($structure['accounts']),
        count($company['accounts']),
        'The skeleton created accounts its structure does not declare, or missed some it does.');
    // A structure never ships a chart of disabled accounts.
    foreach ($company['accounts'] as $account) { assert_true($account['is_active'], 'Account ' . $account['code'] . ' arrived inactive.'); }

    foreach ($structure['modules'] as $module) {
        assert_true(pl_module_state($id, $module)['enabled'], 'Module ' . $module . ' was not turned on for the skeleton.');
        assert_true(pl_module_available($actor, $id, $book, $module), 'Module ' . $module . ' is on but unusable.');
    }
    foreach ($structure['number_series'] as $series) {
        $row = pl_document_series_view(pl_document_series_row($actor, $id, $book, (string) $series['type']));
        assert_same($series['prefix'], $row['prefix'], 'Series ' . $series['type'] . ' kept the default prefix.');
        assert_same(1, $row['next_number'], 'A skeleton consumed a document number.');
    }
    assert_same(count($structure['parties']), (int) pl_page_parties($actor, $id, $book, [])['total'],
        'The customers and suppliers the structure declares were not created.');
    assert_same(count($structure['products']), count(pl_list_inventory_products($actor, $id, $book)));
    foreach (pl_list_inventory_products($actor, $id, $book) as $product) {
        assert_true($product['is_active']);
        assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_inventory_movements WHERE company_id = %i AND book_id = %i AND product_id = %i', $id, $book, (int) $product['id']),
            'A skeleton product arrived with a stock movement.');
    }
    if ($structure['policies'] !== []) {
        assert_same($structure['policies']['discount_posting'], pl_trading_policies($actor, $id, $book)['discount_posting']);
    }

    // The receipt is the durable claim, and it is immutable.
    $receipt = pl_company_sample_import($actor, $id, $book);
    assert_true($receipt !== null, 'A skeleton import recorded no receipt.');
    assert_same('skeleton', $receipt['mode']);
    assert_same('trader', $receipt['sample_id']);
    assert_same($structure['digest'], $receipt['structure_digest']);
    assert_same(count($structure['accounts']), (int) $receipt['created']['accounts']);
    assert_throws(fn () => DB::update('pl_sample_imports', ['mode' => 'full'], 'id = %i', $receipt['id']), Throwable::class);
    assert_same(1, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_template_installation_history WHERE company_id = %i AND snapshot_kind = %s', $id, 'skeleton'));

    // Confirming the same setup twice is the same business, not a second one.
    $again = pl_setup_company($actor, $fixture['input'], $fixture['key']);
    assert_same($id, (int) $again['id']);
    assert_same(count($company['accounts']), count($again['accounts']), 'A replayed confirmation created a second chart.');
    assert_same(1, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_sample_imports WHERE company_id = %i', $id));
    skeleton_assert_zero($actor, $again, 'A replayed skeleton import');
});

test('the starter playground skeleton brings its tax code and practice party with nothing posted', function (): void {
    $fixture = skeleton_business('accounting-starter');
    $actor = $fixture['actor'];
    $company = $fixture['company'];
    skeleton_assert_zero($actor, $company, 'The starter skeleton');
    $receipt = pl_company_sample_import($actor, (int) $company['id'], (int) $company['book_id']);
    assert_same(1, (int) $receipt['created']['tax_codes'], 'The starter skeleton did not bring its sample tax code.');
    assert_same(1, (int) $receipt['created']['parties']);
    assert_same(2, (int) $receipt['created']['products']);
    assert_same(['inventory', 'purchasing'], $receipt['created']['modules']);
    // Its own sample is built in code and has no pack file, so its structure is unpinned and
    // reading it is still an ordinary catalogue read.
    assert_same('catalogue', $receipt['structure_origin']);
});

test('a skeleton refuses a book that already holds records, and never replaces one', function (): void {
    $fixture = skeleton_business('retail-shop');
    $actor = $fixture['actor'];
    $id = (int) $fixture['company']['id'];
    $book = (int) $fixture['company']['book_id'];
    $accounts = pl_account_code_mapping($fixture['company']['accounts']);
    pl_save_and_post_general_draft($actor, $id, $book, [
        'date' => '2026-02-01', 'reference' => 'M10 actual owner funding', 'description' => 'Owner funds the existing expense',
        'creation_key' => 'm10-funding-' . bin2hex(random_bytes(6)),
        'lines' => [
            ['account_id' => $accounts['1000'], 'debit' => '15.0000', 'credit' => '0.0000', 'description' => 'Bank funding'],
            ['account_id' => $accounts['3000'], 'debit' => '0.0000', 'credit' => '15.0000', 'description' => 'Owner capital'],
        ],
    ]);
    pl_save_and_post_general_draft($actor, $id, $book, [
        'date' => '2026-02-02', 'reference' => 'M10 existing record', 'description' => 'An entry that already exists',
        'creation_key' => 'm10-existing-' . bin2hex(random_bytes(6)),
        'lines' => [
            ['account_id' => $accounts['5000'], 'debit' => '15.0000', 'credit' => '0.0000', 'description' => 'Debit'],
            ['account_id' => $accounts['1000'], 'debit' => '0.0000', 'credit' => '15.0000', 'description' => 'Credit'],
        ],
    ]);
    assert_throws(fn () => pl_import_sample_skeleton($actor, $id, $book, 'trader', 'm10-refuse-' . bin2hex(random_bytes(6))),
        DomainException::class, 'new empty business');
    assert_same(2, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE company_id = %i AND book_id = %i', $id, $book),
        'The refused import touched the book.');
    assert_same(1, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_sample_imports WHERE company_id = %i', $id));
});

test('the source rules keep a fictional history out of a real business', function (): void {
    $actor = skeleton_actor('rules');
    $base = ['name' => 'Rules business', 'currency' => 'USD', 'start_date' => '2026-01-01',
        'fiscal_year_end' => '12-31', 'chart_choice' => 'neutral', 'zero_balances_confirmed' => true,
        'template_digest' => pl_starter_template()['digest']];
    $refuse = static function (array $input) use ($actor, $base): void {
        assert_throws(fn () => pl_setup_company($actor, $input + $base, 'm10-rule:' . bin2hex(random_bytes(8))), DomainException::class);
    };
    // A full sample carries fictional transactions, so it is only ever a separate sample.
    $refuse(['start_mode' => 'fresh', 'source' => 'full', 'sample_pack' => 'trader']);
    // Past records bring their own chart through the opening cutover.
    $refuse(['start_mode' => 'existing', 'source' => 'skeleton', 'sample_pack' => 'trader', 'zero_balances_confirmed' => false]);
    // A blank business has no sample, and a sample source has to name one.
    $refuse(['start_mode' => 'fresh', 'source' => 'blank', 'sample_pack' => 'trader']);
    $refuse(['start_mode' => 'fresh', 'source' => 'skeleton']);
    $refuse(['start_mode' => 'fresh', 'source' => 'nonsense', 'sample_pack' => 'trader']);
    $refuse(['start_mode' => 'fresh', 'source' => 'skeleton', 'sample_pack' => '../../etc/passwd']);

    // The stages the wizard offers follow the same rule, so a screen can never present a
    // source the service would refuse.
    assert_same(['blank', 'skeleton'], pl_onboarding_sources('fresh'));
    assert_same(['blank'], pl_onboarding_sources('existing'));
    assert_same(['skeleton', 'full'], pl_onboarding_sources('sample'));

    // A sample company started from a skeleton keeps its own dates; a full one takes the
    // sample's pinned history dates, and pl_onboarding_payload() is where that is decided.
    $skeleton = pl_onboarding_payload(['start_mode' => 'sample', 'source' => 'skeleton', 'sample_pack' => 'trader',
        'start_date' => '2026-03-01', 'fiscal_year_end' => '06-30'], 'digest');
    assert_same('2026-03-01', $skeleton['start_date']);
    assert_same('06-30', $skeleton['fiscal_year_end']);
    $full = pl_onboarding_payload(['start_mode' => 'sample', 'source' => 'full', 'sample_pack' => 'trader',
        'start_date' => '2026-03-01', 'fiscal_year_end' => '06-30'], 'digest');
    assert_same('2024-01-01', $full['start_date']);
    assert_same('12-31', $full['fiscal_year_end']);
});

test('a sample that needs packages says what happened to them instead of claiming an activation', function (): void {
    $actor = skeleton_actor('packages');
    // The plugin runtime is a separate branch. Whether it is loaded or not, the seam reports a
    // real outcome per package and never silently reports success.
    $outcome = pl_sample_structure_activate_packages($actor, ['no-such-package'], 'Required by a sample structure.', 'm10-test:');
    assert_same(1, count($outcome));
    assert_true(in_array($outcome['no-such-package'], ['runtime_unavailable', 'not_installed', 'refused'], true),
        'The package seam reported ' . $outcome['no-such-package'] . '.');
    assert_same([], pl_sample_structure_activate_packages($actor, [], 'Nothing to do.', 'm10-test:'));
    // No bundled sample names a package yet, so none of them can claim one silently.
    foreach (pl_sample_structure_ids() as $id) {
        assert_same([], pl_sample_structure_read($id)['packages'], $id . ' names a package that does not ship.');
    }
});

test('all five wizard stages answer over HTTP, and walking them creates the business', function (): void {
    $suffix = bin2hex(random_bytes(6));
    $email = 'm10-wizard-' . $suffix . '@example.test';
    $password = 'Sample wizard password ' . $suffix;
    pl_create_user($email, 'Wizard owner', $password);

    $root = dirname(__DIR__) . '/www/phpledger/public';
    $port = random_int(20000, 50000);
    $base = 'http://127.0.0.1:' . $port;
    $log = sys_get_temp_dir() . '/phpledger-m10-wizard-' . $suffix . '.log';
    // Named per run: a repeated run against the same test database would otherwise find the
    // business the last one created and read it as one created before it was confirmed.
    $business = 'Wizard walk business ' . $suffix;
    $environment = array_replace(getenv(), ['PL_SESSION_SECURE' => '0']);
    $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, dirname(__DIR__), $environment);
    if (!is_resource($server)) {
        throw new RuntimeException('Sample HTTP server unavailable.');
    }
    fclose($pipes[0]);
    $cookie = '';
    $request = static function (string $path, ?array $body = null) use ($base, &$cookie): array {
        $headers = ['Connection: close'];
        if ($cookie !== '') { $headers[] = 'Cookie: ' . $cookie; }
        if ($body !== null) { $headers[] = 'Content-Type: application/x-www-form-urlencoded'; }
        $context = stream_context_create(['http' => ['method' => $body === null ? 'GET' : 'POST',
            'header' => implode("\r\n", $headers), 'content' => $body === null ? '' : http_build_query($body),
            'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 20]]);
        $response = @file_get_contents($base . $path, false, $context);
        $responseHeaders = $http_response_header ?? [];
        $location = '';
        foreach ($responseHeaders as $header) {
            if (preg_match('/^Set-Cookie: ([^;]+)/i', $header, $match)) { $cookie = $match[1]; }
            if (preg_match('/^Location: (.+)$/i', trim($header), $match)) { $location = trim($match[1]); }
        }
        preg_match('/\s(\d{3})\s/', $responseHeaders[0] ?? '', $status);
        return [(int) ($status[1] ?? 0), $response === false ? '' : $response, $location];
    };
    $token = static function (string $html): string {
        preg_match('/name="csrf" value="([a-f0-9]+)"/', $html, $match);
        return (string) ($match[1] ?? '');
    };
    try {
        for ($retry = 0; $retry < 100; $retry++) {
            [$status, $body] = $request('/login');
            if ($status) { break; }
            usleep(20000);
        }
        assert_same(200, $status, 'The sample server did not start: ' . substr((string) file_get_contents($log), -400));
        [$status] = $request('/login', ['email' => $email, 'password' => $password, 'csrf' => $token($body)]);
        assert_true(in_array($status, [200, 302, 303], true), 'Sign-in did not complete: status ' . $status);

        // Stage 1: what are you starting from. The four cards and the sample gallery.
        [$status, $body] = $request('/onboarding');
        assert_same(200, $status, 'The wizard did not render: ' . substr((string) file_get_contents($log), -400));
        assert_true(str_contains($body, 'name="start"') && str_contains($body, 'value="structure"'), 'The Start stage offers no sample structure.');
        assert_true(str_contains($body, 'Willow Corner Shop'), 'The Start stage does not show the sample gallery.');
        assert_true(!str_contains($body, 'zero_balances_confirmed'), 'The confirmation checkbox is back.');
        [$status, , $location] = $request('/onboarding', ['csrf' => $token($body), 'action' => 'next', 'stage' => 'start', 'start' => 'structure', 'sample_pack' => 'retail-shop']);
        assert_same(303, $status, 'The Start stage did not advance.');
        assert_true(str_ends_with($location, '/onboarding?stage=business'), 'The Start stage advanced to ' . $location);

        // Stage 2: the business, in its country's own words.
        [$status, $body] = $request('/onboarding?stage=business');
        assert_same(200, $status);
        assert_true(str_contains($body, 'name="country_code"') && str_contains($body, 'name="legal_form"') && str_contains($body, 'legal-forms-data'), 'The Business stage has no country-aware legal form.');
        assert_true(str_contains($body, 'name="fiscal_year_end_choice"'), 'The Business stage has no financial year end.');
        [$status, , $location] = $request('/onboarding', ['csrf' => $token($body), 'action' => 'next', 'stage' => 'business',
            'name' => $business, 'country_code' => 'PK', 'legal_form' => 'pk.pvt_ltd', 'currency' => 'USD', 'start_date' => '2026-04-01',
            'fiscal_year_end_choice' => '06-30', 'legal_name' => $business . ' (Private) Limited', 'registration_number' => '0123456', 'registration_authority' => 'SECP', 'tax1' => '1234567-8']);
        assert_same(303, $status, 'The Business stage did not advance.');
        assert_true(str_ends_with($location, '/onboarding?stage=owners'), 'The Business stage advanced to ' . $location);

        // Stage 3: owners, and the named bank and cash accounts under the group.
        [$status, $body] = $request('/onboarding?stage=owners');
        assert_same(200, $status);
        assert_true(str_contains($body, 'name="money_name[]"') && str_contains($body, 'Reserve bank'), 'The Owners stage does not offer the skeleton\'s own money accounts.');
        [$status, , $location] = $request('/onboarding', ['csrf' => $token($body), 'action' => 'next', 'stage' => 'owners',
            'owner_name' => ['Wizard Owner', ''], 'owner_kind' => ['person', 'person'], 'owner_role' => ['Director & shareholder', ''], 'owner_share' => ['100', ''],
            'money_kind' => ['bank', 'petty'], 'money_code' => ['1-100-21010-00', ''], 'money_name' => ['Sample Bank current 0001', 'Petty cash'],
            'money_custodian' => ['', 'Ali Khan'], 'money_amount' => ['1,000', ''], 'money_source' => ['capital_introduced', '']]);
        assert_same(303, $status, 'The Owners stage did not advance.');
        assert_true(str_ends_with($location, '/onboarding?stage=features'), 'The Owners stage advanced to ' . $location);

        // Stage 4: features, and the account names.
        [$status, $body] = $request('/onboarding?stage=features');
        assert_same(200, $status);
        assert_true(str_contains($body, 'name="features[]"') && str_contains($body, 'name="account_name['), 'The Features stage offers nothing to choose.');
        [$status, , $location] = $request('/onboarding', ['csrf' => $token($body), 'action' => 'next', 'stage' => 'features',
            'features' => ['inventory'], 'account_name' => ['5-100-10001-00' => 'Office expenses']]);
        assert_same(303, $status, 'The Features stage did not advance.');
        assert_true(str_ends_with($location, '/onboarding?stage=review'), 'The Features stage advanced to ' . $location);

        // Stage 5: what is about to be created, and nothing created yet.
        [$status, $body] = $request('/onboarding?stage=review');
        assert_same(200, $status);
        assert_true(str_contains($body, 'Willow Corner Shop'), 'The Review stage does not name the sample it will use.');
        assert_true(str_contains($body, 'Petty cash') && str_contains($body, 'Ali Khan'), 'The Review stage does not list the money accounts.');
        assert_true(str_contains($body, 'action" value="confirm"'), 'The Review stage has no confirmation.');
        assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_companies WHERE name = %s', $business),
            'The wizard created the business before it was confirmed.');

        [$status, , $location] = $request('/onboarding', ['csrf' => $token($body), 'action' => 'confirm']);
        assert_same(303, $status, 'Confirming did not complete.');
        assert_true(str_ends_with($location, '/onboarding?stage=ready'), 'Confirming went to ' . $location);

        // Ready: the manifest of what now exists.
        [$status, $body] = $request('/onboarding?stage=ready');
        assert_same(200, $status);
        assert_true(str_contains($body, $business), 'The Ready stage does not name the business.');
        assert_true(str_contains($body, 'opening money posted'), 'The Ready stage does not mention the opening money.');
    } finally {
        proc_terminate($server);
        proc_close($server);
        @unlink($log);
    }

    $companyId = (int) DB::queryFirstField('SELECT id FROM pl_companies WHERE name = %s', $business);
    assert_true($companyId > 0, 'The wizard walk created no business.');
    $ownerId = (int) DB::queryFirstField('SELECT created_by FROM pl_companies WHERE id = %i', $companyId);
    $created = pl_company_context($ownerId, $companyId);
    assert_same(false, $created['is_sample']);
    assert_same('06-30', (string) $created['fiscal_year_end'], 'The wizard lost the financial year end it was given.');
    assert_same('2026-04-01', (string) $created['start_date'], 'The wizard lost the accounting start it was given.');
    $receipt = pl_company_sample_import($ownerId, $companyId, (int) $created['book_id']);
    assert_true($receipt !== null && $receipt['sample_id'] === 'retail-shop', 'The wizard walk recorded no skeleton receipt.');
    // The skeleton itself came in at zero; the only postings are the opening money the owner asked for.
    $accounts = array_column($created['accounts'], null, 'code');
    assert_same('Sample Bank current 0001', (string) $accounts['1-100-21010-00']['name'], 'The skeleton\'s bank account was not renamed in place.');
    assert_same('Office expenses', (string) $accounts['5-100-10001-00']['name'], 'The account name chosen on the Features stage was lost.');
    // The second row named no code, so it took the starter's generic leaf: nothing is left called "Cash and bank".
    assert_same('Petty cash — Ali Khan', (string) $accounts['1-100-10001-00']['name'], 'The custodian is not part of the petty cash name, or the generic leaf survived.');
    assert_same('core.cash_bank', (string) $accounts['1-100-10001-00']['semantic_key'], 'The renamed leaf lost its semantic key.');
    assert_same('Petty cash', (string) $accounts['1-100-21030-00']['name'], 'The skeleton\'s own petty cash was touched.');
    assert_same(1, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE company_id = %i AND book_id = %i', $companyId, (int) $created['book_id']), 'Exactly one opening journal was expected.');
    $trial = pl_trial_balance($ownerId, $companyId, (int) $created['book_id']);
    assert_true($trial['balanced'], 'The opening money unbalanced the books.');
    $balances = array_column($trial['accounts'], 'balance', 'code');
    assert_same('1000.0000', $balances['1-100-21010-00'], 'The opening capital did not reach the named bank account.');
    assert_same(1, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_ownership_parties WHERE company_id = %i', $companyId), 'The owner was not registered.');
    assert_same('pk.pvt_ltd', (string) pl_company_profile($ownerId, $companyId)['legal_form'], 'The legal form was not saved in its country\'s words.');
    assert_true(pl_module_state($companyId, 'inventory')['enabled'], 'The chosen feature was not switched on.');
    assert_same('strict', pl_cash_balance_policy($companyId, (int) $created['book_id']), 'A wizard-created business does not get the strict cash policy.');
});


test('ready manifest reports actual scoped history after full import and resumed setup', function (): void {
    $f = ledger_fixture();
    $company = pl_company_context($f['actor_id'], $f['company_id']);
    assert_true(in_array('Zero transactions: these books start empty', pl_onboarding_manifest($company, null, []), true));
    pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], ledger_payload($f));
    $company['is_sample'] = true;
    $lines = pl_onboarding_manifest($company, null, []);
    assert_true(in_array('1 posted journals and 0 open drafts are recorded in these books.', $lines, true));
    assert_true(!in_array('Zero transactions: these books start empty', $lines, true));
    $other = ledger_fixture();
    assert_same(['journals' => 0, 'drafts' => 0], pl_onboarding_book_counts(pl_company_context($other['actor_id'], $other['company_id'])));
    $ar = ar_ap_fixture();
    pl_save_ar_document($ar['actor_id'], $ar['company_id'], $ar['book_id'], ar_ap_input($ar));
    assert_same(['journals' => 0, 'drafts' => 1], pl_onboarding_book_counts(pl_company_context($ar['actor_id'], $ar['company_id'])));
    $general = pl_save_general_draft($other['actor_id'], $other['company_id'], $other['book_id'], core_general_input($other));
    $otherCompany = pl_company_context($other['actor_id'], $other['company_id']);
    assert_same(['journals' => 0, 'drafts' => 1], pl_onboarding_book_counts($otherCompany));
    pl_post_general_draft($other['actor_id'], $other['company_id'], $other['book_id'], $general['id'], $general['revision']);
    assert_same(['journals' => 1, 'drafts' => 0], pl_onboarding_book_counts($otherCompany));
    $receiptDraft = pl_save_document($other['actor_id'], $other['company_id'], $other['book_id'], document_input($other, 'receipt'));
    assert_same(['journals' => 1, 'drafts' => 1], pl_onboarding_book_counts($otherCompany));
    pl_post_document($other['actor_id'], $other['company_id'], $other['book_id'], $receiptDraft['id'], $receiptDraft['revision']);
    assert_same(['journals' => 2, 'drafts' => 0], pl_onboarding_book_counts($otherCompany));
    $company['is_sample'] = false; // Returning to a previously completed real-company setup is also truthful.
    assert_same($lines, pl_onboarding_manifest($company, null, []));
});
