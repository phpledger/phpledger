<?php
declare(strict_types=1);
$receipt = $receipt ?? null;
$recovery = $recovery ?? null;
$quote = $quote ?? null;
$form = $form ?? ['input' => [], 'message' => ''];
$input = $input ?? $form['input'];
$canCheckout = pl_can_write($company) && $company['setup_status'] === 'ready' && pl_module_available((int) $user['id'], (int) $company['id'], (int) $company['book_id'], 'pos-showcase');
?>
<header class="pos-topbar pos-noprint">
    <div class="flex items-center gap-2"><span class="company-switcher-mark" aria-hidden="true"><?= pl_e(mb_strtoupper(mb_substr($company['name'], 0, 1))) ?></span><div><strong><?= pl_e($company['name']) ?></strong><p class="text-xs text-ink-muted">Point of sale</p></div></div>
    <ol class="pos-progress" aria-label="Sale progress"><?php foreach (['Build cart','Review & cash','Receipt'] as $index => $step): ?><li<?= ($receipt !== null ? 2 : ($quote !== null || $recovery !== null ? 1 : 0)) === $index ? ' aria-current="step"' : '' ?>><span><?= $index + 1 ?></span><?= pl_e($step) ?></li><?php endforeach; ?></ol>
    <span class="pos-cashier">Cashier: <?= pl_e($user['display_name']) ?></span><a class="btn btn-ghost btn-sm" href="<?= pl_e(pl_url('/home')) ?>"><?= pl_icon('logout') ?> Exit to app</a>
</header>
<div class="pos-noprint"><?php require dirname(__DIR__).'/partials/ui/context-strips.php'; ?></div>
<section class="page-wrap pos-workspace" data-pos-root data-currency="<?= pl_e((string) $company['currency']) ?>">
<?php if ($receipt !== null): ?>
    <div class="page-heading pos-noprint"><div><p class="eyebrow">Cash sale recorded</p><h1>Receipt ready</h1><p class="muted">The receipt and its balanced journal have been saved together.</p></div><?php if ($canCheckout): ?><a class="btn btn-primary" href="<?= pl_e(pl_url('/pos')) ?>">Start another sale</a><?php endif; ?></div>
    <article class="panel pos-receipt" aria-labelledby="pos-receipt-title">
        <p class="eyebrow">Sample shop &middot; Cash receipt</p><h2 id="pos-receipt-title"><?= pl_e((string) $company['name']) ?></h2>
        <p><?= pl_e((string) $receipt['number']) ?> &middot; <?= pl_e(pl_date_label((string) $receipt['document']['date'])) ?></p>
        <p class="text-xs text-ink-muted">Cashier: <?= pl_e($receipt['cashier_name']) ?></p>
        <?php if ($receipt['document']['status'] === 'reversed'): ?><p class="alert">This sale's accounting entry has been reversed. Its original receipt is preserved.</p><?php endif; ?>
        <div class="table-wrap"><table class="table"><caption>Recorded sale in <?= pl_e((string) $receipt['currency']) ?></caption><thead><tr><th scope="col">Item</th><th scope="col">Qty</th><th scope="col" class="amount">Each</th><th scope="col" class="amount">Total</th></tr></thead><tbody>
        <?php foreach ($receipt['items'] as $item): ?><tr><th scope="row"><?= pl_e((string) $item['name']) ?><span class="muted pos-sku"><?= pl_e((string) $item['sku']) ?></span></th><td><?= pl_e((string) $item['quantity']) ?></td><td class="amount"><?= pl_e(pl_money((string) $item['unit_price'])) ?></td><td class="amount"><?= pl_e(pl_money((string) $item['line_total'])) ?></td></tr><?php endforeach; ?>
        </tbody><tfoot><tr><th scope="row" colspan="3">Sale total</th><td class="amount"><?= pl_e(pl_money((string) $receipt['total'])) ?></td></tr><tr><th scope="row" colspan="3">Cash received</th><td class="amount"><?= pl_e(pl_money((string) $receipt['cash_received'])) ?></td></tr><tr><th scope="row" colspan="3">Change</th><td class="amount"><?= pl_e(pl_money((string) $receipt['change_due'])) ?></td></tr></tfoot></table></div>
        <p class="muted">Illustrative products and prices. No payment was collected by this application. This showcase does not calculate tax, stock movements, or cost of goods sold.</p>
        <p class="muted">Catalog <?= pl_e((string) $receipt['catalog_id']) ?> &middot; <?= pl_e((string) $receipt['catalog_version']) ?></p>
        <div class="actions pos-noprint"><button class="btn btn-primary" type="button" data-pos-print>Print receipt</button><a class="btn btn-secondary" href="<?= pl_e(pl_url('/transactions/detail', ['id' => $receipt['document_id']])) ?>">View source transaction</a><a href="<?= pl_e(pl_url('/journals/detail', ['id' => $receipt['document']['journal_id']])) ?>">View journal</a></div>
    </article>
<?php elseif ($recovery !== null): ?>
    <div class="page-heading pos-heading"><div><p class="eyebrow">Point of sale</p><h1>Check sale outcome</h1><p class="muted"><?= pl_e((string) $company['name']) ?></p></div><span class="badge">Awaiting confirmation</span></div>
    <div class="alert" role="alert" data-pos-recovery><strong>We could not confirm whether this sale completed.</strong><p>The original items, date and cash are preserved. Retry this exact sale to retrieve its saved receipt or safely complete it. Do not collect cash again.</p><p>Editing and starting another sale are paused until this attempt is resolved.</p></div>
    <section class="panel pos-receipt" aria-labelledby="pos-recovery-title"><h2 id="pos-recovery-title">Original submitted sale</h2><p><?= pl_e(pl_date_label((string) $recovery['request']['date'])) ?></p>
        <?php foreach ($recovery['quote']['items'] ?? $recovery['request']['items'] as $item): ?><div class="pos-review-line"><strong><?= pl_e((string) ($item['name'] ?? $item['sku'])) ?></strong><span><?= pl_e((string) $item['quantity']) ?> units</span></div><?php endforeach; ?>
        <?php if ($recovery['quote'] !== null): ?><div class="pos-total"><span>Original total &middot; <?= pl_e((string) $company['currency']) ?></span><strong class="amount"><?= pl_e(pl_money((string) $recovery['quote']['total'])) ?></strong></div><?php endif; ?>
        <p>Original cash received: <strong class="amount"><?= pl_e((string) $company['currency']) ?> <?= pl_e(pl_money((string) $recovery['request']['cash_received'])) ?></strong></p>
        <form action="<?= pl_e(pl_url('/pos/retry')) ?>" method="post"><?= pl_csrf_field() ?><?= pl_scope_fields($company) ?><button type="submit" name="retry_intent" value="retry_original" class="btn btn-primary">Retry original sale</button></form>
        <p class="muted">The server keeps the original checkout identity. Repeating this retry cannot create a second receipt for it.</p>
        <a href="<?= pl_e(pl_url('/transactions', ['status' => 'posted'])) ?>">Inspect posted transactions</a>
    </section>
<?php elseif ($quote !== null): ?>
    <div class="page-heading pos-heading"><div><p class="eyebrow">Point of sale</p><h1>Confirm cash sale</h1><p class="muted"><?= pl_e((string) $company['name']) ?> &middot; <?= pl_e(pl_date_label((string) $quote['request']['date'])) ?></p></div><span class="badge">Review sale</span></div>
    <?php if ($form['message'] !== ''): ?><div class="alert" role="alert" tabindex="-1" data-form-error><strong>Check this sale</strong><p><?= pl_e((string) $form['message']) ?></p><p>Your items and cash amount are preserved. Correct them before confirming again.</p></div><?php endif; ?>
    <?php /* Dropping the total to two places is lossless only because pl_pos_catalog() refuses a
             price finer than cash; app.js compares the tender against this value and Exact amount
             types it into a field that now accepts two decimals. Change neither alone. */ ?>
    <form action="<?= pl_e(pl_url('/pos/checkout')) ?>" method="post" class="pos-review-grid" data-pos-payment data-total="<?= pl_e(bcadd((string) $quote['total'], '0', 2)) ?>">
        <button type="submit" disabled hidden aria-hidden="true" tabindex="-1">Editing cash</button>
        <?= pl_csrf_field() ?><?= pl_scope_fields($company) ?>
        <input type="hidden" name="checkout_key" value="<?= pl_e((string) $quote['request']['checkout_key']) ?>">
        <input type="hidden" name="catalog_digest" value="<?= pl_e((string) $quote['request']['catalog_digest']) ?>">
        <input type="hidden" name="date" value="<?= pl_e((string) $quote['request']['date']) ?>">
        <section class="panel pos-review-lines" aria-labelledby="pos-review-title"><div class="pos-section-heading"><h2 id="pos-review-title">Review your items</h2><span><?= pl_e((string) $quote['units']) ?> units</span></div>
            <?php foreach ($quote['items'] as $index => $item): ?>
            <input type="hidden" name="items[<?= pl_e((string) $index) ?>][sku]" value="<?= pl_e((string) $item['sku']) ?>"><input type="hidden" name="items[<?= pl_e((string) $index) ?>][quantity]" value="<?= pl_e((string) $item['quantity']) ?>">
            <div class="pos-review-line"><div><strong><?= pl_e((string) $item['name']) ?></strong><small><?= pl_e((string) $item['quantity']) ?> &times; <?= pl_e(pl_money((string) $item['unit_price'])) ?> &middot; <?= pl_e((string) $item['sku']) ?></small></div><strong class="amount"><?= pl_e(pl_money((string) $item['line_total'])) ?></strong></div>
            <?php endforeach; ?>
            <div class="pos-total"><span>Total &middot; <?= pl_e((string) $company['currency']) ?></span><strong class="amount"><?= pl_e(pl_money((string) $quote['total'])) ?></strong></div>
            <button type="submit" name="review_intent" value="edit_cart" formaction="<?= pl_e(pl_url('/pos/edit')) ?>" formnovalidate class="btn btn-secondary" data-pos-edit>Edit cart</button>
            <p class="muted pos-small">Prices verified against the sample catalog. Reviewing does not change the books.</p>
        </section>
        <aside class="panel pos-payment" aria-labelledby="pos-cash-title"><p class="eyebrow">Cash payment</p><h2 id="pos-cash-title">Amount due</h2><p class="pos-due amount"><span><?= pl_e((string) $company['currency']) ?></span><?= pl_e(pl_money((string) $quote['total'])) ?></p>
            <?php /* Two places is what a person can tender; pl_cash_amount() still accepts a retry's 20.0000. */ ?>
            <label class="field">Cash received (<?= pl_e((string) $company['currency']) ?>)<input type="text" inputmode="decimal" name="cash_received" maxlength="21" pattern="(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,2})?" required value="<?= pl_e(pl_web_text($input, 'cash_received')) ?>" placeholder="0.00" data-pos-cash autocomplete="off"><span class="muted">Use a decimal point and at most two decimal places, without grouping separators.</span></label>
            <button type="button" class="btn btn-secondary" data-pos-exact>Exact amount</button>
            <p class="pos-change" aria-live="polite" data-pos-change>Enter cash received to see change.</p>
            <button type="submit" name="checkout_intent" value="record_cash_sale" class="btn btn-primary" data-pos-checkout<?= !$canCheckout ? ' disabled' : '' ?>>Record cash sale</button>
            <p class="muted pos-small">Confirming saves the receipt and its balanced journal together. No actual payment is collected by this showcase.</p>
            <noscript><p class="muted">Enter cash and choose Record cash sale. The server validates the amount and returns exact change on the receipt.</p></noscript>
        </aside>
    </form>
<?php else: ?>
    <?php
    $quantities = [];
    foreach (is_array($input['items'] ?? null) ? $input['items'] : [] as $item) {
        if (is_array($item) && is_string($item['sku'] ?? null) && (is_string($item['quantity'] ?? null) || is_int($item['quantity'] ?? null))) {
            $quantities[$item['sku']] = (string) $item['quantity'];
        }
    }
    $checkoutKey = pl_web_text($input, 'checkout_key', bin2hex(random_bytes(24)));
    ?>
    <div class="page-heading pos-heading"><div><p class="eyebrow"><?= pl_e((string) $company['name']) ?></p><h1>Point of sale</h1><p class="muted">Tap a product to add one. Adjust quantities in your cart.</p></div><span class="badge">Sample catalog</span></div>
    <details class="pos-scope"><summary>About this sample shop</summary><p>These products and prices are illustrative. Checkout posts to <?= pl_e((string) $company['name']) ?>. No actual payment is taken; inventory, tax and credit sales are not included.</p></details>
    <?php if (!$canCheckout): ?><p class="alert">Checkout needs an owner/accountant role and completed business setup. You can browse the sample catalog.</p><?php endif; ?>
    <?php if ($form['message'] !== ''): ?><div class="alert" role="alert" tabindex="-1" data-form-error><strong>Review your cart</strong><p><?= pl_e((string) $form['message']) ?></p><p>Your selections are kept below with current catalog prices. Review before continuing.</p></div><?php endif; ?>
    <form action="<?= pl_e(pl_url('/pos/review')) ?>" method="post" class="pos-grid" data-pos-form>
        <button type="submit" disabled hidden aria-hidden="true" tabindex="-1">Editing a sale</button>
        <?= pl_csrf_field() ?><?= pl_scope_fields($company) ?>
        <input type="hidden" name="checkout_key" value="<?= pl_e($checkoutKey) ?>">
        <input type="hidden" name="catalog_digest" value="<?= pl_e((string) $catalog['digest']) ?>">
        <input type="hidden" name="cash_received" value="<?= pl_e(pl_web_text($input, 'cash_received')) ?>">
        <section class="pos-catalog" aria-labelledby="pos-products-title">
            <div class="pos-section-heading"><h2 id="pos-products-title">Products</h2><span class="muted">Tap to add</span></div>
            <label class="field pos-search">Find a product<input type="search" placeholder="Name or product code" data-pos-search autocomplete="off"></label>
            <div class="pos-categories" aria-label="Product categories"><button type="button" class="btn btn-secondary" data-pos-category="all" aria-pressed="true">All products</button><?php foreach (array_unique(array_column($catalog['products'], 'category')) as $category): ?><button type="button" class="btn btn-secondary" data-pos-category="<?= pl_e((string) $category) ?>" aria-pressed="false"><?= pl_e((string) $category) ?></button><?php endforeach; ?></div>
            <div class="pos-products">
            <?php foreach ($catalog['products'] as $index => $product): ?>
                <article class="panel pos-product" data-pos-product data-sku="<?= pl_e((string) $product['sku']) ?>" data-name="<?= pl_e((string) $product['name']) ?>" data-category="<?= pl_e((string) $product['category']) ?>" data-price="<?= pl_e((string) $product['unit_price']) ?>">
                    <button type="button" class="pos-product-pick" data-pos-add aria-label="<?= pl_e('Add ' . $product['name']) ?>"<?= !$canCheckout ? ' disabled' : '' ?>>
                        <span class="pos-product-top"><span class="pos-product-mark" aria-hidden="true"><?= pl_e((string) $product['mark']) ?></span><span class="pos-selected" data-pos-selected aria-hidden="true">+</span></span>
                        <span class="eyebrow"><?= pl_e((string) $product['category']) ?></span><strong class="pos-product-name"><?= pl_e((string) $product['name']) ?></strong><span class="pos-sku"><?= pl_e((string) $product['sku']) ?></span><span class="pos-price amount"><?= pl_e((string) $company['currency']) ?> <?= pl_e(pl_money((string) $product['unit_price'])) ?></span>
                    </button>
                    <input type="hidden" name="items[<?= pl_e((string) $index) ?>][sku]" value="<?= pl_e((string) $product['sku']) ?>">
                    <label class="field pos-fallback-quantity" for="pos-qty-<?= pl_e((string) $index) ?>">Quantity<span class="sr-only"> of <?= pl_e((string) $product['name']) ?></span><input id="pos-qty-<?= pl_e((string) $index) ?>" type="text" inputmode="numeric" pattern="(?:0|[1-9][0-9]?)" maxlength="2" name="items[<?= pl_e((string) $index) ?>][quantity]" value="<?= pl_e($quantities[$product['sku']] ?? '0') ?>" data-pos-quantity<?= !$canCheckout ? ' disabled' : '' ?>></label>
                </article>
            <?php endforeach; ?>
            </div><p class="muted pos-no-results" data-pos-no-results hidden>No products match. Try another name or category.</p>
        </section>
        <aside class="panel pos-cart" aria-labelledby="pos-cart-title"><div class="pos-section-heading"><h2 id="pos-cart-title">Current sale</h2><span class="badge" data-pos-count>0 items</span></div>
            <div data-pos-cart><p class="muted pos-cart-empty">Set quantities, then choose Review sale to see your items and total.</p></div>
            <div class="pos-total"><span>Total &middot; <?= pl_e((string) $company['currency']) ?></span><strong class="amount" data-pos-total>&mdash;</strong></div>
            <label class="field">Sale date<input type="date" name="date" required value="<?= pl_e(pl_web_text($input, 'date', gmdate('Y-m-d'))) ?>"<?= !isset($input['date']) ? ' data-local-today' : '' ?>></label>
            <p class="muted pos-small" aria-live="polite" data-pos-feedback>Up to 99 of each product and 200 units per sale.</p>
            <button type="submit" name="review_intent" value="review_cart" class="btn btn-primary" data-pos-review<?= !$canCheckout ? ' disabled' : '' ?>>Review sale <span aria-hidden="true">&rarr;</span></button><p class="muted pos-small">Review the total, then confirm cash. Nothing is posted yet.</p>
            <noscript><p>Set product quantities, then choose Review sale. Cash is entered on the next screen.</p></noscript>
        </aside>
    </form>
    <a class="pos-mobile-cart" href="#pos-cart-title">View cart <span data-pos-mobile-count>0 items</span></a>
<?php endif; ?>
</section>
