## Current package: 1.1.3

**1.1.3**, published 20 September 2026, fixes issue [#90](https://github.com/phpledger/phpledger/issues/90): the in-app updater now completes its migrate phase without error, so an installation on 1.1.2 with the key pinned installs 1.1.3 from `/maintenance.php` (older installations use the manual procedure, [issue #91](https://github.com/phpledger/phpledger/issues/91)). [[Release notes|Release-1.1.3]]. **1.1.2**, published 20 September 2026, corrects the 1.1.1 record below and restores signed update metadata, so an installation on 1.1.0 or 1.1.1 with the publisher key pinned installs it from `/maintenance.php`; it also counts cash at the point of sale in whole minor units ([issue #88](https://github.com/phpledger/phpledger/issues/88)). [[Release notes|Release-1.1.2]]. **1.1.1**, published 20 September 2026, rebuilds browser setup into six stages with green and red checks, accepts a local database account including `root` with no password and creates that database, and warns instead of refusing over plain HTTP. Correction of 20 September 2026: its notes say it carries no migration, but the package includes migration 034 and the optional Stock locations module; an installation upgraded from 1.1.0 by replacing files must run `install/migrate.php` once or install 1.1.2 from `/maintenance.php`. It has no signed update metadata. [[Release notes|Release-1.1.1]].

**1.1.0**, published 19 September 2026, installs like WordPress: upload the `phpledger` folder into any web folder and open its address, and the installer starts by itself. It adds MariaDB 10.4+, a chosen username, an optional logo, and the first signed update metadata. [Download 1.1.0](https://github.com/phpledger/phpledger/releases/tag/v1.1.0) and its [matching media kit](https://github.com/phpledger/phpledger/releases/download/v1.1.0/phpledger-1.1.0-media-kit.zip). [[Release notes|Release-1.1.0]]. **1.0.0**, published 18 September 2026, was the first stable release.

# The path after 1.1.3

With 1.1.3 published, the release sequence the owner set on 19 September 2026 is (status and dates in the [1.2 release plan](https://github.com/phpledger/phpledger/blob/master/docs/strategy/RELEASE-PLAN-1.2.md)):

1. **1.1.1 (published 20 September 2026)** shipped the Workbench installer, the local-database and plain-HTTP handling, the trigger preflight with resumable migrations, the single database account and the corrected demo labels. The first packaging step, the demo packs leaving the release ZIP, did not ship and moves to 1.2 with the plugin runtime. **1.1.2 (published 20 September 2026)** corrects the 1.1.1 record (migration 034 and the optional Stock locations module shipped unannounced), counts cash at the point of sale in whole minor units ([issue #88](https://github.com/phpledger/phpledger/issues/88)) and carries the first signed update metadata since 1.1.0, before the 1 October pilot.
2. **1.2 — stock locations, trading documents and plugins**, in this order:
   1. multiple stock locations with transfer documents and per-location moving weighted-average cost, as a bundled module (the code is on `master` and inside the 1.1.1 package; its release notes, wiki page, compatibility matrix and upgrade proof follow in 1.2);
   2. trading-document extensions as a bundled module: product packs with pack-and-unit entry, line discounts and free-goods lines, per-type document number series, sales-staff and area dimensions, cash received on the invoice, and printable invoice, receipt and statement templates;
   3. on-account receipts with oldest-first allocation, unapplied credit and batch receipts in core AR;
   4. the plugin runtime: hooks, manifest, loader, activation and plugin migrations with their own receipt table, independent of the table prefix, with an official verified marketplace plus owner uploads of any plugin ZIP behind warnings;
   5. the Users module capability catalogue and per-company roles;
   6. reviewed Urdu with right-to-left layout and English fallback;
   7. the container image on the release feed, opened once its upgrade path is documented.
3. **1.3:** aggregate payroll accounting and employee links; recurring/accrual/loan drafts; period and fiscal-year close/reopen; draft Arabic RTL; table prefixes and database TLS; MySQL 8.0.19+; optional installation notices; validated sample packages; shared installer demo and official GHCR/Docker Hub image channels. Native language review, provider acceptance and final publication evidence remain explicit gates.
4. **Later capability releases:** reviewed regional connectors, then a stock/tax-integrated shop POS, e-commerce, controlled API/MCP writes, and restaurant, pharmacy, exporter, freelancer, distribution and other specialists as directory plugins with paired sample packages. Each has its own acceptance gate.

**First supervised pilot:** the maintainer's own company runs its books on PHP Ledger from 1 October 2026 and completes a real month-end close; the accountant review is commissioned on that close. Independent security review, further pilots and the release-candidate period stay open. Sources: [roadmap](https://github.com/phpledger/phpledger/blob/master/docs/ROADMAP.md#following-stable), [platform sequence](https://github.com/phpledger/phpledger/blob/master/docs/strategy/PLATFORM-ROADMAP.md#sequence) and [decisions B20–B23](https://github.com/phpledger/phpledger/blob/master/docs/strategy/DECISION-REGISTER.md#h-decisions-taken-by-the-owner-19-september-2026-evening-later-session).

**Sample companies and plugins become separate packages (owner decision, 19 September 2026).** The core package will carry the accounting application and its bundled modules only. Sample companies and plugins are built, versioned and downloaded separately from a package directory on phpledger.com, the way WordPress serves themes and plugins, or uploaded by the owner. A sample package is data only and can declare the plugins it needs, Dependencies must already be installed and reviewed; a sample never silently installs or activates executable plugins. Only the small core-accounting sample stays bundled for first-run onboarding. Restaurant POS, pharmacy POS, exporter and freelancer invoicing are planned as the first directory plugins, each with a paired sample; core, AR, AP, Inventory and Purchasing stay bundled. Design, manifest and sequence: [package directory](https://github.com/phpledger/phpledger/blob/master/docs/strategy/PLATFORM-ROADMAP.md#package-directory-plugins-and-sample-companies) and [decisions B18 and B19](https://github.com/phpledger/phpledger/blob/master/docs/strategy/DECISION-REGISTER.md#g-decisions-taken-by-the-owner-19-september-2026). The rest of the platform work (table prefix, MeekroORM, a Users module) is in the same [platform roadmap](https://github.com/phpledger/phpledger/blob/master/docs/strategy/PLATFORM-ROADMAP.md).

The direction confirmed on 15 September 2026 is **complete accounting core first, optional business modules next**, with a business API and MCP access over the same services. 1.0.0 completes the required core and read API/MCP access; it does not complete the independent-review, pilot or release-candidate gates described in [Validation](https://github.com/phpledger/phpledger/blob/master/docs/VALIDATION.md) and [Release 1.0.0](https://github.com/phpledger/phpledger/wiki/Release-1.0.0).

1.0.0 completes the bounded core implementation, required AR/AP, optional Purchasing/Inventory and bundled module lifecycle technical checks, along with browser installation and signed automatic updates. Controlled API/MCP writes follow the 1.2 and 1.3 work above. Qualified reviews and observed pilot use remain separate, open gates.

The [[module roadmap|Module-Roadmap]] gives the detailed delivery order. [[First package|First-Package]] points to the 1.0.0 scope and remaining acceptance work.

| Stage | What it delivers | Completion gate |
|---|---|---|
| **1. Discovery and restart** | Preserved history, product scope, accounting examples, architecture, licence review and priorities | Owner/accountant decisions and source research support the scope. |
| **2. Experience and stack proof** | Selected design, onboarding/entry/report prototypes, authentication and transactions | Runtime checks and observed journeys support the design. |
| **3. Complete product slice** | Business setup, accounts, receipt/expense, journal, trial balance and reversal | Results reconcile and representative users complete the journey. |
| **4. Website and validation** | Website, shared installer demo, documentation, contributor paths and pilot interest | Accurate claims and working public journeys, with reviewers and prospective pilots. |
| **5. Milestone funding** | A costed next release and verified receiving route | Licence, eligibility, budget and delivery capacity are established. |
| **6. Complete accounting core and pilots** | Statements, chart, general journals, opening/cutover, periods, cash/bank reconciliation, reports/exports and recovery | A supported core-only business completes a period; accounting review and observed usability pass. |
| **7. Extension and integration foundation** | Module/dependency contracts, company capability gates, shared master data, business API and MCP | Scoped clients, consistent results, retained history and idempotent audited commands. |
| **8. Optional business modules** | AR, AP, purchasing/inventory, tax, shop and restaurant POS, distribution and specialists | Each module passes its own accounting and operational gates. |
| **9. Further regional and multi-book support** | Reviewed profiles/adapters, approved alternative-book model, translations and multicurrency | Defined entity/period support; every book and difference reconciles. |

## Required accounting progression

Account statements, chart management, general journals, opening conversion, bank reconciliation and required AR/AP with manually configured tax are shipped in 1.0.0's core. Historical import still needs preview of mappings, errors and totals before confirmation for cases not already covered.

AR/AP open-item records, ageing and control-account reconciliation are part of the 1.0.0 core; stock valuation belongs to the optional Inventory module, also shipped. An account statement alone does not provide ageing or stock reports on its own — use AR/AP and Inventory for those.

Financial reporting uses explicit, reviewed regional entity/period profiles over a country-neutral core. Pakistan FBR is one planned connector; the product is not defined by one country. Tax research runs alongside it, covers eight countries and seven industries, and remains disabled and unreviewed. [[Tax research|Tax-Research]] explains that separate boundary.

## Optional business expansion

- **Purchasing and Inventory (shipped, optional):** products, receiving/returns, one stock location (further locations and transfers are the first 1.2 module), moving weighted-average valuation and reviewed adjustments.
- **Tax (research only):** reviewed jurisdiction adapters, effective rules and immutable calculation snapshots; required before applicable production use. 1.0.0 ships only manually configured core tax codes and rates.
- **Shop POS (bundled module):** shared checkout, shop entry, returns and settlement controls. The bundled cash POS is an illustrative demonstration, not this production module.
- **Restaurant, distribution and specialists (directory plugins):** table/order/kitchen operations; route/van stock and collections; pharmacy batch/expiry; exporter documents; freelancer invoicing; jewelry pricing; membership dues; workshop jobs/parts/labour. Each ships from the package directory with a paired sample company rather than inside the core package.

The bundled core/POS lifecycle and required AR/AP are implemented. Controlled API/MCP write access follows the 1.2 and 1.3 releases. Optional software does not make legal obligations optional.

## Later investigations

Reviewed translations/RTL and number/date preferences must preserve stored amounts and business dates. Multicurrency needs immutable rate snapshots and reviewed rounding/revaluation. Alternative books require an approved meaning and reconciliation rules.

One **Scan document** action is planned to suggest editable fields from receipts, invoices and cheques for review before the normal save/post flow. It has no current extraction capability or next-sprint promise.

Offline synchronization, durable background events and native wrappers follow demonstrated need, with duplicate protection, recovery and authoritative posting.

Each release is scoped separately. Roadmap entries are not delivery dates, funding commitments or regulatory support claims.

[[Module roadmap|Module-Roadmap]] · [[First package|First-Package]] · [[Contributing and support|Contributing-and-Support]]

## Combined 0.2.1 preview

The owner approved combining read API/MCP, OAuth/Connections and server-side tables with four reconciled businesses, 2024–2025 history, open 2026 practice and reporting guides. The prior staged sequence is superseded. Existing accounting and POS capabilities remain included.

Each named client's compatibility requires its own executed connection and report checks. The verified-client preview can ship with unavailable clients explicitly pending. Protocol support or an OpenAPI fallback does not close those gates. The product remains country-neutral; Pakistan FBR is one planned regional connector. Next: AR, AP, distribution/updater tooling, reviewed regional connectors, inventory, shop POS, e-commerce and controlled commands.
