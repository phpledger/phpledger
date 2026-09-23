<?php
declare(strict_types=1);

function series_fixture(): array
{
    $f = ledger_fixture('USD');
    $party = pl_save_party($f['actor_id'], $f['company_id'], $f['book_id'], ['legal_name' => 'Sample numbering party', 'entity_type' => 'private_company', 'country_code' => 'GB', 'is_customer' => true, 'is_vendor' => true, 'currency' => 'USD', 'request_key' => bin2hex(random_bytes(16)), 'reason' => 'Sample numbering fixture']);
    return $f + ['party_id' => $party['id']];
}

function series_input(array $f, string $kind = 'invoice', string $amount = '1000', string $date = '2026-01-05'): array
{
    return ['kind' => $kind, 'party_id' => $f['party_id'], 'date' => $date, 'due_date' => $date, 'currency' => 'USD', 'reference' => 'Sample reference', 'creation_key' => bin2hex(random_bytes(16)),
        'lines' => [['description' => 'Sample service', 'quantity' => '1', 'unit_price' => $amount, 'account_id' => $f['accounts'][in_array($kind, ['bill', 'supplier_credit'], true) ? '5000' : '4000']]]];
}

function series_post(array $f, string $kind = 'invoice', string $amount = '1000', string $date = '2026-01-05'): array
{
    $draft = pl_save_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], series_input($f, $kind, $amount, $date));
    return pl_post_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], $draft['id'], $draft['revision']);
}

function series_row(array $f, string $type): array
{
    foreach (pl_list_document_series($f['actor_id'], $f['company_id'], $f['book_id']) as $entry) {
        if ($entry['document_type'] === $type) { return $entry; }
    }
    throw new RuntimeException('Missing series ' . $type);
}

test('every book carries the recommended default series per document type', function (): void {
    $f = series_fixture();
    $series = pl_list_document_series($f['actor_id'], $f['company_id'], $f['book_id']);
    // Trading documents first, then the stock documents added in 1.2 M4 (migration 037).
    assert_same(['invoice', 'bill', 'customer_credit', 'supplier_credit', 'stock_issue', 'stock_reissue', 'stock_return', 'gate_pass'], array_column($series, 'document_type'));
    foreach ($series as $entry) {
        assert_same(6, $entry['padding']);
        assert_true($entry['year_segment']);
        assert_same('yearly', $entry['reset_rule']);
        assert_same(1, $entry['next_number']);
    }
    assert_same(['INV', 'BILL', 'CR', 'SC', 'ISS', 'RISS', 'RTN', 'GP'], array_column($series, 'prefix'));
    assert_same('INV-' . gmdate('Y') . '-000001', $series[0]['example']);
});

test('a draft stays unnumbered and posting allocates the series number for its type', function (): void {
    $f = series_fixture();
    $draft = pl_save_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], series_input($f));
    assert_same(null, $draft['document_number']);
    assert_same('INV-' . str_pad((string) $draft['id'], 6, '0', STR_PAD_LEFT), $draft['number']);
    assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_document_numbers WHERE book_id=%i', $f['book_id']));
    $posted = pl_post_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], $draft['id'], $draft['revision']);
    assert_same('INV-2026-000001', $posted['document_number']);
    assert_same('INV-2026-000001', $posted['number']);
    // A posting retry returns the same document and never consumes a second number.
    assert_same($posted, pl_post_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], $draft['id'], $draft['revision']));
    assert_same(1, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_document_numbers WHERE book_id=%i', $f['book_id']));
    assert_same('INV-2026-000002', series_row($f, 'invoice')['example']);
    // Each document type runs its own series.
    $bill = series_post($f, 'bill');
    assert_same('BILL-2026-000001', $bill['number']);
    $customerCredit = series_input($f, 'customer_credit', '10');
    $customerCredit['original_document_id'] = $posted['id'];
    $customerCredit = pl_save_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], $customerCredit);
    assert_same('CR-2026-000001', pl_post_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], $customerCredit['id'], $customerCredit['revision'])['number']);
    $supplierCredit = series_input($f, 'supplier_credit', '10');
    $supplierCredit['original_document_id'] = $bill['id'];
    $supplierCredit = pl_save_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], $supplierCredit);
    assert_same('SC-2026-000001', pl_post_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], $supplierCredit['id'], $supplierCredit['revision'])['number']);
    assert_same('INV-2026-000002', series_post($f)['number']);
});

test('a posted correction keeps the number the document was issued with', function (): void {
    $f = series_fixture();
    $posted = series_post($f);
    $input = series_input($f);
    $input['creation_key'] = 'irrelevant-for-correction';
    $input['date'] = gmdate('Y-m-d');
    $input['due_date'] = $input['date'];
    $input['lines'][0]['unit_price'] = '1200';
    $corrected = pl_correct_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], $posted['id'], $input, $posted['revision'], bin2hex(random_bytes(16)), 'Correct the price');
    assert_same($posted['number'], $corrected['number']);
    assert_same(1, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_document_numbers WHERE book_id=%i', $f['book_id']));
});

test('an allocated number is never reused and a rolled-back allocation leaves no gap', function (): void {
    $f = series_fixture();
    assert_same('INV-2026-000001', series_post($f)['number']);
    $draft = pl_save_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], series_input($f));
    // Allocate inside a transaction that is then rolled back, exactly as a failing posting does.
    DB::startTransaction();
    try {
        assert_same('INV-2026-000002', pl_document_series_allocate($f['actor_id'], $f['company_id'], $f['book_id'], 'invoice', $draft['id'], '2026-01-05'));
    } finally {
        DB::rollback();
    }
    assert_same(2, series_row($f, 'invoice')['next_number']);
    assert_same('INV-2026-000002', pl_post_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], $draft['id'], $draft['revision'])['number']);
    // A refused posting never touches the series either.
    $credit = series_input($f, 'customer_credit', '10');
    $credit['original_document_id'] = $draft['id'];
    $credit = pl_save_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], $credit);
    assert_same('CR-2026-000001', pl_post_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], $credit['id'], $credit['revision'])['number']);
    $excessive = series_input($f, 'customer_credit', '9000');
    $excessive['original_document_id'] = $draft['id'];
    $excessive = pl_save_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], $excessive);
    assert_throws(fn() => pl_post_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], $excessive['id'], $excessive['revision']), DomainException::class, 'over-allocated');
    assert_same(2, series_row($f, 'customer_credit')['next_number']);
    $next = series_input($f, 'customer_credit', '10');
    $next['original_document_id'] = $draft['id'];
    $next = pl_save_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], $next);
    assert_same('CR-2026-000002', pl_post_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], $next['id'], $next['revision'])['number']);
    assert_throws(fn() => DB::query('DELETE FROM pl_document_numbers WHERE book_id=%i', $f['book_id']), Throwable::class, 'never released');
});

test('simultaneous postings in one book take consecutive numbers with no duplicate and no gap', function (): void {
    $f = series_fixture();
    $first = pl_save_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], series_input($f));
    $second = pl_save_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], series_input($f));
    $results = ledger_race([
        ['mode' => 'ar_post', 'fixture' => $f, 'document_id' => $first['id'], 'revision' => $first['revision']],
        ['mode' => 'ar_post', 'fixture' => $f, 'document_id' => $second['id'], 'revision' => $second['revision']],
    ]);
    assert_true($results[0]['id'] !== $results[1]['id']);
    $numbers = DB::queryFirstColumn('SELECT number FROM pl_document_numbers WHERE book_id=%i AND document_type=%s ORDER BY sequence_number', $f['book_id'], 'invoice');
    assert_same(['INV-2026-000001', 'INV-2026-000002'], $numbers);
    assert_same(2, (int) DB::queryFirstField('SELECT COUNT(DISTINCT number) FROM pl_document_numbers WHERE book_id=%i', $f['book_id']));
    assert_same(3, series_row($f, 'invoice')['next_number']);
    // Which of the two racing postings wins the book lock first is not deterministic, so the
    // number a given document receives is not either. What must hold is that both numbers were
    // issued exactly once, to different documents, with no gap: sort before comparing.
    $stored = DB::queryFirstColumn('SELECT document_number FROM pl_ar_documents WHERE book_id=%i', $f['book_id']);
    sort($stored, SORT_STRING);
    assert_same(['INV-2026-000001', 'INV-2026-000002'], $stored);
});

test('a document posted before the series existed keeps its derived number', function (): void {
    // Use an id no row can hold. The derived form is what a document shows when it has no
    // series row, so the test needs an id that is genuinely absent. It used to hard-code 42,
    // which was free until the samples grew enough to reach it: id 42 became a real supplier
    // credit carrying a real series number, and the assertion failed on the data rather than
    // on the behaviour. Deriving the id from the table cannot rot that way.
    $absent = (int) DB::queryFirstField('SELECT COALESCE(MAX(id), 0) + 1000 FROM pl_ar_documents');
    assert_same('INV-' . str_pad((string) $absent, 6, '0', STR_PAD_LEFT), pl_document_number_display(null, $absent, 'invoice'));
    assert_same('BILL-' . str_pad((string) $absent, 6, '0', STR_PAD_LEFT), pl_document_number_display('', $absent, 'bill'));
    assert_same('CR-' . str_pad((string) $absent, 6, '0', STR_PAD_LEFT), pl_ar_document_display_number($absent, 'customer_credit'));
    assert_same('SC-' . str_pad((string) $absent, 6, '0', STR_PAD_LEFT), pl_ar_document_display_number($absent, 'supplier_credit'));
    assert_same(null, pl_document_number_lookup('invoice', 0));
    $f = series_fixture();
    $posted = series_post($f);
    assert_same('INV-2026-000001', pl_ar_document_display_number($posted['id'], 'invoice'));
    assert_same('INV-2026-000001', pl_document_number_display($posted['document_number'], $posted['id'], 'invoice'));
});

test('a yearly series restarts at one in the next year and refuses an earlier year', function (): void {
    $f = series_fixture();
    pl_create_period($f['actor_id'], $f['company_id'], $f['book_id'], ['start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'reason' => 'Sample next reporting year', 'request_key' => 'numbering-2027']);
    assert_same('INV-2026-000001', series_post($f, 'invoice', '1000', '2026-03-01')['number']);
    assert_same('INV-2026-000002', series_post($f, 'invoice', '1000', '2026-12-31')['number']);
    assert_same('INV-2027-000001', series_post($f, 'invoice', '1000', '2027-01-01')['number']);
    assert_same('INV-2027-000002', series_post($f, 'invoice', '1000', '2027-06-30')['number']);
    assert_throws(fn() => series_post($f, 'invoice', '1000', '2026-06-01'), DomainException::class, 'resets every year');
    // The refusal is a posting refusal, so the 2027 counter is untouched.
    assert_same(3, series_row($f, 'invoice')['next_number']);
    assert_same(2027, series_row($f, 'invoice')['series_year']);
    // Without the yearly reset the running number continues across the year boundary.
    $bill = series_row($f, 'bill');
    pl_save_document_series($f['actor_id'], $f['company_id'], $f['book_id'], 'bill', ['prefix' => 'BILL', 'padding' => 6, 'year_segment' => true, 'reset_rule' => 'never', 'next_number' => 1, 'revision' => $bill['revision'], 'reason' => 'Continuous supplier numbering']);
    assert_same('BILL-2026-000001', series_post($f, 'bill', '1000', '2026-05-01')['number']);
    assert_same('BILL-2027-000002', series_post($f, 'bill', '1000', '2027-05-01')['number']);
    assert_same('BILL-2026-000003', series_post($f, 'bill', '1000', '2026-05-02')['number']);
});

test('only the owner may change numbering and the next number can never be lowered', function (): void {
    $f = series_fixture();
    $accountant = ledger_fixture();
    sample_membership_insert(['company_id' => $f['company_id'], 'user_id' => $accountant['actor_id'], 'role' => 'accountant']);
    $current = series_row($f, 'invoice');
    $valid = ['prefix' => 'SI', 'padding' => 4, 'year_segment' => true, 'reset_rule' => 'yearly', 'next_number' => 1, 'revision' => $current['revision'], 'reason' => 'Shorter customer numbering'];
    assert_throws(fn() => pl_save_document_series($accountant['actor_id'], $f['company_id'], $f['book_id'], 'invoice', $valid), DomainException::class, 'business owner');
    assert_throws(fn() => pl_save_document_series($f['actor_id'], $f['company_id'], $f['book_id'], 'invoice', array_replace($valid, ['revision' => $current['revision'] + 1])), DomainException::class, 'Someone changed this series');
    assert_throws(fn() => pl_save_document_series($f['actor_id'], $f['company_id'], $f['book_id'], 'invoice', array_replace($valid, ['reset_rule' => 'yearly', 'year_segment' => false])), DomainException::class, 'yearly reset needs the year segment');
    assert_throws(fn() => pl_save_document_series($f['actor_id'], $f['company_id'], $f['book_id'], 'invoice', array_replace($valid, ['prefix' => 'BILL'])), DomainException::class, 'already uses that prefix');
    foreach ([['prefix' => 'inv'], ['prefix' => '1INV'], ['padding' => 0], ['padding' => 13], ['reset_rule' => 'monthly'], ['next_number' => 0], ['reason' => '']] as $bad) {
        assert_throws(fn() => pl_save_document_series($f['actor_id'], $f['company_id'], $f['book_id'], 'invoice', array_replace($valid, $bad)), DomainException::class);
    }
    $saved = pl_save_document_series($f['actor_id'], $f['company_id'], $f['book_id'], 'invoice', array_replace($valid, ['next_number' => 120]));
    assert_same('SI-' . '2026' . '-0120', pl_document_series_format($saved, 2026, 120));
    assert_same(2, $saved['revision']);
    assert_same('SI-2026-0120', series_post($f, 'invoice', '1000', '2026-04-01')['number']);
    $now = series_row($f, 'invoice');
    assert_same(121, $now['next_number']);
    assert_throws(fn() => pl_save_document_series($f['actor_id'], $f['company_id'], $f['book_id'], 'invoice', array_replace($valid, ['prefix' => 'SI', 'padding' => 4, 'next_number' => 120, 'revision' => $now['revision']])), DomainException::class, 'only be raised');
    // The database guard refuses the same move even without the service.
    assert_throws(fn() => DB::update('pl_document_series', ['next_number' => 2], 'id=%i', $now['id']), Throwable::class, 'only be raised');
    assert_throws(fn() => DB::update('pl_document_series', ['document_type' => 'bill'], 'id=%i', $now['id']), Throwable::class, 'identity is immutable');
    $history = pl_core_history($f['actor_id'], $f['company_id'], $f['book_id'], 'document_series', $now['id']);
    assert_same('Shorter customer numbering', $history[0]['reason']);
});

test('document lists and record screens find a document by its issued number', function (): void {
    $f = series_fixture();
    $posted = series_post($f);
    $page = pl_page_ar_documents($f['actor_id'], $f['company_id'], $f['book_id'], 'ar', ['q' => 'INV-2026-000001']);
    assert_same(1, $page['total']);
    assert_same($posted['id'], $page['documents'][0]['id']);
    assert_same('INV-2026-000001', $page['documents'][0]['number']);
    assert_same(1, pl_page_ar_documents($f['actor_id'], $f['company_id'], $f['book_id'], 'ar', ['q' => '2026-000001'])['total']);
    assert_same(0, pl_page_ar_documents($f['actor_id'], $f['company_id'], $f['book_id'], 'ar', ['q' => 'INV-2026-000002'])['total']);
    // A draft has no issued number and is still findable by its derived one.
    $draft = pl_save_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], series_input($f));
    assert_same(1, pl_page_ar_documents($f['actor_id'], $f['company_id'], $f['book_id'], 'ar', ['q' => 'INV-' . str_pad((string) $draft['id'], 6, '0', STR_PAD_LEFT)])['total']);
});
