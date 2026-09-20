<?php
declare(strict_types=1);

function pl_web_starter_ar(int $actorId,int $companyId,int $bookId,array $user,array $company,string $path,string $method): never
{
    $receivable=$path==='/ar'; $normalKind=$receivable?'invoice':'bill'; $creditKind=$receivable?'customer_credit':'supplier_credit';
    $filterInput=$_GET['return_filters']??$_GET;
    if (!is_array($filterInput)) { throw new DomainException('Invalid list return filters.'); }
    $filters=pl_list_filters($filterInput,$receivable?'ar':'ap');
    if (isset($_GET['settle']) || in_array(pl_web_text($_POST,'action'),['preview_settlement','confirm_settlement'],true)) {
        pl_web_settlement($actorId,$companyId,$bookId,$user,$company,$path,$method);
    }
    if ($method==='POST') {
        $id=pl_web_id($_POST,'id');
        try {
            if ($id && !in_array(pl_get_ar_document($actorId,$companyId,$bookId,$id)['kind'],[$normalKind,$creditKind],true)) { throw new DomainException('Open this document in its owning module.'); }
            $action=pl_web_text($_POST,'action'); $key=pl_web_text($_POST,'request_key');
            if (in_array($action,['save','correct'],true)) {
                $kind=pl_web_text($_POST,'kind',$normalKind);
                if (!in_array($kind,[$normalKind,$creditKind],true)) { throw new DomainException('Invalid document type for this module.'); }
                $return=pl_workflow_url($path,['id'=>$id?:null,'new'=>$id?null:'1','return_filters'=>$filters]);
                if (pl_web_text($_POST,'editor_action')==='add_line' || isset($_POST['remove_line'])) {
                    pl_require_company_access($actorId,$companyId,true);
                    pl_form_failure($return,pl_web_line_action($_POST),'',200);
                }
                $input=pl_web_ar_editor_input($_POST,$kind);
                if (pl_web_text($_POST,'editor_action')==='preview') {
                    $preview=pl_web_ar_editor_preview($actorId,$companyId,$bookId,$id,$_POST,$kind);
                    $_SESSION['ar_editor_review']=['company_id'=>$companyId,'book_id'=>$bookId,'key'=>$key,'hash'=>hash('sha256',json_encode($preview,JSON_THROW_ON_ERROR))];
                    pl_form_failure($return,$_POST,'',200);
                }
                if ($action==='save' && pl_web_text($_POST,'editor_action')==='post_reviewed_document') {
                    $review=$_SESSION['ar_editor_review']??[];
                    $hash=is_array($review) && ($review['company_id']??null)===$companyId && ($review['book_id']??null)===$bookId && ($review['key']??null)===$key ? ($review['hash']??'') : '';
                    $result=pl_save_and_post_ar_document($actorId,$companyId,$bookId,$input,is_string($hash)?$hash:'',$id?:null,$id?pl_web_id($_POST,'revision'):null,pl_web_text($_POST,'rate')?:null);
                } else {
                $review=$_SESSION['ar_editor_review']??[];
                $hash=is_array($review) && ($review['company_id']??null)===$companyId && ($review['book_id']??null)===$bookId && ($review['key']??null)===$key ? ($review['hash']??'') : '';
                $result=$action==='correct'
                    ?pl_correct_ar_document($actorId,$companyId,$bookId,$id,$input,pl_web_id($_POST,'revision'),$key,pl_web_text($_POST,'reason'),pl_web_text($_POST,'reversal_date')?:null,pl_web_text($_POST,'rate')?:null,is_string($hash)?$hash:'')
                    :pl_save_ar_document($actorId,$companyId,$bookId,$input,$id?:null,$id?pl_web_id($_POST,'revision'):null);
                }
                $id=(int)$result['id'];
            } elseif ($action==='post') {
                pl_post_ar_document($actorId,$companyId,$bookId,$id,pl_web_id($_POST,'revision'),null,pl_web_text($_POST,'rate')?:null);
            } elseif ($action==='settle') {
                pl_settle_ar_document($actorId,$companyId,$bookId,$id,['date'=>pl_web_text($_POST,'date'),'amount_fc'=>pl_web_text($_POST,'amount_fc'),
                    'bank_account_id'=>pl_web_id($_POST,'bank_account_id'),'gain_account_id'=>pl_web_id($_POST,'gain_account_id')?:null,
                    'loss_account_id'=>pl_web_id($_POST,'loss_account_id')?:null,'actual_rate'=>pl_web_text($_POST,'actual_rate')?:null,
                    'description'=>pl_web_text($_POST,'description'),'idempotency_key'=>$key]);
            } elseif ($action==='reverse') {
                pl_reverse_ar_document($actorId,$companyId,$bookId,$id,pl_web_text($_POST,'date')?:null,pl_web_text($_POST,'reason'),$key);
            } elseif ($action==='reverse_payment') {
                $document=pl_get_ar_document($actorId,$companyId,$bookId,$id);
                if ($document['kind']!==$normalKind || $document['open_item_id']===null) { throw new DomainException('Open the original invoice or bill to reverse its payment.'); }
                $journalId=pl_web_id($_POST,'journal_id');
                $owned=DB::queryFirstField('SELECT e.id FROM pl_open_item_entries e JOIN pl_journal_lines l ON l.id=e.journal_line_id WHERE e.item_id=%i AND e.company_id=%i AND e.book_id=%i AND e.kind=%s AND l.journal_id=%i',$document['open_item_id'],$companyId,$bookId,'allocation',$journalId);
                if (!$owned) { throw new DomainException('Payment is unavailable for this document.'); }
                $journal=pl_get_journal($actorId,$companyId,$bookId,$journalId);
                if (!in_array($journal['source_type'],['open_item_settlement','open_item_batch_settlement'],true) || DB::queryFirstField('SELECT id FROM pl_ar_document_revisions WHERE journal_id=%i AND company_id=%i AND book_id=%i',$journalId,$companyId,$bookId)) { throw new DomainException('Reverse credits through their own document.'); }
                pl_reverse_journal($actorId,$companyId,$bookId,$journalId,pl_web_text($_POST,'date')?:null,$key,pl_web_text($_POST,'reason'));
            } elseif ($action==='activate') {
                pl_activate_open_item_account($actorId,$companyId,$bookId,pl_web_id($_POST,'account_id'),pl_web_text($_POST,'reason'));
            } else { throw new DomainException('Choose a document action.'); }
            pl_notice('Accounting action completed. Review the updated document and balances.');
            pl_redirect(pl_workflow_url($path,['id'=>$id?:null,'return_filters'=>$filters]));
        } catch (DomainException $error) {
            $input=array_filter($_POST,static fn(mixed $value):bool=>is_scalar($value));
            if (is_array($_POST['lines']??null)) { $input['lines']=array_values(array_map(static fn(array $line):array=>array_filter($line,static fn(mixed $value):bool=>is_scalar($value)),array_filter($_POST['lines'],'is_array'))); }
            pl_form_failure(pl_workflow_url($path,['id'=>$id?:null,'new'=>!$id&&in_array(pl_web_text($_POST,'action'),['save','correct'],true)?'1':null,'return_filters'=>$filters]),$input,$error->getMessage());
        }
    }
    $id=pl_web_id($_GET,'id'); $document=$id?pl_get_ar_document($actorId,$companyId,$bookId,$id):null;
    if ($document && !in_array($document['kind'],[$normalKind,$creditKind],true)) { throw new DomainException('Open this document in its owning module.'); }
    $creditFor=pl_web_id($_GET,'credit_for'); $original=$creditFor?pl_get_ar_document($actorId,$companyId,$bookId,$creditFor):null;
    if ($original && ($original['kind']!==$normalKind || $original['journal_id']===null || $original['payment_status']==='reversed' || bccomp($original['outstanding_fc'],'0',4)<=0)) { throw new DomainException('Choose a posted invoice or bill with an outstanding amount in this module.'); }
    $form=pl_form_state(pl_workflow_url($path,['id'=>$id?:null,'new'=>$id?null:(pl_web_text($_GET,'new')?:null),'return_filters'=>isset($_GET['return_filters'])?$filters:null]));
    $originalId=$document['original_document_id']??pl_web_id($form['input'],'original_document_id');
    if ($original===null && $originalId) { $original=pl_get_ar_document($actorId,$companyId,$bookId,(int)$originalId); }
    $editing=isset($_GET['new']) || isset($_GET['edit']) || isset($_GET['correct']) || $original!==null && !$document || in_array($form['input']['action']??'',['save','correct'],true);
    $list=$id || $editing?['documents'=>[],'total'=>0,'page'=>1,'pages'=>1]:pl_list_query($actorId,$companyId,$bookId,$receivable?'ar':'ap',$filters);
    $selection=null;
    if (!$id && !$editing) {
        $selectedId=pl_web_id($_GET,'select');
        $selection=$selectedId?pl_get_ar_document($actorId,$companyId,$bookId,$selectedId):($list['documents'][0]??null);
        if ($selection && !in_array($selection['kind'],[$normalKind,$creditKind],true)) { throw new DomainException('Open this document in its owning module.'); }
    }
    $postingPreview=null;
    if (in_array($form['input']['action']??'',['save','correct'],true) && in_array($form['input']['editor_action']??'',['preview','post_reviewed_document'],true)) {
        try { $postingPreview=pl_web_ar_editor_preview($actorId,$companyId,$bookId,$id,$form['input'],pl_web_text($form['input'],'kind',$normalKind)); }
        catch (DomainException $error) { if ($form['message']==='') { $form['message']=$error->getMessage(); } }
    }
    $documents=$list['documents'];
    $report=pl_ar_ap_open_items($actorId,$companyId,$bookId,$receivable?'receivable':'payable',pl_web_text($_GET,'as_of')?:null);
    $settlements=$document&&$document['open_item_id']?DB::query("SELECT e.kind,l.journal_id,l.amount_fc,l.amount_base,j.journal_date AS posting_date,j.source_type,d.id AS credit_document_id,d.kind AS credit_kind,EXISTS(SELECT 1 FROM pl_journals v WHERE v.reversal_of_id=j.id) AS already_reversed FROM pl_open_item_entries e JOIN pl_journal_lines l ON l.id=e.journal_line_id JOIN pl_journals j ON j.id=l.journal_id LEFT JOIN pl_ar_document_revisions r ON r.journal_id=j.id AND r.company_id=e.company_id AND r.book_id=e.book_id LEFT JOIN pl_ar_documents d ON d.id=r.document_id AND d.kind IN ('customer_credit','supplier_credit') WHERE e.item_id=%i AND e.company_id=%i AND e.book_id=%i ORDER BY e.id",$document['open_item_id'],$companyId,$bookId):[];
    pl_render($receivable?'ar':'ap',['title'=>$receivable?'Invoices':'Bills','user'=>$user,'company'=>$company,'path'=>$path,'filters'=>$filters,'list'=>$list,'selection'=>$selection,
        'form'=>$form,'document'=>$document,'original'=>$original,'normalKind'=>$normalKind,'creditKind'=>$creditKind,'documents'=>$documents,'report'=>$report,'postingPreview'=>$postingPreview,
        'taxContext'=>(isset($_GET['new']) || isset($_GET['edit']) || isset($_GET['correct']) || $original!==null || in_array($form['input']['action']??'',['save','correct'],true))?pl_ar_editor_tax_context($actorId,$companyId,$bookId,$original,$id?:null):null,
        'purchaseSources'=>!$receivable && $document?pl_purchase_document_sources($actorId,$companyId,$bookId,$document['id']):[],
        'recordJournal'=>$document && $document['journal_id']?pl_get_journal($actorId,$companyId,$bookId,$document['journal_id']):null,
        'selectionJournal'=>$selection && $selection['journal_id']?pl_get_journal($actorId,$companyId,$bookId,$selection['journal_id']):null,
        'accounts'=>pl_starter_accounts($actorId,$companyId,$bookId),'parties'=>pl_starter_parties($actorId,$companyId,$bookId),
        'priceMode'=>pl_tax_price_mode($actorId,$companyId,$bookId),'products'=>pl_list_inventory_products($actorId,$companyId,$bookId),'taxCodes'=>pl_list_tax_codes($actorId,$companyId,$bookId),'settlements'=>$settlements]);
}

function pl_web_ar_editor_preview(int $actorId,int $companyId,int $bookId,int $id,array $values,string $kind): array
{
    $input=pl_web_ar_editor_input($values,$kind); $rate=pl_web_text($values,'rate')?:null;
    if (pl_web_text($values,'action')==='correct') {
        return pl_preview_ar_correction($actorId,$companyId,$bookId,$id,$input,pl_web_id($values,'revision'),pl_web_text($values,'reason'),pl_web_text($values,'reversal_date')?:null,$rate);
    }
    return pl_preview_ar_document($actorId,$companyId,$bookId,$input,$id?:null,$id?pl_web_id($values,'revision'):null,$rate);
}

function pl_web_ar_editor_input(array $input,string $kind): array
{
    $data=['kind'=>$kind,'price_mode'=>pl_web_text($input,'price_mode')?:null,'party_id'=>pl_web_id($input,'party_id'),'date'=>pl_web_text($input,'date'),
        'due_date'=>pl_web_text($input,'due_date'),'currency'=>strtoupper(pl_web_text($input,'currency')),'reference'=>pl_web_text($input,'reference'),
        'terms'=>pl_web_text($input,'terms'),'notes'=>pl_web_text($input,'notes'),'creation_key'=>pl_web_text($input,'request_key'),
        'rounding_account_id'=>pl_web_id($input,'rounding_account_id')?:null,'lines'=>pl_starter_lines($input),
        // Trading dimensions and counter cash (1.2 M3). Every one is optional; an editor that
        // does not send them produces the document it produced before this milestone.
        'sales_staff_id'=>pl_web_id($input,'sales_staff_id')?:'','area_id'=>pl_web_id($input,'area_id')?:'',
        'warehouse_id'=>pl_web_id($input,'warehouse_id')?:'',
        'cash_received'=>pl_web_text($input,'cash_received','0')?:'0','cash_account_id'=>pl_web_id($input,'cash_account_id')?:''];
    if (in_array($kind,['customer_credit','supplier_credit'],true)) { $data['original_document_id']=pl_web_id($input,'original_document_id'); }
    return $data;
}

function pl_web_settlement_input(array $input,string $direction): array
{
    $rows=$input['allocations']??[];
    if (!is_array($rows) || count($rows)>500) { throw new DomainException('Choose a valid allocation list.'); }
    $allocations=[];
    foreach ($rows as $row) {
        if (!is_array($row)) { throw new DomainException('Choose valid allocation rows.'); }
        $amount=pl_web_text($row,'amount_fc');
        if ($amount==='' || bccomp(pl_amount($amount),'0',4)===0) { continue; }
        $allocations[]=['item_id'=>pl_web_id($row,'item_id'),'amount_fc'=>$amount];
    }
    return ['party_id'=>pl_web_id($input,'party_id'),'direction'=>$direction,'date'=>pl_web_text($input,'date'),
        'amount_fc'=>pl_web_text($input,'amount_fc'),'bank_account_id'=>pl_web_id($input,'bank_account_id'),
        'gain_account_id'=>pl_web_id($input,'gain_account_id')?:null,'loss_account_id'=>pl_web_id($input,'loss_account_id')?:null,
        'actual_rate'=>pl_web_text($input,'actual_rate')?:null,'description'=>pl_web_text($input,'description'),
        'idempotency_key'=>pl_web_text($input,'request_key'),'allocations'=>$allocations];
}

function pl_web_settlement(int $actorId,int $companyId,int $bookId,array $user,array $company,string $path,string $method): never
{
    $direction=$path==='/ar'?'receivable':'payable';
    $return=pl_workflow_url($path,['settle'=>'1']);
    if ($method==='POST') {
        try {
            $input=pl_web_settlement_input($_POST,$direction);
            if (pl_web_text($_POST,'action')==='confirm_settlement') {
                $review=$_SESSION['settlement_review']??[];
                $hash=is_array($review) && ($review['company_id']??null)===$companyId && ($review['book_id']??null)===$bookId && ($review['key']??null)===$input['idempotency_key'] ? ($review['hash']??'') : '';
                $result=pl_confirm_settlement($actorId,$companyId,$bookId,$input,is_string($hash)?$hash:'');
                pl_notice('Payment posted across '.count($result['allocations']).' open items. No amount was left unallocated.');
                pl_redirect(pl_workflow_url('/journals/detail',['id'=>$result['journal_id']]));
            }
            $preview=pl_preview_settlement($actorId,$companyId,$bookId,$input);
            $_SESSION['settlement_review']=['company_id'=>$companyId,'book_id'=>$bookId,'key'=>$input['idempotency_key'],'hash'=>pl_settlement_review_hash($input,$preview)];
            pl_form_failure($return,$_POST,'',200);
        } catch (DomainException $error) { pl_form_failure($return,$_POST,$error->getMessage()); }
    }
    $form=pl_form_state($return); $input=$form['input']?:$_GET; $preview=null;
    if ($form['input']) {
        try { $preview=pl_preview_settlement($actorId,$companyId,$bookId,pl_web_settlement_input($input,$direction)); }
        catch (DomainException $error) { if ($form['message']==='') { $form['message']=$error->getMessage(); } }
    }
    $report=pl_ar_ap_open_items($actorId,$companyId,$bookId,$direction);
    $partyId=pl_web_id($input,'party_id'); $currency=$preview['currency']??pl_web_text($input,'currency',$company['currency']);
    $items=array_values(array_filter($report['items'],static fn(array $item):bool=>(int)$item['party_id']===$partyId && $item['currency']===$currency));
    pl_render('settlement',['title'=>$direction==='receivable'?'Record receipt':'Record payment','user'=>$user,'company'=>$company,'path'=>$path,'direction'=>$direction,
        'input'=>$input,'form'=>$form,'preview'=>$preview,'items'=>$items,'partyId'=>$partyId,'currency'=>$currency,
        'parties'=>pl_starter_parties($actorId,$companyId,$bookId),'accounts'=>pl_starter_accounts($actorId,$companyId,$bookId),
        'currencies'=>array_values(array_unique(array_merge([$company['currency']],array_column($report['items'],'currency'))))]);
}
