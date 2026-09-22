<?php
declare(strict_types=1);

function year_fixture(): array
{
    $f=ledger_fixture();
    $retained=(int)pl_save_account($f['actor_id'],$f['company_id'],$f['book_id'],['code'=>'3-100-19001-00','name'=>'Retained earnings','type'=>'equity','is_contra'=>false,'is_active'=>true,'role'=>null,'reason'=>'Year-end example','creation_key'=>bin2hex(random_bytes(16))])['id'];
    return $f+['retained'=>$retained];
}
function year_policy(array $f): array {return ['treatment'=>'company','legal_basis'=>'Private company, earnings retained; no dividend approved.','review_note'=>'Internal accounting worked example reviewed.','destination_account_id'=>$f['retained']];}
function year_start(array $f,?array $policy=null): int
{
    $y=pl_year_end_create($f['actor_id'],$f['company_id'],$f['book_id'],'2026-12-31',$policy??year_policy($f),'create');
    pl_year_end_change($f['actor_id'],$f['company_id'],$f['book_id'],$y['year_id'],'start',1,'Ready for final adjustments','start');return $y['year_id'];
}
function year_close(array $f,int $id,string $key='close'): array
{
    $p=pl_year_end_preview($f['actor_id'],$f['company_id'],$f['book_id'],$id);
    return pl_year_end_change($f['actor_id'],$f['company_id'],$f['book_id'],$id,'close',$p['year']['revision'],'Valuation, provisions, adjustments and payroll reviewed; non-applicable items checked.',$key,['fingerprint'=>$p['fingerprint'],'stock'=>true,'provisions'=>true,'adjustments'=>true,'payroll'=>true]);
}
function year_balance(array $f,int $account): string
{
    foreach(pl_trial_balance($f['actor_id'],$f['company_id'],$f['book_id'],'2026-12-31')['accounts'] as $row){if($row['id']===$account){return $row['balance'];}}throw new RuntimeException('Missing account.');
}

test('year-end return to preparation permits blocked operational work without losing adjustment evidence',function():void{
    $f=year_fixture();$id=year_start($f);$a=pl_year_end_adjust($f['actor_id'],$f['company_id'],$f['book_id'],$id,['description'=>'Reviewed adjustment','lines'=>ledger_payload($f,'10')['lines']],'adjust');
    $draft=pl_save_general_draft($f['actor_id'],$f['company_id'],$f['book_id'],['date'=>'2026-09-14','reference'=>'Pending task','description'=>'Operational task','creation_key'=>'pending','lines'=>ledger_payload($f,'15')['lines']]);
    assert_throws(fn()=>year_close($f,$id),DomainException::class,'unposted drafts');
    pl_year_end_change($f['actor_id'],$f['company_id'],$f['book_id'],$id,'prepare',2,'Complete operational draft','prepare');
    assert_same($a['journal_id'],pl_get_journal($f['actor_id'],$f['company_id'],$f['book_id'],$a['journal_id'])['id']);
    pl_post_general_draft($f['actor_id'],$f['company_id'],$f['book_id'],$draft['id'],$draft['revision']);
    pl_year_end_change($f['actor_id'],$f['company_id'],$f['book_id'],$id,'start',3,'Operational work complete','resume');year_close($f,$id);assert_same('-25.0000',year_balance($f,$f['retained']));
});

test('year-end sole-trader loss and drawings close to capital without pretending a distribution',function():void{
    $f=year_fixture();$drawings=(int)DB::queryFirstField("SELECT id FROM pl_accounts WHERE book_id=%i AND type='equity' AND is_contra=1 LIMIT 1",$f['book_id']);
    pl_post_journal($f['actor_id'],$f['company_id'],$f['book_id'],ledger_payload($f,'100'));
    foreach([[$f['accounts']['5000'],'200'],[$drawings,'20']] as [$account,$amount]){$p=ledger_payload($f,$amount);$p['lines'][0]['account_id']=$account;$p['lines'][1]['account_id']=$f['accounts']['1000'];pl_post_journal($f['actor_id'],$f['company_id'],$f['book_id'],$p);}
    $id=year_start($f,['treatment'=>'sole_trader','legal_basis'=>'Sole proprietor, drawings close to own capital','review_note'=>'Loss example reviewed','destination_account_id'=>$f['accounts']['3000'],'drawings_account_id'=>$drawings]);
    year_close($f,$id);assert_same('120.0000',year_balance($f,$f['accounts']['3000']));assert_same('0.0000',year_balance($f,$drawings));assert_same('-100.0000',pl_profit_loss($f['actor_id'],$f['company_id'],$f['book_id'],'2026-01-01','2026-12-31')['net_profit']);
});

test('year-end refuses pending depreciation before starting and uses existing asset schedule arithmetic',function():void{
    $f=year_fixture();$m=pl_module_registry()['fixed-assets'];pl_set_company_module($f['actor_id'],$f['company_id'],'fixed-assets',true,0,$m['digest'],'Year-end depreciation fixture','enable-assets');
    $class=pl_save_asset_class($f['actor_id'],$f['company_id'],$f['book_id'],['code'=>'YE','name'=>'Year-end asset class','method'=>'straight_line','useful_life_months'=>24,'annual_rate'=>null,'is_active'=>true,'reason'=>'Asset example','idempotency_key'=>'class']);
    pl_save_asset($f['actor_id'],$f['company_id'],$f['book_id'],['class_id'=>$class['id'],'code'=>'YE1','name'=>'Year-end equipment','acquisition_date'=>'2026-01-01','in_service_date'=>'2026-01-01','cost'=>'2400','residual_value'=>'0','credit_account_id'=>$f['accounts']['1000'],'reason'=>'Asset example','idempotency_key'=>'asset']);
    $year=pl_year_end_create($f['actor_id'],$f['company_id'],$f['book_id'],'2026-12-31',year_policy($f),'create');$id=$year['year_id'];assert_throws(fn()=>pl_year_end_change($f['actor_id'],$f['company_id'],$f['book_id'],$id,'start',1,'Review','start'),DomainException::class,'depreciation');
    pl_run_asset_depreciation($f['actor_id'],$f['company_id'],$f['book_id'],['period_id'=>$f['period_id'],'reason'=>'Depreciation reviewed','idempotency_key'=>'depreciation']);
    pl_year_end_change($f['actor_id'],$f['company_id'],$f['book_id'],$id,'start',1,'Review','start');assert_same('-1200.0000',pl_year_end_preview($f['actor_id'],$f['company_id'],$f['book_id'],$id)['profit']);year_close($f,$id);
});

test('year-end read API and service enforce current role, grant scope and company scope',function():void{
    $oldUrl=getenv('PL_PUBLIC_URL');putenv('PL_PUBLIC_URL=http://127.0.0.1:18200');
    try {
    $f=year_fixture();$id=year_start($f);$scope=['company_id'=>$f['company_id'],'book_id'=>$f['book_id']];
    $token=pl_create_personal_token($f['actor_id'],'Year-end read fixture',[$scope]);$connection=$token['connection']['id'];unset($token);
    assert_same($id,(int)pl_read_operation($connection,'fiscal_years',$scope)['data']['rows'][0]['id']);
    $report=pl_read_operation($connection,'year_end',$scope+['year_id'=>$id])['data'];assert_same('closing',$report['year']['status']);assert_true(!str_contains(json_encode($report,JSON_THROW_ON_ERROR),'request_key'));
    $other=year_fixture();assert_throws(fn()=>pl_read_operation($connection,'year_end',['company_id'=>$other['company_id'],'book_id'=>$other['book_id'],'year_id'=>$id]),DomainException::class);
    $limited=pl_create_personal_token($f['actor_id'],'Reports only',[$scope],'reports');assert_throws(fn()=>pl_read_operation($limited['connection']['id'],'year_end',$scope+['year_id'=>$id]),DomainException::class,'summary reports');unset($limited);
    $viewer=pl_create_user('year-viewer-'.bin2hex(random_bytes(8)).'@example.test','Sample viewer','Sample-password-only');DB::insert('pl_company_members',['company_id'=>$f['company_id'],'user_id'=>$viewer,'role'=>'viewer']);
    assert_throws(fn()=>pl_year_end_get($viewer,$f['company_id'],$f['book_id'],$id),DomainException::class,'cannot access');assert_throws(fn()=>pl_year_end_change($viewer,$f['company_id'],$f['book_id'],$id,'close',2,'Denied','viewer'),DomainException::class);
    } finally {putenv($oldUrl===false?'PL_PUBLIC_URL':'PL_PUBLIC_URL='.$oldUrl);}
});

test('year-end concurrent retries produce one closing journal and a current receipt under old snapshot',function():void{
    $f=year_fixture();pl_post_journal($f['actor_id'],$f['company_id'],$f['book_id'],ledger_payload($f,'100'));
    $id=year_start($f);$p=pl_year_end_preview($f['actor_id'],$f['company_id'],$f['book_id'],$id);$input=['fingerprint'=>$p['fingerprint'],'stock'=>true,'provisions'=>true,'adjustments'=>true,'payroll'=>true];
    $job=['mode'=>'year_close','fixture'=>$f,'year_id'=>$id,'revision'=>2,'key'=>'race','close_input'=>$input];$results=ledger_race([$job,$job]);assert_same($results[0]['id'],$results[1]['id']);assert_true($results[0]['id']>0);
    assert_same(1,(int)DB::queryFirstField("SELECT COUNT(*) FROM pl_journals WHERE book_id=%i AND source_type='year_end_close'",$f['book_id']));
    $f=year_fixture();pl_post_journal($f['actor_id'],$f['company_id'],$f['book_id'],ledger_payload($f,'100'));$id=year_start($f);$p=pl_year_end_preview($f['actor_id'],$f['company_id'],$f['book_id'],$id);$input['fingerprint']=$p['fingerprint'];
    DB::startTransaction();try{
        DB::queryFirstField('SELECT COUNT(*) FROM pl_year_end_actions WHERE book_id=%i',$f['book_id']);
        $results=ledger_race([['mode'=>'year_close','fixture'=>$f,'year_id'=>$id,'revision'=>2,'key'=>'snapshot','close_input'=>$input]]);
        $retry=pl_year_end_change($f['actor_id'],$f['company_id'],$f['book_id'],$id,'close',2,'Concurrent reviewed close','snapshot',$input);assert_same($results[0]['id'],$retry['journal_id']);
    }finally{DB::rollback();}
});

test('year-end fault rolls back closing intent, journal, year and period together',function():void{
    $f=year_fixture();pl_post_journal($f['actor_id'],$f['company_id'],$f['book_id'],ledger_payload($f,'100'));$id=year_start($f);
    DB::query("CREATE TRIGGER year_test_fault BEFORE INSERT ON pl_year_end_actions FOR EACH ROW BEGIN IF NEW.action='close' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='year storage fault'; END IF; END");
    try{assert_throws(fn()=>year_close($f,$id),Throwable::class,'year storage fault');}finally{DB::query('DROP TRIGGER year_test_fault');}
    assert_same('closing',pl_year_end_get($f['actor_id'],$f['company_id'],$f['book_id'],$id)['status']);assert_same('open',DB::queryFirstField('SELECT status FROM pl_periods WHERE id=%i',$f['period_id']));assert_same(0,(int)DB::queryFirstField("SELECT COUNT(*) FROM pl_year_end_posting_intents WHERE year_id=%i AND kind='close'",$id));assert_same('-100.0000',year_balance($f,$f['accounts']['4000']));year_close($f,$id);
});

test('year-end linked adjustment correction, year lock and next-year balances preserve history',function():void{
    $f=year_fixture();$id=year_start($f);$a=pl_year_end_adjust($f['actor_id'],$f['company_id'],$f['book_id'],$id,['description'=>'Wrong amount','lines'=>ledger_payload($f,'100')['lines']],'adjust');
    $r=pl_year_end_reverse_adjustment($f['actor_id'],$f['company_id'],$f['book_id'],$id,$a['journal_id'],'Correct the adjustment','correct');assert_same($a['journal_id'],(int)pl_get_journal($f['actor_id'],$f['company_id'],$f['book_id'],$r['journal_id'])['reversal_of_id']);assert_same('0.0000',pl_profit_loss($f['actor_id'],$f['company_id'],$f['book_id'],'2026-01-01','2026-12-31')['net_profit']);year_close($f,$id);
    pl_year_end_change($f['actor_id'],$f['company_id'],$f['book_id'],$id,'lock',3,'Approved year lock','lock');
    pl_create_period($f['actor_id'],$f['company_id'],$f['book_id'],['start_date'=>'2027-01-01','end_date'=>'2027-12-31','reason'=>'Next year','request_key'=>'next']);
    $second=pl_year_end_create($f['actor_id'],$f['company_id'],$f['book_id'],'2027-12-31',year_policy($f),'second');$id2=$second['year_id'];pl_year_end_change($f['actor_id'],$f['company_id'],$f['book_id'],$id2,'start',1,'Second year ready','start2');year_close($f,$id2,'close2');assert_throws(fn()=>pl_year_end_change($f['actor_id'],$f['company_id'],$f['book_id'],$id,'reopen',4,'Too early','reopen'),DomainException::class,'later closed');
    assert_same('0.0000',pl_year_end_report($f['actor_id'],$f['company_id'],$f['book_id'],$id2)['comparative']['profit_loss']['net_profit']);
});

test('year-end company closes profit once, preserves P&L, retries, reopens and recloses immutably',function():void{
    $f=year_fixture();pl_post_journal($f['actor_id'],$f['company_id'],$f['book_id'],ledger_payload($f,'1000'));
    $id=year_start($f);
    $adjust=['description'=>'Final expense provision','lines'=>[['account_id'=>$f['accounts']['5000'],'debit'=>'400','credit'=>'0'],['account_id'=>$f['accounts']['1000'],'debit'=>'0','credit'=>'400']]];
    $a=pl_year_end_adjust($f['actor_id'],$f['company_id'],$f['book_id'],$id,$adjust,'expense');
    assert_same($a,pl_year_end_adjust($f['actor_id'],$f['company_id'],$f['book_id'],$id,$adjust,'expense'));
    $before=pl_balance_sheet($f['actor_id'],$f['company_id'],$f['book_id'],'2026-12-31');$p=pl_year_end_preview($f['actor_id'],$f['company_id'],$f['book_id'],$id);assert_same('600.0000',$p['profit']);
    $close=year_close($f,$id);assert_same('-600.0000',year_balance($f,$f['retained']));assert_same('0.0000',year_balance($f,$f['accounts']['4000']));
    assert_same('600.0000',pl_profit_loss($f['actor_id'],$f['company_id'],$f['book_id'],'2026-01-01','2026-12-31')['net_profit']);
    assert_same($before['total_assets'],pl_balance_sheet($f['actor_id'],$f['company_id'],$f['book_id'],'2026-12-31')['total_assets']);
    assert_same($close,pl_year_end_change($f['actor_id'],$f['company_id'],$f['book_id'],$id,'close',2,'Valuation, provisions, adjustments and payroll reviewed; non-applicable items checked.','close',['fingerprint'=>$p['fingerprint'],'stock'=>true,'provisions'=>true,'adjustments'=>true,'payroll'=>true]));
    assert_throws(fn()=>pl_change_period_status($f['actor_id'],$f['company_id'],$f['book_id'],$f['period_id'],'open',2,'Bypass','bypass'),DomainException::class,'fiscal year');
    assert_throws(fn()=>pl_reverse_journal($f['actor_id'],$f['company_id'],$f['book_id'],$close['journal_id'],'2026-12-31','bypass-reversal','Bypass'),DomainException::class);
    pl_year_end_change($f['actor_id'],$f['company_id'],$f['book_id'],$id,'reopen',3,'Correction required','reopen');
    assert_same('600.0000',pl_profit_loss($f['actor_id'],$f['company_id'],$f['book_id'],'2026-01-01','2026-12-31')['net_profit']);
    $adjust['lines'][0]['debit']='100';$adjust['lines'][1]['credit']='100';pl_year_end_adjust($f['actor_id'],$f['company_id'],$f['book_id'],$id,$adjust,'extra');year_close($f,$id,'reclose');assert_same('-500.0000',year_balance($f,$f['retained']));
    assert_throws(fn()=>DB::update('pl_year_end_actions',['payload'=>'{}'],'year_id=%i',$id),Throwable::class,'immutable');
});

test('year-end reopening drains pending ordinary reversals before returning to closing',function():void{
    $f=year_fixture();
    $original=pl_post_journal($f['actor_id'],$f['company_id'],$f['book_id'],ledger_payload($f,'100'));
    $id=year_start($f);$closed=year_close($f,$id);
    assert_throws(fn()=>pl_schedule_journal_reversal($f['actor_id'],$f['company_id'],$f['book_id'],$closed['journal_id'],'2027-01-01','Cannot bypass year-end'),DomainException::class,'only standalone');
    $scheduled=pl_schedule_journal_reversal($f['actor_id'],$f['company_id'],$f['book_id'],$original['id'],'2026-12-31','Reviewed correction after close');
    assert_same(null,$scheduled['reversal']);
    $receipt=pl_year_end_change($f['actor_id'],$f['company_id'],$f['book_id'],$id,'reopen',3,'Apply pending correction','reopen-pending');
    assert_same('closing',$receipt['status']);
    assert_same(1,(int)DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE reversal_of_id=%i',$original['id']));
    assert_same($receipt,pl_year_end_change($f['actor_id'],$f['company_id'],$f['book_id'],$id,'reopen',3,'Apply pending correction','reopen-pending'));
    assert_same('0.0000',pl_profit_loss($f['actor_id'],$f['company_id'],$f['book_id'],'2026-01-01','2026-12-31')['net_profit']);
    year_close($f,$id,'corrected-close');assert_same('0.0000',year_balance($f,$f['retained']));
});

test('year-end allocation distributes exact residuals symmetrically and rejects missing policy',function():void{
    $parts=[['partner_id'=>2,'ratio'=>'0.400000'],['partner_id'=>1,'ratio'=>'0.600000']];
    assert_same(['0.0001','0.0000'],array_column(pl_year_end_allocate('0.0001',$parts),'amount'));assert_same(['-0.0001','0.0000'],array_column(pl_year_end_allocate('-0.0001',$parts),'amount'));
    $f=year_fixture();assert_throws(fn()=>pl_year_end_create($f['actor_id'],$f['company_id'],$f['book_id'],'2026-12-31',[],'bad'),DomainException::class,'Explicitly');
    DB::update('pl_accounts',['currency'=>'EUR'],'id=%i',$f['retained']);assert_throws(fn()=>pl_year_end_create($f['actor_id'],$f['company_id'],$f['book_id'],'2026-12-31',year_policy($f),'fx'),DomainException::class,'functional-currency');
});

test('year-end refuses ordinary posting, spoofed close, stale preview and incomplete attestations',function():void{
    $f=year_fixture();$id=year_start($f);$p=pl_year_end_preview($f['actor_id'],$f['company_id'],$f['book_id'],$id);
    assert_throws(fn()=>pl_post_journal($f['actor_id'],$f['company_id'],$f['book_id'],ledger_payload($f)),DomainException::class,'final-day');
    $spoof=ledger_payload($f);$spoof['date']='2026-12-31';$spoof['source_type']='year_end_close';assert_throws(fn()=>pl_post_journal($f['actor_id'],$f['company_id'],$f['book_id'],$spoof),DomainException::class,'reviewed');
    assert_throws(fn()=>pl_year_end_change($f['actor_id'],$f['company_id'],$f['book_id'],$id,'close',2,'Review','bad',['fingerprint'=>$p['fingerprint']]),DomainException::class,'Confirm');
    pl_year_end_adjust($f['actor_id'],$f['company_id'],$f['book_id'],$id,['description'=>'Adjustment','lines'=>ledger_payload($f)['lines']],'adjust');
    assert_throws(fn()=>pl_year_end_change($f['actor_id'],$f['company_id'],$f['book_id'],$id,'close',2,'Review','stale',['fingerprint'=>$p['fingerprint']]),DomainException::class,'preview changed');
});

test('year-end fixed 60/40 partnership closes drawings to current accounts without changing capital',function():void{
    $f=year_fixture();$policy=['treatment'=>'partnership','legal_basis'=>'Two-partner fixed capital agreement, full-year 60/40 ratio','review_note'=>'Reviewed fixed-capital worked example.','capital_method'=>'fixed','partners'=>[]];$drawings=[];$currents=[];$capitals=[];
    foreach(['A'=>'0.600000','B'=>'0.400000'] as $name=>$ratio){$ids=[];foreach(['capital'=>false,'drawings'=>true,'current'=>false] as $role=>$contra){$n=19010+count($capitals)*10+count($ids);$ids[$role]=(int)pl_save_account($f['actor_id'],$f['company_id'],$f['book_id'],['code'=>'3-100-'.$n.'-00','name'=>$name.' '.$role,'type'=>'equity','is_contra'=>$contra,'is_active'=>true,'role'=>null,'reason'=>'Partner example','creation_key'=>bin2hex(random_bytes(16))])['id'];}$partner=pl_save_owner_partner($f['actor_id'],$f['company_id'],$f['book_id'],['name'=>$name,'profit_share'=>$ratio,'is_active'=>true,'capital_account_id'=>$ids['capital'],'drawings_account_id'=>$ids['drawings'],'loan_account_id'=>null]);$policy['partners'][]=['partner_id'=>$partner['id'],'ratio'=>$ratio,'current_account_id'=>$ids['current']];$drawings[]=$ids['drawings'];$currents[]=$ids['current'];$capitals[]=$ids['capital'];}
    pl_post_journal($f['actor_id'],$f['company_id'],$f['book_id'],ledger_payload($f,'1000'));
    foreach($drawings as $i=>$account){$p=ledger_payload($f,$i===0?'100':'50');$p['lines'][0]['account_id']=$account;$p['lines'][1]['account_id']=$f['accounts']['1000'];pl_post_journal($f['actor_id'],$f['company_id'],$f['book_id'],$p);}
    $id=year_start($f,$policy);year_close($f,$id);assert_same('-500.0000',year_balance($f,$currents[0]));assert_same('-350.0000',year_balance($f,$currents[1]));foreach(array_merge($drawings,$capitals) as $account){assert_same('0.0000',year_balance($f,$account));}
    pl_year_end_change($f['actor_id'],$f['company_id'],$f['book_id'],$id,'reopen',3,'Agreement correction','reopen');$policy['capital_method']='fluctuating';pl_year_end_change($f['actor_id'],$f['company_id'],$f['book_id'],$id,'policy',4,'Reviewed fluctuating capital agreement','policy',$policy);year_close($f,$id,'reclose');assert_same('-500.0000',year_balance($f,$capitals[0]));assert_same('-350.0000',year_balance($f,$capitals[1]));foreach($currents as $account){assert_same('0.0000',year_balance($f,$account));}
});

test('year-end HTTP policy, reviewed close and reopen forms enforce CSRF and scope',function():void {
    $suffix=bin2hex(random_bytes(6));$email='period-http-'.$suffix.'@example.test';$password='Sample-http-password-'.$suffix;
    $actor=pl_create_user($email,'Sample period owner',$password);
    $f=['actor_id'=>$actor]+pl_create_company($actor,'Sample period HTTP '.$suffix,'USD','2026-01-01');
    $journal=pl_post_journal($actor,$f['company_id'],$f['book_id'],ledger_payload($f,'100.0000'));
    $port=random_int(20000,50000);$base='http://127.0.0.1:'.$port;
    $log=sys_get_temp_dir().'/period-http-'.$suffix.'.log';
    $server=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,'-t',dirname(__DIR__).'/www/phpledger/public'],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,dirname(__DIR__),array_replace(getenv(),['PL_SESSION_SECURE'=>'0']));
    assert_true(is_resource($server));fclose($pipes[0]);$cookie='';
    $request=static function(string $path,?array $data=null)use($base,&$cookie):array {
        $headers=['Connection: close'];if($cookie!==''){$headers[]='Cookie: '.$cookie;}if($data!==null){$headers[]='Content-Type: application/x-www-form-urlencoded';}
        $context=stream_context_create(['http'=>['method'=>$data===null?'GET':'POST','header'=>implode("\r\n",$headers),'content'=>$data===null?'':http_build_query($data),'ignore_errors'=>true,'follow_location'=>0,'timeout'=>10]]);
        $body=@file_get_contents($base.$path,false,$context);$responseHeaders=$http_response_header??[];
        foreach($responseHeaders as $header){if(preg_match('/^Set-Cookie: ([^;]+)/i',$header,$m)){$cookie=$m[1];}}
        preg_match('/\s(\d{3})\s/',$responseHeaders[0]??'',$m);return [(int)($m[1]??0),$body?:''];
    };
    $csrf=static function(string $body):string{preg_match('/name="csrf" value="([a-f0-9]+)"/',$body,$m);return $m[1]??'';};
    try {
        for($i=0;$i<100;$i++){[$status,$body]=$request('/login');if($status){break;}usleep(20000);}
        assert_same(200,$status);[$status]=$request('/login',['email'=>$email,'password'=>$password,'csrf'=>$csrf($body)]);assert_true(in_array($status,[302,303],true));
        [, $body]=$request('/companies');$request('/company/select',['company_id'=>$f['company_id'],'csrf'=>$csrf($body)]);

        [$status,$body]=$request('/year-end');assert_same(200,$status,substr((string)file_get_contents($log),-900));assert_true(str_contains($body,'Reviewed accounting policy'));
        $retained=(int)pl_save_account($actor,$f['company_id'],$f['book_id'],['code'=>'3-100-19001-00','name'=>'Retained earnings','type'=>'equity','is_active'=>true,'is_contra'=>false,'role'=>null,'reason'=>'HTTP example','creation_key'=>bin2hex(random_bytes(16))])['id'];
        $input=['action'=>'create','end_date'=>'2026-12-31','treatment'=>'company','legal_basis'=>'Private company retained earnings','note'=>'Reviewed HTTP policy','destination_account_id'=>$retained,'company_id'=>$f['company_id'],'book_id'=>$f['book_id'],'request_key'=>'http-create','csrf'=>$csrf($body)];
        [$status]=$request('/year-end',array_replace($input,['csrf'=>'invalid']));assert_same(403,$status);
        [$status]=$request('/year-end',array_replace($input,['book_id'=>999999]));assert_true(in_array($status,[302,303],true));assert_same(0,(int)DB::queryFirstField('SELECT COUNT(*) FROM pl_fiscal_years WHERE book_id=%i',$f['book_id']));
        $request('/year-end'); // Read the scope-error redirect before the next independent submission.
        [$status]=$request('/year-end',$input);assert_true(in_array($status,[302,303],true));$id=(int)DB::queryFirstField('SELECT id FROM pl_fiscal_years WHERE book_id=%i',$f['book_id']);assert_true($id>0);
        [$status,$body]=$request('/year-end?id='.$id);assert_same(200,$status,substr((string)file_get_contents($log),-900));assert_true(str_contains($body,'Start closing'));
        $action=['year_id'=>$id,'revision'=>1,'action'=>'start','note'=>'Start reviewed HTTP closing','request_key'=>'http-start','company_id'=>$f['company_id'],'book_id'=>$f['book_id'],'csrf'=>$csrf($body)];
        [$status]=$request('/year-end',$action);assert_true(in_array($status,[302,303],true));
        [$status,$body]=$request('/year-end?id='.$id);assert_same(200,$status);assert_true(str_contains($body,'Post final-day adjustment'));preg_match('/name="fingerprint" value="([a-f0-9]+)"/',$body,$m);assert_true(isset($m[1]));
        $close=array_replace($action,['revision'=>2,'action'=>'close','note'=>'All closing facts reviewed','request_key'=>'http-close','csrf'=>$csrf($body),'fingerprint'=>$m[1],'stock'=>'1','provisions'=>'1','adjustments'=>'1','payroll'=>'1']);
        [$status]=$request('/year-end',$close);assert_true(in_array($status,[302,303],true));
        [$status,$body]=$request('/year-end?id='.$id);assert_same(200,$status,substr((string)file_get_contents($log),-900));assert_true(str_contains($body,'Reopen for correction'));assert_true(str_contains($body,'Comparative profit and loss'),'P&L comparative missing '.substr((string)file_get_contents($log),-1200));assert_true(str_contains($body,'Comparative balance sheet'),'BS comparative missing '.substr((string)file_get_contents($log),-1200));assert_true(str_contains($body,'Liabilities and equity'),'BS totals missing '.substr((string)file_get_contents($log),-1200));assert_true(!str_contains($body,'Post final-day adjustment'));assert_same('closed',pl_year_end_get($actor,$f['company_id'],$f['book_id'],$id)['status']);
    } catch(Throwable $e) {throw new RuntimeException($e->getMessage().' at test line '.$e->getLine().' server: '.substr((string)file_get_contents($log),-1800).' body: '.substr($body??'',-600),0,$e);
    } finally {proc_terminate($server);proc_close($server);@unlink($log);}
});
