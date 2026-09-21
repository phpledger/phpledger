<?php declare(strict_types=1); ?>
<div class="py-5"><section class="rounded-panel border border-border bg-surface">
<?php pl_ui_document_header(pl_t('Stock count · {product}',['product'=>$product['name']]),'draft',static function () use ($product,$preview,$filters): void { ?>
<a class="btn btn-ghost" href="<?= pl_e(pl_url('/inventory',['id'=>$product['id'],'return_filters'=>$filters])) ?>"><?= pl_e(pl_t('Cancel')) ?></a>
<button class="btn btn-secondary" form="stock-count-editor" name="action" value="count_preview"><?= pl_e(pl_t('Update count preview')) ?></button>
<?php if ($preview): ?><button class="btn btn-primary" form="stock-count-editor" name="action" value="count_confirm" data-review-confirm><?= pl_e(pl_t('Confirm stock count')) ?></button><?php endif; ?>
<?php }); ?>
<form id="stock-count-editor" class="doc-body" method="post" action="<?= pl_e(pl_url('/inventory')) ?>" data-reviewed-form>
<?php pl_ui_return_filters($filters); ?><?= pl_csrf_field() ?><?= pl_scope_fields($company) ?>
<?php foreach (['id'=>$product['id'],'expected_quantity'=>$input['expected_quantity']??$balance['quantity'],'request_key'=>$input['request_key']??bin2hex(random_bytes(20))] as $key=>$value) { pl_starter_hidden($key,$value); } ?>
<?php if ($form['message']!==''): ?><div class="alert alert-danger" role="alert" tabindex="-1" data-form-error><?= pl_e($form['message']) ?><p><?= pl_e(pl_t('Your values are retained.')) ?> <a href="<?= pl_e(pl_url('/inventory',['id'=>$product['id'],'count'=>'1','return_filters'=>$filters])) ?>"><?= pl_e(pl_t('Reload current stock')) ?></a> <?= pl_e(pl_t('if the recorded quantity changed.')) ?></p></div><?php endif; ?>
<p class="text-xs text-ink-muted"><?= pl_e(pl_t('Use Purchasing for ordered goods and supplier returns. A count needs an explicit counterpart account and reason, and adjusts quantity and value together.')) ?></p>
<section><h2 class="section-title mb-2"><?= pl_e(pl_t('Recorded vs. counted')) ?></h2><div class="grid grid-cols-2 md:grid-cols-4 gap-4 rounded-panel border border-border p-4" data-review-preview>
<?php foreach ([pl_t('Recorded')=>$preview['recorded']['quantity']??$balance['quantity'],pl_t('Counted')=>$preview['counted_quantity']??'—',pl_t('Difference')=>$preview['effect']['quantity_delta']??'—',pl_t('Value effect ({currency})',['currency'=>$company['currency']])=>$preview['effect']['value_delta']??'—'] as $label=>$value): ?><div><span class="text-xs text-ink-muted"><?= pl_e($label) ?></span><p class="text-xl font-semibold amount mt-1"><?= pl_e($value) ?></p></div><?php endforeach; ?>
</div></section>
<section><h2 class="section-title mb-2"><?= pl_e(pl_t('Count details')) ?></h2><div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-4">
<?php pl_starter_field(pl_t('Date'),'date',$input['date']??gmdate('Y-m-d'),'date'); pl_starter_field(pl_t('Counted quantity'),'counted_quantity',$input['counted_quantity']??''); pl_starter_field(pl_t('Unit cost for count increases'),'unit_cost',$input['unit_cost']??'','text',false);
pl_starter_select(pl_t('Counterpart account'),'offset_account_id',pl_starter_options($accounts),$input['offset_account_id']??''); if ($locations) { $locationOptions=[]; foreach ($warehouses as $w) { if ($w['is_active']) { $locationOptions[(string)$w['id']]=$w['code'].' · '.$w['name']; } } pl_starter_select(pl_t('Stock location'),'warehouse_id',$locationOptions,$input['warehouse_id']??'',false); } pl_starter_field(pl_t('Source reference'),'reference',$input['reference']??''); pl_starter_field(pl_t('Reason'),'reason',$input['reason']??''); ?>
</div></section>
<?php if ($preview): ?><section data-review-preview><h2 class="section-title mb-2"><?= pl_e(pl_t('Journal effect')) ?></h2>
<?php if ($preview['journal']): $names=pl_starter_options($accounts); pl_ui_table([pl_t('Account'),pl_t('Debit ({currency})',['currency'=>$company['currency']]),pl_t('Credit ({currency})',['currency'=>$company['currency']])],static function () use ($preview,$names): void { foreach ($preview['journal']['lines'] as $line): ?><tr><td><?= pl_e($names[$line['account_id']]??pl_t('Account {id}',['id'=>$line['account_id']])) ?></td><td class="amount"><?= pl_e(pl_money($line['debit'])) ?></td><td class="amount"><?= pl_e(pl_money($line['credit'])) ?></td></tr><?php endforeach; },pl_t('Stock count journal effect')); ?>
<?php else: ?><p class="text-sm"><?= pl_e(pl_t('This count changes quantity without a carrying-value change. No journal is required.')) ?></p><?php endif; ?>
<p class="text-xs text-ink-muted mt-2"><?= pl_e(pl_t('Confirmation rejects safely if the recorded quantity or carrying value changed since this count was reviewed.')) ?></p></section><?php endif; ?>
<p class="text-xs text-ink-muted" data-review-message aria-live="polite"><?= pl_e(pl_t('Update the preview before confirming. Nothing is posted by a preview.')) ?></p>
</form></section></div>
