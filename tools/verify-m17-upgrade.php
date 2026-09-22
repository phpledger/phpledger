<?php
declare(strict_types=1);

// A two-process upgrade proof: seed with the archived v1.2.1 implementation, then
// check with the candidate implementation. Only a random disposable test schema.
if (PHP_SAPI !== 'cli' || getenv('PL_ENV') !== 'test' || getenv('PL_DB_HOST') !== 'db_test'
    || getenv('PL_DB_NAME') !== 'phpledger_test' || getenv('PL_DB_USER') !== 'root') {
    fwrite(STDERR, "M17 upgrade verification requires the isolated db_test root account.\n");
    exit(2);
}
$mode = $argv[1] ?? '';
if (!in_array($mode, ['seed', 'check'], true)) {
    throw new DomainException('Choose seed or check; seed also takes the archived v1.2.1 source directory.');
}
$source = $mode === 'seed' ? realpath($argv[2] ?? '') : dirname(__DIR__);
if (!$source || !is_file($source . '/www/phpledger/includes/bootstrap.php')) {
    throw new DomainException('The source directory must contain the application.');
}
if ($mode === 'seed' && trim((string) file_get_contents($source . '/www/phpledger/VERSION')) !== '1.2.1') {
    throw new DomainException('Seed from the published v1.2.1 archive.');
}
require $source . '/www/phpledger/includes/bootstrap.php';
require $source . '/www/phpledger/install/migrate.php';
if (DB::$host !== 'db_test' || DB::$dbName !== 'phpledger_test' || DB::$user !== 'root') {
    throw new RuntimeException('Effective configuration is not the disposable test service.');
}
$receiptPath = '/tmp/phpledger-m17-upgrade.json';

function m17_upgrade_require(bool $ok, string $message): void
{
    if (!$ok) { throw new RuntimeException($message); }
}

/** The fixture implementation comes from the archived release, not this source. */
function m17_upgrade_fixture(string $name, mixed ...$args): array
{
    if (!in_array($name, ['ar_ap_fixture', 'ledger_payload', 'ar_ap_input', 'ar_ap_payment'], true) || !function_exists($name)) {
        throw new RuntimeException('The published fixture builder is unavailable.');
    }
    $result = call_user_func_array($name, $args);
    if (!is_array($result)) { throw new RuntimeException('Unexpected published fixture result.'); }
    return $result;
}

if ($mode === 'seed') {
    m17_upgrade_require(!file_exists($receiptPath), 'A pending upgrade receipt exists; inspect it before repeating.');
    $database = 'phpledger_m17_verify_' . bin2hex(random_bytes(12));
    DB::query('CREATE DATABASE %b CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $database);
    DB::useDB($database);
    try {
        $migrations = pl_migrate();
        m17_upgrade_require(in_array('043_sample_skeletons', $migrations['applied'], true)
            && !in_array('044_ownership_register', $migrations['applied'], true), 'Unexpected baseline migration chain.');
        // Use the actual release fixture builders and services, never current helpers
        // against an old schema. Merely loading test files does not run their tests.
        function test(string $name, callable $action): void {}
        require $source . '/tests/ledger_test.php';
        require $source . '/tests/ar_ap_test.php';
        $fixture = m17_upgrade_fixture('ar_ap_fixture');
        $args = [$fixture['actor_id'], $fixture['company_id'], $fixture['book_id']];
        pl_post_journal(...array_merge($args, [m17_upgrade_fixture('ledger_payload', $fixture, '125.0000', 'm17-baseline-cash')]));
        $draft = pl_save_ar_document(...array_merge($args, [m17_upgrade_fixture('ar_ap_input', $fixture, 'invoice', '1000')]));
        $invoice = pl_post_ar_document(...array_merge($args, [$draft['id'], $draft['revision']]));
        pl_settle_ar_document(...array_merge($args, [$invoice['id'], m17_upgrade_fixture('ar_ap_payment', $fixture, '250', '2026-09-15')]));
        $tables = ['pl_accounts', 'pl_periods', 'pl_journals', 'pl_journal_lines', 'pl_parties',
            'pl_ar_documents', 'pl_ar_document_lines', 'pl_ar_document_actions', 'pl_ar_document_events'];
        $snapshot = [];
        foreach ($tables as $table) {
            $rows = DB::query('SELECT * FROM %b ORDER BY id', $table);
            if ($rows !== []) { $snapshot[$table] = $rows; }
        }
        $trial = pl_trial_balance(...$args);
        m17_upgrade_require($trial['balanced'], 'Baseline must balance before the upgrade.');
        $receipt = ['database' => $database, 'fixture' => $fixture, 'rows' => $snapshot,
            'receipts' => DB::query('SELECT * FROM pl_schema_migrations ORDER BY version'),
            'total_debit' => $trial['total_debit'], 'total_credit' => $trial['total_credit']];
        $oldMask = umask(0077);
        try {
            m17_upgrade_require(file_put_contents($receiptPath, json_encode($receipt, JSON_THROW_ON_ERROR)) !== false,
                'Could not persist the sample upgrade receipt.');
        } finally { umask($oldMask); }
        echo "Seed passed: published v1.2.1, posted cash journal, partially paid invoice, exact historical rows recorded.\n";
    } catch (Throwable $error) {
        DB::useDB('phpledger_test');
        DB::query('DROP DATABASE %b', $database);
        throw $error;
    }
    exit(0);
}

$receipt = json_decode((string) file_get_contents($receiptPath), true, 64, JSON_THROW_ON_ERROR);
$database = (string) ($receipt['database'] ?? '');
m17_upgrade_require((bool) preg_match('/^phpledger_m17_verify_[a-f0-9]{24}$/D', $database), 'Invalid disposable schema receipt.');
DB::useDB($database);
try {
    $migration = pl_migrate();
    foreach (['047_period_close', '048_employee_master', '049_secret_store'] as $version) {
        m17_upgrade_require(in_array($version, $migration['applied'], true), 'Expected new migration not applied: ' . $version);
    }
    foreach ($receipt['rows'] as $table => $rows) {
        $fields = array_keys($rows[0]);
        $select = implode(', ', array_map(static fn(string $field): string => '`' . str_replace('`', '``', $field) . '`', $fields));
        $after = DB::query('SELECT ' . $select . ' FROM %b WHERE id IN %li ORDER BY id', $table, array_column($rows, 'id'));
        m17_upgrade_require($after === $rows, 'Historical rows changed during upgrade: ' . $table);
    }
    $oldReceipts = DB::query('SELECT * FROM pl_schema_migrations WHERE version IN %ls ORDER BY version', array_column($receipt['receipts'], 'version'));
    m17_upgrade_require($oldReceipts === $receipt['receipts'], 'Published migration receipts changed.');
    $f = $receipt['fixture'];
    $trial = pl_trial_balance($f['actor_id'], $f['company_id'], $f['book_id']);
    m17_upgrade_require($trial['balanced'] && $trial['total_debit'] === $receipt['total_debit']
        && $trial['total_credit'] === $receipt['total_credit'], 'Historical trial balance changed.');
    m17_upgrade_require(pl_migrate()['applied'] === [], 'Migration replay was not a no-op.');
    echo 'Upgrade passed: ' . count($migration['applied']) . " migrations from v1.2.1, historical accounts/periods/posted journals/partially paid invoice unchanged, old receipts preserved, balanced totals and no-op replay.\n";
} finally {
    DB::useDB('phpledger_test');
    DB::query('DROP DATABASE %b', $database);
    unlink($receiptPath);
}
