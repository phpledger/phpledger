<?php declare(strict_types=1);
$values=[];
foreach (is_array($input['allocations']??null)?$input['allocations']:[] as $row) { if (is_array($row)) { $values[pl_web_id($row,'item_id')]=pl_web_text($row,'amount_fc'); } }
$receipt=$direction==='receivable';
$advanceAccounts=array_filter($accounts,static fn(array $a):bool=>$a['role']===$advanceRole);
?>
<div class="flex flex-col gap-4 py-5">
<?php if ($batch!==null): ?>
<?php pl_ui_page_header($receipt?pl_t('Batch receipts'):pl_t('Batch payments'), pl_t('Enter many parties in one sitting. Each row posts its own voucher.'), static function (): void { ?><button class="btn btn-primary" form="batch-editor" name="action" value="preview_batch"><?= pl_e(pl_t('Update batch preview')) ?></button><?php }); ?>
<?php if ($form['message']!==''): ?><div class="alert alert-danger" role="alert" tabindex="-1" data-form-error><?= pl_e($form['message']) ?></div><?php endif; ?>
<form id="batch-editor" method="post" action="<?= pl_e(pl_workflow_url($path,['settle'=>'1','batch'=>'1'])) ?>" class="flex flex-col gap-4">
<?= pl_csrf_field() ?><?= pl_scope_fields($company) ?>
<input type="hidden" name="request_key" value="<?= pl_e(pl_web_text($input,'request_key',bin2hex(random_bytes(20)))) ?>">
<section class="panel !m-0"><div class="grid grid-cols-2 min-[900px]:grid-cols-4 gap-3">
<?php pl_starter_field($receipt?pl_t('Received date'):pl_t('Paid date'),'date',pl_web_text($input,'date',gmdate('Y-m-d')),'date'); ?>
<?php pl_starter_select(pl_t('Cash or bank account'),'bank_account_id',pl_starter_options(array_filter($accounts,static fn(array $a):bool=>$a['role']==='cash_bank')),pl_web_id($input,'bank_account_id')); ?>
<?php pl_starter_select(pl_t('{side} advances control', ['side' => pl_t(ucfirst($side))]),'advance_account_id',pl_starter_options($advanceAccounts),pl_web_id($input,'advance_account_id'),false); ?>
<?php pl_starter_field(pl_t('Batch reference'),'description',pl_web_text($input,'description')); ?>
</div>
<p class="text-xs text-ink-muted mt-3"><?= pl_e(pl_t('Each row posts its own balanced voucher with its own bank line, so one party\'s entry can be reversed without touching another\'s. Each row is allocated to that party\'s oldest documents first; anything left over becomes that party\'s own unapplied credit.')) ?></p>
</section>
<section class="panel !m-0">
<div class="table-wrap" tabindex="0" role="region" aria-label="<?= pl_e(pl_t('Batch rows; scroll horizontally on smaller screens')) ?>"><table class="table"><thead><tr><th><?= pl_e($receipt?pl_t('Customer'):pl_t('Vendor')) ?></th><th class="text-end"><?= pl_e(pl_t('Outstanding before')) ?></th><th><?= pl_e(pl_t('{label} {currency}', ['label' => $receipt?pl_t('Received'):pl_t('Paid'), 'currency' => $company['currency']])) ?></th><th class="text-end"><?= pl_e(pl_t('Applied')) ?></th><th class="text-end"><?= pl_e(pl_t('Held on account')) ?><?php pl_ui_help('unapplied-credit', 'sheet'); ?></th></tr></thead><tbody>
<?php
$rows=is_array($input['rows']??null)?$input['rows']:[];
$partyOptions=pl_starter_options(array_filter($parties,static fn(array $party):bool=>(bool)$party[$receipt?'is_customer':'is_vendor']),'legal_name');
for ($index=0;$index<max(6,count($rows)+2);$index++):
    $row=is_array($rows[$index]??null)?$rows[$index]:[];
    $planned=null;
    foreach ($batch['rows']??[] as $candidate) { if ($candidate['party_id']===pl_web_id($row,'party_id')) { $planned=$candidate; break; } }
?>
<tr>
<td><?php pl_starter_select($receipt?pl_t('Customer'):pl_t('Vendor'),"rows[{$index}][party_id]",$partyOptions,pl_web_id($row,'party_id'),false); ?><input type="hidden" name="rows[<?= $index ?>][currency]" value="<?= pl_e($company['currency']) ?>"></td>
<td class="amount"><?= $planned===null?'—':pl_e(pl_money($planned['outstanding_before'])) ?></td>
<td><input class="input min-w-24" inputmode="decimal" name="rows[<?= $index ?>][amount_fc]" value="<?= pl_e(pl_web_text($row,'amount_fc')) ?>" aria-label="<?= pl_e(pl_t('Amount for row {row}', ['row' => (string) ($index+1)])) ?>" maxlength="21"></td>
<td class="amount"><?= $planned===null?'—':pl_e(pl_money(bcsub($planned['amount_fc'],$planned['remainder_fc'],4))) ?></td>
<td class="amount"><?= $planned===null?'—':pl_e(pl_money($planned['remainder_fc'])) ?></td>
</tr>
<?php endfor; ?>
</tbody></table></div>
<?php if ($batch['rows']!==[]): ?>
<div class="flex justify-between items-center gap-3 mt-3"><p class="text-sm"><strong><?= count($batch['rows']) ?></strong> <?= pl_e(pl_t('vouchers will be posted, one per party row.')) ?></p>
<button class="btn btn-primary" name="action" value="post_batch"><?= pl_e(pl_t('Post all')) ?></button></div>
<?php else: ?><p class="text-xs text-ink-muted mt-3"><?= pl_e(pl_t('Enter at least one party and amount, then update the preview. Nothing is posted until you choose "Post all".')) ?></p><?php endif; ?>
</section>
</form>
<a class="btn btn-ghost self-start" href="<?= pl_e(pl_workflow_url($path,['settle'=>'1'])) ?>"><?= pl_e(pl_t('Single {kind} instead', ['kind' => $receipt?pl_t('receipt'):pl_t('payment')])) ?></a>
<?php else: ?>
<?php pl_ui_page_header($receipt?pl_t('Record customer receipt'):pl_t('Record supplier payment'), pl_t('Allocate one payment across open items for one party and currency.'), static function () use ($items): void { if ($items!==[]): ?><button class="btn btn-primary" form="settlement-editor" name="action" value="preview_settlement"><?= pl_e(pl_t('Update payment preview')) ?></button><?php endif; }); ?>
<?php if ($form['message']!==''): ?><div class="alert alert-danger" role="alert" tabindex="-1" data-form-error><?= pl_e($form['message']) ?></div><?php endif; ?>
<form method="get" action="<?= pl_e(pl_workflow_url($path)) ?>" class="filter-bar">
<?php pl_ui_account_return(); ?><input type="hidden" name="settle" value="1">
<?php pl_starter_select($receipt?pl_t('Customer'):pl_t('Vendor'),'party_id',pl_starter_options(array_filter($parties,static fn(array $party):bool=>(bool)$party[$receipt?'is_customer':'is_vendor']),'legal_name'),$partyId); ?>
<?php pl_starter_select(pl_t('Document currency'),'currency',array_combine($currencies,$currencies),$currency); ?>
<button class="btn btn-secondary"><?= pl_e(pl_t('Show open items')) ?></button><a class="btn btn-secondary" href="<?= pl_e(pl_workflow_url($path,['settle'=>'1','batch'=>'1'])) ?>"><?= pl_e(pl_t('Batch {kind}', ['kind' => $receipt?pl_t('receipts'):pl_t('payments')])) ?></a><a class="btn btn-ghost" href="<?= pl_e(pl_workflow_url($path)) ?>"><?= pl_e(pl_t('Back to documents')) ?></a>
</form>
<?php if ($unapplied['count']>0): ?><?php pl_ui_strip(pl_t('This {party} has {amount} of unapplied credit on the {side}-advances control. Apply it to an invoice or refund it before taking more money on account.', ['party' => $receipt?pl_t('customer'):pl_t('supplier'), 'amount' => $company['currency'].' '.pl_money($unapplied['total_base']), 'side' => pl_t($side)]),'info'); ?><?php endif; ?>
<?php if ($items===[]): ?><?php pl_ui_empty(pl_t('Choose open items'), pl_t('Select a party and currency with unpaid balances. No payment is posted from this screen until you preview and confirm.')); ?>
<?php else: ?>
<form id="settlement-editor" method="post" action="<?= pl_e(pl_workflow_url($path)) ?>" data-settlement-form class="grid grid-cols-1 min-[900px]:grid-cols-[minmax(0,1fr)_300px] gap-4">
<?= pl_csrf_field() ?><?= pl_scope_fields($company) ?><?php pl_ui_account_return(); ?>
<input type="hidden" name="request_key" value="<?= pl_e(pl_web_text($input,'request_key',bin2hex(random_bytes(20)))) ?>"><input type="hidden" name="party_id" value="<?= $partyId ?>"><input type="hidden" name="currency" value="<?= pl_e($currency) ?>">
<section class="panel !m-0"><div class="grid grid-cols-2 gap-3 mb-3">
<?php pl_starter_field(pl_t('Payment date'),'date',pl_web_text($input,'date',gmdate('Y-m-d')),'date'); pl_starter_field(pl_t('Payment amount ({currency})', ['currency' => $currency]),'amount_fc',pl_web_text($input,'amount_fc')); ?>
<?php pl_starter_select(pl_t('Cash or bank account'),'bank_account_id',pl_starter_options(array_filter($accounts,static fn(array $a):bool=>$a['role']==='cash_bank')),pl_web_id($input,'bank_account_id')); pl_starter_field(pl_t('Payment reference'),'description',pl_web_text($input,'description')); ?>
<?php if ($currency!==$company['currency']): ?><?php pl_starter_field(pl_t('Actual exchange rate (optional)'),'actual_rate',pl_web_text($input,'actual_rate'),'text',false); ?><p class="field-hint"><?= pl_e(pl_t('Enter the actual {base} per {currency}, or use the stored rate for the payment date.', ['base' => $company['currency'], 'currency' => $currency])) ?></p><?php endif; ?>
<?php pl_starter_select(pl_t('{side} advances control for any remainder', ['side' => pl_t(ucfirst($side))]),'advance_account_id',pl_starter_options($advanceAccounts),pl_web_id($input,'advance_account_id'),false); ?>
</div>
<div class="flex flex-wrap items-center gap-3 mb-3"><button class="btn btn-secondary" name="action" value="plan_settlement"><?= pl_e(pl_t('Allocate oldest first')) ?></button>
<p class="text-xs text-ink-muted m-0"><?= pl_e(pl_t('Fills the earliest-due document first. Every amount stays editable afterwards.')) ?></p></div>
<div class="text-xs text-ink-muted mb-3"><?= pl_e(pl_t('Enter the amount to allocate to each document; leave the others blank. Up to {cap} allocations; split a larger recovery into several receipts. Allocations may not exceed the payment. Whatever is left over is held as unapplied credit on the advances control above — it is never dropped and never guessed.', ['cap' => pl_settlement_allocation_cap()])) ?><?php pl_ui_help('unapplied-credit'); ?></div>
<div class="table-wrap max-h-64 overflow-auto" tabindex="0" role="region" aria-label="<?= pl_e(pl_t('Payment allocations; scroll to review all open items')) ?>"><table class="table"><thead><tr><th><?= pl_e(pl_t('Document')) ?></th><th><?= pl_e(pl_t('Due')) ?></th><th class="text-end"><?= pl_e(pl_t('Remaining')) ?></th><th><?= pl_e(pl_t('Allocate {currency}', ['currency' => $currency])) ?></th></tr></thead><tbody>
<?php foreach ($items as $index=>$item): ?><tr><th scope="row"><?= pl_e($item['number']) ?></th><td><?= pl_e($item['due_date']??'—') ?></td><td class="amount"><?= pl_e(pl_money($item['remaining_fc'])) ?></td><td><input type="hidden" name="allocations[<?= $index ?>][item_id]" value="<?= (int)$item['id'] ?>"><input class="input min-w-24" inputmode="decimal" name="allocations[<?= $index ?>][amount_fc]" value="<?= pl_e($values[$item['id']]??'') ?>" aria-label="<?= pl_e(pl_t('Allocate to {number}', ['number' => $item['number']])) ?>" data-allocation-amount maxlength="21"></td></tr><?php endforeach; ?>
</tbody></table></div><p class="text-xs text-ink-muted mt-3" data-allocation-total aria-live="polite"><?= pl_e(pl_t('Update the preview to validate the allocation total.')) ?></p>
</section>
<aside class="panel !m-0">
<h2 class="section-title"><?= pl_e(pl_t('Payment preview')) ?></h2>
<?php if ($preview): ?><div data-settlement-preview>
<?php pl_ui_totals([pl_t('Payment ({currency})', ['currency' => $preview['currency']])=>pl_money($preview['amount_fc']),pl_t('Applied to documents ({currency})', ['currency' => $preview['currency']])=>pl_money(bcsub($preview['amount_fc'],$preview['remainder_fc'],4)),pl_t('Held on account ({currency})', ['currency' => $preview['currency']])=>pl_money($preview['remainder_fc']),pl_t('Carrying amount ({currency})', ['currency' => $preview['functional_currency']])=>pl_money($preview['allocated_base']),pl_t('Bank amount ({currency})', ['currency' => $preview['functional_currency']])=>pl_money($preview['settlement_base'])]); ?>
<?php if ($preview['fx_kind']!==null): ?><p class="text-sm my-3"><?= pl_e(pl_t('Realised FX {kind}:', ['kind' => pl_t($preview['fx_kind'])])) ?> <strong><?= pl_e($preview['functional_currency'].' '.pl_money($preview['fx_amount'])) ?></strong></p><?php pl_starter_select(pl_t('Realised FX {kind} account', ['kind' => pl_t($preview['fx_kind'])]),$preview['fx_kind'].'_account_id',pl_starter_options(array_filter($accounts,static fn(array $a):bool=>$a['type']===($preview['fx_kind']==='gain'?'income':'expense'))),pl_web_id($input,$preview['fx_kind'].'_account_id')); ?>
<?php else: ?><p class="text-xs text-ink-muted my-3"><?= pl_e(pl_t('No exchange difference. FX accounts are not required.')) ?></p><?php endif; ?>
<p class="text-xs text-ink-muted my-3"><?= pl_e(bccomp($preview['remainder_fc'],'0',4)>0
    ? pl_t('{count} open items · One balanced journal and one bank entry. The remainder becomes unapplied credit for this {party} in the same journal, offered first against their next document. Reversing this payment reverses all its allocations; once the credit is applied, only the application can be reversed.', ['count' => count($preview['allocations']), 'party' => $receipt?pl_t('customer'):pl_t('supplier')])
    : pl_t('{count} open items · One balanced journal and one bank entry. Reversing this payment reverses all its allocations; once the credit is applied, only the application can be reversed.', ['count' => count($preview['allocations'])])
) ?></p>
<button class="btn btn-primary w-full" name="action" value="confirm_settlement"><?= pl_e(pl_t('Confirm {kind}', ['kind' => $receipt?pl_t('receipt'):pl_t('payment')])) ?></button>
</div><?php else: ?><p class="text-sm text-ink-muted mt-3"><?= pl_e(pl_t('Enter the payment and its allocations, then update the preview. The server checks remaining balances and calculates any exchange difference.')) ?></p><?php endif; ?>
</aside></form>
<?php endif; ?>
<?php endif; ?></div>
