<?php
declare(strict_types=1);

/**
 * The employee master (1.3 M17, issue #98's blocker; owner decisions B70, B71 as amended by
 * B76, B72 as narrowed by B74).
 *
 * B70 revises B69 (which had put employees outside the core): the core currently records the
 * same person in three unconnected places — `pl_sales_staff` (migration 037), a free-text
 * `driver_name` on a mobile warehouse (migration 038), and `pl_users` — and a fourth register
 * for payroll would have made it worse. This migration is the one place a person who works for
 * the business is recorded. Resolving `pl_sales_staff` and `driver_name` onto it is named by B70
 * as later work, not part of landing this table, and is deliberately not touched here.
 *
 * Four things this migration deliberately does and does not do.
 *
 * 1. `pl_employees` is company-scoped, not book-scoped, for the same reason migration 044 gave
 *    for the ownership register: `pl_core_audit.book_id` is NOT NULL and nothing an employee
 *    record holds belongs to one book. So this migration does **not** touch the `pl_core_audit`
 *    `entity_type` ENUM at all — there is no union to collide with migrations 037/038 over, and
 *    no need to renumber past a Wave 2 sibling's ENUM change either.
 *
 * 2. **It is its own table, never `pl_parties`.** B71 as amended by B76 keeps the separation:
 *    `pl_parties` carries `ck_party_roles`, which requires a party to be a customer or a vendor,
 *    and an employee is neither by default. No employee flag is ever added to `pl_parties`, and
 *    no column here references `pl_parties` at all — B76 rule 2's "explicit link where the same
 *    person genuinely trades" is deliberately left for whichever screen records that trade
 *    relationship; it is not part of the master record this migration builds.
 *
 * 3. **The link to the ownership register is a column, not an inference.** `ownership_party_id`
 *    is nullable, carries no default derivation and no trigger — a human sets it, or does not.
 *    If an employee is also a director or a shareholder, that fact is recorded once, here, and
 *    read wherever it is needed; nothing about being an employee, and nothing about setting this
 *    column, creates, implies or back-fills a related-party marker. That marker stays exactly
 *    what migration 044 built: a separate, affirmative act behind `relatedparty.manage`.
 *
 * 4. **`related_register` on `pl_related_party_markers` already allows `'employee'`** — migration
 *    044 shipped the ENUM value on day one, precisely so this migration could add the table it
 *    points at without an ALTER. `ownership_functions.php`'s `pl_related_party_subject()` is
 *    extended in this same change to resolve it, which is the only edit this milestone makes to
 *    the related-party mechanism: no new relationship kind, no new marker source, nothing that
 *    reads an employee's status to decide who is related. A marker naming an employee is exactly
 *    as affirmative, and exactly as rare by default, as one naming an officer or a member.
 *
 * What the table holds, and why the boundary with payroll (#98) sits here: identity (name, date
 * of birth, a national identifier, contact details), employment status and dates (hire, optional
 * termination with a reason, and a status the same CHECK keeps coherent with the termination
 * date), job title and department, and **static pay terms** — basic salary, its currency and pay
 * frequency — as reference data a payroll module reads. Nothing here computes a wage, runs a pay
 * period, withholds a statutory deduction or produces a payslip; #98 owns all of that, keyed to
 * this table's `id`, which is the stable identity B70 asks the sync contract to offer. A plugin
 * keeps its own tables (B46) against that id and never mints a second employee identity.
 *
 * Personal data is sensitive (B58): reading and maintaining the register are split into two
 * capabilities (`employee.view`, `employee.manage`) the same way the related-party marker split
 * `relatedparty.view` from `relatedparty.manage`, registered in `capability_functions.php`.
 *
 * Reversal path, in full:
 *
 *   DROP TRIGGER pl_employee_audit_no_update; DROP TRIGGER pl_employee_audit_no_delete;
 *   DROP TABLE pl_employee_audit, pl_employees;
 *
 * Nothing here alters a posted journal, a journal line, a book row, an account or any accounting
 * amount, and no other table is touched.
 */

return [
    // -------------------------------------------------------------------- 1. the employee master
    <<<'SQL'
CREATE TABLE pl_employees (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    ownership_party_id BIGINT UNSIGNED NULL,
    personnel_number VARCHAR(40) NULL,
    full_name VARCHAR(160) NOT NULL,
    date_of_birth DATE NULL,
    national_identifier VARCHAR(80) NOT NULL DEFAULT '',
    email VARCHAR(190) NOT NULL DEFAULT '',
    phone VARCHAR(40) NOT NULL DEFAULT '',
    address VARCHAR(400) NOT NULL DEFAULT '',
    job_title VARCHAR(120) NOT NULL DEFAULT '',
    department VARCHAR(120) NOT NULL DEFAULT '',
    employment_type ENUM('full_time','part_time','contract','casual') NOT NULL DEFAULT 'full_time',
    employment_status ENUM('active','on_leave','suspended','terminated') NOT NULL DEFAULT 'active',
    hire_date DATE NOT NULL,
    status_effective_date DATE NOT NULL,
    termination_date DATE NULL,
    termination_reason VARCHAR(300) NOT NULL DEFAULT '',
    -- Static reference data for a payroll module to read, never a pay run: what the person is
    -- paid per period, not what they were actually paid in any period that has happened.
    pay_frequency ENUM('monthly','semi_monthly','biweekly','weekly','daily') NULL,
    basic_salary DECIMAL(20,4) NULL,
    pay_currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NULL,
    note VARCHAR(1000) NOT NULL DEFAULT '',
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_employee_scope (id, company_id),
    UNIQUE KEY uq_employee_personnel_number (company_id, personnel_number),
    KEY ix_employee_company (company_id, employment_status, full_name),
    KEY ix_employee_ownership_party (ownership_party_id, company_id),
    -- A termination date and the terminated status are the same fact, told twice for two
    -- different readers (a report filters on the ENUM, a reconciliation reads the date), and the
    -- CHECK is what keeps them from ever disagreeing. The same pairing pattern migration 044
    -- used for a share event's journal_id and book_id.
    CONSTRAINT ck_employee_status_termination CHECK ((employment_status = 'terminated') = (termination_date IS NOT NULL)),
    CONSTRAINT ck_employee_status_date CHECK (status_effective_date >= hire_date AND (termination_date IS NULL OR status_effective_date = termination_date)),
    CONSTRAINT ck_employee_dates CHECK (termination_date IS NULL OR termination_date >= hire_date),
    CONSTRAINT ck_employee_salary CHECK (basic_salary IS NULL OR basic_salary >= 0),
    CONSTRAINT ck_employee_pay_currency CHECK ((basic_salary IS NULL) = (pay_currency IS NULL)),
    CONSTRAINT ck_employee_pay_frequency CHECK ((basic_salary IS NULL) = (pay_frequency IS NULL)),
    CONSTRAINT ck_employee_revision CHECK (revision > 0),
    CONSTRAINT fk_employee_company FOREIGN KEY (company_id) REFERENCES pl_companies (id),
    CONSTRAINT fk_employee_ownership_party FOREIGN KEY (ownership_party_id, company_id) REFERENCES pl_ownership_parties (id, company_id),
    CONSTRAINT fk_employee_actor FOREIGN KEY (created_by) REFERENCES pl_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,

    // ---------------------------------------------------------- 2. the register's immutable audit
    <<<'SQL'
CREATE TABLE pl_employee_audit (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    entity_type ENUM('employee') NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(40) NOT NULL,
    reason VARCHAR(500) NOT NULL DEFAULT '',
    before_state JSON NULL,
    after_state JSON NOT NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_employee_audit_entity (company_id, entity_type, entity_id, id),
    KEY ix_employee_audit_company (company_id, id),
    CONSTRAINT fk_employee_audit_company FOREIGN KEY (company_id) REFERENCES pl_companies (id),
    CONSTRAINT fk_employee_audit_actor FOREIGN KEY (actor_id) REFERENCES pl_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
    "CREATE TRIGGER pl_employee_audit_no_update BEFORE UPDATE ON pl_employee_audit FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Employee register audit records are immutable'",
    "CREATE TRIGGER pl_employee_audit_no_delete BEFORE DELETE ON pl_employee_audit FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Employee register audit records cannot be deleted'",
];
