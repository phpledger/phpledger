<?php declare(strict_types=1);
/*
 * A4 sales invoice (1.2 M3; frame invoice-print-a4.html, decision 7).
 *
 * Letterhead, two-column parties, pack/discount/free-goods columns, a rate-grouped tax
 * summary beside the totals, and the cash received with the resulting balance.
 *
 * @var array $document Read-only invoice data from pl_trading_invoice_print().
 */
$invoice = $document['document'];
$party = $invoice['party'];
$addressLines = pl_print_party_lines($party['details']['addresses'] ?? null);
$registrationLines = pl_print_party_lines($party['details']['registrations'] ?? null);
$isCredit = (bool) $invoice['is_credit'];
?>
<section class="print-parties">
    <div class="print-party">
        <h2><?= pl_e($isCredit ? pl_t('Credit to') : pl_t('Bill to')) ?></h2>
        <p class="print-party-name"><?= pl_e((string) $party['legal_name']) ?></p>
        <?php foreach ($addressLines as $line): ?><p><?= pl_e($line) ?></p><?php endforeach; ?>
        <?php foreach ($registrationLines as $line): ?><p class="print-party-registration"><?= pl_e($line) ?></p><?php endforeach; ?>
    </div>
    <div class="print-party">
        <h2><?= pl_e(pl_t('Delivery details')) ?></h2>
        <dl class="print-facts">
            <dt><?= pl_e(pl_t('Dated')) ?></dt><dd><?= pl_e(pl_date_label((string) $invoice['document_date'])) ?></dd>
            <dt><?= pl_e(pl_t('Due')) ?></dt><dd><?= pl_e(pl_date_label((string) $invoice['due_date'])) ?></dd>
            <?php if ($document['warehouse'] !== null): ?><dt><?= pl_e(pl_t('Warehouse')) ?></dt><dd><?= pl_e($document['warehouse']['name'] . ' (' . $document['warehouse']['code'] . ')') ?></dd><?php endif; ?>
            <?php if ($document['sales_staff'] !== null): ?><dt><?= pl_e(pl_t('Sales staff')) ?></dt><dd><?= pl_e((string) $document['sales_staff']['name']) ?></dd><?php endif; ?>
            <?php if ($document['area'] !== null): ?><dt><?= pl_e(pl_t('Area / route')) ?></dt><dd><?= pl_e((string) $document['area']['name']) ?></dd><?php endif; ?>
            <?php if ((string) $invoice['terms'] !== ''): ?><dt><?= pl_e(pl_t('Terms')) ?></dt><dd><?= pl_e((string) $invoice['terms']) ?></dd><?php endif; ?>
            <?php if ((string) $invoice['reference'] !== ''): ?><dt><?= pl_e(pl_t('Reference')) ?></dt><dd><?= pl_e((string) $invoice['reference']) ?></dd><?php endif; ?>
        </dl>
    </div>
</section>
<?php if ($invoice['payment_status'] === 'reversed'): ?>
<p class="print-notice"><?= pl_e(pl_t('This document has been reversed by a linked entry. It is kept for the record.')) ?></p>
<?php endif; ?>
<div class="print-lines-scroll">
<table class="print-lines">
    <caption><?= pl_e($isCredit ? pl_t('Credited items') : pl_t('Items invoiced')) ?></caption>
    <thead><tr>
        <th scope="col"><?= pl_e(pl_t('Item')) ?></th>
        <th scope="col" class="print-num"><?= pl_e(pl_t('Packs')) ?></th>
        <th scope="col" class="print-num"><?= pl_e(pl_t('Units')) ?></th>
        <th scope="col" class="print-num"><?= pl_e(pl_t('Unit price')) ?></th>
        <th scope="col" class="print-num"><?= pl_e(pl_t('Disc %')) ?></th>
        <th scope="col" class="print-num"><?= pl_e(pl_t('Tax %')) ?></th>
        <th scope="col" class="print-num"><?= pl_e(pl_t('Amount ({currency})', ['currency' => (string) $invoice['currency']])) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($invoice['lines'] as $line): ?>
        <tr<?= $line['is_free_goods'] ? ' class="print-line-free"' : '' ?>>
            <th scope="row"><?= pl_e((string) $line['description']) ?><?= $line['is_free_goods'] ? ' — ' . pl_e(pl_t('free goods')) : '' ?></th>
            <td class="print-num"><?= $line['pack_id'] === null ? '–' : pl_e(pl_money((string) $line['pack_quantity'])) ?></td>
            <td class="print-num"><?= pl_e(pl_money((string) ($line['pack_id'] === null ? $line['quantity'] : $line['unit_quantity']))) ?></td>
            <td class="print-num"><?= $line['is_free_goods'] ? '–' : pl_e(pl_money((string) $line['unit_price'])) ?></td>
            <td class="print-num"><?= $line['is_free_goods'] ? '–' : pl_e(pl_money((string) $line['discount_percent'])) ?></td>
            <td class="print-num"><?= pl_e(pl_money((string) $line['tax_rate'])) ?></td>
            <td class="print-num"><?= pl_e(pl_money((string) $line['line_total'])) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php if ($document['tax_summary'] !== []): ?>
<table class="print-lines">
    <caption><?= pl_e(pl_t('Tax summary by rate')) ?></caption>
    <thead><tr><th scope="col"><?= pl_e(pl_t('Rate')) ?></th><th scope="col" class="print-num"><?= pl_e(pl_t('Taxable')) ?></th><th scope="col" class="print-num"><?= pl_e(pl_t('Tax')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($document['tax_summary'] as $band): ?>
        <tr>
            <th scope="row"><?= pl_e($band['label'] !== '' ? $band['label'] : pl_t('Exempt or zero-rated')) ?> · <?= pl_e(pl_money($band['rate'])) ?>%</th>
            <td class="print-num"><?= pl_e(pl_money($band['taxable'])) ?></td>
            <td class="print-num"><?= pl_e(pl_money($band['tax'])) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
<section class="print-totals">
    <?php $gross = bcadd((string) $invoice['subtotal'], (string) $invoice['discount_total'], 4); ?>
    <div class="print-total-row"><span><?= pl_e(pl_t('Subtotal')) ?></span><span class="print-num"><?= pl_e(pl_money($gross)) ?></span></div>
    <?php if (bccomp((string) $invoice['discount_total'], '0', 4) > 0): ?>
        <div class="print-total-row"><span><?= pl_e(pl_t('Line discounts')) ?></span><span class="print-num">−<?= pl_e(pl_money((string) $invoice['discount_total'])) ?></span></div>
    <?php endif; ?>
    <div class="print-total-row"><span><?= pl_e(pl_t('Tax')) ?></span><span class="print-num"><?= pl_e(pl_money((string) $invoice['tax_total'])) ?></span></div>
    <div class="print-total-row print-total-grand"><span><?= pl_e(pl_t('Total ({currency})', ['currency' => (string) $invoice['currency']])) ?></span><span class="print-num"><?= pl_e(pl_money((string) $invoice['total'])) ?></span></div>
    <?php if (bccomp((string) $invoice['cash_received'], '0', 4) > 0): ?>
        <div class="print-total-row"><span><?= pl_e(pl_t('Cash received')) ?></span><span class="print-num">−<?= pl_e(pl_money((string) $invoice['cash_received'])) ?></span></div>
        <div class="print-total-row"><span><?= pl_e(pl_t('Balance due')) ?></span><span class="print-num"><?= pl_e(pl_money((string) $document['balance_due'])) ?></span></div>
    <?php endif; ?>
    <?php if (bccomp((string) $invoice['free_tax_total'], '0', 4) > 0): ?>
        <div class="print-total-row"><span><?= pl_e(pl_t('Output tax on free goods, borne by us')) ?></span><span class="print-num"><?= pl_e(pl_money((string) $invoice['free_tax_total'])) ?></span></div>
    <?php endif; ?>
</section>
<?php if ((string) $invoice['notes'] !== ''): ?>
<section class="print-parties"><div class="print-party"><h2><?= pl_e(pl_t('Terms & notes')) ?></h2><p><?= pl_e((string) $invoice['notes']) ?></p></div></section>
<?php endif; ?>
<section class="print-signoff">
    <div class="print-signature"><?= pl_e(pl_t('Prepared by')) ?></div>
    <div class="print-signature"><?= pl_e(pl_t('Received in good order')) ?></div>
</section>
