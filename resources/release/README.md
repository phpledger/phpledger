# PHP Ledger

Country-neutral, open-source bookkeeping for small businesses, built with PHP and MySQL. Project-owned code uses AGPL-3.0-or-later; a commercial licence is available. Self-hosting is free without licence keys or licensing-server calls. Published pre-adoption 0.1.0 through 0.1.5 previews retain MIT. See [Licensing policy](docs/LICENSING-POLICY.md).

**1.4.1: local patch candidate, not yet published.** The candidate covers date entry, employee date-of-birth validation, eligible invoice/bill line accounts, required-field presentation, sample completion wording, navigation and phone layout corrections. See RELEASE-NOTES.md for scope and pending gates. There are no new migrations or schema changes, and this patch carries no media kit. Independent accounting/security review, real-business pilots and unfamiliar-operator observation remain separate assurance work. It is not a completed ERP or a country-certified accounting product.

The package itself carries a short `README.txt`; this longer overview is kept in the repository.

The interface uses compiled local CSS and server-side paged lists. No Node installation is required. See RELEASE-NOTES.md for interface changes, browser installation and signed updates, and the disclosed assurance limits.

## Start here

1. Read [INSTALL.md](INSTALL.md) for hosting requirements, browser setup at `/install`, and the retained CLI setup path.
2. Sign in and create an isolated sample company or a new business. In local development, the separate sample chooser provisions only the selected sample company. Review the chart and create a customer/vendor party before recording an invoice or bill.
3. Review and post the document, record a partial payment, and follow the remaining balance into ageing, its journal and the account statement. Corrections retain the same document identity and preserve linked reversal history.
4. For stock businesses, enable Inventory and then Purchasing in **Modules**. Create a stock product, confirm a purchase order, receive goods and match the later supplier bill. Review stock valuation and received-but-unbilled reconciliation.
5. Configure any required tax codes, accounts and dated rates manually in **Tax**. Choose exclusive or inclusive price entry; documents show the separate net, tax and total. For existing businesses, review opening debt/stock conversion before using their imported balances operationally.

The package needs PHP **8.2+ (8.3 recommended)**, MySQL **8.4** and HTTPS. Production Composer dependencies are included. Use hosting-panel preparation and guarded browser setup at `/install`, or the retained CLI setup path. Automatic updates additionally require PHP ZIP, private backup space and verified schema/file permissions. Keep the supplied directory layout: only `www/phpledger/public` is the web document root. Do not serve the package root.

## Historical first-stable scope: 1.0.0

- Protected browser installation at `/install`: host ownership proof with a private setup key, the existing migration chain, private configuration and OAuth key provisioning, first-account creation and onboarding. Setup locks after completion.
- Operator-initiated signed updates and recovery through the independent `/maintenance.php` entry: a private `operator.key`, a pinned publisher public key, explicit stable/preview channels, automatic complete matched backups (code, configuration, keys, database) and automatic matched restoration with financial-total verification on failure. `tools/resume-update.php` advances recovery from the CLI when shell access is available.
- Eleven sample businesses with closed 2024–2025 histories, open 2026 practice, three editable drafts per company and private reporting walkthroughs. The public website carries three illustrated guide articles. Only the selected sample is provisioned for a visitor. See [demo packs](resources/demo-packs/README.md).

- Sign-in, company setup, a preliminary account template and a clearly identified sample.
- Seven newer vertical successors carry pinned research evidence and remain `preview_only` until their operational treatment is independently reviewed. The current importer posts the reconciled supported history; future specialist records remain review evidence.
- Receipt/expense drafts, balanced posting, durable source references, duplicate protection, period controls and linked reversals.
- Chart management with stable account IDs and audited name/status changes; general-journal drafts, review, posting and dated reversals.
- Account statements for all five account classes, with opening, period, running and closing balances.
- Authorized read API/MCP, existing-user Connections/OAuth and a standalone STDIO bridge. See [Integrations](docs/INTEGRATIONS.md) for configuration and open client acceptance gates.
- Progressive server-side tables with 25/50/100-row pages, bounded search/sorting and canonical account running balances.
- An owner overview, trial balance, balance sheet, profit and loss, and a cash scenario using editable assumptions.
- An illustrative cash-sale POS with click-to-add products, cart controls, separate review/cash confirmation, receipt and accounting entry.
- Opening trial-balance/CSV cutover with reconciled unpaid-document evidence, period administration, and bank statement CSV import/matching/reconciliation.
- Core CSV exports with exact amounts and scoped report metadata; account exports include all movements up to the documented 10,000-row limit.
- Bundled required AR/AP and optional Inventory, Purchasing and POS manifests. **Modules** records owner-controlled activation/upgrade decisions and AR/AP navigation visibility. Ordinary companies start with optional modules disabled; explicitly created samples enable the POS showcase. Disabling optional operations preserves authorised historical access.
- English screens, a choice of supported functional currencies, regional formatting and terminal timezone display. Each book has one immutable functional currency; the internal posting service also stores exact transaction-currency amounts and frozen manual rates on every journal line.
- Country-neutral party/contact storage with customer/vendor roles, scoped identity duplicate checks and acknowledged shared-phone duplicates. Vetting status and history are schema foundations; no vetting workflow is enabled.
- Customer invoices, supplier bills, partial/final payments, linked credits, historical ageing and control reconciliation. Browser workflows and internal module calls share the authoritative open-item ledger, exact carrying amounts and realised FX with actual-rate override.
- Purchase orders, separate partial receipts, later AP bills, receipt/bill matching, explicit price/rate variance review and coordinated supplier returns. Purchasing never maintains a separate supplier balance.
- Shared stock/non-stock products, one inventory location, immutable stock movements, moving weighted-average valuation, counts and reviewed value adjustments. Stock invoices issue goods and post cost of goods sold through the same services.
- Reviewed conversion of existing unpaid opening evidence and opening stock quantities/value into operational ledgers. Explicit party/product mappings must reconcile to the original journal basis; confirmation does not post opening balances again.
- Country-neutral manual tax codes, dated rate revisions, input/output accounts and owner-controlled default exclusive/inclusive price entry. Posted documents retain their reviewed mode and tax snapshot; credits inherit their original source basis.
- Receipt/expense and general-journal correction services retain document identity, append source revisions, reverse the old posting and post the replacement atomically. Existing lists, reports and read integrations resolve the current source while preserving original history.
- A retryable, idempotent outbound queue and manual rate-entry CLI. No external delivery adapter or automatic cron installation is supplied.
- Disabled, unreviewed tax research JSON for eight countries and seven industries, with a read-only CLI structural validator. These files do not enable tax calculations or set company tax profiles.

Quotes are excluded from this starter and preserved separately for a future plugin. Detailed historical journals, XLSX, multiple stock locations, serials/batches/expiry, landed cost, manufacturing, advances/unapplied credits/refunds, country tax adapters, rate providers, period-end FX revaluation, group consolidation, additional accounting books and document scanning remain future work. The illustrative POS does not use shared stock/COGS or document tax and has no credit-sale or payment-provider integration. See [RELEASE-NOTES.md](RELEASE-NOTES.md) for the boundaries.

## Package and operations

[INSTALL.md](INSTALL.md) covers a fresh database. [UPGRADE.md](UPGRADE.md) covers maintenance, backups and recovery. No automatic upgrade from the historical PHP Ledger application is provided.

`PACKAGE-MANIFEST.json` identifies the packaged files and source. Preserve the package, its published checksum, configuration backup and database backup together. Project terms are in [LICENSE](LICENSE); dependency and asset notices are in [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md).

The supplied chain extends through `031_posting_source_lookup`, with 32 migration receipts including both distinct `006_*` identities. Earlier migration names and checksums remain unchanged. Follow the upgrade guide before changing an existing database, and compare the installed receipts against every migration in the package manifest. Account IDs and posted history are retained, and existing-business opening reconciliation remains required. Installing optional modules does not activate them for existing companies; AR/AP and the manual tax engine belong to the required core. API/MCP financial mutations remain future work.

The archive excludes the old application, marketing website, development Docker setup, development/test suite, private configuration and customer data. The standalone `tools/validate-tax-catalog.php` is an optional structural check; it does not activate or approve tax research. There is no requirement to run Composer development scripts on the customer server.

## Learn and contribute

[Project website](https://phpledger.com/) · [Hosted evaluation demo](https://phpledger.com/demo/) · [Documentation](https://github.com/phpledger/phpledger/wiki) · [Issue tracker](https://github.com/phpledger/phpledger/issues)

The hosted demo uses temporary sample data and resets hourly; never enter customer information there. Report reproducible problems with the package version and a sample example, without passwords, database dumps or private records. Setup and pilot enquiries: [rmak78@gmail.com](mailto:rmak78@gmail.com).
