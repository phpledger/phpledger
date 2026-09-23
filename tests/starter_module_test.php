<?php
declare(strict_types=1);

test('starter has required AR AP and independent optional purchasing and inventory capabilities',function():void {
    $modules=pl_module_registry();
    assert_same(false,$modules['ar']['optional']); assert_same(false,$modules['ap']['optional']);
    assert_same(true,$modules['inventory']['optional']); assert_same(true,$modules['purchasing']['optional']);
    assert_same('1.0.0',$modules['purchasing']['requires']['ap']);
    assert_same('1.0.0',$modules['purchasing']['requires']['inventory']);
    $f=ledger_fixture();
    assert_throws(fn()=>pl_set_company_module($f['actor_id'],$f['company_id'],'ar',false,0,$modules['ar']['digest'],'Sample','ar-disabled'),DomainException::class,'required');
    assert_throws(fn()=>pl_set_company_module($f['actor_id'],$f['company_id'],'purchasing',true,0,$modules['purchasing']['digest'],'Sample','buy-enabled'),DomainException::class,'dependency');
});

test('hiding AR AP is audited owner presentation only and preserves required services',function():void {
    $f=ledger_fixture();
    assert_same(['show_ar'=>true,'show_ap'=>true,'revision'=>0],pl_company_visibility($f['actor_id'],$f['company_id']));
    $result=pl_set_company_visibility($f['actor_id'],$f['company_id'],false,false,0,'Sample simpler navigation','visibility');
    assert_true($result==pl_set_company_visibility($f['actor_id'],$f['company_id'],false,false,0,'Sample simpler navigation','visibility'));
    assert_same(false,pl_company_visibility($f['actor_id'],$f['company_id'])['show_ar']);
    pl_require_module($f['actor_id'],$f['company_id'],$f['book_id'],'ar'); pl_require_module($f['actor_id'],$f['company_id'],$f['book_id'],'ap');
    assert_throws(fn()=>pl_set_company_visibility($f['actor_id'],$f['company_id'],true,true,0,'Stale form','stale'),DomainException::class,'changed');
    $other=ledger_fixture(); sample_membership_insert(['company_id'=>$f['company_id'],'user_id'=>$other['actor_id'],'role'=>'accountant']);
    assert_throws(fn()=>pl_set_company_visibility($other['actor_id'],$f['company_id'],true,true,1,'Not owner','notowner'),DomainException::class,'cannot change navigation');
    assert_throws(fn()=>DB::delete('pl_visibility_actions','company_id=%i',$f['company_id']));
});
