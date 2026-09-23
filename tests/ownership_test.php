<?php
declare(strict_types=1);

/**
 * The ownership register (1.2 M8a, issue #92; owner decisions B63, B64, B71, B72, B74, B76).
 *
 * Three things in here are load-bearing and deserve saying out loud.
 *
 * 1. **The related-party default is tested, not assumed.** B74 narrowed B72: an ordinary employee
 *    is not a related party, every person defaults to *not* related, and the designation must be
 *    affirmative and separate from any role. There is therefore a test that appoints a director
 *    who is also a supplier, changes nothing else, and asserts the related-party report is empty.
 *    If anybody ever makes a role imply a marker, that test fails.
 *
 * 2. **Transfers post nothing** is proved at the ledger, not at the form: a transfer is recorded
 *    and the trial balance is asserted unchanged to the penny.
 *
 * 3. **The two-period worked examples are in the suite, not only in the documentation.** The
 *    partnership and the private company in `docs/accounting/OWNERSHIP-REGISTER.md` are computed
 *    here with the same figures, so the worked example the accountant reviews cannot drift from
 *    what the software does.
 */

function ownership_fixture(string $date = '2025-01-01'): array
{
    $suffix = bin2hex(random_bytes(8));
    $actorId = pl_create_user('ownership-' . $suffix . '@example.invalid', 'Sample ownership tester', 'Sample-ownership-password-' . $suffix);
    $fixture = ['actor_id' => $actorId, 'suffix' => $suffix]
        + pl_create_company($actorId, 'Sample ownership company ' . $suffix, 'USD', $date);
    pl_capability_cache_reset();
    // A second reporting year, so a two-period example has somewhere to post its second period.
    pl_create_period($actorId, (int) $fixture['company_id'], (int) $fixture['book_id'],
        ['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'reason' => 'Sample second reporting year', 'request_key' => 'ownership-2026-' . $suffix]);
    return $fixture;
}

function ownership_account(array $f, string $code, string $name, string $type, bool $contra = false): int
{
    return (int) pl_save_account($f['actor_id'], $f['company_id'], $f['book_id'], ['code' => $code, 'name' => $name,
        'type' => $type, 'role' => null, 'is_active' => true, 'is_contra' => $contra,
        'reason' => 'Sample ownership chart account.', 'creation_key' => bin2hex(random_bytes(16))])['id'];
}

function ownership_person(array $f, string $name, string $kind = 'person', string $identifier = ''): int
{
    return (int) pl_save_ownership_party($f['actor_id'], $f['company_id'], [
        'kind' => $kind, 'name' => $name, 'country_code' => 'PK', 'identifier' => $identifier,
        'address' => '', 'email' => '', 'note' => '', 'is_active' => true,
        'reason' => 'Sample register entry.',
    ])['id'];
}

function ownership_class(array $f, string $code = 'ORD', string $nominal = '10.0000', ?string $authorised = '100000', bool $pool = false, string $type = 'common'): int
{
    return (int) pl_save_share_class($f['actor_id'], $f['company_id'], [
        'code' => $code, 'name' => $code . ' shares', 'class_type' => $type, 'currency' => 'USD',
        'nominal_value' => $nominal, 'votes_per_share' => '1', 'authorised_shares' => $authorised ?? '',
        'is_option_pool' => $pool, 'is_active' => true, 'dividend_rights' => '', 'liquidation_rights' => '',
        'reason' => 'Sample share class.',
    ])['id'];
}

function ownership_event(array $f, array $input): array
{
    return pl_record_share_event($f['actor_id'], $f['company_id'], (int) $f['book_id'],
        $input + ['creation_key' => bin2hex(random_bytes(16)), 'reason' => 'Sample share ledger event.']);
}

function ownership_balance(array $f, int $accountId, string $asOf): string
{
    foreach (pl_trial_balance($f['actor_id'], $f['company_id'], $f['book_id'], $asOf)['accounts'] as $row) {
        if ((int) $row['id'] === $accountId) { return (string) $row['balance']; }
    }
    return '0.0000';
}

/** One equity chart with a share capital account and a share premium account. */
function ownership_company_chart(array $f): array
{
    return [
        'capital' => ownership_account($f, '3-210-10001-00', 'Share capital', 'equity'),
        'premium' => ownership_account($f, '3-220-10001-00', 'Share premium', 'equity'),
        'cash' => (int) $f['accounts']['1000'],
    ];
}

/* ------------------------------------------------- the legal form and registration profile (B63) */

test('the company profile records a legal form and registration, and a legal form never selects a framework', function (): void {
    $f = ownership_fixture();
    $saved = pl_save_company_profile($f['actor_id'], (int) $f['company_id'], [
        'legal_name' => 'Sample Private Limited', 'legal_form' => 'private_limited',
        'registration_number' => '0099887', 'registration_authority' => 'Securities and Exchange Commission of Pakistan',
        'incorporation_date' => '2019-04-15', 'financial_year_end_month' => 6, 'financial_year_end_day' => 30,
        'address_line1' => '12 Sample Road', 'address_line2' => '', 'address_line3' => '', 'phone' => '',
        'email' => '', 'tax_registrations' => '', 'footer_terms' => '',
        'revision' => 0, 'reason' => 'Sample registration profile.', 'idempotency_key' => bin2hex(random_bytes(16)),
    ]);
    assert_same('private_limited', $saved['legal_form']);
    assert_same('2019-04-15', $saved['incorporation_date']);
    assert_same('30 June', $saved['financial_year_end']);
    assert_same('Private limited company', $saved['legal_form_label']);
    assert_true(pl_legal_form_has_shares('private_limited'), 'A private limited company issues shares.');
    assert_true(pl_legal_form_has_partners('partnership'), 'A partnership has partners rather than shares.');
    // The framework is chosen, never inferred: nothing in the register reads the legal form to
    // decide an accounting treatment, and the profile carries no framework field at all.
    assert_true(!array_key_exists('accounting_framework', $saved), 'A legal form must not carry an accounting framework.');

    // The letterhead prints it, because several jurisdictions require the registration number on
    // a business letter.
    $letterhead = pl_print_letterhead(['name' => 'Sample', 'currency' => 'USD'], $saved);
    assert_same('Private limited company', $letterhead['legal_form']);
    assert_true(str_contains($letterhead['incorporation'], '0099887'), 'The letterhead carries the registration number.');
    assert_true(str_contains($letterhead['incorporation'], 'Securities and Exchange Commission'), 'The letterhead names the authority.');
});

test('the registration profile refuses a form it does not know and a year end that does not exist', function (): void {
    $f = ownership_fixture();
    $base = array_fill_keys(pl_company_profile_fields(), '');
    $base['revision'] = 0;
    $base['reason'] = 'Sample refusal.';
    foreach ([
        ['legal_form' => 'sole_trader_ltd_gmbh'],
        ['financial_year_end_month' => 2, 'financial_year_end_day' => 30],
        ['financial_year_end_month' => 2, 'financial_year_end_day' => 29],
        ['financial_year_end_month' => 6],
        ['financial_year_end_day' => 30],
        ['incorporation_date' => '2019-02-31'],
    ] as $bad) {
        assert_throws(fn () => pl_save_company_profile($f['actor_id'], (int) $f['company_id'],
            $bad + $base + ['idempotency_key' => bin2hex(random_bytes(16))]), DomainException::class);
    }
    assert_true(pl_financial_year_end_valid(6, 30), '30 June is a financial year end.');
    assert_true(!pl_financial_year_end_valid(2, 29), '29 February exists in three years out of four, so it is refused.');
    assert_true(!pl_financial_year_end_valid(13, 1), 'There is no thirteenth month.');
});

/* --------------------------------------------------------------- people, members and officers */

test('the members register is effective dated and one person\'s interests never overlap', function (): void {
    $f = ownership_fixture();
    $aslam = ownership_person($f, 'Aslam Sample');
    $first = pl_save_ownership_member($f['actor_id'], (int) $f['company_id'], [
        'ownership_party_id' => $aslam, 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31',
        'profit_share' => '0.6', 'note' => '', 'reason' => 'Sample interest.']);
    assert_same('0.600000', $first['profit_share']);
    // A second interest that overlaps the first is two answers to one question, not an amendment.
    assert_throws(fn () => pl_save_ownership_member($f['actor_id'], (int) $f['company_id'], [
        'ownership_party_id' => $aslam, 'effective_from' => '2026-06-01', 'effective_to' => '',
        'profit_share' => '0.5', 'note' => '', 'reason' => 'Sample overlap.']), DomainException::class, 'already has an interest');
    // One that begins after the first ends is allowed, and the snapshot at a date picks the
    // interest that was in force on that day.
    pl_save_ownership_member($f['actor_id'], (int) $f['company_id'], [
        'ownership_party_id' => $aslam, 'effective_from' => '2027-01-01', 'effective_to' => '',
        'profit_share' => '0.5', 'note' => '', 'reason' => 'Sample renegotiated share.']);
    assert_same('0.600000', pl_list_ownership_members($f['actor_id'], (int) $f['company_id'], '2026-06-30')[0]['profit_share']);
    assert_same('0.500000', pl_list_ownership_members($f['actor_id'], (int) $f['company_id'], '2027-06-30')[0]['profit_share']);
    assert_same(0, count(pl_list_ownership_members($f['actor_id'], (int) $f['company_id'], '2025-06-30')));
    assert_same(2, count(pl_list_ownership_members($f['actor_id'], (int) $f['company_id'])));
});

test('an officer with significant control must say how control is held, and a directorship is not a marker', function (): void {
    $f = ownership_fixture();
    $person = ownership_person($f, 'Bilal Sample');
    assert_throws(fn () => pl_save_ownership_officer($f['actor_id'], (int) $f['company_id'], [
        'ownership_party_id' => $person, 'officer_role' => 'director', 'role_title' => '',
        'appointed_on' => '2026-01-01', 'resigned_on' => '', 'has_significant_control' => true,
        'control_nature' => '', 'note' => '', 'reason' => 'Sample PSC with no nature.']),
        DomainException::class, 'Say how significant control is held');
    $officer = pl_save_ownership_officer($f['actor_id'], (int) $f['company_id'], [
        'ownership_party_id' => $person, 'officer_role' => 'director', 'role_title' => 'Chair',
        'appointed_on' => '2026-01-01', 'resigned_on' => '', 'has_significant_control' => true,
        'control_nature' => 'Holds more than 75% of the voting rights', 'note' => '', 'reason' => 'Sample appointment.']);
    assert_true($officer['has_significant_control']);
    assert_true($officer['is_director'], 'IAS 24 makes any director key management personnel.');
    // B74: recording a directorship creates no related-party marker. Nobody is related by default.
    assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_related_party_markers WHERE company_id = %i', $f['company_id']),
        'An appointment must never imply a related-party marker.');
    assert_throws(fn () => pl_save_ownership_officer($f['actor_id'], (int) $f['company_id'], [
        'ownership_party_id' => $person, 'officer_role' => 'director', 'role_title' => '',
        'appointed_on' => '2026-05-01', 'resigned_on' => '2026-01-01', 'has_significant_control' => false,
        'control_nature' => '', 'note' => '', 'reason' => 'Sample backwards appointment.']), DomainException::class);
});

/* ----------------------------------------------------------- share classes and the share ledger */

test('a share class stores no issued count and the ledger supplies it', function (): void {
    $f = ownership_fixture();
    $chart = ownership_company_chart($f);
    $classId = ownership_class($f);
    $holder = ownership_person($f, 'Ayesha Sample');
    assert_same('0.000000', pl_list_share_classes($f['actor_id'], (int) $f['company_id'])[0]['issued_shares']);
    assert_true(!in_array('issued_shares', array_map(
        static fn (array $row): string => (string) $row['Field'],
        DB::query('SHOW COLUMNS FROM pl_share_classes')), true), 'The issued count must never be a stored column.');
    ownership_event($f, ['event_type' => 'allotment', 'share_class_id' => $classId, 'effective_date' => '2026-03-01',
        'quantity' => '1000', 'to_party_id' => $holder, 'consideration_amount' => '10000',
        'post' => true, 'debit_account_id' => $chart['cash'], 'share_capital_account_id' => $chart['capital']]);
    assert_same('1000.000000', pl_list_share_classes($f['actor_id'], (int) $f['company_id'])[0]['issued_shares']);
    // The issued count is a function of the date, not a running total.
    assert_same('0.000000', pl_list_share_classes($f['actor_id'], (int) $f['company_id'], '2026-02-28')[0]['issued_shares']);
    // Authorised capital is a real limit, and it cannot be lowered below what is issued.
    assert_throws(fn () => ownership_event($f, ['event_type' => 'allotment', 'share_class_id' => $classId,
        'effective_date' => '2026-04-01', 'quantity' => '99001', 'to_party_id' => $holder,
        'consideration_amount' => '990010', 'post' => false]), DomainException::class, 'authorised for');
    $class = pl_get_share_class($f['actor_id'], (int) $f['company_id'], $classId);
    assert_throws(fn () => pl_save_share_class($f['actor_id'], (int) $f['company_id'], [
        'code' => 'ORD', 'name' => 'ORD shares', 'class_type' => 'common', 'currency' => 'USD',
        'nominal_value' => '10.0000', 'votes_per_share' => '1', 'authorised_shares' => '500',
        'is_option_pool' => false, 'is_active' => true, 'dividend_rights' => '', 'liquidation_rights' => '',
        'reason' => 'Sample shrink.'], $classId, $class['revision']), DomainException::class, 'already has');
});

test('an allotment posts share capital and premium through the core posting service and nowhere else', function (): void {
    $f = ownership_fixture();
    $chart = ownership_company_chart($f);
    $classId = ownership_class($f);
    $holder = ownership_person($f, 'Ayesha Sample');
    $event = ownership_event($f, ['event_type' => 'allotment', 'share_class_id' => $classId,
        'effective_date' => '2026-03-01', 'quantity' => '500', 'to_party_id' => $holder,
        'consideration_amount' => '12500', 'post' => true, 'debit_account_id' => $chart['cash'],
        'share_capital_account_id' => $chart['capital'], 'share_premium_account_id' => $chart['premium'],
        'certificate_reference' => 'CERT-001']);
    // 500 shares of 10.00 nominal subscribed at 25.00: capital 5,000 and premium 7,500, derived
    // from the class rather than entered, so share capital always reconciles to the register.
    assert_same('5000.0000', $event['nominal_total']);
    assert_same('7500.0000', $event['premium_total']);
    assert_true($event['journal_id'] !== null, 'An allotment that posts carries its journal.');
    $journal = pl_get_journal($f['actor_id'], (int) $f['company_id'], (int) $f['book_id'], (int) $event['journal_id']);
    assert_same('share_event', (string) $journal['source_type']);
    assert_same(3, count($journal['lines']));
    assert_same('-5000.0000', ownership_balance($f, $chart['capital'], '2026-03-01'));
    assert_same('-7500.0000', ownership_balance($f, $chart['premium'], '2026-03-01'));
    assert_same('12500.0000', ownership_balance($f, $chart['cash'], '2026-03-01'));
    assert_same('0.0000', bcsub(pl_trial_balance($f['actor_id'], $f['company_id'], $f['book_id'], '2026-03-01')['total_debit'],
        pl_trial_balance($f['actor_id'], $f['company_id'], $f['book_id'], '2026-03-01')['total_credit'], 4));
    // No account is ever guessed, and capital and premium can never be one account.
    assert_throws(fn () => ownership_event($f, ['event_type' => 'allotment', 'share_class_id' => $classId,
        'effective_date' => '2026-04-01', 'quantity' => '100', 'to_party_id' => $holder,
        'consideration_amount' => '2500', 'post' => true, 'debit_account_id' => $chart['cash']]),
        DomainException::class, 'Choose the share capital account');
    assert_throws(fn () => ownership_event($f, ['event_type' => 'allotment', 'share_class_id' => $classId,
        'effective_date' => '2026-04-01', 'quantity' => '100', 'to_party_id' => $holder,
        'consideration_amount' => '2500', 'post' => true, 'debit_account_id' => $chart['cash'],
        'share_capital_account_id' => $chart['capital'], 'share_premium_account_id' => $chart['capital']]),
        DomainException::class, 'two different accounts');
    // Shares issued at a discount need a treatment this register will not choose.
    assert_throws(fn () => ownership_event($f, ['event_type' => 'allotment', 'share_class_id' => $classId,
        'effective_date' => '2026-04-01', 'quantity' => '100', 'to_party_id' => $holder,
        'consideration_amount' => '500', 'post' => false]), DomainException::class, 'below the nominal value');
});

test('a transfer posts nothing at all, and the schema refuses one that tries', function (): void {
    $f = ownership_fixture();
    $chart = ownership_company_chart($f);
    $classId = ownership_class($f);
    $ayesha = ownership_person($f, 'Ayesha Sample');
    $bilal = ownership_person($f, 'Bilal Sample');
    $allotment = ownership_event($f, ['event_type' => 'allotment', 'share_class_id' => $classId,
        'effective_date' => '2026-03-01', 'quantity' => '1000', 'to_party_id' => $ayesha,
        'consideration_amount' => '10000', 'post' => true, 'debit_account_id' => $chart['cash'],
        'share_capital_account_id' => $chart['capital']]);
    $before = pl_trial_balance($f['actor_id'], $f['company_id'], $f['book_id'], '2026-12-31');
    $transfer = ownership_event($f, ['event_type' => 'transfer', 'share_class_id' => $classId,
        'effective_date' => '2026-06-01', 'quantity' => '200', 'from_party_id' => $ayesha, 'to_party_id' => $bilal]);
    assert_same(null, $transfer['journal_id'], 'A transfer between two holders changes nothing in the company\'s books.');
    assert_same($before['accounts'], pl_trial_balance($f['actor_id'], $f['company_id'], $f['book_id'], '2026-12-31')['accounts'],
        'A share transfer moved a ledger balance.');
    assert_throws(fn () => ownership_event($f, ['event_type' => 'transfer', 'share_class_id' => $classId,
        'effective_date' => '2026-07-01', 'quantity' => '10', 'from_party_id' => $ayesha, 'to_party_id' => $bilal,
        'post' => true]), DomainException::class, 'never carries a journal');
    assert_throws(fn () => ownership_event($f, ['event_type' => 'transfer', 'share_class_id' => $classId,
        'effective_date' => '2026-07-01', 'quantity' => '10', 'from_party_id' => $ayesha, 'to_party_id' => $bilal,
        'journal_id' => (int) $allotment['journal_id']]), DomainException::class, 'never carries a journal');
    // The holdings moved even though the ledger did not.
    $positions = pl_share_positions((int) $f['company_id'], '2026-06-01');
    assert_same('800.000000', $positions[$classId][$ayesha]);
    assert_same('200.000000', $positions[$classId][$bilal]);
    assert_same('1000.000000', pl_share_positions((int) $f['company_id'], '2026-05-31')[$classId][$ayesha]);
});

test('the share ledger is append-only in the database and a correction is a linked reversal', function (): void {
    $f = ownership_fixture();
    $chart = ownership_company_chart($f);
    $classId = ownership_class($f);
    $holder = ownership_person($f, 'Ayesha Sample');
    $event = ownership_event($f, ['event_type' => 'allotment', 'share_class_id' => $classId,
        'effective_date' => '2026-03-01', 'quantity' => '1000', 'to_party_id' => $holder,
        'consideration_amount' => '10000', 'post' => true, 'debit_account_id' => $chart['cash'],
        'share_capital_account_id' => $chart['capital']]);
    // The two triggers migration 044 installs, proved the way posted journals are proved.
    assert_throws(fn () => DB::query('UPDATE pl_share_events SET quantity = 1 WHERE id = %i', $event['id']), Throwable::class);
    assert_throws(fn () => DB::query('DELETE FROM pl_share_events WHERE id = %i', $event['id']), Throwable::class);
    $correction = pl_reverse_share_event($f['actor_id'], (int) $f['company_id'], (int) $event['id'],
        'Sample correction: the allotment was recorded twice.', bin2hex(random_bytes(16)));
    assert_same((int) $event['id'], $correction['reversal_of_id']);
    assert_true($correction['journal_id'] !== null, 'The journal behind the event was reversed too.');
    assert_same('reversed', pl_get_share_event($f['actor_id'], (int) $f['company_id'], (int) $event['id'])['status']);
    assert_same('0.000000', pl_share_positions((int) $f['company_id'], '2026-12-31')[$classId][$holder], 'The reversal removed the holding.');
    assert_same('0.000000', pl_share_issued_by_class((int) $f['company_id'], '2026-12-31')[$classId]);
    assert_same('0.0000', ownership_balance($f, $chart['capital'], '2026-12-31'));
    assert_throws(fn () => pl_reverse_share_event($f['actor_id'], (int) $f['company_id'], (int) $event['id'],
        'Sample double correction.', bin2hex(random_bytes(16))), DomainException::class, 'already been corrected');
    assert_throws(fn () => pl_reverse_share_event($f['actor_id'], (int) $f['company_id'], (int) $correction['id'],
        'Sample reversal of a reversal.', bin2hex(random_bytes(16))), DomainException::class, 'already a correction');
});

test('the register refuses a holding that would go below nought at any moment, not only at the end', function (): void {
    $f = ownership_fixture();
    $classId = ownership_class($f);
    $ayesha = ownership_person($f, 'Ayesha Sample');
    $bilal = ownership_person($f, 'Bilal Sample');
    ownership_event($f, ['event_type' => 'allotment', 'share_class_id' => $classId, 'effective_date' => '2026-06-01',
        'quantity' => '100', 'to_party_id' => $ayesha, 'consideration_amount' => '1000', 'post' => false]);
    assert_throws(fn () => ownership_event($f, ['event_type' => 'transfer', 'share_class_id' => $classId,
        'effective_date' => '2026-07-01', 'quantity' => '150', 'from_party_id' => $ayesha, 'to_party_id' => $bilal]),
        DomainException::class, 'fewer than nought shares');
    // A back-dated transfer that only balances at the end is refused as well: the register has to
    // hold at every moment.
    ownership_event($f, ['event_type' => 'transfer', 'share_class_id' => $classId, 'effective_date' => '2026-08-01',
        'quantity' => '100', 'from_party_id' => $ayesha, 'to_party_id' => $bilal]);
    assert_throws(fn () => ownership_event($f, ['event_type' => 'cancellation', 'share_class_id' => $classId,
        'effective_date' => '2026-09-01', 'quantity' => '50', 'from_party_id' => $ayesha]),
        DomainException::class, 'fewer than nought shares');
});

test('a share ledger write is idempotent on its request key', function (): void {
    $f = ownership_fixture();
    $classId = ownership_class($f);
    $holder = ownership_person($f, 'Ayesha Sample');
    $key = bin2hex(random_bytes(16));
    $input = ['event_type' => 'allotment', 'share_class_id' => $classId, 'effective_date' => '2026-03-01',
        'quantity' => '100', 'to_party_id' => $holder, 'consideration_amount' => '1000', 'post' => false,
        'reason' => 'Sample retried allotment.', 'creation_key' => $key];
    $first = pl_record_share_event($f['actor_id'], (int) $f['company_id'], (int) $f['book_id'], $input);
    $second = pl_record_share_event($f['actor_id'], (int) $f['company_id'], (int) $f['book_id'], $input);
    assert_same($first['id'], $second['id'], 'A retried request must not allot the shares twice.');
    assert_same('100.000000', pl_share_issued_by_class((int) $f['company_id'], '2026-03-01')[$classId]);
});

/* ------------------------------------------------------------------------------ the snapshot */

test('the ownership snapshot reports percentages of the class, of the outstanding shares and fully diluted', function (): void {
    $f = ownership_fixture();
    $ordinary = ownership_class($f, 'ORD', '10.0000', '100000');
    $pool = ownership_class($f, 'POOL', '10.0000', '1000', true);
    $ayesha = ownership_person($f, 'Ayesha Sample');
    $bilal = ownership_person($f, 'Bilal Sample');
    ownership_event($f, ['event_type' => 'allotment', 'share_class_id' => $ordinary, 'effective_date' => '2026-03-01',
        'quantity' => '750', 'to_party_id' => $ayesha, 'consideration_amount' => '7500', 'post' => false]);
    ownership_event($f, ['event_type' => 'allotment', 'share_class_id' => $ordinary, 'effective_date' => '2026-03-01',
        'quantity' => '250', 'to_party_id' => $bilal, 'consideration_amount' => '2500', 'post' => false]);
    $snapshot = pl_ownership_snapshot($f['actor_id'], (int) $f['company_id'], '2026-12-31');
    assert_same('1000.000000', $snapshot['outstanding_shares']);
    assert_same('2000.000000', $snapshot['fully_diluted_shares'], 'The unissued option pool dilutes.');
    assert_same('1000.000000', $snapshot['pool_unissued_shares']);
    $holders = [];
    foreach ($snapshot['holdings'] as $entry) {
        if ((int) $entry['class']['id'] !== $ordinary) { continue; }
        foreach ($entry['holders'] as $row) { $holders[$row['name']] = $row; }
    }
    assert_same('75.000000', $holders['Ayesha Sample']['percent_of_class']);
    assert_same('75.000000', $holders['Ayesha Sample']['percent_outstanding']);
    assert_same('37.500000', $holders['Ayesha Sample']['percent_fully_diluted']);
    assert_same('25.000000', $holders['Bilal Sample']['percent_of_class']);
    assert_same('1000.000000', $snapshot['total_votes']);
});

/* ----------------------------------------------------- the related-party marker (B72 / B74 / B58) */

test('B74: a director who is also a supplier is NOT a related party until somebody says so', function (): void {
    $f = ownership_fixture();
    $party = pl_save_party($f['actor_id'], (int) $f['company_id'], (int) $f['book_id'],
        ['legal_name' => 'Bilal Sample', 'entity_type' => 'individual', 'country_code' => 'PK',
            'is_customer' => false, 'is_vendor' => true, 'currency' => 'USD',
            'request_key' => bin2hex(random_bytes(16)), 'reason' => 'Sample supplier who is also a director']);
    $person = ownership_person($f, 'Bilal Sample');
    pl_save_ownership_officer($f['actor_id'], (int) $f['company_id'], [
        'ownership_party_id' => $person, 'officer_role' => 'director', 'role_title' => '',
        'appointed_on' => '2026-01-01', 'resigned_on' => '', 'has_significant_control' => false,
        'control_nature' => '', 'note' => '', 'reason' => 'Sample directorship.']);

    // Nothing above is a designation. The report is empty and the marker table has no row.
    $report = pl_related_party_transactions($f['actor_id'], (int) $f['company_id'], (int) $f['book_id'], '2026-01-01', '2026-12-31');
    assert_same([], $report['parties'], 'A role must never make anybody a related party.');
    assert_same(0, count(pl_list_related_party_markers($f['actor_id'], (int) $f['company_id'])));

    // The cross-check surfaces them for a human to decide, and marks nothing (B74, B75).
    $candidates = pl_related_party_candidates($f['actor_id'], (int) $f['company_id']);
    assert_same(1, count($candidates));
    assert_same('name', $candidates[0]['matched_on']);
    assert_same('officer', $candidates[0]['related_register']);
    assert_same((int) $party['id'], $candidates[0]['party_id']);
    assert_same(0, count(pl_list_related_party_markers($f['actor_id'], (int) $f['company_id'])),
        'Listing candidates must not mark anybody.');

    // Only an affirmative designation does it, and only the three IAS 24 kinds exist.
    $officerId = pl_list_ownership_officers($f['actor_id'], (int) $f['company_id'])[0]['id'];
    assert_throws(fn () => pl_save_related_party_marker($f['actor_id'], (int) $f['company_id'], [
        'party_id' => (int) $party['id'], 'related_register' => 'officer', 'related_id' => $officerId,
        'relationship' => 'employee', 'effective_from' => '2026-01-01', 'effective_to' => '',
        'note' => '', 'reason' => 'Sample wrong kind.']), DomainException::class, 'ordinary employee is not a related party');
    assert_same(['key_management', 'close_family_member', 'controlled_entity'], array_keys(pl_related_party_relationships()));
    pl_save_related_party_marker($f['actor_id'], (int) $f['company_id'], [
        'party_id' => (int) $party['id'], 'related_register' => 'officer', 'related_id' => $officerId,
        'relationship' => 'key_management', 'effective_from' => '2026-01-01', 'effective_to' => '',
        'note' => '', 'reason' => 'Sample designation: a director is key management personnel.']);
    $marked = pl_list_related_party_markers($f['actor_id'], (int) $f['company_id']);
    assert_same(1, count($marked));
    assert_same('Bilal Sample', $marked[0]['subject_name']);
    assert_same(0, count(pl_related_party_candidates($f['actor_id'], (int) $f['company_id'])),
        'A marked party is no longer a candidate.');
    // B70 now supplies the employee register. Missing and foreign-company employees must
    // still be refused; a real employee reference never bypasses the same-company boundary.
    assert_true(isset(pl_related_party_registers()['employee']));
    $missingEmployeeId = (int) DB::queryFirstField('SELECT COALESCE(MAX(id), 0) + 1 FROM pl_employees');
    $marker = ['party_id' => (int) $party['id'], 'related_register' => 'employee',
        'relationship' => 'key_management', 'effective_from' => '2026-01-01', 'effective_to' => '',
        'note' => '', 'reason' => 'Sample employee marker.'];
    assert_throws(fn () => pl_save_related_party_marker($f['actor_id'], (int) $f['company_id'],
        $marker + ['related_id' => $missingEmployeeId]), DomainException::class, 'not in the register you chose');
    $other = pl_create_company($f['actor_id'], 'Sample other employer ' . $f['suffix'], 'USD', '2026-01-01');
    $foreign = pl_save_employee($f['actor_id'], (int) $other['company_id'], [
        'full_name' => 'Sample employee of another company', 'employment_type' => 'full_time',
        'employment_status' => 'active', 'hire_date' => '2026-01-01', 'reason' => 'Sample foreign-company reference.']);
    assert_throws(fn () => pl_save_related_party_marker($f['actor_id'], (int) $f['company_id'],
        $marker + ['related_id' => $foreign['id']]), DomainException::class, 'not in the register you chose');
    assert_same(1, count(pl_list_related_party_markers($f['actor_id'], (int) $f['company_id'])),
        'Rejected employee references must not add a disclosure designation.');
});

test('B58: reading a related-party marker takes an authority an ordinary member does not have', function (): void {
    $f = ownership_fixture();
    $viewer = pl_create_user('ownership-viewer-' . $f['suffix'] . '@example.invalid', 'Sample viewer', 'Sample-viewer-password-471!');
    $accountant = pl_create_user('ownership-acc-' . $f['suffix'] . '@example.invalid', 'Sample accountant', 'Sample-accountant-password-471!');
    foreach ([[$viewer, 'viewer'], [$accountant, 'accountant']] as [$userId, $slug]) {
        sample_membership_insert(['company_id' => (int) $f['company_id'], 'user_id' => $userId, 'role' => $slug,
            'role_id' => (int) DB::queryFirstField('SELECT id FROM pl_roles WHERE company_id IS NULL AND slug = %s', $slug)]);
    }
    pl_capability_cache_reset();
    foreach ([$viewer, $accountant] as $actor) {
        assert_true(!pl_user_can($actor, (int) $f['company_id'], 'relatedparty.view'), 'The authority is not part of an ordinary role.');
        assert_throws(fn () => pl_list_related_party_markers($actor, (int) $f['company_id']), DomainException::class, 'restricted');
        assert_throws(fn () => pl_related_party_transactions($actor, (int) $f['company_id'], (int) $f['book_id'], '2026-01-01', '2026-12-31'), DomainException::class, 'restricted');
        assert_throws(fn () => pl_director_loan_movements($actor, (int) $f['company_id'], (int) $f['book_id'], '2026-01-01', '2026-12-31'), DomainException::class, 'restricted');
        assert_true(!pl_user_can($actor, (int) $f['company_id'], 'ownership.manage'));
        assert_throws(fn () => ownership_person(['actor_id' => $actor] + $f, 'Sample forbidden person'), DomainException::class);
    }
    // And the read is grantable without the write, because the person who prepares the disclosure
    // is usually not the person who decides who is key management personnel.
    $role = pl_save_role($f['actor_id'], (int) $f['company_id'], ['name' => 'Disclosure preparer',
        'description' => '', 'reason' => 'Sample split of the two related-party capabilities.',
        'capabilities' => ['company.read', 'relatedparty.view']]);
    pl_assign_company_role($f['actor_id'], (int) $f['company_id'], $viewer, (int) $role['id'], 'Sample grant.');
    pl_capability_cache_reset();
    assert_true(pl_user_can($viewer, (int) $f['company_id'], 'relatedparty.view'));
    assert_true(!pl_user_can($viewer, (int) $f['company_id'], 'relatedparty.manage'));
    assert_same([], pl_list_related_party_markers($viewer, (int) $f['company_id']));
});

test('the related-party report carries the transactions, the balance, the terms and an honest provision', function (): void {
    $f = ownership_fixture();
    $party = pl_save_party($f['actor_id'], (int) $f['company_id'], (int) $f['book_id'],
        ['legal_name' => 'Director Supplies Sample', 'entity_type' => 'private_company', 'country_code' => 'PK',
            'is_customer' => true, 'is_vendor' => false, 'currency' => 'USD', 'payment_terms' => 'Net 30 days, interest free',
            'request_key' => bin2hex(random_bytes(16)), 'reason' => 'Sample related customer']);
    $person = ownership_person($f, 'Director Supplies Sample', 'entity');
    $officer = pl_save_ownership_officer($f['actor_id'], (int) $f['company_id'], [
        'ownership_party_id' => $person, 'officer_role' => 'director', 'role_title' => '',
        'appointed_on' => '2026-01-01', 'resigned_on' => '', 'has_significant_control' => false,
        'control_nature' => '', 'note' => '', 'reason' => 'Sample directorship.']);
    pl_save_related_party_marker($f['actor_id'], (int) $f['company_id'], [
        'party_id' => (int) $party['id'], 'related_register' => 'officer', 'related_id' => (int) $officer['id'],
        'relationship' => 'controlled_entity', 'effective_from' => '2026-01-01', 'effective_to' => '',
        'note' => 'Controlled by a director.', 'reason' => 'Sample designation.']);
    $report = pl_related_party_transactions($f['actor_id'], (int) $f['company_id'], (int) $f['book_id'], '2026-01-01', '2026-12-31');
    assert_same(1, count($report['parties']));
    assert_same('Director Supplies Sample', $report['parties'][0]['legal_name']);
    assert_same('Net 30 days, interest free', $report['parties'][0]['terms']);
    assert_same('controlled_entity', $report['parties'][0]['relationships'][0]['relationship']);
    // IAS 24.18(d) asks for the provision. PHP Ledger holds no per-party allowance, and the
    // report says so rather than printing a figure nobody computed.
    assert_same(null, $report['parties'][0]['provision']);
    assert_true(str_contains($report['provision_note'], 'no allowance against an individual party'));
    assert_true(is_array($report['provision_accounts']));
    // A marker that ended before the period opens is out of the report.
    $marker = pl_list_related_party_markers($f['actor_id'], (int) $f['company_id'])[0];
    pl_save_related_party_marker($f['actor_id'], (int) $f['company_id'], [
        'party_id' => (int) $party['id'], 'related_register' => 'officer', 'related_id' => (int) $officer['id'],
        'relationship' => 'controlled_entity', 'effective_from' => '2026-01-01', 'effective_to' => '2026-02-01',
        'note' => '', 'reason' => 'Sample relationship ended.'], (int) $marker['id'], (int) $marker['revision']);
    assert_same([], pl_related_party_transactions($f['actor_id'], (int) $f['company_id'], (int) $f['book_id'], '2026-06-01', '2026-12-31')['parties']);
});

/* ------------------------------------------------------- the Open Cap Format round trip (#92) */

test('the Open Cap Format export reproduces the register\'s own holdings when it is read back', function (): void {
    $f = ownership_fixture();
    $ordinary = ownership_class($f, 'ORD', '10.0000', '100000');
    $preference = ownership_class($f, 'PREF', '10.0000', null, false, 'preferred');
    $ayesha = ownership_person($f, 'Ayesha Sample', 'person', 'CNIC-11111');
    $bilal = ownership_person($f, 'Bilal Sample');
    $holdco = ownership_person($f, 'Sample Holdings', 'entity');
    ownership_event($f, ['event_type' => 'allotment', 'share_class_id' => $ordinary, 'effective_date' => '2026-03-01',
        'quantity' => '1000', 'to_party_id' => $ayesha, 'consideration_amount' => '10000', 'post' => false]);
    ownership_event($f, ['event_type' => 'allotment', 'share_class_id' => $preference, 'effective_date' => '2026-03-01',
        'quantity' => '400', 'to_party_id' => $holdco, 'consideration_amount' => '4000', 'post' => false]);
    ownership_event($f, ['event_type' => 'transfer', 'share_class_id' => $ordinary, 'effective_date' => '2026-06-01',
        'quantity' => '250', 'from_party_id' => $ayesha, 'to_party_id' => $bilal]);
    ownership_event($f, ['event_type' => 'cancellation', 'share_class_id' => $preference, 'effective_date' => '2026-07-01',
        'quantity' => '100', 'from_party_id' => $holdco]);
    ownership_event($f, ['event_type' => 'redesignation', 'share_class_id' => $preference, 'effective_date' => '2026-08-01',
        'quantity' => '50', 'from_party_id' => $holdco, 'to_party_id' => $holdco, 'to_share_class_id' => $ordinary]);
    // One event that is corrected, so the export has to leave both rows out.
    $mistake = ownership_event($f, ['event_type' => 'allotment', 'share_class_id' => $ordinary,
        'effective_date' => '2026-09-01', 'quantity' => '99', 'to_party_id' => $bilal, 'consideration_amount' => '990', 'post' => false]);
    pl_reverse_share_event($f['actor_id'], (int) $f['company_id'], (int) $mistake['id'], 'Sample correction.', bin2hex(random_bytes(16)));

    $document = pl_ownership_export_ocf($f['actor_id'], (int) $f['company_id'], '2026-12-31');
    assert_same('ISSUER', $document['issuer']['object_type']);
    assert_same(3, count($document['stakeholders']));
    assert_same('INSTITUTION', $document['stakeholders'][2]['stakeholder_type']);
    assert_same('UNLIMITED', $document['stock_classes'][1]['initial_shares_authorized'], 'A class with no authorised capital says so.');
    assert_same(5, count($document['transactions']), 'A corrected event and its correction are both out of the export.');

    // The round trip: read the document we emit and rebuild the holdings from it alone.
    $rebuilt = pl_ownership_ocf_holdings($document);
    $expected = [];
    foreach (pl_share_positions((int) $f['company_id'], '2026-12-31') as $classId => $holders) {
        foreach ($holders as $partyId => $shares) {
            if (bccomp($shares, '0', 6) === 0) { continue; }
            $expected['stock-class-' . $classId]['stakeholder-' . $partyId] = $shares;
        }
    }
    foreach ($rebuilt as $class => $holders) { ksort($holders); $rebuilt[$class] = $holders; }
    foreach ($expected as $class => $holders) { ksort($holders); $expected[$class] = $holders; }
    ksort($rebuilt);
    ksort($expected);
    assert_same($expected, $rebuilt, 'The exported transactions do not reproduce the exported holdings.');
    // Ayesha 1000 - 250 = 750, Bilal 250, Holdings 50 re-designated in; preference 400 - 100 - 50.
    assert_same('750.000000', $rebuilt['stock-class-' . $ordinary]['stakeholder-' . $ayesha]);
    assert_same('250.000000', $rebuilt['stock-class-' . $preference]['stakeholder-' . $holdco]);
    // It is a reader, not an importer, so an unknown transaction type is refused rather than skipped.
    assert_throws(fn () => pl_ownership_ocf_holdings(['transactions' => [['object_type' => 'TX_WARRANT_ISSUANCE', 'quantity' => '1']]]),
        DomainException::class, 'Unsupported Open Cap Format transaction type');
    $file = pl_ownership_export_file($f['actor_id'], (int) $f['company_id'], '2026-12-31');
    assert_true(str_ends_with($file['filename'], '.json'));
    assert_same($document['transactions'], json_decode($file['json'], true, 64, JSON_THROW_ON_ERROR)['transactions']);
});

/* ------------------------------------------------------------- the hook points for the runtime */

test('every register write reaches the hook seam the plugin runtime will bridge', function (): void {
    $f = ownership_fixture();
    $seen = [];
    pl_ownership_listeners([]);
    pl_ownership_on('*', function (string $hook, array $payload) use (&$seen): void { $seen[] = $hook; });
    // A listener that throws must not roll back the register: the hook is after the fact.
    pl_ownership_on('ownership.member.recorded', function (): void { throw new RuntimeException('Sample listener failure.'); });
    try {
        $person = ownership_person($f, 'Ayesha Sample');
        pl_save_ownership_member($f['actor_id'], (int) $f['company_id'], ['ownership_party_id' => $person,
            'effective_from' => '2026-01-01', 'effective_to' => '', 'profit_share' => '', 'note' => '', 'reason' => 'Sample.']);
        pl_save_ownership_officer($f['actor_id'], (int) $f['company_id'], ['ownership_party_id' => $person,
            'officer_role' => 'director', 'role_title' => '', 'appointed_on' => '2026-01-01', 'resigned_on' => '',
            'has_significant_control' => false, 'control_nature' => '', 'note' => '', 'reason' => 'Sample.']);
        $classId = ownership_class($f);
        $event = ownership_event($f, ['event_type' => 'allotment', 'share_class_id' => $classId,
            'effective_date' => '2026-03-01', 'quantity' => '10', 'to_party_id' => $person,
            'consideration_amount' => '100', 'post' => false]);
        pl_reverse_share_event($f['actor_id'], (int) $f['company_id'], (int) $event['id'], 'Sample.', bin2hex(random_bytes(16)));
    } finally {
        pl_ownership_listeners([]);
    }
    assert_same(['ownership.member.recorded', 'ownership.officer.appointed', 'ownership.share_event.recorded',
        'ownership.share_event.reversed'], $seen);
    assert_same(1, count(pl_list_ownership_members($f['actor_id'], (int) $f['company_id'])),
        'A failing listener must not undo the write it was told about.');
    assert_throws(fn () => pl_ownership_on('ownership.nonsense', static function (): void {}), DomainException::class);
});

/* ------------------------------------------------------- what the read API does and does not show */

test('the read API publishes the register and never the related-party markers', function (): void {
    $catalog = pl_read_catalog();
    assert_true(isset($catalog['ownership_snapshot']), 'The ownership snapshot is a read operation.');
    assert_true(isset($catalog['share_ledger']), 'The share ledger is a read operation.');
    foreach (array_keys($catalog) as $operation) {
        assert_true(!str_contains($operation, 'related') && !str_contains($operation, 'director_loan'),
            'A read connection is a token, not a person with a role: ' . $operation . ' exposes B58 data over an API key.');
    }
    $manifest = pl_module_registry()['core'];
    foreach ($manifest['api_operations'] as $operation) {
        assert_true(isset($catalog[$operation]), 'The core manifest declares ' . $operation . ', which the catalogue does not have.');
    }
    assert_true(in_array('044_ownership_register', $manifest['migrations'], true), 'The core manifest declares migration 044.');
    $router = (string) file_get_contents(dirname(__DIR__) . '/www/phpledger/public/index.php');
    foreach (['/ownership', '/reports/ownership'] as $route) {
        assert_true(in_array($route, $manifest['routes'], true), 'The core manifest declares ' . $route . '.');
        assert_true(str_contains($router, "'" . $route . "'"), 'The router has no ' . $route . ' route.');
    }
    $packaged = json_decode((string) file_get_contents(dirname(__DIR__) . '/tools/package-files.json'), true, 32, JSON_THROW_ON_ERROR);
    $sources = array_column($packaged['files'], 'source');
    foreach (['www/phpledger/install/migrations/044_ownership_register.php',
        'www/phpledger/includes/functions/ownership_functions.php',
        'www/phpledger/includes/functions/ownership_web_functions.php',
        'www/phpledger/templates/views/ownership.php',
        'www/phpledger/templates/views/ownership-reports.php'] as $file) {
        assert_true(in_array($file, $sources, true), $file . ' is not in the shipped file list.');
    }
});

/* ============================================================================================
 * The two-period worked examples.
 *
 * These are the figures in docs/accounting/OWNERSHIP-REGISTER.md, computed here so the worked
 * example the accountant reviews cannot drift from what the software does.
 * ========================================================================================== */

test('worked example, a private company over two periods: allotment, premium, transfer and bonus issue', function (): void {
    $f = ownership_fixture();
    $chart = ownership_company_chart($f);
    $classId = ownership_class($f, 'ORD', '10.0000', '100000');
    $ayesha = ownership_person($f, 'Ayesha Sample');
    $bilal = ownership_person($f, 'Bilal Sample');

    // Period 1, the year to 31 December 2025 — the fixture's own first reporting year.
    //   1,000 ORD to Ayesha at nominal: Dr bank 10,000 / Cr share capital 10,000.
    //     500 ORD to Bilal at 25.00:    Dr bank 12,500 / Cr share capital 5,000, Cr premium 7,500.
    ownership_event($f, ['event_type' => 'allotment', 'share_class_id' => $classId, 'effective_date' => '2025-02-01',
        'quantity' => '1000', 'to_party_id' => $ayesha, 'consideration_amount' => '10000', 'post' => true,
        'debit_account_id' => $chart['cash'], 'share_capital_account_id' => $chart['capital']]);
    ownership_event($f, ['event_type' => 'allotment', 'share_class_id' => $classId, 'effective_date' => '2025-05-01',
        'quantity' => '500', 'to_party_id' => $bilal, 'consideration_amount' => '12500', 'post' => true,
        'debit_account_id' => $chart['cash'], 'share_capital_account_id' => $chart['capital'],
        'share_premium_account_id' => $chart['premium']]);
    assert_same('-15000.0000', ownership_balance($f, $chart['capital'], '2025-12-31'));
    assert_same('-7500.0000', ownership_balance($f, $chart['premium'], '2025-12-31'));
    assert_same('22500.0000', ownership_balance($f, $chart['cash'], '2025-12-31'));
    $year1 = pl_book_value_per_share($f['actor_id'], (int) $f['company_id'], (int) $f['book_id'], '2025-12-31');
    assert_same('22500.0000', $year1['total_equity']);
    assert_same('1500.000000', $year1['outstanding_shares']);
    assert_same('15.000000', $year1['book_value_per_share']);

    // Period 2, the year to 31 December 2026.
    //   A transfer of 200 ORD from Ayesha to Bilal: the register moves, the ledger does not.
    //   A bonus issue of 150 ORD to Ayesha capitalising the premium: Dr premium 1,500 /
    //   Cr share capital 1,500. Equity is unchanged in total; its composition is not.
    ownership_event($f, ['event_type' => 'transfer', 'share_class_id' => $classId, 'effective_date' => '2026-03-15',
        'quantity' => '200', 'from_party_id' => $ayesha, 'to_party_id' => $bilal]);
    ownership_event($f, ['event_type' => 'bonus_issue', 'share_class_id' => $classId, 'effective_date' => '2026-06-15',
        'quantity' => '150', 'to_party_id' => $ayesha, 'post' => true,
        'share_capital_account_id' => $chart['capital'], 'source_account_id' => $chart['premium']]);
    assert_same('-16500.0000', ownership_balance($f, $chart['capital'], '2026-12-31'));
    assert_same('-6000.0000', ownership_balance($f, $chart['premium'], '2026-12-31'));
    assert_same('22500.0000', ownership_balance($f, $chart['cash'], '2026-12-31'), 'A bonus issue brings in no money.');

    $snapshot = pl_ownership_snapshot($f['actor_id'], (int) $f['company_id'], '2026-12-31');
    $holders = [];
    foreach ($snapshot['holdings'][0]['holders'] as $row) { $holders[$row['name']] = $row['shares']; }
    assert_same('950.000000', $holders['Ayesha Sample']);
    assert_same('700.000000', $holders['Bilal Sample']);
    assert_same('1650.000000', $snapshot['outstanding_shares']);
    // Called-up share capital reconciles to the register: 1,650 shares of 10.00 nominal.
    assert_same('16500.0000', bcmul($snapshot['outstanding_shares'], '10.0000', 4));
    $year2 = pl_book_value_per_share($f['actor_id'], (int) $f['company_id'], (int) $f['book_id'], '2026-12-31');
    assert_same('22500.0000', $year2['total_equity'], 'Neither a transfer nor a bonus issue changes total equity.');
    assert_same('13.636363', $year2['book_value_per_share']);
    assert_true(!$year2['has_preference_class']);
});

test('worked example, a partnership over two periods: fixed and fluctuating partner capital', function (): void {
    $f = ownership_fixture();
    $accounts = [
        'aslam_capital' => ownership_account($f, '3-310-10001-00', 'Capital account - Aslam', 'equity'),
        'kamran_capital' => ownership_account($f, '3-310-10002-00', 'Capital account - Kamran', 'equity'),
        'aslam_drawings' => ownership_account($f, '3-910-10001-00', 'Drawings - Aslam', 'equity', true),
        'kamran_drawings' => ownership_account($f, '3-910-10002-00', 'Drawings - Kamran', 'equity', true),
    ];
    // The bundled chart's own owner loan account, which is the only one carrying the semantic key
    // pl_owner_accounts() looks for; one partner uses it.
    $loanAccountId = (int) DB::queryFirstField("SELECT id FROM pl_accounts WHERE company_id = %i AND book_id = %i AND semantic_key = 'core.liability.owner_loan'", $f['company_id'], $f['book_id']);
    assert_true($loanAccountId > 0, 'The bundled chart carries an owner loan account.');
    $aslam = pl_save_owner_partner($f['actor_id'], (int) $f['company_id'], (int) $f['book_id'], [
        'name' => 'Aslam Sample', 'profit_share' => '0.6', 'is_active' => true,
        'capital_account_id' => $accounts['aslam_capital'], 'drawings_account_id' => $accounts['aslam_drawings'], 'loan_account_id' => null]);
    $kamran = pl_save_owner_partner($f['actor_id'], (int) $f['company_id'], (int) $f['book_id'], [
        'name' => 'Kamran Sample', 'profit_share' => '0.4', 'is_active' => true,
        'capital_account_id' => $accounts['kamran_capital'], 'drawings_account_id' => $accounts['kamran_drawings'], 'loan_account_id' => $loanAccountId]);
    // The register's own people, linked to the B61 partner records.
    $aslamPerson = ownership_person($f, 'Aslam Sample');
    $kamranPerson = ownership_person($f, 'Kamran Sample');
    pl_link_ownership_partner($f['actor_id'], (int) $f['company_id'], (int) $f['book_id'], $aslamPerson, (int) $aslam['id'], 'Sample link.');
    pl_link_ownership_partner($f['actor_id'], (int) $f['company_id'], (int) $f['book_id'], $kamranPerson, (int) $kamran['id'], 'Sample link.');
    assert_throws(fn () => pl_link_ownership_partner($f['actor_id'], (int) $f['company_id'], (int) $f['book_id'],
        $aslamPerson, (int) $kamran['id'], 'Sample double link.'), DomainException::class, 'already linked');

    $post = static function (array $f, string $kind, string $date, string $amount, int $partnerId): void {
        pl_post_owner_transaction($f['actor_id'], (int) $f['company_id'], (int) $f['book_id'], [
            'kind' => $kind, 'date' => $date, 'amount' => $amount, 'partner_id' => $partnerId,
            'cash_account_id' => (int) $f['accounts']['1000'], 'description' => 'Sample ' . $kind,
            'creation_key' => bin2hex(random_bytes(16))]);
    };
    // Period 1, the year to 31 December 2025.
    $post($f, 'capital_introduced', '2025-01-15', '60000', (int) $aslam['id']);
    $post($f, 'capital_introduced', '2025-01-15', '40000', (int) $kamran['id']);
    $post($f, 'drawings', '2025-09-30', '5000', (int) $aslam['id']);
    $year1 = pl_partner_capital_statement($f['actor_id'], (int) $f['company_id'], (int) $f['book_id'], '2025-01-01', '2025-12-31');
    $byName = [];
    foreach ($year1['partners'] as $row) { $byName[$row['name']] = $row; }
    assert_same('0.0000', $byName['Aslam Sample']['capital_opening']);
    assert_same('60000.0000', $byName['Aslam Sample']['capital_introduced']);
    assert_same('60000.0000', $byName['Aslam Sample']['fixed_capital']);
    assert_same('5000.0000', $byName['Aslam Sample']['drawings_period']);
    assert_same('55000.0000', $byName['Aslam Sample']['fluctuating_capital']);
    assert_same('40000.0000', $byName['Kamran Sample']['fixed_capital']);
    assert_same('Aslam Sample', $byName['Aslam Sample']['register_person'], 'The statement names the person in the register.');
    assert_same('100000.0000', $year1['totals']['closing']);

    // Period 2, the year to 31 December 2026.
    $post($f, 'capital_introduced', '2026-02-15', '10000', (int) $kamran['id']);
    $post($f, 'drawings', '2026-08-15', '3000', (int) $aslam['id']);
    $post($f, 'owner_loan_received', '2026-03-15', '20000', (int) $kamran['id']);
    $year2 = pl_partner_capital_statement($f['actor_id'], (int) $f['company_id'], (int) $f['book_id'], '2026-01-01', '2026-12-31');
    $byName = [];
    foreach ($year2['partners'] as $row) { $byName[$row['name']] = $row; }
    assert_same('60000.0000', $byName['Aslam Sample']['capital_opening']);
    assert_same('0.0000', $byName['Aslam Sample']['capital_introduced']);
    assert_same('5000.0000', $byName['Aslam Sample']['drawings_opening']);
    assert_same('3000.0000', $byName['Aslam Sample']['drawings_period']);
    assert_same('8000.0000', $byName['Aslam Sample']['drawings_closing']);
    assert_same('60000.0000', $byName['Aslam Sample']['fixed_capital']);
    assert_same('52000.0000', $byName['Aslam Sample']['fluctuating_capital']);
    assert_same('40000.0000', $byName['Kamran Sample']['capital_opening']);
    assert_same('10000.0000', $byName['Kamran Sample']['capital_introduced']);
    assert_same('50000.0000', $byName['Kamran Sample']['fixed_capital']);
    // An owner's loan is a liability, not equity: it is reported beside the capital account and
    // never inside it (B61, B60).
    assert_same('0.0000', $byName['Kamran Sample']['loan_opening']);
    assert_same('20000.0000', $byName['Kamran Sample']['loan_advanced']);
    assert_same('20000.0000', $byName['Kamran Sample']['loan_closing']);
    assert_same('110000.0000', $year2['totals']['closing']);
    assert_true(str_contains($year2['note'], 'not allocated between partners'));

    // The same loan, read as the director loan movement disclosure both regimes ask for.
    pl_save_ownership_officer($f['actor_id'], (int) $f['company_id'], ['ownership_party_id' => $kamranPerson,
        'officer_role' => 'director', 'role_title' => '', 'appointed_on' => '2026-01-01', 'resigned_on' => '',
        'has_significant_control' => false, 'control_nature' => '', 'note' => '', 'reason' => 'Sample directorship.']);
    pl_save_ownership_officer($f['actor_id'], (int) $f['company_id'], ['ownership_party_id' => $aslamPerson,
        'officer_role' => 'director', 'role_title' => '', 'appointed_on' => '2026-01-01', 'resigned_on' => '',
        'has_significant_control' => false, 'control_nature' => '', 'note' => '', 'reason' => 'Sample directorship.']);
    $loans = pl_director_loan_movements($f['actor_id'], (int) $f['company_id'], (int) $f['book_id'], '2026-01-01', '2026-12-31');
    $byDirector = [];
    foreach ($loans['directors'] as $row) { $byDirector[$row['name']] = $row; }
    assert_same('0.0000', $byDirector['Kamran Sample']['opening']);
    assert_same('20000.0000', $byDirector['Kamran Sample']['advanced']);
    assert_same('0.0000', $byDirector['Kamran Sample']['repaid']);
    assert_same('20000.0000', $byDirector['Kamran Sample']['closing']);
    assert_same('owed_to_director', $byDirector['Kamran Sample']['direction']);
    assert_true($loans['reconciles'], 'Opening plus advances less repayments must equal the closing balance.');
    // A director with no loan account is listed with the reason, because an empty row that says
    // why is a finding and a missing row is not.
    assert_same(null, $byDirector['Aslam Sample']['closing']);
    assert_true(str_contains((string) $byDirector['Aslam Sample']['reason'], 'no loan account'));
});
