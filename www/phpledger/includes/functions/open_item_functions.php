<?php
declare(strict_types=1);

/** This ledger is internal: no invoice, bill, payment document or write transport is introduced. */
function pl_oi_id(array $input, string $field): int
{
    if (!is_int($input[$field] ?? null) || $input[$field] < 1) {
        throw new DomainException('Supply a valid ' . $field . '.');
    }
    return $input[$field];
}

/**
 * Every account role whose balance is carried as open items.
 *
 * `customer_advances` and `supplier_advances` join receivables and payables in 1.2
 * (migration 037, B39/B59): unapplied credit is tracked as advance open items on their
 * own control, not as a negative balance hiding inside receivables.
 */
function pl_open_item_control_roles(): array
{
    return ['receivables', 'payables', 'customer_advances', 'supplier_advances'];
}

/** The role, account type and open-item direction of each side of the advances pair. */
function pl_advance_role_contract(string $side): array
{
    return match ($side) {
        // Money held for a customer is an obligation: credit-normal, so a `payable` item.
        'customer' => ['role' => 'customer_advances', 'type' => 'liability', 'direction' => 'payable', 'counter_direction' => 'receivable', 'counter_role' => 'receivables'],
        // Money paid to a supplier early is a claim: debit-normal, so a `receivable` item.
        'supplier' => ['role' => 'supplier_advances', 'type' => 'asset', 'direction' => 'receivable', 'counter_direction' => 'payable', 'counter_role' => 'payables'],
        default => throw new DomainException('Choose the customer or supplier advances side.'),
    };
}

/** The advances side that holds unapplied credit for a receivable/payable document direction. */
function pl_advance_side_for_direction(string $direction): string
{
    return match ($direction) {
        'receivable' => 'customer',
        'payable' => 'supplier',
        default => throw new DomainException('Choose receivables or payables.'),
    };
}

/**
 * The account type that belongs opposite unapplied credit recognised with no document.
 *
 * A goodwill credit granted to a customer is consideration payable to that customer, so
 * it reduces the transaction price rather than creating a cost (IFRS 15.70-72); the
 * mirror, a credit received from a supplier, reduces the cost of what was bought. Taking
 * either to equity, to an owner's loan or to an unrelated expense or income account
 * misstates the profit and loss account, and IAS 1.32 forbids netting one against the
 * other. `example` names the reserved contra group B60 puts in every chart, which is the
 * offered default rather than the only permitted account.
 *
 * @return array{type:string, expected:string, example:string, reduces:string}
 */
function pl_advance_offset_contract(string $side): array
{
    return match ($side) {
        'customer' => ['type' => 'income', 'expected' => 'an income account', 'reduces' => 'revenue',
            'example' => 'the reserved contra-income group "Sales returns and discounts allowed"'],
        'supplier' => ['type' => 'expense', 'expected' => 'an expense account', 'reduces' => 'cost',
            'example' => 'the reserved contra-expense group "Purchase returns and discounts received"'],
        default => throw new DomainException('Choose the customer or supplier advances side.'),
    };
}

/**
 * The one guard on the other side of an orphan credit note, applied by the recognition
 * service and again by the posting funnel (1.2 internal accounting review, finding 2).
 *
 * A bank or a control account is refused outright and is **not** overridable: paying the
 * party is a refund and settling a document is an application, and each has its own
 * service. The account *type* is the accounting rule of the credit note itself, and it is
 * the only part an owner may set aside, explicitly and with a recorded reason.
 *
 * @return array<string,mixed> the same account row, so a caller may use it without a
 *                             second null check.
 */
function pl_advance_assert_offset_account(string $side, ?array $account, bool $overridden = false): array
{
    $contract = pl_advance_offset_contract($side);
    if ($account === null || !(bool) $account['is_active']) {
        throw new DomainException('Choose an active offset account for a credit note with no original invoice.');
    }
    if (in_array($account['role'], pl_open_item_control_roles(), true) || $account['role'] === 'cash_bank') {
        throw new DomainException('A credit note with no original invoice never has a bank or a control account on its other side: paying the party is a refund and settling a document is an application. Choose ' . $contract['expected'] . ', normally ' . $contract['example'] . '.');
    }
    if ($account['type'] === $contract['type'] || $overridden) { return $account; }
    throw new DomainException('A ' . $side . ' credit note with no original invoice reduces ' . $contract['reduces']
        . ', so its other side must be ' . $contract['expected'] . ', normally ' . $contract['example'] . '. Account '
        . $account['code'] . ' is of type ' . $account['type']
        . '. Any other account needs an explicit reviewed override with a recorded reason.');
}

/**
 * A reviewed override is a named choice, never a bare flag.
 *
 * The funnel accepts it only when the posting carries both the explicit election and the
 * reason recorded with it, so a screen cannot widen the rule by setting one boolean.
 */
function pl_advance_offset_override_claimed(array $allocation): bool
{
    if (($allocation['offset_override'] ?? false) !== true) { return false; }
    $reason = $allocation['offset_override_reason'] ?? null;
    if (!is_string($reason) || trim($reason) === '') {
        throw new DomainException('An offset account outside the expected type is a reviewed exception: record the reason for it.');
    }
    return true;
}

function pl_activate_open_item_account(int $actorId, int $companyId, int $bookId, int $accountId, string $reason): array
{
    pl_demo_require_setup_action();
    $reason = pl_ledger_text($reason, 'Activation reason', 500);
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $accountId, $reason): array {
        $prior=pl_open_item_activation_check($actorId,$companyId,$bookId,$accountId);
        if ($prior!==null) { return $prior; }
        DB::insert('pl_open_item_accounts', ['account_id' => $accountId, 'company_id' => $companyId, 'book_id' => $bookId, 'activated_by' => $actorId, 'reason' => $reason]);
        return DB::queryFirstRow('SELECT * FROM pl_open_item_accounts WHERE account_id = %i', $accountId);
    });
}

/** Read-only eligibility check, shared with activation and editor preview. */
function pl_open_item_activation_check(int $actorId,int $companyId,int $bookId,int $accountId): ?array
{
    pl_demo_require_setup_action();
        $access = pl_require_company_access($actorId, $companyId, true);
        if (!pl_user_can($actorId, $companyId, 'openitem.activate')) { throw new DomainException('Your role cannot activate open-item accounting.'); }
        pl_ledger_book($companyId, $bookId, true);
        $prior = DB::queryFirstRow('SELECT * FROM pl_open_item_accounts WHERE account_id = %i AND company_id = %i AND book_id = %i FOR UPDATE', $accountId, $companyId, $bookId);
        if ($prior) { return $prior; }
        $account = DB::queryFirstRow('SELECT * FROM pl_accounts WHERE id = %i AND company_id = %i AND book_id = %i FOR SHARE', $accountId, $companyId, $bookId);
        if (!$account || !(bool) $account['is_active'] || $account['currency'] !== null || !in_array($account['role'], pl_open_item_control_roles(), true)) {
            throw new DomainException('Choose an active receivable, payable or advances control account.');
        }
        if (DB::queryFirstField('SELECT id FROM pl_journal_lines WHERE account_id = %i LIMIT 1 FOR SHARE', $accountId)
            || DB::queryFirstField('SELECT id FROM pl_opening_documents WHERE company_id = %i AND book_id = %i AND account_id = %i LIMIT 1 FOR SHARE', $companyId, $bookId, $accountId)) {
            throw new DomainException('Only unused control accounts can be activated. Existing balances require a reviewed AR/AP cutover.');
        }
    return null;
}

/** Outstanding is a sum of immutable referenced GL line amounts, never an independently editable total. */
function pl_open_item_state(int $companyId, int $bookId, int $itemId): array
{
    $item = DB::queryFirstRow('SELECT * FROM pl_open_items WHERE id = %i AND company_id = %i AND book_id = %i FOR UPDATE', $itemId, $companyId, $bookId);
    if (!$item) { throw new DomainException('This open item is not available in the selected book.'); }
    $entries = DB::query('SELECT e.id AS entry_id, e.kind, e.reversal_of_id AS entry_reversal_of_id, l.*, COALESCE(e.allocated_amount_fc, l.amount_fc) AS amount_fc, COALESCE(e.allocated_amount_base, l.amount_base) AS amount_base, e.opening_document_id, od.document_date AS opening_document_date, od.due_date AS opening_due_date, od.reference AS opening_reference, j.journal_date FROM pl_open_item_entries e LEFT JOIN pl_opening_documents od ON od.id = e.opening_document_id JOIN pl_journal_lines l ON l.id = e.journal_line_id AND l.company_id = e.company_id AND l.book_id = e.book_id JOIN pl_journals j ON j.id = l.journal_id WHERE e.item_id = %i AND e.company_id = %i AND e.book_id = %i ORDER BY e.id FOR SHARE', $itemId, $companyId, $bookId);
    $fc = '0.0000'; $base = '0.0000'; $recognized = null; $reversed = false; $latestDate = null;
    foreach ($entries as $entry) {
        $positive = in_array($entry['kind'], pl_open_item_increasing_kinds(), true);
        $fc = $positive ? bcadd($fc, $entry['amount_fc'], 4) : bcsub($fc, $entry['amount_fc'], 4);
        $base = $positive ? bcadd($base, $entry['amount_base'], 4) : bcsub($base, $entry['amount_base'], 4);
        if ($entry['kind'] === 'recognition') { $recognized = $entry; }
        if ($entry['kind'] === 'recognition_reversal') { $reversed = true; }
        if ($latestDate === null || $entry['journal_date'] > $latestDate) { $latestDate = $entry['journal_date']; }
    }
    $item['nature'] = $item['nature'] ?? 'document';
    return $item + ['recognition' => $recognized, 'entries' => $entries, 'remaining_fc' => $fc, 'remaining_base' => $base, 'reversed' => $reversed, 'latest_activity_date' => $latestDate];
}

/**
 * Entry kinds that increase an open item's outstanding amount.
 *
 * `application` (unapplied credit used against a document) reduces both items exactly
 * as `allocation` does; `application_reversal` restores them exactly as
 * `allocation_reversal` does. The kinds are separate only so B7's reversal rules and
 * the statement can tell a credit application apart from a bank receipt (migration 037).
 */
function pl_open_item_increasing_kinds(): array
{
    return ['recognition', 'allocation_reversal', 'application_reversal'];
}

/** Entry kinds that relieve an open item, whether through the bank or through credit. */
function pl_open_item_relieving_kinds(): array
{
    return ['allocation', 'application'];
}

/** Posting source types that only the authoritative open-item services may write. */
function pl_open_item_source_types(): array
{
    return ['open_item_recognition', 'open_item_settlement', 'open_item_batch_settlement', 'open_item_application', 'open_item_advance'];
}

function pl_get_open_item(int $actorId, int $companyId, int $bookId, int $itemId): array
{
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $itemId): array {
        pl_require_company_access($actorId, $companyId);
        pl_ledger_book($companyId, $bookId);
        return pl_open_item_state($companyId, $bookId, $itemId);
    });
}

function pl_oi_command(int $actorId, int $companyId, int $bookId, string $key, array $payload, callable $work): array
{
    $key = pl_request_key($key);
    $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $key, $hash, $work): array {
        pl_require_company_access($actorId, $companyId, true);
        pl_ledger_book($companyId, $bookId, true);
        $prior = DB::queryFirstRow('SELECT payload_hash, result_json FROM pl_open_item_commands WHERE company_id = %i AND book_id = %i AND request_key = %s FOR UPDATE', $companyId, $bookId, $key);
        if ($prior) {
            if (!hash_equals($prior['payload_hash'], $hash)) { throw new DomainException('This open-item request key has different content.'); }
            $result = json_decode($prior['result_json'], true, 512, JSON_THROW_ON_ERROR);
            ksort($result);
            return $result;
        }
        return pl_demo_with_document_capacity($companyId, $bookId, function () use ($companyId, $bookId, $actorId, $key, $hash, $work): array {
            $result = $work('open-item:' . hash('sha256', $key));
            ksort($result);
            DB::insert('pl_open_item_commands', ['company_id' => $companyId, 'book_id' => $bookId, 'actor_id' => $actorId, 'request_key' => $key, 'payload_hash' => $hash, 'result_json' => json_encode($result, JSON_THROW_ON_ERROR)]);
            return $result;
        });
    });
}

/** Actual input is frozen directly in the posting; a rate-table reference retains its own provenance. */
function pl_oi_rate(int $actorId, int $companyId, int $bookId, string $currency, string $date, ?string $override, ?int $rateId, string $type): array
{
    $book = pl_ledger_book($companyId, $bookId);
    $base = (string) $book['currency'];
    if ($currency === $base) {
        if ($override !== null && bccomp(pl_fx_rate($override), '1', 12) !== 0) { throw new DomainException('Domestic amounts use rate one.'); }
        if ($rateId !== null) { throw new DomainException('Domestic entries do not need a currency-rate reference.'); }
        return ['currency' => $currency, 'rate' => '1.000000000000', 'rate_type' => 'spot', 'rate_source_id' => null, 'rate_is_stale' => false, 'ic_counterparty_entity_id' => null];
    }
    if ($override !== null) {
        return ['currency' => $currency, 'rate' => pl_fx_rate($override), 'rate_type' => $type, 'rate_source_id' => null, 'rate_is_stale' => false, 'ic_counterparty_entity_id' => null];
    }
    $row = $rateId === null
        ? pl_currency_rate_lookup($actorId, $companyId, $bookId, $currency, $base, $date, 'spot')
        : DB::queryFirstRow('SELECT * FROM pl_currency_rates WHERE id = %i AND company_id = %i AND book_id = %i FOR SHARE', $rateId, $companyId, $bookId);
    if (!$row || $row['from_currency'] !== $currency || $row['to_currency'] !== $base || $row['rate_date'] > $date
        || !in_array($row['rate_type'], ['spot', 'actual'], true)) {
        throw new DomainException('Supply an applicable manual spot or actual rate on or before the posting date.');
    }
    return ['currency' => $currency, 'rate' => (string) $row['rate'], 'rate_type' => $row['rate_type'], 'rate_source_id' => (int) $row['id'], 'rate_is_stale' => $row['rate_date'] < $date, 'ic_counterparty_entity_id' => null];
}

function pl_oi_line(int $accountId, string $amountFc, string $amountBase, bool $debit, array $snapshot, string $description): array
{
    return ['account_id' => $accountId, 'description' => $description, 'debit' => $debit ? $amountBase : '0.0000', 'credit' => $debit ? '0.0000' : $amountBase, 'amount_fc' => $amountFc, 'amount_base' => $amountBase] + $snapshot;
}

function pl_open_item_recognize(int $actorId, int $companyId, int $bookId, array $input): array
{
    $data = [
        'action' => 'recognize', 'party_id' => pl_oi_id($input, 'party_id'), 'control_account_id' => pl_oi_id($input, 'control_account_id'),
        'offset_account_id' => pl_oi_id($input, 'offset_account_id'), 'currency' => pl_currency_code(pl_ledger_text($input['currency'] ?? null, 'Currency', 3)),
        'amount_fc' => pl_amount(pl_ledger_text($input['amount_fc'] ?? null, 'Foreign amount', 30)),
        'date' => pl_ledger_date(pl_ledger_text($input['date'] ?? null, 'Recognition date', 10)),
        'source_reference' => pl_ledger_text($input['source_reference'] ?? null, 'Source reference', 120),
        'description' => pl_ledger_text($input['description'] ?? null, 'Description', 500),
        'rate' => isset($input['rate']) ? pl_fx_rate(pl_ledger_text($input['rate'], 'Rate', 40)) : null,
        'rate_source_id' => isset($input['rate_source_id']) ? pl_oi_id($input, 'rate_source_id') : null,
    ];
    if (bccomp($data['amount_fc'], '0', 4) <= 0) { throw new DomainException('Recognition needs a positive amount.'); }
    return pl_oi_command($actorId, $companyId, $bookId, pl_ledger_text($input['idempotency_key'] ?? null, 'Request key', 128), $data,
        function (string $journalKey) use ($actorId, $companyId, $bookId, $data): array {
            $party = pl_get_party($actorId, $companyId, $bookId, $data['party_id']);
            $account = DB::queryFirstRow('SELECT a.* FROM pl_open_item_accounts t JOIN pl_accounts a ON a.id = t.account_id WHERE t.account_id = %i AND t.company_id = %i AND t.book_id = %i FOR SHARE', $data['control_account_id'], $companyId, $bookId);
            if (!$account || !in_array($account['role'], ['receivables', 'payables'], true)) { throw new DomainException('Activate an unused control account before recognition.'); }
            $receivable = $account['role'] === 'receivables';
            if (!(bool) ($party[$receivable ? 'is_customer' : 'is_vendor'] ?? false)) { throw new DomainException('The party must have the corresponding customer or vendor role.'); }
            $snapshot = pl_oi_rate($actorId, $companyId, $bookId, $data['currency'], $data['date'], $data['rate'], $data['rate_source_id'], 'spot');
            if (($party['linked_entity_id'] ?? null) !== null) { $snapshot['ic_counterparty_entity_id'] = (int) $party['linked_entity_id']; }
            $base = pl_fx_convert($data['amount_fc'], $snapshot['rate']);
            DB::insert('pl_open_items', ['company_id' => $companyId, 'book_id' => $bookId, 'party_id' => $data['party_id'], 'control_account_id' => $data['control_account_id'], 'direction' => $receivable ? 'receivable' : 'payable', 'currency' => $data['currency'], 'source_reference' => $data['source_reference'], 'created_by' => $actorId]);
            $itemId = (int) DB::insertId();
            $book = pl_ledger_book($companyId, $bookId);
            $journal = pl_post_journal_locked($actorId, $companyId, $bookId, ['date' => $data['date'], 'currency' => $book['currency'], 'source_type' => 'open_item_recognition', 'source_reference' => 'open-item:' . $itemId, 'idempotency_key' => $journalKey, 'description' => $data['description'], 'lines' => [
                pl_oi_line($data['control_account_id'], $data['amount_fc'], $base, $receivable, $snapshot, $data['description']),
                pl_oi_line($data['offset_account_id'], $data['amount_fc'], $base, !$receivable, $snapshot, $data['description']),
            ]]);
            return ['item_id' => $itemId, 'journal_id' => (int) $journal['id']];
        });
}

function pl_oi_allocated_base(array $item, string $amount): string
{
    if (!$item['recognition'] || $item['reversed'] || bccomp($amount, '0', 4) <= 0 || bccomp($amount, $item['remaining_fc'], 4) > 0) {
        throw new DomainException('The open item is reversed, unavailable or would be over-allocated.');
    }
    if (bccomp($amount, $item['remaining_fc'], 4) === 0) { return $item['remaining_base']; }
    // Prorate the remaining actual carrying amount; final allocation takes the exact residual.
    $value = bcdiv(bcmul($item['remaining_base'], $amount, 24), $item['remaining_fc'], 24);
    $rounded = pl_amount(bcadd($value, '0.00005', 4));
    if (bccomp($rounded, '0', 4) <= 0 || bccomp($rounded, $item['remaining_base'], 4) >= 0) {
        throw new DomainException('This partial allocation cannot be represented at ledger precision.');
    }
    return $rounded;
}

function pl_settle_open_item(int $actorId, int $companyId, int $bookId, array $input): array
{
    $data = [
        'action' => 'settle', 'item_id' => pl_oi_id($input, 'item_id'), 'bank_account_id' => pl_oi_id($input, 'bank_account_id'),
        // FX accounts are only needed when the frozen settlement produces a difference.
        'gain_account_id' => isset($input['gain_account_id']) && $input['gain_account_id'] !== '' ? pl_oi_id($input, 'gain_account_id') : null,
        'loss_account_id' => isset($input['loss_account_id']) && $input['loss_account_id'] !== '' ? pl_oi_id($input, 'loss_account_id') : null,
        'amount_fc' => pl_amount(pl_ledger_text($input['amount_fc'] ?? null, 'Allocated amount', 30)),
        'date' => pl_ledger_date(pl_ledger_text($input['date'] ?? null, 'Settlement date', 10)),
        'actual_rate' => isset($input['actual_rate']) ? pl_fx_rate(pl_ledger_text($input['actual_rate'], 'Actual rate', 40)) : null,
        'rate_source_id' => isset($input['rate_source_id']) ? pl_oi_id($input, 'rate_source_id') : null,
        'description' => pl_ledger_text($input['description'] ?? null, 'Description', 500),
    ];
    return pl_oi_command($actorId, $companyId, $bookId, pl_ledger_text($input['idempotency_key'] ?? null, 'Request key', 128), $data,
        function (string $journalKey) use ($actorId, $companyId, $bookId, $data): array {
            $item = pl_open_item_state($companyId, $bookId, $data['item_id']);
            $carrying = pl_oi_allocated_base($item, $data['amount_fc']);
            if ($data['date'] < $item['latest_activity_date']) { throw new DomainException('Settlement cannot precede the latest open-item activity.'); }
            $book = pl_ledger_book($companyId, $bookId);
            $bank = DB::queryFirstRow('SELECT * FROM pl_accounts WHERE id = %i AND company_id = %i AND book_id = %i FOR SHARE', $data['bank_account_id'], $companyId, $bookId);
            if (!$bank || $bank['role'] !== 'cash_bank' || !(bool) $bank['is_active']) { throw new DomainException('Choose an active cash/bank account in this book.'); }
            $snapshot = pl_oi_rate($actorId, $companyId, $bookId, $item['currency'], $data['date'], $data['actual_rate'], $data['rate_source_id'], 'actual');
            $settlementBase = pl_fx_convert($data['amount_fc'], $snapshot['rate']);
            $bankCurrency = $bank['currency'] ?? $book['currency'];
            if (!in_array($bankCurrency, [$book['currency'], $item['currency']], true)) { throw new DomainException('A third-currency bank conversion requires a separate conversion transaction.'); }
            $domestic = pl_oi_rate($actorId, $companyId, $bookId, $book['currency'], $data['date'], null, null, 'spot');
            $receipt = $item['direction'] === 'receivable';
            if (!$receipt && $bankCurrency !== $book['currency']) { throw new DomainException('Outgoing settlements require a functional-currency bank until foreign-bank carrying-value allocation is implemented.'); }
            $recognition = $item['recognition'];
            $historical = array_intersect_key($recognition, array_flip(['currency', 'rate', 'rate_type', 'rate_source_id', 'rate_is_stale', 'ic_counterparty_entity_id']));
            $historical['rate_source_id'] = $historical['rate_source_id'] === null ? null : (int) $historical['rate_source_id'];
            $historical['rate_is_stale'] = (bool) $historical['rate_is_stale'];
            $historical['ic_counterparty_entity_id'] = $historical['ic_counterparty_entity_id'] === null ? null : (int) $historical['ic_counterparty_entity_id'];
            $lines = [pl_oi_line((int) $item['control_account_id'], $data['amount_fc'], $carrying, !$receipt, $historical, 'Historic open-item carrying value'),
                pl_oi_line($data['bank_account_id'], $bankCurrency === $book['currency'] ? $settlementBase : $data['amount_fc'], $settlementBase, $receipt, $bankCurrency === $book['currency'] ? $domestic : $snapshot, $data['description'])];
            $difference = bcsub($settlementBase, $carrying, 4);
            if (bccomp($difference, '0', 4) !== 0) {
                $gain = ($receipt && bccomp($difference, '0', 4) > 0) || (!$receipt && bccomp($difference, '0', 4) < 0);
                $differenceAccount = $gain ? $data['gain_account_id'] : $data['loss_account_id'];
                if ($differenceAccount === null || !DB::queryFirstRow('SELECT id FROM pl_accounts WHERE id = %i AND company_id = %i AND book_id = %i AND type = %s AND is_active = 1 FOR SHARE', $differenceAccount, $companyId, $bookId, $gain ? 'income' : 'expense')) {
                    throw new DomainException('Choose a scoped, active realised gain/loss account when the settlement has an exchange difference.');
                }
                $magnitude = ltrim($difference, '-');
                $lines[] = pl_oi_line($differenceAccount, $magnitude, $magnitude, !$gain, $domestic, $gain ? 'Realised FX gain' : 'Realised FX loss');
            }
            $journal = pl_post_journal_locked($actorId, $companyId, $bookId, ['date' => $data['date'], 'currency' => $book['currency'], 'source_type' => 'open_item_settlement', 'source_reference' => 'open-item:' . $item['id'], 'idempotency_key' => $journalKey, 'description' => $data['description'], 'lines' => $lines], null, (int) $item['id']);
            return ['item_id' => (int) $item['id'], 'journal_id' => (int) $journal['id'], 'allocated_fc' => $data['amount_fc'], 'allocated_base' => $carrying, 'settlement_base' => $settlementBase,
                'transaction_currency' => $item['currency'], 'settlement_rate' => $snapshot['rate'], 'settlement_rate_type' => $snapshot['rate_type'],
                'settlement_rate_source_id' => $snapshot['rate_source_id'], 'settlement_rate_is_stale' => $snapshot['rate_is_stale']];
        });
}

/** Called by the posting funnel under its book lock, never an unchecked conversion override. */
function pl_open_item_validate_settlement_basis(int $companyId, int $bookId, array $payload, int $itemId): int
{
    if ($payload['source_type'] !== 'open_item_settlement' || $payload['source_reference'] !== 'open-item:' . $itemId) { throw new DomainException('Invalid settlement source identity.'); }
    $item = pl_open_item_state($companyId, $bookId, $itemId);
    $lines = array_values(array_filter($payload['lines'], static fn (array $line): bool => (int) $line['account_id'] === (int) $item['control_account_id']));
    if (count($lines) !== 1) { throw new DomainException('A settlement needs exactly one open-item control line.'); }
    $line = $lines[0];
    pl_open_item_validate_settlement_line($item, $line, $payload['date']);
    return (int) $item['control_account_id'];
}

function pl_open_item_validate_settlement_line(array $item, array $line, string $date): void
{
    $expected = pl_oi_allocated_base($item, $line['amount_fc']);
    $debit = $item['direction'] === 'payable';
    if ((int)$line['account_id'] !== (int)$item['control_account_id'] || $date < $item['latest_activity_date'] || $line['amount_base'] !== $expected
        || $line[$debit ? 'debit' : 'credit'] !== $expected) { throw new DomainException('Settlement must relieve the persisted historic carrying amount.'); }
    foreach (['currency', 'rate', 'rate_type', 'rate_source_id', 'rate_is_stale', 'ic_counterparty_entity_id'] as $field) {
        $matches = $field === 'rate_is_stale' ? (bool) $line[$field] === (bool) $item['recognition'][$field] : (string) $line[$field] === (string) $item['recognition'][$field];
        if (!$matches) { throw new DomainException('Settlement must retain the recognition currency snapshot.'); }
    }
}

/**
 * Validate every mapped carrying line and reject unmapped tracked-control lines.
 *
 * The map is `line index => ['item_id' => int, 'kind' => 'allocation'|'recognition']`.
 * An `allocation` entry relieves an existing open item at its own historic carrying
 * value. The single optional `recognition` entry is the unallocated remainder of the
 * payment (migration 037): a brand-new advance open item on the advances control,
 * in the opposite direction, measured at the payment's own rate. Because it is part of
 * the same journal, a receipt with a remainder is one voucher with one bank line.
 */
function pl_open_item_validate_batch_basis(int $companyId, int $bookId, array $payload, array $allocations): void
{
    $itemIds = array_column($allocations, 'item_id');
    $references = ['open_item_batch_settlement'=>'/^open-item-batch:[a-f0-9]{64}$/D',
        'open_item_application'=>'/^open-item-application:[a-f0-9]{64}$/D',
        'open_item_advance'=>'/^open-item-advance:[a-f0-9]{64}$/D'];
    if (!isset($references[$payload['source_type']]) || !preg_match($references[$payload['source_type']],$payload['source_reference'])
        || count($allocations)<1 || count($allocations)>pl_settlement_allocation_cap()+1 || count(array_unique($itemIds))!==count($itemIds)) {
        throw new DomainException('Invalid multi-item settlement source.');
    }
    if ($payload['source_type']==='open_item_application') { pl_open_item_validate_application_basis($companyId,$bookId,$payload,$allocations); return; }
    if ($payload['source_type']==='open_item_advance') { pl_open_item_validate_direct_advance_basis($companyId,$bookId,$payload,$allocations); return; }
    $scope = null; $remainders = 0;
    $tracked = array_map('intval',DB::queryFirstColumn('SELECT account_id FROM pl_open_item_accounts WHERE company_id=%i AND book_id=%i',$companyId,$bookId));
    foreach ($allocations as $index=>$entry) {
        if (!is_int($index) || !is_array($entry) || !is_int($entry['item_id']??null) || !in_array($entry['kind']??null,['allocation','recognition'],true) || !isset($payload['lines'][$index])) {
            throw new DomainException('Invalid allocation line mapping.');
        }
        $item=pl_open_item_state($companyId,$bookId,$entry['item_id']);
        $line=$payload['lines'][$index];
        if ($entry['kind']==='recognition') {
            $remainders++;
            if ($remainders>1) { throw new DomainException('A payment leaves at most one unallocated remainder.'); }
            pl_open_item_validate_advance_recognition_line($companyId,$bookId,$item,$line);
            // The remainder belongs to the same party and currency, on the opposite side.
            $identity=[(int)$item['party_id'],$item['currency'],$item['direction']==='payable'?'receivable':'payable'];
        } else {
            pl_open_item_validate_settlement_line($item,$line,$payload['date']);
            $identity=[(int)$item['party_id'],$item['currency'],$item['direction']];
        }
        $scope ??= $identity;
        if ($scope!==$identity) { throw new DomainException('One payment must use one party, currency and payment direction.'); }
    }
    foreach ($payload['lines'] as $index=>$line) {
        if (in_array($line['account_id'],$tracked,true) !== array_key_exists($index,$allocations)) {
            throw new DomainException('Every tracked control line must have exactly one allocation.');
        }
    }
}

/**
 * Unapplied credit recognised without a receipt behind it: a credit note that has no
 * original invoice (B39). Exactly one advance line; every other line is an offset of the
 * type that side of the credit belongs to - income for a customer credit, expense for a
 * supplier debit - and none of them may be a bank or a tracked control.
 */
function pl_open_item_validate_direct_advance_basis(int $companyId, int $bookId, array $payload, array $allocations): void
{
    if (count($allocations)!==1 || !isset($allocations[0]) || ($allocations[0]['kind']??null)!=='recognition' || !is_int($allocations[0]['item_id']??null)) {
        throw new DomainException('A directly recognised advance has exactly one advance line.');
    }
    $item=pl_open_item_state($companyId,$bookId,$allocations[0]['item_id']);
    pl_open_item_validate_advance_recognition_line($companyId,$bookId,$item,$payload['lines'][0]);
    // Finding 2 of the 1.2 internal accounting review: the offset line was never looked at
    // once the advance line had been checked, so equity, an owner's loan or an ordinary
    // expense account all posted. The comment above claimed the rule; this enforces it, in
    // the funnel, where every other open-item rule lives.
    $side=pl_advance_side_for_direction($item['direction']==='payable'?'receivable':'payable');
    $overridden=pl_advance_offset_override_claimed($allocations[0]);
    foreach ($payload['lines'] as $index=>$line) {
        if ($index===0) { continue; }
        pl_advance_assert_offset_account($side,DB::queryFirstRow('SELECT id,code,type,role,is_active FROM pl_accounts WHERE id=%i AND company_id=%i AND book_id=%i FOR SHARE',$line['account_id'],$companyId,$bookId),$overridden);
    }
}

/**
 * Applying unapplied credit: two controls, no bank line, no money moving (B39).
 *
 * Exactly one `application` entry relieves the advance item and one or more relieve the
 * documents it is applied to, all for one party and one currency, each at its own
 * historic carrying value. Because the journal never touches cash, a bank, till or any
 * untracked account on it would make it something other than an application, so every
 * line must be a mapped tracked-control line.
 */
function pl_open_item_validate_application_basis(int $companyId, int $bookId, array $payload, array $allocations): void
{
    // Every line is either a mapped control line or the realised exchange difference between
    // the two frozen rates. A cash or bank line would make this a payment, not an application.
    $tracked=array_map('intval',DB::queryFirstColumn('SELECT account_id FROM pl_open_item_accounts WHERE company_id=%i AND book_id=%i',$companyId,$bookId));
    foreach ($payload['lines'] as $index=>$line) {
        if (array_key_exists($index,$allocations)) {
            if (!in_array((int)$line['account_id'],$tracked,true)) { throw new DomainException('Every mapped application line must be an open-item control line.'); }
            continue;
        }
        $account=DB::queryFirstRow('SELECT role,type FROM pl_accounts WHERE id=%i AND company_id=%i AND book_id=%i FOR SHARE',$line['account_id'],$companyId,$bookId);
        if (!$account || in_array((int)$line['account_id'],$tracked,true) || $account['role']==='cash_bank' || !in_array($account['type'],['income','expense'],true)) {
            throw new DomainException('An application of unapplied credit moves no money: only its realised exchange difference may sit outside the controls.');
        }
    }
    $advances = 0; $party = null; $currency = null; $side = null; $documentDirections = [];
    foreach ($allocations as $index=>$entry) {
        if (!is_int($index) || !is_array($entry) || !is_int($entry['item_id']??null) || ($entry['kind']??null)!=='application' || !isset($payload['lines'][$index])) {
            throw new DomainException('Invalid allocation line mapping.');
        }
        $item=pl_open_item_state($companyId,$bookId,$entry['item_id']);
        pl_open_item_validate_settlement_line($item,$payload['lines'][$index],$payload['date']);
        $party ??= (int)$item['party_id']; $currency ??= $item['currency'];
        if ((int)$item['party_id']!==$party || $item['currency']!==$currency) { throw new DomainException('One application must use one party and one currency.'); }
        if ($item['nature']==='advance') {
            $advances++;
            $side = pl_advance_side_for_direction($item['direction']==='payable'?'receivable':'payable');
        } else { $documentDirections[] = $item['direction']; }
    }
    if ($advances !== 1 || $documentDirections === []) { throw new DomainException('An application uses exactly one advance and at least one document.'); }
    foreach ($documentDirections as $direction) {
        if (pl_advance_side_for_direction($direction) !== $side) { throw new DomainException('Customer credit applies to customer documents and supplier credit to supplier documents.'); }
    }
}

/**
 * The remainder line of a receipt, and the single line of a directly recognised advance.
 *
 * A fresh advance item, on a registered advances control, in its own direction, at the
 * frozen rate of this posting. Nothing historic is preserved here: unlike an allocation,
 * this is new money arriving, so the base amount must be the transaction amount
 * converted at the line's own rate.
 */
function pl_open_item_validate_advance_recognition_line(int $companyId, int $bookId, array $item, array $line): void
{
    if ($item['nature']!=='advance' || $item['entries']!==[]) { throw new DomainException('An advance may be recognised once, on its own new open item.'); }
    if (!DB::queryFirstField('SELECT account_id FROM pl_advance_accounts WHERE account_id=%i AND company_id=%i AND book_id=%i FOR SHARE',$item['control_account_id'],$companyId,$bookId)) {
        throw new DomainException('Unapplied credit belongs on a registered advances control account.');
    }
    if ((int)$line['account_id']!==(int)$item['control_account_id'] || $line['currency']!==$item['currency']) { throw new DomainException('The advances control and currency must match the advance item.'); }
    $side=$item['direction']==='receivable'?'debit':'credit';
    if (bccomp($line[$side],'0',4)<=0 || $line['amount_base']!==$line[$side] || pl_fx_convert($line['amount_fc'],$line['rate'])!==$line['amount_base']) {
        throw new DomainException('An advance is recognised as a positive amount at its own frozen rate.');
    }
}

function pl_open_item_assert_correction_allowed(int $companyId, int $bookId, int $journalId): void
{
    if (DB::queryFirstField('SELECT e.id FROM pl_open_item_entries e JOIN pl_journal_lines l ON l.id = e.journal_line_id WHERE l.journal_id = %i AND e.company_id = %i AND e.book_id = %i AND e.opening_document_id IS NOT NULL LIMIT 1 FOR SHARE', $journalId, $companyId, $bookId)) {
        throw new DomainException('Converted opening debt retains its journal basis; use reviewed correcting transactions rather than reversing the cutover.');
    }
    $entries = DB::query("SELECT e.* FROM pl_open_item_entries e JOIN pl_journal_lines l ON l.id = e.journal_line_id WHERE l.journal_id = %i AND e.company_id = %i AND e.book_id = %i AND e.kind = 'recognition'", $journalId, $companyId, $bookId);
    foreach ($entries as $entry) {
        $item = pl_open_item_state($companyId, $bookId, (int) $entry['item_id']);
        if ($item['recognition'] && (bccomp($item['remaining_fc'], $item['recognition']['amount_fc'], 4) !== 0 || bccomp($item['remaining_base'], $item['recognition']['amount_base'], 4) !== 0)) {
            throw new DomainException('Reverse the active allocations explicitly before reversing this recognition.');
        }
    }
}

function pl_open_item_assert_generic_reversal_allowed(int $companyId, int $bookId, int $journalId): void
{
    pl_open_item_assert_correction_allowed($companyId, $bookId, $journalId);
}

function pl_open_item_validate_posting(int $companyId, int $bookId, array $payload, ?int $reversalOf = null, ?int $settlementItemId = null): void
{
    $tracked = DB::query('SELECT account_id FROM pl_open_item_accounts WHERE company_id = %i AND book_id = %i FOR SHARE', $companyId, $bookId);
    $ids = array_map('intval', array_column($tracked, 'account_id'));
    $controls = array_values(array_filter($payload['lines'], static fn (array $line): bool => in_array((int) $line['account_id'], $ids, true)));
    if ($controls === []) {
        if (in_array($payload['source_type'], pl_open_item_source_types(), true)) { throw new DomainException('An open-item source must post to its tracked control account.'); }
        return;
    }
    if ($reversalOf !== null) {
        pl_open_item_assert_correction_allowed($companyId, $bookId, $reversalOf);
        $entries = DB::query('SELECT e.item_id FROM pl_open_item_entries e JOIN pl_journal_lines l ON l.id = e.journal_line_id WHERE l.journal_id = %i AND e.company_id = %i AND e.book_id = %i', $reversalOf, $companyId, $bookId);
        if ($entries === []) { throw new DomainException('Tracked control reversals require their open-item source.'); }
        foreach ($entries as $entry) {
            if ($payload['date'] < pl_open_item_state($companyId, $bookId, (int) $entry['item_id'])['latest_activity_date']) { throw new DomainException('A reversal cannot precede the latest open-item activity.'); }
        }
        return;
    }
    if (count($controls) !== 1 || !preg_match('/^open-item:([1-9][0-9]*)$/D', $payload['source_reference'], $match)) { throw new DomainException('Tracked control accounts require one explicit open-item source.'); }
    $item = pl_open_item_state($companyId, $bookId, (int) $match[1]);
    $line = $controls[0];
    if ((int) $line['account_id'] !== (int) $item['control_account_id'] || $line['currency'] !== $item['currency']) { throw new DomainException('The control account and currency must match the open item.'); }
    if ($payload['source_type'] === 'open_item_recognition') {
        if ($item['entries'] !== [] || bccomp($line[$item['direction'] === 'receivable' ? 'debit' : 'credit'], '0', 4) <= 0) { throw new DomainException('An open item may be recognized once with the correct direction.'); }
    } elseif ($payload['source_type'] === 'open_item_settlement' && $settlementItemId === (int) $item['id']) {
        pl_open_item_validate_settlement_basis($companyId, $bookId, $payload, $settlementItemId);
    } else { throw new DomainException('Use the internal open-item service for tracked control accounts.'); }
}

/** Atomic construction ties the only outstanding calculator to the exact posted GL lines. */
function pl_open_item_track_posting(int $companyId, int $bookId, int $journalId, array $payload, ?int $reversalOf = null, ?array $allocations = null): void
{
    if ($allocations !== null) {
        foreach ($allocations as $index=>$entry) {
            $lineId=DB::queryFirstField('SELECT id FROM pl_journal_lines WHERE journal_id=%i AND company_id=%i AND book_id=%i AND line_number=%i',$journalId,$companyId,$bookId,$index+1);
            DB::insert('pl_open_item_entries',['company_id'=>$companyId,'book_id'=>$bookId,'item_id'=>$entry['item_id'],'kind'=>$entry['kind'],'journal_line_id'=>$lineId,'reversal_of_id'=>null]);
        }
        return;
    }
    if ($reversalOf !== null) {
        $entries = DB::query('SELECT e.*, l.line_number FROM pl_open_item_entries e JOIN pl_journal_lines l ON l.id = e.journal_line_id WHERE l.journal_id = %i AND e.company_id = %i AND e.book_id = %i', $reversalOf, $companyId, $bookId);
        $mirror = ['recognition' => 'recognition_reversal', 'allocation' => 'allocation_reversal', 'application' => 'application_reversal'];
        foreach ($entries as $entry) {
            if (!isset($mirror[$entry['kind']])) { throw new DomainException('An open-item reversal cannot itself be reversed.'); }
            $lineId = DB::queryFirstField('SELECT id FROM pl_journal_lines WHERE journal_id = %i AND line_number = %i', $journalId, $entry['line_number']);
            DB::insert('pl_open_item_entries', ['company_id' => $companyId, 'book_id' => $bookId, 'item_id' => $entry['item_id'], 'kind' => $mirror[$entry['kind']], 'journal_line_id' => $lineId, 'reversal_of_id' => $entry['id']]);
        }
        return;
    }
    if (!in_array($payload['source_type'], ['open_item_recognition', 'open_item_settlement'], true)) { return; }
    $itemId = (int) substr($payload['source_reference'], strlen('open-item:'));
    $item = pl_open_item_state($companyId, $bookId, $itemId);
    $lineId = DB::queryFirstField('SELECT id FROM pl_journal_lines WHERE journal_id = %i AND account_id = %i', $journalId, $item['control_account_id']);
    DB::insert('pl_open_item_entries', ['company_id' => $companyId, 'book_id' => $bookId, 'item_id' => $itemId, 'kind' => $payload['source_type'] === 'open_item_recognition' ? 'recognition' : 'allocation', 'journal_line_id' => $lineId, 'reversal_of_id' => null]);
}
