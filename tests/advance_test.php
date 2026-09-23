<?php
declare(strict_types=1);

/**
 * Advances, refunds, orphan credit notes and batch receipts — 1.2 M5 (B39, B53, B57, B59).
 *
 * The worked journals these tests pin are written out for the accountant in
 * docs/accounting/ADVANCES-AND-REFUNDS.md.
 */

function advance_fixture(bool $payable = false, bool $foreign = false): array
{
    $f = open_item_fixture($payable);
    $items = [];
    // The second item is recognised first, so the oldest-first planner reaches it first:
    // the internal open-item ledger dates an item by its recognition, and an id that is
    // higher but a date that is earlier is exactly the case a naive plan would get wrong.
    foreach ([['10', '2026-01-20'], ['10', '2026-01-10']] as [$amount, $date]) {
        $input = open_item_recognition_input($f, $amount, $foreign ? '280' : '1');
        if (!$foreign) { $input['currency'] = 'PKR'; }
        $input['date'] = $date;
        $items[] = pl_open_item_recognize($f['actor_id'], $f['company_id'], $f['book_id'], $input)['item_id'];
    }
    return $f + ['items' => $items, 'foreign' => $foreign,
        'advance_account_id' => $f['accounts'][$payable ? '1-120-10001-00' : '2-120-10001-00'],
        'side' => $payable ? 'supplier' : 'customer'];
}

function advance_receipt_input(array $f, string $amount, array $allocations = []): array
{
    return ['party_id' => $f['party_id'], 'direction' => $f['payable'] ? 'payable' : 'receivable',
        'date' => '2026-03-01', 'bank_account_id' => $f['accounts']['1000'], 'amount_fc' => $amount,
        'currency' => $f['foreign'] ? 'USD' : 'PKR', 'description' => 'Sample on-account receipt',
        'advance_account_id' => $f['advance_account_id'], 'idempotency_key' => bin2hex(random_bytes(16)),
        'actual_rate' => $f['foreign'] ? '282' : null, 'allocations' => $allocations,
        // A foreign receipt allocated at a historic rate realises a difference; the accounts
        // are offered so the fixture exercises the whole plan, not only its domestic half.
        'gain_account_id' => $f['accounts']['4000'], 'loss_account_id' => $f['accounts']['5000']];
}

test('the oldest-first planner is pure, fills by due date then id, and leaves the rest unapplied', function (): void {
    $items = [
        ['item_id' => 7, 'due_date' => '2026-02-01', 'remaining_fc' => '100.0000', 'latest_activity_date' => '2026-01-05'],
        ['item_id' => 3, 'due_date' => '2026-01-10', 'remaining_fc' => '40.0000', 'latest_activity_date' => '2026-01-05'],
        ['item_id' => 5, 'due_date' => '2026-01-10', 'remaining_fc' => '25.0000', 'latest_activity_date' => '2026-01-05'],
        ['item_id' => 9, 'due_date' => '2026-01-12', 'remaining_fc' => '30.0000', 'latest_activity_date' => '2026-04-01'],
        ['item_id' => 11, 'due_date' => '2026-01-15', 'remaining_fc' => '0.0000', 'latest_activity_date' => '2026-01-05'],
    ];
    $plan = pl_oldest_first_allocation($items, '90', '2026-03-01');
    // 3 and 5 share a due date, so the id breaks the tie; 9 has later activity and is skipped;
    // 11 is already settled; 7 takes the exact residual.
    assert_same([['item_id' => 3, 'amount_fc' => '40.0000'], ['item_id' => 5, 'amount_fc' => '25.0000'], ['item_id' => 7, 'amount_fc' => '25.0000']], $plan['allocations']);
    assert_same('0.0000', $plan['remainder_fc']);
    assert_same([['item_id' => 9, 'reason' => 'Later activity on 2026-04-01 than this payment.']], $plan['skipped']);
    assert_same(false, $plan['limited']);
    // The same inputs always give the same plan; nothing is read or written.
    assert_same($plan, pl_oldest_first_allocation(array_reverse($items), '90', '2026-03-01'));
    $over = pl_oldest_first_allocation($items, '500', '2026-03-01');
    assert_same('335.0000', $over['remainder_fc']);
    assert_same(3, count($over['allocations']));
    $capped = pl_oldest_first_allocation($items, '500', '2026-03-01', 1);
    assert_same(true, $capped['limited']);
    assert_same('460.0000', $capped['remainder_fc']);
});

test('the planner action reads a party and still goes through preview and confirm', function (): void {
    $f = advance_fixture();
    $plan = pl_plan_settlement_allocation($f['actor_id'], $f['company_id'], $f['book_id'], 'receivable', $f['party_id'], 'PKR', '25', '2026-03-01');
    // The second item is due first, so it is filled first, and the first takes the residual.
    assert_same([['item_id' => $f['items'][1], 'amount_fc' => '10.0000'], ['item_id' => $f['items'][0], 'amount_fc' => '10.0000']], $plan['allocations']);
    assert_same('5.0000', $plan['remainder_fc']);
    $input = advance_receipt_input($f, '25', $plan['allocations']);
    $preview = pl_preview_settlement($f['actor_id'], $f['company_id'], $f['book_id'], $input);
    assert_same('5.0000', $preview['remainder_fc']);
    assert_same(2, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id=%i', $f['book_id']));
    $hash = pl_settlement_review_hash($input, $preview);
    $posted = pl_confirm_settlement($f['actor_id'], $f['company_id'], $f['book_id'], $input, $hash);
    assert_same($posted, pl_confirm_settlement($f['actor_id'], $f['company_id'], $f['book_id'], $input, $hash));
    assert_same('5.0000', $posted['remainder_fc']);
    foreach ($f['items'] as $id) { assert_same('0.0000', pl_get_open_item($f['actor_id'], $f['company_id'], $f['book_id'], $id)['remaining_fc']); }
    // Decision 21: the plan is a proposal. A manual split of the same money is posted through
    // the same preview and confirmation, and the stored fact is still "this amount to that item".
    $manual = advance_fixture();
    $override = advance_receipt_input($manual, '25', [['item_id' => $manual['items'][0], 'amount_fc' => '10'], ['item_id' => $manual['items'][1], 'amount_fc' => '3']]);
    $manualPlan = pl_preview_settlement($manual['actor_id'], $manual['company_id'], $manual['book_id'], $override);
    assert_same('12.0000', $manualPlan['remainder_fc']);
    pl_confirm_settlement($manual['actor_id'], $manual['company_id'], $manual['book_id'], $override, pl_settlement_review_hash($override, $manualPlan));
    assert_same('7.0000', pl_get_open_item($manual['actor_id'], $manual['company_id'], $manual['book_id'], $manual['items'][1])['remaining_fc']);
});

test('a receipt with a remainder posts one journal with one bank line and one advance item', function (): void {
    $f = advance_fixture();
    $input = advance_receipt_input($f, '25', [['item_id' => $f['items'][0], 'amount_fc' => '10'], ['item_id' => $f['items'][1], 'amount_fc' => '10']]);
    $posted = pl_settle_open_items($f['actor_id'], $f['company_id'], $f['book_id'], $input);
    $journal = pl_get_journal($f['actor_id'], $f['company_id'], $f['book_id'], $posted['journal_id']);
    assert_same(4, count($journal['lines']));
    assert_same(1, count(array_filter($journal['lines'], static fn (array $l): bool => (int) $l['account_id'] === $f['accounts']['1000'])));
    $advanceLines = array_values(array_filter($journal['lines'], static fn (array $l): bool => (int) $l['account_id'] === $f['advance_account_id']));
    assert_same(1, count($advanceLines));
    // Dr bank 25, Cr receivables 10 + 10, Cr customer advances 5. Nothing is left in suspense.
    $bank = array_values(array_filter($journal['lines'], static fn (array $l): bool => (int) $l['account_id'] === $f['accounts']['1000']));
    assert_same('25.0000', $bank[0]['debit']);
    assert_same('5.0000', $advanceLines[0]['credit']);
    $advance = pl_get_open_item($f['actor_id'], $f['company_id'], $f['book_id'], $posted['advance']['item_id']);
    assert_same('advance', $advance['nature']);
    assert_same('payable', $advance['direction']);
    assert_same('5.0000', $advance['remaining_fc']);
    assert_true(pl_trial_balance($f['actor_id'], $f['company_id'], $f['book_id'])['balanced']);
    // The receipt a customer is handed says what was applied and what is held on account.
    $receipt = pl_settlement_receipt($f['actor_id'], $f['company_id'], $f['book_id'], $posted['journal_id']);
    assert_same('20.0000', $receipt['allocated_fc']);
    assert_same('5.0000', $receipt['remainder_fc']);
});

test('a receipt entirely on account still posts one voucher and names its currency', function (): void {
    $f = advance_fixture();
    $input = advance_receipt_input($f, '15');
    $posted = pl_settle_open_items($f['actor_id'], $f['company_id'], $f['book_id'], $input);
    $journal = pl_get_journal($f['actor_id'], $f['company_id'], $f['book_id'], $posted['journal_id']);
    assert_same(2, count($journal['lines']));
    assert_same('15.0000', $posted['remainder_fc']);
    assert_same('15.0000', pl_settlement_receipt($f['actor_id'], $f['company_id'], $f['book_id'], $posted['journal_id'])['remainder_fc']);
    $blind = $input; $blind['currency'] = ''; $blind['idempotency_key'] = bin2hex(random_bytes(16));
    assert_throws(fn () => pl_settle_open_items($f['actor_id'], $f['company_id'], $f['book_id'], $blind), DomainException::class, 'currency');
    $over = advance_receipt_input($f, '15', [['item_id' => $f['items'][0], 'amount_fc' => '16']]);
    assert_throws(fn () => pl_settle_open_items($f['actor_id'], $f['company_id'], $f['book_id'], $over), DomainException::class, 'cannot exceed the payment');
    $nothing = advance_receipt_input($f, '15'); $nothing['advance_account_id'] = null; $nothing['idempotency_key'] = bin2hex(random_bytes(16));
    assert_throws(fn () => pl_settle_open_items($f['actor_id'], $f['company_id'], $f['book_id'], $nothing), DomainException::class, 'advances control');
});

test('unapplied credit applies to invoices with no bank line, domestic and foreign', function (): void {
    foreach ([false, true] as $foreign) {
        $f = advance_fixture(false, $foreign);
        $receipt = pl_settle_open_items($f['actor_id'], $f['company_id'], $f['book_id'], advance_receipt_input($f, '15', [['item_id' => $f['items'][1], 'amount_fc' => '10']]));
        $apply = ['advance_item_id' => $receipt['advance']['item_id'], 'date' => '2026-03-05', 'description' => 'Sample credit application',
            'gain_account_id' => $f['accounts']['4000'], 'loss_account_id' => $f['accounts']['5000'],
            'idempotency_key' => bin2hex(random_bytes(16)), 'allocations' => [['item_id' => $f['items'][0], 'amount_fc' => '5']]];
        $applied = pl_apply_unapplied_credit($f['actor_id'], $f['company_id'], $f['book_id'], $apply);
        $journal = pl_get_journal($f['actor_id'], $f['company_id'], $f['book_id'], $applied['journal_id']);
        assert_same(0, count(array_filter($journal['lines'], static fn (array $l): bool => (int) $l['account_id'] === $f['accounts']['1000'])));
        // Dr customer advances 5, Cr receivables 5. The advance was taken at the receipt's
        // actual rate and the invoice at its own spot rate, so a foreign pair realises FX.
        assert_same($foreign ? 3 : 2, count($journal['lines']));
        assert_same('5.0000', $applied['applied_fc']);
        assert_same('0.0000', pl_get_open_item($f['actor_id'], $f['company_id'], $f['book_id'], $receipt['advance']['item_id'])['remaining_fc']);
        assert_same('5.0000', pl_get_open_item($f['actor_id'], $f['company_id'], $f['book_id'], $f['items'][0])['remaining_fc']);
        assert_true(pl_trial_balance($f['actor_id'], $f['company_id'], $f['book_id'])['balanced']);
        assert_same($applied, pl_apply_unapplied_credit($f['actor_id'], $f['company_id'], $f['book_id'], $apply));
    }
});

test('an application refuses another party, another currency, a bank line and an invoice as the credit', function (): void {
    $f = advance_fixture(); $other = advance_fixture();
    $receipt = pl_settle_open_items($f['actor_id'], $f['company_id'], $f['book_id'], advance_receipt_input($f, '15'));
    $base = ['advance_item_id' => $receipt['advance']['item_id'], 'date' => '2026-03-05', 'description' => 'Sample application',
        'idempotency_key' => bin2hex(random_bytes(16)), 'allocations' => [['item_id' => $f['items'][0], 'amount_fc' => '5']]];
    foreach ([
        array_replace($base, ['allocations' => [['item_id' => $other['items'][0], 'amount_fc' => '5']]]),
        array_replace($base, ['advance_item_id' => $f['items'][0]]),
        array_replace($base, ['allocations' => [['item_id' => $f['items'][0], 'amount_fc' => '16']]]),
        array_replace($base, ['allocations' => []]),
        array_replace($base, ['date' => '2026-01-01']),
    ] as $invalid) {
        $invalid['idempotency_key'] = bin2hex(random_bytes(16));
        assert_throws(fn () => pl_apply_unapplied_credit($f['actor_id'], $f['company_id'], $f['book_id'], $invalid), DomainException::class);
    }
    assert_same('15.0000', pl_get_open_item($f['actor_id'], $f['company_id'], $f['book_id'], $receipt['advance']['item_id'])['remaining_fc']);
    // The advance may not be allocated as if it were a document either.
    $forged = advance_receipt_input($f, '5', [['item_id' => $receipt['advance']['item_id'], 'amount_fc' => '5']]);
    assert_throws(fn () => pl_settle_open_items($f['actor_id'], $f['company_id'], $f['book_id'], $forged), DomainException::class);
});

test('B7 reversal rules hold: the receipt locks once applied, the application reverses alone, the advance never returns', function (): void {
    $f = advance_fixture();
    $receipt = pl_settle_open_items($f['actor_id'], $f['company_id'], $f['book_id'], advance_receipt_input($f, '15', [['item_id' => $f['items'][1], 'amount_fc' => '10']]));
    $applied = pl_apply_unapplied_credit($f['actor_id'], $f['company_id'], $f['book_id'], ['advance_item_id' => $receipt['advance']['item_id'],
        'date' => '2026-03-05', 'description' => 'Sample application', 'idempotency_key' => bin2hex(random_bytes(16)),
        'allocations' => [['item_id' => $f['items'][0], 'amount_fc' => '5']]]);
    // 1. The whole receipt can no longer be reversed: part of its remainder is spent.
    assert_throws(fn () => pl_reverse_journal($f['actor_id'], $f['company_id'], $f['book_id'], $receipt['journal_id'], gmdate('Y-m-d'), bin2hex(random_bytes(16)), 'Whole receipt'), DomainException::class, 'allocations');
    // 2. The application reverses on its own and restores both sides exactly.
    pl_reverse_journal($f['actor_id'], $f['company_id'], $f['book_id'], $applied['journal_id'], gmdate('Y-m-d'), bin2hex(random_bytes(16)), 'Applied to the wrong invoice');
    assert_same('5.0000', pl_get_open_item($f['actor_id'], $f['company_id'], $f['book_id'], $receipt['advance']['item_id'])['remaining_fc']);
    assert_same('10.0000', pl_get_open_item($f['actor_id'], $f['company_id'], $f['book_id'], $f['items'][0])['remaining_fc']);
    // 3. Now the receipt reverses, and the reversed advance is never usable again.
    pl_reverse_journal($f['actor_id'], $f['company_id'], $f['book_id'], $receipt['journal_id'], gmdate('Y-m-d'), bin2hex(random_bytes(16)), 'Receipt recorded in error');
    $advance = pl_get_open_item($f['actor_id'], $f['company_id'], $f['book_id'], $receipt['advance']['item_id']);
    assert_same(true, $advance['reversed']);
    assert_throws(fn () => pl_apply_unapplied_credit($f['actor_id'], $f['company_id'], $f['book_id'], ['advance_item_id' => $receipt['advance']['item_id'],
        'date' => gmdate('Y-m-d'), 'description' => 'Sample application', 'idempotency_key' => bin2hex(random_bytes(16)),
        'allocations' => [['item_id' => $f['items'][0], 'amount_fc' => '5']]]), DomainException::class, 'reversed');
    assert_throws(fn () => pl_refund_unapplied_credit($f['actor_id'], $f['company_id'], $f['book_id'], ['item_id' => $receipt['advance']['item_id'],
        'bank_account_id' => $f['accounts']['1000'], 'amount_fc' => '5', 'date' => gmdate('Y-m-d'), 'description' => 'Refund a reversed advance',
        'idempotency_key' => bin2hex(random_bytes(16))]), DomainException::class, 'reversed');
    assert_true(pl_trial_balance($f['actor_id'], $f['company_id'], $f['book_id'])['balanced']);
});

test('unapplied credit is refunded from its own control and never from an invoice', function (): void {
    $f = advance_fixture();
    $receipt = pl_settle_open_items($f['actor_id'], $f['company_id'], $f['book_id'], advance_receipt_input($f, '15'));
    $refund = ['item_id' => $receipt['advance']['item_id'], 'bank_account_id' => $f['accounts']['1000'], 'amount_fc' => '9',
        'date' => '2026-03-10', 'description' => 'Sample cash refund of unapplied credit', 'idempotency_key' => bin2hex(random_bytes(16))];
    $posted = pl_refund_unapplied_credit($f['actor_id'], $f['company_id'], $f['book_id'], $refund);
    assert_same('customer', $posted['refund_side']);
    $journal = pl_get_journal($f['actor_id'], $f['company_id'], $f['book_id'], $posted['journal_id']);
    // Dr customer advances 9, Cr bank 9. The obligation is discharged in cash.
    assert_same(2, count($journal['lines']));
    assert_same('9.0000', $journal['lines'][0]['debit']);
    assert_same('9.0000', $journal['lines'][1]['credit']);
    assert_same((int) $journal['lines'][0]['account_id'], $f['advance_account_id']);
    assert_same('6.0000', pl_get_open_item($f['actor_id'], $f['company_id'], $f['book_id'], $receipt['advance']['item_id'])['remaining_fc']);
    assert_same($posted, pl_refund_unapplied_credit($f['actor_id'], $f['company_id'], $f['book_id'], $refund));
    $wrong = array_replace($refund, ['item_id' => $f['items'][0], 'idempotency_key' => bin2hex(random_bytes(16))]);
    assert_throws(fn () => pl_refund_unapplied_credit($f['actor_id'], $f['company_id'], $f['book_id'], $wrong), DomainException::class, 'Only unapplied credit');
    assert_true(pl_trial_balance($f['actor_id'], $f['company_id'], $f['book_id'])['balanced']);
});

test('a credit note with no original invoice becomes unapplied credit against sales returns', function (): void {
    $f = advance_fixture();
    $returns = (int) DB::queryFirstField('SELECT id FROM pl_accounts WHERE book_id=%i AND semantic_key=%s', $f['book_id'], 'core.income.sales_returns');
    $input = ['side' => 'customer', 'party_id' => $f['party_id'], 'offset_account_id' => $returns, 'currency' => 'PKR',
        'amount_fc' => '12', 'date' => '2026-03-02', 'source_reference' => 'Goodwill credit, no invoice',
        'description' => 'Sample orphan credit note', 'idempotency_key' => bin2hex(random_bytes(16))];
    $posted = pl_recognize_unapplied_credit($f['actor_id'], $f['company_id'], $f['book_id'], $input);
    $journal = pl_get_journal($f['actor_id'], $f['company_id'], $f['book_id'], $posted['journal_id']);
    // Dr sales returns and discounts allowed 12, Cr customer advances 12.
    assert_same(2, count($journal['lines']));
    assert_same('12.0000', $journal['lines'][0]['credit']);
    assert_same('12.0000', $journal['lines'][1]['debit']);
    assert_same($f['advance_account_id'], $posted['control_account_id']);
    assert_same($posted, pl_recognize_unapplied_credit($f['actor_id'], $f['company_id'], $f['book_id'], $input));
    // It behaves like any other unapplied credit from here on.
    $applied = pl_apply_unapplied_credit($f['actor_id'], $f['company_id'], $f['book_id'], ['advance_item_id' => $posted['item_id'],
        'date' => '2026-03-06', 'description' => 'Sample application of an orphan credit', 'idempotency_key' => bin2hex(random_bytes(16)),
        'allocations' => [['item_id' => $f['items'][0], 'amount_fc' => '10']]]);
    assert_same('2.0000', pl_get_open_item($f['actor_id'], $f['company_id'], $f['book_id'], $posted['item_id'])['remaining_fc']);
    assert_same('0.0000', pl_get_open_item($f['actor_id'], $f['company_id'], $f['book_id'], $f['items'][0])['remaining_fc']);
    assert_true(pl_trial_balance($f['actor_id'], $f['company_id'], $f['book_id'])['balanced']);
    // A bank or a control account is never the other side of a credit note.
    foreach ([$f['accounts']['1000'], $f['control_account_id'], $f['advance_account_id']] as $forbidden) {
        $bad = array_replace($input, ['offset_account_id' => $forbidden, 'idempotency_key' => bin2hex(random_bytes(16))]);
        assert_throws(fn () => pl_recognize_unapplied_credit($f['actor_id'], $f['company_id'], $f['book_id'], $bad), DomainException::class);
    }
    assert_true($applied['journal_id'] > 0);
});

/**
 * The other side of an orphan credit note — 1.2 internal accounting review, finding 2.
 *
 * Until this guard the offset was screened only for "not a bank and not an open-item
 * control", so the owner capital account, the owner's loan account and any ordinary
 * expense account were all accepted. An expense offset grosses the profit and loss
 * account up on both sides; an equity or loan offset takes the adjustment out of profit
 * altogether. IFRS 15.70-72 and IAS 1.32: a goodwill credit to a customer is a reduction
 * of revenue, and its supplier mirror a reduction of cost.
 */
function advance_credit_note_input(array $f, int $offsetAccountId, array $extra = []): array
{
    return ['side' => $f['side'], 'party_id' => $f['party_id'], 'offset_account_id' => $offsetAccountId,
        'currency' => 'PKR', 'amount_fc' => '12', 'date' => '2026-03-02',
        'source_reference' => 'Goodwill credit, no invoice', 'description' => 'Sample orphan credit note',
        'idempotency_key' => bin2hex(random_bytes(16))] + $extra;
}

test('a customer credit note with no invoice takes income and refuses expense, equity and liability', function (): void {
    $f = advance_fixture();
    $post = static fn (string $code, array $extra = []): array => pl_recognize_unapplied_credit($f['actor_id'], $f['company_id'], $f['book_id'],
        advance_credit_note_input($f, $f['accounts'][$code], $extra));
    // The reserved contra-income group is the offered default; ordinary income is still income.
    foreach (['4-900-10001-00', '4-100-10001-00'] as $code) {
        $posted = $post($code);
        assert_true($posted['journal_id'] > 0);
        assert_same(false, $posted['offset_override']);
        assert_same($f['accounts'][$code], $posted['offset_account_id']);
    }
    // Each refusal names the type expected and the type offered, so the guard cannot be
    // weakened without a test noticing.
    foreach (['5-100-10001-00' => 'expense', '3-100-10001-00' => 'equity', '2-110-10001-00' => 'liability'] as $code => $type) {
        assert_throws(fn () => $post($code), DomainException::class, 'must be an income account');
        assert_throws(fn () => $post($code), DomainException::class, 'is of type ' . $type);
        assert_throws(fn () => $post($code), DomainException::class, 'Sales returns and discounts allowed');
    }
    assert_true(pl_trial_balance($f['actor_id'], $f['company_id'], $f['book_id'])['balanced']);
});

test('a supplier credit note with no bill takes expense and refuses income', function (): void {
    $f = advance_fixture(true);
    $post = static fn (string $code, array $extra = []): array => pl_recognize_unapplied_credit($f['actor_id'], $f['company_id'], $f['book_id'],
        advance_credit_note_input($f, $f['accounts'][$code], $extra));
    foreach (['5-900-10001-00', '5-100-10001-00'] as $code) {
        $posted = $post($code);
        assert_same('supplier', $posted['side']);
        $journal = pl_get_journal($f['actor_id'], $f['company_id'], $f['book_id'], $posted['journal_id']);
        // Dr supplier advances 12, Cr purchase returns 12 — the mirror of the customer side.
        assert_same('12.0000', $journal['lines'][0]['debit']);
        assert_same('12.0000', $journal['lines'][1]['credit']);
    }
    foreach (['4-100-10001-00' => 'income', '3-100-10001-00' => 'equity'] as $code => $type) {
        assert_throws(fn () => $post($code), DomainException::class, 'must be an expense account');
        assert_throws(fn () => $post($code), DomainException::class, 'is of type ' . $type);
        assert_throws(fn () => $post($code), DomainException::class, 'Purchase returns and discounts received');
    }
    assert_true(pl_trial_balance($f['actor_id'], $f['company_id'], $f['book_id'])['balanced']);
});

test('a bank or a control account is never the other side, and no override buys one', function (): void {
    $f = advance_fixture();
    $override = ['allow_other_offset_account' => true, 'offset_override_reason' => 'Sample reviewed exception'];
    foreach ([$f['accounts']['1000'], $f['control_account_id'], $f['advance_account_id']] as $forbidden) {
        foreach ([[], $override] as $extra) {
            assert_throws(fn () => pl_recognize_unapplied_credit($f['actor_id'], $f['company_id'], $f['book_id'],
                advance_credit_note_input($f, $forbidden, $extra)), DomainException::class, 'never has a bank or a control account');
        }
    }
    // An inactive account is refused before anything else is considered.
    $returns = $f['accounts']['4-900-10001-00'];
    DB::update('pl_accounts', ['is_active' => 0], 'id = %i', $returns);
    assert_throws(fn () => pl_recognize_unapplied_credit($f['actor_id'], $f['company_id'], $f['book_id'],
        advance_credit_note_input($f, $returns)), DomainException::class, 'Choose an active offset account');
    DB::update('pl_accounts', ['is_active' => 1], 'id = %i', $returns);
});

test('another account is an explicit reviewed override: owner, a recorded reason, and it is on the line', function (): void {
    $f = advance_fixture();
    $expense = $f['accounts']['5-100-10001-00'];
    $reason = 'Reviewed: this credit pays for a distinct advertising service the customer supplied';
    $input = advance_credit_note_input($f, $expense, ['allow_other_offset_account' => true, 'offset_override_reason' => $reason]);
    $posted = pl_recognize_unapplied_credit($f['actor_id'], $f['company_id'], $f['book_id'], $input);
    assert_same(true, $posted['offset_override']);
    assert_same($reason, $posted['offset_override_reason']);
    assert_same($expense, $posted['offset_account_id']);
    // The named choice is on the posting itself, not only in the request that made it.
    $journal = pl_get_journal($f['actor_id'], $f['company_id'], $f['book_id'], $posted['journal_id']);
    assert_true(str_contains((string) $journal['lines'][1]['description'], 'reviewed offset override: ' . $reason));
    // It is a durable command receipt like any other, and its payload covers the override.
    assert_same($posted, pl_recognize_unapplied_credit($f['actor_id'], $f['company_id'], $f['book_id'], $input));
    assert_throws(fn () => pl_recognize_unapplied_credit($f['actor_id'], $f['company_id'], $f['book_id'],
        array_replace($input, ['offset_override_reason' => 'A different reason'])), DomainException::class, 'different content');
    // The flag alone is not a choice, and it must be a real boolean.
    assert_throws(fn () => pl_recognize_unapplied_credit($f['actor_id'], $f['company_id'], $f['book_id'],
        advance_credit_note_input($f, $expense, ['allow_other_offset_account' => true])), DomainException::class, 'Reason for this offset account');
    assert_throws(fn () => pl_recognize_unapplied_credit($f['actor_id'], $f['company_id'], $f['book_id'],
        advance_credit_note_input($f, $expense, ['allow_other_offset_account' => 'yes'])), DomainException::class, 'must be chosen explicitly');
    // And it is the owner's call, like every other deliberate accounting exception here.
    $accountant = ledger_fixture('PKR');
    sample_membership_insert(['company_id' => $f['company_id'], 'user_id' => $accountant['actor_id'], 'role' => 'accountant']);
    assert_throws(fn () => pl_recognize_unapplied_credit($accountant['actor_id'], $f['company_id'], $f['book_id'],
        advance_credit_note_input($f, $expense, ['allow_other_offset_account' => true, 'offset_override_reason' => $reason])),
        DomainException::class, 'Only the business owner');
    assert_true(pl_trial_balance($f['actor_id'], $f['company_id'], $f['book_id'])['balanced']);
});

test('the posting funnel checks the offset itself, so no future screen can widen the rule', function (): void {
    $f = advance_fixture();
    // One real credit note first: the advances control is registered by the service.
    pl_recognize_unapplied_credit($f['actor_id'], $f['company_id'], $f['book_id'], advance_credit_note_input($f, $f['accounts']['4-900-10001-00']));
    $itemId = pl_advance_open_item($f['actor_id'], $f['company_id'], $f['book_id'], $f['party_id'], $f['advance_account_id'], 'payable', 'PKR', 'advance:probe-' . bin2hex(random_bytes(8)));
    $snapshot = ['currency' => 'PKR', 'rate' => '1.000000000000', 'rate_type' => 'spot', 'rate_source_id' => null, 'rate_is_stale' => false, 'ic_counterparty_entity_id' => null];
    $payload = static fn (int $offsetId): array => ['lines' => [
        pl_oi_line($f['advance_account_id'], '5.0000', '5.0000', false, $snapshot, 'Sample funnel probe'),
        pl_oi_line($offsetId, '5.0000', '5.0000', true, $snapshot, 'Sample funnel probe'),
    ]];
    $basis = static fn (array $extra = []): array => [0 => ['item_id' => $itemId, 'kind' => 'recognition'] + $extra];
    $validate = static function (string $code, array $extra = []) use ($f, $payload, $basis): void {
        pl_open_item_validate_direct_advance_basis($f['company_id'], $f['book_id'], $payload($f['accounts'][$code]), $basis($extra));
    };
    // Straight at the funnel's own validator, with no service in front of it.
    $validate('4-900-10001-00');
    assert_throws(fn () => $validate('3-100-10001-00'), DomainException::class, 'must be an income account');
    assert_throws(fn () => $validate('5-100-10001-00'), DomainException::class, 'must be an income account');
    assert_throws(fn () => $validate('1000'), DomainException::class, 'never has a bank or a control account');
    // An override reaches the funnel as a named choice or not at all.
    assert_throws(fn () => $validate('3-100-10001-00', ['offset_override' => true]), DomainException::class, 'record the reason');
    assert_throws(fn () => $validate('3-100-10001-00', ['offset_override' => true, 'offset_override_reason' => '   ']), DomainException::class, 'record the reason');
    $validate('3-100-10001-00', ['offset_override' => true, 'offset_override_reason' => 'Sample reviewed exception']);
    assert_true(true);
});

test('supplier advances mirror the customer side through payment, application and refund', function (): void {
    $f = advance_fixture(true);
    $payment = pl_settle_open_items($f['actor_id'], $f['company_id'], $f['book_id'], advance_receipt_input($f, '25', [['item_id' => $f['items'][0], 'amount_fc' => '10']]));
    $journal = pl_get_journal($f['actor_id'], $f['company_id'], $f['book_id'], $payment['journal_id']);
    // Dr payables 10, Dr supplier advances 15, Cr bank 25 — one payment, one bank line.
    assert_same(3, count($journal['lines']));
    assert_same('25.0000', $journal['lines'][2]['credit']);
    $advance = pl_get_open_item($f['actor_id'], $f['company_id'], $f['book_id'], $payment['advance']['item_id']);
    assert_same('receivable', $advance['direction']);
    assert_same('15.0000', $advance['remaining_fc']);
    $applied = pl_apply_unapplied_credit($f['actor_id'], $f['company_id'], $f['book_id'], ['advance_item_id' => (int) $advance['id'],
        'date' => '2026-03-05', 'description' => 'Sample supplier application', 'idempotency_key' => bin2hex(random_bytes(16)),
        'allocations' => [['item_id' => $f['items'][1], 'amount_fc' => '10']]]);
    $applyJournal = pl_get_journal($f['actor_id'], $f['company_id'], $f['book_id'], $applied['journal_id']);
    // Dr payables 10, Cr supplier advances 10. No bank line.
    assert_same(2, count($applyJournal['lines']));
    assert_same('10.0000', $applyJournal['lines'][0]['credit']);
    assert_same('0.0000', pl_get_open_item($f['actor_id'], $f['company_id'], $f['book_id'], $f['items'][1])['remaining_fc']);
    $refunded = pl_refund_unapplied_credit($f['actor_id'], $f['company_id'], $f['book_id'], ['item_id' => (int) $advance['id'],
        'bank_account_id' => $f['accounts']['1000'], 'amount_fc' => '5', 'date' => '2026-03-11',
        'description' => 'Sample supplier refund received', 'idempotency_key' => bin2hex(random_bytes(16))]);
    assert_same('supplier', $refunded['refund_side']);
    assert_same('0.0000', pl_get_open_item($f['actor_id'], $f['company_id'], $f['book_id'], (int) $advance['id'])['remaining_fc']);
    assert_true(pl_trial_balance($f['actor_id'], $f['company_id'], $f['book_id'])['balanced']);
});

test('unapplied credit is its own report section, reconciled to the control and never in the ageing', function (): void {
    $f = advance_fixture();
    $receipt = pl_settle_open_items($f['actor_id'], $f['company_id'], $f['book_id'], advance_receipt_input($f, '25', [['item_id' => $f['items'][0], 'amount_fc' => '10']]));
    $report = pl_unapplied_credit($f['actor_id'], $f['company_id'], $f['book_id'], 'customer', '2026-03-01');
    assert_same(1, $report['count']);
    assert_same('15.0000', $report['total_base']);
    assert_same(true, $report['reconciled']);
    assert_same('0.0000', $report['difference_base']);
    assert_same('15.0000', $report['controls'][0]['ledger_base']);
    assert_same($f['party_id'], $report['parties'][0]['party_id']);
    // The receivables ageing covers documents only: advances are a different account family,
    // so nothing is counted twice and a customer advance never appears among payables.
    $ageing = pl_ar_ap_open_items($f['actor_id'], $f['company_id'], $f['book_id'], 'receivable', '2026-03-01');
    assert_same(true, $ageing['reconciled']);
    assert_same(1, $ageing['count']);
    assert_same(0, pl_ar_ap_open_items($f['actor_id'], $f['company_id'], $f['book_id'], 'payable', '2026-03-01')['count']);
    // As of the day before the receipt there is no unapplied credit yet.
    assert_same(0, pl_unapplied_credit($f['actor_id'], $f['company_id'], $f['book_id'], 'customer', '2026-02-28')['count']);
    // Applying it empties the section and the control together.
    pl_apply_unapplied_credit($f['actor_id'], $f['company_id'], $f['book_id'], ['advance_item_id' => $receipt['advance']['item_id'],
        'date' => '2026-03-07', 'description' => 'Sample application', 'idempotency_key' => bin2hex(random_bytes(16)),
        'allocations' => [['item_id' => $f['items'][1], 'amount_fc' => '10']]]);
    $after = pl_unapplied_credit($f['actor_id'], $f['company_id'], $f['book_id'], 'customer', '2026-03-31');
    assert_same(1, $after['count']);
    assert_same('5.0000', $after['total_base']);
    assert_same(true, $after['reconciled']);
    assert_same(0, pl_unapplied_credit($f['actor_id'], $f['company_id'], $f['book_id'], 'supplier', '2026-03-31')['count']);
});

test('a batch posts one voucher per customer with its own remainder and one idempotency receipt', function (): void {
    $f = advance_fixture();
    $party = pl_save_party($f['actor_id'], $f['company_id'], $f['book_id'], ['legal_name' => 'Second sample customer', 'entity_type' => 'private_company',
        'country_code' => 'GB', 'is_customer' => true, 'is_vendor' => true, 'currency' => 'PKR', 'request_key' => bin2hex(random_bytes(16)), 'reason' => 'Sample batch fixture']);
    $input = ['direction' => 'receivable', 'bank_account_id' => $f['accounts']['1000'], 'date' => '2026-03-01',
        'description' => 'Sample end-of-day collection', 'idempotency_key' => bin2hex(random_bytes(16)),
        'rows' => [
            ['party_id' => $f['party_id'], 'amount_fc' => '12', 'currency' => 'PKR', 'advance_account_id' => $f['advance_account_id'],
                'allocations' => [['item_id' => $f['items'][0], 'amount_fc' => '10']]],
            ['party_id' => $party['id'], 'amount_fc' => '7', 'currency' => 'PKR', 'advance_account_id' => $f['advance_account_id'], 'allocations' => []],
        ]];
    $posted = pl_post_batch_receipts($f['actor_id'], $f['company_id'], $f['book_id'], $input);
    assert_same(2, $posted['voucher_count']);
    assert_same('19.0000', $posted['total_fc']);
    $journals = array_column($posted['receipts'], 'journal_id');
    assert_same(2, count(array_unique($journals)));
    foreach ($posted['receipts'] as $row) {
        $journal = pl_get_journal($f['actor_id'], $f['company_id'], $f['book_id'], $row['journal_id']);
        // Each row is its own voucher with its own single bank line, individually reversible.
        assert_same(1, count(array_filter($journal['lines'], static fn (array $l): bool => (int) $l['account_id'] === $f['accounts']['1000'])));
    }
    assert_same('2.0000', $posted['receipts'][0]['remainder_fc']);
    assert_same('7.0000', $posted['receipts'][1]['remainder_fc']);
    assert_same($posted, pl_post_batch_receipts($f['actor_id'], $f['company_id'], $f['book_id'], $input));
    // One customer's receipt reverses without touching the other's.
    pl_reverse_journal($f['actor_id'], $f['company_id'], $f['book_id'], (int) $posted['receipts'][0]['journal_id'], gmdate('Y-m-d'), bin2hex(random_bytes(16)), 'One row entered in error');
    assert_same('10.0000', pl_get_open_item($f['actor_id'], $f['company_id'], $f['book_id'], $f['items'][0])['remaining_fc']);
    assert_same('7.0000', pl_get_open_item($f['actor_id'], $f['company_id'], $f['book_id'], (int) $posted['receipts'][1]['advance']['item_id'])['remaining_fc']);
    $duplicate = $input; $duplicate['rows'][1]['party_id'] = $f['party_id'];
    $duplicate['idempotency_key'] = bin2hex(random_bytes(16));
    assert_throws(fn () => pl_post_batch_receipts($f['actor_id'], $f['company_id'], $f['book_id'], $duplicate), DomainException::class, 'appears once');
});

test('concurrent identical batches post one set of vouchers', function (): void {
    $f = advance_fixture();
    $input = ['direction' => 'receivable', 'bank_account_id' => $f['accounts']['1000'], 'date' => '2026-03-01',
        'description' => 'Sample concurrent batch', 'idempotency_key' => bin2hex(random_bytes(16)),
        'rows' => [['party_id' => $f['party_id'], 'amount_fc' => '14', 'currency' => 'PKR',
            'advance_account_id' => $f['advance_account_id'], 'allocations' => [['item_id' => $f['items'][0], 'amount_fc' => '10']]]]];
    $job = ['mode' => 'batch_receipts', 'fixture' => $f, 'settlement_input' => $input];
    $results = ledger_race([$job, $job]);
    assert_true((int) $results[0]['id'] > 0);
    assert_same($results[0]['id'], $results[1]['id']);
    assert_same(1, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id=%i AND source_type=%s', $f['book_id'], 'open_item_batch_settlement'));
    assert_same(1, (int) DB::queryFirstField("SELECT COUNT(*) FROM pl_open_items WHERE book_id=%i AND nature='advance'", $f['book_id']));
    assert_same('4.0000', pl_unapplied_credit($f['actor_id'], $f['company_id'], $f['book_id'], 'customer', '2026-03-01')['total_base']);
});

test('concurrent applications of the same credit cannot spend it twice', function (): void {
    $f = advance_fixture();
    $receipt = pl_settle_open_items($f['actor_id'], $f['company_id'], $f['book_id'], advance_receipt_input($f, '15'));
    $apply = ['advance_item_id' => $receipt['advance']['item_id'], 'date' => '2026-03-05', 'description' => 'Sample race',
        'idempotency_key' => bin2hex(random_bytes(16)), 'allocations' => [['item_id' => $f['items'][0], 'amount_fc' => '10']]];
    $one = ['mode' => 'advance_apply', 'fixture' => $f, 'settlement_input' => $apply, 'allow_domain_failure' => true];
    $two = $one; $two['settlement_input']['idempotency_key'] = bin2hex(random_bytes(16));
    $two['settlement_input']['allocations'] = [['item_id' => $f['items'][1], 'amount_fc' => '10']];
    $race = ledger_race([$one, $two]);
    // 15 of credit cannot cover two applications of 10; exactly one survives.
    assert_same(1, count(array_filter($race, static fn (array $row): bool => $row['id'] > 0)));
    assert_same('5.0000', pl_get_open_item($f['actor_id'], $f['company_id'], $f['book_id'], $receipt['advance']['item_id'])['remaining_fc']);
});

test('the advances schema ties an advance to its own control and no screen can forge one', function (): void {
    $f = advance_fixture();
    $receipt = pl_settle_open_items($f['actor_id'], $f['company_id'], $f['book_id'], advance_receipt_input($f, '15'));
    $advanceId = (int) $receipt['advance']['item_id'];
    // A document item can never point at the advances control, and an advance can never
    // point anywhere else: the CHECK and the foreign key refuse both.
    assert_throws(fn () => DB::insert('pl_open_items', ['company_id' => $f['company_id'], 'book_id' => $f['book_id'], 'party_id' => $f['party_id'],
        'control_account_id' => $f['advance_account_id'], 'direction' => 'payable', 'currency' => 'PKR',
        'source_reference' => bin2hex(random_bytes(8)), 'created_by' => $f['actor_id']]), Throwable::class);
    assert_throws(fn () => DB::insert('pl_open_items', ['company_id' => $f['company_id'], 'book_id' => $f['book_id'], 'party_id' => $f['party_id'],
        'control_account_id' => $f['control_account_id'], 'advance_control_account_id' => $f['control_account_id'], 'nature' => 'advance',
        'direction' => 'payable', 'currency' => 'PKR', 'source_reference' => bin2hex(random_bytes(8)), 'created_by' => $f['actor_id']]), Throwable::class);
    assert_throws(fn () => DB::delete('pl_advance_accounts', 'account_id=%i', $f['advance_account_id']), Throwable::class, 'permanent');
    // The direct posting funnel refuses every advance source type.
    $journal = pl_get_journal($f['actor_id'], $f['company_id'], $f['book_id'], $receipt['journal_id']);
    $lines = array_map(static fn (array $l): array => ['account_id' => (int) $l['account_id'], 'debit' => (string) $l['debit'], 'credit' => (string) $l['credit']], $journal['lines']);
    foreach (['open_item_application', 'open_item_advance', 'open_item_batch_settlement'] as $sourceType) {
        assert_throws(fn () => pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], ['date' => '2026-03-01', 'currency' => 'PKR',
            'source_type' => $sourceType, 'source_reference' => $sourceType . ':' . str_repeat('a', 64), 'idempotency_key' => bin2hex(random_bytes(16)),
            'description' => 'Forged advance posting', 'lines' => $lines]), DomainException::class, 'authoritative open-item');
    }
    assert_true($advanceId > 0);
});

test('the receipt, batch and ageing screens render their advances sections', function (): void {
    // The browser layer is loaded on demand by the front controller, not by the bootstrap.
    require_once PL_APP . '/includes/functions/web_functions.php';
    require_once PL_APP . '/includes/functions/starter_web_functions.php';
    require_once PL_APP . '/includes/functions/starter_ar_web_functions.php';
    require_once PL_APP . '/templates/partials/ui/components.php';
    $f = advance_fixture();
    pl_settle_open_items($f['actor_id'], $f['company_id'], $f['book_id'], advance_receipt_input($f, '25', [['item_id' => $f['items'][0], 'amount_fc' => '10']]));
    $company =['id' => $f['company_id'], 'book_id' => $f['book_id'], 'name' => 'Sample advances company', 'currency' => 'PKR'];
    $accounts = pl_starter_accounts($f['actor_id'], $f['company_id'], $f['book_id']);
    $parties = pl_starter_parties($f['actor_id'], $f['company_id'], $f['book_id']);
    if (session_status() !== PHP_SESSION_ACTIVE) { pl_session_start(false); }
    $render = static function (string $view, array $data): string {
        extract($data, EXTR_SKIP);
        ob_start();
        try { require PL_APP . '/templates/views/' . $view . '.php'; }
        finally { $html = (string) ob_get_clean(); }
        return $html;
    };
    $batchInput = ['request_key' => bin2hex(random_bytes(16)), 'date' => '2026-03-02', 'bank_account_id' => (string) $f['accounts']['1000'],
        'advance_account_id' => (string) $f['advance_account_id'], 'description' => 'Sample batch',
        'rows' => [['party_id' => (string) $f['party_id'], 'amount_fc' => '30', 'currency' => 'PKR']]];
    $batch = pl_web_batch_receipt_plan($f['actor_id'], $f['company_id'], $f['book_id'], pl_web_batch_receipt_input($batchInput, 'receivable'));
    assert_same('20.0000', $batch['rows'][0]['remainder_fc']);
    // "Outstanding before" is read from the posted open items, never invented by the screen.
    assert_same('10.0000', $batch['rows'][0]['outstanding_before']);
    $shared = ['company' => $company, 'path' => '/ar', 'direction' => 'receivable', 'accounts' => $accounts, 'parties' => $parties,
        'currencies' => ['PKR'], 'advanceRole' => 'customer_advances', 'side' => 'customer', 'preview' => null, 'items' => [], 'partyId' => 0,
        'currency' => 'PKR', 'unapplied' => ['items' => [], 'count' => 0, 'total_base' => '0.0000', 'side' => 'customer'],
        'form' => ['message' => '', 'input' => $batchInput], 'input' => $batchInput];
    $html = $render('settlement', $shared + ['batch' => $batch]);
    assert_true(str_contains($html, 'Held on account'), 'The batch grid does not show the per-row remainder.');
    assert_true(str_contains($html, 'post_batch'), 'The batch grid has no posting action.');
    $single = $render('settlement', array_replace($shared, ['batch' => null, 'input' => ['request_key' => bin2hex(random_bytes(16))], 'form' => ['message' => '', 'input' => []],
        'partyId' => $f['party_id'], 'items' => array_values(array_filter(pl_ar_ap_open_items($f['actor_id'], $f['company_id'], $f['book_id'], 'receivable')['items'],
            static fn (array $item): bool => (int) $item['party_id'] === $f['party_id'])),
        'unapplied' => pl_unapplied_credit($f['actor_id'], $f['company_id'], $f['book_id'], 'customer', null, $f['party_id'])]));
    assert_true(str_contains($single, 'plan_settlement'), 'The receipt screen has no oldest-first action.');
    assert_true(str_contains($single, 'advances control for any remainder'), 'The receipt screen does not name the advances control.');
    assert_true(str_contains($single, 'unapplied credit on the customer-advances control'), 'The receipt screen does not warn about existing unapplied credit.');
    $ageing = $render('ageing', ['company' => $company, 'report' => pl_ar_ap_open_items($f['actor_id'], $f['company_id'], $f['book_id'], 'receivable'),
        'unapplied' => pl_unapplied_credit($f['actor_id'], $f['company_id'], $f['book_id'], 'customer')]);
    assert_true(str_contains($ageing, 'customer advances (unapplied credit)'), 'The ageing report has no unapplied-credit section.');
    assert_true(str_contains($ageing, 'Unapplied credit reconciles to the advances control account.'), 'The unapplied-credit section does not reconcile.');
});

test('unapplied credit is an authorized read grant with its own bounded arguments', function (): void {
    assert_true(isset(pl_read_catalog()['unapplied_credit']));
    // A report-only connection reads summaries; party-level credit needs a full grant.
    assert_true(!in_array('unapplied_credit', pl_connection_read_operations(['access_mode' => 'reports']), true));
    assert_true(in_array('unapplied_credit', pl_connection_read_operations(['access_mode' => 'full']), true));
    assert_throws(fn () => pl_read_arguments('unapplied_credit', ['company_id' => 1, 'book_id' => 1, 'side' => 'everyone']), DomainException::class, 'Unsupported value');
    assert_throws(fn () => pl_read_arguments('unapplied_credit', ['company_id' => 1, 'book_id' => 1, 'unknown' => 1]), DomainException::class, 'Unknown arguments');
    assert_same('customer', pl_read_arguments('unapplied_credit', ['company_id' => 1, 'book_id' => 1])['side']);
});
