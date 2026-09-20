# Publication receipt: PHP Ledger 1.1.2

Published 20 September 2026 on the owner's request ("Yes to decisions 1 to 4, cut 1.1.2 now"). Owner decisions: version **1.1.2**, patch release; correct the 1.1.1 record on every surface; restore signed update metadata; cut 1.1.2 immediately with issue #88 (POS cash tender precision) already fixed in pull request #89; **no demo refresh** (owner's choice of 20 September); **no media kit** (waived for minor and patch releases under decision B29). Machine-readable copy: [STABLE-1.1.2-PUBLICATION.json](STABLE-1.1.2-PUBLICATION.json).

## What was released

The 1.1.1 errata corrected across every public surface: migration 034 and the Stock locations module do ship in 1.1.1; issue #71 (demo-pack unbundling) moves to 1.2; the signed upgrade path from 1.1.0 is restored. Issue #88 (POS cash tender precision fix, pull request #89) integrated on master before the tag. See [RELEASE-NOTES.md](../../resources/release/RELEASE-NOTES.md) and [UPGRADE.md](../../resources/release/UPGRADE.md).

Release commit `3df5755` ("Release 1.1.2: the 1.1.1 record corrected, and signed updates again", 2026-09-20 18:43:56 UTC) integrates the #88 fix (`06e1ca9`) and the 1.1.1 record corrections (`54fb89e`).

## Artifacts

| Item | Value |
|---|---|
| Tag | `v1.1.2` → `3df5755cb777a6e60e545d644ece6cb93f56fd7f` (package source) |
| Release | https://github.com/phpledger/phpledger/releases/tag/v1.1.2, published 2026-09-20 18:43:56 UTC |
| `phpledger-1.1.2.zip` | 2,951,475 bytes, 1,502 files verified against `PACKAGE-MANIFEST.json`, SHA-256 `24d499d4b3ba249a7b25eb7874fa18c0c0ece778203d32b927bef3cf260eb882` |
| `phpledger-1.1.2.zip.sha256` | 86 bytes |
| Signed update metadata | **yes**: `phpledger-1.1.2.update.json` (312,687 bytes, SHA-256 `5868d8124d06a2c9e8fafac2a7c7a6e93d984db4ffba97b8f472cc575876b49d`), verified as upgrade from 1.1.0 and 1.1.1, 1,503 files in signed inventory |
| Media kit | **none**: waived by the owner for minor and patch releases (decision B29) |
| Publisher key | unchanged from 1.1.0 and 1.1.1 (RSA-4096, SPKI DER SHA-256 `4e58a5f46b0538c9b37aaadbfced9a2d8ad2f7d413bc168a67b1b94feaa78e78`); used for this release |

The published ZIP was downloaded again from GitHub after publication and hashed to the local build. The release is marked latest and lists all three assets.

## Evidence

| Check | Result |
|---|---|
| Full check on the release commit (MySQL 8.4) | composer check: lint, PHPStan, samples, **319 tests, 0 failures** (316 at 1.1.1; three added by the #88 fix) |
| Installer and browser checks | keyless installer fixtures passed; browser checks at 1440, 768 and 390 px |
| Tests | tests/update-channel-test.php: 38 checks passed |
| Reproducible build | two local builds from `3df5755` byte-identical (build/release-1.1.2 and build/release-1.1.2-check); CI Release build run 35529190431 also byte-identical across two runs; CI archive different bytes (2,946,503 bytes) but identical member contents (1,503 members, every member SHA-256 equal); only zlib compression differs between platforms |
| CI Foundation on `3df5755` | **success**: runs 35529190381 and 35529187092; PHP 8.2/8.3/8.4 on MySQL 8.4, MariaDB 10.6/10.11/11.4 |
| Signature | signed on the owner's machine; verified with `pl_update_verify_metadata()` as upgrade from 1.1.0 and from 1.1.1; rejected for 1.1.2 (correctly cannot upgrade to itself); 1,503 files in signed inventory |
| Upgrade proof (i) | 1.1.0→1.1.2 by file replacement plus `install/migrate.php` on MySQL 8.4.9: from published 1.1.0 archive, file replacement, `migrate.php` applied exactly 034_inventory_locations (35 receipts, checksum 5aea895a51b4582023498c5b8f7720df01edd133482b3c890d9f8608e751c7ca equal to package file); second run a no-op; `preflight.php` exit 0 |
| Upgrade proof (ii) | 1.1.1→1.1.2 through the application's updater with real signed metadata: FAILED at the migrate phase, and the failure is a product defect, not a harness problem (issue #90). On a disposable MySQL 8.4 schema the published 1.1.1 package was installed, the operator key and the official publisher key were provisioned, and the real 1.1.2 archive and signed metadata were submitted through /maintenance.php: pl_update_begin() accepted the envelope (operation 149ec4afbe4c1059a99ce802582f562f, phase backup, version 1.1.2, archive hash matching), the backup phase (steps 0-95) and the apply phase (98-110) completed, step 114 entered migrate, and step 115 returned 500 with `PHP Fatal error: Cannot redeclare pl_database_platform() (previously declared in .../storage/installation/updates/<id>/runtime/database_platform_functions.php:22) in .../www/phpledger/includes/functions/database_platform_functions.php on line 22`. The updater recorded `interrupted_update` and entered `recovering`; the driver's operator session then expired, and `tools/resume-update.php --drain` afterwards reported `Installation recovery could not continue. Private storage and database access require host review; maintenance remains active.` with the operation still marked in flight, so automatic restoration was not observed to finish. The disposable copy was left in maintenance with 1.1.2 files. Cause: `pl_update_database_connect()` loads the copied runtime's `database_platform_functions.php`, then `pl_update_database_migrate()` requires the application's `install_functions.php`, which requires the application's copy of the same file; present since 1.1.0 (`01ebc1c`); the update tests inject a `migrate` callback and never run the real function under the copied runtime. Every surface of 1.1.2 now carries a known-issue notice telling operators to use the manual procedure; the fix is the first item of 1.1.3. |
| Website rebuild | 73 pages, 0 errors, 0 warnings; release feed valid; stable channel offers 1.1.2 with update_json link |
| .gitattributes rule | `*.update.json -text` added to prevent CRLF conversion of signed metadata in republication |

## Demo

| Item | Value |
|---|---|
| Release | **not refreshed**, at the owner's choice; still `core-1.1.0-af0f4895f85a` (source `af0f4895f85a9c9c8b2107b5b919428c9919d551`, version 1.1.0, cut over 2026-09-19 15:20:41 UTC) |
| Consequence | the demo does not show the #88 cash tender precision fix or any 1.1.2 change; issue #88 remains visible there |

## Website

| Item | Value |
|---|---|
| Release | website-1-1-2-20260920-185427 from commit ac0639d6dcfb46a3f16f8670c24e7287a2b4ed60 through .cache/publish-website-1.1.2.py: 305 files, archive SHA-256 1eb6a0a7fad783197a30367accc4391f179a5cf30b03406c3ae2cbab9daadc82, vhost SHA-256 5ff56e18f65e6a15fe6cbe61e0189cf613b4f76735c11df7964cef39ec22a7a8, backup /var/www/phpledger/data/backups/website-1-1-2-20260920-185427; only document root changed in vhost; /support, demo proxy, OAuth, TLS and headers preserved; demo identity unchanged |
| Live checks | /releases/index.json offers stable 1.1.2 with update_json; /releases/1.1.2.update.json verified with `pl_update_verify_metadata()`; /download/, /news/1-1-2/, /roadmap/ and corrected /news/1-1-1/ return 200 |
| Release feed | https://phpledger.com/releases/index.json serves 4 releases (1.1.2 stable, upgrade paths from 1.1.0 and 1.1.1) |

## Wiki and other surfaces

| Surface | Status |
|---|---|
| GitHub Wiki | Release-1.1.2 added; Release-1.1.1 corrected; Home, _Sidebar, _Footer, Roadmap, Getting-Started, Accounting-and-Reports, Architecture, Module-Roadmap updated. Wiki commit `7a4fd75aae39aec516e12898ba1f5a75d6e98f0c` ("Record the 1.1.2 release and correct the 1.1.1 page", 2 files for 1.1.2, amendments to 1.1.1 and others) is **pushed** and confirmed |
| Repository About | updated to name 1.1.2 |
| v1.1.1 GitHub release | correction block prepended to the body: migration 034 and Stock locations module shipped in 1.1.1; file-replacement upgrades from 1.1.0 must run migrate.php once; no signed metadata in 1.1.1; 1.1.2 restores it |
| Release notes, UPGRADE.md, README, wiki Release-1.1.1 and website news-1-1-1 | corrected in place for the 1.1.1 facts |
| 1.1.1 publication receipt | backfilled: docs/repository/PUBLICATION-2026-09-20-1.1.1.md and STABLE-1.1.1-PUBLICATION.json (with errata) |
| Issues and milestones | #88 closed (auto-closed by PR #89 merge); #74 and #87 closed as shipped in 1.1.1; milestone 1.1.1 closed; milestone 1.1.2 (number 9) created with #88; #71–#73 placed on milestone 1.2 |
| Coordination files | AGENTS.md, AGENTS_SYNC.md, AGENT_MESSAGES.md, docs/RELEASE-PROTOCOL.md status table, docs/RELEASE-SIGNING.md, .github/workflows/release.yml updated; protocol status table brought to 21 September (internal date handling; all surfaces record 20 September) |
| Policy | decision B29 (media kits accompany major releases only): AGENTS.md step 6 and .github/workflows/release.yml updated |

## Open items

- **Demo on 1.1.0**: the lag is stated on release notes, wiki and website; #88 is fixed in 1.1.2 but still visible on the demo (owner's choice).
- **MariaDB 10.4** (advertised floor) still has no CI or local evidence; CI runs 10.6, 10.11, 11.4.
- **tools/verify-upgrade.php**: no 1.x baseline yet; the upgrade proof above was a scripted disposable-schema run, not a tracked verifier.
- **Cross-platform reproducible compression** (issue #75): ZIP member contents identical, only zlib bytes differ between CI and local; open by decision.
- **Key custody**: offline backup of the signing key (`C:\phpledger-signing-key`) still uncreated; a cloud backup was recorded on 19 September 2026.
- Not yet observed: independent accounting and security review; supervised pilots with a real month-end close (pilot #1 scheduled for 1 October 2026 on 1.1.2, decision B28); unfamiliar-operator installation; restricted shared-host recovery certification.
