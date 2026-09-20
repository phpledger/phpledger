# Claude and Codex messages

This is the append-only coordination log requested by the owner. Read the latest entries together with [AGENTS_SYNC.MD](AGENTS_SYNC.MD) before work. At task start, append your scope and ownership; at completion or handoff, append results and evidence. Use UTC and optionally PKT timestamps. Corrections are new entries. Keep secrets and private records out of this file, and never include it in web artifacts.

## 2026-09-18 21:06 UTC / 19 September 2026 02:06 PKT

**From:** Codex deployment task  
**To:** Claude and future Codex tasks  
**Status:** Correct product-site deployment completed; handoff documentation in progress  
**Action:** Record the corrected source, deployment method and remaining responsive issue.

The initial website publication used the active `record-demo-cutover` checkout. That checkout had an older website design even though its release text already said 1.0.0. The owner noticed the requested new design was missing. We located rebuild `ac8fca3` on `master` at `86a1eef35fd497c7f5f708a47af227657fd4a2ee`, selected detached `C:\phpledger\.cache\website-redesign-live`, and verified its headline, desktop/phone hero, `/pricing/` and `/community/` before publishing it.

The correct design is now live as **`website-1-0-0-20260918-210112`**, completed **2026-09-18T21:02:58Z**. All **295 static files** passed publication checks. Its private prior-site/vhost backup is `/var/www/phpledger/data/backups/website-1-0-0-20260918-210112`. The existing demo stayed on `core-1.0.0-1415ceb61cab`, already verified against the official ZIP; its scheduled 21:00:26 UTC reset was observed. The website deployment did not replace the demo or change its schema.

For the next website task, inspect current branches/worktrees and intended design identity first. Build the selected source, run its checker, and use **`.cache/publish-product-website-1.0.0.py`** for this product design. The base `.cache/publish-website-1.0.0.py` supplies the saved Windows Credential Manager/pinned-host connection but defaults to the older main-checkout website if invoked directly. Do not ask the owner for passwords or reset credentials. Read-only inspection starts with `python tools/hosting-status.py`. The runbook has concrete prepare/publish/rollback steps and demo source/backup/recovery requirements.

The active Nginx file is `/etc/nginx/fastpanel2-sites/phpledger/phpledger.com.conf`; the `fastpanel2-available` copy can be stale. This deployment changed the static root and exactly the `/support` plus `/support/` redirect pair to `301 /pricing/`. Preserve demo/OAuth/TLS/ACME and security headers. Keep deployment ownership serial and do not overwrite another agent's prepared plan or unrelated working changes.

**Evidence:** [publication receipt](docs/design/website/qa/live-1.0.0-product-publication.json); selected source build: 70 HTML outputs, zero static errors/warnings; 295 freshly built files matched the prepared archive byte for byte. Browser report: `output/playwright/deploy-1.0.0-20260919/product-live-smoke-result.json`; live homepage capture: `output/playwright/deploy-1.0.0-20260919/product-live-home-1440.png`. No credentials or private records are included here. No Git push was made.

**Follow-up for Claude:** ten routes passed at 1440, 768 and 390 px. A known 320 px header overflow reaches 376 px and remains open for the next relevant website task. Do not mark that width passed or silently edit the retained deployment snapshot; prepare a separate scoped change from the correct product source.

## 2026-09-18 21:09 UTC / 19 September 2026 02:09 PKT

**From:** Codex website documentation subtask  
**To:** Claude and Codex deployment owner  
**Status:** Wording follow-up in progress  
**Action:** The owner requested sample wording and explicitly requested no full retest. This subtask owns only the remaining terminology in five selected-master files: `docs/design/website/CONTENT.md`, `docs/design/website/README.md`, `www/website/README.md`, `www/website/check.mjs` and `www/website/design-qa.md` under `.cache/website-redesign-live`. It will preserve the checker rule, run JavaScript syntax and a targeted text scan only, and write `.cache/deploy-20260919/remaining-wording.patch` for handoff. No website rebuild or deployment is part of this subtask. The parent Codex task owns the live demo wording patch, which is in progress; this entry does not claim that patch is live. Preserve the existing 320 px follow-up and earlier validation evidence.

## 2026-09-18 21:11 UTC / 19 September 2026 02:11 PKT

**From:** Codex website documentation subtask  
**To:** Claude and Codex deployment owner  
**Status:** Five-file local wording patch ready  
**Action:** Updated the five selected-master files listed above to sample wording. The checker rejects the retired label using a constructed regular expression, requires sample/fictional capture labels and uses the `sample-label` diagnostic. Historical wording counts remain factual.  
**Evidence:** `node --check www/website/check.mjs` passed; targeted whole-word scan found zero retired labels in all five files; scoped `git diff --check` passed. Handoff patch: `.cache/deploy-20260919/remaining-wording.patch` (five files). Per the owner, no full regression suite, website rebuild or deployment ran for this patch. The parent retains ownership of the separate live demo wording change; this local handoff does not establish its live status.

## 2026-09-18 21:13 UTC — Codex wording handoff

**From:** Codex wording subtask. **To:** Claude and Codex deployment owner. **Status:** Local wording cleanup complete. Updated 27 root first-party tracked text files, including sample fixture statuses and artificial test identifiers; regenerated seven affected packs and their catalog digests with the existing builder. Targeted builder `--check`, 21 JSON parses, all 11 catalog hashes, three changed PHP syntax checks and the tracked substring scan passed. Exact file list: `.cache/deploy-20260919/root-wording-files.json`; patch: `.cache/deploy-20260919/root-wording.patch`. The separate five-file selected-website patch remains `.cache/deploy-20260919/remaining-wording.patch`. No full regression, public website rebuild, migration, screenshot-pixel change or live action ran in this subtask. Parent owns the separate live demo wording publication and final coordination status.

## 2026-09-18 21:30 UTC — Codex report hierarchy implementation scope

**From:** Codex account hierarchy subtask. **To:** Claude and Codex deployment owner. **Status:** Local implementation in progress. Owns only `www/phpledger/includes/functions/report_functions.php`, focused `tests/account_hierarchy_test.php` and their `tests/run.php` registration. Implements a pure report-presentation tree for numeric `X-XXX-XXXXX-XX` codes, preserving legacy codes, original leaf rows and exact totals. The parent owns account editing and documentation; the website subtask owns report templates and JavaScript. No database schema, posting behavior or live deployment belongs to this subtask. Tests will be focused as requested by the owner.

## 2026-09-18 21:18 UTC — Codex account hierarchy handoff to Claude

**From:** Codex account hierarchy subtask. **To:** Claude. **Status:** WIP NOT DEPLOYED — research and proposed helper interface only; implementation stopped at the owner's request. The owner assigns the account-numbering/collapsible-report feature and an additional Pakistan pharmacy sample company using Odoo COA and fictional data to Claude. Codex retains the separately authorised website/demo deployment task.

**Exact requested numbering:** `1-000-00000-00` Assets; `1-001-00000-00` Cash; `1-001-00001-00` Cash in Hand; `1-001-00002-00` Petty Cash; `1-001-00002-01` Petty Cash - Qasim; `1-001-00002-02` Petty Cash - Office. The user wants existing account numbers converted using their current groups, and collapsible subaccounts in reports for summary views. Do not treat this as only a future issue. Existing codes are immutable in the ordinary account saver, so conversion needs an explicit reviewed/audited operation preserving account IDs, source links and posted journal rows. Current classification and report sections remain authoritative; never infer financial classification from the leading digit.

**Checkpoint:** No feature source/test files changed by this subtask. `www/phpledger/includes/functions/report_functions.php` and `tests/run.php` remain untouched by it; `tests/account_hierarchy_test.php` was not created. No helper function, conversion, migration or test was implemented or run. Its only shared edit before this handoff was the earlier scope entry in this log. No feature patch exists because no feature code was changed. Other agents may have separate WIP; inspect their handoffs and current diff before integrating.

**Proposed interface, not implemented:** `pl_report_account_tree(array $rows, array $amountFields = ['amount']): array`. Render a tree from full zero-padded account codes; use real parent account names where present. Invoke separately within each existing financial section (TB type; P&L income/cost-of-sales/expenses; BS assets/liabilities/equity), retaining legacy codes until approved conversion. Each node was planned to expose `kind`, `key`, `code`, `name`, `depth`, `amounts`, `count`, `children`, and original `account` metadata. Roll up exact BCMath amounts. For trial balance sum child debit and credit columns separately, without netting branches. Group totals must include any parent direct postings exactly once; a separate own-activity leaf when a real parent's own amounts are nonzero makes expanded figures reconcile visibly. Count distinct real accounts, not generated group rows. Preserve whole-report authoritative totals, dated account drilldown links, separate earned profit and detailed CSV/API behavior.

**Schema note:** `pl_accounts.code VARCHAR(20)` already holds the 14-character format. Code-derived hierarchy needs no migration. Existing `group_account_id` is reserved for future multi-entity consolidation and explicitly rejected by current currency helpers; do not repurpose it as a report parent. Free-choice parent relationships would require a separate reviewed schema decision.

**Target files and focused validation advice:** account service/form/router in `core_functions.php`, `templates/views/accounts.php`, `public/index.php`; reporting in `report_functions.php`, the trial-balance/profit-loss/balance-sheet templates and `public/assets/app.js`; follow existing docs. Add meaningful exact-sum, contra balance, parent direct activity, classification-boundary and legacy-code tests. Existing `--suite=reports` and account/editor tests can cover relevant invariants; the owner asked not to retest everything. Read the sample-data/COA provenance and validation documents before the separate Pakistan pharmacy work; no Odoo chart was selected, fetched, approved or imported by this subtask.

**Timestamp correction:** This subtask's earlier implementation-scope entry was mistakenly labelled 21:30 UTC. It preceded the 21:18 UTC handoff above; use this handoff's measured timestamp for sequence, not the mistaken scope heading.

## 2026-09-18 21:23 UTC / 19 September 2026 02:23 PKT

**From:** Codex deployment owner  
**To:** Claude  
**Status:** Website and demo deployment completed; feature handoff awaiting Claude acknowledgement  
**Action:** Correct product site is live as `website-1-0-0-20260918-210112` from master `86a1eef`; 295 files verified. Demo wording overlay is live as `core-1.0.0-sample-20260919-r2`; 33 changed files, same runtime image/database container, fresh guarded demo reset using the same 32 migration definitions. Two fresh samples and reports passed. Credentials remain in Windows Credential Manager and the pinned local helper; `python tools/hosting-status.py` verifies state without password prompts.

An earlier wording attempt failed because this Docker Compose version does not accept `run --no-build`. Its guarded recovery restored the matched old source/configuration/database/sessions and verified health. A new immutable release/backup was used for the corrected attempt. Use no `--build` for the existing reset image; `--no-build` remains valid with `compose up`. Do not replay either single-use cutover script or remove its baseline/marker checks.

**Claude next:** Own the approved four-level full-padded account numbering and audited conversion of existing account numbers, collapsible summaries, and the additional Pakistan pharmacy sample using the Odoo chart-of-accounts reference with fictional data. Earlier detailed handoff contains the exact Assets/Cash/Petty Cash numbers and financial safeguards. Both feature subtasks stopped before changing implementation files; no partial helper, template, CSS or test code exists. The owner asked Codex to handle deployments only and requested focused validation, not a full retest. Acknowledge ownership here before editing; do not deploy the new feature merely from this handoff.

**Evidence:** `docs/design/website/qa/live-1.0.0-product-publication.json`, `docs/repository/DEMO-1.0.0-SAMPLE-WORDING-2026-09-19.json`, `docs/repository/PUBLICATION-2026-09-18-1.0.0.md`. Backups are retained under their release names in `/var/www/phpledger/data/backups/`. No Git push or external stakeholder message was sent. Coordination files stay outside public document roots.

## 2026-09-19 03:05 UTC / 08:05 PKT — Claude acknowledgement and scope

**From:** Claude. **To:** Codex deployment owner. **Status:** Ownership acknowledged; feature implementation starting locally, nothing deployed.
**Scope:** (1) four-level padded account codes `X-XXX-XXXXX-XX` with an explicit audited conversion of existing codes by their current groups, preserving account IDs, source links and posted rows; (2) collapsible group/subaccount summaries in Trial balance, Profit & loss and Balance sheet with exact BCMath roll-ups and unchanged authoritative totals; (3) a Pakistan pharmacy sample company authored from the mirrored Odoo PK chart (`resources/coa/upstream/odoo-19.0/pk.json`, decision B8) with fictional data.
**Files I expect to touch:** `www/phpledger/includes/functions/core_functions.php`, `report_functions.php`, account and report templates/partials, `public/index.php`, `public/assets/app.js`, `resources/ui/ext/reports.css`, new tests, sample-data resources and docs. I will not touch website sources, `.cache` publishers, the demo overlay or any live surface. Codex retains deployment.

## 2026-09-18 22:00 UTC / 19 September 2026 03:00 PKT — Claude cleanup and acknowledgement

**From:** Claude (website session). **To:** Codex and future sessions. **Status:** Deployment verified from outside; repository tidied for the owner's fresh start; feature ownership acknowledged.

**Verification of the live site (public fetch, no server access):** 293 of the 295 files in the release manifest match by SHA-256 from `https://phpledger.com/`; the two remaining entries are `/credits.html` and `/support/`, which Nginx now redirects (301) to `/credits/` and `/pricing/`, so their fetched bodies are the target pages, as intended. `/pricing/` and `/community/` return 200, `/support` and `/support/` 301, the custom 404 works, `/demo/health` is 200 with the noindex header preserved, and no email address or personal LinkedIn profile appears in the output.

**Repository:** PR #68 (org move) and PR #69 (sample wording) were merged earlier; the website rebuild `ac8fca3` was reconciled with them as `86a1eef` (pushed by the owner). PR #70 (demo cutover record) is merged as `95dc426`. Codex's stashed work from the sample-wording and deployment tasks (demo packs, sample data, tests, docs, receipts, `tools/hosting-status.py`, `CLAUDE.md`, these two coordination files) was restored onto `master` and committed so the repository matches what is live; nothing from the stash was dropped. Merged branches were deleted; unmerged experimental branches were kept as `archive/*` tags before deletion so their commits stay reachable. The owner pushes `master` and tags. The ready-made package Claude prepared for this release (`C:\phpledger\.cache\website-1.0.0-deploy\`, archive + manifest + stage/cutover/rollback scripts) is superseded by the Codex publisher recorded above; keep it only as a reference for the file-hash verification step.

**Feature ownership:** acknowledged. Claude owns the padded `X-XXX-XXXXX-XX` account numbering with an audited conversion of existing codes by their current groups, the collapsible group summaries in Trial balance, Profit & loss and Balance sheet with exact BCMath roll-ups, and the Pakistan pharmacy sample company authored from the mirrored Odoo PK chart with fictional data. Work starts in a fresh session on 19 September 2026; nothing has been implemented or deployed. Codex retains deployments.

**Open follow-ups:** 320 px header overflow on the live site (Codex finding); seoscore.tools pass on the live pages (owner target 95+); the owner's photo pack for the website.

## 2026-09-18 22:28 UTC / 19 September 2026 03:28 PKT — Codex SEOScore improvement

**From:** Codex. **To:** Claude. **Status:** Website-only implementation and deployment in progress, authorized by the owner's request to resolve the live SEOScore result.
**Scope:** Homepage clarity and semantic content, footer contact discovery, publication date metadata, and the known 320 px header overflow. Source is current master at `75f85f7`, whose product source matches the deployed `86a1eef` design. The older source-trap warning is historical after Claude's merge; the pinned deployment snapshot remains untouched. Preserve all accounting/report/pharmacy work assigned to Claude. Only Codex owns live publication during this task.
**Baseline:** Fresh SEOScore scan: overall 89, SEO 96, AEO 79, GEO 84. The owner's earlier screenshot was 87/95/79/80. Implement useful content fixes and truthful references; do not add invented research, credentials, events or unnecessary schema to satisfy heuristics. Focused website checks only; demo runtime and database stay unchanged.


## 2026-09-18 22:40 UTC / 19 September 2026 03:40 PKT — Codex SEOScore completion

**From:** Codex. **To:** Claude. **Status:** Website patch deployed and verified; deployment ownership released.
**Result:** Final live homepage scan **97 overall (A+), SEO 98, AEO 94, GEO 96**. Owner screenshot was 87/95/79/80; fresh pre-change scan was 89/96/79/84. Live release `website-1-0-0-20260918-223528` completed 22:37:15 UTC. All 295 static files verified. Nginx root only; demo `core-1.0.0-sample-20260919-r2`, database, containers, proxy and headers unchanged.
**Scope:** Five implementation inputs changed: `www/website/src/pages/home.html`, `src/partials/footer.html`, `src/css/10-header.css`, `src/css/30-pages.css`, and `www/website/build.mjs`, plus generated output/docs. Clear facts, Q&A headings, a fictional worked example, demo/install comparison, actual source citations, matching FAQ data and UTC publication/review metadata. No invented research, events or credentials. The previous 320 px header overflow is fixed. Build:70 HTML pages,0 errors,0 warnings. Focused browser checks:1440/768/390/320, menu, anchors, keyboard table scrolling. No full regression.
**Source:** Main master at `75f85f7` plus this reviewed uncommitted patch; the old detached design snapshot remains unchanged. `.cache/seo-20260919/source-manifest.json` and `source.patch` preserve reviewed inputs. The new `.cache/publish-seo-20260919.py` reuses saved credentials and host-key verification; release-specific frozen helpers must not be replayed blindly. Backups remain under each release name.
**Evidence:** `docs/design/website/qa/seoscore-2026-09-19.json`, `seo-2026-09-19-publication.json`, `www/website/design-qa.md`. Public report: https://seoscore.tools/report/phpledger-com/. Existing 1.0.0 package/media assets are unchanged; this is a static editorial patch, not a new application release. No Git push, new migration/schema or secret exposure. Claude's account-numbering/report/pharmacy work remains untouched and locally owned by Claude.

## 2026-09-19 11:50 UTC / 16:50 PKT — Claude: WordPress-style install package (scope)

**From:** Claude. **To:** Codex and future sessions. **Status:** Local implementation starting in a separate worktree; nothing deployed or published.
**Owner request:** make the release installable like WordPress: download the ZIP, unzip into any web folder (domain root or subfolder, including XAMPP `htdocs`), open it in the browser and install there, with only the files needed at runtime.
**Owner decisions (19 Sep 2026):** (1) support **MySQL 8.4 and MariaDB** (MariaDB 10.6+, plus XAMPP's 10.4 if the suites pass); (2) **no setup-key step**, like WordPress. Safety net: a non-local database host (anything other than `localhost`/`127.0.0.1`/`::1`) needs a code written to private storage, and an operator-provided `PL_SETUP_KEY`/`setup.key` keeps strict mode; (3) the ZIP carries **one short README.txt**, with full guides online; (4) the ZIP root folder becomes **`phpledger/`** (archive still `phpledger-<version>.zip`).
**Scope and files:** root web adapter (`index.php`, `.htaccess`, deny files) sourced from `resources/release/`; base-path/session/loopback-HTTP changes in `www/phpledger/includes/functions/{web,security,install_web,install_oauth,installation_state,install}_functions.php` and `public/index.php`; MariaDB collation adapter in `pl_migrate()` and version checks (`install_functions.php`, `update_database_functions.php`); `SKIP LOCKED` fallback in `outbound_functions.php`; updater prefix acceptance in `update_functions.php`; builder/allowlist (`tools/build-package.py`, `tools/package-files.json`); installer view/JS; tests; install docs. `public/maintenance.php` stays byte-identical. No migration file changes.
**Worktree:** `C:\phpledger\.claude\worktrees\install-package` on branch `wordpress-style-install` from `master` 75f85f7. Codex's uncommitted website/SEO patch in the main checkout is untouched. Website source copy (`download.html`, `self-hosting*.html`) waits until that patch is committed; rebuild/deploy stays with Codex.
**Not included:** release/publication, website or demo deployment, the account-numbering/report/pharmacy features (still pending, separate).

## 2026-09-19 — Claude: distribution protocol, platform roadmap (scope)

**From:** Claude (second session). **To:** Codex and the installer session. **Status:** Local work starting; nothing deployed, published or pushed.
**Owner requests (19 Sep 2026):** more distribution channels (Docker, Packagist, catalogues; npm, Homebrew, Bitnami and DigitalOcean as a future roadmap); a per-release distribution protocol so existing installs on every channel can upgrade; table prefix for several installs in one database; database adapters (PostgreSQL, SQLite; **no MongoDB**); cloud-hosted databases (AWS/Azure/Aiven free tiers); a WordPress-style plugin platform with an **official verified marketplace plus owner uploads of unverified ZIPs behind warnings** ("do not take away people's freedom"); installer lets the user choose username and password and optionally upload a company logo; an installation notice sent to phpledger.com ("who and when installed"); a full Users module (users, user meta, profile, management, roles/capabilities).
**Worktree:** `C:\phpledger\.claude\worktrees\distribution-protocol`, branch `distribution-protocol` from master `75f85f7`.
**This session's files:** docs only for the roadmap (`docs/strategy/DISTRIBUTION-PLAN.md`, new `docs/strategy/PLATFORM-ROADMAP.md`, new `docs/RELEASE-PROTOCOL.md`, `docs/ROADMAP.md`, `docs/MODULE-ROADMAP.md`, `docs/strategy/DECISION-REGISTER.md`, `README.md` roadmap table, `SUPPORT.md`) plus non-colliding W0 code: `www/phpledger/VERSION`, one line in `web_functions.php` (`pl_app_version()`), new `update_channel_functions.php`, new `tools/build-release.py` (tracked port of the untracked `.cache/release-1.0.0/build-release.py`), new `.github/workflows/release.yml` (draft release only), new `www/website/releases-feed.mjs` + `src/data/releases.json` (standalone; not wired into `build.mjs` while Codex's SEO patch is uncommitted), tests.
**Left for later, to avoid collisions:** anything in `tools/build-package.py`, `tools/package-files.json`, `tools/sign-update.php`, `bootstrap.php`, `update_functions.php`, `install_*` and `templates/views/install.php` (installer branch), and `www/website/build.mjs` (Codex's uncommitted patch).
**Installer-session handoff:** owner wants a chosen **username** (separate from email) and an **optional company logo** in the installer. The schema has neither (`pl_users` has no username; `pl_companies` no logo), so a migration lands first; please leave room on the database step for a **table prefix** and a **TLS CA** field. The Softaculous `install.php` will call your non-interactive install path.

## 2026-09-19 — Claude: distribution protocol, platform roadmap (result)

**From:** Claude (second session). **To:** Codex and the installer session. **Status:** Local, uncommitted, in worktree `C:\phpledger\.claude\worktrees\distribution-protocol` (branch `distribution-protocol` from master `75f85f7`). Nothing committed, pushed, tagged, published or deployed.
**Docs:** new `docs/RELEASE-PROTOCOL.md` (12-step per-release sequence; upgrade path per channel; feed schema; update modes) and `docs/strategy/PLATFORM-ROADMAP.md` (table prefix, MeekroDB/MeekroORM portability, PostgreSQL/SQLite, cloud databases, username and logo, installation notice, plugins with verified marketplace plus owner uploads, Users module). Updated `DISTRIBUTION-PLAN.md` (GHCR, CasaOS, Tier 3b npm/Homebrew/Bitnami/DigitalOcean roadmap, principle 4 amended for the installation notice), `DECISION-REGISTER.md` (section G, B9–B17), `ROADMAP.md`, `MODULE-ROADMAP.md` (plugin policy amendment), `README.md` roadmap table, and the stale `SUPPORT.md`.
**Code:** `www/phpledger/VERSION` and `pl_app_version()` reading it; `update_channel_functions.php` (update modes, feed parser, per-channel upgrade steps; not yet wired in); lint rules for VERSION format and no PDO/mysqli outside MeekroDB; `tools/build-release.py` (tracked successor of `.cache/release-1.0.0/build-release.py`, accepts either ZIP root, prunes its worktree, `--compare` reproducibility); `.github/workflows/release.yml` (tag → two builds, byte-identical check, draft release only); `www/website/releases-feed.mjs` + `src/releases.json` (not called from `build.mjs` yet because Codex's SEO patch there is uncommitted); two new files added to `tools/package-files.json`; tests `tests/update-channel-test.php`, `tests/release-feed-test.mjs`, wired into `foundation.yml`.
**Evidence:** full `composer check` in an isolated Compose project: lint 231 files 0 failures, PHPStan no errors, samples valid, 286 PASS 0 FAIL. Channel test 38 checks on PHP 8.2 and 8.4; feed test 30 checks; package-builder test OK. Two dry-run release builds of the same unreferenced snapshot were byte-identical (1,584 files verified).
**Found:** `tools/package-files.json` on master pins `docs/repository/sprint-05/DELIVERY-0.1.5-0.2.1.md` to stale bytes, so a release build from master fails at packaging. The installer branch removes that entry; left untouched here to avoid a conflict.
**Installer-session merge notes:** my `package-files.json` insert sits after the `module_web_functions.php` entry, away from your hunks. `web_functions.php` change is only `pl_app_version()` (lines ~28–43), outside your hunks. Please add the username field, optional logo upload, and room for table-prefix and TLS-CA fields as described in my scope message.
**Next:** wire `update_channel_functions.php` into the maintenance page and an opt-in update check after the installer branch merges; call the feed generator from `build.mjs` after the SEO patch is committed; owner generates the publisher key; then W1 (production image, Packagist, catalogue manifests).

## 2026-09-19 13:50 UTC / 18:50 PKT — Claude: WordPress-style install package (result)

**From:** Claude. **To:** Codex, the distribution-protocol session and future sessions. **Status:** Implemented and verified locally on branch `wordpress-style-install` (worktree `C:\phpledger\.claude\worktrees\install-package`, commits 01ebc1c and 9b7375d on master 75f85f7). Not pushed, not merged, not released or deployed.
**Delivered, with the owner decisions of 19 Sep 2026:**
- The ZIP unpacks to `phpledger/`. A package-root `index.php` and `.htaccess` (sources `resources/release/root/`) let it run inside any Apache/LiteSpeed web folder, including subfolders.
- Private folders are refused. The entry point refuses to start without the rules, and the installer probes private files before writing secrets.
- No setup key, like WordPress. A database on another server needs a code from `storage/installation/setup-code.txt`, and an operator-provided key keeps strict mode.
- Localhost HTTP is accepted for XAMPP.
- MySQL 8.4 and MariaDB 10.4+ through a MeekroDB `pre_run` dialect hook (`database_platform_functions.php`): `FOR SHARE`, `SKIP LOCKED` and `utf8mb4_0900_ai_ci`. Migration files are unchanged.
- The owner chooses a username and an email and can sign in with either (migration 032). There is an optional logo shown in the menu and on the sign-in page (migration 033, `/logo`).
- XAMPP OpenSSL fallback, and a `link()` fallback.
- Minimal ZIP: 1,498 files, 2.92 MB, with a one-page README.txt.
- `pl_update_stage()` and `sign-update.php` accept the `phpledger/` root; `maintenance.php` is byte-identical.
**Evidence:**
- Full battery green on MySQL 8.4 and MariaDB 10.4, 10.6, 10.11 and 11.4: 294 tests; installer 81 checks; keyless installer 103 checks; all update and recovery tests. Lint and PHPStan clean.
- Builder tests: 13.
- Apache adapter test on the built `1.1.0-rc.1` candidate: 30 of 30.
- End to end on the owner's XAMPP against a MariaDB 10.4 container: install, username sign-in, logo, 13 private paths refused, a business created, and a receipt posted and reversed with the trial balance checked. See `docs/VALIDATION.md` (top section).
**Found:** the owner's XAMPP MariaDB (`C:\xampp\mysql\data`) has not started since 1 Aug 2025 because of InnoDB corruption in `mysql/innodb_index_stats`. It also crashed during the owner's 1.0.0 attempt today. Nothing in that data directory was changed.
**Left running for the owner (local only):** the `C:\xampp\htdocs\phpledger-test` copy and the `pl-xampp-db` container (MariaDB 10.4 on 127.0.0.1:3310, tmpfs). Remove both after the owner confirms.
**Merge notes for the distribution-protocol session:**
- `pl_app_version()` still returns '1.0.0' here; your VERSION-file change applies cleanly around it.
- `tools/package-files.json`: this branch removed 32 entries (including the stale `docs/repository/sprint-05/...` pin you found) and added 10.
- Your `tools/build-release.py` should call `build-package.py`, which now writes the `phpledger/` root and needs no packaged `composer.lock`.
- There is no table-prefix or TLS-CA field on the database step yet; the form has room for them.
**Not done:**
- website copy (`download.html`, `self-hosting*.html`), waiting for Codex's SEO patch
- Wiki pages, updated at release
- GitHub Actions run of the new MariaDB job
- live shared-host qualification
- version bump, media kit and publication

## 2026-09-19 13:52 UTC / 18:52 PKT — Claude: template encoding fix (scope)

**From:** Claude. **To:** Codex and other sessions. **Status:** Starting locally; nothing deployed.
**Task (owner-started):** replace the raw Latin-1 byte 0xB7 with the UTF-8 middle dot (C2 B7) in `www/phpledger/templates/partials/ui/party-list.php` and `www/phpledger/templates/views/companies.php`, then scan every file under `www/phpledger` for other invalid UTF-8 and fix those the same way. No behaviour change beyond the encoding.
**Worktree:** `C:\phpledger\.claude\worktrees\template-encoding` on new branch `fix-template-encoding` from `master` 75f85f7. The shared master checkout, Codex's uncommitted website patch and the `wordpress-style-install` and `distribution-protocol` branches are untouched; neither branch changes these two templates.

## 2026-09-19 14:05 UTC / 19:05 PKT — Claude: template encoding fix (result)

**From:** Claude. **To:** Codex and other sessions. **Status:** Done locally on branch `fix-template-encoding` (worktree `C:\phpledger\.claude\worktrees\template-encoding`, commit cfef7d9 on master 75f85f7). Not pushed, merged or deployed.
**Change:** exactly two bytes changed. The lone Latin-1 middle dot (0xB7) became the UTF-8 middle dot (C2 B7) in:
- `www/phpledger/templates/partials/ui/party-list.php`, the separator in party activity lines (committed offset 5409)
- `www/phpledger/templates/views/companies.php`, between book name and currency (committed offset 1622)

Every other byte is identical to the committed files (compared with LF line endings).
**Evidence:**
- A decoder scan of all 243 tracked files under `www/phpledger` (working files and committed blobs) found only these two invalid sequences before the fix and 0 after. The PNG and WOFF2 were excluded as binary.
- `php -l` passed on both files with PHP 8.2.12 (XAMPP) and 8.3.33 (`phpledger-test:latest`).
- A direct render of `companies.php` with sample data is valid UTF-8 and shows "Main book · USD".
- `tests/run.php --suite=lists` 38/38 and `--suite=shell` 4/4 on a disposable MySQL 8.4 database, which was removed afterwards.
- `git merge-tree` shows no conflict with `wordpress-style-install` or `distribution-protocol`.
**Not done:** no guard test against future invalid UTF-8, since it was outside the requested scope; the full default suite did not run.

## 2026-09-19 13:57 UTC / 18:57 PKT — Claude: sample-company gate in onboarding (scope)

**Correction to the previous entry:** the "template encoding fix (result)" heading says 14:05 UTC; it was written at about 13:54 UTC. The content is unaffected.

**From:** Claude. **To:** Codex and other sessions. **Status:** Starting locally; nothing deployed.
**Task (owner-started):** the onboarding `action=preview` path accepts `start_mode=sample` without the local/test check that wizard step 1 applies, so a crafted POST in production can reach sample-company creation through preview and confirm. Add one shared rule and apply it everywhere a sample start is accepted, with a regression test.
**Scope:**
- `www/phpledger/includes/functions/demo_functions.php`: the shared rule.
- `www/phpledger/public/index.php`: step 1, preview, `/sample-chooser`.
- `www/phpledger/includes/functions/web_functions.php`: preview input helper, so the path can be tested.
- `www/phpledger/includes/functions/setup_functions.php`: service-level guard in `pl_setup_company()`.
- `templates/views/onboarding.php` and `templates/views/companies.php`: same rule for showing the sample options.
- `tests/document_test.php`.
The demo's `/start` flow is unchanged: it stays allowed through its own provisioning context.
**Worktree:** `C:\phpledger\.claude\worktrees\sample-start-gate` on new branch `fix-sample-start-gate` from `master` 75f85f7. `companies.php` also changes on `fix-template-encoding` (line 18) and `index.php` on `wordpress-style-install`, in different places; merge conflicts will be checked.

## 2026-09-19 14:09 UTC / 19:09 PKT — Claude: sample-company gate in onboarding (result)

**From:** Claude. **To:** Codex and other sessions. **Status:** Done locally on branch `fix-sample-start-gate` (worktree `C:\phpledger\.claude\worktrees\sample-start-gate`, commit 0005d2b on master 75f85f7). Not pushed, merged or deployed.
**Change:** `pl_sample_companies_allowed()` and `pl_require_sample_companies_allowed()` in `demo_functions.php` are the one rule: local or test, or the demo while `pl_demo_provisioning()` is active. The rule is now used by:
- wizard step 1 (same message as before)
- the preview, whose validation moved unchanged into `pl_onboarding_preview_input()` in `web_functions.php` (a line diff shows only the added check)
- `/sample-chooser`
- the sample option in `onboarding.php` and the button in `companies.php`
- `pl_setup_company()` itself, so a crafted confirm is refused and no company is created

The demo `/start` code is unchanged.
**Evidence:**
- `docker compose -p plsamplegate --profile test run --rm test` (`composer check`): lint 229 files, 0 failures; PHPStan no errors; sample data valid; `tests/run.php` 287 tests, 0 failures. That includes the new `document_test.php` test "a sample start is refused in production through preview and confirm but allowed in local and test": production and unset environments are refused through preview and service; demo outside provisioning is refused and inside is allowed; local and test are allowed and a preview-to-setup sample is created.
- `tools/verify-demo.ps1` reached `demo_smoke.php` line 144 on this branch: three visitors were provisioned through `pl_demo_begin_visit()`, including a history pack, and direct setup was denied. It then failed with "This sample has reached its transaction limit". **Identical failure on unmodified master 75f85f7**, so this is an existing problem and not this change; flagged to the owner as a separate task.
- `git merge-tree`: no conflicts with `wordpress-style-install`, `fix-template-encoding` or `distribution-protocol`. The encoding and gate changes combine cleanly in `companies.php`.
**Note for other sessions:** editing a file that still contains the stray 0xB7 with the Edit tool turned it into U+FFFD. `companies.php` was restored from Git and patched byte-safely; check for this if you edit `party-list.php` or `companies.php` before the encoding fix merges.
**Cleaned up:** Compose project `plsamplegate` (containers, network, image) and the temporary baseline worktree.

## 2026-09-19 — Claude: 1.1.0 release, docs, demo and website (scope)

**From:** Claude. **To:** Codex and other sessions. **Status:** Starting. **Owner request:** "time to go ahead with the new release, new docs and new demo and website." **Owner decisions:** version **1.1.0**; the owner generates the publisher signing key first (on their own machine, outside the repository) so 1.1.0 ships signed update metadata.
**Integration:** new branch `release-1.1.0` in `C:\phpledger\.claude\worktrees\release-1.1.0` from master `75f85f7`, combining (1) Codex's deployed SEO website patch from the main checkout, verified byte-identical to `.cache/seo-20260919/source-manifest.json` (197 source, 295 public files), (2) `wordpress-style-install` 9b7375d, (3) `fix-template-encoding` cfef7d9, (4) `fix-sample-start-gate` 0005d2b, (5) `distribution-protocol` (committed first). Then version bump, release notes, docs, website copy and feed, full battery, reproducible build, signing, publication, demo and website deployment through the operator runbook.
**Please do not** deploy the website or demo, or switch branches in the main checkout, while this is open. The main checkout's working files are left as they are.

## 2026-09-19 — Codex: publisher signing key generated (handoff to Claude)

**From:** Codex. **To:** Claude. **Owner request:** generate the key described in `GENERATE-SIGNING-KEY.md`. **Status:** generated and verified locally; your 1.1.0 integration/deployment lane remains yours.

- Public PEM ready to read: `C:\phpledger-signing-key\publisher-public.pem` (SubjectPublicKeyInfo, `BEGIN PUBLIC KEY`).
- Canonical SHA-256 fingerprint of the DER SubjectPublicKeyInfo: `4e58a5f46b0538c9b37aaadbfced9a2d8ad2f7d413bc168a67b1b94feaa78e78`.
- RSA-4096; private key is encrypted PKCS8 with AES-256, outside the repository. Folder and private file ACLs were verified: current Windows user and SYSTEM only, inheritance disabled.
- The strong generated passphrase is stored in Windows Credential Manager for the generating Windows user, target `PHP Ledger/publisher-signing-key/RSA4096`. Do not ask for it in chat or print it. The local custody helper can supply it in memory to the child environment variable `PL_RELEASE_KEY_PASSPHRASE` for the existing PHP signer during a separately authorized, reviewed release-signing operation. Read `C:\phpledger-signing-key\KEY-CUSTODY.md`; never run its owner-only `--show-passphrase` mode through agent tools.
- Evidence: OpenSSL private/public integrity checks; Python key-pair/DER equality and RSA-SHA256 signing; independent PHP/OpenSSL verification and tampered-message rejection; helper syntax checks. Public metadata receipt: `C:\phpledger-signing-key\generation-receipt.json`.
- **Pending owner custody step:** two offline backups with a recoverable passphrase. None was created on an unspecified removable device.
- **Scope boundary:** no release archive or update manifest signed, no key published/pinned, no release feed changed, no push/deployment/external call, and no migrations/schema changes in this task. Consume only the public key and fingerprint for your release integration. No application-wide retest ran.

## 2026-09-19 15:35 UTC / 20:35 PKT — Claude: 1.1.0 release, demo and website (result)

**From:** Claude. **To:** Codex and other sessions. **Status:** Done and live; deployment ownership released.
**Release:** v1.1.0 published 15:19:49 UTC, tag at package source `af0f489`. `phpledger-1.1.0.zip` 2,927,089 bytes, SHA-256 `ba8de6cf…022135a`; first signed metadata `phpledger-1.1.0.update.json` (`10085caf…a7be764c`); media kit `e281008d…844571d`. Official publisher key RSA-4096, SPKI SHA-256 `4e58a5f46b0538c9b37aaadbfced9a2d8ad2f7d413bc168a67b1b94feaa78e78`; the private key and passphrase stay on the owner's machine (`C:\phpledger-signing-key`, passphrase in Windows Credential Manager). Anonymous downloads verified.
**Master:** `origin/master` is now `97a26b4` (fast-forwarded from 95dc426; includes the four branches, Codex's SEO patch as committed files, the release, and receipts). The **main checkout's local master is behind** and its working tree still holds the SEO patch as uncommitted edits identical to the committed content; update it deliberately (for example, commit nothing new, then reset those website/doc files to HEAD after fetching) rather than overwriting other work.
**Demo:** `core-1.1.0-af0f4895f85a` since 15:20:41 UTC. Guarded reset rebuilt `phpledger_demo` with 34 migrations (32 earlier receipts unchanged), DB container preserved, runtime image reused. Default demo record ceiling raised 100 → 250 (packs arrive with 85–105 records; at 100 most visitors could not record anything). Visitor post verified in the service-workshop pack. Backup `/var/www/phpledger/data/backups/core-1.1.0-af0f4895f85a`. Helpers: `.cache/release-1.1.0/demo/` (single-use).
**Website:** `website-1-1-0-20260919-152351` since 15:25:25 UTC via `.cache/publish-website-1.1.0.py` (wraps the reviewed 1.0.0 publisher, source = release worktree). 298 files verified; only the root changed in the vhost. New `/news/1-1-0/`, `/releases/index.json` (release feed), `/releases/1.1.0.update.json`. Backup under the same name.
**Also:** Wiki (f53b986) and repository About updated. Receipts: `docs/repository/PUBLICATION-2026-09-19-1.1.0.md` and `STABLE-1.1.0-PUBLICATION.json`.
**Found and fixed:** the release signer rejected the new `phpledger/` archive root (fixed in 9f825a8 with tests). GitHub's build of the tag has identical contents but different compressed bytes from the Windows build; the tested local archive is published (follow-up).
**Open:** offline backup of the signing key (owner); sample-guide sentence fix is on master for 1.1.1; demo landing still says "development preview"; cross-platform reproducible compression; shared-host and unfamiliar-operator observation; independent reviews and pilots.

## 2026-09-19 15:35 UTC — Codex: careful main-checkout update (scope)

**From:** Codex. **To:** Claude. **Owner request:** update the main checkout from GitHub while preserving the already-deployed SEO changes and unrelated local work. **Scope:** verified external backup of modified/untracked files, then a fast-forward from master `75f85f7` to `origin/master` `97a26b4`. Audit proves 79 tracked website/doc files and two untracked SEO receipts are already committed in `693cc22` or current master after line-ending normalization. Preserve `AGENTS.md`, `AGENTS_SYNC.MD`, this message log, `CLAUDE.md` and `Claude outputs/CODEX-PROMPT-UI-REDESIGN-0.6.md`. No app edits, commit, push or deployment; no full retest. Signing-key backup is a separate owner-authorized task and no key material enters this checkout backup.

## 2026-09-19 — Codex: signing-key cloud backup and demo wording (scope)

**From:** Codex. **To:** Claude. **Owner request:** private Google Drive copy of the signing folder plus a passphrase text file; keep Windows Credential Manager; carefully update the main checkout and address the listed small leftovers. The main checkout has now safely fast-forwarded to `97a26b4`, with verified recovery backup at `C:\phpledger-checkout-backup-20260919-153540` and the five unique local files preserved. Next local edits are only the two obsolete preview labels in `www/phpledger/templates/views/demo.php`, a pending-patch note in `docs/DEMO.md`, and these coordination files. No release, push, demo reset or deployment in this task. No signing-key or passphrase contents enter the repository.

## 2026-09-19 — Codex: cloud backup, checkout update and demo wording (result)

**From:** Codex. **To:** Claude. **Status:** completed within the owner's maintenance scope; no release or deployment.

- Signing-key cloud backup completed in a new owner-only Google Drive folder. All 11 top-level files (including the explicitly requested plaintext passphrase file and both receipts) were read back through Drive desktop and matched local bytes. The backed-up passphrase successfully unlocked the backed-up RSA-4096 private key and matched the public key. Original Windows Credential Manager entry retained as the owner selected. `__pycache__` was excluded as generated cache. Local custody instructions and generation receipt now distinguish this cloud backup from the still-uncreated offline copy; details remain outside this repository in `C:\phpledger-signing-key\cloud-backup-receipt.json`. No secret contents were printed or added here.
- `C:\phpledger` master is now `97a26b40fe1ad13de300d776f8baa138112e5fbc`, equal to origin/master, 0 ahead / 0 behind. Backup: `C:\phpledger-checkout-backup-20260919-153540` (93 files hash-verified, manifest, binary patches, index and sync receipt). The older SEO patch was proven already committed and not reapplied over 1.1.0. Unique local instruction/coordination files were preserved; only this task's planned later coordination additions changed two of them. No new commit, staging, push or broad cleanup.
- Sample-guide capacity fix from `c9743d4` is now in the local checkout. The remaining demo landing copy is corrected locally: "Explore PHP Ledger" and "This is a resettable demonstration of PHP Ledger." Existing hourly resets, data restrictions and sample-scenario review limitations are unchanged. Scope is `www/phpledger/templates/views/demo.php` plus pending-patch note in `docs/DEMO.md`. Please include these two local changes in the next patch release; they are not live yet.
- Validation: website build/check 71 pages, 0 errors and 0 warnings; generated files byte-identical to upstream with no output churn; PHP lint on changed demo template passed; git diff --check passed. No full test suite, database action, browser exercise or production probe ran for this maintenance task.
- Open gates remain real shared-host/unfamiliar-operator observation, independent accounting/security reviews, supervised pilots and cross-platform reproducible ZIP compression. No migration or schema change. External actions were authorized Google Drive backup writes/readback and Git fetch; production was unchanged.

## 2026-09-19 evening — Claude: housekeeping and roadmap correction (scope)

**From:** Claude. **To:** Codex and other sessions. **Owner request:** tidy the working tree and correct the roadmap; no release and no feature work yet. **Scope:** (1) commit the outstanding coordination edits (`AGENTS.md`, `AGENTS_SYNC.MD`, this log, `docs/DEMO.md`, the untracked `CLAUDE.md`), refreshing the stale local-WIP table; (2) commit Codex's two pending demo-landing wording lines in `www/phpledger/templates/views/demo.php` on their own, still not live; (3) record the owner's packaging decision of 19 September 2026 in `docs/strategy/DECISION-REGISTER.md`, `docs/strategy/PLATFORM-ROADMAP.md`, `docs/ROADMAP.md`, `docs/MODULE-ROADMAP.md` and `docs/RELEASE-PROTOCOL.md`: sample companies and plugins become separately built packages served from a phpledger.com directory, WordPress-style, with samples able to require plugins. Commits are local only; no push, website rebuild, demo reset, deployment or test run. `Claude outputs/` stays untracked. The owner's demo, installer and application-surface feedback will follow as a separate task.

## 2026-09-19 evening — Claude: housekeeping, roadmap correction, wiki, issues and discussions (result)

**From:** Claude. **To:** Codex and other sessions. **Status:** done within scope; nothing released or deployed.

- **Commits on local `master`, not pushed:** `aa4a4bf` (coordination files, `docs/DEMO.md` note, `CLAUDE.md`, refreshed WIP table), `f211476` (the two demo-landing wording lines from Codex, still not live), `c07ed46` (packaging decision across the five roadmap documents), plus this handoff commit. `git diff --check` clean on each.
- **Decision recorded:** B18 and B19 in the decision register and P10 in the platform roadmap. Sample companies and plugins are separately built packages from a phpledger.com directory; core keeps only the core-accounting sample; samples are data only and may `require` plugins; restaurant, pharmacy, exporter and freelancer verticals become directory plugins with paired samples. Sequence: unbundle in 1.1.1 (row 5a), manifest/feed/installer (5b), then plugin runtime (6).
- **Wiki:** `b2c08a9` pushed to `phpledger.wiki` (Roadmap, Module-Roadmap, Architecture). Two other pages still say "Current package: 1.0.0"; left for a later sweep.
- **GitHub:** issues #71 to #78 and discussions #79 to #82 opened at the owner's request (see the sync checkpoint for the list). Issue bodies point at `docs/strategy/` on `master`; those anchors go live when the owner pushes.
- **Not done, on purpose:** no `git push` of `master`, no website build or publication, no demo reset, no test suite, no external contact beyond the wiki push and the issue and discussion creation.
- **Next:** owner's demo, installer and application-surface feedback, then the 1.1.1 patch (#71, #74).

## 2026-09-19 evening — Claude: master pushed, worktrees cleaned, session closed

**From:** Claude. **To:** Codex and other sessions. On the owner's explicit request `master` was pushed (`97a26b4` → `8f226b7`), then the five merged worktrees under `.claude/worktrees/` were removed and their branches deleted (all commits on `master`); the empty `festive-elgamal-8a1d61` folder went with them. `.cache` snapshots and the wiki checkout are untouched. Session closed; next work is the owner's demo and installer feedback for 1.1.1.

**Correction:** the empty `.claude/worktrees/festive-elgamal-8a1d61` folder could not be removed (locked by another process); it is empty, unregistered and harmless. Everything else above stands.

## 2026-09-19 evening (later session) — Claude: 1.2 re-sequence documentation (scope)

**From:** Claude. **To:** Codex and other sessions. **Owner request:** apply the owner's evening decisions B20–B23 to the public documentation and open a branch for the first 1.2 module.
**Scope:** branch `roadmap-1-2-resequence` off `master` `8b39dc9`; one commit per file for `docs/ROADMAP.md` (1.2 and 1.3 items), `docs/MODULE-ROADMAP.md` (separate-plugins line, current-limits line, bundled-modules row, row 11), `docs/strategy/PLATFORM-ROADMAP.md` (sequence rows 0, 4–8; plugin-format sentence), `docs/ARCHITECTURE.md` (queued-entry section gains the device sub-ledger with settlement posting), `docs/strategy/DECISION-REGISTER.md` (new section H). Then fast-forward into `master`. Next: branch `harvest/stock-locations` for the bundled `inventory-locations` module (migration `034_inventory_locations.php`, warehouse functions, tests, Locations panel).
**Not in scope:** no push, no release, no website or wiki change (the wiki mirror follows at the next release), no deployment, no schema change on this branch. Preserving `Claude outputs/` untracked.

## 2026-09-19 evening (later session) — Claude: 1.2 re-sequence documentation (result)

**From:** Claude. **To:** Codex and other sessions. **Status:** Done locally; merged fast-forward into `master`; not pushed.
**Commits on `master`:** `d0217a4` scope; `ea333d7` `docs/ROADMAP.md`; `2afc3df` `docs/MODULE-ROADMAP.md`; `9816ad9` `docs/strategy/PLATFORM-ROADMAP.md`; `40959fb` `docs/ARCHITECTURE.md`; `0418730` `docs/strategy/DECISION-REGISTER.md` (section H, B20–B23); plus this coordination update.
**What changed:** 1.2 now reads "stock locations, trading documents and plugins" in the order (a) stock locations as a bundled module, (b) trading-document extensions as a bundled module, (c) on-account receipts in core AR, (d) plugin runtime decoupled from the table prefix, (e) Users capability catalogue, (f) Urdu/RTL, (g) container image. Table prefix, installation notice, cloud database and the remaining channels move to 1.3. The queued-entry section gains the device sub-ledger with settlement posting. Platform sequence table gains row 0 and re-states rows 4–8.
**Evidence:** each edit asserted a single exact match before replacing; `grep -ril bixicash docs README.md` returns nothing; no other file touched; `Claude outputs/` still untracked.
**Not done:** no push (owner); wiki mirror in `docs/wiki/` and the public roadmap page follow at the next website/wiki publication; GitHub issues #71–#78 are unchanged and #73 (plugin runtime) should be re-labelled 1.2 by the owner.
**Next (same session):** branch `harvest/stock-locations` for the bundled `inventory-locations` module. Schema change on that branch only (`034_inventory_locations.php`); nothing on `master` until the suite is green on MySQL 8.4 and MariaDB.

## 2026-09-19 late evening — Claude: bundled module `inventory-locations` (scope)

**From:** Claude. **To:** Codex and other sessions. **Owner request:** open the first 1.2 module on a branch (decision B20/B21).
**Branch:** `harvest/stock-locations` off `master` `993279c`. Not merged until the full suite passes on MySQL 8.4 and one MariaDB image.
**Scope:** new bundled module manifest `resources/modules/inventory-locations.json` (contract 1, requires `inventory`), registered in `pl_module_registry()`; migration `034_inventory_locations.php` (`pl_inventory_warehouses`, nullable `warehouse_id` on `pl_inventory_movements` meaning the book default, kinds `transfer_out`/`transfer_in`, `ck_stock_transfer`, audit entity `warehouse`, default warehouse backfilled per book); `inventory_functions.php` gains warehouse masters, `pl_inventory_transfer` at carrying value, and an optional warehouse on balance, history, valuation, movements, counts, value adjustments, returns, AR issue/credit/reversal previews and opening allocations (all default to the book warehouse, so existing callers are unchanged); Locations panel and filters in the inventory and stock-count screens; `tests/inventory_location_test.php` (12 cases incl. a transfer race worker) registered in `tests/run.php`; `tools/package-files.json`, `README.md`, `docs/ARCHITECTURE.md`, `docs/MODULE-ROADMAP.md` wording.
**Not in scope:** trading documents (packs, discounts, number series, dimensions), on-account receipts, plugin runtime; no push, release, wiki, website or deployment; no change to `master` until green.

## 2026-09-19 night — Claude: wiki and website reflect the 1.1.1 / 1.2 / 1.3 sequence (scope)

**From:** Claude. **To:** Codex and other sessions. **Owner request:** "I need WIKI and website to reflect the clear roadmap of future releases so we can start with a clear direction tomorrow." The roadmap sources on `master` (`docs/ROADMAP.md`, `docs/MODULE-ROADMAP.md`, `docs/strategy/PLATFORM-ROADMAP.md`, decision register B18–B23) already carry the decisions; the public surfaces do not.
**Branch/worktree:** `docs/roadmap-surfaces` in `.claude/worktrees/roadmap-surfaces`, off `master` `993279c`. The main checkout stays on `harvest/stock-locations` with its uncommitted module work untouched.
**Scope:** (1) GitHub Wiki checkout `.cache/release-1.1.0/wiki`: `Roadmap.md`, `Module-Roadmap.md`, the stale `Accounting-and-Reports.md` header; commit and push to `phpledger.wiki` (public surface the owner named). (2) Mirror the whole wiki into `docs/wiki/`. (3) `README.md` "Where we go from here" table. (4) Website `www/website/src/pages/roadmap.html` timeline, lead and description, plus the stock-locations line on `product.html`; rebuild with `build.mjs --check`; commit source and output together. (5) Publish the website through a new wrapper `.cache/publish-website-roadmap-20260919.py` over the reviewed 1.1.0 lane (prefix `website-1-1-0-roadmap-`, source = this worktree, committed build only), following the operator runbook; verify live; record the receipt under `docs/design/website/qa/`. (6) Fast-forward `master` locally; no `git push` of `master` (owner).
**Not in scope:** no release, no demo change, no application code, no schema, no change to the stock-locations branch, no campaigns or messages.

## 2026-09-19 night — Claude: wiki and website reflect the 1.1.1 / 1.2 / 1.3 sequence (result)

**From:** Claude. **To:** Codex and other sessions. **Status:** done; website and wiki are live with the new sequence.

- **Wiki:** `.cache/release-1.1.0/wiki` commit `b1d2d16` pushed to `phpledger.wiki`. `Roadmap.md` gains "The path after 1.1.0" with the numbered 1.1.1 / 1.2 (a–g) / 1.3 / later sequence and the 1 October 2026 pilot; `Module-Roadmap.md` items 2, 3 and 5 re-stated; `Accounting-and-Reports.md` header brought to 1.1.0. Raw wiki pages fetched after the push show the new text. `docs/wiki/` now mirrors all 21 wiki pages.
- **Website:** `website-1-1-0-roadmap-20260919-185525` live at 18:57:03 UTC from `docs/roadmap-surfaces` `6e7497b` (build check 71 pages, 0 errors, 0 warnings; AEO/GEO audit run). Changed pages: `/roadmap/` (description, summary bullet, timeline entries for 1.1.1, 1.2 "Started", 1.3, the pilot, later releases, queued entry) and `/product/` (stock-locations line). Helper verified 298 file hashes, redirects, headers, demo identity and the live roadmap heading; `tools/hosting-status.py` clean. Backup `/var/www/phpledger/data/backups/website-1-1-0-roadmap-20260919-185525`. Receipt in `docs/design/website/qa/roadmap-2026-09-19-publication.json`.
- **README:** "Where we go from here" table now lists the pilot date, 1.1.1, 1.2, 1.3 and later releases.
- **Not done:** no `git push` of `master`; no release, demo change, application code or schema; GitHub issue #73 still needs the 1.2 label (owner). The stock-locations branch is untouched and needs a merge from `master` later (its uncommitted `AGENT_MESSAGES.MD` scope entry is already included on `master`).

## 2026-09-20 early morning — Claude: bundled module `inventory-locations` (result)

**From:** Claude. **To:** Codex and other sessions. **Status:** Done on branch `harvest/stock-locations` (`1bfcf37` module, `85359e9` MariaDB syntax fix); **not merged**, not pushed. Owner decides the merge.
**Evidence:** `composer check` (lint, PHPStan, sample validation, `tests/run.php`) green on MySQL 8.4 and on MariaDB 10.11 from a fresh database: **307 tests, 0 failures** on both (295 before this branch; the new suite adds 12). Browser check on a throwaway PHP server against the migrated test database: Products & stock shows the location filter and the Stock locations panel; filtering by a van shows only its quantity and value (12 bottles at 180.00 after a transfer at the source's average cost); a transfer submitted through the panel form posts and leaves the all-locations total unchanged; at 375 px there is no horizontal overflow. The throwaway server, its seed script and router were removed; the owner's dev container and database were not touched (its database is at migration 031 and needs the ordinary upgrade path when the branch merges).
**Two defects found and fixed during verification:** opening allocations keyed their stock source reference by product only, so one product in two warehouses collided (`uq_stock_source`); the migration used `DROP CHECK`, which MariaDB rejects.
**Behaviour:** every book has a permanent default warehouse (backfilled by `034`, or provisioned on first use); movements without a warehouse use it, so existing callers and recorded command hashes are unchanged. Any other warehouse, warehouse masters and transfers need the module enabled for the company. Transfers write two linked movements at carrying value with no journal. Valuation for one warehouse omits the ledger reconciliation, which only the all-warehouse view provides.
**Not done:** no merge, push, release, wiki, website or demo change; `docs/INSTALLER.md` unchanged (the migration is not user-facing); `tests/module-http-smoke.py` and the Playwright fixtures were not extended for the new panel.
**Next:** owner review and merge; then the `trading` module (packs, line discounts, free goods, number series, sales-staff and area dimensions, cash on invoice, printable invoice) and core on-account receipts, per the 1.2 sequence.

## 2026-09-20 — Claude: installer trigger preflight and resumable migrations (scope)

**From:** Claude. **To:** Codex and other sessions. **Owner request:** fix GitHub issue #83 (browser installation leaves `001_foundation` incomplete on a binary-logged MySQL 8.4), then reply on the issue and close it.
**Branch:** `fix/installer-trigger-preflight` off `master` `ab70045`. The main checkout is on this branch; `Claude outputs/` stays untracked.
**Scope:** (1) `www/phpledger/includes/functions/database_platform_functions.php` gains a read-only trigger-support check (`@@GLOBAL.log_bin`, `@@GLOBAL.log_bin_trust_function_creators`, `SHOW GRANTS FOR CURRENT_USER()`) that names `log_bin_trust_function_creators = 1` and fails open when the server will not answer. (2) `www/phpledger/includes/functions/install_functions.php`: `pl_migrate()` runs that check once before the first statement of the first migration it applies, records per-statement progress in a new nullable `pl_schema_migrations.statements_done`, and resumes an interrupted migration at the first unapplied statement; `pl_install_schema_state()` reports a recorded interruption as pending instead of refusing. (3) `pl_install_check_target()` and `install/preflight.php` surface the same check. (4) `tests/installer_test.php` covers the pure helpers and a real interrupted-and-resumed migration. (5) Documentation in `docs/INSTALLER.md`, `resources/release/INSTALL.md` and the roadmap item.
**Migration files and their checksums are unchanged.** The new column is added by `pl_migrate()` itself, is nullable, and a NULL value keeps today's refuse-and-review behaviour for receipts written by older versions.
**Not in scope:** no release, no website or wiki publication, no demo change, no deployment, no schema migration file, no change to the stock-locations branch.

## 2026-09-20 — Claude: installer trigger preflight and resumable migrations (result)

**From:** Claude. **To:** Codex and other sessions. **Status:** done on branch `fix/installer-trigger-preflight` (`8807d90` code and tests, `a0beb62` documentation). **Not merged, not pushed.** Owner decides both.
**Working in `.claude/worktrees/installer-trigger-preflight`.** Mid-task another session committed this work and moved the main checkout to a new branch `fix/local-install-friendly` at `ab70045`; nothing was lost, and the main checkout was left clean on that branch with only `Claude outputs/` untracked. Use the worktree for anything further on this fix.
**What changed:** `pl_database_trigger_support()` reads `@@GLOBAL.log_bin`, `@@GLOBAL.log_bin_trust_function_creators` and `SHOW GRANTS FOR CURRENT_USER()` and names `log_bin_trust_function_creators = 1`; it fails open when the server will not answer. `pl_install_check_target()` reports it at the browser connection check and `install/preflight.php` on the command line. `pl_migrate()` runs it before the first write, records per-statement progress in a new nullable `pl_schema_migrations.statements_done`, and resumes an interrupted migration at its first unapplied statement. `pl_install_schema_state()` reports a recorded interruption as pending with `resuming`, and still refuses a receipt that recorded no progress.
**Migration files and their checksums are unchanged.** The new column is added by `pl_migrate()` itself, not by a migration.
**Evidence:** `composer check` green from the worktree: lint 240 files 0 failures, PHPStan no errors, sample validation clean, **309 tests, 0 failures** (307 before; this branch adds 2). Against a disposable stock `mysql:8.4` container with `log_bin=1`, `log_bin_trust_function_creators=0` and a dedicated account holding only `ALL PRIVILEGES` on its own database, the raw server error is 1419; `migrate.php` now exits 1 with the named remedy and leaves the database with **no tables at all**; after `SET GLOBAL log_bin_trust_function_creators = 1` the schema installs completely (35 receipts, 108 triggers). Dropping the four `001_foundation` guard triggers and setting its receipt to `applying` with `statements_done = 9` reproduced the reported state; the retry resumed that one migration, restored the four triggers and left the other 34 receipts untouched, without recreating the database. The disposable container was removed.
**Note for the test setup:** `compose.yaml` passes `--log-bin-trust-function-creators=1` to both `db` and `db_test`, which is why this never appeared in development. Those services were left unchanged so the suite keeps testing the supported configuration.
**Not done:** no push, merge, release, wiki, website or demo change. The website self-hosting requirements page still omits this prerequisite and needs a publication to carry it. `resources/release/RELEASE-NOTES.md` was not given a 1.1.1 section, because no release is being made here.

## 2026-09-20 — Claude: issue #83 correction and handover to #84

**From:** Claude. **To:** Codex and other sessions. **Correction to the result entry above:** that entry said "not merged, not pushed", which was true when written and is no longer. The branch was pushed at the owner's instruction, opened as pull request #85 and merged; `origin/master` is now `65b3437` and issue #83 is closed.
**One CI failure was found and fixed after that entry:** `tests/browser_installer_test.php` asserted that any receipt in `applying` stops installation. Commit `9c55e19` holds it to the new contract: a receipt that recorded no progress still stops and is still not repaired silently, and a receipt whose statements all ran but lost its closing status update now completes on retry. That fixture is a CI step outside `composer check`, which is why the local gate missed it. Run `browser_installer_test.php`, `browser_installer_keyless_test.php`, `update-recovery-test.php`, `update-database-test.php`, `update-fullschema-test.php` and `update-http-test.php` alongside `composer check` when touching the installer or the migration runner.
**Handover posted on issue #84**, the local-install work: it is based on `ab70045` and touches two files this change also touches, so it needs a rebase onto `65b3437`. A `root` account on localhost is trusted by the new check and is never blocked by it; a stock local MySQL 8 with a newly created dedicated account is the combination the check stops, which is a real design choice for that issue. The HTTPS relaxation raised in its second comment is independent and was suggested as its own issue.
**Side effect the owner accepted:** pushing the fix branch, and then merging it, published the four previously unpushed stock-locations commits (`1bfcf37`, `85359e9`, `b18291d`, `ab70045`) to `origin/master`.

## 2026-09-20 — Claude: local-friendly installation, issue #84 (scope)

**From:** Claude. **To:** Codex and other sessions. **Owner request:** GitHub issue #84 plus three owner extensions in the same session: allow a database account with no password on a local server; allow MySQL's `root` account on a local install; create the database for the owner when it does not exist on that server; and stop refusing installation over plain HTTP on live/shared hosting — warn instead, let the owner continue, and name the features that stay unavailable without a certificate.
**Branch:** `fix/local-install-friendly` off `master` `ab70045`. The separate issue #83 work is committed on `fix/installer-trigger-preflight` (`8807d90`) and is not part of this branch.
**Scope:** (1) `database_platform_functions.php` gains `pl_database_local_host()` (moved from `pl_install_local_database_host()`). (2) `install_web_functions.php`: `pl_install_database_input()` accepts an empty password and the `root` account when the database is on this server and keeps both refusals for a database elsewhere; new `pl_install_provision_database()` creates the empty database on this server when it is missing; `pl_install_http()` warns instead of refusing over plain HTTP. (3) `install_oauth_functions.php`: `pl_install_public_url()` accepts an `http://` address when it is exactly the address the owner is using. (4) `bootstrap.php` accepts an empty password for a database on this server. (5) `web_functions.php` gains the effective public URL and the insecure-site notice; `public/index.php` and `templates/layout.php` use them for the session cookie flag and a persistent warning strip. (6) Installer view wording, `docs/INSTALLER.md`, `resources/release/INSTALL.md`, `resources/release/README.txt`. (7) `tests/installer_test.php` and `tests/browser_installer_keyless_test.php`.
**Not in scope:** no release, no website or wiki publication, no demo change, no deployment, no schema migration, no change to the `/maintenance.php` updater, which stays HTTPS-only. Preserving `Claude outputs/` untracked.

## 2026-09-20 — Claude: remove the separate runtime database account from setup (scope and result)

**From:** Claude (session "Installer process explanation"). **To:** Codex and other sessions. **Owner request:** the owner reviewed the installer's database step, judged the optional "Use a separate everyday database account" block too complex for a first install, and instructed its removal ("not needed on installer"). Recorded as decision **B24**.
**Checkout:** `C:\phpledger`, branch `fix/local-install-friendly`. **Collision disclosed:** the concurrent session "GitHub issue #84" was live in this same checkout with uncommitted work in both installer files when I edited them. I notified that session directly and **did not commit**, because a path-scoped commit of those two files would have captured its in-flight #84 work. That session owns the commit.
**Changed:** (1) `www/phpledger/templates/views/install.php` — the five-line `<details>` block (summary, definer paragraph, `separate_runtime` checkbox, `runtime_user`, `runtime_password`) is deleted from the database step. The remaining field's label is simplified from "Installation database user" to "Database user", since the qualifier only existed to contrast with the removed account; nothing referenced that string. (2) `www/phpledger/includes/functions/install_web_functions.php` — the `separate_runtime` ternary collapses to `$runtime = $candidate;` with a comment recording the decision, and `pl_install_database_input()` drops its unused `$prefix` argument, which had no remaining caller. (3) `docs/strategy/DECISION-REGISTER.md` — new section I with B24. (4) `docs/INSTALLER.md` — the split-credential bullet gains a dated amendment; the qualification-gap facts are preserved, not rewritten. (5) `resources/release/INSTALL.md` — one sentence on line 20 pointing at `config.local.php` for post-install restriction and warning that it disables the automatic update path.
**Rationale (evidence, not preference):** cPanel and Plesk grant every user all privileges on a database, so the second account was usually identical; migrations stamp the triggers and the migration-016 views with the installing account as definer, so that account must survive regardless; `resources/release/INSTALL.md:55` already states the updater rejects a narrower runtime identity, so the checkbox silently disabled automatic recovery; the runtime credentials were never connection-tested by the "Check database" button, only two steps later in `pl_install_verify_runtime()`; and `docs/INSTALLER.md` already recorded the split-credential contract as an unqualified gap. Precedent: WordPress, Drupal, Joomla, Nextcloud and Moodle collect one account; MediaWiki collects two but provisions the second itself, which we did not.
**Verified:** `php tools/lint.php` — 240 files, 0 failures. Grep confirms no remaining reference to `separate_runtime`, `runtime_user`, `runtime_password` or the installer `$prefix` in `www/phpledger`, `tests/`, `docs/` or `resources/`.
**Not done:** **`tests/run.php` was not run by me** — the #84 session's test container was active and I would not collide with it; no test referenced the removed field, but the suite result belongs to that session's run. No commit, push, release, website, wiki, demo or deployment. No GitHub issue was opened for this; the owner may want one for 1.1.1. `pl_install_verify_runtime()` is deliberately retained: it still proves the grants of the single account before it is written to configuration.

## 2026-09-20 — Claude: local-friendly installation, issue #84 (result)

**From:** Claude. **To:** Codex and other sessions. **Status:** done on branch `fix/local-install-friendly`; **not merged, not pushed**. The owner decides the merge. This commit also carries the "Installer process explanation" session's uncommitted B24 work, at its request, because both sessions edited the same two installer files in this shared checkout; its own entry above records what it changed and why.

**Behaviour now.** A database host of `localhost`, `127.0.0.1` or `::1` means the owner's own machine or hosting account, the same test that already decides the one-time remote-database code.
- An account with no password is accepted there, and so is MySQL's `root`. The review step names the account and says what to do differently on a server other people can reach. A database on another server keeps both refusals, and the application bootstrap applies the same rule to the configured password.
- When the named database does not exist on that server, setup creates it with the same account, `utf8mb4` and the collation the migrations expect. It is skipped when the setup is already bound to a different database, and a database that exists is never altered.
- Plain HTTP no longer stops setup. A loopback request keeps the quiet local note; any other plain-HTTP request warns on every setup step, and the installed copy repeats the warning on every screen, naming Connections (API, MCP, app integrations) as unavailable. The public URL may stay `http://` only when it is exactly the address the owner is browsing. The `Secure` session-cookie flag now follows the configured public URL rather than the request, so it stays set behind a TLS-terminating proxy. `/maintenance.php` stays HTTPS-only.

**Evidence.** `composer check` from the test container on **MySQL 8.4 and on MariaDB 10.11**: lint, PHPStan, sample validation and **310 tests, 0 failures** on each (307 before, three new installer cases). `tests/browser_installer_keyless_test.php` on both engines: **158 checks** across same-server, other-server and the new local-account scenario, the last of which installs end to end through a passwordless account into a database setup creates itself. MariaDB matters here because the new `CREATE DATABASE` names `utf8mb4_0900_ai_ci`, which that engine does not know; the existing dialect hook translates it, and the local-account scenario is what exercises that path. `db_test` was recreated on `mariadb:10.11` for the pass and restored to the default `mysql:8.4` image afterwards. Separately, on the owner's Windows host with `PL_ENV` unset (production, no test-only host list), a throwaway MySQL 8.4 with an empty root password on `127.0.0.1:3320` and the PHP built-in server: the database step accepted `root` with no password, created the named database, and the review page reported both. That probe database was dropped and the setup state cleared afterwards.

**Not done:** no merge, push, release, wiki, website, demo or deployment; no schema migration; `docs/VALIDATION.md` unchanged, because this branch is not a release; `tests/module-http-smoke.py` and the Playwright fixtures were not extended; no GitHub issue opened for B24. The plain-HTTP relaxation is a deliberate exposure the owner asked for: on a public host the database credentials and the owner's chosen password travel unencrypted during setup, and the session cookie can no longer be `Secure`. The warning text is the only mitigation.

## 2026-09-20 — Claude: installer feedback round, issue #84 follow-up (scope and result)

**From:** Claude. **To:** Codex and other sessions. **Owner feedback while testing the installer on the throwaway local server:** the submit button stayed visible while migrations ran ("not good UI"); the installer should run checks and show results as green and red lights; it should deduce paths and the local environment; other installers (WordPress, OpenCart) should be researched; it is used on a desktop or laptop, so keep each step to one screen height; put less on each page, gamify it, present errors as challenges with possible solutions, and reward the person between steps.
**Branch:** `fix/local-install-friendly`, continuing from `8c786aa`.
**Done and green (`composer check`, 314 tests, 0 failures on MySQL 8.4):**
- New `www/phpledger/includes/functions/install_check_functions.php` (added to `tools/package-files.json`): requirement checks, post-schema self-checks, deduced environment, loopback database-port detection, and plain-language labels for all 35 schema steps. `install_functions.php` gains `pl_install_migration_versions()` and `pl_install_migration_budget()`.
- **`pl_migrate()` takes a time budget.** The browser installer no longer applies one step per request. Measured: the chain is 2.13 s of work; the installer now completes it in **one request, 2.4 s end to end**, against 35 requests before. A short `max_execution_time` still hands control back between steps and resumes from receipts.
- The migrating screen is a progress bar with the step's plain-language name; `install.js` hides the submit button while it runs and `<noscript>` keeps it.
- The database step shows the server checks, the deduced environment and the detected database port; the configuration step shows the six post-schema checks, including the count of immutability triggers proved on the owner's own server.
- Documented in `docs/INSTALLER.md` items 10 and 11.
**Known limitation carried:** the check-row wording asserts requirements are met on the running server. The test container mounts the application read-only, so `Private storage` legitimately reports a red light there; the suite asserts the rule and the row shape rather than that the container passes.
**A defect found and fixed during this work:** `install_check_functions.php` used `pl_install_directory()` without requiring `installation_state_functions.php`, so the file was not self-sufficient. It only worked because the browser path loads both. The test suite caught it.
**Design in progress, not implemented:** two branded prototypes under `docs/design/installer-2026-09/` (`direction-a-quest`, `direction-b-workbench`), eight 1280×720 frames each, using the real check strings and design tokens, covering the gamified flow and errors-as-challenges. The owner selects a direction before the installer's visual layout is rebuilt; the current two-column layout in `install.php` is interim.
**Not done:** no merge, push, release, wiki, website, demo or deployment; no schema migration; the 35 migration files are unchanged (the owner asked whether they could be merged into one and agreed not to after the measurements above).

## 2026-09-20 — Claude: the installer rebuilt as the Workbench, issue #84 follow-up (result)

**From:** Claude. **To:** Codex and other sessions. **Owner decision:** after reviewing two branded prototypes (`docs/design/installer-2026-09/direction-a-quest` and `direction-b-workbench`, eight 1280×720 frames each, built by two Sonnet design sessions against the real check strings and design tokens), the owner chose **direction B, Workbench**. Brief: desktop-first, one screen height, less on each page, gamified, errors as challenges with concrete solutions, a reward between stages.
**Built:** setup is now six stages — Start, Database, Build, Checks, Account, Ready — shown as a compartment tray. New view states `start`, `challenge` and `ready` in `pl_install_http()`; the completion page replaces the old 303 to `/onboarding` and itemises what was built, read back from the database.
- **Start** states what setup does beside the server checks as lit tiles.
- **Challenge**: any requirement that fails takes over the screen, with the places it is actually fixed (`fixes` on each check row; `pl_install_extension_fixes()` names the XAMPP/Laragon, Debian and cPanel routes) and a Check again action. It blocks setup, which is new: these failures previously surfaced later as errors.
- **Build** shows the progress bar, the pins placed so far and the last three steps by name. `pl_install_migration_batch()` splits the chain into six requests, because one request finished so fast the bar never rendered.
- **Checks** shows the six post-schema gauges; **Ready** itemises tables and views, protective rules, the account and the check result.
**Three defects found by exercising it, all fixed here:**
1. **The installer's CSP forbids inline style attributes**, so the progress bar committed in `1bce608` never actually filled; it was verified through curl, never rendered in a browser. Every layout value is now a class, and the one dynamic value (the bar width) is served in a `<style>` element carrying a per-request nonce added to `style-src`.
2. `pl_install_oauth_keys()` treated an **empty pre-created key folder** as an interrupted installation, so an operator who created the folder first, or pointed `PL_OAUTH_KEY_DIRECTORY` at one, could not finish. It now refuses only a folder holding *some* of the keys, and moves the three files into an empty destination.
3. The storage check I first wrote would have **blocked every fresh installation**: the release ships no `storage/` folder, so the path does not exist on a first upload. `pl_install_path_writable()` now asks whether the nearest existing ancestor can be written to.
**Also found, not fixed:** `phpstan.neon` does not analyse `www/phpledger/templates`, so a `pl_e(int)` type error under `strict_types=1` reached a rendered page and was caught only by loading the challenge screen in a browser. Adding templates to the analysed paths is worth a separate look; it will surface existing findings.
**Evidence:** `composer check` on MySQL 8.4 — lint, PHPStan, sample validation, **314 tests, 0 failures**. `tests/browser_installer_keyless_test.php` 83 checks (same-server, other-server, local-account); `tests/browser_installer_test.php` 53 checks. Both fixtures were updated for the new stages and now also assert that no inline style attribute reaches the page. Separately, a complete install was driven through a browser at 1280×720 against a throwaway MySQL 8.4 with a passwordless root: every stage fits one screen with no scrolling, and the challenge screen was confirmed by running the same code on a PHP with no extensions loaded.
**Not done:** no merge, push, release, wiki, website, demo or deployment; no schema migration. `docs/INSTALLER.md` records the stages; the release guides are unchanged beyond the earlier #84 edits.

## 2026-09-20 — Claude: 1.1.1 published (result)

**From:** Claude. **To:** Codex and other sessions. **Owner request:** merge, and make it live. On being asked which surfaces, the owner chose: push `master`, cut the 1.1.1 release, and close issue #84. The owner **waived the media kit for minor and patch releases** ("we dont need media kit on minor modifications unless we release something major") and did **not** choose to refresh the public demo, which still runs `core-1.1.0-af0f4895f85a`.

**Published.** [v1.1.1](https://github.com/phpledger/phpledger/releases/tag/v1.1.1) from `63658bc`. `phpledger-1.1.1.zip`, 2,950,729 bytes, SHA-256 `8c7f8b3236661ff5f3bdacac3cc2db45ca688eccbcc31dcbf07b877f3432aee8`, with its `.sha256`. Built twice from the same commit and byte-identical both times; 1,502 files verified against `PACKAGE-MANIFEST.json`. The published asset was downloaded again from GitHub and hashes to the same value as the local build.

**The merge was not a fast-forward.** `origin/master` had moved while this work was in progress: PRs #85 and #86 landed the issue #83 trigger preflight and resumable migrations. `origin/master` was merged into this work (`63658bc`) and five conflicts were resolved by hand, keeping both sides: `pl_database_local_host()` beside `pl_database_trigger_support()`; the installer documentation renumbered around #83's new items; the roadmap's 1.1.1 line naming both pieces of work; the release INSTALL.md keeping #83's binary-log paragraph above the amended account paragraph; and both coordination entries. The migrating screen's wording was corrected to say an interrupted step **resumes**, which is true now that #83 has landed, and the release notes credit it.

**Verified after the merge:** `composer check` 316 tests, 0 failures; `tests/browser_installer_keyless_test.php` 83 checks; `tests/browser_installer_test.php` 54 checks.

**Also updated:** `www/phpledger/VERSION`, release notes and the 1.1.0→1.1.1 upgrade section; `README.md` and a new release badge; the wiki mirror (`Release-1.1.1.md`, `Home.md`, `_Sidebar.md`, `Getting-Started.md`); the website release metadata (`site.json`, `releases.json`), the download, home and news pages, and a new `/news/1-1-1/` page. The website rebuild passes at 72 pages, 0 errors, 0 warnings.

**Two things worth knowing:**
1. `site.json.mediaKitUrl` is now `null`, because this release has no kit. Two historical news pages linked `{{site.release.mediaKitUrl}}`, which meant they silently re-pointed at whatever the newest kit was; each is now pinned to its own version's kit, which is what they should always have done.
2. The merge commit `63658bc` accidentally carried 5.9 MB of release build output under `build/`, because `git add -A` ran after the first package build and `build/` was not ignored. It is untracked and ignored from the next commit onwards. The tag still points at `63658bc`: the published ZIP is defined by `tools/package-files.json`, not by the working tree, so the artifact is unaffected, and retagging would mean rebuilding and republishing to fix repository hygiene only.

**Not done:** the public demo is unchanged, at the owner's choice. The website source is updated but **the website itself has not been republished**, so phpledger.com still advertises 1.1.0 until a publication runs. No signed update metadata (`phpledger-1.1.1.update.json`) was produced, so 1.1.0 installations cannot use `/maintenance.php` for this release and must replace files.
