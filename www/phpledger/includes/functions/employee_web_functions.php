<?php
declare(strict_types=1);

/**
 * The employee master's one screen (1.3 M17, issue #98's blocker): `/employees`, list plus a
 * selected employee's detail and edit form, the same shape `/users` and `/owner` already use.
 *
 * Every service call here has already been authorised inside the service. This function reads
 * the form, calls the service and chooses the next screen; it never decides a permission and
 * never computes a figure.
 */
function pl_web_employees(int $actorId, int $companyId, int $bookId, array $user, array $company, string $method): never
{
    if ($method === 'POST') {
        pl_web_employees_post($actorId, $companyId, $company);
    }
    $form = pl_form_state(pl_url('/employees'));
    // A failed form belongs to the company/book in which it was submitted, even if another
    // tab switches context before its redirect is followed. Never replay personal data there.
    if ($form['input'] !== [] && (pl_web_id($form['input'], 'company_id') !== $companyId
        || pl_web_id($form['input'], 'book_id') !== $bookId)) {
        $form['input'] = [];
    }
    // Split exactly like the related-party marker (B58): reading the register at all needs
    // employee.view, and the form needs employee.manage. Neither implies the other, so both are
    // checked here: a save returns personal data, so a manager must also hold the read grant.
    $mayView = pl_user_can($actorId, $companyId, 'employee.view');
    $mayManage = $mayView && pl_user_can($actorId, $companyId, 'employee.manage');
    $statusFilter = pl_web_text($_GET, 'status');
    if ($statusFilter !== '' && !isset(pl_employment_statuses()[$statusFilter])) {
        $statusFilter = '';
    }
    $selectedId = pl_web_id($_GET, 'id') ?: pl_web_id($form['input'], 'id');
    $selected = ($mayView && $selectedId > 0) ? pl_employee_get_or_null($actorId, $companyId, $selectedId) : null;
    pl_render('employees', [
        'title' => pl_t('Employees'), 'user' => $user, 'company' => $company,
        'employees' => $mayView ? pl_list_employees($actorId, $companyId, $statusFilter === '' ? null : $statusFilter) : [],
        'statusFilter' => $statusFilter,
        'statuses' => pl_employment_statuses(), 'types' => pl_employment_types(), 'payFrequencies' => pl_pay_frequencies(),
        'ownershipParties' => $mayManage ? array_values(array_filter(pl_list_ownership_parties($actorId, $companyId), static fn (array $party): bool => $party['kind'] === 'person')) : [],
        'mayView' => $mayView, 'mayManage' => $mayManage,
        'selected' => $selected,
        'form' => $form, 'input' => $form['input'] ?: ($selected ?? ['hire_date' => gmdate('Y-m-d'), 'employment_status' => 'active', 'employment_type' => 'full_time']),
    ]);
}

/** pl_get_employee() throws for an id outside this company; the screen treats that as "no selection" rather than a 422 on a stale link. */
function pl_employee_get_or_null(int $actorId, int $companyId, int $id): ?array
{
    try {
        return pl_get_employee($actorId, $companyId, $id);
    } catch (DomainException) {
        return null;
    }
}

function pl_web_employees_post(int $actorId, int $companyId, array $company): void
{
    $return = pl_url('/employees');
    try {
        pl_web_assert_scope($company, $_POST);
    } catch (DomainException $error) {
        pl_form_failure($return, [], $error->getMessage());
    }
    try {
        $ownershipPartyRaw = pl_web_text($_POST, 'ownership_party_id');
        $employee = pl_save_employee($actorId, $companyId, [
            'full_name' => pl_web_text($_POST, 'full_name'),
            'date_of_birth' => pl_web_text($_POST, 'date_of_birth'),
            'national_identifier' => pl_web_text($_POST, 'national_identifier'),
            'email' => pl_web_text($_POST, 'email'),
            'phone' => pl_web_text($_POST, 'phone'),
            'address' => pl_web_text($_POST, 'address'),
            'job_title' => pl_web_text($_POST, 'job_title'),
            'department' => pl_web_text($_POST, 'department'),
            'employment_type' => pl_web_text($_POST, 'employment_type'),
            'employment_status' => pl_web_text($_POST, 'employment_status'),
            'status_effective_date' => pl_web_text($_POST, 'status_effective_date'),
            'hire_date' => pl_web_text($_POST, 'hire_date'),
            'termination_date' => pl_web_text($_POST, 'termination_date'),
            'termination_reason' => pl_web_text($_POST, 'termination_reason'),
            'personnel_number' => pl_web_text($_POST, 'personnel_number'),
            'pay_frequency' => pl_web_text($_POST, 'pay_frequency'),
            'basic_salary' => pl_web_text($_POST, 'basic_salary'),
            'pay_currency' => pl_web_text($_POST, 'pay_currency'),
            'ownership_party_id' => $ownershipPartyRaw === '' ? null : pl_web_id($_POST, 'ownership_party_id'),
            'note' => pl_web_text($_POST, 'note'),
            'reason' => pl_web_text($_POST, 'reason'),
        ], pl_web_id($_POST, 'id') ?: null, pl_web_id($_POST, 'id') ? pl_web_id($_POST, 'revision') : null);
        pl_notice(pl_t('Saved to the employee register.'));
        pl_redirect($return . '?id=' . $employee['id']);
    } catch (DomainException $error) {
        pl_form_failure($return, $_POST, $error->getMessage());
    }
}
