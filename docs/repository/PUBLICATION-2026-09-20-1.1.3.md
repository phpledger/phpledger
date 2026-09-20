# Publication receipt: PHP Ledger 1.1.3

Published 20 September 2026, a stable patch release. Machine-readable copy: [STABLE-1.1.3-PUBLICATION.json](STABLE-1.1.3-PUBLICATION.json).

## What was released

Fixes issue #90: the 1.1.2 in-app updater failed at the migrate phase with a duplicate declaration of `pl_database_platform()` between the copied recovery runtime and the application. `install_functions.php` and `update_database_functions.php` now keep whichever copy of the platform helpers is already loaded, with a documented one-release compatibility rule at the top of `database_platform_functions.php`. A new test, `tests/update-migrate-test.php`, runs the real migrate and verify phases under the copied recovery runtime (it fails on 1.1.2's code and passes on 1.1.3's) and is registered in the CI MySQL job and the MariaDB matrix.

Release commit `521dbc3` (tag `v1.1.3`, merge of `fix/issue-90-updater-migrate` into `master`), published 2026-09-20T20:40:01Z. Later commits on `master` the same day corrected upgrade-path wording: `319cf5f` (README, wiki, website), `00ce9ce` and `63bcb39`, plus this receipt's own commit.

## Artifacts

| Item | Value |
|---|---|
| Tag | `v1.1.3` → `521dbc3` (package source) |
| Published | 2026-09-20T20:40:01Z |
| `phpledger-1.1.3.zip` | 2,952,255 bytes, 1,502 files verified against the package manifest, SHA-256 `208c0a089ee32d377116026a4d0401790d21706399a5c63b5f5f818a4d3b5482` |
| `phpledger-1.1.3.zip.sha256` | 86 bytes |
| `phpledger-1.1.3.update.json` | 312,687 bytes, SHA-256 `c285f3aa11dc9db697c4ae62ca5750e8103f360f4a1ab19a062a3ae885cb2002`, signed by the owner with the official key via `.cache/release-1.1.3/sign-1.1.3.py`, 1,503 files in the signed inventory |
| Media kit | **none** (patch release, decision B29) |
| Publisher key | unchanged (RSA-4096, SPKI DER SHA-256 `4e58a5f46b0538c9b37aaadbfced9a2d8ad2f7d413bc168a67b1b94feaa78e78`) |

All three assets were re-downloaded anonymously from GitHub after publication and hash-verified.

## Evidence

| Check | Result |
|---|---|
| Build | two local builds from the tag byte-identical (2,952,255 bytes, `reproduction: bytes`); CI Release build succeeded on `521dbc3` and created the draft; the tested local archive was uploaded over the draft's asset before publication |
| `composer check` on MySQL 8.4 | 320 tests, 0 failures; lint 242 files; PHPStan clean |
| Update and installer tests | update-recovery, update-database, update-fullschema, update-http, browser_installer (54 checks) and the new update-migrate test all pass |
| MariaDB 10.4.34 | the whole battery also passed on MariaDB 10.4.34 the same day, at 319 tests (run before the update-migrate test was added) |
| Signature | signed with the official key; verified as an upgrade from 1.1.0, 1.1.1 and 1.1.2; rejected for 1.1.3 (correctly cannot upgrade to itself); 1,503 files in the signed inventory |
| Upgrade proof, 1.1.2 → 1.1.3 through the in-app updater | published archive and published signed metadata, disposable MySQL 8.4.9 schema: `backup > apply > migrate > verify > runtime > complete`; `public/maintenance.php` byte-identical before and after |
| Upgrade proof, 1.1.1 → 1.1.3 through the in-app updater | same sequence, applying migration 034 on the way; `public/maintenance.php` byte-identical before and after |
| Upgrade proof, 1.1.0 → 1.1.3 through the in-app updater | `backup > apply > migrate > recovering > restored` — the installation was restored to 1.1.0 with 34 receipts and nothing lost; `public/maintenance.php` byte-identical before and after |

The 1.1.0 failure is tracked as **issue #91**: `pl_update_database_connect()` keeps the installed release's `database_platform_functions.php` for the whole operation, and the 1.1.0 copy predates `pl_database_require_trigger_support()` (added in 1.1.1 for issue #83), which the 1.1.3 `pl_migrate()` calls at `install_functions.php:224` and `:292`. The #90 fix holds across one release step, not three. Logs for all three upgrade proofs live outside the repository, under `build/inapp-proof-113/` on the releasing machine.

## Demo

| Item | Value |
|---|---|
| Release | **not refreshed**; still `core-1.1.0-af0f4895f85a` |
| Stated on | release notes, wiki and website |

## Website

| Item | Value |
|---|---|
| Release | `website-1-1-3-20260920-205507`, published 2026-09-20T20:56:37Z from `63bcb39` through `.cache/publish-website-1.1.3.py`: 308 files, archive SHA-256 `d9ab31aeac1bdbdd1538e042d71466c457fb376e91c0a912c56f1ace64db144b`, vhost SHA-256 `069b577d391a7cf0ffa48019f34de98933c1e511bd825a62826769368ddcd6bb`, backup `/var/www/phpledger/data/backups/website-1-1-3-20260920-205507`; only the document root changed; demo untouched |
| Live checks | the release feed offers stable 1.1.3 with `update_json`; `/releases/1.1.3.update.json` is byte-identical to the GitHub asset; `/download/`, `/news/1-1-3/` and `/roadmap/` carry the corrected upgrade wording |
| Earlier attempts | `website-1-1-3-20260920-204637` was extracted on the host but never switched, because a re-run hit the publisher's "release directory already exists" guard; its directory remains unused on the host. `website-1-1-3-20260920-204912` switched and then **rolled itself back** when a wrong page check in the wrapper failed, leaving 1.1.2 live until the corrected run above |

## Wiki and other surfaces

| Surface | Status |
|---|---|
| GitHub Wiki | pushed; latest commit in the wiki clone: `e822ef4` ("Record the 1.1.3 release and the proven upgrade paths") |
| Repository About | names 1.1.3 |
| v1.1.2 GitHub release page | carries a "Fixed in 1.1.3" note |
| Issue #90 | closed with evidence |
| Issue #91 | open, on milestone 1.2 |

## Open items

- Independent accounting review, independent security review — not yet observed.
- Supervised pilots with a real month-end close — not yet observed; pilot #1 starts on 1.1.3 on 1 October 2026.
- Unfamiliar-operator observation, restricted shared-host recovery — not yet observed.
- **Issue #91**: installations more than one release behind 1.1.3 cannot yet complete the in-app update in a single hop.
