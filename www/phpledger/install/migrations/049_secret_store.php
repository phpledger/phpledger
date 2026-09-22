<?php
declare(strict_types=1);

/**
 * The secret store (owner decision B83; unblocks the connector platform's highest-value piece,
 * B78). One table: `pl_plugin_secrets`, the exact scoping shape `pl_plugin_options` already uses
 * (slug, company_id 0-for-installation-or-a-real-company, name), because B83 asks for a place a
 * package's credential lives, not a new scoping mechanism. `secret_functions.php` and the
 * `pl_plugin_secret_*` wrappers in `plugin_functions.php` are the code; this is only the table.
 *
 * The column holds ciphertext only. `ciphertext` is never plaintext, under any code path: the
 * encryption key is a file in the existing private installation directory
 * (`pl_update_directory()`), generated on first use and never written here or anywhere else in
 * the database, so a database backup alone never carries the key that would open this column.
 * Nothing about the key lives in this migration, on purpose.
 *
 * Deliberately no change to `pl_core_audit.entity_type`. Secret storage is package lifecycle
 * data, not an accounting entity, the same reasoning 042_plugin_runtime already recorded for
 * `pl_packages`, `pl_package_actions`, `pl_plugin_migrations` and `pl_plugin_options`: those four
 * tables added no ENUM value either, and this one follows them rather than starting a second
 * pattern.
 *
 * Reversal, if this is ever removed before another migration depends on it:
 *   DROP TABLE pl_plugin_secrets;
 * A key file left behind by a reversal is inert (nothing decrypts a dropped table's rows) and is
 * not this migration's concern to delete; an operator who removes the feature can delete
 * `secret.key` from the private installation directory by hand.
 */
return [
    <<<'SQL'
CREATE TABLE pl_plugin_secrets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    secret_name VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    ciphertext LONGTEXT NOT NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_plugin_secret (slug, company_id, secret_name),
    KEY ix_plugin_secret_company (company_id, slug),
    CONSTRAINT fk_plugin_secret_updater FOREIGN KEY (updated_by) REFERENCES pl_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
];
