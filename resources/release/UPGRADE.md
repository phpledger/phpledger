# Upgrade and recovery — PHP Ledger

## Operator-initiated automatic updates

This release adds `/maintenance.php`, an independent installation-operator interface for publisher-signed releases. It is not a certified shared-host recovery service: automated and fault-injection tests, and exact-artifact installation/upgrade/recovery checks have passed, but independent security review and real restricted-host fault recovery have not been evidenced (see RELEASE-NOTES.md "Assurance status"). The manual procedure below remains the expert recovery path and is required for the first upgrade from a published 0.6.0-preview installation, which predates this updater.

Provision a private installation directory (`PL_INSTALL_DIRECTORY`, default `www/phpledger/storage/installation`) and a strong random `operator.key` of at least 32 characters; browser setup creates a separate operator key. Pin the publisher's independently obtained RSA public key as `publisher.pem`, or set `PL_UPDATE_PUBLIC_KEY` to its private host-controlled location. No key supplied inside a release establishes trust. Never store the publisher private signing key on a customer installation. Company-owner access does not grant update authority.

The operator selects stable or preview, supplies signed metadata and a matching ZIP (or explicitly requests the official download), then confirms the operation. Signed metadata binds version, channel, archive size/hash and every file. The updater stages privately, blocks/drains application writes, takes matched code/config/key/database backups, applies the release, runs migrations and verifies the result. Failed mutations automatically restore the matched backup. Keep the browser open for bounded continuation requests; after a host interruption reopen the independent maintenance page to resume. Maintenance stays active until verification succeeds. CLI/external database writers must respect the same maintenance boundary.

PHP ZIP and OpenSSL, sufficient private disk space, the supported uncustomized schema on MySQL 8.4 or MariaDB 10.4+, complete schema/view/trigger recovery privileges and writable release destinations are required. Routines/events, custom databases and unknown migration states are rejected rather than silently omitted. Preserve private backups outside the public root and apply an operator-controlled retention policy. Broad hosting compatibility, independent security review and real restricted-host fault recovery must be evidenced before production recommendation. A host or disk that is unavailable cannot execute recovery until service resumes.

The updater uses the configured database identity. It must own the supported view/trigger definers and have the required privileges scoped to that database; a separate runtime identity without DDL privileges is rejected before mutation. Supplying a separate temporary privileged update identity is not implemented. Do not grant server-wide privileges to bypass this boundary. Database snapshots and restores use resumable batches with durable progress; original records and financial totals are checked before reopening.

The independent `public/maintenance.php` loader is pinned: automated packages must contain the same loader bytes. Changing this loader requires a separately reviewed hosting-panel procedure. The saved recovery worker remains outside the application being replaced. Final acceptance loads the replacement bootstrap in a fresh authenticated request; a broken or interrupted bootstrap triggers restoration. `php tools/resume-update.php --drain` can advance bounded recovery stages when shell access is available, but successful-update runtime acceptance still requires the authenticated maintenance page. A recurring scheduler is not installed. The browser maintenance page refuses further operator-key attempts for 15 minutes after ten failures; `php tools/resume-update.php` reads the host-held key directly and is not subject to that limit, so a remote guesser cannot lock the host operator out of command-line recovery.

Each release's source revision is recorded in its `PACKAGE-MANIFEST.json`.

This package adds the accounting starter to the modern foundation: required AR/AP workflows and manual core tax, optional Purchasing/Inventory, and reviewed opening conversions. Preserve the complete supplied migration chain and all existing checksums. Recognized older modern schemas apply their missing migrations in order. The repository includes disposable verification for the published 0.3.0 baseline through migration 016; your exact package and backup still require a restoration rehearsal. No automatic upgrade from the historical application, an unpublished quote worktree or a customized schema is supported.

## From 0.5.0-preview to 0.6.0-preview

Keep the installed chain through 028 unchanged. This release adds 029-031 for cost-of-sales presentation, connection read scopes and list-source indexes. Existing chart classifications retain prior net-profit behavior; existing connections retain their previous access. The interface change does not require Node on the host. Use the backup/maintenance procedure below and run migrations before reopening traffic. Restore both matching code and database if rollback is needed; copying old PHP over an upgraded database is not a tested rollback.

## From 0.6.0-preview to 1.0.0

Keep the installed chain through 031 unchanged; 1.0.0 adds no new migration. This release adds browser installation at `/install` (new installations only; an existing 0.6.0-preview installation is already set up) and the signed-update/recovery path through `/maintenance.php`, `tools/resume-update.php` and the release/signing tools. A published 0.6.0-preview installation predates `/maintenance.php`, so its first upgrade to 1.0.0 must use the manual procedure below, not the new updater. After completing that manual upgrade, the operator may provision the private installation directory, `operator.key` and a pinned `publisher.pem` to use `/maintenance.php` for later updates.

Use the backup/maintenance procedure below and run migrations before reopening traffic, exactly as for the 0.5.0-preview to 0.6.0-preview upgrade. Restore both matching code and database if rollback is needed; copying old PHP over an upgraded database is not a tested rollback.

## From 1.1.2 to 1.1.3

A files-only upgrade: no migration, no receipt change, no accounting change. It fixes the in-app updater ([issue #90](https://github.com/phpledger/phpledger/issues/90)), and the 1.1.2 notice below no longer applies from this release onward: install it from `/maintenance.php` with `phpledger-1.1.3.update.json` once that metadata is signed (the pinned publisher key applies; the fix is in the new release's files, so the copied runtime of your installed 1.1.2 completes the update), or replace the files with the manual procedure below. `public/maintenance.php` is unchanged.

## From 1.1.0 or 1.1.1 to 1.1.3

**Use the manual procedure below, not `/maintenance.php`.** Replace the files and run `php www/phpledger/install/migrate.php` once; from 1.1.0 that also applies migration 034 (the stock-locations tables and the optional module, which stays off).

An in-app update from 1.1.0 stops in its migrate step and restores the installation to 1.1.0 by itself, losing nothing: the installed release's copy of the database helpers is loaded for the whole operation, and the 1.1.0 copy lacks a function the 1.1.3 migrations call ([issue #91](https://github.com/phpledger/phpledger/issues/91)). The same limit applies to any installation more than one release behind. From 1.1.1 the in-app path is not yet proven and is documented here once it is.

## From 1.1.1 to 1.1.2

**Known issue, found 20 September 2026 ([issue #90](https://github.com/phpledger/phpledger/issues/90)), fixed in 1.1.3:** the in-app updater cannot complete a real update. After the backup and file replacement succeed, the migrate step fails with a duplicate function declaration between the copied recovery runtime and the application, the updater enters automatic recovery, which restored the previous release completely on the copy used for the proof (files, database and sign-in page back at 1.1.1). The cause has existed since 1.1.0 and was never exercised because no earlier release pair had signed metadata on both sides. **Until 1.1.3 fixes it, do not start an update from `/maintenance.php` on a live installation.** Upgrade by the manual procedure instead: replace the files and run `php www/phpledger/install/migrate.php` once (proven for 1.1.0 and 1.1.1 to 1.1.2). The signed metadata remains valid and will serve the fixed updater.

A files-only upgrade: no migration, no receipt change, and one point-of-sale validation change (cash received must be a whole number of minor units). Replace the files with the manual procedure below, or install it from `/maintenance.php` with `phpledger-1.1.2.update.json`: 1.1.2 is the first release since 1.1.0 with signed metadata, so the pinned publisher key applies.

If your 1.1.1 was installed over 1.1.0 by replacing files and you never ran `install/migrate.php`, migration 034 is still pending (see the correction below). The maintenance page reports it; `/maintenance.php` applies it as part of this update, or run `php www/phpledger/install/migrate.php` once after replacing files.

## From 1.1.0 to 1.1.2

Keep the installed chain through 033 unchanged. This upgrade applies migration 034 (the stock-locations tables and the optional module, which stays off). Install it from `/maintenance.php` with the 1.1.2 signed metadata, which runs the migration inside its backup-and-verify sequence, or replace the files with the manual procedure below and run `php www/phpledger/install/migrate.php` once.

## From 1.1.0 to 1.1.1

**Correction (20 September 2026):** the paragraph below was wrong when 1.1.1 was published. This release **does** add migration `034_inventory_locations` (stock-locations tables and the optional Stock locations module); the chain ends at 034 only after it is applied. An installation that replaced files without running `php www/phpledger/install/migrate.php` has it pending. Because 1.1.1 shipped no signed metadata, `/maintenance.php` could not install it from 1.1.0; 1.1.2 restores that path.

Original text: A files-only upgrade. There is no migration in this release: the schema chain still ends at 034, no receipt changes, and no accounting behaviour changes. Replace the files with the manual procedure below, or use `/maintenance.php` if you pinned the publisher key at 1.1.0.

Everything new is in browser setup, which an existing installation has already completed and which stays closed. Two changes are worth knowing about anyway:
- A copy whose recorded public URL begins with `http://` now shows a warning on every screen and cannot mark its session cookie `Secure`. That is a statement of what was already true, not a new restriction. Connections still require an HTTPS address.
- Browser setup no longer offers a second runtime database account (decision B24). An existing installation keeps whatever account its `config.local.php` names.

## From 1.0.0 to 1.1.0

Keep the installed chain through 031 unchanged. This release adds migration 032 (an optional username for each account) and 033 (the optional installation logo). Existing accounts keep signing in by email. Because 1.0.0 shipped no signed update metadata, use the manual procedure below. 1.1.0 is the first release with official signed metadata: after this manual upgrade, pin the publisher key from the [release signing guide](https://github.com/phpledger/phpledger/blob/master/docs/RELEASE-SIGNING.md#official-publisher-key) as `publisher.pem` in the private installation directory, and later releases can be installed from `/maintenance.php`.

The package layout changed only at its top:
- It now unpacks to `phpledger/`, not `phpledger-<version>/`.
- It adds a package-root `index.php` and `.htaccess` for uploads inside a website. An installation whose document root is `www/phpledger/public` does not use them.
- It no longer carries these files: CLA.md, composer files, INSTALL/UPGRADE/RELEASE-NOTES/README.md, `docs/`, `resources/tax/`, `resources/integrations/`, `resources/coa/industry-profiles-0.5.0.json`, `tools/validate-tax-catalog.php`, `tools/export-openapi.php` and `tools/dispatch-outbound-events.php`. They remain in the source repository.

Unpack the new package beside the installation as usual. Leaving older copies of those files in a retired directory is harmless.

MariaDB support is new in this release. An installation stays on its existing database server; moving between MySQL and MariaDB is a separate backup/restore project and is not an upgrade step.

## Manual procedure: before changing an installation

Arrange a maintenance window at the web server or reverse proxy, blocking customer access and writes while keeping operator access. A published 0.6.0-preview installation has no customer maintenance-mode switch; the `/maintenance.php` updater described above supplies its own application barrier for installations already on 1.0.0. Stop any locally added workers or integrations, and prevent concurrent schema changes. Record the installed package version, manifest, PHP/MySQL versions and migration state.

Keep a private, recoverable copy of:

- The complete installed application/package, including all migrations and dependency files.
- Private configuration and hosting/PHP settings, held separately from public artifacts.
- A consistent database backup containing table definitions, data, views and triggers, including `pl_schema_migrations`.

Backups contain private business and authentication data. Store them outside web roots with restricted access and your chosen encryption/retention policy. A download of the source code is not a database backup.

## Back up and rehearse restoration

Use MySQL 8.4 tools or an equivalent host-managed backup whose restoration you have verified. For a small InnoDB installation, an operator can use this Bash example after configuring a private MySQL login path called `phpledger-backup` and choosing a new backup filename:

```sh
mysqldump --login-path=phpledger-backup --single-transaction --triggers --no-tablespaces --set-gtid-purged=OFF --result-file=/private/backups/phpledger-before-upgrade.sql phpledger
```

Replace the database and private output path. Do not reuse an existing backup filename, put a database password in arguments, or accept a failed/empty dump. Preserve the exit status, size and checksum. This example is for the application database, not server-wide users or replication recovery; have the database administrator adapt it for those needs. MySQL documents the [backup options and required privileges](https://dev.mysql.com/doc/refman/8.4/en/mysqldump.html).

Before relying on the backup, create a separate empty restoration database and use a restricted staging application with matching code. Review view/trigger definers and required restore privileges with the database administrator; preserve the guards rather than dropping them to make a restore appear successful. An example Bash import using a separately configured restoration login is:

```sh
mysql --login-path=phpledger-restore phpledger_restore < /private/backups/phpledger-before-upgrade.sql
```

Do not aim that command at the live database. Confirm definitions/data, all included migration receipts and their checksums, every installed accounting guard trigger, company scope, original/current source links and balanced totals. Check both effective-source views: preserve their security/definer metadata, verify their dependencies point only to the restoration database, and compare their returned source rows. A retained definer may need temporary read access to the isolated restoration database; scope and revoke that rehearsal grant explicitly. Run preflight and sign in on the isolated restoration. Compare a known transaction and its reports with the recorded pre-backup values. A completed SQL import alone is insufficient proof of recovery.

## Apply a reviewed update

For an installation already through `016_correction_identity`, the starter adds these nine migrations:

| Migration | Operator implications |
|---|---|
| `017_ar_ap_documents` | Adds invoices, bills and linked credits with immutable source revisions and action history. No existing customer or supplier balances are automatically adopted. |
| `018_inventory` | Adds shared products, stock movements, command receipts and opening review records. Installation does not enable Inventory for a company or guess opening stock quantities. |
| `019_purchasing` | Adds purchase orders, goods receipts, supplier-bill matches and returns. Purchasing activation requires Inventory; supplier debt remains in AP. |
| `020_opening_conversion` | Adds explicit allocation of existing opening journal amounts to reviewed unpaid documents, plus immutable conversion receipts. Conversion is a later owner action, not an automatic migration side effect. |
| `021_module_visibility` | Adds audited navigation preferences. Hiding AR/AP does not disable accounting services or change balances. |
| `022_tax_engine` | Adds manually configured tax codes/rate history and frozen document tax snapshots. No country rate catalog is activated. |
| `023_inventory_product_audit` | Extends the existing shared audit classification for product changes. |
| `024_opening_allocation_guard` | Requires exact non-null amount allocations whenever opening evidence shares an existing journal line. |
| `025_tax_price_mode` | Adds audited default price-entry settings and each document's frozen exclusive/inclusive mode. |

Earlier installations also require these retained foundation migrations, in their original order:

| Migration | Operator implications |
|---|---|
| `013_currency_foundation` | Adds company/book/account currency properties, versioned manual rates and journal-line snapshots. Existing domestic line metadata is backfilled while original amounts, source fields and hashes remain protected. Temporary insert guards and a restrictive update trigger cover the backfill; permanent immutability is restored before completion. |
| `014_parties_outbox` | Adds company-scoped party/contact storage, reserved vetting/history data and the outbound queue. Does not activate vetting or install external delivery. |
| `015_open_items` | Adds the authoritative GL-linked internal open-item ledger and command receipts. Does not adopt existing aggregate balances or opening registers. |
| `016_correction_identity` | Adds immutable source identity/revisions and two effective-source views used by existing reads. Views run with SQL SECURITY DEFINER under the account that executes this migration. |

Stop web traffic, schedulers and other writers throughout migration and verification. The migration advisory lock coordinates installers; it does not provide an application maintenance mode. Migration 013 exchanges guard triggers using nontransactional DDL. Keep maintenance active if any statement fails, and restore the matched backup before reopening unless a qualified operator has reviewed the exact interrupted state and recovery sequence. Do not remove restrictive guards to force writes through.

Use a stable dedicated migration account for migration 016. Retain that account and its required access to underlying tables; grant normal application users access to the effective views. Dropping a temporary migration account afterward can leave the views unusable. Inspect `SHOW CREATE VIEW` for `pl_effective_documents` and `pl_effective_general_drafts`, and verify reads as the actual restricted runtime user. For a separately managed hosted demo, validate that the stable reset account recreates the views successfully during its next reset; the customer package installs no demo scheduler.

Install the complete new vendor payload before running the new code. Preserve existing signing/encryption keys, configured public URL and private settings; initialize OAuth keys only for an installation that has never enabled them. Do not copy keys into the package. Earlier missing migrations, including `011_read_connections` and `012_demo_history_periods`, still run in their original order and require the same maintenance boundary. Older preflight binaries reject unknown receipts: rollback requires restoring matched database/source/configuration, not deleting new receipts or merely reverting application files.

Only continue when the new release explicitly supports your starting version/schema and the restoration rehearsal succeeds. Unpack the new package beside the existing directory. Verify its manifest/checksum, retain the previous version, and transfer private configuration deliberately. Do not overlay unknown old files into the new package.

Under maintenance, run from the new package root:

```sh
php www/phpledger/install/preflight.php
php www/phpledger/install/migrate.php
php www/phpledger/install/preflight.php
```

Stop on any nonzero exit status. Preflight must distinguish a recognized pending migration from an unknown/interrupted/altered schema; only a recognized upgrade path may proceed. An unknown migration receipt or checksum mismatch stops the upgrade. Switch the web root to the new `www/phpledger/public` after successful migration. Recheck sign-in, company selection, existing totals and account statement balances. In an isolated sample company, check account creation/rename/status and a general-journal draft, review, post and dated reversal before reopening access. Do not recreate the initial user during an upgrade.

The supplied migrations preserve existing account IDs, posted journals and source records; they do not replace the chart, calculate opening balances or clear existing-business readiness restrictions. Compare saved totals, verify the 32 supplied migration identities through `031_posting_source_lookup`, and check that the installed tables/views/guards match the reviewed package. Compare each receipt checksum to the deployed migration file, including its exact bytes; do not change line endings after packaging. Confirm every historical line has its domestic currency snapshot, and the temporary currency-upgrade guards are absent after successful completion. Both `006_core_accounts_journals` and `006_opening_cutover` are distinct retained identities. Never renumber them. The bundled country tax research stays disabled and unreviewed; manual core tax configuration is separate and no country adapter is activated. Existing read integrations retain their scope; no new financial write transport is enabled.

Migration `010_module_lifecycle` retains per-company state and immutable owner decisions. Existing optional-module decisions remain subject to their supplied manifests; newly introduced Inventory and Purchasing start disabled. After compatible installation, the owner can review and enable Inventory, then Purchasing, in **Modules**. Required AR/AP stays available; its navigation can be hidden separately. Existing receipts, source documents and reports remain readable while optional operations are disabled. A changed optional manifest requires a reviewed upgrade decision; a missing migration/checksum mismatch requires package repair. The screen never installs SQL or drops historical data. New explicit sample companies enable their sample POS showcase during provisioning.

Before reopening access, use an isolated sample company to check invoice/bill review and posting, a partial payment, a linked credit, ageing and control reconciliation. If Purchasing/Inventory will be activated, check an order, partial goods receipt, later matched supplier bill, stock valuation and received-but-unbilled reconciliation. Test inclusive/exclusive tax with explicitly reviewed manual codes and verify that credits retain their original snapshots. New receipt/payment journal lines use the existing bank CSV reconciliation; do not create a duplicate bank ledger.

Existing aggregate opening debts and inventory values need separate reviewed conversions after upgrade. Map parties/products explicitly and reconcile to the original opening journal basis; do not recreate those balances as newly posted documents. Conversion may reject a control or product basis with ambiguous or later activity. Preserve that evidence for review instead of bypassing the restriction. No opening conversion or optional module activation is performed by the migration command itself.

## Interrupted migration or rollback

MySQL DDL is not automatically rolled back as one application transaction. An `applying` receipt indicates an interrupted operation requiring review. Preserve the database and error evidence; do not edit migration files, delete receipts or manually mark a failed migration as applied. Do not repeatedly rerun the installer to conceal the failure.

Keep maintenance active. Have the operator restore the last verified database backup into an isolated replacement database, restore its matching code/configuration, and rerun the restoration checks before switching back. Restore code and database as a compatible pair; reverting application files alone does not undo a schema change. Any writes made after the backup require a separate reconciliation plan.

A published 0.6.0-preview installation has no automatic rollback or backup scheduler; use the manual procedure above. This release's `/maintenance.php` updater described above adds automatic pre-update matched backup and recovery, subject to its disclosed assurance limits; it does not add a recurring backup scheduler or historical application migration. Repository development/restore test tools are not shipped as customer commands.
