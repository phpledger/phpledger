<?php
declare(strict_types=1);

/*
 * Browser adapters for the stock documents and their reports (1.2 M4).
 *
 * These are adapters only: every accounting outcome, permission and number comes from
 * stock_document_functions.php, and the templates decide nothing.
 */

// The stock screens reuse the shared starter form controls.
require_once __DIR__ . '/starter_web_functions.php';

/** Document lines submitted by the editor, with blank rows dropped. */
function pl_web_stock_document_lines(array $input): array
{
    $rows = $input['lines'] ?? [];
    if (!is_array($rows) || count($rows) > 100) { throw new DomainException('Use up to 100 stock document lines.'); }
    $lines = [];
    foreach ($rows as $row) {
        if (!is_array($row)) { continue; }
        $productId = pl_web_id($row, 'product_id');
        if ($productId < 1 && pl_web_text($row, 'quantity') === '') { continue; }
        $lines[] = ['product_id' => $productId ?: null, 'quantity' => pl_web_text($row, 'quantity')];
    }
    return $lines;
}

function pl_web_stock_documents(int $actorId, int $companyId, int $bookId, array $user, array $company, string $path, string $method): never
{
    pl_require_company_access($actorId, $companyId);
    pl_ledger_book($companyId, $bookId);
    $enabled = pl_module_available($actorId, $companyId, $bookId, 'inventory-locations');
    if ($path === '/stock-documents/settlement') { pl_web_van_settlement($actorId, $companyId, $bookId, $user, $company, $method, $enabled); }
    if ($method === 'POST') {
        pl_web_assert_scope($company, $_POST);
        $action = pl_web_text($_POST, 'action');
        $return = pl_url('/stock-documents', ['new' => $action === 'gate_pass' ? 'gate_pass' : pl_web_text($_POST, 'kind', 'stock_issue')]);
        try {
            if (pl_web_text($_POST, 'editor_action') === 'add_line' || isset($_POST['remove_line'])) {
                pl_require_module($actorId, $companyId, $bookId, 'inventory-locations');
                pl_form_failure($return, pl_web_line_action($_POST, 100), '', 200);
            }
            if ($action === 'stock_document') {
                $document = pl_post_stock_document($actorId, $companyId, $bookId, [
                    'kind' => pl_web_text($_POST, 'kind'), 'date' => pl_web_text($_POST, 'date'),
                    'from_warehouse_id' => pl_web_id($_POST, 'from_warehouse_id') ?: null,
                    'to_warehouse_id' => pl_web_id($_POST, 'to_warehouse_id') ?: null,
                    'original_document_id' => pl_web_id($_POST, 'original_document_id') ?: null,
                    'reference' => pl_web_text($_POST, 'reference'), 'reason' => pl_web_text($_POST, 'reason'),
                    'lines' => pl_web_stock_document_lines($_POST), 'idempotency_key' => pl_web_text($_POST, 'request_key'),
                ]);
                pl_notice('Stock document ' . $document['document_number'] . ' recorded. Stock moved at carrying value; no ledger entry was written.');
                pl_redirect(pl_url('/stock-documents/detail', ['id' => $document['id']]));
            }
            if ($action === 'gate_pass') {
                $pass = pl_issue_gate_pass($actorId, $companyId, $bookId, [
                    'date' => pl_web_text($_POST, 'date'), 'warehouse_id' => pl_web_id($_POST, 'warehouse_id') ?: null,
                    'direction' => pl_web_text($_POST, 'direction'), 'is_returnable' => isset($_POST['is_returnable']),
                    'covers_document_id' => pl_web_id($_POST, 'covers_document_id') ?: null,
                    'party_name' => pl_web_text($_POST, 'party_name'), 'vehicle_reference' => pl_web_text($_POST, 'vehicle_reference'),
                    'driver_name' => pl_web_text($_POST, 'driver_name'), 'purpose' => pl_web_text($_POST, 'purpose'),
                    'expected_return_date' => pl_web_text($_POST, 'expected_return_date') ?: null,
                    'reference' => pl_web_text($_POST, 'reference'), 'reason' => pl_web_text($_POST, 'reason'),
                    'idempotency_key' => pl_web_text($_POST, 'request_key'),
                ]);
                pl_notice('Gate pass ' . $pass['document_number'] . ' issued. A gate pass moves no stock and posts nothing.');
                pl_redirect(pl_url('/stock-documents/detail', ['id' => $pass['id']]));
            }
            if ($action === 'stamp') {
                $id = pl_web_id($_POST, 'id');
                pl_stamp_gate_pass($actorId, $companyId, $bookId, $id, pl_web_text($_POST, 'stamp'));
                pl_notice('Gate pass stamped at the security desk.');
                pl_redirect(pl_url('/stock-documents/detail', ['id' => $id]));
            }
            throw new DomainException('Choose a stock document action.');
        } catch (DomainException $error) {
            if ($action === 'stamp') { pl_form_failure(pl_url('/stock-documents/detail', ['id' => pl_web_id($_POST, 'id')]), [], $error->getMessage()); }
            $input = array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $_POST);
            $input['lines'] = is_array($_POST['lines'] ?? null) ? array_values(array_map(
                static fn (array $line): array => array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $line),
                array_filter($_POST['lines'], 'is_array'))) : [];
            pl_form_failure($return, $input, $error->getMessage());
        }
    }
    if ($path === '/stock-documents/detail') {
        $document = pl_get_stock_document($actorId, $companyId, $bookId, pl_web_id($_GET, 'id'));
        pl_render('stock-document', ['title' => $document['label'] . ' ' . (string) $document['document_number'], 'user' => $user, 'company' => $company,
            'document' => $document, 'enabled' => $enabled, 'form' => pl_form_state(pl_url('/stock-documents/detail', ['id' => $document['id']]))]);
    }
    $new = pl_web_text($_GET, 'new');
    if ($new !== '' && !isset(pl_stock_document_kinds()[$new])) { throw new DomainException('Choose a stock document kind to record.'); }
    $warehouses = pl_list_inventory_warehouses($actorId, $companyId, $bookId);
    $form = pl_form_state(pl_url('/stock-documents', ['new' => $new]));
    $input = $form['input'] ?: ['date' => gmdate('Y-m-d'), 'kind' => $new ?: 'stock_issue', 'request_key' => bin2hex(random_bytes(20)), 'lines' => [[], [], []]];
    pl_render('stock-documents', ['title' => 'Stock issues and returns', 'user' => $user, 'company' => $company,
        'enabled' => $enabled, 'new' => $new, 'form' => $form, 'input' => $input,
        'warehouses' => $warehouses, 'products' => pl_list_inventory_products($actorId, $companyId, $bookId),
        'documents' => pl_list_stock_documents($actorId, $companyId, $bookId, ['kind' => pl_web_text($_GET, 'kind', 'all')]),
        'kindFilter' => pl_web_text($_GET, 'kind', 'all')]);
}

/** The driver's day: review it, then approve it. Reviewing and approving post nothing. */
function pl_web_van_settlement(int $actorId, int $companyId, int $bookId, array $user, array $company, string $method, bool $enabled): never
{
    $warehouseId = pl_web_id($method === 'POST' ? $_POST : $_GET, 'warehouse');
    $date = pl_web_text($method === 'POST' ? $_POST : $_GET, 'date', gmdate('Y-m-d'));
    $return = pl_url('/stock-documents/settlement', ['warehouse' => $warehouseId ?: '', 'date' => $date]);
    if ($method === 'POST') {
        pl_web_assert_scope($company, $_POST);
        try {
            $action = pl_web_text($_POST, 'action');
            if ($action === 'review') {
                pl_review_van_settlement($actorId, $companyId, $bookId, $warehouseId, $date, pl_web_text($_POST, 'reason'), pl_web_text($_POST, 'request_key'));
                pl_notice('Settlement sheet reviewed. Nothing has been posted; the sale documents carry the money.');
            } elseif ($action === 'approve') {
                pl_approve_van_settlement($actorId, $companyId, $bookId, pl_web_id($_POST, 'settlement_id'), pl_web_text($_POST, 'reason'), pl_web_text($_POST, 'request_key'));
                pl_notice('Settlement approved. The record is now immutable.');
            } else {
                throw new DomainException('Choose whether to review or approve this settlement.');
            }
            pl_redirect($return);
        } catch (DomainException $error) {
            pl_form_failure($return, array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $_POST), $error->getMessage());
        }
    }
    $vans = array_values(array_filter(pl_list_inventory_warehouses($actorId, $companyId, $bookId), static fn (array $row): bool => $row['kind'] === 'mobile'));
    $day = null; $settlement = null;
    if ($warehouseId > 0) {
        $day = pl_van_day($actorId, $companyId, $bookId, $warehouseId, $date);
        $existing = DB::queryFirstField('SELECT id FROM pl_van_settlements WHERE book_id=%i AND warehouse_id=%i AND settlement_date=%s', $bookId, $warehouseId, $day['date']);
        $settlement = $existing === null ? null : pl_get_van_settlement($actorId, $companyId, $bookId, (int) $existing);
    }
    $form = pl_form_state($return);
    pl_render('van-settlement', ['title' => 'Van settlement', 'user' => $user, 'company' => $company, 'enabled' => $enabled,
        'vans' => $vans, 'warehouseId' => $warehouseId, 'date' => $date, 'day' => $day, 'settlement' => $settlement,
        'settlements' => pl_list_van_settlements($actorId, $companyId, $bookId, $warehouseId ?: null),
        'canApprove' => pl_van_settlement_can_approve($actorId, $companyId),
        'form' => $form, 'input' => $form['input'] ?: ['request_key' => bin2hex(random_bytes(20))]]);
}

/** The manifest's `stock-by-location` report, on the route the manifest declares. */
function pl_web_stock_by_location(int $actorId, int $companyId, int $bookId, array $user, array $company): never
{
    $asOf = pl_web_text($_GET, 'as_of', gmdate('Y-m-d'));
    $locations = pl_web_text($_GET, 'locations', 'all');
    $search = pl_web_text($_GET, 'q');
    $report = pl_stock_by_location($actorId, $companyId, $bookId, $asOf, $locations, $search);
    pl_render('stock-by-location', ['title' => 'Stock by location', 'user' => $user, 'company' => $company,
        'report' => $report, 'aggregate' => pl_stock_location_aggregate($actorId, $companyId, $bookId, $report['as_of']),
        'enabled' => pl_module_available($actorId, $companyId, $bookId, 'inventory-locations')]);
}
