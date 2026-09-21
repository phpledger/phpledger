<?php
declare(strict_types=1);

/*
 * Browser adapter for the counter point of sale (1.2 M9).
 *
 * An adapter only: every price, permission, refusal and number comes from
 * counter_pos_functions.php, and the template decides nothing. The screen is deliberately a
 * plain no-JavaScript form — a counter runs on whatever browser the shop has, and the review
 * step is a server round trip so the figures shown are the figures the server will post.
 */

require_once __DIR__ . '/starter_web_functions.php';

/** Till lines submitted by the form, with blank spare rows dropped. */
function pl_web_counter_lines(array $input): array
{
    $rows = $input['lines'] ?? [];
    if (!is_array($rows) || count($rows) > 100) { throw new DomainException('Ring up to 100 lines.'); }
    $lines = [];
    foreach ($rows as $row) {
        if (!is_array($row)) { continue; }
        $productId = pl_web_id($row, 'product_id');
        $quantities = pl_web_text($row, 'quantity') . pl_web_text($row, 'pack_quantity') . pl_web_text($row, 'unit_quantity');
        if ($productId < 1 && $quantities === '') { continue; }
        $packId = pl_web_id($row, 'pack_id');
        $lines[] = ['product_id' => $productId ?: null, 'pack_id' => $packId ?: null,
            'quantity' => pl_web_text($row, 'quantity'),
            'pack_quantity' => pl_web_text($row, 'pack_quantity'), 'unit_quantity' => pl_web_text($row, 'unit_quantity'),
            'unit_price' => pl_web_text($row, 'unit_price'), 'discount_percent' => pl_web_text($row, 'discount_percent'),
            'tax_code_id' => pl_web_id($row, 'tax_code_id') ?: null, 'description' => pl_web_text($row, 'description')];
    }
    return $lines;
}

/** The whole submitted sale, as the service's input shape. */
function pl_web_counter_input(array $post): array
{
    return ['tender' => pl_web_text($post, 'tender', 'cash'), 'date' => pl_web_text($post, 'date'),
        'warehouse_id' => pl_web_id($post, 'warehouse_id') ?: null, 'party_id' => pl_web_id($post, 'party_id') ?: null,
        'cash_account_id' => pl_web_id($post, 'cash_account_id') ?: null,
        'cash_tendered' => pl_web_text($post, 'cash_tendered'), 'due_date' => pl_web_text($post, 'due_date'),
        'reference' => pl_web_text($post, 'reference'),
        'sales_staff_id' => pl_web_id($post, 'sales_staff_id') ?: null, 'area_id' => pl_web_id($post, 'area_id') ?: null,
        'idempotency_key' => pl_web_text($post, 'request_key'), 'lines' => pl_web_counter_lines($post)];
}

/** Flatten a submitted form back into re-displayable strings after a refusal. */
function pl_web_counter_echo(array $post): array
{
    $input = array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $post);
    $input['lines'] = is_array($post['lines'] ?? null) ? array_values(array_map(
        static fn (array $line): array => array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $line),
        array_filter($post['lines'], 'is_array'))) : [];
    return $input;
}

function pl_web_counter(int $actorId, int $companyId, int $bookId, array $user, array $company, string $path, string $method): never
{
    pl_require_company_access($actorId, $companyId);
    pl_ledger_book($companyId, $bookId);
    if ($path === '/counter/receipt') {
        $receipt = pl_counter_sale_receipt($actorId, $companyId, $bookId, pl_web_id($_GET, 'id'));
        pl_render('counter-receipt', ['title' => pl_t('Counter receipt'), 'user' => $user, 'company' => $company, 'receipt' => $receipt]);
    }
    $enabled = pl_module_available($actorId, $companyId, $bookId, 'ar');
    if ($method === 'POST') {
        pl_web_assert_scope($company, $_POST);
        try {
            if (pl_web_text($_POST, 'editor_action') === 'add_line' || isset($_POST['remove_line'])) {
                pl_form_failure('/counter', pl_web_line_action($_POST, 100), '', 200);
            }
            $action = pl_web_text($_POST, 'action');
            if ($action === 'review') {
                $review = pl_review_counter_sale($actorId, $companyId, $bookId, pl_web_counter_input($_POST));
                $_SESSION['counter_review'] = ['input' => pl_web_counter_echo($_POST), 'hash' => $review['review_hash']];
                pl_redirect('/counter');
            }
            if ($action === 'sell') {
                $expected = (string) ($_SESSION['counter_review']['hash'] ?? '');
                if ($expected === '') { throw new DomainException('Review the sale before taking the money.'); }
                $result = pl_post_counter_sale($actorId, $companyId, $bookId, pl_web_counter_input($_POST), $expected);
                unset($_SESSION['counter_review']);
                pl_notice(pl_t('Sale {number} recorded. {settled}', [
                    'number' => (string) $result['document']['document_number'],
                    'settled' => $result['sale']['tender'] === 'cash'
                        ? pl_t('Cash taken and allocated to the invoice; change due {change}.', ['change' => pl_money($result['change_due'])])
                        : pl_t('The amount stands on the customer’s account.'),
                ]));
                pl_redirect(pl_url('/counter/receipt', ['id' => (int) $result['document']['id']]));
            }
            throw new DomainException('Choose whether to review this sale or take the money.');
        } catch (DomainException $error) {
            unset($_SESSION['counter_review']);
            pl_form_failure('/counter', pl_web_counter_echo($_POST), $error->getMessage());
        }
    }
    $form = pl_form_state('/counter');
    $stored = $_SESSION['counter_review'] ?? null;
    $input = $form['input'] ?: (is_array($stored) ? $stored['input'] : []);
    if ($input === []) {
        $input = ['date' => gmdate('Y-m-d'), 'tender' => 'cash', 'request_key' => bin2hex(random_bytes(20)), 'lines' => [[], [], []]];
    }
    // A book that has never moved stock has no warehouse row at all: the default one is
    // provisioned by the first movement, and reading this screen must not be what provisions
    // it. Nothing is chosen, nothing is read, and the screen says what to do first.
    $warehouses = array_values(array_filter(pl_list_inventory_warehouses($actorId, $companyId, $bookId), static fn (array $row): bool => (bool) $row['is_active']));
    $warehouseId = pl_web_id($input, 'warehouse_id');
    $known = array_map(static fn (array $row): int => (int) $row['id'], $warehouses);
    if (!in_array($warehouseId, $known, true)) {
        $warehouseId = 0;
        foreach ($warehouses as $warehouse) { if ($warehouse['is_default']) { $warehouseId = (int) $warehouse['id']; break; } }
        if ($warehouseId === 0 && $warehouses !== []) { $warehouseId = (int) $warehouses[0]['id']; }
        $input['warehouse_id'] = $warehouseId === 0 ? '' : (string) $warehouseId;
    }
    // The review is recomputed here rather than carried in the session: a session-held plan can
    // go stale against a price change and still look authoritative on screen.
    $review = null; $failure = '';
    if ($enabled && $warehouseId > 0 && pl_can_write($company) && $input['lines'] !== [] && pl_web_id($input, 'party_id') > 0) {
        try {
            $review = pl_review_counter_sale($actorId, $companyId, $bookId, pl_web_counter_input($input));
            $_SESSION['counter_review'] = ['input' => $input, 'hash' => $review['review_hash']];
        } catch (DomainException $error) {
            $failure = $error->getMessage();
            unset($_SESSION['counter_review']);
        }
    }
    $parties = array_values(array_filter(pl_starter_parties($actorId, $companyId, $bookId), static fn (array $row): bool => (bool) $row['is_customer']));
    $cashAccounts = array_values(array_filter(pl_starter_accounts($actorId, $companyId, $bookId), static fn (array $row): bool => (string) $row['role'] === 'cash_bank'));
    pl_render('counter', ['title' => pl_t('Counter sale'), 'user' => $user, 'company' => $company,
        'enabled' => $enabled, 'form' => $form, 'input' => $input, 'review' => $review, 'failure' => $failure,
        'warehouses' => $warehouses, 'warehouseId' => $warehouseId,
        'catalogue' => $warehouseId === 0 ? null : pl_counter_pos_catalogue($actorId, $companyId, $bookId, $warehouseId, pl_web_text($_GET, 'q')),
        'parties' => $parties, 'cashAccounts' => $cashAccounts,
        'policies' => pl_trading_policies($actorId, $companyId, $bookId),
        'taxCodes' => pl_list_tax_codes($actorId, $companyId, $bookId)]);
}
