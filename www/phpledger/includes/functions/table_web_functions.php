<?php
declare(strict_types=1);

function pl_table_request_options(array $input, string $kind): array
{
    $columns = match ($kind) {
        'transactions' => ['date','name','amount','status'],
        'general-journals' => ['date','description','status'],
        'account' => ['date','journal','description','source','debit','credit','balance'],
        'bank' => ['date','reference','money_in','money_out','match'],
        default => throw new DomainException('Unknown table.'),
    };
    $numbers = [];
    foreach (['length' => 25, 'start' => 0, 'draw' => 0, 'column' => 0] as $key => $default) {
        $value = $input[$key] ?? $default;
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^(0|[1-9][0-9]{0,8})$/D', (string) $value)) { throw new DomainException('Invalid table paging argument.'); }
        $numbers[$key] = (int) $value;
    }
    $size = pl_table_size($numbers['length']);
    $start = $numbers['start'];
    $draw = $numbers['draw'];
    $column = $numbers['column'];
    if ($start < 0 || $start > 2500000 || $start % $size !== 0 || $draw < 0 || $draw > 1000000 || !isset($columns[$column])) { throw new DomainException('Invalid table page or order.'); }
    $direction = pl_web_text($input, 'direction', $kind === 'account' ? 'asc' : 'desc');
    if (!in_array($direction, ['asc','desc'], true)) { throw new DomainException('Invalid table direction.'); }
    return ['page_size' => $size, 'page' => intdiv($start, $size) + 1, 'sort' => $columns[$column], 'direction' => $direction, 'search' => pl_ledger_text(pl_web_text($input, 'search'), 'Search', 160, false), 'draw' => $draw];
}

function pl_table_link(string $path, int $id, string $label, array $extra = []): string
{
    return '<a href="' . pl_e(pl_url($path, ['id' => $id] + $extra)) . '">' . pl_e($label) . '</a>';
}

/** Cells contain only server-escaped HTML, using the same browser routes and CSRF forms. */
function pl_table_data(int $actor, array $company, string $kind, array $input): array
{
    pl_web_assert_scope($company, $input);
    $companyId = (int) $company['id'];
    $bookId = (int) $company['book_id'];
    $options = pl_table_request_options($input, $kind);
    $rows = [];
    $summary = '';
    if ($kind === 'transactions') {
        $list = pl_list_documents($actor, $companyId, $bookId, array_replace(pl_filters($input), $options));
        $total = $list['records_total'];
        $filtered = $list['total'];
        foreach ($list['documents'] as $row) {
            $rows[] = [pl_table_link('/transactions/detail', $row['id'], pl_date_label($row['date']) . ' · ' . $row['number'], pl_filters($input)), pl_e($row['counterparty'] ?: pl_t('No name entered')) . '<span class="row-secondary muted">' . pl_e(ucfirst($row['kind'])) . '</span>', pl_e(pl_money($row['amount'])), pl_e(ucfirst($row['status']))];
        }
        $summary = pl_t('{count} matching records · Total {currency} {amount}',
            ['count' => $filtered, 'currency' => $company['currency'], 'amount' => pl_money($list['total_amount'])]);
    } elseif ($kind === 'general-journals') {
        $list = pl_list_general_drafts($actor, $companyId, $bookId, $options['page'], $options);
        $total = $list['records_total'];
        $filtered = $list['total'];
        foreach ($list['rows'] as $row) {
            $rows[] = [pl_table_link('/general-journals/detail', $row['id'], $row['number'] . ' · ' . pl_date_label($row['document_date'])), pl_e($row['description']) . '<span class="row-secondary muted">' . pl_e($row['reference']) . '</span>', pl_e(ucfirst($row['status'])), pl_e(pl_money($row['totals']['debit'])), pl_e(pl_money($row['totals']['credit'])), pl_e($row['totals']['balanced'] ? pl_t('Balanced') : pl_t('Needs balancing'))];
        }
    } elseif ($kind === 'account') {
        $list = pl_account_activity($actor, $companyId, $bookId, pl_web_id($input, 'account_id'), pl_web_text($input, 'as_of'), $options['page'], pl_web_text($input, 'from') ?: null, $options);
        $total = $list['total'];
        $filtered = $list['filtered_total'];
        foreach ($list['movements'] as $row) {
            $source = $row['document_id'] !== null ? pl_table_link('/transactions/detail', $row['document_id'], pl_t('View transaction')) : ($row['general_id'] !== null ? pl_table_link('/general-journals/detail', $row['general_id'], pl_t('View general journal')) : pl_e(ucfirst($row['source_type'])));
            $balance = $row['running_balance'];
            $signed = bccomp($balance, '0', 4);
            $rows[] = [pl_e(pl_date_label($row['date'])), pl_table_link('/journals/detail', $row['journal_id'], $row['journal_reference']), pl_e($row['description']), $source, pl_e(pl_money($row['debit'])), pl_e(pl_money($row['credit'])), pl_e(pl_money($signed < 0 ? substr($balance, 1) : $balance) . ($signed < 0 ? ' Cr' : ($signed > 0 ? ' Dr' : '')))];
        }
        // One literal, not two joined: a split key is invisible to any string extractor.
        $summary = pl_t('Running balances use date, journal and line order across the full period. Searching and display sorting do not change them. {filtered} matching entries of {total}.', ['filtered' => $filtered, 'total' => $total]);
    } else {
        $list = pl_bank_get_statement($actor, $companyId, $bookId, pl_web_id($input, 'statement_id'), $options);
        $total = $list['records_total'];
        $filtered = $list['filtered_total'];
        foreach ($list['rows'] as $row) {
            $match = $row['journal_line_id'] === null ? pl_e(pl_t('Unmatched')) : pl_table_link('/journals/detail', (int) $row['journal_id'],
                pl_t('Journal {journal} · line {line}', ['journal' => $row['journal_id'], 'line' => $row['journal_line_id']]));
            if (pl_can_write($company) && $list['status'] === 'draft') {
                if ($row['journal_line_id'] === null) { $match = pl_table_link('/bank-reconciliation', $list['id'], pl_t('Review matches'), ['row' => (int) $row['id']]); }
                else { $match .= '<form method="post" action="' . pl_e(pl_url('/bank-reconciliation')) . '">' . pl_csrf_field() . pl_scope_fields($company) . '<input type="hidden" name="action" value="unmatch"><input type="hidden" name="statement_id" value="' . $list['id'] . '"><input type="hidden" name="row_id" value="' . (int) $row['id'] . '"><input type="hidden" name="revision" value="' . (int) $list['revision'] . '"><button class="button">' . pl_e(pl_t('Remove match')) . '</form>'; }
            }
            $rows[] = [pl_e($row['transaction_date']), pl_e($row['reference']) . '<span class="row-secondary muted">' . pl_e($row['description']) . '</span>', pl_e(pl_money((string) $row['money_in'])), pl_e(pl_money((string) $row['money_out'])), $match];
        }
    }
    return ['draw' => $options['draw'], 'recordsTotal' => $total, 'recordsFiltered' => $filtered, 'data' => $rows, 'summary' => $summary];
}

function pl_web_table(int $actor, array $company): never
{
    header('Content-Type: application/json; charset=utf-8');
    try {
        $result = pl_ledger_transaction(fn () => pl_table_data($actor, $company, pl_web_text($_GET, 'table'), $_GET));
        echo json_encode($result, JSON_THROW_ON_ERROR);
    } catch (DomainException $error) {
        http_response_code(400);
        echo json_encode(['error' => $error->getMessage()], JSON_THROW_ON_ERROR);
    }
    exit;
}
