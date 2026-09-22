<?php
declare(strict_types=1);

/** Internal work uses the existing event/delivery/attempt machine. Job rows are immutable
 * source metadata, not a second queue. No handler may send a payment or post a journal. */
function pl_scheduler_require(int $actorId, int $companyId, bool $write = false, bool $loan = false): void
{
    pl_require_company_access($actorId, $companyId, $write);
    pl_require_capability($actorId, $companyId, ($loan ? 'loans.' : 'schedules.') . ($write ? 'manage' : 'view'), 'Your role cannot access this schedule.');
    if ($write) { pl_demo_require_setup_action(); }
}

function pl_scheduler_enqueue(int $actorId, int $companyId, int $bookId, string $kind, int $sourceId, string $date, array $payload, string $key): int
{
    if (DB::transactionDepth() < 1) { throw new LogicException('Scheduled work must share its source transaction.'); }
    if (!in_array($kind, ['recurring', 'release', 'loan_payment', 'loan_accrual'], true)) { throw new DomainException('Unknown scheduled work.'); }
    pl_scheduler_require($actorId, $companyId, true, str_starts_with($kind, 'loan_'));
    pl_ledger_book($companyId, $bookId, true);
    $date = pl_ledger_date($date); $key = pl_request_key($key);
    $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    $hash = hash('sha256', json_encode([$actorId,$companyId,$bookId,$kind,$sourceId,$date,$payload], JSON_THROW_ON_ERROR));
    $prior = DB::queryFirstRow('SELECT id,payload_hash FROM pl_scheduler_jobs WHERE book_id=%i AND request_key=%s FOR UPDATE', $bookId,$key);
    if ($prior) {
        if (!hash_equals($prior['payload_hash'],$hash)) { throw new DomainException('The occurrence already describes different work.'); }
        return (int) $prior['id'];
    }
    DB::insert('pl_scheduler_jobs', ['company_id'=>$companyId,'book_id'=>$bookId,'actor_id'=>$actorId,'job_kind'=>$kind,'source_id'=>$sourceId,'occurrence_date'=>$date,'request_key'=>$key,'payload'=>$json,'payload_hash'=>$hash]);
    $id=(int)DB::insertId();
    $event=pl_enqueue_delivery_event($companyId,$bookId,'scheduler.draft','scheduled_job',$id,1,'scheduler:'.$id,'internal');
    DB::insert('pl_outbound_deliveries',['event_id'=>$event,'company_id'=>$companyId,'book_id'=>$bookId,'consumer'=>'core.scheduler']);
    return $id;
}

/** Commit draft, immutable receipt and acknowledgement together while the current lease is held. */
function pl_scheduler_execute(array $claim): bool
{
    $event=$claim['event']; $companyId=(int)$event['company_id']; $bookId=(int)$event['book_id'];
    return pl_ledger_transaction(function() use($claim,$event,$companyId,$bookId): bool {
        pl_ledger_book($companyId,$bookId,true);
        $lease=DB::queryFirstRow("SELECT id FROM pl_outbound_deliveries WHERE id=%i AND company_id=%i AND book_id=%i AND consumer='core.scheduler' AND status='leased' AND lease_token=%s AND leased_until>UTC_TIMESTAMP() FOR UPDATE",$claim['id'],$companyId,$bookId,$claim['token']);
        if (!$lease) { return false; }
        $job=DB::queryFirstRow('SELECT * FROM pl_scheduler_jobs WHERE id=%i AND company_id=%i AND book_id=%i FOR UPDATE',(int)$event['payload']['entity_id'],$companyId,$bookId);
        if (!$job) { throw new DomainException('The scheduled source is unavailable.'); }
        $actor=(int)$job['actor_id'];
        pl_scheduler_require($actor,$companyId,true,str_starts_with($job['job_kind'],'loan_'));
        $prior=DB::queryFirstField('SELECT job_id FROM pl_scheduler_results WHERE job_id=%i FOR UPDATE',$job['id']);
        if ($prior === null) {
            $payload=json_decode($job['payload'],true,64,JSON_THROW_ON_ERROR);
            $result=match($job['job_kind']) {
                'recurring'=>pl_recurring_generate($job,$payload),
                'release'=>pl_schedule_generate($job,$payload),
                'loan_payment','loan_accrual'=>pl_loan_generate($job,$payload),
                default=>throw new DomainException('Unknown scheduled handler.'),
            };
            DB::insert('pl_scheduler_results',['job_id'=>$job['id'],'company_id'=>$companyId,'book_id'=>$bookId,'result'=>json_encode($result,JSON_THROW_ON_ERROR)]);
        }
        return pl_outbound_acknowledge((int)$claim['id'],$claim['token'],true);
    });
}

/** Bounded CLI dispatcher, reusable by an explicitly authorized company action. */
function pl_scheduler_dispatch(int $companyId, int $bookId, int $limit = 100): array
{
    if (DB::transactionDepth() !== 0 || $limit<1 || $limit>1000) { throw new LogicException('Run a bounded scheduler batch outside a transaction.'); }
    $result=['claimed'=>0,'succeeded'=>0,'retried'=>0,'dead'=>0,'stale'=>0];
    for($i=0;$i<$limit;$i++) {
        $claim=pl_outbound_claim('core.scheduler',$companyId,$bookId);
        if ($claim === null) { break; }
        $result['claimed']++;
        if(isset($claim['exhausted'])) { $result['dead']++; continue; }
        try { if(pl_scheduler_execute($claim)) { $result['succeeded']++; } else { $result['stale']++; } }
        catch(Throwable $error) {
            if(!pl_outbound_acknowledge((int)$claim['id'],$claim['token'],false)) { $result['stale']++; }
            elseif($claim['attempt']>=8) { $result['dead']++; } else { $result['retried']++; }
        }
    }
    return $result;
}

function pl_scheduler_run(string $asOf, int $limit = 100, ?int $companyId = null, ?int $bookId = null, bool $dryRun = false): array
{
    if (PHP_SAPI !== 'cli') { throw new DomainException('The installation scheduler runs from the command line.'); }
    $asOf=pl_ledger_date($asOf);
    if($limit<1 || $limit>1000) { throw new DomainException('Choose a batch of 1 to 1000 jobs.'); }
    $books=DB::query('SELECT id,company_id FROM pl_books'.($companyId===null?'':' WHERE company_id=%i AND id=%i').' ORDER BY id', ...($companyId===null?[]:[$companyId,$bookId]));
    if(($companyId===null)!==($bookId===null)) { throw new DomainException('Select company and book together.'); }
    $results=[]; $remaining=$limit;$dispatchRemaining=$limit;
    foreach($books as $book) {
        if($remaining<1 && ($dryRun || $dispatchRemaining<1)) { break; }
        $c=(int)$book['company_id'];$b=(int)$book['id'];
        $planned=pl_recurring_due($c,$b,$asOf,$remaining,$dryRun);
        $remaining-=count($planned);
        $release=pl_schedule_due($c,$b,$asOf,$remaining,$dryRun);$remaining-=count($release);
        $loans=pl_loan_due($c,$b,$asOf,$remaining,$dryRun);$remaining-=count($loans);
        $dispatch=$dryRun || $dispatchRemaining<1?null:pl_scheduler_dispatch($c,$b,$dispatchRemaining);
        $dispatchRemaining-=$dispatch['claimed']??0;
        $results[]=['company_id'=>$c,'book_id'=>$b,'planned'=>count($planned)+count($release)+count($loans),'dispatch'=>$dispatch];
    }
    return $results;
}

/** An explicit new delivery preserves every exhausted attempt and the same idempotent job. */
function pl_scheduler_retry(int $actor,int $company,int $book,int $jobId,string $reason): void
{
    pl_ledger_transaction(function()use($actor,$company,$book,$jobId,$reason):void {
        pl_ledger_book($company,$book,true);
        $job=DB::queryFirstRow('SELECT * FROM pl_scheduler_jobs WHERE id=%i AND company_id=%i AND book_id=%i FOR SHARE',$jobId,$company,$book);
        if(!$job) { throw new DomainException('The scheduled job is unavailable.'); }
        pl_scheduler_require($actor,$company,true,str_starts_with($job['job_kind'],'loan_'));
        if(DB::queryFirstField('SELECT job_id FROM pl_scheduler_results WHERE job_id=%i FOR SHARE',$jobId)!==null) { return; }
        $deliveries=DB::query("SELECT d.status FROM pl_outbound_deliveries d JOIN pl_outbound_events e ON e.id=d.event_id WHERE d.company_id=%i AND d.book_id=%i AND d.consumer='core.scheduler' AND e.dispatch_kind='internal' AND CAST(JSON_UNQUOTE(JSON_EXTRACT(e.payload,'$.entity_id')) AS UNSIGNED)=%i FOR UPDATE",$company,$book,$jobId);
        foreach($deliveries as $delivery) { if($delivery['status']!=='dead') { throw new DomainException('This job already has a pending delivery. It will retry automatically after its backoff.'); } }
        $event=pl_enqueue_delivery_event($company,$book,'scheduler.draft','scheduled_job',$jobId,1,'scheduler:'.$jobId.':retry:'.count($deliveries),'internal');
        DB::insert('pl_outbound_deliveries',['event_id'=>$event,'company_id'=>$company,'book_id'=>$book,'consumer'=>'core.scheduler']);
        pl_schedule_audit($actor,$company,$book,'job',$jobId,'retried',pl_ledger_text($reason,'Reason',500),null,['delivery_round'=>count($deliveries)+1]);
    });
}
