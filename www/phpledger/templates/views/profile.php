<?php
declare(strict_types=1);
/**
 * Your own profile (release plan 1.2 M7). The prototype's "Change my password" dialog said it
 * best: you can change only your own password, and the old screen that let anyone pick any user
 * is gone. Administrators force a reset from Admin > Users instead; they never choose a password.
 *
 * @var array|null $company @var array $user @var array $profile @var array $sessions
 * @var string $currentHandle @var array $memberships @var array|null $pendingEmail
 * @var array $form @var array $input
 */
$profileInput = $form['input'];
$value = static function (string $field) use ($profileInput, $profile): string {
    $current = array_key_exists($field, $profile) && is_scalar($profile[$field]) ? (string) $profile[$field] : '';
    return pl_web_text($profileInput, $field, $current);
};
?>
<section class="flex flex-col gap-4 py-5" aria-labelledby="profile-title">
    <div class="page-header">
        <div>
            <p class="eyebrow"><?= pl_e(pl_t('Your account')) ?></p>
            <h1 class="page-title" id="profile-title"><?= pl_e(pl_t('Your profile')) ?></h1>
            <p class="muted"><?= pl_e(pl_t('Your name, how you sign in, and where you are signed in. These settings belong to you, not to a business.')) ?></p>
        </div>
    </div>

    <?php if ($form['message'] !== ''): ?>
        <div class="alert alert-danger" role="alert" tabindex="-1" data-form-error><p><?= pl_e((string) $form['message']) ?></p></div>
    <?php endif; ?>
    <?php if ($profile['must_change_password']): ?>
        <div class="alert alert-warning" role="alert"><p><?= pl_e(pl_t('An administrator asked you to set a new password. Until you do, this is the only screen available.')) ?></p></div>
    <?php endif; ?>
    <?php if ($pendingEmail !== null): ?>
        <div class="alert alert-info" role="status">
            <h2 class="section-title mb-2"><?= pl_e(pl_t('Confirm your new email address')) ?></h2>
            <p><?= pl_e(pl_t('Open this one-time link to move your sign-in address to {email}. Until you do, nothing changes. This release does not send email, so the link is shown here once.', ['email' => (string) $pendingEmail['email']])) ?></p>
            <p><code class="break-all"><?= pl_e((string) $pendingEmail['url']) ?></code></p>
        </div>
    <?php endif; ?>

    <div class="rounded-panel border border-border bg-surface p-4">
        <h2 class="section-title mb-2"><?= pl_e(pl_t('About you')) ?></h2>
        <form class="grid grid-cols-1 gap-3 sm:grid-cols-2" method="post" action="<?= pl_e(pl_url('/profile')) ?>">
            <?= pl_csrf_field() ?><input type="hidden" name="action" value="profile">
            <div class="field"><label for="profile-display-name"><?= pl_e(pl_t('Name')) ?></label><input class="input" id="profile-display-name" name="display_name" maxlength="120" required value="<?= pl_e($value('display_name')) ?>"></div>
            <div class="field"><label for="profile-username"><?= pl_e(pl_t('Username (optional)')) ?></label><input class="input" id="profile-username" name="username" maxlength="60" value="<?= pl_e($value('username')) ?>"><p class="field-hint"><?= pl_e(pl_t('Three to sixty letters, numbers, dots, dashes or underscores. You can sign in with it instead of your email address.')) ?></p></div>
            <div class="field"><label for="profile-first-name"><?= pl_e(pl_t('First name')) ?></label><input class="input" id="profile-first-name" name="first_name" maxlength="80" value="<?= pl_e($value('first_name')) ?>"></div>
            <div class="field"><label for="profile-last-name"><?= pl_e(pl_t('Last name')) ?></label><input class="input" id="profile-last-name" name="last_name" maxlength="80" value="<?= pl_e($value('last_name')) ?>"></div>
            <div class="field"><label for="profile-phone"><?= pl_e(pl_t('Phone')) ?></label><input class="input" id="profile-phone" name="phone" maxlength="40" value="<?= pl_e($value('phone')) ?>"></div>
            <div class="field"><label for="profile-job-title"><?= pl_e(pl_t('Job title')) ?></label><input class="input" id="profile-job-title" name="job_title" maxlength="120" value="<?= pl_e($value('job_title')) ?>"></div>
            <div class="field"><label for="profile-locale"><?= pl_e(pl_t('Language')) ?></label><input class="input" id="profile-locale" name="locale" maxlength="35" value="<?= pl_e($value('locale')) ?>"><p class="field-hint"><?= pl_e(pl_t('A language tag such as en or ur-PK. The interface is English in this release; the setting is stored ready for the translated one.')) ?></p></div>
            <div class="field"><label for="profile-timezone"><?= pl_e(pl_t('Time zone')) ?></label>
                <select class="input" id="profile-timezone" name="timezone">
                    <option value=""><?= pl_e(pl_t('Use the installation default (UTC)')) ?></option>
                    <?php foreach (pl_timezone_options() as $zone): ?>
                        <option value="<?= pl_e($zone) ?>"<?= $zone === $value('timezone') ? ' selected' : '' ?>><?= pl_e($zone) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="field-hint"><?= pl_e(pl_t('Accounting dates are business calendar dates and never move with a time zone. This affects the times shown beside records.')) ?></p>
            </div>
            <div class="sm:col-span-2"><button class="btn btn-primary" type="submit"><?= pl_e(pl_t('Save profile')) ?></button></div>
        </form>
    </div>

    <div class="rounded-panel border border-border bg-surface p-4">
        <h2 class="section-title mb-2"><?= pl_e(pl_t('Change your password')) ?></h2>
        <p class="muted"><?= pl_e(pl_t('You can change only your own password. Your current password is required, and every other signed-in session is ended.')) ?></p>
        <form class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2" method="post" action="<?= pl_e(pl_url('/profile')) ?>">
            <?= pl_csrf_field() ?><input type="hidden" name="action" value="password">
            <div class="field sm:col-span-2"><label for="profile-current-password"><?= pl_e(pl_t('Current password')) ?></label><input class="input" id="profile-current-password" name="current_password" type="password" autocomplete="current-password" required></div>
            <div class="field"><label for="profile-new-password"><?= pl_e(pl_t('New password')) ?></label><input class="input" id="profile-new-password" name="new_password" type="password" autocomplete="new-password" required><p class="field-hint"><?= pl_e(pl_t('Between 6 and 72 characters; longer is safer.')) ?></p></div>
            <div class="sm:col-span-2"><button class="btn btn-primary" type="submit"><?= pl_e(pl_t('Save password')) ?></button></div>
        </form>
    </div>

    <div class="rounded-panel border border-border bg-surface p-4">
        <h2 class="section-title mb-2"><?= pl_e(pl_t('Your email address')) ?></h2>
        <p class="muted"><?= pl_e(pl_t('This is your sign-in address. Changing it needs a confirmation, and every session is ended when it takes effect.')) ?></p>
        <form class="mt-3 flex flex-wrap items-end gap-3" method="post" action="<?= pl_e(pl_url('/profile')) ?>">
            <?= pl_csrf_field() ?><input type="hidden" name="action" value="email">
            <div class="field flex-1 min-w-64"><label for="profile-email"><?= pl_e(pl_t('New email address')) ?></label><input class="input" id="profile-email" name="email" type="email" maxlength="254" required value="<?= pl_e(pl_web_text($profileInput, 'email')) ?>"><p class="field-hint"><?= pl_e(pl_t('Currently {email}.', ['email' => (string) $profile['email']])) ?></p></div>
            <button class="btn btn-secondary" type="submit"><?= pl_e(pl_t('Request the change')) ?></button>
        </form>
    </div>

    <div class="rounded-panel border border-border bg-surface p-4">
        <h2 class="section-title mb-2"><?= pl_e(pl_t('Where you are signed in')) ?></h2>
        <p class="muted"><?= pl_e(pl_t('Each row is a signed-in browser. Ending one signs it out immediately, wherever it is.')) ?></p>
        <div class="table-wrap mt-3" tabindex="0" role="region" aria-label="<?= pl_e(pl_t('Active sessions')) ?>">
            <table class="table table-dense">
                <thead><tr>
                    <th scope="col"><?= pl_e(pl_t('Session')) ?></th>
                    <th scope="col"><?= pl_e(pl_t('Browser')) ?></th>
                    <th scope="col"><?= pl_e(pl_t('Started')) ?></th>
                    <th scope="col"><?= pl_e(pl_t('Last seen')) ?></th>
                    <th scope="col"><?= pl_e(pl_t('Action')) ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($sessions as $session): $isCurrent = (string) $session['handle'] === $currentHandle; ?>
                    <tr>
                        <td class="tabular-nums"><?= pl_e((string) $session['handle']) ?><?php if ($isCurrent): ?><span class="row-sub"><?= pl_e(pl_t('This browser')) ?></span><?php endif; ?></td>
                        <td class="text-ink-muted"><?= pl_e(mb_substr((string) $session['client_label'], 0, 80)) ?></td>
                        <td class="whitespace-nowrap"><?= pl_e((string) $session['created_at']) ?></td>
                        <td class="whitespace-nowrap"><?= pl_e((string) $session['last_seen_at']) ?></td>
                        <td class="text-end">
                            <?php if (!$isCurrent): ?>
                                <form method="post" action="<?= pl_e(pl_url('/profile')) ?>">
                                    <?= pl_csrf_field() ?><input type="hidden" name="action" value="end_session">
                                    <input type="hidden" name="handle" value="<?= pl_e((string) $session['handle']) ?>">
                                    <button class="btn btn-ghost btn-sm" type="submit"><?= pl_e(pl_t('Sign out')) ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <form class="mt-3" method="post" action="<?= pl_e(pl_url('/profile')) ?>">
            <?= pl_csrf_field() ?><input type="hidden" name="action" value="end_everywhere">
            <button class="btn btn-secondary" type="submit"><?= pl_e(pl_t('Sign out everywhere else')) ?></button>
        </form>
    </div>

    <?php if ($memberships !== []): ?>
        <div class="rounded-panel border border-border bg-surface p-4">
            <h2 class="section-title mb-2"><?= pl_e(pl_t('Where you work')) ?></h2>
            <ul class="text-sm text-ink-muted">
                <?php foreach ($memberships as $membership): ?>
                    <li><?= pl_e((string) $membership['name']) ?> · <?= pl_e((string) $membership['role_name']) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
</section>
