<?php
declare(strict_types=1);

// Numbered stock documents over the existing stock movements (1.2 M4; owner decisions
// B33, B34, B35, B36; docs/design/1.2-2026-09/distribution-research/DOCUMENT-MODEL.md).
//
// A warehouse gains a `kind`: `fixed` for a building, `mobile` for a van, which carries a
// driver, a vehicle reference and a route. The book's permanent default warehouse stays
// `fixed`, so the existing default-warehouse rule is untouched.
//
// pl_stock_documents is a thin header above pl_inventory_movements, exactly as an AR
// document sits above its journal: one row per document, numbered from the 035 series per
// document kind, with pl_stock_document_lines naming the movement pair each line created.
// A gate pass is a numbered document of the same family that creates no line and no
// movement at all: it references the stock document that actually moved the goods
// (research decision 2). pl_gate_passes carries its security-desk fields.
//
// pl_van_settlements records the end-of-day review of one mobile warehouse's day. The
// approval is a distinct act (research decision 6); until the Users module lands, the
// service requires the company owner and this table keeps the approver and the moment.
//
// Nothing here changes an existing movement, journal or command receipt.
return [
    <<<'SQL'
ALTER TABLE pl_inventory_warehouses
 ADD COLUMN kind ENUM('fixed','mobile') NOT NULL DEFAULT 'fixed' AFTER name,
 ADD COLUMN driver_name VARCHAR(160) NULL AFTER kind,
 ADD COLUMN vehicle_reference VARCHAR(60) NULL AFTER driver_name,
 ADD COLUMN route_name VARCHAR(160) NULL AFTER vehicle_reference,
 ADD CONSTRAINT ck_warehouse_kind CHECK (
  (is_default = 0 OR kind = 'fixed')
  AND (kind = 'mobile' OR (driver_name IS NULL AND vehicle_reference IS NULL AND route_name IS NULL))
  AND (kind = 'fixed' OR driver_name IS NOT NULL))
SQL,
    'DROP TRIGGER pl_inventory_warehouses_identity',
    <<<'SQL'
CREATE TRIGGER pl_inventory_warehouses_identity BEFORE UPDATE ON pl_inventory_warehouses FOR EACH ROW
BEGIN
 IF NEW.id<>OLD.id OR NEW.company_id<>OLD.company_id OR NEW.book_id<>OLD.book_id OR NEW.is_default<>OLD.is_default OR BINARY NEW.code<>BINARY OLD.code OR NEW.created_by<>OLD.created_by OR NEW.created_at<>OLD.created_at THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Warehouse identity is immutable';
 END IF;
 IF NEW.kind<>OLD.kind THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A warehouse cannot change between a building and a van; create a separate location';
 END IF;
END
SQL,
    <<<'SQL'
CREATE TABLE pl_stock_documents (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL, book_id BIGINT UNSIGNED NOT NULL,
 document_kind ENUM('stock_issue','stock_reissue','stock_return','gate_pass') NOT NULL,
 document_number VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
 document_date DATE NOT NULL,
 from_warehouse_id BIGINT UNSIGNED NULL,
 to_warehouse_id BIGINT UNSIGNED NULL,
 original_document_id BIGINT UNSIGNED NULL,
 reference VARCHAR(120) NOT NULL DEFAULT '',
 reason VARCHAR(500) NOT NULL,
 request_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_stock_document_number (book_id, document_number),
 UNIQUE KEY uq_stock_document_key (book_id, request_key),
 UNIQUE KEY uq_stock_document_scope (id, company_id, book_id),
 KEY ix_stock_document_day (book_id, document_kind, document_date, id),
 KEY ix_stock_document_from (book_id, from_warehouse_id, document_date, id),
 KEY ix_stock_document_to (book_id, to_warehouse_id, document_date, id),
 CONSTRAINT fk_stock_document_book FOREIGN KEY (book_id, company_id) REFERENCES pl_books (id, company_id),
 CONSTRAINT fk_stock_document_from FOREIGN KEY (from_warehouse_id, company_id, book_id) REFERENCES pl_inventory_warehouses (id, company_id, book_id),
 CONSTRAINT fk_stock_document_to FOREIGN KEY (to_warehouse_id, company_id, book_id) REFERENCES pl_inventory_warehouses (id, company_id, book_id),
 CONSTRAINT fk_stock_document_original FOREIGN KEY (original_document_id, company_id, book_id) REFERENCES pl_stock_documents (id, company_id, book_id),
 CONSTRAINT fk_stock_document_creator FOREIGN KEY (created_by) REFERENCES pl_users (id),
 -- A moving document names two different locations; a gate pass names the gate it crosses
 -- and never a destination, because it moves nothing.
 CONSTRAINT ck_stock_document_shape CHECK (
  (document_kind = 'gate_pass'
   AND from_warehouse_id IS NOT NULL AND to_warehouse_id IS NULL AND original_document_id IS NULL)
  OR (document_kind <> 'gate_pass'
   AND from_warehouse_id IS NOT NULL AND to_warehouse_id IS NOT NULL AND from_warehouse_id <> to_warehouse_id
   AND (document_kind <> 'stock_reissue' OR original_document_id IS NOT NULL)
   AND (document_kind = 'stock_reissue' OR original_document_id IS NULL)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
    <<<'SQL'
CREATE TRIGGER pl_stock_documents_immutable BEFORE UPDATE ON pl_stock_documents FOR EACH ROW
BEGIN
 IF NEW.id<>OLD.id OR NEW.company_id<>OLD.company_id OR NEW.book_id<>OLD.book_id
  OR NEW.document_kind<>OLD.document_kind OR NEW.document_date<>OLD.document_date
  OR NOT (NEW.from_warehouse_id<=>OLD.from_warehouse_id) OR NOT (NEW.to_warehouse_id<=>OLD.to_warehouse_id)
  OR NOT (NEW.original_document_id<=>OLD.original_document_id)
  OR BINARY NEW.reference<>BINARY OLD.reference OR BINARY NEW.reason<>BINARY OLD.reason
  OR BINARY NEW.request_key<>BINARY OLD.request_key
  OR NEW.created_by<>OLD.created_by OR NEW.created_at<>OLD.created_at THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A recorded stock document is immutable';
 END IF;
 IF OLD.document_number IS NOT NULL AND NOT (NEW.document_number<=>OLD.document_number) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'An allocated stock document number is never changed or reused';
 END IF;
END
SQL,
    "CREATE TRIGGER pl_stock_documents_no_delete BEFORE DELETE ON pl_stock_documents FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A recorded stock document is permanent; record the opposite document instead'",
    <<<'SQL'
CREATE TABLE pl_stock_document_lines (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 document_id BIGINT UNSIGNED NOT NULL,
 company_id BIGINT UNSIGNED NOT NULL, book_id BIGINT UNSIGNED NOT NULL,
 line_number INT UNSIGNED NOT NULL,
 product_id BIGINT UNSIGNED NOT NULL,
 quantity DECIMAL(24,4) NOT NULL,
 value_base DECIMAL(24,4) NOT NULL,
 out_movement_id BIGINT UNSIGNED NOT NULL,
 in_movement_id BIGINT UNSIGNED NOT NULL,
 UNIQUE KEY uq_stock_document_line (document_id, line_number),
 UNIQUE KEY uq_stock_document_line_out (out_movement_id),
 UNIQUE KEY uq_stock_document_line_in (in_movement_id),
 KEY ix_stock_document_line_product (book_id, product_id, document_id),
 CONSTRAINT fk_stock_line_document FOREIGN KEY (document_id, company_id, book_id) REFERENCES pl_stock_documents (id, company_id, book_id),
 CONSTRAINT fk_stock_line_product FOREIGN KEY (product_id) REFERENCES pl_products (id),
 CONSTRAINT fk_stock_line_out FOREIGN KEY (out_movement_id) REFERENCES pl_inventory_movements (id),
 CONSTRAINT fk_stock_line_in FOREIGN KEY (in_movement_id) REFERENCES pl_inventory_movements (id),
 CONSTRAINT ck_stock_line_shape CHECK (line_number >= 1 AND quantity > 0 AND value_base >= 0 AND out_movement_id <> in_movement_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
    "CREATE TRIGGER pl_stock_document_lines_no_update BEFORE UPDATE ON pl_stock_document_lines FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A recorded stock document line is immutable'",
    "CREATE TRIGGER pl_stock_document_lines_no_delete BEFORE DELETE ON pl_stock_document_lines FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A recorded stock document line is permanent'",
    <<<'SQL'
CREATE TABLE pl_gate_passes (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 document_id BIGINT UNSIGNED NOT NULL,
 company_id BIGINT UNSIGNED NOT NULL, book_id BIGINT UNSIGNED NOT NULL,
 direction ENUM('out','in') NOT NULL,
 is_returnable BOOLEAN NOT NULL DEFAULT TRUE,
 covers_document_id BIGINT UNSIGNED NULL,
 party_name VARCHAR(160) NOT NULL DEFAULT '',
 vehicle_reference VARCHAR(60) NOT NULL DEFAULT '',
 driver_name VARCHAR(160) NOT NULL DEFAULT '',
 purpose VARCHAR(500) NOT NULL,
 expected_return_date DATE NULL,
 authorised_by BIGINT UNSIGNED NOT NULL,
 security_out_at DATETIME NULL, security_in_at DATETIME NULL,
 UNIQUE KEY uq_gate_pass_document (document_id),
 UNIQUE KEY uq_gate_pass_scope (id, company_id, book_id),
 KEY ix_gate_pass_covers (book_id, covers_document_id),
 CONSTRAINT fk_gate_pass_document FOREIGN KEY (document_id, company_id, book_id) REFERENCES pl_stock_documents (id, company_id, book_id),
 CONSTRAINT fk_gate_pass_covers FOREIGN KEY (covers_document_id, company_id, book_id) REFERENCES pl_stock_documents (id, company_id, book_id),
 CONSTRAINT fk_gate_pass_authoriser FOREIGN KEY (authorised_by) REFERENCES pl_users (id),
 -- A returnable pass states when the goods are expected back; a non-returnable one never does.
 CONSTRAINT ck_gate_pass_shape CHECK (is_returnable IN (0,1)
  AND (is_returnable = 1 OR expected_return_date IS NULL)
  AND (direction = 'out' OR security_out_at IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
    <<<'SQL'
CREATE TRIGGER pl_gate_passes_immutable BEFORE UPDATE ON pl_gate_passes FOR EACH ROW
BEGIN
 IF NEW.id<>OLD.id OR NEW.document_id<>OLD.document_id OR NEW.company_id<>OLD.company_id OR NEW.book_id<>OLD.book_id
  OR NEW.direction<>OLD.direction OR NEW.is_returnable<>OLD.is_returnable
  OR NOT (NEW.covers_document_id<=>OLD.covers_document_id)
  OR BINARY NEW.party_name<>BINARY OLD.party_name OR BINARY NEW.vehicle_reference<>BINARY OLD.vehicle_reference
  OR BINARY NEW.driver_name<>BINARY OLD.driver_name OR BINARY NEW.purpose<>BINARY OLD.purpose
  OR NOT (NEW.expected_return_date<=>OLD.expected_return_date) OR NEW.authorised_by<>OLD.authorised_by THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A recorded gate pass is immutable; the security desk may only stamp it';
 END IF;
 IF (OLD.security_out_at IS NOT NULL AND NOT (NEW.security_out_at<=>OLD.security_out_at))
  OR (OLD.security_in_at IS NOT NULL AND NOT (NEW.security_in_at<=>OLD.security_in_at)) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A security stamp is recorded once';
 END IF;
END
SQL,
    "CREATE TRIGGER pl_gate_passes_no_delete BEFORE DELETE ON pl_gate_passes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A recorded gate pass is permanent'",
    <<<'SQL'
CREATE TABLE pl_van_settlements (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL, book_id BIGINT UNSIGNED NOT NULL,
 warehouse_id BIGINT UNSIGNED NOT NULL,
 settlement_date DATE NOT NULL,
 status ENUM('reviewed','approved') NOT NULL DEFAULT 'reviewed',
 reconciles BOOLEAN NOT NULL,
 summary_json JSON NOT NULL,
 reason VARCHAR(500) NOT NULL,
 reviewed_by BIGINT UNSIGNED NOT NULL, reviewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 approved_by BIGINT UNSIGNED NULL, approved_at DATETIME NULL,
 UNIQUE KEY uq_van_settlement_day (book_id, warehouse_id, settlement_date),
 CONSTRAINT fk_van_settlement_book FOREIGN KEY (book_id, company_id) REFERENCES pl_books (id, company_id),
 CONSTRAINT fk_van_settlement_warehouse FOREIGN KEY (warehouse_id, company_id, book_id) REFERENCES pl_inventory_warehouses (id, company_id, book_id),
 CONSTRAINT fk_van_settlement_reviewer FOREIGN KEY (reviewed_by) REFERENCES pl_users (id),
 CONSTRAINT fk_van_settlement_approver FOREIGN KEY (approved_by) REFERENCES pl_users (id),
 CONSTRAINT ck_van_settlement_shape CHECK (reconciles IN (0,1)
  AND ((status = 'reviewed' AND approved_by IS NULL AND approved_at IS NULL)
   OR (status = 'approved' AND approved_by IS NOT NULL AND approved_at IS NOT NULL)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
    <<<'SQL'
CREATE TRIGGER pl_van_settlements_immutable BEFORE UPDATE ON pl_van_settlements FOR EACH ROW
BEGIN
 IF OLD.status = 'approved' THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'An approved settlement is immutable; review the next day or record a correcting document';
 END IF;
 IF NEW.id<>OLD.id OR NEW.company_id<>OLD.company_id OR NEW.book_id<>OLD.book_id
  OR NEW.warehouse_id<>OLD.warehouse_id OR NEW.settlement_date<>OLD.settlement_date
  OR NEW.reviewed_by<>OLD.reviewed_by OR NEW.reviewed_at<>OLD.reviewed_at THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Settlement identity is immutable';
 END IF;
END
SQL,
    "CREATE TRIGGER pl_van_settlements_no_delete BEFORE DELETE ON pl_van_settlements FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A settlement record is permanent'",
    // One series per stock-document kind, on the 035 machinery, for every existing book.
    <<<'SQL'
INSERT INTO pl_document_series (company_id, book_id, document_type, prefix, padding, year_segment, reset_rule, next_number, series_year, created_by, updated_by)
SELECT b.company_id, b.id, t.document_type, t.prefix, 6, 1, 'yearly', 1, 0, c.created_by, c.created_by
FROM pl_books b
JOIN pl_companies c ON c.id = b.company_id
JOIN (SELECT 'stock_issue' AS document_type, 'ISS' AS prefix
 UNION ALL SELECT 'stock_reissue', 'RISS'
 UNION ALL SELECT 'stock_return', 'RTN'
 UNION ALL SELECT 'gate_pass', 'GP') t
LEFT JOIN pl_document_series s ON s.book_id = b.id AND s.document_type = t.document_type
WHERE s.id IS NULL
SQL,
    "ALTER TABLE pl_core_audit MODIFY entity_type ENUM('account','general_journal','product','warehouse','document_series','stock_document','gate_pass','van_settlement') NOT NULL",
];
