<?php
declare(strict_types=1);

/**
 * Release plan 1.2, milestone M8: the plugin runtime's tables (issue #73; owner decisions B44,
 * B46, B47, B51, B52).
 *
 * Four tables and nothing else. There is deliberately no change to `pl_core_audit`: package
 * lifecycle is not an accounting entity, so it gets its own immutable audit table rather than a
 * new value in the core audit ENUM. (Migration 038 shipped a bug by rewriting that ENUM without
 * carrying the union of the values earlier migrations had added; not touching it at all is the
 * surest way not to repeat that.)
 *
 * Nothing here touches a posted journal, a book row or an accounting amount.
 */
return [
    // ------------------------------------------------------------------ the installed packages
    // One row per package the installation has recorded. `files_digest` is the digest of the
    // package's files taken at installation; the loader recomputes it on every boot and refuses
    // to load code that no longer matches. `trust` is the two tiers of B51: `verified` is signed
    // by the project, `unverified` is a ZIP the owner uploaded and accepted responsibility for,
    // and an unverified package never updates automatically.
    //
    // `status` has three values, not two: `failed` is what the loader sets when a package's
    // files stopped matching, when its manifest stopped validating, or when its code ended a
    // request. It is distinct from `installed` so a screen can say which packages an
    // administrator turned off and which ones the application turned off for them.
    <<<'SQL'
CREATE TABLE pl_packages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    type ENUM('plugin','sample') NOT NULL DEFAULT 'plugin',
    name VARCHAR(160) NOT NULL,
    version VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    contract SMALLINT UNSIGNED NOT NULL,
    api_version VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    trust ENUM('verified','unverified') NOT NULL,
    status ENUM('installed','active','failed') NOT NULL DEFAULT 'installed',
    manifest JSON NOT NULL,
    manifest_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    files_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    last_error VARCHAR(500) NOT NULL DEFAULT '',
    last_error_at DATETIME NULL,
    revision INT UNSIGNED NOT NULL DEFAULT 0,
    installed_by BIGINT UNSIGNED NOT NULL,
    installed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_packages_slug (slug),
    KEY ix_packages_status (status, slug),
    CONSTRAINT fk_packages_installer FOREIGN KEY (installed_by) REFERENCES pl_users (id),
    CONSTRAINT fk_packages_updater FOREIGN KEY (updated_by) REFERENCES pl_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
    // ------------------------------------------------------------- the immutable package audit
    // Same shape and the same two triggers as pl_core_audit and pl_user_audit, with one
    // difference: `actor_id` is NULLABLE, because the loader deactivates a package with nobody
    // signed in. `acknowledgements` keeps the three statements an owner accepted before
    // unverified code was installed, next to the digest of exactly what they accepted (B51).
    //
    // `request_key` is NULLABLE and unique per slug: a browser action carries one so a resubmit
    // cannot record a second act, and a loader deactivation carries none. MySQL and MariaDB both
    // allow repeated NULLs in a unique index, which is the behaviour this relies on.
    <<<'SQL'
CREATE TABLE pl_package_actions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_id BIGINT UNSIGNED NULL,
    request_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
    action ENUM('installed','upload_accepted','activated','deactivated','auto_deactivated','uninstalled') NOT NULL,
    trust ENUM('verified','unverified') NOT NULL,
    files_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reason VARCHAR(500) NOT NULL DEFAULT '',
    acknowledgements JSON NULL,
    before_state JSON NULL,
    result_json JSON NOT NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_package_action_key (slug, request_key),
    KEY ix_package_actions_slug (slug, id),
    CONSTRAINT fk_package_action_actor FOREIGN KEY (actor_id) REFERENCES pl_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
    "CREATE TRIGGER pl_package_actions_no_update BEFORE UPDATE ON pl_package_actions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Package audit records are immutable'",
    "CREATE TRIGGER pl_package_actions_no_delete BEFORE DELETE ON pl_package_actions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Package audit records cannot be deleted'",
    // ------------------------------------------------------- plugin migration receipts (#73)
    // A mirror of pl_schema_migrations, per package. It is a separate table so that removing a
    // package can never leave a receipt the core migration runner cannot account for: pl_migrate()
    // refuses to run when pl_schema_migrations holds a version this code does not ship, and a
    // plugin's migrations must never be able to trip that.
    <<<'SQL'
CREATE TABLE pl_plugin_migrations (
    slug VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    version VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status ENUM('applying','applied') NOT NULL,
    statements_done INT UNSIGNED NULL DEFAULT NULL,
    applied_at DATETIME NULL,
    PRIMARY KEY (slug, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
    // -------------------------------------------------------------- the shared options table
    // B46: one shared options table, plus each package's own prefixed tables for its data.
    //
    // `company_id` 0 is the installation scope — the same convention pl_user_can($actor, 0, ...)
    // already uses for an installation-wide capability. It is NOT NULL with a 0 default rather
    // than NULLABLE because the uniqueness of (slug, company_id, option_name) has to hold for
    // installation-scoped rows too, and a NULL in a unique index does not collide with itself.
    // The cost is that the column carries no foreign key; pl_plugin_option_scope() checks a
    // non-zero company against pl_companies before any read or write.
    //
    // This table is for settings. It is not a secret store: this release has no reversible
    // encryption, and B77 records that key custody on shared hosting is an owner decision that
    // has not been taken, so a connector's credentials have nowhere safe to live yet.
    <<<'SQL'
CREATE TABLE pl_plugin_options (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    option_name VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    option_value LONGTEXT NOT NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_plugin_option (slug, company_id, option_name),
    KEY ix_plugin_option_company (company_id, slug),
    CONSTRAINT fk_plugin_option_updater FOREIGN KEY (updated_by) REFERENCES pl_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
];
