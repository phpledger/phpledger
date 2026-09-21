<?php declare(strict_types=1);
/*
 * 80 mm van-facing load list (research decision 7: 80 mm for the documents that travel
 * with the van). One block per item, so the narrow roll still names what was loaded.
 *
 * @var array $document Read-only stock document from pl_stock_document_print().
 */
$stock = $document;
$from = $stock['from_warehouse'];
$to = $stock['to_warehouse'];
$van = $to !== null && $to['kind'] === 'mobile' ? $to : ($from !== null && $from['kind'] === 'mobile' ? $from : null);
?>
<section class="print-roll">
    <div class="print-roll-row"><span><?= pl_e(pl_t('Document')) ?></span><span><?= pl_e((string) $stock['label']) ?></span></div>
    <div class="print-roll-row"><span><?= pl_e(pl_t('Dated')) ?></span><span><?= pl_e(pl_date_label((string) $stock['document_date'])) ?></span></div>
    <div class="print-roll-row"><span><?= pl_e(pl_t('From')) ?></span><span><?= pl_e($from === null ? '—' : $from['code']) ?></span></div>
    <div class="print-roll-row"><span><?= pl_e(pl_t('To')) ?></span><span><?= pl_e($to === null ? '—' : $to['code']) ?></span></div>
    <?php if ($van !== null && $van['driver_name'] !== null): ?><div class="print-roll-row"><span><?= pl_e(pl_t('Driver')) ?></span><span><?= pl_e((string) $van['driver_name']) ?></span></div><?php endif; ?>
    <?php if ($van !== null && $van['route_name'] !== null): ?><div class="print-roll-row"><span><?= pl_e(pl_t('Route')) ?></span><span><?= pl_e((string) $van['route_name']) ?></span></div><?php endif; ?>
    <?php if ($stock['reference'] !== ''): ?><div class="print-roll-row"><span><?= pl_e(pl_t('Reference')) ?></span><span><?= pl_e((string) $stock['reference']) ?></span></div><?php endif; ?>
    <hr class="print-roll-rule">
    <?php foreach ($stock['lines'] as $line): ?>
        <div class="print-roll-item">
            <div class="print-roll-item-name"><?= pl_e((string) $line['sku']) ?> · <?= pl_e((string) $line['product_name']) ?></div>
            <div class="print-roll-row"><span><?= pl_e((string) $line['base_unit']) ?></span><span><?= pl_e((string) $line['quantity']) ?></span></div>
        </div>
    <?php endforeach; ?>
    <hr class="print-roll-rule">
    <div class="print-roll-row print-roll-grand"><span><?= pl_e(pl_t('TOTAL QUANTITY')) ?></span><span><?= pl_e((string) $stock['total_quantity']) ?></span></div>
    <div class="print-roll-row"><span><?= pl_e(pl_t('Value at sale ({currency})',['currency'=>$letterhead['currency']])) ?></span><span><?= pl_e(pl_money((string) $stock['total_sale_value'])) ?></span></div>
    <p class="print-roll-note"><?= pl_e(pl_t('Internal stock movement, not a sale.')) ?><br><?= pl_e(pl_t('Check the count before the vehicle leaves.')) ?></p>
</section>
