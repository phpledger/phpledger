## Current package: 1.1.2

**1.1.2**, published 20 September 2026, corrects the 1.1.1 record (its package carries migration 034 and the optional Stock locations module, off by default), counts cash at the point of sale in whole minor units ([issue #88](https://github.com/phpledger/phpledger/issues/88)), and restores signed update metadata, but a defect found the same night ([issue #90](https://github.com/phpledger/phpledger/issues/90)) stops the in-app updater at its migrate step, so until 1.1.3 upgrade by replacing files and running `install/migrate.php` once. [Download 1.1.2](https://github.com/phpledger/phpledger/releases/tag/v1.1.2) and see the [[1.1.2 release notes|Release-1.1.2]]. The public demo still runs 1.1.0.

**1.1.1**, published 20 September 2026, rebuilds browser setup into six stages with green and red checks, accepts the database account XAMPP and Laragon install on your own computer including `root` with no password, creates that database itself, and warns instead of refusing over plain HTTP. It was announced as carrying no migration, but its package includes migration 034 and the optional Stock locations module; an installation upgraded from 1.1.0 by replacing files must run `php www/phpledger/install/migrate.php` once or install 1.1.2 from `/maintenance.php`. It shipped without signed update metadata. See the [[1.1.1 release notes|Release-1.1.1]].

**1.1.0**, published 19 September 2026, installs like WordPress: upload the `phpledger` folder into any web folder and open its address, and the installer starts by itself. It adds MariaDB 10.4+, a chosen username, an optional logo, and the first signed update metadata. [Download 1.1.0](https://github.com/phpledger/phpledger/releases/tag/v1.1.0) and its [matching media kit](https://github.com/phpledger/phpledger/releases/download/v1.1.0/phpledger-1.1.0-media-kit.zip). [[Release notes|Release-1.1.0]]. **1.0.0**, published 18 September 2026, was the first stable release.

**Assurance status:** automated test suites, fault-injection update/recovery tests, exact-artifact install/upgrade/recovery checks and developer-operated browser checks back this release. Independent accounting review, independent security review, supervised pilots with a real month-end close, unfamiliar-operator installation observation and restricted shared-host recovery certification have **not** happened; the owner published 1.0.0 with these limits disclosed, as post-release commitments. See [[Release 1.0.0|Release-1.0.0]] and [[Getting started|Getting-Started]].

<p><img src="https://raw.githubusercontent.com/phpledger/phpledger/master/docs/repository/assets/phpledger-logo.webp" alt="PHP Ledger" width="320"></p>

# PHP Ledger

**PHP Ledger is open-source, self-hosted double-entry accounting software with a simple cash point of sale for small businesses, built on PHP 8.2+ (8.3 recommended) with MySQL 8.4 or MariaDB 10.4+. 1.1.2 is the current release; 1.0.0 was the first stable release.**

PHP Ledger serves small-business owners, accountants and bookkeepers who want a useful workspace on hosting they control, installable from a browser without a terminal. The direction is a complete accounting core, followed by optional business modules.

Explore [phpledger.com](https://phpledger.com/) and [your temporary sample company](https://phpledger.com/demo/). The [1.0.0 package](https://github.com/phpledger/phpledger/releases/tag/v1.0.0) includes universal account statements, chart management and saved general journals alongside the accounting starter. Evaluate it with synthetic data; independent review and pilot use remain in progress.

Modern application source is in `www/phpledger`; the historical application remains only in Git history. See [[Getting started|Getting-Started]] for the package, installation requirements and demonstration limits.

## Find your starting point

| I want to… | Start here |
|---|---|
| Understand the product and who it serves | [[Product overview|Product-Overview]] |
| Try the demo or plan an installation | [[Getting started|Getting-Started]] |
| Follow accounts, journals and report balances | [[Accounting and reports|Accounting-and-Reports]] |
| See how a shop sale reaches the ledger | [[POS showcase|POS-Showcase]] |
| Understand core and optional module delivery | [[Module roadmap|Module-Roadmap]] |
| Review country and industry tax research | [[Tax research|Tax-Research]] |
| Check currencies and regional plans | [[Countries and currencies|Countries-and-Currencies]] |
| Check the package scope and remaining gates | [[First package|First-Package]] |
| Explore the broader future path | [[Roadmap]] |
| Help build or review PHP Ledger | [[Contributing and support|Contributing-and-Support]] |

## What 1.0.0 demonstrates

Follow any authorized account from its opening balance through period debits, credits and running balances to its closing balance. Return from a statement line to the journal and source behind it. Authorized owners and accountants can create accounts and audit changes to names or active status; account code, type and purpose stay fixed.

Save a general-journal draft, return to edit it, review its lines and post when debits equal credits. Corrections use linked reversals that preserve the original entry. Receipts, expenses, owner reports and the sample cash POS use the same accounting services.

The public demo provides temporary visitor books: accounts are read-only, while general-journal drafts, posting and linked reversals are available within capacity limits. Its synthetic data resets hourly. The shop showcase remains a six-product cash example, without stock, tax or payment processing.

## The direction

Opening conversion, period controls, bank CSV reconciliation, required AR/AP, optional Purchasing/Inventory, the bundled core/POS lifecycle, scoped read API/MCP access, browser installation and signed automatic updates are included in 1.0.0. Controlled API/MCP write commands and distribution-channel packaging are next. Richer multi-year demo packs have separate reconciliation and reset gates.

Accounting framework work uses explicit regional entity/period profiles over a country-neutral core. Pakistan is one intended direction; connector readiness and validated demand determine delivery. The separate tax candidate catalog covers eight countries and seven industries, remains disabled and unreviewed, and supplies no active tax calculations.

New project-owned code and documentation use the [AGPL-3.0-or-later](https://github.com/phpledger/phpledger/blob/master/LICENSE). [Licence scope](https://github.com/phpledger/phpledger/blob/master/LICENSE-SCOPE.md) preserves separate historical, dependency and asset terms. No stable release, professional-body endorsement or regulatory certification is claimed.

[[Package scope|First-Package]] · [[Module roadmap|Module-Roadmap]] · [[Full roadmap|Roadmap]]

## Hosting, licensing and direction

PHP 8.2 is the minimum and PHP 8.3 is recommended for deployment. [[Hosting compatibility|PHP-Hosting]] explains the tested runtime and the remaining shared-hosting requirements.

The core is country-neutral and serves businesses, owners, bookkeepers and accountants across countries. Pakistan is one intended regional direction; FBR is one planned connector. Regional accounting/tax capabilities remain separate reviewed integrations.

The current release uses AGPL-3.0-or-later with a commercial licence available. Previously published 0.1.0 through 0.1.5 previews retain MIT. Self-hosting stays free without licence keys or licensing-server calls. See [[Licensing and contributions|Licensing]].

## Historical note: 0.1.5 accessibility update (15 September 2026)

[Nagulanvelu contributed PR #65](https://github.com/phpledger/phpledger/pull/65), giving each fallback POS quantity its product name. A maintainer correction keeps the fields visible with JavaScript disabled. The package passed fresh installation, upgrade from 0.1.4, 29 synthetic checkout checks and desktop/tablet/mobile browser checks. See the [release](https://github.com/phpledger/phpledger/releases/tag/v0.1.5-preview), [privacy notice](https://phpledger.com/privacy/) and [demo-use terms](https://phpledger.com/terms/).

## Connected reporting and richer samples

This combined preview retains company/book permissions, chart management, receipt/expense/general-journal drafts, balanced posting, linked reversals, opening cutover with a reconciled unpaid-document register, period controls, bank CSV reconciliation, reports, running account balances and CSV exports.

Scoped API/MCP reads and existing-user OAuth/Connections join four fictional businesses: service agency, retail shop, seasonal business and distributor. Each includes 74 sources, 2024–2025 history, an open 2026 practice period and three editable drafts. Follow [[Reporting walkthroughs|Reporting-Guides]] and [[Read integrations|Integrations]].
