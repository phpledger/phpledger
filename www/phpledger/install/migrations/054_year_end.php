<?php
declare(strict_types=1);

// Policies are versioned in immutable action receipts; posted journals remain untouched.
$sql = [<<<'SQL'
CREATE TABLE pl_fiscal_years (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL, book_id BIGINT UNSIGNED NOT NULL,
 start_date DATE NOT NULL, end_date DATE NOT NULL,
 status ENUM('open','closing','closed','locked') NOT NULL DEFAULT 'open',
 revision INT UNSIGNED NOT NULL DEFAULT 1,
 policy LONGTEXT NOT NULL, created_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_fiscal_end (book_id,end_date), UNIQUE KEY uq_fiscal_scope (id,company_id,book_id),
 CONSTRAINT fk_fiscal_book FOREIGN KEY (book_id,company_id) REFERENCES pl_books(id,company_id),
 CONSTRAINT fk_fiscal_actor FOREIGN KEY (created_by) REFERENCES pl_users(id),
 CONSTRAINT ck_fiscal_dates CHECK (start_date <= end_date),
 CONSTRAINT ck_fiscal_policy CHECK (JSON_VALID(policy))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL, <<<'SQL'
CREATE TABLE pl_year_end_actions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL, book_id BIGINT UNSIGNED NOT NULL, year_id BIGINT UNSIGNED NOT NULL,
 action ENUM('create','policy','start','prepare','adjust','close','reopen','lock') NOT NULL,
 request_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload LONGTEXT NOT NULL, actor_id BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_year_action_key(book_id,request_key),
 CONSTRAINT fk_year_action_scope FOREIGN KEY(year_id,company_id,book_id) REFERENCES pl_fiscal_years(id,company_id,book_id),
 CONSTRAINT fk_year_action_actor FOREIGN KEY(actor_id) REFERENCES pl_users(id),
 CONSTRAINT ck_year_action_payload CHECK(JSON_VALID(payload))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL, <<<'SQL'
CREATE TABLE pl_year_end_posting_intents (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL, book_id BIGINT UNSIGNED NOT NULL, year_id BIGINT UNSIGNED NOT NULL,
 request_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 kind ENUM('adjust','close','reopen') NOT NULL, reversal_of_id BIGINT UNSIGNED NULL,
 actor_id BIGINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_year_intent_key(book_id,request_key),
 CONSTRAINT fk_year_intent_scope FOREIGN KEY(year_id,company_id,book_id) REFERENCES pl_fiscal_years(id,company_id,book_id),
 CONSTRAINT fk_year_intent_actor FOREIGN KEY(actor_id) REFERENCES pl_users(id),
 CONSTRAINT fk_year_intent_original FOREIGN KEY(reversal_of_id) REFERENCES pl_journals(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL];
foreach (['pl_year_end_actions','pl_year_end_posting_intents'] as $table) {
    foreach (['UPDATE','DELETE'] as $verb) {
        $sql[] = 'CREATE TRIGGER ' . $table . '_' . strtolower($verb) . ' BEFORE ' . $verb . ' ON ' . $table
            . " FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Year-end evidence is immutable'";
    }
}
return $sql;
