<?php declare(strict_types=1);
$write = $enabled && pl_can_write($company);
$submitted=$form['input'];
$saveInput=($submitted['action']??'')==='save'?$submitted:[];
$confirmInput=($submitted['action']??'')==='confirm'?$submitted:[];
$cancelInput=($submitted['action']??'')==='cancel'?$submitted:[];
$receiveInput=($submitted['action']??'')==='receive'?$submitted:[];
$billInput=($submitted['action']??'')==='bill_preview'?$submitted:($preview['input']??[]);
$billConfirmInput=($submitted['action']??'')==='bill_confirm'?$submitted:[];
$v=$saveInput?:($order??[]);
$receiveQuantities=[];
foreach ($receiveInput['lines']??[] as $line) { $receiveQuantities[(int)($line['order_line_id']??0)]=$line['quantity']??''; }
$billLines=[];
foreach ($billInput['lines']??[] as $line) { $billLines[(int)($line['receipt_line_id']??0)]=$line; }
$hasUnreceived=$order && array_filter($order['lines'],static fn(array $line):bool=>bccomp($line['remaining_quantity'],'0',4)>0)!==[];
$editing = isset($_GET['new']) || ($order && $order['status'] === 'draft') || (($v['action'] ?? '') === 'save');
$supplierOptions = pl_starter_options(array_filter($parties, static fn(array $p): bool => (bool) $p['is_vendor']), 'legal_name');
$productOptions = pl_starter_options(array_filter($products, static fn(array $p): bool => $p['kind'] === 'stock'));
$grniOptions = pl_starter_options(array_filter($accounts, static fn(array $a): bool => $a['type'] === 'liability' && !in_array($a['role'], ['payables', 'receivables', 'cash_bank'], true)));
$varianceOptions = pl_starter_options(array_filter($accounts, static fn(array $a): bool => in_array($a['type'], ['expense', 'income'], true)));
$roundingOptions = pl_starter_options(array_filter($accounts, static fn(array $a): bool => $a['type'] === 'expense'));
$receiptRows = array_values(array_filter($receipts, static fn(array $r): bool => !$order || (int) $r['order_id'] === $order['id']));
$billRows = array_values(array_filter($receiptRows, static fn(array $r): bool => bccomp($r['unbilled_quantity'], '0', 4) > 0));
if ($editing && $write) { require dirname(__DIR__).'/partials/ui/purchase-order-editor.php'; return; }
?>
<div class="flex flex-col gap-4 py-5">
<?php if ($form['message']!==''): ?><div class="alert alert-danger" role="alert" tabindex="-1" data-form-error><?= pl_e($form['message']) ?><p><?= pl_e(pl_t('Your entered values are retained.')) ?></p></div><?php endif; ?>
<?php if (!$enabled): ?><div class="alert alert-info"><?= pl_e(pl_t('Purchasing is disabled for new operations. Orders, receipt history and reconciliation remain available.')) ?> <a class="link" href="<?= pl_e(pl_url('/modules')) ?>"><?= pl_e(pl_t('Review modules')) ?></a>.</div><?php endif; ?>
<?php if ($order) { require dirname(__DIR__).'/partials/ui/purchase-order-record.php'; } else { require dirname(__DIR__).'/partials/ui/purchase-order-list.php'; }
require dirname(__DIR__).'/partials/ui/purchase-operations.php'; ?>
</div>
