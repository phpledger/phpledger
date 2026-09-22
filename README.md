<p align="center">
  <a href="https://phpledger.com/">
    <img src="docs/repository/assets/phpledger-logo.webp" width="520" alt="PHP Ledger">
  </a>
</p>

<h1 align="center">Open-source, self-hosted accounting and cash POS for small businesses</h1>

<p align="center">
  Built on PHP 8.2+ with MySQL 8.4 or MariaDB 10.4+. New code is AGPL-3.0-or-later licensed; a commercial licence is available.
</p>

<p align="center">
  <a href="https://github.com/phpledger/phpledger/releases/latest"><img alt="Latest release" src="https://img.shields.io/github/v/release/phpledger/phpledger?style=for-the-badge&label=release&labelColor=0c2052&color=4656e8"></a>
  <a href="https://packagist.org/packages/phpledger/phpledger"><img alt="Packagist version" src="https://img.shields.io/packagist/v/phpledger/phpledger?style=for-the-badge&label=packagist&labelColor=0c2052&color=f28d1a&logo=packagist&logoColor=white"></a>
  <a href="https://github.com/phpledger/phpledger/pkgs/container/phpledger"><img alt="Container image on GitHub Container Registry" src="https://img.shields.io/badge/ghcr.io-phpledger%2Fphpledger-2496ed?style=for-the-badge&labelColor=0c2052&logo=docker&logoColor=white"></a>
  <a href="https://github.com/phpledger/phpledger/actions/workflows/foundation.yml"><img alt="Foundation checks" src="https://img.shields.io/github/actions/workflow/status/phpledger/phpledger/foundation.yml?branch=master&style=for-the-badge&label=checks&labelColor=0c2052&logo=githubactions&logoColor=white"></a>
</p>

<p align="center">
  <a href="docs/INSTALLER.md"><img alt="PHP 8.2 or newer" src="https://img.shields.io/badge/PHP-8.2%2B-777bb4?style=for-the-badge&labelColor=0c2052&logo=php&logoColor=white"></a>
  <a href="docs/INSTALLER.md"><img alt="MySQL 8.4" src="https://img.shields.io/badge/MySQL-8.4-00758f?style=for-the-badge&labelColor=0c2052&logo=mysql&logoColor=white"></a>
  <a href="docs/INSTALLER.md"><img alt="MariaDB 10.4 or newer" src="https://img.shields.io/badge/MariaDB-10.4%2B-c0765a?style=for-the-badge&labelColor=0c2052&logo=mariadb&logoColor=white"></a>
  <a href="LICENSE"><img alt="Licence: AGPL-3.0-or-later" src="https://img.shields.io/badge/licence-AGPL--3.0--or--later-a42e2b?style=for-the-badge&labelColor=0c2052"></a>
</p>

<p align="center">
  <a href="https://github.com/phpledger/phpledger/releases"><strong>Release downloads</strong></a> &nbsp; · &nbsp;
  <a href="https://phpledger.com/demo/">Try the demo</a> &nbsp; · &nbsp;
  <a href="https://github.com/phpledger/phpledger/wiki">Read the Wiki</a> &nbsp; · &nbsp;
  <a href="https://github.com/phpledger/phpledger/wiki/Roadmap">Roadmap</a> &nbsp; · &nbsp;
  <a href="https://github.com/phpledger/phpledger/discussions">Discussions</a>
</p>

---

## Accounting you can host yourself

PHP Ledger helps small businesses keep their books, follow money owed by customers and to suppliers, and trace report balances back to the entries behind them. It is open-source PHP software that runs on infrastructure you control.

**Start here:** [Try the demo](https://phpledger.com/demo/) · [Download the latest release](https://github.com/phpledger/phpledger/releases/latest) · [Installation guide](resources/release/INSTALL.md) · [Wiki](https://github.com/phpledger/phpledger/wiki)

This branch is preparing **1.3.0**. The [published releases](https://github.com/phpledger/phpledger/releases) are the source of truth for available downloads; the [release plan](docs/strategy/RELEASE-PLAN-1.3.md) describes work still being completed and verified.

## What you can do

| Your task | How PHP Ledger helps |
|---|---|
| Keep the books | Record receipts, expenses and general journals; review drafts before posting. |
| Track customers and suppliers | Create invoices and bills, record partial payments and credits, and review outstanding balances and ageing. |
| Understand the numbers | Explore account statements, trial balance, profit and loss and balance sheet, with links back to journals and source records. |
| Manage day-to-day operations | Use optional Purchasing, Inventory, Stock locations and a simple cash point of sale. Each module has its own documented scope. |
| Record owner transactions | Keep capital, drawings, owner loans and repayments distinct from operating income and expenses. |
| Work with others | Assign users and roles with permissions enforced by the server. |
| Connect reporting tools | Use scoped read-only API and MCP connections for supported reports. |

Posted journals are immutable. Corrections create linked reversals, closed periods reject ordinary new postings, and financial workflows use a shared posting service. See [Accounting and reports](https://github.com/phpledger/phpledger/wiki/Accounting-and-Reports) for examples and boundaries.

## Try it before installing

[**Open the public demo →**](https://phpledger.com/demo/)

Use fictional information and follow a transaction from its source to the ledger and reports. Demo work is temporary and resets hourly. The demo page describes the experience currently deployed.

For 1.3.0, the demo is being changed to offer the actual installer and business onboarding in a shared installation with protected database settings and a fixed public login. That new experience is a release requirement; it is not yet claimed live by this branch.

For a first exercise, create a small business or import a fictional sample, record a receipt and an expense, and inspect the trial balance. Then try a correction and follow the linked reversal. [Reporting walkthroughs](https://github.com/phpledger/phpledger/wiki/Reporting-Guides) provide more guided examples.

## Install on your own hosting

1. Download the application ZIP and checksum from the [latest release](https://github.com/phpledger/phpledger/releases/latest). Use the application package, which includes production dependencies.
2. Prepare PHP and a dedicated database using the [installation guide](resources/release/INSTALL.md).
3. Upload and extract the package, then open its address to start the browser installer. No Composer, Node or terminal is needed for a packaged browser installation.
4. Create the administrator account, set up a business and review its accounts, currency and opening position before entering real records.

**Requirements:** PHP 8.2 or newer (8.3 recommended), MySQL 8.4 or MariaDB 10.4+, and the extensions listed in the [installer documentation](docs/INSTALLER.md). Automatic browser updates also require PHP's zip extension. Use HTTPS for an internet-facing installation.

The upload-anywhere package layout requires Apache or LiteSpeed with the supplied access rules. A dedicated document root at `www/phpledger/public` is the preferred layout; never serve the repository root. Follow the documented Nginx configuration when using Nginx.

Already running PHP Ledger? Read the [upgrade and recovery guide](resources/release/UPGRADE.md) before replacing files. Back up matching code, configuration and database. The updater verifies signed metadata against the [publisher key](docs/RELEASE-SIGNING.md) you pin; older releases can require a manual first upgrade.

## Find the right guide

| You need | Read |
|---|---|
| First installation and business setup | [Getting started](https://github.com/phpledger/phpledger/wiki/Getting-Started) |
| Accounting workflows and reports | [Accounting and reports](https://github.com/phpledger/phpledger/wiki/Accounting-and-Reports) |
| Users, roles and access | [Users and roles](https://github.com/phpledger/phpledger/wiki/Users-and-Roles) |
| API and MCP connections | [Integrations](docs/INTEGRATIONS.md) |
| What changed in a release | [Release notes](resources/release/RELEASE-NOTES.md) and [release downloads](https://github.com/phpledger/phpledger/releases) |
| Development setup and checks | [Development guide](docs/DEVELOPMENT.md) and [architecture](docs/ARCHITECTURE.md) |
| Planned work | [Roadmap](docs/ROADMAP.md) |
| Help or a reproducible bug report | [Support guide](SUPPORT.md) |

## Scope and review status

PHP Ledger's accounting core is country-neutral. A selected currency, sample business or researched tax template does not establish local tax compliance or activate statutory filing. Check the module documentation for operational limits; a sample labelled pharmacy or manufacturing is a teaching scenario, not a claim of a complete industry system.

Published validation records distinguish automated checks, exact-package installation and upgrade tests, and developer-operated browser checks from independent accounting/security review and observed business use. Independent review, real-business pilots and restricted-host recovery qualification remain separate commitments. See [validation](docs/VALIDATION.md) and the evidence for the release you install.

## Help the project grow

Try PHP Ledger and tell us where a task became confusing. A small, reproducible example is especially useful. If you find the project useful, sharing it with another business owner or accountant also helps.

- **Ask a question:** [Discussions](https://github.com/phpledger/phpledger/discussions).
- **Report a bug:** [Issues](https://github.com/phpledger/phpledger/issues), using fictional records and sanitized diagnostics.
- **Contribute:** help with documentation, translations, testing, design, code or reviewed accounting examples. Start with the [contributor guide](https://github.com/phpledger/phpledger/wiki/Contributing-and-Support) and [CLA](CLA.md).
- **Report a security issue privately:** follow [SECURITY.md](SECURITY.md).
- **Discuss installation assistance or support:** [contact the project](mailto:rmak78@gmail.com).

Voluntary donations can help fund development, documentation, testing and project infrastructure. Financial support is optional; trying the software, reporting an issue and contributing an improvement are welcome too. The donation destination will be added after the project owner confirms it.

## Licence and project

New project-owned code and documentation use [AGPL-3.0-or-later](LICENSE). Self-hosting requires no licence key or licensing-server call. A separate commercial licence is available; see the [licensing policy](docs/LICENSING-POLICY.md).

Historical releases and third-party material retain their own terms. [Licence scope](LICENSE-SCOPE.md) records those boundaries, including dependencies, fonts, datasets and company marks. Modern application source is in `www/phpledger`; the historical application remains in Git history.

PHP Ledger is supported by [BixiTech](https://www.bixitech.com/), [BixiSoft](https://bixisoft.com/), [BrownBag](https://brownbag.pk/) and [Agency75](https://agency75.com/).

[Website](https://phpledger.com/) · [Demo](https://phpledger.com/demo/) · [Wiki](https://github.com/phpledger/phpledger/wiki) · [Releases](https://github.com/phpledger/phpledger/releases)
