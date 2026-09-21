<?php
declare(strict_types=1);

/** Transaction codes come from the bundled, versioned CLDR reference; no network lookup. */
function pl_currency_code(string $code): string
{
    static $codes = null;
    if ($codes === null) {
        $json = file_get_contents(dirname(__DIR__, 4) . '/resources/locale/country-defaults-cldr48.json');
        if ($json === false) { throw new RuntimeException('Currency reference is unavailable.'); }
        $reference = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $codes = [];
        foreach ($reference['countries'] as $country) {
            foreach ($country['currencies'] as $currency) { $codes[$currency] = true; }
        }
    }
    if (!isset($codes[$code])) { throw new DomainException('Choose a supported transaction currency code.'); }
    return $code;
}

function pl_fx_rate(string $rate): string
{
    if (!preg_match('/^(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,12})?$/D', $rate) || bccomp($rate, '0', 12) <= 0) {
        throw new DomainException('Enter a positive decimal-string rate with at most twelve decimal places.');
    }
    return bcadd($rate, '0', 12);
}

/** Exact positive multiplication, explicitly half-up to the ledger's four places. */
function pl_fx_convert(string $amount, string $rate): string
{
    $product = bcmul(pl_amount($amount), pl_fx_rate($rate), 16);
    return pl_amount(bcadd($product, '0.00005', 4));
}

function pl_currency_account_properties(array $input, ?array $existing = null): array
{
    $role = $input['role'] ?? ($existing['role'] ?? null);
    // The role-driven mapping migration 013 established; migration 037 adds the advances
    // controls, which are monetary: a fixed number of currency units, owed or claimed.
    $default = in_array($role, ['cash_bank','receivables','payables','customer_advances','supplier_advances'], true) ? true : (in_array($role, ['owner_equity','income','expense'], true) ? false : null);
    $monetary = $input['is_monetary'] ?? ($existing !== null ? ($existing['is_monetary'] === null ? null : (bool) $existing['is_monetary']) : $default);
    if ($monetary !== null && !is_bool($monetary)) { throw new DomainException('Monetary classification must be true, false or unknown.'); }
    $currency = array_key_exists('currency', $input) ? $input['currency'] : ($existing['currency'] ?? null);
    if ($currency !== null && !is_string($currency)) { throw new DomainException('Account currency must be a currency code or null.'); }
    $revaluation = $input['revaluation_account_id'] ?? ($existing['revaluation_account_id'] ?? null);
    if ($revaluation !== null && (!is_int($revaluation) || $revaluation < 1)) { throw new DomainException('Choose a valid revaluation account.'); }
    if (($input['group_account_id'] ?? null) !== null) { throw new DomainException('Group chart mapping is reserved for a later foundation.'); }
    return ['currency' => $currency === null ? null : pl_currency_code($currency), 'is_monetary' => $monetary, 'revaluation_account_id' => $revaluation, 'group_account_id' => null];
}

function pl_currency_validate_account_links(int $companyId, int $bookId, array $data): void
{
    if ($data['revaluation_account_id'] !== null && !DB::queryFirstRow("SELECT id FROM pl_accounts WHERE id = %i AND company_id = %i AND book_id = %i AND is_active = 1 AND type IN ('income','expense') AND currency IS NULL FOR SHARE", $data['revaluation_account_id'], $companyId, $bookId)) {
        throw new DomainException('Revaluation account must be an active scoped currency-neutral income or expense account.');
    }
}

/** Manual rate revisions retain each original source row, including command receipts. */
function pl_currency_rate_enter(int $actorId, int $companyId, int $bookId, array $input): array
{
    pl_demo_require_setup_action();
    $data = ['from_currency' => pl_currency_code(pl_ledger_text($input['from_currency'] ?? null, 'From currency', 3)), 'to_currency' => pl_currency_code(pl_ledger_text($input['to_currency'] ?? null, 'To currency', 3)), 'rate_date' => pl_ledger_date(pl_ledger_text($input['rate_date'] ?? null, 'Rate date', 10)), 'rate_type' => pl_ledger_text($input['rate_type'] ?? 'spot', 'Rate type', 10), 'rate' => pl_fx_rate(pl_ledger_text($input['rate'] ?? null, 'Rate', 29)), 'source' => pl_ledger_text($input['source'] ?? 'manual', 'Rate source', 120), 'note' => pl_ledger_text($input['note'] ?? null, 'Rate evidence/reason', 500), 'supersedes_id' => $input['supersedes_id'] ?? null];
    if ($data['from_currency'] === $data['to_currency'] || !in_array($data['rate_type'], ['spot','actual'], true)) { throw new DomainException('Manual rates need different currencies and spot or actual type.'); }
    if ($data['supersedes_id'] !== null && (!is_int($data['supersedes_id']) || $data['supersedes_id'] < 1)) { throw new DomainException('Choose a valid previous rate.'); }
    $key = pl_period_request_key($input['idempotency_key'] ?? null);
    $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $data, $key, $hash): array {
        pl_require_company_access($actorId, $companyId, true);
        pl_ledger_book($companyId, $bookId, true);
        $previous = DB::queryFirstRow('SELECT * FROM pl_currency_rates WHERE book_id = %i AND idempotency_key = %s FOR UPDATE', $bookId, $key);
        if ($previous) {
            if (!hash_equals($previous['payload_hash'], $hash)) { throw new DomainException('This request key already entered a different rate.'); }
            unset($previous['payload_hash'], $previous['idempotency_key']);
            return $previous;
        }
        $latest = DB::queryFirstRow('SELECT * FROM pl_currency_rates WHERE company_id = %i AND book_id = %i AND from_currency = %s AND to_currency = %s AND rate_date = %s AND rate_type = %s AND source = %s ORDER BY revision DESC LIMIT 1 FOR UPDATE', $companyId, $bookId, $data['from_currency'], $data['to_currency'], $data['rate_date'], $data['rate_type'], $data['source']);
        if (($latest ? (int) $latest['id'] : null) !== $data['supersedes_id']) { throw new DomainException('A correction must explicitly supersede the latest rate for this date and source.'); }
        DB::insert('pl_currency_rates', $data + ['company_id' => $companyId, 'book_id' => $bookId, 'revision' => $latest ? (int) $latest['revision'] + 1 : 1, 'entered_by' => $actorId, 'idempotency_key' => $key, 'payload_hash' => $hash]);
        $row = DB::queryFirstRow('SELECT * FROM pl_currency_rates WHERE id = %i', DB::insertId());
        unset($row['payload_hash'], $row['idempotency_key']);
        return $row;
    });
}

function pl_currency_rate_lookup(int $actorId, int $companyId, int $bookId, string $from, string $to, string $date, string $type = 'spot', string $source = 'manual'): ?array
{
    pl_require_company_access($actorId, $companyId);
    pl_ledger_book($companyId, $bookId);
    pl_currency_code($from); pl_currency_code($to); pl_ledger_date($date);
    if (!in_array($type, ['spot','actual'], true)) { throw new DomainException('Unsupported rate type.'); }
    $source = pl_ledger_text($source, 'Rate source', 120);
    $row = DB::queryFirstRow('SELECT id, from_currency, to_currency, rate_date, rate_type, rate, source, revision FROM pl_currency_rates WHERE company_id = %i AND book_id = %i AND from_currency = %s AND to_currency = %s AND rate_type = %s AND source = %s AND rate_date <= %s ORDER BY rate_date DESC, revision DESC LIMIT 1', $companyId, $bookId, $from, $to, $type, $source, $date);
    if ($row) { $row['rate_is_stale'] = $row['rate_date'] < $date; }
    return $row ?: null;
}

function pl_currency_line_normalize(array $line, string $base, string $debit, string $credit): array
{
    $currency = pl_currency_code(pl_ledger_text($line['currency'] ?? $base, 'Line currency', 3));
    $amountBase = pl_amount($line['amount_base'] ?? bcadd($debit, $credit, 4));
    if (bccomp($amountBase, bcadd($debit, $credit, 4), 4) !== 0) { throw new DomainException('Base amount must equal the positive debit or credit.'); }
    $amountFc = pl_amount($line['amount_fc'] ?? ($currency === $base ? $amountBase : '0'));
    $rate = pl_fx_rate($line['rate'] ?? ($currency === $base ? '1' : '0'));
    $type = $line['rate_type'] ?? 'spot';
    $stale = $line['rate_is_stale'] ?? false;
    if (!in_array($type, ['spot','actual'], true) || !is_bool($stale) || bccomp($amountFc, '0', 4) <= 0) { throw new DomainException('Invalid currency amount, rate type or stale flag.'); }
    foreach (['rate_source_id','ic_counterparty_entity_id'] as $field) {
        if (isset($line[$field]) && (!is_int($line[$field]) || $line[$field] < 1)) { throw new DomainException('Invalid currency reference.'); }
    }
    if ($currency === $base && ($rate !== '1.000000000000' || $amountFc !== $amountBase || $stale || isset($line['rate_source_id']))) { throw new DomainException('Domestic lines require identical amounts, rate one and no rate source or stale flag.'); }
    return ['currency' => $currency, 'amount_fc' => $amountFc, 'rate' => $rate, 'rate_type' => $type, 'rate_source_id' => $line['rate_source_id'] ?? null, 'amount_base' => $amountBase, 'rate_is_stale' => $stale, 'ic_counterparty_entity_id' => $line['ic_counterparty_entity_id'] ?? null];
}

function pl_currency_validate_posting_line(int $actorId, int $companyId, int $bookId, array $payload, array $line, array $account, bool $preserveCarrying = false): void
{
    if ($account['currency'] !== null && $account['currency'] !== $line['currency']) { throw new DomainException('The line currency must match the designated account currency.'); }
    if (!$preserveCarrying && pl_fx_convert($line['amount_fc'], $line['rate']) !== $line['amount_base']) { throw new DomainException('The base amount must equal the transaction amount converted at its frozen rate.'); }
    if ($line['rate_source_id'] !== null) {
        $rate = DB::queryFirstRow('SELECT * FROM pl_currency_rates WHERE id = %i AND company_id = %i AND book_id = %i FOR SHARE', $line['rate_source_id'], $companyId, $bookId);
        if (!$rate || $rate['from_currency'] !== $line['currency'] || $rate['to_currency'] !== $payload['currency'] || $rate['rate_type'] !== $line['rate_type'] || $rate['rate'] !== $line['rate'] || (!$preserveCarrying && ($rate['rate_date'] > $payload['date'] || ($rate['rate_date'] < $payload['date']) !== $line['rate_is_stale']))) { throw new DomainException('The rate reference does not match the frozen posting snapshot.'); }
    } elseif ($line['rate_is_stale']) { throw new DomainException('Stale rates require an explicit prior rate reference.'); }
    if ($line['ic_counterparty_entity_id'] !== null) {
        if ($line['ic_counterparty_entity_id'] === $companyId) { throw new DomainException('An intercompany counterparty must be another company.'); }
        pl_require_company_access($actorId, $line['ic_counterparty_entity_id']);
    }
}

/** Legacy hashes predate FX metadata. Only the identical domestic legacy shape qualifies. */
function pl_currency_legacy_payload(array $payload): ?array
{
    foreach ($payload['lines'] as &$line) {
        if ($line['currency'] !== $payload['currency'] || $line['rate'] !== '1.000000000000' || $line['rate_type'] !== 'spot' || $line['rate_source_id'] !== null || $line['rate_is_stale'] || $line['ic_counterparty_entity_id'] !== null) { return null; }
        foreach (['currency','amount_fc','rate','rate_type','rate_source_id','amount_base','rate_is_stale','ic_counterparty_entity_id'] as $key) { unset($line[$key]); }
    }
    unset($line);
    return $payload;
}
