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

/*
 * PHP Ledger keeps posted journals immutable with database triggers, so every
 * installation creates triggers from the first migration onwards. MySQL and
 * MariaDB refuse CREATE TRIGGER on a server that writes a binary log unless the
 * account is trusted to do so, which stops the schema part-way through with a
 * server error that names no remedy. MySQL 8 enables the binary log by default
 * and leaves log_bin_trust_function_creators off, so a dedicated application
 * account hits this on a stock server. These checks read three server facts and
 * name the parameter to set, before any migration writes a receipt.
 */

function pl_database_variable_enabled(mixed $value): bool
{
    return in_array(strtoupper(trim((string) $value)), ['1', 'ON', 'YES', 'TRUE'], true);
}

/**
 * Only a global grant carries the privileges that bypass the binary-log check;
 * ALL PRIVILEGES on the application's own database does not.
 *
 * @param list<string> $grants
 */
function pl_database_trigger_creator_trusted(array $grants): bool
{
    foreach ($grants as $grant) {
        if (preg_match('/^GRANT\s+(.+?)\s+ON\s+\*\.\*\s+TO\s/is', (string) $grant, $match) === 1
            && preg_match('/\b(ALL PRIVILEGES|SUPER|SET_USER_ID)\b/i', $match[1]) === 1) {
            return true;
        }
    }
    return false;
}

/** The exact remediation for this server, or null when triggers can be created. */
function pl_database_trigger_support_issue(bool $binaryLog, bool $trustCreators, bool $trusted): ?string
{
    if (!$binaryLog || $trustCreators || $trusted) {
        return null;
    }
    return 'This database server writes a binary log and does not trust this account to create triggers, so the schema would stop part-way through. '
        . 'PHP Ledger keeps posted entries immutable with database triggers. '
        . 'Ask whoever administers the server to set log_bin_trust_function_creators = 1 under [mysqld] in my.cnf or my.ini, or in the parameter group of a managed database, and apply it. '
        . 'Granting this account the SUPER privilege has the same effect and is the less safe choice. Then run this step again; this check changed nothing.';
}

/**
 * Read the server facts behind that check. An unreadable variable or grant list
 * returns null: a server that refuses to describe itself must not be blocked
 * here, because the migration itself still reports its own failure.
 */
function pl_database_trigger_support(): ?string
{
    try {
        $row = DB::queryFirstRow('SELECT @@GLOBAL.log_bin AS binary_log, @@GLOBAL.log_bin_trust_function_creators AS trust_creators');
        $grants = DB::queryFirstColumn('SHOW GRANTS FOR CURRENT_USER()');
    } catch (Throwable $error) {
        return null;
    }
    return pl_database_trigger_support_issue(
        pl_database_variable_enabled($row['binary_log'] ?? '0'),
        pl_database_variable_enabled($row['trust_creators'] ?? '1'),
        pl_database_trigger_creator_trusted(is_array($grants) ? $grants : [])
    );
}

/** Refuse schema work this account cannot finish, before it writes anything. */
function pl_database_require_trigger_support(): void
{
    $issue = pl_database_trigger_support();
    if ($issue !== null) {
        throw new DomainException($issue);
    }
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
