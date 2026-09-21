<?php declare(strict_types=1);
/*
 * One asset's statement (1.3 M14, issue #95): what it is, every entry it has been part of,
 * the depreciation schedule ahead of it, and the disposal and correction actions.
 */
$asset = $statement['asset'];
$class = $statement['class'];
$currency = (string) $company['currency'];
$accountOptions = pl_starter_options($accounts);
$methods = pl_asset_methods();
?>
<div class="flex flex-col gap-6 py-5">
<?php pl_ui_document_header(pl_t('Asset {code}', ['code' => (string) $asset['code']]),
    $asset['status'] === 'active' ? 'active' : ($asset['status'] === 'disposed' ? 'paid' : 'reversed'),
    static function (): void { ?>
<a class="btn btn-ghost" href="<?= pl_e(pl_url('/fixed-assets')) ?>"><?= pl_e(pl_t('All assets')) ?></a>
<a class="btn btn-secondary" href="<?= pl_e(pl_url('/reports/asset-register')) ?>"><?= pl_e(pl_t('Register report')) ?></a>
<?php }); ?>

<?php if ($form['message'] !== ''): ?>
<div class="alert alert-danger" role="alert" tabindex="-1" data-form-error><?= pl_e($form['message']) ?></div>
<?php endif; ?>

<?php if (!$enabled): ?>
<?php pl_ui_strip(pl_t('Fixed assets is switched off for this company. This record stays readable; nothing new can be recorded against it until an owner switches the module on in Modules.'), 'warning'); ?>
<?php endif; ?>

<?php pl_ui_totals([
    pl_t('Cost') => pl_money((string) $asset['posted_cost']),
    pl_t('Accumulated depreciation') => pl_money((string) $asset['posted_accumulated']),
    pl_t('Net book value') => pl_money((string) $asset['net_book_value']),
    pl_t('Residual value') => pl_money((string) $asset['residual_value']),
]); ?>

<section>
<h2 class="section-title mb-2"><?= pl_e(pl_t('Details')) ?></h2>
<dl class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
<div><dt class="text-ink-muted"><?= pl_e(pl_t('Name')) ?></dt><dd><?= pl_e((string) $asset['name']) ?></dd></div>
<div><dt class="text-ink-muted"><?= pl_e(pl_t('Class')) ?></dt><dd><?= pl_e($class['code'] . ' · ' . $class['name']) ?></dd></div>
<div><dt class="text-ink-muted"><?= pl_e(pl_t('Method')) ?></dt><dd><?= pl_e((string) ($methods[$class['method']] ?? $class['method'])) ?></dd></div>
<div><dt class="text-ink-muted"><?= pl_e(pl_t('Convention')) ?></dt><dd><?= pl_e(pl_t('A full month in the month it enters service, and none in the month it is disposed of')) ?></dd></div>
<div><dt class="text-ink-muted"><?= pl_e(pl_t('Acquired')) ?></dt><dd><?= pl_e(pl_date_label((string) $asset['acquisition_date'])) ?></dd></div>
<div><dt class="text-ink-muted"><?= pl_e(pl_t('In service')) ?></dt><dd><?= pl_e(pl_date_label((string) $asset['in_service_date'])) ?></dd></div>
<div><dt class="text-ink-muted"><?= pl_e(pl_t('Useful life')) ?></dt><dd><?= pl_e(pl_tn('{count} month', '{count} months', (int) ($asset['useful_life_months'] ?? $class['useful_life_months']), ['count' => (int) ($asset['useful_life_months'] ?? $class['useful_life_months'])])) ?></dd></div>
<div><dt class="text-ink-muted"><?= pl_e(pl_t('Location')) ?></dt><dd><?= pl_e((string) $asset['location'] ?: '—') ?></dd></div>
<div><dt class="text-ink-muted"><?= pl_e(pl_t('Supplier')) ?></dt><dd><?= pl_e((string) $asset['supplier'] ?: '—') ?></dd></div>
<div><dt class="text-ink-muted"><?= pl_e(pl_t('Reference')) ?></dt><dd><?= pl_e((string) $asset['reference'] ?: '—') ?></dd></div>
<?php if ($asset['disposal_date'] !== null): ?>
<div><dt class="text-ink-muted"><?= pl_e(pl_t('Disposed')) ?></dt><dd><?= pl_e(pl_date_label((string) $asset['disposal_date'])) ?></dd></div>
<?php endif; ?>
</dl>
</section>

<section>
<h2 class="section-title mb-2"><?= pl_e(pl_t('Depreciation schedule')) ?></h2>
<?php if (!$statement['schedule_complete']): ?>
<?php pl_ui_strip(pl_t('The accounting periods this book has do not cover the whole useful life yet, so the schedule below stops early. It will extend itself as later periods are created; the charges over the full life still add up to cost less residual value exactly.'), 'info'); ?>
<?php endif; ?>
<?php if ($statement['schedule'] === []): ?>
<?php pl_ui_empty(pl_t('No schedule'), pl_t('No accounting period overlaps the useful life of this asset yet.')); ?>
<?php else: ?>
<?php pl_ui_table([pl_t('Period'), pl_t('Months'), pl_t('Charge ({currency})', ['currency' => $currency]), pl_t('Posted ({currency})', ['currency' => $currency]), pl_t('Outstanding ({currency})', ['currency' => $currency]), pl_t('Accumulated ({currency})', ['currency' => $currency]), pl_t('Closing net book value ({currency})', ['currency' => $currency])],
    static function () use ($statement): void {
        foreach ($statement['schedule'] as $row): ?>
<tr><td><?= pl_e(pl_date_label($row['start_date']) . ' – ' . pl_date_label($row['end_date'])) ?></td>
<td class="amount"><?= pl_e((string) $row['months']) ?></td>
<td class="amount"><?= pl_e(pl_money($row['charge'])) ?></td>
<td class="amount"><?= pl_e(pl_money($row['posted'])) ?></td>
<td class="amount"><?= pl_e(pl_money($row['outstanding'])) ?></td>
<td class="amount"><?= pl_e(pl_money($row['accumulated'])) ?></td>
<td class="amount"><?= pl_e(pl_money($row['closing_nbv'])) ?></td></tr>
<?php endforeach; ?>
<tr class="bg-surface-subtle"><th scope="row" class="font-semibold" colspan="2"><?= pl_e(pl_t('Total over the useful life')) ?></th>
<td class="amount"><?= pl_e(pl_money((string) $statement['scheduled_total'])) ?></td><td colspan="4"><?= pl_e(pl_t('Cost less residual value is {amount}.', ['amount' => pl_money((string) $statement['depreciable_amount'])])) ?></td></tr>
<?php }, pl_t('Depreciation schedule')); ?>
<?php endif; ?>
</section>

<section>
<h2 class="section-title mb-2"><?= pl_e(pl_t('Entries')) ?></h2>
<p class="text-sm text-ink-muted"><?= pl_e(pl_t('Every line here is backed by one posted journal. A correction is a linked reversal under the same entry, never an edit.')) ?></p>
<?php pl_ui_table([pl_t('Date'), pl_t('Entry'), pl_t('Journal'), pl_t('Cost ({currency})', ['currency' => $currency]), pl_t('Depreciation ({currency})', ['currency' => $currency]), pl_t('Proceeds ({currency})', ['currency' => $currency]), pl_t('Gain or loss ({currency})', ['currency' => $currency])],
    static function () use ($statement): void {
        foreach ($statement['events'] as $event): ?>
<tr><td><?= pl_e(pl_date_label((string) $event['event_date'])) ?></td>
<td><?= pl_e(match ((string) $event['kind']) { 'acquisition' => pl_t('Acquisition'), 'depreciation' => pl_t('Depreciation'), default => pl_t('Disposal') }) ?><?php if ($event['is_reversal']): ?> · <?= pl_e(pl_t('correction')) ?><?php endif; ?></td>
<td><a class="link" href="<?= pl_e(pl_url('/journals/detail', ['id' => $event['journal_id']])) ?>"><?= pl_e((string) $event['journal_reference']) ?></a></td>
<td class="amount"><?= pl_e(pl_money((string) $event['cost_amount'])) ?></td>
<td class="amount"><?= pl_e(pl_money((string) $event['depreciation_amount'])) ?></td>
<td class="amount"><?= pl_e(pl_money((string) $event['proceeds_amount'])) ?></td>
<td class="amount"><?= pl_e(pl_money((string) $event['gain_loss_amount'])) ?></td></tr>
<?php endforeach;
    }, pl_t('Asset entries')); ?>
</section>

<?php if ($enabled && $asset['status'] === 'active'): ?>
<section class="flex flex-col gap-4">
<h2 class="section-title"><?= pl_e(pl_t('Dispose of this asset')) ?></h2>
<p class="text-sm text-ink-muted"><?= pl_e(pl_t('Disposal removes the cost and the accumulated depreciation and posts the difference against the proceeds as the gain or loss. Depreciation has to be posted for every period that ended before the disposal date first, so the gain or loss is measured against an up-to-date carrying amount. The period the disposal falls in takes no charge.')) ?></p>
<form method="post" action="<?= pl_e(pl_url('/fixed-assets/detail', ['id' => $asset['id']])) ?>" class="rounded-panel border border-border bg-surface p-4 grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
<?php
pl_starter_form($company, 'dispose', ['request_key' => $input['request_key'] ?? '', 'id' => $asset['id']]);
pl_starter_select(pl_t('What happened'), 'kind', pl_asset_disposal_kinds(), $input['kind'] ?? 'sale');
pl_starter_field(pl_t('Disposal date'), 'date', $input['date'] ?? '', 'date');
pl_starter_field(pl_t('Proceeds ({currency})', ['currency' => $currency]), 'proceeds', $input['proceeds'] ?? '0', 'text', false);
pl_starter_select(pl_t('Proceeds received into'), 'proceeds_account_id', $accountOptions, $input['proceeds_account_id'] ?? '', false);
pl_starter_field(pl_t('Reason for this entry'), 'reason', $input['reason'] ?? '');
?>
<div><button class="btn btn-primary"><?= pl_e(pl_t('Record the disposal')) ?></button></div>
</form>

<h2 class="section-title"><?= pl_e(pl_t('Correct the acquisition')) ?></h2>
<?php pl_ui_confirmation(pl_t('Reverse the acquisition'),
    pl_t('This posts a linked reversal of the acquisition entry and takes the asset out of the register. It is only available while the asset has no depreciation and no disposal against it.'),
    static function () use ($company, $asset, $input): void { ?>
<form method="post" action="<?= pl_e(pl_url('/fixed-assets/detail', ['id' => $asset['id']])) ?>" class="flex flex-wrap gap-2 items-end">
<?php
pl_starter_form($company, 'reverse_acquisition', ['request_key' => $input['request_key'] ?? '', 'id' => $asset['id']]);
pl_starter_field(pl_t('Reversal date'), 'date', $input['date'] ?? '', 'date', false);
pl_starter_field(pl_t('Reason for this correction'), 'reason', '');
?>
<button class="btn btn-danger"><?= pl_e(pl_t('Post the reversal')) ?></button>
</form>
<?php }); ?>
</section>
<?php endif; ?>

<?php if ($enabled && $asset['status'] === 'disposed'): ?>
<section>
<h2 class="section-title mb-2"><?= pl_e(pl_t('Correct the disposal')) ?></h2>
<?php pl_ui_confirmation(pl_t('Reverse the disposal'),
    pl_t('This posts a linked reversal of the disposal entry. The cost and the accumulated depreciation come back exactly as they were and the asset returns to the register.'),
    static function () use ($company, $asset, $input): void { ?>
<form method="post" action="<?= pl_e(pl_url('/fixed-assets/detail', ['id' => $asset['id']])) ?>" class="flex flex-wrap gap-2 items-end">
<?php
pl_starter_form($company, 'reverse_disposal', ['request_key' => $input['request_key'] ?? '', 'id' => $asset['id']]);
pl_starter_field(pl_t('Reversal date'), 'date', $input['date'] ?? '', 'date', false);
pl_starter_field(pl_t('Reason for this correction'), 'reason', '');
?>
<button class="btn btn-danger"><?= pl_e(pl_t('Post the reversal')) ?></button>
</form>
<?php }); ?>
</section>
<?php endif; ?>
</div>
