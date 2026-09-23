<?php
declare(strict_types=1);
if (getenv('PL_ENV') !== 'test' || getenv('PL_DB_HOST') !== 'db_test') { fwrite(STDERR, "Use the isolated Docker test service.\n"); exit(2); }
require dirname(__DIR__) . '/www/phpledger/includes/functions/update_functions.php';
require dirname(__DIR__) . '/www/phpledger/includes/functions/update_database_functions.php';
require dirname(__DIR__) . '/www/phpledger/includes/functions/update_probe_functions.php';
require dirname(__DIR__) . '/www/phpledger/includes/functions/install_web_functions.php';
require dirname(__DIR__) . '/vendor/autoload.php';
$fixture = sys_get_temp_dir() . '/phpledger-update-http-' . bin2hex(random_bytes(8));
$root = $fixture . '/app'; $private = $fixture . '/private';
mkdir($root . '/www/phpledger/public', 0700, true); mkdir($private, 0700, true);
$repository = dirname(__DIR__);
foreach (['update_functions.php', 'update_database_functions.php', 'update_web_functions.php', 'update_probe_functions.php', 'database_platform_functions.php', 'database_functions.php', 'update_channel_functions.php'] as $name) {
    pl_update_write($root . '/www/phpledger/includes/functions/' . $name, (string) file_get_contents($repository . '/www/phpledger/includes/functions/' . $name));
}
$loader = (string) file_get_contents($repository . '/www/phpledger/public/maintenance.php');
pl_update_write($root . '/www/phpledger/public/maintenance.php', $loader);
pl_update_write($root . '/www/phpledger/public/index.php', '<?php require dirname(__DIR__)."/includes/functions/update_functions.php"; try {pl_update_application_guard(dirname(__DIR__,3)); echo "application-open";} catch(Throwable $e) {http_response_code(503); echo "maintenance-closed";}');
$database = 'phpledger_update_http_' . bin2hex(random_bytes(8));
DB::$host = 'db_test'; DB::$user = 'root'; DB::$password = 'local-test-root-only'; DB::$dbName = 'information_schema'; DB::$encoding = 'utf8mb4';
// MariaDB runs the same fixtures through the shared dialect translation.
require_once dirname(__DIR__) . '/www/phpledger/includes/functions/database_platform_functions.php';
pl_database_use_dialect();
DB::query('CREATE DATABASE %b CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci', $database); DB::query('USE %b', $database);
DB::query('CREATE TABLE pl_schema_migrations (version VARCHAR(100) PRIMARY KEY, status VARCHAR(10)) ENGINE=InnoDB');
DB::insert('pl_schema_migrations', ['version' => '001_sample', 'status' => 'applied']);
DB::query('CREATE TABLE pl_journal_lines (id INT PRIMARY KEY, journal_id INT, company_id INT, book_id INT, debit DECIMAL(20,4), credit DECIMAL(20,4)) ENGINE=InnoDB');
DB::insert('pl_journal_lines', ['id' => 1, 'journal_id' => 1, 'company_id' => 1, 'book_id' => 1, 'debit' => '10.0000', 'credit' => '10.0000']);
pl_update_write($root . '/www/phpledger/includes/config.local.php', '<?php return ' . var_export(['host' => 'db_test', 'port' => 3306, 'database' => $database, 'user' => 'root', 'password' => 'local-test-root-only'], true) . ';');
pl_update_write($root . '/www/phpledger/includes/bootstrap.php', '<?php // original working fixture');
pl_update_write($root . '/vendor/sergeytsalkov/meekrodb/db.class.php', (string) file_get_contents($repository . '/vendor/sergeytsalkov/meekrodb/db.class.php'));
pl_update_checkpoint($root . '/PACKAGE-MANIFEST.json', ['version' => '0.6.0-preview', 'files' => []]);
$operator = bin2hex(random_bytes(32)); pl_update_write($private . '/operator.key', $operator);
$key = openssl_pkey_new(['private_key_bits' => 3072, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if (!$key) { throw new RuntimeException('Sample signing fixture unavailable.'); }
pl_update_write($private . '/publisher.pem', openssl_pkey_get_details($key)['key']);
$port = random_int(20000, 50000); $base = 'http://127.0.0.1:' . $port;
$environment = array_replace(getenv(), ['PL_ENV' => 'test', 'PL_INSTALL_DIRECTORY' => $private, 'PL_INSTALL_CONFIG_PATH' => $root . '/www/phpledger/includes/config.local.php']);
$log = $fixture . '/server.log';
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root . '/www/phpledger/public'], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root, $environment);
if (!is_resource($server)) { throw new RuntimeException('Sample HTTP server unavailable.'); }
fclose($pipes[0]); $cookie = '';
$request = static function (string $path, ?string $body = null, string $type = 'application/x-www-form-urlencoded') use ($base, &$cookie): array {
    $headers = ['Connection: close']; if ($cookie !== '') { $headers[] = 'Cookie: ' . $cookie; }
    if ($body !== null) { $headers[] = 'Content-Type: ' . $type; }
    $context = stream_context_create(['http' => ['method' => $body === null ? 'GET' : 'POST', 'header' => implode("\r\n", $headers), 'content' => $body ?? '', 'ignore_errors' => true, 'timeout' => 10]]);
    $response = @file_get_contents($base . $path, false, $context); $responseHeaders = $http_response_header ?? [];
    foreach ($responseHeaders as $header) { if (preg_match('/^Set-Cookie: ([^;]+)/i', $header, $match)) { $cookie = $match[1]; } }
    preg_match('/\s(\d{3})\s/', $responseHeaders[0] ?? '', $status);
    return [(int) ($status[1] ?? 0), $response === false ? '' : $response];
};
$check = static function (bool $condition, string $label): void { clearstatcache(); if (!$condition) { throw new RuntimeException($label); } echo "PASS $label\n"; };
try {
    $secureCases = [
        [['HTTPS' => 'on'], true], [['HTTPS' => 'ON'], true], [['HTTPS' => '1'], true], [['SERVER_PORT' => '443'], true],
        [['HTTPS' => 'off', 'SERVER_PORT' => 80], false], [['HTTPS' => '0', 'SERVER_PORT' => 80], false],
        [['HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_PORT' => '443', 'HTTP_FORWARDED' => 'proto=https', 'SERVER_PORT' => 80], false],
    ];
    foreach ($secureCases as [$serverVariables, $expected]) {
        if (pl_update_secure_request($serverVariables) !== $expected || pl_update_secure_request($serverVariables) !== pl_install_secure_request($serverVariables)) {
            throw new RuntimeException('Installer/updater HTTPS normalization differs.');
        }
    }
    $check(true, 'installer and updater normalize HTTPS on, 1 and port 443 without trusting forwarded headers');
    for ($retry = 0; $retry < 50; $retry++) { [$status, $body] = $request('/maintenance.php'); if ($status) { break; } usleep(20000); }
    $check($status === 200 && str_contains($body, 'Host installation operator key'), 'independent operator page is reachable');
    preg_match('/name="csrf" value="([a-f0-9]+)"/', $body, $match); $csrf = $match[1] ?? '';
    [$status] = $request('/maintenance.php', http_build_query(['action' => 'authenticate', 'operator_key' => $operator, 'csrf' => 'wrong']));
    $check($status === 422, 'missing CSRF rejects even a valid operator key');
    [$status] = $request('/maintenance.php', http_build_query(['action' => 'authenticate', 'operator_key' => 'company-owner-session', 'csrf' => $csrf]));
    $check($status === 422, 'company authority cannot authenticate as installation operator');
    [$status, $body] = $request('/maintenance.php', http_build_query(['action' => 'authenticate', 'operator_key' => $operator, 'csrf' => $csrf]));
    $check($status === 200 && !str_contains($body, $operator), 'host proof authenticates without reflecting its secret');
    $files = ['www/phpledger/public/maintenance.php' => $loader, 'www/phpledger/public/index.php' => '<?php echo "updated";', 'www/phpledger/includes/bootstrap.php' => '<?php // sample', 'vendor/autoload.php' => '<?php // sample', 'PACKAGE-MANIFEST.json' => '{"version":"0.8.0-preview","files":[]}'];
    $archive = $fixture . '/release.zip'; $zip = new ZipArchive(); $zip->open($archive, ZipArchive::CREATE); $inventory = [];
    foreach ($files as $path => $data) { $zip->addFromString('phpledger-0.8.0-preview/' . $path, $data); $inventory[] = ['path' => $path, 'bytes' => strlen($data), 'sha256' => hash('sha256', $data)]; }
    $zip->close();
    $payload = json_encode(['schema' => 1, 'version' => '0.8.0-preview', 'channel' => 'preview', 'min_php' => '8.2.0', 'archive_bytes' => filesize($archive), 'archive_sha256' => hash_file('sha256', $archive), 'files' => $inventory], JSON_THROW_ON_ERROR);
    openssl_sign($payload, $signature, $key, OPENSSL_ALGO_SHA256);
    $envelope = json_encode(['payload' => base64_encode($payload), 'signature' => base64_encode($signature)], JSON_THROW_ON_ERROR);
    $boundary = 'sample-' . bin2hex(random_bytes(8)); $body = '';
    foreach (['csrf' => $csrf, 'action' => 'begin', 'operator_key' => $operator, 'channel' => 'preview', 'source' => 'upload'] as $name => $value) {
        $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"\r\n\r\n$value\r\n";
    }
    foreach (['archive' => ['release.zip', file_get_contents($archive)], 'metadata' => ['release.json', $envelope]] as $name => [$filename, $data]) {
        $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"; filename=\"$filename\"\r\nContent-Type: application/octet-stream\r\n\r\n$data\r\n";
    }
    $body .= "--$boundary--\r\n";
    [$status, $body] = $request('/maintenance.php', $body, 'multipart/form-data; boundary=' . $boundary);
    $check($status === 200 && is_file($private . '/updates/active.json') && str_contains($body, 'backup'), 'authenticated signed upload enters maintenance before mutation');
    [$status, $body] = $request('/');
    $check($status === 503 && $body === 'maintenance-closed', 'ordinary application bootstrap is blocked with HTTP 503');
    $id = pl_update_json($private . '/updates/active.json')['id']; $operation = $private . '/updates/' . $id;
    putenv('PL_INSTALL_DIRECTORY=' . $private);
    $backup = pl_update_file_backup($root, $operation, pl_update_json($operation . '/release.json'));
    $receipt = null;
    for ($step = 0; $receipt === null && $step < 20; $step++) { $receipt = pl_update_database_backup($operation . '/backup/database'); }
    $backup['database'] = $receipt; pl_update_checkpoint($operation . '/backup.json', $backup);
    DB::insert('pl_schema_migrations', ['version' => '999_failed', 'status' => 'applying']);
    pl_update_write($root . '/www/phpledger/includes/bootstrap.php', '<?php BROKEN UPDATED APPLICATION !!!');
    [$status, $body] = $request('/maintenance.php');
    $check($status === 200 && str_contains($body, 'Continue update or recovery'), 'saved recovery runtime survives a broken application bootstrap');
    $state = pl_update_json($operation . '/state.json'); $state['phase'] = 'runtime'; pl_update_checkpoint($operation . '/state.json', $state);
    $request('/maintenance.php', http_build_query(['action' => 'continue', 'csrf' => $csrf]));
    $state = pl_update_json($operation . '/state.json');
    $check($state['phase'] === 'recovering' && $state['error'] === 'runtime_probe_failed', 'fresh runtime probe detects a parse error and requests rollback');
    // The marker is unlinked by another process; clear this process's stat cache before each poll.
    for ($step = 0; (clearstatcache(true, $private . '/updates/active.json') || true) && is_file($private . '/updates/active.json') && $step < 60; $step++) { $request('/maintenance.php', http_build_query(['action' => 'continue', 'csrf' => $csrf])); }
    clearstatcache(true, $private . '/updates/active.json');
    $state = pl_update_json($operation . '/state.json');
    $check($state['phase'] === 'restored' && !is_file($private . '/updates/active.json')
        && file_get_contents($root . '/www/phpledger/includes/bootstrap.php') === '<?php // original working fixture'
        && (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_schema_migrations') === 1, 'bad new bootstrap automatically restores matched code and real database before reopening');
    // Run the real current bootstrap in a separate fresh HTTP probe, against only this sample DB.
    pl_update_write($root . '/www/phpledger/includes/bootstrap.php', '<?php require ' . var_export($repository . '/www/phpledger/includes/bootstrap.php', true) . ';');
    $state['phase'] = 'runtime'; $state['inflight'] = false; pl_update_checkpoint($operation . '/state.json', $state);
    pl_update_checkpoint($private . '/updates/active.json', ['id' => $id]);
    $request('/maintenance.php', http_build_query(['action' => 'continue', 'csrf' => $csrf]));
    $state = pl_update_json($operation . '/state.json');
    $check($state['phase'] === 'complete' && !is_file($private . '/updates/active.json'), 'fresh real application bootstrap and database probe releases maintenance');
    [$status, $body] = $request('/');
    $check($status === 200 && $body === 'application-open', 'normal requests reopen only after fresh runtime success');
    foreach (['complete', 'restored', 'aborted'] as $terminalPhase) {
        // Simulate termination after the terminal checkpoint but before marker deletion.
        $state['phase'] = $terminalPhase; $state['inflight'] = false; pl_update_checkpoint($operation . '/state.json', $state);
        pl_update_checkpoint($private . '/updates/active.json', ['id' => $id]);
        [$closedStatus] = $request('/');
        [$status, $body] = $request('/maintenance.php');
        $canResume = $status === 200 && str_contains($body, 'Continue update or recovery');
        $request('/maintenance.php', http_build_query(['action' => 'continue', 'csrf' => $csrf]));
        [$openStatus] = $request('/');
        $check($closedStatus === 503 && $canResume && $openStatus === 200 && !is_file($private . '/updates/active.json'), 'browser finalizes an interrupted ' . $terminalPhase . ' checkpoint before reopening');
    }
    pl_update_write($private . '/operator.key', bin2hex(random_bytes(32)));
    [$status] = $request('/maintenance.php', http_build_query(['action' => 'continue', 'csrf' => $csrf]));
    $check($status === 422, 'rotating the host key revokes an authenticated operator session');
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $request('/maintenance.php', http_build_query(['action' => 'authenticate', 'operator_key' => 'sample-guess-' . $attempt, 'csrf' => $csrf]));
    }
    [$status, $body] = $request('/maintenance.php', http_build_query(['action' => 'authenticate', 'operator_key' => 'sample-guess-final', 'csrf' => $csrf]));
    $check($status === 422 && str_contains($body, 'Too many installation operator key attempts'), 'remote operator key guessing is bounded');
    echo "Installation update HTTP authority, CSRF, signed upload, maintenance barrier and independent recovery checks passed.\n";
} finally {
    proc_terminate($server); proc_close($server);
    putenv('PL_INSTALL_DIRECTORY');
    if (preg_match('/^phpledger_update_http_[a-f0-9]{16}$/D', $database)) { DB::query('USE information_schema'); DB::query('DROP DATABASE %b', $database); }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) { if ($file->isDir()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); } }
    rmdir($fixture);
}
