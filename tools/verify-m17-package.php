<?php
declare(strict_types=1);

// Run this probe outside the extracted archive. Application code and dependencies
// must all load from that unchanged archive, not from this checkout.
if (PHP_SAPI !== 'cli' || getenv('PL_ENV') !== 'test' || !in_array(getenv('PL_DB_HOST'), ['db_test', 'maria_test'], true)
    || getenv('PL_DB_NAME') !== 'phpledger_test' || getenv('PL_DB_USER') !== 'root') {
    fwrite(STDERR, "Package verification requires the disposable db_test/maria_test root account.\n");
    exit(2);
}
$package = realpath($argv[1] ?? '');
if (!$package || !is_file($package . '/PACKAGE-MANIFEST.json')) {
    throw new DomainException('Pass the extracted application package root.');
}
$manifest = json_decode((string) file_get_contents($package . '/PACKAGE-MANIFEST.json'), true, 64, JSON_THROW_ON_ERROR);
foreach ($manifest['files'] as $entry) {
    $path = realpath($package . '/' . $entry['path']);
    if (!$path || !str_starts_with($path, $package . DIRECTORY_SEPARATOR)
        || filesize($path) !== $entry['bytes'] || hash_file('sha256', $path) !== $entry['sha256']) {
        throw new RuntimeException('The extracted package does not match its manifest.');
    }
}
$private = sys_get_temp_dir() . '/phpledger-m17-package-' . bin2hex(random_bytes(12));
if (!mkdir($private, 0700)) { throw new RuntimeException('Could not create private probe storage.'); }
putenv('PL_INSTALL_DIRECTORY=' . $private);
require $package . '/www/phpledger/includes/bootstrap.php';
require $package . '/www/phpledger/install/migrate.php';
if (!in_array(DB::$host, ['db_test', 'maria_test'], true) || DB::$dbName !== 'phpledger_test' || DB::$user !== 'root') {
    throw new RuntimeException('Effective configuration is not the disposable test service.');
}
$database = 'phpledger_m17_package_' . bin2hex(random_bytes(12));
DB::query('CREATE DATABASE %b CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $database);
DB::useDB($database);
try {
    $migrations = pl_migrate();
    foreach (['047_period_close', '048_employee_master', '049_secret_store', '057_document_parties', '058_money_account_kind', '059_bank_overdraft_limits', '060_cash_balance_policy'] as $version) {
        if (!in_array($version, $migrations['applied'], true)) { throw new RuntimeException('Package omitted required migration ' . $version); }
    }
    $sampleIds = pl_sample_structure_ids();
    if ($sampleIds === []) { throw new RuntimeException('Package has no bundled sample structures.'); }
    foreach ($sampleIds as $sampleId) {
        if (pl_sample_structure_read($sampleId) === null) {
            throw new RuntimeException('Packaged sample structure is missing or stale: ' . $sampleId);
        }
    }
    $actor = pl_create_user('package@example.test', 'Sample package owner', bin2hex(random_bytes(24)));
    $f = pl_create_company($actor, 'Sample M17 package company', 'USD', '2026-01-01');
    $company = $f['company_id']; $book = $f['book_id'];
    $policy = pl_trading_policies($actor, $company, $book);
    if ($policy['cash_shortfall_policy'] !== 'warning') { throw new RuntimeException('Fresh install did not default to warning only.'); }
    pl_save_trading_policies($actor, $company, $book, array_replace($policy, ['cash_shortfall_policy'=>'strict','reason'=>'Opt into strict policy for package proof','idempotency_key'=>'package-strict-policy']));
    $cash = pl_get_account($actor, $company, $book, $f['accounts']['1000']);
    if ($cash['money_kind'] !== null) { throw new RuntimeException('New company silently classified its combined cash / bank account.'); }
    pl_save_account($actor, $company, $book, array_replace($cash, ['money_kind' => 'physical', 'reason' => 'The package proof uses a physical cash box.']), $cash['id'], $cash['revision']);
    $party = pl_save_party($actor, $company, $book, ['legal_name' => 'Sample package customer', 'entity_type' => 'private_company', 'country_code' => 'US',
        'is_customer' => true, 'is_vendor' => false, 'currency' => 'USD', 'request_key' => 'package-proof-party', 'reason' => 'Fictional package receipt proof.']);
    $receipt = pl_save_document($actor, $company, $book, ['kind' => 'receipt', 'date' => '2026-09-14', 'amount' => '125', 'money_account_id' => $cash['id'],
        'category_account_id' => $f['accounts']['4000'], 'party_id' => $party['id'], 'counterparty' => '', 'reference' => 'PACKAGE-RECEIPT', 'memo' => 'Sample receipt proof', 'creation_key' => 'package-proof-receipt']);
    $receipt = pl_post_document($actor, $company, $book, $receipt['id'], $receipt['revision']);
    if ($receipt['party_id'] !== $party['id'] || $receipt['counterparty'] !== 'Sample package customer') { throw new RuntimeException('Packaged receipt omitted its linked party or name snapshot.'); }
    $refused = false;
    try {
        pl_post_journal($actor, $company, $book, ['date' => '2026-09-14', 'currency' => 'USD', 'source_type' => 'general_journal', 'source_reference' => 'package-overspend',
            'idempotency_key' => 'package-overspend', 'description' => 'Must reject fictional cash overspend', 'lines' => [
                ['account_id' => $f['accounts']['5000'], 'debit' => '126', 'credit' => '0'], ['account_id' => $cash['id'], 'debit' => '0', 'credit' => '126']]]);
    } catch (DomainException $error) { $refused = str_contains($error->getMessage(), 'keep the document as a draft'); }
    if (!$refused || DB::queryFirstField('SELECT id FROM pl_journals WHERE book_id=%i AND idempotency_key=%s', $book, 'package-overspend')) { throw new RuntimeException('Packaged physical cash control did not atomically reject overspending.'); }
    $bank = pl_save_account($actor, $company, $book, ['code' => 'BANK-PROOF', 'name' => 'Sample agreed bank facility', 'type' => 'asset', 'role' => 'cash_bank',
        'money_kind' => 'bank', 'overdraft_enabled' => true, 'overdraft_limit' => '10', 'is_active' => true, 'reason' => 'Fictional package proof of an agreed bank facility.', 'creation_key' => 'package-bank-facility']);
    $bankPayment = ['date' => '2026-09-14', 'currency' => 'USD', 'source_type' => 'general_journal', 'source_reference' => 'package-bank-limit',
        'idempotency_key' => 'package-bank-limit', 'description' => 'Sample exact bank facility limit', 'lines' => [
            ['account_id' => $f['accounts']['5000'], 'debit' => '10', 'credit' => '0'], ['account_id' => $bank['id'], 'debit' => '0', 'credit' => '10']]];
    pl_post_journal($actor, $company, $book, $bankPayment);
    $bankPayment['idempotency_key'] = 'package-bank-over-limit'; $bankPayment['source_reference'] = 'package-bank-over-limit';
    $bankPayment['lines'][0]['debit'] = '0.0001'; $bankPayment['lines'][1]['credit'] = '0.0001';
    $refused = false;
    try { pl_post_journal($actor, $company, $book, $bankPayment); }
    catch (DomainException $error) { $refused = str_contains($error->getMessage(), 'permitted floor of -10.0000'); }
    if (!$refused) { throw new RuntimeException('Packaged bank facility exceeded its agreed limit.'); }
    $employee = pl_save_employee($actor, $company, ['full_name' => 'Sample Employee',
        'employment_type' => 'full_time', 'employment_status' => 'active', 'hire_date' => '2026-01-01',
        'reason' => 'Sample package smoke.']);
    if ($employee['id'] < 1) { throw new RuntimeException('The packaged employee register did not save.'); }
    $count = pl_record_cash_count($actor, $company, $book, ['account_id' => $f['accounts']['1000'],
        'count_date' => '2026-09-14', 'counted_at' => '2026-09-14 18:00:00', 'counted_amount' => '122.35',
        'note' => 'Sample package cash count', 'creation_key' => 'package-count']);
    if ($count['difference'] !== '-2.6500' || !$count['journal_id']) {
        throw new RuntimeException('The packaged cash count did not post its exact difference.');
    }
    $period = (int) DB::queryFirstField('SELECT id FROM pl_periods WHERE company_id=%i AND book_id=%i ORDER BY id LIMIT 1', $company, $book);
    $checklist = pl_period_checklist($actor, $company, $book, $period);
    if ($checklist['items'] === []) { throw new RuntimeException('The packaged period checklist is empty.'); }
    $ciphertext = pl_secret_encrypt('Sample package proof', 'package-proof');
    if ($ciphertext === 'Sample package proof' || pl_secret_decrypt($ciphertext, 'package-proof') !== 'Sample package proof') {
        throw new RuntimeException('The packaged encrypted secret primitive did not round-trip.');
    }
    $trial = pl_trial_balance($actor, $company, $book);
    if (!$trial['balanced'] || pl_migrate()['applied'] !== []) {
        throw new RuntimeException('The packaged first operations or migration replay failed.');
    }
    // Recheck after execution as evidence that the probe did not alter the payload.
    foreach ($manifest['files'] as $entry) {
        if (hash_file('sha256', $package . '/' . $entry['path']) !== $entry['sha256']) {
            throw new RuntimeException('Package runtime changed an archive payload file.');
        }
    }
    echo 'Package smoke passed: ' . count($migrations['applied']) . ' fresh migrations; '
        . "bundled sample structures current, party-linked receipt snapshot retained, physical cash overspend refused, agreed bank facility exact limit accepted and excess refused, employee saved, cash difference posted exactly, checklist rendered as data, encrypted secret round-trip, balanced report, replay no-op and payload unchanged.\n";
} finally {
    DB::useDB('phpledger_test');
    DB::query('DROP DATABASE %b', $database);
    foreach (['application.lock', 'secret.lock', 'secret.key'] as $name) {
        if (is_file($private . '/' . $name)) { unlink($private . '/' . $name); }
    }
    rmdir($private);
}
