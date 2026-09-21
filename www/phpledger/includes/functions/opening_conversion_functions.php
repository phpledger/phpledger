<?php
declare(strict_types=1);

/** Pure reviewed allocation of an existing opening journal; confirmation never posts GL. */
function pl_opening_conversion_review(int $actorId, int $companyId, int $bookId, int $cutoverId, array $mappings): array
{
    pl_require_company_access($actorId, $companyId, true);
    if (!pl_user_can($actorId, $companyId, 'opening.manage')) { throw new DomainException('Your role cannot review opening debt conversion.'); }
    $book = pl_ledger_book($companyId, $bookId, true);
    pl_require_book_ready($companyId);
    $cutover = DB::queryFirstRow('SELECT * FROM pl_opening_cutovers WHERE id = %i AND company_id = %i AND book_id = %i FOR SHARE', $cutoverId, $companyId, $bookId);
    if (!$cutover || $cutover['status'] !== 'confirmed' || !$cutover['journal_id']) { throw new DomainException('Choose a confirmed nonzero opening cutover.'); }
    if (DB::queryFirstField('SELECT id FROM pl_opening_conversions WHERE cutover_id = %i FOR SHARE', $cutoverId)) { throw new DomainException('This opening cutover is already converted.'); }
    if (!array_is_list($mappings) || count($mappings) > 500) { throw new DomainException('Supply an explicit party mapping for every unpaid opening document.'); }
    $byDocument = [];
    foreach ($mappings as $mapping) {
        if (!is_array($mapping)) { throw new DomainException('Invalid opening document mapping.'); }
        $id = pl_oi_id($mapping, 'opening_document_id');
        if (isset($byDocument[$id])) { throw new DomainException('Duplicate opening document mapping.'); }
        $byDocument[$id] = pl_oi_id($mapping, 'party_id');
    }
    $documents = DB::query('SELECT d.*, a.code AS account_code, a.role, a.currency AS account_currency, a.is_active FROM pl_opening_documents d JOIN pl_accounts a ON a.id = d.account_id WHERE d.cutover_id = %i AND d.company_id = %i AND d.book_id = %i ORDER BY d.id FOR SHARE', $cutoverId, $companyId, $bookId);
    if ($documents === [] || count($documents) !== count($byDocument)) { throw new DomainException('Map every unpaid opening document exactly once.'); }
    $accounts = []; $review = [];
    foreach ($documents as $document) {
        $id = (int) $document['id']; $accountId = (int) $document['account_id'];
        if (!isset($byDocument[$id])) { throw new DomainException('An opening document has no explicit party mapping.'); }
        $party = DB::queryFirstRow('SELECT * FROM pl_parties WHERE id = %i AND company_id = %i FOR SHARE', $byDocument[$id], $companyId);
        $role = $document['kind'] === 'receivable' ? 'is_customer' : 'is_vendor';
        if (!$party || !(bool) $party[$role]) { throw new DomainException('Map each document to a party with the correct customer/vendor role in this company.'); }
        if (!(bool) $document['is_active'] || $document['account_currency'] !== null || $document['role'] !== ($document['kind'] === 'receivable' ? 'receivables' : 'payables')) { throw new DomainException('Opening control account is inactive or incompatible.'); }
        if (!isset($accounts[$accountId])) {
            $lines = DB::query('SELECT l.*, j.journal_date FROM pl_journal_lines l JOIN pl_journals j ON j.id = l.journal_id WHERE l.company_id = %i AND l.book_id = %i AND l.account_id = %i ORDER BY l.id FOR SHARE', $companyId, $bookId, $accountId);
            if (count($lines) !== 1 || (int) $lines[0]['journal_id'] !== (int) $cutover['journal_id']) { throw new DomainException('Control has activity beyond its single opening basis; a separately reviewed conversion is required.'); }
            $line = $lines[0];
            $normal = $document['kind'] === 'receivable' ? $line['debit'] : $line['credit'];
            if ($line['currency'] !== $book['currency'] || bccomp($line['rate'], '1', 12) !== 0 || bccomp($normal, $line['amount_base'], 4) !== 0 || bccomp($line['amount_fc'], $line['amount_base'], 4) !== 0) { throw new DomainException('The opening carrying amount is ambiguous; this conversion requires explicit domestic opening evidence.'); }
            if (DB::queryFirstField('SELECT id FROM pl_open_item_entries WHERE journal_line_id = %i LIMIT 1 FOR SHARE', $line['id'])) { throw new DomainException('Opening basis already has allocations.'); }
            $accounts[$accountId] = ['account_id' => $accountId, 'account_code' => $document['account_code'], 'journal_line_id' => (int) $line['id'], 'journal_date' => $line['journal_date'], 'basis_amount' => $normal, 'document_total' => '0.0000'];
        }
        $accounts[$accountId]['document_total'] = bcadd($accounts[$accountId]['document_total'], $document['outstanding'], 4);
        $review[] = ['opening_document_id' => $id, 'party_id' => $byDocument[$id], 'party_name' => $party['legal_name'], 'original_party' => $document['party'], 'reference' => $document['reference'], 'document_date' => $document['document_date'], 'due_date' => $document['due_date'], 'direction' => $document['kind'], 'account_id' => $accountId, 'journal_line_id' => $accounts[$accountId]['journal_line_id'], 'amount_fc' => $document['outstanding'], 'amount_base' => $document['outstanding']];
    }
    foreach ($accounts as $account) {
        if (bccomp($account['basis_amount'], $account['document_total'], 4) !== 0) { throw new DomainException('Unpaid documents do not reconcile exactly with their opening control basis.'); }
    }
    $payload = ['cutover_id' => $cutoverId, 'journal_id' => (int) $cutover['journal_id'], 'currency' => $book['currency'], 'accounts' => array_values($accounts), 'documents' => $review];
    return $payload + ['payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))];
}

function pl_preview_opening_conversion(int $actorId, int $companyId, int $bookId, int $cutoverId, array $mappings): array
{
    return pl_ledger_transaction(fn(): array => pl_opening_conversion_review($actorId, $companyId, $bookId, $cutoverId, $mappings));
}

function pl_confirm_opening_conversion(int $actorId, int $companyId, int $bookId, int $cutoverId, array $mappings, string $expectedHash, bool $confirmed, string $key, string $reason): array
{
    pl_demo_require_setup_action();
    if (!$confirmed) { throw new DomainException('Confirm the party mappings and reconciled opening carrying amounts.'); }
    $key = pl_request_key($key); $reason = pl_ledger_text($reason, 'Conversion reason', 500);
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $cutoverId, $mappings, $expectedHash, $key, $reason): array {
        pl_require_company_access($actorId, $companyId, true);
        if (!pl_user_can($actorId, $companyId, 'opening.manage')) { throw new DomainException('Your role cannot confirm opening debt conversion.'); }
        pl_ledger_book($companyId, $bookId, true);
        $prior = DB::queryFirstRow('SELECT * FROM pl_opening_conversions WHERE book_id = %i AND request_key = %s FOR UPDATE', $bookId, $key);
        $canonicalMappings = $mappings;
        usort($canonicalMappings, static fn(array $a, array $b): int => ($a['opening_document_id'] ?? 0) <=> ($b['opening_document_id'] ?? 0));
        $requestHash = hash('sha256', json_encode([$cutoverId, $canonicalMappings, $expectedHash, $reason], JSON_THROW_ON_ERROR));
        if ($prior) {
            if (!hash_equals($prior['payload_hash'], $requestHash)) { throw new DomainException('Conversion request key has different content.'); }
            return json_decode($prior['result_json'], true, 512, JSON_THROW_ON_ERROR);
        }
        $review = pl_opening_conversion_review($actorId, $companyId, $bookId, $cutoverId, $mappings);
        if (!hash_equals($review['payload_hash'], $expectedHash)) { throw new DomainException('Opening conversion preview changed; review it again.'); }
        foreach ($review['accounts'] as $account) {
            if (!DB::queryFirstField('SELECT account_id FROM pl_open_item_accounts WHERE account_id = %i FOR SHARE', $account['account_id'])) {
                DB::insert('pl_open_item_accounts', ['account_id' => $account['account_id'], 'company_id' => $companyId, 'book_id' => $bookId, 'activated_by' => $actorId, 'reason' => $reason]);
            }
        }
        $items = [];
        foreach ($review['documents'] as $document) {
            DB::insert('pl_open_items', ['company_id' => $companyId, 'book_id' => $bookId, 'party_id' => $document['party_id'], 'control_account_id' => $document['account_id'], 'direction' => $document['direction'], 'currency' => $review['currency'], 'source_reference' => 'opening-document:' . $document['opening_document_id'], 'created_by' => $actorId]);
            $itemId = (int) DB::insertId();
            DB::insert('pl_open_item_entries', ['company_id' => $companyId, 'book_id' => $bookId, 'item_id' => $itemId, 'kind' => 'recognition', 'journal_line_id' => $document['journal_line_id'], 'opening_document_id' => $document['opening_document_id'], 'allocated_amount_fc' => $document['amount_fc'], 'allocated_amount_base' => $document['amount_base']]);
            $items[] = ['opening_document_id' => $document['opening_document_id'], 'item_id' => $itemId];
        }
        $result = ['cutover_id' => $cutoverId, 'journal_id' => $review['journal_id'], 'items' => $items];
        DB::insert('pl_opening_conversions', ['company_id' => $companyId, 'book_id' => $bookId, 'cutover_id' => $cutoverId, 'request_key' => $key, 'payload_hash' => $requestHash, 'review_json' => json_encode($review, JSON_THROW_ON_ERROR), 'result_json' => json_encode($result, JSON_THROW_ON_ERROR), 'reason' => $reason, 'actor_id' => $actorId]);
        return $result;
    });
}
