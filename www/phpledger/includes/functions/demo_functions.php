<?php
declare(strict_types=1);

require_once __DIR__ . '/shared_demo_functions.php';

final class PlDemoUnavailable extends DomainException {}

function pl_demo_enabled(): bool
{
    return getenv('PL_ENV') === 'demo';
}

function pl_demo_reset_process(): bool
{
    return pl_demo_enabled() && PHP_SAPI === 'cli' && getenv('PL_DEMO_RESET_MODE') === '1';
}

function pl_demo_validate_configuration(array $configuration): void
{
    if (pl_shared_demo_enabled()) {
        foreach (pl_shared_demo_database() as $key => $value) {
            if ((string) ($configuration[$key] ?? '') !== (string) $value) {
                throw new RuntimeException('The shared demonstration must use its assigned database connection.');
            }
        }
        if (getenv('PL_DEMO_RESET_MODE') === '1') {
            throw new RuntimeException('Shared demo maintenance credentials cannot run the application.');
        }
        return;
    }
    if (!pl_demo_enabled()) {
        if (($configuration['database'] ?? null) === 'phpledger_demo') {
            throw new RuntimeException('The isolated demo database cannot be opened as a normal application.');
        }
        if (getenv('PL_DEMO_RESET_MODE') === '1') {
            throw new RuntimeException('Demo reset mode cannot be used outside the isolated demo environment.');
        }
        return;
    }
    if (($configuration['database'] ?? null) !== 'phpledger_demo') {
        throw new RuntimeException('Demo mode requires the exact isolated phpledger_demo database.');
    }
    if (getenv('PL_DEMO_RESET_MODE') === '1' && PHP_SAPI !== 'cli') {
        throw new RuntimeException('Demo reset credentials must never be used by the web runtime.');
    }
    if (!pl_demo_reset_process() && ($configuration['user'] ?? '') === 'root') {
        throw new RuntimeException('The demo web runtime requires a separate restricted database account.');
    }
}

/** Every demo request holds this connection-scoped lock until shutdown; reset never races a write. */
function pl_demo_acquire_maintenance_lock(): void
{
    if (!pl_demo_enabled()) {
        return;
    }
    // Concurrent MCP startup requests queue briefly without bypassing reset isolation.
    $wait = pl_demo_reset_process() ? 30 : 2;
    if ((int) DB::queryFirstField('SELECT GET_LOCK(%s, %i)', 'phpledger:demo:maintenance', $wait) !== 1) {
        throw new PlDemoUnavailable('The sample is being refreshed or is busy. Please try again in a moment.');
    }
    register_shutdown_function(static function (): void {
        try {
            DB::queryFirstField('SELECT RELEASE_LOCK(%s)', 'phpledger:demo:maintenance');
        } catch (Throwable) {
            // A closed MySQL connection releases its advisory locks automatically.
        }
    });
    if (!pl_demo_reset_process()) {
        foreach (DB::queryFirstColumn('SHOW GRANTS FOR CURRENT_USER') as $grant) {
            if (preg_match('/\b(ALL PRIVILEGES|CREATE|ALTER|DROP|DELETE|TRIGGER|EVENT|SUPER|FILE|GRANT OPTION|EXECUTE)\b/i', $grant)) {
                throw new RuntimeException('The demo web database account has forbidden destructive or administrative privileges.');
            }
        }
        $state = DB::queryFirstRow('SELECT generation, next_reset_at FROM pl_demo_state WHERE id = 1');
        if (!$state || strtotime($state['next_reset_at'] . ' UTC') <= time()) {
            throw new PlDemoUnavailable('The hourly sample refresh is due. Please return after the demo has refreshed.');
        }
    }
}

/** Private provisioning scope is never chosen from request parameters. */
function pl_demo_provisioning(?callable $work = null): mixed
{
    static $active = false;
    if ($work === null) {
        return $active;
    }
    if ($active || !pl_demo_enabled() || pl_demo_reset_process()) {
        throw new DomainException('Demo provisioning is unavailable.');
    }
    $active = true;
    try {
        return $work();
    } finally {
        $active = false;
    }
}

function pl_demo_require_setup_action(): void
{
    if (pl_demo_enabled() && !pl_demo_provisioning()) {
        throw new DomainException('Business setup and user administration are disabled in the public sample.');
    }
}

/**
 * The one rule for creating fictional sample companies: only a local or test environment,
 * or the isolated public demo while it provisions a visitor's own sample. A production
 * installation never creates one, whichever form or request asks for it.
 */
function pl_sample_companies_allowed(): bool
{
    return in_array(getenv('PL_ENV'), ['local', 'test'], true) || (pl_demo_enabled() && pl_demo_provisioning()) || pl_sample_installed_packages() !== [];
}

function pl_require_sample_companies_allowed(): void
{
    if (!pl_sample_companies_allowed()) {
        throw new DomainException('Sample companies are available through the isolated demo or local development environment.');
    }
}

function pl_demo_require_company(int $actorId, int $companyId): void
{
    if (!pl_demo_enabled() || pl_demo_provisioning()) {
        return;
    }
    $allowed = DB::queryFirstField('SELECT v.user_id FROM pl_demo_visitors v JOIN pl_demo_state s ON s.id = 1 AND s.generation = v.generation JOIN pl_companies c ON c.id = v.company_id WHERE v.user_id = %i AND v.company_id = %i AND c.is_sample = 1 AND s.next_reset_at > UTC_TIMESTAMP() FOR SHARE', $actorId, $companyId);
    if (!$allowed) {
        throw new DomainException('This sample belongs to another visitor or has expired. Start a fresh sample.');
    }
}

function pl_demo_require_document_capacity(int $companyId, int $bookId): void
{
    if (!pl_demo_enabled() || pl_demo_provisioning() || pl_demo_with_document_capacity($companyId, $bookId)) {
        return;
    }
    if (pl_demo_document_count($companyId, $bookId) >= pl_demo_document_limit()) {
        throw new DomainException('This sample has reached its transaction limit. Existing records remain available until the hourly refresh.');
    }
}

/**
 * The ceiling includes the sample pack's own history, which is already 85 to 105 records
 * on arrival, so the default leaves each visitor well over 100 practice records.
 */
function pl_demo_document_limit(): int
{
    return min(500, max(10, (int) (getenv('PL_DEMO_MAX_DOCUMENTS') ?: 250)));
}

/** Count durable sources and operation receipts; compound actions can consume several records. */
function pl_demo_document_count(int $companyId, int $bookId): int
{
    $count = 0;
    foreach (['pl_documents', 'pl_general_drafts', 'pl_ar_documents', 'pl_ar_document_actions',
        'pl_purchase_orders', 'pl_purchase_commands', 'pl_inventory_commands', 'pl_open_item_commands'] as $table) {
        $count += (int) DB::queryFirstField('SELECT COUNT(*) FROM %b WHERE company_id=%i AND book_id=%i', $table, $companyId, $bookId);
    }
    return $count;
}

/**
 * Called after retry lookup, inside the caller's transaction and book lock.
 * Null work only reads the private admission state for the legacy single-source guard.
 */
function pl_demo_with_document_capacity(int $companyId, int $bookId, ?callable $work = null): mixed
{
    static $active = [];
    $scope = $companyId . ':' . $bookId;
    if ($work === null) { return isset($active[$scope]); }
    if (!pl_demo_enabled() || pl_demo_provisioning() || isset($active[$scope])) { return $work(); }
    pl_demo_require_document_capacity($companyId, $bookId);
    $active[$scope] = true;
    try {
        $result = $work();
        if (pl_demo_document_count($companyId, $bookId) > pl_demo_document_limit()) {
            throw new DomainException('This action would exceed the sample transaction limit. Use fewer lines or start a fresh sample after the hourly refresh.');
        }
        return $result;
    } finally {
        unset($active[$scope]);
    }
}

function pl_demo_session_valid(int $userId): bool
{
    if (!pl_demo_enabled()) {
        return true;
    }
    pl_require_session();
    $generation = $_SESSION['demo_generation'] ?? null;
    if (!is_string($generation) || strlen($generation) !== 64) {
        return false;
    }
    $current = DB::queryFirstField('SELECT v.generation FROM pl_demo_visitors v JOIN pl_demo_state s ON s.id = 1 AND s.generation = v.generation WHERE v.user_id = %i AND s.next_reset_at > UTC_TIMESTAMP()', $userId);
    return is_string($current) && hash_equals($current, $generation);
}

function pl_demo_begin_visit(string $csrfToken, string $currency = 'USD', ?string $samplePack = null): array
{
    if (!pl_demo_enabled() || pl_demo_reset_process()) {
        throw new DomainException('Public samples are available only in the isolated demo environment.');
    }
    pl_require_session();
    pl_require_post();
    pl_require_csrf($csrfToken);
    if (!isset(pl_base_currency_options()[$currency])) {
        throw new DomainException('Choose one of the supported sample currencies.');
    }
    $pack = $samplePack === null ? null : pl_demo_sample($samplePack);
    $current = pl_current_user_id();
    if ($current !== null) {
        $existing = DB::queryFirstRow('SELECT v.company_id, b.id AS book_id, v.generation, u.id, u.email, u.display_name FROM pl_demo_visitors v JOIN pl_books b ON b.company_id = v.company_id JOIN pl_users u ON u.id = v.user_id WHERE v.user_id = %i', $current);
        if ($existing) {
            return ['user' => ['id' => $current, 'email' => $existing['email'], 'display_name' => $existing['display_name']], 'company_id' => (int) $existing['company_id'], 'book_id' => (int) $existing['book_id'], 'generation' => $existing['generation']];
        }
    }
    $result = pl_demo_provisioning(static fn (): array => pl_ledger_transaction(static function () use ($currency, $pack): array {
        $state = DB::queryFirstRow('SELECT * FROM pl_demo_state WHERE id = 1 FOR UPDATE');
        if (!$state || strtotime($state['next_reset_at'] . ' UTC') <= time()) {
            throw new PlDemoUnavailable('The hourly sample refresh is due. Please return after the demo has refreshed.');
        }
        $limit = min(1000, max(1, (int) (getenv('PL_DEMO_MAX_VISITORS') ?: 100)));
        if ((int) DB::queryFirstField('SELECT COUNT(*) FROM pl_demo_visitors') >= $limit) {
            throw new PlDemoUnavailable('This hour’s sample spaces are full. Please return after the hourly refresh.');
        }
        $nonce = bin2hex(random_bytes(16));
        $email = 'demo-' . $nonce . '@example.invalid';
        $name = 'Sample visitor';
        $userId = pl_create_user($email, $name, bin2hex(random_bytes(24)));
        $company = pl_setup_company($userId, [
            'name' => ($pack['name'] ?? 'Cedar Trading') . ' — Your private sample', 'currency' => $currency, 'start_date' => $pack['start_date'] ?? gmdate('Y-m-d'), 'fiscal_year_end' => '12-31',
            'start_mode' => 'sample', 'template_digest' => pl_starter_template()['digest'], 'zero_balances_confirmed' => false,
        ] + ($pack === null ? [] : ['sample_pack' => $pack['id']]), 'visitor:' . $nonce);
        DB::insert('pl_demo_visitors', ['user_id' => $userId, 'company_id' => $company['id'], 'generation' => $state['generation']]);
        return ['user' => ['id' => $userId, 'email' => $email, 'display_name' => $name], 'company_id' => $company['id'], 'book_id' => $company['book_id'], 'generation' => $state['generation']];
    }));
    pl_login_session($result['user']);
    $_SESSION['demo_generation'] = $result['generation'];
    return $result;
}
