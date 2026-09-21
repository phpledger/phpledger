<?php declare(strict_types=1); ?>
<?php if (pl_demo_enabled() && $view !== 'error'): $demoState = DB::queryFirstRow('SELECT next_reset_at FROM pl_demo_state WHERE id = 1'); ?>
<div class="demo-banner"><span><strong><?= pl_e(pl_t('Public demo')) ?></strong> · <?= pl_e(pl_t('Separate sample data for each visitor. Destructive actions are disabled.')) ?></span><span><?= pl_e(pl_t('Resets')) ?> <time data-local-time datetime="<?= pl_e(str_replace(' ', 'T', $demoState['next_reset_at']) . 'Z') ?>"><?= pl_e(pl_t('{time} UTC', ['time' => $demoState['next_reset_at']])) ?></time> · <span data-demo-expiry="<?= pl_e(str_replace(' ', 'T', $demoState['next_reset_at']) . 'Z') ?>"><?= pl_e(pl_tn('{count} minute remaining', '{count} minutes remaining', max(0, (int) ceil((strtotime($demoState['next_reset_at'] . ' UTC') - time()) / 60)), ['count' => max(0, (int) ceil((strtotime($demoState['next_reset_at'] . ' UTC') - time()) / 60))])) ?></span></span></div>
<?php endif; ?>
<?php if ($company): ?>
<?php if ($company['setup_status'] !== 'ready'): ?>
<div class="readiness-banner"><?= pl_icon('info-circle') ?><div><strong><?= pl_e($company['setup_status'] === 'opening_required' ? pl_t('Opening balances required.') : pl_t('Review your existing setup.')) ?></strong> <?= pl_e($company['setup_status'] === 'opening_required' ? pl_t('Reconcile opening balances and unpaid documents before recording or posting transactions.') : pl_t('Confirm account mappings and opening balances before posting new documents.')) ?> <?php if (pl_can_write($company)): ?><a href="<?= pl_e(pl_url($company['setup_status'] === 'opening_required' ? '/opening-balances' : '/setup/review')) ?>"><?= pl_e(pl_t('Review setup')) ?></a><?php endif; ?></div></div>
<?php endif; ?>
<?php endif; ?>
