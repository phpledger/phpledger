<?php
declare(strict_types=1);

/** Internal transactional outbox: callers have already authorized their source mutation. */
function pl_enqueue_outbound_event(int $companyId,int $bookId,string $type,string $entityType,int $entityId,int $revision,string $key): int
{
    return pl_enqueue_delivery_event($companyId,$bookId,$type,$entityType,$entityId,$revision,$key,'outbound');
}

/** One event writer for outbound delivery and internal draft jobs; never duplicate the queue. */
function pl_enqueue_delivery_event(int $companyId,int $bookId,string $type,string $entityType,int $entityId,int $revision,string $key,string $dispatchKind): int
{
    if (!in_array($dispatchKind,['outbound','internal'],true)) { throw new DomainException('Unknown delivery purpose.'); }
    if (DB::transactionDepth()<1) { throw new LogicException('Enqueue must share the source transaction.'); }
    pl_ledger_book($companyId,$bookId);
    if (!preg_match('/^[a-z][a-z0-9_.]{0,79}$/D',$type) || !preg_match('/^[a-z][a-z0-9_]{0,39}$/D',$entityType) || $entityId<1 || $revision<1) { throw new DomainException('Invalid outbound event identity.'); }
    $key=pl_request_key($key);
    // No contact names, bank accounts, tax identities or arbitrary private payload copies.
    $payload=json_encode(['entity_type'=>$entityType,'entity_id'=>$entityId,'revision'=>$revision],JSON_THROW_ON_ERROR);
    $hash=hash('sha256',json_encode([$companyId,$bookId,$type,1,$payload],JSON_THROW_ON_ERROR));
    $prior=DB::queryFirstRow('SELECT id,payload_hash,dispatch_kind FROM pl_outbound_events WHERE book_id=%i AND event_key=%s FOR UPDATE',$bookId,$key);
    if($prior){if($prior['dispatch_kind']!==$dispatchKind || !hash_equals($prior['payload_hash'],$hash)){throw new DomainException('Outbound event key already describes different content.');}return (int)$prior['id'];}
    DB::insert('pl_outbound_events',['company_id'=>$companyId,'book_id'=>$bookId,'event_type'=>$type,'event_key'=>$key,'dispatch_kind'=>$dispatchKind,'payload'=>$payload,'payload_hash'=>$hash]);
    return (int)DB::insertId();
}

/** Claim one delivery. DB transaction ends before any handler is invoked. */
function pl_outbound_claim(string $consumer,int $companyId,int $bookId): ?array
{
    return pl_ledger_transaction(function()use($consumer,$companyId,$bookId):?array{
        $delivery=DB::queryFirstRow("SELECT * FROM pl_outbound_deliveries WHERE consumer=%s AND company_id=%i AND book_id=%i AND ((status='ready' AND available_at<=UTC_TIMESTAMP()) OR (status='leased' AND leased_until<=UTC_TIMESTAMP())) ORDER BY available_at,id LIMIT 1 FOR UPDATE SKIP LOCKED",$consumer,$companyId,$bookId);
        if(!$delivery){return null;}
        if($delivery['status']==='leased'){
            DB::insert('pl_outbound_attempts',['delivery_id'=>$delivery['id'],'attempt_number'=>$delivery['attempts'],'outcome'=>'lease_expired']);
        }
        if((int)$delivery['attempts']>=8){
            DB::update('pl_outbound_deliveries',['status'=>'dead','lease_token'=>null,'leased_until'=>null],'id=%i',$delivery['id']);
            return ['exhausted'=>true];
        }
        $token=bin2hex(random_bytes(16));$attempt=(int)$delivery['attempts']+1;
        DB::query("UPDATE pl_outbound_deliveries SET status='leased', attempts=%i, lease_token=%s, leased_until=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 120 SECOND) WHERE id=%i",$attempt,$token,$delivery['id']);
        $event=DB::queryFirstRow('SELECT id,company_id,book_id,event_type,event_version,event_key,payload,created_at FROM pl_outbound_events WHERE id=%i AND company_id=%i AND book_id=%i',$delivery['event_id'],$companyId,$bookId);
        $event['payload']=json_decode($event['payload'],true,512,JSON_THROW_ON_ERROR);
        return ['id'=>(int)$delivery['id'],'attempt'=>$attempt,'token'=>$token,'event'=>$event];
    });
}

/** A stale worker cannot acknowledge a replacement lease; outcomes contain no response bodies. */
function pl_outbound_acknowledge(int $deliveryId,string $token,bool $success): bool
{
    return pl_ledger_transaction(function()use($deliveryId,$token,$success):bool{
        $row=DB::queryFirstRow("SELECT * FROM pl_outbound_deliveries WHERE id=%i AND status='leased' AND lease_token=%s FOR UPDATE",$deliveryId,$token);
        if(!$row){return false;}
        $attempt=(int)$row['attempts'];$outcome=$success?'succeeded':($attempt>=8?'dead':'retry');
        $delay=min(3600,60*(2**min(6,$attempt-1)));
        DB::update('pl_outbound_deliveries',[
            'status'=>$success?'succeeded':($attempt>=8?'dead':'ready'),
            'lease_token'=>null,'leased_until'=>null,'available_at'=>gmdate('Y-m-d H:i:s',time()+$delay),
            'completed_at'=>$success?gmdate('Y-m-d H:i:s'):null,
        ],'id=%i',$deliveryId);
        DB::insert('pl_outbound_attempts',['delivery_id'=>$deliveryId,'attempt_number'=>$attempt,'outcome'=>$outcome]);
        return true;
    });
}

/**
 * Trusted CLI registry, not request data. No default consumers and no network transport.
 * Each registration binds one consumer to one company/book and an explicit callable.
 * Handlers return true to acknowledge; false or any exception schedules a bounded retry.
 * Delivery is at-least-once: receivers must deduplicate the stable event_key.
 * Entries are checked at runtime: company_id:int, book_id:int, handler:callable.
 * @param array<array-key,mixed> $handlers
 */
function pl_dispatch_outbound_events(array $handlers,int $limit=100): array
{
    if(PHP_SAPI!=='cli'){throw new DomainException('Outbound dispatch is available only from the command line.');}
    if(DB::transactionDepth()!==0){throw new LogicException('Dispatch cannot run inside a transaction.');}
    if($limit<1||$limit>1000){throw new DomainException('Dispatch batch must contain 1 to 1000 events.');}
    $result=['claimed'=>0,'succeeded'=>0,'retried'=>0,'dead'=>0,'stale'=>0];
    foreach($handlers as $consumer=>$registration){
        if(!is_string($consumer)||!preg_match('/^[a-z][a-z0-9_.-]{0,79}$/D',$consumer)||!is_array($registration)||!is_int($registration['company_id']??null)||!is_int($registration['book_id']??null)||!is_callable($registration['handler']??null)){throw new DomainException('Invalid explicitly scoped outbound consumer.');}
        $companyId=$registration['company_id'];$bookId=$registration['book_id'];
        pl_ledger_book($companyId,$bookId);
        // A bounded lazy fan-out leaves events unconsumed when there is no registry entry.
        pl_ledger_transaction(function()use($consumer,$companyId,$bookId,$limit):void{
            pl_ledger_book($companyId,$bookId,true);
            $events=DB::query('SELECT e.id FROM pl_outbound_events e WHERE e.company_id=%i AND e.book_id=%i AND e.dispatch_kind=\'outbound\' AND NOT EXISTS(SELECT 1 FROM pl_outbound_deliveries d WHERE d.event_id=e.id AND d.consumer=%s) ORDER BY e.id LIMIT %i',$companyId,$bookId,$consumer,$limit);
            foreach($events as $event){DB::insert('pl_outbound_deliveries',['event_id'=>$event['id'],'company_id'=>$companyId,'book_id'=>$bookId,'consumer'=>$consumer]);}
        });
        while($result['claimed']<$limit){
            $claim=pl_outbound_claim($consumer,$companyId,$bookId);
            if($claim===null){break;}
            ++$result['claimed'];
            if(isset($claim['exhausted'])){++$result['dead'];continue;}
            try{$success=($registration['handler'])($claim['event'])===true;}catch(Throwable){$success=false;}
            if(!pl_outbound_acknowledge($claim['id'],$claim['token'],$success)){++$result['stale'];}
            elseif($success){++$result['succeeded'];}elseif($claim['attempt']>=8){++$result['dead'];}else{++$result['retried'];}
        }
        if($result['claimed']>=$limit){break;}
    }
    return $result;
}
