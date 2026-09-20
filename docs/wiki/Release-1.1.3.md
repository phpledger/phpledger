## 1.1.3: the in-app updater completes again

Published 20 September 2026. A patch release: one fix, no migration, no schema change, no change to any accounting behaviour, to the rules that guard posted data, or to the migration checksums.

- **An update started from `/maintenance.php` now completes** ([issue #90](https://github.com/phpledger/phpledger/issues/90)). Before starting to replace files, the updater saves a copy of its own helpers, including `database_platform_functions.php`, as a private recovery runtime and works from that copy so that recovery keeps working while the application is being replaced. The migrate phase then loaded the new release's `install_functions.php`, which loaded the application's copy of the same helper file, and PHP stopped with `Cannot redeclare pl_database_platform()`. The updater's fail-safe did its job (automatic recovery restored the previous release completely on the copy used for the proof), but no real update could finish. `install_functions.php` and the recovery runtime now keep whichever copy of the platform helpers is already loaded instead of declaring them twice. The fix lives in the new release's files, so it applies to the update *into* 1.1.3 as well: the copied runtime of the installed 1.1.0, 1.1.1 or 1.1.2 is unchanged and needs no change.
- **The 1.1.2 notice is lifted for an installation on 1.1.2.** An installation on **1.1.2** with the publisher key pinned installs 1.1.3 from `/maintenance.php`; that path is proven end to end. An installation on **1.1.0** cannot: the update stops in its migrate step and restores itself, because the installed release's database helpers are three releases behind what the new migrations call ([issue #91](https://github.com/phpledger/phpledger/issues/91)). From 1.1.0, and from 1.1.1 until its path is proven, upgrade by the manual procedure: replace the files and run `php www/phpledger/install/migrate.php` once, which also applies migration 034.
- **The path is now tested.** `tests/update-migrate-test.php` installs a schema with one migration pending, copies the recovery runtime the way `pl_update_begin()` does and drives a signed update through the real `/maintenance.php` loader with no migrate, health or restore callback injected, asserting the phase sequence `backup > apply > migrate > verify > runtime > complete`. On 1.1.2's code it reproduces the failure exactly (`backup > apply > migrate > recovering > restored`, with the fatal error in the server log). CI runs it on MySQL 8.4 and every MariaDB in the matrix. The earlier update tests injected a `migrate` callback, which is why the defect was never seen before a real release pair with signed metadata existed.
- **One rule for maintainers**, written at the top of `database_platform_functions.php`: during an update the installed release's copy of that file serves the next release's migrations, so the file must stay backward compatible across one release step. A release must not make `install_functions.php` or a migration depend on a helper that this file first adds or changes in the same release.

Upgrading from 1.1.2 replaces files only. Upgrading from 1.1.0 or 1.1.1 may also apply migration 034. See [UPGRADE.md](https://github.com/phpledger/phpledger/blob/master/resources/release/UPGRADE.md).

This release ships without a media kit: minor and patch releases do not carry one. The assurance limits recorded for 1.1.2, 1.1.1, 1.1.0 and 1.0.0 below still apply.

### Verify the download

| File | SHA-256 |
|---|---|
| `phpledger-1.1.3.zip` (2,952,255 bytes) | `208c0a089ee32d377116026a4d0401790d21706399a5c63b5f5f818a4d3b5482` |

Built from commit `521dbc3` and 1,502 files verified against `PACKAGE-MANIFEST.json`. See the [release](https://github.com/phpledger/phpledger/releases/tag/v1.1.3) for its published assets, including `phpledger-1.1.3.update.json`.

### What was checked

`composer check` passes on MySQL 8.4: 320 tests, 0 failures, covering lint, static analysis, sample validation, the accounting suites and the new update-migrate test. The two end-to-end browser installation fixtures run the whole flow against disposable databases, and the update and recovery tests cover the signed-update path this release enables.

These are technical checks. Independent accounting review, independent security review, supervised pilots including a real month-end close, and installation observation by an unfamiliar operator remain outstanding, as recorded for 1.0.0.
