<?php
declare(strict_types=1);

/*
 * One distributor's driver day, recorded through the shipped services (1.2 M9).
 *
 * This file defines the scenario and nothing else: it opens no database connection, reads no
 * configuration and runs nothing when it is included. Two callers use it —
 * tools/simulate-distribution.php, which builds a throwaway company and prints the day, and
 * tests/distribution_simulation_test.php, which asserts every figure it produces. Keeping the
 * scenario in one place is the point: the printed walkthrough and the proof are the same day.
 *
 * It is a development tool. It is not in tools/package-files.json and does not ship.
 *
 * The day follows DOCUMENT-MODEL.md §3 exactly:
 *
 *   1. morning load        stock issue, warehouse -> van, with an outward returnable gate pass
 *   2. sales on the road   counter/van sales through the till: one cash, two on credit
 *   3. mid-day re-issue    a second stock issue against the same van, linked to the first
 *   4. a customer return   a credit note whose stock goes back into the van (decision 5)
 *   5. end of route        stock return, van -> warehouse, of everything unsold
 *   6. settlement          reviewed, credit limits checked, then approved
 *
 * Nothing here is a new accounting path. Every document is raised by the service a user's
 * screen calls, so what the simulation proves is what an operator would get.
 */

/**
 * What the scenario needs. Both callers build this the same way; only the source of the
 * company differs.
 *
 * @param array{actor_id:int, company_id:int, book_id:int, warehouse_id:int, van_id:int,
 *   product_a:int, product_b:int, cash_account_id:int, cash_customer_id:int,
 *   credit_customer_id:int, date:string} $c
 */
function pl_distribution_scenario(array $c): array
{
    $args = [$c['actor_id'], $c['company_id'], $c['book_id']];
    $date = $c['date'];
    $key = static fn (string $name): string => 'sim:' . $name . ':' . bin2hex(random_bytes(12));

    // 1. The morning load: sixty of A and forty of B onto the van, and the gate pass the
    //    security desk keeps. The gate pass moves nothing and posts nothing (decision 2).
    $issue = pl_post_stock_document(...array_merge($args, [[
        'kind' => 'stock_issue', 'date' => $date,
        'from_warehouse_id' => $c['warehouse_id'], 'to_warehouse_id' => $c['van_id'],
        'reference' => 'Morning load', 'reason' => 'Load the van for the route',
        'lines' => [['product_id' => $c['product_a'], 'quantity' => '60'], ['product_id' => $c['product_b'], 'quantity' => '40']],
        'idempotency_key' => $key('issue'),
    ]]));
    $pass = pl_issue_gate_pass(...array_merge($args, [[
        'date' => $date, 'warehouse_id' => $c['warehouse_id'], 'direction' => 'out', 'is_returnable' => true,
        'expected_return_date' => $date, 'covers_document_id' => (int) $issue['id'],
        'driver_name' => 'Sample driver', 'vehicle_reference' => 'SAMPLE-4471',
        'purpose' => 'Outward load for the day\'s route', 'reason' => 'Gate pass for the morning load',
        'idempotency_key' => $key('pass'),
    ]]));
    pl_stamp_gate_pass(...array_merge($args, [(int) $pass['id'], 'out']));

    // 2. Sales on the road, through the same till a counter uses. The warehouse on each sale is
    //    the van, so the stock leaves the van's own balance and lands in its `sold` bucket.
    $sell = static function (array $sale) use ($args): array {
        $review = pl_review_counter_sale(...array_merge($args, [$sale]));
        return pl_post_counter_sale(...array_merge($args, [$sale, $review['review_hash']]));
    };
    $base = ['date' => $date, 'warehouse_id' => $c['van_id']];
    $cashSale = $sell($base + ['tender' => 'cash', 'party_id' => $c['cash_customer_id'],
        'cash_account_id' => $c['cash_account_id'], 'cash_tendered' => '500',
        'reference' => 'Van sale, paid', 'idempotency_key' => $key('sale-cash'),
        'lines' => [['product_id' => $c['product_a'], 'quantity' => '20', 'unit_price' => '25']]]);
    $creditSale = $sell($base + ['tender' => 'credit', 'party_id' => $c['credit_customer_id'],
        'due_date' => $date, 'reference' => 'Van sale, on account', 'idempotency_key' => $key('sale-credit-1'),
        'lines' => [['product_id' => $c['product_a'], 'quantity' => '10', 'unit_price' => '25'],
            ['product_id' => $c['product_b'], 'quantity' => '5', 'unit_price' => '40']]]);

    // 3. The van sells out of B and goes back for more: a re-issue against the same day and the
    //    same van, linked to the morning issue, on its own number series.
    $reissue = pl_post_stock_document(...array_merge($args, [[
        'kind' => 'stock_reissue', 'date' => $date,
        'from_warehouse_id' => $c['warehouse_id'], 'to_warehouse_id' => $c['van_id'],
        'original_document_id' => (int) $issue['id'],
        'reference' => 'Mid-day top-up', 'reason' => 'The van sold out and came back',
        'lines' => [['product_id' => $c['product_a'], 'quantity' => '20']],
        'idempotency_key' => $key('reissue'),
    ]]));
    $secondCreditSale = $sell($base + ['tender' => 'credit', 'party_id' => $c['credit_customer_id'],
        'due_date' => $date, 'reference' => 'Van sale, on account', 'idempotency_key' => $key('sale-credit-2'),
        'lines' => [['product_id' => $c['product_b'], 'quantity' => '10', 'unit_price' => '40']]]);

    // 4. A customer hands five units of A back, in sellable condition. Decision 5: it goes back
    //    into the van's own stock, not to the warehouse, because the next stop can still buy it.
    //    The credit note's stock leg returns to the warehouse the original sale issued from,
    //    which is the van, and consumes that sale's exact unreturned carrying basis.
    $creditNote = pl_save_ar_document(...array_merge($args, [[
        'kind' => 'customer_credit', 'party_id' => $c['credit_customer_id'], 'date' => $date, 'due_date' => $date,
        'currency' => (string) $creditSale['document']['currency'], 'warehouse_id' => $c['van_id'],
        'original_document_id' => (int) $creditSale['document']['id'],
        'reference' => 'Goods returned on the route', 'creation_key' => $key('credit-note'),
        // A credit reverses revenue where the sale recognised it, so the line takes the income
        // account of the original line it credits rather than being resolved again.
        'lines' => [['description' => 'Returned in sellable condition', 'product_id' => $c['product_a'],
            'account_id' => (int) $creditSale['document']['lines'][0]['account_id'],
            'quantity' => '5', 'unit_price' => '25', 'original_line_number' => 1]],
    ]]));
    $creditNote = pl_post_ar_document(...array_merge($args, [(int) $creditNote['id'], (int) $creditNote['revision']]));

    // 5. End of the route: everything the van still holds goes back to the warehouse.
    $remaining = static fn (int $productId): string => pl_inventory_balance(...array_merge($args, [$productId, $date, $c['van_id']]))['quantity'];
    $return = pl_post_stock_document(...array_merge($args, [[
        'kind' => 'stock_return', 'date' => $date,
        'from_warehouse_id' => $c['van_id'], 'to_warehouse_id' => $c['warehouse_id'],
        'reference' => 'Unsold stock returned', 'reason' => 'End of route',
        'lines' => [['product_id' => $c['product_a'], 'quantity' => $remaining($c['product_a'])],
            ['product_id' => $c['product_b'], 'quantity' => $remaining($c['product_b'])]],
        'idempotency_key' => $key('return'),
    ]]));
    pl_stamp_gate_pass(...array_merge($args, [(int) $pass['id'], 'in']));

    return ['context' => $c, 'issue' => $issue, 'gate_pass' => $pass, 'reissue' => $reissue, 'return' => $return,
        'cash_sale' => $cashSale['document'], 'credit_sale' => $creditSale['document'],
        'second_credit_sale' => $secondCreditSale['document'], 'credit_note' => $creditNote,
        'day' => pl_van_day(...array_merge($args, [$c['van_id'], $date]))];
}

/**
 * Settle the day: review it, find the credit limit broken, clear it, review again, approve.
 *
 * Each step's outcome is returned rather than thrown away, including the two refusals, because
 * the refusals are the behaviour worth showing: a settlement never absorbs a broken credit
 * limit, and an approval is always held to a review of the day as it now stands.
 */
function pl_distribution_settlement(array $c, string $clearingReceipt, int $clearDocumentId): array
{
    $args = [$c['actor_id'], $c['company_id'], $c['book_id']];
    $key = static fn (string $name): string => 'sim:' . $name . ':' . bin2hex(random_bytes(12));
    $refused = static function (callable $work): ?string {
        try { $work(); return null; } catch (DomainException $error) { return $error->getMessage(); }
    };
    $first = pl_review_van_settlement(...array_merge($args, [$c['van_id'], $c['date'], 'End of the driver\'s day', $key('review-1')]));
    $breach = $refused(static fn () => pl_approve_van_settlement(...array_merge($args, [(int) $first['id'], 'Approve the day', $key('approve-1')])));
    $receipt = pl_settle_ar_document(...array_merge($args, [$clearDocumentId, [
        'bank_account_id' => $c['cash_account_id'], 'amount_fc' => $clearingReceipt, 'date' => $c['date'],
        'description' => 'Recovery that brings the customer back inside the limit',
        'idempotency_key' => $key('recovery'),
    ]]));
    // The day has changed, so the reviewed sheet is stale and the approval says so before it
    // ever looks at the credit limit again.
    $stale = $refused(static fn () => pl_approve_van_settlement(...array_merge($args, [(int) $first['id'], 'Approve the day', $key('approve-2')])));
    $second = pl_review_van_settlement(...array_merge($args, [$c['van_id'], $c['date'], 'Reviewed again after the recovery', $key('review-2')]));
    $approved = pl_approve_van_settlement(...array_merge($args, [(int) $second['id'], 'Goods reconcile and every limit holds', $key('approve-3')]));
    return ['first_review' => $first, 'breach_refusal' => $breach, 'receipt' => $receipt,
        'stale_refusal' => $stale, 'second_review' => $second, 'approved' => $approved];
}

/**
 * The reconciliation identity, product by product, as a plain table a person can check.
 *
 * opening + loaded + customer returns - sold - returned = closing, for every item on the van.
 */
function pl_distribution_identity(array $day): array
{
    $rows = [];
    foreach ($day['products'] as $product) {
        $rows[] = ['sku' => $product['sku'], 'name' => $product['product_name'],
            'opening' => $product['opening'], 'loaded' => $product['loaded'],
            'customer_returns' => $product['customer_returns'], 'sold' => $product['sold'],
            'returned' => $product['returned'], 'expected_closing' => $product['expected_closing'],
            'closing' => $product['closing'], 'variance' => $product['variance'],
            'identity' => $product['opening'] . ' + ' . $product['loaded'] . ' + ' . $product['customer_returns']
                . ' - ' . $product['sold'] . ' - ' . $product['returned'] . ' = ' . $product['expected_closing']];
    }
    return $rows;
}
