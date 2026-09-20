<?php
declare(strict_types=1);

function owner_fixture(): array
{
    $f = ledger_fixture('USD', '2026-01-01');
    $accounts = DB::query('SELECT id, code, semantic_key FROM pl_accounts WHERE company_id = %i', $f['company_id']);
    $bySemantic = [];
    foreach ($accounts as $row) { $bySemantic[(string) $row['semantic_key']] = (int) $row['id']; }
    return $f + ['semantic' => $bySemantic];
}

function owner_input(string $kind, string $amount, string $date = '2026-02-01'): array
{
    return ['kind' => $kind, 'date' => $date, 'amount' => $amount, 'cash_account_id' => null,
        'description' => 'Owner test: ' . $kind, 'creation_key' => bin2hex(random_bytes(16))];
}

test('each owner transaction posts the journal its accounting treatment requires', function (): void {
    $f = owner_fixture();
    $expected = [
        'capital_introduced' => ['1000', 'core.equity.owner'],
        'owner_loan_received' => ['1000', 'core.liability.owner_loan'],
        'owner_loan_repaid' => ['core.liability.owner_loan', '1000'],
        'drawings' => ['core.equity.drawings', '1000'],
    ];
    $amounts = ['capital_introduced' => '5000.0000', 'owner_loan_received' => '2000.0000',
        'owner_loan_repaid' => '500.0000', 'drawings' => '300.0000'];
    foreach ($expected as $kind => [$debit, $credit]) {
        $journal = pl_post_owner_transaction($f['actor_id'], $f['company_id'], $f['book_id'], owner_input($kind, $amounts[$kind]));
        assert_same('owner_transaction', $journal['source_type']);
        assert_same(2, count($journal['lines']));
        $debitId = $debit === '1000' ? $f['accounts']['1000'] : $f['semantic'][$debit];
        $creditId = $credit === '1000' ? $f['accounts']['1000'] : $f['semantic'][$credit];
        assert_same($debitId, (int) $journal['lines'][0]['account_id'], $kind . ' debits the wrong account.');
        assert_same($creditId, (int) $journal['lines'][1]['account_id'], $kind . ' credits the wrong account.');
        assert_same($amounts[$kind], (string) $journal['lines'][0]['debit']);
        assert_same($amounts[$kind], (string) $journal['lines'][1]['credit']);
    }
    // The trial balance still balances and the owner's loan is a liability, not equity.
    $trial = pl_trial_balance($f['actor_id'], $f['company_id'], $f['book_id'], '2026-02-01');
    assert_true($trial['balanced']);
    $balances = [];
    foreach ($trial['accounts'] as $row) { $balances[(int) $row['id']] = $row['balance']; }
    assert_same('-5000.0000', $balances[$f['semantic']['core.equity.owner']]);
    assert_same('-1500.0000', $balances[$f['semantic']['core.liability.owner_loan']]);
    assert_same('300.0000', $balances[$f['semantic']['core.equity.drawings']]);
    assert_same('6200.0000', $balances[$f['accounts']['1000']]);
    $movements = pl_owner_equity_movements($f['actor_id'], $f['company_id'], $f['book_id'], '2026-02-01');
    assert_same('5000.0000', $movements['total_capital']);
    assert_same('300.0000', $movements['total_drawings']);
    assert_same('1500.0000', $movements['total_owner_loans']);
    assert_same('4700.0000', $movements['net_owner_equity']);
    // Equity on the balance sheet holds capital less drawings; the loan stays in liabilities.
    $sheet = pl_balance_sheet($f['actor_id'], $f['company_id'], $f['book_id'], '2026-02-01');
    assert_true($sheet['balanced']);
    assert_same('4700.0000', $sheet['recorded_equity']);
    assert_same('1500.0000', $sheet['total_liabilities']);
    assert_same($movements['total_capital'], $sheet['equity_movements']['total_capital']);
});

test('owner transactions are idempotent, immutable and corrected only by a linked reversal', function (): void {
    $f = owner_fixture();
    $input = owner_input('capital_introduced', '1000.0000');
    $journal = pl_post_owner_transaction($f['actor_id'], $f['company_id'], $f['book_id'], $input);
    assert_same($journal['id'], pl_post_owner_transaction($f['actor_id'], $f['company_id'], $f['book_id'], $input)['id']);
    assert_throws(fn () => pl_post_owner_transaction($f['actor_id'], $f['company_id'], $f['book_id'], array_replace($input, ['amount' => '2000.0000'])), DomainException::class, 'different journal');
    $reversal = pl_reverse_owner_transaction($f['actor_id'], $f['company_id'], $f['book_id'], $journal['id'], '2026-02-01', 'Recorded against the wrong business.');
    assert_same($journal['id'], (int) $reversal['reversal_of_id']);
    assert_same((string) $journal['lines'][0]['debit'], (string) $reversal['lines'][0]['credit']);
    assert_same('0.0000', pl_owner_equity_movements($f['actor_id'], $f['company_id'], $f['book_id'], '2026-02-01')['total_capital']);
    assert_same($reversal['id'], pl_reverse_owner_transaction($f['actor_id'], $f['company_id'], $f['book_id'], $journal['id'], '2026-02-01', 'Recorded against the wrong business.')['id']);
    $list = pl_list_owner_transactions($f['actor_id'], $f['company_id'], $f['book_id']);
    assert_same(1, count($list));
    assert_same('capital_introduced', $list[0]['kind']);
    assert_same('Capital introduced', $list[0]['kind_label']);
    assert_same('reversed', $list[0]['status']);
    // Anything else is not an owner transaction and cannot be reversed from this screen.
    $other = pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], ledger_payload($f));
    assert_throws(fn () => pl_reverse_owner_transaction($f['actor_id'], $f['company_id'], $f['book_id'], $other['id'], null, 'Wrong screen.'), DomainException::class, 'not recorded as an owner transaction');
});

test('owner transactions refuse invalid input, missing accounts and callers without write access', function (): void {
    $f = owner_fixture();
    foreach ([['kind' => 'gift'], ['amount' => '0'], ['amount' => '-5'], ['amount' => '1,000'], ['date' => '2026-02-30']] as $change) {
        assert_throws(fn () => pl_post_owner_transaction($f['actor_id'], $f['company_id'], $f['book_id'], array_replace(owner_input('drawings', '10.0000'), $change)), DomainException::class);
    }
    assert_throws(fn () => pl_post_owner_transaction($f['actor_id'], $f['company_id'], $f['book_id'], array_replace(owner_input('drawings', '10.0000'), ['cash_account_id' => $f['semantic']['core.equity.owner']])), DomainException::class, 'cash or bank');
    $viewer = ledger_fixture();
    DB::insert('pl_company_members', ['company_id' => $f['company_id'], 'user_id' => $viewer['actor_id'], 'role' => 'viewer']);
    assert_throws(fn () => pl_post_owner_transaction($viewer['actor_id'], $f['company_id'], $f['book_id'], owner_input('drawings', '10.0000')), DomainException::class);
    assert_same(0, count(pl_list_owner_transactions($f['actor_id'], $f['company_id'], $f['book_id'])));
    // A book with no drawings account says so instead of inventing one.
    $bare = ledger_fixture();
    DB::update('pl_accounts', ['is_active' => 0], 'company_id = %i AND is_contra = 1', $bare['company_id']);
    assert_throws(fn () => pl_post_owner_transaction($bare['actor_id'], $bare['company_id'], $bare['book_id'], owner_input('drawings', '10.0000')), DomainException::class, 'no drawings account');
});

test('partner capital accounts carry profit-sharing ratios that must total one', function (): void {
    $f = owner_fixture();
    $make = static fn (array $f, string $code, string $name, string $type, bool $contra): array =>
        pl_save_account($f['actor_id'], $f['company_id'], $f['book_id'], ['code' => $code, 'name' => $name, 'type' => $type,
            'role' => null, 'is_active' => true, 'is_contra' => $contra, 'reason' => 'Partner accounts for an AOP.',
            'creation_key' => bin2hex(random_bytes(16))]);
    $capitalA = $make($f, '3-100-10002-00', 'Capital — partner A', 'equity', false);
    $capitalB = $make($f, '3-100-10003-00', 'Capital — partner B', 'equity', false);
    $drawingsA = $make($f, '3-900-10002-00', 'Drawings — partner A', 'equity', true);
    $a = pl_save_owner_partner($f['actor_id'], $f['company_id'], $f['book_id'], ['name' => 'Partner A',
        'profit_share' => '0.6', 'is_active' => true, 'capital_account_id' => $capitalA['id'], 'drawings_account_id' => $drawingsA['id']]);
    assert_same('0.600000', $a['profit_share']);
    // Partners are entered one at a time, so the ratios are simply not complete yet.
    assert_same(false, pl_owner_shares_complete($f['actor_id'], $f['company_id'], $f['book_id']));
    assert_throws(fn () => pl_save_owner_partner($f['actor_id'], $f['company_id'], $f['book_id'], ['name' => 'Partner over',
        'profit_share' => '0.5', 'is_active' => true, 'capital_account_id' => $capitalB['id']]), DomainException::class, 'cannot total more than 1');
    $b = pl_save_owner_partner($f['actor_id'], $f['company_id'], $f['book_id'], ['name' => 'Partner B',
        'profit_share' => '0.4', 'is_active' => true, 'capital_account_id' => $capitalB['id']]);
    assert_same(true, pl_owner_shares_complete($f['actor_id'], $f['company_id'], $f['book_id']));
    assert_same(2, count(pl_list_owner_partners($f['actor_id'], $f['company_id'], $f['book_id'])));
    foreach (['1.5', '-0.1', 'half', ''] as $bad) {
        assert_throws(fn () => pl_save_owner_partner($f['actor_id'], $f['company_id'], $f['book_id'], ['name' => 'Partner C',
            'profit_share' => $bad, 'is_active' => true, 'capital_account_id' => $capitalB['id']]), DomainException::class);
    }
    // A partner-specific movement uses that partner's own accounts.
    pl_post_owner_transaction($f['actor_id'], $f['company_id'], $f['book_id'], array_replace(owner_input('capital_introduced', '6000.0000'), ['partner_id' => $a['id']]));
    pl_post_owner_transaction($f['actor_id'], $f['company_id'], $f['book_id'],
        array_replace(owner_input('capital_introduced', '4000.0000'), ['partner_id' => $b['id']]));
    pl_post_owner_transaction($f['actor_id'], $f['company_id'], $f['book_id'],
        array_replace(owner_input('drawings', '500.0000'), ['partner_id' => $a['id']]));
    assert_throws(fn () => pl_post_owner_transaction($f['actor_id'], $f['company_id'], $f['book_id'],
        array_replace(owner_input('drawings', '100.0000'), ['partner_id' => $b['id']])), DomainException::class, 'no drawings account');
    $positions = pl_owner_partner_positions($f['actor_id'], $f['company_id'], $f['book_id'], '2026-02-01');
    assert_same(2, count($positions));
    assert_same('Partner A', $positions[0]['name']);
    assert_same('6000.0000', $positions[0]['capital']);
    assert_same('500.0000', $positions[0]['drawings']);
    assert_same('5500.0000', $positions[0]['net_capital']);
    assert_same('4000.0000', $positions[1]['capital']);
    assert_same('4000.0000', $positions[1]['net_capital']);
    // Profit allocation between partners is deliberately not posted here (B30); see
    // docs/accounting/OWNER-TRANSACTIONS.md. The ratios are recorded, nothing is guessed.
    assert_same('0.600000', $positions[0]['profit_share']);
    assert_same('0.400000', $positions[1]['profit_share']);
});
