<?php
declare(strict_types=1);

function payroll_fixture(): array
{
    $f=employee_fixture();
    $f['accounts']=pl_payroll_provision_accounts($f['actor_id'],$f['company_id'],$f['book_id']);
    $f['bank']=(int)DB::queryFirstField("SELECT id FROM pl_accounts WHERE book_id=%i AND role='cash_bank' AND is_active=1 LIMIT 1",$f['book_id']);
    return $f;
}
function payroll_input(array $f,array $overrides=[]): array
{
    return $overrides+['period_from'=>'2026-01-01','period_to'=>'2026-01-31','date'=>'2026-01-31','external_reference'=>'sample:'.bin2hex(random_bytes(6)),
        'request_key'=>bin2hex(random_bytes(16)), 'elements'=>[
            ['kind'=>'gross_pay','account_id'=>$f['accounts']['gross_pay'],'amount'=>'10000'],
            ['kind'=>'employer_contribution','account_id'=>$f['accounts']['employer_contribution'],'amount'=>'1000'],
            ['kind'=>'withholding','account_id'=>$f['accounts']['withholding'],'amount'=>'1500'],
            ['kind'=>'social_security','account_id'=>$f['accounts']['social_security'],'amount'=>'1500'],
            ['kind'=>'net_pay','account_id'=>$f['accounts']['net_pay'],'amount'=>'8000'],
        ]];
}
function payroll_payment(array $f,array $payroll,string $kind,string $amount,string $date='2026-02-05'): array
{
    $element=array_values(array_filter($payroll['elements'],static fn(array $e):bool=>$e['element_kind']===$kind))[0];
    return pl_prepare_payroll_payment($f['actor_id'],$f['company_id'],$f['book_id'],$payroll['id'],[
        'date'=>$date,'bank_account_id'=>$f['bank'],'creation_key'=>bin2hex(random_bytes(16)),
        'allocations'=>[['element_id'=>$element['id'],'amount'=>$amount]]]);
}

test('payroll two-period accounting accrues aggregates and settles net pay and withholding without second expense',function():void{
    $f=payroll_fixture(); $input=payroll_input($f);
    $payroll=pl_post_payroll($f['actor_id'],$f['company_id'],$f['book_id'],$input);
    assert_same($payroll['id'],pl_post_payroll($f['actor_id'],$f['company_id'],$f['book_id'],$input)['id']);
    foreach (['net_pay'=>'8000','withholding'=>'1500'] as $kind=>$amount) {
        $draft=payroll_payment($f,$payroll,$kind,$amount);
        assert_same(null,$draft['journal_id'],'Preparing a payment must not post or transfer money.');
        pl_post_general_draft($f['actor_id'],$f['company_id'],$f['book_id'],$draft['id'],$draft['revision']);
    }
    $after=pl_get_payroll($f['actor_id'],$f['company_id'],$f['book_id'],$payroll['id']);
    $byKind=array_column($after['elements'],null,'element_kind');
    assert_same('0.0000',$byKind['net_pay']['remaining']); assert_same('0.0000',$byKind['withholding']['remaining']);
    assert_same('1500.0000',$byKind['social_security']['remaining']);
    $before=pl_get_payroll($f['actor_id'],$f['company_id'],$f['book_id'],$payroll['id'],'2026-01-31');
    assert_same('8000.0000',array_column($before['elements'],null,'element_kind')['net_pay']['remaining']);
    $expense=(string)DB::queryFirstField('SELECT SUM(l.debit-l.credit) FROM pl_journal_lines l JOIN pl_accounts a ON a.id=l.account_id WHERE a.book_id=%i AND a.type=%s',$f['book_id'],'expense');
    assert_same('11000.0000',$expense);
    assert_same(0,(int)DB::queryFirstField('SELECT COUNT(*) FROM pl_open_items WHERE book_id=%i',$f['book_id']));
    assert_true(isset(pl_read_catalog()['payroll_journals']));
});

test('payroll validates types totals period permissions source identity and immutable history',function():void{
    $f=payroll_fixture();$input=payroll_input($f);$bad=$input;$bad['elements'][4]['amount']='7999';
    assert_throws(fn()=>pl_post_payroll($f['actor_id'],$f['company_id'],$f['book_id'],$bad),DomainException::class,'balance');
    $bad=$input;$bad['elements'][4]['account_id']=$f['accounts']['gross_pay'];
    assert_throws(fn()=>pl_post_payroll($f['actor_id'],$f['company_id'],$f['book_id'],$bad),DomainException::class,'account');
    $reader=employee_member($f,['company.read','payroll.view']);
    assert_throws(fn()=>pl_post_payroll($reader,$f['company_id'],$f['book_id'],$input),DomainException::class);
    $row=pl_post_payroll($f['actor_id'],$f['company_id'],$f['book_id'],$input);
    assert_same($row['id'],pl_get_payroll($reader,$f['company_id'],$f['book_id'],$row['id'])['id']);
    $input['description']='Changed retry';assert_throws(fn()=>pl_post_payroll($f['actor_id'],$f['company_id'],$f['book_id'],$input),DomainException::class,'different totals');
    assert_throws(fn()=>DB::update('pl_payroll_journals',['description'=>'tamper'],'id=%i',$row['id']),Throwable::class,'immutable');
    assert_throws(fn()=>DB::query('DELETE FROM pl_payroll_elements WHERE payroll_id=%i',$row['id']),Throwable::class,'immutable');
    $payload=['date'=>'2026-01-31','currency'=>'USD','source_type'=>'payroll','source_reference'=>'payroll:999999999','idempotency_key'=>'fake-payroll',
        'description'=>'Fake', 'lines'=>[['account_id'=>$f['accounts']['gross_pay'],'debit'=>'1','credit'=>'0'],['account_id'=>$f['accounts']['net_pay'],'debit'=>'0','credit'=>'1']]];
    assert_throws(fn()=>pl_post_journal($f['actor_id'],$f['company_id'],$f['book_id'],$payload),DomainException::class,'payroll accounting service');
});

test('payroll partial payments cannot overpay through changed journals or competing drafts',function():void{
    $f=payroll_fixture();$row=pl_post_payroll($f['actor_id'],$f['company_id'],$f['book_id'],payroll_input($f));
    $first=payroll_payment($f,$row,'net_pay','5000');$second=payroll_payment($f,$row,'net_pay','5000');
    pl_post_general_draft($f['actor_id'],$f['company_id'],$f['book_id'],$first['id'],1);
    assert_throws(fn()=>pl_post_general_draft($f['actor_id'],$f['company_id'],$f['book_id'],$second['id'],1),DomainException::class,'Another payment');
    $draft=payroll_payment($f,$row,'net_pay','3000');
    $changed=$draft['lines'];$changed[0]['debit']='1';$changed[1]['credit']='1';
    $edit=pl_save_general_draft($f['actor_id'],$f['company_id'],$f['book_id'],['date'=>$draft['document_date'],'description'=>$draft['description'],'lines'=>$changed],$draft['id'],1);
    assert_throws(fn()=>pl_post_general_draft($f['actor_id'],$f['company_id'],$f['book_id'],$edit['id'],$edit['revision']),DomainException::class,'differs from its allocation');
    assert_throws(fn()=>pl_reverse_journal($f['actor_id'],$f['company_id'],$f['book_id'],$row['journal_id'],gmdate('Y-m-d'),'sample-reverse','Sample correction.'),DomainException::class,'Reverse payroll payments');
    pl_reverse_general_draft($f['actor_id'],$f['company_id'],$f['book_id'],$first['id'],gmdate('Y-m-d'),'Sample payment correction.');
    pl_reverse_journal($f['actor_id'],$f['company_id'],$f['book_id'],$row['journal_id'],gmdate('Y-m-d'),'sample-reverse','Sample accrual correction.');
    assert_throws(fn()=>pl_post_general_draft($f['actor_id'],$f['company_id'],$f['book_id'],$second['id'],1),DomainException::class,'reversed');
});

test('payroll staff advance recovery is separate from trade receivables and payroll failures roll back',function():void{
    $f=payroll_fixture();$input=payroll_input($f);$input['elements'][4]['amount']='7500';$input['elements'][]=['kind'=>'staff_advance','account_id'=>$f['accounts']['staff_advance'],'amount'=>'500'];
    assert_throws(fn()=>pl_post_payroll($f['actor_id'],$f['company_id'],$f['book_id'],$input),DomainException::class,'recorded advance balance');
    $advance=pl_account_payment_plan($f['actor_id'],$f['company_id'],$f['book_id'],['date'=>'2026-01-01','bank_account_id'=>$f['bank'],'description'=>'Sample aggregate staff advance','creation_key'=>bin2hex(random_bytes(16)),'debits'=>[['account_id'=>$f['accounts']['staff_advance'],'amount'=>'500']]]);
    pl_post_general_draft($f['actor_id'],$f['company_id'],$f['book_id'],$advance['id'],1);
    $row=pl_post_payroll($f['actor_id'],$f['company_id'],$f['book_id'],$input);
    $elements=array_column($row['elements'],null,'element_kind');assert_same(false,$elements['staff_advance']['settleable']);
    assert_throws(fn()=>payroll_payment($f,$row,'staff_advance','100'),DomainException::class,'payable element');
    $before=(int)DB::queryFirstField('SELECT COUNT(*) FROM pl_payroll_journals WHERE book_id=%i',$f['book_id']);
    assert_throws(function()use($f):void{pl_ledger_transaction(function()use($f):void{pl_post_payroll($f['actor_id'],$f['company_id'],$f['book_id'],payroll_input($f));throw new DomainException('Sample rollback');});},DomainException::class,'rollback');
    assert_same($before,(int)DB::queryFirstField('SELECT COUNT(*) FROM pl_payroll_journals WHERE book_id=%i',$f['book_id']));
});

test('employee operational links require explicit same-company employment and never manufacture identity',function():void{
    $f=employee_fixture();$a=$f['actor_id'];$c=$f['company_id'];$b=$f['book_id'];
    foreach(['inventory','trading-documents'] as $module){$manifest=pl_module_registry()[$module];pl_set_company_module($a,$c,$module,true,0,$manifest['digest'],'Sample module',bin2hex(random_bytes(16)));}
    assert_throws(fn()=>pl_save_sales_staff($a,$c,$b,['code'=>'NEW','name'=>'Somebody','reason'=>'Test']),DomainException::class,'Select an employee');
    $e=pl_save_employee($a,$c,employee_input(['full_name'=>'Sample linked person']));
    $staff=pl_save_sales_staff($a,$c,$b,['code'=>'LINK','employee_id'=>$e['id'],'reason'=>'Explicit sample assignment']);
    assert_same($e['id'],$staff['employee_id']);assert_same('Sample linked person',$staff['name']);
    DB::insert('pl_sales_staff',['company_id'=>$c,'book_id'=>$b,'code'=>'LEGACY','name'=>'Historical spelling','is_active'=>true,'created_by'=>$a]);$legacy=(int)DB::insertId();
    $old=pl_get_sales_staff($a,$c,$b,$legacy);assert_same(null,$old['employee_id']);
    pl_link_employee_reference($a,$c,$b,'sales_staff',$legacy,$e['id'],$old['revision'],'Explicit same person');
    $linked=pl_get_sales_staff($a,$c,$b,$legacy);assert_same('Historical spelling',$linked['legacy_name']);assert_same('Sample linked person',$linked['name']);
    $foreign=employee_fixture();$fe=pl_save_employee($foreign['actor_id'],$foreign['company_id'],employee_input());
    assert_throws(fn()=>pl_link_employee_reference($a,$c,$b,'sales_staff',$legacy,$fe['id'],$linked['revision'],'Wrong company'),DomainException::class);
    assert_same(1,(int)DB::queryFirstField('SELECT COUNT(*) FROM pl_employees WHERE company_id=%i',$c));
});

test('employee trade associations and identity candidates never infer a related-party marker',function():void{
    $f=employee_fixture();$a=$f['actor_id'];$c=$f['company_id'];$b=$f['book_id'];
    $e=pl_save_employee($a,$c,employee_input(['full_name'=>'Sample matched person']));
    $p=pl_save_party($a,$c,$b,['legal_name'=>'Sample matched person','entity_type'=>'individual','country_code'=>'GB','is_customer'=>true,'is_vendor'=>false,'currency'=>'USD','request_key'=>bin2hex(random_bytes(16)),'reason'=>'Explicit sample trade identity']);
    $matches=pl_employee_trade_candidates($a,$c);assert_same('name',$matches[0]['matched_on'][0]);assert_true(!$matches[0]['linked']);
    $linked=pl_link_employee_trade_party($a,$c,$e['id'],$p['id'],$e['revision'],'Confirmed identity');assert_same($p['id'],$linked['trade_party_id']);
    assert_true(pl_employee_trade_candidates($a,$c)[0]['linked']);
    assert_same(0,(int)DB::queryFirstField('SELECT COUNT(*) FROM pl_related_party_markers WHERE company_id=%i',$c));
    assert_throws(fn()=>pl_link_employee_trade_party($a,$c,$e['id'],null,$e['revision'],'Stale'),DomainException::class,'changed');
    $reader=employee_member($f,['company.read','employee.view']);assert_throws(fn()=>pl_employee_trade_candidates($reader,$c),DomainException::class);
});

test('shared public demo login remains usable while other users and harmless profile settings remain editable',function():void{
    require_once dirname(__DIR__).'/www/phpledger/includes/functions/installation_state_functions.php';
    $f=employee_fixture();$id=$f['actor_id'];$dir=sys_get_temp_dir().'/shared-demo-policy-'.bin2hex(random_bytes(8));mkdir($dir,0700);
    $prior=getenv('PL_INSTALL_DIRECTORY');putenv('PL_INSTALL_DIRECTORY='.$dir);pl_install_save_state(['initial_owner_id'=>$id],'installed.json');
    $user=pl_user_row($id);$beforeHash=DB::queryFirstField('SELECT password_hash FROM pl_users WHERE id=%i',$id);
    try{
        putenv('PL_ENV=demo-install');assert_true(pl_shared_demo_is_account($id));
        foreach([
            fn()=>pl_change_own_password($id,$f['password'],'Other-password-123!'),
            fn()=>pl_request_email_change($id,'other@example.invalid'),
            fn()=>pl_confirm_email_change($id,'sample'),
            fn()=>pl_complete_password_reset($f['email'],'sample','Other-password-123!'),
            fn()=>pl_force_password_reset($id,$f['company_id'],$id,'Test reset'),
            fn()=>pl_set_user_active($id,$f['company_id'],$id,false,'Test suspend'),
            fn()=>pl_remove_company_member($id,$f['company_id'],$id,'Test remove'),
            fn()=>pl_anonymise_user($id,$f['company_id'],$id,'Test anonymise'),
            fn()=>pl_update_profile($id,['display_name'=>'Demo','username'=>'changed']),
            fn()=>pl_set_installation_grant($id,$id,'installation.admin',false,'Test revoke'),
            fn()=>pl_assign_company_role($id,$f['company_id'],$id,(int)DB::queryFirstField("SELECT id FROM pl_roles WHERE company_id IS NULL AND slug='viewer'"),'Test demote'),
        ] as $action){assert_throws($action,DomainException::class,'shared demo login');}
        pl_update_profile($id,['display_name'=>'Sample display name','username'=>$user['username']??'']);
        pl_set_user_locale($id,'en');assert_same($beforeHash,DB::queryFirstField('SELECT password_hash FROM pl_users WHERE id=%i',$id));
        $other=pl_create_user('demo-other-'.bin2hex(random_bytes(5)).'@example.invalid','Sample other','Sample-password-123!');
        assert_true(!pl_shared_demo_is_account($other));pl_update_profile($other,['display_name'=>'Edited sample user','username'=>'other'.bin2hex(random_bytes(5))]);
        putenv('PL_ENV=test');assert_true(!pl_shared_demo_is_account($id));pl_update_profile($id,['display_name'=>'Normal installation','username'=>'normal'.bin2hex(random_bytes(5))]);
    }finally{putenv('PL_ENV=test');putenv($prior===false?'PL_INSTALL_DIRECTORY':'PL_INSTALL_DIRECTORY='.$prior);unlink($dir.'/installed.json');rmdir($dir);}
});

test('payroll current allocation reads reject overpayment from an older caller snapshot',function():void{
    $f=payroll_fixture();$row=pl_post_payroll($f['actor_id'],$f['company_id'],$f['book_id'],payroll_input($f));
    $element=array_column($row['elements'],null,'element_kind')['net_pay']['id'];
    DB::startTransaction();
    try{
        DB::queryFirstField('SELECT COUNT(*) FROM pl_payroll_payment_lines');
        $job=$f+['payroll'=>$row['id'],'element'=>$element];
        $process=proc_open([PHP_BINARY,__DIR__.'/payroll_snapshot_worker.php',json_encode($job,JSON_THROW_ON_ERROR)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($process)){throw new RuntimeException('Payroll snapshot worker unavailable.');}fclose($pipes[0]);
        $json=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);assert_same(0,proc_close($process),$error);
        $second=json_decode($json,true,32,JSON_THROW_ON_ERROR);
        assert_throws(fn()=>pl_post_general_draft($f['actor_id'],$f['company_id'],$f['book_id'],$second['id'],1),DomainException::class,'Another payment');
    }finally{DB::rollback();}
});

test('payroll accrual reversal cannot precede the effective reversal of its settled payments',function():void{
    $f=payroll_fixture();$row=pl_post_payroll($f['actor_id'],$f['company_id'],$f['book_id'],payroll_input($f));
    $draft=payroll_payment($f,$row,'net_pay','5000');$posted=pl_post_general_draft($f['actor_id'],$f['company_id'],$f['book_id'],$draft['id'],1);
    pl_reverse_journal($f['actor_id'],$f['company_id'],$f['book_id'],$posted['journal_id'],gmdate('Y-m-d',time()+86400),bin2hex(random_bytes(16)),'Sample future payment reversal');
    assert_throws(fn()=>pl_reverse_journal($f['actor_id'],$f['company_id'],$f['book_id'],$row['journal_id'],gmdate('Y-m-d'),bin2hex(random_bytes(16)),'Too early'),DomainException::class,'effective date');
    pl_reverse_journal($f['actor_id'],$f['company_id'],$f['book_id'],$row['journal_id'],gmdate('Y-m-d',time()+86400),bin2hex(random_bytes(16)),'After effective payment reversal');
});
