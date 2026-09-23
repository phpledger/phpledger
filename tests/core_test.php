<?php
declare(strict_types=1);

function core_account_input(): array
{
    return ['code' => '6100', 'name' => 'Workshop repairs', 'type' => 'expense', 'role' => 'expense', 'is_active' => true, 'reason' => 'Separate repair costs for management review.', 'creation_key' => bin2hex(random_bytes(16))];
}

function core_general_input(array $fixture): array
{
    return ['date' => '2026-09-14', 'reference' => 'CAPITAL-01', 'description' => 'Owner capital introduced', 'creation_key' => bin2hex(random_bytes(16)), 'lines' => [
        ['account_id' => $fixture['accounts']['1000'], 'debit' => '1000.0001', 'credit' => '0', 'description' => 'Bank contribution'],
        ['account_id' => $fixture['accounts']['3000'], 'debit' => '0', 'credit' => '1000.0001', 'description' => 'Owner capital'],
    ]];
}

test('chart creation is idempotent scoped and concurrent while preserving starter provenance', function (): void {
    $f = ledger_fixture();
    $snapshot = DB::queryFirstField('SELECT snapshot FROM pl_template_installations WHERE company_id = %i', $f['company_id']);
    $input = core_account_input();
    $job = ['mode' => 'account_create', 'fixture' => $f, 'account_input' => $input];
    $race = ledger_race([$job, $job]);
    assert_same($race[0]['id'], $race[1]['id']);
    $account = pl_save_account($f['actor_id'], $f['company_id'], $f['book_id'], $input);
    assert_same($race[0]['id'], $account['id']);
    assert_same(1, count(pl_core_history($f['actor_id'], $f['company_id'], $f['book_id'], 'account', $account['id'])));
    assert_same($snapshot, DB::queryFirstField('SELECT snapshot FROM pl_template_installations WHERE company_id = %i', $f['company_id']));
    assert_throws(fn () => pl_save_account($f['actor_id'], $f['company_id'], $f['book_id'], array_replace($input, ['name' => 'Changed retry'])), DomainException::class);
    assert_throws(fn () => pl_save_account($f['actor_id'], $f['company_id'], $f['book_id'], core_account_input()), DomainException::class, 'code is already');
});

test('account edits audit names and status and reject stale revisions or reclassification', function (): void {
    $f = ledger_fixture();
    $input = core_account_input();
    $account = pl_save_account($f['actor_id'], $f['company_id'], $f['book_id'], $input);
    $input['name'] = 'Repairs and maintenance';
    $input['is_active'] = false;
    $updated = pl_save_account($f['actor_id'], $f['company_id'], $f['book_id'], $input, $account['id'], 1);
    assert_same(2, $updated['revision']);
    assert_same(false, $updated['is_active']);
    assert_throws(fn () => pl_save_account($f['actor_id'], $f['company_id'], $f['book_id'], $input, $account['id'], 1), DomainException::class, 'Someone changed');
    assert_throws(fn () => pl_save_account($f['actor_id'], $f['company_id'], $f['book_id'], array_replace($input, ['type' => 'income', 'role' => 'income']), $account['id'], 2), DomainException::class, 'fixed');
    assert_throws(fn () => pl_save_account($f['actor_id'], $f['company_id'], $f['book_id'], array_replace($input, ['role' => 'cash_bank']), $account['id'], 2), DomainException::class, 'match');
    $input['is_active'] = true;
    assert_same(true, pl_save_account($f['actor_id'], $f['company_id'], $f['book_id'], $input, $account['id'], 2)['is_active']);
    $audit = DB::queryFirstRow('SELECT * FROM pl_core_audit WHERE company_id = %i AND action = %s ORDER BY id LIMIT 1', $f['company_id'], 'updated');
    assert_same('Workshop repairs', json_decode($audit['before_state'], true)['name']);
    assert_same('Repairs and maintenance', json_decode($audit['after_state'], true)['name']);
    assert_throws(fn () => DB::update('pl_core_audit', ['reason' => 'Overwrite'], 'id = %i', $audit['id']));
    assert_throws(fn () => DB::delete('pl_core_audit', 'id = %i', $audit['id']));
});

test('core account and general journal services enforce viewers scope and readiness', function (): void {
    $f = ledger_fixture(); $other = ledger_fixture();
    sample_membership_insert(['company_id' => $f['company_id'], 'user_id' => $other['actor_id'], 'role' => 'viewer']);
    assert_same($f['accounts']['1000'], pl_get_account($other['actor_id'], $f['company_id'], $f['book_id'], $f['accounts']['1000'])['id']);
    assert_throws(fn () => pl_save_account($other['actor_id'], $f['company_id'], $f['book_id'], core_account_input()), DomainException::class);
    assert_throws(fn () => pl_get_account($f['actor_id'], $f['company_id'], $f['book_id'], $other['accounts']['1000']), DomainException::class);
    assert_throws(fn () => pl_save_general_draft($other['actor_id'], $f['company_id'], $f['book_id'], core_general_input($f)), DomainException::class);
    $input = core_general_input($f); $input['lines'][0]['account_id'] = $other['accounts']['1000'];
    assert_throws(fn () => pl_save_general_draft($f['actor_id'], $f['company_id'], $f['book_id'], $input), DomainException::class);
    DB::update('pl_companies', ['setup_status' => 'opening_required'], 'id = %i', $f['company_id']);
    assert_throws(fn () => pl_save_general_draft($f['actor_id'], $f['company_id'], $f['book_id'], core_general_input($f)), DomainException::class, 'Opening balances');
    assert_same(0, count(pl_list_general_drafts($f['actor_id'], $f['company_id'], $f['book_id'])['rows']));
});

test('general drafts preserve unbalanced work and exclude it from reports until explicitly posted', function (): void {
    $f = ledger_fixture(); $input = core_general_input($f);
    $input['lines'][1]['credit'] = '900';
    $draft = pl_save_general_draft($f['actor_id'], $f['company_id'], $f['book_id'], $input);
    assert_same(false, $draft['totals']['balanced']);
    assert_same('100.0001', $draft['totals']['difference']);
    assert_same('0.0000', pl_trial_balance($f['actor_id'], $f['company_id'], $f['book_id'])['total_debit']);
    assert_throws(fn () => pl_post_general_draft($f['actor_id'], $f['company_id'], $f['book_id'], $draft['id'], 1), DomainException::class, 'balance');
    assert_same(null, pl_get_general_draft($f['actor_id'], $f['company_id'], $f['book_id'], $draft['id'])['journal_id']);
    $input['lines'][1]['credit'] = '1000.0001';
    $edited = pl_save_general_draft($f['actor_id'], $f['company_id'], $f['book_id'], $input, $draft['id'], 1);
    assert_same(2, $edited['revision']);
    assert_throws(fn () => pl_save_general_draft($f['actor_id'], $f['company_id'], $f['book_id'], $input, $draft['id'], 1), DomainException::class, 'Someone changed');
    assert_throws(fn () => pl_post_general_draft($f['actor_id'], $f['company_id'], $f['book_id'], $draft['id'], 1), DomainException::class, 'changed');
    $job = ['mode' => 'general_post', 'fixture' => $f, 'draft_id' => $draft['id'], 'revision' => 2];
    $race = ledger_race([$job, $job]);
    assert_same($race[0]['id'], $race[1]['id']);
    assert_same(1, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id = %i', $f['book_id']));
    $posted = pl_get_general_draft($f['actor_id'], $f['company_id'], $f['book_id'], $draft['id']);
    assert_same('posted', $posted['status']);
    assert_same('1000.0001', pl_trial_balance($f['actor_id'], $f['company_id'], $f['book_id'])['total_debit']);
    assert_same(3, count(pl_core_history($f['actor_id'], $f['company_id'], $f['book_id'], 'general_journal', $draft['id'])));
    assert_throws(fn () => pl_save_general_draft($f['actor_id'], $f['company_id'], $f['book_id'], $input, $draft['id'], 2), DomainException::class, 'cannot be edited');
    assert_throws(fn () => DB::update('pl_general_drafts', ['description' => 'Changed'], 'id = %i', $draft['id']));
    assert_throws(fn () => DB::delete('pl_general_drafts', 'id = %i', $draft['id']));
});

test('general journal reversals retain dated source and restore account balances once', function (): void {
    $f = ledger_fixture(); $input = core_general_input($f); $today = gmdate('Y-m-d');
    $draft = pl_save_general_draft($f['actor_id'], $f['company_id'], $f['book_id'], $input);
    assert_same($draft['id'], pl_save_general_draft($f['actor_id'], $f['company_id'], $f['book_id'], $input)['id']);
    assert_throws(fn () => pl_save_general_draft($f['actor_id'], $f['company_id'], $f['book_id'], array_replace($input, ['description' => 'Different'])), DomainException::class);
    pl_post_general_draft($f['actor_id'], $f['company_id'], $f['book_id'], $draft['id'], 1);
    $reversed = pl_reverse_general_draft($f['actor_id'], $f['company_id'], $f['book_id'], $draft['id'], $today, 'Capital entry correction');
    assert_same('reversed', $reversed['status']);
    assert_same($reversed['reversal_journal_id'], pl_reverse_general_draft($f['actor_id'], $f['company_id'], $f['book_id'], $draft['id'], $today, 'Capital entry correction')['reversal_journal_id']);
    $statement = pl_account_activity($f['actor_id'], $f['company_id'], $f['book_id'], $f['accounts']['1000'], $today, 1, $today);
    assert_same('1000.0001', $statement['opening_balance']);
    assert_same('0.0000', $statement['closing_balance']);
    assert_same($draft['id'], $statement['movements'][0]['general_id']);
    assert_same(3, count(pl_core_history($f['actor_id'], $f['company_id'], $f['book_id'], 'general_journal', $draft['id'])));
});

test('closed periods and inactive accounts reject general posting and preserve the draft', function (): void {
    $f = ledger_fixture(); $draft = pl_save_general_draft($f['actor_id'], $f['company_id'], $f['book_id'], core_general_input($f));
    DB::update('pl_periods', ['status' => 'closed'], 'id = %i', $f['period_id']);
    assert_throws(fn () => pl_post_general_draft($f['actor_id'], $f['company_id'], $f['book_id'], $draft['id'], 1), DomainException::class);
    assert_same('draft', pl_get_general_draft($f['actor_id'], $f['company_id'], $f['book_id'], $draft['id'])['status']);
    DB::update('pl_periods', ['status' => 'open'], 'id = %i', $f['period_id']);
    DB::update('pl_accounts', ['is_active' => 0], 'id = %i', $f['accounts']['1000']);
    assert_throws(fn () => pl_post_general_draft($f['actor_id'], $f['company_id'], $f['book_id'], $draft['id'], 1), DomainException::class);
    assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id = %i', $f['book_id']));
});

test('audit failure rolls back journal source linkage and all financial effects', function (): void {
    $f = ledger_fixture(); $draft = pl_save_general_draft($f['actor_id'], $f['company_id'], $f['book_id'], core_general_input($f));
    DB::query("CREATE TRIGGER pl_core_test_fail BEFORE INSERT ON pl_core_audit FOR EACH ROW BEGIN IF NEW.book_id = %i AND NEW.action = 'posted' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sample audit failure'; END IF; END", $f['book_id']);
    try {
        assert_throws(fn () => pl_post_general_draft($f['actor_id'], $f['company_id'], $f['book_id'], $draft['id'], 1));
        assert_same(null, pl_get_general_draft($f['actor_id'], $f['company_id'], $f['book_id'], $draft['id'])['journal_id']);
        assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id = %i', $f['book_id']));
        assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journal_lines WHERE book_id = %i', $f['book_id']));
    } finally { DB::query('DROP TRIGGER pl_core_test_fail'); }
    assert_same('posted', pl_post_general_draft($f['actor_id'], $f['company_id'], $f['book_id'], $draft['id'], 1)['status']);
});

test('an older caller snapshot cannot overwrite a changed account or post stale general amounts', function (): void {
    $f = ledger_fixture(); $accountInput = core_account_input(); $generalInput = core_general_input($f);
    $account = pl_save_account($f['actor_id'], $f['company_id'], $f['book_id'], $accountInput);
    $draft = pl_save_general_draft($f['actor_id'], $f['company_id'], $f['book_id'], $generalInput);
    $newAccount = array_replace($accountInput, ['name' => 'Newer account name']);
    $newGeneral = $generalInput;
    $newGeneral['lines'][0]['debit'] = '2000.0002'; $newGeneral['lines'][1]['credit'] = '2000.0002';
    DB::startTransaction();
    try {
        DB::queryFirstField('SELECT COUNT(*) FROM pl_general_drafts');
        ledger_race([['mode' => 'account_update', 'fixture' => $f, 'account_input' => $newAccount, 'account_id' => $account['id'], 'revision' => 1]]);
        ledger_race([['mode' => 'general_save', 'fixture' => $f, 'general_input' => $newGeneral, 'draft_id' => $draft['id'], 'revision' => 1]]);
        assert_same(1, (int) DB::queryFirstField('SELECT revision FROM pl_general_drafts WHERE id = %i', $draft['id']), 'The test must retain an old consistent snapshot.');
        assert_throws(fn () => pl_save_account($f['actor_id'], $f['company_id'], $f['book_id'], $accountInput, $account['id'], 1), DomainException::class, 'Someone changed');
        assert_throws(fn () => pl_save_general_draft($f['actor_id'], $f['company_id'], $f['book_id'], $generalInput, $draft['id'], 1), DomainException::class, 'Someone changed');
        assert_throws(fn () => pl_post_general_draft($f['actor_id'], $f['company_id'], $f['book_id'], $draft['id'], 1), DomainException::class, 'changed');
        assert_same(2, pl_get_general_draft($f['actor_id'], $f['company_id'], $f['book_id'], $draft['id'])['revision']);
    } finally { DB::rollback(); }
    assert_same('Newer account name', pl_get_account($f['actor_id'], $f['company_id'], $f['book_id'], $account['id'])['name']);
    assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id = %i', $f['book_id']));
    $posted = pl_post_general_draft($f['actor_id'], $f['company_id'], $f['book_id'], $draft['id'], 2);
    assert_same('2000.0002', $posted['totals']['debit']);
    assert_same('2000.0002', pl_trial_balance($f['actor_id'], $f['company_id'], $f['book_id'])['total_debit']);
});
