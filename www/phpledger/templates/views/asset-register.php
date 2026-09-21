<?php declare(strict_types=1);
/*
 * The fixed-asset register (1.3 M14, issue #95; the fixed-assets manifest's `asset-register`
 * report, on the route the manifest declares).
 *
 * Cost, accumulated depreciation and net book value per class, and — the part that makes it
 * a register rather than a list — the reconciliation of each control account to the ledger,
 * stated as a figure rather than asserted.
 */
$currency = (string) $company['currency'];
?>
<div class="flex flex-col gap-6 py-5">
<?php pl_ui_page_header(pl_t('Fixed-asset register'),
    pl_t('{company} · {currency} · As at {asOf}', ['company' => $company['name'], 'currency' => $currency, 'asOf' => pl_date_label((string) $report['as_of'])]),
    static function (): void { ?>
<a class="btn btn-ghost" href="<?= pl_e(pl_url('/fixed-assets')) ?>"><?= pl_e(pl_t('Fixed assets')) ?></a>
<a class="btn btn-secondary" href="<?= pl_e(pl_url('/fixed-assets/depreciation')) ?>"><?= pl_e(pl_t('Depreciation run')) ?></a>
<?php }); ?>

<form method="get" action="<?= pl_e(pl_url('/reports/asset-register')) ?>" class="rounded-panel border border-border bg-surface p-4 grid grid-cols-2 md:grid-cols-4 gap-4 items-end">
<?php pl_starter_field(pl_t('As at'), 'as_of', (string) $report['as_of'], 'date'); ?>
<div><button class="btn btn-secondary"><?= pl_e(pl_t('Update')) ?></button></div>
</form>

<?php if (!$enabled): ?>
<?php pl_ui_strip(pl_t('Fixed assets is switched off for this company. This report keeps reading what was recorded while it was on; nothing new can be recorded until an owner switches it back on in Modules.'), 'info'); ?>
<?php endif; ?>

<?php if ($report['classes'] === []): ?>
<?php pl_ui_empty(pl_t('Nothing in the register'), pl_t('No asset has been recorded in this book yet.')); ?>
<?php else: ?>
<?php pl_ui_table([pl_t('Number'), pl_t('Asset'), pl_t('In service'), pl_t('Cost ({currency})', ['currency' => $currency]), pl_t('Accumulated depreciation ({currency})', ['currency' => $currency]), pl_t('Net book value ({currency})', ['currency' => $currency]), pl_t('Not yet posted ({currency})', ['currency' => $currency])],
    static function () use ($report): void {
        foreach ($report['classes'] as $group):
            $class = $group['class']; ?>
<tr class="bg-surface-subtle"><th scope="rowgroup" class="font-semibold" colspan="7"><?= pl_e($class['name'] . ' (' . $class['code'] . ')') ?> · <?= pl_e(pl_tn('{count} asset', '{count} assets', count($group['assets']), ['count' => count($group['assets'])])) ?></th></tr>
<?php foreach ($group['assets'] as $asset): ?>
<tr><td><a class="link" href="<?= pl_e(pl_url('/fixed-assets/detail', ['id' => $asset['id']])) ?>"><?= pl_e((string) $asset['code']) ?></a></td>
<td><?= pl_e((string) $asset['name']) ?><?php if ($asset['status'] === 'disposed'): ?> · <?= pl_e(pl_t('disposed {date}', ['date' => pl_date_label((string) $asset['disposal_date'])])) ?><?php endif; ?></td>
<td><?= pl_e(pl_date_label((string) $asset['in_service_date'])) ?></td>
<td class="amount"><?= pl_e(pl_money((string) $asset['posted_cost'])) ?></td>
<td class="amount"><?= pl_e(pl_money((string) $asset['posted_accumulated'])) ?></td>
<td class="amount"><?= pl_e(pl_money((string) $asset['net_book_value'])) ?></td>
<td class="amount"><?= pl_e(pl_money((string) $asset['outstanding_depreciation'])) ?></td></tr>
<?php endforeach; ?>
<tr class="bg-surface-subtle"><th scope="row" class="font-semibold" colspan="3"><?= pl_e(pl_t('Sub total · {code}', ['code' => (string) $class['code']])) ?></th>
<td class="amount"><?= pl_e(pl_money((string) $group['cost'])) ?></td>
<td class="amount"><?= pl_e(pl_money((string) $group['accumulated'])) ?></td>
<td class="amount"><?= pl_e(pl_money((string) $group['net_book_value'])) ?></td>
<td class="amount"><?= pl_e(pl_money((string) $group['outstanding'])) ?></td></tr>
<?php endforeach; ?>
<tr class="bg-surface-subtle"><th scope="row" class="font-semibold" colspan="3"><?= pl_e(pl_t('Grand total · every class')) ?></th>
<td class="amount"><?= pl_e(pl_money((string) $report['totals']['cost'])) ?></td>
<td class="amount"><?= pl_e(pl_money((string) $report['totals']['accumulated'])) ?></td>
<td class="amount"><?= pl_e(pl_money((string) $report['totals']['net_book_value'])) ?></td>
<td class="amount"><?= pl_e(pl_money((string) $report['totals']['outstanding'])) ?></td></tr>
<?php }, pl_t('Fixed-asset register')); ?>
<?php endif; ?>

<section>
<h2 class="section-title mb-2"><?= pl_e(pl_t('Reconciliation to the control accounts')) ?></h2>
<?php pl_ui_strip($report['reconciled']
    ? pl_t('The register agrees with every control account it posts to, to the last four decimal places. Accumulated depreciation is a contra asset, so the balance sheet shows it as a deduction from cost.')
    : pl_t('The register and the ledger differ on at least one control account. Something reached the account without going through the register — usually a manual journal straight into fixed assets. Investigate before relying on either figure.'),
    $report['reconciled'] ? 'info' : 'warning'); ?>
<?php if ($report['reconciliation'] !== []): ?>
<?php pl_ui_table([pl_t('Account'), pl_t('Name'), pl_t('Purpose'), pl_t('Ledger ({currency})', ['currency' => $currency]), pl_t('Register ({currency})', ['currency' => $currency]), pl_t('Difference ({currency})', ['currency' => $currency])],
    static function () use ($report): void {
        foreach ($report['reconciliation'] as $row): ?>
<tr><td><?= pl_e((string) $row['code']) ?></td><td><?= pl_e((string) $row['name']) ?></td>
<td><?= pl_e($row['purpose'] === 'cost' ? pl_t('Cost') : pl_t('Accumulated depreciation')) ?></td>
<td class="amount"><?= pl_e(pl_money((string) $row['ledger'])) ?></td>
<td class="amount"><?= pl_e(pl_money((string) $row['register'])) ?></td>
<td class="amount"><?= pl_e(pl_money((string) $row['difference'])) ?></td></tr>
<?php endforeach;
    }, pl_t('Register reconciliation')); ?>
<?php endif; ?>
</section>

<p class="text-sm text-ink-muted"><?= pl_e(pl_t('"Not yet posted" is the depreciation the schedule says should have been charged by this date and has not been. It is not a ledger figure and nothing here posts it: run those periods in Depreciation run.')) ?></p>
</div>
