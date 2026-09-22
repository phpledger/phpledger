<?php
declare(strict_types=1);

/**
 * 1.3 M17, issue #93: cash counts.
 *
 * One document, for one money account, at one moment: what was counted, what the books say,
 * and the difference posted through the ordinary posting service to a cash over-and-short
 * account. The M9 settlement sheet and any later shift or day close use this rather than
 * carrying a count of their own.
 *
 * **"Cash-role account" is `cash_bank` here, and that is the whole set.** pl_save_account()
 * accepts eight role names and `cash_bank` is the only money one — there is no separate `cash`
 * role and no separate `bank` role in this schema. A drawer, a petty-cash tin, van cash and a
 * bank account are all `cash_bank` accounts that differ by name, so the issue's "any cash-role
 * account (drawer, petty cash, van cash)" is exactly the accounts this file offers. It is the
 * same set bank reconciliation works on, which is the point: one definition of "money account".
 *
 * **Money is bcmath at four decimal places throughout.** Nothing here converts through a float,
 * including the denomination working, where quantity is an integer and the extension is bcmul.
 *
 * **The book balance is the balance at the end of `count_date`.** The ledger's granularity is a
 * date, not a timestamp, so there is no such thing as the book balance at 18:04. `counted_at`
 * records the clock time as evidence of when the drawer was actually counted; the comparison
 * and the posting are both on `count_date`. A count taken mid-day and recorded immediately is
 * therefore compared with the books as they stand at that instant, which is the ordinary day
 * or shift close this document exists for.
 */

/** The key the over-and-short account is found by. B88: a leaf key, never `core.group.*`. */
function pl_cash_over_short_key(): string
{
    return 'core.expense.cash_over_short';
}

/** The money accounts a count can be recorded for. */
function pl_cash_count_accounts(int $actorId, int $companyId, int $bookId): array
{
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId): array {
        pl_require_company_access($actorId, $companyId);
        pl_ledger_book($companyId, $bookId);
        $rows = DB::query("SELECT id, code, name FROM pl_accounts WHERE company_id = %i AND book_id = %i AND role = 'cash_bank' AND type = 'asset' AND is_active = 1 ORDER BY code FOR SHARE", $companyId, $bookId);
        $accounts = [];
        foreach ($rows as $row) {
            if (!pl_account_is_postable($companyId, $bookId, (string) $row['code'])) {
                continue;
            }
            $accounts[] = ['id' => (int) $row['id'], 'code' => (string) $row['code'], 'name' => (string) $row['name']];
        }
        return $accounts;
    });
}

/**
 * The cash over-and-short account, provisioned if this book has none.
 *
 * Found by `semantic_key` and never by name or number (B88). A book born on the 1.2.0 chart
 * package has it from birth and migration 047 back-filled every book that existed before;
 * what is left is a hand-built or prior-foundation chart, and for those this provisions a real
 * account under the operating-expense group rather than refusing to work — the fallback B88
 * requires — and never falls back on General expenses, because posting drawer differences into
 * the same account as rent and electricity is a misclassification nobody would find again.
 *
 * Must be called with the book already locked; it writes.
 */
function pl_cash_over_short_account(int $actorId, int $companyId, int $bookId): int
{
    $found = DB::queryFirstRow('SELECT id, type, is_active, code FROM pl_accounts WHERE company_id = %i AND book_id = %i AND semantic_key = %s FOR UPDATE',
        $companyId, $bookId, pl_cash_over_short_key());
    if ($found !== null) {
        if (!$found['is_active'] || $found['type'] !== 'expense') { throw new DomainException('Reactivate the cash over-and-short expense account before recording a difference.'); }
        if (!pl_account_is_postable($companyId, $bookId, (string) $found['code'])) {
            throw new DomainException('The cash over-and-short account has gained sub-accounts, so it can no longer be posted to. Point the purpose at one of them.');
        }
        return (int) $found['id'];
    }
    $code = pl_cash_over_short_code($companyId, $bookId);
    $account = pl_save_account($actorId, $companyId, $bookId, [
        'code' => $code, 'name' => 'Cash over and short', 'type' => 'expense',
        'is_active' => true, 'is_contra' => false, 'is_monetary' => false,
        'reason' => 'Cash counts provisioned the over-and-short account this book had no equivalent of',
        'creation_key' => 'cash-over-short:' . $bookId,
    ]);
    // pl_save_account() does not write a semantic key — that column is set by the chart
    // installer and by the prior-foundation review — so the key is attached here, which is what
    // makes the account findable the next time and keeps uq_account_semantic honest.
    DB::update('pl_accounts', ['semantic_key' => pl_cash_over_short_key()], 'id = %i AND company_id = %i AND book_id = %i', (int) $account['id'], $companyId, $bookId);
    return (int) $account['id'];
}

/** A free code inside this book's own operating-expense group. */
function pl_cash_over_short_code(int $companyId, int $bookId): string
{
    if (function_exists('pl_asset_next_account_code')) {
        // The one existing allocator for exactly this job: it reads the group number off
        // whatever account carries the heading key, so it never assumes a number, and it opens
        // a group of its own only when the chart has no such heading.
        return pl_asset_next_account_code($companyId, $bookId, 'expense', false, 'core.group.expense.operating');
    }
    // Reached only if asset_functions.php is not loaded, which bootstrap.php always does.
    $used = DB::queryFirstField("SELECT MAX(CAST(SUBSTRING(code, 3, 3) AS UNSIGNED)) FROM pl_accounts WHERE company_id = %i AND book_id = %i AND type = 'expense' AND code LIKE '_-___-_____-__'", $companyId, $bookId);
    return pl_account_code_format(5, ((int) $used ?: 90) + 10, 10001, 0);
}

/** The book balance of one account at the end of a date, as a signed 4dp decimal string. */
function pl_cash_account_balance(int $companyId, int $bookId, int $accountId, string $asOf): string
{
    $row = DB::queryFirstRow('SELECT COALESCE(SUM(l.debit), 0) AS debit, COALESCE(SUM(l.credit), 0) AS credit
        FROM pl_journal_lines l JOIN pl_journals j ON j.id = l.journal_id AND j.company_id = l.company_id AND j.book_id = l.book_id
        WHERE l.company_id = %i AND l.book_id = %i AND l.account_id = %i AND j.journal_date <= %s FOR SHARE',
        $companyId, $bookId, $accountId, $asOf);
    return bcsub(bcadd((string) $row['debit'], '0', 4), bcadd((string) $row['credit'], '0', 4), 4);
}

/**
 * The denomination working, if there is one, proved to sum to the counted amount.
 *
 * @return array{lines:list<array{line_number:int, denomination:string, quantity:int, amount:string}>, total:string}
 */
function pl_cash_count_denominations(mixed $input): array
{
    if (!is_array($input) || $input === []) {
        return ['lines' => [], 'total' => '0.0000'];
    }
    if (!array_is_list($input) || count($input) > 60) {
        throw new DomainException('A denomination breakdown is a list of at most 60 rows.');
    }
    $lines = [];
    $total = '0.0000';
    $seen = [];
    foreach ($input as $row) {
        if (!is_array($row)) {
            throw new DomainException('Each denomination row needs a note or coin value and how many of them were counted.');
        }
        $denomination = pl_cash_amount(pl_ledger_text($row['denomination'] ?? null, 'Denomination', 21));
        $quantity = $row['quantity'] ?? null;
        if (is_string($quantity) && preg_match('/^\d{1,9}$/D', $quantity)) { $quantity = (int) $quantity; }
        if (!is_int($quantity) || $quantity < 1 || $quantity > 999999999) {
            throw new DomainException('A denomination is counted a whole number of times, at least once.');
        }
        if (bccomp($denomination, '0', 4) <= 0) {
            throw new DomainException('A denomination is worth more than nothing.');
        }
        if (in_array($denomination, $seen, true)) {
            throw new DomainException('Each note or coin value appears once in a count. Add the quantities together.');
        }
        $seen[] = $denomination;
        $amount = bcmul($denomination, (string) $quantity, 4);
        $lines[] = ['line_number' => count($lines) + 1, 'denomination' => $denomination, 'quantity' => $quantity, 'amount' => $amount];
        $total = bcadd($total, $amount, 4);
    }
    return ['lines' => $lines, 'total' => $total];
}

/**
 * Record a cash count and post its difference.
 *
 * A count that agrees posts nothing and is still recorded: "we counted and it agreed" is the
 * evidence a close needs, and a document that only exists when something went wrong is a
 * document nobody trusts. `journal_id` is NULL for such a count, which is the difference
 * between "no difference" and "difference not yet posted" — there is no such state here,
 * because the count and its posting are one transaction.
 */
function pl_record_cash_count(int $actorId, int $companyId, int $bookId, array $input): array
{
    $accountId = (int) ($input['account_id'] ?? 0);
    if ($accountId < 1) {
        throw new DomainException('Choose the cash or bank account that was counted.');
    }
    $countDate = pl_ledger_date(pl_ledger_text($input['count_date'] ?? null, 'Count date', 10));
    $countedAt = pl_ledger_text($input['counted_at'] ?? ($countDate . ' 00:00:00'), 'Time of the count', 19);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/D', $countedAt)) {
        throw new DomainException('Record when the count was taken as a date and a time.');
    }
    $countedAt = str_replace('T', ' ', $countedAt) . (strlen($countedAt) === 16 ? ':00' : '');
    $time = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $countedAt, new DateTimeZone('UTC'));
    if (!$time || $time->format('Y-m-d H:i:s') !== $countedAt) { throw new DomainException('Choose a valid count timestamp.'); }
    $counted = pl_cash_amount(pl_amount(pl_ledger_text($input['counted_amount'] ?? null, 'Counted amount', 21)));
    $note = pl_ledger_text($input['note'] ?? '', 'Note', 500, false);
    $creationKey = pl_ledger_text($input['creation_key'] ?? null, 'Request identity', 100);
    if (!preg_match('/^[A-Za-z0-9._:-]{1,100}$/D', $creationKey)) {
        throw new DomainException('Use a valid request identity for this count.');
    }
    $denominations = pl_cash_count_denominations($input['denominations'] ?? null);
    if ($denominations['lines'] !== [] && bccomp($denominations['total'], $counted, 4) !== 0) {
        throw new DomainException('The notes and coins counted add up to ' . $denominations['total'] . ', not the ' . $counted . ' entered as the counted amount.');
    }
    $hash = hash('sha256', json_encode([$actorId, $companyId, $bookId, $accountId, $countDate, $countedAt, $counted, $note, $denominations], JSON_THROW_ON_ERROR));
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $accountId, $countDate, $countedAt, $counted, $note, $creationKey, $denominations, $hash): array {
        pl_require_company_access($actorId, $companyId, true);
        pl_ledger_book($companyId, $bookId, true);
        $existing = DB::queryFirstRow('SELECT id, payload_hash FROM pl_cash_counts WHERE book_id = %i AND creation_key = %s FOR UPDATE', $bookId, $creationKey);
        if ($existing !== null) {
            if (!hash_equals((string) $existing['payload_hash'], $hash)) { throw new DomainException('This request identity belongs to a different cash count.'); }
            return pl_get_cash_count($actorId, $companyId, $bookId, (int) $existing['id']);
        }
        $account = DB::queryFirstRow("SELECT id, code, name FROM pl_accounts WHERE id = %i AND company_id = %i AND book_id = %i AND role = 'cash_bank' AND type = 'asset' AND is_active = 1 FOR SHARE", $accountId, $companyId, $bookId);
        if (!$account) {
            throw new DomainException('A cash count belongs to an active cash or bank account in this book.');
        }
        if (!pl_account_is_postable($companyId, $bookId, (string) $account['code'])) { throw new DomainException('Choose a postable cash account.'); }
        $period = DB::queryFirstRow('SELECT status FROM pl_periods WHERE company_id = %i AND book_id = %i AND start_date <= %s AND end_date >= %s FOR UPDATE', $companyId, $bookId, $countDate, $countDate);
        if (!$period || $period['status'] !== 'open') { throw new DomainException('A cash count needs an open accounting period.'); }
        $balance = pl_cash_account_balance($companyId, $bookId, $accountId, $countDate);
        $difference = bcsub($counted, $balance, 4);
        $journalId = null;
        if (bccomp($difference, '0', 4) !== 0) {
            $over = bccomp($difference, '0', 4) > 0;
            $amount = $over ? $difference : bcsub('0', $difference, 4);
            $overShort = pl_cash_over_short_account($actorId, $companyId, $bookId);
            // More money in the drawer than the books say is a debit to the drawer and a credit
            // to over-and-short; less is the mirror. The presentation of that account is the
            // question the accounting reviewer is asked (see docs/design/1.2-2026-09/
            // PERIOD-CLOSE.md); the direction of the entry is not in doubt either way.
            $lines = $over
                ? [['account_id' => $accountId, 'debit' => $amount, 'credit' => '0.0000', 'description' => 'Cash over on count'],
                   ['account_id' => $overShort, 'debit' => '0.0000', 'credit' => $amount, 'description' => 'Cash over on count']]
                : [['account_id' => $overShort, 'debit' => $amount, 'credit' => '0.0000', 'description' => 'Cash short on count'],
                   ['account_id' => $accountId, 'debit' => '0.0000', 'credit' => $amount, 'description' => 'Cash short on count']];
            $journal = pl_post_journal_locked($actorId, $companyId, $bookId, [
                'date' => $countDate, 'currency' => (string) pl_ledger_book($companyId, $bookId)['currency'],
                'source_type' => 'cash_count', 'source_reference' => $creationKey,
                'idempotency_key' => 'cash-count:' . $creationKey,
                'description' => ($over ? 'Cash over' : 'Cash short') . ' on counting ' . $account['name'] . ' at ' . $countedAt,
                'lines' => $lines,
            ]);
            $journalId = (int) $journal['id'];
        }
        DB::insert('pl_cash_counts', ['company_id' => $companyId, 'book_id' => $bookId, 'account_id' => $accountId,
            'count_date' => $countDate, 'counted_at' => $countedAt, 'counted_amount' => $counted, 'book_balance' => $balance,
            'difference' => $difference, 'by_denomination' => $denominations['lines'] === [] ? 0 : 1, 'note' => $note,
            'journal_id' => $journalId, 'creation_key' => $creationKey, 'payload_hash' => $hash, 'counted_by' => $actorId]);
        $countId = (int) DB::insertId();
        foreach ($denominations['lines'] as $line) {
            DB::insert('pl_cash_count_lines', ['cash_count_id' => $countId, 'company_id' => $companyId, 'book_id' => $bookId,
                'line_number' => $line['line_number'], 'denomination' => $line['denomination'],
                'quantity' => $line['quantity'], 'amount' => $line['amount']]);
        }
        return pl_get_cash_count($actorId, $companyId, $bookId, $countId);
    });
}

/** One recorded count, with its working and the journal its difference posted through. */
function pl_get_cash_count(int $actorId, int $companyId, int $bookId, int $countId): array
{
    pl_require_company_access($actorId, $companyId);
    pl_ledger_book($companyId, $bookId);
    $row = DB::queryFirstRow('SELECT c.*, a.code AS account_code, a.name AS account_name, u.display_name AS counted_by_name
        FROM pl_cash_counts c JOIN pl_accounts a ON a.id = c.account_id JOIN pl_users u ON u.id = c.counted_by
        WHERE c.id = %i AND c.company_id = %i AND c.book_id = %i FOR SHARE', $countId, $companyId, $bookId);
    if (!$row) {
        throw new DomainException('This cash count is not available in the selected company and book.');
    }
    $count = pl_cash_count_row($row);
    $count['lines'] = [];
    foreach (DB::query('SELECT line_number, denomination, quantity, amount FROM pl_cash_count_lines WHERE cash_count_id = %i AND company_id = %i AND book_id = %i ORDER BY line_number FOR SHARE', $countId, $companyId, $bookId) as $line) {
        $count['lines'][] = ['line_number' => (int) $line['line_number'], 'denomination' => bcadd((string) $line['denomination'], '0', 4),
            'quantity' => (int) $line['quantity'], 'amount' => bcadd((string) $line['amount'], '0', 4)];
    }
    return $count;
}

function pl_cash_count_row(array $row): array
{
    $difference = bcadd((string) $row['difference'], '0', 4);
    return [
        'id' => (int) $row['id'], 'reference' => 'CC-' . str_pad((string) (int) $row['id'], 8, '0', STR_PAD_LEFT),
        'account_id' => (int) $row['account_id'], 'account_code' => (string) ($row['account_code'] ?? ''),
        'account_name' => (string) ($row['account_name'] ?? ''),
        'count_date' => (string) $row['count_date'], 'counted_at' => (string) $row['counted_at'],
        'counted_amount' => bcadd((string) $row['counted_amount'], '0', 4),
        'book_balance' => bcadd((string) $row['book_balance'], '0', 4),
        'difference' => $difference,
        'outcome' => bccomp($difference, '0', 4) === 0 ? 'agreed' : (bccomp($difference, '0', 4) > 0 ? 'over' : 'short'),
        'by_denomination' => (bool) $row['by_denomination'], 'note' => (string) $row['note'],
        'journal_id' => $row['journal_id'] === null ? null : (int) $row['journal_id'],
        'counted_by_name' => (string) ($row['counted_by_name'] ?? ''), 'created_at' => (string) $row['created_at'],
    ];
}

/**
 * The count history of one account, or of every money account when none is named.
 *
 * The running totals are what a close actually wants: how many counts, how many agreed, and
 * the net and gross over-and-short across them. A drawer that is over as often as it is short
 * by small amounts is a different problem from one that is short every time.
 */
function pl_cash_count_history(int $actorId, int $companyId, int $bookId, ?int $accountId = null, int $limit = 100): array
{
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $accountId, $limit): array {
        pl_require_company_access($actorId, $companyId);
        pl_ledger_book($companyId, $bookId);
        $limit = max(1, min(500, $limit));
        $rows = $accountId === null
            ? DB::query('SELECT c.*, a.code AS account_code, a.name AS account_name, u.display_name AS counted_by_name FROM pl_cash_counts c JOIN pl_accounts a ON a.id = c.account_id JOIN pl_users u ON u.id = c.counted_by WHERE c.company_id = %i AND c.book_id = %i ORDER BY c.count_date DESC, c.id DESC LIMIT %i FOR SHARE', $companyId, $bookId, $limit)
            : DB::query('SELECT c.*, a.code AS account_code, a.name AS account_name, u.display_name AS counted_by_name FROM pl_cash_counts c JOIN pl_accounts a ON a.id = c.account_id JOIN pl_users u ON u.id = c.counted_by WHERE c.company_id = %i AND c.book_id = %i AND c.account_id = %i ORDER BY c.count_date DESC, c.id DESC LIMIT %i FOR SHARE', $companyId, $bookId, $accountId, $limit);
        $counts = [];
        $net = '0.0000';
        $gross = '0.0000';
        $agreed = 0;
        foreach ($rows as $row) {
            $count = pl_cash_count_row($row);
            $net = bcadd($net, $count['difference'], 4);
            $gross = bcadd($gross, bccomp($count['difference'], '0', 4) < 0 ? bcsub('0', $count['difference'], 4) : $count['difference'], 4);
            if ($count['outcome'] === 'agreed') { $agreed++; }
            $counts[] = $count;
        }
        return ['counts' => $counts, 'total_counts' => count($counts), 'agreed_counts' => $agreed,
            'net_difference' => $net, 'gross_difference' => $gross];
    });
}
