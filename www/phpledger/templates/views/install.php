<?php declare(strict_types=1);
/*
 * Setup, laid out as the Workbench (owner decision, 20 September 2026, direction B
 * in docs/design/installer-2026-09). One task per screen, sized for a laptop without
 * scrolling; a failed check takes over the screen as something to clear, with the
 * places it is actually fixed; and each cleared stage states one specific fact about
 * this server rather than congratulating the owner.
 */
$headings = [
    'locked' => 'Installation is locked', 'blocked' => 'Finish preparing this website',
    'key' => 'Unlock setup', 'start' => 'This installs PHP Ledger on this server.',
    'challenge' => 'Checking this server', 'database' => 'Connect your database',
    'review' => 'Database connected.', 'migrating' => 'Preparing your database',
    'configuration' => 'Database checks', 'account' => 'Create your sign-in account',
    'ready' => 'Your installation is complete.',
];
// Six compartments; each view fills one of them.
$tray = ['Start' => ['key', 'start', 'challenge'], 'Database' => ['database'], 'Build' => ['review', 'migrating'],
    'Checks' => ['configuration'], 'Account' => ['account'], 'Ready' => ['ready']];
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
$completion = $completion ?? [];
$styleNonce = $styleNonce ?? '';
$reviewConfig = $_SESSION['install_database'] ?? [];
$tick = '<svg class="icon" viewBox="0 0 16 16" aria-hidden="true"><path d="M3.5 8.3l3.1 3.1L12.5 5" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"/></svg>';
$statusClass = static fn (string $status): string => 'is-' . $status;
$summaryWording = static function (array $checks): array {
    $counts = pl_install_check_counts($checks);
    $summary = pl_install_check_summary($checks);
    return [$summary, $summary === PL_CHECK_PASS
        ? 'all ' . $counts[PL_CHECK_PASS] . ' passed'
        : ($counts[PL_CHECK_FAIL] > 0 ? $counts[PL_CHECK_FAIL] . ' need attention' : $counts[PL_CHECK_WARN] . ' worth a look')
            . ', ' . $counts[PL_CHECK_PASS] . ' passed'];
};
$blocking = array_values(array_filter($requirements, static fn (array $check): bool => $check['status'] === PL_CHECK_FAIL));
?>
<!doctype html>
<html lang="<?= pl_e(pl_locale()) ?>" dir="<?= pl_e(pl_text_direction()) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light">
<title><?= pl_e($view === 'start' ? 'Install PHP Ledger' : $headings[$view]) ?> · PHP Ledger</title>
<link rel="stylesheet" href="<?= pl_e(pl_url('/assets/app.css', ['v' => pl_app_version()])) ?>">
<?php if ($view === 'migrating'): $progress = pl_install_progress($schema ?? []); ?>
<style nonce="<?= pl_e($styleNonce) ?>">#install-progress-bar{width:<?= (int) $progress['percent'] ?>%}</style>
<?php endif; ?>
<script src="<?= pl_e(pl_url('/assets/install.js', ['v' => pl_app_version()])) ?>" defer></script>
</head><body><main class="install-frame">
<div class="bench-chrome">
<img class="bench-logo" src="<?= pl_e(pl_url('/assets/brand/phpledger-horizontal.png')) ?>" alt="PHP Ledger" width="2172" height="724">
<?php if (!in_array($view, ['locked', 'blocked'], true)): ?>
<ol class="bench-tray" aria-label="Installation stages">
<?php $reached = true; foreach ($tray as $name => $views): $current = in_array($view, $views, true); $done = $reached && !$current; ?>
<li class="tray-slot <?= $current ? 'is-current' : ($done ? 'is-done' : 'is-pending') ?>"<?= $current ? ' aria-current="step"' : '' ?>><?= $done ? $tick : '' ?><span class="sr-only"><?= pl_e($name) ?></span></li>
<?php if ($current) { $reached = false; } endforeach; ?>
</ol>
<?php endif; ?>
</div>
<?php if ($error !== ''): ?><div class="alert alert-danger" role="alert" tabindex="-1"><p><?= pl_e($error) ?></p></div><?php endif; ?>
<?php if ($insecureHttp && !in_array($view, ['locked', 'blocked'], true)): ?><div class="alert alert-warning" role="note"><p><strong>This address is not using HTTPS.</strong> What you type here travels unencrypted. Setup continues so that you can try PHP Ledger; before you keep real books, turn on SSL in your hosting panel and open the site again with https://.</p></div><?php endif; ?>
<?php $exposureLink = $exposureWarning && !in_array($view, ['locked', 'blocked'], true) ? pl_install_exposure_check_link() : null; ?>
<?php if ($exposureLink !== null): ?><div class="alert alert-warning" role="note"><p>Setup could not confirm that PHP Ledger's private folders are hidden. Open <a href="<?= pl_e($exposureLink) ?>" target="_blank" rel="noopener">this check link</a> in a new tab. It should show “Not Found” or “Forbidden”. If you can read a message instead, point the website's document root at <code>www/phpledger/public</code>.</p></div><?php endif; ?>

<?php if ($view === 'locked'): ?>
<div class="bench-head"><h1 class="bench-heading size-lg">Installation is locked</h1><p class="bench-sub">This copy is already installed, so browser setup is closed. Nothing was changed.</p></div>
<div class="bench-body"><div class="done-body"><a class="btn btn-primary" href="<?= pl_e(pl_url('/login')) ?>">Go to sign in</a></div></div>

<?php elseif ($view === 'blocked'): ?>
<div class="bench-head"><h1 class="bench-heading size-lg">Finish preparing this website</h1><p class="bench-sub">Setup cannot continue yet. Follow the message above, then reload this page. Nothing was saved.</p></div>

<?php elseif ($view === 'key'): ?>
<div class="bench-head"><h1 class="bench-heading">Unlock setup</h1><p class="bench-sub">The key is in your private installation directory. It proves you control this hosting account. Never put it in a public folder or a web address.</p></div>
<div class="bench-body"><div class="bench-split">
<form class="flex flex-col gap-3" method="post" action="<?= pl_e(pl_url('/install')) ?>"><input type="hidden" name="csrf_token" value="<?= pl_e($csrf) ?>"><input type="hidden" name="action" value="unlock">
<div class="field"><label class="field-label" for="setup-key">Private setup key</label><input class="input" id="setup-key" name="setup_key" type="password" required autocomplete="off" maxlength="256"></div>
<div><button class="btn btn-primary" type="submit">Unlock setup</button></div></form>
<div></div></div></div>

<?php elseif ($view === 'challenge'): $challenge = $blocking[0]; ?>
<div class="bench-head"><h1 class="bench-heading"><?= pl_e($headings['challenge']) ?></h1>
<p class="bench-sub"><?= (int) (count($requirements) - count($blocking)) ?> parts are already in place. <?= count($blocking) === 1 ? 'One needs' : (int) count($blocking) . ' need' ?> a fix before setup can continue.</p></div>
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
<h2 class="challenge-title"><?= pl_e($challenge['name']) ?> needs attention</h2>
<p class="challenge-detail"><?= pl_e($challenge['name']) ?>: <?= pl_e($challenge['detail']) ?>.</p>
<?php if ($challenge['fixes'] !== []): ?>
<p class="fix-head">Ways to fix this</p>
<ol class="fix-list">
<?php foreach ($challenge['fixes'] as $index => $fix): ?>
<li class="fix-item"><span class="fix-num" aria-hidden="true"><?= $index + 1 ?></span><span><?= pl_e($fix) ?></span></li>
<?php endforeach; ?>
</ol>
<?php endif; ?>
<form class="recheck-row" method="post" action="<?= pl_e(pl_url('/install')) ?>"><input type="hidden" name="csrf_token" value="<?= pl_e($csrf) ?>"><input type="hidden" name="action" value="recheck">
<button class="btn btn-primary" type="submit">Check again</button><span class="recheck-note">Nothing has been saved. Clear this, then check again.</span></form>
</div>
</div></div>

<?php elseif ($view === 'start'): [$summary, $wording] = $summaryWording($requirements); ?>
<div class="bench-head"><h1 class="bench-heading size-lg"><?= pl_e($headings['start']) ?></h1>
<p class="bench-sub">A private installer builds your ledger's structure and protections, then hands you the keys. No company and no transaction is created yet.</p></div>
<div class="bench-body"><div class="bench-split">
<div class="bench-stats">
<p class="stat-row"><span class="stat-num"><?= (int) count(pl_install_migration_versions()) ?></span><span class="stat-label">build steps for your ledger</span></p>
<p class="stat-row"><span class="stat-num">1</span><span class="stat-label">sign-in account you create</span></p>
<p class="stat-row"><span class="stat-num">0</span><span class="stat-label">transactions, until you record one</span></p>
<p class="bench-sub is-small">Each one is checked on your own server before it counts, not assumed.</p>
</div>
<div>
<div class="checks-head"><h2>Checking this server</h2><span class="status-pill<?= $summary === PL_CHECK_PASS ? '' : ' has-issue' ?>"><?= pl_e($wording) ?></span></div>
<ul class="tile-grid">
<?php foreach ($requirements as $index => $check): ?>
<li class="tile <?= pl_e($statusClass($check['status'])) ?> reveal"><span class="led"></span><span class="tile-name"><?= pl_e($check['name']) ?></span><span class="tile-detail"><?= pl_e($check['detail']) ?></span></li>
<?php endforeach; ?>
</ul>
</div>
</div></div>
<div class="bench-actions"><span class="fine-print">Takes about two minutes.</span>
<form method="post" action="<?= pl_e(pl_url('/install')) ?>"><input type="hidden" name="csrf_token" value="<?= pl_e($csrf) ?>"><input type="hidden" name="action" value="start">
<button class="btn btn-primary" type="submit">Start setup</button></form></div>

<?php elseif ($view === 'database'): ?>
<div class="bench-head"><h1 class="bench-heading"><?= pl_e($headings['database']) ?></h1>
<p class="bench-sub">PHP Ledger stores every transaction here. On this computer, setup can create the database for you.</p></div>
<div class="bench-body"><div class="bench-split">
<form class="flex flex-col gap-3 bench-main" id="database-form" method="post" action="<?= pl_e(pl_url('/install')) ?>"><input type="hidden" name="csrf_token" value="<?= pl_e($csrf) ?>"><input type="hidden" name="action" value="database">
<?php if ($remoteProof): ?><div class="field"><label class="field-label" for="setup-code">Setup code from <?= pl_e(basename($setupCodePath)) ?></label><input class="input" id="setup-code" name="setup_code" type="password" required autocomplete="off" maxlength="256" aria-describedby="setup-code-hint"><p class="field-hint" id="setup-code-hint">Your database is on another server, so setup asks for this one-time code. Open <span class="snip"><?= pl_e($setupCodePath) ?></span> with your hosting file manager and copy the code inside.</p></div><?php endif; ?>
<div class="field"><label class="field-label" for="public-url">This site's address</label><input class="input" id="public-url" name="public_url" type="url" value="<?= pl_e($field('public_url', (string) (getenv('PL_PUBLIC_URL') ?: pl_install_suggested_public_url($_SERVER)))) ?>" placeholder="https://books.example.com" required maxlength="480" autocomplete="url"></div>
<div class="field-pair">
<div class="field"><label class="field-label" for="db-host">Database host</label><input class="input" id="db-host" name="host" value="<?= pl_e($field('host', 'localhost')) ?>" required maxlength="253" autocomplete="off"></div>
<div class="field"><label class="field-label" for="db-port">Port</label><input class="input" id="db-port" name="port" value="<?= pl_e($field('port', (string) ($databasePorts[0] ?? 3306))) ?>" required inputmode="numeric" pattern="[0-9]+"></div>
<?php if ($databasePorts !== []): ?><span class="detect-chip">A database server answered on this computer at port <?= pl_e(implode(', ', array_map('strval', $databasePorts))) ?></span><?php endif; ?>
</div>
<div class="field"><label class="field-label" for="db-name">Database name</label><input class="input" id="db-name" name="database" value="<?= pl_e($field('database')) ?>" required maxlength="64" autocomplete="off" aria-describedby="db-name-hint"><p class="field-hint" id="db-name-hint">Any name will do here. Setup creates it if it does not exist yet on this computer.</p></div>
<div class="field-pair">
<div class="field"><label class="field-label" for="db-user">Database user</label><input class="input" id="db-user" name="user" value="<?= pl_e($field('user')) ?>" required maxlength="128" autocomplete="off"></div>
<div class="field"><label class="field-label" for="db-password">Database password</label><input class="input" id="db-password" name="password" type="password" autocomplete="new-password" maxlength="1024" placeholder="Leave empty on this computer"></div>
</div>
</form>
<div class="bench-side">
<div class="tip-module"><h2>Setting up on your own computer?</h2>
<p>XAMPP, Laragon and MAMP already create a <span class="snip">root</span> account with no password. Leave the password empty and setup does the rest, including creating the database itself.</p></div>
<?php if ($requirements !== []): [$summary, $wording] = $summaryWording($requirements); ?>
<span class="recap-pill"><?= $tick ?>Server checks: <?= pl_e($wording) ?></span>
<?php endif; ?>
</div>
</div></div>
<div class="bench-actions"><span class="fine-print">MySQL 8.4 or MariaDB 10.4 and newer. No terminal needed.</span>
<button class="btn btn-primary" type="submit" form="database-form">Check database</button></div>

<?php elseif ($view === 'review'): ?>
<div class="bench-body"><div class="reward-body">
<div class="reward-card reveal">
<span class="reward-badge"><?= $tick ?>Connected</span>
<h1 class="reward-title"><?= pl_e($headings['review']) ?></h1>
<p class="reward-detail"><?= pl_e((string) ($reviewConfig['database'] ?? '')) ?> on <?= pl_e((string) ($reviewConfig['host'] ?? '')) ?>:<?= (int) ($reviewConfig['port'] ?? 3306) ?></p>
<p class="reward-note"><?= $createdDatabase !== '' ? 'Created just now, because it did not exist yet.' : 'The database is empty and ready.' ?><?= ($reviewConfig['password'] ?? '') === '' || strtolower((string) ($reviewConfig['user'] ?? '')) === 'root' ? ' Signing in as ' . pl_e((string) ($reviewConfig['user'] ?? '')) . (($reviewConfig['password'] ?? '') === '' ? ' with no password, which is normal on your own computer.' : '.') : '' ?></p>
</div>
<ul class="next-row reveal"><li class="next-chip">Chart of accounts</li><li class="next-chip">Protections</li><li class="next-chip">Reports</li><li class="next-chip">Your account</li></ul>
</div></div>
<div class="bench-actions is-centred">
<form method="post" action="<?= pl_e(pl_url('/install')) ?>"><input type="hidden" name="csrf_token" value="<?= pl_e($csrf) ?>"><input type="hidden" name="action" value="migrate">
<button class="btn btn-primary" type="submit">Install database</button></form></div>

<?php elseif ($view === 'migrating'): ?>
<div class="bench-head"><h1 class="bench-heading"><?= pl_e($headings['migrating']) ?></h1>
<p class="bench-sub">Your browser is building the database. Keep this tab open; it continues by itself.</p></div>
<div class="bench-body"><div class="progress-wrap">
<div>
<p class="install-progress-status" role="status"><span class="install-progress-step"><?= pl_e($progress['label']) ?></span><span class="install-progress-count">Step <?= (int) min($progress['done'] + 1, $progress['total']) ?> of <?= (int) $progress['total'] ?> &middot; <?= (int) $progress['percent'] ?>%</span></p>
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
<div><p class="field-hint">Just placed</p>
<ul class="placed-row"><?php foreach ($justPlaced as $version): ?><li class="placed-chip"><?= $tick ?><?= pl_e(pl_install_step_label($version)) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
</div></div>
<div class="bench-actions"><span class="fine-print">Continuing automatically. Nothing to click. If the connection stops, reopen this page and enter the same database details: an interrupted step resumes where it stopped.</span>
<form method="post" action="<?= pl_e(pl_url('/install')) ?>"<?= $error === '' ? ' data-install-continue' : '' ?>><input type="hidden" name="csrf_token" value="<?= pl_e($csrf) ?>"><input type="hidden" name="action" value="migrate">
<button class="btn btn-primary" type="submit"<?= $error === '' ? ' data-install-auto' : '' ?>>Continue installation</button></form></div>
<noscript><p class="field-hint">JavaScript is off, so this does not continue by itself. Select Continue installation until every step is complete.</p></noscript>

<?php elseif ($view === 'configuration'): $selfChecks = pl_install_self_checks($schema ?? []); [$summary, $wording] = $summaryWording($selfChecks); ?>
<div class="bench-head"><h1 class="bench-heading"><?= pl_e($headings['configuration']) ?></h1>
<p class="bench-sub">Setup has just proved these on your own server.</p></div>
<div class="bench-body"><ul class="gauge-grid">
<?php foreach ($selfChecks as $index => $check): ?>
<li class="gauge-tile <?= pl_e($statusClass($check['status'])) ?> reveal"><span class="gauge-top"><span class="gauge-led"></span><span class="gauge-name"><?= pl_e($check['name']) ?></span></span><p class="gauge-detail"><?= pl_e($check['detail']) ?></p></li>
<?php endforeach; ?>
</ul></div>
<div class="bench-actions"><span class="fine-print"><?= pl_e(ucfirst($wording)) ?>. Next, the settings are saved outside the folder visitors can reach.</span>
<form method="post" action="<?= pl_e(pl_url('/install')) ?>"><input type="hidden" name="csrf_token" value="<?= pl_e($csrf) ?>"><input type="hidden" name="public_url" value="<?= pl_e((string) ($_SESSION['install_runtime']['public_url'] ?? '')) ?>">
<button class="btn btn-primary" name="action" value="save_config" type="submit">Save private configuration</button></form></div>
<details class="text-sm"><summary>My host does not allow PHP to write this file</summary><p class="field-hint">Download the private configuration and upload it with your hosting panel as <span class="snip">www/phpledger/includes/config.local.php</span>, outside <span class="snip">public/</span>. Restrict its permissions, delete the downloaded copy, then refresh this page. Setup never overwrites an existing file.</p>
<form method="post" action="<?= pl_e(pl_url('/install')) ?>"><input type="hidden" name="csrf_token" value="<?= pl_e($csrf) ?>"><input type="hidden" name="public_url" value="<?= pl_e((string) ($_SESSION['install_runtime']['public_url'] ?? '')) ?>"><button class="btn btn-secondary" name="action" value="download_config" type="submit">Download private configuration</button></form></details>

<?php elseif ($view === 'account'): ?>
<div class="bench-head"><h1 class="bench-heading"><?= pl_e($headings['account']) ?></h1><p class="bench-sub">The last step before your first business.</p></div>
<div class="bench-body"><div class="bench-split">
<form class="flex flex-col gap-3 bench-main-wide" id="account-form" method="post" action="<?= pl_e(pl_url('/install')) ?>" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?= pl_e($csrf) ?>"><input type="hidden" name="action" value="finish">
<div class="field-pair">
<div class="field"><label class="field-label" for="owner-name">Your name</label><input class="input" id="owner-name" name="name" value="<?= pl_e($field('name')) ?>" required maxlength="120" autocomplete="name"></div>
<div class="field"><label class="field-label" for="owner-username">Username</label><input class="input" id="owner-username" name="username" value="<?= pl_e($field('username', (string) ($state['owner_username'] ?? ''))) ?>" required minlength="3" maxlength="60" autocomplete="username" autocapitalize="none" spellcheck="false" aria-describedby="username-hint"></div>
</div>
<p class="field-hint" id="username-hint">3–60 letters, numbers, dots, dashes or underscores, such as “owner” or “ali.khan”.</p>
<div class="field"><label class="field-label" for="owner-email">Email address</label><input class="input" id="owner-email" name="email" type="email" value="<?= pl_e($field('email', (string) ($state['owner_email'] ?? ''))) ?>" required maxlength="254" autocomplete="email"></div>
<div class="field-pair">
<div class="field"><label class="field-label" for="owner-password">Password</label><input class="input" id="owner-password" name="password" type="password" required minlength="12" maxlength="72" autocomplete="new-password" aria-describedby="password-hint"></div>
<div class="field"><label class="field-label" for="owner-password-confirm">Type it again</label><input class="input" id="owner-password-confirm" name="password_confirm" type="password" required minlength="12" maxlength="72" autocomplete="new-password"></div>
</div>
<p class="field-hint" id="password-hint">At least 12 characters. You will sign in with your username or your email address.</p>
<div class="field"><label class="field-label" for="owner-logo">Your logo (optional)</label><input class="input" id="owner-logo" name="logo" type="file" accept="image/png,image/jpeg,image/webp" aria-describedby="logo-hint"><p class="field-hint" id="logo-hint">PNG, JPEG or WebP up to 1 MB. It replaces the PHP Ledger logo in the menu and on the sign-in page.</p></div>
</form>
<div class="bench-side-narrow">
<div class="bench-aside">
<p><strong>This is the last step.</strong> Next you set up your first business, and browser setup then closes permanently.</p>
<p>Your private installation directory also holds <span class="snip">operator.key</span>, for maintenance. Keep it with your private backups; it is not your sign-in password.</p>
</div>
</div>
</div></div>
<div class="bench-actions"><span class="fine-print">You will sign in with your username or your email.</span>
<button class="btn btn-primary" type="submit" form="account-form">Finish and create your business</button></div>

<?php elseif ($view === 'ready'): ?>
<div class="bench-body"><div class="done-body">
<div><h1 class="bench-heading size-lg"><?= pl_e($headings['ready']) ?></h1><p class="bench-sub">Next, set up your first business.</p></div>
<ul class="manifest-card reveal">
<?php foreach ($completion as $line): ?>
<li class="manifest-row"><?= $tick ?><?= pl_e($line) ?></li>
<?php endforeach; ?>
</ul>
</div></div>
<div class="bench-actions is-centred"><a class="btn btn-primary" href="<?= pl_e(pl_url('/onboarding')) ?>">Set up your first business</a></div>
<?php endif; ?>
</main></body></html>
