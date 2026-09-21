<?php
declare(strict_types=1);

/**
 * Capabilities and roles (release plan 1.2, milestone M7; owner decisions B17, B44, B53, B58).
 *
 * WHAT REPLACED WHAT
 * ------------------
 * Until this milestone, authorisation was one membership gate — pl_require_company_access(),
 * which is still the boundary and is not going anywhere — plus roughly fourteen literal
 * `$member['role'] !== 'owner'` checks scattered across the function files. Those literal checks
 * are now pl_user_can($actor, $company, '<capability>'). The membership gate still runs first at
 * every one of those call sites, so a non-member still gets the same DomainException it always
 * got; pl_user_can() answers a yes/no question for someone who is already through the gate, and
 * answers "no" rather than throwing for anyone who is not.
 *
 * The three-value ENUM on pl_company_members is still written (pl_assign_company_role() writes
 * both it and role_id) and is still what pl_require_company_access() reads for the read/write
 * boundary. That mirror is deliberate and lasts through 1.2; 1.3 drops the ENUM. Nothing in 1.2
 * reads the ENUM to make a *permission* decision beyond read-versus-write.
 *
 * INSTALLATION SCOPE
 * ------------------
 * A capability is company-scoped or installation-scoped. Company-scoped capabilities come from
 * the role a user holds in that company (B17: roles are assigned per company). Installation-scoped
 * ones — `installation.admin` above all (B44) — cannot come from a per-company role, because the
 * owner of company 2 must not inherit the installation rights of the owner of company 1. They are
 * held per user, in pl_user_meta under `capabilities:installation`, which is precisely where
 * WordPress keeps `wp_capabilities`. See pl_user_installation_grants().
 *
 * INERT GRANTS
 * ------------
 * A capability carries its owner (`core`, a module, or from M8 a plugin). A grant of a
 * module-owned capability is retained when the company disables that module, and simply stops
 * being effective until the module is enabled again. Nothing is deleted, so re-enabling a module
 * restores exactly the permissions that were configured before.
 */

/**
 * The core capability catalogue: the capabilities the application itself owns.
 *
 * `scope` is 'company' unless the act belongs to the installation rather than to a set of books.
 * Every entry here is registered into pl_capabilities by pl_sync_capability_catalogue(); a
 * module or plugin adds its own through its manifest's optional `grants` map.
 *
 * @return array<string, array{label: string, description: string, scope: string}>
 */
function pl_capability_catalogue(): array
{
    return [
        // The membership boundary, expressed as capabilities so a custom role can be built from
        // them. These two are what pl_require_company_access() already enforces.
        'company.read' => ['label' => 'Read this company', 'description' => 'Open records and reports for this company.', 'scope' => 'company'],
        'company.write' => ['label' => 'Record accounting entries', 'description' => 'Create, edit and post documents and journals.', 'scope' => 'company'],

        // Settings that change how the books behave. Every one of these replaced a literal
        // owner-only check; see docs/DEVELOPMENT.md for the call site of each.
        'modules.manage' => ['label' => 'Enable and disable modules', 'description' => 'Review a module package and turn it on or off for this company.', 'scope' => 'company'],
        'navigation.manage' => ['label' => 'Change navigation', 'description' => 'Show or hide receivables and payables in the sidebar.', 'scope' => 'company'],
        'numbering.manage' => ['label' => 'Change document numbering', 'description' => 'Edit the running number series for a document type.', 'scope' => 'company'],
        'periods.reopen' => ['label' => 'Reopen a closed period', 'description' => 'Reopen an accounting period that was closed.', 'scope' => 'company'],
        'opening.manage' => ['label' => 'Manage opening balances and conversion', 'description' => 'Prepare, review and confirm opening balances, opening debt and opening stock.', 'scope' => 'company'],
        'openitem.activate' => ['label' => 'Activate open-item accounting', 'description' => 'Switch this company to open-item receivables and payables.', 'scope' => 'company'],
        'tax.settings.manage' => ['label' => 'Change tax settings', 'description' => 'Change the tax price mode for this company.', 'scope' => 'company'],
        'policy.manage' => ['label' => 'Change accounting policies and the company profile', 'description' => 'Edit trading-document policies and the company profile used on printed documents.', 'scope' => 'company'],
        'journal.reverse_backdated' => ['label' => 'Post a backdated reversal', 'description' => 'Reverse a posted entry on its original date rather than today.', 'scope' => 'company'],
        'connections.manage_all' => ['label' => 'Manage every connection', 'description' => 'See and revoke API connections created by any member, not only your own.', 'scope' => 'company'],

        // The two seams M4 wrote to be replaced by a capability.
        'cost.view' => ['label' => 'See cost and margin', 'description' => 'See purchase cost, carrying value and margin. Cost is a trade secret in many businesses (owner decision B58).', 'scope' => 'company'],
        'settlement.approve' => ['label' => 'Approve a van settlement', 'description' => 'Approve a reconciled van day. Approving is a distinct act from recording.', 'scope' => 'company'],

        // 1.2 M8a: the ownership register (B63) and the related-party marker (B72 as narrowed by
        // B74). The marker is split into two capabilities on purpose. Reading one says that a
        // named customer or supplier is a director, a director's spouse or a company a director
        // controls, which is exactly the sensitive fact B58 keeps from whoever manages customers;
        // and the person who prepares the disclosure is usually not the person who decides who is
        // key management personnel, so the read is grantable without the write.
        'ownership.manage' => ['label' => 'Maintain the ownership register', 'description' => 'Record members, officers, share classes and share ledger events, and the company\'s legal form and registration.', 'scope' => 'company'],
        'relatedparty.view' => ['label' => 'See related-party information', 'description' => 'Read the related-party markers and the related-party and director loan reports. Restricted, because a marker names a customer or supplier as a director or a director\'s family (owner decision B58).', 'scope' => 'company'],
        'relatedparty.manage' => ['label' => 'Record a related-party marker', 'description' => 'Designate a customer or supplier as key management personnel, a close family member of one, or an entity either controls. Nobody is related by default; the designation is always affirmative.', 'scope' => 'company'],

        // The Users module itself.
        'users.manage' => ['label' => 'Manage people in this company', 'description' => 'Invite, edit, suspend, reactivate and deactivate the people who can use this company.', 'scope' => 'company'],
        'roles.manage' => ['label' => 'Manage roles', 'description' => 'Create and edit custom roles and their capabilities for this company.', 'scope' => 'company'],
        'reports.cost_settings.manage' => ['label' => 'Choose which reports show cost', 'description' => 'Turn the cost and margin columns on or off per report (owner decision B58).', 'scope' => 'company'],

        // Installation scope (B44). Held per user, never through a per-company role.
        'installation.admin' => ['label' => 'Administer this installation', 'description' => 'Install and activate packages, and administer settings that belong to the whole installation rather than to one business.', 'scope' => 'installation'],
    ];
}

/**
 * What each protected system role may do. These grants reproduce, exactly, the authorisation the
 * application had before this milestone: everything that was an owner-only literal check is on
 * `owner`, `accountant` keeps read and write, `viewer` keeps read. tests/capability_equivalence_test.php
 * proves that claim rather than asserting it.
 *
 * @return array<string, list<string>>
 */
function pl_system_role_grants(): array
{
    $owner = [];
    foreach (pl_capability_catalogue() as $capability => $definition) {
        if ($definition['scope'] === 'company') {
            $owner[] = $capability;
        }
    }
    return [
        'owner' => $owner,
        'accountant' => ['company.read', 'company.write'],
        'viewer' => ['company.read'],
    ];
}

/** The three protected system roles. Their slugs are the three values of the 1.1 role ENUM. */
function pl_system_role_slugs(): array
{
    return ['owner', 'accountant', 'viewer'];
}

/**
 * Capabilities a module manifest declares as its own (contract 1, optional key, exactly like
 * `lang`). A manifest that omits `grants` is byte-identical to the one 1.1 shipped, so its digest
 * does not move and no company is asked to review a module it did not change. The bundled
 * manifests deliberately omit it in 1.2 M7; M8's plugin contract 2 uses it.
 *
 * @return array<string, string> capability => label
 */
function pl_manifest_capability_grants(array $manifest): array
{
    $grants = $manifest['grants'] ?? [];
    if (!is_array($grants)) {
        throw new DomainException('A module capability declaration must be a map of capability to label.');
    }
    $registered = [];
    foreach ($grants as $capability => $label) {
        if (!is_string($capability) || !preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*){1,3}$/D', $capability) || strlen($capability) > 80) {
            throw new DomainException('A capability name is lower-case, dotted and up to 80 characters.');
        }
        if (!is_string($label) || $label === '' || mb_strlen($label, 'UTF-8') > 160) {
            throw new DomainException('A capability needs a label of up to 160 characters.');
        }
        if (isset(pl_capability_catalogue()[$capability])) {
            throw new DomainException('A module cannot re-register a core capability: ' . $capability);
        }
        $registered[$capability] = $label;
    }
    return $registered;
}

/**
 * Register the catalogue and the system roles' grants. Idempotent, and safe to run on every
 * migration: it inserts what is missing, refreshes labels, and NEVER removes a capability row or
 * a grant, because a removed grant is a silent permission change and because a module that is
 * currently disabled must keep its rows (see "inert grants" above).
 */
function pl_sync_capability_catalogue(): void
{
    $definitions = [];
    foreach (pl_capability_catalogue() as $capability => $definition) {
        $definitions[$capability] = $definition + ['owner_type' => 'core', 'owner_id' => ''];
    }
    foreach (pl_module_registry() as $id => $manifest) {
        foreach (pl_manifest_capability_grants($manifest) as $capability => $label) {
            $definitions[$capability] = ['label' => $label, 'description' => '', 'scope' => 'company',
                'owner_type' => 'module', 'owner_id' => $id];
        }
    }
    foreach ($definitions as $capability => $definition) {
        DB::insertUpdate('pl_capabilities', [
            'capability' => $capability, 'owner_type' => $definition['owner_type'], 'owner_id' => $definition['owner_id'],
            'scope' => $definition['scope'], 'label' => $definition['label'], 'description' => $definition['description'],
        ], ['owner_type' => $definition['owner_type'], 'owner_id' => $definition['owner_id'],
            'scope' => $definition['scope'], 'label' => $definition['label'], 'description' => $definition['description']]);
    }
    $ids = pl_capability_ids();
    foreach (pl_system_role_grants() as $slug => $capabilities) {
        $roleId = (int) DB::queryFirstField('SELECT id FROM pl_roles WHERE company_id IS NULL AND slug = %s', $slug);
        if ($roleId < 1) {
            continue;
        }
        foreach ($capabilities as $capability) {
            if (isset($ids[$capability])) {
                DB::query('INSERT IGNORE INTO pl_role_capabilities (role_id, capability_id) VALUES (%i, %i)', $roleId, $ids[$capability]);
            }
        }
    }
}

/** capability name => id, read once per request. */
function pl_capability_ids(bool $refresh = false): array
{
    static $ids = null;
    if ($ids === null || $refresh) {
        $ids = [];
        foreach (DB::query('SELECT id, capability FROM pl_capabilities') as $row) {
            $ids[(string) $row['capability']] = (int) $row['id'];
        }
    }
    return $ids;
}

/** Every registered capability with its owner and scope, for the Roles matrix. */
function pl_list_capabilities(): array
{
    $rows = DB::query('SELECT id, capability, owner_type, owner_id, scope, label, description FROM pl_capabilities ORDER BY scope, capability');
    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
    }
    unset($row);
    return $rows;
}

/** Forget everything pl_user_can() cached. Call after any grant, role or membership change. */
function pl_capability_cache_reset(): void
{
    $cache = &pl_capability_cache();
    $cache = [];
    pl_capability_ids(true);
}

/** @return array<string, array<string, bool>> */
function &pl_capability_cache(): array
{
    static $cache = [];
    return $cache;
}

/**
 * The installation-scoped capabilities held by one user, from pl_user_meta (the WordPress
 * `wp_capabilities` shape). Unparsable or unknown values are ignored rather than trusted.
 *
 * @return list<string>
 */
function pl_user_installation_grants(int $userId): array
{
    if ($userId < 1) {
        return [];
    }
    $raw = DB::queryFirstField('SELECT meta_value FROM pl_user_meta WHERE user_id = %i AND meta_key = %s', $userId, 'capabilities:installation');
    if (!is_string($raw) || $raw === '') {
        return [];
    }
    try {
        $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return [];
    }
    if (!is_array($decoded)) {
        return [];
    }
    $catalogue = pl_capability_ids();
    $granted = [];
    foreach ($decoded as $capability => $enabled) {
        if (is_string($capability) && $enabled === true && isset($catalogue[$capability])) {
            $granted[] = $capability;
        }
    }
    sort($granted, SORT_STRING);
    return $granted;
}

/**
 * Grant or withdraw one installation-scoped capability for one user. The actor must already hold
 * `installation.admin`, except during the one-time seeding of the first installation admin.
 */
function pl_set_installation_grant(int $actorId, int $userId, string $capability, bool $granted, string $reason, bool $seeding = false): array
{
    if (!$seeding) {
        pl_demo_require_setup_action();
        if (!pl_user_can($actorId, 0, 'installation.admin')) {
            throw new DomainException('Only an installation administrator can change installation permissions.');
        }
    }
    $reason = pl_ledger_text($reason, 'Reason', 500, !$seeding);
    if (!isset(pl_capability_catalogue()[$capability]) && !isset(pl_capability_ids()[$capability])) {
        throw new DomainException('Unknown capability.');
    }
    return pl_ledger_transaction(function () use ($actorId, $userId, $capability, $granted, $reason): array {
        DB::queryFirstField('SELECT id FROM pl_users WHERE id = %i FOR UPDATE', $userId);
        $before = pl_user_installation_grants($userId);
        $after = array_values(array_unique(array_merge(
            array_diff($before, [$capability]),
            $granted ? [$capability] : []
        )));
        sort($after, SORT_STRING);
        $payload = [];
        foreach ($after as $name) {
            $payload[$name] = true;
        }
        DB::insertUpdate('pl_user_meta', [
            'user_id' => $userId, 'meta_key' => 'capabilities:installation',
            'meta_value' => json_encode($payload, JSON_THROW_ON_ERROR), 'updated_at' => gmdate('Y-m-d H:i:s'),
        ], ['meta_value' => json_encode($payload, JSON_THROW_ON_ERROR), 'updated_at' => gmdate('Y-m-d H:i:s')]);
        pl_user_audit($actorId, null, $userId, 'capability', $userId, $granted ? 'granted' : 'withdrawn', $reason,
            ['capabilities' => $before], ['capabilities' => $after]);
        pl_capability_cache_reset();
        return $after;
    });
}

/**
 * Seed the first installation administrator (B44): the owner of the first company created on this
 * installation. Idempotent, and a no-op once any user already holds `installation.admin`, so it
 * never quietly promotes somebody on a running installation.
 */
function pl_seed_installation_admin(): ?int
{
    $existing = DB::queryFirstField(
        "SELECT user_id FROM pl_user_meta WHERE meta_key = %s AND meta_value LIKE %s LIMIT 1",
        'capabilities:installation',
        '%"installation.admin":true%'
    );
    if ($existing !== null) {
        return (int) $existing;
    }
    $owner = DB::queryFirstField(
        "SELECT m.user_id FROM pl_company_members m JOIN pl_companies c ON c.id = m.company_id "
        . "WHERE m.role = 'owner' ORDER BY c.id, m.user_id LIMIT 1"
    );
    if ($owner === null) {
        return null;
    }
    $ownerId = (int) $owner;
    foreach (['installation.admin', 'roles.manage'] as $capability) {
        pl_set_installation_grant($ownerId, $ownerId, $capability, true, 'Seeded on the first company owner.', true);
    }
    return $ownerId;
}

/**
 * The membership row plus the resolved role. The ENUM is still read for the read/write boundary;
 * role_id is what carries the capabilities. A membership written before migration 040 and never
 * touched since is backfilled by that migration, so role_id is never null in practice; if one
 * ever were, it falls back to the installation-wide role whose slug matches the ENUM.
 */
function pl_company_member_role(int $actorId, int $companyId): ?array
{
    // Inside a caller-owned transaction the membership is read exactly the way
    // pl_require_company_access() reads it — the same two-table join, FOR SHARE — so a stale
    // REPEATABLE READ snapshot cannot retain a permission that was revoked, and so both engines
    // see the same statement the existing gate already runs on them. Outside a transaction no
    // lock is taken: the read is a plain one, as every other navigation hint is.
    $lock = DB::transactionDepth() > 0 ? ' FOR SHARE' : '';
    $member = DB::queryFirstRow(
        'SELECT m.role, m.role_id FROM pl_company_members m '
        . 'INNER JOIN pl_users u ON u.id = m.user_id '
        . 'WHERE m.company_id = %i AND m.user_id = %i AND u.is_active = 1' . $lock,
        $companyId,
        $actorId
    );
    if (!$member) {
        return null;
    }
    $role = $member['role_id'] === null
        ? null
        : DB::queryFirstRow('SELECT id AS resolved_role_id, slug, name, is_system FROM pl_roles WHERE id = %i', (int) $member['role_id']);
    // A membership written before migration 040 and never touched since is backfilled by that
    // migration, so role_id is never null in practice; if one ever were, the installation-wide
    // role whose slug matches the mirrored ENUM is the answer.
    $role ??= DB::queryFirstRow('SELECT id AS resolved_role_id, slug, name, is_system FROM pl_roles WHERE company_id IS NULL AND slug = %s', (string) $member['role']);
    if (!$role) {
        return null;
    }
    $row = array_replace($member, $role);
    $row['resolved_role_id'] = (int) $row['resolved_role_id'];
    $row['is_system'] = (bool) $row['is_system'];
    return $row;
}

/**
 * The effective capability set for one user in one company: the capabilities of the role they
 * hold there, minus any owned by a module this company has switched off (inert, not removed),
 * plus the installation-scoped capabilities held on their own account.
 *
 * Pass $companyId = 0 to ask only about installation scope.
 *
 * @return array<string, bool>
 */
function pl_user_capabilities(int $actorId, int $companyId): array
{
    $cache = &pl_capability_cache();
    $key = $actorId . ':' . $companyId;
    // The per-request cache is a presentation convenience: a screen asks the same question a dozen
    // times while it renders. It is bypassed inside a transaction, where a service is about to act
    // on the answer and must read the membership as it stands right now, under a share lock.
    $inTransaction = DB::transactionDepth() > 0;
    if (!$inTransaction && isset($cache[$key])) {
        return $cache[$key];
    }
    $effective = [];
    if ($actorId < 1) {
        return $inTransaction ? $effective : ($cache[$key] = $effective);
    }
    foreach (pl_user_installation_grants($actorId) as $capability) {
        $effective[$capability] = true;
    }
    if ($companyId > 0) {
        $member = pl_company_member_role($actorId, $companyId);
        if ($member !== null) {
            $rows = DB::query(
                'SELECT c.capability, c.owner_type, c.owner_id FROM pl_role_capabilities rc '
                . 'INNER JOIN pl_capabilities c ON c.id = rc.capability_id '
                . 'WHERE rc.role_id = %i',
                $member['resolved_role_id']
            );
            foreach ($rows as $row) {
                $ownerType = (string) $row['owner_type'];
                $ownerId = (string) $row['owner_id'];
                // Inert: the grant row stays, the permission does not apply while the module is off.
                if ($ownerType === 'module' && $ownerId !== '' && !pl_module_state($companyId, $ownerId)['enabled']) {
                    continue;
                }
                $effective[(string) $row['capability']] = true;
            }
        }
    }
    return $inTransaction ? $effective : ($cache[$key] = $effective);
}

/**
 * May this actor do this thing in this company? The single authorisation question the application
 * asks. It never throws for a stranger: it answers no. Call sites keep their own
 * pl_require_company_access() gate, which is what turns "not a member" into a refusal.
 *
 * $companyId 0 asks about installation scope only (B44).
 */
function pl_user_can(int $actorId, int $companyId, string $capability): bool
{
    if ($actorId < 1 || $companyId < 0 || $capability === '') {
        return false;
    }
    return pl_user_capabilities($actorId, $companyId)[$capability] ?? false;
}

/** The throwing form, for a service that has already checked membership. */
function pl_require_capability(int $actorId, int $companyId, string $capability, string $message): void
{
    if (!pl_user_can($actorId, $companyId, $capability)) {
        throw new DomainException($message);
    }
}

// ------------------------------------------------------------------------------ role management

/** Every role this company may assign: the installation-wide system roles plus its own. */
function pl_list_roles(int $actorId, int $companyId): array
{
    pl_require_company_access($actorId, $companyId);
    $rows = DB::query(
        'SELECT id, company_id, slug, name, description, is_system, revision FROM pl_roles '
        . 'WHERE company_id IS NULL OR company_id = %i ORDER BY is_system DESC, name, id',
        $companyId
    );
    $grants = [];
    foreach (DB::query(
        'SELECT rc.role_id, c.capability FROM pl_role_capabilities rc '
        . 'INNER JOIN pl_capabilities c ON c.id = rc.capability_id '
        . 'INNER JOIN pl_roles r ON r.id = rc.role_id '
        . 'WHERE r.company_id IS NULL OR r.company_id = %i',
        $companyId
    ) as $row) {
        $grants[(int) $row['role_id']][] = (string) $row['capability'];
    }
    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['company_id'] = $row['company_id'] === null ? null : (int) $row['company_id'];
        $row['is_system'] = (bool) $row['is_system'];
        $row['revision'] = (int) $row['revision'];
        $row['capabilities'] = $grants[$row['id']] ?? [];
        sort($row['capabilities'], SORT_STRING);
        $row['member_count'] = (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_company_members WHERE company_id = %i AND role_id = %i', $companyId, $row['id']);
    }
    unset($row);
    return $rows;
}

function pl_get_role(int $actorId, int $companyId, int $roleId): array
{
    foreach (pl_list_roles($actorId, $companyId) as $role) {
        if ($role['id'] === $roleId) {
            return $role;
        }
    }
    throw new DomainException('This role is not available in this company.');
}

/**
 * Create or edit a custom role for one company. A protected system role is read-only here and in
 * the database (migration 040 installs the two guard triggers), because an installation that can
 * quietly redefine "Owner" cannot answer "who was allowed to do this in March".
 *
 * `installation.admin` can never be granted through a role: it is installation scope, held per
 * user (see the file header).
 */
function pl_save_role(int $actorId, int $companyId, array $input, ?int $roleId = null, ?int $revision = null): array
{
    pl_demo_require_setup_action();
    $name = pl_ledger_text($input['name'] ?? '', 'Role name', 80);
    $description = pl_ledger_text($input['description'] ?? '', 'Description', 500, false);
    $reason = pl_ledger_text($input['reason'] ?? '', 'Reason', 500);
    $capabilities = $input['capabilities'] ?? [];
    if (!is_array($capabilities)) {
        throw new DomainException('Choose the capabilities for this role.');
    }
    $catalogue = pl_capability_ids();
    $wanted = [];
    foreach ($capabilities as $capability) {
        if (!is_string($capability) || !isset($catalogue[$capability])) {
            throw new DomainException('Choose capabilities from the list.');
        }
        $scope = (string) DB::queryFirstField('SELECT scope FROM pl_capabilities WHERE id = %i', $catalogue[$capability]);
        if ($scope !== 'company') {
            throw new DomainException('Installation permissions are held per person, not through a role.');
        }
        $wanted[$capability] = $catalogue[$capability];
    }
    if (!isset($wanted['company.read'])) {
        throw new DomainException('Every role can read the company it belongs to. Keep "Read this company" selected.');
    }
    return pl_ledger_transaction(function () use ($actorId, $companyId, $name, $description, $reason, $wanted, $roleId, $revision): array {
        pl_require_company_access($actorId, $companyId, true);
        pl_require_capability($actorId, $companyId, 'roles.manage', 'Your role cannot change roles in this company.');
        if ($roleId !== null) {
            $existing = DB::queryFirstRow('SELECT id, company_id, slug, name, description, is_system, revision FROM pl_roles WHERE id = %i FOR UPDATE', $roleId);
            if (!$existing || ($existing['company_id'] !== null && (int) $existing['company_id'] !== $companyId)) {
                throw new DomainException('This role is not available in this company.');
            }
            if ((int) $existing['is_system'] === 1) {
                throw new DomainException('The Owner, Accountant and Viewer roles are protected and cannot be edited. Create a custom role instead.');
            }
            if ($revision === null || (int) $existing['revision'] !== $revision) {
                throw new DomainException('Someone changed this role. Reload it and review the current capabilities.');
            }
            $before = pl_get_role($actorId, $companyId, $roleId);
            DB::update('pl_roles', ['name' => $name, 'description' => $description, 'revision' => $revision + 1,
                'updated_at' => gmdate('Y-m-d H:i:s')], 'id = %i', $roleId);
        } else {
            $slug = pl_role_slug($name);
            if (DB::queryFirstField('SELECT id FROM pl_roles WHERE (company_id IS NULL OR company_id = %i) AND slug = %s', $companyId, $slug) !== null) {
                throw new DomainException('A role with that name already exists in this company.');
            }
            DB::insert('pl_roles', ['company_id' => $companyId, 'slug' => $slug, 'name' => $name,
                'description' => $description, 'is_system' => 0, 'revision' => 1, 'created_by' => $actorId]);
            $roleId = (int) DB::insertId();
            $before = null;
        }
        // A role created a moment ago has no capability rows to clear, and the public demo's
        // database account is deliberately SELECT/INSERT/UPDATE only. Clearing unconditionally
        // made creating a custom role impossible there, with nothing wrong but the statement.
        // Editing a role still clears, because that is how a capability is taken away.
        if ($before !== null) {
            DB::query('DELETE FROM pl_role_capabilities WHERE role_id = %i', $roleId);
        }
        foreach ($wanted as $capabilityId) {
            DB::insert('pl_role_capabilities', ['role_id' => $roleId, 'capability_id' => $capabilityId, 'granted_by' => $actorId]);
        }
        pl_capability_cache_reset();
        $after = pl_get_role($actorId, $companyId, $roleId);
        pl_user_audit($actorId, $companyId, null, 'role', $roleId, $before === null ? 'created' : 'updated', $reason, $before, $after);
        return $after;
    });
}

/** A stable machine name for a custom role, unique within its company. */
function pl_role_slug(string $name): string
{
    $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $name));
    $slug = trim($slug, '-');
    if ($slug === '' || strlen($slug) > 60) {
        $slug = 'role-' . substr(hash('sha256', $name), 0, 12);
    }
    if (in_array($slug, pl_system_role_slugs(), true)) {
        $slug .= '-custom';
    }
    return $slug;
}

/**
 * Assign a role in one company, writing BOTH the new role_id and the 1.1 ENUM. A custom role
 * mirrors the ENUM of the closest system role: a role that can write is an 'accountant', a
 * read-only role is a 'viewer'. `owner` is never derived, so the ENUM never gains an owner the
 * new model did not intend.
 */
function pl_assign_company_role(int $actorId, int $companyId, int $userId, int $roleId, string $reason): array
{
    return pl_ledger_transaction(function () use ($actorId, $companyId, $userId, $roleId, $reason): array {
        pl_require_company_access($actorId, $companyId, true);
        pl_require_capability($actorId, $companyId, 'users.manage', 'Your role cannot change who works in this company.');
        $role = pl_get_role($actorId, $companyId, $roleId);
        $before = DB::queryFirstRow('SELECT company_id, user_id, role, role_id FROM pl_company_members WHERE company_id = %i AND user_id = %i FOR UPDATE', $companyId, $userId);
        $enum = pl_role_enum_mirror($role);
        if ($before && $before['role'] === 'owner' && $enum !== 'owner'
            && (int) DB::queryFirstField("SELECT COUNT(*) FROM pl_company_members WHERE company_id = %i AND role = 'owner'", $companyId) < 2) {
            throw new DomainException('This company would be left without an owner. Give someone else the Owner role first.');
        }
        $row = ['company_id' => $companyId, 'user_id' => $userId, 'role' => $enum, 'role_id' => $roleId];
        DB::insertUpdate('pl_company_members', $row, ['role' => $enum, 'role_id' => $roleId]);
        pl_capability_cache_reset();
        pl_user_audit($actorId, $companyId, $userId, 'membership', $userId, $before ? 'updated' : 'created',
            pl_ledger_text($reason, 'Reason', 500, false), $before ?: null, $row);
        return $row;
    });
}

/**
 * The ENUM value a role mirrors, for the 1.2 dual write. Dropped with the ENUM in 1.3.
 * A protected system role maps to its own slug; a custom role maps to accountant or viewer
 * depending on whether it can record entries.
 */
function pl_role_enum_mirror(array $role): string
{
    if ($role['is_system'] && in_array($role['slug'], pl_system_role_slugs(), true)) {
        return (string) $role['slug'];
    }
    return in_array('company.write', $role['capabilities'], true) ? 'accountant' : 'viewer';
}

// --------------------------------------------------------------------- per-report cost settings

/**
 * The reports whose cost and margin columns an Admin can switch off (owner decision B58: cost
 * visibility is an Admin call that varies report by report). A report reaches this list by
 * showing purchase cost, carrying value or margin; M9's counter reports join it there.
 *
 * @return array<string, array{label: string, cost: string, margin: bool}>
 */
function pl_report_cost_reports(): array
{
    return [
        'stock-by-location' => ['label' => 'Stock and value by location', 'cost' => 'Carrying value, average cost and the control-account reconciliation, per location and in total.', 'margin' => false],
        'stock-documents' => ['label' => 'Stock issues, returns and gate passes', 'cost' => 'Carrying value on each document line and its total. Quantities and movements are always shown.', 'margin' => false],
        // 1.2 M9. The settlement's cost totals were the one cost-bearing surface M7 left
        // outside B58, because they feed the approval comparison; they are inside it now, and
        // the approval compares the stored, unmasked sheet instead of the reader's copy.
        'van-settlement' => ['label' => 'Van settlement sheet', 'cost' => 'Cost of the stock loaded, sold and returned on the driver\'s day. Quantities, the reconciliation and the money the driver collected are always shown.', 'margin' => false],
        'counter-pos' => ['label' => 'Counter point of sale', 'cost' => 'Carrying value of the stock on hand and of the sale being rung up. The price, the tender and the change are always shown.', 'margin' => true],
    ];
}

/** The shipped default for a report with no stored row: cost shown, margin hidden. */
function pl_report_cost_defaults(string $reportId): array
{
    if (!isset(pl_report_cost_reports()[$reportId])) {
        throw new DomainException('Unknown report.');
    }
    return ['report_id' => $reportId, 'show_cost' => true, 'show_margin' => false, 'revision' => 0];
}

function pl_report_cost_setting(int $companyId, int $bookId, string $reportId): array
{
    $row = DB::queryFirstRow('SELECT report_id, show_cost, show_margin, revision FROM pl_report_cost_settings WHERE company_id = %i AND book_id = %i AND report_id = %s', $companyId, $bookId, $reportId);
    if (!$row) {
        return pl_report_cost_defaults($reportId);
    }
    return ['report_id' => (string) $row['report_id'], 'show_cost' => (bool) $row['show_cost'],
        'show_margin' => (bool) $row['show_margin'], 'revision' => (int) $row['revision']];
}

/** Every report's current setting, for the Admin screen. */
function pl_list_report_cost_settings(int $actorId, int $companyId, int $bookId): array
{
    pl_require_company_access($actorId, $companyId);
    pl_ledger_book($companyId, $bookId);
    $settings = [];
    foreach (pl_report_cost_reports() as $reportId => $definition) {
        $settings[] = pl_report_cost_setting($companyId, $bookId, $reportId) + $definition;
    }
    return $settings;
}

/**
 * THE question a cost-bearing report asks. Both must say yes: the viewer holds `cost.view`
 * (a trade-secret judgement about the person, B58) AND this report is configured to show cost
 * (a judgement about the report). Default: hidden from anyone without the capability.
 */
function pl_report_cost_visible(int $actorId, int $companyId, int $bookId, string $reportId): bool
{
    if (!pl_user_can($actorId, $companyId, 'cost.view')) {
        return false;
    }
    return pl_report_cost_setting($companyId, $bookId, $reportId)['show_cost'];
}

function pl_report_margin_visible(int $actorId, int $companyId, int $bookId, string $reportId): bool
{
    if (!pl_user_can($actorId, $companyId, 'cost.view')) {
        return false;
    }
    return pl_report_cost_setting($companyId, $bookId, $reportId)['show_margin'];
}

function pl_save_report_cost_setting(int $actorId, int $companyId, int $bookId, string $reportId, bool $showCost, bool $showMargin, int $revision, string $reason): array
{
    pl_demo_require_setup_action();
    $reason = pl_ledger_text($reason, 'Reason', 500);
    if (!isset(pl_report_cost_reports()[$reportId])) {
        throw new DomainException('Choose one of the listed reports.');
    }
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $reportId, $showCost, $showMargin, $revision, $reason): array {
        pl_require_company_access($actorId, $companyId, true);
        pl_ledger_book($companyId, $bookId);
        pl_require_capability($actorId, $companyId, 'reports.cost_settings.manage', 'Your role cannot change which reports show cost.');
        $before = pl_report_cost_setting($companyId, $bookId, $reportId);
        if ($before['revision'] !== $revision) {
            throw new DomainException('Someone changed this report setting. Reload the screen and review it.');
        }
        $after = ['report_id' => $reportId, 'show_cost' => $showCost, 'show_margin' => $showMargin, 'revision' => $revision + 1];
        DB::insertUpdate('pl_report_cost_settings', [
            'company_id' => $companyId, 'book_id' => $bookId, 'report_id' => $reportId,
            'show_cost' => $showCost ? 1 : 0, 'show_margin' => $showMargin ? 1 : 0,
            'revision' => $after['revision'], 'updated_by' => $actorId, 'updated_at' => gmdate('Y-m-d H:i:s'),
        ], ['show_cost' => $showCost ? 1 : 0, 'show_margin' => $showMargin ? 1 : 0,
            'revision' => $after['revision'], 'updated_by' => $actorId, 'updated_at' => gmdate('Y-m-d H:i:s')]);
        $id = (int) DB::queryFirstField('SELECT id FROM pl_report_cost_settings WHERE company_id = %i AND book_id = %i AND report_id = %s', $companyId, $bookId, $reportId);
        pl_core_audit($actorId, $companyId, $bookId, 'report_cost_setting', $id, $before['revision'] === 0 ? 'created' : 'updated', $reason, $before, $after);
        return $after;
    });
}
