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
        <h2>Gate</h2>
        <p class="print-party-name"><?= pl_e($gate === null ? '—' : $gate['name']) ?></p>
        <p><?= pl_e($gate === null ? '' : $gate['code']) ?></p>
    </div>
    <div class="print-party">
        <h2>Movement</h2>
        <dl class="print-facts">
            <dt>Dated</dt><dd><?= pl_e(pl_date_label((string) $document['document_date'])) ?></dd>
            <dt>Direction</dt><dd><?= $pass['direction'] === 'out' ? 'Goods leaving' : 'Goods entering' ?></dd>
            <dt>Type</dt><dd><?= $pass['is_returnable'] ? 'Returnable' : 'Non-returnable' ?></dd>
            <?php if ($pass['expected_return_date'] !== null): ?><dt>Expected back</dt><dd><?= pl_e(pl_date_label((string) $pass['expected_return_date'])) ?></dd><?php endif; ?>
            <dt>Covers</dt><dd><?= pl_e($covered === null ? '—' : (string) $covered['document_number']) ?></dd>
        </dl>
    </div>
    <div class="print-party">
        <h2>Carrier</h2>
        <dl class="print-facts">
            <?php if ($pass['party_name'] !== ''): ?><dt>Party</dt><dd><?= pl_e((string) $pass['party_name']) ?></dd><?php endif; ?>
            <?php if ($pass['vehicle_reference'] !== ''): ?><dt>Vehicle</dt><dd><?= pl_e((string) $pass['vehicle_reference']) ?></dd><?php endif; ?>
            <?php if ($pass['driver_name'] !== ''): ?><dt>Driver</dt><dd><?= pl_e((string) $pass['driver_name']) ?></dd><?php endif; ?>
            <dt>Purpose</dt><dd><?= pl_e((string) $pass['purpose']) ?></dd>
        </dl>
    </div>
</section>
<?php if ($covered !== null): ?>
<table class="print-lines">
    <caption>Items on <?= pl_e((string) $covered['document_number']) ?></caption>
    <thead><tr><th scope="col">Code</th><th scope="col">Item</th><th scope="col">Unit</th><th scope="col" class="print-num">Quantity</th></tr></thead>
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
    <tfoot><tr><th scope="row" colspan="3">Total</th><td class="print-num"><?= pl_e((string) $covered['total_quantity']) ?></td></tr></tfoot>
</table>
<?php endif; ?>
<p class="print-notice">A gate pass is a permission record. It moves no stock and posts no accounting entry: the stock document named above is the record that moved the goods. Security: verify the count against this pass before the vehicle passes the gate.</p>
<section class="print-signoff">
    <div class="print-signature">Authorised by</div>
    <div class="print-signature">Carrier</div>
    <div class="print-signature">Gate security</div>
</section>
