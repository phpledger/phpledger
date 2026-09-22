<?php
declare(strict_types=1);

/** Period administration shares the posting service's authorization and book lock. */
function pl_period_require_write(int $actorId, int $companyId, bool $reopen = false): void
{
    if (pl_demo_enabled() && !pl_demo_provisioning()) {
        throw new DomainException('Period administration is disabled in the public sample.');
    }
    pl_require_company_access($actorId, $companyId, true);
    if ($reopen && !pl_user_can($actorId, $companyId, 'periods.reopen')) {
        throw new DomainException('Your role cannot reopen a closed period.');
    }
}

function pl_period_request_key(mixed $key): string
{
    $key = pl_ledger_text($key, 'Request key', 128);
    if (!preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $key)) {
        throw new DomainException('Use a valid period request key.');
    }
    return $key;
}

/** A retry returns the original action result, even if the period has changed since. */
function pl_period_existing_receipt(int $companyId, int $bookId, string $key, string $hash): ?array
{
    $receipt = DB::queryFirstRow('SELECT payload_hash, result_json FROM pl_period_actions WHERE company_id = %i AND book_id = %i AND request_key = %s FOR UPDATE', $companyId, $bookId, $key);
    if (!$receipt) {
        return null;
    }
    if (!hash_equals((string) $receipt['payload_hash'], $hash)) {
        throw new DomainException('This request key already belongs to a different period action.');
    }
    $result = json_decode((string) $receipt['result_json'], true, 512, JSON_THROW_ON_ERROR);
    // MySQL normalizes JSON object key order; keep the service result stable on retries.
    ksort($result);
    return $result;
}

/**
 * @param array<string, mixed> $extra 1.3 M17: what the action did beyond changing the status —
 *        which checklist item a tick was for, how many scheduled reversals an opening posted.
 *        It becomes part of the stored `result_json`, so a retry of the same request key returns
 *        the same answer rather than re-deriving one against books that have since moved.
 */
function pl_period_record_action(int $actorId, int $companyId, int $bookId, array $period, string $action, ?string $priorStatus, string $reason, string $key, string $hash, array $extra = []): array
{
    $result = $extra + [
        'id' => (int) $period['id'], 'start_date' => (string) $period['start_date'],
        'end_date' => (string) $period['end_date'], 'status' => (string) $period['status'],
        'revision' => (int) $period['revision'], 'action' => $action,
    ];
    ksort($result);
    DB::insert('pl_period_actions', [
        'company_id' => $companyId, 'book_id' => $bookId, 'period_id' => $result['id'],
        'action' => $action, 'prior_status' => $priorStatus, 'resulting_status' => $result['status'],
        'resulting_revision' => $result['revision'], 'reason' => $reason, 'actor_id' => $actorId,
        'request_key' => $key, 'payload_hash' => $hash,
        'result_json' => json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
    ]);
    return $result;
}

/** Create a nonoverlapping open period; no period may precede the book's start. */
function pl_create_period(int $actorId, int $companyId, int $bookId, array $input): array
{
    $start = pl_ledger_date(pl_ledger_text($input['start_date'] ?? null, 'Start date', 10));
    $end = pl_ledger_date(pl_ledger_text($input['end_date'] ?? null, 'End date', 10));
    if ($end < $start) {
        throw new DomainException('The period end must be on or after its start date.');
    }
    $reason = pl_ledger_text($input['reason'] ?? null, 'Reason', 500);
    $key = pl_period_request_key($input['request_key'] ?? null);
    $hash = hash('sha256', json_encode([$actorId, $companyId, $bookId, 'create', $start, $end, $reason, $key], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $start, $end, $reason, $key, $hash): array {
        pl_period_require_write($actorId, $companyId);
        pl_ledger_book($companyId, $bookId, true);
        $existing = pl_period_existing_receipt($companyId, $bookId, $key, $hash);
        if ($existing !== null) {
            return $existing;
        }
        $companyStart = DB::queryFirstField('SELECT start_date FROM pl_companies WHERE id = %i FOR SHARE', $companyId);
        if ($start < $companyStart) {
            throw new DomainException('An accounting period cannot start before the business start date.');
        }
        if (DB::queryFirstRow('SELECT id FROM pl_periods WHERE company_id = %i AND book_id = %i AND start_date <= %s AND end_date >= %s FOR UPDATE', $companyId, $bookId, $end, $start)) {
            throw new DomainException('These dates overlap an existing accounting period. Choose dates outside every existing period.');
        }
        DB::insert('pl_periods', ['company_id' => $companyId, 'book_id' => $bookId, 'start_date' => $start, 'end_date' => $end, 'status' => 'open', 'revision' => 1]);
        $period = ['id' => (int) DB::insertId(), 'start_date' => $start, 'end_date' => $end, 'status' => 'open', 'revision' => 1];
        // 1.3 M17, owner decision B89: opening a period is what posts the reversals due in it.
        // This is the ordinary case — last month's accruals reverse on the first of this one —
        // and it happens inside the transaction that created the period, under its book lock.
        $reversals = pl_post_due_reversals($actorId, $companyId, $bookId, $period, 'period_open');
        return pl_period_record_action($actorId, $companyId, $bookId, $period, 'create', null, $reason, $key, $hash,
            pl_period_reversal_receipt($reversals));
    });
}

/**
 * What an opening did, flattened to scalars and strings so a retry answers identically.
 *
 * MySQL normalizes the key order of a JSON object, and pl_period_existing_receipt() can only
 * ksort the top level, so anything nested deeper than one list of strings could come back from
 * a retry in a different shape than the call that wrote it.
 *
 * @param array{posted:list<array<string,mixed>>, refused:list<array{reference:string, message:string}>} $reversals
 * @return array<string, mixed>
 */
function pl_period_reversal_receipt(array $reversals): array
{
    $posted = [];
    foreach ($reversals['posted'] as $entry) {
        $posted[] = $entry['original'] . ' reversed by ' . $entry['reference'] . ' on ' . $entry['date'];
    }
    $refused = [];
    foreach ($reversals['refused'] as $entry) {
        $refused[] = $entry['reference'] . ': ' . $entry['message'];
    }
    return ['reversals_posted' => count($posted), 'reversals_refused' => count($refused),
        'reversals' => $posted, 'reversals_not_posted' => $refused];
}

/** Closing prevents new posting; reopening needs owner authority and a fresh revision. */
function pl_change_period_status(int $actorId, int $companyId, int $bookId, int $periodId, string $status, int $expectedRevision, string $reason, string $requestKey): array
{
    if ($periodId < 1 || $expectedRevision < 1 || !in_array($status, ['open', 'closed'], true)) {
        throw new DomainException('Choose a valid period action and reload its current revision.');
    }
    $reason = pl_ledger_text($reason, 'Reason', 500);
    $key = pl_period_request_key($requestKey);
    $action = $status === 'closed' ? 'close' : 'reopen';
    $hash = hash('sha256', json_encode([$actorId, $companyId, $bookId, $periodId, $action, $expectedRevision, $reason, $key], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    // 1.3 M17: the close checklist is evaluated BEFORE the transaction, because the extension
    // point that lets a package add a computed item is a hook, and pl_hook_assert_unlocked()
    // rightly refuses to run one while a book row is held FOR UPDATE. The core items are
    // computed again under the lock below, so a draft or a pending reversal that appears in
    // between still refuses the close; only a package's items are the pre-lock copy.
    $checklist = null;
    if ($status === 'closed') {
        $receipt = pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $key, $hash): ?array {
            pl_period_require_write($actorId, $companyId);
            pl_ledger_book($companyId, $bookId, true);
            return pl_period_existing_receipt($companyId, $bookId, $key, $hash);
        });
        if ($receipt !== null) { return $receipt; }
        $checklist = pl_period_checklist($actorId, $companyId, $bookId, $periodId);
        if (!$checklist['ready']) {
            throw new DomainException(pl_period_close_refusal($checklist['blocking']));
        }
    }
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $periodId, $status, $expectedRevision, $reason, $key, $action, $hash, $checklist): array {
        pl_period_require_write($actorId, $companyId, $status === 'open');
        pl_ledger_book($companyId, $bookId, true);
        $existing = pl_period_existing_receipt($companyId, $bookId, $key, $hash);
        if ($existing !== null) {
            return $existing;
        }
        $period = DB::queryFirstRow('SELECT id, start_date, end_date, status, revision FROM pl_periods WHERE id = %i AND company_id = %i AND book_id = %i FOR UPDATE', $periodId, $companyId, $bookId);
        if (!$period) {
            throw new DomainException('This period is not available in the selected company and book.');
        }
        if ((int) $period['revision'] !== $expectedRevision || $period['status'] === $status) {
            throw new DomainException('This period has changed. Review its current status before submitting a new action.');
        }
        if ($status === 'open') { pl_year_end_assert_period($companyId, $bookId, $period); }
        $priorStatus = (string) $period['status'];
        if ($status === 'closed') {
            // The authoritative pass, under the lock. Only the core items are recomputed here;
            // a package's items came from the pre-lock read and are carried through unchanged,
            // which is stated rather than hidden because it is the one thing a plugin author
            // has to know about this extension point.
            $under = pl_period_checklist_build($actorId, $companyId, $bookId, $periodId, false);
            if (!$under['ready']) {
                throw new DomainException(pl_period_close_refusal($under['blocking']));
            }
            $checklist = pl_period_checklist_result($under['period'], array_merge($under['items'], array_values(array_filter($checklist['items'], fn(array $item): bool => $item['source'] === 'package'))));
        }
        $period['status'] = $status;
        $period['revision'] = $expectedRevision + 1;
        DB::update('pl_periods', ['status' => $status, 'revision' => $period['revision']], 'id = %i AND company_id = %i AND book_id = %i', $periodId, $companyId, $bookId);
        // B89's first path again: reopening a period makes its scheduled reversals due. The
        // ticks are deliberately left alone — the issue says a reopen keeps them.
        $reversals = $status === 'open'
            ? pl_post_due_reversals($actorId, $companyId, $bookId, $period, 'period_reopen')
            : ['posted' => [], 'refused' => []];
        if ($status === 'closed') {
            // 1.2 M8: queued, not fired. The book row above is locked for the rest of this
            // transaction; pl_ledger_transaction() runs this after the commit, outside the lock.
            pl_hook_after_commit('period.closed', [$period, ['company_id' => $companyId, 'book_id' => $bookId,
                'actor_id' => $actorId, 'checklist' => $checklist['items']]]);
        }
        return pl_period_record_action($actorId, $companyId, $bookId, $period, $action, $priorStatus, $reason, $key, $hash,
            pl_period_reversal_receipt($reversals) + ['checklist_warnings' => $checklist === null ? [] : array_column($checklist['warnings'], 'label')]);
    });
}

function pl_list_periods(int $actorId, int $companyId, int $bookId): array
{
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId): array {
        pl_require_company_access($actorId, $companyId);
        pl_ledger_book($companyId, $bookId);
        return DB::query('SELECT id, start_date, end_date, status, revision FROM pl_periods WHERE company_id = %i AND book_id = %i ORDER BY start_date DESC, id DESC FOR SHARE', $companyId, $bookId);
    });
}

/** Recent history is intentionally bounded; durable rows remain in the database. */
function pl_period_history(int $actorId, int $companyId, int $bookId): array
{
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId): array {
        pl_require_company_access($actorId, $companyId);
        pl_ledger_book($companyId, $bookId);
        return DB::query('SELECT a.id, a.period_id, a.action, a.prior_status, a.resulting_status, a.resulting_revision, a.reason, a.created_at, u.display_name AS actor_name, p.start_date, p.end_date FROM pl_period_actions a JOIN pl_users u ON u.id = a.actor_id JOIN pl_periods p ON p.id = a.period_id AND p.company_id = a.company_id AND p.book_id = a.book_id WHERE a.company_id = %i AND a.book_id = %i ORDER BY a.id DESC LIMIT 100 FOR SHARE', $companyId, $bookId);
    });
}
