<?php
declare(strict_types=1);

// Exact-archive fixture. Run only through tools/verify-patch-release.py.
[$script, $mode, $package, $receiptPath] = array_pad($argv, 4, '');
$host = (string) getenv('PL_DB_HOST');
$databaseName = (string) getenv('PL_DB_NAME');
$runId = (string) getenv('PL_PATCH_RUN_ID');
if (PHP_SAPI !== 'cli' || getenv('PL_ENV') !== 'test' || getenv('PL_DB_USER') !== 'root'
    || !preg_match('/^pl141-[a-z0-9-]+$/D', $host)
    || !preg_match('/^[a-f0-9]{8}$/D', $runId)
    || !in_array($mode, ['seed', 'verify', 'fresh', 'cleanup'], true)
    || !is_file($package . '/PACKAGE-MANIFEST.json') || $receiptPath === '') {
    throw new RuntimeException('Use an explicit pl141 test service, extracted archive and private receipt.');
}
if ($mode !== 'verify' && $databaseName !== 'phpledger_test') {
    throw new RuntimeException('Seed, fresh and cleanup start from the isolated test database.');
}
$version = trim((string) file_get_contents($package . '/www/phpledger/VERSION'));
if ($version !== (in_array($mode, ['seed', 'cleanup'], true) ? '1.4.0' : '1.4.1')) {
    throw new RuntimeException('The selected mode received the wrong exact release package.');
}
require $package . '/www/phpledger/includes/bootstrap.php';
require $package . '/www/phpledger/install/migrate.php';
if (DB::$host !== $host || DB::$user !== 'root' || DB::$dbName !== $databaseName) {
    throw new RuntimeException('Effective connection escaped the isolated test service.');
}

function patch_require(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function patch_database_name(): string
{
    global $runId;
    return 'pl141_patch_' . $runId . '_' . bin2hex(random_bytes(8));
}

function patch_receipt_database(string $path): string
{
    global $runId;
    $receipt = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    $name = $receipt['database'] ?? '';
    patch_require(is_string($name) && (bool) preg_match('/^pl141_patch_' . $runId . '_[a-f0-9]{16}$/D', $name), 'Invalid disposable schema receipt.');
    return $name;
}

function patch_cleanup_owned(): void
{
    global $runId;
    $owned = [];
    foreach (DB::queryFirstColumn('SHOW DATABASES') as $name) {
        if (is_string($name) && preg_match('/^pl141_patch_' . $runId . '_[a-f0-9]{16}$/D', $name)) {
            $owned[] = $name;
        }
    }
    foreach ($owned as $name) {
        DB::query('DROP DATABASE %b', $name);
    }
    echo 'PASS removed ' . count($owned) . " schema(s) owned by this patch run.\n";
}

function patch_snapshot(): array
{
    // The schema is dedicated to this fixture, so whole-table hashes are scoped to its records.
    $tables = ['pl_users', 'pl_companies', 'pl_company_members', 'pl_books', 'pl_accounts', 'pl_periods',
        'pl_journals', 'pl_journal_lines', 'pl_parties', 'pl_documents', 'pl_ar_documents',
        'pl_ar_document_lines', 'pl_ar_document_actions', 'pl_ar_document_events',
        'pl_open_items', 'pl_open_item_entries', 'pl_employees', 'pl_employee_audit', 'pl_schema_migrations'];
    $snapshot = [];
    foreach ($tables as $table) {
        $rows = array_map(static fn(array $row): string => json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            DB::query('SELECT * FROM %b', $table));
        sort($rows, SORT_STRING);
        $snapshot[$table] = ['count' => count($rows), 'sha256' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR))];
    }
    return $snapshot;
}

function patch_seed(): void
{
    global $receiptPath;
    patch_require(!file_exists($receiptPath), 'A prior receipt exists; inspect it before running again.');
    $database = patch_database_name();
    DB::query('CREATE DATABASE %b CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $database);
    DB::useDB($database);
    try {
        $migrations = pl_migrate();
        patch_require(count($migrations['applied']) === 61 && in_array('060_cash_balance_policy', $migrations['applied'], true), 'Published 1.4.0 migration chain differs.');
        $actor = pl_create_user('patch-' . bin2hex(random_bytes(6)) . '@example.test', 'Sample patch owner', bin2hex(random_bytes(24)));
        $fixture = pl_create_company($actor, 'Sample patch upgrade company', 'USD', '2026-01-01');
        $company = $fixture['company_id']; $book = $fixture['book_id']; $accounts = $fixture['accounts'];
        $party = pl_save_party($actor, $company, $book, ['legal_name' => 'Sample patch counterparty', 'entity_type' => 'private_company',
            'country_code' => 'US', 'is_customer' => true, 'is_vendor' => true, 'currency' => 'USD',
            'request_key' => 'patch-party', 'reason' => 'Sample upgrade fixture']);
        $receipt = pl_save_document($actor, $company, $book, ['kind' => 'receipt', 'date' => '2026-01-05', 'amount' => '500',
            'money_account_id' => $accounts['1000'], 'category_account_id' => $accounts['4000'], 'party_id' => $party['id'],
            'counterparty' => '', 'reference' => 'PATCH-RECEIPT', 'memo' => 'Sample receipt', 'creation_key' => 'patch-receipt']);
        $receipt = pl_post_document($actor, $company, $book, $receipt['id'], $receipt['revision']);
        $draft = pl_save_document($actor, $company, $book, ['kind' => 'expense', 'date' => '2026-01-06', 'amount' => '12.5000',
            'money_account_id' => $accounts['1000'], 'category_account_id' => $accounts['5000'], 'party_id' => $party['id'],
            'counterparty' => '', 'reference' => 'PATCH-DRAFT', 'memo' => 'Keep this draft', 'creation_key' => 'patch-expense-draft']);
        $invoice = pl_save_ar_document($actor, $company, $book, ['kind' => 'invoice', 'party_id' => $party['id'],
            'date' => '2026-01-07', 'due_date' => '2026-02-07', 'currency' => 'USD', 'reference' => 'PATCH-INVOICE',
            'creation_key' => 'patch-invoice', 'lines' => [['description' => 'Sample service', 'quantity' => '1',
                'unit_price' => '1000', 'account_id' => $accounts['4000']]]]);
        $invoice = pl_post_ar_document($actor, $company, $book, $invoice['id'], $invoice['revision']);
        pl_settle_ar_document($actor, $company, $book, $invoice['id'], ['bank_account_id' => $accounts['1000'],
            'gain_account_id' => $accounts['4000'], 'loss_account_id' => $accounts['5000'], 'amount_fc' => '250',
            'date' => '2026-01-08', 'description' => 'Sample partial settlement', 'idempotency_key' => 'patch-partial']);
        $employee = pl_save_employee($actor, $company, ['full_name' => 'Sample patch employee', 'employment_type' => 'full_time',
            'employment_status' => 'active', 'hire_date' => '2026-01-01', 'reason' => 'Sample upgrade fixture']);
        $journal = pl_post_journal($actor, $company, $book, ['date' => '2026-01-09', 'currency' => 'USD',
            'source_type' => 'general_journal', 'source_reference' => 'patch-reversing', 'description' => 'Sample reversing entry',
            'idempotency_key' => 'patch-original', 'lines' => [
                ['account_id' => $accounts['5000'], 'debit' => '7', 'credit' => '0'],
                ['account_id' => $accounts['1000'], 'debit' => '0', 'credit' => '7']]]);
        $reversal = pl_reverse_journal($actor, $company, $book, $journal['id'], '2026-01-09', 'patch-reversal', 'Sample linked reversal');
        patch_require((int) $reversal['reversal_of_id'] === (int) $journal['id'], 'Journal reversal is not linked.');
        $trial = pl_trial_balance($actor, $company, $book);
        patch_require($trial['balanced'], 'Baseline trial balance is not balanced.');
        $migrationRows = DB::query('SELECT * FROM pl_schema_migrations ORDER BY version');
        $payload = ['database' => $database, 'ids' => ['actor' => $actor, 'company' => $company, 'book' => $book,
            'party' => $party['id'], 'receipt' => $receipt['id'], 'draft' => $draft['id'], 'invoice' => $invoice['id'],
            'employee' => $employee['id'], 'journal' => $journal['id'], 'reversal' => $reversal['id']],
            'migration_rows' => $migrationRows, 'snapshot' => patch_snapshot(),
            'trial' => ['debit' => $trial['total_debit'], 'credit' => $trial['total_credit']]];
        patch_require(count($migrationRows) === 61, 'Baseline migration receipts are incomplete.');
        $oldMask = umask(0077);
        try { patch_require(file_put_contents($receiptPath, json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)) !== false, 'Could not save fixture receipt.'); }
        finally { umask($oldMask); }
        echo "PASS exact 1.4.0 archive seeded receipt, expense draft, party, employee, partial invoice and linked reversal; 61 migrations.\n";
    } catch (Throwable $error) {
        DB::useDB('phpledger_test');
        DB::query('DROP DATABASE %b', $database);
        throw $error;
    }
}

function patch_verify(): void
{
    global $receiptPath, $databaseName;
    $receipt = json_decode((string) file_get_contents($receiptPath), true, 64, JSON_THROW_ON_ERROR);
    patch_require($databaseName === patch_receipt_database($receiptPath), 'Verification database differs from receipt.');
    $rows = DB::query('SELECT * FROM pl_schema_migrations ORDER BY version');
    patch_require($rows === $receipt['migration_rows'] && count($rows) === 61, 'Migration receipts changed or a migration was added.');
    patch_require(patch_snapshot() === $receipt['snapshot'], 'An historical fixture row changed during upgrade.');
    $ids = $receipt['ids'];
    $trial = pl_trial_balance($ids['actor'], $ids['company'], $ids['book']);
    patch_require($trial['balanced'] && $trial['total_debit'] === $receipt['trial']['debit']
        && $trial['total_credit'] === $receipt['trial']['credit'], 'Trial balance changed during patch upgrade.');
    patch_require(pl_migrate()['applied'] === [], 'Patch migration replay applied new work.');
    patch_require(pl_install_database_check()['status'] === 'current', 'Candidate schema is not current.');
    $invoice = pl_get_ar_document($ids['actor'], $ids['company'], $ids['book'], $ids['invoice']);
    patch_require($invoice['payment_status'] === 'partially_paid' && $invoice['outstanding_fc'] === '750.0000', 'Partial invoice state changed.');
    $draft = pl_get_document($ids['actor'], $ids['company'], $ids['book'], $ids['draft']);
    patch_require($draft['journal_id'] === null, 'Expense draft was posted during upgrade.');
    patch_require((int) DB::queryFirstField('SELECT reversal_of_id FROM pl_journals WHERE id=%i', $ids['reversal']) === (int) $ids['journal'], 'Linked reversal changed.');
    echo "PASS packaged upgrade twice; 61 identical migration receipts, historical row hashes, partial invoice, draft, reversal and balanced totals.\n";
}

function patch_fresh(): void
{
    $database = patch_database_name();
    DB::query('CREATE DATABASE %b CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $database);
    DB::useDB($database);
    try {
        $migrations = pl_migrate();
        patch_require(count($migrations['applied']) === 61 && count(DB::query('SELECT * FROM pl_schema_migrations')) === 61, 'Candidate fresh migration count differs from 61.');
        $actor = pl_create_user('fresh-' . bin2hex(random_bytes(6)) . '@example.test', 'Sample fresh owner', bin2hex(random_bytes(24)));
        $fixture = pl_create_company($actor, 'Sample fresh patch company', 'USD', '2026-01-01');
        pl_post_journal($actor, $fixture['company_id'], $fixture['book_id'], ['date' => '2026-01-05', 'currency' => 'USD',
            'source_type' => 'general_journal', 'source_reference' => 'patch-fresh-post', 'description' => 'First packaged posting',
            'idempotency_key' => 'patch-fresh-post', 'lines' => [
                ['account_id' => $fixture['accounts']['1000'], 'debit' => '1.2500', 'credit' => '0'],
                ['account_id' => $fixture['accounts']['3000'], 'debit' => '0', 'credit' => '1.2500']]]);
        $trial = pl_trial_balance($actor, $fixture['company_id'], $fixture['book_id']);
        patch_require($trial['balanced'] && $trial['total_debit'] === '1.2500' && pl_migrate()['applied'] === [], 'Fresh first posting or replay failed.');
        echo "PASS exact 1.4.1 candidate fresh install: 61 migrations, first balanced posting and no-op replay.\n";
    } finally {
        DB::useDB('phpledger_test');
        DB::query('DROP DATABASE %b', $database);
    }
}

if ($mode === 'seed') { patch_seed(); }
elseif ($mode === 'verify') { patch_verify(); }
elseif ($mode === 'fresh') { patch_fresh(); }
else {
    patch_cleanup_owned();
}
