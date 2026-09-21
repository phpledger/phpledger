<?php declare(strict_types=1);
/*
 * The depreciation run (1.3 M14, issue #95). Choose a period, see exactly what would be
 * posted asset by asset, then post it. Running a period twice posts nothing the second
 * time; running it again after a correction posts the difference, never a duplicate.
 */
$currency = (string) $company['currency'];
$periodOptions = [];
foreach ($periods as $period) {
    $periodOptions[(string) $period['id']] = pl_date_label((string) $period['start_date']) . ' – ' . pl_date_label((string) $period['end_date'])
        . ($period['status'] === 'open' ? '' : ' · ' . pl_t('closed'));
}
?>
<div class="flex flex-col gap-6 py-5">
<?php pl_ui_page_header(pl_t('Depreciation run'),
    pl_t('{company} · {currency} · One reviewed entry per accounting period', ['company' => $company['name'], 'currency' => $currency]),
    static function (): void { ?>
<a class="btn btn-ghost" href="<?= pl_e(pl_url('/fixed-assets')) ?>"><?= pl_e(pl_t('Fixed assets')) ?></a>
<a class="btn btn-secondary" href="<?= pl_e(pl_url('/reports/asset-register')) ?>"><?= pl_e(pl_t('Register report')) ?></a>
<?php }); ?>

<?php if ($form['message'] !== ''): ?>
<div class="alert alert-danger" role="alert" tabindex="-1" data-form-error><?= pl_e($form['message']) ?></div>
<?php endif; ?>

<?php if (!$enabled): ?>
<?php pl_ui_strip(pl_t('Fixed assets is switched off for this company, so no depreciation can be posted. Runs already posted stay listed below. An owner can switch the module on in Modules.'), 'warning'); ?>
<?php endif; ?>

<form method="get" action="<?= pl_e(pl_url('/fixed-assets/depreciation')) ?>" class="rounded-panel border border-border bg-surface p-4 grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
<?php pl_starter_select(pl_t('Accounting period'), 'period', $periodOptions, $periodId ?: ''); ?>
<div><button class="btn btn-secondary"><?= pl_e(pl_t('Show what would be posted')) ?></button></div>
</form>

<?php if ($preview !== null): ?>
<section class="flex flex-col gap-4">
<h2 class="section-title"><?= pl_e(pl_t('Period ended {date}', ['date' => pl_date_label((string) $preview['period']['end_date'])])) ?></h2>
<?php if ($preview['period']['status'] !== 'open'): ?>
<?php pl_ui_strip(pl_t('This period is closed, so nothing can be posted into it. Reopen it in Periods first, or run a later period instead.'), 'warning'); ?>
<?php endif; ?>
<?php if ($preview['lines'] === []): ?>
<?php pl_ui_empty(pl_t('Nothing to post'), pl_t('Every asset is already depreciated for this period, or none of them was in service during it.')); ?>
<?php else: ?>
<?php pl_ui_table([pl_t('Asset'), pl_t('Class'), pl_t('Scheduled ({currency})', ['currency' => $currency]), pl_t('Already posted ({currency})', ['currency' => $currency]), pl_t('To post ({currency})', ['currency' => $currency])],
    static function () use ($preview): void {
        foreach ($preview['lines'] as $line): ?>
<tr><td><a class="link" href="<?= pl_e(pl_url('/fixed-assets/detail', ['id' => $line['asset_id']])) ?>"><?= pl_e($line['asset_code'] . ' · ' . $line['asset_name']) ?></a></td>
<td><?= pl_e((string) $line['class_code']) ?></td>
<td class="amount"><?= pl_e(pl_money((string) $line['scheduled'])) ?></td>
<td class="amount"><?= pl_e(pl_money((string) $line['already_posted'])) ?></td>
<td class="amount"><?= pl_e(pl_money((string) $line['charge'])) ?></td></tr>
<?php endforeach; ?>
<tr class="bg-surface-subtle"><th scope="row" class="font-semibold" colspan="4"><?= pl_e(pl_t('Total to post')) ?></th>
<td class="amount"><?= pl_e(pl_money((string) $preview['total'])) ?></td></tr>
<?php }, pl_t('Depreciation preview')); ?>

<?php if ($enabled && $preview['period']['status'] === 'open'): ?>
<form method="post" action="<?= pl_e(pl_url('/fixed-assets/depreciation', ['period' => $periodId])) ?>" class="rounded-panel border border-border bg-surface p-4 grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
<?php
pl_starter_form($company, 'run', ['request_key' => $input['request_key'] ?? '', 'period' => $periodId]);
pl_starter_field(pl_t('Reason for this run'), 'reason', $input['reason'] ?? '');
?>
<div><button class="btn btn-primary"><?= pl_e(pl_t('Post the depreciation')) ?></button></div>
</form>
<?php endif; ?>
<?php endif; ?>
</section>
<?php endif; ?>

<section>
<h2 class="section-title mb-2"><?= pl_e(pl_t('Runs already posted')) ?></h2>
<?php if ($runs === []): ?>
<?php pl_ui_empty(pl_t('No depreciation posted yet'), pl_t('Choose a period above to see what a run would post.')); ?>
<?php else: ?>
<?php pl_ui_table([pl_t('Date'), pl_t('Period'), pl_t('Journal'), pl_t('Assets'), pl_t('Amount ({currency})', ['currency' => $currency]), pl_t('Reason'), pl_t('Correction')],
    static function () use ($runs, $company, $input, $enabled): void {
        foreach ($runs as $run): ?>
<tr><td><?= pl_e(pl_date_label((string) $run['run_date'])) ?></td>
<td><?= pl_e(pl_date_label((string) $run['start_date']) . ' – ' . pl_date_label((string) $run['end_date'])) ?></td>
<td><a class="link" href="<?= pl_e(pl_url('/journals/detail', ['id' => $run['journal_id']])) ?>"><?= pl_e((string) $run['journal_reference']) ?></a></td>
<td class="amount"><?= pl_e((string) $run['asset_count']) ?></td>
<td class="amount"><?= pl_e(pl_money((string) $run['total_amount'])) ?></td>
<td><?= pl_e((string) $run['reason']) ?></td>
<td><?php if ($run['is_reversal']): ?><?= pl_e(pl_t('This is a correction')) ?><?php elseif ($run['reversed']): ?><?= pl_e(pl_t('Reversed')) ?><?php elseif ($enabled): ?>
<form method="post" action="<?= pl_e(pl_url('/fixed-assets/depreciation')) ?>" class="flex flex-wrap gap-2 items-end">
<?php
pl_starter_form($company, 'reverse', ['request_key' => ((string) ($input['request_key'] ?? '')) . 'r' . (string) $run['id'], 'run_id' => $run['id']]);
pl_starter_field(pl_t('Reversal date'), 'date', (string) $run['run_date'], 'date', false);
pl_starter_field(pl_t('Reason'), 'reason', '');
?>
<button class="btn btn-danger"><?= pl_e(pl_t('Reverse')) ?></button>
</form>
<?php endif; ?></td></tr>
<?php endforeach;
    }, pl_t('Depreciation runs')); ?>
<?php endif; ?>
</section>
</div>
