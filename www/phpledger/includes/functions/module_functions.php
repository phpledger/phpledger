<?php
declare(strict_types=1);

/** Manifests are metadata from an explicit project-owned allowlist, never executable uploads. */
function pl_module_registry(): array
{
    $registry = [];
    foreach (['core', 'ar', 'ap', 'inventory', 'inventory-locations', 'purchasing', 'pos-showcase', 'trading-documents', 'fixed-assets'] as $id) {
        // The browser installer reads metadata before the application bootstrap defines PL_ROOT.
        $path = (defined('PL_ROOT') ? PL_ROOT : dirname(__DIR__, 4)) . '/resources/modules/' . $id . '.json';
        $source = is_file($path) ? file_get_contents($path) : false;
        if ($source === false) { throw new DomainException('A bundled module manifest is missing. Restore the reviewed package.'); }
        try { $manifest = json_decode($source, true, 32, JSON_THROW_ON_ERROR); }
        catch (JsonException) { throw new DomainException('A bundled module manifest is invalid. Restore the reviewed package.'); }
        if (!is_array($manifest) || ($manifest['id'] ?? null) !== $id) { throw new DomainException('Module identity does not match its package.'); }
        $registry[$id] = $manifest;
    }
    pl_validate_module_registry($registry);
    foreach ($registry as &$manifest) {
        // Canonical data hash ignores checkout line endings, but detects contract/content changes.
        $manifest['digest'] = hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
    unset($manifest);
    return $registry;
}

/** Exact dependency versions are intentional for the first small compatibility matrix. */
function pl_validate_module_registry(array $registry): void
{
    if (!isset($registry['core']) || ($registry['core']['optional'] ?? null) !== false) { throw new DomainException('The required accounting core is missing.'); }
    $capabilities = [];
    foreach ($registry as $id => $m) {
        if (!is_array($m) || ($m['id'] ?? null) !== $id || !preg_match('/^[a-z][a-z0-9-]{0,59}$/D', $id)
            || !is_string($m['name'] ?? null) || ($m['contract'] ?? null) !== 1 || !is_bool($m['optional'] ?? null)
            || !is_string($m['version'] ?? null) || !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $m['version'])
            || !is_array($m['requires'] ?? null) || !is_string($m['history'] ?? null)) {
            throw new DomainException('A module requires an unsupported or malformed core contract.');
        }
        // Optional translation catalogue directory (release plan 1.2 M2). A manifest that omits
        // `lang` is unchanged, so no bundled manifest's digest and no company's recorded
        // manifest_hash moves; a module that declares one names a plain project-relative
        // directory, never an absolute path, a parent traversal or a file.
        if (array_key_exists('lang', $m) && (!is_string($m['lang']) || !preg_match('#^[a-z0-9][a-z0-9-]*(?:/[a-z0-9][a-z0-9-]*){0,4}$#D', $m['lang']) || strlen($m['lang']) > 128)) {
            throw new DomainException('A module translation directory must be a plain relative path.');
        }
        // Optional permission capabilities this module owns (release plan 1.2 M7), a map of
        // capability name to label. A manifest that omits `grants` is byte-identical to the one
        // 1.1 shipped, so its digest does not move and no company is asked to re-review a module
        // that did not change; the bundled manifests omit it in 1.2. Same rule as `lang`.
        if (array_key_exists('grants', $m) && function_exists('pl_manifest_capability_grants')) {
            pl_manifest_capability_grants($m);
        }
        foreach (['capabilities', 'migrations', 'routes', 'permissions', 'settings', 'reports', 'api_operations', 'mcp_operations'] as $key) {
            if (!is_array($m[$key] ?? null) || !array_is_list($m[$key]) || count($m[$key]) !== count(array_unique($m[$key], SORT_REGULAR))) { throw new DomainException('Module declarations must be unique lists.'); }
            foreach ($m[$key] as $value) { if (!is_string($value) || $value === '') { throw new DomainException('Module declarations must be nonempty strings.'); } }
        }
        foreach ($m['migrations'] as $migration) {
            if (!preg_match('/^[0-9]{3}_[a-z0-9_]+$/D', $migration)) { throw new DomainException('Invalid module migration identity.'); }
        }
        foreach ($m['capabilities'] as $capability) {
            if (isset($capabilities[$capability])) { throw new DomainException('A capability has multiple owners.'); }
            $capabilities[$capability] = $id;
        }
        foreach ($m['requires'] as $dependency => $version) {
            if (!isset($registry[$dependency]) || $dependency === $id || $registry[$dependency]['version'] !== $version) { throw new DomainException('Module dependency is missing or incompatible.'); }
        }
    }
    $visited = []; $visiting = [];
    $visit = function (string $id) use (&$visit, &$visited, &$visiting, $registry): void {
        if (isset($visiting[$id])) { throw new DomainException('Module dependencies contain a cycle.'); }
        if (isset($visited[$id])) { return; }
        $visiting[$id] = true;
        foreach (array_keys($registry[$id]['requires']) as $dependency) { $visit($dependency); }
        unset($visiting[$id]); $visited[$id] = true;
    };
    foreach (array_keys($registry) as $id) { $visit($id); }
}

function pl_module_installed(array $manifest): void
{
    foreach ($manifest['migrations'] as $version) {
        $path = PL_APP . '/install/migrations/' . $version . '.php';
        $receipt = DB::queryFirstRow('SELECT status, checksum FROM pl_schema_migrations WHERE version = %s FOR SHARE', $version);
        $checksum = is_file($path) ? hash_file('sha256', $path) : false;
        $legacyReceipt = $version === '017_ar_ap_documents'
            && hash_equals('9464c5c1f3f62c294e628bb1a8361918ac0b153c155ce2fa21c9d3bd63f7b936', (string) ($receipt['checksum'] ?? ''));
        if (!$receipt || $receipt['status'] !== 'applied' || $checksum === false
            || (!hash_equals((string) $receipt['checksum'], $checksum) && !$legacyReceipt)) {
            throw new DomainException('Module installation is incomplete or incompatible. Run the reviewed package preflight and migrations.');
        }
    }
}

function pl_module_state(int $companyId, string $id): array
{
    $row = DB::queryFirstRow('SELECT module_id, enabled, version, manifest_hash, revision FROM pl_company_modules WHERE company_id = %i AND module_id = %s FOR SHARE', $companyId, $id);
    if (!$row) { return ['module_id' => $id, 'enabled' => false, 'version' => '', 'manifest_hash' => '', 'revision' => 0]; }
    $row['enabled'] = (bool) $row['enabled']; $row['revision'] = (int) $row['revision'];
    return $row;
}

function pl_require_module(int $actorId, int $companyId, int $bookId, string $id, bool $write = true): void
{
    $member = pl_require_company_access($actorId, $companyId, $write);
    pl_ledger_book($companyId, $bookId);
    $registry = pl_module_registry();
    if (!isset($registry[$id])) { throw new DomainException('Unknown module.'); }
    if ($write && !in_array($member['role'], $registry[$id]['permissions'], true)) { throw new DomainException('Your role cannot use this module action.'); }
    $check = function (string $module) use (&$check, $companyId, $registry): void {
        $manifest = $registry[$module];
        pl_module_installed($manifest);
        if ($manifest['optional']) {
            $state = pl_module_state($companyId, $module);
            if (!$state['enabled']) { throw new DomainException('This module is disabled for this company. The owner can enable it in Modules.'); }
            if ($state['version'] !== $manifest['version'] || !hash_equals($state['manifest_hash'], $manifest['digest'])) { throw new DomainException('Review and apply the module upgrade in Modules before new operations.'); }
        }
        foreach (array_keys($manifest['requires']) as $dependency) { $check($dependency); }
    };
    $check($id);
}

/** Navigation hint only; all execution paths independently repeat the service gate. */
function pl_module_available(int $actorId, int $companyId, int $bookId, string $id): bool
{
    try { pl_require_module($actorId, $companyId, $bookId, $id, false); return true; }
    catch (DomainException) { return false; }
}

function pl_set_company_module(int $actorId, int $companyId, string $id, bool $enabled, int $revision, string $digest, string $reason, string $key): array
{
    pl_demo_require_setup_action();
    $key = pl_request_key($key); $reason = pl_ledger_text($reason, 'Reason', 500);
    $registry = pl_module_registry();
    $manifest = $registry[$id] ?? null;
    if (!$manifest || !$manifest['optional']) { throw new DomainException('The accounting core is required; choose a known optional module.'); }
    if ($revision < 0 || !hash_equals($manifest['digest'], $digest)) { throw new DomainException('The module package changed. Reload and review the installed version.'); }
    $hash = hash('sha256', json_encode([$actorId, $id, $enabled, $revision, $digest, $reason], JSON_THROW_ON_ERROR));
    return pl_ledger_transaction(function () use ($actorId, $companyId, $id, $enabled, $revision, $reason, $key, $hash, $manifest, $registry): array {
        $member = pl_require_company_access($actorId, $companyId, true);
        if (!pl_user_can($actorId, $companyId, 'modules.manage')) { throw new DomainException('Your role cannot change modules for this company.'); }
        DB::queryFirstField('SELECT id FROM pl_companies WHERE id = %i FOR UPDATE', $companyId);
        $prior = DB::queryFirstRow('SELECT payload_hash, result_json FROM pl_module_actions WHERE company_id = %i AND request_key = %s FOR UPDATE', $companyId, $key);
        if ($prior) {
            if (!hash_equals($prior['payload_hash'], $hash)) { throw new DomainException('This module request already recorded different content.'); }
            return json_decode($prior['result_json'], true, 32, JSON_THROW_ON_ERROR);
        }
        $before = pl_module_state($companyId, $id);
        if ($before['revision'] !== $revision) { throw new DomainException('Someone changed this module. Reload its current revision.'); }
        if ($enabled) {
            pl_module_installed($manifest);
            foreach (array_keys($manifest['requires']) as $dependency) {
                pl_module_installed($registry[$dependency]);
                if ($registry[$dependency]['optional']) {
                    $state = pl_module_state($companyId, $dependency);
                    if (!$state['enabled'] || $state['version'] !== $registry[$dependency]['version'] || $state['manifest_hash'] !== $registry[$dependency]['digest']) { throw new DomainException('Enable or upgrade the required dependency first.'); }
                }
            }
        } else {
            foreach ($registry as $dependent => $definition) {
                if (isset($definition['requires'][$id]) && pl_module_state($companyId, $dependent)['enabled']) { throw new DomainException('Disable dependent modules first.'); }
            }
        }
        $result = ['module_id' => $id, 'enabled' => $enabled, 'version' => $manifest['version'], 'manifest_hash' => $manifest['digest'], 'revision' => $revision + 1];
        DB::insertUpdate('pl_company_modules', ['company_id' => $companyId] + $result + ['updated_by' => $actorId, 'updated_at' => gmdate('Y-m-d H:i:s')]);
        $action = !$enabled ? 'disabled' : ($before['enabled'] ? 'upgraded' : 'enabled');
        DB::insert('pl_module_actions', ['company_id' => $companyId, 'module_id' => $id, 'actor_id' => $actorId, 'request_key' => $key, 'payload_hash' => $hash,
            'action' => $action, 'reason' => $reason, 'before_state' => json_encode($before, JSON_THROW_ON_ERROR), 'result_json' => json_encode($result, JSON_THROW_ON_ERROR)]);
        return $result;
    });
}

function pl_module_history(int $actorId, int $companyId): array
{
    pl_require_company_access($actorId, $companyId);
    return DB::query('SELECT a.module_id, a.action, a.reason, a.recorded_at, u.display_name FROM pl_module_actions a JOIN pl_users u ON u.id = a.actor_id WHERE a.company_id = %i ORDER BY a.id DESC LIMIT 50', $companyId);
}
