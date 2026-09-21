<?php declare(strict_types=1);
/*
 * A4 gate copy (research decision 7). The pass lists the items of the stock document it
 * covers so the guard can count them; it moves no stock and posts nothing itself.
 *
 * @var array $document Read-only gate pass from pl_gate_pass_print().
 */
$pass = $document['gate_pass'];
$covered = $document['covers'];
$gate = $document['from_warehouse'];
?>
<section class="print-parties">
    <div class="print-party">
        <h2><?= pl_e(pl_t('Gate')) ?></h2>
        <p class="print-party-name"><?= pl_e($gate === null ? '—' : $gate['name']) ?></p>
        <p><?= pl_e($gate === null ? '' : $gate['code']) ?></p>
    </div>
    <div class="print-party">
        <h2><?= pl_e(pl_t('Movement')) ?></h2>
        <dl class="print-facts">
            <dt><?= pl_e(pl_t('Dated')) ?></dt><dd><?= pl_e(pl_date_label((string) $document['document_date'])) ?></dd>
            <dt><?= pl_e(pl_t('Direction')) ?></dt><dd><?= pl_e($pass['direction'] === 'out' ? pl_t('Goods leaving') : pl_t('Goods entering')) ?></dd>
            <dt><?= pl_e(pl_t('Type')) ?></dt><dd><?= pl_e($pass['is_returnable'] ? pl_t('Returnable') : pl_t('Non-returnable')) ?></dd>
            <?php if ($pass['expected_return_date'] !== null): ?><dt><?= pl_e(pl_t('Expected back')) ?></dt><dd><?= pl_e(pl_date_label((string) $pass['expected_return_date'])) ?></dd><?php endif; ?>
            <dt><?= pl_e(pl_t('Covers')) ?></dt><dd><?= pl_e($covered === null ? '—' : (string) $covered['document_number']) ?></dd>
        </dl>
    </div>
    <div class="print-party">
        <h2><?= pl_e(pl_t('Carrier')) ?></h2>
        <dl class="print-facts">
            <?php if ($pass['party_name'] !== ''): ?><dt><?= pl_e(pl_t('Party')) ?></dt><dd><?= pl_e((string) $pass['party_name']) ?></dd><?php endif; ?>
            <?php if ($pass['vehicle_reference'] !== ''): ?><dt><?= pl_e(pl_t('Vehicle')) ?></dt><dd><?= pl_e((string) $pass['vehicle_reference']) ?></dd><?php endif; ?>
            <?php if ($pass['driver_name'] !== ''): ?><dt><?= pl_e(pl_t('Driver')) ?></dt><dd><?= pl_e((string) $pass['driver_name']) ?></dd><?php endif; ?>
            <dt><?= pl_e(pl_t('Purpose')) ?></dt><dd><?= pl_e((string) $pass['purpose']) ?></dd>
        </dl>
    </div>
</section>
<?php if ($covered !== null): ?>
<table class="print-lines">
    <caption><?= pl_e(pl_t('Items on {number}', ['number' => (string) $covered['document_number']])) ?></caption>
    <thead><tr><th scope="col"><?= pl_e(pl_t('Code')) ?></th><th scope="col"><?= pl_e(pl_t('Item')) ?></th><th scope="col"><?= pl_e(pl_t('Unit')) ?></th><th scope="col" class="print-num"><?= pl_e(pl_t('Quantity')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($covered['lines'] as $line): ?>
        <tr>
            <th scope="row"><?= pl_e((string) $line['sku']) ?></th>
            <td><?= pl_e((string) $line['product_name']) ?></td>
            <td><?= pl_e((string) $line['base_unit']) ?></td>
            <td class="print-num"><?= pl_e((string) $line['quantity']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><th scope="row" colspan="3"><?= pl_e(pl_t('Total')) ?></th><td class="print-num"><?= pl_e((string) $covered['total_quantity']) ?></td></tr></tfoot>
</table>
<?php endif; ?>
<p class="print-notice"><?= pl_e(pl_t('A gate pass is a permission record. It moves no stock and posts no accounting entry: the stock document named above is the record that moved the goods. Security: verify the count against this pass before the vehicle passes the gate.')) ?></p>
<section class="print-signoff">
    <div class="print-signature"><?= pl_e(pl_t('Authorised by')) ?></div>
    <div class="print-signature"><?= pl_e(pl_t('Carrier')) ?></div>
    <div class="print-signature"><?= pl_e(pl_t('Gate security')) ?></div>
</section>
