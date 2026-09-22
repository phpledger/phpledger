<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/www/phpledger/includes/functions/update_functions.php';

function update_check(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
function update_reject(callable $action, string $message): void
{
    try { $action(); } catch (Throwable $error) { echo "PASS $message\n"; return; }
    throw new RuntimeException($message . ' was accepted.');
}
function update_remove_fixture(string $path): void
{
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        if ($file->isDir() && !$file->isLink()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); }
    }
    rmdir($path);
}
$fixture = sys_get_temp_dir() . '/phpledger-update-unit-' . bin2hex(random_bytes(8));
mkdir($fixture, 0700, true);
$key = openssl_pkey_new(['private_key_bits' => 3072, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if (!$key || !class_exists(ZipArchive::class)) { throw new RuntimeException('Update tests require OpenSSL RSA and ZIP.'); }
$public = openssl_pkey_get_details($key)['key'];
$private = $fixture . '/installation'; mkdir($private, 0700);
putenv('PL_INSTALL_DIRECTORY=' . $private); putenv('PL_ENV=test');
pl_update_write($private . '/operator.key', str_repeat('test-only-', 5));
pl_update_write($private . '/publisher.pem', $public);
pl_update_write($private . '/secret.key', 'sample-secret-store-key');
try {
    foreach (['../escape.php', '/absolute.php', 'a//b', 'storage/key.php', 'www/config.local.php', 'a/CON.php', 'a/file.', 'a/private.pem', 'a\b.php'] as $path) {
        update_reject(fn() => pl_update_path($path), 'unsafe package path rejected');
    }
    update_reject(fn() => pl_update_operator($private, 'company-owner'), 'company ownership is not installation authority');
    pl_update_operator($private, str_repeat('test-only-', 5));
    // Remote operator-key guessing is bounded; host command-line recovery stays available.
    for ($attempt = 0; $attempt < 10; $attempt++) {
        try { pl_update_operator_attempt($private, 'sample-operator-guess-' . $attempt, time()); throw new LogicException('guess accepted'); }
        catch (DomainException $rejected) { /* expected */ }
    }
    update_reject(fn() => pl_update_operator_attempt($private, str_repeat('test-only-', 5), time()), 'bounded remote operator attempts refuse even a correct key');
    pl_update_operator($private, str_repeat('test-only-', 5));
    echo "PASS host command-line operator recovery is not locked out by remote guessing\n";
    pl_update_checkpoint($private . '/operator-attempts.json', ['started' => time() - 901, 'count' => 10]);
    pl_update_operator_attempt($private, str_repeat('test-only-', 5), time());
    update_check((int) pl_update_json($private . '/operator-attempts.json')['count'] === 0, 'Successful operator proof did not reset bounded attempts.');
    echo "PASS bounded operator attempts reset after their window\n";
    $root = $fixture . '/app'; mkdir($root, 0700);
    $loader = file_get_contents(dirname(__DIR__) . '/www/phpledger/public/maintenance.php');
    $files = [
        'vendor/autoload.php' => "<?php // sample\n", 'www/phpledger/includes/bootstrap.php' => "<?php // updated\n",
        'www/phpledger/public/index.php' => "<?php // updated entry\n", 'www/phpledger/public/maintenance.php' => $loader,
        'new-file.txt' => 'new release file',
    ];
    pl_update_write($root . '/www/phpledger/public/maintenance.php', $loader);
    pl_update_write($root . '/www/phpledger/includes/bootstrap.php', 'original code');
    pl_update_write($root . '/www/phpledger/includes/config.local.php', '<?php return ["sample"=>true];');
    pl_update_write($root . '/www/phpledger/storage/oauth/private.key', 'sample-private-key');
    pl_update_write($root . '/old-file.txt', 'old file');
    chmod($root . '/old-file.txt', 0750);
    chmod($root . '/www/phpledger/includes/bootstrap.php', 0640);
    pl_update_write($root . '/vendor/sergeytsalkov/meekrodb/db.class.php', (string) file_get_contents(dirname(__DIR__) . '/vendor/sergeytsalkov/meekrodb/db.class.php'));
    $installed = ['version' => '0.6.0-preview', 'files' => [['path' => 'old-file.txt'], ['path' => 'www/phpledger/includes/bootstrap.php'], ['path' => 'www/phpledger/public/maintenance.php']]];
    pl_update_checkpoint($root . '/PACKAGE-MANIFEST.json', $installed);
    $files['PACKAGE-MANIFEST.json'] = json_encode(['version' => '0.8.0-preview', 'files' => []], JSON_THROW_ON_ERROR);
    $archive = $fixture . '/release.zip';
    $zip = new ZipArchive(); $zip->open($archive, ZipArchive::CREATE | ZipArchive::EXCL);
    $inventory = [];
    foreach ($files as $path => $contents) {
        $zip->addFromString('phpledger-0.8.0-preview/' . $path, $contents);
        $inventory[] = ['path' => $path, 'bytes' => strlen($contents), 'sha256' => hash('sha256', $contents)];
    }
    $zip->close();
    $metadata = ['schema' => 1, 'version' => '0.8.0-preview', 'channel' => 'preview', 'min_php' => '8.2.0', 'files' => $inventory,
        'archive_bytes' => filesize($archive), 'archive_sha256' => hash_file('sha256', $archive)];
    $sign = static function (array $payload) use ($key): string {
        $json = json_encode($payload, JSON_THROW_ON_ERROR); openssl_sign($json, $signature, $key, OPENSSL_ALGO_SHA256);
        return json_encode(['payload' => base64_encode($json), 'signature' => base64_encode($signature)], JSON_THROW_ON_ERROR);
    };
    $envelope = $sign($metadata);
    $verified = pl_update_verify_metadata($envelope, $public, 'preview', '0.6.0-preview');
    update_check(count($verified['inventory']) === count($files), 'Signed inventory missing.');
    update_reject(fn() => pl_update_verify_metadata($envelope, $public, 'stable', '0.6.0-preview'), 'channel downgrade/crossover rejected');
    update_reject(fn() => pl_update_verify_metadata($envelope, $public, 'preview', '0.9.0-beta'), 'older signed release rejected');
    update_reject(fn() => pl_update_verify_metadata($envelope, $public, 'preview', ''), 'signed release rejected when the installed version is unknown');
    update_reject(fn() => pl_update_verify_metadata($envelope, $public, 'preview', 'not-a-version'), 'signed release rejected when the installed version is malformed');
    $tampered = json_decode($envelope, true); $tampered['payload'] = base64_encode(str_replace('0.8.0', '0.9.0', base64_decode($tampered['payload'])));
    update_reject(fn() => pl_update_verify_metadata(json_encode($tampered), $public, 'preview', '0.6.0-preview'), 'tampered metadata rejected');
    $expired = $metadata; $expired['expires_at'] = time() - 1;
    update_reject(fn() => pl_update_verify_metadata($sign($expired), $public, 'preview', '0.6.0-preview'), 'expired signed release rejected');
    $duplicate = $metadata; $duplicate['files'][] = array_replace($inventory[0], ['path' => strtoupper($inventory[0]['path'])]);
    update_reject(fn() => pl_update_verify_metadata($sign($duplicate), $public, 'preview', '0.6.0-preview'), 'case-colliding file rejected');
    $stage = $fixture . '/stage'; mkdir($stage);
    pl_update_stage($archive, $verified, $stage);
    update_check(file_get_contents($stage . '/new-file.txt') === 'new release file', 'Signed archive staging failed.');
    // Current releases unpack to phpledger/; the versioned root above stays accepted, any other root is refused.
    foreach (['phpledger/' => true, 'other/' => false] as $rootName => $accepted) {
        $rootArchive = $fixture . '/root-' . trim($rootName, '/') . '.zip';
        $rootZip = new ZipArchive(); $rootZip->open($rootArchive, ZipArchive::CREATE | ZipArchive::EXCL);
        foreach ($files as $path => $contents) { $rootZip->addFromString($rootName . $path, $contents); }
        $rootZip->close();
        $rootMetadata = $verified; $rootMetadata['archive_bytes'] = filesize($rootArchive); $rootMetadata['archive_sha256'] = hash_file('sha256', $rootArchive);
        $rootStage = $fixture . '/stage-' . trim($rootName, '/'); mkdir($rootStage);
        if ($accepted) {
            pl_update_stage($rootArchive, $rootMetadata, $rootStage);
            update_check(file_get_contents($rootStage . '/new-file.txt') === 'new release file', 'The phpledger/ archive root was not staged.');
        } else {
            update_reject(fn() => pl_update_stage($rootArchive, $rootMetadata, $rootStage), 'foreign archive root rejected');
        }
    }
    $badArchive = $fixture . '/bad.zip'; copy($archive, $badArchive); file_put_contents($badArchive, 'extra', FILE_APPEND);
    update_reject(fn() => pl_update_stage($badArchive, $verified, $stage), 'tampered archive rejected');
    $symlinkArchive = $fixture . '/symlink.zip'; copy($archive, $symlinkArchive);
    $unsafeZip = new ZipArchive(); $unsafeZip->open($symlinkArchive);
    $unsafeZip->setExternalAttributesName('phpledger-0.8.0-preview/new-file.txt', ZipArchive::OPSYS_UNIX, 0120777 << 16); $unsafeZip->close();
    clearstatcache(true, $symlinkArchive);
    $symlinkMetadata = $verified; $symlinkMetadata['archive_bytes'] = filesize($symlinkArchive); $symlinkMetadata['archive_sha256'] = hash_file('sha256', $symlinkArchive);
    update_reject(fn() => pl_update_stage($symlinkArchive, $symlinkMetadata, $stage), 'signed symlink archive member rejected');
    $connect = static fn(string $root) => null;
    $callbacks = ['connect' => $connect, 'backup_database' => static fn(string $path): array => ['sample' => true],
        'migrate' => static fn(string $root): bool => throw new RuntimeException('sample migration failure'),
        'restore_database' => static fn(string $path, array $receipt): bool => true, 'health' => static fn() => null];
    $state = pl_update_begin($root, $archive, $envelope, 'preview');
    update_check($state['phase'] === 'backup', 'Update did not enter backup.');
    update_reject(fn() => pl_update_begin($root, $archive, $envelope, 'preview'), 'concurrent update rejected');
    $reader = fopen($private . '/application.lock', 'c+b'); flock($reader, LOCK_SH);
    update_reject(fn() => pl_update_step($root, $callbacks), 'active application request is drained before backup');
    flock($reader, LOCK_UN); fclose($reader);
    update_check(pl_update_step($root, $callbacks)['phase'] === 'apply', 'Backup did not precede mutation.');
    update_check(pl_update_step($root, $callbacks)['phase'] === 'migrate', 'Apply did not checkpoint.');
    update_check(file_get_contents($root . '/www/phpledger/includes/bootstrap.php') !== 'original code', 'Fixture code not updated.');
    pl_update_write($private . '/secret.key', 'sample-altered-key');
    update_check(pl_update_step($root, $callbacks)['phase'] === 'restored', 'Failed migration did not recover.');
    update_check(file_get_contents($root . '/www/phpledger/includes/bootstrap.php') === 'original code', 'Original code not restored.');
    update_check(file_get_contents($root . '/old-file.txt') === 'old file' && !is_file($root . '/new-file.txt'), 'Old/new file restoration mismatch.');
    update_check(file_get_contents($root . '/www/phpledger/storage/oauth/private.key') === 'sample-private-key', 'Private key lost.');
    update_check(file_get_contents($private . '/secret.key') === 'sample-secret-store-key', 'Secret store key not restored with the matched database.');
    clearstatcache();
    update_check((fileperms($root . '/old-file.txt') & 0777) === 0750 && (fileperms($root . '/www/phpledger/includes/bootstrap.php') & 0777) === 0640
        && (fileperms($root . '/www/phpledger/storage/oauth/private.key') & 0777) === 0600, 'Original code and private-key permissions were not restored.');
    update_check(!is_file($private . '/updates/active.json'), 'Maintenance not released after verified recovery.');
    echo "PASS automatic matched file recovery after migration failure\n";
    $state = pl_update_begin($root, $archive, $envelope, 'preview');
    pl_update_step($root, $callbacks); pl_update_step($root, $callbacks);
    $operation = $private . '/updates/' . $state['id']; $state = pl_update_json($operation . '/state.json');
    $state['inflight'] = true; pl_update_checkpoint($operation . '/state.json', $state);
    $unavailable = array_replace($callbacks, ['restore_database' => static fn(): bool => throw new RuntimeException('sample outage')]);
    update_check(pl_update_step($root, $unavailable)['phase'] === 'recovering', 'Interrupted update did not hold maintenance.');
    update_check(is_file($private . '/updates/active.json'), 'Recovery outage reopened application.');
    update_check(pl_update_step($root, $callbacks)['phase'] === 'restored', 'Recovery did not resume when database returned.');
    echo "PASS interrupted mutation fails closed and resumes recovery\n";
    $manyOperation = $private . '/updates/batch-fixture'; $manyMetadata = $verified; $manyInstalled = $installed;
    foreach ($files as $path => $data) { pl_update_write($manyOperation . '/stage/' . $path, $data); }
    for ($index = 0; $index < 120; $index++) {
        $old = 'batch-old/' . $index . '.txt'; $new = 'batch-new/' . $index . '.txt';
        pl_update_write($root . '/' . $old, 'original-' . $index); chmod($root . '/' . $old, 0640);
        $manyInstalled['files'][] = ['path' => $old];
        $data = 'replacement-' . $index; pl_update_write($manyOperation . '/stage/' . $new, $data);
        $manyMetadata['inventory'][$new] = ['path' => $new, 'bytes' => strlen($data), 'sha256' => hash('sha256', $data)];
    }
    pl_update_checkpoint($root . '/PACKAGE-MANIFEST.json', $manyInstalled);
    $manyBackup = null; $backupCalls = 0;
    while ($manyBackup === null && $backupCalls++ < 10) { $manyBackup = pl_update_file_backup($root, $manyOperation, $manyMetadata, 100); }
    update_check($manyBackup !== null && $backupCalls >= 3, 'Managed-file backup did not yield between bounded batches.');
    $applied = false; $applyCalls = 0;
    while (!$applied && $applyCalls++ < 10) {
        $applied = pl_update_apply_files($root, $manyOperation, $manyMetadata, $manyBackup, 100);
        if ($applyCalls === 1) { pl_update_checkpoint($manyOperation . '/apply-files-progress.json', ['index' => 0]); }
    }
    update_check($applied && $applyCalls >= 3 && !is_file($root . '/batch-old/119.txt'), 'Managed-file application did not resume/replay bounded batches.');
    $restored = false; $restoreCalls = 0; $replayed = false;
    while (!$restored && $restoreCalls++ < 20) {
        $restored = pl_update_restore_files($root, $manyOperation, $manyBackup, 100);
        $progress = pl_update_json($manyOperation . '/restore-files-progress.json');
        if (!$replayed && $progress['phase'] === 'restore' && $progress['index'] > 0) {
            $progress['index'] = 0; pl_update_checkpoint($manyOperation . '/restore-files-progress.json', $progress); $replayed = true;
        }
    }
    update_check($restored && $restoreCalls >= 5 && $replayed, 'Managed-file restoration did not resume after a lost copy checkpoint.');
    for ($index = 0; $index < 120; $index++) {
        $old = $root . '/batch-old/' . $index . '.txt'; clearstatcache(true, $old);
        update_check(file_get_contents($old) === 'original-' . $index && (fileperms($old) & 0777) === 0640
            && !is_file($root . '/batch-new/' . $index . '.txt'), 'Batched restore changed original content/mode or retained a new file.');
    }
    echo "PASS bounded file backup/application/recovery and permission preservation with lost-checkpoint replay ($backupCalls/$applyCalls/$restoreCalls stages)\n";
    echo "Update signature, staging and recovery tests passed.\n";
} finally { putenv('PL_INSTALL_DIRECTORY'); update_remove_fixture($fixture); }
