<?php
/**
 * PHP Ledger - Softaculous custom package installer.
 *
 * Spec: https://www.softaculous.com/docs/developers/making-custom-package/
 * ("install.php" must define __install(); $__settings, $software and $error
 * are supplied by Softaculous as globals; sconfigure()/sdb_import()/
 * sdb_query()/sunzip()/schmod()/smkdir()/swrite()/sreplace() are its
 * documented helper functions).
 *
 * This file does not reimplement application setup logic. It writes the
 * one private config file the application defines
 * (www/phpledger/includes/config.local.php), prepares the one private
 * directory the application requires
 * (www/phpledger/storage/installation, mode 0700), and then invokes the
 * application's own shipped, non-interactive installer scripts in order:
 *   1. www/phpledger/install/migrate.php
 *   2. www/phpledger/install/create-admin.php   (password via env, never argv)
 *   3. www/phpledger/install/complete.php
 * exactly as the container entrypoint and the CasaOS/CapRover/Coolify
 * catalogue manifests under resources/distribution/ do. See
 * AGENT_MESSAGES.MD (2026-09-21, "Softaculous install.php will call your
 * non-interactive install path") and issue #101.
 *
 * No credential is ever hardcoded here; every value in config.local.php and
 * every install-script argument comes from Softaculous's own $__settings.
 */

declare(strict_types=1);

function __install()
{
    global $__settings, $software, $error;

    $base = isset($__settings['softpath']) ? rtrim((string) $__settings['softpath'], '/') . '/' : '';
    if ($base === '' || !is_dir($base)) {
        $error[] = 'Softaculous did not provide a valid install path (softpath).';
        return;
    }

    $configPath = $base . 'www/phpledger/includes/config.local.php';
    $storagePath = $base . 'www/phpledger/storage/installation';
    $installDir = $base . 'www/phpledger/install';

    if (!is_dir($installDir)) {
        $error[] = 'Package layout is missing www/phpledger/install; verify the release archive.';
        return;
    }

    // --- 1. Build the public URL from what Softaculous knows about this install. ---
    $scheme = 'https';
    $domain = isset($__settings['softdomain']) ? (string) $__settings['softdomain'] : '';
    $directory = isset($__settings['softdirectory']) ? trim((string) $__settings['softdirectory'], '/') : '';
    if ($domain === '') {
        $error[] = 'Softaculous did not provide a domain (softdomain) for the install.';
        return;
    }
    $publicUrl = $scheme . '://' . $domain . ($directory !== '' ? '/' . $directory : '');

    // --- 2. Resolve the database port. Softaculous's own docs do not list a
    //        softdbport settings key, so this package collects it as its own
    //        field (install.xml: db_port, default 3306). ---
    $dbPort = 3306;
    if (isset($__settings['db_port']) && (string) $__settings['db_port'] !== '') {
        $dbPort = (int) $__settings['db_port'];
    }

    foreach (array('softdbhost', 'softdb', 'softdbuser', 'softdbpass') as $required) {
        if (!isset($__settings[$required]) || (string) $__settings[$required] === '') {
            $error[] = "Softaculous did not provide required database setting: {$required}.";
            return;
        }
    }

    // --- 3. Write the application's private config file. This OVERRIDES any
    //        PL_DB_* / PL_PUBLIC_URL environment variables per the
    //        application's own contract (docs referenced in
    //        resources/distribution/README.md). ---
    $configBody = "<?php\n"
        . "declare(strict_types=1);\n\n"
        . "return array(\n"
        . "    'host' => " . var_export((string) $__settings['softdbhost'], true) . ",\n"
        . "    'port' => " . var_export($dbPort, true) . ",\n"
        . "    'database' => " . var_export((string) $__settings['softdb'], true) . ",\n"
        . "    'user' => " . var_export((string) $__settings['softdbuser'], true) . ",\n"
        . "    'password' => " . var_export((string) $__settings['softdbpass'], true) . ",\n"
        . "    'public_url' => " . var_export($publicUrl, true) . ",\n"
        . ");\n";

    if (function_exists('swrite')) {
        if (!swrite($configPath, $configBody, true)) {
            $error[] = 'Could not write www/phpledger/includes/config.local.php (swrite failed).';
            return;
        }
    } else {
        // Documented helper unavailable in this panel build; fall back to a
        // direct write rather than leaving the app unconfigured.
        if (@file_put_contents($configPath, $configBody) === false) {
            $error[] = 'Could not write www/phpledger/includes/config.local.php.';
            return;
        }
    }
    @chmod($configPath, 0640);

    // --- 4. Create/secure the application's private writable directory.
    //        The app refuses every request until this exists at 0700. ---
    if (!is_dir($storagePath)) {
        $created = function_exists('smkdir') ? smkdir($storagePath, 0700) : @mkdir($storagePath, 0700, true);
        if (!$created && !is_dir($storagePath)) {
            $error[] = 'Could not create www/phpledger/storage/installation.';
            return;
        }
    }
    if (function_exists('schmod')) {
        schmod($storagePath, 0700);
    } else {
        @chmod($storagePath, 0700);
    }

    // --- 5. Run the application's own installer scripts. Softaculous
    //        packages execute as PHP inside the panel; shelling out to a
    //        CLI PHP binary is the documented way custom packages run
    //        multi-step setups (sdb_query()/sdb_import() cover SQL only,
    //        not arbitrary scripts), so this mirrors that pattern with
    //        exec()/proc_open(). ---
    $canShellOut = function_exists('proc_open') && function_exists('exec');
    if (!$canShellOut) {
        // Documented fallback: many hosts running Softaculous disable
        // exec()/proc_open() in php.ini's disable_functions. When that is
        // the case here, this package cannot complete steps 5-7
        // automatically. Rather than silently leaving the install half
        // done, report exactly what the operator (the host's admin, who
        // controls SSH/cron on that account) must run once, using the same
        // config file this step just wrote:
        $error[] = 'PHP exec()/proc_open() are disabled on this server, so this '
            . 'package cannot run the application installer automatically. '
            . 'The database and config.local.php are already prepared; finish '
            . 'manually (via SSH or a one-time cron job) by running, in order: '
            . 'php ' . $installDir . '/migrate.php ; '
            . 'PL_ADMIN_PASSWORD=... php ' . $installDir . '/create-admin.php --email=... --name=... [--username=...] ; '
            . 'php ' . $installDir . '/complete.php';
        return;
    }

    $phpBinary = (defined('PHP_BINARY') && PHP_BINARY !== '') ? PHP_BINARY : 'php';

    // 5a. Schema migration - no secrets involved.
    $migrateCmd = escapeshellarg($phpBinary) . ' ' . escapeshellarg($installDir . '/migrate.php') . ' 2>&1';
    exec($migrateCmd, $migrateOutput, $migrateStatus);
    if ($migrateStatus !== 0) {
        $error[] = 'Database migration failed: ' . trim(implode("\n", $migrateOutput));
        return;
    }

    // 5b. Administrator account. The application explicitly refuses a
    //     password passed as a command argument (process-list exposure), so
    //     it must arrive as the PL_ADMIN_PASSWORD environment variable of
    //     the child process only - never interpolated into the command
    //     string, never logged.
    $adminEmail = isset($__settings['admin_email']) ? (string) $__settings['admin_email'] : '';
    $adminName = isset($__settings['admin_name']) ? (string) $__settings['admin_name'] : '';
    $adminUsername = isset($__settings['admin_username']) ? (string) $__settings['admin_username'] : '';
    $adminPassword = isset($__settings['admin_pass']) ? (string) $__settings['admin_pass'] : '';

    if ($adminEmail === '' || $adminName === '' || $adminPassword === '') {
        $error[] = 'Administrator email, name and password are all required.';
        return;
    }

    $createAdminCmd = escapeshellarg($phpBinary) . ' ' . escapeshellarg($installDir . '/create-admin.php')
        . ' --email=' . escapeshellarg($adminEmail)
        . ' --name=' . escapeshellarg($adminName);
    if ($adminUsername !== '') {
        $createAdminCmd .= ' --username=' . escapeshellarg($adminUsername);
    }

    $descriptors = array(1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
    $process = proc_open($createAdminCmd, $descriptors, $pipes, $installDir, array('PL_ADMIN_PASSWORD' => $adminPassword));
    $adminPassword = '';
    unset($adminPassword);

    if (!is_resource($process)) {
        $error[] = 'Could not launch the administrator-creation step.';
        return;
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $createAdminStatus = proc_close($process);
    if ($createAdminStatus !== 0) {
        $error[] = 'Administrator account creation failed: ' . trim($stderr !== '' ? $stderr : $stdout);
        return;
    }

    // 5c. Completion receipt - no secrets involved.
    $completeCmd = escapeshellarg($phpBinary) . ' ' . escapeshellarg($installDir . '/complete.php') . ' 2>&1';
    exec($completeCmd, $completeOutput, $completeStatus);
    if ($completeStatus !== 0) {
        $error[] = 'Install completion step failed: ' . trim(implode("\n", $completeOutput));
        return;
    }
}
