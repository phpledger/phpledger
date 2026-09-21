<?php
declare(strict_types=1);

/** Normalize unsigned financial input without ever converting through a float. */
function pl_amount(string $amount): string
{
    if (!preg_match('/^(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,4})?$/D', $amount)) {
        throw new DomainException('Enter an amount with up to 16 whole digits and four decimal places, without commas or a sign.');
    }
    return bcadd($amount, '0', 4);
}

/**
 * Is a normalized amount payable in coins? Every currency in pl_base_currency_options()
 * has two minor-unit decimals, so the scale is a constant; the first zero-decimal (JPY)
 * or three-decimal (KWD) currency added there has to make it depend on the book. BCMath
 * truncates, so bcadd(..., 2) is the value dropped to whole minor units.
 */
function pl_whole_minor_units(string $amount): bool
{
    return bccomp($amount, bcadd($amount, '0', 2), 4) === 0;
}

/** A tender is physical money: reject cash the drawer could not hold or give back. */
function pl_cash_amount(string $amount): string
{
    $value = pl_amount($amount);
    if (!pl_whole_minor_units($value)) {
        throw new DomainException('Enter cash in whole notes and coins, with at most two decimal places.');
    }
    return $value;
}

function pl_ledger_date(string $date): string
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
    if (!$parsed || $parsed->format('Y-m-d') !== $date || $date < '1000-01-01' || $date > '9998-12-31') {
        throw new DomainException('Choose a valid date in YYYY-MM-DD format.');
    }
    return $date;
}

function pl_ledger_text(mixed $value, string $label, int $limit, bool $required = true): string
{
    if (!is_string($value)) {
        throw new DomainException($label . ' must be text.');
    }
    $value = trim($value);
    if (($required && $value === '') || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > $limit) {
        throw new DomainException($label . ' is missing or too long.');
    }
    return $value;
}

function pl_ledger_transaction(callable $work): mixed
{
    $ownsTransaction = DB::transactionDepth() === 0;
    // Only database work belongs in this callback: a deadlock can replay an owned transaction.
    for ($attempt = 0; ; $attempt++) {
        DB::startTransaction();
        try {
            $result = $work();
            DB::commit();
            // 1.2 M8, plan decision 15: the database has released this transaction's row locks,
            // so the actions queued during it may now run. They run outside every lock, they
            // cannot abort anything, and nothing they do can un-post what has just committed.
            if ($ownsTransaction) {
                pl_hook_transaction_ended(true);
            }
            return $result;
        } catch (Throwable $error) {
            $rolledBack = false;
            try {
                if (DB::get()->inTransaction()) {
                    DB::rollback();
                } else {
                    // InnoDB deadlocks roll back every savepoint; reset MeekroDB's depth too.
                    DB::getMDB()->rollback(true);
                }
                $rolledBack = true;
            } catch (Throwable) {
                // Preserve the original failure rather than mask it with cleanup errors.
            }
            if ($ownsTransaction) {
                // Nothing committed, so the queued actions describe work that never happened.
                // A deadlock retry comes through here too, and starts from an empty queue.
                pl_hook_transaction_ended(false);
            }
            $deadlock = false;
            for ($cause = $error; $cause !== null; $cause = $cause->getPrevious()) {
                if ($cause instanceof PDOException && (int) ($cause->errorInfo[1] ?? 0) === 1213) {
                    $deadlock = true;
                    break;
                }
            }
            if (!$ownsTransaction || !$rolledBack || !$deadlock || $attempt >= 3) {
                throw $error;
            }
            usleep(random_int(5000, 20000) * ($attempt + 1));
        }
    }
}

/** @return array{company_id:int, book_id:int, period_id:int, accounts:array<int|string,int>} */
function pl_create_company(int $actorId, string $name, string $currency, string $startDate, string $fiscalYearEnd = '12-31'): array
{
    pl_demo_require_setup_action();
    $name = pl_ledger_text($name, 'Business name', 160);
    if (!isset(pl_base_currency_options()[$currency])) {
        throw new DomainException('Choose one of the supported base currencies.');
    }
    pl_ledger_date($startDate);
    if (!preg_match('/^[0-9]{2}-[0-9]{2}$/D', $fiscalYearEnd)) {
        throw new DomainException('Choose a fiscal year end in MM-DD format.');
    }
    // February 29 is not a stable annual closing date; use February 28 instead.
    pl_ledger_date('2001-' . $fiscalYearEnd);
    $year = (int) substr($startDate, 0, 4);
    $endDate = $year . '-' . $fiscalYearEnd;
    if ($endDate < $startDate) {
        $endDate = ($year + 1) . '-' . $fiscalYearEnd;
    }
    return pl_ledger_transaction(function () use ($actorId, $name, $currency, $startDate, $fiscalYearEnd, $endDate): array {
        if (!DB::queryFirstRow('SELECT id FROM pl_users WHERE id = %i AND is_active = 1 FOR SHARE', $actorId)) {
            throw new DomainException('Sign in with an active account to create a company.');
        }
        DB::insert('pl_companies', ['name' => $name, 'currency' => $currency, 'functional_currency' => $currency, 'presentation_currency' => $currency, 'start_date' => $startDate, 'fiscal_year_end' => $fiscalYearEnd, 'created_by' => $actorId, 'setup_status' => 'ready']);
        $companyId = (int) DB::insertId();
        // 1.2 M7 dual write: the 1.1 ENUM and the new role_id both name the Owner role. The ENUM
        // is dropped in 1.3; until then every membership write sets both (migration 040).
        DB::insert('pl_company_members', ['company_id' => $companyId, 'user_id' => $actorId, 'role' => 'owner',
            'role_id' => DB::queryFirstField("SELECT id FROM pl_roles WHERE company_id IS NULL AND slug = 'owner'")]);
        DB::insert('pl_books', ['company_id' => $companyId, 'name' => 'Primary book', 'functional_currency' => $currency, 'presentation_currency' => $currency]);
        $bookId = (int) DB::insertId();
        DB::insert('pl_periods', ['company_id' => $companyId, 'book_id' => $bookId, 'start_date' => $startDate, 'end_date' => $endDate, 'status' => 'open']);
        $periodId = (int) DB::insertId();
        $accounts = [];
        $template = pl_starter_template();
        $mapping = [];
        foreach ($template['accounts'] as $definition) {
            // The bundled chart is already numbered in the X-XXX-XXXXX-XX shape (B56); its
            // `legacy_code` is the number the same account carried in the 1.0.0 chart, so a report,
            // demo pack or import written against the old numbers still resolves. A new book was
            // never converted, so it has no pl_account_code_map rows: that table records conversions.
            $legacyCode = $definition['legacy_code'] ?? null;
            DB::insert('pl_accounts', ['company_id' => $companyId, 'book_id' => $bookId] + $definition + pl_currency_account_properties($definition));
            $accountId = (int) DB::insertId();
            $accounts[$definition['code']] = $accountId;
            if (is_string($legacyCode) && $legacyCode !== '' && !isset($accounts[$legacyCode])) {
                $accounts[$legacyCode] = $accountId;
            }
            $mapping[$definition['semantic_key']] = $accountId;
        }
        // The class and group names. A heading is an ordinary chart row with a heading code, so
        // `pl_account_is_postable()` already refuses a posting to it and `pl_report_tree()` uses
        // its name for the node it names instead of falling back on "Group 1-100".
        //
        // It carries its own `semantic_key`, which is how a module finds the group it needs —
        // `pl_account_heading_by_key()` — rather than by a name a translation changes or a number
        // migration 036 allocated per chart. It carries no `role`: a role names where a posting
        // goes, and a second active account holding `receivables`, `payables` or an advances role
        // would leave `pl_ar_control()` and `pl_advance_control()` with no unambiguous default.
        //
        // The heading keys stay out of `$mapping`, which is the starter-purpose map the chart
        // snapshot and the prior-foundation review are built from: a heading is not one of the
        // purposes the owner is asked to map onto an existing account.
        foreach ($template['headings'] as $heading) {
            DB::insert('pl_accounts', ['company_id' => $companyId, 'book_id' => $bookId,
                'code' => $heading['code'], 'name' => $heading['name'], 'type' => $heading['type'],
                'semantic_key' => $heading['semantic_key'], 'role' => null, 'is_active' => 1, 'is_contra' => 0]
                + pl_currency_account_properties([]));
            $accounts[$heading['code']] = (int) DB::insertId();
        }
        pl_install_template_snapshot($actorId, $companyId, $bookId, $template, $mapping);
        // B44: the owner of the FIRST company on this installation becomes its administrator.
        // A no-op once anybody holds installation.admin, so the second company changes nothing.
        if (function_exists('pl_seed_installation_admin')) {
            pl_seed_installation_admin();
        }
        return ['company_id' => $companyId, 'book_id' => $bookId, 'period_id' => $periodId, 'accounts' => $accounts];
    });
}

/** Validated, canonical payload is also the durable idempotency comparison input. */
function pl_normalize_journal(array $payload): array
{
    $date = pl_ledger_date(pl_ledger_text($payload['date'] ?? null, 'Posting date', 10));
    $currency = pl_currency_code(pl_ledger_text($payload['currency'] ?? null, 'Currency', 3));
    $sourceType = pl_ledger_text($payload['source_type'] ?? null, 'Source type', 40);
    $key = pl_ledger_text($payload['idempotency_key'] ?? null, 'Request key', 128);
    if (!preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $sourceType) || !preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $key)) {
        throw new DomainException('Use a valid source type and request key.');
    }
    $normalized = [
        'date' => $date,
        'currency' => $currency,
        'source_type' => $sourceType,
        'source_reference' => pl_ledger_text($payload['source_reference'] ?? null, 'Source reference', 120),
        'idempotency_key' => $key,
        'description' => pl_ledger_text($payload['description'] ?? null, 'Description', 500),
        'lines' => [],
    ];
    $lines = $payload['lines'] ?? null;
    if (!is_array($lines) || !array_is_list($lines) || count($lines) < 2 || count($lines) > 1000) {
        throw new DomainException('A journal needs between two and 1,000 lines.');
    }
    $debits = '0.0000';
    $credits = '0.0000';
    foreach ($lines as $line) {
        if (!is_array($line) || !is_int($line['account_id'] ?? null) || $line['account_id'] < 1 || !is_string($line['debit'] ?? null) || !is_string($line['credit'] ?? null)) {
            throw new DomainException('Every line needs an account ID and decimal-string debit and credit amounts.');
        }
        $debit = pl_amount($line['debit']);
        $credit = pl_amount($line['credit']);
        if ((bccomp($debit, '0', 4) > 0) === (bccomp($credit, '0', 4) > 0)) {
            throw new DomainException('Each line must contain either a positive debit or a positive credit.');
        }
        $normalized['lines'][] = ['account_id' => $line['account_id'], 'debit' => $debit, 'credit' => $credit, 'description' => pl_ledger_text($line['description'] ?? '', 'Line description', 500, false)] + pl_currency_line_normalize($line, $currency, $debit, $credit);
        $debits = bcadd($debits, $debit, 4);
        $credits = bcadd($credits, $credit, 4);
    }
    if (bccomp($debits, $credits, 4) !== 0) {
        throw new DomainException('Debits and credits must balance before posting.');
    }
    return $normalized;
}

/** @return array<string,mixed> */
function pl_ledger_book(int $companyId, int $bookId, bool $lock = false): array
{
    $book = DB::queryFirstRow('SELECT b.id, b.company_id, b.functional_currency AS currency, b.functional_currency, b.presentation_currency FROM pl_books b WHERE b.id = %i AND b.company_id = %i' . ($lock ? ' FOR UPDATE' : ' FOR SHARE'), $bookId, $companyId);
    if (!$book) {
        throw new DomainException('This book is not available in the selected company.');
    }
    if ($lock) {
        // 1.2 M8, plan decision 15: from here until the outermost transaction ends, no hook may
        // run. pl_do_action() and pl_apply_filters() refuse while this is recorded, so a plugin
        // can neither hold this book against other writers nor throw inside a half-written entry.
        pl_hook_lock_acquired($companyId, $bookId);
    }
    return $book;
}

/** Internal posting primitive; nested calls retain the outer transaction's book lock. */
function pl_post_journal_locked(int $actorId, int $companyId, int $bookId, array $payload, ?int $reversalOf = null, ?int $settlementItemId = null, ?array $settlementAllocations = null): array
{
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $payload, $reversalOf, $settlementItemId, $settlementAllocations): array {
        $payload = pl_normalize_journal($payload);
        // This helper also checks permissions so future direct callers cannot bypass membership.
        pl_require_company_access($actorId, $companyId, true);
        // The validation phase (1.2 M8, B45 and plan decision 15). A plugin filter runs HERE:
        // after the draft is normalized and authorised, and before the book row below is taken
        // FOR UPDATE. A filter that vetoes refuses cleanly; a filter that throws aborts before
        // the lock exists, so it cannot leave a half-posted entry or block another writer.
        //
        // It runs only at the outermost posting. A nested call — an invoice, a settlement, a
        // stock document — reaches this line with the book already locked by the flow that owns
        // the operation, and pl_apply_filters() refuses to run inside that lock by design.
        $payload = pl_hook_posting_draft($actorId, $companyId, $bookId, $payload, $reversalOf);
        $book = pl_ledger_book($companyId, $bookId, true);
        pl_require_book_ready($companyId);
        if ($book['currency'] !== $payload['currency']) {
            throw new DomainException('Journal header currency must be the book functional currency; transaction currencies belong on lines.');
        }
        if ($reversalOf === null && $payload['source_type'] === 'reversal') {
            throw new DomainException('A reversal must reference its original journal.');
        }
        if ($reversalOf !== null) {
            $original = pl_get_journal($actorId, $companyId, $bookId, $reversalOf);
            $expectedLines = [];
            foreach ($original['lines'] as $line) {
                $expectedLines[] = ['account_id' => (int) $line['account_id'], 'debit' => (string) $line['credit'], 'credit' => (string) $line['debit'], 'description' => (string) $line['description']] + pl_currency_line_normalize($line, (string) $original['currency'], (string) $line['credit'], (string) $line['debit']);
            }
            if ($original['reversal_of_id'] !== null || $payload['date'] < $original['journal_date']
                || $payload['source_type'] !== 'reversal' || $payload['source_reference'] !== (string) $reversalOf
                || $payload['lines'] !== $expectedLines) {
                throw new DomainException('A linked reversal must exactly undo its original journal on or after the original date.');
            }
        }
        $hash = hash('sha256', json_encode(['company_id' => $companyId, 'book_id' => $bookId, 'reversal_of_id' => $reversalOf, 'payload' => $payload], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        // A waiting caller may already own an older REPEATABLE READ snapshot.
        $existing = DB::queryFirstRow('SELECT id, payload_hash FROM pl_journals WHERE book_id = %i AND idempotency_key = %s FOR UPDATE', $bookId, $payload['idempotency_key']);
        if ($existing) {
            $legacy = pl_currency_legacy_payload($payload);
            $legacyHash = $legacy === null ? null : hash('sha256', json_encode(['company_id' => $companyId, 'book_id' => $bookId, 'reversal_of_id' => $reversalOf, 'payload' => $legacy], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            if (!hash_equals((string) $existing['payload_hash'], $hash) && ($legacyHash === null || !hash_equals((string) $existing['payload_hash'], $legacyHash))) {
                throw new DomainException('This request key already belongs to a different journal. Use a new key for a new request.');
            }
            return pl_get_journal($actorId, $companyId, $bookId, (int) $existing['id']);
        }
        if ($reversalOf !== null) {
            if (function_exists('pl_inventory_assert_reversal_allowed')) { pl_inventory_assert_reversal_allowed($companyId, $bookId, $reversalOf); }
            if (function_exists('pl_purchasing_assert_reversal_allowed')) { pl_purchasing_assert_reversal_allowed($companyId, $bookId, $reversalOf); }
        }
        pl_correction_assert_posting_allowed($companyId, $bookId, $payload, $reversalOf);
        if ($reversalOf !== null && $original['source_type'] !== 'opening_balance' && $payload['date'] < gmdate('Y-m-d')) {
            pl_require_company_access($actorId, $companyId, true);
            if (!pl_user_can($actorId, $companyId, 'journal.reverse_backdated') || $payload['date'] !== $original['journal_date']) {
                throw new DomainException('Backdated reversals require the backdated-reversal permission, the original posting date and an open period.');
            }
        }
        $carryingAccount = $settlementItemId === null ? null : pl_open_item_validate_settlement_basis($companyId, $bookId, $payload, $settlementItemId);
        if ($settlementAllocations !== null) {
            if ($reversalOf !== null || $settlementItemId !== null) { throw new DomainException('Settlement source modes cannot be combined.'); }
            pl_open_item_validate_batch_basis($companyId,$bookId,$payload,$settlementAllocations);
        } else { pl_open_item_validate_posting($companyId, $bookId, $payload, $reversalOf, $settlementItemId); }
        pl_opening_assert_posting_allowed($companyId, $bookId, $payload);
        pl_reconciliation_assert_posting_allowed($companyId, $bookId, $payload);
        $periods = DB::query('SELECT id, status FROM pl_periods WHERE company_id = %i AND book_id = %i AND start_date <= %s AND end_date >= %s FOR UPDATE', $companyId, $bookId, $payload['date'], $payload['date']);
        if (count($periods) !== 1 || $periods[0]['status'] !== 'open') {
            throw new DomainException('The posting date must fall within exactly one open accounting period.');
        }
        foreach ($payload['lines'] as $lineIndex=>$line) {
            $account = DB::queryFirstRow('SELECT id, code, currency FROM pl_accounts WHERE id = %i AND company_id = %i AND book_id = %i AND is_active = 1 FOR SHARE', $line['account_id'], $companyId, $bookId);
            if (!$account) {
                throw new DomainException('Every account must be active and belong to the selected company and book.');
            }
            // Structured codes make the chart a tree (B56): only a leaf receives postings, so a
            // class or group heading and any account that has gained sub-accounts are refused here,
            // in the one central funnel, rather than in each screen that builds a journal.
            if (!pl_account_is_postable($companyId, $bookId, (string) $account['code'])) {
                throw new DomainException('Postings belong on the lowest account in the chart. ' . $account['code'] . ' aggregates the accounts below it.');
            }
            pl_currency_validate_posting_line($actorId, $companyId, $bookId, $payload, $line, $account, $reversalOf !== null || $carryingAccount === $line['account_id'] || array_key_exists($lineIndex,$settlementAllocations ?? []));
        }
        DB::insert('pl_journals', [
            'company_id' => $companyId, 'book_id' => $bookId, 'period_id' => (int) $periods[0]['id'],
            'journal_date' => $payload['date'], 'currency' => $payload['currency'], 'description' => $payload['description'],
            'source_type' => $payload['source_type'], 'source_reference' => $payload['source_reference'],
            'idempotency_key' => $payload['idempotency_key'], 'payload_hash' => $hash,
            'reversal_of_id' => $reversalOf, 'posted_by' => $actorId,
        ]);
        $journalId = (int) DB::insertId();
        foreach ($payload['lines'] as $index => $line) {
            DB::insert('pl_journal_lines', [
                'journal_id' => $journalId, 'company_id' => $companyId, 'book_id' => $bookId, 'line_number' => $index + 1,
                'account_id' => $line['account_id'], 'description' => $line['description'], 'debit' => $line['debit'], 'credit' => $line['credit'],
            ] + array_intersect_key($line, array_flip(['currency','amount_fc','rate','rate_type','rate_source_id','amount_base','rate_is_stale','ic_counterparty_entity_id'])));
        }
        pl_correction_track_posting($actorId, $companyId, $bookId, $journalId, $payload, $reversalOf);
        pl_open_item_track_posting($companyId, $bookId, $journalId, $payload, $reversalOf, $settlementAllocations);
        $journal = pl_get_journal($actorId, $companyId, $bookId, $journalId);
        // Queued, not fired: the book row is locked at this point and stays locked until this
        // transaction commits. pl_ledger_transaction() runs the queue afterwards, outside every
        // lock, and a plugin that fails there cannot turn a posted journal into a failed request.
        pl_hook_after_commit('journal.posted', [$journal, ['company_id' => $companyId, 'book_id' => $bookId,
            'actor_id' => $actorId, 'source_type' => $payload['source_type'], 'reversal_of_id' => $reversalOf]]);
        return $journal;
    });
}

/**
 * Run the validation-phase filter over a normalized journal draft and return what core will post.
 *
 * Whatever a filter returns is normalized again by core, so a plugin cannot post an unbalanced,
 * malformed or out-of-currency journal however it rewrites the draft. Four fields are frozen on
 * top of that: `idempotency_key`, `source_type`, `source_reference` and `currency` are the
 * caller's identity and the replay contract, and a plugin that could change them could make one
 * request record a different operation than the one that was asked for. A filter may change the
 * date, the description and the lines.
 *
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function pl_hook_posting_draft(int $actorId, int $companyId, int $bookId, array $payload, ?int $reversalOf): array
{
    if (pl_hook_lock_held() || pl_hook_callbacks('journal.validate') === []) {
        return $payload;
    }
    $filtered = pl_apply_filters('journal.validate', $payload, [['actor_id' => $actorId, 'company_id' => $companyId,
        'book_id' => $bookId, 'reversal_of_id' => $reversalOf]]);
    if (!is_array($filtered)) {
        throw new DomainException('A package returned something that is not a journal from the posting filter.');
    }
    foreach (['idempotency_key', 'source_type', 'source_reference', 'currency'] as $frozen) {
        if (($filtered[$frozen] ?? null) !== $payload[$frozen]) {
            throw new DomainException('A package cannot change the ' . str_replace('_', ' ', $frozen) . ' of a posting it is reviewing.');
        }
    }
    return pl_normalize_journal($filtered);
}

function pl_post_journal(int $actorId, int $companyId, int $bookId, array $payload): array
{
    $payload = pl_normalize_journal($payload);
    if ($payload['source_type'] === 'reversal') {
        throw new DomainException('Use the linked reversal action to reverse a posted journal.');
    }
    if (in_array($payload['source_type'], pl_open_item_source_types(), true)) { throw new DomainException('Use the authoritative open-item posting service.'); }
    if ($payload['source_type'] === 'opening_balance') {
        throw new DomainException('Use the opening preview and confirmation service for opening balances.');
    }
    return pl_ledger_transaction(fn(): array => pl_post_journal_locked($actorId, $companyId, $bookId, $payload));
}

function pl_get_journal(int $actorId, int $companyId, int $bookId, int $journalId): array
{
    pl_require_company_access($actorId, $companyId);
    pl_ledger_book($companyId, $bookId);
    $journal = DB::queryFirstRow('SELECT * FROM pl_journals WHERE id = %i AND company_id = %i AND book_id = %i FOR SHARE', $journalId, $companyId, $bookId);
    if (!$journal) {
        throw new DomainException('This journal is not available in the selected company and book.');
    }
    $journal['id'] = (int) $journal['id'];
    $journal['reference'] = 'PL-' . str_pad((string) $journal['id'], 8, '0', STR_PAD_LEFT);
    unset($journal['payload_hash'], $journal['idempotency_key']);
    $journal['lines'] = DB::query('SELECT l.id, l.line_number, l.account_id, a.code, a.name, l.description, l.debit, l.credit, l.currency, l.amount_fc, l.rate, l.rate_type, l.rate_source_id, l.amount_base, l.rate_is_stale, l.ic_counterparty_entity_id FROM pl_journal_lines l JOIN pl_accounts a ON a.id = l.account_id WHERE l.journal_id = %i AND l.company_id = %i AND l.book_id = %i ORDER BY l.line_number FOR SHARE', $journalId, $companyId, $bookId);
    foreach ($journal['lines'] as &$line) {
        $line['rate_is_stale'] = (bool) $line['rate_is_stale'];
        foreach (['id','line_number','rate_source_id','ic_counterparty_entity_id'] as $field) { $line[$field] = $line[$field] === null ? null : (int) $line[$field]; }
    }
    unset($line);
    return $journal;
}

function pl_reverse_journal(int $actorId, int $companyId, int $bookId, int $journalId, ?string $date, string $idempotencyKey, string $reason): array
{
    $date ??= gmdate('Y-m-d');
    pl_ledger_date($date);
    $reason = pl_ledger_text($reason, 'Reversal reason', 400);
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $journalId, $date, $idempotencyKey, $reason): array {
        pl_require_company_access($actorId, $companyId, true);
        $book = pl_ledger_book($companyId, $bookId, true);
        $original = pl_get_journal($actorId, $companyId, $bookId, $journalId);
        if ($original['source_type'] === 'opening_balance') {
            throw new DomainException('Use the opening cutover correction action to retain its source and readiness history.');
        }
        if ($original['reversal_of_id'] !== null) {
            throw new DomainException('A reversal cannot itself be reversed in this foundation. Record a new correcting journal.');
        }
        if ($date < $original['journal_date']) {
            throw new DomainException('A reversal cannot be dated before the original journal.');
        }
        $existing = DB::queryFirstRow('SELECT idempotency_key FROM pl_journals WHERE reversal_of_id = %i FOR UPDATE', $journalId);
        if ($existing && $existing['idempotency_key'] !== $idempotencyKey) {
            throw new DomainException('This journal has already been reversed.');
        }
        $lines = [];
        foreach ($original['lines'] as $line) {
            $lines[] = ['account_id' => (int) $line['account_id'], 'debit' => (string) $line['credit'], 'credit' => (string) $line['debit'], 'description' => (string) $line['description']] + pl_currency_line_normalize($line, (string) $original['currency'], (string) $line['credit'], (string) $line['debit']);
        }
        $payload = pl_normalize_journal([
            'date' => $date, 'currency' => (string) $book['currency'], 'source_type' => 'reversal', 'source_reference' => (string) $journalId,
            'idempotency_key' => $idempotencyKey, 'description' => 'Reversal of ' . $original['reference'] . ': ' . $reason, 'lines' => $lines,
        ]);
        return pl_post_journal_locked($actorId, $companyId, $bookId, $payload, $journalId);
    });
}

function pl_trial_balance(int $actorId, int $companyId, int $bookId, ?string $asOf = null): array
{
    pl_require_company_access($actorId, $companyId);
    pl_ledger_book($companyId, $bookId);
    if ($asOf !== null) {
        pl_ledger_date($asOf);
    }
    $accounts = DB::query('SELECT a.id, a.code, a.legacy_code, a.name, a.type, a.is_contra,
        COALESCE(SUM(CASE WHEN j.id IS NOT NULL THEN l.debit ELSE 0 END), 0) AS debit_movement,
        COALESCE(SUM(CASE WHEN j.id IS NOT NULL THEN l.credit ELSE 0 END), 0) AS credit_movement
        FROM pl_accounts a
        LEFT JOIN pl_journal_lines l ON l.account_id = a.id AND l.company_id = a.company_id AND l.book_id = a.book_id
        LEFT JOIN pl_journals j ON j.id = l.journal_id AND j.company_id = a.company_id AND j.book_id = a.book_id AND j.journal_date <= %s
        WHERE a.company_id = %i AND a.book_id = %i
        GROUP BY a.id, a.code, a.legacy_code, a.name, a.type, a.is_contra ORDER BY a.code', $asOf ?? '9999-12-31', $companyId, $bookId);
    $debits = '0.0000';
    $credits = '0.0000';
    foreach ($accounts as &$account) {
        $account['id'] = (int) $account['id'];
        $account['is_contra'] = (bool) $account['is_contra'];
        $account['level'] = pl_account_code_is_valid((string) $account['code']) ? pl_account_code_level((string) $account['code']) : 'account';
        $account['debit_movement'] = bcadd((string) $account['debit_movement'], '0', 4);
        $account['credit_movement'] = bcadd((string) $account['credit_movement'], '0', 4);
        $account['balance'] = bcsub($account['debit_movement'], $account['credit_movement'], 4);
        $account['debit'] = bccomp($account['balance'], '0', 4) > 0 ? $account['balance'] : '0.0000';
        $account['credit'] = bccomp($account['balance'], '0', 4) < 0 ? bcsub('0', $account['balance'], 4) : '0.0000';
        $debits = bcadd($debits, $account['debit'], 4);
        $credits = bcadd($credits, $account['credit'], 4);
    }
    unset($account);
    return ['accounts' => $accounts, 'tree' => pl_report_tree($accounts, ['debit', 'credit', 'balance']),
        'total_debit' => $debits, 'total_credit' => $credits, 'balanced' => bccomp($debits, $credits, 4) === 0];
}
