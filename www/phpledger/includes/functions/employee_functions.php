<?php
declare(strict_types=1);

/**
 * The employee master (1.3 M17, issue #98's blocker; owner decisions B70, B71 as amended by
 * B76, B72 as narrowed by B74, B58).
 *
 * WHERE THE MASTER ENDS AND PAYROLL (#98) BEGINS
 * ------------------------------------------------
 * This file owns identity, employment status and dates, job title and department, and static
 * pay terms (basic salary, currency, frequency) as reference data. It never computes a wage,
 * never runs a pay period, never withholds a statutory deduction and never produces a payslip.
 * The boundary is the same one B70 draws for the sync contract: a payroll module gets a stable
 * `id` from `pl_get_employee()` / `pl_list_employees()`, an effective-dated employment status it
 * can read as of any date, and the right to keep its own tables (B46) keyed to that id. It may
 * never mint its own employee identity, and this file never reaches into a payroll table.
 *
 * WHAT B71/B76 SETTLE ABOUT THE SHAPE
 * ------------------------------------
 * An employee is never a `pl_parties` row: `ck_party_roles` requires a customer or a vendor, and
 * an employee is neither by default (B71). No employee flag exists anywhere near that table.
 * B76's explicit-trade-link rule (rule 2) is about a genuine sale to an employee becoming a
 * trade receivable; it belongs to whichever screen records that trade relationship, not to this
 * master, and nothing here builds it.
 *
 * `ownership_party_id` is the **other** explicit link this milestone was asked to carry: when
 * the same human is also recorded in the ownership register (a director, an officer, a member),
 * that fact is set once, by a person, on this column. Nothing computes it, nothing infers it
 * from a name match, and setting it never creates, implies or back-fills a related-party marker
 * — that marker is B72 as narrowed by B74, entirely unchanged by this file except for the one
 * extension below.
 *
 * THE ONE RELATED-PARTY CHANGE THIS MILESTONE MAKES
 * -----------------------------------------------------
 * `pl_related_party_markers.related_register` has allowed `'employee'` since migration 044
 * shipped, on purpose — B72's design was "one mechanism serves all three registers" and the
 * employee register was always going to arrive later. `pl_related_party_subject()` in
 * `ownership_functions.php` is extended, in this same change, to resolve it. That is the entire
 * change: no new relationship kind, no new way for a marker to be created, and nothing that
 * reads an employee's status to decide who counts as related. An employee becomes related the
 * same way an officer or a member does — because somebody with `relatedparty.manage` said so,
 * naming key management personnel, a close family member of one, or an entity either controls —
 * and nothing about being an employee makes that more or less likely to happen automatically,
 * because nothing automatic happens at all.
 *
 * PERSONAL DATA IS SENSITIVE (B58)
 * -----------------------------------
 * Reading and maintaining the register are split exactly the way the related-party marker split
 * `relatedparty.view` from `relatedparty.manage`: `employee.view` for a read (name, status,
 * dates, job title, pay terms — all of it, because a payslip approver needs the pay terms to
 * mean anything), `employee.manage` together with the read grant for a write. Neither one is granted to the system `viewer`
 * or `accountant` roles by default; only `owner` gets every company-scoped capability, exactly
 * as every other capability in the catalogue does.
 */

/** @return array<string,string> */
function pl_employment_types(): array
{
    return [
        'full_time' => 'Full time',
        'part_time' => 'Part time',
        'contract' => 'Contract',
        'casual' => 'Casual',
    ];
}

/** @return array<string,string> */
function pl_employment_statuses(): array
{
    return [
        'active' => 'Active',
        'on_leave' => 'On leave',
        'suspended' => 'Suspended',
        'terminated' => 'Terminated',
    ];
}

/** @return array<string,string> */
function pl_pay_frequencies(): array
{
    return [
        'monthly' => 'Monthly',
        'semi_monthly' => 'Semi-monthly',
        'biweekly' => 'Biweekly',
        'weekly' => 'Weekly',
        'daily' => 'Daily',
    ];
}

/** The authority B58 reserves for personal data: a read. */
function pl_employee_require_view(int $actorId, int $companyId): void
{
    pl_require_company_access($actorId, $companyId);
    if (!pl_user_can($actorId, $companyId, 'employee.view')) {
        throw new DomainException('The employee register is restricted. Your role cannot read it.');
    }
}

/** The authority B58 reserves for personal data: a write. Called inside the owned transaction. */
function pl_employee_require_manage(int $actorId, int $companyId): void
{
    if (!pl_user_can($actorId, $companyId, 'employee.manage')) {
        throw new DomainException('Your role cannot maintain the employee register.');
    }
}

function pl_employee_audit(int $actorId, int $companyId, int $id, string $action, string $reason, ?array $before, array $after): void
{
    DB::insert('pl_employee_audit', [
        'company_id' => $companyId, 'actor_id' => $actorId, 'entity_type' => 'employee', 'entity_id' => $id,
        'action' => $action, 'reason' => $reason,
        'before_state' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        'after_state' => json_encode($after, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
    ]);
}

/**
 * Every write here emits after the outermost commit, with every lock released, the same seam
 * `pl_ownership_emit()` uses and for the same reason: a hook raised inside `pl_ledger_transaction()`
 * is refused by `pl_hook_assert_unlocked()`.
 *
 * @param array<string,mixed> $payload
 */
function pl_employee_emit(string $hook, array $payload): void
{
    if (function_exists('pl_hook_after_commit')) {
        pl_hook_after_commit($hook, [$payload]);
    }
}

/** @param array<string,mixed> $row */
function pl_employee_view(array $row): array
{
    foreach (['id', 'company_id', 'revision', 'created_by'] as $field) { $row[$field] = (int) $row[$field]; }
    $row['trade_party_id'] = ($row['trade_party_id'] ?? null) === null ? null : (int) $row['trade_party_id'];
    $row['ownership_party_id'] = $row['ownership_party_id'] === null ? null : (int) $row['ownership_party_id'];
    $row['status_label'] = pl_employment_statuses()[(string) $row['employment_status']] ?? (string) $row['employment_status'];
    $row['type_label'] = pl_employment_types()[(string) $row['employment_type']] ?? (string) $row['employment_type'];
    $row['pay_frequency_label'] = $row['pay_frequency'] === null ? '' : (pl_pay_frequencies()[(string) $row['pay_frequency']] ?? (string) $row['pay_frequency']);
    return $row;
}

/**
 * Every employee, most recently hired first. `$statusFilter` narrows to one status (for example
 * a payroll module asking only for `active`); omit it for the whole register.
 *
 * @return array<int,array<string,mixed>>
 */
function pl_list_employees(int $actorId, int $companyId, ?string $statusFilter = null): array
{
    pl_employee_require_view($actorId, $companyId);
    $sql = 'SELECT * FROM pl_employees WHERE company_id = %i';
    $args = [$companyId];
    if ($statusFilter !== null) {
        if (!isset(pl_employment_statuses()[$statusFilter])) {
            throw new DomainException('Unknown employment status.');
        }
        $sql .= ' AND employment_status = %s';
        $args[] = $statusFilter;
    }
    $rows = DB::query($sql . ' ORDER BY hire_date DESC, full_name', ...$args);
    return array_map('pl_employee_view', $rows);
}

function pl_get_employee(int $actorId, int $companyId, int $id): array
{
    pl_employee_require_view($actorId, $companyId);
    $row = DB::queryFirstRow('SELECT * FROM pl_employees WHERE id = %i AND company_id = %i', $id, $companyId);
    if (!$row) {
        throw new DomainException('That employee is not in this company\'s register.');
    }
    return pl_employee_view($row);
}

/**
 * Record or amend an employee. Create and edit share one validation path, the same way
 * `pl_save_ownership_officer()` does: a status change is just a field on the ordinary save,
 * kept coherent with the termination date by the same rule the schema's CHECK enforces.
 *
 * @param array<string,mixed> $input untrusted request data
 */
function pl_save_employee(int $actorId, int $companyId, array $input, ?int $id = null, ?int $revision = null): array
{
    pl_employee_require_view($actorId, $companyId);
    pl_employee_require_manage($actorId, $companyId);
    pl_demo_require_setup_action();
    $reason = pl_ledger_text($input['reason'] ?? null, 'Reason for this change', 500);

    $fullName = pl_ledger_text($input['full_name'] ?? null, 'Name', 160);
    $dobRaw = pl_ledger_text($input['date_of_birth'] ?? '', 'Date of birth', 10, false);
    $dob = $dobRaw === '' ? null : pl_ledger_date($dobRaw);
    $nationalId = pl_ledger_text($input['national_identifier'] ?? '', 'National identifier', 80, false);
    $email = pl_ledger_text($input['email'] ?? '', 'Email', 190, false);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new DomainException('Enter a valid email address, or leave it empty.');
    }
    $phone = pl_ledger_text($input['phone'] ?? '', 'Phone', 40, false);
    $address = pl_ledger_text($input['address'] ?? '', 'Address', 400, false);
    $jobTitle = pl_ledger_text($input['job_title'] ?? '', 'Job title', 120, false);
    $department = pl_ledger_text($input['department'] ?? '', 'Department', 120, false);

    $type = is_string($input['employment_type'] ?? null) ? $input['employment_type'] : '';
    if (!isset(pl_employment_types()[$type])) { throw new DomainException('Choose an employment type.'); }
    $status = is_string($input['employment_status'] ?? null) ? $input['employment_status'] : '';
    if (!isset(pl_employment_statuses()[$status])) { throw new DomainException('Choose an employment status.'); }

    $hireDate = pl_ledger_date(pl_ledger_text($input['hire_date'] ?? null, 'Hire date', 10));
    $effectiveRaw = pl_ledger_text($input['status_effective_date'] ?? '', 'Status effective date', 10, false);
    $effectiveDate = $effectiveRaw === '' ? $hireDate : pl_ledger_date($effectiveRaw);
    if ($effectiveDate < $hireDate) { throw new DomainException('Status cannot take effect before employment begins.'); }
    $terminationRaw = pl_ledger_text($input['termination_date'] ?? '', 'Termination date', 10, false);
    $terminationDate = $terminationRaw === '' ? null : pl_ledger_date($terminationRaw);
    if (($status === 'terminated') !== ($terminationDate !== null)) {
        throw new DomainException('A termination date and the terminated status go together: set both, or neither.');
    }
    if ($terminationDate !== null && $terminationDate < $hireDate) {
        throw new DomainException('Employment cannot end before it begins.');
    }
    if ($terminationDate !== null && $effectiveDate !== $terminationDate) {
        throw new DomainException('The terminated status must take effect on the termination date.');
    }
    $terminationReason = pl_ledger_text($input['termination_reason'] ?? '', 'Termination reason', 300, false);

    $personnelRaw = pl_ledger_text($input['personnel_number'] ?? '', 'Personnel number', 40, false);
    $personnelNumber = $personnelRaw === '' ? null : $personnelRaw;

    $payFrequencyRaw = is_string($input['pay_frequency'] ?? null) ? $input['pay_frequency'] : '';
    $salaryRaw = pl_ledger_text($input['basic_salary'] ?? '', 'Basic salary', 21, false);
    $currencyRaw = strtoupper(pl_ledger_text($input['pay_currency'] ?? '', 'Pay currency', 3, false));
    $basicSalary = null;
    $payCurrency = null;
    $payFrequency = null;
    if ($salaryRaw !== '' || $currencyRaw !== '' || $payFrequencyRaw !== '') {
        if (!preg_match('/^\d{1,16}(\.\d{1,4})?$/D', $salaryRaw) || bccomp($salaryRaw, '0', 4) < 0) {
            throw new DomainException('Enter a basic salary of zero or more, or leave all three pay fields empty.');
        }
        if (!preg_match('/^[A-Z]{3}$/D', $currencyRaw)) {
            throw new DomainException('Enter a three-letter pay currency code, or leave all three pay fields empty.');
        }
        if (!isset(pl_pay_frequencies()[$payFrequencyRaw])) {
            throw new DomainException('Choose a pay frequency, or leave all three pay fields empty.');
        }
        $basicSalary = bcadd($salaryRaw, '0', 4);
        $payCurrency = $currencyRaw;
        $payFrequency = $payFrequencyRaw;
    }

    $ownershipPartyRaw = $input['ownership_party_id'] ?? null;
    $ownershipPartyId = null;
    if ($ownershipPartyRaw !== null && $ownershipPartyRaw !== '') {
        if (!is_int($ownershipPartyRaw) || $ownershipPartyRaw < 1) {
            throw new DomainException('Choose a valid entry in the ownership register, or leave the link empty.');
        }
        $ownershipPartyId = $ownershipPartyRaw;
    }

    $note = pl_ledger_text($input['note'] ?? '', 'Note', 1000, false);

    $data = [
        'ownership_party_id' => $ownershipPartyId, 'personnel_number' => $personnelNumber,
        'full_name' => $fullName, 'date_of_birth' => $dob, 'national_identifier' => $nationalId,
        'email' => $email, 'phone' => $phone, 'address' => $address,
        'job_title' => $jobTitle, 'department' => $department,
        'employment_type' => $type, 'employment_status' => $status,
        'hire_date' => $hireDate, 'status_effective_date' => $effectiveDate, 'termination_date' => $terminationDate, 'termination_reason' => $terminationReason,
        'pay_frequency' => $payFrequency, 'basic_salary' => $basicSalary, 'pay_currency' => $payCurrency,
        'note' => $note,
    ];

    return pl_ledger_transaction(function () use ($actorId, $companyId, $data, $id, $revision, $reason): array {
        pl_require_company_access($actorId, $companyId, true);
        pl_employee_require_view($actorId, $companyId);
        pl_employee_require_manage($actorId, $companyId);
        if ($data['ownership_party_id'] !== null) {
            $linked = DB::queryFirstRow('SELECT id, kind FROM pl_ownership_parties WHERE id = %i AND company_id = %i FOR SHARE', $data['ownership_party_id'], $companyId);
            if ($linked && $linked['kind'] !== 'person') { throw new DomainException('An employee can only link to a person in the ownership register.'); }
            if (!$linked) { throw new DomainException('That entry is not in this company\'s ownership register.'); }
        }
        if ($data['personnel_number'] !== null) {
            $clash = DB::queryFirstField('SELECT id FROM pl_employees WHERE company_id = %i AND personnel_number = %s AND id != %i',
                $companyId, $data['personnel_number'], $id ?? 0);
            if ($clash !== null) { throw new DomainException('That personnel number is already in use in this company.'); }
        }
        if ($id === null) {
            DB::insert('pl_employees', $data + ['company_id' => $companyId, 'created_by' => $actorId]);
            $id = (int) DB::insertId();
            $before = null;
            $action = 'created';
        } else {
            $existing = DB::queryFirstRow('SELECT * FROM pl_employees WHERE id = %i AND company_id = %i FOR UPDATE', $id, $companyId);
            if (!$existing) { throw new DomainException('That employee is not in this company\'s register.'); }
            if ($revision !== (int) $existing['revision']) {
                throw new DomainException('Someone changed this employee\'s record. Reload the latest version before applying your changes.');
            }
            if ($data['hire_date'] !== $existing['hire_date']) {
                throw new DomainException('The hire date is fixed once recorded; employment history must remain coherent.');
            }
            if ($data['status_effective_date'] < $existing['status_effective_date']) {
                throw new DomainException('A status change cannot precede the last recorded effective date.');
            }
            if ($data['employment_status'] === $existing['employment_status'] && $data['status_effective_date'] !== $existing['status_effective_date']) {
                throw new DomainException('Keep the effective date unchanged unless the employment status changes.');
            }
            $before = pl_employee_view($existing);
            DB::update('pl_employees', $data + ['revision' => (int) $existing['revision'] + 1, 'updated_at' => gmdate('Y-m-d H:i:s')],
                'id = %i AND company_id = %i', $id, $companyId);
            $action = $data['employment_status'] === 'terminated' && $before['employment_status'] !== 'terminated' ? 'terminated' : 'updated';
        }
        $row = DB::queryFirstRow('SELECT * FROM pl_employees WHERE id = %i AND company_id = %i', $id, $companyId);
        $after = pl_employee_view($row);
        pl_employee_audit($actorId, $companyId, $id, $action, $reason, $before, $after);
        if ($action === 'created') {
            pl_employee_emit('employee.created', ['company_id' => $companyId, 'employee' => $after]);
        } elseif ($action === 'terminated') {
            pl_employee_emit('employee.terminated', ['company_id' => $companyId, 'employee' => $after]);
        } else {
            pl_employee_emit('employee.updated', ['company_id' => $companyId, 'employee' => $after]);
        }
        return $after;
    });
}

/**
 * B70's in-process sync contract. Returns only employment status, effective date and stable id;
 * this is not a historical salary read. Same-date corrections use the last audited revision.
 * Null means the as-of date predates the first recorded status; no earlier status is invented.
 */
function pl_employee_status_at(int $actorId, int $companyId, int $id, string $asOf): ?array
{
    pl_get_employee($actorId, $companyId, $id);
    $asOf = pl_ledger_date($asOf);
    $rows = DB::query('SELECT after_state FROM pl_employee_audit WHERE company_id = %i AND entity_id = %i ORDER BY id DESC', $companyId, $id);
    foreach ($rows as $row) {
        $state = json_decode((string) $row['after_state'], true, 64, JSON_THROW_ON_ERROR);
        if ($state['status_effective_date'] <= $asOf) {
            return ['id' => $id, 'employment_status' => $state['employment_status'], 'status_effective_date' => $state['status_effective_date']];
        }
    }
    return null;
}
