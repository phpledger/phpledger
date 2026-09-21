<?php
declare(strict_types=1);

/**
 * Release plan 1.2, milestone M8a: the ownership register (issue #92, owner decision B63,
 * extending the B64 company profile; the related-party marker is B72 as narrowed by B74).
 *
 * The balance sheet already carries an equity section and 1.2 already posts owner capital,
 * loans and drawings (B61). What no table held was the record every company and partnership
 * must keep by law and that makes the equity section complete: who owns the business, in what
 * proportion, through which class of share, since when, and who its officers are. A private
 * company in Pakistan keeps registers of members and of directors (Companies Act 2017), a UK
 * company keeps registers of members, directors, secretaries and persons with significant
 * control (Companies Act 2006 as amended by the Economic Crime and Corporate Transparency Act
 * 2023), and a Delaware corporation keeps a stock ledger (DGCL section 224). An AOP or
 * partnership needs the partners, their ratio and the capital accounts 1.2 already posts to.
 *
 * Nine schema facts are introduced here, and what each one deliberately does *not* do matters
 * as much as what it does.
 *
 * 1. `pl_company_profile` gains the registration profile. The address, phone, email and tax
 *    registrations are already there (B64, migration 037), so this adds only the legal form,
 *    the registration number and the authority that issued it, the incorporation date and the
 *    financial year end. Every one is optional and empty by default, exactly like the rest of
 *    the profile: an installation that fills nothing prints what it prints today.
 *
 *    **The legal form never selects an accounting framework.** `docs/ARCHITECTURE.md` and the
 *    accounting-profile work are explicit that a framework is chosen, never inferred, and a
 *    private limited company may report under full IFRS, IFRS for SMEs or a local standard.
 *    The column is a fact about the entity's registration, and nothing in this migration or in
 *    `ownership_functions.php` reads it to decide an accounting treatment.
 *
 *    The legal form is a VARCHAR validated in PHP against `pl_legal_forms()`, not an ENUM.
 *    Adding a jurisdiction's form then costs a line of PHP rather than an ALTER on every
 *    installed database, and a form that falls out of use keeps its existing rows readable.
 *
 * 2. `pl_ownership_parties` is the register's own record of a person or an entity, and it is
 *    deliberately **not** `pl_parties`.
 *
 *    `pl_parties` carries `ck_party_roles`, which requires a party to be a customer or a
 *    vendor. A shareholder who never trades with the business is neither, so an owner cannot be
 *    a `pl_parties` row without inventing a trading role for them. B72 anticipated this by
 *    describing the related-party marker as pointing from a trade party *into* whichever core
 *    register holds the person, which is what point 8 below builds.
 *
 * 3. `pl_ownership_party_accounts` links one person in the register to their B61 partner record
 *    in one book. Ownership is a fact about the company; a capital account is a fact about a
 *    book, and a company may keep more than one. Keeping the link in its own table is what lets
 *    a single register serve every book without duplicating the person.
 *
 *    `pl_owner_partners` is **not** altered. It keeps its name, its three account columns, its
 *    ratio and the two identity keys migration 040 added, and the posting service in
 *    `owner_functions.php` is untouched. The register links to it; it does not replace it.
 *
 * 4. `pl_ownership_members` is the members register, effective dated. `profit_share` is the
 *    partnership half of an interest; the company half is the share ledger in point 7, which is
 *    why this table stores no share count. A member row is closed by an `effective_to` date
 *    rather than deleted, because who was a member during a period is exactly what a statutory
 *    register has to be able to answer years later.
 *
 * 5. `pl_ownership_officers` is the register of directors and officers. `has_significant_control`
 *    with `control_nature` is the person-with-significant-control / beneficial-owner flag; the
 *    CHECK requires the nature whenever the flag is set, because a PSC register entry that does
 *    not say *how* control is held is not a register entry.
 *
 *    The flag is **not** the related-party marker and does not create one. See point 8.
 *
 * 6. `pl_share_classes`. `authorised_shares` is NULL for a jurisdiction with no authorised
 *    capital (the UK abolished it in 2006), and there is deliberately **no issued count column**:
 *    the issued count is derived from the ledger in point 7 every time it is read. A stored
 *    count is a second source of ownership, which B63 forbids, and it is the field that goes
 *    stale first.
 *
 * 7. `pl_share_events` is the share ledger, and it is append-only in the database, not only in
 *    the service: the two triggers refuse an UPDATE and a DELETE outright, the way posted
 *    journals are already protected. A correction is therefore a linked reversal row pointing at
 *    the event it reverses, which is the same correction mechanism the rest of the core uses.
 *
 *    Which events touch the ledger of accounts, and which do not:
 *      - `allotment` and `bonus_issue` may post through `pl_post_journal()`, or carry a link to
 *        a journal that was already posted elsewhere.
 *      - `transfer` posts **nothing, ever**. A sale of shares between two shareholders is a
 *        transaction between them; the company's own assets, liabilities and equity are
 *        unchanged by it. The CHECK enforces that a transfer carries no journal at all.
 *      - `cancellation` and `redesignation` never post from here and may only carry a link to an
 *        already posted journal: a capital reduction's accounting is jurisdiction specific and
 *        court- or solvency-gated, and a redesignation between classes is a reallocation whose
 *        treatment depends on the classes' terms. Guessing either would be exactly the silent
 *        accounting guess `AGENTS.md` forbids.
 *
 * 8. `pl_related_party_markers` is B72 **as corrected by B74**, and the correction is the point
 *    of the table rather than a detail of it.
 *
 *    B72 was written when the assumption was that an employee is a related party. B74 checked
 *    the standard instead: IAS 24 relates a person through control, joint control, significant
 *    influence, or membership of key management personnel, which includes any director, and
 *    "employee" is never used as a class of related party. So **a marker row is never implied by
 *    a role, an employment link or an appointment.** There is no default-related state anywhere
 *    in this schema: a marker exists only because somebody affirmatively recorded one, and
 *    `relationship` carries only the three kinds IAS 24 actually names.
 *
 *    `related_register` names which core register holds the person and `related_id` is that
 *    register's own row id. There is no foreign key on `related_id`, on purpose: it points into
 *    a different table per register, and the employee master is 1.3 work (B70), so a key here
 *    would either be wrong or would have to be added later. `ownership_functions.php` resolves
 *    and validates it per register under the company lock, and refuses a register it does not
 *    know. This is the whole reason B72 chose this shape: the employee register arrives without
 *    a schema change.
 *
 *    Reading a marker takes the authority B58 reserves for sensitive data (`relatedparty.view`),
 *    so it stays invisible to whoever manages customers.
 *
 * 9. `pl_ownership_audit` is this register's immutable audit, and it is its own table rather
 *    than a widened `pl_core_audit` for one blunt reason: `pl_core_audit.book_id` is NOT NULL
 *    and every fact in this register belongs to a company, not to a book. Migration 041 made the
 *    same choice for `pl_user_audit` and for the same reason. It also means this migration does
 *    **not** touch the `pl_core_audit` `entity_type` ENUM at all, so it cannot repeat the union
 *    bug that migrations 037 and 038 collided over.
 *
 * Nothing here alters a posted journal, a journal line, a book row, an account or any accounting
 * amount. The only ALTER is the five optional columns on the company profile in point 1.
 *
 * Reversal path, in full:
 *
 *   DROP TRIGGER pl_share_events_no_update; DROP TRIGGER pl_share_events_no_delete;
 *   DROP TRIGGER pl_ownership_audit_no_update; DROP TRIGGER pl_ownership_audit_no_delete;
 *   DROP TABLE pl_related_party_markers, pl_share_events, pl_share_classes,
 *              pl_ownership_officers, pl_ownership_members, pl_ownership_party_accounts,
 *              pl_ownership_parties, pl_ownership_audit;
 *   ALTER TABLE pl_company_profile DROP CONSTRAINT ck_company_profile_financial_year,
 *       DROP COLUMN financial_year_end_day, DROP COLUMN financial_year_end_month,
 *       DROP COLUMN incorporation_date, DROP COLUMN registration_authority,
 *       DROP COLUMN registration_number, DROP COLUMN legal_form;
 *
 * Every posted journal an allotment or a bonus issue raised survives that reversal untouched, as
 * an ordinary journal with the source type `share_event`: dropping the register loses the
 * register, never the accounting.
 */

return [
    // ------------------------------------------------- 1. the registration profile (B63 over B64)
    <<<'SQL'
ALTER TABLE pl_company_profile
    ADD COLUMN legal_form VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' AFTER legal_name,
    ADD COLUMN registration_number VARCHAR(80) NOT NULL DEFAULT '' AFTER legal_form,
    ADD COLUMN registration_authority VARCHAR(160) NOT NULL DEFAULT '' AFTER registration_number,
    ADD COLUMN incorporation_date DATE NULL AFTER registration_authority,
    ADD COLUMN financial_year_end_month TINYINT UNSIGNED NULL AFTER incorporation_date,
    ADD COLUMN financial_year_end_day TINYINT UNSIGNED NULL AFTER financial_year_end_month
SQL,
    // A financial year end is both halves or neither. The day-in-month pair is checked in PHP
    // (pl_financial_year_end_valid()), because a CHECK cannot express "30 February is not a day"
    // portably across MySQL and MariaDB.
    <<<'SQL'
ALTER TABLE pl_company_profile
    ADD CONSTRAINT ck_company_profile_financial_year CHECK (
        (financial_year_end_month IS NULL AND financial_year_end_day IS NULL)
        OR (financial_year_end_month BETWEEN 1 AND 12 AND financial_year_end_day BETWEEN 1 AND 31))
SQL,

    // ------------------------------------------------------ 2. the people the registers share
    <<<'SQL'
CREATE TABLE pl_ownership_parties (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    kind ENUM('person','entity') NOT NULL,
    name VARCHAR(160) NOT NULL,
    country_code CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
    identifier VARCHAR(80) NOT NULL DEFAULT '',
    address VARCHAR(400) NOT NULL DEFAULT '',
    email VARCHAR(190) NOT NULL DEFAULT '',
    note VARCHAR(1000) NOT NULL DEFAULT '',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ownership_party_scope (id, company_id),
    UNIQUE KEY uq_ownership_party_name (company_id, name),
    KEY ix_ownership_party_company (company_id, is_active, name),
    CONSTRAINT ck_ownership_party_active CHECK (is_active IN (0,1)),
    CONSTRAINT ck_ownership_party_revision CHECK (revision > 0),
    CONSTRAINT fk_ownership_party_company FOREIGN KEY (company_id) REFERENCES pl_companies (id),
    CONSTRAINT fk_ownership_party_actor FOREIGN KEY (created_by) REFERENCES pl_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,

    // ------------------------------- 3. one person's B61 capital, drawings and loan accounts
    // The unique key in both directions is the rule migration 040 established for partners: an
    // account belongs to one person, and a person has one partner record per book.
    <<<'SQL'
CREATE TABLE pl_ownership_party_accounts (
    company_id BIGINT UNSIGNED NOT NULL,
    book_id BIGINT UNSIGNED NOT NULL,
    ownership_party_id BIGINT UNSIGNED NOT NULL,
    partner_id BIGINT UNSIGNED NOT NULL,
    linked_by BIGINT UNSIGNED NOT NULL,
    linked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (book_id, ownership_party_id),
    UNIQUE KEY uq_ownership_party_partner (book_id, partner_id),
    CONSTRAINT fk_ownership_accounts_party FOREIGN KEY (ownership_party_id, company_id) REFERENCES pl_ownership_parties (id, company_id),
    CONSTRAINT fk_ownership_accounts_book FOREIGN KEY (book_id, company_id) REFERENCES pl_books (id, company_id),
    CONSTRAINT fk_ownership_accounts_partner FOREIGN KEY (partner_id) REFERENCES pl_owner_partners (id),
    CONSTRAINT fk_ownership_accounts_actor FOREIGN KEY (linked_by) REFERENCES pl_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,

    // ------------------------------------------------- 4. the members register, effective dated
    <<<'SQL'
CREATE TABLE pl_ownership_members (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    ownership_party_id BIGINT UNSIGNED NOT NULL,
    effective_from DATE NOT NULL,
    effective_to DATE NULL,
    profit_share DECIMAL(9,6) NULL,
    note VARCHAR(500) NOT NULL DEFAULT '',
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ownership_member_scope (id, company_id),
    UNIQUE KEY uq_ownership_member_start (company_id, ownership_party_id, effective_from),
    KEY ix_ownership_member_period (company_id, effective_from, effective_to),
    CONSTRAINT ck_ownership_member_period CHECK (effective_to IS NULL OR effective_to >= effective_from),
    CONSTRAINT ck_ownership_member_share CHECK (profit_share IS NULL OR (profit_share >= 0 AND profit_share <= 1)),
    CONSTRAINT ck_ownership_member_revision CHECK (revision > 0),
    CONSTRAINT fk_ownership_member_party FOREIGN KEY (ownership_party_id, company_id) REFERENCES pl_ownership_parties (id, company_id),
    CONSTRAINT fk_ownership_member_actor FOREIGN KEY (created_by) REFERENCES pl_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,

    // ----------------------------------------- 5. the directors and officers register with PSC
    <<<'SQL'
CREATE TABLE pl_ownership_officers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    ownership_party_id BIGINT UNSIGNED NOT NULL,
    officer_role ENUM('director','managing_director','chief_executive','company_secretary','chief_financial_officer','partner','other') NOT NULL,
    role_title VARCHAR(120) NOT NULL DEFAULT '',
    appointed_on DATE NOT NULL,
    resigned_on DATE NULL,
    has_significant_control TINYINT(1) NOT NULL DEFAULT 0,
    control_nature VARCHAR(300) NOT NULL DEFAULT '',
    note VARCHAR(500) NOT NULL DEFAULT '',
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ownership_officer_scope (id, company_id),
    UNIQUE KEY uq_ownership_officer_appointment (company_id, ownership_party_id, officer_role, appointed_on),
    KEY ix_ownership_officer_period (company_id, appointed_on, resigned_on),
    CONSTRAINT ck_ownership_officer_period CHECK (resigned_on IS NULL OR resigned_on >= appointed_on),
    CONSTRAINT ck_ownership_officer_control CHECK (has_significant_control IN (0,1) AND (has_significant_control = 0 OR control_nature <> '')),
    CONSTRAINT ck_ownership_officer_revision CHECK (revision > 0),
    CONSTRAINT fk_ownership_officer_party FOREIGN KEY (ownership_party_id, company_id) REFERENCES pl_ownership_parties (id, company_id),
    CONSTRAINT fk_ownership_officer_actor FOREIGN KEY (created_by) REFERENCES pl_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,

    // ------------------------------------------------------------------------ 6. share classes
    <<<'SQL'
CREATE TABLE pl_share_classes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name VARCHAR(120) NOT NULL,
    -- Ordinary or preference. Two values only, because that is the distinction the Open Cap
    -- Format export has to carry (COMMON / PREFERRED); the rights themselves are the free text
    -- below, because no two jurisdictions agree on how to enumerate them.
    class_type ENUM('common','preferred') NOT NULL DEFAULT 'common',
    currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    nominal_value DECIMAL(20,4) NOT NULL,
    votes_per_share DECIMAL(12,4) NOT NULL DEFAULT 1.0000,
    authorised_shares DECIMAL(24,6) NULL,
    is_option_pool TINYINT(1) NOT NULL DEFAULT 0,
    dividend_rights VARCHAR(1000) NOT NULL DEFAULT '',
    liquidation_rights VARCHAR(1000) NOT NULL DEFAULT '',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_share_class_scope (id, company_id),
    UNIQUE KEY uq_share_class_code (company_id, code),
    CONSTRAINT ck_share_class_nominal CHECK (nominal_value >= 0),
    CONSTRAINT ck_share_class_votes CHECK (votes_per_share >= 0),
    CONSTRAINT ck_share_class_authorised CHECK (authorised_shares IS NULL OR authorised_shares > 0),
    CONSTRAINT ck_share_class_flags CHECK (is_option_pool IN (0,1) AND is_active IN (0,1)),
    CONSTRAINT ck_share_class_revision CHECK (revision > 0),
    CONSTRAINT fk_share_class_company FOREIGN KEY (company_id) REFERENCES pl_companies (id),
    CONSTRAINT fk_share_class_actor FOREIGN KEY (created_by) REFERENCES pl_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,

    // ----------------------------------------------------- 7. the append-only share ledger
    <<<'SQL'
CREATE TABLE pl_share_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    share_class_id BIGINT UNSIGNED NOT NULL,
    event_type ENUM('allotment','transfer','cancellation','bonus_issue','redesignation') NOT NULL,
    effective_date DATE NOT NULL,
    from_party_id BIGINT UNSIGNED NULL,
    to_party_id BIGINT UNSIGNED NULL,
    to_share_class_id BIGINT UNSIGNED NULL,
    quantity DECIMAL(24,6) NOT NULL,
    consideration_currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NULL,
    consideration_amount DECIMAL(20,4) NULL,
    nominal_total DECIMAL(20,4) NULL,
    premium_total DECIMAL(20,4) NULL,
    certificate_reference VARCHAR(80) NOT NULL DEFAULT '',
    reason VARCHAR(500) NOT NULL DEFAULT '',
    book_id BIGINT UNSIGNED NULL,
    journal_id BIGINT UNSIGNED NULL,
    reversal_of_id BIGINT UNSIGNED NULL,
    request_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_share_event_scope (id, company_id),
    UNIQUE KEY uq_share_event_request (company_id, request_key),
    UNIQUE KEY uq_share_event_reversal (reversal_of_id),
    KEY ix_share_event_timeline (company_id, effective_date, id),
    KEY ix_share_event_class (share_class_id, effective_date, id),
    CONSTRAINT ck_share_event_quantity CHECK (quantity > 0),
    CONSTRAINT ck_share_event_money CHECK (
        (consideration_amount IS NULL OR consideration_amount >= 0)
        AND (nominal_total IS NULL OR nominal_total >= 0)
        AND (premium_total IS NULL OR premium_total >= 0)),
    -- A transfer is a transaction between two shareholders; the company's own books do not move.
    CONSTRAINT ck_share_event_transfer_never_posts CHECK (
        event_type <> 'transfer' OR (journal_id IS NULL AND book_id IS NULL)),
    -- A journal link always names the book it was posted in, and vice versa.
    CONSTRAINT ck_share_event_journal_book CHECK ((journal_id IS NULL) = (book_id IS NULL)),
    -- Each event type names the sides it actually has.
    CONSTRAINT ck_share_event_sides CHECK (
        (event_type IN ('allotment','bonus_issue') AND to_party_id IS NOT NULL AND from_party_id IS NULL AND to_share_class_id IS NULL)
        OR (event_type = 'transfer' AND from_party_id IS NOT NULL AND to_party_id IS NOT NULL AND to_share_class_id IS NULL)
        OR (event_type = 'cancellation' AND from_party_id IS NOT NULL AND to_party_id IS NULL AND to_share_class_id IS NULL)
        OR (event_type = 'redesignation' AND from_party_id IS NOT NULL AND to_party_id IS NOT NULL AND to_share_class_id IS NOT NULL)),
    CONSTRAINT fk_share_event_class FOREIGN KEY (share_class_id, company_id) REFERENCES pl_share_classes (id, company_id),
    CONSTRAINT fk_share_event_target_class FOREIGN KEY (to_share_class_id, company_id) REFERENCES pl_share_classes (id, company_id),
    CONSTRAINT fk_share_event_from FOREIGN KEY (from_party_id, company_id) REFERENCES pl_ownership_parties (id, company_id),
    CONSTRAINT fk_share_event_to FOREIGN KEY (to_party_id, company_id) REFERENCES pl_ownership_parties (id, company_id),
    CONSTRAINT fk_share_event_journal FOREIGN KEY (journal_id, company_id, book_id) REFERENCES pl_journals (id, company_id, book_id),
    CONSTRAINT fk_share_event_reversal FOREIGN KEY (reversal_of_id, company_id) REFERENCES pl_share_events (id, company_id),
    CONSTRAINT fk_share_event_actor FOREIGN KEY (created_by) REFERENCES pl_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
    // Append-only in the database, not only in the service. A correction is a linked reversal
    // row, exactly as it is for a posted journal.
    "CREATE TRIGGER pl_share_events_no_update BEFORE UPDATE ON pl_share_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The share ledger is append-only; correct an event with a linked reversal'",
    "CREATE TRIGGER pl_share_events_no_delete BEFORE DELETE ON pl_share_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Share ledger events cannot be deleted'",

    // ------------------------------------- 8. the related-party marker (B72 as narrowed by B74)
    <<<'SQL'
CREATE TABLE pl_related_party_markers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    party_id BIGINT UNSIGNED NOT NULL,
    related_register ENUM('officer','owner','employee') NOT NULL,
    related_id BIGINT UNSIGNED NOT NULL,
    relationship ENUM('key_management','close_family_member','controlled_entity') NOT NULL,
    note VARCHAR(500) NOT NULL DEFAULT '',
    effective_from DATE NOT NULL,
    effective_to DATE NULL,
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_related_party_scope (id, company_id),
    UNIQUE KEY uq_related_party_marker (company_id, party_id, related_register, related_id, relationship),
    KEY ix_related_party_register (company_id, related_register, related_id),
    CONSTRAINT ck_related_party_period CHECK (effective_to IS NULL OR effective_to >= effective_from),
    CONSTRAINT ck_related_party_revision CHECK (revision > 0),
    CONSTRAINT fk_related_party_party FOREIGN KEY (party_id, company_id) REFERENCES pl_parties (id, company_id),
    CONSTRAINT fk_related_party_actor FOREIGN KEY (created_by) REFERENCES pl_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,

    // -------------------------------------------------------- 9. the register's immutable audit
    <<<'SQL'
CREATE TABLE pl_ownership_audit (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    entity_type ENUM('company_registration','ownership_party','ownership_member','ownership_officer','share_class','share_event','related_party_marker','partner_link') NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(40) NOT NULL,
    reason VARCHAR(500) NOT NULL DEFAULT '',
    before_state JSON NULL,
    after_state JSON NOT NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_ownership_audit_entity (company_id, entity_type, entity_id, id),
    KEY ix_ownership_audit_company (company_id, id),
    CONSTRAINT fk_ownership_audit_company FOREIGN KEY (company_id) REFERENCES pl_companies (id),
    CONSTRAINT fk_ownership_audit_actor FOREIGN KEY (actor_id) REFERENCES pl_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
    "CREATE TRIGGER pl_ownership_audit_no_update BEFORE UPDATE ON pl_ownership_audit FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ownership register audit records are immutable'",
    "CREATE TRIGGER pl_ownership_audit_no_delete BEFORE DELETE ON pl_ownership_audit FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ownership register audit records cannot be deleted'",
];
