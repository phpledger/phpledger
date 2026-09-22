<?php
declare(strict_types=1);

/** Hosting policy for the shared, hourly disposable installation. */
function pl_shared_demo_enabled(): bool
{
    return getenv('PL_ENV') === 'demo-install';
}

/** Public application credentials, deliberately not database credentials. */
function pl_shared_demo_account(): array
{
    return ['username' => 'demo', 'email' => 'demo@example.invalid',
        'name' => 'Demo visitor', 'password' => 'DemoLedger123!'];
}

/** Never accept a database destination or secret from a demo visitor. */
function pl_shared_demo_database(): array
{
    if (!pl_shared_demo_enabled() || getenv('PL_DB_NAME') !== 'phpledger_demo') {
        throw new DomainException('The shared demonstration database is not configured.');
    }
    $host = (string) getenv('PL_DB_HOST');
    $user = (string) getenv('PL_DB_USER');
    $password = (string) getenv('PL_DB_PASSWORD');
    $port = (string) (getenv('PL_DB_PORT') ?: '3306');
    if ($host === '' || $user === '' || strtolower($user) === 'root' || $password === ''
        || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
        throw new DomainException('The shared demonstration database is not configured.');
    }
    return ['host' => $host, 'port' => (int) $port, 'database' => 'phpledger_demo',
        'user' => $user, 'password' => $password];
}

function pl_shared_demo_public_url(): string
{
    $url = (string) getenv('PL_PUBLIC_URL');
    if (!pl_shared_demo_enabled() || $url === '') {
        throw new DomainException('The shared demonstration address is not configured.');
    }
    return $url;
}

/** Stable host-held identity, unaffected by attempted profile changes. */
function pl_shared_demo_is_account(int $userId): bool
{
    if (!pl_shared_demo_enabled()) {
        return false;
    }
    require_once __DIR__ . '/installation_state_functions.php';
    $receipt = pl_install_read_state('installed.json');
    return $userId > 0 && (int) ($receipt['initial_owner_id'] ?? 0) === $userId;
}

function pl_shared_demo_require_mutable_account(int $userId): void
{
    if (pl_shared_demo_is_account($userId)) {
        throw new DomainException('The shared demo login stays fixed so everyone can sign in.');
    }
}
