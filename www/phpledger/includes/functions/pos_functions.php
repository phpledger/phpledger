<?php
declare(strict_types=1);

/** A bundled original sample catalog; displayed amounts use the company's base currency. */
function pl_pos_catalog(): array
{
    $source = file_get_contents(PL_ROOT . '/resources/core/pos-catalog.json');
    if ($source === false) {
        throw new RuntimeException('The sample shop catalog is unavailable.');
    }
    $catalog = json_decode($source, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($catalog) || !is_array($catalog['products'] ?? null) || count($catalog['products']) !== 6) {
        throw new RuntimeException('The sample shop catalog is invalid.');
    }
    $seen = [];
    foreach ($catalog['products'] as $product) {
        if (!is_array($product) || !is_string($product['sku'] ?? null)
            || !preg_match('/^[A-Z0-9-]{1,40}$/D', $product['sku']) || isset($seen[$product['sku']])
            || !is_string($product['unit_price'] ?? null)
            || bccomp(pl_amount($product['unit_price']), '0', 4) <= 0) {
            throw new RuntimeException('The sample shop catalog has invalid products.');
        }
        // A whole-minor-unit price times a whole quantity keeps every cart total tenderable,
        // so cash received and change can never need more precision than the drawer holds.
        if (!pl_whole_minor_units(pl_amount($product['unit_price']))) {
            throw new RuntimeException('The sample shop catalog prices goods more finely than cash.');
        }
        foreach (['name', 'category', 'description', 'mark'] as $field) {
            pl_ledger_text($product[$field] ?? null, 'Catalog ' . $field, 200);
        }
        $seen[$product['sku']] = true;
    }
    $catalog['digest'] = hash('sha256', $source);
    return $catalog;
}

/** Normalize a request without trusting submitted prices or performing financial writes. */
function pl_pos_normalize_checkout(array $input): array
{
    if (array_diff(array_keys($input), ['checkout_key', 'catalog_digest', 'date', 'items', 'cash_received']) !== []) {
        throw new DomainException('Checkout accepts catalog items and quantities, not custom prices or totals.');
    }
    $key = pl_ledger_text($input['checkout_key'] ?? null, 'Checkout identity', 96);
    if (!preg_match('/^[A-Za-z0-9._:-]{16,96}$/D', $key)) {
        throw new DomainException('The checkout identity is invalid. Start a new sale.');
    }
    $digest = pl_ledger_text($input['catalog_digest'] ?? null, 'Catalog identity', 64);
    if (!preg_match('/^[a-f0-9]{64}$/D', $digest)) {
        throw new DomainException('The catalog identity is invalid. Reload the sample shop.');
    }
    $raw = $input['items'] ?? null;
    if (!is_array($raw) || !array_is_list($raw) || count($raw) > 6) {
        throw new DomainException('Choose up to six sample products.');
    }
    $items = [];
    $seen = [];
    $units = 0;
    foreach ($raw as $item) {
        if (!is_array($item) || array_diff(array_keys($item), ['sku', 'quantity']) !== []) {
            throw new DomainException('Only a product code and quantity may be supplied for each item.');
        }
        $sku = pl_ledger_text($item['sku'] ?? null, 'Product code', 40);
        $quantity = $item['quantity'] ?? null;
        if (!preg_match('/^[A-Z0-9-]{1,40}$/D', $sku) || isset($seen[$sku])
            || (!is_int($quantity) && !is_string($quantity))
            || !preg_match('/^(?:0|[1-9][0-9]?)$/D', (string) $quantity)) {
            throw new DomainException('Use each product once with a whole quantity from 0 to 99.');
        }
        $seen[$sku] = true;
        $quantity = (int) $quantity;
        if ($quantity > 0) {
            $items[$sku] = ['sku' => $sku, 'quantity' => $quantity];
            $units += $quantity;
        }
    }
    if ($items === [] || $units > 200) {
        throw new DomainException('Add at least one item, with no more than 200 units in a sale.');
    }
    ksort($items, SORT_STRING);
    return [
        'checkout_key' => $key,
        'catalog_digest' => $digest,
        'date' => pl_ledger_date(pl_ledger_text($input['date'] ?? null, 'Sale date', 10)),
        'items' => array_values($items),
        'cash_received' => pl_cash_amount(pl_ledger_text($input['cash_received'] ?? null, 'Cash received', 21)),
    ];
}

/** Price a normalized cart from the current catalog, without touching the books. */
function pl_pos_price_cart(array $request, array $catalog): array
{
    if (!hash_equals($catalog['digest'], $request['catalog_digest'])) {
        throw new DomainException('The sample catalog changed. Reload and review current prices before checkout.');
    }
    $products = array_column($catalog['products'], null, 'sku');
    $lines = [];
    $total = '0.0000';
    $units = 0;
    foreach ($request['items'] as $item) {
        $product = $products[$item['sku']] ?? null;
        if ($product === null) {
            throw new DomainException('A selected product is not in the current sample catalog.');
        }
        $price = pl_amount($product['unit_price']);
        $lineTotal = bcmul($price, (string) $item['quantity'], 4);
        $total = pl_amount(bcadd($total, $lineTotal, 4));
        $units += $item['quantity'];
        $lines[] = ['sku' => $item['sku'], 'name' => $product['name'], 'category' => $product['category'],
            'quantity' => $item['quantity'], 'unit_price' => $price, 'line_total' => $lineTotal];
    }
    return ['items' => $lines, 'total' => $total, 'units' => $units];
}

/** Validate and quote an unposted sale; cash confirmation is a separate action. */
function pl_pos_quote(array $input): array
{
    if (array_diff(array_keys($input), ['checkout_key', 'catalog_digest', 'date', 'items']) !== []) {
        throw new DomainException('Sale review accepts catalog items and quantities, not custom prices, totals or cash.');
    }
    $request = pl_pos_normalize_checkout($input + ['cash_received' => '0']);
    unset($request['cash_received']);
    $catalog = pl_pos_catalog();
    return ['request' => $request] + pl_pos_price_cart($request, $catalog) + [
        'catalog_id' => $catalog['id'], 'catalog_version' => $catalog['version'], 'catalog_digest' => $catalog['digest'],
    ];
}

/** Preserve the exact canonical request while an interrupted checkout is unresolved. */
function pl_pos_recovery(int $companyId, int $bookId, array $input, ?array $quote = null): array
{
    $request = pl_pos_normalize_checkout($input);
    $quotedRequest = $request;
    unset($quotedRequest['cash_received']);
    if (($quote['request'] ?? null) !== $quotedRequest) {
        $quote = null;
    }
    return ['company_id' => $companyId, 'book_id' => $bookId, 'request' => $request, 'quote' => $quote];
}

function pl_get_pos_receipt(int $actorId, int $companyId, int $bookId, int $documentId): array
{
    pl_require_company_access($actorId, $companyId);
    pl_ledger_book($companyId, $bookId);
    $sale = DB::queryFirstRow('SELECT * FROM pl_pos_sales WHERE document_id = %i AND company_id = %i AND book_id = %i FOR SHARE', $documentId, $companyId, $bookId);
    if (!$sale) {
        throw new DomainException('This shop receipt is not available in the selected business.');
    }
    $sale['document_id'] = (int) $sale['document_id'];
    $sale['company_id'] = (int) $sale['company_id'];
    $sale['book_id'] = (int) $sale['book_id'];
    $sale['created_by'] = (int) $sale['created_by'];
    $sale['cashier_name'] = (string) DB::queryFirstField('SELECT display_name FROM pl_users WHERE id = %i', $sale['created_by']);
    foreach (['total', 'cash_received', 'change_due'] as $field) {
        $sale[$field] = bcadd((string) $sale[$field], '0', 4);
    }
    $sale['items'] = json_decode($sale['cart_snapshot'], true, 512, JSON_THROW_ON_ERROR);
    $sale['document'] = pl_get_document($actorId, $companyId, $bookId, $documentId);
    if ($sale['document']['journal_id'] === null) {
        throw new RuntimeException('The shop receipt is missing its posted journal.');
    }
    $sale['number'] = 'POS-' . str_pad((string) $documentId, 6, '0', STR_PAD_LEFT);
    unset($sale['checkout_key'], $sale['request_hash'], $sale['cart_snapshot']);
    return $sale;
}

/** Catalog snapshot, cash receipt and balanced journal commit or roll back together. */
function pl_checkout_pos(int $actorId, int $companyId, int $bookId, array $input): array
{
    $request = pl_pos_normalize_checkout($input);
    $requestHash = hash('sha256', json_encode($request, JSON_THROW_ON_ERROR));
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $request, $requestHash): array {
        pl_require_company_access($actorId, $companyId, true);
        $book = pl_ledger_book($companyId, $bookId, true);
        pl_require_module($actorId, $companyId, $bookId, 'pos-showcase');
        pl_require_book_ready($companyId);
        $existing = DB::queryFirstRow('SELECT document_id, request_hash FROM pl_pos_sales WHERE book_id = %i AND company_id = %i AND checkout_key = %s FOR UPDATE', $bookId, $companyId, $request['checkout_key']);
        if ($existing) {
            if (!hash_equals((string) $existing['request_hash'], $requestHash)) {
                throw new DomainException('This checkout already recorded a different sale. Open its receipt or start a new sale.');
            }
            return pl_get_pos_receipt($actorId, $companyId, $bookId, (int) $existing['document_id']);
        }
        // An identical committed retry must survive a catalog update or unavailable catalog.
        $catalog = pl_pos_catalog();
        $priced = pl_pos_price_cart($request, $catalog);
        $lines = $priced['items'];
        $total = $priced['total'];
        if (bccomp($request['cash_received'], $total, 4) < 0) {
            throw new DomainException('Cash received must cover the sale total. Credit sales are not available in this showcase.');
        }
        $cash = DB::queryFirstField('SELECT id FROM pl_accounts WHERE company_id = %i AND book_id = %i AND semantic_key = %s AND role = %s AND type = %s AND is_active = 1 FOR SHARE', $companyId, $bookId, 'core.cash_bank', 'cash_bank', 'asset');
        $income = DB::queryFirstField('SELECT id FROM pl_accounts WHERE company_id = %i AND book_id = %i AND semantic_key = %s AND role = %s AND type = %s AND is_active = 1 FOR SHARE', $companyId, $bookId, 'core.income.sales', 'income', 'income');
        if (!$cash || !$income) {
            throw new DomainException('This business needs its active cash/bank and sales income accounts before checkout.');
        }
        $draft = pl_save_document($actorId, $companyId, $bookId, [
            'kind' => 'receipt', 'date' => $request['date'], 'amount' => $total,
            'money_account_id' => (int) $cash, 'category_account_id' => (int) $income,
            'counterparty' => 'Walk-in sample customer', 'reference' => 'POS-' . $request['checkout_key'],
            'memo' => 'General-shop POS showcase. Sample catalog; no tax, stock or cost-of-goods entries.',
            'creation_key' => 'pos:' . $request['checkout_key'],
        ]);
        $posted = pl_post_document($actorId, $companyId, $bookId, $draft['id'], $draft['revision']);
        DB::insert('pl_pos_sales', [
            'document_id' => $posted['id'], 'company_id' => $companyId, 'book_id' => $bookId,
            'checkout_key' => $request['checkout_key'], 'request_hash' => $requestHash, 'currency' => $book['currency'],
            'total' => $total, 'cash_received' => $request['cash_received'],
            'change_due' => bcsub($request['cash_received'], $total, 4),
            'catalog_id' => $catalog['id'], 'catalog_version' => $catalog['version'], 'catalog_digest' => $catalog['digest'],
            'cart_snapshot' => json_encode($lines, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'created_by' => $actorId,
        ]);
        return pl_get_pos_receipt($actorId, $companyId, $bookId, $posted['id']);
    });
}

/** Scoped business review; pl_pos_quote remains a pure, non-authorizing pricing helper. */
function pl_review_pos(int $actorId, int $companyId, int $bookId, array $input): array
{
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $input): array {
        pl_require_module($actorId, $companyId, $bookId, 'pos-showcase');
        pl_require_book_ready($companyId);
        return pl_pos_quote($input);
    });
}
