<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/www/phpledger/install/preflight.php';
require_once dirname(__DIR__) . '/www/phpledger/includes/functions/web_functions.php';
require_once dirname(__DIR__) . '/www/phpledger/includes/functions/install_web_functions.php';

function installer_process(string $script, array $arguments = [], string $input = '', array $overrides = []): array
{
    $environment = getenv();
    if (!is_array($environment)) {
        throw new RuntimeException('Process environment unavailable.');
    }
    $process = proc_open([PHP_BINARY, $script, ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_replace($environment, $overrides));
    if (!is_resource($process)) {
        throw new RuntimeException('Installer process unavailable.');
    }
    fwrite($pipes[0], $input);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['status' => proc_close($process), 'output' => $output];
}

test('installer prerequisites identify missing runtime, extensions and dependencies', function (): void {
    foreach ([80200, 80233, 80300, 80333, 80425, 80510] as $version) {
        assert_same([], pl_install_runtime_issues($version, ['bcmath', 'mbstring', 'PDO', 'pdo_mysql', 'session', 'curl', 'openssl', 'fileinfo'], true));
    }
    $issues = pl_install_runtime_issues(80199, [], false);
    assert_same(10, count($issues));
    assert_true(str_contains(implode(' ', $issues), 'PHP 8.2'));
    assert_true(str_contains(implode(' ', $issues), 'Composer dependencies'));
    assert_throws(fn () => pl_require_runtime(80199), RuntimeException::class, 'PHP 8.2');
    assert_same('error', pl_install_session_check('files', sys_get_temp_dir() . '/missing-session-' . bin2hex(random_bytes(4)))['status']);
    assert_same('warning', pl_install_session_check('redis', 'unverified-custom-handler')['status']);
    assert_same('ok', pl_install_session_check('files', sys_get_temp_dir())['status']);
});

test('installer schema inspection distinguishes fresh, pending and unsafe receipts', function (): void {
    $hash = str_repeat('a', 64);
    $known = ['001_fixture' => $hash, '002_fixture' => $hash];
    $receipt = ['version' => '001_fixture', 'checksum' => $hash, 'status' => 'applied'];
    assert_same(['status' => 'empty', 'applied' => 0, 'pending' => 2], pl_install_schema_state(null, $known, 0));
    assert_same('pending', pl_install_schema_state([$receipt], $known, 1)['status']);
    assert_same('current', pl_install_schema_state([$receipt], ['001_fixture' => $hash], 1)['status']);
    assert_throws(fn () => pl_install_schema_state(null, $known, 1), DomainException::class, 'separate empty database');
    assert_throws(fn () => pl_install_schema_state([$receipt], [], 1), DomainException::class, 'unknown migration');
    assert_throws(fn () => pl_install_schema_state([array_replace($receipt, ['status' => 'applying'])], $known, 1), DomainException::class, 'incomplete');
    assert_throws(fn () => pl_install_schema_state([array_replace($receipt, ['checksum' => str_repeat('b', 64)])], $known, 1), DomainException::class, 'checksum');
});

test('preflight and migration replay preserve receipts and existing data', function (): void {
    $before = DB::query('SELECT * FROM pl_schema_migrations ORDER BY version');
    $users = (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_users');
    $journals = (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals');
    foreach (['preflight.php', 'migrate.php'] as $name) {
        $result = installer_process(dirname(__DIR__) . '/www/phpledger/install/' . $name);
        assert_same(0, $result['status'], $result['output']);
        assert_true(str_contains($result['output'], $name === 'preflight.php' ? 'schema is current' : 'Migrations applied: 0; already current: ' . count($before)));
    }
    assert_same($before, DB::query('SELECT * FROM pl_schema_migrations ORDER BY version'));
    assert_same($users, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_users'));
    assert_same($journals, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals'));
});

test('installer connection failures do not expose credentials or stack traces', function (): void {
    $secret = 'Sample-private-check-' . bin2hex(random_bytes(12));
    foreach (['preflight.php', 'migrate.php', 'create-admin.php'] as $name) {
        $args = $name === 'create-admin.php' ? ['--email=wrong-db@example.invalid', '--name=Fixture', '--password-stdin'] : [];
        $result = installer_process(dirname(__DIR__) . '/www/phpledger/install/' . $name, $args, 'Sample account passphrase 427!' . "\n", ['PL_DB_PASSWORD' => $secret]);
        assert_same(1, $result['status']);
        assert_true(!str_contains($result['output'], $secret));
        assert_true(!str_contains($result['output'], 'Stack trace'));
        assert_true(!str_contains($result['output'], 'PDOException'));
    }
});

test('missing autoload and invalid local configuration produce safe installer failures', function (): void {
    $root = sys_get_temp_dir() . '/phpledger-installer-' . bin2hex(random_bytes(8));
    $install = $root . '/www/phpledger/install';
    $includes = $root . '/www/phpledger/includes';
    mkdir($install, 0700, true);
    mkdir($includes . '/functions', 0700, true);
    mkdir($root . '/www/phpledger/public', 0700, true);
    mkdir($root . '/vendor', 0700);
    $files = [
        $install . '/preflight.php' => dirname(__DIR__) . '/www/phpledger/install/preflight.php',
        $includes . '/bootstrap.php' => dirname(__DIR__) . '/www/phpledger/includes/bootstrap.php',
        $includes . '/functions/runtime_functions.php' => dirname(__DIR__) . '/www/phpledger/includes/functions/runtime_functions.php',
        $includes . '/functions/install_functions.php' => dirname(__DIR__) . '/www/phpledger/includes/functions/install_functions.php',
        $includes . '/functions/database_platform_functions.php' => dirname(__DIR__) . '/www/phpledger/includes/functions/database_platform_functions.php',
        $includes . '/functions/installation_state_functions.php' => dirname(__DIR__) . '/www/phpledger/includes/functions/installation_state_functions.php',
        $includes . '/functions/update_functions.php' => dirname(__DIR__) . '/www/phpledger/includes/functions/update_functions.php',
    ];
    foreach ($files as $target => $source) {
        copy($source, $target);
    }
    try {
        $missing = installer_process($install . '/preflight.php');
        assert_same(1, $missing['status']);
        assert_true(str_contains($missing['output'], 'Composer dependencies are missing'));
        assert_true(!str_contains($missing['output'], 'Warning:'));
        file_put_contents($root . '/vendor/autoload.php', '<?php require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ';');
        $secret = 'Sample-invalid-config-' . bin2hex(random_bytes(8));
        file_put_contents($includes . '/config.local.php', '<?php return ' . var_export($secret, true) . ';');
        $invalid = installer_process($install . '/preflight.php');
        assert_same(1, $invalid['status']);
        assert_true(str_contains($invalid['output'], 'Database/configuration check failed'));
        assert_true(!str_contains($invalid['output'], $secret));
        assert_true(!str_contains($invalid['output'], 'Stack trace'));
        file_put_contents($includes . '/config.local.php', '<?php throw new DomainException(' . var_export($secret, true) . ');');
        $thrown = installer_process($install . '/preflight.php');
        assert_same(1, $thrown['status']);
        assert_true(!str_contains($thrown['output'], $secret));
        unlink($includes . '/functions/install_functions.php');
        $missingService = installer_process($install . '/preflight.php');
        assert_same(1, $missingService['status']);
        assert_true(str_contains($missingService['output'], 'Installation service files are missing'));
        assert_true(!str_contains($missingService['output'], 'Warning:'));
    } finally {
        foreach ([...array_keys($files), $root . '/vendor/autoload.php', $includes . '/config.local.php'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        foreach ([$install, $includes . '/functions', $includes, $root . '/www/phpledger/public', $root . '/www/phpledger', $root . '/www', $root . '/vendor', $root] as $directory) {
            rmdir($directory);
        }
    }
});

test('database platforms: MySQL 8.4 and MariaDB are accepted, other servers refused', function (): void {
    foreach (['8.4.0', '8.4.6-log', '10.4.32-MariaDB', '5.5.5-10.6.25-MariaDB-ubu2204', '10.11.18-MariaDB-ubu2204', '11.4.10-MariaDB-ubu2404'] as $version) {
        assert_true(pl_database_platform($version)['supported'], $version);
    }
    foreach (['8.0.39', '9.1.0', '10.3.39-MariaDB', '5.7.44', 'unknown'] as $version) {
        assert_true(!pl_database_platform($version)['supported'], $version);
    }
    assert_same(['engine' => 'mariadb', 'version' => '10.6.25', 'supported' => true], pl_database_platform('5.5.5-10.6.25-MariaDB'));
    assert_true(pl_database_platform(pl_database_server_version())['supported'], 'The test database server itself must be supported.');
});

test('MariaDB translation rewrites only MySQL-only spellings; MySQL statements stay unchanged', function (): void {
    $locking = 'SELECT id FROM pl_accounts WHERE code = ? FOR SHARE';
    assert_same($locking, pl_database_translate($locking, ['mariadb' => false, 'skip_locked' => true, 'collation' => null]));
    $older = ['mariadb' => true, 'skip_locked' => false, 'collation' => 'utf8mb4_unicode_520_nopad_ci'];
    assert_same('SELECT id FROM pl_accounts WHERE code = ? LOCK IN SHARE MODE', pl_database_translate($locking, $older));
    assert_same('SELECT 1 FOR UPDATE', pl_database_translate('SELECT 1 FOR UPDATE SKIP LOCKED', $older));
    assert_same('CREATE TABLE t (a INT) COLLATE=utf8mb4_unicode_520_nopad_ci', pl_database_translate('CREATE TABLE t (a INT) COLLATE=utf8mb4_0900_ai_ci', $older));
    $current = ['mariadb' => true, 'skip_locked' => true, 'collation' => null];
    assert_same('SELECT 1 FOR UPDATE SKIP LOCKED', pl_database_translate('SELECT 1 FOR UPDATE SKIP LOCKED', $current));
    assert_same("SELECT 'for share' FROM t", pl_database_translate("SELECT 'for share' FROM t", $current));
});

test('plain HTTP is accepted only from a browser on the same computer', function (): void {
    $local = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost', 'SERVER_PORT' => '80'];
    assert_true(pl_web_local_http($local));
    assert_true(pl_web_local_http(['REMOTE_ADDR' => '::1', 'HTTP_HOST' => '[::1]:8080']));
    assert_true(pl_web_local_http(['REMOTE_ADDR' => '::ffff:127.0.0.1', 'HTTP_HOST' => 'ledger.localhost']));
    assert_true(!pl_web_local_http(array_replace($local, ['HTTPS' => 'on'])));
    assert_true(!pl_web_local_http(array_replace($local, ['REMOTE_ADDR' => '192.168.1.20'])));
    assert_true(!pl_web_local_http(array_replace($local, ['HTTP_HOST' => 'books.example.com'])));
    assert_true(!pl_web_local_http(array_replace($local, ['HTTP_HOST' => 'localhost.example.com'])));
    assert_true(!pl_web_local_http(array_replace($local, ['HTTP_X_FORWARDED_FOR' => '203.0.113.9'])));
});

test('uploaded folders may use dotted names but never escape the site', function (): void {
    $previous = getenv('PL_BASE_PATH');
    try {
        foreach (['/phpledger-1.1.0', '/accounts', '/~owner/books.v2'] as $base) {
            putenv('PL_BASE_PATH=' . $base);
            assert_same($base, pl_base_path());
            assert_same($base . '/install', pl_url('/install'));
        }
        foreach (['/..', '/a/../b', '/.hidden', '/a b', 'relative'] as $base) {
            putenv('PL_BASE_PATH=' . $base);
            assert_throws(fn () => pl_base_path(), RuntimeException::class, 'base path');
        }
        putenv('PL_BASE_PATH=/accounts');
        assert_same('https://books.example.com/accounts', pl_install_public_url('https://books.example.com/accounts/'));
        assert_throws(fn () => pl_install_public_url('https://books.example.com/elsewhere'), InvalidArgumentException::class);
        assert_throws(fn () => pl_install_public_url('https://books.example.com'), InvalidArgumentException::class);
    } finally {
        putenv($previous === false ? 'PL_BASE_PATH' : 'PL_BASE_PATH=' . $previous);
    }
});

test('only a database on this server skips the one-time setup code', function (): void {
    foreach (['localhost', '127.0.0.1', '::1', '[::1]', ' LOCALHOST '] as $host) {
        assert_true(pl_database_local_host($host), $host);
    }
    foreach (['db.example.com', '10.0.0.5', '127.0.0.2', 'localhost.example.com', 'db_test'] as $host) {
        assert_true(!pl_database_local_host($host), $host);
    }
});

test('a database on this server accepts the account local stacks install, elsewhere does not', function (): void {
    // Issue #84: XAMPP, Laragon and MAMP install root with no password, and refusing
    // that only stopped people from trying PHP Ledger on their own computer.
    $local = ['host' => 'localhost', 'port' => '3306', 'database' => 'phpledger', 'user' => 'root', 'password' => ''];
    assert_same(['host' => 'localhost', 'port' => 3306, 'database' => 'phpledger', 'user' => 'root', 'password' => ''],
        pl_install_database_input($local));
    assert_same('ledger', pl_install_database_input(array_replace($local, ['user' => 'ledger', 'password' => 'a passphrase']))['user']);
    // A database on another server keeps both rules: its credentials cross the network.
    $remote = array_replace($local, ['host' => 'db.example.com']);
    assert_throws(fn () => pl_install_database_input($remote), InvalidArgumentException::class, 'host, port');
    assert_throws(fn () => pl_install_database_input(array_replace($remote, ['user' => 'ledger'])), InvalidArgumentException::class, 'host, port');
    assert_throws(fn () => pl_install_database_input(array_replace($remote, ['password' => 'a passphrase'])), InvalidArgumentException::class, 'not the MySQL root account');
    assert_same('ledger', pl_install_database_input(array_replace($remote, ['user' => 'ledger', 'password' => 'a passphrase']))['user']);
    // Every other rule is unchanged, on this server as well.
    foreach ([['database' => 'has space'], ['database' => ''], ['user' => ''], ['port' => '0'], ['port' => '70000'],
        ['host' => ''], ['password' => str_repeat('x', 1025)], ['password' => 'pass' . chr(0) . 'word']] as $broken) {
        assert_throws(fn () => pl_install_database_input(array_replace($local, $broken)), InvalidArgumentException::class, 'host, port');
    }
});

test('a site installed at a plain http address keeps working and names what a certificate would add', function (): void {
    $previous = getenv('PL_PUBLIC_URL');
    try {
        putenv('PL_PUBLIC_URL=https://books.example.com');
        assert_true(!pl_web_insecure_site());
        assert_same(null, pl_web_insecure_site_notice());
        putenv('PL_PUBLIC_URL=http://books.example.com');
        assert_true(pl_web_insecure_site());
        assert_true(str_contains((string) pl_web_insecure_site_notice(), 'not using HTTPS'));
        assert_true(str_contains((string) pl_web_insecure_site_notice(), 'Connections'));
        putenv('PL_PUBLIC_URL=http://localhost:8080');
        assert_true(str_contains((string) pl_web_insecure_site_notice(), 'Local test'));
    } finally {
        putenv($previous === false ? 'PL_PUBLIC_URL' : 'PL_PUBLIC_URL=' . $previous);
    }
});

test('the public address may stay plain http only when it is the address the owner is using', function (): void {
    $previousHost = $_SERVER['HTTP_HOST'] ?? null;
    $previousPort = $_SERVER['SERVER_PORT'] ?? null;
    try {
        $_SERVER['HTTP_HOST'] = 'books.example.com';
        unset($_SERVER['SERVER_PORT'], $_SERVER['HTTPS']);
        assert_same('http://books.example.com', pl_install_public_url('http://books.example.com/'));
        assert_same('https://books.example.com', pl_install_public_url('https://books.example.com'));
        assert_throws(fn () => pl_install_public_url('http://other.example.com'), InvalidArgumentException::class, 'HTTPS address');
        $_SERVER['SERVER_PORT'] = '443';
        assert_throws(fn () => pl_install_public_url('http://books.example.com'), InvalidArgumentException::class, 'HTTPS address');
    } finally {
        unset($_SERVER['HTTP_HOST'], $_SERVER['SERVER_PORT']);
        if ($previousHost !== null) {
            $_SERVER['HTTP_HOST'] = $previousHost;
        }
        if ($previousPort !== null) {
            $_SERVER['SERVER_PORT'] = $previousPort;
        }
    }
});

test('one installer request applies as much of the chain as its PHP time limit allows', function (): void {
    // The whole chain is about two seconds of work, so a normal host finishes in one
    // request; a host with a short limit hands control back and resumes from receipts.
    assert_same(15.0, pl_install_migration_budget('0'));
    assert_same(15.0, pl_install_migration_budget('30'));
    assert_same(6.0, pl_install_migration_budget('10'));
    assert_same(3.0, pl_install_migration_budget('5'));
    assert_same(3.0, pl_install_migration_budget('1'));
    assert_throws(fn () => pl_migrate(0), InvalidArgumentException::class, 'batch size');
    assert_throws(fn () => pl_migrate(null, 0.0), InvalidArgumentException::class, 'time budget');
});

test('the progress bar names the step that is running in words an owner recognises', function (): void {
    $versions = pl_install_migration_versions();
    assert_same('001_foundation', $versions[0]);
    assert_true(count($versions) >= 35, 'every migration file is listed');
    assert_same($versions, array_values(array_unique($versions)));
    assert_same('Creating the core tables', pl_install_step_label('001_foundation'));
    assert_same('Building the chart of accounts and journals', pl_install_step_label('006_core_accounts_journals'));
    // A step added after this release still reads as a sentence rather than a file name.
    assert_same('Adding future thing', pl_install_step_label('099_future_thing'));
    $started = pl_install_progress(['status' => 'pending', 'applied' => 0, 'pending' => count($versions)]);
    assert_same(2, $started['percent'], 'a chain that has not started still shows movement');
    assert_same('Creating the core tables', $started['label']);
    $middle = pl_install_progress(['status' => 'pending', 'applied' => 5, 'pending' => count($versions) - 5]);
    assert_same(5, $middle['done']);
    assert_same(count($versions), $middle['total']);
    assert_same('Building the chart of accounts and journals', $middle['label']);
    $finished = pl_install_progress(['status' => 'current', 'applied' => count($versions), 'pending' => 0]);
    assert_same(100, $finished['percent']);
    assert_same('Finishing up', $finished['label']);
});

test('the setup lights summarise what the server can and cannot do', function (): void {
    // The rule is stated for any PHP, not only the one running the suite.
    assert_same(PL_CHECK_PASS, pl_install_php_version_check(80200, '8.2.0')['status']);
    assert_same(PL_CHECK_PASS, pl_install_php_version_check(80500, '8.5.0')['status']);
    assert_same(PL_CHECK_FAIL, pl_install_php_version_check(80199, '8.1.99')['status']);
    assert_true(str_contains(pl_install_php_version_check(80199, '8.1.99')['detail'], 'needs 8.2 or newer'));
    $checks = pl_install_requirement_checks(['HTTPS' => 'on', 'SERVER_PORT' => '443'], 'protected');
    assert_true(count($checks) >= 13, 'every requirement gets its own light');
    $byName = [];
    foreach ($checks as $check) {
        assert_same(['name', 'status', 'detail'], array_keys($check));
        assert_true(in_array($check['status'], [PL_CHECK_PASS, PL_CHECK_WARN, PL_CHECK_FAIL], true), $check['name']);
        assert_true($check['detail'] !== '', $check['name'] . ' explains itself');
        $byName[$check['name']] = $check;
    }
    // Whatever the host, what the application itself brings must pass here.
    foreach (['PHP version', 'PHP bcmath', 'PHP pdo_mysql', 'PHP openssl', 'Bundled dependencies'] as $required) {
        assert_same(PL_CHECK_PASS, $byName[$required]['status'], $required);
    }
    assert_same(PL_CHECK_PASS, $byName['HTTPS']['status']);
    assert_same(PL_CHECK_PASS, $byName['Private folders hidden']['status']);
    // The worst light decides the summary, and plain HTTP is a warning rather than a refusal.
    $plain = pl_install_requirement_checks(['HTTP_HOST' => 'books.example.com'], 'exposed');
    assert_same(PL_CHECK_FAIL, pl_install_check_summary($plain));
    assert_same(PL_CHECK_WARN, pl_install_check_summary([pl_install_check_row('a', PL_CHECK_PASS, ''), pl_install_check_row('b', PL_CHECK_WARN, '')]));
    assert_same(PL_CHECK_PASS, pl_install_check_summary([]));
    $environment = pl_install_environment(['SERVER_SOFTWARE' => 'Apache/2.4.58'], [3306]);
    $names = array_column($environment, 'name');
    foreach (['PHP', 'Web server', 'Application folder', 'Private storage', 'Database servers found here'] as $expected) {
        assert_true(in_array($expected, $names, true), $expected);
    }
    assert_same('127.0.0.1 port 3306', $environment[array_search('Database servers found here', $names, true)]['value']);
});

test('the checks setup runs against the finished database prove what the owner cannot see', function (): void {
    $checks = pl_install_self_checks(pl_install_database_check());
    $byName = array_column($checks, 'detail', 'name');
    assert_same(PL_CHECK_PASS, pl_install_check_summary($checks));
    assert_true(str_contains($byName['Database structure'], 'every checksum matches'));
    assert_true(str_contains($byName['Posted entries protected'], 'database rules are active'));
    assert_true(str_contains($byName['Money arithmetic'], 'exactly'));
    // An incomplete chain is reported as a red light, not hidden.
    $pending = pl_install_self_checks(['status' => 'pending', 'applied' => 30, 'pending' => 5]);
    assert_same(PL_CHECK_FAIL, $pending[0]['status']);
    assert_true(str_contains($pending[0]['detail'], '5 of 35 steps still to run'));
});

test('private-folder probes stay inside the uploaded folder and are skipped for a public document root', function (): void {
    assert_same('https://books.example.com/accounts/www/phpledger/storage/installation/exposure-probe.txt',
        pl_install_probe_url('https://books.example.com/accounts', '/srv/site/accounts', '/srv/site/accounts/www/phpledger/storage/installation/exposure-probe.txt'));
    assert_same(null, pl_install_probe_url('https://books.example.com', '/srv/site/accounts', '/srv/private/installation/exposure-probe.txt'));
    assert_same(null, pl_install_probe_origin(['HTTP_HOST' => 'books.example.com/elsewhere']));
    assert_same('http://localhost:8080', pl_install_probe_origin(['HTTP_HOST' => 'LocalHost:8080']));
    assert_same('not-needed', pl_install_exposure_status(['HTTP_HOST' => 'localhost']));
});

test('the optional logo accepts real PNG, JPEG or WebP images only and is stored byte for byte', function (): void {
    require_once dirname(__DIR__) . '/www/phpledger/includes/functions/branding_functions.php';
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAADAAAAAQCAYAAABQrvyxAAAAGUlEQVR42u3BAQEAAACCIP+vbkhAAQAArwYMEAAB9jm1kQAAAABJRU5ErkJggg==', true);
    $wide = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAMgAAAAQCAYAAABTNTDcAAAAI0lEQVR42u3BAQ0AAADCoPdPbQ43oAAAAAAAAAAAAAAAAHgyMhAAAefnqjEAAAAASUVORK5CYII=', true);
    $logo = pl_logo_validate((string) $png);
    assert_same(['media_type' => 'image/png', 'width' => 48, 'height' => 16], array_diff_key($logo, ['bytes' => true]));
    assert_throws(fn () => pl_logo_validate((string) $wide), InvalidArgumentException::class, 'six times wider');
    assert_throws(fn () => pl_logo_validate('<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>'), InvalidArgumentException::class, 'SVG');
    assert_throws(fn () => pl_logo_validate('GIF89a' . substr((string) $png, 6)), InvalidArgumentException::class);
    assert_throws(fn () => pl_logo_validate(str_repeat('x', PL_LOGO_MAX_BYTES + 1)), InvalidArgumentException::class, '1 MB');
    assert_same(null, pl_logo_from_upload(['error' => UPLOAD_ERR_NO_FILE]));
    assert_throws(fn () => pl_logo_from_upload(['error' => UPLOAD_ERR_OK, 'tmp_name' => __FILE__]), InvalidArgumentException::class, 'could not be uploaded');
    DB::startTransaction();
    try {
        $owner = (int) DB::queryFirstField('SELECT id FROM pl_users ORDER BY id LIMIT 1');
        if ($owner === 0) {
            $owner = pl_create_user('logo-' . bin2hex(random_bytes(6)) . '@example.invalid', 'Logo fixture', 'Sample logo passphrase 551!');
        }
        pl_logo_save($logo, $owner);
        assert_same((string) $png, DB::queryFirstField("SELECT content FROM pl_installation_assets WHERE name = 'logo'"));
        assert_same(hash('sha256', (string) $png), DB::queryFirstField("SELECT sha256 FROM pl_installation_assets WHERE name = 'logo'"));
    } finally {
        DB::rollback();
    }
});

test('repeating administrator creation preserves the original account and password', function (): void {
    $email = 'installer-' . bin2hex(random_bytes(8)) . '@example.invalid';
    $script = dirname(__DIR__) . '/www/phpledger/install/create-admin.php';
    $args = ['--email=' . $email, '--name=Original installer owner', '--password-stdin'];
    $password = 'Original sample passphrase 481!';
    $created = installer_process($script, $args, $password . "\n");
    assert_same(0, $created['status'], $created['output']);
    $before = DB::queryFirstRow('SELECT * FROM pl_users WHERE email=%s', $email);
    assert_true(is_array($before));
    $replayed = installer_process($script, $args, "Replacement sample passphrase 482!\n");
    assert_same(1, $replayed['status']);
    assert_same($before, DB::queryFirstRow('SELECT * FROM pl_users WHERE email=%s', $email));
    assert_true(pl_verify_password($password, $before['password_hash']));
    assert_true(!pl_verify_password('Replacement sample passphrase 482!', $before['password_hash']));
});
