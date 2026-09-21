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
        <h2><?= pl_e(pl_t('From')) ?></h2>
        <p class="print-party-name"><?= pl_e($from === null ? '—' : $from['name']) ?></p>
        <p><?= pl_e($from === null ? '' : $from['code']) ?></p>
    </div>
    <div class="print-party">
        <h2><?= pl_e(pl_t('To')) ?></h2>
        <p class="print-party-name"><?= pl_e($to === null ? '—' : $to['name']) ?></p>
        <p><?= pl_e($to === null ? '' : $to['code']) ?></p>
        <?php if ($van !== null): ?>
            <p><?= pl_e(trim(((string) ($van['driver_name'] ?? '')).' · '.((string) ($van['vehicle_reference'] ?? '')).' · '.((string) ($van['route_name'] ?? '')), ' ·')) ?></p>
        <?php endif; ?>
    </div>
    <div class="print-party">
        <h2><?= pl_e(pl_t('Document')) ?></h2>
        <dl class="print-facts">
            <dt><?= pl_e(pl_t('Dated')) ?></dt><dd><?= pl_e(pl_date_label((string) $stock['document_date'])) ?></dd>
            <dt><?= pl_e(pl_t('Kind')) ?></dt><dd><?= pl_e((string) $stock['label']) ?></dd>
            <?php if ($stock['reference'] !== ''): ?><dt><?= pl_e(pl_t('Reference')) ?></dt><dd><?= pl_e((string) $stock['reference']) ?></dd><?php endif; ?>
            <dt><?= pl_e(pl_t('Lines')) ?></dt><dd><?= count($stock['lines']) ?></dd>
        </dl>
    </div>
</section>
<table class="print-lines">
    <caption><?= pl_e(pl_t('Items moved by this document')) ?></caption>
    <thead><tr><th scope="col"><?= pl_e(pl_t('Code')) ?></th><th scope="col"><?= pl_e(pl_t('Item')) ?></th><th scope="col"><?= pl_e(pl_t('Unit')) ?></th><th scope="col" class="print-num"><?= pl_e(pl_t('Quantity')) ?></th><th scope="col" class="print-num"><?= pl_e(pl_t('Value at sale ({currency})',['currency'=>$letterhead['currency']])) ?></th></tr></thead>
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
        <tr><th scope="row" colspan="3"><?= pl_e(pl_t('Total')) ?></th><td class="print-num"><?= pl_e((string) $stock['total_quantity']) ?></td><td class="print-num"><?= pl_e(pl_money((string) $stock['total_sale_value'])) ?></td></tr>
    </tfoot>
</table>
<p class="print-notice"><?= pl_e(pl_t("This is an internal transfer between the company's own stock locations, not a sale. No invoice value is realised until the goods are sold. No ledger entry is written by this document.")) ?></p>
<?php if ($pass !== null): ?>
<section class="print-parties">
    <div class="print-party">
        <h2><?= pl_e(pl_t('Gate pass {number}',['number'=>(string) $pass['document_number']])) ?></h2>
        <dl class="print-facts">
            <dt><?= pl_e(pl_t('Direction')) ?></dt><dd><?= pl_e($pass['gate_pass']['direction'] === 'out' ? pl_t('Goods leaving') : pl_t('Goods entering')) ?></dd>
            <dt><?= pl_e(pl_t('Returnable')) ?></dt><dd><?= pl_e($pass['gate_pass']['is_returnable'] ? pl_t('Yes') : pl_t('No')) ?></dd>
            <?php if ($pass['gate_pass']['expected_return_date'] !== null): ?><dt><?= pl_e(pl_t('Expected back')) ?></dt><dd><?= pl_e(pl_date_label((string) $pass['gate_pass']['expected_return_date'])) ?></dd><?php endif; ?>
            <?php if ($pass['gate_pass']['vehicle_reference'] !== ''): ?><dt><?= pl_e(pl_t('Vehicle')) ?></dt><dd><?= pl_e((string) $pass['gate_pass']['vehicle_reference']) ?></dd><?php endif; ?>
            <?php if ($pass['gate_pass']['driver_name'] !== ''): ?><dt><?= pl_e(pl_t('Driver')) ?></dt><dd><?= pl_e((string) $pass['gate_pass']['driver_name']) ?></dd><?php endif; ?>
        </dl>
    </div>
    <div class="print-party">
        <h2><?= pl_e(pl_t('Security')) ?></h2>
        <p><?= pl_e((string) $pass['gate_pass']['purpose']) ?></p>
        <p><?= pl_e(pl_t('Verify the item count against this document before the vehicle leaves the yard.')) ?></p>
    </div>
</section>
<?php endif; ?>
<section class="print-signoff">
    <div class="print-signature"><?= pl_e(pl_t('Issued by (store keeper)')) ?></div>
    <div class="print-signature"><?= pl_e(pl_t('Received by (driver or salesman)')) ?></div>
    <div class="print-signature"><?= pl_e(pl_t('Gate security')) ?></div>
</section>
