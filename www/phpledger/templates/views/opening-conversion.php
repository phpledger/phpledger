<?php
 declare(strict_types=1);
 $v=$form['input'];
 $confirmInput=($v['action']??'')==='confirm'?$v:[];
 $mappingErrors=[];
 if ($form['message']!=='' && ($v['action']??'')==='preview') {
     $submitted=[];
     foreach (is_array($v['mappings']??null)?$v['mappings']:[] as $mapping) {
         if (is_array($mapping)) { $submitted[pl_web_id($mapping,'opening_document_id')]=pl_web_id($mapping,'party_id'); }
     }
     foreach ($cutovers as $cutover) {
         if ((int)$cutover['id']!==pl_web_id($v,'cutover_id')) { continue; }
         foreach ($cutover['documents'] as $document) {
             $partyId=$submitted[(int)$document['id']]??0;
             $eligible=array_filter($parties,static fn(array $party):bool=>(int)$party['id']===$partyId && (bool)$party[$document['kind']==='receivable'?'is_customer':'is_vendor']);
             if (!$eligible) { $mappingErrors[(int)$document['id']]=['reference'=>$document['reference'],'message'=>pl_t('Choose a {party_kind} for this document.',['party_kind'=>$document['kind']==='receivable'?pl_t('customer'):pl_t('supplier')])]; }
         }
     }
 }

?>
<section class="flex flex-col gap-3 py-5">
<?php pl_starter_header(pl_t('Opening documents'),pl_t('Map unpaid opening documents into the shared receivables and payables ledger.'),$form,$company); ?>
<?php if ($mappingErrors): ?><div class="alert alert-danger" role="alert" tabindex="-1" data-form-error><div><strong><?= pl_e(pl_t('{count} rows need attention.',['count'=>count($mappingErrors)])) ?></strong><ul class="list-disc ps-5"><?php foreach ($mappingErrors as $documentId=>$error): ?><li><a class="link" href="#opening-party-<?= $documentId ?>"><?= pl_e($error['reference'].' — '.$error['message']) ?></a></li><?php endforeach; ?></ul></div></div><?php endif; ?>
<p class="text-sm text-ink-muted"><?= pl_e(pl_t('Conversion preserves the original trial balance and creates no new journal. Each unpaid document needs an explicit party mapping, with an exact reconciliation to its existing control account.')) ?></p>
<?php foreach ($cutovers as $cutover):
    if (!$cutover['documents']) { continue; }
    $previewInput=($v['action']??'')==='preview'&&(int)($v['cutover_id']??0)===(int)$cutover['id']?$v:[];
    $selectedParties=[];
    foreach (is_array($previewInput['mappings']??null)?$previewInput['mappings']:[] as $mapping) {
        if (is_array($mapping)) { $selectedParties[(int)($mapping['opening_document_id']??0)]=$mapping['party_id']??''; }
    }
?><details class="rounded-panel border border-border bg-surface p-4"<?= $review && (int) $review['review']['cutover_id'] === (int) $cutover['id'] ? '' : ' open' ?>><summary class="section-title"><?= pl_e(pl_t('Cutover {date} · Party mappings',['date'=>$cutover['cutover_date']])) ?></summary>
<?php if ($company['role']==='owner'): ?><form method="post" action="<?= pl_e(pl_url('/opening-conversion')) ?>"><?php pl_starter_form($company,'preview',['cutover_id'=>$cutover['id'],'request_key'=>$previewInput['request_key']??null]); ?>
<?php pl_ui_table([pl_t('Row'),pl_t('Document'),pl_t('Kind'),pl_t('Due'),pl_t('Outstanding'),pl_t('Map to party')], static function () use ($cutover, $company, $parties, $selectedParties, $mappingErrors): void {
foreach ($cutover['documents'] as $index=>$document): ?><tr><td><?= (int) $index + 1 ?></td><td><?= pl_e($document['reference']) ?><span class="row-sub"><?= pl_e($document['party']) ?></span></td><td><?= pl_e(ucfirst($document['kind'])) ?></td><td><?= pl_e(pl_date_label($document['due_date'])) ?></td><td class="amount"><?= pl_e($company['currency'].' '.pl_money($document['outstanding'])) ?></td><td>
<?php pl_starter_hidden("mappings[$index][opening_document_id]",$document['id']); $fieldId='opening-party-'.(int)$document['id']; $error=$mappingErrors[(int)$document['id']]['message']??'';
$options=pl_starter_options(array_filter($parties,fn($p)=>(bool)$p[$document['kind']==='receivable'?'is_customer':'is_vendor']),'legal_name');
$selected=$selectedParties[(int)$document['id']]??'';
pl_ui_field($fieldId,pl_t('Party for {reference}',['reference'=>$document['reference']]),static function () use ($fieldId,$error,$options,$selected,$index):void { ?>
<select class="select" id="<?= pl_e($fieldId) ?>" name="mappings[<?= $index ?>][party_id]" required<?= $error!==''?' aria-invalid="true" aria-describedby="'.pl_e($fieldId).'-error"':'' ?>><option value=""><?= pl_e(pl_t('Choose…')) ?></option><?php foreach ($options as $id=>$label): ?><option value="<?= (int)$id ?>"<?= (string)$selected===(string)$id?' selected':'' ?>><?= pl_e($label) ?></option><?php endforeach; ?></select>
<?php },'',$error); ?></td></tr><?php endforeach;
}, pl_t('Unpaid opening documents and party mappings')); ?>
<div class="panel-actions" data-fold="primary action"><button class="btn btn-primary"><?= pl_e(pl_t('Preview mapped debts')) ?></button></div></form><?php else: ?><p><?= pl_e(pl_t('The company owner must review opening conversion.')) ?></p><?php endif; ?></details><?php endforeach; ?>
<?php if ($review): ?><section class="rounded-panel border border-border bg-surface p-4"><h2 class="section-title mb-2"><?= pl_e(pl_t('Confirm reviewed opening debts')) ?></h2>
<?php pl_ui_table([pl_t('Document'),pl_t('Original party'),pl_t('Mapped party'),pl_t('Amount')], static function () use ($review): void {
foreach ($review['review']['documents'] as $row): ?><tr><td><?= pl_e($row['reference']) ?></td><td><?= pl_e($row['original_party']) ?></td><td><?= pl_e($row['party_name']) ?></td><td class="amount"><?= pl_e(pl_money($row['amount_base'])) ?></td></tr><?php endforeach;
}, pl_t('Reviewed opening mappings')); ?>
<?php pl_ui_table([pl_t('Control account'),pl_t('Existing basis'),pl_t('Mapped debts')], static function () use ($review): void {
foreach ($review['review']['accounts'] as $row): ?><tr><td><?= pl_e($row['account_code']) ?></td><td class="amount"><?= pl_e(pl_money($row['basis_amount'])) ?></td><td class="amount"><?= pl_e(pl_money($row['document_total'])) ?></td></tr><?php endforeach;
}, pl_t('Opening document control reconciliation')); ?>
<?php if ($company['role']==='owner'): ?>
<form method="post" action="<?= pl_e(pl_url('/opening-conversion')) ?>"><?php
pl_starter_form($company,'confirm',['expected_hash'=>$confirmInput?($confirmInput['expected_hash']??''):$review['review']['payload_hash'],'request_key'=>$confirmInput['request_key']??null]);
pl_starter_field(pl_t('Reason'),'reason',$confirmInput['reason']??'');
?><p><label><input type="checkbox" name="confirmed" value="1" required <?= ($confirmInput['confirmed']??'')==='1'?'checked':'' ?>> <?= pl_e(pl_t('I reviewed the party mappings and reconciled amounts.')) ?></label></p>
<div class="panel-actions" data-fold="primary action"><button class="btn btn-primary"><?= pl_e(pl_t('Confirm conversion')) ?></button><?php if ($confirmInput): ?><a class="btn btn-secondary" href="<?= pl_e(pl_url('/opening-conversion')) ?>"><?= pl_e(pl_t('Reload current review')) ?></a><?php endif; ?></div></form>
<?php else: ?><p><?= pl_e(pl_t('Only the company owner can confirm opening conversion.')) ?></p><?php endif; ?></section><?php endif; ?>
<section class="rounded-panel border border-border bg-surface p-4"><h2 class="section-title mb-2"><?= pl_e(pl_t('Converted opening debts')) ?></h2><?php if (!$items): ?>
<?php if ($company['setup_status']==='opening_required'): ?><p><?= pl_e(pl_t('Prepare and confirm the opening trial balance and unpaid documents before mapping them here.')) ?></p><a class="btn btn-primary" href="<?= pl_e(pl_url('/opening-balances')) ?>"><?= pl_e(pl_t('Prepare opening balances')) ?></a>
<?php elseif ($company['setup_status']==='review_required'): ?><p><?= pl_e(pl_t('Review the existing books before converting their unpaid opening documents.')) ?></p><a class="btn btn-primary" href="<?= pl_e(pl_url('/setup/review')) ?>"><?= pl_e(pl_t('Review existing setup')) ?></a>
<?php elseif ($cutovers): ?><p><?= pl_e(pl_t('Map the unpaid documents above, review their control totals, then confirm conversion.')) ?></p>
<?php else: ?><p><?= pl_e(pl_t('No unpaid opening documents need conversion. Use ordinary invoices and bills for new activity.')) ?></p><?php endif; endif; ?>
<div class="table-wrap"><table class="table"><thead><tr><th scope="col"><?= pl_e(pl_t('Document')) ?></th><th scope="col"><?= pl_e(pl_t('Party')) ?></th><th scope="col"><?= pl_e(pl_t('Direction')) ?></th><th scope="col" class="num"><?= pl_e(pl_t('Remaining')) ?></th><th scope="col"><span class="sr-only"><?= pl_e(pl_t('Action')) ?></span></th></tr></thead><tbody>
<?php foreach ($items as $item): ?><tr><td><?= pl_e($item['reference']) ?></td><td><?= pl_e($item['legal_name']) ?></td><td><?= pl_e(ucfirst($item['direction'])) ?></td><td class="num"><?= pl_e($item['currency'].' '.pl_money($item['remaining_fc'])) ?></td><td><?php if (pl_can_write($company) && bccomp($item['remaining_fc'],'0',4)>0): ?><a class="btn btn-secondary btn-sm" href="<?= pl_e(pl_url($item['direction']==='receivable'?'/ar':'/ap',['settle'=>'1','party_id'=>$item['party_id'],'currency'=>$item['currency']])) ?>"><?= pl_e(pl_t('Record payment')) ?></a><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div>
<p class="text-xs text-ink-muted mt-3"><?= pl_e(pl_t('Use the shared payment editor to review and allocate a payment across this party’s open documents. Opening conversion never adds a second opening journal.')) ?></p>
</section>
</section>
