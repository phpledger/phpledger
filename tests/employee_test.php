<?php
declare(strict_types=1);

function employee_fixture(): array
{
    $suffix = bin2hex(random_bytes(6));
    $email = 'employee-' . $suffix . '@example.invalid';
    $password = 'Sample-employee-password-' . $suffix;
    $actor = pl_create_user($email, 'Sample employee owner', $password);
    $fixture = ['actor_id' => $actor, 'email' => $email, 'password' => $password]
        + pl_create_company($actor, 'Sample employee company ' . $suffix, 'USD', '2026-01-01');
    pl_capability_cache_reset();
    return $fixture;
}

function employee_input(array $overrides = []): array
{
    return $overrides + ['full_name' => 'Sample Employee', 'employment_type' => 'full_time',
        'employment_status' => 'active', 'hire_date' => '2026-01-01', 'reason' => 'Sample employment record.'];
}

function employee_member(array $f, array $capabilities, string $slug = 'viewer'): int
{
    $user = pl_create_user('employee-reader-' . bin2hex(random_bytes(6)) . '@example.invalid', 'Sample employee reader', 'Sample-reader-password-123');
    sample_membership_insert(['company_id' => $f['company_id'], 'user_id' => $user, 'role' => $slug,
        'role_id' => (int) DB::queryFirstField('SELECT id FROM pl_roles WHERE company_id IS NULL AND slug = %s', $slug)]);
    if ($capabilities !== []) {
        $role = pl_save_role($f['actor_id'], $f['company_id'], ['name' => 'Sample employee role ' . $user,
            'description' => '', 'reason' => 'Sample permission split.', 'capabilities' => $capabilities]);
        pl_assign_company_role($f['actor_id'], $f['company_id'], $user, (int) $role['id'], 'Sample grant.');
    }
    pl_capability_cache_reset();
    return $user;
}

test('employee master keeps exact static pay, has no trade identity or postings, and no inferred related party', function (): void {
    $f = employee_fixture();
    $journals = DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE company_id = %i', $f['company_id']);
    $employee = pl_save_employee($f['actor_id'], $f['company_id'], employee_input(['basic_salary' => '1234567890123456.1234', 'pay_currency' => 'usd', 'pay_frequency' => 'monthly']));
    assert_same('1234567890123456.1234', $employee['basic_salary']);
    assert_same('USD', $employee['pay_currency']);
    assert_same(1, $employee['revision']);
    assert_same(null, $employee['ownership_party_id']);
    assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_parties WHERE company_id = %i', $f['company_id']));
    assert_same([], pl_list_related_party_markers($f['actor_id'], $f['company_id']));
    assert_same($journals, DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE company_id = %i', $f['company_id']));
});

test('employee employment history resolves effective dates, termination and same-day correction', function (): void {
    $f = employee_fixture();
    $save = fn(array $data, ?int $id = null, ?int $revision = null): array => pl_save_employee($f['actor_id'], $f['company_id'], employee_input($data), $id, $revision);
    $row = $save([]);
    $row = $save(['employment_status' => 'on_leave', 'status_effective_date' => '2026-02-01'], $row['id'], $row['revision']);
    $row = $save(['employment_status' => 'suspended', 'status_effective_date' => '2026-02-01'], $row['id'], $row['revision']);
    $row = $save(['employment_status' => 'terminated', 'status_effective_date' => '2026-03-01', 'termination_date' => '2026-03-01'], $row['id'], $row['revision']);
    assert_same(null, pl_employee_status_at($f['actor_id'], $f['company_id'], $row['id'], '2025-12-31'));
    foreach (['2026-01-31' => 'active', '2026-02-01' => 'suspended', '2026-02-28' => 'suspended', '2026-03-01' => 'terminated'] as $date => $status) {
        assert_same($status, pl_employee_status_at($f['actor_id'], $f['company_id'], $row['id'], $date)['employment_status']);
    }
    assert_same(0, count(pl_list_employees($f['actor_id'], $f['company_id'], 'active')));
    assert_same(1, count(pl_list_employees($f['actor_id'], $f['company_id'], 'terminated')));
    assert_throws(fn () => $save(['employment_status' => 'active', 'status_effective_date' => '2026-02-15'], $row['id'], $row['revision']), DomainException::class, 'precede');
    assert_throws(fn () => $save(['hire_date' => '2025-01-01'], $row['id'], $row['revision']), DomainException::class, 'hire date is fixed');
});

test('employee validates employment dates, salary tuple, identity and enums atomically', function (): void {
    $f = employee_fixture();
    foreach ([['employment_status' => 'unknown'], ['employment_type' => 'unknown'], ['hire_date' => '2026-02-30'],
        ['employment_status' => 'terminated'], ['termination_date' => '2026-02-01'],
        ['employment_status' => 'terminated', 'termination_date' => '2025-12-31'],
        ['employment_status' => 'terminated', 'termination_date' => '2026-02-01', 'status_effective_date' => '2026-02-02'],
        ['status_effective_date' => '2025-12-31'], ['email' => 'invalid'], ['full_name' => ''],
        ['basic_salary' => '-1', 'pay_currency' => 'USD', 'pay_frequency' => 'monthly'], ['basic_salary' => '2'],
        ['basic_salary' => '1.12345', 'pay_currency' => 'USD', 'pay_frequency' => 'monthly'],
        ['basic_salary' => '2', 'pay_currency' => 'US', 'pay_frequency' => 'monthly'],
        ['basic_salary' => '2', 'pay_currency' => 'USD', 'pay_frequency' => 'unknown']] as $bad) {
        assert_throws(fn () => pl_save_employee($f['actor_id'], $f['company_id'], employee_input($bad)), DomainException::class);
    }
    assert_same([], pl_list_employees($f['actor_id'], $f['company_id']));
    assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_employee_audit WHERE company_id = %i', $f['company_id']));
});

test('employee access excludes ordinary roles, permits separate reads, and prevents manage-only data access', function (): void {
    $f = employee_fixture();
    $row = pl_save_employee($f['actor_id'], $f['company_id'], employee_input());
    foreach (['viewer', 'accountant'] as $slug) {
        $actor = employee_member($f, [], $slug);
        assert_throws(fn () => pl_get_employee($actor, $f['company_id'], $row['id']), DomainException::class);
        assert_throws(fn () => pl_save_employee($actor, $f['company_id'], employee_input()), DomainException::class);
    }
    $reader = employee_member($f, ['company.read', 'employee.view']);
    assert_same($row['id'], pl_get_employee($reader, $f['company_id'], $row['id'])['id']);
    assert_throws(fn () => pl_save_employee($reader, $f['company_id'], employee_input()), DomainException::class);
    $writer = employee_member($f, ['company.read', 'company.write', 'employee.manage']);
    assert_throws(fn () => pl_save_employee($writer, $f['company_id'], employee_input(), $row['id'], 1), DomainException::class);
});

test('employee birth date is optional and must not follow today or employment on create and edit', function (): void {
    $f = employee_fixture();
    $today = gmdate('Y-m-d');
    $tomorrow = gmdate('Y-m-d', strtotime('+1 day'));
    $save = fn(array $data, ?int $id = null, ?int $revision = null): array => pl_save_employee($f['actor_id'], $f['company_id'], employee_input($data), $id, $revision);
    assert_same(null, $save([])['date_of_birth']);
    assert_same('2000-02-29', $save(['date_of_birth' => '2000-02-29'])['date_of_birth']);
    assert_same($today, $save(['date_of_birth' => $today, 'hire_date' => $today])['date_of_birth']);
    $row = $save(['date_of_birth' => '1990-01-01']);
    $before = DB::queryFirstRow('SELECT * FROM pl_employees WHERE id=%i', $row['id']);
    $audits = DB::queryFirstField('SELECT COUNT(*) FROM pl_employee_audit WHERE company_id=%i', $f['company_id']);
    foreach ([['date_of_birth' => $tomorrow, 'hire_date' => $tomorrow], ['date_of_birth' => '2026-01-02'], ['date_of_birth' => '2001-02-29']] as $bad) {
        assert_throws(fn () => $save($bad), DomainException::class);
        assert_throws(fn () => $save($bad, $row['id'], $row['revision']), DomainException::class);
    }
    assert_same($before, DB::queryFirstRow('SELECT * FROM pl_employees WHERE id=%i', $row['id']));
    assert_same($audits, DB::queryFirstField('SELECT COUNT(*) FROM pl_employee_audit WHERE company_id=%i', $f['company_id']));
    assert_same(null, $save(['date_of_birth' => ''], $row['id'], $row['revision'])['date_of_birth']);
});

test('employee legacy birth dates remain readable and unchanged until an explicit validated edit', function (): void {
    $f = employee_fixture();
    $row = pl_save_employee($f['actor_id'], $f['company_id'], employee_input());
    // Represent a record accepted before the date rule; reads never repair stored history.
    DB::update('pl_employees', ['date_of_birth' => '2026-01-02'], 'id=%i', $row['id']);
    assert_same('2026-01-02', pl_get_employee($f['actor_id'], $f['company_id'], $row['id'])['date_of_birth']);
    assert_same('2026-01-02', pl_list_employees($f['actor_id'], $f['company_id'])[0]['date_of_birth']);
    assert_throws(fn () => pl_save_employee($f['actor_id'], $f['company_id'], employee_input(['date_of_birth' => '2026-01-02']), $row['id'], 1), DomainException::class, 'hire date');
    assert_same('2026-01-02', DB::queryFirstField('SELECT date_of_birth FROM pl_employees WHERE id=%i', $row['id']));
    $edited = pl_save_employee($f['actor_id'], $f['company_id'], employee_input(['date_of_birth' => '1990-01-01']), $row['id'], 1);
    assert_same('1990-01-01', $edited['date_of_birth']);
});

test('employee company scope includes reads writes ownership links and same-company personnel uniqueness', function (): void {
    $f = employee_fixture(); $other = employee_fixture();
    $row = pl_save_employee($f['actor_id'], $f['company_id'], employee_input(['personnel_number' => 'EMP-1']));
    pl_save_employee($other['actor_id'], $other['company_id'], employee_input(['personnel_number' => 'EMP-1']));
    assert_throws(fn () => pl_get_employee($other['actor_id'], $other['company_id'], $row['id']), DomainException::class);
    assert_throws(fn () => pl_employee_status_at($other['actor_id'], $other['company_id'], $row['id'], '2026-02-01'), DomainException::class);
    assert_throws(fn () => pl_save_employee($other['actor_id'], $other['company_id'], employee_input(), $row['id'], 1), DomainException::class);
    assert_throws(fn () => pl_save_employee($f['actor_id'], $f['company_id'], employee_input(['personnel_number' => 'EMP-1'])), DomainException::class, 'already in use');
    $person = pl_save_ownership_party($other['actor_id'], $other['company_id'], ['kind' => 'person', 'name' => 'Sample other person', 'is_active' => true, 'reason' => 'Sample.']);
    assert_throws(fn () => pl_save_employee($f['actor_id'], $f['company_id'], employee_input(['ownership_party_id' => (int) $person['id']])), DomainException::class, 'ownership register');
    assert_same(null, pl_related_party_subject($other['company_id'], 'employee', $row['id']));
});

test('employee audit is immutable, optimistic revisions reject stale saves, and rollback leaves no employee', function (): void {
    $f = employee_fixture();
    $row = pl_save_employee($f['actor_id'], $f['company_id'], employee_input());
    pl_save_employee($f['actor_id'], $f['company_id'], employee_input(['department' => 'Sample department']), $row['id'], 1);
    assert_throws(fn () => pl_save_employee($f['actor_id'], $f['company_id'], employee_input(['department' => 'Stale']), $row['id'], 1), DomainException::class, 'Reload');
    assert_same(2, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_employee_audit WHERE entity_id = %i', $row['id']));
    assert_throws(fn () => DB::query('UPDATE pl_employee_audit SET reason = %s WHERE entity_id = %i', 'tampered', $row['id']), Throwable::class, 'immutable');
    assert_throws(fn () => DB::query('DELETE FROM pl_employee_audit WHERE entity_id = %i', $row['id']), Throwable::class, 'cannot be deleted');
    assert_throws(function () use ($f): void {
        pl_ledger_transaction(function () use ($f): void {
            pl_save_employee($f['actor_id'], $f['company_id'], employee_input(['full_name' => 'Rolled back']));
            throw new DomainException('Sample rollback.');
        });
    }, DomainException::class, 'rollback');
    assert_same(1, count(pl_list_employees($f['actor_id'], $f['company_id'])));
});

test('employee explicit ownership link does not mark related parties; deliberate markers disclose only permitted detail', function (): void {
    $f = employee_fixture();
    $person = pl_save_ownership_party($f['actor_id'], $f['company_id'], ['kind' => 'person', 'name' => 'Sample director', 'is_active' => true, 'reason' => 'Sample.']);
    $row = pl_save_employee($f['actor_id'], $f['company_id'], employee_input(['ownership_party_id' => (int) $person['id'], 'job_title' => 'Private job title']));
    assert_same((int) $person['id'], $row['ownership_party_id']);
    assert_same([], pl_list_related_party_markers($f['actor_id'], $f['company_id']));
    $party = pl_save_party($f['actor_id'], $f['company_id'], $f['book_id'], ['legal_name' => 'Sample Employee', 'country_code' => 'US', 'entity_type' => 'individual', 'is_customer' => true, 'is_vendor' => false, 'currency' => 'USD', 'request_key' => bin2hex(random_bytes(16)), 'reason' => 'Sample trade.']);
    pl_save_related_party_marker($f['actor_id'], $f['company_id'], ['party_id' => (int) $party['id'], 'related_register' => 'employee', 'related_id' => $row['id'], 'relationship' => 'key_management', 'effective_from' => '2026-01-01', 'effective_to' => '', 'note' => '', 'reason' => 'Explicit sample designation.']);
    $reader = employee_member($f, ['company.read', 'relatedparty.view']);
    $markers = pl_list_related_party_markers($reader, $f['company_id']);
    assert_same('Sample Employee', $markers[0]['subject_name']);
    assert_same('', $markers[0]['subject_detail']);
    assert_true(str_contains(pl_list_related_party_markers($f['actor_id'], $f['company_id'])[0]['subject_detail'], 'Private job title'));
});

test('employee hooks fire after commit and do not fire for rollback; no employee token API exists', function (): void {
    $f = employee_fixture(); $seen = [];
    pl_add_action('employee.created', function (array $payload) use (&$seen): void {
        assert_true(!pl_hook_lock_held());
        $seen[] = $payload['employee']['id'];
    }, 10, 'core');
    try {
        $row = pl_save_employee($f['actor_id'], $f['company_id'], employee_input());
        assert_same([$row['id']], $seen);
        assert_throws(function () use ($f): void {
            pl_ledger_transaction(function () use ($f): void {
                pl_save_employee($f['actor_id'], $f['company_id'], employee_input());
                throw new DomainException('Sample rollback.');
            });
        }, DomainException::class);
        assert_same([$row['id']], $seen);
    } finally { pl_hook_reset(); }
    foreach (array_keys(pl_read_catalog()) as $name) { assert_true(!str_contains($name, 'employee')); }
});

test('employee HTTP screen renders, validates CSRF and company scope, saves safely, and hides restricted data', function (): void {
    $f = employee_fixture();
    $reader = employee_member($f, [], 'accountant');
    $readerEmail = (string) DB::queryFirstField('SELECT email FROM pl_users WHERE id = %i', $reader);
    $port = random_int(20000, 50000);
    $log = tempnam(sys_get_temp_dir(), 'employee-http-');
    $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', dirname(__DIR__) . '/www/phpledger/public'],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, dirname(__DIR__), array_replace(getenv(), ['PL_SESSION_SECURE' => '0']));
    if (!is_resource($server)) { throw new RuntimeException('Employee HTTP server unavailable.'); }
    fclose($pipes[0]); $cookie = '';
    $request = static function (string $path, ?array $data = null) use ($port, &$cookie): array {
        $headers = ['Connection: close'];
        if ($cookie !== '') { $headers[] = 'Cookie: ' . $cookie; }
        if ($data !== null) { $headers[] = 'Content-Type: application/x-www-form-urlencoded'; }
        $body = @file_get_contents('http://127.0.0.1:' . $port . $path, false, stream_context_create(['http' => [
            'method' => $data === null ? 'GET' : 'POST', 'header' => implode("\r\n", $headers), 'content' => $data === null ? '' : http_build_query($data),
            'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 15]]));
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^Set-Cookie: ([^;]+)/i', $header, $match)) { $cookie = $match[1]; }
        }
        preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $match);
        return [(int) ($match[1] ?? 0), (string) $body];
    };
    $csrf = static function (string $html): string { preg_match('/name="csrf" value="([a-f0-9]+)"/', $html, $m); return $m[1] ?? ''; };
    $login = static function (string $email, string $password) use ($request, $csrf, $f): void {
        [, $body] = $request('/login');
        [$status] = $request('/login', ['email' => $email, 'password' => $password, 'csrf' => $csrf($body)]);
        assert_true(in_array($status, [302, 303], true), 'Login failed.');
        [, $body] = $request('/companies');
        [$status] = $request('/company/select', ['company_id' => $f['company_id'], 'csrf' => $csrf($body)]);
        assert_true(in_array($status, [302, 303], true), 'Company selection failed.');
    };
    try {
        for ($i = 0; $i < 100; $i++) { [$status] = $request('/login'); if ($status !== 0) { break; } usleep(20000); }
        $login($f['email'], $f['password']);
        [$status, $html] = $request('/employees');
        assert_same(200, $status, substr((string) file_get_contents($log), -400));
        assert_true(str_contains($html, 'employee-status-date'));
        $data = employee_input(['full_name' => '<script>Sample</script>', 'basic_salary' => '777.12', 'pay_currency' => 'USD', 'pay_frequency' => 'monthly'])
            + ['company_id' => $f['company_id'], 'book_id' => $f['book_id'], 'csrf' => $csrf($html)];
        [$status] = $request('/employees', array_replace($data, ['csrf' => 'bad']));
        assert_same(403, $status);
        $request('/employees', array_replace($data, ['company_id' => $f['company_id'] + 999999, '_date_display' => ['date_of_birth' => '12/03/1987']]));
        assert_same([], pl_list_employees($f['actor_id'], $f['company_id']));
        [$status, $html] = $request('/employees');
        assert_same(422, $status, 'The redirected scope error must be displayed.');
        assert_true(!str_contains($html, '777.12') && !str_contains($html, '&lt;script&gt;Sample'), 'A wrong-company form must never replay private data under the current company.');
        assert_true(!str_contains($html, '12/03/1987') && !str_contains($html, '12\/03\/1987'), 'Wrong-company date recovery must not enter the page configuration.');
        $data['csrf'] = $csrf($html);
        foreach ([gmdate('Y-m-d', strtotime('+1 day')) => 'future', '2026-01-02' => 'hire date'] as $birthDate => $message) {
            $request('/employees', array_replace($data, ['date_of_birth' => $birthDate, 'csrf' => $csrf($html)]));
            [$status, $html] = $request('/employees');
            assert_same(422, $status);
            assert_true(str_contains($html, $message));
            assert_true(str_contains($html, 'value="' . $birthDate . '"'), 'Invalid birth date is retained for correction.');
            assert_same([], pl_list_employees($f['actor_id'], $f['company_id']));
        }
        $data['csrf'] = $csrf($html);
        [$status] = $request('/employees', $data);
        assert_true(in_array($status, [302, 303], true));
        $rows = pl_list_employees($f['actor_id'], $f['company_id']);
        assert_same(1, count($rows));
        [$status, $html] = $request('/employees?id=' . $rows[0]['id']);
        assert_same(200, $status, 'Employee page failed: ' . substr(strip_tags($html), -800));
        assert_true(str_contains($html, '&lt;script&gt;Sample&lt;/script&gt;'));
        assert_true(!str_contains($html, '<script>Sample</script>'));
        assert_true(str_contains($html, '777.1200'));
        assert_true(str_contains($html, 'Register #' . $rows[0]['id']));
        $request('/employees', array_replace($data, ['id' => $rows[0]['id'], 'revision' => 1, 'date_of_birth' => '2026-01-02', 'csrf' => $csrf($html)]));
        [$status, $html] = $request('/employees');
        assert_same(422, $status);
        assert_true(str_contains($html, 'hire date'));
        assert_same(1, pl_get_employee($f['actor_id'], $f['company_id'], $rows[0]['id'])['revision']);
        $edit = array_replace($data, ['id' => $rows[0]['id'], 'revision' => $rows[0]['revision'], 'basic_salary' => 'invalid', 'csrf' => $csrf($html)]);
        $request('/employees', $edit);
        [$status, $html] = $request('/employees');
        assert_same(422, $status);
        assert_true(str_contains($html, 'name="id" value="' . $rows[0]['id'] . '"'), 'A failed edit must remain an edit, not turn into a duplicate create.');
        $request('/employees', array_replace($edit, ['basic_salary' => '777.12', 'department' => 'Updated sample department', 'csrf' => $csrf($html)]));
        [, $html] = $request('/employees?id=' . $rows[0]['id']);
        assert_same(1, count(pl_list_employees($f['actor_id'], $f['company_id'])));
        assert_true(str_contains($html, 'Updated sample department'));
        [$status,$payHtml]=$request('/payroll');assert_same(200,$status,'Payroll route renders.');
        [$status]=$request('/payroll',['action'=>'provision','company_id'=>$f['company_id'],'book_id'=>$f['book_id'],'csrf'=>'bad']);assert_same(403,$status);
        $request('/payroll',['action'=>'provision','company_id'=>$f['company_id'],'book_id'=>$f['book_id'],'csrf'=>$csrf($payHtml)]);
        [$status,$payHtml]=$request('/payroll');assert_same(200,$status);
        assert_true(str_contains($payHtml,'net_pay'),'Payroll element controls render.');
        $payAccounts=pl_payroll_provision_accounts($f['actor_id'],$f['company_id'],$f['book_id']);
        $payData=['action'=>'preview','company_id'=>$f['company_id'],'book_id'=>$f['book_id'],'csrf'=>$csrf($payHtml),'period_from'=>'2026-01-01','period_to'=>'2026-01-31','date'=>'2026-01-31','external_reference'=>'HTTP payroll','description'=>'Reviewed aggregate HTTP sample','request_key'=>bin2hex(random_bytes(16)),
            'elements'=>[['kind'=>'gross_pay','account_id'=>$payAccounts['gross_pay'],'amount'=>'100'],['kind'=>'net_pay','account_id'=>$payAccounts['net_pay'],'amount'=>'100']]];
        [$status,$payHtml]=$request('/payroll',$payData);assert_same(200,$status,'Payroll preview renders.');
        preg_match('/name="preview_hash" value="([a-f0-9]+)"/',$payHtml,$payHash);assert_true(isset($payHash[1]),'Payroll preview provides confirmation hash.');
        [$status]=$request('/payroll',array_replace($payData,['action'=>'post','confirmed'=>'yes','preview_hash'=>$payHash[1],'csrf'=>$csrf($payHtml)]));assert_true(in_array($status,[302,303],true));
        assert_same(1,(int)DB::queryFirstField('SELECT COUNT(*) FROM pl_payroll_journals WHERE company_id=%i AND journal_id IS NOT NULL',$f['company_id']));
        [$status,$linkHtml]=$request('/employees/links');assert_same(200,$status,'Employee links route renders: '.substr(strip_tags($linkHtml),-600));
        assert_true(str_contains($linkHtml,'employee bank details'),'Bank matching limitation is visible. '.substr((string)file_get_contents($log),-1200));
        [$status]=$request('/employees/links',['action'=>'trade','employee_id'=>$rows[0]['id'],'revision'=>3,'company_id'=>$f['company_id'],'book_id'=>$f['book_id'],'csrf'=>'bad']);assert_same(403,$status);
        // A valid-scope failure can also become stale if context changes before its redirect.
        $request('/employees', array_replace($edit, ['revision' => 2, 'csrf' => $csrf($html), '_date_display' => ['date_of_birth' => '12/03/1987']]));
        $other = pl_create_company($f['actor_id'], 'Sample second employee company', 'USD', '2026-01-01');
        [, $companies] = $request('/companies');
        $request('/company/select', ['company_id' => $other['company_id'], 'csrf' => $csrf($companies)]);
        [$status, $html] = $request('/employees');
        assert_same(422, $status);
        assert_true(!str_contains($html, '&lt;script&gt;Sample') && !str_contains($html, 'value="invalid"'), 'Cross-company recovery must discard a previously valid-scope failed form.');
        assert_true(!str_contains($html, '12/03/1987') && !str_contains($html, '12\/03\/1987'), 'Context changes must discard private date recovery from the page configuration.');
        $cookie = '';
        $login($readerEmail, 'Sample-reader-password-123');
        [$status, $html] = $request('/employees?id=' . $rows[0]['id']);
        assert_same(200, $status, 'Employee page failed: ' . substr(strip_tags($html), -800));
        assert_true(!str_contains($html, '777.1200') && !str_contains($html, '&lt;script&gt;Sample'));
        assert_true(!str_contains($html, 'id="employee-form"'));
        $request('/employees', array_replace($data, ['csrf' => $csrf($html), 'full_name' => 'Unauthorized']));
        assert_same(1, count(pl_list_employees($f['actor_id'], $f['company_id'])));
    } finally { proc_terminate($server); proc_close($server); @unlink($log); }
});
