# PHP Ledger 1.3.0 published container verification

Recorded 23 September 2026. Both official registries passed independent download and managed-file verification for both supported architectures. This receipt verifies published image contents; it does not claim a production deployment or an additional runtime/database test.

The release-triggered [Container image run 35808808018](https://github.com/phpledger/phpledger/actions/runs/35808808018) completed successfully at tag `v1.3.0`, source `9a4d2872545e232d5c94933e701e5c5ae8cd1de1`. The workflow consumed the published archive and checksum and pushed both registries. No duplicate workflow was dispatched for this verification.

## Immutable published identities

Both `ghcr.io/phpledger/phpledger:1.3.0` and `docker.io/phpledger/phpledger:1.3.0` returned the following identical OCI identities:

| Object | SHA-256 digest |
| --- | --- |
| Multiarchitecture index | `sha256:5277187a140c459c852f6b1cb1b7649a1754c189ebaef426d289c94c2d890d73` |
| Linux AMD64 image manifest | `sha256:82af4cc738ae9df85073c652f467afc11ffe22428e70e1370de4545ae1378105` |
| Linux ARM64 image manifest | `sha256:0dcbc1cde6b52d0b8efe727de27f05a481887efb555b7ab86bfdaa96078748b4` |

Use the repository plus the index digest to pin deployment while retaining architecture selection. The index also contains BuildKit attestation manifests; these were not mistaken for runnable platforms.

## Observed verification

Each registry's AMD64 and ARM64 image was separately pulled by its immutable platform manifest digest. A temporary container was created with networking disabled solely to copy `/var/www/phpledger`; it was **never started**, then removed with its anonymous volumes. No application or database was launched, and no other container was changed.

All four copies passed:

- `VERSION` is `1.3.0`.
- `PACKAGE-MANIFEST.json` is byte-identical to the officially signed release ZIP's manifest and identifies source `9a4d2872545e232d5c94933e701e5c5ae8cd1de1`.
- All **1,648 managed files** have the exact SHA-256 hashes in that manifest; managed paths were checked for unsafe traversal and symlinks.
- The reference ZIP SHA-256 is `f2103a58bf3202f8e82bd974114b713b086b0e68825c5111442110bc87b97e12`, matching the [rc4 artifact gates](RELEASE-1.3.0-RC4-GATES.md). Its official update envelope SHA-256 is `e6808a8bc20cbc5c6543d559b4005bc761b443fafde23f0a16bf17622d97f9ff`.

## Retained operator evidence

Local ignored evidence is under `.cache/13-artifact-gates/.cache/registry-1.3.0-evidence/`: raw and readable OCI indexes for both registries, four successful pull logs, the four copied package trees, and `receipt.json`. The JSON receipt SHA-256 is `4da49da41a70062f512cde9efbf441220db7ea46dc6381138e63fe9a25b92aa6`. The local verifier is `.cache/13-artifact-gates/.cache/verify-published-images.py`; Python compilation passed. All temporary verifier containers were confirmed absent after completion.

Changed tracked file: this documentation receipt only. No migrations, schema or application behavior changed. Public GitHub/registry read requests and image downloads were made; no private signing key or secret was read or exposed. No live/production system was modified by this gate. Runtime upgrade/recovery evidence remains separately recorded in the linked artifact receipt; this gate does not attest every operating-system file or independently validate BuildKit attestations.
