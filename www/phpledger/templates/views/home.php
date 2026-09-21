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
?>
<div class="flex flex-col gap-5 py-5">
<?php pl_ui_page_header(pl_t('Home'), pl_t('{company} · {date}', ['company' => $company['name'], 'date' => pl_date_label($overview['as_of'])]), static function () use ($company): void {
    if (pl_can_write($company)): ?><a class="btn btn-primary" href="<?= pl_e(pl_url('/transactions/new')) ?>"><?= pl_icon('plus') ?> <?= pl_e(pl_t('New transaction')) ?></a><?php endif;
}); ?>
<?php if (pl_can_write($company)): ?>
<nav class="flex flex-wrap items-center gap-2" aria-label="<?= pl_e(pl_t('Quick actions')) ?>" data-fold="quick actions">
<?php foreach ([['/transactions/new?kind=expense','Expense','receipt-2',true],['/transactions/new?kind=receipt','Receipt','receipt',true],['/ar?new=1','Invoice','file-invoice',$homeVisibility['show_ar']],['/ap?new=1','Bill','file-dollar',$homeVisibility['show_ap']],['/general-journals/new','Journal','book-2',true]] as [$href,$label,$icon,$visible]): if (!$visible) { continue; } ?>
<a class="btn btn-secondary btn-sm" href="<?= pl_e(pl_url($href)) ?>"><?= pl_icon($icon) ?><?= pl_e(pl_t($label)) ?></a><?php endforeach; ?>
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
        <div class="panel" data-fold="cash balance"><h3 class="text-sm font-medium text-ink-muted"><?= pl_e(pl_t('Cash & bank')) ?></h3><p class="amount-lg text-brand"><?= pl_e($overview['currency'] . ' ' . pl_money($overview['cash'])) ?></p><p class="text-xs text-ink-muted"><?= pl_e(pl_t('Posted balance')) ?></p><a class="link text-xs" href="<?= pl_e(pl_url('/reports/balance-sheet', ['as_of'=>$overview['as_of']])) ?>"><?= pl_e(pl_t('See your financial position')) ?></a></div>
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
