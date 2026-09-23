<?php
declare(strict_types=1);

function document_input(array $f, string $kind = 'expense', string $amount = '125.0000'): array
{
    return ['kind' => $kind, 'date' => '2026-09-14', 'amount' => $amount,
        'money_account_id' => $f['accounts']['1000'], 'category_account_id' => $f['accounts'][$kind === 'receipt' ? '4000' : '5000'],
        'counterparty' => 'Sample supplier', 'reference' => 'TEST-REFERENCE', 'memo' => 'Sample document proof', 'creation_key' => bin2hex(random_bytes(16))];
}

function setup_input(string $mode = 'fresh'): array
{
    return ['name' => 'Sample setup ' . bin2hex(random_bytes(4)), 'currency' => 'USD', 'start_date' => '2026-09-14', 'fiscal_year_end' => '12-31', 'start_mode' => $mode, 'template_digest' => pl_starter_template()['digest'], 'zero_balances_confirmed' => true];
}

test('setup pins one chart and rejects stale previews and changed requests', function (): void {
    $f = ledger_fixture();
    $input = setup_input();
    $key = bin2hex(random_bytes(16));
    $company = pl_setup_company($f['actor_id'], $input, $key);
    assert_same('ready', $company['setup_status']);
    assert_same(false, $company['is_sample']);
    assert_same('core-starter', $company['template']['id']);
    assert_same(pl_starter_template()['digest'], $company['template']['digest']);
    // Thirteen starter purposes, one over/short, seven payroll accounts and nineteen headings.
    assert_same(21 + 19, count($company['accounts']));
    assert_same(21, count(array_filter($company['accounts'], static fn (array $row): bool => $row['is_postable'])));
    assert_same($company['id'], pl_setup_company($f['actor_id'], $input, $key)['id']);
    $changed = $input;
    $changed['name'] .= ' changed';
    assert_throws(fn () => pl_setup_company($f['actor_id'], $changed, $key), DomainException::class, 'different business');
    $changed = $input;
    $changed['template_digest'] = str_repeat('0', 64);
    assert_throws(fn () => pl_setup_company($f['actor_id'], $changed, 'stale-' . $key), DomainException::class, 'chart changed');
    $changed = $input;
    $changed['zero_balances_confirmed'] = false;
    assert_throws(fn () => pl_setup_company($f['actor_id'], $changed, 'unconfirmed-' . $key), DomainException::class, 'no opening balances');
});

test('opening readiness blocks documents and direct ledger posts without bypass through review', function (): void {
    $f = ledger_fixture();
    $company = pl_setup_company($f['actor_id'], setup_input('existing'), bin2hex(random_bytes(16)));
    assert_same('opening_required', $company['setup_status']);
    $f = ['actor_id' => $f['actor_id'], 'company_id' => $company['id'], 'book_id' => $company['book_id'], 'accounts' => pl_account_code_mapping($company['accounts'])];
    assert_throws(fn () => pl_save_document($f['actor_id'], $f['company_id'], $f['book_id'], document_input($f)), DomainException::class, 'Opening balances');
    assert_throws(fn () => pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], ledger_payload($f)), DomainException::class, 'Opening balances');
    assert_throws(fn () => pl_confirm_existing_setup($f['actor_id'], $f['company_id'], $f['book_id'], [], true), DomainException::class, 'cannot be skipped');
    assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id = %i', $f['book_id']));
});

test('prior company review preserves renamed accounts and posted history', function (): void {
    $f = ledger_fixture();
    $journal = pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], ledger_payload($f));
    // Reproduce the pre-002 records after their additive columns exist.
    DB::delete('pl_template_installations', 'company_id = %i', $f['company_id']);
    DB::update('pl_accounts', ['semantic_key' => null, 'role' => null], 'book_id = %i', $f['book_id']);
    DB::update('pl_accounts', ['name' => 'My renamed operating bank'], 'id = %i', $f['accounts']['1000']);
    DB::update('pl_companies', ['setup_status' => 'review_required'], 'id = %i', $f['company_id']);
    assert_throws(fn () => pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], ledger_payload($f)), DomainException::class, 'Review');
    $mapping = [];
    foreach (pl_starter_template()['accounts'] as $account) {
        $mapping[$account['semantic_key']] = $f['accounts'][$account['code']];
    }
    assert_throws(fn () => pl_confirm_existing_setup($f['actor_id'], $f['company_id'], $f['book_id'], $mapping, false), DomainException::class);
    $reviewed = pl_confirm_existing_setup($f['actor_id'], $f['company_id'], $f['book_id'], $mapping, true);
    assert_same('ready', $reviewed['setup_status']);
    // By code, not by position: the chart's class and group headings sort before the first
    // posting account, and this assertion is about the mapped bank account keeping its own name.
    $bank = array_values(array_filter($reviewed['accounts'], static fn (array $row): bool => (int) $row['id'] === $f['accounts']['1000']));
    assert_same('My renamed operating bank', $bank[0]['name']);
    assert_same($journal['id'], pl_get_journal($f['actor_id'], $f['company_id'], $f['book_id'], $journal['id'])['id']);
    assert_same('12.3400', pl_trial_balance($f['actor_id'], $f['company_id'], $f['book_id'])['total_debit']);
});

test('draft creation is idempotent and edits and posting reject stale revisions', function (): void {
    $f = ledger_fixture();
    $input = document_input($f);
    $draft = pl_save_document($f['actor_id'], $f['company_id'], $f['book_id'], $input);
    assert_same('draft', $draft['status']);
    assert_same(null, $draft['journal']);
    assert_same('125.0000', $draft['journal_preview']['lines'][0]['debit']);
    assert_same($draft['id'], pl_save_document($f['actor_id'], $f['company_id'], $f['book_id'], $input)['id']);
    $input['amount'] = '150.00';
    assert_throws(fn () => pl_save_document($f['actor_id'], $f['company_id'], $f['book_id'], $input), DomainException::class, 'different draft');
    $edited = pl_save_document($f['actor_id'], $f['company_id'], $f['book_id'], $input, $draft['id'], 1);
    assert_same(2, $edited['revision']);
    assert_same('150.0000', $edited['amount']);
    assert_throws(fn () => pl_save_document($f['actor_id'], $f['company_id'], $f['book_id'], $input, $draft['id'], 1), DomainException::class, 'Someone changed');
    assert_throws(fn () => pl_post_document($f['actor_id'], $f['company_id'], $f['book_id'], $draft['id'], 1), DomainException::class, 'changed after');
    assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id = %i', $f['book_id']));
});

test('receipt expense and linked reversal reconcile immutable sources and report drilldown', function (): void {
    $f = ledger_fixture(); $today = gmdate('Y-m-d');
    $receipt = pl_save_document($f['actor_id'], $f['company_id'], $f['book_id'], document_input($f, 'receipt', '1000'));
    pl_post_document($f['actor_id'], $f['company_id'], $f['book_id'], $receipt['id'], 1);
    $expense = pl_save_document($f['actor_id'], $f['company_id'], $f['book_id'], document_input($f));
    $expense = pl_post_document($f['actor_id'], $f['company_id'], $f['book_id'], $expense['id'], 1);
    assert_same('posted', $expense['status']);
    assert_same($expense['journal_id'], pl_post_document($f['actor_id'], $f['company_id'], $f['book_id'], $expense['id'], 1)['journal_id']);
    assert_same('document:' . $expense['id'], $expense['journal']['source_reference']);
    $bank = pl_account_activity($f['actor_id'], $f['company_id'], $f['book_id'], $f['accounts']['1000']);
    assert_same('875.0000', $bank['balance']);
    assert_same(2, $bank['total']);
    assert_same($expense['id'], $bank['movements'][1]['document_id']);
    assert_same('0.0000', pl_account_activity($f['actor_id'], $f['company_id'], $f['book_id'], $f['accounts']['1000'], '2026-09-13')['balance']);
    assert_throws(fn () => DB::update('pl_documents', ['amount' => '200'], 'id = %i', $expense['id']));
    assert_throws(fn () => DB::delete('pl_documents', 'id = %i', $expense['id']));
    assert_throws(fn () => pl_save_document($f['actor_id'], $f['company_id'], $f['book_id'], document_input($f), $expense['id'], 1), DomainException::class, 'cannot be edited');
    $reversed = pl_reverse_document($f['actor_id'], $f['company_id'], $f['book_id'], $expense['id'], $today, 'Duplicate purchase');
    assert_same('reversed', $reversed['status']);
    assert_same($reversed['reversal_journal_id'], pl_reverse_document($f['actor_id'], $f['company_id'], $f['book_id'], $expense['id'], $today, 'Duplicate purchase')['reversal_journal_id']);
    assert_throws(fn () => pl_reverse_document($f['actor_id'], $f['company_id'], $f['book_id'], $expense['id'], $today, 'Changed reason'), DomainException::class, 'different journal');
    assert_same('1000.0000', pl_account_activity($f['actor_id'], $f['company_id'], $f['book_id'], $f['accounts']['1000'])['balance']);
    assert_same('875.0000', pl_account_activity($f['actor_id'], $f['company_id'], $f['book_id'], $f['accounts']['1000'], '2026-09-14')['balance']);
    assert_same('0.0000', pl_account_activity($f['actor_id'], $f['company_id'], $f['book_id'], $f['accounts']['5000'])['balance']);
});

test('document permissions and scoped account roles fail closed', function (): void {
    $f = ledger_fixture();
    $other = ledger_fixture();
    $input = document_input($f);
    $document = pl_save_document($f['actor_id'], $f['company_id'], $f['book_id'], $input);
    assert_throws(fn () => pl_get_document($other['actor_id'], $f['company_id'], $f['book_id'], $document['id']), DomainException::class);
    assert_throws(fn () => pl_list_documents($other['actor_id'], $f['company_id'], $f['book_id']), DomainException::class);
    assert_throws(fn () => pl_post_document($f['actor_id'], $f['company_id'], $other['book_id'], $document['id'], 1), DomainException::class);
    foreach ([$other['accounts']['1000'], $f['accounts']['1100']] as $badId) {
        $bad = $input;
        $bad['money_account_id'] = $badId;
        assert_throws(fn () => pl_save_document($f['actor_id'], $f['company_id'], $f['book_id'], $bad), DomainException::class, 'cash/bank');
    }
    $bad = $input;
    $bad['category_account_id'] = $f['accounts']['4000'];
    assert_throws(fn () => pl_save_document($f['actor_id'], $f['company_id'], $f['book_id'], $bad), DomainException::class);
    sample_membership_insert(['company_id' => $f['company_id'], 'user_id' => $other['actor_id'], 'role' => 'viewer']);
    assert_same($document['id'], pl_get_document($other['actor_id'], $f['company_id'], $f['book_id'], $document['id'])['id']);
    assert_throws(fn () => pl_save_document($other['actor_id'], $f['company_id'], $f['book_id'], $input), DomainException::class);
    assert_throws(fn () => pl_post_document($other['actor_id'], $f['company_id'], $f['book_id'], $document['id'], 1), DomainException::class);
    assert_throws(fn () => pl_account_activity($f['actor_id'], $f['company_id'], $f['book_id'], $other['accounts']['1000']), DomainException::class);
});

test('closed periods and source linkage failures preserve drafts with no partial posting', function (): void {
    $f = ledger_fixture();
    $draft = pl_save_document($f['actor_id'], $f['company_id'], $f['book_id'], document_input($f));
    DB::update('pl_periods', ['status' => 'closed'], 'id = %i', $f['period_id']);
    assert_throws(fn () => pl_post_document($f['actor_id'], $f['company_id'], $f['book_id'], $draft['id'], 1), DomainException::class, 'open accounting period');
    assert_same('draft', pl_get_document($f['actor_id'], $f['company_id'], $f['book_id'], $draft['id'])['status']);
    DB::update('pl_periods', ['status' => 'open'], 'id = %i', $f['period_id']);
    DB::query("CREATE TRIGGER pl_test_document_failure BEFORE UPDATE ON pl_documents FOR EACH ROW BEGIN IF NEW.id = " . $draft['id'] . " AND NEW.journal_id IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sample source linkage failure'; END IF; END");
    try {
        assert_throws(fn () => pl_post_document($f['actor_id'], $f['company_id'], $f['book_id'], $draft['id'], 1), MeekroDBException::class, 'Sample source linkage failure');
        assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id = %i', $f['book_id']));
        assert_same('draft', pl_get_document($f['actor_id'], $f['company_id'], $f['book_id'], $draft['id'])['status']);
    } finally {
        DB::query('DROP TRIGGER pl_test_document_failure');
    }
});

test('core sample isolates two supported postings and four drafts and never replaces existing data', function (): void {
    $f = ledger_fixture();
    $input = setup_input('sample');
    $key = bin2hex(random_bytes(16));
    $sample = pl_setup_company($f['actor_id'], $input, $key);
    assert_same(true, $sample['is_sample']);
    assert_same($sample['id'], pl_setup_company($f['actor_id'], $input, $key)['id']);
    $drafts = pl_list_documents($f['actor_id'], $sample['id'], $sample['book_id'], ['status' => 'draft']);
    assert_same(4, $drafts['total']);
    assert_same('465.0000', $drafts['total_amount']);
    assert_same('125.0000', $drafts['documents'][0]['amount']);
    assert_same(2, pl_list_documents($f['actor_id'], $sample['id'], $sample['book_id'], ['status' => 'posted'])['total']);
    $accounts = pl_account_code_mapping($sample['accounts']);
    assert_same('875.0000', pl_account_activity($f['actor_id'], $sample['id'], $sample['book_id'], $accounts['1000'])['balance']);
    assert_throws(fn () => pl_seed_core_sample($f['actor_id'], $f['company_id'], $f['book_id']), DomainException::class, 'isolated sample');
    assert_throws(fn () => pl_seed_core_sample($f['actor_id'], $sample['id'], $sample['book_id']), DomainException::class, 'new empty company');
    assert_same(0, pl_list_documents($f['actor_id'], $f['company_id'], $f['book_id'])['total']);
    assert_same(1, pl_list_documents($f['actor_id'], $sample['id'], $sample['book_id'], ['status' => 'draft', 'search' => 'Beacon'])['total']);
});

test('a sample start is refused in production through preview and confirm but allowed in local and test', function (): void {
    require_once dirname(__DIR__) . '/www/phpledger/includes/functions/web_functions.php';
    $f = ledger_fixture();
    $digest = (string) pl_starter_template()['digest'];
    $sample = ['start_mode' => 'sample', 'name' => 'Crafted sample request', 'currency' => 'USD', 'start_date' => '2026-09-14',
        'entity_type' => 'other', 'fiscal_year_end_choice' => '12-31'];
    $fresh = array_replace($sample, ['start_mode' => 'fresh', 'name' => 'Sample production business', 'zero_balances_confirmed' => '1']);
    $created = static fn (): int => (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_companies WHERE created_by = %i', $f['actor_id']);
    $previous = getenv('PL_ENV');
    try {
        foreach (['production', ''] as $environment) {
            putenv($environment === '' ? 'PL_ENV' : 'PL_ENV=' . $environment);
            assert_true(!pl_sample_companies_allowed(), 'Sample companies were allowed in ' . ($environment ?: 'an unset environment') . '.');
            assert_throws(fn () => pl_onboarding_preview_input($sample, $digest), DomainException::class, 'isolated demo or local');
            // A crafted draft that skips the preview is refused by the setup service, and nothing is created.
            $before = $created();
            assert_throws(fn () => pl_setup_company($f['actor_id'], setup_input('sample'), bin2hex(random_bytes(16))), DomainException::class, 'isolated demo or local');
            assert_same($before, $created());
            assert_same('fresh', pl_onboarding_preview_input($fresh, $digest)['start_mode'], 'A normal start must still preview in production.');
        }
        putenv('PL_ENV=demo');
        assert_true(!pl_sample_companies_allowed(), 'A demo request outside visitor provisioning was allowed to create samples.');
        assert_true(pl_demo_provisioning(static fn (): bool => pl_sample_companies_allowed()), 'The demo could not provision its own visitor sample.');
        foreach (['local', 'test'] as $environment) {
            putenv('PL_ENV=' . $environment);
            assert_true(pl_sample_companies_allowed());
            $input = pl_onboarding_preview_input($sample, $digest);
            assert_same(['sample', 'Core accounting sample', true], [$input['start_mode'], $input['name'], $input['zero_balances_confirmed']]);
        }
        $company = pl_setup_company($f['actor_id'], $input, bin2hex(random_bytes(16)));
        assert_same(true, $company['is_sample']);
    } finally {
        putenv($previous === false ? 'PL_ENV' : 'PL_ENV=' . $previous);
    }
});

test('simultaneous document posts create one scoped source-linked journal', function (): void {
    $f = ledger_fixture();
    $draft = pl_save_document($f['actor_id'], $f['company_id'], $f['book_id'], document_input($f));
    $job = ['mode' => 'document_post', 'fixture' => $f, 'document_id' => $draft['id'], 'revision' => 1];
    $results = ledger_race([$job, $job]);
    assert_same($results[0]['id'], $results[1]['id']);
    assert_same(1, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id = %i', $f['book_id']));
    assert_same($results[0]['id'], pl_get_document($f['actor_id'], $f['company_id'], $f['book_id'], $draft['id'])['journal_id']);
});

test('simultaneous setup and first draft submissions return one durable result each', function (): void {
    $f = ledger_fixture();
    $setup = ['mode' => 'setup', 'fixture' => $f, 'setup_input' => setup_input(), 'key' => bin2hex(random_bytes(16))];
    $companies = ledger_race([$setup, $setup]);
    assert_same($companies[0]['id'], $companies[1]['id']);
    assert_same(1, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_companies WHERE created_by = %i AND setup_request_key = %s', $f['actor_id'], $setup['key']));
    $draft = ['mode' => 'document_create', 'fixture' => $f, 'document_input' => document_input($f)];
    $documents = ledger_race([$draft, $draft]);
    assert_same($documents[0]['id'], $documents[1]['id']);
    assert_same(1, pl_list_documents($f['actor_id'], $f['company_id'], $f['book_id'])['total']);
});
