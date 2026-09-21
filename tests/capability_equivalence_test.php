<?php
declare(strict_types=1);

/**
 * The equivalence proof for release plan 1.2, milestone M7.
 *
 * Until this milestone, roughly fourteen places asked `$member['role'] !== 'owner'` and refused.
 * Those places now ask pl_user_can($actor, $company, '<capability>'). This test exists to prove
 * that the SWAP CHANGED NOTHING: for every gate converted, and for every kind of actor —
 * owner, accountant, viewer, outsider and an inactive account — the old literal check and the new
 * capability check must give the same answer.
 *
 * It is parametrised rather than written out: one table of (capability, the literal check it
 * replaced), one table of actors, and the cross product is asserted. A gate added to the
 * conversion is added to the table, not copied as another block.
 *
 * "The same answer" is a tri-state, not a boolean, because the old checks did not all fail the
 * same way: an outsider hit pl_require_company_access() and got a DomainException, while a viewer
 * passed the membership gate and then failed the role check. Both outcomes are captured, so a
 * conversion that quietly turned a refusal into a permission — or a membership failure into a
 * polite "no" — fails here.
 */

/** @return array<string, string> capability => the 1.1 role predicate it replaced */
function equivalence_capability_map(): array
{
    // Every value is the literal check that stood at the call site before M7. Each was
    // `$member['role'] !== 'owner'` (refuse) or, for the read-only gates, membership alone.
    return [
        'modules.manage' => 'owner',
        'navigation.manage' => 'owner',
        'numbering.manage' => 'owner',
        'periods.reopen' => 'owner',
        'opening.manage' => 'owner',
        'openitem.activate' => 'owner',
        'tax.settings.manage' => 'owner',
        'policy.manage' => 'owner',
        'journal.reverse_backdated' => 'owner',
        'connections.manage_all' => 'owner',
        // The two seams M4 wrote to be replaced by a capability, with the interim rule it named.
        'cost.view' => 'owner',
        'settlement.approve' => 'owner',
        // The read/write boundary, unchanged, expressed as capabilities.
        'company.write' => 'owner|accountant',
        'company.read' => 'owner|accountant|viewer',
    ];
}

/**
 * The 1.1 answer: what the literal check would have said. 'no-access' is the membership gate
 * refusing before any role check ran.
 */
function equivalence_before(string $role, bool $active, string $predicate): string
{
    if (!$active || $role === '') {
        return 'no-access';
    }
    return in_array($role, explode('|', $predicate), true) ? 'allowed' : 'denied';
}

/** The 1.2 answer: the membership gate first, exactly as every converted call site still does. */
function equivalence_after(int $actorId, int $companyId, string $capability): string
{
    try {
        pl_require_company_access($actorId, $companyId);
    } catch (DomainException) {
        return 'no-access';
    }
    return pl_user_can($actorId, $companyId, $capability) ? 'allowed' : 'denied';
}

test('pl_user_can() gives every actor exactly the authorisation the literal role checks gave', function (): void {
    $suffix = bin2hex(random_bytes(6));
    $password = 'Sample equivalence passphrase 471!';
    $owner = pl_create_user('eq-owner-' . $suffix . '@example.invalid', 'Equivalence owner', $password);
    $accountant = pl_create_user('eq-acc-' . $suffix . '@example.invalid', 'Equivalence accountant', $password);
    $viewer = pl_create_user('eq-view-' . $suffix . '@example.invalid', 'Equivalence viewer', $password);
    $outsider = pl_create_user('eq-out-' . $suffix . '@example.invalid', 'Equivalence outsider', $password);
    $inactive = pl_create_user('eq-off-' . $suffix . '@example.invalid', 'Equivalence inactive', $password);
    $company = pl_create_company($owner, 'Equivalence fixture ' . $suffix, 'USD', '2026-01-01');
    $companyId = (int) $company['company_id'];

    $roles = [];
    foreach (['owner', 'accountant', 'viewer'] as $slug) {
        $roles[$slug] = (int) DB::queryFirstField('SELECT id FROM pl_roles WHERE company_id IS NULL AND slug = %s', $slug);
        assert_true($roles[$slug] > 0, 'The protected system role ' . $slug . ' exists.');
    }
    foreach ([[$accountant, 'accountant'], [$viewer, 'viewer'], [$inactive, 'accountant']] as [$userId, $slug]) {
        DB::insert('pl_company_members', ['company_id' => $companyId, 'user_id' => $userId, 'role' => $slug, 'role_id' => $roles[$slug]]);
    }
    DB::update('pl_users', ['is_active' => 0], 'id = %i', $inactive);
    pl_capability_cache_reset();

    // (actor id, the 1.1 ENUM role they hold, whether the account is active)
    $actors = [
        'owner' => [$owner, 'owner', true],
        'accountant' => [$accountant, 'accountant', true],
        'viewer' => [$viewer, 'viewer', true],
        'outsider' => [$outsider, '', true],
        'inactive' => [$inactive, 'accountant', false],
    ];

    $mismatches = [];
    $checked = 0;
    foreach (equivalence_capability_map() as $capability => $predicate) {
        foreach ($actors as $name => [$actorId, $role, $active]) {
            $before = equivalence_before($role, $active, $predicate);
            $after = equivalence_after($actorId, $companyId, $capability);
            ++$checked;
            if ($before !== $after) {
                $mismatches[] = $capability . ' / ' . $name . ': was ' . $before . ', is ' . $after;
            }
        }
    }
    assert_same([], $mismatches, 'A converted gate changed who may do it.');
    assert_same(count(equivalence_capability_map()) * count($actors), $checked, 'Every capability was checked against every actor.');
    assert_true($checked >= 70, 'The cross product covers every converted gate and every kind of actor.');
});

test('the converted services themselves refuse and allow exactly as they did', function (): void {
    // The map above compares the predicate; this compares the real services, so a call site that
    // was converted to the WRONG capability is caught as well as one that was not converted.
    $suffix = bin2hex(random_bytes(6));
    $password = 'Sample service equivalence 583!';
    $owner = pl_create_user('sv-owner-' . $suffix . '@example.invalid', 'Service owner', $password);
    $accountant = pl_create_user('sv-acc-' . $suffix . '@example.invalid', 'Service accountant', $password);
    $viewer = pl_create_user('sv-view-' . $suffix . '@example.invalid', 'Service viewer', $password);
    $outsider = pl_create_user('sv-out-' . $suffix . '@example.invalid', 'Service outsider', $password);
    $company = pl_create_company($owner, 'Service fixture ' . $suffix, 'USD', '2026-01-01');
    $companyId = (int) $company['company_id'];
    $bookId = (int) $company['book_id'];
    $roles = [];
    foreach (['accountant', 'viewer'] as $slug) {
        $roles[$slug] = (int) DB::queryFirstField('SELECT id FROM pl_roles WHERE company_id IS NULL AND slug = %s', $slug);
    }
    DB::insert('pl_company_members', ['company_id' => $companyId, 'user_id' => $accountant, 'role' => 'accountant', 'role_id' => $roles['accountant']]);
    DB::insert('pl_company_members', ['company_id' => $companyId, 'user_id' => $viewer, 'role' => 'viewer', 'role_id' => $roles['viewer']]);
    pl_capability_cache_reset();

    // Each entry: the service, and who could run it in 1.1.
    $services = [
        'periods.reopen' => static fn (int $actorId): mixed => pl_period_require_write($actorId, $companyId, true),
        'settlement.approve' => static fn (int $actorId): mixed => pl_van_settlement_require_approver($actorId, $companyId),
        'cost.view' => static fn (int $actorId): mixed => pl_stock_cost_visible($actorId, $companyId, $bookId) ?: throw new DomainException('Cost hidden.'),
    ];
    foreach ($services as $capability => $service) {
        foreach ([[$owner, true], [$accountant, false], [$viewer, false], [$outsider, false]] as [$actorId, $allowed]) {
            $ran = true;
            try {
                $service($actorId);
            } catch (DomainException) {
                $ran = false;
            }
            assert_same($allowed, $ran, 'Service gate ' . $capability . ' for user ' . $actorId . ' changed.');
        }
    }
});

test('a custom role can be given exactly one owner-only permission without becoming an owner', function (): void {
    // The point of the conversion: what used to be "owner, or nobody" is now divisible.
    $suffix = bin2hex(random_bytes(6));
    $password = 'Sample custom role passphrase 662!';
    $owner = pl_create_user('cr-owner-' . $suffix . '@example.invalid', 'Custom role owner', $password);
    $approver = pl_create_user('cr-appr-' . $suffix . '@example.invalid', 'Custom role approver', $password);
    $company = pl_create_company($owner, 'Custom role fixture ' . $suffix, 'USD', '2026-01-01');
    $companyId = (int) $company['company_id'];
    $bookId = (int) $company['book_id'];
    $role = pl_save_role($owner, $companyId, [
        'name' => 'Settlement approver ' . $suffix,
        'description' => 'Approves van days and nothing else that an owner does.',
        'reason' => 'Milestone M7 equivalence test.',
        'capabilities' => ['company.read', 'company.write', 'settlement.approve'],
    ]);
    DB::insert('pl_company_members', ['company_id' => $companyId, 'user_id' => $approver, 'role' => 'accountant', 'role_id' => $role['id']]);
    pl_capability_cache_reset();

    assert_true(pl_user_can($approver, $companyId, 'settlement.approve'), 'The custom role carries the one permission it was given.');
    assert_true(!pl_user_can($approver, $companyId, 'cost.view'), 'It does not carry cost visibility.');
    assert_true(!pl_user_can($approver, $companyId, 'modules.manage'), 'It does not carry module administration.');
    assert_true(!pl_user_can($approver, $companyId, 'periods.reopen'), 'It does not carry period reopening.');
    // And the service agrees with the capability, not with the ENUM the membership still mirrors.
    pl_van_settlement_require_approver($approver, $companyId);
    assert_same(false, pl_stock_cost_visible($approver, $companyId, $bookId), 'Cost stays hidden from the custom role.');
    assert_same('accountant', DB::queryFirstField('SELECT role FROM pl_company_members WHERE company_id = %i AND user_id = %i', $companyId, $approver),
        'The 1.1 ENUM is still mirrored for a custom role, as a writing non-owner.');
});
