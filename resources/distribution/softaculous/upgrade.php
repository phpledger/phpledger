<?php
/**
 * PHP Ledger - Softaculous custom package upgrader.
 *
 * Spec: https://www.softaculous.com/docs/developers/making-custom-package/
 * ("upgrade.php" must define __upgrade(); $__settings/$software/$error are
 * supplied as globals, with $__settings additionally carrying the older
 * version number per the docs).
 *
 * The application ships no upgrade entry point of its own (issue #100: no
 * install/upgrade.php exists in the release). Softaculous is responsible
 * for extracting the new version's files over the existing installation
 * before calling __upgrade(); this function's only job is what the
 * container image and the compose-based catalogues also do on upgrade -
 * run the schema migrator again. It is idempotent: already-applied
 * migrations are skipped (see install/migrate.php's own "already current"
 * counter).
 */

declare(strict_types=1);

function __upgrade()
{
    global $__settings, $software, $error;

    $base = isset($__settings['softpath']) ? rtrim((string) $__settings['softpath'], '/') . '/' : '';
    if ($base === '' || !is_dir($base)) {
        $error[] = 'Softaculous did not provide a valid install path (softpath).';
        return;
    }

    $installDir = $base . 'www/phpledger/install';
    $configPath = $base . 'www/phpledger/includes/config.local.php';

    if (!is_file($configPath)) {
        $error[] = 'www/phpledger/includes/config.local.php is missing; this does not look like an existing PHP Ledger install.';
        return;
    }
    if (!is_file($installDir . '/migrate.php')) {
        $error[] = 'Package layout is missing www/phpledger/install/migrate.php; verify the release archive.';
        return;
    }

    if (!function_exists('exec')) {
        $error[] = 'PHP exec() is disabled on this server, so this package cannot run the migration '
            . 'automatically. Finish manually (via SSH or a one-time cron job) by running: '
            . 'php ' . $installDir . '/migrate.php';
        return;
    }

    $phpBinary = (defined('PHP_BINARY') && PHP_BINARY !== '') ? PHP_BINARY : 'php';
    $migrateCmd = escapeshellarg($phpBinary) . ' ' . escapeshellarg($installDir . '/migrate.php') . ' 2>&1';
    exec($migrateCmd, $migrateOutput, $migrateStatus);
    if ($migrateStatus !== 0) {
        $error[] = 'Database migration failed during upgrade: ' . trim(implode("\n", $migrateOutput));
        return;
    }
}
