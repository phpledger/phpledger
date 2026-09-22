<?php
declare(strict_types=1);
return [
"ALTER TABLE pl_employees ADD COLUMN trade_party_id BIGINT UNSIGNED NULL, ADD UNIQUE KEY uq_employee_trade_party(company_id,trade_party_id), ADD CONSTRAINT fk_employee_trade_party FOREIGN KEY(trade_party_id,company_id) REFERENCES pl_parties(id,company_id)",
"ALTER TABLE pl_sales_staff ADD COLUMN employee_id BIGINT UNSIGNED NULL, ADD CONSTRAINT fk_sales_staff_employee FOREIGN KEY(employee_id,company_id) REFERENCES pl_employees(id,company_id)",
"ALTER TABLE pl_inventory_warehouses ADD COLUMN driver_employee_id BIGINT UNSIGNED NULL, ADD CONSTRAINT fk_warehouse_employee FOREIGN KEY(driver_employee_id,company_id) REFERENCES pl_employees(id,company_id)",
<<<'SQL'
CREATE TABLE pl_employee_document_labels (
 source_kind ENUM('invoice','stock') NOT NULL, source_id BIGINT UNSIGNED NOT NULL,
 company_id BIGINT UNSIGNED NOT NULL, book_id BIGINT UNSIGNED NOT NULL,
 staff_name VARCHAR(160) NOT NULL DEFAULT '', from_driver_name VARCHAR(160) NOT NULL DEFAULT '', to_driver_name VARCHAR(160) NOT NULL DEFAULT '',
 PRIMARY KEY(source_kind,source_id),
 CONSTRAINT fk_employee_label_book FOREIGN KEY(book_id,company_id) REFERENCES pl_books(id,company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
"INSERT INTO pl_employee_document_labels(source_kind,source_id,company_id,book_id,staff_name) SELECT 'invoice',d.id,d.company_id,d.book_id,COALESCE(s.name,'') FROM pl_ar_documents d LEFT JOIN pl_sales_staff s ON s.id=d.sales_staff_id WHERE d.journal_id IS NOT NULL",
"INSERT INTO pl_employee_document_labels(source_kind,source_id,company_id,book_id,from_driver_name,to_driver_name) SELECT 'stock',d.id,d.company_id,d.book_id,COALESCE(f.driver_name,''),COALESCE(t.driver_name,'') FROM pl_stock_documents d LEFT JOIN pl_inventory_warehouses f ON f.id=d.from_warehouse_id LEFT JOIN pl_inventory_warehouses t ON t.id=d.to_warehouse_id",
<<<'SQL'
CREATE TRIGGER pl_employee_invoice_label AFTER UPDATE ON pl_ar_documents FOR EACH ROW
BEGIN
 IF OLD.journal_id IS NULL AND NEW.journal_id IS NOT NULL THEN
  INSERT INTO pl_employee_document_labels(source_kind,source_id,company_id,book_id,staff_name)
  SELECT 'invoice',NEW.id,NEW.company_id,NEW.book_id,COALESCE(e.full_name,s.name,'') FROM (SELECT 1) one_row
  LEFT JOIN pl_sales_staff s ON s.id=NEW.sales_staff_id AND s.company_id=NEW.company_id LEFT JOIN pl_employees e ON e.id=s.employee_id;
 END IF;
END
SQL,
<<<'SQL'
CREATE TRIGGER pl_employee_stock_label AFTER INSERT ON pl_stock_documents FOR EACH ROW
BEGIN
 INSERT INTO pl_employee_document_labels(source_kind,source_id,company_id,book_id,from_driver_name,to_driver_name)
 SELECT 'stock',NEW.id,NEW.company_id,NEW.book_id,COALESCE(ef.full_name,f.driver_name,''),COALESCE(et.full_name,t.driver_name,'') FROM (SELECT 1) one_row
 LEFT JOIN pl_inventory_warehouses f ON f.id=NEW.from_warehouse_id LEFT JOIN pl_employees ef ON ef.id=f.driver_employee_id
 LEFT JOIN pl_inventory_warehouses t ON t.id=NEW.to_warehouse_id LEFT JOIN pl_employees et ON et.id=t.driver_employee_id;
END
SQL,
"CREATE TRIGGER pl_employee_labels_no_update BEFORE UPDATE ON pl_employee_document_labels FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Employee document labels are immutable'",
"CREATE TRIGGER pl_employee_labels_no_delete BEFORE DELETE ON pl_employee_document_labels FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Employee document labels are immutable'",
];
