<?php
declare(strict_types=1);

function sample_package_fixture(): array
{
    $suffix=bin2hex(random_bytes(6));$actor=pl_create_user('sample-pkg-'.$suffix.'@example.invalid','Sample package owner','Sample-package-password-123!');
    $f=['actor_id'=>$actor]+pl_create_company($actor,'Sample package company '.$suffix,'USD','2026-01-01');
    pl_set_installation_grant($actor,$actor,'installation.admin',true,'Sample package administrator',true);pl_capability_cache_reset();
    return $f;
}
function sample_package_test_archive(string $root,array $changes=[],array $extra=[]): array
{
    $entry=array_values(array_filter(json_decode((string)file_get_contents(PL_ROOT.'/resources/demo-packs/catalog.json'),true,32,JSON_THROW_ON_ERROR),static fn(array $e):bool=>$e['id']==='retail-shop'))[0];
    $pack=str_replace("\r\n","\n",(string)file_get_contents(PL_ROOT.'/resources/demo-packs/'.$entry['file']));
    $structure=str_replace("\r\n","\n",(string)file_get_contents(PL_ROOT.'/resources/sample-structures/retail-shop-1.0.0.json'));
    $m=array_replace(['type'=>'sample','slug'=>'sample-retail-shop','version'=>'1.0.0','contract'=>1,'name'=>$entry['name'],'description'=>$entry['capability_note'],'author'=>'Sample test publisher','licence'=>'CC0-1.0','homepage'=>'https://phpledger.com/directory/sample-retail-shop/','requires'=>['core'=>'1.0.0'],'sample'=>$entry,'files'=>['pack.json'=>hash('sha256',$pack),'structure.json'=>hash('sha256',$structure)]],$changes);
    $bytes=['package.json'=>json_encode($m,JSON_THROW_ON_ERROR),'pack.json'=>$pack,'structure.json'=>$structure]+$extra;
    $path=$root.'/archive-'.bin2hex(random_bytes(5)).'.zip';$z=new ZipArchive();$z->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE);foreach($bytes as $name=>$raw){$z->addFromString($m['slug'].'/'.$name,$raw);}$z->close();
    return ['archive'=>$path,'manifest'=>$m,'bytes'=>$bytes];
}
function sample_package_test_root(): string
{
    $root=sys_get_temp_dir().'/sample-package-test-'.bin2hex(random_bytes(8));mkdir($root,0700);putenv('PL_SAMPLE_PACKAGE_DIRECTORY='.$root.'/samples');return $root;
}
function sample_package_test_cleanup(string $root): void
{
    putenv('PL_ENV=test');putenv('PL_SAMPLE_PACKAGES_READONLY');putenv('PL_UPDATE_PUBLIC_KEY');putenv('PL_SAMPLE_PACKAGE_DIRECTORY');
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $f){if($f->isDir()&&!$f->isLink()){rmdir($f->getPathname());}else{unlink($f->getPathname());}}rmdir($root);
}

test('sample packages are absent from ordinary fresh installs while the neutral starter stays offline',function():void{
    $root=sample_package_test_root();try{putenv('PL_ENV=production');assert_same([],pl_demo_pack_catalog());assert_same(['accounting-starter'],array_keys(pl_demo_sample_choices()));assert_true(pl_sample_structure_read('accounting-starter')!==null);assert_throws(fn()=>pl_demo_pack('retail-shop'),DomainException::class);assert_true(!pl_sample_companies_allowed());}finally{sample_package_test_cleanup($root);}
});

test('validated data-only uploads enter the existing registry and production chooser without executing code',function():void{
    $root=sample_package_test_root();$f=sample_package_fixture();try{
        $a=sample_package_test_archive($root);$staged=pl_plugin_stage_archive($f['actor_id'],$a['archive']);assert_same('sample',$staged['type']);
        pl_sample_package_install($f['actor_id'],$staged['slug'],'Sample upload',bin2hex(random_bytes(16)));$record=pl_plugin_record($staged['slug']);assert_same('sample',$record['type']);assert_same('unverified',$record['trust']);
        putenv('PL_ENV=production');assert_same(['accounting-starter','retail-shop'],array_keys(pl_demo_sample_choices()));assert_same('retail-shop',pl_demo_pack('retail-shop')['id']);assert_true(pl_sample_structure_read('retail-shop')!==null);assert_true(pl_sample_companies_allowed());
        $practice=pl_setup_company($f['actor_id'],['name'=>'Installed package practice','currency'=>'USD','start_date'=>'2024-01-01','fiscal_year_end'=>'12-31','start_mode'=>'sample','chart_choice'=>'neutral','source'=>'full','sample_pack'=>'retail-shop','template_digest'=>pl_starter_template()['digest']],'sample-package-practice:'.bin2hex(random_bytes(12)));
        assert_true((bool)$practice['is_sample']);assert_true((int)DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE company_id=%i',$practice['id'])>=85);assert_true(pl_trial_balance($f['actor_id'],(int)$practice['id'],(int)$practice['book_id'])['balanced']);
        $before=(int)DB::queryFirstField('SELECT COUNT(*) FROM pl_companies');pl_sample_package_remove($f['actor_id'],$staged['slug'],'Remove fixture package',bin2hex(random_bytes(16)));assert_same($before,(int)DB::queryFirstField('SELECT COUNT(*) FROM pl_companies'));assert_same([],pl_demo_pack_catalog());
    }finally{sample_package_test_cleanup($root);}
});

test('sample archives reject executable payloads traversal contract mismatch tampering and unmet dependencies before staging',function():void{
    $root=sample_package_test_root();$f=sample_package_fixture();try{
        foreach([
            [[],['plugin.php'=>'<?php throw new RuntimeException("must not run");']],
            [[],['../escape.json'=>'{}']],
            [['contract'=>999],[]],
            [['requires'=>['core'=>'1.0.0','missing-plugin'=>'1.0.0']],[]],
            [['files'=>['pack.json'=>str_repeat('0',64),'structure.json'=>str_repeat('0',64)]],[]],
        ] as [$changes,$extra]){$a=sample_package_test_archive($root,$changes,$extra);assert_throws(fn()=>pl_plugin_stage_archive($f['actor_id'],$a['archive']),Throwable::class);assert_true(!is_dir($root.'/samples/sample-retail-shop'));}
        $other=pl_create_user('not-admin-'.bin2hex(random_bytes(5)).'@example.invalid','Sample user','Sample-password-123!');$a=sample_package_test_archive($root);assert_throws(fn()=>pl_plugin_stage_archive($other,$a['archive']),DomainException::class,'administrator');
    }finally{sample_package_test_cleanup($root);}
});

test('publisher-signed host preloads remain offline read-only and reject changed inventories or payloads',function():void{
    $root=sample_package_test_root();$f=sample_package_fixture();try{
        $a=sample_package_test_archive($root);$stage=pl_plugin_stage_archive($f['actor_id'],$a['archive']);
        $key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>3072]);$details=openssl_pkey_get_details($key);file_put_contents($root.'/publisher.pem',$details['key']);putenv('PL_UPDATE_PUBLIC_KEY='.$root.'/publisher.pem');
        $m=['schema'=>1,'type'=>'sample','slug'=>$stage['slug'],'version'=>'1.0.0','archive_bytes'=>filesize($a['archive']),'archive_sha256'=>hash_file('sha256',$a['archive']),'manifest_sha256'=>hash('sha256',$a['bytes']['package.json']),'files'=>$a['manifest']['files']];
        $payload=json_encode($m,JSON_THROW_ON_ERROR);openssl_sign($payload,$signature,$key,OPENSSL_ALGO_SHA256);$envelope=json_encode(['payload'=>base64_encode($payload),'signature'=>base64_encode($signature)],JSON_THROW_ON_ERROR);
        file_put_contents($root.'/samples/'.$stage['slug'].'/sample-envelope.json',$envelope);putenv('PL_SAMPLE_PACKAGES_READONLY=1');putenv('PL_ENV=demo-install');
        assert_same('verified',pl_sample_package_read($stage['slug'])['trust']);assert_same(['retail-shop'],array_keys(pl_demo_pack_catalog()));
        assert_throws(fn()=>pl_sample_package_install($f['actor_id'],$stage['slug'],'Must refuse',bin2hex(random_bytes(16))),DomainException::class,'read-only');
        assert_throws(fn()=>pl_plugin_stage_archive($f['actor_id'],$a['archive']),DomainException::class,'shared demo host');
        assert_throws(fn()=>pl_plugin_activate($f['actor_id'],'untrusted','Bypass attempt',bin2hex(random_bytes(16))),DomainException::class,'shared demo host');
        assert_throws(fn()=>pl_sample_package_envelope(json_encode(['payload'=>base64_encode($payload),'signature'=>base64_encode('bad')],JSON_THROW_ON_ERROR),$details['key']),DomainException::class,'signature');
        file_put_contents($root.'/samples/'.$stage['slug'].'/pack.json','{}');assert_throws(fn()=>pl_sample_package_read($stage['slug']),DomainException::class,'digest');assert_same([],pl_demo_pack_catalog());
    }finally{sample_package_test_cleanup($root);}
});

test('sample directory is data with fixed destinations and rejects duplicate identities',function():void{
    $entry=['type'=>'sample','slug'=>'sample-retail-shop','version'=>'1.0.0','name'=>'Sample shop','description'=>'Fictional data','author'=>'Sample publisher','licence'=>'CC0-1.0','metadata_url'=>'http://127.0.0.1/private'];
    $feed=pl_sample_directory_parse(json_encode(['schema'=>1,'packages'=>[$entry]],JSON_THROW_ON_ERROR));assert_true(str_starts_with($feed['packages'][0]['metadata_url'],'https://phpledger.com/directory/'));
    assert_throws(fn()=>pl_sample_directory_parse(json_encode(['schema'=>1,'packages'=>[$entry,$entry]],JSON_THROW_ON_ERROR)),DomainException::class,'Duplicate');
    assert_throws(fn()=>pl_sample_directory_parse(json_encode(['schema'=>2,'packages'=>[]],JSON_THROW_ON_ERROR)),DomainException::class);
});

test('package administration renders before company selection and protects directory refresh with CSRF',function():void{
    $email='sample-http-'.bin2hex(random_bytes(6)).'@example.invalid';$password='Sample-http-password-123!';$actor=pl_create_user($email,'Package HTTP owner',$password);
    pl_set_installation_grant($actor,$actor,'installation.admin',true,'Package HTTP owner',true);pl_capability_cache_reset();
    $root=sample_package_test_root();$port=random_int(20000,50000);$log=tempnam(sys_get_temp_dir(),'sample-http-');
    $server=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,'-t',dirname(__DIR__).'/www/phpledger/public'],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,dirname(__DIR__),array_replace(getenv(),['PL_SESSION_SECURE'=>'0']));
    if(!is_resource($server)){throw new RuntimeException('Package HTTP server unavailable.');}fclose($pipes[0]);$cookie='';
    $request=static function(string $path,?array $data=null)use($port,&$cookie):array{
        $headers=['Connection: close'];if($cookie!==''){$headers[]='Cookie: '.$cookie;}if($data!==null){$headers[]='Content-Type: application/x-www-form-urlencoded';}
        $body=@file_get_contents('http://127.0.0.1:'.$port.$path,false,stream_context_create(['http'=>['method'=>$data===null?'GET':'POST','header'=>implode("\r\n",$headers),'content'=>$data===null?'':http_build_query($data),'ignore_errors'=>true,'follow_location'=>0,'timeout'=>15]]));
        foreach($http_response_header??[] as $header){if(preg_match('/^Set-Cookie: ([^;]+)/i',$header,$m)){$cookie=$m[1];}}preg_match('/\s(\d{3})\s/',$http_response_header[0]??'',$m);return [(int)($m[1]??0),(string)$body];
    };
    try{
        for($i=0;$i<100;$i++){[$status,$html]=$request('/login');if($status!==0){break;}usleep(20000);}
        preg_match('/name="csrf" value="([a-f0-9]+)"/',$html,$m);[$status]=$request('/login',['email'=>$email,'password'=>$password,'csrf'=>$m[1]??'']);assert_true(in_array($status,[302,303],true));
        [$status,$html]=$request('/packages');assert_same(200,$status,substr((string)file_get_contents($log),-500));assert_true(str_contains($html,'Sample companies'));assert_true(str_contains($html,'Refresh directory'));
        [$status]=$request('/packages',['action'=>'sample_refresh','csrf'=>'invalid','company_id'=>0,'book_id'=>0]);assert_same(403,$status);
        assert_same(0,(int)DB::queryFirstField('SELECT COUNT(*) FROM pl_company_members WHERE user_id=%i',$actor));
    }finally{proc_terminate($server);proc_close($server);unlink($log);sample_package_test_cleanup($root);}
});