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
<?php pl_ui_page_header('Van settlement', 'One van, one day · '.$company['name'], static function (): void { ?>
<a class="btn btn-ghost" href="<?= pl_e(pl_url('/stock-documents')) ?>">Stock documents</a>
<?php }); ?>
<?php if (!$enabled): ?><?php pl_ui_strip('Stock locations is disabled for this business. Settlement records stay readable; the owner can enable the module in Modules to review a new day.', 'warning'); ?><?php endif; ?>
<?php if ($form['message'] !== ''): ?><div class="alert alert-danger" role="alert" tabindex="-1" data-form-error><?= pl_e($form['message']) ?></div><?php endif; ?>

<form method="get" action="<?= pl_e(pl_url('/stock-documents/settlement')) ?>" class="rounded-panel border border-border bg-surface p-4 grid grid-cols-2 md:grid-cols-4 gap-4 items-end">
<?php pl_starter_select('Van', 'warehouse', $vanOptions, (string) ($warehouseId ?: ''), false); pl_starter_field('Day', 'date', $date, 'date'); ?>
<div><button class="btn btn-secondary">Show the day</button></div>
</form>

<?php if ($vans === []): ?>
<?php pl_ui_empty('No vans yet', 'Add a stock location of kind “van” on the Products & stock screen, then issue stock to it.'); ?>
<?php elseif ($day === null): ?>
<p class="text-sm">Choose a van and a day.</p>
<?php else: ?>
<section>
<h2 class="section-title mb-2"><?= pl_e($day['warehouse']['name']) ?><?= $day['warehouse']['driver_name'] === null ? '' : ' · '.pl_e((string) $day['warehouse']['driver_name']) ?> · <?= pl_e(pl_date_label($day['date'])) ?></h2>
<?php pl_ui_strip($day['reconciles']
    ? 'This day reconciles: opening plus loaded plus customer returns, less sold and less returned, equals the van’s closing stock for every item.'
    : 'This day does not reconcile. Record the stock count or adjustment that explains the difference; a settlement never absorbs it silently.',
    $day['reconciles'] ? 'info' : 'warning'); ?>
<?php pl_ui_table(['Code', 'Item', 'Opening', 'Loaded', 'Customer returns', 'Sold', 'Returned', 'Expected closing', 'Closing', 'Difference'],
    static function () use ($day): void { foreach ($day['products'] as $p): ?>
<tr><td><?= pl_e((string) $p['sku']) ?></td><td><?= pl_e((string) $p['product_name']) ?></td>
<td class="amount"><?= pl_e($p['opening']) ?></td><td class="amount"><?= pl_e($p['loaded']) ?></td><td class="amount"><?= pl_e($p['customer_returns']) ?></td>
<td class="amount"><?= pl_e($p['sold']) ?></td><td class="amount"><?= pl_e($p['returned']) ?></td>
<td class="amount"><?= pl_e($p['expected_closing']) ?></td><td class="amount"><?= pl_e($p['closing']) ?></td>
<td class="amount<?= $p['reconciles'] ? '' : ' text-danger' ?>"><?= pl_e($p['variance']) ?></td></tr>
<?php endforeach; }, 'The driver’s day'); ?>
<?php pl_ui_totals(['Loaded' => $day['totals']['loaded'], 'Sold' => $day['totals']['sold'], 'Returned' => $day['totals']['returned'],
    'Cost of stock loaded ('.$company['currency'].')' => pl_money($day['totals']['loaded_cost']),
    'Cost of stock sold ('.$company['currency'].')' => pl_money($day['totals']['sold_cost'])]); ?>
<p class="text-xs text-ink-muted">This sheet reconciles the goods, from the stock movements themselves. The cash and credit the driver collected are carried by the sale documents the van raised and post through the ordinary posting service; this sheet neither posts nor changes them.</p>
</section>

<?php if ($write): ?>
<section class="rounded-panel border border-border bg-surface p-4">
<h2 class="section-title mb-3">Review and approve</h2>
<form method="post" action="<?= pl_e(pl_url('/stock-documents/settlement', ['warehouse' => $warehouseId, 'date' => $date])) ?>" class="flex flex-col gap-3">
<?= pl_csrf_field() ?><?= pl_scope_fields($company) ?>
<input type="hidden" name="warehouse" value="<?= (int) $warehouseId ?>"><input type="hidden" name="date" value="<?= pl_e($date) ?>">
<input type="hidden" name="request_key" value="<?= pl_e((string) ($input['request_key'] ?? bin2hex(random_bytes(20)))) ?>">
<?php if ($settlement !== null) { ?><input type="hidden" name="settlement_id" value="<?= (int) $settlement['id'] ?>"><?php } ?>
<div class="grid grid-cols-2 gap-4"><?php pl_starter_field('Reason', 'reason', $input['reason'] ?? ''); ?></div>
<div class="flex gap-2">
<button class="btn btn-secondary" name="action" value="review"><?= $settlement === null ? 'Record the review' : 'Refresh the review' ?></button>
<?php if ($settlement !== null && $settlement['status'] === 'reviewed'): ?>
<button class="btn btn-primary" name="action" value="approve" <?= $canApprove ? '' : 'disabled' ?>>Approve the settlement</button>
<?php endif; ?>
</div>
<p class="text-xs text-ink-muted">Approving a settlement is a separate permission from recording one. Until user permissions ship, only the business owner can approve. An approved settlement is immutable.</p>
</form>
<?php if ($settlement !== null): ?>
<p class="text-sm mt-3">Status: <strong><?= pl_e($settlement['status']) ?></strong> · reviewed <?= pl_e((string) $settlement['reviewed_at']) ?><?= $settlement['approved_at'] === null ? '' : ' · approved '.pl_e((string) $settlement['approved_at']) ?></p>
<?php endif; ?>
</section>
<?php endif; ?>
<?php endif; ?>

<?php if ($settlements !== []): ?>
<section><h2 class="section-title mb-2">Recorded settlements</h2>
<?php pl_ui_table(['Van', 'Driver', 'Day', 'Status', 'Reconciles'], static function () use ($settlements): void { foreach ($settlements as $s): ?>
<tr><td><a class="link" href="<?= pl_e(pl_url('/stock-documents/settlement', ['warehouse' => (int) $s['warehouse_id'], 'date' => (string) $s['settlement_date']])) ?>"><?= pl_e((string) $s['code']) ?> · <?= pl_e((string) $s['name']) ?></a></td>
<td><?= pl_e((string) ($s['driver_name'] ?? '')) ?></td><td><?= pl_e(pl_date_label((string) $s['settlement_date'])) ?></td>
<td><?php pl_ui_badge((string) $s['status'] === 'approved' ? 'posted' : 'draft', (string) $s['status']); ?></td>
<td><?= $s['reconciles'] ? 'Yes' : 'No' ?></td></tr>
<?php endforeach; }, 'Recorded settlements'); ?>
</section>
<?php endif; ?>
</div>
