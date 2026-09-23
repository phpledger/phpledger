# Cash, bank controls, document parties, samples and guidance — local receipt

Status: local implementation, combined verification and exact-package checks are complete. No publication or deployment occurred.

## Scope and ownership

Base `1647c965`; branch `codex/cash-parties-sample-stories`; isolated worktree `.cache/cash-parties-sample-stories`. Root owns help, report grouping, manifests, integration and acceptance. Cash lane owns central controls/account configuration and website profiles/logos; party lane owns linked documents, migration050 and receipt/expense workspaces; sample lane owns versioned resources, stable stories/source resolution and staged practice-defect evidence. The eight originally modified website outputs in the main checkout were copied to `.cache/cash-plan-preservation`; both originals and backups have matching recorded SHA-256 values. No unrelated changes were reset, overwritten or staged.

## Confirmed decisions

The owner approved physical-cash blocking and linked parties, then explicitly approved physical/bank account classification and separately approved bank overdraft limits in this delivery. No cash-policy settings table or second ledger/party store was added. Existing names/codes do not imply a classification. Bank accounts default to zero borrowing allowance; only an explicit agreed limit permits negative units in the facility currency. An independent loan or credit line remains a liability; its unused limit is not bank funding.

Migration 050 adds the nullable indexed company-scoped party relationship and effective-view field. Migration 051 adds nullable physical/bank classification. Migration 052 adds disabled-by-default bank overdraft configuration with database constraints. All are new versioned migrations. Old journal values, source identifiers and migration receipts remain unchanged; no historical chart migration is rerun.

## Release content decision

The owner explicitly required the demo, documentation and website to be included and waived the media kit for this delivery. The bundled demo resources, existing documentation, website source and generated pages are updated locally. This is a delivery-specific waiver; it does not rewrite the repository release policy. Published version/download metadata remains unchanged because publication is outside the authorized scope. A future hosted release must publish the matching application, demo and website together; no hosted demo reset or website deployment has occurred here.

## Final verification results

- Focused MariaDB cash/bank: 49 tests, zero failures; party/HTTP: 39 tests, zero failures. The final combined gates also cover the later read-DTO/retry/package additions.
- Populated archived-baseline upgrades passed on MySQL 8.4 and MariaDB 10.11, preserving original columns/receipts, negative history and literal `null` name snapshots. New fields did not infer parties, classifications or facilities. The probes removed only their own random schemas.
- Browser: unfunded physical cash 25.00 refused and retained as draft; linked party inline creation retained amount/reference/memo through validation failure; separate fictional receipt 25.00 posted; exact-balance cash expense 25.00 posted; bank payment 10.00 refused with no facility; explicit agreed 10.00 facility saved through audited account form; exact-limit expense posted.
- Browser: receipt/expense workspace context, supplier selectable as payer, desktop 1440 / tablet 768 / phone 390 pixels, one page-help control, Escape, report expansion independent of help, viewport-contained sheet, print-hidden help, native help/select without JavaScript.
- Independent review fixed a foreign-currency physical-cash bypass, preserved omitted legacy draft party links, and recovered draft views after an account becomes inactive. Follow-up bank-policy review found no additional actionable issue.

## References and external boundary

Repository instructions, design/architecture/accounting/demo/development/integration/validation documents and the owner's pasted plan were used. No Google Drive document was required or read. Public read-only research answered the owner's comparison question:

- [Intuit: negative book balances and uncleared transactions](https://quickbooks.intuit.com/community/reports-and-accounting-5/why-is-my-account-showing-two-separate-balances-and-one-is-a-negative-balance-58848).
- [Intuit: separate line-of-credit liability and interest](https://quickbooks.intuit.com/learn-support/en-us/help-article/pay-bills/set-track-line-credit/L0KRxAKPI_US_en_US).
- [Intuit: an issued cheque subsequently bouncing](https://quickbooks.intuit.com/learn-support/en-us/help-article/pay-bills/manage-bounced-check-wrote/L26onIB9m_US_en_US).
- [ERPNext version 15 source: optional balance-sign enforcement](https://raw.githubusercontent.com/frappe/erpnext/version-15/erpnext/accounts/doctype/gl_entry/gl_entry.py). This is a version-specific source observation, not a claim of equivalent future-date/overdraft enforcement.
- [Akaunting: payment-account workflows](https://akaunting.com/hc/docs/banking-feeds-reconciliations/managing-accounts/). No documented hard bank-limit guarantee was established.

No production database, hosting/provider action, payment, message to stakeholders, publication, push or deployment occurred. Browser fixtures and database services are local and fictional. Dependency preparation used cached Docker images and Composer dependencies with networking disabled. Raw secrets exposed: no.

## Remaining acceptance boundaries

The other ten companies intentionally have profiles and scenario summaries while their enriched monthly journeys remain visibly incomplete. New guidance wording is marked as draft pending qualified accounting review. Passing local tests is not accounting assurance, accessibility certification, translation acceptance or observed real-business usability. Customer classification, real facility terms and existing deficits require explicit owner decisions/reconciliation.


## Sample outcomes

Current 1.1.0 packs and structures retain all 1.0.0 resources unchanged. All 11 sample replays passed 36 monthly checkpoints each (focused 31 tests, zero failures). Original historical source amounts and monthly totals are preserved. Six unfunded physical-cash transfers, 16 unfunded bank outflows across 9 samples, and 3 dependent reversals are explicit staged/unposted practice evidence; source IDs and amounts are retained. No funding or bank credit was invented. Twelve zero-balance structures include the Accounting starter. Cedar has 24 historical monthly chapters plus a separate 2026 practice chapter and eight quarterly teaching pairs; ten other journeys are clearly incomplete.

## Combined-run corrections

The initial full MariaDB run reported 625 tests / 7 failures; MySQL reported 625 / 6, with the cash-count fixture fix already loaded by its later stage. Six cases were older fixtures relying on unclassified/unfunded money or an implicit facility-currency change; one case was a bare country-code placeholder. Fixtures now explicitly classify and genuinely fund their intended scenarios, including owner equity before expenses, and confirm facility terms before testing currency immutability. The placeholder uses `pl_t()`; the untranslated ceiling remains 12. A temporary exact-name driver reran all 7 original failed closures from normal test definitions on each engine: 7 passed, zero failures each. Subsequent normal full `composer check` runs passed on both engines: 625 tests, zero failures each, 368 PHP files linted without failures, PHPStan clean and sample validation passed. The runtime was unchanged throughout these final runs.

## Reproducible commands and local evidence

The runtime under test is `005384204faed83499b6145c6c4a364042a2d8bb`. Full checks use the normal `composer check` command in the two disposable engine environments. Logs are retained under `.cache/full-mysql-verified.log` and `.cache/full-maria-verified.log`; both runs completed successfully with 625 tests and zero failures each.

Other commands completed: `python -X utf8 tools/build-demo-packs.py --check`, `python -X utf8 tools/build-sample-structures.py --check`, `python -X utf8 tests/sample-learning-test.py` (9 tests), `python -X utf8 tests/package-builder-test.py` (13 tests), `node --test www/website/sample-company-pages.test.mjs` (3 tests), and `node www/website/build.mjs --check` (87 pages, zero errors or warnings). All passed. Applicable JavaScript syntax checks passed. The changed-file encoding audit and `git diff --check 1647c965` were clean.

Exact local archive: `.cache/cash-party-package-verified/phpledger-1.2.1.zip`, SHA-256 `b86fed0ef76488a7587b6b05dd44c2678c491d7943cf7b70baec11e08df05868`. The existing package version is a local validation artifact, not a newly published release. `tools/verify-m17-package.php` passed on both engines against the extracted unchanged payload: 53 fresh migrations, linked receipt snapshot, physical cash overspend refusal, exact bank limit acceptance/excess refusal, employee save, cash-count difference, checklist, encrypted round-trip, balanced reports and no-op migration replay. Evidence: `.cache/package-verified-codexcashplan-test.log` and `.cache/package-verified-codexcashplan-mariatest.log`.

`tools/verify-cash-party-upgrade.php` tested populated archived-baseline upgrades on both engines. Evidence: `.cache/upgrade-mysql-final-seed.log`, `.cache/upgrade-mysql-final-check.log`, `.cache/upgrade-maria-final-seed.log`, and `.cache/upgrade-maria-final-check.log`. Only disposable local databases were migrated.

Browser workflows used `/transactions?kind=expense`, `/transactions?kind=receipt`, their editor/detail/post/reversal journeys, account configuration, `/reports/trial-balance`, `/sample-chooser`, `/sample-guide` and a company-scoped `/journals/detail` source link. Website checks covered `/sample-companies/` and `/sample-companies/service-agency/`. Actual interactions included inline creation validation/cancellation, keyboard party selection, draft preservation, exact cash/overdraft balances, excess refusal, linked reversal, mobile overflow, independent report help, Escape dismissal, no-JavaScript fallback and print exclusion. Viewports: 1440, 768 and 390 pixels. Fictional Cedar provisioning and the historical chapter source link were exercised in the visitor's own company.

## Exact changed-file inventory

Repository-relative implementation delta from `1647c965`:

- `AGENTS_SYNC.MD`
- `AGENT_MESSAGES.MD`
- `docs/ARCHITECTURE.md`
- `docs/DEMO.md`
- `docs/DESIGN.md`
- `docs/DEVELOPMENT.md`
- `docs/INTEGRATIONS.md`
- `docs/VALIDATION.md`
- `docs/accounting/CORE_RULE_REGISTER.md`
- `docs/design/website/qa/static-checks.json`
- `docs/repository/CASH-PARTIES-SAMPLES-2026-09-23.json`
- `docs/repository/CASH-PARTIES-SAMPLES-2026-09-23.md`
- `resources/demo-packs/catalog.json`
- `resources/demo-packs/distributor-1.1.0.json`
- `resources/demo-packs/jewelry-studio-1.1.0.json`
- `resources/demo-packs/light-manufacturing-1.1.0.json`
- `resources/demo-packs/membership-club-1.1.0.json`
- `resources/demo-packs/pharmacy-1.1.0.json`
- `resources/demo-packs/restaurant-1.1.0.json`
- `resources/demo-packs/retail-shop-1.1.0.json`
- `resources/demo-packs/seasonal-business-1.1.0.json`
- `resources/demo-packs/service-agency-1.1.0.json`
- `resources/demo-packs/service-workshop-1.1.0.json`
- `resources/demo-packs/trader-1.1.0.json`
- `resources/guidance/README.md`
- `resources/guidance/concepts/cash-availability.php`
- `resources/guidance/concepts/page-access.php`
- `resources/guidance/concepts/page-assets.php`
- `resources/guidance/concepts/page-chart.php`
- `resources/guidance/concepts/page-commercial.php`
- `resources/guidance/concepts/page-extensions.php`
- `resources/guidance/concepts/page-forecast.php`
- `resources/guidance/concepts/page-getting-started.php`
- `resources/guidance/concepts/page-help.php`
- `resources/guidance/concepts/page-installation.php`
- `resources/guidance/concepts/page-journals.php`
- `resources/guidance/concepts/page-money-documents.php`
- `resources/guidance/concepts/page-opening.php`
- `resources/guidance/concepts/page-overview.php`
- `resources/guidance/concepts/page-ownership.php`
- `resources/guidance/concepts/page-parties.php`
- `resources/guidance/concepts/page-people.php`
- `resources/guidance/concepts/page-periods.php`
- `resources/guidance/concepts/page-pos.php`
- `resources/guidance/concepts/page-reconciliation.php`
- `resources/guidance/concepts/page-reports.php`
- `resources/guidance/concepts/page-settings.php`
- `resources/guidance/concepts/page-stock.php`
- `resources/guidance/concepts/transaction-party.php`
- `resources/guidance/pages.php`
- `resources/guidance/routes.php`
- `resources/modules/core.json`
- `resources/sample-structures/accounting-starter-1.1.0.json`
- `resources/sample-structures/distributor-1.1.0.json`
- `resources/sample-structures/jewelry-studio-1.1.0.json`
- `resources/sample-structures/light-manufacturing-1.1.0.json`
- `resources/sample-structures/membership-club-1.1.0.json`
- `resources/sample-structures/pharmacy-1.1.0.json`
- `resources/sample-structures/restaurant-1.1.0.json`
- `resources/sample-structures/retail-shop-1.1.0.json`
- `resources/sample-structures/seasonal-business-1.1.0.json`
- `resources/sample-structures/service-agency-1.1.0.json`
- `resources/sample-structures/service-workshop-1.1.0.json`
- `resources/sample-structures/trader-1.1.0.json`
- `resources/ui/ext/guidance.css`
- `resources/ui/ext/reports.css`
- `resources/ui/ext/setup.css`
- `tests/cash_control_test.php`
- `tests/cash_count_test.php`
- `tests/concurrency_worker.php`
- `tests/currency_test.php`
- `tests/demo_pack_test.php`
- `tests/document_party_http_test.php`
- `tests/document_party_test.php`
- `tests/document_test.php`
- `tests/guidance_test.php`
- `tests/i18n_test.php`
- `tests/ledger_test.php`
- `tests/onboarding_skeleton_test.php`
- `tests/ownership_test.php`
- `tests/report_tree_test.php`
- `tests/run.php`
- `tests/sample-learning-test.py`
- `tools/build-demo-packs.py`
- `tools/build-sample-structures.py`
- `tools/package-files.json`
- `tools/sample_pack_learning.py`
- `tools/verify-cash-party-upgrade.php`
- `tools/verify-m17-package.php`
- `www/phpledger/includes/functions/core_functions.php`
- `www/phpledger/includes/functions/correction_functions.php`
- `www/phpledger/includes/functions/demo_pack_functions.php`
- `www/phpledger/includes/functions/document_functions.php`
- `www/phpledger/includes/functions/guidance_functions.php`
- `www/phpledger/includes/functions/install_check_functions.php`
- `www/phpledger/includes/functions/ledger_functions.php`
- `www/phpledger/includes/functions/read_functions.php`
- `www/phpledger/includes/functions/report_functions.php`
- `www/phpledger/includes/functions/sample_structure_functions.php`
- `www/phpledger/includes/functions/setup_functions.php`
- `www/phpledger/includes/functions/web_functions.php`
- `www/phpledger/install/migrations/050_document_parties.php`
- `www/phpledger/install/migrations/051_money_account_kind.php`
- `www/phpledger/install/migrations/052_bank_overdraft_limits.php`
- `www/phpledger/public/assets/app.css`
- `www/phpledger/public/assets/app.js`
- `www/phpledger/public/assets/help.js`
- `www/phpledger/public/assets/party-picker.js`
- `www/phpledger/public/assets/sample-companies/distributor.svg`
- `www/phpledger/public/assets/sample-companies/jewelry-studio.svg`
- `www/phpledger/public/assets/sample-companies/light-manufacturing.svg`
- `www/phpledger/public/assets/sample-companies/membership-club.svg`
- `www/phpledger/public/assets/sample-companies/pharmacy.svg`
- `www/phpledger/public/assets/sample-companies/restaurant.svg`
- `www/phpledger/public/assets/sample-companies/retail-shop.svg`
- `www/phpledger/public/assets/sample-companies/seasonal-business.svg`
- `www/phpledger/public/assets/sample-companies/service-agency.svg`
- `www/phpledger/public/assets/sample-companies/service-workshop.svg`
- `www/phpledger/public/assets/sample-companies/trader.svg`
- `www/phpledger/public/index.php`
- `www/phpledger/templates/layout.php`
- `www/phpledger/templates/partials/ui/components.php`
- `www/phpledger/templates/partials/ui/report-tree.php`
- `www/phpledger/templates/partials/ui/shell.php`
- `www/phpledger/templates/partials/ui/unavailable.php`
- `www/phpledger/templates/views/accounts.php`
- `www/phpledger/templates/views/balance-sheet.php`
- `www/phpledger/templates/views/editor.php`
- `www/phpledger/templates/views/help.php`
- `www/phpledger/templates/views/install.php`
- `www/phpledger/templates/views/profit-loss.php`
- `www/phpledger/templates/views/sample-chooser.php`
- `www/phpledger/templates/views/sample-guide.php`
- `www/phpledger/templates/views/transactions.php`
- `www/phpledger/templates/views/trial-balance.php`
- `www/website/README.md`
- `www/website/build.mjs`
- `www/website/public/404.html`
- `www/website/public/about/index.html`
- `www/website/public/assets/sample-companies/distributor.svg`
- `www/website/public/assets/sample-companies/jewelry-studio.svg`
- `www/website/public/assets/sample-companies/light-manufacturing.svg`
- `www/website/public/assets/sample-companies/membership-club.svg`
- `www/website/public/assets/sample-companies/pharmacy.svg`
- `www/website/public/assets/sample-companies/restaurant.svg`
- `www/website/public/assets/sample-companies/retail-shop.svg`
- `www/website/public/assets/sample-companies/seasonal-business.svg`
- `www/website/public/assets/sample-companies/service-agency.svg`
- `www/website/public/assets/sample-companies/service-workshop.svg`
- `www/website/public/assets/sample-companies/trader.svg`
- `www/website/public/assets/site.css`
- `www/website/public/community/index.html`
- `www/website/public/compare/akaunting-alternative/index.html`
- `www/website/public/compare/bigcapital-alternative/index.html`
- `www/website/public/compare/dolibarr-alternative/index.html`
- `www/website/public/compare/erpnext-alternative/index.html`
- `www/website/public/compare/frontaccounting-alternative/index.html`
- `www/website/public/compare/index.html`
- `www/website/public/compare/open-source-accounting-software/index.html`
- `www/website/public/credits/index.html`
- `www/website/public/download/index.html`
- `www/website/public/for/accountants/index.html`
- `www/website/public/for/analysts/index.html`
- `www/website/public/for/index.html`
- `www/website/public/for/small-businesses/index.html`
- `www/website/public/for/software-houses/index.html`
- `www/website/public/glossary/index.html`
- `www/website/public/guides/bank-reconciliation-walkthrough/index.html`
- `www/website/public/guides/correcting-a-posted-entry/index.html`
- `www/website/public/guides/daily-cash-check/index.html`
- `www/website/public/guides/first-week-on-the-books/index.html`
- `www/website/public/guides/index.html`
- `www/website/public/guides/monthly-closing/index.html`
- `www/website/public/guides/opening-balances-cutover/index.html`
- `www/website/public/guides/quarterly-yearly-review/index.html`
- `www/website/public/guides/retail-two-year-statements/index.html`
- `www/website/public/index.html`
- `www/website/public/learn/bank-reconciliation/index.html`
- `www/website/public/learn/cash-vs-accrual/index.html`
- `www/website/public/learn/chart-of-accounts/index.html`
- `www/website/public/learn/correcting-mistakes-reversal-vs-edit/index.html`
- `www/website/public/learn/debits-and-credits/index.html`
- `www/website/public/learn/double-entry-bookkeeping/index.html`
- `www/website/public/learn/index.html`
- `www/website/public/learn/journal-entry/index.html`
- `www/website/public/learn/opening-balances-and-cutover/index.html`
- `www/website/public/learn/profit-and-loss-vs-balance-sheet/index.html`
- `www/website/public/learn/trial-balance/index.html`
- `www/website/public/llms.txt`
- `www/website/public/news/0-1-0-preview/index.html`
- `www/website/public/news/0-1-3-preview/index.html`
- `www/website/public/news/0-1-4-preview/index.html`
- `www/website/public/news/0-1-5-preview/index.html`
- `www/website/public/news/0-1-6-preview/index.html`
- `www/website/public/news/0-2-1-preview/index.html`
- `www/website/public/news/0-3-0-preview/index.html`
- `www/website/public/news/0-4-0-preview/index.html`
- `www/website/public/news/0-5-0-preview/index.html`
- `www/website/public/news/0-6-0-preview/index.html`
- `www/website/public/news/1-0-0/index.html`
- `www/website/public/news/1-1-0/index.html`
- `www/website/public/news/1-1-1/index.html`
- `www/website/public/news/1-1-2/index.html`
- `www/website/public/news/1-1-3/index.html`
- `www/website/public/news/index.html`
- `www/website/public/point-of-sale/index.html`
- `www/website/public/pricing/index.html`
- `www/website/public/privacy/index.html`
- `www/website/public/product/index.html`
- `www/website/public/research/fbr-digital-invoicing/index.html`
- `www/website/public/research/index.html`
- `www/website/public/research/open-source-accounting-landscape/index.html`
- `www/website/public/research/pakistan-sme-bookkeeping/index.html`
- `www/website/public/research/php-hosting-availability-2026/index.html`
- `www/website/public/roadmap/index.html`
- `www/website/public/sample-companies/distributor/index.html`
- `www/website/public/sample-companies/index.html`
- `www/website/public/sample-companies/jewelry-studio/index.html`
- `www/website/public/sample-companies/light-manufacturing/index.html`
- `www/website/public/sample-companies/membership-club/index.html`
- `www/website/public/sample-companies/pharmacy/index.html`
- `www/website/public/sample-companies/restaurant/index.html`
- `www/website/public/sample-companies/retail-shop/index.html`
- `www/website/public/sample-companies/seasonal-business/index.html`
- `www/website/public/sample-companies/service-agency/index.html`
- `www/website/public/sample-companies/service-workshop/index.html`
- `www/website/public/sample-companies/trader/index.html`
- `www/website/public/self-hosting/backup-and-restore/index.html`
- `www/website/public/self-hosting/docker/index.html`
- `www/website/public/self-hosting/index.html`
- `www/website/public/self-hosting/php-version-compatibility/index.html`
- `www/website/public/self-hosting/requirements/index.html`
- `www/website/public/self-hosting/shared-hosting-cpanel/index.html`
- `www/website/public/self-hosting/vps-install/index.html`
- `www/website/public/sitemap.xml`
- `www/website/public/terms/index.html`
- `www/website/sample-company-pages.mjs`
- `www/website/sample-company-pages.test.mjs`
- `www/website/src/css/31-sample-companies.css`
- `www/website/src/partials/footer.html`

Task-owned test database/helper/browser containers and network, acceptance browser, and local static preview server were closed after evidence capture. Logs, screenshots, validation archive and isolated source branch are retained; other projects were untouched.
