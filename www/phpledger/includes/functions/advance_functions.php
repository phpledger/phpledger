<?php
declare(strict_types=1);

/**
 * Advances, refunds and orphan credit notes — 1.2 M5 (owner decisions B39, B53, B57, B59).
 *
 * Unapplied credit is a customer-advances liability control tracked as advance open
 * items. Nothing here introduces a second ledger or a second posting path: every
 * function below builds lines and hands them to pl_post_journal_locked() through the
 * open-item services, under the same book lock, idempotency receipt and period rules as
 * an invoice.
 *
 * The worked journals are in docs/accounting/ADVANCES-AND-REFUNDS.md (B30).
 */

/**
 * Resolve, and on posting register, the advances control for one side of a book.
 *
 * `$activate` is false during a preview: a preview must not create chart registrations.
 * Registration is permanent (migration 037) because `pl_open_items` carries a foreign
 * key to it; an advances control is therefore checked hard before it is registered.
 */
function pl_advance_control(int $actorId, int $companyId, int $bookId, string $side, ?int $accountId = null, bool $activate = true): int
{
    $contract = pl_advance_role_contract($side);
    if ($accountId === null) {
        $candidates = DB::query('SELECT id FROM pl_accounts WHERE company_id=%i AND book_id=%i AND role=%s AND is_active=1 FOR SHARE', $companyId, $bookId, $contract['role']);
        if (count($candidates) !== 1) { throw new DomainException('Choose the ' . $side . ' advances control account; there must be one unambiguous default.'); }
        $accountId = (int) $candidates[0]['id'];
    }
    $account = DB::queryFirstRow('SELECT * FROM pl_accounts WHERE id=%i AND company_id=%i AND book_id=%i FOR SHARE', $accountId, $companyId, $bookId);
    if (!$account || !(bool) $account['is_active'] || $account['role'] !== $contract['role'] || $account['type'] !== $contract['type'] || $account['currency'] !== null) {
        throw new DomainException('Choose an active, currency-neutral ' . $side . ' advances control account of the correct classification.');
    }
    $registered = DB::queryFirstRow('SELECT * FROM pl_advance_accounts WHERE account_id=%i AND company_id=%i AND book_id=%i FOR SHARE', $accountId, $companyId, $bookId);
    if ($registered) {
        if ($registered['side'] !== $side) { throw new DomainException('This control already holds the other side of advances.'); }
        return $accountId;
    }
    if (!DB::queryFirstField('SELECT account_id FROM pl_open_item_accounts WHERE account_id=%i AND company_id=%i AND book_id=%i FOR SHARE', $accountId, $companyId, $bookId)) {
        if ($activate) { pl_activate_open_item_account($actorId, $companyId, $bookId, $accountId, 'First unapplied ' . $side . ' credit held on this control'); }
        else { pl_open_item_activation_check($actorId, $companyId, $bookId, $accountId); }
    }
    if (!$activate) { return $accountId; }
    DB::insert('pl_advance_accounts', ['account_id' => $accountId, 'company_id' => $companyId, 'book_id' => $bookId, 'side' => $side,
        'registered_by' => $actorId, 'reason' => 'First unapplied ' . $side . ' credit held on this control']);
    return $accountId;
}

/** Create the advance open item a remainder, refund basis or orphan credit note will carry. */
function pl_advance_open_item(int $actorId, int $companyId, int $bookId, int $partyId, int $controlAccountId, string $direction, string $currency, string $sourceReference): int
{
    DB::insert('pl_open_items', ['company_id' => $companyId, 'book_id' => $bookId, 'party_id' => $partyId,
        'control_account_id' => $controlAccountId, 'advance_control_account_id' => $controlAccountId, 'nature' => 'advance',
        'direction' => $direction, 'currency' => $currency, 'source_reference' => pl_ledger_text($sourceReference, 'Advance source reference', 120), 'created_by' => $actorId]);
    return (int) DB::insertId();
}

/**
 * The oldest-first allocation planner — a pure function.
 *
 * No database, no clock, no request state: given the open items, the amount and the
 * business date, it returns the same plan every time, which is what makes it reviewable
 * and testable on its own. The ordering key is **due date, then id**; id breaks the tie
 * so two documents due on the same day always allocate in the order they were recorded.
 *
 * Three rules the accountant should know about:
 *  - The last item allocated takes the *exact residual*, so the allocations always sum
 *    to the amount they claim to; there is no proportional spreading and no rounding.
 *  - An item whose latest activity is dated after the payment is **skipped**, not
 *    partially filled: the ledger refuses a settlement that precedes an item's own last
 *    movement, so planning around it here turns a rejected posting into a visible note.
 *  - Whatever is left over is the remainder, and the remainder becomes unapplied credit.
 *
 * The plan is a proposal. Decision 21 of the forms-and-reports design frame keeps every
 * allocated amount editable before posting, and both routes go through the same preview,
 * review hash and confirmation.
 *
 * @param list<array{item_id:int,due_date:string,remaining_fc:string,latest_activity_date:?string}> $items
 */
function pl_oldest_first_allocation(array $items, string $amount, string $date, ?int $cap = null): array
{
    $cap ??= pl_settlement_allocation_cap();
    $residual = pl_amount($amount);
    pl_ledger_date($date);
    usort($items, static fn (array $a, array $b): int => [$a['due_date'], $a['item_id']] <=> [$b['due_date'], $b['item_id']]);
    $allocations = []; $skipped = []; $limited = false;
    foreach ($items as $item) {
        if (bccomp($residual, '0', 4) <= 0) { break; }
        if (bccomp($item['remaining_fc'], '0', 4) <= 0) { continue; }
        if (($item['latest_activity_date'] ?? null) !== null && $date < $item['latest_activity_date']) {
            $skipped[] = ['item_id' => $item['item_id'], 'reason' => 'Later activity on ' . $item['latest_activity_date'] . ' than this payment.'];
            continue;
        }
        if (count($allocations) >= $cap) { $limited = true; break; }
        $take = bccomp($residual, $item['remaining_fc'], 4) < 0 ? $residual : $item['remaining_fc'];
        $allocations[] = ['item_id' => $item['item_id'], 'amount_fc' => $take];
        $residual = bcsub($residual, $take, 4);
    }
    return ['allocations' => $allocations, 'remainder_fc' => $residual, 'skipped' => $skipped, 'limited' => $limited];
}

/**
 * Read one party's open documents and propose the oldest-first plan for them.
 *
 * The ageing read is the authority for a document's due date, so this uses it rather
 * than re-deriving dates; the extra query adds each item's latest activity, which the
 * planner needs to skip an item the payment could not legally relieve.
 */
function pl_plan_settlement_allocation(int $actorId, int $companyId, int $bookId, string $direction, int $partyId, string $currency, string $amount, string $date): array
{
    pl_currency_code($currency);
    $amount = pl_amount(pl_ledger_text($amount, 'Payment amount', 30));
    $date = pl_ledger_date(pl_ledger_text($date, 'Payment date', 10));
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $direction, $partyId, $currency, $amount, $date): array {
        pl_require_company_access($actorId, $companyId);
        pl_ledger_book($companyId, $bookId);
        $report = pl_ar_ap_open_items($actorId, $companyId, $bookId, $direction, $date);
        $items = [];
        foreach ($report['items'] as $row) {
            if ((int) $row['party_id'] !== $partyId || $row['currency'] !== $currency) { continue; }
            $items[] = ['item_id' => (int) $row['id'], 'due_date' => (string) $row['due_date'], 'remaining_fc' => (string) $row['remaining_fc'],
                'latest_activity_date' => pl_open_item_state($companyId, $bookId, (int) $row['id'])['latest_activity_date'],
                'number' => (string) $row['number'], 'document_date' => (string) $row['document_date']];
        }
        $plan = pl_oldest_first_allocation($items, $amount, $date);
        return $plan + ['items' => $items, 'direction' => $direction, 'party_id' => $partyId, 'currency' => $currency, 'amount_fc' => $amount, 'date' => $date];
    });
}

/**
 * Apply unapplied credit to open documents. No bank line; no money moves (B39).
 *
 * Dr the customer-advances control and Cr receivables (or the mirror for suppliers):
 * the obligation to the customer is discharged by cancelling their debt instead of by
 * paying them. Each side is relieved at its own historic carrying value, so a foreign
 * advance applied to a foreign invoice realises the exchange difference between the two
 * frozen rates exactly as a bank settlement would.
 */
function pl_apply_unapplied_credit(int $actorId, int $companyId, int $bookId, array $input): array
{
    $rows = $input['allocations'] ?? null;
    if (!is_array($rows) || count($rows) < 1 || count($rows) > pl_settlement_allocation_cap()) { throw new DomainException('Apply the credit to between one and ' . pl_settlement_allocation_cap() . ' open items.'); }
    $allocations = []; $sum = '0.0000';
    foreach ($rows as $row) {
        if (!is_array($row)) { throw new DomainException('Choose valid allocation rows.'); }
        $id = pl_oi_id($row, 'item_id');
        if (isset($allocations[$id])) { throw new DomainException('An open item may appear only once in an application.'); }
        $amount = pl_amount(pl_ledger_text($row['amount_fc'] ?? null, 'Applied amount', 30));
        if (bccomp($amount, '0', 4) <= 0) { throw new DomainException('Each applied amount must be greater than zero.'); }
        $allocations[$id] = ['item_id' => $id, 'amount_fc' => $amount];
        $sum = bcadd($sum, $amount, 4);
    }
    ksort($allocations, SORT_NUMERIC);
    $data = ['action' => 'apply_advance', 'advance_item_id' => pl_oi_id($input, 'advance_item_id'), 'allocations' => array_values($allocations), 'amount_fc' => $sum,
        'date' => pl_ledger_date(pl_ledger_text($input['date'] ?? null, 'Application date', 10)),
        'description' => pl_ledger_text($input['description'] ?? null, 'Application reference', 500),
        'gain_account_id' => isset($input['gain_account_id']) && $input['gain_account_id'] !== '' ? pl_oi_id($input, 'gain_account_id') : null,
        'loss_account_id' => isset($input['loss_account_id']) && $input['loss_account_id'] !== '' ? pl_oi_id($input, 'loss_account_id') : null];
    return pl_ar_canonical_result(pl_oi_command($actorId, $companyId, $bookId, pl_ledger_text($input['idempotency_key'] ?? null, 'Application identity', 128), $data,
        function (string $journalKey) use ($actorId, $companyId, $bookId, $data): array {
            $book = pl_ledger_book($companyId, $bookId, true);
            $advance = pl_open_item_state($companyId, $bookId, $data['advance_item_id']);
            if ($advance['nature'] !== 'advance') { throw new DomainException('Choose unapplied credit, not an invoice or bill.'); }
            $side = pl_advance_side_for_direction($advance['direction'] === 'payable' ? 'receivable' : 'payable');
            pl_require_module($actorId, $companyId, $bookId, $side === 'customer' ? 'ar' : 'ap');
            $advanceBase = pl_oi_allocated_base($advance, $data['amount_fc']);
            if ($data['date'] < $advance['latest_activity_date']) { throw new DomainException('An application cannot precede the latest activity of the credit it uses.'); }
            $lines = [pl_advance_carrying_line($advance, $data['amount_fc'], $advanceBase, 'Unapplied credit applied')];
            $map = [0 => ['item_id' => (int) $advance['id'], 'kind' => 'application']];
            $documentBase = '0.0000'; $applied = [];
            foreach ($data['allocations'] as $allocation) {
                $item = pl_open_item_state($companyId, $bookId, $allocation['item_id']);
                if ($item['nature'] !== 'document' || (int) $item['party_id'] !== (int) $advance['party_id'] || $item['currency'] !== $advance['currency']) {
                    throw new DomainException('Apply credit only to that party\'s own open documents in the same currency.');
                }
                if ($data['date'] < $item['latest_activity_date']) { throw new DomainException('An application cannot precede the latest activity of any selected open item.'); }
                $base = pl_oi_allocated_base($item, $allocation['amount_fc']);
                $map[count($lines)] = ['item_id' => $allocation['item_id'], 'kind' => 'application'];
                $lines[] = pl_advance_carrying_line($item, $allocation['amount_fc'], $base, 'Settled by unapplied credit');
                $applied[] = $allocation + ['allocated_base' => $base];
                $documentBase = bcadd($documentBase, $base, 4);
            }
            $difference = bcsub($documentBase, $advanceBase, 4); $fxKind = null;
            if (bccomp($difference, '0', 4) !== 0) {
                // The two sides were frozen at different rates; the gap is realised now.
                $gain = ($side === 'customer') === (bccomp($difference, '0', 4) < 0);
                $fxKind = $gain ? 'gain' : 'loss';
                $account = $data[$fxKind . '_account_id'];
                if ($account === null || !DB::queryFirstField('SELECT id FROM pl_accounts WHERE id=%i AND company_id=%i AND book_id=%i AND is_active=1 AND type=%s FOR SHARE', $account, $companyId, $bookId, $gain ? 'income' : 'expense')) {
                    throw new DomainException('Choose an active realised FX ' . $fxKind . ' account for the calculated exchange difference.');
                }
                $magnitude = ltrim($difference, '-');
                $domestic = pl_oi_rate($actorId, $companyId, $bookId, (string) $book['currency'], $data['date'], null, null, 'spot');
                $lines[] = pl_oi_line($account, $magnitude, $magnitude, !$gain, $domestic, 'Realised FX ' . $fxKind);
            }
            $journal = pl_post_journal_locked($actorId, $companyId, $bookId, ['date' => $data['date'], 'currency' => (string) $book['currency'],
                'source_type' => 'open_item_application', 'source_reference' => 'open-item-application:' . hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)),
                'idempotency_key' => $journalKey, 'description' => $data['description'], 'lines' => $lines], null, null, $map);
            return ['journal_id' => (int) $journal['id'], 'advance_item_id' => (int) $advance['id'], 'applied_fc' => $data['amount_fc'],
                'advance_base' => $advanceBase, 'document_base' => $documentBase, 'fx_kind' => $fxKind, 'fx_amount' => ltrim($difference, '-'),
                'currency' => $advance['currency'], 'allocations' => $applied];
        }));
}

/** One carrying line that relieves an open item at its own frozen recognition snapshot. */
function pl_advance_carrying_line(array $item, string $amountFc, string $amountBase, string $description): array
{
    $snapshot = array_intersect_key($item['recognition'], array_flip(['currency', 'rate', 'rate_type', 'rate_source_id', 'rate_is_stale', 'ic_counterparty_entity_id']));
    foreach (['rate_source_id', 'ic_counterparty_entity_id'] as $field) { $snapshot[$field] = $snapshot[$field] === null ? null : (int) $snapshot[$field]; }
    $snapshot['rate_is_stale'] = (bool) $snapshot['rate_is_stale'];
    // A receivable item is relieved by a credit, a payable item by a debit.
    return pl_oi_line((int) $item['control_account_id'], $amountFc, $amountBase, $item['direction'] === 'payable', $snapshot, $description);
}

/**
 * Refund unapplied credit in cash (B39).
 *
 * A refund is the ordinary settlement of an advance open item against the bank, so it
 * reuses the single-item settlement service unchanged: Dr customer advances, Cr bank.
 * The only thing this wrapper adds is the check that the item really is unapplied
 * credit, so a refund can never be aimed at an invoice by mistake.
 */
function pl_refund_unapplied_credit(int $actorId, int $companyId, int $bookId, array $input): array
{
    $itemId = pl_oi_id($input, 'item_id');
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $input, $itemId): array {
        pl_require_company_access($actorId, $companyId, true);
        pl_ledger_book($companyId, $bookId, true);
        $item = pl_open_item_state($companyId, $bookId, $itemId);
        if ($item['nature'] !== 'advance') { throw new DomainException('Only unapplied credit is refunded this way. Reverse or credit a document instead.'); }
        $side = pl_advance_side_for_direction($item['direction'] === 'payable' ? 'receivable' : 'payable');
        pl_require_module($actorId, $companyId, $bookId, $side === 'customer' ? 'ar' : 'ap');
        return pl_settle_open_item($actorId, $companyId, $bookId, $input) + ['refund_side' => $side];
    });
}

/**
 * Recognise unapplied credit directly, with no receipt behind it (B39).
 *
 * This is how a credit note that has no original invoice becomes real money owed to the
 * customer: Dr sales returns and discounts allowed (the reserved contra-income group
 * B60 put in every chart), Cr customer advances. The obligation is recognised now and
 * the customer later takes it as cash (a refund) or against a future invoice (an
 * application). The supplier mirror credits purchase returns and debits supplier
 * advances.
 *
 * The offset is constrained by side (1.2 internal accounting review, finding 2): a
 * customer credit reduces revenue, so it takes an `income` account; a supplier credit
 * reduces cost, so it takes an `expense` account. Until 1.2 the only refusals were a
 * bank and an open-item control, which left equity, an owner's loan account and any
 * ordinary expense account posting a revenue misstatement. A business that genuinely
 * needs another account says so explicitly: `allow_other_offset_account` with an
 * `offset_override_reason`, owner only, recorded on the command, hashed into the
 * journal's source reference and written on the offset line itself. The alternatives
 * considered, and why they were rejected, are in
 * docs/accounting/ADVANCES-AND-REFUNDS.md section 5 (B53).
 *
 * The AR *document* shape for such a credit note belongs to the trading-documents
 * module; what is fixed here is the accounting and the only service that may post it.
 */
function pl_recognize_unapplied_credit(int $actorId, int $companyId, int $bookId, array $input): array
{
    $side = $input['side'] ?? null;
    if (!in_array($side, ['customer', 'supplier'], true)) { throw new DomainException('Choose customer or supplier unapplied credit.'); }
    $override = $input['allow_other_offset_account'] ?? false;
    if (!is_bool($override)) { throw new DomainException('An offset account outside the expected type must be chosen explicitly.'); }
    $data = ['action' => 'recognize_advance', 'side' => $side, 'party_id' => pl_oi_id($input, 'party_id'),
        'offset_account_id' => pl_oi_id($input, 'offset_account_id'),
        'offset_override' => $override,
        'offset_override_reason' => $override ? pl_ledger_text($input['offset_override_reason'] ?? null, 'Reason for this offset account', 300) : '',
        'advance_account_id' => isset($input['advance_account_id']) && $input['advance_account_id'] !== '' ? pl_oi_id($input, 'advance_account_id') : null,
        'currency' => pl_currency_code(pl_ledger_text($input['currency'] ?? null, 'Currency', 3)),
        'amount_fc' => pl_amount(pl_ledger_text($input['amount_fc'] ?? null, 'Credit amount', 30)),
        'date' => pl_ledger_date(pl_ledger_text($input['date'] ?? null, 'Credit date', 10)),
        'source_reference' => pl_ledger_text($input['source_reference'] ?? null, 'Source reference', 100),
        'description' => pl_ledger_text($input['description'] ?? null, 'Description', 500),
        'rate' => isset($input['rate']) && $input['rate'] !== '' ? pl_fx_rate(pl_ledger_text($input['rate'], 'Rate', 40)) : null,
        'rate_source_id' => isset($input['rate_source_id']) ? pl_oi_id($input, 'rate_source_id') : null];
    if (bccomp($data['amount_fc'], '0', 4) <= 0) { throw new DomainException('Unapplied credit needs a positive amount.'); }
    return pl_ar_canonical_result(pl_oi_command($actorId, $companyId, $bookId, pl_ledger_text($input['idempotency_key'] ?? null, 'Request key', 128), $data,
        function (string $journalKey) use ($actorId, $companyId, $bookId, $data): array {
            $contract = pl_advance_role_contract($data['side']);
            pl_require_module($actorId, $companyId, $bookId, $data['side'] === 'customer' ? 'ar' : 'ap');
            $party = pl_get_party($actorId, $companyId, $bookId, $data['party_id']);
            if (!(bool) ($party[$data['side'] === 'customer' ? 'is_customer' : 'is_vendor'] ?? false)) { throw new DomainException('The party must have the corresponding customer or vendor role.'); }
            $offset = DB::queryFirstRow('SELECT * FROM pl_accounts WHERE id=%i AND company_id=%i AND book_id=%i FOR SHARE', $data['offset_account_id'], $companyId, $bookId);
            // Taking the credit outside its expected type is a reviewed exception, so it is the
            // owner's call and it carries a reason — the same shape as every other deliberate
            // exception in this application. The funnel checks the account again either way.
            if ($data['offset_override'] && pl_require_company_access($actorId, $companyId, true)['role'] !== 'owner') {
                throw new DomainException('Only the business owner can take a credit note to an account outside its expected type.');
            }
            $offset = pl_advance_assert_offset_account($data['side'], $offset, $data['offset_override']);
            $control = pl_advance_control($actorId, $companyId, $bookId, $data['side'], $data['advance_account_id']);
            $book = pl_ledger_book($companyId, $bookId, true);
            $snapshot = pl_oi_rate($actorId, $companyId, $bookId, $data['currency'], $data['date'], $data['rate'], $data['rate_source_id'], 'spot');
            if (($party['linked_entity_id'] ?? null) !== null) { $snapshot['ic_counterparty_entity_id'] = (int) $party['linked_entity_id']; }
            $base = pl_fx_convert($data['amount_fc'], $snapshot['rate']);
            $itemId = pl_advance_open_item($actorId, $companyId, $bookId, $data['party_id'], $control, $contract['direction'], $data['currency'], 'advance:' . $journalKey);
            $debit = $contract['direction'] === 'receivable';
            $journal = pl_post_journal_locked($actorId, $companyId, $bookId, ['date' => $data['date'], 'currency' => (string) $book['currency'],
                'source_type' => 'open_item_advance', 'source_reference' => 'open-item-advance:' . hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)),
                'idempotency_key' => $journalKey, 'description' => $data['description'], 'lines' => [
                    pl_oi_line($control, $data['amount_fc'], $base, $debit, $snapshot, $data['description']),
                    pl_oi_line((int) $offset['id'], $data['amount_fc'], $base, !$debit, $snapshot,
                        $data['offset_override'] ? $data['source_reference'] . ' — reviewed offset override: ' . $data['offset_override_reason'] : $data['source_reference']),
                ]], null, null, [0 => ['item_id' => $itemId, 'kind' => 'recognition',
                    'offset_override' => $data['offset_override'], 'offset_override_reason' => $data['offset_override_reason']]]);
            return ['item_id' => $itemId, 'journal_id' => (int) $journal['id'], 'control_account_id' => $control,
                'amount_fc' => $data['amount_fc'], 'amount_base' => $base, 'currency' => $data['currency'], 'side' => $data['side'],
                'offset_account_id' => (int) $offset['id'], 'offset_override' => $data['offset_override'],
                'offset_override_reason' => $data['offset_override_reason']];
        }));
}

/**
 * Batch receipts: one voucher per customer row (B57).
 *
 * The grid is an entry convenience. A salesman's end-of-day cash covers many customers,
 * but a single shared voucher would make one customer's receipt un-reversible without
 * editing another's, so each row posts its own immutable voucher with its own bank line,
 * its own allocations and its own remainder. They are written inside one transaction, so
 * the batch is all-or-nothing, and the batch itself carries one idempotency receipt while
 * each row derives its own key from it — a retry re-reads the same vouchers rather than
 * posting a second set.
 */
function pl_post_batch_receipts(int $actorId, int $companyId, int $bookId, array $input): array
{
    $direction = $input['direction'] ?? null;
    if (!in_array($direction, ['receivable', 'payable'], true)) { throw new DomainException('Choose a receipt or a payment batch.'); }
    $rows = $input['rows'] ?? null;
    if (!is_array($rows) || !array_is_list($rows) || count($rows) < 1 || count($rows) > 100) { throw new DomainException('A batch covers between one and 100 party rows.'); }
    $shared = ['direction' => $direction, 'bank_account_id' => pl_oi_id($input, 'bank_account_id'),
        'date' => pl_ledger_date(pl_ledger_text($input['date'] ?? null, 'Received date', 10)),
        'description' => pl_ledger_text($input['description'] ?? null, 'Batch reference', 400)];
    $normalized = []; $parties = []; $total = '0.0000';
    foreach ($rows as $index => $row) {
        if (!is_array($row)) { throw new DomainException('Choose valid batch rows.'); }
        $partyId = pl_oi_id($row, 'party_id');
        if (isset($parties[$partyId])) { throw new DomainException('Each party appears once in a batch. Combine that party\'s amounts into one row.'); }
        $parties[$partyId] = true;
        $line = $shared + ['party_id' => $partyId, 'amount_fc' => pl_amount(pl_ledger_text($row['amount_fc'] ?? null, 'Received amount', 30)),
            'currency' => isset($row['currency']) && $row['currency'] !== '' ? pl_currency_code(pl_ledger_text($row['currency'], 'Currency', 3)) : null,
            'allocations' => $row['allocations'] ?? [], 'advance_account_id' => $row['advance_account_id'] ?? null,
            'actual_rate' => $row['actual_rate'] ?? null, 'gain_account_id' => $row['gain_account_id'] ?? null, 'loss_account_id' => $row['loss_account_id'] ?? null,
            'description' => $shared['description'] . ' — row ' . ($index + 1)];
        $total = bcadd($total, $line['amount_fc'], 4);
        $normalized[] = $line;
    }
    $key = pl_ledger_text($input['idempotency_key'] ?? null, 'Batch identity', 100);
    return pl_ar_canonical_result(pl_oi_command($actorId, $companyId, $bookId, 'batch:' . $key, ['action' => 'batch_receipts', 'rows' => $normalized],
        function (string $journalKey) use ($actorId, $companyId, $bookId, $normalized, $key, $total, $direction): array {
            $results = [];
            foreach ($normalized as $index => $line) {
                $line['idempotency_key'] = 'batch:' . $key . ':' . $index;
                $results[] = ['party_id' => $line['party_id'], 'amount_fc' => $line['amount_fc']]
                    + pl_settle_open_items($actorId, $companyId, $bookId, $line);
            }
            return ['direction' => $direction, 'total_fc' => $total, 'voucher_count' => count($results), 'receipts' => $results];
        }));
}

/**
 * Unapplied credit as its own report section, reconciled to the advances control.
 *
 * This is the read the ageing screen and the customer statement use. It never nets
 * unapplied credit against receivables: the two are different account families and the
 * accountant reads them side by side. `controls` proves the section: for each advances
 * control it compares the posted general-ledger balance with the sum of the advance open
 * items on it, and `reconciled` is false the moment they diverge.
 *
 * `pl_ar_ap_open_items()` deliberately excludes these items, so a book's receivables
 * ageing plus this report covers every open item exactly once.
 */
function pl_unapplied_credit(int $actorId, int $companyId, int $bookId, string $side = 'customer', ?string $asOf = null, ?int $partyId = null): array
{
    $contract = pl_advance_role_contract($side);
    $asOf = pl_ledger_date($asOf ?? gmdate('Y-m-d'));
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $side, $contract, $asOf, $partyId): array {
        pl_require_company_access($actorId, $companyId);
        pl_ledger_book($companyId, $bookId);
        $rows = DB::query("SELECT i.*,p.legal_name FROM pl_open_items i JOIN pl_parties p ON p.id=i.party_id AND p.company_id=i.company_id
            WHERE i.company_id=%i AND i.book_id=%i AND i.nature='advance' AND i.direction=%s ORDER BY i.id FOR SHARE", $companyId, $bookId, $contract['direction']);
        $items = []; $total = '0.0000'; $byControl = []; $byParty = []; $currencyTotals = [];
        foreach ($rows as $row) {
            if ($partyId !== null && (int) $row['party_id'] !== $partyId) { continue; }
            $state = pl_open_item_state($companyId, $bookId, (int) $row['id']);
            $fc = '0.0000'; $base = '0.0000'; $date = null;
            foreach ($state['entries'] as $entry) {
                if ($entry['journal_date'] > $asOf) { continue; }
                $positive = in_array($entry['kind'], pl_open_item_increasing_kinds(), true);
                $fc = $positive ? bcadd($fc, $entry['amount_fc'], 4) : bcsub($fc, $entry['amount_fc'], 4);
                $base = $positive ? bcadd($base, $entry['amount_base'], 4) : bcsub($base, $entry['amount_base'], 4);
                if ($entry['kind'] === 'recognition') { $date = $entry['journal_date']; }
            }
            if (bccomp($fc, '0', 4) <= 0) { continue; }
            $row['id'] = (int) $row['id']; $row['party_id'] = (int) $row['party_id']; $row['control_account_id'] = (int) $row['control_account_id'];
            $row += ['remaining_fc' => $fc, 'remaining_base' => $base, 'received_date' => $date, 'number' => (string) $row['source_reference'],
                'age_days' => max(0, (int) (new DateTimeImmutable($date ?? $asOf))->diff(new DateTimeImmutable($asOf))->format('%r%a'))];
            $items[] = $row;
            $total = bcadd($total, $base, 4);
            $byControl[$row['control_account_id']] = bcadd($byControl[$row['control_account_id']] ?? '0.0000', $base, 4);
            $byParty[$row['party_id']] = ['party_id' => $row['party_id'], 'legal_name' => (string) $row['legal_name'],
                'unapplied_base' => bcadd($byParty[$row['party_id']]['unapplied_base'] ?? '0.0000', $base, 4)];
            $currencyTotals[$row['currency']] = bcadd($currencyTotals[$row['currency']] ?? '0.0000', $fc, 4);
        }
        $controls = DB::query('SELECT a.id,a.code,a.name,COALESCE(SUM(CASE WHEN j.id IS NOT NULL THEN l.debit-l.credit ELSE 0 END),0) AS balance
            FROM pl_accounts a LEFT JOIN pl_journal_lines l ON l.account_id=a.id AND l.company_id=a.company_id AND l.book_id=a.book_id
            LEFT JOIN pl_journals j ON j.id=l.journal_id AND j.journal_date<=%s
            WHERE a.company_id=%i AND a.book_id=%i AND a.role=%s GROUP BY a.id,a.code,a.name ORDER BY a.code', $asOf, $companyId, $bookId, $contract['role']);
        $difference = '0.0000';
        foreach ($controls as &$control) {
            $control['id'] = (int) $control['id'];
            // A customer-advances control is credit-normal; present it as a positive obligation.
            $control['ledger_base'] = $contract['direction'] === 'receivable' ? bcadd($control['balance'], '0', 4) : bcsub('0', $control['balance'], 4);
            $control['unapplied_base'] = $byControl[$control['id']] ?? '0.0000';
            $control['difference_base'] = bcsub($control['ledger_base'], $control['unapplied_base'], 4);
            $difference = bcadd($difference, $control['difference_base'], 4);
        }
        unset($control);
        usort($items, static fn (array $a, array $b): int => [$a['received_date'], $a['id']] <=> [$b['received_date'], $b['id']]);
        return ['side' => $side, 'direction' => $contract['direction'], 'as_of' => $asOf, 'items' => $items, 'count' => count($items),
            'total_base' => $total, 'currency_totals' => $currencyTotals, 'parties' => array_values($byParty), 'controls' => $controls,
            'difference_base' => $difference,
            'reconciled' => count(array_filter($controls, static fn (array $c): bool => bccomp($c['difference_base'], '0', 4) !== 0)) === 0];
    });
}
