# PHP Ledger 1.4.5 publication receipt — started 26 September 2026

Owner instruction of 26 September 2026: "publish 1.4.5". This receipt follows the per-release sequence in [`docs/RELEASE-PROTOCOL.md`](../RELEASE-PROTOCOL.md) and is completed step by step; a step not marked done below has not happened.

| Step | Status | Evidence |
|---|---|---|
| 1. Freeze and version | done | Branch `claude/setup-1.4.5` (from `codex/release-1.4.1`): mockup `c0804987`, W1 `e731a2d6`, W2 `c9ffaa9d`, W3 `6c35ab15`, W4 `c3353a97`, country column `50ae1169`, W5 `8d4b8ab7`, W6 `f253f2b4`, W7 `c5b46627`, receipts `98d2cba9`, PHPStan cap `cb59e3ea`; `git branch --merged` confirms every listed commit is on the branch, and the release notes were written from that list. `www/phpledger/VERSION` is `1.4.5`. Local gates: [RELEASE-1.4.5-GATES.md](RELEASE-1.4.5-GATES.md). |
| 2. Tag | done | Annotated tag `v1.4.5` at `c5b46627` (the commit the gated archive was built from; the two later commits carry receipts and analysis configuration, neither packaged), tag object `8b29fe23`, pushed to `origin` on 26 September 2026 04:30 UTC. Unsigned annotated tag, as `v1.4.1` was. |
| 3. Build in CI | done | `release.yml` run 36218047246 (tag push) succeeded: build, byte-identical rebuild, artifact `release-1.4.5`, **draft** release `v1.4.5` with `phpledger-1.4.5.zip` (3,629,752 bytes, SHA-256 `20f0ec2404e71e6334e8c07efdc1eb3343f4e7988ecb5e3043b2c7cd9009f59e`) and its `.sha256`. |
| 4. Reproduce locally | done | `tools/build-release.py --commit v1.4.5 --compare <CI asset>`: `reproduction: members` (same member set and per-member SHA-256; the CI zip compresses differently). The locally built, gated archive is `.cache/release-1.4.5/phpledger-1.4.5.zip`, 3,635,906 bytes, SHA-256 `fa90a69cacd084621958ea44b53a8e596ac2b64b6af351d40726c52d92ead96c`, 1,728 members, built and gated from `c5b46627`. |
| 5. Sign offline | pending (owner) | `php tools/sign-update.php --archive=<gated archive> --key=<private key> --output=phpledger-1.4.5.update.json` on the signing machine, against the gated archive above. |
| 6. Media kit | skipped | Patch release; the patch-release policy carries no media kit. |
| 7. Publish the release | pending | Upload the signed gated archive over the draft's asset, attach the update metadata, publish; record the published asset hashes here. |
| 8. Feed | pending | Add 1.4.5 at the top of `www/website/src/releases.json`, update `release` in `site.json`, copy the signed metadata to `/releases/1.4.5.update.json`, publish the website through the reviewed operator lane. |
| 9. Container image | pending | `container-image.yml` runs on the published release; record the pushed digest here. Docker Hub republish is a manual dispatch (`docker-hub-push.yml`). |
| 10. Registries and catalogues | pending | Packagist follows the tag; bump the image tag in `resources/distribution/*` manifests. |
| 11. Upgrade proof | pending | In-app update from a disposable published-1.4.1 copy through the signed metadata and feed; container tag pull; `install/upgrade.php` on an opened package. |
| 12. Receipt | in progress | This file and `PUBLICATION-2026-09-26-1.4.5.json`. |

Nothing outside the named release surfaces was touched: no demo or host cutover, no customer database, no message, campaign or payment.
