<?php
declare(strict_types=1);

/*
 * The green and red lights on the setup pages, and the plain-language names for
 * the schema steps behind the progress bar.
 *
 * Every installer the owner asked us to compare shows its checks before asking
 * for anything: OpenCart's pre-installation table, Joomla's pre-installation
 * check, Moodle's server checks, phpBB's compatibility table. Drupal shows a
 * percentage bar with a live status line while it writes the schema. A missing
 * extension, an unwritable folder or a stalled step then names itself here,
 * instead of surfacing later as a broken page.
 *
 * These functions read; none of them writes to the database or to the site.
 */

require_once __DIR__ . '/install_functions.php';
require_once __DIR__ . '/installation_state_functions.php';

const PL_CHECK_PASS = 'pass';
const PL_CHECK_WARN = 'warn';
const PL_CHECK_FAIL = 'fail';

/** @return array{name: string, status: string, detail: string} */
function pl_install_check_row(string $name, string $status, string $detail): array
{
    return ['name' => $name, 'status' => $status, 'detail' => $detail];
}

/** The worst light in a list, so one summary line can stand for the whole table. */
function pl_install_check_summary(array $checks): string
{
    $status = PL_CHECK_PASS;
    foreach ($checks as $check) {
        if (($check['status'] ?? '') === PL_CHECK_FAIL) {
            return PL_CHECK_FAIL;
        }
        if (($check['status'] ?? '') === PL_CHECK_WARN) {
            $status = PL_CHECK_WARN;
        }
    }
    return $status;
}

function pl_install_check_counts(array $checks): array
{
    $counts = [PL_CHECK_PASS => 0, PL_CHECK_WARN => 0, PL_CHECK_FAIL => 0];
    foreach ($checks as $check) {
        $status = (string) ($check['status'] ?? PL_CHECK_PASS);
        $counts[$status] = ($counts[$status] ?? 0) + 1;
    }
    return $counts;
}

/**
 * Taken as arguments rather than read from the constants, so this states the rule
 * rather than whatever the analyser's platform happens to be.
 *
 * @return array{name: string, status: string, detail: string}
 */
function pl_install_php_version_check(int $version, string $display): array
{
    return pl_install_check_row('PHP version', $version >= 80200 ? PL_CHECK_PASS : PL_CHECK_FAIL,
        $version >= 80200 ? $display : $display . ', but PHP Ledger needs 8.2 or newer. Choose a newer version in your hosting panel.');
}

/**
 * What this copy needs from the server, one light per fact. Nothing here needs a
 * database, so it can be shown before the owner types any credentials.
 *
 * @param array<string, mixed> $server
 * @param ?string $exposure the private-folder probe result, when the caller has one
 * @return list<array{name: string, status: string, detail: string}>
 */
function pl_install_requirement_checks(array $server = [], ?string $exposure = null): array
{
    $checks = [pl_install_php_version_check(PHP_VERSION_ID, PHP_VERSION)];
    foreach (['bcmath' => 'exact money arithmetic', 'pdo_mysql' => 'the database connection', 'mbstring' => 'text in every language',
        'curl' => 'outbound checks', 'openssl' => 'private keys', 'fileinfo' => 'checking uploaded images',
        'session' => 'signing in', 'json' => 'stored settings'] as $extension => $purpose) {
        $loaded = extension_loaded($extension);
        $checks[] = pl_install_check_row('PHP ' . $extension, $loaded ? PL_CHECK_PASS : PL_CHECK_FAIL,
            $loaded ? 'available' : 'missing, and it is needed for ' . $purpose);
    }
    $autoload = is_file(dirname(__DIR__, 4) . '/vendor/autoload.php');
    $checks[] = pl_install_check_row('Bundled dependencies', $autoload ? PL_CHECK_PASS : PL_CHECK_FAIL,
        $autoload ? 'the vendor folder is in place' : 'the vendor folder is missing. Upload the complete release package.');
    $session = pl_install_session_check((string) ini_get('session.save_handler'), (string) ini_get('session.save_path'));
    $checks[] = pl_install_check_row('Session storage',
        match ($session['status']) { 'ok' => PL_CHECK_PASS, 'warning' => PL_CHECK_WARN, default => PL_CHECK_FAIL },
        $session['status'] === 'ok' ? 'writable' : $session['message']);
    try {
        $directory = pl_install_directory();
        $writable = is_dir($directory) ? is_writable($directory) : is_writable(dirname($directory));
        $checks[] = pl_install_check_row('Private storage', $writable ? PL_CHECK_PASS : PL_CHECK_FAIL,
            $writable ? 'writable' : 'not writable. Allow PHP to write to it in your hosting file manager.');
        $configuration = dirname(pl_install_config_path());
        $checks[] = pl_install_check_row('Private configuration', is_writable($configuration) ? PL_CHECK_PASS : PL_CHECK_WARN,
            is_writable($configuration) ? 'writable' : 'not writable, so setup will offer the file for download and you upload it yourself.');
    } catch (Throwable $error) {
        $checks[] = pl_install_check_row('Private storage', PL_CHECK_FAIL, 'the private folder could not be resolved and needs operator review.');
    }
    if ($exposure !== null && $exposure !== 'not-needed') {
        $checks[] = pl_install_check_row('Private folders hidden',
            match ($exposure) { 'protected' => PL_CHECK_PASS, 'unknown' => PL_CHECK_WARN, default => PL_CHECK_FAIL },
            match ($exposure) {
                'protected' => 'visitors cannot download them',
                'unknown' => 'this could not be confirmed automatically. Use the check link above.',
                default => 'visitors can download them. Point the document root at www/phpledger/public.',
            });
    }
    $secure = (!empty($server['HTTPS']) && strtolower((string) $server['HTTPS']) !== 'off') || (int) ($server['SERVER_PORT'] ?? 0) === 443;
    if ($server !== []) {
        $checks[] = pl_install_check_row('HTTPS', $secure ? PL_CHECK_PASS : PL_CHECK_WARN,
            $secure ? 'this address uses a certificate' : 'this address is plain HTTP. Setup continues, and Connections stay unavailable until a certificate is in place.');
    }
    return $checks;
}

/**
 * Look for a database server on this computer, the way XAMPP, Laragon, MAMP and
 * Docker Desktop publish one, so the port can be filled in instead of guessed.
 * Only loopback ports are probed, each attempt is bounded, and no credentials
 * are sent: the socket is opened and closed again.
 *
 * @return list<int>
 */
function pl_install_local_database_ports(): array
{
    $found = [];
    foreach ([3306, 3307, 3308, 3310, 3320, 8889] as $port) {
        $errorNumber = 0;
        $errorText = '';
        $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errorNumber, $errorText, 0.2);
        if ($socket !== false) {
            $found[] = $port;
            fclose($socket);
        }
    }
    return $found;
}

/**
 * What setup worked out about this server by itself: the folders it will use and
 * the address it will record. Shown so the owner can confirm them rather than
 * discover later that a path was not what they expected.
 *
 * @param array<string, mixed> $server
 * @return list<array{name: string, value: string}>
 */
function pl_install_environment(array $server = [], array $databasePorts = []): array
{
    $root = realpath(dirname(__DIR__, 4));
    $root = $root === false ? dirname(__DIR__, 4) : $root;
    $shorten = static function (string $path) use ($root): string {
        $path = str_replace('\\', '/', $path);
        $prefix = rtrim(str_replace('\\', '/', $root), '/');
        return $prefix !== '' && str_starts_with(strtolower($path), strtolower($prefix) . '/')
            ? substr($path, strlen($prefix) + 1) : $path;
    };
    $environment = [['name' => 'PHP', 'value' => PHP_VERSION . ' (' . PHP_SAPI . ') on ' . PHP_OS_FAMILY]];
    if (isset($server['SERVER_SOFTWARE'])) {
        $environment[] = ['name' => 'Web server', 'value' => (string) $server['SERVER_SOFTWARE']];
    }
    if (function_exists('pl_install_suggested_public_url')) {
        $address = pl_install_suggested_public_url($server);
        if ($address !== '') {
            $environment[] = ['name' => 'This address', 'value' => $address];
        }
    }
    $environment[] = ['name' => 'Application folder', 'value' => $root];
    $environment[] = ['name' => 'Folder served to visitors', 'value' => $shorten($root . '/www/phpledger/public')];
    if (function_exists('pl_base_path')) {
        $environment[] = ['name' => 'Address folder', 'value' => pl_base_path() === '' ? '/ (the site root)' : pl_base_path()];
    }
    try {
        $environment[] = ['name' => 'Private storage', 'value' => $shorten(pl_install_directory())];
        $environment[] = ['name' => 'Private configuration', 'value' => $shorten(pl_install_config_path())];
    } catch (Throwable $error) {
        $environment[] = ['name' => 'Private storage', 'value' => 'needs operator review'];
    }
    $sessions = (string) ini_get('session.save_path');
    $environment[] = ['name' => 'Session files', 'value' => $sessions === '' ? 'the PHP default for this server' : $sessions];
    $environment[] = ['name' => 'Database servers found here', 'value' => $databasePorts === []
        ? 'none on this computer, so the database is probably on another host'
        : '127.0.0.1 port ' . implode(', port ', $databasePorts)];
    return $environment;
}

/**
 * Plain-language names for the schema steps, so the progress line says what is
 * being built rather than showing a file name. A step added later without an
 * entry here falls back to its own readable name.
 */
function pl_install_step_label(string $version): string
{
    $labels = [
        '001_foundation' => 'Creating the core tables',
        '002_product_slice' => 'Adding products and stock',
        '003_demo_isolation' => 'Separating sample data',
        '004_pos_showcase' => 'Adding the counter and till',
        '005_demo_period_guard' => 'Protecting sample periods',
        '006_core_accounts_journals' => 'Building the chart of accounts and journals',
        '006_opening_cutover' => 'Preparing opening balances',
        '007_period_administration' => 'Adding accounting periods',
        '008_bank_reconciliation' => 'Adding bank reconciliation',
        '009_bank_draft_cancellation' => 'Allowing bank drafts to be cancelled',
        '010_module_lifecycle' => 'Setting up modules',
        '011_read_connections' => 'Preparing connections for other apps',
        '012_demo_history_periods' => 'Preparing sample history',
        '013_currency_foundation' => 'Adding currencies and exchange rates',
        '014_parties_outbox' => 'Adding customers, suppliers and the outbox',
        '015_open_items' => 'Adding unpaid invoices and bills',
        '016_correction_identity' => 'Making corrections traceable',
        '017_ar_ap_documents' => 'Adding invoices, bills and credit notes',
        '018_inventory' => 'Adding stock valuation',
        '019_purchasing' => 'Adding purchase orders and goods receipts',
        '020_opening_conversion' => 'Adding opening-balance conversion',
        '021_module_visibility' => 'Deciding which modules appear',
        '022_tax_engine' => 'Adding the tax engine',
        '023_inventory_product_audit' => 'Recording product changes',
        '024_opening_allocation_guard' => 'Guarding opening allocations',
        '025_tax_price_mode' => 'Adding tax-inclusive prices',
        '026_installation_history' => 'Recording installation history',
        '027_ar_ap_upgrade' => 'Upgrading receivables and payables',
        '028_ar_ap_upgrade_completion' => 'Finishing the receivables upgrade',
        '029_connection_access' => 'Setting connection permissions',
        '030_cost_of_sales' => 'Adding cost of sales',
        '031_posting_source_lookup' => 'Indexing posted entries',
        '032_user_names' => 'Adding usernames',
        '033_installation_logo' => 'Making room for your logo',
        '034_inventory_locations' => 'Adding stock locations',
    ];
    if (isset($labels[$version])) {
        return $labels[$version];
    }
    $readable = trim(preg_replace('/^[0-9]+_/', '', $version) ?? $version);
    $readable = trim(str_replace('_', ' ', $readable));
    return $readable === '' ? 'Preparing your database' : 'Adding ' . $readable;
}

/**
 * Where the schema has got to, for the progress bar. The step named is the one
 * about to run, which is what the owner is waiting for.
 *
 * @return array{done: int, total: int, percent: int, label: string}
 */
function pl_install_progress(array $schema): array
{
    $steps = pl_install_migration_versions();
    $done = max(0, (int) ($schema['applied'] ?? 0));
    $total = max($done + max(0, (int) ($schema['pending'] ?? 0)), count($steps), 1);
    $resuming = is_string($schema['resuming'] ?? null) ? $schema['resuming'] : null;
    $current = $resuming ?? ($steps[$done] ?? null);
    return [
        'done' => $done,
        'total' => $total,
        // A step that has started but not finished should not read as 0%, and only
        // the completion page may read as 100%.
        'percent' => $done >= $total ? 100 : max(2, (int) floor($done * 100 / $total)),
        'label' => $done >= $total ? 'Finishing up' : ($current === null ? 'Preparing your database' : pl_install_step_label($current)),
    ];
}

/**
 * After the schema is written: prove the parts the owner cannot see. These read
 * the catalogue and one arithmetic result; nothing is inserted or changed.
 *
 * @return list<array{name: string, status: string, detail: string}>
 */
function pl_install_self_checks(array $schema): array
{
    $checks = [];
    $complete = ($schema['status'] ?? '') === 'current';
    $total = (int) ($schema['applied'] ?? 0) + (int) ($schema['pending'] ?? 0);
    $checks[] = pl_install_check_row('Database structure', $complete ? PL_CHECK_PASS : PL_CHECK_FAIL,
        $complete ? $schema['applied'] . ' of ' . $total . ' steps applied, and every checksum matches this release'
            : $schema['pending'] . ' of ' . $total . ' steps still to run');
    try {
        $platform = pl_database_platform(pl_database_server_version());
        $checks[] = pl_install_check_row('Database server', $platform['supported'] ? PL_CHECK_PASS : PL_CHECK_FAIL,
            ($platform['engine'] === 'mariadb' ? 'MariaDB ' : 'MySQL ') . $platform['version']
                . ($platform['supported'] ? '' : ', which is not supported. PHP Ledger needs ' . pl_database_requirement() . '.'));
        // Posted entries are kept immutable by database triggers, so their presence is
        // the one guarantee worth proving on the owner's own server.
        $triggers = (int) DB::queryFirstField('SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = DATABASE()');
        $checks[] = pl_install_check_row('Posted entries protected', $triggers > 0 ? PL_CHECK_PASS : PL_CHECK_FAIL,
            $triggers > 0 ? $triggers . ' database rules are active, so a posted entry cannot be edited away'
                : 'no protective database rules are active. Do not keep books on this copy; ask your host about trigger privileges.');
        $views = (int) DB::queryFirstField('SELECT COUNT(*) FROM information_schema.views WHERE table_schema = DATABASE()');
        $checks[] = pl_install_check_row('Report sources', $views > 0 ? PL_CHECK_PASS : PL_CHECK_WARN,
            $views > 0 ? $views . ' report views are readable by this account' : 'no report views were found');
        $zone = (string) DB::queryFirstField('SELECT @@session.time_zone');
        $checks[] = pl_install_check_row('Dates and times', $zone === '+00:00' ? PL_CHECK_PASS : PL_CHECK_WARN,
            $zone === '+00:00' ? 'events are stored in UTC, so they survive a server move' : 'this connection reports ' . $zone);
    } catch (Throwable $error) {
        $checks[] = pl_install_check_row('Database server', PL_CHECK_FAIL, 'the database could not be inspected. Check the credentials and grants.');
    }
    $exact = function_exists('bcadd') && bcadd('0.1', '0.2', 4) === '0.3000' && bcmul('19.99', '3', 4) === '59.9700';
    $checks[] = pl_install_check_row('Money arithmetic', $exact ? PL_CHECK_PASS : PL_CHECK_FAIL,
        $exact ? 'amounts add up exactly, with no rounding drift' : 'exact decimal arithmetic is unavailable on this PHP build');
    return $checks;
}
