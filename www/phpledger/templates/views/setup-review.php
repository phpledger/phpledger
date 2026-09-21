<?php
declare(strict_types=1);
$reviewInput = $form['input'];
$roleInput = is_array($reviewInput['roles'] ?? null) ? $reviewInput['roles'] : [];
?>
<section class="flex flex-col gap-4 py-5" aria-labelledby="review-title">
    <?php pl_ui_page_header(pl_t('Review your existing books'), (string) $company['name'] . ' · ' . (string) $company['currency'], static function (): void { ?>
        <a class="btn btn-ghost" href="<?= pl_e(pl_url('/transactions')) ?>"><?= pl_e(pl_t('Back to transactions')) ?></a>
    <?php }, 'review-title'); ?>
    <?php if ($form['message'] !== ''): ?>
        <div class="alert alert-info" role="alert" tabindex="-1" data-form-error><h2 class="section-title mb-2"><?= pl_e(pl_t('Review needs attention')) ?></h2><p><?= pl_e((string) $form['message']) ?></p><p><?= pl_e(pl_t('Your selections are preserved below.')) ?></p></div>
    <?php endif; ?>
    <?php if ($company['setup_status'] === 'opening_required'): ?>
        <div class="rounded-panel border border-border bg-surface p-4"><h2 class="section-title mb-2"><?= pl_e(pl_t('Opening balances still need reconciliation')) ?></h2><p><?= pl_e(pl_t('Preview and reconcile this business\'s opening trial balance and unpaid invoices/bills before recording or posting transactions.')) ?></p><a class="btn btn-secondary" href="<?= pl_e(pl_url('/opening-balances')) ?>"><?= pl_e(pl_t('Review opening cutover')) ?></a></div>
    <?php elseif ($company['setup_status'] !== 'review_required'): ?>
        <div class="rounded-panel border border-border bg-surface p-4"><h2 class="section-title mb-2"><?= pl_e(pl_t('Setup review is complete')) ?></h2><p><?= pl_e(pl_t('Your current setup does not need the prior-foundation account review.')) ?></p><a class="btn btn-primary" href="<?= pl_e(pl_url('/transactions')) ?>"><?= pl_e(pl_t('Open transactions')) ?></a></div>
    <?php elseif (!pl_can_write($company)): ?>
        <div class="rounded-panel border border-border bg-surface p-4"><h2 class="section-title mb-2"><?= pl_e(pl_t('An authorised owner needs to review this setup')) ?></h2><p><?= pl_e(pl_t('You can read these books, but your role cannot change account assignments or confirm the opening setup.')) ?></p><a class="btn btn-secondary" href="<?= pl_e(pl_url('/reports/trial-balance')) ?>"><?= pl_e(pl_t('View trial balance')) ?></a></div>
    <?php else: ?>
        <div class="rounded-panel border border-border bg-surface p-4"><h2 class="section-title mb-2"><?= pl_e(pl_t('Keep your existing records intact')) ?></h2><p><?= pl_e(pl_t('Choose which existing account serves each purpose below. This records account roles without replacing account names, balances, or posted journals. Each purpose needs a separate active account of the matching type.')) ?></p><p class="muted"><?= pl_e(pl_t('{name}, version {version}.', ['name' => (string) $template['name'], 'version' => (string) $template['version']])) ?> <?= pl_e((string) $template['notice']) ?></p><a href="<?= pl_e(pl_url('/reports/trial-balance')) ?>"><?= pl_e(pl_t('Review the current trial balance')) ?></a></div>
        <form action="<?= pl_e(pl_url('/setup/review')) ?>" method="post" class="rounded-panel border border-border bg-surface p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <?= pl_csrf_field() ?>
            <?= pl_scope_fields($company) ?>
            <?php foreach ($template['accounts'] as $definition): ?>
                <?php $fieldId = 'role-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', (string) $definition['semantic_key']); ?>
                <?php pl_ui_field($fieldId, (string) $definition['name'] . ' (' . (string) $definition['type'] . ')', static function () use ($fieldId, $definition, $company, $roleInput): void { ?>
                    <select class="select" id="<?= pl_e($fieldId) ?>" name="roles[<?= pl_e((string) $definition['semantic_key']) ?>]" required>
                        <option value=""><?= pl_e(pl_t('Choose an existing account')) ?></option>
                        <?php foreach ($company['accounts'] as $available): ?>
                            <?php if (!$available['is_active'] || $available['type'] !== $definition['type']) { continue; } ?>
                            <option value="<?= pl_e((string) $available['id']) ?>"<?= pl_web_id($roleInput, (string) $definition['semantic_key']) === (int) $available['id'] ? ' selected' : '' ?>><?= pl_e($available['code'] . ' — ' . $available['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php }); ?>
            <?php endforeach; ?>
            <div class="field sm:col-span-2 lg:col-span-3">
                <label class="checkbox"><input type="checkbox" name="reviewed" value="1" required<?= pl_web_text($reviewInput, 'reviewed') === '1' ? ' checked' : '' ?>> <?= pl_e(pl_t('I have reviewed the existing accounts and opening balances, including any unpaid invoices and bills.')) ?></label>
                <p class="muted"><?= pl_e(pl_t('This confirms a review of records already in these books. It does not import missing history or confirm tax compliance.')) ?></p>
            </div>
            <div class="panel-actions sm:col-span-2 lg:col-span-3" data-fold="primary action"><button class="btn btn-primary" type="submit"><?= pl_e(pl_t('Confirm reviewed setup')) ?></button><a class="btn btn-secondary" href="<?= pl_e(pl_url('/transactions')) ?>"><?= pl_e(pl_t('Cancel')) ?></a></div>
        </form>
    <?php endif; ?>
</section>
