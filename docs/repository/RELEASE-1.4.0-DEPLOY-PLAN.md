# 1.4.0 website and shared-demo deployment plan

Prepared locally on 23 September 2026. This document is a reviewable operator plan, not a deployment receipt. The coordinator owns release publication, artifact acceptance, read-only baseline capture, upload, execution and public verification. No live action was performed while preparing these helpers.

## Reviewed baseline and source boundaries

The coordinator's pinned-SSH check at 16:25 UTC reported application 1.3.0 at `9a4d2872545e232d5c94933e701e5c5ae8cd1de1`, hosting root `release-1-3-0-20260923-hosting-r2`, website root `release-1-3-0-20260923-website`, and matching web/scheduler image. Refresh these facts immediately before staging. The existing 1.3 web container also has its separate immutable Apache override; its full Compose dependency paths and hashes must remain intact.

References read: `AGENTS_SYNC.MD`, latest `AGENT_MESSAGES.MD`, README, Architecture, Roadmap, [Demo operator runbook](../DEMO.md), website README, [1.3 publication receipt](PUBLICATION-2026-09-23-1.3.0.md), local `.cache/1.3-deploy-operator` and integration worktree `.cache/release-evidence` staging/HTTPS/runtime-verification helpers. No Google Drive document was required or read.

The new ignored operational files are under `.cache/release-1.4-operator/`: `prepare.py`, `staging_helpers.py`, `host_operator.py`, `execute.py`, `test_operator.py`, and `test_execute.py`. Pure archive readers were adapted from the historical staging code. The local preparer contains no network entrypoint. The separate execution wrapper uses only `connect()` and `remote()` from the established pinned-SSH Credential Manager helper; it never invokes historical publication/retry/receiver-deployment entrypoints. The new host state machine is specific to this baseline; do not rerun the 1.3 operator.

## Exact input and local preparation

Before preparing final bundles, require the committed application source and generated website source, published immutable GHCR image digest, exact website Git-blob archive SHA-256, all eleven signed sample 1.1.0 preload directories (44 files), their reviewed inventory SHA-256, and a hashed independent verification receipt covering application/image equivalence and sample signatures. Preparation binds the receipt bytes; it does not replace signature or registry verification. Do not invent these pending values.

The coordinator can obtain a sanitized fresh host baseline using the reviewed helper through the existing Credential Manager plus pinned-host-key connection, without uploading or invoking a mutation phase: `host_operator.py --capture-baseline`. Execute the script body in memory if no remote file exists. Capture output only: container/config fingerprints, source-file hashes and the vhost hash. No environment values or credentials are emitted. Store and hash that JSON locally. Inspect the root/proxy candidate before execution.

Invoke locally, substituting actual reviewed values:

```powershell
python .cache/release-1.4-operator/prepare.py --name release-1-4-0-20260923 --app-source APP_COMMIT --website-source WEBSITE_COMMIT --image ghcr.io/phpledger/phpledger@sha256:IMAGE_DIGEST --website-archive WEBSITE_ARCHIVE --website-sha256 WEBSITE_SHA256 --samples SIGNED_PRELOAD_DIRECTORY --sample-inventory SAMPLE_INVENTORY_JSON --sample-inventory-sha256 SAMPLE_INVENTORY_SHA256 --baseline CURRENT_BASELINE_JSON --baseline-sha256 BASELINE_SHA256 --verification-receipt VERIFICATION_JSON --verification-sha256 VERIFICATION_SHA256 --output NEW_OUTPUT_DIRECTORY
```

Preparation creates three finite archives, exact per-file inventories, operator input, the host operator, verification receipt and staging manifest. It requires a nonexistent output directory; it never connects or uploads. Website bytes must equal the committed public tree. Public directory package/envelope bytes must equal the demo's signed 1.1.0 files. The fixed initializer subshell and exact-peer HTTPS expression must be present in the committed hosting source.

After independent review, coordinator uploads only those manifest-pinned files using the existing pinned SSH/SFTP lane. Create distinct immutable release roots `<name>-hosting`, `<name>-samples`, `<name>-website` directly under `/var/www/phpledger/data/releases`, and a root-only private operator directory. Extract only the finite archives just produced, preserving mode 0644 including the MySQL initializer; no symlinks, extras, scripts or repository root are allowed in the public site. Recheck archive and expanded-file hashes remotely. Upload/staging is deliberately not implemented in the local preparer.

The separate `execute.py` implements this finite upload/extraction lane. Its default only checks local files and never opens a connection:

```powershell
python .cache/release-1.4-operator/execute.py --staging PREPARED_DIRECTORY --manifest-sha256 EXACT_STAGING_MANIFEST_SHA256
```

Only the coordinator executes the following explicit remote actions. Substitute the actual prepared release name; both remote root arguments must exactly match the host operator's fixed paths:

```powershell
python .cache/release-1.4-operator/execute.py --staging PREPARED_DIRECTORY --manifest-sha256 EXACT_STAGING_MANIFEST_SHA256 --action stage --remote-operators /var/www/phpledger/data/private/RELEASE_NAME-operators --remote-releases /var/www/phpledger/data/releases --execute
python .cache/release-1.4-operator/execute.py --staging PREPARED_DIRECTORY --manifest-sha256 EXACT_STAGING_MANIFEST_SHA256 --action phase --phase preflight --remote-operators /var/www/phpledger/data/private/RELEASE_NAME-operators --remote-releases /var/www/phpledger/data/releases --execute
```

Staging reserves a new root-owned 0700 private directory, uploads exactly six manifest-pinned payload files plus their manifest using exclusive creation, validates them again on the host, then manually extracts only the three validated finite regular-file archives into new immutable release roots. It never overwrites, follows links, runs uploaded content, launches a service or cuts over. Exact modes are 0600 for private payloads, 0755 for new release directories and 0644 for extracted files. The existing initializer therefore retains its tested sourced-file mode. Current website paths longer than 100 characters use TAR PAX path records; only the exact validated path override is accepted, with all other extended metadata refused. Member/file/total sizes, counts, SSH/SFTP operations and phase commands are bounded.

Each subsequent phase needs its own explicit `--action phase --phase prepare|launch|cutover|rollback` invocation; there is no automatic next phase. The wrapper reports sanitized JSON only, with release, phase, input/manifest hashes and file counts where applicable. Keep those stdout receipts in coordinator evidence outside the immutable staging directory. A successful extraction also writes a private `stage-complete.json`. Failures suppress raw SSH/operator output and require inspection before retry; interruption may leave partial immutable staging or an unconfirmed phase, so never delete state or blindly rerun it. Rollback checks the pinned manifest, exact operator/input and private receipts without requiring the failed new archives or trees. The old baseline/private-backup/current-vhost safeguards remain enforced by `host_operator.py`.

## Finite host phases and rollback

All phases require `--input /private/path/input.json --input-sha256 EXACT_INPUT_SHA`. Default is read-only `preflight`; mutations additionally require the named `--phase` and `--execute`. Host root and the existing root-owned 0700 private directory are required. Mutations share the existing exclusive release lock. Commands have bounded timeouts; no secret output or raw command logs are persisted locally.

1. **Preflight:** refuse changed baseline containers, image, source, Compose/Apache/env hashes or Nginx bytes; require exactly the current 1.3 baseline. Refuse any existing new project, overlapping subnet, port or volumes. Validate all exact artifact trees and Nginx configuration.
2. **Prepare:** pull only the exact official image digest and inspect its package version/source. Create one root-only backup directory, current Nginx copy, baseline configuration backups and fingerprints, candidate Nginx bytes, private generated demo credentials, and the receipt. Credential/config backups remain exclusively on the server with mode 0600; they must never be copied into release artifacts or local evidence. Pin new DB and proxy services to the currently running DB/proxy image IDs. New app/reset/scheduler all use the published immutable application image. No running service changes in this phase.
3. **Launch:** create project `phpledger-demo14`, private subnet `10.204.85.0/24`, proxy peer `10.204.85.10`, loopback port `18204`, and new project-specific DB/runtime volumes. No old volume is mounted. Use the existing `shared-demo-reset.php --now` and scheduler, without a new provisioning pathway. Require first DB health without restart, correct images/mounts, internal network and loopback-only proxy. On launch failure, stop only the new project and preserve all resources; old public routing remains unchanged. A retry requires a new reviewed plan, not marker deletion.
4. **Cutover:** after loopback installer/browser acceptance, replace exactly the old static website root and demo upstream `18202` with the new root and `18204`. The installation-notice route at `18302` and all other vhost bytes stay unchanged. Validate/reload Nginx; failed local checks restore the old routing. The complete old 1.3 project, scheduler, volumes, source, Apache override and images remain running for rollback.
5. **Rollback:** while the expected baseline remains intact, restore the exact old vhost, validate/reload Nginx and stop only the new project. Rollback validates pinned input, private backup receipt/files, old runtime and expected current vhost, but does not read the failed new artifact trees or Compose file. It stops only inspected containers with the finite new-project names, project label and expected working directory. Every forward phase still validates all new trees. Never delete volumes/networks, rewrite migration receipts, replay SQL dumps, or pair old code with the new schema. The old isolated 1.3 runtime remains the rollback target. This is a disposable demo release, not a customer-data migration or a historical point-in-time database restore.

The operator uses one-shot phase markers. A failed marker or changed baseline is a review stop, not an authentication error. Do not weaken guards or delete state to retry. Keep the old runtime until public acceptance and a separately reviewed retention decision; cleanup is outside this plan.

## Acceptance after launch and cutover

Coordinator must verify the new project explicitly: current `hosting-status.py` may still assume the old `phpledger-demo` names. Running that unchanged checker after cutover can report the retained 1.3 runtime instead of the publicly routed 1.4 application.

Before cutover, verify through the new loopback proxy the clean installer, exact 1.4 package manifest/source, HTTPS forwarding behavior, restricted grants, sample signature acceptance and final loopback health. After cutover, verify public TLS, root/download/news/directory bytes and signed update/download hashes; `/demo/health`, fresh installer/onboarding, shared login, all eleven 1.1.0 samples, cash/party/help/screens at desktop/tablet/mobile widths, sessions/cookies and unchanged notice receiver route and container identities. Observe an actual next UTC-hour reset and expiry of prior sessions, then return the demo to its clean installer using the existing reset. No notice POST or external notification is implicit in operator preparation.

Record exact source/image/archive/sample pins, staged inventory, private backup location (no contents), all public proof and rollback status in the final publication receipt. Passing helper tests proves the tested local guards, not public acceptance or independent accounting/security assurance.

## Preparation validation and pending work

Local Python compilation passed for the four original helper/test files and both execution-wrapper files. Sixteen original operator guards passed. Fifteen additional wrapper guards passed, including independent review, covering finite manifest/hash checks, default no-connection behavior, explicit phase/root arguments, traversal/hidden/PHP/special/duplicate/missing-member refusal, exact modes, PAX path compatibility, size limits, duplicate JSON keys, sanitized failures and rollback without new archives. The reviewed `execute.py` SHA-256 is `d01f2985424c03bf7c92aeaf8cff01b160523c3d98aa6d2b37bc9abaddc9d43e`. No host action was performed in preparing or reviewing the wrapper. Real artifact preparation, remote execution, Docker integration and public/browser validation belong to coordinator release evidence. This subtask introduces no application migration or schema change; the release's application migrations remain owned by the coordinator. Files changed are the new plan and six ignored helper/test files. No external/live call, publication, upload, production mutation or raw secret exposure occurred during preparation.
