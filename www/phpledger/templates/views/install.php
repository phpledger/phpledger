<?php declare(strict_types=1);
require_once __DIR__ . '/../partials/ui/components.php';
/*
 * Setup, laid out as one centred column (owner review of 25-26 September 2026, frames in
 * docs/design/setup-1.4.5). Every step fits a 1366x768 laptop without scrolling; the "?" beside
 * a title or a label opens its bubble right there; required fields carry an asterisk; and the
 * primary button turns green once the form is valid. Four named stages: Database, Build,
 * Account, Done. The old "Database connected / Install database" screen is gone: a passed
 * check goes straight into the build, and the private settings file is written without a
 * click wherever the server allows it. Nothing here uses a style attribute, because the page's
 * Content-Security-Policy forbids them.
 */
$headings = [
    'locked' => 'Installation is locked', 'blocked' => 'Finish preparing this website',
    'key' => 'Unlock setup', 'start' => 'This installs PHP Ledger on this server.',
    'challenge' => 'Checking this server', 'database' => 'Connect your database',
    'migrating' => 'Preparing your database', 'configuration' => 'Your host does not let PHP write this file',
    'account' => 'Create your sign-in account', 'ready' => 'Your installation is complete.',
];
// Four named stages; each view lives in one of them.
$tray = ['Database' => ['key', 'start', 'challenge', 'database'], 'Build' => ['migrating', 'configuration'], 'Account' => ['account'], 'Done' => ['ready']];
$field = static fn (string $name, string $default = ''): string => is_string($_POST[$name] ?? null) ? $_POST[$name] : $default;
$csrf = session_status() === PHP_SESSION_ACTIVE ? pl_csrf_token() : '';
$localHttp = $localHttp ?? false;
$insecureHttp = $insecureHttp ?? false;
$createdDatabase = $createdDatabase ?? '';
$exposureWarning = $exposureWarning ?? false;
$remoteProof = $remoteProof ?? false;
$setupCodePath = $setupCodePath ?? '';
$requirements = $requirements ?? [];
$databasePorts = $databasePorts ?? [];
$sharedDemo = pl_shared_demo_enabled();
$completion = $completion ?? [];
$signIn = $signIn ?? [];
$styleNonce = $styleNonce ?? '';
$reviewConfig = $_SESSION['install_database'] ?? [];
$tick = '<svg class="icon" viewBox="0 0 16 16" aria-hidden="true"><path d="M3.5 8.3l3.1 3.1L12.5 5" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"/></svg>';
// The handful of glyphs this page needs, inline so no request leaves the page and no icon file has to exist.
$glyph = static function (string $name): string {
    $paths = [
        'eye' => '<path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0"/><path d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6"/>',
        'eye-off' => '<path d="M10.585 10.587a2 2 0 0 0 2.829 2.828"/><path d="M16.681 16.673a8.717 8.717 0 0 1 -4.681 1.327c-3.6 0 -6.6 -2 -9 -6c1.272 -2.12 2.712 -3.678 4.32 -4.674m2.86 -1.146a9.055 9.055 0 0 1 1.82 -.18c3.6 0 6.6 2 9 6c-.666 1.11 -1.379 2.067 -2.138 2.87"/><path d="M3 3l18 18"/>',
        'info' => '<path d="M3 12a9 9 0 1 0 18 0a9 9 0 0 0 -18 0"/><path d="M12 9h.01"/><path d="M11 12h1v4h1"/>',
        'plus' => '<path d="M12 5l0 14"/><path d="M5 12l14 0"/>',
        'chevron' => '<path d="M6 9l6 6l6 -6"/>',
        'download' => '<path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2"/><path d="M7 11l5 5l5 -5"/><path d="M12 4l0 12"/>',
        'copy' => '<path d="M7 9.667a2.667 2.667 0 0 1 2.667 -2.667h8.666a2.667 2.667 0 0 1 2.667 2.667v8.666a2.667 2.667 0 0 1 -2.667 2.667h-8.666a2.667 2.667 0 0 1 -2.667 -2.667l0 -8.666"/><path d="M4.012 16.737a2.005 2.005 0 0 1 -1.012 -1.737v-10c0 -1.1 .9 -2 2 -2h10c.75 0 1.158 .385 1.5 1"/>',
        'check' => '<path d="M5 12l5 5l10 -10"/>',
    ];
    return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths[$name] . '</svg>';
};
$required = '<span class="required-marker" aria-hidden="true">*</span>';
$optional = '<span class="optional">' . pl_e(pl_t('(optional)')) . '</span>';
$statusClass = static fn (string $status): string => 'is-' . $status;
$summaryWording = static function (array $checks): array {
    $counts = pl_install_check_counts($checks);
    $summary = pl_install_check_summary($checks);
    if ($summary === PL_CHECK_PASS) {
        return [$summary, pl_t('all {count} passed', ['count' => $counts[PL_CHECK_PASS]])];
    }
    if ($counts[PL_CHECK_FAIL] > 0) {
        return [$summary, pl_t('{count} need attention, {passed} passed', ['count' => $counts[PL_CHECK_FAIL], 'passed' => $counts[PL_CHECK_PASS]])];
    }
    return [$summary, pl_t('{count} worth a look, {passed} passed', ['count' => $counts[PL_CHECK_WARN], 'passed' => $counts[PL_CHECK_PASS]])];
};
$blocking = array_values(array_filter($requirements, static fn (array $check): bool => $check['status'] === PL_CHECK_FAIL));
$stageIndex = 0;
foreach (array_values($tray) as $index => $views) {
    if (in_array($view, $views, true)) { $stageIndex = $index; }
}
$wide = in_array($view, ['start', 'challenge'], true);
?>
<!doctype html>
<html lang="<?= pl_e(pl_locale()) ?>" dir="<?= pl_e(pl_text_direction()) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light">
<title><?= pl_e(pl_t('{heading} · PHP Ledger', ['heading' => $view === 'start' ? pl_t('Install PHP Ledger') : pl_t($headings[$view])])) ?></title>
<link rel="stylesheet" href="<?= pl_e(pl_url('/assets/app.css', ['v' => pl_app_version()])) ?>">
<?php if ($view === 'migrating'): $progress = pl_install_progress($schema ?? []); ?>
<style nonce="<?= pl_e($styleNonce) ?>">#install-progress-bar{width:<?= (int) $progress['percent'] ?>%}</style>
<?php endif; ?>
<script src="<?= pl_e(pl_url('/assets/help.js')) ?>" defer></script>
<script src="<?= pl_e(pl_url('/assets/install.js', ['v' => pl_app_version()])) ?>" defer></script>
</head><body><main class="install-frame wp-frame"><div class="wp-shell<?= $wide ? ' is-wide' : '' ?>">
<div class="bench-chrome wp-chrome">
<img class="bench-logo" src="<?= pl_e(pl_url('/assets/brand/phpledger-horizontal.png')) ?>" alt="<?= pl_e(pl_t('PHP Ledger')) ?>" width="2172" height="724">
<?php if (!in_array($view, ['locked', 'blocked'], true)): ?>
<ol class="bench-tray is-labelled" aria-label="<?= pl_e(pl_t('Installation stages')) ?>">
<?php foreach (array_keys($tray) as $index => $name): $done = $view === 'ready' || $index < $stageIndex; $current = $view !== 'ready' && $index === $stageIndex; ?>
<li class="tray-slot <?= $current ? 'is-current' : ($done ? 'is-done' : 'is-pending') ?>"<?= $current ? ' aria-current="step"' : '' ?>><?= $done ? $tick : '' ?><span><?= pl_e(pl_t($name)) ?></span></li>
<?php endforeach; ?>
</ol>
<span class="wp-step"><?= pl_e($view === 'ready' ? pl_t('Done') : pl_t('Step {step} of {total}', ['step' => $stageIndex + 1, 'total' => count($tray)])) ?></span>
<?php endif; ?>
</div>
<?php if ($error !== ''): ?><div class="alert alert-danger" role="alert" tabindex="-1"><p><?= pl_e($error) ?></p></div><?php endif; ?>
<?php if ($sharedDemo): ?><div class="alert alert-info" role="note"><p><?= pl_e(pl_t('Shared public demo: complete the real installer with protected database settings and a fixed application login. Everyone shares the results until the hourly reset. Use fictional information.')) ?></p></div><?php endif; ?>
<?php if ($insecureHttp && !in_array($view, ['locked', 'blocked'], true)): ?><div class="alert alert-warning" role="note"><p><strong><?= pl_e(pl_t('This address is not using HTTPS.')) ?></strong> <?= pl_e(pl_t('What you type here travels unencrypted. Setup continues so that you can try PHP Ledger; before you keep real books, turn on SSL in your hosting panel and open the site again with https://.')) ?></p></div><?php endif; ?>
<?php $exposureLink = $exposureWarning && !in_array($view, ['locked', 'blocked'], true) ? pl_install_exposure_check_link() : null; ?>
<?php if ($exposureLink !== null): ?><div class="alert alert-warning" role="note"><p><?= pl_e(pl_t('Setup could not confirm that PHP Ledger\'s private folders are hidden. Open')) ?> <a href="<?= pl_e($exposureLink) ?>" target="_blank" rel="noopener"><?= pl_e(pl_t('this check link')) ?></a> <?= pl_e(pl_t('in a new tab. It should show “Not Found” or “Forbidden”. If you can read a message instead, point the website\'s document root at')) ?> <code>www/phpledger/public</code>.</p></div><?php endif; ?>
<section class="wp-card">

<?php if ($view === 'locked'): ?>
<div class="bench-head wp-head"><div class="wp-title-row"><h1 class="bench-heading size-lg"><?= pl_e(pl_t('Installation is locked')) ?></h1></div><p class="bench-sub"><?= pl_e(pl_t('This copy is already installed, so browser setup is closed. Nothing was changed.')) ?></p></div>
<div class="bench-body"><div><a class="btn btn-primary" href="<?= pl_e(pl_url('/login')) ?>"><?= pl_e(pl_t('Go to sign in')) ?></a></div></div>

<?php elseif ($view === 'blocked'): ?>
<div class="bench-head wp-head"><div class="wp-title-row"><h1 class="bench-heading size-lg"><?= pl_e(pl_t('Finish preparing this website')) ?></h1></div><p class="bench-sub"><?= pl_e(pl_t('Setup cannot continue yet. Follow the message above, then reload this page. Nothing was saved.')) ?></p></div>

<?php elseif ($view === 'key'): ?>
<div class="bench-head wp-head"><div class="wp-title-row"><h1 class="bench-heading"><?= pl_e(pl_t('Unlock setup')) ?></h1><?php pl_ui_page_help('install', 'start'); ?></div><p class="bench-sub"><?= pl_e(pl_t('The key is in your private installation directory. It proves you control this hosting account. Never put it in a public folder or a web address.')) ?></p></div>
<div class="bench-body">
<form class="wp-grid" id="unlock-form" method="post" action="<?= pl_e(pl_url('/install')) ?>" data-ready-button="#unlock"><input type="hidden" name="csrf_token" value="<?= pl_e($csrf) ?>"><input type="hidden" name="action" value="unlock">
<div class="field span-12"><div class="inline-heading"><label class="field-label" for="setup-key"><?= pl_e(pl_t('Private setup key')) ?> <?= $required ?></label></div><input class="input" id="setup-key" name="setup_key" type="password" required autocomplete="off" maxlength="256"></div>
</form></div>
<div class="bench-actions"><span class="fine-print"><?= pl_e(pl_t('Nothing is saved by this step.')) ?></span><button class="btn btn-primary" id="unlock" type="submit" form="unlock-form"><?= pl_e(pl_t('Unlock setup')) ?></button></div>

<?php elseif ($view === 'challenge'): $challenge = $blocking[0]; ?>
<div class="bench-head wp-head"><div class="wp-title-row"><h1 class="bench-heading"><?= pl_e(pl_t($headings['challenge'])) ?></h1><?php pl_ui_page_help('install', 'start'); ?></div>
<p class="bench-sub"><?= pl_e(pl_t('{done} parts are already in place.', ['done' => (int) (count($requirements) - count($blocking))])) ?> <?= pl_e(pl_tn('One needs a fix before setup can continue.', '{count} need a fix before setup can continue.', count($blocking), ['count' => count($blocking)])) ?></p></div>
<div class="bench-body"><div class="bench-challenge">
<div class="rail">
<?php [$summary, $wording] = $summaryWording($requirements); ?>
<span class="status-pill<?= $summary === PL_CHECK_PASS ? '' : ' has-issue' ?>"><?= pl_e($wording) ?></span>
<ul class="rail-list">
<?php foreach ($requirements as $check): ?>
<li class="rail-row <?= pl_e($statusClass($check['status'])) ?>"><span class="led"></span><span class="rail-name"><?= pl_e($check['name']) ?></span></li>
<?php endforeach; ?>
</ul>
</div>
<div class="challenge-card">
<h2 class="challenge-title"><?= pl_e(pl_t('{name} needs attention', ['name' => $challenge['name']])) ?></h2>
<p class="challenge-detail"><?= pl_e($challenge['name']) ?>: <?= pl_e($challenge['detail']) ?>.</p>
<?php if ($challenge['fixes'] !== []): ?>
<p class="fix-head"><?= pl_e(pl_t('Ways to fix this')) ?></p>
<ol class="fix-list">
<?php foreach ($challenge['fixes'] as $index => $fix): ?>
<li class="fix-item"><span class="fix-num" aria-hidden="true"><?= $index + 1 ?></span><span><?= pl_e($fix) ?></span></li>
<?php endforeach; ?>
</ol>
<?php endif; ?>
<form class="recheck-row" method="post" action="<?= pl_e(pl_url('/install')) ?>"><input type="hidden" name="csrf_token" value="<?= pl_e($csrf) ?>"><input type="hidden" name="action" value="recheck">
<button class="btn btn-primary" type="submit"><?= pl_e(pl_t('Check again')) ?></button><span class="recheck-note"><?= pl_e(pl_t('Nothing has been saved. Clear this, then check again.')) ?></span></form>
</div>
</div></div>

<?php elseif ($view === 'start'): [$summary, $wording] = $summaryWording($requirements); ?>
<div class="bench-head wp-head"><div class="wp-title-row"><h1 class="bench-heading size-lg"><?= pl_e(pl_t($headings['start'])) ?></h1><?php pl_ui_page_help('install', 'start'); ?></div>
<p class="bench-sub"><?= pl_e(pl_t('A private installer builds your ledger\'s structure and protections, then hands you the keys. No company and no transaction is created yet.')) ?></p></div>
<div class="bench-body"><div class="bench-split">
<div class="bench-stats">
<p class="stat-row"><span class="stat-num"><?= (int) count(pl_install_migration_versions()) ?></span><span class="stat-label"><?= pl_e(pl_t('build steps for your ledger')) ?></span></p>
<p class="stat-row"><span class="stat-num">1</span><span class="stat-label"><?= pl_e(pl_t('sign-in account you create')) ?></span></p>
<p class="stat-row"><span class="stat-num">0</span><span class="stat-label"><?= pl_e(pl_t('transactions, until you record one')) ?></span></p>
<p class="bench-sub is-small"><?= pl_e(pl_t('Each one is checked on your own server before it counts, not assumed.')) ?></p>
</div>
<div>
<div class="checks-head"><h2><?= pl_e(pl_t('Checking this server')) ?></h2><span class="status-pill<?= $summary === PL_CHECK_PASS ? '' : ' has-issue' ?>"><?= pl_e($wording) ?></span></div>
<ul class="tile-grid">
<?php foreach ($requirements as $index => $check): ?>
<li class="tile <?= pl_e($statusClass($check['status'])) ?> reveal"><span class="led"></span><span class="tile-name"><?= pl_e($check['name']) ?></span><span class="tile-detail"><?= pl_e($check['detail']) ?></span></li>
<?php endforeach; ?>
</ul>
</div>
</div></div>
<div class="bench-actions"><span class="fine-print"><?= pl_e(pl_t('Takes about two minutes.')) ?></span>
<form method="post" action="<?= pl_e(pl_url('/install')) ?>"><input type="hidden" name="csrf_token" value="<?= pl_e($csrf) ?>"><input type="hidden" name="action" value="start">
<button class="btn btn-primary" type="submit"><?= pl_e(pl_t('Start setup')) ?></button></form></div>

<?php elseif ($view === 'database'): ?>
<div class="bench-head wp-head"><div class="wp-title-row"><h1 class="bench-heading"><?= pl_e(pl_t($headings['database'])) ?></h1><?php pl_ui_page_help('install', 'start'); ?></div>
<p class="bench-sub"><?= pl_e(pl_t('Every transaction is stored here. Nothing is written until the check passes.')) ?></p></div>
<div class="bench-body">
<?php if ($sharedDemo): ?>
<p class="wp-note"><?= $glyph('info') ?><span><?= pl_e(pl_t('These are example values for the demo. The real database settings stay private and cannot be changed here. Check and install runs the normal connection checks using the server settings.')) ?></span></p>
<?php else: ?>
<p class="wp-note"><?= $glyph('info') ?><span><strong><?= pl_e(pl_t('Setting up on your own computer?')) ?></strong> <?= pl_e(pl_t('XAMPP, Laragon and MAMP create a')) ?> <span class="snip">root</span> <?= pl_e(pl_t('account with no password. Leave the password empty and setup does the rest, including creating the database itself.')) ?><?php if ($databasePorts !== []): ?> <?= pl_e(pl_t('A database server answered on this computer at port {ports}.', ['ports' => implode(', ', array_map('strval', $databasePorts))])) ?><?php endif; ?></span></p>
<?php endif; ?>
<form class="wp-grid" id="database-form" method="post" action="<?= pl_e(pl_url('/install')) ?>" data-ready-button="#check-db"><input type="hidden" name="csrf_token" value="<?= pl_e($csrf) ?>"><input type="hidden" name="action" value="database">
<?php if ($remoteProof): ?><div class="field span-12"><div class="inline-heading"><label class="field-label" for="setup-code"><?= pl_e(pl_t('Setup code from {file}', ['file' => basename($setupCodePath)])) ?> <?= $required ?></label></div><input class="input" id="setup-code" name="setup_code" type="password" required autocomplete="off" maxlength="256" aria-describedby="setup-code-hint"><p class="field-hint" id="setup-code-hint"><?= pl_e(pl_t('Your database is on another server, so setup asks for this one-time code. Open')) ?> <span class="snip"><?= pl_e($setupCodePath) ?></span> <?= pl_e(pl_t('with your hosting file manager and copy the code inside.')) ?></p></div><?php endif; ?>
<?php if ($sharedDemo): ?>
<div class="field span-12"><div class="inline-heading"><label class="field-label" for="public-url"><?= pl_e(pl_t('This site\'s address')) ?></label></div><input class="input" id="public-url" name="public_url" type="url" value="<?= pl_e(pl_shared_demo_public_url()) ?>" readonly></div>
<div class="field span-6"><div class="inline-heading"><label class="field-label" for="db-host"><?= pl_e(pl_t('Database host')) ?></label></div><input class="input" id="db-host" name="host" value="db.example.invalid" readonly autocomplete="off"></div>
<div class="field span-3"><div class="inline-heading"><label class="field-label" for="db-port"><?= pl_e(pl_t('Port')) ?></label></div><input class="input" id="db-port" name="port" value="3306" readonly></div>
<div class="field span-3"><div class="inline-heading"><label class="field-label" for="db-name"><?= pl_e(pl_t('Database name')) ?></label></div><input class="input" id="db-name" name="database" value="example_ledger" readonly autocomplete="off"></div>
<div class="field span-6"><div class="inline-heading"><label class="field-label" for="db-user"><?= pl_e(pl_t('Database user')) ?></label></div><input class="input" id="db-user" name="user" value="example_user" readonly autocomplete="off"></div>
<div class="field span-6"><div class="inline-heading"><label class="field-label" for="db-password"><?= pl_e(pl_t('Database password')) ?></label></div><input class="input" id="db-password" name="password" type="password" value="example-password" readonly autocomplete="off"></div>
<?php else: ?>
<div class="field span-12"><div class="inline-heading"><label class="field-label" for="public-url"><?= pl_e(pl_t('This site\'s address')) ?> <?= $required ?></label><?php pl_ui_help('install-site-address', 'start'); ?></div><input class="input" id="public-url" name="public_url" type="url" value="<?= pl_e($field('public_url', (string) (getenv('PL_PUBLIC_URL') ?: pl_install_suggested_public_url($_SERVER)))) ?>" placeholder="https://books.example.com" required maxlength="480" autocomplete="url"></div>
<div class="field span-6"><div class="inline-heading"><label class="field-label" for="db-host"><?= pl_e(pl_t('Database host')) ?> <?= $required ?></label><?php pl_ui_help('install-db-host', 'start'); ?></div><input class="input" id="db-host" name="host" value="<?= pl_e($field('host', 'localhost')) ?>" required maxlength="253" autocomplete="off"></div>
<div class="field span-3"><div class="inline-heading"><label class="field-label" for="db-port"><?= pl_e(pl_t('Port')) ?> <?= $required ?></label><?php pl_ui_help('install-db-port', 'start'); ?></div><input class="input" id="db-port" name="port" value="<?= pl_e($field('port', (string) ($databasePorts[0] ?? 3306))) ?>" required inputmode="numeric" pattern="[0-9]+"></div>
<?php if (getenv('PL_ENV') !== 'demo-install'): ?>
<div class="field span-3"><div class="inline-heading"><label class="field-label" for="db-prefix"><?= pl_e(pl_t('Table prefix')) ?> <?= $required ?></label><?php pl_ui_help('install-db-prefix', 'end'); ?></div><input class="input" id="db-prefix" name="db_prefix" value="<?= pl_e($field('db_prefix', (string) (getenv('PL_DB_PREFIX') ?: 'pl_'))) ?>" required pattern="[a-z][a-z0-9_]{0,15}_" maxlength="17"></div>
<?php else: ?><div class="span-3"></div><?php endif; ?>
<div class="field span-6"><div class="inline-heading"><label class="field-label" for="db-name"><?= pl_e(pl_t('Database name')) ?> <?= $required ?></label><?php pl_ui_help('install-db-name', 'start'); ?></div><input class="input" id="db-name" name="database" value="<?= pl_e($field('database')) ?>" required maxlength="64" autocomplete="off"></div>
<div class="field span-3"><div class="inline-heading"><label class="field-label" for="db-user"><?= pl_e(pl_t('Database user')) ?> <?= $required ?></label><?php pl_ui_help('install-db-user', 'start'); ?></div><input class="input" id="db-user" name="user" value="<?= pl_e($field('user')) ?>" required maxlength="128" autocomplete="off"></div>
<div class="field span-3"><div class="inline-heading"><label class="field-label" for="db-password"><?= pl_e(pl_t('Database password')) ?></label><?php pl_ui_help('install-db-password', 'end'); ?></div><div class="input-affix"><input class="input" id="db-password" name="password" type="password" autocomplete="new-password" maxlength="1024" placeholder="<?= pl_e(pl_t('Leave empty on this computer')) ?>"><button type="button" class="affix-btn" data-toggle-password="#db-password" aria-label="<?= pl_e(pl_t('Show password')) ?>"><?= $glyph('eye') ?><span hidden><?= $glyph('eye-off') ?></span></button></div></div>
<?php if (getenv('PL_ENV') !== 'demo-install'): ?>
<details class="wp-details span-12"><summary><?= $glyph('chevron') ?><?= pl_e(pl_t('Advanced: a database on another server')) ?><span class="optional"><?= pl_e(pl_t('optional')) ?></span></summary>
<div class="wp-details-body"><div class="wp-grid">
<div class="field span-12"><div class="inline-heading"><label class="field-label" for="db-ca"><?= pl_e(pl_t('TLS CA certificate path')) ?></label><?php pl_ui_help('install-db-tls', 'start'); ?></div><input class="input" id="db-ca" name="db_ssl_ca" value="<?= pl_e($field('db_ssl_ca')) ?>" autocomplete="off"><p class="field-hint"><?= pl_e(pl_t('Leave empty for a database on this computer or on the same hosting account. Server identity verification always stays on.')) ?></p></div>
</div></div></details>
<?php endif; ?>
<?php endif; ?>
</form>
</div>
<div class="bench-actions"><span class="fine-print"><?= pl_e(pl_t('MySQL 8.0+ or MariaDB 10.4+. No terminal needed.')) ?><?php if ($requirements !== []): [$summary, $wording] = $summaryWording($requirements); ?> <?= pl_e(pl_t('Server checks: {status}.', ['status' => $wording])) ?><?php endif; ?></span>
<button class="btn btn-primary" id="check-db" type="submit" form="database-form"><?= pl_e(pl_t('Check and install')) ?></button></div>

<?php elseif ($view === 'migrating'): $signInUser = (string) ($reviewConfig['user'] ?? ''); $noPassword = (string) ($reviewConfig['password'] ?? '') === ''; ?>
<div class="bench-head wp-head"><div class="wp-title-row"><h1 class="bench-heading"><?= pl_e(pl_t($headings['migrating'])) ?></h1><?php pl_ui_page_help('install', 'start'); ?></div></div>
<div class="bench-body">
<div class="build-fact"><span class="reward-badge"><?= $tick ?><?= pl_e(pl_t('Connected')) ?></span>
<?php if ($sharedDemo): ?><span class="muted"><?= pl_e(pl_t('The shared demonstration database is connected and ready.')) ?></span>
<?php else: ?><span><span class="snip"><?= pl_e((string) ($reviewConfig['database'] ?? '')) ?></span> <?= pl_e(pl_t('on')) ?> <span class="snip"><?= pl_e((string) ($reviewConfig['host'] ?? '') . ':' . (int) ($reviewConfig['port'] ?? 3306)) ?></span></span>
<span class="muted"><?= $createdDatabase !== '' ? pl_e(pl_t('· created just now, because it did not exist yet')) . ' ' : '' ?><?= $noPassword || strtolower($signInUser) === 'root' ? pl_e($noPassword ? pl_t('· signing in as {user} with no password, which is normal on your own computer', ['user' => $signInUser]) : pl_t('· signing in as {user}', ['user' => $signInUser])) : '' ?></span>
<?php endif; ?></div>
<div class="progress-wrap">
<div>
<p class="install-progress-status" role="status"><span class="install-progress-step"><?= pl_e($progress['label']) ?></span><span class="install-progress-count"><?= pl_e(pl_t('Step {done} of {total} · {percent}%', ['done' => (int) min($progress['done'] + 1, $progress['total']), 'total' => (int) $progress['total'], 'percent' => (int) $progress['percent']])) ?></span></p>
<div class="install-progress-track"><div class="install-progress-bar" id="install-progress-bar"></div></div>
</div>
<ul class="pin-strip reveal">
<?php $versions = pl_install_migration_versions(); $placed = (int) $progress['done']; ?>
<?php foreach (array_chunk($versions, 7) as $clusterIndex => $cluster): ?>
<li class="pin-cluster"><?php foreach ($cluster as $pinIndex => $version): $absolute = $clusterIndex * 7 + $pinIndex; ?><span class="pin <?= $absolute < $placed ? 'is-done' : ($absolute === $placed ? 'is-current' : '') ?>"></span><?php endforeach; ?></li>
<?php endforeach; ?>
</ul>
<?php $justPlaced = array_slice(array_slice($versions, 0, $placed), -3); ?>
<?php if ($justPlaced !== []): ?>
<div><p class="field-hint"><?= pl_e(pl_t('Just placed')) ?></p>
<ul class="placed-row"><?php foreach ($justPlaced as $version): ?><li class="placed-chip"><?= $tick ?><?= pl_e(pl_install_step_label($version)) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
</div></div>
<div class="bench-actions"><span class="fine-print"><?= pl_e(pl_t('Continuing automatically, nothing to click. If the connection stops, reopen this page and enter the same database details: an interrupted step resumes where it stopped.')) ?></span>
<form method="post" action="<?= pl_e(pl_url('/install')) ?>"<?= $error === '' ? ' data-install-continue' : '' ?>><input type="hidden" name="csrf_token" value="<?= pl_e($csrf) ?>"><input type="hidden" name="action" value="migrate">
<button class="btn btn-primary" type="submit"<?= $error === '' ? ' data-install-auto' : '' ?>><?= pl_e(pl_t('Continue installation')) ?></button></form></div>
<noscript><p class="field-hint"><?= pl_e(pl_t('JavaScript is off, so this does not continue by itself. Select Continue installation until every step is complete.')) ?></p></noscript>

<?php elseif ($view === 'configuration'): $kind = pl_install_environment_kind(); $facts = []; foreach (pl_install_environment($_SERVER, $databasePorts) as $fact) { $facts[$fact['name']] = $fact['value']; } $configPath = pl_install_display_path(pl_install_config_path()); ?>
<div class="bench-head wp-head"><div class="wp-title-row"><h1 class="bench-heading"><?= pl_e(pl_t($headings['configuration'])) ?></h1><?php pl_ui_help('install-settings-file', 'start'); ?></div>
<p class="bench-sub"><?= pl_e(pl_t('Setup keeps its settings in a private file outside the folder visitors can reach. This server refused to create it, so you place it once. Nothing else changes.')) ?></p></div>
<div class="bench-body">
<dl class="env-facts">
<div><dt><?= pl_e(pl_t('This server')) ?></dt><dd><?= pl_e((string) ($facts['PHP'] ?? PHP_VERSION)) ?><?= isset($facts['Web server']) ? ' · ' . pl_e((string) $facts['Web server']) : '' ?></dd></div>
<div><dt><?= pl_e(pl_t('Private settings file')) ?></dt><dd><span class="snip"><?= pl_e($configPath) ?></span></dd></div>
<div><dt><?= pl_e(pl_t('Folder served to visitors')) ?></dt><dd><span class="snip"><?= pl_e((string) ($facts['Folder served to visitors'] ?? 'www/phpledger/public')) ?></span></dd></div>
</dl>
<p class="fix-head"><?= pl_e(pl_t('Place the file, then check again')) ?></p>
<ol class="fix-list">
<?php $steps = match ($kind) {
    'windows' => [
        pl_t('Choose Download private configuration below.'),
        pl_t('Save it as {path}. If Windows added .txt to the name, remove it.', ['path' => $configPath]),
        pl_t('If Windows refuses, right-click the includes folder, open Properties, then Security, and allow your user to write there; or start your local stack as your own user.'),
        pl_t('Choose I have placed it, check again.'),
    ],
    'container' => [
        pl_t('Setup writes this file into the private data directory ({path}). The container could not, so that directory is read-only or owned by another user.', ['path' => $configPath]),
        pl_t('Give the directory back to the web server user, for example with docker compose exec followed by chown for www-data, then check again.'),
        pl_t('Or choose Download private configuration and copy the file into the container with docker cp.'),
        pl_t('Choose I have placed it, check again.'),
    ],
    default => [
        pl_t('Choose Download private configuration below. The file is named config.local.php and is complete.'),
        pl_t('Open File Manager in cPanel or Plesk, go to the includes folder of PHP Ledger and upload it there as {path}. Never put it inside public/.', ['path' => $configPath]),
        pl_t('Right-click the file, choose Change permissions and set 600, so only the owner may read and write it.'),
        pl_t('Delete the downloaded copy from your computer, then choose I have placed it, check again.'),
    ],
}; ?>
<?php foreach ($steps as $index => $step): ?><li class="fix-item"><span class="fix-num" aria-hidden="true"><?= $index + 1 ?></span><span><?= pl_e($step) ?></span></li><?php endforeach; ?>
</ol>
</div>
<div class="bench-actions">
<?php if (!$sharedDemo): ?><form method="post" action="<?= pl_e(pl_url('/install')) ?>"><input type="hidden" name="csrf_token" value="<?= pl_e($csrf) ?>"><input type="hidden" name="public_url" value="<?= pl_e((string) ($_SESSION['install_runtime']['public_url'] ?? '')) ?>"><button class="btn btn-secondary" name="action" value="download_config" type="submit"><?= $glyph('download') ?> <?= pl_e(pl_t('Download private configuration')) ?></button></form><?php else: ?><span></span><?php endif; ?>
<form method="post" action="<?= pl_e(pl_url('/install')) ?>"><input type="hidden" name="csrf_token" value="<?= pl_e($csrf) ?>"><input type="hidden" name="public_url" value="<?= pl_e((string) ($_SESSION['install_runtime']['public_url'] ?? '')) ?>"><button class="btn btn-primary" name="action" value="save_config" type="submit"><?= pl_e(pl_t('I have placed it, check again')) ?></button></form></div>

<?php elseif ($view === 'account'): $selfChecks = pl_install_self_checks($schema ?? []); [$summary, $wording] = $summaryWording($selfChecks); ?>
<div class="bench-head wp-head"><div class="wp-title-row"><h1 class="bench-heading"><?= pl_e(pl_t($headings['account'])) ?></h1><?php pl_ui_page_help('install', 'start'); ?></div><p class="bench-sub"><?= pl_e(pl_t('The account that owns this installation. Your first business comes next.')) ?></p></div>
<div class="bench-body">
<span class="recap-pill"><?= $tick ?><?= pl_e(pl_t('Database built and checked: {status}. Private settings saved outside the public folder.', ['status' => $wording])) ?></span>
<form class="wp-grid" id="account-form" method="post" action="<?= pl_e(pl_url('/install')) ?>" enctype="multipart/form-data" data-ready-button="#finish"><input type="hidden" name="csrf_token" value="<?= pl_e($csrf) ?>"><input type="hidden" name="action" value="finish">
<?php if ($sharedDemo): $demoAccount = pl_shared_demo_account(); ?>
<div class="alert alert-info span-12" role="note">
<p><?= pl_e(pl_t('Create the shared demo account. Its sign-in stays fixed so everyone can use this installation.')) ?></p>
<p><?= pl_e(pl_t('Username')) ?>: <code><?= pl_e($demoAccount['username']) ?></code><br>
<?= pl_e(pl_t('Password')) ?>: <code><?= pl_e($demoAccount['password']) ?></code></p>
</div>
<?php else: ?>
<div class="field span-6"><div class="inline-heading"><label class="field-label" for="owner-name"><?= pl_e(pl_t('Your name')) ?> <?= $required ?></label></div><input class="input" id="owner-name" name="name" value="<?= pl_e($field('name')) ?>" required maxlength="120" autocomplete="name"></div>
<div class="field span-6"><div class="inline-heading"><label class="field-label" for="owner-username"><?= pl_e(pl_t('Username')) ?> <?= $required ?></label><?php pl_ui_help('install-username', 'end'); ?></div><input class="input" id="owner-username" name="username" value="<?= pl_e($field('username', (string) ($state['owner_username'] ?? ''))) ?>" required minlength="3" maxlength="60" autocomplete="username" autocapitalize="none" spellcheck="false"></div>
<div class="field span-12"><div class="inline-heading"><label class="field-label" for="owner-email"><?= pl_e(pl_t('Email address')) ?> <?= $required ?></label><?php pl_ui_help('install-email', 'start'); ?></div><input class="input" id="owner-email" name="email" type="email" value="<?= pl_e($field('email', (string) ($state['owner_email'] ?? ''))) ?>" required maxlength="254" autocomplete="email"></div>
<div class="field span-6"><div class="inline-heading"><label class="field-label" for="owner-password"><?= pl_e(pl_t('Password')) ?> <?= $required ?></label><?php pl_ui_help('install-password', 'start'); ?></div><div class="input-affix"><input class="input" id="owner-password" name="password" type="password" required minlength="6" maxlength="72" autocomplete="new-password"><button type="button" class="affix-btn" data-toggle-password="#owner-password" aria-label="<?= pl_e(pl_t('Show password')) ?>"><?= $glyph('eye') ?><span hidden><?= $glyph('eye-off') ?></span></button></div>
<div class="pw-row"><span class="pw-strength" data-strength-for="#owner-password"><span class="meter"><span class="meter-fill"></span></span><span data-strength-label><?= pl_e(pl_t('At least 6 characters')) ?></span></span><button type="button" class="btn btn-secondary btn-sm" data-generate-password="#owner-password, #owner-password-confirm"><?= pl_e(pl_t('Generate a password')) ?></button></div></div>
<div class="field span-6"><div class="inline-heading"><label class="field-label" for="owner-password-confirm"><?= pl_e(pl_t('Type it again')) ?> <?= $required ?></label></div><div class="input-affix"><input class="input" id="owner-password-confirm" name="password_confirm" type="password" required minlength="6" maxlength="72" autocomplete="new-password"><button type="button" class="affix-btn" data-toggle-password="#owner-password-confirm" aria-label="<?= pl_e(pl_t('Show password')) ?>"><?= $glyph('eye') ?><span hidden><?= $glyph('eye-off') ?></span></button></div></div>
<?php endif; ?>
<div class="field span-12"><div class="inline-heading"><label class="field-label" for="owner-logo"><?= pl_e(pl_t('Your logo')) ?> <?= $optional ?></label><?php pl_ui_help('install-logo', 'start'); ?></div>
<div class="dropzone" data-dropzone><span class="dropzone-preview" data-dropzone-preview><?= $glyph('plus') ?></span><span class="dropzone-text" data-dropzone-text><strong><?= pl_e(pl_t('Drop a PNG, JPEG or WebP here')) ?></strong> · <?= pl_e(pl_t('up to 1 MB · a preview appears before you continue')) ?></span><input class="sr-only" id="owner-logo" name="logo" type="file" accept="image/png,image/jpeg,image/webp"><label class="btn btn-secondary btn-sm" for="owner-logo"><?= pl_e(pl_t('Choose file')) ?></label></div></div>
<?php if (!$sharedDemo): ?>
<p class="wp-note is-plain span-12"><?= $glyph('info') ?><span><?= pl_e(pl_t('This copy tells phpledger.com it was installed: the version, PHP and database engine and the operating system family. Nothing about your books, your users or your address.')) ?> <a class="link" href="https://phpledger.com/privacy/" target="_blank" rel="noopener noreferrer"><?= pl_e(pl_t('Privacy details')) ?></a></span></p>
<?php endif; ?>
</form>
</div>
<div class="bench-actions"><span class="fine-print"><?= pl_e(pl_t('You will sign in with your username or your email.')) ?></span>
<button class="btn btn-primary" id="finish" type="submit" form="account-form"><?= pl_e(pl_t('Finish and create your business')) ?></button></div>

<?php elseif ($view === 'ready'): ?>
<div class="bench-head wp-head"><div class="wp-title-row"><h1 class="bench-heading size-lg"><?= pl_e(pl_t($headings['ready'])) ?></h1></div><p class="bench-sub"><?= pl_e(pl_t('Note the sign-in details on the right. Next, set up your first business.')) ?></p></div>
<div class="bench-body"><div class="done-split">
<ul class="manifest-card reveal">
<?php foreach ($completion as $line): ?>
<li class="manifest-row"><?= $tick ?><?= pl_e($line) ?></li>
<?php endforeach; ?>
</ul>
<div class="signin-card" data-signin-card>
<h2><?= pl_e(pl_t('Your sign-in details, to note down')) ?></h2>
<dl class="signin-grid">
<?php if (($signIn['url'] ?? '') !== ''): ?><dt><?= pl_e(pl_t('Sign-in address')) ?></dt><dd data-signin="url"><?= pl_e((string) $signIn['url']) ?></dd><?php endif; ?>
<?php if (($signIn['username'] ?? '') !== ''): ?><dt><?= pl_e(pl_t('Username')) ?></dt><dd data-signin="username"><?= pl_e((string) $signIn['username']) ?></dd><?php endif; ?>
<?php if (($signIn['email'] ?? '') !== ''): ?><dt><?= pl_e(pl_t('Email')) ?></dt><dd data-signin="email"><?= pl_e((string) $signIn['email']) ?></dd><?php endif; ?>
<dt><?= pl_e(pl_t('Password')) ?></dt><dd><?= pl_e(pl_t('the one you just chose; it is never shown again')) ?></dd>
</dl>
<div class="signin-actions"><button type="button" class="btn btn-secondary btn-sm" data-copy-signin><?= $glyph('copy') ?> <?= pl_e(pl_t('Copy these details')) ?></button></div>
<p class="keep-safe"><?= pl_e(pl_t('Also keep safe:')) ?> <span class="snip"><?= pl_e(pl_install_display_path(pl_install_directory() . '/operator.key')) ?></span> <?= pl_e(pl_t('is the maintenance key for updates and recovery. It is not your password and it lives outside the public folder. Back it up with your database.')) ?></p>
</div>
</div></div>
<div class="bench-actions"><span class="fine-print"><?= pl_e(pl_t('Browser setup is now closed. Everything else happens inside PHP Ledger.')) ?></span><a class="btn btn-primary" href="<?= pl_e(pl_url('/onboarding')) ?>"><?= pl_e(pl_t('Set up your first business')) ?></a></div>
<?php endif; ?>
</section>
</div></main></body></html>
