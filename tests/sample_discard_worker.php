<?php
declare(strict_types=1);
if (getenv('PL_ENV')!=='test' || getenv('PL_DB_NAME')!=='phpledger_sample_bootstrap_test' || !in_array($argv[1]??'', ['public','ordinary'],true)) { throw new RuntimeException('Dedicated sample test database and explicit mode required.'); }
require dirname(__DIR__).'/www/phpledger/includes/bootstrap.php';
require_once dirname(__DIR__).'/www/phpledger/includes/functions/web_functions.php';
require_once dirname(__DIR__).'/www/phpledger/includes/functions/package_web_functions.php';
$public=$argv[1]==='public';
$actor=pl_create_user('discard-'.bin2hex(random_bytes(8)).'@example.invalid','Sample package operator','Sample-package-password-123!');
pl_set_installation_grant($actor,$actor,'installation.admin',true,'Explicit test operator',true);
$root=sys_get_temp_dir().'/sample-discard-'.bin2hex(random_bytes(8));mkdir($root,0700);mkdir($root.'/sample-staged',0700);
$file=$root.'/sample-staged/plugin.json';file_put_contents($file,'{"staged":"host-owned-test-marker"}');
putenv('PL_PLUGIN_DIRECTORY='.$root);if($public){putenv('PL_ENV=demo-install');}
$_SESSION=[];$_POST=['action'=>'discard','slug'=>'sample-staged','company_id'=>'0','book_id'=>'0'];
register_shutdown_function(static function()use($file,$root,$public):void{
    $present=is_file($file);$failure=$_SESSION['form_failure']['message']??'';
    $passed=$public ? ($present&&str_contains($failure,'shared demo host')) : (!$present&&$failure==='');
    if($present){unlink($file);}if(is_dir($root.'/sample-staged')){rmdir($root.'/sample-staged');}rmdir($root);
    echo ($passed?'PASS':'FAIL').' '.($public?'public discard preserves host-staged executable files':'ordinary administrator can discard staged files')."\n";
    if(!$passed){exit(1);}
});
pl_web_packages_post($actor,['id'=>0,'book_id'=>0]);
