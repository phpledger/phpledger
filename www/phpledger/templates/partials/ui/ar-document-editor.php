<?php declare(strict_types=1);
require_once __DIR__.'/commercial-lines.php';
$credit=$kind===$creditKind;
$label=pl_t(match($kind){'invoice'=>'Invoice','bill'=>'Bill','customer_credit'=>'Credit note',default=>'Supplier credit'});
$names=pl_starter_options($accounts);
$errors=$form['message']!==''?pl_web_editor_errors($v,'ar'):[];
// Trading controls (1.2 M3) appear on a customer document when the module is enabled, and the
// cash panel only when the owner has set a positive cap, so the screen never offers an entry
// the service would then refuse.
$trading=$trading??['enabled'=>false,'packs'=>[],'packs_by_product'=>[],'sales_staff'=>[],'areas'=>[],'warehouses'=>[],'policies'=>pl_trading_policy_defaults()+['revision'=>0]];
$tradingOn=($trading['enabled']??false) && $isAr;
$cashAllowed=$tradingOn && !$credit && bccomp((string)$trading['policies']['cash_on_invoice_cap'],'0',4)>0;
$fields=['party_id'=>['ar-party',pl_t('Customer / supplier')],'date'=>['ar-date',pl_t('Document date')],'due_date'=>['ar-due',pl_t('Due date')],'currency'=>['ar-currency',pl_t('Currency')],'reference'=>['ar-reference',pl_t('Reference')]];
foreach (array_values($v['lines']??[[]]) ?: [[]] as $index=>$unused) {
    foreach (['description'=>pl_t('Description'),'quantity'=>pl_t('Quantity'),'unit_price'=>pl_t('Unit price')] as $field=>$text) { $fields['lines.'.$index.'.'.$field]=['commercial-'.$index.'-'.$field,pl_t('{field}, line {line}', ['field'=>$text,'line'=>$index+1])]; }
}
?>
<div class="py-5"><section class="rounded-panel border border-border bg-surface">
<?php pl_ui_document_header($correct?pl_t('Correct {number}', ['number' => $document['number']]):($document?$document['number']:pl_t('New {type}', ['type' => $label])),$correct?'posted':'draft',static function () use ($correct,$path,$postingPreview,$filters): void { ?>
<a class="btn btn-ghost" href="<?= pl_e(pl_workflow_url($path,$filters)) ?>"><?= pl_e(pl_t('Cancel')) ?></a>
<?php if ($correct): ?><button class="btn btn-secondary" form="ar-document-editor" name="editor_action" value="preview"><?= pl_e(pl_t('Update correction preview')) ?></button><?php if ($postingPreview): ?><button class="btn btn-primary" form="ar-document-editor" name="editor_action" value="post_reviewed_document" data-review-confirm><?= pl_e(pl_t('Reverse and post correction')) ?></button><?php endif; ?>
<?php else: ?><button class="btn btn-secondary" form="ar-document-editor"><?= pl_e(pl_t('Save draft for review')) ?></button><button class="btn btn-secondary" form="ar-document-editor" name="editor_action" value="preview"><?= pl_e(pl_t('Update posting preview')) ?></button>
<?php if ($postingPreview): ?><button class="btn btn-primary" form="ar-document-editor" name="editor_action" value="post_reviewed_document" data-review-confirm><?= pl_e(pl_t('Post')) ?></button><?php endif; endif; ?>
<?php }); ?>
<form id="ar-document-editor" class="doc-body" method="post" action="<?= pl_e(pl_workflow_url($path,['return_filters'=>$filters])) ?>" data-commercial-form data-reviewed-form data-tax-context="<?= pl_e(json_encode($taxContext,JSON_THROW_ON_ERROR)) ?>" data-pack-sizes="<?= pl_e(json_encode(array_column($trading['packs'],'units_per_pack','id'),JSON_THROW_ON_ERROR)) ?>">
<?php pl_ui_account_return(); pl_ui_error_summary($errors,$fields); ?>
<?php pl_starter_form($company,$correct?'correct':'save',['id'=>$document['id']??0,'revision'=>$form['input']['revision']??$document['revision']??0,'kind'=>$kind,'original_document_id'=>$v['original_document_id']??0,'request_key'=>$form['input']['request_key']??bin2hex(random_bytes(20))]); ?>
<?php if ($form['message']!==''): ?><div class="alert alert-danger" role="alert" tabindex="-1" data-form-error><?= pl_e($form['message']) ?><p><?= pl_e(pl_t('Your entered values are retained.')) ?><?php if ($document): ?> <a href="<?= pl_e(pl_workflow_url($path,['id'=>$document['id'],'edit'=>'1','return_filters'=>$filters])) ?>"><?= pl_e(pl_t('Reload the saved document')) ?></a> <?= pl_e(pl_t('before resolving a revision conflict.')) ?><?php endif; ?></p></div><?php endif; ?>
<?php if ($original): ?><section><div class="alert alert-info"><?= pl_e(pl_t('Credit against')) ?> <a href="<?= pl_e(pl_workflow_url($path,['id'=>$original['id']])) ?>"><?= pl_e($original['number']) ?></a><?= pl_e(pl_t(', revision {revision}. Prices {mode} tax, matching the original. Choose the original line for each taxed credit.', ['revision' => (int)$original['revision'], 'mode' => $original['price_mode']==='inclusive'?pl_t('include'):pl_t('exclude')])) ?></div><details class="mt-2"><summary class="text-sm"><?= pl_e(pl_t('Original lines and remaining credit basis')) ?></summary><?php pl_ui_table([pl_t('Original line'),pl_t('Description'),pl_t('Net'),pl_t('Tax')],static function () use ($original): void { foreach ($original['lines'] as $line): ?><tr><td><?= (int)$line['line_number'] ?></td><td><?= pl_e($line['description']) ?></td><td class="amount"><?= pl_e(pl_money($line['line_total'])) ?></td><td class="amount"><?= pl_e(pl_money($line['tax_amount'])) ?></td></tr><?php endforeach; },pl_t('Original document lines')); ?><p class="text-xs text-ink-muted mt-2"><?= pl_e(pl_t('These are original amounts. Preview checks the remaining net and tax amounts after earlier credits.')) ?></p></details></section><?php endif; ?>
<section class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-4">
<?php pl_starter_select($isAr?pl_t('Customer'):pl_t('Vendor'),'party_id',pl_starter_options(array_filter($parties,static fn(array $p):bool=>(bool)$p[$isAr?'is_customer':'is_vendor']),'legal_name'),$v['party_id']??'',error:$errors['party_id']??'',id:'ar-party');
pl_starter_field(pl_t('Document date'),'date',$v['date']??$v['document_date']??gmdate('Y-m-d'),'date',error:$errors['date']??'',id:'ar-date'); pl_starter_field(pl_t('Due date'),'due_date',$v['due_date']??gmdate('Y-m-d'),'date',error:$errors['due_date']??'',id:'ar-due'); pl_starter_field(pl_t('Currency'),'currency',$v['currency']??$company['currency'],error:$errors['currency']??'',id:'ar-currency');
if ($credit) { pl_starter_hidden('price_mode',$v['price_mode']??$original['price_mode']??'exclusive'); } else { pl_starter_select(pl_t('Entered prices'),'price_mode',['exclusive'=>pl_t('Exclude tax'),'inclusive'=>pl_t('Include tax')],$v['price_mode']??$priceMode); }
pl_starter_field(pl_t('Reference'),'reference',$v['reference']??'','text',false,error:$errors['reference']??'',id:'ar-reference'); pl_starter_field(pl_t('Terms'),'terms',$v['terms']??'','text',false); pl_starter_field(pl_t('Notes'),'notes',$v['notes']??'','text',false); ?>
</section>
<?php if ($tradingOn): ?>
<section class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-4" aria-label="<?= pl_e(pl_t('Delivery details')) ?>">
<?php
// Trading dimensions (1.2 M3). Every one is optional; a document saved without them is the
// document this screen produced before, and a retired selection is refused when the draft saves.
if ($trading['warehouses']!==[]) { pl_starter_select(pl_t('Warehouse'),'warehouse_id',pl_starter_options($trading['warehouses']),$v['warehouse_id']??$document['warehouse_id']??'',false,id:'ar-warehouse'); }
if ($trading['sales_staff']!==[]) { pl_starter_select(pl_t('Sales staff'),'sales_staff_id',pl_starter_options($trading['sales_staff']),$v['sales_staff_id']??$document['sales_staff_id']??'',false,id:'ar-sales-staff'); }
if ($trading['areas']!==[]) { pl_starter_select(pl_t('Area / route'),'area_id',pl_starter_options($trading['areas']),$v['area_id']??$document['area_id']??'',false,id:'ar-area'); }
?>
</section>
<?php endif; ?>
<?php if ($cashAllowed): ?>
<section class="rounded-panel border border-border bg-surface p-4">
<h2 class="section-title mb-2"><?= pl_e(pl_t('Cash received on this invoice (optional)')) ?></h2>
<p class="text-xs text-ink-muted mb-3"><?= pl_e(pl_t('Recorded as a receipt against this invoice when it posts, for a counter or van sale paid on delivery. The recognition and this receipt are one action: neither can happen without the other.')) ?></p>
<div class="grid grid-cols-2 md:grid-cols-3 gap-4">
<?php pl_starter_field(pl_t('Cash received'),'cash_received',$v['cash_received']??($document['cash_received']??''),'text',false,id:'ar-cash-received');
pl_starter_select(pl_t('Cash or bank account'),'cash_account_id',pl_starter_options(array_filter($accounts,static fn(array $a):bool=>$a['role']==='cash_bank')),$v['cash_account_id']??$document['cash_account_id']??'',false,id:'ar-cash-account'); ?>
</div>
<p class="text-xs text-ink-muted mt-2"><?= pl_e(pl_t('Up to') . ' ' . pl_money($trading['policies']['cash_on_invoice_cap']) . ' ' . pl_t('per invoice, set in Admin > Accounting policies.')) ?></p>
</section>
<?php endif; ?>
<details<?= $correct || ($v['rate']??'')!=='' || ($v['rounding_account_id']??'')!==''?' open':'' ?>><summary class="text-sm"><?= pl_e($correct ? pl_t('More options and correction reason') : pl_t('More options')) ?></summary><div class="grid grid-cols-2 md:grid-cols-3 gap-4 mt-3">
<?php pl_starter_select(pl_t('FX rounding account (optional)'),'rounding_account_id',pl_starter_options(array_filter($accounts,static fn(array $a):bool=>$a['type']==='expense')),$v['rounding_account_id']??'',false); pl_starter_field($correct?pl_t('Replacement rate (base per foreign unit)'):pl_t('Manual exchange rate (blank for domestic or recorded rate)'),'rate',$v['rate']??'','text',false);
if ($correct) { pl_starter_field(pl_t('Correction reason'),'reason',$v['reason']??''); pl_starter_field(pl_t('Reversal date (blank = today)'),'reversal_date',$v['reversal_date']??'','date',false); } ?>
</div><?php if ($correct): ?><p class="text-xs text-ink-muted mt-2"><?= pl_e(pl_t('This keeps the document identity and records a linked reversal and replacement. Dependent payments and credits must be reversed first; closed periods remain protected.')) ?></p><?php endif; ?></details>
<?php if ($tradingOn && ($tradingReadout??null)!==null): $readout=$tradingReadout; ?>
<section class="rounded-panel border border-border bg-surface p-4" aria-label="<?= pl_e(pl_t('Balance and stock readout')) ?>" data-trading-readout>
<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
<div><p class="eyebrow"><?= pl_e(pl_t('Customer balance to date')) ?></p><p class="amount"><?= pl_e($readout['party_balance']===null?pl_t('Choose a customer'):$readout['currency'].' '.pl_money($readout['party_balance'])) ?></p></div>
<div><p class="eyebrow"><?= pl_e(pl_t('Warehouse stock')) ?><?= $readout['warehouse']===null?'':' &middot; '.pl_e($readout['warehouse']['code']) ?></p><p class="amount"><?= pl_e($readout['warehouse_stock']===null?pl_t('Choose a stock product'):pl_money($readout['warehouse_stock']['quantity']).' '.$readout['product']['base_unit']) ?></p></div>
<div><p class="eyebrow"><?= pl_e(pl_t('Stock in this book')) ?></p><p class="amount"><?= pl_e($readout['book_stock']===null?pl_t('Choose a stock product'):pl_money($readout['book_stock']['quantity']).' '.$readout['product']['base_unit']) ?></p></div>
</div>
<p class="text-xs text-ink-muted mt-2"><?= pl_e($readout['product']===null?pl_t('Stock appears once a line names a stock product.'):pl_t('Stock shown for') . ' ' . $readout['product']['name'] . ' ' . pl_t('(the last line named). A reading only: nothing here is posted.')) ?></p>
<p class="text-xs text-ink-muted"><?= pl_e(pl_t('Van stock per salesman needs the van warehouse kind, which is not in this book yet.')) ?></p>
</section>
<?php endif; ?>
<section><h2 class="section-title mb-2"><?= pl_e(pl_t('Lines')) ?></h2><?php pl_ui_commercial_lines($v['lines']??[],['account_id'=>$accountOptions,'product_id'=>pl_starter_options($products),'tax_code_id'=>pl_starter_options($taxCodes)],$credit,errors:$errors,trading:$tradingOn?$trading:[]); ?>
<p class="text-xs text-ink-muted mt-3"><?= pl_e(pl_t('Amounts allow four decimal places. Unit prices follow the tax entry mode above.')) ?> <?= pl_e($isAr?pl_t('A stock invoice also issues the goods and records their cost.'):pl_t('For stock purchases, receive goods in Purchasing before matching the supplier bill.')) ?></p></section>
<?php
$totals=[]; $totalKeys=[];
$previewDocument=$postingPreview['document']??null;
$totals[pl_t('Subtotal')]=$previewDocument?pl_money(bcadd((string)$previewDocument['subtotal'],(string)($previewDocument['discount_total']??'0'),4)):'—';
$totalKeys[pl_t('Subtotal')]='net';
if ($previewDocument && bccomp((string)($previewDocument['discount_total']??'0'),'0',4)>0) { $totals[pl_t('Line discounts')]='−'.pl_money((string)$previewDocument['discount_total']); $totalKeys[pl_t('Line discounts')]='discount'; }
$totals[pl_t('Tax')]=$previewDocument?pl_money($previewDocument['tax_total']):'—';
$totalKeys[pl_t('Tax')]='tax';
$totals[pl_t('Total').' ('.($v['currency']??$company['currency']).')']=$previewDocument?pl_money($previewDocument['total']):'—';
$totalKeys[pl_t('Total').' ('.($v['currency']??$company['currency']).')']='total';
if ($previewDocument && bccomp((string)($previewDocument['cash_received']??'0'),'0',4)>0) {
    $totals[pl_t('Cash received')]='−'.pl_money((string)$previewDocument['cash_received']);
    $totalKeys[pl_t('Cash received')]='preview';
    $totals[pl_t('Balance receivable')]=pl_money(bcsub((string)$previewDocument['total'],(string)$previewDocument['cash_received'],4));
    $totalKeys[pl_t('Balance receivable')]='preview';
}
if ($previewDocument && bccomp((string)($previewDocument['free_tax_total']??'0'),'0',4)>0) { $totals[pl_t('Output tax on free goods, borne by us')]=pl_money((string)$previewDocument['free_tax_total']); $totalKeys[pl_t('Output tax on free goods, borne by us')]='preview'; }
?>
<div class="flex justify-end" data-commercial-tax-totals><?php pl_ui_totals($totals,$totalKeys); ?></div>
<?php if (isset($postingPreview['reversal'])): ?><section data-review-preview><h2 class="section-title mb-2"><?= pl_e(pl_t('Reversal on {date}', ['date' => pl_date_label($postingPreview['reversal']['date'])])) ?></h2>
<?php pl_ui_table([pl_t('Account'),pl_t('Debit ({currency})', ['currency' => $postingPreview['currency']]),pl_t('Credit ({currency})', ['currency' => $postingPreview['currency']])],static function () use ($postingPreview,$names):void { foreach ($postingPreview['reversal']['lines'] as $line): ?><tr><td><?= pl_e($names[$line['account_id']]??pl_t('Account {id}', ['id' => $line['account_id']])) ?></td><td class="amount"><?= pl_e(pl_money($line['debit'])) ?></td><td class="amount"><?= pl_e(pl_money($line['credit'])) ?></td></tr><?php endforeach; },pl_t('Correction reversing entry')); ?>
<p class="text-xs text-ink-muted mt-2"><?= pl_e(pl_t('The original journal stays unchanged. This reversal and the replacement below post together, or neither posts.')) ?></p></section><?php endif; ?>
<?php if (($postingPreview['stock_reversal']??[])!==[]): ?><section data-review-preview><h2 class="section-title mb-2"><?= pl_e(pl_t('Stock restored before replacement')) ?></h2>
<?php foreach ($postingPreview['stock_reversal'] as $effect): ?><p class="text-xs text-ink-muted"><?= pl_e(pl_t('{product} · Quantity change {quantity} · Carrying value change {currency} {value}', ['product' => $effect['product_name'], 'quantity' => $effect['quantity_delta'], 'currency' => $company['currency'], 'value' => $effect['value_delta']])) ?></p><?php endforeach; ?></section><?php endif; ?>
<?php if ($postingPreview): ?><section data-review-preview>
<details open><summary class="section-title mb-2"><?= pl_e(pl_t('Posting preview')) ?></summary><?php pl_ui_table([pl_t('Account'),pl_t('Debit ({currency})', ['currency' => $postingPreview['currency']]),pl_t('Credit ({currency})', ['currency' => $postingPreview['currency']])],static function () use ($postingPreview,$names): void { foreach ($postingPreview['lines'] as $line): ?><tr><td><?= pl_e($names[$line['account_id']]??pl_t('Account {id}', ['id' => $line['account_id']])) ?></td><td class="amount"><?= pl_e(pl_money($line['debit'])) ?></td><td class="amount"><?= pl_e(pl_money($line['credit'])) ?></td></tr><?php endforeach; },pl_t('Customer or supplier document posting')); ?></details></section><?php endif; ?>
<?php if (($postingPreview['stock']??[])!==[]): ?><section data-review-preview><details open><summary class="section-title mb-2"><?= pl_e(pl_t('Stock and cost effects')) ?></summary>
<?php foreach ($postingPreview['stock'] as $stock): ?><p class="text-xs text-ink-muted my-2"><?= pl_e(pl_t('{product} · Quantity change {quantity} · Carrying value change {value}', ['product' => $stock['product_name'], 'quantity' => $stock['quantity_delta'], 'value' => $company['currency'].' '.$stock['value_delta']])) ?></p>
<?php pl_ui_table([pl_t('Account'),pl_t('Debit'),pl_t('Credit')],static function () use ($stock,$names): void { foreach ($stock['movement']['journal']['lines']??[] as $line): ?><tr><td><?= pl_e($names[$line['account_id']]??pl_t('Account {id}', ['id' => $line['account_id']])) ?></td><td class="amount"><?= pl_e(pl_money($line['debit'])) ?></td><td class="amount"><?= pl_e(pl_money($line['credit'])) ?></td></tr><?php endforeach; },pl_t('Linked stock journal effect')); ?>
<?php endforeach; ?></details></section><?php endif; ?>
<p class="text-xs text-ink-muted" data-review-message aria-live="polite"><?= pl_e(pl_t('Posting adds this transaction to reports. A saved draft does not change your books.')) ?></p>
</form></section></div>
