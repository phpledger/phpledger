<?php declare(strict_types=1);
/*
 * A4 stock document with its gate pass (1.2 M4; research decision 7: A4 for the
 * warehouse's own copy). Layout from
 * docs/design/1.2-2026-09/forms-and-reports/stock-issue-gate-pass.html.
 *
 * @var array $document Read-only stock document from pl_stock_document_print().
 */
$stock = $document;
$from = $stock['from_warehouse'];
$to = $stock['to_warehouse'];
$van = $to !== null && $to['kind'] === 'mobile' ? $to : ($from !== null && $from['kind'] === 'mobile' ? $from : null);
$pass = $stock['gate_passes'][0] ?? null;
?>
<section class="print-parties">
    <div class="print-party">
        <h2>From</h2>
        <p class="print-party-name"><?= pl_e($from === null ? '—' : $from['name']) ?></p>
        <p><?= pl_e($from === null ? '' : $from['code']) ?></p>
    </div>
    <div class="print-party">
        <h2>To</h2>
        <p class="print-party-name"><?= pl_e($to === null ? '—' : $to['name']) ?></p>
        <p><?= pl_e($to === null ? '' : $to['code']) ?></p>
        <?php if ($van !== null): ?>
            <p><?= pl_e(trim(((string) ($van['driver_name'] ?? '')).' · '.((string) ($van['vehicle_reference'] ?? '')).' · '.((string) ($van['route_name'] ?? '')), ' ·')) ?></p>
        <?php endif; ?>
    </div>
    <div class="print-party">
        <h2>Document</h2>
        <dl class="print-facts">
            <dt>Dated</dt><dd><?= pl_e(pl_date_label((string) $stock['document_date'])) ?></dd>
            <dt>Kind</dt><dd><?= pl_e((string) $stock['label']) ?></dd>
            <?php if ($stock['reference'] !== ''): ?><dt>Reference</dt><dd><?= pl_e((string) $stock['reference']) ?></dd><?php endif; ?>
            <dt>Lines</dt><dd><?= count($stock['lines']) ?></dd>
        </dl>
    </div>
</section>
<table class="print-lines">
    <caption>Items moved by this document</caption>
    <thead><tr><th scope="col">Code</th><th scope="col">Item</th><th scope="col">Unit</th><th scope="col" class="print-num">Quantity</th><th scope="col" class="print-num">Value at sale (<?= pl_e($letterhead['currency']) ?>)</th></tr></thead>
    <tbody>
    <?php foreach ($stock['lines'] as $line): ?>
        <tr>
            <th scope="row"><?= pl_e((string) $line['sku']) ?></th>
            <td><?= pl_e((string) $line['product_name']) ?></td>
            <td><?= pl_e((string) $line['base_unit']) ?></td>
            <td class="print-num"><?= pl_e((string) $line['quantity']) ?></td>
            <td class="print-num"><?= pl_e(pl_money((string) $line['sale_value'])) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr><th scope="row" colspan="3">Total</th><td class="print-num"><?= pl_e((string) $stock['total_quantity']) ?></td><td class="print-num"><?= pl_e(pl_money((string) $stock['total_sale_value'])) ?></td></tr>
    </tfoot>
</table>
<p class="print-notice">This is an internal transfer between the company's own stock locations, not a sale. No invoice value is realised until the goods are sold. No ledger entry is written by this document.</p>
<?php if ($pass !== null): ?>
<section class="print-parties">
    <div class="print-party">
        <h2>Gate pass <?= pl_e((string) $pass['document_number']) ?></h2>
        <dl class="print-facts">
            <dt>Direction</dt><dd><?= $pass['gate_pass']['direction'] === 'out' ? 'Goods leaving' : 'Goods entering' ?></dd>
            <dt>Returnable</dt><dd><?= $pass['gate_pass']['is_returnable'] ? 'Yes' : 'No' ?></dd>
            <?php if ($pass['gate_pass']['expected_return_date'] !== null): ?><dt>Expected back</dt><dd><?= pl_e(pl_date_label((string) $pass['gate_pass']['expected_return_date'])) ?></dd><?php endif; ?>
            <?php if ($pass['gate_pass']['vehicle_reference'] !== ''): ?><dt>Vehicle</dt><dd><?= pl_e((string) $pass['gate_pass']['vehicle_reference']) ?></dd><?php endif; ?>
            <?php if ($pass['gate_pass']['driver_name'] !== ''): ?><dt>Driver</dt><dd><?= pl_e((string) $pass['gate_pass']['driver_name']) ?></dd><?php endif; ?>
        </dl>
    </div>
    <div class="print-party">
        <h2>Security</h2>
        <p><?= pl_e((string) $pass['gate_pass']['purpose']) ?></p>
        <p>Verify the item count against this document before the vehicle leaves the yard.</p>
    </div>
</section>
<?php endif; ?>
<section class="print-signoff">
    <div class="print-signature">Issued by (store keeper)</div>
    <div class="print-signature">Received by (driver or salesman)</div>
    <div class="print-signature">Gate security</div>
</section>
