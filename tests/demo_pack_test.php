<?php
declare(strict_types=1);

/** Balances keyed the way each pack authors them: the pre-conversion number where there is one. */
function pack_balances(array $trial): array
{
    $balances = [];
    foreach ($trial['accounts'] as $row) {
        $balances[(string) (($row['legacy_code'] ?? '') !== '' ? $row['legacy_code'] : $row['code'])] = $row['balance'];
    }
    return $balances;
}

function demo_pack_fixture(string $id): array
{
    $actor = pl_create_user('pack-' . bin2hex(random_bytes(8)) . '@example.invalid', 'Sample pack owner', 'Sample fixture password 123!');
    $input = ['name' => pl_demo_pack($id)['name'], 'currency' => 'USD', 'start_date' => '2024-01-01',
        'fiscal_year_end' => '12-31', 'start_mode' => 'sample', 'sample_pack' => $id, 'template_digest' => pl_starter_template()['digest']];
    $key = 'pack:' . bin2hex(random_bytes(8));
    $company = pl_setup_company($actor, $input, $key);
    return ['actor' => $actor, 'company' => $company, 'input' => $input, 'key' => $key];
}

foreach (array_keys(pl_demo_pack_catalog()) as $packId) {
    test($packId . ' reconciles 36 months, immutable history, manual schedules and editable practice', function () use ($packId): void {
        $f = demo_pack_fixture($packId); $company = $f['company']; $actor = $f['actor'];
        $id = $company['id']; $book = $company['book_id']; $pack = pl_demo_pack($packId);
        assert_same($id, pl_setup_company($actor, $f['input'], $f['key'])['id']);
        assert_same($pack['digest'], pl_company_demo_pack($actor, $id, $book)['digest']);
        assert_true((int) DB::queryFirstField('SELECT (SELECT COUNT(*) FROM pl_documents WHERE book_id = %i) + (SELECT COUNT(*) FROM pl_general_drafts WHERE book_id = %i)', $book, $book) >= 74, 'The pinned history was not retained.');
        assert_true((int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id = %i', $book) >= 72, 'The pinned history journals were not retained.');
        assert_true((int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id = %i AND reversal_of_id IS NOT NULL', $book) >= 1, 'The pinned correction was not retained.');
        $snapshot = json_decode((string) DB::queryFirstField('SELECT snapshot FROM pl_template_installation_history WHERE company_id = %i AND book_id = %i AND snapshot_kind = %s ORDER BY id DESC LIMIT 1', $id, $book, 'sample'), true, 512, JSON_THROW_ON_ERROR);
        assert_true((int) ($snapshot['sample_pack']['operational_replay']['event_count'] ?? 0) > 0, 'Operational replay receipt has no events.');
        assert_true(in_array($snapshot['sample_pack']['operational_replay']['status'] ?? '', ['runtime_replayed', 'runtime_replayed_with_staged_vertical_evidence'], true), 'Operational replay receipt is missing.');
        assert_same(2, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_template_installation_history WHERE company_id = %i AND book_id = %i', $id, $book));
        $chartSnapshot = json_decode((string) DB::queryFirstField('SELECT snapshot FROM pl_template_installations WHERE company_id = %i AND book_id = %i', $id, $book), true, 512, JSON_THROW_ON_ERROR);
        assert_true(!isset($chartSnapshot['sample_pack']), 'The original chart snapshot was overwritten by sample metadata.');
        $periods = pl_list_periods($actor, $id, $book);
        assert_same(14, count($periods));
        assert_same(13, count(array_filter($periods, static fn (array $p): bool => $p['status'] === 'closed')));
        assert_same('2026-01-01', $periods[0]['start_date']); assert_same('open', $periods[0]['status']);
        // Independent authored checkpoints are already checked during the atomic seed.
        // Verify cross-year items and year/quarter aggregation separately from that loop.
        $year = pl_profit_loss($actor, $id, $book, '2025-01-01', '2025-12-31');
        $quarterTotal = '0.0000';
        foreach ([['01-01','03-31'], ['04-01','06-30'], ['07-01','09-30'], ['10-01','12-31']] as [$start, $end]) {
            $quarterTotal = bcadd($quarterTotal, pl_profit_loss($actor, $id, $book, '2025-' . $start, '2025-' . $end)['net_profit'], 4);
        }
        assert_same($year['net_profit'], $quarterTotal);
        $at2024 = pack_balances(pl_trial_balance($actor, $id, $book, '2024-12-31'));
        $at2025 = pack_balances(pl_trial_balance($actor, $id, $book, '2025-12-31'));
        $at2026 = pack_balances(pl_trial_balance($actor, $id, $book, '2026-01-31'));
        assert_same('800.0000', $at2024['1100']); assert_same('-420.0000', $at2024['2100']);
        assert_same('0.0000', $at2025['1100']); assert_same('0.0000', $at2025['2100']);
        assert_same('-550.0000', $at2025['2000']); assert_same('0.0000', $at2026['2000']);
        assert_same('-3600.0000', $at2025['2200']); assert_same('-920.0000', $at2025['1390']);
        // Owner history (B61): capital introduced, the owner's loan, a repayment and drawings.
        assert_same('-25000.0000', $at2024['3000']);
        assert_same('-3000.0000', $at2024['2-110-10001-00']);
        assert_same('-2000.0000', $at2025['2-110-10001-00']);
        assert_same('0.0000', $at2024['3-900-10001-00']);
        assert_same('800.0000', $at2025['3-900-10001-00']);
        $owner = pl_list_owner_transactions($actor, $id, $book);
        assert_same(4, count($owner));
        assert_same(['capital_introduced', 'drawings', 'owner_loan_received', 'owner_loan_repaid'],
            (static function (array $rows): array { $kinds = array_map(static fn (array $row): string => $row['kind'], $rows); sort($kinds); return $kinds; })($owner));
        $movements = pl_owner_equity_movements($actor, $id, $book, '2025-12-31');
        assert_same('25000.0000', $movements['total_capital']);
        assert_same('800.0000', $movements['total_drawings']);
        assert_same('2000.0000', $movements['total_owner_loans']);
        assert_same('24200.0000', $movements['net_owner_equity']);
        if ($packId === 'retail-shop') { assert_same('1200.0000', $at2025['1400']); }
        if ($packId === 'distributor') { assert_same('2400.0000', $at2025['1400']); }
        $drafts = pl_list_documents($actor, $id, $book, ['status' => 'draft']);
        assert_same(3, $drafts['total']); assert_same('302.5000', $drafts['total_amount']);
        $draft = $drafts['documents'][0];
        $old = pl_get_document($actor, $id, $book, $draft['id']);
        $input = ['kind' => $old['kind'], 'date' => '2025-12-31', 'amount' => $old['amount'],
            'money_account_id' => $old['money_account_id'], 'category_account_id' => $old['category_account_id'],
            'counterparty' => $old['counterparty'], 'reference' => $old['reference'], 'memo' => $old['memo']];
        $edit = pl_save_document($actor, $id, $book, $input, $old['id'], $old['revision']);
        assert_throws(fn () => pl_post_document($actor, $id, $book, $edit['id'], $edit['revision']), DomainException::class, 'open accounting period');
        $input['date'] = '2026-02-04'; $input['amount'] = '13.5000';
        $edit = pl_save_document($actor, $id, $book, $input, $edit['id'], $edit['revision']);
        assert_same('posted', pl_post_document($actor, $id, $book, $edit['id'], $edit['revision'])['status']);
        assert_same($at2025, pack_balances(pl_trial_balance($actor, $id, $book, '2025-12-31')));
        assert_throws(fn () => pl_seed_demo_pack($actor, $id, $book, $packId), DomainException::class, 'empty');
        assert_throws(fn () => pl_setup_company($actor, array_replace($f['input'], ['sample_pack' => $packId === 'distributor' ? 'service-agency' : 'distributor']), $f['key']), DomainException::class, 'different');
    });
}

test('installation history snapshots reject updates and deletes', function (): void {
    $f = demo_pack_fixture('service-agency');
    $historyId = (int) DB::queryFirstField('SELECT id FROM pl_template_installation_history WHERE company_id = %i AND snapshot_kind = %s ORDER BY id DESC LIMIT 1', $f['company']['id'], 'sample');
    assert_true($historyId > 0, 'The sample installation history row was not created.');
    assert_throws(fn () => DB::update('pl_template_installation_history', ['snapshot_digest' => str_repeat('a', 64)], 'id = %i', $historyId), MeekroDBException::class, 'immutable');
    assert_throws(fn () => DB::delete('pl_template_installation_history', 'id = %i', $historyId), MeekroDBException::class, 'immutable');
});

test('sample selection rejects paths dates ordinary companies and foreign guide access atomically', function (): void {
    assert_throws(fn () => pl_demo_pack('../core-samples/core-accounting'), DomainException::class);
    $a = ledger_fixture(); $b = ledger_fixture();
    assert_same(null, pl_company_demo_pack($a['actor_id'], $a['company_id'], $a['book_id']));
    assert_throws(fn () => pl_company_demo_pack($b['actor_id'], $a['company_id'], $a['book_id']), DomainException::class);
    assert_throws(fn () => pl_seed_demo_pack($a['actor_id'], $a['company_id'], $a['book_id'], 'service-agency'), DomainException::class);
    $before = (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_companies');
    $input = ['name' => 'Bad date sample', 'currency' => 'USD', 'start_date' => '2026-01-01',
        'fiscal_year_end' => '12-31', 'start_mode' => 'sample', 'sample_pack' => 'retail-shop', 'template_digest' => pl_starter_template()['digest']];
    assert_throws(fn () => pl_setup_company($a['actor_id'], $input, 'invalid-pack-date'), DomainException::class, '2024');
    assert_same($before, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_companies'));
});
