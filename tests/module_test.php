<?php
declare(strict_types=1);

function module_change(array $f, bool $enabled, int $revision, string $key = 'module-change'): array
{
    return pl_set_company_module($f['actor_id'], $f['company_id'], 'pos-showcase', $enabled, $revision,
        pl_module_registry()['pos-showcase']['digest'], 'Sample owner decision', $key);
}

test('core-only companies post report export and reverse with every optional module disabled', function (): void {
    $f = ledger_fixture(); $today = gmdate('Y-m-d');
    assert_same(false, pl_module_available($f['actor_id'], $f['company_id'], $f['book_id'], 'pos-showcase'));
    assert_throws(fn() => pl_checkout_pos($f['actor_id'], $f['company_id'], $f['book_id'], pos_input()), DomainException::class, 'disabled');
    $quote = pos_input(); unset($quote['cash_received']);
    assert_throws(fn() => pl_review_pos($f['actor_id'], $f['company_id'], $f['book_id'], $quote), DomainException::class, 'disabled');
    $draft = pl_save_general_draft($f['actor_id'], $f['company_id'], $f['book_id'], core_general_input($f));
    $posted = pl_post_general_draft($f['actor_id'], $f['company_id'], $f['book_id'], $draft['id'], 1);
    assert_same('posted', $posted['status']);
    assert_true(str_contains(pl_export_report($f['actor_id'], $f['company_id'], $f['book_id'], 'trial-balance', '2026-12-31')['csv'], '1000.0001'));
    pl_reverse_general_draft($f['actor_id'], $f['company_id'], $f['book_id'], $draft['id'], $today, 'Sample core-only correction');
    assert_same('0.0000', pl_trial_balance($f['actor_id'], $f['company_id'], $f['book_id'])['total_debit']);
});

test('module enable disable and reenable retain POS sources exact receipts and accounting corrections', function (): void {
    $f = ledger_fixture(); $today = gmdate('Y-m-d');
    $first = module_change($f, true, 0);
    assert_same(1, $first['revision']);
    // MySQL JSON normalizes object key order; the durable values must be identical.
    assert_true($first == module_change($f, true, 0));
    assert_throws(fn() => module_change($f, false, 0), DomainException::class, 'different');
    $sale = pl_checkout_pos($f['actor_id'], $f['company_id'], $f['book_id'], pos_input());
    $original = DB::queryFirstRow('SELECT * FROM pl_pos_sales WHERE document_id = %i', $sale['document_id']);
    module_change($f, false, 1, 'disable');
    assert_same(false, pl_module_available($f['actor_id'], $f['company_id'], $f['book_id'], 'pos-showcase'));
    assert_throws(fn() => pl_checkout_pos($f['actor_id'], $f['company_id'], $f['book_id'], pos_input()), DomainException::class, 'disabled');
    assert_same('12.7500', pl_get_pos_receipt($f['actor_id'], $f['company_id'], $f['book_id'], $sale['document_id'])['total']);
    pl_reverse_document($f['actor_id'], $f['company_id'], $f['book_id'], $sale['document_id'], $today, 'Sample full-sale correction');
    assert_same($original, DB::queryFirstRow('SELECT * FROM pl_pos_sales WHERE document_id = %i', $sale['document_id']));
    assert_same('0.0000', pl_trial_balance($f['actor_id'], $f['company_id'], $f['book_id'])['total_debit']);
    assert_same(3, module_change($f, true, 2, 'reenable')['revision']);
    assert_same(3, count(pl_module_history($f['actor_id'], $f['company_id'])));
    assert_throws(fn() => DB::update('pl_module_actions', ['reason' => 'Rewrite'], 'company_id = %i', $f['company_id']));
    assert_throws(fn() => DB::delete('pl_module_actions', 'company_id = %i', $f['company_id']));
});

test('module changes enforce owner identity scope freshness compatible installation and rollback', function (): void {
    $f = ledger_fixture(); $other = ledger_fixture(); $m = pl_module_registry()['pos-showcase'];
    assert_throws(fn() => pl_set_company_module($other['actor_id'], $f['company_id'], 'pos-showcase', true, 0, $m['digest'], 'Denied', 'denied'), DomainException::class);
    foreach (['accountant', 'viewer'] as $role) {
        DB::insertUpdate('pl_company_members', ['company_id' => $f['company_id'], 'user_id' => $other['actor_id'], 'role' => $role]);
        assert_throws(fn() => pl_set_company_module($other['actor_id'], $f['company_id'], 'pos-showcase', true, 0, $m['digest'], 'Denied', 'denied'), DomainException::class);
    }
    assert_throws(fn() => pl_set_company_module($f['actor_id'], $f['company_id'], 'core', false, 0, $m['digest'], 'Denied', 'denied'), DomainException::class, 'required');
    assert_throws(fn() => pl_set_company_module($f['actor_id'], $f['company_id'], 'pos-showcase', true, 0, str_repeat('0', 64), 'Stale preview', 'stale'), DomainException::class, 'changed');
    DB::startTransaction();
    try {
        DB::update('pl_schema_migrations', ['status' => 'applying'], 'version = %s', '004_pos_showcase');
        assert_throws(fn() => module_change($f, true, 0), DomainException::class, 'incomplete');
        assert_same(0, pl_module_state($f['company_id'], 'pos-showcase')['revision']);
    } finally { DB::rollback(); }
    DB::query("CREATE TRIGGER pl_test_module_failure BEFORE INSERT ON pl_module_actions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sample module audit failure'");
    try {
        assert_throws(fn() => module_change($f, true, 0), MeekroDBException::class);
        assert_same(0, pl_module_state($f['company_id'], 'pos-showcase')['revision']);
    } finally { DB::query('DROP TRIGGER pl_test_module_failure'); }
    module_change($f, true, 0);
    DB::update('pl_company_modules', ['version' => '0.0.1'], 'company_id = %i', $f['company_id']);
    assert_throws(fn() => pl_require_module($f['actor_id'], $f['company_id'], $f['book_id'], 'pos-showcase'), DomainException::class, 'upgrade');
    assert_same(2, module_change($f, true, 1, 'reviewed-upgrade')['revision']);
    assert_throws(fn() => module_change($f, false, 1, 'stale-revision'), DomainException::class, 'revision');
    DB::update('pl_users', ['is_active' => 0], 'id = %i', $f['actor_id']);
    assert_throws(fn() => pl_require_module($f['actor_id'], $f['company_id'], $f['book_id'], 'pos-showcase'), DomainException::class);
});

test('module manifests reject unknown contracts missing dependencies capability collisions and cycles', function (): void {
    $registry = pl_module_registry();
    pl_validate_module_registry($registry);
    $bad = $registry; $bad['pos-showcase']['contract'] = 2;
    assert_throws(fn() => pl_validate_module_registry($bad), DomainException::class);
    $bad = $registry; $bad['pos-showcase']['requires']['core'] = '2.0.0';
    assert_throws(fn() => pl_validate_module_registry($bad), DomainException::class, 'incompatible');
    $bad = $registry; $bad['pos-showcase']['capabilities'][] = 'core.accounting';
    assert_throws(fn() => pl_validate_module_registry($bad), DomainException::class, 'multiple owners');
    $bad = $registry; $bad['core']['requires']['pos-showcase'] = '1.0.0';
    assert_throws(fn() => pl_validate_module_registry($bad), DomainException::class, 'cycle');
    $bad = $registry; $bad['pos-showcase']['migrations'][] = '../private';
    assert_throws(fn() => pl_validate_module_registry($bad), DomainException::class, 'migration');
});

test('concurrent module retries create one audited decision and current reads observe disablement', function (): void {
    $f = ledger_fixture();
    $job = ['mode' => 'module_set', 'fixture' => $f, 'enabled' => true, 'revision' => 0, 'key' => 'parallel-module'];
    $results = ledger_race([$job, $job]);
    assert_same(1, $results[0]['id']); assert_same(1, $results[1]['id']);
    assert_same(1, count(pl_module_history($f['actor_id'], $f['company_id'])));
    DB::startTransaction();
    try {
        DB::query('SELECT id FROM pl_users LIMIT 1');
        ledger_race([['mode' => 'module_set', 'fixture' => $f, 'enabled' => false, 'revision' => 1, 'key' => 'parallel-disable']]);
        assert_throws(fn() => pl_checkout_pos($f['actor_id'], $f['company_id'], $f['book_id'], pos_input()), DomainException::class, 'disabled');
    } finally { DB::rollback(); }
});

/** Enables every optional dependency of $id (deepest first), then $id itself, tracking revisions already granted in $state. */
function module_matrix_enable(array $f, string $id, array $registry, array &$state): void
{
    $manifest = $registry[$id];
    foreach (array_keys($manifest['requires']) as $dependency) {
        if ($registry[$dependency]['optional']) { module_matrix_enable($f, $dependency, $registry, $state); }
    }
    if ($state[$id] ?? false) { return; }
    pl_set_company_module($f['actor_id'], $f['company_id'], $id, true, 0, $registry[$id]['digest'], 'Sample module-matrix enable', bin2hex(random_bytes(16)));
    $state[$id] = true;
}

/** Core-only proof that does not depend on any optional module: post, reverse, and reconcile a general journal. */
function module_matrix_assert_core_works(array $f): void
{
    $today = gmdate('Y-m-d');
    $draft = pl_save_general_draft($f['actor_id'], $f['company_id'], $f['book_id'], core_general_input($f));
    $posted = pl_post_general_draft($f['actor_id'], $f['company_id'], $f['book_id'], $draft['id'], 1);
    assert_same('posted', $posted['status']);
    pl_reverse_general_draft($f['actor_id'], $f['company_id'], $f['book_id'], $draft['id'], $today, 'Sample core-only correction');
    assert_same('0.0000', pl_trial_balance($f['actor_id'], $f['company_id'], $f['book_id'])['total_debit']);
}

test('every optional bundled module enables alone disables and re-enables while core-only operation keeps working', function (): void {
    $registry = pl_module_registry();
    foreach ($registry as $id => $manifest) {
        if (!$manifest['optional']) { continue; }
        $f = ledger_fixture();
        module_matrix_assert_core_works($f);
        $state = [];
        module_matrix_enable($f, $id, $registry, $state);
        assert_same(true, pl_module_available($f['actor_id'], $f['company_id'], $f['book_id'], $id));
        module_matrix_assert_core_works($f);
        $revision = pl_module_state($f['company_id'], $id)['revision'];
        pl_set_company_module($f['actor_id'], $f['company_id'], $id, false, $revision, $manifest['digest'], 'Sample module-matrix disable', bin2hex(random_bytes(16)));
        assert_same(false, pl_module_available($f['actor_id'], $f['company_id'], $f['book_id'], $id));
        module_matrix_assert_core_works($f);
        $revision = pl_module_state($f['company_id'], $id)['revision'];
        pl_set_company_module($f['actor_id'], $f['company_id'], $id, true, $revision, $manifest['digest'], 'Sample module-matrix reenable', bin2hex(random_bytes(16)));
        assert_same(true, pl_module_available($f['actor_id'], $f['company_id'], $f['book_id'], $id));
        module_matrix_assert_core_works($f);
    }
});
