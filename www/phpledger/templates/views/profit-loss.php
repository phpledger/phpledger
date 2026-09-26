<?php declare(strict_types=1);
$columns = [['key' => 'amount', 'label' => $company['currency']]];
?>
<div class="flex flex-col gap-4 py-5 report-columns-one">
<?php pl_ui_page_header(pl_t('Profit & loss'), pl_t('{company} · {from} to {to} · Posted entries only', ['company' => $company['name'], 'from' => pl_date_label($from), 'to' => pl_date_label($to)]), static function () use ($from,$to): void { ?>
<a class="btn btn-secondary" href="<?= pl_e(pl_url('/reports/export',['report'=>'profit-loss','from'=>$from,'to'=>$to])) ?>"><?= pl_icon('download') ?> <?= pl_e(pl_t('Export CSV')) ?></a>
<?php }); ?>
<form method="get" action="<?= pl_e(pl_url('/reports/profit-loss')) ?>" class="filter-bar" data-report-period data-fold="primary action">
<div class="field"><label for="report-preset"><?= pl_e(pl_t('Period')) ?></label><select id="report-preset" class="select" name="preset"><?php foreach (['month'=>'This month','last_month'=>'Last month','quarter'=>'This quarter','year'=>'This year','custom'=>'Custom'] as $value=>$label): $dates=pl_report_period($value,gmdate('Y-m-d')); ?><option value="<?= pl_e($value) ?>" <?= $preset===$value?'selected':'' ?> <?= $dates?'data-from="'.pl_e($dates['from']).'" data-to="'.pl_e($dates['to']).'"':'' ?>><?= pl_e(pl_t($label)) ?></option><?php endforeach; ?></select></div>
<label class="field"><?= pl_e(pl_t('From')) ?><input class="input" type="date" name="from" value="<?= pl_e($from) ?>" required></label><label class="field"><?= pl_e(pl_t('To')) ?><input class="input" type="date" name="to" value="<?= pl_e($to) ?>" required></label>
<div class="field"><label for="pl-depth"><?= pl_e(pl_t('Depth shown')) ?></label><select id="pl-depth" class="select" name="depth"><?php foreach (pl_report_depth_options() as $value=>$label): ?><option value="<?= (int)$value ?>" <?= $depth===$value?'selected':'' ?>><?= pl_e($label) ?></option><?php endforeach; ?></select></div>
<button class="btn btn-primary"><?= pl_e(pl_t('Update')) ?></button><a class="btn btn-ghost" href="<?= pl_e(pl_url('/reports')) ?>"><?= pl_e(pl_t('All reports')) ?></a>
</form>
<p class="text-xs text-ink-muted"><?= pl_e(pl_t('Presets use calendar periods through today; last month covers the full month. Choose Custom for your own dates.')) ?></p>
<div class="flex flex-col gap-3 max-w-[60rem]">
<?php foreach (['income'=>'Income','cost_of_sales'=>'Cost of sales','expenses'=>'Expenses'] as $key=>$label): ?>
<section class="flex flex-col gap-2" aria-labelledby="pl-<?= pl_e($key) ?>"><h2 class="section-title" id="pl-<?= pl_e($key) ?>"><?= pl_e(pl_t($label)) ?></h2>
<?php pl_ui_report_tree($trees[$key], $columns, ['caption' => pl_t('{section} in {currency}', ['section' => pl_t($label), 'currency' => $company['currency']]), 'sections' => true,
    'link' => static fn (int $id): string => pl_url('/reports/account', ['id'=>$id,'from'=>$from,'as_of'=>$to,'return_report'=>'profit-loss','return_preset'=>$preset]),
    'empty' => $key==='cost_of_sales' ? pl_t('No accounts classified as cost of sales. Existing expenses stay in Expenses until explicitly classified.') : pl_t('No {section} accounts carry a balance in this period.', ['section' => strtolower(pl_t($label))])]); ?>
<table class="stmt-table"><caption class="sr-only"><?= pl_e(pl_t('Total {section}', ['section' => strtolower(pl_t($label))])) ?></caption><tbody><tr class="stmt-row stmt-subtotal"><th scope="row"><?= pl_e(pl_t('Total {section}', ['section' => strtolower(pl_t($label))])) ?></th><td class="num"><?= pl_e(pl_money($report['total_'.$key])) ?></td></tr>
<?php if ($key==='cost_of_sales'): ?><tr class="stmt-row stmt-total"><th scope="row"><?= pl_e(pl_t('Gross profit')) ?></th><td class="num"><?= pl_e(pl_money($report['gross_profit'])) ?></td></tr><?php endif; ?>
</tbody></table></section>
<?php endforeach; ?>
<table class="stmt-table"><caption class="sr-only"><?= pl_e(pl_t('Net result')) ?></caption><tbody><tr class="stmt-row stmt-total"><th scope="row"><?= pl_e(pl_t('Net profit / loss')) ?></th><td class="num"><?= pl_e(pl_money($report['net_profit'])) ?></td></tr></tbody></table>
</div>
<p class="text-xs text-ink-muted"><?= pl_e(pl_t('Sales returns and discounts allowed are shown as deductions inside Income, and purchase returns and discounts received as deductions inside their expense section; they are never moved to the opposite side. Includes posted entries and dated reversals. Account links retain this date range. Cost-of-sales classification changes presentation across all periods; it does not change posted entries or net profit.')) ?></p>
</div>
