<?php
declare(strict_types=1);

/**
 * Per-document-type number series (owner decisions B37 and B55).
 *
 * One series per company, book and document type, with the prefix and padding settable in
 * Admin and no per-warehouse override. A number is allocated inside the posting transaction,
 * under the book lock and with FOR UPDATE on the series row, and recorded in the immutable
 * pl_document_numbers table. Drafts stay unnumbered; a rolled-back posting releases the row
 * lock with the counter unchanged, so the next posting takes the same number and no gap
 * appears. Documents posted before migration 035 keep their derived INV-<id> form.
 */
function pl_document_series_types(): array
{
    return [
        'invoice' => ['label' => 'Customer invoice', 'prefix' => 'INV'],
        'bill' => ['label' => 'Supplier bill', 'prefix' => 'BILL'],
        'customer_credit' => ['label' => 'Customer credit note', 'prefix' => 'CR'],
        'supplier_credit' => ['label' => 'Supplier credit note', 'prefix' => 'SC'],
        // Stock documents (1.2 M4, migration 037). Research decision 1: a separate series
        // per document kind, with the prefix settable here in Admin > Numbering.
        'stock_issue' => ['label' => 'Stock issue', 'prefix' => 'ISS'],
        'stock_reissue' => ['label' => 'Stock re-issue', 'prefix' => 'RISS'],
        'stock_return' => ['label' => 'Stock return from van', 'prefix' => 'RTN'],
        'gate_pass' => ['label' => 'Gate pass', 'prefix' => 'GP'],
    ];
}

/** `INV-2026-000123` with the year segment, `INV-000123` without it. */
function pl_document_series_format(array $series, int $year, int $number): string
{
    if ($number < 1) {
        throw new DomainException('A document number must be positive.');
    }
    $parts = [(string) $series['prefix']];
    if ((bool) $series['year_segment']) {
        $parts[] = (string) $year;
    }
    $parts[] = str_pad((string) $number, (int) $series['padding'], '0', STR_PAD_LEFT);
    return implode('-', $parts);
}

/** Present the stored series row; a book created after migration 035 gets the recommended default. */
function pl_document_series_row(int $actorId, int $companyId, int $bookId, string $type, bool $lock = false): array
{
    $types = pl_document_series_types();
    if (!isset($types[$type])) {
        throw new DomainException('This document type has no number series.');
    }
    $sql = 'SELECT * FROM pl_document_series WHERE company_id = %i AND book_id = %i AND document_type = %s';
    $row = DB::queryFirstRow($sql . ($lock ? ' FOR UPDATE' : ' FOR SHARE'), $companyId, $bookId, $type);
    if (!$row) {
        DB::insertIgnore('pl_document_series', [
            'company_id' => $companyId, 'book_id' => $bookId, 'document_type' => $type,
            'prefix' => $types[$type]['prefix'], 'padding' => 6, 'year_segment' => 1,
            'reset_rule' => 'yearly', 'next_number' => 1, 'series_year' => 0,
            'created_by' => $actorId, 'updated_by' => $actorId,
        ]);
        $row = DB::queryFirstRow($sql . ($lock ? ' FOR UPDATE' : ' FOR SHARE'), $companyId, $bookId, $type);
        if (!$row) {
            throw new DomainException('The number series for this document type could not be prepared.');
        }
    }
    return $row;
}

function pl_document_series_view(array $row): array
{
    $view = [
        'id' => (int) $row['id'], 'company_id' => (int) $row['company_id'], 'book_id' => (int) $row['book_id'],
        'document_type' => (string) $row['document_type'],
        'label' => pl_document_series_types()[(string) $row['document_type']]['label'] ?? (string) $row['document_type'],
        'prefix' => (string) $row['prefix'], 'padding' => (int) $row['padding'],
        'year_segment' => (bool) $row['year_segment'], 'reset_rule' => (string) $row['reset_rule'],
        'next_number' => (int) $row['next_number'], 'series_year' => (int) $row['series_year'],
        'revision' => (int) $row['revision'],
    ];
    // series_year 0 means this series has issued nothing yet: it adopts the year of its first document.
    $view['example'] = pl_document_series_format($row, $view['series_year'] === 0 ? (int) gmdate('Y') : $view['series_year'], $view['next_number']);
    return $view;
}

/** @return list<array<string,mixed>> one row per document type, in the catalogue's order. */
function pl_list_document_series(int $actorId, int $companyId, int $bookId): array
{
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId): array {
        pl_require_company_access($actorId, $companyId);
        pl_ledger_book($companyId, $bookId);
        $rows = [];
        foreach (array_keys(pl_document_series_types()) as $type) {
            $rows[] = pl_document_series_view(pl_document_series_row($actorId, $companyId, $bookId, $type));
        }
        return $rows;
    });
}

/**
 * The prefix, padding, year segment and reset rule may change; the next number may only be
 * raised. Lowering it would re-issue a number that a customer already holds, so it is refused
 * whatever the caller asks for, and the guard trigger refuses it again in the database.
 */
function pl_save_document_series(int $actorId, int $companyId, int $bookId, string $type, array $input): array
{
    pl_demo_require_setup_action();
    $reason = pl_ledger_text($input['reason'] ?? null, 'Reason for this change', 500);
    $prefix = pl_ledger_text($input['prefix'] ?? null, 'Series prefix', 10);
    if (!preg_match('/^[A-Z][A-Z0-9]{0,9}$/D', $prefix)) {
        throw new DomainException('Use one to ten capital letters or digits for the prefix, starting with a letter.');
    }
    $padding = $input['padding'] ?? null;
    if (!is_int($padding) || $padding < 1 || $padding > 12) {
        throw new DomainException('Choose a running-number width between 1 and 12 digits.');
    }
    if (!is_bool($input['year_segment'] ?? null)) {
        throw new DomainException('Choose whether the number carries a year segment.');
    }
    $yearSegment = $input['year_segment'];
    $resetRule = $input['reset_rule'] ?? null;
    if (!in_array($resetRule, ['never', 'yearly'], true)) {
        throw new DomainException('Choose whether the running number never resets or resets every year.');
    }
    if ($resetRule === 'yearly' && !$yearSegment) {
        throw new DomainException('A yearly reset needs the year segment; without it the same number would be issued again next year.');
    }
    $nextNumber = $input['next_number'] ?? null;
    if (!is_int($nextNumber) || $nextNumber < 1) {
        throw new DomainException('Enter the next number as a positive whole number.');
    }
    $revision = $input['revision'] ?? null;
    if (!is_int($revision) || $revision < 1) {
        throw new DomainException('Reload the numbering screen before saving.');
    }
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $type, $reason, $prefix, $padding, $yearSegment, $resetRule, $nextNumber, $revision): array {
        $member = pl_require_company_access($actorId, $companyId, true);
        if (!pl_user_can($actorId, $companyId, 'numbering.manage')) {
            throw new DomainException('Only the business owner can change document numbering.');
        }
        pl_ledger_book($companyId, $bookId, true);
        $current = pl_document_series_row($actorId, $companyId, $bookId, $type, true);
        $before = pl_document_series_view($current);
        if ($before['revision'] !== $revision) {
            throw new DomainException('Someone changed this series. Your values are retained; reload the current numbering before applying your changes.');
        }
        if ($nextNumber < $before['next_number']) {
            throw new DomainException('The next number can only be raised, never lowered. This series is already at ' . $before['next_number'] . '.');
        }
        if ($prefix !== $before['prefix'] && DB::queryFirstField('SELECT id FROM pl_document_series WHERE book_id = %i AND prefix = %s AND id <> %i FOR SHARE', $bookId, $prefix, $before['id'])) {
            throw new DomainException('Another document type in this book already uses that prefix.');
        }
        DB::update('pl_document_series', [
            'prefix' => $prefix, 'padding' => $padding, 'year_segment' => $yearSegment ? 1 : 0,
            'reset_rule' => $resetRule, 'next_number' => $nextNumber,
            'revision' => $before['revision'] + 1, 'updated_by' => $actorId, 'updated_at' => gmdate('Y-m-d H:i:s'),
        ], 'id = %i AND company_id = %i AND book_id = %i', $before['id'], $companyId, $bookId);
        $after = pl_document_series_view(pl_document_series_row($actorId, $companyId, $bookId, $type));
        pl_core_audit($actorId, $companyId, $bookId, 'document_series', $after['id'], 'updated', $reason, $before, $after);
        return $after;
    });
}

/**
 * Allocate this document's permanent number. Idempotent: a retry of the same posting returns
 * the number already recorded for the document rather than consuming another one.
 */
function pl_document_series_allocate(int $actorId, int $companyId, int $bookId, string $type, int $documentId, string $date): string
{
    if ($documentId < 1) {
        throw new DomainException('A document number belongs to a saved document.');
    }
    $date = pl_ledger_date($date);
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $type, $documentId, $date): string {
        pl_require_company_access($actorId, $companyId, true);
        // The posting transaction already holds this lock; taking it here keeps the allocator
        // correct for any later caller and orders every allocation in the book behind it.
        pl_ledger_book($companyId, $bookId, true);
        $existing = DB::queryFirstRow('SELECT number FROM pl_document_numbers WHERE document_type = %s AND document_id = %i FOR UPDATE', $type, $documentId);
        if ($existing) {
            return (string) $existing['number'];
        }
        $series = pl_document_series_row($actorId, $companyId, $bookId, $type, true);
        $year = (int) substr($date, 0, 4);
        $seriesYear = (int) $series['series_year'];
        $next = (int) $series['next_number'];
        // A series that has issued nothing adopts the year of its first document, so a fresh
        // company can still record historical books; afterwards the year only moves forward.
        if ($seriesYear === 0) {
            $seriesYear = $year;
        }
        if ((string) $series['reset_rule'] === 'yearly' && $year < $seriesYear) {
            throw new DomainException('This series resets every year and has already issued numbers for ' . $seriesYear . '. Date this document in ' . $seriesYear . ' or later, or set its reset rule to "never" in Admin > Numbering.');
        }
        if ($year > $seriesYear) {
            if ((string) $series['reset_rule'] === 'yearly') {
                $next = 1;
            }
            $seriesYear = $year;
        }
        $numberYear = (bool) $series['year_segment'] ? $year : $seriesYear;
        $number = pl_document_series_format($series, $numberYear, $next);
        DB::insert('pl_document_numbers', [
            'series_id' => (int) $series['id'], 'company_id' => $companyId, 'book_id' => $bookId,
            'document_type' => $type, 'document_id' => $documentId,
            'number_year' => $numberYear, 'sequence_number' => $next, 'number' => $number, 'allocated_by' => $actorId,
        ]);
        DB::update('pl_document_series', [
            'next_number' => $next + 1, 'series_year' => $seriesYear,
            'updated_by' => $actorId, 'updated_at' => gmdate('Y-m-d H:i:s'),
        ], 'id = %i AND company_id = %i AND book_id = %i', (int) $series['id'], $companyId, $bookId);
        return $number;
    });
}

function pl_document_number_lookup(string $type, int $documentId): ?string
{
    if ($documentId < 1) {
        return null;
    }
    $number = DB::queryFirstField('SELECT number FROM pl_document_numbers WHERE document_type = %s AND document_id = %i', $type, $documentId);
    return $number === null ? null : (string) $number;
}

/** The stored number when this document has one, otherwise the pre-035 derived form. */
function pl_document_number_display(?string $stored, int $documentId, string $type): string
{
    if (is_string($stored) && $stored !== '') {
        return $stored;
    }
    return pl_ar_document_number($documentId, $type);
}

/** Display helper for call sites that hold only the document identity. */
function pl_ar_document_display_number(int $documentId, string $kind): string
{
    return pl_document_number_display(pl_document_number_lookup($kind, $documentId), $documentId, $kind);
}
