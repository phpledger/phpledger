<?php
declare(strict_types=1);

function pl_web_periods(int $actorId, int $companyId, int $bookId, array $user, array $company, string $method): never
{
    if ($method === 'POST') {
        try {
            pl_require_post($method);
            pl_require_csrf(pl_web_text($_POST, 'csrf'));
            pl_web_assert_scope($company, $_POST);
            $action = pl_web_text($_POST, 'action');
            if ($action === 'create') {
                $result = pl_create_period($actorId, $companyId, $bookId, [
                    'start_date' => pl_web_text($_POST, 'start_date'), 'end_date' => pl_web_text($_POST, 'end_date'),
                    'reason' => pl_web_text($_POST, 'reason'), 'request_key' => pl_web_text($_POST, 'request_key'),
                ]);
            } elseif (in_array($action, ['close', 'reopen'], true)) {
                $result = pl_change_period_status($actorId, $companyId, $bookId, pl_web_id($_POST, 'period_id'), $action === 'close' ? 'closed' : 'open', pl_web_id($_POST, 'revision'), pl_web_text($_POST, 'reason'), pl_web_text($_POST, 'request_key'));
            } elseif ($action === 'tick') {
                $result = pl_period_tick_item($actorId, $companyId, $bookId, pl_web_id($_POST, 'period_id'), pl_web_text($_POST, 'item_key'), pl_web_text($_POST, 'state'), pl_web_text($_POST, 'reason'), pl_web_text($_POST, 'request_key'));
            } elseif ($action === 'add_item') {
                $result = pl_period_save_checklist_item($actorId, $companyId, $bookId, ['item_key' => pl_web_text($_POST, 'item_key'), 'label' => pl_web_text($_POST, 'label'), 'severity' => pl_web_text($_POST, 'severity')]);
            } elseif ($action === 'severity') {
                $result = pl_period_set_item_severity($actorId, $companyId, $bookId, pl_web_text($_POST, 'item_key'), pl_web_text($_POST, 'severity'));
            } else {
                throw new DomainException('Choose a valid period action.');
            }
            pl_notice('Period action recorded. Review the current status and checklist below.' . (!empty($result['checklist_warnings']) ? ' Advisory items remain: ' . implode('; ', $result['checklist_warnings']) : '') . (!empty($result['reversals_refused']) ? ' Some scheduled reversals could not post: ' . implode('; ', $result['reversals_not_posted']) : ''));
            pl_redirect('/periods');
        } catch (DomainException $error) {
            pl_form_failure('/periods', $_POST, $error->getMessage());
        }
    }
    $periods = pl_list_periods($actorId, $companyId, $bookId);
    $checklists = [];
    foreach ($periods as $period) { $checklists[(int) $period['id']] = pl_period_checklist($actorId, $companyId, $bookId, (int) $period['id']); }
    pl_render('periods', [
        'checklists' => $checklists,
        'title' => 'Accounting periods', 'user' => $user, 'company' => $company,
        'periods' => $periods,
        'history' => pl_period_history($actorId, $companyId, $bookId), 'form' => pl_form_state('/periods'),
    ]);
}
