<?php
declare(strict_types=1);

test('passwords are hashed and invalid credentials are rejected', function (): void {
    $password = 'Sample test passphrase 471!';
    $hash = pl_hash_password($password);
    assert_true($hash !== $password);
    assert_true(pl_verify_password($password, $hash));
    assert_true(!pl_verify_password('A different test passphrase', $hash));
    assert_true(!pl_verify_password(str_repeat('x', 73), $hash));
    assert_throws(fn () => pl_hash_password('short'), InvalidArgumentException::class);
    assert_throws(fn () => pl_hash_password(str_repeat('x', 73)), InvalidArgumentException::class);
    assert_throws(fn () => pl_hash_password("A long password\0with null"), InvalidArgumentException::class);
});

test('unsafe HTTP verbs are rejected and HTML output is escaped', function (): void {
    pl_require_post('POST');
    assert_throws(fn () => pl_require_post('GET'), DomainException::class);
    assert_throws(fn () => pl_require_post('HEAD'), DomainException::class);
    assert_same('&lt;script&gt;&quot;&#039;&amp;', pl_e('<script>"\'&'));
    assert_same(pl_e('<b>'), e('<b>'));
});

test('sessions expire at idle and absolute deadlines and reject future state', function (): void {
    $now = 100000;
    assert_true(pl_session_is_fresh(['authenticated_at' => $now - 4000, 'last_activity' => $now - 1799], $now));
    assert_true(!pl_session_is_fresh(['authenticated_at' => $now - 4000, 'last_activity' => $now - 1800], $now));
    assert_true(!pl_session_is_fresh(['authenticated_at' => $now - 43200, 'last_activity' => $now], $now));
    assert_true(!pl_session_is_fresh(['authenticated_at' => $now, 'last_activity' => $now + 1], $now));
    assert_true(!pl_session_is_fresh(['authenticated_at' => $now, 'last_activity' => $now - 1], $now));
    assert_true(!pl_session_is_fresh([], $now));
});

test('CSRF and session identities rotate at login, logout, and expiry', function (): void {
    pl_session_start(false);
    $cookie = session_get_cookie_params();
    assert_true($cookie['httponly']);
    assert_same('Lax', $cookie['samesite']);
    assert_same('1', ini_get('session.use_strict_mode'));
    assert_same('1', ini_get('session.use_only_cookies'));
    $_SESSION = [];
    assert_throws(fn () => pl_require_csrf(null), DomainException::class);
    $token = pl_csrf_token();
    assert_same(64, strlen($token));
    pl_require_csrf($token);
    assert_throws(fn () => pl_require_csrf(str_repeat('0', 64)), DomainException::class);
    assert_throws(fn () => pl_require_csrf(''), DomainException::class);

    $anonymousId = session_id();
    pl_login_session(['id' => 987654]);
    assert_true(session_id() !== $anonymousId);
    assert_same(987654, $_SESSION['user_id']);
    assert_true($token !== pl_csrf_token());
    assert_throws(fn () => pl_require_csrf($token), DomainException::class);
    $authenticatedId = session_id();
    $authenticatedToken = pl_csrf_token();
    pl_logout_session();
    assert_true(session_id() !== $authenticatedId);
    assert_true(!isset($_SESSION['user_id']));
    assert_true(pl_csrf_token() !== $authenticatedToken);
    assert_same(null, pl_current_user_id());

    pl_login_session(['id' => 987654]);
    $_SESSION['last_activity'] = time() - 1800;
    $_SESSION['authenticated_at'] = time() - 3600;
    assert_same(null, pl_current_user_id());
    assert_true(!isset($_SESSION['user_id']));
});

    test('real authentication uses persistent account throttling and expires the block', function (): void {
        $email = 'auth-' . bin2hex(random_bytes(6)) . '@example.invalid';
        $password = 'Sample integration passphrase 471!';
        $id = pl_create_user($email, 'Authentication fixture', $password);
        $ip = '198.51.100.' . random_int(1, 200);
        $actual = pl_authenticate(strtoupper($email), $password, $ip);
        assert_same($id, $actual['id']);
        assert_true(!array_key_exists('password_hash', $actual));
        for ($attempt = 0; $attempt < 5; $attempt++) {
            assert_same(null, pl_authenticate($email, 'Incorrect sample password', $ip));
        }
        // A different client/session cannot bypass the account limit.
        assert_same(null, pl_authenticate($email, $password, '203.0.113.250'));
        $key = hash('sha256', 'account:' . $email);
        assert_same(5, (int) DB::queryFirstField('SELECT attempt_count FROM pl_login_attempts WHERE subject_hash = %s', $key));
        DB::update('pl_login_attempts', [
            'window_started_at' => gmdate('Y-m-d H:i:s', time() - 901),
            'blocked_until' => gmdate('Y-m-d H:i:s', time() - 1),
        ], 'subject_hash = %s', $key);
        assert_same($id, pl_authenticate($email, $password, $ip)['id']);
        assert_same(0, (int) DB::queryFirstField('SELECT attempt_count FROM pl_login_attempts WHERE subject_hash = %s', $key));
        assert_throws(fn () => pl_create_user($email, 'Duplicate fixture', $password));
    });

    test('client limits, disabled accounts, and unknown accounts fail closed', function (): void {
        $email = 'access-' . bin2hex(random_bytes(6)) . '@example.invalid';
        $password = 'Sample integration passphrase 582!';
        $id = pl_create_user($email, 'Access fixture', $password);
        $ip = 'blocked-fixture-' . bin2hex(random_bytes(6));
        DB::insert('pl_login_attempts', [
            'subject_hash' => hash('sha256', 'client:' . $ip),
            'window_started_at' => gmdate('Y-m-d H:i:s'),
            'attempt_count' => 30,
            'blocked_until' => gmdate('Y-m-d H:i:s', time() + 900),
        ]);
        assert_same(null, pl_authenticate($email, $password, $ip));
        assert_same($id, pl_authenticate($email, $password, 'unblocked-' . $ip)['id']);
        DB::update('pl_users', ['is_active' => 0], 'id = %i', $id);
        assert_same(null, pl_authenticate($email, $password, 'unblocked-' . $ip));
        assert_same(null, pl_authenticate('missing-' . $email, $password, 'unblocked-' . $ip));

        pl_session_start(false);
        pl_login_session(['id' => $id]);
        assert_same(null, pl_current_user_id());
        assert_true(!isset($_SESSION['user_id']));
    });

test('owners sign in with a username or an email, and both share one attempt limit', function (): void {
    $suffix = bin2hex(random_bytes(5));
    $email = 'names-' . $suffix . '@example.invalid';
    $username = 'Owner.' . $suffix;
    $password = 'Sample username passphrase 739!';
    $id = pl_create_user($email, 'Username fixture', $password, $username);
    assert_same(strtolower($username), DB::queryFirstField('SELECT username FROM pl_users WHERE id = %i', $id));
    $ip = 'names-' . $suffix;
    assert_same($id, pl_authenticate(strtoupper($username), $password, $ip)['id']);
    assert_same($id, pl_authenticate($email, $password, $ip)['id']);
    assert_throws(fn () => pl_create_user('other-' . $email, 'Duplicate name fixture', $password, strtolower($username)), InvalidArgumentException::class, 'already taken');
    foreach (['ab', 'has space', 'name@example', '-leading', 'trailing.', str_repeat('a', 61)] as $invalid) {
        assert_throws(fn () => pl_normalize_username($invalid), InvalidArgumentException::class);
    }
    assert_same(null, DB::queryFirstField('SELECT username FROM pl_users WHERE email = %s', 'other-' . $email));
    // Switching between the two names does not add guesses: both count against one account limit.
    for ($attempt = 0; $attempt < 5; $attempt++) {
        assert_same(null, pl_authenticate($attempt % 2 === 0 ? $username : $email, 'Incorrect sample password', $ip . '-' . $attempt));
    }
    assert_same(null, pl_authenticate($username, $password, $ip . '-after'));
    assert_same(5, (int) DB::queryFirstField('SELECT attempt_count FROM pl_login_attempts WHERE subject_hash = %s', hash('sha256', 'account:' . $email)));
});

test('company access enforces membership, read-only roles, and active accounts', function (): void {
    $suffix = bin2hex(random_bytes(6));
    $password = 'Sample membership passphrase 693!';
    $owner = pl_create_user('owner-' . $suffix . '@example.invalid', 'Owner fixture', $password);
    $accountant = pl_create_user('accountant-' . $suffix . '@example.invalid', 'Accountant fixture', $password);
    $viewer = pl_create_user('viewer-' . $suffix . '@example.invalid', 'Viewer fixture', $password);
    $outsider = pl_create_user('outsider-' . $suffix . '@example.invalid', 'Outsider fixture', $password);
    $company = pl_create_company($owner, 'Permission fixture', 'USD', '2026-01-01');
    $companyId = $company['company_id'];
    sample_membership_insert(['company_id' => $companyId, 'user_id' => $accountant, 'role' => 'accountant']);
    sample_membership_insert(['company_id' => $companyId, 'user_id' => $viewer, 'role' => 'viewer']);
    assert_same('owner', pl_require_company_access($owner, $companyId, true)['role']);
    assert_same('accountant', pl_require_company_access($accountant, $companyId, true)['role']);
    assert_same('viewer', pl_require_company_access($viewer, $companyId)['role']);
    assert_throws(fn () => pl_require_company_access($viewer, $companyId, true), DomainException::class);
    assert_throws(fn () => pl_require_company_access($outsider, $companyId), DomainException::class);
    assert_throws(fn () => pl_require_company_access($owner, 0), DomainException::class);
    assert_throws(fn () => pl_require_company_access(0, $companyId), DomainException::class);
    DB::update('pl_users', ['is_active' => 0], 'id = %i', $accountant);
    assert_throws(fn () => pl_require_company_access($accountant, $companyId), DomainException::class);

    pl_session_start(false);
    pl_login_session(['id' => $owner]);
    $oldId = session_id();
    $_SESSION['rotated_at'] = time() - 900;
    assert_same($owner, pl_current_user_id());
    assert_true($oldId !== session_id());
    pl_logout_session();
});
