<?php
declare(strict_types=1);

/*
 * Trading documents (release plan 1.2 M3).
 *
 * Owner decisions this file implements:
 *   B37  the trading-document accounting policies are configurable in Admin, decided
 *        report by report. Each policy is a per-company/book setting with a recommended
 *        default and its own immutable audit row.
 *   B55  transaction-ID numbering per document type; the series already exists (M2,
 *        document_series_functions.php) and is not touched here.
 *   B64  a company profile (name, address lines, phone, email, tax registrations and
 *        optional footer terms), per company, Admin-editable and empty by default.
 *   B53  every choice records its alternatives and its reversal path — see
 *        docs/accounting/examples/trading-document-policies.md.
 *   B54  the Awan prototype supplied the field set (packs, sales staff, areas, cash on
 *        the invoice); the accounting rules below prevail wherever the two differ.
 *
 * Small typed functions, no request globals, no HTML. Every write goes through the
 * existing posting and audit interfaces.
 */

/* ------------------------------------------------------------------ policies */

/** The recommended default of every B37 policy. A book with no row behaves exactly as 1.1 did. */
function pl_trading_policy_defaults(): array
{
    return [
        'discount_posting' => 'net',
        'discount_account_id' => null,
        'free_goods_account_id' => null,
        'free_goods_output_tax' => 'none',
        'cash_on_invoice_cap' => '0.0000',
    ];
}

/**
 * Read the effective policies of one book.
 *
 * @return array{discount_posting:string, discount_account_id:?int, free_goods_account_id:?int,
 *               free_goods_output_tax:string, cash_on_invoice_cap:string, revision:int}
 */
function pl_trading_policies(int $actorId, int $companyId, int $bookId): array
{
    pl_require_company_access($actorId, $companyId);
    pl_ledger_book($companyId, $bookId);
    $row = DB::queryFirstRow('SELECT * FROM pl_trading_policies WHERE company_id=%i AND book_id=%i FOR SHARE', $companyId, $bookId);
    $policies = pl_trading_policy_defaults() + ['revision' => 0];
    if ($row) {
        $policies = [
            'discount_posting' => (string) $row['discount_posting'],
            'discount_account_id' => $row['discount_account_id'] === null ? null : (int) $row['discount_account_id'],
            'free_goods_account_id' => $row['free_goods_account_id'] === null ? null : (int) $row['free_goods_account_id'],
            'free_goods_output_tax' => (string) $row['free_goods_output_tax'],
            'cash_on_invoice_cap' => bcadd((string) $row['cash_on_invoice_cap'], '0', 4),
            'revision' => (int) $row['revision'],
        ];
    }
    return $policies;
}

/**
 * Save the B37 policies. Owner only, one revision at a time, with an immutable audit row
 * recording who changed what and why. Posted documents are never restated: a policy change
 * applies to documents posted afterwards, which is why the audit trail keeps both states.
 */
function pl_save_trading_policies(int $actorId, int $companyId, int $bookId, array $input): array
{
    pl_demo_require_setup_action();
    $reason = pl_ledger_text($input['reason'] ?? null, 'Reason for this change', 500);
    $key = pl_request_key(pl_ledger_text($input['idempotency_key'] ?? null, 'Request key', 128));
    $discountPosting = $input['discount_posting'] ?? null;
    if (!in_array($discountPosting, ['net', 'gross'], true)) {
        throw new DomainException('Choose whether a line discount posts net to income, or gross with the discount shown as contra-income.');
    }
    $freeGoodsTax = $input['free_goods_output_tax'] ?? null;
    if (!in_array($freeGoodsTax, ['none', 'open_market_value'], true)) {
        throw new DomainException('Choose whether free goods carry no output tax, or output tax at open-market value.');
    }
    $cap = pl_amount(pl_ledger_text($input['cash_on_invoice_cap'] ?? null, 'Cash-on-invoice cap', 30));
    $discountAccountId = isset($input['discount_account_id']) && $input['discount_account_id'] !== '' ? pl_oi_id($input, 'discount_account_id') : null;
    $freeGoodsAccountId = isset($input['free_goods_account_id']) && $input['free_goods_account_id'] !== '' ? pl_oi_id($input, 'free_goods_account_id') : null;
    $revision = $input['revision'] ?? null;
    if (!is_int($revision) || $revision < 0) {
        throw new DomainException('Reload the accounting policies before saving.');
    }
    $data = ['discount_posting' => $discountPosting, 'discount_account_id' => $discountAccountId,
        'free_goods_account_id' => $freeGoodsAccountId, 'free_goods_output_tax' => $freeGoodsTax,
        'cash_on_invoice_cap' => $cap];
    $hash = hash('sha256', json_encode([$actorId, $bookId, $data, $revision, $reason], JSON_THROW_ON_ERROR));
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $data, $revision, $reason, $key, $hash): array {
        if (pl_require_company_access($actorId, $companyId, true)['role'] !== 'owner') {
            throw new DomainException('Only the business owner can change the accounting policies.');
        }
        pl_ledger_book($companyId, $bookId, true);
        $prior = DB::queryFirstRow('SELECT payload_hash, result_json FROM pl_trading_policy_actions WHERE company_id=%i AND request_key=%s FOR UPDATE', $companyId, $key);
        if ($prior) {
            if (!hash_equals((string) $prior['payload_hash'], $hash)) { throw new DomainException('This policy request key already recorded different content.'); }
            return json_decode((string) $prior['result_json'], true, 32, JSON_THROW_ON_ERROR);
        }
        $before = pl_trading_policies($actorId, $companyId, $bookId);
        if ($before['revision'] !== $revision) {
            throw new DomainException('Someone changed these policies. Your values are retained; reload the current policies before applying your changes.');
        }
        if ($data['discount_posting'] === 'gross') {
            $account = $data['discount_account_id'] === null ? null : pl_get_account($actorId, $companyId, $bookId, $data['discount_account_id']);
            if ($account === null || !$account['is_active'] || $account['type'] !== 'income' || !$account['is_contra']) {
                throw new DomainException('Gross discount posting needs an active contra-income account, for example the reserved "Discounts allowed" group 4-910.');
            }
        }
        if ($data['free_goods_account_id'] !== null) {
            $account = pl_get_account($actorId, $companyId, $bookId, $data['free_goods_account_id']);
            if (!$account['is_active'] || $account['type'] !== 'expense' || $account['role'] !== null) {
                throw new DomainException('Free goods need an active general expense account for their promotional carrying value.');
            }
        }
        if ($data['free_goods_output_tax'] === 'open_market_value' && $data['free_goods_account_id'] === null) {
            throw new DomainException('Output tax at open-market value is borne by the business, so it needs the promotional expense account.');
        }
        $result = $data + ['revision' => $revision + 1];
        DB::insertUpdate('pl_trading_policies', $result + ['company_id' => $companyId, 'book_id' => $bookId,
            'updated_by' => $actorId, 'updated_at' => gmdate('Y-m-d H:i:s')]);
        DB::insert('pl_trading_policy_actions', ['company_id' => $companyId, 'book_id' => $bookId, 'scope' => 'policies',
            'actor_id' => $actorId, 'reason' => $reason, 'request_key' => $key, 'payload_hash' => $hash,
            'before_state' => json_encode($before, JSON_THROW_ON_ERROR), 'result_json' => json_encode($result, JSON_THROW_ON_ERROR)]);
        return $result;
    });
}

/**
 * The accounts each policy may name, so Admin offers only accounts the service accepts.
 *
 * @return array{discount: list<array<string,mixed>>, free_goods: list<array<string,mixed>>}
 */
function pl_trading_policy_account_options(int $actorId, int $companyId, int $bookId): array
{
    pl_require_company_access($actorId, $companyId);
    pl_ledger_book($companyId, $bookId);
    $rows = DB::query('SELECT id, code, name, type, role, is_contra FROM pl_accounts WHERE company_id=%i AND book_id=%i AND is_active=1 ORDER BY code', $companyId, $bookId);
    $options = ['discount' => [], 'free_goods' => []];
    foreach ($rows as $row) {
        $row['id'] = (int) $row['id'];
        $row['is_contra'] = (bool) $row['is_contra'];
        if ($row['type'] === 'income' && $row['is_contra']) { $options['discount'][] = $row; }
        if ($row['type'] === 'expense' && $row['role'] === null) { $options['free_goods'][] = $row; }
    }
    return $options;
}

/** @return list<array<string,mixed>> the recorded policy and profile changes, newest first. */
function pl_trading_policy_history(int $actorId, int $companyId): array
{
    pl_require_company_access($actorId, $companyId);
    return DB::query('SELECT a.scope, a.reason, a.recorded_at, u.display_name FROM pl_trading_policy_actions a JOIN pl_users u ON u.id=a.actor_id WHERE a.company_id=%i ORDER BY a.id DESC LIMIT 50', $companyId);
}

/* ---------------------------------------------------------- company profile */

function pl_company_profile_fields(): array
{
    return ['legal_name', 'address_line1', 'address_line2', 'address_line3', 'phone', 'email', 'tax_registrations', 'footer_terms'];
}

/** B64: per company, Admin-editable and empty by default. No field is ever invented. */
function pl_company_profile(int $actorId, int $companyId): array
{
    pl_require_company_access($actorId, $companyId);
    $row = DB::queryFirstRow('SELECT * FROM pl_company_profile WHERE company_id=%i FOR SHARE', $companyId);
    $profile = array_fill_keys(pl_company_profile_fields(), '');
    if ($row) {
        foreach (pl_company_profile_fields() as $field) { $profile[$field] = (string) $row[$field]; }
    }
    $profile['revision'] = $row ? (int) $row['revision'] : 0;
    $profile['is_empty'] = implode('', array_map(static fn (string $f): string => $profile[$f], pl_company_profile_fields())) === '';
    return $profile;
}

function pl_save_company_profile(int $actorId, int $companyId, array $input): array
{
    pl_demo_require_setup_action();
    $reason = pl_ledger_text($input['reason'] ?? null, 'Reason for this change', 500);
    $key = pl_request_key(pl_ledger_text($input['idempotency_key'] ?? null, 'Request key', 128));
    $limits = ['legal_name' => 200, 'address_line1' => 200, 'address_line2' => 200, 'address_line3' => 200,
        'phone' => 80, 'email' => 190, 'tax_registrations' => 300, 'footer_terms' => 2000];
    $data = [];
    foreach ($limits as $field => $limit) {
        $data[$field] = pl_ledger_text($input[$field] ?? '', ucfirst(str_replace('_', ' ', $field)), $limit, false);
    }
    if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        throw new DomainException('Enter a valid email address for the company profile, or leave it empty.');
    }
    $revision = $input['revision'] ?? null;
    if (!is_int($revision) || $revision < 0) { throw new DomainException('Reload the company profile before saving.'); }
    $hash = hash('sha256', json_encode([$actorId, $companyId, $data, $revision, $reason], JSON_THROW_ON_ERROR));
    return pl_ledger_transaction(function () use ($actorId, $companyId, $data, $revision, $reason, $key, $hash): array {
        if (pl_require_company_access($actorId, $companyId, true)['role'] !== 'owner') {
            throw new DomainException('Only the business owner can change the company profile.');
        }
        DB::queryFirstField('SELECT id FROM pl_companies WHERE id=%i FOR UPDATE', $companyId);
        $prior = DB::queryFirstRow('SELECT payload_hash, result_json FROM pl_trading_policy_actions WHERE company_id=%i AND request_key=%s FOR UPDATE', $companyId, $key);
        if ($prior) {
            if (!hash_equals((string) $prior['payload_hash'], $hash)) { throw new DomainException('This profile request key already recorded different content.'); }
            return json_decode((string) $prior['result_json'], true, 32, JSON_THROW_ON_ERROR);
        }
        $before = pl_company_profile($actorId, $companyId);
        if ($before['revision'] !== $revision) {
            throw new DomainException('Someone changed this profile. Your values are retained; reload the current profile before applying your changes.');
        }
        DB::insertUpdate('pl_company_profile', $data + ['company_id' => $companyId, 'revision' => $revision + 1,
            'updated_by' => $actorId, 'updated_at' => gmdate('Y-m-d H:i:s')]);
        $result = pl_company_profile($actorId, $companyId);
        DB::insert('pl_trading_policy_actions', ['company_id' => $companyId, 'book_id' => null, 'scope' => 'company_profile',
            'actor_id' => $actorId, 'reason' => $reason, 'request_key' => $key, 'payload_hash' => $hash,
            'before_state' => json_encode($before, JSON_THROW_ON_ERROR), 'result_json' => json_encode($result, JSON_THROW_ON_ERROR)]);
        return $result;
    });
}

/* ------------------------------------------------- packs, sales staff, areas */

/** Shared shape for the three small trading reference lists. */
function pl_trading_reference_input(array $input): array
{
    $code = strtoupper(pl_ledger_text($input['code'] ?? null, 'Code', 40));
    if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{0,39}$/D', $code)) {
        throw new DomainException('Use letters, digits, underscore or hyphen for a code.');
    }
    $active = $input['is_active'] ?? true;
    if (!is_bool($active)) { throw new DomainException('Choose a valid active status.'); }
    return ['code' => $code, 'name' => pl_ledger_text($input['name'] ?? null, 'Name', 160), 'is_active' => $active];
}

function pl_get_sales_staff(int $actorId, int $companyId, int $bookId, int $id): array
{
    pl_require_company_access($actorId, $companyId); pl_ledger_book($companyId, $bookId);
    $row = DB::queryFirstRow('SELECT * FROM pl_sales_staff WHERE id=%i AND company_id=%i AND book_id=%i FOR SHARE', $id, $companyId, $bookId);
    if (!$row) { throw new DomainException('This sales staff member is not available in the selected company and book.'); }
    return pl_trading_reference_row($row);
}

function pl_get_area(int $actorId, int $companyId, int $bookId, int $id): array
{
    pl_require_company_access($actorId, $companyId); pl_ledger_book($companyId, $bookId);
    $row = DB::queryFirstRow('SELECT * FROM pl_areas WHERE id=%i AND company_id=%i AND book_id=%i FOR SHARE', $id, $companyId, $bookId);
    if (!$row) { throw new DomainException('This area or route is not available in the selected company and book.'); }
    return pl_trading_reference_row($row);
}

function pl_trading_reference_row(array $row): array
{
    foreach (['id', 'company_id', 'book_id', 'revision'] as $field) { $row[$field] = (int) $row[$field]; }
    if (isset($row['product_id'])) { $row['product_id'] = (int) $row['product_id']; }
    $row['is_active'] = (bool) $row['is_active'];
    return $row;
}

/** Inactive rows stay listed so a historical document still resolves its dimension. */
function pl_list_sales_staff(int $actorId, int $companyId, int $bookId): array
{
    pl_require_company_access($actorId, $companyId); pl_ledger_book($companyId, $bookId);
    return array_map('pl_trading_reference_row', DB::query('SELECT * FROM pl_sales_staff WHERE company_id=%i AND book_id=%i ORDER BY code', $companyId, $bookId));
}

function pl_list_areas(int $actorId, int $companyId, int $bookId): array
{
    pl_require_company_access($actorId, $companyId); pl_ledger_book($companyId, $bookId);
    return array_map('pl_trading_reference_row', DB::query('SELECT * FROM pl_areas WHERE company_id=%i AND book_id=%i ORDER BY code', $companyId, $bookId));
}

function pl_save_sales_staff(int $actorId, int $companyId, int $bookId, array $input, ?int $id = null, ?int $revision = null): array
{
    return pl_trading_save_reference($actorId, $companyId, $bookId, 'pl_sales_staff', 'sales_staff', $input, $id, $revision);
}

function pl_save_area(int $actorId, int $companyId, int $bookId, array $input, ?int $id = null, ?int $revision = null): array
{
    return pl_trading_save_reference($actorId, $companyId, $bookId, 'pl_areas', 'area', $input, $id, $revision);
}

function pl_trading_save_reference(int $actorId, int $companyId, int $bookId, string $table, string $entity, array $input, ?int $id, ?int $revision): array
{
    $data = pl_trading_reference_input($input);
    $reason = pl_ledger_text($input['reason'] ?? null, 'Reason', 500);
    $reader = $entity === 'area' ? 'pl_get_area' : 'pl_get_sales_staff';
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $table, $entity, $data, $reason, $id, $revision, $reader): array {
        pl_require_module($actorId, $companyId, $bookId, 'trading-documents');
        pl_ledger_book($companyId, $bookId, true);
        $before = $id === null ? null : $reader($actorId, $companyId, $bookId, $id);
        if ($before !== null) {
            if ($before['revision'] !== $revision) { throw new DomainException('This record changed. Reload it before saving.'); }
            if ($before['code'] !== $data['code']) { throw new DomainException('The code is fixed. Create a separate record for a different code.'); }
            DB::update($table, ['name' => $data['name'], 'is_active' => $data['is_active'], 'revision' => $before['revision'] + 1], 'id=%i AND company_id=%i AND book_id=%i', $id, $companyId, $bookId);
        } else {
            if (DB::queryFirstField('SELECT id FROM ' . $table . ' WHERE company_id=%i AND book_id=%i AND code=%s FOR SHARE', $companyId, $bookId, $data['code'])) {
                throw new DomainException('This code is already in use in this book.');
            }
            DB::insert($table, $data + ['company_id' => $companyId, 'book_id' => $bookId, 'created_by' => $actorId]);
            $id = (int) DB::insertId();
        }
        $after = $reader($actorId, $companyId, $bookId, $id);
        pl_core_audit($actorId, $companyId, $bookId, $entity, $id, $before === null ? 'created' : 'updated', $reason, $before, $after);
        return $after;
    });
}

function pl_get_product_pack(int $actorId, int $companyId, int $bookId, int $id): array
{
    pl_require_company_access($actorId, $companyId); pl_ledger_book($companyId, $bookId);
    $row = DB::queryFirstRow('SELECT * FROM pl_product_packs WHERE id=%i AND company_id=%i AND book_id=%i FOR SHARE', $id, $companyId, $bookId);
    if (!$row) { throw new DomainException('This pack is not available in the selected company and book.'); }
    $row = pl_trading_reference_row($row);
    $row['units_per_pack'] = bcadd((string) $row['units_per_pack'], '0', 4);
    return $row;
}

function pl_list_product_packs(int $actorId, int $companyId, int $bookId, ?int $productId = null): array
{
    pl_require_company_access($actorId, $companyId); pl_ledger_book($companyId, $bookId);
    $rows = $productId === null
        ? DB::query('SELECT id FROM pl_product_packs WHERE company_id=%i AND book_id=%i ORDER BY product_id,code', $companyId, $bookId)
        : DB::query('SELECT id FROM pl_product_packs WHERE company_id=%i AND book_id=%i AND product_id=%i ORDER BY code', $companyId, $bookId, $productId);
    return array_map(static fn (array $row): array => pl_get_product_pack($actorId, $companyId, $bookId, (int) $row['id']), $rows);
}

/**
 * A pack's size is frozen at creation (the database trigger refuses any change), because a
 * posted line stores only its resolved base quantity; a restated pack size would silently
 * restate stock history. Only the name and the active flag may change afterwards.
 */
function pl_save_product_pack(int $actorId, int $companyId, int $bookId, array $input, ?int $id = null, ?int $revision = null): array
{
    $data = pl_trading_reference_input($input);
    $reason = pl_ledger_text($input['reason'] ?? null, 'Reason', 500);
    $productId = pl_oi_id($input, 'product_id');
    $units = pl_amount(pl_ledger_text($input['units_per_pack'] ?? null, 'Units per pack', 30));
    if (bccomp($units, '0', 4) <= 0) { throw new DomainException('A pack must contain a positive number of base units.'); }
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $data, $reason, $productId, $units, $id, $revision): array {
        pl_require_module($actorId, $companyId, $bookId, 'trading-documents');
        pl_ledger_book($companyId, $bookId, true);
        pl_get_inventory_product($actorId, $companyId, $bookId, $productId);
        $before = $id === null ? null : pl_get_product_pack($actorId, $companyId, $bookId, $id);
        if ($before !== null) {
            if ($before['revision'] !== $revision) { throw new DomainException('This pack changed. Reload it before saving.'); }
            if ($before['code'] !== $data['code'] || $before['product_id'] !== $productId || bccomp($before['units_per_pack'], $units, 4) !== 0) {
                throw new DomainException('A pack keeps its product, code and size for ever. Add another pack for a different size.');
            }
            DB::update('pl_product_packs', ['name' => $data['name'], 'is_active' => $data['is_active'], 'revision' => $before['revision'] + 1], 'id=%i AND company_id=%i AND book_id=%i', $id, $companyId, $bookId);
        } else {
            if (DB::queryFirstField('SELECT id FROM pl_product_packs WHERE company_id=%i AND book_id=%i AND product_id=%i AND code=%s FOR SHARE', $companyId, $bookId, $productId, $data['code'])) {
                throw new DomainException('This pack code is already in use for that product.');
            }
            DB::insert('pl_product_packs', $data + ['company_id' => $companyId, 'book_id' => $bookId, 'product_id' => $productId, 'units_per_pack' => $units, 'created_by' => $actorId]);
            $id = (int) DB::insertId();
        }
        $after = pl_get_product_pack($actorId, $companyId, $bookId, $id);
        pl_core_audit($actorId, $companyId, $bookId, 'product_pack', $id, $before === null ? 'created' : 'updated', $reason, $before, $after);
        return $after;
    });
}

/**
 * Packs resolve to a base quantity before pricing, tax and stock issue.
 *
 * `5 cartons + 3 units` of a 12-unit carton is 63 base units — one number, which is what
 * the line stores, what the tax engine sees and what the stock ledger issues. Pure
 * arithmetic: the caller supplies the frozen pack size.
 */
function pl_trading_pack_quantity(string $packQuantity, string $unitQuantity, string $unitsPerPack): string
{
    $packQuantity = pl_amount($packQuantity);
    $unitQuantity = pl_amount($unitQuantity);
    $unitsPerPack = pl_amount($unitsPerPack);
    if (bccomp($unitsPerPack, '0', 4) <= 0) { throw new DomainException('A pack must contain a positive number of base units.'); }
    $total = bcadd(bcmul($packQuantity, $unitsPerPack, 8), $unitQuantity, 8);
    if (bccomp($total, bcadd($total, '0', 4), 8) !== 0) {
        throw new DomainException('A pack quantity must resolve to a base quantity with at most four decimal places.');
    }
    return pl_amount(bcadd($total, '0', 4));
}

/**
 * The frozen pack sizes a document's lines refer to, as `pack_id => units_per_pack`.
 * Resolution is deliberately split from pl_normalize_ar_document() so the normaliser stays
 * a pure function of its input and the resolved quantity is part of the draft's hash.
 */
function pl_trading_document_pack_sizes(int $actorId, int $companyId, int $bookId, array $input): array
{
    $lines = $input['lines'] ?? null;
    if (!is_array($lines)) { return []; }
    $sizes = [];
    foreach ($lines as $line) {
        if (!is_array($line) || ($line['pack_id'] ?? null) === null) { continue; }
        $packId = pl_oi_id($line, 'pack_id');
        if (isset($sizes[$packId])) { continue; }
        $pack = pl_get_product_pack($actorId, $companyId, $bookId, $packId);
        if (($line['product_id'] ?? null) !== null && pl_oi_id($line, 'product_id') !== $pack['product_id']) {
            throw new DomainException('A pack belongs to its own product; choose the pack of the product on that line.');
        }
        $sizes[$packId] = $pack['units_per_pack'];
    }
    return $sizes;
}

/* ---------------------------------------------------- line discount arithmetic */

/**
 * Gross, discount and net for one line, at ledger precision.
 *
 * Decision 2 of the 1.2 frames: the entered value is a percentage and the amount is
 * computed from it; the amount is what the line stores, so switching the editor to
 * amount-first later is a front-end change with no schema or posting effect.
 *
 * @return array{gross:string, discount:string, net:string}
 */
function pl_trading_line_discount(string $gross, string $percent): array
{
    $gross = pl_amount($gross);
    $percent = pl_trading_discount_percent($percent);
    $discount = pl_amount(bcadd(bcdiv(bcmul($gross, $percent, 10), '100', 12), '0.00005', 4));
    if (bccomp($discount, $gross, 4) > 0) { $discount = $gross; }
    return ['gross' => $gross, 'discount' => $discount, 'net' => bcsub($gross, $discount, 4)];
}

function pl_trading_discount_percent(string $percent): string
{
    if (!preg_match('/^(?:0|[1-9][0-9]?|100)(?:\.[0-9]{1,4})?$/D', $percent) || bccomp($percent, '100', 4) > 0) {
        throw new DomainException('A line discount must be between 0 and 100 per cent, with up to four decimal places.');
    }
    return bcadd($percent, '0', 4);
}

/* --------------------------------------------------------- party statement */

/**
 * One party's statement over a date range, built from the posted ledger only.
 *
 * The opening balance, every movement and the closing balance come from the party's
 * control-account journal lines, so the statement reconciles to the ledger by construction
 * rather than by a parallel running total. Open-item detail is read through the existing
 * read services; this module never writes a settlement or an open item.
 */
function pl_party_statement(int $actorId, int $companyId, int $bookId, int $partyId, ?string $from = null, ?string $to = null): array
{
    $to = pl_ledger_date($to ?? gmdate('Y-m-d'));
    $from = pl_ledger_date($from ?? substr($to, 0, 8) . '01');
    if ($from > $to) { throw new DomainException('The beginning of the statement range must be on or before its end.'); }
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $partyId, $from, $to): array {
        pl_require_company_access($actorId, $companyId);
        $book = pl_ledger_book($companyId, $bookId);
        $party = pl_get_party($actorId, $companyId, $bookId, $partyId);
        $items = DB::query('SELECT id, control_account_id, direction, currency FROM pl_open_items WHERE company_id=%i AND book_id=%i AND party_id=%i ORDER BY id FOR SHARE', $companyId, $bookId, $partyId);
        $direction = 'receivable';
        foreach ($items as $item) { if ((string) $item['direction'] === 'payable') { $direction = 'payable'; } }
        $receivable = $direction === 'receivable';
        $opening = '0.0000'; $rows = []; $invoiced = '0.0000'; $received = '0.0000'; $unapplied = '0.0000';
        foreach ($items as $item) {
            $state = pl_open_item_state($companyId, $bookId, (int) $item['id']);
            $document = DB::queryFirstRow('SELECT r.document_id, d.kind, d.document_number FROM pl_ar_document_revisions r JOIN pl_ar_documents d ON d.id=r.document_id WHERE r.open_item_id=%i ORDER BY r.id DESC LIMIT 1 FOR SHARE', (int) $item['id']);
            $number = $document ? pl_document_number_display($document['document_number'] === null ? null : (string) $document['document_number'], (int) $document['document_id'], (string) $document['kind']) : (string) $state['source_reference'];
            foreach ($state['entries'] as $entry) {
                $date = (string) $entry['journal_date'];
                $increases = in_array((string) $entry['kind'], ['recognition', 'allocation_reversal'], true);
                $amount = bcadd((string) $entry['amount_base'], '0', 4);
                if ($date < $from) { $opening = $increases ? bcadd($opening, $amount, 4) : bcsub($opening, $amount, 4); continue; }
                if ($date > $to) { continue; }
                $label = match ((string) $entry['kind']) {
                    'recognition' => $document === null ? 'Opening balance' : ucfirst(str_replace('_', ' ', (string) $document['kind'])),
                    'allocation' => 'Receipt or credit applied',
                    default => 'Allocation reversed',
                };
                $rows[] = ['date' => $date, 'type' => $label, 'number' => $number, 'item_id' => (int) $item['id'],
                    'debit' => $increases === $receivable ? $amount : '0.0000',
                    'credit' => $increases === $receivable ? '0.0000' : $amount,
                    'increases' => $increases, 'amount_base' => $amount,
                    'journal_id' => (int) $entry['journal_id']];
                if ($increases) { $invoiced = bcadd($invoiced, $amount, 4); } else { $received = bcadd($received, $amount, 4); }
            }
            // Decision 9 of the 1.2 frames: money held against the party with nothing left to
            // apply it to is shown inline as an unapplied advance, not as a separate table.
            if (bccomp($state['remaining_fc'], '0', 4) < 0) { $unapplied = bcadd($unapplied, bcsub('0', $state['remaining_base'], 4), 4); }
        }
        usort($rows, static fn (array $a, array $b): int => [$a['date'], $a['journal_id'], $a['item_id']] <=> [$b['date'], $b['journal_id'], $b['item_id']]);
        $balance = $opening;
        foreach ($rows as &$row) {
            $balance = $row['increases'] ? bcadd($balance, $row['amount_base'], 4) : bcsub($balance, $row['amount_base'], 4);
            $row['balance'] = $balance;
        }
        unset($row);
        return ['party' => $party, 'direction' => $direction, 'currency' => (string) $book['currency'],
            'from' => $from, 'to' => $to, 'opening_balance' => $opening, 'closing_balance' => $balance,
            'invoiced' => $invoiced, 'received' => $received, 'unapplied' => $unapplied, 'rows' => $rows,
            // The statement is the control-account movement; nothing here is a parallel total.
            'reconciles' => bccomp($balance, bcadd($opening, bcsub($invoiced, $received, 4), 4), 4) === 0];
    });
}

/** Print loader for /print/statement/<party id>. Read-only, in the record screen's scope. */
function pl_trading_statement_print(int $actorId, int $companyId, int $bookId, int $partyId): array
{
    return pl_party_statement($actorId, $companyId, $bookId, $partyId) + ['company_profile' => pl_company_profile($actorId, $companyId)];
}

/** Print loader for /print/invoice/<document id>. */
function pl_trading_invoice_print(int $actorId, int $companyId, int $bookId, int $documentId): array
{
    $document = pl_get_ar_document($actorId, $companyId, $bookId, $documentId);
    if (!in_array((string) $document['kind'], ['invoice', 'customer_credit'], true)) {
        throw new DomainException('Only a customer invoice or credit note prints on this form.');
    }
    if ($document['journal_id'] === null) {
        throw new DomainException('A draft has no number yet. Post the document before printing it.');
    }
    $taxSummary = [];
    foreach ($document['lines'] as $line) {
        if ((bool) ($line['is_free_goods'] ?? false)) { continue; }
        $rate = bcadd((string) $line['tax_rate'], '0', 6);
        $taxSummary[$rate] ??= ['rate' => $rate, 'label' => (string) ($line['tax_label'] ?? ''), 'taxable' => '0.0000', 'tax' => '0.0000'];
        $taxSummary[$rate]['taxable'] = bcadd($taxSummary[$rate]['taxable'], (string) $line['line_total'], 4);
        $taxSummary[$rate]['tax'] = bcadd($taxSummary[$rate]['tax'], (string) $line['tax_amount'], 4);
    }
    krsort($taxSummary, SORT_STRING);
    return ['document' => $document, 'tax_summary' => array_values($taxSummary),
        'company_profile' => pl_company_profile($actorId, $companyId),
        'sales_staff' => $document['sales_staff_id'] === null ? null : pl_get_sales_staff($actorId, $companyId, $bookId, (int) $document['sales_staff_id']),
        'area' => $document['area_id'] === null ? null : pl_get_area($actorId, $companyId, $bookId, (int) $document['area_id']),
        'warehouse' => $document['warehouse_id'] === null ? null : pl_get_inventory_warehouse($actorId, $companyId, $bookId, (int) $document['warehouse_id']),
        'balance_due' => bcsub((string) $document['total'], (string) $document['cash_received'], 4)];
}

function pl_print_invoice_reference(array $data): string
{
    return (string) $data['document']['number'];
}

function pl_print_statement_reference(array $data): string
{
    return (string) $data['party']['legal_name'] . ' · ' . $data['from'] . ' to ' . $data['to'];
}
