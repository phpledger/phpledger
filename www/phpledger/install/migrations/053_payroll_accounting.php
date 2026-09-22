<?php
declare(strict_types=1);

$sql = [
<<<'SQL'
CREATE TABLE pl_payroll_journals (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL, book_id BIGINT UNSIGNED NOT NULL,
 period_from DATE NOT NULL, period_to DATE NOT NULL, posting_date DATE NOT NULL,
 external_reference VARCHAR(120) NOT NULL, description VARCHAR(500) NOT NULL,
 request_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 journal_id BIGINT UNSIGNED NULL, created_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_payroll_scope (id,company_id,book_id),
 UNIQUE KEY uq_payroll_request (book_id,request_key), UNIQUE KEY uq_payroll_reference (book_id,external_reference),
 CONSTRAINT ck_payroll_period CHECK (period_from <= period_to),
 CONSTRAINT fk_payroll_book FOREIGN KEY (book_id,company_id) REFERENCES pl_books(id,company_id),
 CONSTRAINT fk_payroll_journal FOREIGN KEY (journal_id,company_id,book_id) REFERENCES pl_journals(id,company_id,book_id),
 CONSTRAINT fk_payroll_actor FOREIGN KEY (created_by) REFERENCES pl_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
<<<'SQL'
CREATE TABLE pl_payroll_elements (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 payroll_id BIGINT UNSIGNED NOT NULL, company_id BIGINT UNSIGNED NOT NULL, book_id BIGINT UNSIGNED NOT NULL,
 element_kind VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 account_id BIGINT UNSIGNED NOT NULL, amount DECIMAL(20,4) NOT NULL,
 UNIQUE KEY uq_payroll_element_scope (id,payroll_id,company_id,book_id),
 CONSTRAINT ck_payroll_element_positive CHECK (amount > 0),
 CONSTRAINT fk_payroll_element_source FOREIGN KEY (payroll_id,company_id,book_id) REFERENCES pl_payroll_journals(id,company_id,book_id),
 CONSTRAINT fk_payroll_element_account FOREIGN KEY (account_id,company_id,book_id) REFERENCES pl_accounts(id,company_id,book_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
<<<'SQL'
CREATE TABLE pl_payroll_payments (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 payroll_id BIGINT UNSIGNED NOT NULL, company_id BIGINT UNSIGNED NOT NULL, book_id BIGINT UNSIGNED NOT NULL,
 draft_id BIGINT UNSIGNED NOT NULL, plan_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_payroll_payment_scope (id,payroll_id,company_id,book_id),
 UNIQUE KEY uq_payroll_payment_draft (draft_id), UNIQUE KEY uq_payroll_payment_request (book_id,request_key),
 CONSTRAINT fk_payroll_payment_source FOREIGN KEY (payroll_id,company_id,book_id) REFERENCES pl_payroll_journals(id,company_id,book_id),
 CONSTRAINT fk_payroll_payment_draft FOREIGN KEY (draft_id) REFERENCES pl_general_drafts(id),
 CONSTRAINT fk_payroll_payment_actor FOREIGN KEY (created_by) REFERENCES pl_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
<<<'SQL'
CREATE TABLE pl_payroll_payment_lines (
 payment_id BIGINT UNSIGNED NOT NULL, element_id BIGINT UNSIGNED NOT NULL,
 payroll_id BIGINT UNSIGNED NOT NULL, company_id BIGINT UNSIGNED NOT NULL, book_id BIGINT UNSIGNED NOT NULL,
 amount DECIMAL(20,4) NOT NULL,
 PRIMARY KEY(payment_id,element_id), CONSTRAINT ck_payroll_payment_amount CHECK(amount > 0),
 CONSTRAINT fk_payroll_allocation_payment FOREIGN KEY(payment_id,payroll_id,company_id,book_id) REFERENCES pl_payroll_payments(id,payroll_id,company_id,book_id),
 CONSTRAINT fk_payroll_allocation_element FOREIGN KEY(element_id,payroll_id,company_id,book_id) REFERENCES pl_payroll_elements(id,payroll_id,company_id,book_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
];
foreach (['pl_payroll_elements','pl_payroll_payments','pl_payroll_payment_lines'] as $table) {
    foreach (['UPDATE','DELETE'] as $operation) {
        $sql[] = "CREATE TRIGGER {$table}_no_" . strtolower($operation) . " BEFORE {$operation} ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Payroll source history is immutable'";
    }
}
$sql[] = "CREATE TRIGGER pl_payroll_no_delete BEFORE DELETE ON pl_payroll_journals FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Payroll source history is immutable'";
$sql[] = <<<'SQL'
CREATE TRIGGER pl_payroll_no_update BEFORE UPDATE ON pl_payroll_journals FOR EACH ROW
BEGIN
 IF OLD.journal_id IS NOT NULL OR NEW.journal_id IS NULL
 OR NOT (NEW.id <=> OLD.id) OR NOT (NEW.company_id <=> OLD.company_id) OR NOT (NEW.book_id <=> OLD.book_id)
 OR NOT (NEW.period_from <=> OLD.period_from) OR NOT (NEW.period_to <=> OLD.period_to) OR NOT (NEW.posting_date <=> OLD.posting_date)
 OR NOT (NEW.external_reference <=> OLD.external_reference) OR NOT (NEW.description <=> OLD.description)
 OR NOT (NEW.request_key <=> OLD.request_key) OR NOT (NEW.request_hash <=> OLD.request_hash)
 OR NOT (NEW.created_by <=> OLD.created_by) OR NOT (NEW.created_at <=> OLD.created_at)
 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Payroll source history is immutable'; END IF;
END
SQL;
return $sql;
