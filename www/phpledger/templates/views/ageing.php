<?php declare(strict_types=1);
$labels = ['not_due'=>'Not overdue','1_30'=>'1–30 days','31_60'=>'31–60 days','61_90'=>'61–90 days','over_90'=>'Over 90 days'];
$sourcePath = $report['direction'] === 'receivable' ? '/ar' : '/ap';
?>
<div class="flex flex-col gap-4 py-5">
<?php pl_ui_page_header(pl_t('Receivables & payables ageing'), pl_t('{company} · Unpaid balances at {date}', ['company' => $company['name'], 'date' => pl_date_label($report['as_of'])])); ?>
<form method="get" action="<?= pl_e(pl_url('/reports/ageing')) ?>" class="filter-bar" data-fold="primary action">
    <label class="field"><?= pl_e(pl_t('Balance type')) ?><select class="select" name="direction"><option value="receivable" <?= $report['direction']==='receivable'?'selected':'' ?>><?= pl_e(pl_t('Receivables')) ?></option><option value="payable" <?= $report['direction']==='payable'?'selected':'' ?>><?= pl_e(pl_t('Payables')) ?></option></select></label>
    <label class="field"><?= pl_e(pl_t('As of')) ?><input class="input" type="date" name="as_of" value="<?= pl_e($report['as_of']) ?>" required></label>
    <button class="btn btn-primary"><?= pl_e(pl_t('Update report')) ?></button><a class="btn btn-ghost" href="<?= pl_e(pl_url('/reports')) ?>"><?= pl_e(pl_t('All reports')) ?></a>
</form>
<p class="text-xs text-ink-muted"><?= pl_e(pl_t('Days past the due date. Totals use recorded carrying amounts in {currency}; document currency balances appear beside them. Drafts are excluded.', ['currency' => $company['currency']])) ?></p>
<div class="grid grid-cols-2 gap-3 min-[900px]:grid-cols-5">
<?php foreach ($labels as $key=>$label): ?><section class="panel !m-0"><h2 class="text-xs text-ink-muted"><?= pl_e($label) ?></h2><p class="amount mt-2 text-lg font-semibold"><?= pl_e(pl_money($report['buckets'][$key])) ?></p></section><?php endforeach; ?>
</div>
<?php pl_ui_strip($report['reconciled']?pl_t('Open items reconcile to the control accounts.'):pl_t('Control accounts and open items differ. Review the reconciliation below before relying on this report.'), $report['reconciled']?'success':'warning'); ?>
<section class="panel !m-0"><div class="flex justify-between gap-3 mb-3"><h2 class="section-title"><?= pl_e(pl_t('{count} unpaid documents', ['count' => (int)$report['count']])) ?></h2><strong class="amount"><?= pl_e(pl_t('Total {amount}', ['amount' => $company['currency'].' '.pl_money($report['total_base'])])) ?></strong></div>
<div class="table-wrap" tabindex="0" role="region" aria-label="<?= pl_e(pl_t('Ageing documents; scroll horizontally on smaller screens')) ?>"><table class="table"><thead><tr><th><?= pl_e(pl_t('Customer / vendor')) ?></th><th><?= pl_e(pl_t('Document')) ?></th><th><?= pl_e(pl_t('Due date')) ?></th><th><?= pl_e(pl_t('Age')) ?></th><th class="text-end"><?= pl_e(pl_t('Document balance')) ?></th><th class="text-end"><?= pl_e(pl_t('{currency} balance', ['currency' => $company['currency']])) ?></th></tr></thead><tbody>
<?php foreach ($report['items'] as $item): ?><tr><th scope="row"><?= pl_e($item['legal_name']) ?></th><td><?php if ($item['document_id']): ?><a class="link" href="<?= pl_e(pl_url($sourcePath,['id'=>$item['document_id'],'as_of'=>$report['as_of'],'return_ageing'=>['direction'=>$report['direction'],'as_of'=>$report['as_of']]])) ?>"><?= pl_e($item['number']) ?></a><?php else: ?><a class="link" href="<?= pl_e(pl_url('/opening-conversion')) ?>"><?= pl_e($item['number']) ?></a><?php endif; ?></td><td><?= pl_e($item['due_date'] ?? '—') ?></td><td><?= pl_e($labels[$item['bucket']]) ?></td><td class="amount"><?= pl_e($item['currency'].' '.pl_money($item['remaining_fc'])) ?></td><td class="amount"><?= pl_e(pl_money($item['remaining_base'])) ?></td></tr><?php endforeach; ?>
<?php if ($report['items']===[]): ?><tr><td colspan="6"><?= pl_e(pl_t('No unpaid documents at this date.')) ?></td></tr><?php endif; ?>
</tbody></table></div></section>
<?php
// Unapplied credit is its own section (B59, design decision 9). Its strings go through pl_t()
// so the 1.2 Urdu work (M11) finds them already externalised.
$customerSide = $unapplied['side'] === 'customer';
?>
<section class="panel !m-0">
<div class="flex justify-between gap-3 mb-3"><h2 class="section-title"><?= pl_e(pl_t($customerSide ? '{count} customer advances (unapplied credit)' : '{count} supplier advances (prepayments)', ['count' => (int)$unapplied['count']])) ?></h2><strong class="amount"><?= pl_e(pl_t('Total {amount}', ['amount' => $company['currency'].' '.pl_money($unapplied['total_base'])])) ?></strong></div>
<p class="text-xs text-ink-muted mb-3"><?= pl_e(pl_t($customerSide
    ? 'Money received from customers and not yet applied to an invoice. It is held on the customer-advances control account, not inside receivables, so it is never netted against the balances above. Apply it to a document, or refund it.'
    : 'Money paid to suppliers before their bill arrived. It is held on the supplier-advances control account, not inside payables, so it is never netted against the balances above. Apply it to a document, or recover it.')) ?></p>
<?php pl_ui_strip(pl_t($unapplied['reconciled'] ? 'Unapplied credit reconciles to the advances control account.' : 'The advances control and the unapplied credit below differ. Review the reconciliation before relying on this section.'), $unapplied['reconciled']?'success':'warning'); ?>
<div class="table-wrap mt-3" tabindex="0" role="region" aria-label="<?= pl_e(pl_t('Unapplied credit; scroll horizontally on smaller screens')) ?>"><table class="table"><thead><tr><th><?= pl_e(pl_t('Customer / vendor')) ?></th><th><?= pl_e(pl_t('Reference')) ?></th><th><?= pl_e(pl_t('Received')) ?></th><th><?= pl_e(pl_t('Age (days)')) ?></th><th class="text-end"><?= pl_e(pl_t('Document balance')) ?></th><th class="text-end"><?= pl_e(pl_t('{currency} balance', ['currency' => $company['currency']])) ?></th></tr></thead><tbody>
<?php foreach ($unapplied['items'] as $item): ?><tr><th scope="row"><?= pl_e($item['legal_name']) ?></th><td><?= pl_e($item['number']) ?></td><td><?= pl_e($item['received_date'] ?? '—') ?></td><td><?= (int)$item['age_days'] ?></td><td class="amount"><?= pl_e($item['currency'].' '.pl_money($item['remaining_fc'])) ?></td><td class="amount"><?= pl_e(pl_money($item['remaining_base'])) ?></td></tr><?php endforeach; ?>
<?php if ($unapplied['items']===[]): ?><tr><td colspan="6"><?= pl_e(pl_t('No unapplied credit at this date.')) ?></td></tr><?php endif; ?>
</tbody></table></div>
<div class="table-wrap mt-3" tabindex="0" role="region" aria-label="<?= pl_e(pl_t('Advances control reconciliation')) ?>"><table class="table"><thead><tr><th><?= pl_e(pl_t('Advances control')) ?></th><th class="text-end"><?= pl_e(pl_t('Ledger')) ?></th><th class="text-end"><?= pl_e(pl_t('Unapplied credit')) ?></th><th class="text-end"><?= pl_e(pl_t('Difference')) ?></th></tr></thead><tbody>
<?php foreach ($unapplied['controls'] as $control): ?><tr><th scope="row"><?= pl_e($control['code'].' · '.$control['name']) ?></th><td class="amount"><?= pl_e(pl_money($control['ledger_base'])) ?></td><td class="amount"><?= pl_e(pl_money($control['unapplied_base'])) ?></td><td class="amount"><?= pl_e(pl_money($control['difference_base'])) ?></td></tr><?php endforeach; ?>
<?php if ($unapplied['controls']===[]): ?><tr><td colspan="4"><?= pl_e(pl_t($customerSide ? 'This book has no customer-advances control account yet.' : 'This book has no supplier-advances control account yet.')) ?></td></tr><?php endif; ?>
</tbody></table></div></section>
<details class="panel !m-0" <?= !$report['reconciled']?'open':'' ?>><summary class="section-title"><?= pl_e(pl_t('Control-account reconciliation')) ?></summary><div class="table-wrap mt-3" tabindex="0" role="region" aria-label="<?= pl_e(pl_t('Ageing control account reconciliation')) ?>"><table class="table"><thead><tr><th><?= pl_e(pl_t('Account')) ?></th><th class="text-end"><?= pl_e(pl_t('Ledger')) ?></th><th class="text-end"><?= pl_e(pl_t('Open items')) ?></th><th class="text-end"><?= pl_e(pl_t('Difference')) ?></th></tr></thead><tbody>
<?php foreach ($report['controls'] as $control): ?><tr><th scope="row"><?= pl_e($control['code'].' · '.$control['name']) ?></th><td class="amount"><?= pl_e(pl_money($control['ledger_base'])) ?></td><td class="amount"><?= pl_e(pl_money($control['open_items_base'])) ?></td><td class="amount"><?= pl_e(pl_money($control['difference_base'])) ?></td></tr><?php endforeach; ?>
</tbody></table></div></details>
</div>
