<?php
declare(strict_types=1);

/** Customer/vendor documents are deliberately separate from the cash-document table. */
/**
 * The derived number a document carried before migration 035 introduced the per-type series.
 * Posted documents from those releases keep this form for ever, so history reads unchanged;
 * everything posted since carries an allocated `pl_document_series` number instead. Read a
 * document's number through pl_document_number_display() or pl_ar_document_display_number(),
 * never from this function alone.
 */
function pl_ar_document_number(int $id, string $kind): string
{
    return match ($kind) {
        'customer_credit' => 'CR-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT),
        'supplier_credit' => 'SC-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT),
        'invoice' => 'INV-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT),
        'bill' => 'BILL-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT),
        'quote' => 'QUOTE-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT),
        default => throw new LogicException('Unknown AR/AP document kind.'),
    };
}

function pl_ar_line_amount(string $quantity, string $unitPrice): string
{
    $quantity = pl_amount($quantity);
    $unitPrice = pl_amount($unitPrice);
    if (bccomp($quantity, '0', 4) <= 0 || bccomp($unitPrice, '0', 4) <= 0) {
        throw new DomainException('Each document line needs a positive quantity and unit price.');
    }
    $total = pl_amount(bcadd(bcmul($quantity, $unitPrice, 8), '0.00005', 4));
    if (bccomp($total, '0', 4) <= 0) { throw new DomainException('A document line total must be positive.'); }
    return $total;
}

function pl_normalize_ar_document(array $input): array
{
    $kind = $input['kind'] ?? null;
    if (!in_array($kind, ['invoice', 'bill', 'customer_credit', 'supplier_credit'], true)) { throw new DomainException('Choose an invoice, bill, customer credit or supplier credit.'); }
    $date = pl_ledger_date(pl_ledger_text($input['date'] ?? null, 'Document date', 10));
    $currency = pl_currency_code(pl_ledger_text($input['currency'] ?? null, 'Document currency', 3));
    if (!is_int($input['party_id'] ?? null) || $input['party_id'] < 1) { throw new DomainException('Choose a customer or vendor.'); }
    $lines = $input['lines'] ?? null;
    if (!is_array($lines) || !array_is_list($lines) || count($lines) < 1 || count($lines) > 100) { throw new DomainException('Add between one and 100 document lines.'); }
    $normalizedLines = []; $subtotal = '0.0000';
    foreach ($lines as $index => $line) {
        if (!is_array($line)) { throw new DomainException('A document line is invalid.'); }
        $description = pl_ledger_text($line['description'] ?? null, 'Line description', 500);
        $quantity = pl_amount(pl_ledger_text($line['quantity'] ?? null, 'Line quantity', 30));
        $unitPrice = pl_amount(pl_ledger_text($line['unit_price'] ?? null, 'Unit price', 30));
        $lineTotal = pl_ar_line_amount($quantity, $unitPrice);
        $accountId = isset($line['account_id']) ? pl_oi_id($line, 'account_id') : null;
        $productId = isset($line['product_id']) ? pl_oi_id($line, 'product_id') : null;
        $normalizedLines[] = ['line_number' => $index + 1, 'account_id' => $accountId, 'product_id' => $productId,
            'tax_code_id' => isset($line['tax_code_id']) ? pl_oi_id($line, 'tax_code_id') : null,
            'original_line_number' => isset($line['original_line_number']) ? pl_oi_id($line, 'original_line_number') : null,
            'description' => $description, 'quantity' => $quantity, 'unit_price' => $unitPrice, 'line_total' => $lineTotal];
        $subtotal = pl_amount(bcadd($subtotal, $lineTotal, 4));
    }
    $dueDate = isset($input['due_date']) && $input['due_date'] !== '' ? pl_ledger_date(pl_ledger_text($input['due_date'], 'Due date', 10)) : null;
    if ($dueDate === null && in_array($kind, ['customer_credit','supplier_credit'], true)) { $dueDate = $date; }
    if ($dueDate === null) { throw new DomainException('An invoice or bill needs a due date.'); }
    $originalId = isset($input['original_document_id']) ? pl_oi_id($input, 'original_document_id') : null;
    if (in_array($kind, ['customer_credit','supplier_credit'], true) !== ($originalId !== null)) { throw new DomainException('A credit must link its original invoice or bill.'); }
    if ($dueDate < $date) { throw new DomainException('The due date cannot precede the document date.'); }
    $priceMode = $input['price_mode'] ?? null;
    if ($priceMode !== null && !in_array($priceMode, ['exclusive','inclusive'], true)) { throw new DomainException('Choose tax-exclusive or tax-inclusive prices.'); }
    return ['kind' => $kind, 'party_id' => $input['party_id'], 'document_date' => $date, 'due_date' => $dueDate,
        'original_document_id' => $originalId, 'price_mode'=>$priceMode, 'rounding_account_id' => isset($input['rounding_account_id']) ? pl_oi_id($input, 'rounding_account_id') : null, 'currency' => $currency, 'subtotal' => $subtotal,
        'reference' => pl_ledger_text($input['reference'] ?? '', 'Reference', 120, false),
        'terms' => pl_ledger_text($input['terms'] ?? '', 'Payment terms', 500, false),
        'notes' => pl_ledger_text($input['notes'] ?? '', 'Notes', 10000, false), 'lines' => $normalizedLines];
}

function pl_ar_document_party(int $actorId, int $companyId, int $bookId, int $partyId, string $kind): array
{
    $party = pl_get_party($actorId, $companyId, $bookId, $partyId);
    $flag = in_array($kind, ['bill','supplier_credit'], true) ? 'is_vendor' : 'is_customer';
    if (!(bool) $party[$flag]) { throw new DomainException('Choose a party with the required customer or vendor role.'); }
    return $party;
}

function pl_get_ar_document(int $actorId, int $companyId, int $bookId, int $documentId): array
{
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $documentId): array {
        pl_require_company_access($actorId, $companyId); pl_ledger_book($companyId, $bookId);
        $row = DB::queryFirstRow('SELECT * FROM pl_ar_documents WHERE id = %i AND company_id = %i AND book_id = %i FOR SHARE', $documentId, $companyId, $bookId);
        if (!$row) { throw new DomainException('This customer/vendor document is not available in the selected book.'); }
        return pl_ar_document_row($row, $actorId);
    });
}

function pl_save_ar_document(int $actorId, int $companyId, int $bookId, array $input, ?int $documentId = null, ?int $expectedRevision = null): array
{
    $data = pl_normalize_ar_document($input);
    $key = $documentId === null ? pl_request_key(pl_ledger_text($input['creation_key'] ?? null, 'Request identity', 128)) : null;
    $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $data, $key, $hash, $documentId, $expectedRevision): array {
        pl_require_company_access($actorId, $companyId, true); pl_ledger_book($companyId, $bookId, true); pl_require_book_ready($companyId);
        pl_require_module($actorId, $companyId, $bookId, in_array($data['kind'], ['bill','supplier_credit'], true) ? 'ap' : 'ar');
        pl_ar_document_party($actorId, $companyId, $bookId, $data['party_id'], $data['kind']);
        pl_ar_validate_source($actorId, $companyId, $bookId, $data);
        if ($documentId === null) {
            $prior = DB::queryFirstRow('SELECT id, creation_hash FROM pl_ar_documents WHERE company_id = %i AND book_id = %i AND creation_key = %s FOR UPDATE', $companyId, $bookId, $key);
            if ($prior) { if (!hash_equals((string) $prior['creation_hash'], $hash)) { throw new DomainException('This request key already created a different customer/vendor document.'); } return pl_get_ar_document($actorId, $companyId, $bookId, (int) $prior['id']); }
            $data = pl_ar_price_document($actorId, $companyId, $bookId, $data);
            pl_demo_require_document_capacity($companyId, $bookId);
            DB::insert('pl_ar_documents', array_diff_key($data, ['lines' => true]) + ['company_id' => $companyId, 'book_id' => $bookId, 'creation_key' => $key, 'creation_hash' => $hash, 'created_by' => $actorId, 'updated_by' => $actorId]);
            $documentId = (int) DB::insertId();
            foreach ($data['lines'] as $index => $line) { DB::insert('pl_ar_document_lines', $line + ['document_id' => $documentId, 'company_id' => $companyId, 'book_id' => $bookId, 'line_number' => $index + 1]); }
            pl_ar_event($actorId,$companyId,$bookId,$documentId,null,'draft','Draft created');
        } else {
            $existing = DB::queryFirstRow('SELECT * FROM pl_ar_documents WHERE id = %i AND company_id = %i AND book_id = %i FOR UPDATE', $documentId, $companyId, $bookId);
            if (!$existing) { throw new DomainException('This customer/vendor document is not available in the selected book.'); }
            if ($existing['journal_id'] !== null || $existing['status'] !== 'draft') { throw new DomainException('Only draft documents can be edited. Use the explicit workflow for posted corrections.'); }
            if ($expectedRevision !== (int) $existing['revision']) { throw new DomainException('Someone changed this document. Reload the latest draft before saving again.'); }
            if ($data['kind'] !== $existing['kind']) { throw new DomainException('A saved document cannot change kind. Start a new document.'); }
            $data = pl_ar_price_document($actorId, $companyId, $bookId, $data);
            $existingLines = [];
            if (pl_demo_enabled()) {
                $existingLines = DB::query('SELECT id FROM pl_ar_document_lines WHERE document_id=%i AND company_id=%i AND book_id=%i ORDER BY line_number FOR UPDATE', $documentId, $companyId, $bookId);
                if (count($data['lines']) < count($existingLines)) { throw new DomainException('The public sample cannot remove draft lines. Start a new draft with the required lines.'); }
            } else { DB::delete('pl_ar_document_lines', 'document_id = %i', $documentId); }
            foreach ($data['lines'] as $index => $line) {
                if (isset($existingLines[$index])) { DB::update('pl_ar_document_lines', $line, 'id=%i AND document_id=%i AND company_id=%i AND book_id=%i', $existingLines[$index]['id'], $documentId, $companyId, $bookId); }
                else { DB::insert('pl_ar_document_lines', $line + ['document_id' => $documentId, 'company_id' => $companyId, 'book_id' => $bookId, 'line_number' => $index + 1]); }
            }
            DB::update('pl_ar_documents', array_diff_key($data, ['lines' => true]) + ['revision' => $expectedRevision + 1, 'updated_by' => $actorId, 'updated_at' => gmdate('Y-m-d H:i:s')], 'id = %i', $documentId);
            pl_ar_event($actorId,$companyId,$bookId,$documentId,'draft','draft','Draft revised to ' . ($expectedRevision + 1));
        }
        return pl_get_ar_document($actorId, $companyId, $bookId, $documentId);
    });
}

function pl_ar_action(int $actorId, int $companyId, int $bookId, ?int $documentId, string $action, string $key, array $payload, callable $work): array
{
    $key = pl_request_key($key); $hash = hash('sha256', json_encode([$documentId, $action, $payload], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $documentId, $action, $key, $hash, $work): array {
        pl_require_company_access($actorId, $companyId, true); pl_ledger_book($companyId, $bookId, true);
        $prior = DB::queryFirstRow('SELECT payload_hash, result_json FROM pl_ar_document_actions WHERE book_id = %i AND request_key = %s FOR UPDATE', $bookId, $key);
        if ($prior) { if (!hash_equals($prior['payload_hash'], $hash)) { throw new DomainException('This document request key belongs to a different action.'); } return pl_ar_canonical_result(json_decode($prior['result_json'], true, 512, JSON_THROW_ON_ERROR)); }
        return pl_demo_with_document_capacity($companyId, $bookId, function () use ($companyId, $bookId, $documentId, $actorId, $action, $key, $hash, $work): array {
            $result = pl_ar_canonical_result($work());
            DB::insert('pl_ar_document_actions', ['company_id' => $companyId, 'book_id' => $bookId, 'document_id' => $documentId, 'actor_id' => $actorId, 'action' => $action, 'request_key' => $key, 'payload_hash' => $hash, 'result_json' => json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
            return $result;
        });
    });
}

/** MySQL JSON may reorder object keys; first response and exact replay have the same PHP shape. */
function pl_ar_canonical_result(array $value): array
{
    foreach ($value as &$child) { if (is_array($child)) { $child = pl_ar_canonical_result($child); } } unset($child);
    if (!array_is_list($value)) { ksort($value); }
    return $value;
}

function pl_ar_event(int $actorId, int $companyId, int $bookId, int $documentId, ?string $from, string $to, string $reason): void
{
    DB::insert('pl_ar_document_events', ['document_id'=>$documentId,'company_id'=>$companyId,'book_id'=>$bookId,'from_status'=>$from,'to_status'=>$to,'actor_id'=>$actorId,'reason'=>$reason]);
}

/** Validation shared by browser forms and internal module callers. */
function pl_ar_validate_source(int $actorId, int $companyId, int $bookId, array $data): void
{
    foreach ($data['lines'] as $line) {
        if ($line['account_id'] !== null && !DB::queryFirstField('SELECT id FROM pl_accounts WHERE id=%i AND company_id=%i AND book_id=%i AND is_active=1 FOR SHARE', $line['account_id'], $companyId, $bookId)) {
            throw new DomainException('Each line account must be active in this book.');
        }
        if ($line['product_id'] !== null) { pl_get_inventory_product($actorId, $companyId, $bookId, $line['product_id']); }
    }
    if ($data['original_document_id'] !== null) {
        $original = pl_get_ar_document($actorId, $companyId, $bookId, $data['original_document_id']);
        $expected = $data['kind'] === 'customer_credit' ? 'invoice' : 'bill';
        if ($original['kind'] !== $expected || $original['journal_id'] === null || $original['payment_status'] === 'reversed'
            || $original['party_id'] !== $data['party_id'] || $original['currency'] !== $data['currency'] || $data['document_date'] < $original['document_date']) {
            throw new DomainException('The credit must match a posted original document, party, currency and date.');
        }
    }
}

/** Freeze calculated taxes during review; credits reuse original line rates and exact residuals. */
/** Posted, unreversed credit consumption for the current original revision. */
function pl_ar_credit_used(int $companyId,int $bookId,array $original,?int $excludeCreditId=null): array
{
    $used=[];
    $prior=DB::query("SELECT r.source_snapshot,d.id FROM pl_ar_documents d JOIN pl_ar_document_revisions r ON r.document_id=d.id WHERE d.company_id=%i AND d.book_id=%i AND d.original_document_id=%i AND d.id<>%i AND NOT EXISTS (SELECT 1 FROM pl_ar_document_revisions n WHERE n.document_id=r.document_id AND n.revision>r.revision) AND NOT EXISTS (SELECT 1 FROM pl_journals j WHERE j.reversal_of_id=r.journal_id) FOR SHARE",$companyId,$bookId,$original['id'],$excludeCreditId??0);
    foreach ($prior as $previous) {
        $snapshot=json_decode($previous['source_snapshot'],true,512,JSON_THROW_ON_ERROR);
        if (($snapshot['original_revision']??null)!==$original['revision']) { continue; }
        foreach ($snapshot['lines'] as $line) {
            if (($line['original_line_number']??null)===null) { continue; }
            $number=$line['original_line_number']; $used[$number]??=['net'=>'0.0000','tax'=>'0.0000'];
            $used[$number]['net']=bcadd($used[$number]['net'],$line['line_total'],4);
            $used[$number]['tax']=bcadd($used[$number]['tax'],$line['tax_amount'],4);
        }
    }
    return $used;
}

/** Safe display inputs only; the pricing service still validates and recomputes on save. */
function pl_ar_editor_tax_context(int $actorId,int $companyId,int $bookId,?array $original=null,?int $excludeCreditId=null): array
{
    pl_require_company_access($actorId,$companyId); pl_ledger_book($companyId,$bookId);
    $rates=DB::query('SELECT tax_code_id,effective_from,revision,percentage FROM pl_tax_rates WHERE company_id=%i AND book_id=%i ORDER BY effective_from DESC,revision DESC',$companyId,$bookId);
    $sources=[]; $requiresLine=false;
    if ($original!==null) {
        $original=pl_get_ar_document($actorId,$companyId,$bookId,(int)$original['id']);
        $used=pl_ar_credit_used($companyId,$bookId,$original,$excludeCreditId);
        foreach ($original['lines'] as $line) {
            $number=$line['line_number'];
            $sources[$number]=['net'=>bcsub($line['line_total'],$used[$number]['net']??'0',4),'tax'=>bcsub($line['tax_amount'],$used[$number]['tax']??'0',4),'tax_code_id'=>$line['tax_code_id']];
            $requiresLine=$requiresLine || $line['tax_code_id']!==null;
        }
    }
    return ['rates'=>$rates,'credit'=>$original!==null,'requires_line'=>$requiresLine,'sources'=>$sources];
}

function pl_ar_price_document(int $actorId, int $companyId, int $bookId, array $data, ?int $excludeCreditId = null): array
{
    $credit = in_array($data['kind'], ['customer_credit','supplier_credit'], true);
    $original = $credit ? pl_get_ar_document($actorId, $companyId, $bookId, $data['original_document_id']) : null;
    $data['original_revision'] = $original === null ? null : $original['revision'];
    if ($original !== null && ($data['price_mode'] ?? null) !== null && $data['price_mode'] !== $original['price_mode']) { throw new DomainException('A credit retains the original document price mode.'); }
    $data['price_mode'] = $original['price_mode'] ?? $data['price_mode'] ?? pl_tax_price_mode($actorId,$companyId,$bookId);
    $used=$original===null?[]:pl_ar_credit_used($companyId,$bookId,$original,$excludeCreditId);
    $taxTotal = '0.0000'; $subtotal = '0.0000';
    foreach ($data['lines'] as &$line) {
        $rawAmount = pl_ar_line_amount($line['quantity'], $line['unit_price']);
        if ($original === null) {
            $tax = pl_tax_calculate($actorId, $companyId, $bookId, $line['tax_code_id'], $data['document_date'], $rawAmount, $data['kind'] === 'bill' ? 'purchase' : 'sale');
            $split = pl_tax_split($rawAmount,$tax['tax_rate'],$data['price_mode']);
            $line = array_replace($line, $tax, ['line_total'=>$split['net'],'tax_amount'=>$split['tax']]);
        } else {
            $number = $line['original_line_number'];
            $hasTaxCodes = count(array_filter($original['lines'], static fn(array $source): bool => $source['tax_code_id'] !== null)) > 0;
            if ($number === null && ($hasTaxCodes || $line['tax_code_id'] !== null)) { throw new DomainException('Choose the original line for a taxed credit.'); }
            if ($number !== null) {
                $sourceLine = null;
                foreach ($original['lines'] as $candidate) { if ((int) $candidate['line_number'] === $number) { $sourceLine = $candidate; break; } }
                if ($sourceLine === null) { throw new DomainException('The credited original line is not in this document revision.'); }
                if ($line['product_id'] !== null && $line['product_id'] !== $sourceLine['product_id']) { throw new DomainException('A stock credit must use the product from its selected original line.'); }
                $used[$number] ??= ['net'=>'0.0000','tax'=>'0.0000'];
                $remaining = bcsub($sourceLine['line_total'], $used[$number]['net'], 4);
                $taxRemaining = bcsub($sourceLine['tax_amount'], $used[$number]['tax'], 4);
                $basis = $data['price_mode'] === 'inclusive' ? bcadd($remaining,$taxRemaining,4) : $remaining;
                if (bccomp($rawAmount, $basis, 4) > 0) { throw new DomainException('This credit exceeds the original line remaining amount.'); }
                if ($line['tax_code_id'] !== null && $line['tax_code_id'] !== $sourceLine['tax_code_id']) { throw new DomainException('A credit retains the original tax code.'); }
                $taxAmount = bccomp($rawAmount, $basis, 4) === 0 ? $taxRemaining
                    : bcadd(bcdiv(bcmul($taxRemaining, $rawAmount, 24), $basis, 24), '0.00005', 4);
                $netAmount = $data['price_mode'] === 'inclusive' ? bcsub($rawAmount,$taxAmount,4) : $rawAmount;
                $line = array_replace($line, array_intersect_key($sourceLine, array_flip(['tax_code_id','tax_rate_id','tax_rate','tax_account_id','tax_label'])), ['line_total'=>$netAmount,'tax_amount'=>$taxAmount]);
                $used[$number]['net'] = bcadd($used[$number]['net'], $line['line_total'], 4); $used[$number]['tax'] = bcadd($used[$number]['tax'], $taxAmount, 4);
            } else {
                $line = array_replace($line, ['line_total'=>$rawAmount,'tax_code_id'=>null,'tax_rate_id'=>null,'tax_rate'=>'0.000000','tax_amount'=>'0.0000','tax_account_id'=>null,'tax_label'=>'']);
            }
        }
        $taxTotal = pl_amount(bcadd($taxTotal, $line['tax_amount'], 4));
        if (bccomp($line['line_total'],'0',4)<=0) { throw new DomainException('The tax split must leave a positive net line at ledger precision.'); }
        $subtotal = pl_amount(bcadd($subtotal,$line['line_total'],4));
    } unset($line);
    $data['subtotal'] = $subtotal; $data['tax_total'] = $taxTotal; $data['total'] = pl_amount(bcadd($subtotal, $taxTotal, 4));
    return $data;
}

function pl_ar_document_row(array $row, int $actorId): array
{
    $revision = DB::queryFirstRow('SELECT * FROM pl_ar_document_revisions WHERE document_id=%i AND company_id=%i AND book_id=%i ORDER BY revision DESC LIMIT 1 FOR SHARE', $row['id'], $row['company_id'], $row['book_id']);
    if ($revision) {
        $row = array_replace($row, json_decode($revision['source_snapshot'], true, 512, JSON_THROW_ON_ERROR), ['revision' => (int) $revision['revision'], 'journal_id' => (int) $revision['journal_id'], 'open_item_id' => (int) $revision['open_item_id'], 'status' => 'posted']);
    } else {
        $row['lines'] = DB::query('SELECT id,line_number,description,quantity,unit_price,line_total,account_id,product_id,tax_code_id,tax_rate_id,tax_rate,tax_amount,tax_account_id,tax_label,original_line_number FROM pl_ar_document_lines WHERE document_id=%i AND company_id=%i AND book_id=%i ORDER BY line_number FOR SHARE', $row['id'], $row['company_id'], $row['book_id']);
    }
    foreach (['id','company_id','book_id','party_id','revision'] as $field) { $row[$field] = (int) $row[$field]; }
    foreach (['journal_id','open_item_id','original_document_id','rounding_account_id','original_revision'] as $field) { $row[$field] = $row[$field] === null ? null : (int) $row[$field]; }
    foreach ($row['lines'] as &$line) {
        foreach (['id','line_number','account_id','product_id','tax_code_id','tax_rate_id','tax_account_id','original_line_number'] as $field) { if (isset($line[$field])) { $line[$field] = (int) $line[$field]; } }
        foreach (['quantity','unit_price','line_total'] as $field) { $line[$field] = bcadd((string) $line[$field], '0', 4); }
    } unset($line);
    $row['subtotal'] = bcadd((string) $row['subtotal'], '0', 4);
    $row['document_number'] = ($row['document_number'] ?? null) === null ? null : (string) $row['document_number'];
    $row['number'] = pl_document_number_display($row['document_number'], $row['id'], $row['kind']); $row['date'] = $row['document_date'];
    $row['party'] = pl_get_party($actorId, $row['company_id'], $row['book_id'], $row['party_id']);
    $row['payment_status'] = null; $row['outstanding_fc'] = '0.0000'; $row['outstanding_base'] = '0.0000'; $row['reversal_journal_id'] = null;
    $row['is_credit'] = in_array($row['kind'], ['customer_credit','supplier_credit'], true);
    if ($row['journal_id'] !== null) {
        $reversal = DB::queryFirstField('SELECT id FROM pl_journals WHERE reversal_of_id=%i FOR SHARE', $row['journal_id']);
        $row['reversal_journal_id'] = $reversal === null ? null : (int) $reversal;
        $item = pl_open_item_state($row['company_id'], $row['book_id'], $row['open_item_id']);
        $row['outstanding_fc'] = $row['is_credit'] ? '0.0000' : $item['remaining_fc'];
        $row['outstanding_base'] = $row['is_credit'] ? '0.0000' : $item['remaining_base'];
        $row['payment_status'] = $reversal !== null ? 'reversed' : ($row['is_credit'] ? 'applied' : (bccomp($item['remaining_fc'], '0', 4) === 0 ? 'paid' : (bccomp($item['remaining_fc'], $row['total'], 4) < 0 ? 'partially_paid' : 'posted')));
        $row['open_item'] = $item;
    }
    $row['history'] = DB::query('SELECT revision,journal_id,open_item_id,reason,actor_id,recorded_at FROM pl_ar_document_revisions WHERE document_id=%i ORDER BY revision', $row['id']);
    unset($row['creation_key'], $row['creation_hash']);
    return $row;
}

/** Only unused controls may be automatically activated by an owner. Existing books require reviewed conversion. */
function pl_ar_control(int $actorId, int $companyId, int $bookId, array $document, bool $activate = true): int
{
    $payable = in_array($document['kind'], ['bill','supplier_credit'], true);
    $role = $payable ? 'payables' : 'receivables';
    $control = $document['party']['financial'][$payable ? 'ap_account_id' : 'ar_account_id'] ?? null;
    if ($control === null) {
        $candidates = DB::query('SELECT id FROM pl_accounts WHERE company_id=%i AND book_id=%i AND role=%s AND is_active=1 FOR SHARE', $companyId, $bookId, $role);
        if (count($candidates) !== 1) { throw new DomainException('Choose the party control account; there must be one unambiguous default.'); }
        $control = (int) $candidates[0]['id'];
    }
    if (!DB::queryFirstField('SELECT id FROM pl_accounts WHERE id=%i AND company_id=%i AND book_id=%i AND role=%s AND is_active=1 FOR SHARE', $control, $companyId, $bookId, $role)) {
        throw new DomainException('Choose a matching active customer or supplier control account.');
    }
    if (!DB::queryFirstField('SELECT account_id FROM pl_open_item_accounts WHERE account_id=%i FOR SHARE', $control)) {
        if ($activate) { pl_activate_open_item_account($actorId, $companyId, $bookId, (int) $control, 'Explicit posting of the first customer/vendor document to an unused control'); }
        else { pl_open_item_activation_check($actorId,$companyId,$bookId,(int)$control); }
    }
    return (int) $control;
}

function pl_ar_snapshot(array $line): array
{
    $snapshot = array_intersect_key($line, array_flip(['currency','rate','rate_type','rate_source_id','rate_is_stale','ic_counterparty_entity_id']));
    foreach (['rate_source_id','ic_counterparty_entity_id'] as $field) { $snapshot[$field] = $snapshot[$field] === null ? null : (int) $snapshot[$field]; }
    $snapshot['rate_is_stale'] = (bool) $snapshot['rate_is_stale'];
    return $snapshot;
}

/** Internal work under the document command and book lock. No independent balance is stored. */
/** Shared financial posting plan; preview does not activate a control or create an item. */
function pl_ar_posting_plan(int $actorId, int $companyId, int $bookId, array $document, ?int $offsetAccountId, ?string $rate, bool $activateControl, ?array $creditRestoration = null): array
{
    pl_require_module($actorId, $companyId, $bookId, in_array($document['kind'], ['bill','supplier_credit'], true) ? 'ap' : 'ar');
    pl_require_book_ready($companyId); pl_ar_validate_source($actorId, $companyId, $bookId, $document);
    pl_ar_document_party($actorId, $companyId, $bookId, $document['party_id'], $document['kind']);
    $book = pl_ledger_book($companyId, $bookId, true);
    $credit = in_array($document['kind'], ['customer_credit','supplier_credit'], true);
    $receivable = in_array($document['kind'], ['invoice','customer_credit'], true);
    $repriced = pl_ar_price_document($actorId, $companyId, $bookId, $document, $credit ? $document['id'] : null);
    $taxFields = array_flip(['tax_code_id','tax_rate_id','tax_rate','tax_amount','tax_account_id','tax_label']);
    foreach ($document['lines'] as $index => $line) {
        if (array_intersect_key($line,$taxFields) !== array_intersect_key($repriced['lines'][$index],$taxFields)) { throw new DomainException('The effective tax rate or credited line residual changed. Save and review the draft again before posting.'); }
    }
    if ($document['original_revision'] !== $repriced['original_revision'] || $document['total'] !== $repriced['total']) { throw new DomainException('The original revision or reviewed document total changed. Review this draft again.'); }
    $original = null;
    if ($credit) {
        $original = pl_get_ar_document($actorId, $companyId, $bookId, $document['original_document_id']);
        $item = pl_open_item_state($companyId, $bookId, $original['open_item_id']);
        if ($creditRestoration!==null) {
            $item['remaining_fc']=bcadd($item['remaining_fc'],$creditRestoration['amount_fc'],4);
            $item['remaining_base']=bcadd($item['remaining_base'],$creditRestoration['amount_base'],4);
            $item['latest_activity_date']=max($item['latest_activity_date'],$creditRestoration['date']);
        }
        $control = (int) $item['control_account_id']; $itemId = (int) $item['id'];
        $base = pl_oi_allocated_base($item, $document['total']);
        if ($document['document_date'] < $item['latest_activity_date']) { throw new DomainException('A credit cannot precede the latest open-item activity.'); }
        $snapshot = pl_ar_snapshot($item['recognition']);
        if ($rate !== null && pl_fx_rate($rate) !== $snapshot['rate']) { throw new DomainException('Credits retain the original transaction rate and carrying value.'); }
    } else {
        $control = pl_ar_control($actorId, $companyId, $bookId, $document, $activateControl);
        $snapshot = pl_oi_rate($actorId, $companyId, $bookId, $document['currency'], $document['document_date'], $rate, null, 'spot');
        if (($document['party']['linked_entity_id'] ?? null) !== null) { $snapshot['ic_counterparty_entity_id'] = (int) $document['party']['linked_entity_id']; }
        $base = pl_fx_convert($document['total'], $snapshot['rate']);
        $itemId = null;
    }
    $description = $document['number'] . ' - ' . $document['party']['legal_name'];
    $controlDebit = $receivable !== $credit;
    $lines = [pl_oi_line($control, $document['total'], $base, $controlDebit, $snapshot, $description)]; $offsetBase = '0.0000'; $taxLines = [];
    foreach ($document['lines'] as &$sourceLine) {
        $accountId = $sourceLine['account_id'] ?? $offsetAccountId;
        if ($accountId === null) { throw new DomainException('Choose a posting account for every document line.'); }
        $account = DB::queryFirstRow('SELECT * FROM pl_accounts WHERE id=%i AND company_id=%i AND book_id=%i AND is_active=1 FOR SHARE', $accountId, $companyId, $bookId);
        if (!$account || ($receivable ? $account['type'] !== 'income' : !in_array($account['type'], ['expense','asset','liability'], true))
            || in_array($account['role'], ['cash_bank','receivables','payables'], true)) { throw new DomainException('Choose an income line account for sales, or an expense, asset or clearing account for bills.'); }
        $sourceLine['account_id'] = (int) $accountId;
        $value = pl_fx_convert($sourceLine['line_total'], $snapshot['rate']);
        $lines[] = pl_oi_line((int) $accountId, $sourceLine['line_total'], $value, !$controlDebit, $snapshot, $sourceLine['description']);
        $offsetBase = bcadd($offsetBase, $value, 4);
        if (bccomp($sourceLine['tax_amount'], '0', 4) > 0) {
            $taxBase = pl_fx_convert($sourceLine['tax_amount'], $snapshot['rate']);
            $taxLines[] = pl_oi_line($sourceLine['tax_account_id'], $sourceLine['tax_amount'], $taxBase, !$controlDebit, $snapshot, $sourceLine['tax_label']);
            $offsetBase = bcadd($offsetBase, $taxBase, 4);
        }
    } unset($sourceLine);
    $lines = array_merge($lines, $taxLines);
    $difference = bcsub($base, $offsetBase, 4);
    if (bccomp($difference, '0', 4) !== 0) {
        $roundingId = $document['rounding_account_id']; $magnitude = ltrim($difference, '-');
        if ($roundingId === null || bccomp($magnitude, '0.0100', 4) > 0 || !DB::queryFirstField("SELECT id FROM pl_accounts WHERE id=%i AND company_id=%i AND book_id=%i AND type='expense' AND is_active=1 FOR SHARE", $roundingId, $companyId, $bookId)) {
            throw new DomainException('Currency rounding requires a selected expense rounding account and a difference no greater than 0.0100.');
        }
        $domestic = pl_oi_rate($actorId, $companyId, $bookId, $book['currency'], $document['document_date'], null, null, 'spot');
        $lines[] = pl_oi_line($roundingId, $magnitude, $magnitude, bccomp($difference, '0', 4) > 0 ? !$controlDebit : $controlDebit, $domestic, 'Explicit currency rounding');
    }
    return ['document'=>$document,'original'=>$original,'currency'=>$book['currency'],'credit'=>$credit,'receivable'=>$receivable,'control'=>$control,'item_id'=>$itemId,'description'=>$description,'lines'=>$lines];
}

function pl_ar_post_version(int $actorId, int $companyId, int $bookId, array $document, ?int $offsetAccountId, ?string $rate, string $key): array
{
    $plan=pl_ar_posting_plan($actorId,$companyId,$bookId,$document,$offsetAccountId,$rate,true);
    $document=$plan['document']; $original=$plan['original']; $credit=$plan['credit']; $receivable=$plan['receivable'];
    $control=$plan['control']; $itemId=$plan['item_id']; $lines=$plan['lines']; $description=$plan['description'];
    $book=['currency'=>$plan['currency']];
    if ($itemId===null) {
        DB::insert('pl_open_items', ['company_id'=>$companyId,'book_id'=>$bookId,'party_id'=>$document['party_id'],'control_account_id'=>$control,'direction'=>$receivable ? 'receivable' : 'payable','currency'=>$document['currency'],'source_reference'=>'ar-document:' . $document['id'] . ':revision:' . $document['revision'],'created_by'=>$actorId]);
        $itemId = (int) DB::insertId();
    }
    $journal = pl_post_journal_locked($actorId, $companyId, $bookId, ['date'=>$document['document_date'],'currency'=>$book['currency'],'source_type'=>$credit ? 'open_item_settlement' : 'open_item_recognition','source_reference'=>'open-item:' . $itemId,'idempotency_key'=>$key,'description'=>$description,'lines'=>$lines], null, $credit ? $itemId : null);
    $document['journal_id'] = (int) $journal['id']; $document['open_item_id'] = $itemId; $document['status'] = 'posted';
    if ($document['kind'] === 'invoice') { pl_inventory_issue_ar_document($actorId, $companyId, $bookId, $document, (int) $journal['id'], $key . ':stock'); }
    if ($document['kind'] === 'customer_credit') { pl_inventory_credit_ar_document($actorId, $companyId, $bookId, $document, $original, $key . ':return'); }
    return $document;
}

function pl_ar_record_version(int $actorId, array $document, string $reason): void
{
    $snapshot = array_intersect_key($document, array_flip(['kind','party_id','document_date','due_date','currency','subtotal','tax_total','total','price_mode','reference','terms','notes','lines','original_document_id','original_revision','rounding_account_id']));
    DB::insert('pl_ar_document_revisions', ['document_id'=>$document['id'],'company_id'=>$document['company_id'],'book_id'=>$document['book_id'],'revision'=>$document['revision'],'journal_id'=>$document['journal_id'],'open_item_id'=>$document['open_item_id'],'source_snapshot'=>json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),'actor_id'=>$actorId,'reason'=>$reason]);
}

/** Preview current editor values without saving a draft, activating controls or posting. */
function pl_preview_ar_document(int $actorId,int $companyId,int $bookId,array $input,?int $documentId=null,?int $revision=null,?string $rate=null): array
{
    $data=pl_normalize_ar_document($input);
    return pl_ledger_transaction(function () use ($actorId,$companyId,$bookId,$data,$documentId,$revision,$rate): array {
        pl_require_company_access($actorId,$companyId,true); pl_ledger_book($companyId,$bookId,true);
        if ($documentId!==null) {
            $current=pl_get_ar_document($actorId,$companyId,$bookId,$documentId);
            if ($current['status']!=='draft' || $current['revision']!==$revision || $current['kind']!==$data['kind']) { throw new DomainException('Review the current draft before previewing these changes.'); }
        }
        $document=pl_ar_price_document($actorId,$companyId,$bookId,$data,$documentId);
        $document+=['id'=>$documentId??0,'revision'=>$revision??0,// A draft is unnumbered: its series number is allocated when it is posted.
            'number'=>($documentId===null?'New ':'Draft ').str_replace('_',' ',$data['kind']),
            'party'=>pl_ar_document_party($actorId,$companyId,$bookId,$data['party_id'],$data['kind'])];
        $plan=pl_ar_posting_plan($actorId,$companyId,$bookId,$document,null,$rate,false);
        $plan['stock']=pl_inventory_ar_preview($actorId,$companyId,$bookId,$plan['document'],$plan['original']);
        return $plan;
    });
}

/** Posting from an editor is one atomic save/review/post operation with stable retries. */
function pl_save_and_post_ar_document(int $actorId,int $companyId,int $bookId,array $input,string $expectedHash,?int $documentId=null,?int $revision=null,?string $rate=null): array
{
    $key=pl_ledger_text($input['creation_key']??null,'Document identity',128);
    return pl_ar_action($actorId,$companyId,$bookId,$documentId,'editor_post','ar-editor:'.hash('sha256',$key),[$input,$revision,$rate],
        function () use ($actorId,$companyId,$bookId,$input,$expectedHash,$documentId,$revision,$rate): array {
            $plan=pl_preview_ar_document($actorId,$companyId,$bookId,$input,$documentId,$revision,$rate);
            if (!hash_equals($expectedHash,hash('sha256',json_encode($plan,JSON_THROW_ON_ERROR)))) { throw new DomainException('The document or its posting basis changed. Update the preview before posting.'); }
            $draft=pl_save_ar_document($actorId,$companyId,$bookId,$input,$documentId,$revision);
            return pl_post_ar_document($actorId,$companyId,$bookId,$draft['id'],$draft['revision'],null,$rate);
        });
}

function pl_post_ar_document(int $actorId, int $companyId, int $bookId, int $documentId, int $expectedRevision, ?int $offsetAccountId = null, ?string $rate = null): array
{
    return pl_ar_action($actorId, $companyId, $bookId, $documentId, 'post', 'ar-document:' . $documentId . ':post', compact('expectedRevision','offsetAccountId','rate'), function () use ($actorId,$companyId,$bookId,$documentId,$expectedRevision,$offsetAccountId,$rate): array {
        $document = pl_get_ar_document($actorId, $companyId, $bookId, $documentId);
        if ($document['status'] !== 'draft' || $document['revision'] !== $expectedRevision) { throw new DomainException('Review the latest draft before posting.'); }
        $document = pl_ar_post_version($actorId, $companyId, $bookId, $document, $offsetAccountId, $rate, 'ar-document:' . $documentId . ':post-journal');
        // Drafts stay unnumbered: the series is consumed here, inside the posting transaction and
        // under the book lock the posting funnel already holds. A failure after this point rolls the
        // whole transaction back, including the counter, so the next posting takes the same number.
        $document['document_number'] = pl_document_series_allocate($actorId, $companyId, $bookId, $document['kind'], $documentId, $document['document_date']);
        DB::update('pl_ar_documents', ['status'=>'posted','journal_id'=>$document['journal_id'],'open_item_id'=>$document['open_item_id'],'document_number'=>$document['document_number'],'updated_by'=>$actorId,'updated_at'=>gmdate('Y-m-d H:i:s')], 'id=%i AND journal_id IS NULL', $documentId);
        pl_ar_record_version($actorId, $document, 'Initial posting');
        pl_ar_event($actorId,$companyId,$bookId,$documentId,'draft','posted','Initial posting');
        return pl_get_ar_document($actorId, $companyId, $bookId, $documentId);
    });
}

function pl_settle_ar_document(int $actorId, int $companyId, int $bookId, int $documentId, array $input): array
{
    return pl_ar_action($actorId, $companyId, $bookId, $documentId, 'settle', pl_ledger_text($input['idempotency_key'] ?? null, 'Settlement key', 128), $input, function () use ($actorId,$companyId,$bookId,$documentId,$input): array {
        $document = pl_get_ar_document($actorId, $companyId, $bookId, $documentId);
        if (!in_array($document['kind'], ['invoice','bill'], true) || $document['journal_id'] === null || $document['payment_status'] === 'reversed') { throw new DomainException('Choose a posted invoice or bill for settlement.'); }
        pl_require_module($actorId, $companyId, $bookId, $document['kind'] === 'bill' ? 'ap' : 'ar');
        $input['item_id'] = $document['open_item_id'];
        return pl_settle_open_item($actorId, $companyId, $bookId, $input) + ['document_id'=>$documentId];
    });
}

function pl_reverse_ar_document(int $actorId, int $companyId, int $bookId, int $documentId, ?string $date, string $reason, ?string $key = null): array
{
    $key ??= 'ar-document:' . $documentId . ':reverse';
    return pl_ar_action($actorId, $companyId, $bookId, $documentId, 'reverse', $key, compact('date','reason'), function () use ($actorId,$companyId,$bookId,$documentId,$date,$reason,$key): array {
        $document = pl_get_ar_document($actorId, $companyId, $bookId, $documentId);
        if ($document['journal_id'] === null) { throw new DomainException('Only posted documents can be reversed.'); }
        $reversalDate = $date ?? gmdate('Y-m-d');
        pl_inventory_reverse_ar_document($actorId, $companyId, $bookId, $document, $reversalDate, $key . ':stock', $reason);
        pl_reverse_journal($actorId, $companyId, $bookId, $document['journal_id'], $date, $key . ':journal', $reason);
        pl_ar_event($actorId,$companyId,$bookId,$documentId,'posted','reversed',$reason);
        return pl_get_ar_document($actorId, $companyId, $bookId, $documentId);
    });
}

/** Read-only reversal and replacement effects, including stock restored before re-issue. */
function pl_preview_ar_correction(int $actorId,int $companyId,int $bookId,int $documentId,array $input,int $expectedRevision,string $reason,?string $reversalDate=null,?string $rate=null): array
{
    $data=pl_normalize_ar_document($input); $reason=pl_ledger_text($reason,'Correction reason',400);
    $date=pl_ledger_date($reversalDate??gmdate('Y-m-d'));
    return pl_ledger_transaction(function () use ($actorId,$companyId,$bookId,$documentId,$data,$expectedRevision,$reason,$date,$rate): array {
        $member=pl_require_company_access($actorId,$companyId,true); pl_ledger_book($companyId,$bookId,true);
        $document=pl_get_ar_document($actorId,$companyId,$bookId,$documentId);
        if ($document['journal_id']===null || $document['revision']!==$expectedRevision || $document['payment_status']==='reversed') { throw new DomainException('Review the current posted revision before correction.'); }
        if ($document['kind']!==$data['kind'] || $document['reference']!==$data['reference'] || $document['original_document_id']!==$data['original_document_id']) { throw new DomainException('A correction retains the same document identity, kind, reference and original credit link.'); }
        $journal=pl_get_journal($actorId,$companyId,$bookId,$document['journal_id']);
        if ($date<$journal['journal_date'] || $data['document_date']<$date) { throw new DomainException('Date the reversal on or after its original posting, and the replacement on or after the reversal.'); }
        if ($date<gmdate('Y-m-d') && ($member['role']!=='owner' || $date!==$journal['journal_date'])) { throw new DomainException('Backdated reversals require an owner, the original posting date and an open period.'); }
        pl_open_item_assert_correction_allowed($companyId,$bookId,$document['journal_id']);
        pl_purchasing_assert_reversal_allowed($companyId,$bookId,$document['journal_id']);
        $reversal=[];
        foreach ($journal['lines'] as $line) { $reversal[]=['account_id'=>(int)$line['account_id'],'debit'=>$line['credit'],'credit'=>$line['debit']]; }
        $restoration=null;
        if ($document['is_credit']) {
            $control=(int)$document['open_item']['control_account_id'];
            $allocated=array_values(array_filter($journal['lines'],static fn(array $line):bool=>(int)$line['account_id']===$control));
            if (count($allocated)!==1) { throw new DomainException('Review the credit control allocation before correction.'); }
            $restoration=['amount_fc'=>$allocated[0]['amount_fc'],'amount_base'=>$allocated[0]['amount_base'],'date'=>$date];
        }
        $updated=array_replace($document,$data,['revision'=>$expectedRevision+1,'journal_id'=>null,'open_item_id'=>null,'status'=>'draft']);
        $updated['party']=pl_ar_document_party($actorId,$companyId,$bookId,$updated['party_id'],$updated['kind']);
        $updated=pl_ar_price_document($actorId,$companyId,$bookId,$updated,$document['is_credit']?$documentId:null);
        $plan=pl_ar_posting_plan($actorId,$companyId,$bookId,$updated,null,$rate,false,$restoration);
        foreach ([['date'=>$date,'lines'=>$reversal],['date'=>$data['document_date'],'lines'=>$plan['lines']]] as $posting) {
            $periods=DB::query('SELECT status FROM pl_periods WHERE company_id=%i AND book_id=%i AND start_date<=%s AND end_date>=%s FOR SHARE',$companyId,$bookId,$posting['date'],$posting['date']);
            if (count($periods)!==1 || $periods[0]['status']!=='open') { throw new DomainException('Both correction dates must fall within open accounting periods.'); }
            pl_reconciliation_assert_posting_allowed($companyId,$bookId,$posting);
        }
        $stock=pl_inventory_ar_correction_basis($actorId,$companyId,$bookId,$document,$date);
        $plan['stock']=pl_inventory_ar_preview($actorId,$companyId,$bookId,$plan['document'],$plan['original'],$stock['balances'],$stock['restored']);
        $plan['stock_reversal']=$stock['plans'];
        $plan['reversal']=['journal_id'=>$document['journal_id'],'date'=>$date,'reason'=>$reason,'lines'=>$reversal];
        return $plan;
    });
}

function pl_correct_ar_document(int $actorId, int $companyId, int $bookId, int $documentId, array $input, int $expectedRevision, string $key, string $reason, ?string $reversalDate = null, ?string $rate = null, ?string $expectedHash = null): array
{
    $data = pl_normalize_ar_document($input); $reason = pl_ledger_text($reason, 'Correction reason', 500);
    return pl_ar_action($actorId, $companyId, $bookId, $documentId, 'correct', $key, compact('data','expectedRevision','reason','reversalDate','rate'), function () use ($actorId,$companyId,$bookId,$documentId,$input,$data,$expectedRevision,$key,$reason,$reversalDate,$rate,$expectedHash): array {
        if ($expectedHash!==null) {
            $plan=pl_preview_ar_correction($actorId,$companyId,$bookId,$documentId,$input,$expectedRevision,$reason,$reversalDate,$rate);
            if (!hash_equals($expectedHash,hash('sha256',json_encode($plan,JSON_THROW_ON_ERROR)))) { throw new DomainException('The correction or its posting basis changed. Update the preview before posting.'); }
        }
        $document = pl_get_ar_document($actorId, $companyId, $bookId, $documentId);
        if ($document['journal_id'] === null || $document['revision'] !== $expectedRevision || $document['payment_status'] === 'reversed') { throw new DomainException('Review the current posted revision before correction.'); }
        if ($document['kind'] !== $data['kind'] || $document['reference'] !== $data['reference'] || $document['original_document_id'] !== $data['original_document_id']) { throw new DomainException('A correction retains the same document identity, kind, reference and original credit link.'); }
        $date = $reversalDate ?? gmdate('Y-m-d');
        if ($data['document_date'] < $date) { throw new DomainException('A corrected posting cannot precede its reversal.'); }
        pl_inventory_reverse_ar_document($actorId, $companyId, $bookId, $document, $date, $key . ':stock-reversal', $reason);
        pl_reverse_journal($actorId, $companyId, $bookId, $document['journal_id'], $date, $key . ':reversal', $reason);
        $updated = array_replace($document, $data, ['revision'=>$expectedRevision + 1,'journal_id'=>null,'open_item_id'=>null,'status'=>'draft']);
        $updated['party'] = pl_ar_document_party($actorId, $companyId, $bookId, $updated['party_id'], $updated['kind']);
        $updated = pl_ar_price_document($actorId, $companyId, $bookId, $updated, $updated['is_credit'] ? $documentId : null);
        $posted = pl_ar_post_version($actorId, $companyId, $bookId, $updated, null, $rate, $key . ':replacement');
        pl_ar_record_version($actorId, $posted, $reason);
        pl_ar_event($actorId,$companyId,$bookId,$documentId,'posted','corrected',$reason);
        return pl_get_ar_document($actorId, $companyId, $bookId, $documentId);
    });
}

function pl_list_ar_documents(int $actorId, int $companyId, int $bookId, array $filters = []): array
{
    return pl_ledger_transaction(function () use ($actorId,$companyId,$bookId,$filters): array {
        pl_require_company_access($actorId, $companyId); pl_ledger_book($companyId, $bookId);
        $kind = $filters['kind'] ?? 'all';
        if (!in_array($kind, ['all','invoice','bill','customer_credit','supplier_credit','ar','ap'], true)) { throw new DomainException('Choose customer or supplier documents.'); }
        $documents = [];
        foreach (DB::query('SELECT * FROM pl_ar_documents WHERE company_id=%i AND book_id=%i ORDER BY id DESC FOR SHARE', $companyId, $bookId) as $row) {
            if ($kind !== 'all' && $kind !== $row['kind'] && !($kind === 'ar' && in_array($row['kind'], ['invoice','customer_credit'], true)) && !($kind === 'ap' && in_array($row['kind'], ['bill','supplier_credit'], true))) { continue; }
            $documents[] = pl_ar_document_row($row, $actorId);
        }
        usort($documents, static fn(array $a, array $b): int => [$b['document_date'],$b['id']] <=> [$a['document_date'],$a['id']]);
        return ['documents'=>$documents,'total'=>count($documents)];
    });
}

/** Page the current revision, including corrections, before hydrating document detail. */
function pl_page_ar_documents(int $actorId,int $companyId,int $bookId,string $side,array $filters): array
{
    if (!in_array($side,['ar','ap'],true)) { throw new DomainException('Choose customer or supplier documents.'); }
    $orders=[
        'date'=>['asc'=>'q.effective_date ASC, q.id ASC','desc'=>'q.effective_date DESC, q.id DESC'],
        'name'=>['asc'=>'p.legal_name ASC, q.id ASC','desc'=>'p.legal_name DESC, q.id DESC'],
        'amount'=>['asc'=>'q.current_total ASC, q.id ASC','desc'=>'q.current_total DESC, q.id DESC'],
        'due'=>['asc'=>'q.current_due ASC, q.id ASC','desc'=>'q.current_due DESC, q.id DESC'],
    ];
    $sort=$filters['sort']??'date'; $dir=$filters['dir']??'desc';
    if (!is_string($sort) || !is_string($dir) || !isset($orders[$sort][$dir])) { throw new DomainException('Unsupported document order.'); }
    $order=$orders[$sort][$dir]; $size=pl_table_size($filters['per_page']??25);
    $status=$filters['status']??'all';
    if (!in_array($status,['all','draft','unpaid','overdue','paid','reversed'],true)) { throw new DomainException('Choose a valid document status.'); }
    $search=pl_ledger_text($filters['q']??'','Search',160,false);
    $from=($filters['from']??'')===''?'':pl_ledger_date($filters['from']); $to=($filters['to']??'')===''?'':pl_ledger_date($filters['to']);
    if ($from!=='' && $to!=='' && $from>$to) { throw new DomainException('The beginning of the date range must be on or before its end.'); }
    return pl_ledger_transaction(function () use ($actorId,$companyId,$bookId,$side,$filters,$order,$size,$status,$search,$from,$to): array {
        pl_require_company_access($actorId,$companyId); pl_ledger_book($companyId,$bookId);
        // Complete fixed order clauses above; no browser column or direction is SQL text.
        $cte=<<<'SQL'
WITH effective AS (
 SELECT d.id,d.kind,d.reference,d.document_number,
 COALESCE(r.journal_id,d.journal_id) AS current_journal,
 COALESCE(r.open_item_id,d.open_item_id) AS current_item,
 IF(r.id IS NULL,d.party_id,CAST(JSON_UNQUOTE(JSON_EXTRACT(r.source_snapshot,'$.party_id')) AS UNSIGNED)) AS current_party,
 IF(r.id IS NULL,d.document_date,JSON_UNQUOTE(JSON_EXTRACT(r.source_snapshot,'$.document_date'))) AS effective_date,
 IF(r.id IS NULL,d.due_date,JSON_UNQUOTE(JSON_EXTRACT(r.source_snapshot,'$.due_date'))) AS current_due,
 CAST(IF(r.id IS NULL,d.total,JSON_UNQUOTE(JSON_EXTRACT(r.source_snapshot,'$.total'))) AS DECIMAL(20,4)) AS current_total
 FROM pl_ar_documents d
 LEFT JOIN pl_ar_document_revisions r ON r.document_id=d.id AND r.company_id=d.company_id AND r.book_id=d.book_id
 AND r.revision=(SELECT MAX(n.revision) FROM pl_ar_document_revisions n WHERE n.document_id=d.id)
 WHERE d.company_id=%i AND d.book_id=%i AND d.kind IN %ls
), registry AS (
 SELECT effective.*,v.id AS reversed,
 (SELECT COALESCE(SUM(CASE WHEN e.kind IN ('recognition','allocation_reversal','application_reversal') THEN COALESCE(e.allocated_amount_fc,l.amount_fc) ELSE -COALESCE(e.allocated_amount_fc,l.amount_fc) END),0)
 FROM pl_open_item_entries e JOIN pl_journal_lines l ON l.id=e.journal_line_id AND l.company_id=e.company_id AND l.book_id=e.book_id
 WHERE e.company_id=%i AND e.book_id=%i AND e.item_id=effective.current_item) AS remaining
 FROM effective LEFT JOIN pl_journals v ON v.reversal_of_id=effective.current_journal
)
SQL;
        $join=<<<'SQL'
 FROM registry q JOIN pl_parties p ON p.id=q.current_party AND p.company_id=%i
 WHERE (%s='' OR q.effective_date>=%s) AND (%s='' OR q.effective_date<=%s)
 AND (%s='' OR LOCATE(%s,p.legal_name)>0 OR LOCATE(%s,q.reference)>0
 OR LOCATE(%s,COALESCE(q.document_number,CONCAT(CASE q.kind WHEN 'invoice' THEN 'INV-' WHEN 'bill' THEN 'BILL-' WHEN 'customer_credit' THEN 'CR-' ELSE 'SC-' END,LPAD(q.id,6,'0'))))>0)
 AND (%s='all' OR (%s='draft' AND q.current_journal IS NULL)
 OR (%s='reversed' AND q.reversed IS NOT NULL)
 OR (%s='paid' AND q.current_journal IS NOT NULL AND q.reversed IS NULL AND q.kind IN ('invoice','bill') AND q.remaining=0)
 OR (%s='unpaid' AND q.current_journal IS NOT NULL AND q.reversed IS NULL AND q.kind IN ('invoice','bill') AND q.remaining>0)
 OR (%s='overdue' AND q.current_journal IS NOT NULL AND q.reversed IS NULL AND q.kind IN ('invoice','bill') AND q.remaining>0 AND q.current_due<%s))
SQL;
        $args=[$companyId,$bookId,$side==='ar'?['invoice','customer_credit']:['bill','supplier_credit'],$companyId,$bookId,$companyId,$from,$from,$to,$to,$search,$search,$search,strtoupper($search),$status,$status,$status,$status,$status,$status,gmdate('Y-m-d')];
        $total=(int)DB::queryFirstField($cte.' SELECT COUNT(*)'.$join,...$args);
        $pages=max(1,(int)ceil($total/$size)); $page=min($pages,max(1,(int)($filters['page']??1)));
        $ids=DB::queryFirstColumn($cte.' SELECT q.id'.$join.' ORDER BY '.$order.' LIMIT %i OFFSET %i',...array_merge($args,[$size,($page-1)*$size]));
        $documents=array_map(static fn($id): array=>pl_get_ar_document($actorId,$companyId,$bookId,(int)$id),$ids);
        return ['documents'=>$documents,'total'=>$total,'page'=>$page,'pages'=>$pages];
    });
}

/** Historical balances use the authoritative entries including dated reversals and opening allocations. */
function pl_ar_ap_open_items(int $actorId, int $companyId, int $bookId, string $direction, ?string $asOf = null): array
{
    if (!in_array($direction, ['receivable','payable'], true)) { throw new DomainException('Choose receivables or payables.'); }
    $asOf = pl_ledger_date($asOf ?? gmdate('Y-m-d'));
    return pl_ledger_transaction(function () use ($actorId,$companyId,$bookId,$direction,$asOf): array {
        pl_require_company_access($actorId, $companyId); pl_ledger_book($companyId, $bookId);
        // Advance items (migration 037) are unapplied credit on their own control, not trade
        // debt: a customer advance is credit-normal and would otherwise appear in the payables
        // ageing. They are reported by pl_unapplied_credit() instead.
        $rows = DB::query("SELECT i.*,p.legal_name FROM pl_open_items i JOIN pl_parties p ON p.id=i.party_id AND p.company_id=i.company_id WHERE i.company_id=%i AND i.book_id=%i AND i.direction=%s AND COALESCE(i.nature,'document')='document' ORDER BY i.id FOR SHARE", $companyId, $bookId, $direction);
        $items = []; $total = '0.0000'; $overdue = '0.0000'; $byControl = []; $currencyTotals = [];
        $buckets = array_fill_keys(['not_due','1_30','31_60','61_90','over_90'], '0.0000'); $oldest = null;
        foreach ($rows as $row) {
            $state = pl_open_item_state($companyId, $bookId, (int) $row['id']); $fc = '0.0000'; $base = '0.0000'; $date = null; $due = null;
            foreach ($state['entries'] as $entry) {
                if ($entry['journal_date'] > $asOf) { continue; }
                // Shared with pl_open_item_state(); migration 037 added the application kinds.
                $positive = in_array($entry['kind'], pl_open_item_increasing_kinds(), true);
                $fc = $positive ? bcadd($fc, $entry['amount_fc'], 4) : bcsub($fc, $entry['amount_fc'], 4);
                $base = $positive ? bcadd($base, $entry['amount_base'], 4) : bcsub($base, $entry['amount_base'], 4);
                if ($entry['kind'] === 'recognition') { $date = $entry['opening_document_date'] ?? $entry['journal_date']; $due = $entry['opening_due_date'] ?? $date; }
            }
            if (bccomp($fc, '0', 4) <= 0) { continue; }
            $version = DB::queryFirstRow('SELECT r.document_id,r.source_snapshot,d.kind FROM pl_ar_document_revisions r JOIN pl_ar_documents d ON d.id=r.document_id WHERE r.open_item_id=%i AND d.kind IN %ls ORDER BY r.id DESC LIMIT 1 FOR SHARE', $row['id'], ['invoice','bill']);
            $row['document_id'] = null; $row['number'] = $row['source_reference'];
            if ($version) { $snapshot = json_decode($version['source_snapshot'], true, 512, JSON_THROW_ON_ERROR); $date = $snapshot['document_date']; $due = $snapshot['due_date']; $row['document_id'] = (int) $version['document_id']; $row['number'] = pl_ar_document_display_number($row['document_id'], $version['kind']); }
            $days = max(0, (int) (new DateTimeImmutable($due ?? $asOf))->diff(new DateTimeImmutable($asOf))->format('%r%a'));
            $bucket = $days === 0 ? 'not_due' : ($days <= 30 ? '1_30' : ($days <= 60 ? '31_60' : ($days <= 90 ? '61_90' : 'over_90')));
            $row += ['remaining_fc'=>$fc,'remaining_base'=>$base,'document_date'=>$date,'due_date'=>$due,'age_days'=>$days,'bucket'=>$bucket];
            $row['id'] = (int) $row['id']; $items[] = $row;
            $total = bcadd($total, $base, 4); $buckets[$bucket] = bcadd($buckets[$bucket], $base, 4);
            if ($days > 0) { $overdue = bcadd($overdue, $base, 4); }
            if ($oldest === null || $date < $oldest) { $oldest = $date; }
            $byControl[$row['control_account_id']] = bcadd($byControl[$row['control_account_id']] ?? '0.0000', $base, 4);
            $currencyTotals[$row['currency']] = bcadd($currencyTotals[$row['currency']] ?? '0.0000', $fc, 4);
        }
        $controls = DB::query('SELECT a.id,a.code,a.name,COALESCE(SUM(CASE WHEN j.id IS NOT NULL THEN l.debit-l.credit ELSE 0 END),0) AS balance FROM pl_accounts a LEFT JOIN pl_journal_lines l ON l.account_id=a.id AND l.company_id=a.company_id AND l.book_id=a.book_id LEFT JOIN pl_journals j ON j.id=l.journal_id AND j.journal_date<=%s WHERE a.company_id=%i AND a.book_id=%i AND a.role=%s GROUP BY a.id,a.code,a.name ORDER BY a.code', $asOf,$companyId,$bookId,$direction === 'receivable' ? 'receivables' : 'payables');
        $difference = '0.0000';
        foreach ($controls as &$control) { $control['id'] = (int) $control['id']; $control['ledger_base'] = $direction === 'receivable' ? bcadd($control['balance'],'0',4) : bcsub('0',$control['balance'],4); $control['open_items_base'] = $byControl[$control['id']] ?? '0.0000'; $control['difference_base'] = bcsub($control['ledger_base'],$control['open_items_base'],4); $difference = bcadd($difference,$control['difference_base'],4); } unset($control);
        usort($items, static fn(array $a,array $b): int => [$a['due_date'],$a['id']] <=> [$b['due_date'],$b['id']]);
        return ['direction'=>$direction,'as_of'=>$asOf,'items'=>$items,'count'=>count($items),'total_base'=>$total,'overdue_base'=>$overdue,'oldest_date'=>$oldest,'buckets'=>$buckets,'currency_totals'=>$currencyTotals,'controls'=>$controls,'difference_base'=>$difference,'reconciled'=>count(array_filter($controls,static fn(array $c): bool => bccomp($c['difference_base'],'0',4)!==0))===0];
    });
}
