<?php
declare(strict_types=1);

require_once __DIR__ . '/installation_state_functions.php';
require_once __DIR__ . '/install_functions.php';
require_once __DIR__ . '/install_oauth_functions.php';
require_once __DIR__ . '/install_exposure_functions.php';
require_once __DIR__ . '/security_functions.php';
require_once __DIR__ . '/demo_functions.php';

/** Browser setup is open until installation completes; the hosted demo never offers it. */
function pl_install_available(): bool
{
    return getenv('PL_ENV') !== 'demo' && !is_file(pl_install_directory() . '/installed.json');
}

/**
 * An operator may still require a private setup key (PL_SETUP_KEY or setup.key),
 * for example on a VPS or through an auto-installer. Without one, setup opens
 * directly, as WordPress does (owner decision, 19 September 2026).
 */
function pl_install_setup_key(): ?string
{
    if (!pl_install_available()) {
        return null;
    }
    $key = getenv('PL_SETUP_KEY');
    if ($key === false || $key === '') {
        $path = pl_install_directory() . '/setup.key';
        $key = is_file($path) && filesize($path) <= 256 ? file_get_contents($path) : false;
    }
    if (!is_string($key)) {
        return null;
    }
    $key = trim($key);
    return strlen($key) >= 32 && strlen($key) <= 256 && !preg_match('/[\x00-\x20\x7f]/', $key) ? $key : null;
}

/** A setup-key proof is installation scoped and expires independently of application sign-in. */
function pl_install_authorized(string $key, array $session, int $now): bool
{
    $proof = $session['install_proof'] ?? null;
    return is_array($proof) && is_string($proof['key_hash'] ?? null) && is_int($proof['at'] ?? null)
        && $proof['at'] <= $now && $now - $proof['at'] < 1800
        && hash_equals(hash('sha256', $key), $proof['key_hash']);
}

/** The caller holds install.lock; rate limits survive cookie deletion. */
function pl_install_verify_key(string $expected, string $provided, int $now): void
{
    $attempts = pl_install_read_state('attempts.json');
    $started = (int) ($attempts['started'] ?? 0);
    $count = $started <= $now && $now - $started < 900 ? (int) ($attempts['count'] ?? 0) : 0;
    if ($count >= 10) {
        throw new DomainException('Too many setup attempts. Wait 15 minutes before trying again.');
    }
    if (!hash_equals($expected, $provided)) {
        pl_install_save_state(['started' => $count === 0 ? $now : $started, 'count' => $count + 1], 'attempts.json');
        throw new DomainException('That setup key or code was not accepted. Copy it again from the private file.');
    }
    pl_install_save_state(['started' => $now, 'count' => 0], 'attempts.json');
}

/** The code is created in private storage on first use and is never shown by the web page. */
function pl_install_remote_code(): string
{
    $path = pl_install_directory(true) . '/setup-code.txt';
    if (!is_file($path)) {
        try {
            pl_install_write_private($path, bin2hex(random_bytes(16)) . "\n", false);
        } catch (DomainException $error) {
            if (!is_file($path)) {
                throw $error;
            }
        }
    }
    $code = trim((string) file_get_contents($path));
    if (!preg_match('/^[a-f0-9]{32}$/D', $code)) {
        throw new DomainException('The private setup code file needs review. Delete setup-code.txt in the installation folder and try again.');
    }
    return $code;
}

/** Name private files relative to the uploaded folder, without revealing other server paths. */
function pl_install_display_path(string $path): string
{
    $root = realpath(dirname(__DIR__, 4));
    $path = str_replace('\\', '/', $path);
    $root = $root === false ? '' : rtrim(str_replace('\\', '/', $root), '/');
    if ($root !== '' && str_starts_with(strtolower($path), strtolower($root) . '/')) {
        return substr($path, strlen($root) + 1);
    }
    return basename($path) . ' in the private installation folder';
}

/** Suggest this site's own address for the owner to confirm; it is validated like typed input. */
function pl_install_suggested_public_url(array $server): string
{
    $host = (string) ($server['HTTP_HOST'] ?? '');
    if (!preg_match('/^(?:[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*|\[[0-9A-Fa-f:.]+\])(?::[0-9]{1,5})?$/D', $host)) {
        return '';
    }
    return (pl_install_secure_request($server) ? 'https://' : 'http://') . strtolower($host) . pl_base_path();
}

/**
 * A database on this same server belongs to whoever already controls the machine
 * or the hosting account, so setup accepts what XAMPP, Laragon and MAMP install
 * by default: the `root` account, and an account with no password (issue #84).
 * Refusing them only stopped people from trying PHP Ledger. A database on another
 * server still needs a dedicated account with a password, because its credentials
 * cross the network and it is no proof of controlling this website.
 *
 * @return array{host:string,port:int,database:string,user:string,password:string}
 */
function pl_install_database_input(array $input): array
{
    $get = static function (string $name) use ($input): string {
        $value = $input[$name] ?? '';
        return is_string($value) ? $value : '';
    };
    $host = trim($get('host'));
    $database = trim($get('database'));
    $user = trim($get('user'));
    $password = $get('password');
    $port = $get('port');
    $local = pl_database_local_host($host);
    if ($host === '' || strlen($host) > 253 || preg_match('/[\x00-\x20;]/', $host)
        || !preg_match('/^[A-Za-z0-9_][A-Za-z0-9_$-]{0,63}$/D', $database)
        || $user === '' || strlen($user) > 128 || preg_match('/[\x00-\x1f]/', $user)
        || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535
        || ($password === '' && !$local) || strlen($password) > 1024 || str_contains($password, "\0")) {
        throw new InvalidArgumentException('Enter the database host, port, dedicated database name, user and password from your hosting panel.');
    }
    if (!$local && strtolower($user) === 'root') {
        throw new InvalidArgumentException('A database on another server needs a dedicated database account, not the MySQL root account.');
    }
    return ['host' => $host, 'port' => (int) $port, 'database' => $database, 'user' => $user, 'password' => $password];
}

function pl_install_database_identity(array $config): string
{
    return hash('sha256', json_encode([$config['host'], (int) $config['port'], $config['database']], JSON_THROW_ON_ERROR));
}

/** Configure the existing single MeekroDB connection; no parallel database layer. */
function pl_install_connect(array $config): void
{
    pl_install_require_runtime();
    require_once dirname(__DIR__, 4) . '/vendor/autoload.php';
    DB::disconnect();
    DB::$host = $config['host'];
    DB::$port = (int) $config['port'];
    DB::$dbName = $config['database'];
    DB::$user = $config['user'];
    DB::$password = $config['password'];
    DB::$encoding = 'utf8mb4';
    DB::$nested_transactions = true;
    pl_database_use_dialect();
    DB::query("SET time_zone = '+00:00'");
}

/**
 * On this same server setup creates the empty database itself when it is missing,
 * so a local owner does not have to open phpMyAdmin first (issue #84). A database
 * on another server is never created: setup is not entitled to change a server it
 * only holds credentials for.
 *
 * @return bool true when this call created the database
 */
function pl_install_provision_database(array $config): bool
{
    if (!pl_database_local_host((string) $config['host'])) {
        return false;
    }
    pl_install_require_runtime();
    require_once dirname(__DIR__, 4) . '/vendor/autoload.php';
    DB::disconnect();
    DB::$host = $config['host'];
    DB::$port = (int) $config['port'];
    // Every authenticated account may open information_schema, whatever it is granted elsewhere.
    DB::$dbName = 'information_schema';
    DB::$user = $config['user'];
    DB::$password = $config['password'];
    DB::$encoding = 'utf8mb4';
    DB::$nested_transactions = true;
    pl_database_use_dialect();
    try {
        $exists = (int) DB::queryFirstField('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = %s', $config['database']) > 0;
    } catch (Throwable $error) {
        // The server, the port or the credentials are wrong; the connection check reports that.
        DB::disconnect();
        return false;
    }
    if ($exists) {
        DB::disconnect();
        return false;
    }
    try {
        DB::query('CREATE DATABASE %b CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci', $config['database']);
    } catch (Throwable $error) {
        // information_schema hides a database this account has no privilege on, so the
        // two cases reach here together and the message has to cover both.
        throw new DomainException('Setup could not use the database ' . $config['database'] . ' on this server: it does not exist and this account cannot create it, or the account has no access to it. Create an empty database with that exact name in phpMyAdmin or your hosting panel and give this user all privileges on it, then check again.');
    } finally {
        DB::disconnect();
    }
    return true;
}

/** Reject takeover and unknown databases even when the host's setup key is valid. */
function pl_install_check_target(array $config, array $state): array
{
    pl_install_connect($config);
    $schema = pl_install_database_check();
    if ($schema['status'] === 'empty'
        && ((int) DB::queryFirstField('SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema = DATABASE()') > 0
            || (int) DB::queryFirstField('SELECT COUNT(*) FROM information_schema.events WHERE event_schema = DATABASE()') > 0)) {
        throw new DomainException('Use a separate empty database without stored routines or events.');
    }
    $identity = pl_install_database_identity($config);
    if ($schema['status'] !== 'empty' && (!isset($state['database_id']) || !hash_equals((string) $state['database_id'], $identity))) {
        throw new DomainException('This database is not an empty installation target. Existing installations must use the upgrade procedure.');
    }
    if (isset($state['database_id']) && !hash_equals((string) $state['database_id'], $identity)) {
        throw new DomainException('This setup is already bound to another database. The existing installation was preserved.');
    }
    $tables = DB::queryFirstColumn('SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema = DATABASE()');
    if (in_array('pl_users', $tables, true) && (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_users') > 0
        && !isset($state['owner_email'])) {
        throw new DomainException('This database already has users. Browser installation cannot claim an existing application.');
    }
    return $schema;
}

/** Existing configuration is never replaced by browser installation. */
function pl_install_check_existing_configuration(array $runtime): void
{
    $path = pl_install_config_path();
    if (is_file($path)) {
        try {
            $existing = require $path;
        } catch (Throwable $error) {
            throw new RuntimeException('The private configuration could not be loaded.');
        }
        if (!is_array($existing)) {
            throw new DomainException('The existing private configuration needs operator review.');
        }
        foreach ($runtime as $key => $value) {
            if (!array_key_exists($key, $existing) || (string) $existing[$key] !== (string) $value) {
                throw new DomainException('The existing private configuration was preserved. Use its database settings or review it in your hosting panel.');
            }
        }
    }
}

/** Even if setup markers are lost, an existing configured application cannot be claimed. */
function pl_install_refuse_existing_application(array $state): void
{
    if ($state !== []) {
        return;
    }
    $path = pl_install_config_path();
    if (!is_file($path) && (getenv('PL_DB_PASSWORD') === false || getenv('PL_DB_PASSWORD') === '')) {
        return;
    }
    $config = ['host' => getenv('PL_DB_HOST') ?: '127.0.0.1', 'port' => (int) (getenv('PL_DB_PORT') ?: 3306),
        'database' => getenv('PL_DB_NAME') ?: 'phpledger', 'user' => getenv('PL_DB_USER') ?: 'phpledger', 'password' => getenv('PL_DB_PASSWORD') ?: ''];
    if (is_file($path)) {
        try {
            $override = require $path;
        } catch (Throwable $error) {
            throw new RuntimeException('The private configuration could not be loaded.');
        }
        if (!is_array($override)) {
            throw new DomainException('The existing private configuration needs operator review.');
        }
        $config = array_replace($config, $override);
    }
    pl_install_connect($config);
    if (pl_install_database_check()['status'] !== 'empty') {
        throw new DomainException('This configured application already contains a schema. Use its sign-in or upgrade procedure; browser setup cannot replace it.');
    }
}

function pl_install_config_document(array $config): string
{
    return "<?php\ndeclare(strict_types=1);\nreturn " . var_export($config, true) . ";\n";
}

/** Preflight the normal runtime identity after migration, before it is persisted. */
function pl_install_verify_runtime(array $config): void
{
    pl_install_connect($config);
    if (pl_install_database_check()['status'] !== 'current') {
        throw new DomainException('Complete all migrations before saving runtime configuration.');
    }
    // Check the view definers using the normal runtime identity, not only schema credentials.
    $views = DB::queryFirstColumn("SELECT TABLE_NAME FROM information_schema.views WHERE table_schema = DATABASE()");
    foreach ($views as $view) {
        DB::query('SELECT * FROM %b LIMIT 0', $view);
    }
    DB::startTransaction();
    try {
        // A no-op DML check verifies common runtime grants without adding or changing an account.
        DB::query('UPDATE pl_users SET is_active = is_active WHERE id = -1');
        DB::query('DELETE FROM pl_login_attempts WHERE subject_hash = %s', str_repeat('0', 64));
        DB::rollback();
    } catch (Throwable $error) {
        DB::rollback();
        throw $error;
    }
}

/**
 * Complete setup through the existing account service; retries cannot replace an account.
 * The owner chooses a username as well as an email address and may sign in with either.
 */
function pl_install_finish(array $schemaConfig, array $runtimeConfig, string $email, string $name, string $password, array $state, string $username = '', ?array $logo = null): array
{
    require_once __DIR__ . '/auth_functions.php';
    pl_install_check_target($schemaConfig, $state);
    pl_install_verify_runtime($runtimeConfig);
    pl_install_check_existing_configuration($runtimeConfig);
    pl_install_oauth_keys((string) $runtimeConfig['oauth_key_directory']);
    if (!is_file(pl_install_config_path())) {
        throw new DomainException('Save the private configuration file before creating the first account.');
    }
    $email = strtolower(trim($email));
    $name = trim($name);
    if (isset($state['owner_email']) && (!is_string($state['owner_email']) || !hash_equals($state['owner_email'], $email))) {
        throw new DomainException('Resume with the original installation account. Its identity cannot be replaced.');
    }
    // Validate before writing an identity intent or touching a user row.
    if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false || $name === ''
        || !mb_check_encoding($name, 'UTF-8') || mb_strlen($name, 'UTF-8') > 120) {
        throw new InvalidArgumentException('Enter a valid email address and a name up to 120 characters.');
    }
    $username = pl_normalize_username($username);
    if (isset($state['owner_username']) && (!is_string($state['owner_username']) || !hash_equals($state['owner_username'], $username))) {
        throw new DomainException('Resume with the original installation account. Its identity cannot be replaced.');
    }
    pl_hash_password($password);
    $state['owner_email'] = $email;
    $state['owner_username'] = $username;
    $state['phase'] = 'account';
    pl_install_save_state($state);
    $users = DB::query('SELECT id, email, username, password_hash, is_active FROM pl_users');
    if ($users === []) {
        $id = pl_create_user($email, $name, $password, $username);
    } elseif (count($users) === 1 && $users[0]['email'] === $email && $users[0]['username'] === $username && (int) $users[0]['is_active'] === 1
        && pl_verify_password($password, $users[0]['password_hash'])) {
        $id = (int) $users[0]['id'];
    } else {
        throw new DomainException('The existing account was preserved. Resume with its original credentials or ask the installation operator.');
    }
    if ($logo !== null) {
        require_once __DIR__ . '/branding_functions.php';
        pl_logo_save($logo, $id);
    }
    $operatorKey = pl_install_directory() . '/operator.key';
    if (!is_file($operatorKey)) {
        pl_install_write_private($operatorKey, bin2hex(random_bytes(32)) . "\n", false);
    }
    $receipt = ['format' => 1, 'database_id' => pl_install_database_identity($runtimeConfig), 'initial_owner_id' => $id,
        'completed_at' => gmdate('c'), 'schema_receipts' => (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_schema_migrations')];
    pl_install_save_state($receipt, 'installed.json');
    $state['phase'] = 'complete';
    pl_install_save_state($state);
    // Setup is closed now; the one-time remote-database code and probe files are no longer needed.
    foreach (['setup-code.txt', 'exposure-probe.txt', 'exposure-probe.key', 'exposure.json'] as $leftover) {
        if (is_file(pl_install_directory() . '/' . $leftover)) {
            @unlink(pl_install_directory() . '/' . $leftover);
        }
    }
    return ['id' => $id, 'email' => $email, 'display_name' => $name];
}

/** HTTPS as PHP sees it. Plain HTTP is accepted only from this same computer (pl_web_local_http). */
function pl_install_secure_request(array $server): bool
{
    return (!empty($server['HTTPS']) && strtolower((string) $server['HTTPS']) !== 'off') || (int) ($server['SERVER_PORT'] ?? 0) === 443;
}

function pl_install_http(): never
{
    require_once __DIR__ . '/web_functions.php';
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; font-src 'self'; script-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
    header('Content-Type: text/html; charset=utf-8');
    $error = '';
    $view = 'locked';
    $state = [];
    $schema = null;
    $lock = null;
    $applicationLock = null;
    $localHttp = false;
    $insecureHttp = false;
    $exposureWarning = false;
    $remoteProof = false;
    $setupCodePath = '';
    $createdDatabase = '';
    try {
        if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'POST'], true)) {
            http_response_code(405);
            header('Allow: GET, POST');
            throw new DomainException('Use the installation form to continue.');
        }
        if (!pl_install_available()) {
            http_response_code(404);
        } else {
            $view = 'blocked';
            $secure = pl_install_secure_request($_SERVER);
            $localHttp = !$secure && pl_web_local_http($_SERVER);
            // Plain HTTP used to stop setup here. It now warns and continues (owner decision,
            // 20 September 2026, issue #84): a site without a certificate yet is still a site,
            // and every feature that needs one reports its own unavailability.
            $insecureHttp = !$secure && !$localHttp;
            // Before any secret exists, confirm that private folders cannot be downloaded from this website.
            $exposure = pl_install_exposure_status($_SERVER);
            if ($exposure === 'exposed') {
                http_response_code(503);
                throw new DomainException('This web server lets visitors download files from PHP Ledger\'s private folders, so setup has stopped. It usually means .htaccess files are ignored (for example on Nginx). Point the website\'s document root at the www/phpledger/public folder, or ask your host to enable .htaccess rules, then reload this page.');
            }
            $exposureWarning = $exposure === 'unknown';
            pl_session_start($secure);
            $key = pl_install_setup_key();
            $view = $key === null ? 'database' : 'key';
            $lock = pl_install_operation_lock();
            $state = pl_install_read_state();
            $authorized = $key === null || pl_install_authorized($key, $_SESSION, time());
            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
                pl_require_csrf(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null);
                $action = pl_web_text($_POST, 'action');
                if ($action === 'unlock' && $key !== null) {
                    pl_install_verify_key($key, pl_web_text($_POST, 'setup_key'), time());
                    if (!session_regenerate_id(true)) {
                        throw new RuntimeException('Setup session could not be renewed.');
                    }
                    $_SESSION['install_proof'] = ['key_hash' => hash('sha256', $key), 'at' => time()];
                    pl_csrf_rotate();
                    $authorized = true;
                } elseif (!$authorized) {
                    throw new DomainException('Enter the private setup key to resume this installation.');
                }
            }
            if ($authorized) {
                $view = 'database';
                pl_install_require_runtime();
                pl_install_refuse_existing_application($state);
                $sessionConfig = $_SESSION['install_database'] ?? null;
                $runtimeConfig = $_SESSION['install_runtime'] ?? null;
                if (is_array($sessionConfig) && is_array($runtimeConfig)) {
                    $view = match ($state['phase'] ?? '') {
                        'review' => 'review', 'migrating' => 'migrating', 'configuration' => 'configuration', 'account' => 'account', default => 'database',
                    };
                }
                if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
                    $action = pl_web_text($_POST, 'action');
                    if ($action === 'database') {
                        $view = 'database';
                        $candidate = pl_install_database_input($_POST);
                        if ($key === null && !pl_database_local_host($candidate['host'])) {
                            $code = pl_install_remote_code();
                            $setupCodePath = pl_install_display_path(pl_install_directory() . '/setup-code.txt');
                            if (!pl_install_authorized($code, $_SESSION, time())) {
                                $remoteProof = true;
                                $provided = pl_web_text($_POST, 'setup_code');
                                if ($provided === '') {
                                    throw new DomainException('This database is on another server, so setup needs one more check. Open ' . $setupCodePath . ' with your hosting file manager, copy the code inside and paste it below, together with the database password.');
                                }
                                pl_install_verify_key($code, $provided, time());
                                if (!session_regenerate_id(true)) {
                                    throw new RuntimeException('Setup session could not be renewed.');
                                }
                                $_SESSION['install_proof'] = ['key_hash' => hash('sha256', $code), 'at' => time()];
                                pl_csrf_rotate();
                                $remoteProof = false;
                            }
                        }
                        // Setup asked for an optional second, restricted database account until
                        // 20 September 2026 (owner decision B24). Hosting panels grant every
                        // user all privileges on a database, so the second account was usually
                        // identical; the views and triggers keep the installing account as their
                        // definer either way; and a narrower identity turns off the updater's
                        // automatic recovery. Hardening after installation belongs to the operator
                        // and to INSTALL.md, not to a checkbox on a first install.
                        $runtime = $candidate;
                        $runtime['public_url'] = pl_install_public_url(pl_web_text($_POST, 'public_url'));
                        $runtime['oauth_key_directory'] = pl_install_private_path((string) (getenv('PL_OAUTH_KEY_DIRECTORY') ?: pl_install_directory() . '/oauth'));
                        $runtime['installation_directory'] = pl_install_directory();
                        pl_install_check_existing_configuration($runtime);
                        // Never create a database for a setup already bound to a different one.
                        if ((!isset($state['database_id']) || hash_equals((string) $state['database_id'], pl_install_database_identity($candidate)))
                            && pl_install_provision_database($candidate)) {
                            $createdDatabase = $candidate['database'];
                        }
                        $schema = pl_install_check_target($candidate, $state);
                        if ($schema['status'] === 'empty' && $state === []) {
                            $state = ['format' => 1, 'database_id' => pl_install_database_identity($candidate), 'phase' => 'review', 'created_at' => gmdate('c')];
                            pl_install_save_state($state);
                            // This browser now owns the setup: renew its session identifier, as a sign-in would.
                            if ($key === null && !session_regenerate_id(true)) {
                                throw new RuntimeException('Setup session could not be renewed.');
                            }
                        }
                        $_SESSION['install_database'] = $sessionConfig = $candidate;
                        $_SESSION['install_runtime'] = $runtimeConfig = $runtime;
                    } elseif (in_array($action, ['migrate', 'save_config', 'download_config', 'finish'], true)) {
                        if (!is_array($sessionConfig) || !is_array($runtimeConfig)) {
                            throw new DomainException('Re-enter the database credentials to resume. No stored migration receipt will be changed.');
                        }
                        $applicationLock = pl_install_operation_lock('application.lock');
                        $schema = pl_install_check_target($sessionConfig, $state);
                        if ($action === 'migrate') {
                            if (!in_array($state['phase'] ?? '', ['review', 'migrating', 'configuration'], true)) {
                                throw new DomainException('This installation state needs operator review.');
                            }
                            $state['phase'] = 'migrating';
                            pl_install_save_state($state);
                            pl_migrate(1);
                            $schema = pl_install_database_check();
                            if ($schema['status'] === 'current') {
                                $state['phase'] = 'configuration';
                                pl_install_save_state($state);
                            }
                        } elseif ($action === 'save_config' || $action === 'download_config') {
                            $runtimeConfig['public_url'] = pl_install_public_url(pl_web_text($_POST, 'public_url', (string) ($runtimeConfig['public_url'] ?? '')));
                            $runtimeConfig['oauth_key_directory'] = pl_install_private_path((string) (getenv('PL_OAUTH_KEY_DIRECTORY') ?: ($runtimeConfig['oauth_key_directory'] ?? pl_install_directory() . '/oauth')));
                            $runtimeConfig['installation_directory'] = pl_install_directory();
                            $_SESSION['install_runtime'] = $runtimeConfig;
                            pl_install_verify_runtime($runtimeConfig);
                            pl_install_check_existing_configuration($runtimeConfig);
                            pl_install_oauth_keys((string) $runtimeConfig['oauth_key_directory']);
                            if ($action === 'download_config') {
                                header('Content-Type: application/octet-stream');
                                header('Content-Disposition: attachment; filename="config.local.php"');
                                echo pl_install_config_document($runtimeConfig);
                                exit;
                            }
                            if (!is_file(pl_install_config_path())) {
                                pl_install_write_private(pl_install_config_path(), pl_install_config_document($runtimeConfig), false);
                            }
                            $state['phase'] = 'account';
                            pl_install_save_state($state);
                        } else {
                            $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
                            if (!hash_equals($password, is_string($_POST['password_confirm'] ?? null) ? $_POST['password_confirm'] : '')) {
                                throw new InvalidArgumentException('The two passwords do not match. Type the same password in both fields.');
                            }
                            require_once __DIR__ . '/branding_functions.php';
                            $logo = pl_logo_from_upload($_FILES['logo'] ?? null);
                            $user = pl_install_finish($sessionConfig, $runtimeConfig, pl_web_text($_POST, 'email'), pl_web_text($_POST, 'name'), $password, $state, pl_web_text($_POST, 'username'), $logo);
                            pl_login_session($user); // Clears temporary schema/runtime credentials and setup proof.
                            header('Location: ' . pl_url('/onboarding'), true, 303);
                            exit;
                        }
                    } elseif ($action !== 'unlock') {
                        throw new DomainException('Choose an action from the installation form.');
                    }
                }
                if (is_array($sessionConfig) && is_array($runtimeConfig)) {
                    $schema = pl_install_check_target($sessionConfig, $state);
                    $view = $schema['status'] === 'current' ? (is_file(pl_install_config_path()) ? 'account' : 'configuration')
                        : (($state['phase'] ?? '') === 'review' ? 'review' : 'migrating');
                }
            }
        }
    } catch (InvalidArgumentException | DomainException $exception) {
        $error = $exception->getMessage();
        if (http_response_code() < 400) {
            http_response_code(400);
        }
    } catch (Throwable $exception) {
        // Database drivers may include credentials or raw SQL; never render/log their message.
        $error = 'Setup could not complete this step. Check database credentials, grants, private storage and migration status in your hosting panel. Existing state was preserved.';
        http_response_code(503);
    } finally {
        if (is_resource($applicationLock)) {
            flock($applicationLock, LOCK_UN);
            fclose($applicationLock);
        }
        if (is_resource($lock)) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
    require dirname(__DIR__, 2) . '/templates/views/install.php';
    exit;
}
