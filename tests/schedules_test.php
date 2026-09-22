<?php
declare(strict_types=1);

function schedule_fixture(): array
{
    $f=ledger_fixture();
    foreach(['prepaid'=>['asset','1-990-10001-00'],'loan'=>['liability','2-990-10001-00'],'accrued'=>['liability','2-990-10002-00'],'deferred'=>['liability','2-990-10003-00'],'interest'=>['expense','5-990-10001-00']] as $name=>[$type,$code]) {
        $a=pl_save_account($f['actor_id'],$f['company_id'],$f['book_id'],['code'=>$code,'name'=>'Sample '.$name,'type'=>$type,'is_active'=>true,'is_contra'=>false,'is_monetary'=>false,'reason'=>'Sample schedule account','creation_key'=>'sample-'.$name]);$f[$name]=(int)$a['id'];
    }
    $party=pl_save_party($f['actor_id'],$f['company_id'],$f['book_id'],['legal_name'=>'Sample Lender','country_code'=>'US','entity_type'=>'company','is_customer'=>false,'is_vendor'=>true,'currency'=>'USD','request_key'=>'sample-lender','reason'=>'Sample lender']);$f['lender']=(int)$party['id'];return $f;
}
function schedule_funding(array $f,int $debit,int $credit,string $amount,string $key='funding'): array
{
    return pl_post_journal($f['actor_id'],$f['company_id'],$f['book_id'],['date'=>'2026-09-14','currency'=>'USD','source_type'=>'general','source_reference'=>$key,'idempotency_key'=>$key,'description'=>'Sample funding','lines'=>[['account_id'=>$debit,'debit'=>$amount,'credit'=>'0','description'=>'Sample'],['account_id'=>$credit,'debit'=>'0','credit'=>$amount,'description'=>'Sample']]]);
}
function schedule_drafts(array $f,string $kind): array
{
    $rows=DB::query("SELECT x.result FROM pl_scheduler_results x JOIN pl_scheduler_jobs j ON j.id=x.job_id WHERE j.company_id=%i AND j.book_id=%i AND j.job_kind=%s ORDER BY j.id",$f['company_id'],$f['book_id'],$kind);return array_map(fn($r)=>json_decode($r['result'],true,64,JSON_THROW_ON_ERROR),$rows);
}

test('calendar recurrences preserve month-end and leap anchors and release totals exactly',function():void{
    assert_same('2026-02-28',pl_recurrence_date('2026-01-31','monthly',1));assert_same('2026-03-31',pl_recurrence_date('2026-01-31','monthly',2));assert_same('2025-02-28',pl_recurrence_date('2024-02-29','yearly',1));assert_same('2028-02-29',pl_recurrence_date('2024-02-29','yearly',4));
    $rows=pl_schedule_split('100','2026-01-31',3);assert_same(['33.3333','33.3333','33.3334'],array_column($rows,'amount'));assert_throws(fn()=>pl_schedule_split('100','2026-01-01',2,[['date'=>'2026-01-01','amount'=>'99']]),DomainException::class);
});

test('loan calculators preserve principal and use declared nominal and flat conventions',function():void{
    $base=['principal'=>'1000','annual_rate'=>'12','term_months'=>2,'start_date'=>'2026-01-31','method'=>'reducing'];$plan=pl_loan_plan($base);
    assert_same('507.5124',$plan['payment']);assert_same('497.5124',$plan['rows'][0]['principal']);assert_same('502.4876',$plan['rows'][1]['principal']);assert_same('15.0249',$plan['total_interest']);assert_same('0.0000',$plan['rows'][1]['closing_balance']);assert_same('2026-03-31',$plan['rows'][1]['date']);
    $flat=pl_loan_plan(array_replace($base,['method'=>'flat']));assert_same('20.0000',$flat['total_interest']);assert_same('510.0000',$flat['payment']);
    $zero=pl_loan_plan(array_replace($base,['annual_rate'=>'0','term_months'=>3]));assert_same('333.3334',$zero['rows'][2]['principal']);
    assert_throws(fn()=>pl_loan_plan(array_replace($base,['payment'=>'1'])),DomainException::class);
    $custom=pl_loan_plan(array_replace($base,['method'=>'custom','custom'=>[['date'=>'2026-02-28','principal'=>'600','interest'=>'8'],['date'=>'2026-04-01','principal'=>'400','interest'=>'3']]]));assert_same('11.0000',$custom['total_interest']);
});

test('recurring catch-up creates review drafts once and internal jobs never fan out to external consumers',function():void{
    $f=ledger_fixture();$source=pl_post_journal($f['actor_id'],$f['company_id'],$f['book_id'],ledger_payload($f,'120.00'));
    $t=pl_save_recurring($f['actor_id'],$f['company_id'],$f['book_id'],['name'=>'Sample monthly','source_kind'=>'journal','source_id'=>$source['id'],'frequency'=>'monthly','start_date'=>'2026-09-30','max_runs'=>3,'lead_days'=>2,'creation_key'=>'recurring-fixture','reason'=>'Sample recurrence']);
    assert_same(3,count(pl_recurring_due($f['company_id'],$f['book_id'],'2026-11-30',10)));
    assert_same(0,count(pl_recurring_due($f['company_id'],$f['book_id'],'2026-11-30',10)));
    $claim=pl_outbound_claim('core.scheduler',$f['company_id'],$f['book_id']);assert_true(pl_scheduler_execute($claim));
    assert_same(2,pl_scheduler_dispatch($f['company_id'],$f['book_id'],10)['succeeded']);
    assert_same(3,count(schedule_drafts($f,'recurring')));
    assert_same(1,(int)DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id=%i',$f['book_id']));
    $seen=[];pl_dispatch_outbound_events(['sample-external'=>['company_id'=>$f['company_id'],'book_id'=>$f['book_id'],'handler'=>function($event)use(&$seen){$seen[]=$event['event_type'];return true;}]],50);
    assert_true(!in_array('scheduler.draft',$seen,true));
    $drafts=schedule_drafts($f,'recurring');$d=pl_get_general_draft($f['actor_id'],$f['company_id'],$f['book_id'],$drafts[0]['id']);assert_same(null,$d['journal_id']);assert_same('2026-09-30',$d['document_date']);
});

test('prepaid releases reconcile, stay unposted until review and block close until released',function():void{
    $f=schedule_fixture();$fund=schedule_funding($f,$f['prepaid'],$f['accounts']['1000'],'100');
    $s=pl_save_release_schedule($f['actor_id'],$f['company_id'],$f['book_id'],['name'=>'Sample insurance','kind'=>'prepaid','amount'=>'100','balance_account_id'=>$f['prepaid'],'counterpart_account_id'=>$f['interest'],'funding_journal_id'=>$fund['id'],'start_date'=>'2026-09-30','periods'=>3,'creation_key'=>'release-plan','reason'=>'Sample prepaid']);
    assert_same(1,count(pl_schedule_unreleased($f['company_id'],$f['book_id'],'2026-09-30')));
    pl_schedule_due($f['company_id'],$f['book_id'],'2026-09-30',10);assert_same(1,pl_scheduler_dispatch($f['company_id'],$f['book_id'],10)['succeeded']);
    $id=schedule_drafts($f,'release')[0]['id'];$d=pl_get_general_draft($f['actor_id'],$f['company_id'],$f['book_id'],$id);pl_post_general_draft($f['actor_id'],$f['company_id'],$f['book_id'],$id,$d['revision']);
    assert_same([],pl_schedule_unreleased($f['company_id'],$f['book_id'],'2026-09-30'));
    $report=pl_schedule_report($f['actor_id'],$f['company_id'],$f['book_id'],'2026-09-30');assert_same('66.6667',$report['schedules'][0]['unreleased']);assert_same('0.0000',$report['accounts'][0]['difference']);
    $other=ledger_fixture();assert_throws(fn()=>pl_schedule_report($other['actor_id'],$f['company_id'],$f['book_id'],'2026-09-30'),DomainException::class);
});

test('loan drafts split principal interest and keep ledger aligned after review',function():void{
    $f=schedule_fixture();$fund=schedule_funding($f,$f['accounts']['1000'],$f['loan'],'1000');
    $input=['name'=>'Sample financing','lender_party_id'=>$f['lender'],'principal'=>'1000','annual_rate'=>'12','method'=>'reducing','term_months'=>2,'start_date'=>'2026-09-14','liability_account_id'=>$f['loan'],'interest_account_id'=>$f['interest'],'bank_account_id'=>$f['accounts']['1000'],'accrued_account_id'=>$f['accrued'],'funding_journal_id'=>$fund['id'],'creation_key'=>'loan-fixture','reason'=>'Sample lender terms'];
    $loan=pl_save_loan($f['actor_id'],$f['company_id'],$f['book_id'],$input);assert_same($loan['id'],pl_save_loan($f['actor_id'],$f['company_id'],$f['book_id'],$input)['id']);
    pl_loan_due($f['company_id'],$f['book_id'],'2026-10-14',10);assert_same(1,pl_scheduler_dispatch($f['company_id'],$f['book_id'],10)['succeeded']);
    $id=schedule_drafts($f,'loan_payment')[0]['id'];$d=pl_get_general_draft($f['actor_id'],$f['company_id'],$f['book_id'],$id);assert_same(3,count($d['lines']));pl_post_general_draft($f['actor_id'],$f['company_id'],$f['book_id'],$id,$d['revision']);
    $r=pl_loan_report($f['actor_id'],$f['company_id'],$f['book_id'],'2026-10-14');assert_same('502.4876',$r['loans'][0]['outstanding']);assert_same('10.0000',$r['loans'][0]['interest_paid']);assert_same('0.0000',$r['accounts'][0]['difference']);
    pl_revise_loan($f['actor_id'],$f['company_id'],$f['book_id'],$loan['id'],1,['method'=>'flat','annual_rate'=>'10','term_months'=>2,'start_date'=>'2026-10-15','reason'=>'Sample rate change']);assert_same(2,(int)DB::queryFirstField('SELECT COUNT(*) FROM pl_loan_versions WHERE loan_id=%i',$loan['id']));
    assert_throws(fn()=>DB::query('UPDATE pl_loan_instalments SET interest=0 WHERE loan_id=%i',$loan['id']),Throwable::class,'immutable');
});

test('scheduler stale leases cannot create drafts and exhausted retries preserve immutable attempts',function():void{
    $f=ledger_fixture();$source=pl_post_journal($f['actor_id'],$f['company_id'],$f['book_id'],ledger_payload($f));
    pl_save_recurring($f['actor_id'],$f['company_id'],$f['book_id'],['name'=>'Lease fixture','source_kind'=>'journal','source_id'=>$source['id'],'frequency'=>'monthly','start_date'=>'2026-09-30','max_runs'=>1,'creation_key'=>'lease-fixture','reason'=>'Sample lease']);
    $job=pl_recurring_due($f['company_id'],$f['book_id'],'2026-09-30',1)[0]['job_id'];
    $stale=pl_outbound_claim('core.scheduler',$f['company_id'],$f['book_id']);DB::query('UPDATE pl_outbound_deliveries SET leased_until=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE id=%i',$stale['id']);
    assert_same(false,pl_scheduler_execute($stale));assert_same([],schedule_drafts($f,'recurring'));
    $current=pl_outbound_claim('core.scheduler',$f['company_id'],$f['book_id']);assert_same(false,pl_scheduler_execute($stale));
    pl_outbound_acknowledge($current['id'],$current['token'],false);
    for($n=3;$n<=8;$n++){DB::query('UPDATE pl_outbound_deliveries SET available_at=UTC_TIMESTAMP() WHERE id=%i',$stale['id']);$claim=pl_outbound_claim('core.scheduler',$f['company_id'],$f['book_id']);pl_outbound_acknowledge($claim['id'],$claim['token'],false);}
    assert_same('dead',DB::queryFirstField('SELECT status FROM pl_outbound_deliveries WHERE id=%i',$stale['id']));
    pl_scheduler_retry($f['actor_id'],$f['company_id'],$f['book_id'],$job,'Fixed sample source');assert_same(1,pl_scheduler_dispatch($f['company_id'],$f['book_id'],10)['succeeded']);
    assert_same(8,(int)DB::queryFirstField('SELECT COUNT(*) FROM pl_outbound_attempts WHERE delivery_id=%i',$stale['id']));assert_same(1,count(schedule_drafts($f,'recurring')));
});

test('scheduled releases reject altered vectors and generic corrections through the central funnel',function():void{
    $f=schedule_fixture();$fund=schedule_funding($f,$f['prepaid'],$f['accounts']['1000'],'100');
    pl_save_release_schedule($f['actor_id'],$f['company_id'],$f['book_id'],['name'=>'Protected release','kind'=>'prepaid','amount'=>'100','balance_account_id'=>$f['prepaid'],'counterpart_account_id'=>$f['interest'],'funding_journal_id'=>$fund['id'],'start_date'=>'2026-09-30','periods'=>1,'creation_key'=>'protected-release','reason'=>'Sample']);
    pl_schedule_due($f['company_id'],$f['book_id'],'2026-09-30',1);pl_scheduler_dispatch($f['company_id'],$f['book_id'],1);$id=schedule_drafts($f,'release')[0]['id'];$d=pl_get_general_draft($f['actor_id'],$f['company_id'],$f['book_id'],$id);
    $input=['date'=>$d['document_date'],'description'=>$d['description'],'reference'=>$d['reference'],'lines'=>$d['lines']];$input['lines'][0]['debit']='101';$input['lines'][1]['credit']='101';
    $changed=pl_save_general_draft($f['actor_id'],$f['company_id'],$f['book_id'],$input,$id,$d['revision']);assert_throws(fn()=>pl_post_general_draft($f['actor_id'],$f['company_id'],$f['book_id'],$id,$changed['revision']),DomainException::class,'match');
    $input['lines']=$d['lines'];$restored=pl_save_general_draft($f['actor_id'],$f['company_id'],$f['book_id'],$input,$id,$changed['revision']);$posted=pl_post_general_draft($f['actor_id'],$f['company_id'],$f['book_id'],$id,$restored['revision']);
    assert_throws(fn()=>pl_correct_source($f['actor_id'],$f['company_id'],$f['book_id'],'general_journal',$id,$posted['revision'],$input,'2026-09-30','schedule-correction','Sample correction'),DomainException::class,'Scheduled');
    assert_same(2,(int)DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id=%i',$f['book_id']));
    $other=ledger_fixture();DB::insert('pl_company_members',['company_id'=>$f['company_id'],'user_id'=>$other['actor_id'],'role'=>'viewer']);assert_throws(fn()=>pl_scheduler_retry($other['actor_id'],$f['company_id'],$f['book_id'],(int)DB::queryFirstField('SELECT id FROM pl_scheduler_jobs WHERE book_id=%i',$f['book_id']),'Forged'),DomainException::class);
});
