<?php
declare(strict_types=1);

/** B83: database-only disclosure does not reveal plugin credentials. The installation key
 * stays in the existing private directory. Installed PHP plugins remain trusted code, not a
 * security sandbox. No core browser/API adapter returns decrypted credentials.
 * All store operations serialize against rotation. A durable pending key preserves recovery
 * on either side of a database commit; successful rotation removes the previous key. */

/** The authenticated cipher B83 specifies. AES-256-GCM needs OpenSSL >= 1.0.1; PHP >= 8.2 (this
 *  project's floor) always links against a newer one, so this is not expected to be unavailable on
 *  a supported PHP version — but it is checked, never assumed, because "refuse rather than
 *  degrade" has to hold even here. */
const PL_SECRET_CIPHER = 'aes-256-gcm';
const PL_SECRET_KEY_BYTES = 32;
const PL_SECRET_IV_BYTES = 12;
const PL_SECRET_TAG_BYTES = 16;
/** Bumped only if the envelope format ever changes; lets a future version recognise an older one. */
const PL_SECRET_FORMAT_PREFIX = 'plsecret:v1:';

function pl_secret_require_cipher(): void
{
    if (!in_array(PL_SECRET_CIPHER, openssl_get_cipher_methods(), true)) {
        throw new RuntimeException('This PHP build\'s OpenSSL extension does not support ' . PL_SECRET_CIPHER
            . '. The secret store refuses to operate rather than use a weaker construction.');
    }
}

/** Where the key lives: the private installation directory, resolved and validated exactly as the
 *  updater resolves it. Throws (refuses) when that directory is missing, a symlink, or inside the
 *  public document root — pl_update_directory() already does all three checks. */
function pl_secret_key_path(): string
{
    return pl_update_directory(PL_ROOT) . '/secret.key';
}

/** Serialize key creation, reads, writes and rotation across PHP processes. */
function pl_secret_locked(callable $work): mixed
{
    static $depth = 0;
    if ($depth > 0) { return $work(); }
    $path = pl_update_directory(PL_ROOT) . '/secret.lock';
    if (is_link($path)) { throw new DomainException('The secret store lock is invalid.'); }
    $lock = @fopen($path, 'c+b');
    if (!$lock) { throw new RuntimeException('The secret store is unavailable.'); }
    try {
        if (!chmod($path, 0600) || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('The secret store is busy or unavailable. Retry the operation.');
        }
        $depth++;
        try { return $work(); } finally { $depth--; }
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

/** Current and optionally pending keys; pending exists only during interrupted rotation. */
function pl_secret_keys(bool $generate = false): array
{
    pl_secret_require_cipher();
    $path = pl_secret_key_path();
    if (is_link($path)) { throw new DomainException('The secret store key file is invalid.'); }
    if (!is_file($path)) {
        if (!$generate || file_exists($path) || (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_plugin_secrets') > 0) {
            throw new DomainException('The secret store key is unavailable. Restore the matching private-directory backup.');
        }
        pl_update_checkpoint($path, ['key' => base64_encode(random_bytes(PL_SECRET_KEY_BYTES)), 'created_at' => gmdate('c')]);
    }
    try { $stored = pl_update_json($path); } catch (Throwable $error) {
        throw new DomainException('The secret store key file is unreadable. Restore its backup.');
    }
    $keys = [];
    foreach (['key', 'pending_key'] as $field) {
        if ($field === 'pending_key' && !array_key_exists($field, $stored)) { continue; }
        $key = is_string($stored[$field] ?? null) ? base64_decode($stored[$field], true) : false;
        if (!is_string($key) || strlen($key) !== PL_SECRET_KEY_BYTES) {
            throw new DomainException('The secret store key file is invalid. Restore its backup.');
        }
        $keys[] = $key;
    }
    return $keys;
}

function pl_secret_key(bool $generate = true): string
{
    return pl_secret_locked(fn(): string => pl_secret_keys($generate)[0]);
}

/** Bind ciphertext to its package, business and field to reject database row substitution. */
function pl_secret_context(string $slug, int $companyId, string $name): string
{
    return json_encode([$slug, $companyId, $name], JSON_THROW_ON_ERROR);
}

/** Encrypt with an explicit key. Used directly only by pl_secret_rotate(), which must encrypt
 *  under a key it has not yet made current. Everything else calls pl_secret_encrypt(). */
function pl_secret_encrypt_with(string $plaintext, string $key, string $context = ''): string
{
    pl_secret_require_cipher();
    $iv = random_bytes(PL_SECRET_IV_BYTES);
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, PL_SECRET_CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, $context, PL_SECRET_TAG_BYTES);
    if ($ciphertext === false || strlen($tag) !== PL_SECRET_TAG_BYTES) {
        throw new RuntimeException('The secret store could not encrypt this value.');
    }
    return PL_SECRET_FORMAT_PREFIX . base64_encode($iv . $tag . $ciphertext);
}

/** Decrypt with an explicit key. Used directly only by pl_secret_rotate(); everything else calls
 *  pl_secret_decrypt(), which reads the current key from the private directory. */
function pl_secret_decrypt_with(string $stored, string $key, string $context = ''): string
{
    if (!str_starts_with($stored, PL_SECRET_FORMAT_PREFIX)) {
        throw new DomainException('This value is not in a format the secret store recognises.');
    }
    $raw = base64_decode(substr($stored, strlen(PL_SECRET_FORMAT_PREFIX)), true);
    if (!is_string($raw) || strlen($raw) <= PL_SECRET_IV_BYTES + PL_SECRET_TAG_BYTES) {
        throw new DomainException('This stored secret is malformed.');
    }
    $iv = substr($raw, 0, PL_SECRET_IV_BYTES);
    $tag = substr($raw, PL_SECRET_IV_BYTES, PL_SECRET_TAG_BYTES);
    $ciphertext = substr($raw, PL_SECRET_IV_BYTES + PL_SECRET_TAG_BYTES);
    pl_secret_require_cipher();
    $plaintext = openssl_decrypt($ciphertext, PL_SECRET_CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, $context);
    if ($plaintext === false) {
        // This is what "authenticated" buys: a wrong key and a tampered ciphertext fail the same
        // check, the same way, and neither ever returns partial or garbage plaintext.
        throw new DomainException('This stored secret could not be verified. It was encrypted under a different key, or its stored value has been altered.');
    }
    return $plaintext;
}

/** Encrypt under the current key, generating one on first use. Never returns without a key: if the
 *  private directory is unavailable, pl_secret_key() throws and nothing is stored. */
function pl_secret_encrypt(string $plaintext, string $context = ''): string
{
    return pl_secret_locked(fn(): string => pl_secret_encrypt_with($plaintext, pl_secret_keys(true)[0], $context));
}

/** Decrypt under the current key. Refuses (throws) rather than returning plaintext salvaged any
 *  other way if the key is unavailable or the value does not verify. */
function pl_secret_decrypt(string $stored, string $context = ''): string
{
    return pl_secret_locked(function () use ($stored, $context): string {
        foreach (pl_secret_keys(false) as $key) {
            try { return pl_secret_decrypt_with($stored, $key, $context); }
            catch (DomainException $error) { /* Try the durable pending rotation key. */ }
        }
        throw new DomainException('This stored secret could not be verified. Restore the matching private-directory backup.');
    });
}

/** Rotation owns its transaction: never publish a key for an uncommitted caller transaction.
 * Persist both keys before changing rows. If interrupted before commit, old rows still open;
 * after commit, pending-key rows open. Retrying rotation safely converges either state.
 * Removing the old key happens only after the database commit. Keep matched off-host backups
 * of the database and private directory; a database-only backup cannot recover credentials. */
function pl_secret_rotate(int $actorId): int
{
    pl_plugin_require_admin($actorId);
    if (DB::transactionDepth() !== 0) { throw new LogicException('Secret rotation cannot run inside a transaction.'); }
    return pl_secret_locked(function (): int {
        $oldKeys = pl_secret_keys(false);
        $newKey = random_bytes(PL_SECRET_KEY_BYTES);
        $rows = DB::query('SELECT id, slug, company_id, secret_name, ciphertext FROM pl_plugin_secrets');
        $updates = [];
        foreach ($rows as $row) {
            $context = pl_secret_context((string) $row['slug'], (int) $row['company_id'], (string) $row['secret_name']);
            $plaintext = pl_secret_decrypt((string) $row['ciphertext'], $context);
            $updates[(int) $row['id']] = pl_secret_encrypt_with($plaintext, $newKey, $context);
        }
        // Complete any previously interrupted rotation first, so at most two keys suffice.
        if (count($oldKeys) > 1) {
            pl_ledger_transaction(function () use ($rows, $oldKeys): void {
                foreach ($rows as $row) {
                    $context = pl_secret_context((string) $row['slug'], (int) $row['company_id'], (string) $row['secret_name']);
                    DB::update('pl_plugin_secrets', ['ciphertext' => pl_secret_encrypt_with(pl_secret_decrypt((string) $row['ciphertext'], $context), $oldKeys[0], $context)], 'id = %i', (int) $row['id']);
                }
            });
        }
        pl_update_checkpoint(pl_secret_key_path(), ['key' => base64_encode($oldKeys[0]), 'pending_key' => base64_encode($newKey), 'created_at' => gmdate('c')]);
        pl_ledger_transaction(function () use ($updates): void {
            foreach ($updates as $id => $ciphertext) {
                DB::update('pl_plugin_secrets', ['ciphertext' => $ciphertext], 'id = %i', $id);
            }
        });
        pl_update_checkpoint(pl_secret_key_path(), ['key' => base64_encode($newKey), 'created_at' => gmdate('c')]);
        return count($updates);
    });
}
