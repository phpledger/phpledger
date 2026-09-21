<?php
declare(strict_types=1);

/** Internal, short-lived command context; never taken from request globals. */
function pl_correction_context(?array $set = null): array
{
    static $context = [];
    if ($set !== null) { $context = $set; }
    return $context;
}

/** Resolve only an actual supported source, never a free-form journal reference. */
function pl_correction_source(int $companyId, int $bookId, string $type, string $reference): ?array
{
    $prefix = $type === 'general_journal' ? 'general' : 'document';
    if (!in_array($type, ['general_journal', 'receipt', 'expense'], true)
        || !preg_match('/^' . $prefix . ':([1-9][0-9]*)$/D', $reference, $match)) { return null; }
    $table = $type === 'general_journal' ? 'pl_general_drafts' : 'pl_documents';
    $row = DB::queryFirstRow("SELECT * FROM {$table} WHERE company_id=%i AND book_id=%i AND id=%i FOR SHARE", $companyId, $bookId, (int) $match[1]);
    if (!$row || ($type !== 'general_journal' && $row['kind'] !== $type)) { return null; }
    return $row;
}

function pl_correction_snapshot(array $row): array
{
    unset($row['creation_key'], $row['creation_hash']);
    if (isset($row['lines']) && is_string($row['lines'])) {
        $row['lines'] = json_decode($row['lines'], true, 512, JSON_THROW_ON_ERROR);
    }
    return $row;
}

/** Called only by the central posting funnel, within its existing transaction. */
function pl_correction_track_posting(int $actorId, int $companyId, int $bookId, int $journalId, array $payload, ?int $reversalOf = null): void
{
    if ($reversalOf !== null) { return; }
    $source = pl_correction_source($companyId, $bookId, $payload['source_type'], $payload['source_reference']);
    if ($source === null) { return; }
    if (DB::queryFirstField('SELECT id FROM pl_posting_identities WHERE book_id=%i AND source_type=%s AND source_id=%i FOR UPDATE', $bookId, $payload['source_type'], $source['id'])) { return; }
    DB::insert('pl_posting_identities', ['company_id' => $companyId, 'book_id' => $bookId, 'source_type' => $payload['source_type'], 'source_id' => $source['id'], 'original_journal_id' => $journalId]);
    $identityId = (int) DB::insertId();
    $source['journal_id'] = $journalId;
    DB::insert('pl_posting_revisions', ['identity_id' => $identityId, 'company_id' => $companyId, 'book_id' => $bookId,
        'revision' => $source['revision'], 'journal_id' => $journalId,
        'source_snapshot' => json_encode(pl_correction_snapshot($source), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'actor_id' => $actorId]);
}

/** Called after exact idempotency replay detection, before any posting writes. */
function pl_correction_assert_posting_allowed(int $companyId, int $bookId, array $payload, ?int $reversalOf = null): void
{
    if ($reversalOf !== null) { return; }
    $source = pl_correction_source($companyId, $bookId, $payload['source_type'], $payload['source_reference']);
    if ($source === null) {
        $prefix = $payload['source_type'] === 'general_journal' ? 'general' : 'document';
        if (in_array($payload['source_type'], ['general_journal','receipt','expense'], true) && preg_match('/^' . $prefix . ':[1-9][0-9]*$/D', $payload['source_reference'])) {
            throw new DomainException('The posting source is not available in this company and book.');
        }
        return;
    }
    $identity = DB::queryFirstRow('SELECT id FROM pl_posting_identities WHERE book_id=%i AND source_type=%s AND source_id=%i FOR UPDATE', $bookId, $payload['source_type'], $source['id']);
    if (!$identity && $source['journal_id'] === null) { return; }
    $context = pl_correction_context();
    if (($context['identity_id'] ?? null) !== (int) ($identity['id'] ?? 0)
        || ($context['posting_key'] ?? null) !== $payload['idempotency_key']) {
        throw new DomainException('This source already has a posting. Use the same-identity correction service.');
    }
}

/** Source-input command: no invoice/bill documents or browser workflow are introduced. */
function pl_correct_source(int $actorId, int $companyId, int $bookId, string $sourceType, int $sourceId, int $expectedRevision, array $input, ?string $date, string $key, string $reason): array
{
    if (!in_array($sourceType, ['receipt', 'expense', 'general_journal'], true)) {
        throw new DomainException('This source requires its own correction workflow.');
    }
    $key = pl_request_key(pl_ledger_text($key, 'Correction request key', 128));
    $reason = pl_ledger_text($reason, 'Correction reason', 400);
    $date = $date === null ? null : pl_ledger_date($date);
    $data = $sourceType === 'general_journal' ? pl_normalize_general_draft($input) : pl_normalize_document($input);
    // General journals may carry explicit line-level frozen FX posting inputs.
    if ($sourceType === 'general_journal') {
        foreach ($data['lines'] as $index => &$line) {
            $line += array_intersect_key($input['lines'][$index], array_flip(['currency','amount_fc','rate','rate_type','rate_source_id','amount_base','rate_is_stale','ic_counterparty_entity_id']));
        }
        unset($line);
    }
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $sourceType, $sourceId, $expectedRevision, $data, $date, $key, $reason): array {
        $member = pl_require_company_access($actorId, $companyId, true);
        $book = pl_ledger_book($companyId, $bookId, true);
        pl_require_book_ready($companyId);
        if ($sourceType === 'general_journal') {
            $normalized = pl_normalize_journal(['date' => $data['document_date'], 'currency' => $book['currency'], 'source_type' => $sourceType, 'source_reference' => 'general:' . $sourceId, 'idempotency_key' => $key, 'description' => $data['description'], 'lines' => $data['lines']]);
            $data['lines'] = $normalized['lines'];
        }
        $hash = hash('sha256', json_encode([$companyId, $bookId, $sourceType, $sourceId, $expectedRevision, $data, $date, $reason], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $receipt = DB::queryFirstRow('SELECT payload_hash,result_json FROM pl_correction_actions WHERE company_id=%i AND book_id=%i AND request_key=%s FOR UPDATE', $companyId, $bookId, $key);
        if ($receipt) {
            if (!hash_equals($receipt['payload_hash'], $hash)) { throw new DomainException('This correction key belongs to a different request.'); }
            $result = json_decode($receipt['result_json'], true, 512, JSON_THROW_ON_ERROR);
            ksort($result);
            return $result;
        }
        $reference = ($sourceType === 'general_journal' ? 'general:' : 'document:') . $sourceId;
        $raw = pl_correction_source($companyId, $bookId, $sourceType, $reference);
        if (!$raw || $raw['journal_id'] === null) { throw new DomainException('Only a posted source in this company and book can be corrected.'); }
        if ($sourceType !== 'general_journal' && DB::queryFirstField('SELECT document_id FROM pl_pos_sales WHERE document_id=%i AND company_id=%i AND book_id=%i', $sourceId, $companyId, $bookId)) {
            throw new DomainException('POS snapshots require their own correction workflow; use the existing linked reversal.');
        }
        // Existing installations are registered lazily without touching immutable old rows/hashes.
        pl_correction_track_posting((int) $raw['updated_by'], $companyId, $bookId, (int) $raw['journal_id'], ['source_type' => $sourceType, 'source_reference' => $reference]);
        $identity = DB::queryFirstRow('SELECT * FROM pl_posting_identities WHERE book_id=%i AND source_type=%s AND source_id=%i FOR UPDATE', $bookId, $sourceType, $sourceId);
        $current = DB::queryFirstRow('SELECT * FROM pl_posting_revisions WHERE identity_id=%i ORDER BY revision DESC LIMIT 1 FOR UPDATE', $identity['id']);
        if ($expectedRevision !== (int) $current['revision']) { throw new DomainException('This source revision changed. Review its latest version before correcting.'); }
        $original = pl_get_journal($actorId, $companyId, $bookId, (int) $current['journal_id']);
        if (DB::queryFirstField('SELECT id FROM pl_journals WHERE reversal_of_id=%i FOR UPDATE', $original['id'])) { throw new DomainException('The current posting has already been reversed.'); }
        pl_open_item_assert_correction_allowed($companyId, $bookId, $original['id']);
        $reversalDate = $date ?? gmdate('Y-m-d');
        if ($reversalDate !== gmdate('Y-m-d') && (!pl_user_can($actorId, $companyId, 'journal.reverse_backdated') || $reversalDate !== $original['journal_date'])) {
            throw new DomainException('Only a role with the backdated-reversal permission may use the original posting date instead of the cancellation date.');
        }
        if ($data['document_date'] < $reversalDate) { throw new DomainException('A corrected posting cannot precede its reversing entry.'); }
        if ($data['reference'] !== $raw['reference']) { throw new DomainException('A correction must retain the same document reference.'); }
        if ($sourceType !== 'general_journal' && $data['kind'] !== $sourceType) { throw new DomainException('A correction must retain the source kind.'); }
        $snapshot = array_replace(pl_correction_snapshot($raw), $data, ['revision' => $expectedRevision + 1]);
        if ($sourceType === 'general_journal') {
            $payload = ['date' => $data['document_date'], 'currency' => $book['currency'], 'description' => $data['description'], 'lines' => $data['lines']];
        } else {
            pl_validate_document_accounts($companyId, $bookId, $snapshot);
            $payload = pl_document_posting_payload($snapshot, $book['currency']);
        }
        $suffix = hash('sha256', $key);
        $payload['source_type'] = $sourceType;
        $payload['source_reference'] = $reference;
        $payload['idempotency_key'] = 'correction:' . $suffix . ':post';
        $priorContext = pl_correction_context();
        pl_correction_context(['identity_id' => (int) $identity['id'], 'posting_key' => $payload['idempotency_key']]);
        try {
            $reversal = pl_reverse_journal($actorId, $companyId, $bookId, $original['id'], $reversalDate, 'correction:' . $suffix . ':reverse', $reason);
            $replacement = pl_post_journal($actorId, $companyId, $bookId, $payload);
            $snapshot['journal_id'] = $replacement['id'];
            $snapshot['updated_by'] = $actorId;
            $snapshot['updated_at'] = gmdate('Y-m-d H:i:s');
            DB::insert('pl_posting_revisions', ['identity_id' => $identity['id'], 'company_id' => $companyId, 'book_id' => $bookId,
                'revision' => $expectedRevision + 1, 'journal_id' => $replacement['id'], 'source_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'actor_id' => $actorId]);
            $result = ['identity_id' => (int) $identity['id'], 'source_type' => $sourceType, 'source_id' => $sourceId, 'revision' => $expectedRevision + 1,
                'original_journal_id' => (int) $identity['original_journal_id'], 'superseded_journal_id' => $original['id'], 'reversal_journal_id' => $reversal['id'], 'journal_id' => $replacement['id'], 'reversal_date' => $reversalDate];
            ksort($result);
            DB::insert('pl_correction_actions', ['company_id' => $companyId, 'book_id' => $bookId, 'identity_id' => $identity['id'], 'request_key' => $key, 'payload_hash' => $hash,
                'actor_id' => $actorId, 'reason' => $reason, 'reversal_date' => $reversalDate, 'result_json' => json_encode($result, JSON_THROW_ON_ERROR)]);
            if ($sourceType === 'general_journal') { pl_core_audit($actorId, $companyId, $bookId, 'general_journal', $sourceId, 'corrected', $reason, json_decode($current['source_snapshot'], true, 512, JSON_THROW_ON_ERROR), $snapshot); }
            return $result;
        } finally { pl_correction_context($priorContext); }
    });
}

/** Scoped immutable revision snapshots, including cancellation links, for review/API. */
function pl_source_posting_history(int $actorId, int $companyId, int $bookId, string $type, int $sourceId): array
{
    pl_require_company_access($actorId, $companyId);
    pl_ledger_book($companyId, $bookId);
    $rows = DB::query('SELECT v.revision,v.journal_id,v.source_snapshot,v.actor_id,v.recorded_at,r.id AS reversal_journal_id FROM pl_posting_identities i JOIN pl_posting_revisions v ON v.identity_id=i.id LEFT JOIN pl_journals r ON r.reversal_of_id=v.journal_id WHERE i.company_id=%i AND i.book_id=%i AND i.source_type=%s AND i.source_id=%i ORDER BY v.revision', $companyId, $bookId, $type, $sourceId);
    foreach ($rows as &$row) {
        $row['revision'] = (int) $row['revision']; $row['journal_id'] = (int) $row['journal_id']; $row['actor_id'] = (int) $row['actor_id'];
        $row['reversal_journal_id'] = $row['reversal_journal_id'] === null ? null : (int) $row['reversal_journal_id'];
        $row['source_snapshot'] = json_decode($row['source_snapshot'], true, 512, JSON_THROW_ON_ERROR);
    }
    unset($row);
    return $rows;
}
