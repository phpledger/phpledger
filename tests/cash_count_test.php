<?php
declare(strict_types=1);
function cash_count_input(array $f,string $amount,string $key):array {
    return ['account_id'=>$f['accounts']['1000'],'count_date'=>'2026-09-14','counted_at'=>'2026-09-14 18:00:00','counted_amount'=>$amount,'note'=>'Sample physical count','creation_key'=>$key];
}
test('cash counts post exact overages and shortages and preserve agreed evidence with retry payload identity',function():void {
    $f=ledger_fixture();pl_post_journal($f['actor_id'],$f['company_id'],$f['book_id'],ledger_payload($f,'100.0000'));
    $record=fn(array $input)=>pl_record_cash_count($f['actor_id'],$f['company_id'],$f['book_id'],$input);
    $over=$record(cash_count_input($f,'102.35','over'));
    assert_same('2.3500',$over['difference']);assert_true($over['journal_id']!==null);
    assert_same($over,$record(cash_count_input($f,'102.35','over')));
    assert_throws(fn()=>$record(cash_count_input($f,'103.35','over')),DomainException::class,'different cash count');
    $short=$record(cash_count_input($f,'100','short'));assert_same('-2.3500',$short['difference']);
    $agreed=$record(cash_count_input($f,'100','agreed'));assert_same(null,$agreed['journal_id']);assert_same('agreed',$agreed['outcome']);
    assert_same('100.0000',pl_cash_account_balance($f['company_id'],$f['book_id'],$f['accounts']['1000'],'2026-09-14'));
    $history=pl_cash_count_history($f['actor_id'],$f['company_id'],$f['book_id'],$f['accounts']['1000']);
    assert_same(3,$history['total_counts']);assert_same('0.0000',$history['net_difference']);assert_same('4.7000',$history['gross_difference']);
    assert_throws(fn()=>DB::update('pl_cash_counts',['note'=>'changed'],'id=%i',$agreed['id']),Throwable::class,'immutable');
});
test('cash counts validate whole cash, denomination evidence, scoped access and agreed closed-period attempts',function():void {
    $f=ledger_fixture();$other=ledger_fixture();$record=fn(array $input)=>pl_record_cash_count($f['actor_id'],$f['company_id'],$f['book_id'],$input);
    assert_throws(fn()=>$record(cash_count_input($f,'0.001','fraction')),DomainException::class,'whole notes');
    $input=cash_count_input($f,'10','denoms');$input['denominations']=[['denomination'=>'5','quantity'=>2]];
    assert_same(2,$record($input)['lines'][0]['quantity']);
    $input['creation_key']='mismatch';$input['counted_amount']='11';assert_throws(fn()=>$record($input),DomainException::class,'add up');
    $input=cash_count_input($f,'10','timestamp');$input['counted_at']='2026-09-14 99:99:00';assert_throws(fn()=>$record($input),DomainException::class,'timestamp');
    assert_throws(fn()=>pl_get_cash_count($other['actor_id'],$f['company_id'],$f['book_id'],1),DomainException::class);
    $input=cash_count_input($f,'0','foreign');$input['account_id']=$other['accounts']['1000'];assert_throws(fn()=>$record($input),DomainException::class,'this book');
    pl_change_period_status($f['actor_id'],$f['company_id'],$f['book_id'],$f['period_id'],'closed',1,'Review','close');
    assert_throws(fn()=>$record(cash_count_input($f,'10','closed-agreed')),DomainException::class,'open accounting period');
});

test('simultaneous cash-count retries post one difference and audit persistence failure rolls back the journal',function():void {
    $f=ledger_fixture();$job=['mode'=>'cash-count','fixture'=>$f,'input'=>cash_count_input($f,'25.00','race')];
    $results=period_race([$job,$job]);assert_same($results[0]['result'],$results[1]['result']);
    assert_same(1,(int)DB::queryFirstField('SELECT COUNT(*) FROM pl_cash_counts WHERE book_id=%i',$f['book_id']));
    assert_same(1,(int)DB::queryFirstField("SELECT COUNT(*) FROM pl_journals WHERE book_id=%i AND source_type='cash_count'",$f['book_id']));
    $trigger='pl_test_cash_count_'.bin2hex(random_bytes(5));
    DB::query("CREATE TRIGGER %b BEFORE INSERT ON pl_cash_counts FOR EACH ROW BEGIN IF NEW.note = 'Sample cash failure' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sample cash failure'; END IF; END",$trigger);
    $input=cash_count_input($f,'30','storage');$input['note']='Sample cash failure';
    try { assert_throws(fn()=>pl_record_cash_count($f['actor_id'],$f['company_id'],$f['book_id'],$input),Throwable::class,'Sample cash failure'); }
    finally { DB::query('DROP TRIGGER %b',$trigger); }
    assert_same('25.0000',pl_cash_account_balance($f['company_id'],$f['book_id'],$f['accounts']['1000'],'2026-09-14'));
});

test('period and cash count routes render and enforce CSRF, scope and posted evidence over HTTP',function():void {
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
        foreach(['/periods','/cash-counts','/journals/detail?id='.$journal['id']] as $route){[$status,$body]=$request($route);assert_same(200,$status,$route.' '.substr((string)file_get_contents($log),-700));}
        assert_true(str_contains($body,'Save reversal schedule'),'Journal scheduling form');
        [$status,$body]=$request('/cash-counts');$token=$csrf($body);
        $input=cash_count_input($f,'105','http')+['company_id'=>$f['company_id'],'book_id'=>$f['book_id'],'csrf'=>$token];
        [$status,$body]=$request('/cash-counts',array_replace($input,['csrf'=>'invalid']));assert_same(403,$status,'Global CSRF rejection');assert_true(str_contains($body,'Your session changed'),'CSRF reason');
        [$status]=$request('/cash-counts',array_replace($input,['book_id'=>999999]));assert_true(in_array($status,[302,303],true),'Wrong scope returns form');
        [$status]=$request('/cash-counts');assert_same(422,$status,'Scope rejection form');assert_same(0,(int)DB::queryFirstField('SELECT COUNT(*) FROM pl_cash_counts WHERE book_id=%i',$f['book_id']));
        [$status]=$request('/cash-counts',$input);assert_true(in_array($status,[302,303],true),'Count POST '.$status.' '.substr((string)file_get_contents($log),-700));
        $countId=(int)DB::queryFirstField('SELECT id FROM pl_cash_counts WHERE book_id=%i',$f['book_id']);assert_true($countId>0);
        [$status,$body]=$request('/cash-counts?id='.$countId);assert_same(200,$status);assert_true(str_contains($body,'View posted difference'));
        [$status]=$request('/periods/schedule-reversal',['company_id'=>$f['company_id'],'book_id'=>$f['book_id'],'csrf'=>$token,'journal_id'=>$journal['id'],'reverse_on'=>'2027-01-01','reason'=>'Sample HTTP accrual']);assert_true(in_array($status,[302,303],true));
        assert_same('2027-01-01',DB::queryFirstField('SELECT reverse_on FROM pl_journal_reversal_schedules WHERE journal_id=%i',$journal['id']));
    } finally {proc_terminate($server);proc_close($server);@unlink($log);}
});

test('cash count retries read current receipts from a caller-owned old snapshot',function():void {
    $f=ledger_fixture();$input=cash_count_input($f,'20','old-snapshot');
    DB::startTransaction();
    try {
        assert_same(0,(int)DB::queryFirstField('SELECT COUNT(*) FROM pl_cash_counts WHERE book_id=%i',$f['book_id']));
        $results=period_race([['mode'=>'cash-count','fixture'=>$f,'input'=>$input]]);
        assert_true(isset($results[0]['result']));
        assert_same(0,(int)DB::queryFirstField('SELECT COUNT(*) FROM pl_cash_counts WHERE book_id=%i',$f['book_id']));
        assert_same($results[0]['result'],pl_record_cash_count($f['actor_id'],$f['company_id'],$f['book_id'],$input));
    } finally { DB::rollback(); }
});
