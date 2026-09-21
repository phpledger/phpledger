<?php
declare(strict_types=1);

/** Browser adapters use scoped services; templates never decide accounting outcomes. */
function pl_web_starter(int $actorId,int $companyId,int $bookId,array $user,array $company,string $path,string $method): never
{
    pl_require_company_access($actorId,$companyId);
    pl_ledger_book($companyId,$bookId);
    if ($method === 'POST') { pl_web_assert_scope($company,$_POST); }
    $module = match ($path) { '/ar'=>'ar','/ap'=>'ap','/inventory'=>'inventory','/purchasing'=>'purchasing',default=>null };
    if ($module !== null && in_array($module,['ar','ap'],true)) { pl_require_module($actorId,$companyId,$bookId,$module,false); }
    $handler = match ($path) { '/ar','/ap'=>'ar', '/parties'=>'parties', '/inventory'=>'inventory', '/purchasing'=>'purchasing', '/opening-conversion'=>'opening', '/tax'=>'tax', default=>throw new DomainException('Unknown starter page.') };
    require_once __DIR__ . '/starter_' . $handler . '_web_functions.php';
    $function = 'pl_web_starter_' . $handler;
    $function($actorId,$companyId,$bookId,$user,$company,$path,$method);
}

function pl_starter_options(array $rows,string $label='name'): array
{
    $options=[];
    foreach ($rows as $row) { $options[(string)$row['id']] = (isset($row['code']) ? $row['code'].' · ' : '').(string)$row[$label]; }
    return $options;
}

function pl_starter_accounts(int $actorId,int $companyId,int $bookId): array
{
    pl_require_company_access($actorId,$companyId); pl_ledger_book($companyId,$bookId);
    return DB::query('SELECT a.id,a.code,a.name,a.type,a.role,EXISTS(SELECT 1 FROM pl_open_item_accounts o WHERE o.account_id=a.id) AS open_item_managed FROM pl_accounts a WHERE a.company_id=%i AND a.book_id=%i AND a.is_active=1 ORDER BY a.code',$companyId,$bookId);
}

function pl_starter_parties(int $actorId,int $companyId,int $bookId): array
{
    pl_require_company_access($actorId,$companyId); pl_ledger_book($companyId,$bookId);
    return DB::query('SELECT id,legal_name,is_customer,is_vendor FROM pl_parties WHERE company_id=%i ORDER BY legal_name,id',$companyId);
}

function pl_starter_field(string $label,string $name,mixed $value='',string $type='text',bool $required=true,string $error='',string $id=''): void
{
    static $sequence=0; $id=$id !== '' ? $id : 'starter-field-'.++$sequence;
    pl_ui_field($id, $label, static function () use ($id, $name, $type, $value, $required, $error): void {
        echo '<input class="input" id="'.pl_e($id).'" name="'.pl_e($name).'" type="'.pl_e($type).'" value="'.pl_e((string)($value??'')).'"'.pl_ui_error_attributes($id,$error).($required?' required':'').($type==='text'?' maxlength="500"':'').'>';
    }, '', $error);
}

function pl_starter_select(string $label,string $name,array $options,mixed $value='',bool $required=true,string $error='',string $id=''): void
{
    static $sequence=0; $id=$id !== '' ? $id : 'starter-select-'.++$sequence;
    pl_ui_field($id, $label, static function () use ($id, $name, $required, $options, $value, $error): void {
        echo '<select class="select" id="'.pl_e($id).'" name="'.pl_e($name).'"'.pl_ui_error_attributes($id,$error).($required?' required':'').'><option value="">Choose…</option>';
        if (!in_array($value,['',null,0,'0'],true) && !array_key_exists((string)$value,$options)) { echo '<option value="'.pl_e((string)$value).'" selected>Unavailable selection — choose another</option>'; }
        foreach ($options as $key=>$text) { echo '<option value="'.pl_e((string)$key).'"'.((string)$value===(string)$key?' selected':'').'>'.pl_e((string)$text).'</option>'; }
        echo '</select>';
    }, '', $error);
}

function pl_starter_hidden(string $name,mixed $value): void
{
    echo '<input type="hidden" name="'.pl_e($name).'" value="'.pl_e((string)$value).'">';
}

function pl_starter_form(array $company,string $action,array $fields=[]): void
{
    echo pl_csrf_field().pl_scope_fields($company);
    pl_starter_hidden('action',$action);
    pl_starter_hidden('request_key',$fields['request_key']??bin2hex(random_bytes(20)));
    foreach ($fields as $name=>$value) { if ($name!=='request_key') { pl_starter_hidden($name,$value); } }
}

function pl_starter_lines(array $input): array
{
    $rows = $input['lines'] ?? [];
    if (!is_array($rows) || count($rows)>100) { throw new DomainException('Use up to 100 document lines.'); }
    $result=[];
    foreach ($rows as $row) {
        if (!is_array($row)) { throw new DomainException('Invalid document line.'); }
        $hasInput=false;
        foreach (['description','quantity','unit_price'] as $field) { if (pl_web_text($row,$field)!=='') { $hasInput=true; } }
        foreach (['account_id','product_id','tax_code_id','original_line_number','pack_id'] as $field) { if (pl_web_id($row,$field)>0) { $hasInput=true; } }
        if (!$hasInput) { continue; }
        // Trading fields (1.2 M3). Neutral when the editor does not send them, so a screen
        // written before this milestone produces exactly the line it produced before.
        $packId=pl_web_id($row,'pack_id')?:null;
        $line=['description'=>pl_web_text($row,'description'),'quantity'=>pl_web_text($row,'quantity'),
            'unit_price'=>pl_web_text($row,'unit_price'),'account_id'=>pl_web_id($row,'account_id'),
            'product_id'=>pl_web_id($row,'product_id')?:null,'tax_code_id'=>pl_web_id($row,'tax_code_id')?:null,'original_line_number'=>pl_web_id($row,'original_line_number')?:null,
            'discount_percent'=>pl_web_text($row,'discount_percent','0')?:'0','is_free_goods'=>pl_web_text($row,'is_free_goods')==='1'];
        if ($packId!==null) {
            $line+=['pack_id'=>$packId,'pack_quantity'=>pl_web_text($row,'pack_quantity','0')?:'0','unit_quantity'=>pl_web_text($row,'unit_quantity','0')?:'0'];
        }
        $result[]=$line;
    }
    return $result;
}

function pl_starter_header(string $title,string $description,array $form,array $company=[]): void
{
    pl_ui_page_header($title, $description);
    if ($form['message']!=='') { echo '<div class="alert alert-danger" role="alert" tabindex="-1" data-form-error>'.pl_e($form['message']).'</div>'; }
}
