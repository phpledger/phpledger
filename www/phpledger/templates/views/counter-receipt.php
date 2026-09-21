<?php declare(strict_types=1);
/*
 * The counter receipt: one posted sale, read back as the customer's copy (1.2 M9).
 *
 * There is no receipt record behind this. A counter sale is a customer invoice, so this is
 * that invoice read through pl_get_ar_document(); the printable 80mm copy is the existing
 * invoice template, on the existing print route.
 */
?>
<div class="flex flex-col gap-6 py-5">
<?php pl_ui_page_header(pl_t('Sale {number}', ['number' => (string) $receipt['document_number']]),
    pl_t('{date} · {party}', ['date' => pl_date_label((string) $receipt['document_date']), 'party' => (string) $receipt['party']['legal_name']]),
    static function () use ($receipt): void { ?>
<a class="btn btn-secondary" href="<?= pl_e(pl_url('/print/invoice/' . (int) $receipt['id'], ['format' => '80mm'])) ?>"><?= pl_e(pl_t('Print the receipt')) ?></a>
<a class="btn btn-ghost" href="<?= pl_e(pl_url('/print/invoice/' . (int) $receipt['id'])) ?>"><?= pl_e(pl_t('A4 copy')) ?></a>
<a class="btn btn-ghost" href="<?= pl_e(pl_url('/counter')) ?>"><?= pl_e(pl_t('Next sale')) ?></a>
<?php }); ?>

<?php pl_ui_strip(match ($receipt['tender']) {
    'cash' => pl_t('Paid in cash. The receipt was allocated to this invoice in the same action that posted it, so nothing is outstanding.'),
    'part_cash' => pl_t('Part paid in cash. {amount} remains on the customer’s account.', ['amount' => pl_money($receipt['outstanding'])]),
    default => pl_t('On the customer’s account. {amount} is outstanding and appears in the receivables ageing.', ['amount' => pl_money($receipt['outstanding'])]),
}, 'info'); ?>

<?php if ($receipt['warehouse'] !== null): ?>
<p class="text-sm"><?= pl_e(pl_t('Served from {location}.', ['location' => $receipt['warehouse']['code'] . ' · ' . $receipt['warehouse']['name']])) ?>
<?= $receipt['warehouse']['is_mobile'] ? pl_e(pl_t('This is a van, so the sale appears in that driver’s day at settlement.')) : '' ?></p>
<?php endif; ?>

<?php pl_ui_table([pl_t('Item'), pl_t('Quantity'), pl_t('Unit price'), pl_t('Discount'), pl_t('Amount')],
    static function () use ($receipt): void { foreach ($receipt['lines'] as $line): ?>
<tr><td><?= pl_e((string) $line['description']) ?></td>
<td class="amount"><?= pl_e((string) $line['quantity']) ?></td>
<td class="amount"><?= pl_e(pl_money((string) $line['unit_price'])) ?></td>
<td class="amount"><?= pl_e(pl_money((string) ($line['discount_amount'] ?? '0.0000'))) ?></td>
<td class="amount"><?= pl_e(pl_money((string) $line['line_total'])) ?></td></tr>
<?php endforeach; }, pl_t('What was sold')); ?>

<?php pl_ui_totals([
    pl_t('Goods ({currency})', ['currency' => (string) $receipt['currency']]) => pl_money((string) $receipt['subtotal']),
    pl_t('Tax ({currency})', ['currency' => (string) $receipt['currency']]) => pl_money((string) $receipt['tax_total']),
    pl_t('Total ({currency})', ['currency' => (string) $receipt['currency']]) => pl_money((string) $receipt['total']),
    pl_t('Paid in cash') => pl_money((string) $receipt['cash_received']),
    pl_t('Outstanding') => pl_money((string) $receipt['outstanding']),
]); ?>

<p class="text-sm"><a class="link" href="<?= pl_e(pl_url('/ar')) ?>"><?= pl_e(pl_t('Open this sale in Invoices')) ?></a></p>
</div>
