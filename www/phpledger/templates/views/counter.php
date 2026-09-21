<?php declare(strict_types=1);
/*
 * The counter till, over the selected location's real stock (1.2 M9).
 *
 * Every figure below is read from the service: on-hand quantities from the stock ledger, the
 * total and tax from the invoice preview, the change from the drawer arithmetic, the credit
 * headroom from the customer's ledger. Nothing here calculates money.
 *
 * Carrying value appears only when the read says it may (owner decision B58): the screen does
 * not decide that either — it receives nulls where cost is withheld.
 */
$write = $enabled && pl_can_write($company);
$warehouseOptions = [];
foreach ($warehouses as $warehouse) {
    $warehouseOptions[(string) $warehouse['id']] = $warehouse['code'] . ' · ' . $warehouse['name']
        . ($warehouse['is_mobile'] ? ' · ' . pl_t('van') : '');
}
$partyOptions = [];
foreach ($parties as $party) { $partyOptions[(string) $party['id']] = (string) $party['legal_name']; }
$cashOptions = [];
foreach ($cashAccounts as $account) { $cashOptions[(string) $account['id']] = $account['code'] . ' · ' . $account['name']; }
$taxOptions = ['' => pl_t('No tax')];
foreach ($taxCodes as $code) { $taxOptions[(string) $code['id']] = (string) $code['name']; }
// A book that has never moved stock has no warehouse row at all — the default one is
// provisioned by the first movement, and reading this screen must not be what provisions it.
// The adapter passes a null catalogue in that case and the till has nothing to sell from.
$hasLocation = $catalogue !== null;
$stocked = $hasLocation ? $catalogue['products'] : [];
$productOptions = [];
$onHand = [];
foreach ($stocked as $product) {
    $productOptions[(string) $product['product_id']] = $product['sku'] . ' · ' . $product['name']
        . ($product['on_hand'] === null ? '' : ' · ' . pl_t('{quantity} on hand', ['quantity' => $product['on_hand']]));
    $onHand[(int) $product['product_id']] = $product;
}
$packOptions = ['' => pl_t('Base units')];
foreach ($stocked as $product) {
    foreach ($product['packs'] as $pack) {
        $packOptions[(string) $pack['pack_id']] = $product['sku'] . ' · ' . $pack['name'] . ' (' . $pack['units_per_pack'] . ')';
    }
}
$lines = is_array($input['lines'] ?? null) && $input['lines'] !== [] ? $input['lines'] : [[], [], []];
$tender = (string) ($input['tender'] ?? 'cash');
$readout = $review === null ? null : $review['readout'];
?>
<div class="flex flex-col gap-6 py-5">
<?php pl_ui_page_header(pl_t('Counter sale'), pl_t('Sell what this location is holding · {company}', ['company' => $company['name']]), static function (): void { ?>
<a class="btn btn-ghost" href="<?= pl_e(pl_url('/reports/stock-by-location')) ?>"><?= pl_e(pl_t('Stock by location')) ?></a>
<a class="btn btn-ghost" href="<?= pl_e(pl_url('/ar')) ?>"><?= pl_e(pl_t('Invoices')) ?></a>
<?php }); ?>

<?php if (!$enabled): ?><?php pl_ui_strip(pl_t('Receivables are switched off for this business, so no counter sale can be recorded. An administrator can switch them on in Modules.'), 'warning'); ?><?php endif; ?>
<?php if ($form['message'] !== ''): ?><div class="alert alert-danger" role="alert" tabindex="-1" data-form-error><?= pl_e($form['message']) ?></div><?php endif; ?>
<?php if ($failure !== ''): ?><?php pl_ui_strip($failure, 'warning'); ?><?php endif; ?>
<?php if ($tender === 'cash' && bccomp($policies['cash_on_invoice_cap'], '0', 4) === 0): ?>
<?php pl_ui_strip(pl_t('Cash cannot be taken on a sale until an administrator sets the cash-on-invoice cap in Admin > Accounting policies. Until then, ring sales up on the customer’s account.'), 'warning'); ?>
<?php endif; ?>

<?php pl_ui_strip(pl_t('This till sells the stock the chosen location actually holds and records each sale as an ordinary customer invoice: the goods leave that location at carrying value, and a cash sale settles itself as it posts. The sample shop at Point of sale is a demonstration and moves no stock.'), 'info'); ?>

<?php if (!$hasLocation): ?>
<?php pl_ui_empty(pl_t('No stock location yet'),
    pl_t('This business has not moved any stock, so it has no warehouse to sell from. Receive stock on the Products & stock screen; the default warehouse is created by the first movement.')); ?>
<?php else: ?>

<form method="post" action="<?= pl_e(pl_url('/counter')) ?>" class="flex flex-col gap-4">
<?= pl_csrf_field() ?><?= pl_scope_fields($company) ?>
<input type="hidden" name="request_key" value="<?= pl_e((string) ($input['request_key'] ?? bin2hex(random_bytes(20)))) ?>">

<section class="rounded-panel border border-border bg-surface p-4 grid grid-cols-2 md:grid-cols-4 gap-4">
<?php
pl_starter_select(pl_t('Sell from'), 'warehouse_id', $warehouseOptions, (string) ($input['warehouse_id'] ?? ''));
pl_starter_select(pl_t('Customer'), 'party_id', $partyOptions, (string) ($input['party_id'] ?? ''));
pl_starter_field(pl_t('Sale date'), 'date', $input['date'] ?? gmdate('Y-m-d'), 'date');
pl_starter_select(pl_t('Payment'), 'tender', [
    'cash' => pl_t('Cash now'), 'credit' => pl_t('On the customer’s account')], $tender);
?>
</section>

<section class="rounded-panel border border-border bg-surface p-4 grid grid-cols-2 md:grid-cols-4 gap-4">
<?php if ($tender === 'cash'): ?>
<?php pl_starter_select(pl_t('Money goes into'), 'cash_account_id', $cashOptions, (string) ($input['cash_account_id'] ?? '')); ?>
<?php pl_starter_field(pl_t('Cash tendered'), 'cash_tendered', $input['cash_tendered'] ?? '', 'text', false); ?>
<?php else: ?>
<?php pl_starter_field(pl_t('Due date'), 'due_date', $input['due_date'] ?? '', 'date', false); ?>
<?php endif; ?>
<?php pl_starter_field(pl_t('Reference'), 'reference', $input['reference'] ?? '', 'text', false); ?>
</section>

<section>
<h2 class="section-title mb-2"><?= pl_e(pl_t('What is being sold')) ?></h2>
<p class="text-xs text-ink-muted mb-2"><?= pl_e(pl_t('Enter packs and loose units separately — “three cartons and four bottles”. A pack resolves to base units before pricing, tax and the stock issue, using the size frozen when the pack was created.')) ?></p>
<?php pl_ui_table([pl_t('Item'), pl_t('Pack'), pl_t('Packs'), pl_t('Loose units'), pl_t('Base quantity'), pl_t('Unit price'), pl_t('Discount %'), pl_t('Tax'), pl_t('On hand'), ''],
    static function () use ($lines, $productOptions, $packOptions, $taxOptions, $onHand): void {
        foreach ($lines as $index => $line):
            $productId = isset($line['product_id']) && $line['product_id'] !== '' ? (int) $line['product_id'] : 0;
            $product = $onHand[$productId] ?? null; ?>
<tr>
<td><?php pl_starter_select(pl_t('Item'), 'lines[' . $index . '][product_id]', $productOptions, (string) ($line['product_id'] ?? ''), false); ?></td>
<td><?php pl_starter_select(pl_t('Pack'), 'lines[' . $index . '][pack_id]', $packOptions, (string) ($line['pack_id'] ?? ''), false); ?></td>
<td><?php pl_starter_field(pl_t('Packs'), 'lines[' . $index . '][pack_quantity]', $line['pack_quantity'] ?? '', 'text', false); ?></td>
<td><?php pl_starter_field(pl_t('Loose units'), 'lines[' . $index . '][unit_quantity]', $line['unit_quantity'] ?? '', 'text', false); ?></td>
<td><?php pl_starter_field(pl_t('Base quantity'), 'lines[' . $index . '][quantity]', $line['quantity'] ?? '', 'text', false); ?></td>
<td><?php pl_starter_field(pl_t('Unit price'), 'lines[' . $index . '][unit_price]', $line['unit_price'] ?? ($product === null ? '' : $product['selling_price']), 'text', false); ?></td>
<td><?php pl_starter_field(pl_t('Discount %'), 'lines[' . $index . '][discount_percent]', $line['discount_percent'] ?? '', 'text', false); ?></td>
<td><?php pl_starter_select(pl_t('Tax'), 'lines[' . $index . '][tax_code_id]', $taxOptions, (string) ($line['tax_code_id'] ?? ''), false); ?></td>
<td class="amount"><?= pl_e($product === null || $product['on_hand'] === null ? '—' : $product['on_hand']) ?></td>
<td><button class="btn btn-ghost btn-sm" name="remove_line" value="<?= (int) $index ?>"><?= pl_e(pl_t('Remove')) ?></button></td>
</tr>
<?php endforeach; }, pl_t('The sale')); ?>
<div class="mt-2"><button class="btn btn-secondary" name="editor_action" value="add_line"><?= pl_e(pl_t('Add a line')) ?></button></div>
</section>

<?php if ($readout !== null): ?>
<section class="rounded-panel border border-border bg-surface p-4">
<h2 class="section-title mb-3"><?= pl_e(pl_t('Review this sale')) ?></h2>
<?php pl_ui_totals([
    pl_t('Goods ({currency})', ['currency' => $company['currency']]) => pl_money((string) $review['plan']['document']['subtotal']),
    pl_t('Tax ({currency})', ['currency' => $company['currency']]) => pl_money((string) $review['plan']['document']['tax_total']),
    pl_t('Total ({currency})', ['currency' => $company['currency']]) => pl_money($readout['total']),
]); ?>
<?php if ($readout['cash'] !== null): ?>
<?php pl_ui_totals([
    pl_t('Cash tendered') => pl_money($readout['cash']['tendered']),
    pl_t('Change due') => pl_money($readout['cash']['change_due']),
]); ?>
<?php if (!$readout['cash']['tenderable']): ?><?php pl_ui_strip(pl_t('This total is finer than the smallest coin, so it cannot be paid in cash. Adjust a price or a discount.'), 'warning'); ?><?php endif; ?>
<?php if (!$readout['cash']['sufficient']): ?><?php pl_ui_strip(pl_t('The cash tendered does not cover the sale.'), 'warning'); ?><?php endif; ?>
<?php endif; ?>
<?php if ($readout['credit'] !== null): ?>
<?php if ($readout['credit']['credit_limit'] === null): ?>
<?php pl_ui_strip(pl_t('No credit limit is recorded for this customer, so none is enforced. An administrator sets one on the customer record.'), 'info'); ?>
<?php else: ?>
<?php pl_ui_totals([
    pl_t('Owed now') => pl_money($readout['credit']['exposure_base']),
    pl_t('After this sale') => pl_money($readout['credit']['exposure_after_base']),
    pl_t('Credit limit') => pl_money((string) $readout['credit']['credit_limit']),
]); ?>
<?php if ($readout['credit']['would_exceed']): ?>
<?php pl_ui_strip($readout['credit_enforced_now']
    ? pl_t('This sale would take the customer past their credit limit, so the counter will refuse it. Take payment, or have an administrator change the limit.')
    : pl_t('This sale would take the customer past their credit limit. A sale from a van is not refused here — the limit is checked when the driver’s day is settled.'),
    $readout['credit_enforced_now'] ? 'warning' : 'info'); ?>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>
<?php if ($readout['stock'] !== []): ?>
<?php pl_ui_table([pl_t('Code'), pl_t('Item'), pl_t('Selling'), pl_t('On hand'), pl_t('Left after')], static function () use ($readout): void { foreach ($readout['stock'] as $row): ?>
<tr><td><?= pl_e($row['sku']) ?></td><td><?= pl_e($row['name']) ?></td>
<td class="amount"><?= pl_e($row['quantity']) ?></td><td class="amount"><?= pl_e($row['on_hand']) ?></td>
<td class="amount<?= $row['sufficient'] ? '' : ' text-danger' ?>"><?= pl_e($row['on_hand_after']) ?></td></tr>
<?php endforeach; }, pl_t('Stock this sale takes')); ?>
<?php endif; ?>
<?php if ($readout['short'] !== []): ?>
<?php pl_ui_strip(pl_t('This location does not hold enough of {items}.', ['items' => implode(', ', $readout['short'])]), 'warning'); ?>
<?php endif; ?>
<?php if (!$readout['cost_visible']): ?>
<p class="text-xs text-ink-muted"><?= pl_e(pl_t('Cost and margin are not shown to you. They are a separate permission, chosen report by report in Admin > Cost visibility.')) ?></p>
<?php endif; ?>
</section>
<?php endif; ?>

<?php if ($write): ?>
<div class="flex gap-2">
<button class="btn btn-secondary" name="action" value="review"><?= pl_e(pl_t('Review the sale')) ?></button>
<button class="btn btn-primary" name="action" value="sell" <?= $readout === null ? 'disabled' : '' ?>><?= pl_e($tender === 'cash' ? pl_t('Take the money') : pl_t('Put it on the account')) ?></button>
</div>
<p class="text-xs text-ink-muted"><?= pl_e(pl_t('Posting is one action: the invoice, the stock issue and — for a cash sale — the receipt that settles it are written together or not at all. A posted sale is corrected by a linked reversal, never edited.')) ?></p>
<?php else: ?>
<?php pl_ui_strip(pl_t('Your role can read this till but cannot record a sale.'), 'info'); ?>
<?php endif; ?>
</form>

<section>
<h2 class="section-title mb-2"><?= pl_e(pl_t('What {location} is holding', ['location' => $catalogue['warehouse']['name']])) ?></h2>
<?php if ($catalogue['products'] === []): ?>
<?php pl_ui_empty(pl_t('Nothing to sell yet'), pl_t('Add products on the Products & stock screen and receive stock into this location first.')); ?>
<?php else: ?>
<?php pl_ui_table(array_merge([pl_t('Code'), pl_t('Item'), pl_t('Unit'), pl_t('Price'), pl_t('On hand')], $catalogue['cost_visible'] ? [pl_t('Carrying value')] : []),
    static function () use ($catalogue): void { foreach ($catalogue['products'] as $product): ?>
<tr><td><?= pl_e($product['sku']) ?></td><td><?= pl_e($product['name']) ?></td>
<td><?= pl_e($product['base_unit']) ?></td><td class="amount"><?= pl_e(pl_money($product['selling_price'])) ?></td>
<td class="amount<?= $product['sellable'] ? '' : ' text-ink-muted' ?>"><?= pl_e($product['on_hand'] === null ? pl_t('service') : $product['on_hand']) ?></td>
<?php if ($catalogue['cost_visible']): ?><td class="amount"><?= pl_e($product['value_base'] === null ? '—' : pl_money($product['value_base'])) ?></td><?php endif; ?>
</tr>
<?php endforeach; }, pl_t('Stock at this location')); ?>
<?php endif; ?>
</section>
<?php endif; ?>
</div>
