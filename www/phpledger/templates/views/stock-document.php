<?php declare(strict_types=1);
/** One recorded stock document, its lines, the movements it created and its gate passes. */
$pass = $document['gate_pass'];
$write = $enabled && pl_can_write($company);
?>
<div class="py-5"><section class="rounded-panel border border-border bg-surface">
<?php pl_ui_document_header($document['label'].' · '.(string) $document['document_number'], 'posted', static function () use ($document, $pass): void { ?>
<a class="btn btn-ghost" href="<?= pl_e(pl_url('/stock-documents')) ?>"><?= pl_e(pl_t('Back to stock documents')) ?></a>
<?php if ($pass === null): ?>
<a class="btn btn-secondary" href="<?= pl_e(pl_url('/print/stock-issue/'.$document['id'], ['format' => '80mm'])) ?>"><?= pl_e(pl_t('80 mm load list')) ?></a>
<a class="btn btn-primary" href="<?= pl_e(pl_url('/print/stock-issue/'.$document['id'])) ?>"><?= pl_e(pl_t('Print A4')) ?></a>
<?php else: ?>
<a class="btn btn-primary" href="<?= pl_e(pl_url('/print/gate-pass/'.$document['id'])) ?>"><?= pl_e(pl_t('Print gate pass')) ?></a>
<?php endif; ?>
<?php }); ?>
<div class="doc-body flex flex-col gap-5">
<?php if ($form['message'] !== ''): ?><div class="alert alert-danger" role="alert" tabindex="-1" data-form-error><?= pl_e($form['message']) ?></div><?php endif; ?>
<section><h2 class="section-title mb-2"><?= pl_e(pl_t('Document')) ?></h2><div class="grid grid-cols-2 md:grid-cols-4 gap-4 rounded-panel border border-border p-4">
<?php
$facts = [pl_t('Number') => (string) $document['document_number'], pl_t('Dated') => pl_date_label((string) $document['document_date']),
    pl_t('From') => $document['from_warehouse'] === null ? '—' : $document['from_warehouse']['code'].' · '.$document['from_warehouse']['name'],
    pl_t('To') => $document['to_warehouse'] === null ? '—' : $document['to_warehouse']['code'].' · '.$document['to_warehouse']['name']];
if ($document['to_warehouse'] !== null && $document['to_warehouse']['driver_name'] !== null) { $facts[pl_t('Driver')] = (string) $document['to_warehouse']['driver_name']; }
if ($document['from_warehouse'] !== null && $document['from_warehouse']['driver_name'] !== null) { $facts[pl_t('Driver')] = (string) $document['from_warehouse']['driver_name']; }
if ($document['reference'] !== '') { $facts[pl_t('Reference')] = (string) $document['reference']; }
foreach ($facts as $label => $value): ?><div><span class="text-xs text-ink-muted"><?= pl_e($label) ?></span><p class="text-sm font-semibold mt-1"><?= pl_e($value) ?></p></div><?php endforeach; ?>
</div><p class="text-xs text-ink-muted mt-2"><?= pl_e((string) $document['reason']) ?></p></section>

<?php if ($pass !== null): ?>
<section><h2 class="section-title mb-2"><?= pl_e(pl_t('Gate pass')) ?></h2>
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 rounded-panel border border-border p-4">
<?php foreach ([pl_t('Direction') => $pass['direction'] === 'out' ? pl_t('Goods leaving') : pl_t('Goods entering'),
    pl_t('Returnable') => $pass['is_returnable'] ? pl_t('Yes') : pl_t('No'),
    pl_t('Expected back') => $pass['expected_return_date'] === null ? '—' : pl_date_label((string) $pass['expected_return_date']),
    pl_t('Covers') => $document['covers'] === null ? '—' : (string) $document['covers']['document_number'],
    pl_t('Party') => $pass['party_name'] !== '' ? (string) $pass['party_name'] : '—',
    pl_t('Vehicle') => $pass['vehicle_reference'] !== '' ? (string) $pass['vehicle_reference'] : '—',
    pl_t('Driver') => $pass['driver_name'] !== '' ? (string) $pass['driver_name'] : '—',
    pl_t('Security') => trim(($pass['security_out_at'] === null ? '' : pl_t('Out {when}', ['when' => $pass['security_out_at']])).' '.($pass['security_in_at'] === null ? '' : pl_t('In {when}', ['when' => $pass['security_in_at']]))) ?: pl_t('Not stamped'),
] as $label => $value): ?><div><span class="text-xs text-ink-muted"><?= pl_e($label) ?></span><p class="text-sm font-semibold mt-1"><?= pl_e((string) $value) ?></p></div><?php endforeach; ?>
</div>
<p class="text-sm mt-2"><?= pl_e(pl_t('This pass moves no stock and posts nothing.')) ?> <?= pl_e((string) $pass['purpose']) ?></p>
<?php if ($write): ?>
<form method="post" action="<?= pl_e(pl_url('/stock-documents/detail', ['id' => $document['id']])) ?>" class="flex gap-2 mt-3">
<?= pl_csrf_field() ?><?= pl_scope_fields($company) ?>
<input type="hidden" name="action" value="stamp"><input type="hidden" name="id" value="<?= (int) $document['id'] ?>">
<?php if ($pass['direction'] === 'out' && $pass['security_out_at'] === null): ?><button class="btn btn-secondary" name="stamp" value="out"><?= pl_e(pl_t('Stamp out of the gate')) ?></button><?php endif; ?>
<?php if ($pass['security_in_at'] === null): ?><button class="btn btn-secondary" name="stamp" value="in"><?= pl_e(pl_t('Stamp back in')) ?></button><?php endif; ?>
</form>
<?php endif; ?>
</section>
<?php endif; ?>

<?php $shown = $pass !== null && $document['covers'] !== null ? $document['covers'] : $document; ?>
<?php if ($shown['lines'] !== []): ?>
<section><h2 class="section-title mb-2"><?= pl_e($shown !== $document ? pl_t('Lines of {number}', ['number' => (string) $shown['document_number']]) : pl_t('Lines')) ?></h2>
<?php $showCost = (bool) ($shown['cost_visible'] ?? true); ?>
<?php pl_ui_table(array_merge([pl_t('#'), pl_t('Code'), pl_t('Item'), pl_t('Quantity'), pl_t('Unit')], $showCost ? [pl_t('Carrying value ({currency})', ['currency' => $company['currency']])] : [], [pl_t('Movements')]), static function () use ($shown, $showCost): void { foreach ($shown['lines'] as $line): ?>
<tr><td><?= (int) $line['line_number'] ?></td><td><?= pl_e((string) $line['sku']) ?></td><td><?= pl_e((string) $line['product_name']) ?></td>
<td class="amount"><?= pl_e((string) $line['quantity']) ?></td><td><?= pl_e((string) $line['base_unit']) ?></td>
<?php if ($showCost): ?><td class="amount"><?= pl_e(pl_money((string) $line['value_base'])) ?></td><?php endif; ?>
<td class="text-xs text-ink-muted"><?= pl_e(pl_t('out {out} · in {in}', ['out' => (int) $line['out_movement_id'], 'in' => (int) $line['in_movement_id']])) ?></td></tr>
<?php endforeach; }, pl_t('Stock document lines')); ?>
<?php pl_ui_totals(array_merge([pl_t('Quantity') => $shown['total_quantity']], $showCost ? [pl_t('Carrying value ({currency})', ['currency' => $company['currency']]) => pl_money((string) $shown['total_value_base'])] : [], [pl_t('Value at sale rate ({currency})', ['currency' => $company['currency']]) => pl_money($shown['total_sale_value'])])); ?>
<?php if (!$showCost): ?><p class="text-xs text-ink-muted"><?= pl_e(pl_t('Carrying value is a separate permission on this document. Ask an administrator for the "See cost and margin" permission, or check Admin > Cost visibility.')) ?></p><?php endif; ?>
<p class="text-xs text-ink-muted"><?= pl_e(pl_t('Each line moved its quantity between two of your own locations at the source\'s average cost. No journal was written: ownership did not change.')) ?></p>
</section>
<?php elseif ($pass === null): ?>
<p class="text-sm"><?= pl_e(pl_t('This document has no lines.')) ?></p>
<?php endif; ?>

<?php if ($document['gate_passes'] !== []): ?>
<section><h2 class="section-title mb-2"><?= pl_e(pl_t('Gate passes raised against this document')) ?></h2>
<?php pl_ui_table([pl_t('Number'), pl_t('Direction'), pl_t('Returnable'), pl_t('Dated')], static function () use ($document): void { foreach ($document['gate_passes'] as $g): ?>
<tr><td><a class="link" href="<?= pl_e(pl_url('/stock-documents/detail', ['id' => $g['id']])) ?>"><?= pl_e((string) $g['document_number']) ?></a></td>
<td><?= pl_e($g['gate_pass']['direction'] === 'out' ? pl_t('Goods leaving') : pl_t('Goods entering')) ?></td>
<td><?= pl_e($g['gate_pass']['is_returnable'] ? pl_t('Yes') : pl_t('No')) ?></td>
<td><?= pl_e(pl_date_label((string) $g['document_date'])) ?></td></tr>
<?php endforeach; }, pl_t('Gate passes')); ?>
</section>
<?php endif; ?>
</div></section></div>
