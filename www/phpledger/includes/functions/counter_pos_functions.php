<?php
declare(strict_types=1);

/*
 * The counter point of sale, over real stock (1.2 M9).
 *
 * Owner decision B34 asks for "a POS for counter sales" over the company's actual stock, and
 * research decision 8 says explicitly that this is not the illustrative sample cart at /pos:
 * that screen prices six bundled products, takes cash, writes one receipt document and moves
 * no stock at all. This one sells what the selected location is actually holding.
 *
 * It is a till, not a second accounting system. Every sale becomes an ordinary customer
 * invoice through pl_save_and_post_ar_document(), which means:
 *
 *   - the stock leg is pl_inventory_issue() through the invoice's own issue service, off the
 *     selected warehouse's balance at its moving-average carrying value;
 *   - the money is the existing recognition journal, plus — for a cash sale — the invoice's
 *     own `cash_received`, settled by pl_settle_open_item() inside the same posting action,
 *     so a counter sale can never leave a receivable standing against money in the drawer;
 *   - tax, discounts, pack resolution, numbering, the print pipeline and the correction rules
 *     are the ones every other invoice already has.
 *
 * There is no counter-sale table, no counter-sale journal and no counter-sale number series.
 * A counter sale IS an invoice that names a warehouse and took cash, and history reads it as
 * one for ever.
 *
 * The same service records a sale from a van: the warehouse decides. That is what makes the
 * driver's day of DOCUMENT-MODEL.md §3 recordable today — the salesman rings a sale up
 * against the van's stock, and it lands in the van's `sold` bucket at settlement. The one
 * difference is the credit check: at a counter the server is present, so a credit sale over
 * the customer's limit is refused now; from a van the device may be offline, so the limit is
 * checked at settlement instead (research decision 4).
 */

/** The two ways a counter sale is paid for (research decision 3: both, chosen per sale). */
function pl_counter_tenders(): array
{
    return ['cash' => 'Cash now', 'credit' => 'On the customer\'s account'];
}

/**
 * What the till may sell from one location: the products actually held there.
 *
 * On-hand quantity and carrying value come from pl_inventory_balance() for that warehouse, so
 * the number on the till is the number in the stock ledger. Non-stock products are listed
 * too — a service line on a counter invoice is legitimate and has no balance to show.
 *
 * Carrying value is withheld from a reader without `cost.view` (owner decision B58): the
 * price is what a counter hand needs, the cost is not, and this is a read, so an API client
 * is refused it exactly as the screen is.
 */
function pl_counter_pos_catalogue(int $actorId, int $companyId, int $bookId, int $warehouseId, string $search = '', ?string $asOf = null): array
{
    pl_require_company_access($actorId, $companyId);
    pl_ledger_book($companyId, $bookId);
    $warehouse = pl_get_inventory_warehouse($actorId, $companyId, $bookId, $warehouseId);
    $asOf = pl_ledger_date($asOf ?? gmdate('Y-m-d'));
    $search = pl_ledger_text($search, 'Search', 160, false);
    $costVisible = pl_report_cost_visible($actorId, $companyId, $bookId, 'counter-pos');
    $products = [];
    foreach (pl_list_inventory_products($actorId, $companyId, $bookId) as $product) {
        if (!(bool) $product['is_active']) { continue; }
        if ($search !== '' && stripos((string) $product['name'], $search) === false && stripos((string) $product['sku'], $search) === false) { continue; }
        $productId = (int) $product['id'];
        $stock = (string) $product['kind'] === 'stock';
        $onHand = null; $value = null; $average = null; $sellable = true;
        if ($stock) {
            $balance = pl_inventory_balance($actorId, $companyId, $bookId, $productId, $asOf, $warehouse['id']);
            $onHand = $balance['quantity'];
            $sellable = bccomp($onHand, '0', 4) > 0;
            if ($costVisible) { $value = $balance['value_base']; $average = $balance['average_cost']; }
        }
        $packs = [];
        foreach (pl_list_product_packs($actorId, $companyId, $bookId, $productId) as $pack) {
            if (!(bool) $pack['is_active']) { continue; }
            $packs[] = ['pack_id' => (int) $pack['id'], 'code' => (string) $pack['code'], 'name' => (string) $pack['name'],
                'units_per_pack' => pl_amount((string) $pack['units_per_pack'])];
        }
        $products[] = ['product_id' => $productId, 'sku' => (string) $product['sku'], 'name' => (string) $product['name'],
            'kind' => (string) $product['kind'], 'base_unit' => (string) $product['base_unit'],
            'selling_price' => pl_amount((string) $product['selling_price']),
            'on_hand' => $onHand, 'value_base' => $value, 'average_cost' => $average,
            'sellable' => $sellable, 'packs' => $packs];
    }
    return ['warehouse' => $warehouse, 'as_of' => $asOf, 'search' => $search,
        'cost_visible' => $costVisible, 'products' => $products];
}

/**
 * Normalise one till sale. Pure: no database, no authorisation, no writes.
 *
 * Input: date, warehouse_id, party_id, tender (`cash`|`credit`), cash_account_id and
 * cash_tendered for a cash sale, due_date for a credit sale, reference, sales_staff_id,
 * area_id, idempotency_key, and lines of {product_id, pack_id?, pack_quantity, unit_quantity
 * or quantity, unit_price?, discount_percent?, tax_code_id?}.
 *
 * Quantities are entered as packs plus loose units, which is how a distributor's counter
 * actually counts: "three cartons and four bottles". The resolution to a base quantity is the
 * existing pl_trading_pack_quantity(), reached through the invoice normaliser, so the till and
 * the invoice editor can never disagree about what a carton is.
 */
function pl_counter_sale_input(array $input): array
{
    $tender = $input['tender'] ?? null;
    if (!is_string($tender) || !isset(pl_counter_tenders()[$tender])) {
        throw new DomainException('Choose whether this sale is paid in cash now or goes on the customer\'s account.');
    }
    foreach (['warehouse_id', 'party_id'] as $field) {
        if (!is_int($input[$field] ?? null) || $input[$field] < 1) {
            throw new DomainException($field === 'party_id'
                ? 'Choose the customer this sale is for. A walk-in counter uses the business\'s own walk-in customer record.'
                : 'Choose the stock location this sale is served from.');
        }
    }
    $rows = $input['lines'] ?? null;
    if (!is_array($rows) || !array_is_list($rows) || $rows === [] || count($rows) > 100) {
        throw new DomainException('Ring up between one and 100 lines.');
    }
    $lines = []; $seen = [];
    foreach ($rows as $row) {
        if (!is_array($row) || !is_int($row['product_id'] ?? null) || $row['product_id'] < 1) {
            throw new DomainException('Choose a product on every till line.');
        }
        $packId = isset($row['pack_id']) && $row['pack_id'] !== '' ? pl_oi_id($row, 'pack_id') : null;
        $key = $row['product_id'] . ':' . ($packId ?? 'base');
        if (isset($seen[$key])) { throw new DomainException('Ring each product and pack up once, with its whole quantity on one line.'); }
        $seen[$key] = true;
        $line = ['product_id' => $row['product_id'], 'pack_id' => $packId,
            // A till line describes itself: an empty description becomes the product's own name
            // when the sale is priced, where the product master is readable.
            'description' => pl_ledger_text($row['description'] ?? '', 'Line description', 500, false),
            'discount_percent' => pl_trading_discount_percent(trim((string) ($row['discount_percent'] ?? '')) !== '' ? trim((string) $row['discount_percent']) : '0'),
            'tax_code_id' => isset($row['tax_code_id']) && $row['tax_code_id'] !== '' ? pl_oi_id($row, 'tax_code_id') : null,
            'unit_price' => pl_amount(pl_ledger_text($row['unit_price'] ?? null, 'Unit price', 30))];
        if ($packId === null) {
            $line['quantity'] = pl_amount(pl_ledger_text($row['quantity'] ?? null, 'Quantity', 30));
            if (bccomp($line['quantity'], '0', 4) <= 0) { throw new DomainException('Every till line needs a positive quantity.'); }
        } else {
            $number = static fn (mixed $value): string => is_string($value) && trim($value) !== '' ? trim($value) : (is_int($value) ? (string) $value : '0');
            $line['pack_quantity'] = pl_amount(pl_ledger_text($number($row['pack_quantity'] ?? null), 'Packs', 30));
            $line['unit_quantity'] = pl_amount(pl_ledger_text($number($row['unit_quantity'] ?? null), 'Loose units', 30));
            if (bccomp($line['pack_quantity'], '0', 4) === 0 && bccomp($line['unit_quantity'], '0', 4) === 0) {
                throw new DomainException('A pack line needs a number of packs, loose units, or both.');
            }
        }
        $lines[] = $line;
    }
    $sale = ['tender' => $tender,
        'date' => pl_ledger_date(pl_ledger_text($input['date'] ?? null, 'Sale date', 10)),
        'warehouse_id' => $input['warehouse_id'], 'party_id' => $input['party_id'],
        'reference' => pl_ledger_text($input['reference'] ?? '', 'Reference', 120, false),
        'sales_staff_id' => isset($input['sales_staff_id']) && $input['sales_staff_id'] !== '' ? pl_oi_id($input, 'sales_staff_id') : null,
        'area_id' => isset($input['area_id']) && $input['area_id'] !== '' ? pl_oi_id($input, 'area_id') : null,
        'idempotency_key' => pl_ledger_text($input['idempotency_key'] ?? null, 'Sale identity', 128),
        'lines' => $lines];
    if ($tender === 'cash') {
        if (!is_int($input['cash_account_id'] ?? null) || $input['cash_account_id'] < 1) {
            throw new DomainException('Choose the till or bank account the money goes into.');
        }
        $sale['cash_account_id'] = $input['cash_account_id'];
        // Physical money, so whole notes and coins: this is the rule issue #88 established for
        // the showcase till and it belongs on a real one at least as much.
        $sale['cash_tendered'] = pl_cash_amount(pl_ledger_text($input['cash_tendered'] ?? null, 'Cash tendered', 21));
        $sale['due_date'] = $sale['date'];
    } else {
        $sale['cash_account_id'] = null;
        $sale['cash_tendered'] = '0.0000';
        $due = $input['due_date'] ?? null;
        $sale['due_date'] = $due === null || $due === '' ? $sale['date'] : pl_ledger_date(pl_ledger_text($due, 'Due date', 10));
        if ($sale['due_date'] < $sale['date']) { throw new DomainException('The due date cannot precede the sale date.'); }
    }
    return $sale;
}

/**
 * The income account a till line posts to.
 *
 * It is the product's own `sales_account_id` and nothing else. A cashier is never asked to
 * choose a general-ledger account — that is the whole reason a product master carries one —
 * and the till therefore refuses to sell a product nobody has finished setting up rather than
 * guessing an account or falling back to a default that would quietly misclassify revenue.
 */
function pl_counter_sale_line_account(array $product): int
{
    if (($product['sales_account_id'] ?? null) === null) {
        throw new DomainException('The product ' . (string) $product['sku'] . ' has no sales account, so the till cannot post it. Name one on the product first.');
    }
    return (int) $product['sales_account_id'];
}

/**
 * The invoice input one till sale becomes.
 *
 * `cash_received` is the invoice total, not the tender: the change handed back is money that
 * never entered the business, so only the total is an accounting figure. The tender and the
 * change are the drawer's arithmetic and are reported by the till, not posted.
 */
function pl_counter_sale_document_input(array $sale, string $currency, ?string $total = null): array
{
    $lines = [];
    foreach ($sale['lines'] as $index => $line) {
        $lines[$index] = ['description' => $line['description'], 'product_id' => $line['product_id'],
            'account_id' => $line['account_id'] ?? null,
            'unit_price' => $line['unit_price'], 'discount_percent' => $line['discount_percent'],
            'tax_code_id' => $line['tax_code_id']];
        if ($line['pack_id'] === null) {
            $lines[$index]['quantity'] = $line['quantity'];
        } else {
            $lines[$index] += ['pack_id' => $line['pack_id'], 'pack_quantity' => $line['pack_quantity'], 'unit_quantity' => $line['unit_quantity']];
        }
    }
    return ['kind' => 'invoice', 'party_id' => $sale['party_id'], 'date' => $sale['date'],
        'due_date' => $sale['due_date'], 'currency' => $currency,
        'warehouse_id' => $sale['warehouse_id'], 'sales_staff_id' => $sale['sales_staff_id'], 'area_id' => $sale['area_id'],
        'reference' => $sale['reference'],
        'terms' => '', 'notes' => '',
        'cash_received' => $sale['tender'] === 'cash' && $total !== null ? $total : '0.0000',
        'cash_account_id' => $sale['tender'] === 'cash' ? $sale['cash_account_id'] : null,
        'creation_key' => 'counter:' . $sale['idempotency_key'],
        'lines' => array_values($lines)];
}

/**
 * What the selected location holds of every stocked product on the sale, and what is left
 * after it. This runs **before** the invoice is priced, deliberately.
 *
 * The posting funnel refuses a negative issue from inside pl_inventory_movement_plan(), and
 * pl_preview_ar_document() reaches it, so an over-sold cart would otherwise come back as
 * "This movement would leave negative stock or an unexplained stock value without quantity" —
 * true, and useless to a cashier. The till answers first, in the words of a shop: this
 * location does not hold enough of these items.
 *
 * The balance is read with no as-of date, which is what the issue plan itself uses, so the
 * number the till shows is the number the posting will act on rather than a near miss.
 *
 * @param array $lines normalised AR document lines, with packs already resolved to base units
 */
function pl_counter_sale_stock(int $actorId, int $companyId, int $bookId, array $sale, array $lines): array
{
    $rows = []; $short = []; $planned = [];
    foreach ($lines as $index => $line) {
        $productId = ($line['product_id'] ?? null) === null ? null : (int) $line['product_id'];
        if ($productId === null) { continue; }
        $product = pl_get_inventory_product($actorId, $companyId, $bookId, $productId);
        if ((string) $product['kind'] !== 'stock') { continue; }
        $balance = pl_inventory_balance($actorId, $companyId, $bookId, $productId, null, $sale['warehouse_id']);
        // Two lines of the same product draw on one balance, exactly as the issue plan's own
        // running basis does, so a cart that is short only in total is still caught.
        $planned[$productId] = bcadd($planned[$productId] ?? '0.0000', pl_amount((string) $line['quantity']), 4);
        $after = bcsub($balance['quantity'], $planned[$productId], 4);
        $row = ['line_number' => $index + 1, 'product_id' => $productId, 'sku' => (string) $product['sku'],
            'name' => (string) $product['name'], 'quantity' => pl_amount((string) $line['quantity']),
            'on_hand' => $balance['quantity'], 'on_hand_after' => $after, 'sufficient' => bccomp($after, '0', 4) >= 0];
        $rows[] = $row;
        if (!$row['sufficient'] && !in_array($row['sku'], $short, true)) { $short[] = $row['sku']; }
    }
    return ['rows' => $rows, 'short' => $short];
}

/** The message a cashier is given when the shelf cannot serve the cart. */
function pl_counter_short_stock_message(array $short): string
{
    return 'This location does not hold enough of ' . implode(', ', $short) . '. Issue stock to it, or sell what is there.';
}

/**
 * The till's own answers about a sale, over and above the invoice preview: the stock it takes,
 * the change the drawer owes, and — for a credit sale — where it leaves the customer against
 * their limit.
 */
function pl_counter_sale_readout(int $actorId, int $companyId, int $bookId, array $sale, array $plan, array $stock): array
{
    $document = $plan['document'];
    $total = pl_amount((string) $document['total']);
    $onHand = $stock['rows']; $short = $stock['short'];
    $cash = null;
    if ($sale['tender'] === 'cash') {
        $tendered = $sale['cash_tendered'];
        $cash = ['tendered' => $tendered, 'total' => $total, 'change_due' => bcsub($tendered, $total, 4),
            'sufficient' => bccomp($tendered, $total, 4) >= 0,
            // A total finer than the drawer can pay is not tenderable, which is exactly the
            // defect issue #88 recorded on the showcase till. Here the total comes out of tax
            // and discount arithmetic, so the till says so rather than silently rounding.
            'tenderable' => pl_whole_minor_units($total)];
    }
    $credit = null;
    if ($sale['tender'] === 'credit') {
        $credit = pl_party_credit_headroom($companyId, $bookId, $sale['party_id'], $sale['date'], $total);
    }
    $warehouse = pl_get_inventory_warehouse($actorId, $companyId, $bookId, $sale['warehouse_id']);
    $costVisible = pl_report_cost_visible($actorId, $companyId, $bookId, 'counter-pos');
    return ['warehouse' => $warehouse, 'tender' => $sale['tender'], 'total' => $total,
        'stock' => $onHand, 'short' => $short, 'cash' => $cash, 'credit' => $credit,
        'cost_visible' => $costVisible,
        // Credit on the road is settled later (research decision 4); at a counter the server is
        // right here, so the limit is enforced now and the readout says which rule applied.
        'credit_enforced_now' => $sale['tender'] === 'credit' && !$warehouse['is_mobile'],
        'margin_visible' => pl_report_margin_visible($actorId, $companyId, $bookId, 'counter-pos')];
}

/**
 * Review a till sale without saving or posting anything.
 *
 * Returns the ordinary invoice preview — the same one the invoice editor reviews, with the
 * same posting plan and the same hash — plus the till readout. The hash a confirmation is
 * held to is the invoice preview's, unchanged, so the till adds no second review contract.
 */
function pl_review_counter_sale(int $actorId, int $companyId, int $bookId, array $input): array
{
    $sale = pl_counter_sale_input($input);
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $sale): array {
        pl_require_company_access($actorId, $companyId, true);
        $book = pl_ledger_book($companyId, $bookId, true);
        pl_require_module($actorId, $companyId, $bookId, 'ar');
        pl_require_book_ready($companyId);
        // A van is a stock location like any other here; pl_inventory_movement_warehouse()
        // applies the locations-module rule for a non-default one, as it does everywhere else.
        pl_inventory_movement_warehouse($actorId, $companyId, $bookId, $sale['warehouse_id']);
        // Each line describes itself and posts to its own product's income account. Both come
        // from the product master, here, where it is readable — never from the cashier.
        foreach ($sale['lines'] as $index => $line) {
            $product = pl_get_inventory_product($actorId, $companyId, $bookId, $line['product_id']);
            if ($line['description'] === '') { $sale['lines'][$index]['description'] = (string) $product['name']; }
            $sale['lines'][$index]['account_id'] = pl_counter_sale_line_account($product);
        }
        $documentInput = pl_counter_sale_document_input($sale, (string) $book['currency']);
        // Normalised once, without a database write, to learn the base quantity behind every
        // pack; the shelf is then checked before the invoice is priced, because pricing walks
        // into the posting funnel's own negative-stock refusal.
        $resolved = pl_normalize_ar_document($documentInput, pl_trading_document_pack_sizes($actorId, $companyId, $bookId, $documentInput));
        $stock = pl_counter_sale_stock($actorId, $companyId, $bookId, $sale, $resolved['lines']);
        if ($stock['short'] !== []) { throw new DomainException(pl_counter_short_stock_message($stock['short'])); }
        $plan = pl_preview_ar_document($actorId, $companyId, $bookId, $documentInput);
        $total = pl_amount((string) $plan['document']['total']);
        if ($sale['tender'] === 'cash') {
            // Priced once to know the total, then re-previewed with the cash on it, because the
            // cash line is part of the reviewed plan the confirmation is held to.
            $documentInput = pl_counter_sale_document_input($sale, (string) $book['currency'], $total);
            $plan = pl_preview_ar_document($actorId, $companyId, $bookId, $documentInput);
        }
        return ['sale' => $sale, 'document_input' => $documentInput, 'plan' => $plan,
            'review_hash' => hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR)),
            'readout' => pl_counter_sale_readout($actorId, $companyId, $bookId, $sale, $plan, $stock)];
    });
}

/**
 * The invoice-editor request key one till sale consumes, and the sale it already posted.
 *
 * A till is the screen most likely to be pressed twice: a cashier who does not see a response
 * presses again. The invoice services already make that safe — pl_ar_action() returns the
 * first result for a repeated key — but the till's own refusals run before them and would
 * otherwise turn an honest retry into "this location does not hold enough", because the first
 * attempt already took the stock. So a completed sale is recognised first and replayed.
 */
function pl_counter_sale_posted(int $companyId, int $bookId, array $sale): ?array
{
    $key = pl_request_key('ar-editor:' . hash('sha256', 'counter:' . $sale['idempotency_key']));
    $prior = DB::queryFirstField('SELECT result_json FROM pl_ar_document_actions WHERE book_id=%i AND request_key=%s FOR SHARE', $bookId, $key);
    if ($prior === null) { return null; }
    $result = json_decode((string) $prior, true, 512, JSON_THROW_ON_ERROR);
    return is_array($result) && isset($result['id']) ? $result : null;
}

/**
 * Ring the sale up: one atomic save, review and post through the invoice services.
 *
 * The review hash is checked by pl_save_and_post_ar_document() itself, so a cart whose prices,
 * tax or stock basis moved between review and confirmation is refused there rather than here.
 * What this adds is the refusals that belong to a till and to nothing else.
 */
function pl_post_counter_sale(int $actorId, int $companyId, int $bookId, array $input, string $expectedHash): array
{
    $normalised = pl_counter_sale_input($input);
    // One transaction with the book lock held, so the stock on hand and the customer's credit
    // exposure that the refusals below are judged on are the ones the posting then uses. A
    // second till selling the last case, or taking the customer past the limit, cannot slip
    // between the check and the post.
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $input, $expectedHash, $normalised): array {
        pl_require_company_access($actorId, $companyId, true);
        pl_ledger_book($companyId, $bookId, true);
        $posted = pl_counter_sale_posted($companyId, $bookId, $normalised);
        if ($posted !== null) {
            // A replay reports the sale, not a fresh readout: the stock on hand and the credit
            // headroom a readout would show now are the ones after this sale, and presenting
            // them as if they were the sale's own basis would be a lie.
            $document = pl_get_ar_document($actorId, $companyId, $bookId, (int) $posted['id']);
            return ['document' => $document, 'sale' => $normalised, 'readout' => null, 'replayed' => true,
                'change_due' => $normalised['tender'] === 'cash'
                    ? bcsub($normalised['cash_tendered'], pl_amount((string) $document['total']), 4) : '0.0000'];
        }
        $review = pl_review_counter_sale($actorId, $companyId, $bookId, $input);
        $sale = $review['sale'];
        $readout = $review['readout'];
        if (!hash_equals($expectedHash, $review['review_hash'])) {
            throw new DomainException('The sale or its prices changed. Review the sale again before taking the money.');
        }
        // The review already refuses a cart the shelf cannot serve; this repeats it because the
        // refusal is a financial control and must not depend on the order of two functions.
        if ($readout['short'] !== []) { throw new DomainException(pl_counter_short_stock_message($readout['short'])); }
        if ($sale['tender'] === 'cash') {
            if (!$readout['cash']['tenderable']) {
                throw new DomainException('This sale totals ' . $readout['total'] . ', which is finer than the smallest coin. Adjust a price or a discount so the total can be paid in cash.');
            }
            if (!$readout['cash']['sufficient']) {
                throw new DomainException('The cash tendered is less than the sale total. Take the full amount, or put the sale on the customer\'s account.');
            }
        }
        if ($readout['credit_enforced_now'] && $readout['credit']['would_exceed']) {
            throw new DomainException('This sale would put ' . $readout['credit']['exposure_after_base']
                . ' on the customer\'s account against a credit limit of ' . (string) $readout['credit']['credit_limit']
                . '. Take payment, or have an administrator change the limit.');
        }
        $document = pl_save_and_post_ar_document($actorId, $companyId, $bookId, $review['document_input'], $expectedHash);
        return ['document' => $document, 'sale' => $sale, 'readout' => $readout, 'replayed' => false,
            'change_due' => $sale['tender'] === 'cash' ? $readout['cash']['change_due'] : '0.0000'];
    });
}

/**
 * Read one posted counter sale back as a receipt.
 *
 * It is an invoice, so it is read through pl_get_ar_document() and the company/book scope,
 * the revision snapshot and the correction rules are that document's own. The till adds only
 * the words a receipt needs: which location served it, and how it was paid.
 */
function pl_counter_sale_receipt(int $actorId, int $companyId, int $bookId, int $documentId): array
{
    $document = pl_get_ar_document($actorId, $companyId, $bookId, $documentId);
    if ($document['kind'] !== 'invoice') { throw new DomainException('A counter receipt is a customer invoice.'); }
    $cash = pl_amount((string) ($document['cash_received'] ?? '0'));
    $total = pl_amount((string) $document['total']);
    return $document + [
        'tender' => bccomp($cash, '0', 4) === 0 ? 'credit' : (bccomp($cash, $total, 4) >= 0 ? 'cash' : 'part_cash'),
        'outstanding' => bcsub($total, $cash, 4),
        'warehouse' => $document['warehouse_id'] === null ? null : pl_get_inventory_warehouse($actorId, $companyId, $bookId, (int) $document['warehouse_id']),
    ];
}
