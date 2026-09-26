<?php
declare(strict_types=1);

/**
 * The 1.4.5 business setup (owner review of 25-26 September 2026): legal forms in each country's
 * own words, and the wizard's additions to pl_setup_company() — the registration profile, the
 * owners, the named bank and cash accounts with opening money, the features and the strict cash
 * policy — proved against the book, not against the wizard's own summary.
 */
require_once dirname(__DIR__) . '/www/phpledger/includes/functions/legal_form_functions.php';
require_once dirname(__DIR__) . '/www/phpledger/includes/functions/owner_functions.php';

function wizard_actor(string $label): int
{
    return pl_create_user('wizard-' . $label . '-' . bin2hex(random_bytes(6)) . '@example.invalid', 'Wizard owner', 'Sample wizard password 123!');
}

/** @return array<string, mixed> a fresh, blank business with the wizard's additions; an override wins over the default */
function wizard_input(array $overrides = []): array
{
    return $overrides + [
        'name' => 'Wizard business ' . bin2hex(random_bytes(4)), 'currency' => 'PKR', 'start_date' => '2026-07-01', 'fiscal_year_end' => '06-30',
        'start_mode' => 'fresh', 'chart_choice' => 'neutral', 'source' => 'blank', 'zero_balances_confirmed' => true,
        'template_digest' => pl_starter_template()['digest'],
        'country_code' => 'PK', 'legal_form' => 'pk.pvt_ltd',
        'profile' => ['legal_name' => 'Wizard (Private) Limited', 'registration_number' => '0123456', 'registration_authority' => 'SECP', 'tax_registrations' => 'NTN 1234567-8'],
        'owners' => [['name' => 'Rana Owner', 'kind' => 'person', 'role' => 'Director & shareholder', 'share' => '60'],
            ['name' => 'Sara Owner', 'kind' => 'person', 'role' => 'Shareholder', 'share' => '40'], ['name' => '', 'kind' => 'person', 'role' => '', 'share' => '']],
        'money_accounts' => [
            ['kind' => 'bank', 'code' => '', 'name' => 'Meezan Bank — current 0123', 'custodian' => '', 'opening_amount' => '1,500,000', 'opening_source' => 'capital_introduced'],
            ['kind' => 'petty', 'code' => '', 'name' => 'Petty cash', 'custodian' => 'Ali Khan', 'opening_amount' => '20000', 'opening_source' => 'owner_loan_received'],
            ['kind' => 'till', 'code' => '', 'name' => 'Shop till', 'custodian' => '', 'opening_amount' => '', 'opening_source' => ''],
        ],
        'features' => ['inventory'], 'account_names' => ['5-100-10001-00' => 'Office expenses'], 'cash_policy' => 'strict',
    ];
}

test('legal forms speak each country\'s own words and resolve to the families the register knows', function (): void {
    $pakistan = pl_legal_forms_for('PK');
    assert_true(isset($pakistan['pk.pvt_ltd']) && str_contains($pakistan['pk.pvt_ltd'], '(Private) Limited'), 'Pakistan does not list (Private) Limited.');
    assert_same('private_limited', pl_legal_form_family('pk.pvt_ltd'));
    assert_same('single_member_company', pl_legal_form_family('pk.smc'));
    assert_same('private_limited', pl_legal_form_family('private_limited'), 'A family key resolves to itself.');
    assert_same('', pl_legal_form_family('xx.nonsense'));
    assert_true(pl_legal_form_has_shares('pk.pvt_ltd') && pl_legal_form_has_shares('gb.ltd') && pl_legal_form_has_shares('private_limited'));
    assert_true(pl_legal_form_has_partners('gb.partnership') && pl_legal_form_has_partners('in.llp') && !pl_legal_form_has_partners('ae.llc'));
    assert_same('GB', pl_legal_form_country('gb.ltd'));
    assert_same(null, pl_legal_form_country('zz.llc'), 'The neutral list remembers no country.');
    assert_same(null, pl_legal_form_country('private_limited'));
    assert_true(pl_legal_form_known('ee.ou') && pl_legal_form_known('partnership') && !pl_legal_form_known('') && !pl_legal_form_known('made-up'));
    assert_true(str_contains(pl_legal_form_label('ae.llc'), 'LLC') && pl_legal_form_label('llp') === 'Limited liability partnership');
    assert_same('ee.ou', pl_legal_form_default('EE'));
    assert_same('', pl_legal_form_default('FR'), 'A country outside the catalogue pre-selects nothing.');
    assert_same('', pl_legal_form_default(null));
    assert_same('ZZ', pl_legal_form_country_profile('FR')['code']);
    // A country the catalogue does not name keeps its code in front of a neutral form.
    assert_true(isset(pl_legal_forms_for('FR')['fr.private_limited']) && !isset(pl_legal_forms_for('FR')['zz.private_limited']));
    assert_same('FR', pl_legal_form_country('fr.private_limited'));
    assert_same('private_limited', pl_legal_form_family('fr.private_limited'));
    assert_same('Private limited company', pl_legal_form_label('fr.private_limited'));
    assert_true(pl_legal_form_known('fr.partnership') && !pl_legal_form_known('xx.private_limited'), 'A code that is not a country is refused.');
    assert_true(pl_legal_form_has_shares('gb.cic') && !pl_legal_form_has_shares('gb.clg'));
    assert_same('SECP registration number (CUIN)', pl_legal_form_country_profile('PK')['labels']['reg_number']);
    $guide = pl_legal_form_guide('pk.pvt_ltd');
    assert_true(str_contains($guide, 'Registered with SECP') && str_contains($guide, 'On invoices: CUIN, NTN, STRN'), 'The guide line is incomplete: ' . $guide);
    assert_same('', pl_legal_form_guide('private_limited'));
    foreach (pl_legal_form_catalogue()['countries'] as $code => $country) {
        $default = pl_legal_form_default((string) $code);
        assert_true($code === 'ZZ' ? $default === '' : isset(pl_legal_forms_for((string) $code)[$default]), $code . ' defaults to a form it does not list.');
    }
    $countries = pl_country_options();
    assert_same('Pakistan', $countries['PK']);
    assert_true(count($countries) > 200, 'The country list is too short to be the CLDR registry.');
    assert_same(array_values($countries), (function () use ($countries): array { $sorted = $countries; asort($sorted, SORT_NATURAL | SORT_FLAG_CASE); return array_values($sorted); })(), 'Countries are not sorted by name.');
});

test('a company profile keeps a legal form in its country\'s words and still knows its family', function (): void {
    $actor = wizard_actor('profile');
    $f = ['actor_id' => $actor] + pl_create_company($actor, 'Wizard profile company ' . bin2hex(random_bytes(4)), 'USD', '2026-01-01');
    pl_capability_cache_reset();
    $before = pl_company_profile($f['actor_id'], $f['company_id']);
    $profile = pl_save_company_profile($f['actor_id'], $f['company_id'], ['legal_form' => 'pk.smc', 'legal_name' => 'Fixture (SMC-Private) Limited',
        'revision' => $before['revision'], 'reason' => 'Test', 'idempotency_key' => 'wizard-profile-' . bin2hex(random_bytes(6))]);
    assert_same('pk.smc', $profile['legal_form']);
    assert_true(str_contains($profile['legal_form_label'], 'SMC'), 'The profile does not show the local name.');
    assert_true(pl_legal_form_has_shares($profile['legal_form']), 'A single member company issues shares.');
    assert_throws(fn () => pl_save_company_profile($f['actor_id'], $f['company_id'], ['legal_form' => 'xx.made_up', 'revision' => $profile['revision'], 'reason' => 'Test', 'idempotency_key' => 'wizard-profile-' . bin2hex(random_bytes(6))]), DomainException::class);
    // The Company profile screen posts every profile field, so every field it does not show is
    // blanked on save. The registration half now has its inputs, with the same country-aware picker.
    $view = (string) file_get_contents(dirname(__DIR__) . '/www/phpledger/templates/views/company-profile.php');
    foreach (['name="legal_form"', "'registration_number' =>", "'registration_authority' =>", 'data-legal-forms', 'id="legal-forms-data"'] as $needle) {
        assert_true(str_contains($view, $needle), 'The Company profile screen lacks ' . $needle);
    }
});

test('setup registers the owners, names the bank and cash accounts, posts the opening money and sets the strict cash policy', function (): void {
    $actor = wizard_actor('extras');
    $input = wizard_input();
    $key = 'wizard:' . bin2hex(random_bytes(8));
    $company = pl_setup_company($actor, $input, $key);
    $id = (int) $company['id'];
    $book = (int) $company['book_id'];
    $accounts = array_column($company['accounts'], null, 'code');
    // The starter's generic leaf became the first named bank account and kept its semantic key.
    assert_same('Meezan Bank — current 0123', (string) $accounts['1-100-10001-00']['name']);
    assert_same('core.cash_bank', (string) $accounts['1-100-10001-00']['semantic_key']);
    assert_same('bank', (string) DB::queryFirstField('SELECT money_kind FROM pl_accounts WHERE id = %i', (int) $accounts['1-100-10001-00']['id']));
    // The others are new leaves under the same group, numbered after it; the custodian is in the name.
    assert_same('Petty cash — Ali Khan', (string) $accounts['1-100-10002-00']['name']);
    assert_same('physical', (string) DB::queryFirstField('SELECT money_kind FROM pl_accounts WHERE id = %i', (int) $accounts['1-100-10002-00']['id']));
    assert_same('Shop till', (string) $accounts['1-100-10003-00']['name']);
    assert_same('cash_bank', (string) $accounts['1-100-10003-00']['role']);
    // Nothing posts to the group.
    assert_same('1-100-00000-00', (string) $accounts['1-100-00000-00']['code']);
    assert_true(!$accounts['1-100-00000-00']['is_postable'], 'The Cash and cash equivalents group became postable.');
    // The account name chosen before creation.
    assert_same('Office expenses', (string) $accounts['5-100-10001-00']['name']);
    // Opening money: two journals on the start date, balanced, in the named accounts.
    assert_same(2, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE company_id = %i AND book_id = %i', $id, $book));
    assert_same('2026-07-01', (string) DB::queryFirstField('SELECT MIN(journal_date) FROM pl_journals WHERE company_id = %i AND book_id = %i', $id, $book));
    $trial = pl_trial_balance($actor, $id, $book);
    assert_true($trial['balanced']);
    $balances = array_column($trial['accounts'], 'balance', 'code');
    assert_same('1500000.0000', $balances['1-100-10001-00']);
    assert_same('20000.0000', $balances['1-100-10002-00']);
    assert_same('-1500000.0000', $balances['3-100-10001-00'], 'Capital introduced did not credit owner equity.');
    assert_same('-20000.0000', $balances['2-110-10001-00'], 'The owner loan did not credit the owner\'s loan account.');
    // The owners are in the register from the start date; the blank row was skipped.
    $parties = pl_list_ownership_parties($actor, $id);
    assert_same(['Rana Owner', 'Sara Owner'], array_column($parties, 'name'));
    assert_same(2, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_ownership_members WHERE company_id = %i AND effective_from = %s', $id, '2026-07-01'));
    assert_same(null, DB::queryFirstField('SELECT profit_share FROM pl_ownership_members WHERE company_id = %i LIMIT 1', $id), 'A company\'s share percentage is a note, not a partner ratio.');
    // The profile, the feature and the policy.
    $profile = pl_company_profile($actor, $id);
    assert_same('pk.pvt_ltd', $profile['legal_form']);
    assert_same('Wizard (Private) Limited', $profile['legal_name']);
    assert_same('NTN 1234567-8', $profile['tax_registrations']);
    assert_true(pl_module_state($id, 'inventory')['enabled'], 'The chosen feature was not switched on.');
    assert_same('strict', pl_cash_balance_policy($id, $book));
    // The same request again is the same business, with nothing posted twice.
    $again = pl_setup_company($actor, $input, $key);
    assert_same($id, (int) $again['id']);
    assert_same(2, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE company_id = %i AND book_id = %i', $id, $book));
});

test('a partnership records the shares as the partners\' ratio, and a caller that sends nothing extra keeps the old contract', function (): void {
    $actor = wizard_actor('partners');
    $company = pl_setup_company($actor, wizard_input(['legal_form' => 'gb.partnership', 'country_code' => 'GB', 'currency' => 'GBP',
        'owners' => [['name' => 'Ann Partner', 'kind' => 'person', 'role' => 'Partner', 'share' => '50'], ['name' => 'Ben Partner', 'kind' => 'person', 'role' => 'Partner', 'share' => '50']],
        'money_accounts' => [['kind' => 'bank', 'code' => '', 'name' => 'Barclays current', 'custodian' => '', 'opening_amount' => '', 'opening_source' => '']],
        'features' => [], 'account_names' => []]), 'wizard:' . bin2hex(random_bytes(8)));
    assert_same(['0.500000', '0.500000'], DB::queryFirstColumn('SELECT profit_share FROM pl_ownership_members WHERE company_id = %i ORDER BY id', (int) $company['id']));
    assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE company_id = %i', (int) $company['id']), 'No opening amount means no journal.');
    // The 1.2 contract: no extras at all keeps the warning policy and the generic starter leaf.
    $plain = pl_setup_company(wizard_actor('plain'), ['name' => 'Plain business ' . bin2hex(random_bytes(4)), 'currency' => 'USD', 'start_date' => '2026-01-01',
        'fiscal_year_end' => '12-31', 'start_mode' => 'fresh', 'chart_choice' => 'neutral', 'source' => 'blank', 'zero_balances_confirmed' => true,
        'template_digest' => pl_starter_template()['digest']], 'wizard:' . bin2hex(random_bytes(8)));
    assert_same('warning', pl_cash_balance_policy((int) $plain['id'], (int) $plain['book_id']));
    assert_same('Cash and bank', (string) array_column($plain['accounts'], 'name', 'code')['1-100-10001-00']);
});

test('the starter leaf is claimed once, never keeps its generic name, and a country outside the catalogue is remembered', function (): void {
    $actor = wizard_actor('leaf');
    // Row 0 names the starter leaf by its code, as the Owners stage pre-fills it; row 1 has no code.
    $company = pl_setup_company($actor, wizard_input(['country_code' => 'FR', 'legal_form' => 'fr.private_limited', 'money_accounts' => [
        ['kind' => 'bank', 'code' => '1-100-10001-00', 'name' => 'HBL current', 'custodian' => '', 'opening_amount' => '500,000', 'opening_source' => 'capital_introduced'],
        ['kind' => 'till', 'code' => '', 'name' => 'Shop till', 'custodian' => '', 'opening_amount' => '5000', 'opening_source' => 'capital_introduced'],
    ]]), 'wizard:' . bin2hex(random_bytes(8)));
    $accounts = array_column($company['accounts'], null, 'code');
    assert_same('HBL current', (string) $accounts['1-100-10001-00']['name'], 'The coded row did not rename the starter leaf.');
    assert_same('Shop till', (string) ($accounts['1-100-10002-00']['name'] ?? ''), 'The row without a code took the starter leaf instead of a new account.');
    $balances = array_column(pl_trial_balance($actor, (int) $company['id'], (int) $company['book_id'])['accounts'], 'balance', 'code');
    assert_same('500000.0000', $balances['1-100-10001-00']);
    assert_same('5000.0000', $balances['1-100-10002-00']);
    $profile = pl_company_profile($actor, (int) $company['id']);
    assert_same('fr.private_limited', $profile['legal_form']);
    assert_same('Private limited company', $profile['legal_form_label']);
    $generic = [['kind' => 'bank', 'code' => '', 'name' => 'Cash and bank', 'custodian' => '', 'opening_amount' => '', 'opening_source' => '']];
    assert_throws(fn () => pl_setup_company($actor, wizard_input(['money_accounts' => $generic]), 'wizard:' . bin2hex(random_bytes(8))), DomainException::class, 'real account');
    $generic[0]['code'] = '1-100-10001-00';
    $generic[] = ['kind' => 'till', 'code' => '', 'name' => 'Till', 'custodian' => '', 'opening_amount' => '', 'opening_source' => ''];
    assert_throws(fn () => pl_setup_company($actor, wizard_input(['money_accounts' => $generic]), 'wizard:' . bin2hex(random_bytes(8))), DomainException::class, 'real account');
    $twice = [['kind' => 'bank', 'code' => '1-100-10001-00', 'name' => 'A bank', 'custodian' => '', 'opening_amount' => '', 'opening_source' => ''],
        ['kind' => 'bank', 'code' => '1-100-10001-00', 'name' => 'B bank', 'custodian' => '', 'opening_amount' => '', 'opening_source' => '']];
    assert_throws(fn () => pl_setup_company($actor, wizard_input(['money_accounts' => $twice]), 'wizard:' . bin2hex(random_bytes(8))), DomainException::class, 'same account');
});

test('setup refuses opening money without a source, opening money for past records, an unknown feature and a bad share', function (): void {
    $actor = wizard_actor('refusals');
    $key = 'wizard:' . bin2hex(random_bytes(8));
    $noSource = wizard_input();
    $noSource['money_accounts'][0]['opening_source'] = '';
    assert_throws(fn () => pl_setup_company($actor, $noSource, $key), DomainException::class, 'where the opening money');
    assert_throws(fn () => pl_setup_company($actor, wizard_input(['start_mode' => 'existing', 'zero_balances_confirmed' => false]), $key), DomainException::class, 'opening cutover');
    assert_throws(fn () => pl_setup_company($actor, wizard_input(['features' => ['no-such-module']]), $key), DomainException::class, 'known optional feature');
    $badShare = wizard_input();
    $badShare['owners'][0]['share'] = '150';
    assert_throws(fn () => pl_setup_company($actor, $badShare, $key), DomainException::class, 'ownership share');
    assert_throws(fn () => pl_setup_company($actor, wizard_input(['legal_form' => 'xx.made_up']), $key), DomainException::class, 'legal form');
    assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_companies WHERE created_by = %i', $actor), 'A refused setup left a business behind.');
});
