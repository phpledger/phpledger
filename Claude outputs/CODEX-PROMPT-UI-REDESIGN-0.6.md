# Codex prompt: 0.6.0-preview interface rebuild and release

Written 17 September 2026 after the owner approved the redesign prototype. Paste everything below the line into Codex at the repository root.

---

You are working in the PHP Ledger repository. Create and work on a new branch **`codex/ui-redesign-0.6`, branched from `master`**. This task rebuilds every application screen on the approved 0.5 redesign, builds the fourteen approved interface improvements (three of which touch accounting services), and then packages and publishes **0.6.0-preview**.

The owner has explicitly authorised, on 17 September 2026: the interface rebuild, the fourteen improvements below, removal of DataTables, the version bump, the package build, the GitHub release, the hosted demo update and the website download/screenshot update. No other live action is authorised.

## Read first, in this order

1. `docs/design/redesign-0.5/README.md` — owner decisions, the approved improvements, the build plan. This document is the specification for the rebuild.
2. `docs/design/redesign-0.5/BRIEF.md` — visual system, information architecture, the nine page patterns, fold rules, RTL and accessibility conventions.
3. `docs/design/redesign-0.5/COMPONENTS.md` — the component vocabulary you are porting.
4. The prototype itself: open `docs/design/redesign-0.5/dist/index.html` in a browser, and read `docs/design/redesign-0.5/src/app.css`, `src/shell.html`, `src/proto.js` and every file under `docs/design/redesign-0.5/screens/`. **The prototype is the visual and interaction contract. Each screen file names the route it belongs to in its `<!--meta …-->` header.**
5. `AGENTS.md`, `docs/ARCHITECTURE.md`, `docs/DESIGN.md`, `docs/ROADMAP.md`.
6. `docs/design/ux-recovery/README.md`, `SETUP-DAILY-AUDIT.md`, `OPERATIONS-AUDIT.md`, `REPORTING-ADMIN-AUDIT.md` — the P1/P2 findings this rebuild is expected to close.
7. `docs/strategy/DECISION-REGISTER.md` (§B7 correction model), `docs/strategy/MULTI-CURRENCY.md` §4 (realised FX on settlement), `docs/ERPNEXT-REVIEW.md` §0 (one posting funnel).

If the prototype and a current screen disagree, the prototype wins on layout, wording and interaction; the current code wins on accounting behaviour, permissions and posting rules. Where the two genuinely conflict, stop and record the conflict in `docs/design/redesign-0.5/IMPLEMENTATION-NOTES.md` instead of resolving it unilaterally.

## Non-negotiable constraints

- No new framework, ORM, router, authentication stack or second database layer. Modular PHP with MeekroDB, `www/phpledger` only, document root stays `www/phpledger/public`.
- The Content-Security-Policy in `public/index.php` stays exactly as strict as it is: `script-src 'self'; style-src 'self'`. **No inline `style=""`, no inline `on*=` handlers, no CDN, no `eval`.** Behaviour attaches through `data-*` attributes in one small vanilla-JS file, the way `docs/design/redesign-0.5/src/proto.js` does.
- Tailwind is a **development-time dependency only**. The compiled stylesheet is committed and shipped; a VPS installing the package must never need Node, npm or a build step. Add the Tailwind CLI to `package.json` (dev dependency) with an `npm run build:css` script, commit the generated `www/phpledger/public/assets/app.css`, and document the workflow in `docs/DEVELOPMENT.md`. The package build must include the compiled CSS and exclude `node_modules`, the Tailwind config sources and the prototype folder.
- Fixed-precision money, one posting funnel, immutable posted entries, reversal-and-repost under the same document identity (register B7), server-side company/book scoping, CSRF on every browser POST, contextual escaping, bound parameters. The rebuild must not weaken any of this.
- Logical CSS properties only (`ms-`, `me-`, `ps-`, `pe-`, `start-`, `end-`, `text-start`, `text-end`), so the planned Urdu/Arabic RTL work is a `dir` switch and not a rewrite.
- Desktop and tablet are the acceptance targets: **1366×768 and 1024×768**. Screens must still be usable and free of horizontal overflow at 768×1024 and at phone width, but phone layout refinement is explicitly deferred.

## Phase 1 — foundation in the application

1. Port the design tokens and component layer from `docs/design/redesign-0.5/src/app.css` into the application's Tailwind entry stylesheet. Keep the tokens as CSS variables on `:root` re-exposed through `@theme`, so a dark theme is later a variable swap. Do not ship a dark theme now.
2. Port the shell from `src/shell.html` into `templates/layout.php`: sidebar with the company switcher (this replaces the separate company bar), the navigation groups exactly as the prototype orders them, collapse-to-rail below 1180px, drawer below 900px, and the 48px top bar with breadcrumbs, the Ctrl-K search/jump, the global "+ New" quick-create menu, the sample-guide link and the user menu. Module-gated items (point of sale, purchasing, inventory) keep their existing visibility rules.
3. Add PHP view helpers under `www/phpledger/templates/partials/ui/` — page header, status badge, table, pagination, field, document header, totals block, context strip, empty state, tabs, stepper, side panel, dialog — mirroring the prototype's component classes one to one. **Screens must be built from these helpers, not hand-written markup, so the system cannot drift.** Extend `pl_icon()` with the icons the prototype uses.
4. Port `src/proto.js` behaviours into the application's single `assets/app.js`: menus, tabs, dismissible strips, sidebar collapse and drawer with the active item scrolled into view, the command palette over the real route list, add/remove line rows, split-view row selection. Keyboard reachable, visible focus, `prefers-reduced-motion` respected, and every behaviour degrades to a working server-rendered page without JavaScript.
5. Retire `core.css`, `starter.css`, `ledger-tables.css`, `sample-guide.css` and `pos.css`, folding what they do into the new system.

### DataTables removal and server-side lists

Remove `public/assets/vendor/datatables-3.0.4/` and `assets/ledger-tables.js`. Replace them with a server-side list contract that gives the same three capabilities the owner uses it for — paging, search and filtering — without the dependency:

- A single helper (for example `pl_list_query()`) reads `page`, `per_page`, `q`, `sort`, `dir` and the screen's own filters from `$_GET`, validates `sort` against an allow-list of columns per screen, and returns rows plus a total count using SQL `LIMIT`/`OFFSET` and bound parameters. Never interpolate a sort column or direction into SQL.
- The filter bar is an ordinary `GET` form. Sortable column headers are links carrying the current filters. Pagination is links. So filter state lives in the URL: bookmarkable, shareable, survives the back button, and works with JavaScript disabled — which the current DataTables setup does not.
- Keep the existing `/tables` JSON endpoint working for API consumers, but no screen depends on it for rendering.
- Optional progressive enhancement, only if it costs little: submit the filter form on change and replace the table body via `fetch` against the same URL, with the full page load as the fallback. No new library.

Run the existing list screens (transactions, journals, account statement, bank reconciliation) at ~5,000 rows and confirm paged queries stay indexed and fast; add indexes where the query plan shows a scan.

## Phase 2 — rebuild every screen

Rebuild all 38 HTML routes against the matching prototype screens (75 states in total). Work in this order, and keep every commit green:

1. **Access and setup** — login, businesses, sample chooser, the six-step onboarding, setup review, opening balances, opening-document conversion preview and confirmation, sample guide, help, public demo, errors (404/405/unavailable), OAuth consent.
2. **Daily work** — receipts and expenses list with detail panel, expense and receipt editors, edit with unsaved-changes state, posted and reversed record views, journals list, journal editor with live debit/credit totals and difference, posted and reversed journals, journal drill-down from a report, and the four point-of-sale screens.
3. **Sales, purchases, inventory** — invoices, bills, customer receipt and supplier payment, credit note, customers and suppliers, purchase orders, goods receipt, products and stock, stock count, tax codes.
4. **Reports and administration** — reports hub, profit & loss, balance sheet, trial balance, account statement, cash forecast, report-to-account-to-source drill-down, chart of accounts and account form, periods, bank reconciliation, modules, connections.

Rules for the port: keep every real field, action, status word and safety message the current screens carry ("Posting adds this transaction to reports. A saved draft does not change your books."), keep entered values on validation errors with a field-level message plus a focusable summary, keep status readable without colour, right-align amounts with tabular figures, and keep the drill-down path back to the source document.

## Phase 3 — the fourteen approved improvements

All fourteen are owner-approved, including the last three, which change accounting services. Each one needs tests.

**Interface only**

1. **Home** (`/home`, and `/` redirects there for a signed-in user with a selected book): drafts awaiting posting, overdue receivables, bills due, unreconciled bank lines, setup readiness, cash and bank balances, receivable and payable totals, quick actions, recent activity. Every figure comes from an existing service; invent no new calculation.
2. **One line first**: every line editor (invoice, bill, purchase order, credit note, journal, stock count) starts with one line and an "Add line" control instead of five or ten fixed blank groups. Closes UX audit P1.
3. **Live line amounts and running totals** while editing, computed client-side for display and recomputed server-side on save; the server value is authoritative.
4. **Posting preview inside the editor**, produced by the same posting service that will write the entry, never a second implementation of the same rules.
5. **Post from the journal editor** once the entry balances, alongside "Save draft".
6. **Stock-count preview** (recorded → counted → difference → value effect) and a **goods-receipt stock/GRNI effect preview** before confirmation.
7. **Receivables and payables ageing report** as a first-class report with 30/60/90 buckets, drill-through to documents, reconciled to the control accounts.
8. **Confirmation dialogs** before disabling a module or revoking a connection, naming the consequence.
9. **Connection scope choice** (report-only vs full read), enforced server-side on every API and MCP request, not only in the UI.
10. **Drill-down back links preserve the date range and filters**; the journals list gains status and text filtering.
11. **Account form consequence hint** (which statement the account will appear on) and a **warning before deactivating an account with an operational role** (cash/bank, receivable, payable, tax) — a warning, not a new lock.
12. **Point-of-sale cashier identity**: record which user made the sale on the sale and its journal source reference, and show it on the receipt. No till or shift management.

**Service changes (design carefully, test hard)**

13. **Settle one payment across several open items.** Today a receipt or payment settles one open item per call. Extend the settlement service to accept a set of allocations in one transaction: each allocation capped at that document's remaining balance, the whole set rejected atomically if any line fails, over-allocation and duplicate allocation impossible, and the unallocated remainder handled explicitly — either rejected, or posted to the party's control account as an on-account balance. Choose one, document it, and make the screen say which. Idempotent under retry. The open-item ledger stays the single source of truth.
14. **Realised FX fields only where they apply, and cost of sales on the profit & loss.** Two related report/settlement corrections:
    - A settlement in the book's functional currency with no exchange difference must not require gain or loss accounts, in the service validation as well as in the form (UX audit P1). Where a difference does exist, the existing realised-FX posting path stays exactly as it is (`MULTI-CURRENCY.md` §4.1). Test: domestic settlement with no FX accounts configured; foreign settlement with a difference; foreign settlement netting to zero.
    - Add an account classification for cost of sales (a new role or sub-type, with its migration and an admin way to set it), default it sensibly for new charts, and leave existing accounts unclassified. The profit & loss then returns Income, Cost of sales, Gross profit, Expenses, Net profit, and gains the period presets (this month, last month, this quarter, this year, custom) that fill the date fields. An unclassified chart must produce exactly today's numbers — no expense account may silently move between sections.

Migrations continue the existing chain (next number `029`), are versioned and idempotent, and must run cleanly both on a fresh install and on an upgrade from 0.5.0-preview data.

## Phase 4 — verification, before any release step

State exactly what you ran and what you skipped. Do not claim a passing build is accounting review or observed usability.

1. `composer check` (lint, PHPStan, sample validation, tests) green. Add tests for every service change in phase 3 and for the list-query helper (sort allow-list, filter persistence, pagination boundaries).
2. Fresh install and upgrade both verified against a real database in Docker: `install/migrate.php` from empty, and from a 0.5.0-preview dataset, using `tools/verify-upgrade.php` and the starter/currency verifiers.
3. Browser evidence with the existing isolated Playwright setup: **every one of the 38 routes captured at 1366×768 and 1024×768**, plus 768×1024 for a representative ten. For each capture assert: no horizontal page overflow, no console errors, and the screen's primary action visible without scrolling. Store the run under `docs/design/redesign-0.5/evidence-0.6.0/` with an index that pairs each capture with its prototype screen.
4. Accessibility pass on the shell and on one screen per pattern: keyboard-only path through the task, visible focus, labels associated, status not by colour alone, contrast checked against the token values. Record gaps rather than claiming WCAG conformance.
5. Re-check the P1/P2 findings in the three UX audits and record, per finding, whether this release closes it.
6. Confirm the package contains the compiled CSS and no development artefacts: no `node_modules`, no Tailwind sources, no prototype folder, no DataTables leftovers.

## Phase 5 — release 0.6.0-preview (authorised)

1. Bump `pl_app_version()` to `0.6.0-preview` and every version reference that follows it (README, release resources, demo config, website download copy).
2. Write `resources/release/RELEASE-NOTES.md` for 0.6.0-preview: the interface rebuild, the fourteen improvements, the DataTables removal and what replaces it, the upgrade path, and an honest limits section (development preview; mobile deferred; no accounting sign-off; no WCAG certification).
3. Update the documentation that this release makes stale: `docs/DESIGN.md` (record that the 0.5 redesign supersedes the Review Console selection and the three candidate shells, and that P0.4 is now decided), `docs/DEVELOPMENT.md` (Tailwind workflow, list-query contract), `docs/ARCHITECTURE.md`, `docs/ROADMAP.md`, `README.md`, the in-app help, and `docs/design/redesign-0.5/README.md` (mark it implemented, link the evidence run).
4. Build the package with `tools/build-package.py --version 0.6.0-preview`, with its SHA-256, plus the upgrade notes and the release media kit following the existing release convention. Screenshots in the media kit must be from this release's verified captures, with captions and alt text, showing synthetic sample data only.
5. Publish: create the GitHub release `v0.6.0-preview` with the archive, checksum and media kit; update the hosted demo to this release **after** taking a backup and confirming the reset job, synthetic-data isolation and disabled destructive actions still hold; update the website download links, version references and screenshots. Verify each published surface after the fact and record the receipts in `docs/VALIDATION.md` and a new `docs/repository/PUBLICATION-2026-09-17.md`.
6. Never publish real or customer data, credentials, or a screenshot of a real book. If any verification step in phase 4 fails, stop before phase 5 and report.

## Explicitly not in this task

- Dark theme, mobile layout refinement, Urdu/Arabic translation (keep the markup RTL-ready, ship no translations).
- Any new accounting capability beyond the fifteen numbered items: no new tax rules, no country packages, no FBR integration, no offline queue, no website builder.
- Rate providers, period-end revaluation, consolidation.
- Changing the posting funnel, the correction model or period locking.
- Restoring any legacy root application code.

## Working rules

- Commit the prototype itself as the design reference in your first commit: `docs/design/redesign-0.5/` **excluding** `dist/`, `node_modules/` and `src/fonts/` (the font already ships in `www/phpledger/public/assets/fonts/`). Add the excluded paths to `.gitignore`.
- Commit in reviewable groups: (1) Tailwind build and tokens, (2) shell and UI partials, (3) list-query helper and DataTables removal, (4) each screen lane as its own commit, (5) each service change with its tests, (6) documentation, (7) release artefacts. Push the branch; open a pull request; publish only at phase 5.
- After each lane, run the browser captures for that lane and fix what they show before moving on.
- Report at the end: files changed, migrations added, tests run and their results, routes captured, audit findings closed, secrets exposure (none expected), live actions taken with timestamps, and the risks that remain open.
