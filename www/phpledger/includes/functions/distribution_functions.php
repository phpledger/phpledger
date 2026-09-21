<?php
declare(strict_types=1);

/*
 * The money side of a driver's day, and the credit limit that is checked at settlement
 * (1.2 M9).
 *
 * M4 built the goods side: pl_van_day() reconciles opening plus loaded plus customer
 * returns, less sold and less returned, against the van's closing stock. It deliberately
 * said nothing about money, because "the cash and credit side of the day belongs to the
 * sale documents the van raised". This file reads those documents and answers the two
 * questions the settlement sheet was missing.
 *
 *   1. What did the driver sell, and how much of it was on credit?
 *   2. Is any of that credit outside the limit the business gave that customer?
 *
 * Research decision 4 (docs/design/1.2-2026-09/distribution-research/DECISIONS.md) puts the
 * credit check here rather than on the device: a van may be offline, so the limit is checked
 * where the server can see the whole ledger — at settlement, before approval. A counter sale
 * is a different case and is checked immediately; see counter_pos_functions.php.
 *
 * Nothing here posts, allocates or writes. Every figure is read from posted documents and
 * open-item entries, so the sheet reconciles to the ledger by construction.
 */

/**
 * The credit limit recorded for one party in this book, or null when none is.
 *
 * A NULL limit means "no limit recorded", which is how decision 4's reversal path disables
 * enforcement; a recorded 0.0000 means "no credit at all" and is enforced as such. The two
 * are not the same and the column (migration 014) has always been able to tell them apart.
 */
function pl_party_credit_limit(int $companyId, int $bookId, int $partyId): ?string
{
    $limit = DB::queryFirstField('SELECT credit_limit FROM pl_party_financial_profiles WHERE party_id=%i AND company_id=%i AND book_id=%i',
        $partyId, $companyId, $bookId);
    return $limit === null ? null : pl_amount(bcadd((string) $limit, '0', 4));
}

/**
 * What one customer still owes on trade documents as of a date, in the book's currency.
 *
 * The same arithmetic as the receivables ageing (pl_ar_ap_open_items): an item's entries up
 * to the date, increasing kinds added and relieving kinds subtracted, and only a positive
 * residual counts. Advance items are excluded, because unapplied credit the customer has
 * already paid is not debt. Reading it here rather than calling the ageing report keeps this
 * to one party and one query.
 */
function pl_party_receivable_exposure(int $companyId, int $bookId, int $partyId, string $asOf): string
{
    $rows = DB::query('SELECT e.item_id,e.kind,COALESCE(e.allocated_amount_base,l.amount_base) AS amount_base'
        . ' FROM pl_open_item_entries e'
        . ' JOIN pl_journal_lines l ON l.id=e.journal_line_id AND l.company_id=e.company_id AND l.book_id=e.book_id'
        . ' JOIN pl_journals j ON j.id=l.journal_id'
        . ' JOIN pl_open_items i ON i.id=e.item_id AND i.company_id=e.company_id AND i.book_id=e.book_id'
        . " WHERE i.company_id=%i AND i.book_id=%i AND i.party_id=%i AND i.direction='receivable'"
        . " AND COALESCE(i.nature,'document')='document' AND j.journal_date<=%s ORDER BY e.item_id,e.id FOR SHARE",
        $companyId, $bookId, $partyId, pl_ledger_date($asOf));
    $items = [];
    foreach ($rows as $row) {
        $id = (int) $row['item_id'];
        $items[$id] ??= '0.0000';
        $items[$id] = in_array((string) $row['kind'], pl_open_item_increasing_kinds(), true)
            ? bcadd($items[$id], (string) $row['amount_base'], 4)
            : bcsub($items[$id], (string) $row['amount_base'], 4);
    }
    $total = '0.0000';
    foreach ($items as $remaining) {
        // A negative residual is an over-allocated item, not a credit against another
        // invoice; it never reduces what the customer owes on the rest of the ledger.
        if (bccomp($remaining, '0', 4) > 0) { $total = bcadd($total, $remaining, 4); }
    }
    return $total;
}

/**
 * The limit, what is already owed and what is left, for one customer.
 *
 * `status` is the word the sheet and the approval both use: `no_limit` when the business has
 * recorded none, `within` when the exposure is inside it, and `over` when it is not.
 */
function pl_party_credit_position(int $companyId, int $bookId, int $partyId, string $asOf): array
{
    $limit = pl_party_credit_limit($companyId, $bookId, $partyId);
    $exposure = pl_party_receivable_exposure($companyId, $bookId, $partyId, $asOf);
    $over = $limit !== null && bccomp($exposure, $limit, 4) > 0;
    return ['party_id' => $partyId, 'credit_limit' => $limit, 'exposure_base' => $exposure,
        'available_base' => $limit === null ? null : bcsub($limit, $exposure, 4),
        'excess_base' => $over ? bcsub($exposure, $limit, 4) : '0.0000',
        'status' => $limit === null ? 'no_limit' : ($over ? 'over' : 'within')];
}

/**
 * Would this new credit amount put the customer outside the limit?
 *
 * Used by the counter, where the server is present and the answer is wanted before the sale
 * is posted. The settlement path asks the same question of what is already posted instead.
 */
function pl_party_credit_headroom(int $companyId, int $bookId, int $partyId, string $asOf, string $additional): array
{
    $position = pl_party_credit_position($companyId, $bookId, $partyId, $asOf);
    $additional = pl_amount($additional);
    $after = bcadd($position['exposure_base'], $additional, 4);
    $limit = $position['credit_limit'];
    return $position + ['additional_base' => $additional, 'exposure_after_base' => $after,
        'would_exceed' => $limit !== null && bccomp($after, $limit, 4) > 0,
        'excess_after_base' => $limit !== null && bccomp($after, $limit, 4) > 0 ? bcsub($after, $limit, 4) : '0.0000'];
}

/**
 * The sale documents one van raised on one day, split into what was paid and what was not.
 *
 * A sale from a van is an ordinary customer invoice carrying that van as its warehouse
 * (migration 037's `warehouse_id`), so the stock leg comes off the van's own balance and
 * lands in pl_van_day()'s `sold` bucket. Cash taken on delivery is the invoice's own
 * `cash_received`, settled inside the same posting action; the rest is the credit the driver
 * extended. A customer credit raised against the van is listed separately: it is a return,
 * not a negative sale, and its goods leg is pl_van_day()'s `customer_returns` bucket.
 *
 * Reversed and corrected revisions are excluded: `status` is `posted` only for the revision
 * that currently stands.
 */
function pl_van_day_sales(int $actorId, int $companyId, int $bookId, int $warehouseId, string $date): array
{
    pl_require_company_access($actorId, $companyId);
    pl_ledger_book($companyId, $bookId);
    $date = pl_ledger_date($date);
    $rows = DB::query('SELECT d.id,d.kind,d.party_id,d.document_number,d.document_date,d.due_date,d.currency,d.total,d.cash_received,d.status,'
        . 'p.legal_name AS party_name FROM pl_ar_documents d'
        . ' JOIN pl_parties p ON p.id=d.party_id AND p.company_id=d.company_id'
        . " WHERE d.company_id=%i AND d.book_id=%i AND d.warehouse_id=%i AND d.document_date=%s"
        . " AND d.status='posted' AND d.kind IN %ls ORDER BY d.id FOR SHARE",
        $companyId, $bookId, $warehouseId, $date, ['invoice', 'customer_credit']);
    $documents = []; $parties = [];
    $totals = ['documents' => 0, 'sales' => '0.0000', 'cash' => '0.0000', 'credit' => '0.0000',
        'credit_documents' => 0, 'cash_documents' => 0, 'returns' => '0.0000', 'return_documents' => 0];
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $partyId = (int) $row['party_id'];
        $total = pl_amount(bcadd((string) $row['total'], '0', 4));
        $cash = pl_amount(bcadd((string) $row['cash_received'], '0', 4));
        $credit = bcsub($total, $cash, 4);
        $isCredit = (string) $row['kind'] === 'customer_credit';
        $entry = ['document_id' => $id, 'kind' => (string) $row['kind'], 'party_id' => $partyId,
            'party_name' => (string) $row['party_name'],
            'number' => pl_document_number_display($row['document_number'] === null ? null : (string) $row['document_number'], $id, (string) $row['kind']),
            'document_date' => (string) $row['document_date'], 'due_date' => (string) $row['due_date'],
            'currency' => (string) $row['currency'], 'total_fc' => $total,
            'cash_fc' => $isCredit ? '0.0000' : $cash, 'credit_fc' => $isCredit ? '0.0000' : $credit,
            'tender' => $isCredit ? 'return' : (bccomp($credit, '0', 4) === 0 ? 'cash' : (bccomp($cash, '0', 4) === 0 ? 'credit' : 'part_cash'))];
        $documents[] = $entry;
        if ($isCredit) {
            $totals['returns'] = bcadd($totals['returns'], $total, 4);
            $totals['return_documents']++;
            continue;
        }
        $totals['documents']++;
        $totals['sales'] = bcadd($totals['sales'], $total, 4);
        $totals['cash'] = bcadd($totals['cash'], $cash, 4);
        $totals['credit'] = bcadd($totals['credit'], $credit, 4);
        if (bccomp($credit, '0', 4) > 0) {
            $totals['credit_documents']++;
            $parties[$partyId] = bcadd($parties[$partyId] ?? '0.0000', $credit, 4);
        }
        if (bccomp($cash, '0', 4) > 0) { $totals['cash_documents']++; }
    }
    ksort($parties, SORT_NUMERIC);
    return ['warehouse_id' => $warehouseId, 'date' => $date, 'documents' => $documents,
        'credit_by_party' => $parties, 'totals' => $totals];
}

/**
 * Credit limits, checked at settlement (research decision 4).
 *
 * One row per customer the driver sold to on credit that day: the credit taken that day, what
 * the customer owes in total as of the settlement date, the recorded limit, and whether the
 * limit is broken. A customer with no recorded limit is reported as `no_limit` and is not a
 * breach — that is how the decision's reversal path leaves credit unenforced — while a limit
 * of zero is a real limit and a single rupee of credit breaks it.
 *
 * The check is over the customer's whole receivable balance, not over the day's sales alone,
 * because a limit is a statement about exposure and a driver cannot see the office ledger.
 */
function pl_van_credit_check(int $companyId, int $bookId, array $sales): array
{
    $parties = [];
    $breaches = 0;
    $creditTotal = '0.0000';
    foreach ($sales['credit_by_party'] as $partyId => $credit) {
        $position = pl_party_credit_position($companyId, $bookId, (int) $partyId, $sales['date']);
        $name = '';
        foreach ($sales['documents'] as $document) {
            if ($document['party_id'] === (int) $partyId) { $name = $document['party_name']; break; }
        }
        $position['party_name'] = $name;
        $position['credit_today_base'] = $credit;
        $parties[] = $position;
        $creditTotal = bcadd($creditTotal, $credit, 4);
        if ($position['status'] === 'over') { $breaches++; }
    }
    return ['date' => $sales['date'], 'parties' => $parties, 'breaches' => $breaches,
        'credit_today_base' => $creditTotal, 'within_limits' => $breaches === 0,
        'checked_at' => 'settlement'];
}

/**
 * The sentence an approver is shown when a limit is broken.
 *
 * It names the customers, because "a credit limit is exceeded" is not something a supervisor
 * can act on and "Ali Traders is 12,000 over a 50,000 limit" is.
 */
function pl_van_credit_breach_message(array $check): string
{
    $named = [];
    foreach ($check['parties'] as $party) {
        if ($party['status'] !== 'over') { continue; }
        $named[] = ($party['party_name'] === '' ? 'Customer ' . $party['party_id'] : $party['party_name'])
            . ' owes ' . $party['exposure_base'] . ' against a limit of ' . (string) $party['credit_limit']
            . ' (' . $party['excess_base'] . ' over)';
    }
    return 'This day put ' . count($named) . ' customer' . (count($named) === 1 ? '' : 's')
        . ' outside the credit limit recorded for them: ' . implode('; ', $named)
        . '. Record the receipt or the credit note that clears it, or have an administrator change the limit, then review the day again.';
}
