<?php
declare(strict_types=1);

/** One payment has one party/currency/direction and no unallocated remainder. */
function pl_normalize_settlement(array $input): array
{
    $rows=$input['allocations'] ?? null;
    if (!is_array($rows) || count($rows)<1 || count($rows)>30) { throw new DomainException('Allocate the payment to between one and thirty open items.'); }
    $allocations=[]; $sum='0.0000';
    foreach ($rows as $row) {
        if (!is_array($row)) { throw new DomainException('Choose valid allocation rows.'); }
        $id=pl_oi_id($row,'item_id');
        if (isset($allocations[$id])) { throw new DomainException('An open item may appear only once in a payment.'); }
        $amount=pl_amount(pl_ledger_text($row['amount_fc']??null,'Allocation amount',30));
        if (bccomp($amount,'0',4)<=0) { throw new DomainException('Each selected allocation must be greater than zero.'); }
        $allocations[$id]=['item_id'=>$id,'amount_fc'=>$amount]; $sum=bcadd($sum,$amount,4);
    }
    ksort($allocations,SORT_NUMERIC);
    $total=pl_amount(pl_ledger_text($input['amount_fc']??null,'Payment amount',30));
    if (bccomp($total,$sum,4)!==0) { throw new DomainException('Allocations must equal the payment exactly. Unallocated money and over-allocation are not accepted.'); }
    $direction=$input['direction']??null;
    if (!in_array($direction,['receivable','payable'],true)) { throw new DomainException('Choose a receipt or a payment.'); }
    return ['action'=>'settle_batch','party_id'=>pl_oi_id($input,'party_id'),'direction'=>$direction,
        'bank_account_id'=>pl_oi_id($input,'bank_account_id'),'amount_fc'=>$total,'allocations'=>array_values($allocations),
        'date'=>pl_ledger_date(pl_ledger_text($input['date']??null,'Payment date',10)),
        'description'=>pl_ledger_text($input['description']??null,'Payment reference',500),
        'actual_rate'=>isset($input['actual_rate']) && $input['actual_rate']!=='' ? pl_fx_rate(pl_ledger_text($input['actual_rate'],'Actual rate',40)) : null,
        'rate_source_id'=>isset($input['rate_source_id']) ? pl_oi_id($input,'rate_source_id') : null,
        'gain_account_id'=>isset($input['gain_account_id']) && $input['gain_account_id']!=='' ? pl_oi_id($input,'gain_account_id') : null,
        'loss_account_id'=>isset($input['loss_account_id']) && $input['loss_account_id']!=='' ? pl_oi_id($input,'loss_account_id') : null];
}

/** Called under the book lock for both preview and posting; no writes here. */
function pl_settlement_plan(int $actorId,int $companyId,int $bookId,array $data,bool $posting): array
{
    $book=pl_ledger_book($companyId,$bookId);
    pl_require_module($actorId,$companyId,$bookId,$data['direction']==='receivable'?'ar':'ap');
    $bank=DB::queryFirstRow('SELECT * FROM pl_accounts WHERE id=%i AND company_id=%i AND book_id=%i AND is_active=1 AND role=%s FOR SHARE',$data['bank_account_id'],$companyId,$bookId,'cash_bank');
    if (!$bank) { throw new DomainException('Choose an active cash/bank account in this book.'); }
    $currency=null; $lines=[]; $map=[]; $allocated=[]; $carrying='0.0000';
    $receipt=$data['direction']==='receivable';
    foreach ($data['allocations'] as $allocation) {
        $item=pl_open_item_state($companyId,$bookId,$allocation['item_id']);
        $currency ??= $item['currency'];
        if ((int)$item['party_id']!==$data['party_id'] || $item['direction']!==$data['direction'] || $item['currency']!==$currency) {
            throw new DomainException('One payment must use one party, currency and payment direction.');
        }
        if ($data['date']<$item['latest_activity_date']) { throw new DomainException('Payment cannot precede the latest activity of any selected open item.'); }
        $base=pl_oi_allocated_base($item,$allocation['amount_fc']);
        $historical=array_intersect_key($item['recognition'],array_flip(['currency','rate','rate_type','rate_source_id','rate_is_stale','ic_counterparty_entity_id']));
        foreach (['rate_source_id','ic_counterparty_entity_id'] as $field) { $historical[$field]=$historical[$field]===null?null:(int)$historical[$field]; }
        $historical['rate_is_stale']=(bool)$historical['rate_is_stale'];
        $map[count($lines)]=$allocation['item_id'];
        $lines[]=pl_oi_line((int)$item['control_account_id'],$allocation['amount_fc'],$base,!$receipt,$historical,'Historic open-item carrying value');
        $allocated[]=$allocation+['allocated_base'=>$base,'source_reference'=>$item['source_reference'],'remaining_fc'=>$item['remaining_fc'],'remaining_base'=>$item['remaining_base']];
        $carrying=bcadd($carrying,$base,4);
    }
    $snapshot=pl_oi_rate($actorId,$companyId,$bookId,$currency,$data['date'],$data['actual_rate'],$data['rate_source_id'],'actual');
    $settlementBase=pl_fx_convert($data['amount_fc'],$snapshot['rate']);
    $bankCurrency=$bank['currency']??$book['currency'];
    if (!in_array($bankCurrency,[$book['currency'],$currency],true)) { throw new DomainException('A third-currency bank conversion requires a separate conversion transaction.'); }
    if (!$receipt && $bankCurrency!==$book['currency']) { throw new DomainException('Outgoing settlements require a functional-currency bank until foreign-bank carrying-value allocation is implemented.'); }
    $domestic=pl_oi_rate($actorId,$companyId,$bookId,$book['currency'],$data['date'],null,null,'spot');
    $lines[]=pl_oi_line($data['bank_account_id'],$bankCurrency===$book['currency']?$settlementBase:$data['amount_fc'],$settlementBase,$receipt,$bankCurrency===$book['currency']?$domestic:$snapshot,$data['description']);
    $difference=bcsub($settlementBase,$carrying,4); $fxKind=null;
    if (bccomp($difference,'0',4)!==0) {
        $gain=($receipt && bccomp($difference,'0',4)>0) || (!$receipt && bccomp($difference,'0',4)<0);
        $fxKind=$gain?'gain':'loss'; $account=$data[$fxKind.'_account_id'];
        $valid=$account!==null && DB::queryFirstField('SELECT id FROM pl_accounts WHERE id=%i AND company_id=%i AND book_id=%i AND is_active=1 AND type=%s FOR SHARE',$account,$companyId,$bookId,$gain?'income':'expense');
        if (!$valid && $posting) { throw new DomainException('Choose an active realised FX '.$fxKind.' account for the calculated exchange difference.'); }
        if ($valid) { $amount=ltrim($difference,'-'); $lines[]=pl_oi_line($account,$amount,$amount,!$gain,$domestic,'Realised FX '.$fxKind); }
    }
    return ['currency'=>$currency,'functional_currency'=>$book['currency'],'amount_fc'=>$data['amount_fc'],'allocated_base'=>$carrying,'settlement_base'=>$settlementBase,
        'fx_kind'=>$fxKind,'fx_amount'=>ltrim($difference,'-'),'settlement_rate'=>$snapshot['rate'],'rate_snapshot'=>$snapshot,'allocations'=>$allocated,'line_items'=>$map,'lines'=>$lines];
}

function pl_preview_settlement(int $actorId,int $companyId,int $bookId,array $input): array
{
    $data=pl_normalize_settlement($input);
    return pl_ledger_transaction(function () use ($actorId,$companyId,$bookId,$data): array {
        pl_require_company_access($actorId,$companyId,true); pl_ledger_book($companyId,$bookId,true);
        return pl_settlement_plan($actorId,$companyId,$bookId,$data,false);
    });
}

function pl_settle_open_items(int $actorId,int $companyId,int $bookId,array $input): array
{
    $data=pl_normalize_settlement($input);
    return pl_ar_canonical_result(pl_oi_command($actorId,$companyId,$bookId,pl_ledger_text($input['idempotency_key']??null,'Payment identity',128),$data,
        function (string $journalKey) use ($actorId,$companyId,$bookId,$data): array {
            $plan=pl_settlement_plan($actorId,$companyId,$bookId,$data,true);
            $journal=pl_post_journal_locked($actorId,$companyId,$bookId,['date'=>$data['date'],'currency'=>$plan['functional_currency'],
                'source_type'=>'open_item_batch_settlement','source_reference'=>'open-item-batch:'.hash('sha256',json_encode($data,JSON_THROW_ON_ERROR)),
                'idempotency_key'=>$journalKey,'description'=>$data['description'],'lines'=>$plan['lines']],null,null,$plan['line_items']);
            unset($plan['lines'],$plan['line_items']);
            return ['journal_id'=>(int)$journal['id']]+$plan;
        }));
}

/** Financial review identity excludes the FX account selected after preview. */
function pl_settlement_review_hash(array $input,array $plan): string
{
    $data=pl_normalize_settlement($input);
    unset($data['gain_account_id'],$data['loss_account_id'],$plan['lines'],$plan['line_items']);
    return hash('sha256',json_encode([$data,$plan],JSON_THROW_ON_ERROR));
}

function pl_confirm_settlement(int $actorId,int $companyId,int $bookId,array $input,string $expectedHash): array
{
    return pl_ledger_transaction(function () use ($actorId,$companyId,$bookId,$input,$expectedHash): array {
        pl_require_company_access($actorId,$companyId,true); pl_ledger_book($companyId,$bookId,true);
        $key=pl_request_key(pl_ledger_text($input['idempotency_key']??null,'Payment identity',128));
        if (!DB::queryFirstField('SELECT request_key FROM pl_open_item_commands WHERE company_id=%i AND book_id=%i AND request_key=%s',$companyId,$bookId,$key)) {
            $plan=pl_preview_settlement($actorId,$companyId,$bookId,$input);
            if (!hash_equals($expectedHash,pl_settlement_review_hash($input,$plan))) { throw new DomainException('The payment or its balances changed. Update the preview before confirming.'); }
        }
        return pl_settle_open_items($actorId,$companyId,$bookId,$input);
    });
}

/**
 * Read-only receipt view of one posted settlement, for the print pipeline (1.2 M2).
 *
 * It reads through pl_get_journal(), so the company/book scope and the viewer-readable
 * role rule of the settlement's record screen apply unchanged, and a journal id from
 * another company is refused there. Nothing is posted, allocated or written.
 */
function pl_settlement_receipt(int $actorId,int $companyId,int $bookId,int $journalId): array
{
    $journal=pl_get_journal($actorId,$companyId,$bookId,$journalId);
    if (!in_array($journal['source_type'],['open_item_settlement','open_item_batch_settlement'],true)) {
        throw new DomainException('This journal is not a customer receipt or supplier payment.');
    }
    $allocations=DB::query('SELECT i.id AS item_id,i.source_reference,i.currency,i.direction,i.party_id,l.amount_fc,l.amount_base '
        .'FROM pl_open_item_entries e JOIN pl_journal_lines l ON l.id=e.journal_line_id '
        .'JOIN pl_open_items i ON i.id=e.item_id AND i.company_id=e.company_id AND i.book_id=e.book_id '
        .'WHERE l.journal_id=%i AND e.company_id=%i AND e.book_id=%i AND e.kind=%s ORDER BY e.id',
        $journalId,$companyId,$bookId,'allocation');
    if ($allocations===[]) { throw new DomainException('This payment has no allocated open item to receipt.'); }
    $allocated='0.0000';
    foreach ($allocations as &$allocation) {
        $allocation['item_id']=(int)$allocation['item_id'];
        $allocation['party_id']=(int)$allocation['party_id'];
        $allocated=bcadd($allocated,(string)$allocation['amount_fc'],4);
    }
    unset($allocation);
    $bankAccounts=array_map('intval',array_column(DB::query('SELECT id FROM pl_accounts WHERE company_id=%i AND book_id=%i AND role=%s',$companyId,$bookId,'cash_bank'),'id'));
    $bank=null;
    foreach ($journal['lines'] as $line) {
        if ($bank===null && in_array((int)$line['account_id'],$bankAccounts,true)) { $bank=$line; }
    }
    return ['journal'=>$journal,'direction'=>(string)$allocations[0]['direction'],'currency'=>(string)$allocations[0]['currency'],
        'party'=>pl_get_party($actorId,$companyId,$bookId,(int)$allocations[0]['party_id']),
        'allocations'=>$allocations,'allocated_fc'=>$allocated,
        'bank'=>$bank===null?null:['code'=>(string)$bank['code'],'name'=>(string)$bank['name'],'amount_fc'=>(string)$bank['amount_fc'],
            'amount_base'=>(string)$bank['amount_base'],'currency'=>(string)$bank['currency']],
        'reversed'=>(bool)DB::queryFirstField('SELECT id FROM pl_journals WHERE reversal_of_id=%i AND company_id=%i AND book_id=%i',$journalId,$companyId,$bookId),
        'is_reversal'=>$journal['reversal_of_id']!==null];
}
