<?php
declare(strict_types=1);
// A private runtime mirror lets this regression remove the catalog without touching the checkout.
if (getenv('PL_ENV') !== 'test' || getenv('PL_DB_NAME') !== 'phpledger_test') { exit(2); }
$job = json_decode((string) file_get_contents('php://stdin'), true, 512, JSON_THROW_ON_ERROR);
$source = dirname(__DIR__);
$mirror = sys_get_temp_dir() . '/phpledger-pos-retry-' . bin2hex(random_bytes(12));
$includes = $mirror . '/www/phpledger/includes';
mkdir($includes, 0700, true);
symlink($source . '/vendor', $mirror . '/vendor');
symlink($source . '/www/phpledger/includes/functions', $includes . '/functions');
// 1.2 M7 added includes/models to the bootstrap, so the mirror carries it too.
symlink($source . '/www/phpledger/includes/models', $includes . '/models');
copy($source . '/www/phpledger/includes/bootstrap.php', $includes . '/bootstrap.php');
mkdir($mirror . '/resources', 0700);
symlink($source . '/resources/modules', $mirror . '/resources/modules');
symlink($source . '/www/phpledger/install', $mirror . '/www/phpledger/install');
try {
    require $includes . '/bootstrap.php';
    $f = $job['fixture'];
    // Only module metadata exists: a committed retry must never attempt catalog access.
    set_error_handler(static function (int $severity, string $message): never { throw new RuntimeException($message); });
    $receipt = pl_checkout_pos($f['actor_id'], $f['company_id'], $f['book_id'], $job['input']);
    restore_error_handler();
    echo json_encode(['id' => $receipt['document_id'], 'total' => $receipt['total']], JSON_THROW_ON_ERROR);
} finally {
    unlink($mirror . '/resources/modules');
    rmdir($mirror . '/resources');
    unlink($mirror . '/www/phpledger/install');
    unlink($includes . '/bootstrap.php');
    unlink($includes . '/models');
    unlink($includes . '/functions');
    unlink($mirror . '/vendor');
    rmdir($includes);
    rmdir($mirror . '/www/phpledger');
    rmdir($mirror . '/www');
    rmdir($mirror);
}
