<?php
declare(strict_types=1);

/**
 * Starting a business from a sample's structure (1.2.1 M10; owner decision B50).
 *
 * Two facts, both additive.
 *
 * 1. `pl_template_installation_history.snapshot_kind` gains `skeleton`. The table already
 *    records the two ways a book's structure could arrive — the chart it was installed with,
 *    and a sample pack seeded into an isolated sample — and a skeleton is a third. Extending
 *    this ENUM is safe in a way that extending `pl_core_audit.entity` was not (the 038 bug):
 *    nothing reads `snapshot_kind` by ordinal, `pl_record_installation_history()` is the only
 *    writer and already refuses an unknown kind, and every existing row keeps its own value.
 *    The table's two immutability triggers are untouched and keep applying.
 *
 * 2. `pl_sample_imports` — one immutable row per skeleton import, recording which sample and
 *    which structure document produced it, where that structure was read from, and what was
 *    created. It exists because the claim a skeleton makes ("this brought in structure and no
 *    transactions") has to survive the request that made it: support, the Ready stage and any
 *    later audit read this row rather than re-deriving the answer from a sample file that may
 *    since have changed. `request_key` makes a repeated confirmation return the first import
 *    instead of creating a second.
 *
 * No posting table is touched, no row is rewritten, and a book that never used a skeleton is
 * bit-for-bit what it was before this ran.
 */
return [
    "ALTER TABLE pl_template_installation_history MODIFY snapshot_kind ENUM('chart','sample','skeleton') NOT NULL",
    <<<'SQL'
CREATE TABLE pl_sample_imports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    book_id BIGINT UNSIGNED NOT NULL,
    mode ENUM('skeleton','full') NOT NULL,
    sample_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    sample_version VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    sample_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    structure_origin ENUM('manifest','catalogue') NOT NULL,
    structure_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_json JSON NOT NULL,
    imported_by BIGINT UNSIGNED NOT NULL,
    imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sample_imports_request (company_id, request_key),
    KEY ix_sample_imports_book (company_id, book_id, id),
    CONSTRAINT fk_sample_imports_book FOREIGN KEY (book_id, company_id) REFERENCES pl_books (id, company_id),
    CONSTRAINT fk_sample_imports_actor FOREIGN KEY (imported_by) REFERENCES pl_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
    "CREATE TRIGGER pl_sample_imports_no_update BEFORE UPDATE ON pl_sample_imports FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A sample import receipt is immutable'",
    "CREATE TRIGGER pl_sample_imports_no_delete BEFORE DELETE ON pl_sample_imports FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A sample import receipt is immutable'",
];
