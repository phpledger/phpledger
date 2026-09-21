<?php declare(strict_types=1);
/*
 * Stock by location (owner decision B35; the inventory-locations manifest's
 * `stock-by-location` report, on the route the manifest declares).
 *
 * Layout from docs/design/1.2-2026-09/forms-and-reports/stock-by-location.html: warehouses
 * first, then vans, a group header and subtotal per location, an aggregate column per item
 * and a grand total, with inline negative and low badges.
 */
$currency = (string) $company['currency'];
$costs = (bool) $report['cost_visible'];
$headings = [pl_t('Code'), pl_t('Item'), pl_t('Unit'), pl_t('Quantity')];
if ($costs) { $headings[] = pl_t('Value at cost ({currency})', ['currency' => $currency]); }
$headings[] = pl_t('Value at sale ({currency})', ['currency' => $currency]);
$headings[] = pl_t('All locations');
$columns = count($headings);
?>
<div class="flex flex-col gap-6 py-5">
<?php pl_ui_page_header(pl_t('Stock by location'),
    pl_t('{company} · {currency} · As at {asOf} · {locations} locations · {items} item rows', [
        'company' => $company['name'], 'currency' => $currency, 'asOf' => pl_date_label($report['as_of']),
        'locations' => $report['totals']['locations'], 'items' => $report['totals']['items'],
    ])
    .($report['totals']['negative'] > 0 ? ' · '.pl_t('{count} negative', ['count' => $report['totals']['negative']]) : ''),
    static function (): void { ?>
<a class="btn btn-ghost" href="<?= pl_e(pl_url('/stock-documents')) ?>"><?= pl_e(pl_t('Stock documents')) ?></a>
<?php }); ?>

<form method="get" action="<?= pl_e(pl_url('/reports/stock-by-location')) ?>" class="rounded-panel border border-border bg-surface p-4 grid grid-cols-2 md:grid-cols-4 gap-4 items-end">
<?php
pl_starter_field(pl_t('As at'), 'as_of', $report['as_of'], 'date');
pl_starter_select(pl_t('Locations'), 'locations', ['all' => pl_t('All locations'), 'fixed' => pl_t('Warehouses only'), 'mobile' => pl_t('Vans only')], $report['locations']);
pl_starter_field(pl_t('Item or code'), 'q', $report['search'], 'text', false);
?>
<div><button class="btn btn-secondary"><?= pl_e(pl_t('Update')) ?></button></div>
</form>

<?php if (!$costs): ?>
<?php pl_ui_strip(pl_t('Cost columns are hidden: viewing stock cost is a separate permission. Until user permissions ship, only the business owner sees cost and the reconciliation to the inventory accounts.'), 'info'); ?>
<?php endif; ?>

<?php if ($report['groups'] === []): ?>
<?php pl_ui_empty(pl_t('Nothing to show'), pl_t('No stock location matches this filter, or no stock has been recorded at one yet.')); ?>
<?php else: ?>
<?php pl_ui_table($headings, static function () use ($report, $costs, $columns): void {
    foreach ($report['groups'] as $group):
        $w = $group['warehouse']; ?>
<tr class="bg-surface-subtle"><th scope="rowgroup" class="font-semibold" colspan="<?= (int) $columns ?>"><?= pl_e($w['name'].' ('.$w['code'].')') ?><?php
    if ($w['kind'] === 'mobile') { echo ' · '.pl_e(trim(((string) ($w['driver_name'] ?? '')).' · '.((string) ($w['route_name'] ?? '')), ' ·')); }
    ?> · <?= pl_e(pl_tn('{count} item', '{count} items', (int) $group['totals']['items'], ['count' => (int) $group['totals']['items']])) ?><?php if ($group['totals']['negative'] > 0): ?> · <?= pl_e(pl_t('{count} negative', ['count' => (int) $group['totals']['negative']])) ?><?php endif; ?></th></tr>
<?php if ($group['rows'] === []): ?>
<tr><td colspan="<?= (int) $columns ?>" class="text-ink-muted"><?= pl_e(pl_t('No stock at this location.')) ?></td></tr>
<?php endif; ?>
<?php foreach ($group['rows'] as $row): ?>
<tr><td><?= pl_e((string) $row['sku']) ?></td>
<td><?= pl_e((string) $row['product_name']) ?><?php if ($row['is_negative']): ?> <span class="badge badge-overdue"><span class="badge-dot" aria-hidden="true"></span><?= pl_e(pl_t('Negative')) ?></span><?php elseif ($row['is_low']): ?> <span class="badge badge-due-soon"><span class="badge-dot" aria-hidden="true"></span><?= pl_e(pl_t('Low')) ?></span><?php endif; ?></td>
<td><?= pl_e((string) $row['base_unit']) ?></td>
<td class="amount"><?= pl_e($row['quantity']) ?></td>
<?php if ($costs): ?><td class="amount"><?= pl_e(pl_money((string) $row['value_base'])) ?></td><?php endif; ?>
<td class="amount"><?= pl_e(pl_money($row['sale_value'])) ?></td>
<td class="amount"><?= pl_e($row['aggregate_quantity']) ?></td></tr>
<?php endforeach; ?>
<tr class="bg-surface-subtle"><th scope="row" class="font-semibold" colspan="3"><?= pl_e(pl_t('Sub total · {code}', ['code' => (string) $w['code']])) ?></th>
<td class="amount"><?= pl_e($group['totals']['quantity']) ?></td>
<?php if ($costs): ?><td class="amount"><?= pl_e(pl_money((string) $group['totals']['value_base'])) ?></td><?php endif; ?>
<td class="amount"><?= pl_e(pl_money($group['totals']['sale_value'])) ?></td><td></td></tr>
<?php endforeach; ?>
<tr class="bg-surface-subtle"><th scope="row" class="font-semibold" colspan="3"><?= pl_e(pl_t('Grand total · all shown locations')) ?></th>
<td class="amount"><?= pl_e($report['totals']['quantity']) ?></td>
<?php if ($costs): ?><td class="amount"><?= pl_e(pl_money((string) $report['totals']['value_base'])) ?></td><?php endif; ?>
<td class="amount"><?= pl_e(pl_money($report['totals']['sale_value'])) ?></td><td></td></tr>
<?php }, pl_t('Stock by location')); ?>
<?php endif; ?>

<section><h2 class="section-title mb-2"><?= pl_e(pl_t('Aggregate across locations')) ?></h2>
<?php if (!$report['reconciliation_available']): ?>
<p class="text-sm"><?= pl_e(pl_t('This is a filtered view. Only the unfiltered, all-locations report ties to the inventory control accounts.')) ?></p>
<?php elseif (!$costs): ?>
<p class="text-sm"><?= pl_e(pl_t('The aggregate reconciliation shows stock cost, so it follows the same permission as the cost columns above.')) ?></p>
<?php else: ?>
<?php pl_ui_strip($report['reconciles']
    ? pl_t('The per-location totals add up to the aggregate valuation across every location.')
    : pl_t('The per-location totals differ from the aggregate valuation by {difference}. Investigate before relying on either figure.', ['difference' => pl_money((string) $report['difference'])]),
    $report['reconciles'] ? 'info' : 'warning'); ?>
<?php pl_ui_table([pl_t('Code'), pl_t('Item'), pl_t('Unit'), pl_t('Quantity everywhere'), pl_t('Value at cost ({currency})', ['currency' => $currency]), pl_t('Average cost')], static function () use ($aggregate): void {
    foreach ($aggregate['products'] as $row): ?>
<tr><td><?= pl_e((string) $row['sku']) ?></td><td><?= pl_e((string) $row['product_name']) ?></td><td><?= pl_e((string) $row['base_unit']) ?></td>
<td class="amount"><?= pl_e((string) $row['quantity']) ?></td><td class="amount"><?= pl_e(pl_money((string) $row['value_base'])) ?></td>
<td class="amount"><?= pl_e((string) $row['average_cost']) ?></td></tr>
<?php endforeach; }, pl_t('Aggregate stock across locations')); ?>
<?php pl_ui_totals([pl_t('Aggregate valuation ({currency})', ['currency' => $currency]) => pl_money((string) $aggregate['total_value_base']), pl_t('Sum of the location groups ({currency})', ['currency' => $currency]) => pl_money((string) $aggregate['per_location_value_base'])]); ?>
<?php if ($aggregate['accounts'] !== []): ?>
<?php pl_ui_table([pl_t('Inventory account'), pl_t('Stock value ({currency})', ['currency' => $currency]), pl_t('Ledger value ({currency})', ['currency' => $currency]), pl_t('Difference')], static function () use ($aggregate): void {
    foreach ($aggregate['accounts'] as $account): ?>
<tr><td><?= (int) $account['account_id'] ?></td><td class="amount"><?= pl_e(pl_money((string) $account['stock_value'])) ?></td>
<td class="amount"><?= pl_e($account['ledger_value'] === null ? '—' : pl_money((string) $account['ledger_value'])) ?></td>
<td class="amount"><?= pl_e($account['difference'] === null ? '—' : pl_money((string) $account['difference'])) ?></td></tr>
<?php endforeach; }, pl_t('Inventory account reconciliation')); ?>
<?php endif; ?>
<?php endif; ?>
</section>
<p class="text-xs text-ink-muted"><?= pl_e(pl_t('Stock on a van is still your stock: a stock issue moves value between your own locations and posts nothing. “Low” marks a location holding less than a tenth of what all locations hold of that item; it is a derived attention flag, not a stored reorder level.')) ?></p>
</div>
