<?php declare(strict_types=1);
$columns = [['key' => 'debit', 'label' => pl_t('Debit')], ['key' => 'credit', 'label' => pl_t('Credit')]];
?>
<section class="flex flex-col gap-4 py-5" aria-labelledby="trial-title" style="--report-tree-columns:2">
<?php pl_ui_page_header(pl_t('Trial balance'),pl_t('{company} · {currency} · Through {asOf} · Posted entries only',['company'=>$company['name'],'currency'=>$company['currency'],'asOf'=>pl_date_label($asOf)]),static function () use ($asOf): void { ?>
<a class="btn btn-secondary" href="<?= pl_e(pl_url('/reports/export',['report'=>'trial-balance','to'=>$asOf])) ?>"><?= pl_icon('download') ?> <?= pl_e(pl_t('Export CSV')) ?></a>
<?php },'trial-title'); ?>
<form action="<?= pl_e(pl_url('/reports/trial-balance')) ?>" method="get" class="filter-bar">
<div class="field"><label class="field-label" for="trial-date"><?= pl_e(pl_t('Through date')) ?></label><input class="input" id="trial-date" name="as_of" type="date" required value="<?= pl_e($asOf) ?>"></div>
<div class="field"><label class="field-label" for="trial-depth"><?= pl_e(pl_t('Depth shown')) ?></label><select class="select" id="trial-depth" name="depth"><?php foreach (pl_report_depth_options() as $value=>$label): ?><option value="<?= (int)$value ?>" <?= $depth===$value?'selected':'' ?>><?= pl_e(pl_t($label)) ?></option><?php endforeach; ?></select></div>
<button class="btn btn-secondary" type="submit"><?= pl_e(pl_t('Update report')) ?></button><a class="btn btn-ghost" href="<?= pl_e(pl_url('/reports')) ?>"><?= pl_e(pl_t('All reports')) ?></a>
</form>
<?php if ($company['setup_status']!=='ready'): ?><p class="alert alert-info"><?= pl_e(pl_t('Opening review is outstanding. This report shows the records currently in these books; it is not confirmation that all opening balances or past documents are included.')) ?></p><?php endif; ?>
<?php if (!$report['balanced']): ?><div class="alert alert-danger" role="alert"><h2><?= pl_e(pl_t('The debit and credit totals do not agree')) ?></h2><p><?= pl_e(pl_t('Stop and ask the person responsible for these books to investigate before relying on this report.')) ?></p></div><?php endif; ?>
<?php pl_ui_report_tree($tree, $columns, ['caption' => pl_t('Trial balance through {asOf} in {currency}', ['asOf' => pl_date_label($asOf), 'currency' => $company['currency']]),
    'link' => static fn (int $id): string => pl_url('/reports/account', ['id' => $id, 'as_of' => $asOf, 'return_report' => 'trial-balance']),
    'empty' => pl_t('No accounts are available in this book.')]); ?>
<table class="table"><caption class="sr-only"><?= pl_e(pl_t('Trial balance totals')) ?></caption><tfoot><tr class="table-totals"><th scope="row"><?= pl_e(pl_t('Total')) ?><?php pl_ui_help('debit-and-credit'); ?></th><td class="num"><?= pl_e(pl_money($report['total_debit'])) ?></td><td class="num"><?= pl_e(pl_money($report['total_credit'])) ?></td></tr></tfoot></table>
<?php if ($report['balanced']): ?><p class="balanced-label"><?= pl_icon('check') ?> <?= pl_e(pl_t('Debit and credit totals agree')) ?></p><?php endif; ?>
<div class="text-xs text-ink-muted"><?= pl_e(pl_t('Each class and group adds up the accounts inside it; expand a class to see its groups and accounts, or print the page to keep exactly what you have expanded. Select an account to see its posted activity. Contra rows are shown as deductions inside their own section.')) ?><?php pl_ui_help('contra-account'); ?></div>
<p class="text-xs text-ink-muted"><?= pl_e(pl_t('Equal totals confirm that posted debits and credits balance. They do not by themselves establish that every transaction has been recorded or categorized correctly.')) ?></p>
</section>
