<?php
declare(strict_types=1);

// Keep this bounded service race worker with its suite, outside the public root.
if (($argv[1] ?? '') === '--period-worker') {
    if (getenv('PL_ENV') !== 'test' || getenv('PL_DB_NAME') !== 'phpledger_test') {
        exit(2);
    }
    require_once dirname(__DIR__) . '/www/phpledger/includes/bootstrap.php';
    require_once dirname(__DIR__) . '/www/phpledger/includes/functions/period_functions.php';
    $job = json_decode(file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
    $deadline = microtime(true) + 15;
    while (!is_file($job['barrier'])) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Period worker barrier timed out.');
        }
        usleep(10000);
    }
    $f = $job['fixture'];
    try {
        $result = match ($job['mode']) {
            'create' => pl_create_period($f['actor_id'], $f['company_id'], $f['book_id'], $job['input']),
            'close' => pl_change_period_status($f['actor_id'], $f['company_id'], $f['book_id'], $f['period_id'], 'closed', 1, 'Concurrent close', $job['key']),
            'cash-count' => pl_record_cash_count($f['actor_id'], $f['company_id'], $f['book_id'], $job['input']),
            'post' => pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], $job['payload']),
        };
        echo json_encode(['result' => $result], JSON_THROW_ON_ERROR);
    } catch (DomainException $error) {
        echo json_encode(['error' => $error->getMessage()], JSON_THROW_ON_ERROR);
    }
    exit;
}

function period_input(string $key = 'create-period'): array
{
    return ['start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'reason' => 'Sample next reporting year', 'request_key' => $key];
}

function period_race(array $jobs): array
{
    $directory = sys_get_temp_dir() . '/phpledger-period-race-' . bin2hex(random_bytes(8));
    if (!mkdir($directory, 0700)) {
        throw new RuntimeException('Could not create the period race fixture.');
    }
    $processes = [];
    try {
        foreach ($jobs as $index => $job) {
            $path = $directory . '/' . $index . '.json';
            file_put_contents($path, json_encode($job + ['barrier' => $directory . '/start'], JSON_THROW_ON_ERROR));
            $pipes = [];
            $process = proc_open([PHP_BINARY, __FILE__, '--period-worker', $path], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) {
                throw new RuntimeException('Could not start a period race worker.');
            }
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $processes[] = ['process' => $process, 'out' => $pipes[1], 'err' => $pipes[2], 'stdout' => '', 'stderr' => '', 'exit' => null];
        }
        touch($directory . '/start');
        $deadline = microtime(true) + 20;
        do {
            $running = false;
            foreach ($processes as &$entry) {
                if ($entry['exit'] !== null) { continue; }
                $entry['stdout'] .= stream_get_contents($entry['out']);
                $entry['stderr'] .= stream_get_contents($entry['err']);
                $status = proc_get_status($entry['process']);
                if ($status['running']) { $running = true; } else { $entry['exit'] = $status['exitcode']; }
            }
            unset($entry);
            if ($running) { usleep(10000); }
        } while ($running && microtime(true) < $deadline);
        assert_true(!$running, 'Period race timed out.');
        $results = [];
        foreach ($processes as &$entry) {
            $entry['stdout'] .= stream_get_contents($entry['out']);
            $entry['stderr'] .= stream_get_contents($entry['err']);
            assert_same(0, $entry['exit'], $entry['stderr']);
            $results[] = json_decode($entry['stdout'], true, 512, JSON_THROW_ON_ERROR);
        }
        unset($entry);
        return $results;
    } finally {
        unset($entry);
        foreach ($processes as $entry) {
            if ($entry['exit'] === null) { proc_terminate($entry['process']); }
            fclose($entry['out']);
            fclose($entry['err']);
            proc_close($entry['process']);
        }
        foreach (glob($directory . '/*') ?: [] as $path) { unlink($path); }
        rmdir($directory);
    }
}

test('period creation is scoped, reasoned and idempotent with durable original receipts', function (): void {
    $f = ledger_fixture();
    $created = pl_create_period($f['actor_id'], $f['company_id'], $f['book_id'], period_input());
    assert_same('open', $created['status']);
    assert_same(1, $created['revision']);
    assert_same($created, pl_create_period($f['actor_id'], $f['company_id'], $f['book_id'], period_input()));
    assert_same(2, count(pl_list_periods($f['actor_id'], $f['company_id'], $f['book_id'])));
    assert_same(1, count(pl_period_history($f['actor_id'], $f['company_id'], $f['book_id'])));
    $changed = period_input();
    $changed['reason'] = 'Different reason';
    assert_throws(fn() => pl_create_period($f['actor_id'], $f['company_id'], $f['book_id'], $changed), DomainException::class, 'different period action');
    pl_change_period_status($f['actor_id'], $f['company_id'], $f['book_id'], $created['id'], 'closed', 1, 'Year checked', 'close-new');
    assert_same($created, pl_create_period($f['actor_id'], $f['company_id'], $f['book_id'], period_input()));
});

test('period creation refuses invalid, overlapping and pre-business dates without writes', function (): void {
    $f = ledger_fixture();
    foreach ([['2027-02-30', '2027-12-31'], ['2027-03-01', '2027-02-28'], ['2026-12-31', '2027-12-31'], ['2025-01-01', '2025-12-31'], ['2026-02-01', '2026-02-28']] as [$start, $end]) {
        $input = period_input();
        $input['start_date'] = $start;
        $input['end_date'] = $end;
        assert_throws(fn() => pl_create_period($f['actor_id'], $f['company_id'], $f['book_id'], $input), DomainException::class);
    }
    $input = period_input();
    $input['reason'] = ' ';
    assert_throws(fn() => pl_create_period($f['actor_id'], $f['company_id'], $f['book_id'], $input), DomainException::class, 'Reason');
    $input = period_input('invalid key');
    assert_throws(fn() => pl_create_period($f['actor_id'], $f['company_id'], $f['book_id'], $input), DomainException::class, 'key');
    assert_same(1, count(pl_list_periods($f['actor_id'], $f['company_id'], $f['book_id'])));
    assert_same([], pl_period_history($f['actor_id'], $f['company_id'], $f['book_id']));
});

test('closed periods block posting and reversal; reopening preserves all prior journals', function (): void {
    $f = ledger_fixture();
    $payload = ledger_payload($f);
    $journal = pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], $payload);
    $closed = pl_change_period_status($f['actor_id'], $f['company_id'], $f['book_id'], $f['period_id'], 'closed', 1, 'Reviewed ledger', 'close-main');
    assert_same(2, $closed['revision']);
    assert_throws(fn() => pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], ledger_payload($f)), DomainException::class, 'period');
    assert_throws(fn() => pl_reverse_journal($f['actor_id'], $f['company_id'], $f['book_id'], $journal['id'], '2026-09-15', 'closed-reversal', 'Correction'), DomainException::class, 'period');
    assert_same($journal, pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], $payload));
    $opened = pl_change_period_status($f['actor_id'], $f['company_id'], $f['book_id'], $f['period_id'], 'open', 2, 'Owner approves correction', 'reopen-main');
    assert_same(3, $opened['revision']);
    assert_same($closed, pl_change_period_status($f['actor_id'], $f['company_id'], $f['book_id'], $f['period_id'], 'closed', 1, 'Reviewed ledger', 'close-main'));
    assert_same('open', pl_list_periods($f['actor_id'], $f['company_id'], $f['book_id'])[0]['status']);
    assert_same($journal, pl_get_journal($f['actor_id'], $f['company_id'], $f['book_id'], $journal['id']));
    pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], ledger_payload($f));
});

test('period actions enforce revision conflicts, required reasons and request payload identity', function (): void {
    $f = ledger_fixture();
    assert_throws(fn() => pl_change_period_status($f['actor_id'], $f['company_id'], $f['book_id'], $f['period_id'], 'closed', 1, '', 'empty-reason'), DomainException::class, 'Reason');
    pl_change_period_status($f['actor_id'], $f['company_id'], $f['book_id'], $f['period_id'], 'closed', 1, 'Checked', 'close');
    assert_throws(fn() => pl_change_period_status($f['actor_id'], $f['company_id'], $f['book_id'], $f['period_id'], 'closed', 1, 'Different', 'close'), DomainException::class, 'different period action');
    assert_throws(fn() => pl_change_period_status($f['actor_id'], $f['company_id'], $f['book_id'], $f['period_id'], 'open', 1, 'Stale request', 'stale'), DomainException::class, 'changed');
    pl_change_period_status($f['actor_id'], $f['company_id'], $f['book_id'], $f['period_id'], 'open', 2, 'Correct', 'open');
    assert_throws(fn() => pl_change_period_status($f['actor_id'], $f['company_id'], $f['book_id'], $f['period_id'], 'closed', 1, 'Old close after reopen', 'aba'), DomainException::class, 'changed');
    assert_same(2, count(pl_period_history($f['actor_id'], $f['company_id'], $f['book_id'])));
});

test('period administration separates owner, accountant and viewer permissions and company scope', function (): void {
    $f = ledger_fixture();
    $accountant = ledger_fixture();
    $viewer = ledger_fixture();
    sample_membership_insert(['company_id' => $f['company_id'], 'user_id' => $accountant['actor_id'], 'role' => 'accountant']);
    sample_membership_insert(['company_id' => $f['company_id'], 'user_id' => $viewer['actor_id'], 'role' => 'viewer']);
    assert_same(1, count(pl_list_periods($viewer['actor_id'], $f['company_id'], $f['book_id'])));
    assert_throws(fn() => pl_create_period($viewer['actor_id'], $f['company_id'], $f['book_id'], period_input()), DomainException::class, 'access');
    assert_throws(fn() => pl_change_period_status($viewer['actor_id'], $f['company_id'], $f['book_id'], $f['period_id'], 'closed', 1, 'Viewer action', 'viewer'), DomainException::class, 'access');
    pl_create_period($accountant['actor_id'], $f['company_id'], $f['book_id'], period_input());
    pl_change_period_status($accountant['actor_id'], $f['company_id'], $f['book_id'], $f['period_id'], 'closed', 1, 'Accountant review', 'accountant-close');
    assert_throws(fn() => pl_change_period_status($accountant['actor_id'], $f['company_id'], $f['book_id'], $f['period_id'], 'open', 2, 'Cannot authorize', 'accountant-open'), DomainException::class, 'cannot reopen');
    assert_throws(fn() => pl_change_period_status($f['actor_id'], $f['company_id'], $f['book_id'], $accountant['period_id'], 'closed', 1, 'Wrong period', 'scope'), DomainException::class, 'selected company');
    assert_throws(fn() => pl_list_periods($f['actor_id'], $accountant['company_id'], $accountant['book_id']), DomainException::class, 'access');
    assert_throws(fn() => pl_period_history($f['actor_id'], $accountant['company_id'], $accountant['book_id']), DomainException::class, 'access');
    assert_throws(fn() => pl_create_period($f['actor_id'], $f['company_id'], $accountant['book_id'], period_input()), DomainException::class, 'book');
});

test('period administration denies demo calls even from direct service callers', function (): void {
    $f = ledger_fixture();
    $prior = getenv('PL_ENV');
    putenv('PL_ENV=demo');
    try {
        assert_throws(fn() => pl_create_period($f['actor_id'], $f['company_id'], $f['book_id'], period_input()), DomainException::class, 'public sample');
        assert_throws(fn() => pl_change_period_status($f['actor_id'], $f['company_id'], $f['book_id'], $f['period_id'], 'closed', 1, 'Demo attempt', 'demo-close'), DomainException::class, 'public sample');
    } finally { putenv('PL_ENV=' . $prior); }
});

test('period history is immutable and failed audit persistence rolls back status and revision', function (): void {
    $f = ledger_fixture();
    $trigger = 'pl_test_period_audit_' . bin2hex(random_bytes(5));
    DB::query("CREATE TRIGGER %b BEFORE INSERT ON pl_period_actions FOR EACH ROW BEGIN IF NEW.reason = 'Sample audit storage failure' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sample audit storage failure'; END IF; END", $trigger);
    try {
        assert_throws(fn() => pl_change_period_status($f['actor_id'], $f['company_id'], $f['book_id'], $f['period_id'], 'closed', 1, 'Sample audit storage failure', 'failed-audit'), Throwable::class, 'storage failure');
        $period = pl_list_periods($f['actor_id'], $f['company_id'], $f['book_id'])[0];
        assert_same('open', $period['status']);
        assert_same(1, (int) $period['revision']);
        assert_same([], pl_period_history($f['actor_id'], $f['company_id'], $f['book_id']));
    } finally { DB::query('DROP TRIGGER %b', $trigger); }
    pl_change_period_status($f['actor_id'], $f['company_id'], $f['book_id'], $f['period_id'], 'closed', 1, 'Stored audit', 'audit');
    assert_throws(fn() => DB::update('pl_period_actions', ['reason' => 'Rewrite'], 'book_id = %i', $f['book_id']), Throwable::class, 'immutable');
    assert_throws(fn() => DB::delete('pl_period_actions', 'book_id = %i', $f['book_id']), Throwable::class, 'immutable');
});

test('simultaneous overlapping period creation accepts one and simultaneous retries return one receipt', function (): void {
    $f = ledger_fixture();
    $job = ['mode' => 'create', 'fixture' => $f, 'input' => period_input()];
    $results = period_race([$job, $job]);
    assert_same($results[0]['result'], $results[1]['result']);
    assert_same(1, count(pl_period_history($f['actor_id'], $f['company_id'], $f['book_id'])));
    $next = ledger_fixture();
    $results = period_race([
        ['mode' => 'create', 'fixture' => $next, 'input' => period_input('one')],
        ['mode' => 'create', 'fixture' => $next, 'input' => period_input('two')],
    ]);
    assert_same(1, count(array_filter($results, fn(array $r): bool => isset($r['result']))));
    $errors = array_values(array_filter($results, fn(array $r): bool => isset($r['error'])));
    assert_true(str_contains($errors[0]['error'], 'overlap'));
});

test('simultaneous period closes accept one revision and posting serializes against close', function (): void {
    $f = ledger_fixture();
    $results = period_race([
        ['mode' => 'close', 'fixture' => $f, 'key' => 'close-a'],
        ['mode' => 'close', 'fixture' => $f, 'key' => 'close-b'],
    ]);
    assert_same(1, count(array_filter($results, fn(array $r): bool => isset($r['result']))));
    assert_same(1, count(pl_period_history($f['actor_id'], $f['company_id'], $f['book_id'])));
    $other = ledger_fixture();
    $results = period_race([
        ['mode' => 'close', 'fixture' => $other, 'key' => 'racing-close'],
        ['mode' => 'post', 'fixture' => $other, 'payload' => ledger_payload($other, '25.00')],
    ]);
    assert_same('closed', $results[0]['result']['status']);
    if (isset($results[1]['error'])) { assert_true(str_contains($results[1]['error'], 'period')); }
    assert_same(isset($results[1]['result']) ? 1 : 0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id = %i', $other['book_id']));
    assert_throws(fn() => pl_post_journal($other['actor_id'], $other['company_id'], $other['book_id'], ledger_payload($other)), DomainException::class, 'period');
    assert_true(pl_trial_balance($other['actor_id'], $other['company_id'], $other['book_id'])['balanced']);
});

test('period service uses current state and receipts even with an older caller snapshot', function (): void {
    $f = ledger_fixture();
    DB::startTransaction();
    try {
        assert_same('open', DB::queryFirstField('SELECT status FROM pl_periods WHERE id = %i', $f['period_id']));
        $results = period_race([['mode' => 'close', 'fixture' => $f, 'key' => 'old-snapshot-close']]);
        assert_same('closed', $results[0]['result']['status']);
        assert_same('open', DB::queryFirstField('SELECT status FROM pl_periods WHERE id = %i', $f['period_id']));
        assert_same($results[0]['result'], pl_change_period_status($f['actor_id'], $f['company_id'], $f['book_id'], $f['period_id'], 'closed', 1, 'Concurrent close', 'old-snapshot-close'));
        assert_throws(fn() => pl_change_period_status($f['actor_id'], $f['company_id'], $f['book_id'], $f['period_id'], 'closed', 1, 'Stale close', 'old-snapshot-new'), DomainException::class, 'changed');
    } finally { if (DB::transactionDepth() > 0) { DB::rollback(); } }
});
