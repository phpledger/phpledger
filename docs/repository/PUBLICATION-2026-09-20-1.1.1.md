# Publication receipt: PHP Ledger 1.1.1

Published 20 September 2026 on the owner's request ("merge, and make it live"). Owner decisions: version **1.1.1**; push `master`, cut the release and close issue #84; **no media kit** (waived for minor and patch releases: "we dont need media kit on minor modifications unless we release something major"); **no demo refresh**. This receipt was written retrospectively on the evening of 20 September 2026: the release was published without the receipt that step 12 of the [release protocol](../RELEASE-PROTOCOL.md) requires, and two errors in the published record were found the same evening (see Errata). Machine-readable copy: [STABLE-1.1.1-PUBLICATION.json](STABLE-1.1.1-PUBLICATION.json).

## What was released

Browser setup rebuilt as the six-stage Workbench (Start, Database, Build, Checks, Account, Ready; checks as lights, errors as challenges), a local database account with no password (including MySQL `root`) and database creation on the owner's own server, plain HTTP warning instead of refusing, the schema applied in six requests, the binary-logging trigger preflight and resumable migrations from issue #83, the single database account (decision B24, issue #87) and the corrected demo-landing labels. See [RELEASE-NOTES.md](../../resources/release/RELEASE-NOTES.md). The package also carries migration `034_inventory_locations` and the optional Stock locations module, which none of the 1.1.1 surfaces announced; see Errata.

Integrated by merge commit `63658bc` ("Merge remote-tracking branch 'origin/master'", 2026-09-20 16:35:59 UTC), not a fast-forward:
- Branch `fix/local-install-friendly` at `c731ced` (2a04f1a, 8c786aa, 1bce608, 141d457, c731ced "Release 1.1.1: setup you can watch, and a local database").
- `origin/master` at `196c02a`, which had taken PR #85 (`65b3437`, trigger preflight and resumable migrations) and PR #86 (`196c02a`, issue #83 record) meanwhile. Merge base `ab70045`.
- Five conflicts resolved by hand, keeping both sides ([AGENT_MESSAGES.MD](../../AGENT_MESSAGES.MD), "2026-09-20 — Claude: 1.1.1 published (result)").

## Artifacts

| Item | Value |
|---|---|
| Tag | `v1.1.1` (annotated tag `442f433`, created 2026-09-20 16:42:55 UTC) → `63658bcabd6a92c84ca3745099d150c19eb22705` (package source, merge commit) |
| Release | https://github.com/phpledger/phpledger/releases/tag/v1.1.1, published 2026-09-20 16:43:25 UTC |
| `phpledger-1.1.1.zip` | 2,950,729 bytes, 1,502 files verified against `PACKAGE-MANIFEST.json`, SHA-256 `8c7f8b3236661ff5f3bdacac3cc2db45ca688eccbcc31dcbf07b877f3432aee8` |
| `phpledger-1.1.1.zip.sha256` | 86 bytes, SHA-256 `9e06340bb31f5b09bdaa7825e0ef4f41bddfe5fa37bdde6114f2b3b14134a028` |
| Signed update metadata | **none**: no `phpledger-1.1.1.update.json` was produced or attached |
| Media kit | **none**: waived by the owner on 20 September 2026 for minor and patch releases |
| Publisher key | unchanged from 1.1.0 (RSA-4096, SPKI DER SHA-256 `4e58a5f46b0538c9b37aaadbfced9a2d8ad2f7d413bc168a67b1b94feaa78e78`); not used for this release |

The published ZIP was downloaded again from GitHub after publication and hashed to the local build (AGENT_MESSAGES.MD, same entry). GitHub's stored digest for the asset is the same value (`gh release view v1.1.1`, 20 September evening). The release lists no other assets, and `gh release view` shows it is neither a draft nor a pre-release.

## Evidence

| Check | Result |
|---|---|
| Full check on the merged commit (MySQL 8.4) | `composer check`: lint, PHPStan, samples, **316 tests, 0 failures** (AGENT_MESSAGES.MD, "1.1.1 published (result)"; not re-run for this receipt) |
| Installer | browser installer 54 checks; keyless installer 83 checks (same entry) |
| MariaDB | the earlier issue #84 pass at `8c786aa`, before the Workbench rebuild and the merge: **310 tests, 0 failures on MySQL 8.4 and on MariaDB 10.11**, keyless installer 158 checks (AGENT_MESSAGES.MD, "local-friendly installation, issue #84 (result)"). Not re-run on the merged commit locally; CI covers 10.6, 10.11 and 11.4 below. MariaDB 10.4, the advertised floor, was not run anywhere |
| Reproducible build | two local builds of `63658bc` byte-identical: `build/release-1.1.1` (16:41:49 UTC) and `build/release-1.1.1-check` (16:42:41 UTC, `reproduced_against` the first); both receipts record 2,950,729 bytes, SHA-256 `8c7f8b32…`, 1,502 manifest files verified; both ZIPs re-hashed for this receipt |
| CI Foundation on `63658bc` | **success** on the `master` push (16:42:57 UTC) and on the `v1.1.1` tag push (run 35523584079, 16:43:01 UTC): PHP 8.2/8.3/8.4 on MySQL and MariaDB 10.6/10.11/11.4, six jobs green |
| CI Release build on `63658bc` | run 35523584101: "Build the release archive", "Rebuild and require a byte-identical archive" and "Keep the build receipt" **succeeded**; the final "Create the draft release" step **failed** with "A release for v1.1.1 already exists; this workflow never replaces release assets", because the release had been published from the local build at 16:43:25 UTC and the step ran at 16:44:06 UTC. The workflow's own rule, not a build fault |
| Update and recovery, signing, channel and feed suites | not run for this release: no signed metadata was produced, so there was nothing to verify |
| Exact archive in Apache | not run for this release |
| Signature | none |

**The tag was moved once.** `v1.1.1` was first pushed at `c731ced`, the branch tip before the merge, at 16:33 UTC. The committed build receipts show that commit was built twice locally at 16:31:44 and 16:32:47 UTC (2,948,511 bytes, SHA-256 `7c24f5ce3b03515c62e52d3d0d379552e5aa094c9257db89fd9ecd56fd535d70`, byte-identical). Its Foundation run (35523076834) lost one of six jobs (`php-mysql 8.3`) to "couldn't find remote ref refs/tags/v1.1.1" because the tag had been deleted before that job checked out; its Release run (35523076744) built the archive and then aborted at the draft step: "tag v1.1.1 doesn't exist in the repo … aborting due to --verify-tag flag". The merge produced `63658bc`, the tag was re-created there at 16:42:55 UTC and the runs above followed. Neither CI run created a draft; the release was created from the owner's machine with the local build of `63658bc`.

**Cross-platform build difference, again.** CI's archive for `63658bc` is 2,945,768 bytes, SHA-256 `dc1ad1d0733e8ff819fd0f2547fd4061d39e5947ebad400dad778dd4a407fa26`, byte-identical across its two builds and 1,502 manifest files verified, but different from the local Windows build (2,950,729 bytes). The CI artifact was not downloaded for this receipt, so member contents were not compared this time; for 1.1.0 the members were identical and only zlib's compressed bytes differed. The receipts' `vendor_composer_lock_sha256` values also differ (CI `1fbf19c7…`, local `3866a53b…`): hashing the committed `composer.lock` blob with LF gives the CI value and with CRLF the local value, so that difference is the Windows checkout's `core.autocrlf=true`, not different dependencies. The 1.1.0 follow-up (identical compressed bytes across platforms, or member comparison in `tools/build-release.py --compare`) stands.

**Repository hygiene.** The merge commit `63658bc` carried 5.9 MB of release build output under `build/` that neither parent had: 6 files, 5,898,633 bytes, the two `c731ced` builds (`build/release-1.1.1` and `build/release-1.1.1-check`, each a 2,948,511-byte ZIP with its `.sha256` and receipt). `git add -A` ran after the first package build and `build/` was not yet ignored. It is ignored from `9a25d63` onward and untracked there. The published ZIP is defined by `tools/package-files.json`, never by the working tree, so the artifact is unaffected; the tag was left in place because retagging would have meant rebuilding and republishing to fix hygiene only.

## Demo

| Item | Value |
|---|---|
| Release | **not refreshed**, at the owner's choice; still `core-1.1.0-af0f4895f85a` (source `af0f4895f85a9c9c8b2107b5b919428c9919d551`, version 1.1.0, cut over 2026-09-19 15:20:41 UTC) |
| Public check | `python tools/hosting-status.py` at 2026-09-20 18:19:28 UTC: `/demo/health` 200 `ok`; web, scheduler and db containers running on image `phpledger-demo-app:core-1.1.0-af0f4895f85a`; no content overlay |
| Consequence | the demo does not show the Workbench installer or any 1.1.1 change; issue #88 (POS cash tender precision), reported against the demo, remains visible there |

## Website

| Item | Value |
|---|---|
| Release | **none**. Source updated in `9a25d63` ("Record 1.1.1 across the README, wiki and website", 2026-09-20 16:48:24 UTC, on `master`): `site.json`, `releases.json`, download, home and news pages, a new `/news/1-1-1/` page, and the historical news pages pinned to their own media kits; rebuild 72 pages, 0 errors, 0 warnings (AGENT_MESSAGES.MD). **Not published** |
| Live root | `website-1-1-0-roadmap-20260919-185525` (`tools/hosting-status.py`, 18:19 UTC; nginx validation passed) |
| Live content checked for this receipt | `https://phpledger.com/releases/index.json`: stable channel **1.1.0** (`generated_at` 2026-09-19); `/download/` mentions 1.1.0 39 times and 1.1.1 never; `/news/1-1-1/` returns **404** |
| Release feed | still offers 1.1.0; a 1.1.0 installation is offered nothing. The unpublished source feed at `9a25d63` lists 1.1.1 with `"update_json": null`, so even after republication `/maintenance.php` could not install 1.1.1 |

## Other surfaces

| Surface | Status |
|---|---|
| `www/phpledger/VERSION`, release notes, `UPGRADE.md` 1.1.0→1.1.1 section | updated in `63658bc` (`VERSION` reads `1.1.1` at the tag); the notes and upgrade section carry the erratum below |
| README, release badge, roadmap | updated in `9a25d63`; the README and roadmap carried both errata until corrected (see Errata) |
| GitHub Wiki | Home, Getting Started, sidebar updated; new Release-1.1.1 page. Wiki commit `0772a10` "Record the 1.1.1 release" (4 files, 42 insertions) is **pushed**: `git ls-remote` on the wiki remote returns `0772a10` for `master`, and the clone at `.cache/release-1.1.0/wiki` is level with it and clean. The page is identical to `docs/wiki/Release-1.1.1.md` at `9a25d63` and carries the "no migration" erratum. The wiki Roadmap page (last changed `b1d2d16`) still says the demo packs left the ZIP |
| Repository About | **not updated**: `gh repo view` on 20 September evening still reads "… 1.1.0 installs like WordPress from any web folder and ships signed updates …" |
| Issue #84 | closed 2026-09-20 16:49:07 UTC |
| Media kit | none (waived) |

## Errata

Found on the evening of 20 September 2026 while planning the 1.2 release ([RELEASE-PLAN-1.2.md](../strategy/RELEASE-PLAN-1.2.md)); recorded in AGENT_MESSAGES.MD, "1.2 release plan (result)".

1. **"No schema change, no migration" is wrong.** The 1.1.1 release notes (line 9 and the upgrade line at the tag), `resources/release/UPGRADE.md` line 33 ("There is no migration in this release: the schema chain still ends at 034"), README line 39 at `9a25d63`, the wiki Release-1.1.1 page, the website news page source and the GitHub release body all say the release carries no migration. `git diff --stat v1.1.0 v1.1.1 -- www/phpledger/install/migrations/` shows `034_inventory_locations.php` (50 lines) added, and `v1.1.1` also ships `resources/modules/inventory-locations.json` and `templates/partials/ui/inventory-locations.php`, none of which exist at `v1.1.0`. A fresh 1.1.1 installation applies 034 during setup; an installation upgraded from 1.1.0 by replacing files, as the notes instruct, leaves 034 pending.
2. **The demo-pack unbundling (issue #71) did not ship.** The roadmap at `9a25d63` (line 42: "the demo packs leave the release ZIP"), README line 163, the wiki Roadmap page and the website roadmap page all said 1.1.1 included it. `tools/package-files.json` at `v1.1.1` still lists all eleven `resources/demo-packs/*.json` files and the catalog for the archive. Issue #71 moves to 1.2.
3. **The signed update path does not exist for this release.** `UPGRADE.md` line 33 says "use `/maintenance.php` if you pinned the publisher key at 1.1.0", but no `phpledger-1.1.1.update.json` was produced, so a 1.1.0 installation cannot reach 1.1.1 in-app and must replace files (and then, per item 1, run `php www/phpledger/install/migrate.php` once).

**1.1.2 corrects these.** The 1.2 release plan (milestone M0, owner decision 1) puts the erratum on every 1.1.1 surface, the corrected upgrade paths from 1.1.0 and 1.1.1, issue #88, the first signed metadata since 1.1.0 (one envelope serving both 1.1.0 and 1.1.1; 1.1.1 is not signed retroactively), the version-fact sweep and the website republication into a 1.1.2 patch. The roadmap, README and `docs/wiki/Roadmap.md` were corrected in commit `54fb89e` ("Plan the 1.2 release and correct the 1.1.1 record", 18:18:25 UTC), which at the time of writing is on the local branch `fix/issue-88-pos-cash-precision`, not on `master` or `origin`. The release notes, `UPGRADE.md`, wiki release page, website pages and GitHub release body still carry the wrong statements; their wording and republication are the owner's call. At the last check for this receipt (20 September 2026, about 18:30 UTC), a further commit `3df5755` ("Release 1.1.2: the 1.1.1 record corrected, and signed updates again") and a local annotated tag `v1.1.2` pointing at it existed on the same local branch; neither was on `origin`, and no `v1.1.2` GitHub release existed. That release, when published, gets its own receipt.

## Open items

- **The errata above.** Every public 1.1.1 surface except the corrected roadmap/README sources still says "no migration"; the wiki Roadmap and website roadmap still claim issue #71.
- **Website not republished:** phpledger.com advertises 1.1.0, `/news/1-1-1/` is 404, and the live feed offers 1.1.0 only.
- **Demo on 1.1.0** at the owner's choice; issue #88 is fixed in 1.1.2 (commit `06e1ca9`, pull request #89) but still visible on the demo.
- **Repository About** still describes 1.1.0.
- **No signed update metadata**, so 1.1.0 installations cannot use `/maintenance.php` for this release; 1.1.2 is to restore the signed path.
- **Key custody:** a cloud backup of `C:\phpledger-signing-key` was recorded on 19 September 2026 (AGENT_MESSAGES.MD); the offline copy on removable media is still uncreated.
- **Cross-platform reproducible compression** (above); CI member contents were not compared for this release.
- **MariaDB 10.4**, the advertised floor, has no CI or local evidence, and migration 034 has never run on it (release plan finding 4).
- **`build/` output in the history** of `63658bc` (5.9 MB); ignored from `9a25d63`.
- Not yet observed: a real shared host, an unfamiliar operator, independent accounting and security review, supervised pilots with a real month-end close.
- **This receipt was written retrospectively** on the evening of 20 September 2026; protocol step 12 was skipped at publication.
