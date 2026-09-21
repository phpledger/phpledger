<?php
declare(strict_types=1);

// Trading documents (release plan 1.2 M3; owner decisions B37, B53, B55, B64).
//
// A bundled module cannot extend another module's tables from outside, so — exactly as
// migration 034 did for stock movements — AR's own tables gain neutral-default columns
// here and every existing row keeps its meaning:
//   * a NULL sales_staff_id, area_id, warehouse_id means "not recorded" (documents
//     posted before this migration carry no dimension and never will);
//   * cash_received 0 with a NULL cash account and NULL settlement journal means
//     "no cash was taken on the document itself";
//   * discount_total and free_tax_total 0 mean "no line discount, no free goods";
//   * on a line, gross_amount 0 is read as "gross equals line_total" (the pre-M3 shape),
//     because the posted-line trigger from 017 forbids backfilling a posted row.
//
// The line CHECK is replaced so a line flagged as free goods may be zero-valued while
// every other line keeps the positive quantity/price/total rule 017 established.
return [
    <<<'SQL'
ALTER TABLE pl_ar_documents
 ADD sales_staff_id BIGINT UNSIGNED NULL,
 ADD area_id BIGINT UNSIGNED NULL,
 ADD warehouse_id BIGINT UNSIGNED NULL,
 ADD cash_received DECIMAL(20,4) NOT NULL DEFAULT 0,
 ADD cash_account_id BIGINT UNSIGNED NULL,
 ADD cash_settlement_journal_id BIGINT UNSIGNED NULL,
 ADD discount_total DECIMAL(20,4) NOT NULL DEFAULT 0,
 ADD free_tax_total DECIMAL(20,4) NOT NULL DEFAULT 0,
 ADD CONSTRAINT ck_ar_document_cash CHECK (cash_received >= 0 AND discount_total >= 0 AND free_tax_total >= 0
  AND (cash_received = 0 OR cash_account_id IS NOT NULL)),
 ADD CONSTRAINT fk_ar_document_cash_account FOREIGN KEY (cash_account_id, company_id, book_id) REFERENCES pl_accounts (id, company_id, book_id),
 ADD CONSTRAINT fk_ar_document_cash_journal FOREIGN KEY (cash_settlement_journal_id, company_id, book_id) REFERENCES pl_journals (id, company_id, book_id),
 ADD CONSTRAINT fk_ar_document_warehouse FOREIGN KEY (warehouse_id, company_id, book_id) REFERENCES pl_inventory_warehouses (id, company_id, book_id)
SQL,
    <<<'SQL'
CREATE TABLE pl_sales_staff (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL, book_id BIGINT UNSIGNED NOT NULL,
 code VARCHAR(40) NOT NULL, name VARCHAR(160) NOT NULL,
 is_active BOOLEAN NOT NULL DEFAULT TRUE,
 revision INT UNSIGNED NOT NULL DEFAULT 1,
 created_by BIGINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_sales_staff_code (book_id, code),
 UNIQUE KEY uq_sales_staff_scope (id, company_id, book_id),
 CONSTRAINT ck_sales_staff_flags CHECK (is_active IN (0,1) AND revision > 0),
 CONSTRAINT fk_sales_staff_book FOREIGN KEY (book_id, company_id) REFERENCES pl_books (id, company_id),
 CONSTRAINT fk_sales_staff_creator FOREIGN KEY (created_by) REFERENCES pl_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
    <<<'SQL'
CREATE TABLE pl_areas (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL, book_id BIGINT UNSIGNED NOT NULL,
 code VARCHAR(40) NOT NULL, name VARCHAR(160) NOT NULL,
 is_active BOOLEAN NOT NULL DEFAULT TRUE,
 revision INT UNSIGNED NOT NULL DEFAULT 1,
 created_by BIGINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_area_code (book_id, code),
 UNIQUE KEY uq_area_scope (id, company_id, book_id),
 CONSTRAINT ck_area_flags CHECK (is_active IN (0,1) AND revision > 0),
 CONSTRAINT fk_area_book FOREIGN KEY (book_id, company_id) REFERENCES pl_books (id, company_id),
 CONSTRAINT fk_area_creator FOREIGN KEY (created_by) REFERENCES pl_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
    <<<'SQL'
CREATE TABLE pl_product_packs (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL, book_id BIGINT UNSIGNED NOT NULL,
 product_id BIGINT UNSIGNED NOT NULL,
 code VARCHAR(40) NOT NULL, name VARCHAR(160) NOT NULL,
 units_per_pack DECIMAL(20,4) NOT NULL,
 is_active BOOLEAN NOT NULL DEFAULT TRUE,
 revision INT UNSIGNED NOT NULL DEFAULT 1,
 created_by BIGINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_product_pack_code (book_id, product_id, code),
 UNIQUE KEY uq_product_pack_scope (id, company_id, book_id),
 CONSTRAINT ck_product_pack_size CHECK (units_per_pack > 0 AND is_active IN (0,1) AND revision > 0),
 CONSTRAINT fk_product_pack_product FOREIGN KEY (product_id, company_id, book_id) REFERENCES pl_products (id, company_id, book_id),
 CONSTRAINT fk_product_pack_creator FOREIGN KEY (created_by) REFERENCES pl_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
    // A pack size is frozen once created: a posted line stores only its resolved base
    // quantity, so a changed units_per_pack would silently restate history.
    <<<'SQL'
CREATE TRIGGER pl_product_packs_identity BEFORE UPDATE ON pl_product_packs FOR EACH ROW
BEGIN
 IF NEW.id<>OLD.id OR NEW.company_id<>OLD.company_id OR NEW.book_id<>OLD.book_id OR NEW.product_id<>OLD.product_id
  OR BINARY NEW.code<>BINARY OLD.code OR NEW.units_per_pack<>OLD.units_per_pack
  OR NEW.created_by<>OLD.created_by OR NEW.created_at<>OLD.created_at THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pack identity and size are immutable; add another pack instead';
 END IF;
END
SQL,
    "CREATE TRIGGER pl_product_packs_no_delete BEFORE DELETE ON pl_product_packs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pack identity is immutable; deactivate instead'",
    <<<'SQL'
CREATE TRIGGER pl_sales_staff_identity BEFORE UPDATE ON pl_sales_staff FOR EACH ROW
BEGIN
 IF NEW.id<>OLD.id OR NEW.company_id<>OLD.company_id OR NEW.book_id<>OLD.book_id OR BINARY NEW.code<>BINARY OLD.code
  OR NEW.created_by<>OLD.created_by OR NEW.created_at<>OLD.created_at THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sales staff identity is immutable';
 END IF;
END
SQL,
    "CREATE TRIGGER pl_sales_staff_no_delete BEFORE DELETE ON pl_sales_staff FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sales staff identity is immutable; deactivate instead'",
    <<<'SQL'
CREATE TRIGGER pl_areas_identity BEFORE UPDATE ON pl_areas FOR EACH ROW
BEGIN
 IF NEW.id<>OLD.id OR NEW.company_id<>OLD.company_id OR NEW.book_id<>OLD.book_id OR BINARY NEW.code<>BINARY OLD.code
  OR NEW.created_by<>OLD.created_by OR NEW.created_at<>OLD.created_at THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Area identity is immutable';
 END IF;
END
SQL,
    "CREATE TRIGGER pl_areas_no_delete BEFORE DELETE ON pl_areas FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Area identity is immutable; deactivate instead'",
    <<<'SQL'
ALTER TABLE pl_ar_documents
 ADD CONSTRAINT fk_ar_document_sales_staff FOREIGN KEY (sales_staff_id, company_id, book_id) REFERENCES pl_sales_staff (id, company_id, book_id),
 ADD CONSTRAINT fk_ar_document_area FOREIGN KEY (area_id, company_id, book_id) REFERENCES pl_areas (id, company_id, book_id)
SQL,
    <<<'SQL'
ALTER TABLE pl_ar_document_lines
 ADD pack_id BIGINT UNSIGNED NULL,
 ADD pack_quantity DECIMAL(20,4) NOT NULL DEFAULT 0,
 ADD unit_quantity DECIMAL(20,4) NOT NULL DEFAULT 0,
 ADD gross_amount DECIMAL(20,4) NOT NULL DEFAULT 0,
 ADD discount_percent DECIMAL(7,4) NOT NULL DEFAULT 0,
 ADD discount_amount DECIMAL(20,4) NOT NULL DEFAULT 0,
 ADD is_free_goods TINYINT(1) NOT NULL DEFAULT 0,
 ADD CONSTRAINT fk_ar_line_pack FOREIGN KEY (pack_id, company_id, book_id) REFERENCES pl_product_packs (id, company_id, book_id),
 DROP CONSTRAINT ck_ar_line_values,
 ADD CONSTRAINT ck_ar_line_values CHECK (
  quantity > 0 AND (
   (is_free_goods = 0 AND unit_price > 0 AND line_total > 0)
   OR (is_free_goods = 1 AND unit_price >= 0 AND line_total = 0))),
 ADD CONSTRAINT ck_ar_line_trading CHECK (
  is_free_goods IN (0,1) AND pack_quantity >= 0 AND unit_quantity >= 0 AND gross_amount >= 0
  AND discount_percent >= 0 AND discount_percent <= 100 AND discount_amount >= 0
  AND (gross_amount = 0 OR discount_amount <= gross_amount)
  AND (pack_id IS NOT NULL OR pack_quantity = 0))
SQL,
    <<<'SQL'
CREATE TABLE pl_company_profile (
 company_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 legal_name VARCHAR(200) NOT NULL DEFAULT '',
 address_line1 VARCHAR(200) NOT NULL DEFAULT '',
 address_line2 VARCHAR(200) NOT NULL DEFAULT '',
 address_line3 VARCHAR(200) NOT NULL DEFAULT '',
 phone VARCHAR(80) NOT NULL DEFAULT '',
 email VARCHAR(190) NOT NULL DEFAULT '',
 tax_registrations VARCHAR(300) NOT NULL DEFAULT '',
 footer_terms TEXT NOT NULL,
 revision INT UNSIGNED NOT NULL DEFAULT 1,
 updated_by BIGINT UNSIGNED NOT NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT ck_company_profile_revision CHECK (revision > 0),
 CONSTRAINT fk_company_profile_company FOREIGN KEY (company_id) REFERENCES pl_companies (id),
 CONSTRAINT fk_company_profile_actor FOREIGN KEY (updated_by) REFERENCES pl_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
    // Policy rows in company settings (B37). Every value carries the recommended default,
    // so a book that never opens Admin > Accounting policies behaves exactly as 1.1 did:
    // net discount posting, no free-goods output tax, and no cash accepted on an invoice.
    <<<'SQL'
CREATE TABLE pl_trading_policies (
 book_id BIGINT UNSIGNED NOT NULL PRIMARY KEY, company_id BIGINT UNSIGNED NOT NULL,
 discount_posting ENUM('net','gross') NOT NULL DEFAULT 'net',
 discount_account_id BIGINT UNSIGNED NULL,
 free_goods_account_id BIGINT UNSIGNED NULL,
 free_goods_output_tax ENUM('none','open_market_value') NOT NULL DEFAULT 'none',
 cash_on_invoice_cap DECIMAL(20,4) NOT NULL DEFAULT 0,
 revision INT UNSIGNED NOT NULL DEFAULT 1,
 updated_by BIGINT UNSIGNED NOT NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT ck_trading_policy CHECK (revision > 0 AND cash_on_invoice_cap >= 0
  AND (discount_posting = 'net' OR discount_account_id IS NOT NULL)),
 CONSTRAINT fk_trading_policy_book FOREIGN KEY (book_id, company_id) REFERENCES pl_books (id, company_id),
 CONSTRAINT fk_trading_policy_discount FOREIGN KEY (discount_account_id, company_id, book_id) REFERENCES pl_accounts (id, company_id, book_id),
 CONSTRAINT fk_trading_policy_free_goods FOREIGN KEY (free_goods_account_id, company_id, book_id) REFERENCES pl_accounts (id, company_id, book_id),
 CONSTRAINT fk_trading_policy_actor FOREIGN KEY (updated_by) REFERENCES pl_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
    <<<'SQL'
CREATE TABLE pl_trading_policy_actions (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL, book_id BIGINT UNSIGNED NULL,
 scope ENUM('policies','company_profile') NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL, reason VARCHAR(500) NOT NULL,
 request_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 before_state JSON NULL, result_json JSON NOT NULL,
 recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_trading_policy_request (company_id, request_key),
 CONSTRAINT fk_trading_policy_action_company FOREIGN KEY (company_id) REFERENCES pl_companies (id),
 CONSTRAINT fk_trading_policy_action_actor FOREIGN KEY (actor_id) REFERENCES pl_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
    "CREATE TRIGGER pl_trading_policy_actions_no_update BEFORE UPDATE ON pl_trading_policy_actions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Accounting policy history is immutable'",
    "CREATE TRIGGER pl_trading_policy_actions_no_delete BEFORE DELETE ON pl_trading_policy_actions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Accounting policy history is immutable'",
    "ALTER TABLE pl_core_audit MODIFY entity_type ENUM('account','general_journal','product','warehouse','document_series','product_pack','sales_staff','area','company_profile','trading_policy') NOT NULL",
];
