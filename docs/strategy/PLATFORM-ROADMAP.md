# Platform roadmap

Drafted 19 September 2026 from owner requests in a planning session. It covers the platform work that sits under distribution: several installations in one database, database engines, cloud-hosted databases, installer identity, the installation notice, a package directory for plugins and sample companies, and a full Users module. WordPress is the reference architecture the owner named; each section says what is copied from it and where PHP Ledger differs.

Nothing here is implemented unless its status says so. Distribution channels are in the [distribution plan](DISTRIBUTION-PLAN.md) and the per-release sequence is in the [release protocol](../RELEASE-PROTOCOL.md).

## Owner decisions, 19 September 2026

| # | Decision | Consequence |
|---|---|---|
| P1 | **Plugins: an official verified marketplace, plus owner uploads of any plugin ZIP with warnings and disclaimers.** "We should not take away people's freedom of what they do with their software." | Two trust tiers, both supported. Supersedes the "no arbitrary uploaded PHP" line in the [module roadmap](../MODULE-ROADMAP.md#versioned-module-contract). |
| P2 | **Database engines: MySQL and MariaDB now, PostgreSQL next, SQLite for single-user bundles. No MongoDB. All database access stays inside MeekroDB, which already supports all three engines.** | Portable SQL first; engine migration sets follow. |
| P3 | **Several installations may share one database through a table prefix**, as WordPress does. | Table-prefix work. |
| P4 | **Installations may use a cloud database on a free tier** (AWS, Azure, Google and similar). | TLS connections and a MySQL 8.0 floor. |
| P5 | **The installer lets the user choose a username and password, and optionally upload a company logo.** | Schema change plus installer fields. |
| P6 | **Installations report their installation to phpledger.com**, so the project knows who installed and when. | Changes distribution principle 4 and the licensing-policy wording on outbound contact; see the installation-notice section. |
| P7 | **A proper Users module**: users, user meta, profile, user management, roles and permissions, like standard software. | Designed now so earlier work does not block it. |
| P8 | **npm, Homebrew, Bitnami and DigitalOcean stay on the roadmap** as future channels. | Recorded in the distribution plan. |
| P9 | Windows and Android clients are planned. | Feeds carry a `min_client` field; TLS work covers clients. |
| P10 | **Sample companies and plugins ship as separate packages from a phpledger.com directory, never inside the core package** (19 September 2026, evening). Samples may require plugins. | Package directory section below; decisions B18 and B19 in the [decision register](DECISION-REGISTER.md#g-decisions-taken-by-the-owner-19-september-2026). |

## Sequence

| Order | Work | Depends on | Status |
|---|---|---|---|
| 0 | Stock locations and trading-document extensions as bundled modules; on-account receipts in core AR | none | Stock locations shipped unannounced inside 1.1.1 (migration 034; its release surfaces are due in 1.2); trading documents and on-account receipts planned for 1.2 (owner, 19 Sep 2026, evening). Sequence, gates and dates in the [1.2 release plan](RELEASE-PLAN-1.2.md) |
| 1 | Release protocol and feed | Publisher key (owner) | Started 19 Sep 2026 |
| 2 | Container image, Packagist, catalogue manifests | 1 | Container image planned for 1.2 (item g); Packagist and catalogue manifests 1.3 |
| 3 | Username and company logo (schema, login, installer fields) | Installer branch merge for the form | Shipped in 1.1.0 |
| 4 | Table prefix and portable SQL through MeekroDB, in one pass | Installer branch merge | Planned for 1.3 |
| 5 | Installation notice and the phpledger.com endpoint | 1; endpoint deployment is a separate owner-requested action | Planned for 1.3 |
| 5a | Unbundle the demo packs and add the package directory resolver | none; data-file change plus a path resolver | Did not ship in 1.1.1 (the eleven packs are still in `tools/package-files.json`); first step of the 1.2 plugin-runtime milestone, issue #71 |
| 5b | Package manifest, directory feed and in-app package installer (samples first, plugins second) | 1, 5a | Planned for 1.2 with the plugin runtime, issue #72 |
| 6 | Plugin runtime: hooks, loader, activation, plugin receipt table | 1 (release feed for verified packages) | Planned for 1.2 |
| 7 | Cloud-database support and guides | 4 | Planned for 1.3 |
| 8 | Users module | 6 | Capability catalogue and per-company roles planned for 1.2; the full module (profiles, invitations, sessions, audit) later |
| 9 | PostgreSQL migration sets and CI | 4 | After 1.2 |
| 10 | SQLite migration sets and locking overrides | 4 | With the Windows bundle |

## Table prefix: several installations in one database

**Today.** No prefix. About 82 tables named `pl_*` are written literally in SQL across 58 function files and 32 migrations. The updater's database inventory requires every table to match `^pl_[a-z0-9_]+$`.

**Plan.**
- `PL_DB_PREFIX` and `db_prefix` in the private configuration, default `pl_`, validated as `^[a-z][a-z0-9_]{0,15}_$`. Existing installations keep `pl_` and need no change.
- One resolver in a new `database_functions.php`. SQL is written with `{{accounts}}`-style tokens that the resolver expands. A tool converts the existing SQL mechanically, and a lint rule rejects literal `pl_` table names outside the resolver.
- Migrations, triggers, the migration receipt table and the updater's backup, restore and financial digest all use the resolver, so upgrading or restoring one installation never touches another installation's tables.
- The installer's database step gets a prefix field and refuses a prefix that is already in use.

**Caution.** Shared databases share one set of credentials. An installation that is compromised can read its neighbours. The documentation recommends separate databases when the host allows them.

## Database engines

**MeekroDB is the database layer.** The installed MeekroDB 3.1.5 is PDO-based and connects to `mysql`, `pgsql` and `sqlite`. Its helpers (`insert`, `update`, `delete`, `insertUpdate`, `insertIgnore`, identifier quoting, table and column listing) already generate the right SQL for each engine. Owner direction, 19 September 2026: keep every database operation strictly inside MeekroDB, so the application does not depend on one engine.

**Where the code stands (measured 19 September 2026).**

| Access path | Calls |
|---|---|
| Portable MeekroDB helpers (`insert`, `update`, `delete`, `insertUpdate`) | about 130 |
| Raw SQL passed through MeekroDB (`query`, `queryFirstRow`, `queryFirstField`, `queryFirstColumn`) | about 417 |
| Direct PDO or mysqli outside MeekroDB | 1 tool (`tools/verify-currency-upgrade.php`) |

The connection is already a single point. What still ties the product to MySQL is the text of the raw SQL, which MeekroDB passes through unchanged:

| MySQL-specific construct | Files |
|---|---|
| `CREATE TRIGGER` immutability guards (in migrations) | 25 |
| `JSON_*` functions | 39 |
| `FOR UPDATE` row locks | 27 |
| MySQL DDL (`ENGINE=InnoDB`, `AUTO_INCREMENT`, `utf8mb4_0900_ai_ci`) | 21 to 28 |

`GET_LOCK`, `ON DUPLICATE KEY` and `SKIP LOCKED` appear in a few more. The installer branch translates three of these spellings for MariaDB just before execution.

**Plan.**
- **Rules, enforced by lint.** No PDO or mysqli outside MeekroDB. Prefer MeekroDB helpers over hand-written `INSERT`, `UPDATE` and upsert SQL; `ON DUPLICATE KEY` becomes `DB::insertUpdate`. Raw SQL must be portable, or call one of a few named dialect helpers for the unavoidable differences: row locks, advisory locks, JSON extraction and the current timestamp.
- **Connection by DSN.** Configure MeekroDB through `DB::$dsn` built from `PL_DB_DRIVER` (default `mysql`), so the same configuration shape covers all three engines.
- **Migrations per engine.** DDL and triggers genuinely differ, so a migration may carry a statement list per engine, with MySQL required and others optional, each checksummed. The core chain reports a missing engine set clearly instead of running MySQL DDL elsewhere.
- **Done together with the table prefix**, because both touch the same SQL strings once.
- **Use MeekroORM for records** (owner direction, 19 September 2026: "use the full power of MeekroDB"). `orm.class.php` ships in the same package and runs on all three engines. See the next section.
- **PostgreSQL.** Add the PostgreSQL migration sets (PL/pgSQL triggers, `jsonb`) and a CI job. Supabase and Neon are then usable as hosted PostgreSQL.
- **SQLite.** For single-user installations such as the Windows bundle and a Homebrew launcher. One writer, no row locks, `RAISE(ABORT)` triggers.
- **Not MongoDB.** MeekroDB does not support it, and a double-entry ledger depends on foreign keys, transactions across several tables and triggers that make posted journals immutable.

## MeekroORM for application records

MeekroORM (`vendor/sergeytsalkov/meekrodb/orm.class.php`, same 3.1.5 package) is an active-record layer on top of MeekroDB. It is not used today. What it provides, checked in the vendored source:

| Feature | Use in PHP Ledger |
|---|---|
| One class per table, with an overridable `_tablename()` | A single `PL_Model` base class adds the table prefix, so every model is prefix-aware without touching SQL |
| `belongs_to`, `has_one`, `has_many` associations | Users and roles, companies and members, plugins and their settings |
| Typed columns: bool, int, double, datetime, JSON | Replaces most `JSON_*` SQL with portable PHP handling |
| `_validate_<field>`, `_pre_save`, `_post_save`, `_pre_create`, `_post_create`, `_pre_destroy` | Validation in one place, and the points where plugin hooks fire (`user.created`, `company.updated` and so on) |
| Scopes, `Search`, `SearchMany`, `where`, `all` | Portable lookups instead of hand-written `SELECT` |
| Row `lock()` and a transaction around each `save()` | Simple optimistic and pessimistic edits |

**Where it applies.**
- **New code uses models from the start**: the Users module (users, user meta, roles, capabilities, invitations, sessions), plugins and their settings, installation settings, and the installation notice.
- **Existing record-keeping code moves to models when it is touched** for the table-prefix pass: companies, members, parties, products, tax codes, settings, connections, module state.
- **The ledger core stays as hand-written MeekroDB queries**: journal posting, period close, reports, reconciliation and anything that locks several rows in order or aggregates. An ORM adds nothing there and would hide the locking order the accounting guarantees depend on.
- Immutability stays in database triggers. A model callback is a convenience, not the guard, because other code paths and plugins can still write through plain queries.

**Limits to handle.** `lock()` emits `FOR UPDATE`, which SQLite rejects, so the SQLite work overrides it in `PL_Model`. The ORM reads each table's columns once per request to learn its structure; the cost is one metadata query per model table, which is acceptable but worth measuring on shared hosting.

## Cloud-hosted databases

The owner's case: the application runs on inexpensive PHP hosting and the database runs on a cloud free tier.

**Blockers today.** The database connection has no TLS options, and cloud databases require TLS. MySQL is pinned to 8.4, while most managed MySQL is 8.0.

**Plan.**
- `PL_DB_SSL_CA`, `PL_DB_SSL_VERIFY`, `PL_DB_SSL_CERT` and `PL_DB_SSL_KEY`, with private-configuration equivalents and an installer field for the CA certificate. They map to PDO TLS attributes through MeekroDB's existing `DB::$connect_options`, so no code outside MeekroDB opens the connection.
- Accept MySQL 8.0 as well as 8.4; keep 8.4 as the tested recommendation and add 8.0 to CI.
- ~~Preflight detects a binary-logged server without `log_bin_trust_function_creators=1` and says exactly which parameter to set, instead of failing during migration.~~ **Delivered ahead of this work** for every installation, not only cloud databases, after issue #83 reported it on a stock local MySQL 8.4. Migrations also record their progress and resume where they stopped. See the [installer contract](../INSTALLER.md).
- Guides for AWS RDS, Azure Database for MySQL and Aiven, and for Google Cloud SQL on trial credit. Free-tier terms change often, so each guide carries its check date. Services without triggers or foreign keys are listed as unsupported.

## Installer identity: username, password and company logo

**Today.** `pl_users` has email, display name and password hash; there is no username. `pl_companies` has no logo. The installer branch's final step already asks for a name, an email used to sign in and a chosen password.

**Plan.**
- A migration adds `pl_users.username` (unique, lowercase, 3 to 32 characters) and `pl_companies.logo_path`.
- Sign-in accepts a username or an email in one field.
- The installer's final step adds a username field and an optional logo upload. `install/create-admin.php` gains `--username`.
- Logos are stored in private storage and served through an authenticated route, never from the public folder. The company logo appears in the application header and on printed documents.

## Installation notice

**Owner decision P6.** The project wants to know who installed PHP Ledger and when.

**Policy change.** The distribution plan's principle 4 ("no phone-home … the only outbound contact is an opt-in, anonymous update check") and the licensing policy's statement that the software never contacts a licensing server are amended together with this work. The notice is never a condition for using the software; a failed or disabled notice changes nothing.

**Plan.** The installer's final step shows two choices:
1. **Tell phpledger.com this copy was installed** (on by default). Sends a random installation ID, version, channel, PHP version, database engine and version, operating-system family, installation mode and time.
2. **Register this installation** (off by default). Adds the owner's name, email, site address and company name, for release announcements and support.

The same installation ID accompanies the update check, so active installations can be counted over time. An administrator can switch both off; the Updates page shows what was last sent. The website's privacy page lists every field.

The phpledger.com endpoint is a small PHP script that validates, rate-limits and stores notices, with an owner-only summary. Deploying it changes the website host and is a separate owner-requested action under the operator runbook.

## Package directory: plugins and sample companies

**Decision (P10, 19 September 2026).** The core package carries the accounting application and its bundled modules only. Everything a user adds afterwards, whether code (a plugin) or data (a sample company), is a separately built, separately versioned package downloaded from a directory on phpledger.com or uploaded by the owner. This is the WordPress split between core and its plugin and theme directories.

**Why.** Eleven demo packs (1.9 MB, close to half the packaged source bytes) ship in every 1.1.0 ZIP and cannot be started in production since the sample gate landed. Sample companies for restaurant, pharmacy, export and freelancer work only make sense alongside the plugin that renders them, and that pairing cannot be expressed inside one core package. Keeping vertical work out of the core release gate lets each vertical move at its own pace.

**Today.** A manifest-driven bundled-module system exists: a fixed list of manifests in `resources/modules/`, per-company enablement with an audit trail, and a Modules page. The demo packs are read from `resources/demo-packs/` by `pl_demo_pack_catalog()`; only `resources/core-samples/core-accounting-1.0.0.json` is needed for first-run onboarding. Signed release metadata, a pinned publisher key, `pl_update_verify_metadata()` and an allowlisted downloader already exist for core updates and are reused here. No plugin code exists.

**Copied from WordPress.** Actions and filters with priorities; a plugin folder with a manifest; activation, deactivation and uninstall; a plugin screen with upload, update and delete; a directory served from the project website; plugin data in prefixed tables and in user and option meta.

**Different from WordPress.** Plugins declare their capabilities, migrations, routes and menus in the manifest, and the core validates them before activation. Plugin migrations live in their own receipt table, so removing a plugin can never break the core migration chain. Posting to the ledger still goes through the core posting service. Sample companies are a second package type that WordPress does not have: pure data, installed at a lower trust tier, never containing PHP.

**Package manifest.** Every package carries `package.json` with: `type` (`plugin` or `sample`), `slug`, `version`, `contract` (the schema contract the payload was written against), `requires` (a core version range, plugin slugs with version ranges, bundled module ids), `files` with a SHA-256 per file, `licence`, `homepage`. The core refuses a package whose `contract` it does not support or whose `requires` are not satisfied, and says which requirement is missing.

**Plan.**
1. **Unbundle (1.1.1).** A package directory resolver, default `storage/packages/samples/` and `storage/packages/plugins/`, with local, test and demo environments falling back to the repository copies. The eleven demo packs and `catalog.json` leave `tools/package-files.json`; the core-accounting sample stays. The public demo mounts the packs into the package directory and becomes the first consumer of sample packages. Pack sources stay in the repository as build inputs for `tools/build-demo-packs.py`.
2. **Directory feed.** A static `/directory/index.json` on phpledger.com plus one page per package, generated by the website build from a data file in the same way as the release feed. Packages are published as GitHub release assets in one repository per package under the phpledger organisation (`phpledger/sample-<slug>`, `phpledger/plugin-<slug>`), which the existing download allowlist already trusts. Submissions are pull requests reviewed against a published checklist.
3. **Package installer in the application.** Browse the directory, install, update and uninstall. Verified packages are checked against the project key before anything is written; owner uploads follow the two-tier rule below. Sample installs appear in the onboarding chooser; the chooser links to the directory for more.
4. **Hooks API**: `pl_add_action`, `pl_do_action`, `pl_add_filter` and `pl_apply_filters`, with hook points at document posting, period close, reports, navigation, routes, templates, settings and the read API.
5. **Plugin format**: `www/phpledger/plugins/<slug>/` with `plugin.json` (the package manifest, module contract 2) and `plugin.php`. Tables use the installation prefix plus the slug. Migrations sit in the plugin's own folder and receipt table. Until the table-prefix resolver exists, plugin tables are named `pl_<slug>_*`; the resolver later treats `pl_` as the installation prefix, so no plugin migration changes when the prefix work lands.
6. **Loader**: plugin manifests are discovered from the folder; code loads only for activated plugins whose files still match the digest recorded at installation.
7. **Two trust tiers (P1).**
   - **Verified.** Packages from the official directory are signed by the project key, or by a directory review key after review. They show a Verified badge and can update automatically.
   - **Uploaded by the owner.** The owner can upload any package ZIP. An unsigned or unknown plugin installs only after a confirmation page stating that it has not been reviewed, runs with full access to the application and database, may break upgrades or damage records, that backups are the owner's responsibility, and that support covers verified packages only. The plugin keeps an Unverified badge, the audit trail records who accepted the warning and the package digest, and it never updates automatically.
   - **Samples are data.** A sample package contains no code, so an uploaded sample needs only the archive checks. But a sample whose `requires` names an unverified plugin surfaces that plugin's confirmation page first; a sample never installs a plugin quietly.
   - Both tiers pass the same archive checks, so a malformed archive is rejected before anything is written.
8. **Admin > Packages**, next to Modules: installed plugins and samples, activate, deactivate, update, uninstall (with a choice to keep or delete a plugin's data), upload, and a Directory tab.
9. **Licensing.** Plugins run inside the application and are treated as combined works under the AGPL. Directory listing requires an AGPL-compatible licence; other terms use the existing commercial-licence route. Sample data packages are published under CC0 so training institutes can redistribute them. The owner's freedom principle applies to what users install on their own copy, not to what the directory lists.

**Verticals.** Restaurant POS, pharmacy POS, exporter and freelancer invoicing are the first plugins, each with a paired sample package (B19). The Pakistan pharmacy sample becomes the pharmacy sample package. Core, AR, AP, Inventory and Purchasing stay bundled.

## Users module

**Today.** Three fixed roles (owner, accountant, viewer) stored as an enumeration on company membership and checked in code. No user-management screen; administrators are created from the command line.

**Copied from WordPress.** Users, user meta, roles and capabilities as separate tables; a profile screen; a Users screen; plugins adding capabilities and meta.

**Different from WordPress.** Roles are assigned per company, because one person can be an owner of one business and a viewer of another.

**Plan.**
- **Built on MeekroORM models** (`PL_User`, `PL_UserMeta`, `PL_Role`, `PL_Capability`, `PL_Invitation`, `PL_Session`), with lifecycle callbacks firing the user hooks. This is the first module written that way and sets the pattern.
- **Tables.** `pl_users` gains username, status (active, invited, suspended, deactivated), locale, timezone, last sign-in, password-changed time, avatar and a reserved two-factor secret. New tables: `pl_user_meta`, `pl_roles`, `pl_role_capabilities`, `pl_capabilities`, `pl_user_invitations`, `pl_password_resets`, `pl_user_sessions` and an immutable `pl_user_audit`. Company membership moves from the role enumeration to a role reference, with the three current roles kept as protected system roles.
- **Authorisation.** One call, `pl_user_can(actor, company, capability)`, replaces literal role checks. The plugin platform introduces the capability catalogue first so this module only makes roles editable.
- **My profile.** Name, username, email change with confirmation, password change, avatar, locale and timezone, active sessions with sign-out everywhere, and the user's own activity.
- **Admin › Users.** Search and filter, create, invite by email, resend or revoke invitations, edit, assign roles per company, suspend, reactivate, deactivate, force a password reset, and view activity. Users referenced by posted records are never hard-deleted; they can be anonymised on request.
- **Admin › Roles.** Custom roles per company or for the whole installation, a capability matrix, cloning from a system role, and deletion only when unused.
- **Email.** Invitations and resets use the outbound queue, with a copyable link when no mail service is configured.
- **Hooks.** User created, invited, role changed and profile updated; a filter on capabilities.
- **API.** Scopes aligned with capabilities, and a "current user" endpoint.
