<?php
declare(strict_types=1);

// Stock locations for the optional inventory-locations module.
// A NULL warehouse_id on an existing movement permanently means the book's default warehouse.
// Immutable movements and recorded command hashes are preserved; new writes use explicit IDs.
return [
    <<<'SQL'
CREATE TABLE pl_inventory_warehouses (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL, book_id BIGINT UNSIGNED NOT NULL,
 code VARCHAR(60) NOT NULL, name VARCHAR(160) NOT NULL,
 is_default BOOLEAN NOT NULL DEFAULT FALSE, is_active BOOLEAN NOT NULL DEFAULT TRUE,
 default_book_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN is_default THEN book_id ELSE NULL END) STORED,
 revision INT UNSIGNED NOT NULL DEFAULT 1,
 created_by BIGINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_warehouse_code (book_id, code),
 UNIQUE KEY uq_warehouse_default (default_book_id),
 UNIQUE KEY uq_warehouse_scope (id, company_id, book_id),
 FOREIGN KEY (book_id, company_id) REFERENCES pl_books(id, company_id),
 FOREIGN KEY (created_by) REFERENCES pl_users(id),
 CONSTRAINT ck_warehouse_flags CHECK (is_default IN (0,1) AND is_active IN (0,1) AND (is_default=0 OR is_active=1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
    "INSERT INTO pl_inventory_warehouses (company_id,book_id,code,name,is_default,created_by) SELECT b.company_id,b.id,'DEFAULT','Default warehouse',1,c.created_by FROM pl_books b JOIN pl_companies c ON c.id=b.company_id",
    <<<'SQL'
CREATE TRIGGER pl_inventory_warehouses_identity BEFORE UPDATE ON pl_inventory_warehouses FOR EACH ROW
BEGIN
 IF NEW.id<>OLD.id OR NEW.company_id<>OLD.company_id OR NEW.book_id<>OLD.book_id OR NEW.is_default<>OLD.is_default OR BINARY NEW.code<>BINARY OLD.code OR NEW.created_by<>OLD.created_by OR NEW.created_at<>OLD.created_at THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Warehouse identity is immutable';
 END IF;
END
SQL,
    "CREATE TRIGGER pl_inventory_warehouses_no_delete BEFORE DELETE ON pl_inventory_warehouses FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Warehouse identity is immutable; deactivate instead'",
    <<<'SQL'
ALTER TABLE pl_inventory_movements
 ADD warehouse_id BIGINT UNSIGNED NULL AFTER product_id,
 ADD CONSTRAINT fk_stock_warehouse FOREIGN KEY (warehouse_id,company_id,book_id) REFERENCES pl_inventory_warehouses(id,company_id,book_id),
 ADD KEY ix_stock_warehouse_history (book_id,product_id,warehouse_id,movement_date,id),
 MODIFY kind ENUM('receipt','issue','customer_return','purchase_return','adjustment','value_adjustment','opening','transfer_out','transfer_in') NOT NULL,
 DROP CONSTRAINT ck_stock_journal,
 ADD CONSTRAINT ck_stock_journal CHECK (kind IN ('opening','transfer_out','transfer_in') OR value_delta=0 OR journal_id IS NOT NULL),
 ADD CONSTRAINT ck_stock_transfer CHECK (
  kind NOT IN ('transfer_out','transfer_in') OR
  (warehouse_id IS NOT NULL AND journal_id IS NULL AND offset_account_id IS NULL AND opening_journal_line_id IS NULL AND
   ((kind='transfer_out' AND quantity_delta<0 AND value_delta<=0 AND original_movement_id IS NULL AND source_type='inventory_transfer_out') OR
    (kind='transfer_in' AND quantity_delta>0 AND value_delta>=0 AND original_movement_id IS NOT NULL AND source_type='inventory_transfer_in'))))
SQL,
    "ALTER TABLE pl_core_audit MODIFY entity_type ENUM('account','general_journal','product','warehouse') NOT NULL",
];
