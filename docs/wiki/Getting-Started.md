## Current package: 1.1.0

**1.1.0**, published 19 September 2026, installs like WordPress: upload the `phpledger` folder into any web folder and open its address, and the installer starts by itself. It adds MariaDB 10.4+, a chosen username, an optional logo, and the first signed update metadata. [Download 1.1.0](https://github.com/phpledger/phpledger/releases/tag/v1.1.0) and its [matching media kit](https://github.com/phpledger/phpledger/releases/download/v1.1.0/phpledger-1.1.0-media-kit.zip). [[Release notes|Release-1.1.0]]. **1.0.0**, published 18 September 2026, was the first stable release.

Automated test suites, fault-injection update/recovery tests, exact-artifact install/upgrade/recovery checks and developer-operated browser checks back this release. Independent accounting review, independent security review, supervised pilots with a real month-end close, unfamiliar-operator installation observation and restricted shared-host recovery certification have **not** happened; these continue as post-release commitments. See [[Release 1.0.0|Release-1.0.0]], [INSTALL.md](https://github.com/phpledger/phpledger/blob/master/resources/release/INSTALL.md), [UPGRADE.md](https://github.com/phpledger/phpledger/blob/master/resources/release/UPGRADE.md) and [RELEASE-SIGNING.md](https://github.com/phpledger/phpledger/blob/master/docs/RELEASE-SIGNING.md).

# Start with the package

Start at [the PHP Ledger website](https://phpledger.com/) or [open the demonstration](https://phpledger.com/demo/).

**[Download the 1.1.0 package](https://github.com/phpledger/phpledger/releases/tag/v1.1.0).** Choose `phpledger-1.1.0.zip` and its SHA-256 file from the release assets; automatic source archives do not include installed dependencies. The ZIP carries a short `README.txt`; the full [INSTALL.md](https://github.com/phpledger/phpledger/blob/master/resources/release/INSTALL.md) and [UPGRADE.md](https://github.com/phpledger/phpledger/blob/master/resources/release/UPGRADE.md) are on GitHub.

This release adds universal account statements, chart management, saved general-journal draft/review/post/reverse workflows, the accounting starter (AR/AP, optional Purchasing/Inventory, core tax), browser installation and signed automatic updates. It retains receipts, expenses, owner reports and the sample cash POS. It is the first stable release, with the remaining independent-review and pilot gates described in [[First package|First-Package]].

Modern source is in `www/phpledger`; historical code remains only in Git history. Developers can use the [development guide](https://github.com/phpledger/phpledger/blob/master/docs/DEVELOPMENT.md). New project-owned code and documentation are [AGPL-3.0-or-later licensed](https://github.com/phpledger/phpledger/blob/master/LICENSE); [licence scope](https://github.com/phpledger/phpledger/blob/master/LICENSE-SCOPE.md) preserves separate historical, dependency and asset terms.

## Install in the browser

Unzip the release to get one folder named `phpledger`, upload it anywhere inside your website (for example `public_html/accounts`, or XAMPP's `htdocs`), and open that folder's address. The installer starts by itself: with the database on the same server it needs no setup key; a remote database host needs a one-time code written to private storage. It checks the host and database, applies the migration chain, and creates the first account with a username, email, password and optional logo. Pointing an HTTPS hostname's document root at `www/phpledger/public` still works and remains the most secure layout. Upgrading from 1.0.0 is manual; see UPGRADE.md.

## Keep it updated

Signed automatic updates are available from the independent `/maintenance.php` operator interface: verify the publisher signature, take a matched code/configuration/key/database backup, apply the release and run migrations, with automatic restoration of the matched backup if anything fails. This requires the PHP zip extension. See `UPGRADE.md` and [[Release 1.0.0|Release-1.0.0]] for the manual backup/restore procedure and current qualification boundaries.

## Try the demonstration

Each visitor receives a separate fictional business. Synthetic records reset hourly, ending the old sample session. Capacity limits apply to temporary writes. Do not enter real customer records, credentials or business documents.

1. Open **Reports → Open account ledger**, choose an account and inspect opening, debit, credit, running and closing balances. Transactions and Journals also link directly to the ledger; mobile entries keep the running balance visible.
2. Create a general-journal draft with synthetic amounts and save it.
3. Reopen it, review the lines and balance debits against credits before posting.
4. Follow its source and journal into the account statement and trial balance.
5. Try a linked reversal with a reason, or explore the [[sample shop sale|POS-Showcase]].

Accounts are read-only in the public demo; account creation and changes are reserved for authorized owners/accountants in a self-hosted installation. General-journal draft editing, posting and linked reversals are available within the visitor's books. Reset removes temporary work.

## Planning a self-hosted installation

| Requirement | 1.1.0 environment |
|---|---|
| PHP | PHP 8.2+ (8.3 recommended) |
| PHP extensions | BCMath, PDO, PDO MySQL, mbstring, cURL, OpenSSL, fileinfo and sessions; the zip extension is additionally required to use automatic in-browser updates |
| Database | MySQL 8.4 LTS or MariaDB 10.4+ with InnoDB |
| Dependencies | Composer with a pinned lockfile; production dependencies included in the package |
| Development environment | Docker Compose |
| Web root | Only the new application's `www/phpledger/public` directory |
| Installation | Upload the `phpledger` folder and open it; the browser installer starts by itself, with no terminal access required. Apache or LiteSpeed is needed for the upload-anywhere layout. CLI installation/recovery remains available |
| Operations | HTTPS, private configuration, backups and tested restoration |

Never serve the repository root, and never use historical installation SQL dumps as the new application's migration path. Follow the instructions for the exact package and retain a reconciled backup before an upgrade.

## Installation and business setup are separate

An administrator prepares the server and initial administrator account. An owner or accountant then creates a business and reviews its accounts and opening-position requirements.

Reviewed opening trial-balance/CSV cutover, period administration, bank CSV reconciliation and core CSV exports are included. Company owners can review and enable the optional POS showcase in Modules; ordinary companies default off and explicit new samples enable it. Existing-business setup must not be treated as complete merely because a name and currency were entered. AR/AP, tax, inventory and production POS are planned optional modules; the eight-country tax research catalog does not activate tax rules.

[[Package scope and remaining gates|First-Package]] · [[Module roadmap|Module-Roadmap]] · [[Support enquiries|Contributing-and-Support]]

## Connected reporting and richer samples

This combined preview retains company/book permissions, chart management, receipt/expense/general-journal drafts, balanced posting, linked reversals, opening cutover with a reconciled unpaid-document register, period controls, bank CSV reconciliation, reports, running account balances and CSV exports.

Scoped API/MCP reads and existing-user OAuth/Connections join four fictional businesses: service agency, retail shop, seasonal business and distributor. Each includes 74 sources, 2024–2025 history, an open 2026 practice period and three editable drafts. Follow [[Reporting walkthroughs|Reporting-Guides]] and [[Read integrations|Integrations]].
