<?php
declare(strict_types=1);
require_once __DIR__ . '/shared_demo_functions.php';

/**
 * The Users module (release plan 1.2, milestone M7; owner decisions B17, B44, B53).
 *
 * People, their profiles, their invitations, their sessions and the immutable record of what was
 * done to their accounts. Authorisation lives next door in capability_functions.php; this file
 * asks it the questions and never repeats a role name.
 *
 * DELETION. A user referenced by a posted record is never hard-deleted. `pl_journals.posted_by`,
 * `pl_core_audit.actor_id` and every other actor column are the answer to "who did this", and an
 * accounting trail that loses its actor is no longer a trail (AGENTS.md: posted entries are
 * immutable and carry a durable source reference). Three lesser acts exist instead: suspend
 * (pl_set_user_active, reversible), remove from a company (pl_remove_company_member, the account
 * survives elsewhere) and anonymise (pl_anonymise_user, irreversible, keeps the row and its id
 * and replaces the personal data on it).
 *
 * TOKENS. Invitations, password resets and email confirmations issue a token that is shown ONCE
 * and stored only as a SHA-256. This release has no mail transport, so the screen shows the
 * one-time link to the administrator, who passes it on. Sending it by email is M8 work and is
 * recorded as a gap in docs/wiki/Users-and-Roles.md rather than faked here.
 */

/** Immutable user audit, the pl_core_audit shape with a nullable company and no book. */
function pl_user_audit(int $actorId, ?int $companyId, ?int $subjectUserId, string $entity, int $entityId, string $action, string $reason, ?array $before, array $after): void
{
    DB::insert('pl_user_audit', [
        'company_id' => $companyId, 'actor_id' => $actorId, 'subject_user_id' => $subjectUserId,
        'entity_type' => $entity, 'entity_id' => $entityId, 'action' => $action, 'reason' => $reason,
        'before_state' => $before === null ? null : json_encode(pl_user_audit_safe($before), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        'after_state' => json_encode(pl_user_audit_safe($after), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
    ]);
}

/** A password hash, a session token hash or an invitation token never reaches an audit row. */
function pl_user_audit_safe(array $state): array
{
    foreach (['password_hash', 'token', 'token_hash', 'session_token'] as $secret) {
        unset($state[$secret]);
    }
    return $state;
}

function pl_user_history(int $actorId, int $companyId, string $entity, int $entityId): array
{
    pl_require_company_access($actorId, $companyId);
    pl_require_capability($actorId, $companyId, 'users.manage', 'Your role cannot read the user history for this company.');
    return DB::query(
        'SELECT a.id, a.action, a.reason, a.recorded_at, u.display_name FROM pl_user_audit a '
        . 'JOIN pl_users u ON u.id = a.actor_id '
        . 'WHERE (a.company_id = %i OR a.company_id IS NULL) AND a.entity_type = %s AND a.entity_id = %i '
        . 'ORDER BY a.id DESC LIMIT 50',
        $companyId,
        $entity,
        $entityId
    );
}

// ------------------------------------------------------------------------------------ user meta

function pl_user_meta_get(int $userId, string $key, ?string $default = null): ?string
{
    $value = DB::queryFirstField('SELECT meta_value FROM pl_user_meta WHERE user_id = %i AND meta_key = %s', $userId, $key);
    return is_string($value) ? $value : $default;
}

function pl_user_meta_set(int $userId, string $key, ?string $value): void
{
    if (in_array($key, ['password_reset','email_change'], true) && $value !== null) { pl_shared_demo_require_mutable_account($userId); }
    if (!preg_match('/^[a-z][a-z0-9_:.-]{0,188}$/D', $key)) {
        throw new InvalidArgumentException('Invalid user meta key.');
    }
    if ($value === null) {
        DB::query('DELETE FROM pl_user_meta WHERE user_id = %i AND meta_key = %s', $userId, $key);
        return;
    }
    DB::insertUpdate('pl_user_meta', ['user_id' => $userId, 'meta_key' => $key, 'meta_value' => $value, 'updated_at' => gmdate('Y-m-d H:i:s')],
        ['meta_value' => $value, 'updated_at' => gmdate('Y-m-d H:i:s')]);
}

// ------------------------------------------------------------------------------- reading people

/** The columns a screen may see. `password_hash` is not one of them. */
const PL_USER_COLUMNS = 'id, email, username, display_name, first_name, last_name, phone, job_title, locale, timezone, '
    . 'must_change_password, password_changed_at, is_active, last_signed_in_at, deactivated_at, anonymised_at, created_at';

function pl_user_row(int $userId): ?array
{
    $row = DB::queryFirstRow('SELECT ' . PL_USER_COLUMNS . ' FROM pl_users WHERE id = %i', $userId);
    return $row ? pl_user_shape($row) : null;
}

function pl_user_shape(array $row): array
{
    $row['id'] = (int) $row['id'];
    foreach (['is_active', 'must_change_password'] as $flag) {
        $row[$flag] = (bool) $row[$flag];
    }
    return $row;
}

/** Everyone who works in this company, with the role they hold here. */
function pl_list_company_users(int $actorId, int $companyId): array
{
    pl_require_company_access($actorId, $companyId);
    pl_require_capability($actorId, $companyId, 'users.manage', 'Your role cannot read the people in this company.');
    $rows = DB::query(
        'SELECT u.' . str_replace(', ', ', u.', PL_USER_COLUMNS) . ', r.slug AS role, m.role_id, r.name AS role_name, r.slug AS role_slug, r.is_system AS role_is_system '
        . 'FROM pl_company_members m JOIN pl_users u ON u.id = m.user_id '
        . 'LEFT JOIN pl_roles r ON r.id = m.role_id '
        . 'WHERE m.company_id = %i ORDER BY u.is_active DESC, u.display_name, u.id' . (DB::transactionDepth() > 0 ? ' FOR SHARE' : ''),
        $companyId
    );
    foreach ($rows as &$row) {
        $row = pl_user_shape($row);
        $row['role_id'] = $row['role_id'] === null ? null : (int) $row['role_id'];
        $row['role_is_system'] = (bool) $row['role_is_system'];
        $row['open_sessions'] = (int) DB::queryFirstField(
            'SELECT COUNT(*) FROM pl_user_sessions WHERE user_id = %i AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()',
            $row['id']
        );
        $row['is_installation_admin'] = in_array('installation.admin', pl_user_installation_grants($row['id']), true);
    }
    unset($row);
    return $rows;
}

/** One member of this company. Cross-company isolation: a user outside it is simply not found. */
function pl_get_company_user(int $actorId, int $companyId, int $userId): array
{
    foreach (pl_list_company_users($actorId, $companyId) as $row) {
        if ($row['id'] === $userId) {
            return $row;
        }
    }
    throw new DomainException('That person does not work in this company.');
}

/** Is this account named by a posted record or an audit row? Then it is never hard-deleted. */
function pl_user_is_referenced(int $userId): bool
{
    foreach ([['pl_journals', 'posted_by'], ['pl_core_audit', 'actor_id'], ['pl_user_audit', 'actor_id'],
        ['pl_module_actions', 'actor_id'], ['pl_company_members', 'user_id'], ['pl_companies', 'created_by']] as [$table, $column]) {
        if (DB::queryFirstField('SELECT 1 FROM %b WHERE %b = %i LIMIT 1', $table, $column, $userId) !== null) {
            return true;
        }
    }
    return false;
}

// --------------------------------------------------------------------------------- the profile

/** Timezones a profile may choose. The application stores every DATETIME in UTC regardless. */
function pl_timezone_options(): array
{
    return DateTimeZone::listIdentifiers();
}

/**
 * Edit your own profile. Display name, the optional username, locale and timezone. The email
 * address is NOT changed here: it is the sign-in name, so it goes through a confirmation
 * (pl_request_email_change / pl_confirm_email_change).
 */
function pl_update_profile(int $userId, array $input): array
{
    $displayName = pl_ledger_text($input['display_name'] ?? '', 'Name', 120);
    $firstName = pl_ledger_text($input['first_name'] ?? '', 'First name', 80, false);
    $lastName = pl_ledger_text($input['last_name'] ?? '', 'Last name', 80, false);
    $phone = pl_ledger_text($input['phone'] ?? '', 'Phone', 40, false);
    $jobTitle = pl_ledger_text($input['job_title'] ?? '', 'Job title', 120, false);
    $username = trim((string) ($input['username'] ?? ''));
    $username = $username === '' ? null : pl_normalize_username($username);
    $locale = trim((string) ($input['locale'] ?? ''));
    $locale = $locale === '' ? null : pl_normalize_locale($locale);
    $timezone = trim((string) ($input['timezone'] ?? ''));
    if ($timezone !== '' && !in_array($timezone, pl_timezone_options(), true)) {
        throw new DomainException('Choose a listed time zone.');
    }
    return pl_ledger_transaction(function () use ($userId, $displayName, $firstName, $lastName, $phone, $jobTitle, $username, $locale, $timezone): array {
        $before = pl_user_row($userId);
        if ($before === null) {
            throw new DomainException('This account is not available.');
        }
        if ($username !== $before['username']) { pl_shared_demo_require_mutable_account($userId); }
        if ($username !== null && $username !== $before['username']
            && DB::queryFirstField('SELECT id FROM pl_users WHERE username = %s AND id <> %i', $username, $userId) !== null) {
            throw new DomainException('That username is already taken. Choose another one.');
        }
        DB::update('pl_users', [
            'display_name' => $displayName, 'first_name' => $firstName ?: null, 'last_name' => $lastName ?: null,
            'phone' => $phone ?: null, 'job_title' => $jobTitle ?: null, 'username' => $username,
            'locale' => $locale, 'timezone' => $timezone ?: null,
        ], 'id = %i', $userId);
        $after = pl_user_row($userId);
        pl_user_audit($userId, null, $userId, 'user', $userId, 'profile_updated', 'Profile updated by its owner.', $before, (array) $after);
        return (array) $after;
    });
}

/**
 * Set only the interface language of your own account (1.2 M11, the language switch).
 *
 * This is deliberately NOT pl_update_profile(): that function is the whole profile form and
 * rewrites display name, username, phone, job title and time zone from its input, so calling it
 * with a language alone would blank the rest of the account. A switch in the top bar changes one
 * column and says so in the audit trail.
 *
 * null or an empty tag clears the preference, which returns the account to the hosting default.
 */
function pl_set_user_locale(int $userId, ?string $locale): ?string
{
    $normalized = $locale === null || trim($locale) === '' ? null : pl_normalize_locale($locale);
    return pl_ledger_transaction(function () use ($userId, $normalized): ?string {
        $before = pl_user_row($userId);
        if ($before === null) {
            throw new DomainException('This account is not available.');
        }
        if (($before['locale'] ?? null) === $normalized) {
            return $normalized;
        }
        DB::update('pl_users', ['locale' => $normalized], 'id = %i', $userId);
        $after = (array) pl_user_row($userId);
        pl_user_audit($userId, null, $userId, 'user', $userId, 'profile_updated',
            'Interface language changed by its owner.', $before, $after);
        return $normalized;
    });
}

/**
 * Change your own password. The current password is required — an open session is not enough, so
 * a borrowed screen cannot be turned into a permanent takeover — and every OTHER session is
 * revoked, which is the point of server-side sessions.
 */
function pl_change_own_password(int $userId, string $current, string $new, ?string $keepTokenHash = null): void
{
    pl_shared_demo_require_mutable_account($userId);
    $hash = DB::queryFirstField('SELECT password_hash FROM pl_users WHERE id = %i AND is_active = 1', $userId);
    if (!is_string($hash) || !pl_verify_password($current, $hash)) {
        throw new DomainException('That is not your current password.');
    }
    if ($new === $current) {
        throw new DomainException('Choose a new password that is different from the current one.');
    }
    $newHash = pl_hash_password($new);
    pl_ledger_transaction(function () use ($userId, $newHash, $keepTokenHash): void {
        DB::update('pl_users', ['password_hash' => $newHash, 'must_change_password' => 0,
            'password_changed_at' => gmdate('Y-m-d H:i:s')], 'id = %i', $userId);
        pl_revoke_user_sessions($userId, $userId, $keepTokenHash, 'Password changed.');
        pl_user_audit($userId, null, $userId, 'user', $userId, 'password_changed', 'Password changed by its owner.', null, ['id' => $userId]);
    });
}

/**
 * Ask to change your sign-in email address. The new address is held, unconfirmed, in user meta
 * with a one-time token; the account keeps signing in with the old address until the token is
 * presented. An unconfirmed request does not reserve the address.
 */
function pl_request_email_change(int $userId, string $email): array
{
    pl_shared_demo_require_mutable_account($userId);
    $email = strtolower(trim($email));
    if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new DomainException('Enter a valid email address.');
    }
    $current = pl_user_row($userId);
    if ($current === null) {
        throw new DomainException('This account is not available.');
    }
    if ($email === $current['email']) {
        throw new DomainException('That is already your email address.');
    }
    if (DB::queryFirstField('SELECT id FROM pl_users WHERE email = %s', $email) !== null) {
        throw new DomainException('That email address is already in use.');
    }
    $token = bin2hex(random_bytes(32));
    pl_user_meta_set($userId, 'email_change', json_encode([
        'email' => $email, 'token_hash' => hash('sha256', $token), 'expires_at' => time() + 86400,
    ], JSON_THROW_ON_ERROR));
    pl_user_audit($userId, null, $userId, 'user', $userId, 'email_change_requested', 'Email change requested by its owner.',
        ['email' => $current['email']], ['email_pending' => $email]);
    return ['email' => $email, 'token' => $token];
}

/** Present the token to complete the change. Every session is revoked: the sign-in name moved. */
function pl_confirm_email_change(int $userId, string $token): array
{
    pl_shared_demo_require_mutable_account($userId);
    $raw = pl_user_meta_get($userId, 'email_change');
    if ($raw === null) {
        throw new DomainException('There is no email change waiting for confirmation.');
    }
    try {
        $pending = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new DomainException('There is no email change waiting for confirmation.');
    }
    if (!is_array($pending) || !is_string($pending['token_hash'] ?? null) || !is_string($pending['email'] ?? null)
        || !is_int($pending['expires_at'] ?? null) || $pending['expires_at'] < time()
        || !hash_equals($pending['token_hash'], hash('sha256', $token))) {
        throw new DomainException('That confirmation link is not valid any more. Request the change again.');
    }
    return pl_ledger_transaction(function () use ($userId, $pending): array {
        $before = pl_user_row($userId);
        if (DB::queryFirstField('SELECT id FROM pl_users WHERE email = %s AND id <> %i', $pending['email'], $userId) !== null) {
            throw new DomainException('That email address is already in use.');
        }
        DB::update('pl_users', ['email' => $pending['email']], 'id = %i', $userId);
        pl_user_meta_set($userId, 'email_change', null);
        pl_revoke_user_sessions($userId, $userId, null, 'Sign-in email address changed.');
        $after = pl_user_row($userId);
        pl_user_audit($userId, null, $userId, 'user', $userId, 'email_changed', 'Email change confirmed.', $before, (array) $after);
        return (array) $after;
    });
}

// ----------------------------------------------------------------------- administering people

/**
 * Edit the details on somebody else's account: the name they are shown by and how to reach them.
 *
 * Deliberately NOT here: the email address and the username, which are sign-in names and belong to
 * the person (they change their own, with a confirmation); and the password, which no
 * administrator ever chooses — pl_force_password_reset() hands over a one-time link instead. An
 * administrator who could quietly change a sign-in name and a password could sign in as that
 * person, and the books record who did what by that account.
 */
function pl_admin_update_user(int $actorId, int $companyId, int $userId, array $input, string $reason): array
{
    pl_demo_require_setup_action();
    $reason = pl_ledger_text($reason, 'Reason', 500);
    $displayName = pl_ledger_text($input['display_name'] ?? '', 'Name', 120);
    $firstName = pl_ledger_text($input['first_name'] ?? '', 'First name', 80, false);
    $lastName = pl_ledger_text($input['last_name'] ?? '', 'Last name', 80, false);
    $phone = pl_ledger_text($input['phone'] ?? '', 'Phone', 40, false);
    $jobTitle = pl_ledger_text($input['job_title'] ?? '', 'Job title', 120, false);
    return pl_ledger_transaction(function () use ($actorId, $companyId, $userId, $displayName, $firstName, $lastName, $phone, $jobTitle, $reason): array {
        pl_require_company_access($actorId, $companyId, true);
        pl_require_capability($actorId, $companyId, 'users.manage', 'Your role cannot edit the people in this company.');
        $before = pl_get_company_user($actorId, $companyId, $userId);
        if ($before['anonymised_at'] !== null) {
            throw new DomainException('This account has been anonymised and its details are not editable.');
        }
        DB::update('pl_users', [
            'display_name' => $displayName, 'first_name' => $firstName ?: null, 'last_name' => $lastName ?: null,
            'phone' => $phone ?: null, 'job_title' => $jobTitle ?: null,
        ], 'id = %i', $userId);
        $after = pl_get_company_user($actorId, $companyId, $userId);
        pl_user_audit($actorId, $companyId, $userId, 'user', $userId, 'details_updated', $reason, $before, $after);
        return $after;
    });
}

/** Suspend or reactivate an account. Reversible, and it keeps every record the person posted. */
function pl_set_user_active(int $actorId, int $companyId, int $userId, bool $active, string $reason): array
{
    if (!$active) { pl_shared_demo_require_mutable_account($userId); }
    pl_demo_require_setup_action();
    $reason = pl_ledger_text($reason, 'Reason', 500);
    return pl_ledger_transaction(function () use ($actorId, $companyId, $userId, $active, $reason): array {
        pl_lock_user_membership_companies($userId, $companyId);
        pl_require_company_access($actorId, $companyId, true);
        pl_require_capability($actorId, $companyId, 'users.manage', 'Your role cannot suspend or reactivate people.');
        $before = pl_get_company_user($actorId, $companyId, $userId);
        if ($actorId === $userId && !$active) {
            throw new DomainException('You cannot suspend your own account. Ask another administrator.');
        }
        if (!$active) { pl_require_other_active_owners($userId); }
        DB::update('pl_users', ['is_active' => $active ? 1 : 0, 'deactivated_at' => $active ? null : gmdate('Y-m-d H:i:s')], 'id = %i', $userId);
        if (!$active) {
            pl_revoke_user_sessions($actorId, $userId, null, $reason);
        }
        pl_capability_cache_reset();
        $after = pl_get_company_user($actorId, $companyId, $userId);
        pl_user_audit($actorId, $companyId, $userId, 'user', $userId, $active ? 'reactivated' : 'suspended', $reason, $before, $after);
        return $after;
    });
}

/**
 * Take someone out of this company without touching their account. Their postings keep their
 * name; they simply stop having access here. The last active owner cannot be removed.
 */
function pl_remove_company_member(int $actorId, int $companyId, int $userId, string $reason): void
{
    pl_shared_demo_require_mutable_account($userId);
    pl_demo_require_setup_action();
    $reason = pl_ledger_text($reason, 'Reason', 500);
    pl_ledger_transaction(function () use ($actorId, $companyId, $userId, $reason): void {
        DB::queryFirstField('SELECT id FROM pl_companies WHERE id = %i FOR UPDATE', $companyId);
        pl_require_company_access($actorId, $companyId, true);
        pl_require_capability($actorId, $companyId, 'users.manage', 'Your role cannot remove people from this company.');
        $before = pl_get_company_user($actorId, $companyId, $userId);
        if ($actorId === $userId) {
            throw new DomainException('You cannot remove your own access. Ask another administrator.');
        }
        if ($before['role'] === 'owner'
            && pl_company_owner_count($companyId, $userId) < 1) {
            throw new DomainException('This company would be left without an owner. Give someone else the Owner role first.');
        }
        DB::query('DELETE FROM pl_company_members WHERE company_id = %i AND user_id = %i', $companyId, $userId);
        pl_capability_cache_reset();
        pl_user_audit($actorId, $companyId, $userId, 'membership', $userId, 'removed', $reason, $before, ['company_id' => $companyId, 'user_id' => $userId, 'role' => null]);
    });
}

/**
 * Replace the personal data on an account, keeping the row, its id and every reference to it.
 * Irreversible by design: this is the answer to an erasure request that an accounting trail can
 * honour. The account is deactivated, its sessions are revoked, and its invitations are closed.
 */
function pl_anonymise_user(int $actorId, int $companyId, int $userId, string $reason): array
{
    pl_shared_demo_require_mutable_account($userId);
    pl_demo_require_setup_action();
    $reason = pl_ledger_text($reason, 'Reason', 500);
    return pl_ledger_transaction(function () use ($actorId, $companyId, $userId, $reason): array {
        pl_lock_user_membership_companies($userId, $companyId);
        pl_require_company_access($actorId, $companyId, true);
        pl_require_capability($actorId, $companyId, 'users.manage', 'Your role cannot anonymise an account.');
        $before = pl_get_company_user($actorId, $companyId, $userId);
        if ($actorId === $userId) {
            throw new DomainException('You cannot anonymise your own account. Ask another administrator.');
        }
        if ($before['anonymised_at'] !== null) {
            throw new DomainException('This account has already been anonymised.');
        }
        if ($before['role'] === 'owner') {
            throw new DomainException('Give the Owner role to someone else before anonymising this account.');
        }
        pl_require_other_active_owners($userId);
        $marker = 'anonymised-' . $userId;
        DB::update('pl_users', [
            'email' => $marker . '@anonymised.invalid', 'username' => null,
            'display_name' => 'Former user ' . $userId, 'first_name' => null, 'last_name' => null,
            'phone' => null, 'job_title' => null, 'is_active' => 0,
            'deactivated_at' => gmdate('Y-m-d H:i:s'), 'anonymised_at' => gmdate('Y-m-d H:i:s'),
            'password_hash' => pl_hash_password(bin2hex(random_bytes(24))),
        ], 'id = %i', $userId);
        DB::query('DELETE FROM pl_user_meta WHERE user_id = %i', $userId);
        pl_revoke_user_sessions($actorId, $userId, null, $reason);
        DB::update('pl_user_invitations', ['revoked_at' => gmdate('Y-m-d H:i:s')], 'accepted_user_id = %i AND accepted_at IS NULL', $userId);
        pl_capability_cache_reset();
        $after = pl_get_company_user($actorId, $companyId, $userId);
        pl_user_audit($actorId, $companyId, $userId, 'user', $userId, 'anonymised', $reason, $before, $after);
        return $after;
    });
}

/**
 * Force a password reset. The old password stops working at once, every session is revoked, and
 * a one-time token is returned for the administrator to hand over. This release has no mailer.
 */
function pl_force_password_reset(int $actorId, int $companyId, int $userId, string $reason): array
{
    pl_shared_demo_require_mutable_account($userId);
    pl_demo_require_setup_action();
    $reason = pl_ledger_text($reason, 'Reason', 500);
    return pl_ledger_transaction(function () use ($actorId, $companyId, $userId, $reason): array {
        pl_require_company_access($actorId, $companyId, true);
        pl_require_capability($actorId, $companyId, 'users.manage', 'Your role cannot reset another person\'s password.');
        $before = pl_get_company_user($actorId, $companyId, $userId);
        $token = bin2hex(random_bytes(32));
        DB::update('pl_users', [
            'password_hash' => pl_hash_password(bin2hex(random_bytes(24))),
            'must_change_password' => 1,
        ], 'id = %i', $userId);
        pl_user_meta_set($userId, 'password_reset', json_encode([
            'token_hash' => hash('sha256', $token), 'expires_at' => time() + 86400, 'requested_by' => $actorId,
        ], JSON_THROW_ON_ERROR));
        pl_revoke_user_sessions($actorId, $userId, null, $reason);
        pl_user_audit($actorId, $companyId, $userId, 'user', $userId, 'password_reset_forced', $reason, $before, pl_get_company_user($actorId, $companyId, $userId));
        return ['user_id' => $userId, 'token' => $token, 'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400)];
    });
}

/** Redeem a forced-reset token and choose a new password. */
function pl_complete_password_reset(string $signInName, string $token, string $password): int
{
    $signInName = strtolower(trim($signInName));
    $userId = (int) (DB::queryFirstField(
        str_contains($signInName, '@')
            ? 'SELECT id FROM pl_users WHERE email = %s'
            : 'SELECT id FROM pl_users WHERE username = %s',
        $signInName
    ) ?? 0);
    pl_shared_demo_require_mutable_account($userId);
    $raw = $userId > 0 ? pl_user_meta_get($userId, 'password_reset') : null;
    $pending = null;
    if (is_string($raw)) {
        try {
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
            $pending = is_array($decoded) ? $decoded : null;
        } catch (JsonException) {
            $pending = null;
        }
    }
    if ($pending === null || !is_string($pending['token_hash'] ?? null) || !is_int($pending['expires_at'] ?? null)
        || $pending['expires_at'] < time() || !hash_equals($pending['token_hash'], hash('sha256', $token))) {
        throw new DomainException('That reset link is not valid any more. Ask an administrator for a new one.');
    }
    $hash = pl_hash_password($password);
    pl_ledger_transaction(function () use ($userId, $hash): void {
        DB::update('pl_users', ['password_hash' => $hash, 'must_change_password' => 0,
            'password_changed_at' => gmdate('Y-m-d H:i:s')], 'id = %i', $userId);
        pl_user_meta_set($userId, 'password_reset', null);
        pl_revoke_user_sessions($userId, $userId, null, 'Password reset completed.');
        pl_user_audit($userId, null, $userId, 'user', $userId, 'password_reset_completed', 'Reset token redeemed.', null, ['id' => $userId]);
    });
    return $userId;
}

// ---------------------------------------------------------------------------------- invitations

function pl_list_invitations(int $actorId, int $companyId): array
{
    pl_require_company_access($actorId, $companyId);
    pl_require_capability($actorId, $companyId, 'users.manage', 'Your role cannot read invitations.');
    $rows = DB::query(
        'SELECT i.id, i.email, i.role_id, r.name AS role_name, i.message, i.expires_at, i.accepted_at, i.revoked_at, i.created_at, u.display_name AS invited_by '
        . 'FROM pl_user_invitations i JOIN pl_roles r ON r.id = i.role_id JOIN pl_users u ON u.id = i.invited_by '
        . 'WHERE i.company_id = %i ORDER BY i.id DESC LIMIT 100',
        $companyId
    );
    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['role_id'] = (int) $row['role_id'];
        $row['status'] = $row['revoked_at'] !== null ? 'revoked'
            : ($row['accepted_at'] !== null ? 'accepted'
                : (strtotime((string) $row['expires_at'] . ' UTC') < time() ? 'expired' : 'open'));
    }
    unset($row);
    return $rows;
}

/**
 * Invite someone by email address to work in this company with one role. Returns the one-time
 * token exactly once; only its hash is stored. An address that already works here is refused, and
 * one open invitation per address per company is enforced by the database.
 */
function pl_invite_user(int $actorId, int $companyId, string $email, int $roleId, string $message): array
{
    pl_demo_require_setup_action();
    $email = strtolower(trim($email));
    if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new DomainException('Enter a valid email address.');
    }
    $message = pl_ledger_text($message, 'Message', 500, false);
    return pl_ledger_transaction(function () use ($actorId, $companyId, $email, $roleId, $message): array {
        pl_require_company_access($actorId, $companyId, true);
        pl_require_capability($actorId, $companyId, 'users.manage', 'Your role cannot invite people to this company.');
        $role = pl_get_role($actorId, $companyId, $roleId);
        $existing = DB::queryFirstField(
            'SELECT m.user_id FROM pl_company_members m JOIN pl_users u ON u.id = m.user_id WHERE m.company_id = %i AND u.email = %s',
            $companyId,
            $email
        );
        if ($existing !== null) {
            throw new DomainException('That person already works in this company. Change their role instead.');
        }
        if (DB::queryFirstField('SELECT id FROM pl_user_invitations WHERE company_id = %i AND email = %s AND accepted_at IS NULL AND revoked_at IS NULL', $companyId, $email) !== null) {
            throw new DomainException('An invitation for that address is already open. Revoke it first to send a new one.');
        }
        $token = bin2hex(random_bytes(32));
        DB::insert('pl_user_invitations', [
            'company_id' => $companyId, 'email' => $email, 'role_id' => $role['id'],
            'token_hash' => hash('sha256', $token), 'invited_by' => $actorId, 'message' => $message,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 604800),
        ]);
        $id = (int) DB::insertId();
        pl_user_audit($actorId, $companyId, null, 'invitation', $id, 'created', $message,
            null, ['id' => $id, 'email' => $email, 'role' => $role['name']]);
        return ['id' => $id, 'email' => $email, 'role' => $role['name'], 'token' => $token,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 604800)];
    });
}

function pl_revoke_invitation(int $actorId, int $companyId, int $invitationId, string $reason): void
{
    pl_demo_require_setup_action();
    $reason = pl_ledger_text($reason, 'Reason', 500, false);
    pl_ledger_transaction(function () use ($actorId, $companyId, $invitationId, $reason): void {
        pl_require_company_access($actorId, $companyId, true);
        pl_require_capability($actorId, $companyId, 'users.manage', 'Your role cannot revoke invitations.');
        $row = DB::queryFirstRow('SELECT id, email, accepted_at, revoked_at FROM pl_user_invitations WHERE id = %i AND company_id = %i FOR UPDATE', $invitationId, $companyId);
        if (!$row) {
            throw new DomainException('That invitation is not available in this company.');
        }
        if ($row['accepted_at'] !== null) {
            throw new DomainException('That invitation has already been accepted.');
        }
        DB::update('pl_user_invitations', ['revoked_at' => gmdate('Y-m-d H:i:s')], 'id = %i', $invitationId);
        pl_user_audit($actorId, $companyId, null, 'invitation', $invitationId, 'revoked', $reason, $row, ['id' => $invitationId, 'revoked' => true]);
    });
}

/**
 * Redeem an invitation token. An unknown address gets a new account; a known one simply gains
 * membership of the inviting company. Either way the role comes from the invitation, never from
 * the browser. Returns the user id.
 */
function pl_accept_invitation(string $token, array $input): int
{
    $hash = hash('sha256', $token);
    return pl_ledger_transaction(function () use ($hash, $input): int {
        $invitation = DB::queryFirstRow('SELECT * FROM pl_user_invitations WHERE token_hash = %s FOR UPDATE', $hash);
        if (!$invitation || $invitation['accepted_at'] !== null || $invitation['revoked_at'] !== null
            || strtotime((string) $invitation['expires_at'] . ' UTC') < time()) {
            throw new DomainException('That invitation link is not valid any more. Ask for a new one.');
        }
        $companyId = (int) $invitation['company_id'];
        DB::queryFirstField('SELECT id FROM pl_companies WHERE id = %i FOR UPDATE', $companyId);
        $roleId = (int) $invitation['role_id'];
        $email = (string) $invitation['email'];
        $userId = (int) (DB::queryFirstField('SELECT id FROM pl_users WHERE email = %s', $email) ?? 0);
        if ($userId < 1) {
            $userId = pl_create_user($email, pl_ledger_text($input['display_name'] ?? '', 'Name', 120),
                is_string($input['password'] ?? null) ? $input['password'] : '',
                ($input['username'] ?? '') === '' ? null : (string) $input['username']);
        }
        $role = DB::queryFirstRow('SELECT id, company_id, slug, name, is_system FROM pl_roles WHERE id = %i', $roleId);
        if (!$role || ($role['company_id'] !== null && (int) $role['company_id'] !== $companyId)) {
            throw new DomainException('That invitation names a role that no longer exists.');
        }
        $capabilities = DB::queryFirstColumn('SELECT c.capability FROM pl_role_capabilities rc JOIN pl_capabilities c ON c.id = rc.capability_id WHERE rc.role_id = %i', $roleId);
        $enum = pl_role_access_label(['slug' => (string) $role['slug'], 'is_system' => (bool) $role['is_system'], 'capabilities' => $capabilities]);
        if ($enum !== 'owner') { pl_shared_demo_require_mutable_account($userId); }
        $existingMember = pl_company_member_role($userId, $companyId);
        if ($existingMember && $existingMember['role'] === 'owner' && $enum !== 'owner' && pl_company_owner_count($companyId, $userId) < 1) {
            throw new DomainException('This company would be left without an active owner. Give someone else the Owner role first.');
        }
        DB::insertUpdate('pl_company_members', ['company_id' => $companyId, 'user_id' => $userId, 'role_id' => $roleId],
            ['role_id' => $roleId]);
        DB::update('pl_user_invitations', ['accepted_at' => gmdate('Y-m-d H:i:s'), 'accepted_user_id' => $userId], 'id = %i', (int) $invitation['id']);
        pl_capability_cache_reset();
        pl_user_audit($userId, $companyId, $userId, 'invitation', (int) $invitation['id'], 'accepted', 'Invitation accepted.',
            null, ['company_id' => $companyId, 'user_id' => $userId, 'role' => $enum]);
        return $userId;
    });
}

// ------------------------------------------------------------------- server-side user sessions

/** How long a server-side session may live without being seen, and in total. */
const PL_SESSION_IDLE_SECONDS = 1800;
const PL_SESSION_ABSOLUTE_SECONDS = 43200;

/**
 * Open a server-side session and return its token. The token goes into the PHP session, which is
 * still the cookie transport; the row here is what decides whether the sign-in is still valid, so
 * an administrator can end it from another machine.
 */
function pl_session_open(int $userId, string $installationHash, string $clientLabel = ''): string
{
    $token = bin2hex(random_bytes(32));
    DB::insert('pl_user_sessions', [
        'token_hash' => hash('sha256', $token), 'user_id' => $userId,
        'installation_hash' => $installationHash,
        'client_label' => mb_substr($clientLabel, 0, 160),
        'expires_at' => gmdate('Y-m-d H:i:s', time() + PL_SESSION_ABSOLUTE_SECONDS),
    ]);
    DB::update('pl_users', ['last_signed_in_at' => gmdate('Y-m-d H:i:s')], 'id = %i', $userId);
    return $token;
}

/**
 * Is this token still a live session for this user and this copy of the application? Returns the
 * session row and refreshes last_seen_at, or null, in which case the caller signs the user out.
 */
function pl_session_validate(string $token, int $userId, string $installationHash): ?array
{
    if ($token === '' || $userId < 1) {
        return null;
    }
    $hash = hash('sha256', $token);
    $row = DB::queryFirstRow(
        'SELECT token_hash, user_id, installation_hash, created_at, last_seen_at, expires_at, revoked_at '
        . 'FROM pl_user_sessions WHERE token_hash = %s',
        $hash
    );
    if (!$row || (int) $row['user_id'] !== $userId || $row['revoked_at'] !== null
        || !hash_equals((string) $row['installation_hash'], $installationHash)) {
        return null;
    }
    $now = time();
    $expires = strtotime((string) $row['expires_at'] . ' UTC');
    $lastSeen = strtotime((string) $row['last_seen_at'] . ' UTC');
    if ($expires === false || $lastSeen === false || $expires <= $now || $now - $lastSeen >= PL_SESSION_IDLE_SECONDS) {
        return null;
    }
    // One write per request is acceptable; a second-resolution check keeps it to one.
    if ($now - $lastSeen >= 1) {
        DB::update('pl_user_sessions', ['last_seen_at' => gmdate('Y-m-d H:i:s', $now)], 'token_hash = %s', $hash);
    }
    return $row;
}

function pl_session_close(string $token): void
{
    if ($token === '') {
        return;
    }
    DB::update('pl_user_sessions', ['revoked_at' => gmdate('Y-m-d H:i:s')], 'token_hash = %s AND revoked_at IS NULL', hash('sha256', $token));
}

/** Everything currently signed in as this user. The token itself is never shown. */
function pl_list_user_sessions(int $userId): array
{
    $rows = DB::query(
        'SELECT token_hash, client_label, created_at, last_seen_at, expires_at FROM pl_user_sessions '
        . 'WHERE user_id = %i AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP() ORDER BY last_seen_at DESC LIMIT 50',
        $userId
    );
    foreach ($rows as &$row) {
        // A short, non-reversible handle so a person can point at the row they want to end.
        $row['handle'] = substr((string) $row['token_hash'], 0, 12);
        unset($row['token_hash']);
    }
    unset($row);
    return $rows;
}

/**
 * End sessions. $keepTokenHash keeps the caller's own session alive ("sign out everywhere else");
 * null ends all of them. Returns how many were ended.
 */
function pl_revoke_user_sessions(int $actorId, int $userId, ?string $keepTokenHash, string $reason): int
{
    $now = gmdate('Y-m-d H:i:s');
    if ($keepTokenHash === null) {
        DB::update('pl_user_sessions', ['revoked_at' => $now, 'revoked_by' => $actorId],
            'user_id = %i AND revoked_at IS NULL', $userId);
    } else {
        DB::update('pl_user_sessions', ['revoked_at' => $now, 'revoked_by' => $actorId],
            'user_id = %i AND revoked_at IS NULL AND token_hash <> %s', $userId, $keepTokenHash);
    }
    $ended = DB::affectedRows();
    if ($ended > 0) {
        pl_user_audit($actorId, null, $userId, 'session', $userId, 'revoked', pl_ledger_text($reason, 'Reason', 500, false),
            null, ['user_id' => $userId, 'ended' => $ended]);
    }
    return $ended;
}

/** End one named session of your own, by the handle the profile screen shows. */
function pl_revoke_session_handle(int $userId, string $handle, string $reason): void
{
    if (!preg_match('/^[0-9a-f]{12}$/D', $handle)) {
        throw new DomainException('Choose one of the listed sessions.');
    }
    $tokenHash = DB::queryFirstField(
        'SELECT token_hash FROM pl_user_sessions WHERE user_id = %i AND revoked_at IS NULL AND LEFT(token_hash, 12) = %s',
        $userId,
        $handle
    );
    if (!is_string($tokenHash)) {
        throw new DomainException('That session has already ended.');
    }
    DB::update('pl_user_sessions', ['revoked_at' => gmdate('Y-m-d H:i:s'), 'revoked_by' => $userId], 'token_hash = %s', $tokenHash);
    pl_user_audit($userId, null, $userId, 'session', $userId, 'revoked', pl_ledger_text($reason, 'Reason', 500, false),
        null, ['user_id' => $userId, 'ended' => 1]);
}

/** Lock company rows in one order before touching the installation-wide account state. */
function pl_lock_user_membership_companies(int $userId, int $selectedCompanyId): void
{
    $ids = array_map('intval', DB::queryFirstColumn('SELECT company_id FROM pl_company_members WHERE user_id = %i', $userId));
    $ids[] = $selectedCompanyId;
    $ids = array_values(array_unique($ids));
    sort($ids, SORT_NUMERIC);
    DB::query('SELECT id FROM pl_companies WHERE id IN %li ORDER BY id FOR UPDATE', $ids);
    DB::queryFirstField('SELECT id FROM pl_users WHERE id = %i FOR UPDATE', $userId);
    // A new assignment may have committed while acquiring locks. Do not acquire extra company
    // locks after the user lock: refuse and let the operator retry in the correct lock order.
    $current = array_map('intval', DB::queryFirstColumn('SELECT company_id FROM pl_company_members WHERE user_id = %i FOR SHARE', $userId));
    if (array_diff($current, $ids) !== []) {
        throw new DomainException('This account changed company memberships. Retry the account change.');
    }
}

/** Suspending/anonymising an account must not strand any of its companies. */
function pl_require_other_active_owners(int $userId): void
{
    $companies = DB::queryFirstColumn("SELECT m.company_id FROM pl_company_members m JOIN pl_roles r ON r.id = m.role_id JOIN pl_users u ON u.id = m.user_id WHERE m.user_id = %i AND u.is_active = 1 AND r.is_system = 1 AND r.company_id IS NULL AND r.slug = 'owner' FOR SHARE", $userId);
    foreach ($companies as $companyId) {
        if (pl_company_owner_count((int) $companyId, $userId) < 1) {
            throw new DomainException('This account is the last active owner of a company. Give someone else the Owner role there first.');
        }
    }
}
