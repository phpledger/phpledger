<?php
declare(strict_types=1);

/**
 * Release plan 1.2, milestone M7: the full Users module (owner decisions B17, B44, B58).
 *
 * Roles become rows rather than an ENUM, capabilities become the authorisation unit, and the
 * user record grows the profile, meta, invitation, session and audit tables a real Users screen
 * needs. Modelled on WordPress (B17) with one deliberate difference: a role is assigned per
 * company, on pl_company_members, not once per installation.
 *
 * Compatibility rule for 1.2: pl_company_members keeps BOTH the three-value `role` ENUM and the
 * new `role_id`. Every write sets both (pl_assign_company_role()); every authorisation read goes
 * through pl_user_can(). The ENUM is dropped in 1.3, once no released code reads it. A copy that
 * upgrades to 1.2 and then restores a 1.1 backup of the application still finds the ENUM it
 * expects, which is why the mirror exists rather than a straight replacement.
 *
 * Nothing here touches a posted journal, a book row or an accounting amount.
 */
return [
    // ---------------------------------------------------------------- profile fields and locale
    <<<'SQL'
ALTER TABLE pl_users
    ADD COLUMN first_name VARCHAR(80) NULL AFTER display_name,
    ADD COLUMN last_name VARCHAR(80) NULL AFTER first_name,
    ADD COLUMN phone VARCHAR(40) NULL AFTER last_name,
    ADD COLUMN job_title VARCHAR(120) NULL AFTER phone,
    ADD COLUMN locale VARCHAR(35) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER job_title,
    ADD COLUMN timezone VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER locale,
    ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER password_hash,
    ADD COLUMN password_changed_at DATETIME NULL AFTER must_change_password,
    ADD COLUMN last_signed_in_at DATETIME NULL AFTER is_active,
    ADD COLUMN deactivated_at DATETIME NULL AFTER last_signed_in_at,
    ADD COLUMN anonymised_at DATETIME NULL AFTER deactivated_at
SQL,
    // ------------------------------------------------------------------------ user meta (B17)
    // WordPress's usermeta shape: an open key/value store per user. PHP Ledger uses it for the
    // per-user installation capability grants (see pl_user_installation_grants()) and for the
    // small profile preferences that do not deserve a column of their own.
    <<<'SQL'
CREATE TABLE pl_user_meta (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    meta_key VARCHAR(190) NOT NULL,
    meta_value LONGTEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_meta (user_id, meta_key),
    CONSTRAINT fk_user_meta_user FOREIGN KEY (user_id) REFERENCES pl_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
    // --------------------------------------------------------------------------- capabilities
    // `owner_type`/`owner_id` is the column the release plan asks for: a module or, from M8, a
    // plugin registers its own capabilities and keeps them. A capability owned by a module that
    // a company has switched off is INERT for that company: the grant row is retained, and
    // pl_user_can() simply answers no until the module is enabled again.
    <<<'SQL'
CREATE TABLE pl_capabilities (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    capability VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    owner_type ENUM('core','module','plugin') NOT NULL DEFAULT 'core',
    owner_id VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
    scope ENUM('company','installation') NOT NULL DEFAULT 'company',
    label VARCHAR(160) NOT NULL,
    description VARCHAR(500) NOT NULL DEFAULT '',
    registered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_capabilities_name (capability),
    KEY ix_capabilities_owner (owner_type, owner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
    // ---------------------------------------------------------------------------------- roles
    // company_id NULL = an installation-wide role definition, available to every company. The
    // three protected system roles are exactly these. A custom role belongs to one company.
    // scope_key exists because a UNIQUE KEY does not constrain NULLs: without it two
    // installation-wide roles could share a slug.
    <<<'SQL'
CREATE TABLE pl_roles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NULL,
    scope_key BIGINT UNSIGNED GENERATED ALWAYS AS (IFNULL(company_id, 0)) STORED,
    slug VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name VARCHAR(80) NOT NULL,
    description VARCHAR(500) NOT NULL DEFAULT '',
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    revision INT UNSIGNED NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_roles_slug (scope_key, slug),
    KEY ix_roles_company (company_id),
    CONSTRAINT fk_roles_company FOREIGN KEY (company_id) REFERENCES pl_companies (id),
    CONSTRAINT fk_roles_creator FOREIGN KEY (created_by) REFERENCES pl_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
    <<<'SQL'
CREATE TABLE pl_role_capabilities (
    role_id BIGINT UNSIGNED NOT NULL,
    capability_id BIGINT UNSIGNED NOT NULL,
    granted_by BIGINT UNSIGNED NULL,
    granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (role_id, capability_id),
    KEY ix_role_capabilities_capability (capability_id),
    CONSTRAINT fk_role_capabilities_role FOREIGN KEY (role_id) REFERENCES pl_roles (id),
    CONSTRAINT fk_role_capabilities_capability FOREIGN KEY (capability_id) REFERENCES pl_capabilities (id),
    CONSTRAINT fk_role_capabilities_granter FOREIGN KEY (granted_by) REFERENCES pl_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
    // ------------------------------------------------- the three protected system roles (B17)
    "INSERT INTO pl_roles (company_id, slug, name, description, is_system) VALUES "
    . "(NULL, 'owner', 'Owner', 'Runs the business: every accounting action, plus the settings that change how the books behave.', 1), "
    . "(NULL, 'accountant', 'Accountant', 'Records and corrects day-to-day accounting, but does not change the settings that govern the books.', 1), "
    . "(NULL, 'viewer', 'Viewer', 'Reads records and reports. Records nothing.', 1)",
    // The capability catalogue and the system roles' grants are seeded by
    // pl_sync_capability_catalogue(), which pl_migrate() runs after this migration and which
    // every later release re-runs. Keeping the catalogue in PHP means a module or plugin can
    // add to it without a migration, which is the whole point of the owner column.

    // ------------------------------------------------- role_id on membership, backfilled (B17)
    <<<'SQL'
ALTER TABLE pl_company_members
    ADD COLUMN role_id BIGINT UNSIGNED NULL AFTER role,
    ADD KEY ix_members_role (role_id),
    ADD CONSTRAINT fk_members_role FOREIGN KEY (role_id) REFERENCES pl_roles (id)
SQL,
    // Backfill from the ENUM. The ENUM stays authoritative for nothing after this point, but it
    // is kept mirrored through 1.2 and is dropped in 1.3.
    <<<'SQL'
UPDATE pl_company_members m
JOIN pl_roles r ON r.company_id IS NULL AND r.slug = m.role
SET m.role_id = r.id
WHERE m.role_id IS NULL
SQL,
    // ---------------------------------------------------------------------------- invitations
    <<<'SQL'
CREATE TABLE pl_user_invitations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    email VARCHAR(254) NOT NULL,
    role_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    invited_by BIGINT UNSIGNED NOT NULL,
    message VARCHAR(500) NOT NULL DEFAULT '',
    expires_at DATETIME NOT NULL,
    accepted_at DATETIME NULL,
    accepted_user_id BIGINT UNSIGNED NULL,
    revoked_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    open_key VARCHAR(300) GENERATED ALWAYS AS (CASE WHEN accepted_at IS NULL AND revoked_at IS NULL THEN CONCAT(company_id, ':', email) ELSE NULL END) STORED,
    UNIQUE KEY uq_invitations_token (token_hash),
    UNIQUE KEY uq_invitations_open (open_key),
    KEY ix_invitations_company (company_id, created_at),
    CONSTRAINT fk_invitations_company FOREIGN KEY (company_id) REFERENCES pl_companies (id),
    CONSTRAINT fk_invitations_role FOREIGN KEY (role_id) REFERENCES pl_roles (id),
    CONSTRAINT fk_invitations_inviter FOREIGN KEY (invited_by) REFERENCES pl_users (id),
    CONSTRAINT fk_invitations_accepter FOREIGN KEY (accepted_user_id) REFERENCES pl_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
    // -------------------------------------------------------- server-side sessions, revocable
    // The primary key is the SHA-256 of the session token, never the token: a database copy or a
    // log line cannot be replayed as a sign-in. Same rule as pl_login_attempts' subject_hash.
    <<<'SQL'
CREATE TABLE pl_user_sessions (
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    installation_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    client_label VARCHAR(160) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    revoked_by BIGINT UNSIGNED NULL,
    KEY ix_user_sessions_user (user_id, last_seen_at),
    KEY ix_user_sessions_expiry (expires_at),
    CONSTRAINT fk_user_sessions_user FOREIGN KEY (user_id) REFERENCES pl_users (id),
    CONSTRAINT fk_user_sessions_revoker FOREIGN KEY (revoked_by) REFERENCES pl_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
    // --------------------------------------------------------- per-report cost settings (B58)
    // "Cost visibility is an Admin call that varies report by report." The capability decides
    // whether a viewer MAY see cost at all; this table decides whether a given report SHOWS it.
    // Both must say yes. A missing row means the shipped default in pl_report_cost_defaults().
    <<<'SQL'
CREATE TABLE pl_report_cost_settings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    book_id BIGINT UNSIGNED NOT NULL,
    report_id VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    show_cost TINYINT(1) NOT NULL DEFAULT 1,
    show_margin TINYINT(1) NOT NULL DEFAULT 0,
    revision INT UNSIGNED NOT NULL DEFAULT 0,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_report_cost_report (book_id, report_id),
    CONSTRAINT fk_report_cost_book FOREIGN KEY (book_id, company_id) REFERENCES pl_books (id, company_id),
    CONSTRAINT fk_report_cost_updater FOREIGN KEY (updated_by) REFERENCES pl_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
    // ------------------------------------------------------------ immutable user audit (B53)
    // Same shape and the same two triggers as pl_core_audit, with two differences: company_id is
    // NULLABLE, because an installation-wide act (granting installation.admin, editing an
    // installation-wide role) belongs to no company; and there is no book, because none of these
    // acts belong to a book.
    <<<'SQL'
CREATE TABLE pl_user_audit (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    subject_user_id BIGINT UNSIGNED NULL,
    entity_type ENUM('user','role','capability','invitation','session','membership','report_cost_setting') NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(40) NOT NULL,
    reason VARCHAR(500) NOT NULL DEFAULT '',
    before_state JSON NULL,
    after_state JSON NOT NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_user_audit_entity (entity_type, entity_id, id),
    KEY ix_user_audit_company (company_id, id),
    KEY ix_user_audit_subject (subject_user_id, id),
    CONSTRAINT fk_user_audit_company FOREIGN KEY (company_id) REFERENCES pl_companies (id),
    CONSTRAINT fk_user_audit_actor FOREIGN KEY (actor_id) REFERENCES pl_users (id),
    CONSTRAINT fk_user_audit_subject FOREIGN KEY (subject_user_id) REFERENCES pl_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
    "CREATE TRIGGER pl_user_audit_no_update BEFORE UPDATE ON pl_user_audit FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'User audit records are immutable'",
    "CREATE TRIGGER pl_user_audit_no_delete BEFORE DELETE ON pl_user_audit FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'User audit records cannot be deleted'",
    // A protected system role is protected in the database too, not only in pl_save_role():
    // a direct UPDATE or DELETE on one is refused, the way posted journals already are.
    "CREATE TRIGGER pl_roles_system_no_update BEFORE UPDATE ON pl_roles FOR EACH ROW BEGIN IF OLD.is_system = 1 AND (NEW.slug <> OLD.slug OR NEW.is_system <> OLD.is_system OR NOT (NEW.company_id <=> OLD.company_id)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Protected system roles cannot be renamed, rescoped or unprotected'; END IF; END",
    "CREATE TRIGGER pl_roles_system_no_delete BEFORE DELETE ON pl_roles FOR EACH ROW BEGIN IF OLD.is_system = 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Protected system roles cannot be deleted'; END IF; END",
    // ------------------------------------------------------------------- core audit enum union
    // A MODIFY replaces the whole ENUM list, so this carries the union of every value any
    // earlier migration added (037 and 038 collided here once already) plus this one's.
    "ALTER TABLE pl_core_audit MODIFY entity_type ENUM('account','general_journal','product','warehouse','document_series',"
    . "'product_pack','sales_staff','area','company_profile','trading_policy',"
    . "'stock_document','gate_pass','van_settlement',"
    . "'report_cost_setting') NOT NULL",
];
