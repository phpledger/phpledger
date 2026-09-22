<?php
declare(strict_types=1);

function pl_schedule_audit(int $actor,int $company,int $book,string $entity,int $id,string $action,string $reason,?array $before,array $after): void
{
    DB::insert('pl_schedule_audit',['actor_id'=>$actor,'company_id'=>$company,'book_id'=>$book,'entity_type'=>$entity,'entity_id'=>$id,'action'=>$action,'reason'=>pl_ledger_text($reason,'Reason',500),'before_state'=>$before===null?null:json_encode($before,JSON_THROW_ON_ERROR),'after_state'=>json_encode($after,JSON_THROW_ON_ERROR)]);
}

/** Anchored calendar arithmetic: clipping February never changes the March anchor. */
function pl_recurrence_date(string $start,string $frequency,int $index,int $days = 1): string
{
    $start=pl_ledger_date($start);
    if($index<0 || $index>10000 || $days<1 || $days>3660) { throw new DomainException('Recurrence bounds are invalid.'); }
    $date=new DateTimeImmutable($start,new DateTimeZone('UTC'));
    if($frequency==='days' || $frequency==='weekly') { return $date->modify('+'.($index*($frequency==='weekly'?7:$days)).' days')->format('Y-m-d'); }
    $months=match($frequency){'monthly'=>1,'quarterly'=>3,'yearly'=>12,default=>throw new DomainException('Choose a recurrence frequency.')};
    $first=$date->modify('first day of this month')->modify('+'.($months*$index).' months');
    return $first->setDate((int)$first->format('Y'),(int)$first->format('m'),min((int)$date->format('d'),(int)$first->format('t')))->format('Y-m-d');
}

function pl_recurring_source(int $actor,int $company,int $book,string $kind,int $id): array
{
    if($kind==='ar') {
        $row=pl_get_ar_document($actor,$company,$book,$id);
        if($row['status']!=='posted' || $row['reversal_journal_id']!==null || !in_array($row['kind'],['invoice','bill'],true)) { throw new DomainException('Choose a posted, unreversed invoice or bill.'); }
        $row['due_days']=(int)(new DateTimeImmutable($row['document_date']))->diff(new DateTimeImmutable($row['due_date']))->format('%r%a');
        $row['cash_received']='0.0000';$row['cash_account_id']=null;
        return ['kind'=>'ar','input'=>$row];
    }
    if($kind==='document') {
        $row=pl_get_document($actor,$company,$book,$id);
        if($row['journal_id']===null || $row['reversal_journal_id']!==null) { throw new DomainException('Choose a posted, unreversed receipt or expense.'); }
        return ['kind'=>'document','input'=>$row];
    }
    if($kind==='journal') {
        $row=pl_get_journal($actor,$company,$book,$id);
        if(!in_array($row['source_type'],['general','general_journal','receipt','payment','adjustment'],true) || $row['reversal_of_id']!==null || DB::queryFirstField('SELECT id FROM pl_journals WHERE reversal_of_id=%i FOR SHARE',$id)!==null) { throw new DomainException('Choose an unreversed standalone journal.'); }
        $lines=array_map(static function(array $line):array { $line['account_id']=(int)$line['account_id'];return $line; },$row['lines']);
        return ['kind'=>'journal','input'=>['description'=>$row['description'],'reference'=>'','lines'=>$lines]];
    }
    throw new DomainException('Choose an invoice, bill, receipt, expense or standalone journal.');
}

function pl_save_recurring(int $actor,int $company,int $book,array $input): array
{
    pl_scheduler_require($actor,$company,true);
    $name=pl_ledger_text($input['name']??null,'Name',160);$start=pl_ledger_date((string)($input['start_date']??''));
    $end=empty($input['end_date'])?null:pl_ledger_date((string)$input['end_date']);
    $frequency=(string)($input['frequency']??'');$days=(int)($input['every_days']??1);pl_recurrence_date($start,$frequency,0,$days);
    $count=empty($input['max_runs'])?null:(int)$input['max_runs'];$lead=(int)($input['lead_days']??0);
    if(($end!==null && $end<$start) || ($count!==null && ($count<1 || $count>10000)) || $lead<0 || $lead>365) { throw new DomainException('Check the recurrence end, count and lead days.'); }
    $key=pl_request_key((string)($input['creation_key']??''));$reason=pl_ledger_text($input['reason']??null,'Reason',500);
    return pl_ledger_transaction(function()use($actor,$company,$book,$input,$name,$start,$end,$frequency,$days,$count,$lead,$key,$reason):array{
        pl_scheduler_require($actor,$company,true);pl_ledger_book($company,$book,true);
        $payload=pl_recurring_source($actor,$company,$book,(string)($input['source_kind']??''),(int)($input['source_id']??0));
        if(isset($input['fixed_amount']) && $input['fixed_amount']!=='') {
            if($payload['kind']!=='document') { throw new DomainException('A fixed total is available for receipts and expenses. Copy invoice/journal lines and edit their generated draft.'); }
            $payload['input']['amount']=pl_amount((string)$input['fixed_amount']);
            if(bccomp($payload['input']['amount'],'0',4)<=0) { throw new DomainException('Enter a positive fixed amount.'); }
        }
        $row=['actor_id'=>$actor,'name'=>$name,'source_kind'=>$payload['kind'],'source_id'=>(int)$input['source_id'],'source_payload'=>json_encode($payload,JSON_THROW_ON_ERROR),'frequency'=>$frequency,'every_days'=>$days,'start_date'=>$start,'end_date'=>$end,'max_runs'=>$count,'lead_days'=>$lead];
        $hash=hash('sha256',json_encode($row,JSON_THROW_ON_ERROR));
        $old=DB::queryFirstRow('SELECT * FROM pl_recurring_templates WHERE book_id=%i AND creation_key=%s FOR UPDATE',$book,$key);
        if($old) { if(!hash_equals($old['creation_hash'],$hash)) { throw new DomainException('This request describes another recurring template.'); } return $old; }
        DB::insert('pl_recurring_templates',$row+['company_id'=>$company,'book_id'=>$book,'creation_key'=>$key,'creation_hash'=>$hash]);$id=(int)DB::insertId();
        pl_schedule_audit($actor,$company,$book,'recurring',$id,'created',$reason,null,$row);
        return DB::queryFirstRow('SELECT * FROM pl_recurring_templates WHERE id=%i FOR SHARE',$id);
    });
}

function pl_recurring_change(int $actor,int $company,int $book,int $id,int $revision,string $action,string $reason): array
{
    return pl_ledger_transaction(function()use($actor,$company,$book,$id,$revision,$action,$reason):array{
        pl_scheduler_require($actor,$company,true);pl_ledger_book($company,$book,true);
        $row=DB::queryFirstRow('SELECT * FROM pl_recurring_templates WHERE id=%i AND company_id=%i AND book_id=%i FOR UPDATE',$id,$company,$book);
        if(!$row || (int)$row['revision']!==$revision) { throw new DomainException('The template changed. Reload it before changing its schedule.'); }
        if($row['status']==='ended') { throw new DomainException('An ended template cannot resume. Create a new template.'); }
        $changes=['revision'=>$revision+1];
        if($action==='skip') {
            $date=pl_recurrence_date($row['start_date'],$row['frequency'],(int)$row['next_index'],(int)$row['every_days']);
            DB::insert('pl_recurring_runs',['template_id'=>$id,'company_id'=>$company,'book_id'=>$book,'occurrence_date'=>$date,'occurrence_index'=>$row['next_index'],'template_revision'=>$revision,'action'=>'skipped','job_id'=>null,'reason'=>pl_ledger_text($reason,'Reason',500),'actor_id'=>$actor]);
            $changes['next_index']=(int)$row['next_index']+1;
        } else { $changes['status']=match($action){'pause'=>'paused','resume'=>'active','end'=>'ended',default=>throw new DomainException('Choose pause, resume, skip or end.')}; }
        DB::update('pl_recurring_templates',$changes,'id=%i',$id);
        pl_schedule_audit($actor,$company,$book,'recurring',$id,$action,$reason,$row,$changes);
        return DB::queryFirstRow('SELECT * FROM pl_recurring_templates WHERE id=%i FOR SHARE',$id);
    });
}

function pl_recurring_due(int $company,int $book,string $asOf,int $limit,bool $dry=false): array
{
    if($limit<1) { return []; }
    $asOf=pl_ledger_date($asOf);$out=[];
    foreach(DB::query("SELECT id FROM pl_recurring_templates WHERE company_id=%i AND book_id=%i AND status='active' ORDER BY id",$company,$book) as $candidate) {
        $dryOffset=0;
        while(count($out)<$limit) {
            $next=pl_ledger_transaction(function()use($company,$book,$asOf,$candidate,$dry,$dryOffset):?array{
                pl_ledger_book($company,$book,true);
                $row=DB::queryFirstRow('SELECT * FROM pl_recurring_templates WHERE id=%i FOR UPDATE',$candidate['id']);
                if($row['status']!=='active') { return null; }
                $index=(int)$row['next_index']+$dryOffset;$date=pl_recurrence_date($row['start_date'],$row['frequency'],$index,(int)$row['every_days']);
                if(($row['max_runs']!==null && $index>=(int)$row['max_runs']) || ($row['end_date']!==null && $date>$row['end_date'])) { return null; }
                if($date>(new DateTimeImmutable($asOf))->modify('+'.(int)$row['lead_days'].' days')->format('Y-m-d')) { return null; }
                if($dry) { return ['template_id'=>(int)$row['id'],'due_date'=>$date]; }
                $job=pl_scheduler_enqueue((int)$row['actor_id'],$company,$book,'recurring',(int)$row['id'],$date,json_decode($row['source_payload'],true,64,JSON_THROW_ON_ERROR),'recurring:'.$row['id'].':'.$index);
                DB::insert('pl_recurring_runs',['template_id'=>$row['id'],'company_id'=>$company,'book_id'=>$book,'occurrence_date'=>$date,'occurrence_index'=>$index,'template_revision'=>$row['revision'],'action'=>'queued','job_id'=>$job,'reason'=>'Scheduled draft preparation','actor_id'=>$row['actor_id']]);
                DB::update('pl_recurring_templates',['next_index'=>$index+1],'id=%i',$row['id']);
                return ['job_id'=>$job,'due_date'=>$date];
            });
            if($next===null) { break; }$out[]=$next;if($dry) { $dryOffset++; }
        }
        if(count($out)>=$limit) { break; }
    }
    return $out;
}

function pl_recurring_generate(array $job,array $payload): array
{
    $row=DB::queryFirstRow('SELECT status FROM pl_recurring_templates WHERE id=%i AND company_id=%i AND book_id=%i FOR SHARE',$job['source_id'],$job['company_id'],$job['book_id']);
    if(!$row || $row['status']!=='active') { throw new DomainException('The recurring template is paused or ended.'); }
    $input=$payload['input'];$input['date']=$job['occurrence_date'];$input['creation_key']='scheduled:'.$job['id'];$input['request_key']=$input['creation_key'];
    $actor=(int)$job['actor_id'];$company=(int)$job['company_id'];$book=(int)$job['book_id'];
    if($payload['kind']==='ar') { $input['due_date']=(new DateTimeImmutable($input['date']))->modify('+'.max(0,(int)($input['due_days']??0)).' days')->format('Y-m-d');$draft=pl_save_ar_document($actor,$company,$book,$input); }
    elseif($payload['kind']==='document') { $draft=pl_save_document($actor,$company,$book,$input); }
    else { $draft=pl_save_general_draft($actor,$company,$book,$input); }
    return ['kind'=>$payload['kind'],'id'=>(int)$draft['id']];
}

/** Existing posting is the funding evidence, never an inferred balancing journal. */
function pl_schedule_funding(int $actor,int $company,int $book,int $journalId,int $accountId,string $amount,bool $debit): void
{
    $journal=pl_get_journal($actor,$company,$book,$journalId);
    if($journal['reversal_of_id']!==null || DB::queryFirstField('SELECT id FROM pl_journals WHERE reversal_of_id=%i FOR SHARE',$journalId)!==null) { throw new DomainException('Use an unreversed funding or opening journal.'); }
    $net='0.0000';foreach($journal['lines'] as $line) { if((int)$line['account_id']===$accountId) { $net=bcadd($net,bcsub((string)$line[$debit?'debit':'credit'],(string)$line[$debit?'credit':'debit'],4),4); } }
    if(bccomp($net,$amount,4)!==0) { throw new DomainException('The linked funding journal must recognize exactly this principal on the selected balance account.'); }
}

function pl_schedule_account(int $actor,int $company,int $book,int $id,string $type): array
{
    $a=pl_get_account($actor,$company,$book,$id);$b=pl_ledger_book($company,$book);
    if(!$a['is_active'] || !$a['is_postable'] || $a['type']!==$type || (!empty($a['currency']) && $a['currency']!==$b['currency']) || in_array($a['role'],['receivables','payables','customer_advances','supplier_advances'],true)) { throw new DomainException('Choose an active postable non-trade '.$type.' account in the book currency.'); }
    return $a;
}

function pl_schedule_split(string $amount,string $start,int $periods,array $custom=[]): array
{
    $amount=pl_amount($amount);$start=pl_ledger_date($start);
    if(bccomp($amount,'0',4)<=0 || $periods<1 || $periods>600) { throw new DomainException('Choose a positive amount and 1 to 600 release periods.'); }
    $rows=[];$sum='0.0000';$seen=[];
    if($custom!==[]) {
        if(!array_is_list($custom) || count($custom)>600) { throw new DomainException('Enter at most 600 custom releases.'); }
        foreach($custom as $row) { $date=pl_ledger_date((string)($row['date']??''));$value=pl_amount((string)($row['amount']??''));if($date<$start || isset($seen[$date]) || bccomp($value,'0',4)<=0) { throw new DomainException('Custom releases need unique dates and positive amounts.'); }$seen[$date]=true;$sum=bcadd($sum,$value,4);$rows[]=['date'=>$date,'amount'=>$value]; }
        if(bccomp($sum,$amount,4)!==0) { throw new DomainException('Custom releases must sum exactly to the recognized amount.'); }usort($rows,fn($a,$b)=>strcmp($a['date'],$b['date']));return $rows;
    }
    $part=bcdiv($amount,(string)$periods,4);
    for($i=0;$i<$periods;$i++) { $value=$i===$periods-1?bcsub($amount,$sum,4):$part;if(bccomp($value,'0',4)<=0) { throw new DomainException('The amount is too small for this many releases.'); }$rows[]=['date'=>pl_recurrence_date($start,'monthly',$i),'amount'=>$value];$sum=bcadd($sum,$value,4); }
    return $rows;
}

function pl_save_release_schedule(int $actor,int $company,int $book,array $input): array
{
    return pl_ledger_transaction(function()use($actor,$company,$book,$input):array{
        pl_scheduler_require($actor,$company,true);pl_ledger_book($company,$book,true);
        $kind=(string)($input['kind']??'');if(!in_array($kind,['prepaid','accrual','deferred'],true)) { throw new DomainException('Choose prepaid, accrual or deferred income.'); }
        $amount=pl_amount((string)($input['amount']??''));$balance=(int)($input['balance_account_id']??0);$counterpart=(int)($input['counterpart_account_id']??0);$funding=(int)($input['funding_journal_id']??0);
        pl_schedule_account($actor,$company,$book,$balance,$kind==='prepaid'?'asset':'liability');pl_schedule_account($actor,$company,$book,$counterpart,$kind==='deferred'?'income':'expense');
        pl_schedule_funding($actor,$company,$book,$funding,$balance,$amount,$kind==='prepaid');
        $rows=pl_schedule_split($amount,(string)($input['start_date']??''),(int)($input['periods']??1),$input['custom']??[]);
        $fund=pl_get_journal($actor,$company,$book,$funding);if($rows[0]['date']<$fund['journal_date']) { throw new DomainException('Releases cannot precede the funding entry.'); }
        $data=['actor_id'=>$actor,'name'=>pl_ledger_text($input['name']??null,'Name',160),'kind'=>$kind,'balance_account_id'=>$balance,'counterpart_account_id'=>$counterpart,'funding_journal_id'=>$funding,'amount'=>$amount];
        $key=pl_request_key((string)($input['creation_key']??''));$hash=hash('sha256',json_encode([$data,$rows],JSON_THROW_ON_ERROR));
        $old=DB::queryFirstRow('SELECT * FROM pl_release_schedules WHERE book_id=%i AND creation_key=%s FOR UPDATE',$book,$key);
        if($old) { if(!hash_equals($old['creation_hash'],$hash)) { throw new DomainException('This request describes another release schedule.'); }return $old; }
        if(DB::queryFirstField('SELECT id FROM pl_release_schedules WHERE book_id=%i AND funding_journal_id=%i AND balance_account_id=%i FOR UPDATE',$book,$funding,$balance)!==null) { throw new DomainException('This funding allocation already has a release schedule.'); }
        DB::insert('pl_release_schedules',$data+['company_id'=>$company,'book_id'=>$book,'creation_key'=>$key,'creation_hash'=>$hash]);$id=(int)DB::insertId();
        foreach($rows as $row) { DB::insert('pl_schedule_releases',['schedule_id'=>$id,'company_id'=>$company,'book_id'=>$book,'due_date'=>$row['date'],'amount'=>$row['amount']]); }
        pl_schedule_audit($actor,$company,$book,'release',$id,'created',(string)($input['reason']??''),null,$data+['releases'=>$rows]);
        return $data+['id'=>$id];
    });
}

function pl_schedule_due(int $company,int $book,string $asOf,int $limit,bool $dry=false): array
{
    if($limit<1) { return []; }
    $rows=DB::query("SELECT r.*,s.actor_id,s.kind,s.balance_account_id,s.counterpart_account_id,s.name FROM pl_schedule_releases r JOIN pl_release_schedules s ON s.id=r.schedule_id WHERE r.company_id=%i AND r.book_id=%i AND r.due_date<=%s AND NOT EXISTS(SELECT 1 FROM pl_scheduler_jobs j WHERE j.book_id=r.book_id AND j.request_key=CONCAT('release:',r.id)) ORDER BY r.due_date,r.id LIMIT %i",$company,$book,pl_ledger_date($asOf),$limit);
    if(!$dry) { foreach($rows as $row) { pl_ledger_transaction(fn()=>pl_scheduler_enqueue((int)$row['actor_id'],$company,$book,'release',(int)$row['id'],$row['due_date'],['release_id'=>(int)$row['id']],'release:'.$row['id'])); } }
    return $rows;
}

function pl_schedule_generate(array $job,array $payload): array
{
    $row=DB::queryFirstRow('SELECT r.*,s.actor_id,s.kind,s.balance_account_id,s.counterpart_account_id,s.funding_journal_id,s.name FROM pl_schedule_releases r JOIN pl_release_schedules s ON s.id=r.schedule_id WHERE r.id=%i AND r.company_id=%i AND r.book_id=%i FOR SHARE',$payload['release_id'],$job['company_id'],$job['book_id']);
    if(!$row) { throw new DomainException('The release plan is unavailable.'); }
    if(DB::queryFirstField('SELECT id FROM pl_journals WHERE reversal_of_id=%i FOR SHARE',$row['funding_journal_id'])!==null) { throw new DomainException('The funding entry has been reversed. Review its schedule.'); }
    $debit=$row['kind']==='prepaid'?$row['counterpart_account_id']:$row['balance_account_id'];$credit=$row['kind']==='prepaid'?$row['balance_account_id']:$row['counterpart_account_id'];
    $draft=pl_save_general_draft((int)$job['actor_id'],(int)$job['company_id'],(int)$job['book_id'],['date'=>$row['due_date'],'description'=>'Scheduled '.$row['kind'].' release: '.$row['name'],'creation_key'=>'scheduled:'.$job['id'],'lines'=>[
        ['account_id'=>(int)$debit,'debit'=>$row['amount'],'credit'=>'0','description'=>'Scheduled release'],['account_id'=>(int)$credit,'debit'=>'0','credit'=>$row['amount'],'description'=>'Scheduled release']]]);
    return ['kind'=>'journal','id'=>(int)$draft['id']];
}

/** Shared with period/year close: due plans remain outstanding until an unreversed draft posts. */
function pl_schedule_unreleased(int $companyId,int $bookId,string $through): array
{
    return DB::query("SELECT r.schedule_id,r.id AS release_id,s.name,r.due_date,r.amount,d.id AS draft_id FROM pl_schedule_releases r JOIN pl_release_schedules s ON s.id=r.schedule_id LEFT JOIN pl_scheduler_jobs j ON j.book_id=r.book_id AND j.request_key=CONCAT('release:',r.id) LEFT JOIN pl_scheduler_results x ON x.job_id=j.id LEFT JOIN pl_effective_general_drafts d ON d.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(x.result,'$.id')) AS UNSIGNED) AND d.company_id=r.company_id AND d.book_id=r.book_id LEFT JOIN pl_journals v ON v.reversal_of_id=d.journal_id WHERE r.company_id=%i AND r.book_id=%i AND r.due_date<=%s AND (d.journal_id IS NULL OR v.id IS NOT NULL) ORDER BY r.due_date,r.id FOR SHARE",$companyId,$bookId,pl_ledger_date($through));
}

function pl_scheduled_draft_job(int $company,int $book,int $draftId): ?array
{
    return DB::queryFirstRow("SELECT j.* FROM pl_scheduler_jobs j JOIN pl_scheduler_results r ON r.job_id=j.id WHERE j.company_id=%i AND j.book_id=%i AND JSON_UNQUOTE(JSON_EXTRACT(r.result,'$.kind'))='journal' AND CAST(JSON_UNQUOTE(JSON_EXTRACT(r.result,'$.id')) AS UNSIGNED)=%i FOR SHARE",$company,$book,$draftId);
}

function pl_scheduled_general_validate(int $actor,int $company,int $book,array $draft): void
{
    $job=pl_scheduled_draft_job($company,$book,(int)$draft['id']);if(!$job || $job['job_kind']==='recurring') { return; }
    pl_scheduler_require($actor,$company,true,str_starts_with($job['job_kind'],'loan_'));
    $payload=json_decode($job['payload'],true,64,JSON_THROW_ON_ERROR);
    if($draft['document_date']!==$job['occurrence_date']) { throw new DomainException('A scheduled release or instalment must retain its planned date. Create a reviewed new plan to change it.'); }
    if($job['job_kind']==='release') {
        $row=DB::queryFirstRow('SELECT r.amount,s.kind,s.balance_account_id,s.counterpart_account_id,s.funding_journal_id FROM pl_schedule_releases r JOIN pl_release_schedules s ON s.id=r.schedule_id WHERE r.id=%i FOR SHARE',$payload['release_id']);
        if(DB::queryFirstField('SELECT id FROM pl_journals WHERE reversal_of_id=%i FOR SHARE',$row['funding_journal_id'])!==null) { throw new DomainException('The funding entry has been reversed.'); }
        $debit=(int)($row['kind']==='prepaid'?$row['counterpart_account_id']:$row['balance_account_id']);$credit=(int)($row['kind']==='prepaid'?$row['balance_account_id']:$row['counterpart_account_id']);
        $expected=[['account_id'=>$debit,'debit'=>$row['amount'],'credit'=>'0'],['account_id'=>$credit,'debit'=>'0','credit'=>$row['amount']]];
    } else { $expected=pl_loan_job_lines($job,$payload); }
    $canon=static function(array $lines): array { $out=[];foreach($lines as $line) { $out[]=[(int)$line['account_id'],bcadd((string)$line['debit'],'0',4),bcadd((string)$line['credit'],'0',4)]; }sort($out);return $out; };
    if($canon($draft['lines'])!==$canon($expected)) { throw new DomainException('These lines no longer match the schedule. Restore the planned accounts and amounts or create a new schedule version.'); }
}

/** Central funnel guard also covers generic same-identity corrections and direct callers. */
function pl_schedule_validate_posting(int $actor,int $company,int $book,array $payload,?int $reversalOf): void
{
    $source=$payload;
    if($reversalOf!==null) { $source=DB::queryFirstRow('SELECT source_type,source_reference FROM pl_journals WHERE id=%i AND company_id=%i AND book_id=%i FOR SHARE',$reversalOf,$company,$book)?:[]; }
    if(($source['source_type']??'')!=='general_journal' || !preg_match('/^general:([1-9][0-9]*)$/D',(string)($source['source_reference']??''),$match)) { return; }
    $id=(int)$match[1];$job=pl_scheduled_draft_job($company,$book,$id);
    if(!$job || $job['job_kind']==='recurring') { return; }
    pl_scheduler_require($actor,$company,true,str_starts_with($job['job_kind'],'loan_'));
    if(pl_correction_context()!==[]) { throw new DomainException('Scheduled amounts require a linked reversal and a reviewed schedule version; generic correction is unavailable.'); }
    if($reversalOf!==null) {
        if($job['job_kind']==='loan_payment') {
            $data=json_decode($job['payload'],true,64,JSON_THROW_ON_ERROR);
            $row=DB::queryFirstRow('SELECT v.version,l.current_version FROM pl_loan_instalments i JOIN pl_loan_versions v ON v.id=i.version_id JOIN pl_loans l ON l.id=i.loan_id WHERE i.id=%i FOR SHARE',$data['instalment_id']);
            if($row && (int)$row['version']!==(int)$row['current_version']) { throw new DomainException('This payment was incorporated into a later loan version. Review that version before correcting earlier payments.'); }
        }
        return;
    }
    pl_scheduled_general_validate($actor,$company,$book,['id'=>$id,'document_date'=>$payload['date'],'lines'=>$payload['lines']]);
}

function pl_scheduled_general_posted(int $actor,int $company,int $book,int $draftId,int $journalId): void
{
    $job=pl_scheduled_draft_job($company,$book,$draftId);
    if($job && $job['job_kind']==='loan_accrual') { $payload=json_decode($job['payload'],true,64,JSON_THROW_ON_ERROR);pl_schedule_journal_reversal($actor,$company,$book,$journalId,$payload['reverse_on'],'Reverse the reviewed loan interest accrual'); }
}

function pl_schedule_report(int $actor,int $company,int $book,string $asOf): array
{
    pl_scheduler_require($actor,$company);pl_ledger_book($company,$book);$asOf=pl_ledger_date($asOf);$rows=[];$accounts=[];
    foreach(DB::query('SELECT s.*,j.journal_date FROM pl_release_schedules s JOIN pl_journals j ON j.id=s.funding_journal_id WHERE s.company_id=%i AND s.book_id=%i AND j.journal_date<=%s ORDER BY s.id',$company,$book,$asOf) as $s) {
        $released='0.0000';$releases=DB::query("SELECT r.*,d.id AS draft_id,d.journal_id,j.journal_date,v.id AS reversal_id,v.journal_date AS reversal_date FROM pl_schedule_releases r LEFT JOIN pl_scheduler_jobs q ON q.book_id=r.book_id AND q.request_key=CONCAT('release:',r.id) LEFT JOIN pl_scheduler_results x ON x.job_id=q.id LEFT JOIN pl_effective_general_drafts d ON d.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(x.result,'$.id')) AS UNSIGNED) LEFT JOIN pl_journals j ON j.id=d.journal_id LEFT JOIN pl_journals v ON v.reversal_of_id=j.id WHERE r.schedule_id=%i ORDER BY r.due_date",$s['id']);
        foreach($releases as $r) { if($r['journal_id']!==null && $r['journal_date']<=$asOf && ($r['reversal_id']===null || $r['reversal_date']>$asOf)) { $released=bcadd($released,$r['amount'],4); } }
        $s['released']=$released;$s['unreleased']=bcsub($s['amount'],$released,4);$s['releases']=$releases;$rows[]=$s;
        $id=(int)$s['balance_account_id'];$accounts[$id]=bcadd($accounts[$id]??'0',$s['unreleased'],4);
    }
    $tie=[];foreach($accounts as $id=>$expected) { $a=pl_get_account($actor,$company,$book,$id);$balance=pl_cash_account_balance($company,$book,$id,$asOf);if($a['type']==='liability') { $balance=bcsub('0',$balance,4); }$tie[]=['account_id'=>$id,'code'=>$a['code'],'name'=>$a['name'],'scheduled_balance'=>$expected,'ledger_balance'=>$balance,'difference'=>bcsub($balance,$expected,4)]; }
    return ['as_of'=>$asOf,'schedules'=>$rows,'accounts'=>$tie];
}
