<?php
declare(strict_types=1);

function bank_fixture_input(array $fixture, array $rows = [], string $closing = '0'): array
{
    return ['account_id' => $fixture['accounts']['1000'], 'reference' => 'BANK-' . bin2hex(random_bytes(8)), 'start_date' => '2026-01-01', 'end_date' => '2026-09-30', 'opening_balance' => '0', 'closing_balance' => $closing, 'baseline_confirmed' => true, 'rows' => $rows];
}

function bank_fixture_row(string $reference, string $in = '12.34', string $out = '0', string $date = '2026-09-14'): array
{
    return ['date' => $date, 'reference' => $reference, 'description' => 'Sample bank transfer', 'money_in' => $in, 'money_out' => $out];
}

function bank_fixture_import(array $fixture, array $input): array
{
    $preview = pl_bank_preview_statement($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $input);
    return pl_bank_import_statement($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $input, bin2hex(random_bytes(16)), $preview['digest']);
}

function bank_fixture_line(int $journalId, int $accountId): int
{
    return (int) DB::queryFirstField('SELECT id FROM pl_journal_lines WHERE journal_id = %i AND account_id = %i ORDER BY id LIMIT 1', $journalId, $accountId);
}

test('bank CSV strictly validates format, quoting, row limits, exact money and unique references', function (): void {
    $header = "date,reference,description,money_in,money_out\n";
    $rows = pl_bank_parse_csv($header . '2026-09-14,B-1,"A, B",12.3400,0');
    assert_same('A, B', $rows[0]['description']);
    assert_same('-1.2500', pl_bank_signed_amount('-1.25'));
    assert_same('0.0000', pl_bank_signed_amount('-0'));
    foreach (["date,amount\n2026-09-14,10", $header . '2026-09-14,B-1,"broken,12,0', $header . '2026-09-14,B-1,bad"quote,12,0', $header . '2026-09-14,B-1,"bad"junk,12,0', str_repeat('x', 524289), $header . "bad\0data", $header . str_repeat("2026-09-14,B-1,Bank,1,0\n", 501)] as $invalid) {
        assert_throws(fn() => pl_bank_parse_csv($invalid), DomainException::class);
    }
    $input = bank_fixture_input(['accounts' => ['1000' => 1]], [bank_fixture_row('B1')], '12.34');
    assert_same('12.3400', pl_bank_normalize_statement($input)['closing_balance']);
    foreach ([['money_in' => '1e2'], ['money_in' => '1.00001'], ['money_out' => '12.34'], ['date' => '2026-02-30'], ['date' => '2027-01-01'], ['reference' => '']] as $override) {
        $invalid = $input;
        $invalid['rows'][0] = array_replace($invalid['rows'][0], $override);
        assert_throws(fn() => pl_bank_normalize_statement($invalid), DomainException::class);
    }
    $input['rows'][] = $input['rows'][0];
    assert_throws(fn() => pl_bank_normalize_statement($input), DomainException::class, 'unique');
    $input = bank_fixture_input(['accounts' => ['1000' => 1]], [bank_fixture_row('B1')], '12.33');
    assert_throws(fn() => pl_bank_normalize_statement($input), DomainException::class, 'closing balance');
});

test('bank import preview writes nothing, requires reviewed baseline, and has durable replay/conflict handling', function (): void {
    $fixture = ledger_fixture();
    $input = bank_fixture_input($fixture, [bank_fixture_row('B1')], '12.34');
    $preview = pl_bank_preview_statement($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $input);
    assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_bank_statements WHERE book_id = %i', $fixture['book_id']));
    assert_throws(fn() => pl_bank_import_statement($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $input, 'import-one', 'unreviewed'), DomainException::class, 'preview');
    $statement = pl_bank_import_statement($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $input, 'import-one', $preview['digest']);
    assert_same($statement['id'], pl_bank_import_statement($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $input, 'import-one', $preview['digest'])['id']);
    $changed = $input;
    $changed['reference'] = 'different';
    assert_throws(fn() => pl_bank_import_statement($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $changed, 'import-one', $preview['digest']), DomainException::class, 'different');
    assert_same(1, count($statement['rows']));
    assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id = %i', $fixture['book_id']));
    assert_throws(fn() => pl_bank_complete_statement($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], (int) $statement['id'], 1, 'finish'), DomainException::class, 'Match every');
    assert_throws(fn() => pl_bank_preview_statement($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $input), DomainException::class, 'previous statement');
});

test('bank access rejects viewer writes, cross-company objects, wrong accounts, unready books and demo mutations', function (): void {
    $fixture = ledger_fixture();
    $other = ledger_fixture();
    $input = bank_fixture_input($fixture);
    sample_membership_insert(['company_id' => $fixture['company_id'], 'user_id' => $other['actor_id'], 'role' => 'viewer']);
    assert_throws(fn() => pl_bank_preview_statement($other['actor_id'], $fixture['company_id'], $fixture['book_id'], $input), DomainException::class);
    assert_throws(fn() => pl_bank_preview_statement($fixture['actor_id'], $other['company_id'], $other['book_id'], $input), DomainException::class);
    $invalid = $input;
    $invalid['account_id'] = $other['accounts']['1000'];
    assert_throws(fn() => pl_bank_preview_statement($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $invalid), DomainException::class, 'cash/bank');
    $invalid['account_id'] = $fixture['accounts']['4000'];
    assert_throws(fn() => pl_bank_preview_statement($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $invalid), DomainException::class, 'cash/bank');
    DB::update('pl_companies', ['setup_status' => 'opening_required'], 'id = %i', $fixture['company_id']);
    assert_throws(fn() => pl_bank_preview_statement($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $input), DomainException::class, 'Opening');
    DB::update('pl_companies', ['setup_status' => 'ready'], 'id = %i', $fixture['company_id']);
    $statement = bank_fixture_import($fixture, $input);
    assert_same($statement['id'], pl_bank_get_statement($other['actor_id'], $fixture['company_id'], $fixture['book_id'], (int) $statement['id'])['id']);
    assert_throws(fn() => pl_bank_get_statement($other['actor_id'], $other['company_id'], $other['book_id'], (int) $statement['id']), DomainException::class);
    $environment = getenv('PL_ENV');
    putenv('PL_ENV=demo');
    try {
        assert_throws(fn() => pl_bank_complete_statement($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], (int) $statement['id'], 1, 'demo'), DomainException::class, 'public sample');
    } finally {
        putenv('PL_ENV=' . $environment);
    }
});

test('bank matching shows ambiguity, rejects cross-scope and double allocation, and preserves unmatch audit', function (): void {
    $fixture = ledger_fixture();
    $other = ledger_fixture();
    $one = pl_post_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], ledger_payload($fixture));
    $two = pl_post_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], ledger_payload($fixture));
    $foreign = pl_post_journal($other['actor_id'], $other['company_id'], $other['book_id'], ledger_payload($other));
    $statement = bank_fixture_import($fixture, bank_fixture_input($fixture, [bank_fixture_row('B1'), bank_fixture_row('B2')], '24.68'));
    $id = (int) $statement['id'];
    $rowOne = (int) $statement['rows'][0]['id'];
    $rowTwo = (int) $statement['rows'][1]['id'];
    assert_same(2, count(pl_bank_candidates($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $id, $rowOne)));
    assert_same(null, $statement['rows'][0]['journal_line_id']);
    assert_throws(fn() => pl_bank_match_row($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $id, $rowOne, bank_fixture_line($foreign['id'], $other['accounts']['1000']), 1), DomainException::class);
    $lineOne = bank_fixture_line($one['id'], $fixture['accounts']['1000']);
    $statement = pl_bank_match_row($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $id, $rowOne, $lineOne, 1);
    assert_throws(fn() => pl_bank_match_row($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $id, $rowTwo, $lineOne, 2), DomainException::class, 'unused');
    assert_throws(fn() => pl_bank_match_row($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $id, $rowOne, null, 1), DomainException::class, 'changed');
    $statement = pl_bank_match_row($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $id, $rowOne, null, 2);
    assert_same(null, $statement['rows'][0]['journal_line_id']);
    assert_same(['match', 'unmatch'], DB::queryFirstColumn('SELECT action FROM pl_bank_match_events WHERE row_id = %i ORDER BY id', $rowOne));
    $statement = pl_bank_match_row($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $id, $rowTwo, $lineOne, 3);
    assert_same($lineOne, (int) $statement['rows'][1]['journal_line_id']);
});

test('bank completion reconciles outstanding entries, freezes history, blocks backdating and carries continuity', function (): void {
    $fixture = ledger_fixture();
    $one = pl_post_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], ledger_payload($fixture, '100'));
    $outstanding = pl_post_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], ledger_payload($fixture, '25'));
    $statement = bank_fixture_import($fixture, bank_fixture_input($fixture, [bank_fixture_row('B1', '100')], '100'));
    $id = (int) $statement['id'];
    $statement = pl_bank_match_row($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $id, (int) $statement['rows'][0]['id'], bank_fixture_line($one['id'], $fixture['accounts']['1000']), 1);
    $summary = pl_bank_reconciliation_summary($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $id);
    assert_same('125.0000', $summary['ledger_balance']);
    assert_same('25.0000', $summary['outstanding_balance']);
    assert_same('0.0000', $summary['difference']);
    assert_true($summary['ready']);
    $statement = pl_bank_complete_statement($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $id, 2, 'complete-first');
    assert_same('completed', $statement['status']);
    assert_same($statement['id'], pl_bank_complete_statement($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $id, 2, 'complete-first')['id']);
    assert_throws(fn() => pl_bank_complete_statement($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $id, 3, 'different'), DomainException::class);
    assert_throws(fn() => DB::delete('pl_bank_matches', 'row_id = %i', $statement['rows'][0]['id']), Throwable::class, 'immutable');
    assert_throws(fn() => DB::update('pl_bank_statement_rows', ['description' => 'changed'], 'id = %i', $statement['rows'][0]['id']), Throwable::class, 'immutable');
    assert_throws(fn() => pl_post_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], ledger_payload($fixture, '1')), DomainException::class, 'reconciled');
    $next = bank_fixture_input($fixture, [bank_fixture_row('B2', '25', '0', '2026-10-04')], '125');
    $next['start_date'] = '2026-10-01';
    $next['end_date'] = '2026-10-31';
    $next['opening_balance'] = '100';
    $bad = $next;
    $bad['start_date'] = '2026-10-02';
    assert_throws(fn() => bank_fixture_import($fixture, $bad), DomainException::class, 'following day');
    $bad = $next;
    $bad['rows'][0]['reference'] = 'B1';
    assert_throws(fn() => bank_fixture_import($fixture, $bad), DomainException::class, 'already been imported');
    $nextStatement = bank_fixture_import($fixture, $next);
    $nextId = (int) $nextStatement['id'];
    $nextStatement = pl_bank_match_row($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $nextId, (int) $nextStatement['rows'][0]['id'], bank_fixture_line($outstanding['id'], $fixture['accounts']['1000']), 1);
    assert_same('0.0000', pl_bank_reconciliation_summary($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $nextId)['outstanding_balance']);
    assert_same('25.0000', pl_bank_reconciliation_summary($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $id)['outstanding_balance']);
    assert_same('completed', pl_bank_complete_statement($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $nextId, 2, 'complete-second')['status']);
    assert_same(2, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id = %i', $fixture['book_id']));
});

test('bank first statement baseline is explicit and excludes already cleared earlier entries', function (): void {
    $fixture = ledger_fixture();
    $journal = pl_post_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], ledger_payload($fixture, '50'));
    $input = bank_fixture_input($fixture, [], '50');
    $input['start_date'] = '2026-09-15';
    $input['opening_balance'] = '50';
    $invalid = $input;
    $invalid['baseline_confirmed'] = false;
    assert_throws(fn() => bank_fixture_import($fixture, $invalid), DomainException::class, 'confirm');
    $invalid = $input;
    $invalid['opening_balance'] = '49';
    $invalid['closing_balance'] = '49';
    assert_throws(fn() => bank_fixture_import($fixture, $invalid), DomainException::class, 'ledger balance');
    $statement = bank_fixture_import($fixture, $input);
    $summary = pl_bank_reconciliation_summary($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], (int) $statement['id']);
    assert_true($summary['ready']);
    assert_same('0.0000', $summary['outstanding_balance']);
    pl_post_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], ledger_payload($fixture, '1'));
    assert_throws(fn() => pl_bank_complete_statement($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], (int) $statement['id'], 1, 'finish'), DomainException::class, 'baseline');
});

test('bank service outer rollback removes imports and match history atomically', function (): void {
    $fixture = ledger_fixture();
    $journal = pl_post_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], ledger_payload($fixture));
    $input = bank_fixture_input($fixture, [bank_fixture_row('B1')], '12.34');
    assert_throws(function () use ($fixture, $input, $journal): void {
        pl_ledger_transaction(function () use ($fixture, $input, $journal): void {
            $statement = bank_fixture_import($fixture, $input);
            pl_bank_match_row($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], (int) $statement['id'], (int) $statement['rows'][0]['id'], bank_fixture_line($journal['id'], $fixture['accounts']['1000']), 1);
            throw new DomainException('Injected rollback');
        });
    }, DomainException::class, 'Injected rollback');
    assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_bank_statements WHERE book_id = %i', $fixture['book_id']));
    assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_bank_matches WHERE book_id = %i', $fixture['book_id']));
    assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_bank_match_events e JOIN pl_bank_statement_rows r ON r.id = e.row_id WHERE r.book_id = %i', $fixture['book_id']));
});

test('bank baseline and completion gates use current reads despite an older outer transaction snapshot', function (): void {
    $fixture = ledger_fixture();
    $input = bank_fixture_input($fixture, [], '50');
    $input['start_date'] = '2026-09-15';
    $input['opening_balance'] = '50';
    DB::startTransaction();
    try {
        DB::queryFirstField('SELECT COUNT(*) FROM pl_journals');
        ledger_race([['mode' => 'post', 'fixture' => $fixture, 'payload' => ledger_payload($fixture, '50')]]);
        $preview = pl_bank_preview_statement($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $input);
        assert_same('50.0000', $preview['baseline']['baseline_balance']);
    } finally {
        DB::rollback();
    }
    $statement = bank_fixture_import($fixture, $input);
    DB::startTransaction();
    try {
        DB::queryFirstField('SELECT COUNT(*) FROM pl_journals');
        ledger_race([['mode' => 'post', 'fixture' => $fixture, 'payload' => ledger_payload($fixture, '1')]]);
        $summary = pl_bank_reconciliation_summary($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], (int) $statement['id']);
        assert_true(!$summary['baseline_unchanged']);
        assert_same('51.0000', $summary['ledger_balance']);
        assert_throws(fn() => pl_bank_complete_statement($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], (int) $statement['id'], 1, 'finish'), DomainException::class, 'baseline');
    } finally {
        DB::rollback();
    }
});

test('simultaneous bank import and completion retries retain one statement and one completion', function (): void {
    $fixture = ledger_fixture();
    $input = bank_fixture_input($fixture);
    $preview = pl_bank_preview_statement($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $input);
    $job = ['mode' => 'bank_import', 'fixture' => $fixture, 'bank_input' => $input, 'key' => 'same-bank-import', 'digest' => $preview['digest']];
    $results = ledger_race([$job, $job]);
    assert_same($results[0]['id'], $results[1]['id']);
    assert_same(1, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_bank_statements WHERE book_id = %i', $fixture['book_id']));
    $job = ['mode' => 'bank_complete', 'fixture' => $fixture, 'statement_id' => $results[0]['id'], 'revision' => 1, 'key' => 'same-bank-completion'];
    $results = ledger_race([$job, $job]);
    assert_same($results[0]['id'], $results[1]['id']);
    assert_same('completed', pl_bank_get_statement($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $results[0]['id'])['status']);
    assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id = %i', $fixture['book_id']));
});

test('bank cancellation retains immutable evidence and releases exact references for a corrected import', function (): void {
    $fixture = ledger_fixture();
    $journal = pl_post_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], ledger_payload($fixture));
    $input = bank_fixture_input($fixture, [bank_fixture_row('ORIGINAL-BANK-REF')], '12.34');
    $statement = bank_fixture_import($fixture, $input);
    $id = (int) $statement['id'];
    $rowId = (int) $statement['rows'][0]['id'];
    assert_throws(fn() => DB::update('pl_bank_statement_rows', ['active_reference' => null], 'id = %i', $rowId), Throwable::class, 'immutable');
    assert_throws(fn() => pl_bank_cancel_statement($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $id, 1, '', 'cancel'), DomainException::class);
    $statement = pl_bank_match_row($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $id, $rowId, bank_fixture_line($journal['id'], $fixture['accounts']['1000']), 1);
    assert_throws(fn() => pl_bank_cancel_statement($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $id, 2, 'Wrong statement data', 'cancel'), DomainException::class, 'Remove all');
    $statement = pl_bank_match_row($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $id, $rowId, null, 2);
    assert_throws(function () use ($fixture, $id): void {
        pl_ledger_transaction(function () use ($fixture, $id): void {
            pl_bank_cancel_statement($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $id, 3, 'Wrong statement data', 'cancel');
            throw new DomainException('Injected cancellation rollback');
        });
    }, DomainException::class, 'rollback');
    $statement = pl_bank_get_statement($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $id);
    assert_same('draft', $statement['status']);
    assert_same('ORIGINAL-BANK-REF', $statement['rows'][0]['active_reference']);
    $cancelled = pl_bank_cancel_statement($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $id, 3, 'Wrong statement data', 'cancel');
    assert_same('cancelled', $cancelled['status']);
    assert_same('ORIGINAL-BANK-REF', $cancelled['rows'][0]['reference']);
    assert_same(null, $cancelled['rows'][0]['active_reference']);
    assert_same(null, $cancelled['active_reference']);
    assert_same($fixture['actor_id'], (int) $cancelled['cancelled_by']);
    assert_same(['match', 'unmatch'], DB::queryFirstColumn('SELECT action FROM pl_bank_match_events WHERE row_id = %i ORDER BY id', $rowId));
    assert_same($id, (int) pl_bank_cancel_statement($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $id, 3, 'Wrong statement data', 'cancel')['id']);
    assert_throws(fn() => pl_bank_cancel_statement($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $id, 3, 'Changed reason', 'cancel'), DomainException::class, 'another');
    assert_throws(fn() => pl_bank_complete_statement($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $id, 4, 'complete-cancelled'), DomainException::class);
    assert_throws(fn() => DB::update('pl_bank_statements', ['status' => 'draft'], 'id = %i', $id), Throwable::class, 'immutable');
    assert_throws(fn() => DB::update('pl_bank_statement_rows', ['description' => 'Changed original'], 'id = %i', $rowId), Throwable::class, 'immutable');
    $input['rows'][0]['description'] = 'Corrected statement description';
    $corrected = bank_fixture_import($fixture, $input);
    assert_true((int) $corrected['id'] !== $id);
    assert_same($input['reference'], $corrected['reference']);
    assert_same('ORIGINAL-BANK-REF', $corrected['rows'][0]['reference']);
    assert_same('Sample bank transfer', pl_bank_get_statement($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $id)['rows'][0]['description']);
    $corrected = pl_bank_match_row($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], (int) $corrected['id'], (int) $corrected['rows'][0]['id'], bank_fixture_line($journal['id'], $fixture['accounts']['1000']), 1);
    $corrected = pl_bank_complete_statement($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], (int) $corrected['id'], 2, 'complete-corrected');
    assert_same('completed', $corrected['status']);
    assert_throws(fn() => pl_bank_cancel_statement($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], (int) $corrected['id'], 3, 'Cannot cancel complete', 'cancel-complete'), DomainException::class);
});
