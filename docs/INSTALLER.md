# Browser installer

Status: published in **1.0.0** (18 September 2026) as part of the consolidated 0.6.1/0.7/0.8 stable release; general shared-host qualification and unfamiliar-operator acceptance remain open post-release commitments. The [stable release checklist](ROADMAP.md#current-delivery-contract-first-stable-10) now controls sequencing. The original WordPress-style request remains: hosting-panel preparation, then browser setup without Composer, Node or shell. Existing CLI installation remains available. The installer-created customer website remains parked. Independent review and unfamiliar-operator/host acceptance remain separate gates.

## WordPress-style installation (1.1.0, published 19 September 2026)

The owner asked for the WordPress experience after installing 1.0.0 on XAMPP proved difficult. The branch `wordpress-style-install` implements the flow below. It changes the 1.0.0 contract described in the next section wherever the two differ.

1. **Unzip anywhere.** The release ZIP unpacks to one `phpledger/` folder; the archive name keeps its version. It can sit at a domain root, in a subfolder such as `public_html/accounts`, or in XAMPP's `htdocs`.
   - The internal layout is unchanged (`vendor/`, `resources/`, `tools/`, `www/phpledger/`).
   - A package-root `index.php` and `.htaccess` (sources in `resources/release/root/`) route pages to the front controller and serve `www/phpledger/public/` files.
   - They return 403 for every other folder and for the root documents. Each private folder also carries a deny-all `.htaccess`, and the installer adds one to its private storage.
   - Pointing a (sub)domain's document root at `www/phpledger/public` remains the most secure setup. The package's `public/.htaccess` makes that work without editing a virtual host.
2. **Refusal on unsafe servers.** The package-root `index.php` refuses to start unless the `.htaccess` marker (`PL_HTACCESS`) shows that the rules are active; Nginx, for example, ignores them.
   - Before setup writes any secret, it fetches harmless probe files in private storage and `vendor/composer/installed.json` through the site's own address. The checks are bounded GETs with no redirects.
   - Confirmed exposure stops setup. An inconclusive result shows a manual check link.
3. **Subfolders.** The base path comes from `PL_BASE_PATH` or from the package-root entry point, and dotted folder names are accepted.
   - A copy in a subfolder gets its own session cookie name and path. Sessions are also bound to the copy that created them.
   - The installer's public URL may include that folder. API/MCP OAuth discovery still needs a (sub)domain root.
4. **No setup key (owner decision, 19 September 2026).** An unconfigured copy sends visitors to `/install`, which opens at the requirements and database step.
   - **Database on another server:** any host other than literally `localhost`, `127.0.0.1` or `::1` requires a one-time code. Setup writes it to private `setup-code.txt`, the owner reads it with the hosting file manager, and it is deleted when setup completes.
   - **Strict mode:** an operator-provided `PL_SETUP_KEY` or `setup.key` keeps the original unlock step.
   - **Residual risk:** whoever reaches a fresh upload first with a database on the same server can claim it. On shared hosting that includes another account on the same server. README.txt tells owners to run setup right after uploading.
   - **Existing protections are unchanged:**
     - CSRF protection
     - bounded attempts
     - locks
     - binding to the first database
     - refusal to claim non-empty databases, users or existing configuration
     - no reopening after completion
5. **Plain HTTP warns instead of refusing** (owner decision, 20 September 2026, [issue #84](https://github.com/phpledger/phpledger/issues/84)). A loopback request keeps the quiet local-test note: the peer is 127.0.0.1 or ::1, the host is a loopback name or `*.localhost`, and no forwarded headers are present. Any other plain-HTTP request now shows a warning on every setup step and continues, and the installed copy repeats it on every application screen.
   - The public URL may stay `http://` only when it is exactly the address the owner is browsing. Any other `http://` address is still refused.
   - Such a copy cannot carry a `Secure` session cookie, so that flag follows the configured public URL rather than the request, which keeps it set behind a TLS-terminating proxy.
   - Connections (API, MCP and app integrations) already require an HTTPS public URL and report themselves unavailable; the warning names them. `/maintenance.php` stays HTTPS-only.
6. **MariaDB.** MySQL 8.4 LTS and MariaDB 10.4 or newer are accepted. The shared MeekroDB `pre_run` hook in `database_platform_functions.php` translates three MySQL-only spellings on MariaDB:
   - `FOR SHARE` becomes `LOCK IN SHARE MODE`.
   - `SKIP LOCKED` is dropped before MariaDB 10.6.
   - `utf8mb4_0900_ai_ci` becomes `utf8mb4_uca1400_nopad_ai_ci` or `utf8mb4_unicode_520_nopad_ci` where the server lacks it. Both are NO PAD, like MySQL's collation.

   Migration files and checksums never change. On MariaDB, `pl_migrate()` also sets the database default collation, so trigger variables compare cleanly.
7. **Owner account.** The owner chooses a username, an email address and a password, typed twice. They can sign in with either name.
   - Usernames are 3–60 lowercase letters, digits, dots, dashes or underscores (migration `032_user_names`).
   - Both names count against one attempt limit.
   - `create-admin.php --username=` offers the same choice on the command line.
8. **Optional logo.** A PNG, JPEG or WebP image of at most 1 MB and at most six times wider than tall. Its type is checked from the bytes, and SVG is refused.
   - It is stored in the database (migration `033_installation_logo`) and served by `/logo` without a session.
   - It is shown in the menu and on the sign-in page instead of the PHP Ledger logo.
9. **XAMPP fixes.** When the host's OpenSSL configuration file is missing, key generation retries with the bundled `install/openssl.cnf`. Private files fall back to exclusive creation where `link()` is disabled.
10. **Six stages, laid out as the Workbench** (owner decision, 20 September 2026, direction B in `docs/design/installer-2026-09`). Start, Database, Build, Checks, Account and Ready, shown as a compartment tray, each stage sized for a laptop screen without scrolling.
   - A requirement that fails takes over the screen as something to clear, with the places it is actually fixed, and blocks setup until it is cleared. Those failures previously surfaced later as errors.
   - The completion page replaces the redirect to `/onboarding` and itemises what was built, read back from the database.
   - The page's Content-Security-Policy forbids inline style attributes, so every layout value is a class. The progress width is served in a `<style>` element carrying a per-request nonce added to `style-src`; do not reintroduce `style=` in this view.
11. **Server checks, a progress bar and few requests for the schema** (owner feedback, 20 September 2026). Setup used to apply one schema step per browser request and show a submit button while it worked.
   - `pl_migrate()` takes a time budget as well as a batch limit. The browser passes `pl_install_migration_budget(ini_get('max_execution_time'))`, which is 60% of PHP's own limit clamped to 3–15 seconds. The whole 35-step chain is about two seconds of work, so a normal host finishes it in **one request**; a host with a short limit hands control back between steps and the next request resumes from the receipts. Measured on MySQL 8.4: 2.13 s for the chain, slowest single step `013_currency_foundation` at 0.24 s.
   - The migrating screen is a progress bar with the step's plain-language name from `pl_install_step_label()` ("Building the chart of accounts and journals · Step 6 of 35 · 14%"). The submit button is hidden by `install.js` while it runs and stays for `<noscript>`.
   - `install_check_functions.php` supplies the green/red lights: `pl_install_requirement_checks()` before any credentials are typed (PHP version, eight extensions, dependencies, session storage, private storage and configuration, private-folder exposure, HTTPS), and `pl_install_self_checks()` after the schema is written (structure and checksums, server version, **the count of immutability triggers**, report views, UTC storage, exact money arithmetic). A red light never blocks the page; it names the fix.
   - `pl_install_environment()` reports what setup deduced: the application folder, the folder served to visitors, the address folder, private storage, private configuration, session files, and any database server answering on a loopback port. `pl_install_local_database_ports()` probes 3306, 3307, 3308, 3310, 3320 and 8889 on 127.0.0.1 with a 0.2 s bound and sends no credentials; the first hit becomes the port field's default.
12. **A database on this same server** (owner decision, 20 September 2026, [issue #84](https://github.com/phpledger/phpledger/issues/84)). `localhost`, `127.0.0.1` and `::1` mean the owner's own machine or hosting account, the same test that already decides the one-time remote code.
   - A database account with no password is accepted there, and so is MySQL's `root`. The review step names the account, says that local stacks are set up that way, and says what to do instead on a server other people can reach.
   - When the named database does not exist, setup creates it with the same account, `utf8mb4` and the collation the migrations expect. An account that may not create it gets a message naming the database to create in phpMyAdmin or the hosting panel. A database that already exists is never altered by this step.
   - A database on another server keeps both refusals and is never created, because its credentials cross the network and it is no proof of controlling this website. The application bootstrap applies the same rule to the configured password.

The package keeps runtime files, legal notices and recovery tools only. Guides moved online, and the ZIP carries a one-page `README.txt`. See `tools/package-files.json`, `tools/build-package.py` and [Validation](VALIDATION.md) for the tests and what remains unverified.

## Local implementation contract (1.0.0)

`GET/POST /install` runs before the configured application bootstrap. It requires HTTPS except explicit local/test loopback use, and is disabled for the hosted demo. The host provisions a random setup key of at least 32 characters in private `www/phpledger/storage/installation/setup.key`, or `PL_SETUP_KEY`. `PL_INSTALL_DIRECTORY` may point to a private directory outside the application; `PL_INSTALL_CONFIG_PATH` optionally selects a private configuration path. Neither may resolve into the public document root. Setup credentials never belong in a URL.

The browser verifies the key, checks the dedicated empty database, runs the original migration chain one migration per request, provisions private OAuth keys, creates the first account and hands off to existing business onboarding. Automatic progress has a manual no-JavaScript continuation. State and database identity protect retry behavior. Unknown/nonempty databases and incomplete/altered migration receipts stop for review. Writable private configuration is published atomically; otherwise an authenticated no-store download allows hosting-panel placement. Installation completion disables setup; deleting a flag does not authorize takeover of existing users.

A separate private `operator.key` authorizes installation-wide maintenance. It is not a company-owner permission and must be kept by the hosting operator. Application updates use the independent `/maintenance.php` entry point and their own signed-package, backup and recovery gates. PHP ZIP is an additional prerequisite for that update capability. Local automated evidence belongs in [Validation](VALIDATION.md); no live provider/hosting mutation is implied.

## Intended experience

An operator downloads the complete release ZIP, unpacks it and points the HTTPS hostname at `www/phpledger/public`. On a validated host, the normal fresh-install journey should then work in the browser without Composer or terminal commands. The wizard cannot install PHP/extensions, create DNS/certificates or grant database privileges; it explains missing prerequisites with hosting-panel guidance.

| Step | User experience and result |
|---|---|
| 1. Welcome and host checks | Explain the supported package profile, confirm explicit installation ownership/enablement, and check PHP/extensions, HTTPS, private sessions, routing, configuration access and database requirements. Show actionable failures. |
| 2. Database connection | Enter a dedicated empty database's host, port, name and credentials. Test connectivity, MySQL version/collation and required privileges. Reject an existing/unrecognized database. Explain how to create the database/user in a hosting panel. |
| 3. Install | Show the target and package version for review, then run the existing versioned migrations through a guarded installer service. Display progress and safe error messages. Preserve complete filenames, checksums, locks and migration receipts. |
| 4. Owner account | Create the initial sign-in account through the existing auth service. Use its current password rules; this is not a new global administrator role. |
| 5. First business | Continue into existing onboarding: new business, reviewed existing-business opening/cutover, or a separate sample. Reuse currency, date, account-template and membership rules. Optional modules retain their existing defaults. |
| 6. Finish | Verify schema/current identity, write the private installation completion state, disable installation access and open the application. Give backup and next-step guidance. Do not post a transaction into a real company as a test. |

## Architecture and boundaries

- Extend the shared bootstrap/configuration lifecycle to show a safe unconfigured state before attempting a normal database connection. Reuse the existing MeekroDB configuration and services; do not introduce another framework, router, auth stack or database abstraction.
- Refactor the existing CLI-only migration implementation into a shared internal service with separately guarded CLI and one-time installer entry points. Do not simply remove its CLI guard or expose migration scripts directly under the document root.
- Keep configuration in the current `includes/config.local.php` location outside `public/`, or retain operator-provided environment settings. Write configuration atomically with restrictive permissions when explicitly writable; otherwise provide a protected one-time download/copy fallback for the operator. Never make the entire application writable.
- Require explicit one-time installer enablement and proof of control through the hosting environment, such as a temporary private setup key. A publicly reachable empty installation must not let an arbitrary visitor claim the first account. *Amended by the owner on 19 September 2026 for the next release: setup opens without a key, as WordPress does. Proof is still required for a database on another server, and an operator-provided key keeps strict mode. The residual same-server risk is described above.* Use CSRF, secure sessions, no-store responses, bounded attempts and one installation lock. Detailed credentials/errors stay out of URLs, logs and diagnostics.
- The current candidate retains a dedicated identity scoped to the application database, with installation/recovery DDL privileges. Separate temporary installation and narrower runtime identities remain a qualification gap: the updater currently rejects insufficient grants or a different view/trigger definer before mutation. Do not describe this candidate as supporting automatic recovery with every existing restricted runtime account. A portable split-credential contract and representative hosting-panel evidence remain required before that claim. *Amended by the owner on 20 September 2026 (decision B24): browser setup no longer offers the optional second runtime account, because it could not deliver the separation on the hosting panels the installer targets and quietly disabled the updater's recovery path. The one account entered during setup becomes the configured runtime identity. Restricting it afterwards remains an operator activity described in the release [INSTALL.md](../resources/release/INSTALL.md); until the contract above is qualified, that is guidance, not a supported configuration, and it must not be offered as a choice on a first install.*
- Refuse overwriting private configuration, existing users or unknown database objects. Repeated requests must not create duplicate accounts or migrations. Reuse matching applied receipts; an interrupted `applying` migration stops for operator review because MySQL DDL is not one rollbackable transaction.
- Fresh installation only in version 1. Existing installations show their status and the current upgrade guidance. Automatic updates, schema rollback, hosting-account provisioning, email delivery, payments, tax activation and historical import execution are separate capabilities.
- Disable browser installation entirely in the hosted demo and after successful installation. CLI recovery remains an operator action; deleting one lock file must not allow takeover of an existing installation.

## Supported profile and acceptance

Start with the current package profile: PHP 8.2+ (8.3 recommended); BCMath, PDO/PDO MySQL, mbstring, JSON and sessions; MySQL 8.4/InnoDB with the required collation; HTTPS and the exact public document root. The [hosting/runtime record](strategy/HOSTING-PHP-COMPATIBILITY.md) records the compatible lock and tested environments. MariaDB and arbitrary shared-hosting configurations are not implicitly supported.

Verify fresh installation on Apache/PHP-FPM and Nginx/PHP-FPM hosting profiles where available, including a representative hosting-panel setup. Test missing extensions, wrong credentials, insufficient grants, wrong/nonempty database, unwritable configuration, interrupted migration, concurrent installation, CSRF, ownership failure, duplicate submission and access after completion. Confirm no private files can be downloaded. Compare CLI and browser-installed schemas/receipts and run existing scoped posting, report, permission and recovery checks against sample data.

Check the six-step experience on desktop, tablet and mobile. Have a person unfamiliar with the repository complete installation from the release ZIP and document where assistance was required. Technical test success does not establish easy installation on all hosts.

## Delivery sequence and changes

1. Close 0.6.1 workflow gaps while preparing browser setup and review evidence; read access and AR/AP are already in the published baseline.
2. Accept 0.7 browser setup against the complete ZIP with unfamiliar operators and explicit host requirements.
3. Accept 0.8 automatic backup/update/recovery; test failure/recovery boundaries and validate supported hosts. Distribution-channel publication follows stable in 1.1.
4. Under release authorization, publish an evaluation package with updated INSTALL/UPGRADE instructions, README, Wiki, website and installation evidence.
5. Continue regional tax/e-invoicing connectors (Pakistan FBR is one planned connector), inventory, shop POS and e-commerce/storefront; controlled API/MCP commands follow e-commerce.

Expected implementation areas: shared bootstrap/config handling, existing install/preflight/migrate/create-admin services, a guarded installer controller/view, configuration persistence and release docs. No new accounting schema or migration content is proposed for the wizard itself; installation executes the existing migration chain. An additional installation-state schema is not assumed.

References: [current package installation](../resources/release/INSTALL.md), [upgrade contract](../resources/release/UPGRADE.md), [architecture](ARCHITECTURE.md), [module roadmap](MODULE-ROADMAP.md), and [WordPress's installation flow](https://developer.wordpress.org/advanced-administration/before-install/howto-install/). The WordPress experience informs usability, not PHP Ledger's financial or permission rules. No Google Drive reference was required. This plan makes no production, DNS, schema or provider change.
