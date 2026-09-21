<?php declare(strict_types=1);
/*
 * 80 mm counter receipt for a sales invoice (1.2 M3; frame receipt-print-80mm.html).
 *
 * Decision 8: two lines per item, so a one-column roll still shows the calculation and
 * not only the amount.
 *
 * @var array $document Read-only invoice data from pl_trading_invoice_print().
 */
$invoice = $document['document'];
?>
<section class="print-roll">
    <div class="print-roll-row"><span>Customer</span><span><?= pl_e((string) $invoice['party']['legal_name']) ?></span></div>
    <div class="print-roll-row"><span>Date</span><span><?= pl_e(pl_date_label((string) $invoice['document_date'])) ?></span></div>
    <?php if ($document['warehouse'] !== null): ?><div class="print-roll-row"><span>Counter</span><span><?= pl_e((string) $document['warehouse']['code']) ?></span></div><?php endif; ?>
    <?php if ($document['sales_staff'] !== null): ?><div class="print-roll-row"><span>Served by</span><span><?= pl_e((string) $document['sales_staff']['name']) ?></span></div><?php endif; ?>
    <hr class="print-roll-rule">
    <?php if ($invoice['payment_status'] === 'reversed'): ?>
        <p class="print-notice">Reversed by a linked entry.</p>
        <hr class="print-roll-rule">
    <?php endif; ?>
    <?php foreach ($invoice['lines'] as $line): ?>
        <div class="print-roll-item">
            <div class="print-roll-item-name"><?= pl_e((string) $line['description']) ?><?= $line['is_free_goods'] ? ' — FREE' : '' ?></div>
            <div class="print-roll-row">
                <span><?= pl_e(pl_money((string) $line['quantity'])) ?><?= $line['is_free_goods'] ? ' bonus' : ' × ' . pl_e(pl_money((string) $line['unit_price'])) ?><?= bccomp((string) $line['discount_percent'], '0', 4) > 0 && !$line['is_free_goods'] ? ' (−' . pl_e(pl_money((string) $line['discount_percent'])) . '%)' : '' ?></span>
                <span><?= pl_e(pl_money((string) $line['line_total'])) ?></span>
            </div>
        </div>
    <?php endforeach; ?>
    <hr class="print-roll-rule">
    <?php $gross = bcadd((string) $invoice['subtotal'], (string) $invoice['discount_total'], 4); ?>
    <div class="print-roll-row"><span>Subtotal</span><span><?= pl_e(pl_money($gross)) ?></span></div>
    <?php if (bccomp((string) $invoice['discount_total'], '0', 4) > 0): ?>
        <div class="print-roll-row"><span>Discount</span><span>−<?= pl_e(pl_money((string) $invoice['discount_total'])) ?></span></div>
    <?php endif; ?>
    <div class="print-roll-row"><span>Tax</span><span><?= pl_e(pl_money((string) $invoice['tax_total'])) ?></span></div>
    <div class="print-roll-row print-roll-grand"><span>TOTAL <?= pl_e((string) $invoice['currency']) ?></span><span><?= pl_e(pl_money((string) $invoice['total'])) ?></span></div>
    <?php if (bccomp((string) $invoice['cash_received'], '0', 4) > 0): ?>
        <div class="print-roll-row"><span>Cash received</span><span><?= pl_e(pl_money((string) $invoice['cash_received'])) ?></span></div>
        <div class="print-roll-row"><span>Balance due</span><span><?= pl_e(pl_money((string) $document['balance_due'])) ?></span></div>
    <?php endif; ?>
    <p class="print-roll-note"><?= pl_e((string) $invoice['number']) ?><br>Thank you for your business.</p>
</section>
