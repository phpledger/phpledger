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
    <div class="print-roll-row"><span><?= pl_e($isReceipt ? pl_t('Received from') : pl_t('Paid to')) ?></span><span><?= pl_e((string) $receipt['party']['legal_name']) ?></span></div>
    <div class="print-roll-row"><span><?= pl_e(pl_t('Date')) ?></span><span><?= pl_e(pl_date_label((string) $receipt['journal']['journal_date'])) ?></span></div>
    <?php if ($receipt['bank'] !== null): ?><div class="print-roll-row"><span><?= pl_e($isReceipt ? pl_t('Into') : pl_t('From')) ?></span><span><?= pl_e($receipt['bank']['code']) ?></span></div><?php endif; ?>
    <div class="print-roll-row"><span><?= pl_e(pl_t('Reference')) ?></span><span><?= pl_e((string) $receipt['journal']['description']) ?></span></div>
    <hr class="print-roll-rule">
    <?php if ($receipt['reversed'] || $receipt['is_reversal']): ?>
        <p class="print-notice"><?= pl_e($receipt['is_reversal'] ? pl_t('Linked reversal of an earlier payment.') : pl_t('Reversed by a linked entry.')) ?></p>
        <hr class="print-roll-rule">
    <?php endif; ?>
    <?php foreach ($receipt['allocations'] as $allocation): ?>
        <div class="print-roll-item">
            <div class="print-roll-item-name"><?= pl_e((string) $allocation['source_reference']) ?></div>
            <div class="print-roll-row"><span><?= pl_e(pl_t('Item {id}', ['id' => (int) $allocation['item_id']])) ?></span><span><?= pl_e(pl_money((string) $allocation['amount_fc'])) ?></span></div>
        </div>
    <?php endforeach; ?>
    <hr class="print-roll-rule">
    <div class="print-roll-row print-roll-grand"><span><?= pl_e(pl_t('TOTAL {currency}', ['currency' => (string) $receipt['currency']])) ?></span><span><?= pl_e(pl_money((string) $receipt['allocated_fc'])) ?></span></div>
    <div class="print-roll-row"><span><?= pl_e(pl_t('Book amount ({currency})', ['currency' => $letterhead['currency']])) ?></span><span><?= pl_e(pl_money($receipt['bank'] === null ? '0' : $receipt['bank']['amount_base'])) ?></span></div>
    <p class="print-roll-note"><?= pl_e(pl_t('No amount is left unallocated.')) ?><br><?= pl_e($isReceipt ? pl_t('Thank you for your payment.') : pl_t('Retain this slip with the supplier record.')) ?></p>
</section>
