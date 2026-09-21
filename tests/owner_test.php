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

function owner_account(array $f, string $code, string $name, string $type, bool $contra): int
{
    return (int) pl_save_account($f['actor_id'], $f['company_id'], $f['book_id'], ['code' => $code, 'name' => $name,
        'type' => $type, 'role' => null, 'is_active' => true, 'is_contra' => $contra,
        'reason' => 'Sample owner test chart account.', 'creation_key' => bin2hex(random_bytes(16))])['id'];
}

function owner_balance(array $f, int $accountId, string $asOf = '2026-02-01'): string
{
    foreach (pl_trial_balance($f['actor_id'], $f['company_id'], $f['book_id'], $asOf)['accounts'] as $row) {
        if ((int) $row['id'] === $accountId) { return (string) $row['balance']; }
    }
    return '0.0000';
}

/**
 * A book shaped the way migration 036 leaves an upgraded chart: numbered and
 * keyed, but with `is_contra` at its `DEFAULT 0` on every row, plus two
 * accounts the owner added that carry no semantic key at all.
 */
function owner_converted_fixture(): array
{
    $f = owner_fixture();
    DB::update('pl_accounts', ['is_contra' => 0], 'company_id = %i AND book_id = %i', $f['company_id'], $f['book_id']);
    // 036 renumbered a chart and named none of its classes or groups; migration 045 is what adds
    // the heading rows, and it runs after 040. A converted book at 040 therefore has no heading
    // row at all, and leaving the chart's own headings here would put nineteen keyless names in
    // front of the contra confirmation step as things to decide about.
    DB::query("DELETE FROM pl_accounts WHERE company_id = %i AND book_id = %i AND code LIKE '_-___-00000-__'", $f['company_id'], $f['book_id']);
    $f['keyless'] = [
        'depreciation' => owner_account($f, '1-200-10001-00', 'Accumulated depreciation', 'asset', false),
        'allowances' => owner_account($f, '4-200-10001-00', 'Customer allowances', 'income', false),
    ];
    // The conversion receipt 036 writes. It is what tells the follow-up migration that this
    // book's chart was converted rather than born on 1.2.
    foreach (DB::query('SELECT id, code FROM pl_accounts WHERE company_id = %i AND book_id = %i', $f['company_id'], $f['book_id']) as $row) {
        DB::insert('pl_account_code_map', ['company_id' => $f['company_id'], 'book_id' => $f['book_id'],
            'account_id' => (int) $row['id'], 'legacy_code' => 'L' . (int) $row['id'], 'structured_code' => (string) $row['code']]);
    }
    return $f;
}

/**
 * Run migration 040's two data statements against the converted book above. The
 * schema half is already applied to the test database, so only the derivation
 * and the confirm-step seed are replayed, and they are read from the real
 * migration file rather than restated here.
 */
function owner_run_contra_migration(): void
{
    DB::query('DELETE c FROM pl_contra_confirmations c WHERE EXISTS (SELECT 1 FROM pl_account_code_map m WHERE m.company_id = c.company_id AND m.book_id = c.book_id)');
    $statements = require dirname(__DIR__) . '/www/phpledger/install/migrations/040_contra_accounts_and_partner_identity.php';
    $data = array_values(array_filter($statements, static fn (string $sql): bool => (bool) preg_match('/^\s*(UPDATE|INSERT)\b/i', $sql)));
    assert_same(2, count($data), 'Migration 040 carries exactly the contra derivation and the confirm-step seed as data statements.');
    foreach ($data as $sql) { DB::query($sql); }
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

/* ---------------------------------------------------------------------------
 * Internal accounting review of 1.2, findings 3, 4 and 5.
 * ------------------------------------------------------------------------ */

test('an owner transaction uses the only candidate account but refuses to choose between several', function (): void {
    $f = owner_fixture();
    // Exactly one capital account: the only candidate is not a choice, so this still posts.
    $only = pl_post_owner_transaction($f['actor_id'], $f['company_id'], $f['book_id'], owner_input('capital_introduced', '500000.0000'));
    assert_same($f['semantic']['core.equity.owner'], (int) $only['lines'][1]['account_id']);
    // Finding 3's scenario exactly: a revaluation reserve at 3-050 sorts before owner equity at
    // 3-100, and the old default credited 500,000 of proprietor's capital to the reserve.
    $reserve = owner_account($f, '3-050-10001-00', 'Revaluation reserve', 'equity', false);
    assert_throws(fn () => pl_post_owner_transaction($f['actor_id'], $f['company_id'], $f['book_id'],
        owner_input('capital_introduced', '500000.0000')), DomainException::class, 'no obvious one to use');
    // Naming the account is all it takes, and the reserve never receives a penny by default.
    $named = pl_post_owner_transaction($f['actor_id'], $f['company_id'], $f['book_id'],
        array_replace(owner_input('capital_introduced', '500000.0000'), ['owner_account_id' => $f['semantic']['core.equity.owner']]));
    assert_same($f['semantic']['core.equity.owner'], (int) $named['lines'][1]['account_id']);
    assert_same('0.0000', owner_balance($f, $reserve));
    assert_same('-1000000.0000', owner_balance($f, $f['semantic']['core.equity.owner']));
    // Drawings behaves the same way once the chart holds more than one contra-equity account.
    owner_account($f, '3-900-10002-00', 'Drawings - partner B', 'equity', true);
    assert_throws(fn () => pl_post_owner_transaction($f['actor_id'], $f['company_id'], $f['book_id'],
        owner_input('drawings', '100.0000')), DomainException::class, 'no obvious one to use');
    // So does the cash side: a second bank account is a choice nobody else can make.
    pl_save_account($f['actor_id'], $f['company_id'], $f['book_id'], ['code' => '1-100-10002-00', 'name' => 'Second bank account',
        'type' => 'asset', 'role' => 'cash_bank', 'is_active' => true, 'is_contra' => false,
        'reason' => 'Sample second bank account.', 'creation_key' => bin2hex(random_bytes(16))]);
    assert_throws(fn () => pl_post_owner_transaction($f['actor_id'], $f['company_id'], $f['book_id'],
        array_replace(owner_input('owner_loan_received', '100.0000'), ['owner_account_id' => $f['semantic']['core.liability.owner_loan']])),
        DomainException::class, 'cash or bank accounts');
});

test('once partners are on record, capital and drawings must say whose they are', function (): void {
    $f = owner_fixture();
    $capital = owner_account($f, '3-100-10002-00', 'Capital - partner A', 'equity', false);
    $partner = pl_save_owner_partner($f['actor_id'], $f['company_id'], $f['book_id'], ['name' => 'Partner A',
        'profit_share' => '1', 'is_active' => true, 'capital_account_id' => $capital]);
    assert_throws(fn () => pl_post_owner_transaction($f['actor_id'], $f['company_id'], $f['book_id'],
        owner_input('capital_introduced', '6000.0000')), DomainException::class, 'belongs to one of them');
    $posted = pl_post_owner_transaction($f['actor_id'], $f['company_id'], $f['book_id'],
        array_replace(owner_input('capital_introduced', '6000.0000'), ['partner_id' => $partner['id']]));
    assert_same($capital, (int) $posted['lines'][1]['account_id']);
    // An owner loan is a liability owed back, not a share of equity, so it is not partner-scoped here.
    $loan = pl_post_owner_transaction($f['actor_id'], $f['company_id'], $f['book_id'], owner_input('owner_loan_received', '2000.0000'));
    assert_same($f['semantic']['core.liability.owner_loan'], (int) $loan['lines'][1]['account_id']);
});

test('no chart account may serve two partners, or two roles', function (): void {
    $f = owner_fixture();
    $capitalA = owner_account($f, '3-100-10002-00', 'Capital - partner A', 'equity', false);
    $capitalB = owner_account($f, '3-100-10003-00', 'Capital - partner B', 'equity', false);
    $drawingsA = owner_account($f, '3-900-10002-00', 'Drawings - partner A', 'equity', true);
    $drawingsB = owner_account($f, '3-900-10003-00', 'Drawings - partner B', 'equity', true);
    $loan = $f['semantic']['core.liability.owner_loan'];
    $a = pl_save_owner_partner($f['actor_id'], $f['company_id'], $f['book_id'], ['name' => 'Partner A', 'profit_share' => '0.6',
        'is_active' => true, 'capital_account_id' => $capitalA, 'drawings_account_id' => $drawingsA, 'loan_account_id' => $loan]);
    $second = static fn (array $extra): array => ['name' => 'Partner B', 'profit_share' => '0.4', 'is_active' => true,
        'capital_account_id' => $capitalB] + $extra;
    // A shared drawings account is refused...
    assert_throws(fn () => pl_save_owner_partner($f['actor_id'], $f['company_id'], $f['book_id'], $second(['drawings_account_id' => $drawingsA])),
        DomainException::class, 'already Partner A\'s drawings account');
    // ...and so is a shared loan account.
    assert_throws(fn () => pl_save_owner_partner($f['actor_id'], $f['company_id'], $f['book_id'], $second(['loan_account_id' => $loan])),
        DomainException::class, 'already Partner A\'s owner loan account');
    // A contra-equity account is never a capital candidate, so naming another partner's drawings
    // account as this partner's capital account is refused before the identity rule is reached.
    assert_throws(fn () => pl_save_owner_partner($f['actor_id'], $f['company_id'], $f['book_id'],
        ['name' => 'Partner C', 'profit_share' => '0.4', 'is_active' => true, 'capital_account_id' => $drawingsA]),
        DomainException::class, 'owner capital account');
    // The cross-role rule is reached when an account changes side: partner A's capital account is
    // marked contra in the chart, which makes it a drawings candidate while A still holds it.
    pl_save_account($f['actor_id'], $f['company_id'], $f['book_id'], ['code' => '3-100-10002-00', 'name' => 'Capital - partner A',
        'type' => 'equity', 'role' => null, 'is_active' => true, 'is_contra' => true,
        'reason' => 'Sample contra reclassification.'], $capitalA, 1);
    assert_throws(fn () => pl_save_owner_partner($f['actor_id'], $f['company_id'], $f['book_id'], $second(['drawings_account_id' => $capitalA])),
        DomainException::class, 'already Partner A\'s owner capital account');
    $b = pl_save_owner_partner($f['actor_id'], $f['company_id'], $f['book_id'], $second(['drawings_account_id' => $drawingsB]));
    assert_same($drawingsB, $b['drawings_account_id']);
    assert_true($a['id'] !== $b['id'], 'Two partners are two records.');
});

test('each partner is shown their own drawings, and a shared account can no longer exist', function (): void {
    $f = owner_fixture();
    $capitalA = owner_account($f, '3-100-10002-00', 'Capital - partner A', 'equity', false);
    $capitalB = owner_account($f, '3-100-10003-00', 'Capital - partner B', 'equity', false);
    $drawingsA = owner_account($f, '3-900-10002-00', 'Drawings - partner A', 'equity', true);
    $drawingsB = owner_account($f, '3-900-10003-00', 'Drawings - partner B', 'equity', true);
    $a = pl_save_owner_partner($f['actor_id'], $f['company_id'], $f['book_id'], ['name' => 'Partner A', 'profit_share' => '0.6',
        'is_active' => true, 'capital_account_id' => $capitalA, 'drawings_account_id' => $drawingsA]);
    $b = pl_save_owner_partner($f['actor_id'], $f['company_id'], $f['book_id'], ['name' => 'Partner B', 'profit_share' => '0.4',
        'is_active' => true, 'capital_account_id' => $capitalB, 'drawings_account_id' => $drawingsB]);
    pl_post_owner_transaction($f['actor_id'], $f['company_id'], $f['book_id'],
        array_replace(owner_input('drawings', '40000.0000'), ['partner_id' => $a['id']]));
    $positions = pl_owner_partner_positions($f['actor_id'], $f['company_id'], $f['book_id'], '2026-02-01');
    // Finding 4 showed 40,000 posted and 80,000 reported. Each partner now carries their own.
    assert_same('40000.0000', $positions[0]['drawings']);
    assert_same('0.0000', $positions[1]['drawings']);
    assert_same('40000.0000', pl_owner_equity_movements($f['actor_id'], $f['company_id'], $f['book_id'], '2026-02-01')['total_drawings']);
    // The service refuses a shared account with a readable message...
    assert_throws(fn () => pl_save_owner_partner($f['actor_id'], $f['company_id'], $f['book_id'],
        ['name' => 'Partner B', 'profit_share' => '0.4', 'is_active' => true, 'capital_account_id' => $capitalB,
            'drawings_account_id' => $drawingsA], $b['id'], $b['revision']), DomainException::class, 'already Partner A\'s drawings account');
    // ...and so does the schema, whatever writes the row.
    assert_throws(fn () => DB::update('pl_owner_partners', ['drawings_account_id' => $drawingsA], 'id = %i', $b['id']),
        Throwable::class, 'uq_owner_partner_drawings');
});

test('migration 040 marks a converted chart from its semantic keys and leaves the rest to be confirmed', function (): void {
    $f = owner_converted_fixture();
    // The frozen list in the migration is the bundled chart's own contra purposes, and cannot drift.
    $source = (string) file_get_contents(dirname(__DIR__) . '/www/phpledger/install/migrations/040_contra_accounts_and_partner_identity.php');
    assert_true(preg_match('/\$contraKeys = \[(.*?)\];/s', $source, $match) === 1, 'Migration 040 declares its contra purposes.');
    $listed = [];
    foreach (explode(',', (string) preg_replace('/\s+/', ' ', $match[1])) as $entry) {
        $entry = trim($entry, " '\"");
        if ($entry !== '') { $listed[] = $entry; }
    }
    sort($listed);
    assert_same(pl_contra_semantic_keys(), $listed, 'Migration 040 derives from exactly the purposes the bundled chart marks contra.');
    assert_same(4, count($listed));
    // The defect first: a chart converted by 036 has no drawings account at all, and its own
    // drawings account appears among the capital candidates where the old default could pick it.
    $before = pl_owner_accounts($f['company_id'], $f['book_id']);
    assert_same([], $before['drawings']);
    assert_true(in_array('3-900-10001-00', array_column($before['capital'], 'code'), true), 'The converted drawings account sits in the capital list.');
    assert_throws(fn () => pl_post_owner_transaction($f['actor_id'], $f['company_id'], $f['book_id'],
        owner_input('drawings', '100.0000')), DomainException::class, 'no drawings account');

    owner_run_contra_migration();

    $marked = DB::queryFirstColumn('SELECT semantic_key FROM pl_accounts WHERE company_id = %i AND book_id = %i AND is_contra = 1 ORDER BY semantic_key', $f['company_id'], $f['book_id']);
    assert_same(pl_contra_semantic_keys(), array_map('strval', $marked));
    // Nothing is deduced from a name: an account called "Accumulated depreciation" with no
    // semantic key is left exactly as it was, for the reviewer to decide about.
    assert_same(0, (int) DB::queryFirstField('SELECT is_contra FROM pl_accounts WHERE id = %i', $f['keyless']['depreciation']));
    assert_same(0, (int) DB::queryFirstField('SELECT is_contra FROM pl_accounts WHERE id = %i', $f['keyless']['allowances']));
    $state = pl_contra_confirmation($f['company_id'], $f['book_id']);
    assert_same('pending', $state['status']);
    assert_same(4, $state['derived_contra_count']);
    assert_same(2, $state['candidate_count']);
    assert_same(null, $state['answer']);
    // A book born on 1.2 was never converted, so it is never given the step.
    $born = owner_fixture();
    assert_same(null, pl_contra_confirmation($born['company_id'], $born['book_id']));
    assert_same(false, pl_contra_review_pending($born['company_id'], $born['book_id']));
});

test('the owner screens stay closed until the contra confirmation is answered, and open afterwards', function (): void {
    $f = owner_converted_fixture();
    owner_run_contra_migration();
    assert_true(pl_contra_review_pending($f['company_id'], $f['book_id']));
    assert_throws(fn () => pl_post_owner_transaction($f['actor_id'], $f['company_id'], $f['book_id'],
        owner_input('capital_introduced', '1000.0000')), DomainException::class, 'Confirm which accounts are contra accounts');
    assert_throws(fn () => pl_save_owner_partner($f['actor_id'], $f['company_id'], $f['book_id'], ['name' => 'Partner A',
        'profit_share' => '1', 'is_active' => true, 'capital_account_id' => $f['semantic']['core.equity.owner']]),
        DomainException::class, 'Confirm which accounts are contra accounts');
    $review = pl_contra_review($f['actor_id'], $f['company_id'], $f['book_id']);
    assert_same(4, count($review['derived']));
    $candidates = array_column($review['candidates'], 'id');
    assert_same(2, count($candidates));
    assert_true(in_array($f['keyless']['depreciation'], $candidates, true));
    assert_true(!in_array($f['semantic']['core.equity.owner'], $candidates, true), 'An account with a chart purpose is not asked about.');
    // Only what the screen offered can be marked here.
    assert_throws(fn () => pl_confirm_contra_accounts($f['actor_id'], $f['company_id'], $f['book_id'],
        [$f['semantic']['core.equity.owner']], 'Reviewed with the accountant.', bin2hex(random_bytes(16))), DomainException::class, 'not on this list');
    $key = bin2hex(random_bytes(16));
    $confirmed = pl_confirm_contra_accounts($f['actor_id'], $f['company_id'], $f['book_id'],
        [$f['keyless']['depreciation']], 'Reviewed against the fixed-asset register.', $key);
    assert_same('confirmed', $confirmed['status']);
    assert_same(1, count($confirmed['answer']['marked']));
    assert_same(2, $confirmed['answer']['reviewed']);
    assert_same(1, (int) DB::queryFirstField('SELECT is_contra FROM pl_accounts WHERE id = %i', $f['keyless']['depreciation']));
    assert_same(0, (int) DB::queryFirstField('SELECT is_contra FROM pl_accounts WHERE id = %i', $f['keyless']['allowances']));
    // The same reviewed answer replayed is the same record, not a second one.
    assert_same($confirmed['answer'], pl_confirm_contra_accounts($f['actor_id'], $f['company_id'], $f['book_id'],
        [$f['keyless']['depreciation']], 'Reviewed against the fixed-asset register.', $key)['answer']);
    // A different answer afterwards belongs in the chart of accounts, with its own reason.
    assert_throws(fn () => pl_confirm_contra_accounts($f['actor_id'], $f['company_id'], $f['book_id'],
        [$f['keyless']['allowances']], 'Second thoughts.', bin2hex(random_bytes(16))), DomainException::class, 'already confirmed');
    // Every account it changed carries the ordinary chart audit row with the reviewer's reason.
    assert_same('Reviewed against the fixed-asset register.', (string) DB::queryFirstField(
        "SELECT reason FROM pl_core_audit WHERE book_id = %i AND entity_type = 'account' AND entity_id = %i ORDER BY id DESC LIMIT 1",
        $f['book_id'], $f['keyless']['depreciation']));
    // A recorded answer cannot be quietly rewritten in place.
    assert_throws(fn () => DB::update('pl_contra_confirmations', ['reason' => 'Rewritten.'], 'book_id = %i', $f['book_id']),
        Throwable::class, 'cannot be edited');
    // And the screens are open again: drawings post to the chart's own drawings account.
    assert_same(false, pl_contra_review_pending($f['company_id'], $f['book_id']));
    $journal = pl_post_owner_transaction($f['actor_id'], $f['company_id'], $f['book_id'], owner_input('drawings', '100.0000'));
    assert_same($f['semantic']['core.equity.drawings'], (int) $journal['lines'][0]['account_id']);
    assert_same('100.0000', pl_owner_equity_movements($f['actor_id'], $f['company_id'], $f['book_id'], '2026-02-01')['total_drawings']);
});
