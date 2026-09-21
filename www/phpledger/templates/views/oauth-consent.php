<?php declare(strict_types=1); ?>
<section class="auth-panel" aria-labelledby="consent-title">
    <p class="eyebrow"><?= pl_e(pl_t('Authorize a reporting client')) ?></p>
    <?php pl_ui_page_header(pl_t('Connect {client}', ['client' => $client->getName()]), pl_t('You are signed in as {user}. Choose the company this client may read.', ['user' => $user['display_name']]), null, 'consent-title'); ?>
    <section class="panel">
        <h2 class="section-title"><?= pl_e(pl_t('Requested access')) ?></h2>
        <p><?= pl_e(pl_t('Accounts, transactions, posted journals and financial reports for the selected company and book. Your current membership and permissions are checked on every request.')) ?> <strong><?= pl_e(pl_t('Financial writes are unavailable.')) ?></strong></p>
        <dl class="dgrid">
            <div class="dgrid-row"><dt><?= pl_e(pl_t('Client identifier')) ?></dt><dd class="break-all"><code><?= pl_e($client->getIdentifier()) ?></code></dd></div>
            <div class="dgrid-row"><dt><?= pl_e(pl_t('Return address')) ?></dt><dd class="break-all"><code><?= pl_e($pending['query']['redirect_uri']) ?></code></dd></div>
        </dl>
        <p class="text-xs text-ink-muted mt-3"><?= pl_e(pl_t('Financial results will be sent to this client. Review its identity and data handling before allowing access.')) ?></p>
        <p class="text-xs text-ink-muted"><?= pl_e(pl_demo_enabled() ? pl_t('Access ends at the next hourly sample reset. Start a fresh sample and reconnect afterward.') : pl_t('The grant expires in 30 days and can be revoked sooner in Connections. Access tokens last up to 15 minutes.')) ?></p>
    </section>
    <?php if ($message !== ''): ?><div class="alert alert-warning" role="alert" tabindex="-1" data-form-error><?= pl_e($message) ?></div><?php endif; ?>
    <form method="post" action="<?= pl_e(pl_url('/oauth/authorize')) ?>" class="flex flex-col gap-4">
        <?= pl_csrf_field() ?><input type="hidden" name="consent_nonce" value="<?= pl_e($pending['nonce']) ?>">
        <?php pl_ui_field('consent-company', pl_t('Company and book'), static function () use ($companies): void { ?>
            <select class="select" id="consent-company" name="company_id" required>
                <option value=""><?= pl_e(pl_t('Choose a company')) ?></option>
                <?php foreach ($companies as $availableCompany): ?><option value="<?= (int) $availableCompany['id'] ?>"><?= pl_e(pl_t('{company} · Book {book}', ['company' => $availableCompany['name'], 'book' => $availableCompany['book_id']])) ?></option><?php endforeach; ?>
            </select>
        <?php }); ?>
        <?php pl_ui_connection_scope('consent-scope'); ?>
        <div class="panel-actions" data-fold="primary action"><button class="btn btn-primary" name="decision" value="allow"><?= pl_e(pl_t('Allow read access')) ?></button><button class="btn btn-ghost" name="decision" value="deny" formnovalidate><?= pl_e(pl_t('Cancel')) ?></button></div>
    </form>
</section>
