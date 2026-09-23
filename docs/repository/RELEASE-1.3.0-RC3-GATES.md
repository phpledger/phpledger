# PHP Ledger 1.3.0 rc3 artifact gate receipt

Recorded 23 September 2026. **Historical candidate evidence only: superseded by the owner's subsequent request for separate Expense and Receipt screens.** All five gates below passed for these exact bytes. They do not qualify a rebuilt archive, authorize publication, or establish that the latest application has passed its final gates.

## Artifact identity

| Item | Verified identity |
| --- | --- |
| Candidate source | `44fb5acc390d32e6109fe4f57fdaea155e41e59b` |
| Candidate ZIP SHA-256 | `a2b4ca36ea3ff55ae1a5ac6bb7e2892d8243b41f4ad5d86d9b115dfb0f767120` |
| Official update envelope SHA-256 | `46ed586faa81ecdacc80a0a1bb98c444c141e337b61a1814d6e496cba1fe56e4` |
| Published 1.2.1 baseline ZIP SHA-256 | `e983add7d909daf2b78151f6bc1d2195c2603fd57a711bf88e1ffb42c9c214b7` |
| Published 1.2.1 image ID | `sha256:b41acde7d846055ce3b7d19ccf4ec2fbc09b698be25022ac6a0c946466f753a8` |
| Candidate `phpledger-release:1.3.0-rc3` image ID | `sha256:ce2ce02cbf50a327ce71677168312bc4ee3cee99cba03d57b2cdc0d3c6199168` |

The HTTP gates used the official signed envelope and pinned publisher public key. No private signing key or passphrase was accessed. The baseline was the published release ZIP/image; historical source fixture builders were loaded separately, not substituted for the baseline runtime.

## Observed results

| Gate | Result and observed invariant |
| --- | --- |
| Fresh exact ZIP | PASS: migrations, first owner/company, balanced posting, no-op migration replay. |
| Populated 1.2.1 CLI upgrade | PASS: 14 migrations; original accounts, periods, posted journals, partially paid invoice, membership IDs/labels, migration receipts and balanced totals preserved. New Owner grants present; replay applies nothing. |
| Official signed HTTP update | PASS: actual 1.2.1 maintenance page, operator authentication and multipart upload progress through backup, apply, migrate, verify, runtime and complete. Original data remains unchanged; every final managed-file hash matches the candidate inventory. |
| Official signed recovery | PASS: deliberate bootstrap corruption after actual migrations causes verify, recovering and restored. Exact original database values and migration receipts return; all original managed files match 1.2.1, and candidate-only managed files are absent. |
| Production image upgrade | PASS: published 1.2.1 image serves the populated baseline; candidate image with `PL_AUTO_MIGRATE=1` serves health and preserves historical rows, labels, receipts and balances. |

The candidate verifier checks already-applied migrations and Owner company/payroll/schedules/loans grants **before** its final no-op replay; it cannot finish missing migrations to make a failed updater pass. Recovery verification runs no migration replay against the restored schema, despite the older shared summary wording in that log.

The harness is tracked in commit `d3850b35`: `tests/artifact-upgrade-test.php`, `tests/artifact-container-upgrade-test.py`, and the extended `tools/verify-m17-upgrade.php`. PHP syntax, Python compilation and diff checks passed. Tests used one isolated MySQL service and synthetic fixture databases. The temporary production container, volume, fixture schemas, PHP runner and test database/network were removed.

## Retained evidence and remaining gate

Operator-local evidence remains under `.cache/13-artifact-gates/.cache/`: `artifact-rc3-{fresh,cli,http,recovery,container,images}.log`, `artifact-rc3-baseline-receipt.json`, and `artifact-rc3-receipt.md`. These logs and synthetic row snapshots are not web artifacts and are not part of the release ZIP. Earlier rejected candidate hashes are obsolete.

After the separate-screen changes, repeat fresh ZIP, populated CLI, official signed HTTP update, post-migration recovery and two-image upgrade against the **new** archive, envelope and image identities. A version label alone is insufficient. Publication, hosting/demo deployment, complete CI, media-kit verification and independent real-book accounting review remain separate coordinator-owned evidence.

No Google Drive documents were used. This receipt changes no migration/schema, exposes no raw secrets, makes no external calls and changes no live system.
