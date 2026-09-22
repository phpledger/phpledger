<?php
declare(strict_types=1);

/** No cached negative: the installer may apply 054 in this same process. */
function pl_year_end_available(): bool
{
    return (int) DB::queryFirstField("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='pl_fiscal_years'") === 1;
}

function pl_year_end_require(int $actor, int $company, bool $write = false): void
{
    pl_require_company_access($actor, $company, $write);
    if (!pl_user_can($actor, $company, $write ? 'year_end.manage' : 'cost.view')) {
        throw new DomainException('Your role cannot access this year-end operation.');
    }
    if ($write && pl_demo_enabled() && !pl_demo_provisioning()) { throw new DomainException('Year-end administration is disabled in the public sample.'); }
}

function pl_year_end_get(int $actor, int $company, int $book, int $id): array
{
    pl_year_end_require($actor,$company);
    pl_ledger_book($company,$book);
    $row=DB::queryFirstRow('SELECT * FROM pl_fiscal_years WHERE id=%i AND company_id=%i AND book_id=%i FOR SHARE',$id,$company,$book);
    if (!$row) { throw new DomainException('This fiscal year is not available in the selected book.'); }
    $row['id']=(int)$row['id']; $row['revision']=(int)$row['revision'];
    $row['policy']=json_decode($row['policy'],true,512,JSON_THROW_ON_ERROR);
    return $row;
}

function pl_year_end_list(int $actor, int $company, int $book): array
{
    pl_year_end_require($actor,$company); pl_ledger_book($company,$book);
    return DB::query('SELECT id,start_date,end_date,status,revision FROM pl_fiscal_years WHERE company_id=%i AND book_id=%i ORDER BY end_date DESC FOR SHARE',$company,$book);
}

function pl_year_end_account(int $company, int $book, int $id, bool $drawings = false): array
{
    $a=DB::queryFirstRow('SELECT a.*,b.functional_currency AS book_currency FROM pl_accounts a JOIN pl_books b ON b.id=a.book_id AND b.company_id=a.company_id WHERE a.id=%i AND a.company_id=%i AND a.book_id=%i FOR SHARE',$id,$company,$book);
    if (!$a || $a['type']!=='equity' || (bool)$a['is_contra']!==$drawings || !(bool)$a['is_active'] || !pl_account_is_postable($company,$book,$a['code']) || ($a['currency']!==null && $a['currency']!=='' && $a['currency']!==$a['book_currency'])) {
        throw new DomainException('Choose active, postable functional-currency equity destinations and contra-equity drawings in this book.');
    }
    return $a;
}

/** A business decision, not inferred from legal form or statutory membership interests. */
function pl_year_end_policy(int $company, int $book, array $input): array
{
    $treatment=(string)($input['treatment']??'');
    if (!in_array($treatment,['company','sole_trader','partnership'],true)) { throw new DomainException('Explicitly choose the year-end accounting treatment.'); }
    $note=pl_ledger_text($input['review_note']??null,'Policy review note',1000);
    $legal=pl_ledger_text($input['legal_basis']??null,'Legal form and agreement basis',500);
    $policy=['treatment'=>$treatment,'legal_basis'=>$legal,'review_note'=>$note,'partners'=>[]];
    if ($treatment!=='partnership') {
        $policy['destination_account_id']=(int)($input['destination_account_id']??0);
        pl_year_end_account($company,$book,$policy['destination_account_id']);
        $policy['drawings_account_id']=$treatment==='sole_trader'?(int)($input['drawings_account_id']??0):0;
        if ($policy['drawings_account_id']) { pl_year_end_account($company,$book,$policy['drawings_account_id'],true); }
        if ($treatment==='sole_trader' && !$policy['drawings_account_id']) { throw new DomainException('Choose the owner drawings account explicitly.'); }
        return $policy;
    }
    $policy['capital_method']=(string)($input['capital_method']??'');
    if (!in_array($policy['capital_method'],['fixed','fluctuating'],true)) { throw new DomainException('Choose fixed or fluctuating partner capital explicitly.'); }
    $total='0.000000'; $seen=[];
    foreach (($input['partners']??[]) as $p) {
        $id=(int)($p['partner_id']??0);
        $partner=DB::queryFirstRow('SELECT id,name,capital_account_id,drawings_account_id,is_active FROM pl_owner_partners WHERE id=%i AND company_id=%i AND book_id=%i FOR SHARE',$id,$company,$book);
        if (!$partner || !(bool)$partner['is_active'] || isset($seen['partner'.$id])) { throw new DomainException('Select each active partner once in this book.'); }
        $ratio=(string)($p['ratio']??'');
        if (!preg_match('/^(?:0(?:\.[0-9]{1,6})?|1(?:\.0{1,6})?)$/D',$ratio)) { throw new DomainException('Partner ratios must be exact fractions from zero to one, up to six decimal places.'); }
        $capital=(int)$partner['capital_account_id']; $drawings=(int)$partner['drawings_account_id'];
        $destination=$policy['capital_method']==='fixed'?(int)($p['current_account_id']??0):$capital;
        foreach ([$capital,$drawings,$destination] as $accountId) {
            if (isset($seen['account'.$accountId]) || ($policy['capital_method']==='fixed' && $destination===$capital)) { throw new DomainException('Partners must have separate capital, drawings and destination accounts.'); }
        }
        pl_year_end_account($company,$book,$capital); pl_year_end_account($company,$book,$drawings,true); pl_year_end_account($company,$book,$destination);
        foreach ([$capital,$drawings,$destination] as $accountId) { $seen['account'.$accountId]=true; }
        $seen['partner'.$id]=true; $total=bcadd($total,$ratio,6);
        $policy['partners'][]=['partner_id'=>$id,'name'=>$partner['name'],'ratio'=>bcadd($ratio,'0',6),'capital_account_id'=>$capital,'drawings_account_id'=>$drawings,'destination_account_id'=>$destination];
    }
    if ($policy['partners']===[] || bccomp($total,'1',6)!==0) { throw new DomainException('Reviewed partner ratios must sum to exactly one.'); }
    usort($policy['partners'],static fn(array $a,array $b):int=>$a['partner_id']<=>$b['partner_id']);
    return $policy;
}

function pl_year_end_receipt(int $company,int $book,string $key,string $hash): ?array
{
    $r=DB::queryFirstRow('SELECT request_hash,payload FROM pl_year_end_actions WHERE company_id=%i AND book_id=%i AND request_key=%s FOR UPDATE',$company,$book,$key);
    if (!$r) { return null; }
    if (!hash_equals($r['request_hash'],$hash)) { throw new DomainException('This year-end request key belongs to different input.'); }
    return json_decode($r['payload'],true,512,JSON_THROW_ON_ERROR);
}

function pl_year_end_record(int $actor,int $company,int $book,int $year,string $action,string $key,string $hash,array $payload): array
{
    DB::insert('pl_year_end_actions',['company_id'=>$company,'book_id'=>$book,'year_id'=>$year,'action'=>$action,'request_key'=>$key,'request_hash'=>$hash,'payload'=>json_encode($payload,JSON_THROW_ON_ERROR),'actor_id'=>$actor]);
    return $payload;
}

function pl_year_end_key(string $key): string
{
    $key=pl_period_request_key($key);if(strlen($key)>100){throw new DomainException('Year-end request keys must not exceed 100 characters.');}return $key;
}

function pl_year_end_hash(mixed $value): string { return hash('sha256',json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)); }

/** Date boundaries must match the company's fiscal calendar and exactly cover periods. */
function pl_year_end_create(int $actor,int $company,int $book,string $end,array $policy,string $key): array
{
    $end=pl_ledger_date($end); $key=pl_year_end_key($key); $hash=pl_year_end_hash([$actor,$end,$policy]);
    return pl_ledger_transaction(function()use($actor,$company,$book,$end,$policy,$key,$hash):array {
        pl_year_end_require($actor,$company,true); pl_ledger_book($company,$book,true);
        if ($r=pl_year_end_receipt($company,$book,$key,$hash)) { return $r; }
        $c=DB::queryFirstRow('SELECT start_date,fiscal_year_end FROM pl_companies WHERE id=%i FOR SHARE',$company);
        $profile=pl_company_profile($actor,$company);
        $configured=($profile['financial_year_end_month']??null)===null?null:sprintf('%02d-%02d',$profile['financial_year_end_month'],$profile['financial_year_end_day']);
        if (substr($end,5)!==$c['fiscal_year_end'] || ($configured!==null && $configured!==$c['fiscal_year_end'])) { throw new DomainException('Resolve the company fiscal calendar before creating this year.'); }
        $start=(new DateTimeImmutable($end))->modify('-1 year')->modify('+1 day')->format('Y-m-d');
        $start=max($start,$c['start_date']);
        if ($start>$end || DB::queryFirstField('SELECT id FROM pl_fiscal_years WHERE book_id=%i AND start_date<=%s AND end_date>=%s FOR UPDATE',$book,$end,$start)) { throw new DomainException('This fiscal year overlaps an existing year or precedes company start.'); }
        $normalized=pl_year_end_policy($company,$book,$policy);
        DB::insert('pl_fiscal_years',['company_id'=>$company,'book_id'=>$book,'start_date'=>$start,'end_date'=>$end,'policy'=>json_encode($normalized,JSON_THROW_ON_ERROR),'created_by'=>$actor]);
        $id=(int)DB::insertId();
        return pl_year_end_record($actor,$company,$book,$id,'create',$key,$hash,['year_id'=>$id,'status'=>'open','revision'=>1,'policy'=>$normalized]);
    });
}

/** Stable largest-remainder split in 0.0001 units; losses mirror gains. */
function pl_year_end_allocate(string $profit,array $partners): array
{
    $negative=bccomp($profit,'0',4)<0; $absolute=$negative?bcsub('0',$profit,4):$profit;
    $units=bcmul($absolute,'10000',0); $used='0'; $rows=[];
    foreach($partners as $p) {
        $exact=bcmul($units,$p['ratio'],6); $whole=bcadd($exact,'0',0); $used=bcadd($used,$whole,0);
        $rows[]=$p+['units'=>$whole,'remainder'=>bcsub($exact,$whole,6)];
    }
    usort($rows,static function(array $a,array $b):int { $c=bccomp($b['remainder'],$a['remainder'],6); return $c?:($a['partner_id']<=>$b['partner_id']); });
    $remaining=(int)bcsub($units,$used,0);
    foreach($rows as $i=>&$row) { if($i<$remaining) { $row['units']=bcadd($row['units'],'1',0); } $row['amount']=bcdiv($row['units'],'10000',4); if($negative){$row['amount']=bcsub('0',$row['amount'],4);} unset($row['units'],$row['remainder']); } unset($row);
    usort($rows,static fn(array $a,array $b):int=>$a['partner_id']<=>$b['partner_id']); return $rows;
}

/** Current locking reads avoid mixed snapshots when called within a caller transaction. */
function pl_year_end_balances(int $company,int $book,string $end,string $mode='full',?string $from=null): array
{
    $accounts=DB::query('SELECT id,code,name,type,is_contra FROM pl_accounts WHERE company_id=%i AND book_id=%i ORDER BY id FOR SHARE',$company,$book);
    $lines=DB::query('SELECT l.account_id,l.debit,l.credit,j.journal_date,j.source_type,o.source_type AS original_source FROM pl_journal_lines l JOIN pl_journals j ON j.id=l.journal_id LEFT JOIN pl_journals o ON o.id=j.reversal_of_id WHERE j.company_id=%i AND j.book_id=%i AND j.journal_date<=%s ORDER BY j.id,l.line_number FOR SHARE',$company,$book,$end);
    $sums=[];
    foreach($lines as $l){ if($mode!=='full' && ($from===null||$l['journal_date']>=$from) && ($l['source_type']==='year_end_close'||$l['original_source']==='year_end_close')){continue;} if($mode==='unadjusted'&&($from===null||$l['journal_date']>=$from)&&($l['source_type']==='year_end_adjustment'||$l['original_source']==='year_end_adjustment')){continue;} $id=(int)$l['account_id'];$sums[$id]=bcadd($sums[$id]??'0',bcsub($l['debit'],$l['credit'],4),4); }
    foreach($accounts as &$a){$a['id']=(int)$a['id'];$a['balance']=$sums[$a['id']]??'0.0000';} unset($a); return $accounts;
}

function pl_year_end_periods(array $year): array
{
    $periods=DB::query('SELECT id,start_date,end_date,status,revision FROM pl_periods WHERE company_id=%i AND book_id=%i AND start_date<=%s AND end_date>=%s ORDER BY start_date FOR SHARE',$year['company_id'],$year['book_id'],$year['end_date'],$year['start_date']);
    $next=$year['start_date'];
    foreach($periods as $p){if($p['start_date']!==$next||$p['end_date']>$year['end_date']){throw new DomainException('Periods must cover this fiscal year exactly, without gaps or straddling boundaries.');}$next=(new DateTimeImmutable($p['end_date']))->modify('+1 day')->format('Y-m-d');}
    if($periods===[]||$next!==(new DateTimeImmutable($year['end_date']))->modify('+1 day')->format('Y-m-d')){throw new DomainException('Create periods covering the complete fiscal year first.');}
    return $periods;
}

/** Existing depreciation arithmetic with current reads for every mutable input. */
function pl_year_end_depreciation(int $actor,int $company,int $book,array $periods): array
{
    $assets=DB::query('SELECT * FROM pl_assets WHERE company_id=%i AND book_id=%i ORDER BY id FOR SHARE',$company,$book);
    $allPeriods=DB::query('SELECT id,start_date,end_date,status FROM pl_periods WHERE company_id=%i AND book_id=%i ORDER BY start_date FOR SHARE',$company,$book);
    $events=DB::query("SELECT asset_id,period_id,depreciation_amount FROM pl_asset_events WHERE company_id=%i AND book_id=%i AND kind='depreciation' FOR SHARE",$company,$book);
    $posted=[];foreach($events as $e){$key=$e['asset_id'].':'.$e['period_id'];$posted[$key]=bcadd($posted[$key]??'0',$e['depreciation_amount'],4);}
    $ids=array_map('intval',array_column($periods,'id'));$outstanding=[];
    foreach($assets as $raw){$asset=pl_asset_row($raw);$class=pl_get_asset_class($actor,$company,$book,(int)$asset['class_id']);foreach(pl_asset_schedule(pl_asset_plan($asset,$class),$allPeriods) as $s){if(in_array((int)$s['period_id'],$ids,true)&&bccomp($s['charge'],$posted[$asset['id'].':'.$s['period_id']]??'0',4)!==0){$outstanding[]='Review outstanding depreciation for asset '.$asset['id'].' in period '.$s['period_id'];}}}
    return $outstanding;
}

function pl_year_end_preview(int $actor,int $company,int $book,int $id): array
{
    return pl_ledger_transaction(function()use($actor,$company,$book,$id):array{
        $year=pl_year_end_get($actor,$company,$book,$id);$periods=pl_year_end_periods($year);$policy=$year['policy'];
        $balances=pl_year_end_balances($company,$book,$year['end_date']);$byId=[];$profit='0.0000';$movements=[];$blockers=[];
        foreach($balances as $a){$byId[$a['id']]=$a;if(in_array($a['type'],['income','expense'],true)){$profit=bcsub($profit,$a['balance'],4);$movements[$a['id']]=bcsub('0',$a['balance'],4);}}
        $allocations=$policy['treatment']==='partnership'?pl_year_end_allocate($profit,$policy['partners']):[['destination_account_id'=>$policy['destination_account_id'],'drawings_account_id'=>$policy['drawings_account_id'],'amount'=>$profit]];
        $covered=[];
        foreach($allocations as &$a){$destination=(int)$a['destination_account_id'];pl_year_end_account($company,$book,$destination);$drawings=(int)$a['drawings_account_id'];$amount=$drawings?($byId[$drawings]['balance']??'0.0000'):'0.0000';if($drawings){pl_year_end_account($company,$book,$drawings,true);$covered[$drawings]=true;$movements[$drawings]=bcsub('0',$amount,4);}$a['drawings']=$amount;$a['net_credit']=bcsub($a['amount'],$amount,4);$movements[$destination]=bcsub($movements[$destination]??'0',$a['net_credit'],4);}unset($a);
        foreach($balances as $a){if($a['type']==='equity'&&(bool)$a['is_contra']&&!isset($covered[$a['id']])&&bccomp($a['balance'],'0',4)!==0){$blockers[]='Unassigned drawings balance: '.$a['code'];}}
        $lines=[];ksort($movements);foreach($movements as $account=>$amount){if(bccomp($amount,'0',4)===0){continue;}$lines[]=['account_id'=>$account,'debit'=>bccomp($amount,'0',4)>0?$amount:'0.0000','credit'=>bccomp($amount,'0',4)<0?bcsub('0',$amount,4):'0.0000','description'=>'Year-end closing'];}
        foreach($periods as $index=>$p){if($index<count($periods)-1&&$p['status']!=='closed'){$blockers[]='Close earlier period ending '.$p['end_date'];}$check=pl_period_checklist_build($actor,$company,$book,(int)$p['id'],false);foreach($check['blocking'] as $item){$blockers[]=$p['end_date'].': '.$item['label'];}}
        if(DB::queryFirstField("SELECT id FROM pl_fiscal_years WHERE company_id=%i AND book_id=%i AND end_date<%s AND status NOT IN ('closed','locked') FOR SHARE",$company,$book,$year['start_date'])){$blockers[]='Close earlier fiscal years first.';}
        $companyStart=(string)DB::queryFirstField('SELECT start_date FROM pl_companies WHERE id=%i FOR SHARE',$company);
        if($year['start_date']>$companyStart&&!DB::queryFirstField("SELECT id FROM pl_fiscal_years WHERE book_id=%i AND end_date=%s AND status IN ('closed','locked') FOR SHARE",$book,(new DateTimeImmutable($year['start_date']))->modify('-1 day')->format('Y-m-d'))){$blockers[]='Register and close the preceding fiscal year first.';}
        $unreleased=function_exists('pl_schedule_unreleased')?pl_schedule_unreleased($company,$book,$year['end_date']):[];
        if($unreleased!==[]){$blockers[]='Post all due schedule releases.';}
        $blockers=array_merge($blockers,pl_year_end_depreciation($actor,$company,$book,$periods));
        $journalIds=DB::queryFirstColumn('SELECT id FROM pl_journals WHERE company_id=%i AND book_id=%i AND journal_date<=%s ORDER BY id FOR SHARE',$company,$book,$year['end_date']);
        $basis=['journal_ids'=>$journalIds,'year'=>$year,'periods'=>$periods,'balances'=>$balances,'allocations'=>$allocations,'lines'=>$lines,'blockers'=>array_values(array_unique($blockers)),'unreleased'=>$unreleased];
        return $basis+['profit'=>$profit,'fingerprint'=>pl_year_end_hash($basis),'ready'=>$blockers===[]];
    });
}

/** Intent is an immutable, transaction-local authorization for one exact central posting. */
function pl_year_end_intent(int $actor,int $company,int $book,int $year,string $kind,array $payload,?int $original=null): void
{
    DB::insert('pl_year_end_posting_intents',['company_id'=>$company,'book_id'=>$book,'year_id'=>$year,'request_key'=>$payload['idempotency_key'],'payload_hash'=>pl_year_end_hash(pl_normalize_journal($payload)),'kind'=>$kind,'reversal_of_id'=>$original,'actor_id'=>$actor]);
}

function pl_year_end_assert_posting(int $actor,int $company,int $book,array $payload,?int $original): void
{
    if(!pl_year_end_available()){return;}
    $year=DB::queryFirstRow('SELECT id,status,end_date FROM pl_fiscal_years WHERE company_id=%i AND book_id=%i AND start_date<=%s AND end_date>=%s FOR SHARE',$company,$book,$payload['date'],$payload['date']);
    $originalSource=$original===null?null:DB::queryFirstField('SELECT source_type FROM pl_journals WHERE id=%i AND company_id=%i AND book_id=%i FOR SHARE',$original,$company,$book);
    $reserved=in_array($payload['source_type'],['year_end_close','year_end_adjustment'],true)||$originalSource==='year_end_close';
    if(!$reserved&&(!$year||$year['status']==='open')){return;}
    if(!$year||$year['status']!=='closing'||$payload['date']!==$year['end_date']){throw new DomainException('This fiscal year is closed or requires the final-day year-end adjustment workflow.');}
    $intent=DB::queryFirstRow('SELECT * FROM pl_year_end_posting_intents WHERE company_id=%i AND book_id=%i AND year_id=%i AND request_key=%s FOR SHARE',$company,$book,$year['id'],$payload['idempotency_key']);
    if(!$intent||(int)$intent['actor_id']!==$actor||($intent['reversal_of_id']===null?null:(int)$intent['reversal_of_id'])!==$original||!hash_equals($intent['payload_hash'],pl_year_end_hash($payload))){throw new DomainException('Use the reviewed year-end service for this posting or reversal.');}
}

function pl_year_end_assert_period(int $company,int $book,array $period): void
{
    if(pl_year_end_available()&&DB::queryFirstField("SELECT id FROM pl_fiscal_years WHERE company_id=%i AND book_id=%i AND start_date<=%s AND end_date>=%s AND status IN ('closed','locked') FOR SHARE",$company,$book,$period['end_date'],$period['start_date'])){throw new DomainException('Reopen the fiscal year before reopening its periods.');}
}

function pl_year_end_change(int $actor,int $company,int $book,int $id,string $action,int $revision,string $note,string $key,array $input=[]): array
{
    if(!in_array($action,['policy','start','prepare','close','reopen','lock'],true)){throw new DomainException('Choose a valid year-end action.');}
    $key=pl_year_end_key($key);$note=pl_ledger_text($note,'Review or correction note',1000);$hash=pl_year_end_hash([$actor,$id,$action,$revision,$note,$input]);
    // Package computations run outside the book lock. Core checks are repeated inside it.
    $packageChecks=[];
    if($action==='close'){
        $replay=pl_ledger_transaction(function()use($actor,$company,$book,$key,$hash):?array{pl_year_end_require($actor,$company,true);pl_ledger_book($company,$book,true);return pl_year_end_receipt($company,$book,$key,$hash);});
        if($replay!==null){return $replay;}
        $y=pl_year_end_get($actor,$company,$book,$id);
        foreach(pl_year_end_periods($y) as $p){$packageChecks[]=pl_period_checklist($actor,$company,$book,(int)$p['id']);}
    }
    return pl_ledger_transaction(function()use($actor,$company,$book,$id,$action,$revision,$note,$key,$input,$hash,$packageChecks):array{
        pl_year_end_require($actor,$company,true);$bookRow=pl_ledger_book($company,$book,true);
        if($r=pl_year_end_receipt($company,$book,$key,$hash)){return $r;}
        $year=pl_year_end_get($actor,$company,$book,$id);
        if($year['revision']!==$revision){throw new DomainException('This fiscal year changed. Review the current version.');}
        $newStatus=$year['status'];$journal=null;$preview=null;$policy=$year['policy'];
        if($action==='policy'){
            if(!in_array($year['status'],['open','closing'],true)){throw new DomainException('Reopen the year before revising its policy.');}
            $policy=pl_year_end_policy($company,$book,$input);
        }elseif($action==='prepare'){
            if($year['status']!=='closing'){throw new DomainException('Only a year in closing can return to preparation.');}$newStatus='open';
        }elseif($action==='lock'){
            if($year['status']!=='closed'){throw new DomainException('Only a closed year can be locked.');}$newStatus='locked';
        }elseif($action==='start'){
            if($year['status']!=='open'){throw new DomainException('This year is not open.');}
            $initial=pl_year_end_preview($actor,$company,$book,$id);if(!$initial['ready']){throw new DomainException(implode(' ',$initial['blockers']));}
            $periods=pl_year_end_periods($year);foreach(array_slice($periods,0,-1) as $p){if($p['status']!=='closed'){throw new DomainException('Close earlier periods first.');}}
            $final=$periods[count($periods)-1];
            if($final['status']==='closed'){pl_change_period_status($actor,$company,$book,(int)$final['id'],'open',(int)$final['revision'],$note,'ye-start-'.substr(hash('sha256',$key),0,40));}
            $newStatus='closing';
        }elseif($action==='close'){
            if($year['status']!=='closing'){throw new DomainException('Start year-end closing before reviewing the final preview.');}
            $preview=pl_year_end_preview($actor,$company,$book,$id);
            if(!hash_equals($preview['fingerprint'],(string)($input['fingerprint']??''))){throw new DomainException('The reviewed preview changed. Reload and review before closing.');}
            if(!$preview['ready']){throw new DomainException(implode(' ',$preview['blockers']));}
            foreach($packageChecks as $check){if(!$check['ready']){throw new DomainException(pl_period_close_refusal($check['blocking']));}}
            foreach(['stock','provisions','adjustments','payroll'] as $attestation){if(($input[$attestation]??false)!==true){throw new DomainException('Confirm stock valuation, provisions, adjusting journals and payroll review (or explain not applicable in the review note).');}}
            $final=$preview['periods'][count($preview['periods'])-1];
            if($final['status']!=='open'){throw new DomainException('The final period must be open for the closing journal.');}
            if($preview['lines']!==[]){$payload=pl_normalize_journal(['date'=>$year['end_date'],'currency'=>$bookRow['currency'],'source_type'=>'year_end_close','source_reference'=>(string)$id.':'.$revision,'description'=>'Year-end close '.$year['end_date'],'idempotency_key'=>'ye-close-'.substr(hash('sha256',$key),0,48),'lines'=>$preview['lines']]);pl_year_end_intent($actor,$company,$book,$id,'close',$payload);$journal=pl_post_journal($actor,$company,$book,$payload);}
            // Reuse the audited period action without invoking package hooks inside a lock.
            DB::update('pl_periods',['status'=>'closed','revision'=>(int)$final['revision']+1],'id=%i',$final['id']);
            $final['status']='closed';$final['revision']=(int)$final['revision']+1;
            pl_period_record_action($actor,$company,$book,$final,'close','open',$note,'ye-period-'.substr(hash('sha256',$key),0,40),$hash,['year_id'=>$id]);
            pl_hook_after_commit('period.closed',[$final,['company_id'=>$company,'book_id'=>$book,'actor_id'=>$actor,'checklist'=>end($packageChecks)['items']??[]]]);
            $newStatus='closed';
        }else{
            if(!in_array($year['status'],['closed','locked'],true)){throw new DomainException('Only a closed or locked fiscal year can reopen.');}
            if(!pl_user_can($actor,$company,'periods.reopen')||!pl_user_can($actor,$company,'journal.reverse_backdated')){throw new DomainException('Year reopen requires period reopen and backdated reversal authority.');}
            if(DB::queryFirstField("SELECT id FROM pl_fiscal_years WHERE book_id=%i AND end_date>%s AND status IN ('closed','locked') FOR SHARE",$book,$year['end_date'])){throw new DomainException('Reopen later closed fiscal years first.');}
            $last=DB::queryFirstField("SELECT payload FROM pl_year_end_actions WHERE year_id=%i AND action='close' ORDER BY id DESC LIMIT 1 FOR SHARE",$id);
            $receipt=json_decode($last,true,512,JSON_THROW_ON_ERROR);
            // The same book lock and transaction protect this temporary open state.
            // Period reopening must run its due ordinary reversals before the
            // final-adjustment-only closing guard applies again.
            DB::update('pl_fiscal_years',['status'=>'open'],'id=%i',$id);
            $periods=pl_year_end_periods($year);$final=$periods[count($periods)-1];
            if($final['status']==='closed'){pl_change_period_status($actor,$company,$book,(int)$final['id'],'open',(int)$final['revision'],$note,'ye-reopen-period-'.substr(hash('sha256',$key),0,32));}
            DB::update('pl_fiscal_years',['status'=>'closing'],'id=%i',$id);
            if($receipt['journal_id']!==null){
                $original=pl_get_journal($actor,$company,$book,(int)$receipt['journal_id']);$lines=[];
                foreach($original['lines'] as $line){$lines[]=['account_id'=>(int)$line['account_id'],'debit'=>(string)$line['credit'],'credit'=>(string)$line['debit'],'description'=>(string)$line['description']]+pl_currency_line_normalize($line,$original['currency'],$line['credit'],$line['debit']);}
                $reason=substr($note,0,400);$payload=pl_normalize_journal(['date'=>$year['end_date'],'currency'=>$bookRow['currency'],'source_type'=>'reversal','source_reference'=>(string)$original['id'],'description'=>'Reversal of '.$original['reference'].': '.$reason,'idempotency_key'=>'ye-reopen-'.substr(hash('sha256',$key),0,48),'lines'=>$lines]);
                pl_year_end_intent($actor,$company,$book,$id,'reopen',$payload,(int)$original['id']);$journal=pl_reverse_journal($actor,$company,$book,(int)$original['id'],$year['end_date'],$payload['idempotency_key'],$reason);
            }$newStatus='closing';
        }
        DB::update('pl_fiscal_years',['status'=>$newStatus,'revision'=>$revision+1,'policy'=>json_encode($policy,JSON_THROW_ON_ERROR)],'id=%i',$id);
        return pl_year_end_record($actor,$company,$book,$id,$action,$key,$hash,['year_id'=>$id,'status'=>$newStatus,'revision'=>$revision+1,'journal_id'=>$journal['id']??null,'policy'=>$policy,'review_note'=>$note,'preview'=>$preview,'attestations'=>$action==='close'?$input:[]]);
    });
}

function pl_year_end_adjust(int $actor,int $company,int $book,int $id,array $input,string $key): array
{
    $key=pl_year_end_key($key);$hash=pl_year_end_hash([$actor,$id,$input]);
    return pl_ledger_transaction(function()use($actor,$company,$book,$id,$input,$key,$hash):array{
        pl_year_end_require($actor,$company,true);$b=pl_ledger_book($company,$book,true);if($r=pl_year_end_receipt($company,$book,$key,$hash)){return $r;}
        $year=pl_year_end_get($actor,$company,$book,$id);if($year['status']!=='closing'){throw new DomainException('Year-end adjustments require a year in closing.');}
        $payload=pl_normalize_journal(['date'=>$year['end_date'],'currency'=>$b['currency'],'source_type'=>'year_end_adjustment','source_reference'=>(string)$id,'description'=>$input['description']??'','lines'=>$input['lines']??[],'idempotency_key'=>'ye-adjust-'.substr(hash('sha256',$key),0,48)]);
        pl_year_end_intent($actor,$company,$book,$id,'adjust',$payload);$journal=pl_post_journal($actor,$company,$book,$payload);
        return pl_year_end_record($actor,$company,$book,$id,'adjust',$key,$hash,['year_id'=>$id,'journal_id'=>$journal['id'],'description'=>$payload['description']]);
    });
}

function pl_year_end_reverse_adjustment(int $actor,int $company,int $book,int $id,int $journalId,string $reason,string $key): array
{
    $key=pl_year_end_key($key);$reason=pl_ledger_text($reason,'Adjustment correction reason',400);$hash=pl_year_end_hash([$actor,$id,$journalId,$reason]);
    return pl_ledger_transaction(function()use($actor,$company,$book,$id,$journalId,$reason,$key,$hash):array{
        pl_year_end_require($actor,$company,true);$b=pl_ledger_book($company,$book,true);if($r=pl_year_end_receipt($company,$book,$key,$hash)){return $r;}
        $year=pl_year_end_get($actor,$company,$book,$id);$original=pl_get_journal($actor,$company,$book,$journalId);
        if($year['status']!=='closing'||$original['source_type']!=='year_end_adjustment'||$original['source_reference']!==(string)$id){throw new DomainException('Choose an adjustment belonging to this year while it is in closing.');}
        $lines=[];foreach($original['lines'] as $line){$lines[]=['account_id'=>(int)$line['account_id'],'debit'=>(string)$line['credit'],'credit'=>(string)$line['debit'],'description'=>(string)$line['description']]+pl_currency_line_normalize($line,$original['currency'],$line['credit'],$line['debit']);}
        $payload=pl_normalize_journal(['date'=>$year['end_date'],'currency'=>$b['currency'],'source_type'=>'reversal','source_reference'=>(string)$journalId,'description'=>'Reversal of '.$original['reference'].': '.$reason,'idempotency_key'=>'ye-correct-'.substr(hash('sha256',$key),0,48),'lines'=>$lines]);
        pl_year_end_intent($actor,$company,$book,$id,'adjust',$payload,$journalId);$journal=pl_reverse_journal($actor,$company,$book,$journalId,$year['end_date'],$payload['idempotency_key'],$reason);
        return pl_year_end_record($actor,$company,$book,$id,'adjust',$key,$hash,['year_id'=>$id,'journal_id'=>$journal['id'],'reversal_of_id'=>$journalId,'review_note'=>$reason]);
    });
}

/** Read DTO excludes actor IDs, request keys and internal intent hashes. */
function pl_year_end_report(int $actor,int $company,int $book,int $id): array
{
    $year=pl_year_end_get($actor,$company,$book,$id);$history=DB::query('SELECT a.action,a.created_at,a.payload,u.display_name FROM pl_year_end_actions a JOIN pl_users u ON u.id=a.actor_id WHERE a.year_id=%i AND a.company_id=%i AND a.book_id=%i ORDER BY a.id FOR SHARE',$id,$company,$book);
    foreach($history as &$h){$p=json_decode($h['payload'],true,512,JSON_THROW_ON_ERROR);$h['journal_id']=$p['journal_id']??null;$h['policy']=$p['policy']??null;$h['allocations']=$p['preview']['allocations']??[];$h['review_note']=$p['review_note']??($p['policy']['review_note']??'');unset($h['payload']);}unset($h);
    $prior=DB::queryFirstRow('SELECT start_date,end_date FROM pl_fiscal_years WHERE company_id=%i AND book_id=%i AND end_date<%s ORDER BY end_date DESC LIMIT 1 FOR SHARE',$company,$book,$year['start_date']);
    return ['year'=>array_intersect_key($year,array_flip(['id','start_date','end_date','status','revision','policy'])),'history'=>$history,'unadjusted_trial_balance'=>pl_year_end_balances($company,$book,$year['end_date'],'unadjusted',$year['start_date']),'adjusted_trial_balance'=>pl_year_end_balances($company,$book,$year['end_date'],'adjusted',$year['start_date']),'profit_loss'=>pl_profit_loss($actor,$company,$book,$year['start_date'],$year['end_date']),'balance_sheet'=>pl_balance_sheet($actor,$company,$book,$year['end_date']),'comparative'=>$prior?['profit_loss'=>pl_profit_loss($actor,$company,$book,$prior['start_date'],$prior['end_date']),'balance_sheet'=>pl_balance_sheet($actor,$company,$book,$prior['end_date'])]:null];
}
