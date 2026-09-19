<?php declare(strict_types=1); $submitted=$form['input']; $v=($submitted['action']??'')==='product'?$submitted:($product??[]);
$write=$enabled&&pl_can_write($company); $accountOptions=pl_starter_options(array_filter($accounts,static fn(array $a):bool=>!in_array($a['role'],['receivables','payables'],true)));
$stockProducts=array_values(array_filter($products,static fn(array $p):bool=>$p['kind']==='stock'&&$p['is_active']));
$warehouseOptions=[]; foreach ($warehouses as $w) { if ($w['is_active']) { $warehouseOptions[(string)$w['id']]=$w['code'].' · '.$w['name']; } } $warehouseById=array_column($warehouses,null,'id');
$productInput=($submitted['action']??'')==='product'?$submitted:[];
$openingInput=($submitted['action']??'')==='opening_preview'?$submitted:[];
$confirmInput=($submitted['action']??'')==='opening_confirm'?$submitted:[]; ?>
<?php if ($product || isset($_GET['new']) || $productInput) { require dirname(__DIR__).'/partials/ui/product-editor.php'; return; } ?>
<?php require dirname(__DIR__).'/partials/ui/product-list.php'; ?>
