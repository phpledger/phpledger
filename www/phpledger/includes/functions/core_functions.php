<?php
declare(strict_types=1);

function pl_core_audit(int $actorId, int $companyId, int $bookId, string $entity, int $id, string $action, string $reason, ?array $before, array $after): void
{
    DB::insert('pl_core_audit', [
        'company_id' => $companyId, 'book_id' => $bookId, 'actor_id' => $actorId,
        'entity_type' => $entity, 'entity_id' => $id, 'action' => $action, 'reason' => $reason,
        'before_state' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        'after_state' => json_encode($after, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
    ]);
}

function pl_core_history(int $actorId, int $companyId, int $bookId, string $entity, int $id): array
{
    pl_require_company_access($actorId, $companyId);
    pl_ledger_book($companyId, $bookId);
    return DB::query('SELECT a.id, a.action, a.reason, a.recorded_at, u.display_name FROM pl_core_audit a JOIN pl_users u ON u.id = a.actor_id WHERE a.company_id = %i AND a.book_id = %i AND a.entity_type = %s AND a.entity_id = %i ORDER BY a.id DESC LIMIT 50', $companyId, $bookId, $entity, $id);
}

/** Page the chart within fixed accounting groups; filtering never changes report balances. */
function pl_page_accounts(int $actorId,int $companyId,int $bookId,array $filters): array
{
    $orders=['code'=>['asc'=>'code ASC,id ASC','desc'=>'code DESC,id DESC'],'name'=>['asc'=>'name ASC,id ASC','desc'=>'name DESC,id DESC']];
    $sort=$filters['sort']??'code'; $dir=$filters['dir']??'asc'; $type=$filters['type']??'all'; $status=$filters['status']??'all';
    if (!is_string($sort) || !is_string($dir) || !isset($orders[$sort][$dir]) || !in_array($type,['all','asset','liability','equity','income','expense'],true) || !in_array($status,['all','active','inactive'],true)) { throw new DomainException('Choose valid account filters.'); }
    $order=$orders[$sort][$dir]; $q=pl_ledger_text($filters['q']??'','Search',160,false); $size=pl_table_size($filters['per_page']??25);
    return pl_ledger_transaction(function () use ($actorId,$companyId,$bookId,$filters,$order,$type,$status,$q,$size):array {
        pl_require_company_access($actorId,$companyId); pl_ledger_book($companyId,$bookId);
        $where='company_id=%i AND book_id=%i AND (%s=%s OR type=%s) AND (%s=%s OR is_active=%i) AND (%s=%s OR LOCATE(%s,code)>0 OR LOCATE(%s,name)>0)';
        $args=[$companyId,$bookId,$type,'all',$type,$status,'all',$status==='active'?1:0,$q,'',$q,$q];
        $total=(int)DB::queryFirstField('SELECT COUNT(*) FROM pl_accounts WHERE '.$where,...$args);
        $pages=max(1,(int)ceil($total/$size)); $page=min($pages,max(1,(int)($filters['page']??1)));
        $rows=DB::query('SELECT id,code,legacy_code,name,type,role,money_kind,overdraft_enabled,overdraft_limit,is_active,is_contra FROM pl_accounts WHERE '.$where." ORDER BY FIELD(type,'asset','liability','equity','income','expense'), ".$order.' LIMIT %i OFFSET %i',...array_merge($args,[$size,($page-1)*$size]));
        foreach ($rows as &$row) {
            $row['id']=(int)$row['id']; $row['is_active']=(bool)$row['is_active']; $row['is_contra']=(bool)$row['is_contra'];
            $row['level']=pl_account_code_is_valid((string)$row['code'])?pl_account_code_level((string)$row['code']):'account';
        }
        unset($row);
        return ['rows'=>$rows,'total'=>$total,'page'=>$page,'pages'=>$pages];
    });
}

function pl_get_account(int $actorId, int $companyId, int $bookId, int $id): array
{
    pl_require_company_access($actorId, $companyId);
    pl_ledger_book($companyId, $bookId);
    $row = DB::queryFirstRow('SELECT id, code, legacy_code, name, type, role, money_kind, overdraft_enabled, overdraft_limit, report_classification, semantic_key, is_active, is_contra, revision, currency, is_monetary, revaluation_account_id, group_account_id FROM pl_accounts WHERE id = %i AND company_id = %i AND book_id = %i FOR SHARE', $id, $companyId, $bookId);
    if (!$row) { throw new DomainException('This account is not available in the selected company and book.'); }
    $row['id'] = (int) $row['id'];
    $row['revision'] = (int) $row['revision'];
    $row['is_active'] = (bool) $row['is_active'];
    $row['is_contra'] = (bool) $row['is_contra'];
    $row['overdraft_enabled'] = (bool) $row['overdraft_enabled'];
    $row['overdraft_limit'] = pl_amount((string) $row['overdraft_limit']);
    $row['is_monetary'] = $row['is_monetary'] === null ? null : (bool) $row['is_monetary'];
    $row['level'] = pl_account_code_is_valid((string) $row['code']) ? pl_account_code_level((string) $row['code']) : 'account';
    $row['is_postable'] = pl_account_is_postable($companyId, $bookId, (string) $row['code']);
    foreach (['revaluation_account_id', 'group_account_id'] as $field) { $row[$field] = $row[$field] === null ? null : (int) $row[$field]; }
    return $row;
}

/** A configured facility is explicit bank credit, denominated in the account's currency. */
function pl_account_overdraft_properties(array $input, array $existing = []): array
{
    $enabled = $input['overdraft_enabled'] ?? $existing['overdraft_enabled'] ?? false;
    if (!is_bool($enabled)) { throw new DomainException('Choose whether an agreed bank overdraft facility exists.'); }
    $limit = pl_amount($input['overdraft_limit'] ?? $existing['overdraft_limit'] ?? '0');
    if (!$enabled) { return ['overdraft_enabled' => false, 'overdraft_limit' => '0.0000']; }
    $kind = array_key_exists('money_kind', $input) ? $input['money_kind'] : ($existing['money_kind'] ?? null);
    if ($kind !== 'bank' || ($input['role'] ?? $existing['role'] ?? null) !== 'cash_bank' || ($input['type'] ?? $existing['type'] ?? null) !== 'asset' || bccomp($limit, '0', 4) <= 0) {
        throw new DomainException('An overdraft facility requires an explicitly classified bank account and a positive agreed limit.');
    }
    return ['overdraft_enabled' => true, 'overdraft_limit' => $limit];
}

/** Stable code, root type and operational role cannot be silently reclassified by an edit. */
function pl_save_account(int $actorId, int $companyId, int $bookId, array $input, ?int $id = null, ?int $revision = null): array
{
    pl_demo_require_setup_action();
    $name = pl_ledger_text($input['name'] ?? null, 'Account name', 120);
    $reason = pl_ledger_text($input['reason'] ?? null, 'Reason for this change', 500);
    if (!is_bool($input['is_active'] ?? null)) { throw new DomainException('Choose whether this account is active.'); }
    $active = $input['is_active'];
    $code = pl_ledger_text($input['code'] ?? null, 'Account code', 20);
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,19}$/D', $code)) { throw new DomainException('Use letters, digits, dots, dashes or underscores for the account code.'); }
    $type = $input['type'] ?? '';
    if (!in_array($type, ['asset', 'liability', 'equity', 'income', 'expense'], true)) { throw new DomainException('Choose an account classification.'); }
    // Structured codes (B56) are the shape new charts use; a legacy number is still accepted so a
    // book converted from an older release can keep adding accounts the way it always did.
    $structured = pl_account_code_is_valid($code);
    if ($structured && !pl_account_code_matches_type($code, $type)) {
        throw new DomainException('The first digit of a structured account code must match its classification: 1 asset, 2 liability, 3 equity, 4 income, 5 expense.');
    }
    $heading = $structured && pl_account_code_is_heading($code);
    $role = $input['role'] ?? null;
    // `customer_advances` and `supplier_advances` arrived with migration 037: unapplied
    // credit is an obligation to a customer, a prepayment to a supplier is an asset.
    $roleTypes = ['cash_bank' => 'asset', 'receivables' => 'asset', 'payables' => 'liability', 'owner_equity' => 'equity', 'income' => 'income', 'expense' => 'expense', 'customer_advances' => 'liability', 'supplier_advances' => 'asset'];
    if ($role !== null && (!is_string($role) || !isset($roleTypes[$role]) || $roleTypes[$role] !== $type)) {
        throw new DomainException('The account purpose must match its classification.');
    }
    if ($heading && $role !== null) {
        throw new DomainException('A class or group heading aggregates its accounts and cannot carry an operational purpose.');
    }
    $contra = $input['is_contra'] ?? false;
    if (!is_bool($contra)) { throw new DomainException('Choose whether this is a contra account.'); }
    if ($contra && $type === 'liability') {
        throw new DomainException('A liability is not presented as a deduction. Record a provision against an asset as a contra asset, and an obligation as an ordinary liability.');
    }
    if ($contra && $heading) { throw new DomainException('Mark the contra accounts themselves, not the heading they sit under.'); }
    $data = ['code' => $code, 'name' => $name, 'type' => $type, 'role' => $role, 'is_active' => $active];
    $reportClassification = $input['report_classification'] ?? null;
    if ($reportClassification !== null && ($reportClassification !== 'cost_of_sales' || $type !== 'expense')) {
        throw new DomainException('Cost of sales is available only for expense accounts.');
    }
    $legacyHash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    $data['is_contra'] = $contra;
    if (array_key_exists('money_kind', $input)) {
        $moneyKind = $input['money_kind'];
        if ($moneyKind !== null && (!in_array($moneyKind, ['physical', 'bank'], true) || $role !== 'cash_bank')) {
            throw new DomainException('Choose physical cash or bank only for a cash / bank asset account.');
        }
        $data['money_kind'] = $moneyKind;
    }
    if (array_key_exists('overdraft_enabled', $input)) {
        if (!is_bool($input['overdraft_enabled'])) { throw new DomainException('Choose whether an agreed bank overdraft facility exists.'); }
        $data['overdraft_enabled'] = $input['overdraft_enabled'];
    }
    if (array_key_exists('overdraft_limit', $input)) { $data['overdraft_limit'] = pl_amount($input['overdraft_limit']); }
    if (($data['overdraft_enabled'] ?? null) === false) { $data['overdraft_limit'] = '0.0000'; }
    $currencyInput = $input;
    if ($id === null) { $data += pl_currency_account_properties($input); }
    if ($reportClassification !== null) { $data['report_classification'] = $reportClassification; }
    $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    $key = $id === null ? pl_request_key(pl_ledger_text($input['creation_key'] ?? null, 'Request identity', 128)) : null;
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $id, $revision, $reason, $data, $key, $hash, $currencyInput, $legacyHash): array {
        pl_require_company_access($actorId, $companyId, true);
        pl_ledger_book($companyId, $bookId, true);
        if ($id === null) {
            pl_currency_validate_account_links($companyId, $bookId, $data);
            $prior = DB::queryFirstRow('SELECT id, creation_hash FROM pl_accounts WHERE book_id = %i AND company_id = %i AND creation_key = %s FOR UPDATE', $bookId, $companyId, $key);
            if ($prior) {
                if (!hash_equals((string) $prior['creation_hash'], $hash) && !(array_intersect_key($currencyInput, array_flip(['currency','is_monetary','revaluation_account_id','group_account_id','report_classification','money_kind','overdraft_enabled','overdraft_limit'])) === [] && hash_equals((string) $prior['creation_hash'], $legacyHash))) { throw new DomainException('This request already created a different account. Open the existing account.'); }
                return pl_get_account($actorId, $companyId, $bookId, (int) $prior['id']);
            }
            if (DB::queryFirstField('SELECT id FROM pl_accounts WHERE book_id = %i AND code = %s FOR SHARE', $bookId, $data['code'])) {
                throw new DomainException('This account code is already in use. Choose a different code.');
            }
            pl_account_require_parent($companyId, $bookId, (string) $data['code'], (string) $data['type']);
            $facility = pl_account_overdraft_properties($data);
            DB::insert('pl_accounts', array_replace($data, $facility) + ['company_id' => $companyId, 'book_id' => $bookId, 'creation_key' => $key, 'creation_hash' => $hash]);
            $id = (int) DB::insertId();
            $before = null;
        } else {
            $before = pl_get_account($actorId, $companyId, $bookId, $id);
            if ($revision !== $before['revision']) { throw new DomainException('Someone changed this account. Your values are retained; reload the latest account before applying your changes.'); }
            foreach (['code', 'type', 'role'] as $field) {
                if ($data[$field] !== $before[$field]) { throw new DomainException('Account code, classification and purpose are fixed. Create a new account and use a reviewed journal to correct classification.'); }
            }
            $properties = pl_currency_account_properties($currencyInput, $before);
            $facility = pl_account_overdraft_properties($data, $before);
            if ($properties['currency'] !== $before['currency'] && $facility['overdraft_enabled'] && !array_key_exists('overdraft_limit', $data)) {
                throw new DomainException('Changing a bank account currency requires explicitly confirming its overdraft limit in the new currency.');
            }
            pl_currency_validate_account_links($companyId, $bookId, $properties);
            if (($properties['currency'] !== $before['currency'] || $properties['is_monetary'] !== $before['is_monetary'])
                && DB::queryFirstField('SELECT journal_id FROM pl_journal_lines WHERE account_id=%i AND company_id=%i AND book_id=%i LIMIT 1 FOR SHARE', $id, $companyId, $bookId)) {
                throw new DomainException('Currency and monetary classification are fixed once this account has postings.');
            }
            DB::update('pl_accounts', $properties + $facility + ['name' => $data['name'], 'is_active' => $data['is_active'], 'is_contra' => $data['is_contra'], 'money_kind' => array_key_exists('money_kind', $data) ? $data['money_kind'] : $before['money_kind'], 'report_classification'=>array_key_exists('report_classification',$currencyInput) ? $currencyInput['report_classification'] : $before['report_classification'], 'revision' => $before['revision'] + 1], 'id = %i AND company_id = %i AND book_id = %i', $id, $companyId, $bookId);
        }
        $account = pl_get_account($actorId, $companyId, $bookId, $id);
        pl_core_audit($actorId, $companyId, $bookId, 'account', $id, $before === null ? 'created' : 'updated', $reason, $before, $account);
        return $account;
    });
}

/** Drafts may be unbalanced, but each retained line must be valid and use exact amounts. */
function pl_normalize_general_draft(array $input): array
{
    $data = [
        'document_date' => pl_ledger_date(pl_ledger_text($input['date'] ?? null, 'Journal date', 10)),
        'reference' => pl_ledger_text($input['reference'] ?? '', 'Reference', 120, false),
        'description' => pl_ledger_text($input['description'] ?? null, 'Journal description', 500), 'lines' => [],
    ];
    $lines = $input['lines'] ?? null;
    if (!is_array($lines) || !array_is_list($lines) || count($lines) > 100) { throw new DomainException('A general journal supports up to 100 lines.'); }
    foreach ($lines as $line) {
        if (!is_array($line) || !is_int($line['account_id'] ?? null) || $line['account_id'] < 1) { throw new DomainException('Choose an account for every journal line.'); }
        if (!is_string($line['debit'] ?? null) || !is_string($line['credit'] ?? null)) { throw new DomainException('Enter debit and credit as exact decimal amounts.'); }
        $debit = pl_amount($line['debit']);
        $credit = pl_amount($line['credit']);
        if ((bccomp($debit, '0', 4) > 0) === (bccomp($credit, '0', 4) > 0)) { throw new DomainException('Each line needs a positive debit or credit, but not both.'); }
        $normalizedLine = ['account_id' => $line['account_id'], 'debit' => $debit, 'credit' => $credit, 'description' => pl_ledger_text($line['description'] ?? '', 'Line description', 500, false)];
        // Backend drafts preserve explicit FX inputs; the central funnel validates their book/rate relationships on posting.
        foreach (['currency','amount_fc','rate','rate_type','rate_source_id','amount_base','rate_is_stale','ic_counterparty_entity_id'] as $field) {
            if (!array_key_exists($field, $line)) { continue; }
            $value = $line[$field];
            if (in_array($field, ['amount_fc','amount_base','rate'], true)) {
                if (!is_string($value)) { throw new DomainException('Currency amounts and rates must be exact decimal strings.'); }
                $value = $field === 'rate' ? pl_fx_rate($value) : pl_amount($value);
            } elseif ($field === 'currency') {
                $value = pl_currency_code(pl_ledger_text($value, 'Line currency', 3));
            } elseif ($field === 'rate_type' && !in_array($value, ['spot','actual'], true)) {
                throw new DomainException('Choose a supported rate type.');
            } elseif ($field === 'rate_is_stale' && !is_bool($value)) {
                throw new DomainException('The stale-rate flag must be boolean.');
            } elseif (str_ends_with($field, '_id') && $value !== null && (!is_int($value) || $value < 1)) {
                throw new DomainException('Choose a valid currency reference.');
            }
            $normalizedLine[$field] = $value;
        }
        $data['lines'][] = $normalizedLine;
    }
    if ($data['lines'] === []) { throw new DomainException('Add at least one journal line before saving a draft.'); }
    return $data;
}

function pl_general_totals(array $lines): array
{
    $debit = '0.0000'; $credit = '0.0000';
    foreach ($lines as $line) { $debit = bcadd($debit, $line['debit'], 4); $credit = bcadd($credit, $line['credit'], 4); }
    return ['debit' => $debit, 'credit' => $credit, 'difference' => bcsub($debit, $credit, 4), 'balanced' => count($lines) >= 2 && bccomp($debit, $credit, 4) === 0];
}

function pl_get_general_draft(int $actorId, int $companyId, int $bookId, int $id): array
{
    pl_require_company_access($actorId, $companyId);
    pl_ledger_book($companyId, $bookId);
    $row = DB::queryFirstRow('SELECT d.*, r.id AS reversal_journal_id FROM pl_effective_general_drafts d LEFT JOIN pl_journals r ON r.reversal_of_id = d.journal_id WHERE d.id = %i AND d.company_id = %i AND d.book_id = %i FOR SHARE', $id, $companyId, $bookId);
    if (!$row) { throw new DomainException('This general journal is not available in the selected company and book.'); }
    $view = pl_general_draft_view($row);
    $view['posting_history'] = pl_source_posting_history($actorId, $companyId, $bookId, 'general_journal', $id);
    return $view;
}

/** Format an already authorized row; shared by single-source and bounded-list reads. */
function pl_general_draft_view(array $row): array
{
    foreach (['id', 'company_id', 'book_id', 'revision'] as $field) { $row[$field] = (int) $row[$field]; }
    foreach (['journal_id', 'reversal_journal_id'] as $field) { $row[$field] = $row[$field] === null ? null : (int) $row[$field]; }
    $row['lines'] = json_decode((string) $row['lines'], true, 512, JSON_THROW_ON_ERROR);
    $row['totals'] = pl_general_totals($row['lines']);
    $row['status'] = $row['journal_id'] === null ? 'draft' : ($row['reversal_journal_id'] === null ? 'posted' : 'reversed');
    $row['number'] = 'GJ-' . str_pad((string) $row['id'], 6, '0', STR_PAD_LEFT);
    unset($row['creation_key'], $row['creation_hash']);
    return $row;
}

function pl_save_general_draft(int $actorId, int $companyId, int $bookId, array $input, ?int $id = null, ?int $revision = null): array
{
    $data = pl_normalize_general_draft($input);
    $key = $id === null ? pl_request_key(pl_ledger_text($input['creation_key'] ?? null, 'Request identity', 128)) : null;
    $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $data, $key, $hash, $id, $revision): array {
        pl_require_company_access($actorId, $companyId, true);
        pl_ledger_book($companyId, $bookId, true);
        pl_require_book_ready($companyId);
        foreach ($data['lines'] as $line) {
            $account = pl_get_account($actorId, $companyId, $bookId, $line['account_id']);
            if (!$account['is_active']) { throw new DomainException('Choose active accounts. Inactive accounts retain history but cannot receive new entries.'); }
            if (!$account['is_postable']) { throw new DomainException('Postings belong on the lowest account in the chart. ' . $account['code'] . ' aggregates the accounts below it.'); }
        }
        $storage = $data;
        $storage['lines'] = json_encode($data['lines'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if ($id === null) {
            $prior = DB::queryFirstRow('SELECT id, creation_hash FROM pl_general_drafts WHERE company_id = %i AND book_id = %i AND creation_key = %s FOR UPDATE', $companyId, $bookId, $key);
            if ($prior) {
                if (!hash_equals((string) $prior['creation_hash'], $hash)) { throw new DomainException('This request already created a different draft. Open the saved general journal.'); }
                return pl_get_general_draft($actorId, $companyId, $bookId, (int) $prior['id']);
            }
            pl_demo_require_document_capacity($companyId, $bookId);
            DB::insert('pl_general_drafts', $storage + ['company_id' => $companyId, 'book_id' => $bookId, 'creation_key' => $key, 'creation_hash' => $hash, 'created_by' => $actorId, 'updated_by' => $actorId]);
            $id = (int) DB::insertId();
            $before = null;
        } else {
            $before = pl_get_general_draft($actorId, $companyId, $bookId, $id);
            if ($before['journal_id'] !== null) { throw new DomainException('Posted general journals cannot be edited. Use a linked reversal.'); }
            if ($revision !== $before['revision']) { throw new DomainException('Someone changed this draft. Your values are retained; reload the saved version before editing again.'); }
            DB::update('pl_general_drafts', $storage + ['revision' => $before['revision'] + 1, 'updated_by' => $actorId, 'updated_at' => gmdate('Y-m-d H:i:s')], 'id = %i', $id);
        }
        $draft = pl_get_general_draft($actorId, $companyId, $bookId, $id);
        pl_core_audit($actorId, $companyId, $bookId, 'general_journal', $id, 'draft_saved', $data['description'], $before, $draft);
        return $draft;
    });
}

/** Save the reviewed editor values and post atomically through the existing funnel. */
function pl_save_and_post_general_draft(int $actorId, int $companyId, int $bookId, array $input, ?int $id = null, ?int $revision = null): array
{
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $input, $id, $revision): array {
        $draft = pl_save_general_draft($actorId, $companyId, $bookId, $input, $id, $revision);
        return pl_post_general_draft($actorId, $companyId, $bookId, $draft['id'], $draft['revision']);
    });
}

function pl_post_general_draft(int $actorId, int $companyId, int $bookId, int $id, int $revision): array
{
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $id, $revision): array {
        pl_require_company_access($actorId, $companyId, true);
        $book = pl_ledger_book($companyId, $bookId, true);
        pl_require_book_ready($companyId);
        $draft = pl_get_general_draft($actorId, $companyId, $bookId, $id);
        if ($revision !== $draft['revision']) { throw new DomainException('The saved draft changed. Review its latest version before posting.'); }
        if ($draft['journal_id'] !== null) { return $draft; }
        $journal = pl_post_journal($actorId, $companyId, $bookId, [
            'date' => $draft['document_date'], 'currency' => $book['currency'], 'source_type' => 'general_journal',
            'source_reference' => 'general:' . $id, 'description' => $draft['description'],
            'idempotency_key' => 'general:' . $id . ':post', 'lines' => $draft['lines'],
        ]);
        DB::update('pl_general_drafts', ['journal_id' => $journal['id'], 'updated_by' => $actorId, 'updated_at' => gmdate('Y-m-d H:i:s')], 'id = %i', $id);
        $posted = pl_get_general_draft($actorId, $companyId, $bookId, $id);
        pl_core_audit($actorId, $companyId, $bookId, 'general_journal', $id, 'posted', $draft['description'], $draft, $posted);
        return $posted;
    });
}

function pl_reverse_general_draft(int $actorId, int $companyId, int $bookId, int $id, string $date, string $reason): array
{
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $id, $date, $reason): array {
        pl_require_company_access($actorId, $companyId, true);
        pl_ledger_book($companyId, $bookId, true);
        pl_require_book_ready($companyId);
        $draft = pl_get_general_draft($actorId, $companyId, $bookId, $id);
        if ($draft['journal_id'] === null) { throw new DomainException('Only a posted general journal can be reversed.'); }
        pl_reverse_journal($actorId, $companyId, $bookId, $draft['journal_id'], $date, 'general:' . $id . ':reverse' . ($draft['journal_id'] === (int) ($draft['original_journal_id'] ?? $draft['journal_id']) ? '' : ':' . $draft['journal_id']), $reason);
        $reversed = pl_get_general_draft($actorId, $companyId, $bookId, $id);
        if ($draft['reversal_journal_id'] === null) { pl_core_audit($actorId, $companyId, $bookId, 'general_journal', $id, 'reversed', $reason, $draft, $reversed); }
        return $reversed;
    });
}

function pl_list_general_drafts(int $actorId, int $companyId, int $bookId, int $page = 1, array $options = []): array
{
    pl_require_company_access($actorId, $companyId);
    pl_ledger_book($companyId, $bookId);
    $total = (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_general_drafts WHERE company_id = %i AND book_id = %i', $companyId, $bookId);
    $size = pl_table_size($options['page_size'] ?? 50);
    $search = pl_ledger_text($options['search'] ?? '', 'Search', 160, false);
    $where = ' FROM pl_effective_general_drafts d LEFT JOIN pl_journals r ON r.reversal_of_id = d.journal_id WHERE d.company_id = %i AND d.book_id = %i';
    $args = [$companyId, $bookId];
    $status = $options['status'] ?? 'all';
    if (!in_array($status, ['all','draft','posted','reversed'], true)) { throw new DomainException('Choose a valid journal status.'); }
    if ($status === 'draft') { $where .= ' AND d.journal_id IS NULL'; }
    elseif ($status === 'posted') { $where .= ' AND d.journal_id IS NOT NULL AND r.id IS NULL'; }
    elseif ($status === 'reversed') { $where .= ' AND r.id IS NOT NULL'; }
    if ($search !== '') {
        $where .= ' AND (LOCATE(%s, d.description) > 0 OR LOCATE(%s, d.reference) > 0 OR LOCATE(%s, CONCAT(\'GJ-\', LPAD(d.id, 6, \'0\'))) > 0)';
        array_push($args, $search, $search, strtoupper($search));
    }
    $filtered = $search === '' && $status === 'all' ? $total : (int) DB::queryFirstField('SELECT COUNT(*)' . $where, ...$args);
    $pages = max(1, (int) ceil($filtered / $size));
    $page = min($pages, max(1, $page));
    $order = pl_table_order($options, 'general-journals');
    $rows = DB::query('SELECT d.*, r.id AS reversal_journal_id' . $where . ' ORDER BY ' . $order . ' LIMIT %i OFFSET %i', ...array_merge($args, [$size, ($page - 1) * $size]));
    return ['rows' => array_map('pl_general_draft_view', $rows), 'total' => $filtered, 'records_total' => $total, 'page' => $page, 'pages' => $pages];
}

/**
 * Only a leaf receives postings: a class or group heading never does, and an
 * account stops being postable once it has sub-accounts under it (B56).
 */
function pl_account_is_postable(int $companyId, int $bookId, string $code): bool
{
    if (!pl_account_code_is_valid($code)) { return true; }
    if (pl_account_code_is_heading($code)) { return false; }
    if (pl_account_code_level($code) === 'sub_account') { return true; }
    $parts = pl_account_code_parse($code);
    $prefix = $parts['class'] . '-' . str_pad((string) $parts['group'], 3, '0', STR_PAD_LEFT) . '-' . str_pad((string) $parts['account'], 5, '0', STR_PAD_LEFT) . '-';
    return DB::queryFirstField('SELECT id FROM pl_accounts WHERE company_id = %i AND book_id = %i AND code LIKE %s AND code <> %s LIMIT 1', $companyId, $bookId, $prefix . '%', $code) === null;
}

/** A sub-account may only be created under an account that already exists. */
function pl_account_require_parent(int $companyId, int $bookId, string $code, string $type): void
{
    if (!pl_account_code_is_valid($code) || pl_account_code_level($code) !== 'sub_account') { return; }
    $parent = pl_account_code_parent($code);
    $row = DB::queryFirstRow('SELECT id, type FROM pl_accounts WHERE company_id = %i AND book_id = %i AND code = %s FOR SHARE', $companyId, $bookId, $parent);
    if (!$row) { throw new DomainException('Create the parent account ' . $parent . ' before adding a sub-account under it.'); }
    if ($row['type'] !== $type) { throw new DomainException('A sub-account keeps the classification of the account above it.'); }
}
