## 1.1.1: setup you can watch, and a database on your own computer

Published 20 September 2026. A patch release: no schema change, no migration, and no change to any accounting behaviour. Everything in it is browser setup, which an existing installation has already completed.

- **Six stages you can see.** Start, Database, Build, Checks, Account and Ready, each sized for a laptop screen. The Build stage shows a progress bar naming the step it is on, such as "Building the chart of accounts and journals, step 6 of 35", instead of a button that looks like it is waiting for you.
- **A failed requirement is something to clear, not an error page.** A missing PHP extension takes over the screen with the places it is actually fixed: the `php.ini` line on XAMPP or Laragon, the package name on Debian, the PHP Selector screen on shared hosting, and a Check again button.
- **Setup proves what it built.** Fourteen checks on your server before you type anything, and six against the finished database afterwards, including the count of protective database rules that keep posted entries immutable. The last screen itemises what exists, read back from the database.
- **A database on your own computer needs no preparation** ([issue #84](https://github.com/phpledger/phpledger/issues/84)). On `localhost`, `127.0.0.1` or `::1`, setup accepts the account XAMPP, Laragon and MAMP install, including `root` with no password, and creates the database itself when it does not exist. A database on another server still needs a dedicated account with a password, and is never created.
- **Setup deduces your environment.** It reports the folders it will use and the address it will record, and finds a database server answering on a loopback port to fill in the port.
- **Plain HTTP warns instead of refusing.** A site without a certificate can be installed and used, with a warning on every setup step and on every screen afterwards naming what stays unavailable. Connections (the API, MCP and app integrations) still require an HTTPS address. Sign-in details travel unencrypted until a certificate is in place, so turn on SSL before keeping real books.
- **A server that would refuse the schema says so first** ([issue #83](https://github.com/phpledger/phpledger/issues/83)). MySQL 8 writes a binary log by default and refuses `CREATE TRIGGER` unless the account is trusted, which used to stop the schema part-way through migration `001`. Setup reads that setting at the connection check and names `log_bin_trust_function_creators = 1` before anything is written. A migration interrupted anyway records how far it got and resumes at its first unapplied statement.
- **The schema applies in six requests instead of 35.** The chain is about two seconds of work. A host with a short `max_execution_time` still hands control back between steps and resumes from its receipts.
- **One database account, not two.** Browser setup no longer offers an optional second runtime account ([issue #87](https://github.com/phpledger/phpledger/issues/87)). Restricting the account after installation is documented in INSTALL.md and turns off the automatic update path.
- **Fixes.** An empty key folder that an operator created in advance, or pointed `PL_OAUTH_KEY_DIRECTORY` at, was treated as an interrupted installation and blocked setup; only a folder holding some of the keys is refused now. The two demo landing labels corrected on 19 September ship here.

Upgrading from 1.1.0 replaces files only. See [UPGRADE.md](https://github.com/phpledger/phpledger/blob/master/resources/release/UPGRADE.md#from-110-to-111).

This release ships **without a media kit**, by the owner's decision of 20 September 2026: minor and patch releases do not carry one. The assurance limits recorded for 1.1.0 and 1.0.0 still apply, and a real shared host and an unfamiliar operator have still not been observed.

### Verify the download

| File | SHA-256 |
|---|---|
| `phpledger-1.1.1.zip` (2,950,729 bytes) | `8c7f8b3236661ff5f3bdacac3cc2db45ca688eccbcc31dcbf07b877f3432aee8` |

Built twice from commit `63658bc` and byte-identical both times; 1,502 files verified against `PACKAGE-MANIFEST.json`. See the [release](https://github.com/phpledger/phpledger/releases/tag/v1.1.1) for its published assets.

### What was checked

`composer check` passes on MySQL 8.4 and MariaDB 10.11: 316 tests, 0 failures, covering lint, static analysis, sample validation and the accounting suites. Two end-to-end browser installation fixtures run the whole flow against disposable databases, including an installation through an account with no password into a database setup creates itself. A complete installation was also driven through a browser at 1280×720.

These are technical checks. Independent accounting review, independent security review, supervised pilots including a real month-end close, and installation observation by an unfamiliar operator remain outstanding, as recorded for 1.0.0.
