<?php
declare(strict_types=1);

// Internal services shared by the separately guarded CLI and browser installers.
require_once __DIR__ . '/runtime_functions.php';
require_once __DIR__ . '/database_platform_functions.php';

/** Report prerequisites before loading configuration or attempting a database connection. */
function pl_install_runtime_issues(int $version, array $extensions, bool $autoloadExists): array
{
    $issues = [];
    try {
        pl_require_runtime($version);
    } catch (RuntimeException $error) {
        $issues[] = $error->getMessage();
    }
    $extensions = array_map('strtolower', $extensions);
    foreach (['bcmath', 'mbstring', 'pdo', 'pdo_mysql', 'session', 'curl', 'openssl', 'fileinfo'] as $extension) {
        if (!in_array($extension, $extensions, true)) {
            $issues[] = 'Enable the PHP ' . $extension . ' extension.';
        }
    }
    if (!$autoloadExists) {
        $issues[] = 'Composer dependencies are missing. Run composer install from the package root.';
    }
    return $issues;
}

function pl_install_require_runtime(): void
{
    $issues = pl_install_runtime_issues(PHP_VERSION_ID, get_loaded_extensions(), is_file(dirname(__DIR__, 4) . '/vendor/autoload.php'));
    if ($issues !== []) {
        throw new DomainException(implode(' ', $issues));
    }
}

/**
 * The 0.4 preview shipped an AR/AP schema under the same 017 identity. Keep
 * that one published receipt admissible so the additive 027 upgrade can
 * migrate it; every other changed receipt remains a hard failure.
 */
function pl_install_legacy_migration_checksums(): array
{
    return [
        '017_ar_ap_documents' => ['9464c5c1f3f62c294e628bb1a8361918ac0b153c155ce2fa21c9d3bd63f7b936'],
        '027_ar_ap_upgrade' => ['84067974e6e2fe81ffa4db0f075b5cbc806c0ec1895fe4bdb869653a3d69d9c3'],
    ];
}

function pl_install_checksum_matches(string $version, string $checksum, string $receiptChecksum): bool
{
    if (hash_equals($checksum, $receiptChecksum)) {
        return true;
    }
    foreach (pl_install_legacy_migration_checksums()[$version] ?? [] as $legacyChecksum) {
        if (hash_equals($legacyChecksum, $receiptChecksum)) {
            return true;
        }
    }
    return false;
}

/** This checks the CLI identity only; it does not start a session or create a probe file. */
function pl_install_session_check(string $handler, string $path): array
{
    if ($handler !== 'files') {
        return ['status' => 'warning', 'message' => 'Custom session handler cannot be verified here. Verify its configuration and access in the web runtime.'];
    }
    $parts = explode(';', $path);
    $directory = end($parts) ?: sys_get_temp_dir();
    if (!is_dir($directory) || !is_writable($directory)) {
        return ['status' => 'error', 'message' => 'The file-session directory is missing or not writable by this CLI user. Configure session.save_path and verify the web user has access.'];
    }
    if (count($parts) > 1 && (int) $parts[0] > 0) {
        return ['status' => 'warning', 'message' => 'The session root is writable, but its configured subdirectory layout must be verified in the web runtime.'];
    }
    return ['status' => 'ok', 'message' => 'File-session directory is writable by this CLI user. Confirm the web user can also use it.'];
}

/**
 * Inspect versioned receipts without issuing migrations or changing a receipt.
 *
 * An interrupted migration that recorded how far it got is reported as pending,
 * naming it in `resuming`, so the operator can fix the cause and run the same
 * step again. A receipt left behind by a version that recorded no progress is
 * still refused, because where that migration stopped is unknown.
 */
function pl_install_schema_state(?array $receipts, array $checksums, int $tableCount): array
{
    if ($receipts === null) {
        if ($tableCount !== 0) {
            throw new DomainException('The database has tables without PHP Ledger migration receipts. Use a separate empty database; do not import legacy SQL.');
        }
        return ['status' => 'empty', 'applied' => 0, 'pending' => count($checksums), 'resuming' => null];
    }
    $seen = [];
    $resuming = null;
    foreach ($receipts as $receipt) {
        $version = $receipt['version'];
        if (!isset($checksums[$version])) {
            throw new DomainException('The database contains an unknown migration. Use a compatible package; do not change its receipts.');
        }
        if (!pl_install_checksum_matches($version, $checksums[$version], (string) $receipt['checksum'])) {
            throw new DomainException('A migration checksum differs from this package. Restore the original file; do not edit the receipt.');
        }
        if ($receipt['status'] !== 'applied') {
            if (!array_key_exists('statements_done', $receipt) || $receipt['statements_done'] === null) {
                throw new DomainException('A previous migration is incomplete and recorded no progress. Inspect or restore the database before retrying installation.');
            }
            $resuming = $version;
            continue;
        }
        $seen[$version] = true;
    }
    $pending = count(array_diff_key($checksums, $seen));
    return ['status' => $pending === 0 ? 'current' : 'pending', 'applied' => count($seen), 'pending' => $pending, 'resuming' => $resuming];
}

/** @return list<string> */
function pl_install_tables(): array
{
    return DB::queryFirstColumn('SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema = DATABASE()');
}

/** @return list<string> */
function pl_install_receipt_columns(): array
{
    return DB::queryFirstColumn('SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = %s', 'pl_schema_migrations');
}

function pl_install_database_check(): array
{
    pl_database_require_supported();
    $checksums = [];
    foreach (glob(dirname(__DIR__, 2) . '/install/migrations/[0-9]*.php') ?: [] as $file) {
        $checksum = hash_file('sha256', $file);
        if ($checksum === false) {
            throw new DomainException('A migration file could not be read. Verify the package files.');
        }
        $checksums[basename($file, '.php')] = $checksum;
    }
    if ($checksums === []) {
        throw new DomainException('Migration files are missing from the package.');
    }
    $tables = pl_install_tables();
    $receipts = in_array('pl_schema_migrations', $tables, true)
        ? DB::query('SELECT version, checksum, status, ' . (in_array('statements_done', pl_install_receipt_columns(), true) ? 'statements_done' : 'NULL AS statements_done') . ' FROM pl_schema_migrations') : null;
    return pl_install_schema_state($receipts, $checksums, count($tables));
}

/** @return array{applied: list<string>, skipped: list<string>} */
function pl_migrate(?int $limit = null): array
{
    if ($limit !== null && $limit < 1) {
        throw new InvalidArgumentException('Migration batch size must be positive.');
    }
    $lock = 'phpledger:migrate:' . substr(hash('sha256', (string) DB::queryFirstField('SELECT DATABASE()')), 0, 40);
    if ((int) DB::queryFirstField('SELECT GET_LOCK(%s, 10)', $lock) !== 1) {
        throw new DomainException('Another installer is running. Try again after it finishes.');
    }
    try {
        $preflighted = false;
        if (!in_array('pl_schema_migrations', pl_install_tables(), true)) {
            // An untouched target means every migration is pending, so a server that cannot
            // create the guard triggers is refused before the first object is created.
            pl_database_require_trigger_support();
            $preflighted = true;
        }
        if (pl_database_require_supported()['engine'] === 'mariadb') {
            // Trigger variables take the database default collation; match the tables' collation so
            // comparisons inside triggers never mix collations. Hosting panels often default to another one.
            // The dialect hook translates the collation name for this server.
            DB::query('ALTER DATABASE %b CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci', (string) DB::queryFirstField('SELECT DATABASE()'));
        }
        DB::query("CREATE TABLE IF NOT EXISTS pl_schema_migrations (
            version VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
            checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            status ENUM('applying','applied') NOT NULL,
            applied_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");
        // Progress within one migration, so an interrupted run resumes at the statement that
        // failed rather than re-running schema changes MySQL cannot roll back. Receipts written
        // before this column existed keep NULL, which means "unknown" and is never resumed.
        if (!in_array('statements_done', pl_install_receipt_columns(), true)) {
            DB::query('ALTER TABLE pl_schema_migrations ADD COLUMN statements_done INT UNSIGNED NULL DEFAULT NULL');
        }
        $files = glob(dirname(__DIR__, 2) . '/install/migrations/[0-9]*.php') ?: [];
        sort($files, SORT_STRING);
        $result = ['applied' => [], 'skipped' => []];
        $known = [];
        foreach ($files as $file) {
            $known[basename($file, '.php')] = true;
        }
        foreach (DB::query('SELECT version FROM pl_schema_migrations') as $receipt) {
            if (!isset($known[$receipt['version']])) {
                throw new DomainException('This code is missing an installed migration. Use a compatible application release.');
            }
        }
        foreach ($files as $file) {
            $version = basename($file, '.php');
            $checksum = hash_file('sha256', $file);
            if ($checksum === false) {
                throw new DomainException('Cannot read migration: ' . $version);
            }
            $receipt = DB::queryFirstRow('SELECT * FROM pl_schema_migrations WHERE version = %s', $version);
            $completed = 0;
            if ($receipt) {
                if (!pl_install_checksum_matches($version, $checksum, (string) $receipt['checksum'])) {
                    throw new DomainException('Migration checksum mismatch: ' . $version . '. Restore the original migration before continuing.');
                }
                if ($receipt['status'] === 'applied') {
                    $result['skipped'][] = $version;
                    continue;
                }
                if (!array_key_exists('statements_done', $receipt) || $receipt['statements_done'] === null) {
                    throw new DomainException('An earlier migration stopped without recording its progress: ' . $version . '. Review the database before resuming; MySQL schema changes cannot be rolled back automatically.');
                }
                $completed = (int) $receipt['statements_done'];
            }
            if ($limit !== null && count($result['applied']) >= $limit) {
                break;
            }
            $statements = require $file;
            if (!is_array($statements) || $statements === []) {
                throw new DomainException('Invalid migration definition: ' . $version);
            }
            $statements = array_values($statements);
            if ($completed > count($statements)) {
                throw new DomainException('The recorded progress of migration ' . $version . ' does not match this package. Restore the original migration before continuing.');
            }
            if (!$preflighted) {
                // A partly installed target reaches here: check before this run's first statement.
                pl_database_require_trigger_support();
                $preflighted = true;
            }
            if (!$receipt) {
                DB::insert('pl_schema_migrations', ['version' => $version, 'checksum' => $checksum, 'status' => 'applying', 'statements_done' => 0]);
            }
            foreach ($statements as $index => $statement) {
                if (!is_string($statement) || trim($statement) === '') {
                    throw new DomainException('Invalid SQL statement in migration: ' . $version);
                }
                if ($index < $completed) {
                    continue;
                }
                DB::query($statement);
                DB::update('pl_schema_migrations', ['statements_done' => $index + 1], 'version = %s', $version);
            }
            DB::update('pl_schema_migrations', ['status' => 'applied', 'applied_at' => gmdate('Y-m-d H:i:s')], 'version = %s', $version);
            $result['applied'][] = $version;
        }
        return $result;
    } finally {
        DB::queryFirstField('SELECT RELEASE_LOCK(%s)', $lock);
    }
}

