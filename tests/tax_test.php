<?php
declare(strict_types=1);

function starter_tax_fixture(): array
{
    $f=ledger_fixture();
    foreach (['tax_out'=>['2150','liability'],'tax_in'=>['1350','asset']] as $name=>[$code,$type]) {
        $account=pl_save_account($f['actor_id'],$f['company_id'],$f['book_id'],['code'=>$code,'name'=>'Sample '.$name,'type'=>$type,'role'=>null,'is_active'=>true,'reason'=>'Sample tax accounts','creation_key'=>bin2hex(random_bytes(16))]);
        $f[$name]=(int)$account['id'];
    }
    $input=['code'=>'SAMPLE','name'=>'Sample test tax','treatment'=>'standard','sales_account_id'=>$f['tax_out'],'purchase_account_id'=>$f['tax_in'],'reason'=>'Sample tax engine test','idempotency_key'=>bin2hex(random_bytes(16))];
    $code=pl_create_tax_code($f['actor_id'],$f['company_id'],$f['book_id'],$input);
    assert_same($code['id'],pl_create_tax_code($f['actor_id'],$f['company_id'],$f['book_id'],$input)['id']);
    return $f+['tax_code_id'=>$code['id']];
}

test('core tax exact percentage and four-place rounding avoid floats',function():void {
    assert_same('7.500000',pl_tax_percentage('7.5'));
    assert_same('7.5000',pl_tax_amount('100','7.5'));
    assert_same('0.0001',pl_tax_amount('0.0010','5'));
    assert_throws(fn()=>pl_tax_percentage('-1'),DomainException::class);
    assert_throws(fn()=>pl_tax_percentage('100.000001'),DomainException::class);
    assert_throws(fn()=>pl_tax_percentage('5e1'),DomainException::class);
});

test('core tax dated revisions are scoped immutable and selected without future lookahead',function():void {
    $f=starter_tax_fixture(); $input=['tax_code_id'=>$f['tax_code_id'],'effective_from'=>'2026-01-01','percentage'=>'5','reason'=>'Sample first rate','idempotency_key'=>'first'];
    $rate=pl_enter_tax_rate($f['actor_id'],$f['company_id'],$f['book_id'],$input);
    assert_same($rate,pl_enter_tax_rate($f['actor_id'],$f['company_id'],$f['book_id'],$input));
    $calc=fn(string $date)=>pl_tax_calculate($f['actor_id'],$f['company_id'],$f['book_id'],$f['tax_code_id'],$date,'100','sale');
    assert_same('5.0000',$calc('2026-01-05')['tax_amount']);
    assert_throws(fn()=>$calc('2025-12-31'),DomainException::class,'No tax rate');
    pl_enter_tax_rate($f['actor_id'],$f['company_id'],$f['book_id'],array_replace($input,['effective_from'=>'2026-03-01','percentage'=>'10','idempotency_key'=>'future']));
    assert_same('5.0000',$calc('2026-02-28')['tax_amount']); assert_same('10.0000',$calc('2026-03-01')['tax_amount']);
    pl_enter_tax_rate($f['actor_id'],$f['company_id'],$f['book_id'],array_replace($input,['percentage'=>'6','idempotency_key'=>'revision']));
    assert_same('6.0000',$calc('2026-01-05')['tax_amount']);
    assert_throws(fn()=>DB::update('pl_tax_rates',['percentage'=>'9'],'id=%i',$rate['id']));
    assert_throws(fn()=>DB::delete('pl_tax_codes','id=%i',$f['tax_code_id']));
    $other=ledger_fixture();
    assert_throws(fn()=>pl_tax_calculate($other['actor_id'],$f['company_id'],$f['book_id'],$f['tax_code_id'],'2026-01-05','100','sale'),DomainException::class);
    assert_throws(fn()=>pl_tax_calculate($other['actor_id'],$other['company_id'],$other['book_id'],$f['tax_code_id'],'2026-01-05','100','sale'),DomainException::class);
    sample_membership_insert(['company_id'=>$f['company_id'],'user_id'=>$other['actor_id'],'role'=>'viewer']);
    assert_throws(fn()=>pl_enter_tax_rate($other['actor_id'],$f['company_id'],$f['book_id'],array_replace($input,['idempotency_key'=>'viewer'])),DomainException::class);
});
