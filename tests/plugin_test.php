<?php
declare(strict_types=1);

/**
 * The plugin runtime (release plan 1.2 M8; issue #73; decisions B44 to B47, B52, B77 and B78).
 *
 * The lock discipline is what this suite exists for. Plan decision 15 says filters run in the
 * validation phase before book rows are locked and may veto or alter the draft, actions run after
 * the commit, and no hook runs inside the locked section. Every one of those three is proved
 * below against real postings, including the two failure modes the owner named: a hook that
 * throws must not leave a half-posted entry, and a validation hook that vetoes must refuse
 * cleanly.
 *
 * Packages are built on disk at run time rather than committed as fixtures, because a manifest
 * carries a SHA-256 per file and a committed fixture would break on any checkout whose line
 * endings differ.
 */

require_once dirname(__DIR__) . '/www/phpledger/includes/functions/package_web_functions.php';

function plugin_fixture_root(): string
{
    static $root = null;
    if ($root === null) {
        $root = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/phpledger-packages-' . bin2hex(random_bytes(6));
        if (!mkdir($root, 0700, true) && !is_dir($root)) {
            throw new RuntimeException('The package fixture directory could not be created.');
        }
    }
    putenv('PL_PLUGIN_DIRECTORY=' . $root);
    return $root;
}

function plugin_fixture_rmdir(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        /** @var SplFileInfo $entry */
        $entry->isDir() ? @rmdir((string) $entry->getPathname()) : @unlink((string) $entry->getPathname());
    }
    @rmdir($path);
}

/**
 * A package's entry file returns a closure rather than declaring functions, because the loader
 * uses require(), not require_once(): a package is re-read on every boot, so anything it declares
 * at file scope would be declared twice in one process.
 */
function plugin_fixture_entry(): string
{
    return "<?php\ndeclare(strict_types=1);\nreturn static function (array \$context): void {\n"
        . "    \$GLOBALS['plugin_fixture_loaded'][] = \$context['slug'];\n"
        . "    \$behaviour = \$GLOBALS['plugin_fixture_behaviour'][\$context['slug']] ?? null;\n"
        . "    if (is_callable(\$behaviour)) { \$behaviour(\$context); }\n"
        . "};\n";
}

/**
 * @param array<string, string> $files  relative path => contents, beside the generated plugin.json
 * @param array<string, mixed> $overrides
 * @return array<string, mixed> the manifest as written
 */
function plugin_fixture_write(string $slug, array $overrides = [], array $files = []): array
{
    $root = plugin_fixture_root();
    $base = $root . '/' . $slug;
    plugin_fixture_rmdir($base);
    $files = $files === [] ? ['plugin.php' => plugin_fixture_entry()] : $files;
    $digests = [];
    foreach ($files as $path => $contents) {
        $target = $base . '/' . $path;
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0700, true) && !is_dir(dirname($target))) {
            throw new RuntimeException('The package fixture folder could not be created.');
        }
        file_put_contents($target, $contents);
        $digests[$path] = hash('sha256', $contents);
    }
    $manifest = $overrides + [
        'type' => 'plugin', 'slug' => $slug, 'name' => 'Sample package ' . $slug, 'version' => '1.0.0',
        'contract' => PL_PLUGIN_CONTRACT, 'api' => PL_PLUGIN_API_VERSION,
        'description' => 'A package built by the test suite. It posts nothing and sends nothing.',
        'author' => 'PHP Ledger test suite', 'licence' => 'AGPL-3.0-or-later',
        'adds' => ['A sample extension point registration.'],
        'requires' => [], 'entry' => 'plugin.php',
    ];
    $manifest['files'] = $digests;
    file_put_contents($base . '/plugin.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return $manifest;
}

/** A company whose owner also administers this installation (B44). */
function plugin_admin_fixture(): array
{
    $fixture = ledger_fixture();
    pl_set_installation_grant($fixture['actor_id'], $fixture['actor_id'], 'installation.admin', true, 'Sample test administrator.', true);
    pl_capability_cache_reset();
    return $fixture;
}

function plugin_install_and_activate(array $fixture, string $slug, string $trust = 'verified'): void
{
    $acknowledged = [];
    foreach (array_keys(pl_plugin_acknowledgements()) as $name) {
        $acknowledged[$name] = true;
    }
    // The audit keeps one row per (slug, request key) and never deletes one, so a fixture that
    // installs the same slug again needs a key of its own.
    $key = bin2hex(random_bytes(8));
    pl_plugin_install($fixture['actor_id'], $slug, $trust, 'Sample installation.', 'install-' . $key, $acknowledged);
    pl_plugin_activate($fixture['actor_id'], $slug, 'Sample activation.', 'activate-' . $key);
}

/**
 * A request key that is new on every run. `pl_package_actions` is immutable and holds one row per
 * (slug, request key) for ever, so a fixed key would collide the second time this suite runs
 * against a database that is still warm from the first.
 */
function plugin_key(string $label): string
{
    return $label . '-' . bin2hex(random_bytes(6));
}

/** Remove what an earlier, interrupted run of this suite may have left recorded. */
function plugin_fixture_clean_state(): void
{
    foreach (pl_plugin_records() as $slug => $record) {
        DB::delete('pl_packages', 'slug = %s', $slug);
        DB::delete('pl_plugin_options', 'slug = %s', $slug);
        DB::delete('pl_plugin_migrations', 'slug = %s', $slug);
    }
    DB::query('DROP TABLE IF EXISTS pl_sample_plugin_notes');
}

function plugin_fixture_reset(): void
{
    pl_hook_reset();
    $GLOBALS['plugin_fixture_loaded'] = [];
    $GLOBALS['plugin_fixture_behaviour'] = [];
}

// ------------------------------------------------------------------------- the hooks API

test('the package fixtures start from a clean recorded state', function (): void {
    plugin_fixture_clean_state();
    assert_same([], pl_plugin_records());
});

test('hooks run in priority order, then registration order, and a filter carries its value through', function (): void {
    plugin_fixture_reset();
    $order = [];
    pl_add_action('journal.posted', static function () use (&$order): void { $order[] = 'second at 10'; }, 10);
    pl_add_action('journal.posted', static function () use (&$order): void { $order[] = 'first at 1'; }, 1);
    pl_add_action('journal.posted', static function () use (&$order): void { $order[] = 'third at 10'; }, 10);
    pl_do_action('journal.posted', [[], []]);
    assert_same(['first at 1', 'second at 10', 'third at 10'], $order);

    pl_add_filter('navigation.groups', static fn (array $value): array => $value + ['b' => 2], 20);
    pl_add_filter('navigation.groups', static fn (array $value): array => $value + ['a' => 1], 5);
    assert_same(['a' => 1, 'b' => 2], pl_apply_filters('navigation.groups', [], [[]]));
});

test('a hook registration is refused when its name, priority, owner or kind is wrong', function (): void {
    plugin_fixture_reset();
    assert_throws(fn() => pl_add_action('Journal.Posted', static fn() => null), DomainException::class);
    assert_throws(fn() => pl_add_action('journal.posted', static fn() => null, -1), DomainException::class);
    assert_throws(fn() => pl_add_action('journal.posted', static fn() => null, 10, 'Not A Slug'), DomainException::class);
    // journal.validate is a published filter; registering an action on it is a contract error.
    assert_throws(fn() => pl_add_action('journal.validate', static fn() => null), DomainException::class, 'filter');
    assert_throws(fn() => pl_add_filter('journal.posted', static fn($v) => $v), DomainException::class, 'action');
});

test('a package failure becomes one refusal naming the package, and core keeps its own', function (): void {
    plugin_fixture_reset();
    pl_add_filter('navigation.groups', static function (): array { throw new RuntimeException('Sample internal detail'); }, 10, 'sample-plugin');
    assert_throws(fn() => pl_apply_filters('navigation.groups', [], [[]]), RuntimeException::class, 'sample-plugin');
    // The plugin's own message never reaches the caller; the failure is recorded for the screen.
    $failures = pl_hook_failures();
    assert_same(1, count($failures));
    assert_same('sample-plugin', $failures[0]['owner']);

    plugin_fixture_reset();
    pl_add_filter('navigation.groups', static function (): array { throw new DomainException('Core keeps this message'); }, 10, 'core');
    assert_throws(fn() => pl_apply_filters('navigation.groups', [], [[]]), DomainException::class, 'Core keeps this message');

    plugin_fixture_reset();
    pl_add_filter('navigation.groups', static function (): array { throw new PL_Hook_Veto('The sample package refused this.'); }, 10, 'sample-plugin');
    assert_throws(fn() => pl_apply_filters('navigation.groups', [], [[]]), PL_Hook_Veto::class, 'The sample package refused this.');
});

test('deactivating a package removes its callbacks and leaves everyone else registered', function (): void {
    plugin_fixture_reset();
    $ran = [];
    pl_add_action('journal.posted', static function () use (&$ran): void { $ran[] = 'core'; }, 10, 'core');
    pl_add_action('journal.posted', static function () use (&$ran): void { $ran[] = 'plugin'; }, 10, 'sample-plugin');
    assert_same(2, count(pl_hook_callbacks('journal.posted')));
    assert_same(1, pl_remove_hooks('sample-plugin'));
    pl_do_action('journal.posted', [[], []]);
    assert_same(['core'], $ran);
});

// ------------------------------------------------------- the lock discipline (decision 15)

test('no hook runs while a book row is locked, and the guard clears when the transaction ends', function (): void {
    plugin_fixture_reset();
    $fixture = ledger_fixture();
    $ran = 0;
    pl_add_action('journal.posted', static function () use (&$ran): void { $ran++; }, 10, 'sample-plugin');
    assert_same(false, pl_hook_lock_held());
    pl_ledger_transaction(function () use ($fixture): void {
        assert_same(false, pl_hook_lock_held());
        pl_ledger_book($fixture['company_id'], $fixture['book_id'], true);
        assert_same(true, pl_hook_lock_held());
        assert_throws(fn() => pl_do_action('journal.posted', [[], []]), LogicException::class, 'locked');
        assert_throws(fn() => pl_apply_filters('navigation.groups', [], [[]]), LogicException::class, 'locked');
    });
    assert_same(0, $ran);
    // The database released the row at the commit, so the guard releases with it.
    assert_same(false, pl_hook_lock_held());
    pl_do_action('journal.posted', [[], []]);
    assert_same(1, $ran);
});

test('a rolled-back transaction clears the lock guard and drops the actions it had queued', function (): void {
    plugin_fixture_reset();
    $fixture = ledger_fixture();
    $ran = 0;
    pl_add_action('journal.posted', static function () use (&$ran): void { $ran++; }, 10, 'sample-plugin');
    assert_throws(function () use ($fixture): void {
        pl_ledger_transaction(function () use ($fixture): void {
            pl_ledger_book($fixture['company_id'], $fixture['book_id'], true);
            pl_hook_after_commit('journal.posted', [[], []]);
            throw new DomainException('Sample failure after queuing.');
        });
    }, DomainException::class);
    assert_same(0, $ran, 'An action queued by work that rolled back describes something that never happened.');
    assert_same(false, pl_hook_lock_held());
});

// ------------------------------------------------------------------ the posting flow (B45)

test('a validation filter may alter the draft, and core revalidates whatever it returns', function (): void {
    plugin_fixture_reset();
    $fixture = ledger_fixture();
    pl_add_filter('journal.validate', static function (array $payload, array $context): array {
        assert_same(0, count(array_diff(['actor_id', 'company_id', 'book_id', 'reversal_of_id'], array_keys($context))));
        $payload['description'] = 'Rewritten by the sample package';
        return $payload;
    }, 10, 'sample-plugin');
    $journal = pl_post_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], ledger_payload($fixture, '25.0000'));
    assert_same('Rewritten by the sample package', (string) $journal['description']);

    // An unbalanced rewrite is refused by the core rules, not by the package's own care.
    plugin_fixture_reset();
    pl_add_filter('journal.validate', static function (array $payload): array {
        $payload['lines'][0]['debit'] = '99.0000';
        return $payload;
    }, 10, 'sample-plugin');
    assert_throws(fn() => pl_post_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], ledger_payload($fixture, '25.0000', 'sample-unbalanced')),
        DomainException::class);
    assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id = %i AND idempotency_key = %s', $fixture['book_id'], 'sample-unbalanced'));
});

test('a validation filter cannot change the identity of the posting it is reviewing', function (): void {
    plugin_fixture_reset();
    $fixture = ledger_fixture();
    foreach (['idempotency_key' => 'a-different-key', 'source_type' => 'journal', 'source_reference' => 'elsewhere', 'currency' => 'EUR'] as $field => $value) {
        plugin_fixture_reset();
        pl_add_filter('journal.validate', static function (array $payload) use ($field, $value): array {
            $payload[$field] = $value;
            return $payload;
        }, 10, 'sample-plugin');
        assert_throws(fn() => pl_post_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], ledger_payload($fixture)),
            DomainException::class, 'cannot change');
    }
    plugin_fixture_reset();
    pl_add_filter('journal.validate', static fn (): string => 'not a journal', 10, 'sample-plugin');
    assert_throws(fn() => pl_post_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], ledger_payload($fixture)),
        DomainException::class, 'not a journal');
});

test('a vetoing validation hook refuses cleanly and leaves nothing behind', function (): void {
    plugin_fixture_reset();
    $fixture = ledger_fixture();
    $before = (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id = %i', $fixture['book_id']);
    $beforeLines = (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journal_lines WHERE book_id = %i', $fixture['book_id']);
    pl_add_filter('journal.validate', static function (): array {
        throw new PL_Hook_Veto('This posting needs a cost centre before the sample package will allow it.');
    }, 10, 'sample-plugin');
    $payload = ledger_payload($fixture, '40.0000', 'sample-vetoed-posting');
    assert_throws(fn() => pl_post_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $payload),
        PL_Hook_Veto::class, 'cost centre');
    assert_same($before, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id = %i', $fixture['book_id']));
    assert_same($beforeLines, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journal_lines WHERE book_id = %i', $fixture['book_id']));
    assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id = %i AND idempotency_key = %s', $fixture['book_id'], 'sample-vetoed-posting'));
    assert_same('0.0000', pl_trial_balance($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'])['total_debit']);
    // The refusal is not permanent: the same key posts once the package stops objecting.
    plugin_fixture_reset();
    assert_true((int) pl_post_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $payload)['id'] > 0);
});

test('a validation hook that throws rolls its own writes back with the posting', function (): void {
    plugin_fixture_reset();
    $fixture = ledger_fixture();
    // The filter shares the posting's transaction, so a package that writes and then fails
    // leaves neither its own row nor a half-posted entry.
    pl_add_filter('journal.validate', static function (array $payload) use ($fixture): array {
        pl_enqueue_outbound_event($fixture['company_id'], $fixture['book_id'], 'sample.reviewed', 'journal', 1, 1, 'sample-plugin-write');
        throw new RuntimeException('The sample package failed after writing.');
    }, 10, 'sample-plugin');
    assert_throws(fn() => pl_post_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], ledger_payload($fixture, '17.0000', 'sample-failed-posting')),
        RuntimeException::class, 'sample-plugin');
    assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id = %i', $fixture['book_id']));
    assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_outbound_events WHERE book_id = %i', $fixture['book_id']));
});

test('a nested posting raises no hook, because the book it posts to is already locked', function (): void {
    plugin_fixture_reset();
    $fixture = ledger_fixture();
    $filtered = 0;
    pl_add_filter('journal.validate', static function (array $payload) use (&$filtered): array { $filtered++; return $payload; }, 10, 'sample-plugin');
    $journal = pl_ledger_transaction(function () use ($fixture): array {
        // What an invoice, a settlement or a stock document does: take the book, then post.
        pl_ledger_book($fixture['company_id'], $fixture['book_id'], true);
        return pl_post_journal_locked($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], ledger_payload($fixture, '11.0000'));
    });
    assert_true((int) $journal['id'] > 0);
    assert_same(0, $filtered, 'Decision 15: no hook runs inside the locked section.');
    // The outermost posting, with nothing locked yet, does raise it.
    pl_post_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], ledger_payload($fixture, '12.0000'));
    assert_same(1, $filtered);
});

test('journal.posted runs once, after the commit, outside every lock', function (): void {
    plugin_fixture_reset();
    $fixture = ledger_fixture();
    $seen = [];
    pl_add_action('journal.posted', static function (array $journal, array $context) use (&$seen): void {
        assert_same(0, DB::transactionDepth(), 'An after-commit action runs outside the transaction.');
        assert_same(false, pl_hook_lock_held(), 'An after-commit action runs outside every book lock.');
        $seen[] = [(int) $journal['id'], (string) $context['source_type']];
    }, 10, 'sample-plugin');
    $payload = ledger_payload($fixture, '30.0000', 'sample-after-commit');
    $journal = pl_post_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $payload);
    assert_same([[(int) $journal['id'], 'receipt']], $seen);
    // The idempotent replay returns the same journal and does not report a second posting.
    pl_post_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $payload);
    assert_same(1, count($seen));
});

test('an after-commit action that fails cannot un-post the journal it was told about', function (): void {
    plugin_fixture_reset();
    $fixture = ledger_fixture();
    pl_add_action('journal.posted', static function (): void { throw new RuntimeException('The sample package failed after the commit.'); }, 10, 'sample-plugin');
    $journal = pl_post_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], ledger_payload($fixture, '45.0000'));
    assert_same('45.0000', pl_get_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], (int) $journal['id'])['lines'][0]['debit']);
    assert_same('45.0000', pl_trial_balance($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'])['total_debit']);
    $failures = pl_hook_failures();
    assert_same('sample-plugin', $failures[count($failures) - 1]['owner']);
    assert_same('journal.posted', $failures[count($failures) - 1]['hook']);
});

test('period.closed is reported after the commit that closed it', function (): void {
    plugin_fixture_reset();
    $fixture = ledger_fixture();
    $closed = [];
    pl_add_action('period.closed', static function (array $period) use (&$closed): void {
        assert_same(0, DB::transactionDepth());
        $closed[] = (string) $period['status'];
    }, 10, 'sample-plugin');
    $period = pl_list_periods($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'])[0];
    pl_change_period_status($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], (int) $period['id'], 'closed',
        (int) $period['revision'], 'Sample close.', plugin_key('close'));
    assert_same(['closed'], $closed);
});

// ------------------------------------------------------------- the manifest (contract 2)

test('a contract 2 manifest validates, canonicalises and refuses what it does not define', function (): void {
    plugin_fixture_reset();
    $manifest = plugin_fixture_write('sample-plugin');
    $validated = pl_plugin_manifest_validate($manifest, 'sample-plugin');
    assert_same('sample-plugin', $validated['slug']);
    assert_same(PL_PLUGIN_CONTRACT, $validated['contract']);
    assert_same(PL_PLUGIN_API_VERSION, $validated['api']);
    assert_same('pl_sample_plugin_', pl_plugin_table_prefix('sample-plugin'));

    // In order: an undefined key, the wrong contract, another API version, a slug that is not
    // its folder, no description, no author, no licence, nothing it adds, a requirement without
    // an exact version, an unpublished extension point, a table outside its own prefix, a path
    // that climbs out of the package, and a sample package offered to the code loader.
    $refusals = [
        ['extra' => 'value'], ['contract' => 1], ['api' => '9.9.9'], ['slug' => 'other-plugin'],
        ['description' => ''], ['author' => ''], ['licence' => ''], ['adds' => []],
        ['requires' => ['core' => '>=1.0.0']], ['hooks' => ['journal.secret']],
        ['tables' => ['pl_journals']], ['screenshots' => ['../../secret.png']], ['type' => 'sample'],
    ];
    foreach ($refusals as $change) {
        assert_throws(fn() => pl_plugin_manifest_validate($change + $manifest, 'sample-plugin'), DomainException::class);
    }
    // A declared migration without its file is refused before anything runs.
    assert_throws(fn() => pl_plugin_manifest_validate(['migrations' => ['001_initial']] + $manifest, 'sample-plugin'), DomainException::class, 'migration');
});

// ------------------------------------------------------- installation, trust and the audit

test('only an installation administrator installs, activates or removes a package', function (): void {
    plugin_fixture_reset();
    plugin_fixture_clean_state();
    plugin_fixture_write('sample-plugin');
    $outsider = ledger_fixture();
    assert_throws(fn() => pl_plugin_install($outsider['actor_id'], 'sample-plugin', 'verified', 'Denied.', plugin_key('denied-install')), DomainException::class, 'installation administrator');
    $admin = plugin_admin_fixture();
    plugin_install_and_activate($admin, 'sample-plugin');
    assert_throws(fn() => pl_plugin_deactivate($outsider['actor_id'], 'sample-plugin', 'Denied.', plugin_key('denied-off')), DomainException::class, 'installation administrator');
    assert_throws(fn() => pl_plugin_uninstall($outsider['actor_id'], 'sample-plugin', false, 'Denied.', plugin_key('denied-rm')), DomainException::class, 'installation administrator');
    pl_plugin_deactivate($admin['actor_id'], 'sample-plugin', 'Sample tidy-up.', plugin_key('tidy-off'));
    pl_plugin_uninstall($admin['actor_id'], 'sample-plugin', true, 'Sample tidy-up.', plugin_key('tidy-rm'));
});

test('unverified code installs only with the three acknowledgements, kept with its digest', function (): void {
    plugin_fixture_reset();
    plugin_fixture_clean_state();
    plugin_fixture_write('sample-plugin');
    $admin = plugin_admin_fixture();
    assert_same(3, count(pl_plugin_acknowledgements()));
    assert_throws(fn() => pl_plugin_install($admin['actor_id'], 'sample-plugin', 'unverified', 'No confirmation.', plugin_key('no-ack')),
        DomainException::class, 'every statement');
    assert_throws(fn() => pl_plugin_install($admin['actor_id'], 'sample-plugin', 'unverified', 'Partial.', plugin_key('partial-ack'),
        ['unreviewed' => true, 'full_access' => true, 'backups' => false]), DomainException::class, 'every statement');
    $acknowledged = [];
    foreach (array_keys(pl_plugin_acknowledgements()) as $name) {
        $acknowledged[$name] = true;
    }
    $key = plugin_key('upload-ack');
    $result = pl_plugin_install($admin['actor_id'], 'sample-plugin', 'unverified', 'Sample owner upload.', $key, $acknowledged);
    assert_same('unverified', $result['trust']);
    $audit = DB::queryFirstRow("SELECT * FROM pl_package_actions WHERE slug = %s AND request_key = %s AND action = 'upload_accepted'", 'sample-plugin', $key);
    assert_same($admin['actor_id'], (int) $audit['actor_id']);
    assert_same(pl_plugin_record('sample-plugin')['files_digest'], (string) $audit['files_digest']);
    // MySQL normalises the key order of a JSON object, so this compares the map, not its order.
    $accepted = json_decode((string) $audit['acknowledgements'], true);
    ksort($accepted);
    assert_same(['backups' => true, 'full_access' => true, 'unreviewed' => true], $accepted);
    // The audit is immutable, like every other audit in this application.
    assert_throws(fn() => DB::update('pl_package_actions', ['reason' => 'Rewrite'], 'slug = %s', 'sample-plugin'));
    assert_throws(fn() => DB::delete('pl_package_actions', 'slug = %s', 'sample-plugin'));
    pl_plugin_uninstall($admin['actor_id'], 'sample-plugin', true, 'Sample tidy-up.', plugin_key('ack-tidy'));
});

test('a package installs once, and a second copy of the same slug is refused', function (): void {
    plugin_fixture_reset();
    plugin_fixture_clean_state();
    plugin_fixture_write('sample-plugin');
    $admin = plugin_admin_fixture();
    pl_plugin_install($admin['actor_id'], 'sample-plugin', 'verified', 'Sample.', plugin_key('once-install'));
    assert_throws(fn() => pl_plugin_install($admin['actor_id'], 'sample-plugin', 'verified', 'Again.', plugin_key('twice-install')), DomainException::class, 'already installed');
    pl_plugin_uninstall($admin['actor_id'], 'sample-plugin', true, 'Sample tidy-up.', plugin_key('once-tidy'));
});

// ---------------------------------------------------------- requirements, exact versions

test('a requirement is satisfied by an exact version and by nothing else', function (): void {
    plugin_fixture_reset();
    plugin_fixture_clean_state();
    $admin = plugin_admin_fixture();
    plugin_fixture_write('sample-plugin', ['requires' => ['inventory' => '0.0.1']]);
    pl_plugin_install($admin['actor_id'], 'sample-plugin', 'verified', 'Sample.', plugin_key('req-install'));
    assert_throws(fn() => pl_plugin_activate($admin['actor_id'], 'sample-plugin', 'Sample.', plugin_key('req-activate')), DomainException::class, 'exactly 0.0.1');
    pl_plugin_uninstall($admin['actor_id'], 'sample-plugin', true, 'Sample tidy-up.', plugin_key('req-tidy'));

    plugin_fixture_write('sample-plugin', ['requires' => ['no-such-package' => '1.0.0']]);
    pl_plugin_install($admin['actor_id'], 'sample-plugin', 'verified', 'Sample.', plugin_key('req2-install'));
    assert_throws(fn() => pl_plugin_activate($admin['actor_id'], 'sample-plugin', 'Sample.', plugin_key('req2-activate')), DomainException::class, 'not installed here');
    pl_plugin_uninstall($admin['actor_id'], 'sample-plugin', true, 'Sample tidy-up.', plugin_key('req2-tidy'));

    // The version the registry actually carries is accepted.
    plugin_fixture_write('sample-plugin', ['requires' => ['core' => pl_module_registry()['core']['version']]]);
    plugin_install_and_activate($admin, 'sample-plugin');
    assert_same('active', pl_plugin_record('sample-plugin')['status']);
    pl_plugin_deactivate($admin['actor_id'], 'sample-plugin', 'Sample tidy-up.', plugin_key('req3-off'));
    pl_plugin_uninstall($admin['actor_id'], 'sample-plugin', true, 'Sample tidy-up.', plugin_key('req3-tidy'));
});

// -------------------------------------------------- plugin migrations and their own receipts

test('a package migration runs into its own receipt table and may only touch its own tables', function (): void {
    plugin_fixture_reset();
    plugin_fixture_clean_state();
    $admin = plugin_admin_fixture();
    $coreReceipts = (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_schema_migrations');
    $create = "<?php\nreturn ['CREATE TABLE pl_sample_plugin_notes (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, note VARCHAR(120) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci'];\n";
    plugin_fixture_write('sample-plugin', ['migrations' => ['001_initial'], 'tables' => ['pl_sample_plugin_notes'],
        'grants' => ['sample.notes.manage' => 'Manage the sample package\'s notes']],
        ['plugin.php' => plugin_fixture_entry(), 'migrations/001_initial.php' => $create]);
    plugin_install_and_activate($admin, 'sample-plugin');

    assert_same('applied', (string) DB::queryFirstField('SELECT status FROM pl_plugin_migrations WHERE slug = %s AND version = %s', 'sample-plugin', '001_initial'));
    assert_same($coreReceipts, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_schema_migrations'), 'A package never writes into the core migration chain.');
    assert_true(in_array('pl_sample_plugin_notes', pl_install_tables(), true));
    // The capability it declared is registered against the package, and is never silently removed.
    $capability = DB::queryFirstRow('SELECT owner_type, owner_id FROM pl_capabilities WHERE capability = %s', 'sample.notes.manage');
    assert_same(['owner_type' => 'plugin', 'owner_id' => 'sample-plugin'], $capability);

    pl_plugin_deactivate($admin['actor_id'], 'sample-plugin', 'Sample.', plugin_key('mig-off'));
    // Removing without deleting data keeps the table and the receipts, so reinstalling finds them.
    pl_plugin_uninstall($admin['actor_id'], 'sample-plugin', false, 'Keep the data.', plugin_key('mig-keep'));
    assert_true(in_array('pl_sample_plugin_notes', pl_install_tables(), true));
    assert_same(1, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_plugin_migrations WHERE slug = %s', 'sample-plugin'));

    pl_plugin_install($admin['actor_id'], 'sample-plugin', 'verified', 'Sample.', plugin_key('mig-again'));
    pl_plugin_uninstall($admin['actor_id'], 'sample-plugin', true, 'Delete the data.', plugin_key('mig-delete'));
    assert_same(false, in_array('pl_sample_plugin_notes', pl_install_tables(), true));
    assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_plugin_migrations WHERE slug = %s', 'sample-plugin'));
});

test('a package migration that reaches for a core table is refused before it runs', function (): void {
    plugin_fixture_reset();
    plugin_fixture_clean_state();
    $admin = plugin_admin_fixture();
    $journals = (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals');
    foreach (['DELETE FROM pl_journals', 'ALTER TABLE pl_accounts ADD COLUMN sample_note VARCHAR(10) NULL',
        'GRANT ALL ON *.* TO CURRENT_USER()', 'DROP TABLE pl_users'] as $index => $statement) {
        $file = "<?php\nreturn [" . var_export($statement, true) . "];\n";
        plugin_fixture_write('sample-plugin', ['migrations' => ['001_initial']],
            ['plugin.php' => plugin_fixture_entry(), 'migrations/001_initial.php' => $file]);
        pl_plugin_install($admin['actor_id'], 'sample-plugin', 'verified', 'Sample.', plugin_key('bad-mig-' . $index));
        assert_throws(fn() => pl_plugin_activate($admin['actor_id'], 'sample-plugin', 'Sample.', plugin_key('bad-act-' . $index)), DomainException::class);
        assert_same('installed', pl_plugin_record('sample-plugin')['status']);
        pl_plugin_uninstall($admin['actor_id'], 'sample-plugin', true, 'Sample tidy-up.', plugin_key('bad-rm-' . $index));
    }
    assert_same($journals, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals'));
    assert_same(false, in_array('sample_note', DB::queryFirstColumn('SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = %s', 'pl_accounts'), true));
});

// --------------------------------------------------- the loader, the digest and safe mode

test('the loader runs an active package and ignores a folder nothing recorded', function (): void {
    plugin_fixture_reset();
    plugin_fixture_clean_state();
    $admin = plugin_admin_fixture();
    plugin_fixture_write('sample-plugin');
    plugin_fixture_write('unrecorded-plugin');
    assert_same([], pl_plugin_boot(), 'A folder that is only present has not been installed.');
    plugin_install_and_activate($admin, 'sample-plugin');
    plugin_fixture_reset();
    assert_same(['sample-plugin'], pl_plugin_boot());
    assert_same(['sample-plugin'], $GLOBALS['plugin_fixture_loaded']);

    // What a package does at load: register its callbacks under its own slug.
    plugin_fixture_reset();
    $GLOBALS['plugin_fixture_behaviour']['sample-plugin'] = static function (array $context): void {
        pl_add_filter('journal.validate', static function (array $payload): array {
            $payload['description'] = 'Reviewed by the loaded package';
            return $payload;
        }, 10, $context['slug']);
    };
    pl_plugin_boot();
    $fixture = ledger_fixture();
    assert_same('Reviewed by the loaded package', (string) pl_post_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], ledger_payload($fixture))['description']);

    plugin_fixture_reset();
    pl_plugin_deactivate($admin['actor_id'], 'sample-plugin', 'Sample tidy-up.', plugin_key('loader-off'));
    pl_plugin_uninstall($admin['actor_id'], 'sample-plugin', true, 'Sample tidy-up.', plugin_key('loader-rm'));
    plugin_fixture_rmdir(plugin_fixture_root() . '/unrecorded-plugin');
});

test('a package whose files moved is deactivated instead of loaded, with an audit row', function (): void {
    plugin_fixture_reset();
    plugin_fixture_clean_state();
    $admin = plugin_admin_fixture();
    plugin_fixture_write('sample-plugin');
    plugin_install_and_activate($admin, 'sample-plugin');
    $digest = pl_plugin_record('sample-plugin')['files_digest'];

    // Somebody edits the code in place. The manifest still says what it said.
    file_put_contents(plugin_fixture_root() . '/sample-plugin/plugin.php', plugin_fixture_entry() . "// changed\n");
    plugin_fixture_reset();
    assert_same([], pl_plugin_boot());
    assert_same([], $GLOBALS['plugin_fixture_loaded'], 'Code that no longer matches its digest does not run.');
    $record = pl_plugin_record('sample-plugin');
    assert_same('failed', (string) $record['status']);
    assert_true(str_contains((string) $record['last_error'], 'checksum') || str_contains((string) $record['last_error'], 'match'));
    $audit = DB::queryFirstRow("SELECT * FROM pl_package_actions WHERE slug = %s AND action = 'auto_deactivated' ORDER BY id DESC LIMIT 1", 'sample-plugin');
    assert_same(null, $audit['actor_id'], 'The loader deactivates with nobody signed in.');
    assert_same($digest, (string) $audit['files_digest']);

    // A file the manifest does not list is the other half of the same check: without it, a file
    // dropped in beside a listed one and required from it would run with the digest intact.
    plugin_fixture_write('sample-plugin');
    pl_plugin_uninstall($admin['actor_id'], 'sample-plugin', true, 'Reinstall from the reviewed copy.', plugin_key('digest-rm'));
    plugin_install_and_activate($admin, 'sample-plugin');
    file_put_contents(plugin_fixture_root() . '/sample-plugin/sneaked.php', "<?php\n");
    plugin_fixture_reset();
    assert_same([], pl_plugin_boot());
    assert_same('failed', (string) pl_plugin_record('sample-plugin')['status']);
    @unlink(plugin_fixture_root() . '/sample-plugin/sneaked.php');
    pl_plugin_uninstall($admin['actor_id'], 'sample-plugin', true, 'Sample tidy-up.', plugin_key('sneak-rm'));
});

test('a package that ended the last request is deactivated as a package fatal, not an update failure', function (): void {
    plugin_fixture_reset();
    plugin_fixture_clean_state();
    $admin = plugin_admin_fixture();
    plugin_fixture_write('sample-plugin');
    plugin_install_and_activate($admin, 'sample-plugin');
    // The breadcrumb the loader writes before it requires a package's entry file, left behind
    // because the last request never returned from that require.
    $breadcrumb = pl_plugin_breadcrumb_path('sample-plugin');
    assert_true($breadcrumb !== null, 'The package directory is writable, so fatal recovery is available.');
    file_put_contents((string) $breadcrumb, json_encode(['slug' => 'sample-plugin']));
    plugin_fixture_reset();
    assert_same([], pl_plugin_boot());
    assert_same([], $GLOBALS['plugin_fixture_loaded']);
    $record = pl_plugin_record('sample-plugin');
    assert_same('failed', (string) $record['status']);
    assert_true(str_contains((string) $record['last_error'], 'not a core update'),
        'The recorded reason names the package, so a package fatal is never read as a failed core update.');
    assert_same(false, is_file((string) $breadcrumb), 'The breadcrumb is cleared, so the next request is not a second accusation.');
    pl_plugin_uninstall($admin['actor_id'], 'sample-plugin', true, 'Sample tidy-up.', plugin_key('fatal-rm'));
});

test('safe mode loads nothing and changes nothing that was recorded', function (): void {
    plugin_fixture_reset();
    plugin_fixture_clean_state();
    $admin = plugin_admin_fixture();
    plugin_fixture_write('sample-plugin');
    plugin_install_and_activate($admin, 'sample-plugin');
    putenv('PL_PLUGINS_DISABLED=1');
    try {
        assert_same(true, pl_plugins_safe_mode());
        plugin_fixture_reset();
        assert_same([], pl_plugin_boot());
        assert_same([], $GLOBALS['plugin_fixture_loaded']);
        assert_same('active', (string) pl_plugin_record('sample-plugin')['status'], 'Safe mode changes nothing that was recorded.');
    } finally {
        putenv('PL_PLUGINS_DISABLED');
    }
    plugin_fixture_reset();
    assert_same(['sample-plugin'], pl_plugin_boot());
    pl_plugin_deactivate($admin['actor_id'], 'sample-plugin', 'Sample tidy-up.', plugin_key('safe-off'));
    pl_plugin_uninstall($admin['actor_id'], 'sample-plugin', true, 'Sample tidy-up.', plugin_key('safe-rm'));
});

// ------------------------------------------------------------------------------- options

test('package options are shared by one table and scoped to the installation or one business', function (): void {
    plugin_fixture_reset();
    plugin_fixture_clean_state();
    $admin = plugin_admin_fixture();
    plugin_fixture_write('sample-plugin', ['options' => ['retries', 'label']]);
    plugin_install_and_activate($admin, 'sample-plugin');

    pl_plugin_option_set($admin['actor_id'], 'sample-plugin', 'retries', 3);
    pl_plugin_option_set($admin['actor_id'], 'sample-plugin', 'retries', ['per_day' => 5], $admin['company_id']);
    assert_same(3, pl_plugin_option_get('sample-plugin', 'retries'));
    assert_same(['per_day' => 5], pl_plugin_option_get('sample-plugin', 'retries', $admin['company_id']));
    assert_same('fallback', pl_plugin_option_get('sample-plugin', 'label', 0, 'fallback'));
    assert_same(['retries' => 3], pl_plugin_option_all('sample-plugin'));

    $outsider = ledger_fixture();
    assert_throws(fn() => pl_plugin_option_set($outsider['actor_id'], 'sample-plugin', 'retries', 9), DomainException::class, 'installation administrator');
    assert_throws(fn() => pl_plugin_option_set($outsider['actor_id'], 'sample-plugin', 'retries', 9, $admin['company_id']), DomainException::class);
    assert_throws(fn() => pl_plugin_option_set($admin['actor_id'], 'sample-plugin', 'Not A Name', 9), DomainException::class);
    assert_throws(fn() => pl_plugin_option_get('sample-plugin', 'retries', 999999999), DomainException::class);

    pl_plugin_option_delete($admin['actor_id'], 'sample-plugin', 'retries');
    assert_same(null, pl_plugin_option_get('sample-plugin', 'retries'));
    pl_plugin_deactivate($admin['actor_id'], 'sample-plugin', 'Sample tidy-up.', plugin_key('opt-off'));
    pl_plugin_uninstall($admin['actor_id'], 'sample-plugin', true, 'Sample tidy-up.', plugin_key('opt-rm'));
    assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_plugin_options WHERE slug = %s', 'sample-plugin'));
});

// ---------------------------------------------- the outbound registration point (B77, B78)

test('a channel package registers a named consumer on the one outbound machine', function (): void {
    plugin_fixture_reset();
    plugin_fixture_clean_state();
    $admin = plugin_admin_fixture();
    plugin_fixture_write('sample-plugin', ['consumers' => ['sample-channel'], 'hooks' => ['outbound.consumers']]);
    plugin_install_and_activate($admin, 'sample-plugin');

    $delivered = [];
    plugin_fixture_reset();
    $GLOBALS['plugin_fixture_behaviour']['sample-plugin'] = static function (array $context) use (&$delivered): void {
        pl_add_filter('outbound.consumers', static function (array $handlers) use (&$delivered): array {
            $handlers['sample-channel'] = ['handler' => static function (array $event) use (&$delivered): bool {
                $delivered[] = (string) $event['event_key'];
                return true;
            }];
            return $handlers;
        }, 10, $context['slug']);
    };
    pl_plugin_boot();

    pl_ledger_transaction(fn() => pl_enqueue_outbound_event($admin['company_id'], $admin['book_id'], 'party.created', 'party', 1, 1, 'sample-channel-event'));
    $handlers = pl_plugin_outbound_handlers($admin['company_id'], $admin['book_id']);
    assert_same(['sample-channel'], array_keys($handlers));
    // The shape the existing dispatcher takes, unchanged: there is one queue and this joins it.
    assert_same(1, pl_dispatch_outbound_events($handlers)['succeeded']);
    assert_same(['sample-channel-event'], $delivered);
    assert_same(0, pl_dispatch_outbound_events($handlers)['claimed']);

    // A package cannot take over deliveries for a consumer its manifest never declared.
    plugin_fixture_reset();
    pl_add_filter('outbound.consumers', static function (array $handlers): array {
        $handlers['someone-elses-channel'] = ['handler' => static fn(): bool => true];
        return $handlers;
    }, 10, 'sample-plugin');
    assert_throws(fn() => pl_plugin_outbound_handlers($admin['company_id'], $admin['book_id']), DomainException::class, 'does not declare');

    plugin_fixture_reset();
    pl_plugin_deactivate($admin['actor_id'], 'sample-plugin', 'Sample tidy-up.', plugin_key('out-off'));
    pl_plugin_uninstall($admin['actor_id'], 'sample-plugin', true, 'Sample tidy-up.', plugin_key('out-rm'));
});

// ----------------------------------------------------------------- the Packages screen data

test('the Packages card list carries every field a person needs to decide', function (): void {
    plugin_fixture_reset();
    plugin_fixture_clean_state();
    $admin = plugin_admin_fixture();
    plugin_fixture_write('sample-plugin', ['adds' => ['A sample report.', 'A sample setting.'], 'homepage' => 'https://example.test/sample']);
    plugin_install_and_activate($admin, 'sample-plugin');
    $cards = pl_plugin_cards();
    $byName = [];
    foreach ($cards as $card) {
        $byName[$card['slug']] = $card;
    }
    assert_true(isset($byName['core']) && $byName['core']['status'] === 'required');
    assert_true(isset($byName['inventory']) && $byName['inventory']['status'] === 'optional');
    $card = $byName['sample-plugin'];
    foreach (['name', 'description', 'version', 'author', 'licence', 'adds', 'requires', 'screenshots', 'trust', 'status'] as $field) {
        assert_true(array_key_exists($field, $card), 'B52 asks the card to carry ' . $field . '.');
    }
    assert_same('active', $card['status']);
    assert_same('verified', $card['trust']);
    assert_same(2, count($card['adds']));
    // A business owner who does not administer the installation still gets the cards.
    $outsider = ledger_fixture();
    assert_same(false, pl_user_can($outsider['actor_id'], 0, 'installation.admin'));
    assert_true(count(pl_plugin_cards()) > 1);
    assert_throws(fn() => pl_plugin_history($outsider['actor_id']), DomainException::class, 'installation administrator');
    assert_true(count(pl_plugin_history($admin['actor_id'])) >= 2);

    pl_plugin_deactivate($admin['actor_id'], 'sample-plugin', 'Sample tidy-up.', plugin_key('card-off'));
    pl_plugin_uninstall($admin['actor_id'], 'sample-plugin', true, 'Sample tidy-up.', plugin_key('card-rm'));
});

test('the navigation filter accepts a package entry and drops a malformed one', function (): void {
    plugin_fixture_reset();
    $groups = ['Setup' => [['/modules', 'Modules', 'list', ['modules'], true]]];
    assert_same($groups, pl_plugin_navigation_groups($groups, []));
    pl_add_filter('navigation.groups', static function (array $value): array {
        $value['Sample'] = [['/sample-screen', 'Sample screen', 'list', ['sample-screen'], true], ['broken entry'], [123, 456]];
        return $value;
    }, 10, 'sample-plugin');
    $filtered = pl_plugin_navigation_groups($groups, []);
    assert_same(1, count($filtered['Sample']), 'A malformed entry is dropped rather than rendered.');
    assert_same('/sample-screen', $filtered['Sample'][0][0]);
    assert_same(1, count($filtered['Setup']));
    // A package that filtered the whole sidebar away has made a mistake, not a decision.
    plugin_fixture_reset();
    pl_add_filter('navigation.groups', static fn (): array => [], 10, 'sample-plugin');
    assert_same($groups, pl_plugin_navigation_groups($groups, []));
});

// ------------------------------------------------------------------------ the owner upload

test('an uploaded archive is checked member by member, unpacks nothing else, and installs nothing', function (): void {
    plugin_fixture_reset();
    plugin_fixture_clean_state();
    $admin = plugin_admin_fixture();
    $root = plugin_fixture_root();
    $entry = plugin_fixture_entry();
    $manifest = ['type' => 'plugin', 'slug' => 'uploaded-plugin', 'name' => 'Uploaded sample package',
        'version' => '1.0.0', 'contract' => PL_PLUGIN_CONTRACT, 'api' => PL_PLUGIN_API_VERSION,
        'description' => 'A package the test suite zipped up. It posts nothing and sends nothing.',
        'author' => 'PHP Ledger test suite', 'licence' => 'AGPL-3.0-or-later',
        'adds' => ['A sample extension point registration.'], 'requires' => [], 'entry' => 'plugin.php',
        'files' => ['plugin.php' => hash('sha256', $entry)]];
    $archive = $root . '/uploaded-plugin.zip';
    $zip = new ZipArchive();
    assert_same(true, $zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE));
    $zip->addFromString('uploaded-plugin/plugin.php', $entry);
    $zip->addFromString('uploaded-plugin/plugin.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    $zip->close();

    $outsider = ledger_fixture();
    assert_throws(fn() => pl_plugin_stage_archive($outsider['actor_id'], $archive), DomainException::class, 'installation administrator');

    $staged = pl_plugin_stage_archive($admin['actor_id'], $archive);
    assert_same('uploaded-plugin', $staged['slug']);
    assert_same(null, pl_plugin_record('uploaded-plugin'), 'Unpacking records nothing.');
    assert_same([], $GLOBALS['plugin_fixture_loaded'], 'Unpacking runs nothing.');
    assert_same($staged['files_digest'], pl_plugin_files_digest('uploaded-plugin', $staged['manifest']));
    // The screen finds it waiting for a decision rather than installed.
    $waiting = array_column(pl_web_packages_staged(), 'slug');
    assert_true(in_array('uploaded-plugin', $waiting, true));
    assert_throws(fn() => pl_plugin_stage_archive($admin['actor_id'], $archive), DomainException::class, 'already staged');

    // An archive whose members do not all sit under one folder named after its slug is refused,
    // and nothing from it is written: this is where a path that climbs out would arrive.
    $mixed = $root . '/mixed.zip';
    $zip = new ZipArchive();
    assert_same(true, $zip->open($mixed, ZipArchive::CREATE | ZipArchive::OVERWRITE));
    $zip->addFromString('mixed-plugin/plugin.php', $entry);
    $zip->addFromString('somewhere-else/evil.php', "<?php\n");
    $zip->close();
    assert_throws(fn() => pl_plugin_stage_archive($admin['actor_id'], $mixed), DomainException::class, 'own folder');
    assert_same(false, is_dir($root . '/mixed-plugin'));
    assert_same(false, is_dir($root . '/somewhere-else'));

    // An archive with no manifest is refused, and its files are not left behind either.
    $bare = $root . '/bare.zip';
    $zip = new ZipArchive();
    assert_same(true, $zip->open($bare, ZipArchive::CREATE | ZipArchive::OVERWRITE));
    $zip->addFromString('bare-plugin/plugin.php', $entry);
    $zip->close();
    assert_throws(fn() => pl_plugin_stage_archive($admin['actor_id'], $bare), DomainException::class, 'plugin.json');
    assert_same(false, is_dir($root . '/bare-plugin'));

    // The staged upload becomes a package only through the Unverified confirmation.
    $acknowledged = [];
    foreach (array_keys(pl_plugin_acknowledgements()) as $name) {
        $acknowledged[$name] = true;
    }
    pl_plugin_install($admin['actor_id'], 'uploaded-plugin', 'unverified', 'Sample owner upload.', plugin_key('zip-install'), $acknowledged);
    assert_same('unverified', (string) pl_plugin_record('uploaded-plugin')['trust']);
    pl_plugin_uninstall($admin['actor_id'], 'uploaded-plugin', true, 'Sample tidy-up.', plugin_key('zip-rm'));
    pl_plugin_remove_directory($root . '/uploaded-plugin');
    @unlink($archive);
    @unlink($mixed);
    @unlink($bare);
});

test('the package fixtures leave nothing installed and no package directory behind', function (): void {
    plugin_fixture_reset();
    assert_same([], pl_plugin_records(), 'Every fixture package was removed by the test that installed it.');
    plugin_fixture_rmdir(plugin_fixture_root());
    // Later suites, and the pseudo-locale route sweep's own server, must see the default path.
    putenv('PL_PLUGIN_DIRECTORY');
    assert_same([], pl_plugin_boot());
});
