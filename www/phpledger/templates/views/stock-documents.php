<?php declare(strict_types=1);
/*
 * Stock issues, re-issues, returns from a van and gate passes. Field cues from the
 * Awan prototype's "Stock issues & returns" and "Delivery Challan" screens and the
 * 1.2 frame docs/design/1.2-2026-09/forms-and-reports/stock-issue-gate-pass.html.
 *
 * @var array $input Retained editor values.
 */
$write = $enabled && pl_can_write($company);
$kinds = pl_stock_document_kinds();
$warehouseOptions = []; $fixedOptions = []; $vanOptions = [];
foreach ($warehouses as $w) {
    if (!$w['is_active']) { continue; }
    $label = $w['code'].' · '.$w['name'].($w['driver_name'] !== null ? ' · '.$w['driver_name'] : '');
    $warehouseOptions[(string) $w['id']] = $label;
    if ($w['kind'] === 'mobile') { $vanOptions[(string) $w['id']] = $label; } else { $fixedOptions[(string) $w['id']] = $label; }
}
$productOptions = pl_starter_options(array_values(array_filter($products, static fn (array $p): bool => $p['kind'] === 'stock' && $p['is_active'])));
$lines = is_array($input['lines'] ?? null) && $input['lines'] !== [] ? $input['lines'] : [[], [], []];
$kind = (string) ($input['kind'] ?? 'stock_issue');
$isGatePass = $new === 'gate_pass';
?>
<div class="flex flex-col gap-6 py-5">
<?php pl_ui_page_header('Stock issues and returns', 'Numbered stock documents for '.$company['name'].' · Moving stock between your own locations carries its cost and posts nothing', static function () use ($write): void { if ($write): ?>
<a class="btn btn-secondary" href="<?= pl_e(pl_url('/stock-documents', ['new' => 'stock_issue'])) ?>"><?= pl_icon('plus') ?> Stock issue</a>
<a class="btn btn-secondary" href="<?= pl_e(pl_url('/stock-documents', ['new' => 'stock_return'])) ?>"><?= pl_icon('plus') ?> Return from van</a>
<a class="btn btn-primary" href="<?= pl_e(pl_url('/stock-documents', ['new' => 'gate_pass'])) ?>"><?= pl_icon('plus') ?> Gate pass</a>
<?php endif; }); ?>
<?php if (!$enabled): ?><?php pl_ui_strip('Stock locations is disabled for this business. Existing stock documents stay readable; the owner can enable the module in Modules to record new ones.', 'warning'); ?><?php endif; ?>
<?php if ($form['message'] !== ''): ?><div class="alert alert-danger" role="alert" tabindex="-1" data-form-error><?= pl_e($form['message']) ?></div><?php endif; ?>

<?php if ($new !== '' && $write): ?>
<section class="rounded-panel border border-border bg-surface p-4">
<h2 class="section-title mb-3"><?= pl_e($kinds[$new]['label']) ?></h2>
<?php if ($isGatePass): ?>
<form method="post" action="<?= pl_e(pl_url('/stock-documents')) ?>" class="flex flex-col gap-4">
<?php pl_starter_form($company, 'gate_pass', ['request_key' => $input['request_key'] ?? null]); ?>
<p class="text-sm">A gate pass authorises goods to cross the gate. It moves no stock and posts nothing: it names the stock document that already moved the goods, so the guard can check the load without seeing the accounting record.</p>
<div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-4">
<?php
pl_starter_field('Date', 'date', $input['date'] ?? gmdate('Y-m-d'), 'date');
pl_starter_select('Gate (stock location)', 'warehouse_id', $warehouseOptions, $input['warehouse_id'] ?? '');
pl_starter_select('Direction', 'direction', ['out' => 'Goods leaving', 'in' => 'Goods entering'], $input['direction'] ?? 'out');
pl_starter_field('Stock document number or id it covers', 'covers_document_id', $input['covers_document_id'] ?? '');
pl_starter_field('Party', 'party_name', $input['party_name'] ?? '', 'text', false);
pl_starter_field('Vehicle', 'vehicle_reference', $input['vehicle_reference'] ?? '', 'text', false);
pl_starter_field('Driver or salesman', 'driver_name', $input['driver_name'] ?? '', 'text', false);
pl_starter_field('Expected return date', 'expected_return_date', $input['expected_return_date'] ?? '', 'date', false);
pl_starter_field('Purpose', 'purpose', $input['purpose'] ?? '');
pl_starter_field('Reference', 'reference', $input['reference'] ?? '', 'text', false);
pl_starter_field('Reason', 'reason', $input['reason'] ?? '');
?>
</div>
<p><label><input type="checkbox" name="is_returnable" value="1" <?= ($input['is_returnable'] ?? '1') === '1' ? 'checked' : '' ?>> Returnable — the goods are expected back, on the date above</label></p>
<div><button class="btn btn-primary">Issue gate pass</button></div>
</form>
<?php else: ?>
<form method="post" action="<?= pl_e(pl_url('/stock-documents')) ?>" class="flex flex-col gap-4">
<?php pl_starter_form($company, 'stock_document', ['request_key' => $input['request_key'] ?? null]); ?>
<div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-4">
<?php
pl_starter_select('Document', 'kind', array_map(static fn (array $d): string => $d['label'], array_filter($kinds, static fn (array $d): bool => $d['moves'])), $kind);
pl_starter_field('Dated', 'date', $input['date'] ?? gmdate('Y-m-d'), 'date');
pl_starter_select('From', 'from_warehouse_id', $kind === 'stock_return' ? $vanOptions : $fixedOptions, $input['from_warehouse_id'] ?? '');
pl_starter_select('To', 'to_warehouse_id', $kind === 'stock_return' ? $fixedOptions : $vanOptions, $input['to_warehouse_id'] ?? '');
pl_starter_field('Original stock issue (re-issue only)', 'original_document_id', $input['original_document_id'] ?? '', 'text', false);
pl_starter_field('Reference', 'reference', $input['reference'] ?? '', 'text', false);
pl_starter_field('Reason', 'reason', $input['reason'] ?? '');
?>
</div>
<h3 class="section-title">Lines</h3>
<?php pl_ui_table(['Stock product', 'Quantity in base units', ''], static function () use ($lines, $productOptions): void {
    foreach ($lines as $index => $line) { ?>
<tr><td><?php pl_starter_select('Product', 'lines['.$index.'][product_id]', $productOptions, $line['product_id'] ?? '', false, '', 'stock-line-product-'.$index); ?></td>
<td><?php pl_starter_field('Quantity', 'lines['.$index.'][quantity]', $line['quantity'] ?? '', 'text', false, '', 'stock-line-quantity-'.$index); ?></td>
<td><button class="btn btn-ghost" name="remove_line" value="<?= (int) $index ?>">Remove</button></td></tr>
<?php } }, 'Stock document lines'); ?>
<div class="flex gap-2"><button class="btn btn-ghost" name="editor_action" value="add_line">Add line</button><button class="btn btn-primary">Record stock document</button></div>
<div class="alert alert-info"><div><span class="alert-title">Stock effect</span><p>Overall stock does not change: goods on a van are still your stock. The value follows the goods at the source location's average cost and no ledger entry is written. Only a sale from the van reduces overall stock.</p></div></div>
</form>
<?php endif; ?>
</section>
<?php endif; ?>

<section>
<div class="flex flex-wrap gap-2 mb-2">
<?php foreach (['all' => 'All'] + array_map(static fn (array $d): string => $d['label'], $kinds) as $value => $label): ?>
<a class="btn <?= $kindFilter === (string) $value ? 'btn-secondary' : 'btn-ghost' ?>" href="<?= pl_e(pl_url('/stock-documents', ['kind' => $value])) ?>"><?= pl_e($label) ?></a>
<?php endforeach; ?>
</div>
<?php if ($documents === []): ?>
<?php pl_ui_empty('No stock documents yet', 'Record a stock issue when a van is loaded, a return when unsold stock comes back, and a gate pass for the security desk.'); ?>
<?php else: ?>
<?php pl_ui_table(['Number', 'Document', 'Dated', 'From', 'To', 'Lines', 'Quantity'], static function () use ($documents): void { foreach ($documents as $d): ?>
<tr>
<td><a class="link" href="<?= pl_e(pl_url('/stock-documents/detail', ['id' => $d['id']])) ?>"><?= pl_e((string) ($d['document_number'] ?? ('#'.$d['id']))) ?></a></td>
<td><?= pl_e($d['label']) ?><?php if (!$d['moves_stock']): ?> <span class="badge">Moves nothing</span><?php endif; ?></td>
<td><?= pl_e(pl_date_label((string) $d['document_date'])) ?></td>
<td><?= pl_e(trim(((string) ($d['from_code'] ?? '')).' · '.((string) ($d['from_name'] ?? '')), ' ·')) ?></td>
<td><?= pl_e(trim(((string) ($d['to_code'] ?? '')).' · '.((string) ($d['to_name'] ?? '')).($d['driver_name'] !== null ? ' · '.$d['driver_name'] : ''), ' ·')) ?></td>
<td class="amount"><?= (int) $d['line_count'] ?></td>
<td class="amount"><?= pl_e($d['total_quantity']) ?></td>
</tr>
<?php endforeach; }, 'Stock documents'); ?>
<?php endif; ?>
</section>
</div>
