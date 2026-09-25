<?php
declare(strict_types=1);
/**
 * The two signed-out screens that make an invitation and a forced password reset reachable
 * (release plan 1.2 M7). Both redeem a one-time token from a link an administrator handed over,
 * and both end at the sign-in page. Neither reveals whether the token is valid before it is
 * submitted, and neither says which address or account it belongs to.
 *
 * @var string $mode @var string $token @var array $form @var array $input
 */
$accessInput = $form['input'];
?>
<section class="flex flex-col gap-4 py-5" aria-labelledby="account-access-title">
    <div class="page-header">
        <div>
            <p class="eyebrow"><?= pl_e(pl_t('PHP Ledger')) ?></p>
            <h1 class="page-title" id="account-access-title"><?= pl_e($mode === 'invitation' ? pl_t('Accept your invitation') : pl_t('Set a new password')) ?></h1>
            <p class="muted"><?= pl_e($mode === 'invitation'
                ? pl_t('You were invited to work in a business on this installation. Choose how you will sign in. Nobody else sees or chooses your password.')
                : pl_t('An administrator reset your password. Choose a new one to sign in again.')) ?></p>
        </div>
    </div>

    <?php if ($form['message'] !== ''): ?>
        <div class="alert alert-danger" role="alert" tabindex="-1" data-form-error><p><?= pl_e((string) $form['message']) ?></p></div>
    <?php endif; ?>

    <div class="rounded-panel border border-border bg-surface p-4 max-w-xl">
        <form class="grid grid-cols-1 gap-3" method="post" action="<?= pl_e(pl_url($mode === 'invitation' ? '/invitation' : '/reset-password')) ?>">
            <?= pl_csrf_field() ?>
            <input type="hidden" name="token" value="<?= pl_e($token) ?>">
            <?php if ($mode === 'invitation'): ?>
                <div class="field"><label for="access-display-name"><?= pl_e(pl_t('Your name')) ?></label><input class="input" id="access-display-name" name="display_name" maxlength="120" required value="<?= pl_e(pl_web_text($accessInput, 'display_name')) ?>"></div>
                <div class="field"><label for="access-username"><?= pl_e(pl_t('Username (optional)')) ?></label><input class="input" id="access-username" name="username" maxlength="60" value="<?= pl_e(pl_web_text($accessInput, 'username')) ?>"><p class="field-hint"><?= pl_e(pl_t('Three to sixty letters, numbers, dots, dashes or underscores. You can sign in with your email address instead.')) ?></p></div>
            <?php else: ?>
                <div class="field"><label for="access-sign-in-name"><?= pl_e(pl_t('Your email address or username')) ?></label><input class="input" id="access-sign-in-name" name="sign_in_name" maxlength="254" required value="<?= pl_e(pl_web_text($accessInput, 'sign_in_name')) ?>"></div>
            <?php endif; ?>
            <div class="field"><label for="access-password"><?= pl_e(pl_t('Password')) ?></label><input class="input" id="access-password" name="password" type="password" autocomplete="new-password" required><p class="field-hint"><?= pl_e(pl_t('Between 6 and 72 characters; longer is safer.')) ?></p></div>
            <div><button class="btn btn-primary" type="submit"><?= pl_e($mode === 'invitation' ? pl_t('Accept and continue') : pl_t('Set password')) ?></button></div>
        </form>
    </div>

    <p class="text-sm text-ink-muted"><a href="<?= pl_e(pl_url('/login')) ?>"><?= pl_e(pl_t('Back to sign in')) ?></a></p>
</section>
