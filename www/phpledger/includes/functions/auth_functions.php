<?php
declare(strict_types=1);

/**
 * PASSWORD_DEFAULT currently uses bcrypt, whose input limit is 72 bytes. The minimum is six
 * characters (owner decision, 26 September 2026); the sign-in screens advise longer.
 */
function pl_hash_password(string $password): string
{
    if (strlen($password) < 6 || strlen($password) > 72 || str_contains($password, "\0")) {
        throw new InvalidArgumentException('Use a password between 6 and 72 bytes.');
    }
    return password_hash($password, PASSWORD_DEFAULT);
}

function pl_verify_password(string $password, string $hash): bool
{
    return strlen($password) <= 72 && !str_contains($password, "\0") && password_verify($password, $hash);
}

/**
 * Usernames are an optional second sign-in name: 3-60 lowercase letters, digits, dots,
 * dashes or underscores, starting and ending with a letter or digit. They never contain
 * "@", so a sign-in name is unambiguously either an email address or a username.
 */
function pl_normalize_username(string $username): string
{
    $username = strtolower(trim($username));
    if (!preg_match('/^[a-z0-9](?:[a-z0-9._-]{1,58})[a-z0-9]$/D', $username)) {
        throw new InvalidArgumentException('Choose a username of 3 to 60 characters using letters, numbers, dots, dashes or underscores. It must start and end with a letter or number.');
    }
    return $username;
}

/** Internal account creation, used by guarded CLI and one-time browser setup. */
function pl_create_user(string $email, string $displayName, string $password, ?string $username = null): int
{
    pl_demo_require_setup_action();
    $email = strtolower(trim($email));
    $displayName = trim($displayName);
    if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new InvalidArgumentException('Enter a valid email address.');
    }
    if ($displayName === '' || !mb_check_encoding($displayName, 'UTF-8') || mb_strlen($displayName, 'UTF-8') > 120) {
        throw new InvalidArgumentException('Enter a name up to 120 characters.');
    }
    $username = $username === null || trim($username) === '' ? null : pl_normalize_username($username);
    $hash = pl_hash_password($password);
    if ($username !== null && DB::queryFirstField('SELECT id FROM pl_users WHERE username = %s', $username) !== null) {
        throw new InvalidArgumentException('That username is already taken. Choose another one.');
    }
    DB::insert('pl_users', [
        'email' => $email,
        'username' => $username,
        'display_name' => $displayName,
        'password_hash' => $hash,
        'is_active' => 1,
    ]);
    return (int) DB::insertId();
}

/**
 * Count login attempts in durable server-side buckets, locked in stable order.
 * No email address, password, or client address is stored in the limiter.
 * A failed/disabled/unknown/throttled login always returns the same null result.
 * The sign-in name is an email address or, when it has no "@", a username.
 */
function pl_authenticate(string $signInName, string $password, string $clientIp): ?array
{
    $signInName = strtolower(trim($signInName));
    $now = time();

    DB::startTransaction();
    try {
        if (str_contains($signInName, '@')) {
            $user = strlen($signInName) <= 254 && filter_var($signInName, FILTER_VALIDATE_EMAIL) !== false
                ? DB::queryFirstRow('SELECT id, email, display_name, password_hash, is_active FROM pl_users WHERE email = %s', $signInName)
                : null;
        } else {
            $user = preg_match('/^[a-z0-9][a-z0-9._-]{1,58}[a-z0-9]$/D', $signInName)
                ? DB::queryFirstRow('SELECT id, email, display_name, password_hash, is_active FROM pl_users WHERE username = %s', $signInName)
                : null;
        }
        // Email and username share one account limit, so switching names does not add guesses.
        $accountKey = hash('sha256', 'account:' . ($user['email'] ?? $signInName));
        $clientKey = hash('sha256', 'client:' . $clientIp);
        $limits = [$accountKey => 5, $clientKey => 30];
        ksort($limits, SORT_STRING);
        $buckets = [];
        $blocked = false;
        foreach ($limits as $key => $limit) {
            DB::query(
                'INSERT INTO pl_login_attempts (subject_hash, window_started_at, attempt_count, blocked_until) '
                . 'VALUES (%s, %s, 0, NULL) ON DUPLICATE KEY UPDATE subject_hash = subject_hash',
                $key,
                gmdate('Y-m-d H:i:s', $now)
            );
            $bucket = DB::queryFirstRow('SELECT * FROM pl_login_attempts WHERE subject_hash = %s FOR UPDATE', $key);
            if (!$bucket) {
                throw new RuntimeException('Login attempt tracking is unavailable.');
            }
            $windowStart = strtotime($bucket['window_started_at'] . ' UTC');
            if ($windowStart === false || $now - $windowStart >= 900) {
                $bucket['attempt_count'] = 0;
                $bucket['window_started_at'] = gmdate('Y-m-d H:i:s', $now);
                $bucket['blocked_until'] = null;
            }
            if ((int) $bucket['attempt_count'] >= $limit) {
                $blocked = true;
            }
            $buckets[$key] = $bucket;
        }
        if ($blocked) {
            DB::commit();
            return null;
        }

        // Public dummy bcrypt hash keeps unknown-user verification on the same code path.
        $dummyHash = '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        $valid = pl_verify_password($password, $user['password_hash'] ?? $dummyHash)
            && $user !== null && (int) $user['is_active'] === 1;

        foreach ($buckets as $key => $bucket) {
            // Successful sign-ins reset the account bucket and never consume the shared client bucket.
            $count = $valid ? ($key === $accountKey ? 0 : (int) $bucket['attempt_count']) : (int) $bucket['attempt_count'] + 1;
            $windowStart = ($valid && $key === $accountKey) ? gmdate('Y-m-d H:i:s', $now) : $bucket['window_started_at'];
            DB::update('pl_login_attempts', [
                'window_started_at' => $windowStart,
                'attempt_count' => $count,
                'blocked_until' => $count >= $limits[$key]
                    ? gmdate('Y-m-d H:i:s', (int) strtotime($windowStart . ' UTC') + 900)
                    : null,
            ], 'subject_hash = %s', $key);
        }

        if ($valid && password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            DB::update('pl_users', ['password_hash' => pl_hash_password($password)], 'id = %i', (int) $user['id']);
        }
        DB::commit();
        if (!$valid) {
            return null;
        }
        return ['id' => (int) $user['id'], 'email' => $user['email'], 'display_name' => $user['display_name']];
    } catch (Throwable $error) {
        DB::rollback();
        throw $error;
    }
}

/** Server-side boundary for every company read and write, including CLI services. */
function pl_require_company_access(int $actorId, int $companyId, bool $write = false): array
{
    if ($actorId < 1 || $companyId < 1) {
        throw new DomainException('You do not have access to this company.');
    }
    $member = pl_company_member_role($actorId, $companyId);
    if (!$member || !in_array($write ? 'company.write' : 'company.read', $member['capabilities'], true)) {
        throw new DomainException('You do not have access to this company.');
    }
    pl_demo_require_company($actorId, $companyId);
    return $member;
}
