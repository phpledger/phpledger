<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('PL_ENV') !== 'demo-install'
    || getenv('PL_DB_NAME') !== 'phpledger_demo' || getenv('PL_DB_USER') !== 'ledger_demo_reset'
    || getenv('PL_DEMO_RESET_MODE') !== '1') {
    fwrite(STDERR, "Shared-demo reset requires its dedicated CLI maintenance environment.\n");
    exit(2);
}

/** Delete only this volume's disposable runtime children; never follow links. */
function pl_shared_demo_remove_runtime_child(string $path): void
{
    if (is_link($path) || !is_dir($path)) {
        if (!unlink($path)) {
            throw new RuntimeException('Disposable file cleanup failed.');
        }
        return;
    }
    foreach (new DirectoryIterator($path) as $entry) {
        if (!$entry->isDot()) {
            pl_shared_demo_remove_runtime_child($entry->getPathname());
        }
    }
    if (!rmdir($path)) {
        throw new RuntimeException('Disposable directory cleanup failed.');
    }
}

$lock = null;
try {
    $base = '/var/lib/phpledger';
    if (realpath($base) !== $base || is_link($base)) {
        throw new RuntimeException('Dedicated demo volume unavailable.');
    }
    $host = $base . '/host';
    if (!is_dir($host) && !mkdir($host, 0755)) {
        throw new RuntimeException('Maintenance storage unavailable.');
    }
    if (realpath($host) !== $host || is_link($host)) {
        throw new RuntimeException('Maintenance storage is not a dedicated directory.');
    }
    $lock = fopen($host . '/reset.lock', 'c+');
    if ($lock === false) {
        throw new RuntimeException('Maintenance lock unavailable.');
    }
    chmod($host . '/reset.lock', 0644);
    $deadline = microtime(true) + 40;
    while (!flock($lock, LOCK_EX | LOCK_NB)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Active requests did not drain.');
        }
        usleep(100000);
    }
    $markerPath = $host . '/generation.json';
    $marker = is_file($markerPath) ? json_decode((string) file_get_contents($markerPath), true) : null;
    if (is_file($markerPath) && (!is_array($marker) || ($marker['format'] ?? null) !== 1
        || ($marker['database'] ?? null) !== 'phpledger_demo'
        || !is_string($marker['instance'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $marker['instance'])
        || !is_int($marker['expires_at'] ?? null))) {
        throw new RuntimeException('Invalid demo ownership marker.');
    }
    if (is_array($marker) && $marker['expires_at'] > time() && !in_array('--now', $argv, true)) {
        fwrite(STDOUT, "Shared demo reset is not due; no data changed.\n");
        exit(0);
    }
    require dirname(__DIR__) . '/vendor/autoload.php';
    DB::$host = (string) getenv('PL_DB_HOST');
    DB::$port = (int) (getenv('PL_DB_PORT') ?: 3306);
    DB::$user = 'ledger_demo_reset';
    DB::$password = (string) getenv('PL_DB_PASSWORD');
    DB::$dbName = 'information_schema';
    if (DB::$host === '' || DB::$password === '') {
        throw new RuntimeException('Dedicated maintenance connection unavailable.');
    }
    if ($marker === null && (int) DB::queryFirstField("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'phpledger_demo'") !== 0) {
        throw new RuntimeException('Unmarked nonempty database preserved.');
    }
    $runtime = $base . '/runtime';
    if (file_exists($runtime) && (realpath($runtime) !== $runtime || is_link($runtime))) {
        throw new RuntimeException('Disposable runtime path is not a dedicated directory.');
    }
    // Invalidate first: a failed reset must never reopen partial state to visitors.
    $next = ['format' => 1, 'database' => 'phpledger_demo',
        'instance' => $marker['instance'] ?? bin2hex(random_bytes(32)), 'expires_at' => 0];
    if (file_put_contents($markerPath, json_encode($next, JSON_THROW_ON_ERROR)) === false) {
        throw new RuntimeException('Demo invalidation failed.');
    }
    chmod($markerPath, 0644);
    DB::query('DROP DATABASE IF EXISTS phpledger_demo');
    DB::query('CREATE DATABASE phpledger_demo CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
    if (is_dir($runtime)) {
        foreach (new DirectoryIterator($runtime) as $entry) {
            if (!$entry->isDot()) {
                pl_shared_demo_remove_runtime_child($entry->getPathname());
            }
        }
    } elseif (!mkdir($runtime, 0750)) {
        throw new RuntimeException('Disposable runtime creation failed.');
    }
    if (!chown($runtime, 'www-data') || !chgrp($runtime, 'www-data') || !chmod($runtime, 0750)) {
        throw new RuntimeException('Disposable runtime permissions failed.');
    }
    $sessions = $runtime . '/sessions';
    if (!mkdir($sessions, 0700) || !chown($sessions, 'www-data') || !chgrp($sessions, 'www-data')) {
        throw new RuntimeException('Disposable session storage creation failed.');
    }
    $next['generation'] = bin2hex(random_bytes(32));
    $next['expires_at'] = (intdiv(time(), 3600) + 1) * 3600;
    $next['reset_at'] = time();
    if (file_put_contents($markerPath, json_encode($next, JSON_THROW_ON_ERROR)) === false) {
        throw new RuntimeException('Demo generation publication failed.');
    }
    fwrite(STDOUT, "Shared demo refreshed to the uninstalled state; next reset is at the next UTC hour.\n");
} catch (Throwable $error) {
    fwrite(STDERR, "Shared demo reset failed; access remains guarded. Inspect the dedicated database and host marker without bypassing ownership checks.\n");
    exit(1);
} finally {
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
