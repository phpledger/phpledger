<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$plInstallServices = dirname(__DIR__) . '/includes/functions/install_functions.php';
if (!is_file($plInstallServices) || !is_file(dirname(__DIR__) . '/includes/functions/runtime_functions.php')
    || !is_file(dirname(__DIR__) . '/includes/functions/database_platform_functions.php')
    || !is_file(dirname(__DIR__) . '/includes/functions/database_functions.php')) {
    fwrite(STDERR, "Installation service files are missing. Verify the complete release package.\n");
    exit(1);
}
require_once $plInstallServices;

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        pl_install_require_runtime();
        fwrite(STDOUT, 'Runtime: PHP ' . PHP_VERSION . " (minimum 8.2), required extensions and Composer dependencies are available.\n");
        $session = pl_install_session_check((string) ini_get('session.save_handler'), (string) ini_get('session.save_path'));
        fwrite(STDOUT, 'Session ' . $session['status'] . ': ' . $session['message'] . "\n");
        if ($session['status'] === 'error') {
            exit(1);
        }
        try {
            require dirname(__DIR__) . '/includes/bootstrap.php';
        } catch (Throwable $error) {
            // A local config file can throw arbitrary exception text; never print it.
            throw new RuntimeException('Application configuration could not be loaded.');
        }
        $state = pl_install_database_check();
        $platform = pl_database_platform(pl_database_server_version());
        fwrite(STDOUT, 'Database: ' . ($platform['engine'] === 'mariadb' ? 'MariaDB ' : 'MySQL ') . $platform['version'] . " connection available. No schema or account writes were performed.\n");
        if ($state['status'] !== 'current') {
            // Name any server configuration that would stop the schema part-way, before migrations run.
            $triggers = pl_database_trigger_support();
            if ($triggers !== null) {
                fwrite(STDERR, $triggers . "\n");
                exit(1);
            }
        }
        fwrite(STDOUT, $state['status'] === 'current'
            ? "Database schema is current; all migration checksums match.\n"
            : 'Ready to run migrations: ' . $state['pending'] . ' pending'
                . (($state['resuming'] ?? null) !== null ? ', resuming ' . $state['resuming'] . ' where it stopped' : '')
                . ". Back up an existing installation first.\n");
    } catch (DomainException $error) {
        fwrite(STDERR, $error->getMessage() . "\n");
        exit(1);
    } catch (Throwable $error) {
        fwrite(STDERR, "Database/configuration check failed. Check config.local.php or PL_DB_* settings, server access and credentials. No configuration values are printed.\n");
        exit(1);
    }
}
