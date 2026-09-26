# PHP Ledger 1.4.5 local candidate gates — 26 September 2026

This is a **local candidate receipt**, not a publication, deployment or independent accounting/security assurance receipt. It identifies the tested source and archive rather than the mutable branch tip. The published 1.4.1 archive is the upgrade baseline (SHA-256 `3758aa71704f0c605949ae91422acd6842cebbff38368a40425b74a9beeea37b`, downloaded from the public release and verified against the 1.4.1 gate receipt before use).

## What the candidate changes

Seven work packages on branch `claude/setup-1.4.5` from `codex/release-1.4.1`, each committed with its own evidence and radio note: the mockup (`c0804987`), the installer (`e731a2d6`), the mandatory installation notice (`c9ffaa9d`), business setup (`6c35ab15`), the first Home (`c3353a97`), the country-of-registration column (`50ae1169`, migration `061_company_country`), the shell and icons (`8d4b8ab7`) and the Packages page (`f253f2b4`). Decision register rows B91 to B97 record the choices; B16 carries the notice amendment. The candidate source commit and archive identity are recorded in the [machine-readable receipt](RELEASE-1.4.5-GATES.json).

**Migration 061** adds `pl_company_profile.country_code` (`CHAR(2)`, empty default) after `legal_form`; it rewrites no row. Every historical migration file is byte-identical to the published 1.4.1 set.

## Full application checks

| Engine | Tests | Failures | PHP lint | PHPStan | Sample validation | Log |
|---|---|---|---|---|---|---|
| MySQL 8.4 | 708 | 0 | 420 files, 0 failures (first `composer check`) | no errors (2 workers, 3 GB cap) | valid | `.cache/validation/1.4.5/mysql-test.log`, `mysql-analyse.log` |
| MariaDB 10.11 | 708 | 0 | 420 files, 0 failures | no errors (2 workers, 3 GB cap) | valid | `.cache/validation/1.4.5/maria-*.log` |

Both engines ran the same isolated Compose recipe (own project, own subnet, tmpfs data directory); the suite is invoked as `php tests/run.php` and PHPStan through an include of the tracked `phpstan.neon` that only caps the worker count. Logs are copied under `.cache/validation/1.4.5/` in the main checkout and are not part of the repository.

## Exact-package gates

`tools/verify-patch-release.py` (generalised in this release: versions, baseline digest, added migrations and service prefix are arguments) ran the exact candidate archive against the published 1.4.1 archive on disposable `pl145-mysql` (MySQL 8.4) and `pl145-maria` (MariaDB 10.11) services. On each engine: a fresh install applied 62 migrations and made a first balanced posting; a populated 1.4.1 seed (receipt, expense draft, party, employee, partially paid invoice, linked reversal) was upgraded with the packaged `install/upgrade.php`, which applied exactly `061_company_country` once and was a no-op the second time; every historical migration receipt, row hash, the partial invoice, the draft, the reversal and the balanced totals were unchanged. Steps recorded: MySQL `fresh_62_migrations_first_post, populated_1_4_1_seed, packaged_upgrade_1_applied_061_company_country, packaged_upgrade_2_no_op, historical_rows_and_balances_unchanged`; MariaDB `fresh_62_migrations_first_post, populated_1_4_1_seed, packaged_upgrade_1_applied_061_company_country, packaged_upgrade_2_no_op, historical_rows_and_balances_unchanged`.

| Item | Evidence |
|---|---|
| Candidate archive | `phpledger-1.4.5.zip`, 3,635,906 bytes, SHA-256 `fa90a69cacd084621958ea44b53a8e596ac2b64b6af351d40726c52d92ead96c`, 1728 members, source commit `c5b4662748e35db7f0aa0f063dedb1531a675ccb` |
| Published 1.4.1 baseline | SHA-256 `3758aa71704f0c605949ae91422acd6842cebbff38368a40425b74a9beeea37b` |
| Reproduction | `tools/build-release.py` rebuilt the archive from the same commit: `bytes` |
| Migration files | 62 (61 identical to 1.4.1 plus `061_company_country`) |

## Website and resources

`node www/website/check.mjs` on the worktree: 102 pages, 0 errors, 0 warnings (the W2 privacy-page rebuild is the only website change; the release feed and site metadata are publication steps and were not changed).

## Browser acceptance on the served tree (1366×768)

- Installer: unlock → database (green button once valid) → automatic build and settings file → account (generator, strength) → completion with sign-in details (W1, `tests/browser_installer_test.php --serve`).
- Business setup: all five stages and Ready inside the fold; country switch PK → IN → PK moved the legal forms, labels, currency and year end; a business with two owners, four named money accounts and PKR 500,000 capital introduced was created end to end; Company profile then showed Pakistan, `(Private) Limited` and the SECP labels.
- Home: the getting-started guide (5 of 6 done for that business), Expense and Bill open because money was in, per-account balances, all inside the fold; 67 inline SVG icons and no `<img>` icons; Help as the labelled topbar button; the version in the user menu; no sidebar footer.
- Packages: Installed, Sample companies and Directory tabs for a business owner; switching Stock locations on and off for BixiSoft flipped the badge and returned to the Installed tab.

## Review before this receipt

A read-only Opus review of the W3 diff found one high defect (a coded starter-leaf row let the next row claim the same account) and five medium/low findings (registrar saved as the authority, full-sample money rows, script touched-state, guide/label i18n, AE/SG/GB catalogue facts); all were fixed and are covered by `tests/setup_wizard_test.php` before the W3 commit.

## Remaining gates before publication

1. Review the final source/ZIP identity immediately before publication. The signed update metadata, ZIP sidecar and release upload must pin the same archive SHA-256; signing is the owner's offline step and was not performed here.
2. Only under separate publication authorization, tag `v1.4.5`, publish the release assets and update the website feed and site metadata, the container image and any catalogue. Download the public ZIP, checksum and update metadata anonymously and compare their hashes with this receipt.
3. Before any demo or host cutover, follow the operator runbook with a verified snapshot and rollback path.

No production, public provider or credential state was changed by these gates.

## Environment notes

Docker Desktop's WSL engine died twice during the first PHPStan runs and then failed to bootstrap at all (`wslexec` exit `0xc00000fd`). With the owner's approval its data disk was set aside and the engine started fresh; every image and volume was rebuilt, and images pulled while the engine was dying had extracted with empty files and were purged (`docker system prune -af`) before the final pulls. The cause of the PHPStan crashes was resource exhaustion, not the analysis: PHPStan starts one worker per CPU (22 on this machine) at 512 MB each inside an 8 GB VM on a host with half a gigabyte free. The recorded PHPStan run therefore uses the same `phpstan.neon` rules and level through an include that caps `parallel.maximumNumberOfProcesses` at 2, inside a 3 GB-capped container; the two findings it raised (a redundant demo check in `package_web_functions.php` and an inaccurate `@var array $company` in `packages.php`) were fixed before the recorded run. The full suite is run as `php tests/run.php` directly, because `composer test` wraps it in Composer's 900-second process timeout, which this machine exceeds.
