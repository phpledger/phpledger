<?php declare(strict_types=1);
$headings = ['locked' => 'Installation is locked', 'blocked' => 'Finish preparing this website', 'key' => 'Install PHP Ledger', 'database' => 'Connect your database',
    'review' => 'Review your installation', 'migrating' => 'Preparing your database', 'configuration' => 'Save private configuration', 'account' => 'Create your sign-in account'];
$field = static fn (string $name, string $default = ''): string => is_string($_POST[$name] ?? null) ? $_POST[$name] : $default;
$csrf = session_status() === PHP_SESSION_ACTIVE ? pl_csrf_token() : '';
$localHttp = $localHttp ?? false;
$insecureHttp = $insecureHttp ?? false;
$createdDatabase = $createdDatabase ?? '';
$reviewConfig = $_SESSION['install_database'] ?? [];
$exposureWarning = $exposureWarning ?? false;
$remoteProof = $remoteProof ?? false;
$setupCodePath = $setupCodePath ?? '';
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light">
<title><?= pl_e($headings[$view]) ?> · PHP Ledger</title><link rel="stylesheet" href="<?= pl_e(pl_url('/assets/app.css')) ?>">
<?php if ($view === 'migrating' && $error === ''): ?><script src="<?= pl_e(pl_url('/assets/install.js')) ?>" defer></script><?php endif; ?>
</head><body><main class="auth-shell"><div class="auth-card">
<img class="auth-logo" src="<?= pl_e(pl_url('/assets/brand/phpledger-horizontal.png')) ?>" alt="PHP Ledger" width="2172" height="724">
<section class="auth-panel flex flex-col gap-4"><p class="eyebrow">Private installation · <?= pl_e(pl_app_version()) ?></p><h1 class="page-title"><?= pl_e($headings[$view]) ?></h1>
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
<p>Enter the setup key from your private installation directory. This proves that you control this hosting installation. Never place the key in a public folder or share it in a URL.</p>
<form class="flex flex-col gap-3" method="post" action="<?= pl_e(pl_url('/install')) ?>"><input type="hidden" name="csrf_token" value="<?= pl_e($csrf) ?>"><input type="hidden" name="action" value="unlock">
<label class="field-label" for="setup-key">Private setup key</label><input class="input" id="setup-key" name="setup_key" type="password" required autocomplete="off" maxlength="256">
<div class="panel-actions"><button class="btn btn-primary" type="submit">Unlock setup</button></div></form>
<?php elseif ($view === 'database'): ?>
<p>Tell PHP Ledger which database to use. MySQL 8.4 and MariaDB 10.4 or newer both work, and no terminal or Composer command is needed. On a hosting panel, create an empty database and a user for it first. On your own computer, type any name you like: when the database is on this same server and does not exist yet, setup creates it.</p>
<details class="text-sm"><summary>Where do I create the database?</summary>
<p><strong>cPanel and most hosting panels:</strong> open MySQL Databases (or the Database Wizard). Create a database, then a user with a strong password, and add the user to the database with all privileges. Hosts often put your account name in front, such as <code>myaccount_ledger</code>. The database host is usually <code>localhost</code>.</p>
<p><strong>XAMPP, Laragon or MAMP on your computer:</strong> there is nothing to prepare. Leave the host as <code>localhost</code>, type a database name such as <code>phpledger</code>, and enter the account your stack installed, usually <code>root</code> with an empty password. Setup creates the database for you. For a copy that other people will use, open <code>http://localhost/phpmyadmin</code>, choose User accounts, then Add user account, and create a separate user with a password instead.</p></details>
<form class="flex flex-col gap-3" method="post" action="<?= pl_e(pl_url('/install')) ?>"><input type="hidden" name="csrf_token" value="<?= pl_e($csrf) ?>"><input type="hidden" name="action" value="database">
<?php if ($remoteProof): ?><label class="field-label" for="setup-code">Setup code from <?= pl_e(basename($setupCodePath)) ?></label><input class="input" id="setup-code" name="setup_code" type="password" required autocomplete="off" maxlength="256" aria-describedby="setup-code-hint"><p id="setup-code-hint">Your database is on another server, so setup asks for this one-time code. Open <code><?= pl_e($setupCodePath) ?></code> with your hosting file manager and copy the code inside. It confirms that you control this website.</p><?php endif; ?>
<label class="field-label" for="public-url">This site's address</label><input class="input" id="public-url" name="public_url" type="url" value="<?= pl_e($field('public_url', (string) (getenv('PL_PUBLIC_URL') ?: pl_install_suggested_public_url($_SERVER)))) ?>" placeholder="https://books.example.com" required maxlength="480" autocomplete="url"><p>Check that it matches the address in your browser. Connections for other apps use this exact address.</p>
<label class="field-label" for="db-host">Database host</label><input class="input" id="db-host" name="host" value="<?= pl_e($field('host', 'localhost')) ?>" required maxlength="253" autocomplete="off">
<label class="field-label" for="db-port">Port</label><input class="input" id="db-port" name="port" value="<?= pl_e($field('port', '3306')) ?>" required inputmode="numeric" pattern="[0-9]+">
<label class="field-label" for="db-name">Database name</label><input class="input" id="db-name" name="database" value="<?= pl_e($field('database')) ?>" required maxlength="64" autocomplete="off">
<label class="field-label" for="db-user">Database user</label><input class="input" id="db-user" name="user" value="<?= pl_e($field('user')) ?>" required maxlength="128" autocomplete="off">
<label class="field-label" for="db-password">Database password</label><input class="input" id="db-password" name="password" type="password" autocomplete="new-password" maxlength="1024" aria-describedby="db-password-hint"><p id="db-password-hint">Leave this empty only when the database is on this same computer and its account has no password, as XAMPP installs it. A database on another server always needs one.</p>
<div class="panel-actions"><button class="btn btn-primary" type="submit">Check database</button></div></form>
<?php elseif ($view === 'review' || $view === 'migrating'): ?>
<?php if ($createdDatabase !== ''): ?><div class="alert alert-info" role="note"><p>Setup created the empty database <code><?= pl_e($createdDatabase) ?></code> on this server, because it did not exist yet.</p></div><?php endif; ?>
<?php if ($view === 'review' && (($reviewConfig['password'] ?? '') === '' || strtolower((string) ($reviewConfig['user'] ?? '')) === 'root')): ?><div class="alert alert-warning" role="note"><p>PHP Ledger will sign in to the database as <code><?= pl_e((string) ($reviewConfig['user'] ?? '')) ?></code><?= ($reviewConfig['password'] ?? '') === '' ? ' with no password' : '' ?>. That is how local stacks such as XAMPP are set up and it is fine for trying the software out. On a server other people can reach, create a database user with its own password and its own database, then install again.</p></div><?php endif; ?>
<?php if ($view === 'review'): ?><p>Install <?= pl_e(pl_app_version()) ?> into <strong><?= pl_e((string) ($_SESSION['install_database']['database'] ?? '')) ?></strong> on <?= pl_e((string) ($_SESSION['install_database']['host'] ?? '')) ?>. This creates the application schema in the empty database. No company or financial transaction will be posted.</p>
<?php else: ?><p role="status"><?= (int) ($schema['applied'] ?? 0) ?> migrations complete; <?= (int) ($schema['pending'] ?? 0) ?> remain. Your browser continues one migration at a time. Keep this tab open.</p><p>If the connection stops, reopen this page and enter the same database details. A partially applied migration requires operator review; setup will not blindly replay it.</p><?php endif; ?>
<form class="flex flex-col gap-3" method="post" action="<?= pl_e(pl_url('/install')) ?>"<?= $view === 'migrating' && $error === '' ? ' data-install-continue' : '' ?>><input type="hidden" name="csrf_token" value="<?= pl_e($csrf) ?>"><input type="hidden" name="action" value="migrate">
<button class="btn btn-primary" type="submit"><?= $view === 'review' ? 'Install database' : 'Continue installation' ?></button></form>
<noscript><p>JavaScript is off. Select Continue installation until all migrations are complete.</p></noscript>
<?php elseif ($view === 'configuration'): ?>
<p>The schema is ready. Save the normal database configuration and public URL privately, outside the public directory. This step also creates private OAuth signing and encryption keys for Connections, preserving any matching existing keys. Temporary installation credentials will be removed from the setup session when installation finishes.</p>
<form class="flex flex-col gap-3" method="post" action="<?= pl_e(pl_url('/install')) ?>"><input type="hidden" name="csrf_token" value="<?= pl_e($csrf) ?>"><label class="field-label" for="confirm-public-url">Public application URL</label><input class="input" id="confirm-public-url" name="public_url" type="url" value="<?= pl_e($field('public_url', (string) ($_SESSION['install_runtime']['public_url'] ?? getenv('PL_PUBLIC_URL') ?: ''))) ?>" placeholder="https://books.example.com" required maxlength="480"><button class="btn btn-primary" name="action" value="save_config" type="submit">Save private configuration</button></form>
<details class="text-sm"><summary>My host does not allow PHP to write this file</summary><p>Download the private configuration and upload it with your hosting panel as <code>www/phpledger/includes/config.local.php</code>, outside <code>public/</code>. Restrict its permissions and remove the downloaded copy from shared devices. Then refresh this page. Existing files are never overwritten by setup.</p>
<form class="flex flex-col gap-3" method="post" action="<?= pl_e(pl_url('/install')) ?>"><input type="hidden" name="csrf_token" value="<?= pl_e($csrf) ?>"><button class="btn btn-secondary" name="action" value="download_config" type="submit">Download private configuration</button></form></details>
<?php elseif ($view === 'account'): ?>
<p>Create your owner account. You will sign in with either your username or your email address, and the password you choose here. Next you will set up your first business, and browser installation then closes permanently.</p>
<p>Your private installation directory also holds the separate <code>operator.key</code> for maintenance. Keep it with your private backups; it is not your sign-in password.</p>
<form class="flex flex-col gap-3" method="post" action="<?= pl_e(pl_url('/install')) ?>" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?= pl_e($csrf) ?>"><input type="hidden" name="action" value="finish">
<label class="field-label" for="owner-name">Your name</label><input class="input" id="owner-name" name="name" value="<?= pl_e($field('name')) ?>" required maxlength="120" autocomplete="name">
<label class="field-label" for="owner-username">Username</label><input class="input" id="owner-username" name="username" value="<?= pl_e($field('username', (string) ($state['owner_username'] ?? ''))) ?>" required minlength="3" maxlength="60" autocomplete="username" autocapitalize="none" spellcheck="false" aria-describedby="username-hint"><p id="username-hint">3–60 letters, numbers, dots, dashes or underscores, such as “owner” or “ali.khan”.</p>
<label class="field-label" for="owner-email">Email address</label><input class="input" id="owner-email" name="email" type="email" value="<?= pl_e($field('email', (string) ($state['owner_email'] ?? ''))) ?>" required maxlength="254" autocomplete="email">
<label class="field-label" for="owner-password">Password</label><input class="input" id="owner-password" name="password" type="password" required minlength="12" maxlength="72" autocomplete="new-password" aria-describedby="password-hint"><p id="password-hint">Use 12–72 bytes. For a simple choice, use at least 12 ASCII characters.</p>
<label class="field-label" for="owner-password-confirm">Type the password again</label><input class="input" id="owner-password-confirm" name="password_confirm" type="password" required minlength="12" maxlength="72" autocomplete="new-password">
<label class="field-label" for="owner-logo">Your logo (optional)</label><input class="input" id="owner-logo" name="logo" type="file" accept="image/png,image/jpeg,image/webp" aria-describedby="logo-hint"><p id="logo-hint">PNG, JPEG or WebP up to 1 MB. It appears in the menu and on the sign-in page instead of the PHP Ledger logo. You can skip this.</p>
<div class="panel-actions"><button class="btn btn-primary" type="submit">Finish and create your business</button></div></form>
<?php endif; ?>
</section></div></main></body></html>
