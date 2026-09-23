# PHP Ledger 1.3.0 rc4 exact artifact gates

Recorded 23 September 2026. **All five local artifact gates passed** for the exact candidate below, which includes the owner-requested separate Expense and Receipt screens. This replaces rc3 as the applicable artifact evidence. It is not a publication or deployment receipt, and does not qualify different archive bytes with the same version label.

## Pinned inputs

| Input | Identity |
| --- | --- |
| Candidate source | `9a4d2872545e232d5c94933e701e5c5ae8cd1de1` |
| `phpledger-1.3.0.zip` SHA-256 | `f2103a58bf3202f8e82bd974114b713b086b0e68825c5111442110bc87b97e12` |
| Official adjacent update envelope SHA-256 | `e6808a8bc20cbc5c6543d559b4005bc761b443fafde23f0a16bf17622d97f9ff` |
| Published 1.2.1 ZIP SHA-256 | `e983add7d909daf2b78151f6bc1d2195c2603fd57a711bf88e1ffb42c9c214b7` |
| Published `ghcr.io/phpledger/phpledger:1.2.1` image ID | `sha256:b41acde7d846055ce3b7d19ccf4ec2fbc09b698be25022ac6a0c946466f753a8` |
| `phpledger-release:1.3.0-rc4` image ID | `sha256:eb41ca8fdcf999d994e09892502f79b87844b012295c8ff8c7e5ae0683455b16` |

Both HTTP gates used the official publisher signature and public key, not a substitute test signature. No private signing key or passphrase was accessed. Historical fixture builders were separate from the actual published baseline runtime.

## Observed gates

| Gate | Result |
| --- | --- |
| Fresh exact ZIP | PASS: migrations, first owner/company, balanced accounting posting, no-op migration replay. |
| Populated CLI upgrade from published 1.2.1 | PASS: 14 migrations. Original accounting records, partially paid invoice, membership IDs/labels, migration receipts and balanced totals preserved. Owner company/payroll/schedules/loans grants present; replay applies nothing. |
| Official signed HTTP update | PASS: actual 1.2.1 maintenance page authenticates the operator and accepts the signed multipart upload; backup → apply → migrate → verify → runtime → complete. Historical invariants hold and every final managed-file hash matches the 1.3.0 inventory. |
| Official signed post-migration recovery | PASS: intentional bootstrap corruption introduced only after migrations triggers verify → recovering → restored. Original database values and exact migration receipts return; all old managed files match 1.2.1 and candidate-only files are absent. |
| Production image upgrade | PASS: actual published 1.2.1 image serves the populated baseline; the rc4 image starts with `PL_AUTO_MIGRATE=1`, serves health, identifies 1.3.0, and preserves the historical data, labels, receipts and balances. |

The verifier does not apply migrations before checking an already-upgraded HTTP/container candidate. It first asserts migration state and new grants, then checks the final replay is a no-op. Recovery verification runs no migration replay against the restored database; the legacy shared log summary wording does not imply otherwise.

The unchanged harness is commit `d3850b35`: `tests/artifact-upgrade-test.php`, `tests/artifact-container-upgrade-test.py`, and `tools/verify-m17-upgrade.php`. All gates ran serially against one isolated MySQL service using synthetic records. The temporary production container/volume, fixture schemas, PHP runner and database/network were removed afterward. Other project and coordinator demo containers were not changed.

## Retained evidence

Operator-local files are under `.cache/13-artifact-gates/.cache/`; they are not release/web artifacts. `artifact-rc4-baseline-receipt.json` retains the synthetic original row snapshot; `artifact-rc4-images.log` records image identities.

| Log | SHA-256 |
| --- | --- |
| `artifact-rc4-fresh.log` | `dcb04b506ab2fce46ed410f24f4ba4ab1a058ec81637ad7ac2ae563398f43ec1` |
| `artifact-rc4-cli.log` | `afe9389d073bf09b3ad311096fd253c55c3f209af1f20f82130818151679e4d8` |
| `artifact-rc4-http.log` | `6fb8520a0efa435e35f5e3c3945d9e6326256c2dbf9dfd428047397104ec8986` |
| `artifact-rc4-recovery.log` | `2a2a574d2d27602dd96bd84060483b489c7c9ed6867cf9d29f5ea5b553fcf140` |
| `artifact-rc4-container.log` | `9f0adc6c7c46f6885852dc2ca0b5ba976ef522c7750452dabae9965ee80f4800` |

This receipt adds no schema or migration; the tests exercise the candidate's approved migrations only. No real customer data, exposed real secrets, external provider calls, publication or live-system changes occurred. No Google Drive documents were used. Complete CI, responsive browser QA, media kit, public assets, website/demo deployment and independent real-book accounting review remain separate coordinator-owned evidence.
