<?php declare(strict_types=1);
/*
 * The driver's day for one van: opening, loaded, sold, customer returns, returned to the
 * warehouse, and the closing stock those explain. Reviewing and approving post nothing —
 * the money is carried by the sale documents the van raised.
 *
 * Field cues from the Awan prototype's "Salesman Stock" screen.
 */
$write = $enabled && pl_can_write($company);
$vanOptions = [];
foreach ($vans as $van) { $vanOptions[(string) $van['id']] = $van['code'].' · '.$van['name'].($van['driver_name'] !== null ? ' · '.$van['driver_name'] : ''); }
?>
<div class="flex flex-col gap-6 py-5">
<?php pl_ui_page_header(pl_t('Van settlement'), pl_t('One van, one day · {company}', ['company' => $company['name']]), static function (): void { ?>
<a class="btn btn-ghost" href="<?= pl_e(pl_url('/stock-documents')) ?>"><?= pl_e(pl_t('Stock documents')) ?></a>
<?php }); ?>
<?php if (!$enabled): ?><?php pl_ui_strip(pl_t('Stock locations is disabled for this business. Settlement records stay readable; the owner can enable the module in Modules to review a new day.'), 'warning'); ?><?php endif; ?>
<?php if ($form['message'] !== ''): ?><div class="alert alert-danger" role="alert" tabindex="-1" data-form-error><?= pl_e($form['message']) ?></div><?php endif; ?>

<form method="get" action="<?= pl_e(pl_url('/stock-documents/settlement')) ?>" class="rounded-panel border border-border bg-surface p-4 grid grid-cols-2 md:grid-cols-4 gap-4 items-end">
<?php pl_starter_select(pl_t('Van'), 'warehouse', $vanOptions, (string) ($warehouseId ?: ''), false); pl_starter_field(pl_t('Day'), 'date', $date, 'date'); ?>
<div><button class="btn btn-secondary"><?= pl_e(pl_t('Show the day')) ?></button></div>
</form>

<?php if ($vans === []): ?>
<?php pl_ui_empty(pl_t('No vans yet'), pl_t('Add a stock location of kind “van” on the Products & stock screen, then issue stock to it.')); ?>
<?php elseif ($day === null): ?>
<p class="text-sm"><?= pl_e(pl_t('Choose a van and a day.')) ?></p>
<?php else: ?>
<section>
<h2 class="section-title mb-2"><?= pl_e($day['warehouse']['name']) ?><?= $day['warehouse']['driver_name'] === null ? '' : ' · '.pl_e((string) $day['warehouse']['driver_name']) ?> · <?= pl_e(pl_date_label($day['date'])) ?></h2>
<?php pl_ui_strip($day['reconciles']
    ? pl_t('This day reconciles: opening plus loaded plus customer returns, less sold and less returned, equals the van’s closing stock for every item.')
    : pl_t('This day does not reconcile. Record the stock count or adjustment that explains the difference; a settlement never absorbs it silently.'),
    $day['reconciles'] ? 'info' : 'warning'); ?>
<?php pl_ui_table([pl_t('Code'), pl_t('Item'), pl_t('Opening'), pl_t('Loaded'), pl_t('Customer returns'), pl_t('Sold'), pl_t('Returned'), pl_t('Expected closing'), pl_t('Closing'), pl_t('Difference')],
    static function () use ($day): void { foreach ($day['products'] as $p): ?>
<tr><td><?= pl_e((string) $p['sku']) ?></td><td><?= pl_e((string) $p['product_name']) ?></td>
<td class="amount"><?= pl_e($p['opening']) ?></td><td class="amount"><?= pl_e($p['loaded']) ?></td><td class="amount"><?= pl_e($p['customer_returns']) ?></td>
<td class="amount"><?= pl_e($p['sold']) ?></td><td class="amount"><?= pl_e($p['returned']) ?></td>
<td class="amount"><?= pl_e($p['expected_closing']) ?></td><td class="amount"><?= pl_e($p['closing']) ?></td>
<td class="amount<?= $p['reconciles'] ? '' : ' text-danger' ?>"><?= pl_e($p['variance']) ?></td></tr>
<?php endforeach; }, pl_t('The driver’s day')); ?>
<?php pl_ui_totals([pl_t('Loaded') => $day['totals']['loaded'], pl_t('Sold') => $day['totals']['sold'], pl_t('Returned') => $day['totals']['returned'],
    pl_t('Cost of stock loaded ({currency})', ['currency' => $company['currency']]) => pl_money($day['totals']['loaded_cost']),
    pl_t('Cost of stock sold ({currency})', ['currency' => $company['currency']]) => pl_money($day['totals']['sold_cost'])]); ?>
<p class="text-xs text-ink-muted"><?= pl_e(pl_t('This sheet reconciles the goods, from the stock movements themselves. The cash and credit the driver collected are carried by the sale documents the van raised and post through the ordinary posting service; this sheet neither posts nor changes them.')) ?></p>
</section>

<?php if ($write): ?>
<section class="rounded-panel border border-border bg-surface p-4">
<h2 class="section-title mb-3"><?= pl_e(pl_t('Review and approve')) ?></h2>
<form method="post" action="<?= pl_e(pl_url('/stock-documents/settlement', ['warehouse' => $warehouseId, 'date' => $date])) ?>" class="flex flex-col gap-3">
<?= pl_csrf_field() ?><?= pl_scope_fields($company) ?>
<input type="hidden" name="warehouse" value="<?= (int) $warehouseId ?>"><input type="hidden" name="date" value="<?= pl_e($date) ?>">
<input type="hidden" name="request_key" value="<?= pl_e((string) ($input['request_key'] ?? bin2hex(random_bytes(20)))) ?>">
<?php if ($settlement !== null) { ?><input type="hidden" name="settlement_id" value="<?= (int) $settlement['id'] ?>"><?php } ?>
<div class="grid grid-cols-2 gap-4"><?php pl_starter_field(pl_t('Reason'), 'reason', $input['reason'] ?? ''); ?></div>
<div class="flex gap-2">
<button class="btn btn-secondary" name="action" value="review"><?= pl_e($settlement === null ? pl_t('Record the review') : pl_t('Refresh the review')) ?></button>
<?php if ($settlement !== null && $settlement['status'] === 'reviewed'): ?>
<button class="btn btn-primary" name="action" value="approve" <?= $canApprove ? '' : 'disabled' ?>><?= pl_e(pl_t('Approve the settlement')) ?></button>
<?php endif; ?>
</div>
<p class="text-xs text-ink-muted"><?= pl_e(pl_t('Approving a settlement is a separate permission from recording one. Until user permissions ship, only the business owner can approve. An approved settlement is immutable.')) ?></p>
</form>
<?php if ($settlement !== null): ?>
<p class="text-sm mt-3"><?= pl_e(pl_t('Status:')) ?> <strong><?= pl_e($settlement['status']) ?></strong> · <?= pl_e(pl_t('reviewed {when}', ['when' => (string) $settlement['reviewed_at']])) ?><?= $settlement['approved_at'] === null ? '' : ' · '.pl_e(pl_t('approved {when}', ['when' => (string) $settlement['approved_at']])) ?></p>
<?php endif; ?>
</section>
<?php endif; ?>
<?php endif; ?>

<?php if ($settlements !== []): ?>
<section><h2 class="section-title mb-2"><?= pl_e(pl_t('Recorded settlements')) ?></h2>
<?php pl_ui_table([pl_t('Van'), pl_t('Driver'), pl_t('Day'), pl_t('Status'), pl_t('Reconciles')], static function () use ($settlements): void { foreach ($settlements as $s): ?>
<tr><td><a class="link" href="<?= pl_e(pl_url('/stock-documents/settlement', ['warehouse' => (int) $s['warehouse_id'], 'date' => (string) $s['settlement_date']])) ?>"><?= pl_e((string) $s['code']) ?> · <?= pl_e((string) $s['name']) ?></a></td>
<td><?= pl_e((string) ($s['driver_name'] ?? '')) ?></td><td><?= pl_e(pl_date_label((string) $s['settlement_date'])) ?></td>
<td><?php pl_ui_badge((string) $s['status'] === 'approved' ? 'posted' : 'draft', (string) $s['status']); ?></td>
<td><?= pl_e($s['reconciles'] ? pl_t('Yes') : pl_t('No')) ?></td></tr>
<?php endforeach; }, pl_t('Recorded settlements')); ?>
</section>
<?php endif; ?>
</div>
