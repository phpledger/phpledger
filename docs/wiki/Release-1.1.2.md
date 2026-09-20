## 1.1.2: the 1.1.1 record corrected, and signed updates again

Published 20 September 2026. A patch release: no migration of its own, no schema change, and one fix at the point of sale.

- **The 1.1.1 record is corrected.** 1.1.1 was announced as carrying no migration. It does: its package includes migration `034_inventory_locations` and the optional Stock locations module (warehouses and vans, transfers at carrying value, per-location stock), which is off by default and changes nothing until a company enables it under Modules. A fresh 1.1.1 installation applied the migration during setup. An installation upgraded from 1.1.0 by replacing files has it pending: run `php www/phpledger/install/migrate.php` once, or install 1.1.2 from `/maintenance.php`, which applies it. The module's documentation, compatibility matrix and upgrade proof are 1.2 work; treat it as an early copy of the first 1.2 module. The [[1.1.1 page|Release-1.1.1]] is amended.
- **Cash at the point of sale is counted in notes and coins** ([issue #88](https://github.com/phpledger/phpledger/issues/88)). The showcase accepted a tender such as `1262.2555` against a sale total of `1,262.25` and recorded change of `0.0055`. Cash received must now be a whole number of the currency's minor units, so tender, total and change share one precision on the receipt; the sample catalogue is checked to price goods no more finely. The ledger keeps four decimal places; only the tender is held to two.
- **Signed update metadata is back.** 1.1.1 was published without `phpledger-1.1.1.update.json`, so an installation on 1.1.0 could not reach it through `/maintenance.php` although its upgrade guide said so. 1.1.2 carries signed metadata: an installation on 1.1.0 or 1.1.1 with the publisher key pinned installs it from `/maintenance.php`, and from 1.1.0 that run also applies migration 034. 1.1.1 itself is not signed retroactively.
- **Documentation and release metadata corrected.** The README, wiki, download page and roadmap surfaces that still called 1.1.0 the current release, called 1.1.1 the first signed release, or said the demo packs had left the package (they have not; that work moves to 1.2 with the plugin runtime) are fixed. The website requirements page names `log_bin_trust_function_creators = 1` for binary-logged MySQL, which setup already reports. The rule that only a major release (`x.y.0`) carries a media kit is written into the project's release rules.

Upgrading from 1.1.1 replaces files only; see [UPGRADE.md](https://github.com/phpledger/phpledger/blob/master/resources/release/UPGRADE.md#from-111-to-112). Upgrading from 1.1.0 also applies migration 034; see [UPGRADE.md](https://github.com/phpledger/phpledger/blob/master/resources/release/UPGRADE.md#from-110-to-112). Either upgrade can be installed from `/maintenance.php` with the pinned publisher key, because 1.1.2 carries signed metadata; from 1.1.0 that run applies the migration inside its backup-and-verify sequence.

This release ships **without a media kit**: minor and patch releases do not carry one, by the owner's decision of 20 September 2026. The assurance limits recorded for 1.1.1, 1.1.0 and 1.0.0 still apply; the first supervised pilot, on the maintainer's own books, starts on this release on 1 October 2026. The public demo still runs 1.1.0.

**Known issue, found 20 September 2026 ([issue #90](https://github.com/phpledger/phpledger/issues/90)):** the in-app updater cannot complete a real update. After the backup and file replacement succeed, the migrate step fails with a duplicate function declaration between the copied recovery runtime and the application, the updater enters automatic recovery, and on the disposable copy used for the proof that recovery did not finish on its own. The cause has existed since 1.1.0 and was never exercised because no earlier release pair had signed metadata on both sides. **Until 1.1.3 fixes it, do not start an update from `/maintenance.php` on a live installation.** Upgrade by the manual procedure instead: replace the files and run `php www/phpledger/install/migrate.php` once (proven for 1.1.0 and 1.1.1 to 1.1.2). The signed metadata remains valid and will serve the fixed updater.

### Verify the download

| File | SHA-256 |
|---|---|
| `phpledger-1.1.2.zip` (2,951,475 bytes) | `24d499d4b3ba249a7b25eb7874fa18c0c0ece778203d32b927bef3cf260eb882` |

Built twice from commit `3df5755` and byte-identical both times; 1,502 files verified against `PACKAGE-MANIFEST.json`. See the [release](https://github.com/phpledger/phpledger/releases/tag/v1.1.2) for its published assets, including `phpledger-1.1.2.update.json`.

### What was checked

`composer check` passes on MySQL 8.4: 319 tests, 0 failures, covering lint, static analysis, sample validation and the accounting suites. The two end-to-end browser installation fixtures run the whole flow against disposable databases, and the update and recovery tests cover the signed-update path this release restores.

These are technical checks. Independent accounting review, independent security review, supervised pilots including a real month-end close, and installation observation by an unfamiliar operator remain outstanding, as recorded for 1.0.0.
