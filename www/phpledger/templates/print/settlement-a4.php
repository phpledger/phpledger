<?php declare(strict_types=1);
/** @var array $document Read-only settlement data from pl_settlement_receipt(). */
$receipt = $document;
$isReceipt = $receipt['direction'] === 'receivable';
$party = $receipt['party'];
$addressLines = pl_print_party_lines($party['details']['addresses'] ?? null);
$registrationLines = pl_print_party_lines($party['details']['registrations'] ?? null);
?>
<section class="print-parties">
    <div class="print-party">
        <h2><?= $isReceipt ? 'Received from' : 'Paid to' ?></h2>
        <p class="print-party-name"><?= pl_e((string) $party['legal_name']) ?></p>
        <?php foreach ($addressLines as $line): ?><p><?= pl_e($line) ?></p><?php endforeach; ?>
        <?php foreach ($registrationLines as $line): ?><p class="print-party-registration"><?= pl_e($line) ?></p><?php endforeach; ?>
    </div>
    <div class="print-party">
        <h2>Payment details</h2>
        <dl class="print-facts">
            <dt>Date</dt><dd><?= pl_e(pl_date_label((string) $receipt['journal']['journal_date'])) ?></dd>
            <dt>Currency</dt><dd><?= pl_e((string) $receipt['currency']) ?></dd>
            <?php if ($receipt['bank'] !== null): ?><dt><?= $isReceipt ? 'Received into' : 'Paid from' ?></dt><dd><?= pl_e($receipt['bank']['code'] . ' — ' . $receipt['bank']['name']) ?></dd><?php endif; ?>
            <dt>Reference</dt><dd><?= pl_e((string) $receipt['journal']['description']) ?></dd>
        </dl>
    </div>
</section>
<?php if ($receipt['reversed'] || $receipt['is_reversal']): ?>
<p class="print-notice"><?= $receipt['is_reversal'] ? 'This document records a linked reversal of an earlier payment.' : 'This payment has been reversed by a linked entry. It is kept for the record.' ?></p>
<?php endif; ?>
<table class="print-lines">
    <caption><?= $isReceipt ? 'Invoices settled by this receipt' : 'Bills settled by this payment' ?></caption>
    <thead><tr><th scope="col">Open item</th><th scope="col">Source reference</th><th scope="col" class="print-num">Allocated (<?= pl_e((string) $receipt['currency']) ?>)</th></tr></thead>
    <tbody>
    <?php foreach ($receipt['allocations'] as $allocation): ?>
        <tr>
            <th scope="row">Item <?= (int) $allocation['item_id'] ?></th>
            <td><?= pl_e((string) $allocation['source_reference']) ?></td>
            <td class="print-num"><?= pl_e(pl_money((string) $allocation['amount_fc'])) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr><th scope="row" colspan="2">Total allocated</th><td class="print-num"><?= pl_e(pl_money((string) $receipt['allocated_fc'])) ?></td></tr>
    </tfoot>
</table>
<section class="print-totals">
    <div class="print-total-row"><span><?= $isReceipt ? 'Amount received' : 'Amount paid' ?> (<?= pl_e((string) $receipt['currency']) ?>)</span><span class="print-num"><?= pl_e(pl_money((string) $receipt['allocated_fc'])) ?></span></div>
    <?php if ($receipt['bank'] !== null && $receipt['bank']['currency'] !== $receipt['currency']): ?>
        <div class="print-total-row"><span>Bank amount (<?= pl_e($receipt['bank']['currency']) ?>)</span><span class="print-num"><?= pl_e(pl_money($receipt['bank']['amount_fc'])) ?></span></div>
    <?php endif; ?>
    <div class="print-total-row"><span>Book amount (<?= pl_e($letterhead['currency']) ?>)</span><span class="print-num"><?= pl_e(pl_money($receipt['bank'] === null ? '0' : $receipt['bank']['amount_base'])) ?></span></div>
    <div class="print-total-row print-total-grand"><span>No amount is left unallocated</span><span class="print-num"><?= pl_e(pl_money((string) $receipt['allocated_fc'])) ?></span></div>
</section>
<section class="print-signoff">
    <div class="print-signature"><?= $isReceipt ? 'Received by' : 'Authorised by' ?></div>
    <div class="print-signature"><?= $isReceipt ? 'Customer acknowledgement' : 'Supplier acknowledgement' ?></div>
</section>
