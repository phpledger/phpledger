<?php declare(strict_types=1);
require_once __DIR__ . '/../../../includes/functions/i18n_functions.php'; ?>
<!doctype html>
<html lang="<?= pl_e(pl_locale()) ?>" dir="<?= pl_e(pl_text_direction()) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light">
<title><?= pl_e($title) ?> · <?= pl_e(pl_t('PHP Ledger')) ?></title><link rel="stylesheet" href="<?= pl_e(pl_url('/assets/app.css')) ?>"><script src="<?= pl_e(pl_url('/assets/help.js')) ?>" defer></script></head>
<body><main class="auth-shell"><div class="auth-card">
<img class="auth-logo" src="<?= pl_e(pl_url('/assets/brand/phpledger-horizontal.png')) ?>" alt="<?= pl_e(pl_t('PHP Ledger')) ?>" width="2172" height="724">
<header class="context-help-header"><?php pl_ui_page_help('error'); ?></header>
<section class="auth-panel"><p class="eyebrow"><?= pl_e(pl_t('Error {status}', ['status' => (int) $status])) ?></p>
<?php pl_ui_page_header($title, $message); ?>
<div class="panel-actions" data-fold="primary action">
<?php if ($status === 503): ?><a class="btn btn-primary" href="<?= pl_e(pl_url('/')) ?>"><?= pl_e(pl_t('Try again')) ?></a>
<?php elseif ($status === 405): ?><a class="btn btn-primary" href="<?= pl_e(pl_url('/transactions')) ?>"><?= pl_e(pl_t('Back to receipts & expenses')) ?></a>
<?php else: ?><a class="btn btn-primary" href="<?= pl_e(pl_url('/companies')) ?>"><?= pl_e(pl_t('Return to your businesses')) ?></a><?php endif; ?>
<a class="btn btn-secondary" href="<?= pl_e(pl_url('/help')) ?>"><?= pl_e(pl_t('Getting-started help')) ?></a>
</div></section></div></main></body></html>
