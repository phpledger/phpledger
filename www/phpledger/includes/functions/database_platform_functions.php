<?php
declare(strict_types=1);

/*
 * PHP Ledger's SQL is written for MySQL 8.4 LTS. MariaDB, which XAMPP and most
 * shared hosts provide, runs the same schema and queries once three MySQL-only
 * spellings are translated just before execution:
 *   - FOR SHARE row locks become LOCK IN SHARE MODE (the same shared lock);
 *   - SKIP LOCKED is dropped before MariaDB 10.6 (a plain locking read stays correct);
 *   - MySQL 8's utf8mb4_0900_ai_ci becomes the closest NO PAD Unicode collation on
 *     MariaDB releases that do not know that name (11.4.5 and later do).
 * Migration files and their recorded checksums never change.
 */

const PL_MARIADB_MINIMUM = '10.4.0';

/**
 * Classify a server version such as "8.4.3", "10.11.8-MariaDB-log" or "5.5.5-10.6.25-MariaDB".
 *
 * @return array{engine: string, version: string, supported: bool}
 */
function pl_database_platform(string $version): array
{
    $engine = stripos($version, 'mariadb') !== false ? 'mariadb' : 'mysql';
    if (!preg_match('/^(?:5\.5\.5-)?([0-9]+)\.([0-9]+)\.([0-9]+)/', $version, $match)) {
        return ['engine' => $engine, 'version' => '', 'supported' => false];
    }
    $number = $match[1] . '.' . $match[2] . '.' . $match[3];
    $supported = $engine === 'mariadb'
        ? version_compare($number, PL_MARIADB_MINIMUM, '>=')
        : $match[1] . '.' . $match[2] === '8.4';
    return ['engine' => $engine, 'version' => $number, 'supported' => $supported];
}

function pl_database_requirement(): string
{
    [$major, $minor] = explode('.', PL_MARIADB_MINIMUM);
    return 'MySQL 8.4 LTS or MariaDB ' . $major . '.' . $minor . ' or newer';
}

/** The connected server's version from its handshake; no query is sent. */
function pl_database_server_version(): string
{
    return (string) DB::get()->getAttribute(PDO::ATTR_SERVER_VERSION);
}

/** Refuse unsupported servers before any schema work; returns the platform for the caller. */
function pl_database_require_supported(): array
{
    $platform = pl_database_platform(pl_database_server_version());
    if (!$platform['supported']) {
        throw new DomainException('This database server is not supported. PHP Ledger needs ' . pl_database_requirement() . '. Choose another database version in your hosting panel.');
    }
    return $platform;
}

/**
 * The translation needed for one connection, detected from its handshake and cached
 * per server version, so MySQL requests pay no extra query.
 *
 * @return array{mariadb: bool, skip_locked: bool, collation: ?string}
 */
function pl_database_dialect(PDO $connection): array
{
    static $cache = [];
    $version = (string) $connection->getAttribute(PDO::ATTR_SERVER_VERSION);
    $key = spl_object_id($connection) . '|' . $version;
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $platform = pl_database_platform($version);
    $dialect = ['mariadb' => $platform['engine'] === 'mariadb', 'skip_locked' => true, 'collation' => null];
    if ($dialect['mariadb']) {
        $dialect['skip_locked'] = version_compare($platform['version'], '10.6.0', '>=');
        // A raw PDO read: MeekroDB hooks would otherwise re-enter this translation.
        $available = $connection->query("SELECT COLLATION_NAME FROM information_schema.COLLATIONS WHERE COLLATION_NAME IN ('utf8mb4_0900_ai_ci', 'utf8mb4_uca1400_nopad_ai_ci', 'utf8mb4_unicode_520_nopad_ci')")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('utf8mb4_0900_ai_ci', $available, true)) {
            $dialect['collation'] = in_array('utf8mb4_uca1400_nopad_ai_ci', $available, true) ? 'utf8mb4_uca1400_nopad_ai_ci' : 'utf8mb4_unicode_520_nopad_ci';
        }
    }
    return $cache[$key] = $dialect;
}

/** Rewrite one statement for the connected server; MySQL statements are returned unchanged. */
function pl_database_translate(string $query, array $dialect): string
{
    if (!$dialect['mariadb']) {
        return $query;
    }
    $query = preg_replace('/\bFOR SHARE\b/', 'LOCK IN SHARE MODE', $query) ?? $query;
    if (!$dialect['skip_locked']) {
        $query = preg_replace('/\s+SKIP LOCKED\b/', '', $query) ?? $query;
    }
    if ($dialect['collation'] !== null) {
        $query = str_replace('utf8mb4_0900_ai_ci', $dialect['collation'], $query);
    }
    return $query;
}

/** Install the translation once on the shared MeekroDB connection. */
function pl_database_use_dialect(): void
{
    static $registered = false;
    if ($registered) {
        return;
    }
    $registered = true;
    DB::addHook('pre_run', static function (array $run): ?string {
        $dialect = pl_database_dialect(DB::get());
        return $dialect['mariadb'] ? pl_database_translate((string) $run['query'], $dialect) : null;
    });
}

/**
 * A database on the same server as PHP Ledger: XAMPP, Laragon, MAMP or a hosting
 * panel's `localhost`. Setup treats these as the owner's own machine or hosting
 * account, so it may create the database there and accepts the credentials those
 * stacks install by default. Any other host is another server.
 */
function pl_database_local_host(string $host): bool
{
    $host = strtolower(trim($host));
    if (in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)) {
        return true;
    }
    // Test containers name their database service; production never reads this setting.
    $testHosts = getenv('PL_ENV') === 'test' ? (string) getenv('PL_INSTALL_TEST_LOCAL_DB_HOSTS') : '';
    return $testHosts !== '' && in_array($host, array_map('strtolower', array_map('trim', explode(',', $testHosts))), true);
}
