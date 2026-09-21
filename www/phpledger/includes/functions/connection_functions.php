<?php
declare(strict_types=1);

/** A stable operator-configured origin prevents Host-header audience/redirect substitution. */
function pl_connection_issuer(): string
{
    $url = rtrim(getenv('PL_PUBLIC_URL') ?: '', '/');
    $parts = parse_url($url);
    $local = in_array(getenv('PL_ENV'), ['local', 'test'], true) || (pl_demo_enabled() && getenv('PL_DEMO_LOCAL_HTTP') === '1');
    if (!$parts || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
        || !isset($parts['host']) || strlen($url) > 480
        || (($parts['scheme'] ?? '') !== 'https' && !($local && ($parts['scheme'] ?? '') === 'http' && in_array($parts['host'], ['localhost', '127.0.0.1'], true)))) {
        throw new RuntimeException('Configure PL_PUBLIC_URL with the public HTTPS application URL to enable connections.');
    }
    return $url;
}

function pl_connection_resource(): string
{
    return pl_connection_issuer() . '/mcp';
}

function pl_connection_source(): string
{
    // Reuse existing exact trusted-proxy parsing without performing a country lookup.
    return pl_country_lookup_ip($_SERVER) ?? pl_normalize_ip((string) ($_SERVER['REMOTE_ADDR'] ?? '')) ?? 'unknown';
}

function pl_connection_audit(?string $id, ?int $actor, string $action, string $outcome): void
{
    // Deliberately no arguments, result bodies, addresses, tokens or user-supplied descriptions.
    if (!preg_match('/^[a-z0-9_.-]{1,60}$/D', $action) || !preg_match('/^[a-z0-9_]{1,30}$/D', $outcome)) {
        throw new LogicException('Invalid integration audit event.');
    }
    DB::insert('pl_connection_audit', ['connection_id' => $id, 'actor_id' => $actor, 'action' => $action, 'outcome' => $outcome]);
}

/** Durable per-minute limits. Cleanup is bounded and records no raw source address. */
function pl_connection_rate(string $subject, int $limit): void
{
    $hash = hash('sha256', $subject);
    $window = gmdate('Y-m-d H:i:00');
    // The demo web user deliberately has no DELETE privilege; its hourly reset clears these rows.
    if (!pl_demo_enabled()) { DB::query('DELETE FROM pl_connection_rates WHERE window_started_at < UTC_TIMESTAMP() - INTERVAL 1 DAY LIMIT 100'); }
    DB::query('INSERT INTO pl_connection_rates (subject_hash, window_started_at, attempt_count) VALUES (%s, %s, 1) ON DUPLICATE KEY UPDATE attempt_count = IF(window_started_at = %s, attempt_count + 1, 1), window_started_at = %s', $hash, $window, $window, $window);
    if ((int) DB::queryFirstField('SELECT attempt_count FROM pl_connection_rates WHERE subject_hash = %s', $hash) > $limit) {
        throw new OverflowException('Too many requests. Wait one minute and retry.');
    }
}

function pl_connection_demo_context(int $actor, int $company): ?array
{
    if (!pl_demo_enabled()) {
        return null;
    }
    $row = DB::queryFirstRow('SELECT v.generation, s.next_reset_at FROM pl_demo_visitors v JOIN pl_demo_state s ON s.id = 1 AND s.generation = v.generation WHERE v.user_id = %i AND v.company_id = %i AND s.next_reset_at > UTC_TIMESTAMP() FOR SHARE', $actor, $company);
    if (!$row) {
        throw new DomainException('Demo access expired. Start a new sample and reconnect your client.');
    }
    return $row;
}

/** Grants are explicit pairs of existing company/book IDs, never a wildcard. */
function pl_create_connection(int $actor, string $client, string $name, array $books, string $kind = 'personal', string $oauthScopes = 'ledger.read', string $accessMode = 'full'): array
{
    return pl_ledger_transaction(function () use ($actor, $client, $name, $books, $kind, $oauthScopes, $accessMode): array {
        if (!in_array($accessMode, ['reports', 'full'], true)) { throw new DomainException('Choose report-only or full read access.'); }
        $name = pl_ledger_text($name, 'Connection name', 120);
        if (!in_array($kind, ['personal', 'oauth'], true) || !in_array($oauthScopes, ['ledger.read', 'ledger.read offline_access'], true) || count($books) < 1 || count($books) > 20) {
            throw new DomainException('Choose one to twenty authorized company/book pairs and read access.');
        }
        // Serialize connection creation for this actor, including concurrent consent forms.
        if (!DB::queryFirstField('SELECT id FROM pl_users WHERE id = %i AND is_active = 1 FOR UPDATE', $actor)) {
            throw new DomainException('Sign in again to create a connection.');
        }
        if ((int) DB::queryFirstField('SELECT COUNT(*) FROM pl_connections WHERE actor_id = %i AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()', $actor) >= 30) {
            throw new DomainException('Revoke an existing connection before adding another.');
        }
        if ($kind === 'personal') {
            $client = 'personal';
            DB::query("INSERT IGNORE INTO pl_connection_clients (client_id, name, redirect_uris, source) VALUES ('personal', 'Personal API token', '[]', 'personal')");
        }
        $clientRow = DB::queryFirstRow('SELECT client_id, expires_at FROM pl_connection_clients WHERE client_id = %s AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP()) FOR SHARE', $client);
        if (!$clientRow) {
            throw new DomainException('This client registration is unavailable. Reconnect the client.');
        }
        $expiry = gmdate('Y-m-d H:i:s', time() + 30 * 86400);
        if ($clientRow['expires_at'] !== null) { $expiry = min($expiry, $clientRow['expires_at']); }
        $generation = null;
        $pairs = [];
        foreach ($books as $pair) {
            if (!is_array($pair) || !is_int($pair['company_id'] ?? null) || !is_int($pair['book_id'] ?? null)) {
                throw new DomainException('Choose a valid company and book.');
            }
            pl_require_company_access($actor, $pair['company_id']);
            pl_ledger_book($pair['company_id'], $pair['book_id']);
            $demo = pl_connection_demo_context($actor, $pair['company_id']);
            if ($demo) {
                $generation = $demo['generation'];
                $expiry = min($expiry, $demo['next_reset_at']);
            }
            $pairs[$pair['company_id'] . ':' . $pair['book_id']] = $pair;
        }
        $id = bin2hex(random_bytes(16));
        DB::insert('pl_connections', ['id' => $id, 'actor_id' => $actor, 'client_id' => $client, 'name' => $name, 'kind' => $kind, 'oauth_scopes' => $oauthScopes, 'access_mode' => $accessMode, 'resource' => pl_connection_resource(), 'demo_generation' => $generation, 'expires_at' => $expiry]);
        foreach ($pairs as $pair) {
            DB::insert('pl_connection_books', ['connection_id' => $id] + $pair);
        }
        pl_connection_audit($id, $actor, 'created', 'success');
        return pl_connection_require($id);
    });
}

/** Current reads on every request; no browser session is constructed for machine callers. */
function pl_connection_require(string $id): array
{
    $row = DB::queryFirstRow('SELECT c.* FROM pl_connections c JOIN pl_connection_clients k ON k.client_id = c.client_id JOIN pl_users u ON u.id = c.actor_id WHERE c.id = %s AND c.revoked_at IS NULL AND c.expires_at > UTC_TIMESTAMP() AND k.revoked_at IS NULL AND (k.expires_at IS NULL OR k.expires_at > UTC_TIMESTAMP()) AND u.is_active = 1 FOR SHARE', $id);
    if (!$row || !hash_equals(pl_connection_resource(), $row['resource'])) {
        throw new UnexpectedValueException('Connection expired or revoked. Reconnect from PHP Ledger Connections.');
    }
    $row['actor_id'] = (int) $row['actor_id'];
    $row['books'] = DB::query('SELECT company_id, book_id FROM pl_connection_books WHERE connection_id = %s ORDER BY company_id, book_id FOR SHARE', $id);
    foreach ($row['books'] as &$pair) {
        $pair['company_id'] = (int) $pair['company_id'];
        $pair['book_id'] = (int) $pair['book_id'];
        try {
            pl_require_company_access($row['actor_id'], $pair['company_id']);
            pl_ledger_book($pair['company_id'], $pair['book_id']);
            $demo = pl_connection_demo_context($row['actor_id'], $pair['company_id']);
            if (($demo['generation'] ?? null) !== $row['demo_generation']) {
                throw new DomainException('Demo generation changed.');
            }
        } catch (DomainException) {
            throw new UnexpectedValueException('Connection permissions changed or the sample expired. Reconnect from PHP Ledger Connections.');
        }
    }
    unset($pair);
    if ($row['books'] === []) {
        throw new UnexpectedValueException('This connection has no authorized books.');
    }
    return $row;
}

function pl_connection_scope(array $connection, int $company, int $book): void
{
    if (!in_array(['company_id' => $company, 'book_id' => $book], $connection['books'], true)) {
        throw new DomainException('This connection does not authorize the selected company and book.');
    }
}

/** Return the raw personal token once to the caller; persist only SHA-256 of 256 random bits. */
function pl_create_personal_token(int $actor, string $name, array $books, string $accessMode = 'full'): array
{
    return pl_ledger_transaction(function () use ($actor, $name, $books, $accessMode): array {
        $connection = pl_create_connection($actor, 'personal', $name, $books, 'personal', 'ledger.read', $accessMode);
        $token = 'plp_' . bin2hex(random_bytes(32));
        pl_connection_save_token($token, $connection, 'personal', $connection['expires_at']);
        return ['connection' => $connection, 'token' => $token];
    });
}

function pl_connection_save_token(#[SensitiveParameter] string $token, array $connection, string $kind, string $expiry): void
{
    DB::insert('pl_connection_tokens', ['token_hash' => hash('sha256', $token), 'connection_id' => $connection['id'], 'kind' => $kind, 'expires_at' => min($expiry, $connection['expires_at'])]);
}

function pl_connection_token(#[SensitiveParameter] string $token, string $kind, bool $lock = false): array
{
    $row = DB::queryFirstRow('SELECT * FROM pl_connection_tokens WHERE token_hash = %s AND kind = %s' . ($lock ? ' FOR UPDATE' : ' FOR SHARE'), hash('sha256', $token), $kind);
    if (!$row || $row['revoked_at'] !== null || $row['expires_at'] <= gmdate('Y-m-d H:i:s')) {
        throw new UnexpectedValueException('Credential expired or revoked. Reconnect from PHP Ledger Connections.');
    }
    return pl_connection_require($row['connection_id']);
}

function pl_revoke_connection(int $actor, string $id): void
{
    pl_ledger_transaction(function () use ($actor, $id): void {
        $row = DB::queryFirstRow('SELECT actor_id FROM pl_connections WHERE id = %s FOR UPDATE', $id);
        // 1.2 M7: 'may manage anyone's connection in one of this connection's companies',
        // formerly 'is the owner of one of them'.
        $owner = false;
        foreach (DB::queryFirstColumn('SELECT company_id FROM pl_connection_books WHERE connection_id = %s', $id) as $scoped) {
            if (pl_user_can($actor, (int) $scoped, 'connections.manage_all')) { $owner = true; break; }
        }
        if (!$row || ((int) $row['actor_id'] !== $actor && !$owner) || !DB::queryFirstField('SELECT id FROM pl_users WHERE id = %i AND is_active = 1 FOR SHARE', $actor)) {
            throw new DomainException('You cannot revoke this connection.');
        }
        foreach (DB::queryFirstColumn('SELECT company_id FROM pl_connection_books WHERE connection_id = %s', $id) as $company) {
            if (pl_demo_enabled()) {
                pl_demo_require_company($actor, (int) $company);
            }
        }
        DB::query('UPDATE pl_connections SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP()) WHERE id = %s', $id);
        pl_connection_end_sessions($id);
        pl_connection_audit($id, $actor, 'revoked', 'success');
    });
}

function pl_connection_end_sessions(string $id): void
{
    DB::query("UPDATE pl_connection_sessions SET state = '', expires_at = UTC_TIMESTAMP() WHERE connection_id = %s", $id);
}

function pl_list_connections(int $actor, int $company, int $book): array
{
    pl_require_company_access($actor, $company);
    pl_ledger_book($company, $book);
    return DB::query('SELECT c.id, c.name, c.kind, c.access_mode, c.client_id, c.actor_id, c.created_at, c.expires_at, c.revoked_at FROM pl_connections c JOIN pl_connection_books b ON b.connection_id = c.id WHERE b.company_id = %i AND b.book_id = %i AND (c.actor_id = %i OR %i = 1) ORDER BY c.created_at DESC, c.id LIMIT 100', $company, $book, $actor, pl_user_can($actor, $company, 'connections.manage_all') ? 1 : 0);
}
