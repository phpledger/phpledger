<?php
declare(strict_types=1);
// The migrate and verify phases of an update run the real install helpers of the NEW release
// inside a request that has already loaded the installed release's copied recovery runtime
// (issue #90). This drives a complete update through the real /maintenance.php loader, with no
// migrate or health callback injected, and expects it to reach `complete`. It creates and
// removes only its own random database on db_test and its own temporary directory.
if (getenv('PL_ENV') !== 'test' || getenv('PL_DB_HOST') !== 'db_test') { fwrite(STDERR, "Use the isolated Docker test service.\n"); exit(2); }
$repository = dirname(__DIR__);
require $repository . '/vendor/autoload.php';
require $repository . '/www/phpledger/includes/functions/update_functions.php';
// This process installs the starting schema with the application's own copy; the server
// process started below loads the copied runtime instead, exactly as an operator's browser does.
require $repository . '/www/phpledger/includes/functions/install_functions.php';
DB::$host = 'db_test'; DB::$user = 'root'; DB::$password = 'local-test-root-only'; DB::$dbName = 'information_schema'; DB::$encoding = 'utf8mb4';
pl_database_use_dialect();
$database = 'phpledger_update_migrate_' . bin2hex(random_bytes(8));
$fixture = sys_get_temp_dir() . '/' . $database;
$root = $fixture . '/app'; $private = $fixture . '/private';
mkdir($root . '/www/phpledger/public', 0700, true); mkdir($private, 0700, true);
$created = false; $server = null;
$check = static function (bool $condition, string $label): void { clearstatcache(); if (!$condition) { throw new RuntimeException($label); } echo "PASS $label\n"; };
try {
    DB::query('CREATE DATABASE %b CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci', $database); $created = true; DB::query('USE %b', $database);
    // The installed release has every schema step but the last applied; the update applies that one.
    $versions = pl_install_migration_versions();
    pl_migrate(count($versions) - 1);
    $pending = pl_install_database_check()['pending'];
    $check($pending === 1, 'installed schema leaves exactly one migration (' . end($versions) . ') for the update to apply');
    // The installed application tree: the real install helpers and migrations, the pinned loader,
    // and a bootstrap that defers to the repository's real bootstrap for the fresh runtime probe.
    $functions = 'www/phpledger/includes/functions/';
    $tree = [];
    foreach (['update_functions.php', 'update_database_functions.php', 'update_web_functions.php', 'update_probe_functions.php', 'database_platform_functions.php', 'database_functions.php', 'update_channel_functions.php', 'install_functions.php', 'runtime_functions.php'] as $name) {
        $tree[$functions . $name] = (string) file_get_contents($repository . '/' . $functions . $name);
    }
    foreach (glob($repository . '/www/phpledger/install/migrations/[0-9]*.php') ?: [] as $file) {
        $tree['www/phpledger/install/migrations/' . basename($file)] = (string) file_get_contents($file);
    }
    $loader = (string) file_get_contents($repository . '/www/phpledger/public/maintenance.php');
    $tree['www/phpledger/public/maintenance.php'] = $loader;
    $tree['www/phpledger/public/index.php'] = '<?php echo "installed";';
    $tree['www/phpledger/includes/bootstrap.php'] = '<?php require ' . var_export($repository . '/www/phpledger/includes/bootstrap.php', true) . ';';
    $tree['vendor/autoload.php'] = '<?php // sample';
    $tree['vendor/sergeytsalkov/meekrodb/db.class.php'] = (string) file_get_contents($repository . '/vendor/sergeytsalkov/meekrodb/db.class.php');
    $currentTree = $tree;
    // Optional preserved previous-release worker: prove it can load the incoming installer
    // without the newly added namespace helper having existed in its recovery runtime.
    $legacyRuntime = getenv('PL_TEST_LEGACY_DATABASE_RUNTIME');
    if (is_string($legacyRuntime) && $legacyRuntime !== '') {
        foreach (['update_functions.php', 'update_database_functions.php', 'update_web_functions.php', 'update_probe_functions.php', 'database_platform_functions.php', 'update_channel_functions.php'] as $name) {
            $bytes = file_get_contents($legacyRuntime . '/' . $name);
            if ($bytes === false) { throw new RuntimeException('Legacy worker fixture file missing.'); }
            $tree[$functions . $name] = $bytes;
        }
        unset($tree[$functions . 'database_functions.php']);
    }
    foreach ($tree as $path => $bytes) { pl_update_write($root . '/' . $path, $bytes); }
    $manifest = ['version' => '1.1.2', 'files' => array_map(static fn(string $path): array => ['path' => $path], array_keys($tree))];
    pl_update_checkpoint($root . '/PACKAGE-MANIFEST.json', $manifest);
    pl_update_write($root . '/www/phpledger/includes/config.local.php', '<?php return ' . var_export(['host' => 'db_test', 'port' => 3306, 'database' => $database, 'user' => 'root', 'password' => 'local-test-root-only'], true) . ';');
    $operator = bin2hex(random_bytes(32)); pl_update_write($private . '/operator.key', $operator);
    $key = openssl_pkey_new(['private_key_bits' => 3072, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    if (!$key) { throw new RuntimeException('Sample signing fixture unavailable.'); }
    pl_update_write($private . '/publisher.pem', openssl_pkey_get_details($key)['key']);
    // The next release: the same helpers and migrations, a changed entry point and manifest.
    $release = $currentTree;
    $release['www/phpledger/public/index.php'] = '<?php echo "updated";';
    $release['PACKAGE-MANIFEST.json'] = json_encode(['version' => '1.1.3', 'files' => array_map(static fn(string $path): array => ['path'=>$path], array_keys($release))], JSON_THROW_ON_ERROR);
    $archive = $fixture . '/release.zip'; $zip = new ZipArchive(); $zip->open($archive, ZipArchive::CREATE); $inventory = [];
    foreach ($release as $path => $data) { $zip->addFromString('phpledger/' . $path, $data); $inventory[] = ['path' => $path, 'bytes' => strlen($data), 'sha256' => hash('sha256', $data)]; }
    $zip->close();
    $payload = json_encode(['schema' => 1, 'version' => '1.1.3', 'channel' => 'stable', 'min_php' => '8.2.0', 'archive_bytes' => filesize($archive), 'archive_sha256' => hash_file('sha256', $archive), 'files' => $inventory], JSON_THROW_ON_ERROR);
    openssl_sign($payload, $signature, $key, OPENSSL_ALGO_SHA256);
    $envelope = json_encode(['payload' => base64_encode($payload), 'signature' => base64_encode($signature)], JSON_THROW_ON_ERROR);
    $port = random_int(20000, 50000); $base = 'http://127.0.0.1:' . $port;
    $environment = array_replace(getenv(), ['PL_ENV' => 'test', 'PL_INSTALL_DIRECTORY' => $private, 'PL_INSTALL_CONFIG_PATH' => $root . '/www/phpledger/includes/config.local.php']);
    $log = $fixture . '/server.log';
    $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root . '/www/phpledger/public'], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root, $environment);
    if (!is_resource($server)) { throw new RuntimeException('Sample HTTP server unavailable.'); }
    fclose($pipes[0]); $cookie = '';
    $request = static function (string $path, ?string $body = null, string $type = 'application/x-www-form-urlencoded') use ($base, &$cookie): array {
        $headers = ['Connection: close']; if ($cookie !== '') { $headers[] = 'Cookie: ' . $cookie; }
        if ($body !== null) { $headers[] = 'Content-Type: ' . $type; }
        $context = stream_context_create(['http' => ['method' => $body === null ? 'GET' : 'POST', 'header' => implode("\r\n", $headers), 'content' => $body ?? '', 'ignore_errors' => true, 'timeout' => 60]]);
        $response = @file_get_contents($base . $path, false, $context); $responseHeaders = $http_response_header ?? [];
        foreach ($responseHeaders as $header) { if (preg_match('/^Set-Cookie: ([^;]+)/i', $header, $match)) { $cookie = $match[1]; } }
        preg_match('/\s(\d{3})\s/', $responseHeaders[0] ?? '', $status);
        return [(int) ($status[1] ?? 0), $response === false ? '' : $response];
    };
    for ($retry = 0; $retry < 50; $retry++) { [$status, $body] = $request('/maintenance.php'); if ($status) { break; } usleep(20000); }
    $check($status === 200 && str_contains($body, 'Host installation operator key'), 'independent operator page is reachable');
    preg_match('/name="csrf" value="([a-f0-9]+)"/', $body, $match); $csrf = $match[1] ?? '';
    [$status] = $request('/maintenance.php', http_build_query(['action' => 'authenticate', 'operator_key' => $operator, 'csrf' => $csrf]));
    $check($status === 200, 'host operator key authenticates');
    $boundary = 'sample-' . bin2hex(random_bytes(8)); $body = '';
    foreach (['csrf' => $csrf, 'action' => 'begin', 'operator_key' => $operator, 'channel' => 'stable', 'source' => 'upload'] as $name => $value) {
        $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"\r\n\r\n$value\r\n";
    }
    foreach (['archive' => ['release.zip', file_get_contents($archive)], 'metadata' => ['release.json', $envelope]] as $name => [$filename, $data]) {
        $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"; filename=\"$filename\"\r\nContent-Type: application/octet-stream\r\n\r\n$data\r\n";
    }
    $body .= "--$boundary--\r\n";
    [$status, $body] = $request('/maintenance.php', $body, 'multipart/form-data; boundary=' . $boundary);
    $check($status === 200 && is_file($private . '/updates/active.json'), 'signed release enters maintenance in the backup phase');
    $id = pl_update_json($private . '/updates/active.json')['id']; $operation = $private . '/updates/' . $id;
    $check(is_file($operation . '/runtime/database_platform_functions.php') && is_file($operation . '/runtime/update_database_functions.php'), 'recovery runtime was copied before any application file changed');
    // Drive the operation with the real functions only: no migrate, health or restore callback.
    $phases = []; $failures = [];
    for ($step = 0; $step < 600; $step++) {
        [$status, $body] = $request('/maintenance.php', http_build_query(['action' => 'continue', 'csrf' => $csrf]));
        $state = pl_update_json($operation . '/state.json');
        if ($phases === [] || end($phases) !== $state['phase']) { $phases[] = $state['phase']; }
        // The fresh runtime probe answers 303 back to the maintenance page; anything else but 200 is a failed step.
        if ($status !== 200 && $status !== 303) { $failures[] = 'step ' . $step . ' status ' . $status . ' phase ' . $state['phase'] . ' error ' . var_export($state['error'], true); }
        clearstatcache(true, $private . '/updates/active.json');
        if (in_array($state['phase'], ['complete', 'restored', 'aborted'], true) && !is_file($private . '/updates/active.json')) { break; }
    }
    echo 'Phase sequence: ' . implode(' > ', $phases) . "\n";
    $serverLog = is_file($log) ? (string) file_get_contents($log) : '';
    if (preg_match('/PHP Fatal error:[^\n]*/', $serverLog, $fatal)) { $failures[] = $fatal[0]; }
    if ($failures !== []) { echo 'Observed: ' . implode(' | ', $failures) . "\n"; }
    $check($phases === ['backup', 'apply', 'migrate', 'verify', 'runtime', 'complete'], 'real migrate and health phases complete under the copied recovery runtime');
    $check(!str_contains($serverLog, 'Cannot redeclare'), 'no function is declared twice between the recovery runtime and the updated application');
    $check(pl_install_database_check()['pending'] === 0 && (int) DB::queryFirstField("SELECT COUNT(*) FROM pl_schema_migrations WHERE version = %s AND status = 'applied'", end($versions)) === 1, 'the update applied the pending migration of the new release');
    $check(pl_update_json($private . '/updates/last.json')['phase'] === 'complete' && !is_file($private . '/updates/active.json'), 'maintenance is released after the fresh runtime probe');
    [$status, $body] = $request('/');
    $check($status === 200 && $body === 'updated', 'the updated application serves after the update');
    echo "Real migrate and health phases under the copied recovery runtime passed.\n";
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    if ($created && preg_match('/^phpledger_update_migrate_[a-f0-9]{16}$/D', $database)) { DB::query('USE information_schema'); DB::query('DROP DATABASE %b', $database); }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) { if ($file->isDir()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); } }
    rmdir($fixture);
}
