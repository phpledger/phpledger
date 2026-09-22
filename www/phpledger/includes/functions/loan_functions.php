<?php
declare(strict_types=1);

/** Monthly nominal APR/12, exact decimal arithmetic, half-up 4dp amounts. */
function pl_loan_round(string $value): string
{
    return bcadd($value,bccomp($value,'0',16)<0?'-0.00005':'0.00005',4);
}

function pl_loan_plan(array $input): array
{
    $principal=pl_amount((string)($input['principal']??''));$rate=(string)($input['annual_rate']??'0');$months=(int)($input['term_months']??0);$start=pl_ledger_date((string)($input['start_date']??''));$method=(string)($input['method']??'');
    if(bccomp($principal,'0',4)<=0 || !preg_match('/^\d{1,3}(\.\d{1,8})?$/D',$rate) || bccomp($rate,'100',8)>0 || $months<1 || $months>600 || !in_array($method,['reducing','flat','custom'],true)) { throw new DomainException('Choose principal, 0 to 100 percent nominal annual rate and 1 to 600 monthly instalments.'); }
    $monthly=bcdiv($rate,'1200',16);$payment='0.0000';$rows=[];$balance=$principal;$interestTotal='0.0000';
    if($method==='custom') {
        $custom=$input['custom']??[];if(!is_array($custom) || !array_is_list($custom) || $custom===[] || count($custom)>600) { throw new DomainException('Enter the lender schedule, up to 600 rows.'); }
        $last=$start;foreach($custom as $r) { $date=pl_ledger_date((string)($r['date']??''));$p=pl_amount((string)($r['principal']??''));$i=pl_amount((string)($r['interest']??''));if($date<=$last || bccomp($p,$balance,4)>0 || bccomp(bcadd($p,$i,4),'0',4)<=0) { throw new DomainException('Custom dates must increase and principal cannot exceed the remaining balance.'); }$balance=bcsub($balance,$p,4);$rows[]=['date'=>$date,'principal'=>$p,'interest'=>$i,'closing_balance'=>$balance];$interestTotal=bcadd($interestTotal,$i,4);$last=$date; }
        if(bccomp($balance,'0',4)!==0) { throw new DomainException('The custom principal rows must repay the full principal exactly.'); }
        $payment=bcadd($rows[0]['principal'],$rows[0]['interest'],4);
    } else {
        if($method==='flat') { $interestTotal=pl_loan_round(bcmul(bcmul($principal,$monthly,16),(string)$months,16));$payment=pl_loan_round(bcdiv(bcadd($principal,$interestTotal,16),(string)$months,16)); }
        elseif(bccomp($monthly,'0',16)===0) { $payment=pl_loan_round(bcdiv($principal,(string)$months,16)); }
        else { $factor=bcpow(bcadd('1',$monthly,16),(string)$months,16);$payment=pl_loan_round(bcdiv(bcmul(bcmul($principal,$monthly,16),$factor,16),bcsub($factor,'1',16),16)); }
        if(isset($input['payment']) && $input['payment']!=='') { $payment=pl_amount((string)$input['payment']); }
        $flatTotal=$interestTotal;$interestTotal='0.0000';
        for($n=1;$n<=$months;$n++) {
            $interest=$method==='flat'?($n===$months?bcsub($flatTotal,$interestTotal,4):pl_loan_round(bcdiv($flatTotal,(string)$months,16))):pl_loan_round(bcmul($balance,$monthly,16));
            $p=$n===$months?$balance:bcsub($payment,$interest,4);
            if(bccomp($p,'0',4)<=0 || ($n<$months && bccomp($p,$balance,4)>=0)) { throw new DomainException('The payment does not amortize over this term. Change the term/payment or use a custom schedule.'); }
            $balance=bcsub($balance,$p,4);$interestTotal=bcadd($interestTotal,$interest,4);$rows[]=['date'=>pl_recurrence_date($start,'monthly',$n),'principal'=>$p,'interest'=>$interest,'closing_balance'=>$balance];
        }
    }
    return ['principal'=>$principal,'method'=>$method,'annual_rate'=>bcadd($rate,'0',8),'term_months'=>count($rows),'start_date'=>$start,'payment'=>$payment,'total_interest'=>$interestTotal,'total_payments'=>bcadd($principal,$interestTotal,4),'rows'=>$rows,'convention'=>'Monthly nominal APR / 12; Actual/365 period-end accrual; 4-decimal half-up amounts; final principal residual cleared.'];
}

function pl_loan_store_version(int $actor,int $company,int $book,int $loanId,int $version,array $plan,string $reason): int
{
    DB::insert('pl_loan_versions',['loan_id'=>$loanId,'company_id'=>$company,'book_id'=>$book,'version'=>$version,'method'=>$plan['method'],'annual_rate'=>$plan['annual_rate'],'term_months'=>$plan['term_months'],'effective_date'=>$plan['start_date'],'principal'=>$plan['principal'],'payment'=>$plan['payment'],'reason'=>pl_ledger_text($reason,'Reason',500),'actor_id'=>$actor]);$id=(int)DB::insertId();
    foreach($plan['rows'] as $r) { DB::insert('pl_loan_instalments',['version_id'=>$id,'loan_id'=>$loanId,'company_id'=>$company,'book_id'=>$book,'due_date'=>$r['date'],'principal'=>$r['principal'],'interest'=>$r['interest'],'closing_balance'=>$r['closing_balance']]); }
    return $id;
}

function pl_save_loan(int $actor,int $company,int $book,array $input): array
{
    $plan=pl_loan_plan($input);$reason=pl_ledger_text($input['reason']??null,'Reason',500);$key=pl_request_key((string)($input['creation_key']??''));
    return pl_ledger_transaction(function()use($actor,$company,$book,$input,$plan,$reason,$key):array{
        pl_scheduler_require($actor,$company,true,true);$b=pl_ledger_book($company,$book,true);
        $liability=(int)($input['liability_account_id']??0);$interest=(int)($input['interest_account_id']??0);$bank=(int)($input['bank_account_id']??0);$accrued=(int)($input['accrued_account_id']??0);$funding=(int)($input['funding_journal_id']??0);$lender=(int)($input['lender_party_id']??0);
        pl_get_party($actor,$company,$book,$lender);pl_schedule_account($actor,$company,$book,$liability,'liability');pl_schedule_account($actor,$company,$book,$interest,'expense');pl_schedule_account($actor,$company,$book,$accrued,'liability');$cash=pl_schedule_account($actor,$company,$book,$bank,'asset');
        if($cash['role']!=='cash_bank' || count(array_unique([$liability,$interest,$bank,$accrued]))!==4) { throw new DomainException('Use separate loan, accrued-interest, interest-expense and cash/bank accounts.'); }
        pl_schedule_funding($actor,$company,$book,$funding,$liability,$plan['principal'],false);$j=pl_get_journal($actor,$company,$book,$funding);
        if($j['journal_date']!==$plan['start_date']) { throw new DomainException('The first loan schedule starts on its linked funding/opening date.'); }
        $row=['actor_id'=>$actor,'name'=>pl_ledger_text($input['name']??null,'Name',160),'lender_party_id'=>$lender,'liability_account_id'=>$liability,'interest_account_id'=>$interest,'bank_account_id'=>$bank,'accrued_account_id'=>$accrued,'funding_journal_id'=>$funding,'principal'=>$plan['principal'],'start_date'=>$plan['start_date']];
        $hash=hash('sha256',json_encode([$row,$plan],JSON_THROW_ON_ERROR));$old=DB::queryFirstRow('SELECT * FROM pl_loans WHERE book_id=%i AND creation_key=%s FOR UPDATE',$book,$key);
        if($old) { if(!hash_equals($old['creation_hash'],$hash)) { throw new DomainException('This request describes another loan.'); } $old['id']=(int)$old['id'];$old['revision']=(int)$old['revision'];return $old; }
        if(DB::queryFirstField('SELECT id FROM pl_loans WHERE book_id=%i AND funding_journal_id=%i AND liability_account_id=%i FOR UPDATE',$book,$funding,$liability)!==null) { throw new DomainException('This funding entry already has a loan schedule.'); }
        DB::insert('pl_loans',$row+['company_id'=>$company,'book_id'=>$book,'creation_key'=>$key,'creation_hash'=>$hash]);$id=(int)DB::insertId();pl_loan_store_version($actor,$company,$book,$id,1,$plan,$reason);pl_schedule_audit($actor,$company,$book,'loan',$id,'created',$reason,null,$row+['plan'=>$plan]);return $row+['id'=>$id,'revision'=>1];
    });
}

function pl_loan_paid_rows(int $company,int $book,int $loanId,string $asOf): array
{
    return DB::query("SELECT i.*,d.journal_id,j.journal_date FROM pl_loan_instalments i JOIN pl_scheduler_jobs q ON q.book_id=i.book_id AND q.request_key=CONCAT('loan-payment:',i.id) JOIN pl_scheduler_results x ON x.job_id=q.id JOIN pl_effective_general_drafts d ON d.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(x.result,'$.id')) AS UNSIGNED) JOIN pl_journals j ON j.id=d.journal_id LEFT JOIN pl_journals v ON v.reversal_of_id=j.id AND v.journal_date<=%s WHERE i.company_id=%i AND i.book_id=%i AND i.loan_id=%i AND j.journal_date<=%s AND v.id IS NULL ORDER BY j.journal_date,i.id FOR SHARE",$asOf,$company,$book,$loanId,$asOf);
}

function pl_revise_loan(int $actor,int $company,int $book,int $id,int $revision,array $input): array
{
    return pl_ledger_transaction(function()use($actor,$company,$book,$id,$revision,$input):array{
        pl_scheduler_require($actor,$company,true,true);pl_ledger_book($company,$book,true);$loan=DB::queryFirstRow('SELECT * FROM pl_loans WHERE id=%i AND company_id=%i AND book_id=%i FOR UPDATE',$id,$company,$book);
        if(!$loan || (int)$loan['revision']!==$revision) { throw new DomainException('The loan changed. Review the current schedule version.'); }
        $effective=pl_ledger_date((string)($input['start_date']??''));$paid=pl_loan_paid_rows($company,$book,$id,'9999-12-31');$remaining=$loan['principal'];
        $activity=DB::query("SELECT j.journal_date,v.journal_date AS reversed_on FROM pl_loan_instalments i JOIN pl_scheduler_jobs q ON q.book_id=i.book_id AND q.request_key=CONCAT('loan-payment:',i.id) JOIN pl_scheduler_results x ON x.job_id=q.id JOIN pl_effective_general_drafts d ON d.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(x.result,'$.id')) AS UNSIGNED) JOIN pl_journals j ON j.id=d.journal_id LEFT JOIN pl_journals v ON v.reversal_of_id=j.id WHERE i.loan_id=%i AND i.company_id=%i AND i.book_id=%i FOR SHARE",$id,$company,$book);
        foreach($activity as $event) { if(max($event['journal_date'],$event['reversed_on']??'')>$effective) { throw new DomainException('A new loan version cannot precede an existing payment or its dated reversal.'); } }
        foreach($paid as $r) { if($r['journal_date']>$effective) { throw new DomainException('A new version cannot precede a posted instalment.'); }$remaining=bcsub($remaining,$r['principal'],4); }
        $currentDate=DB::queryFirstField('SELECT effective_date FROM pl_loan_versions WHERE loan_id=%i AND version=%i FOR SHARE',$id,$loan['current_version']);
        if($effective<$currentDate || bccomp($remaining,'0',4)<=0) { throw new DomainException('A revised schedule cannot precede its current version and needs remaining principal.'); }
        $input['principal']=$remaining;$plan=pl_loan_plan($input);$version=(int)$loan['current_version']+1;$reason=pl_ledger_text($input['reason']??null,'Reason',500);
        pl_loan_store_version($actor,$company,$book,$id,$version,$plan,$reason);DB::update('pl_loans',['current_version'=>$version,'revision'=>$revision+1,'actor_id'=>$actor],'id=%i',$id);pl_schedule_audit($actor,$company,$book,'loan',$id,'rescheduled',$reason,$loan,['version'=>$version,'plan'=>$plan]);return ['id'=>$id,'version'=>$version,'revision'=>$revision+1];
    });
}

function pl_loan_due(int $company,int $book,string $asOf,int $limit,bool $dry=false): array
{
    if($limit<1) { return []; }$asOf=pl_ledger_date($asOf);
    $rows=DB::query("SELECT i.*,l.actor_id FROM pl_loan_instalments i JOIN pl_loan_versions v ON v.id=i.version_id JOIN pl_loans l ON l.id=i.loan_id AND l.current_version=v.version WHERE i.company_id=%i AND i.book_id=%i AND i.due_date<=%s AND NOT EXISTS(SELECT 1 FROM pl_scheduler_jobs q WHERE q.book_id=i.book_id AND q.request_key=CONCAT('loan-payment:',i.id)) ORDER BY i.due_date,i.id LIMIT %i",$company,$book,$asOf,$limit);
    if(!$dry) {
        $candidates=$rows;$rows=[];
        foreach($candidates as $candidate) {
            $current=pl_ledger_transaction(function()use($candidate,$company,$book):?array {
                pl_ledger_book($company,$book,true);
                $r=DB::queryFirstRow('SELECT i.*,l.actor_id FROM pl_loan_instalments i JOIN pl_loan_versions v ON v.id=i.version_id JOIN pl_loans l ON l.id=i.loan_id AND l.current_version=v.version WHERE i.id=%i AND i.company_id=%i AND i.book_id=%i FOR SHARE',$candidate['id'],$company,$book);
                if(!$r) { return null; }
                pl_scheduler_enqueue((int)$r['actor_id'],$company,$book,'loan_payment',(int)$r['id'],$r['due_date'],['instalment_id'=>(int)$r['id']],'loan-payment:'.$r['id']);return $r;
            });
            if($current!==null) { $rows[]=$current; }
        }
    }
    if(count($rows)>=$limit) { return $rows; }
    // End-of-period interest remains a draft; an actual instalment on that day already includes it.
    foreach(DB::query('SELECT l.*,v.id AS version_id,v.annual_rate,v.method,v.effective_date,p.id AS period_id,p.end_date FROM pl_loans l JOIN pl_loan_versions v ON v.loan_id=l.id AND v.version=l.current_version JOIN pl_periods p ON p.company_id=l.company_id AND p.book_id=l.book_id WHERE l.company_id=%i AND l.book_id=%i AND p.end_date<=%s AND p.end_date>=v.effective_date ORDER BY p.end_date,l.id',$company,$book,$asOf) as $r) {
        if(count($rows)>=$limit) { break; }
        $made=pl_ledger_transaction(function()use($r,$company,$book,$dry):?array{
            pl_ledger_book($company,$book,true);
            // Eligibility and rates must come from the current version after serialization.
            $r=DB::queryFirstRow('SELECT l.*,v.id AS version_id,v.annual_rate,v.method,v.effective_date,v.principal AS version_principal,p.id AS period_id,p.end_date FROM pl_loans l JOIN pl_loan_versions v ON v.loan_id=l.id AND v.version=l.current_version JOIN pl_periods p ON p.company_id=l.company_id AND p.book_id=l.book_id WHERE l.id=%i AND l.company_id=%i AND l.book_id=%i AND p.id=%i AND p.end_date>=v.effective_date FOR SHARE',$r['id'],$company,$book,$r['period_id']);
            if(!$r) { return null; }
            if(DB::queryFirstField('SELECT id FROM pl_loan_accruals WHERE loan_id=%i AND period_id=%i AND version_id=%i FOR UPDATE',$r['id'],$r['period_id'],$r['version_id'])!==null) { return null; }
            if($r['method']==='custom') { return null; } // No implied APR/day count for a lender-entered schedule.
            $last=$r['effective_date'];$outstanding=$r['principal'];foreach(pl_loan_paid_rows($company,$book,(int)$r['id'],$r['end_date']) as $paid) {
                // A planned or reversed payment is not interest recognition.
                if($paid['journal_date']===$r['end_date'] && bccomp($paid['interest'],'0',4)>0) { return null; }
                $outstanding=bcsub($outstanding,$paid['principal'],4);if($paid['journal_date']>$last) { $last=$paid['journal_date']; }
            }
            $days=(int)(new DateTimeImmutable($last))->diff(new DateTimeImmutable($r['end_date']))->format('%r%a');$base=$r['method']==='flat'?$r['version_principal']:$outstanding;
            $amount=pl_loan_round(bcdiv(bcmul(bcmul($base,$r['annual_rate'],16),(string)$days,16),'36500',16));
            if($days<1 || bccomp($outstanding,'0',4)<=0 || bccomp($amount,'0',4)<=0) { return null; }
            if($dry) { return ['loan_id'=>(int)$r['id'],'period_id'=>(int)$r['period_id'],'due_date'=>$r['end_date'],'amount'=>$amount]; }
            $reverse=(new DateTimeImmutable($r['end_date']))->modify('+1 day')->format('Y-m-d');
            DB::insert('pl_loan_accruals',['loan_id'=>$r['id'],'version_id'=>$r['version_id'],'company_id'=>$company,'book_id'=>$book,'period_id'=>$r['period_id'],'due_date'=>$r['end_date'],'from_date'=>$last,'reverse_on'=>$reverse,'amount'=>$amount,'days_elapsed'=>$days]);$id=(int)DB::insertId();
            pl_scheduler_enqueue((int)$r['actor_id'],$company,$book,'loan_accrual',$id,$r['end_date'],['accrual_id'=>$id,'reverse_on'=>$reverse],'loan-accrual:'.$id);return ['accrual_id'=>$id];
        });if($made!==null) { $rows[]=$made; }
    }
    return $rows;
}

function pl_loan_job_lines(array $job,array $payload): array
{
    $table=$job['job_kind']==='loan_payment'?'pl_loan_instalments':'pl_loan_accruals';$id=(int)($payload[$job['job_kind']==='loan_payment'?'instalment_id':'accrual_id']??0);
    $r=DB::queryFirstRow('SELECT i.*,l.liability_account_id,l.interest_account_id,l.bank_account_id,l.accrued_account_id,l.funding_journal_id,l.current_version,l.principal AS loan_principal,v.version,v.method,v.annual_rate,v.effective_date,v.principal AS version_principal FROM %b i JOIN pl_loans l ON l.id=i.loan_id JOIN pl_loan_versions v ON v.id=i.version_id WHERE i.id=%i AND i.company_id=%i AND i.book_id=%i FOR SHARE',$table,$id,$job['company_id'],$job['book_id']);
    if(!$r || (int)$r['version']!==(int)$r['current_version']) { throw new DomainException('This draft belongs to a superseded loan schedule. Use the current version.'); }
    if(DB::queryFirstField('SELECT id FROM pl_journals WHERE reversal_of_id=%i FOR SHARE',$r['funding_journal_id'])!==null) { throw new DomainException('The loan funding entry has been reversed.'); }
    $lines=[];
    if($job['job_kind']==='loan_payment') {
        foreach([[$r['liability_account_id'],$r['principal']],[$r['interest_account_id'],$r['interest']]] as [$account,$amount]) { if(bccomp($amount,'0',4)>0) { $lines[]=['account_id'=>(int)$account,'debit'=>$amount,'credit'=>'0','description'=>'Loan instalment']; } }
        $lines[]=['account_id'=>(int)$r['bank_account_id'],'debit'=>'0','credit'=>bcadd($r['principal'],$r['interest'],4),'description'=>'Loan payment'];
    } else {
        pl_loan_accrual_replacement_guard((int)$job['company_id'],(int)$job['book_id'],(int)$r['loan_id'],(int)$r['period_id'],$id);
        if($r['method']!=='custom') {
            $last=$r['effective_date'];$outstanding=$r['loan_principal'];
            foreach(pl_loan_paid_rows((int)$job['company_id'],(int)$job['book_id'],(int)$r['loan_id'],$r['due_date']) as $paid) { $outstanding=bcsub($outstanding,$paid['principal'],4);$last=max($last,$paid['journal_date']); }
            $days=(int)(new DateTimeImmutable($last))->diff(new DateTimeImmutable($r['due_date']))->format('%r%a');$base=$r['method']==='flat'?$r['version_principal']:$outstanding;
            $current=pl_loan_round(bcdiv(bcmul(bcmul($base,$r['annual_rate'],16),(string)$days,16),'36500',16));
            if($last!==$r['from_date'] || bccomp($current,$r['amount'],4)!==0) { throw new DomainException('Payments changed this accrual basis. Reschedule the remaining loan to create a new reviewed accrual version.'); }
        }
        $lines=[['account_id'=>(int)$r['interest_account_id'],'debit'=>$r['amount'],'credit'=>'0','description'=>'Loan interest accrual'],['account_id'=>(int)$r['accrued_account_id'],'debit'=>'0','credit'=>$r['amount'],'description'=>'Accrued loan interest']];
    }
    return $lines;
}

function pl_loan_accrual_replacement_guard(int $company,int $book,int $loan,int $period,int $except=0): void
{
    $posted=DB::queryFirstField("SELECT a.id FROM pl_loan_accruals a JOIN pl_scheduler_jobs q ON q.book_id=a.book_id AND q.request_key=CONCAT('loan-accrual:',a.id) JOIN pl_scheduler_results x ON x.job_id=q.id JOIN pl_effective_general_drafts d ON d.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(x.result,'$.id')) AS UNSIGNED) JOIN pl_journals j ON j.id=d.journal_id LEFT JOIN pl_journals v ON v.reversal_of_id=j.id AND v.journal_date<=a.due_date WHERE a.company_id=%i AND a.book_id=%i AND a.loan_id=%i AND a.period_id=%i AND a.id<>%i AND v.id IS NULL LIMIT 1 FOR SHARE",$company,$book,$loan,$period,$except);
    if($posted!==null) { throw new DomainException('Reverse the earlier accrued interest within its affected period before posting a replacement.'); }
}

function pl_loan_payment_accrual_guard(int $company,int $book,int $instalment): void
{
    $blocked=DB::queryFirstField("SELECT a.id FROM pl_loan_instalments i JOIN pl_loan_accruals a ON a.loan_id=i.loan_id AND a.due_date>=i.due_date JOIN pl_scheduler_jobs q ON q.book_id=a.book_id AND q.request_key=CONCAT('loan-accrual:',a.id) JOIN pl_scheduler_results x ON x.job_id=q.id JOIN pl_effective_general_drafts d ON d.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(x.result,'$.id')) AS UNSIGNED) JOIN pl_journals j ON j.id=d.journal_id LEFT JOIN pl_journals v ON v.reversal_of_id=j.id AND v.journal_date<=a.due_date WHERE i.id=%i AND i.company_id=%i AND i.book_id=%i AND v.id IS NULL LIMIT 1 FOR SHARE",$instalment,$company,$book);
    if($blocked!==null) { throw new DomainException('This backdated payment changes posted accrued interest. Reverse that accrual within its period first, or reschedule the payment into the later period.'); }
}

/** Custom lender accrual is an explicit reviewed amount; no rate is inferred. */
function pl_loan_custom_accrual(int $actor,int $company,int $book,int $loanId,int $periodId,string $amount,string $reason): int
{
    $amount=pl_amount($amount);$reason=pl_ledger_text($reason,'Reason',500);
    if(bccomp($amount,'0',4)<=0) { throw new DomainException('Enter the lender-supported accrued interest amount.'); }
    return pl_ledger_transaction(function()use($actor,$company,$book,$loanId,$periodId,$amount,$reason):int {
        pl_scheduler_require($actor,$company,true,true);pl_ledger_book($company,$book,true);
        $loan=DB::queryFirstRow('SELECT l.id,v.id AS version_id,v.method,v.effective_date FROM pl_loans l JOIN pl_loan_versions v ON v.loan_id=l.id AND v.version=l.current_version WHERE l.id=%i AND l.company_id=%i AND l.book_id=%i FOR SHARE',$loanId,$company,$book);
        $period=DB::queryFirstRow('SELECT start_date,end_date FROM pl_periods WHERE id=%i AND company_id=%i AND book_id=%i FOR SHARE',$periodId,$company,$book);
        if(!$loan || !$period || $loan['method']!=='custom' || $period['end_date']<$loan['effective_date']) { throw new DomainException('Select a current custom loan and a period after its effective date.'); }
        $old=DB::queryFirstRow('SELECT id,amount FROM pl_loan_accruals WHERE loan_id=%i AND period_id=%i AND version_id=%i FOR UPDATE',$loanId,$periodId,$loan['version_id']);
        if($old) { if(bccomp($old['amount'],$amount,4)!==0) { throw new DomainException('This period already has a different immutable accrual.'); }return (int)$old['id']; }
        $from=max($loan['effective_date'],$period['start_date']);$reverse=(new DateTimeImmutable($period['end_date']))->modify('+1 day')->format('Y-m-d');$days=(int)(new DateTimeImmutable($from))->diff(new DateTimeImmutable($period['end_date']))->format('%r%a')+1;
        DB::insert('pl_loan_accruals',['loan_id'=>$loanId,'version_id'=>$loan['version_id'],'company_id'=>$company,'book_id'=>$book,'period_id'=>$periodId,'due_date'=>$period['end_date'],'from_date'=>$from,'reverse_on'=>$reverse,'amount'=>$amount,'days_elapsed'=>$days]);$id=(int)DB::insertId();
        pl_scheduler_enqueue($actor,$company,$book,'loan_accrual',$id,$period['end_date'],['accrual_id'=>$id,'reverse_on'=>$reverse],'loan-accrual:'.$id);
        pl_schedule_audit($actor,$company,$book,'loan',$loanId,'custom_accrual',$reason,null,['accrual_id'=>$id,'period_id'=>$periodId,'amount'=>$amount]);return $id;
    });
}

function pl_loan_generate(array $job,array $payload): array
{
    $lines=pl_loan_job_lines($job,$payload);$input=['date'=>$job['occurrence_date'],'description'=>$job['job_kind']==='loan_payment'?'Scheduled loan instalment':'Period-end loan interest accrual','creation_key'=>'scheduled:'.$job['id']];
    if($job['job_kind']==='loan_payment') { $credit=array_pop($lines);$input['bank_account_id']=$credit['account_id'];$input['debits']=array_map(fn($l)=>['account_id'=>$l['account_id'],'amount'=>$l['debit'],'description'=>$l['description']],$lines);$draft=pl_account_payment_plan((int)$job['actor_id'],(int)$job['company_id'],(int)$job['book_id'],$input); }
    else { $draft=pl_save_general_draft((int)$job['actor_id'],(int)$job['company_id'],(int)$job['book_id'],$input+['lines'=>$lines]); }
    return ['kind'=>'journal','id'=>(int)$draft['id']];
}

function pl_loan_report(int $actor,int $company,int $book,string $asOf,?string $from=null): array
{
    pl_scheduler_require($actor,$company,false,true);pl_ledger_book($company,$book);$asOf=pl_ledger_date($asOf);$cutoff=(new DateTimeImmutable($asOf))->modify('+12 months')->format('Y-m-d');$rows=[];$accounts=[];
    $from=pl_ledger_date($from??substr($asOf,0,4).'-01-01');if($from>$asOf) { throw new DomainException('Interest period start must not follow its end.'); }
    foreach(DB::query('SELECT l.*,p.legal_name AS lender FROM pl_loans l JOIN pl_parties p ON p.id=l.lender_party_id WHERE l.company_id=%i AND l.book_id=%i AND l.start_date<=%s ORDER BY l.id',$company,$book,$asOf) as $loan) {
        $paid='0.0000';$interest='0.0000';$paidIds=[];foreach(pl_loan_paid_rows($company,$book,(int)$loan['id'],$asOf) as $r) { $paid=bcadd($paid,$r['principal'],4);$interest=bcadd($interest,$r['interest'],4);$paidIds[(int)$r['id']]=true; }
        $remaining=bcsub($loan['principal'],$paid,4);$version=DB::queryFirstRow('SELECT * FROM pl_loan_versions WHERE loan_id=%i AND effective_date<=%s ORDER BY version DESC LIMIT 1',$loan['id'],$asOf);$current='0.0000';$schedule=[];
        if($version) { $schedule=DB::query("SELECT i.*,d.id AS draft_id,d.journal_id FROM pl_loan_instalments i LEFT JOIN pl_scheduler_jobs q ON q.book_id=i.book_id AND q.request_key=CONCAT('loan-payment:',i.id) LEFT JOIN pl_scheduler_results x ON x.job_id=q.id LEFT JOIN pl_effective_general_drafts d ON d.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(x.result,'$.id')) AS UNSIGNED) WHERE i.version_id=%i ORDER BY i.due_date",$version['id']);foreach($schedule as $r) { if($r['due_date']<=$cutoff && !isset($paidIds[(int)$r['id']])) { $current=bcadd($current,$r['principal'],4); } } }
        if(bccomp($current,$remaining,4)>0) { $current=$remaining; }
        $loan['principal_paid']=$paid;$loan['interest_paid']=$interest;$loan['outstanding']=$remaining;$loan['current']=$current;$loan['non_current']=bcsub($remaining,$current,4);$loan['schedule']=$schedule;$loan['version']=$version;
        $journals=DB::queryFirstColumn("SELECT DISTINCT d.journal_id FROM pl_scheduler_jobs q JOIN pl_scheduler_results x ON x.job_id=q.id JOIN pl_effective_general_drafts d ON d.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(x.result,'$.id')) AS UNSIGNED) WHERE q.company_id=%i AND q.book_id=%i AND d.journal_id IS NOT NULL AND ((q.job_kind='loan_payment' AND q.source_id IN (SELECT id FROM pl_loan_instalments WHERE loan_id=%i)) OR (q.job_kind='loan_accrual' AND q.source_id IN (SELECT id FROM pl_loan_accruals WHERE loan_id=%i)))",$company,$book,$loan['id'],$loan['id']);
        $loan['interest_period']=$journals===[]?'0.0000':bcadd((string)DB::queryFirstField('SELECT COALESCE(SUM(l.debit-l.credit),0) FROM pl_journal_lines l JOIN pl_journals j ON j.id=l.journal_id WHERE j.company_id=%i AND j.book_id=%i AND l.account_id=%i AND j.journal_date BETWEEN %s AND %s AND (j.id IN %li OR j.reversal_of_id IN %li)',$company,$book,$loan['interest_account_id'],$from,$asOf,$journals,$journals),'0',4);
        $loan['accruals']=DB::query("SELECT a.*,d.id AS draft_id,d.journal_id FROM pl_loan_accruals a LEFT JOIN pl_scheduler_jobs q ON q.book_id=a.book_id AND q.request_key=CONCAT('loan-accrual:',a.id) LEFT JOIN pl_scheduler_results x ON x.job_id=q.id LEFT JOIN pl_effective_general_drafts d ON d.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(x.result,'$.id')) AS UNSIGNED) WHERE a.loan_id=%i ORDER BY a.due_date",$loan['id']);$rows[]=$loan;$id=(int)$loan['liability_account_id'];$accounts[$id]=bcadd($accounts[$id]??'0',$remaining,4);
    }
    $tie=[];foreach($accounts as $id=>$expected) { $a=pl_get_account($actor,$company,$book,$id);$balance=bcsub('0',pl_cash_account_balance($company,$book,$id,$asOf),4);$tie[]=['account_id'=>$id,'code'=>$a['code'],'name'=>$a['name'],'scheduled_balance'=>$expected,'ledger_balance'=>$balance,'difference'=>bcsub($balance,$expected,4)]; }
    return ['from'=>$from,'as_of'=>$asOf,'loans'=>$rows,'accounts'=>$tie];
}
