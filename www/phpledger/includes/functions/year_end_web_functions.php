<?php
declare(strict_types=1);

function pl_web_year_end(int $actor,int $companyId,int $book,array $user,array $company,string $method): never
{
    pl_year_end_require($actor,$companyId);
    if($method==='POST'){
        try{
            pl_require_post($method);pl_require_csrf(pl_web_text($_POST,'csrf'));pl_web_assert_scope($company,$_POST);
            $action=pl_web_text($_POST,'action');$id=pl_web_id($_POST,'year_id');$key=pl_web_text($_POST,'request_key');
            if($action==='create'||$action==='policy'){
                $partners=[];foreach((array)($_POST['partner_id']??[]) as $index=>$partner){if((string)$partner===''){continue;}$partners[]=['partner_id'=>(int)$partner,'ratio'=>(string)($_POST['ratio'][$index]??''),'current_account_id'=>(int)($_POST['current_account_id'][$index]??0)];}
                $policy=['treatment'=>pl_web_text($_POST,'treatment'),'legal_basis'=>pl_web_text($_POST,'legal_basis'),'review_note'=>pl_web_text($_POST,'note'),'destination_account_id'=>pl_web_id($_POST,'destination_account_id'),'drawings_account_id'=>pl_web_id($_POST,'drawings_account_id'),'capital_method'=>pl_web_text($_POST,'capital_method'),'partners'=>$partners];
                $result=$action==='create'?pl_year_end_create($actor,$companyId,$book,pl_web_text($_POST,'end_date'),$policy,$key):pl_year_end_change($actor,$companyId,$book,$id,'policy',pl_web_id($_POST,'revision'),pl_web_text($_POST,'note'),$key,$policy);
            }elseif($action==='reverse_adjustment'){
                $result=pl_year_end_reverse_adjustment($actor,$companyId,$book,$id,pl_web_id($_POST,'journal_id'),pl_web_text($_POST,'note'),$key);
            }elseif($action==='adjust'){
                $lines=[];foreach((array)($_POST['account_id']??[]) as $index=>$account){if((string)$account===''){continue;}$lines[]=['account_id'=>(int)$account,'debit'=>(string)($_POST['debit'][$index]??'0'),'credit'=>(string)($_POST['credit'][$index]??'0'),'description'=>pl_web_text($_POST,'note')];}
                $result=pl_year_end_adjust($actor,$companyId,$book,$id,['description'=>pl_web_text($_POST,'note'),'lines'=>$lines],$key);
            }else{
                $input=['fingerprint'=>pl_web_text($_POST,'fingerprint')];foreach(['stock','provisions','adjustments','payroll'] as $item){$input[$item]=($_POST[$item]??'')==='1';}
                $result=pl_year_end_change($actor,$companyId,$book,$id,$action,pl_web_id($_POST,'revision'),pl_web_text($_POST,'note'),$key,$action==='close'?$input:[]);
            }
            pl_notice('Year-end action recorded.');pl_redirect(pl_url('/year-end',['id'=>$result['year_id']]));
        }catch(DomainException $e){pl_form_failure('/year-end',$_POST,$e->getMessage());}
    }
    $form=pl_form_state('/year-end');$id=pl_web_id($_GET,'id')?:pl_web_id($form['input'],'year_id');$year=$id?pl_year_end_get($actor,$companyId,$book,$id):null;$preview=null;$previewError='';
    if($year&&in_array($year['status'],['open','closing'],true)){try{$preview=pl_year_end_preview($actor,$companyId,$book,$id);}catch(DomainException $e){$previewError=$e->getMessage();}}
    $currency=pl_ledger_book($companyId,$book)['currency'];
    $accounts=array_values(array_filter(DB::query('SELECT id,code,name,type,is_contra,currency FROM pl_accounts WHERE company_id=%i AND book_id=%i AND is_active=1 ORDER BY code',$companyId,$book),static fn(array $a):bool=>($a['currency']===null||$a['currency']===''||$a['currency']===$currency)&&pl_account_is_postable($companyId,$book,$a['code'])));
    pl_render('year-end',['title'=>'Year-end close','user'=>$user,'company'=>$company,'years'=>pl_year_end_list($actor,$companyId,$book),'year'=>$year,'preview'=>$preview,'previewError'=>$previewError,'report'=>$year?pl_year_end_report($actor,$companyId,$book,$id):null,'form'=>$form,'canManage'=>pl_user_can($actor,$companyId,'year_end.manage'),'accounts'=>$accounts,'partners'=>pl_list_owner_partners($actor,$companyId,$book)]);
}
