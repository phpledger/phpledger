# Foundation validation

## 1.4.1 local candidate — 24 September 2026

The patch is implemented locally from baseline `f325d02d` on `codex/release-1.4.1`. It is not published or deployed. The [exact-artifact gate record](repository/RELEASE-1.4.1-GATES.md) and [issue audit](repository/RELEASE-1.4.1-ISSUES.md) distinguish validated behavior from publication and remaining assurance.

| Check | Current evidence or remaining gate |
|---|---|
| Employee DOB and invoice/bill account guards | Targeted MySQL employee tests 11/11 and AR/AP tests 75/75 passed. Browser failure recovery retains invalid display input without silently submitting an old date. Full combined reruns include the additional scoped recovery regression. |
| Shared dates and required fields | Date component 58/58 passed. Application matrix covered employee, invoice, payroll, recurring, receipt and expense forms at 1440/768/320/360/390 pixels. Initial findings were corrected; focused recovery 22/22 and markers/navigation 61/61 passed. |
| Sample completion, navigation and phone layouts | Actual journal/draft counts replace the empty-books claim. Separate workspaces and selected states passed scoped browser checks. Full-suite and demo results are recorded in the gate receipt when complete. |
| Combined validation | Changed-file syntax checks and PHPStan passed; full MySQL/MariaDB application suites are running. No passing combined result is claimed until their completion. |
| Distribution and upgrade | Exact signed ZIP fresh installs and populated 1.4.0 upgrades passed on MySQL and MariaDB, including repeat packaged upgrade execution, unchanged history/balances and all 61 migration receipts. Byte-identical reproduction and 13 package-builder tests passed. No new migration or schema change. |
| Website and resources | Both resource generators passed check mode. Local website build/content check: 102 pages, zero errors/warnings. Release/download pages passed three browser widths. Wiki draft prepared and About reviewed unchanged. |
| Publication | Separate authorization required. Signed archive, checksum and website metadata prepared locally; no patch upload, website/demo cutover or issue closure claimed. No media kit for this patch. |

Historical validation records below retain their original scope and do not establish acceptance of this candidate. Independent accounting/security review, native translation review, provider acceptance and real-business pilots remain separate assurance work.

## 1.1.3 publication — 20 September 2026

Stable patch published 2026-09-20T20:40:01Z from tag `v1.1.3` (`521dbc3`, merge of `fix/issue-90-updater-migrate` into `master`). Fixes issue #90: `install_functions.php` and `update_database_functions.php` now keep whichever copy of the platform helpers is already loaded, with a documented one-release compatibility rule at the top of `database_platform_functions.php`; a new `tests/update-migrate-test.php` runs the real migrate and verify phases under the copied recovery runtime and is registered in CI. Later commits `319cf5f`, `00ce9ce` and `63bcb39` corrected upgrade-path wording across README, wiki and website. Machine-readable receipt: [STABLE-1.1.3-PUBLICATION.json](repository/STABLE-1.1.3-PUBLICATION.json); narrative: [PUBLICATION-2026-09-20-1.1.3.md](repository/PUBLICATION-2026-09-20-1.1.3.md).

| Check | Result |
|---|---|
| `composer check` on MySQL 8.4 | **320 tests, 0 failures**; lint 242 files; PHPStan clean |
| Update and installer tests | update-recovery, update-database, update-fullschema, update-http, browser_installer (54 checks) and the new update-migrate test all pass |
| MariaDB 10.4.34 (same day) | the whole battery also passed at 319 tests, run before the update-migrate test was added |
| Reproducible build | two local builds from the tag byte-identical (2,952,255 bytes); CI Release build succeeded on `521dbc3` and created the draft; the tested local archive was uploaded over the draft's asset before publication |
| Signed metadata | `phpledger-1.1.3.update.json` (312,687 bytes, SHA-256 `c285f3aa…2002`) signed by the owner with the official key via `.cache/release-1.1.3/sign-1.1.3.py`; verified as an upgrade from 1.1.0, 1.1.1 and 1.1.2, rejected for 1.1.3; 1,503 files in the signed inventory |
| Upgrade proof, 1.1.2 → 1.1.3 through the in-app updater | published archive and published signed metadata, disposable MySQL 8.4.9 schema: `backup > apply > migrate > verify > runtime > complete`; `public/maintenance.php` byte-identical before and after |
| Upgrade proof, 1.1.1 → 1.1.3 through the in-app updater | same sequence, applying migration 034 on the way; `public/maintenance.php` byte-identical before and after |
| Upgrade proof, 1.1.0 → 1.1.3 through the in-app updater | `backup > apply > migrate > recovering > restored` — restored to 1.1.0 with 34 receipts and nothing lost ([issue #91](https://github.com/phpledger/phpledger/issues/91)); `public/maintenance.php` byte-identical before and after; cause is `pl_update_database_connect()` keeping the installed release's `database_platform_functions.php` for the whole operation, and the 1.1.0 copy predates `pl_database_require_trigger_support()` (added in 1.1.1 for issue #83) which the 1.1.3 `pl_migrate()` calls; the #90 fix holds across one release step, not three |
| Anonymous downloads | all three assets re-downloaded from GitHub after publication and hash-verified |
| Website | `website-1-1-3-20260920-205507` published 2026-09-20T20:56:37Z from `63bcb39` through `.cache/publish-website-1.1.3.py`; document root only; feed offers stable 1.1.3 with `update_json`; `/download/`, `/news/1-1-3/` and `/roadmap/` carry the corrected upgrade wording; demo untouched. Two earlier attempts: `website-1-1-3-20260920-204637` extracted but never switched (publisher's "release directory already exists" guard); `website-1-1-3-20260920-204912` switched and then rolled itself back on a wrapper page-check bug, leaving 1.1.2 live until the corrected run above |
| Wiki, About, issues | wiki pushed, latest clone commit `e822ef4`; About names 1.1.3; issue #90 closed with evidence; issue #91 open on milestone 1.2; the v1.1.2 release page carries a "Fixed in 1.1.3" note |

These are technical checks. Independent accounting and security review, supervised pilots with a real month-end close (pilot #1 starts on this release on 1 October 2026), unfamiliar-operator observation and restricted shared-host recovery remain open. Issue #91 (in-app update from more than one release behind) remains open. The demo was **not refreshed** and still runs `core-1.1.0-af0f4895f85a`.

## 1.1.2 publication — 20 September 2026

Patch release published 2026-09-20 18:43:56 UTC from tag `v1.1.2` (`3df5755`) on the owner's instruction "cut 1.1.2 now" (decisions B27–B30). It corrects the 1.1.1 record (1.1.1 shipped migration 034 and the optional Stock locations module while its notes said "no migration"; 1.1.1 also shipped without signed update metadata), fixes issue #88 (a point-of-sale cash tender must be a whole number of minor units) and restores publisher-signed update metadata. Machine-readable receipt: [STABLE-1.1.2-PUBLICATION.json](repository/STABLE-1.1.2-PUBLICATION.json); narrative: [PUBLICATION-2026-09-20-1.1.2.md](repository/PUBLICATION-2026-09-20-1.1.2.md). The 1.1.1 receipt, missing at its publication, was backfilled the same night with errata: [PUBLICATION-2026-09-20-1.1.1.md](repository/PUBLICATION-2026-09-20-1.1.1.md).

| Check | Result |
|---|---|
| `composer check` on MySQL 8.4 (lint, PHPStan level 5, samples, `tests/run.php`) | **319 tests, 0 failures** (316 at 1.1.1; three added by the #88 fix); PHP lint 241 files after the version bump; update-channel checks 38 |
| CI on `3df5755` | Foundation checks: two runs, both success (PHP 8.2/8.3/8.4 on MySQL 8.4; MariaDB 10.6/10.11/11.4 with installer fixtures and update/recovery tests); Release build: success, two byte-identical CI builds |
| Reproducible build | two local builds of `v1.1.2` byte-identical (2,951,475 bytes, SHA-256 `24d499d4…eb882`); CI archive differs only in ZIP compression bytes, all 1,503 members hash-identical; the tested local archive is the published one |
| Signed metadata | `phpledger-1.1.2.update.json` (312,687 bytes, SHA-256 `5868d812…b49d`) signed by the owner with the official key; verified with `pl_update_verify_metadata()` as an upgrade from 1.1.0 and 1.1.1, rejected for 1.1.2; the copy served at `https://phpledger.com/releases/1.1.2.update.json` verified the same way |
| Upgrade proof, 1.1.0 → 1.1.2 by file replacement plus `install/migrate.php` | disposable MySQL 8.4.9 schema: 1.1.0 migrated (34 receipts) with one company and book; 1.1.2 files applied exactly `034_inventory_locations` (35 receipts, checksum equal to the package file); one `DEFAULT` warehouse per book; second run a no-op; preflight clean |
| Upgrade proof, 1.1.1 → 1.1.2 through the updater with the real signed metadata | **failed** at the migrate phase with a duplicate declaration of `pl_database_platform()` between the copied recovery runtime and the application ([issue #90](https://github.com/phpledger/phpledger/issues/90)); backup and apply succeeded, the envelope was accepted, automatic recovery then restored 1.1.1 completely (1,503 files, database, sign-in page; `maintenance.php` unchanged). Present since 1.1.0; the update tests bypass the real migrate function. Every 1.1.2 surface now says: use the manual procedure until 1.1.3. |
| Anonymous downloads | all three assets re-downloaded from GitHub after publication and hash-verified |
| Website | website-1-1-2-20260920-185427 published through the reviewed lane; document root only; feed offers stable 1.1.2 with the signed metadata address; download, news and roadmap pages verified live; demo untouched at `core-1.1.0-af0f4895f85a` |
| Wiki, About, issues | wiki `7a4fd75`; About names 1.1.2; #74, #87, #88 closed; milestone 1.1.2 created and 1.1.1 closed; #71–#73 on milestone 1.2 |

These are technical checks. Independent accounting and security review, supervised pilots with a real month-end close (pilot #1 starts on this release on 1 October 2026), unfamiliar-operator observation and restricted shared-host recovery remain open. The MariaDB 10.4 floor still has no CI evidence, `tools/verify-upgrade.php` still has no 1.x baseline (the proofs above were scripted disposable runs), and the notes inside the 1.1.2 package say "21 September" although publication was 20 September (23:43 PKT).

## WordPress-style installation, MariaDB, username and logo — 19 September 2026 (released as 1.1.0)

The work is on branch `wordpress-style-install` (worktree `.claude/worktrees/install-package`, from `master` 75f85f7). The implementation and rules are in [Installer](INSTALLER.md#wordpress-style-installation-next-release-implemented-locally-19-september-2026-not-yet-published). Nothing was released, pushed or deployed.

| Check | Result and scope |
|---|---|
| MySQL 8.4 (PHP 8.3.33 test image) | `tests/run.php` **294 tests, 0 failures**; lint **235 files, 0 failures**; PHPStan no errors; release signing 20 checks; browser installer with an operator key **81 checks**; new keyless installer **103 checks**; update recovery, database recovery, full-schema recovery (81 tables, 2 views, 106 triggers) and maintenance HTTP tests passed. |
| MariaDB 10.4, 10.6, 10.11 and 11.4 | The same battery except lint/PHPStan, which are database-independent, passed on each version: 294 tests, 0 failures; installer 81 and keyless installer 103 checks; all update and recovery tests. Before the dialect hook, MariaDB rejected `FOR SHARE` (132 queries) and `utf8mb4_0900_ai_ci` (before 11.4.5); both are now translated at execution time with unchanged migration files. |
| Package builder | `python tests/package-builder-test.py`: **13 tests passed**. They cover the `phpledger/` root, the adapter files, deny files, vendor pruning, the minimal allowlist, runtime resource coverage and the pinned `maintenance.php` bytes. |
| Local candidate | `phpledger-1.1.0-rc.1.zip` (not published), built from commit 01ebc1c: **2,922,930 bytes, 1,498 files**. The 1.0.0 archive had 1,583. The root holds `index.php`, `.htaccess`, `README.txt`, `LICENSE`, `PACKAGE-MANIFEST.json` and five folders. |
| Apache web adapter | `python tests/package-web-adapter-test.py --zip …` in the PHP 8.3 Apache image: **30 of 30 checks**. See the detail below the table. |
| Owner's XAMPP (Apache 2.4.58, PHP 8.2.12 mod_php, Windows) | The candidate was unzipped to `htdocs/phpledger-test`. See the detail below the table. |
| Not verified | The GitHub Actions workflow changes (YAML not parsed locally); a real shared host (cPanel/LiteSpeed/Nginx front proxy); hosting-panel file upload; unfamiliar-user installation; a fresh CI run of the MariaDB matrix; website copy. |

**Apache web adapter checks.** With `.htaccess` allowed:
- a fresh upload at `/sub/` redirects to `/sub/install`
- the installer opens without a key over same-computer HTTP, and the private-folder probe passes
- assets are served, and a missing asset returns 404
- direct `public/index.php` redirects back to the folder
- 16 private paths are refused: vendor, configuration, storage, install, templates, tools, resources, licences, the manifest, README and `.htaccess`

With `.htaccess` ignored, the package-root `index.php` refuses to start. The document-root-at-`public` layout also works.

**Owner's XAMPP checks.**
- **Why a container database:** XAMPP's own MariaDB has been crashing at startup since 1 August 2025, from InnoDB page corruption in `mysql/innodb_index_stats`. It crashed again at 15:38 and 15:42 PKT on 19 September during the owner's 1.0.0 attempt, so the test used a disposable MariaDB 10.4.34 container on 127.0.0.1:3310. Its default database character set was `latin1`.
- **Installation:**
  - `http://localhost/phpledger-test/` opened the installer without a key.
  - All 34 migrations ran through the browser flow in 4.0 s.
  - Configuration and OAuth keys were written through the bundled `openssl.cnf` fallback.
  - The owner was created with username, email and logo, and signed in with the username.
- **After installation:**
  - `/logo` and the sign-in page show the logo.
  - Setup closed.
  - XAMPP refused 13 private paths, including the real `config.local.php`, `operator.key` and `oauth/private.key`.
- **Accounting:** a new business was created in the in-app browser, and a sample receipt of 1,250.00 was posted (trial balance 1,250.00 = 1,250.00). A linked reversal returned it to 0.00 = 0.00.
- **Layout:** no horizontal overflow at 375, 768 or 1280 px on the account step or Home.
- **How it was driven:** passwords were sent by a local script rather than typed into the browser.

## 1.0.0 publication — 18 September 2026

The owner published **1.0.0** on 18 September 2026 from `codex/ui-redesign-0.6` as PHP Ledger's first stable release, consolidating the 0.6.1 workflow-recovery closure, the 0.7 browser installer and the 0.8 signed update/automatic-backup/recovery work recorded in the checkpoint below into one supported production scope. This section records what the publication does and does not rest on. Evidence that exists: the automated test suites in the checkpoint below, the fault-injection update/recovery tests, the exact-artifact install/upgrade/recovery checks run against the built package, and developer-operated browser checks. Evidence that does **not** exist: independent accounting review, independent security review, supervised pilots with a real month-end close, unfamiliar-operator installation observation, and restricted shared-host recovery certification. The owner published 1.0.0 as the supported production scope with these limits disclosed, carrying the outstanding items forward as post-release commitments rather than presenting them as satisfied.

Published as a GitHub release (tag `v1.0.0` at `2f24495`, package source `1415ceb`) with `phpledger-1.0.0.zip` (3,137,991 bytes, SHA-256 `1c44e685b126352220c54d2ce13d575aaf2dfaf873d6b372a3a2b17189dcc63d`) and its media kit, both verified by anonymous download; Wiki and About updated. Release-tree source suite: 286 tests, 0 failures, lint/PHPStan clean; CI green on PHP 8.2/8.3/8.4 for the tagged commit; exact-archive fresh install, browser installer (75 checks) and clean upgrade from the published 0.6.0-preview archive passed; backup restoration and the update/recovery fault suites passed on the same commit. An internal automated security review fixed one medium and one low defect. Not done: independent accounting/security review, pilots, RC period, unfamiliar-operator observation, shared-host recovery certification, publisher key/signed metadata, and the phpledger.com website/demo deployment. See the [publication receipt](repository/PUBLICATION-2026-09-18-1.0.0.md), [package proof](repository/STABLE-1.0.0-PACKAGE-PROOF.json) and [internal security review](repository/SECURITY-REVIEW-INTERNAL-2026-09-18.md).

## Stable-path local implementation — 18 September 2026

This checkpoint implements the approved path toward stable 1.0 on `codex/ui-redesign-0.6`. The published release and main working-tree application version remain **0.6.0-preview**. No new release has been published. The [current roadmap](ROADMAP.md#current-delivery-contract-first-stable-10) is the authoritative release checklist; the [reviewer/pilot brief](strategy/STABLE-REVIEWER-PILOT-BRIEF.md) contains the requested volunteer advert and review responsibilities. The owner has offered their accountant and lawyer; technical security review, review dates and pilots remain unconfirmed.

### Implemented changes

| Area | Files and resulting behavior |
|---|---|
| Workflow recovery | `includes/functions/web_functions.php`, the three `starter_*web_functions.php` helpers, targeted `public/index.php` controllers, editor/list/source/settlement templates and shared UI components: preserve submitted values and validated report/account/ageing context through save/post/reverse/source journeys. Linked errors, focusable financial tables and unique dynamic-row IDs improve keyboard review; partial commercial rows are not silently discarded. |
| Browser setup | New `install_functions.php`, `installation_state_functions.php`, `install_web_functions.php`, `install_oauth_functions.php`, install view and JS; shared CLI preflight/migration and OAuth setup helpers; early `/install` entry: host ownership proof, private state/configuration, guarded existing migrations, explicit public URL, private keys, first account and existing onboarding. Completed installation cannot be reclaimed by deleting a flag. |
| Update/recovery | New `update_functions.php`, `update_database_functions.php`, `update_web_functions.php`, `update_probe_functions.php`, `update_download_functions.php`, independent `public/maintenance.php`, bootstrap barrier and `tools/resume-update.php`: pinned publisher signatures, operator authority, explicit channels, complete matched backups, durable recovery, original-record verification and fresh replacement-runtime acceptance. |
| Release tooling | `tools/build-package.py`, `tools/package-files.json`, `tools/sign-update.php`, package/signature regression tests: stable/preview/RC identities, explicit channels, allowlisted source and authenticated exact archive/member inventory. No production signing key was generated. |
| Reproducible checks | New installer, signing, update, database, full-schema and HTTP tests; existing editor/installer tests extended; Docker adds ZIP; the existing PHP 8.2/8.3/8.4 CI workflow includes the new suites. Remote CI has not run for these unpushed changes. |
| Documentation | README, Architecture, Roadmap, Module Roadmap, Installer, Development, Hosting compatibility, this Validation receipt, reviewer/pilot brief, and packaged INSTALL/README/UPGRADE guidance. Older dated receipts retain their historical scope. |

### Executed evidence

| Check | Result and scope |
|---|---|
| Main PHP 8.3.33 suite | `docker compose --profile test run --rm test`: **286 tests, 0 failures; 228 PHP files linted, 0 failures; PHPStan no errors; sample validator passed and rejected all eight invalid fixtures**. Log: `.cache/stable-final-suite.log`. The earlier mixed-revision optional-description failure was resolved by rerunning against the frozen helper/assertions without weakening the test. Later updater hardening has its own focused lint/static/fault reruns. |
| Package tests | `python tests/package-builder-test.py`: **9 tests passed**, including true stable versions, RCs, channel mismatch and runtime allowlisting. |
| Publisher signatures | `tests/release-signing-test.php`: **20 checks passed**, including signature/member tampering, unsafe/private entries, channel identity, output overwrite and fixed official-download host restrictions. Test keys are sample. |
| Browser installer | `tests/browser_installer_test.php`: **75 checks passed** against source and an unpacked local candidate ZIP. Covers ownership/CSRF, dedicated empty schema, migration receipts/retries, private config/download, owner creation, completion lock, OAuth provisioning/discovery and wrong-Host rejection. CLI installer regressions: **6 passed**. |
| Installer browser | Actual sample fresh-install journey through host/database review, all 32 existing migrations, private configuration/OAuth, first account and onboarding; desktop 1440px, tablet 768px and phone 390px checks. Completed setup is locked. Screenshots are in `output/playwright/stable-path/installer-*.png`. This was developer-operated, not unfamiliar-user acceptance. |
| Workflow browser | Receipt and journal error→save→post→source→reverse→report journeys with JS on/off; invoice and purchase-order error/context journeys; ageing→document→journal→settlement/correction→ageing with JS on/off. Receipt/invoice widths 1366/1024/390; journal 1024/390. Four final workflow regressions pass. `output/playwright/workflow061-validation.json` records scope and screenshots. |
| Current route smoke | **41 registered HTML GET paths × 4 widths = 164 authenticated captures** at 1366/1024/768/390. **32 direct ordinary screens / 128 captures**; five routes showed expected missing-parameter/context 403s, two redirected, and two fell back to their default screen. Zero page overflow or JS exceptions; console errors were only the 20 expected 403 resource responses. `output/playwright/workflow061-route-smoke-auth.json` distinguishes these outcomes. An earlier stale-session run was explicitly rejected for route acceptance. No financial writes occurred during this sweep; parameter-specific and other state acceptance remains pending. |
| Update failures | **22 fault-test groups passed**: signature/channel/downgrade/expiry, unsafe paths, case collisions, tampered/symlink archives, operator authority, competing updates, active-request drain, failed migration, interrupted mutation, file/key rollback and recovery resumption. Added 120 original/120 replacement-file fixture completed in **3 backup, 4 apply and 6 restore stages**, preserving exact `0640`/`0750`/`0600` modes and replaying a lost copy checkpoint. Dependency-loss interruption uses controlled injected failures. |
| Database recovery | **601 rows** including binary/generated/default data; preserved views, triggers, receipts and balanced totals; original-value mutation rejected; lost-checkpoint replay passed. Snapshot/restore progressed in bounded stages. |
| Complete application schema | **80 tables, 2 views, 106 triggers**, original immutable journal/records and all migration receipts restored under a schema-scoped identity. **82 snapshot, 80 original-record verification and 439 restoration stages**. Random disposable schema/grant cleaned afterward. |
| HTTP recovery | **16 real local HTTP checks passed**: operator/CSRF authority, signed upload, actual application 503 barrier, saved recovery with broken application bootstrap, parse-error detection, automatic matched code plus database restoration, successful fresh bootstrap/database probe, reopening and key rotation. Includes interrupted terminal marker removal in all three terminal phases and HTTPS normalization without forwarded-header trust. Final updater PHP lint and complete PHPStan passed. |

### Exact local package proof

An isolated clean Git snapshot was created for packaging; the active checkout was not staged or committed, and its version remains 0.6.0-preview. Only the isolated snapshot uses `0.7.0-preview`. Existing unrelated website/Claude files were excluded, the unchanged production dependency lock and 1,275 vendor file hashes were checked, and the normal committed-source package builder was used.

The final unpublished archive is `.cache/stable-package-proof/20260918T115540Z/artifacts/phpledger-0.7.0-preview.zip`: **3,159,380 bytes**, SHA-256 **`7e334c08b0ebb0a60474e4513a8ccb285553053985a9f2f116c1c2d7df15c5df`**, isolated snapshot revision `d373cce498376c0f9fa7c657f376f92a751ff099`. Its **1,582 manifest entries** matched their exact sizes/hashes. The browser installer passed **75 checks against the unpacked ZIP**. The actual publisher signing CLI and packaged consumer passed **10 additional checks**, verifying and staging all **1,583 signed members** including the manifest, and rejecting signature/channel/replay/tamper errors. The sample RSA-3072 private key existed only in the disposable container and was destroyed.

The machine-readable receipt is `.cache/stable-package-proof/20260918T115540Z/receipt.json`. All packaged inputs match the final working files, allowing only Git newline normalization and the isolated version adjustment; later unbundled narrative edits do not change the archive. This proof does **not** include a complete database upgrade using this exact archive, an official publisher identity, a media kit, release publication or production acceptance. Earlier local proof archives are superseded by this final receipt.

### Boundaries and remaining gates

- Complete acceptance of the current **41 registered HTML GET routes** and their supported states, full keyboard/200% zoom/screen-reader coverage and unfamiliar-user observation remain open. The older 38-route audit is historical; its 75 prototype HTML files include supporting pages and are not 75 distinct runtime states. Installer/updater operations are tracked separately. Focused developer browser tests and broad route smoke checks are not full state or observed-usability acceptance.
- New-path execution in this checkpoint is on local PHP 8.3.33/MySQL 8.4. Historical PHP 8.2/8.4 release evidence does not automatically cover this change; the updated matrix still needs execution. No shared-host provider, Apache/PHP-FPM or Nginx/PHP-FPM customer environment is certified here.
- The updater needs schema-scoped DDL/recovery privileges and matching view/trigger definers. A separate low-privilege runtime identity with temporary update credentials is not implemented. Unsupported routines/events/custom objects are rejected. The pinned maintenance loader requires a separate reviewed hosting-panel procedure when its bytes change. Published 0.6 installations need the initial manual staged upgrade before using this new loader.
- Original rows/columns must be preserved by an automatic update. A future migration that intentionally rewrites historical stored values needs a separately reviewed policy extension. The current implementation does not silently permit such changes.
- Managed-file backup/apply/restore now use 100-action checkpoints, and database batches use 250 rows. Initial ZIP verification/staging, an individual migration, the final installed-file hash pass and fresh bootstrap must still fit one host request. Actual exhausted-disk/OS-permission failures, representative host request time limits and outages, sustained workload, every declared staged upgrade path and public download behavior still need qualification. Passing the controlled fault tests does not close this larger gate.
- Official publisher key custody, authenticated fingerprint distribution and rotation/revocation procedure remain to be accepted. No official key or signed release has been published.
- Independent accounting/security review, 2–3 supervised pilots with 30 consecutive days and a real month-end close, the 14-day exact-artifact RC period, media kit/publication alignment and owner release approval remain pending. The December target is conditional.

**Change receipt:** no new/edited migrations and no product accounting-schema change. Existing migration chains and recovery/schema mutations ran only against disposable sample databases. No raw real secrets exposed. External calls were dependency/tool downloads; no customer/provider calls, emails, payments, advertisement posting, Git push, release publication or production change. Existing website changes were preserved. References read: repository instructions, README, architecture, roadmap, design/audit closure, installer, development, hosting and prior publication/validation records. No Google Drive documents were used. Browser checks used the Playwright skill.

## Accounting starter - published 0.4.0-preview, 16 September 2026

The current `codex/accounting-starter` implementation adds required core AR/AP, optional shared Inventory and Purchasing, configurable manual core tax, and the related browser flows. The published release is **0.4.0-preview**, with [archive and live evidence](repository/sprint-06/PREVIEW-0.4.0-PUBLICATION.json). Read the [starter implementation and validation record](repository/sprint-06/ACCOUNTING-STARTER.md) and its [machine-readable receipt](repository/sprint-06/ACCOUNTING-STARTER-VALIDATION.json) for the current nine migrations, test evidence, browser journeys and remaining limits. Earlier sections below are historical checkpoints.

Validation used sample data and separate disposable Compose projects. The final complete suite passed 225 tests on each of PHP 8.2.33 and 8.3.33, with clean PHP lint/static analysis, fresh and populated-upgrade rehearsals, and a tested backup/restore. The browser journey connected invoice/credit/payment and order/receipt/bill/return/stock-sale flows to the existing bank reconciliation; five bank rows reconciled to USD 884.25 with no difference. Exact final counts, canonical migration-byte checks and responsive evidence are in the linked receipt.

Migrations and schema changes: yes, isolated local checks and the authorized hosted-demo upgrade. The package and hosted application are published; the receipt records website publication separately. Live verification used one sample demo visitor; no real customer payments or messages were sent. Technical checks do not represent professional accounting or tax approval. Local references include the approved product/module direction, multi-currency, party/vetting and correction model documents; no Google Drive document was required or read.

## AR/AP prerequisites — local, 16 September 2026

This checkpoint implements the owner-approved schema and posting-service prerequisite task on `codex/ar-ap-foundations`, based on `531541c`. It adds migrations 013–016, exact currency snapshots/manual rates, country-neutral parties and contacts, an internal authoritative open-item ledger, realised FX settlement, stable source corrections, and an inactive outbound queue/dispatcher. It adds no invoice/bill documents or workflows. [Foundation notes](strategy/AR-AP-FOUNDATIONS-NOTES.md) record the selected design decisions; [development guidance](DEVELOPMENT.md#arap-foundations--local-service-and-upgrade-contract) describes the service inputs and maintenance boundary.

### Files and executed checks

| Group | Added or changed files |
|---|---|
| Currency | Migration `013_currency_foundation.php`; `includes/functions/currency_functions.php`; currency integration in `ledger_functions.php`, `core_functions.php`, `read_functions.php`; `tools/currency-rates.php`; `tests/currency_test.php` |
| Party and queue | Migrations `014_parties_outbox.php`, `015_open_items.php`; `party_functions.php`, `outbound_functions.php`; `tools/dispatch-outbound-events.php`; party, outbound and separate-process race tests |
| Settlement and correction | Migration `016_correction_identity.php`; `open_item_functions.php`, `correction_functions.php`; central ledger, source/current-read and bootstrap integration; open-item/correction tests and concurrency worker |
| Verification and documentation | `tools/verify-upgrade.php`, `verify-currency-upgrade.php`, `verify-backup-restore.ps1`, package allowlist, test runner; README, Architecture, Roadmap, Development, this receipt and foundation notes |

| Check | Observed result |
|---|---|
| PHP 8.3.33 complete `composer check` | **179 tests, 0 failures**; **128 PHP files linted, 0 failures**; PHPStan passed; seven sample packs/77 events/42 documents/16 items validated, eight invalid sample fixtures rejected. Includes real separate-connection correction, settlement, deduplication and queue lease races. |
| PHP 8.2.33 complete `composer check` and platform compatibility | **179 tests, 0 failures**, **128 PHP files linted, 0 failures**, PHPStan and sample validation passed. All Composer platform requirements and 17 isolated arithmetic/normalization assertions passed using current locked dependencies copied from the cached test image; the unrelated stale host vendor directory was preserved. |
| Fresh install and populated upgrades | `tools/verify-upgrade.php` passed `fresh`, `foundation`, `core-0.1.2`, `opening-local` and `preview-0.2.1` through the full **17-migration** chain. Preserved original account IDs, posting amounts and header fields; verified domestic snapshots, report reconciliation and replay. The duplicate historical 006 filenames account for the chain count. |
| Interrupted currency upgrade | `tools/verify-currency-upgrade.php` passed: populated old-format journal/hash; restrictive backfill; blocked original-field changes and second-connection writes; interrupted receipt rejection; reviewed recovery; complete domestic metadata; restored immutability guards; original hash and exact legacy replay preserved. |
| Backup and restore | `powershell -NoProfile -ExecutionPolicy Bypass -File tools/verify-backup-restore.ps1`: final fresh-database run passed **56 table definitions/data checksums, 7,870 rows, two restored-schema-only view definitions, 379 effective row digests, 62 guard triggers and 17 migration receipts**. An earlier accumulated-fixture run also passed with 25,379 rows and 1,166 effective rows. Original/current scoped source links and balanced journals passed. Temporary read permission for the retained test view definer was revoked; only each run's randomly created restore database was removed. |
| Existing screen compatibility | **27/27 HTTP 200 route/viewport checks**: nine routes at **1440, 768 and 390px**. Corrected receipt, expense and general-journal details/lists, bank statement and original/reversal/replacement journals retained correct source links. No page overflow, JavaScript page errors or new matrix console errors. Nine representative screenshots inspected. |
| Browser financial example | Receipt 100→250, expense 40→60 and general capital 1,000→900 retained stable source numbers; all nine immutable movements reconciled to **USD 1,090.00 Dr**. Current table searches used corrected descriptions/amounts. Evidence: ignored `output/playwright/browser-qa-summary.json`, matrix/link results and 27 PNGs. |
| Packaging and whitespace | `python tests/package-builder-test.py`: **6 tests passed**; changed runtime files are in the explicit package allowlist. Task diff whitespace checks passed. No release archive was published. |

The final implementation fixes three issues caught in independent review: activation and account-currency checks now use current locking reads even inside older transactions; recognition-reversal retries consult the durable posting receipt before validating new actions. Regression tests cover all three. Queue worker tests allow a bounded retry on an empty `SKIP LOCKED` claim without weakening live-lease or duplicate-delivery assertions.

Final byte verification detected CRLF in the uncommitted Windows copy of migration 013 while Git correctly stored LF. The working file was normalized to its committed bytes under the existing `.gitattributes` rule. All four new migrations then matched Git byte for byte. Only the disposable `db_test` service was recreated; fresh installation, all four upgrade baselines, interrupted recovery, the complete 179-test PHP 8.3 suite and backup/restore passed again against the canonical committed migration bytes. No existing migration receipt was rewritten.

### Scope and remaining limits

- **Migrations: yes. Schema changed: yes, disposable test databases only. Raw secrets exposed: no. External/live calls or sends for this task: no. Live/production changed: no.** The main development database and existing demo were not migrated or reset. The temporary browser container/session was removed. No push, release, cron installation, provider delivery or A75 connector was performed.
- Open-item activation accepts unused, currency-neutral AR/AP control accounts. Existing aggregate opening balances require a later reviewed cutover. Outstanding FC/base amounts are derived directly from immutable linked GL lines, without a second mutable balance.
- Outgoing settlements currently require a functional-currency bank. Foreign-bank outgoing carrying-value allocation and third-currency conversions need their own design. Incoming foreign-bank receipts, actual-rate override, partial/final allocations and dated allocation reversals are covered within the implemented contract.
- Party bank, attachment and tag storage and vetting states/log are schema foundations only. The dispatcher has an empty production handler registry; fake tests prove retry/idempotency behavior, not real connector delivery. No regional tax, revaluation, consolidation, commodity, invoice or bill capability is claimed.
- Browser review observed the existing initial favicon 404 and direct-detail companion list defaulting to Drafts; the selected corrected source still shows Posted. The narrow general-journal table scrolls inside its container. No unrelated UI refactor was made.
- Independent professional accounting/security acceptance, load testing, supported-host acceptance and production migration remain unperformed. Passing technical checks is not accounting sign-off. Concurrent website/content changes were preserved outside these commits.
- References read: repository instructions, README/Architecture/Roadmap, Decision Register B7, Product Direction Clarification (15 September 2026), full Multi-Currency and AR/AP Parties and Vetting documents, ERPNext Review sections 0–2, relevant development/migration/accounting/test code. No Google Drive document was listed as required or read. Browser verification used the Playwright skill.

## Current local core and module checkpoint

The earlier snapshots below retain their original dates and scope. Outstanding work has since been committed and merged, legacy source removed from the current tree, and the bounded core checklist completed with exports and a two-period reconciliation. The next requested sprint implements the bundled module lifecycle. See [core completion](repository/sprint-05/CORE-COMPLETION.md) and [module foundation](repository/sprint-05/MODULE-FOUNDATION.md) for current files, commands, 121 passing tests, HTTP/browser checks, installation/recovery evidence and remaining accounting/security/pilot gates. The source is local; the public download/demo still reflect their separately recorded release.

## Consolidation checkpoint, 15 September 2026

The owner requested completion and local commits of outstanding work, consolidation of all worktrees, then the module/API/MCP roadmap. The initial inventory found the dirty `website-redesign` branch at `8000e31` and a clean `codex/account-statements` worktree at `2972e57`. The latter contains the newer released core work and module roadmap and must be merged before extension implementation. A fresh pre-merge `composer check` passed 94 tests, 74 PHP lint checks, PHPStan and the sample validator. The website build/check passed 11 HTML documents with zero errors/warnings; all six package-builder tests passed.

The 1,063 untracked root application files matched their archived `legacy/` counterparts byte for byte and were moved out of the active root. Playwright runtime artifacts are ignored. The owner subsequently explicitly requested removal of the legacy application from the repository; history remains the research reference. This checkpoint is local only; no remote publication is authorized by cleanup.

## Website redesign and accounting continuation

**15 September 2026 — local `website-redesign` work, based on `8000e31`.** Completed the unfinished multi-page marketing website and the requested opening balances/cutover → period administration → bank reconciliation sequence. The source remains uncommitted; the earlier dirty website work and unrelated historical/root copies were preserved. No push, public deployment, Wiki edit, release publication, provider call, message sending or real payment occurred.

### Current implementation and changed files

| Group | Added/changed files |
|---|---|
| Opening cutover | `www/phpledger/includes/functions/opening_functions.php`, `opening_web_functions.php`; `www/phpledger/templates/views/opening-balances.php`; `www/phpledger/install/migrations/006_opening_cutover.php` |
| Period administration | `www/phpledger/includes/functions/period_functions.php`, `period_web_functions.php`; `www/phpledger/templates/views/periods.php`; migration `007_period_administration.php` |
| Bank reconciliation | `www/phpledger/includes/functions/reconciliation_functions.php`, `reconciliation_web_functions.php`; `www/phpledger/templates/views/bank-reconciliation.php`; migrations `008_bank_reconciliation.php`, `009_bank_draft_cancellation.php` |
| Shared app integration | `www/phpledger/includes/bootstrap.php`; `includes/functions/{ledger,setup,web}_functions.php`; `public/index.php`; `public/assets/app.css`, `app.js`; `templates/layout.php`; views `companies.php`, `help.php`, `journal.php`, `onboarding.php`, `setup-review.php` |
| Tests and packaging | `tests/opening_test.php`, `period_test.php`, `reconciliation_test.php`, `accounting-http-smoke.py`, `concurrency_worker.php`, `installer_test.php`, `package-builder-test.py`, `run.php`; `tools/package-files.json`, `verify-upgrade.php`, `verify-backup-restore.ps1`; operator templates `resources/release/{README,INSTALL,UPGRADE,RELEASE-NOTES}.md` |
| Current documentation | `README.md`, `docs/ARCHITECTURE.md`, `docs/DEVELOPMENT.md`, `docs/ROADMAP.md`, this receipt |
| Website | `www/website/src/**`, `build.mjs`, `check.mjs`, `tools/{prepare-images.py,browser-smoke.cjs,browser-interactions.cjs}`, `README.md`, `design-qa.md`, generated `public/**` pages/assets; `docker/website.conf`; `docs/design/website/{CONTENT,SOURCES}.md` and QA data. Full website inventory/evidence is in [website QA](../www/website/design-qa.md). |

The app uses the existing shared bootstrap, explicit front controller, PHP/MeekroDB services, scoped permissions, CSRF, exact-money helpers and central posting transaction. Opening source/register data posts once; periods serialize with posting; bank reconciliation posts no journals. Preview/confirmation and stale-state checks are server-enforced. The new admin navigation is absent in the public demo and its service mutation guards remain active.

### Executed checks

| Check | Result |
|---|---|
| `docker compose --profile test run --rm test composer check` | **94 tests passed, 0 failures**; PHP lint **74 files, 0 failures**; PHPStan passed; seven sample packs/77 events/42 documents/16 items validated and eight invalid sample fixtures rejected. Includes opening/period/bank rollback, current-read and concurrent-retry cases. |
| `python tests/accounting-http-smoke.py` | **71 HTTP checks passed**: auth/CSRF/scope/viewer denial; opening preview/confirmation and cutover-date rejection; period create/close/reopen and closed-posting rejection; bank import/manual match/unmatch/cancel/corrected reimport/complete and reconciled-date rejection. Final sample company/book 13, cancelled statement 5, completed statement 6; bank difference zero and exactly three journals. |
| Application browser QA | **27 functional assertions + 51 state/viewport checks** at **1440, 768 and 390px**, with no horizontal page overflow, broken images, page JavaScript errors or external requests. Verified retained-input error focus, nonzero AR/AP cutover, journal/source round trip, period create/close/reopen, bank cancellation/reimport/manual matching/completion. [Receipt](../output/playwright/accounting-next/receipt.json) and screenshots are local ignored artifacts. |
| Website build and static QA | **11 HTML documents, 0 errors/warnings**. All navigation destinations exist. Repeated build produced identical output across 101 public files. |
| Website browser QA | Nine content routes at **1440, 768 and 320px**, plus 14 interaction checks. Redirects, custom 404, robots/sitemap/llms/RSS, mobile navigation, keyboard dialogs, reduced motion, no-JavaScript links and email draft validation passed. No external page-load requests or sends. Clipboard success was mocked and fallback tested. |
| JavaScript/Python/package syntax | Applicable website JavaScript and `public/assets/app.js` passed `node --check`; Python image-preparation/HTTP/package scripts compiled. `python tests/package-builder-test.py`: **6 tests passed**, including the explicit runtime service/view/migration allowlist. `git diff --check` passed. |
| Fresh database installation | All **9 migrations** applied from empty storage in a new randomly named `db_test` database; sample user/company creation, central posting, balanced trial balance and migration replay passed. Only that temporary database was removed. |
| Baseline upgrade | `tools/verify-upgrade.php` passed **001 → 009**, preserving six account identities, posted journal/lines, review-required setup, mapping, report totals and UTC/replay behavior; only its temporary test database was removed. |
| Backup/restore | `tools/verify-backup-restore.ps1` passed: **23 table definitions/data checksums, 6,088 rows, 29 guard triggers and all 9 migration receipts**, scoped source links and balanced journals. Only its randomly named restoration database was removed. |
| Local application migration | Preserved a private development backup in ignored `.cache/accounting-backups/`; applied **006–009** successfully to local `phpledger`. Earlier migration checksums and posted records were preserved. |

The first integrated run exposed a test that hardcoded five migration receipts; it now checks the installed chain length. Review also found and fixed old-snapshot cutover/bank decision reads, an inherited website redirect loop, bank CSV instruction overflow at mobile width and absent error-summary focus. The final counts above reflect those fixes, not the initial failures.

### Boundaries and remaining checks

- Migrations: **yes (006–009)**. Schema changed: **yes, local/test only**. Raw secrets exposed: **no**. External/live calls: **no**. Live/production changed: **no**.
- Read references: repository `AGENTS.md`, `README.md`, `ARCHITECTURE`, `ROADMAP`, historical approved `PLAN`, `DESIGN`, relevant `PRODUCT_BRIEF`/accounting context, `DEVELOPMENT`, website content/design/QA and existing release templates. Google Drive documents: **none listed as required or read**. Browser verification used the Playwright skill.
- Opening unpaid documents are a reconciled cutover register, not invoice collection, bill settlement or a current operational AR/AP subledger. Credit-note/advance imports, XLSX, detailed historical journals and country/bank-specific adapters remain outside this implementation.
- Bank matching supports one exact-amount ledger line per bank row. The first baseline requires all earlier cash entries already cleared; unresolved older outstanding items need earlier reconciliation. Completed statements are terminal; corrections use later-dated accounting entries. Draft cancellation retains original data and audit history.
- Period closing controls posting dates; year-end profit-transfer entries, issued statements and jurisdiction compliance are not provided by that control.
- Independent accounting/security review, observed participant usability, full screen-reader/zoom/performance/load testing, supported host acceptance and production deployment remain unperformed. Public website/demo and the 0.1.0-preview download were not changed. No new release ZIP was built from the dirty working tree; the existing builder deliberately requires a clean committed source revision.

The remaining sections below preserve earlier validation checkpoints and publication history.

Evidence recorded on **14 September 2026**. Earlier sections are chronological local checkpoints; the [hosted publication receipt](#hosted-website-and-restricted-demo-publication) records the subsequently authorized website/demo launch. These checks do not establish a complete accounting product, approved reporting framework, or stable installable release.

## Changed-file inventory

This inventory includes new files as well as edits to tracked files; a plain `git diff --stat` does not include newly added, untracked files.

| Group | Added or changed paths |
|---|---|
| Application | `www/phpledger/includes/` contains the new bootstrap, private configuration example, and auth/security/ledger functions; `www/phpledger/install/` contains the account installer, migration runner and `migrations/001_foundation.php`; `www/phpledger/public/index.php` is the restricted health/route entrypoint |
| Environment and dependencies | `Dockerfile`, `compose.yaml`, `docker/apache.conf`, `docker/php.ini`, `.dockerignore`, `.env.example`, `.gitignore`, `.gitattributes`, `composer.json`, `composer.lock`, and `phpstan.neon` |
| Tests and validation tools | `tests/run.php`, `tests/auth_test.php`, `tests/ledger_test.php`, `tests/concurrency_test.php`, `tests/concurrency_worker.php`; `tools/lint.php`, `tools/validate-sample-data.php`, `tools/verify-backup-restore.ps1`; `.github/workflows/foundation.yml` |
| Product, design and research | `README.md`, `AGENTS.md`, the planning/product/architecture/roadmap/discovery/funding/license/legacy/validation Markdown files in `docs/`; `docs/DESIGN_RESEARCH.md`, `docs/design/` concept images and prompts; `docs/coa/` and `docs/sample-data/` research and fixture documentation; `resources/coa/research-index.json` and seven `resources/sample-data/*.json` packs |

Historical root application files and legacy database dumps remain reference material and were not modernized in place.

## Environment and installation

| Check | Observed result |
|---|---|
| Runtime | PHP **8.5.10**, CLI/Apache Docker image; MySQL **8.4.9** on the 8.4 LTS image |
| Dependencies | Composer-managed MeekroDB **3.1.5** and PHPStan **2.2.14** |
| Fresh development installation | `docker compose exec -T web php www/phpledger/install/migrate.php`: **1 applied, 0 skipped** |
| Installed-version replay | Same command again: **0 applied, 1 skipped**, checksum verified |
| Main database after installation | **0 users and 0 journals**; `001_foundation` receipt marked `applied` |
| Account installer | CLI creation succeeded using an ephemeral password and sample address in **phpledger_test only**; no password was printed |
| Test isolation | Integration runner requires both `PL_ENV=test` and `PL_DB_NAME=phpledger_test`; Compose uses the separate `db_test` service |

The migration runner preserves checksum receipts, serializes installers, and refuses changed or interrupted migrations. Git attributes preserve LF migration files across Windows/Linux checkouts so line-ending conversion does not alter their checksums. Only the initial foundation version exists. The replay check is an installed-version no-op check, **not evidence of upgrading from a released earlier product or importing the 2014 database**. Historical root SQL scripts were not executed.

## Dependency and CI checks

- `composer validate --no-interaction`: **exit 0**, manifest valid with two warnings: project license remains undecided, and MeekroDB is intentionally pinned exactly.
- `composer validate --strict --no-interaction`: **exit 1** for those same warnings. No license was invented or silently assigned to remove the warning.
- `composer audit --no-interaction`: **no security vulnerability advisories found** at the time of the check.
- `.github/workflows/foundation.yml` passed `actionlint`. It builds the Docker test service, validates/audits dependencies, runs local checks, and verifies isolated restoration. It has read-only repository permission and no deployment step.
- The checkout action is pinned to the verified v6 commit `d23441a48e516b6c34aea4fa41551a30e30af803`. The workflow has **not run on GitHub** because this work has not been pushed.

## Accounting and concurrency coverage

The integration suite exercises exact decimal handling and maximum stored precision; company setup; balanced posting and report drilldown; rejected malformed, unbalanced, cross-company, inactive-account, wrong-currency and closed-period requests; duplicate-key conflicts; reversals; server-enforced role boundaries; database immutability; and an injected failure on the second line that rolls back both the header and the first line.

Separate PHP/database processes exercise simultaneous duplicate requests, distinct postings, linked reversal retries, older caller snapshots, and independent companies posting concurrently. A separate-connection permission test proves that an older snapshot cannot retain revoked write permission.

The concurrency work exposed and corrected two real issues: stale repeatable-read snapshots could hide a journal committed by the first caller, and cross-company gap-lock deadlocks could leave MeekroDB attempting to roll back missing savepoints. Current reads now resolve the former. Deadlock cleanup resets transaction depth, preserves the original error, and permits up to three retries only when the service owns the complete transaction. Caller-owned transactions are never replayed independently.

Final combined command: `docker compose --profile test run --rm test`, after building the latest test image. **Passed:** PHP lint **16 files, 0 failures**; PHPStan **0 errors**; integration suite **27 tests, 0 failures**.

The same command runs the offline sample validator: **7 sample business packs, 77 events, 42 opening/current documents, and 16 items** validated; **8 intentionally invalid in-memory variants** were rejected. These are authored demonstration candidates, not installed application records or an implemented importer. The validator reads only local JSON resources and does not connect to a database. The test service mounts resources read-only outside the public document root.

After the final sample files were declared stable, their standalone PHP lint, targeted PHPStan analysis, and `composer validate-samples` were rerun on PHP 8.5.10. All passed with the same totals and eight rejected invalid variants.

The main task's final documentation checks passed: **67 local links across 21 Markdown files** (excluding the historical plan's deliberately preserved machine-specific links), trailing-whitespace checks across **46 authored text files**, and `git diff --check`. The preserved plan still matches its supplied source, SHA-256 `012C9D72DB6DD0F6F66D80C319E0FF95C1D82D98EF3A874AEF3EEFB3BD838E3B`.

## HTTP boundary checks

Requests were sent only to `http://127.0.0.1:18200`:

| Request | Result |
|---|---|
| `GET /health` | **200**, `status=ok`, `stage=foundation-proof` |
| `GET /` | **404**, product UI awaits design selection |
| `GET /includes/bootstrap.php` | **404** |
| `GET /install/migrate.php` | **404** |
| `GET /.env` | **404** |
| `GET /vendor/autoload.php` | **404** |
| `GET /README.md` | **404** |
| `POST /health` | **405**, `Allow: GET` |

Apache serves only `www/phpledger/public`. These requests verify route and private-file boundaries; they do not constitute browser, responsive-design, keyboard, or accessibility validation.

## Isolated backup and restoration

Command: `./tools/verify-backup-restore.ps1` from PowerShell.

The script verified that its source service was the disposable **db_test/phpledger_test** environment. It took a consistent logical backup of sample data without saving or printing the dump, created a random restore database only after confirming that name did not exist, restored there, and compared the source with the restored database.

**Passed:** 10 table definitions, every table's extended data checksum and row count, **1,338 rows** in that snapshot, four immutable journal triggers, the applied migration receipt, and balanced journals. The script removed only the database created by that run. The main development database and source test database remained intact. PowerShell parsing also completed with zero errors.

The row count describes the checked snapshot; later test runs add new sample fixtures. This proof does not establish a production backup schedule, retention policy, encryption, off-site recovery, restore-time target, or compatibility across different MySQL versions.

## Remaining gates and change boundaries

- UI selection, actual browser login/onboarding, desktop/tablet/mobile behavior, keyboard/accessibility testing, timed user research, and accountant sign-off remain pending.
- Historical imports, AR/AP workflows, tax/localization, regional or industry account-template activation, multi-book modes, website launch, funding collection, and production deployment remain later work.
- No Google Drive documents were required or read for this validation. Local references included the README, repository instructions, architecture, roadmap, and chart-of-accounts/sample-data research context.
- The user's latest supplied `PLAN (7).md` is preserved as [PLAN.md](PLAN.md); the original **Seed Idea Brief: Multi-Book Regional ERP Ecosystem** is attributed in [the product brief](PRODUCT_BRIEF.md). The approved plan supersedes the seed's initial SaaS/asynchronous-first direction with the country-neutral, self-hosted, synchronous foundation.
- Public QuickBooks, Odoo and Salesforce sources and separately inspected browser visuals are documented in [Design research](DESIGN_RESEARCH.md). Vendor-authored CodeCanyon listings and vendor documentation are recorded in [Sample-data sources](sample-data/SOURCES.md). These references informed original design/scenario work; they are not authenticated product tests, accounting approval, or permission to redistribute proprietary assets.
- Migrations: **yes, local initial migration and replay**. Schema changed: **yes, new local foundation tables**. Raw secrets exposed: **no**. External calls: **yes, public dependency/runtime metadata and downloads, security metadata, and the checkout action reference**. Live/production changed: **no**.

## Sprint 02 execution receipt — opened 14 September 2026

The foundation evidence above is preserved as recorded. Its pending-design wording and `GET /` 404 observation describe that earlier snapshot. The user has since approved direction 6, Review Console, with Inter, and authorized [Sprint 02](SPRINT-02.md). That approval is a design decision, not a browser/accounting test result.

Repository documentation, contributor guidance, local issue/PR templates, a website preview, and the first browser accounting journey are being prepared. Sprint-specific route, migration/schema, lint/test, and browser results are **pending**. No historical foundation test count is reused as evidence that the new work passes.

The repository documentation task includes a read-only GitHub metadata inspection. No GitHub metadata changes, commit, push, release, website publication, production change, or message sending is authorized by this receipt. The new sprint's final change inventory and exact validation results will be appended when verified.

### Repository documentation checks

The repository workstream added `CONTRIBUTING.md`, `docs/SPRINT-02.md`, two YAML issue forms, and a pull request template. It updated README and the roadmap, architecture, product, funding, and design status, then appended this receipt without rewriting the foundation evidence.

- Local checks covered **12 authored files and 68 local document links**, with **0 missing links and 0 trailing-whitespace errors**. `git diff --check` passed; Git emitted existing Windows line-ending notices for root text files.
- Both issue forms passed YAML parsing and structural checks for metadata, supported field types, unique field identifiers, labels, and boolean required flags. This is local template validation, not a hosted GitHub form test.
- The supplied historical plan retained SHA-256 `012C9D72DB6DD0F6F66D80C319E0FF95C1D82D98EF3A874AEF3EEFB3BD838E3B`.
- The initial Python environment lacked a YAML parser. PyYAML **6.0.2** was installed into an external validation-tool cache and the checks then passed; application dependencies and Composer files were not changed by this workstream.
- No application tests or browser flows were claimed for these documentation changes. Final application/website documentation still requires verified implementation results.
- References: local repository instructions, product/architecture/design/brand/roadmap/funding/license/validation documentation, and read-only GitHub metadata. No Google Drive documents were read. External calls: **yes**, GitHub metadata and the isolated validation dependency download. Migrations/schema changed by this workstream: **no**. Raw secrets exposed: **no**. Live/production changed: **no**.

### Later scope and authorization updates

The user subsequently specified multilingual support with English primary, UTC backend event/audit timestamps and terminal-local display, flexible locale/date/currency/number formatting, and future multicurrency with fixed, periodically fetched, and manually overridden rates. These decisions are recorded in architecture, product, roadmap, and sprint documentation. Accounting DATE values and exact stored amounts must not change with presentation preferences. Translations, exchange-rate providers, refresh cadence, cross-rate/rounding policy, and gains/revaluation behavior are not represented as implemented or selected by this record.

The user's later explicit request authorizes **GitHub repository information updates and marketing website publication at the end of the sprint after validation**. This supersedes the earlier local-only publication boundary for those actions, while preserving the historical receipt above. The technical lead owns remote operations and website backup/deployment verification. Publication is authorized; no success or live change is claimed until an actual release receipt is appended. A public accounting demo, an unrequested application domain, external messages, and funding collection are not included.

After these documentation updates, the repository checks were rerun across **12 authored files and 71 local links**: **0 missing links, 0 trailing-whitespace errors**, the historical plan hash still matched, and the scoped `git diff --check` passed. These are documentation checks only; no new language, formatting, UTC-display, multicurrency, or publication behavior is claimed as validated by them.

### Website selection, public demo, and supporting views

The user later selected website composition **1, Field Notes**, and explicitly added a public **`/demo` with hourly reset and no destructive user operations**. That later decision extends the previously recorded marketing-only publication authorization. Current docs now require separate sample demo storage, server-enforced restrictions, safe backend reset, and correct base-path behavior. Deployment, reset, isolation, and live results remain pending; the earlier narrower authorization record is retained above as history.

The repository agent additionally implemented nine PHP views: login, companies, onboarding, setup review, trial balance, account activity, journal, help, and error. Their routes/forms use the shared `pl_url()` helper, POST forms include CSRF and company scope where applicable, and dynamic text/attributes use `pl_e()`. Shared layout, transaction editor/console, assets, routing, and integration belong to the lead.

All nine views passed individual syntax checks on local **PHP 8.5.5**. A sample render smoke check covered **15 states**, including existing/sample setup previews, empty lists, opening-required setup, and viewer restrictions; PHP warnings were promoted to failures and injected script text remained escaped. These checks did not call the database or network. They are not evidence of browser behavior, backend permissions, target-runtime integration, or observed usability. No backend/schema change was made by this view workstream.

### Independent local HTTP acceptance

`tests/http-smoke.py` is a reusable Python-standard-library client restricted to **http://127.0.0.1:18200**. It receives local test-user credentials through private environment variables, stores cookies only in memory, refuses non-local targets/redirects, and creates its own uniquely named sample company. It never deletes/reset databases or changes the existing sample company.

The final command was `python tests/http-smoke.py` with `PL_HTTP_EMAIL` and `PL_HTTP_PASSWORD` supplied privately and cleared afterward. Python syntax validation passed. The final HTTP run passed **26 checks, 0 failures**:

- JSON health versus HTML sign-in, method rejection, missing/invalid CSRF denial, login and logout, and denial of transaction access after logout.
- Fresh-business setup preview/confirmation and an isolated starter chart.
- Invalid draft values retained and escaped, no report effect before posting, and rejection of a mismatched company/book scope without losing the draft.
- Valid posting and repeated confirmation returning the same durable journal with one balanced **USD 125.50** report effect.
- Trial balance → account activity → source transaction/journal links; an invalid reversal retains its date/reason; the valid linked reversal points to the original and reconciles net balances to zero.
- Closed-period posting rejection preserves the saved draft, revision, amount, and unchanged report. The CLI fixture verifies the exact sample company name/IDs, takes the existing book lock, changes only its matching period, and reopens it in `finally`.

The final fixture was `HTTP Acceptance 20260914-164514-0af492`, company/book **3**, original source document **9**. It remains for inspection with its tested period open. An earlier 25-check run also passed; the final run added an explicit durable-journal identity assertion and exact source-ID comparisons. These are local sample database writes, not changes to customer books or production.

No browser automation was used for this check, so it does not establish responsive layout, keyboard behavior, visual quality, usability targets, or a hosted demo. The script does not test the `/demo` deployment/reset boundary. External/public network calls by this check: **no**; local loopback HTTP and a scoped Docker CLI command: **yes**. No migration/schema changes, plaintext credential persistence, or production changes were made by this HTTP workstream.

### Expanded backend and POS checkpoint — 14 September 2026

The current sprint additionally implements posted profit and loss, balance sheet and cash balance; a read-only cash scenario using entered weekly assumptions; a six-product general-shop cash POS; private sample companies per demo visitor with restricted operations and controlled hourly reset; and a once-per-session country/currency hint. The earlier historical receipts above remain unchanged and do not imply these later features existed at those checkpoints. Receivables, payables, inventory/stock reports, historical imports, reviewed country accounting, translations, flexible formatting settings, FX posting, and document scanning are still future work. The user moved the unified **Scan document** action further down the roadmap without a next-sprint commitment.

The product-foundation agent reported the following completed local checks, including a final rerun after POS tests were tightened to require the intended database exception and trigger message:

- **54 tests passed, 0 failures** on the target Docker PHP 8.5/MySQL 8.4 stack. Coverage includes accounting/access/setup/documents, regional hints with a mock provider, P&L/balance-sheet/cash-scenario behavior, and eight POS cases. POS tests cover exact money, immutable price snapshots, normalized duplicate/conflict requests, malformed/tampered cart rejection, access/readiness boundaries, closed-period rollback, injected late-snapshot rollback, two-process duplicate checkout, and linked reversal preserving receipt facts.
- Target-runtime lint passed **55 PHP files** at that checkpoint and PHPStan reported no errors. Later UI/integration changes still require the lead's final lint/static receipt.
- `tools/verify-upgrade.php` passed the actual original **001 → 002/003/004/005** upgrade: six original accounts and existing posted journal/header/lines preserved, existing setup moved to explicit review, role mapping/review restored a reconciled report, migration replay was empty, and the database session used UTC.
- `tools/verify-backup-restore.ps1` passed **15 table definitions, 6,069 sample rows, 9 guard triggers, all five migration receipts, scoped source links, and balanced journals**. The unique restore database was removed; the development database was untouched. The row count describes that checked snapshot, before the final suite rerun added fixtures.
- `tools/verify-demo.ps1` and the restricted-user demo smoke passed private visitor scope, CSRF/start, posting/reversal, setup/admin/delete/period/posted-edit denial, generation expiry/capacity/maintenance-lock behavior, and real replacement of the isolated demo generation. Only the literal sample demo database in `db_test` was reset. These are local backend results, not proof of a live hourly scheduler or public browser behavior.

The current migration inventory is **001_foundation**, **002_product_slice**, **003_demo_isolation**, **004_pos_showcase**, and **005_demo_period_guard**. Migration 004 adds `pl_pos_sales` and two immutable-snapshot triggers. Migration 005 adds the demo period guard without rewriting an already-applied 003 checksum. The complete schema has 15 tables and 9 guards. No legacy root SQL dump was run.

### Independent local POS HTTP acceptance

`tests/pos-http-smoke.py` reuses the core HTTP client's loopback-only transport and memory cookie session. With private environment credentials supplied temporarily, the command `python tests/pos-http-smoke.py --company-id 3` passed **17 checks, 0 failures**. It used only the existing sample `HTTP Acceptance` company/book **3** and left posted source document **13** for inspection.

Two notebooks and three pens produced **USD 12.75** sale total, **20.00** cash received and **7.25** change. The receipt preserved item identities/currency, linked to the same source as the balanced journal, and returned the same durable receipt when checkout was repeated. Trial-balance totals increased only once by 12.75. Invalid CSRF, changed book scope, forged total, insufficient cash, changed-content replay, GET checkout, and receipt access after logout were rejected. Insufficient cash retained quantities, cash amount, and checkout identity; failed submissions left report totals unchanged.

Inspection first found that the route passed browser transport fields into the strict financial payload. The lead corrected that integration by validating/removing only CSRF/company/book transport fields, preserving rejection of forged financial fields. The full 17-check run then passed. No other company's books were changed, no database was reset, and no actual payment/provider was contacted.

Changed POS files include `resources/core/pos-catalog.json`, `includes/functions/pos_functions.php`, `templates/views/pos.php`, `public/assets/pos.css`, `public/assets/pos.js`, migration 004 under the new application, `tests/pos_test.php`, `tests/pos-http-smoke.py`, the existing test runner/concurrency worker, and [POS documentation](POS.md). The nine supporting views and central documentation changes are recorded above; route/bootstrap/layout integration and final visual work belong to the lead/website workstream.

POS PHP and JavaScript syntax checks passed locally. These HTTP checks do not establish search/cart JavaScript interaction, responsive/keyboard usability, print layout, printer hardware compatibility, or public `/demo` behavior. Website, app browser review, deployment, live reset schedule, hosted GitHub checks, accounting sign-off, and observed user success retain separate gates. Publication is authorized but no live result is claimed by this checkpoint.

References read for these updates: current routes/helpers/schema/tests; local architecture/product/design/roadmap/funding/license/validation records; the preserved approved plan and website photography record; and the bundled regional-data provenance. No Google Drive documents were read. POS/HTTP external public calls: **no**. The separate regional research workstream read official Country API/Unicode sources and downloaded CLDR data; runtime country lookup tests used mocks and local loopback addresses skipped it. Migrations/schema changed: **yes**, additive sprint schemas as listed. Raw secrets exposed: **no**. Live/production changed by this checkpoint's workstreams: **no**; root-owned publication remains pending its release receipt.

After the expanded scope update, documentation checks covered **13 authored files and 85 local links** with **0 missing links and 0 trailing-whitespace errors**. Both issue forms parsed as YAML, both Python HTTP scripts passed syntax parsing, the POS catalog parsed as JSON, and scoped `git diff --check` passed (with the existing README Windows line-ending notice). The historical plan SHA-256 still matched the preserved value above. Final local PHP lint also passed the changed POS test/view and help view, and `node --check` passed `pos.js`.

### Final backend integration review

The product-foundation review inspected the current router, web helpers, financial/demo views, isolated Compose services, reset command and scheduler. Balance-sheet equity includes earned profit exactly once. Profit-and-loss drilldown passes both date boundaries to account activity, including pagination. Demo bootstrap obtains the maintenance lock before reading visitor generations or writing books; reset runs with a separate credential and targets only the literal isolated database. The scheduler starts with a guarded due check, wakes at UTC hour boundaries and retries failed refreshes after 30 seconds. This code review is not a hosted scheduling receipt.

Two bounded fixes were made in `www/phpledger/public/index.php` and `includes/functions/web_functions.php`: local HTTP cookies now require demo mode, the explicit local-only flag, a loopback Host and an actual private/loopback connection peer; early error-page assets and recovery links retain the configured `/demo` path. The existing regional guard test now covers allowed local peers and denial of public, invalid, non-demo and flag-disabled requests. The shared layout's opening-readiness copy was separately flagged to the lead because the service blocks new drafts as well as posting until readiness is resolved.

- Final `docker compose --profile test run --rm test composer check`: **55 PHP files linted, 0 failures; PHPStan 0 errors; 54 integration tests, 0 failures**. Offline fixtures again validated **7 packs, 77 events, 42 documents and 16 items**, with all **8 intentionally invalid variants rejected**.
- `python tests/demo-http-smoke.py` passed **18 checks, 0 failures** against **http://127.0.0.1:18202/demo/** after those fixes. Checks covered scoped entry and cookies, CSRF, owner reports and POS, separate sample records, setup/admin route rejection, unknown-record rejection, health, logout and denied access after logout. Its new sample visitor is retained only until the hourly reset.
- Two additional read-only loopback requests verified **404** for `/demo/review-missing` and **405/Allow: POST** for `GET /demo/start`, including `/demo` asset/recovery links and `Cache-Control: no-store`.
- The prior actual upgrade and restore proofs remain applicable because these final fixes changed no schema: **001 through 005; 15 restored tables; 6,069 rows in the checked snapshot; 9 triggers; all five receipts**. Later suite fixtures do not alter the recorded snapshot count.

This review changed the two PHP integration files, their existing regional guard test and this validation record. Migrations/schema changed by the final review: **no**. References were current local implementation and project docs; no Google Drive or new external reference reads were needed. Public/external calls by the final review: **no**; isolated Docker and loopback HTTP checks: **yes**. Raw secrets exposed: **no**. Live/production changed by this review: **no**. Browser presentation, hosted refresh operation, accounting sign-off and observed usability require their own evidence.

### Publication hold, reporting reference and POS confirmation follow-up

The user subsequently rejected the reports/POS presentation and requested professional/country reporting research and POS design research. **Publication is on hold for that review/redesign.** The prior technical passes and publication authorization do not override the new product-quality gate; no hosted publication is claimed by this record.

The documentation workstream added [DEMO.md](DEMO.md), covering the actual Compose services, separate literal demo database, private environment examples, restricted web/reset identities, visitor/document capacity, generation expiry, UTC-hour scheduler, proxy trust, and deployment/rollback pattern. It reviewed publication candidates without staging, committing or pushing. The lead excluded two generated Python bytecode cache files. Private environment files, dependency folders, app storage and output remain ignored. A bounded text-pattern scan found no private keys, common provider/GitHub tokens, or credential-bearing URLs among the inspected changed/untracked text files; reviewed literal test passwords were sample. This is not a forensic audit of the entire historical repository.

The [reporting gap analysis](accounting/REPORTING_GAP_ANALYSIS.md) reviews the existing five-root-type summaries, schema, report routes/templates and tests. It acknowledges the shared layout's global opening-readiness warning while distinguishing the missing structured report-completeness/issuance state. It records the user's requirement that accounting guidelines govern system behavior as well as report format, and proposes versioned classifications, mappings, coverage checks and reproducible issued results. No reporting schema or services were changed by that review.

The original [retail statement example](accounting/examples/retail-statements.md), in PKR thousands for 30 June 2026/2025, is **documentation-only and not installed sample data**. A Python check parsed its actual UTF-8 Markdown tables and passed **64 exact-Decimal arithmetic assertions, 0 failures**, including P&L, position, equity, indirect cash flow and opening bridges. The tax amounts are supplied fixture values, not Pakistan tax calculations. These checks prove the example's arithmetic, not an implemented accounting framework, COGS/subledger system, or PHP report service.

Following the lead/website workstream's bounded implicit-Enter guard, `tests/pos-http-smoke.py` now supplies explicit `checkout_intent=record_cash_sale` only for deliberate checkout. The final run passed **21 checks, 0 failures** against loopback port 18200 in sample company/book **3**, leaving source document **14**. Missing and unrelated intent each produced a recoverable **422** and unchanged trial-balance totals. The deliberate sale still produced USD **12.75**, cash **20.00**, change **7.25**, a scoped source/journal link, and one report effect after replay. Other-company records were untouched. This HTTP evidence does not replace the browser keyboard/no-JavaScript/print checks.

The independent [designer asset review](design/brand/designer-2026-09-14/REVIEW.md) visually inspected the supplied layout and six unaltered 500×500 RGB JPEG variants. It recommends retaining the book/P identity and sidebar/spacing direction, comparing the proposed Manrope/Poppins specimen, and keeping **Inter for money amounts**. It records that SVG/transparent light/dark masters and small-size icon exports remain needed. No logo was edited and no body-font choice or stylesheet was overridden by that review.

At this checkpoint, the publication-candidate inventory had **260 files and 45 Markdown files**. Local checks resolved **248 documentation/image links with 0 missing targets and 0 whitespace errors** in current Markdown, preserving `PLAN.md` and `LEGACY.md` as historical exceptions. The original plan hash remained unchanged; Python syntax and scoped diff checks passed. Candidate counts may change as parallel work continues. References were current local implementation/docs, user-supplied images and local font audit metadata; no Google Drive documents, external/live requests, or production changes occurred in this follow-up. Migrations/schema changed: **no**. Raw secrets exposed: **no**.

### Accounting research, font proof and original asset delivery

The lead added the [accounting research index](accounting/README.md) and [UK/UAE research](accounting/UK_UAE_REPORTING_RESEARCH.md), complementing the Pakistan workstream. Official SECP, ICAP, ICMAP, ACCA, FRC, GOV.UK, UAE MoF/FTA and IFRS references are linked in the country documents. They distinguish framework/entity/period requirements and recognition/measurement from presentation choices. The original UK 2008 schedule PDF was read as a historical visual reference, not current consolidated law. ACCA member model accounts were not obtained and no request was sent. Applicable current notifications, complete supporting modules and qualified accounting review remain release gates.

The [typography study](design/typography-review/README.md) includes locally hosted, licensed Manrope/Poppins/Inter files and inspected metadata. Actual browser font checks confirmed Manrope headings, Poppins body and **Inter for amounts and numeric entry**, with tabular lining numerals. Chrome captures cover 1440px desktop and 390px mobile; separate in-app DOM checks also covered 768px and 320px with no horizontal page overflow. The working app's body font was not replaced by this study. Exact 200% zoom and observed user task completion remain unverified.

The supplied Google Drive folder and its PHP SVG/PHP PNG subfolders were read. All 24 originals, named 12–23 in each format, were downloaded unchanged after connector metadata inspection and are recorded in the [original-asset manifest](design/brand/designer-2026-09-14/originals/manifest.json). All 24 size/hash checks, 12 bounded SVG XML screens, 12 transparent-PNG checks and two JSON parses passed. The SVGs contain raster-backed symbols and outlined wordmarks; they are not fully vector masters. Remote operations were read-only, with no Drive edits or sharing changes.

Following the user's cropping authorization, [prepared SVG assets](design/brand/designer-2026-09-14/prepared/README.md) change only root canvas attributes and verify unchanged drawing payloads. A built-in image-editing attempt changed lettering and was rejected; it is not a shipped asset. The user's later comparison favoured the existing application horizontal mark, which remains the recommended main identity. Original source artwork is preserved. Browser framing checks and the subsequent regional additions have their own receipts.

At this checkpoint a fresh isolated `composer check` passed **55 PHP lint checks, PHPStan with 0 errors, 54 integration tests**, and the existing seven-pack sample validation with all eight invalid variants rejected. `node --check` passed the POS script and the SVG preparation script. This check predates the subsequent five-country addition and does not claim coverage of uncommitted work added afterward.

The local demo scheduler was observed performing its automatic UTC-hour refresh, invalidating prior sample visitor sessions. This is local scheduling evidence only. The hosted release remains staged privately and stale relative to this work; its scheduler, public proxy, final asset sync and live verification remain outstanding. Earlier VPS staging installed Docker and prepared isolated private services; it did not switch the public website. No public deployment, GitHub write, outreach or funding collection occurred in this research/asset work. Migrations/schema changed by this work: **no**. Raw secrets exposed: **no**. External read-only calls and image editing service: **yes**. Live/production changed by this work: **no**.

### Five-country base-currency addition

The shared currency choices now include **Malaysia/MYR, Bangladesh/BDT, Sri Lanka/LKR, Nepal/NPR and Singapore/SGD**, retaining USD/EUR/GBP/PKR/INR. Demo entry, normal onboarding preview, company creation and demo provisioning all use `pl_base_currency_options()`. The existing CLDR 48 registry already contained the five country/locale/currency mappings; it was not regenerated. Cached country suggestions refresh their local support metadata without another API request. Existing company/sample currencies and amounts are not converted or changed.

The final isolated `docker compose --profile test run --rm test composer check` exited **0**: **55 PHP files linted, 0 failures; PHPStan 0 errors; 56 integration tests, 0 failures; seven sample packs/77 events/42 documents/16 items valid; eight invalid sample variants rejected**. The two added cases verify country names/locales and cached support refresh, then all five currencies through actual sample setup, POS, journal previews/posted journals, profit/loss, position and cash balances, and unchanged setup identity after a conflicting currency replay. Initial new test assertions used fields absent from the existing document/trial-balance contract; those assertions were corrected to the actual interfaces before this final passing run. No production service change was needed for that test correction.

`python tests/demo-http-smoke.py --currencies` passed **68 checks, 0 failures** on `http://127.0.0.1:18202/demo/`. It retained the existing 18-check restricted-demo journey and added ten checks for each new currency. Each selected sample started with 875.00; its 12.75 cash sale with 20.00 tender/7.25 change produced one receipt, profit/assets of 887.75 and trial-balance debit totals of 1,012.75 in the selected currency. Repeated checkout returned the same receipt; resubmitting `/demo/start` with USD retained that visitor's original currency and posted totals. Six new sample visitors were used; no reset was invoked or existing sample edited. Python syntax compilation also passed.

Chrome inspection of the changed `/demo/login` selector covered **1440×1000 desktop, 768×1024 tablet and 390×844 phone**. The initial long labels clipped in the 300px control, so the final labels use country plus currency code. Final visual checks showed readable, contained labels, including Sri Lanka on the phone viewport; keyboard Arrow Down changed Sri Lanka/LKR to Nepal/NPR. The temporary viewport override was reset and the QA tab closed. Normal authenticated onboarding was covered by shared validation/sample-service tests, but its screen was not separately browser-tested in this addition. No new live country-IP lookup, exchange-rate call or country reporting/tax implementation was claimed.

Changed application files: regional, ledger and demo helpers; onboarding validation in `public/index.php`; demo/onboarding templates. Tests: regional and POS suites plus the optional expanded demo HTTP check. Documentation: README, architecture, demo runbook, regional-resource README and this receipt. References: bundled CLDR source metadata and the official Unicode CLDR currency-data URL read again; no Google Drive documents were read for this addition. Migrations/schema changes: **no**. Raw secrets exposed: **no**. External calls: **read-only CLDR reference only**; application checks used local sample data. Live/production changed: **no**. Hosted publication remains on hold, and framework review/FX/translated interfaces remain separate future work.

### Preferred header framing and live GitHub metadata

The app layout/styles, website homepage/credits/styles and typography specimen now frame the unchanged preferred horizontal logo more tightly. No raster pixels were edited. Desktop/mobile captures confirm the complete book/P and wordmark with no page overflow; the website menu also fits at 320px. The local `/demo/` returned 200 and retained its `/demo/assets/` logo and versioned stylesheet URLs. PHP lint, CSS parsing and website static asset checks passed; the revised conservative website budget is 592,323 bytes. Source PNG/WebP hashes remained unchanged. Fonts were not changed by the framing work. See [website source record](design/website/SOURCES.md) and [app capture](design/website/qa/brand-framing/app-desktop.png).

The user then explicitly requested the missing repository metadata update. The lead checked the Git remote and current GitHub fields, verified `https://phpledger.com/` returned HTTP 200, and updated **only the About description, homepage and eight topics** on `phpledger/phpledger`. The description says the revival targets PHP 8.5/MySQL 8.4 and is in development. Immediate GitHub read-back matched all requested values and confirmed default branch `master`. Exact values are in the [Sprint 02 receipt](SPRINT-02.md#github-about-metadata-applied--14-september-2026).

This is a completed live **GitHub metadata** change. It is not a code push, README publication, license change, new website deployment or public demo activation. The existing website still serves its prior content. No commit, staging or branch change occurred. Local receipt files updated: README, Sprint 02 and this validation record; Markdown diff checks passed. External/live calls: **yes**, GitHub metadata write/read-back and public website read. Live repository metadata changed: **yes**; application/website production changed by this operation: **no**. Migrations/schema changes: **no**. Raw secrets exposed: **no**. No messages, purchases or funding collection were performed.

### Hosted website and restricted demo publication

The user explicitly requested and reconfirmed replacement of the existing website and publication of the latest demo. The lead reviewed the exact allowlisted package, site-only proxy configuration, guarded demo reset, scheduler and rollback sequence before execution. At approximately **17:50 UTC on 14 September 2026**, [phpledger.com](https://phpledger.com/) began serving the new website and [the public demo](https://phpledger.com/demo/) became available over HTTPS.

Application release **`sprint-02-20260914-174642`** contains **111 source files** plus its manifest, with a **2,096,316-byte** archive and SHA-256 `befa197c90bddb6fef943dcebd7ba4e86e30518117984ff8576e8617b127d8e0`. Every archive member and source hash was verified before extraction. The package contains the new application, static website, required Docker/Composer files, active core/sample/locale resources and demo maintenance tools. It excludes legacy application code, local configuration/environment files, storage, Git history, local vendor output, tests and unpublished research. Composer installed the locked dependencies inside the new image. The existing staged release was preserved.

Fresh private backups of the previous website, PHP Ledger Nginx configuration and demo environment were saved under the matching release identifier. Backup/private directories were verified as mode **700** and private files as **600**, owned by the deployment operator. Only the PHP Ledger vhost was replaced; the website serves a static public directory and has no financial-database connection. `/demo/` proxies the loopback service and overwrites forwarded client/protocol headers. HTTP and existing aliases redirect to canonical HTTPS; the existing ACME challenge exception and valid certificate were retained. Nginx syntax validation and reload succeeded. The first immediate post-reload health probe returned 404; subsequent independent public probes returned 200, and the full workflows below passed. No persistent origin failure was observed and rollback was not required.

The existing isolated MySQL container and named volume were preserved. The expired, zero-visitor demo generation was refreshed through the guarded reset service, then the new web and separate hourly scheduler containers were started. Hosted checks verified **15 tables, five applied migration receipts matching the deployed files, nine accounting/demo triggers, ten supported base currencies, restricted web grants, and a reset account limited to the literal `phpledger_demo` database**. Public session cookies carry a separate name, `/demo/` path, Secure, HttpOnly and SameSite=Lax; public mode has the local HTTP exception disabled.

The hosted version of the expanded sample demo check passed **68 checks, 0 failures** at `https://phpledger.com/demo/`. These cover CSRF, reports, POS, server-side administration rejection, source isolation, logout, and each of MYR/BDT/LKR/NPR/SGD through sample creation, exact cash checkout, stable replayed receipt, reconciled P&L/position/trial balance and unchanged currency on re-entry. An initial generated test copy corrupted its expected em dash through Windows default text decoding; correcting that ignored test file's UTF-8 handling produced the final pass without an application change. A separate read-only check passed **71 checks, 0 failures**, covering canonical routes, the ACME exception, inaccessible private paths, security headers and SHA-256 equality for all initially published static pages/assets. These initial asset checks predate the contact correction below.

Hosted runtime verification confirmed **PHP 8.5.10**, all required production extensions, **39 deployed application PHP files linted with zero errors**, and no advisories from the locked production dependency audit. Ordinary Composer metadata validation passed with warnings; **strict validation returned 1** because the project license field is unset and Composer discourages the deliberately exact MeekroDB pin. No license was invented to suppress that warning. The license/provenance decision remains an explicit first-package gate. The earlier local **56-test** integration/static-analysis result remains the backend regression evidence; those database tests were not run against the public demo. The bounded live log check found no PHP fatal/uncaught errors and one recoverable busy 503 on a simultaneous QA request, consistent with the documented serialized demo limit.

The **actual 18:00 UTC scheduler reset was observed without a manual trigger**. Before the boundary, the generation fingerprint was `dc613b0201755a600ce67d78`; at 18:00:13 all sample visitor/company/document/journal tables were empty during rebuilding and health returned the maintenance response. By **18:00:17**, a browser-session check passed **3 assertions, 0 failures**: the pre-boundary GBP visitor existed, the prior session returned to demo entry after the reset, and the same browser started a clean GBP 875.00 sample. At 18:00:36 the new fingerprint was `77af188e3fc2ed95504537a3`, next reset **19:00 UTC**, and only that new sample existed with six documents and two journals. Scheduler logs recorded success and restart count remained zero. The demo database container identity/start time and host MySQL process/start time were unchanged across the reset. Temporary maintenance during rebuilding is an observed limitation, not a claim of uninterrupted demo availability.

The user's later contact correction was published at **17:59:40 UTC** as a separate immutable static release, **`website-20260914-175932`**, with **26 files** and archive SHA-256 `42b711e0d1a38a425aa5a9c01687201e5f77648a367663d92181bf8b0c815917`. The phone and telephone link were removed and the address became exactly **Innovista Chenab**. A fresh prior website/vhost backup was retained; only the static document-root path changed. Public HTML hash `edfddddd1488a3e47ecd17706cbfd43e5bd7b44cc766359780663f16e74faba7` matched the reviewed local file. Demo code, credentials, containers and scheduled reset were not changed by this correction. Subsequent partner-logo changes require their own static release receipt.

The [independent live browser check](design/website/qa/live-20260914/README.md) covers the website at 1440/768/390/320px, keyboard tabs/menus/dialogs, published documentation links and the actual sample report screen. It found no blocking presentation issue in that bounded pass. This does not substitute for accounting review, a security/privacy assessment, load/soak tests, receipt-printer testing, WCAG conformance or observed usability sessions. The requested reporting/POS redesign, country frameworks and first installable package remain incomplete.

Files changed by this deployment workstream: this validation record, the demo runbook and ignored local packaging/verification scripts; the site's deployed release/configuration/private release identifier and isolated demo runtime were changed remotely. Website source changes belong to the website workstream. References read: current local runtime/runbook/lockfile, actual scoped host configuration and public responses; **no Google Drive documents** were read for publication. New migration files: **no**; existing **001–005 were replayed when rebuilding only the sample demo schema**. Customer/host accounting schemas changed: **no**. Raw secrets exposed: **no**. External/live calls: **yes**, authorized SSH/SFTP/deployment, dependency retrieval/audit, country hints and HTTPS checks. Live website/demo changed: **yes**. No message, phone call, payment or funding collection occurred; other domains and host services were not restarted.


### Supporting-company logos and final public wording

The verified local logos for **BixiTech, BixiSoft, BrownBag and Agency75** were published in static release `website-20260914-180337`. A combined check against that release passed **77 routing/security/asset assertions, 0 failures**, including exact hashes for the four logos and their provenance files. The website workstream separately checked the responsive logo strip and keyboard focus; no demo source or scheduler configuration changed.

The user's final heading, **“Companies that support our open-source initiative.”**, was then applied to the website and the public credit/source wording was aligned to describe support without a corporate-ownership claim. The final static release is **`website-20260914-180534`**, published and verified at **18:05:41 UTC**, with 32 files and archive SHA-256 `47b4616d4b6e2b2bc3f40a8433c2f6ee6b75e77a3d329c0ec15886a138125039`. A late source-record wording edit was received after the first heading package had been sealed, so it received this further versioned static package rather than an in-place release modification. The three changed public text files matched their final local hashes and the exact heading assertion passed. The phone remains absent and the address remains **Innovista Chenab**.

Each static cutover preserved a private backup, changed only the static document-root path, and passed Nginx validation/reload. The application remains `sprint-02-20260914-174642`; demo credentials, containers, database state and scheduler were unchanged. The 77-check full pass predates the final wording-only package; its four focused text/hash assertions are the follow-up evidence, without claiming a repeated full UI/accounting run. New migrations/schema changes: **no**. Raw secrets exposed: **no**. External/live website writes and public reads: **yes**. No messages, calls or payments were made.


The final contact correction requested afterward was published at **18:08:16 UTC** as **`website-20260914-180808`**. It restores **Innovista Chenab, Arcade Plaza, Sector C, DHA Multan, Punjab 60000, Pakistan**, removes only the office-floor prefix, retains no phone number, and adds the verified [LinkedIn profile](https://pk.linkedin.com/in/rmak78). Public HTML matched SHA-256 `990ea879857ca26b3b8001aa2db1d2ea94a5b09e7d83699a301f54d55492120c`; focused assertions verified the full address, profile link, missing phone and missing floor prefix. The archive SHA-256 is `db9f0db7529caef9d965524b4feaa9d87e699d4020ba809421e652a12cd111da`. Nginx validation/reload passed, a fresh prior static/vhost backup was retained, and the demo runtime/scheduler were unchanged. This supersedes the earlier shortened-address wording.


### Installer prerequisite and safe-retry checkpoint

The first-package work adds the CLI-only `www/phpledger/install/preflight.php`. It uses the existing shared bootstrap/configuration and MeekroDB connection, checking PHP 8.5, BCMath/mbstring/PDO/PDO MySQL/session availability, Composer autoload, MySQL 8.4 and versioned migration checksums without schema/account writes. The existing runtime-version guard moved unchanged to `includes/functions/runtime_functions.php` so prerequisite inspection can run before a database connection. Fresh empty databases and recognized earlier migration chains report migrations pending; unversioned nonempty databases, unknown/interrupted receipts and checksum differences stop safely. File-session directory checks apply to the CLI identity only; custom handlers and directory-depth layouts are explicitly unverified warnings, and web-user access remains an installation check.

Migration CLI bootstrap failures now pass through its safe error handler. Initial-user creation checks prerequisites/current migration state before calling the existing user service. Configuration exceptions, including an exception whose message contains sample private configuration, are not copied to output. Repeating user creation with the same email returns failure without replacing the original row or password.

The final bounded validation ran **only the installer suite** with `docker compose --profile test run --rm test php tests/run.php --suite=installer`: **6 tests, 0 failures**. Cases cover missing runtime/extensions/dependencies, session limitations, fresh/pending/unsafe receipt states, actual read-only preflight and unchanged migration replay, wrong database credentials, missing autoload, invalid/private exception-bearing configuration and duplicate administrator preservation. All **7 changed PHP files** passed lint, and scoped PHPStan for the installer/runtime/bootstrap reported **0 errors**. Diff whitespace checks passed. Tests used only `phpledger_test` and temporary sample configuration fixtures. The full accounting suite, fresh packaged-artifact browser installation and recovery/upgrade checks were **not rerun** at this user-requested checkpoint; those remain package-acceptance work.

Changed files: new runtime helper and preflight CLI; bootstrap, migrate/create-admin entry points; installer tests and the runner's `--suite=installer` selection; this receipt. The package installation draft was read and its command sequence matches the implementation. Google Drive documents read: **none**. New migrations/schema changes: **no**; migration file contents were preserved. Raw secrets exposed: **no**. External/live calls for the installer checkpoint: **no**. Live runtime redeployed: **no**. The separately published website/demo receipts above remain the live evidence. Work stopped after these targeted checks as requested.


## Core accounts and journals — 15 September 2026

The [0.1.2 core release receipt](repository/sprint-04/CORE-0.1.2-VALIDATION.md) records account statements, audited chart management, general journals, disabled tax research, migration 006, current tests and synchronized publication. Earlier receipts above remain historical evidence.

## 0.3.0 publication — 16 September 2026

The owner authorized the foundations package, hosted sample demo and completed website publication. The [combined machine-readable receipt](repository/sprint-05/PREVIEW-0.3.0-PUBLICATION.json) records exact package bytes/hash, both extracted-package runtimes, frozen-backup rehearsal, preserved original fields/receipts, 17 live migration receipts, 62 guards, stable view definers and the real hourly reset. The reset observer's transient-database timing failure is recorded explicitly; it is not reported as a completed old-token replay.

The live browser exercised all four sample packs, 1440/768/390 layouts, report drilldowns, no-JavaScript support and cross-visitor denial. Website checks covered all 62 indexable pages at four widths, ten interactions and twenty final layout/encoding correction checks; all 162 published static files matched their reviewed archive hashes. Qualified accounting review, independent security review and observed business usability remain open. No operational AR/AP documents, connector activation, messages or payments were introduced.


## 0.6.0-preview publication - 18 September 2026

Published GitHub prerelease, upgraded hosted demo and generated website; Wiki/About updated. Exact ZIP fresh/0.5 upgrade checks passed on PHP 8.2/8.3/8.4, source 282 tests passed, and local plus actual frozen hosted backup restoration verified. Public downloads and media-kit checksums match. See [publication receipt](repository/PUBLICATION-2026-09-18.md) for results, backups and disclosed remaining acceptance limitations.


## 23 September 2026 — Local cash, bank, parties and samples revision

This delivery remains local. The implementation and exact evidence are recorded in [the cash/party/sample receipt](repository/CASH-PARTIES-SAMPLES-2026-09-23.md). Three additive migrations are included: nullable scoped document parties050, explicit money-account classification051, and agreed bank overdraft limits052. Original migration receipts and posted values must remain unchanged. Default bank borrowing allowance is zero; no facility is inferred during upgrade.

Acceptance covers central posting under the book lock, daily and future-date balances, currency units, exact retries, concurrent spending, reversal/correction rollback, linked-party snapshots and old callers, form state preservation, both workspaces, independent report help, versioned sample/source links, zero-balance structures, populated upgrades and exact-package fresh installs. Unfunded practice events are disclosed and staged, not forced through with invented funding. A technical pass does not replace independent accounting/security review, translated-copy review or observed business usability. Consult the receipt for completed checks and remaining gaps rather than treating this list as a pass claim.


## 1.4.0 administrator choice for cash and bank controls

Admin > Accounting policies now offers **Warning only** and **Strict** for each book through the existing `policy.manage` permission. Warning only is the default on fresh installs and upgrades, preserving the ability to record transactions even with a shortfall or an unclassified money account. The receipt/expense preview still explains recorded balances and shortfalls. Strict requires explicit physical-cash/bank classification before money-out postings and enforces the physical-cash zero floor and any explicitly agreed bank overdraft limit. No separate loan or unused credit line is treated as funding.

Changes require a reason, revision and idempotency key and are audited through the existing accounting-policy service. Policy updates and central posting share the book lock; posting reads the effective choice afresh, including after an earlier caller snapshot. Reversals and atomic corrections follow the same selected mode. Neither mode edits historical journals or guarantees bank clearance. Migration 060 adds the warning-default policy field to the existing policy table; feature migrations are 057 document parties, 058 money-account classification and 059 bank facilities, after the published 1.3.0 chain through 056. This release supersedes the earlier always-strict local implementation; earlier validation receipts remain historical. No media kit is included by explicit owner instruction.


## 1.4.0 live release acceptance (23 September 2026)

See the [immutable-artifact gates](repository/RELEASE-1.4.0-GATES.md) and [public deployment receipt](repository/PUBLICATION-2026-09-23-1.4.0.md). Local/final CI accounting suites pass 694 tests on both MySQL and MariaDB. Public verification covers 413 website paths, official signatures, 11 samples, four registry/architecture payloads, 61 fresh-install migration checksums and the actual 18:00 UTC reset. Live browser checks cover administrator Strict save, durable inline supplier selection with transaction-input preservation, insufficient-cash draft save and posting refusal, the receipt selector and a Cedar chapter source journal.

Acceptance is bounded: Chromium resized desktop/tablet/phone checks are not real-device or independent accessibility/accounting/security review. All 15 website route/width combinations passed. The application phone cash-warning/detail layout remains cramped, combined transaction navigation still appears alongside the requested separate workspaces, and pre-existing sample Ready text incorrectly reports empty books after a full import. Actual posted sample history and the financial refusal were verified. These presentation/UX gaps remain open; do not describe all original UI acceptance criteria as complete. Ten non-Cedar enriched monthly journeys remain intentionally incomplete.
