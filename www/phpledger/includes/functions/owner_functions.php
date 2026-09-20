<?php
declare(strict_types=1);

/**
 * Owner and partner transactions (owner decision B61, A3).
 *
 * The owner could not find a way to put cash into the business as a loan or as
 * an equity injection, and no sample showed drawings. These four movements are
 * now first class:
 *
 *   capital_introduced   Dr cash/bank            Cr owner's capital (equity)
 *   owner_loan_received  Dr cash/bank            Cr owner's loan account (liability)
 *   owner_loan_repaid    Dr owner's loan account Cr cash/bank
 *   drawings             Dr drawings (contra-equity) Cr cash/bank
 *
 * Every one of them is an ordinary journal through `pl_post_journal()`: the
 * same period check, the same idempotency key, the same immutability, and a
 * correction is the same linked reversal. Nothing here writes a journal line
 * of its own, and there is no second ledger (B54).
 *
 * An owner's loan is a liability, not equity: the business owes the money back
 * and the balance belongs above the equity section, not inside it. Drawings
 * are a contra-equity account rather than a debit to capital so the year's
 * withdrawals stay readable on their own line (B60).
 */

/** @return array<string,array{label:string, help:string}> */
function pl_owner_transaction_kinds(): array
{
    return [
        'capital_introduced' => ['label' => 'Capital introduced', 'help' => 'The owner or a partner puts money into the business as equity. It is not repayable.'],
        'owner_loan_received' => ['label' => 'Owner loan to the business', 'help' => 'The owner lends money to the business. The business owes it back, so it is a liability.'],
        'owner_loan_repaid' => ['label' => 'Owner loan repayment', 'help' => 'The business repays part or all of the owner\'s loan.'],
        'drawings' => ['label' => 'Drawings', 'help' => 'The owner takes money out for personal use. It reduces equity and is not a business expense.'],
    ];
}

/** The chart accounts each owner movement needs, resolved from the book's own chart. */
function pl_owner_accounts(int $companyId, int $bookId): array
{
    $rows = DB::query("SELECT id, code, name, type, role, semantic_key, is_contra FROM pl_accounts WHERE company_id = %i AND book_id = %i AND is_active = 1 ORDER BY code", $companyId, $bookId);
    $accounts = ['cash' => [], 'capital' => [], 'drawings' => [], 'loan' => []];
    foreach ($rows as $row) {
        $row['id'] = (int) $row['id'];
        $row['is_contra'] = (bool) $row['is_contra'];
        if (!pl_account_is_postable($companyId, $bookId, (string) $row['code'])) { continue; }
        if ($row['type'] === 'asset' && $row['role'] === 'cash_bank') { $accounts['cash'][] = $row; }
        if ($row['type'] === 'equity' && !$row['is_contra']) { $accounts['capital'][] = $row; }
        if ($row['type'] === 'equity' && $row['is_contra']) { $accounts['drawings'][] = $row; }
        if ($row['type'] === 'liability' && str_starts_with((string) $row['semantic_key'], 'core.liability.owner_loan')) { $accounts['loan'][] = $row; }
    }
    return $accounts;
}

/** Which side of which account each kind needs; the single source of the journals. */
function pl_owner_transaction_plan(string $kind): array
{
    return match ($kind) {
        'capital_introduced' => ['debit' => 'cash', 'credit' => 'capital'],
        'owner_loan_received' => ['debit' => 'cash', 'credit' => 'loan'],
        'owner_loan_repaid' => ['debit' => 'loan', 'credit' => 'cash'],
        'drawings' => ['debit' => 'drawings', 'credit' => 'cash'],
        default => throw new DomainException('Choose an owner transaction to record.'),
    };
}

/**
 * Post one owner movement through the central posting service.
 *
 * The input is untrusted request data, so every field is checked here rather
 * than assumed: `kind`, `date`, `amount`, `creation_key`, and the optional
 * `cash_account_id`, `owner_account_id`, `partner_id` and `description`.
 *
 * @param array<string,mixed> $input
 */
function pl_post_owner_transaction(int $actorId, int $companyId, int $bookId, array $input): array
{
    $kind = is_string($input['kind'] ?? null) ? $input['kind'] : '';
    $plan = pl_owner_transaction_plan($kind);
    $date = pl_ledger_date(pl_ledger_text($input['date'] ?? null, 'Transaction date', 10));
    $amount = pl_amount(is_string($input['amount'] ?? null) ? $input['amount'] : '');
    if (bccomp($amount, '0', 4) <= 0) { throw new DomainException('Enter an amount greater than zero.'); }
    $description = pl_ledger_text($input['description'] ?? pl_owner_transaction_kinds()[$kind]['label'], 'Description', 500);
    $key = pl_request_key(pl_ledger_text($input['creation_key'] ?? null, 'Request identity', 64));
    $cashAccountId = $input['cash_account_id'] ?? null;
    $ownerAccountId = $input['owner_account_id'] ?? null;
    $partnerId = $input['partner_id'] ?? null;
    foreach (['cash_account_id' => $cashAccountId, 'owner_account_id' => $ownerAccountId, 'partner_id' => $partnerId] as $label => $value) {
        if ($value !== null && (!is_int($value) || $value < 1)) { throw new DomainException('Choose a valid ' . str_replace('_', ' ', $label) . '.'); }
    }
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $kind, $plan, $date, $amount, $description, $key, $cashAccountId, $ownerAccountId, $partnerId): array {
        pl_require_company_access($actorId, $companyId, true);
        pl_ledger_book($companyId, $bookId, true);
        $available = pl_owner_accounts($companyId, $bookId);
        $ownerSide = $plan['debit'] === 'cash' ? $plan['credit'] : $plan['debit'];
        if ($partnerId !== null) {
            $partner = pl_get_owner_partner($actorId, $companyId, $bookId, $partnerId);
            $ownerAccountId = match ($ownerSide) {
                'capital' => $partner['capital_account_id'],
                'drawings' => $partner['drawings_account_id'],
                default => $partner['loan_account_id'],
            } ?? $ownerAccountId;
            if ($ownerAccountId === null) {
                throw new DomainException('This partner has no ' . $ownerSide . ' account yet. Add one to the chart and link it on the partner record.');
            }
        }
        $cash = pl_owner_pick_account($available['cash'], $cashAccountId, 'cash or bank');
        $owner = pl_owner_pick_account($available[$ownerSide], $ownerAccountId, pl_owner_side_label($ownerSide));
        $debitId = $plan['debit'] === 'cash' ? $cash['id'] : $owner['id'];
        $creditId = $plan['credit'] === 'cash' ? $cash['id'] : $owner['id'];
        return pl_post_journal($actorId, $companyId, $bookId, [
            'date' => $date, 'currency' => (string) pl_ledger_book($companyId, $bookId)['currency'],
            'source_type' => 'owner_transaction', 'source_reference' => $kind . ':' . $key,
            'idempotency_key' => 'owner:' . $key, 'description' => $description,
            'lines' => [
                ['account_id' => $debitId, 'debit' => $amount, 'credit' => '0', 'description' => $description],
                ['account_id' => $creditId, 'debit' => '0', 'credit' => $amount, 'description' => $description],
            ],
        ]);
    });
}

function pl_owner_side_label(string $side): string
{
    return ['capital' => 'owner capital', 'drawings' => 'drawings', 'loan' => 'owner loan'][$side] ?? $side;
}

/** @param array<int,array<string,mixed>> $choices */
function pl_owner_pick_account(array $choices, ?int $requested, string $label): array
{
    if ($choices === []) {
        throw new DomainException('This chart has no ' . $label . ' account yet. Add one to the chart of accounts before recording owner transactions.');
    }
    if ($requested === null) { return $choices[0]; }
    foreach ($choices as $choice) {
        if ((int) $choice['id'] === $requested) { return $choice; }
    }
    throw new DomainException('Choose a ' . $label . ' account from this book\'s chart.');
}

/** A correction is the same linked reversal every other posted journal uses. */
function pl_reverse_owner_transaction(int $actorId, int $companyId, int $bookId, int $journalId, ?string $date, string $reason): array
{
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $journalId, $date, $reason): array {
        $journal = pl_get_journal($actorId, $companyId, $bookId, $journalId);
        if ($journal['source_type'] !== 'owner_transaction') {
            throw new DomainException('This journal was not recorded as an owner transaction. Reverse it from where it was posted.');
        }
        return pl_reverse_journal($actorId, $companyId, $bookId, $journalId, $date, 'owner:' . $journalId . ':reverse', $reason);
    });
}

/** @return array<int,array<string,mixed>> */
function pl_list_owner_transactions(int $actorId, int $companyId, int $bookId, int $limit = 50): array
{
    pl_require_company_access($actorId, $companyId);
    pl_ledger_book($companyId, $bookId);
    $limit = max(1, min(200, $limit));
    $rows = DB::query("SELECT j.id, j.journal_date, j.description, j.source_reference, r.id AS reversal_journal_id,
        COALESCE(SUM(l.debit), 0) AS amount
        FROM pl_journals j
        JOIN pl_journal_lines l ON l.journal_id = j.id AND l.company_id = j.company_id AND l.book_id = j.book_id
        LEFT JOIN pl_journals r ON r.reversal_of_id = j.id
        WHERE j.company_id = %i AND j.book_id = %i AND j.source_type = 'owner_transaction'
        GROUP BY j.id, j.journal_date, j.description, j.source_reference, r.id
        ORDER BY j.journal_date DESC, j.id DESC LIMIT %i", $companyId, $bookId, $limit);
    $kinds = pl_owner_transaction_kinds();
    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['reversal_journal_id'] = $row['reversal_journal_id'] === null ? null : (int) $row['reversal_journal_id'];
        $row['kind'] = strstr((string) $row['source_reference'], ':', true) ?: (string) $row['source_reference'];
        $row['kind_label'] = $kinds[$row['kind']]['label'] ?? 'Owner transaction';
        $row['amount'] = bcadd((string) $row['amount'], '0', 4);
        $row['status'] = $row['reversal_journal_id'] === null ? 'posted' : 'reversed';
    }
    unset($row);
    return $rows;
}

/**
 * Real-time owner's equity (A3): what the owner put in, what they took out and
 * what the business still owes them, read straight from posted journals.
 */
function pl_owner_equity_movements(int $actorId, int $companyId, int $bookId, string $asOf): array
{
    pl_require_company_access($actorId, $companyId);
    pl_ledger_date($asOf);
    $rows = DB::query("SELECT a.id, a.code, a.name, a.type, a.is_contra, a.semantic_key,
        COALESCE(SUM(CASE WHEN j.id IS NOT NULL THEN l.debit ELSE 0 END), 0) AS debit,
        COALESCE(SUM(CASE WHEN j.id IS NOT NULL THEN l.credit ELSE 0 END), 0) AS credit
        FROM pl_accounts a
        LEFT JOIN pl_journal_lines l ON l.account_id = a.id AND l.company_id = a.company_id AND l.book_id = a.book_id
        LEFT JOIN pl_journals j ON j.id = l.journal_id AND j.company_id = a.company_id AND j.book_id = a.book_id AND j.journal_date <= %s
        WHERE a.company_id = %i AND a.book_id = %i AND (a.type = 'equity' OR a.semantic_key LIKE 'core.liability.owner_loan%')
        GROUP BY a.id, a.code, a.name, a.type, a.is_contra, a.semantic_key ORDER BY a.code", $asOf, $companyId, $bookId);
    $movements = ['capital' => [], 'drawings' => [], 'loans' => []];
    $totals = ['capital' => '0.0000', 'drawings' => '0.0000', 'loans' => '0.0000'];
    foreach ($rows as $row) {
        $credit = bcsub((string) $row['credit'], (string) $row['debit'], 4);
        $entry = ['id' => (int) $row['id'], 'code' => $row['code'], 'name' => $row['name']];
        if ($row['type'] === 'equity' && !(bool) $row['is_contra']) {
            $entry['amount'] = $credit;
            $movements['capital'][] = $entry;
            $totals['capital'] = bcadd($totals['capital'], $credit, 4);
        } elseif ($row['type'] === 'equity') {
            // Drawings carry a debit balance; report the amount withdrawn as a positive figure.
            $entry['amount'] = bcsub('0', $credit, 4);
            $movements['drawings'][] = $entry;
            $totals['drawings'] = bcadd($totals['drawings'], $entry['amount'], 4);
        } else {
            $entry['amount'] = $credit;
            $movements['loans'][] = $entry;
            $totals['loans'] = bcadd($totals['loans'], $credit, 4);
        }
    }
    return $movements + ['as_of' => $asOf, 'total_capital' => $totals['capital'], 'total_drawings' => $totals['drawings'],
        'total_owner_loans' => $totals['loans'], 'net_owner_equity' => bcsub($totals['capital'], $totals['drawings'], 4)];
}

/* ---------------------------------------------------------------------------
 * Partners (A3). A partner is a name attached to chart accounts and a
 * profit-sharing ratio. Profit *allocation* is not posted from here: see
 * docs/accounting/OWNER-TRANSACTIONS.md, "Left for the accountant".
 * ------------------------------------------------------------------------ */

/** @return array<int,array<string,mixed>> */
function pl_list_owner_partners(int $actorId, int $companyId, int $bookId): array
{
    pl_require_company_access($actorId, $companyId);
    pl_ledger_book($companyId, $bookId);
    $rows = DB::query('SELECT p.*, c.code AS capital_code, c.name AS capital_name FROM pl_owner_partners p JOIN pl_accounts c ON c.id = p.capital_account_id WHERE p.company_id = %i AND p.book_id = %i ORDER BY p.name', $companyId, $bookId);
    return array_map('pl_owner_partner_view', $rows);
}

function pl_get_owner_partner(int $actorId, int $companyId, int $bookId, int $id): array
{
    pl_require_company_access($actorId, $companyId);
    pl_ledger_book($companyId, $bookId);
    $row = DB::queryFirstRow('SELECT p.*, c.code AS capital_code, c.name AS capital_name FROM pl_owner_partners p JOIN pl_accounts c ON c.id = p.capital_account_id WHERE p.id = %i AND p.company_id = %i AND p.book_id = %i FOR SHARE', $id, $companyId, $bookId);
    if (!$row) { throw new DomainException('This partner is not available in the selected company and book.'); }
    return pl_owner_partner_view($row);
}

function pl_owner_partner_view(array $row): array
{
    foreach (['id', 'company_id', 'book_id', 'capital_account_id', 'revision'] as $field) { $row[$field] = (int) $row[$field]; }
    foreach (['drawings_account_id', 'loan_account_id'] as $field) { $row[$field] = $row[$field] === null ? null : (int) $row[$field]; }
    $row['is_active'] = (bool) $row['is_active'];
    $row['profit_share'] = bcadd((string) $row['profit_share'], '0', 6);
    return $row;
}

/**
 * Record a partner and their profit-sharing ratio. The ratios of the active
 * partners must total exactly 1 so that a later allocation, whoever posts it,
 * cannot silently lose or duplicate a share.
 */
function pl_save_owner_partner(int $actorId, int $companyId, int $bookId, array $input, ?int $id = null, ?int $revision = null): array
{
    $name = pl_ledger_text($input['name'] ?? null, 'Partner name', 160);
    $share = is_string($input['profit_share'] ?? null) ? $input['profit_share'] : '';
    if (!preg_match('/^(?:0(?:\.[0-9]{1,6})?|1(?:\.0{1,6})?)$/D', $share)) {
        throw new DomainException('Enter a profit-sharing ratio between 0 and 1, for example 0.5 for half.');
    }
    $share = bcadd($share, '0', 6);
    if (!is_bool($input['is_active'] ?? null)) { throw new DomainException('Choose whether this partner is active.'); }
    $active = $input['is_active'];
    $capital = $input['capital_account_id'] ?? null;
    if (!is_int($capital) || $capital < 1) { throw new DomainException('Choose the partner\'s capital account.'); }
    $drawings = $input['drawings_account_id'] ?? null;
    $loan = $input['loan_account_id'] ?? null;
    foreach ([$drawings, $loan] as $optional) {
        if ($optional !== null && (!is_int($optional) || $optional < 1)) { throw new DomainException('Choose a valid partner account.'); }
    }
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $id, $revision, $name, $share, $active, $capital, $drawings, $loan): array {
        pl_require_company_access($actorId, $companyId, true);
        pl_ledger_book($companyId, $bookId, true);
        $available = pl_owner_accounts($companyId, $bookId);
        pl_owner_pick_account($available['capital'], $capital, 'owner capital');
        if ($drawings !== null) { pl_owner_pick_account($available['drawings'], $drawings, 'drawings'); }
        if ($loan !== null) { pl_owner_pick_account($available['loan'], $loan, 'owner loan'); }
        $data = ['name' => $name, 'capital_account_id' => $capital, 'drawings_account_id' => $drawings,
            'loan_account_id' => $loan, 'profit_share' => $share, 'is_active' => $active];
        if ($id === null) {
            DB::insert('pl_owner_partners', $data + ['company_id' => $companyId, 'book_id' => $bookId]);
            $id = (int) DB::insertId();
        } else {
            $before = pl_get_owner_partner($actorId, $companyId, $bookId, $id);
            if ($revision !== $before['revision']) { throw new DomainException('Someone changed this partner. Reload the latest version before applying your changes.'); }
            DB::update('pl_owner_partners', $data + ['revision' => $before['revision'] + 1], 'id = %i AND company_id = %i AND book_id = %i', $id, $companyId, $bookId);
        }
        $total = '0.000000';
        foreach (DB::queryFirstColumn('SELECT profit_share FROM pl_owner_partners WHERE company_id = %i AND book_id = %i AND is_active = 1', $companyId, $bookId) as $value) {
            $total = bcadd($total, (string) $value, 6);
        }
        // Partners are entered one at a time, so a running total below 1 is simply incomplete;
        // a total above 1 would allocate more than the whole profit and is refused outright.
        if (bccomp($total, '1', 6) > 0) {
            throw new DomainException('The active partners\' profit-sharing ratios cannot total more than 1. They would total ' . $total . '.');
        }
        return pl_get_owner_partner($actorId, $companyId, $bookId, $id);
    });
}

/** Do the active partners' ratios account for the whole profit yet? */
function pl_owner_shares_complete(int $actorId, int $companyId, int $bookId): bool
{
    pl_require_company_access($actorId, $companyId);
    pl_ledger_book($companyId, $bookId);
    $total = '0.000000';
    foreach (DB::queryFirstColumn('SELECT profit_share FROM pl_owner_partners WHERE company_id = %i AND book_id = %i AND is_active = 1', $companyId, $bookId) as $value) {
        $total = bcadd($total, (string) $value, 6);
    }
    return bccomp($total, '1', 6) === 0;
}

/**
 * Each partner's capital, drawings and loan position, for the equity section
 * and the partners screen. Profit for the period is deliberately absent.
 */
function pl_owner_partner_positions(int $actorId, int $companyId, int $bookId, string $asOf): array
{
    $movements = pl_owner_equity_movements($actorId, $companyId, $bookId, $asOf);
    $byAccount = [];
    foreach (['capital', 'drawings', 'loans'] as $section) {
        foreach ($movements[$section] as $entry) { $byAccount[$section][(int) $entry['id']] = $entry['amount']; }
    }
    $positions = [];
    foreach (pl_list_owner_partners($actorId, $companyId, $bookId) as $partner) {
        $capital = $byAccount['capital'][$partner['capital_account_id']] ?? '0.0000';
        $drawings = $partner['drawings_account_id'] === null ? '0.0000' : ($byAccount['drawings'][$partner['drawings_account_id']] ?? '0.0000');
        $loan = $partner['loan_account_id'] === null ? '0.0000' : ($byAccount['loans'][$partner['loan_account_id']] ?? '0.0000');
        $positions[] = $partner + ['capital' => $capital, 'drawings' => $drawings, 'loan' => $loan,
            'net_capital' => bcsub($capital, $drawings, 4)];
    }
    return $positions;
}
