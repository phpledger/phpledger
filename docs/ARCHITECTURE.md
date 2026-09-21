# Architecture

## Stable delivery boundary adopted 18 September 2026

The [1.0 release checklist](ROADMAP.md#current-delivery-contract-first-stable-10) controls current sequencing. English-first core accounting, required AR/AP, manual tax and optional basic Purchasing/Inventory form the supported target. Browser installation and operator-initiated updates with automatic matched backups/recovery are required before stable. Urdu/RTL is 1.2 and Arabic/RTL is 1.3 (owner decision B20); regional connectors and production retail retain independent later gates.

Installation authority belongs to a host-controlled operator, not an owner of one company. Setup and update entry points use private state, ownership proof, CSRF and locking before accessing the normal application. Existing CLI entry points retain CLI guards and share internal migration/preflight services. Signed update metadata uses RSA-SHA256 over exact payload bytes and a separately configured trusted publisher public key; package-provided keys cannot establish trust. Stable versus preview channel is bound to the semantic version. Publisher private keys never enter packages.

Update recovery is installation-wide: block/drain writes, preserve matched code/configuration/OAuth keys and the complete schema/data/views/triggers/receipts, then verify a new version or restore the matched backup. Recovery state must remain independent of newly installed application code. Interrupted infrastructure resumes safely when available; maintenance remains active until successful verification. MySQL DDL is not a rollbackable application transaction. A requirements-based hosting claim and representative restricted-host recovery evidence are separate from ordinary VPS operation. These contracts do not by themselves close implementation, independent review or production gates.

## 1.0.0 status

The published 1.0.0 UI remains PHP server-rendered with MeekroDB and the existing router/bootstrap/posting funnel. Compiled Tailwind CSS is shipped; no Node runtime or DataTables dependency is deployed. GET lists use page/per_page/q/sort/dir, fixed sort allow-lists and SQL LIMIT/OFFSET. The /tables JSON endpoint remains. Migration chain additions are 029-031; immutable correction previews share the accounting planner. 1.0.0 adds the browser installer and the signed update/maintenance path described in [Installer](INSTALLER.md) and [Development](DEVELOPMENT.md).

## Accounting starter: published 0.4.0-preview (16 September 2026)

The starter is published as **0.4.0-preview**, with separate service modules and a verified hosted playground. The [publication receipt](repository/sprint-06/PREVIEW-0.4.0-PUBLICATION.json) records the package, upgrade and live checks. Foundation descriptions below retain their historical release boundaries.

| Capability | Package and service boundary |
|---|---|
| AR and AP | Required modules bundled with the accounting core. Invoices, bills, partial settlements, linked credits, ageing and control-account reconciliation use the existing party identity, posting funnel and authoritative open-item ledger. From 1.2, each document type carries its own number series (`INV-2026-000123`), allocated on posting inside the posting transaction under the book lock and recorded immutably; drafts are unnumbered, the prefix, padding, year segment and reset rule are set per company and book in Admin › Numbering, the next number may only be raised, there is no per-warehouse series (B37, B55), and documents posted before migration 035 keep their derived `INV-<id>` number. From 1.2 M3 an AR document also carries its trading shape (migration 037, neutral defaults): sales-staff, area and warehouse dimensions, cash taken on the invoice with its account and settlement journal, and per line a pack with its pack/unit quantities, a gross amount, a discount percentage with the amount stored, and a free-goods flag. The line CHECK was replaced so a flagged free-goods line may be zero-valued while every other line keeps its positive rule. Every field is optional: a document posted before 037 carries none of them and never will, and a line's zero gross is read as "gross equals net". |
| Tax calculation | Shared core service with manually configured codes, dated rate versions, document choice of inclusive or exclusive prices, and immutable calculated line snapshots. Country rules, official forms, fiscal devices and e-filing belong to future adapters. |
| Inventory | Shared optional service for the product catalogue, a permanent default stock location per book, immutable movements, exact moving weighted-average costing, source-linked returns, reasoned counts/value adjustments and valuation-to-ledger reconciliation. |
| Stock locations | Bundled optional module depending on Inventory, shipped (unannounced) in 1.1.1 and documented in 1.2 ([wiki](wiki/Stock-Locations.md)). Adds further warehouses and vans, per-location balance/history/valuation/counts, and transfers between locations at carrying value with no journal entry. Returns stay pinned to their original warehouse. Disabling it leaves existing non-default-warehouse stock readable but blocks new warehouse operations. |
| Trading documents | Bundled optional module depending on the core, AR and Inventory (1.2 M3). Adds packs, line discounts, free goods, sales-staff and area dimensions, warehouse selection and cash on the invoice to the customer/vendor document shape, plus the B37 accounting policies, the B64 company profile and the A4 invoice, 80 mm counter receipt and party statement prints. Because a bundled module cannot extend AR from outside, its migration adds neutral-default columns to AR's own tables, as 034 did for movements. Disabling it leaves every posted document, dimension and print readable and blocks new trading entry. |
| Stock locations | Bundled optional module depending on Inventory, shipped (unannounced) in 1.1.1 and documented in 1.2 ([wiki](wiki/Stock-Locations.md)). Adds further warehouses and vans, per-location balance/history/valuation/counts, and transfers between locations at carrying value with no journal entry. Returns stay pinned to their original warehouse. Disabling it leaves existing non-default-warehouse stock readable but blocks new warehouse operations. From 1.2 (migration 037) a location has a `kind`: a van is a `mobile` location with a driver, a vehicle and a route, and the book's default warehouse is always `fixed`. The same movements now carry a numbered stock-document layer — stock issue, re-issue and return from a van, each on its own series from the 035 machinery — plus gate passes, which are numbered permission records that move no stock and post nothing. A van's day is reviewed as a settlement sheet built from the movements, approved under a permission distinct from ordinary posting rights, and posts nothing itself. Stock by location, van stock and the across-locations aggregate are reports; cost columns follow a separate viewing permission. |
| Purchasing | Bundled optional module depending on AP and Inventory. Owns orders, partial goods receipts, later receipt-to-bill matching, outstanding quantities, received-but-unbilled reconciliation and coordinated purchase returns. |
| Verticals | Future route, delivery and collection workflows call these shared services. A distributor owns operational handoffs; AR owns customer debt and collections, AP owns supplier debt, and Inventory owns stock. |

AR/AP company preferences hide navigation and dashboard panels only. They do not disable authorised services, remove financial balances from reports or change permissions. Optional Inventory/Purchasing enablement still controls new operations; authorised historical reads survive disablement. The existing manifest registry, bootstrap, MeekroDB layer and company/book permissions remain the only application foundation. Quotes are excluded from the starter and reserved for a separate plugin.

Browser adapters at `/ar`, `/ap`, `/parties`, `/inventory`, `/purchasing`, `/opening-conversion`, `/tax`, `/stock-documents` (with `/stock-documents/detail` and `/stock-documents/settlement`) and `/reports/stock-by-location` call the same typed internal services. No new HTTP/MCP financial write transport is added. Drafts do not post. Recognition, settlements, credits, source revisions, stock movement journals and operational allocations commit atomically under the existing book lock. Money and quantities use decimal strings and BCMath; idempotency receipts, expected revisions, closed periods and company access are checked in the services.

Posting a stock invoice also issues its goods and posts cost of goods sold. A linked customer return restores the original issue basis; credit reversal removes that return before the invoice can be corrected. The central posting funnel rejects standalone financial reversals that would detach stock or purchasing history. Same-identity correction retains the original document and appends reversal/replacement history. Purchase orders have no financial effect; goods receipts debit Inventory and credit received-but-unbilled clearing, and the later AP bill relieves that clearing. Price, rate and allocation-rounding differences require an explicit reviewed variance account instead of rewriting receipt costs.

Inventory derives quantity and carrying value from immutable movement rows. Issues allocate the current carrying value proportionally and the final issue consumes its exact residual. Negative stock, negative carrying value, nonzero value with zero quantity and posting before an item's latest movement are rejected. Purchase returns use their original receipt basis; after intervening sales or different-cost receipts, a return can require an explicit reviewed value adjustment. That adjustment rechecks both expected quantity and value under the book lock. Inventory values are in the functional currency; Purchasing and AR/AP retain their own transaction-currency rate snapshots.

Opening-debt conversion explicitly maps parties and links open items to the existing opening journal basis. Opening-stock conversion explicitly maps product quantities and values to existing opening lines and must cover every already-mapped inventory account with a balance. Neither conversion posts the opening balance again. Converted openings cannot be independently restarted through the old opening-reversal route. Ageing and financial reconciliation use a selected accounting date; bank matching retains the existing exact one-statement-row-to-one-journal-line workflow.

Migrations `017_ar_ap_documents` through `023_inventory_product_audit` are additive local starter migrations, preserving the published migration receipts. The existing core audit stream records product changes. Local automated evidence is separate from supported accounting-policy review, operator browser validation, install/upgrade evidence and publication; the release documentation records completed checks and remaining gates.

Intentional boundaries: one unit per product (further warehouses and vans, per-location carrying values and transfers at carrying value are the optional bundled Stock locations module, whose default warehouse every book carries); no stock reservations or separate dispatch documents; no batches, serials, expiry, landed-cost allocation or letter-of-credit workflows. Those advanced inventory/import features are separate future plugins. The cash POS showcase retains its sample catalogue and is **not** integrated with inventory deductions or COGS. Bank feeds, split bank matching, outgoing foreign-currency bank carrying-value allocation, revaluation and consolidation remain deferred. Tax calculation does not establish country compliance or perform external submissions.

## Published historical foundation boundary: 0.3.0-preview

Migrations `013_currency_foundation` through `016_correction_identity` extend the existing services and are published in 0.3.0-preview. [Foundation notes](strategy/AR-AP-FOUNDATIONS-NOTES.md) record owner decisions, scope and validation.

Every journal line stores exact transaction/base amounts and a frozen twelve-place rate snapshot, including domestic rate-one entries. Books own immutable functional currency with compatible company metadata. Accounts may designate a currency and record known/unknown monetary classification. Manual rate revisions are append-only; lookup selects an explicit source and a date no later than posting. Prior-date selection remains visible as stale. Existing request hashes retain a bounded domestic compatibility path.

Parties are company-scoped legal entities with customer/vendor roles and separate contacts; financial profiles remain book-scoped. Identity schemes, registrations, names and addresses are country-neutral. Tax identity collisions block; duplicate phone use requires acknowledgement and reason. Vetting history, banks, attachments and tags have reserved storage without later workflows. There is no independently editable party opening balance.

Open-item activation is owner-only for unused, currency-neutral AR/AP controls. The sole outstanding calculation sums immutable entries referencing actual journal-line FC/base values; the central posting service writes those references atomically. Settlement reads historical basis from those records, uses the actual bank rate when supplied, and posts bank value, control relief and realised gain/loss together. Final allocation consumes the exact carrying residual. Activity cannot precede the latest item event. Outgoing settlements require a functional-currency bank until foreign-bank carrying-value allocation is implemented. Existing aggregate AR/AP openings are preserved for a later reviewed cutover.

Business source identity is independent of posting IDs. Receipt, expense and general-journal corrections append exact reversals and replacements under the same source/reference. Immutable source revisions drive current reads, filters, sorting and drilldown; original rows remain intact. Cancellation defaults to UTC today; owner original-date exceptions still require an open period. Exact retries return the original action receipt. Bank reconciliation and active-allocation guards remain enforced; openings and POS retain specialized behavior.

Party events contain only source identity/revision. A CLI dispatcher uses scoped consumer leases, bounded retries and immutable attempts, with handlers outside transactions. No consumers, network transport or cron jobs are installed. Delivery is at-least-once with stable receiver idempotency keys. No AR/AP documents, HTTP/MCP write endpoints, provider, regional tax, revaluation or consolidation capability is added.

## Runtime and application structure

### Read integration and richer-demo release (0.2.1-preview)

The 0.2.1 implementation extends the shared bootstrap with connection/read services and the explicit front controller with `/api/v1/`, `/mcp`, OAuth, `/connections` and `/tables`. `mcp/sdk` 0.8.1 supplies Streamable HTTP protocol handling; League OAuth2 Server 9.4.1 supplies authorization-code/refresh flows using MeekroDB repositories and the existing users/permissions. No browser session is constructed for machine callers. Financial DTOs invoke the same accounting services as browser reports. Grants contain explicit actor/client/company/book scope and generation-bound demo expiry; each request rechecks current access and durable revocation.

Migration `011_read_connections` adds seven lifecycle/session/rate/audit tables, without changing posted journals. Browser DataTables 3.0.4 calls the same scoped list services; account window balances are computed before search/sort/pagination. Runtime and key/proxy/discovery requirements, exact limits and the named-client matrix are in [Integrations](INTEGRATIONS.md). The [0.2.1 publication receipt](repository/sprint-05/PREVIEW-0.2.1-PUBLICATION.json) records hosted acceptance and verified Codex HTTP/STDIO clients; partial and untested clients remain explicit. Financial commands are not included.

The minimum runtime is PHP 8.2 with MySQL 8.4 LTS/InnoDB. The next release also accepts MariaDB 10.4 or newer (owner decision, 19 September 2026), because XAMPP and most shared hosts provide it. SQL stays written for MySQL 8.4; the shared MeekroDB `pre_run` hook in `database_platform_functions.php` translates the three MySQL-only spellings on MariaDB. Migration files and checksums do not change, and CI runs the suites on MariaDB 10.4, 10.6, 10.11 and 11.4. Docker defaults to PHP 8.3.33; CI covers PHP 8.2.33, 8.3.33 and 8.4.25. Composer resolves dependencies with a PHP 8.2.0 platform constraint and checks the actual installed runtime separately. The [hosting/runtime record](strategy/HOSTING-PHP-COMPATIBILITY.md) records executed checks. The original [audit](strategy/PHP-8.2-AUDIT.md) retains its historical blocker evidence. Pin third-party dependencies and commit the lockfile; host availability alone is not a verified installation profile.

Keep BixiSoft's lightweight modular architecture: one application, one shared bootstrap, explicit routes, PHP-rendered templates, reusable typed functions, and a maintained MeekroDB dependency. Do not import Agency75 CRM business modules or settings. The reference project demonstrates conventions; it does not establish runtime compatibility.

PHP Ledger is a standalone PHP application. It does not run inside WordPress or depend on WordPress plugins, themes, hooks, or its database model. Its maintainability depends on explicit service boundaries, small testable functions, versioned migrations and documented extension contracts; adding ERP modules must preserve those boundaries.

New code belongs under `www/phpledger`. Only its `public` directory is web-accessible: either it is the document root, or, in a release package uploaded inside a website, the package-root `index.php` and `.htaccess` route requests to it and refuse every other folder. That adapter refuses to start where its rules are inactive, and the installer checks that private files cannot be downloaded before writing secrets. The repository root itself is never served. Configuration, Composer dependencies, migrations, command-line tools, tests, and private storage remain outside that document root. The historical application was removed from the current tree at the owner's request. It remains in Git history for research and is never included by the bootstrap.

Use modern CSS and small JavaScript modules for progressive enhancement. Financial decisions stay on the server. The approved Review Console direction and Inter typography produce one reusable component system. Replace the legacy UI in the new application without editing historical assets for cosmetic consistency.

### Document printing

`GET /print/<type>/<id>` (1.2 M2) is one read-only route serving every printable document. A registry in `print_functions.php`, keyed by document type and paper format, names the view file, the human label and the read-only loader; an optional `format` parameter defaults to A4, and the first registered pair set is the settlement receipt in A4 and 80 mm. The route resolves after the session company context and delegates the read to the same scoped service the document's record screen uses, so company/book scope and role are enforced once: a cross-company id, an unknown type, an unregistered format and any non-GET method are all refused. Printing adds no posting path, no mutation and no new template allow-list in `pl_render()`; print documents render without the application shell from `templates/print`, and their styles are the `@media print` block and paper classes in `resources/ui/app.css`. 1.2 M3 adds three more pairs through that same registry and without touching the route: the A4 sales invoice, its 80 mm counter receipt and the A4 party statement, whose loaders are the scoped read services of the `/ar` and `/parties` record screens. The letterhead uses the optional installation logo (migration 033), the company's name, book and functional currency, and — since the company profile of owner decision B64 exists (migration 037) — its address lines, phone, email, tax registrations and optional footer terms. The profile is empty by default and every field is optional, so a print never claims an address, a registration or a term the owner has not entered.

### In-app accounting guidance

`pl_ui_help('<concept-id>')` (1.2 M2b, owner decisions B66 and B67) renders a question mark beside a label, a column heading or a total; activating it opens a bubble that explains the concept in plain language, so a screen teaches as it is used instead of carrying a long text block or deferring to a manual. The component is native `<details>`/`<summary>`, so it opens, closes and reads with no JavaScript, is keyboard-operable, announces its expanded state, traps no focus, and is positioned out of flow so opening it never moves the page; `app.js` only adds Escape, click-outside and one-open-at-a-time, and the CSP's ban on inline styles means placement is a caller-chosen class, never a script-set coordinate. The content is a catalogue of plain data files in `resources/guidance/` — one file per concept holding a title, a 40-to-70-word explanation, an optional line tying it to the screen and an optional in-application link, and one file per country holding local notes keyed by the same concept ids — read on demand by `guidance_functions.php`, so a screen with ten bubbles loads ten small files and exactly one jurisdiction, its own. Every string is an English source string for `pl_t()`: the guidance is interface text and is translated with everything else. The jurisdictions are the countries behind `pl_base_currency_options()` plus AE, SA and OM; the company's base currency selects one today and a `country_code` on the company row will when the B63/B64 registration profile carries one. A local note reaches a reader only when it is marked verified and carries a source and a check date — anything else is dropped by the loader rather than by review, because B67 holds that an unsourced local claim is worse than none — and a jurisdiction with no note shows the shared explanation alone rather than implying it is that country's law.

## Data and posting boundaries

### Bundled module lifecycle

The [module foundation contract and compatibility matrix](repository/sprint-05/MODULE-FOUNDATION.md) record the published core/POS foundation. The 0.4.0 starter extends that same registry with required AR/AP and optional Purchasing/Inventory manifests. Project-owned JSON manifests under `resources/modules` declare contract/version/dependencies/capabilities and the existing routes/services. Migration `010_module_lifecycle` stores company state and immutable lifecycle receipts. Optional modules default off for ordinary companies; an owner decision enables them after migration/checksum/version validation. The required accounting core remains available.

`pl_require_module()` reuses company/book authorization, current locking reads, manifest action roles and installation checks; `pl_review_pos()` and `pl_checkout_pos()` enforce it in their transaction. Existing POS receipts and accounting sources stay readable after disablement. `pl_set_company_module()` serializes decisions with a company lock and durable content-bound retry receipts. Package installation is a CLI operation, distinct from company enablement. This adds no dynamic code loader, parallel router/auth/database layer, API or MCP transport. Parties, product identity and core tax calculation now serve the starter modules through shared services; consumers must not create competing master-data or accounting stores.

### Opening, period and bank services added on 15 September 2026

`opening_functions.php`, `period_functions.php` and `reconciliation_functions.php` extend the shared bootstrap and explicit front controller. Their `/opening-balances`, `/periods` and `/bank-reconciliation` GET/POST screens use the existing session, CSRF, company/book scope, escaping, forms and server-rendered design. They add no router, framework, connection layer, provider or authentication system. Owners/accountants can write; viewers can read. Opening restart and period reopening require the owner. Public-demo mutations are denied in services; normal navigation omits administration in that demo.

Opening previews are append-only source snapshots. The AR/AP register records **remaining unpaid amounts** and must reconcile separately to each opening control account. Confirmation never posts unpaid documents again: the single opening journal contains the complete reviewed trial balance. An explicit zero opening has a durable receipt without fabricated zero journal lines. Readiness and journal/register writes commit atomically under the book lock. Source digest, account validity, period status and access are checked again at confirmation; repeat confirmation returns the same receipt. A reserved opening source must exactly match its confirmed preview. A cutover stores balances at the **close** of the accounting start date; subsequent entries must have a later date, enforced in the central posting service after retry lookup. Income/expense opening balances may represent reviewed year-to-date amounts; the software does not infer them.

An owner may restart a cutover only while its period is open and before ordinary journals, document drafts or bank statements exist. The correction posts an exact linked reversal, retains the original preview/register, and restores the readiness gate. Once business activity exists, the cutover remains historical and accountant-reviewed correcting entries are needed; the application does not rewrite source history or silently reclassify the original register as current AR/AP.

Period changes hold the same book lock as journal posting. New ranges cannot overlap or precede the company start. Close/reopen uses expected revisions and an actor/reason/request-key receipt with immutable audit data. Closing is a posting control; it does not transfer profit, produce a statutory close or issue statements. Owner reopening does not bypass bank reconciliation's separate backdating guard.

Bank import preserves statement identity, account, date range, signed opening/closing balance and up to 500 exact-money rows. Preview writes no bank rows; confirmation revalidates its digest and the current baseline. The first baseline explicitly confirms that all earlier bank entries cleared and must equal the ledger before statement start. Subsequent statements are consecutive and carry the previous bank closing balance. One journal line can be matched once across statements, in the same company/book/account, with the same debit/credit amount and a valid date. Candidates remain suggestions even when only one exists. Every bank row must be explicitly matched before completion. Ledger-only entries remain outstanding and reconcile **bank close + signed outstanding = ledger close**. Completion rechecks relations and records the balances/actor/time; future posting on or before that reconciled date is denied for that bank account. Reconciliation creates no journals or bank transactions.

Migration `009_bank_draft_cancellation` adds recovery for an incorrect draft import without changing migration 008. Cancellation requires a reason and no current matches; explicit unmatching retains audit events. It preserves the original header, rows, actor and timestamps as terminal cancelled history, while releasing only the active uniqueness claims so a corrected import can use the real bank references again. Completed statements cannot be cancelled. The current matching model is one bank row to one exact-amount journal line; split/aggregate matching remains future scope.

All decision reads use current locking reads under the book transaction, including when a caller owns an older repeatable-read snapshot. Versioned migrations are additive; existing posted records and prior migration checksums remain unchanged. [Development](DEVELOPMENT.md#opening-cutover-periods-and-bank-reconciliation) lists CSV fields, limits and local commands, and [Validation](VALIDATION.md#website-redesign-and-accounting-continuation) separates technical evidence from accounting, usability and release gates.

Financial amounts use exact fixed precision; never use floating-point arithmetic for ledger decisions. Validate date, currency, account ownership, amount precision, period availability, actor permissions, and balanced totals before writing. Database changes use versioned migrations against a canonical new schema, not historical dumps.

Every financial module submits through one posting interface carrying company/book identity, source-document reference, posting date, currency, duplicate-prevention key, and debit/credit lines. Return a durable journal reference or an explicit validation/access failure. This is currently an internal application interface; the approved future business API and MCP adapters will expose the same services after their access and compatibility gates, not a second posting implementation.

Post synchronously and atomically: all header, line, source, and audit records succeed or roll back together. Repeated submissions return the original journal only when their payloads agree; reuse of a key with different financial content is a conflict. Tests must cover concurrent submissions, not just sequential duplicates.

Posted entries are immutable. Corrections use linked reversals with period rules and a visible original/reversal relationship. Closing a period must serialize with posting so concurrent requests cannot bypass the lock. Reports read the same company/book boundaries and reconcile to posted lines.

Begin with one complete book per company. A book key anticipates scoped posting and permissions; it does not settle alternative-book policy. Do not add tax/reporting flags, automatic cross-book copying, or consolidation until discovery defines the intended behavior.

## Approved accounting frameworks and reporting profiles

The accounting core is country-neutral and must follow the accounting guidance applicable to the selected entity and period, including recognition, measurement, classification, transaction rules, closing, statements and disclosures. Pakistan is one intended regional direction; it is not the main product market or a mandatory launch dependency. Regional profiles and tax/e-invoicing connectors are explicit adapters, including Pakistan FBR, ZATCA, UAE Peppol PINT and Oman. For a Pakistan profile, research the applicable statutory/SECP framework with ICAP and ICMAP guidance and qualified review; ACCA guidance supports professional practice rather than defining one universal reporting standard. Existing [Pakistan research](accounting/PAKISTAN_REPORTING_RESEARCH.md) and [reporting gaps](accounting/REPORTING_GAP_ANALYSIS.md) remain scoped reference material.

Define versioned accounting/reporting profiles by jurisdiction, legal form, entity classification, reporting period, framework edition and applicable notifications. Record policy choices, source/paragraph provenance and reviewer approval. Frameworks must be explicitly confirmed for the entity and period, never inferred from IP location, display locale or currency. Effective-period changes create reviewed profile versions without rewriting posted entries or previously issued statements.

Preserve the central posting interface and stable account identities. Add reviewed recognition/measurement rules, supporting subledger and adjustment data, and separate report mappings rather than treating labels or account-number ranges as accounting authority. A statement package needs current/prior periods, applicable components and disclosures, coherent source reads, and reproducible issued results. Unmapped or missing data must remain visible; do not substitute zero or assert compliance merely because the equation balances.

Release gates require a traceable accounting rule register and complete sample financial-statement fixtures posted through the real services. Reconcile profit, position, cash flows where applicable, equity movements, notes and subledger controls; test period closing, reversals, prior balances, mapping changes and restatements. Obtain recorded qualified accounting review for the supported entity/framework scope before making compliance claims. The current reports remain an accounting preview, not a supported jurisdiction-specific reporting framework. The planned headline is real-time owner equity, with AOP partner capital, profit-sharing ratios and drawings; the existing recorded-equity/earnings summary is not that partner-allocation implementation.

### Ownership register (1.2 M8a, decision B63, issue #92)

The company record carries its legal form, registration and incorporation details; a reporting framework is still confirmed explicitly, never inferred from the form. Ownership is an append-only ledger of share events (allotment, transfer, cancellation, bonus issue, re-designation) against share classes, and, for partnerships, effective-dated profit-sharing interests; corrections are linked reversals. Allotments post share capital and share premium through the core posting service; transfers change the register only. The registers of owners/members and of directors/officers use the same party records as receivables and payables. Reports derive the ownership snapshot at any date, book value per share from the balance sheet at that date, and the partner capital account statement over the owner-transactions accounts. The register exports in the Open Cap Format. Hook points at every register event and a read-API surface let a country company-secretarial plugin produce statutory forms, certificates and filing calendars without keeping a second register; those plugins, like country tax adapters, are outside the core.

## Language, time, and future currency model

The user has decided that PHP Ledger will be multilingual with **Urdu first, then Arabic, RTL support and English as the fallback catalog**, keep backend time in **UTC**, display event times in each terminal's local timezone, and later support fixed exchange rates, periodic online rate updates, and manual overrides. Translations and terminal formatting remain future work. Foreign-currency posting now follows the published snapshot/settlement foundations and the local starter boundaries above.

### Language and terminal display

The first delivery layer targets **Urdu first, then Arabic, with RTL support and English as the fallback catalog**. Externalise interface text, test fallback and missing keys, and review RTL navigation, tables, numeric/date fields, printing and mixed-direction source references. As of 1.2 M11 the screens are externalised and a draft Urdu catalogue ships; English is still what an unreviewed key falls back to, and no language is supported until a named person has reviewed its wording.

**Implemented (1.2 M2).** `www/phpledger/includes/functions/i18n_functions.php` provides `pl_t()` and `pl_tn()` over English-keyed catalogues in `resources/lang/<locale>.php`, `pl_locale()`/`pl_set_locale()`, `pl_text_direction()`, and a CLDR plural table covering the six forms (English and Urdu use one/other; Arabic's six are declared). Placeholders are named, `{like_this}`; escaping stays with the caller through `pl_e()`. English is the source language and loads no file; a missing key, a malformed catalogue and an unknown locale all fall back to the English source string. A bundled module, and later a plugin, may declare a `lang` directory in its manifest, merged after the core catalogue; no bundled manifest declares one yet, because that would move the module digest. The four HTML documents (`templates/layout.php`, `templates/views/install.php`, `templates/partials/ui/unavailable.php` and the maintenance page in `update_web_functions.php`) take `lang` and `dir` from the active locale. `tests/i18n_test.php` runs in `composer check`: it covers the helper and sweeps the routes under the non-shipping `qps` pseudo-locale, asserting that every page renders with the locale on its document element. `pl_money()` and `pl_date_label()` now read the presentation seams `pl_number_format_rules()` and `pl_date_format_pattern()`, which return today's Western grouping and `d M Y` for every locale, so written output is unchanged.

**Implemented (1.2 M11).** The interface strings are externalised: `tests/i18n_test.php` counts the literal interface text left in `www/phpledger/templates` and holds a ceiling that may only be lowered. `pl_web_apply_locale()` resolves the locale for every request — the signed-in account's `pl_users.locale`, then a choice made while signed out and held in the session, then `PL_LOCALE`, then English — and the language switch (`POST /locale`, CSRF-protected, `templates/partials/ui/locale-switch.php`) is reachable from the user menu on every workspace screen and from the signed-out card, so the sign-in page can be switched by someone who cannot read it. `pl_set_user_locale()` writes that one column and nothing else. The print frame now takes `lang` and `dir` from the active locale, so all five HTML documents do. `pl_number_format_rules()` returns South Asian grouping (1,23,45,678) for `ur` and for the `en-PK` style locales, looked up by full tag and then by language; `pl_date_format_pattern()` returns `d F Y` for Urdu and `pl_date_label()` translates the month name it produced through `pl_t()`. Amounts, dates and other left-to-right runs are fenced with `pl_bidi_isolate()` (U+2066/U+2069) in a right-to-left locale and are byte-for-byte unchanged in a left-to-right one. Noto Naskh Arabic is self-hosted beside Inter under `font-src 'self'`, cut to the Arabic range so Latin text stays in Inter. `resources/lang/ur.php` is a **draft** catalogue: everything it does not cover falls back to English per key, the switch says so on screen, and `pl_locale_review_state()` reports it as `draft`.

**Still outstanding.** No named human reviewer has passed the Urdu wording, so no language is supported: that review is a separate release gate. Arabic has no catalogue and no number or date rules — its plural forms are declared and nothing else is guessed at. Nastaliq, the style Urdu is normally set in, is not shipped; Naskh is, because Nastaliq's line height does not fit an accounting table, and offering it is a design decision for a later release. A company-level default locale, per-terminal formatting overrides and localized *input* parsing are unbuilt: entry is still canonical `Y-m-d` and plain decimal strings.

Keep text, storage and responses UTF-8, and separate language/locale formatting from accounting values and stable internal identifiers. A language is supported only after translations, font coverage, dates/numbers and actual screens are reviewed. Inter remains the English interface font. Noto Naskh Arabic (SIL OFL 1.1) is self-hosted beside it from 1.2 M11 and is reached by per-character fallback, so Latin stays in Inter; its Urdu coverage still needs a reviewer's eye on real screens, and Arabic has no catalogue yet.

Persist event instants and audit timestamps in UTC and keep backend timekeeping consistent with that convention. Format those instants for the terminal using its browser-reported IANA timezone and locale, with a visible timezone when context could be ambiguous. A terminal's timezone is a presentation preference, not authority to change server timestamps or accounting-period rules. Define and test the display fallback when a valid terminal timezone is unavailable; do not silently reinterpret stored timestamps.

Accounting dates are a separate type of value: posting dates, fiscal boundaries, due dates, and cutover dates represent a business calendar **DATE**, not midnight UTC. Preserve their date value across terminals and daylight-saving changes. Never convert an accounting DATE through timezone arithmetic or let a timestamp formatter shift it into the preceding/following day. Localized display may change the written date format, not the date used for posting, period checks, or reconciliation. A language, country hint or base currency does not establish a tax/reporting profile.

### Locale and formatting preferences

The user requires flexible date formats, currency symbol/code and placement, digit grouping, decimal/group separators, and display precision within the supported currency/accounting rules. Support Western grouping such as `123,456,789` and South Asian grouping such as `12,34,56,789`. Start from locale defaults and allow explicit user/terminal presentation overrides; later official exported documents need company-controlled defaults so an operator's terminal preferences do not silently restyle them.

Formatting is a presentation layer. It must not change stored exact amounts, the transaction/base currency, posting precision rules, or business DATE values. Conversion of entered localized numbers/dates is a separate, explicit validation step using the selected input format. Reject ambiguous or invalid input with a clear example instead of guessing separators, decimal scale, or day/month order. Normalize accepted amounts to exact decimal strings and dates to canonical DATE values before the accounting services receive them; never introduce binary floating-point financial calculations.

Future display preferences must cover the field, table, totals, report, and exported-document contexts deliberately. A changed currency symbol is not currency conversion, and a changed decimal display must not bypass supported precision or reconciliation requirements. Record the actually implemented locale preferences and parsing behavior separately from these approved future requirements.

### Historical multicurrency design direction, superseded by the published foundations

Retain a company/base-book currency and introduce a distinct transaction currency when the multicurrency milestone is designed. Future conversion must use exact decimal amounts and a rate snapshot linked to each posting: the chosen rate, rate source/type (fixed, fetched, or manual), effective time, and enough provenance to explain the converted base amount. Changing or refreshing a rate later must never rewrite posted amounts or historical rate snapshots.

Periodic rate retrieval belongs in a scheduled backend process, never a live provider request made by a browser page load. Keep the last known good rate and make its age, refresh failure, and stale status visible. Manual overrides require explicit server-side permission, a recorded actor/reason and audit trail, and visible reconciliation effects; they must not bypass the central posting interface or posted-entry immutability.

The user has chosen all three rate mechanisms. The provider, refresh cadence, currency-pair/cross-rate rules, precision/rounding policy, and realized/unrealized gains and revaluation treatment are still design and accounting-review decisions. Specify rate selection and override behavior before implementation; do not infer those accounting policies from a rate feed. That earlier base-only sprint boundary is historical. Published 0.3.0 foundations and the local starter now support exact transaction/base snapshots and bounded realised FX; provider retrieval, revaluation and consolidation remain future work.

## Access and installation

Use real password authentication, secure session settings, server-side membership/action authorization, browser POST CSRF checks, bound query values, and contextual output escaping. Deny unknown routes and cross-company access. Keep database errors and credentials out of browser output and logs.

### Capabilities, roles and users (1.2 M7)

Authorisation asks one question: `pl_user_can($actorId, $companyId, $capability)`. Until 1.2 it asked two — `pl_require_company_access()` for membership and read/write, then about fourteen literal `$member['role'] !== 'owner'` checks scattered across the function files. The membership gate is unchanged and still runs first at every one of those call sites; the literal checks are gone. `pl_user_can()` answers no rather than throwing for a stranger, so a screen can ask it to decide what to show; the call site's own gate is what turns "not a member" into a refusal. `tests/capability_equivalence_test.php` proves the swap changed no outcome, for owner, accountant, viewer, outsider and inactive actors, across every converted gate.

A **capability** is a named permission with an owner (`core`, a module, or from M8 a plugin) and a scope. A company-scoped capability is held through a **role**; an installation-scoped one — `installation.admin`, owner decision B44 — is held per person, in `pl_user_meta` under `capabilities:installation`, the shape WordPress uses for `wp_capabilities`, because the owner of one company must not inherit the installation rights of another's. `pl_capability_catalogue()` holds the core catalogue in PHP rather than in a migration, so a module or plugin adds to it without one; `pl_sync_capability_catalogue()` registers it on every migrate, and never removes a capability or a grant.

A **role** is a row in `pl_roles`. `company_id` NULL means an installation-wide definition, available to every company: the three protected system roles (Owner, Accountant, Viewer) are exactly those, and are read-only in the service, in the model and in the database, where migration 041 installs guard triggers. A company may define its own roles from the same catalogue. Roles are assigned per company (owner decision B17), on `pl_company_members.role_id`. The 1.1 three-value ENUM on that table is kept mirrored through 1.2 — every membership write sets both, and `pl_role_enum_mirror()` maps a custom role to `accountant` or `viewer` by whether it can write — and is dropped in 1.3.

A grant of a module-owned capability is **inert, not lost**, while the company has that module disabled: the row in `pl_role_capabilities` is retained and `pl_user_can()` simply answers no, so re-enabling the module restores exactly what was configured.

**Cost visibility** (owner decision B58) needs two yeses: the viewer holds `cost.view`, and the report is configured to show cost in Admin › Cost visibility (`pl_report_cost_settings`). `pl_report_cost_visible()` is the one question every cost-bearing report asks, on the screen and in the machine-readable read alike, so an API client cannot read what the screen hides. Cost is hidden from anyone without the capability whatever the setting says. Two reports are registered and consulted: `stock-by-location` (carrying value, average cost and the control-account reconciliation) and `stock-documents` (the carrying value on a document's lines and its total — quantities, movements and numbers are never withheld, because they are what the document is). The M4 seams `pl_stock_cost_visible()` and `pl_van_settlement_require_approver()` were written to be replaced by a capability and now are. The van settlement's two cost totals are deliberately **not** yet under this rule: they are part of the summary that `pl_approve_van_settlement()` compares before approving, so withholding them from an approver who lacks `cost.view` would make that comparison fail. Bringing them under it needs the comparison to be split from the presentation first.

**Sessions** are server-side and revocable. The PHP session remains the cookie transport and keeps the CSRF token, the anonymous state and the regional hint; a signed-in user additionally holds a row in `pl_user_sessions`, keyed by the SHA-256 of an opaque token, and `pl_current_user_id()` refuses a sign-in whose row is missing, revoked, idle past 30 minutes, past its 12-hour life, or belongs to another copy of the application. Suspending an account, changing or resetting a password and changing a sign-in address all end sessions. The installer and the maintenance page never ask who is signed in, so neither is affected.

**People are never hard-deleted** when a posted record names them: `pl_journals.posted_by` and the audit tables are the answer to "who did this". Suspension is reversible, removal takes away one company's access while the account survives, and anonymisation replaces the personal data while keeping the row, its id and every reference to it. `pl_user_audit` records all of it and is immutable, with the same two triggers `pl_core_audit` uses.

**Invitations and resets** issue a token that is shown once and stored only as a hash. This release has no mail transport, so the one-time link is shown to the administrator, who passes it on; `/invitation` and `/reset-password` redeem it without a session.

**Models.** `PL_User`, `PL_Role` and `PL_Capability` are the first MeekroORM records, on a `PL_Model` base written without table-prefix support (the prefix is 1.3, and lands in `PL_Model::_tablename()` alone). MeekroORM discovers a model's columns with a `SHOW COLUMNS` round trip per model per request, cached within the request and not between them, which on shared hosting is a real per-request cost; the models are therefore used on administration screens only, and every hot path stays on plain MeekroDB. `pl_model_metadata_probe()` measures it and `tests/users_test.php` asserts the hot paths issue none.

Installation checks runtime extensions, database connectivity, writable private storage, and schema status before creating the first administrator through a controlled installation command/flow. Installer re-entry must not become an unauthenticated administrator-creation path. From the next release, a fresh copy's setup opens without a setup key, as WordPress does (owner decision, 19 September 2026). A database on another server still requires a code from private storage, and an operator-provided key keeps strict mode. Once installation completes, setup never reopens. [Installer](INSTALLER.md) records the remaining same-server risk. Sample fixtures are explicit development tools and must never overwrite customer records.

Keep development services bound to loopback and the database internal by default. Installation documentation must distinguish local HTTP from production HTTPS/session requirements. Backup/restore and upgrade verification are release gates. Do not add hosted-account billing, telemetry collection, provider calls on page load, or external sending to the foundation.

The user subsequently authorized a public `/demo` at the marketing website, with a separate sample database, hourly controlled reset, and server-enforced restrictions on destructive user operations. This is an explicit demo deployment scope, not permission to expose customer books. Keep demo identity, allowed actions, storage/reset targets, and session/base-path behavior separate from ordinary installations. Use the shared URL helper for routes, forms, redirects, and assets so the application operates correctly below `/demo`. Reset is backend-controlled, not a public destructive action. Verify customer-database isolation, denied operations, active-session behavior during reset, and recovery before claiming the hosted demo is ready. Published deployment and reset evidence is recorded in the [0.3.0 publication receipt](repository/sprint-05/PREVIEW-0.3.0-PUBLICATION.json); the new starter release still needs its own evidence.

## Core, optional modules and integration direction

The owner's 16 September starter decision supersedes the earlier optional-AR/AP classification: AR and AP remain separate code modules but ship as required accounting capabilities, and generic tax calculation is a shared core service. Purchasing and Inventory are bundled optional capabilities. The [core and module roadmap](MODULE-ROADMAP.md) records the current boundary and preserves earlier delivery priorities as history. The published read API/MCP retains its named-client evidence; this starter does not introduce financial commands.

Keep companies/books, authentication/permissions, chart of accounts, journals/posting, periods, opening/cutover, cash/bank reconciliation and general-ledger reporting in the required core. A supported core-only installation must complete its accounting work with every add-on disabled. Universal account statements and required AR/AP ageing/open-item reporting remain available with optional modules disabled. Inventory valuation and purchasing operational reports belong to their optional service modules and reconcile to the same core ledger.

Use one shared contact identity with customer/vendor roles, and one product/service catalog identity with optional module-owned attributes. Introduce those shared capabilities for their first consumer instead of making a catalog mandatory for a general-ledger-only company. Inventory owns stock and valuation; restaurant operations own tables, modifiers and kitchen state. Shop and restaurant interfaces compose shared POS checkout, posting, identity and permissions. Stocked sales require inventory/costing; non-stock/service checkout can run without it. Credit and applicable tax introduce their own capability requirements.

Define a small explicit module registry in the existing application. Versioned manifests declare supported core contracts, dependencies, migrations/data ownership, routes, permissions, settings and API/MCP operations. Package installation is separate from per-company activation. Use reviewed project-owned packages initially; arbitrary web-uploaded executable plug-ins are outside this design. The current core/POS development line uses AGPL-3.0-or-later; published 0.1.0 through 0.1.5 previews retain MIT. [Licensing policy](LICENSING-POLICY.md) controls dual licensing and advance declaration of future commercial modules. Preserve separate dependency notices; no licence key or licensing-server call may gate self-hosted core use.

Enforce enabled capabilities, dependencies and actor/company/book permissions at execution on every transport. A hidden menu or unadvertised MCP tool is not a security boundary. Refuse incompatible dependencies/upgrades and preserve applied migration checksums. Disabling stops new operations while retaining journal/source snapshots, authorized history/export access and audit; it never silently deletes accounting data. Corrections use core linked reversals, but module-backed documents require compatible correction services and subledger reconciliation; disablement cannot permit an isolated ledger reversal to bypass them. Test disabled modules and dependency combinations as well as ordinary installation.

Reserve effective-version tax contracts and immutable document calculation snapshots before AR/AP/checkout document schemas are finalised. Model separate adapters for FBR JSON/REST, ZATCA XML/UBL with cryptographic stamping, UAE Peppol PINT and Oman; retain jurisdiction-specific credentials, payload/source identity, asynchronous provider response and correction states without duplicating the ledger. Implement/review the required adapter before enabling transactions that need it, even if that moves adapter delivery earlier than the illustrative module order. The shared manually configured tax calculator does not infer country rules or justify treating unsupported tax as zero. Regional accounting/reporting profiles remain a distinct reviewed requirement.

### Business API and MCP adapters

Add a versioned HTTP business API described with OpenAPI, then MCP tools over the same typed service contracts. Keep the existing bootstrap/router, MeekroDB connection and identity/authorization model; transport-specific credential handling must not create a parallel user store or independent ledger. Start with scoped company/capability, account, journal and report reads. Add draft/validate commands before explicit posting/reversal/period commands. The published read implementation retains the named-client results and limitations recorded in the integration documentation. Controlled commands are sequenced after shop POS and e-commerce/storefront. Native desktop/Android clients are planned paid consumers of the same contracts, not a second business/authentication layer.

Machine/client access needs scoped companies/books/actions, revocation/expiry, bounded reads and audit. Mutating commands require durable request identity, matching-content retry receipts, execution-time validation and configured review policy; neither model intent nor a tool annotation grants permission. Return exact decimal strings, stable IDs, business DATE values, UTC event times and structured errors. Retain source/actor/client/command references without logging credentials. API/MCP tests must prove the same totals and denied operations as browser/service tests, including lost-response retries and revocation.

Select and record supported API/MCP protocol versions and authentication transport during implementation. HTTP MCP requires a protocol-compatible authorization design, including discovery and token audience checks; browser cookies alone are not that design. No remote provider, message or payment action is implicitly enabled. The [module roadmap's protocol references](MODULE-ROADMAP.md#api-and-mcp-contract-decisions) point to official OpenAPI and MCP documentation consulted for this plan.

## Extension path and quality gates

Core reports now have scoped CSV exports through `pl_export_report()` and the explicit `/reports/export` route. The export calls the same report/account-statement services inside one transaction, includes all bounded statement pages and preserves exact amounts. It completes the basic export path without introducing a separate reporting engine or issued-report store. Technical two-period fixtures reconcile cutover, general journals, consecutive bank reconciliations, carried earnings, trial balance and date closure; qualified accounting review and jurisdiction-specific year-end policy remain separate gates.

Receipt/expense screens, future AR/AP, and later operational modules must use the same posting interface. If asynchronous operational delivery is later needed, introduce a durable outbox, duplicate protection, bounded retries, visible failure status, and reconciliation. An operational save must never silently lose its financial entry.

Keep accounting rules typed and independent of HTML, sessions, and request globals so automated tests can exercise them directly. Cover precision, balance, reversal, period locks, transaction rollback, permissions, duplicates/concurrency, and report reconciliation. Run syntax checks, static analysis, dependency review, and installation tests on the target runtime.

No compatibility is claimed merely because code parses on an older PHP. Verify the compatible lock, actual platform requirements, fresh/upgrade paths and financial/access suites on PHP 8.2/8.3/8.4 with MySQL 8.4, and on the supported MariaDB releases. Test UI at desktop/tablet/mobile sizes and with keyboard interaction; timed participant research remains separate. PHP 8.3 is the default deployment runtime; 8.4 validation does not raise the minimum.

### Capacity, security and operational release gates

InnoDB is a suitable transactional foundation for accounting and checkout. Its ACID behavior supplies database transaction and recovery mechanisms; durability also depends on database configuration, storage, power and backup arrangements. Balanced accounting, valid account scope and duplicate prevention remain application responsibilities. Preserve durability settings and verify recovery on each supported hosting profile. See [MySQL 8.4: InnoDB and ACID](https://dev.mysql.com/doc/refman/8.4/en/mysql-acid.html).

The current `pl_ledger_book(..., true)` locking read serializes writes to the selected book. `pl_ledger_transaction()` commits related database work together, rolls back failures, and permits at most three retries after a detected deadlock when it owns the complete transaction. An outer caller's transaction is not independently replayed. POS source, journal and receipt snapshot share one transaction; stable request identities distinguish harmless retries from changed-content conflicts. Keep these transactions short, with no user interaction or provider calls while locks are held. InnoDB still permits deadlocks, including through index locks; consistent lock order, suitable indexes and complete-transaction retry are deliberate controls. See [locking](https://dev.mysql.com/doc/refman/8.4/en/innodb-locking.html) and [deadlock handling](https://dev.mysql.com/doc/refman/8.4/en/innodb-deadlocks-handling.html) in the MySQL 8.4 manual.

Existing separate-process tests prove selected duplicate, rollback and concurrency outcomes; they do not establish a till count, transactions per second, latency target or sustained capacity. Before production retail, run simultaneous checkout and report workloads using realistic history volumes, several terminals in the same book and several independent businesses. Agree workload and response-time/error targets before testing, then record latency percentiles, lock waits/deadlocks, resource use, report reconciliation and a sustained soak run. Test lost acknowledgements and retry recovery. The public demo's additional request-wide maintenance lock intentionally serializes demo requests and is not a normal-installation throughput benchmark.

Current reports aggregate posted ledger data synchronously. Profile queries and indexes before introducing summary tables, caching, report workers or background exports. Add those only for measured need; any derived balance must remain rebuildable, scoped, dated and reconciled to authoritative journals. A background job needs durable identity, retries and visible progress/failure. Never make checkout wait for a large report or move financial acceptance into an untracked background task to mask contention.

Current controls include password hashing and login throttling, session rotation, company/book permissions, CSRF, bound query values, output escaping, browser security headers, immutable posted records and tested rollback. These do not constitute an independent security audit or compliance certificate. Before real-customer pilots, commission a risk-based independent application/deployment review using a recorded [OWASP ASVS](https://owasp.org/www-project-application-security-verification-standard/) version and scope. Include privilege separation, account recovery, session/access revocation, backups, dependencies and abuse controls; add upload/export review when those features exist. Separately review privacy/data flows and retention, including the authorized country-IP lookup, and obtain accounting/local tax review for each supported jurisdiction. No current country selector establishes tax compliance.

### Queued entry and synchronisation

Owner decision, 15 September 2026: **offline means queued entry only, never offline posting**. Browser clients may store unposted drafts locally and submit when online. Planned desktop/Android clients use periodic sync over low-bandwidth connections. Posting, period control and reversal always run on the authoritative server through the existing guarded services.

Each queued draft needs a stable device/request identity and idempotent submission key, a versioned payload, an explicit company/book/actor context and visible pending, submitted, accepted or rejected state. The server rechecks current identity, membership, permissions, scope, period, prices, stock/tax dependencies and duplicate content. A draft remains unposted until the server returns the durable source/journal receipt required for the requested action. A transport acknowledgement alone is not posting success.

Lost responses and repeated sync return the existing result only for matching content; changed content is a conflict. Retain rejected drafts for correction and explain expired credentials, closed periods or changed source data. Do not silently overwrite posted records, substitute client clock time for business dates, or treat a draft as final fiscal output. Local storage protection, device loss, browser storage eviction, queue upgrades and per-user logout cleanup require validation before release. No background sync may grant posting authority or bypass the configured review policy.

A second queued-entry form is a **device sub-ledger with settlement posting**, for trusted signed devices such as a delivery van or a counter terminal (owner decision B22, 19 September 2026). The device keeps an append-only, device-signed event log with a per-device sequence, records both device time and server-received time on every event, and uploads events in batches. The server stores every event durably and never drops one; it does not post events individually. When the device closes its session, the batch enters review: the server rechecks parties, prices, stock, periods and duplicates and marks exceptions as flagged lines. An authorised user resolves the flags and the batch posts as one set of journals through the ordinary posting service with the batch identity as the idempotency key. The business date is the settlement date chosen at review, never the device clock. Human-readable references printed on the device are kept as source references; ledger document numbers are assigned at settlement from the per-type series. This form does not grant any device posting authority and does not bypass the review policy.

The current browser/POS has no service worker or durable offline draft queue. A reachable customer LAN server may allow server-side operation during an internet outage, but that is a separately tested hosting profile, not disconnected-browser posting. No offline card-payment authorisation is promised.

### Distribution, installation and update contract

Immediately after AP, the named adoption milestone covers Softaculous, Installatron, a Docker Hub image, Packagist and an in-app updater, coordinating the planned browser installer. Validate authentic versioned downloads, least-privilege one-time setup, current schema receipts, recoverable source/configuration/database backups, maintenance locking and failure recovery. Never replace accounting data or rewrite applied migration checksums. The installer-creates-a-customer-website idea is parked. These are planned distribution/update capabilities, not active endpoints, published packages or an automatic-update service.

## Historical foundation and browser implementation checkpoints

The following describes the verified foundation snapshot. [Sprint 02](SPRINT-02.md) adds the first browser journey using these same interfaces; its routes, draft persistence, schema changes, and checks must be documented from the completed implementation. Until that evidence is recorded, the planned journey is not a verified capability.

The foundation checkpoint was a fresh application under `www/phpledger`, with PHP 8.5.10 and MySQL 8.4 in Docker. The shared bootstrap loads the pinned Composer MeekroDB dependency and typed functions from `includes/functions/`. At that checkpoint the only HTTP endpoint was `GET /health`; login, onboarding, transaction entry, imports, and reports had no browser routes. The newer browser implementation and its separate evidence are recorded below.

Financial inputs are decimal strings, normalized through BCMath to four decimal places; persisted amounts use `DECIMAL(20,4)`. The proof supports one primary book per company and the base currencies USD, EUR, GBP, PKR, INR, MYR, BDT, LKR, NPR and SGD. A currency option does not imply tax, localization, or foreign-exchange support. Company creation currently adds six generic starter accounts; the researched industry/country catalog is not activated.

| Internal function | Current behavior |
|---|---|
| `pl_create_user()` | Creates a password-hashed account through controlled CLI setup; no public registration route |
| `pl_authenticate()` | Verifies password/active state and durable account/client attempt limits; returns a user identity or generic denial |
| `pl_require_company_access()` | Checks current active membership; owners/accountants can write, viewers can read |
| `pl_create_company()` | Atomically creates company, owner membership, one book, initial open period, and six generic accounts |
| `pl_post_journal()` | Validates scope, dates, base currency, active accounts, precision, balanced lines, open period, and duplicate-prevention key before atomic posting |
| `pl_get_journal()` | Retrieves a scoped durable journal reference and its account lines |
| `pl_reverse_journal()` | Posts one exact linked reversal on or after the original date in an open period |
| `pl_trial_balance()` | Returns scoped net account balances, debit/credit movements, and reconciled totals, optionally to a date |

Posted headers and lines reject updates/deletes through database triggers. The posting interface is the authorized application write path; database administrators still require tightly controlled access. The proof validates an existing period's state at posting time; period-management screens and a complete fiscal-calendar workflow are future work.

Posting serializes on the selected book, uses current locking reads for permissions and idempotency, and can replay a complete internally owned database transaction after a detected InnoDB deadlock, with at most three retries. Caller-owned transactions are never independently replayed. Callbacks must contain database work only; email, provider, or other external side effects cannot be placed inside a retryable transaction.

Migrations are CLI-only and keep checksums and application receipts. An interrupted DDL migration is a recovery case, not an instruction to blindly replay partial SQL. Fresh install and replay evidence, dependency checks, concurrent-write regressions, and isolated backup restoration are recorded in [Validation](VALIDATION.md). Design selection is recorded separately; the technical proof does not establish a finished interface, production readiness, general hosting compatibility, or accountant approval.

### Sprint 02 implementation contract

Add browser login and logout around the existing authentication/session helpers, and essential company onboarding around the existing company/book service. Save receipt/expense drafts as editable business documents; posting submits their authorized, validated financial content to the central journal interface. A durable link must connect each posted source document to its journal and report drilldown. Show Save draft only during editing, preserve user input on validation failure, and prevent stale or repeated actions from silently overwriting or duplicating financial work.

Reports and linked reversals retain the same company/book permission boundaries. The Review Console uses a persistent list/detail layout on desktop and an intentional list-to-detail path on small screens. The initial sprint does not add a second routing/authentication/database stack, historical import execution, alternative books, or country tax behavior. Record actual route and schema details once implemented; do not treat this contract as a completed API inventory.

### Browser implementation checkpoint

The published browser router implements the following server-rendered interfaces. These are internal browser routes, not a promised public JSON API. HTML GET screens and POST actions share the existing bootstrap, authentication, CSRF, company/book permissions, and posting services. `/health` remains a separate JSON endpoint.

| Browser interface | Routes and behavior |
|---|---|
| Session and business context | `GET/POST /login`, `POST /logout`, `GET /companies`, `POST /company/select`; root redirects according to authentication state |
| Setup | `GET/POST /onboarding` previews and confirms the starter chart; `GET/POST /setup/review` explicitly maps prior-foundation accounts and confirms review without replacing their history |
| Drafts and review | `GET /transactions`, `/transactions/detail`, `/transactions/new`, `/transactions/edit`; `POST /transactions/save` keeps draft revisions and recoverable errors |
| Accounting writes | `POST /transactions/post` uses the central posting interface and returns the same durable journal on duplicate confirmation; `POST /transactions/reverse` posts a linked reversal |
| Reports and help | `GET /reports`, `/reports/trial-balance`, `/reports/account`, `/reports/profit-loss`, `/reports/balance-sheet`, `/journals/detail`, and `/help`; reports read posted records and drill down to account/journal/source detail |
| Cash scenario | `GET/POST /reports/cash-forecast`; scoped posted opening cash plus explicit weekly inflow/outflow assumptions over 1–52 weeks; no ledger writes or automatic prediction |
| General-shop POS | `GET /pos`, `POST /pos/checkout`, `GET /pos/receipt?id=...`; server-priced sample cart → atomic cash receipt/journal/item snapshot → printable receipt |
| Restricted demo entry | `GET /login` shows the public sample entry in demo mode; `POST /start` creates/reuses the visitor's private sample company; the normal business-setup and company-switch routes are blocked |

Migration `002_product_slice` adds business setup/sample state, stable semantic account roles, a versioned template installation snapshot, and receipt/expense documents. A document carries its company/book, exact amount, date, source fields, creation identity, revision, and durable journal link. Posted source documents reject updates/deletes through database triggers. Companies from the original foundation enter `review_required`; an existing business chosen during new onboarding enters `opening_required` and cannot bypass missing opening data through the prior-foundation review route.

The active preliminary template is `core-starter` version `1.0.0`. It is a small neutral chart, not reviewed country/industry accounting coverage. The isolated core sample and its version/digest are recorded with setup; the broader industry research packs remain separate. The current schema has migrations `001_foundation` through `005_demo_period_guard`, with **15 tables and 9 accounting/demo/POS guard triggers**. Applied migration files and their checksums remain immutable; the demo period guard is additive migration 005, not a rewrite of 003.

A local HTTP acceptance checkpoint passed **26 checks** using its own sample company: session/CSRF boundaries, setup preview/confirmation, retained and escaped invalid inputs, draft report isolation, repeated posting identity, report/source links, invalid/repeated-state recovery, linked reversal, and closed-period rejection preserving the draft. The period check only closed/reopened that sample company's period. This is HTTP integration evidence; browser interaction, responsive/keyboard usability, final demo checks, and publication require their own receipts in [Validation](VALIDATION.md).

### Reports and cash scenario

`pl_profit_loss()` reads posted income/expense movements for an inclusive business-date range. `pl_balance_sheet()` classifies posted balances as assets, liabilities, and recorded equity, with accumulated unclosed earnings included once; the result reconciles assets against liabilities plus equity. `pl_cash_balance()` reads scoped asset accounts carrying the cash/bank role. Reversals remain posted records and naturally change their report effect; drafts do not appear.

The 0.1.2-preview implementation extends `pl_account_activity()` and `GET /reports/account?id=...` into an account statement. An optional `from` business date and inclusive `as_of` cutoff define the period. Opening is all posted debit-minus-credit activity before `from` (zero when omitted); period debit/credit totals cover the selected dates; closing equals opening plus period movement and therefore all activity through the cutoff. The existing `balance` return field remains period movement for internal-caller compatibility; `opening_balance` and `closing_balance` carry the new meanings.

Running balances use debit-positive signed amounts in business-date, journal-ID and line-number order across the whole period before 50-row pagination; each page includes its brought-forward and carried-forward amounts. Totals, rows and source reads share one transaction holding the shared book lock while posting takes its exclusive lock. Company/book access is enforced and inactive accounts retain readable history. The statement does not create an opening/cutover entry or implement an import/reconciliation workflow. See the [local account-statement validation receipt](repository/sprint-04/ACCOUNT-STATEMENTS-VALIDATION.md) for test/browser evidence and release status.

`pl_cash_forecast()` is an exact-decimal scenario function. The browser takes the selected date's authorized posted cash balance and explicitly entered weekly cash in/out over 1–52 weeks, then shows closing balances and the first negative point. It does not infer future revenue, outstanding invoices, bills, stock, or tax. It neither changes the ledger nor represents a cash-flow statement. Full receivables/payables/stock reports await their respective modules and cannot be derived honestly from this small receipt/expense ledger alone.

### Structured account codes, contra accounts and owner transactions (1.2, migration 036)

Accounts are numbered `X-XXX-XXXXX-XX` — one class digit, a three-digit group, a
five-digit account and a two-digit sub-account (owner decision B56, issue #76).
The code **is** the hierarchy: `1-000-00000-00` is the Assets class heading,
`1-100-00000-00` a group heading, `1-100-10001-00` an account and
`1-100-10001-01` a sub-account under it. No parent identifier is stored, so a
heading and the accounts under it can never disagree. `account_code_functions.php`
holds the parser, validator, formatter and hierarchy helpers as small typed
functions with no database, request or HTML access.

Only a leaf receives postings. A class or group heading never does, and an
account stops being postable as soon as it has sub-accounts; the check lives in
the single central posting funnel (`pl_post_journal_locked()`), not in each
screen. Parents aggregate: every report totals each level from the accounts
below it.

Migration **036_structured_account_codes** converts an installed chart by its
**current** groups — the first two characters of the old number are the group —
allocating group numbers 100, 110, 120 … per class and account numbers from
10001 within each group. Group numbers 900 and above are never allocated,
because they are reserved for the contra groups. The old number is kept twice:
as `pl_accounts.legacy_code` and as a row in the immutable `pl_account_code_map`
(guard triggers refuse updates and deletes). Account identity, classification,
role, currency, status and every posted journal line are untouched, so no
balance and no report figure moves. The conversion is reversed, if it ever has
to be, with `UPDATE pl_accounts a JOIN pl_account_code_map m ON m.account_id =
a.id SET a.code = m.legacy_code`. A book created on 1.2 is born numbered and has
no conversion rows; `pl_account_code_mapping()` resolves either number so demo
packs, samples and imports written against the old chart keep working.

`pl_accounts.is_contra` marks a contra account (owner decision B60). It is a
presentation flag only: the account keeps its own type and its natural debit or
credit balance, so the trial balance still balances, and reports show it as a
deduction **inside** its own section rather than as a member of the opposite one.
The reserved groups are accumulated depreciation and provisions against assets
(`1-900`, `1-910`), drawings (`3-900`), sales returns and discounts allowed
(`4-900`, `4-910`) and purchase returns and discounts received (`5-900`,
`5-910`). A liability cannot be marked contra: an obligation is an ordinary
liability.

`pl_report_tree()` shapes the flat trial-balance, profit-and-loss and
balance-sheet rows into that class → group → account → sub-account tree with
every measure aggregated at each level (issue #77). `pl_report_tree_limit()` is
the depth control and `pl_report_tree_rows()` flattens a tree for CSV and print.
The screens render it with native `<details>`/`<summary>`, so it expands and
collapses with the keyboard and on a phone without JavaScript, and the print
view shows exactly what the reader has expanded. Assets and Expenses open by
default (design frame decision 19). The machine-read API keeps the flat,
paginated rows: the tree is the same figures re-shaped for a screen.

`owner_functions.php` implements owner transactions (owner decisions B61 and A3)
over the same central posting service: capital introduced (Dr cash, Cr owner
capital), an owner loan to the business (Dr cash, Cr the owner's loan account, a
liability), its repayment and drawings (Dr the contra-equity drawings account,
Cr cash). Every one is an ordinary immutable journal with `source_type`
`owner_transaction`, corrected only by a linked reversal.
`pl_owner_equity_movements()` reads capital, drawings and the outstanding owner
loan straight from posted journals for the balance sheet's equity section and
the Owner and partners screen. `pl_owner_partners` records an AOP partner's
capital, drawings and loan accounts and a profit-sharing ratio; the active
ratios can never total more than 1. Allocating a period's profit between
partners is **not** posted automatically — the appropriation order, interest on
capital and drawings, and the treatment of losses are accounting decisions left
for the accountant's review (owner decision B30). See the
[owner-transaction worked examples](accounting/OWNER-TRANSACTIONS.md).

The bundled chart is `resources/coa/core-starter-1.1.0.json`: structured codes,
each pre-1.2 account's old number recorded, and the owner-capital, owner-loan,
drawings, contra and advances control accounts present so every sample and demo
shows them.

Migration **040_contra_accounts_and_partner_identity** completes the conversion
036 left half-finished. 036 added `is_contra` with `DEFAULT 0` and never set it,
so a book born on 1.2 carried the bundled chart's contra marking and a book
upgraded to 1.2 carried none: no drawings account existed, the chart's own
drawings account appeared among the capital candidates, and every deduction
presentation was lost. 040 sets `is_contra` from `semantic_key` — written only
by the starter template at company creation and by `pl_confirm_existing_setup()`,
so the four contra purposes mean exactly what the bundled chart says — and for
every keyless account it asks instead of guessing. `pl_contra_confirmations`
holds one row per **converted** book (a book with `pl_account_code_map` rows),
`pl_contra_require_confirmed()` refuses the owner services and `/owner` while it
is `pending`, and `pl_confirm_contra_accounts()` records the reviewed answer and
writes the ordinary `pl_core_audit` row per account it marks. Nothing is derived
from an account's name. The same migration makes a partner's drawings and loan
accounts unique per book, as the capital account already was;
`pl_owner_require_own_accounts()` carries the cross-role half of that rule, which
spans three columns and every row and so cannot be a constraint. Every rejected
alternative and the exact reversal SQL are in the migration's header.

### Advances, refunds and orphan credit notes (1.2)

Money a customer pays beyond their open invoices is **unapplied credit**, and it
is held as an advance received from that customer: an ordinary liability on the
`customer_advances` control, tracked as its own open item. It is never a credit
balance inside receivables and never a suspense account (B59). The supplier
mirror, `supplier_advances`, is an asset. Migration
**039_advances_and_refunds** adds the two account roles with their monetary
mapping, the `pl_advance_accounts` registry of registered controls,
`pl_open_items.nature` (`document` or `advance`, null meaning document for every
row written before it), the `application` and `application_reversal` entry
kinds, and the two control accounts in every book that lacks them. `direction`
is deliberately not extended: it is read arithmetically everywhere, so a
customer advance is a credit-normal `payable` item and a supplier advance a
debit-normal `receivable` item. A CHECK plus a foreign key require an advance to
sit on a registered advances control; the reverse implication — a document item
never sits on one — cannot be expressed as a CHECK, because it has to read
another table, so it is a `BEFORE INSERT` trigger.

`pl_oldest_first_allocation()` is a pure function: given open items, an amount
and a date it returns the plan, ordering by due date then id, giving the last
item the exact residual and skipping any item whose latest activity is later
than the payment. `pl_plan_settlement_allocation()` reads a party's items and
calls it; the plan is then editable and still goes through the existing preview,
review hash and confirmation. A receipt with a remainder posts **one** journal
with **one** bank line, the remainder recognising the advance item in the same
journal. `pl_apply_unapplied_credit()` later moves the credit between the two
controls with no bank line at all, realising the difference between the two
frozen rates when they differ. `pl_refund_unapplied_credit()` is the ordinary
single-item settlement of an advance against the bank, and
`pl_recognize_unapplied_credit()` posts a credit note that has no original
invoice against an income account for a customer or an expense account for a
supplier — a goodwill credit reduces revenue rather than creating a cost (IFRS
15.70-72, IAS 1.32) — with the reserved contra group offered as the default, a
bank or control account refused outright, and an owner-given reasoned override
recorded on the posting for the genuine exception. The rule is enforced again by
`pl_open_item_validate_direct_advance_basis()` in the posting funnel.
`pl_post_batch_receipts()` posts one voucher per party row (B57) inside one transaction with one batch receipt, so a
row can be reversed without touching another's. B7's reversal rules hold
throughout: a whole receipt can no longer be reversed once its remainder has
been applied, an application reverses on its own, and a reversed advance is
never usable again. `pl_unapplied_credit()` is the read behind the ageing
report's own section and the statement's Unapplied rows; it reconciles each
advances control's posted balance to the advances outstanding on it, and
`pl_ar_ap_open_items()` excludes advance items so nothing is counted twice. The
same read is an authorised API/MCP operation, `unapplied_credit`, on full-access
connections only. See the
[advances and refunds worked examples](accounting/ADVANCES-AND-REFUNDS.md).

### Core accounts and general journals (0.1.2-preview)

Migration **006_core_accounts_journals** appends account revisions and stable creation identities, `pl_general_drafts` and append-only `pl_core_audit`. It preserves migrations 001–005 and prior accounts, journals, documents and template mappings. A fully migrated installation has 17 tables, six receipts and thirteen guard triggers.

`GET /accounts` lists scoped accounts and shows creation/edit/history; `POST /accounts/save` requires permission, CSRF, a reason and the expected revision. Creation keys reject conflicting reuse. Existing codes, types and purpose roles are immutable; names and active status are audited. Inactive accounts retain their statements. Public-demo account administration is blocked on the server.

`GET /general-journals`, `/new`, `/edit` and `/detail` provide saved general journals. `POST /general-journals/save`, `/post` and `/reverse` reuse the shared bootstrap, auth, CSRF, readiness, book lock and posting services. Drafts may be unbalanced but each used line has one exact positive debit or credit; posting requires a balanced saved revision. The posting request supplies identity/revision and explicit intent, never replacement financial values. The durable `general:ID:post` key links source and journal atomically with the audit entry. Posted sources are immutable; a dated, reasoned reversal preserves linked history.

Current locking reads revalidate account/draft revisions even inside an older repeatable-read transaction. Audit or source-link failure rolls back all financial effects. General-journal drafts share the public demo's document capacity; demo post/reversal remains allowed through the same services. General-ledger source links resolve original and reversing entries. Browser/API/MCP module transports do not bypass these rules; the latter two interfaces remain planned.

The [core rule register](accounting/CORE_RULE_REGISTER.md) maps behavior and tests to researched professional guidance without claiming professional certification. The [tax catalog](tax/README.md) is versioned research data only, excluded from runtime activation and calculation.

### POS posting contract

The [POS showcase](POS.md) uses `pl_pos_catalog()`, `pl_checkout_pos()`, and `pl_get_pos_receipt()` with the existing database/authentication/transaction interfaces. Only catalog SKU/quantity, date, cash tender, catalog digest, and stable checkout identity reach the checkout service. The route verifies CSRF and company/book identity before removing those transport fields; unexpected price/total fields still fail validation. The ledger stores four decimal places, but a cash tender is physical money: `pl_cash_amount()` holds cash received to whole minor units, so recorded tender and change share the sale total's precision.

Checkout locks the book, checks posting permission/readiness, resolves prices on the server, and calls `pl_save_document()` and `pl_post_document()` inside the same outer transaction as the immutable `pl_pos_sales` snapshot. The cash journal amount is the sale total; cash received/change remain receipt facts. Same-key/same-content retries return the existing receipt; conflicting content is rejected. A closed period or late snapshot-insert failure rolls back the source, journal, lines, and snapshot together. Migration **004_pos_showcase** adds the scoped snapshot table, uniqueness/check constraints, and update/delete guards. The six fictional products are not stock records, and their prices are not FX conversions.

The independent local POS HTTP checkpoint passed **17 checks**, including a USD 12.75 sale, 20.00 cash/7.25 change, source/journal identity, repeat checkout, retained invalid inputs, and CSRF/scope/tamper rejection. The combined target-runtime suite passed **54 tests** including eight POS cases and separate-process duplicates. These tests do not establish receipt-printer compatibility or production retail suitability.

### Restricted public demonstration

Demo mode requires the literal isolated demo database, a separate restricted web database user, and a valid demo state. Migration **003_demo_isolation** adds generation/visitor records; migration **005_demo_period_guard** blocks fiscal-period changes by the demo web path. Each visitor receives a sample account and private sample company, with server-enforced membership, generation/expiry checks, and bounded visitor/document capacity. Business setup, administration, destructive database privileges, posted edits/deletes, and fiscal changes are unavailable to visitors. Normal authorized draft/post/reversal/POS services retain their accounting checks.

The CLI-only `tools/demo-reset.php` validates the exact demo target and sample markers, holds a maintenance lock shared with requests, and rebuilds only that isolated database. Reset credentials never enter the web runtime. An expired generation rejects work until refresh; sessions from a prior generation are invalid. An hourly backend schedule and correctly prefixed `/demo` links/forms/assets are required at deployment. Local reset/restricted-user/isolation proof passed; the actual live schedule, uptime, and public browser behavior require release evidence. Never use this reset against the development, test, or customer database.

### Current regional and presentation groundwork

The English UI formats journal UTC event instants using the terminal's IANA timezone and uses the local day only for untouched new-date defaults. Stored accounting dates are presented as date values, without timestamp conversion. Money display uses exact decimal strings, Western grouping, and two decimals unless the stored value needs four; explicit localized parsing, user formatting settings, English translation catalogs, additional languages, and FX posting remain future work.

`pl_regional_suggestion()` performs at most one bounded backend country lookup per browser session, including caching failure or local-network skip. For a public client address it sends that address to the fixed country.is HTTPS endpoint; it requests no precise location and does not log the raw address/provider URL/body. Private/local addresses never call the provider. Forwarded addresses are accepted only behind explicitly configured exact trusted proxies, using the nearest untrusted hop rather than an arbitrary leftmost value. A timeout/failure leaves manual choices available without per-page retries.

The bundled [CLDR country defaults](../resources/locale/README.md) supply country/locale/currency metadata. Only supported base currencies (USD, EUR, GBP, PKR, INR, MYR, BDT, LKR, NPR, SGD) can be suggested; unknown, ambiguous, or unsupported currencies require manual choice. The shared `pl_base_currency_options()` list controls both selectors and server validation. Malaysia, Bangladesh, Sri Lanka, Nepal and Singapore reuse their existing CLDR entries, and cached hints refresh their local support metadata without another API call. The suggestion never changes an existing company's currency, chooses a tax regime, translates the interface, or fetches exchange rates. Provider behavior is tested using a mock transport, separately from any live deployment lookup.

## Researched template and sample-data integration

The [Chart of Accounts research](coa/README.md) proposes stable semantic account keys, a neutral core, an industry layer, and reviewed country packages. The [business sample packs](sample-data/README.md) use original sample data and account roles to describe linked business scenarios. Both remain separate from runtime installation until their format, provenance, accounting behavior, and company isolation are reviewed and implemented.

The future wizard should preview the resolved chart, explain required accounts, allow import mappings, and pin the confirmed template version to the company. Application upgrades must not replace a customer's chart. A sample-company choice must create an isolated demonstration context; real-company setup must never silently load sample customers, products, balances, or transactions. Opening documents and balances must reconcile without posting AR/AP twice. See the research model for the worked cutover example and the roadmap for delivery gates.
