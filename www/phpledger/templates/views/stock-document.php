<?php declare(strict_types=1);
/** One recorded stock document, its lines, the movements it created and its gate passes. */
$pass = $document['gate_pass'];
$write = $enabled && pl_can_write($company);
?>
<div class="py-5"><section class="rounded-panel border border-border bg-surface">
<?php pl_ui_document_header($document['label'].' · '.(string) $document['document_number'], 'posted', static function () use ($document, $pass): void { ?>
<a class="btn btn-ghost" href="<?= pl_e(pl_url('/stock-documents')) ?>">Back to stock documents</a>
<?php if ($pass === null): ?>
<a class="btn btn-secondary" href="<?= pl_e(pl_url('/print/stock-issue/'.$document['id'], ['format' => '80mm'])) ?>">80 mm load list</a>
<a class="btn btn-primary" href="<?= pl_e(pl_url('/print/stock-issue/'.$document['id'])) ?>">Print A4</a>
<?php else: ?>
<a class="btn btn-primary" href="<?= pl_e(pl_url('/print/gate-pass/'.$document['id'])) ?>">Print gate pass</a>
<?php endif; ?>
<?php }); ?>
<div class="doc-body flex flex-col gap-5">
<?php if ($form['message'] !== ''): ?><div class="alert alert-danger" role="alert" tabindex="-1" data-form-error><?= pl_e($form['message']) ?></div><?php endif; ?>
<section><h2 class="section-title mb-2">Document</h2><div class="grid grid-cols-2 md:grid-cols-4 gap-4 rounded-panel border border-border p-4">
<?php
$facts = ['Number' => (string) $document['document_number'], 'Dated' => pl_date_label((string) $document['document_date']),
    'From' => $document['from_warehouse'] === null ? '—' : $document['from_warehouse']['code'].' · '.$document['from_warehouse']['name'],
    'To' => $document['to_warehouse'] === null ? '—' : $document['to_warehouse']['code'].' · '.$document['to_warehouse']['name']];
if ($document['to_warehouse'] !== null && $document['to_warehouse']['driver_name'] !== null) { $facts['Driver'] = (string) $document['to_warehouse']['driver_name']; }
if ($document['from_warehouse'] !== null && $document['from_warehouse']['driver_name'] !== null) { $facts['Driver'] = (string) $document['from_warehouse']['driver_name']; }
if ($document['reference'] !== '') { $facts['Reference'] = (string) $document['reference']; }
foreach ($facts as $label => $value): ?><div><span class="text-xs text-ink-muted"><?= pl_e($label) ?></span><p class="text-sm font-semibold mt-1"><?= pl_e($value) ?></p></div><?php endforeach; ?>
</div><p class="text-xs text-ink-muted mt-2"><?= pl_e((string) $document['reason']) ?></p></section>

<?php if ($pass !== null): ?>
<section><h2 class="section-title mb-2">Gate pass</h2>
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 rounded-panel border border-border p-4">
<?php foreach (['Direction' => $pass['direction'] === 'out' ? 'Goods leaving' : 'Goods entering',
    'Returnable' => $pass['is_returnable'] ? 'Yes' : 'No',
    'Expected back' => $pass['expected_return_date'] === null ? '—' : pl_date_label((string) $pass['expected_return_date']),
    'Covers' => $document['covers'] === null ? '—' : (string) $document['covers']['document_number'],
    'Party' => $pass['party_name'] !== '' ? (string) $pass['party_name'] : '—',
    'Vehicle' => $pass['vehicle_reference'] !== '' ? (string) $pass['vehicle_reference'] : '—',
    'Driver' => $pass['driver_name'] !== '' ? (string) $pass['driver_name'] : '—',
    'Security' => trim(($pass['security_out_at'] === null ? '' : 'Out '.$pass['security_out_at']).' '.($pass['security_in_at'] === null ? '' : 'In '.$pass['security_in_at'])) ?: 'Not stamped',
] as $label => $value): ?><div><span class="text-xs text-ink-muted"><?= pl_e($label) ?></span><p class="text-sm font-semibold mt-1"><?= pl_e((string) $value) ?></p></div><?php endforeach; ?>
</div>
<p class="text-sm mt-2">This pass moves no stock and posts nothing. <?= pl_e((string) $pass['purpose']) ?></p>
<?php if ($write): ?>
<form method="post" action="<?= pl_e(pl_url('/stock-documents/detail', ['id' => $document['id']])) ?>" class="flex gap-2 mt-3">
<?= pl_csrf_field() ?><?= pl_scope_fields($company) ?>
<input type="hidden" name="action" value="stamp"><input type="hidden" name="id" value="<?= (int) $document['id'] ?>">
<?php if ($pass['direction'] === 'out' && $pass['security_out_at'] === null): ?><button class="btn btn-secondary" name="stamp" value="out">Stamp out of the gate</button><?php endif; ?>
<?php if ($pass['security_in_at'] === null): ?><button class="btn btn-secondary" name="stamp" value="in">Stamp back in</button><?php endif; ?>
</form>
<?php endif; ?>
</section>
<?php endif; ?>

<?php $shown = $pass !== null && $document['covers'] !== null ? $document['covers'] : $document; ?>
<?php if ($shown['lines'] !== []): ?>
<section><h2 class="section-title mb-2">Lines<?= $shown !== $document ? ' of '.pl_e((string) $shown['document_number']) : '' ?></h2>
<?php pl_ui_table(['#', 'Code', 'Item', 'Quantity', 'Unit', 'Carrying value ('.$company['currency'].')', 'Movements'], static function () use ($shown): void { foreach ($shown['lines'] as $line): ?>
<tr><td><?= (int) $line['line_number'] ?></td><td><?= pl_e((string) $line['sku']) ?></td><td><?= pl_e((string) $line['product_name']) ?></td>
<td class="amount"><?= pl_e((string) $line['quantity']) ?></td><td><?= pl_e((string) $line['base_unit']) ?></td>
<td class="amount"><?= pl_e(pl_money((string) $line['value_base'])) ?></td>
<td class="text-xs text-ink-muted">out <?= (int) $line['out_movement_id'] ?> · in <?= (int) $line['in_movement_id'] ?></td></tr>
<?php endforeach; }, 'Stock document lines'); ?>
<?php pl_ui_totals(['Quantity' => $shown['total_quantity'], 'Carrying value ('.$company['currency'].')' => pl_money($shown['total_value_base']), 'Value at sale rate ('.$company['currency'].')' => pl_money($shown['total_sale_value'])]); ?>
<p class="text-xs text-ink-muted">Each line moved its quantity between two of your own locations at the source's average cost. No journal was written: ownership did not change.</p>
</section>
<?php elseif ($pass === null): ?>
<p class="text-sm">This document has no lines.</p>
<?php endif; ?>

<?php if ($document['gate_passes'] !== []): ?>
<section><h2 class="section-title mb-2">Gate passes raised against this document</h2>
<?php pl_ui_table(['Number', 'Direction', 'Returnable', 'Dated'], static function () use ($document): void { foreach ($document['gate_passes'] as $g): ?>
<tr><td><a class="link" href="<?= pl_e(pl_url('/stock-documents/detail', ['id' => $g['id']])) ?>"><?= pl_e((string) $g['document_number']) ?></a></td>
<td><?= pl_e($g['gate_pass']['direction'] === 'out' ? 'Goods leaving' : 'Goods entering') ?></td>
<td><?= $g['gate_pass']['is_returnable'] ? 'Yes' : 'No' ?></td>
<td><?= pl_e(pl_date_label((string) $g['document_date'])) ?></td></tr>
<?php endforeach; }, 'Gate passes'); ?>
</section>
<?php endif; ?>
</div></section></div>
