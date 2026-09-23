# Public demo operations

## 1.3 shared installer demo

The 1.3 hosting configuration uses one disposable installation (`PL_ENV=demo-install`). Visitors complete the real installer and normal onboarding; everyone then shares that installation until the next UTC hour. This section describes the release candidate. The publication receipt is the authority for what is live.

- The database stage remains in the installer, but hosting supplies every connection field. The usual fields show clearly labelled, read-only example values. No real database name, username, password, host or configuration download is exposed, and browser-submitted settings are never used. Forged fields cannot change the connection.
- The application account is public: **demo / DemoLedger123!**. It is displayed at login and during account setup. Its identity, password and installation access remain fixed so subsequent visitors can sign in. These are public application credentials, not database credentials.
- Visitors use ordinary company onboarding and accounting workflows with fictional data. A banner explains the shared workspace and hourly reset. Hosting isolates outbound services and keeps application code read-only; installing executable extensions is a self-hosted operation.
- `compose.demo.yaml` gives the application access only to its dedicated database. An edge proxy exposes the app while the app and database stay on an internal network. The root-owned host directory holds the generation marker and reset lock; the web user can write only disposable runtime storage.
- The hosting-only PHP prepend holds a shared lock through application shutdown and PHP session persistence. The reset process takes an exclusive lock, marks the generation unavailable, recreates only `phpledger_demo`, removes private configuration, installer receipts and sessions, then opens a new generation. A failed reset leaves access closed. An unmarked nonempty database is refused.
- The scheduler runs at each UTC hour. For an intentional operator reset, run `docker compose -f compose.demo.yaml --profile maintenance run --rm demo-reset --now`. Do not reuse this command against another environment or bypass its ownership checks. No app request can invoke the reset account.
- `/demo/health` checks the pinned database connection before installation and reports `installer-ready`; after installation it checks the application dependencies. It returns unavailable during reset.

Candidate evidence: all six installer stages and ordinary onboarding completed; forged database/account fields were ignored, configuration download was refused, a second visitor signed in, and a reset rejected an old session/CSRF token even after a new account reused its numeric ID. Nine desktop/tablet/phone screenshots passed overflow checks. A live local HTTP shutdown/session race confirmed that reset waited and removed all old-generation files. These checks are local, not proof of public deployment.

For this configuration, set `PL_DEMO_IMAGE` to the verified production release image (pin its registry digest) and `PL_DEMO_SAMPLE_DIRECTORY` to the private directory of signed sample preloads described below. The application and dependencies come entirely from the same release ZIP as ordinary installs; there are no source-code bind mounts. Only the Apache alias, request guard, hosting configuration, publisher public key and signed samples are mounted read-only. The web process remains unprivileged on port 8080. The isolated maintenance process runs as root only to manage its dedicated private volume and reset database; the volume root and host directory are root-owned, while runtime/session storage belongs to the web identity. Start the database, run the guarded maintenance reset, then start web, scheduler and proxy. Configure independent private database passwords, public HTTPS URL and the controlled internal proxy address before starting. The historical build commands below apply only to older deployments.

The shared configuration uses a distinct logical `shared` network named `<project>_shared_install` (default `10.204.83.0/24`, proxy `10.204.83.10`) and new `shared_demo_*` volumes. Preserve the older demo network and volumes for rollback; never rename or reuse its outward-facing logical `default` network for the isolated application. Check both subnets against the host before cutover. The same Compose project retains the established web/database/scheduler container names, while the edge proxy takes over the existing loopback port after the old web service is stopped. Force recreation when starting the new database and application services so no container retains the old network mode. Verify the old network and volume IDs remain available before declaring rollback ready.

## Historical deployments and restricted-demo operations

The sections below retain previous deployment receipts and the older `PL_ENV=demo` model. Their visitor-isolation, restricted-account and seeded-reset instructions do not describe the 1.3 shared installer configuration.

## Pending demo wording patch — 19 September 2026

The main checkout includes the sample-guide capacity correction from `c9743d4`: every sample leaves room for at least 100 new records. A local follow-up changes the demo landing eyebrow to "Explore PHP Ledger" and replaces the obsolete "development preview" label with "a resettable demonstration of PHP Ledger". It retains the hourly reset, fictional-data restrictions and the separate preview status of the seven newer sample scenarios. These wording changes require the next patch release/deployment; they were not published by this maintenance task. See the [1.1.0 publication receipt](repository/PUBLICATION-2026-09-19-1.1.0.md) for the last recorded deployed state; older release sections below are historical.

## Current accounting starter release - 0.4.0-preview

The hosted demo runs source `680fa6fa3c7a35e6865207b52f38fbf9a9663947` on PHP 8.3.33 with 79 base tables, two effective-source views, 104 guard triggers and 26 migration receipts. The authorized cutover restored and upgraded its frozen backup before switching and preserved prior data/receipts. A real HTTPS visitor then completed the ten-action order/receipt/bill/invoice/payment example, with zero AR, AP and stock reconciliation differences. The existing reset schedule and restricted database grants remain. See the [publication receipt](repository/sprint-06/PREVIEW-0.4.0-PUBLICATION.json).

## Foundations release — 0.3.0-preview

The owner-authorized 0.3.0 package and hosted demo use source `2d8f4417229f8472b2112e941f9c5f439937eed4` on PHP 8.3.33. Migrations 013–016 bring the schema to 56 base tables, two effective-source views, 62 guards and 17 migration receipts. A frozen live backup was restored/upgraded in isolation before cutover; original fields and receipts were preserved. The permanent reset account defines the views, and the restricted web account can read them after the real hourly reset. The four sample businesses and scoped read connections remain; AR/AP documents and operational workflows are still deferred. See [publication evidence](repository/sprint-05/PREVIEW-0.3.0-PUBLICATION.json).

## Combined 0.2.1 release

**Published:** [0.2.1-preview](https://github.com/phpledger/phpledger/releases/tag/v0.2.1-preview), source `a8ee38a37616cc245102404bd47b6afea9373a23`, PHP 8.3.33. The [publication receipt](repository/sprint-05/PREVIEW-0.2.1-PUBLICATION.json) records the live package, website, Wiki and thirteen migration receipts; [validation](repository/sprint-05/PREVIEW-0.2.1-VALIDATION.md) includes native Codex HTTP/STDIO checks and the actual 19:00 UTC scheduled reset on 15 September 2026. Other client results are recorded individually.

The release includes eleven packs, version 1.0.0: service agency, retail shop, seasonal business, distributor, trader, restaurant, membership club, pharmacy, jewelry studio, light manufacturing and service workshop. Each contains 85 sources (87 for the partnership, which records capital and drawings per partner) and 36 exact monthly checkpoints across 2024–2026, including three editable practice drafts. The default demo limit is 250 source and operation records, so every pack leaves room for at least 100 of a visitor’s own. The 2024–2025 history is closed; 2026 is open for practice. Manual asset, depreciation, prepayment, deferral, loan, payroll and unpaid-document schedules are illustrative support, not implemented registers, schedulers or payroll.

**A 1.2.0 demo is provisioned from scratch, not converted.** A book converted from an earlier chart by migration 040 carries a pending contra-account review, and the owner screens stay closed until someone answers it — which a public visitor cannot. A company created fresh on 1.2.0 never records that confirmation, so the samples come up with the owner, partner and contra presentation already usable. `tests/demo_pack_test.php` asserts both for every pack: no pending review, and no confirmation row at all.

`/demo/sample-guide` follows the visitor’s authorized company. Public illustrated guides live at `/guides/`. Scoped API/MCP credentials expire at the next hourly boundary and bind to the demo generation; refresh cannot extend it. Reconnect after starting a fresh sample. Migration 011 adds seven connection tables; migration 012 replaces two period guards while web and scheduler are stopped. OAuth keys belong in private storage, mounted read-only in the web service. See [Integrations](INTEGRATIONS.md) for discovery/proxy/CORS configuration. Earlier receipts below retain their dated scope; the 0.2.1 publication receipt records its separate live checks.

## Website-only publication: 15 September 2026

The approved multi-page website was published at **08:08 UTC (13:08 PKT)** as `website-redesign-20260915-080700`. Its static root was switched independently of the demo. Existing **core-0.1.2-preview-da5ff133e645** demo containers, upstream proxy target, application files and database were preserved. No demo reset or migration was run.

The website SEO plan required `X-Robots-Tag: noindex, nofollow` on `/demo/`; this header was absent in the fresh preflight and was added to the existing proxy location. Existing inherited Alt-Svc/nosniff headers were repeated to preserve Nginx behavior, and upstream CSP/referrer headers remained unchanged. Fresh `/demo/` and `/demo/health` checks returned 200 with the new noindex header; the container identities matched before/after publication. Public sample creation/posting/reset journeys were not rerun by this static release.

The marketing site now uses directory routes, canonical flat-page redirects, a custom 404 and reviewed security/cache headers. Previous website/configuration are backed up under `/var/www/phpledger/data/backups/website-redesign-20260915-080700`. See [current website QA](../www/website/design-qa.md#live-publication-15-september-2026) and [publication receipt](design/website/qa/live-20260915-publication.json). The earlier operational receipts below retain their historical scope.

The user authorized and reconfirmed publication on **14 September 2026**. The [marketing website](https://phpledger.com/) and [restricted public demo](https://phpledger.com/demo/) are now live over HTTPS. The actual **18:00 UTC automatic reset** was observed: old sample data cleared, prior sessions expired, and a clean sample could be started. This remains an accounting/POS preview; the requested reporting/POS improvements and first installable package are separate work. See [validation](VALIDATION.md#hosted-website-and-restricted-demo-publication), [sprint scope](SPRINT-02.md), [architecture](ARCHITECTURE.md), and the [POS limits](POS.md).

## Release layout and exposure

The deployed application release is `/var/www/phpledger/data/releases/sprint-02-20260914-174642`. The website has an independent static release, **`website-20260914-180808`**, including the contact correction and supporting-company logos/heading; static updates do not replace the demo runtime. Store deployment secrets separately in `/var/www/phpledger/data/private/demo.env`, readable only by the deployment operator. Neither release root nor the private directory is a web document root. Fresh backups preserve the previous website, site-specific Nginx configuration and private environment without publishing their contents.

| Surface | Current deployment contract |
|---|---|
| Marketing website | Static document root `<release>/www/website/public`; never serve the repository or release root |
| Public demo | HTTPS `/demo` reverse-proxies to `127.0.0.1:18202`, retaining the `/demo` path prefix |
| Application container | Apache serves only `www/phpledger/public`, with the `/demo/` alias and fallback in `docker/demo-apache.conf` |
| Demo database | Dedicated Compose MySQL service and named volume; no host database port is published |
| Project name | `phpledger-demo` in `compose.demo.yaml`; keep this identity stable between releases so the intended volume/network are used |
| Private state | Deployment environment file, restricted credentials, and any operator backups stay outside public roots and Git |

`compose.demo.yaml` mounts the release's application and resources read-only. The image supplies the pinned Composer dependencies and PHP runtime. Keep a published release directory immutable: a rebuilt image alone does not replace these mounted source files. Never mount legacy root code or a customer's database into the demo.

## Environment and database identities

The following is an **illustrative private environment file**, with unusable placeholder secrets. Provision three independent random secrets privately. The database initialization script requires the web/reset passwords to be lowercase hexadecimal strings of at least 48 characters; use equally strong independent material for the root password. Do not copy development/test fixture passwords.

```dotenv
PL_DEMO_RELEASE=sprint-02-20260914-174642
PL_DEMO_PORT=18202
PL_DEMO_ROOT_PASSWORD=<independent-random-root-secret>
PL_DEMO_WEB_PASSWORD=<at-least-48-random-lowercase-hex-characters>
PL_DEMO_RESET_PASSWORD=<different-at-least-48-random-lowercase-hex-characters>
PL_DEMO_LOCAL_HTTP=0
PL_DEMO_DOCKER_SUBNET=10.204.82.0/24
PL_DEMO_TRUSTED_PROXY_IPS=10.204.82.1
```

Verify that the subnet does not overlap an existing host network and that the trusted address is the actual nearest controlled proxy hop. The subnet/gateway above match the current Compose defaults; they are not universal hosting values. An environment file is not a secret manager: restrict its permissions, keep it out of backups shared publicly, and never paste resolved `docker compose config`, environment output, or credential-bearing diagnostics into a public receipt.

The Compose file sets `PL_ENV=demo`, `PL_BASE_PATH=/demo`, and the literal database `phpledger_demo` for the web service. Demo mode rejects a different database name, and normal application mode rejects this demo database. `PL_DEMO_LOCAL_HTTP=0` is required for the public deployment; the guarded loopback-only override exists solely for local HTTP testing. Public session cookies require HTTPS.

`docker/demo-db-init.sh` creates these identities on a fresh MySQL volume:

- `ledger_demo_web`: only `SELECT`, `INSERT`, and `UPDATE` on `phpledger_demo.*`. It has no schema-changing, delete, trigger-management, administrative, or grant-option privileges. The application checks current grants and refuses a web connection with forbidden privileges. Existing accounting/source/POS triggers also reject posted changes; service permissions restrict allowed operations further.
- `ledger_demo_reset`: `ALL PRIVILEGES` on **only** `phpledger_demo.*`, so its CLI maintenance process can drop/recreate that sample database and migrations. This is not a global administrator or a grant to other databases. Only `demo-reset` and `demo-scheduler` receive this credential and `PL_DEMO_RESET_MODE=1`.
- MySQL root: bootstrap/administrative identity confined to the database container's setup and health checks; never the application web account.

The init script runs only for a fresh MySQL volume. Changing a password in the environment file does not rotate an already-created database user's password. Coordinate any rotation of the database identity and consuming services explicitly; do not erase the volume to work around a credential mismatch.

## Visitor isolation, expiry, and capacity

The accounting starter adds an explicit **Accounting starter playground** choice alongside the four historical companies. Private provisioning creates a zero-balance practice company in the current year, activates Purchasing and Inventory, prepares control accounts, a sample customer/vendor, a stock product and a clearly illustrative manual tax code. It posts no historical/opening balance. The existing four historical pack files remain unchanged.

Visitors can try invoices/bills, payments, credits, orders, receipts and stock operations through the normal posting services. Account/tax/product configuration, module activation and other administration remain restricted. Existing invoice/order draft lines may be edited or added; removing a line requires a new draft in this public demo because its database identity deliberately lacks DELETE. Ordinary installations retain normal draft editing.


`POST /demo/start` requires CSRF and creates a sample user and a private sample company for that visitor. Repeated entry with the same valid session reuses its sample. Company/book permissions and the current generation are checked by server services; another visitor's IDs cannot grant access. Visitors can explore reports, edit sample drafts, post/reverse entries, and use the bounded cash POS. Setup, company switching, user administration, fiscal-period changes, and destructive user operations are blocked.

The entry selector includes the original USD/EUR/GBP/PKR/INR choices plus **Malaysia — MYR, Bangladesh — BDT, Sri Lanka — LKR, Nepal — NPR and Singapore — SGD**. Country hints and manual choices use one shared server-validated currency list. A new visitor's selected base currency carries through sample transactions, POS receipts/journals and reports. The sample's numerical amounts remain illustrative, without exchange conversion or local price/tax/reporting rules. Starting again in the same valid session preserves the existing company's currency even if another supported currency is submitted. No migration or reset is needed to add these choices.

Current defaults allow **100 visitors per generation** and **250 source/operation records per company**, including the sample pack's own history. The default was 100 until 1.1.0; the eleven packs arrive with 85 to 105 records each, so at 100 most visitors could not record anything (measured 19 September 2026). At 250 every pack leaves at least 100 practice records, and `tests/demo_smoke.php` asserts that. Until 21 September 2026 the sample seeder carried **a second copy of this limit with the old default of 100**, so a pack of 85 sources was refused before it began by a number no longer in force anywhere else; it now asks `pl_demo_document_limit()` like everything else. The total includes cash/general drafts, AR/AP documents and actions, purchase orders/commands, inventory commands and open-item commands. A compound operation may consume several records; a failed capacity check rolls it back, while an exact retry returns the durable previous result. The helper clamps optional settings to 1–1,000 visitors and 10–500 documents, but the current Compose file does not forward `PL_DEMO_MAX_VISITORS` or `PL_DEMO_MAX_DOCUMENTS`; adding those names to the private environment file alone will not change runtime limits. Any future tuning must explicitly wire the settings, preserve guard tests, and follow load evidence. Capacity is a ceiling, not a claim of supported concurrent throughput.

A visitor's session stores the current generation. The next reset invalidates all previous visitors, even if numeric IDs are reused later. An expired generation rejects new work until the reset completes; it does not keep accepting writes into expired data. The UI must show that records are sample, temporary, and refreshed hourly. Never enter real financial/customer information or treat a printed sample receipt as a production transaction.

Every demo request and reset uses the same database advisory maintenance lock. Ordinary requests queue for at most two seconds before a recoverable busy/refresh response; reset waits up to 30 seconds. Approved browser origins retain CORS and retry headers even when bootstrap cannot acquire that lock. This deliberately serializes the bounded showcase; it is not evidence of a production concurrency design or a multi-node demo deployment.

## Hourly UTC reset

`tools/demo-scheduler.sh` runs the guarded reset on startup, then sleeps until the next UTC hour. `tools/demo-reset.php` skips a valid generation whose reset is not yet due. A successful reset records a new random generation and sets the next boundary to the following UTC hour. On failure, the scheduler logs a generic error and retries after 30 seconds. Container restart policy is `unless-stopped`.

The reset entry point requires CLI, `PL_ENV=demo`, `PL_DB_NAME=phpledger_demo`, and scheduler-only reset mode. Before replacing an existing nonempty database, it requires the demo marker, a valid generation, exclusively sample companies, and exclusively visitor users. It uses the **literal** database name in destructive SQL. It never substitutes an arbitrary environment-derived database name. Missing markers or unexpected records stop the reset for inspection.

`--now` forces an otherwise valid isolated demo generation to refresh before its due time, invalidating existing sessions. It does not bypass configuration, marker, scope, or maintenance-lock checks. Only the operator runs it through the maintenance service. Do not expose reset as a browser route, give the web service reset credentials, or run this tool against development/test/customer databases.

## Reverse proxy and country hint

The public HTTPS proxy must retain `/demo` when forwarding to the loopback listener; redirect `/demo` to `/demo/` consistently if the host needs it. Forward the verified host and scheme, and overwrite client forwarding headers rather than appending untrusted browser values. A single trusted host proxy can set `X-Forwarded-For` from its own observed client address. Configure `PL_DEMO_TRUSTED_PROXY_IPS` to the exact controlled hop seen by the container. If there are multiple proxy layers, validate their trust chain explicitly before enabling forwarded country hints.

The application accepts forwarded addresses only when `REMOTE_ADDR` exactly matches a configured trusted proxy, then chooses the nearest untrusted hop in a valid bounded chain. Private/reserved/local addresses skip the external lookup. A public address may be sent once per browser session to country.is for a country hint; success, failure, and local-network skips are cached. The response only suggests a supported base currency and never overrides confirmed business settings. See [regional provenance and behavior](../resources/locale/README.md). This is not currency conversion or tax/language coverage.

Application country-hint code does not retain the raw address/provider response in its session cache. Proxy/Apache access logs are a separate operational surface and may still record request addresses; configure access, retention, and public notice deliberately. The published Nginx location overwrites forwarding headers and the trusted Docker gateway was verified as `10.204.82.1`. A Pakistan suggestion was observed during live entry; provider accuracy across countries is not established by that observation.

## Deployment and rollback pattern

The lead owns the authorized release. These steps describe the current Compose contract; run only against the prepared isolated deployment after checking the concrete paths and private environment file.

1. Preserve the current website/proxy configuration and its recoverable release pointer. Keep the previous release and any needed isolated-demo recovery evidence outside the public root. Confirm the new files, license-status wording, image provenance, checks, and private-file exclusions before upload.
2. Put the verified release in its versioned directory and the secrets in the separate restricted environment file. Inspect the unresolved Compose source and variable names without printing resolved secrets. Verify the loopback port and network range are available.
3. Build and start the database, wait for its healthy state, then initialize the guarded demo generation through the maintenance service. Start the web and hourly scheduler only with the intended release tag and private environment file. Example commands from the release directory:

   ```sh
   docker compose --env-file /var/www/phpledger/data/private/demo.env -f compose.demo.yaml build demo-web demo-reset
   docker compose --env-file /var/www/phpledger/data/private/demo.env -f compose.demo.yaml up -d demo-db
   docker compose --env-file /var/www/phpledger/data/private/demo.env -f compose.demo.yaml --profile maintenance run --rm demo-reset --now
   docker compose --env-file /var/www/phpledger/data/private/demo.env -f compose.demo.yaml up -d demo-web demo-scheduler
   ```

4. Check the loopback `/demo/health`, sample entry, restrictive grants, sample generation, and scheduler state. Switch the static website document root and HTTPS `/demo` proxy only after the local deployment checks succeed. Do not expose the raw loopback port publicly or proxy the release root.
5. Verify public HTTPS, secure cookies, `/demo` links/forms/assets, two independent visitor sessions, POS posting/receipt/report links, rejected destructive operations, and recoverable busy/expired states. Verify an actual scheduled UTC-hour generation replacement and that prior sessions expire. Record the release identifier, checked URLs, commands/results, remaining gates, and rollback location without credentials.

For rollback, restore the previous website/proxy configuration first if the new public surface fails. Stop the new demo web/scheduler if isolation or reset safety is uncertain; leave the database volume intact for inspection. Restart a known compatible prior release using its matching source mounts/image/environment. Check schema compatibility before pairing old code with the new database; when incompatible, keep the demo unavailable until its matching isolated state is restored or safely rebuilt through the guarded reset. Never use `down -v`, a blanket database drop, or altered migration receipts as a shortcut. A website-only rollback can leave the demo temporarily unavailable rather than expose an unverified combination.

## Evidence and remaining release gates

Local evidence includes the expanded 56-test backend suite, original-foundation-to-005 upgrade proof, 15-table/9-trigger restoration check, restricted-user demo/reset smoke, and the latest 21-check POS HTTP journey with explicit-confirmation rejection. The loopback-only `python tests/demo-http-smoke.py --currencies` check passed 68 checks: the existing 18 demo checks plus ten per added currency covering selection, sample startup, POS/receipt, repeat checkout, reports, unchanged currency on re-entry and logout. It creates six independent sample visitors, makes no database reset, and leaves them until the ordinary hourly refresh. Omit `--currencies` for the original 18-check journey. Exact snapshot counts and follow-up fixes are in [Validation](VALIDATION.md).

The hosted counterpart passed **68 HTTP checks**, including secure scoped cookies and the five new currency journeys. Another **71 routing/asset checks** verified the initial published bytes, canonical HTTPS, the retained ACME exception and private-path rejection. All **39 deployed application PHP files** passed lint, platform requirements passed, and the locked production dependency audit reported no advisories. Composer metadata remains valid with warnings for the unset project license and the intentionally exact dependency pin; strict metadata validation therefore does not pass yet.

At the actual 18:00 UTC boundary the scheduler rebuilt only `phpledger_demo`. Empty sample tables and a temporary maintenance response were observed during rebuilding; by 18:00:17 the prior browser session had returned to entry and started a clean GBP sample. The generation changed, the next reset became 19:00 UTC, the scheduler did not restart, and the existing host MySQL process and demo database container were unchanged. The three session/reset assertions passed. The temporary maintenance interval and a separate busy response during concurrent QA are expected behavior of the current serialized demo, not throughput validation.

Public browser evidence is recorded in the [independent launch check](design/website/qa/live-20260914/README.md). These checks do not establish production throughput, monitored disaster recovery, regulatory/accounting approval, receipt-printer compatibility, accessibility conformance or observed usability targets. The project license/provenance decision still gates the first downloadable package. See the [hosted publication receipt](VALIDATION.md#hosted-website-and-restricted-demo-publication) for exact deployment scope and follow-up static changes.


## Demo rebuild and a demo build tool (owner direction, 20 September 2026)

Status: recorded direction, deliberately deferred. **Nothing in the demo was changed by this task** — the live demo, its hourly UTC reset, restricted database grants, containers and sample packs are untouched.

The owner is not satisfied with the current demo and wants a tool that builds demos, rather than today's path of hand-assembled sample packs plus `tools/build-demo-packs.py` and `tools/demo-reset.php`. The tool's scope is not decided: what "build a demo" should produce beyond the present packs, how it relates to the teaching histories described below, and how it relates to the package directory that takes sample companies out of the release ZIP are all open. The owner named this a battle for another time, so it waits for its own session. Recorded as decision B26 in the [decision register](strategy/DECISION-REGISTER.md).

## Planned multi-year teaching histories (owner direction, 15 September 2026)

Status: research and implementation plan; not loaded into the public demo. The owner explicitly wants variety of transaction types across multiple years, not high transaction volume. Plan four choices at demo entry: service agency, retail shop, seasonal business and distributor. Retain one private company per visitor initially and the existing scoped services and reset model.

Target approximately 50-80 counted documents/general-journal drafts per selected company across two years, including a small open-period practice tail and 2-4 editable drafts. Leave at least 20 visitor actions under the current default 100-document cap; counts are acceptance budgets, not a reason to increase the cap. Use versioned fixed business dates and report links that open populated periods. A reset restores the same reviewed fixture version rather than moving historical dates each hour.

### Accounting variety and company stories

| Company | Distinct situations and report questions |
|---|---|
| Service agency | Service income, customer advances and earned release, employee salary expense/payable/payment, contractor accrual, prepaid insurance/software, equipment/depreciation, owner funding/drawings. Why can cash rise before income is earned, or fall while profit grows? |
| Retail shop | Cash sales, bank deposit, petty-cash spending/replenishment, stock purchases and explicitly manual supported COGS/count adjustments, supplier credits, salary changes, equipment and a linked correction. Why are bank deposits not revenue, and why can higher sales produce lower profit? |
| Seasonal business | Quiet/busy months, booking advances, earned releases, refund/cancellation examples, pre-season maintenance, seasonal wages, prepaid costs and equipment. How does surplus from the busy period finance the quiet period? |
| Distributor | Reconciled opening controls, manually recorded credit sales/purchases and settlements, late/partial collection across a year boundary, customer/supplier credits, delivery expenses, loan principal/interest and supported manual stock/COGS adjustments. Why can a profitable business be short of cash? |

Each company has separate operating bank, second bank, cash-on-hand and petty-cash accounts, appropriate asset and accumulated-depreciation accounts, and a small fictional staff/salary schedule. Include bank-to-bank transfers, cash deposits, petty-cash replenishment, salary accrual and settlement, an asset purchase and later adjustment, a reversal, and an outstanding item that crosses a period boundary. Names, amounts and schedules are authored sample examples; no personal banking/payroll details or real customer identifiers are imported.

### Closing examples

| Frequency | Example and existing-capability boundary |
|---|---|
| Daily | Cash count, petty-cash check, bank deposit and documented difference/adjustment, followed by that day's journal/account balances. This is a teaching close checklist; a POS shift-lock workflow is not implemented. |
| Monthly | Salary accrual/payment, depreciation, prepaid release, accrued expenses, exact bank reconciliation, trial balance, P&L and balance sheet, then the supported reasoned period close. Include a traceable correction/reopen example where the service permits it. |
| Quarterly | Review three monthly periods, reconcile asset/payroll/control schedules, compare supported date-filtered results and document adjustments before closing the last open month. Do not create overlapping quarterly periods. |
| Yearly | December adjustments, asset/liability review, annual report checks, outstanding balances carried into January and a next-year reversal/settlement. Monthly period closure is distinct from formal year-end earnings closing or issued reviewed statements; those rules need their own accounting acceptance. |

Periods remain nonoverlapping. Daily, quarterly and yearly teaching reviews must not create ranges that overlap the monthly accounting periods. Salary journals and supporting staff schedules do not implement payroll calculations or statutory withholding. Distributor/retail control schedules and manual journals do not implement customer/vendor subledgers, allocations, inventory valuation or stock screens. Full module workflows become acceptance fixtures when their modules exist.

### Delivery and acceptance

1. Author original compact, versioned packs using the existing sample/resource conventions. Include source references, precise transaction types, expected effects and three guided report questions per company. Monthly summaries must say they are summaries; exclude individually illustrated transactions from them to prevent double recognition.
2. Load only into new isolated samples through the current setup, account, posting/reversal, opening, period and bank services. Pin pack/version/digest/dates and deterministic source identities. No direct posted-journal insertion and no new auth/database layer.
3. Independently reconcile each month end: opening+movement=closing, debit=credit, assets=liabilities+equity/earned profit, report totals=ledger details, and sample support schedules=declared controls. Cover filtered openings, same-day ordering, reversals and the 50-row page boundary with at least one meaningful ledger.
4. Measure compact setup/read/reset behavior before proposing capacity or provisioning architecture changes. Preserve visitor isolation, the normal hourly reset and room for user experiments.
5. Use the packs as reference fixtures alongside API/MCP reads, then controlled commands, then optional AR/AP. Dataset planning does not reprioritize AR/AP or mark reviewed reporting complete. No migration is currently proposed solely for the fixture content; any needed permission/workflow change requires its own narrow design.

Research references: [UCI Online Retail II](https://archive.ics.uci.edu/dataset/502/online+retail+ii) provides two years of sales/cancellations, not complete books (CC BY 4.0; no workbook import planned). [Microsoft Wide World Importers workflows](https://learn.microsoft.com/en-us/sql/samples/wide-world-importers-what-is?view=sql-server-ver17) inform distributor event coverage. [OpenStax journal/ledger examples](https://openstax.org/books/principles-financial-accounting/pages/3-5-use-journal-entries-to-record-transactions-and-post-to-t-accounts) inform concept coverage; author original content rather than copying restricted teaching material. [ONS short-let seasonality](https://www.ons.gov.uk/peoplepopulationandcommunity/housing/bulletins/shorttermletsthroughonlinecollaborativeeconomyplatformsuk/july2024tojune2025) supports a seasonal business pattern, not invented revenue/cost facts. No Google Drive references were required.

## Optional future demo subdomain

The owner asked whether `demo.phpledger.com` is possible, explicitly as a question rather than authorization to move it. It is feasible and can be deferred. The current demo URL, DNS and hosting configuration remain unchanged by this assessment.

Benefits: a clear shareable demo address; a separate browser origin from the marketing site; independent demo caching/security/monitoring rules; and easier future relocation to another host. Existing containers, database isolation and scheduler can remain on the same server. A subdomain does not itself create database or infrastructure isolation, and it remains under the same parent site.

Costs: DNS and TLS lifecycle, a new virtual host/proxy configuration, session/cookie and base-path changes, old-link redirects and another deployment surface to verify. Current `compose.demo.yaml`, `docker/demo-apache.conf` and demo session-cookie handling explicitly use `/demo/`; this is not a DNS-only switch. Browser sessions should start fresh on the new hostname rather than broadening cookies to the parent domain.

When separately authorized, stage DNS/TLS and the new virtual host, adapt root-path routing/cookies, preserve noindex and strict same-origin access, validate entry/posting/reports/assets/reset behavior, update public links, and redirect old GET entry URLs deliberately. Do not replay old POST requests across hosts. Keep a reviewed source/proxy rollback. Coordinate the switch with a normal demo refresh. There is no inherent SEO-ranking benefit for this noindex sample demo, and the move need not block API/MCP, the installer or richer examples.

References: [MDN cookie scope](https://developer.mozilla.org/en-US/docs/Web/HTTP/Guides/Cookies) and [Google URL-move guidance](https://developers.google.com/search/docs/crawling-indexing/site-move-with-url-changes). No DNS/provider changes or new credentials were requested or made.

## Operator deployment runbook for Claude and Codex

This is the operator entrypoint for the existing `https://phpledger.com/` website and `https://phpledger.com/demo/` deployment. Read [AGENTS.md](../AGENTS.md) and the [website guide](../www/website/README.md) before changes. An explicit owner request to deploy either surface authorizes its necessary reviewed preparation, backup, cutover and verification; do not request the same approval again. It does not authorize unrelated domains, customer databases, campaigns, messages, payments or Git publication. Run releases serially: website and demo helpers can pin the same Nginx configuration, so one deployment can invalidate another's prepared baseline.

Read [AGENTS_SYNC.MD](../AGENTS_SYNC.MD) and the latest [AGENT_MESSAGES.MD](../AGENT_MESSAGES.MD) entries before taking deployment ownership. Record the selected source/scope before shared work and append the result at handoff. Keep these coordination files in the repository, outside every web artifact.

### Start with status and the existing credential path

From the repository root, run:

```powershell
git status --short
git branch --show-current
git worktree list
git log -8 --all --oneline -- www/website
python tools/hosting-status.py
```

The status tool is read-only and reports selected release/runtime facts without credentials or customer records. Use it to inspect the current website root, configured demo release, mounted source/image identity and public health before deciding whether a deployment is needed. A GitHub release, a local publication JSON file or a successful build alone does not establish the current live version.

The configured Windows operator's SSH identity is already in **Windows Credential Manager**. The local `.cache/publish-website-1.0.0.py` `connect()` function reads that identity through `.cache/hosting.py` and requires the existing `.cache/hosting_known_hosts` pin with Paramiko `RejectPolicy`. The historical demo helper `.cache/release-0.1.6/demo_common.py` uses the same credential source and host-key policy. The identity is read in process memory; no password argument or interactive password entry is needed. Run the Windows Python environment under the same Windows account that holds the credential, with Paramiko available. WSL, another OS account, or another machine does not automatically inherit this credential store.

Do not call the older `.cache/hosting.py` `connect()` directly: it uses `AutoAddPolicy` and can rewrite the host-key file. Do not print the credential, full container environment, resolved Compose configuration, private environment file, database dump or session archive. Do not ask the owner to paste a password or reset working credentials because an ordinary `ssh` invocation prompts for one; use the configured helper. If the credential or pinned key is genuinely unavailable, report that precise missing prerequisite and let the operator restore it privately. Do not bypass host-key verification.

Distinguish connection failures from release guards. `Configured baseline changed`, a vhost hash mismatch, an existing release directory, or a `stage-complete`/`cutover-started` marker means that the inspected deployment state differs from the single-use plan. It is not an authentication failure. Refresh the read-only status and prepare a new reviewed plan; do not weaken assertions, delete markers or request a new password to get past it.

### Website: build, prepare, review and publish

> **Correction, 21 September 2026. The source named below is no longer what is live, and publishing from it would regress the public site.** Verify before trusting any of the following paragraphs.
>
> Checked against the running site on 21 September:
>
> | | Live site | `.cache/website-redesign-live` | main checkout `www/website` |
> |---|---|---|---|
> | Hero headline | "PHP Ledger: self-hosted double-entry accounting software" | "Complete double-entry accounting that runs on your own PHP hosting" | matches live |
> | Download page offers | 1.1.3 | **1.0.0** | 1.1.3 |
> | `src/releases.json` | served | **absent entirely** | present |
>
> The live site was published from the **main checkout**, not from the redesign checkout. The redesign checkout also carries around 86 uncommitted modified files, so its state exists nowhere in Git history and cannot be identified by a commit.
>
> Publishing from the redesign checkout as written below would roll the download page back to 1.0.0, change the homepage headline, and **remove `releases.json`**, which is the feed every installation reads to discover an update. That last one would silently break the in-app updater for every user.
>
> Before any website deployment, re-run the identification rather than trusting this file: fetch the live homepage and download page, compare the headline and the advertised version against each candidate checkout, and confirm which one carries a release feed. The rule that a version number alone does not identify a design still holds; it simply now cuts the other way.


Only the selected website checkout's `www/website/public` is the deployable static document root. First identify the requested design from relevant branches/worktrees, recent website history, its homepage and screenshots, and its route set. Version `1.0.0` alone does not identify the design: `record-demo-cutover` at `def47d1` contains the older site, while `master` at `86a1eef35fd497c7f5f708a47af227657fd4a2ee` includes rebuild `ac8fca3`. The rebuilt design has the headline **Complete double-entry accounting that runs on your own PHP hosting**, desktop/phone hero captures, and `/pricing/` and `/community/`. Its checked-out source is `.cache/website-redesign-live`; the earlier `.claude/worktrees/festive-elgamal-8a1d61` directory is empty and is not a source checkout.

For that reviewed product-site rebuild, run these commands from the main repository root. For a later design, first select its actual source and configure the reviewed publisher to use it; never infer the intended source from the current directory alone.

```powershell
node .cache/website-redesign-live/www/website/build.mjs --check
node .cache/website-redesign-live/www/website/tools/aeo-geo-audit.mjs
python .cache/publish-product-website-1.0.0.py --prepare
```

The build command regenerates public output and validates it. This source produces 70 HTML outputs and 295 static files; its fresh build matched every static byte in `.cache/website-1.0.0-deploy/website-1.0.0-20260918-201114/website-release.tar.gz`. Record the selected source commit, design headline, route set and full manifest in the publication evidence. Inspect the returned preparation directory's `review.json` and `nginx-review.diff`, its complete static-file manifest and archive hash. The established live configuration is `/etc/nginx/fastpanel2-sites/phpledger/phpledger.com.conf`; an older `/etc/nginx/fastpanel2-available/phpledger/phpledger.com.conf` copy can be stale. Confirm the effective configuration and path before publishing. This design adds exact Nginx locations for `/support` and `/support/`, both returning `301 /pricing/`, in addition to changing the static root. Preserve the `/demo/` proxy, OAuth locations, TLS/ACME handling, security headers and demo noindex policy.

After reviewing the actual prepared release identifier, run `python .cache/publish-product-website-1.0.0.py --publish <prepared-release-id>`, replacing the placeholder with that returned identifier. This wrapper reuses the established credential-safe publisher with the selected product-site source and its support redirects; the base `.cache/publish-website-1.0.0.py` alone points to the older main-checkout website. The helper stores private prior-site/configuration backups under `/var/www/phpledger/data/backups/<prepared-release-id>`, verifies uploaded/extracted file hashes, validates Nginx and atomically switches the website root. It verifies public file bytes, redirects, custom 404/private-path rejection, headers and unchanged demo identity, and attempts rollback if validation fails. For a reviewed manual website rollback, its command is `python .cache/publish-product-website-1.0.0.py --rollback <prepared-release-id>`; check that the recorded original configuration is still the correct recovery target and will not overwrite a later deployment.

The helper is a local operational dependency in ignored `.cache`, not a file supplied by a fresh clone. If it is absent, obtain the existing operator tooling or implement a reviewed equivalent from these contracts; do not substitute a whole-repository upload. Complete responsive browser checks and current release/download/media-kit link checks, then record the actual release, backup and public verification results in the existing publication records.

### Demo: prepare an exact release and preserve its matched state

The files under `.cache/release-1.0.0-demo/` document the **single-use 0.6.0-to-1.0.0 cutover**. `remote-preflight.py` requires the old `core-0.6.0-preview-b117b5cef7b4` release, while `prepare-demo.py` requires the verified 1.0.0 package and its source commit, exactly 32 unchanged migration files, and only the expected Dockerfile runtime-input change. It generates its remote helper from the historical 0.6.0 helper. `deploy.py stage` and `deploy.py cutover` use fresh-stage/single-cutover markers. These scripts cannot be replayed against an already deployed 1.0.0 demo or treated as a generic updater. Their old `fastpanel2-available` vhost path must also be reconciled with the effective `fastpanel2-sites` configuration in a new plan.

For a future authorized demo deployment, prepare a new release-specific plan with these requirements:

1. **Inspect the actual baseline.** Record the current release/configuration, web/scheduler/database container identities, mounted source paths, image digest, runtime-input hashes, migration receipts/checksums, restricted grants, guard/view state and time until the scheduled reset. Confirm only sample companies and demo visitors are present. Read private values only inside the controlled helper and return sanitized facts. If the requested release already matches, verify it rather than redeploying it.
2. **Verify the package and source.** Match the application archive to its checksum, package manifest, version/channel and recorded source commit. Assemble demo runtime files from that same commit; include the reviewed Compose/Docker/runtime/resource inputs that are outside the application ZIP. Build the release image when runtime inputs require it. Immutable source mounts and the image must belong to the same reviewed release; rebuilding an image alone does not update mounted PHP files. Identify the exact allowed migration set and expected schema/grants before staging. Do not invent unrelated migrations or alter existing checksums.
3. **Stage without changing the active demo.** Use a new release directory and private backup directory, reject path escapes/symlinks, verify the uploaded archive and every extracted file, and build or pin the tested image. Preserve the current private environment and active vhost. Keep the database container, database volume, restricted accounts, private keys, loopback `127.0.0.1:18202` binding and secure-cookie setting. Keep other host services unchanged.
4. **Cut over from a frozen, recoverable state.** Revalidate the baseline immediately before changes and allow enough time before the next reset; the 1.0.0 helper required more than 480 seconds. Stop only the demo web and scheduler writes. Take a private matched database dump, session archive, configuration and source/image reference; retain strict file permissions. Restore the exact frozen dump into a specifically named isolated verification schema, then run the reviewed migration chain and verify original record projections, receipts, balanced journals, guards and effective-source views. Grant maintenance rights only to that temporary schema and revoke them during scoped cleanup. Never apply the general local test verifier to a production connection.
5. **Switch and verify.** Apply only the reviewed migrations through the existing maintenance/migration interface, update the private release pointer and recreate web/scheduler with matching source mounts and image digest. Restore compatible sessions if the plan calls for it. Verify the restricted web account can read the effective views, public `/demo/health` and entry work, and the database container identity is preserved before resuming the scheduler. Preserve the vhost unless a specifically reviewed proxy change is required. Check visitor isolation, sample selection, representative posting/report journeys, blocked destructive actions, secure cookies/assets and an actual scheduled generation reset. Record any unobserved checks as pending.
6. **Recover with a matched set if needed.** Retain the frozen backup and restore compatible code/image, database, private configuration and sessions together through the reviewed recovery procedure. A failed schema change cannot be undone by simply restarting older PHP. Do not improvise a database drop, `docker compose down -v`, broad filesystem cleanup or migration-receipt rewrite. The historical helper's guarded sample-database restoration is part of its frozen-backup recovery transaction, not a general-purpose reset command. If recovery cannot be verified, keep the demo unavailable rather than expose an unverified combination.

Keep the release helpers' sensitive diagnostics in the private host backup directory. Publication receipts should contain hashes, versions, scoped counts, URLs, results and recovery locations only. Update the existing website/demo/release records after verification, distinguishing application migrations, temporary verification-schema operations, current live deployment and remaining acceptance gates. The historical `.cache` scripts should not be promoted wholesale into reusable tooling: first remove cross-version helper dependencies and hard-coded prior-state assumptions while retaining their strict baseline, source, backup and recovery checks.

## Optional data-only sample packages (1.3)

The ordinary core archive retains the neutral accounting starter and excludes the eleven optional multi-year practice histories. Their authoring JSON remains in this repository for review and deterministic builds. Production and the shared installer demo resolve optional samples only from validated installed packages; repository fallback is limited to local, test and the older provisioned demo environment.

An installation administrator opens `/packages`, explicitly refreshes the public directory, reviews a sample and chooses Install. Page loads read the local cache and do not contact the directory. ZIP upload follows the same existing package archive scanner and audit registry. The original installer owner can receive the existing installation-administrator grant from the private installed receipt before the first company exists. Other users cannot claim this bootstrap grant.

A sample package is CC0-1.0, contract 1, and contains exactly `package.json`, `pack.json` and `structure.json` below its `sample-<id>` directory. The manifest identifies its publisher, exact dependency versions, sample catalogue metadata and SHA-256 payload digests. It cannot contain PHP, migrations, hooks or an executable entry point. Uploads are labelled unverified; directory installs require the pinned publisher signature plus exact archive size/hash, manifest digest and payload inventory. Dependencies must already be installed and, for plugins, explicitly reviewed and active. Samples never install or activate executable dependencies automatically.

After installation, onboarding offers the existing reviewed zero-balance structure workflow and a separate full-history practice company. Fictional history always retains `is_sample`; it cannot populate an ordinary real business. Removing a package removes the source from future choices and preserves all companies and posted history already created from it. Invalid or changed packages fail closed and disappear from the chooser; the private application log records a sanitized package identity for operator diagnosis.

### Operator build, publication and offline preloads

Run `python tools/build-sample-packages.py --directory-source www/website/src/directory.json` after the final authored pack and structure digest checks. It produces eleven deterministic ZIPs, manifests and **unsigned** `sample-metadata.json` inventories under `.cache/sample-packages`. An unsigned inventory is signing input, not verified publication evidence. The website build generates `/directory/`, its index JSON, per-package pages, package manifests and unsigned inventories; the release operator separately signs the exact inventory bytes using the pinned publisher RSA key and publishes the resulting base64 `payload`/`signature` envelope as `/directory/<slug>/<version>/sample-envelope.json`. Archive destinations are the fixed `phpledger/sample-<id>` GitHub release repositories. Publishing, signing and independent repositories remain release-operator actions.

For a host that deliberately disables outbound access, extract verified packages into a private read-only directory with layout `<root>/sample-<id>/{package.json,pack.json,structure.json,sample-envelope.json}`. Set `PL_SAMPLE_PACKAGE_DIRECTORY` to that absolute private root, `PL_SAMPLE_PACKAGES_READONLY=1`, and `PL_UPDATE_PUBLIC_KEY` to the independently pinned public-key file. Every preload is verified on discovery, without relying on a writable package registry. The shared `PL_ENV=demo-install` host refuses executable package mutations at the service boundary; normal self-hosted administrators retain the existing plugin workflow. Keep private roots outside the public document root and mount them read-only. Do not copy a public key from an untrusted package.

Validation is separate from publication: service and archive tests cannot prove the public endpoints, hosted signing custody, exact release download, browser responsiveness or real operator usability. Those gates belong to the final release receipt.
