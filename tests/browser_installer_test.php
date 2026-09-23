<?php
declare(strict_types=1);

// Standalone destructive-fixture test: only its random schema/user/private directory are removed.
if (PHP_SAPI !== 'cli' || getenv('PL_ENV') !== 'test' || getenv('PL_DB_HOST') !== 'db_test') {
    fwrite(STDERR, "Run this fixture in the local test container against db_test only.\n");
    exit(2);
}
$packageRoot = (string) (getenv('PL_INSTALL_TEST_PACKAGE_ROOT') ?: dirname(__DIR__));
require $packageRoot . '/vendor/autoload.php';
require $packageRoot . '/www/phpledger/includes/functions/install_web_functions.php';
require_once $packageRoot . '/www/phpledger/includes/functions/auth_functions.php';

$fixture = 'pl_install_' . bin2hex(random_bytes(6));
$temporary = sys_get_temp_dir() . '/' . $fixture;
$databasePassword = bin2hex(random_bytes(24));
$setupKey = bin2hex(random_bytes(32));
$serve = ($argv[1] ?? '') === '--serve';
if ($serve) {
    $databasePassword = 'Sample browser installer DB fixture 2026!';
    $setupKey = 'sample-browser-installer-ui-fixture-key-2026';
}
$ownerPassword = 'Sample installer owner passphrase 123!';
$rootConfig = ['host' => 'db_test', 'port' => 3306, 'database' => 'information_schema', 'user' => 'root', 'password' => 'local-test-root-only', 'db_prefix' => 'web_'];
$config = ['host' => 'db_test', 'port' => 3306, 'database' => $fixture, 'user' => $fixture, 'password' => $databasePassword, 'db_prefix' => 'web_'];
$server = null;
$checks = 0;
mkdir($temporary, 0700, true);
putenv('PL_INSTALL_DIRECTORY=' . $temporary . '/installation');
putenv('PL_INSTALL_CONFIG_PATH=' . $temporary . '/config.local.php');
putenv('PL_OAUTH_KEY_DIRECTORY=' . $temporary . '/oauth');
$runtimeConfig = pl_database_configuration($config) + ['public_url' => 'https://books.example.invalid', 'oauth_key_directory' => $temporary . '/oauth', 'installation_directory' => $temporary . '/installation'];

function installer_assert(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}

function installer_rejects(callable $action, string $message): void
{
    try {
        $action();
    } catch (DomainException | InvalidArgumentException $error) {
        installer_assert(true, $message);
        return;
    }
    throw new RuntimeException($message);
}

function installer_http(string $url, ?array $data, string $cookieFile, array $headers = []): array
{
    $curl = curl_init($url);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEFILE => $cookieFile, CURLOPT_COOKIEJAR => $cookieFile, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 60]);
    if ($headers !== []) {
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    }
    if ($data !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data));
    }
    $raw = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $headerLength = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    curl_close($curl);
    if (!is_string($raw)) {
        throw new RuntimeException('Local installer HTTP request failed.');
    }
    return ['status' => $status, 'headers' => substr($raw, 0, $headerLength), 'body' => substr($raw, $headerLength)];
}

function installer_token(array $response): string
{
    if (!preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $response['body'], $match)) {
        throw new RuntimeException('Installer response did not contain a CSRF token.');
    }
    return $match[1];
}

function installer_remove_fixture(string $directory, string $expected): void
{
    $resolved = realpath($directory);
    if ($resolved === false || $resolved !== realpath($expected) || !str_starts_with(basename($resolved), 'pl_install_')) {
        throw new RuntimeException('Refusing to remove an unverified fixture directory.');
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        if ($file->isDir() && !$file->isLink()) {
            rmdir($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }
    rmdir($resolved);
}

try {
    pl_install_connect($rootConfig);
    DB::query('CREATE DATABASE %b CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci', $fixture);
    DB::query('CREATE USER %s@%s IDENTIFIED BY %s', $fixture, '%', $databasePassword);
    DB::query('GRANT ALL PRIVILEGES ON %b.* TO %s@%s', $fixture, $fixture, '%');
    $environment = getenv();
    if (!is_array($environment)) {
        throw new RuntimeException('Test environment unavailable.');
    }
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($socket === false) {
        throw new RuntimeException('Cannot reserve a local test port.');
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    if ($serve) {
        $address = '0.0.0.0:18219';
    }
    $environment = array_replace($environment, ['PL_DB_PASSWORD' => '', 'PL_SETUP_KEY' => $setupKey, 'PL_INSTALL_ALLOW_HTTP' => '1']);
    $public = $packageRoot . '/www/phpledger/public';
    $router = $temporary . '/router.php';
    file_put_contents($router, '<?php $public = ' . var_export($public, true) . '; $path = parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH); '
        . '$file = realpath($public . $path); if (is_string($file) && str_starts_with($file, realpath($public) . DIRECTORY_SEPARATOR) && is_file($file) && pathinfo($file, PATHINFO_EXTENSION) !== "php") { return false; } require $public . "/index.php";');
    $server = proc_open([PHP_BINARY, '-S', $address, '-t', $public, $router],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $temporary . '/server.log', 'a'], 2 => ['file', $temporary . '/server.log', 'a']], $pipes, $packageRoot, $environment);
    if (!is_resource($server)) {
        throw new RuntimeException('Could not start the isolated HTTP fixture.');
    }
    $url = 'http://' . ($serve ? '127.0.0.1:18219' : $address) . '/install';
    $cookie = $temporary . '/cookies.txt';
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $probe = @stream_socket_client('tcp://' . ($serve ? '127.0.0.1:18219' : $address), $errno, $errstr, 0.1);
        if ($probe !== false) {
            fclose($probe);
            break;
        }
        usleep(100000);
    }
    if ($serve) {
        echo json_encode(['url' => 'http://127.0.0.1:18219/install', 'database' => $fixture, 'user' => $fixture,
            'host' => 'db_test', 'port' => 3306, 'stop_file' => $temporary . '/finish-ui'], JSON_THROW_ON_ERROR) . "\n";
        fflush(STDOUT);
        $deadline = time() + 7200;
        while (!is_file($temporary . '/finish-ui') && time() < $deadline) {
            clearstatcache();
            usleep(250000);
        }
        echo "Sample installer UI fixture stopped; removing only its temporary schema/user/files.\n";
    } else {
    $response = installer_http($url, null, $cookie);
    installer_assert($response['status'] === 200 && str_contains($response['body'], 'Private setup key'), 'Host-controlled setup entry was not available.');
    installer_assert(!str_contains($response['body'], $setupKey), 'Setup key leaked in the page.');
    installer_rejects(fn () => pl_install_private_path($public . '/exposed-config.php'), 'Public configuration path was accepted.');
    installer_rejects(fn () => pl_install_public_url('https://books.example.invalid/?token=secret'), 'Public URL query was accepted.');
    installer_rejects(fn () => pl_install_public_url('https://user:password@books.example.invalid'), 'Credential-bearing public URL was accepted.');
    installer_assert(!pl_install_authorized($setupKey, ['install_proof' => ['key_hash' => hash('sha256', $setupKey), 'at' => time() - 1800]], time()), 'Expired installer proof was accepted.');
    $csrf = installer_token($response);
    $denied = installer_http($url, ['action' => 'unlock', 'setup_key' => $setupKey], $cookie);
    installer_assert($denied['status'] === 400, 'Missing CSRF was accepted.');
    $denied = installer_http($url, ['csrf_token' => $csrf, 'action' => 'unlock', 'setup_key' => 'wrong-key'], $cookie);
    installer_assert($denied['status'] === 400 && str_contains($denied['body'], 'not accepted'), 'Wrong setup proof was accepted.');
    $response = installer_http($url, ['csrf_token' => $csrf, 'action' => 'unlock', 'setup_key' => $setupKey], $cookie);
    installer_assert($response['status'] === 200 && str_contains($response['body'], 'This installs PHP Ledger on this server'), 'Correct host proof did not unlock setup.');
    $csrf = installer_token($response);
    $lock = pl_install_operation_lock();
    $denied = installer_http($url, null, $cookie);
    installer_assert($denied['status'] === 400 && str_contains($denied['body'], 'Another installation'), 'Concurrent installer lock was not enforced.');
    flock($lock, LOCK_UN);
    fclose($lock);
    $input = array_merge($config, ['port' => '3306', 'public_url' => $runtimeConfig['public_url'], 'action' => 'database', 'csrf_token' => $csrf]);
    pl_install_connect($config);
    DB::query('CREATE TABLE unrelated_fixture (id INT PRIMARY KEY)');
    DB::query('INSERT INTO unrelated_fixture VALUES (42)');
    DB::query('CREATE TABLE pl_unrecognized (id INT PRIMARY KEY)');
    $denied = installer_http($url, $input, $cookie);
    installer_assert($denied['status'] === 400 && str_contains($denied['body'], 'separate empty database'), 'Unknown existing database was not rejected.');
    installer_assert((int) DB::queryFirstField("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'unrelated_fixture'") === 1, 'Existing table was changed.');
    DB::query('DROP TABLE pl_unrecognized');
    $response = installer_http($url, $input, $cookie);
    installer_assert($response['status'] === 200 && str_contains($response['body'], 'Database connected.'), 'Empty target namespace was not accepted for review.');
    installer_assert((int) DB::queryFirstField('SELECT id FROM unrelated_fixture') === 42, 'Neighbor table changed during namespace installation.');
    $state = pl_install_read_state();
    $prefixTamper = $input; $prefixTamper['db_prefix'] = 'different_';
    $denied = installer_http($url, $prefixTamper, $cookie);
    installer_assert($denied['status'] === 400, 'Bound installer accepted another namespace.');
    installer_assert(!str_contains(json_encode($state), $databasePassword), 'Database password was saved in durable setup state.');
    $bad = $input;
    $bad['password'] = 'Sample invalid database password';
    $denied = installer_http($url, $bad, $cookie);
    installer_assert($denied['status'] === 503 && !str_contains($denied['body'], $bad['password']) && !str_contains($denied['body'], 'Stack trace'), 'Connection failure leaked credentials or was accepted.');
    for ($batch = 0; $batch < 40; $batch++) {
        $response = installer_http($url, ['action' => 'migrate', 'csrf_token' => $csrf], $cookie);
        if ($response['status'] !== 200) {
            pl_install_connect($config);
            pl_migrate(pl_install_migration_batch(count(pl_install_migration_versions())));
        }
        preg_match('/class="alert alert-danger"[^>]*><p>(.*?)<\/p>/s', $response['body'], $migrationError);
        installer_assert($response['status'] === 200, 'A fresh schema migration batch failed: ' . strip_tags($migrationError[1] ?? 'no detail'));
        if ($batch === 0) {
            pl_install_connect($config);
            $firstReceipt = DB::queryFirstRow('SELECT * FROM pl_schema_migrations ORDER BY version LIMIT 1');
            // A receipt that recorded no progress leaves an unknown database: still a matter for the operator.
            DB::update('pl_schema_migrations', ['status' => 'applying', 'statements_done' => null], 'version=%s', $firstReceipt['version']);
            $interrupted = installer_http($url, ['action' => 'migrate', 'csrf_token' => $csrf], $cookie);
            installer_assert($interrupted['status'] === 400 && str_contains($interrupted['body'], 'incomplete'), 'Interrupted DDL without recorded progress did not stop installation.');
            installer_assert(DB::queryFirstField('SELECT status FROM pl_schema_migrations WHERE version=%s', $firstReceipt['version']) === 'applying', 'Interrupted receipt was silently repaired.');
            // A receipt whose statements all ran, losing only its closing status update, completes on retry.
            DB::update('pl_schema_migrations', ['statements_done' => $firstReceipt['statements_done']], 'version=%s', $firstReceipt['version']);
            $resumed = installer_http($url, ['action' => 'migrate', 'csrf_token' => $csrf], $cookie);
            installer_assert($resumed['status'] === 200, 'A recorded interruption did not resume.');
            installer_assert(DB::queryFirstField('SELECT status FROM pl_schema_migrations WHERE version=%s', $firstReceipt['version']) === 'applied', 'A resumed receipt was not completed.');
            // Only the deliberately corrupted random fixture receipt is restored here.
            DB::update('pl_schema_migrations', ['status' => $firstReceipt['status'], 'statements_done' => $firstReceipt['statements_done']], 'version=%s', $firstReceipt['version']);
        }
        if (str_contains($response['body'], 'Save private configuration')) {
            break;
        }
    }
    installer_assert($batch < 40, 'Fresh installation did not finish the complete migration chain.');
    pl_install_connect($config);
    installer_assert(pl_install_database_check()['status'] === 'current', 'Browser schema is not current.');
    $receipts = DB::query('SELECT * FROM pl_schema_migrations ORDER BY version');
    $replay = pl_migrate();
    installer_assert($replay['applied'] === [] && DB::query('SELECT * FROM pl_schema_migrations ORDER BY version') === $receipts, 'Browser/CLI schema replay changed receipts.');
    $download = installer_http($url, ['action' => 'download_config', 'csrf_token' => $csrf], $cookie);
    installer_assert($download['status'] === 200 && str_contains($download['headers'], 'attachment; filename="config.local.php"') && $download['body'] === pl_install_config_document($runtimeConfig), 'Protected configuration download did not preserve the normal configuration contract.');
    $keyHashes = [];
    foreach (['private.key', 'public.key', 'encryption.key'] as $name) {
        $keyPath = $temporary . '/oauth/' . $name;
        installer_assert(is_file($keyPath) && ((int) fileperms($keyPath) & 0077) === 0, 'OAuth key missing or permissions too broad.');
        $keyHashes[$name] = hash_file('sha256', $keyPath);
    }
    installer_assert(!pl_install_oauth_keys($temporary . '/oauth'), 'Existing matching keys were unexpectedly replaced.');
    foreach ($keyHashes as $name => $hash) {
        installer_assert(hash_file('sha256', $temporary . '/oauth/' . $name) === $hash, 'OAuth key changed during replay.');
    }
    chmod($temporary . '/oauth/encryption.key', 0644);
    clearstatcache();
    installer_rejects(fn () => pl_install_oauth_keys($temporary . '/oauth'), 'Publicly readable encryption key was accepted.');
    installer_assert(hash_file('sha256', $temporary . '/oauth/encryption.key') === $keyHashes['encryption.key'], 'Permission failure changed an existing OAuth key.');
    chmod($temporary . '/oauth/encryption.key', 0600);
    clearstatcache();
    $response = installer_http($url, ['action' => 'save_config', 'csrf_token' => $csrf], $cookie);
    installer_assert($response['status'] === 200 && str_contains($response['body'], 'Create your sign-in account'), 'Private configuration could not be saved.');
    installer_assert((require pl_install_config_path()) === $runtimeConfig, 'Private configuration differs from reviewed settings.');
    $owner = ['action' => 'finish', 'csrf_token' => $csrf, 'email' => 'installer@example.invalid', 'username' => 'Installer.Owner', 'name' => 'Installer Fixture', 'password' => $ownerPassword, 'password_confirm' => $ownerPassword];
    $denied = installer_http($url, array_replace($owner, ['password' => 'short', 'password_confirm' => 'short']), $cookie);
    installer_assert($denied['status'] === 400 && (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_users') === 0, 'Invalid account input created a user.');
    $denied = installer_http($url, array_replace($owner, ['password_confirm' => $ownerPassword . ' typo']), $cookie);
    installer_assert($denied['status'] === 400 && str_contains($denied['body'], 'do not match') && (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_users') === 0, 'Mismatched password confirmation created a user.');
    $denied = installer_http($url, array_replace($owner, ['username' => 'no spaces allowed']), $cookie);
    installer_assert($denied['status'] === 400 && str_contains($denied['body'], 'username') && (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_users') === 0, 'Invalid username created a user.');
    $response = installer_http($url, $owner, $cookie);
    installer_assert($response['status'] === 200 && str_contains($response['body'], 'Your installation is complete.'), 'Valid account did not finish setup.');
    installer_assert(DB::queryFirstField('SELECT username FROM pl_users') === 'installer.owner', 'The chosen username was not saved in lowercase.');
    installer_assert(pl_authenticate('installer.owner', $ownerPassword, 'installer-fixture')['email'] === 'installer@example.invalid'
        && pl_authenticate('INSTALLER@example.invalid', $ownerPassword, 'installer-fixture')['email'] === 'installer@example.invalid', 'The owner cannot sign in with both the username and the email.');
    $discovery = installer_http(str_replace('/install', '/.well-known/oauth-authorization-server', $url), null, $cookie);
    installer_assert($discovery['status'] === 403, 'OAuth discovery accepted an unconfigured Host header.');
    $discovery = installer_http(str_replace('/install', '/.well-known/oauth-authorization-server', $url), null, $cookie, ['Host: books.example.invalid']);
    $discoveryBody = json_decode($discovery['body'], true);
    installer_assert($discovery['status'] === 200 && is_array($discoveryBody) && ($discoveryBody['issuer'] ?? '') === $runtimeConfig['public_url'], 'OAuth discovery did not use browser-installed public URL configuration.');
    installer_assert((int) DB::queryFirstField('SELECT COUNT(*) FROM pl_users') === 1 && (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_companies') === 0, 'Installer created unexpected users or business data.');
    installer_assert(pl_install_read_state('installed.json')['initial_owner_id'] > 0 && strlen(trim((string) file_get_contents(pl_install_directory() . '/operator.key'))) === 64, 'Completed receipt/operator authority was not private and durable.');
    installer_assert(!str_contains($response['body'], $ownerPassword), 'Owner password leaked in response.');
    $closed = installer_http($url, null, $cookie);
    installer_assert($closed['status'] === 404 && str_contains($closed['body'], 'Installation is locked'), 'Completed installer reopened.');
    $closed = installer_http($url, array_replace($owner, ['email' => 'attacker@example.invalid', 'username' => 'second', 'name' => 'Second']), $cookie);
    installer_assert($closed['status'] === 404 && (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_users') === 1, 'Repeat completion changed the initial account.');
    // Deleting the finish marker alone must still fail closed against an existing account.
    unlink(pl_install_directory() . '/installed.json');
    unlink(pl_install_directory() . '/setup.json');
    $response = installer_http($url, null, $cookie);
    $csrf = installer_token($response);
    $response = installer_http($url, ['action' => 'unlock', 'csrf_token' => $csrf, 'setup_key' => $setupKey], $cookie);
    $csrf = installer_token($response);
    $denied = installer_http($url, array_replace($input, ['csrf_token' => $csrf]), $cookie);
    installer_assert($denied['status'] === 400 && (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_users') === 1, 'Deleting lock state enabled existing-database takeover.');
    $logs = (string) file_get_contents($temporary . '/server.log');
    installer_assert(!str_contains($logs, $databasePassword) && !str_contains($logs, $setupKey) && !str_contains($logs, $ownerPassword)
        && !str_contains($logs, 'BEGIN PRIVATE KEY') && !str_contains($logs, (string) file_get_contents($temporary . '/oauth/encryption.key')), 'HTTP logs contain setup secrets.');
    echo "Browser installer: {$checks} checks passed, including complete disposable-schema installation and locked re-entry.\n";
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Browser installer fixture failed: ' . $error->getMessage() . "\n");
    $failed = true;
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    pl_install_connect($rootConfig);
    if (preg_match('/^pl_install_[a-f0-9]{12}$/D', $fixture)) {
        DB::query('DROP DATABASE IF EXISTS %b', $fixture);
        DB::query('DROP USER IF EXISTS %s@%s', $fixture, '%');
    }
    installer_remove_fixture($temporary, $temporary);
}
exit(isset($failed) ? 1 : 0);
