<?php declare(strict_types=1);
require_once __DIR__.'/commercial-lines.php';
$enteredTotal='0.0000';
$errors=$form['message']!==''?pl_web_editor_errors($v,'purchase'):[];
$fields=['party_id'=>['purchase-party',pl_t('Supplier')],'date'=>['purchase-date',pl_t('Order date')],'currency'=>['purchase-currency',pl_t('Currency')],'reference'=>['purchase-reference',pl_t('Reference')]];
foreach (array_values($v['lines']??[[]]) ?: [[]] as $index=>$unused) {
    foreach (['description'=>pl_t('Description'),'product_id'=>pl_t('Product'),'quantity'=>pl_t('Quantity'),'unit_price'=>pl_t('Unit price')] as $field=>$label) { $fields['lines.'.$index.'.'.$field]=['commercial-'.$index.'-'.$field,pl_t('{label}, line {line}', ['label' => $label, 'line' => $index+1])]; }
}
try { foreach ($v['lines']??[] as $line) { $enteredTotal=bcadd($enteredTotal,pl_ar_line_amount((string)($line['quantity']??''),(string)($line['unit_price']??'')),4); } } catch (DomainException) { $enteredTotal='—'; }
?>
<div class="py-5"><section class="rounded-panel border border-border bg-surface">
<?php pl_ui_document_header($order?$order['number']:pl_t('New purchase order'),'draft',static function () use ($filters): void { ?>
<a class="btn btn-ghost" href="<?= pl_e(pl_workflow_url('/purchasing',$filters)) ?>"><?= pl_e(pl_t('Cancel')) ?></a>
<button class="btn btn-secondary" form="purchase-order-editor"><?= pl_e(pl_t('Save draft for review')) ?></button>
<button class="btn btn-primary" form="purchase-order-editor" name="editor_action" value="confirm_order"><?= pl_e(pl_t('Confirm order')) ?></button>
<?php }); ?>
<form id="purchase-order-editor" method="post" action="<?= pl_e(pl_workflow_url('/purchasing',$filters)) ?>" class="doc-body" data-commercial-form>
<?php pl_ui_error_summary($errors,$fields); ?>
<?php pl_ui_return_filters($filters); pl_starter_form($company,'save',['id'=>$order['id']??0,'revision'=>$saveInput?($saveInput['revision']??''):($order['revision']??0),'request_key'=>$saveInput['request_key']??null]); ?>
<?php if ($form['message']!==''): ?><div class="alert alert-danger" role="alert" tabindex="-1" data-form-error><?= pl_e($form['message']) ?><p><?= pl_e(pl_t('Your entered values are retained.')) ?><?php if ($order): ?> <a href="<?= pl_e(pl_workflow_url('/purchasing',['id'=>$order['id'],'return_filters'=>$filters])) ?>"><?= pl_e(pl_t('Reload the current order')) ?></a> <?= pl_e(pl_t('to resolve a revision conflict.')) ?><?php endif; ?></p></div><?php endif; ?>
<p class="text-xs text-ink-muted"><?= pl_e(pl_t('An order is a commitment. Accounting starts when goods are received or a supplier bill is posted.')) ?></p>
<section class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-4">
<?php pl_starter_select(pl_t('Supplier'),'party_id',$supplierOptions,$v['party_id']??'',error:$errors['party_id']??'',id:'purchase-party'); pl_starter_field(pl_t('Order date'),'date',$v['date']??$v['document_date']??gmdate('Y-m-d'),'date',error:$errors['date']??'',id:'purchase-date'); pl_starter_field(pl_t('Currency'),'currency',$v['currency']??$company['currency'],error:$errors['currency']??'',id:'purchase-currency'); pl_starter_field(pl_t('Reference'),'reference',$v['reference']??'','text',false,error:$errors['reference']??'',id:'purchase-reference'); pl_starter_field(pl_t('Notes'),'notes',$v['notes']??'','text',false); ?>
</section>
<section><h2 class="section-title mb-2"><?= pl_e(pl_t('Order lines')) ?></h2><?php pl_ui_commercial_lines($v['lines']??[],['product_id'=>$productOptions],false,true,$errors); ?>
<p class="text-xs text-ink-muted mt-3"><?= pl_e(pl_t('Purchase order prices are tax-exclusive; tax is applied when the supplier bill is matched. Amounts allow four decimal places.')) ?></p>
<div class="flex justify-end mt-3" data-fold="document total" data-commercial-grand><?php pl_ui_totals([pl_t('Total order value ({currency})',['currency'=>$company['currency']])=>$order?pl_money($order['total']):'—']); ?></div>
</section></form></section></div>
