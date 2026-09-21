<?php
declare(strict_types=1);

function correction_fixture(?string $date = null): array
{
    $f = ledger_fixture('USD', gmdate('Y') . '-01-01');
    $input = core_general_input($f);
    $input['date'] = $date ?? gmdate('Y-m-d');
    $draft = pl_save_general_draft($f['actor_id'], $f['company_id'], $f['book_id'], $input);
    $posted = pl_post_general_draft($f['actor_id'], $f['company_id'], $f['book_id'], $draft['id'], $draft['revision']);
    return $f + ['source' => $posted, 'input' => $input];
}

test('same source correction retains original identity and snapshots across repeat corrections', function (): void {
    $f = correction_fixture(); $source = $f['source'];
    $raw = DB::queryFirstRow('SELECT * FROM pl_general_drafts WHERE id=%i', $source['id']);
    $original = pl_get_journal($f['actor_id'], $f['company_id'], $f['book_id'], $source['journal_id']);
    $input = $f['input']; $input['description'] = 'Corrected source description';
    $input['lines'][0]['debit'] = '900'; $input['lines'][1]['credit'] = '900';
    $first = pl_correct_source($f['actor_id'], $f['company_id'], $f['book_id'], 'general_journal', $source['id'], $source['revision'], $input, null, 'correct-first', 'Correct amount');
    $view = pl_get_general_draft($f['actor_id'], $f['company_id'], $f['book_id'], $source['id']);
    assert_same($source['number'], $view['number']); assert_same('posted', $view['status']);
    assert_same($first['journal_id'], $view['journal_id']); assert_same('900.0000', $view['totals']['debit']);
    assert_same($raw, DB::queryFirstRow('SELECT * FROM pl_general_drafts WHERE id=%i', $source['id']));
    assert_same($original, pl_get_journal($f['actor_id'], $f['company_id'], $f['book_id'], $source['journal_id']));
    $list = pl_list_general_drafts($f['actor_id'], $f['company_id'], $f['book_id'], 1, ['search' => 'Corrected source description']);
    assert_same(1, $list['total']); assert_same($first['journal_id'], $list['rows'][0]['journal_id']);
    $next = $input; $next['lines'][0]['debit'] = '800'; $next['lines'][1]['credit'] = '800';
    $second = pl_correct_source($f['actor_id'], $f['company_id'], $f['book_id'], 'general_journal', $source['id'], $first['revision'], $next, null, 'correct-second', 'Second correction');
    assert_same($first, pl_correct_source($f['actor_id'], $f['company_id'], $f['book_id'], 'general_journal', $source['id'], $source['revision'], $input, null, 'correct-first', 'Correct amount'));
    assert_throws(fn () => pl_correct_source($f['actor_id'], $f['company_id'], $f['book_id'], 'general_journal', $source['id'], $source['revision'], $next, null, 'correct-first', 'Correct amount'), DomainException::class, 'different request');
    assert_same(3, count(pl_source_posting_history($f['actor_id'], $f['company_id'], $f['book_id'], 'general_journal', $source['id'])));
    $activity = pl_account_activity($f['actor_id'], $f['company_id'], $f['book_id'], $f['accounts']['1000']);
    foreach ($activity['movements'] as $row) { assert_same($source['id'], $row['general_id']); }
    assert_same('800.0000', pl_trial_balance($f['actor_id'], $f['company_id'], $f['book_id'])['total_debit']);
    $reversed = pl_reverse_general_draft($f['actor_id'], $f['company_id'], $f['book_id'], $source['id'], gmdate('Y-m-d'), 'Cancel latest revision');
    assert_same('reversed', $reversed['status']);
    assert_same($second['journal_id'], $reversed['journal_id']);
});

test('correction rollback keeps reversal replacement and source history atomic', function (): void {
    $f = correction_fixture(); $source = $f['source'];
    $before = (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id=%i', $f['book_id']);
    $input = $f['input']; $input['lines'][0]['account_id'] = 999999999;
    assert_throws(fn () => pl_correct_source($f['actor_id'], $f['company_id'], $f['book_id'], 'general_journal', $source['id'], $source['revision'], $input, null, 'failed-correction', 'Bad account'), DomainException::class);
    assert_same($before, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id=%i', $f['book_id']));
    assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_correction_actions WHERE book_id=%i', $f['book_id']));
    assert_same(1, count(pl_source_posting_history($f['actor_id'], $f['company_id'], $f['book_id'], 'general_journal', $source['id'])));
    assert_same([], pl_correction_context());
});

test('receipt correction keeps list filters totals API and account links on its current revision', function (): void {
    $f = ledger_fixture('USD', gmdate('Y') . '-01-01');
    $input = document_input($f, 'receipt', '100'); $input['date'] = gmdate('Y-m-d');
    $draft = pl_save_document($f['actor_id'], $f['company_id'], $f['book_id'], $input);
    $source = pl_post_document($f['actor_id'], $f['company_id'], $f['book_id'], $draft['id'], $draft['revision']);
    $changed = $input; $changed['amount'] = '250'; $changed['counterparty'] = 'Corrected sample customer';
    $result = pl_correct_source($f['actor_id'], $f['company_id'], $f['book_id'], 'receipt', $source['id'], $source['revision'], $changed, null, 'receipt-correct', 'Correct receipt');
    $view = pl_get_document($f['actor_id'], $f['company_id'], $f['book_id'], $source['id']);
    assert_same('250.0000', $view['amount']); assert_same('posted', $view['status']);
    assert_same($source['number'], $view['number']); assert_same($result['journal_id'], $view['journal']['id']);
    $list = pl_list_documents($f['actor_id'], $f['company_id'], $f['book_id'], ['search' => 'Corrected sample', 'status' => 'posted']);
    assert_same(1, $list['total']); assert_same('250.0000', $list['total_amount']);
    $api = pl_read_source($view, 'transaction', 1, 25);
    assert_same($result['journal_id'], $api['journal_id']); assert_same(2, $api['posting_history']['pagination']['total']);
    $activity = pl_account_activity($f['actor_id'], $f['company_id'], $f['book_id'], $f['accounts']['1000']);
    foreach ($activity['movements'] as $row) { assert_same($source['id'], $row['document_id']); }
    assert_same('100.0000', DB::queryFirstField('SELECT amount FROM pl_documents WHERE id=%i', $source['id']));
});

test('correction rejects stale scope rename and unsupported document kinds', function (): void {
    $f = correction_fixture(); $s = $f['source']; $other = ledger_fixture();
    assert_throws(fn () => pl_correct_source($other['actor_id'], $f['company_id'], $f['book_id'], 'general_journal', $s['id'], $s['revision'], $f['input'], null, 'foreign', 'Wrong owner'), DomainException::class);
    assert_throws(fn () => pl_correct_source($f['actor_id'], $f['company_id'], $f['book_id'], 'general_journal', $s['id'], 999, $f['input'], null, 'stale', 'Stale input'), DomainException::class, 'revision changed');
    $renamed = $f['input']; $renamed['reference'] .= '-1';
    assert_throws(fn () => pl_correct_source($f['actor_id'], $f['company_id'], $f['book_id'], 'general_journal', $s['id'], $s['revision'], $renamed, null, 'rename', 'Rename input'), DomainException::class, 'same document reference');
    assert_throws(fn () => pl_correct_source($f['actor_id'], $f['company_id'], $f['book_id'], 'opening_balance', $s['id'], $s['revision'], $f['input'], null, 'opening', 'Unsupported'), DomainException::class, 'own correction');
});

test('concurrent identical correction requests return one reversal and replacement pair', function (): void {
    $f = correction_fixture();
    $job = ['mode' => 'source_correct', 'fixture' => $f, 'source_id' => $f['source']['id'], 'revision' => $f['source']['revision'], 'correction_input' => $f['input'], 'key' => 'concurrent-correction'];
    $results = ledger_race([$job, $job]);
    assert_same($results[0]['id'], $results[1]['id']);
    assert_same(3, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id=%i', $f['book_id']));
    assert_same(1, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_correction_actions WHERE book_id=%i', $f['book_id']));
});

test('original-date correction is owner permissioned and closed periods still deny it', function (): void {
    $yesterday = (new DateTimeImmutable('yesterday', new DateTimeZone('UTC')))->format('Y-m-d');
    if (substr($yesterday, 0, 4) !== gmdate('Y')) { return; } // Annual fixture starts on January 1.
    $f = correction_fixture($yesterday); $s = $f['source'];
    $other = ledger_fixture();
    DB::insert('pl_company_members', ['company_id' => $f['company_id'], 'user_id' => $other['actor_id'], 'role' => 'accountant']);
    assert_throws(fn () => pl_correct_source($other['actor_id'], $f['company_id'], $f['book_id'], 'general_journal', $s['id'], $s['revision'], $f['input'], $yesterday, 'denied-backdate', 'Backdate exception'), DomainException::class, 'backdated-reversal permission');
    pl_change_period_status($f['actor_id'], $f['company_id'], $f['book_id'], $f['period_id'], 'closed', 1, 'Close for correction test', 'correction-close');
    assert_throws(fn () => pl_correct_source($f['actor_id'], $f['company_id'], $f['book_id'], 'general_journal', $s['id'], $s['revision'], $f['input'], $yesterday, 'closed-backdate', 'Backdate exception'), DomainException::class, 'period');
    pl_change_period_status($f['actor_id'], $f['company_id'], $f['book_id'], $f['period_id'], 'open', 2, 'Reopen for correction test', 'correction-reopen');
    $result = pl_correct_source($f['actor_id'], $f['company_id'], $f['book_id'], 'general_journal', $s['id'], $s['revision'], $f['input'], $yesterday, 'owner-backdate', 'Backdate exception');
    assert_same($yesterday, $result['reversal_date']);
    assert_throws(fn () => DB::update('pl_posting_revisions', ['revision' => 50], 'journal_id=%i', $result['journal_id']), Throwable::class);
    assert_throws(fn () => DB::delete('pl_correction_actions', 'book_id=%i', $f['book_id']), Throwable::class);
});

test('ordinary posting cannot bypass source correction identity with another request key', function (): void {
    $f = correction_fixture(); $s = $f['source'];
    $payload = ['date' => gmdate('Y-m-d'), 'currency' => 'USD', 'source_type' => 'general_journal', 'source_reference' => 'general:' . $s['id'],
        'description' => $f['input']['description'], 'idempotency_key' => 'bypass-correction', 'lines' => $f['input']['lines']];
    assert_throws(fn () => pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], $payload), DomainException::class, 'same-identity correction');
    assert_same(1, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id=%i', $f['book_id']));
});

test('competing corrections accept one expected revision across separate connections', function (): void {
    $f = correction_fixture();
    $job = ['mode' => 'source_correct', 'fixture' => $f, 'source_id' => $f['source']['id'], 'revision' => $f['source']['revision'], 'correction_input' => $f['input'], 'key' => 'competing-a', 'allow_domain_failure' => true];
    $other = $job; $other['key'] = 'competing-b'; $other['correction_input']['description'] = 'Competing source correction';
    $results = ledger_race([$job, $other]);
    assert_same(1, count(array_filter($results, static fn (array $result): bool => $result['id'] > 0)));
    assert_same(3, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id=%i', $f['book_id']));
    assert_same(2, count(pl_source_posting_history($f['actor_id'], $f['company_id'], $f['book_id'], 'general_journal', $f['source']['id'])));
});

test('foreign general source corrections reverse frozen FX metadata and preserve both histories', function (): void {
    $f = ledger_fixture('USD', gmdate('Y') . '-01-01');
    $input = core_general_input($f); $input['date'] = gmdate('Y-m-d');
    $input['lines'][0]['debit'] = '120'; $input['lines'][1]['credit'] = '120';
    foreach ($input['lines'] as &$line) { $line += ['currency' => 'EUR', 'amount_fc' => '100', 'rate' => '1.2']; }
    unset($line);
    $draft = pl_save_general_draft($f['actor_id'], $f['company_id'], $f['book_id'], $input);
    $source = pl_post_general_draft($f['actor_id'], $f['company_id'], $f['book_id'], $draft['id'], $draft['revision']);
    $original = pl_get_journal($f['actor_id'], $f['company_id'], $f['book_id'], $source['journal_id']);
    $changed = $input; $changed['lines'][0]['debit'] = '130'; $changed['lines'][1]['credit'] = '130';
    foreach ($changed['lines'] as &$line) { $line['rate'] = '1.3'; }
    unset($line);
    $result = pl_correct_source($f['actor_id'], $f['company_id'], $f['book_id'], 'general_journal', $source['id'], $source['revision'], $changed, null, 'fx-correction', 'Correct transaction rate');
    $reversal = pl_get_journal($f['actor_id'], $f['company_id'], $f['book_id'], $result['reversal_journal_id']);
    foreach (['currency','amount_fc','rate','rate_type','rate_source_id','amount_base','rate_is_stale','ic_counterparty_entity_id'] as $field) { assert_same($original['lines'][0][$field], $reversal['lines'][0][$field]); }
    assert_same($original['lines'][0]['debit'], $reversal['lines'][0]['credit']);
    $current = pl_get_general_draft($f['actor_id'], $f['company_id'], $f['book_id'], $source['id']);
    assert_same('1.300000000000', $current['lines'][0]['rate']);
    assert_same('130.0000', $current['totals']['debit']);
    assert_same(2, count($current['posting_history']));
});
