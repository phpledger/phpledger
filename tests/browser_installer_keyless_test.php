<?php
declare(strict_types=1);

// Standalone destructive-fixture test for WordPress-style setup without a setup key.
// Only its random schemas, users and private directories are created and removed.
if (PHP_SAPI !== 'cli' || getenv('PL_ENV') !== 'test' || getenv('PL_DB_HOST') !== 'db_test') {
    fwrite(STDERR, "Run this fixture in the local test container against db_test only.\n");
    exit(2);
}
$packageRoot = (string) (getenv('PL_INSTALL_TEST_PACKAGE_ROOT') ?: dirname(__DIR__));
require $packageRoot . '/vendor/autoload.php';
require $packageRoot . '/www/phpledger/includes/functions/install_web_functions.php';
require_once $packageRoot . '/www/phpledger/includes/functions/auth_functions.php';

$rootConfig = ['host' => 'db_test', 'port' => 3306, 'database' => 'information_schema', 'user' => 'root', 'password' => 'local-test-root-only'];
$ownerPassword = 'Sample keyless owner passphrase 318!';
$checks = 0;

function keyless_assert(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}

function keyless_http(string $url, ?array $data, string $cookieFile): array
{
    $curl = curl_init($url);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEFILE => $cookieFile, CURLOPT_COOKIEJAR => $cookieFile, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 60]);
    if ($data !== null) {
        $multipart = array_filter($data, static fn ($value): bool => $value instanceof CURLFile) !== [];
        curl_setopt($curl, CURLOPT_POSTFIELDS, $multipart ? $data : http_build_query($data));
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

function keyless_token(array $response): string
{
    if (!preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $response['body'], $match)) {
        throw new RuntimeException('Installer response did not contain a CSRF token.');
    }
    return $match[1];
}

function keyless_remove(string $directory): void
{
    $resolved = realpath($directory);
    if ($resolved === false || !str_starts_with(basename($resolved), 'pl_keyless_')) {
        throw new RuntimeException('Refusing to remove an unverified fixture directory.');
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($resolved);
}

/**
 * One complete browser installation. $localDatabase decides whether db_test counts as
 * "this server"; $localAccount reproduces a XAMPP-style local stack (issue #84): the
 * account has no password at all and the database does not exist yet, so setup has to
 * accept the empty password and create the database itself.
 */
function keyless_install(bool $localDatabase, bool $localAccount = false): void
{
    global $packageRoot, $rootConfig, $ownerPassword;
    $fixture = 'pl_keyless_' . bin2hex(random_bytes(6));
    $temporary = sys_get_temp_dir() . '/' . $fixture;
    $password = $localAccount ? '' : bin2hex(random_bytes(24));
    $server = null;
    mkdir($temporary, 0700, true);
    try {
        pl_install_connect($rootConfig);
        // A database-level grant on a database that does not exist yet also permits creating it.
        foreach ($localAccount ? [$fixture . '_b'] : [$fixture, $fixture . '_b'] as $database) {
            DB::query('CREATE DATABASE %b CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci', $database);
        }
        DB::query('CREATE USER %s@%s IDENTIFIED BY %s', $fixture, '%', $password);
        DB::query('GRANT ALL PRIVILEGES ON %b.* TO %s@%s', $fixture, $fixture, '%');
        DB::query('GRANT ALL PRIVILEGES ON %b.* TO %s@%s', $fixture . '_b', $fixture, '%');
        $installation = $temporary . '/installation';
        $environment = array_replace(getenv(), ['PL_DB_PASSWORD' => '', 'PL_INSTALL_DIRECTORY' => $installation,
            'PL_INSTALL_CONFIG_PATH' => $temporary . '/config.local.php', 'PL_OAUTH_KEY_DIRECTORY' => $temporary . '/oauth',
            'PL_INSTALL_TEST_LOCAL_DB_HOSTS' => $localDatabase ? 'db_test' : '']);
        unset($environment['PL_SETUP_KEY'], $environment['PL_INSTALL_ALLOW_HTTP']);
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new RuntimeException('Cannot reserve a local test port.');
        }
        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $public = $packageRoot . '/www/phpledger/public';
        $router = $temporary . '/router.php';
        file_put_contents($router, '<?php $public = ' . var_export($public, true) . '; $path = parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH); '
            . '$file = realpath($public . $path); if (is_string($file) && str_starts_with($file, realpath($public) . DIRECTORY_SEPARATOR) && is_file($file) && pathinfo($file, PATHINFO_EXTENSION) !== "php") { return false; } require $public . "/index.php";');
        $server = proc_open([PHP_BINARY, '-S', $address, '-t', $public, $router],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $temporary . '/server.log', 'a'], 2 => ['file', $temporary . '/server.log', 'a']], $pipes, $packageRoot, $environment);
        for ($wait = 0; $wait < 50; $wait++) {
            $probe = @stream_socket_client('tcp://' . $address, $errno, $errstr, 0.1);
            if ($probe !== false) {
                fclose($probe);
                break;
            }
            usleep(100000);
        }
        $origin = 'http://' . $address;
        $url = $origin . '/install';
        $cookie = $temporary . '/owner-cookies.txt';

        // A fresh copy sends visitors to the installer, which opens without a key over local HTTP.
        $home = keyless_http($origin . '/', null, $cookie);
        keyless_assert($home['status'] === 303 && str_contains($home['headers'], 'Location: /install'), 'An unconfigured copy did not open the installer.');
        $response = keyless_http($url, null, $cookie);
        keyless_assert($response['status'] === 200 && str_contains($response['body'], 'Connect your database') && !str_contains($response['body'], 'Private setup key'), 'Keyless setup did not open at the database step.');
        keyless_assert(str_contains($response['body'], 'Local test on this computer'), 'Local HTTP setup was not labelled.');
        $database = ['host' => 'db_test', 'port' => '3306', 'database' => $fixture, 'user' => $fixture, 'password' => $password,
            'public_url' => $origin, 'action' => 'database', 'csrf_token' => keyless_token($response)];
        if (!$localDatabase) {
            $denied = keyless_http($url, $database, $cookie);
            keyless_assert($denied['status'] === 400 && str_contains($denied['body'], 'another server') && str_contains($denied['body'], 'name="setup_code"'), 'A database on another server did not ask for the file code.');
            $code = trim((string) file_get_contents($installation . '/setup-code.txt'));
            keyless_assert(preg_match('/^[a-f0-9]{32}$/D', $code) === 1 && !str_contains($denied['body'], $code), 'The setup code was not private.');
            $denied = keyless_http($url, $database + ['setup_code' => 'wrong-code'], $cookie);
            keyless_assert($denied['status'] === 400 && str_contains($denied['body'], 'not accepted'), 'A wrong setup code was accepted.');
            $database['setup_code'] = $code;
        }
        $response = keyless_http($url, $database, $cookie);
        keyless_assert($response['status'] === 200 && str_contains($response['body'], 'Review your installation'), 'The owner could not connect the empty database.');
        if ($localAccount) {
            keyless_assert(str_contains($response['body'], 'created the empty database'), 'Setup did not create the missing database on this server.');
            keyless_assert(str_contains($response['body'], 'with no password'), 'Setup did not name the database account without a password.');
        }
        $csrf = keyless_token($response);

        // Once bound, another visitor cannot switch this setup to a different database.
        $visitor = $temporary . '/visitor-cookies.txt';
        $other = keyless_http($url, null, $visitor);
        $attempt = array_replace($database, ['database' => $fixture . '_b', 'csrf_token' => keyless_token($other)]);
        $denied = keyless_http($url, $attempt, $visitor);
        keyless_assert($denied['status'] === 400 && str_contains($denied['body'], 'already bound'), 'A second visitor switched the bound database.');

        for ($batch = 0; $batch < 45 && !str_contains($response['body'], 'Save private configuration'); $batch++) {
            $response = keyless_http($url, ['action' => 'migrate', 'csrf_token' => $csrf], $cookie);
            keyless_assert($response['status'] === 200, 'A migration batch failed.');
        }
        keyless_assert(str_contains($response['body'], 'Save private configuration'), 'The migration chain did not finish.');
        $response = keyless_http($url, ['action' => 'save_config', 'csrf_token' => $csrf, 'public_url' => $origin], $cookie);
        keyless_assert($response['status'] === 200 && str_contains($response['body'], 'Create your owner account'), 'Configuration could not be saved.');
        $owner = ['action' => 'finish', 'csrf_token' => $csrf, 'name' => 'Keyless Owner', 'username' => 'keyless-owner',
            'email' => 'keyless-owner@example.invalid', 'password' => $ownerPassword, 'password_confirm' => $ownerPassword];
        // A disguised script is refused before any account exists; a real PNG becomes the logo.
        file_put_contents($temporary . '/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>');
        $denied = keyless_http($url, $owner + ['logo' => new CURLFile($temporary . '/logo.svg', 'image/png', 'logo.png')], $cookie);
        keyless_assert($denied['status'] === 400 && str_contains($denied['body'], 'SVG'), 'A disguised SVG logo was accepted.');
        $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAADAAAAAQCAYAAABQrvyxAAAAGUlEQVR42u3BAQEAAACCIP+vbkhAAQAArwYMEAAB9jm1kQAAAABJRU5ErkJggg==', true);
        file_put_contents($temporary . '/logo.png', $png);
        $response = keyless_http($url, $owner + ['logo' => new CURLFile($temporary . '/logo.png', 'image/png', 'logo.png')], $cookie);
        keyless_assert($response['status'] === 303 && str_contains($response['headers'], 'Location: /onboarding'), 'The owner account did not finish setup.');
        $image = keyless_http($origin . '/logo', null, $visitor);
        keyless_assert($image['status'] === 200 && str_contains($image['headers'], 'Content-Type: image/png') && $image['body'] === $png
            && str_contains($image['headers'], 'nosniff') && !str_contains($image['headers'], 'Set-Cookie'), 'The installation logo is not served as the same PNG.');
        $page = keyless_http($origin . '/onboarding', null, $cookie);
        keyless_assert(str_contains($page['body'], '/logo?v=') && str_contains($page['body'], 'alt="Business logo"'), 'The logo does not appear in the app.');
        pl_install_connect(['host' => 'db_test', 'port' => 3306, 'database' => $fixture, 'user' => $fixture, 'password' => $password]);
        keyless_assert(pl_authenticate('keyless-owner', $ownerPassword, 'keyless-fixture')['email'] === 'keyless-owner@example.invalid', 'The owner cannot sign in with the username.');
        keyless_assert((int) DB::queryFirstField('SELECT COUNT(*) FROM pl_users') === 1, 'Setup created extra users.');
        $closed = keyless_http($url, null, $visitor);
        keyless_assert($closed['status'] === 404 && str_contains($closed['body'], 'already installed'), 'Completed setup reopened without a key.');
        keyless_assert(!is_file($installation . '/setup-code.txt'), 'The one-time setup code outlived installation.');
        $logs = (string) file_get_contents($temporary . '/server.log');
        keyless_assert(($password === '' || !str_contains($logs, $password)) && !str_contains($logs, $ownerPassword), 'HTTP logs contain setup secrets.');
    } finally {
        if (is_resource($server)) {
            proc_terminate($server);
            proc_close($server);
        }
        pl_install_connect($rootConfig);
        if (preg_match('/^pl_keyless_[a-f0-9]{12}$/D', $fixture)) {
            DB::query('DROP DATABASE IF EXISTS %b', $fixture);
            DB::query('DROP DATABASE IF EXISTS %b', $fixture . '_b');
            DB::query('DROP USER IF EXISTS %s@%s', $fixture, '%');
        }
        keyless_remove($temporary);
    }
}

try {
    keyless_install(true);
    keyless_install(false);
    keyless_install(true, true);
    echo "Keyless browser installer: {$checks} checks passed for same-server, other-server and local-account databases.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Keyless browser installer fixture failed: ' . $error->getMessage() . "\n");
    exit(1);
}
