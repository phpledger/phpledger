<?php
declare(strict_types=1);

function pl_web_payroll(int $actor,int $companyId,int $bookId,array $user,array $company,string $method): never
{
    pl_payroll_require($actor,$companyId);
    $return=pl_url('/payroll'); $preview=null; $input=[];
    if ($method==='POST') {
        try { pl_web_assert_scope($company,$_POST); } catch (DomainException $e) { pl_form_failure($return,[],$e->getMessage()); }
        try {
            $action=pl_web_text($_POST,'action');
            if ($action==='provision') { pl_payroll_provision_accounts($actor,$companyId,$bookId); pl_notice(pl_t('Payroll accounts are ready.')); pl_redirect($return); }
            if ($action==='pay') {
                $allocations=[];
                foreach (is_array($_POST['allocations'] ?? null)?$_POST['allocations']:[] as $element=>$amount) {
                    if (is_string($amount) && trim($amount)!=='') { $allocations[]=['element_id'=>(int)$element,'amount'=>$amount]; }
                }
                $draft=pl_prepare_payroll_payment($actor,$companyId,$bookId,pl_web_id($_POST,'payroll_id'),['date'=>pl_web_text($_POST,'date'),
                    'bank_account_id'=>pl_web_id($_POST,'bank_account_id'),'creation_key'=>pl_web_text($_POST,'request_key'),'allocations'=>$allocations]);
                pl_redirect(pl_url('/general-journals/detail',['id'=>$draft['id']]));
            }
            if ($action==='reverse') {
                pl_payroll_require($actor,$companyId,true); $row=pl_get_payroll($actor,$companyId,$bookId,pl_web_id($_POST,'payroll_id'));
                pl_reverse_journal($actor,$companyId,$bookId,$row['journal_id'],pl_web_text($_POST,'date'),pl_web_text($_POST,'request_key'),pl_web_text($_POST,'reason'));
                pl_redirect($return.'?id='.$row['id']);
            }
            $elements=[];
            foreach (is_array($_POST['elements'] ?? null)?$_POST['elements']:[] as $element) {
                if (!is_array($element) || pl_web_text($element,'amount')==='') { continue; }
                $elements[]=['kind'=>pl_web_text($element,'kind'),'account_id'=>pl_web_id($element,'account_id'),'amount'=>pl_web_text($element,'amount')];
            }
            $input=['period_from'=>pl_web_text($_POST,'period_from'),'period_to'=>pl_web_text($_POST,'period_to'),'date'=>pl_web_text($_POST,'date'),
                'external_reference'=>pl_web_text($_POST,'external_reference'),'description'=>pl_web_text($_POST,'description'),'request_key'=>pl_web_text($_POST,'request_key'),'elements'=>$elements];
            $preview=pl_preview_payroll($actor,$companyId,$bookId,$input);
            if ($action==='post') {
                if (pl_web_text($_POST,'confirmed')!=='yes' || !hash_equals(hash('sha256',json_encode($preview,JSON_THROW_ON_ERROR)),pl_web_text($_POST,'preview_hash'))) { throw new DomainException('Review and confirm the payroll totals before posting.'); }
                $posted=pl_post_payroll($actor,$companyId,$bookId,$input); pl_redirect($return.'?id='.$posted['id']);
            }
            if ($action!=='preview') { throw new DomainException('Choose a payroll action.'); }
        } catch (DomainException $e) { pl_form_failure($return,$_POST,$e->getMessage()); }
    }
    $form=pl_form_state($return);
    if ($form['input']!==[] && (pl_web_id($form['input'],'company_id')!==$companyId || pl_web_id($form['input'],'book_id')!==$bookId)) { $form['input']=[]; }
    if ($input===[]) { $input=$form['input']?:['period_from'=>gmdate('Y-m-01'),'period_to'=>gmdate('Y-m-t'),'date'=>gmdate('Y-m-d'),'description'=>'Payroll '.gmdate('Y-m'),'request_key'=>bin2hex(random_bytes(16))]; }
    $from=pl_web_text($_GET,'from',gmdate('Y-01-01'));$to=pl_web_text($_GET,'to',gmdate('Y-12-31'));
    $selectedId=pl_web_id($_GET,'id');$selected=$selectedId?pl_get_payroll($actor,$companyId,$bookId,$selectedId):null;
    $payments=$selected?DB::query('SELECT p.draft_id,d.document_date,d.journal_id,r.id AS reversal_id FROM pl_payroll_payments p JOIN pl_general_drafts d ON d.id=p.draft_id LEFT JOIN pl_journals r ON r.reversal_of_id=d.journal_id WHERE p.company_id=%i AND p.book_id=%i AND p.payroll_id=%i ORDER BY p.id',$companyId,$bookId,$selectedId):[];
    pl_render('payroll',['title'=>pl_t('Payroll accounting'),'user'=>$user,'company'=>$company,'input'=>$input,'form'=>$form,'preview'=>$preview,
        'rows'=>pl_list_payroll($actor,$companyId,$bookId,$from,$to),'from'=>$from,'to'=>$to,'selected'=>$selected,'payments'=>$payments,
        'accounts'=>DB::query('SELECT id,code,name,type,role,semantic_key FROM pl_accounts WHERE company_id=%i AND book_id=%i AND is_active=1 ORDER BY code',$companyId,$bookId),
        'mayManage'=>pl_user_can($actor,$companyId,'payroll.manage'),'kinds'=>pl_payroll_kinds()]);
}
