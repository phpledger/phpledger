<?php
declare(strict_types=1);
/**
 * Admin > Users (release plan 1.2 M7). Field cues from the owner's Awan prototype: the list is
 * User Id, User login (username over display name), Role, Last sign-in, Status, with Reset
 * password and Edit on the row. What the prototype marked "Proposed" — real roles, per-action
 * permissions and a posting that records who made it — is what this screen now administers.
 *
 * @var array $company @var array $user @var array $users @var array $roles @var array $invitations
 * @var array|null $selected @var array $history @var array|null $issued @var array $form @var array $input
 */
$usersInput = $form['input'];
$actorId = (int) $user['id'];
?>
<section class="flex flex-col gap-4 py-5" aria-labelledby="users-title">
    <div class="page-header">
        <div>
            <p class="eyebrow"><?= pl_e(pl_t('Setup')) ?></p>
            <h1 class="page-title" id="users-title"><?= pl_e(pl_t('Users')) ?></h1>
            <p class="muted"><?= pl_e(pl_tn('{count} person works in {company}.', '{count} people work in {company}.', count($users), ['count' => count($users), 'company' => $company['name']])) ?>
                <?= pl_e(pl_t('A role decides who sees cost, who corrects posted documents and who administers the books. Every posting records the person who made it.')) ?></p>
        </div>
        <div class="page-header-actions">
            <a class="btn btn-secondary" href="<?= pl_e(pl_url('/roles')) ?>"><?= pl_e(pl_t('Roles and permissions')) ?></a>
            <a class="btn btn-secondary" href="<?= pl_e(pl_url('/profile')) ?>"><?= pl_e(pl_t('Your profile')) ?></a>
        </div>
    </div>

    <?php if ($form['message'] !== ''): ?>
        <div class="alert alert-danger" role="alert" tabindex="-1" data-form-error>
            <h2 class="section-title mb-2"><?= pl_e(pl_t('This change needs attention')) ?></h2>
            <p><?= pl_e((string) $form['message']) ?></p>
        </div>
    <?php endif; ?>

    <?php if ($issued !== null): ?>
        <div class="alert alert-info" role="status" tabindex="-1">
            <h2 class="section-title mb-2"><?= pl_e($issued['kind'] === 'invitation' ? pl_t('One-time invitation link') : pl_t('One-time password reset link')) ?></h2>
            <p><?= pl_e(pl_t('This release does not send email. Copy this link and give it to {email} yourself. It is shown once and expires on {expires}.', ['email' => (string) $issued['email'], 'expires' => (string) $issued['expires_at']])) ?></p>
            <p><code class="break-all"><?= pl_e((string) $issued['url']) ?></code></p>
        </div>
    <?php endif; ?>

    <div class="rounded-panel border border-border bg-surface p-4">
        <h2 class="section-title mb-2"><?= pl_e(pl_t('People in this company')) ?></h2>
        <div class="table-wrap" tabindex="0" role="region" aria-label="<?= pl_e(pl_t('People in this company')) ?>">
            <table class="table">
                <thead><tr>
                    <th scope="col"><?= pl_e(pl_t('User id')) ?></th>
                    <th scope="col"><?= pl_e(pl_t('Sign-in name')) ?></th>
                    <th scope="col"><?= pl_e(pl_t('Role')) ?></th>
                    <th scope="col"><?= pl_e(pl_t('Last sign-in')) ?></th>
                    <th scope="col"><?= pl_e(pl_t('Sessions')) ?></th>
                    <th scope="col"><?= pl_e(pl_t('Status')) ?></th>
                    <th scope="col"><?= pl_e(pl_t('Action')) ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($users as $row): ?>
                    <tr>
                        <td class="tabular-nums text-ink-muted"><?= (int) $row['id'] ?></td>
                        <td>
                            <span class="row-title"><?= pl_e((string) ($row['username'] ?? $row['email'])) ?></span>
                            <span class="row-sub"><?= pl_e((string) $row['display_name']) ?></span>
                        </td>
                        <td>
                            <?= pl_e((string) ($row['role_name'] ?? ucfirst((string) $row['role']))) ?>
                            <?php if ($row['is_installation_admin']): ?><span class="row-sub"><?= pl_e(pl_t('Installation administrator')) ?></span><?php endif; ?>
                        </td>
                        <td class="whitespace-nowrap"><?= pl_e($row['last_signed_in_at'] === null ? pl_t('Never') : (string) $row['last_signed_in_at']) ?></td>
                        <td class="tabular-nums"><?= (int) $row['open_sessions'] ?></td>
                        <td><?php pl_ui_badge($row['is_active'] ? 'active' : 'inactive', $row['anonymised_at'] !== null ? pl_t('Anonymised') : ($row['is_active'] ? pl_t('Active') : pl_t('Suspended'))); ?></td>
                        <td class="text-end whitespace-nowrap"><a class="btn btn-ghost btn-sm" href="<?= pl_e(pl_url('/users', ['id' => $row['id']])) ?>"><?= pl_e(pl_t('Manage')) ?></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($selected !== null): $sameActor = (int) $selected['id'] === $actorId; ?>
        <div class="rounded-panel border border-border bg-surface p-4" id="selected-user">
            <h2 class="section-title mb-2"><?= pl_e(pl_t('Manage {name}', ['name' => (string) $selected['display_name']])) ?></h2>
            <p class="muted"><?= pl_e((string) $selected['email']) ?><?php if ($selected['job_title'] !== null): ?> · <?= pl_e((string) $selected['job_title']) ?><?php endif; ?></p>

            <form class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2" method="post" action="<?= pl_e(pl_url('/users')) ?>">
                <?= pl_csrf_field() ?><?= pl_scope_fields($company) ?>
                <input type="hidden" name="action" value="details">
                <input type="hidden" name="user_id" value="<?= (int) $selected['id'] ?>">
                <div class="field"><label for="user-display-name"><?= pl_e(pl_t('Name')) ?></label><input class="input" id="user-display-name" name="display_name" maxlength="120" required value="<?= pl_e((string) $selected['display_name']) ?>"></div>
                <div class="field"><label for="user-job-title"><?= pl_e(pl_t('Job title')) ?></label><input class="input" id="user-job-title" name="job_title" maxlength="120" value="<?= pl_e((string) ($selected['job_title'] ?? '')) ?>"></div>
                <div class="field"><label for="user-first-name"><?= pl_e(pl_t('First name')) ?></label><input class="input" id="user-first-name" name="first_name" maxlength="80" value="<?= pl_e((string) ($selected['first_name'] ?? '')) ?>"></div>
                <div class="field"><label for="user-last-name"><?= pl_e(pl_t('Last name')) ?></label><input class="input" id="user-last-name" name="last_name" maxlength="80" value="<?= pl_e((string) ($selected['last_name'] ?? '')) ?>"></div>
                <div class="field"><label for="user-phone"><?= pl_e(pl_t('Phone')) ?></label><input class="input" id="user-phone" name="phone" maxlength="40" value="<?= pl_e((string) ($selected['phone'] ?? '')) ?>"></div>
                <div class="field"><label for="user-details-reason"><?= pl_e(pl_t('Reason')) ?></label><input class="input" id="user-details-reason" name="reason" maxlength="500" required></div>
                <div class="sm:col-span-2">
                    <button class="btn btn-secondary" type="submit"><?= pl_e(pl_t('Save details')) ?></button>
                    <span class="row-sub"><?= pl_e(pl_t('The sign-in address and the password belong to them: they change their own, and you hand over a one-time reset link if they are locked out.')) ?></span>
                </div>
            </form>

            <form class="mt-3 flex flex-wrap items-end gap-3" method="post" action="<?= pl_e(pl_url('/users')) ?>">
                <?= pl_csrf_field() ?><?= pl_scope_fields($company) ?>
                <input type="hidden" name="action" value="role">
                <input type="hidden" name="user_id" value="<?= (int) $selected['id'] ?>">
                <div class="field"><label for="user-role"><?= pl_e(pl_t('Role in this company')) ?></label>
                    <select class="input" id="user-role" name="role_id">
                        <?php foreach ($roles as $role): ?>
                            <option value="<?= (int) $role['id'] ?>"<?= (int) $role['id'] === (int) ($selected['role_id'] ?? 0) ? ' selected' : '' ?>><?= pl_e((string) $role['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field flex-1 min-w-48"><label for="user-role-reason"><?= pl_e(pl_t('Reason')) ?></label><input class="input" id="user-role-reason" name="reason" maxlength="500" required></div>
                <button class="btn btn-primary" type="submit"><?= pl_e(pl_t('Save role')) ?></button>
            </form>

            <div class="mt-3 flex flex-wrap items-end gap-2 border-t border-border pt-3">
                <form method="post" action="<?= pl_e(pl_url('/users')) ?>" class="flex flex-wrap items-end gap-2">
                    <?= pl_csrf_field() ?><?= pl_scope_fields($company) ?>
                    <input type="hidden" name="user_id" value="<?= (int) $selected['id'] ?>">
                    <div class="field"><label for="user-action-reason"><?= pl_e(pl_t('Reason for the action below')) ?></label><input class="input" id="user-action-reason" name="reason" maxlength="500" required></div>
                    <button class="btn btn-secondary" name="action" value="reset_password"><?= pl_e(pl_t('Force a password reset')) ?></button>
                    <button class="btn btn-secondary" name="action" value="end_sessions"><?= pl_e(pl_t('End every session')) ?></button>
                    <?php if ($selected['is_active']): ?>
                        <button class="btn btn-secondary" name="action" value="suspend"<?= $sameActor ? ' disabled' : '' ?>><?= pl_e(pl_t('Suspend')) ?></button>
                    <?php else: ?>
                        <button class="btn btn-secondary" name="action" value="reactivate"><?= pl_e(pl_t('Reactivate')) ?></button>
                    <?php endif; ?>
                    <input type="hidden" name="granted" value="<?= $selected['is_installation_admin'] ? '0' : '1' ?>">
                    <button class="btn btn-secondary" name="action" value="installation_admin"><?= pl_e($selected['is_installation_admin'] ? pl_t('Withdraw installation administration') : pl_t('Make an installation administrator')) ?></button>
                </form>
            </div>

            <div class="mt-3 border-t border-border pt-3">
                <?php pl_ui_confirmation(pl_t('Remove access to this company'), pl_t('They stop being able to open this company. Their account and everything they posted are kept, and they keep access to any other company they work in.'), static function () use ($company, $selected): void { ?>
                    <form method="post" action="<?= pl_e(pl_url('/users')) ?>" class="flex flex-wrap items-end gap-2">
                        <?= pl_csrf_field() ?><?= pl_scope_fields($company) ?>
                        <input type="hidden" name="action" value="remove"><input type="hidden" name="user_id" value="<?= (int) $selected['id'] ?>">
                        <div class="field"><label for="user-remove-reason"><?= pl_e(pl_t('Reason')) ?></label><input class="input" id="user-remove-reason" name="reason" maxlength="500" required></div>
                        <button class="btn btn-danger" type="submit"><?= pl_e(pl_t('Confirm removal')) ?></button>
                    </form>
                <?php }); ?>
            </div>

            <div class="mt-3 border-t border-border pt-3">
                <p class="muted"><?= pl_e(pl_t('An account named by a posted record is never deleted: the books would lose the answer to "who did this". Suspending is reversible; anonymising is not.')) ?></p>
                <?php pl_ui_confirmation(pl_t('Anonymise this account'), pl_t('The personal details on the account are replaced and the account is suspended. Every journal, document and audit row keeps pointing at the same account. This cannot be undone.'), static function () use ($company, $selected): void { ?>
                    <form method="post" action="<?= pl_e(pl_url('/users')) ?>" class="flex flex-wrap items-end gap-2">
                        <?= pl_csrf_field() ?><?= pl_scope_fields($company) ?>
                        <input type="hidden" name="action" value="anonymise"><input type="hidden" name="user_id" value="<?= (int) $selected['id'] ?>">
                        <div class="field"><label for="user-anonymise-confirm"><?= pl_e(pl_t('Type anonymise to confirm')) ?></label><input class="input" id="user-anonymise-confirm" name="confirm" maxlength="20" required></div>
                        <div class="field"><label for="user-anonymise-reason"><?= pl_e(pl_t('Reason')) ?></label><input class="input" id="user-anonymise-reason" name="reason" maxlength="500" required></div>
                        <button class="btn btn-danger" type="submit"><?= pl_e(pl_t('Anonymise permanently')) ?></button>
                    </form>
                <?php }); ?>
            </div>

            <?php if ($history !== []): ?>
                <div class="mt-3 border-t border-border pt-3">
                    <h3 class="section-title mb-2"><?= pl_e(pl_t('What happened to this account')) ?></h3>
                    <ul class="text-sm text-ink-muted">
                        <?php foreach ($history as $entry): ?>
                            <li><?= pl_e((string) $entry['recorded_at']) ?> · <?= pl_e((string) $entry['action']) ?> · <?= pl_e((string) $entry['display_name']) ?><?php if ((string) $entry['reason'] !== ''): ?> · <?= pl_e((string) $entry['reason']) ?><?php endif; ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="rounded-panel border border-border bg-surface p-4">
        <h2 class="section-title mb-2"><?= pl_e(pl_t('Invite someone')) ?></h2>
        <p class="muted"><?= pl_e(pl_t('An invitation names one role in this company. The person sets their own password when they accept it; you never choose or see it.')) ?></p>
        <form class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2" method="post" action="<?= pl_e(pl_url('/users')) ?>">
            <?= pl_csrf_field() ?><?= pl_scope_fields($company) ?>
            <input type="hidden" name="action" value="invite">
            <div class="field"><label for="invite-email"><?= pl_e(pl_t('Email address')) ?></label><input class="input" id="invite-email" name="email" type="email" maxlength="254" required value="<?= pl_e(pl_web_text($usersInput, 'email')) ?>"></div>
            <div class="field"><label for="invite-role"><?= pl_e(pl_t('Role')) ?></label>
                <select class="input" id="invite-role" name="role_id">
                    <?php foreach ($roles as $role): ?>
                        <option value="<?= (int) $role['id'] ?>"><?= pl_e((string) $role['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field sm:col-span-2"><label for="invite-message"><?= pl_e(pl_t('Message (optional)')) ?></label><input class="input" id="invite-message" name="message" maxlength="500" value="<?= pl_e(pl_web_text($usersInput, 'message')) ?>"></div>
            <div class="sm:col-span-2"><button class="btn btn-primary" type="submit"><?= pl_e(pl_t('Create invitation')) ?></button></div>
        </form>

        <?php if ($invitations !== []): ?>
            <div class="table-wrap mt-4" tabindex="0" role="region" aria-label="<?= pl_e(pl_t('Invitations')) ?>">
                <table class="table table-dense">
                    <thead><tr>
                        <th scope="col"><?= pl_e(pl_t('Email address')) ?></th>
                        <th scope="col"><?= pl_e(pl_t('Role')) ?></th>
                        <th scope="col"><?= pl_e(pl_t('Invited by')) ?></th>
                        <th scope="col"><?= pl_e(pl_t('Expires')) ?></th>
                        <th scope="col"><?= pl_e(pl_t('Status')) ?></th>
                        <th scope="col"><?= pl_e(pl_t('Action')) ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($invitations as $invitation): ?>
                        <tr>
                            <td><?= pl_e((string) $invitation['email']) ?></td>
                            <td><?= pl_e((string) $invitation['role_name']) ?></td>
                            <td><?= pl_e((string) $invitation['invited_by']) ?></td>
                            <td class="whitespace-nowrap"><?= pl_e((string) $invitation['expires_at']) ?></td>
                            <td><?= pl_e(match ($invitation['status']) { 'accepted' => pl_t('Accepted'), 'revoked' => pl_t('Revoked'), 'expired' => pl_t('Expired'), default => pl_t('Waiting') }) ?></td>
                            <td class="text-end">
                                <?php if ($invitation['status'] === 'open'): ?>
                                    <form method="post" action="<?= pl_e(pl_url('/users')) ?>" class="flex items-end gap-2">
                                        <?= pl_csrf_field() ?><?= pl_scope_fields($company) ?>
                                        <input type="hidden" name="action" value="revoke_invitation">
                                        <input type="hidden" name="invitation_id" value="<?= (int) $invitation['id'] ?>">
                                        <input type="hidden" name="reason" value="<?= pl_e(pl_t('Revoked from Admin > Users.')) ?>">
                                        <button class="btn btn-ghost btn-sm" type="submit"><?= pl_e(pl_t('Revoke')) ?></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>
