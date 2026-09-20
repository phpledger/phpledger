<?php
declare(strict_types=1);

/** Recovery uses the same MeekroDB adapter, without loading application bootstrap. */
function pl_update_database_connect(string $root): void
{
    if (!class_exists('DB', false)) {
        require is_file(__DIR__ . '/meekrodb.php') ? __DIR__ . '/meekrodb.php' : $root . '/vendor/sergeytsalkov/meekrodb/db.class.php';
    }
    $config = ['host' => getenv('PL_DB_HOST') ?: '127.0.0.1', 'port' => (int) (getenv('PL_DB_PORT') ?: 3306),
        'database' => getenv('PL_DB_NAME') ?: 'phpledger', 'user' => getenv('PL_DB_USER') ?: 'phpledger', 'password' => getenv('PL_DB_PASSWORD') ?: ''];
    $path = getenv('PL_INSTALL_CONFIG_PATH') ?: $root . '/www/phpledger/includes/config.local.php';
    if (is_file($path)) {
        $override = require $path;
        if (!is_array($override)) { throw new RuntimeException('Invalid database configuration.'); }
        $config = array_replace($config, $override);
    }
    if ($config['password'] === '') { throw new RuntimeException('Database credentials are unavailable.'); }
    DB::$host = $config['host']; DB::$port = (int) $config['port']; DB::$dbName = $config['database'];
    DB::$user = $config['user']; DB::$password = $config['password']; DB::$encoding = 'utf8mb4'; DB::$nested_transactions = true;
    // Whichever copy of the platform helpers loaded first stays: this runtime's copy here, the
    // application's when a caller already has it. The new release's install_functions.php does
    // the same, so the migrate and verify phases never redeclare them (issue #90). The runtime
    // copy is the installed release's; database_platform_functions.php documents the one-release
    // compatibility rule that makes that safe.
    if (!function_exists('pl_database_platform')) { require_once __DIR__ . '/database_platform_functions.php'; }
    pl_database_use_dialect();
    DB::query("SET time_zone = '+00:00'");
    if (!pl_database_platform(pl_database_server_version())['supported']) { throw new DomainException('Automatic updates require ' . pl_database_requirement() . '.'); }
}

function pl_update_database_inventory(bool $requireInstalled = true): array
{
    // Full recovery must never silently omit operator-added database objects.
    if ((int) DB::queryFirstField('SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()')
        || (int) DB::queryFirstField('SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE()')) {
        throw new DomainException('Automatic recovery does not yet support databases with stored routines or scheduled events. Use the documented operator upgrade process.');
    }
    $objects = DB::query('SELECT TABLE_NAME AS name, TABLE_TYPE AS type, ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME');
    $tables = []; $views = [];
    foreach ($objects as $object) {
        if (!preg_match('/^pl_[a-z0-9_]+$/D', $object['name'])) { throw new DomainException('Automatic updates require a dedicated, uncustomized PHP Ledger database.'); }
        if ($object['type'] === 'VIEW') { $views[] = $object['name']; }
        elseif ($object['type'] === 'BASE TABLE' && $object['engine'] === 'InnoDB') { $tables[] = $object['name']; }
        else { throw new DomainException('Database contains unsupported objects; automatic recovery is unavailable.'); }
    }
    if ($requireInstalled && !in_array('pl_schema_migrations', $tables, true)) { throw new DomainException('No installed schema receipts were found.'); }
    return ['tables' => $tables, 'views' => $views];
}

function pl_update_database_rows(string $table, ?string $destination = null): array
{
    $keys = DB::query('SHOW KEYS FROM %b WHERE Key_name = %s', $table, 'PRIMARY');
    usort($keys, static fn(array $a, array $b): int => (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index']);
    if ($keys === []) { throw new DomainException('Automatic snapshots require primary keys on every table.'); }
    $columns = array_column($keys, 'Column_name');
    $handle = $destination === null ? null : fopen($destination, 'wb');
    if ($destination !== null && !$handle) { throw new RuntimeException('Database backup cannot be written.'); }
    if ($destination !== null) { chmod($destination, 0600); }
    $hash = hash_init('sha256'); $count = 0;
    try {
        $order = implode(', ', array_map(static fn(string $column): string => '`' . str_replace('`', '``', $column) . '`', $columns));
        $walker = DB::queryWalk('SELECT * FROM %b ORDER BY ' . $order, $table);
        while ($row = $walker->next()) {
            $encoded = [];
            foreach ($row as $key => $value) { $encoded[$key] = $value === null ? null : base64_encode((string) $value); }
            $line = json_encode($encoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
            hash_update($hash, $line); $count++;
            if ($handle && fwrite($handle, $line) !== strlen($line)) { throw new RuntimeException('Database backup disk write failed.'); }
        }
        if ($handle && (!fflush($handle) || (function_exists('fsync') && !fsync($handle)))) { throw new RuntimeException('Database backup could not be persisted.'); }
    } finally { if ($handle) { fclose($handle); } }
    return ['sha256' => hash_final($hash), 'rows' => $count];
}

/** Called only after the installation-wide write barrier has drained requests. */
function pl_update_database_backup(string $directory): ?array
{
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) { throw new RuntimeException('Database backup directory cannot be created.'); }
    $progressPath = $directory . '/progress.json';
    if (is_file($directory . '/manifest.json')) { return ['manifest_sha256' => hash_file('sha256', $directory . '/manifest.json')]; }
    if (is_file($progressPath)) {
        $progress = pl_update_json($progressPath);
        $manifest = $progress['manifest'];
        $tables = array_keys($manifest['tables']);
        if ($progress['index'] < count($tables)) {
            $table = $tables[$progress['index']]; $entry = $manifest['tables'][$table];
            $file = $directory . '/' . $table . '.jsonl';
            $handle = fopen($file, 'c+b');
            if (!$handle) { throw new RuntimeException('Cannot continue database snapshot.'); }
            try {
                chmod($file, 0600);
                // An interrupted append is discarded to its last durable checkpoint.
                if (!ftruncate($handle, $progress['offset']) || fseek($handle, $progress['offset']) !== 0) { throw new RuntimeException('Cannot position database snapshot.'); }
                $rows = pl_update_database_batch($table, $entry['keys'], $progress['rows']);
                foreach ($rows as $row) {
                    $encoded = [];
                    foreach ($row as $key => $value) { $encoded[$key] = $value === null ? null : base64_encode((string) $value); }
                    $line = json_encode($encoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
                    if (fwrite($handle, $line) !== strlen($line)) { throw new RuntimeException('Database snapshot write failed.'); }
                }
                if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) { throw new RuntimeException('Database snapshot could not be persisted.'); }
                $progress['offset'] = ftell($handle); $progress['rows'] += count($rows);
            } finally { fclose($handle); }
            if (count($rows) < 250) {
                $manifest['tables'][$table]['sha256'] = hash_file('sha256', $file);
                $manifest['tables'][$table]['rows'] = $progress['rows'];
                $progress['index']++; $progress['offset'] = 0; $progress['rows'] = 0;
            }
            $progress['manifest'] = $manifest; pl_update_checkpoint($progressPath, $progress);
            return null;
        }
        if (!hash_equals($manifest['financial'], pl_update_database_financial_digest())) { throw new RuntimeException('Database writes occurred during the maintenance snapshot.'); }
        pl_update_checkpoint($directory . '/manifest.json', $manifest);
        return ['manifest_sha256' => hash_file('sha256', $directory . '/manifest.json')];
    }
    $inventory = pl_update_database_inventory();
    $grantLines = DB::queryFirstColumn('SHOW GRANTS FOR CURRENT_USER()');
    $database = (string) DB::queryFirstField('SELECT DATABASE()');
    $schemaPattern = '`' . str_replace(['\\', '_', '%', '`'], ['\\\\', '\\_', '\\%', '``'], $database) . '`.*';
    $plainPattern = '`' . str_replace('`', '``', $database) . '`.*';
    $grants = '';
    foreach ($grantLines as $line) {
        if (str_contains($line, ' ON *.* TO ') || str_contains($line, ' ON ' . $schemaPattern . ' TO ') || str_contains($line, ' ON ' . $plainPattern . ' TO ')) { $grants .= ' ' . $line; }
    }
    // Fail before mutation when the account cannot restore schema, views and guards.
    if (!str_contains($grants, 'ALL PRIVILEGES')) {
        foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'ALTER', 'INDEX', 'REFERENCES', 'TRIGGER', 'CREATE VIEW', 'SHOW VIEW'] as $privilege) {
            if (!preg_match('/\b' . preg_quote($privilege, '/') . '\b/', $grants)) { throw new DomainException('The database account lacks required backup/recovery privileges.'); }
        }
    }
    $manifest = ['schema' => 1, 'database' => (string) DB::queryFirstField('SELECT DATABASE()'), 'tables' => [], 'views' => [], 'triggers' => []];
    // The durable installation marker blocks application writers across requests.
    // Host operators must also stop direct SQL writers, as for any schema update.
        foreach ($inventory['tables'] as $table) {
            $definition = DB::queryFirstList('SHOW CREATE TABLE %b', $table);
            $columns = DB::query('SHOW COLUMNS FROM %b', $table);
            $generated = [];
            foreach ($columns as $column) { if (str_contains($column['Extra'], 'VIRTUAL GENERATED') || str_contains($column['Extra'], 'STORED GENERATED')) { $generated[] = $column['Field']; } }
            $keys = DB::query('SHOW KEYS FROM %b WHERE Key_name = %s', $table, 'PRIMARY');
            usort($keys, static fn(array $a, array $b): int => (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index']);
            if ($keys === []) { throw new DomainException('Automatic recovery requires primary keys on every table.'); }
            $manifest['tables'][$table] = ['create' => $definition[1], 'generated' => $generated, 'keys' => array_column($keys, 'Column_name'), 'columns' => array_column($columns, 'Field')];
        }
        foreach ($inventory['views'] as $view) {
            $definition = DB::queryFirstList('SHOW CREATE VIEW %b', $view)[1];
            pl_update_database_definer_check($definition); $manifest['views'][$view] = $definition;
        }
        foreach (DB::query('SHOW TRIGGERS') as $trigger) {
            $definition = DB::queryFirstRow('SHOW CREATE TRIGGER %b', $trigger['Trigger']);
            pl_update_database_definer_check($definition['SQL Original Statement']);
            $manifest['triggers'][$trigger['Trigger']] = ['create' => $definition['SQL Original Statement'], 'sql_mode' => $definition['sql_mode']];
        }
    $manifest['financial'] = pl_update_database_financial_digest();
    pl_update_checkpoint($progressPath, ['manifest' => $manifest, 'index' => 0, 'offset' => 0, 'rows' => 0]);
    return null;
}

function pl_update_database_definer_check(string $definition): void
{
    if (preg_match('/DEFINER=`([^`]+)`@`([^`]+)`/', $definition, $match)
        && ($match[1] . '@' . $match[2]) !== (string) DB::queryFirstField('SELECT CURRENT_USER()')) {
        throw new DomainException('Views and triggers must be owned by the update database account before automatic recovery can be enabled.');
    }
}

function pl_update_database_batch(string $table, array $keys, int $offset): array
{
    $order = implode(', ', array_map(static fn(string $column): string => '`' . str_replace('`', '``', $column) . '`', $keys));
    return DB::query('SELECT * FROM %b ORDER BY ' . $order . ' LIMIT 250 OFFSET %i', $table, $offset);
}

function pl_update_database_financial_digest(): string
{
    $rows = DB::query('SELECT company_id, book_id, COUNT(*) AS line_count, CAST(SUM(debit) AS CHAR) AS debit, CAST(SUM(credit) AS CHAR) AS credit FROM pl_journal_lines GROUP BY company_id, book_id ORDER BY company_id, book_id');
    return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
}

/** Additive releases must preserve every original stored value, including source links. */
function pl_update_database_preserved(string $directory): bool
{
    $manifest = pl_update_json($directory . '/manifest.json');
    $path = $directory . '/preserved.json';
    $progress = is_file($path) ? pl_update_json($path) : ['index' => 0, 'offset' => 0];
    $tables = array_keys($manifest['tables']);
    if ($progress['index'] >= count($tables)) { return true; }
    $table = $tables[$progress['index']]; $entry = $manifest['tables'][$table];
    $handle = fopen($directory . '/' . $table . '.jsonl', 'rb');
    if (!$handle || fseek($handle, $progress['offset']) !== 0) { throw new RuntimeException('Original database snapshot cannot be read.'); }
    try {
        $expected = []; $conditions = []; $parameters = [$table];
        for ($index = 0; $index < 250 && ($line = fgets($handle)) !== false; $index++) {
            $row = json_decode($line, true, 64, JSON_THROW_ON_ERROR); $keyValues = []; $terms = [];
            foreach ($entry['keys'] as $key) {
                $keyValues[] = $row[$key]; $terms[] = '%b = %s'; $parameters[] = $key; $parameters[] = base64_decode($row[$key], true);
            }
            $identity = json_encode($keyValues, JSON_THROW_ON_ERROR); $expected[$identity] = $row;
            $conditions[] = '(' . implode(' AND ', $terms) . ')';
        }
        if ($expected !== []) {
            $columns = implode(', ', array_map(static fn(string $column): string => '`' . str_replace('`', '``', $column) . '`', $entry['columns']));
            $actual = DB::query('SELECT ' . $columns . ' FROM %b WHERE ' . implode(' OR ', $conditions), ...$parameters);
            if (count($actual) !== count($expected)) { throw new RuntimeException('An original record was removed by the update.'); }
            foreach ($actual as $row) {
                foreach ($row as $key => $value) { $row[$key] = $value === null ? null : base64_encode((string) $value); }
                $keyValues = []; foreach ($entry['keys'] as $key) { $keyValues[] = $row[$key]; }
                if (($expected[json_encode($keyValues, JSON_THROW_ON_ERROR)] ?? null) !== $row) { throw new RuntimeException('An original stored value was changed by the update.'); }
            }
        }
        $progress['offset'] = ftell($handle);
        if (feof($handle)) { $progress['index']++; $progress['offset'] = 0; }
    } finally { fclose($handle); }
    pl_update_checkpoint($path, $progress);
    return $progress['index'] >= count($tables);
}

/** Durable one-object/250-row restoration; interrupted batches can safely repeat. */
function pl_update_database_restore(string $directory, array $receipt): bool
{
    if (!hash_equals($receipt['manifest_sha256'], (string) hash_file('sha256', $directory . '/manifest.json'))) { throw new RuntimeException('Database backup manifest is corrupt.'); }
    $manifest = pl_update_json($directory . '/manifest.json');
    if (!hash_equals($manifest['database'], (string) DB::queryFirstField('SELECT DATABASE()'))) { throw new RuntimeException('Recovery database identity differs from the backup.'); }
    $progressPath = $directory . '/restore.json';
    if (!is_file($progressPath)) {
        foreach ($manifest['tables'] as $table => $entry) {
            if (!preg_match('/^pl_[a-z0-9_]+$/D', $table) || !hash_equals($entry['sha256'], (string) hash_file('sha256', $directory . '/' . $table . '.jsonl'))) { throw new RuntimeException('Database backup data is corrupt.'); }
        }
        $objects = pl_update_database_inventory(false);
        pl_update_checkpoint($progressPath, ['phase' => 'drop_views', 'index' => 0, 'offset' => 0, 'objects' => $objects]);
    }
    $progress = pl_update_json($progressPath);
    if ($progress['phase'] === 'complete') { return true; }
    DB::query('SET FOREIGN_KEY_CHECKS = 0');
    $originalMode = (string) DB::queryFirstField('SELECT @@SESSION.sql_mode');
    try {
        switch ($progress['phase']) {
            case 'drop_views': case 'drop_tables':
                $type = $progress['phase'] === 'drop_views' ? 'views' : 'tables';
                $names = $progress['objects'][$type];
                if ($progress['index'] < count($names)) {
                    DB::query('DROP ' . ($type === 'views' ? 'VIEW' : 'TABLE') . ' IF EXISTS %b', $names[$progress['index']]); $progress['index']++;
                } else { $progress['phase'] = $type === 'views' ? 'drop_tables' : 'create'; $progress['index'] = 0; }
                break;
            case 'create':
                $names = array_keys($manifest['tables']);
                if ($progress['index'] < count($names)) {
                    $name = $names[$progress['index']];
                    if (!DB::queryFirstField('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $name)) { DB::query($manifest['tables'][$name]['create']); }
                    $progress['index']++;
                } else { $progress['phase'] = 'rows'; $progress['index'] = 0; }
                break;
            case 'rows':
                $names = array_keys($manifest['tables']);
                if ($progress['index'] >= count($names)) { $progress['phase'] = 'views'; $progress['views'] = $manifest['views']; break; }
                $table = $names[$progress['index']]; $entry = $manifest['tables'][$table];
                $handle = fopen($directory . '/' . $table . '.jsonl', 'rb');
                if (!$handle || fseek($handle, $progress['offset']) !== 0) { throw new RuntimeException('Database backup cannot be resumed.'); }
                try {
                    DB::query('START TRANSACTION');
                    for ($count = 0; $count < 250 && ($line = fgets($handle)) !== false; $count++) {
                        $row = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
                        foreach ($row as $key => $value) {
                            if (in_array($key, $entry['generated'], true)) { unset($row[$key]); continue; }
                            $row[$key] = $value === null ? null : base64_decode($value, true);
                            if ($row[$key] === false) { throw new RuntimeException('Invalid backup row encoding.'); }
                        }
                        // Repeating the last committed batch after a lost checkpoint is idempotent.
                        // Immutable guards are installed only after all original data is restored.
                        DB::insertUpdate($table, $row);
                    }
                    DB::query('COMMIT');
                    $progress['offset'] = ftell($handle);
                    if (feof($handle)) { $progress['index']++; $progress['offset'] = 0; }
                } catch (Throwable $error) { DB::query('ROLLBACK'); throw $error; }
                finally { fclose($handle); }
                break;
            case 'views':
                $before = count($progress['views']);
                if ($before === 0) { $progress['phase'] = 'triggers'; $progress['index'] = 0; break; }
                foreach ($progress['views'] as $name => $sql) {
                    try {
                        if (!DB::queryFirstField('SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $name)) { DB::query($sql); }
                        unset($progress['views'][$name]); break;
                    } catch (Throwable $error) { /* Try an independent view first. */ }
                }
                if (count($progress['views']) === $before) { throw new RuntimeException('Database view restoration failed.'); }
                break;
            case 'triggers':
                $names = array_keys($manifest['triggers']);
                if ($progress['index'] < count($names)) {
                    $name = $names[$progress['index']]; $entry = $manifest['triggers'][$name];
                    DB::query('DROP TRIGGER IF EXISTS %b', $name);
                    DB::query('SET SESSION sql_mode = %s', $entry['sql_mode']); DB::query($entry['create']); $progress['index']++;
                } else { $progress['phase'] = 'verify'; }
                break;
            case 'verify':
                if (pl_update_database_backup($directory . '/verification') === null) { break; }
                $actual = pl_update_json($directory . '/verification/manifest.json');
                foreach (['tables', 'views', 'triggers', 'financial'] as $key) {
                    if ($manifest[$key] !== $actual[$key]) { throw new RuntimeException('Restored database contents, definitions or financial totals differ.'); }
                }
                pl_update_database_ledger_check(); $progress['phase'] = 'complete';
                break;
            default: throw new RuntimeException('Unknown database recovery phase.');
        }
    } finally { DB::query('SET SESSION sql_mode = %s', $originalMode); DB::query('SET FOREIGN_KEY_CHECKS = 1'); }
    pl_update_checkpoint($progressPath, $progress);
    return $progress['phase'] === 'complete';
}

function pl_update_database_ledger_check(): void
{
    if ((int) DB::queryFirstField("SELECT COUNT(*) FROM pl_schema_migrations WHERE status <> 'applied'")
        || (int) DB::queryFirstField('SELECT COUNT(*) FROM (SELECT journal_id FROM pl_journal_lines GROUP BY journal_id HAVING SUM(debit) <> SUM(credit)) AS broken')) {
        throw new RuntimeException('Migration or balanced-journal verification failed.');
    }
}

function pl_update_database_migrate(string $root): bool
{
    require_once $root . '/www/phpledger/includes/functions/install_functions.php';
    pl_migrate(1);
    return pl_install_database_check()['pending'] === 0;
}

function pl_update_database_health(string $root, array $release): void
{
    require_once $root . '/www/phpledger/includes/functions/install_functions.php';
    if (pl_install_database_check()['status'] !== 'current') { throw new RuntimeException('The updated schema is not current.'); }
    pl_update_database_ledger_check();
}
