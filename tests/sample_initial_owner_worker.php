<?php
declare(strict_types=1);
if(getenv('PL_ENV')!=='test'||getenv('PL_DB_NAME')!=='phpledger_sample_bootstrap_test'){throw new RuntimeException('Dedicated empty test database required.');}
require dirname(__DIR__).'/www/phpledger/includes/bootstrap.php';
require dirname(__DIR__).'/www/phpledger/install/migrate.php';
pl_migrate();
if((int)DB::queryFirstField('SELECT COUNT(*) FROM pl_companies')!==0){throw new RuntimeException('Bootstrap proof requires no companies.');}
$root=sys_get_temp_dir().'/sample-owner-proof-'.bin2hex(random_bytes(8));putenv('PL_INSTALL_DIRECTORY='.$root);
require_once dirname(__DIR__).'/www/phpledger/includes/functions/install_web_functions.php';
$owner=pl_create_user('sample-owner-'.bin2hex(random_bytes(6)).'@example.invalid','Initial package owner','Sample-owner-password-123!');
$other=pl_create_user('sample-other-'.bin2hex(random_bytes(6)).'@example.invalid','Other package user','Sample-owner-password-123!');
try{
    $receipt=['format'=>1,'initial_owner_id'=>$owner,'database_id'=>'wrong-database'];pl_install_save_state($receipt,'installed.json');
    pl_plugin_bootstrap_initial_owner($owner);if(pl_user_can($owner,0,'installation.admin')){throw new RuntimeException('Wrong database receipt granted authority.');}
    $receipt['database_id']=pl_install_database_identity(['host'=>DB::$host,'port'=>DB::$port,'database'=>DB::$dbName]);pl_install_save_state($receipt,'installed.json');
    pl_plugin_bootstrap_initial_owner($other);if(pl_user_can($other,0,'installation.admin')){throw new RuntimeException('Other user claimed owner grant.');}
    pl_plugin_require_admin($owner);if(!pl_user_can($owner,0,'installation.admin')){throw new RuntimeException('Verified initial owner did not receive ordinary grant.');}
    if((int)DB::queryFirstField('SELECT COUNT(*) FROM pl_companies')!==0){throw new RuntimeException('Bootstrap created a company.');}
    echo "PASS initial owner before first company, wrong database receipt and different actor rejected\n";
}finally{if(is_file($root.'/installed.json')){unlink($root.'/installed.json');}if(is_dir($root)){rmdir($root);}}