<?php declare(strict_types=1);
$columns = [['key' => 'amount', 'label' => $company['currency']]];
$movements = $report['equity_movements'];
?>
<div class="flex flex-col gap-4 py-5 report-columns-one">
<?php pl_ui_page_header(pl_t('Balance sheet'),pl_t('{company} · As of {date} · Posted entries only', ['company' => $company['name'], 'date' => pl_date_label($asOf)]),static function () use ($asOf): void { ?>
<a class="btn btn-secondary" href="<?= pl_e(pl_url('/reports/export',['report'=>'balance-sheet','to'=>$asOf])) ?>"><?= pl_icon('download') ?> <?= pl_e(pl_t('Export CSV')) ?></a>
<?php }); ?>
<div class="grid grid-cols-2 md:grid-cols-4 gap-px overflow-hidden rounded-panel border border-border bg-border">
<?php foreach (['total_assets'=>'Total assets','total_liabilities'=>'Total liabilities','total_equity'=>'Equity including earned profit'] as $key=>$label): ?><div class="bg-surface px-4 py-3"><p class="text-xs text-ink-muted"><?= pl_e(pl_t($label)) ?></p><p class="amount-lg mt-1"><?= pl_e(pl_money($report[$key])) ?></p></div><?php endforeach; ?>
<div class="flex flex-col justify-center gap-1 bg-surface px-4 py-3"><strong class="<?= $report['balanced']?'balanced-label':'text-danger' ?>"><?= pl_e($report['balanced']?pl_t('Assets = liabilities + equity'):pl_t('This statement needs review')) ?></strong><span class="text-xs tabular-nums text-ink-muted"><?= pl_e(pl_money($report['total_assets'])) ?> <?= $report['balanced']?'=':'≠' ?> <?= pl_e(pl_money($report['total_liabilities_equity']).' '.$company['currency']) ?></span></div></div>
<form method="get" action="<?= pl_e(pl_url('/reports/balance-sheet')) ?>" class="filter-bar"><label class="field"><?= pl_e(pl_t('As of date')) ?><input class="input" type="date" name="as_of" value="<?= pl_e($asOf) ?>" required></label>
<div class="field"><label for="bs-depth"><?= pl_e(pl_t('Depth shown')) ?></label><select id="bs-depth" class="select" name="depth"><?php foreach (pl_report_depth_options() as $value=>$label): ?><option value="<?= (int)$value ?>" <?= $depth===$value?'selected':'' ?>><?= pl_e($label) ?></option><?php endforeach; ?></select></div>
<button class="btn btn-secondary"><?= pl_e(pl_t('Update')) ?></button><a class="btn btn-ghost" href="<?= pl_e(pl_url('/reports')) ?>"><?= pl_e(pl_t('All reports')) ?></a></form>
<div class="flex flex-col gap-3 max-w-[60rem]">
<?php foreach (['assets'=>'Assets','liabilities'=>'Liabilities','equity'=>'Equity'] as $key=>$label): ?>
<section class="flex flex-col gap-2" aria-labelledby="bs-<?= pl_e($key) ?>"><h2 class="section-title" id="bs-<?= pl_e($key) ?>"><?= pl_e(pl_t($label)) ?></h2>
<?php pl_ui_report_tree($trees[$key], $columns, ['caption' => pl_t('{section} in {currency}', ['section' => pl_t($label), 'currency' => $company['currency']]), 'sections' => true,
    'link' => static fn (int $id): string => pl_url('/reports/account', ['id'=>$id,'as_of'=>$asOf,'return_report'=>'balance-sheet']),
    'empty' => pl_t('No {section} accounts carry a balance at this date.', ['section' => strtolower(pl_t($label))])]); ?>
<table class="stmt-table"><caption class="sr-only"><?= pl_e(pl_t('Total {section}', ['section' => strtolower(pl_t($label))])) ?></caption><tbody>
<?php if ($key==='equity'): ?>
<?php if (bccomp((string) $movements['total_owner_loans'], '0', 4) !== 0): ?><tr class="stmt-row stmt-indent-1"><th scope="row"><?= pl_e(pl_t('Owner loans outstanding')) ?><span class="block text-xs font-normal text-ink-muted"><?= pl_e(pl_t('A liability of the business, shown here for the owner\'s own view')) ?></span></th><td class="num"><?= pl_e(pl_money($movements['total_owner_loans'])) ?></td></tr>
<?php endif; ?>
<tr class="stmt-row stmt-indent-1"><th scope="row"><?= pl_e(pl_t('Earned profit to date')) ?><span class="block text-xs font-normal text-ink-muted"><?= pl_e(pl_t('Unclosed income less expenses')) ?></span></th><td class="num"><?= pl_e(pl_money($report['earned_profit'])) ?></td></tr>
<?php endif; ?>
<tr class="stmt-row <?= $key==='equity'?'stmt-total':'stmt-subtotal' ?>"><th scope="row"><?= pl_e(pl_t('Total {section}', ['section' => strtolower(pl_t($label))])) ?></th><td class="num"><?= pl_e(pl_money($report['total_'.$key])) ?></td></tr>
</tbody></table></section>
<?php endforeach; ?>
</div>
<p class="text-xs text-ink-muted"><?= pl_e(pl_t('Amounts in {currency} · Posted entries only. Capital introduced, drawings and the owner\'s loan come from the posted owner transactions; drawings are a deduction inside equity, and an owner\'s loan stays a liability because the business owes it back. Earned profit is shown separately from recorded equity. Account classification and completeness still need review; balanced totals alone do not establish correct books.', ['currency' => $company['currency']])) ?></p>
</div>
