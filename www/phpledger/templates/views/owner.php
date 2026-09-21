<?php
declare(strict_types=1);
$canPost = pl_can_write($company) && !pl_demo_enabled();
$kinds = pl_owner_transaction_kinds();
$selected = pl_web_text($input, 'kind') ?: 'capital_introduced';
$ownerSide = ['capital_introduced' => 'capital', 'owner_loan_received' => 'loan', 'owner_loan_repaid' => 'loan', 'drawings' => 'drawings'];
$hasFailure = pl_web_text($form, 'message') !== '';
?>
<div class="flex flex-col gap-4 py-5">
<?php pl_ui_page_header(pl_t('Owner and partners'), pl_t('{company} · {currency} · As of {asOf}', ['company' => $company['name'], 'currency' => $company['currency'], 'asOf' => pl_date_label($asOf)]), static function (): void { ?>
<a class="btn btn-secondary" href="<?= pl_e(pl_url('/reports/balance-sheet')) ?>"><?= pl_icon('file-text') ?> <?= pl_e(pl_t('Balance sheet')) ?></a>
<?php }, 'owner-title'); ?>

<div class="grid grid-cols-2 md:grid-cols-4 gap-px overflow-hidden rounded-panel border border-border bg-border">
<?php foreach ([['total_capital',pl_t('Capital introduced'),'owner-capital'],['total_drawings',pl_t('Drawings'),'drawings'],['total_owner_loans',pl_t('Owner loans outstanding'),''],['net_owner_equity',pl_t('Capital less drawings'),'']] as [$key,$label,$concept]): ?>
<div class="bg-surface px-4 py-3"><div class="text-xs text-ink-muted"><?= pl_e($label) ?><?php if ($concept!==''): pl_ui_help($concept); endif; ?></div><p class="amount-lg mt-1"><?= pl_e(pl_money($movements[$key])) ?></p></div>
<?php endforeach; ?>
</div>
<p class="text-xs text-ink-muted"><?= pl_e(pl_t('Every figure here is read from posted journals.')) ?></p>

<?php if ($hasFailure): ?><div class="alert alert-danger" role="alert" tabindex="-1" data-form-error><strong><?= pl_e(pl_t('Nothing was posted.')) ?></strong><p><?= pl_e(pl_web_text($form, 'message')) ?></p><p><?= pl_e(pl_t('Your submitted values are retained.')) ?></p></div><?php endif; ?>

<?php if ($canPost): ?>
<section class="panel p-4 flex flex-col gap-3" aria-labelledby="owner-record"><h2 class="section-title" id="owner-record"><?= pl_e(pl_t('Record an owner transaction')) ?></h2>
<form method="post" action="<?= pl_e(pl_url('/owner/post')) ?>" class="flex flex-col gap-3">
<?= pl_csrf_field() ?><?= pl_scope_fields($company) ?>
<input type="hidden" name="creation_key" value="<?= pl_e(pl_web_text($input, 'creation_key')) ?>">
<div class="grid grid-cols-2 gap-3">
<label class="field"><?= pl_e(pl_t('What happened')) ?><select class="select" name="kind" required><?php foreach ($kinds as $value=>$kind): ?><option value="<?= pl_e($value) ?>" <?= $selected===$value?'selected':'' ?>><?= pl_e($kind['label']) ?></option><?php endforeach; ?></select><span class="field-hint"><?= pl_e($kinds[$selected]['help'] ?? '') ?></span></label>
<label class="field"><?= pl_e(pl_t('Date')) ?><input class="input" type="date" name="date" required value="<?= pl_e(pl_web_text($input, 'date') ?: $asOf) ?>"></label>
<label class="field"><?= pl_e(pl_t('Amount')) ?><input class="input" name="amount" inputmode="decimal" required value="<?= pl_e(pl_web_text($input, 'amount')) ?>"><span class="field-hint"><?= pl_e(pl_t('In {currency}, without a sign or separators.', ['currency' => $company['currency']])) ?></span></label>
<label class="field"><?= pl_e(pl_t('Cash or bank account')) ?><select class="select" name="cash_account_id" required><?php foreach ($accounts['cash'] as $row): ?><option value="<?= (int)$row['id'] ?>" <?= pl_web_text($input,'cash_account_id')===(string)$row['id']?'selected':'' ?>><?= pl_e($row['code'].' · '.$row['name']) ?></option><?php endforeach; ?></select></label>
<?php foreach (['capital'=>pl_t('Owner capital account'),'loan'=>pl_t('Owner loan account'),'drawings'=>pl_t('Drawings account')] as $side=>$label): ?>
<label class="field"><?= pl_e(pl_t('Which {label}', ['label' => strtolower($label)])) ?><select class="select" name="owner_account_<?= pl_e($side) ?>"><option value=""><?= pl_e(pl_t('Use the first one in the chart')) ?></option><?php foreach ($accounts[$side] as $row): ?><option value="<?= (int)$row['id'] ?>" <?= pl_web_text($input,'owner_account_'.$side)===(string)$row['id']?'selected':'' ?>><?= pl_e($row['code'].' · '.$row['name']) ?></option><?php endforeach; ?></select><span class="field-hint"><?= pl_e(pl_t('Used for {kinds}.', ['kinds' => implode(', ', array_keys($ownerSide, $side, true))])) ?></span></label>
<?php endforeach; ?>
<?php if ($partners): ?><label class="field col-span-2"><?= pl_e(pl_t('Partner (optional)')) ?><select class="select" name="partner_id"><option value=""><?= pl_e(pl_t('Not partner specific')) ?></option><?php foreach ($partners as $partner): ?><option value="<?= (int)$partner['id'] ?>" <?= pl_web_text($input,'partner_id')===(string)$partner['id']?'selected':'' ?>><?= pl_e($partner['name']) ?></option><?php endforeach; ?></select><span class="field-hint"><?= pl_e(pl_t('Choosing a partner uses that partner\'s own capital, drawings or loan account.')) ?></span></label><?php endif; ?>
</div>
<label class="field"><?= pl_e(pl_t('Description')) ?><input class="input" name="description" maxlength="500" value="<?= pl_e(pl_web_text($input, 'description')) ?>" placeholder="<?= pl_e(pl_t('What this was for')) ?>"></label>
<div class="panel-actions"><button class="btn btn-primary" type="submit" data-fold="primary action"><?= pl_e(pl_t('Post owner transaction')) ?></button></div>
<p class="field-hint"><?= pl_e(pl_t('This posts a balanced journal through the same posting service every other screen uses. Once posted it cannot be edited; a correction is a linked reversal.')) ?></p>
</form>
</section>
<?php else: ?>
<?php pl_ui_strip(pl_demo_enabled() ? pl_t('Owner transactions cannot be posted in the public demo. The sample history below shows what they look like.') : pl_t('Your role can review owner transactions but not post them.'), 'info'); ?>
<?php endif; ?>

<section class="flex flex-col gap-2" aria-labelledby="owner-history"><h2 class="section-title" id="owner-history"><?= pl_e(pl_t('Owner transaction history')) ?></h2>
<div class="table-wrap" tabindex="0" role="region" aria-label="<?= pl_e(pl_t('Owner transactions')) ?>"><table class="table"><caption class="sr-only"><?= pl_e(pl_t('Posted owner transactions in {currency}', ['currency' => $company['currency']])) ?></caption>
<thead><tr><th scope="col"><?= pl_e(pl_t('Date')) ?></th><th scope="col"><?= pl_e(pl_t('What happened')) ?></th><th scope="col"><?= pl_e(pl_t('Description')) ?></th><th scope="col" class="num"><?= pl_e(pl_t('Amount')) ?></th><th scope="col"><?= pl_e(pl_t('Status')) ?></th><th scope="col"></th></tr></thead><tbody>
<?php foreach ($transactions as $row): ?>
<tr><td><?= pl_e(pl_date_label($row['journal_date'])) ?></td><th scope="row"><?= pl_e($row['kind_label']) ?></th><td class="text-ink-muted"><?= pl_e($row['description']) ?></td><td class="num"><?= pl_e(pl_money($row['amount'])) ?></td><td><?php pl_ui_badge($row['status']); ?></td>
<td><a class="link" href="<?= pl_e(pl_url('/journals/detail',['id'=>$row['id']])) ?>"><?= pl_e(pl_t('Journal')) ?></a>
<?php if ($canPost && $row['status']==='posted'): ?>
<form method="post" action="<?= pl_e(pl_url('/owner/reverse')) ?>" class="inline"><?= pl_csrf_field() ?><?= pl_scope_fields($company) ?><input type="hidden" name="journal_id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="reason" value="<?= pl_e(pl_t('Owner transaction reversed from the Owner and partners screen.')) ?>"><button class="btn btn-ghost btn-sm" type="submit"><?= pl_e(pl_t('Reverse')) ?></button></form>
<?php endif; ?></td></tr>
<?php endforeach; ?>
<?php if ($transactions === []): ?><tr><td colspan="6"><?= pl_e(pl_t('No owner transactions have been recorded yet.')) ?></td></tr><?php endif; ?>
</tbody></table></div></section>

<section class="flex flex-col gap-2" aria-labelledby="owner-partners"><h2 class="section-title" id="owner-partners"><?= pl_e(pl_t('Partners and profit-sharing ratios')) ?></h2>
<div class="table-wrap" tabindex="0" role="region" aria-label="<?= pl_e(pl_t('Partners')) ?>"><table class="table"><caption class="sr-only"><?= pl_e(pl_t('Partner capital, drawings and profit-sharing ratios')) ?></caption>
<thead><tr><th scope="col"><?= pl_e(pl_t('Partner')) ?></th><th scope="col" class="num"><?= pl_e(pl_t('Profit share')) ?></th><th scope="col" class="num"><?= pl_e(pl_t('Capital')) ?></th><th scope="col" class="num"><?= pl_e(pl_t('Drawings')) ?></th><th scope="col" class="num"><?= pl_e(pl_t('Net capital')) ?></th><th scope="col" class="num"><?= pl_e(pl_t('Loan')) ?></th></tr></thead><tbody>
<?php foreach ($positions as $partner): ?>
<tr><th scope="row"><?= pl_e($partner['name']) ?></th><td class="num"><?= pl_e($partner['profit_share']) ?></td><td class="num"><?= pl_e(pl_money($partner['capital'])) ?></td><td class="num"><?= pl_e(pl_money($partner['drawings'])) ?></td><td class="num"><?= pl_e(pl_money($partner['net_capital'])) ?></td><td class="num"><?= pl_e(pl_money($partner['loan'])) ?></td></tr>
<?php endforeach; ?>
<?php if ($positions === []): ?><tr><td colspan="6"><?= pl_e(pl_t('No partners are recorded. A sole proprietor does not need one; an AOP or partnership records each partner and their agreed share.')) ?></td></tr><?php endif; ?>
</tbody></table></div>
<?php if ($positions && !$sharesComplete): ?><?php pl_ui_strip(pl_t('The recorded profit-sharing ratios do not yet add up to the whole profit. Record the remaining partners, or correct the ratios, before relying on them.'), 'warning'); ?><?php endif; ?>
<p class="text-xs text-ink-muted"><?= pl_e(pl_t('Profit-sharing ratios are recorded here and can never total more than 1 across the active partners. Allocating a period\'s profit between partners is not posted automatically: the treatment (salary and interest on capital before the residue, and the treatment of a loss) is an accounting policy decision that belongs to the accountant\'s review. See')) ?> <code>docs/accounting/OWNER-TRANSACTIONS.md</code>.</p>
</section>
</div>
