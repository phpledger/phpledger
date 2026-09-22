<?php
declare(strict_types=1);
if(getenv('PL_ENV')!=='test' || getenv('PL_DB_NAME')!=='phpledger_test') { exit(2); }
require dirname(__DIR__).'/www/phpledger/includes/bootstrap.php';
$suffix=bin2hex(random_bytes(5));$email='schedules-'.$suffix.'@example.test';
$actor=pl_create_user($email,'Sample Schedule Owner','Sample-browser-only-2026!');$f=pl_create_company($actor,'Sample Scheduled Books '.$suffix,'USD','2026-01-01');$company=$f['company_id'];$book=$f['book_id'];$ids=[];
foreach(['prepaid'=>['asset','1-990-10001-00'],'loan'=>['liability','2-990-10001-00'],'accrued'=>['liability','2-990-10002-00'],'interest'=>['expense','5-990-10001-00']] as $name=>[$type,$code]) { $ids[$name]=(int)pl_save_account($actor,$company,$book,['code'=>$code,'name'=>'Sample '.$name,'type'=>$type,'is_active'=>true,'reason'=>'Sample browser fixture','creation_key'=>$name])['id']; }
$party=pl_save_party($actor,$company,$book,['legal_name'=>'Sample Lender','entity_type'=>'company','country_code'=>'US','currency'=>'USD','is_customer'=>false,'is_vendor'=>true,'request_key'=>'sample-lender','reason'=>'Sample browser fixture']);
$post=static fn(int $debit,int $credit,string $amount,string $key):array=>pl_post_journal($actor,$company,$book,['date'=>'2026-09-14','currency'=>'USD','source_type'=>'general','source_reference'=>$key,'idempotency_key'=>$key,'description'=>'Sample '.$key,'lines'=>[['account_id'=>$debit,'debit'=>$amount,'credit'=>'0','description'=>'Sample'],['account_id'=>$credit,'debit'=>'0','credit'=>$amount,'description'=>'Sample']]]);
$fund=$post($f['accounts']['1000'],$ids['loan'],'1000','loan-funding');
$loan=pl_save_loan($actor,$company,$book,['name'=>'Sample equipment finance','lender_party_id'=>$party['id'],'principal'=>'1000','annual_rate'=>'12','method'=>'reducing','term_months'=>2,'start_date'=>'2026-09-14','liability_account_id'=>$ids['loan'],'interest_account_id'=>$ids['interest'],'bank_account_id'=>$f['accounts']['1000'],'accrued_account_id'=>$ids['accrued'],'funding_journal_id'=>$fund['id'],'creation_key'=>'sample-loan','reason'=>'Sample lender terms']);
$prepaid=$post($ids['prepaid'],$f['accounts']['1000'],'100','prepaid-funding');
pl_save_release_schedule($actor,$company,$book,['name'=>'Sample insurance','kind'=>'prepaid','amount'=>'100','balance_account_id'=>$ids['prepaid'],'counterpart_account_id'=>$ids['interest'],'funding_journal_id'=>$prepaid['id'],'start_date'=>'2026-09-30','periods'=>3,'creation_key'=>'sample-release','reason'=>'Sample policy']);
$receipt=$post($f['accounts']['1000'],$f['accounts']['4000'],'50','monthly-service');
pl_save_recurring($actor,$company,$book,['name'=>'Sample monthly service','source_kind'=>'journal','source_id'=>$receipt['id'],'frequency'=>'monthly','start_date'=>'2026-09-30','max_runs'=>3,'creation_key'=>'sample-recurring','reason'=>'Sample contract']);
pl_recurring_due($company,$book,'2026-10-31',100);pl_schedule_due($company,$book,'2026-10-31',100);pl_loan_due($company,$book,'2026-10-31',100);pl_scheduler_dispatch($company,$book,100);
echo json_encode(['email'=>$email,'actor_id'=>$actor,'company_id'=>$company,'book_id'=>$book,'loan_id'=>$loan['id'],'funding_journal_id'=>$fund['id'],'source_id'=>$receipt['id']],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)."\n";
