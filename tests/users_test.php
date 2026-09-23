<?php
declare(strict_types=1);

/**
 * The Users module (release plan 1.2, milestone M7).
 *
 * The authorisation equivalence proof lives next door in capability_equivalence_test.php. This
 * file covers what the module itself does: capability registration from a manifest and the inert
 * retention of its grants, invitations, forced resets, server-side session revocation, the
 * per-report cost setting on the screen AND on the machine read, the protected system roles, and
 * cross-company isolation. It closes with the measured cost of the first MeekroORM records.
 */

function users_fixture(): array
{
    $suffix = bin2hex(random_bytes(8));
    $actorId = pl_create_user('users-' . $suffix . '@example.invalid', 'Sample users tester', 'Sample-users-password-' . $suffix);
    $fixture = ['actor_id' => $actorId, 'suffix' => $suffix] + pl_create_company($actorId, 'Sample users company ' . $suffix, 'USD', '2026-01-01');
    pl_capability_cache_reset();
    return $fixture;
}

function users_role_id(string $slug): int
{
    return (int) DB::queryFirstField('SELECT id FROM pl_roles WHERE company_id IS NULL AND slug = %s', $slug);
}

test('the three protected system roles ship installation-wide and carry the capabilities they always had', function (): void {
    $roles = DB::query('SELECT slug, company_id, is_system FROM pl_roles WHERE company_id IS NULL ORDER BY slug');
    assert_same(['accountant', 'owner', 'viewer'], array_map(static fn (array $row): string => (string) $row['slug'], $roles));
    foreach ($roles as $row) {
        assert_same(null, $row['company_id'], 'A system role is installation-wide.');
        assert_same(1, (int) $row['is_system'], 'A system role is protected.');
    }
    $grants = pl_system_role_grants();
    foreach (['modules.manage', 'cost.view', 'settlement.approve', 'roles.manage', 'users.manage'] as $capability) {
        assert_true(in_array($capability, $grants['owner'], true), 'Owner keeps ' . $capability . '.');
        assert_true(!in_array($capability, $grants['accountant'], true), 'Accountant does not gain ' . $capability . '.');
        assert_true(!in_array($capability, $grants['viewer'], true), 'Viewer does not gain ' . $capability . '.');
    }
    assert_same(['company.read'], $grants['viewer'], 'A viewer reads and nothing else.');
    // installation.admin is installation scope, so it can never arrive through a per-company role.
    assert_true(!in_array('installation.admin', $grants['owner'], true), 'installation.admin is not a role grant.');
    assert_same('installation', (string) DB::queryFirstField('SELECT scope FROM pl_capabilities WHERE capability = %s', 'installation.admin'));
});

test('a protected system role cannot be edited, renamed or deleted, from the service or the database', function (): void {
    $f = users_fixture();
    $ownerRole = users_role_id('owner');
    assert_throws(fn () => pl_save_role($f['actor_id'], $f['company_id'], [
        'name' => 'Owner but different', 'description' => '', 'reason' => 'Sample attempt',
        'capabilities' => ['company.read'],
    ], $ownerRole, 0), DomainException::class, 'protected');
    // The database refuses it too, so a service that forgot the check still cannot do it.
    assert_throws(fn () => DB::query('UPDATE pl_roles SET slug = %s WHERE id = %i', 'owner-renamed', $ownerRole));
    assert_throws(fn () => DB::query('DELETE FROM pl_roles WHERE id = %i', $ownerRole));
    assert_same('owner', DB::queryFirstField('SELECT slug FROM pl_roles WHERE id = %i', $ownerRole));
});

test('a custom role is per company, editable, and invisible to another company', function (): void {
    $one = users_fixture();
    $two = users_fixture();
    $role = pl_save_role($one['actor_id'], $one['company_id'], [
        'name' => 'Counter staff', 'description' => 'Records sales at the counter.',
        'reason' => 'Sample custom role', 'capabilities' => ['company.read', 'company.write'],
    ]);
    assert_same(1, $role['revision']);
    assert_same($one['company_id'], $role['company_id']);
    // Cross-company isolation: the other company neither lists it nor may load it.
    $slugs = array_map(static fn (array $row): string => (string) $row['slug'], pl_list_roles($two['actor_id'], $two['company_id']));
    assert_true(!in_array($role['slug'], $slugs, true), 'A custom role does not leak into another company.');
    assert_throws(fn () => pl_get_role($two['actor_id'], $two['company_id'], $role['id']), DomainException::class);
    assert_throws(fn () => pl_save_role($two['actor_id'], $two['company_id'], [
        'name' => 'Stolen', 'description' => '', 'reason' => 'Sample attempt', 'capabilities' => ['company.read'],
    ], $role['id'], 1), DomainException::class);
    // A stale revision is refused, and the reason is recorded in the immutable user audit.
    assert_throws(fn () => pl_save_role($one['actor_id'], $one['company_id'], [
        'name' => 'Counter staff', 'description' => '', 'reason' => 'Sample stale', 'capabilities' => ['company.read'],
    ], $role['id'], 0), DomainException::class, 'changed');
    $updated = pl_save_role($one['actor_id'], $one['company_id'], [
        'name' => 'Counter staff', 'description' => 'Records sales and reads cost.',
        'reason' => 'Sample capability change', 'capabilities' => ['company.read', 'cost.view'],
    ], $role['id'], 1);
    assert_same(2, $updated['revision']);
    assert_true(in_array('cost.view', $updated['capabilities'], true));
    // An edit takes a capability away as well as adding one: the rows are replaced, not
    // added to, and the person holding the role loses what the edit dropped.
    assert_true(!in_array('company.write', $updated['capabilities'], true), 'An edited role kept a capability the edit removed.');
    assert_same(2, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_role_capabilities WHERE role_id = %i', $role['id']));
    $restored = pl_save_role($one['actor_id'], $one['company_id'], [
        'name' => 'Counter staff', 'description' => 'Records sales and reads cost.',
        'reason' => 'Sample capability restore', 'capabilities' => ['company.read', 'company.write', 'cost.view'],
    ], $role['id'], 2);
    assert_true(in_array('company.write', $restored['capabilities'], true));
    assert_true((int) DB::queryFirstField('SELECT COUNT(*) FROM pl_user_audit WHERE entity_type = %s AND entity_id = %i', 'role', $role['id']) >= 2);
    // Every role reads the company it belongs to; dropping that is refused rather than silently added.
    assert_throws(fn () => pl_save_role($one['actor_id'], $one['company_id'], [
        'name' => 'Blind role', 'description' => '', 'reason' => 'Sample', 'capabilities' => ['company.write'],
    ]), DomainException::class, 'Read this company');
    // An installation-scope capability can never be granted through a role.
    assert_throws(fn () => pl_save_role($one['actor_id'], $one['company_id'], [
        'name' => 'Sneaky admin', 'description' => '', 'reason' => 'Sample',
        'capabilities' => ['company.read', 'installation.admin'],
    ]), DomainException::class, 'held per person');
});

test('a manifest registers its own capabilities, and its grants go inert — not away — when the module is disabled', function (): void {
    // The manifest side of the contract. Bundled manifests deliberately omit `grants` in 1.2 so
    // their digests do not move; the parser is exercised against a fixture manifest instead.
    $manifest = ['id' => 'pos-showcase', 'grants' => ['pos.refund' => 'Refund a counter sale']];
    assert_same(['pos.refund' => 'Refund a counter sale'], pl_manifest_capability_grants($manifest));
    assert_same([], pl_manifest_capability_grants(['id' => 'pos-showcase']));
    foreach ([['Bad Name' => 'x'], ['pos' => 'x'], ['pos.refund' => ''], ['cost.view' => 'Steal a core capability']] as $bad) {
        assert_throws(fn () => pl_manifest_capability_grants(['id' => 'pos-showcase', 'grants' => $bad]), DomainException::class);
    }
    // A malformed `grants` block is refused when the registry is validated, not at first use.
    assert_throws(fn () => pl_validate_module_registry(['core' => ['id' => 'core', 'name' => 'Core', 'contract' => 1,
        'optional' => false, 'version' => '1.0.0', 'requires' => [], 'history' => '', 'grants' => ['BAD' => 'x'],
        'capabilities' => [], 'migrations' => [], 'routes' => [], 'permissions' => [], 'settings' => [],
        'reports' => [], 'api_operations' => [], 'mcp_operations' => []]]), DomainException::class);

    // The inert-retention side: a module-owned capability, granted to a custom role.
    $f = users_fixture();
    DB::insertUpdate('pl_capabilities', ['capability' => 'pos.refund', 'owner_type' => 'module',
        'owner_id' => 'pos-showcase', 'scope' => 'company', 'label' => 'Refund a counter sale', 'description' => ''],
        ['owner_type' => 'module', 'owner_id' => 'pos-showcase']);
    pl_capability_cache_reset();
    $role = pl_save_role($f['actor_id'], $f['company_id'], [
        'name' => 'Counter supervisor', 'description' => '', 'reason' => 'Sample module capability',
        'capabilities' => ['company.read', 'company.write', 'pos.refund'],
    ]);
    $staff = pl_create_user('refund-' . $f['suffix'] . '@example.invalid', 'Refund staff', 'Sample-refund-password-471!');
    pl_assign_company_role($f['actor_id'], $f['company_id'], $staff, $role['id'], 'Sample assignment');

    $digest = pl_module_registry()['pos-showcase']['digest'];
    pl_set_company_module($f['actor_id'], $f['company_id'], 'pos-showcase', true, 0, $digest, 'Sample enable', bin2hex(random_bytes(16)));
    pl_capability_cache_reset();
    assert_true(pl_user_can($staff, $f['company_id'], 'pos.refund'), 'The module is on, so the grant applies.');

    pl_set_company_module($f['actor_id'], $f['company_id'], 'pos-showcase', false, 1, $digest, 'Sample disable', bin2hex(random_bytes(16)));
    pl_capability_cache_reset();
    assert_true(!pl_user_can($staff, $f['company_id'], 'pos.refund'), 'The module is off, so the grant does not apply.');
    assert_true(pl_user_can($staff, $f['company_id'], 'company.write'), 'Core capabilities on the same role are unaffected.');
    // Retained, not removed: this is the row a re-enable restores from.
    assert_same(1, (int) DB::queryFirstField(
        'SELECT COUNT(*) FROM pl_role_capabilities rc JOIN pl_capabilities c ON c.id = rc.capability_id WHERE rc.role_id = %i AND c.capability = %s',
        $role['id'], 'pos.refund'
    ));
    assert_true(in_array('pos.refund', pl_get_role($f['actor_id'], $f['company_id'], $role['id'])['capabilities'], true));

    pl_set_company_module($f['actor_id'], $f['company_id'], 'pos-showcase', true, 2, $digest, 'Sample re-enable', bin2hex(random_bytes(16)));
    pl_capability_cache_reset();
    assert_true(pl_user_can($staff, $f['company_id'], 'pos.refund'), 'Re-enabling restores exactly what was configured.');
});

test('installation.admin is seeded on the first company owner and is held per person, not per role', function (): void {
    // Whoever holds it already holds it: the seed never promotes a second person on its own.
    $existing = DB::queryFirstField("SELECT user_id FROM pl_user_meta WHERE meta_key = 'capabilities:installation' AND meta_value LIKE '%\"installation.admin\":true%' ORDER BY user_id LIMIT 1");
    assert_true($existing !== null, 'The first company owner was seeded as the installation administrator.');
    $admin = (int) $existing;
    assert_true(pl_user_can($admin, 0, 'installation.admin'), 'Installation scope is asked with company 0.');
    assert_true(in_array('roles.manage', pl_user_installation_grants($admin), true), 'roles.manage was seeded alongside it.');

    $f = users_fixture();
    assert_true(!pl_user_can($f['actor_id'], 0, 'installation.admin'), 'A later company owner is not an installation administrator.');
    assert_same($admin, pl_seed_installation_admin(), 'Seeding again is a no-op that names the same person.');
    // Only an installation administrator may grant installation permissions.
    assert_throws(fn () => pl_set_installation_grant($f['actor_id'], $f['actor_id'], 'installation.admin', true, 'Sample self-promotion'),
        DomainException::class, 'installation administrator');
    pl_set_installation_grant($admin, $f['actor_id'], 'installation.admin', true, 'Sample delegation');
    assert_true(pl_user_can($f['actor_id'], 0, 'installation.admin'));
    // An installation grant follows the person into every company they belong to.
    assert_true(pl_user_can($f['actor_id'], $f['company_id'], 'installation.admin'));
    pl_set_installation_grant($admin, $f['actor_id'], 'installation.admin', false, 'Sample withdrawal');
    assert_true(!pl_user_can($f['actor_id'], 0, 'installation.admin'));
    assert_true((int) DB::queryFirstField('SELECT COUNT(*) FROM pl_user_audit WHERE entity_type = %s AND subject_user_id = %i', 'capability', $f['actor_id']) >= 2);
});

test('an invitation names a role, is redeemed once, and can be revoked or expire', function (): void {
    $f = users_fixture();
    $email = 'invited-' . $f['suffix'] . '@example.invalid';
    $invitation = pl_invite_user($f['actor_id'], $f['company_id'], $email, users_role_id('accountant'), 'Join the books.');
    assert_same(64, strlen($invitation['token']), 'The token is returned once, in full.');
    assert_same(64, strlen((string) DB::queryFirstField('SELECT token_hash FROM pl_user_invitations WHERE id = %i', $invitation['id'])));
    assert_true(DB::queryFirstField('SELECT token_hash FROM pl_user_invitations WHERE id = %i', $invitation['id']) !== $invitation['token'],
        'Only the hash is stored.');
    // One open invitation per address per company.
    assert_throws(fn () => pl_invite_user($f['actor_id'], $f['company_id'], $email, users_role_id('viewer'), ''), DomainException::class, 'already open');

    $userId = pl_accept_invitation($invitation['token'], ['display_name' => 'Invited accountant', 'username' => '', 'password' => 'Sample-invited-password-471!']);
    assert_same('accountant', DB::queryFirstField('SELECT r.slug FROM pl_company_members m JOIN pl_roles r ON r.id = m.role_id WHERE m.company_id = %i AND m.user_id = %i', $f['company_id'], $userId));
    assert_same(users_role_id('accountant'), (int) DB::queryFirstField('SELECT role_id FROM pl_company_members WHERE company_id = %i AND user_id = %i', $f['company_id'], $userId));
    pl_capability_cache_reset();
    assert_true(pl_user_can($userId, $f['company_id'], 'company.write'));
    assert_true(!pl_user_can($userId, $f['company_id'], 'modules.manage'));
    // A redeemed token is spent.
    assert_throws(fn () => pl_accept_invitation($invitation['token'], ['display_name' => 'Again', 'password' => 'Sample-invited-password-471!']), DomainException::class, 'not valid any more');
    assert_throws(fn () => pl_accept_invitation(str_repeat('0', 64), ['display_name' => 'Nobody', 'password' => 'Sample-invited-password-471!']), DomainException::class);
    // Someone who already works here is not invited again.
    assert_throws(fn () => pl_invite_user($f['actor_id'], $f['company_id'], $email, users_role_id('viewer'), ''), DomainException::class, 'already works');

    $second = pl_invite_user($f['actor_id'], $f['company_id'], 'revoked-' . $f['suffix'] . '@example.invalid', users_role_id('viewer'), '');
    pl_revoke_invitation($f['actor_id'], $f['company_id'], $second['id'], 'Sample revocation');
    assert_throws(fn () => pl_accept_invitation($second['token'], ['display_name' => 'Nobody', 'password' => 'Sample-invited-password-471!']), DomainException::class);
    $third = pl_invite_user($f['actor_id'], $f['company_id'], 'expired-' . $f['suffix'] . '@example.invalid', users_role_id('viewer'), '');
    DB::update('pl_user_invitations', ['expires_at' => gmdate('Y-m-d H:i:s', time() - 60)], 'id = %i', $third['id']);
    assert_throws(fn () => pl_accept_invitation($third['token'], ['display_name' => 'Nobody', 'password' => 'Sample-invited-password-471!']), DomainException::class);
    $statuses = [];
    foreach (pl_list_invitations($f['actor_id'], $f['company_id']) as $row) { $statuses[$row['id']] = $row['status']; }
    assert_same('accepted', $statuses[$invitation['id']]);
    assert_same('revoked', $statuses[$second['id']]);
    assert_same('expired', $statuses[$third['id']]);
    // Cross-company isolation: another company neither lists nor revokes this invitation.
    $other = users_fixture();
    assert_throws(fn () => pl_revoke_invitation($other['actor_id'], $other['company_id'], $second['id'], 'Sample'), DomainException::class);
});

test('a forced password reset ends every session, and the one-time token sets a new password once', function (): void {
    $f = users_fixture();
    $password = 'Sample-reset-password-471!';
    $email = 'reset-' . $f['suffix'] . '@example.invalid';
    $staff = pl_create_user($email, 'Reset staff', $password);
    pl_assign_company_role($f['actor_id'], $f['company_id'], $staff, users_role_id('accountant'), 'Sample assignment');
    $token = pl_session_open($staff, 'installation-fixture');
    assert_true(pl_session_validate($token, $staff, 'installation-fixture') !== null);

    $reset = pl_force_password_reset($f['actor_id'], $f['company_id'], $staff, 'Sample forced reset');
    assert_same(null, pl_session_validate($token, $staff, 'installation-fixture'), 'Every session ended.');
    assert_same(null, pl_authenticate($email, $password, 'reset-fixture-' . $f['suffix']), 'The old password stopped working.');
    assert_same(1, (int) DB::queryFirstField('SELECT must_change_password FROM pl_users WHERE id = %i', $staff));

    assert_throws(fn () => pl_complete_password_reset($email, str_repeat('0', 64), 'Sample-new-password-582!'), DomainException::class);
    assert_same($staff, pl_complete_password_reset($email, $reset['token'], 'Sample-new-password-582!'));
    assert_same(0, (int) DB::queryFirstField('SELECT must_change_password FROM pl_users WHERE id = %i', $staff));
    assert_same($staff, pl_authenticate($email, 'Sample-new-password-582!', 'reset-fixture-b-' . $f['suffix'])['id']);
    // The token is spent.
    assert_throws(fn () => pl_complete_password_reset($email, $reset['token'], 'Sample-third-password-693!'), DomainException::class);
});

test('server-side sessions are revocable, expire, and belong to one copy of the application', function (): void {
    $f = users_fixture();
    $staff = pl_create_user('session-' . $f['suffix'] . '@example.invalid', 'Session staff', 'Sample-session-password-471!');
    $first = pl_session_open($staff, 'installation-a', 'Fixture browser one');
    $second = pl_session_open($staff, 'installation-a', 'Fixture browser two');
    assert_same(2, count(pl_list_user_sessions($staff)));
    // The list shows a handle, never the token.
    foreach (pl_list_user_sessions($staff) as $row) {
        assert_same(12, strlen((string) $row['handle']));
        assert_true(!array_key_exists('token_hash', $row));
        assert_true(!str_contains((string) $row['handle'], $first));
    }
    // A session belongs to the copy that created it: a second copy on the same host cannot use it.
    assert_same(null, pl_session_validate($first, $staff, 'installation-b'));
    // And to one account.
    assert_same(null, pl_session_validate($first, $staff + 100000, 'installation-a'));

    // Sign out everywhere else keeps the caller's own session.
    assert_same(1, pl_revoke_user_sessions($staff, $staff, hash('sha256', $first), 'Sample sign-out elsewhere'));
    assert_true(pl_session_validate($first, $staff, 'installation-a') !== null);
    assert_same(null, pl_session_validate($second, $staff, 'installation-a'));

    // Ending one named session by its handle.
    $third = pl_session_open($staff, 'installation-a', 'Fixture browser three');
    pl_revoke_session_handle($staff, substr(hash('sha256', $third), 0, 12), 'Sample handle revocation');
    assert_same(null, pl_session_validate($third, $staff, 'installation-a'));
    assert_throws(fn () => pl_revoke_session_handle($staff, 'not-a-handle', 'Sample'), DomainException::class);

    // Idle and absolute limits, checked on the server rather than in the cookie.
    $fourth = pl_session_open($staff, 'installation-a');
    DB::update('pl_user_sessions', ['last_seen_at' => gmdate('Y-m-d H:i:s', time() - PL_SESSION_IDLE_SECONDS - 1)], 'token_hash = %s', hash('sha256', $fourth));
    assert_same(null, pl_session_validate($fourth, $staff, 'installation-a'), 'An idle session is over.');
    $fifth = pl_session_open($staff, 'installation-a');
    DB::update('pl_user_sessions', ['expires_at' => gmdate('Y-m-d H:i:s', time() - 1)], 'token_hash = %s', hash('sha256', $fifth));
    assert_same(null, pl_session_validate($fifth, $staff, 'installation-a'), 'An expired session is over.');

    // Suspending an account ends its sessions; reactivating does not bring them back.
    $sixth = pl_session_open($staff, 'installation-a');
    pl_assign_company_role($f['actor_id'], $f['company_id'], $staff, users_role_id('viewer'), 'Sample assignment');
    pl_set_user_active($f['actor_id'], $f['company_id'], $staff, false, 'Sample suspension');
    assert_same(null, pl_session_validate($sixth, $staff, 'installation-a'));
    pl_set_user_active($f['actor_id'], $f['company_id'], $staff, true, 'Sample reactivation');
    assert_same(null, pl_session_validate($sixth, $staff, 'installation-a'), 'A revoked session stays revoked.');
});

test('changing your own password needs the current one and signs out every other browser', function (): void {
    $f = users_fixture();
    $password = 'Sample-own-password-471!';
    $staff = pl_create_user('own-' . $f['suffix'] . '@example.invalid', 'Own password staff', $password);
    $keep = pl_session_open($staff, 'installation-a');
    $other = pl_session_open($staff, 'installation-a');
    assert_throws(fn () => pl_change_own_password($staff, 'Wrong current password', 'Sample-next-password-582!'), DomainException::class, 'current password');
    assert_throws(fn () => pl_change_own_password($staff, $password, $password), DomainException::class, 'different');
    pl_change_own_password($staff, $password, 'Sample-next-password-582!', hash('sha256', $keep));
    assert_true(pl_session_validate($keep, $staff, 'installation-a') !== null, 'The browser making the change stays signed in.');
    assert_same(null, pl_session_validate($other, $staff, 'installation-a'), 'Every other browser is signed out.');
    assert_same($staff, pl_authenticate('own-' . $f['suffix'] . '@example.invalid', 'Sample-next-password-582!', 'own-' . $f['suffix'])['id']);
});

test('an email change takes effect only when its confirmation token is presented', function (): void {
    $f = users_fixture();
    $original = 'email-' . $f['suffix'] . '@example.invalid';
    $staff = pl_create_user($original, 'Email staff', 'Sample-email-password-471!');
    $wanted = 'email-new-' . $f['suffix'] . '@example.invalid';
    $change = pl_request_email_change($staff, $wanted);
    assert_same($original, DB::queryFirstField('SELECT email FROM pl_users WHERE id = %i', $staff), 'Nothing moved yet.');
    assert_throws(fn () => pl_confirm_email_change($staff, str_repeat('0', 64)), DomainException::class, 'not valid');
    assert_throws(fn () => pl_request_email_change($staff, $original), DomainException::class, 'already your');
    pl_confirm_email_change($staff, $change['token']);
    assert_same($wanted, DB::queryFirstField('SELECT email FROM pl_users WHERE id = %i', $staff));
    assert_throws(fn () => pl_confirm_email_change($staff, $change['token']), DomainException::class, 'no email change');
    // An address already in use is refused rather than silently reassigned.
    $rival = pl_create_user('rival-' . $f['suffix'] . '@example.invalid', 'Rival staff', 'Sample-rival-password-471!');
    assert_throws(fn () => pl_request_email_change($rival, $wanted), DomainException::class, 'already in use');
});

test('a person named by posted records is never deleted: they are suspended, removed or anonymised', function (): void {
    $f = users_fixture();
    $staff = pl_create_user('kept-' . $f['suffix'] . '@example.invalid', 'Kept staff', 'Sample-kept-password-471!');
    pl_assign_company_role($f['actor_id'], $f['company_id'], $staff, users_role_id('accountant'), 'Sample assignment');
    pl_post_journal($staff, $f['company_id'], $f['book_id'], ledger_payload($f, '25.0000'));
    assert_true(pl_user_is_referenced($staff), 'A posting is a reference.');

    // Suspension is reversible and keeps the membership.
    pl_set_user_active($f['actor_id'], $f['company_id'], $staff, false, 'Sample suspension');
    assert_throws(fn () => pl_require_company_access($staff, $f['company_id']), DomainException::class);
    pl_set_user_active($f['actor_id'], $f['company_id'], $staff, true, 'Sample reactivation');
    assert_same('accountant', pl_require_company_access($staff, $f['company_id'])['role']);

    // Anonymising keeps the row, the id and every reference to it.
    $journals = (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE posted_by = %i', $staff);
    assert_true($journals >= 1);
    $anonymised = pl_anonymise_user($f['actor_id'], $f['company_id'], $staff, 'Sample erasure request');
    assert_same('Former user ' . $staff, $anonymised['display_name']);
    assert_true($anonymised['anonymised_at'] !== null);
    assert_same(false, $anonymised['is_active']);
    assert_same($journals, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE posted_by = %i', $staff), 'Postings still point at the same account.');
    assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_user_meta WHERE user_id = %i', $staff));
    assert_throws(fn () => pl_anonymise_user($f['actor_id'], $f['company_id'], $staff, 'Sample again'), DomainException::class, 'already');

    // You cannot suspend, remove or anonymise yourself, and the last owner cannot lose the company.
    assert_throws(fn () => pl_set_user_active($f['actor_id'], $f['company_id'], $f['actor_id'], false, 'Sample'), DomainException::class, 'your own');
    assert_throws(fn () => pl_remove_company_member($f['actor_id'], $f['company_id'], $f['actor_id'], 'Sample'), DomainException::class, 'your own');
    $second = pl_create_user('second-' . $f['suffix'] . '@example.invalid', 'Second staff', 'Sample-second-password-471!');
    pl_assign_company_role($f['actor_id'], $f['company_id'], $second, users_role_id('viewer'), 'Sample assignment');
    assert_throws(fn () => pl_assign_company_role($second, $f['company_id'], $f['actor_id'], users_role_id('viewer'), 'Sample'), DomainException::class);
    pl_remove_company_member($f['actor_id'], $f['company_id'], $second, 'Sample removal');
    assert_throws(fn () => pl_require_company_access($second, $f['company_id']), DomainException::class);
    assert_true(DB::queryFirstField('SELECT id FROM pl_users WHERE id = %i', $second) !== null, 'The account survives losing one company.');
});

test('an administrator edits the details on an account but never its sign-in name or password', function (): void {
    $f = users_fixture();
    $email = 'edit-' . $f['suffix'] . '@example.invalid';
    $password = 'Sample-edit-password-471!';
    $staff = pl_create_user($email, 'Edit staff', $password);
    pl_assign_company_role($f['actor_id'], $f['company_id'], $staff, users_role_id('accountant'), 'Sample assignment');
    $updated = pl_admin_update_user($f['actor_id'], $f['company_id'], $staff, [
        'display_name' => 'Edited staff', 'first_name' => 'Edited', 'last_name' => 'Staff',
        'phone' => '+92 300 0000000', 'job_title' => 'Counter supervisor',
    ], 'Sample correction of a misspelt name');
    assert_same('Edited staff', $updated['display_name']);
    assert_same('Counter supervisor', $updated['job_title']);
    // The sign-in name and the password are untouched: they belong to the person.
    assert_same($email, DB::queryFirstField('SELECT email FROM pl_users WHERE id = %i', $staff));
    assert_same($staff, pl_authenticate($email, $password, 'edit-' . $f['suffix'])['id']);
    assert_true((int) DB::queryFirstField('SELECT COUNT(*) FROM pl_user_audit WHERE entity_type = %s AND entity_id = %i AND action = %s', 'user', $staff, 'details_updated') === 1);
    // A reason is required, an empty name is refused, and a viewer cannot edit anybody.
    assert_throws(fn () => pl_admin_update_user($f['actor_id'], $f['company_id'], $staff, ['display_name' => 'No reason'], ''), DomainException::class);
    assert_throws(fn () => pl_admin_update_user($f['actor_id'], $f['company_id'], $staff, ['display_name' => ''], 'Sample'), DomainException::class);
    $viewer = pl_create_user('edit-viewer-' . $f['suffix'] . '@example.invalid', 'Edit viewer', 'Sample-editviewer-password-471!');
    pl_assign_company_role($f['actor_id'], $f['company_id'], $viewer, users_role_id('viewer'), 'Sample assignment');
    assert_throws(fn () => pl_admin_update_user($viewer, $f['company_id'], $staff, ['display_name' => 'Hijacked'], 'Sample'), DomainException::class);
    // An anonymised account keeps the placeholder it was given.
    pl_anonymise_user($f['actor_id'], $f['company_id'], $staff, 'Sample erasure');
    assert_throws(fn () => pl_admin_update_user($f['actor_id'], $f['company_id'], $staff, ['display_name' => 'Back again'], 'Sample'), DomainException::class, 'anonymised');
});

test('the per-report cost setting hides cost on the screen and in the machine read alike', function (): void {
    $f = users_fixture();
    $bookId = (int) $f['book_id'];
    // Default: cost shown to a holder of cost.view, so nothing moved for an existing installation.
    assert_same(true, pl_report_cost_setting($f['company_id'], $bookId, 'stock-by-location')['show_cost']);
    assert_same(false, pl_report_cost_setting($f['company_id'], $bookId, 'stock-by-location')['show_margin']);
    assert_same(true, pl_report_cost_visible($f['actor_id'], $f['company_id'], $bookId, 'stock-by-location'));
    assert_same(true, pl_stock_cost_visible($f['actor_id'], $f['company_id'], $bookId));

    // Turn it off for one report: the capability still says yes, the report says no.
    $saved = pl_save_report_cost_setting($f['actor_id'], $f['company_id'], $bookId, 'stock-by-location', false, false, 0, 'Sample trade-secret decision');
    assert_same(1, $saved['revision']);
    assert_true(pl_user_can($f['actor_id'], $f['company_id'], 'cost.view'), 'The person still holds the permission.');
    assert_same(false, pl_report_cost_visible($f['actor_id'], $f['company_id'], $bookId, 'stock-by-location'));
    assert_same(false, pl_stock_cost_visible($f['actor_id'], $f['company_id'], $bookId), 'The report the screen renders hides cost.');
    // Another report on the same book is untouched: this is a per-report decision (B58).
    assert_same(true, pl_report_cost_visible($f['actor_id'], $f['company_id'], $bookId, 'stock-documents'));
    assert_same(true, pl_stock_cost_visible($f['actor_id'], $f['company_id'], $bookId, 'stock-documents'));
    // A stale revision is refused, and an unknown report is not created by asking for it.
    assert_throws(fn () => pl_save_report_cost_setting($f['actor_id'], $f['company_id'], $bookId, 'stock-by-location', true, false, 0, 'Sample'), DomainException::class, 'changed');
    assert_throws(fn () => pl_save_report_cost_setting($f['actor_id'], $f['company_id'], $bookId, 'not-a-report', true, false, 0, 'Sample'), DomainException::class);

    // Without cost.view the setting cannot bring cost back.
    $viewer = pl_create_user('cost-' . $f['suffix'] . '@example.invalid', 'Cost viewer', 'Sample-cost-password-471!');
    pl_assign_company_role($f['actor_id'], $f['company_id'], $viewer, users_role_id('viewer'), 'Sample assignment');
    pl_save_report_cost_setting($f['actor_id'], $f['company_id'], $bookId, 'stock-by-location', true, true, 1, 'Sample restore');
    pl_capability_cache_reset();
    assert_same(false, pl_report_cost_visible($viewer, $f['company_id'], $bookId, 'stock-by-location'));
    assert_same(false, pl_report_margin_visible($viewer, $f['company_id'], $bookId, 'stock-by-location'));
    assert_true(!pl_user_can($viewer, $f['company_id'], 'reports.cost_settings.manage'));
    assert_throws(fn () => pl_save_report_cost_setting($viewer, $f['company_id'], $bookId, 'stock-by-location', false, false, 2, 'Sample'), DomainException::class);
    // Cross-company isolation: another company's book is not this company's setting.
    $other = users_fixture();
    assert_same(true, pl_report_cost_setting($other['company_id'], (int) $other['book_id'], 'stock-by-location')['show_cost']);
    assert_throws(fn () => pl_save_report_cost_setting($f['actor_id'], $other['company_id'], (int) $other['book_id'], 'stock-by-location', false, false, 0, 'Sample'), DomainException::class);
    // The change is in the core audit, under the entity type migration 040 added to the union.
    assert_true((int) DB::queryFirstField('SELECT COUNT(*) FROM pl_core_audit WHERE company_id = %i AND entity_type = %s', $f['company_id'], 'report_cost_setting') >= 2);
});

test('the user list and its history are company-scoped and need the users.manage permission', function (): void {
    $one = users_fixture();
    $two = users_fixture();
    $staff = pl_create_user('scope-' . $one['suffix'] . '@example.invalid', 'Scoped staff', 'Sample-scope-password-471!');
    pl_assign_company_role($one['actor_id'], $one['company_id'], $staff, users_role_id('viewer'), 'Sample assignment');
    $ids = array_map(static fn (array $row): int => $row['id'], pl_list_company_users($one['actor_id'], $one['company_id']));
    assert_true(in_array($staff, $ids, true));
    assert_true(!in_array($two['actor_id'], $ids, true), 'Another company\'s owner is not in this list.');
    assert_throws(fn () => pl_get_company_user($two['actor_id'], $two['company_id'], $staff), DomainException::class);
    // A viewer holds neither the list nor the history.
    assert_throws(fn () => pl_list_company_users($staff, $one['company_id']), DomainException::class);
    assert_throws(fn () => pl_user_history($staff, $one['company_id'], 'user', $staff), DomainException::class);
    assert_throws(fn () => pl_assign_company_role($staff, $one['company_id'], $staff, users_role_id('owner'), 'Sample self-promotion'), DomainException::class);
    assert_true(count(pl_user_history($one['actor_id'], $one['company_id'], 'membership', $staff)) >= 1);
});

test('the user audit is immutable, like every other audit table', function (): void {
    $f = users_fixture();
    $id = (int) DB::queryFirstField('SELECT id FROM pl_user_audit ORDER BY id DESC LIMIT 1');
    assert_true($id > 0, 'The module wrote audit rows.');
    assert_throws(fn () => DB::query('UPDATE pl_user_audit SET reason = %s WHERE id = %i', 'changed', $id));
    assert_throws(fn () => DB::query('DELETE FROM pl_user_audit WHERE id = %i', $id));
    // No secret ever reaches an audit row.
    assert_same([], array_filter(
        DB::queryFirstColumn('SELECT after_state FROM pl_user_audit ORDER BY id DESC LIMIT 200'),
        static fn ($state): bool => str_contains((string) $state, 'password_hash') || str_contains((string) $state, 'token_hash')
    ));
});

test('the MeekroORM records read the same rows, and cost one metadata query each, away from the hot paths', function (): void {
    $f = users_fixture();
    $user = PL_User::Load($f['actor_id']);
    assert_true($user instanceof PL_User);
    assert_same((int) $f['actor_id'], (int) $user->id);
    assert_true($user->isActive());
    assert_true(!$user->isAnonymised());
    assert_true(!array_key_exists('password_hash', $user->toArray()), 'The model never hands out the password hash.');
    $role = $user->roleIn((int) $f['company_id']);
    assert_true($role instanceof PL_Role);
    assert_same('owner', (string) $role->slug);
    assert_true($role->isProtected());
    assert_true($role->isInstallationWide());
    assert_true($role->grants('cost.view'));
    assert_same(1, $role->memberCount((int) $f['company_id']));
    $capability = PL_Capability::findByName('cost.view');
    assert_true($capability instanceof PL_Capability);
    assert_true(!$capability->isInstallationScope());
    assert_true($capability->isEffectiveIn((int) $f['company_id']), 'A core capability is always effective.');
    assert_same($f['actor_id'], (int) PL_User::findByEmail((string) $user->email)->id);
    assert_true(PL_Role::findBySlug(null, 'viewer') instanceof PL_Role);

    // The measurement the milestone asked for, taken rather than assumed: the ORM asks the server
    // for a model's columns once per model per request, and the hot paths ask for none.
    MeekroORM::_orm_struct_reset();
    $cold = pl_model_metadata_probe(static function () use ($f): void {
        PL_User::Load($f['actor_id']);
        PL_Role::findBySlug(null, 'owner');
        PL_Capability::findByName('cost.view');
    });
    assert_same(3, $cold, 'Three models, three SHOW COLUMNS round trips on a cold request.');
    $warm = pl_model_metadata_probe(static function () use ($f): void {
        PL_User::Load($f['actor_id']);
        PL_Role::findBySlug(null, 'owner');
        PL_Capability::findByName('cost.view');
    });
    assert_same(0, $warm, 'Within one request the structure is cached; between requests it is not.');
    MeekroORM::_orm_struct_reset();
    $hot = pl_model_metadata_probe(static function () use ($f): void {
        pl_user_can($f['actor_id'], $f['company_id'], 'cost.view');
        pl_require_company_access($f['actor_id'], $f['company_id']);
        pl_report_cost_visible($f['actor_id'], $f['company_id'], (int) $f['book_id'], 'stock-by-location');
        pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], ledger_payload($f, '1.0000'));
    });
    assert_same(0, $hot, 'Authorisation and posting stay on plain MeekroDB, as PL_Model documents.');

    // A model that does not name its table is refused rather than guessing one.
    assert_throws(static fn () => PL_Unnamed_Model::_tablename(), MeekroORMException::class);
});

/** A model that forgot its table name: PHP Ledger never infers one from the class name. */
class PL_Unnamed_Model extends PL_Model
{
}

test('migration 056 preserves explicit custom roles and backfills only missing legacy role references', function (): void {
    $f = users_fixture();
    $custom = pl_save_role($f['actor_id'], $f['company_id'], ['name'=>'Sample scoped writer', 'reason'=>'Upgrade fixture', 'capabilities'=>['company.read','company.write']]);
    DB::query("CREATE TEMPORARY TABLE pl_membership_upgrade_sample (company_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL, role ENUM('owner','accountant','viewer') NOT NULL, role_id BIGINT UNSIGNED NULL)");
    try {
        DB::insert('pl_membership_upgrade_sample', ['company_id'=>$f['company_id'],'user_id'=>1,'role'=>'owner','role_id'=>null]);
        DB::insert('pl_membership_upgrade_sample', ['company_id'=>$f['company_id'],'user_id'=>2,'role'=>'viewer','role_id'=>$custom['id']]);
        foreach (require dirname(__DIR__).'/www/phpledger/install/migrations/056_membership_role_id.php' as $sql) {
            DB::query(str_replace('pl_company_members','pl_membership_upgrade_sample',$sql));
        }
        assert_same(users_role_id('owner'), (int) DB::queryFirstField('SELECT role_id FROM pl_membership_upgrade_sample WHERE user_id=1'));
        assert_same($custom['id'], (int) DB::queryFirstField('SELECT role_id FROM pl_membership_upgrade_sample WHERE user_id=2'));
        assert_throws(fn()=>DB::insert('pl_membership_upgrade_sample',['company_id'=>$f['company_id'],'user_id'=>3,'role_id'=>null]));
        assert_same('varchar(10)', DB::queryFirstRow("SHOW COLUMNS FROM pl_membership_upgrade_sample LIKE 'role'")['Type']);
        assert_same(['owner', 'viewer'], DB::queryFirstColumn('SELECT role FROM pl_membership_upgrade_sample ORDER BY user_id'));
        assert_same('varchar(10)', DB::queryFirstRow("SHOW COLUMNS FROM pl_company_members LIKE 'role'")['Type']);
    } finally { DB::query('DROP TEMPORARY TABLE pl_membership_upgrade_sample'); }
});

test('role-only membership rejects foreign company roles and derives write access from current custom grants', function (): void {
    $f=users_fixture();$other=users_fixture();
    $writer=pl_save_role($f['actor_id'],$f['company_id'],['name'=>'Sample current writer','reason'=>'Scope fixture','capabilities'=>['company.read','company.write']]);
    sample_membership_insert(['company_id'=>$other['company_id'],'user_id'=>$f['actor_id'],'role_id'=>$writer['id']]);
    assert_throws(fn()=>pl_require_company_access($f['actor_id'],$other['company_id']),DomainException::class);
    sample_membership_insert(['company_id'=>$f['company_id'],'user_id'=>$other['actor_id'],'role_id'=>$writer['id']]);
    assert_same('accountant',pl_require_company_access($other['actor_id'],$f['company_id'],true)['role']);
    pl_save_role($f['actor_id'],$f['company_id'],['name'=>$writer['name'],'reason'=>'Revoke writing','capabilities'=>['company.read']],$writer['id'],$writer['revision']);
    DB::update('pl_company_members', ['role'=>'owner'], 'company_id=%i AND user_id=%i', $f['company_id'], $other['actor_id']);
    assert_same('viewer',pl_require_company_access($other['actor_id'],$f['company_id'])['role']);
    assert_throws(fn()=>pl_require_company_access($other['actor_id'],$f['company_id'],true),DomainException::class);
});

test('an inactive second owner cannot justify removing the only active owner role', function (): void {
    $f=users_fixture();$other=users_fixture();
    sample_membership_insert(['company_id'=>$f['company_id'],'user_id'=>$other['actor_id'],'role'=>'owner']);
    DB::update('pl_users',['is_active'=>0],'id=%i',$other['actor_id']);
    assert_throws(fn()=>pl_assign_company_role($f['actor_id'],$f['company_id'],$f['actor_id'],users_role_id('viewer'),'Invalid demotion'),DomainException::class,'without an owner');
    assert_same('owner',pl_require_company_access($f['actor_id'],$f['company_id'])['role']);
    pl_remove_company_member($f['actor_id'],$f['company_id'],$other['actor_id'],'Remove inactive extra owner');
    assert_same(1,pl_company_owner_count($f['company_id']));
});

test('an old read snapshot cannot retain revoked custom write grants', function (): void {
    $f=users_fixture();$other=users_fixture();
    $role=pl_save_role($f['actor_id'],$f['company_id'],['name'=>'Sample revoked writer','reason'=>'Current grant fixture','capabilities'=>['company.read','company.write']]);
    sample_membership_insert(['company_id'=>$f['company_id'],'user_id'=>$other['actor_id'],'role_id'=>$role['id']]);
    $second=new MeekroDB();DB::startTransaction();
    try {
        assert_true((int)DB::queryFirstField('SELECT COUNT(*) FROM pl_role_capabilities WHERE role_id=%i',$role['id'])>1);
        $second->query("DELETE rc FROM pl_role_capabilities rc JOIN pl_capabilities c ON c.id=rc.capability_id WHERE rc.role_id=%i AND c.capability='company.write'",$role['id']);
        assert_throws(fn()=>pl_require_company_access($other['actor_id'],$f['company_id'],true),DomainException::class);
        assert_true(!pl_user_can($other['actor_id'],$f['company_id'],'company.write'));
    } finally { DB::rollback();$second->disconnect(); }
});

test('accepting an older invitation cannot demote the sole active Owner', function (): void {
    $f=users_fixture();$other=users_fixture();
    $email=DB::queryFirstField('SELECT email FROM pl_users WHERE id=%i',$other['actor_id']);
    $invitation=pl_invite_user($f['actor_id'],$f['company_id'],$email,users_role_id('viewer'),'Initial invitation');
    pl_assign_company_role($f['actor_id'],$f['company_id'],$other['actor_id'],users_role_id('owner'),'Owner since invitation');
    pl_assign_company_role($f['actor_id'],$f['company_id'],$f['actor_id'],users_role_id('accountant'),'Transfer ownership');
    assert_throws(fn()=>pl_accept_invitation($invitation['token'],[]),DomainException::class,'without an active owner');
    assert_same('owner',pl_require_company_access($other['actor_id'],$f['company_id'])['role']);
});

test('an installation-wide suspension or anonymisation cannot strand another company', function (): void {
    $a=users_fixture();$b=users_fixture();
    sample_membership_insert(['company_id'=>$a['company_id'],'user_id'=>$b['actor_id'],'role'=>'accountant']);
    assert_throws(fn()=>pl_set_user_active($a['actor_id'],$a['company_id'],$b['actor_id'],false,'Suspend through another company'),DomainException::class,'last active owner');
    assert_throws(fn()=>pl_anonymise_user($a['actor_id'],$a['company_id'],$b['actor_id'],'Erase through another company'),DomainException::class,'last active owner');
    assert_same('owner',pl_require_company_access($b['actor_id'],$b['company_id'])['role']);
    pl_assign_company_role($b['actor_id'],$b['company_id'],$a['actor_id'],users_role_id('owner'),'Add replacement owner');
    pl_set_user_active($a['actor_id'],$a['company_id'],$b['actor_id'],false,'Replacement owner is active');
    assert_same(0,(int)DB::queryFirstField('SELECT is_active FROM pl_users WHERE id=%i',$b['actor_id']));
    assert_same('owner',pl_require_company_access($a['actor_id'],$b['company_id'])['role']);
});

test('global account change refuses a company membership added after its caller snapshot', function (): void {
    $a=users_fixture();$b=users_fixture();$c=users_fixture();
    sample_membership_insert(['company_id'=>$a['company_id'],'user_id'=>$b['actor_id'],'role'=>'accountant']);
    $second=new MeekroDB();DB::startTransaction();
    try {
        assert_same(2,(int)DB::queryFirstField('SELECT COUNT(*) FROM pl_company_members WHERE user_id=%i',$b['actor_id']));
        $second->insert('pl_company_members',['company_id'=>$c['company_id'],'user_id'=>$b['actor_id'],'role_id'=>users_role_id('owner')]);
        assert_throws(fn()=>pl_set_user_active($a['actor_id'],$a['company_id'],$b['actor_id'],false,'Old account snapshot'),DomainException::class,'changed company memberships');
        assert_same(1,(int)DB::queryFirstField('SELECT is_active FROM pl_users WHERE id=%i',$b['actor_id']));
    } finally { DB::rollback();$second->disconnect(); }
});
