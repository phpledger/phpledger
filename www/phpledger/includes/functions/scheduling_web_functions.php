<?php
declare(strict_types=1);

/** Parse a visibly labelled CSV schedule; never accept arbitrary executable job payloads. */
function pl_schedule_csv(string $text,bool $loan): array
{
    if(trim($text)==='') { return []; }
    $rows=[];
    foreach(preg_split('/\R/',trim($text)) as $line) {
        $fields=str_getcsv($line,',','"','');
        if(count($fields)!==($loan?3:2)) { throw new DomainException($loan?'Use date, principal, interest on each custom row.':'Use date, amount on each custom row.'); }
        $rows[]=$loan?['date'=>trim($fields[0]),'principal'=>trim($fields[1]),'interest'=>trim($fields[2])]:['date'=>trim($fields[0]),'amount'=>trim($fields[1])];
        if(count($rows)>600) { throw new DomainException('A schedule supports at most 600 rows.'); }
    }
    return $rows;
}

function pl_web_scheduling(int $actor,int $companyId,int $bookId,array $user,array $company,string $method,string $path): never
{
    $area=str_contains($path,'loans')?'loans':(str_contains($path,'recurring')?'recurring':'schedules');
    $report=str_starts_with($path,'/reports/');$return=pl_url('/'.$area);
    pl_scheduler_require($actor,$companyId,false,$area==='loans');pl_ledger_book($companyId,$bookId);
    $form=pl_form_state($return);$preview=null;
    if($method==='POST') {
        try { pl_web_assert_scope($company,$_POST); } catch(DomainException $e) { pl_form_failure($return,[],$e->getMessage()); }
        try {
            $input=$_POST;
            foreach(['source_id','every_days','max_runs','lead_days','balance_account_id','counterpart_account_id','funding_journal_id','periods','lender_party_id','liability_account_id','interest_account_id','bank_account_id','accrued_account_id','term_months'] as $key) { if(isset($input[$key]) && $input[$key]!=='') { $input[$key]=pl_web_id($_POST,$key); } }
            $action=pl_web_text($_POST,'action','save');
            pl_scheduler_require($actor,$companyId,true,$area==='loans');
            if($action==='custom_accrual' && $area==='loans') { pl_loan_custom_accrual($actor,$companyId,$bookId,pl_web_id($_POST,'id'),pl_web_id($_POST,'period_id'),pl_web_text($_POST,'amount'),pl_web_text($_POST,'reason')); }
            elseif($action==='retry') { pl_scheduler_retry($actor,$companyId,$bookId,pl_web_id($_POST,'job_id'),pl_web_text($_POST,'reason')); }
            elseif($area==='recurring' && in_array($action,['pause','resume','skip','end'],true)) { pl_recurring_change($actor,$companyId,$bookId,pl_web_id($_POST,'id'),pl_web_id($_POST,'revision'),$action,pl_web_text($_POST,'reason')); }
            elseif($action==='generate') {
                $date=pl_ledger_date(pl_web_text($_POST,'as_of'));
                if($area==='recurring') { pl_recurring_due($companyId,$bookId,$date,100); }
                elseif($area==='schedules') { pl_schedule_due($companyId,$bookId,$date,100); }
                else { pl_loan_due($companyId,$bookId,$date,100); }
                pl_scheduler_dispatch($companyId,$bookId,100);
            } elseif($area==='recurring') { pl_save_recurring($actor,$companyId,$bookId,$input); }
            else {
                $input['custom']=pl_schedule_csv(pl_web_text($_POST,'custom_csv'),$area==='loans');
                if($area==='schedules') { pl_save_release_schedule($actor,$companyId,$bookId,$input); }
                elseif($action==='preview') { $preview=pl_loan_plan($input);$form=['input'=>$_POST,'message'=>'']; }
                else {
                    $plan=pl_loan_plan($input);
                    if(!hash_equals(hash('sha256',json_encode($plan,JSON_THROW_ON_ERROR)),pl_web_text($_POST,'preview_hash'))) { throw new DomainException('Preview these loan terms before saving.'); }
                    if(pl_web_id($_POST,'id')>0) { pl_revise_loan($actor,$companyId,$bookId,pl_web_id($_POST,'id'),pl_web_id($_POST,'revision'),$input); }
                    else { pl_save_loan($actor,$companyId,$bookId,$input); }
                }
            }
            if($preview===null) { pl_notice(pl_t('Saved. Generated entries remain drafts until reviewed and posted.'));pl_redirect($return); }
        } catch(DomainException $e) { pl_form_failure($return,$_POST,$e->getMessage()); }
    }
    if($form['input']!==[] && (pl_web_id($form['input'],'company_id')!==$companyId || pl_web_id($form['input'],'book_id')!==$bookId)) { $form['input']=[]; }
    $asOf=pl_ledger_date(pl_web_text($_GET,'as_of',gmdate('Y-m-d')));
    $rows=$area==='recurring'?DB::query('SELECT id,name,status,revision,frequency,start_date,next_index FROM pl_recurring_templates WHERE company_id=%i AND book_id=%i ORDER BY id DESC',$companyId,$bookId):[];
    $data=$area==='loans'?pl_loan_report($actor,$companyId,$bookId,$asOf):($area==='schedules'?pl_schedule_report($actor,$companyId,$bookId,$asOf):null);
    $jobs=DB::query("SELECT j.id,j.job_kind,j.occurrence_date,d.status,d.attempts,x.result FROM pl_scheduler_jobs j JOIN pl_outbound_events e ON e.book_id=j.book_id AND e.event_key=CONCAT('scheduler:',j.id) JOIN pl_outbound_deliveries d ON d.event_id=e.id AND d.consumer='core.scheduler' LEFT JOIN pl_scheduler_results x ON x.job_id=j.id WHERE j.company_id=%i AND j.book_id=%i AND j.job_kind IN %ls ORDER BY j.id DESC LIMIT 100",$companyId,$bookId,$area==='loans'?['loan_payment','loan_accrual']:[$area==='recurring'?'recurring':'release']);
    $mayManage=pl_user_can($actor,$companyId,($area==='loans'?'loans.':'schedules.').'manage');
    $accounts=$mayManage?DB::query('SELECT id,code,name,type,role FROM pl_accounts WHERE company_id=%i AND book_id=%i AND is_active=1 AND is_postable=1 ORDER BY code',$companyId,$bookId):[];
    $parties=$mayManage && $area==='loans'?DB::query('SELECT id,legal_name FROM pl_parties WHERE company_id=%i ORDER BY legal_name',$companyId):[];
    $input=$form['input']?:['creation_key'=>bin2hex(random_bytes(16)),'start_date'=>gmdate('Y-m-d'),'frequency'=>'monthly','periods'=>12,'term_months'=>12,'annual_rate'=>'0','method'=>'reducing','source_kind'=>pl_web_text($_GET,'source_kind','journal'),'source_id'=>pl_web_id($_GET,'source_id'),'funding_journal_id'=>pl_web_id($_GET,'funding_journal_id')];
    if($area==='loans' && $mayManage && $form['input']===[] && pl_web_id($_GET,'revise')>0) {
        foreach($data['loans'] as $loan) { if((int)$loan['id']===pl_web_id($_GET,'revise')) { $input=array_replace($input,$loan,['principal'=>$loan['outstanding'],'start_date'=>$asOf,'method'=>$loan['version']['method'],'annual_rate'=>$loan['version']['annual_rate'],'term_months'=>$loan['version']['term_months']]);break; } }
    }
    pl_render('scheduling',compact('area','report','user','company','form','input','preview','asOf','rows','data','jobs','mayManage','accounts','parties')+['title'=>ucfirst($area)]);
}
