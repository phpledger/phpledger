# PHP Ledger release notes

Each release's exact source revision is recorded in its `PACKAGE-MANIFEST.json` and in its publication receipt under `docs/repository/`.

PHP Ledger 1.0.0 was the first supported stable release of the restarted application. It preserves the lightweight BixiSoft PHP/MeekroDB structure while separating accounting functions, server permissions, templates and the public front controller. Read "Supported scope and limits" and "Assurance status" below before deployment; the dated preview sections further down record the historical, superseded scope of each earlier development release.

## 1.1.1: setup you can watch, and a database on your own computer

Published 20 September 2026. A patch release: no schema change, no migration, and no change to any accounting behaviour.

- **Setup runs in six stages you can see.** Start, Database, Build, Checks, Account and Ready, each sized for a laptop screen. The Build stage shows a progress bar naming the step it is on, such as "Building the chart of accounts and journals, step 6 of 35", instead of a button that looks like it is waiting for you.
- **A failed requirement is something to clear, not an error page.** A missing PHP extension now takes over the screen with the places it is actually fixed: the `php.ini` line on XAMPP or Laragon, the package name on Debian, the PHP Selector screen on shared hosting, and a Check again button.
- **Setup proves what it built.** Fourteen checks on your server before you type anything, and six against the finished database afterwards, including the count of protective database rules that keep posted entries immutable. The last screen itemises what exists, read back from the database.
- **A database on your own computer needs no preparation** ([issue #84](https://github.com/phpledger/phpledger/issues/84)). On `localhost`, `127.0.0.1` or `::1`, setup accepts the account XAMPP, Laragon and MAMP install, including `root` with no password, and creates the database itself when it does not exist. A database on another server still needs a dedicated account with a password, and is never created.
- **Setup deduces your environment.** It reports the folders it will use and the address it will record, and finds a database server answering on a loopback port to fill in the port.
- **Plain HTTP warns instead of refusing** (owner decision, 20 September 2026). A site without a certificate can be installed and used, with a warning on every setup step and on every screen afterwards naming what stays unavailable. Connections (the API, MCP and app integrations) still require an HTTPS address. Sign-in details travel unencrypted until a certificate is in place, so turn on SSL before keeping real books.
- **The schema applies in six requests instead of 35.** The chain is about two seconds of work; `pl_migrate()` now takes a time budget as well as a batch size, and a host with a short `max_execution_time` still hands control back between steps and resumes from its receipts.
- **One database account, not two.** Browser setup no longer offers an optional second runtime account (owner decision B24, [issue #87](https://github.com/phpledger/phpledger/issues/87)). Restricting the account after installation is documented in [INSTALL.md](INSTALL.md) and turns off the automatic update path.
- **Fixes.** An empty key folder that an operator created in advance, or pointed `PL_OAUTH_KEY_DIRECTORY` at, was treated as an interrupted installation and blocked setup; only a folder holding some of the keys is refused now. The two demo landing labels corrected on 19 September ship here.

Upgrading from 1.1.0 replaces files only. There is no migration in this release.

This release ships without a media kit, at the owner's decision on 20 September 2026: minor and patch releases do not carry one. The assurance limits recorded for 1.1.0 and 1.0.0 below still apply, and a real shared host and an unfamiliar operator have still not been observed.

## 1.1.0: install like WordPress, MariaDB, username and logo

Published 19 September 2026.

- **Install from any web folder.** The package unpacks to a folder named `phpledger/`. Upload it anywhere inside a website, such as `public_html/accounts` or XAMPP's `htdocs`, open its address, and the installer starts by itself. The package-root `index.php` and `.htaccess` send requests to `www/phpledger/public` and refuse every private path. Pointing a domain's document root at `www/phpledger/public` still works and remains the most secure layout.
- **No setup key on a normal install.** The installer opens without a key when the database is on the same server (`localhost`, `127.0.0.1` or `::1`). A remote database host needs a one-time code written to private storage, and an operator-supplied `PL_SETUP_KEY` or `setup.key` keeps the strict mode of 1.0.0.
- **MariaDB.** MariaDB 10.4 or newer works alongside MySQL 8.4. Three MySQL-only spellings (`FOR SHARE`, `SKIP LOCKED` and the `utf8mb4_0900_ai_ci` collation) are translated just before execution. Migration files and their checksums are unchanged.
- **Choose your username.** The first account chooses a username as well as an email and password, and can sign in with either (migration 032). Existing accounts keep signing in by email.
- **Your logo.** An optional installation logo appears in the menu and on the sign-in page (migration 033).
- **Local computers.** Plain `http://localhost` is accepted for trying PHP Ledger on your own computer, with an OpenSSL fallback for XAMPP.
- **Smaller package.** 1,500 files instead of 1,583, with one short `README.txt`. The full install and upgrade guides are online.
- **Signed updates from here on.** This is the first release with official signed update metadata (`phpledger-1.1.0.update.json`). Pin the publisher key listed in the [release signing guide](https://github.com/phpledger/phpledger/blob/master/docs/RELEASE-SIGNING.md#official-publisher-key) to use `/maintenance.php` for later updates.
- **Fixes.** Sample companies can no longer be started in production through the onboarding preview (only local, test and demo provisioning may create them). Two templates that showed a replacement character instead of a middle dot are fixed.
- **Release tooling.** `www/phpledger/VERSION` is the single version source, a reproducible release builder and a draft-only release workflow were added, and the [release protocol](https://github.com/phpledger/phpledger/blob/master/docs/RELEASE-PROTOCOL.md) documents each channel's upgrade path.

Upgrading from 1.0.0 is a manual upgrade, because 1.0.0 shipped without signed update metadata. See [UPGRADE.md](UPGRADE.md#from-100-to-110).

The assurance limits recorded for 1.0.0 below still apply. The installer was additionally checked on MySQL 8.4 and MariaDB 10.4, 10.6, 10.11 and 11.4, in the PHP 8.3 Apache image, and on the owner's XAMPP; a real shared host and an unfamiliar operator have not yet been observed.

## 1.0.0: first stable release

This release consolidates the 0.6.1 workflow closure, 0.7 browser installer and 0.8 signed update/backup/recovery work into one supported stable release, in addition to everything listed under 0.6.0-preview below.

- **Workflow recovery.** Save/post/reverse/source journeys preserve submitted values on a field error instead of discarding the draft, and errors are linked to their field. Report, source, action and return navigation is preserved through nested journeys (for example report → source document → correction action → back to the report). Financial tables and their dynamic rows are keyboard-focusable with unique row identities, so reviewing and correcting entries does not require a mouse.
- **Browser installation.** A new `/install` entry lets an operator set up the application from a browser instead of the command line. It is protected by a private per-installation setup key (`setup.key` under the private installation directory, or `PL_SETUP_KEY`/`PL_INSTALL_DIRECTORY`), proves host ownership before touching the database, applies the existing migration chain, writes private configuration (or offers it as a protected download for hosting-panel placement), provisions OAuth keys, creates the first account and continues into business onboarding. Setup locks after completion and cannot be reopened by deleting a marker from an installed database.
- **Operator-initiated signed updates and recovery.** An independent `public/maintenance.php` entry point, separate from the customer application, lets the installation operator apply publisher-signed releases using a private `operator.key` and a pinned publisher public key (`publisher.pem` or `PL_UPDATE_PUBLIC_KEY`). The operator chooses an explicit stable or preview channel and supplies either an uploaded release ZIP with its signed metadata or requests the official GitHub download. Before applying a release, the updater takes an automatic, complete, matched backup: application code, private configuration, keys and the complete database, including views, triggers and receipt tables. Progress is resumable across host interruptions, and a failed migration or mutation triggers automatic matched restoration of code, configuration and database together, with financial totals and prior migration receipts verified unchanged before the installation reopens.
- **CLI recovery companion.** `tools/resume-update.php` advances a bounded number of pending recovery steps from the shell (`--drain` advances until the operation completes or blocks), for hosts where an operator has terminal access but the browser session was interrupted; final runtime acceptance still requires reopening the authenticated maintenance page.
- **Release and channel tooling.** `tools/build-package.py` and `tools/package-files.json` build the versioned, allowlisted release archive with an authenticated exact-member manifest; `tools/sign-update.php` produces the signed release metadata an operator's update or the official download consumes. Version and channel identities are derived from the semantic version itself (a prerelease identifier such as `-preview` or `-rc.N` means the preview channel; a plain `MAJOR.MINOR.PATCH` such as `1.0.0` means stable) so a package cannot claim a channel its version does not support.

### Supported scope and limits

1.0.0 supports a country-neutral accounting core: chart of accounts, receipts/expenses, general journals with linked reversals, required AR/AP with manually configured tax, optional Purchasing and shared Inventory, opening conversion, bank reconciliation, core financial reports and English-language screens.

1.0.0 does **not** include regional tax certification or e-invoicing, a production-ready shop POS (the bundled cash POS remains an illustrative demonstration), advanced stock (multiple locations, serials/batches/expiry, landed cost), partner/profit-sharing accounting, e-commerce or storefront integration, offline or native clients, or reviewed Urdu/Arabic/RTL screens (planned for 1.1 and 1.2). Each book keeps one immutable functional currency; presentation-currency reporting, period-end FX revaluation and multi-book consolidation remain future work, and the existing FX/settlement restrictions described in UPGRADE.md and INSTALL.md still apply.

### Assurance status

Before this release, the owner ran the full automated test suite, fault-injection tests against the update/recovery path (signature and channel tampering, unsafe paths, interrupted migrations and mutations, dependency loss and recovery resumption), exact-artifact installation/upgrade/recovery checks against a built package archive, and developer-operated browser checks of the installer and workflow journeys at desktop, tablet and phone widths. These checks are technical evidence, not accounting or usability sign-off.

Independent accounting review, independent security review, supervised pilots including a real month-end close, installation observation by an unfamiliar operator, and recovery certification on a restricted shared-hosting account have **not** been performed. The owner is publishing 1.0.0 as the supported production scope with these limits disclosed, rather than waiting for those reviews. Operators who need one of these assurances before deploying should arrange it independently before going live. The updater also currently requires a schema-owning database identity with DDL privileges and matching view/trigger definers; a separate low-privilege runtime identity with temporary update credentials is not implemented, so hosts that only grant a restricted application account cannot yet use automatic updates or recovery.

Exact-artifact results for this package (fresh browser installation from the ZIP, the manual upgrade from the published 0.6.0-preview package, signed-update fault recovery and backup restoration) are recorded in the repository publication receipt `docs/repository/PUBLICATION-2026-09-18-1.0.0.md`, which post-dates this archive. Do not infer those checks from the source-test counts above.

### Upgrade from 0.6.0-preview

The supplied migration chain is unchanged since 0.6.0-preview: it still ends at `031_posting_source_lookup`, with 32 total migration receipts. 1.0.0 adds no new migration. Upgrading is a manual, staged procedure:

1. Back up the complete installed application, private configuration and a consistent database backup (data, views and triggers, including `pl_schema_migrations`), and rehearse restoring that backup into an isolated database before touching the live installation. See UPGRADE.md.
2. Under a maintenance window, replace the application code with the 1.0.0 package while preserving existing private configuration, keys and the database.
3. Run the CLI migration command once (`php www/phpledger/install/preflight.php`, then `php www/phpledger/install/migrate.php`, then `php www/phpledger/install/preflight.php` again); re-running it is a safe no-op once current.
4. Provision the private installation directory and a strong random `operator.key` for update/recovery access, and pin the publisher's independently obtained public key as `publisher.pem` (or `PL_UPDATE_PUBLIC_KEY`). Neither is required to keep operating 1.0.0 without ever using the new updater, but both are required before its first signed update.
5. Verify sign-in, existing totals, account statements and a general-journal post/reversal in an isolated sample company before reopening access.

This procedure does not use the new `/maintenance.php` updater, because a published 0.6.0-preview installation predates it; the first update through `/maintenance.php` is only available after this manual upgrade to 1.0.0. See UPGRADE.md for the complete "From 0.6.0-preview to 1.0.0" section, including exact backup, restoration-rehearsal and verification steps.

Media kit: https://github.com/phpledger/phpledger/releases/download/v1.0.0/phpledger-1.0.0-media-kit.zip

## 0.6.0-preview: interface rebuild

This development preview rebuilds the PHP server-rendered interface with a warm light canvas, navy actions, local Inter fonts, a workspace sidebar, company switcher, compact lists and document forms. Tailwind CSS is compiled during development and included in the download; Node is not needed on the server. The existing CSP, PHP/MeekroDB architecture and central posting service remain in place.

- Home, global search/jump and quick-create navigation; compact account, party, journal, sales, purchase and inventory registers.
- Server-side GET filters, allow-listed sorting and 25/50/100-row pagination replace DataTables. Filter state remains in URLs; the `/tables` JSON API remains available.
- One-line-first document editors, exact live totals, posting previews, journal-editor posting and reviewed reversal/replacement correction previews.
- Stock-count and goods-receipt previews, linked purchase-order/receipt context, and physical-return guidance on supplier documents.
- Receivables/payables ageing, atomic allocation of one settlement across multiple open items, and FX gain/loss fields required only when applicable.
- Cost-of-sales classification, gross profit and P&L period presets. Existing unclassified charts preserve their prior net-profit calculations.
- Module/connection confirmations, server-enforced connection scope choices, account consequence guidance, and POS cashier identity.
- Existing tax-code definitions remain read-only; dated-rate changes remain available.

### Preview limitations and verification scope

Desktop/tablet are the design targets; mobile refinement and dark mode are deferred. Complete prototype-state visual acceptance, keyboard/zoom/accessibility review, consistent field-level validation recovery and the full nested report/source/action return journey remain unfinished. These are disclosed preview limitations, not completed acceptance claims. No accounting sign-off, WCAG certification, country compliance or production-readiness claim is made.

The recorded source check passed 282 tests with zero failures, PHP lint and PHPStan. A refreshed 33-state browser sweep produced 132 captures; this does not establish acceptance of every HTML route or all 75 prototype states. Valid local OAuth consent/cancel passed with JavaScript on/off. Consult the publication receipt for exact archive/runtime/upgrade and hosted verification; do not infer those checks from the source-test count.

Upgrade from 0.5.0-preview uses migrations 029-031. Back up and rehearse restoration first; preserve earlier migration files/checksums and run the included migration command once while writes are stopped. Re-running migrations must be a no-op. Never copy historical legacy SQL into the modern schema. See UPGRADE.md.

Media kit: https://github.com/phpledger/phpledger/releases/download/v0.6.0-preview/phpledger-0.6.0-preview-media-kit.zip

## 0.5.0-preview: eleven isolated sample companies and shared UX candidate

The release candidate extends the 0.4.0 accounting starter with eleven versioned, sample companies: Cedar Studio, Sunrise Garden Services, Willow Corner Shop, Harbour Trade, Harbor Supply Company, Cedar Table, Riverside Community Club, Meadow Training Pharmacy, Lantern Finch Jewelry Studio, Maple Bench Works and Wheel & Spoke Workshop.

- Each selected historical pack contains fixed 2024-2025 bookkeeping history, an open 2026 practice year, 74 source/draft records, 36 month-end checkpoints, durable source references and a pinned SHA-256 digest.
- The public chooser provisions only the company selected by the visitor. Existing isolation, hourly reset, capacity limits, CSRF/session boundaries and the existing posting/reporting services remain in force.
- Pack metadata states sample provenance, demo-only release status and unsupported vertical boundaries. Pharmacy is non-medicinal training inventory only; workshop customer-owned property remains separate from stock; restaurant, club, jewelry and manufacturing operations are teaching scenarios, not compliance or operational modules.
- Successor fixtures retain the authored or generated sample research contract in pinned JSON, including business profile, contacts, products or locations, opening evidence, operational event identities, expected reports and scenario acceptance checks. Isolated sample provisioning now replays supported operational events through the existing AR/AP, Purchasing, Inventory and general-journal services, while future vertical records remain visibly staged as research evidence.
- Chart installations and sample-import receipts are now recorded in append-only installation history. The original chart snapshot is never overwritten when a sample pack is attached; the guide reads the immutable, company/book-scoped sample receipt and rejects later changes.
- The new `resources/coa/industry-profiles-0.5.0.json` catalogue maps all eleven samples to research-backed account vocabulary and role labels. It distinguishes, for example, food versus beverage, labor versus parts, raw material versus WIP versus finished goods, and earned versus unearned dues. Illustrative codes are not statutory numbers, and unsupported vertical controls remain outside the sample runtime.
- Domestic customer receipts and supplier payments no longer require unused realised-FX accounts. The settlement service still requires a scoped gain/loss account when a genuine exchange difference is posted, and retains the existing foreign-bank and carrying-value safeguards.
- No country chart is promoted by this candidate. Country research remains unavailable for real-company installation pending package validation and release criteria.

This is a local release candidate. Hosted publication, observed usability, accessibility sign-off, capacity evidence and production readiness remain open gates. No live system, provider, payment, message or webhook action is performed by sample provisioning.

## 0.4.0-preview: accounting starter

AR and AP are now included in the base accounting core, with separate internal module ownership. Purchasing and shared Inventory are bundled optional modules. Core tax configuration is country-neutral and owner-managed.

- Browser routes: `/ar`, `/ap`, `/parties`, `/purchasing`, `/inventory`, `/opening-conversion`, `/tax`, plus existing `/modules` display and activation settings.
- Invoice/bill drafts, explicit posting review, exact partial/final payments, linked customer/supplier credits, immutable same-identity corrections and historical ageing use the shared open-item ledger and posting service.
- Purchasing links orders, partial receipts and later AP bills. Receipt matching and reviewed price/rate variance preserve the received stock basis. Physical returns and financial credits remain separately linked records.
- Inventory provides one location, products, immutable movements, moving weighted-average cost, counts, reviewed valuation adjustments and stock-to-ledger reconciliation. Stock invoices and customer credits share these services.
- Tax codes and dated rate revisions are manually configured. Entry may include or exclude tax; documents show net/tax/total and retain their saved mode. Tax changes require draft re-review and never rewrite posted snapshots. Credits use their original document's tax basis. No country defaults or filing capability is asserted.
- Opening debt and stock conversions require explicit mapping and exact reconciliation to existing opening GL amounts. Conversion does not create another opening journal. Converted opening bases cannot be reversed independently.
- AR/AP navigation may be hidden. Required services, permissions and financial totals are unaffected. Optional module disabling preserves historical access.
- The hosted demo adds a separate Accounting starter playground with sample parties, a stock product and illustrative tax configuration. Its four historical examples remain available. Demo administration remains restricted; removing lines from an existing demo invoice/order draft requires starting a new draft.
- Quotes are excluded and preserved on a separate plugin branch. Advanced stock features, LC flows, tax country packs, forms and e-filing remain outside this build.

New migrations are additive to 0.3.0. Review the [starter record](https://github.com/phpledger/phpledger/blob/master/docs/repository/sprint-06/ACCOUNTING-STARTER.md) for exact migration and test evidence. Use a matched database/code backup and stop application/worker traffic for an upgrade. Package and hosted publication remain separate release gates.

## Historical 0.3.0-preview: accounting foundations before AR/AP

- Keeps the existing accounting screens and read API/MCP while adding currency, party and correction service foundations. No invoice/bill documents or AR/AP screens are introduced.
- Every journal line stores transaction currency, exact foreign/base amounts, its frozen rate/type/source, stale-rate flag and optional intercompany reference. Domestic history receives equivalent currency snapshots without changing original monetary fields or request hashes. BCMath uses four-place amounts and twelve-place rates with explicit half-up conversion; unbalanced journals are rejected rather than silently adjusted.
- Each company/book has an immutable functional currency and reserved presentation/group metadata. Accounts carry currency designation, monetary classification and nullable revaluation/group references. Unknown legacy monetary classification remains unknown; no revaluation or consolidation runs are included.
- Manual rates have append-only revisions and correction links. Lookups select a specified source on or before the posting date; no provider, interpolation or future-rate selection is enabled. Actual settlement input overrides the selected rate.
- Country-neutral party/contact storage supports customer/vendor roles on one party, scoped tax/national-identity duplicates and explicit shared-phone acknowledgement. Vetting states and immutable transition-log storage are reserved; enforcement, transition workflow, bank-change approval, uploads and tag assignment are deferred.
- The internal open-item ledger references actual immutable journal lines as its sole balance source. Unused, currency-neutral controls can be explicitly activated. Partial settlements allocate the remaining carrying value, the final allocation closes it exactly, and realised gain/loss posts with the settlement. Outgoing foreign-currency-bank settlement and out-of-order open-item events are rejected; legacy aggregate balances are not automatically adopted.
- Receipt/expense and general-journal corrections preserve document identity, reverse and repost atomically, and retain original source snapshots. Effective-source views keep lists, reports and read integrations consistent. The default reversal date is the current UTC cancellation date; an owner with a reason may use the original posting date while the period remains open. POS and opening sources retain their specialized correction paths.
- The outbound queue provides scoped idempotency, leases, retries and a dispatch contract. No A75 connector, external receiver, automatic message sending or cron installation is included.
- The complete schema has 17 receipts, 56 tables, two effective-source views and 62 guard triggers. Migrations 013-016 require stopped application/worker traffic and a recoverable matched backup; follow [UPGRADE.md](UPGRADE.md), including stable view-definer requirements.

These are backend foundations. Public API/MCP financial mutations, invoice/bill workflows, aging, FX providers, period-end revaluation, group consolidation, commodities and regional tax adapters remain future work. Existing screens do not expose the new internal commands. Release/package/host acceptance is recorded separately; these notes do not establish that a customer installation has passed validation.

## Earlier 0.2.1-preview combined release

- Retains company/book permissions, chart of accounts, receipt/expense and general-journal drafts, balanced posting, linked reversals, opening balances with an unpaid-document register, period controls, bank CSV reconciliation, financial reports, running account balances and CSV exports.
- Adds four original sample companies: service agency, retail shop, seasonal business and distributor. Each has 74 source records, three editable drafts, closed 2024–2025 history, open 2026 practice and 36 reconciled month-end checkpoints. Private guides explain daily, monthly and quarterly/yearly reporting, with separate public illustrated walkthroughs.
- Ships read API/MCP, Connections/OAuth and server-side tables described below. Compatibility claims apply only to client versions actually tested; other named clients remain pending.
- Thirteen migration receipts, 34 tables and 35 accounting guards. `012_demo_history_periods` replaces two demo period guards while web/scheduler services are stopped; it permits guarded sample preparation before assignment and continues to reject visitor period administration.
- Manual support schedules explain payroll, stock and unpaid items; full payroll, inventory and AR/AP modules remain future work. Regional connectors, including Pakistan FBR, remain planned.

## Read integration changes

- Scoped read-only `/api/v1/` and native `/mcp` Streamable HTTP, using the same financial services as browser reports; standalone PHP STDIO-to-HTTPS bridge with no database credentials.
- Existing-session Connections and OAuth consent, personal tokens shown once/stored hashed, S256 authorization code, exact redirect/resource checks, refresh rotation, durable revocation and demo generation/reset expiry. Seven additive tables in `011_read_connections`; twelve migration identities and the existing 35 accounting guards.
- Locally bundled DataTables 3.0.4 for transactions, general journals, account movements and bank rows. Running balances precede filtering/sorting/paging; original views and CSV exports remain available.
- Pinned MCP/OAuth dependencies and credential-free versioned client recipes, a disabled n8n native-MCP workflow and independent OpenAPI description.
- This candidate is not a verified compatibility claim for Codex, Claude, ChatGPT, n8n, OpenClaw, Hermes Agent, Open WebUI or llm.bixisoft.com. Their separate application acceptance matrix remains open in `docs/INTEGRATIONS.md`; untested client entries remain pending under the owner-approved preview policy. Multi-year demo packs are included in this combined release.

## 0.1.6-preview changes

- PHP 8.2 minimum, PHP 8.3 recommended/default deployment; compatible dependency resolution, accurate runtime/preflight checks and an 8.2/8.3/8.4 CI matrix.
- AGPL-3.0-or-later core licensing, commercial licence available, individual/entity CLA and a pinned CLA Assistant workflow. The pre-adoption 0.1.0 through 0.1.5 releases retain MIT; third-party terms remain unchanged.
- Country-neutral product positioning: Pakistan is one intended regional direction and FBR is one planned connector, not the definition of the core or a global launch requirement.
- Owner-authored accessible POS labels preserve the contributor/revert history. The eleven migrations and accounting schema are unchanged. No API/MCP transport, new connector or richer demo pack is included in this maintenance package.

## Earlier 0.1.5-preview changes

- Product-specific accessible names for the no-JavaScript POS quantity inputs, contributed by [Nagulanvelu in PR #65](https://github.com/phpledger/phpledger/pull/65).
- A maintainer follow-up preserves the final newline and prevents product cards from clipping the fallback quantity fields at desktop, tablet and mobile widths.
- The accounting services, migration chain and stored amounts retain the 0.1.4-preview behavior. The website adds About, Privacy, demo-use terms and product FAQs; website content is deployed separately and is not part of this ZIP.
- API/MCP read access and richer multi-year samples are subsequent releases with separate acceptance gates.

## Earlier 0.1.3-preview changes

- Consolidates the released accounts/general-journals slice with reviewed opening/CSV cutover, reasoned period administration and bank reconciliation/cancellation.
- Adds CSV exports for trial balance, account statements, profit and loss and balance sheet. Amounts remain exact, scope/dates/readiness are explicit, spreadsheet-formula text is escaped, and account exports reject more than 10,000 movements.
- Adds the bundled core/POS manifest contract and owner-only **Modules** screen. Optional POS defaults off for ordinary new/upgraded companies. Explicit sample provisioning enables it. Service checks block new review/checkout/retry after disablement; old sources and receipts remain available.
- `010_module_lifecycle` adds two tables and two immutable-audit triggers. The complete supplied chain has eleven migration identities and 35 guard triggers. Both original `006_*` files retain their original checksums; numeric prefixes alone are not migration identities.
- Repairs UTF-8 handling in the repository's Windows restore verifier. Technical completion does not establish accounting/pilot certification.

## Earlier 0.1.2-preview changes

- Account statements show opening balance, period debits/credits, running balance and closing balance for all five account classes.
- The chart supports new accounts and audited name/status changes with stable account IDs. Existing account code, type and role remain fixed; changing classification needs a reviewed mapping/correction process that this preview does not supply.
- General journals have saved drafts, review before posting, exact balanced posting and linked dated reversals. Source records and audit history retain the connection to posted entries; posted entries remain immutable.
- Migration `006` adds two tables, account revision/creation metadata and four triggers. The full installation has six migrations and thirteen triggers. Existing migration files and production dependencies are retained.
- Eight country tax research catalogs are packaged as disabled, unreviewed candidates, with an optional standalone structural validator.

The 0.1.1-preview cash POS interactions remain included: click-to-add products, retained validation inputs and separate cart review/cash confirmation.

## Retained core workflows

- PHP 8.2+ (8.3 recommended), MySQL 8.4, pinned production dependencies and a versioned migration runner.
- Command-line prerequisite checks, schema installation and initial-user creation; private database configuration and HTTPS sessions.
- Company setup with an account template, new-business readiness rules and opt-in sample data.
- Company/book-scoped receipts and expenses, drafts, atomic balanced posting, fixed-precision amounts, duplicate protection, linked sources, period locking and reversals.
- Company/book-scoped chart management, general-journal drafts and audit history, plus statements for asset, liability, equity, income and expense accounts.
- Owner overview, trial balance, balance sheet, profit and loss, and an editable cash scenario. A scenario reflects entered assumptions; it is not a prediction or a statement of cash flows.
- An illustrative cash POS with click-to-add product tiles, cart plus/minus controls, server-priced review, separate cash confirmation, exact amount/change, receipt and linked accounting entry.
- Opening balance entry/CSV preview and confirmation, an unpaid-document register reconciled to AR/AP control balances, and owner correction before business activity.
- Period creation, reasoned close/reopen actions and immutable audit receipts; bank CSV import, explicit matching, outstanding entries and protected completed reconciliations.
- English screens, supported base-currency choices, regional date/number formatting and local terminal timezone display with UTC event storage.

## Current starter limits

The reports are country-neutral management views. Accounting/reporting research does not provide statutory presentation compliance, tax certification or filing support. Use this preview within its documented management-accounting scope.

The existing cash POS is a demonstration with its own illustrative sample catalogue. It does not deduct stock from shared Inventory or apply the new document tax engine. Stock quantities, valuation and cost of goods sold are available through the starter's Inventory and stock-invoice flows; extending the POS to use them, payment-provider capture, hardware integrations and complete retail/restaurant workflows remain deferred.

Each book has one immutable functional currency. The posting foundation now supports transaction-currency snapshots and internal realised settlement FX; presentation-currency reporting, period-end revaluation, bank carrying-value realization, consolidation and a completed multi-book model remain deferred. English remains the application language; country metadata does not provide translated screens or localized accounting rules.

Detailed historical journal imports, XLSX, country tax adapters, offline operation and receipt/document scanning remain future work. The local starter now supplies invoice collection, bill settlement, linked credit notes, ageing and inventory reports described above; those capabilities were absent from the historical published 0.3.0 package. Existing opening AR/AP rows remain immutable source evidence until the explicit reviewed conversion maps them into operational open items. Opening credits, advances, unapplied credits and refunds remain deferred. Existing-business setup requires confirmed opening balances; ordinary new transactions must be dated after cutover, and unresolved earlier bank items require explicit review before a reconciliation baseline.

The `resources/tax/` catalogs cover Pakistan, the UK, UAE, Malaysia, Bangladesh, Sri Lanka, Nepal and Singapore, with classification questions for restaurants, membership clubs, pharmacies, traders, distributors, retail shops and workshops. Every candidate remains `research_only` and `enabled: false`. Product/service, registration, jurisdiction, effective-period and recovery conditions are not enabled by an industry name; an industry name never selects a universal rate. Null rates mean unresolved or non-flat treatment, not zero tax. No catalog is imported into company settings or used by POS. The starter's manually configured core tax codes, dated rates and account mappings are separate from these packaged references; no country-pack activation, statutory filing or tax-specific API/MCP interface is included.

Optional `php tools/validate-tax-catalog.php --self-test` validates local catalog schema, disabled states and references without database access. It neither checks legal accuracy nor approves sources, rates or dates. Core document tax snapshots and input/output account mappings are implemented for manually configured rates; country-specific tax profiles, withholding, nonrecoverable/partial-recovery policies, compound taxes and filing rules remain future work.

The selected UI is a working preview. The click-to-add POS direction is owner-approved; representative cashier sessions and country-specific report review remain pending. Passing technical checks does not establish observed usability success or a completed security review.

## Installation and compatibility

Use [INSTALL.md](INSTALL.md) for the current CLI installation. Only `www/phpledger/public` may be served. Dependencies and the complete versioned migration chain are included; the historical root application, development tools, marketing website and hosted-demo scheduler are excluded.

The package has no automatic upgrade from the historical PHP Ledger database. A recognized prior modern installation retains its applied migration identities/checksums and applies only the remaining supplied chain, including both distinct `006_*` files as needed. Use the documented backup, preflight and controlled replacement procedure. MySQL schema changes are not rolled back as one application transaction. See [UPGRADE.md](UPGRADE.md) for maintenance and restoration requirements. Keep release test results and deployment-specific validation records separate from these feature notes; this document does not claim that your host or data has passed acceptance.

## Feedback and next steps

Use the [issue tracker](https://github.com/phpledger/phpledger/issues) for reproducible sample examples and the [public documentation](https://github.com/phpledger/phpledger/wiki) for current scope and roadmap. The [hosted demo](https://phpledger.com/demo/) is temporary and resets hourly. Do not submit real business data, credentials or private database exports with feedback.

### Earlier 0.2.1 client transport corrections

The STDIO bridge preserves JSON capability objects exactly. Concurrent demo admissions wait up to two seconds, with origin-approved CORS and retry headers on busy responses. That release identified itself as 0.2.1-preview in API/OpenAPI and MCP metadata. The current package version and source are recorded in its manifest; protocol compatibility and application version are separate.
