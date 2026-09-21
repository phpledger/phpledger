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
        <h2><?= pl_e($isReceipt ? pl_t('Received from') : pl_t('Paid to')) ?></h2>
        <p class="print-party-name"><?= pl_e((string) $party['legal_name']) ?></p>
        <?php foreach ($addressLines as $line): ?><p><?= pl_e($line) ?></p><?php endforeach; ?>
        <?php foreach ($registrationLines as $line): ?><p class="print-party-registration"><?= pl_e($line) ?></p><?php endforeach; ?>
    </div>
    <div class="print-party">
        <h2><?= pl_e(pl_t('Payment details')) ?></h2>
        <dl class="print-facts">
            <dt><?= pl_e(pl_t('Date')) ?></dt><dd><?= pl_e(pl_date_label((string) $receipt['journal']['journal_date'])) ?></dd>
            <dt><?= pl_e(pl_t('Currency')) ?></dt><dd><?= pl_e((string) $receipt['currency']) ?></dd>
            <?php if ($receipt['bank'] !== null): ?><dt><?= pl_e($isReceipt ? pl_t('Received into') : pl_t('Paid from')) ?></dt><dd><?= pl_e($receipt['bank']['code'] . ' — ' . $receipt['bank']['name']) ?></dd><?php endif; ?>
            <dt><?= pl_e(pl_t('Reference')) ?></dt><dd><?= pl_e((string) $receipt['journal']['description']) ?></dd>
        </dl>
    </div>
</section>
<?php if ($receipt['reversed'] || $receipt['is_reversal']): ?>
<p class="print-notice"><?= pl_e($receipt['is_reversal'] ? pl_t('This document records a linked reversal of an earlier payment.') : pl_t('This payment has been reversed by a linked entry. It is kept for the record.')) ?></p>
<?php endif; ?>
<table class="print-lines">
    <caption><?= pl_e($isReceipt ? pl_t('Invoices settled by this receipt') : pl_t('Bills settled by this payment')) ?></caption>
    <thead><tr><th scope="col"><?= pl_e(pl_t('Open item')) ?></th><th scope="col"><?= pl_e(pl_t('Source reference')) ?></th><th scope="col" class="print-num"><?= pl_e(pl_t('Allocated ({currency})', ['currency' => (string) $receipt['currency']])) ?></th></tr></thead>
    <tbody>
    <?php foreach ($receipt['allocations'] as $allocation): ?>
        <tr>
            <th scope="row"><?= pl_e(pl_t('Item {id}', ['id' => (int) $allocation['item_id']])) ?></th>
            <td><?= pl_e((string) $allocation['source_reference']) ?></td>
            <td class="print-num"><?= pl_e(pl_money((string) $allocation['amount_fc'])) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr><th scope="row" colspan="2"><?= pl_e(pl_t('Total allocated')) ?></th><td class="print-num"><?= pl_e(pl_money((string) $receipt['allocated_fc'])) ?></td></tr>
    </tfoot>
</table>
<section class="print-totals">
    <div class="print-total-row"><span><?= pl_e(pl_t($isReceipt ? 'Amount received ({currency})' : 'Amount paid ({currency})', ['currency' => (string) $receipt['currency']])) ?></span><span class="print-num"><?= pl_e(pl_money((string) $receipt['allocated_fc'])) ?></span></div>
    <?php if ($receipt['bank'] !== null && $receipt['bank']['currency'] !== $receipt['currency']): ?>
        <div class="print-total-row"><span><?= pl_e(pl_t('Bank amount ({currency})', ['currency' => $receipt['bank']['currency']])) ?></span><span class="print-num"><?= pl_e(pl_money($receipt['bank']['amount_fc'])) ?></span></div>
    <?php endif; ?>
    <div class="print-total-row"><span><?= pl_e(pl_t('Book amount ({currency})', ['currency' => $letterhead['currency']])) ?></span><span class="print-num"><?= pl_e(pl_money($receipt['bank'] === null ? '0' : $receipt['bank']['amount_base'])) ?></span></div>
    <div class="print-total-row print-total-grand"><span><?= pl_e(pl_t('No amount is left unallocated')) ?></span><span class="print-num"><?= pl_e(pl_money((string) $receipt['allocated_fc'])) ?></span></div>
</section>
<section class="print-signoff">
    <div class="print-signature"><?= pl_e($isReceipt ? pl_t('Received by') : pl_t('Authorised by')) ?></div>
    <div class="print-signature"><?= pl_e($isReceipt ? pl_t('Customer acknowledgement') : pl_t('Supplier acknowledgement')) ?></div>
</section>
