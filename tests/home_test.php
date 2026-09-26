<?php
declare(strict_types=1);

test('Home reuses posted cash and draft totals without posting or crossing company scope', function (): void {
    $f = ledger_fixture();
    $draft = pl_save_document($f['actor_id'], $f['company_id'], $f['book_id'], document_input($f, 'receipt', '23.4567'));
    $before = (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE company_id=%i', $f['company_id']);
    $home = pl_home_overview($f['actor_id'], $f['company_id'], $f['book_id'], '2026-09-17');
    assert_same('0.0000', $home['cash']);
    assert_same(1, $home['drafts']['total']);
    assert_same('23.4567', $home['drafts']['total_amount']);
    assert_same($draft['id'], $home['recent'][0]['id']);
    assert_same($before, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE company_id=%i', $f['company_id']));
    pl_post_document($f['actor_id'], $f['company_id'], $f['book_id'], $draft['id'], $draft['revision']);
    $home = pl_home_overview($f['actor_id'], $f['company_id'], $f['book_id'], '2026-09-17');
    assert_same('23.4567', $home['cash']);
    assert_same(0, $home['drafts']['total']);
    assert_same(pl_ar_ap_open_items($f['actor_id'], $f['company_id'], $f['book_id'], 'receivable', '2026-09-17'), $home['receivables']);
    $other = ledger_fixture();
    assert_throws(fn () => pl_home_overview($other['actor_id'], $f['company_id'], $f['book_id'], '2026-09-17'), DomainException::class);
    assert_throws(fn () => pl_home_overview($f['actor_id'], $f['company_id'], $other['book_id'], '2026-09-17'), DomainException::class);
});

test('Home bank attention agrees with reconciliation and excludes cancelled statements', function (): void {
    $f = ledger_fixture();
    $input = bank_fixture_input($f, [bank_fixture_row('HOME-1')], '12.34');
    $statement = bank_fixture_import($f, $input);
    $summary = pl_bank_reconciliation_summary($f['actor_id'], $f['company_id'], $f['book_id'], $statement['id']);
    assert_same($summary['unmatched_count'], pl_bank_pending_review_count($f['actor_id'], $f['company_id'], $f['book_id']));
    assert_same(1, pl_home_overview($f['actor_id'], $f['company_id'], $f['book_id'], '2026-09-17')['bank_lines']);
    pl_bank_cancel_statement($f['actor_id'], $f['company_id'], $f['book_id'], $statement['id'], $statement['revision'], 'Sample cancelled statement', bin2hex(random_bytes(16)));
    assert_same(0, pl_bank_pending_review_count($f['actor_id'], $f['company_id'], $f['book_id']));
});

test('Home getting-started guide reads the data, and Expense and Bill wait for money in', function (): void {
    $f = ledger_fixture();
    $home = pl_home_overview($f['actor_id'], $f['company_id'], $f['book_id'], '2026-09-17');
    $guide = $home['getting_started'];
    assert_same(6, $guide['total']);
    assert_same(0, $guide['done']);
    assert_same(false, $guide['complete']);
    assert_same(false, $guide['money_in']);
    assert_same('profile', $guide['current']);
    $done = array_column($guide['steps'], 'done', 'id');
    assert_same(false, $done['money_accounts'], 'The starter\'s generic leaf counted as a named account.');
    assert_same(1, count($home['cash_accounts']));
    assert_same('0.0000', $home['cash_accounts'][0]['balance']);
    // Capital introduced is money in, and not activity; the quick actions open on it.
    $cashId = (int) DB::queryFirstField('SELECT id FROM pl_accounts WHERE company_id = %i AND book_id = %i AND semantic_key = %s', $f['company_id'], $f['book_id'], 'core.cash_bank');
    $equityId = (int) DB::queryFirstField('SELECT id FROM pl_accounts WHERE company_id = %i AND book_id = %i AND semantic_key = %s', $f['company_id'], $f['book_id'], 'core.equity.owner');
    pl_post_owner_transaction($f['actor_id'], $f['company_id'], $f['book_id'], ['kind' => 'capital_introduced', 'date' => '2026-01-05', 'amount' => '1000',
        'cash_account_id' => $cashId, 'owner_account_id' => $equityId, 'creation_key' => 'home-capital-' . bin2hex(random_bytes(6))]);
    $home = pl_home_overview($f['actor_id'], $f['company_id'], $f['book_id'], '2026-09-17');
    assert_same(true, $home['getting_started']['money_in']);
    assert_same('1000.0000', $home['cash_accounts'][0]['balance']);
    $done = array_column($home['getting_started']['steps'], 'done', 'id');
    assert_same(true, $done['money_in']);
    assert_same(false, $done['first_activity'], 'Capital introduced counted as the first sale or expense.');
    // Naming the leaf after a real account, and a posted receipt, complete two more steps.
    $account = pl_get_account($f['actor_id'], $f['company_id'], $f['book_id'], $cashId);
    pl_save_account($f['actor_id'], $f['company_id'], $f['book_id'], array_replace($account, ['name' => 'Main bank', 'reason' => 'Named after the real account']), $cashId, $account['revision']);
    $draft = pl_save_document($f['actor_id'], $f['company_id'], $f['book_id'], document_input($f, 'receipt', '23.4567'));
    pl_post_document($f['actor_id'], $f['company_id'], $f['book_id'], $draft['id'], $draft['revision']);
    $guide = pl_home_overview($f['actor_id'], $f['company_id'], $f['book_id'], '2026-09-17')['getting_started'];
    $done = array_column($guide['steps'], 'done', 'id');
    assert_same(true, $done['money_accounts']);
    assert_same(true, $done['first_activity']);
    assert_same(3, $guide['done']);
    assert_same('profile', $guide['current'], 'The current step is the first one not done.');
    assert_same(['Main bank'], $guide['steps'][2]['facts']['names']);
});
