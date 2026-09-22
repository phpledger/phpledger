<?php
declare(strict_types=1);
return [
    "ALTER TABLE pl_outbound_events ADD dispatch_kind ENUM('outbound','internal') NOT NULL DEFAULT 'outbound'",
    "CREATE TABLE pl_scheduler_jobs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id BIGINT UNSIGNED NOT NULL, book_id BIGINT UNSIGNED NOT NULL,
        actor_id BIGINT UNSIGNED NOT NULL, job_kind VARCHAR(40) NOT NULL, source_id BIGINT UNSIGNED NOT NULL,
        occurrence_date DATE NOT NULL, request_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        payload JSON NOT NULL, payload_hash CHAR(64) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_scheduler_key (book_id, request_key), UNIQUE KEY uq_scheduler_scope (id,company_id,book_id),
        FOREIGN KEY (book_id,company_id) REFERENCES pl_books(id,company_id), FOREIGN KEY (actor_id) REFERENCES pl_users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci",
    "CREATE TABLE pl_scheduler_results (
        job_id BIGINT UNSIGNED PRIMARY KEY, company_id BIGINT UNSIGNED NOT NULL, book_id BIGINT UNSIGNED NOT NULL,
        result JSON NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (job_id,company_id,book_id) REFERENCES pl_scheduler_jobs(id,company_id,book_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci",
    "CREATE TRIGGER pl_scheduler_jobs_no_update BEFORE UPDATE ON pl_scheduler_jobs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Scheduled jobs are immutable'",
    "CREATE TRIGGER pl_scheduler_jobs_no_delete BEFORE DELETE ON pl_scheduler_jobs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Scheduled jobs are immutable'",
    "CREATE TRIGGER pl_scheduler_results_no_update BEFORE UPDATE ON pl_scheduler_results FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Scheduled job receipts are immutable'",
    "CREATE TRIGGER pl_scheduler_results_no_delete BEFORE DELETE ON pl_scheduler_results FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Scheduled job receipts are immutable'",
];
