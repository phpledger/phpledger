<?php
declare(strict_types=1);

require_once __DIR__.'/party_outbound_race.php';
require_once dirname(__DIR__).'/www/phpledger/includes/functions/web_functions.php';

test('party list pages scoped records with literal search and role filters',function(): void {
    $f=ledger_fixture();
    for ($i=0;$i<26;$i++) {
        $input=party_input(); unset($input['identifiers']);
        $input['legal_name']='List party '.str_pad((string)$i,2,'0',STR_PAD_LEFT);
        $input['trading_name']=$i===25?'Literal % match':'Trading'; $input['is_vendor']=$i%2===0;
        pl_save_party($f['actor_id'],$f['company_id'],$f['book_id'],$input);
    }
    $run=fn(array $q):array=>pl_list_query($f['actor_id'],$f['company_id'],$f['book_id'],'parties',$q);
    $first=$run([]); $last=$run(['page'=>'999']);
    assert_same(26,$first['total']); assert_same(25,count($first['rows'])); assert_same(2,$last['page']); assert_same(1,count($last['rows']));
    assert_same('List party 25',$last['rows'][0]['legal_name']);
    assert_same(13,$run(['role'=>'vendor'])['total']); assert_same(26,$run(['role'=>'customer'])['total']);
    assert_same(1,$run(['q'=>'%'])['total']); assert_same('List party 25',$run(['dir'=>'desc'])['rows'][0]['legal_name']);
    foreach ([['sort'=>'name DESC'],['dir'=>'invalid'],['role'=>'owner']] as $bad) { assert_throws(fn()=>$run($bad),DomainException::class); }
    $other=ledger_fixture(); assert_throws(fn()=>pl_list_query($other['actor_id'],$f['company_id'],$f['book_id'],'parties',[]),DomainException::class);
});

function party_input(string $country='PK'): array
{
    return ['legal_name'=>'Sample dual role party','entity_type'=>'private_company','country_code'=>$country,
        'is_customer'=>true,'is_vendor'=>true,'currency'=>'USD','request_key'=>bin2hex(random_bytes(16)),
        'reason'=>'Sample foundation entry','localized_names'=>['ur'=>'فرضی کمپنی'],
        'identifiers'=>[['scheme'=>$country==='PK'?'NTN':'TAX_ID','value'=>'1234567','country_code'=>$country]],
        'addresses'=>[['role'=>'registered','country_code'=>$country,'subdivision'=>'Sample region','street'=>'Sample street']],
        'registrations'=>[['country_code'=>$country,'authority'=>'Sample authority','number'=>'TEST123']],
        'exemption_reference'=>'SAMPLE-EXEMPT','exemption_expires_on'=>'2027-12-31','credit_limit'=>'100.2500'];
}

test('party storage is country neutral, dual role and creates one private-data-free event',function():void{
    $f=ledger_fixture();$input=party_input();
    $receipt=pl_save_party($f['actor_id'],$f['company_id'],$f['book_id'],$input);
    assert_same($receipt,pl_save_party($f['actor_id'],$f['company_id'],$f['book_id'],$input));
    $party=pl_get_party($f['actor_id'],$f['company_id'],$f['book_id'],$receipt['id']);
    assert_same(1,(int)$party['is_customer']);assert_same(1,(int)$party['is_vendor']);assert_same('draft',$party['status']);
    assert_same('فرضی کمپنی',$party['details']['localized_names']['ur']);assert_same('100.2500',$party['financial']['credit_limit']);
    assert_same(1,(int)DB::queryFirstField('SELECT COUNT(*) FROM pl_party_status_history WHERE party_id=%i',$receipt['id']));
    $event=DB::queryFirstRow('SELECT * FROM pl_outbound_events WHERE book_id=%i',$f['book_id']);
    $payload=json_decode($event['payload'],true,512,JSON_THROW_ON_ERROR);
    assert_same('party',$payload['entity_type']);assert_same($receipt['id'],$payload['entity_id']);assert_same(1,$payload['revision']);assert_same(3,count($payload));
    assert_true(!str_contains($event['payload'],'1234567'));
    $other=party_input('GB');$other['legal_name']='Sample second jurisdiction';
    pl_save_party($f['actor_id'],$f['company_id'],$f['book_id'],$other);
});

test('legal identifier dedup blocks canonical collision and allows isolated company identity',function():void{
    $f=ledger_fixture();$input=party_input();pl_save_party($f['actor_id'],$f['company_id'],$f['book_id'],$input);
    $duplicate=party_input();$duplicate['identifiers'][0]['value']='123-4567';
    assert_throws(fn()=>pl_save_party($f['actor_id'],$f['company_id'],$f['book_id'],$duplicate),DomainException::class,'already belongs');
    assert_same(1,(int)DB::queryFirstField('SELECT COUNT(*) FROM pl_parties WHERE company_id=%i',$f['company_id']));
    assert_same(1,(int)DB::queryFirstField('SELECT COUNT(*) FROM pl_outbound_events WHERE book_id=%i',$f['book_id']));
    $other=ledger_fixture();pl_save_party($other['actor_id'],$other['company_id'],$other['book_id'],$duplicate);
});

test('party mutations enforce revision, scope, membership and reserved schema boundaries',function():void{
    $f=ledger_fixture();$input=party_input();$r=pl_save_party($f['actor_id'],$f['company_id'],$f['book_id'],$input);
    $changed=$input;$changed['request_key']=bin2hex(random_bytes(16));$changed['legal_name']='Sample corrected name';
    $updated=pl_save_party($f['actor_id'],$f['company_id'],$f['book_id'],$changed,$r['id'],1);assert_same(2,$updated['revision']);
    $changed['request_key']=bin2hex(random_bytes(16));
    assert_throws(fn()=>pl_save_party($f['actor_id'],$f['company_id'],$f['book_id'],$changed,$r['id'],1),DomainException::class,'revision');
    $other=ledger_fixture();
    assert_throws(fn()=>pl_get_party($other['actor_id'],$other['company_id'],$other['book_id'],$r['id']),DomainException::class);
    foreach(['status'=>'approved','opening_balance'=>'100','bank_accounts'=>[],'attachments'=>[],'tags'=>[]] as $field=>$value){
        $invalid=party_input();$invalid[$field]=$value;
        assert_throws(fn()=>pl_save_party($f['actor_id'],$f['company_id'],$f['book_id'],$invalid),DomainException::class);
    }
    sample_membership_insert(['company_id'=>$f['company_id'],'user_id'=>$other['actor_id'],'role'=>'viewer']);
    assert_throws(fn()=>pl_save_party($other['actor_id'],$f['company_id'],$f['book_id'],party_input()),DomainException::class);
    assert_throws(fn()=>DB::query("UPDATE pl_party_status_history SET reason='changed' WHERE party_id=%i",$r['id']));
    assert_throws(fn()=>DB::delete('pl_party_actions','party_id=%i',$r['id']));
});

test('phone duplicates need explicit acknowledgement and reason without silent merge',function():void{
    $f=ledger_fixture();$r=pl_save_party($f['actor_id'],$f['company_id'],$f['book_id'],party_input());
    $input=['name'=>'Sample billing contact','role'=>'billing','is_primary'=>true,'reason'=>'Sample contact entry',
        'request_key'=>bin2hex(random_bytes(16)),'phones'=>[['phone'=>'+92 300 1234567','is_whatsapp'=>true]]];
    $first=pl_save_contact($f['actor_id'],$f['company_id'],$f['book_id'],$r['id'],$input);
    assert_same($first,pl_save_contact($f['actor_id'],$f['company_id'],$f['book_id'],$r['id'],$input));
    $input['request_key']=bin2hex(random_bytes(16));$input['name']='Sample shared-office contact';$input['is_primary']=false;
    $input['phones'][0]['phone']='+92(300)123-4567';
    assert_throws(fn()=>pl_save_contact($f['actor_id'],$f['company_id'],$f['book_id'],$r['id'],$input),DomainException::class,'acknowledgement');
    $input['acknowledge_phone_duplicates']=true;
    assert_throws(fn()=>pl_save_contact($f['actor_id'],$f['company_id'],$f['book_id'],$r['id'],$input),DomainException::class,'reason');
    $input['duplicate_reason']='Shared sample office telephone confirmed';
    $second=pl_save_contact($f['actor_id'],$f['company_id'],$f['book_id'],$r['id'],$input);
    assert_true($second['id']!==$first['id']);
    assert_same(1,(int)DB::queryFirstField('SELECT phone_duplicate_acknowledged FROM pl_party_actions WHERE company_id=%i AND request_key=%s',$f['company_id'],$input['request_key']));
    assert_same('+92(300)123-4567',pl_get_contact($f['actor_id'],$f['company_id'],$f['book_id'],$r['id'],$second['id'])['phones'][0]['phone']);
    assert_throws(fn()=>pl_party_phone('03001234567'),DomainException::class);
    assert_same('+923001234567',pl_party_phone('+92 300 1234567'));
});

test('party failure and caller rollback preserve source and event atomicity',function():void{
    $f=ledger_fixture();$bad=party_input();$bad['exemption_expires_on']='2026-02-30';
    assert_throws(fn()=>pl_save_party($f['actor_id'],$f['company_id'],$f['book_id'],$bad),DomainException::class);
    $bad=party_input();$bad['credit_limit']='0.00001';
    assert_throws(fn()=>pl_save_party($f['actor_id'],$f['company_id'],$f['book_id'],$bad),DomainException::class);
    assert_throws(function()use($f):void{pl_ledger_transaction(function()use($f):void{pl_save_party($f['actor_id'],$f['company_id'],$f['book_id'],party_input());throw new DomainException('Rollback sample mutation');});});
    assert_same(0,(int)DB::queryFirstField('SELECT COUNT(*) FROM pl_parties WHERE company_id=%i',$f['company_id']));
    assert_same(0,(int)DB::queryFirstField('SELECT COUNT(*) FROM pl_outbound_events WHERE book_id=%i',$f['book_id']));
});

test('concurrent normalized tax identities create one scoped party and event',function():void{
    $f=ledger_fixture();$first=party_input();$second=party_input();$second['identifiers'][0]['value']='123-4567';
    $results=pl_foundation_race([['mode'=>'party','fixture'=>$f,'input'=>$first],['mode'=>'party','fixture'=>$f,'input'=>$second]]);
    $success=array_values(array_filter($results,static fn(array $r):bool=>$r['ok']));
    $failure=array_values(array_filter($results,static fn(array $r):bool=>!$r['ok']));
    assert_same(1,count($success));assert_same(1,count($failure));assert_true(str_contains($failure[0]['error'],'already belongs'));
    assert_same(1,(int)DB::queryFirstField('SELECT COUNT(*) FROM pl_parties WHERE company_id=%i',$f['company_id']));
    assert_same(1,(int)DB::queryFirstField('SELECT COUNT(*) FROM pl_party_identifiers WHERE company_id=%i',$f['company_id']));
    assert_same(1,(int)DB::queryFirstField('SELECT COUNT(*) FROM pl_outbound_events WHERE book_id=%i',$f['book_id']));
});

test('concurrent shared phone entries require acknowledgement without losing either acknowledged contact',function():void{
    $f=ledger_fixture();$party=pl_save_party($f['actor_id'],$f['company_id'],$f['book_id'],party_input());
    $first=['name'=>'Sample first caller','role'=>'billing','reason'=>'Sample race','request_key'=>bin2hex(random_bytes(16)),
        'phones'=>[['phone'=>'+44 7700 900123','is_whatsapp'=>false]]];
    $second=$first;$second['request_key']=bin2hex(random_bytes(16));$second['name']='Sample second caller';$second['phones'][0]['phone']='+44(7700)900-123';
    $job=['mode'=>'contact','fixture'=>$f,'party_id'=>$party['id']];
    $results=pl_foundation_race([$job+['input'=>$first],$job+['input'=>$second]]);
    assert_same(1,count(array_filter($results,static fn(array $r):bool=>$r['ok'])));
    $failed=array_values(array_filter($results,static fn(array $r):bool=>!$r['ok']));assert_true(str_contains($failed[0]['error'],'acknowledgement'));
    foreach([&$first,&$second] as &$input){$input['request_key']=bin2hex(random_bytes(16));$input['acknowledge_phone_duplicates']=true;$input['duplicate_reason']='Shared sample reception phone confirmed';}unset($input);
    $accepted=pl_foundation_race([$job+['input'=>$first],$job+['input'=>$second]]);
    assert_same(2,count(array_filter($accepted,static fn(array $r):bool=>$r['ok'])));
    assert_same(3,(int)DB::queryFirstField('SELECT COUNT(*) FROM pl_contacts WHERE party_id=%i',$party['id']));
    assert_same(2,(int)DB::queryFirstField('SELECT COUNT(*) FROM pl_party_actions WHERE party_id=%i AND phone_duplicate_acknowledged=1',$party['id']));
    assert_same(4,(int)DB::queryFirstField('SELECT COUNT(*) FROM pl_outbound_events WHERE book_id=%i',$f['book_id']));
});
