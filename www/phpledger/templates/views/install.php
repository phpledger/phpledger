<?php declare(strict_types=1);
$headings = ['locked' => 'Installation is locked', 'blocked' => 'Finish preparing this website', 'key' => 'Install PHP Ledger', 'database' => 'Connect your database',
    'review' => 'Review your installation', 'migrating' => 'Preparing your database', 'configuration' => 'Save private configuration', 'account' => 'Create your sign-in account'];
// Setup is used on a desktop or laptop, so each step is laid out across the screen
// and kept to one screen height where its content allows (owner decision, 20 September 2026).
$steps = ['Database' => ['key', 'database'], 'Install' => ['review', 'migrating'], 'Configure' => ['configuration'], 'Account' => ['account']];
$field = static fn (string $name, string $default = ''): string => is_string($_POST[$name] ?? null) ? $_POST[$name] : $default;
$csrf = session_status() === PHP_SESSION_ACTIVE ? pl_csrf_token() : '';
$localHttp = $localHttp ?? false;
$insecureHttp = $insecureHttp ?? false;
$createdDatabase = $createdDatabase ?? '';
$reviewConfig = $_SESSION['install_database'] ?? [];
$exposureWarning = $exposureWarning ?? false;
$remoteProof = $remoteProof ?? false;
$setupCodePath = $setupCodePath ?? '';
$checkPanel = static function (string $title, array $checks, string $intro = ''): void {
    $counts = pl_install_check_counts($checks);
    $summary = pl_install_check_summary($checks);
    $wording = $summary === PL_CHECK_PASS
        ? 'all ' . $counts[PL_CHECK_PASS] . ' passed'
        : ($counts[PL_CHECK_FAIL] > 0 ? $counts[PL_CHECK_FAIL] . ' need attention' : $counts[PL_CHECK_WARN] . ' worth a look')
            . ', ' . $counts[PL_CHECK_PASS] . ' passed';
    ?><details<?= $summary === PL_CHECK_PASS ? '' : ' open' ?>>
    <summary class="check-summary"><span class="check-light check-<?= pl_e($summary) ?>-light"></span><?= pl_e($title) ?>: <?= pl_e($wording) ?></summary>
    <?php if ($intro !== ''): ?><p class="field-hint"><?= pl_e($intro) ?></p><?php endif; ?>
    <ul class="check-list">
    <?php foreach ($checks as $check): ?>
      <li class="check-row check-<?= pl_e($check['status']) ?>"><span class="check-light"></span><span class="check-name"><?= pl_e($check['name']) ?></span><span class="check-detail"><?= pl_e($check['detail']) ?></span></li>
    <?php endforeach; ?>
    </ul></details><?php
};
$environmentPanel = static function (array $environment): void {
    ?><details><summary>What setup found on this server</summary>
    <p class="field-hint">Setup worked these out by itself. Check them before you continue.</p>
    <dl class="env-list">
    <?php foreach ($environment as $item): ?>
      <dt><?= pl_e($item['name']) ?></dt><dd><?= pl_e($item['value']) ?></dd>
    <?php endforeach; ?>
    </dl></details><?php
};
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light">
<title><?= pl_e($headings[$view]) ?> · PHP Ledger</title><link rel="stylesheet" href="<?= pl_e(pl_url('/assets/app.css', ['v' => pl_app_version()])) ?>">
<?php if ($view === 'migrating' && $error === ''): ?><script src="<?= pl_e(pl_url('/assets/install.js', ['v' => pl_app_version()])) ?>" defer></script><?php endif; ?>
</head><body><main class="auth-shell"><div class="auth-card install-card">
<img class="auth-logo" src="<?= pl_e(pl_url('/assets/brand/phpledger-horizontal.png')) ?>" alt="PHP Ledger" width="2172" height="724">
<section class="auth-panel install-panel flex flex-col gap-3">
<div class="install-head">
<div><p class="eyebrow">Private installation · <?= pl_e(pl_app_version()) ?></p><h1 class="page-title"><?= pl_e($headings[$view]) ?></h1></div>
<?php if (!in_array($view, ['locked', 'blocked'], true)): ?>
<ol class="install-steps" aria-label="Installation steps">
<?php $reached = true; $number = 0; foreach ($steps as $name => $views): $number++; $current = in_array($view, $views, true); ?>
<li<?= $current ? ' aria-current="step"' : ($reached && !$current ? ' class="install-step-done"' : '') ?>><span class="install-step-mark" aria-hidden="true"><?= $reached && !$current ? '&check;' : $number ?></span><?= pl_e($name) ?></li>
<?php if ($current) { $reached = false; } endforeach; ?>
</ol>
<?php endif; ?>
</div>
<?php if ($error !== ''): ?><div class="alert alert-danger" role="alert" tabindex="-1"><p><?= pl_e($error) ?></p></div><?php endif; ?>
<?php if ($localHttp && !in_array($view, ['locked', 'blocked'], true)): ?><div class="alert alert-info" role="note"><p>Local test on this computer over plain HTTP. On a real website, open the installer with https:// instead.</p></div><?php endif; ?>
<?php if ($insecureHttp && !in_array($view, ['locked', 'blocked'], true)): ?><div class="alert alert-warning" role="note"><p><strong>This address is not using HTTPS.</strong> The database details and the password you choose here travel unencrypted, and anyone on the network between you and this server can read them. Setup continues so that you can try PHP Ledger, but before you keep real books turn on SSL in your hosting panel, for example AutoSSL or Let's Encrypt, and open the site again with https://. Connections (the API, MCP and app integrations) stay unavailable while this site has no certificate.</p></div><?php endif; ?>
<?php $exposureLink = $exposureWarning && !in_array($view, ['locked', 'blocked'], true) ? pl_install_exposure_check_link() : null; ?>
<?php if ($exposureLink !== null): ?><div class="alert alert-warning" role="note"><p>Setup could not confirm that PHP Ledger's private folders are hidden. Open <a href="<?= pl_e($exposureLink) ?>" target="_blank" rel="noopener">this check link</a> in a new tab. It should show “Not Found” or “Forbidden”. If you can read a message instead, stop and point the website's document root at the <code>www/phpledger/public</code> folder, or ask your host to enable .htaccess rules.</p></div><?php endif; ?>
<?php if ($view === 'locked'): ?>
<p>This copy of PHP Ledger is already installed, so browser setup is closed. Nothing was changed.</p>
<p>Sign in with your account. To move to a newer version, follow the upgrade guide.</p><a class="btn btn-primary" href="<?= pl_e(pl_url('/login')) ?>">Go to sign in</a>
<?php elseif ($view === 'blocked'): ?>
<p>Setup cannot continue yet. Follow the message above, then reload this page. Nothing was saved.</p>
<?php elseif ($view === 'key'): ?>
<div class="install-columns">
<form class="flex flex-col gap-3" method="post" action="<?= pl_e(pl_url('/install')) ?>"><input type="hidden" name="csrf_token" value="<?= pl_e($csrf) ?>"><input type="hidden" name="action" value="unlock">
<div><label class="field-label" for="setup-key">Private setup key</label><input class="input" id="setup-key" name="setup_key" type="password" required autocomplete="off" maxlength="256"></div>
<div class="panel-actions"><button class="btn btn-primary" type="submit">Unlock setup</button></div></form>
<aside class="install-aside"><p>The key is in your private installation directory. It proves that you control this hosting account. Never put it in a public folder or in a web address.</p></aside>
</div>
<?php elseif ($view === 'database'): ?>
<?php $databasePorts = pl_install_local_database_ports(); ?>
<div class="install-columns">
<form class="flex flex-col gap-3" method="post" action="<?= pl_e(pl_url('/install')) ?>"><input type="hidden" name="csrf_token" value="<?= pl_e($csrf) ?>"><input type="hidden" name="action" value="database">
<?php if ($remoteProof): ?><div><label class="field-label" for="setup-code">Setup code from <?= pl_e(basename($setupCodePath)) ?></label><input class="input" id="setup-code" name="setup_code" type="password" required autocomplete="off" maxlength="256" aria-describedby="setup-code-hint"><p class="field-hint" id="setup-code-hint">Your database is on another server, so setup asks for this one-time code. Open <code><?= pl_e($setupCodePath) ?></code> with your hosting file manager and copy the code inside.</p></div><?php endif; ?>
<div><label class="field-label" for="public-url">This site's address</label><input class="input" id="public-url" name="public_url" type="url" value="<?= pl_e($field('public_url', (string) (getenv('PL_PUBLIC_URL') ?: pl_install_suggested_public_url($_SERVER)))) ?>" placeholder="https://books.example.com" required maxlength="480" autocomplete="url" aria-describedby="public-url-hint"><p class="field-hint" id="public-url-hint">It should match the address in your browser bar.</p></div>
<div class="field-grid field-grid-host">
<div><label class="field-label" for="db-host">Database host</label><input class="input" id="db-host" name="host" value="<?= pl_e($field('host', 'localhost')) ?>" required maxlength="253" autocomplete="off"></div>
<div><label class="field-label" for="db-port">Port</label><input class="input" id="db-port" name="port" value="<?= pl_e($field('port', (string) ($databasePorts[0] ?? 3306))) ?>" required inputmode="numeric" pattern="[0-9]+"<?= $databasePorts === [] ? '' : ' aria-describedby="db-port-hint"' ?>></div>
<?php if ($databasePorts !== []): ?><p class="field-hint" id="db-port-hint" style="grid-column: 1 / -1">A database server is answering on this computer at port <?= pl_e(implode(', ', array_map('strval', $databasePorts))) ?>. Change it if your host gave you a different one.</p><?php endif; ?>
</div>
<div><label class="field-label" for="db-name">Database name</label><input class="input" id="db-name" name="database" value="<?= pl_e($field('database')) ?>" required maxlength="64" autocomplete="off" aria-describedby="db-name-hint"><p class="field-hint" id="db-name-hint">On this same computer, any name will do: setup creates it if it does not exist.</p></div>
<div class="field-grid field-grid-pair">
<div><label class="field-label" for="db-user">Database user</label><input class="input" id="db-user" name="user" value="<?= pl_e($field('user')) ?>" required maxlength="128" autocomplete="off"></div>
<div><label class="field-label" for="db-password">Database password</label><input class="input" id="db-password" name="password" type="password" autocomplete="new-password" maxlength="1024" aria-describedby="db-password-hint"></div>
<p class="field-hint" id="db-password-hint" style="grid-column: 1 / -1">Leave the password empty only on this same computer, where XAMPP and Laragon install <code>root</code> without one. A database on another server always needs one.</p>
</div>
<div class="panel-actions"><button class="btn btn-primary" type="submit">Check database</button></div></form>
<aside class="install-aside">
<?php $checkPanel('Server checks', pl_install_requirement_checks($_SERVER, $exposureWarning ? 'unknown' : null), 'A red light will stop the installation later, so fix it first.'); ?>
<?php $environmentPanel(pl_install_environment($_SERVER, $databasePorts)); ?>
<details><summary>Where do I create the database?</summary>
<p class="field-hint"><strong>cPanel and most hosting panels:</strong> open MySQL Databases (or the Database Wizard). Create a database, then a user with a strong password, and add the user to the database with all privileges. Hosts often put your account name in front, such as <code>myaccount_ledger</code>. The database host is usually <code>localhost</code>.</p>
<p class="field-hint"><strong>XAMPP, Laragon or MAMP on your computer:</strong> there is nothing to prepare. Leave the host as <code>localhost</code>, type any database name, and enter the account your stack installed, usually <code>root</code> with an empty password. For a copy other people will use, create a separate user with a password in <code>http://localhost/phpmyadmin</code> instead.</p></details>
<p class="field-hint">MySQL 8.4 and MariaDB 10.4 or newer both work. No terminal or Composer command is needed.</p>
</aside>
</div>
<?php elseif ($view === 'review' || $view === 'migrating'): ?>
<?php if ($createdDatabase !== ''): ?><div class="alert alert-info" role="note"><p>Setup created the empty database <code><?= pl_e($createdDatabase) ?></code> on this server, because it did not exist yet.</p></div><?php endif; ?>
<?php if ($view === 'review' && (($reviewConfig['password'] ?? '') === '' || strtolower((string) ($reviewConfig['user'] ?? '')) === 'root')): ?><div class="alert alert-warning" role="note"><p>PHP Ledger will sign in to the database as <code><?= pl_e((string) ($reviewConfig['user'] ?? '')) ?></code><?= ($reviewConfig['password'] ?? '') === '' ? ' with no password' : '' ?>. That is how local stacks such as XAMPP are set up, and it is fine for trying the software out. On a server other people can reach, create a database user with its own password, then install again.</p></div><?php endif; ?>
<?php if ($view === 'review'): ?><p>Install <?= pl_e(pl_app_version()) ?> into <strong><?= pl_e((string) ($reviewConfig['database'] ?? '')) ?></strong> on <?= pl_e((string) ($reviewConfig['host'] ?? '')) ?>. This writes the application structure into the empty database. No company and no financial transaction is created.</p>
<?php else: $progress = pl_install_progress($schema ?? []); ?>
<div class="install-progress">
<div class="install-progress-track"><div class="install-progress-bar" style="width: <?= (int) $progress['percent'] ?>%"></div></div>
<p class="install-progress-status" role="status"><span class="install-progress-step"><?= pl_e($progress['label']) ?></span><span class="install-progress-count">Step <?= (int) min($progress['done'] + 1, $progress['total']) ?> of <?= (int) $progress['total'] ?> &middot; <?= (int) $progress['percent'] ?>%</span></p>
</div>
<p>Your browser is building the database one step at a time. Keep this tab open; it continues by itself and usually takes under a minute.</p>
<p class="field-hint">If the connection stops, reopen this page and enter the same database details. A step that was interrupted stops for review rather than being replayed blindly.</p>
<?php endif; ?>
<form class="flex flex-col gap-3" method="post" action="<?= pl_e(pl_url('/install')) ?>"<?= $view === 'migrating' && $error === '' ? ' data-install-continue' : '' ?>><input type="hidden" name="csrf_token" value="<?= pl_e($csrf) ?>"><input type="hidden" name="action" value="migrate">
<button class="btn btn-primary" type="submit"<?= $view === 'migrating' && $error === '' ? ' data-install-auto' : '' ?>><?= $view === 'review' ? 'Install database' : 'Continue installation' ?></button></form>
<noscript><p>JavaScript is off, so this does not continue by itself. Select Continue installation until every step is complete.</p></noscript>
<?php elseif ($view === 'configuration'): ?>
<div class="install-columns">
<form class="flex flex-col gap-3" method="post" action="<?= pl_e(pl_url('/install')) ?>"><input type="hidden" name="csrf_token" value="<?= pl_e($csrf) ?>">
<p>Your database is ready. Save its settings privately, outside the folder visitors can reach. This also creates the private keys that Connections use.</p>
<div><label class="field-label" for="confirm-public-url">Public application URL</label><input class="input" id="confirm-public-url" name="public_url" type="url" value="<?= pl_e($field('public_url', (string) ($_SESSION['install_runtime']['public_url'] ?? getenv('PL_PUBLIC_URL') ?: ''))) ?>" placeholder="https://books.example.com" required maxlength="480"></div>
<div class="panel-actions"><button class="btn btn-primary" name="action" value="save_config" type="submit">Save private configuration</button></div></form>
<aside class="install-aside">
<?php $checkPanel('Database checks', pl_install_self_checks($schema ?? []), 'Setup has just proved these on your own server.'); ?>
<details><summary>My host does not allow PHP to write this file</summary><p class="field-hint">Download the private configuration and upload it with your hosting panel as <code>www/phpledger/includes/config.local.php</code>, outside <code>public/</code>. Restrict its permissions and delete the downloaded copy, then refresh this page. Setup never overwrites an existing file.</p>
<form method="post" action="<?= pl_e(pl_url('/install')) ?>"><input type="hidden" name="csrf_token" value="<?= pl_e($csrf) ?>"><button class="btn btn-secondary" name="action" value="download_config" type="submit">Download private configuration</button></form></details>
</aside>
</div>
<?php elseif ($view === 'account'): ?>
<div class="install-columns">
<form class="flex flex-col gap-3" method="post" action="<?= pl_e(pl_url('/install')) ?>" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?= pl_e($csrf) ?>"><input type="hidden" name="action" value="finish">
<div class="field-grid field-grid-pair">
<div><label class="field-label" for="owner-name">Your name</label><input class="input" id="owner-name" name="name" value="<?= pl_e($field('name')) ?>" required maxlength="120" autocomplete="name"></div>
<div><label class="field-label" for="owner-username">Username</label><input class="input" id="owner-username" name="username" value="<?= pl_e($field('username', (string) ($state['owner_username'] ?? ''))) ?>" required minlength="3" maxlength="60" autocomplete="username" autocapitalize="none" spellcheck="false" aria-describedby="username-hint"></div>
<p class="field-hint" id="username-hint" style="grid-column: 1 / -1">3–60 letters, numbers, dots, dashes or underscores, such as “owner” or “ali.khan”.</p>
</div>
<div><label class="field-label" for="owner-email">Email address</label><input class="input" id="owner-email" name="email" type="email" value="<?= pl_e($field('email', (string) ($state['owner_email'] ?? ''))) ?>" required maxlength="254" autocomplete="email"></div>
<div class="field-grid field-grid-pair">
<div><label class="field-label" for="owner-password">Password</label><input class="input" id="owner-password" name="password" type="password" required minlength="12" maxlength="72" autocomplete="new-password" aria-describedby="password-hint"></div>
<div><label class="field-label" for="owner-password-confirm">Type it again</label><input class="input" id="owner-password-confirm" name="password_confirm" type="password" required minlength="12" maxlength="72" autocomplete="new-password"></div>
<p class="field-hint" id="password-hint" style="grid-column: 1 / -1">At least 12 characters. You will sign in with your username or your email address.</p>
</div>
<div><label class="field-label" for="owner-logo">Your logo (optional)</label><input class="input" id="owner-logo" name="logo" type="file" accept="image/png,image/jpeg,image/webp" aria-describedby="logo-hint"><p class="field-hint" id="logo-hint">PNG, JPEG or WebP up to 1 MB. It replaces the PHP Ledger logo in the menu and on the sign-in page.</p></div>
<div class="panel-actions"><button class="btn btn-primary" type="submit">Finish and create your business</button></div></form>
<aside class="install-aside">
<p><strong>This is the last step.</strong> Next you set up your first business, and browser setup then closes permanently.</p>
<p>Your private installation directory also holds <code>operator.key</code>, for maintenance. Keep it with your private backups; it is not your sign-in password.</p>
</aside>
</div>
<?php endif; ?>
</section></div></main></body></html>
