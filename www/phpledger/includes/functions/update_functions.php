<?php
declare(strict_types=1);

/** Installation operations deliberately do not use company/session authority. */
function pl_update_private_config(string $root): array
{
    $path = getenv('PL_INSTALL_CONFIG_PATH') ?: $root . '/www/phpledger/includes/config.local.php';
    if (!is_file($path)) { return []; }
    $config = require $path;
    if (!is_array($config)) { throw new RuntimeException('Private installation configuration is invalid.'); }
    return $config;
}

function pl_update_configured_directory(string $root): string
{
    return (string) (getenv('PL_INSTALL_DIRECTORY') ?: (pl_update_private_config($root)['installation_directory'] ?? $root . '/www/phpledger/storage/installation'));
}

function pl_update_directory(string $root): string
{
    $directory = pl_update_configured_directory($root);
    $public = realpath($root . '/www/phpledger/public');
    if (!is_dir($directory)) {
        throw new RuntimeException('Private installation storage is unavailable.');
    }
    $resolved = realpath($directory);
    if (!$resolved || is_link($directory) || ($public && pl_update_within($resolved, $public))) {
        throw new DomainException('Installation storage must be outside the public directory.');
    }
    return $resolved;
}

function pl_update_within(string $path, string $directory): bool
{
    $path = strtolower(str_replace('\\', '/', $path));
    $directory = rtrim(strtolower(str_replace('\\', '/', $directory)), '/');
    return $path === $directory || str_starts_with($path, $directory . '/');
}

/** Every web, API and CLI bootstrap holds this shared lock for its whole request. */
function pl_update_application_guard(string $root): void
{
    // Only the authenticated independent loader can create this in-process resource.
    // No request header, query parameter, environment variable or company session bypasses maintenance.
    if (defined('PL_UPDATE_INTERNAL_PROBE') && PL_UPDATE_INTERNAL_PROBE === true && isset($GLOBALS['pl_update_probe_barrier'])
        && is_resource($GLOBALS['pl_update_probe_barrier'])
        && realpath((string) (stream_get_meta_data($GLOBALS['pl_update_probe_barrier'])['uri'] ?? '')) === realpath(pl_update_configured_directory($root) . '/application.lock')) { return; }
    static $held = false;
    if ($held) { return; }
    $configured = pl_update_configured_directory($root);
    // Existing read-only deployments do not enable the updater until the host provisions it.
    if (!is_dir($configured)) {
        if (getenv('PL_INSTALL_DIRECTORY') || isset(pl_update_private_config($root)['installation_directory'])) { throw new RuntimeException('Configured private installation storage is unavailable.'); }
        return;
    }
    $directory = pl_update_directory($root);
    $lock = fopen($directory . '/application.lock', 'c+b');
    if (!$lock || !flock($lock, LOCK_SH | LOCK_NB) || is_file($directory . '/updates/active.json')) {
        if (is_resource($lock)) { fclose($lock); }
        if (PHP_SAPI !== 'cli') { http_response_code(503); header('Retry-After: 60'); }
        throw new RuntimeException('Installation maintenance is active. Try again after recovery completes.');
    }
    $held = true;
    register_shutdown_function(static function () use ($lock): void { flock($lock, LOCK_UN); fclose($lock); });
}

function pl_update_json(string $path): array
{
    $value = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($value)) { throw new DomainException('Invalid installation state.'); }
    return $value;
}

/** Atomic checkpoint; private state never contains raw credentials. */
function pl_update_checkpoint(string $path, array $value): void
{
    pl_update_write($path, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
}

function pl_update_write(string $path, string $bytes): void
{
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) {
        throw new RuntimeException('Private storage could not be created.');
    }
    $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));
    $file = fopen($temporary, 'xb');
    if (!$file) { throw new RuntimeException('Cannot create installation checkpoint.'); }
    try {
        chmod($temporary, 0600);
        if (fwrite($file, $bytes) !== strlen($bytes) || !fflush($file)) { throw new RuntimeException('Cannot write installation checkpoint.'); }
        if (function_exists('fsync') && !fsync($file)) { throw new RuntimeException('Cannot persist installation checkpoint.'); }
    } finally { fclose($file); }
    if (!rename($temporary, $path)) { @unlink($temporary); throw new RuntimeException('Cannot replace installation checkpoint.'); }
}

function pl_update_operator(string $directory, string $key): void
{
    $file = $directory . '/operator.key';
    $expected = is_file($file) ? trim((string) file_get_contents($file)) : '';
    if (strlen($expected) < 32 || strlen($key) > 256 || !hash_equals($expected, trim($key))) {
        throw new DomainException('The host installation operator key is required.');
    }
}

/**
 * Bound remote operator-key guessing. Only browser entry points use this wrapper:
 * host command-line recovery calls pl_update_operator directly, so remote guessing
 * cannot lock an operator out of local recovery.
 */
function pl_update_operator_attempt(string $directory, string $key, int $now): void
{
    $path = $directory . '/operator-attempts.json';
    $state = [];
    if (is_file($path)) {
        try { $state = pl_update_json($path); } catch (Throwable $unreadable) { $state = []; }
    }
    $started = (int) ($state['started'] ?? 0);
    $count = $started <= $now && $now - $started < 900 ? (int) ($state['count'] ?? 0) : 0;
    if ($count >= 10) {
        throw new DomainException('Too many installation operator key attempts. Wait 15 minutes, or continue recovery from the host command line.');
    }
    try {
        pl_update_operator($directory, $key);
    } catch (Throwable $rejected) {
        // Never replace an authentication failure with a private-storage error.
        try { pl_update_checkpoint($path, ['started' => $count === 0 ? $now : $started, 'count' => $count + 1]); }
        catch (Throwable $unwritable) { /* Bounded attempts are best effort. */ }
        throw $rejected;
    }
    if ($count !== 0 || $started !== 0) { pl_update_checkpoint($path, ['started' => $now, 'count' => 0]); }
}

function pl_update_path(string $name): string
{
    if (!preg_match('~^[A-Za-z0-9_.\-/]+$~D', $name) || strlen($name) > 230 || str_starts_with($name, '/')) {
        throw new DomainException('Unsafe package path.');
    }
    foreach (explode('/', $name) as $part) {
        if ($part === '' || in_array(strtolower($part), ['.', '..', '.git', '.env', 'storage', 'uploads', 'config.local.php'], true)
            || preg_match('/^(con|prn|aux|nul|com[0-9]|lpt[0-9])(?:\.|$)/i', $part) || str_ends_with($part, '.')) {
            throw new DomainException('Private or unsafe package path.');
        }
    }
    if (preg_match('/\.(?:key|pem|sql|log)$/i', $name)) { throw new DomainException('Private files cannot be supplied by an update.'); }
    return $name;
}

/** Reject symlink ancestors, including existing target directories before writes. */
function pl_update_target(string $root, string $relative): string
{
    $path = rtrim($root, '/\\');
    foreach (explode('/', $relative) as $part) {
        if ($part === '' || $part === '.' || $part === '..' || str_contains($part, '\\')) { throw new DomainException('Unsafe target path.'); }
        $path .= '/' . $part;
        if (is_link($path)) { throw new DomainException('Linked installation paths are unsupported.'); }
        if (file_exists($path)) {
            $resolved = realpath($path);
            if (!$resolved || !pl_update_within($resolved, $root)) { throw new DomainException('Installation path escapes its root.'); }
        }
    }
    return $path;
}

/** The key is host-pinned, never taken from the archive or signed message. */
/**
 * A version string in a form PHP's version_compare() orders correctly.
 *
 * version_compare() knows dev, alpha, beta, RC and pl, and sorts each of them below the plain
 * release. It sorts a word it does NOT know ABOVE the plain release, and "preview" is such a word:
 * version_compare('1.2.0-preview.1', '1.2.0', '>') returns true. The updater compared raw strings,
 * so an installation already on 1.2.0 with the preview channel selected would have been offered
 * 1.2.0-preview.1 as an upgrade - older code over a database whose migrations had already run.
 * "1.2.0-rc.1 > 1.2.0" and "1.2.0-beta > 1.2.0" were both correctly false, so only the word this
 * project actually uses was affected.
 *
 * Rewriting the word to one version_compare() knows fixes the ordering without changing anything
 * published: this is used only to compare, never to display, store, sign or match, so every
 * existing signed envelope keeps its version exactly as it was signed.
 */
function pl_update_version_rank(string $version): string
{
    return str_ireplace('-preview', '-rc', $version);
}

function pl_update_verify_metadata(string $envelope, string $publicKey, string $channel, string $current): array
{
    if (strlen($envelope) > 4000000 || !in_array($channel, ['stable', 'preview'], true)) { throw new DomainException('Invalid update metadata or channel.'); }
    // Without a well-formed installed version there is no downgrade protection to compare against.
    if (!preg_match('/^\d+\.\d+\.\d+(?:-(?:preview|beta|rc)(?:\.[0-9]+)?)?$/D', $current)) {
        throw new DomainException('The installed release version could not be determined. Use the documented hosting-panel upgrade procedure.');
    }
    $message = json_decode($envelope, true, 8, JSON_THROW_ON_ERROR);
    $payload = base64_decode((string) ($message['payload'] ?? ''), true);
    $signature = base64_decode((string) ($message['signature'] ?? ''), true);
    $key = openssl_pkey_get_public($publicKey);
    $details = $key ? openssl_pkey_get_details($key) : false;
    if (!$details || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] < 3072 || $payload === false || $signature === false
        || openssl_verify($payload, $signature, $key, OPENSSL_ALGO_SHA256) !== 1) {
        throw new DomainException('Release signature does not match the pinned publisher key.');
    }
    $metadata = json_decode($payload, true, 16, JSON_THROW_ON_ERROR);
    $version = $metadata['version'] ?? '';
    if (($metadata['schema'] ?? null) !== 1 || !is_string($version) || !preg_match('/^\d+\.\d+\.\d+(?:-(?:preview|beta|rc)(?:\.[0-9]+)?)?$/D', $version)
        || ($metadata['channel'] ?? '') !== $channel || (($channel === 'stable') === str_contains($version, '-'))
        || !version_compare(pl_update_version_rank($version), pl_update_version_rank($current), '>') || !is_string($metadata['min_php'] ?? null)
        || version_compare(PHP_VERSION, $metadata['min_php'], '<')) {
        throw new DomainException('Choose a newer compatible release in the selected channel.');
    }
    if (isset($metadata['expires_at']) && (!is_int($metadata['expires_at']) || $metadata['expires_at'] < time())) { throw new DomainException('Signed release metadata has expired.'); }
    if (isset($metadata['issued_at']) && (!is_int($metadata['issued_at']) || $metadata['issued_at'] > time() + 300)) { throw new DomainException('Signed release metadata is not yet valid.'); }
    if (!is_int($metadata['archive_bytes'] ?? null) || $metadata['archive_bytes'] < 1 || $metadata['archive_bytes'] > 100000000
        || !preg_match('/^[a-f0-9]{64}$/D', $metadata['archive_sha256'] ?? '') || !is_array($metadata['files'] ?? null)
        || count($metadata['files']) < 1 || count($metadata['files']) > 20000) { throw new DomainException('Invalid signed package inventory.'); }
    $files = []; $folded = []; $size = 0;
    foreach ($metadata['files'] as $entry) {
        if (!is_array($entry) || !is_string($entry['path'] ?? null)) { throw new DomainException('Invalid signed file entry.'); }
        $path = pl_update_path($entry['path']);
        if (isset($folded[strtolower($path)]) || !is_int($entry['bytes'] ?? null) || $entry['bytes'] < 0 || $entry['bytes'] > 10000000
            || !preg_match('/^[a-f0-9]{64}$/D', $entry['sha256'] ?? '')) { throw new DomainException('Duplicate or invalid signed file entry.'); }
        $folded[strtolower($path)] = true; $files[$path] = $entry; $size += $entry['bytes'];
    }
    foreach (['PACKAGE-MANIFEST.json', 'vendor/autoload.php', 'www/phpledger/includes/bootstrap.php', 'www/phpledger/public/index.php', 'www/phpledger/public/maintenance.php'] as $required) {
        if (!isset($files[$required])) { throw new DomainException('Release is missing required application files.'); }
    }
    if ($size > 100000000) { throw new DomainException('Expanded package exceeds the supported update limit.'); }
    $metadata['inventory'] = $files;
    return $metadata;
}

/** Extract individually after checking every member; ZipArchive::extractTo is never used. */
function pl_update_stage(string $archive, array $metadata, string $stage): void
{
    if (!class_exists(ZipArchive::class)) { throw new DomainException('Enable PHP ZIP for browser updates.'); }
    if (filesize($archive) !== $metadata['archive_bytes'] || !hash_equals($metadata['archive_sha256'], (string) hash_file('sha256', $archive))) {
        throw new DomainException('The uploaded archive differs from the signed release.');
    }
    $zip = new ZipArchive();
    if ($zip->open($archive, ZipArchive::RDONLY) !== true) { throw new DomainException('The release archive cannot be read.'); }
    try {
        // Releases unpack to "phpledger/"; earlier ones used "phpledger-<version>/". Every member shares one root.
        $first = $zip->numFiles > 0 ? $zip->statIndex(0) : false; $prefix = null; $found = [];
        foreach (['phpledger/', 'phpledger-' . $metadata['version'] . '/'] as $candidate) {
            if ($first && str_starts_with($first['name'], $candidate)) { $prefix = $candidate; }
        }
        if ($prefix === null) { throw new DomainException('Invalid package root.'); }
        if ($zip->numFiles !== count($metadata['inventory'])) { throw new DomainException('Archive inventory differs from the signed release.'); }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->statIndex($i);
            if (!$entry || !str_starts_with($entry['name'], $prefix)) { throw new DomainException('Invalid package root.'); }
            $name = pl_update_path(substr($entry['name'], strlen($prefix)));
            $expected = $metadata['inventory'][$name] ?? null;
            $zip->getExternalAttributesIndex($i, $system, $attributes);
            $type = ($attributes >> 16) & 0170000;
            if (!$expected || isset($found[$name]) || $entry['size'] !== $expected['bytes'] || ($type !== 0 && $type !== 0100000) || $entry['encryption_method'] !== 0) {
                throw new DomainException('Unsafe or unexpected archive member.');
            }
            $found[$name] = $i;
        }
        foreach ($found as $name => $i) {
            $data = $zip->getFromIndex($i);
            if ($data === false || !hash_equals($metadata['inventory'][$name]['sha256'], hash('sha256', $data))) { throw new DomainException('Archive member checksum mismatch.'); }
            pl_update_write(pl_update_target($stage, $name), $data);
        }
        $manifest = pl_update_json($stage . '/PACKAGE-MANIFEST.json');
        if (($manifest['version'] ?? '') !== $metadata['version']) { throw new DomainException('Package version disagrees with signed release.'); }
    } finally { $zip->close(); }
}

/** All destinations are inventoried before any live code is replaced. */
function pl_update_file_backup(string $root, string $operation, array $metadata, ?int $limit = null): ?array
{
    $installed = pl_update_json($root . '/PACKAGE-MANIFEST.json');
    $paths = ['PACKAGE-MANIFEST.json' => true];
    foreach ($installed['files'] ?? [] as $entry) { $paths[pl_update_path($entry['path'])] = true; }
    foreach ($metadata['inventory'] as $path => $_) { $paths[$path] = true; }
    $progressPath = $operation . '/backup-files-progress.json';
    $progress = is_file($progressPath) ? pl_update_json($progressPath) : ['files' => [], 'private' => []];
    $receipt = $progress['files']; $private = $progress['private']; $processed = 0;
    foreach ($paths as $path => $_) {
        if (isset($receipt[$path])) { continue; }
        if ($limit !== null && $processed >= $limit) { pl_update_checkpoint($progressPath, ['files' => $receipt, 'private' => $private]); return null; }
        $source = pl_update_target($root, $path);
        if (file_exists($source) && !is_file($source)) { throw new DomainException('An update destination is not a regular file.'); }
        $exists = is_file($source); $hash = null; $mode = null;
        if ($exists) {
            $bytes = file_get_contents($source);
            if ($bytes === false) { throw new RuntimeException('An installed file cannot be backed up.'); }
            $hash = hash('sha256', $bytes); $mode = fileperms($source) & 0777;
            pl_update_write($operation . '/backup/files/' . $path, $bytes);
        }
        $receipt[$path] = ['exists' => $exists, 'sha256' => $hash, 'mode' => $mode];
        $processed++;
    }
    // Private config and keys remain in place during updates and are included in matched recovery.
    $config = getenv('PL_INSTALL_CONFIG_PATH') ?: $root . '/www/phpledger/includes/config.local.php';
    $privatePaths = is_file($config) ? [$config] : [];
    $localConfig = is_file($config) ? require $config : [];
    $oauth = getenv('PL_OAUTH_KEY_DIRECTORY') ?: ($localConfig['oauth_key_directory'] ?? $root . '/www/phpledger/storage/oauth');
    if (is_dir($oauth)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($oauth, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isLink()) { throw new DomainException('Private key links cannot be backed up automatically.'); }
            if ($file->isFile()) { $privatePaths[] = $file->getPathname(); }
        }
    }
    foreach (['operator.key', 'publisher.pem', 'installed.json', 'secret.key'] as $name) {
        $path = pl_update_directory($root) . '/' . $name;
        if (is_file($path)) { $privatePaths[] = $path; }
    }
    foreach ($privatePaths as $index => $path) {
        if (isset($private[$index])) { continue; }
        if ($limit !== null && $processed >= $limit) { pl_update_checkpoint($progressPath, ['files' => $receipt, 'private' => $private]); return null; }
        $resolved = realpath($path);
        if (!$resolved || is_link($path) || pl_update_within($resolved, (string) realpath($root . '/www/phpledger/public'))) { throw new DomainException('Private configuration backup is unsafe.'); }
        $bytes = file_get_contents($resolved);
        if ($bytes === false) { throw new RuntimeException('Private configuration cannot be backed up.'); }
        pl_update_write($operation . '/backup/private/' . $index, $bytes);
        $private[] = ['path' => $resolved, 'backup' => $index, 'sha256' => hash('sha256', $bytes), 'mode' => fileperms($resolved) & 0777];
        $processed++;
    }
    pl_update_checkpoint($progressPath, ['files' => $receipt, 'private' => $private]);
    return ['files' => $receipt, 'private' => $private];
}

function pl_update_file_mode(string $path, int $mode): void
{
    if (!chmod($path, $mode)) { throw new RuntimeException('Installation file permissions could not be restored.'); }
    clearstatcache(true, $path);
    $actual = fileperms($path);
    if ($actual === false || ($actual & 0777) !== $mode) { throw new RuntimeException('Installation file permission verification failed.'); }
}

/** Verify every backup before restoring; subsequent batches safely replay after interruption. */
function pl_update_restore_files(string $root, string $operation, array $backup, int $limit = 100): bool
{
    $progressPath = $operation . '/restore-files-progress.json';
    $progress = is_file($progressPath) ? pl_update_json($progressPath) : ['phase' => 'verify', 'index' => 0];
    $tasks = [];
    foreach ($backup['files'] as $path => $entry) {
        $tasks[] = ['target' => pl_update_target($root, $path), 'source' => $operation . '/backup/files/' . $path] + $entry;
    }
    foreach ($backup['private'] as $entry) {
        if (is_link($entry['path'])) { throw new RuntimeException('Private restoration path changed.'); }
        $tasks[] = ['target' => $entry['path'], 'source' => $operation . '/backup/private/' . $entry['backup'], 'exists' => true] + $entry;
    }
    while ($limit > 0 && $progress['phase'] !== 'complete') {
        if ($progress['index'] >= count($tasks)) {
            $progress['index'] = 0; $progress['phase'] = $progress['phase'] === 'verify' ? 'restore' : 'complete'; continue;
        }
        $entry = $tasks[$progress['index']]; $target = $entry['target'];
        if ($entry['exists'] && !hash_equals($entry['sha256'], (string) hash_file('sha256', $entry['source']))) { throw new RuntimeException('A matched backup is corrupt; maintenance remains active.'); }
        if ($progress['phase'] === 'restore') {
            if ($entry['exists']) {
                pl_update_write($target, (string) file_get_contents($entry['source'])); pl_update_file_mode($target, $entry['mode']);
                if (!hash_equals($entry['sha256'], (string) hash_file('sha256', $target))) { throw new RuntimeException('File restoration verification failed.'); }
            } elseif (is_file($target) && !unlink($target)) { throw new RuntimeException('A new update file could not be removed.'); }
            if (function_exists('opcache_invalidate')) { opcache_invalidate($target, true); }
        }
        $progress['index']++; $limit--;
    }
    pl_update_checkpoint($progressPath, $progress);
    return $progress['phase'] === 'complete';
}

function pl_update_apply_files(string $root, string $operation, array $release, array $backup, int $limit = 100): bool
{
    $progressPath = $operation . '/apply-files-progress.json';
    $progress = is_file($progressPath) ? pl_update_json($progressPath) : ['index' => 0];
    $paths = array_keys($release['inventory']);
    $paths = array_merge($paths, array_keys(array_diff_key($backup['files'], $release['inventory'])));
    while ($progress['index'] < count($paths) && $limit-- > 0) {
        $path = $paths[$progress['index']]; $target = pl_update_target($root, $path);
        if ($path === 'www/phpledger/public/maintenance.php') { $progress['index']++; continue; }
        if (isset($release['inventory'][$path])) {
            $entry = $release['inventory'][$path]; $bytes = file_get_contents($operation . '/stage/' . $path);
            if ($bytes === false || !hash_equals($entry['sha256'], hash('sha256', $bytes))) { throw new RuntimeException('Staged code changed after verification.'); }
            $parents = []; $parent = dirname($target);
            while (!is_dir($parent)) { $parents[] = $parent; $parent = dirname($parent); }
            foreach (array_reverse($parents) as $parent) {
                if (!mkdir($parent, 0755) && !is_dir($parent)) { throw new RuntimeException('Application directory cannot be created.'); }
                pl_update_file_mode($parent, 0755);
            }
            pl_update_write($target, $bytes); pl_update_file_mode($target, 0644);
            if (function_exists('opcache_invalidate')) { opcache_invalidate($target, true); }
        } elseif (is_file($target) && !unlink($target)) { throw new RuntimeException('Obsolete release file cannot be removed.'); }
        $progress['index']++;
    }
    pl_update_checkpoint($progressPath, $progress);
    return $progress['index'] >= count($paths);
}

function pl_update_begin(string $root, string $archive, string $envelope, string $channel): array
{
    $root = realpath($root) ?: throw new DomainException('Installation root is unavailable.');
    $directory = pl_update_directory($root);
    if (getenv('PL_ENV') === 'demo') { throw new DomainException('Public demos cannot install updates.'); }
    $installed = pl_update_json($root . '/PACKAGE-MANIFEST.json');
    $keyPath = getenv('PL_UPDATE_PUBLIC_KEY') ?: $directory . '/publisher.pem';
    if (!is_file($keyPath)) { throw new DomainException('Pin the publisher public key in private host configuration first.'); }
    $metadata = pl_update_verify_metadata($envelope, (string) file_get_contents($keyPath), $channel, (string) ($installed['version'] ?? ''));
    $loader = 'www/phpledger/public/maintenance.php';
    if (!is_file($root . '/' . $loader) || !hash_equals((string) hash_file('sha256', $root . '/' . $loader), $metadata['inventory'][$loader]['sha256'])) {
        throw new DomainException('This release changes the independent recovery loader. Use the documented hosting-panel upgrade procedure.');
    }
    $control = fopen($directory . '/update.lock', 'c+b');
    if (!$control || !flock($control, LOCK_EX | LOCK_NB)) { throw new DomainException('Another installation operation is running.'); }
    try {
        if (is_file($directory . '/updates/active.json')) { throw new DomainException('Recover the current update before starting another.'); }
        $id = bin2hex(random_bytes(16)); $operation = $directory . '/updates/' . $id;
        if (!mkdir($operation . '/stage', 0700, true)) { throw new RuntimeException('Cannot create private update staging.'); }
        $space = disk_free_space($directory);
        if ($space === false || $space < $metadata['archive_bytes'] * 6 + 100000000) { throw new DomainException('Insufficient private disk space for staging and matched backups.'); }
        pl_update_stage($archive, $metadata, $operation . '/stage');
        // Stable recovery dependencies are copied before replacing any application file.
        // update_channel_functions.php travels with update_web_functions.php (1.2 M12): the
        // maintenance page reads pl_update_mode() from it, so it must be present wherever
        // that page's copy of update_web_functions.php runs.
        foreach (['update_functions.php', 'update_database_functions.php', 'update_web_functions.php', 'update_probe_functions.php', 'database_platform_functions.php', 'update_channel_functions.php'] as $name) {
            pl_update_write($operation . '/runtime/' . $name, (string) file_get_contents(__DIR__ . '/' . $name));
        }
        pl_update_write($operation . '/runtime/meekrodb.php', (string) file_get_contents($root . '/vendor/sergeytsalkov/meekrodb/db.class.php'));
        pl_update_checkpoint($operation . '/release.json', $metadata);
        $state = ['id' => $id, 'root' => $root, 'phase' => 'backup', 'version' => $metadata['version'], 'started_at' => gmdate('c'), 'inflight' => false, 'error' => null];
        pl_update_checkpoint($operation . '/state.json', $state);
        pl_update_checkpoint($directory . '/updates/active.json', ['id' => $id]);
        return $state;
    } finally { flock($control, LOCK_UN); fclose($control); }
}

/** One bounded operation stage per authenticated POST; uncertain mutation rolls back. */
function pl_update_step(string $root, array $callbacks = []): array
{
    $directory = pl_update_directory($root); $active = $directory . '/updates/active.json';
    if (!is_file($active)) { throw new DomainException('There is no active update.'); }
    $id = pl_update_json($active)['id'] ?? '';
    if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)) { throw new DomainException('Invalid update identifier.'); }
    $operation = $directory . '/updates/' . $id;
    $control = fopen($directory . '/update.lock', 'c+b');
    if (!$control || !flock($control, LOCK_EX | LOCK_NB)) { throw new DomainException('Another installation operation is running.'); }
    $barrier = fopen($directory . '/application.lock', 'c+b');
    if (!$barrier || !flock($barrier, LOCK_EX | LOCK_NB)) { fclose($control); throw new DomainException('Waiting for active application requests to finish. Retry this step.'); }
    try {
        $state = pl_update_json($operation . '/state.json');
        $release = pl_update_json($operation . '/release.json');
        if ($state['inflight'] && !in_array($state['phase'], ['backup', 'recovering'], true)) {
            $state['phase'] = 'recovering'; $state['error'] = 'interrupted_update';
        }
        try {
            $state['inflight'] = true; pl_update_checkpoint($operation . '/state.json', $state);
            switch ($state['phase']) {
                case 'backup':
                    ($callbacks['connect'] ?? 'pl_update_database_connect')($root);
                    if (!is_file($operation . '/backup.json')) {
                        $fileBackup = pl_update_file_backup($root, $operation, $release, 100);
                        if ($fileBackup === null) { break; }
                        pl_update_checkpoint($operation . '/backup.json', $fileBackup);
                    }
                    $backup = pl_update_json($operation . '/backup.json');
                    $database = ($callbacks['backup_database'] ?? 'pl_update_database_backup')($operation . '/backup/database');
                    if ($database !== null) {
                        $backup['database'] = $database; pl_update_checkpoint($operation . '/backup.json', $backup);
                        $state['phase'] = 'apply';
                    }
                    break;
                case 'apply':
                    $backup = pl_update_json($operation . '/backup.json');
                    if (pl_update_apply_files($root, $operation, $release, $backup)) { $state['phase'] = 'migrate'; }
                    break;
                case 'migrate':
                    ($callbacks['connect'] ?? 'pl_update_database_connect')($root);
                    $complete = ($callbacks['migrate'] ?? 'pl_update_database_migrate')($root);
                    if ($complete) { $state['phase'] = 'verify'; }
                    break;
                case 'verify':
                    ($callbacks['connect'] ?? 'pl_update_database_connect')($root);
                    if (!isset($callbacks['health']) && !pl_update_database_preserved($operation . '/backup/database')) { break; }
                    ($callbacks['health'] ?? 'pl_update_database_health')($root, $release);
                    $backup = pl_update_json($operation . '/backup.json');
                    if (!isset($callbacks['health'])) {
                        $database = pl_update_json($operation . '/backup/database/manifest.json');
                        if (!hash_equals($database['financial'], pl_update_database_financial_digest())) { throw new RuntimeException('Existing financial history changed during the update.'); }
                    }
                    foreach ($release['inventory'] as $path => $entry) {
                        if (!hash_equals($entry['sha256'], (string) hash_file('sha256', pl_update_target($root, $path)))) { throw new RuntimeException('Installed code checksum verification failed.'); }
                    }
                    $state['phase'] = 'runtime';
                    break;
                case 'runtime':
                    // The independent loader runs a fresh bootstrap before loading recovery helpers.
                    $state['inflight'] = false;
                    break;
                case 'recovering':
                    if (pl_update_recover($root, $operation, $callbacks)) { $state['phase'] = 'restored'; }
                    break;
                case 'complete': case 'restored': case 'aborted': break;
                default: throw new DomainException('Unknown update phase.');
            }
            $state['inflight'] = false;
        } catch (Throwable $error) {
            // Never expose exception text: database errors can contain confidential values.
            $state['error'] = 'operation_failed';
            if ($state['phase'] === 'backup') { $state['phase'] = 'aborted'; $state['inflight'] = false; }
            else {
                $state['phase'] = 'recovering'; pl_update_checkpoint($operation . '/state.json', $state);
                try { if (pl_update_recover($root, $operation, $callbacks)) { $state['phase'] = 'restored'; } $state['inflight'] = false; }
                catch (Throwable $recoveryError) { $state['error'] = 'recovery_pending'; }
            }
        }
        $state['updated_at'] = gmdate('c'); pl_update_checkpoint($operation . '/state.json', $state);
        if (in_array($state['phase'], ['complete', 'restored', 'aborted'], true)) {
            pl_update_checkpoint($directory . '/updates/last.json', $state);
            if (!unlink($active)) { throw new RuntimeException('Maintenance could not be released.'); }
        }
        return $state;
    } finally { flock($barrier, LOCK_UN); fclose($barrier); flock($control, LOCK_UN); fclose($control); }
}

function pl_update_recover(string $root, string $operation, array $callbacks): bool
{
    $backup = pl_update_json($operation . '/backup.json');
    if (!is_file($operation . '/files-restored.json')) {
        if (!pl_update_restore_files($root, $operation, $backup)) { return false; }
        pl_update_checkpoint($operation . '/files-restored.json', ['completed' => true]);
    }
    ($callbacks['connect'] ?? 'pl_update_database_connect')($root);
    $complete = ($callbacks['restore_database'] ?? 'pl_update_database_restore')($operation . '/backup/database', $backup['database']);
    return $complete;
}
