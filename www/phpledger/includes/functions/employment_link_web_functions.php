<?php
declare(strict_types=1);
require_once __DIR__ . '/starter_web_functions.php';
function pl_web_employee_links(int $actor,int $companyId,int $bookId,array $user,array $company,string $method): never
{
    pl_employee_require_view($actor,$companyId);
    $return=pl_url('/employees/links');
    if ($method==='POST') {
        try { pl_web_assert_scope($company,$_POST); } catch (DomainException $e) { pl_form_failure($return,[],$e->getMessage()); }
        try {
            $action=pl_web_text($_POST,'action');$employee=pl_web_id($_POST,'employee_id');
            if ($action==='trade') { pl_link_employee_trade_party($actor,$companyId,$employee,pl_web_id($_POST,'party_id')?:null,pl_web_id($_POST,'revision'),pl_web_text($_POST,'reason')); }
            elseif ($action==='new_sales') { pl_save_sales_staff($actor,$companyId,$bookId,['code'=>pl_web_text($_POST,'code'),'employee_id'=>$employee,'reason'=>pl_web_text($_POST,'reason')]); }
            elseif (in_array($action,['sales_staff','driver'],true)) { pl_link_employee_reference($actor,$companyId,$bookId,$action,pl_web_id($_POST,'record_id'),$employee,pl_web_id($_POST,'revision'),pl_web_text($_POST,'reason')); }
            else { throw new DomainException('Choose an employee link action.'); }
            pl_notice(pl_t('Employee link saved.'));pl_redirect($return);
        } catch (DomainException|InvalidArgumentException $e) { pl_form_failure($return,[],$e->getMessage()); }
    }
    pl_render('employee-links',['title'=>pl_t('Employee links'),'user'=>$user,'company'=>$company,
        'employees'=>pl_list_employees($actor,$companyId),'staff'=>pl_list_sales_staff($actor,$companyId,$bookId),
        'warehouses'=>pl_list_inventory_warehouses($actor,$companyId,$bookId),
        'parties'=>DB::query("SELECT id,legal_name FROM pl_parties WHERE company_id=%i AND entity_type='individual' ORDER BY legal_name",$companyId),
        'candidates'=>pl_user_can($actor,$companyId,'relatedparty.view')?pl_employee_trade_candidates($actor,$companyId):[],
        'manage'=>pl_user_can($actor,$companyId,'employee.manage'),'form'=>pl_form_state($return)]);
}
