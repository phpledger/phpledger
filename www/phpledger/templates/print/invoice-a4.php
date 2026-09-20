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
        <h2><?= $isCredit ? 'Credit to' : 'Bill to' ?></h2>
        <p class="print-party-name"><?= pl_e((string) $party['legal_name']) ?></p>
        <?php foreach ($addressLines as $line): ?><p><?= pl_e($line) ?></p><?php endforeach; ?>
        <?php foreach ($registrationLines as $line): ?><p class="print-party-registration"><?= pl_e($line) ?></p><?php endforeach; ?>
    </div>
    <div class="print-party">
        <h2>Delivery details</h2>
        <dl class="print-facts">
            <dt>Dated</dt><dd><?= pl_e(pl_date_label((string) $invoice['document_date'])) ?></dd>
            <dt>Due</dt><dd><?= pl_e(pl_date_label((string) $invoice['due_date'])) ?></dd>
            <?php if ($document['warehouse'] !== null): ?><dt>Warehouse</dt><dd><?= pl_e($document['warehouse']['name'] . ' (' . $document['warehouse']['code'] . ')') ?></dd><?php endif; ?>
            <?php if ($document['sales_staff'] !== null): ?><dt>Sales staff</dt><dd><?= pl_e((string) $document['sales_staff']['name']) ?></dd><?php endif; ?>
            <?php if ($document['area'] !== null): ?><dt>Area / route</dt><dd><?= pl_e((string) $document['area']['name']) ?></dd><?php endif; ?>
            <?php if ((string) $invoice['terms'] !== ''): ?><dt>Terms</dt><dd><?= pl_e((string) $invoice['terms']) ?></dd><?php endif; ?>
            <?php if ((string) $invoice['reference'] !== ''): ?><dt>Reference</dt><dd><?= pl_e((string) $invoice['reference']) ?></dd><?php endif; ?>
        </dl>
    </div>
</section>
<?php if ($invoice['payment_status'] === 'reversed'): ?>
<p class="print-notice">This document has been reversed by a linked entry. It is kept for the record.</p>
<?php endif; ?>
<table class="print-lines">
    <caption><?= $isCredit ? 'Credited items' : 'Items invoiced' ?></caption>
    <thead><tr>
        <th scope="col">Item</th>
        <th scope="col" class="print-num">Packs</th>
        <th scope="col" class="print-num">Units</th>
        <th scope="col" class="print-num">Unit price</th>
        <th scope="col" class="print-num">Disc %</th>
        <th scope="col" class="print-num">Tax %</th>
        <th scope="col" class="print-num">Amount (<?= pl_e((string) $invoice['currency']) ?>)</th>
    </tr></thead>
    <tbody>
    <?php foreach ($invoice['lines'] as $line): ?>
        <tr<?= $line['is_free_goods'] ? ' class="print-line-free"' : '' ?>>
            <th scope="row"><?= pl_e((string) $line['description']) ?><?= $line['is_free_goods'] ? ' — free goods' : '' ?></th>
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
<?php if ($document['tax_summary'] !== []): ?>
<table class="print-lines">
    <caption>Tax summary by rate</caption>
    <thead><tr><th scope="col">Rate</th><th scope="col" class="print-num">Taxable</th><th scope="col" class="print-num">Tax</th></tr></thead>
    <tbody>
    <?php foreach ($document['tax_summary'] as $band): ?>
        <tr>
            <th scope="row"><?= pl_e($band['label'] !== '' ? $band['label'] : 'Exempt or zero-rated') ?> · <?= pl_e(pl_money($band['rate'])) ?>%</th>
            <td class="print-num"><?= pl_e(pl_money($band['taxable'])) ?></td>
            <td class="print-num"><?= pl_e(pl_money($band['tax'])) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
<section class="print-totals">
    <?php $gross = bcadd((string) $invoice['subtotal'], (string) $invoice['discount_total'], 4); ?>
    <div class="print-total-row"><span>Subtotal</span><span class="print-num"><?= pl_e(pl_money($gross)) ?></span></div>
    <?php if (bccomp((string) $invoice['discount_total'], '0', 4) > 0): ?>
        <div class="print-total-row"><span>Line discounts</span><span class="print-num">−<?= pl_e(pl_money((string) $invoice['discount_total'])) ?></span></div>
    <?php endif; ?>
    <div class="print-total-row"><span>Tax</span><span class="print-num"><?= pl_e(pl_money((string) $invoice['tax_total'])) ?></span></div>
    <div class="print-total-row print-total-grand"><span>Total (<?= pl_e((string) $invoice['currency']) ?>)</span><span class="print-num"><?= pl_e(pl_money((string) $invoice['total'])) ?></span></div>
    <?php if (bccomp((string) $invoice['cash_received'], '0', 4) > 0): ?>
        <div class="print-total-row"><span>Cash received</span><span class="print-num">−<?= pl_e(pl_money((string) $invoice['cash_received'])) ?></span></div>
        <div class="print-total-row"><span>Balance due</span><span class="print-num"><?= pl_e(pl_money((string) $document['balance_due'])) ?></span></div>
    <?php endif; ?>
    <?php if (bccomp((string) $invoice['free_tax_total'], '0', 4) > 0): ?>
        <div class="print-total-row"><span>Output tax on free goods, borne by us</span><span class="print-num"><?= pl_e(pl_money((string) $invoice['free_tax_total'])) ?></span></div>
    <?php endif; ?>
</section>
<?php if ((string) $invoice['notes'] !== ''): ?>
<section class="print-parties"><div class="print-party"><h2>Terms &amp; notes</h2><p><?= pl_e((string) $invoice['notes']) ?></p></div></section>
<?php endif; ?>
<section class="print-signoff">
    <div class="print-signature">Prepared by</div>
    <div class="print-signature">Received in good order</div>
</section>
