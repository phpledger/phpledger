<?php declare(strict_types=1);
/*
 * 80 mm counter roll. Decision 8 of the 1.2 forms and reports frames: two lines per
 * item, so the narrow roll still shows which open item each amount settled.
 *
 * @var array $document Read-only settlement data from pl_settlement_receipt().
 */
$receipt = $document;
$isReceipt = $receipt['direction'] === 'receivable';
?>
<section class="print-roll">
    <div class="print-roll-row"><span><?= $isReceipt ? 'Received from' : 'Paid to' ?></span><span><?= pl_e((string) $receipt['party']['legal_name']) ?></span></div>
    <div class="print-roll-row"><span>Date</span><span><?= pl_e(pl_date_label((string) $receipt['journal']['journal_date'])) ?></span></div>
    <?php if ($receipt['bank'] !== null): ?><div class="print-roll-row"><span><?= $isReceipt ? 'Into' : 'From' ?></span><span><?= pl_e($receipt['bank']['code']) ?></span></div><?php endif; ?>
    <div class="print-roll-row"><span>Reference</span><span><?= pl_e((string) $receipt['journal']['description']) ?></span></div>
    <hr class="print-roll-rule">
    <?php if ($receipt['reversed'] || $receipt['is_reversal']): ?>
        <p class="print-notice"><?= $receipt['is_reversal'] ? 'Linked reversal of an earlier payment.' : 'Reversed by a linked entry.' ?></p>
        <hr class="print-roll-rule">
    <?php endif; ?>
    <?php foreach ($receipt['allocations'] as $allocation): ?>
        <div class="print-roll-item">
            <div class="print-roll-item-name"><?= pl_e((string) $allocation['source_reference']) ?></div>
            <div class="print-roll-row"><span>Item <?= (int) $allocation['item_id'] ?></span><span><?= pl_e(pl_money((string) $allocation['amount_fc'])) ?></span></div>
        </div>
    <?php endforeach; ?>
    <hr class="print-roll-rule">
    <div class="print-roll-row print-roll-grand"><span>TOTAL <?= pl_e((string) $receipt['currency']) ?></span><span><?= pl_e(pl_money((string) $receipt['allocated_fc'])) ?></span></div>
    <div class="print-roll-row"><span>Book amount (<?= pl_e($letterhead['currency']) ?>)</span><span><?= pl_e(pl_money($receipt['bank'] === null ? '0' : $receipt['bank']['amount_base'])) ?></span></div>
    <p class="print-roll-note">No amount is left unallocated.<br><?= $isReceipt ? 'Thank you for your payment.' : 'Retain this slip with the supplier record.' ?></p>
</section>
