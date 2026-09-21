<?php
declare(strict_types=1);

// Explicit sample fixture for local browser acceptance; refuses any other database.
if (getenv('PL_ENV')!=='test' || getenv('PL_DB_NAME')!=='phpledger_test') { fwrite(STDERR,"Use the isolated test database.\n"); exit(2); }
require_once dirname(__DIR__).'/www/phpledger/includes/bootstrap.php';
$suffix=bin2hex(random_bytes(5));
$email='starter-browser-'.$suffix.'@example.test';
$actor=pl_create_user($email,'Sample Starter Owner','Sample-browser-only-2026!');
$f=pl_create_company($actor,'Sample Starter '.$suffix,'USD','2026-01-01');
$company=$f['company_id']; $book=$f['book_id'];
$extra=[];
foreach (['inventory'=>['1300','asset'],'grni'=>['2100','liability'],'tax_in'=>['1350','asset'],'tax_out'=>['2150','liability'],'variance'=>['5200','expense']] as $name=>[$code,$type]) {
    $extra[$name]=pl_save_account($actor,$company,$book,['code'=>$code,'name'=>ucwords(str_replace('_',' ',$name)),'type'=>$type,'role'=>null,'is_active'=>true,'reason'=>'Sample browser fixture','creation_key'=>'account-'.$name])['id'];
}
foreach (['inventory','purchasing','pos-showcase'] as $id) { $m=pl_module_registry()[$id]; pl_set_company_module($actor,$company,$id,true,0,$m['digest'],'Sample browser fixture','module-'.$id); }
$party=pl_save_party($actor,$company,$book,['legal_name'=>'Sample Customer and Supplier','entity_type'=>'private_company','country_code'=>'GB','is_customer'=>true,'is_vendor'=>true,'currency'=>'USD','reason'=>'Sample browser fixture','request_key'=>'party']);
$product=pl_save_inventory_product($actor,$company,$book,['sku'=>'DEMO-ITEM','name'=>'Sample Widget','kind'=>'stock','base_unit'=>'each','selling_price'=>'25','is_active'=>true,
    'inventory_account_id'=>$extra['inventory'],'cogs_account_id'=>$f['accounts']['5000'],'sales_account_id'=>$f['accounts']['4000'],'purchase_account_id'=>$f['accounts']['5000'],'reason'=>'Sample browser fixture','idempotency_key'=>'product']);
$tax=pl_create_tax_code($actor,$company,$book,['code'=>'DEMO5','name'=>'Sample five percent','treatment'=>'standard','sales_account_id'=>$extra['tax_out'],'purchase_account_id'=>$extra['tax_in'],'reason'=>'Sample browser fixture','idempotency_key'=>'tax-code']);
pl_enter_tax_rate($actor,$company,$book,['tax_code_id'=>$tax['id'],'effective_from'=>'2026-01-01','percentage'=>'5','reason'=>'Sample browser fixture','idempotency_key'=>'tax-rate']);
// Trading documents (1.2 M3): the module, its reference data, the policies the editor needs
// before it offers the cash panel, a company profile for the letterhead, and one posted invoice
// carrying a pack line, a discounted line and a free-goods line so every print has real content.
$trading = [];
if (($argv[1] ?? '') === '--trading') {
    $m = pl_module_registry()['trading-documents'];
    pl_set_company_module($actor, $company, 'trading-documents', true, 0, $m['digest'], 'Sample browser fixture', 'module-trading');
    $promotion = pl_save_account($actor, $company, $book, ['code'=>'5300','name'=>'Promotional Goods','type'=>'expense','role'=>null,'is_active'=>true,'reason'=>'Sample browser fixture','creation_key'=>'account-promotion'])['id'];
    $discountAccount = pl_save_account($actor, $company, $book, ['code'=>'4910','name'=>'Discounts Allowed','type'=>'income','role'=>null,'is_active'=>true,'is_contra'=>true,'reason'=>'Sample browser fixture','creation_key'=>'account-discounts'])['id'];
    pl_save_trading_policies($actor, $company, $book, ['discount_posting'=>'net','discount_account_id'=>$discountAccount,
        'free_goods_account_id'=>$promotion,'free_goods_output_tax'=>'none','cash_on_invoice_cap'=>'50000','revision'=>0,
        'reason'=>'Sample browser fixture','idempotency_key'=>'policies']);
    pl_save_company_profile($actor, $company, ['legal_name'=>'Sample Distributors (Pvt) Ltd','address_line1'=>'Plot 14, Industrial Area',
        'address_line2'=>'Gulberg III, Lahore','address_line3'=>'','phone'=>'042-111-556-778','email'=>'sales@example.test',
        'tax_registrations'=>'NTN 3345678-9 . STRN 03-45-1234-567-89','footer_terms'=>'Goods once sold are exchangeable within seven days against a fresh purchase.',
        'revision'=>0,'reason'=>'Sample browser fixture','idempotency_key'=>'profile']);
    $staff = pl_save_sales_staff($actor, $company, $book, ['code'=>'BILAL','name'=>'Bilal Ahmed','is_active'=>true,'reason'=>'Sample browser fixture']);
    $area = pl_save_area($actor, $company, $book, ['code'=>'GULBERG','name'=>'Gulberg route','is_active'=>true,'reason'=>'Sample browser fixture']);
    $pack = pl_save_product_pack($actor, $company, $book, ['product_id'=>$product['id'],'code'=>'CTN12','name'=>'Carton of 12','units_per_pack'=>'12','is_active'=>true,'reason'=>'Sample browser fixture']);
    pl_inventory_receive($actor, $company, $book, ['product_id'=>$product['id'],'quantity'=>'400','amount_base'=>'800','date'=>'2026-01-02',
        'offset_account_id'=>$extra['grni'],'source_type'=>'test_stock','source_reference'=>'trading-opening','reason'=>'Sample browser fixture','idempotency_key'=>'trading-stock']);
    $draft = pl_save_ar_document($actor, $company, $book, ['kind'=>'invoice','party_id'=>$party['id'],'date'=>'2026-01-10','due_date'=>'2026-01-24',
        'currency'=>'USD','reference'=>'Sample trading invoice','terms'=>'Net 14 days','notes'=>'Delivered to the Gulberg route.',
        'creation_key'=>'trading-invoice','sales_staff_id'=>$staff['id'],'area_id'=>$area['id'],
        'cash_received'=>'20000','cash_account_id'=>$f['accounts']['1000'],
        'lines'=>[
            ['description'=>'Sample Widget (carton of 12)','pack_id'=>$pack['id'],'pack_quantity'=>'5','unit_quantity'=>'3','unit_price'=>'1180','account_id'=>$f['accounts']['4000'],'product_id'=>$product['id'],'tax_code_id'=>$tax['id']],
            ['description'=>'Sample Widget, loose units','quantity'=>'20','unit_price'=>'410','discount_percent'=>'5','account_id'=>$f['accounts']['4000'],'product_id'=>$product['id']],
            ['description'=>'Sample Widget - 5+1 scheme bonus','quantity'=>'12','is_free_goods'=>true,'account_id'=>$f['accounts']['4000'],'product_id'=>$product['id']],
        ]]);
    $posted = pl_post_ar_document($actor, $company, $book, $draft['id'], $draft['revision']);
    $trading = ['invoice_id'=>$posted['id'],'invoice_number'=>$posted['number'],'pack_id'=>$pack['id'],
        'sales_staff_id'=>$staff['id'],'area_id'=>$area['id'],'promotion_account_id'=>$promotion,'discount_account_id'=>$discountAccount];
}
if (($argv[1] ?? '') === '--ageing') {
    foreach (['invoice','bill'] as $kind) {
        foreach (['2026-09-30','2026-09-01','2026-08-01','2026-07-01','2026-05-01'] as $index=>$due) {
            $draft=pl_save_ar_document($actor,$company,$book,['kind'=>$kind,'party_id'=>$party['id'],'date'=>'2026-01-05','due_date'=>$due,'currency'=>'USD','reference'=>'Sample ageing bucket '.($index+1),'creation_key'=>'ageing-'.$kind.'-'.$index,
                'lines'=>[['description'=>'Sample ageing service','quantity'=>'1','unit_price'=>'10.2500','account_id'=>$f['accounts'][$kind==='invoice'?'4000':'5000']]]]);
            pl_post_ar_document($actor,$company,$book,$draft['id'],$draft['revision']);
        }
    }
}
echo json_encode(['email'=>$email,'actor_id'=>$actor,'company_id'=>$company,'book_id'=>$book,'party_id'=>$party['id'],'product_id'=>$product['id'],'tax_code_id'=>$tax['id'],'accounts'=>$f['accounts'],'extra'=>$extra,'trading'=>$trading],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)."\n";
