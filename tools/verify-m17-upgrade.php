<?php
declare(strict_types=1);

// A two-process upgrade proof: seed with the archived v1.3.0 implementation, then
// check with the candidate implementation. Only a random disposable test schema.
if (PHP_SAPI !== 'cli' || getenv('PL_ENV') !== 'test' || getenv('PL_DB_HOST') !== 'db_test'
    || getenv('PL_DB_NAME') !== 'phpledger_test' || getenv('PL_DB_USER') !== 'root') {
    fwrite(STDERR, "M17 upgrade verification requires the isolated db_test root account.\n");
    exit(2);
}
$mode = $argv[1] ?? '';
if (!in_array($mode, ['seed', 'check', 'verify', 'verify-candidate'], true)) {
    throw new DomainException('Choose seed or check; seed also takes the archived v1.3.0 source directory.');
}
$source = isset($argv[2]) ? realpath($argv[2]) : dirname(__DIR__);
if (!$source || !is_file($source . '/www/phpledger/includes/bootstrap.php')) {
    throw new DomainException('The source directory must contain the application.');
}
if ($mode === 'seed' && trim((string) file_get_contents($source . '/www/phpledger/VERSION')) !== '1.3.0') {
    throw new DomainException('Seed from the published v1.3.0 archive.');
}
require $source . '/www/phpledger/includes/bootstrap.php';
require $source . '/www/phpledger/install/migrate.php';
if (DB::$host !== 'db_test' || DB::$dbName !== 'phpledger_test' || DB::$user !== 'root') {
    throw new RuntimeException('Effective configuration is not the disposable test service.');
}
$receiptPath = getenv('PL_UPGRADE_RECEIPT') ?: '/tmp/phpledger-m17-upgrade.json';

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
        m17_upgrade_require(in_array('056_membership_role_id', $migrations['applied'], true)
            && !in_array('057_document_parties', $migrations['applied'], true), 'Unexpected baseline migration chain.');
        // Use the actual release fixture builders and services, never current helpers
        // against an old schema. Merely loading test files does not run their tests.
        function test(string $name, callable $action): void {}
        $fixtures = getenv('PL_BASELINE_FIXTURES') ?: $source;
        require $fixtures . '/tests/ledger_test.php';
        require $fixtures . '/tests/ar_ap_test.php';
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
            'memberships' => DB::query('SELECT company_id,user_id,role_id FROM pl_company_members ORDER BY company_id,user_id'),
            'receipts' => DB::query('SELECT * FROM pl_schema_migrations ORDER BY version'),
            'total_debit' => $trial['total_debit'], 'total_credit' => $trial['total_credit']];
        $oldMask = umask(0077);
        try {
            m17_upgrade_require(file_put_contents($receiptPath, json_encode($receipt, JSON_THROW_ON_ERROR)) !== false,
                'Could not persist the sample upgrade receipt.');
        } finally { umask($oldMask); }
        echo "Seed passed: published v1.3.0, posted cash journal, partially paid invoice, exact historical rows recorded.\n";
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
    $migration = in_array($mode, ['verify', 'verify-candidate'], true) ? ['applied'=>[]] : pl_migrate();
    $expectedMigrations = ['057_document_parties', '058_money_account_kind', '059_bank_overdraft_limits', '060_cash_balance_policy'];
    foreach ($mode === 'verify' ? [] : $expectedMigrations as $version) {
        m17_upgrade_require($mode === 'verify-candidate' ? DB::queryFirstField('SELECT status FROM pl_schema_migrations WHERE version=%s',$version)==='applied' : in_array($version, $migration['applied'], true), 'Expected new migration not applied: ' . $version);
    }
    if ($mode !== 'verify') {
        foreach (['company.read', 'company.write', 'payroll.view', 'payroll.manage', 'schedules.view', 'schedules.manage', 'loans.view', 'loans.manage'] as $capability) {
            m17_upgrade_require((int) DB::queryFirstField("SELECT COUNT(*) FROM pl_role_capabilities rc JOIN pl_capabilities c ON c.id=rc.capability_id JOIN pl_roles r ON r.id=rc.role_id WHERE r.company_id IS NULL AND r.slug='owner' AND c.capability=%s", $capability) === 1, 'Owner capability was not initialized by the upgrade: ' . $capability);
        }
    }
    foreach ($receipt['rows'] as $table => $rows) {
        $fields = array_keys($rows[0]);
        $select = implode(', ', array_map(static fn(string $field): string => '`' . str_replace('`', '``', $field) . '`', $fields));
        $after = DB::query('SELECT ' . $select . ' FROM %b WHERE id IN %li ORDER BY id', $table, array_column($rows, 'id'));
        m17_upgrade_require($after === $rows, 'Historical rows changed during upgrade: ' . $table);
    }
    m17_upgrade_require(DB::query('SELECT company_id,user_id,role_id FROM pl_company_members ORDER BY company_id,user_id') === $receipt['memberships'], 'Original membership identities or role assignments changed.');
    $oldReceipts = DB::query('SELECT * FROM pl_schema_migrations WHERE version IN %ls ORDER BY version', array_column($receipt['receipts'], 'version'));
    m17_upgrade_require($oldReceipts === $receipt['receipts'], 'Published migration receipts changed.');
    $f = $receipt['fixture'];
    if ($mode !== 'verify') {
        $newVersions = DB::queryFirstColumn('SELECT version FROM pl_schema_migrations WHERE version NOT IN %ls ORDER BY version', array_column($receipt['receipts'], 'version'));
        m17_upgrade_require($newVersions === $expectedMigrations, 'Upgrade introduced an unexpected migration set.');
        m17_upgrade_require(pl_trading_policies($f['actor_id'], $f['company_id'], $f['book_id'])['cash_shortfall_policy'] === 'warning', 'Upgrade did not preserve warning-only cash policy default.');
    }
    $trial = pl_trial_balance($f['actor_id'], $f['company_id'], $f['book_id']);
    m17_upgrade_require($trial['balanced'] && $trial['total_debit'] === $receipt['total_debit']
        && $trial['total_credit'] === $receipt['total_credit'], 'Historical trial balance changed.');
    if ($mode === 'verify') { m17_upgrade_require(DB::query('SELECT * FROM pl_schema_migrations ORDER BY version') === $receipt['receipts'], 'Restoration did not recover the exact original migration receipts.'); }
    else { m17_upgrade_require(pl_migrate()['applied'] === [], 'Migration replay was not a no-op.'); }
    echo $mode === 'verify'
        ? "Restoration passed: exact v1.3.0 rows, memberships, migration receipts and balanced totals recovered.\n"
        : "Upgrade passed: exactly four v1.4.0 migration receipts verified, warning-only cash default retained, historical accounts/periods/posted journals/partially paid invoice unchanged, old receipts preserved, balanced totals and no-op replay.\n";
} finally {
    if (getenv('PL_UPGRADE_KEEP') !== '1') {
        DB::useDB('phpledger_test');
        DB::query('DROP DATABASE %b', $database);
        unlink($receiptPath);
    }
}
