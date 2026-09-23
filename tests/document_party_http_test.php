<?php
declare(strict_types=1);

test('receipt and expense party workflow preserves entered values without JavaScript and creates once',function():void {
    $suffix=bin2hex(random_bytes(6)); $email='party-http-'.$suffix.'@example.test'; $password='Sample-party-http-'.$suffix;
    $actor=pl_create_user($email,'Sample party HTTP owner',$password);
    $f=['actor_id'=>$actor]+pl_create_company($actor,'Sample party HTTP '.$suffix,'USD','2026-01-01');
    // A preserved older money account has no inferred physical/bank classification.
    DB::update('pl_accounts',['money_kind'=>null],'id=%i',$f['accounts']['1000']);
    $other=ledger_fixture(); document_party_fixture($other,'Foreign private party '.$suffix);
    document_party_fixture($f,'Existing payer '.$suffix);
    $port=random_int(20000,50000); $base='http://127.0.0.1:'.$port; $log=sys_get_temp_dir().'/pl-party-http-'.$suffix.'.log';
    $server=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,'-t',dirname(__DIR__).'/www/phpledger/public'],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,dirname(__DIR__),array_replace(getenv(),['PL_SESSION_SECURE'=>'0','PL_LOCALE'=>'en']));
    if (!is_resource($server)) { throw new RuntimeException('Sample HTTP server unavailable.'); }
    fclose($pipes[0]); $cookie='';
    $request=static function(string $path,?array $post=null) use($base,&$cookie):array {
        $headers=['Connection: close']; if($cookie!==''){$headers[]='Cookie: '.$cookie;}
        if($post!==null){$headers[]='Content-Type: application/x-www-form-urlencoded';}
        $context=stream_context_create(['http'=>['method'=>$post===null?'GET':'POST','header'=>implode("\r\n",$headers),'content'=>$post===null?'':http_build_query($post),'ignore_errors'=>true,'follow_location'=>0,'timeout'=>15]]);
        $html=@file_get_contents($base.$path,false,$context); $response=$http_response_header??[]; $location='';
        foreach($response as $header){if(preg_match('/^Set-Cookie: ([^;]+)/i',$header,$m)){$cookie=$m[1];}if(preg_match('/^Location: (.+)$/i',$header,$m)){$location=$m[1];}}
        preg_match('/\s(\d{3})\s/',$response[0]??'',$m);
        return [(int)($m[1]??0),$html===false?'':$html,$location];
    };
    $field=static function(string $html,string $name):string {preg_match('/name="'.preg_quote($name,'/').'" value="([^"]*)"/',$html,$m);return html_entity_decode($m[1]??'',ENT_QUOTES,'UTF-8');};
    try {
        for($retry=0;$retry<50;$retry++){[$status,$html]=$request('/login');if($status){break;}usleep(20000);}
        assert_same(200,$status);
        [$status]=$request('/login',['email'=>$email,'password'=>$password,'csrf'=>$field($html,'csrf')]); assert_same(303,$status);
        [, $html]=$request('/companies'); $request('/company/select',['company_id'=>$f['company_id'],'csrf'=>$field($html,'csrf')]);
        [$status,,$location]=$request('/transactions/new?kind=expense'); assert_same(303,$status);
        assert_same('/expenses/new',parse_url($location,PHP_URL_PATH));
        [$status,$html]=$request($location); assert_same(200,$status);
        assert_true(!str_contains($html,'Foreign private party '.$suffix));
        assert_true(str_contains($html,'Posted book balance') && str_contains($html,'Classify this account as physical cash or bank'),'The initially selected legacy account did not show its balance and classification advisory.');
        $post=['csrf'=>$field($html,'csrf'),'company_id'=>$f['company_id'],'book_id'=>$f['book_id'],'kind'=>'expense','date'=>'2026-09-14','amount'=>'18.25','money_account_id'=>$f['accounts']['1000'],'category_account_id'=>$f['accounts']['5000'],'reference'=>'KEPT-'.$suffix,'memo'=>'Keep this unfinished memo','creation_key'=>$field($html,'creation_key'),'return_filters'=>['kind'=>'expense','q'=>'kept','status'=>'draft']];
        [$status,,$location]=$request('/transactions/save',$post); assert_same(303,$status);
        [$status,$html]=$request($location); assert_same(422,$status); assert_true(str_contains($html,'Choose who the money'));
        $post['party_search']='New fictional payee '.$suffix; $post['editor_action']='open_party';
        [,, $location]=$request('/transactions/save',$post); [$status,$html]=$request($location); assert_same(200,$status);
        foreach(['18.25','KEPT-'.$suffix,'Keep this unfinished memo','New fictional payee '.$suffix] as $value){assert_true(str_contains($html,$value),'Lost input: '.$value);}
        assert_true(str_contains($html,'name="new_party_vendor" value="1" checked'));
        $post['party_creation_key']=$field($html,'party_creation_key'); $post['new_party_name']=$post['party_search']; $post['new_party_type']='business'; $post['new_party_vendor']='1'; $post['editor_action']='create_party';
        [,, $location]=$request('/transactions/save',$post); [$status,$html]=$request($location); assert_same(422,$status); assert_true(str_contains($html,'Keep this unfinished memo'));
        $post['new_party_country']='US';
        for($attempt=0;$attempt<2;$attempt++){[,, $location]=$request('/transactions/save',$post);[$status,$html]=$request($location);assert_same(200,$status);}
        assert_same(1,(int)DB::queryFirstField('SELECT COUNT(*) FROM pl_parties WHERE company_id=%i AND legal_name=%s',$f['company_id'],$post['new_party_name']));
        $partyId=(int)DB::queryFirstField('SELECT id FROM pl_parties WHERE company_id=%i AND legal_name=%s',$f['company_id'],$post['new_party_name']);
        assert_true(str_contains($html,'value="'.$partyId.'" selected'));
        unset($post['editor_action']); $post['party_id']=$partyId;
        [$status,,$location]=$request('/transactions/save',$post); assert_same(303,$status); assert_true(str_contains($location,'kind=expense')); assert_true(str_contains($location,'q=kept'));
        [$status,$html]=$request($location); assert_same(200,$status); assert_true(str_contains($html,'New expense')); assert_true(str_contains($html,'Posted book balance'));
        assert_true(str_contains($html,'/parties?select='.$partyId),'Linked identity did not open the existing party detail workspace.');
        $doc=DB::queryFirstRow('SELECT * FROM pl_documents WHERE company_id=%i',$f['company_id']); assert_same($partyId,(int)$doc['party_id']); assert_same('Keep this unfinished memo',$doc['memo']);
        $oldAccount=pl_get_account($actor,$f['company_id'],$f['book_id'],$f['accounts']['1000']);
        pl_save_account($actor,$f['company_id'],$f['book_id'],array_replace($oldAccount,['is_active'=>false,'reason'=>'Fictional saved account deactivation']),$oldAccount['id'],$oldAccount['revision']);
        [$status,$html]=$request('/transactions?kind=expense&id='.$doc['id']);
        assert_same(200,$status); assert_true(str_contains($html,'Cash balance preview unavailable') && str_contains($html,'Edit draft'),'Inactive saved account hid the draft or its edit control.');
        [$status,$html]=$request('/transactions/detail?kind=expense&id='.$doc['id']); assert_same(200,$status); assert_true(str_contains($html,'Cash balance preview unavailable'));
        [$status,,$location]=$request('/transactions/edit?id='.$doc['id']); assert_same(303,$status);
        assert_same('/expenses/edit',parse_url($location,PHP_URL_PATH));
        [$status,$html]=$request($location); assert_same(200,$status); assert_true(str_contains($html,'Unavailable saved account'));
        $replacement=pl_save_account($actor,$f['company_id'],$f['book_id'],['code'=>'HTTP-BANK','name'=>'Fictional replacement bank','type'=>'asset','role'=>'cash_bank','money_kind'=>'bank','is_active'=>true,'reason'=>'Recover the sample draft','creation_key'=>'replacement-'.$suffix]);
        $post['id']=$doc['id']; $post['revision']=1; $post['money_account_id']=$replacement['id'];
        [$status,,$location]=$request('/transactions/save',$post); assert_same(303,$status);
        [$status,$html]=$request($location); assert_same(200,$status);
        assert_true(str_contains($html,'No overdraft facility is recorded'),'The default zero-floor bank policy was not explained.');
        assert_same($replacement['id'],pl_get_document($actor,$f['company_id'],$f['book_id'],(int)$doc['id'])['money_account_id']);
        pl_save_account($actor,$f['company_id'],$f['book_id'],array_replace($replacement,['overdraft_enabled'=>true,'overdraft_limit'=>'25.0000','reason'=>'Fictional agreed bank facility']),$replacement['id'],$replacement['revision']);
        [$status,$html]=$request('/transactions/detail?kind=expense&id='.$doc['id']); assert_same(200,$status);
        assert_true(str_contains($html,'Agreed overdraft limit') && str_contains($html,'25.00') && str_contains($html,'not cleared or available funds'),'The agreed bank facility or clearance boundary was not shown.');
        [$status,$html]=$request('/transactions?kind=receipt'); assert_same(200,$status); assert_true(str_contains($html,'New receipt'));
        // A read-only member cannot create parties or reach the editor through these new paths.
        DB::update('pl_company_members',['role_id'=>DB::queryFirstField("SELECT id FROM pl_roles WHERE company_id IS NULL AND slug='viewer'")],'company_id=%i AND user_id=%i',$f['company_id'],$actor);
        [$status,$html]=$request('/transactions/new?kind=expense'); assert_true($status>=400); assert_true(!str_contains($html,'New fictional payee '.$suffix));
        $post['editor_action']='create_party'; $post['party_creation_key']=bin2hex(random_bytes(16)); $post['new_party_name']='Forbidden party '.$suffix;
        $request('/transactions/save',$post);
        assert_same(0,(int)DB::queryFirstField('SELECT COUNT(*) FROM pl_parties WHERE company_id=%i AND legal_name=%s',$f['company_id'],$post['new_party_name']));
    } finally { proc_terminate($server); proc_close($server); @unlink($log); }
});
