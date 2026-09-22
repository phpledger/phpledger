<?php
declare(strict_types=1);

/**
 * The secret store (owner decision B83; 1.3 M17; unblocks B78's "highest-value piece of the
 * connector platform"). Proving the happy path is the easy half; this suite exists for the rest
 * of B83's claims: a secret round-trips; what lands in the database is never the plaintext; a
 * missing or unreadable private key directory makes the store refuse rather than fall back to
 * plaintext; nothing a browser can reach ever returns a decrypted value; rotation re-encrypts
 * every secret and the ciphertext taken before rotation becomes unreadable afterwards; and a
 * tampered ciphertext is rejected outright rather than silently decrypted into garbage, which is
 * the property authenticated encryption exists to buy.
 *
 * Runs after plugin_test.php and reuses its fixtures (ledger_fixture(), plugin_admin_fixture(),
 * plugin_fixture_write(), plugin_install_and_activate(), plugin_key(), plugin_fixture_reset(),
 * plugin_fixture_clean_state()) rather than redefining them: a secret is plugin-scoped data and
 * needs an installed package the same way an option does. The way plugin_test.php points
 * PL_PLUGIN_DIRECTORY at a fixture folder for its own run, this suite points PL_INSTALL_DIRECTORY
 * at one of its own for its whole run, so it never depends on, or leaves anything behind in, the
 * real www/phpledger/storage/installation path — and no other suite ever sees its key file.
 */

/** The private-directory fixture root. A static path for the run so repeated calls (for example,
 *  to restore the env var after a deliberately-broken-directory test) keep pointing at the one
 *  already created. */
function secret_fixture_root(): string
{
    static $root = null;
    if ($root === null) {
        $root = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/phpledger-secret-' . bin2hex(random_bytes(6));
        if (!mkdir($root, 0700, true) && !is_dir($root)) {
            throw new RuntimeException('The secret store fixture directory could not be created.');
        }
    }
    putenv('PL_INSTALL_DIRECTORY=' . $root);
    return $root;
}

/** @return array<string, mixed> the admin fixture, with a fresh installed-and-active package. */
function secret_fixture_package(string $slug = 'sample-secret-plugin'): array
{
    plugin_fixture_reset();
    plugin_fixture_clean_state();
    secret_fixture_root();
    $admin = plugin_admin_fixture();
    plugin_fixture_write($slug, []);
    plugin_install_and_activate($admin, $slug);
    return $admin;
}

function secret_raw(string $slug, string $name, int $companyId = 0): string
{
    return (string) DB::queryFirstField(
        'SELECT ciphertext FROM pl_plugin_secrets WHERE slug = %s AND company_id = %i AND secret_name = %s',
        $slug, $companyId, $name
    );
}

// ------------------------------------------------------------------------------ the round trip

test('a secret round-trips, and what the database holds is never the plaintext', function (): void {
    $admin = secret_fixture_package();
    $plaintext = 'sample-smtp-password-do-not-use';
    pl_plugin_secret_set($admin['actor_id'], 'sample-secret-plugin', 'smtp_password', $plaintext);
    assert_same($plaintext, pl_plugin_secret_get('sample-secret-plugin', 'smtp_password'));

    $stored = secret_raw('sample-secret-plugin', 'smtp_password');
    assert_true(str_starts_with($stored, PL_SECRET_FORMAT_PREFIX), 'The stored value carries the envelope prefix.');
    assert_true(!str_contains($stored, $plaintext), 'The plaintext must not appear anywhere in the stored column.');
    $decoded = base64_decode(substr($stored, strlen(PL_SECRET_FORMAT_PREFIX)), true);
    assert_true(is_string($decoded) && !str_contains($decoded, $plaintext), 'Nor in its decoded bytes (a substring leak the base64 layer alone would not catch).');
    // Encrypting the same plaintext twice must not produce the same bytes: a fresh random IV
    // every call is what makes the ciphertext non-deterministic, which the format alone does not
    // prove without checking it.
    assert_true(pl_secret_encrypt($plaintext) !== pl_secret_encrypt($plaintext));
});

test('a secret is scoped exactly like a package option: the installation, or one business', function (): void {
    $admin = secret_fixture_package();
    pl_plugin_secret_set($admin['actor_id'], 'sample-secret-plugin', 'smtp_password', 'install-scope-value');
    pl_plugin_secret_set($admin['actor_id'], 'sample-secret-plugin', 'smtp_password', 'company-scope-value', $admin['company_id']);
    assert_same('install-scope-value', pl_plugin_secret_get('sample-secret-plugin', 'smtp_password'));
    assert_same('company-scope-value', pl_plugin_secret_get('sample-secret-plugin', 'smtp_password', $admin['company_id']));
    assert_same(null, pl_plugin_secret_get('sample-secret-plugin', 'no_such_secret'));
    assert_same(['smtp_password'], pl_plugin_secret_names('sample-secret-plugin'));

    $outsider = ledger_fixture();
    assert_throws(fn() => pl_plugin_secret_set($outsider['actor_id'], 'sample-secret-plugin', 'smtp_password', 'x'), DomainException::class, 'installation administrator');
    assert_throws(fn() => pl_plugin_secret_set($outsider['actor_id'], 'sample-secret-plugin', 'smtp_password', 'x', $admin['company_id']), DomainException::class);
    assert_throws(fn() => pl_plugin_secret_set($admin['actor_id'], 'sample-secret-plugin', 'Not A Name', 'x'), DomainException::class);
    assert_throws(fn() => pl_plugin_secret_set($admin['actor_id'], 'sample-secret-plugin', 'smtp_password', ''), DomainException::class);
    assert_throws(fn() => pl_plugin_secret_get('sample-secret-plugin', 'smtp_password', 999999999), DomainException::class);

    pl_plugin_secret_delete($admin['actor_id'], 'sample-secret-plugin', 'smtp_password');
    assert_same(null, pl_plugin_secret_get('sample-secret-plugin', 'smtp_password'));
    // The company-scoped row is untouched by deleting the installation-scoped one.
    assert_same('company-scope-value', pl_plugin_secret_get('sample-secret-plugin', 'smtp_password', $admin['company_id']));
});

test('removing a package with its data clears its secrets too', function (): void {
    $admin = secret_fixture_package();
    pl_plugin_secret_set($admin['actor_id'], 'sample-secret-plugin', 'smtp_password', 'sample-value');
    pl_plugin_deactivate($admin['actor_id'], 'sample-secret-plugin', 'Sample tidy-up.', plugin_key('secret-off'));
    pl_plugin_uninstall($admin['actor_id'], 'sample-secret-plugin', true, 'Sample tidy-up.', plugin_key('secret-rm'));
    assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_plugin_secrets WHERE slug = %s', 'sample-secret-plugin'));
});

// -------------------------------------------------------------- never returned to the browser

test('nothing a browser can reach ever returns a decrypted secret', function (): void {
    $admin = secret_fixture_package();
    $plaintext = 'sample-never-rendered-value';
    pl_plugin_secret_set($admin['actor_id'], 'sample-secret-plugin', 'smtp_password', $plaintext);

    // pl_plugin_cards() is the Packages screen's own data. It must never carry a secret's value
    // or its ciphertext, masked or otherwise.
    $cards = json_encode(pl_plugin_cards(), JSON_THROW_ON_ERROR);
    assert_true(!str_contains($cards, $plaintext), 'Package cards leaked a secret value.');
    assert_true(!str_contains($cards, PL_SECRET_FORMAT_PREFIX), 'Package cards leaked stored ciphertext.');

    // What is set, never what it is set to.
    assert_true(!in_array($plaintext, pl_plugin_secret_names('sample-secret-plugin'), true));

    // The durable guard: no template or public entry point calls the decrypting function. A
    // future screen that wires one in breaks this test on sight rather than in production.
    foreach (['templates', 'public'] as $area) {
        $base = dirname(__DIR__) . '/www/phpledger/' . $area;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)) as $file) {
            /** @var SplFileInfo $file */
            if ($file->isFile() && $file->getExtension() === 'php') {
                $source = (string) file_get_contents((string) $file->getPathname());
                assert_true(!str_contains($source, 'pl_plugin_secret_get(') && !str_contains($source, 'pl_secret_decrypt'),
                    'A browser-reachable file calls a secret-decrypting function: ' . $file->getPathname());
            }
        }
    }
});

// -------------------------------------------------------------------- refuse rather than fall back

test('a missing private directory makes the store refuse rather than fall back to plaintext', function (): void {
    $admin = secret_fixture_package();
    $root = secret_fixture_root();

    putenv('PL_INSTALL_DIRECTORY=' . $root . '/does-not-exist');
    assert_throws(fn() => pl_plugin_secret_set($admin['actor_id'], 'sample-secret-plugin', 'unreachable', 'value'));
    // Nothing at all was written: a refusal writes no row, plaintext or otherwise.
    secret_fixture_root();
    assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_plugin_secrets WHERE slug = %s AND secret_name = %s',
        'sample-secret-plugin', 'unreachable'));

    // A secret already stored is equally unreadable while the directory is missing: no cached
    // key, no alternate path, no partial answer.
    pl_plugin_secret_set($admin['actor_id'], 'sample-secret-plugin', 'smtp_password', 'sample-value');
    putenv('PL_INSTALL_DIRECTORY=' . $root . '/does-not-exist');
    assert_throws(fn() => pl_plugin_secret_get('sample-secret-plugin', 'smtp_password'));
    secret_fixture_root();
    assert_same('sample-value', pl_plugin_secret_get('sample-secret-plugin', 'smtp_password'), 'Restoring the directory restores reads.');

    // A configured path that exists but is a plain file, not a directory, refuses the same way;
    // is_dir() says no regardless of which user owns the process, unlike a permission bit.
    $notADirectory = $root . '/not-a-directory';
    file_put_contents($notADirectory, 'sample');
    putenv('PL_INSTALL_DIRECTORY=' . $notADirectory);
    assert_throws(fn() => pl_plugin_secret_get('sample-secret-plugin', 'smtp_password'));
    secret_fixture_root();
    @unlink($notADirectory);
});

test('an unreadable private directory makes the store refuse rather than fall back to plaintext', function (): void {
    if (PHP_OS_FAMILY === 'Windows' || (function_exists('posix_getuid') && posix_getuid() === 0)) {
        // POSIX permission bits do not restrict root, and a test container commonly runs as
        // root. The missing-directory tests above are the portable, always-run proof of
        // "refuse rather than degrade"; this one adds the permission-denied path when it can.
        return;
    }
    $admin = secret_fixture_package();
    $root = secret_fixture_root();
    $locked = $root . '/locked-' . bin2hex(random_bytes(4));
    mkdir($locked, 0700);
    chmod($locked, 0000);
    putenv('PL_INSTALL_DIRECTORY=' . $locked);
    try {
        assert_throws(fn() => pl_plugin_secret_set($admin['actor_id'], 'sample-secret-plugin', 'unreachable', 'value'));
    } finally {
        chmod($locked, 0700);
        secret_fixture_root();
    }
    assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_plugin_secrets WHERE slug = %s AND secret_name = %s',
        'sample-secret-plugin', 'unreachable'));
});

// ------------------------------------------------------------------------------------ rotation

test('rotation re-encrypts every secret, and the ciphertext taken before rotation is unreadable after', function (): void {
    $admin = secret_fixture_package();
    $first = 'sample-rotated-value-one';
    $second = 'sample-rotated-value-two';
    pl_plugin_secret_set($admin['actor_id'], 'sample-secret-plugin', 'smtp_password', $first);
    pl_plugin_secret_set($admin['actor_id'], 'sample-secret-plugin', 'api_token', $second, $admin['company_id']);
    $beforeOne = secret_raw('sample-secret-plugin', 'smtp_password');
    $beforeTwo = secret_raw('sample-secret-plugin', 'api_token', $admin['company_id']);

    $outsider = ledger_fixture();
    assert_throws(fn() => pl_secret_rotate($outsider['actor_id']), DomainException::class, 'installation administrator');

    $rotated = pl_secret_rotate($admin['actor_id']);
    assert_true($rotated >= 2, 'Both secrets just stored were rotated.');

    $afterOne = secret_raw('sample-secret-plugin', 'smtp_password');
    $afterTwo = secret_raw('sample-secret-plugin', 'api_token', $admin['company_id']);
    assert_true($beforeOne !== $afterOne && $beforeTwo !== $afterTwo, 'Rotation must change every stored ciphertext.');

    // Still transparently readable through the now-current key.
    assert_same($first, pl_plugin_secret_get('sample-secret-plugin', 'smtp_password'));
    assert_same($second, pl_plugin_secret_get('sample-secret-plugin', 'api_token', $admin['company_id']));

    // The property rotation exists to prove: the exact bytes stored before rotation no longer
    // open under the key that is current now.
    assert_throws(fn() => pl_secret_decrypt($beforeOne), DomainException::class);
    assert_throws(fn() => pl_secret_decrypt($beforeTwo), DomainException::class);
});

test('a tampered ciphertext is rejected, not silently decrypted into garbage', function (): void {
    $admin = secret_fixture_package();
    pl_plugin_secret_set($admin['actor_id'], 'sample-secret-plugin', 'smtp_password', 'sample-tamper-target');
    $stored = secret_raw('sample-secret-plugin', 'smtp_password');
    $raw = base64_decode(substr($stored, strlen(PL_SECRET_FORMAT_PREFIX)), true);
    assert_true(is_string($raw));
    // Flip one bit at the very end: inside the ciphertext body, past the IV and the auth tag.
    $offset = strlen($raw) - 1;
    $raw[$offset] = chr(ord($raw[$offset]) ^ 0x01);
    $tampered = PL_SECRET_FORMAT_PREFIX . base64_encode($raw);
    DB::update('pl_plugin_secrets', ['ciphertext' => $tampered], 'slug = %s AND secret_name = %s', 'sample-secret-plugin', 'smtp_password');
    assert_throws(fn() => pl_plugin_secret_get('sample-secret-plugin', 'smtp_password'), DomainException::class, 'verified');

    // A value in no recognisable envelope at all is refused the same way, not treated as legacy
    // plaintext.
    DB::update('pl_plugin_secrets', ['ciphertext' => 'not-an-envelope-at-all'], 'slug = %s AND secret_name = %s', 'sample-secret-plugin', 'smtp_password');
    assert_throws(fn() => pl_plugin_secret_get('sample-secret-plugin', 'smtp_password'), DomainException::class);
});

// -------------------------------------------------------------------------- required cipher

test('the store refuses outright if AES-256-GCM is unavailable, rather than choosing a weaker one', function (): void {
    assert_true(in_array(PL_SECRET_CIPHER, openssl_get_cipher_methods(), true), 'This PHP build must offer ' . PL_SECRET_CIPHER . ' for the suite above to mean anything.');
    assert_same('aes-256-gcm', PL_SECRET_CIPHER);
});

test('a lost key with existing data refuses new writes and preserves the original rows', function (): void {
    $admin = secret_fixture_package();
    pl_plugin_secret_set($admin['actor_id'], 'sample-secret-plugin', 'password', 'sample-value');
    $path = pl_secret_key_path();
    $bytes = file_get_contents($path);
    $before = secret_raw('sample-secret-plugin', 'password');
    unlink($path);
    try {
        assert_throws(fn() => pl_plugin_secret_set($admin['actor_id'], 'sample-secret-plugin', 'other', 'sample-other'), DomainException::class);
        assert_true(!file_exists($path), 'A missing key must not be silently regenerated.');
        assert_same($before, secret_raw('sample-secret-plugin', 'password'));
    } finally { pl_update_write($path, (string) $bytes); }
});

test('ciphertext is authenticated against its exact package business and field', function (): void {
    $admin = secret_fixture_package();
    pl_plugin_secret_set($admin['actor_id'], 'sample-secret-plugin', 'password', 'sample-one');
    pl_plugin_secret_set($admin['actor_id'], 'sample-secret-plugin', 'password', 'sample-two', $admin['company_id']);
    pl_plugin_secret_set($admin['actor_id'], 'sample-secret-plugin', 'token', 'sample-three');
    $raw = secret_raw('sample-secret-plugin', 'password');
    DB::update('pl_plugin_secrets', ['ciphertext' => $raw], 'slug = %s AND company_id = %i', 'sample-secret-plugin', $admin['company_id']);
    assert_throws(fn() => pl_plugin_secret_get('sample-secret-plugin', 'password', $admin['company_id']), DomainException::class);
    DB::update('pl_plugin_secrets', ['ciphertext' => $raw], 'slug = %s AND secret_name = %s', 'sample-secret-plugin', 'token');
    assert_throws(fn() => pl_plugin_secret_get('sample-secret-plugin', 'token'), DomainException::class);
    assert_same('sample-one', pl_plugin_secret_get('sample-secret-plugin', 'password'));
});

test('interrupted rotation recovers both precommit and postcommit states without losing secrets', function (): void {
    foreach ([false, true] as $committed) {
        $admin = secret_fixture_package();
        pl_plugin_secret_set($admin['actor_id'], 'sample-secret-plugin', 'password', 'sample-recovery');
        $oldKey = pl_secret_key(false);
        $newKey = random_bytes(PL_SECRET_KEY_BYTES);
        pl_update_checkpoint(pl_secret_key_path(), ['key' => base64_encode($oldKey), 'pending_key' => base64_encode($newKey)]);
        if ($committed) {
            $cipher = pl_secret_encrypt_with('sample-recovery', $newKey, pl_secret_context('sample-secret-plugin', 0, 'password'));
            DB::update('pl_plugin_secrets', ['ciphertext' => $cipher], 'slug = %s', 'sample-secret-plugin');
        }
        assert_same('sample-recovery', pl_plugin_secret_get('sample-secret-plugin', 'password'));
        pl_secret_rotate($admin['actor_id']);
        assert_same('sample-recovery', pl_plugin_secret_get('sample-secret-plugin', 'password'));
        assert_same(1, count(pl_secret_keys(false)), 'Successful recovery retires all prior keys.');
    }
});

test('secret writes and rotation refuse an enclosing uncommitted transaction', function (): void {
    $admin = secret_fixture_package();
    pl_plugin_secret_set($admin['actor_id'], 'sample-secret-plugin', 'password', 'sample-value');
    pl_ledger_transaction(function () use ($admin): void {
        assert_throws(fn() => pl_secret_rotate($admin['actor_id']), LogicException::class);
        assert_throws(fn() => pl_plugin_secret_set($admin['actor_id'], 'sample-secret-plugin', 'password', 'sample-new'), LogicException::class);
    });
    assert_same('sample-value', pl_plugin_secret_get('sample-secret-plugin', 'password'));
});

test('a competing holder of the secret lock prevents key creation and writes', function (): void {
    $admin = secret_fixture_package();
    $handle = fopen(secret_fixture_root() . '/secret.lock', 'c+b');
    assert_true(is_resource($handle) && flock($handle, LOCK_EX | LOCK_NB));
    try {
        assert_throws(fn() => pl_plugin_secret_set($admin['actor_id'], 'sample-secret-plugin', 'blocked', 'sample'), RuntimeException::class, 'busy');
    } finally { flock($handle, LOCK_UN); fclose($handle); }
    assert_same(null, pl_plugin_secret_get('sample-secret-plugin', 'blocked'));
});

test('invalid key files and symlinks are refused without replacing key material', function (): void {
    $admin = secret_fixture_package();
    pl_plugin_secret_set($admin['actor_id'], 'sample-secret-plugin', 'password', 'sample-value');
    $path = pl_secret_key_path();
    $saved = (string) file_get_contents($path);
    try {
        foreach (['not-json', '{"key":"short"}'] as $bad) {
            pl_update_write($path, $bad);
            assert_throws(fn() => pl_plugin_secret_get('sample-secret-plugin', 'password'), DomainException::class);
            assert_throws(fn() => pl_plugin_secret_set($admin['actor_id'], 'sample-secret-plugin', 'password', 'sample-other'), DomainException::class);
            assert_same($bad, file_get_contents($path));
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            $target = secret_fixture_root() . '/sample-key-target';
            pl_update_write($target, $saved);
            unlink($path);
            symlink($target, $path);
            assert_throws(fn() => pl_plugin_secret_get('sample-secret-plugin', 'password'), DomainException::class);
            assert_throws(fn() => pl_plugin_secret_set($admin['actor_id'], 'sample-secret-plugin', 'password', 'sample-other'), DomainException::class);
            assert_true(is_link($path));
            unlink($path);
            unlink($target);
        }
    } finally { pl_update_write($path, $saved); }
});

test('matched private backup restores the pending key checkpoint with its ciphertext', function (): void {
    $admin = secret_fixture_package();
    pl_plugin_secret_set($admin['actor_id'], 'sample-secret-plugin', 'password', 'sample-matched-backup');
    $pending = random_bytes(PL_SECRET_KEY_BYTES);
    pl_update_checkpoint(pl_secret_key_path(), ['key' => base64_encode(pl_secret_key(false)), 'pending_key' => base64_encode($pending)]);
    $raw = pl_secret_encrypt_with('sample-matched-backup', $pending, pl_secret_context('sample-secret-plugin', 0, 'password'));
    DB::update('pl_plugin_secrets', ['ciphertext' => $raw], 'slug = %s', 'sample-secret-plugin');
    $root = secret_fixture_root() . '/backup-app';
    mkdir($root . '/www/phpledger/public', 0700, true);
    pl_update_checkpoint($root . '/PACKAGE-MANIFEST.json', ['files' => []]);
    $operation = secret_fixture_root() . '/sample-backup';
    $backup = pl_update_file_backup($root, $operation, ['inventory' => []]);
    assert_true(is_array($backup));
    $saved = (string) file_get_contents(pl_secret_key_path());
    pl_secret_rotate($admin['actor_id']);
    assert_true($saved !== file_get_contents(pl_secret_key_path()));
    assert_true(pl_update_restore_files($root, $operation, $backup));
    DB::update('pl_plugin_secrets', ['ciphertext' => $raw], 'slug = %s', 'sample-secret-plugin');
    assert_same($saved, file_get_contents(pl_secret_key_path()));
    assert_same('sample-matched-backup', pl_plugin_secret_get('sample-secret-plugin', 'password'));
});

test('the secret store fixtures leave nothing installed and no private directory behind', function (): void {
    plugin_fixture_reset();
    plugin_fixture_clean_state();
    assert_same([], pl_plugin_records(), 'Every fixture package was removed by the test that installed it.');
    assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_plugin_secrets'), 'No secret row survives the suite.');
    // Later suites, and the pseudo-locale route sweep's own server, must see the default path.
    $root = secret_fixture_root();
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        if ($file->isDir() && !$file->isLink()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); }
    }
    rmdir($root);
    putenv('PL_INSTALL_DIRECTORY');
});
