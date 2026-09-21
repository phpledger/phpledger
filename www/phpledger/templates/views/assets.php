<?php declare(strict_types=1);
/*
 * Fixed assets (1.3 M14, issue #95): the register, the form that records an asset and posts
 * its acquisition, and the asset classes that carry the depreciation policy.
 */
$currency = (string) $company['currency'];
$accountOptions = pl_starter_options($accounts);
$classOptions = [];
foreach ($classes as $class) { if ($class['is_active']) { $classOptions[(string) $class['id']] = $class['code'] . ' · ' . $class['name']; } }
$methods = pl_asset_methods();
?>
<div class="flex flex-col gap-6 py-5">
<?php pl_ui_page_header(pl_t('Fixed assets'),
    pl_t('{company} · {currency} · {assets} assets in {classes} classes', [
        'company' => $company['name'], 'currency' => $currency,
        'assets' => count($assets), 'classes' => count($classes),
    ]),
    static function (): void { ?>
<a class="btn btn-ghost" href="<?= pl_e(pl_url('/fixed-assets/depreciation')) ?>"><?= pl_e(pl_t('Depreciation run')) ?></a>
<a class="btn btn-secondary" href="<?= pl_e(pl_url('/reports/asset-register')) ?>"><?= pl_e(pl_t('Register report')) ?></a>
<?php }); ?>

<?php if ($form['message'] !== ''): ?>
<div class="alert alert-danger" role="alert" tabindex="-1" data-form-error><?= pl_e($form['message']) ?></div>
<?php endif; ?>

<?php if (!$enabled): ?>
<?php pl_ui_strip(pl_t('Fixed assets is switched off for this company, so nothing new can be recorded. Everything already recorded stays readable here and in the register report. An owner can switch it on in Modules.'), 'warning'); ?>
<?php endif; ?>

<?php pl_ui_tabs([
    pl_t('Register') => pl_url('/fixed-assets', ['tab' => 'assets']),
    pl_t('Asset classes') => pl_url('/fixed-assets', ['tab' => 'classes']),
], $tab === 'classes' ? pl_t('Asset classes') : pl_t('Register')); ?>

<?php if ($tab === 'classes'): ?>
<section class="flex flex-col gap-4">
<h2 class="section-title"><?= pl_e(pl_t('Asset classes')) ?></h2>
<p class="text-sm text-ink-muted"><?= pl_e(pl_t('A class decides how an asset is written off and which accounts its entries go to. Accumulated depreciation has to be a contra asset account, so the balance sheet shows it as a deduction. Changing a class changes future charges only: everything already posted is a journal, and journals do not move.')) ?></p>
<?php if ($classes === []): ?>
<?php pl_ui_empty(pl_t('No asset classes yet'), pl_t('Add one below. The four accounts it needs are created for you the first time, and you can point the class at your own accounts instead.')); ?>
<?php else: ?>
<?php pl_ui_table([pl_t('Code'), pl_t('Name'), pl_t('Method'), pl_t('Useful life'), pl_t('Rate'), pl_t('Cost account'), pl_t('Accumulated'), pl_t('Expense'), pl_t('Gain or loss')],
    static function () use ($classes, $methods): void {
        foreach ($classes as $class): ?>
<tr><td><?= pl_e((string) $class['code']) ?></td><td><?= pl_e((string) $class['name']) ?></td>
<td><?= pl_e((string) ($methods[$class['method']] ?? $class['method'])) ?></td>
<td class="amount"><?= pl_e(pl_tn('{count} month', '{count} months', (int) $class['useful_life_months'], ['count' => (int) $class['useful_life_months']])) ?></td>
<td class="amount"><?= pl_e($class['annual_rate'] === null ? '—' : (string) $class['annual_rate']) ?></td>
<td><?= pl_e((string) $class['asset_account_code']) ?></td><td><?= pl_e((string) $class['accumulated_account_code']) ?></td>
<td><?= pl_e((string) $class['expense_account_code']) ?></td><td><?= pl_e((string) $class['disposal_account_code']) ?></td></tr>
<?php endforeach;
    }, pl_t('Asset classes')); ?>
<?php endif; ?>

<?php if ($enabled): ?>
<form method="post" action="<?= pl_e(pl_url('/fixed-assets')) ?>" class="rounded-panel border border-border bg-surface p-4 grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
<?php
pl_starter_form($company, 'class', ['request_key' => $input['request_key'] ?? '']);
pl_starter_field(pl_t('Class code'), 'code', $input['code'] ?? '');
pl_starter_field(pl_t('Class name'), 'name', $input['name'] ?? '');
pl_starter_select(pl_t('Method'), 'method', $methods, $input['method'] ?? 'straight_line');
pl_starter_field(pl_t('Useful life in months'), 'useful_life_months', $input['useful_life_months'] ?? '', 'number');
pl_starter_field(pl_t('Reducing-balance rate as a fraction of one, for example 0.15'), 'annual_rate', $input['annual_rate'] ?? '', 'text', false);
pl_starter_field(pl_t('Reason for this change'), 'reason', $input['reason'] ?? '');
?>
<div><button class="btn btn-primary"><?= pl_e(pl_t('Save asset class')) ?></button></div>
</form>
<?php endif; ?>
</section>

<?php else: ?>
<section class="flex flex-col gap-4">
<?php if ($assets === []): ?>
<?php pl_ui_empty(pl_t('No assets recorded yet'), pl_t('Record one below. Its acquisition is posted through the same posting service every other entry uses, which is what lets the register reconcile to the ledger.')); ?>
<?php else: ?>
<?php pl_ui_table([pl_t('Number'), pl_t('Asset'), pl_t('Class'), pl_t('In service'), pl_t('Cost ({currency})', ['currency' => $currency]), pl_t('Accumulated ({currency})', ['currency' => $currency]), pl_t('Net book value ({currency})', ['currency' => $currency]), pl_t('Status')],
    static function () use ($assets): void {
        foreach ($assets as $asset): ?>
<tr><td><a class="link" href="<?= pl_e(pl_url('/fixed-assets/detail', ['id' => $asset['id']])) ?>"><?= pl_e((string) $asset['code']) ?></a></td>
<td><?= pl_e((string) $asset['name']) ?><?php if ((string) $asset['location'] !== ''): ?> <span class="text-ink-muted">· <?= pl_e((string) $asset['location']) ?></span><?php endif; ?></td>
<td><?= pl_e((string) $asset['class_code']) ?></td>
<td><?= pl_e(pl_date_label((string) $asset['in_service_date'])) ?></td>
<td class="amount"><?= pl_e(pl_money((string) $asset['posted_cost'])) ?></td>
<td class="amount"><?= pl_e(pl_money((string) $asset['posted_accumulated'])) ?></td>
<td class="amount"><?= pl_e(pl_money((string) $asset['net_book_value'])) ?></td>
<td><?php pl_ui_badge($asset['status'] === 'active' ? 'active' : ($asset['status'] === 'disposed' ? 'paid' : 'reversed'),
    match ($asset['status']) { 'active' => pl_t('In use'), 'disposed' => pl_t('Disposed'), default => pl_t('Reversed') }); ?></td></tr>
<?php endforeach;
    }, pl_t('Fixed-asset register')); ?>
<?php endif; ?>

<?php if ($enabled): ?>
<h2 class="section-title"><?= pl_e(pl_t('Record an asset')) ?></h2>
<p class="text-sm text-ink-muted"><?= pl_e(pl_t('Recording an asset posts its acquisition: the cost account of its class is debited and the account you name here is credited. Name the account that paid for it — a bank account, the payables account, or the owner capital account.')) ?></p>
<?php if ($classOptions === []): ?>
<?php pl_ui_strip(pl_t('Add an active asset class first: a class is what decides how an asset is written off and where its entries go.'), 'info'); ?>
<?php else: ?>
<form method="post" action="<?= pl_e(pl_url('/fixed-assets')) ?>" class="rounded-panel border border-border bg-surface p-4 grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
<?php
pl_starter_form($company, 'asset', ['request_key' => $input['request_key'] ?? '']);
pl_starter_field(pl_t('Asset number'), 'code', $input['code'] ?? '');
pl_starter_field(pl_t('Asset name'), 'name', $input['name'] ?? '');
pl_starter_select(pl_t('Asset class'), 'class_id', $classOptions, $input['class_id'] ?? '');
pl_starter_field(pl_t('Acquisition date'), 'acquisition_date', $input['acquisition_date'] ?? '', 'date');
pl_starter_field(pl_t('Date in service'), 'in_service_date', $input['in_service_date'] ?? '', 'date', false);
pl_starter_field(pl_t('Cost ({currency})', ['currency' => $currency]), 'cost', $input['cost'] ?? '');
pl_starter_field(pl_t('Residual value ({currency})', ['currency' => $currency]), 'residual_value', $input['residual_value'] ?? '0', 'text', false);
pl_starter_select(pl_t('Paid from'), 'credit_account_id', $accountOptions, $input['credit_account_id'] ?? '');
pl_starter_field(pl_t('Useful life override in months'), 'useful_life_months', $input['useful_life_months'] ?? '', 'number', false);
pl_starter_field(pl_t('Rate override'), 'annual_rate', $input['annual_rate'] ?? '', 'text', false);
pl_starter_field(pl_t('Location'), 'location', $input['location'] ?? '', 'text', false);
pl_starter_field(pl_t('Supplier'), 'supplier', $input['supplier'] ?? '', 'text', false);
pl_starter_field(pl_t('Reference'), 'reference', $input['reference'] ?? '', 'text', false);
pl_starter_field(pl_t('Reason for this entry'), 'reason', $input['reason'] ?? '');
?>
<div><button class="btn btn-primary"><?= pl_e(pl_t('Record asset and post the acquisition')) ?></button></div>
</form>
<?php endif; ?>
<?php endif; ?>
</section>
<?php endif; ?>
</div>
