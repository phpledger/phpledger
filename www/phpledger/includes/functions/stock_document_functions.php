<?php
declare(strict_types=1);

/*
 * Numbered stock documents over the existing stock movements (1.2 M4).
 *
 * Owner decisions B33 (the stock-document family is real: issue, re-issue, return, gate
 * pass), B34 (a multi-warehouse, multi-van distribution business), B35 (reports are added,
 * never removed) and B36 (warehouse reads over the existing API/MCP contract). The taken
 * research decisions are in docs/design/1.2-2026-09/distribution-research/DECISIONS.md:
 * per-kind numbering, gate passes post nothing and move nothing, sellable returns re-enter
 * van stock, and a settlement needs an approval distinct from ordinary posting rights.
 *
 * Nothing here is a new accounting primitive. A moving stock document composes
 * pl_inventory_transfer() calls — carrying value follows the goods, no journal is written,
 * because a van and a warehouse are both the company's own stock. The document adds the
 * number, the print and the paperwork the warehouse actually needs.
 */

/**
 * The document family. `series` is the pl_document_series_types() key that numbers this
 * kind (research decision 1: a separate series per kind, prefix settable in Admin).
 *
 * @return array<string, array{label: string, series: string, moves: bool, from: string, to: string|null}>
 */
function pl_stock_document_kinds(): array
{
    return [
        'stock_issue' => ['label' => 'Stock issue', 'series' => 'stock_issue', 'moves' => true, 'from' => 'fixed', 'to' => 'mobile'],
        'stock_reissue' => ['label' => 'Stock re-issue', 'series' => 'stock_reissue', 'moves' => true, 'from' => 'fixed', 'to' => 'mobile'],
        'stock_return' => ['label' => 'Stock return from van', 'series' => 'stock_return', 'moves' => true, 'from' => 'mobile', 'to' => 'fixed'],
        'gate_pass' => ['label' => 'Gate pass', 'series' => 'gate_pass', 'moves' => false, 'from' => 'any', 'to' => null],
    ];
}

/**
 * Quantity times a unit price, at the ledger's four-decimal scale and half-up rounding,
 * for a quantity that may legitimately be negative in a report row.
 */
function pl_stock_extend(string $quantity, string $price): string
{
    $negative = str_starts_with($quantity, '-');
    $value = pl_amount(bcadd(bcmul(pl_amount(ltrim($quantity, '-')), pl_amount($price), 16), '0.00005', 4));
    return $negative ? bcsub('0', $value, 4) : $value;
}

/** Validated document lines: one positive quantity per product, at most 100 of them. */
function pl_stock_document_lines_input(mixed $lines): array
{
    if (!is_array($lines) || !array_is_list($lines) || $lines === [] || count($lines) > 100) {
        throw new DomainException('Record between one and 100 stock document lines.');
    }
    $result = []; $seen = [];
    foreach ($lines as $line) {
        if (!is_array($line) || !is_int($line['product_id'] ?? null) || $line['product_id'] < 1) {
            throw new DomainException('Choose a product on every stock document line.');
        }
        $quantity = pl_amount(pl_ledger_text($line['quantity'] ?? null, 'Line quantity', 21));
        if (bccomp($quantity, '0', 4) <= 0) { throw new DomainException('Every stock document line needs a positive quantity.'); }
        if (isset($seen[$line['product_id']])) { throw new DomainException('List each product once; add its whole quantity on one line.'); }
        $seen[$line['product_id']] = true;
        $result[] = ['product_id' => $line['product_id'], 'quantity' => $quantity];
    }
    return $result;
}

/** A warehouse of the kind this document side requires. `any` accepts both. */
function pl_stock_document_side(array $warehouse, string $expected, string $role): array
{
    if ($expected !== 'any' && $warehouse['kind'] !== $expected) {
        throw new DomainException($expected === 'mobile'
            ? 'The ' . $role . ' of this document must be a van. Add the van as a mobile stock location first.'
            : 'The ' . $role . ' of this document must be a warehouse, not a van.');
    }
    return $warehouse;
}

/**
 * Record one moving stock document: a stock issue to a van, a same-day re-issue, or a
 * return of unsold stock to the warehouse. Each line becomes one carrying-value transfer
 * pair through the existing single stock funnel; the document is then numbered from its
 * own series and becomes immutable.
 *
 * Input: kind, date, from_warehouse_id, to_warehouse_id, original_document_id (re-issue
 * only), reference, reason, lines [{product_id, quantity}], idempotency_key.
 */
function pl_post_stock_document(int $actorId, int $companyId, int $bookId, array $input): array
{
    $kind = $input['kind'] ?? null;
    $kinds = pl_stock_document_kinds();
    if (!is_string($kind) || !isset($kinds[$kind]) || !$kinds[$kind]['moves']) {
        throw new DomainException('Choose a stock issue, a re-issue or a return from a van.');
    }
    $definition = $kinds[$kind];
    foreach (['from_warehouse_id', 'to_warehouse_id'] as $field) {
        if (!is_int($input[$field] ?? null) || $input[$field] < 1) { throw new DomainException('Choose the source and destination stock locations.'); }
    }
    if ($input['from_warehouse_id'] === $input['to_warehouse_id']) { throw new DomainException('A stock document needs two different stock locations.'); }
    $original = $input['original_document_id'] ?? null;
    if ($original !== null && (!is_int($original) || $original < 1)) { throw new DomainException('Choose the original stock issue this re-issue follows.'); }
    if ($kind === 'stock_reissue' && $original === null) { throw new DomainException('A re-issue names the stock issue it tops up.'); }
    if ($kind !== 'stock_reissue' && $original !== null) { throw new DomainException('Only a re-issue references an earlier stock issue.'); }
    $data = [
        'document_kind' => $kind,
        'document_date' => pl_ledger_date(pl_ledger_text($input['date'] ?? null, 'Document date', 10)),
        'from_warehouse_id' => $input['from_warehouse_id'], 'to_warehouse_id' => $input['to_warehouse_id'],
        'original_document_id' => $original,
        'reference' => pl_ledger_text($input['reference'] ?? '', 'Document reference', 120, false),
        'reason' => pl_ledger_text($input['reason'] ?? null, 'Reason', 500),
    ];
    $lines = pl_stock_document_lines_input($input['lines'] ?? null);
    return pl_inventory_command($actorId, $companyId, $bookId, (string) ($input['idempotency_key'] ?? ''), ['stock_document', $data, $lines],
        function (string $key) use ($actorId, $companyId, $bookId, $data, $lines, $definition): array {
            pl_require_module($actorId, $companyId, $bookId, 'inventory-locations');
            $from = pl_stock_document_side(pl_inventory_movement_warehouse($actorId, $companyId, $bookId, $data['from_warehouse_id']), $definition['from'], 'source');
            $to = pl_stock_document_side(pl_inventory_movement_warehouse($actorId, $companyId, $bookId, $data['to_warehouse_id']), (string) $definition['to'], 'destination');
            if ($data['original_document_id'] !== null) {
                $earlier = pl_get_stock_document($actorId, $companyId, $bookId, $data['original_document_id']);
                if (!in_array($earlier['document_kind'], ['stock_issue', 'stock_reissue'], true) || $earlier['to_warehouse_id'] !== $to['id']) {
                    throw new DomainException('A re-issue tops up an earlier stock issue to the same van.');
                }
                if ($earlier['document_date'] > $data['document_date']) { throw new DomainException('A re-issue cannot precede the stock issue it tops up.'); }
            }
            DB::insert('pl_stock_documents', $data + ['company_id' => $companyId, 'book_id' => $bookId, 'document_number' => null, 'request_key' => $key, 'created_by' => $actorId]);
            $documentId = (int) DB::insertId();
            foreach ($lines as $index => $line) {
                $transfer = pl_inventory_transfer($actorId, $companyId, $bookId, [
                    'product_id' => $line['product_id'], 'from_warehouse_id' => $from['id'], 'to_warehouse_id' => $to['id'],
                    'quantity' => $line['quantity'], 'date' => $data['document_date'],
                    'source_reference' => 'stock-document:' . $documentId . ':' . $index,
                    'reason' => $data['reason'], 'idempotency_key' => 'stock-document:' . hash('sha256', $key . ':' . $index),
                ]);
                DB::insert('pl_stock_document_lines', ['document_id' => $documentId, 'company_id' => $companyId, 'book_id' => $bookId,
                    'line_number' => $index + 1, 'product_id' => $line['product_id'], 'quantity' => $line['quantity'], 'value_base' => $transfer['value_base'],
                    'out_movement_id' => $transfer['out']['movement_id'], 'in_movement_id' => $transfer['in']['movement_id']]);
            }
            $number = pl_document_series_allocate($actorId, $companyId, $bookId, $definition['series'], $documentId, $data['document_date']);
            DB::update('pl_stock_documents', ['document_number' => $number], 'id=%i AND company_id=%i AND book_id=%i AND document_number IS NULL', $documentId, $companyId, $bookId);
            $result = pl_get_stock_document($actorId, $companyId, $bookId, $documentId);
            pl_core_audit($actorId, $companyId, $bookId, 'stock_document', $documentId, 'created', $data['reason'], null, $result);
            return $result;
        });
}

/**
 * A gate pass is a numbered permission record. It creates no line, no movement and no
 * journal (research decision 2); it names the stock document that actually moved the
 * goods so the gate can verify the load without seeing the accounting record.
 *
 * Input: date, warehouse_id, direction (out|in), is_returnable, covers_document_id,
 * party_name, vehicle_reference, driver_name, purpose, expected_return_date, reference,
 * reason, idempotency_key.
 */
function pl_issue_gate_pass(int $actorId, int $companyId, int $bookId, array $input): array
{
    $direction = $input['direction'] ?? null;
    if (!in_array($direction, ['out', 'in'], true)) { throw new DomainException('A gate pass covers goods leaving or goods entering.'); }
    if (!is_int($input['warehouse_id'] ?? null) || $input['warehouse_id'] < 1) { throw new DomainException('Choose the stock location whose gate this pass crosses.'); }
    if (!is_int($input['covers_document_id'] ?? null) || $input['covers_document_id'] < 1) {
        throw new DomainException('A gate pass references the stock document that moves the goods. A standalone pass is not supported in 1.2.');
    }
    $returnable = $input['is_returnable'] ?? true;
    if (!is_bool($returnable)) { throw new DomainException('Choose whether the goods are expected back.'); }
    $expected = $input['expected_return_date'] ?? null;
    if ($expected !== null && $expected !== '') {
        if (!$returnable) { throw new DomainException('A non-returnable gate pass has no expected return date.'); }
        $expected = pl_ledger_date(pl_ledger_text($expected, 'Expected return date', 10));
    } else {
        $expected = null;
    }
    if ($returnable && $expected === null) { throw new DomainException('A returnable gate pass states when the goods are expected back.'); }
    $data = [
        'document_kind' => 'gate_pass',
        'document_date' => pl_ledger_date(pl_ledger_text($input['date'] ?? null, 'Gate pass date', 10)),
        'from_warehouse_id' => $input['warehouse_id'], 'to_warehouse_id' => null, 'original_document_id' => null,
        'reference' => pl_ledger_text($input['reference'] ?? '', 'Gate pass reference', 120, false),
        'reason' => pl_ledger_text($input['reason'] ?? null, 'Reason', 500),
    ];
    $pass = [
        'direction' => $direction, 'is_returnable' => $returnable, 'covers_document_id' => $input['covers_document_id'],
        'party_name' => pl_ledger_text($input['party_name'] ?? '', 'Party', 160, false),
        'vehicle_reference' => pl_ledger_text($input['vehicle_reference'] ?? '', 'Vehicle', 60, false),
        'driver_name' => pl_ledger_text($input['driver_name'] ?? '', 'Driver or salesman', 160, false),
        'purpose' => pl_ledger_text($input['purpose'] ?? null, 'Purpose', 500),
        'expected_return_date' => $expected,
    ];
    return pl_inventory_command($actorId, $companyId, $bookId, (string) ($input['idempotency_key'] ?? ''), ['gate_pass', $data, $pass],
        function (string $key) use ($actorId, $companyId, $bookId, $data, $pass): array {
            pl_require_module($actorId, $companyId, $bookId, 'inventory-locations');
            $gate = pl_inventory_movement_warehouse($actorId, $companyId, $bookId, $data['from_warehouse_id']);
            $covered = pl_get_stock_document($actorId, $companyId, $bookId, (int) $pass['covers_document_id']);
            if ($covered['document_kind'] === 'gate_pass') { throw new DomainException('A gate pass references a moving stock document, not another gate pass.'); }
            if (!in_array($gate['id'], [$covered['from_warehouse_id'], $covered['to_warehouse_id']], true)) {
                throw new DomainException('A gate pass is raised at one of the locations its stock document names.');
            }
            DB::insert('pl_stock_documents', $data + ['company_id' => $companyId, 'book_id' => $bookId, 'document_number' => null, 'request_key' => $key, 'created_by' => $actorId]);
            $documentId = (int) DB::insertId();
            DB::insert('pl_gate_passes', ['document_id' => $documentId, 'company_id' => $companyId, 'book_id' => $bookId, 'authorised_by' => $actorId]
                + array_replace($pass, ['is_returnable' => $pass['is_returnable'] ? 1 : 0]));
            $number = pl_document_series_allocate($actorId, $companyId, $bookId, 'gate_pass', $documentId, $data['document_date']);
            DB::update('pl_stock_documents', ['document_number' => $number], 'id=%i AND company_id=%i AND book_id=%i AND document_number IS NULL', $documentId, $companyId, $bookId);
            $result = pl_get_stock_document($actorId, $companyId, $bookId, $documentId);
            pl_core_audit($actorId, $companyId, $bookId, 'gate_pass', $documentId, 'created', $data['reason'], null, $result);
            return $result;
        });
}

/** The security desk stamps a pass once, on the way out and on the way back. */
function pl_stamp_gate_pass(int $actorId, int $companyId, int $bookId, int $documentId, string $stamp): array
{
    if (!in_array($stamp, ['out', 'in'], true)) { throw new DomainException('A gate pass is stamped out or in.'); }
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $documentId, $stamp): array {
        pl_require_company_access($actorId, $companyId, true);
        pl_ledger_book($companyId, $bookId, true);
        pl_require_module($actorId, $companyId, $bookId, 'inventory-locations');
        $document = pl_get_stock_document($actorId, $companyId, $bookId, $documentId);
        if ($document['gate_pass'] === null) { throw new DomainException('Only a gate pass carries a security stamp.'); }
        if ($stamp === 'out' && $document['gate_pass']['direction'] !== 'out') { throw new DomainException('Only an outward gate pass is stamped out of the gate.'); }
        if ($document['gate_pass'][$stamp === 'out' ? 'security_out_at' : 'security_in_at'] !== null) { throw new DomainException('This gate pass is already stamped.'); }
        DB::update('pl_gate_passes', [($stamp === 'out' ? 'security_out_at' : 'security_in_at') => gmdate('Y-m-d H:i:s')], 'document_id=%i AND company_id=%i AND book_id=%i', $documentId, $companyId, $bookId);
        $result = pl_get_stock_document($actorId, $companyId, $bookId, $documentId);
        pl_core_audit($actorId, $companyId, $bookId, 'gate_pass', $documentId, 'updated', 'Security stamped the pass ' . $stamp, $document, $result);
        return $result;
    });
}

/**
 * One stock document with its lines and locations, without following its gate-pass links.
 * The full read below adds those; keeping the shallow form separate stops a gate pass and
 * the document it covers from reading each other forever.
 */
function pl_stock_document_summary(int $actorId, int $companyId, int $bookId, int $id): array
{
    pl_require_company_access($actorId, $companyId); pl_ledger_book($companyId, $bookId);
    $row = DB::queryFirstRow('SELECT * FROM pl_stock_documents WHERE id=%i AND company_id=%i AND book_id=%i FOR SHARE', $id, $companyId, $bookId);
    if (!$row) { throw new DomainException('This stock document is not available in the selected company and book.'); }
    foreach (['id','company_id','book_id','created_by'] as $field) { $row[$field] = (int) $row[$field]; }
    foreach (['from_warehouse_id','to_warehouse_id','original_document_id'] as $field) { $row[$field] = $row[$field] === null ? null : (int) $row[$field]; }
    $row['document_number'] = $row['document_number'] === null ? null : (string) $row['document_number'];
    $kinds = pl_stock_document_kinds();
    $row['label'] = $kinds[$row['document_kind']]['label'];
    $row['moves_stock'] = $kinds[$row['document_kind']]['moves'];
    $row['from_warehouse'] = $row['from_warehouse_id'] === null ? null : pl_get_inventory_warehouse($actorId, $companyId, $bookId, $row['from_warehouse_id']);
    $row['to_warehouse'] = $row['to_warehouse_id'] === null ? null : pl_get_inventory_warehouse($actorId, $companyId, $bookId, $row['to_warehouse_id']);
    $row['lines'] = []; $row['total_quantity'] = '0.0000'; $row['total_value_base'] = '0.0000'; $row['total_sale_value'] = '0.0000';
    foreach (DB::query('SELECT l.*,p.sku,p.name AS product_name,p.base_unit,p.selling_price FROM pl_stock_document_lines l JOIN pl_products p ON p.id=l.product_id WHERE l.document_id=%i AND l.company_id=%i AND l.book_id=%i ORDER BY l.line_number', $id, $companyId, $bookId) as $line) {
        $line['id'] = (int) $line['id']; $line['line_number'] = (int) $line['line_number']; $line['product_id'] = (int) $line['product_id'];
        $line['out_movement_id'] = (int) $line['out_movement_id']; $line['in_movement_id'] = (int) $line['in_movement_id'];
        $line['quantity'] = pl_amount((string) $line['quantity']); $line['value_base'] = pl_amount((string) $line['value_base']);
        $line['sale_value'] = pl_stock_extend($line['quantity'], (string) $line['selling_price']);
        $row['total_quantity'] = bcadd($row['total_quantity'], $line['quantity'], 4);
        $row['total_value_base'] = bcadd($row['total_value_base'], $line['value_base'], 4);
        $row['total_sale_value'] = bcadd($row['total_sale_value'], $line['sale_value'], 4);
        $row['lines'][] = $line;
    }
    // 1.2 M7 (owner decision B58): a stock document carries carrying value, which is cost. It is
    // withheld — from this read, not only from the screen — unless the reader holds `cost.view`
    // AND Admin > Cost visibility has this report showing cost. `sale_value` is the selling price,
    // not cost, and is unaffected. Quantities, movements and numbers are never withheld: they are
    // what the document IS, and a storeman has to be able to read the document he is handed.
    $row['cost_visible'] = pl_stock_cost_visible($actorId, $companyId, $bookId, 'stock-documents');
    if (!$row['cost_visible']) {
        foreach ($row['lines'] as &$withheld) { $withheld['value_base'] = null; }
        unset($withheld);
        $row['total_value_base'] = null;
    }
    $pass = DB::queryFirstRow('SELECT * FROM pl_gate_passes WHERE document_id=%i AND company_id=%i AND book_id=%i FOR SHARE', $id, $companyId, $bookId);
    $row['gate_pass'] = null;
    if ($pass) {
        foreach (['id','document_id','company_id','book_id','authorised_by'] as $field) { $pass[$field] = (int) $pass[$field]; }
        $pass['covers_document_id'] = $pass['covers_document_id'] === null ? null : (int) $pass['covers_document_id'];
        $pass['is_returnable'] = (bool) $pass['is_returnable'];
        $row['gate_pass'] = $pass;
    }
    return $row;
}

/** One stock document, the document it covers and the passes raised against it. */
function pl_get_stock_document(int $actorId, int $companyId, int $bookId, int $id): array
{
    $row = pl_stock_document_summary($actorId, $companyId, $bookId, $id);
    $row['covers'] = $row['gate_pass'] === null || $row['gate_pass']['covers_document_id'] === null
        ? null : pl_stock_document_summary($actorId, $companyId, $bookId, $row['gate_pass']['covers_document_id']);
    $row['gate_passes'] = array_map(
        static fn (array $found): array => pl_stock_document_summary($actorId, $companyId, $bookId, (int) $found['document_id']),
        DB::query('SELECT document_id FROM pl_gate_passes WHERE covers_document_id=%i AND company_id=%i AND book_id=%i ORDER BY document_id', $id, $companyId, $bookId));
    return $row;
}

/**
 * Stock documents in this book, newest first.
 *
 * Filters come straight from a browser query string or a machine read, so every key is
 * validated here rather than assumed: kind, warehouse_id, from, to, limit.
 *
 * @param array<string, mixed> $filters
 */
function pl_list_stock_documents(int $actorId, int $companyId, int $bookId, array $filters = []): array
{
    pl_require_company_access($actorId, $companyId); pl_ledger_book($companyId, $bookId);
    $where = ''; $args = [$companyId, $bookId];
    $kind = $filters['kind'] ?? 'all';
    if (!is_string($kind) || ($kind !== 'all' && !isset(pl_stock_document_kinds()[$kind]))) { throw new DomainException('Unsupported stock document filter.'); }
    if ($kind !== 'all') { $where .= ' AND d.document_kind=%s'; $args[] = $kind; }
    $warehouseId = $filters['warehouse_id'] ?? null;
    if ($warehouseId !== null) {
        pl_get_inventory_warehouse($actorId, $companyId, $bookId, (int) $warehouseId);
        $where .= ' AND (d.from_warehouse_id=%i OR d.to_warehouse_id=%i)'; $args[] = (int) $warehouseId; $args[] = (int) $warehouseId;
    }
    foreach (['from' => ' AND d.document_date>=%s', 'to' => ' AND d.document_date<=%s'] as $field => $clause) {
        if (($filters[$field] ?? null) !== null && $filters[$field] !== '') { $where .= $clause; $args[] = pl_ledger_date((string) $filters[$field]); }
    }
    $limit = $filters['limit'] ?? 200;
    if (!is_int($limit) || $limit < 1 || $limit > 500) { throw new DomainException('Read between 1 and 500 stock documents.'); }
    $args[] = $limit;
    $rows = DB::query('SELECT d.id,d.document_kind,d.document_number,d.document_date,d.from_warehouse_id,d.to_warehouse_id,d.reference,d.reason,'
        . 'f.code AS from_code,f.name AS from_name,f.kind AS from_kind,t.code AS to_code,t.name AS to_name,t.kind AS to_kind,t.driver_name,'
        . 'COALESCE(l.line_total,0) AS line_count,COALESCE(l.quantity,0) AS total_quantity,COALESCE(l.value_base,0) AS total_value_base'
        . ' FROM pl_stock_documents d'
        . ' LEFT JOIN pl_inventory_warehouses f ON f.id=d.from_warehouse_id'
        . ' LEFT JOIN pl_inventory_warehouses t ON t.id=d.to_warehouse_id'
        // `lines` is reserved in MySQL, so the derived table names its own columns plainly.
        . ' LEFT JOIN (SELECT document_id,COUNT(*) AS line_total,SUM(quantity) AS quantity,SUM(value_base) AS value_base FROM pl_stock_document_lines GROUP BY document_id) l ON l.document_id=d.id'
        . ' WHERE d.company_id=%i AND d.book_id=%i' . $where . ' ORDER BY d.document_date DESC,d.id DESC LIMIT %i', ...$args);
    $kinds = pl_stock_document_kinds();
    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id']; $row['line_count'] = (int) $row['line_count'];
        $row['label'] = $kinds[$row['document_kind']]['label'];
        $row['moves_stock'] = $kinds[$row['document_kind']]['moves'];
        $row['total_quantity'] = pl_amount((string) $row['total_quantity']);
        $row['total_value_base'] = pl_amount((string) $row['total_value_base']);
    }
    unset($row);
    return $rows;
}

/**
 * The driver's day for one van, from the movements themselves.
 *
 * Opening plus loaded plus sellable customer returns, less sold and less returned to the
 * warehouse, is the expected closing stock. A difference means something moved that these
 * documents do not explain — a stock count or adjustment case, never something the
 * settlement absorbs silently (DOCUMENT-MODEL.md §3.6).
 */
function pl_van_day(int $actorId, int $companyId, int $bookId, int $warehouseId, string $date): array
{
    pl_require_company_access($actorId, $companyId); pl_ledger_book($companyId, $bookId);
    $date = pl_ledger_date($date);
    $van = pl_get_inventory_warehouse($actorId, $companyId, $bookId, $warehouseId);
    if (!$van['is_mobile']) { throw new DomainException('A settlement reviews a van\'s day. Choose a mobile stock location.'); }
    $rows = DB::query('SELECT m.product_id,p.sku,p.name AS product_name,m.kind,m.quantity_delta,m.value_delta,m.movement_date'
        . ' FROM pl_inventory_movements m JOIN pl_products p ON p.id=m.product_id'
        . ' WHERE m.company_id=%i AND m.book_id=%i AND m.warehouse_id=%i AND m.movement_date<=%s ORDER BY m.movement_date,m.id FOR SHARE',
        $companyId, $bookId, $van['id'], $date);
    $products = []; $blank = ['opening' => '0.0000', 'loaded' => '0.0000', 'sold' => '0.0000', 'customer_returns' => '0.0000',
        'returned' => '0.0000', 'other' => '0.0000', 'closing' => '0.0000', 'sold_cost' => '0.0000', 'loaded_cost' => '0.0000', 'returned_cost' => '0.0000'];
    foreach ($rows as $row) {
        $id = (int) $row['product_id'];
        $products[$id] ??= ['product_id' => $id, 'sku' => $row['sku'], 'product_name' => $row['product_name']] + $blank;
        $quantity = (string) $row['quantity_delta']; $value = (string) $row['value_delta'];
        if ($row['movement_date'] < $date) {
            $products[$id]['opening'] = bcadd($products[$id]['opening'], $quantity, 4);
            $products[$id]['closing'] = bcadd($products[$id]['closing'], $quantity, 4);
            continue;
        }
        $products[$id]['closing'] = bcadd($products[$id]['closing'], $quantity, 4);
        $bucket = match ((string) $row['kind']) {
            'transfer_in' => 'loaded', 'transfer_out' => 'returned', 'issue' => 'sold', 'customer_return' => 'customer_returns', default => 'other',
        };
        $products[$id][$bucket] = bcadd($products[$id][$bucket], ltrim($quantity, '-'), 4);
        $costKey = ['loaded' => 'loaded_cost', 'sold' => 'sold_cost', 'returned' => 'returned_cost'][$bucket] ?? null;
        if ($costKey !== null) { $products[$id][$costKey] = bcadd($products[$id][$costKey], ltrim($value, '-'), 4); }
    }
    $reconciles = true;
    $totals = ['loaded' => '0.0000', 'sold' => '0.0000', 'returned' => '0.0000', 'customer_returns' => '0.0000',
        'opening' => '0.0000', 'closing' => '0.0000', 'sold_cost' => '0.0000', 'loaded_cost' => '0.0000', 'returned_cost' => '0.0000'];
    foreach ($products as &$product) {
        $expected = bcsub(bcadd(bcadd($product['opening'], $product['loaded'], 4), $product['customer_returns'], 4), bcadd($product['sold'], $product['returned'], 4), 4);
        $product['expected_closing'] = $expected;
        $product['variance'] = bcsub($product['closing'], $expected, 4);
        $product['reconciles'] = bccomp($product['variance'], '0', 4) === 0;
        if (!$product['reconciles']) { $reconciles = false; }
        foreach (array_keys($totals) as $field) { $totals[$field] = bcadd($totals[$field], $product[$field], 4); }
    }
    unset($product);
    ksort($products);
    return ['warehouse' => $van, 'date' => $date, 'products' => array_values($products), 'totals' => $totals, 'reconciles' => $reconciles,
        // The cash and credit side of the day belongs to the sale documents the van raised.
        // Those are AR/POS documents with their own posting; this sheet reconciles the goods.
        'basis' => 'stock movements'];
}

/**
 * The same data in one order, whichever order a JSON column returned its object keys in.
 * Lists keep their order, because a list's order is part of its meaning.
 */
function pl_stock_canonical(mixed $value): mixed
{
    if (!is_array($value)) { return $value; }
    $result = array_map('pl_stock_canonical', $value);
    if (!array_is_list($result)) { ksort($result); }
    return $result;
}

/** The reviewed settlement sheet for one van and day. Reviewing posts nothing. */
function pl_review_van_settlement(int $actorId, int $companyId, int $bookId, int $warehouseId, string $date, string $reason, string $key): array
{
    $reason = pl_ledger_text($reason, 'Settlement reason', 500);
    return pl_inventory_command($actorId, $companyId, $bookId, $key, ['van_settlement_review', $warehouseId, $date, $reason],
        function () use ($actorId, $companyId, $bookId, $warehouseId, $date, $reason): array {
            pl_require_module($actorId, $companyId, $bookId, 'inventory-locations');
            $day = pl_van_day($actorId, $companyId, $bookId, $warehouseId, $date);
            $existing = DB::queryFirstRow('SELECT id,status FROM pl_van_settlements WHERE book_id=%i AND warehouse_id=%i AND settlement_date=%s FOR UPDATE', $bookId, $warehouseId, $day['date']);
            if ($existing && $existing['status'] === 'approved') { throw new DomainException('This van day is already approved. Review the next day or record a correcting document.'); }
            $summary = json_encode(['products' => $day['products'], 'totals' => $day['totals']], JSON_THROW_ON_ERROR);
            if ($existing) {
                DB::update('pl_van_settlements', ['reconciles' => $day['reconciles'] ? 1 : 0, 'summary_json' => $summary, 'reason' => $reason], 'id=%i', (int) $existing['id']);
                $id = (int) $existing['id'];
            } else {
                DB::insert('pl_van_settlements', ['company_id' => $companyId, 'book_id' => $bookId, 'warehouse_id' => $warehouseId, 'settlement_date' => $day['date'],
                    'status' => 'reviewed', 'reconciles' => $day['reconciles'] ? 1 : 0, 'summary_json' => $summary, 'reason' => $reason, 'reviewed_by' => $actorId]);
                $id = (int) DB::insertId();
            }
            $result = pl_get_van_settlement($actorId, $companyId, $bookId, $id);
            pl_core_audit($actorId, $companyId, $bookId, 'van_settlement', $id, $existing ? 'updated' : 'created', $reason, null, $result);
            return $result;
        });
}

/**
 * Approving a settlement is a distinct act from recording one (research decision 6).
 *
 * M4 wrote this as a one-line seam over the interim owner check, to be replaced by a capability
 * when the Users module landed. 1.2 M7 replaced it: the permission is `settlement.approve`, which
 * the Owner role holds by default, so the authorisation outcome is unchanged for every existing
 * installation and a custom role can now be given it. tests/capability_equivalence_test.php
 * proves the outcome did not move.
 */
function pl_van_settlement_require_approver(int $actorId, int $companyId): array
{
    $member = pl_require_company_access($actorId, $companyId, true);
    pl_require_capability($actorId, $companyId, 'settlement.approve',
        'Approving a van settlement needs the settlement approval permission. An administrator can grant it in Admin > Roles.');
    return $member;
}

/** The same question without the throw, for a screen deciding whether to show the button. */
function pl_van_settlement_can_approve(int $actorId, int $companyId): bool
{
    pl_require_company_access($actorId, $companyId);
    return pl_user_can($actorId, $companyId, 'settlement.approve');
}

function pl_approve_van_settlement(int $actorId, int $companyId, int $bookId, int $id, string $reason, string $key): array
{
    $reason = pl_ledger_text($reason, 'Approval reason', 500);
    return pl_inventory_command($actorId, $companyId, $bookId, $key, ['van_settlement_approval', $id, $reason],
        function () use ($actorId, $companyId, $bookId, $id, $reason): array {
            pl_van_settlement_require_approver($actorId, $companyId);
            pl_require_module($actorId, $companyId, $bookId, 'inventory-locations');
            $before = pl_get_van_settlement($actorId, $companyId, $bookId, $id);
            if ($before['status'] === 'approved') { throw new DomainException('This settlement is already approved.'); }
            $day = pl_van_day($actorId, $companyId, $bookId, $before['warehouse_id'], $before['settlement_date']);
            if (!$day['reconciles']) { throw new DomainException('This van day does not reconcile. Record the stock count or adjustment that explains the difference before approving.'); }
            // The stored JSON is compared as data: a JSON column reorders object keys on
            // the way back, so both sides are canonicalised and only the values decide.
            if (pl_stock_canonical($before['summary']) !== pl_stock_canonical(['products' => $day['products'], 'totals' => $day['totals']])) {
                throw new DomainException('The van day changed since it was reviewed. Review it again before approving.');
            }
            DB::update('pl_van_settlements', ['status' => 'approved', 'approved_by' => $actorId, 'approved_at' => gmdate('Y-m-d H:i:s'), 'reason' => $reason], 'id=%i AND company_id=%i AND book_id=%i', $id, $companyId, $bookId);
            $result = pl_get_van_settlement($actorId, $companyId, $bookId, $id);
            pl_core_audit($actorId, $companyId, $bookId, 'van_settlement', $id, 'updated', $reason, $before, $result);
            return $result;
        });
}

function pl_get_van_settlement(int $actorId, int $companyId, int $bookId, int $id): array
{
    pl_require_company_access($actorId, $companyId); pl_ledger_book($companyId, $bookId);
    $row = DB::queryFirstRow('SELECT * FROM pl_van_settlements WHERE id=%i AND company_id=%i AND book_id=%i FOR SHARE', $id, $companyId, $bookId);
    if (!$row) { throw new DomainException('This settlement is not available in the selected company and book.'); }
    foreach (['id','company_id','book_id','warehouse_id','reviewed_by'] as $field) { $row[$field] = (int) $row[$field]; }
    $row['approved_by'] = $row['approved_by'] === null ? null : (int) $row['approved_by'];
    $row['reconciles'] = (bool) $row['reconciles'];
    $row['summary'] = json_decode((string) $row['summary_json'], true, 64, JSON_THROW_ON_ERROR);
    $row['warehouse'] = pl_get_inventory_warehouse($actorId, $companyId, $bookId, $row['warehouse_id']);
    return $row;
}

function pl_list_van_settlements(int $actorId, int $companyId, int $bookId, ?int $warehouseId = null): array
{
    pl_require_company_access($actorId, $companyId); pl_ledger_book($companyId, $bookId);
    $where = ''; $args = [$companyId, $bookId];
    if ($warehouseId !== null) { $where = ' AND s.warehouse_id=%i'; $args[] = $warehouseId; }
    return DB::query('SELECT s.id,s.warehouse_id,s.settlement_date,s.status,s.reconciles,s.reason,s.reviewed_at,s.approved_at,w.code,w.name,w.driver_name'
        . ' FROM pl_van_settlements s JOIN pl_inventory_warehouses w ON w.id=s.warehouse_id'
        . ' WHERE s.company_id=%i AND s.book_id=%i' . $where . ' ORDER BY s.settlement_date DESC,s.id DESC LIMIT 200', ...$args);
}

/**
 * Cost columns are a separate permission. M4 wrote this as a one-line seam over the interim owner
 * check; 1.2 M7 replaced it with TWO questions, both of which must say yes (owner decision B58):
 *
 *   1. does this viewer hold `cost.view`? — a judgement about the person. Cost is a trade secret
 *      in some domains and is never visible to an ordinary user without authority.
 *   2. is this report configured to show cost? — a judgement about the report, made per report by
 *      an Admin in Admin > Cost visibility, because the answer differs report by report.
 *
 * The Owner role holds `cost.view` by default and every report ships showing cost, so the
 * authorisation outcome is unchanged for every existing installation.
 */
function pl_stock_cost_visible(int $actorId, int $companyId, int $bookId, string $reportId = 'stock-by-location'): bool
{
    pl_require_company_access($actorId, $companyId);
    return pl_report_cost_visible($actorId, $companyId, $bookId, $reportId);
}

/**
 * Stock and value by location (owner decision B35; the manifest's `stock-by-location`).
 *
 * Fixed warehouses first, then vans, each a group with its own subtotal, plus the
 * aggregate quantity the company holds of that product everywhere and a grand total.
 * The grand total is reconciled against pl_inventory_valuation() across all warehouses,
 * which is the figure that ties to the inventory control accounts.
 *
 * `is_low` is a derived attention flag, not a stored reorder level: this location holds a
 * positive quantity below a tenth of what every location holds of that product. 1.2 has no
 * reorder-level column and this report does not invent one.
 */
function pl_stock_by_location(int $actorId, int $companyId, int $bookId, ?string $asOf = null, string $locations = 'all', string $search = ''): array
{
    pl_require_company_access($actorId, $companyId); pl_ledger_book($companyId, $bookId);
    if (!in_array($locations, ['all', 'fixed', 'mobile'], true)) { throw new DomainException('Choose all locations, warehouses only or vans only.'); }
    $asOf = pl_ledger_date($asOf ?? gmdate('Y-m-d'));
    $search = pl_ledger_text($search, 'Search', 160, false);
    $costVisible = pl_stock_cost_visible($actorId, $companyId, $bookId);
    $warehouses = []; $default = null;
    foreach (pl_list_inventory_warehouses($actorId, $companyId, $bookId) as $warehouse) {
        if ($warehouse['is_default']) { $default = $warehouse['id']; }
        if ($locations !== 'all' && $warehouse['kind'] !== $locations) { continue; }
        $warehouses[$warehouse['id']] = $warehouse;
    }
    // One grouped read over the immutable movement rows; the same arithmetic the per-product
    // balance does, so a group total equals the sum of pl_inventory_balance() for that group.
    $rows = DB::query('SELECT COALESCE(m.warehouse_id,%i) AS location_id,m.product_id,p.sku,p.name AS product_name,p.base_unit,p.selling_price,'
        . 'SUM(m.quantity_delta) AS quantity,SUM(m.value_delta) AS value_base'
        . ' FROM pl_inventory_movements m JOIN pl_products p ON p.id=m.product_id'
        . ' WHERE m.company_id=%i AND m.book_id=%i AND m.movement_date<=%s AND (%s=\'\' OR LOCATE(%s,p.name)>0 OR LOCATE(%s,p.sku)>0)'
        . ' GROUP BY location_id,m.product_id,p.sku,p.name,p.base_unit,p.selling_price ORDER BY p.sku,m.product_id',
        $default ?? 0, $companyId, $bookId, $asOf, $search, $search, $search);
    $aggregate = [];
    foreach ($rows as $row) {
        $product = (int) $row['product_id'];
        $aggregate[$product] = bcadd($aggregate[$product] ?? '0.0000', (string) $row['quantity'], 4);
    }
    $groups = [];
    foreach ($warehouses as $id => $warehouse) {
        $groups[$id] = ['warehouse' => $warehouse, 'rows' => [],
            'totals' => ['items' => 0, 'quantity' => '0.0000', 'value_base' => '0.0000', 'sale_value' => '0.0000', 'negative' => 0, 'low' => 0]];
    }
    foreach ($rows as $row) {
        $id = (int) $row['location_id'];
        if (!isset($groups[$id])) { continue; }
        $quantity = pl_inventory_signed((string) $row['quantity']); $value = pl_inventory_signed((string) $row['value_base']);
        $everywhere = $aggregate[(int) $row['product_id']];
        $sign = bccomp($quantity, '0', 4);
        if ($sign === 0 && bccomp($value, '0', 4) === 0) { continue; }
        $negative = $sign < 0;
        $low = $sign > 0 && bccomp($everywhere, '0', 4) > 0
            && bccomp(bcmul($quantity, '10', 4), $everywhere, 4) < 0;
        $entry = ['product_id' => (int) $row['product_id'], 'sku' => (string) $row['sku'], 'product_name' => (string) $row['product_name'],
            'base_unit' => (string) $row['base_unit'], 'quantity' => $quantity, 'aggregate_quantity' => $everywhere,
            'value_base' => $costVisible ? $value : null,
            'sale_value' => pl_stock_extend($quantity, (string) $row['selling_price']),
            'is_negative' => $negative, 'is_low' => $low];
        $groups[$id]['rows'][] = $entry;
        $groups[$id]['totals']['items']++;
        $groups[$id]['totals']['quantity'] = bcadd($groups[$id]['totals']['quantity'], $quantity, 4);
        $groups[$id]['totals']['value_base'] = bcadd($groups[$id]['totals']['value_base'], $value, 4);
        $groups[$id]['totals']['sale_value'] = bcadd($groups[$id]['totals']['sale_value'], $entry['sale_value'], 4);
        if ($negative) { $groups[$id]['totals']['negative']++; }
        if ($low) { $groups[$id]['totals']['low']++; }
    }
    $totals = ['locations' => 0, 'items' => 0, 'quantity' => '0.0000', 'value_base' => '0.0000', 'sale_value' => '0.0000', 'negative' => 0, 'low' => 0];
    $ordered = array_values($groups);
    usort($ordered, static function (array $a, array $b): int {
        $kind = ($a['warehouse']['kind'] === 'mobile' ? 1 : 0) <=> ($b['warehouse']['kind'] === 'mobile' ? 1 : 0);
        if ($kind !== 0) { return $kind; }
        $isDefault = ($b['warehouse']['is_default'] ? 1 : 0) <=> ($a['warehouse']['is_default'] ? 1 : 0);
        return $isDefault !== 0 ? $isDefault : strcmp((string) $a['warehouse']['code'], (string) $b['warehouse']['code']);
    });
    foreach ($ordered as &$group) {
        $totals['locations']++;
        foreach (['items', 'negative', 'low'] as $field) { $totals[$field] += $group['totals'][$field]; }
        foreach (['quantity', 'value_base', 'sale_value'] as $field) { $totals[$field] = bcadd($totals[$field], $group['totals'][$field], 4); }
        if (!$costVisible) { $group['totals']['value_base'] = null; }
    }
    unset($group);
    $valuation = pl_inventory_valuation($actorId, $companyId, $bookId, $asOf);
    $difference = bcsub($valuation['total_value_base'], $totals['value_base'], 4);
    $result = ['as_of' => $asOf, 'locations' => $locations, 'search' => $search, 'groups' => $ordered, 'totals' => $totals,
        'cost_visible' => $costVisible,
        // Only an all-locations report can tie to the control accounts; a filtered one shows
        // its own locations' stock and says so, exactly as pl_inventory_valuation() does.
        'reconciliation_available' => $locations === 'all' && $search === '',
        'aggregate_value_base' => $costVisible ? $valuation['total_value_base'] : null,
        'difference' => $costVisible ? $difference : null,
        'reconciles' => $locations !== 'all' || $search !== '' || bccomp($difference, '0', 4) === 0,
        'accounts' => $costVisible ? $valuation['accounts'] : []];
    if (!$costVisible) { $result['totals']['value_base'] = null; }
    return $result;
}

/** Van stock: the same report filtered to mobile locations, with the driver and route. */
function pl_van_stock_report(int $actorId, int $companyId, int $bookId, ?string $asOf = null, string $search = ''): array
{
    return pl_stock_by_location($actorId, $companyId, $bookId, $asOf, 'mobile', $search);
}

/**
 * The aggregate across locations, as the reconciliation counterpart of the grouped report:
 * one row per product for the whole book, tied to the inventory control accounts.
 */
function pl_stock_location_aggregate(int $actorId, int $companyId, int $bookId, ?string $asOf = null): array
{
    $costVisible = pl_stock_cost_visible($actorId, $companyId, $bookId);
    $valuation = pl_inventory_valuation($actorId, $companyId, $bookId, $asOf);
    $grouped = pl_stock_by_location($actorId, $companyId, $bookId, $valuation['as_of']);
    $perLocation = '0.0000';
    foreach ($grouped['groups'] as $group) {
        foreach ($group['rows'] as $row) { $perLocation = bcadd($perLocation, $row['value_base'] ?? '0.0000', 4); }
    }
    return ['as_of' => $valuation['as_of'], 'cost_visible' => $costVisible,
        'products' => array_map(static fn (array $row): array => [
            'product_id' => (int) $row['id'], 'sku' => $row['sku'], 'product_name' => $row['name'], 'base_unit' => $row['base_unit'],
            'quantity' => $row['quantity'], 'value_base' => $costVisible ? $row['value_base'] : null,
            'average_cost' => $costVisible ? $row['average_cost'] : null,
        ], $valuation['products']),
        'total_value_base' => $costVisible ? $valuation['total_value_base'] : null,
        'per_location_value_base' => $costVisible ? $perLocation : null,
        'reconciles' => !$costVisible || bccomp($valuation['total_value_base'], $perLocation, 4) === 0,
        'gl_reconciliation_available' => $valuation['gl_reconciliation_available'],
        'accounts' => $costVisible ? $valuation['accounts'] : []];
}

/** Transfer pairs, with the stock document number when one raised them (B36). */
function pl_list_stock_transfers(int $actorId, int $companyId, int $bookId, array $filters = []): array
{
    pl_require_company_access($actorId, $companyId); pl_ledger_book($companyId, $bookId);
    $where = ''; $args = [$companyId, $bookId];
    $warehouseId = $filters['warehouse_id'] ?? null;
    if ($warehouseId !== null) {
        pl_get_inventory_warehouse($actorId, $companyId, $bookId, (int) $warehouseId);
        $where .= ' AND (o.warehouse_id=%i OR i.warehouse_id=%i)'; $args[] = (int) $warehouseId; $args[] = (int) $warehouseId;
    }
    foreach (['from' => ' AND o.movement_date>=%s', 'to' => ' AND o.movement_date<=%s'] as $field => $clause) {
        if (($filters[$field] ?? null) !== null && $filters[$field] !== '') { $where .= $clause; $args[] = pl_ledger_date((string) $filters[$field]); }
    }
    $rows = DB::query("SELECT o.id AS out_movement_id,i.id AS in_movement_id,o.movement_date,o.product_id,p.sku,p.name AS product_name,"
        . "i.quantity_delta AS quantity,i.value_delta AS value_base,o.warehouse_id AS from_warehouse_id,i.warehouse_id AS to_warehouse_id,"
        . "d.id AS document_id,d.document_kind,d.document_number"
        . " FROM pl_inventory_movements o"
        . " JOIN pl_inventory_movements i ON i.original_movement_id=o.id AND i.kind='transfer_in'"
        . " JOIN pl_products p ON p.id=o.product_id"
        . " LEFT JOIN pl_stock_document_lines l ON l.out_movement_id=o.id"
        . " LEFT JOIN pl_stock_documents d ON d.id=l.document_id"
        . " WHERE o.company_id=%i AND o.book_id=%i AND o.kind='transfer_out'" . $where
        . ' ORDER BY o.movement_date DESC,o.id DESC LIMIT 500', ...$args);
    foreach ($rows as &$row) {
        foreach (['out_movement_id','in_movement_id','product_id','from_warehouse_id','to_warehouse_id'] as $field) { $row[$field] = (int) $row[$field]; }
        $row['document_id'] = $row['document_id'] === null ? null : (int) $row['document_id'];
        $row['quantity'] = pl_amount((string) $row['quantity']); $row['value_base'] = pl_amount((string) $row['value_base']);
    }
    unset($row);
    return $rows;
}

/** Read-only print loader for a moving stock document; the record screen's own rule applies. */
function pl_stock_document_print(int $actorId, int $companyId, int $bookId, int $id): array
{
    $document = pl_get_stock_document($actorId, $companyId, $bookId, $id);
    if (!$document['moves_stock']) { throw new DomainException('Print a gate pass from its own gate pass document.'); }
    return $document;
}

/** Read-only print loader for a gate pass. */
function pl_gate_pass_print(int $actorId, int $companyId, int $bookId, int $id): array
{
    $document = pl_get_stock_document($actorId, $companyId, $bookId, $id);
    if ($document['gate_pass'] === null) { throw new DomainException('This document is not a gate pass.'); }
    return $document;
}

/** The printed reference of a stock document is its allocated number. */
function pl_print_stock_document_reference(array $data): string
{
    return (string) ($data['document_number'] ?? '');
}
