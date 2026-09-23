<?php
declare(strict_types=1);

/**
 * Admin > Users, Admin > Roles, Admin > Report cost visibility, the profile page, and the two
 * pre-sign-in screens that make an invitation and a forced password reset reachable without a
 * mail transport (release plan 1.2, milestone M7).
 *
 * Every service call here has already been authorised inside the service. These functions read
 * the form, call the service, and choose the next screen; they never decide a permission.
 */

/** Admin > Users. */
function pl_web_users(int $actorId, int $companyId, int $bookId, array $user, array $company, string $method): never
{
    if (pl_demo_enabled()) {
        throw new DomainException(pl_t('User administration is unavailable in the public sample.'));
    }
    if ($method === 'POST') {
        pl_web_users_post($actorId, $companyId, $company);
    }
    $form = pl_form_state(pl_url('/users'));
    $issued = $_SESSION['user_admin_token'] ?? null;
    unset($_SESSION['user_admin_token']);
    $selected = pl_web_id($_GET, 'id');
    pl_render('users', [
        'title' => pl_t('Users'), 'user' => $user, 'company' => $company,
        'users' => pl_list_company_users($actorId, $companyId),
        'roles' => pl_list_roles($actorId, $companyId),
        'invitations' => pl_list_invitations($actorId, $companyId),
        'selected' => $selected > 0 ? pl_get_company_user($actorId, $companyId, $selected) : null,
        'history' => $selected > 0 ? pl_user_history($actorId, $companyId, 'user', $selected) : [],
        'issued' => is_array($issued) ? $issued : null,
        'form' => $form, 'input' => $form['input'],
    ]);
}

function pl_web_users_post(int $actorId, int $companyId, array $company): void
{
    $return = pl_url('/users');
    try {
        pl_web_assert_scope($company, $_POST);
        $action = pl_web_text($_POST, 'action');
        $userId = pl_web_id($_POST, 'user_id');
        $reason = pl_web_text($_POST, 'reason');
        if ($action === 'invite') {
            $invitation = pl_invite_user($actorId, $companyId, pl_web_text($_POST, 'email'),
                pl_web_id($_POST, 'role_id'), pl_web_text($_POST, 'message'));
            $_SESSION['user_admin_token'] = ['kind' => 'invitation', 'email' => $invitation['email'],
                'url' => pl_url('/invitation', ['token' => $invitation['token']]), 'expires_at' => $invitation['expires_at']];
            pl_notice(pl_t('Invitation created. Copy the one-time link below and send it to {email} yourself: this release does not send email.', ['email' => $invitation['email']]));
            pl_redirect($return);
        }
        if ($action === 'revoke_invitation') {
            pl_revoke_invitation($actorId, $companyId, pl_web_id($_POST, 'invitation_id'), $reason);
            pl_notice(pl_t('Invitation revoked. Its link no longer works.'));
            pl_redirect($return);
        }
        if ($action === 'details') {
            pl_admin_update_user($actorId, $companyId, $userId, [
                'display_name' => pl_web_text($_POST, 'display_name'),
                'first_name' => pl_web_text($_POST, 'first_name'),
                'last_name' => pl_web_text($_POST, 'last_name'),
                'phone' => pl_web_text($_POST, 'phone'),
                'job_title' => pl_web_text($_POST, 'job_title'),
            ], $reason);
            pl_notice(pl_t('Details saved. Their sign-in address and password are theirs to change; use Force a password reset if they are locked out.'));
            pl_redirect(pl_url('/users', ['id' => $userId]));
        }
        if ($action === 'role') {
            pl_assign_company_role($actorId, $companyId, $userId, pl_web_id($_POST, 'role_id'), $reason);
            pl_notice(pl_t('Role updated. Everything this person posted keeps their name on it.'));
            pl_redirect(pl_url('/users', ['id' => $userId]));
        }
        if ($action === 'suspend' || $action === 'reactivate') {
            pl_set_user_active($actorId, $companyId, $userId, $action === 'reactivate', $reason);
            pl_notice($action === 'reactivate'
                ? pl_t('Account reactivated. They can sign in again.')
                : pl_t('Account suspended. Their open sessions were ended.'));
            pl_redirect(pl_url('/users', ['id' => $userId]));
        }
        if ($action === 'remove') {
            pl_remove_company_member($actorId, $companyId, $userId, $reason);
            pl_notice(pl_t('Access to this company removed. The account and its posting history are kept.'));
            pl_redirect($return);
        }
        if ($action === 'anonymise') {
            if (pl_web_text($_POST, 'confirm') !== 'anonymise') {
                throw new DomainException(pl_t('Type anonymise to confirm. This cannot be undone.'));
            }
            pl_anonymise_user($actorId, $companyId, $userId, $reason);
            pl_notice(pl_t('Account anonymised. Its posting history is unchanged and still points at the same account.'));
            pl_redirect(pl_url('/users', ['id' => $userId]));
        }
        if ($action === 'reset_password') {
            $reset = pl_force_password_reset($actorId, $companyId, $userId, $reason);
            $_SESSION['user_admin_token'] = ['kind' => 'reset', 'email' => pl_get_company_user($actorId, $companyId, $userId)['email'],
                'url' => pl_url('/reset-password', ['token' => $reset['token']]), 'expires_at' => $reset['expires_at']];
            pl_notice(pl_t('Password reset. Their old password and every open session stopped working. Copy the one-time link below and hand it over yourself.'));
            pl_redirect(pl_url('/users', ['id' => $userId]));
        }
        if ($action === 'end_sessions') {
            pl_require_capability($actorId, $companyId, 'users.manage', pl_t('Your role cannot end another person\'s sessions.'));
            pl_get_company_user($actorId, $companyId, $userId);
            $ended = pl_revoke_user_sessions($actorId, $userId, null, pl_ledger_text($reason, 'Reason', 500));
            pl_notice(pl_tn('Ended {count} session.', 'Ended {count} sessions.', $ended, ['count' => $ended]));
            pl_redirect(pl_url('/users', ['id' => $userId]));
        }
        if ($action === 'installation_admin') {
            $granted = pl_web_text($_POST, 'granted') === '1';
            pl_get_company_user($actorId, $companyId, $userId);
            pl_set_installation_grant($actorId, $userId, 'installation.admin', $granted, $reason);
            pl_notice($granted
                ? pl_t('This person can now administer the installation.')
                : pl_t('Installation administration withdrawn.'));
            pl_redirect(pl_url('/users', ['id' => $userId]));
        }
        throw new DomainException(pl_t('Choose a valid action.'));
    } catch (DomainException $error) {
        pl_form_failure($return, $_POST, $error->getMessage());
    }
}

/** Admin > Roles: the system roles read-only, this company's custom roles editable. */
function pl_web_roles(int $actorId, int $companyId, int $bookId, array $user, array $company, string $method): never
{
    if (pl_demo_enabled()) {
        throw new DomainException(pl_t('Role administration is unavailable in the public sample.'));
    }
    if ($method === 'POST') {
        $return = pl_url('/roles');
        try {
            pl_web_assert_scope($company, $_POST);
            $capabilities = $_POST['capabilities'] ?? [];
            $role = pl_save_role($actorId, $companyId, [
                'name' => pl_web_text($_POST, 'name'),
                'description' => pl_web_text($_POST, 'description'),
                'reason' => pl_web_text($_POST, 'reason'),
                'capabilities' => is_array($capabilities) ? array_values($capabilities) : [],
            ], pl_web_id($_POST, 'role_id') ?: null, pl_web_id($_POST, 'role_id') ? pl_web_id($_POST, 'revision') : null);
            pl_notice(pl_t('Role saved. It applies the next time each member loads a screen.'));
            pl_redirect(pl_url('/roles', ['id' => $role['id']]));
        } catch (DomainException $error) {
            pl_form_failure($return, $_POST, $error->getMessage());
        }
    }
    $form = pl_form_state(pl_url('/roles'));
    $selected = pl_web_id($_GET, 'id');
    pl_render('roles', [
        'title' => pl_t('Roles'), 'user' => $user, 'company' => $company,
        'roles' => pl_list_roles($actorId, $companyId),
        'capabilities' => pl_list_capabilities(),
        'selected' => $selected > 0 ? pl_get_role($actorId, $companyId, $selected) : null,
        'isNew' => pl_web_text($_GET, 'new') === '1',
        'canManage' => pl_user_can($actorId, $companyId, 'roles.manage'),
        'form' => $form, 'input' => $form['input'],
    ]);
}

/** Admin > Report cost visibility (owner decision B58). */
function pl_web_cost_visibility(int $actorId, int $companyId, int $bookId, array $user, array $company, string $method): never
{
    if (pl_demo_enabled()) {
        throw new DomainException(pl_t('Cost visibility settings are unavailable in the public sample.'));
    }
    if ($method === 'POST') {
        $return = pl_url('/cost-visibility');
        try {
            pl_web_assert_scope($company, $_POST);
            pl_save_report_cost_setting($actorId, $companyId, $bookId, pl_web_text($_POST, 'report_id'),
                pl_web_text($_POST, 'show_cost') === '1', pl_web_text($_POST, 'show_margin') === '1',
                pl_web_id($_POST, 'revision'), pl_web_text($_POST, 'reason'));
            pl_notice(pl_t('Saved. The report hides or shows cost from the next time it is opened, for the screen and for the API alike.'));
            pl_redirect($return);
        } catch (DomainException $error) {
            pl_form_failure($return, $_POST, $error->getMessage());
        }
    }
    $form = pl_form_state(pl_url('/cost-visibility'));
    pl_render('cost-visibility', [
        'title' => pl_t('Cost visibility'), 'user' => $user, 'company' => $company,
        'settings' => pl_list_report_cost_settings($actorId, $companyId, $bookId),
        'canManage' => pl_user_can($actorId, $companyId, 'reports.cost_settings.manage'),
        'holdsCostView' => pl_user_can($actorId, $companyId, 'cost.view'),
        'form' => $form, 'input' => $form['input'],
    ]);
}

/** Your own profile: name, username, email, password, locale, timezone, active sessions. */
function pl_web_profile(int $actorId, array $user, ?array $company, string $method): never
{
    if (pl_demo_enabled()) {
        throw new DomainException(pl_t('Profile settings are unavailable in the public sample.'));
    }
    $return = pl_url('/profile');
    if ($method === 'POST') {
        try {
            $action = pl_web_text($_POST, 'action');
            if ($action === 'profile') {
                pl_update_profile($actorId, [
                    'display_name' => pl_web_text($_POST, 'display_name'),
                    'first_name' => pl_web_text($_POST, 'first_name'),
                    'last_name' => pl_web_text($_POST, 'last_name'),
                    'phone' => pl_web_text($_POST, 'phone'),
                    'job_title' => pl_web_text($_POST, 'job_title'),
                    'username' => pl_web_text($_POST, 'username'),
                    'locale' => pl_web_text($_POST, 'locale'),
                    'timezone' => pl_web_text($_POST, 'timezone'),
                ]);
                pl_notice(pl_t('Profile saved.'));
                pl_redirect($return);
            }
            if ($action === 'password') {
                $token = $_SESSION['session_token'] ?? null;
                pl_change_own_password($actorId, is_string($_POST['current_password'] ?? null) ? $_POST['current_password'] : '',
                    is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '',
                    is_string($token) ? hash('sha256', $token) : null);
                pl_notice(pl_t('Password changed. Every other session was signed out.'));
                pl_redirect($return);
            }
            if ($action === 'email') {
                $change = pl_request_email_change($actorId, pl_web_text($_POST, 'email'));
                $_SESSION['profile_email_token'] = ['email' => $change['email'],
                    'url' => pl_url('/profile', ['confirm' => $change['token']])];
                pl_notice(pl_t('Confirm the change from the one-time link below. Your sign-in address does not change until you do.'));
                pl_redirect($return);
            }
            if ($action === 'end_session') {
                pl_revoke_session_handle($actorId, pl_web_text($_POST, 'handle'), 'Ended from the profile screen.');
                pl_notice(pl_t('That session was signed out.'));
                pl_redirect($return);
            }
            if ($action === 'end_everywhere') {
                $token = $_SESSION['session_token'] ?? null;
                $ended = pl_revoke_user_sessions($actorId, $actorId, is_string($token) ? hash('sha256', $token) : null, 'Signed out everywhere else.');
                pl_notice(pl_tn('Signed out of {count} other session.', 'Signed out of {count} other sessions.', $ended, ['count' => $ended]));
                pl_redirect($return);
            }
            throw new DomainException(pl_t('Choose a valid action.'));
        } catch (DomainException | InvalidArgumentException $error) {
            pl_form_failure($return, $_POST, $error->getMessage());
        }
    }
    $confirm = pl_web_text($_GET, 'confirm');
    if ($confirm !== '') {
        try {
            pl_confirm_email_change($actorId, $confirm);
            unset($_SESSION['profile_email_token']);
            pl_notice(pl_t('Email address changed. Sign in again with the new address.'));
            pl_redirect(pl_url('/login'));
        } catch (DomainException $error) {
            pl_form_failure($return, [], $error->getMessage());
        }
    }
    $profile = pl_user_row($actorId);
    if ($profile === null) {
        throw new DomainException(pl_t('This account is not available.'));
    }
    $token = $_SESSION['session_token'] ?? null;
    $pending = $_SESSION['profile_email_token'] ?? null;
    unset($_SESSION['profile_email_token']);
    $form = pl_form_state($return);
    pl_render('profile', [
        'title' => pl_t('Your profile'), 'user' => $user, 'company' => $company,
        'profile' => $profile,
        'sessions' => pl_list_user_sessions($actorId),
        'currentHandle' => is_string($token) ? substr(hash('sha256', $token), 0, 12) : '',
        'memberships' => pl_profile_memberships($actorId),
        'pendingEmail' => is_array($pending) ? $pending : null,
        'form' => $form, 'input' => $form['input'] ?: $profile,
    ]);
}

/** Which companies this person works in and with which role. Read for their own account only. */
function pl_profile_memberships(int $actorId): array
{
    $rows = DB::query(
        'SELECT c.id, c.name, r.slug AS role, r.name AS role_name FROM pl_company_members m '
        . 'JOIN pl_companies c ON c.id = m.company_id LEFT JOIN pl_roles r ON r.id = m.role_id '
        . 'WHERE m.user_id = %i ORDER BY c.name, c.id',
        $actorId
    );
    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['role_name'] = $row['role_name'] ?? ucfirst((string) $row['role']);
    }
    unset($row);
    return $rows;
}

/**
 * The two pre-sign-in screens: accept an invitation, and complete a forced password reset. Both
 * take a one-time token from the link an administrator handed over, and both end at /login.
 */
function pl_web_account_access(string $path, string $method): never
{
    $invitation = $path === '/invitation';
    $return = pl_url($invitation ? '/invitation' : '/reset-password');
    $token = pl_web_text($method === 'POST' ? $_POST : $_GET, 'token');
    if ($method === 'POST') {
        try {
            if ($invitation) {
                pl_accept_invitation($token, [
                    'display_name' => pl_web_text($_POST, 'display_name'),
                    'username' => pl_web_text($_POST, 'username'),
                    'password' => is_string($_POST['password'] ?? null) ? $_POST['password'] : '',
                ]);
                pl_notice(pl_t('Your access is ready. Sign in to continue.'));
            } else {
                pl_complete_password_reset(pl_web_text($_POST, 'sign_in_name'), $token,
                    is_string($_POST['password'] ?? null) ? $_POST['password'] : '');
                pl_notice(pl_t('Password set. Sign in to continue.'));
            }
            pl_redirect(pl_url('/login'));
        } catch (DomainException | InvalidArgumentException $error) {
            pl_form_failure(pl_url($invitation ? '/invitation' : '/reset-password', ['token' => $token]), ['display_name' => pl_web_text($_POST, 'display_name'), 'username' => pl_web_text($_POST, 'username'), 'sign_in_name' => pl_web_text($_POST, 'sign_in_name')], $error->getMessage());
        }
    }
    $form = pl_form_state(pl_url($invitation ? '/invitation' : '/reset-password', ['token' => $token]));
    if ($form === ['input' => [], 'message' => '']) {
        $form = pl_form_state($return);
    }
    pl_render('account-access', [
        'title' => $invitation ? pl_t('Accept your invitation') : pl_t('Set a new password'),
        'user' => null, 'company' => null, 'mode' => $invitation ? 'invitation' : 'reset',
        'token' => $token, 'form' => $form, 'input' => $form['input'],
    ]);
}
