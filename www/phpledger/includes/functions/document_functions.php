<?php
declare(strict_types=1);

function pl_normalize_document(array $input): array
{
    $kind = $input['kind'] ?? null;
    if (!in_array($kind, ['receipt', 'expense'], true)) {
        throw new DomainException('Choose a receipt or an expense.');
    }
    if (!is_string($input['amount'] ?? null)) {
        throw new DomainException('Enter the amount as a decimal number.');
    }
    $amount = pl_amount($input['amount']);
    if (bccomp($amount, '0', 4) <= 0) {
        throw new DomainException('The amount must be greater than zero.');
    }
    foreach (['money_account_id', 'category_account_id'] as $field) {
        if (!is_int($input[$field] ?? null) || $input[$field] < 1) {
            throw new DomainException('Choose both a cash/bank account and a category.');
        }
    }
    $partyId = $input['party_id'] ?? null;
    if ($partyId !== null && (!is_int($partyId) || $partyId < 1)) { throw new DomainException('Choose a valid party.'); }
    return [
        'kind' => $kind,
        'document_date' => pl_ledger_date(pl_ledger_text($input['date'] ?? null, 'Transaction date', 10)),
        'amount' => $amount,
        'money_account_id' => $input['money_account_id'],
        'category_account_id' => $input['category_account_id'],
        'party_id' => $partyId,
        'counterparty' => $partyId === null ? pl_ledger_text($input['counterparty'] ?? '', 'Paid to / received from', 160) : '',
        'reference' => pl_ledger_text($input['reference'] ?? '', 'Reference', 120, false),
        'memo' => pl_ledger_text($input['memo'] ?? '', 'Memo', 500, false),
    ];
}

/** Link only eligible company master records; roles do not restrict the direction of money. */
function pl_document_party(int $companyId, array $document): array
{
    if (($document['party_id'] ?? null) === null) { return $document; }
    $party = DB::queryFirstRow('SELECT legal_name,status FROM pl_parties WHERE id=%i AND company_id=%i FOR SHARE', $document['party_id'], $companyId);
    if (!$party || !in_array($party['status'], ['draft','under_review','approved'], true)) {
        throw new DomainException('Choose an available party from this company. Parties on hold, blacklisted or archived cannot receive new transactions.');
    }
    $document['counterparty'] = $party['legal_name'];
    return $document;
}

function pl_validate_document_accounts(int $companyId, int $bookId, array $document): void
{
    $money = DB::queryFirstRow('SELECT id FROM pl_accounts WHERE id = %i AND company_id = %i AND book_id = %i AND is_active = 1 AND type = %s AND role = %s FOR SHARE', $document['money_account_id'], $companyId, $bookId, 'asset', 'cash_bank');
    $type = $document['kind'] === 'receipt' ? 'income' : 'expense';
    $category = DB::queryFirstRow('SELECT id FROM pl_accounts WHERE id = %i AND company_id = %i AND book_id = %i AND is_active = 1 AND type = %s AND role = %s FOR SHARE', $document['category_account_id'], $companyId, $bookId, $type, $type);
    if (!$money || !$category || $document['money_account_id'] === $document['category_account_id']) {
        throw new DomainException('Choose an active cash/bank account and a matching income or expense category from this company.');
    }
}

function pl_document_number(int $id, string $kind): string
{
    return ($kind === 'receipt' ? 'REC-' : 'EXP-') . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
}

function pl_document_row(array $row): array
{
    foreach (['id', 'company_id', 'book_id', 'money_account_id', 'category_account_id', 'revision'] as $field) {
        $row[$field] = (int) $row[$field];
    }
    $row['journal_id'] = $row['journal_id'] === null ? null : (int) $row['journal_id'];
    $row['party_id'] = ($row['party_id'] ?? null) === null ? null : (int) $row['party_id'];
    $row['reversal_journal_id'] = ($row['reversal_journal_id'] ?? null) === null ? null : (int) $row['reversal_journal_id'];
    $row['amount'] = bcadd((string) $row['amount'], '0', 4);
    $row['date'] = $row['document_date'];
    $row['number'] = pl_document_number($row['id'], $row['kind']);
    $row['status'] = $row['journal_id'] === null ? 'draft' : ($row['reversal_journal_id'] === null ? 'posted' : 'reversed');
    unset($row['creation_hash'], $row['creation_key']);
    return $row;
}

function pl_get_document(int $actorId, int $companyId, int $bookId, int $documentId): array
{
    pl_require_company_access($actorId, $companyId);
    $book = pl_ledger_book($companyId, $bookId);
    $row = DB::queryFirstRow('SELECT d.*, m.code AS money_account_code, m.name AS money_account_name, c.code AS category_account_code, c.name AS category_account_name, r.id AS reversal_journal_id FROM pl_effective_documents d JOIN pl_accounts m ON m.id = d.money_account_id JOIN pl_accounts c ON c.id = d.category_account_id LEFT JOIN pl_journals r ON r.reversal_of_id = d.journal_id WHERE d.id = %i AND d.company_id = %i AND d.book_id = %i FOR SHARE', $documentId, $companyId, $bookId);
    if (!$row) {
        throw new DomainException('This transaction is not available in the selected company and book.');
    }
    $document = pl_document_row($row);
    $document['posting_history'] = pl_source_posting_history($actorId, $companyId, $bookId, $document['kind'], $documentId);
    $document['journal'] = $document['journal_id'] === null ? null : pl_get_journal($actorId, $companyId, $bookId, $document['journal_id']);
    $payload = pl_document_posting_payload($document, $book['currency']);
    foreach ($payload['lines'] as &$line) {
        $money = $line['account_id'] === $document['money_account_id'];
        $line['code'] = $document[$money ? 'money_account_code' : 'category_account_code'];
        $line['name'] = $document[$money ? 'money_account_name' : 'category_account_name'];
    }
    unset($line);
    $document['journal_preview'] = ['date' => $payload['date'], 'currency' => $payload['currency'], 'description' => $payload['description'], 'lines' => $payload['lines']];
    return $document;
}

function pl_save_document(int $actorId, int $companyId, int $bookId, array $input, ?int $documentId = null, ?int $expectedRevision = null): array
{
    $partyProvided = array_key_exists('party_id', $input);
    $data = pl_normalize_document($input);
    $key = $documentId === null ? pl_request_key(pl_ledger_text($input['creation_key'] ?? null, 'Request identity', 128)) : null;
    $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $data, $key, $hash, $documentId, $expectedRevision, $partyProvided): array {
        pl_require_company_access($actorId, $companyId, true);
        pl_ledger_book($companyId, $bookId, true);
        pl_require_book_ready($companyId);
        if ($documentId === null) {
            $existing = DB::queryFirstRow('SELECT id, creation_hash FROM pl_documents WHERE company_id = %i AND book_id = %i AND creation_key = %s FOR UPDATE', $companyId, $bookId, $key);
            if ($existing) {
                $legacyData = $data;
                unset($legacyData['party_id']);
                $legacyHash = hash('sha256', json_encode($legacyData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                if (!hash_equals((string) $existing['creation_hash'], $hash)
                    && !(($data['party_id'] ?? null) === null && hash_equals((string)$existing['creation_hash'],$legacyHash))) {
                    throw new DomainException('This form already created a different draft. Open the saved draft or start a new transaction.');
                }
                return pl_get_document($actorId, $companyId, $bookId, (int) $existing['id']);
            }
            $data = pl_document_party($companyId, $data);
            pl_validate_document_accounts($companyId, $bookId, $data);
            pl_demo_require_document_capacity($companyId, $bookId);
            DB::insert('pl_documents', $data + [
                'company_id' => $companyId, 'book_id' => $bookId, 'creation_key' => $key, 'creation_hash' => $hash,
                'created_by' => $actorId, 'updated_by' => $actorId,
            ]);
            $id = (int) DB::insertId();
        } else {
            $existing = DB::queryFirstRow('SELECT id, revision, kind, journal_id, party_id FROM pl_documents WHERE id = %i AND company_id = %i AND book_id = %i FOR UPDATE', $documentId, $companyId, $bookId);
            if (!$existing) {
                throw new DomainException('This transaction is not available in the selected company and book.');
            }
            if ($existing['journal_id'] !== null) {
                throw new DomainException('Posted transactions cannot be edited. Use a linked reversal.');
            }
            if ($expectedRevision === null || $expectedRevision !== (int) $existing['revision']) {
                throw new DomainException('Someone changed this draft. Your entered values are preserved; compare them with the latest saved version before saving again.');
            }
            if ($data['kind'] !== $existing['kind']) {
                throw new DomainException('A saved receipt cannot be changed into an expense, or vice versa. Start a new transaction.');
            }
            if ($existing['party_id'] !== null && $data['party_id'] === null) {
                if ($partyProvided) { throw new DomainException('Choose another party to change the saved link; a linked transaction cannot be changed back to an unlinked name.'); }
                $data['party_id'] = (int)$existing['party_id'];
            }
            $data = pl_document_party($companyId, $data);
            pl_validate_document_accounts($companyId, $bookId, $data);
            DB::update('pl_documents', $data + ['revision' => $expectedRevision + 1, 'updated_by' => $actorId, 'updated_at' => gmdate('Y-m-d H:i:s')], 'id = %i', $documentId);
            $id = $documentId;
        }
        return pl_get_document($actorId, $companyId, $bookId, $id);
    });
}

/** The browser never supplies financial journal lines or a posting identity. */
function pl_preview_document(int $actorId, int $companyId, int $bookId, array $input): array
{
    pl_require_company_access($actorId, $companyId);
    $book = pl_ledger_book($companyId, $bookId);
    $document = pl_normalize_document($input);
    $document = pl_document_party($companyId, $document);
    pl_validate_document_accounts($companyId, $bookId, $document);
    $payload = pl_document_posting_payload($document + ['id'=>0], $book['currency']);
    foreach ($payload['lines'] as &$line) {
        $account = pl_get_account($actorId, $companyId, $bookId, $line['account_id']);
        $line['code'] = $account['code']; $line['name'] = $account['name'];
    }
    unset($line);
    return ['date'=>$payload['date'], 'currency'=>$payload['currency'], 'lines'=>$payload['lines']];
}

/** The browser never supplies financial journal lines or a posting identity. */
function pl_document_posting_payload(array $document, string $currency): array
{
    $money = ['account_id' => (int) $document['money_account_id'], 'debit' => '0.0000', 'credit' => '0.0000', 'description' => (string) $document['counterparty']];
    $category = ['account_id' => (int) $document['category_account_id'], 'debit' => '0.0000', 'credit' => '0.0000', 'description' => (string) $document['memo']];
    $money[$document['kind'] === 'receipt' ? 'debit' : 'credit'] = (string) $document['amount'];
    $category[$document['kind'] === 'receipt' ? 'credit' : 'debit'] = (string) $document['amount'];
    return [
        'date' => $document['document_date'], 'currency' => $currency,
        'source_type' => $document['kind'], 'source_reference' => 'document:' . $document['id'],
        'idempotency_key' => 'document:' . $document['id'] . ':post',
        'description' => pl_document_number((int) $document['id'], $document['kind']) . ' — ' . $document['counterparty'],
        'lines' => $document['kind'] === 'receipt' ? [$money, $category] : [$category, $money],
    ];
}

function pl_post_document(int $actorId, int $companyId, int $bookId, int $documentId, int $expectedRevision): array
{
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $documentId, $expectedRevision): array {
        pl_require_company_access($actorId, $companyId, true);
        $book = pl_ledger_book($companyId, $bookId, true);
        pl_require_book_ready($companyId);
        $document = DB::queryFirstRow('SELECT * FROM pl_documents WHERE id = %i AND company_id = %i AND book_id = %i FOR UPDATE', $documentId, $companyId, $bookId);
        if (!$document) {
            throw new DomainException('This transaction is not available in the selected company and book.');
        }
        if ($document['journal_id'] !== null) {
            return pl_get_document($actorId, $companyId, $bookId, $documentId);
        }
        if ((int) $document['revision'] !== $expectedRevision) {
            throw new DomainException('This draft changed after you reviewed it. Review the latest saved values before posting.');
        }
        pl_validate_document_accounts($companyId, $bookId, $document);
        // Validate current eligibility without changing the saved historical name snapshot.
        pl_document_party($companyId, $document);
        $journal = pl_post_journal($actorId, $companyId, $bookId, pl_document_posting_payload($document, $book['currency']));
        DB::update('pl_documents', ['journal_id' => $journal['id'], 'updated_by' => $actorId, 'updated_at' => gmdate('Y-m-d H:i:s')], 'id = %i', $documentId);
        return pl_get_document($actorId, $companyId, $bookId, $documentId);
    });
}

function pl_reverse_document(int $actorId, int $companyId, int $bookId, int $documentId, string $date, string $reason): array
{
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $documentId, $date, $reason): array {
        pl_require_company_access($actorId, $companyId, true);
        pl_ledger_book($companyId, $bookId, true);
        pl_require_book_ready($companyId);
        $document = pl_get_document($actorId, $companyId, $bookId, $documentId);
        if ($document['journal_id'] === null) {
            throw new DomainException('Only a posted transaction can be reversed.');
        }
        pl_reverse_journal($actorId, $companyId, $bookId, $document['journal_id'], $date, 'document:' . $documentId . ':reverse' . ($document['journal_id'] === (int) ($document['original_journal_id'] ?? $document['journal_id']) ? '' : ':' . $document['journal_id']), $reason);
        return pl_get_document($actorId, $companyId, $bookId, $documentId);
    });
}

function pl_list_documents(int $actorId, int $companyId, int $bookId, array $filters = []): array
{
    pl_require_company_access($actorId, $companyId);
    pl_ledger_book($companyId, $bookId);
    $where = ['d.company_id = %i', 'd.book_id = %i'];
    $args = [$companyId, $bookId];
    $recordsTotal = (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_documents WHERE company_id = %i AND book_id = %i', $companyId, $bookId);
    $status = $filters['status'] ?? 'all';
    if (!in_array($status, ['all', 'draft', 'posted', 'reversed'], true)) {
        throw new DomainException('Choose a valid transaction status.');
    }
    if ($status === 'draft') {
        $where[] = 'd.journal_id IS NULL';
    } elseif ($status === 'posted') {
        $where[] = 'd.journal_id IS NOT NULL AND r.id IS NULL';
    } elseif ($status === 'reversed') {
        $where[] = 'r.id IS NOT NULL';
    }
    $kind = $filters['kind'] ?? 'all';
    if (!in_array($kind, ['all', 'receipt', 'expense'], true)) {
        throw new DomainException('Choose receipts, expenses, or all transactions.');
    }
    if ($kind !== 'all') {
        $where[] = 'd.kind = %s';
        $args[] = $kind;
    }
    foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
        if (($filters[$key] ?? '') !== '') {
            $where[] = 'd.document_date ' . $operator . ' %s';
            $args[] = pl_ledger_date(pl_ledger_text($filters[$key], 'Filter date', 10));
        }
    }
    if (($filters['from'] ?? '') !== '' && ($filters['to'] ?? '') !== '' && $filters['from'] > $filters['to']) {
        throw new DomainException('The beginning of the date range must be on or before its end.');
    }
    $search = pl_ledger_text($filters['search'] ?? '', 'Search', 160, false);
    if ($search !== '') {
        // Bound literal substring comparison does not treat percent/underscore as wildcards.
        $where[] = '(LOCATE(%s, d.counterparty) > 0 OR LOCATE(%s, d.reference) > 0 OR LOCATE(%s, d.memo) > 0 OR LOCATE(%s, CONCAT(IF(d.kind = \'receipt\', \'REC-\', \'EXP-\'), LPAD(d.id, 6, \'0\'))) > 0)';
        array_push($args, $search, $search, $search, strtoupper($search));
    }
    $condition = implode(' AND ', $where);
    $join = ' FROM pl_effective_documents d LEFT JOIN pl_journals r ON r.reversal_of_id = d.journal_id WHERE ' . $condition;
    $totals = DB::queryFirstRow('SELECT COUNT(*) AS total, COALESCE(SUM(d.amount), 0) AS total_amount' . $join, ...$args);
    $total = (int) $totals['total'];
    $size = pl_table_size($filters['page_size'] ?? 50);
    $pages = max(1, (int) ceil($total / $size));
    $page = min($pages, max(1, (int) ($filters['page'] ?? 1)));
    $order = pl_table_order($filters, 'transactions');
    $rows = DB::query('SELECT d.*, r.id AS reversal_journal_id' . $join . ' ORDER BY ' . $order . ' LIMIT %i OFFSET %i', ...array_merge($args, [$size, ($page - 1) * $size]));
    return ['documents' => array_map('pl_document_row', $rows), 'total' => $total, 'records_total' => $recordsTotal, 'total_amount' => bcadd((string) $totals['total_amount'], '0', 4), 'page' => $page, 'pages' => $pages];
}

function pl_account_activity(int $actorId, int $companyId, int $bookId, int $accountId, ?string $asOf = null, int $page = 1, ?string $from = null, array $options = []): array
{
    $date = $asOf === null ? '9999-12-31' : pl_ledger_date($asOf);
    if ($from !== null) {
        pl_ledger_date($from);
        if ($from > $date) {
            throw new DomainException('The activity start date must be on or before its end date.');
        }
    }
    $size = pl_table_size($options['page_size'] ?? 50);
    $search = pl_ledger_text($options['search'] ?? '', 'Search', 160, false);
    $order = pl_table_order($options, 'account');
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $accountId, $date, $asOf, $from, $page, $size, $search, $order): array {
        pl_require_company_access($actorId, $companyId);
        // Hold the shared book lock for all statement reads; posting takes its exclusive lock.
        pl_ledger_book($companyId, $bookId);
        $account = DB::queryFirstRow('SELECT id, code, name, type, is_active FROM pl_accounts WHERE id = %i AND company_id = %i AND book_id = %i', $accountId, $companyId, $bookId);
        if (!$account) {
            throw new DomainException('This account is not available in the selected company and book.');
        }
        $account['id'] = (int) $account['id'];
        $account['is_active'] = (bool) $account['is_active'];
        $scope = ' FROM pl_journal_lines l JOIN pl_journals j ON j.id = l.journal_id AND j.company_id = l.company_id AND j.book_id = l.book_id WHERE l.account_id = %i AND l.company_id = %i AND l.book_id = %i';
        $opening = $from === null ? '0.0000' : bcadd((string) DB::queryFirstField('SELECT COALESCE(SUM(l.debit - l.credit), 0)' . $scope . ' AND j.journal_date < %s', $accountId, $companyId, $bookId, $from), '0', 4);
        $queryFrom = $scope . ' AND j.journal_date <= %s';
        $args = [$accountId, $companyId, $bookId, $date];
        if ($from !== null) {
            $queryFrom .= ' AND j.journal_date >= %s';
            $args[] = $from;
        }
        $totals = DB::queryFirstRow('SELECT COUNT(*) AS total, COALESCE(SUM(l.debit), 0) AS debit_movement, COALESCE(SUM(l.credit), 0) AS credit_movement' . $queryFrom, ...$args);
        $window = 'SELECT j.id AS journal_id, j.journal_date AS date, j.description, j.source_type, j.source_reference, j.reversal_of_id, l.line_number, l.debit, l.credit, SUM(l.debit - l.credit) OVER (ORDER BY j.journal_date, j.id, l.line_number ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS running_movement' . $queryFrom;
        // Search and display ordering are outside the window; neither can redefine a ledger balance.
        $filtered = ' FROM (' . $window . ') q';
        $displayArgs = $args;
        if ($search !== '') {
            $filtered .= ' WHERE LOCATE(%s, q.description) > 0 OR LOCATE(%s, q.source_reference) > 0 OR LOCATE(%s, CONCAT(\'PL-\', LPAD(q.journal_id, 8, \'0\'))) > 0';
            array_push($displayArgs, $search, $search, strtoupper($search));
        }
        $filteredTotal = $search === '' ? (int) $totals['total'] : (int) DB::queryFirstField('SELECT COUNT(*)' . $filtered, ...$displayArgs);
        $pages = max(1, (int) ceil($filteredTotal / $size));
        $page = min($pages, max(1, $page));
        $rows = DB::query('SELECT q.*' . $filtered . ' ORDER BY ' . $order . ' LIMIT %i OFFSET %i', ...array_merge($displayArgs, [$size, ($page - 1) * $size]));
        $sourceIds = array_values(array_unique(array_map(static fn (array $row): int => (int) ($row['reversal_of_id'] ?? $row['journal_id']), $rows)));
        // Fetch scoped source links once per page, including links for reversal rows.
        $documents = $sourceIds === [] ? [] : array_column(DB::query('SELECT id, journal_id FROM pl_documents WHERE company_id = %i AND book_id = %i AND journal_id IN %li', $companyId, $bookId, $sourceIds), 'id', 'journal_id');
        $generals = $sourceIds === [] ? [] : array_column(DB::query('SELECT id, journal_id FROM pl_general_drafts WHERE company_id = %i AND book_id = %i AND journal_id IN %li', $companyId, $bookId, $sourceIds), 'id', 'journal_id');
        if ($sourceIds !== []) {
            $revisionLinks = DB::query('SELECT i.source_type,i.source_id,v.journal_id FROM pl_posting_revisions v JOIN pl_posting_identities i ON i.id=v.identity_id WHERE i.company_id=%i AND i.book_id=%i AND v.journal_id IN %li', $companyId, $bookId, $sourceIds);
            foreach ($revisionLinks as $link) {
                if ($link['source_type'] === 'general_journal') { $generals[$link['journal_id']] = $link['source_id']; }
                else { $documents[$link['journal_id']] = $link['source_id']; }
            }
        }
        foreach ($rows as &$row) {
            $row['journal_id'] = (int) $row['journal_id'];
            $row['journal_reference'] = 'PL-' . str_pad((string) $row['journal_id'], 8, '0', STR_PAD_LEFT);
            $sourceId = (int) ($row['reversal_of_id'] ?? $row['journal_id']);
            $source = $documents[$sourceId] ?? null;
            $row['document_id'] = $source === null ? null : (int) $source;
            $general = $generals[$sourceId] ?? null;
            $row['general_id'] = $general === null ? null : (int) $general;
            $row['debit'] = bcadd((string) $row['debit'], '0', 4);
            $row['credit'] = bcadd((string) $row['credit'], '0', 4);
            $row['running_balance'] = bcadd($opening, (string) $row['running_movement'], 4);
            unset($row['running_movement'], $row['line_number']);
        }
        unset($row);
        $debit = bcadd((string) $totals['debit_movement'], '0', 4);
        $credit = bcadd((string) $totals['credit_movement'], '0', 4);
        $movement = bcsub($debit, $credit, 4);
        $first = $rows[0] ?? null;
        $last = $rows === [] ? null : $rows[array_key_last($rows)];
        return [
            'account' => $account, 'movements' => $rows,
            'debit_movement' => $debit, 'credit_movement' => $credit,
            // Preserve the existing balance field as period movement for internal callers.
            'balance' => $movement, 'opening_balance' => $opening, 'closing_balance' => bcadd($opening, $movement, 4),
            'page_opening_balance' => $first === null ? $opening : bcadd(bcsub($first['running_balance'], $first['debit'], 4), $first['credit'], 4),
            'page_closing_balance' => $last === null ? $opening : $last['running_balance'],
            'total' => (int) $totals['total'], 'filtered_total' => $filteredTotal, 'page' => $page, 'pages' => $pages, 'from' => $from, 'as_of' => $asOf,
        ];
    });
}
