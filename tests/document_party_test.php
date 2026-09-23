<?php
declare(strict_types=1);

function document_party_fixture(array $f, string $name = 'Fictional payee'): array
{
    return pl_save_party($f['actor_id'],$f['company_id'],$f['book_id'],[
        'legal_name'=>$name,'entity_type'=>'business','country_code'=>'US','currency'=>'USD',
        'is_customer'=>true,'is_vendor'=>false,'request_key'=>bin2hex(random_bytes(16)),'reason'=>'Sample party link test',
    ]);
}

test('simple documents retain scoped party identity and frozen name without creating open items',function():void {
    $f=ledger_fixture(); $party=document_party_fixture($f);
    $input=document_input($f,'receipt'); $input['party_id']=$party['id']; $input['counterparty']='Untrusted browser name';
    $draft=pl_save_document($f['actor_id'],$f['company_id'],$f['book_id'],$input);
    assert_same($party['id'],$draft['party_id']); assert_same('Fictional payee',$draft['counterparty']);
    assert_same($draft['id'],pl_save_document($f['actor_id'],$f['company_id'],$f['book_id'],$input)['id']);
    $posted=pl_post_document($f['actor_id'],$f['company_id'],$f['book_id'],$draft['id'],1);
    DB::update('pl_parties',['legal_name'=>'Renamed fictional payee'],'id=%i',$party['id']);
    $read=pl_get_document($f['actor_id'],$f['company_id'],$f['book_id'],$posted['id']);
    assert_same('Fictional payee',$read['counterparty']); assert_same($party['id'],$read['party_id']);
    DB::update('pl_parties',['status'=>'on_hold'],'id=%i',$party['id']);
    $input['counterparty']='Different ignored browser name';
    $retried=pl_save_document($f['actor_id'],$f['company_id'],$f['book_id'],$input);
    assert_same($draft['id'],$retried['id']); assert_same('Fictional payee',$retried['counterparty']);
    assert_same(0,(int)DB::queryFirstField('SELECT COUNT(*) FROM pl_ar_documents WHERE company_id=%i',$f['company_id']));
    assert_same($party['id'],pl_read_source($read,'receipt',1,25)['party_id']);
    assert_throws(fn()=>DB::update('pl_documents',['party_id'=>null],'id=%i',$posted['id']),MeekroDBException::class,'immutable');
});

test('simple document parties enforce company and eligibility regardless of customer or supplier role',function():void {
    $f=ledger_fixture(); $other=ledger_fixture(); $party=document_party_fixture($f); $foreign=document_party_fixture($other);
    $input=document_input($f); $input['party_id']=$foreign['id'];
    assert_throws(fn()=>pl_save_document($f['actor_id'],$f['company_id'],$f['book_id'],$input),DomainException::class,'available party');
    $input['party_id']=$party['id'];
    $draft=pl_save_document($f['actor_id'],$f['company_id'],$f['book_id'],$input);
    assert_same($party['id'],$draft['party_id'],'A customer may receive money; direction does not filter roles.');
    assert_throws(fn()=>DB::update('pl_documents',['party_id'=>$foreign['id']],'id=%i',$draft['id']),MeekroDBException::class);
    foreach (['on_hold','blacklisted','archived'] as $status) {
        DB::update('pl_parties',['status'=>$status],'id=%i',$party['id']);
        assert_throws(fn()=>pl_post_document($f['actor_id'],$f['company_id'],$f['book_id'],$draft['id'],1),DomainException::class,'available party');
    }
    assert_same('draft',pl_get_document($f['actor_id'],$f['company_id'],$f['book_id'],$draft['id'])['status']);
});

test('legacy text-only sources retain identity and can explicitly link a party while still draft',function():void {
    $f=ledger_fixture(); $input=document_input($f); $draft=pl_save_document($f['actor_id'],$f['company_id'],$f['book_id'],$input);
    assert_same(null,$draft['party_id']);
    // Reproduce a pre-migration creation fingerprint without changing any financial fields.
    $legacy=pl_normalize_document($input); unset($legacy['party_id']);
    DB::update('pl_documents',['creation_hash'=>hash('sha256',json_encode($legacy,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))],'id=%i',$draft['id']);
    assert_same($draft['id'],pl_save_document($f['actor_id'],$f['company_id'],$f['book_id'],$input)['id']);
    $party=document_party_fixture($f); $input['party_id']=$party['id'];
    $linked=pl_save_document($f['actor_id'],$f['company_id'],$f['book_id'],$input,$draft['id'],1);
    assert_same($party['id'],$linked['party_id']); assert_same('Fictional payee',$linked['counterparty']);
});

test('receipt corrections carry the linked party snapshot without mutating the original document',function():void {
    $f=ledger_fixture(); $first=document_party_fixture($f,'First fictional payer'); $second=document_party_fixture($f,'Second fictional payer');
    $input=document_input($f,'receipt'); $input['party_id']=$first['id']; $input['date']=gmdate('Y-m-d');
    $draft=pl_save_document($f['actor_id'],$f['company_id'],$f['book_id'],$input);
    $posted=pl_post_document($f['actor_id'],$f['company_id'],$f['book_id'],$draft['id'],1);
    $input['party_id']=$second['id'];
    pl_correct_source($f['actor_id'],$f['company_id'],$f['book_id'],'receipt',$posted['id'],1,$input,null,bin2hex(random_bytes(16)),'Correct payer');
    $read=pl_get_document($f['actor_id'],$f['company_id'],$f['book_id'],$posted['id']);
    assert_same($second['id'],$read['party_id']); assert_same('Second fictional payer',$read['counterparty']);
    assert_same($first['id'],(int)DB::queryFirstField('SELECT party_id FROM pl_documents WHERE id=%i',$posted['id']));
    unset($input['party_id']); $input['amount']='126';
    pl_correct_source($f['actor_id'],$f['company_id'],$f['book_id'],'receipt',$posted['id'],2,$input,null,bin2hex(random_bytes(16)),'Correct amount with an older caller');
    assert_same($second['id'],pl_get_document($f['actor_id'],$f['company_id'],$f['book_id'],$posted['id'])['party_id']);
});

test('older draft callers preserve a linked identity and cannot silently replace it with free text',function():void {
    $f=ledger_fixture(); $party=document_party_fixture($f,'Original draft payee');
    $input=document_input($f); $input['party_id']=$party['id'];
    $draft=pl_save_document($f['actor_id'],$f['company_id'],$f['book_id'],$input);
    DB::update('pl_parties',['legal_name'=>'Current draft payee'],'id=%i',$party['id']);
    unset($input['party_id']); $input['counterparty']='Older caller supplied free text'; $input['memo']='Updated by an older caller';
    $updated=pl_save_document($f['actor_id'],$f['company_id'],$f['book_id'],$input,$draft['id'],1);
    assert_same($party['id'],$updated['party_id']); assert_same('Current draft payee',$updated['counterparty']);
    assert_same('Updated by an older caller',$updated['memo']);
    $input['party_id']=null;
    assert_throws(fn()=>pl_save_document($f['actor_id'],$f['company_id'],$f['book_id'],$input,$draft['id'],2),DomainException::class,'cannot be changed back');
    assert_same($party['id'],pl_get_document($f['actor_id'],$f['company_id'],$f['book_id'],$draft['id'])['party_id']);
});
