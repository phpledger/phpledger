<?php
declare(strict_types=1);
// Exact release adapter for the existing update HTTP phase driver and M17 fixture verifier.
if(PHP_SAPI!=='cli'||getenv('PL_ENV')!=='test'||getenv('PL_DB_HOST')!=='db_test'||getenv('PL_DB_USER')!=='root'){throw new RuntimeException('Dedicated isolated test service required.');}
[$script,$baselineZip,$candidateZip,$mode]=array_pad($argv,4,'');
if(!in_array($mode,['fresh','cli','http','recovery'],true)||!is_file($baselineZip)||!is_file($candidateZip)){throw new RuntimeException('Usage: baseline.zip candidate.zip fresh|cli|http|recovery');}
$fixture=sys_get_temp_dir().'/artifact-upgrade-'.bin2hex(random_bytes(8));mkdir($fixture,0700);
$receiptPath=$fixture.'/fixture.json';$private=$fixture.'/private';mkdir($private,0700);
$repository=dirname(__DIR__);$server=null;$database=null;
$check=static function(bool $ok,string $message):void{if(!$ok){throw new RuntimeException($message);}echo 'PASS '.$message."\n";};
$extract=static function(string $zipPath,string $destination):string{
    mkdir($destination,0700);$zip=new ZipArchive();if($zip->open($zipPath)!==true){throw new RuntimeException('Archive unreadable');}
    try{foreach(range(0,$zip->numFiles-1) as $i){$name=$zip->getNameIndex($i);if(!is_string($name)||str_contains($name,'..')||str_starts_with($name,'/')||str_contains($name,'\\')){throw new RuntimeException('Unsafe test archive path');}}if(!$zip->extractTo($destination)){throw new RuntimeException('Archive extraction failed');}}finally{$zip->close();}
    $roots=glob($destination.'/*/PACKAGE-MANIFEST.json');if(count($roots)!==1){throw new RuntimeException('Expected one packaged application');}return dirname($roots[0]);
};
$run=static function(array $command,array $environment=[]):void{
    $p=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,array_replace(getenv(),$environment));if(!is_resource($p)){throw new RuntimeException('Proof subprocess failed');}fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($p);echo $out;if($status!==0){throw new RuntimeException('Proof subprocess failed: '.$err);}
};
try{
    require $repository.'/vendor/autoload.php';
    $baseline=$extract($baselineZip,$fixture.'/baseline');$candidate=$extract($candidateZip,$fixture.'/candidate');
    $check(trim(file_get_contents($baseline.'/www/phpledger/VERSION'))==='1.2.1','baseline artifact identifies published 1.2.1');
    $check(trim(file_get_contents($candidate.'/www/phpledger/VERSION'))==='1.3.0','candidate artifact identifies 1.3.0');
    echo 'ARTIFACT SHA256 '.hash_file('sha256',$candidateZip)."\n";
    $env=['PL_UPGRADE_RECEIPT'=>$receiptPath,'PL_UPGRADE_KEEP'=>'1'];
    if($mode==='fresh'){
        $database='phpledger_m17_verify_'.bin2hex(random_bytes(12));
        DB::$host='db_test';DB::$user='root';DB::$password='local-test-root-only';DB::$dbName='information_schema';DB::query('CREATE DATABASE %b',$database);
        $code='require '.var_export($candidate.'/www/phpledger/includes/bootstrap.php',true).'; require '.var_export($candidate.'/www/phpledger/install/migrate.php',true).'; $m=pl_migrate(); if(!in_array("056_membership_role_id",$m["applied"],true)){throw new RuntimeException("Final migration absent");} $u=pl_create_user("fresh@example.invalid","Sample fresh owner","Sample-fresh-password-123!"); $f=pl_create_company($u,"Sample exact archive","USD","2026-01-01"); $j=pl_post_journal($u,$f["company_id"],$f["book_id"],["date"=>"2026-09-23","currency"=>"USD","source_type"=>"sample","source_reference"=>"exact-fresh","description"=>"Exact archive proof","idempotency_key"=>"exact-fresh","lines"=>[["account_id"=>$f["accounts"]["1000"],"debit"=>"1.2500","credit"=>"0"],["account_id"=>$f["accounts"]["3000"],"debit"=>"0","credit"=>"1.2500"]]]);$t=pl_trial_balance($u,$f["company_id"],$f["book_id"]);if(!$t["balanced"]||$t["total_debit"]!=="1.2500"||pl_migrate()["applied"]!==[]){throw new RuntimeException("Fresh accounting/replay failure");}echo "PASS exact artifact fresh migrations, owner, company, balanced posting and replay\\n";';
        $run([PHP_BINARY,'-r',$code],['PL_DB_NAME'=>$database]);
    }else{
        $run([PHP_BINARY,$repository.'/tools/verify-m17-upgrade.php','seed',$baseline],$env);
        $receipt=json_decode(file_get_contents($receiptPath),true,64,JSON_THROW_ON_ERROR);$database=$receipt['database'];
        if($mode==='cli'){$run([PHP_BINARY,$repository.'/tools/verify-m17-upgrade.php','check',$candidate],$env);}
        else{
            require $repository.'/tools/sign-update.php';
            $metadataPath=getenv('PL_ARTIFACT_METADATA')?:'';$keyPath=getenv('PL_ARTIFACT_PUBLIC_KEY')?:'';
            if($metadataPath!==''&&$keyPath!==''){$envelope=file_get_contents($metadataPath);$publicKey=file_get_contents($keyPath);echo "SIGNATURE official supplied metadata\n";}
            else{$key=openssl_pkey_new(['private_key_bits'=>3072,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);$publicKey=openssl_pkey_get_details($key)['key'];$payload=json_encode(pl_release_update_payload($candidateZip),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);openssl_sign($payload,$signature,$key,OPENSSL_ALGO_SHA256);$envelope=json_encode(['payload'=>base64_encode($payload),'signature'=>base64_encode($signature)],JSON_THROW_ON_ERROR);echo "SIGNATURE disposable test publisher, exact candidate bytes\n";}
            file_put_contents($private.'/publisher.pem',$publicKey);$operator=bin2hex(random_bytes(32));file_put_contents($private.'/operator.key',$operator);
            file_put_contents($baseline.'/www/phpledger/includes/config.local.php','<?php return '.var_export(['host'=>'db_test','port'=>3306,'database'=>$database,'user'=>'root','password'=>'local-test-root-only'],true).';');
            $port=random_int(20000,50000);$log=$fixture.'/http.log';$server=proc_open([PHP_BINARY,'-d','upload_max_filesize=100M','-d','post_max_size=110M','-S','127.0.0.1:'.$port,'-t',$baseline.'/www/phpledger/public'],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,$baseline,array_replace(getenv(),['PL_INSTALL_DIRECTORY'=>$private,'PL_DB_NAME'=>$database,'PL_UPDATE_PUBLIC_KEY'=>$private.'/publisher.pem','PL_SESSION_SECURE'=>'0']));fclose($pipes[0]);$cookie='';
            $request=static function(string $path,?string $body=null,string $type='application/x-www-form-urlencoded')use($port,&$cookie):array{$headers=['Connection: close'];if($cookie!==''){$headers[]='Cookie: '.$cookie;}if($body!==null){$headers[]='Content-Type: '.$type;}$response=@file_get_contents('http://127.0.0.1:'.$port.$path,false,stream_context_create(['http'=>['method'=>$body===null?'GET':'POST','header'=>implode("\r\n",$headers),'content'=>$body??'','ignore_errors'=>true,'timeout'=>60]]));foreach($http_response_header??[] as $h){if(preg_match('/^Set-Cookie: ([^;]+)/i',$h,$m)){$cookie=$m[1];}}preg_match('/\s(\d{3})\s/',$http_response_header[0]??'',$m);return [(int)($m[1]??0),(string)$response];};
            for($i=0;$i<100;$i++){[$status,$body]=$request('/maintenance.php');if($status){break;}usleep(20000);}$check($status===200&&str_contains($body,'Host installation operator key'),'published baseline maintenance HTTP reachable');preg_match('/name="csrf" value="([a-f0-9]+)"/',$body,$match);$csrf=$match[1]??'';
            [$status]=$request('/maintenance.php',http_build_query(['action'=>'authenticate','operator_key'=>$operator,'csrf'=>$csrf]));$check($status===200,'operator authenticates through HTTP');
            $boundary='artifact-'.bin2hex(random_bytes(8));$body='';foreach(['csrf'=>$csrf,'action'=>'begin','operator_key'=>$operator,'channel'=>'stable','source'=>'upload'] as $name=>$value){$body.="--$boundary\r\nContent-Disposition: form-data; name=\"$name\"\r\n\r\n$value\r\n";}foreach(['archive'=>['release.zip',file_get_contents($candidateZip)],'metadata'=>['release.json',$envelope]] as $name=>[$filename,$bytes]){$body.="--$boundary\r\nContent-Disposition: form-data; name=\"$name\"; filename=\"$filename\"\r\nContent-Type: application/octet-stream\r\n\r\n$bytes\r\n";}$body.="--$boundary--\r\n";[$status,$html]=$request('/maintenance.php',$body,'multipart/form-data; boundary='.$boundary);
            $check(is_file($private.'/updates/active.json'),'HTTP signed upload entered persistent update');$phases=[];$injected=false;
            for($step=0;$step<1500;$step++){
                clearstatcache(true,$private.'/updates/active.json');$activePath=$private.'/updates/active.json';$active=is_file($activePath)?json_decode(file_get_contents($activePath),true,64,JSON_THROW_ON_ERROR):null;$statePath=is_array($active)?$private.'/updates/'.$active['id'].'/state.json':$private.'/updates/last.json';$state=json_decode(file_get_contents($statePath),true,64,JSON_THROW_ON_ERROR);$phase=$state['phase'];if(end($phases)!==$phase){$phases[]=$phase;echo 'PHASE '.$phase."\n";}
                if($mode==='recovery'&&$phase==='verify'&&!$injected){file_put_contents($baseline.'/www/phpledger/includes/bootstrap.php','<?php throw new RuntimeException("Sample intentional post-migration runtime failure");');$injected=true;}
                if(in_array($phase,['complete','restored','aborted'],true)&&!is_file($private.'/updates/active.json')){break;}
                [$status,$html]=$request('/maintenance.php',http_build_query(['action'=>'continue','csrf'=>$csrf]));
            }
            $expected=$mode==='recovery'?'restored':'complete';$check(($state['phase']??'')===$expected,'real HTTP update terminates '.$expected);if($mode==='recovery'){$check($injected,'recovery fault occurred after actual migrations');}
            proc_terminate($server);proc_close($server);$server=null;
            $run([PHP_BINARY,$repository.'/tools/verify-m17-upgrade.php',$mode==='recovery'?'verify':'verify-candidate',$baseline],array_replace($env,['PL_INSTALL_CONFIG_PATH'=>$fixture.'/verifier-no-local-config.php']));
            $manifest=json_decode(file_get_contents($baseline.'/PACKAGE-MANIFEST.json'),true,64,JSON_THROW_ON_ERROR);$check($manifest['version']===($mode==='recovery'?'1.2.1':'1.3.0'),'terminal package manifest has expected release');
            foreach($manifest['files'] as $entry){if(!hash_equals($entry['sha256'],hash_file('sha256',$baseline.'/'.$entry['path']))){throw new RuntimeException('Terminal managed file mismatch: '.$entry['path']);}}$check(true,'every terminal managed file matches its release inventory');
            if($mode==='recovery'){$candidateManifest=json_decode(file_get_contents($candidate.'/PACKAGE-MANIFEST.json'),true,64,JSON_THROW_ON_ERROR);$originalPaths=array_column($manifest['files'],'path');foreach($candidateManifest['files'] as $entry){if(!in_array($entry['path'],$originalPaths,true)&&file_exists($baseline.'/'.$entry['path'])){throw new RuntimeException('Restoration retained candidate-only managed file: '.$entry['path']);}}$check(true,'recovery removes candidate-only managed files');}
        }
    }
}finally{
    if(is_resource($server)){proc_terminate($server);proc_close($server);}
    if(is_string($database)&&preg_match('/^phpledger_m17_verify_[a-f0-9]{24}$/D',$database)){DB::$host='db_test';DB::$user='root';DB::$password='local-test-root-only';DB::$dbName='information_schema';DB::disconnect();DB::query('DROP DATABASE %b',$database);}
    if(getenv('PL_ARTIFACT_KEEP_FILES')==='1'){echo 'Evidence fixture retained '.$fixture."\n";}else{foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $entry){if($entry->isDir()&&!$entry->isLink()){rmdir($entry->getPathname());}else{unlink($entry->getPathname());}}rmdir($fixture);}
}
