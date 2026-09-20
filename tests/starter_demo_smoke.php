<?php
declare(strict_types=1);
ob_start();
if (PHP_SAPI !== 'cli' || getenv('PL_ENV') !== 'demo' || getenv('PL_DB_HOST') !== 'db_test' || getenv('PL_DB_NAME') !== 'phpledger_demo' || getenv('PL_DB_USER') !== 'ledger_demo_test') {
    throw new RuntimeException('Starter demo checks require only the restricted isolated demo account in db_test.');
}
require dirname(__DIR__) . '/www/phpledger/includes/bootstrap.php';
$checks = 0;
function starter_demo_check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    ++$checks;
}
function starter_demo_denied(callable $work, string $message): void
{
    try { $work(); }
    catch (DomainException $error) {
        starter_demo_check(str_contains($error->getMessage(), $message), 'Unexpected demo denial: ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected demo denial: ' . $message);
}

pl_session_start(false);
$_SERVER['REQUEST_METHOD'] = 'POST';
$limitBefore = getenv('PL_DEMO_MAX_DOCUMENTS');
putenv('PL_DEMO_MAX_DOCUMENTS=500');
try {
    $visit = pl_demo_begin_visit(pl_csrf_token(), 'USD', 'accounting-starter');
    $actor = (int) $visit['user']['id']; $company = (int) $visit['company_id']; $book = (int) $visit['book_id'];
    $context = pl_company_context($actor, $company);
    $accounts = pl_account_code_mapping($context['accounts']);
    $party = (int) DB::queryFirstField('SELECT id FROM pl_parties WHERE company_id=%i AND legal_name=%s', $company, 'Sample customer and supplier');
    $product = (int) DB::queryFirstField('SELECT id FROM pl_products WHERE company_id=%i AND book_id=%i AND sku=%s', $company, $book, 'SAMPLE-GOODS');
    $date = gmdate('Y') . '-01-05';
    starter_demo_check($party > 0 && $product > 0, 'Starter practice data was not provisioned.');
    foreach (DB::queryFirstColumn('SHOW GRANTS FOR CURRENT_USER') as $grant) {
        starter_demo_check(!preg_match('/\b(ALL PRIVILEGES|DELETE|CREATE|ALTER|DROP|GRANT OPTION)\b/i', $grant), 'Demo grants were broadened.');
    }
    $key = 'demo-starter:' . bin2hex(random_bytes(12));
    $input = ['kind'=>'invoice','party_id'=>$party,'date'=>$date,'due_date'=>$date,'currency'=>'USD','reference'=>'Sample restricted invoice','creation_key'=>$key . ':ar',
        'lines'=>[['description'=>'Sample service','quantity'=>'1','unit_price'=>'100','account_id'=>(int)$accounts['4000']]]];
    $draft = pl_save_ar_document($actor,$company,$book,$input);
    $lineId = $draft['lines'][0]['id'];
    $edit = $input; $edit['lines'][0]['unit_price'] = '110';
    $draft = pl_save_ar_document($actor,$company,$book,$edit,$draft['id'],$draft['revision']);
    starter_demo_check($draft['lines'][0]['id'] === $lineId && $draft['total'] === '110.0000', 'Same-count AR draft edit failed under restricted grants.');
    $edit['lines'][] = ['description'=>'Additional service','quantity'=>'1','unit_price'=>'10','account_id'=>(int)$accounts['4000']];
    $draft = pl_save_ar_document($actor,$company,$book,$edit,$draft['id'],$draft['revision']);
    starter_demo_check(count($draft['lines']) === 2 && $draft['total'] === '120.0000', 'Growing AR draft edit failed under restricted grants.');
    starter_demo_denied(fn()=>pl_save_ar_document($actor,$company,$book,$input,$draft['id'],$draft['revision']), 'cannot remove draft lines');
    starter_demo_check(pl_get_ar_document($actor,$company,$book,$draft['id'])['total'] === '120.0000', 'Rejected line removal changed the draft.');
    $posted = pl_post_ar_document($actor,$company,$book,$draft['id'],$draft['revision']);
    $payment = ['bank_account_id'=>(int)$accounts['1000'],'gain_account_id'=>(int)$accounts['4000'],'loss_account_id'=>(int)$accounts['5000'],
        'amount_fc'=>'10','date'=>$date,'description'=>'Sample partial payment','idempotency_key'=>$key . ':payment'];
    $paid = pl_settle_ar_document($actor,$company,$book,$draft['id'],$payment);
    starter_demo_check(pl_get_ar_document($actor,$company,$book,$draft['id'])['outstanding_fc'] === '110.0000', 'Restricted demo partial payment failed.');

    $orderInput = ['party_id'=>$party,'date'=>$date,'currency'=>'USD','reference'=>'Sample restricted order','creation_key'=>$key . ':po',
        'lines'=>[['product_id'=>$product,'description'=>'Sample goods','quantity'=>'10','unit_price'=>'5']]];
    $order = pl_save_purchase_order($actor,$company,$book,$orderInput);
    $orderLineId = $order['lines'][0]['id'];
    $orderEdit = $orderInput; $orderEdit['creation_key'] = $key . ':po-edit'; $orderEdit['lines'][0]['unit_price'] = '6';
    $order = pl_save_purchase_order($actor,$company,$book,$orderEdit,$order['id'],$order['revision']);
    starter_demo_check($order['lines'][0]['id'] === $orderLineId && $order['total'] === '60.0000', 'PO edit required forbidden DELETE privileges.');
    $order = pl_confirm_purchase_order($actor,$company,$book,$order['id'],$order['revision'],$key . ':po-confirm');
    $receiptInput = ['date'=>$date,'grni_account_id'=>(int)$accounts['2100'],'idempotency_key'=>$key . ':po-receive','lines'=>[['order_line_id'=>$orderLineId,'quantity'=>'4']]];
    $receipt = pl_receive_purchase_order($actor,$company,$book,$order['id'],$receiptInput);
    starter_demo_check(pl_inventory_balance($actor,$company,$book,$product)['quantity'] === '4.0000', 'Restricted purchase receipt did not update shared stock.');
    $stockInput = ['product_id'=>$product,'date'=>$date,'quantity'=>'2','amount_base'=>'12','offset_account_id'=>(int)$accounts['5000'],
        'source_type'=>'manual_stock','source_reference'=>$key . ':stock','reason'=>'Sample stock adjustment','idempotency_key'=>$key . ':stock'];
    $stock = pl_inventory_receive($actor,$company,$book,$stockInput);
    starter_demo_check(pl_inventory_balance($actor,$company,$book,$product)['quantity'] === '6.0000', 'Restricted manual stock receipt failed.');

    // Three stock lines produce three durable stock commands plus the AR posting receipt.
    $compoundInput = $input; $compoundInput['creation_key'] = $key . ':compound'; $compoundInput['lines'] = [];
    for ($i=0;$i<3;$i++) { $compoundInput['lines'][] = ['description'=>'Sample stock sale ' . $i,'quantity'=>'1','unit_price'=>'10','account_id'=>(int)$accounts['4000'],'product_id'=>$product]; }
    $compound = pl_save_ar_document($actor,$company,$book,$compoundInput);
    while (pl_demo_document_count($company,$book)<10) {
        $padding=$input; $padding['creation_key']=$key . ':padding:' . pl_demo_document_count($company,$book); pl_save_ar_document($actor,$company,$book,$padding);
    }
    $beforeCount = pl_demo_document_count($company,$book);
    $beforeJournals = (int)DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE company_id=%i AND book_id=%i',$company,$book);
    $beforeStock = pl_inventory_balance($actor,$company,$book,$product);
    putenv('PL_DEMO_MAX_DOCUMENTS=' . ($beforeCount+3));
    starter_demo_denied(fn()=>pl_post_ar_document($actor,$company,$book,$compound['id'],$compound['revision']), 'transaction limit');
    starter_demo_check(pl_demo_document_count($company,$book)===$beforeCount && (int)DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE company_id=%i AND book_id=%i',$company,$book)===$beforeJournals
        && pl_inventory_balance($actor,$company,$book,$product)===$beforeStock && pl_get_ar_document($actor,$company,$book,$compound['id'])['journal_id']===null, 'Compound capacity failure left partial stock, journals or command receipts.');
    putenv('PL_DEMO_MAX_DOCUMENTS=' . ($beforeCount+4));
    $compoundPosted = pl_post_ar_document($actor,$company,$book,$compound['id'],$compound['revision']);
    starter_demo_check(pl_demo_document_count($company,$book)===$beforeCount+4 && pl_inventory_balance($actor,$company,$book,$product)['quantity']==='3.0000', 'Nested admission falsely blocked an action that exactly fits the limit.');
    starter_demo_check(pl_post_ar_document($actor,$company,$book,$compound['id'],$compound['revision'])===$compoundPosted, 'Posting retry was blocked at capacity.');
    starter_demo_check(pl_post_ar_document($actor,$company,$book,$draft['id'],$draft['revision'])===$posted, 'Earlier posting retry was blocked at capacity.');
    starter_demo_check(pl_settle_ar_document($actor,$company,$book,$draft['id'],$payment)===$paid, 'Settlement retry was blocked at capacity.');
    starter_demo_check(pl_inventory_receive($actor,$company,$book,$stockInput)==$stock, 'Stock retry was blocked at capacity.');
    starter_demo_check(pl_receive_purchase_order($actor,$company,$book,$order['id'],$receiptInput)==$receipt, 'Purchasing retry was blocked at capacity.');
    starter_demo_check(pl_save_ar_document($actor,$company,$book,$input)['id']===$draft['id'], 'AR creation retry was blocked at capacity.');
    starter_demo_check(pl_save_purchase_order($actor,$company,$book,$orderInput)['id']===$order['id'], 'PO creation retry was blocked at capacity.');
    $newAr=$input; $newAr['creation_key']=$key . ':overflow-ar';
    starter_demo_denied(fn()=>pl_save_ar_document($actor,$company,$book,$newAr),'transaction limit');
    $newAp=$newAr; $newAp['kind']='bill'; $newAp['creation_key']=$key . ':overflow-ap'; $newAp['lines'][0]['account_id']=(int)$accounts['5000'];
    starter_demo_denied(fn()=>pl_save_ar_document($actor,$company,$book,$newAp),'transaction limit');
    $newPo=$orderInput; $newPo['creation_key']=$key . ':overflow-po';
    starter_demo_denied(fn()=>pl_save_purchase_order($actor,$company,$book,$newPo),'transaction limit');
    $newStock=$stockInput; $newStock['idempotency_key']=$key . ':overflow-stock';
    starter_demo_denied(fn()=>pl_inventory_receive($actor,$company,$book,$newStock),'transaction limit');
    $newPayment=$payment; $newPayment['idempotency_key']=$key . ':overflow-payment';
    starter_demo_denied(fn()=>pl_settle_open_item($actor,$company,$book,$newPayment+['item_id'=>$draft['open_item_id']??$posted['open_item_id']]),'transaction limit');
    starter_demo_check(pl_demo_document_count($company,$book)===$beforeCount+4 && pl_trial_balance($actor,$company,$book)['balanced'], 'Capacity denials changed the books.');
    starter_demo_denied(fn()=>pl_save_inventory_product($actor,$company,$book,[]), 'disabled');
    starter_demo_denied(fn()=>pl_activate_open_item_account($actor,$company,$book,(int)$accounts['1100'],'Sample forbidden activation'), 'disabled');
    echo "Starter restricted demo smoke: {$checks} checks passed; AR/PO draft edits, partial payment, goods receipt, stock, exact retries at capacity, atomic compound limit rollback and unchanged administrative restrictions.\n";
} finally {
    putenv($limitBefore === false ? 'PL_DEMO_MAX_DOCUMENTS' : 'PL_DEMO_MAX_DOCUMENTS=' . $limitBefore);
}
