<?php
declare(strict_types=1);

/** Strict, bounded CSV input. A preview never infers missing columns or money formats. */
function pl_opening_csv(string $csv, array $headers): array
{
    if (strlen($csv) > 524288 || !mb_check_encoding($csv, 'UTF-8') || str_contains($csv, "\0")) {
        throw new DomainException('Use UTF-8 CSV no larger than 512 KiB.');
    }
    $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
    if (trim($csv) === '') {
        return [];
    }
    // fgetcsv accepts unterminated quotes. Reject them before financial parsing.
    $state = 'start';
    for ($i = 0, $length = strlen($csv); $i < $length; $i++) {
        $character = $csv[$i];
        if ($state === 'quoted') {
            if ($character === '"') { $state = 'after_quote'; }
        } elseif ($state === 'after_quote') {
            if ($character === '"') {
                $state = 'quoted';
            } elseif (in_array($character, [',', "\r", "\n"], true)) {
                $state = 'start';
            } else {
                throw new DomainException('CSV contains malformed quoted fields.');
            }
        } elseif ($character === '"') {
            if ($state !== 'start') { throw new DomainException('CSV contains malformed quoted fields.'); }
            $state = 'quoted';
        } elseif (in_array($character, [',', "\r", "\n"], true)) {
            $state = 'start';
        } else {
            $state = 'unquoted';
        }
    }
    if ($state === 'quoted') { throw new DomainException('CSV contains an unterminated quoted field.'); }
    $stream = fopen('php://temp', 'r+');
    if ($stream === false) {
        throw new RuntimeException('Cannot read the import.');
    }
    try {
        fwrite($stream, $csv);
        rewind($stream);
        $actual = fgetcsv($stream, null, ',', '"', '');
        if ($actual !== $headers) {
            throw new DomainException('CSV columns must be exactly: ' . implode(',', $headers));
        }
        $rows = [];
        while (($values = fgetcsv($stream, null, ',', '"', '')) !== false) {
            if ($values === [null]) {
                continue;
            }
            if (count($values) !== count($headers) || count($rows) >= 500) {
                throw new DomainException('CSV needs the same columns on each row and at most 500 data rows.');
            }
            $rows[] = array_combine($headers, array_map(static fn ($value): string => trim((string) $value), $values));
        }
        return $rows;
    } finally {
        fclose($stream);
    }
}

function pl_opening_normalize(int $actorId, int $companyId, int $bookId, array $input): array
{
    pl_require_company_access($actorId, $companyId, true);
    $book = pl_ledger_book($companyId, $bookId);
    $date = pl_ledger_date(pl_ledger_text($input['cutover_date'] ?? null, 'Cutover date', 10));
    $start = DB::queryFirstField('SELECT start_date FROM pl_companies WHERE id = %i FOR SHARE', $companyId);
    if ($date !== $start) {
        throw new DomainException('Cutover must be the accounting start date. Bring balances at the close of that day; new transactions start the following day.');
    }
    $source = pl_ledger_text($input['source'] ?? null, 'Source records reference', 200);
    $balances = $input['balances'] ?? null;
    $documents = $input['unpaid_documents'] ?? [];
    if (!is_array($balances) || !array_is_list($balances) || count($balances) > 500
        || !is_array($documents) || !array_is_list($documents) || count($documents) > 500) {
        throw new DomainException('Supply at most 500 balance rows and 500 unpaid documents.');
    }
    $accounts = DB::query('SELECT id, code, legacy_code, type, role, is_active FROM pl_accounts WHERE company_id = %i AND book_id = %i ORDER BY code FOR SHARE', $companyId, $bookId);
    $byCode = [];
    foreach ($accounts as $account) {
        $byCode[(string) $account['code']] = $account;
    }
    // An import prepared against the numbers this chart used before the structured-code
    // conversion (B56) still resolves; the account itself may still appear only once.
    foreach ($accounts as $account) {
        $legacy = (string) ($account['legacy_code'] ?? '');
        if ($legacy !== '' && !isset($byCode[$legacy])) { $byCode[$legacy] = $account; }
    }
    $lines = [];
    $canonicalBalances = [];
    $controlBalances = [];
    $debits = '0.0000';
    $credits = '0.0000';
    $seenAccounts = [];
    foreach ($balances as $row) {
        if (!is_array($row)) {
            throw new DomainException('Every opening balance must have an account code, debit and credit.');
        }
        $code = pl_ledger_text($row['account_code'] ?? null, 'Account code', 20);
        $account = $byCode[$code] ?? null;
        if (!$account || !(bool) $account['is_active'] || isset($seenAccounts[(string) $account['code']])) {
            throw new DomainException('Use each active account code from this book only once: ' . $code);
        }
        $seenAccounts[(string) $account['code']] = true;
        $debit = pl_amount(pl_ledger_text($row['debit'] ?? '0', 'Debit', 21));
        $credit = pl_amount(pl_ledger_text($row['credit'] ?? '0', 'Credit', 21));
        if (bccomp($debit, '0', 4) > 0 && bccomp($credit, '0', 4) > 0) {
            throw new DomainException('Each opening account has either a debit or a credit balance.');
        }
        if (in_array($account['role'], ['receivables', 'payables'], true)) {
            $balance = $account['role'] === 'receivables' ? bcsub($debit, $credit, 4) : bcsub($credit, $debit, 4);
            if (bccomp($balance, '0', 4) < 0) {
                throw new DomainException('Credit notes and advance balances need a separately reviewed import; this cutover supports positive unpaid invoices and bills.');
            }
            // Key the control reconciliation by the account's own code, whichever number the
            // import used to name it (B56), so the totals below always meet the same account.
            $controlBalances[(string) $account['code']] = $balance;
        }
        if (bccomp($debit, '0', 4) === 0 && bccomp($credit, '0', 4) === 0) {
            continue;
        }
        $canonicalBalances[] = ['account_code' => $code, 'debit' => $debit, 'credit' => $credit];
        $lines[] = ['account_id' => (int) $account['id'], 'debit' => $debit, 'credit' => $credit, 'description' => 'Opening balance'];
        $debits = bcadd($debits, $debit, 4);
        $credits = bcadd($credits, $credit, 4);
    }
    if (bccomp($debits, $credits, 4) !== 0) {
        throw new DomainException('Opening debits and credits do not balance. No balancing amount will be guessed.');
    }
    $zeroConfirmed = ($input['zero_confirmed'] ?? false) === true;
    if ($lines === [] && !$zeroConfirmed) {
        throw new DomainException('Explicitly confirm zero opening balances and no unpaid documents, or enter your balances.');
    }
    $canonicalDocuments = [];
    $documentTotals = [];
    $seenDocuments = [];
    foreach ($documents as $row) {
        if (!is_array($row)) {
            throw new DomainException('Every unpaid document needs its original reference and outstanding amount.');
        }
        $kind = pl_ledger_text($row['kind'] ?? null, 'Document kind', 10);
        $code = pl_ledger_text($row['account_code'] ?? null, 'Control account code', 20);
        $account = $byCode[$code] ?? null;
        $role = ['receivable' => 'receivables', 'payable' => 'payables'][$kind] ?? null;
        if ($role === null || !$account || !(bool) $account['is_active'] || $account['role'] !== $role) {
            throw new DomainException('Use receivable with a receivables account, or payable with a payables account from this book.');
        }
        $party = pl_ledger_text($row['party'] ?? null, 'Customer or vendor', 160);
        $reference = pl_ledger_text($row['reference'] ?? null, 'Original document reference', 120);
        $documentDate = pl_ledger_date(pl_ledger_text($row['document_date'] ?? null, 'Original document date', 10));
        $dueDate = pl_ledger_date(pl_ledger_text($row['due_date'] ?? null, 'Due date', 10));
        $amount = pl_amount(pl_ledger_text($row['outstanding'] ?? null, 'Outstanding amount', 21));
        if ($documentDate > $date || $dueDate < $documentDate || bccomp($amount, '0', 4) <= 0) {
            throw new DomainException('Unpaid documents must exist by cutover, have a positive remaining amount and a due date on or after their document date.');
        }
        $identity = hash('sha256', json_encode([$kind, mb_strtolower($party, 'UTF-8'), mb_strtolower($reference, 'UTF-8')], JSON_THROW_ON_ERROR));
        if (isset($seenDocuments[$identity])) {
            throw new DomainException('Duplicate unpaid document: ' . $reference);
        }
        $seenDocuments[$identity] = true;
        $documentTotals[(string) $account['code']] = bcadd($documentTotals[(string) $account['code']] ?? '0', $amount, 4);
        $canonicalDocuments[] = ['kind' => $kind, 'account_code' => $code, 'account_id' => (int) $account['id'], 'party' => $party,
            'reference' => $reference, 'document_date' => $documentDate, 'due_date' => $dueDate, 'outstanding' => $amount, 'identity_hash' => $identity];
    }
    foreach ($accounts as $account) {
        if (in_array($account['role'], ['receivables', 'payables'], true)
            && bccomp($controlBalances[$account['code']] ?? '0', $documentTotals[$account['code']] ?? '0', 4) !== 0) {
            throw new DomainException('Unpaid-document totals must exactly equal opening control account ' . $account['code'] . '. Enter only the remaining unpaid amount, without posting documents again.');
        }
    }
    return ['cutover_date' => $date, 'currency' => (string) $book['currency'], 'source' => $source,
        'zero_confirmed' => $zeroConfirmed, 'balances' => $canonicalBalances, 'lines' => $lines,
        'unpaid_documents' => $canonicalDocuments, 'total_debit' => $debits, 'total_credit' => $credits];
}

function pl_opening_hash(array $payload): string
{
    return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
}

function pl_get_opening_preview(int $actorId, int $companyId, int $bookId, int $previewId): array
{
    pl_require_company_access($actorId, $companyId);
    pl_ledger_book($companyId, $bookId);
    $row = DB::queryFirstRow('SELECT * FROM pl_opening_previews WHERE id = %i AND company_id = %i AND book_id = %i FOR SHARE', $previewId, $companyId, $bookId);
    if (!$row) {
        throw new DomainException('This opening preview is not available in the selected book.');
    }
    $row['payload'] = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);
    return $row;
}

function pl_opening_history(int $actorId, int $companyId, int $bookId): array
{
    pl_require_company_access($actorId, $companyId);
    pl_ledger_book($companyId, $bookId);
    return DB::query('SELECT c.*, p.cutover_date, p.payload_hash FROM pl_opening_cutovers c JOIN pl_opening_previews p ON p.id = c.preview_id WHERE c.company_id = %i AND c.book_id = %i ORDER BY c.id DESC', $companyId, $bookId);
}

function pl_preview_opening(int $actorId, int $companyId, int $bookId, array $input, string $requestKey): array
{
    pl_demo_require_setup_action();
    pl_request_key($requestKey);
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $input, $requestKey): array {
        pl_require_company_access($actorId, $companyId, true);
        pl_ledger_book($companyId, $bookId, true);
        $payload = pl_opening_normalize($actorId, $companyId, $bookId, $input);
        $hash = pl_opening_hash($payload);
        $existing = DB::queryFirstRow('SELECT id, payload_hash FROM pl_opening_previews WHERE book_id = %i AND request_key = %s FOR UPDATE', $bookId, $requestKey);
        if ($existing) {
            if (!hash_equals($existing['payload_hash'], $hash)) {
                throw new DomainException('This preview request already contains different data. Start a new preview.');
            }
            return pl_get_opening_preview($actorId, $companyId, $bookId, (int) $existing['id']);
        }
        if (DB::queryFirstField('SELECT setup_status FROM pl_companies WHERE id = %i FOR SHARE', $companyId) !== 'opening_required') {
            throw new DomainException('Only a business awaiting opening balances can prepare a cutover.');
        }
        DB::insert('pl_opening_previews', ['company_id' => $companyId, 'book_id' => $bookId, 'cutover_date' => $payload['cutover_date'],
            'request_key' => $requestKey, 'payload_hash' => $hash, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'created_by' => $actorId]);
        return pl_get_opening_preview($actorId, $companyId, $bookId, (int) DB::insertId());
    });
}

/** Book lock held: a completed cutover establishes the earliest new transaction date. */
function pl_opening_assert_posting_allowed(int $companyId, int $bookId, array $payload): void
{
    if ($payload['source_type'] === 'opening_balance') {
        $cutover = DB::queryFirstRow("SELECT c.id, c.journal_id, p.payload FROM pl_opening_cutovers c JOIN pl_opening_previews p ON p.id = c.preview_id WHERE c.company_id = %i AND c.book_id = %i AND c.id = %s AND c.status = 'confirmed' FOR SHARE", $companyId, $bookId, $payload['source_reference']);
        if (!$cutover || $cutover['journal_id'] !== null) {
            throw new DomainException('An opening journal requires its pending confirmed cutover source.');
        }
        $source = json_decode($cutover['payload'], true, 512, JSON_THROW_ON_ERROR);
        $expected = pl_normalize_journal(['date' => $source['cutover_date'], 'currency' => $source['currency'],
            'source_type' => 'opening_balance', 'source_reference' => (string) $cutover['id'],
            'idempotency_key' => 'opening:' . $cutover['id'], 'description' => 'Opening cutover: ' . $source['source'], 'lines' => $source['lines']]);
        if ($payload !== $expected) {
            throw new DomainException('The opening journal must exactly match its confirmed preview.');
        }
        return;
    }
    $date = DB::queryFirstField("SELECT p.cutover_date FROM pl_opening_cutovers c JOIN pl_opening_previews p ON p.id = c.preview_id WHERE c.company_id = %i AND c.book_id = %i AND c.status = 'confirmed' FOR SHARE", $companyId, $bookId);
    if ($date !== null && $payload['date'] <= $date) {
        // Only the opening correction service may undo its own source on cutover day.
        $openingReversal = $payload['source_type'] === 'reversal' && DB::queryFirstField("SELECT id FROM pl_opening_cutovers WHERE book_id = %i AND status = 'confirmed' AND journal_id = %s FOR SHARE", $bookId, $payload['source_reference']);
        if (!$openingReversal) {
            throw new DomainException('New transactions must be dated after the confirmed opening cutover.');
        }
    }
}

function pl_confirm_opening(int $actorId, int $companyId, int $bookId, int $previewId, string $expectedHash, bool $confirmed): array
{
    pl_demo_require_setup_action();
    if (!$confirmed) {
        throw new DomainException('Confirm that the preview agrees to your source records and includes all unpaid documents.');
    }
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $previewId, $expectedHash): array {
        pl_require_company_access($actorId, $companyId, true);
        pl_ledger_book($companyId, $bookId, true);
        $preview = pl_get_opening_preview($actorId, $companyId, $bookId, $previewId);
        if (!hash_equals($preview['payload_hash'], $expectedHash)) {
            throw new DomainException('The preview identity changed. Review the opening data again.');
        }
        $existing = DB::queryFirstRow('SELECT * FROM pl_opening_cutovers WHERE preview_id = %i FOR UPDATE', $previewId);
        if ($existing) {
            return $existing;
        }
        if (DB::queryFirstField('SELECT setup_status FROM pl_companies WHERE id = %i FOR UPDATE', $companyId) !== 'opening_required') {
            throw new DomainException('This company already completed its opening review.');
        }
        if (DB::queryFirstField("SELECT id FROM pl_journals WHERE book_id = %i AND source_type NOT IN ('opening_balance','reversal') LIMIT 1 FOR UPDATE", $bookId)) {
            throw new DomainException('Books with existing transactions cannot be replaced by an opening cutover.');
        }
        $payload = pl_opening_normalize($actorId, $companyId, $bookId, $preview['payload']);
        if (!hash_equals($expectedHash, pl_opening_hash($payload))) {
            throw new DomainException('The chart changed since preview. Review a fresh opening preview.');
        }
        $periods = DB::query('SELECT id, status FROM pl_periods WHERE company_id = %i AND book_id = %i AND start_date <= %s AND end_date >= %s FOR UPDATE', $companyId, $bookId, $payload['cutover_date'], $payload['cutover_date']);
        if (count($periods) !== 1 || $periods[0]['status'] !== 'open') {
            throw new DomainException('Cutover must fall in exactly one open accounting period.');
        }
        DB::insert('pl_opening_cutovers', ['company_id' => $companyId, 'book_id' => $bookId, 'preview_id' => $previewId, 'confirmed_by' => $actorId]);
        $cutoverId = (int) DB::insertId();
        // Readiness and the central journal post are one transaction; any failure restores the gate.
        DB::update('pl_companies', ['setup_status' => 'ready'], 'id = %i', $companyId);
        if ($payload['lines'] !== []) {
            $journal = pl_post_journal_locked($actorId, $companyId, $bookId, [
                'date' => $payload['cutover_date'], 'currency' => $payload['currency'], 'source_type' => 'opening_balance',
                'source_reference' => (string) $cutoverId, 'idempotency_key' => 'opening:' . $cutoverId,
                'description' => 'Opening cutover: ' . $payload['source'], 'lines' => $payload['lines'],
            ]);
            DB::update('pl_opening_cutovers', ['journal_id' => $journal['id']], 'id = %i', $cutoverId);
        }
        foreach ($payload['unpaid_documents'] as $document) {
            unset($document['account_code']);
            DB::insert('pl_opening_documents', ['company_id' => $companyId, 'book_id' => $bookId, 'cutover_id' => $cutoverId] + $document);
        }
        return DB::queryFirstRow('SELECT * FROM pl_opening_cutovers WHERE id = %i', $cutoverId);
    });
}

/** An erroneous cutover can be restarted only before any ordinary business activity. */
function pl_reverse_opening(int $actorId, int $companyId, int $bookId, int $cutoverId, string $reason): array
{
    pl_demo_require_setup_action();
    $reason = pl_ledger_text($reason, 'Cutover correction reason', 400);
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $cutoverId, $reason): array {
        pl_require_company_access($actorId, $companyId, true);
        if (!pl_user_can($actorId, $companyId, 'opening.manage')) {
            throw new DomainException('Your role cannot restart an opening cutover.');
        }
        pl_ledger_book($companyId, $bookId, true);
        $cutover = DB::queryFirstRow('SELECT * FROM pl_opening_cutovers WHERE id = %i AND company_id = %i AND book_id = %i FOR UPDATE', $cutoverId, $companyId, $bookId);
        if (!$cutover) {
            throw new DomainException('This cutover is not available in the selected book.');
        }
        if ($cutover['status'] === 'reversed') {
            if ($cutover['reversal_reason'] !== $reason) {
                throw new DomainException('This cutover was already reversed with a different reason.');
            }
            return $cutover;
        }
        $ordinary = DB::queryFirstField("SELECT j.id FROM pl_journals j WHERE j.book_id = %i AND j.source_type <> 'opening_balance' AND NOT (j.source_type = 'reversal' AND EXISTS (SELECT 1 FROM pl_opening_cutovers c WHERE c.journal_id = j.reversal_of_id)) LIMIT 1 FOR UPDATE", $bookId);
        if ($ordinary || DB::queryFirstField('SELECT id FROM pl_documents WHERE book_id = %i LIMIT 1 FOR UPDATE', $bookId)
            || DB::queryFirstField('SELECT id FROM pl_general_drafts WHERE book_id = %i LIMIT 1 FOR UPDATE', $bookId)
            || DB::queryFirstField('SELECT id FROM pl_ar_documents WHERE book_id = %i LIMIT 1 FOR UPDATE', $bookId)
            || DB::queryFirstField('SELECT id FROM pl_purchase_orders WHERE book_id = %i LIMIT 1 FOR UPDATE', $bookId)
            || DB::queryFirstField('SELECT id FROM pl_bank_statements WHERE book_id = %i LIMIT 1 FOR UPDATE', $bookId)) {
            throw new DomainException('This book already has business activity. Keep its cutover history and review correcting entries before proceeding.');
        }
        $reversalId = null;
        if ($cutover['journal_id'] !== null) {
            pl_inventory_assert_reversal_allowed($companyId,$bookId,(int)$cutover['journal_id']);
            pl_open_item_assert_generic_reversal_allowed($companyId,$bookId,(int)$cutover['journal_id']);
            $original = pl_get_journal($actorId, $companyId, $bookId, (int) $cutover['journal_id']);
            $lines = array_map(static fn (array $line): array => ['account_id' => (int) $line['account_id'], 'debit' => $line['credit'], 'credit' => $line['debit'], 'description' => $line['description']], $original['lines']);
            $reversal = pl_post_journal_locked($actorId, $companyId, $bookId, [
                'date' => $original['journal_date'], 'currency' => $original['currency'], 'source_type' => 'reversal',
                'source_reference' => (string) $original['id'], 'idempotency_key' => 'opening-reversal:' . $cutoverId,
                'description' => 'Opening cutover correction: ' . $reason, 'lines' => $lines,
            ], $original['id']);
            $reversalId = $reversal['id'];
        } else {
            $preview = pl_get_opening_preview($actorId, $companyId, $bookId, (int) $cutover['preview_id']);
            $status = DB::queryFirstField('SELECT status FROM pl_periods WHERE book_id = %i AND start_date <= %s AND end_date >= %s FOR SHARE', $bookId, $preview['cutover_date'], $preview['cutover_date']);
            if ($status !== 'open') {
                throw new DomainException('Reopen the cutover period before restarting even a zero opening.');
            }
        }
        DB::update('pl_opening_cutovers', ['status' => 'reversed', 'reversed_journal_id' => $reversalId,
            'reversed_by' => $actorId, 'reversed_at' => gmdate('Y-m-d H:i:s'), 'reversal_reason' => $reason], 'id = %i', $cutoverId);
        DB::update('pl_companies', ['setup_status' => 'opening_required'], 'id = %i', $companyId);
        return DB::queryFirstRow('SELECT * FROM pl_opening_cutovers WHERE id = %i', $cutoverId);
    });
}
