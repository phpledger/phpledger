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
        // The public demo is rebuilt from these packs and has to stand up from scratch on
        // 1.2.0. A book converted by migration 040 carries a pending contra review that
        // closes the owner screens until someone answers it; a book born on 1.2.0 must not.
        assert_same(false, pl_contra_review_pending($id, $book), 'A freshly created sample is waiting on a contra-account review.');
        assert_same(null, pl_contra_confirmation($id, $book), 'A freshly created sample recorded a chart conversion it never had.');
        assert_true(count(pl_owner_accounts($id, $book)['capital']) > 0, 'The owner screens have no capital account to offer.');
        // Numbering (B37/B55): every type has its own series, set before anything posted,
        // and every number the sample issued is recorded once and never reused.
        $numbering = $snapshot['sample_pack']['numbering'];
        assert_same(count(pl_document_series_types()), count($numbering));
        foreach ($numbering as $type => $example) {
            assert_true(preg_match('/^[A-Z]+-[0-9]{4}-[0-9]{5}$/D', (string) $example) === 1, 'Series ' . $type . ' has no real number: ' . $example);
        }
        $issued = DB::queryFirstColumn('SELECT number FROM pl_document_numbers WHERE book_id = %i', $book);
        assert_true(count($issued) > 0, 'The sample issued no document numbers.');
        assert_same(count($issued), count(array_unique($issued)));
        foreach ($issued as $number) {
            assert_true(preg_match('/^[A-Z]+-[0-9]{4}-[0-9]{5}$/D', (string) $number) === 1, 'A document kept a formatted id: ' . $number);
        }
        // The 1.2.0 showcase: what this sample demonstrates beyond its own pinned history.
        $showcase = $snapshot['sample_pack']['operational_replay']['showcase_1_2'];
        assert_same(true, $showcase['reports_agree']);
        $plan = $showcase['plan'];
        if ($plan['trading'] !== null) {
            $trading = $showcase['trading'];
            assert_same('posted', $trading['status']);
            assert_same($plan['trading']['discount_posting'], $trading['discount_posting']);
            assert_same($plan['trading']['price_mode'], $trading['price_mode']);
            assert_same($plan['trading']['free_goods_output_tax'], $trading['free_goods_output_tax']);
            assert_true(str_starts_with((string) $trading['document_number'], 'INV-'), 'The trading invoice took no invoice number.');
            // Two cases of twelve and three loose units is twenty-seven billed units, and the
            // two free ones are not billed at all.
            assert_same('27.0000', $trading['billed_quantity']);
            assert_true(bccomp((string) $trading['discount_total'], '0', 4) > 0, 'The line discount did not reach the document.');
            assert_same('100.0000', $trading['cash_received']);
            assert_true($trading['cash_settlement_journal_id'] !== null, 'Cash on the invoice settled nothing.');
            foreach (['sales_staff_id', 'area_id', 'warehouse_id'] as $dimension) {
                assert_true($trading[$dimension] !== null, 'The invoice carries no ' . $dimension . '.');
            }
            assert_same(true, $trading['free_line_is_free']);
            $document = pl_get_ar_document($actor, $id, $book, (int) $trading['document_id']);
            assert_same(2, count($document['lines']));
            assert_same(true, (bool) $document['lines'][1]['is_free_goods']);
            assert_same($trading['pack_id'], (int) $document['lines'][0]['pack_id']);
            assert_same('2.0000', $document['lines'][0]['pack_quantity']);
            assert_same('3.0000', $document['lines'][0]['unit_quantity']);
        }
        if ($plan['van_day']) {
            $van = $showcase['van_day'];
            assert_same('posted', $van['status']);
            assert_same(false, $van['gate_pass_moves_stock']);
            assert_same(true, $van['gate_pass_stamped_out']);
            assert_same(true, $van['reconciles']);
            assert_same('approved', $van['settlement_status']);
            assert_true($van['approved_by'] !== $van['reviewed_by_owner'], 'Recording and approving a van day were the same act.');
            foreach (['issue_number' => 'ISS-', 'reissue_number' => 'RISS-', 'return_number' => 'RTN-', 'gate_pass_number' => 'GP-'] as $field => $start) {
                assert_true(str_starts_with((string) $van[$field], $start), 'The van day\'s ' . $field . ' is not from its own series.');
            }
            assert_same('50.0000', $van['totals']['loaded']);
            assert_same('22.0000', $van['totals']['sold']);
            assert_same('30.0000', $van['totals']['returned']);
            assert_same('2.0000', $van['totals']['customer_returns']);
        }
        if ($plan['people']) {
            $people = $showcase['people'];
            assert_true($people['user_id'] > 0);
            assert_same(2, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_company_members WHERE company_id = %i', $id));
            assert_true(pl_user_can((int) $people['user_id'], $id, 'settlement.approve'), 'The custom role cannot do the one thing it exists for.');
            assert_true(!pl_user_can((int) $people['user_id'], $id, 'users.manage'), 'The custom role can administer people.');
            assert_true(!pl_user_can((int) $people['user_id'], $id, 'policy.manage'), 'The custom role can change accounting policy.');
        }
        if ($plan['advances']) {
            $advances = $showcase['advances'];
            assert_same('posted', $advances['status']);
            // 200 received against a 120 balance leaves 80 unapplied; 50 of it is applied
            // later and 30 refunded, so nothing of that receipt is left.
            assert_same('80.0000', $advances['on_account_remainder']);
            assert_same('50.0000', $advances['applied_fc']);
            assert_same('customer', $advances['refund_side']);
            assert_same(false, $advances['orphan_offset_override']);
            assert_same('75.0000', $advances['supplier_advance_remainder']);
            assert_same(2, $advances['batch_voucher_count']);
            assert_same('65.0000', $advances['batch_total_fc']);
            // The orphan credit and the two batch remainders are what is still unapplied,
            // and it reconciles to the control account rather than hiding in receivables.
            assert_same(3, $advances['unapplied_customer_items']);
            assert_same('83.0000', $advances['unapplied_customer_credit_base']);
            assert_same(true, $advances['unapplied_reconciles_to_control']);
        }
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
        // The display counter leaves at 2025-10-31: cost 2400 - 600, and accumulated
        // depreciation 840 - 210 removed with it, then two months at the reduced 30 (#95).
        assert_same('-3600.0000', $at2025['2200']); assert_same('-690.0000', $at2025['1390']);
        assert_same('1800.0000', $at2025['1300']); assert_same('40.0000', $at2025['5800']);
        // Contra presentation (B60): a sales return is a deduction from income and a
        // purchase return a deduction from purchases, not an expense and not income.
        assert_same('180.0000', $at2025['4-900-10001-00']);
        assert_same('-120.0000', $at2025['5-900-10001-00']);
        $contra = [];
        foreach (pl_trial_balance($actor, $id, $book, '2025-12-31')['accounts'] as $row) {
            if ($row['is_contra']) { $contra[(string) (($row['legacy_code'] ?? '') !== '' ? $row['legacy_code'] : $row['code'])] = true; }
        }
        foreach (['1390', '4-900-10001-00', '5-900-10001-00'] as $code) {
            assert_true(isset($contra[$code]), 'Contra account ' . $code . ' is not presented as a deduction.');
        }
        // Deferred income released a month at a time, fully earned by June 2025 (#94).
        assert_same('-600.0000', $at2024['2400']); assert_same('0.0000', $at2025['2400']);
        // One payroll month posted as totals per element with a payable each (#98).
        assert_same('120.0000', $at2025['5150']); assert_same('0.0000', $at2025['1500']);
        assert_same('0.0000', $at2025['2300']); assert_same('0.0000', $at2025['2310']);
        $payrollAt = pack_balances(pl_trial_balance($actor, $id, $book, '2025-09-30'));
        assert_same('-1135.0000', $payrollAt['2300']); assert_same('-90.0000', $payrollAt['2310']);
        assert_same('-45.0000', $payrollAt['2320']); assert_same('-150.0000', $payrollAt['2330']);
        assert_same('0.0000', $payrollAt['1500']);
        // B64: the printed seller block is filled in and entirely fictional.
        $profile = pl_company_profile($actor, $id);
        assert_true(!$profile['is_empty'], 'The sample company profile is empty.');
        assert_same($pack['company_profile']['legal_name'], $profile['legal_name']);
        assert_true(str_ends_with($profile['email'], '.example.invalid'), 'The sample company email is not a reserved sample address.');
        // Owner history (B61): capital introduced, the owner's loan, a repayment and drawings.
        $partners = pl_list_owner_partners($actor, $id, $book);
        assert_same(count($pack['partners']), count($partners));
        assert_same('-3000.0000', $at2024['2-110-10001-00']);
        assert_same('-2000.0000', $at2025['2-110-10001-00']);
        $owner = pl_list_owner_transactions($actor, $id, $book);
        $kinds = array_map(static fn (array $row): string => $row['kind'], $owner); sort($kinds);
        if ($pack['partners'] === []) {
            assert_same('-25000.0000', $at2024['3000']);
            assert_same('0.0000', $at2024['3-900-10001-00']);
            assert_same('800.0000', $at2025['3-900-10001-00']);
            assert_same(4, count($owner));
            assert_same(['capital_introduced', 'drawings', 'owner_loan_received', 'owner_loan_repaid'], $kinds);
        } else {
            // A partnership splits both sides: one capital and one drawings account each,
            // no account serving two partners, and the recorded shares totalling one.
            assert_same(6, count($owner));
            assert_same(['capital_introduced', 'capital_introduced', 'drawings', 'drawings', 'owner_loan_received', 'owner_loan_repaid'], $kinds);
            assert_true(pl_owner_shares_complete($actor, $id, $book), 'The sample partners do not share the whole result.');
            $partnerAccounts = [];
            foreach ($pack['partners'] as $partner) {
                assert_same('-' . $partner['capital_introduced'], $at2024[$partner['capital_code']]);
                assert_same($partner['drawings'], $at2025[$partner['drawings_code']]);
                $partnerAccounts[] = $partner['capital_code'];
                $partnerAccounts[] = $partner['drawings_code'];
            }
            assert_same(count($partnerAccounts), count(array_unique($partnerAccounts)));
            assert_same('0.0000', $at2024['3000']);
        }
        $movements = pl_owner_equity_movements($actor, $id, $book, '2025-12-31');
        assert_same('25000.0000', $movements['total_capital']);
        assert_same('800.0000', $movements['total_drawings']);
        assert_same('2000.0000', $movements['total_owner_loans']);
        assert_same('24200.0000', $movements['net_owner_equity']);
        // The 1.3 groundwork is data, never a screen: it must say so on every block.
        foreach ($pack['anticipated_1_3'] as $key => $block) {
            if (is_array($block) && isset($block['status'])) {
                assert_same('future_feature_data_not_implemented', $block['status']);
                assert_true(preg_match('/^#9[3-8]$/D', (string) $block['issue']) === 1, 'Block ' . $key . ' names no tracked 1.3 issue.');
            }
        }
        assert_same($pack['anticipated_1_3']['year_end']['net_result'],
            pl_profit_loss($actor, $id, $book, '2025-01-01', '2025-12-31')['net_profit']);
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

test('the samples between them cover both discount policies and both price modes', function (): void {
    $combinations = [];
    $showcases = ['van_day' => 0, 'people' => 0, 'advances' => 0];
    foreach (array_keys(pl_demo_pack_catalog()) as $packId) {
        $plan = pl_demo_showcase_plan($packId);
        if ($plan['trading'] !== null) {
            $combinations[] = $plan['trading']['discount_posting'] . '/' . $plan['trading']['price_mode'];
        }
        foreach (array_keys($showcases) as $key) { $showcases[$key] += $plan[$key] ? 1 : 0; }
    }
    sort($combinations);
    assert_same(['gross/exclusive', 'gross/inclusive', 'net/exclusive', 'net/inclusive'], $combinations);
    // A free-goods line is posted under every one of those four combinations.
    assert_same(4, count($combinations));
    foreach ($showcases as $key => $count) {
        assert_true($count >= 1, 'No sample demonstrates ' . $key . '.');
    }
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
