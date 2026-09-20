<?php
declare(strict_types=1);

function pl_web_starter_inventory(int $actorId,int $companyId,int $bookId,array $user,array $company,string $path,string $method): never
{
    if (isset($_GET['count']) || in_array(pl_web_text($_POST,'action'),['count','count_preview','count_confirm'],true)) {
        pl_web_stock_count($actorId,$companyId,$bookId,$user,$company,$method);
    }
    $filters=($method==='POST' || isset($_GET['id']) || isset($_GET['new'])) ? pl_return_list_filters($method==='POST'?$_POST:$_GET,'inventory') : pl_list_filters($_GET,'inventory');
    if ($method==='POST') {
        $id=pl_web_id($_POST,'id');
        try {
            $action=pl_web_text($_POST,'action'); $key=pl_web_text($_POST,'request_key');
            if ($action==='product') {
                if (isset($_POST['is_active']) && pl_web_text($_POST,'is_active')!=='1') { throw new DomainException('Choose whether the product is active.'); }
                $input=['sku'=>pl_web_text($_POST,'sku'),'name'=>pl_web_text($_POST,'name'),'kind'=>pl_web_text($_POST,'kind'),
                    'base_unit'=>pl_web_text($_POST,'base_unit'),'selling_price'=>pl_web_text($_POST,'selling_price'),'is_active'=>isset($_POST['is_active']),
                    'reason'=>pl_web_text($_POST,'reason'),'idempotency_key'=>$key];
                foreach (['inventory_account_id','cogs_account_id','sales_account_id','purchase_account_id'] as $field) { $input[$field]=pl_web_id($_POST,$field)?:null; }
                $result=pl_save_inventory_product($actorId,$companyId,$bookId,$input,$id?:null,$id?pl_web_id($_POST,'revision'):null); $id=(int)$result['id'];
            } elseif ($action==='warehouse') {
                $input=['code'=>pl_web_text($_POST,'code'),'name'=>pl_web_text($_POST,'name'),'kind'=>pl_web_text($_POST,'kind','fixed'),
                    'driver_name'=>pl_web_text($_POST,'driver_name')?:null,'vehicle_reference'=>pl_web_text($_POST,'vehicle_reference')?:null,'route_name'=>pl_web_text($_POST,'route_name')?:null,
                    'is_active'=>isset($_POST['is_active']),'reason'=>pl_web_text($_POST,'reason'),'idempotency_key'=>$key];
                $warehouseId=pl_web_id($_POST,'warehouse_id');
                pl_save_inventory_warehouse($actorId,$companyId,$bookId,$input,$warehouseId?:null,$warehouseId?pl_web_id($_POST,'revision'):null);
            } elseif ($action==='transfer') {
                pl_inventory_transfer($actorId,$companyId,$bookId,['product_id'=>pl_web_id($_POST,'product_id'),'from_warehouse_id'=>pl_web_id($_POST,'from_warehouse_id'),'to_warehouse_id'=>pl_web_id($_POST,'to_warehouse_id'),
                    'quantity'=>pl_web_text($_POST,'quantity'),'date'=>pl_web_text($_POST,'date'),'source_reference'=>pl_web_text($_POST,'reference'),'reason'=>pl_web_text($_POST,'reason'),'idempotency_key'=>$key]);
            } elseif (in_array($action,['receive','issue','count','value'],true)) {
                $input=['product_id'=>$id,'date'=>pl_web_text($_POST,'date'),'quantity'=>pl_web_text($_POST,'quantity'),'warehouse_id'=>pl_web_id($_POST,'warehouse_id')?:null,
                    'offset_account_id'=>pl_web_id($_POST,'offset_account_id'),'source_type'=>'manual_stock','source_reference'=>pl_web_text($_POST,'reference'),
                    'reason'=>pl_web_text($_POST,'reason'),'idempotency_key'=>$key];
                if ($action==='receive') { $input['amount_base']=pl_web_text($_POST,'amount_base'); pl_inventory_receive($actorId,$companyId,$bookId,$input); }
                elseif ($action==='issue') { pl_inventory_issue($actorId,$companyId,$bookId,$input); }
                elseif ($action==='value') { $input['amount_base']=pl_web_text($_POST,'amount_base'); $input['expected_quantity']=pl_web_text($_POST,'expected_quantity'); $input['expected_value_base']=pl_web_text($_POST,'expected_value_base'); pl_inventory_value_adjustment($actorId,$companyId,$bookId,$input); }
                else { $input['counted_quantity']=pl_web_text($_POST,'counted_quantity'); $input['expected_quantity']=pl_web_text($_POST,'expected_quantity'); $input['unit_cost']=pl_web_text($_POST,'unit_cost','0'); pl_inventory_adjust_count($actorId,$companyId,$bookId,$input); }
            } elseif ($action==='opening_preview') {
                unset($_SESSION['starter_inventory_preview']);
                if (pl_web_text($_POST,'editor_action')==='add_line' || isset($_POST['remove_line'])) {
                    if (pl_require_company_access($actorId,$companyId,true)['role']!=='owner') { throw new DomainException('Only the owner can prepare opening stock.'); }
                    pl_require_module($actorId,$companyId,$bookId,'inventory');
                    pl_form_failure(pl_url($path,$filters),pl_web_line_action($_POST,500),'',200);
                }
                $lines=[];
                $submittedLines=$_POST['lines']??[];
                if (!is_array($submittedLines)||count($submittedLines)>500) { throw new DomainException('Use up to 500 opening product rows.'); }
                foreach ($submittedLines as $row) { if (is_array($row)&&pl_web_id($row,'product_id')) { $lines[]=['product_id'=>pl_web_id($row,'product_id'),'quantity'=>pl_web_text($row,'quantity'),'amount_base'=>pl_web_text($row,'amount_base')]; } }
                $preview=pl_preview_inventory_opening($actorId,$companyId,$bookId,['date'=>pl_web_text($_POST,'date'),'reason'=>pl_web_text($_POST,'reason'),'lines'=>$lines]);
                $_SESSION['starter_inventory_preview']=['company_id'=>$companyId,'book_id'=>$bookId]+$preview;
            } elseif ($action==='opening_confirm') {
                $preview=$_SESSION['starter_inventory_preview']??null;
                if (!$preview || $preview['company_id']!==$companyId || $preview['book_id']!==$bookId || (int)$preview['id']!==pl_web_id($_POST,'preview_id') || !hash_equals($preview['payload_hash'],pl_web_text($_POST,'expected_hash'))) { throw new DomainException('Review the current opening stock preview in this company before confirming.'); }
                pl_confirm_inventory_opening($actorId,$companyId,$bookId,pl_web_id($_POST,'preview_id'),pl_web_text($_POST,'expected_hash'),pl_web_text($_POST,'confirmed')==='1',$key);
                unset($_SESSION['starter_inventory_preview']);
            } else { throw new DomainException('Choose an inventory action.'); }
            pl_notice($action==='opening_preview'?'Opening quantities and values reconcile. Review before confirmation.':'Inventory action completed.'); pl_redirect(pl_url($path,$id?['id'=>$id,'return_filters'=>$filters]:$filters));
        } catch (DomainException $error) {
            $input=array_map(static fn(mixed $value): string=>is_scalar($value)?(string)$value:'',$_POST);
            $input['lines']=is_array($_POST['lines']??null)?array_values(array_map(
                static fn(array $line): array=>array_map(static fn(mixed $value): string=>is_scalar($value)?(string)$value:'',$line),
                array_filter($_POST['lines'],'is_array')
            )):[];
            $query=$id?['id'=>$id,'return_filters'=>$filters]:(pl_web_text($_POST,'action')==='product'?['new'=>'1','return_filters'=>$filters]:$filters);
            pl_form_failure(pl_url($path,$query),$input,$error->getMessage());
        }
    }
    $id=pl_web_id($_GET,'id'); $product=$id?pl_get_inventory_product($actorId,$companyId,$bookId,$id):null;
    $selectionId=pl_web_id($_GET,'select'); $selection=$selectionId?pl_get_inventory_product($actorId,$companyId,$bookId,$selectionId):null;
    $preview=$_SESSION['starter_inventory_preview']??null;
    if ($preview && ($preview['company_id']!==$companyId||$preview['book_id']!==$bookId)) { $preview=null; }
    $locations=pl_module_available($actorId,$companyId,$bookId,'inventory-locations');
    $warehouseFilter=$locations?(pl_web_id($_GET,'warehouse')?:null):null;
    pl_render('inventory',['title'=>'Products and stock','user'=>$user,'company'=>$company,'product'=>$product,'selection'=>$selection,'filters'=>$filters,
        'list'=>!$product && !isset($_GET['new'])?pl_list_query($actorId,$companyId,$bookId,'inventory',$filters):null,
        'form'=>pl_form_state(pl_url($path,$id?['id'=>$id,'return_filters'=>$filters]:(isset($_GET['new'])?['new'=>'1','return_filters'=>$filters]:$filters))),
        'enabled'=>pl_module_available($actorId,$companyId,$bookId,'inventory'),'accounts'=>pl_starter_accounts($actorId,$companyId,$bookId),
        'locations'=>$locations,'warehouses'=>$locations?pl_list_inventory_warehouses($actorId,$companyId,$bookId):[],'warehouse_filter'=>$warehouseFilter,
        'products'=>pl_list_inventory_products($actorId,$companyId,$bookId),'valuation'=>pl_inventory_valuation($actorId,$companyId,$bookId,$filters['as_of'],$warehouseFilter),
        'movements'=>pl_inventory_history($actorId,$companyId,$bookId,$id?:($selectionId?:null),$warehouseFilter),'preview'=>$preview,
        'balance'=>$product?pl_inventory_balance($actorId,$companyId,$bookId,$id,null,$warehouseFilter):null]);
}

function pl_web_stock_count_input(array $input): array
{
    return ['product_id'=>pl_web_id($input,'id'),'date'=>pl_web_text($input,'date'),'counted_quantity'=>pl_web_text($input,'counted_quantity'),
        'warehouse_id'=>pl_web_id($input,'warehouse_id')?:null,
        'expected_quantity'=>pl_web_text($input,'expected_quantity'),'unit_cost'=>pl_web_text($input,'unit_cost')?:null,
        'offset_account_id'=>pl_web_id($input,'offset_account_id'),'source_type'=>'manual_stock','source_reference'=>pl_web_text($input,'reference'),
        'reason'=>pl_web_text($input,'reason'),'idempotency_key'=>pl_web_text($input,'request_key')];
}

function pl_web_stock_count(int $actorId,int $companyId,int $bookId,array $user,array $company,string $method): never
{
    pl_require_company_access($actorId,$companyId,true);
    $filters=pl_return_list_filters($method==='POST'?$_POST:$_GET,'inventory');
    $id=pl_web_id($method==='POST'?$_POST:$_GET,'id');
    $return=pl_url('/inventory',['id'=>$id,'count'=>'1','return_filters'=>$filters]);
    if ($method==='POST') {
        try {
            $input=pl_web_stock_count_input($_POST);
            if (pl_web_text($_POST,'action')==='count_confirm') {
                $review=$_SESSION['stock_count_review']??[];
                $hash=is_array($review) && ($review['company_id']??null)===$companyId && ($review['book_id']??null)===$bookId && ($review['key']??null)===$input['idempotency_key'] ? ($review['hash']??'') : '';
                pl_confirm_inventory_count($actorId,$companyId,$bookId,$input,is_string($hash)?$hash:'');
                pl_notice('Stock count recorded. Quantity and carrying value were adjusted together.');
                pl_redirect(pl_url('/inventory',['id'=>$id,'return_filters'=>$filters]));
            }
            $preview=pl_preview_inventory_count($actorId,$companyId,$bookId,$input);
            $_SESSION['stock_count_review']=['company_id'=>$companyId,'book_id'=>$bookId,'key'=>$input['idempotency_key'],'hash'=>hash('sha256',json_encode($preview,JSON_THROW_ON_ERROR))];
            pl_form_failure($return,$_POST,'',200);
        } catch (DomainException $error) { pl_form_failure($return,$_POST,$error->getMessage()); }
    }
    $product=pl_get_inventory_product($actorId,$companyId,$bookId,$id);
    if ($product['kind']!=='stock') { throw new DomainException('Choose a stock product to record a physical count.'); }
    $locations=pl_module_available($actorId,$companyId,$bookId,'inventory-locations');
    $form=pl_form_state($return); $preview=null;
    $warehouseId=$locations?(pl_web_id($form['input']?:$_GET,'warehouse_id')?:null):null;
    $balance=pl_inventory_balance($actorId,$companyId,$bookId,$id,null,$warehouseId);
    $input=$form['input']?:['id'=>$id,'expected_quantity'=>$balance['quantity'],'date'=>gmdate('Y-m-d'),'warehouse_id'=>$warehouseId];
    if ($form['input']) {
        try { $preview=pl_preview_inventory_count($actorId,$companyId,$bookId,pl_web_stock_count_input($input)); }
        catch (DomainException $error) { if ($form['message']==='') { $form['message']=$error->getMessage(); } }
    }
    pl_render('stock-count',['title'=>'Record stock count','user'=>$user,'company'=>$company,'product'=>$product,'balance'=>$balance,'form'=>$form,'input'=>$input,'preview'=>$preview,'filters'=>$filters,
        'accounts'=>pl_starter_accounts($actorId,$companyId,$bookId),'locations'=>$locations,'warehouses'=>$locations?pl_list_inventory_warehouses($actorId,$companyId,$bookId):[]]);
}
