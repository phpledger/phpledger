<?php
declare(strict_types=1);
if(getenv('PL_ENV')!=='test'||getenv('PL_DB_NAME')!=='phpledger_sample_bootstrap_test'){throw new RuntimeException('Dedicated empty test database required.');}
require dirname(__DIR__).'/www/phpledger/includes/bootstrap.php';
require dirname(__DIR__).'/www/phpledger/install/migrate.php';
pl_migrate();
function assert_true(bool $value): void { if (!$value) { throw new RuntimeException('HTTP package bootstrap assertion failed.'); } }
function assert_same(mixed $expected,mixed $actual,string $message=''): void { if ($expected!==$actual) { throw new RuntimeException($message ?: 'HTTP package bootstrap status differs.'); } }
if((int)DB::queryFirstField('SELECT COUNT(*) FROM pl_companies')!==0){throw new RuntimeException('Bootstrap proof requires no companies.');}
$root=sys_get_temp_dir().'/sample-owner-proof-'.bin2hex(random_bytes(8));putenv('PL_INSTALL_DIRECTORY='.$root);
require_once dirname(__DIR__).'/www/phpledger/includes/functions/install_web_functions.php';
$email='sample-owner-'.bin2hex(random_bytes(6)).'@example.invalid';
$owner=pl_create_user($email,'Initial package owner','Sample-owner-password-123!');
$other=pl_create_user('sample-other-'.bin2hex(random_bytes(6)).'@example.invalid','Other package user','Sample-owner-password-123!');
try{
    $receipt=['format'=>1,'db_prefix'=>pl_database_prefix(),'initial_owner_id'=>$owner,'database_id'=>'wrong-database'];pl_install_save_state($receipt,'installed.json');
    pl_plugin_bootstrap_initial_owner($owner);if(pl_user_can($owner,0,'installation.admin')){throw new RuntimeException('Wrong database receipt granted authority.');}
    $receipt['database_id']=pl_install_database_identity(['host'=>DB::$host,'port'=>DB::$port,'database'=>DB::$dbName,'db_prefix'=>'other_']);pl_install_save_state($receipt,'installed.json');
    pl_plugin_bootstrap_initial_owner($owner);if(pl_user_can($owner,0,'installation.admin')){throw new RuntimeException('Wrong namespace receipt granted authority.');}
    $receipt['database_id']=pl_install_database_identity(['host'=>DB::$host,'port'=>DB::$port,'database'=>DB::$dbName,'db_prefix'=>pl_database_prefix()]);pl_install_save_state($receipt,'installed.json');
    pl_plugin_bootstrap_initial_owner($other);if(pl_user_can($other,0,'installation.admin')){throw new RuntimeException('Other user claimed owner grant.');}
    $port=random_int(20000,50000);$log=tempnam(sys_get_temp_dir(),'sample-http-');
    $server=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,'-t',dirname(__DIR__).'/www/phpledger/public'],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,dirname(__DIR__),array_replace(getenv(),['PL_SESSION_SECURE'=>'0']));
    if(!is_resource($server)){throw new RuntimeException('Package HTTP server unavailable.');}fclose($pipes[0]);$cookie='';
    $request=static function(string $path,?array $data=null)use($port,&$cookie):array{
        $headers=['Connection: close'];if($cookie!==''){$headers[]='Cookie: '.$cookie;}if($data!==null){$headers[]='Content-Type: application/x-www-form-urlencoded';}
        $body=@file_get_contents('http://127.0.0.1:'.$port.$path,false,stream_context_create(['http'=>['method'=>$data===null?'GET':'POST','header'=>implode("\r\n",$headers),'content'=>$data===null?'':http_build_query($data),'ignore_errors'=>true,'follow_location'=>0,'timeout'=>15]]));
        foreach($http_response_header??[] as $header){if(preg_match('/^Set-Cookie: ([^;]+)/i',$header,$m)){$cookie=$m[1];}}preg_match('/\s(\d{3})\s/',$http_response_header[0]??'',$m);return [(int)($m[1]??0),(string)$body];
    };
    try{
        for($i=0;$i<100;$i++){[$status,$html]=$request('/login');if($status!==0){break;}usleep(20000);}
        preg_match('/name="csrf" value="([a-f0-9]+)"/',$html,$m);[$status]=$request('/login',['email'=>$email,'password'=>'Sample-owner-password-123!','csrf'=>$m[1]??'']);assert_true(in_array($status,[302,303],true));
        [$status,$html]=$request('/packages');assert_same(200,$status,substr((string)file_get_contents($log),-500));assert_true(str_contains($html,'Sample companies'));assert_true(str_contains($html,'Refresh directory'));
    } finally { proc_terminate($server);proc_close($server);unlink($log); }
    pl_capability_cache_reset();
    if(!pl_user_can($owner,0,'installation.admin')){throw new RuntimeException('Verified initial owner did not receive ordinary grant.');}
    if((int)DB::queryFirstField('SELECT COUNT(*) FROM pl_companies')!==0){throw new RuntimeException('Bootstrap created a company.');}
    echo "PASS HTTP initial owner before first company with prefix " . pl_database_prefix() . "; wrong database/namespace receipts and different actor rejected\n";
}finally{if(is_dir($root)){foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $entry){if($entry->isDir()&&!$entry->isLink()){rmdir($entry->getPathname());}else{unlink($entry->getPathname());}}rmdir($root);}}