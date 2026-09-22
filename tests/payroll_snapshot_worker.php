<?php
declare(strict_types=1);
if(getenv('PL_ENV')!=='test'||getenv('PL_DB_NAME')!=='phpledger_test'){exit(2);}
require dirname(__DIR__).'/www/phpledger/includes/bootstrap.php';
$f=json_decode($argv[1],true,32,JSON_THROW_ON_ERROR);
$input=['date'=>'2026-02-05','bank_account_id'=>$f['bank'],'creation_key'=>bin2hex(random_bytes(16)),'allocations'=>[['element_id'=>$f['element'],'amount'=>'5000']]];
$first=pl_prepare_payroll_payment($f['actor_id'],$f['company_id'],$f['book_id'],$f['payroll'],$input);
$input['creation_key']=bin2hex(random_bytes(16));$second=pl_prepare_payroll_payment($f['actor_id'],$f['company_id'],$f['book_id'],$f['payroll'],$input);
pl_post_general_draft($f['actor_id'],$f['company_id'],$f['book_id'],$first['id'],1);
echo json_encode($second,JSON_THROW_ON_ERROR);
