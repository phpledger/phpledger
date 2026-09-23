<?php
declare(strict_types=1);

// Copied into the private updater runtime. Keep the first loaded copy across an update;
// incoming installers must guard any helper first introduced in their own release.
// Business parameters never enter this adapter, and trusted recovery definitions are physical.

/** Logical names remain stable in migrations; only identifiers cross this boundary. */
function pl_database_prefix(?string $prefix = null): string
{
    $prefix ??= $GLOBALS['pl_database_prefix'] ?? (getenv('PL_DB_PREFIX') ?: 'pl_');
    if (!preg_match('/^[a-z][a-z0-9_]{0,15}_$/D', $prefix)) {
        throw new InvalidArgumentException('Database prefix must be 2–17 lowercase letters, digits or underscores, starting with a letter and ending with an underscore.');
    }
    return $prefix;
}

function pl_database_table(string $logical): string
{
    if (!preg_match('/^pl_([a-z0-9_]+)$/D', $logical, $match)) {
        throw new InvalidArgumentException('A canonical PHP Ledger table name is required.');
    }
    return pl_database_prefix() . $match[1];
}

function pl_database_owns(string $physical): bool
{
    return str_starts_with($physical, pl_database_prefix()) && preg_match('/^[a-z][a-z0-9_]+$/D', $physical) === 1;
}

/** Preserve data literals, comments and placeholders; bound values are never passed here. */
function pl_database_namespace_sql(string $sql): string
{
    if (($GLOBALS['pl_database_physical_sql'] ?? false) === true) { return $sql; }
    $prefix = pl_database_prefix();
    // Migration 027 predates namespaces and embeds one metadata identity as a literal.
    // Match the whole historical statement, never arbitrary data values or parameters.
    $legacyMetadata = "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'pl_ar_document_revisions'";
    if (trim($sql) === $legacyMetadata) {
        return "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '" . pl_database_table('pl_ar_document_revisions') . "'";
    }
    // Server/database administration names are physical, never application tables.
    if (preg_match('/^\s*(?:(?:CREATE|ALTER|DROP)\s+(?:DATABASE|SCHEMA|USER)\b|(?:GRANT|REVOKE|USE)\b)/i', $sql)) { return $sql; }
    $pattern = '~(\'(?:\\\\.|\'\'|[^\'\\\\])*\'|"(?:\\\\.|""|[^"\\\\])*"|/\*.*?\*/|--(?=\s)[^\r\n]*|\#[^\r\n]*|`(?:``|[^`])*`|\{\{[a-z][a-z0-9_]*\}\}|[A-Za-z_][A-Za-z0-9_$]*)~s';
    $previous = '';
    return (string) preg_replace_callback($pattern, static function (array $match) use ($prefix, &$previous): string {
        $token = $match[0];
        if ($token[0] === "'" || $token[0] === '"' || str_starts_with($token, '/*') || str_starts_with($token, '--') || $token[0] === '#') { return $token; }
        $quoted = $token[0] === '`';
        $name = $quoted ? str_replace('``', '`', substr($token, 1, -1)) : $token;
        if (str_starts_with($name, '{{')) {
            $name = $prefix . substr($name, 2, -2); $quoted = true;
        } elseif ($prefix !== 'pl_' && !in_array($previous, ['DATABASE', 'SCHEMA', 'USE'], true)) {
            if ($previous === 'CONSTRAINT' && preg_match('/^(?:fk|ck)_[a-z0-9_]+$/D', $name)) {
                $name = 'plns_' . substr(hash('sha256', $prefix), 0, 16) . '_' . $name;
                if (strlen($name) > 64) { $name = substr($name, 0, 47) . '_' . substr(hash('sha256', $name), 0, 16); }
            } elseif (str_starts_with($name, 'pl_') && !str_starts_with($name, $prefix)) {
                $name = $prefix . substr($name, 3);
            }
        }
        $previous = strtoupper($token);
        return $quoted ? '`' . str_replace('`', '``', $name) . '`' : $name;
    }, $sql);
}

/** Normalize environment/private settings without printing certificate paths or contents. */
function pl_database_configuration(array $config): array
{
    $prefix = pl_database_prefix((string) ($config['db_prefix'] ?? (getenv('PL_DB_PREFIX') ?: 'pl_')));
    $explicit = getenv('PL_DB_PREFIX');
    if ($explicit !== false && $explicit !== '' && isset($config['db_prefix']) && $prefix !== $explicit) {
        throw new DomainException('Configured database prefix differs from the installation namespace.');
    }
    $config['db_prefix'] = $prefix;
    foreach (['ca', 'cert', 'key'] as $name) {
        $config['db_ssl_' . $name] = (string) ($config['db_ssl_' . $name] ?? (getenv('PL_DB_SSL_' . strtoupper($name)) ?: ''));
    }
    $verify = $config['db_ssl_verify'] ?? (getenv('PL_DB_SSL_VERIFY') === false ? true : getenv('PL_DB_SSL_VERIFY'));
    if ($verify === '') { $verify = true; }
    $verify = filter_var($verify, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($verify === null) { throw new InvalidArgumentException('Database TLS verification must be enabled or disabled explicitly.'); }
    $config['db_ssl_verify'] = $verify;
    if (($config['db_ssl_cert'] === '') !== ($config['db_ssl_key'] === '')) {
        throw new InvalidArgumentException('Database TLS client certificate and key must be configured together.');
    }
    foreach (['ca', 'cert', 'key'] as $name) {
        $path = $config['db_ssl_' . $name];
        if ($path !== '' && (str_contains($path, "\0") || !is_file($path) || !is_readable($path))) {
            throw new InvalidArgumentException('A configured database TLS file is unavailable.');
        }
    }
    return $config;
}

/** Existing MeekroDB connection, including installer and private recovery workers. */
function pl_database_configure(array $config): array
{
    $config = pl_database_configuration($config);
    $directory = $config['installation_directory'] ?? (getenv('PL_INSTALL_DIRECTORY') ?: dirname(__DIR__, 2) . '/storage/installation');
    if ($directory !== '' && is_file($directory . '/installed.json')) {
        $receipt = json_decode((string) file_get_contents($directory . '/installed.json'), true, 32, JSON_THROW_ON_ERROR);
        if (($receipt['db_prefix'] ?? 'pl_') !== $config['db_prefix']) {
            throw new DomainException('The installation is bound to a different database namespace.');
        }
    }
    DB::disconnect();
    $GLOBALS['pl_database_prefix'] = $config['db_prefix'];
    DB::$host = $config['host']; DB::$port = (int) $config['port']; DB::$dbName = $config['database'];
    DB::$user = $config['user']; DB::$password = $config['password']; DB::$encoding = 'utf8mb4'; DB::$nested_transactions = true;
    foreach (['MYSQL_ATTR_SSL_CA', 'MYSQL_ATTR_SSL_CERT', 'MYSQL_ATTR_SSL_KEY', 'MYSQL_ATTR_SSL_VERIFY_SERVER_CERT'] as $option) {
        if (defined('PDO::' . $option)) { unset(DB::$connect_options[constant('PDO::' . $option)]); }
    }
    foreach (['ca', 'cert', 'key'] as $name) {
        if ($config['db_ssl_' . $name] !== '') { DB::$connect_options[constant('PDO::MYSQL_ATTR_SSL_' . strtoupper($name))] = $config['db_ssl_' . $name]; }
    }
    DB::$connect_options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = $config['db_ssl_verify'];
    static $registered = false;
    if (!$registered) {
        DB::addHook('pre_run', static fn(array $run): string => pl_database_namespace_sql((string) $run['query']));
        $registered = true;
    }
    pl_database_use_dialect();
    if ($config['db_ssl_ca'] !== '' || $config['db_ssl_cert'] !== '') {
        $status = DB::queryFirstRow("SHOW SESSION STATUS LIKE 'Ssl_cipher'");
        if (($status['Value'] ?? '') === '') { throw new DomainException('The database did not establish the requested TLS connection.'); }
    }
    return $config;
}

/** Refuse ambiguous namespaces and dependencies that make scoped recovery unsafe. */
function pl_database_assert_namespace(): void
{
    $prefix = pl_database_prefix();
    $database = (string) DB::queryFirstField('SELECT DATABASE()');
    $tables = DB::queryFirstColumn('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
    foreach ($tables as $table) {
        if (str_ends_with($table, 'schema_migrations')) {
            $other = substr($table, 0, -strlen('schema_migrations'));
            if ($other !== $prefix && (str_starts_with($other, $prefix) || str_starts_with($prefix, $other))) {
                throw new DomainException('Installation namespaces must not overlap. Choose a distinct prefix.');
            }
        }
    }
    $definitions = DB::query('SELECT TABLE_NAME AS owner, VIEW_DEFINITION AS definition FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE()');
    $definitions = array_merge($definitions, DB::query('SELECT EVENT_OBJECT_TABLE AS owner, ACTION_STATEMENT AS definition FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()'));
    foreach ($definitions as $definition) {
        if ($definition['definition'] === null) { throw new DomainException('Database object definitions must be readable for isolated recovery.'); }
        $literalPattern = <<<'SQL_LITERALS'
~'(?:\\.|''|[^'\\])*'|"(?:\\.|""|[^"\\])*"|/\*.*?\*/|--(?=\s)[^\r\n]*~s
SQL_LITERALS;
        $sql = (string) preg_replace($literalPattern, '', $definition['definition']);
        preg_match_all('/`(?:``|[^`])+`|\b[a-z][a-z0-9_]*\b/i', $sql, $tokens);
        foreach ($tokens[0] as $token) {
            $name = str_starts_with($token, '`') ? str_replace('``', '`', substr($token, 1, -1)) : $token;
            if (in_array($name, $tables, true) && pl_database_owns($definition['owner']) !== pl_database_owns($name)) {
                throw new DomainException('A cross-namespace view or trigger prevents isolated installation recovery.');
            }
        }
    }
    foreach (DB::query('SELECT TABLE_SCHEMA, TABLE_NAME, REFERENCED_TABLE_NAME, REFERENCED_TABLE_SCHEMA FROM information_schema.KEY_COLUMN_USAGE WHERE (TABLE_SCHEMA = DATABASE() OR REFERENCED_TABLE_SCHEMA = DATABASE()) AND REFERENCED_TABLE_NAME IS NOT NULL') as $reference) {
        $own = $reference['TABLE_SCHEMA'] === $database && pl_database_owns($reference['TABLE_NAME']);
        $target = pl_database_owns($reference['REFERENCED_TABLE_NAME']) && $reference['REFERENCED_TABLE_SCHEMA'] === $database;
        if ($own !== $target) { throw new DomainException('A cross-namespace foreign key prevents isolated installation recovery.'); }
    }
}


/** Trusted SHOW CREATE definitions already carry physical names, including schema qualifiers. */
function pl_database_restore_definition(string $sql): void
{
    $before = $GLOBALS['pl_database_physical_sql'] ?? false;
    $GLOBALS['pl_database_physical_sql'] = true;
    try { DB::query($sql); } finally { $GLOBALS['pl_database_physical_sql'] = $before; }
}
