<?php
declare(strict_types=1);

/** Country-neutral calculation only. Applicability is an explicit code selection. */
function pl_tax_percentage(string $input): string
{
    if (!preg_match('/^(?:0|[1-9][0-9]{0,2})(?:\.[0-9]{1,6})?$/D',$input) || bccomp($input,'100',6)>0) { throw new DomainException('Tax percentage must be between 0 and 100, with up to six decimal places.'); }
    return bcadd($input,'0',6);
}

function pl_tax_amount(string $net,string $percentage): string
{
    return pl_amount(bcadd(bcdiv(bcmul(pl_amount($net),pl_tax_percentage($percentage),10),'100',12),'0.00005',4));
}

/** Inclusive entry always preserves the entered gross; exclusive display shows its split. */
function pl_tax_split(string $amount,string $percentage,string $mode='exclusive'): array
{
    $amount=pl_amount($amount); $percentage=pl_tax_percentage($percentage);
    if (!in_array($mode,['exclusive','inclusive'],true)) { throw new DomainException('Choose tax-exclusive or tax-inclusive prices.'); }
    if ($mode==='exclusive') {
        $tax=pl_tax_amount($amount,$percentage);
        return ['net'=>$amount,'tax'=>$tax,'total'=>pl_amount(bcadd($amount,$tax,4))];
    }
    $divisor=bcadd('1',bcdiv($percentage,'100',8),8);
    $net=pl_amount(bcadd(bcdiv($amount,$divisor,12),'0.00005',4));
    return ['net'=>$net,'tax'=>bcsub($amount,$net,4),'total'=>$amount];
}

function pl_tax_settings(int $actorId,int $companyId,int $bookId): array
{
    pl_require_company_access($actorId,$companyId); pl_ledger_book($companyId,$bookId);
    $row=DB::queryFirstRow('SELECT price_mode,revision FROM pl_tax_settings WHERE company_id=%i AND book_id=%i FOR SHARE',$companyId,$bookId);
    return ['price_mode'=>$row['price_mode']??'exclusive','revision'=>$row?(int)$row['revision']:0];
}

function pl_tax_price_mode(int $actorId,int $companyId,int $bookId): string
{
    return pl_tax_settings($actorId,$companyId,$bookId)['price_mode'];
}

function pl_set_tax_price_mode(int $actorId,int $companyId,int $bookId,string $mode,int $revision,string $reason,string $key): array
{
    pl_demo_require_setup_action();
    if (!in_array($mode,['exclusive','inclusive'],true)) { throw new DomainException('Choose tax-exclusive or tax-inclusive price entry.'); }
    $reason=pl_ledger_text($reason,'Setting reason',500); $key=pl_request_key($key);
    $hash=hash('sha256',json_encode([$actorId,$mode,$revision,$reason],JSON_THROW_ON_ERROR));
    return pl_ledger_transaction(function () use($actorId,$companyId,$bookId,$mode,$revision,$reason,$key,$hash):array {
        pl_require_company_access($actorId,$companyId,true);
        if (!pl_user_can($actorId,$companyId,'tax.settings.manage')) { throw new DomainException('Your role cannot change tax price settings.'); }
        pl_ledger_book($companyId,$bookId,true);
        $prior=DB::queryFirstRow('SELECT payload_hash,result_json FROM pl_tax_setting_actions WHERE book_id=%i AND request_key=%s FOR UPDATE',$bookId,$key);
        if ($prior) { if (!hash_equals($prior['payload_hash'],$hash)) { throw new DomainException('Tax setting request already has different content.'); } return json_decode($prior['result_json'],true,512,JSON_THROW_ON_ERROR); }
        if (pl_tax_settings($actorId,$companyId,$bookId)['revision']!==$revision) { throw new DomainException('Tax setting changed. Reload before saving.'); }
        $result=['price_mode'=>$mode,'revision'=>$revision+1];
        DB::insertUpdate('pl_tax_settings',$result+['company_id'=>$companyId,'book_id'=>$bookId,'updated_by'=>$actorId]);
        DB::insert('pl_tax_setting_actions',['company_id'=>$companyId,'book_id'=>$bookId,'actor_id'=>$actorId,'reason'=>$reason,'request_key'=>$key,'payload_hash'=>$hash,'result_json'=>json_encode($result,JSON_THROW_ON_ERROR)]);
        return $result;
    });
}

function pl_get_tax_code(int $actorId,int $companyId,int $bookId,int $id): array
{
    pl_require_company_access($actorId,$companyId); pl_ledger_book($companyId,$bookId);
    $row=DB::queryFirstRow('SELECT * FROM pl_tax_codes WHERE id=%i AND company_id=%i AND book_id=%i FOR SHARE',$id,$companyId,$bookId);
    if (!$row) { throw new DomainException('Tax code is unavailable in this book.'); }
    foreach (['id','sales_account_id','purchase_account_id'] as $field) { $row[$field]=(int)$row[$field]; }
    return $row;
}

function pl_list_tax_codes(int $actorId,int $companyId,int $bookId): array
{
    pl_require_company_access($actorId,$companyId); pl_ledger_book($companyId,$bookId);
    return DB::query('SELECT * FROM pl_tax_codes WHERE company_id=%i AND book_id=%i ORDER BY code',$companyId,$bookId);
}

function pl_create_tax_code(int $actorId,int $companyId,int $bookId,array $input): array
{
    pl_demo_require_setup_action();
    $code=strtoupper(pl_ledger_text($input['code']??null,'Tax code',40));
    if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{0,39}$/D',$code)) { throw new DomainException('Use letters, digits, underscore or hyphen for a tax code.'); }
    $data=['code'=>$code,'name'=>pl_ledger_text($input['name']??null,'Tax name',160),'treatment'=>$input['treatment']??'standard',
        'sales_account_id'=>pl_oi_id($input,'sales_account_id'),'purchase_account_id'=>pl_oi_id($input,'purchase_account_id'),
        'reason'=>pl_ledger_text($input['reason']??null,'Tax configuration reason',500)];
    if (!in_array($data['treatment'],['standard','zero','exempt'],true)) { throw new DomainException('Choose a supported tax treatment.'); }
    $key=pl_request_key(pl_ledger_text($input['idempotency_key']??null,'Request key',128));
    $hash=hash('sha256',json_encode([$actorId,$data],JSON_THROW_ON_ERROR));
    return pl_ledger_transaction(function () use($actorId,$companyId,$bookId,$data,$key,$hash):array {
        pl_require_company_access($actorId,$companyId,true); pl_ledger_book($companyId,$bookId,true);
        $prior=DB::queryFirstRow('SELECT id,payload_hash FROM pl_tax_codes WHERE book_id=%i AND creation_key=%s FOR UPDATE',$bookId,$key);
        if ($prior) { if (!hash_equals($prior['payload_hash'],$hash)) { throw new DomainException('Tax code request has different content.'); } return pl_get_tax_code($actorId,$companyId,$bookId,(int)$prior['id']); }
        foreach (['sales_account_id'=>'liability','purchase_account_id'=>'asset'] as $field=>$type) {
            $account=pl_get_account($actorId,$companyId,$bookId,$data[$field]);
            if (!$account['is_active'] || $account['type']!==$type || $account['role']!==null || $account['currency']!==null) { throw new DomainException('Use active currency-neutral general liability and asset accounts for output tax and recoverable input tax.'); }
        }
        if (DB::queryFirstField('SELECT id FROM pl_tax_codes WHERE book_id=%i AND code=%s',$bookId,$data['code'])) { throw new DomainException('Tax code already exists. Add a dated rate to its existing history.'); }
        DB::insert('pl_tax_codes',$data+['company_id'=>$companyId,'book_id'=>$bookId,'created_by'=>$actorId,'creation_key'=>$key,'payload_hash'=>$hash]);
        return pl_get_tax_code($actorId,$companyId,$bookId,(int)DB::insertId());
    });
}

function pl_enter_tax_rate(int $actorId,int $companyId,int $bookId,array $input): array
{
    pl_demo_require_setup_action();
    $data=['tax_code_id'=>pl_oi_id($input,'tax_code_id'),'effective_from'=>pl_ledger_date(pl_ledger_text($input['effective_from']??null,'Effective date',10)),
        'percentage'=>pl_tax_percentage(pl_ledger_text($input['percentage']??null,'Tax percentage',12)),
        'reason'=>pl_ledger_text($input['reason']??null,'Rate change reason',500)];
    $key=pl_request_key(pl_ledger_text($input['idempotency_key']??null,'Request key',128));
    $hash=hash('sha256',json_encode([$actorId,$data],JSON_THROW_ON_ERROR));
    return pl_ledger_transaction(function () use($actorId,$companyId,$bookId,$data,$key,$hash):array {
        pl_require_company_access($actorId,$companyId,true); pl_ledger_book($companyId,$bookId,true);
        $prior=DB::queryFirstRow('SELECT * FROM pl_tax_rates WHERE book_id=%i AND request_key=%s FOR UPDATE',$bookId,$key);
        if ($prior) { if (!hash_equals($prior['payload_hash'],$hash)) { throw new DomainException('Tax rate request has different content.'); } return $prior; }
        $code=pl_get_tax_code($actorId,$companyId,$bookId,$data['tax_code_id']);
        if ($code['treatment']!=='standard' && bccomp($data['percentage'],'0',6)!==0) { throw new DomainException('Zero-rated and exempt codes require a zero percentage.'); }
        $revision=(int)DB::queryFirstField('SELECT COALESCE(MAX(revision),0)+1 FROM pl_tax_rates WHERE tax_code_id=%i AND effective_from=%s FOR UPDATE',$data['tax_code_id'],$data['effective_from']);
        DB::insert('pl_tax_rates',$data+['company_id'=>$companyId,'book_id'=>$bookId,'revision'=>$revision,'entered_by'=>$actorId,'request_key'=>$key,'payload_hash'=>$hash]);
        return DB::queryFirstRow('SELECT * FROM pl_tax_rates WHERE id=%i',(int)DB::insertId());
    });
}

function pl_tax_calculate(int $actorId,int $companyId,int $bookId,?int $codeId,string $date,string $net,string $side): array
{
    pl_require_company_access($actorId,$companyId); pl_ledger_book($companyId,$bookId);
    $date=pl_ledger_date($date); $net=pl_amount($net);
    if (!in_array($side,['sale','purchase'],true)) { throw new DomainException('Choose the tax posting direction.'); }
    if ($codeId===null) { return ['tax_code_id'=>null,'tax_rate_id'=>null,'tax_rate'=>'0.000000','tax_amount'=>'0.0000','tax_account_id'=>null,'tax_label'=>'']; }
    $code=pl_get_tax_code($actorId,$companyId,$bookId,$codeId);
    $rate=DB::queryFirstRow('SELECT * FROM pl_tax_rates WHERE tax_code_id=%i AND company_id=%i AND book_id=%i AND effective_from<=%s ORDER BY effective_from DESC,revision DESC LIMIT 1 FOR SHARE',$codeId,$companyId,$bookId,$date);
    if (!$rate) { throw new DomainException('No tax rate is effective on this document date. Add or select a reviewed rate.'); }
    $account=pl_get_account($actorId,$companyId,$bookId,$code[$side==='sale'?'sales_account_id':'purchase_account_id']);
    if (!$account['is_active']) { throw new DomainException('The selected tax account is inactive.'); }
    return ['tax_code_id'=>$codeId,'tax_rate_id'=>(int)$rate['id'],'tax_rate'=>$rate['percentage'],
        'tax_amount'=>pl_tax_amount($net,$rate['percentage']),'tax_account_id'=>$account['id'],'tax_label'=>$code['code'].' · '.$code['name']];
}
