<?php
declare(strict_types=1);

/** Home is a read-only composition of the same reports and source lists. */
function pl_home_overview(int $actorId, int $companyId, int $bookId, string $asOf): array
{
    pl_require_company_access($actorId, $companyId);
    $book = pl_ledger_book($companyId, $bookId);
    $asOf = pl_ledger_date($asOf);
    $receivables = pl_ar_ap_open_items($actorId, $companyId, $bookId, 'receivable', $asOf);
    $payables = pl_ar_ap_open_items($actorId, $companyId, $bookId, 'payable', $asOf);
    $dueThrough = (new DateTimeImmutable($asOf))->modify('+3 days')->format('Y-m-d');
    $drafts = pl_list_documents($actorId, $companyId, $bookId, ['status' => 'draft', 'page_size' => 25]);
    $journals = pl_list_general_drafts($actorId, $companyId, $bookId, 1, ['status' => 'draft', 'page_size' => 25]);
    $recent = pl_list_documents($actorId, $companyId, $bookId, ['page_size' => 25]);
    $activity = [];
    foreach ($recent['documents'] as $row) {
        $activity[] = ['id'=>$row['id'], 'date'=>$row['date'], 'number'=>$row['number'], 'description'=>$row['counterparty'], 'kind'=>ucfirst($row['kind']), 'amount'=>$row['amount'], 'currency'=>$book['currency'], 'status'=>$row['status'], 'path'=>'/transactions/detail'];
    }
    foreach (pl_list_general_drafts($actorId, $companyId, $bookId, 1, ['page_size'=>25])['rows'] as $row) {
        $activity[] = ['id'=>$row['id'], 'date'=>$row['document_date'], 'number'=>$row['number'], 'description'=>$row['description'], 'kind'=>'Journal', 'amount'=>$row['totals']['debit'], 'currency'=>$book['currency'], 'status'=>$row['status'], 'path'=>'/general-journals/detail'];
    }
    $arDrafts = 0; $apDrafts = 0;
    foreach (pl_list_ar_documents($actorId, $companyId, $bookId)['documents'] as $row) {
        $sales = in_array($row['kind'], ['invoice','customer_credit'], true);
        if ($row['status'] === 'draft') { if ($sales) { $arDrafts++; } else { $apDrafts++; } }
        $activity[] = ['id'=>$row['id'], 'date'=>$row['date'], 'number'=>$row['number'], 'description'=>$row['party']['legal_name'], 'kind'=>ucfirst(str_replace('_',' ',$row['kind'])), 'amount'=>$row['total'], 'currency'=>$row['currency'], 'status'=>$row['reversal_journal_id'] !== null ? 'reversed' : $row['status'], 'path'=>$sales ? '/ar' : '/ap'];
    }
    usort($activity, static fn (array $a, array $b): int => [$b['date'],$b['number']] <=> [$a['date'],$a['number']]);
    $cashAccounts = pl_cash_account_balances($actorId, $companyId, $bookId, $asOf);
    return [
        'as_of' => $asOf, 'currency' => $book['currency'],
        'cash' => pl_cash_balance($actorId, $companyId, $bookId, $asOf),
        'cash_accounts' => $cashAccounts,
        'getting_started' => pl_home_getting_started($actorId, $companyId, $bookId, $cashAccounts),
        'drafts' => $drafts, 'journal_drafts' => $journals['total'], 'ar_drafts'=>$arDrafts, 'ap_drafts'=>$apDrafts,
        'receivables' => $receivables, 'payables' => $payables,
        'overdue_invoices' => array_values(array_filter($receivables['items'], static fn (array $item): bool => $item['age_days'] > 0)),
        'bills_due' => array_values(array_filter($payables['items'], static fn (array $item): bool => $item['due_date'] !== null && $item['due_date'] <= $dueThrough)),
        'bank_lines' => pl_bank_pending_review_count($actorId, $companyId, $bookId),
        'recent' => array_slice($activity, 0, 5),
    ];
}

/** Shared performance-report predicate; full balance-sheet/TB queries deliberately omit it. */
function pl_performance_journal_sql(string $alias = 'j'): string
{
    if (!preg_match('/^[a-z][a-z0-9_]*$/D', $alias)) { throw new LogicException('Use a fixed SQL journal alias.'); }
    return "$alias.source_type <> 'year_end_close' AND NOT EXISTS (SELECT 1 FROM pl_journals ye_original WHERE ye_original.id=$alias.reversal_of_id AND ye_original.source_type='year_end_close')";
}

/** Posted income and expense movements for an inclusive business-date range. */
function pl_profit_loss(int $actorId, int $companyId, int $bookId, string $from, string $to): array
{
    pl_require_company_access($actorId, $companyId);
    $book = pl_ledger_book($companyId, $bookId);
    pl_ledger_date($from);
    pl_ledger_date($to);
    if ($from > $to) {
        throw new DomainException('The report start date must be on or before its end date.');
    }
    $rows = DB::query("SELECT a.id, a.code, a.name, a.type, a.is_contra, a.report_classification,
        COALESCE(SUM(CASE WHEN j.id IS NOT NULL THEN l.debit ELSE 0 END), 0) AS debit,
        COALESCE(SUM(CASE WHEN j.id IS NOT NULL THEN l.credit ELSE 0 END), 0) AS credit
        FROM pl_accounts a
        LEFT JOIN pl_journal_lines l ON l.account_id = a.id AND l.company_id = a.company_id AND l.book_id = a.book_id
        LEFT JOIN pl_journals j ON j.id = l.journal_id AND j.company_id = a.company_id AND j.book_id = a.book_id AND j.journal_date >= %s AND j.journal_date <= %s AND " . pl_performance_journal_sql() . "
        WHERE a.company_id = %i AND a.book_id = %i AND a.type IN ('income','expense')
        GROUP BY a.id, a.code, a.name, a.type, a.is_contra, a.report_classification ORDER BY a.code", $from, $to, $companyId, $bookId);
    $income = [];
    $expenses = [];
    $costOfSales = []; $totalCostOfSales = '0.0000';
    $totalIncome = '0.0000';
    $totalExpenses = '0.0000';
    foreach ($rows as $row) {
        $amount = $row['type'] === 'income' ? bcsub((string) $row['credit'], (string) $row['debit'], 4) : bcsub((string) $row['debit'], (string) $row['credit'], 4);
        $entry = ['id' => (int) $row['id'], 'code' => $row['code'], 'name' => $row['name'], 'type' => $row['type'],
            'is_contra' => (bool) $row['is_contra'], 'level' => pl_account_code_is_valid((string) $row['code']) ? pl_account_code_level((string) $row['code']) : 'account',
            'amount' => $amount];
        if ($row['type'] === 'income') {
            $income[] = $entry;
            $totalIncome = bcadd($totalIncome, $amount, 4);
        } elseif ($row['report_classification'] === 'cost_of_sales') {
            $costOfSales[] = $entry;
            $totalCostOfSales = bcadd($totalCostOfSales, $amount, 4);
        } else {
            $expenses[] = $entry;
            $totalExpenses = bcadd($totalExpenses, $amount, 4);
        }
    }
    $grossProfit = bcsub($totalIncome, $totalCostOfSales, 4);
    return ['from' => $from, 'to' => $to, 'currency' => $book['currency'], 'income' => $income, 'cost_of_sales'=>$costOfSales, 'expenses' => $expenses,
        'trees' => ['income' => pl_report_tree($income, ['amount']), 'cost_of_sales' => pl_report_tree($costOfSales, ['amount']), 'expenses' => pl_report_tree($expenses, ['amount'])],
        'total_income' => $totalIncome, 'total_cost_of_sales'=>$totalCostOfSales, 'gross_profit'=>$grossProfit, 'total_expenses' => $totalExpenses, 'net_profit' => bcsub($grossProfit, $totalExpenses, 4)];
}

/** Chart-classified balances; accumulated unclosed earnings appear once beside recorded equity. */
function pl_balance_sheet(int $actorId, int $companyId, int $bookId, string $asOf): array
{
    pl_require_company_access($actorId, $companyId);
    $book = pl_ledger_book($companyId, $bookId);
    pl_ledger_date($asOf);
    $trial = pl_trial_balance($actorId, $companyId, $bookId, $asOf);
    $groups = ['assets' => [], 'liabilities' => [], 'equity' => []];
    $totals = ['assets' => '0.0000', 'liabilities' => '0.0000', 'equity' => '0.0000'];
    $earnedProfit = '0.0000';
    foreach ($trial['accounts'] as $account) {
        if (in_array($account['type'], ['income', 'expense'], true)) {
            $earnedProfit = bcsub($earnedProfit, $account['balance'], 4);
            continue;
        }
        $group = match ($account['type']) {
            'asset' => 'assets', 'liability' => 'liabilities', 'equity' => 'equity',
            default => throw new DomainException('An account has an unsupported report classification.'),
        };
        $amount = $group === 'assets' ? $account['balance'] : bcsub('0', $account['balance'], 4);
        $groups[$group][] = ['id' => $account['id'], 'code' => $account['code'], 'name' => $account['name'], 'type' => $account['type'],
            'is_contra' => (bool) ($account['is_contra'] ?? false), 'level' => $account['level'] ?? 'account', 'amount' => $amount];
        $totals[$group] = bcadd($totals[$group], $amount, 4);
    }
    $totalEquity = bcadd($totals['equity'], $earnedProfit, 4);
    $liabilitiesEquity = bcadd($totals['liabilities'], $totalEquity, 4);
    $trees = ['assets' => pl_report_tree($groups['assets'], ['amount']), 'liabilities' => pl_report_tree($groups['liabilities'], ['amount']), 'equity' => pl_report_tree($groups['equity'], ['amount'])];
    return ['as_of' => $asOf, 'currency' => $book['currency'], 'trees' => $trees,
        'equity_movements' => pl_owner_equity_movements($actorId, $companyId, $bookId, $asOf)] + $groups + [
        'total_assets' => $totals['assets'], 'total_liabilities' => $totals['liabilities'],
        'recorded_equity' => $totals['equity'], 'earned_profit' => $earnedProfit, 'total_equity' => $totalEquity,
        'total_liabilities_equity' => $liabilitiesEquity, 'balanced' => $trial['balanced'] && bccomp($totals['assets'], $liabilitiesEquity, 4) === 0,
    ];
}

function pl_cash_balance(int $actorId, int $companyId, int $bookId, string $asOf): string
{
    pl_require_company_access($actorId, $companyId);
    pl_ledger_book($companyId, $bookId);
    pl_ledger_date($asOf);
    $row = DB::queryFirstRow("SELECT COALESCE(SUM(CASE WHEN j.id IS NOT NULL THEN l.debit ELSE 0 END), 0) AS debit,
        COALESCE(SUM(CASE WHEN j.id IS NOT NULL THEN l.credit ELSE 0 END), 0) AS credit
        FROM pl_accounts a LEFT JOIN pl_journal_lines l ON l.account_id = a.id AND l.company_id = a.company_id AND l.book_id = a.book_id
        LEFT JOIN pl_journals j ON j.id = l.journal_id AND j.company_id = a.company_id AND j.book_id = a.book_id AND j.journal_date <= %s
        WHERE a.company_id = %i AND a.book_id = %i AND a.type = 'asset' AND a.role = 'cash_bank'", $asOf, $companyId, $bookId);
    return bcsub((string) $row['debit'], (string) $row['credit'], 4);
}

/**
 * Every active bank, till and petty-cash account with its balance at a date. Home lists them
 * under the total (owner review, 25 September 2026), and "money in" is true when any one of
 * them holds money; the total alone cannot say which account an expense could come from.
 *
 * @return list<array{id:int, code:string, name:string, money_kind:?string, semantic_key:string, balance:string}>
 */
function pl_cash_account_balances(int $actorId, int $companyId, int $bookId, string $asOf): array
{
    pl_require_company_access($actorId, $companyId);
    pl_ledger_book($companyId, $bookId);
    pl_ledger_date($asOf);
    $rows = DB::query("SELECT a.id, a.code, a.name, a.money_kind, a.semantic_key,
        COALESCE(SUM(CASE WHEN j.id IS NOT NULL THEN l.debit ELSE 0 END), 0) AS debit,
        COALESCE(SUM(CASE WHEN j.id IS NOT NULL THEN l.credit ELSE 0 END), 0) AS credit
        FROM pl_accounts a LEFT JOIN pl_journal_lines l ON l.account_id = a.id AND l.company_id = a.company_id AND l.book_id = a.book_id
        LEFT JOIN pl_journals j ON j.id = l.journal_id AND j.company_id = a.company_id AND j.book_id = a.book_id AND j.journal_date <= %s
        WHERE a.company_id = %i AND a.book_id = %i AND a.type = 'asset' AND a.role = 'cash_bank' AND a.is_active = 1
        GROUP BY a.id, a.code, a.name, a.money_kind, a.semantic_key ORDER BY a.code", $asOf, $companyId, $bookId);
    $accounts = [];
    foreach ($rows as $row) {
        $accounts[] = ['id' => (int) $row['id'], 'code' => (string) $row['code'], 'name' => (string) $row['name'],
            'money_kind' => $row['money_kind'] === null ? null : (string) $row['money_kind'],
            'semantic_key' => (string) ($row['semantic_key'] ?? ''),
            'balance' => bcsub((string) $row['debit'], (string) $row['credit'], 4)];
    }
    return $accounts;
}

/**
 * The first Home's guide (owner review of 25 September 2026, frame H-1 in
 * docs/design/setup-1.4.5): six steps read from data that already exists, so the guide needs no
 * storage and goes away by itself once the last step is done. It states facts and offers links;
 * it enforces nothing. An empty profile posts fine, and what refuses an expense from an empty
 * account is the book's strict cash policy, not this list.
 *
 * @param list<array{name:string, semantic_key:string, balance:string}> $cashAccounts from pl_cash_account_balances()
 * @return array{steps: list<array{id:string, done:bool, facts:array<string,mixed>}>, done:int, total:int, complete:bool, current:?string, money_in:bool, legal_form:string, legal_form_label:string, country:string}
 */
function pl_home_getting_started(int $actorId, int $companyId, int $bookId, array $cashAccounts): array
{
    pl_require_company_access($actorId, $companyId);
    pl_ledger_book($companyId, $bookId);
    $profile = pl_company_profile($actorId, $companyId);
    $profileDone = implode('', [$profile['legal_name'], $profile['registration_number'], $profile['tax_registrations'], $profile['address_line1']]) !== '';
    $owners = array_column(pl_list_ownership_parties($actorId, $companyId, true), 'name');
    // The starter's generic leaf is not a named account: the step asks for the real places money sits.
    $named = array_values(array_filter($cashAccounts, static fn (array $account): bool => !($account['semantic_key'] === 'core.cash_bank' && $account['name'] === 'Cash and bank')));
    $moneyIn = false;
    foreach ($cashAccounts as $account) {
        if (bccomp($account['balance'], '0', 4) > 0) { $moneyIn = true; break; }
    }
    $customers = (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_parties WHERE company_id = %i AND is_customer = 1', $companyId);
    $suppliers = (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_parties WHERE company_id = %i AND is_vendor = 1', $companyId);
    // Setup postings are not activity: capital and loans in, opening balances, share events.
    $activity = (int) DB::queryFirstField("SELECT COUNT(*) FROM pl_journals WHERE company_id = %i AND book_id = %i
        AND source_type NOT IN ('owner_transaction', 'opening_balance', 'opening_conversion', 'share_event', 'reversal')", $companyId, $bookId);
    $legalForm = (string) $profile['legal_form'];
    $family = pl_legal_form_family($legalForm);
    $country = (string) $profile['country_code'] !== '' ? (string) $profile['country_code'] : pl_legal_form_country($legalForm);
    $steps = [
        ['id' => 'profile', 'done' => $profileDone, 'facts' => ['legal_name' => (string) $profile['legal_name'], 'registration_number' => (string) $profile['registration_number'],
            'tax_registrations' => (string) $profile['tax_registrations'], 'labels' => pl_legal_form_country_profile($country)['labels']]],
        ['id' => 'owners', 'done' => $owners !== [], 'facts' => ['names' => array_slice($owners, 0, 3), 'count' => count($owners), 'family' => $family]],
        ['id' => 'money_accounts', 'done' => $named !== [], 'facts' => ['names' => array_column(array_slice($named, 0, 3), 'name'), 'count' => count($named)]],
        ['id' => 'money_in', 'done' => $moneyIn, 'facts' => ['family' => $family]],
        ['id' => 'parties', 'done' => $customers + $suppliers > 0, 'facts' => ['customers' => $customers, 'suppliers' => $suppliers]],
        ['id' => 'first_activity', 'done' => $activity > 0, 'facts' => ['count' => $activity]],
    ];
    $done = count(array_filter($steps, static fn (array $step): bool => $step['done']));
    $current = null;
    foreach ($steps as $step) {
        if (!$step['done']) { $current = $step['id']; break; }
    }
    return ['steps' => $steps, 'done' => $done, 'total' => count($steps), 'complete' => $done === count($steps), 'current' => $current, 'money_in' => $moneyIn,
        'legal_form' => $legalForm, 'legal_form_label' => (string) $profile['legal_form_label'],
        'country' => $country === null ? '' : (pl_country_options()[$country] ?? $country)];
}

/** An explicit scenario, never an inferred forecast or a ledger write. */
function pl_cash_forecast(string $opening, string $weeklyIn, string $weeklyOut, int $weeks = 12): array
{
    $negative = str_starts_with($opening, '-');
    $amount = pl_amount($negative ? substr($opening, 1) : $opening);
    $opening = $negative ? bcsub('0', $amount, 4) : $amount;
    $inflow = pl_amount($weeklyIn);
    $outflow = pl_amount($weeklyOut);
    if ($weeks < 1 || $weeks > 52) {
        throw new DomainException('Choose a cash scenario between one and 52 weeks.');
    }
    $balance = $opening;
    $firstNegative = bccomp($opening, '0', 4) < 0 ? 0 : null;
    $rows = [];
    for ($week = 1; $week <= $weeks; $week++) {
        $closing = bcsub(bcadd($balance, $inflow, 4), $outflow, 4);
        $rows[] = ['week' => $week, 'opening' => $balance, 'inflow' => $inflow, 'outflow' => $outflow, 'closing' => $closing];
        if ($firstNegative === null && bccomp($closing, '0', 4) < 0) {
            $firstNegative = $week;
        }
        $balance = $closing;
    }
    return ['opening' => $opening, 'weekly_in' => $inflow, 'weekly_out' => $outflow, 'weeks' => $weeks, 'rows' => $rows, 'closing' => $balance, 'first_negative_week' => $firstNegative];
}

/**
 * Shape flat report rows into the chart's own class -> group -> account ->
 * sub-account tree, with every measure aggregated at each level (issue #77).
 *
 * The code *is* the hierarchy, so no row needs a parent identifier: a heading
 * row (`X-000-00000-00`, `X-GGG-00000-00`) lends its name to the node it names
 * and never appears as a figure of its own, and every other row is attached at
 * its own depth. Rows still numbered the legacy way are kept: they hang
 * directly under their classification, so no balance is ever dropped from a
 * report because its account has not been converted.
 *
 * **An empty group is not on a report, deliberately.** The tree is built from
 * the accounts that can hold a figure, and a heading only ever lends a name to
 * a node those accounts created. A group the chart declares but nothing is
 * posted under — `1-200 Property, Plant and Equipment` in a book that has not
 * bought anything yet — therefore has no node and no zero line of its own. A
 * report states what the book holds; the chart of accounts screen states what
 * the book is allowed to hold, and that is where an empty group is visible and
 * can be used. Adding the first account under it puts the group on the report
 * with its name already attached.
 *
 * @param array<int,array<string,mixed>> $rows
 * @param array<int,string> $measures decimal-string fields to aggregate
 * @return array<int,array<string,mixed>>
 */
function pl_report_tree(array $rows, array $measures): array
{
    $headings = [];
    $leaves = [];
    foreach ($rows as $row) {
        $code = (string) ($row['code'] ?? '');
        if (pl_account_code_is_valid($code) && pl_account_code_is_heading($code)) {
            $headings[$code] = (string) ($row['name'] ?? '');
            continue;
        }
        $leaves[] = $row;
    }
    $nodes = [];
    foreach ($leaves as $row) {
        $code = (string) ($row['code'] ?? '');
        if (pl_account_code_is_valid($code)) {
            $path = pl_account_code_ancestors($code);
        } else {
            // A legacy number keeps its classification only; it has no group of its own yet.
            $classCode = pl_account_code_format(pl_account_code_class_for_type((string) ($row['type'] ?? 'asset')), 0, 0, 0);
            $path = [$classCode, 'unstructured:' . $classCode];
        }
        $nodes = pl_report_tree_insert($nodes, $path, $code, $row, $headings, $measures, 0);
    }
    return pl_report_tree_sort($nodes);
}

/** @return array<string,array<string,mixed>> */
function pl_report_tree_insert(array $nodes, array $path, string $code, array $row, array $headings, array $measures, int $depth): array
{
    if ($path === []) {
        if (!isset($nodes[$code])) {
            $nodes[$code] = pl_report_tree_leaf($code, $row, $measures, $depth);
            return $nodes;
        }
        // The account already exists as an aggregate because a sub-account was seen first;
        // keep its children and add its own posted figures to theirs.
        $nodes[$code] = array_replace($nodes[$code], array_diff_key(pl_report_tree_leaf($code, $row, $measures, $depth), array_flip(array_merge(['children'], $measures))));
        foreach ($measures as $measure) {
            $nodes[$code][$measure] = bcadd((string) $nodes[$code][$measure], (string) ($row[$measure] ?? '0'), 4);
        }
        return $nodes;
    }
    $ancestor = array_shift($path);
    if (!isset($nodes[$ancestor])) {
        $nodes[$ancestor] = pl_report_tree_node($ancestor, $headings, $measures, $depth);
    }
    foreach ($measures as $measure) {
        $nodes[$ancestor][$measure] = bcadd((string) $nodes[$ancestor][$measure], (string) ($row[$measure] ?? '0'), 4);
    }
    $nodes[$ancestor]['children'] = pl_report_tree_insert($nodes[$ancestor]['children'], $path, $code, $row, $headings, $measures, $depth + 1);
    return $nodes;
}

/** @return array<string,mixed> */
function pl_report_tree_leaf(string $code, array $row, array $measures, int $depth): array
{
    $leaf = $row + [
        'code' => $code,
        'short_code' => $code,
        'label' => (string) ($row['name'] ?? ''),
        'level' => pl_account_code_is_valid($code) ? pl_account_code_level($code) : 'account',
        'depth' => $depth,
        'is_heading' => false,
        'children' => [],
    ];
    $leaf['is_contra'] = (bool) ($row['is_contra'] ?? false);
    foreach ($measures as $measure) {
        $leaf[$measure] = bcadd((string) ($row[$measure] ?? '0'), '0', 4);
    }
    return $leaf;
}

/** @return array<string,mixed> */
function pl_report_tree_node(string $code, array $headings, array $measures, int $depth): array
{
    if (str_starts_with($code, 'unstructured:')) {
        $node = ['code' => $code, 'short_code' => '', 'label' => 'Other accounts (unstructured codes)',
            'name' => 'Other accounts (unstructured codes)', 'level' => 'group', 'depth' => $depth,
            'is_heading' => true, 'is_contra' => false, 'children' => []];
        foreach ($measures as $measure) { $node[$measure] = '0.0000'; }
        return $node;
    }
    $level = pl_account_code_level($code);
    $label = $headings[$code] ?? '';
    if ($label === '') {
        // Last resort, and it is meant to stay unreachable. The bundled chart names every class
        // and group it defines, and migration 045 names them in a chart 036 converted, so a book
        // reaches this line only for a group its own owner added without naming it.
        // `tests/chart_headings_test.php` asserts that no node of a new book or of a converted one
        // ever carries this label.
        $label = $level === 'class'
            ? pl_account_code_class_label(pl_account_code_parse($code)['class'])
            : 'Group ' . pl_account_code_short($code);
    }
    $node = ['code' => $code, 'short_code' => pl_account_code_short($code), 'label' => $label, 'name' => $label,
        'level' => $level, 'depth' => $depth, 'is_heading' => true, 'is_contra' => false, 'children' => []];
    foreach ($measures as $measure) { $node[$measure] = '0.0000'; }
    return $node;
}

/** @return array<int,array<string,mixed>> */
function pl_report_tree_sort(array $nodes): array
{
    ksort($nodes, SORT_STRING);
    $sorted = [];
    foreach ($nodes as $node) {
        if (($node['children'] ?? []) !== []) { $node['children'] = pl_report_tree_sort($node['children']); }
        $sorted[] = $node;
    }
    return $sorted;
}

/**
 * The depth control of the collapsible reports (frame decision 20): the number
 * of levels shown — 1 classes only, 2 class and group, 3 adds accounts, 4 adds
 * sub-accounts.
 *
 * @return array<int,array<string,mixed>>
 */
function pl_report_tree_limit(array $nodes, int $depth): array
{
    if ($depth < 1 || $depth > 4) { throw new DomainException('Choose a report depth between the class and the sub-account level.'); }
    $limited = [];
    foreach ($nodes as $node) {
        $node['children'] = ((int) ($node['depth'] ?? 0)) + 1 >= $depth ? [] : pl_report_tree_limit($node['children'] ?? [], $depth);
        $limited[] = $node;
    }
    return $limited;
}

/**
 * The depth choices offered on a collapsible report (frame decision 20), as
 * "levels shown" => label.
 *
 * @return array<int,string>
 */
function pl_report_depth_options(): array
{
    return [4 => 'Class → Group → Account', 2 => 'Class → Group only'];
}

/** Read the requested depth from untrusted query input; anything else is full depth. */
function pl_report_depth(array $query): int
{
    $depth = (int) ($query['depth'] ?? 4);
    return isset(pl_report_depth_options()[$depth]) ? $depth : 4;
}

/**
 * Drop the lines that carry nothing: an account at zero in every measure, then a heading left with
 * no accounts under it. A statement lists what is there (owner, 26 September 2026); the CSV export
 * and the account pages keep every account.
 *
 * @param array<int,array<string,mixed>> $nodes tree from pl_report_tree()
 * @param list<string> $measures
 * @return array<int,array<string,mixed>>
 */
function pl_report_tree_prune(array $nodes, array $measures): array
{
    $kept = [];
    foreach ($nodes as $node) {
        $node['children'] = pl_report_tree_prune($node['children'] ?? [], $measures);
        $zero = true;
        foreach ($measures as $measure) {
            if (bccomp((string) ($node[$measure] ?? '0'), '0', 4) !== 0) { $zero = false; break; }
        }
        if ($zero && $node['children'] === []) { continue; }
        $kept[] = $node;
    }
    return $kept;
}

/** Flatten a tree into ordered display rows, for CSV and the print view. */
function pl_report_tree_rows(array $nodes): array
{
    $rows = [];
    foreach ($nodes as $node) {
        $children = $node['children'] ?? [];
        $node['children'] = [];
        $node['has_children'] = $children !== [];
        $rows[] = $node;
        foreach (pl_report_tree_rows($children) as $child) { $rows[] = $child; }
    }
    return $rows;
}
