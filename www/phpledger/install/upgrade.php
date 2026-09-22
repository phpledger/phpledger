<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/preflight.php';

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        pl_install_require_runtime();
        try {
            require dirname(__DIR__) . '/includes/bootstrap.php';
        } catch (Throwable $error) {
            throw new RuntimeException('Application configuration could not be loaded.');
        }
        $state = pl_install_database_check();
        if ($state['status'] === 'empty') {
            throw new DomainException('No existing installation was found. Use the browser installer for a fresh installation.');
        }
        if ($state['status'] === 'current') {
            fwrite(STDOUT, "Already up to date; all migration checksums match.\n");
        } else {
            pl_database_require_trigger_support();
            $result = pl_migrate();
            $verified = pl_install_database_check();
            if ($verified['status'] !== 'current') {
                throw new RuntimeException('Migration verification did not complete.');
            }
            fwrite(STDOUT, 'Upgrade complete. Migrations applied: ' . count($result['applied'])
                . '; already current: ' . count($result['skipped']) . PHP_EOL);
        }
    } catch (Throwable $error) {
        fwrite(STDERR, ($error instanceof DomainException ? $error->getMessage()
            : 'Upgrade failed. Check the local database configuration and migration receipts; keep application traffic stopped.') . PHP_EOL);
        exit(1);
    }
}
