<?php
declare(strict_types=1);
/**
 * The employee master (1.3 M17, issue #98's blocker). Identity, employment status and dates,
 * job title, department and static pay terms only — no pay run, no payslip, that is #98.
 *
 * @var array $company @var array $user @var array $employees @var string $statusFilter
 * @var array $statuses @var array $types @var array $payFrequencies @var array $ownershipParties
 * @var bool $mayView @var bool $mayManage @var array|null $selected @var array $form @var array $input
 */
$hasFailure = pl_web_text($form, 'message') !== '';
$editing = $selected !== null;
?>
<p><a class="link" href="<?= pl_e(pl_url('/employees/links')) ?>"><?= pl_e(pl_t('Employee operational and trade links')) ?></a></p>
<section class="flex flex-col gap-4 py-5" aria-labelledby="employees-title">
    <div class="page-header">
        <div>
            <p class="eyebrow"><?= pl_e(pl_t('Setup')) ?></p>
            <h1 class="page-title" id="employees-title"><?= pl_e(pl_t('Employees')) ?></h1>
            <p class="muted"><?php if ($mayView): ?><?= pl_e(pl_tn('{count} employee is recorded.', '{count} employees are recorded.', count($employees), ['count' => count($employees)])) ?> <?php endif; ?>
                <?= pl_e(pl_t('This is the master record only: identity, employment status and dates, job title and static pay terms. Running payroll from it is separate, later work.')) ?></p>
        </div>
    </div>

    <?php if ($hasFailure): ?>
        <div class="alert alert-danger" role="alert" tabindex="-1" data-form-error>
            <h2 class="section-title mb-2"><?= pl_e(pl_t('This change needs attention')) ?></h2>
            <p><?= pl_e(pl_web_text($form, 'message')) ?></p>
        </div>
    <?php endif; ?>

    <?php if ($mayView): ?>
    <div class="rounded-panel border border-border bg-surface p-4">
        <div class="flex items-center justify-between gap-3 mb-2 flex-wrap">
            <h2 class="section-title"><?= pl_e(pl_t('The register')) ?></h2>
            <form method="get" action="<?= pl_e(pl_url('/employees')) ?>" class="flex items-center gap-2">
                <label class="field flex-row items-center gap-2"><?= pl_e(pl_t('Status')) ?>
                    <select class="select" name="status" onchange="this.form.submit()">
                        <option value=""><?= pl_e(pl_t('Any')) ?></option>
                        <?php foreach ($statuses as $value => $label): ?>
                            <option value="<?= pl_e($value) ?>" <?= $statusFilter === $value ? 'selected' : '' ?>><?= pl_e(pl_t($label)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </form>
        </div>
        <div class="table-wrap" tabindex="0" role="region" aria-label="<?= pl_e(pl_t('Employees')) ?>">
            <table class="table">
                <thead><tr>
                    <th scope="col"><?= pl_e(pl_t('Name')) ?></th>
                    <th scope="col"><?= pl_e(pl_t('Job title')) ?></th>
                    <th scope="col"><?= pl_e(pl_t('Status')) ?></th>
                    <th scope="col"><?= pl_e(pl_t('Hired')) ?></th>
                    <th scope="col"></th>
                </tr></thead>
                <tbody>
                <?php foreach ($employees as $row): ?>
                    <tr>
                        <td>
                            <span class="row-title"><?= pl_e((string) $row['full_name']) ?></span><span class="row-sub"><?= pl_e(pl_t('Register #{id}', ['id' => (int) $row['id']])) ?></span>
                            <?php if ($row['ownership_party_id'] !== null): ?><span class="row-sub"><?= pl_e(pl_t('Linked to the ownership register')) ?></span><?php endif; ?>
                        </td>
                        <td><?= pl_e((string) $row['job_title']) ?><?php if ($row['department'] !== ''): ?><span class="row-sub"><?= pl_e((string) $row['department']) ?></span><?php endif; ?></td>
                        <td><?php pl_ui_badge($row['employment_status'] === 'active' ? 'active' : ($row['employment_status'] === 'terminated' ? 'inactive' : 'due-soon'), (string) $row['status_label']); ?></td>
                        <td class="whitespace-nowrap"><?= pl_e((string) $row['hire_date']) ?></td>
                        <td class="text-end whitespace-nowrap"><a class="btn btn-ghost btn-sm" href="<?= pl_e(pl_url('/employees', ['id' => $row['id']])) ?>"><?= pl_e(pl_t('Manage')) ?></a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($employees === []): ?><tr><td colspan="5"><?= pl_e(pl_t('Nobody is recorded yet.')) ?></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php else: ?>
        <?php pl_ui_strip(pl_t('Employee records are restricted personal data. Your role cannot read the register.'), 'info'); ?>
    <?php endif; ?>

    <?php if ($mayView && !$mayManage && $selected !== null): ?>
    <div class="rounded-panel border border-border bg-surface p-4">
        <h2 class="section-title"><?= pl_e((string) $selected['full_name']) ?></h2><p class="muted"><?= pl_e(pl_t('Register #{id}', ['id' => (int) $selected['id']])) ?></p>
        <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <?php foreach (['personnel_number' => 'Personnel number', 'date_of_birth' => 'Date of birth', 'national_identifier' => 'National identifier', 'email' => 'Email', 'phone' => 'Phone', 'address' => 'Address', 'job_title' => 'Job title', 'department' => 'Department', 'type_label' => 'Employment type', 'status_label' => 'Employment status', 'status_effective_date' => 'Status effective date', 'hire_date' => 'Hire date', 'termination_date' => 'Termination date', 'termination_reason' => 'Termination reason', 'basic_salary' => 'Basic salary', 'pay_currency' => 'Pay currency', 'pay_frequency_label' => 'Pay frequency', 'note' => 'Note'] as $field => $label): ?>
                <div><dt class="field-hint"><?= pl_e(pl_t($label)) ?></dt><dd><?= pl_e((string) ($selected[$field] ?? '')) ?></dd></div>
            <?php endforeach; ?>
        </dl>
    </div>
    <?php endif; ?>

    <?php if ($mayManage): ?>
    <div class="rounded-panel border border-border bg-surface p-4" id="employee-form">
        <h2 class="section-title mb-2"><?= $editing ? pl_e(pl_t('Manage {name}', ['name' => (string) $selected['full_name']])) : pl_e(pl_t('Add an employee')) ?></h2>
        <?php if ($editing): ?><p class="muted"><?= pl_e(pl_t('Register #{id}', ['id' => (int) $selected['id']])) ?></p><?php endif; ?>
        <form class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2" method="post" action="<?= pl_e(pl_url('/employees')) ?>">
            <?= pl_csrf_field() ?><?= pl_scope_fields($company) ?>
            <?php if ($editing): ?>
                <input type="hidden" name="id" value="<?= (int) $selected['id'] ?>">
                <input type="hidden" name="revision" value="<?= (int) ($input['revision'] ?? $selected['revision']) ?>">
            <?php endif; ?>
            <div class="field"><label for="employee-name"><?= pl_e(pl_t('Full name')) ?></label><input class="input" id="employee-name" name="full_name" maxlength="160" required value="<?= pl_e((string) pl_web_text($input, 'full_name')) ?>"></div>
            <div class="field"><label for="employee-dob"><?= pl_e(pl_t('Date of birth')) ?></label><input class="input" type="date" id="employee-dob" name="date_of_birth" value="<?= pl_e((string) pl_web_text($input, 'date_of_birth')) ?>"></div>
            <div class="field"><label for="employee-national-id"><?= pl_e(pl_t('National identifier')) ?></label><input class="input" id="employee-national-id" name="national_identifier" maxlength="80" value="<?= pl_e((string) pl_web_text($input, 'national_identifier')) ?>"></div>
            <div class="field"><label for="employee-personnel-number"><?= pl_e(pl_t('Personnel number')) ?></label><input class="input" id="employee-personnel-number" name="personnel_number" maxlength="40" value="<?= pl_e((string) pl_web_text($input, 'personnel_number')) ?>"><span class="field-hint"><?= pl_e(pl_t('Optional. Must be unique in this company if given.')) ?></span></div>
            <div class="field"><label for="employee-email"><?= pl_e(pl_t('Email')) ?></label><input class="input" type="email" id="employee-email" name="email" maxlength="190" value="<?= pl_e((string) pl_web_text($input, 'email')) ?>"></div>
            <div class="field"><label for="employee-phone"><?= pl_e(pl_t('Phone')) ?></label><input class="input" id="employee-phone" name="phone" maxlength="40" value="<?= pl_e((string) pl_web_text($input, 'phone')) ?>"></div>
            <div class="field sm:col-span-2"><label for="employee-address"><?= pl_e(pl_t('Address')) ?></label><input class="input" id="employee-address" name="address" maxlength="400" value="<?= pl_e((string) pl_web_text($input, 'address')) ?>"></div>
            <div class="field"><label for="employee-job-title"><?= pl_e(pl_t('Job title')) ?></label><input class="input" id="employee-job-title" name="job_title" maxlength="120" value="<?= pl_e((string) pl_web_text($input, 'job_title')) ?>"></div>
            <div class="field"><label for="employee-department"><?= pl_e(pl_t('Department')) ?></label><input class="input" id="employee-department" name="department" maxlength="120" value="<?= pl_e((string) pl_web_text($input, 'department')) ?>"></div>
            <div class="field"><label for="employee-type"><?= pl_e(pl_t('Employment type')) ?></label>
                <select class="select" id="employee-type" name="employment_type" required>
                    <?php foreach ($types as $value => $label): ?><option value="<?= pl_e($value) ?>" <?= pl_web_text($input, 'employment_type') === $value ? 'selected' : '' ?>><?= pl_e(pl_t($label)) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="field"><label for="employee-status"><?= pl_e(pl_t('Employment status')) ?></label>
                <select class="select" id="employee-status" name="employment_status" required>
                    <?php foreach ($statuses as $value => $label): ?><option value="<?= pl_e($value) ?>" <?= pl_web_text($input, 'employment_status') === $value ? 'selected' : '' ?>><?= pl_e(pl_t($label)) ?></option><?php endforeach; ?>
                </select>
                <span class="field-hint"><?= pl_e(pl_t('Terminated needs a termination date below; every other status needs that date left empty.')) ?></span>
            </div>
            <div class="field"><label for="employee-hire-date"><?= pl_e(pl_t('Hire date')) ?></label><input class="input" type="date" id="employee-hire-date" name="hire_date" required <?= $editing ? 'readonly' : '' ?> value="<?= pl_e((string) pl_web_text($input, 'hire_date')) ?>"><?php if ($editing): ?><span class="field-hint"><?= pl_e(pl_t('Hire date is fixed once recorded to preserve employment history.')) ?></span><?php endif; ?></div>
            <div class="field"><label for="employee-status-date"><?= pl_e(pl_t('Status effective date')) ?></label><input class="input" type="date" id="employee-status-date" name="status_effective_date" value="<?= pl_e((string) pl_web_text($input, 'status_effective_date')) ?>"><span class="field-hint"><?= pl_e(pl_t('Defaults to the hire date for a new record. Set the date of each status change; termination uses the same date as termination below.')) ?></span></div>
            <div class="field"><label for="employee-termination-date"><?= pl_e(pl_t('Termination date')) ?></label><input class="input" type="date" id="employee-termination-date" name="termination_date" value="<?= pl_e((string) pl_web_text($input, 'termination_date')) ?>"></div>
            <div class="field sm:col-span-2"><label for="employee-termination-reason"><?= pl_e(pl_t('Termination reason')) ?></label><input class="input" id="employee-termination-reason" name="termination_reason" maxlength="300" value="<?= pl_e((string) pl_web_text($input, 'termination_reason')) ?>"></div>
            <div class="field"><label for="employee-pay-frequency"><?= pl_e(pl_t('Pay frequency')) ?></label>
                <select class="select" id="employee-pay-frequency" name="pay_frequency">
                    <option value=""><?= pl_e(pl_t('Not set')) ?></option>
                    <?php foreach ($payFrequencies as $value => $label): ?><option value="<?= pl_e($value) ?>" <?= pl_web_text($input, 'pay_frequency') === $value ? 'selected' : '' ?>><?= pl_e(pl_t($label)) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="field"><label for="employee-basic-salary"><?= pl_e(pl_t('Basic salary')) ?></label><input class="input" id="employee-basic-salary" name="basic_salary" inputmode="decimal" value="<?= pl_e((string) pl_web_text($input, 'basic_salary')) ?>"></div>
            <div class="field"><label for="employee-pay-currency"><?= pl_e(pl_t('Pay currency')) ?></label><input class="input" id="employee-pay-currency" name="pay_currency" maxlength="3" placeholder="<?= pl_e(pl_t('USD')) ?>" value="<?= pl_e((string) pl_web_text($input, 'pay_currency')) ?>"></div>
            <span class="field-hint sm:col-span-2"><?= pl_e(pl_t('Pay frequency, basic salary and pay currency go together: fill in all three, or leave all three empty. This is what a payroll module reads to know what someone is paid per period; it is never what they were actually paid.')) ?></span>
            <div class="field sm:col-span-2"><label for="employee-ownership-party"><?= pl_e(pl_t('Also recorded in the ownership register as')) ?></label>
                <select class="select" id="employee-ownership-party" name="ownership_party_id">
                    <option value=""><?= pl_e(pl_t('Not linked')) ?></option>
                    <?php foreach ($ownershipParties as $party): ?><option value="<?= (int) $party['id'] ?>" <?= (string) ($input['ownership_party_id'] ?? '') === (string) $party['id'] ? 'selected' : '' ?>><?= pl_e((string) $party['name']) ?></option><?php endforeach; ?>
                </select>
                <span class="field-hint"><?= pl_e(pl_t('Only set this if the same person is genuinely a director, officer or member. It is recorded here explicitly and never inferred, and it does not by itself mark anybody as a related party — that is a separate, deliberate act on the Ownership register screen.')) ?></span>
            </div>
            <div class="field sm:col-span-2"><label for="employee-note"><?= pl_e(pl_t('Note')) ?></label><input class="input" id="employee-note" name="note" maxlength="1000" value="<?= pl_e((string) pl_web_text($input, 'note')) ?>"></div>
            <div class="field sm:col-span-2"><label for="employee-reason"><?= pl_e(pl_t('Reason for this change')) ?></label><input class="input" id="employee-reason" name="reason" maxlength="500" required value="<?= pl_e((string) pl_web_text($input, 'reason')) ?>"></div>
            <div class="panel-actions sm:col-span-2"><button class="btn btn-primary" type="submit"><?= pl_e($editing ? pl_t('Save changes') : pl_t('Add employee')) ?></button>
                <?php if ($editing): ?><a class="btn btn-secondary" href="<?= pl_e(pl_url('/employees')) ?>"><?= pl_e(pl_t('Add another instead')) ?></a><?php endif; ?>
            </div>
        </form>
    </div>
    <?php else: ?>
        <?php pl_ui_strip(pl_demo_enabled() ? pl_t('The employee register cannot be changed in the public demo.') : pl_t('Your role can read the employee register but not maintain it.'), 'info'); ?>
    <?php endif; ?>
</section>
