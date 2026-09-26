# PHP Ledger 1.4.6 local candidate gates — 26 September 2026

This is a **local candidate receipt**, not a publication, deployment or independent accounting/security assurance receipt. It identifies the tested source and archive rather than the mutable branch tip. The published 1.4.5 archive is the upgrade baseline (SHA-256 `fa90a69cacd084621958ea44b53a8e596ac2b64b6af351d40726c52d92ead96c`, verified against the 1.4.5 publication receipt before use).

## What the candidate changes

Three corrections the owner asked for on 26 September 2026 after 1.4.5, on branch `claude/setup-1.4.5` (decision register B98 to B100): the Start-stage sample gallery as the approved frame shows it (all eleven companies from a bundled catalogue snapshot, one-click Install for an installation administrator, the same list on the Packages Directory tab), statements in the standard layout (no amount on an open heading, one total per group, no repeated class row, zero lines hidden), and a partnership's capital and drawings per partner with the opening capital split by ratio. No migration: every migration file is byte-identical to the published 1.4.5 set (62 files).

## Full application checks

| Engine | Tests | Failures | PHP lint | PHPStan | Sample validation | Log |
|---|---|---|---|---|---|---|
| MySQL 8.4 | 713 | 0 | see MariaDB row (same tree) | same analysis (one run) | valid | `.cache/validation/1.4.6/mysql-test.log` |
| MariaDB 10.11 | 713 | 0 | 421 files, 0 failures | no errors (2 workers, 3 GB cap) | valid | `.cache/validation/1.4.6/maria-*.log` |

Both engines ran the isolated Compose recipe used for 1.4.5 (own project, own subnet, tmpfs data directory); the suite is invoked as `php tests/run.php`, PHPStan with the tracked `phpstan.neon` (worker cap 2) inside a 3 GB-capped container. Website check: 102 pages, 0 errors, 0 warnings. Logs are copied under `.cache/validation/1.4.6/` in the main checkout.

## Exact-package gates

`tools/verify-patch-release.py` ran the exact candidate archive against the published 1.4.5 archive on disposable `pl146-mysql` (MySQL 8.4) and `pl146-maria` (MariaDB 10.11) services. On each engine: a fresh install applied 62 migrations and made a first balanced posting; a populated 1.4.5 seed was upgraded with the packaged `install/upgrade.php`, which applied no migration and was a no-op the second time; every migration receipt, row hash and the balanced totals were unchanged. Steps recorded: MySQL `fresh_62_migrations_first_post, populated_1_4_5_seed, packaged_upgrade_1_no_op, packaged_upgrade_2_no_op, historical_rows_and_balances_unchanged`; MariaDB `fresh_62_migrations_first_post, populated_1_4_5_seed, packaged_upgrade_1_no_op, packaged_upgrade_2_no_op, historical_rows_and_balances_unchanged`.

| Item | Evidence |
|---|---|
| Candidate archive | `phpledger-1.4.6.zip`, 3,643,443 bytes, SHA-256 `dadc2758d9c50fff109e79f8448c4cb8010d4a9d51a458ce190d4512cf7765f1`, 1729 members, source commit `334cc78f8582982fb1ff3e400d1b2deab925bc22` |
| Published 1.4.5 baseline | SHA-256 `fa90a69cacd084621958ea44b53a8e596ac2b64b6af351d40726c52d92ead96c` |
| Reproduction | `tools/build-release.py` rebuilt the archive from the same commit: `bytes` |
| Migration files | 62 (identical to 1.4.5; none added) |

## Production-mode acceptance

Screens were checked on a served copy with `PL_ENV=production` (no repository fallback), at 1366×768, against the approved frame o1-start.html and standard statement layouts; the screenshots are in the publication receipt. A one-click install from a Start-stage card fetched the signed `sample-envelope.json` and the release archive from phpledger.com and GitHub and installed the sample on that copy.

No production, public provider or credential state was changed by these gates.
