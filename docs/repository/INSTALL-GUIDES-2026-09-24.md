# Beginner installation guides: plan and delivery

## Plan

Owner request: rewrite the installation guides so a first-time user can finish without getting lost. The previous edit mixed a developer Docker checkout into installation and incorrectly gave WAMP the XAMPP folder name.

1. Put a short choice of Docker Desktop, XAMPP/WAMP and shared hosting near the top of the README and Wiki.
2. Give each choice its own linear walkthrough: get the files, start or upload, fill the setup form, reach the first account. State the expected result at each stage.
3. Supply a local Docker Compose recipe using a published release, generated private database passwords, loopback access and persistent storage. Explain why pulling the application image alone is insufficient.
4. Give XAMPP and WAMP their correct folders and controls. Show exact database field values for an ordinary local setup, with exceptions clearly named.
5. Show hosting users how to create and connect a database using their panel. Put a copyable request for hosting support after the main steps.
6. Keep developer and operator reference material available at the end. Add small symptom/action tables instead of sending beginners through logs or source code.
7. Check links, YAML, the copyable commands and an isolated Docker first installation. Record untested hosting environments explicitly.

## Scope and ownership

Work is local in `C:\phpledger`, based on main `1647c965` with the previous guide edits retained as the revision baseline. Main is older than the release lanes; current release files and public metadata are read-only evidence. No application changes or migration changes are planned. Active release work and unrelated planning remain with their current owners. Baseline diff: `.cache/installation-guides-20260924/before.patch`.

Coordinator: README, Docker walkthrough/recipe, reference entry links, Wiki navigation and this receipt. Bounded writer: new XAMPP/WAMP and shared-hosting walkthroughs. Read-only reviewer: published container and installer contract. Publication is a later action.

## Delivery evidence

Implemented on 24 September 2026. The README now opens with a route chooser. The Wiki Getting Started page is a short hub; the previous mixed historical install text was replaced. Three walkthroughs contain download choices, numbered steps, field tables, expected results, completion and troubleshooting. XAMPP/Wampserver use their distinct paths, version and extension controls. Shared hosting covers cPanel and Plesk separately. Technical references remain linked at the end. The Docker operator reference replaces the earlier incorrect GHCR-only assertion and developer-install recommendation.

### Changed files

- `README.md`: installation chooser before technical/product detail, recipe link, current download link and historical label on the old 1.1.3 section.
- `compose.desktop.yaml`: new loopback-only release recipe, pinned application 1.4.0, MySQL 8.4, two persistent volumes, generated-password requirements, no fixed subnet, optional installation notice disabled.
- `docs/wiki/Getting-Started.md`: rewritten installation hub.
- `docs/wiki/Install-with-Docker-Desktop.md`: new Windows release-image walkthrough.
- `docs/wiki/Install-on-XAMPP-or-WAMP.md`: new local Windows walkthrough.
- `docs/wiki/Install-on-Shared-Hosting.md`: new cPanel/Plesk walkthrough.
- `docs/wiki/_Sidebar.md`: links to all three guides.
- `docs/wiki/PHP-Hosting.md` and `resources/release/INSTALL.md`: beginner entry links before advanced requirements.
- `docs/CONTAINER.md`: operator reference, backup/restart/upgrade context and corrected registry information.
- This receipt, `AGENT_MESSAGES.MD` and `AGENTS_SYNC.MD`: scope and handoff evidence.

### Executed checks

- Live read-only release/registry metadata: latest published release `v1.4.0`; image tag `1.4.0` exists at both GHCR and Docker Hub, with index digest `sha256:a170945385c9cd2dc604d1a2c6889ad7af02b64cbd8b0c3d6bb49c9afcf2edf9`. Version 1.4.1 was not used as a published guide requirement.
- `docker compose ... config --quiet`: desktop YAML parsed successfully. Browser test used the same recipe with only the Compose project name and loopback port overridden to isolate it from other work: `phpledger-guide-check-20260924`, `127.0.0.1:18324`.
- The documented PowerShell block parses successfully. Password creation ran in a fresh task directory. Repeating it retained the existing `.env` byte-for-byte.
- Pulled the published 1.4.0 image and ran `up -d --wait`; both web and database became healthy.
- Playwright followed the actual browser journey: `/install` → Start setup → explicit DB field values → Check database → Install database → Database checks → Save private configuration → Account → Finish and create your business → complete → `/onboarding`. Completion showed signed-in fictional `guidecheck`; no real user/customer data. Anonymous notice and named registration were disabled during the run.
- The published image's form did **not** prefill DB host/name/user/password from its environment. The explicit field table in the guide is necessary. No remote-host setup code was requested for the configured `db` service.
- The installer reported all 61 existing migrations applied, six database checks passed, MySQL 8.4.11, 190 protective database rules and two readable report views. These are observations of the isolated installation, not new source migrations or independent accounting assurance.
- Stop/start with the same recipe succeeded. The installation receipt persisted and the authenticated browser returned to business setup. Containers, network, both task-owned volumes and temporary `.env` were removed after evidence capture; other project resources were untouched.
- Local/repository/Wiki link-target and code-fence checks pass; PowerShell syntax and `git diff --check` pass. Public links to new pages/files are staged publication targets, checked against local files; they are not claimed live.
- An independent read-only review identified omitted Docker buttons, missing Plesk instructions and insufficient Windows prerequisite help. All three were addressed. The only browser console issue observed was the existing missing `/favicon.ico` (404); it did not block installation.

### References and limits

Read local coordination, README, architecture, roadmap, package installation reference, release Compose/entrypoint and current installer templates/functions. Platform instructions were checked against [Docker Desktop Windows setup](https://docs.docker.com/desktop/setup/install/windows-install/), [XAMPP Windows FAQ](https://www.apachefriends.org/faq_windows.html), [Wampserver downloads and support information](https://wampserver.aviatechno.net/), [cPanel Database Wizard](https://docs.cpanel.net/cpanel/databases/mysql-database-wizard/), [cPanel File Manager](https://docs.cpanel.net/cpanel/files/file-manager/110/), [Plesk tutorial](https://docs.plesk.com/en-US/obsidian/quick-start-guide/plesk-tutorial.74376/) and [Plesk database users](https://docs.plesk.com/en-US/obsidian/customer-guide/website-databases/managing-database-user-accounts.69539/). No Google Drive document was required or read.

No new migrations or application-schema changes. The disposable test database received the release's existing schema. No PHP or JavaScript runtime file changed, so runtime lint/full accounting suites were not applicable. No raw credentials appear in the guides or this receipt. External activity was public documentation/registry reads and package/image downloads; no publication, hosting change, message, payment, or production mutation occurred.

XAMPP, Wampserver, cPanel and Plesk were source/documentation reviewed, not installed on those platforms in this pass. A fresh Docker Desktop installation on a new Windows machine and an unfamiliar-user usability session were not performed. Shared-host settings vary; each guide provides a route to the installer checks and hosting support. The broad historical release/product wording elsewhere in the old main checkout is outside this guide rewrite.

Before publication, integrate this bounded file set into the current release branch and publish the repository recipe plus all new Wiki pages together. Until then, use the local Markdown files. No commit, push or Wiki publication was performed by this task.

## Owner-authorized release handover, 24 September 2026

The owner subsequently instructed: "make this live, infact handover to the other session to fold in new release". This authorizes integrating and publishing these guides and the desktop recipe with the active new release. The receiving task is **Fix petty cash balance controls** (`01a0ceaf-3adb-7681-a122-28aa84fcbb01`), currently publishing 1.4.1 from `.cache/release-1.4.1`.

Merge the installation sections into that task's newer README and Wiki content; do not replace release metadata with this older main checkout. Coordinate the repository file, new Wiki pages, sidebar and release links. Preserve the accepted application archive/tag: these documentation and recipe changes should be published alongside the verified release, with package inclusion determined by the existing allowlist. If updating the desktop recipe from 1.4.0 to 1.4.1, first confirm the new image is publicly available and rerun its bounded first-install/restart check; retain the 1.4.0 proof above as historical evidence. Record the resulting public URLs and verification in the release handoff. This task delegates publication to the existing release owner.

## Integration into the 1.4.1 release branch

The frozen guide set was merged into the current release README, container reference, package installation reference and current Wiki text on 24 September 2026. The three walkthroughs and desktop recipe are staged with image tag 1.4.1. The earlier 1.4.0 browser proof above remains historical; the 1.4.1 image first-install and restart check is pending public image availability. The accepted application archive and tag were not changed by this documentation integration.

The frozen handoff manifest's 11 SHA-256 values were verified before integration. The merged README and current Wiki text retain the 1.4.1 release wording and links. `docker compose -f compose.desktop.yaml config --quiet` passed with validation-only password placeholders. `tools/package-files.json` does not include any changed guide, recipe or receipt path, so this addition does not alter the accepted application archive. The release-repository and local Wiki diffs passed `git diff --check`. Registry lookup for `ghcr.io/phpledger/phpledger:1.4.1` returned `manifest unknown` at this checkpoint; the image install/restart acceptance is still pending.

### Published 1.4.1 image acceptance

The container-image workflow [run 35945324450](https://github.com/phpledger/phpledger/actions/runs/35945324450) succeeded for tag source `9e101439e8798815dfa5892d37924bac9d1f9a1e`. GHCR and Docker Hub resolved the pulled `1.4.1` image to `sha256:94599d3e498f9eb22ea5caa10453276cc01811128b4d32da1617569ab91c623f` locally. The guide integration was committed locally as `3d4b117d` in the release repository and `c8044db` in the local Wiki checkout; neither commit was pushed by this guide check.

Using the committed `compose.desktop.yaml`, a disposable Compose project `phpledger-guide-141-check` opened only at `127.0.0.1:18341` with generated private test passwords. `docker compose config --quiet` and `up -d --wait` passed; the web and MySQL 8.4.11 services became healthy. Playwright followed `/install`: Start setup, explicit `db:3306`/`phpledger` connection, Install database, six passing checks with 61/61 migration checksums, Save private configuration, and a fictional `guidecheck141` account. Optional installation notice and named registration were left off. The Ready page stated 155 tables/views, 190 protective rules and signed-in account, then the browser reached `/onboarding`.

The same project was stopped and restarted with `up -d --wait`; both services returned healthy. The signed-in browser still reached `/onboarding`, while `/install` returned the expected locked-installation page (HTTP 404). This proves the bounded 1.4.1 Docker guide path and persistence across a routine stop/start; it does not prove an unfamiliar user's experience, shared-host installation or independent accounting/security assurance. The task-owned containers, network and volumes were removed after the check; the two generated credential files were overwritten to zero bytes. No other Docker project was touched. No application archive, migration, schema, tag or production environment was changed.
