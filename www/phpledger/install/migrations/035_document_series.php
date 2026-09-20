<?php
declare(strict_types=1);

// Per-type document number series (owner decisions B37 and B55; forms-and-reports frame decision 1).
// One series per company/book/document type. Numbers are allocated inside the posting
// transaction under the book lock and recorded in an immutable allocation table, so a
// number is never reused and a rolled-back posting leaves no gap.
// Documents posted before this migration keep their derived INV-<id> form: document_number
// stays NULL for them and the display helpers fall back to the derived number.
return [
    <<<'SQL'
CREATE TABLE pl_document_series (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL, book_id BIGINT UNSIGNED NOT NULL,
 document_type VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 prefix VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 padding TINYINT UNSIGNED NOT NULL DEFAULT 6,
 year_segment BOOLEAN NOT NULL DEFAULT TRUE,
 reset_rule ENUM('never','yearly') NOT NULL DEFAULT 'yearly',
 next_number BIGINT UNSIGNED NOT NULL DEFAULT 1,
 series_year SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 revision INT UNSIGNED NOT NULL DEFAULT 1,
 created_by BIGINT UNSIGNED NOT NULL, updated_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_document_series_type (company_id, book_id, document_type),
 UNIQUE KEY uq_document_series_prefix (book_id, prefix),
 UNIQUE KEY uq_document_series_scope (id, company_id, book_id),
 CONSTRAINT fk_document_series_book FOREIGN KEY (book_id, company_id) REFERENCES pl_books (id, company_id),
 CONSTRAINT fk_document_series_creator FOREIGN KEY (created_by) REFERENCES pl_users (id),
 CONSTRAINT fk_document_series_updater FOREIGN KEY (updated_by) REFERENCES pl_users (id),
 CONSTRAINT ck_document_series_shape CHECK (padding BETWEEN 1 AND 12 AND next_number >= 1 AND revision > 0
  AND year_segment IN (0,1) AND (series_year = 0 OR series_year BETWEEN 1000 AND 9998) AND prefix <> '')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
    <<<'SQL'
CREATE TRIGGER pl_document_series_identity BEFORE UPDATE ON pl_document_series FOR EACH ROW
BEGIN
 IF NEW.id<>OLD.id OR NEW.company_id<>OLD.company_id OR NEW.book_id<>OLD.book_id
  OR BINARY NEW.document_type<>BINARY OLD.document_type OR NEW.created_by<>OLD.created_by OR NEW.created_at<>OLD.created_at THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Document series identity is immutable';
 END IF;
 IF NEW.series_year<OLD.series_year THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A document series year cannot move backwards';
 END IF;
 IF NEW.next_number<OLD.next_number AND NEW.series_year<=OLD.series_year THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The next document number can only be raised, never lowered';
 END IF;
END
SQL,
    "CREATE TRIGGER pl_document_series_no_delete BEFORE DELETE ON pl_document_series FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A document series is permanent; change its prefix or padding instead'",
    <<<'SQL'
CREATE TABLE pl_document_numbers (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 series_id BIGINT UNSIGNED NOT NULL,
 company_id BIGINT UNSIGNED NOT NULL, book_id BIGINT UNSIGNED NOT NULL,
 document_type VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 document_id BIGINT UNSIGNED NOT NULL,
 number_year SMALLINT UNSIGNED NOT NULL,
 sequence_number BIGINT UNSIGNED NOT NULL,
 number VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 allocated_by BIGINT UNSIGNED NOT NULL,
 allocated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_document_number_document (document_type, document_id),
 -- The running number repeats across years when the series resets yearly, so the
 -- gapless sequence is unique within the year its number carries.
 UNIQUE KEY uq_document_number_series (series_id, number_year, sequence_number),
 UNIQUE KEY uq_document_number_book (book_id, number),
 CONSTRAINT fk_document_number_series FOREIGN KEY (series_id, company_id, book_id) REFERENCES pl_document_series (id, company_id, book_id),
 CONSTRAINT fk_document_number_allocator FOREIGN KEY (allocated_by) REFERENCES pl_users (id),
 CONSTRAINT ck_document_number_shape CHECK (sequence_number >= 1 AND number <> '')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
    "CREATE TRIGGER pl_document_numbers_no_update BEFORE UPDATE ON pl_document_numbers FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'An allocated document number is immutable'",
    "CREATE TRIGGER pl_document_numbers_no_delete BEFORE DELETE ON pl_document_numbers FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'An allocated document number is never released; numbers are not reused'",
    "ALTER TABLE pl_ar_documents ADD COLUMN document_number VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER reference, ADD UNIQUE KEY uq_ar_document_number (book_id, document_number)",
    <<<'SQL'
INSERT INTO pl_document_series (company_id, book_id, document_type, prefix, padding, year_segment, reset_rule, next_number, series_year, created_by, updated_by)
SELECT b.company_id, b.id, t.document_type, t.prefix, 6, 1, 'yearly', 1, 0, c.created_by, c.created_by
FROM pl_books b
JOIN pl_companies c ON c.id = b.company_id
JOIN (SELECT 'invoice' AS document_type, 'INV' AS prefix
 UNION ALL SELECT 'bill', 'BILL'
 UNION ALL SELECT 'customer_credit', 'CR'
 UNION ALL SELECT 'supplier_credit', 'SC') t
LEFT JOIN pl_document_series s ON s.book_id = b.id AND s.document_type = t.document_type
WHERE s.id IS NULL
SQL,
    "ALTER TABLE pl_core_audit MODIFY entity_type ENUM('account','general_journal','product','warehouse','document_series') NOT NULL",
];
