<?php
declare(strict_types=1);
$homeVisibility = pl_company_visibility((int) $user['id'], (int) $company['id']);
$attention = [];
if ($company['setup_status'] !== 'ready') {
    $attention[] = [$company['setup_status'] === 'opening_required' ? '/opening-balances' : '/setup/review', pl_t('Review business setup'), pl_t('Complete the required review before posting.'), 'building-cog'];
}
if ($overview['drafts']['total'] > 0) {
    $attention[] = ['/transactions?status=draft', pl_t('{count} receipt and expense drafts', ['count' => $overview['drafts']['total']]), pl_t('{amount} total · Review before posting', ['amount' => $company['currency'] . ' ' . pl_money($overview['drafts']['total_amount'])]), 'receipt-2'];
}
if ($overview['journal_drafts'] > 0) { $attention[] = ['/general-journals?status=draft', pl_t('{count} journal drafts', ['count' => $overview['journal_drafts']]), pl_t('A saved draft does not change your books.'), 'book-2']; }
if ($overview['ar_drafts'] > 0 && $homeVisibility['show_ar']) { $attention[] = ['/ar', pl_t('{count} sales drafts', ['count' => $overview['ar_drafts']]), pl_t('Review invoices and customer credits before posting.'), 'file-invoice']; }
if ($overview['ap_drafts'] > 0 && $homeVisibility['show_ap']) { $attention[] = ['/ap', pl_t('{count} purchase drafts', ['count' => $overview['ap_drafts']]), pl_t('Review bills and supplier credits before posting.'), 'file-dollar']; }
if ($overview['overdue_invoices'] !== [] && $homeVisibility['show_ar']) {
    $attention[] = ['/ar?as_of=' . $overview['as_of'], pl_t('{count} overdue receivables', ['count' => count($overview['overdue_invoices'])]), pl_t('{amount} overdue', ['amount' => $company['currency'] . ' ' . pl_money($overview['receivables']['overdue_base'])]), 'file-invoice'];
}
if ($overview['bills_due'] !== [] && $homeVisibility['show_ap']) {
    $attention[] = ['/ap?as_of=' . $overview['as_of'], pl_t('{count} bills due or overdue', ['count' => count($overview['bills_due'])]), pl_t('Due today, overdue, or due within three days.'), 'file-dollar'];
}
if ($overview['bank_lines'] > 0 && !pl_demo_enabled()) { $attention[] = ['/bank-reconciliation', pl_t('{count} bank lines to review', ['count' => $overview['bank_lines']]), pl_t('Unmatched rows in draft statements.'), 'building-bank']; }
// The first Home's guide (owner review of 25 September 2026, frame H-1 in docs/design/setup-1.4.5):
// six steps read from the data, gone by itself when the last one is done. Expense and Bill wait
// until some bank or cash account holds money; the book's strict cash policy is what refuses an
// expense from an empty account, this only says so before the click.
$guide = $overview['getting_started'];
$moneyIn = $guide['money_in'];
$subtitle = pl_t('{company} · {date}', ['company' => $company['name'], 'date' => pl_date_label($overview['as_of'])]);
if ($guide['legal_form_label'] !== '') {
    $subtitle .= ' · ' . $guide['legal_form_label'] . ($guide['country'] !== '' ? ', ' . $guide['country'] : '');
}
$more = static fn (int $count): string => $count > 3 ? ' ' . pl_t('and {count} more', ['count' => $count - 3]) : '';
$stepCopy = static function (array $step) use ($more): array {
    $facts = $step['facts'];
    switch ($step['id']) {
        case 'profile':
            $parts = array_filter([$facts['legal_name'], $facts['registration_number'], $facts['tax_registrations']]);
            return [pl_t('Invoice details'), $step['done'] ? implode(' · ', $parts) : pl_t('Legal name, {number} and the tax numbers printed on invoices.', ['number' => pl_t((string) $facts['labels']['reg_number'])]), '/company-profile', pl_t('Company profile')];
        case 'owners':
            $hint = pl_legal_form_has_partners($facts['family']) ? pl_t('Register each partner; the profit-sharing ratio goes with them.')
                : (pl_legal_form_has_shares($facts['family']) ? pl_t('Register the shareholders; the share ledger records their shares.') : pl_t('Register the owner, so capital and drawings have a name.'));
            return [pl_t('Owners registered'), $step['done'] ? implode(', ', $facts['names']) . $more($facts['count']) : $hint, '/ownership', pl_t('Owners and shares')];
        case 'money_accounts':
            return [pl_t('Bank and cash accounts'), $step['done'] ? implode(', ', $facts['names']) . $more($facts['count']) : pl_t('Name each bank account, till and petty-cash float. Nothing posts to the group above them.'), '/accounts', pl_t('Accounts')];
        case 'money_in':
            return [pl_t('Put money in'), $step['done'] ? pl_t('An account holds a balance, so expenses and bills are open.') : pl_t('Every account is at zero. Record what the owners put in as capital, or lend to the business.'), '/owner', pl_t('Record capital or an owner loan')];
        case 'parties':
            return [pl_t('Add customers and suppliers'), $step['done'] ? pl_t('{customers} customers · {suppliers} suppliers', ['customers' => $facts['customers'], 'suppliers' => $facts['suppliers']]) : pl_t('Needed before the first invoice or bill.'), '/parties', pl_t('Customers and suppliers')];
        default:
            return [pl_t('Record your first sale or expense'), $step['done'] ? pl_tn('{count} posting so far', '{count} postings so far', (int) $facts['count'], ['count' => $facts['count']]) : pl_t('Receipts and invoices work now. Expenses and bills open once an account has money in it.'), '/receipts/new', pl_t('New receipt')];
    }
};
?>
<div class="flex flex-col gap-5 py-5">
<?php pl_ui_page_header(pl_t('Home'), $subtitle, static function () use ($company): void {
    if (pl_can_write($company)): ?><a class="btn btn-primary" href="<?= pl_e(pl_url('/transactions/new')) ?>"><?= pl_icon('plus') ?> <?= pl_e(pl_t('New transaction')) ?></a><?php endif;
}); ?>
<?php if (!$guide['complete'] && pl_can_write($company)): ?>
<section class="getting-started" aria-labelledby="gs-title" data-fold="getting started">
    <div class="gs-head"><h2 id="gs-title"><?= pl_e(pl_t('Getting started')) ?></h2><p><?= pl_e(pl_t('{done} of {total} done · this guide goes away by itself when the last step is complete', ['done' => $guide['done'], 'total' => $guide['total']])) ?></p></div>
    <ol class="gs-steps">
    <?php foreach ($guide['steps'] as $index => $step): $state = $step['done'] ? 'done' : ($step['id'] === $guide['current'] ? 'current' : 'pending'); [$title, $detail, $href, $action] = $stepCopy($step); ?>
        <li class="gs-step is-<?= $state ?>"><span class="gs-num" aria-hidden="true"><?= $step['done'] ? pl_icon('check') : $index + 1 ?></span><span class="gs-body"><span class="gs-title"><?= pl_e($title) ?></span><span class="gs-detail"><?= pl_e($detail) ?><?php if ($state !== 'current'): ?> <a class="link" href="<?= pl_e(pl_url($href)) ?>"><?= pl_e($action) ?></a><?php endif; ?></span>
            <?php if ($state === 'current'): ?><span class="gs-actions"><a class="btn btn-primary btn-sm" href="<?= pl_e(pl_url($href)) ?>"><?= pl_e($action) ?></a><?php if ($step['id'] === 'money_in'): ?><a class="link" href="<?= pl_e(pl_url('/bank-reconciliation')) ?>"><?= pl_e(pl_t('Import a bank statement instead')) ?></a><?php endif; ?></span><?php endif; ?></span></li>
    <?php endforeach; ?>
    </ol>
</section>
<?php endif; ?>
<?php if (pl_can_write($company)): ?>
<nav class="quick-actions" aria-label="<?= pl_e(pl_t('Quick actions')) ?>" data-fold="quick actions">
<?php foreach ([['/receipts/new','Receipt','receipt',true,false],['/ar?new=1','Invoice','file-invoice',$homeVisibility['show_ar'],false],['/general-journals/new','Journal','book-2',true,false],['/expenses/new','Expense','receipt-2',true,!$moneyIn],['/ap?new=1','Bill','file-dollar',$homeVisibility['show_ap'],!$moneyIn]] as [$href,$label,$icon,$visible,$waiting]): if (!$visible) { continue; } ?>
<a class="btn btn-secondary btn-sm<?= $waiting ? ' is-waiting' : '' ?>" href="<?= pl_e(pl_url($href)) ?>"<?= $waiting ? ' aria-describedby="quick-note"' : '' ?>><?= pl_icon($icon) ?><?= pl_e(pl_t($label)) ?></a><?php endforeach; ?>
<?php if (!$moneyIn): ?><span class="quick-note" id="quick-note"><?= pl_e(pl_t('Expense and Bill unlock when a bank or cash account has a balance.')) ?></span><?php endif; ?>
</nav>
<?php endif; ?>
<section aria-labelledby="home-attention" data-fold="needs attention">
    <div class="flex items-center gap-2 mb-3"><h2 class="section-title" id="home-attention"><?= pl_e(pl_t('Needs attention')) ?></h2><?php pl_ui_badge('info', pl_t('{count} categories', ['count' => count($attention)])); ?></div>
    <?php if ($attention === []): pl_ui_empty(pl_t('Nothing needs your attention here'), pl_t('Review your reports or start a new transaction when you are ready.')); else: ?>
    <div class="panel p-2 max-h-64 overflow-y-auto" tabindex="0" role="region" aria-label="<?= pl_e(pl_t('Items needing attention')) ?>"><ul class="flex flex-col">
    <?php foreach ($attention as [$href,$label,$description,$icon]): ?><li><a class="flex items-center gap-3 rounded-control px-3 py-2 hover:bg-surface-subtle no-underline" href="<?= pl_e(pl_url($href)) ?>"><?= pl_icon($icon) ?><span class="min-w-0 flex-1"><span class="block text-sm font-semibold text-ink"><?= pl_e($label) ?></span><span class="block text-xs text-ink-muted"><?= pl_e($description) ?></span></span><?= pl_icon('chevron-right') ?></a></li><?php endforeach; ?>
    </ul></div><?php endif; ?>
</section>
<section aria-labelledby="home-balances">
    <h2 class="section-title mb-3" id="home-balances"><?= pl_e(pl_t('Cash & bank, and what\'s due')) ?></h2>
    <div class="grid grid-cols-1 gap-4 min-[900px]:grid-cols-3">
        <div class="panel" data-fold="cash balance"><h3 class="text-sm font-medium text-ink-muted"><?= pl_e(pl_t('Cash & bank')) ?></h3><p class="amount-lg text-brand"><?= pl_e($overview['currency'] . ' ' . pl_money($overview['cash'])) ?></p><p class="text-xs text-ink-muted"><?= pl_e(pl_t('Posted balance')) ?></p><?php $cashAccounts = $overview['cash_accounts']; if (count($cashAccounts) > 1 || ($cashAccounts !== [] && $cashAccounts[0]['name'] !== 'Cash and bank')): ?><ul class="balance-accounts"><?php foreach (array_slice($cashAccounts, 0, 5) as $account): ?><li><span><?= pl_e($account['name']) ?></span><span class="num"><?= pl_e($overview['currency'] . ' ' . pl_money($account['balance'])) ?></span></li><?php endforeach; ?><?php if (count($cashAccounts) > 5): ?><li><span class="text-ink-faint"><?= pl_e(pl_t('and {count} more', ['count' => count($cashAccounts) - 5])) ?></span></li><?php endif; ?></ul><?php endif; ?><a class="link text-xs" href="<?= pl_e(pl_url('/reports/balance-sheet', ['as_of'=>$overview['as_of']])) ?>"><?= pl_e(pl_t('See your financial position')) ?></a></div>
        <?php foreach (['receivables'=>['Receivable','/ar','Review invoices'],'payables'=>['Payable','/ap','Review bills']] as $key=>[$label,$href,$link]): ?>
        <div class="panel"><h3 class="text-sm font-medium text-ink-muted"><?= pl_e(pl_t($label)) ?></h3><p class="amount-lg"><?= pl_e($overview['currency'] . ' ' . pl_money($overview[$key]['total_base'])) ?></p><p class="text-xs text-ink-muted"><?= pl_e(pl_t('{amount} overdue', ['amount' => $overview['currency'] . ' ' . pl_money($overview[$key]['overdue_base'])])) ?></p><?php if ($homeVisibility[$key === 'receivables' ? 'show_ar' : 'show_ap']): ?><a class="link text-xs" href="<?= pl_e(pl_url($href, ['as_of'=>$overview['as_of']])) ?>"><?= pl_e(pl_t($link)) ?></a><?php else: ?><a class="link text-xs" href="<?= pl_e(pl_url('/reports/trial-balance', ['as_of'=>$overview['as_of']])) ?>"><?= pl_e(pl_t('Review accounts')) ?></a><?php endif; ?>
        <?php if (!$overview[$key]['reconciled']): ?><p class="text-xs text-warning mt-2"><?= pl_e(pl_t('Open documents differ from the control account.')) ?> <a href="<?= pl_e(pl_url('/opening-conversion')) ?>"><?= pl_e(pl_t('Review opening documents')) ?></a> <?= pl_e(pl_t('and reconciliation before relying on this total.')) ?></p><?php endif; ?>
        </div><?php endforeach; ?>
    </div>
</section>
<section aria-labelledby="home-recent"><h2 class="section-title mb-3" id="home-recent"><?= pl_e(pl_t('Recent activity')) ?></h2>
<?php if ($overview['recent'] === []): pl_ui_empty(pl_t('No activity yet'), pl_t('Saved documents and journals will appear here.')); else:
pl_ui_table([pl_t('Date / reference'),pl_t('Description'),pl_t('Amount'),pl_t('Status')], static function () use ($overview): void {
foreach ($overview['recent'] as $row): ?><tr><td><a href="<?= pl_e(pl_url($row['path'], ['id'=>$row['id']])) ?>"><?= pl_e(pl_date_label($row['date'])) ?></a><span class="row-secondary"><?= pl_e($row['number']) ?></span></td><td><?= pl_e($row['description']) ?><span class="row-secondary"><?= pl_e($row['kind']) ?></span></td><td class="amount"><?= pl_e($row['currency'] . ' ' . pl_money($row['amount'])) ?></td><td><?php pl_ui_badge($row['status']); ?></td></tr><?php endforeach;
}, pl_t('Recent documents and journals')); endif; ?>
</section>
</div>
