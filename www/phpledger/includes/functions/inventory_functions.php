<?php
declare(strict_types=1);

/** Exact proportional cost. The final issue consumes the complete carrying residual. */
function pl_inventory_cost(string $value, string $quantity, string $take): string
{
    $value = pl_amount($value); $quantity = pl_amount($quantity); $take = pl_amount($take);
    if (bccomp($quantity, '0', 4) <= 0 || bccomp($take, $quantity, 4) > 0) { throw new DomainException('Stock quantity exceeds the available quantity.'); }
    if (bccomp($take, $quantity, 4) === 0) { return $value; }
    return pl_amount(bcadd(bcdiv(bcmul($value, $take, 8), $quantity, 12), '0.00005', 4));
}

function pl_inventory_signed(string $amount): string
{
    return str_starts_with($amount, '-') ? bcsub('0', pl_amount(substr($amount, 1)), 4) : pl_amount($amount);
}

function pl_inventory_command(int $actorId, int $companyId, int $bookId, string $key, array $payload, callable $work): array
{
    $key = pl_request_key($key);
    $hash = hash('sha256', json_encode([$actorId, $payload], JSON_THROW_ON_ERROR));
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $key, $hash, $work): array {
        pl_require_company_access($actorId, $companyId, true);
        pl_ledger_book($companyId, $bookId, true);
        pl_require_module($actorId, $companyId, $bookId, 'inventory');
        $prior = DB::queryFirstRow('SELECT payload_hash,result_json FROM pl_inventory_commands WHERE company_id=%i AND book_id=%i AND request_key=%s FOR UPDATE', $companyId, $bookId, $key);
        if ($prior) {
            if (!hash_equals($prior['payload_hash'], $hash)) { throw new DomainException('This inventory request already recorded different content.'); }
            return json_decode($prior['result_json'], true, 64, JSON_THROW_ON_ERROR);
        }
        return pl_demo_with_document_capacity($companyId, $bookId, function () use ($companyId, $bookId, $actorId, $key, $hash, $work): array {
            $result = $work($key);
            DB::insert('pl_inventory_commands', ['company_id' => $companyId, 'book_id' => $bookId, 'request_key' => $key, 'payload_hash' => $hash, 'result_json' => json_encode($result, JSON_THROW_ON_ERROR), 'actor_id' => $actorId]);
            return $result;
        });
    });
}

function pl_get_inventory_product(int $actorId, int $companyId, int $bookId, int $id): array
{
    pl_require_company_access($actorId, $companyId); pl_ledger_book($companyId, $bookId);
    $row = DB::queryFirstRow('SELECT * FROM pl_products WHERE id=%i AND company_id=%i AND book_id=%i FOR SHARE', $id, $companyId, $bookId);
    if (!$row) { throw new DomainException('This product is not available in the selected company and book.'); }
    foreach (['id','company_id','book_id','inventory_account_id','cogs_account_id','sales_account_id','purchase_account_id','revision'] as $field) { $row[$field] = $row[$field] === null ? null : (int) $row[$field]; }
    $row['is_active'] = (bool) $row['is_active'];
    return $row;
}

function pl_save_inventory_product(int $actorId, int $companyId, int $bookId, array $input, ?int $id = null, ?int $revision = null): array
{
    pl_demo_require_setup_action();
    $data = ['sku' => pl_ledger_text($input['sku'] ?? null, 'Product code', 60), 'name' => pl_ledger_text($input['name'] ?? null, 'Product name', 160),
        'kind' => $input['kind'] ?? '', 'base_unit' => pl_ledger_text($input['base_unit'] ?? null, 'Base unit', 40),
        'selling_price' => pl_amount(pl_ledger_text($input['selling_price'] ?? '0', 'Selling price', 21)), 'is_active' => $input['is_active'] ?? true];
    if (!in_array($data['kind'], ['stock','nonstock'], true) || !is_bool($data['is_active'])) { throw new DomainException('Choose a product kind and active status.'); }
    foreach (['inventory_account_id','cogs_account_id','sales_account_id','purchase_account_id'] as $field) {
        $data[$field] = $input[$field] ?? null;
        if ($data[$field] !== null && (!is_int($data[$field]) || $data[$field] < 1)) { throw new DomainException('Choose valid product accounts.'); }
    }
    if ($data['kind'] === 'nonstock') { $data['inventory_account_id'] = null; $data['cogs_account_id'] = null; }
    $reason = pl_ledger_text($input['reason'] ?? null, 'Product change reason', 500);
    return pl_inventory_command($actorId, $companyId, $bookId, (string) ($input['idempotency_key'] ?? $input['creation_key'] ?? ''), ['product', $id, $revision, $data, $reason], function () use ($actorId, $companyId, $bookId, $id, $revision, $data, $reason): array {
        $book = pl_ledger_book($companyId, $bookId);
        $before = $id === null ? null : pl_get_inventory_product($actorId, $companyId, $bookId, $id);
        if ($before && $before['revision'] !== $revision) { throw new DomainException('The product revision changed. Reload before saving.'); }
        $types = ['inventory_account_id' => ['asset'], 'cogs_account_id' => ['expense'], 'sales_account_id' => ['income'], 'purchase_account_id' => ['expense','asset']];
        foreach ($types as $field => $allowed) {
            if ($data[$field] === null && $data['kind'] === 'nonstock' && in_array($field, ['inventory_account_id','cogs_account_id'], true)) { continue; }
            if ($data[$field] === null) { throw new DomainException('Stock products need inventory and cost-of-sales accounts.'); }
            $account = pl_get_account($actorId, $companyId, $bookId, $data[$field]);
            if (!$account['is_active'] || !in_array($account['type'], $allowed, true) || ($account['currency'] !== null && $account['currency'] !== $book['currency'])
                || in_array($account['role'], ['cash_bank','receivables','payables'], true)) { throw new DomainException('Product accounts must be active, correctly classified and use the functional currency.'); }
        }
        if ($before) {
            foreach (['sku','kind','base_unit','inventory_account_id','cogs_account_id'] as $field) {
                if ($before[$field] !== $data[$field]) { throw new DomainException('Product identity, base unit and stock accounts are fixed. Create a separate product for a different identity.'); }
            }
            DB::update('pl_products', $data + ['revision' => $before['revision'] + 1], 'id=%i AND company_id=%i AND book_id=%i', $id, $companyId, $bookId);
        } else {
            if (DB::queryFirstField('SELECT id FROM pl_products WHERE company_id=%i AND sku=%s FOR SHARE', $companyId, $data['sku'])) { throw new DomainException('This product code is already in use.'); }
            DB::insert('pl_products', $data + ['company_id' => $companyId, 'book_id' => $bookId, 'created_by' => $actorId]); $id = (int) DB::insertId();
        }
        $result = pl_get_inventory_product($actorId, $companyId, $bookId, $id);
        pl_core_audit($actorId, $companyId, $bookId, 'product', $id, $before === null ? 'created' : 'updated', $reason, $before, $result);
        return $result;
    });
}

/** Reads remain available even when the owner disables new inventory operations. */
function pl_list_inventory_products(int $actorId, int $companyId, int $bookId): array
{
    pl_require_company_access($actorId, $companyId); pl_ledger_book($companyId, $bookId);
    $rows = DB::query('SELECT id FROM pl_products WHERE company_id=%i AND book_id=%i ORDER BY sku', $companyId, $bookId);
    return array_map(static fn (array $row): array => pl_get_inventory_product($actorId, $companyId, $bookId, (int) $row['id']), $rows);
}

/** Product master paging; financial valuation remains in the existing stock service. */
function pl_page_inventory_products(int $actorId,int $companyId,int $bookId,array $filters): array
{
    pl_require_company_access($actorId,$companyId); pl_ledger_book($companyId,$bookId);
    $orders=['name'=>['asc'=>'name ASC,id ASC','desc'=>'name DESC,id ASC'],'sku'=>['asc'=>'sku ASC,id ASC','desc'=>'sku DESC,id ASC']];
    $sort=$filters['sort']??'name'; $dir=$filters['dir']??'asc'; $kind=$filters['kind']??'all'; $status=$filters['status']??'all';
    if (!is_string($sort) || !is_string($dir) || !isset($orders[$sort][$dir]) || !in_array($kind,['all','stock','nonstock'],true) || !in_array($status,['all','active','inactive'],true)) { throw new DomainException('Unsupported product list filter.'); }
    $size=pl_table_size($filters['per_page']??25); $search=pl_ledger_text($filters['q']??'','Search',160,false);
    $where=' FROM pl_products WHERE company_id=%i AND book_id=%i AND (%s=\'all\' OR kind=%s) AND (%s=\'all\' OR (%s=\'active\' AND is_active=1) OR (%s=\'inactive\' AND is_active=0)) AND (%s=\'\' OR LOCATE(%s,name)>0 OR LOCATE(%s,sku)>0)';
    $args=[$companyId,$bookId,$kind,$kind,$status,$status,$status,$search,$search,$search];
    $total=(int)DB::queryFirstField('SELECT COUNT(*)'.$where,...$args); $pages=max(1,(int)ceil($total/$size)); $page=min($pages,max(1,(int)($filters['page']??1)));
    $rows=DB::query('SELECT id,sku,name,kind,base_unit,is_active'.$where.' ORDER BY '.$orders[$sort][$dir].' LIMIT %i OFFSET %i',...array_merge($args,[$size,($page-1)*$size]));
    return ['rows'=>$rows,'total'=>$total,'page'=>$page,'pages'=>$pages];
}

/**
 * Warehouse identity, kind and default assignment are permanent; edits change name, the
 * van's driver, vehicle and route, or active status only. A van is a warehouse of kind
 * `mobile` (migration 037, DOCUMENT-MODEL.md §2); the book default is always `fixed`.
 */
function pl_get_inventory_warehouse(int $actorId, int $companyId, int $bookId, int $id): array
{
    pl_require_company_access($actorId, $companyId); pl_ledger_book($companyId, $bookId);
    $row = DB::queryFirstRow('SELECT id,company_id,book_id,code,name,kind,driver_name,vehicle_reference,route_name,is_default,is_active,revision,created_by,created_at FROM pl_inventory_warehouses WHERE id=%i AND company_id=%i AND book_id=%i FOR SHARE', $id, $companyId, $bookId);
    if (!$row) { throw new DomainException('This warehouse is not available in the selected company and book.'); }
    foreach (['id','company_id','book_id','revision','created_by'] as $field) { $row[$field] = (int) $row[$field]; }
    foreach (['driver_name','vehicle_reference','route_name'] as $field) { $row[$field] = $row[$field] === null ? null : (string) $row[$field]; }
    $row['is_default'] = (bool) $row['is_default']; $row['is_active'] = (bool) $row['is_active'];
    $row['is_mobile'] = $row['kind'] === 'mobile';
    return $row;
}

/** The kinds a stock location can have. A van is never the book default. */
function pl_inventory_warehouse_kinds(): array
{
    return ['fixed' => 'Warehouse or store', 'mobile' => 'Van or mobile location'];
}

/** Includes inactive warehouses so historical stock remains visible. Reads never require the locations module. */
function pl_list_inventory_warehouses(int $actorId, int $companyId, int $bookId): array
{
    pl_require_company_access($actorId, $companyId); pl_ledger_book($companyId, $bookId);
    return array_map(static fn (array $row): array => pl_get_inventory_warehouse($actorId, $companyId, $bookId, (int) $row['id']),
        DB::query('SELECT id FROM pl_inventory_warehouses WHERE company_id=%i AND book_id=%i ORDER BY is_default DESC,code', $companyId, $bookId));
}

/** The default warehouse exists for every book (migration backfill or company creation); provision once under the book lock if missing. */
function pl_inventory_default_warehouse(int $actorId, int $companyId, int $bookId): array
{
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId): array {
        pl_require_company_access($actorId, $companyId); pl_ledger_book($companyId, $bookId, true);
        $id = DB::queryFirstField('SELECT id FROM pl_inventory_warehouses WHERE company_id=%i AND book_id=%i AND is_default=1 FOR SHARE', $companyId, $bookId);
        if (!$id) {
            pl_require_company_access($actorId, $companyId, true);
            pl_require_module($actorId, $companyId, $bookId, 'inventory');
            DB::insert('pl_inventory_warehouses', ['company_id' => $companyId, 'book_id' => $bookId, 'code' => 'DEFAULT', 'name' => 'Default warehouse', 'is_default' => true, 'created_by' => $actorId]);
            $id = (int) DB::insertId();
            pl_core_audit($actorId, $companyId, $bookId, 'warehouse', $id, 'created', 'Default warehouse provisioned', null, pl_get_inventory_warehouse($actorId, $companyId, $bookId, $id));
        }
        return pl_get_inventory_warehouse($actorId, $companyId, $bookId, (int) $id);
    });
}

/**
 * Input: code, name, kind (`fixed` or `mobile`), driver_name, vehicle_reference, route_name,
 * is_active (bool), reason, idempotency_key; edits require the current revision. Needs the
 * locations module. A `mobile` location names its driver; a `fixed` one carries no driver.
 */
function pl_save_inventory_warehouse(int $actorId, int $companyId, int $bookId, array $input, ?int $id = null, ?int $revision = null): array
{
    pl_demo_require_setup_action();
    $data = ['code' => pl_ledger_text($input['code'] ?? null, 'Warehouse code', 60), 'name' => pl_ledger_text($input['name'] ?? null, 'Warehouse name', 160),
        'kind' => $input['kind'] ?? 'fixed', 'is_active' => $input['is_active'] ?? true];
    if (!is_string($data['kind']) || !isset(pl_inventory_warehouse_kinds()[$data['kind']])) { throw new DomainException('Choose whether this location is a warehouse or a van.'); }
    foreach (['driver_name' => 'Driver or salesman', 'vehicle_reference' => 'Vehicle registration', 'route_name' => 'Route'] as $field => $label) {
        $value = $input[$field] ?? null;
        $data[$field] = $value === null || $value === '' ? null : pl_ledger_text($value, $label, $field === 'vehicle_reference' ? 60 : 160);
    }
    if ($data['kind'] === 'mobile') {
        if ($data['driver_name'] === null) { throw new DomainException('A van needs the driver or salesman who carries its stock.'); }
    } else {
        foreach (['driver_name','vehicle_reference','route_name'] as $field) { $data[$field] = null; }
    }
    if (!is_bool($data['is_active'])) { throw new DomainException('Choose a valid warehouse active status.'); }
    $reason = pl_ledger_text($input['reason'] ?? null, 'Warehouse change reason', 500);
    return pl_inventory_command($actorId, $companyId, $bookId, (string) ($input['idempotency_key'] ?? $input['creation_key'] ?? ''), ['warehouse', $id, $revision, $data, $reason],
        function () use ($actorId, $companyId, $bookId, $id, $revision, $data, $reason): array {
            pl_require_module($actorId, $companyId, $bookId, 'inventory-locations');
            pl_inventory_default_warehouse($actorId, $companyId, $bookId);
            $before = $id === null ? null : pl_get_inventory_warehouse($actorId, $companyId, $bookId, $id);
            if ($before) {
                if ($before['revision'] !== $revision) { throw new DomainException('The warehouse revision changed. Reload before saving.'); }
                if ($before['code'] !== $data['code']) { throw new DomainException('Warehouse identity is fixed. Create a separate warehouse for a different code.'); }
                if ($before['kind'] !== $data['kind']) { throw new DomainException('A location cannot change between a warehouse and a van. Create a separate location instead.'); }
                if ($before['is_default'] && !$data['is_active']) { throw new DomainException('The default warehouse must remain active.'); }
                DB::update('pl_inventory_warehouses', $data + ['revision' => $before['revision'] + 1], 'id=%i AND company_id=%i AND book_id=%i', $id, $companyId, $bookId);
            } else {
                if (DB::queryFirstField('SELECT id FROM pl_inventory_warehouses WHERE company_id=%i AND book_id=%i AND code=%s FOR SHARE', $companyId, $bookId, $data['code'])) { throw new DomainException('This warehouse code is already in use.'); }
                DB::insert('pl_inventory_warehouses', $data + ['company_id' => $companyId, 'book_id' => $bookId, 'created_by' => $actorId]); $id = (int) DB::insertId();
            }
            $result = pl_get_inventory_warehouse($actorId, $companyId, $bookId, $id);
            pl_core_audit($actorId, $companyId, $bookId, 'warehouse', $id, $before === null ? 'created' : 'updated', $reason, $before, $result);
            return $result;
        });
}

/** An omitted warehouse is the book default and never needs the locations module; any other warehouse does. */
function pl_inventory_movement_warehouse(int $actorId, int $companyId, int $bookId, ?int $id): array
{
    $warehouse = $id === null ? pl_inventory_default_warehouse($actorId, $companyId, $bookId) : pl_get_inventory_warehouse($actorId, $companyId, $bookId, $id);
    if (!$warehouse['is_default']) { pl_require_module($actorId, $companyId, $bookId, 'inventory-locations', false); }
    if (!$warehouse['is_active']) { throw new DomainException('Stock movements require an active warehouse.'); }
    return $warehouse;
}

/** A NULL warehouse filter means every warehouse. Legacy NULL rows belong to the permanent default. */
function pl_inventory_balance(int $actorId, int $companyId, int $bookId, int $productId, ?string $asOf = null, ?int $warehouseId = null): array
{
    pl_get_inventory_product($actorId, $companyId, $bookId, $productId);
    $asOf = pl_ledger_date($asOf ?? '9998-12-31');
    $filter = ''; $args = [$companyId, $bookId, $productId, $asOf];
    if ($warehouseId !== null) {
        $warehouse = pl_get_inventory_warehouse($actorId, $companyId, $bookId, $warehouseId);
        $filter = ' AND (warehouse_id=%i' . ($warehouse['is_default'] ? ' OR warehouse_id IS NULL' : '') . ')'; $args[] = $warehouseId;
    }
    // Locking reads see the latest committed rows after acquiring the book lock, including racing requests.
    $rows = DB::query('SELECT quantity_delta,value_delta,movement_date FROM pl_inventory_movements WHERE company_id=%i AND book_id=%i AND product_id=%i AND movement_date<=%s' . $filter . ' ORDER BY movement_date,id FOR SHARE', ...$args);
    $quantity = '0.0000'; $value = '0.0000'; $latest = null;
    foreach ($rows as $row) { $quantity = bcadd($quantity, $row['quantity_delta'], 4); $value = bcadd($value, $row['value_delta'], 4); $latest = $row['movement_date']; }
    return ['quantity' => $quantity, 'value_base' => $value, 'average_cost' => bccomp($quantity, '0', 4) > 0 ? bcdiv($value, $quantity, 12) : '0.000000000000', 'latest_date' => $latest];
}

function pl_inventory_movement_input(array $input): array
{
    $product = $input['product_id'] ?? null; $offset = $input['offset_account_id'] ?? null;
    if (!is_int($product) || $product < 1 || ($offset !== null && (!is_int($offset) || $offset < 1))) { throw new DomainException('Choose a product and a valid offset account.'); }
    $sourceType = pl_ledger_text($input['source_type'] ?? 'stock_adjustment', 'Stock source type', 40);
    if (!preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $sourceType)) { throw new DomainException('Invalid inventory source type.'); }
    $data = ['product_id' => $product, 'offset_account_id' => $offset, 'date' => pl_ledger_date(pl_ledger_text($input['date'] ?? null, 'Movement date', 10)),
        'source_type' => $sourceType, 'source_reference' => pl_ledger_text($input['source_reference'] ?? null, 'Stock source reference', 120), 'reason' => pl_ledger_text($input['reason'] ?? null, 'Movement reason', 500)];
    foreach (['source_document_id','source_journal_id'] as $field) {
        $data[$field] = $input[$field] ?? null;
        if ($data[$field] !== null && (!is_int($data[$field]) || $data[$field] < 1)) { throw new DomainException('Invalid inventory source identity.'); }
    }
    // The key is absent when not supplied so command hashes recorded before stock locations still match on retry.
    if (isset($input['warehouse_id'])) {
        if (!is_int($input['warehouse_id']) || $input['warehouse_id'] < 1) { throw new DomainException('Choose a valid warehouse.'); }
        $data['warehouse_id'] = $input['warehouse_id'];
    }
    return $data;
}

/** Read-only movement plan, shared by preview and the single inventory writer. Transfer legs pass their kind and the out leg. */
function pl_inventory_movement_plan(int $actorId, int $companyId, int $bookId, array $data, string $quantity, string $value, ?array $previewBasis = null, ?string $kind = null, ?int $original = null): array
{
    pl_require_module($actorId, $companyId, $bookId, 'inventory');
    $book = pl_ledger_book($companyId, $bookId, true); pl_require_book_ready($companyId);
    $product = pl_get_inventory_product($actorId, $companyId, $bookId, $data['product_id']);
    if ($product['kind'] !== 'stock' || !$product['is_active']) { throw new DomainException('Stock movements require an active stock product.'); }
    $inventoryAccount = pl_get_account($actorId, $companyId, $bookId, $product['inventory_account_id']);
    if (!$inventoryAccount['is_active']) { throw new DomainException('The inventory account must remain active for new stock movements.'); }
    pl_opening_assert_posting_allowed($companyId, $bookId, ['source_type' => 'inventory_movement', 'date' => $data['date']]);
    if (!DB::queryFirstField('SELECT id FROM pl_inventory_movements WHERE company_id=%i AND book_id=%i AND inventory_account_id=%i LIMIT 1 FOR SHARE', $companyId, $bookId, $product['inventory_account_id'])) {
        $existingValue = '0.0000';
        foreach (DB::query('SELECT debit,credit FROM pl_journal_lines WHERE company_id=%i AND book_id=%i AND account_id=%i FOR SHARE', $companyId, $bookId, $product['inventory_account_id']) as $line) { $existingValue = bcadd($existingValue, bcsub($line['debit'], $line['credit'], 4), 4); }
        if (bccomp($existingValue, '0', 4) !== 0) { throw new DomainException('Review and convert the existing inventory opening balance before recording new stock movements.'); }
    }
    $warehouse = pl_inventory_movement_warehouse($actorId, $companyId, $bookId, $data['warehouse_id'] ?? null);
    $data['warehouse_id'] = $warehouse['id'];
    $state = $previewBasis ?? pl_inventory_balance($actorId, $companyId, $bookId, $product['id'], null, $warehouse['id']);
    if ($state['latest_date'] !== null && $data['date'] < $state['latest_date']) { throw new DomainException('Stock cannot be backdated before the latest movement for this product in this warehouse.'); }
    $quantityAfter = pl_inventory_signed(bcadd($state['quantity'], $quantity, 4)); $valueAfter = pl_inventory_signed(bcadd($state['value_base'], $value, 4));
    if (bccomp($quantityAfter, '0', 4) < 0 || bccomp($valueAfter, '0', 4) < 0 || (bccomp($quantityAfter, '0', 4) === 0 && bccomp($valueAfter, '0', 4) !== 0)) { throw new DomainException('This movement would leave negative stock or an unexplained stock value without quantity.'); }
    if (bccomp($quantity, '0', 4) === 0 && bccomp($value, '0', 4) === 0) { throw new DomainException('A stock movement must change quantity or value.'); }
    if (DB::queryFirstField('SELECT id FROM pl_inventory_movements WHERE book_id=%i AND source_type=%s AND source_reference=%s FOR SHARE', $bookId, $data['source_type'], $data['source_reference'])) { throw new DomainException('This stock source already has a movement. Reuse its original request key.'); }
    $period = DB::query('SELECT id,status FROM pl_periods WHERE company_id=%i AND book_id=%i AND start_date<=%s AND end_date>=%s FOR UPDATE', $companyId, $bookId, $data['date'], $data['date']);
    if (count($period) !== 1 || $period[0]['status'] !== 'open') { throw new DomainException('The movement date must fall within exactly one open accounting period.'); }
    $transfer = in_array($kind, ['transfer_out','transfer_in'], true);
    if ($transfer) {
        if ($data['source_type'] !== 'inventory_' . $kind || $data['offset_account_id'] !== null) { throw new DomainException('Transfers require their paired carrying-value source.'); }
        if ($kind === 'transfer_in') {
            $out = DB::queryFirstRow("SELECT * FROM pl_inventory_movements WHERE id=%i AND company_id=%i AND book_id=%i AND kind='transfer_out' FOR SHARE", (int) $original, $companyId, $bookId);
            if (!$out || (int) $out['warehouse_id'] === $warehouse['id'] || (int) $out['product_id'] !== $product['id'] || $out['movement_date'] !== $data['date'] || $out['source_reference'] !== $data['source_reference']
                || bccomp(bcadd($out['quantity_delta'], $quantity, 4), '0', 4) !== 0 || bccomp(bcadd($out['value_delta'], $value, 4), '0', 4) !== 0) { throw new DomainException('Transfer legs must match product, date, source and exact carrying value.'); }
        }
    }
    $journalPayload = null;
    if (!$transfer && bccomp($value, '0', 4) !== 0) {
        if ($data['offset_account_id'] === null || $data['offset_account_id'] === $product['inventory_account_id']) { throw new DomainException('Choose a distinct offset account for the stock value.'); }
        $offset = pl_get_account($actorId, $companyId, $bookId, $data['offset_account_id']);
        if (!$offset['is_active'] || in_array($offset['role'], ['receivables','payables'], true)) { throw new DomainException('Use the shared AR/AP services for customer and supplier balances.'); }
        $positive = bccomp($value, '0', 4) > 0; $amount = ltrim($value, '-');
        $journalPayload = ['date' => $data['date'], 'currency' => $book['currency'], 'source_type' => 'inventory_movement',
            'source_reference' => $data['source_type'] . ':' . substr(hash('sha256', $data['source_reference']), 0, 48), 'description' => $data['reason'],
            'lines' => [['account_id' => $product['inventory_account_id'], 'debit' => $positive ? $amount : '0', 'credit' => $positive ? '0' : $amount],
                ['account_id' => $data['offset_account_id'], 'debit' => $positive ? '0' : $amount, 'credit' => $positive ? $amount : '0']]];
    }
    return ['product'=>$product,'warehouse'=>$warehouse,'data'=>$data,'recorded'=>$state,'quantity_after'=>$quantityAfter,'value_after'=>$valueAfter,'journal'=>$journalPayload];
}

/** Internal movement writer. All callers hold the book lock and append through this single stock funnel. */
function pl_inventory_record(int $actorId, int $companyId, int $bookId, array $data, string $kind, string $quantity, string $value, string $key, ?int $original = null): array
{
    $plan=pl_inventory_movement_plan($actorId,$companyId,$bookId,$data,$quantity,$value,null,$kind,$original);
    $product=$plan['product']; $data=$plan['data']; $journalId=null;
    if ($plan['journal']!==null) {
        $journal=pl_post_journal($actorId,$companyId,$bookId,$plan['journal']+['idempotency_key'=>'inventory:'.hash('sha256',$key)]);
        $journalId=(int)$journal['id'];
    }
    DB::insert('pl_inventory_movements', ['company_id' => $companyId, 'book_id' => $bookId, 'product_id' => $product['id'], 'warehouse_id' => $data['warehouse_id'], 'movement_date' => $data['date'], 'kind' => $kind,
        'quantity_delta' => $quantity, 'value_delta' => $value, 'inventory_account_id' => $product['inventory_account_id'], 'offset_account_id' => $data['offset_account_id'], 'journal_id' => $journalId,
        'original_movement_id' => $original, 'source_type' => $data['source_type'], 'source_reference' => $data['source_reference'], 'source_document_id' => $data['source_document_id'], 'source_journal_id' => $data['source_journal_id'],
        'reason' => $data['reason'], 'created_by' => $actorId]);
    return ['movement_id' => (int) DB::insertId(), 'journal_id' => $journalId, 'product_id' => $product['id'], 'warehouse_id' => $data['warehouse_id'], 'quantity' => ltrim($quantity, '-'), 'value_base' => ltrim($value, '-'), 'quantity_delta' => $quantity, 'value_delta' => $value, 'inventory_account_id' => $product['inventory_account_id'], 'source_type' => $data['source_type'], 'source_reference' => $data['source_reference']];
}

function pl_inventory_receive(int $actorId, int $companyId, int $bookId, array $input): array
{
    $data = pl_inventory_movement_input($input); $quantity = pl_amount(pl_ledger_text($input['quantity'] ?? null, 'Quantity', 21)); $value = pl_amount(pl_ledger_text($input['amount_base'] ?? null, 'Base amount', 22));
    if (bccomp($quantity, '0', 4) <= 0 || bccomp($value, '0', 4) <= 0) { throw new DomainException('A receipt needs positive quantity and carrying value.'); }
    return pl_inventory_command($actorId, $companyId, $bookId, (string) ($input['idempotency_key'] ?? ''), ['receipt', $data, $quantity, $value],
        fn (string $key): array => pl_inventory_record($actorId, $companyId, $bookId, $data, 'receipt', $quantity, $value, $key));
}

function pl_inventory_issue(int $actorId, int $companyId, int $bookId, array $input): array
{
    $data = pl_inventory_movement_input($input); $quantity = pl_amount(pl_ledger_text($input['quantity'] ?? null, 'Quantity', 21));
    if (bccomp($quantity, '0', 4) <= 0) { throw new DomainException('An issue needs a positive quantity.'); }
    return pl_inventory_command($actorId, $companyId, $bookId, (string) ($input['idempotency_key'] ?? ''), ['issue', $data, $quantity], function (string $key) use ($actorId, $companyId, $bookId, $data, $quantity): array {
        $plan=pl_inventory_issue_plan($actorId,$companyId,$bookId,$data,$quantity);
        return pl_inventory_record($actorId,$companyId,$bookId,$plan['data'],'issue',$plan['quantity_delta'],$plan['value_delta'],$key);
    });
}

/** Shared issue plan; a preview may supply its earlier simulated lines' remaining basis. */
function pl_inventory_issue_plan(int $actorId,int $companyId,int $bookId,array $data,string $quantity,?array $basis=null): array
{
    $product=pl_get_inventory_product($actorId,$companyId,$bookId,$data['product_id']);
    $data['offset_account_id']??=$product['cogs_account_id'];
    if ($basis===null) {
        $data['warehouse_id']=pl_inventory_movement_warehouse($actorId,$companyId,$bookId,$data['warehouse_id']??null)['id'];
        $basis=pl_inventory_balance($actorId,$companyId,$bookId,$product['id'],null,$data['warehouse_id']);
    }
    $cost=pl_inventory_cost($basis['value_base'],$basis['quantity'],$quantity);
    $quantityDelta=bcsub('0',$quantity,4); $valueDelta=bcsub('0',$cost,4);
    return ['data'=>$data,'basis'=>$basis,'quantity_delta'=>$quantityDelta,'value_delta'=>$valueDelta,
        'movement'=>pl_inventory_movement_plan($actorId,$companyId,$bookId,$data,$quantityDelta,$valueDelta,$basis)];
}

/**
 * Atomic carrying-value transfer between two warehouses in the stock ledger; no GL recognition.
 * Input: product_id, from_warehouse_id, to_warehouse_id, quantity, date, source_reference, reason, idempotency_key.
 * Result: out and in movements, movement_ids, warehouse IDs, quantity, value_base, journal_created=false.
 */
function pl_inventory_transfer(int $actorId, int $companyId, int $bookId, array $input): array
{
    foreach (['from_warehouse_id','to_warehouse_id'] as $field) {
        if (!is_int($input[$field] ?? null) || $input[$field] < 1) { throw new DomainException('Choose valid source and destination warehouses.'); }
    }
    if ($input['from_warehouse_id'] === $input['to_warehouse_id']) { throw new DomainException('A transfer needs two different warehouses.'); }
    if (isset($input['offset_account_id']) || isset($input['amount_base'])) { throw new DomainException('Transfer value is calculated from the source carrying value without an offset account.'); }
    $data = pl_inventory_movement_input(array_replace($input, ['warehouse_id' => $input['from_warehouse_id'], 'source_type' => 'inventory_transfer_out', 'offset_account_id' => null]));
    $quantity = pl_amount(pl_ledger_text($input['quantity'] ?? null, 'Transfer quantity', 21));
    if (bccomp($quantity, '0', 4) <= 0) { throw new DomainException('A transfer needs a positive quantity.'); }
    $to = $input['to_warehouse_id'];
    return pl_inventory_command($actorId, $companyId, $bookId, (string) ($input['idempotency_key'] ?? ''), ['transfer', $data, $to, $quantity],
        function (string $key) use ($actorId, $companyId, $bookId, $data, $to, $quantity): array {
            pl_require_module($actorId, $companyId, $bookId, 'inventory-locations');
            pl_inventory_movement_warehouse($actorId, $companyId, $bookId, $data['warehouse_id']);
            pl_inventory_movement_warehouse($actorId, $companyId, $bookId, $to);
            $balance = pl_inventory_balance($actorId, $companyId, $bookId, $data['product_id'], null, $data['warehouse_id']);
            $cost = pl_inventory_cost($balance['value_base'], $balance['quantity'], $quantity);
            $out = pl_inventory_record($actorId, $companyId, $bookId, $data, 'transfer_out', bcsub('0', $quantity, 4), bcsub('0', $cost, 4), $key);
            $inData = array_replace($data, ['warehouse_id' => $to, 'source_type' => 'inventory_transfer_in']);
            $in = pl_inventory_record($actorId, $companyId, $bookId, $inData, 'transfer_in', $quantity, $cost, $key, $out['movement_id']);
            return ['movement_ids' => [$out['movement_id'], $in['movement_id']], 'out' => $out, 'in' => $in, 'product_id' => $data['product_id'],
                'from_warehouse_id' => $data['warehouse_id'], 'to_warehouse_id' => $to, 'quantity' => $quantity, 'value_base' => $cost, 'journal_created' => false];
        });
}

/** Both return directions consume the original movement's exact unreturned carrying basis. */
function pl_inventory_return(int $actorId, int $companyId, int $bookId, array $input): array
{
    $originalId = $input['original_movement_id'] ?? null;
    if (!is_int($originalId) || $originalId < 1) { throw new DomainException('Choose the original stock movement.'); }
    pl_require_company_access($actorId, $companyId); pl_ledger_book($companyId, $bookId);
    $original = DB::queryFirstRow('SELECT * FROM pl_inventory_movements WHERE id=%i AND company_id=%i AND book_id=%i FOR SHARE', $originalId, $companyId, $bookId);
    if (!$original || !in_array($original['kind'], ['receipt','issue'], true)) { throw new DomainException('Returns must reference an original receipt or sale issue.'); }
    $input['product_id'] = (int) $original['product_id'];
    if (isset($input['warehouse_id'])) {
        if (!is_int($input['warehouse_id']) || $input['warehouse_id'] < 1) { throw new DomainException('Choose a valid warehouse.'); }
        $warehouse = pl_get_inventory_warehouse($actorId, $companyId, $bookId, $input['warehouse_id']);
        if ($original['warehouse_id'] === null ? !$warehouse['is_default'] : (int) $original['warehouse_id'] !== $warehouse['id']) { throw new DomainException('Returns must stay in the original warehouse.'); }
    }
    if ($original['warehouse_id'] !== null) { $input['warehouse_id'] = (int) $original['warehouse_id']; }
    $input['offset_account_id'] ??= $original['offset_account_id'] === null ? null : (int) $original['offset_account_id'];
    $data = pl_inventory_movement_input($input); $quantity = pl_amount(pl_ledger_text($input['quantity'] ?? null, 'Quantity', 21));
    if (bccomp($quantity, '0', 4) <= 0) { throw new DomainException('A return needs positive quantity.'); }
    return pl_inventory_command($actorId, $companyId, $bookId, (string) ($input['idempotency_key'] ?? ''), ['return', $originalId, $data, $quantity], function (string $key) use ($actorId, $companyId, $bookId, $data, $quantity, $original, $originalId): array {
        $remaining = pl_inventory_return_basis($companyId, $bookId, $original);
        $effect=pl_inventory_return_effect($original,$data,$quantity,$remaining);
        return pl_inventory_record($actorId,$companyId,$bookId,$data,$effect['kind'],$effect['quantity_delta'],$effect['value_delta'],$key,$originalId);
    });
}

/** Historical return cost is shared by preview and the return writer. */
function pl_inventory_return_effect(array $original,array $data,string $quantity,array $remaining): array
{
    if ($data['date']<$original['movement_date']) { throw new DomainException('A return cannot precede its source movement.'); }
    $cost=pl_inventory_cost($remaining['value_base'],$remaining['quantity'],$quantity);
    $customer=$original['kind']==='issue';
    return ['kind'=>$customer?'customer_return':'purchase_return','quantity_delta'=>$customer?$quantity:bcsub('0',$quantity,4),'value_delta'=>$customer?$cost:bcsub('0',$cost,4)];
}

/** Explicit credit-return reversals restore the original sale's unreturned quantity and basis. */
function pl_inventory_return_basis(int $companyId, int $bookId, array $original): array
{
    $quantity = '0.0000'; $value = '0.0000';
    $children = DB::query('SELECT id,quantity_delta,value_delta FROM pl_inventory_movements WHERE original_movement_id=%i AND company_id=%i AND book_id=%i FOR SHARE', (int) $original['id'], $companyId, $bookId);
    foreach ($children as $child) {
        $quantity = bcadd($quantity, $child['quantity_delta'], 4); $value = bcadd($value, $child['value_delta'], 4);
        foreach (DB::query('SELECT quantity_delta,value_delta FROM pl_inventory_movements WHERE original_movement_id=%i AND company_id=%i AND book_id=%i FOR SHARE', (int) $child['id'], $companyId, $bookId) as $reversal) {
            $quantity = bcadd($quantity, $reversal['quantity_delta'], 4); $value = bcadd($value, $reversal['value_delta'], 4);
        }
    }
    return ['quantity' => bcsub(ltrim($original['quantity_delta'], '-'), ltrim($quantity, '-'), 4), 'value_base' => bcsub(ltrim($original['value_delta'], '-'), ltrim($value, '-'), 4)];
}

function pl_inventory_value_adjustment(int $actorId, int $companyId, int $bookId, array $input): array
{
    $data = pl_inventory_movement_input($input); $value = pl_inventory_signed(pl_ledger_text($input['amount_base'] ?? null, 'Base amount', 22));
    $expectedValue = pl_amount(pl_ledger_text($input['expected_value_base'] ?? null, 'Reviewed carrying value', 21));
    $expectedQuantity = pl_amount(pl_ledger_text($input['expected_quantity'] ?? null, 'Reviewed quantity', 21));
    if (bccomp($value, '0', 4) === 0) { throw new DomainException('A value adjustment needs a nonzero amount.'); }
    return pl_inventory_command($actorId, $companyId, $bookId, (string) ($input['idempotency_key'] ?? ''), ['value_adjustment', $data, $value, $expectedQuantity, $expectedValue],
        function (string $key) use ($actorId, $companyId, $bookId, $data, $value, $expectedQuantity, $expectedValue): array {
            $data['warehouse_id'] = pl_inventory_movement_warehouse($actorId, $companyId, $bookId, $data['warehouse_id'] ?? null)['id'];
            $balance = pl_inventory_balance($actorId, $companyId, $bookId, $data['product_id'], null, $data['warehouse_id']);
            if ($balance['quantity'] !== $expectedQuantity || $balance['value_base'] !== $expectedValue) { throw new DomainException('Stock quantity or carrying value changed since this adjustment was reviewed. Refresh and review again.'); }
            return pl_inventory_record($actorId, $companyId, $bookId, $data, 'value_adjustment', '0.0000', $value, $key);
        });
}

/** The count effect is calculated once for preview and posting. */
function pl_inventory_count_effect(array $balance, string $count, string $expected, ?string $unitCost): array
{
    if ($balance['quantity'] !== $expected) { throw new DomainException('Stock changed since this count was reviewed. Refresh the expected quantity.'); }
    $change = bcsub($count, $expected, 4);
    if (bccomp($change, '0', 4) === 0) { throw new DomainException('The count already matches stock; no adjustment is needed.'); }
    if (bccomp($change, '0', 4) < 0) { $value = bcsub('0', pl_inventory_cost($balance['value_base'], $balance['quantity'], substr($change, 1)), 4); }
    else {
        if ($unitCost === null || bccomp($unitCost, '0', 4) <= 0) { throw new DomainException('A count increase needs an explicitly reviewed positive unit cost.'); }
        $value = pl_fx_convert($change, $unitCost);
    }
    return ['quantity_delta'=>$change,'value_delta'=>$value];
}

function pl_inventory_adjust_count(int $actorId, int $companyId, int $bookId, array $input): array
{
    $data = pl_inventory_movement_input($input); $count = pl_amount(pl_ledger_text($input['counted_quantity'] ?? null, 'Counted quantity', 21));
    $expected = pl_amount(pl_ledger_text($input['expected_quantity'] ?? null, 'Expected quantity', 21)); $unitCost = isset($input['unit_cost']) ? pl_amount(pl_ledger_text($input['unit_cost'], 'Unit cost', 21)) : null;
    return pl_inventory_command($actorId, $companyId, $bookId, (string) ($input['idempotency_key'] ?? ''), ['count', $data, $count, $expected, $unitCost], function (string $key) use ($actorId, $companyId, $bookId, $data, $count, $expected, $unitCost): array {
        $data['warehouse_id'] = pl_inventory_movement_warehouse($actorId, $companyId, $bookId, $data['warehouse_id'] ?? null)['id'];
        $balance = pl_inventory_balance($actorId, $companyId, $bookId, $data['product_id'], null, $data['warehouse_id']);
        $effect=pl_inventory_count_effect($balance,$count,$expected,$unitCost);
        return pl_inventory_record($actorId, $companyId, $bookId, $data, 'adjustment', $effect['quantity_delta'], $effect['value_delta'], $key);
    });
}

function pl_preview_inventory_count(int $actorId, int $companyId, int $bookId, array $input): array
{
    $data=pl_inventory_movement_input($input);
    $count=pl_amount(pl_ledger_text($input['counted_quantity']??null,'Counted quantity',21));
    $expected=pl_amount(pl_ledger_text($input['expected_quantity']??null,'Expected quantity',21));
    $unitCost=isset($input['unit_cost'])?pl_amount(pl_ledger_text($input['unit_cost'],'Unit cost',21)):null;
    return pl_ledger_transaction(function () use ($actorId,$companyId,$bookId,$data,$count,$expected,$unitCost): array {
        pl_require_company_access($actorId,$companyId,true); pl_ledger_book($companyId,$bookId,true);
        $data['warehouse_id']=pl_inventory_movement_warehouse($actorId,$companyId,$bookId,$data['warehouse_id']??null)['id'];
        $balance=pl_inventory_balance($actorId,$companyId,$bookId,$data['product_id'],null,$data['warehouse_id']);
        $effect=pl_inventory_count_effect($balance,$count,$expected,$unitCost);
        $plan=pl_inventory_movement_plan($actorId,$companyId,$bookId,$data,$effect['quantity_delta'],$effect['value_delta']);
        return ['input'=>$data,'recorded'=>$balance,'counted_quantity'=>$count,'unit_cost'=>$unitCost,'effect'=>$effect,'journal'=>$plan['journal'],'product'=>$plan['product']];
    });
}

/** A reviewed count cannot silently use a changed carrying value or stock balance. */
function pl_confirm_inventory_count(int $actorId, int $companyId, int $bookId, array $input, string $expectedHash): array
{
    return pl_ledger_transaction(function () use ($actorId,$companyId,$bookId,$input,$expectedHash): array {
        pl_require_company_access($actorId,$companyId,true); pl_ledger_book($companyId,$bookId,true);
        $key=pl_request_key(pl_ledger_text($input['idempotency_key']??null,'Count identity',128));
        if (!DB::queryFirstField('SELECT request_key FROM pl_inventory_commands WHERE company_id=%i AND book_id=%i AND request_key=%s',$companyId,$bookId,$key)) {
            $plan=pl_preview_inventory_count($actorId,$companyId,$bookId,$input);
            if (!hash_equals($expectedHash,hash('sha256',json_encode($plan,JSON_THROW_ON_ERROR)))) { throw new DomainException('The count or its stock value changed. Update the preview before confirming.'); }
        }
        return pl_inventory_adjust_count($actorId,$companyId,$bookId,$input);
    });
}

function pl_inventory_history(int $actorId, int $companyId, int $bookId, ?int $productId = null, ?int $warehouseId = null): array
{
    pl_require_company_access($actorId, $companyId); pl_ledger_book($companyId, $bookId);
    if ($productId !== null) { pl_get_inventory_product($actorId, $companyId, $bookId, $productId); }
    $where = ''; $args = [$companyId, $bookId];
    if ($productId !== null) { $where .= ' AND m.product_id=%i'; $args[] = $productId; }
    if ($warehouseId !== null) {
        $warehouse = pl_get_inventory_warehouse($actorId, $companyId, $bookId, $warehouseId);
        $where .= ' AND (m.warehouse_id=%i' . ($warehouse['is_default'] ? ' OR m.warehouse_id IS NULL' : '') . ')'; $args[] = $warehouseId;
    }
    return DB::query('SELECT m.*,p.sku,p.name,COALESCE(m.warehouse_id,d.id) AS warehouse_id,w.code AS warehouse_code,w.name AS warehouse_name FROM pl_inventory_movements m JOIN pl_products p ON p.id=m.product_id'
        . ' LEFT JOIN pl_inventory_warehouses d ON d.company_id=m.company_id AND d.book_id=m.book_id AND d.is_default=1'
        . ' LEFT JOIN pl_inventory_warehouses w ON w.id=COALESCE(m.warehouse_id,d.id) AND w.company_id=m.company_id AND w.book_id=m.book_id'
        . ' WHERE m.company_id=%i AND m.book_id=%i' . $where . ' ORDER BY m.movement_date DESC,m.id DESC LIMIT 500', ...$args);
}

/** Only the all-warehouse report reconciles to the inventory control accounts; a single warehouse reports stock values alone. */
function pl_inventory_valuation(int $actorId, int $companyId, int $bookId, ?string $asOf = null, ?int $warehouseId = null): array
{
    $asOf = pl_ledger_date($asOf ?? gmdate('Y-m-d')); $products = pl_list_inventory_products($actorId, $companyId, $bookId);
    if ($warehouseId !== null) { pl_get_inventory_warehouse($actorId, $companyId, $bookId, $warehouseId); }
    $rows = []; $accounts = []; $total = '0.0000';
    foreach ($products as $product) {
        if ($product['kind'] !== 'stock') { continue; }
        $balance = pl_inventory_balance($actorId, $companyId, $bookId, $product['id'], $asOf, $warehouseId); $rows[] = $product + $balance;
        $total = bcadd($total, $balance['value_base'], 4);
        $accountId = $product['inventory_account_id']; $accounts[$accountId] ??= ['account_id' => $accountId, 'stock_value' => '0.0000'];
        $accounts[$accountId]['stock_value'] = bcadd($accounts[$accountId]['stock_value'], $balance['value_base'], 4);
    }
    foreach ($accounts as &$account) {
        if ($warehouseId !== null) { $account['ledger_value'] = null; $account['difference'] = null; continue; }
        $ledger = '0.0000';
        foreach (DB::query('SELECT l.debit,l.credit FROM pl_journal_lines l JOIN pl_journals j ON j.id=l.journal_id WHERE l.company_id=%i AND l.book_id=%i AND l.account_id=%i AND j.journal_date<=%s FOR SHARE', $companyId, $bookId, $account['account_id'], $asOf) as $line) { $ledger = bcadd($ledger, bcsub($line['debit'], $line['credit'], 4), 4); }
        $account['ledger_value'] = $ledger; $account['difference'] = bcsub($ledger, $account['stock_value'], 4);
    }
    unset($account);
    return ['as_of' => $asOf, 'warehouse_id' => $warehouseId, 'gl_reconciliation_available' => $warehouseId === null, 'products' => $rows, 'total_value_base' => $total, 'accounts' => array_values($accounts)];
}

/** Generic journal reversals may not separate stock quantities from their financial value. */
function pl_inventory_assert_reversal_allowed(int $companyId, int $bookId, int $journalId): void
{
    if (DB::queryFirstField('SELECT m.id FROM pl_inventory_movements m LEFT JOIN pl_journal_lines l ON l.id=m.opening_journal_line_id WHERE m.company_id=%i AND m.book_id=%i AND (m.journal_id=%i OR l.journal_id=%i) LIMIT 1 FOR SHARE', $companyId, $bookId, $journalId, $journalId)) { throw new DomainException('Use the linked inventory return or adjustment service; a journal reversal alone would leave stock unreconciled.'); }
    foreach (DB::query("SELECT id,source_type,quantity_delta,value_delta FROM pl_inventory_movements WHERE company_id=%i AND book_id=%i AND source_journal_id=%i AND source_type IN ('ar_invoice','ar_credit') FOR SHARE", $companyId, $bookId, $journalId) as $movement) {
        $quantity = $movement['quantity_delta']; $value = $movement['value_delta'];
        foreach (DB::query('SELECT quantity_delta,value_delta FROM pl_inventory_movements WHERE company_id=%i AND book_id=%i AND original_movement_id=%i AND source_type=%s FOR SHARE', $companyId, $bookId, (int) $movement['id'], $movement['source_type'] . '_reversal') as $reversal) {
            $quantity = bcadd($quantity, $reversal['quantity_delta'], 4); $value = bcadd($value, $reversal['value_delta'], 4);
        }
        if (bccomp($quantity, '0', 4) !== 0 || bccomp($value, '0', 4) !== 0) { throw new DomainException('Reverse this AR document through its inventory-aware document service so stock and accounting remain together.'); }
    }
}

/** Invoice recognition and physical issue share the caller's outer transaction. */
function pl_inventory_issue_ar_document(int $actorId, int $companyId, int $bookId, array $document, int $journalId, string $requestKey): array
{
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $document, $journalId, $requestKey): array {
    pl_require_company_access($actorId, $companyId, true); pl_ledger_book($companyId, $bookId, true);
    $results = [];
    foreach ($document['lines'] as $index => $line) {
        if (($line['product_id'] ?? null) === null) { continue; }
        $product = pl_get_inventory_product($actorId, $companyId, $bookId, (int) $line['product_id']);
        if ($product['kind'] !== 'stock') { continue; }
        $results[] = pl_inventory_issue($actorId, $companyId, $bookId, ['product_id' => $product['id'], 'quantity' => $line['quantity'], 'date' => $document['document_date'] ?? $document['date'],
            'warehouse_id' => $line['warehouse_id'] ?? $document['warehouse_id'] ?? null,
            'source_type' => 'ar_invoice', 'source_reference' => $journalId . ':' . $index, 'source_document_id' => (int) $document['id'], 'source_journal_id' => $journalId,
            'reason' => 'Stock issued for invoice ' . $document['id'], 'idempotency_key' => 'ar-issue:' . hash('sha256', $requestKey . ':' . $index)]);
    }
    return $results;
    });
}

function pl_inventory_reverse_ar_document(int $actorId, int $companyId, int $bookId, array $document, string $date, string $key, string $reason): array
{
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $document, $date, $key, $reason): array {
        pl_require_company_access($actorId, $companyId, true); pl_ledger_book($companyId, $bookId, true);
        $isCredit = in_array($document['kind'] ?? '', ['customer_credit','credit_note','credit'], true);
        $movements = DB::query('SELECT * FROM pl_inventory_movements WHERE company_id=%i AND book_id=%i AND source_type=%s AND source_document_id=%i AND source_journal_id=%i ORDER BY id FOR SHARE', $companyId, $bookId, $isCredit ? 'ar_credit' : 'ar_invoice', (int) $document['id'], (int) $document['journal_id']);
        $results = [];
        foreach ($movements as $movement) {
            if ($isCredit) {
                $data = pl_inventory_movement_input(['product_id' => (int) $movement['product_id'], 'date' => $date, 'offset_account_id' => (int) $movement['offset_account_id'],
                    'warehouse_id' => $movement['warehouse_id'] === null ? null : (int) $movement['warehouse_id'],
                    'source_type' => 'ar_credit_reversal', 'source_reference' => (string) $movement['id'], 'source_document_id' => (int) $document['id'], 'reason' => $reason]);
                $results[] = pl_inventory_command($actorId, $companyId, $bookId, 'ar-credit-reverse:' . hash('sha256', $key . ':' . $movement['id']), ['credit_stock_reversal', $movement['id'], $data], function (string $request) use ($actorId, $companyId, $bookId, $data, $movement): array {
                    if (DB::queryFirstField('SELECT id FROM pl_inventory_movements WHERE original_movement_id=%i LIMIT 1 FOR SHARE', (int) $movement['id'])) { throw new DomainException('This credit stock return was already reversed.'); }
                    return pl_inventory_record($actorId, $companyId, $bookId, $data, 'adjustment', bcsub('0', $movement['quantity_delta'], 4), bcsub('0', $movement['value_delta'], 4), $request, (int) $movement['id']);
                });
                continue;
            }
            $results[] = pl_inventory_return($actorId, $companyId, $bookId, ['original_movement_id' => (int) $movement['id'], 'quantity' => ltrim($movement['quantity_delta'], '-'),
                'date' => $date, 'source_type' => 'ar_invoice_reversal', 'source_reference' => (string) $movement['id'], 'source_document_id' => (int) $document['id'],
                'reason' => $reason, 'idempotency_key' => 'ar-stock-reverse:' . hash('sha256', $key . ':' . $movement['id'])]);
        }
        return $results;
    });
}

function pl_inventory_credit_ar_document(int $actorId, int $companyId, int $bookId, array $credit, array $original, string $key): array
{
    $hasStock = false;
    foreach ($credit['lines'] as $line) {
        if (($line['product_id'] ?? null) !== null && pl_get_inventory_product($actorId, $companyId, $bookId, (int) $line['product_id'])['kind'] === 'stock') { $hasStock = true; }
    }
    if (!$hasStock) { return []; }
    return pl_inventory_command($actorId, $companyId, $bookId, 'credit-returns:' . hash('sha256', $key), ['credit_returns', $credit['id'], $credit['journal_id'], $credit['document_date'], $credit['lines'], $original['id'], $original['journal_id']],
        fn (): array => pl_inventory_credit_ar_document_locked($actorId, $companyId, $bookId, $credit, $original, $key));
}

/** Allocate a credit across its original issue movements without changing their residuals. */
function pl_inventory_credit_allocations(int $actorId,int $companyId,int $bookId,array $credit,array $original,array $restored = []): array
{
    $allocations=[]; $basis=[];
    foreach ($credit['lines'] as $index=>$line) {
        if (($line['product_id']??null)===null) { continue; }
        $product=pl_get_inventory_product($actorId,$companyId,$bookId,(int)$line['product_id']);
        if ($product['kind']!=='stock') { continue; }
        $take=pl_amount($line['quantity']);
        $sources=DB::query("SELECT * FROM pl_inventory_movements WHERE company_id=%i AND book_id=%i AND product_id=%i AND source_type='ar_invoice' AND source_document_id=%i AND source_journal_id=%i ORDER BY id FOR SHARE",$companyId,$bookId,$product['id'],(int)$original['id'],(int)$original['journal_id']);
        foreach ($sources as $source) {
            if (bccomp($take,'0',4)<=0) { break; }
            $id=(int)$source['id'];
            if (!isset($basis[$id])) {
                $basis[$id]=pl_inventory_return_basis($companyId,$bookId,$source);
                if (isset($restored[$id])) {
                    $basis[$id]['quantity']=bcadd($basis[$id]['quantity'],$restored[$id]['quantity'],4);
                    $basis[$id]['value_base']=bcadd($basis[$id]['value_base'],$restored[$id]['value_base'],4);
                }
            }
            $remaining=$basis[$id];
            if (bccomp($remaining['quantity'],'0',4)<=0) { continue; }
            $part=bccomp($take,$remaining['quantity'],4)>0?$remaining['quantity']:$take;
            $effect=pl_inventory_return_effect($source,['date'=>$credit['document_date']??$credit['date']],$part,$remaining);
            $allocations[]=['index'=>$index,'source'=>$source,'basis'=>$remaining,'quantity'=>$part,'effect'=>$effect];
            $basis[$id]=['quantity'=>bcsub($remaining['quantity'],$part,4),'value_base'=>bcsub($remaining['value_base'],$effect['value_delta'],4)];
            $take=bcsub($take,$part,4);
        }
        if (bccomp($take,'0',4)!==0) { throw new DomainException('The credit return exceeds the original invoice quantity still available to return.'); }
    }
    return $allocations;
}

function pl_inventory_credit_ar_document_locked(int $actorId, int $companyId, int $bookId, array $credit, array $original, string $key): array
{
    return pl_ledger_transaction(function () use ($actorId,$companyId,$bookId,$credit,$original,$key): array {
        pl_require_company_access($actorId,$companyId,true); pl_ledger_book($companyId,$bookId,true);
        $results=[];
        foreach (pl_inventory_credit_allocations($actorId,$companyId,$bookId,$credit,$original) as $allocation) {
            $index=$allocation['index']; $source=$allocation['source'];
            $results[]=pl_inventory_return($actorId,$companyId,$bookId,['original_movement_id'=>(int)$source['id'],'quantity'=>$allocation['quantity'],
                'date'=>$credit['document_date']??$credit['date'],'source_type'=>'ar_credit','source_reference'=>$credit['journal_id'].':'.$index.':'.$source['id'],'source_document_id'=>(int)$credit['id'],
                'source_journal_id'=>isset($credit['journal_id'])?(int)$credit['journal_id']:null,'reason'=>'Stock returned on credit '.$credit['id'],
                'idempotency_key'=>'ar-credit-stock:'.hash('sha256',$key.':'.$index.':'.$source['id'])]);
        }
        return $results;
    });
}

/** Simulate correction reversals in memory; never insert movements or consume identities. */
function pl_inventory_ar_correction_basis(int $actorId,int $companyId,int $bookId,array $document,string $date): array
{
    $balances=[]; $restored=[]; $plans=[];
    $credit=$document['kind']==='customer_credit';
    $rows=DB::query('SELECT * FROM pl_inventory_movements WHERE company_id=%i AND book_id=%i AND source_type=%s AND source_document_id=%i AND source_journal_id=%i ORDER BY id FOR SHARE',
        $companyId,$bookId,$credit?'ar_credit':'ar_invoice',$document['id'],$document['journal_id']);
    foreach ($rows as $row) {
        $id=(int)$row['product_id'];
        $warehouseId=$row['warehouse_id']===null?pl_inventory_default_warehouse($actorId,$companyId,$bookId)['id']:(int)$row['warehouse_id'];
        $key=$id.':'.$warehouseId;
        $balances[$key]??=pl_inventory_balance($actorId,$companyId,$bookId,$id,null,$warehouseId);
        if ($credit) {
            if (DB::queryFirstField('SELECT id FROM pl_inventory_movements WHERE original_movement_id=%i LIMIT 1 FOR SHARE',$row['id'])) { throw new DomainException('This credit stock return was already reversed.'); }
            $originalId=(int)$row['original_movement_id'];
            $restored[$originalId]??=['quantity'=>'0.0000','value_base'=>'0.0000'];
            $restored[$originalId]['quantity']=bcadd($restored[$originalId]['quantity'],$row['quantity_delta'],4);
            $restored[$originalId]['value_base']=bcadd($restored[$originalId]['value_base'],$row['value_delta'],4);
        } else {
            $basis=pl_inventory_return_basis($companyId,$bookId,$row);
            pl_inventory_return_effect($row,['date'=>$date],ltrim($row['quantity_delta'],'-'),$basis);
        }
        $quantity=bcsub('0',$row['quantity_delta'],4); $value=bcsub('0',$row['value_delta'],4);
        $data=pl_inventory_movement_input(['product_id'=>$id,'date'=>$date,'offset_account_id'=>(int)$row['offset_account_id'],'warehouse_id'=>$warehouseId,
            'source_type'=>$credit?'ar_credit_reversal':'ar_invoice_reversal','source_reference'=>'correction-preview:'.$row['id'],'reason'=>'Correction stock reversal preview']);
        $movement=pl_inventory_movement_plan($actorId,$companyId,$bookId,$data,$quantity,$value,$balances[$key]);
        $plans[]=['product_name'=>$movement['product']['name'],'quantity_delta'=>$quantity,'value_delta'=>$value,'movement'=>$movement];
        $balances[$key]['quantity']=$movement['quantity_after']; $balances[$key]['value_base']=$movement['value_after']; $balances[$key]['latest_date']=$date;
    }
    return ['balances'=>$balances,'restored'=>$restored,'plans'=>$plans];
}

/** Read-only inventory effects for the financial document editor. */
function pl_inventory_ar_preview(int $actorId,int $companyId,int $bookId,array $document,?array $original,array $balances = [],array $restored = []): array
{
    $plans=[];
    if ($document['kind']==='invoice') {
        foreach ($document['lines'] as $index=>$line) {
            if (($line['product_id']??null)===null) { continue; }
            $product=pl_get_inventory_product($actorId,$companyId,$bookId,(int)$line['product_id']);
            if ($product['kind']!=='stock') { continue; }
            $id=$product['id'];
            $warehouseId=pl_inventory_movement_warehouse($actorId,$companyId,$bookId,$line['warehouse_id']??$document['warehouse_id']??null)['id'];
            $key=$id.':'.$warehouseId; $balances[$key]??=pl_inventory_balance($actorId,$companyId,$bookId,$id,null,$warehouseId);
            $data=pl_inventory_movement_input(['product_id'=>$id,'date'=>$document['document_date'],'warehouse_id'=>$warehouseId,'source_type'=>'ar_invoice','source_reference'=>'editor-preview:'.$index,'reason'=>'Invoice stock issue preview']);
            $plan=pl_inventory_issue_plan($actorId,$companyId,$bookId,$data,$line['quantity'],$balances[$key]);
            $plans[]=$plan+['line_number'=>$index+1,'product_name'=>$product['name']];
            $balances[$key]['quantity']=bcadd($balances[$key]['quantity'],$plan['quantity_delta'],4);
            $balances[$key]['value_base']=bcadd($balances[$key]['value_base'],$plan['value_delta'],4);
        }
    } elseif ($document['kind']==='customer_credit' && $original!==null) {
        foreach (pl_inventory_credit_allocations($actorId,$companyId,$bookId,$document,$original,$restored) as $allocation) {
            $source=$allocation['source']; $effect=$allocation['effect'];
            $productId=(int)$source['product_id'];
            $warehouseId=$source['warehouse_id']===null?pl_inventory_default_warehouse($actorId,$companyId,$bookId)['id']:(int)$source['warehouse_id'];
            $data=pl_inventory_movement_input(['product_id'=>$productId,'date'=>$document['document_date'],'offset_account_id'=>(int)$source['offset_account_id'],'warehouse_id'=>$warehouseId,
                'source_type'=>'ar_credit','source_reference'=>'editor-preview:'.$allocation['index'].':'.$source['id'],'reason'=>'Credit stock return preview']);
            $key=$productId.':'.$warehouseId;
            $balances[$key]??=pl_inventory_balance($actorId,$companyId,$bookId,$productId,null,$warehouseId);
            $movement=pl_inventory_movement_plan($actorId,$companyId,$bookId,$data,$effect['quantity_delta'],$effect['value_delta'],$balances[$key]);
            $balances[$key]['quantity']=$movement['quantity_after'];
            $balances[$key]['value_base']=$movement['value_after'];
            $plans[]=['line_number'=>$allocation['index']+1,'product_name'=>$movement['product']['name'],'basis'=>$allocation['basis'],'quantity_delta'=>$effect['quantity_delta'],'value_delta'=>$effect['value_delta'],'movement'=>$movement];
        }
    }
    return $plans;
}

/** Builds explicit allocations to an existing opening journal. It never creates a new GL opening. */
function pl_inventory_opening_payload(int $actorId, int $companyId, int $bookId, array $input): array
{
    pl_require_company_access($actorId, $companyId, true); pl_ledger_book($companyId, $bookId, true);
    if (DB::queryFirstField('SELECT id FROM pl_inventory_movements WHERE company_id=%i AND book_id=%i LIMIT 1 FOR SHARE', $companyId, $bookId)) { throw new DomainException('Opening stock conversion must precede every inventory movement in this book.'); }
    $date = pl_ledger_date(pl_ledger_text($input['date'] ?? null, 'Opening stock date', 10));
    $reason = pl_ledger_text($input['reason'] ?? null, 'Opening stock evidence', 500);
    $lines = $input['lines'] ?? null;
    if (!is_array($lines) || !array_is_list($lines) || $lines === [] || count($lines) > 500) { throw new DomainException('Review between one and 500 opening stock products.'); }
    $products = []; $totals = []; $accountLines = [];
    foreach ($lines as $line) {
        if (!is_array($line) || !is_int($line['product_id'] ?? null)) { throw new DomainException('Choose each opening product explicitly.'); }
        $product = pl_get_inventory_product($actorId, $companyId, $bookId, $line['product_id']);
        if (isset($line['warehouse_id']) && (!is_int($line['warehouse_id']) || $line['warehouse_id'] < 1)) { throw new DomainException('Choose a valid warehouse for each opening line.'); }
        $warehouseId = pl_inventory_movement_warehouse($actorId, $companyId, $bookId, $line['warehouse_id'] ?? null)['id'];
        $key = $product['id'] . ':' . $warehouseId;
        if ($product['kind'] !== 'stock' || !$product['is_active'] || isset($products[$key])) { throw new DomainException('Choose each active stock product only once per warehouse.'); }
        $quantity = pl_amount(pl_ledger_text($line['quantity'] ?? null, 'Opening quantity', 21)); $value = pl_amount(pl_ledger_text($line['amount_base'] ?? null, 'Opening stock value', 21));
        if (bccomp($quantity, '0', 4) <= 0 || bccomp($value, '0', 4) <= 0) { throw new DomainException('Opening quantity and base value must both be positive.'); }
        $account = $product['inventory_account_id']; $totals[$account] = bcadd($totals[$account] ?? '0.0000', $value, 4);
        $products[$key] = ['product_id' => $product['id'], 'warehouse_id' => $warehouseId, 'quantity' => $quantity, 'amount_base' => $value, 'inventory_account_id' => $account];
    }
    foreach ($totals as $account => $total) {
        $ledger = DB::query('SELECT l.id,l.debit,l.credit,j.source_type,j.journal_date,j.reversal_of_id FROM pl_journal_lines l JOIN pl_journals j ON j.id=l.journal_id WHERE l.company_id=%i AND l.book_id=%i AND l.account_id=%i ORDER BY l.id FOR SHARE', $companyId, $bookId, $account);
        if (count($ledger) !== 1 || $ledger[0]['source_type'] !== 'opening_balance' || $ledger[0]['journal_date'] !== $date || bccomp($ledger[0]['credit'], '0', 4) !== 0 || $ledger[0]['debit'] !== $total) { throw new DomainException('Opening stock must exactly equal one unreversed existing opening journal line per inventory account, with no later postings.'); }
        $accountLines[$account] = (int) $ledger[0]['id'];
    }
    foreach (DB::query("SELECT inventory_account_id FROM pl_products WHERE company_id=%i AND book_id=%i AND kind='stock' FOR SHARE", $companyId, $bookId) as $mapped) {
        $account = (int) $mapped['inventory_account_id'];
        if (isset($totals[$account])) { continue; }
        $value = '0.0000';
        foreach (DB::query('SELECT debit,credit FROM pl_journal_lines WHERE company_id=%i AND book_id=%i AND account_id=%i FOR SHARE', $companyId, $bookId, $account) as $line) { $value = bcadd($value, bcsub($line['debit'], $line['credit'], 4), 4); }
        if (bccomp($value, '0', 4) !== 0) { throw new DomainException('Include every mapped inventory account with an opening balance in the same reviewed stock conversion.'); }
    }
    foreach ($products as &$product) { $product['opening_journal_line_id'] = $accountLines[$product['inventory_account_id']]; }
    unset($product); ksort($products); ksort($totals);
    return ['date' => $date, 'reason' => $reason, 'lines' => array_values($products), 'account_totals' => $totals];
}

function pl_preview_inventory_opening(int $actorId, int $companyId, int $bookId, array $input): array
{
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $input): array {
        $member = pl_require_company_access($actorId, $companyId, true);
        if ($member['role'] !== 'owner') { throw new DomainException('Only the company owner can review opening stock conversion.'); }
        pl_require_module($actorId, $companyId, $bookId, 'inventory');
        $payload = pl_inventory_opening_payload($actorId, $companyId, $bookId, $input);
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        DB::insert('pl_inventory_opening_previews', ['company_id' => $companyId, 'book_id' => $bookId, 'payload_json' => json_encode($payload, JSON_THROW_ON_ERROR), 'payload_hash' => $hash, 'created_by' => $actorId]);
        return ['id' => (int) DB::insertId(), 'payload' => $payload, 'payload_hash' => $hash];
    });
}

function pl_confirm_inventory_opening(int $actorId, int $companyId, int $bookId, int $previewId, string $expectedHash, bool $confirmed, string $key): array
{
    if (!$confirmed) { throw new DomainException('Confirm the reviewed product quantities and opening journal allocations.'); }
    return pl_inventory_command($actorId, $companyId, $bookId, $key, ['opening', $previewId, $expectedHash, $confirmed], function () use ($actorId, $companyId, $bookId, $previewId, $expectedHash): array {
        $member = pl_require_company_access($actorId, $companyId, true);
        if ($member['role'] !== 'owner') { throw new DomainException('Only the company owner can confirm opening stock conversion.'); }
        $preview = DB::queryFirstRow('SELECT * FROM pl_inventory_opening_previews WHERE id=%i AND company_id=%i AND book_id=%i FOR SHARE', $previewId, $companyId, $bookId);
        if (!$preview || !hash_equals($preview['payload_hash'], $expectedHash)) { throw new DomainException('The opening stock preview identity changed. Review it again.'); }
        $payload = json_decode($preview['payload_json'], true, 64, JSON_THROW_ON_ERROR);
        $current = pl_inventory_opening_payload($actorId, $companyId, $bookId, $payload);
        if (!hash_equals($expectedHash, hash('sha256', json_encode($current, JSON_THROW_ON_ERROR)))) { throw new DomainException('Opening stock evidence changed. Create a new preview.'); }
        $ids = [];
        foreach ($payload['lines'] as $line) {
            DB::insert('pl_inventory_movements', ['company_id' => $companyId, 'book_id' => $bookId, 'product_id' => $line['product_id'], 'warehouse_id' => $line['warehouse_id'] ?? null, 'movement_date' => $payload['date'], 'kind' => 'opening',
                'quantity_delta' => $line['quantity'], 'value_delta' => $line['amount_base'], 'inventory_account_id' => $line['inventory_account_id'], 'opening_journal_line_id' => $line['opening_journal_line_id'],
                'source_type' => 'opening_conversion', 'source_reference' => $previewId . ':' . $line['product_id'] . ':' . ($line['warehouse_id'] ?? 0), 'reason' => $payload['reason'], 'created_by' => $actorId]);
            $ids[] = (int) DB::insertId();
        }
        return ['preview_id' => $previewId, 'movement_ids' => $ids, 'journal_created' => false];
    });
}
