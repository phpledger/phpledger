<?php declare(strict_types=1); ?>
<section class="flex flex-col gap-4 py-5" aria-labelledby="error-title">
    <div class="rounded-panel border border-border bg-surface p-4">
        <p class="eyebrow"><?= pl_e(pl_t('Unable to complete this action')) ?></p>
        <?php pl_ui_page_header((string) $title, '', null, 'error-title'); ?>
        <div class="alert alert-danger" role="alert" tabindex="-1" data-form-error><p><?= pl_e((string) $message) ?></p></div>
        <?php if (($errorContext ?? '') === 'oauth'): ?>
        <p><?= pl_e(pl_t('Start the connection again from your client. If the request is still rejected, ask the client\'s maintainer to check its registered return address and requested access. This page does not send you to an unverified client address.')) ?></p>
        <?php elseif (($errorContext ?? '') === 'demo'): ?>
        <p><?= pl_e(pl_t('The public sample refreshes hourly. Wait a moment and try again. Once the refresh completes, start a new private sample; entries and unsaved work from the expired sample are not carried forward.')) ?></p>
        <?php else: ?>
        <p><?= pl_e(pl_t('Check that you are working in the intended business. If your access changed, ask its owner to review your permissions.')) ?></p>
        <?php endif; ?>
        <div class="panel-actions" data-fold="primary action"><a class="btn btn-primary" href="<?= pl_e(pl_url(($errorContext ?? '') === 'demo' ? '/login' : '/companies')) ?>"><?= pl_e(($errorContext ?? '') === 'demo' ? pl_t('Try the sample again') : pl_t('Return to your businesses')) ?></a><a class="btn btn-secondary" href="<?= pl_e(pl_url('/help')) ?>"><?= pl_e(pl_t('Getting-started help')) ?></a></div>
    </div>
</section>
