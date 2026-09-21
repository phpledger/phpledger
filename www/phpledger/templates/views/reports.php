<?php
declare(strict_types=1);
$hasOverview = isset($overview) && is_array($overview);
$groups = [
    'Financial statements' => [
        ['/reports/balance-sheet','Balance sheet','Assets, liabilities and the equity behind your business.','building'],
        ['/reports/profit-loss','Profit & loss','Income, cost of sales and expenses, with gross and net profit for a period.','file-text'],
        ['/reports/trial-balance','Trial balance','Every account’s debit and credit balance, and whether they agree.','book'],
    ],
    'Ledgers' => [
        ['/reports/account','Account statement','Opening balance, every debit and credit, and the running balance for one account.','book'],
        ['/accounts','Chart of accounts','Account classifications, operational roles and active status.','list'],
    ],
    'Receivables & payables' => [
        ['/ar','Invoices','Follow customer invoices from draft to paid, and see what is overdue.','file-text'],
        ['/ap','Bills','Track supplier bills and when they are due.','file-text'],
        ['/reports/ageing','Receivables & payables ageing','Current, 1–30, 31–60, 61–90 and over-90-day balances, reconciled to control accounts.','calendar'],
    ],
    'Stock' => [
        ['/reports/stock-by-location','Stock by location','Items and value in every warehouse and van, each subtotalled, with the aggregate across locations.','list'],
        ['/stock-documents','Stock issues & returns','Numbered stock issues, re-issues, returns from a van and gate passes.','file-text'],
        ['/stock-documents/settlement','Van settlement','One driver’s day: loaded, sold, returned, and whether it reconciles.','calendar'],
    ],
    'Fixed assets' => [
        ['/reports/asset-register','Fixed-asset register','Cost, accumulated depreciation and net book value per class, reconciled to the control accounts.','book'],
        ['/fixed-assets','Asset register','What was bought, when it entered service, what it has been written down by and what happened when it left.','list'],
        ['/fixed-assets/depreciation','Depreciation run','One reviewed entry per accounting period, per asset class.','calendar'],
    ],
    'Cash' => [
        ['/reports/cash-forecast','Cash forecast','Explore cash needs using your own expected money in and out. Scenario only; no posting.','arrow-right'],
        ['/bank-reconciliation','Bank reconciliation','Compare a bank statement with your posted entries.','building'],
    ],
];
?>
<div class="flex flex-col gap-6 py-5">
<?php pl_ui_page_header(pl_t('Reports'),$hasOverview?pl_t('Your business in numbers · {company} · Through {date}',['company'=>$company['name'],'date'=>pl_date_label($overview['as_of'])]):pl_t('Your business in numbers · {company}',['company'=>$company['name']]),static function () use ($company): void { if (pl_can_write($company)): ?>
<a class="btn btn-primary" href="<?= pl_e(pl_url('/transactions/new')) ?>"><?= pl_icon('plus') ?> <?= pl_e(pl_t('New transaction')) ?></a>
<?php endif; }); ?>
<?php if ($hasOverview): ?>
<div class="grid grid-cols-2 md:grid-cols-4 gap-px overflow-hidden rounded-panel border border-border bg-border" aria-label="<?= pl_e(pl_t('Posted business balances')) ?>">
<?php foreach (['cash'=>['Cash & bank','/reports/balance-sheet'],'income'=>['Income YTD','/reports/profit-loss'],'expenses'=>['Costs & expenses YTD','/reports/profit-loss'],'profit'=>['Net profit / loss YTD','/reports/profit-loss']] as $key=>[$label,$path]): ?>
<a class="bg-surface px-4 py-3 hover:bg-surface-subtle" href="<?= pl_e(pl_url($path,$key==='cash'?['as_of'=>$overview['as_of']]:['preset'=>'custom','from'=>$overview['period_from'],'to'=>$overview['as_of']])) ?>"><span class="text-xs text-ink-muted"><?= pl_e(pl_t($label)) ?></span><p class="amount-lg mt-1"><?= pl_e(pl_money($overview[$key])) ?></p></a>
<?php endforeach; ?></div>
<p class="text-xs text-ink-muted"><?= pl_e(pl_t('Amounts in {currency} · Posted entries only, {from} – {to}. Cash and bank is the balance through that date.',['currency'=>$company['currency'],'from'=>pl_date_label($overview['period_from']),'to'=>pl_date_label($overview['as_of'])])) ?></p>
<?php endif; ?>
<?php foreach ($groups as $heading=>$links): ?><section><h2 class="section-title mb-2"><?= pl_e(pl_t($heading)) ?></h2><div class="rounded-panel border border-border bg-surface p-2"><ul class="flex flex-col">
<?php foreach ($links as [$path,$label,$description,$icon]): ?><li><a class="flex items-center gap-3 rounded-control px-3 py-2.5 hover:bg-surface-subtle" href="<?= pl_e(pl_url($path)) ?>"><span class="inline-flex size-8 flex-none items-center justify-center rounded-full bg-surface-subtle text-ink-muted"><?= pl_icon($icon) ?></span><span class="min-w-0 flex-1"><span class="block text-sm font-semibold text-ink"><?= pl_e(pl_t($label)) ?></span><span class="block text-xs text-ink-muted"><?= pl_e(pl_t($description)) ?></span></span><?= pl_icon('chevron-right') ?></a></li><?php endforeach; ?>
</ul></div></section><?php endforeach; ?>
<p class="text-xs text-ink-muted"><?= pl_e(pl_t('Financial statements exclude drafts. Cash forecasts are scenarios based on your assumptions. Country-neutral statements are not statutory filing reports.')) ?></p>
</div>
