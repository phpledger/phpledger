<?php declare(strict_types=1); ?>
<section class="auth-panel" aria-labelledby="login-title">
    <h1 class="page-title text-center" id="login-title"><?= pl_e(pl_t('Sign in')) ?></h1>
    <p class="mt-1.5 text-center text-sm text-ink-muted"><?= pl_e(pl_t('Sign in to work with your business books.')) ?></p>
    <?php if (pl_shared_demo_enabled()): $demoAccount = pl_shared_demo_account(); ?>
        <div class="alert alert-info mt-4" role="note">
            <p><?= pl_e(pl_t('Everyone shares this demo. Use fictional information; all work resets hourly.')) ?></p>
            <p><?= pl_e(pl_t('Username')) ?>: <code><?= pl_e($demoAccount['username']) ?></code><br>
            <?= pl_e(pl_t('Password')) ?>: <code><?= pl_e($demoAccount['password']) ?></code></p>
        </div>
    <?php endif; ?>
    <?php if ($form['message'] !== ''): ?>
        <div class="alert alert-danger mt-4" role="alert" tabindex="-1" id="login-error" data-form-error>
            <h2><?= pl_e(pl_t('We could not sign you in')) ?></h2>
            <p><?= pl_e((string) $form['message']) ?></p>
        </div>
    <?php endif; ?>
    <form action="<?= pl_e(pl_url('/login')) ?>" method="post" class="mt-5 flex flex-col gap-4">
        <?= pl_csrf_field() ?>
        <?php pl_ui_field('login-email', pl_t('Email or username'), static function () use ($form): void { ?>
            <input class="input" id="login-email" name="email" type="text" autocomplete="username" autocapitalize="none" spellcheck="false" required maxlength="254" value="<?= pl_e(pl_web_text($form['input'], 'email')) ?>">
        <?php }); ?>
        <?php pl_ui_field('login-password', pl_t('Password'), static function (): void { ?>
            <input class="input" id="login-password" name="password" type="password" autocomplete="current-password" required>
        <?php }); ?>
        <button class="btn btn-primary w-full justify-center" type="submit" data-fold="primary action"><?= pl_e(pl_t('Sign in')) ?></button>
    </form>
    <p class="mt-5 text-center text-xs text-ink-muted"><?= pl_e(pl_t('Your administrator provides your account. If you need access or a password reset, contact the person who manages this installation.')) ?></p>
</section>
