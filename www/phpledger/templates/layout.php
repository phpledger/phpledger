<?php
declare(strict_types=1);
require_once __DIR__ . '/partials/ui/components.php';
require_once __DIR__ . '/../includes/functions/i18n_functions.php';
$posLayout = $user !== null && $company !== null && $view === 'pos';
$workspace = $user !== null && $company !== null && !in_array($view, ['oauth-consent', 'pos'], true);
?>
<!doctype html>
<html lang="<?= pl_e(pl_locale()) ?>" dir="<?= pl_e(pl_text_direction()) ?>" data-screen="<?= pl_e($view) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <link rel="icon" href="<?= pl_e(pl_url('/assets/brand/phpledger-horizontal.png')) ?>" type="image/png">
    <title><?= pl_e($title) ?> · PHP Ledger</title>
    <link rel="preload" href="<?= pl_e(pl_url('/assets/fonts/InterVariable.woff2')) ?>" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= pl_e(pl_url('/assets/app.css', ['v' => 'redesign-foundation'])) ?>">

    <script src="<?= pl_e(pl_url('/assets/app.js', ['v' => '20260916-setup'])) ?>" defer></script>


</head>
<body class="view-<?= pl_e($view) ?>">
<a class="skip-link" href="#main">Skip to content</a>
<?php if ($workspace):
    // Keep navigation iteration variables out of the view's extracted data.
    (static function (array $user, array $company, string $view, string $title): void {
        require __DIR__ . '/partials/ui/shell.php';
    })($user, $company, $view === 'settlement' ? (($direction ?? '') === 'receivable' ? 'ar' : 'ap') : $view, $title);
?>
<div class="shell-strips">
<?php require __DIR__ . '/partials/ui/context-strips.php'; ?>
</div>
<main id="main" class="shell-main" tabindex="-1"><div class="shell-main-inner">
<?php elseif ($posLayout): ?>
<main id="main" tabindex="-1">
<?php else: ?>
<div class="auth-shell"><div class="auth-card<?= in_array($view, ['login','oauth-consent','error'], true) ? '' : ' auth-card-wide' ?>">
<?php $customLogo = function_exists('pl_logo_current') ? pl_logo_current() : null; ?>
<a href="<?= pl_e(pl_url('/')) ?>" aria-label="PHP Ledger home"><?php if ($customLogo !== null): ?><img class="auth-logo" src="<?= pl_e(pl_logo_url($customLogo)) ?>" alt="Business logo" width="<?= $customLogo['width'] ?>" height="<?= $customLogo['height'] ?>"><?php else: ?><img class="auth-logo" src="<?= pl_e(pl_url('/assets/brand/phpledger-horizontal.png')) ?>" alt="PHP Ledger" width="2172" height="724"><?php endif; ?></a>
<main id="main" tabindex="-1">
<?php endif; ?>
<?php $insecureSite = pl_web_insecure_site_notice(); ?>
<?php if ($insecureSite !== null): ?><div class="strip strip-warning" role="note"><?= pl_icon('alert-triangle') ?><p><?= pl_e($insecureSite) ?></p></div><?php endif; ?>
<?php if ($notice): ?><div class="strip strip-info" role="status" data-dismissible><p><?= pl_e($notice) ?></p><button type="button" class="strip-dismiss" data-dismiss aria-label="Dismiss notification"><?= pl_icon('x') ?></button></div><?php endif; ?>
<?php if (!empty($accountReturn)): ?><a class="btn btn-ghost my-3" href="<?= pl_e(pl_url('/reports/account',$accountReturn)) ?>"><?= pl_icon('arrow-left') ?> Back to account statement</a><?php endif; ?>
<?php if (!empty($ageingReturn)): ?><a class="btn btn-ghost my-3" href="<?= pl_e(pl_url('/reports/ageing',$ageingReturn)) ?>"><?= pl_icon('arrow-left') ?> Back to ageing report</a><?php endif; ?>
<?php require __DIR__ . '/views/' . $view . '.php'; ?>
<?php if ($workspace): ?></div></main></div></div><?php elseif ($posLayout): ?></main><?php else: ?></main><p class="text-xs text-ink-muted">PHP Ledger <?= pl_e(pl_app_version()) ?></p></div></div><?php endif; ?>
</body>
</html>
