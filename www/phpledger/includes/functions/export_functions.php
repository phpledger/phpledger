<?php
declare(strict_types=1);

/** Protect untrusted text from spreadsheet formulas; money uses validated numeric columns. */
function pl_csv_text(string $value): string
{
    return preg_match('/^[\s\x00-\x1f]*[=+@-]|^[\t\r\n]/u', $value) ? "'" . $value : $value;
}

/** A complete bounded export, computed in one book transaction before sending any bytes. */
function pl_export_report(int $actorId, int $companyId, int $bookId, string $kind, string $to, ?string $from = null, ?int $accountId = null): array
{
    if (!in_array($kind, ['trial-balance', 'account', 'profit-loss', 'balance-sheet'], true)) {
        throw new DomainException('Choose a supported report to export.');
    }
    pl_ledger_date($to);
    if ($from !== null && (pl_ledger_date($from) > $to)) {
        throw new DomainException('The report start date must be on or before its end date.');
    }
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $kind, $to, $from, $accountId): array {
        pl_require_company_access($actorId, $companyId);
        $book = pl_ledger_book($companyId, $bookId);
        $company = pl_company_context($actorId, $companyId);
        $rows = [
            ['PHP Ledger report', $kind], ['Company', pl_csv_text($company['name'])],
            ['Company ID', (string) $companyId], ['Book ID', (string) $bookId],
            ['Currency', $book['currency']], ['From', $from ?? 'All recorded history'], ['Through', $to],
            ['Setup status', $company['setup_status']], ['Scope', 'Recorded entries only; development preview'], [],
        ];
        if ($kind === 'trial-balance') {
            $report = pl_trial_balance($actorId, $companyId, $bookId, $to);
            if (count($report['accounts']) > 10000) { throw new DomainException('This export exceeds 10,000 accounts.'); }
            $rows[] = ['Account ID', 'Code', 'Name', 'Type', 'Debit', 'Credit', 'Signed balance'];
            foreach ($report['accounts'] as $a) {
                // A trial balance lists the accounts that carry balances. A class or group heading
                // is a name the report tree uses to title a section; exporting it as a row of
                // zeros would be a line an accountant has to explain away.
                if (in_array((string) ($a['level'] ?? 'account'), ['class', 'group'], true)) { continue; }
                $rows[] = [(string) $a['id'], pl_csv_text($a['code']), pl_csv_text($a['name']), $a['type'], $a['debit'], $a['credit'], $a['balance']];
            }
            $rows[] = ['', '', 'Total', '', $report['total_debit'], $report['total_credit']];
        } elseif ($kind === 'account') {
            if ($accountId === null || $accountId < 1) { throw new DomainException('Choose an account to export.'); }
            $report = pl_account_activity($actorId, $companyId, $bookId, $accountId, $to, 1, $from);
            if ($report['total'] > 10000) { throw new DomainException('Choose a shorter date range; exports support up to 10,000 movements.'); }
            $rows[] = ['Account', pl_csv_text($report['account']['code']), pl_csv_text($report['account']['name'])];
            $rows[] = ['Opening balance', $report['opening_balance']];
            $rows[] = ['Date', 'Journal', 'Description', 'Source type', 'Source reference', 'Debit', 'Credit', 'Running balance'];
            for ($page = 1; $page <= $report['pages']; ++$page) {
                $part = $page === 1 ? $report : pl_account_activity($actorId, $companyId, $bookId, $accountId, $to, $page, $from);
                foreach ($part['movements'] as $m) {
                    $rows[] = [$m['date'], $m['journal_reference'], pl_csv_text($m['description']), pl_csv_text($m['source_type']), pl_csv_text($m['source_reference']), $m['debit'], $m['credit'], $m['running_balance']];
                }
            }
            $rows[] = ['Period totals', '', '', '', '', $report['debit_movement'], $report['credit_movement']];
            $rows[] = ['Closing balance', $report['closing_balance']];
        } elseif ($kind === 'profit-loss') {
            if ($from === null) { throw new DomainException('Choose a start date for profit and loss.'); }
            $report = pl_profit_loss($actorId, $companyId, $bookId, $from, $to);
            $rows[] = ['Section', 'Account ID', 'Code', 'Name', 'Amount'];
            foreach (['income', 'cost_of_sales', 'expenses'] as $group) {
                foreach ($report[$group] as $a) {
                    if (in_array((string) ($a['level'] ?? 'account'), ['class', 'group'], true)) { continue; }
                    $rows[] = [$group, (string) $a['id'], pl_csv_text($a['code']), pl_csv_text($a['name']), $a['amount']];
                }
            }
            $rows[] = ['Total income', '', '', '', $report['total_income']];
            $rows[] = ['Total cost of sales', '', '', '', $report['total_cost_of_sales']];
            $rows[] = ['Gross profit', '', '', '', $report['gross_profit']];
            $rows[] = ['Total expenses', '', '', '', $report['total_expenses']];
            $rows[] = ['Net profit', '', '', '', $report['net_profit']];
        } else {
            $report = pl_balance_sheet($actorId, $companyId, $bookId, $to);
            $rows[] = ['Section', 'Account ID', 'Code', 'Name', 'Amount'];
            foreach (['assets', 'liabilities', 'equity'] as $group) {
                foreach ($report[$group] as $a) {
                    if (in_array((string) ($a['level'] ?? 'account'), ['class', 'group'], true)) { continue; }
                    $rows[] = [$group, (string) $a['id'], pl_csv_text($a['code']), pl_csv_text($a['name']), $a['amount']];
                }
            }
            foreach (['total_assets', 'total_liabilities', 'recorded_equity', 'earned_profit', 'total_equity', 'total_liabilities_equity'] as $key) { $rows[] = [$key, '', '', '', $report[$key]]; }
            $rows[] = ['Balanced', $report['balanced'] ? 'yes' : 'no'];
        }
        if (count($rows) > 10100) { throw new DomainException('This report is too large for a synchronous export.'); }
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) { throw new RuntimeException('Could not prepare export.'); }
        try {
            foreach ($rows as $row) {
                if (fputcsv($stream, $row, ',', '"', '', "\r\n") === false) { throw new RuntimeException('Could not write export.'); }
            }
            rewind($stream);
            $csv = stream_get_contents($stream);
            if ($csv === false) { throw new RuntimeException('Could not read export.'); }
            return ['filename' => 'phpledger-' . $kind . '-book-' . $bookId . '-' . $to . '.csv', 'csv' => $csv];
        } finally { fclose($stream); }
    });
}
