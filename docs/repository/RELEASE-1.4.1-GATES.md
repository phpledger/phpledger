# PHP Ledger 1.4.1 local candidate gates — 24 September 2026

This is a **local candidate receipt**, not a publication, deployment or independent accounting/security assurance receipt. It identifies the tested archive rather than the mutable branch tip. The published 1.4.0 baseline archive was compared with 1.4.1 source commit `556425c13b2e0830b526a0b509a8c08d0bcc247c`.

## Frozen application artifact

| Item | Local evidence |
|---|---|
| Exact candidate | `.cache/release-1.4.1-ready/phpledger-1.4.1.zip`, 3,576,237 bytes, SHA-256 `29e3f33265f23a64bccc5dfeca4b8f512467fa9c7e6306e4096c7d5ff85f2b96` |
| Published 1.4.0 baseline | SHA-256 `53c7fc584bd47723a6f6008a8a951db9b3947dae956484c8bf3b889ce2fad4d2` |
| Package inventory | 1,697 members; exact file digests checked against `PACKAGE-MANIFEST.json`. All 61 migration files match 1.4.0. The package contains `www/phpledger/install/upgrade.php` and the neutral core starter, and omits optional demo packs. No schema change or new migration. |
| Reproduction | `.cache/validation/reproduction.json` records a byte-identical rebuild at `.cache/release-1.4.1-repro/phpledger-1.4.1.zip` with the same SHA-256. |
| Signed update preparation | `.cache/release-1.4.1-ready/phpledger-1.4.1.update.json` and `signing-receipt.json` pin the same archive SHA-256 and source commit. The signing receipt says `published: false`. No signing key material is included here. |

The release builder selects payload paths from `tools/package-files.json`. The local gate helpers `tests/patch-upgrade-test.php` and `tools/verify-patch-release.py` are outside this candidate archive; adding their source files does not change the tested payload. Patch releases carry **no media kit** under owner decision B29.

## Application and upgrade proof

`.cache/patch-1.4.1-gates-r2/receipt.json` and its step logs record the exact ZIP gates on disposable MySQL and MariaDB services. On each engine, the 1.4.1 archive installed 61 migrations, made a first balanced posting and replayed migrations without new work. The published 1.4.0 ZIP then seeded a posted receipt, expense draft, party, employee, partially paid invoice and linked reversal. Invoking the **packaged** `www/phpledger/install/upgrade.php` twice reported all migration checksums current both times. Verification compared all 61 migration receipts, selected complete table hashes and trial-balance totals with the pre-upgrade receipt; the draft, partial invoice and reversal links remained intact. Each run removed its one disposable schema. The fixture and runner are reusable through `python tools/verify-patch-release.py --help`.

Local package-builder tests passed 13/13 (`.cache/validation/package-tests.log`). Both `.cache/validation/{mysql,maria}-check.log` report PHP lint on 416 files with zero failures, PHPStan with no errors and sample validation; **full `composer check` suites are still pending**. Local browser evidence `.cache/validation/patch-browser-final.json` records targeted date, required-field, navigation and form checks at 1440, 768, 320, 360 and 390 px. Its initial matrix had nine failures; targeted recovery and marker reruns passed 22/22 and 61/61, with no unresolved findings in that local fixture. Those browser checks did not exercise the exact packaged build or a successful employee/financial write. Website local `/news/1-4-1/` and `/download/` width checks are in `.cache/validation/website-browser.log`; published website checks remain pending.

## Local demo and resource proof

`.cache/validation/demo-141.json` records a successful isolated, read-only-source-mount demo under a SELECT/INSERT/UPDATE-only runtime account: all eleven packs, 396 checkpoints, twenty additional posted records per company, visitor/capacity limits, cross-visitor/posted-edit denials, CSRF and maintenance protections. Both unauthorized reset cases refused; a successful reset replaced the generation and reduced visitors from thirteen to zero. Own demo containers/network were removed. The ignored operator wrapper needed current role-ID fixture data and an explicit default schema for cleanup; its initial fixture error and successful continuation remain in the log. This is local source-fallback evidence, not hosted signed-preload cutover or scheduled reset observation.

Both resource generator check modes passed (eleven packs and eleven sample structures). Website build/content validation passed for 102 pages with zero errors and warnings. About description, URL and topics were read and reviewed unchanged; local Wiki updates are staged in `.cache/wiki-1.4.1-review` only. Five draft Wiki pages await authorized publication. Public read-only GitHub/provider documentation calls occurred; no provider, public content, credential or production mutation occurred.

## Remaining gates before publication

1. Finish full MySQL and MariaDB `composer check`, review their complete receipts and resolve any failures. Finish local demo/sample acceptance, including signed preload and reset behavior, and record its exact scope. Confirm final website build/check and the version, download, signed-feed and social metadata against the archive above.
2. Review the final source/ZIP identity immediately before publication. Verify the signed update metadata, ZIP sidecar and release upload all pin the same archive SHA-256. Check the 1.4.1 README, release notes, Wiki and repository About metadata; mark unchanged facts as reviewed rather than changing them solely for the version. No media kit is required for this patch.
3. Only under separate publication authorization, publish the release assets and affected website/feed, sample directory, images or package channels. Download the public ZIP, checksum and update metadata anonymously and compare their hashes. Pull any published image by digest and verify its package provenance. Browser-check public desktop/tablet/phone download and changed workflows, and test the chosen upgrade channels. Record each public URL/digest in a separate publication receipt before considering issue closure.
4. Before any demo or host cutover, retain a verified current snapshot and the exact prior image/files and routing configuration. Use the existing phase-based operator runbook for staging and cutover; on failed health, hash, migration or browser checks, stop promotion and invoke its explicit rollback phase, then verify restored routing, services, data and prior public assets. A local test run is not rollback proof for a live installation.

No production, public provider, credential or external system was changed by the local gates. The [1.4.1 issue audit](RELEASE-1.4.1-ISSUES.md) tracks acceptance and deferred issues separately.
