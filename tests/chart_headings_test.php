<?php
declare(strict_types=1);

/**
 * The chart of accounts reads as accounting, not as a code (1.3 M15).
 *
 * The defect these tests exist to stop coming back: every report of every book showed
 * "Group 1-100", "Group 1-110" and so on, because `pl_report_tree_node()` names a group from an
 * account row whose code is a heading code and the bundled chart contained none. The two
 * regression guards are the last two tests in the first group — a freshly created book and a
 * chart migration 036 already converted — and they walk every node of every report tree and
 * refuse the fallback label outright.
 *
 * The rest holds the names, the migration and the help catalogue to each other so the three
 * cannot drift: the chart package names the groups, migration 045 names the same groups in an
 * existing book from the same frozen list, and the help catalogue has plain words for every one
 * of them.
 */

/**
 * The owner-approved names and the semantic key each heading is recognised by, as
 * `code => [name, semantic_key]`. The one place this suite states them.
 *
 * The key is the recognition mechanism a module uses, never the name (translated, and editable by
 * its owner) and never the number (allocated per chart by migration 036).
 */
function chart_heading_catalogue(): array
{
    return [
        '1-000-00000-00' => ['Assets', 'core.class.asset'],
        '2-000-00000-00' => ['Liabilities', 'core.class.liability'],
        '3-000-00000-00' => ['Equity', 'core.class.equity'],
        '4-000-00000-00' => ['Revenue', 'core.class.income'],
        '5-000-00000-00' => ['Expenses', 'core.class.expense'],
        '1-100-00000-00' => ['Cash and Cash Equivalents', 'core.group.asset.cash_equivalents'],
        '1-110-00000-00' => ['Trade and Other Receivables', 'core.group.asset.receivables'],
        '1-120-00000-00' => ['Prepayments and Advances', 'core.group.asset.prepayments'],
        '1-200-00000-00' => ['Property, Plant and Equipment', 'core.group.asset.ppe'],
        '1-900-00000-00' => ['Accumulated Depreciation and Impairment', 'core.group.asset.accumulated_depreciation'],
        '2-100-00000-00' => ['Trade and Other Payables', 'core.group.liability.payables'],
        '2-110-00000-00' => ['Loans and Borrowings', 'core.group.liability.borrowings'],
        '2-120-00000-00' => ['Contract Liabilities and Customer Advances', 'core.group.liability.contract_liabilities'],
        '3-100-00000-00' => ['Capital and Reserves', 'core.group.equity.capital'],
        '3-900-00000-00' => ['Drawings', 'core.group.equity.drawings'],
        '4-100-00000-00' => ['Revenue', 'core.group.income.revenue'],
        '4-900-00000-00' => ['Revenue Deductions', 'core.group.income.revenue_deductions'],
        '5-100-00000-00' => ['Operating Expenses', 'core.group.expense.operating'],
        '5-900-00000-00' => ['Purchase Returns and Discounts Received', 'core.group.expense.purchase_returns'],
    ];
}

/** @return array<string,string> code => name */
function chart_heading_names(): array
{
    return array_map(static fn (array $entry): string => $entry[0], chart_heading_catalogue());
}

/** Every node of every tree a report returns, flattened, with the report it came from. */
function chart_heading_nodes(array $report, string $label = ''): array
{
    $found = [];
    $walk = static function (array $nodes, string $source) use (&$walk, &$found): void {
        foreach ($nodes as $node) {
            $found[] = $node + ['report' => $source];
            $walk($node['children'] ?? [], $source);
        }
    };
    foreach ($report['trees'] ?? [] as $name => $nodes) { $walk($nodes, $label . '/' . $name); }
    if (isset($report['tree'])) { $walk($report['tree'], $label . '/tree'); }
    return $found;
}

/** Assert that nothing in these reports fell back on the placeholder label. */
function chart_heading_assert_named(array $reports, string $where): void
{
    $placeholders = [];
    $unnamed = [];
    foreach ($reports as $label => $report) {
        foreach (chart_heading_nodes($report, (string) $label) as $node) {
            $name = (string) ($node['label'] ?? '');
            if (str_starts_with($name, 'Group ')) { $placeholders[] = $node['report'] . ' ' . $node['code'] . ' "' . $name . '"'; }
            if ($name === '') { $unnamed[] = $node['report'] . ' ' . $node['code']; }
        }
    }
    assert_same([], $placeholders, $where . ': a report node still falls back to the placeholder label: ' . implode(', ', $placeholders));
    assert_same([], $unnamed, $where . ': a report node has no label at all: ' . implode(', ', $unnamed));
}

/** Trial balance, profit and loss and balance sheet for one book, keyed by report name. */
function chart_heading_reports(array $f): array
{
    return [
        'trial-balance' => pl_trial_balance($f['actor_id'], $f['company_id'], $f['book_id'], '2026-12-31'),
        'profit-loss' => pl_profit_loss($f['actor_id'], $f['company_id'], $f['book_id'], '2026-01-01', '2026-12-31'),
        'balance-sheet' => pl_balance_sheet($f['actor_id'], $f['company_id'], $f['book_id'], '2026-12-31'),
    ];
}

/** Migration 045's statements, read from the migration file rather than restated here. */
function chart_heading_run_migration(): array
{
    $statements = require dirname(__DIR__) . '/www/phpledger/install/migrations/045_chart_headings.php';
    assert_same(19, count($statements), 'Migration 045 should carry five class headings, thirteen group headings and the fixed-asset group.');
    $written = 0;
    foreach ($statements as $sql) {
        assert_true((bool) preg_match('/^\s*INSERT INTO pl_accounts\b/', $sql),
            'Migration 045 must only insert heading rows; it carries: ' . substr(trim($sql), 0, 60));
        DB::query($sql);
        $written += DB::affectedRows();
    }
    return ['statements' => count($statements), 'rows' => $written];
}

test('the bundled chart names every class and group it uses, with the approved names and keys', function (): void {
    $template = pl_starter_template();
    $approved = chart_heading_names();
    $headings = [];
    $keys = [];
    foreach ($template['headings'] as $heading) {
        $code = (string) $heading['code'];
        assert_true(pl_account_code_is_valid($code), $code . ' is not a structured code.');
        assert_true(pl_account_code_is_heading($code), $code . ' is listed as a heading but is not one.');
        assert_true(pl_account_code_matches_type($code, (string) $heading['type']), $code . ' has the wrong classification.');
        $headings[$code] = (string) $heading['name'];
        $keys[$code] = (string) $heading['semantic_key'];
    }
    assert_same($approved, $headings, 'The bundled chart headings are not the approved set.');
    assert_same(array_map(static fn (array $entry): string => $entry[1], chart_heading_catalogue()), $keys,
        'The bundled chart headings do not carry the agreed semantic keys.');
    // `uq_account_semantic (book_id, semantic_key)` is one account per key per book, so a heading
    // key that collides with a posting purpose could not be installed at all.
    $all = array_merge(array_values($keys), array_column($template['accounts'], 'semantic_key'));
    assert_same(count($all), count(array_unique($all)), 'A heading key collides with another account key.');
    // Every class, and every group any posting account of the chart sits in, has a name. This is
    // the condition the report tree needs: an unnamed group is exactly what "Group 1-100" was.
    foreach ($template['accounts'] as $account) {
        foreach (pl_account_code_ancestors((string) $account['code']) as $ancestor) {
            assert_true(isset($headings[$ancestor]),
                'Account ' . $account['code'] . ' sits in ' . $ancestor . ', which the chart does not name.');
        }
    }
    // B60 keeps the 900 band of every class for contra accounts, so 1-900 is the accumulated
    // depreciation heading and not the asset heading the equipment itself belongs under.
    assert_same('Accumulated Depreciation and Impairment', $headings['1-900-00000-00']);
    assert_same('Property, Plant and Equipment', $headings['1-200-00000-00']);
    // A heading carries no operational role and no contra marking. A role names where a posting
    // goes, a heading takes none, and a second active account holding `receivables`, `payables` or
    // an advances role leaves `pl_ar_control()` and `pl_advance_control()` with no unambiguous
    // default and refuses every document in the book.
    foreach ($template['headings'] as $heading) {
        assert_true(!isset($heading['role']) && !isset($heading['is_contra']),
            $heading['code'] . ' carries an operational purpose; a heading carries a name and a key.');
    }
    $roles = ['cash_bank', 'receivables', 'payables', 'customer_advances', 'supplier_advances', 'owner_equity', 'income', 'expense'];
    assert_same(0, (int) DB::queryFirstField("SELECT COUNT(*) FROM pl_accounts WHERE code LIKE '_-___-00000-__' AND role IS NOT NULL"),
        'A heading somewhere carries an operational role: ' . implode(', ', $roles) . ' must stay on one postable account each.');
    // The chart-screen editor refuses one too, so this cannot be reintroduced by hand either.
    $f = ledger_fixture();
    assert_throws(static fn () => pl_save_account($f['actor_id'], $f['company_id'], $f['book_id'], [
        'code' => '1-300-00000-00', 'name' => 'A heading with a job', 'type' => 'asset', 'role' => 'cash_bank',
        'is_active' => true, 'reason' => 'Heading role fixture.', 'creation_key' => bin2hex(random_bytes(16))]),
        DomainException::class, 'cannot carry an operational purpose');
});

test('a module finds a chart group by its key, and says so when it had to fall back', function (): void {
    $f = ledger_fixture();
    $company = $f['company_id'];
    $book = $f['book_id'];
    // The ordinary case: the key is on the row, and that is what answered.
    $ppe = pl_account_heading_by_key($company, $book, 'core.group.asset.ppe');
    assert_true($ppe !== null, 'The fixed-asset group is not findable by its key in a new book.');
    assert_same('1-200-00000-00', $ppe['code']);
    assert_same('Property, Plant and Equipment', $ppe['name']);
    assert_same('semantic_key', $ppe['matched_by']);
    foreach (chart_heading_catalogue() as $code => [$name, $key]) {
        $found = pl_account_heading_by_key($company, $book, $key);
        assert_true($found !== null && $found['code'] === $code, $key . ' does not resolve to ' . $code . '.');
    }
    // A chart that predates the keys: the heading row is there, unkeyed. The resolver falls back on
    // the code the bundled chart uses and reports that it did, rather than assuming it silently.
    DB::update('pl_accounts', ['semantic_key' => null], 'company_id = %i AND book_id = %i AND code = %s', $company, $book, '1-200-00000-00');
    $fallback = pl_account_heading_by_key($company, $book, 'core.group.asset.ppe');
    assert_true($fallback !== null, 'A module could not find an unkeyed heading at the bundled code.');
    assert_same('bundled_code', $fallback['matched_by']);
    assert_same($ppe['id'], $fallback['id']);
    // A chart that has no such group at all — a hand-built one, or a converted book 045 could not
    // name. The answer is null, which is the module's cue to ask its owner, not to stop working.
    DB::query('DELETE FROM pl_accounts WHERE company_id = %i AND book_id = %i AND code = %s', $company, $book, '1-200-00000-00');
    assert_true(pl_account_heading_by_key($company, $book, 'core.group.asset.ppe') === null,
        'A group the book does not have was resolved anyway.');
    assert_true(pl_account_heading_by_key($company, $book, 'core.group.asset.no_such_group') === null);
    // A postable account carrying a key is not a heading, whatever key it carries.
    assert_true(pl_account_heading_by_key($company, $book, 'core.cash_bank') === null,
        'A postable account was returned as a chart heading.');
});

test('migration 045 names the same groups as the chart, from the same purposes', function (): void {
    $source = (string) file_get_contents(dirname(__DIR__) . '/www/phpledger/install/migrations/045_chart_headings.php');
    $approved = chart_heading_names();
    // The migration freezes its own copy of the names, as a migration must. This is the check that
    // the frozen copy and the chart package still say the same thing. Checked on the statements it
    // executes, never on the prose above them.
    $statements = require dirname(__DIR__) . '/www/phpledger/install/migrations/045_chart_headings.php';
    $sql = implode("\n", $statements);
    foreach ($approved as $name) {
        assert_true(str_contains($sql, "'" . str_replace("'", "''", $name) . "'"),
            'Migration 045 does not carry the approved name "' . $name . '".');
    }
    // Every heading it writes carries the agreed key. A heading written without one would look
    // like a successful migration and stay invisible to every module that looks for its group.
    foreach (chart_heading_catalogue() as $code => [$name, $key]) {
        assert_true(str_contains($sql, "'" . $key . "'"), 'Migration 045 writes ' . $code . ' without its semantic key ' . $key . '.');
        assert_true(str_contains($sql, "s.semantic_key = '" . $key . "'"),
            'Migration 045 does not guard ' . $key . ' against uq_account_semantic, so an upgrade could die on a duplicate key.');
    }
    // It reads the thirteen bundled purposes to tell which group is which, and names no other.
    $keys = array_column(pl_starter_template()['accounts'], 'semantic_key');
    assert_true((bool) preg_match_all("/k\.semantic_key = '(core\.[a-z_.]+)'/", $sql, $matches), 'Migration 045 derives no group name from a semantic key.');
    foreach ($matches[1] as $key) {
        assert_true(in_array($key, $keys, true), 'Migration 045 names semantic key ' . $key . ', which the bundled chart does not define.');
    }
    assert_same(13, count(array_unique($matches[1])), 'Migration 045 should name the thirteen purposes the bundled chart defines.');
    // It never writes a role. A role names where a posting goes, and a second active account
    // holding one would leave pl_ar_control() and pl_advance_control() with no unambiguous default.
    foreach (['cash_bank', 'receivables', 'payables', 'customer_advances', 'supplier_advances', 'owner_equity'] as $role) {
        assert_true(!str_contains($sql, "'" . $role . "'"), 'Migration 045 writes the operational role ' . $role . ' onto a heading.');
    }
    // The fixed-asset group is added only where the number provably means it: a book with no
    // conversion receipt whose cash account still sits at the bundled code.
    assert_true(str_contains($sql, 'NOT EXISTS (SELECT 1 FROM pl_account_code_map m WHERE m.book_id = b.id)'),
        'Migration 045 adds the fixed-asset group to a converted book, whose group numbers it cannot read.');
    // The prose is where the reasoning lives, so it is checked separately: the reversal path a
    // reader will actually run has to be in the file.
    assert_true(str_contains($source, "creation_key LIKE 'migration-045-%'"), 'Migration 045 does not document its reversal.');
});

test('every heading has plain words for it, keyed the way the screens ask', function (): void {
    $missing = [];
    $external = 0;
    foreach (array_keys(chart_heading_names()) as $code) {
        $id = pl_account_heading_concept($code);
        assert_true($id !== null, $code . ' is a heading but no concept id can be derived for it.');
        $concept = pl_guidance_concept((string) $id);
        if ($concept === null) { $missing[] = $code . ' (' . $id . ')'; continue; }
        $words = pl_guidance_word_count($concept['explanation']);
        assert_true($words >= 40 && $words <= 70, $id . ' explains itself in ' . $words . ' words; the bound is 40 to 70.');
        assert_true($concept['here'] !== '', $id . ' has no "how it works here" line.');
        assert_same('placeholder', $concept['review'], $id . ' claims a review that has not happened.');
        assert_true($concept['document'] !== null && $concept['document']['external'], $id . ' carries no article link.');
        assert_true((bool) preg_match(PL_GUIDANCE_ARTICLE, $concept['document']['href']), $id . ' links somewhere other than a phpledger.com/learn article.');
        $external++;
    }
    assert_same([], $missing, 'Headings with no explanation: ' . implode(', ', $missing));
    assert_same(19, $external);
    // The id is derived from the code, not stored beside it, so these are the exact keys the
    // report tree and the chart of accounts screen will ask for.
    assert_same('chart-class-1', pl_account_heading_concept('1-000-00000-00'));
    assert_same('chart-group-1-100', pl_account_heading_concept('1-100-00000-00'));
    assert_true(pl_account_heading_concept('1-100-10001-00') === null, 'A posting account was given a heading concept.');
    assert_true(pl_account_heading_concept('1000') === null, 'A legacy number was given a heading concept.');
    // A heading the catalogue has no words for is silent, not an exception: a book may hold one.
    assert_true(pl_guidance_concept('chart-group-7-770') === null);
});

test('everything this work ships is in the package manifest', function (): void {
    // A shipped file that is not in the manifest is not in the release archive (repository rule),
    // so the chart a new book is created from, the migration and every explanation would be
    // missing from the very installations this change exists for.
    $manifest = (string) file_get_contents(PL_ROOT . '/tools/package-files.json');
    $shipped = ['resources/coa/core-starter-1.2.0.json', 'www/phpledger/install/migrations/045_chart_headings.php'];
    foreach (array_keys(chart_heading_names()) as $code) {
        $shipped[] = 'resources/guidance/concepts/' . pl_account_heading_concept($code) . '.php';
    }
    $missing = [];
    foreach ($shipped as $path) {
        if (!str_contains($manifest, '"' . $path . '"')) { $missing[] = $path; }
        if (!is_file(PL_ROOT . '/' . $path)) { $missing[] = $path . ' (no such file)'; }
    }
    assert_same([], $missing, 'Not in tools/package-files.json, or not on disk: ' . implode(', ', $missing));
    // The retired charts keep their bytes: a book records the digest of the chart it was created
    // from, so a version already installed somewhere is never rewritten in place.
    foreach (['resources/coa/core-starter-1.0.0.json', 'resources/coa/core-starter-1.1.0.json'] as $retired) {
        assert_true(is_file(PL_ROOT . '/' . $retired), $retired . ' was removed; installed books name it.');
        $chart = json_decode((string) file_get_contents(PL_ROOT . '/' . $retired), true, 512, JSON_THROW_ON_ERROR);
        assert_true(!isset($chart['headings']), $retired . ' was edited in place; ship a new chart version instead.');
    }
});

test('a freshly created book shows no placeholder group on any report', function (): void {
    $f = ledger_fixture('USD', '2026-01-01');
    pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], ledger_payload($f, '1500.0000'));
    pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], [
        'date' => '2026-03-02', 'currency' => 'USD', 'source_type' => 'general_journal',
        'source_reference' => 'chart-headings', 'idempotency_key' => bin2hex(random_bytes(16)),
        'description' => 'Sample expense on account',
        'lines' => [['account_id' => $f['accounts']['5000'], 'debit' => '400.0000', 'credit' => '0'],
                    ['account_id' => $f['accounts']['2000'], 'debit' => '0', 'credit' => '400.0000']]]);

    $reports = chart_heading_reports($f);
    chart_heading_assert_named($reports, 'A new book');
    assert_true($reports['trial-balance']['balanced'], 'The trial balance stopped balancing.');
    assert_true($reports['balance-sheet']['balanced'], 'The balance sheet stopped balancing.');

    // The named headings actually reach the reader, with the approved words.
    $approved = chart_heading_names();
    $seen = [];
    foreach ($reports as $report) {
        foreach (chart_heading_nodes($report) as $node) {
            if (($node['is_heading'] ?? false) !== true) { continue; }
            $seen[(string) $node['code']] = (string) $node['label'];
        }
    }
    foreach (['1-000-00000-00', '1-100-00000-00', '1-110-00000-00', '2-100-00000-00', '4-100-00000-00', '5-100-00000-00'] as $code) {
        assert_same($approved[$code], $seen[$code] ?? null, $code . ' is not named on the reports.');
    }
});

test('an empty group is in the chart and deliberately not on a report', function (): void {
    $f = ledger_fixture('USD', '2026-01-01');
    pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], ledger_payload($f, '90.0000'));
    // The chart declares Property, Plant and Equipment, so it is there to be used...
    $company = pl_company_context($f['actor_id'], $f['company_id']);
    $codes = array_column($company['accounts'], 'code');
    assert_true(in_array('1-200-00000-00', $codes, true), 'The chart lost the fixed-asset group.');
    // ...and a report says what the book holds, so a group nothing is posted under has no node
    // and no zero line. This is the deliberate behaviour, not an accident of the tree builder.
    foreach (chart_heading_reports($f) as $name => $report) {
        foreach (chart_heading_nodes($report, (string) $name) as $node) {
            assert_true((string) $node['code'] !== '1-200-00000-00',
                'An empty group appeared on ' . $node['report'] . ' with nothing posted under it.');
        }
    }
    // A heading is a name, never a place to post: the central funnel refuses it.
    $heading = (int) DB::queryFirstField('SELECT id FROM pl_accounts WHERE company_id = %i AND book_id = %i AND code = %s',
        $f['company_id'], $f['book_id'], '1-200-00000-00');
    assert_throws(static fn () => pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], [
        'date' => '2026-04-01', 'currency' => 'USD', 'source_type' => 'general_journal',
        'source_reference' => 'heading-posting', 'idempotency_key' => bin2hex(random_bytes(16)),
        'description' => 'Posting straight onto a heading',
        'lines' => [['account_id' => $heading, 'debit' => '5.0000', 'credit' => '0'],
                    ['account_id' => $f['accounts']['1000'], 'debit' => '0', 'credit' => '5.0000']]]),
        DomainException::class, 'aggregates the accounts below it');
    // The chart of accounts screen knows it cannot be posted to, so no picker offers it.
    foreach ($company['accounts'] as $account) {
        assert_same(!pl_account_code_is_heading((string) $account['code']), $account['is_postable'],
            $account['code'] . ' has the wrong postable answer.');
    }
});

test('migration 045 names a converted chart, touches no posting account and runs twice safely', function (): void {
    $f = ledger_fixture('USD', '2026-01-01');
    $company = $f['company_id'];
    $book = $f['book_id'];
    pl_post_journal($f['actor_id'], $company, $book, ledger_payload($f, '2200.0000'));

    // Put the book back the way migration 036 leaves a converted chart: numbered and keyed, with
    // no heading row anywhere, because 036 allocated the group numbers and named none of them.
    DB::query("DELETE FROM pl_accounts WHERE company_id = %i AND book_id = %i AND code LIKE '_-___-00000-__'", $company, $book);
    assert_same(0, (int) DB::queryFirstField("SELECT COUNT(*) FROM pl_accounts WHERE company_id = %i AND book_id = %i AND code LIKE '_-___-00000-__'", $company, $book));
    // The conversion receipt 036 writes for every account it renumbered.
    foreach (DB::query('SELECT id, code FROM pl_accounts WHERE company_id = %i AND book_id = %i', $company, $book) as $row) {
        DB::insert('pl_account_code_map', ['company_id' => $company, 'book_id' => $book,
            'account_id' => (int) $row['id'], 'legacy_code' => 'L' . (int) $row['id'], 'structured_code' => (string) $row['code']]);
    }
    // Before 045 this book is the defect: every group falls back.
    $before = chart_heading_reports($f);
    $fallbacks = [];
    foreach ($before as $name => $report) {
        foreach (chart_heading_nodes($report, (string) $name) as $node) {
            if (str_starts_with((string) $node['label'], 'Group ')) { $fallbacks[] = (string) $node['code']; }
        }
    }
    assert_true($fallbacks !== [], 'The converted fixture does not reproduce the defect, so the guard below proves nothing.');

    $accountsBefore = DB::query('SELECT id, code, legacy_code, name, type, role, semantic_key, is_active, is_contra, revision, currency, report_classification FROM pl_accounts WHERE company_id = %i AND book_id = %i ORDER BY id', $company, $book);
    $linesBefore = DB::query('SELECT id, journal_id, account_id, debit, credit FROM pl_journal_lines WHERE company_id = %i ORDER BY id', $company);
    $balancesBefore = [];
    foreach ($before['trial-balance']['accounts'] as $row) { $balancesBefore[(int) $row['id']] = $row['balance']; }

    $first = chart_heading_run_migration();
    assert_true($first['rows'] > 0, 'Migration 045 wrote nothing at all.');

    // Every posting account is exactly as it was: no code, name, type, role, key, status, contra
    // marking, revision or currency moved, and no journal line was touched.
    $ids = array_map(static fn (array $row): int => (int) $row['id'], $accountsBefore);
    assert_same($accountsBefore, DB::query('SELECT id, code, legacy_code, name, type, role, semantic_key, is_active, is_contra, revision, currency, report_classification FROM pl_accounts WHERE id IN %li ORDER BY id', $ids));
    assert_same($linesBefore, DB::query('SELECT id, journal_id, account_id, debit, credit FROM pl_journal_lines WHERE company_id = %i ORDER BY id', $company));

    $after = chart_heading_reports($f);
    chart_heading_assert_named($after, 'A converted book');
    assert_true($after['trial-balance']['balanced']);
    assert_same($before['trial-balance']['total_debit'], $after['trial-balance']['total_debit']);
    $balancesAfter = [];
    foreach ($after['trial-balance']['accounts'] as $row) {
        if (isset($balancesBefore[(int) $row['id']])) { $balancesAfter[(int) $row['id']] = $row['balance']; }
    }
    ksort($balancesBefore);
    ksort($balancesAfter);
    assert_same($balancesBefore, $balancesAfter, 'A balance moved when the headings were named.');

    // The names and the keys it wrote are the agreed ones, and every row it wrote is marked as its
    // own. The key is the point of the row: without it the fixed-asset register and every other
    // module that looks for a group would see a migration that appeared to work and found nothing.
    $catalogue = chart_heading_catalogue();
    $written = DB::query("SELECT code, name, creation_key, semantic_key, role, is_contra FROM pl_accounts WHERE company_id = %i AND book_id = %i AND code LIKE '_-___-00000-__' ORDER BY code", $company, $book);
    assert_true(count($written) > 0, 'Migration 045 created no heading in a converted book.');
    foreach ($written as $row) {
        $code = (string) $row['code'];
        assert_same($catalogue[$code][0] ?? null, (string) $row['name'], $code . ' was given an unapproved name.');
        assert_same($catalogue[$code][1] ?? null, (string) $row['semantic_key'], $code . ' was written without the agreed semantic key.');
        assert_same('migration-045-' . $code, (string) $row['creation_key'], 'A heading row is not marked as the migration\'s own.');
        assert_true($row['role'] === null && (int) $row['is_contra'] === 0, $code . ' was given an operational role.');
        // The resolver finds it by its key, which is what a module will actually do.
        $found = pl_account_heading_by_key($company, $book, (string) $row['semantic_key']);
        assert_true($found !== null && $found['code'] === $code && $found['matched_by'] === 'semantic_key',
            $code . ' is not findable by its key after the migration.');
    }
    // The class headings are deterministic, so all five are there for a book that uses all five.
    $codes = array_column($written, 'code');
    foreach (['1-000-00000-00', '2-000-00000-00', '3-000-00000-00', '4-000-00000-00', '5-000-00000-00'] as $class) {
        assert_true(in_array($class, $codes, true), 'The converted book has no ' . $class . ' heading.');
    }
    // A converted book gets no fixed-asset group: 036 renumbered its groups, so nothing here can
    // say what 1-200 would mean in it. The module asks its owner instead of being told a guess.
    assert_true(!in_array('1-200-00000-00', $codes, true), 'A converted book was given a group number whose meaning nothing could read.');
    assert_true(pl_account_heading_by_key($company, $book, 'core.group.asset.ppe') === null,
        'A converted book resolved a fixed-asset group it does not have.');

    // Idempotent: a second run writes nothing, and the documented reversal removes exactly what it
    // wrote and nothing else.
    $second = chart_heading_run_migration();
    assert_same(0, $second['rows'], 'Migration 045 wrote rows on a second run.');
    DB::query("DELETE FROM pl_accounts WHERE company_id = %i AND book_id = %i AND creation_key LIKE 'migration-045-%'", $company, $book);
    assert_same(0, (int) DB::queryFirstField("SELECT COUNT(*) FROM pl_accounts WHERE company_id = %i AND book_id = %i AND code LIKE '_-___-00000-__'", $company, $book),
        'The reversal left a heading behind.');
    assert_same($accountsBefore, DB::query('SELECT id, code, legacy_code, name, type, role, semantic_key, is_active, is_contra, revision, currency, report_classification FROM pl_accounts WHERE id IN %li ORDER BY id', $ids),
        'The reversal changed a posting account.');
});

test('migration 045 refuses to name a group that holds two starter purposes', function (): void {
    $f = ledger_fixture('USD', '2026-01-01');
    $company = $f['company_id'];
    $book = $f['book_id'];
    DB::query("DELETE FROM pl_accounts WHERE company_id = %i AND book_id = %i AND code LIKE '_-___-00000-__'", $company, $book);
    // After 036 a group is the chart's own group, so a book that numbered cash and receivables in
    // one band has two starter purposes in one group. Naming that group after either of them would
    // describe the other one wrongly, so it is left for its owner to name.
    $receivables = (int) DB::queryFirstField('SELECT id FROM pl_accounts WHERE company_id = %i AND book_id = %i AND semantic_key = %s',
        $company, $book, 'core.receivables.trade');
    DB::update('pl_accounts', ['code' => '1-100-10009-00'], 'id = %i', $receivables);

    chart_heading_run_migration();

    $named = DB::queryFirstField("SELECT name FROM pl_accounts WHERE company_id = %i AND book_id = %i AND code = %s", $company, $book, '1-100-00000-00');
    assert_true($named === null, 'A group holding two starter purposes was named anyway: "' . (string) $named . '".');
    // The purposes that are alone in their group are still named, so one ambiguous group does not
    // cost the book the rest of its names.
    assert_same('Trade and Other Payables', (string) DB::queryFirstField('SELECT name FROM pl_accounts WHERE company_id = %i AND book_id = %i AND code = %s', $company, $book, '2-100-00000-00'));
    assert_same('Assets', (string) DB::queryFirstField('SELECT name FROM pl_accounts WHERE company_id = %i AND book_id = %i AND code = %s', $company, $book, '1-000-00000-00'));
    DB::query("DELETE FROM pl_accounts WHERE company_id = %i AND book_id = %i AND creation_key LIKE 'migration-045-%'", $company, $book);
});
