# What it would take to open each channel — 21 September 2026

A follow-up to the [channel proposal review](CHANNEL-PROPOSAL-2026-09-21.md), answering the owner's
question of the same day: what would unblock Homebrew and npm, what to do about promotional pages on
GitHub Pages or Cloudflare, and how to approach Softaculous and the other one-click builders.

**The headline finding is that several gates in the [distribution plan](DISTRIBUTION-PLAN.md) were
written before the container image and the command-line install scripts existed, and are looser now
than the plan assumes.** Milestone M12 shipped a complete non-interactive install path in 1.2.1. That
path is the prerequisite most of these channels share.

Nothing here is a decision. Decisions belong in the [decision register](DECISION-REGISTER.md).

**Tracked as:** [#99](https://github.com/phpledger/phpledger/issues/99) (Homebrew),
[#100](https://github.com/phpledger/phpledger/issues/100) (the missing `upgrade.php`) and
[#101](https://github.com/phpledger/phpledger/issues/101) (the one-click channels).

## The non-interactive install already exists

This matters more than any individual channel. A scripted deployment can install PHP Ledger end to
end today, with no browser:

| Step | Mechanism |
|---|---|
| Database settings | `PL_DB_HOST`, `PL_DB_PORT`, `PL_DB_NAME`, `PL_DB_USER`, `PL_DB_PASSWORD` read in `includes/bootstrap.php`, or a `config.local.php` array file that overrides them |
| Schema | `php install/migrate.php` |
| Administrator | `php install/create-admin.php --email= --name= [--username=]`, password in `PL_ADMIN_PASSWORD` or on stdin, never in an argument |
| Completion receipt | `php install/complete.php` |

`install/complete.php` states in its own header that it is "equally usable by any other scripted ZIP or
Composer deployment", not only by the container. The distribution plan's Tier 1 requirement for
Softaculous, a release zip with config, database and administrator all from parameters, is therefore
**already met on the application side**.

A local database account with no password is accepted when the host is `localhost`, `127.0.0.1` or
`::1` (issue #84, `pl_database_local_host()`). That is what makes a single-machine install possible
without asking the user for credentials.

## Homebrew

The distribution plan gates the tap on SQLite support. **That gate is avoidable, and avoiding it turns
a major port into a small packaging job.**

### What SQLite would actually cost

Measured on `master` at 1.2.1:

| Work | Scale |
|---|---|
| Migrations containing `CREATE TRIGGER` | 33 of 43 |
| Function files calling `JSON_*` | 46 |
| Function files using `FOR UPDATE` row locks | 34 |

SQLite has no `FOR UPDATE`, spells triggers differently and needs `RAISE(ABORT)` for the immutability
guards. This is the engine-portability work in the [platform roadmap](PLATFORM-ROADMAP.md), sequenced
after PostgreSQL and tied to the Windows bundle. It is a release of its own, not a packaging task.

### The alternative: depend on MariaDB

A third-party tap has no review and no acceptance criteria, so nothing outside the project forces the
SQLite route. A formula may declare `depends_on "mariadb"` and let `brew services` run it. The
notability rules that would block this, roughly thirty stars and ninety for a self-submission, apply
to `homebrew-core`, which is not the plan.

On that route the work is:

1. **A `phpledger` launcher**, the only genuinely missing piece. It would start PHP's built-in server
   against `www/phpledger/public` with a small router script and offer `start`, `stop`, `status` and
   `backup`. **The pattern already exists and is exercised by tests**: `tests/browser_installer_test.php`
   writes a `router.php` and runs `php -S` against that same document root. The router replicates the
   `FallbackResource /index.php` rule the Apache configuration uses.
2. **First-run setup**, which is the four commands in the table above against the local MariaDB.
3. **A tap repository** named `phpledger/homebrew-tap`, and the formula pinned to the release tarball
   and its SHA-256.

The proposal's own formula would not work: it symlinks `bin/phpledger`, and there is no `bin/`
directory or command-line entry point in the application.

**The trade-off is worth putting to the owner.** Requiring MariaDB makes `brew install` heavier than a
typical formula and makes the launcher manage a service it does not own. SQLite would make it a true
single-file local install. The first is available in days; the second is a release.

## npm

The [release protocol](../RELEASE-PROTOCOL.md) is explicit that **npm carries no application package**,
and that position should hold. `npx phpledger init` would advertise npm as an install channel for a PHP
application it cannot install. The planned packages are a scaffolder and an API client.

`create-phpledger` is the one channel in the proposal whose own recorded prerequisite is already met.
Its gate is "release feed live", and the feed has answered with 1.2.1 since the 1.2.1 publication.

**Built on 21 September 2026** in `clients/js/create-phpledger`, with `tests/create-phpledger-test.py`
covering it. It is not published to npm; that needs the owner's account and a publish step on tag.

What it took:

1. A package under a new `clients/js/` directory, so the private root `package.json` stays the Tailwind
   build manifest it is.
2. Behaviour: read the current stable version from the release feed, ask for a port, a public URL and a
   database password, then write a `compose.yaml` derived from `compose.production.yaml` with
   `PL_VERSION` pinned to that release, plus a `.env`. Print the `docker compose up -d` line and the
   address to open. It installs nothing itself.
3. An npm account or the free public `@phpledger` organisation, and a publish step on tag with
   provenance, mirroring the existing container workflow.

This is small, and it is the cheapest credible presence the project can have in an ecosystem the
buyers' developers already use.

## Promotional pages on GitHub Pages or Cloudflare Pages

**A second promotional site would compete with the one that already exists.** The website source holds
72 pages, and they are not a thin brochure: they include the comparison pages the distribution plan
calls the search-intent strategy (Akaunting, Dolibarr, ERPNext, FrontAccounting, BigCapital and a
general open-source-accounting comparison), audience pages for small businesses, accountants and
software houses, task guides and a glossary. The SEO workstream is described as high priority.

Publishing the same message on `phpledger.github.io` or a Pages domain splits link equity and puts two
of the project's own pages into the same search results. The usual remedy, a canonical link back to
phpledger.com, makes the copy non-indexable and therefore pointless as promotion. There is also a trust
cost: the audience is small-business owners and accountants choosing accounting software, and two
domains showing the same product reads as unsettled.

The site also cannot move, as the earlier review recorded. `PL_RELEASE_FEED_URL` pins
`https://phpledger.com/releases/index.json` inside the updater, and the same origin proxies the live
demo, which static hosting cannot do.

**Three uses of Pages that add something instead of duplicating:**

1. **Cloudflare Pages previews for website pull requests.** Unlimited preview deployments on the free
   tier, and reviewers get a real URL instead of a local build. This is the one with day-to-day value
   and it never serves production.
2. **Claim `phpledger.github.io` as a permanent redirect to phpledger.com.** It costs nothing, stops
   anyone else taking the namespace, and carries no duplicate-content risk.
3. **A Cloudflare Pages copy of the static site as a standby**, published but not pointed at by DNS, so
   a host outage has a documented failover for the marketing pages. The feed and demo would still be
   down, so this is a partial measure and should be described as one.

## Softaculous and the shared-hosting panels

This is the channel that matches how the target market buys hosting, and the distribution plan ranks it
Tier 1 for that reason.

What it would take:

- The **custom package** files that Softaculous documents: `info.xml`, `install.xml`, `install.php`
  with an `__install()` function, `install.js` with a `formcheck()`, `fileindex.php` and the zipped
  release, with optional `cust.sql` and `upgrade.xml`, `upgrade.php` and `upgrade.js`. A custom package
  needs no approval and can be given to an individual host immediately.
- `install.php` would write `config.local.php` from the panel's parameters and run the same three
  scripts the container entrypoint runs. **Nothing new is needed in the application for this.**
- **One genuine gap, now [#100](https://github.com/phpledger/phpledger/issues/100): there is no panel upgrade entry point.** The release protocol's channel table says
  the panel "runs `upgrade.php`: extract over the installation, then `install/migrate.php`", and no
  `upgrade.php` exists. It is a thin script, but the protocol implies it already exists.
- The **official library** listing is a separate, slower path. Its process, criteria and cost are not
  publicly documented and need direct contact with Softaculous. The custom package does not wait on it.

Installatron follows the same package discipline and is the second panel worth pursuing.

## The container app stores

These take the compose file the project already ships. `compose.production.yaml` pins the public image,
reads its settings from environment variables and installs its administrator on first start, which is
the shape these catalogues expect. Verified requirements, with the fit noted:

| Platform | Artifact | Where it goes | Review | Fit |
|---|---|---|---|---|
| **CasaOS** | `docker-compose.yml` with `x-casaos` metadata, icon, at least one screenshot | Pull request to the CasaOS-AppStore repository | CI plus maintainer review | Good. Note the rule that image tags must be pinned and `:latest` is not allowed |
| **CapRover** | One YAML file, `captainVersion: 4`, with a `caproverOneClickApp` section and an icon | Pull request to the CapRover one-click-apps repository | Validation script plus maintainer review | Good. The "official" trust badge needs official base images; ours is `php:8.3-apache` on GHCR |
| **Umbrel** | Compose app folder and manifest, a 256×256 SVG icon, three to five gallery images | Pull request to the umbrel-apps repository | Pull-request review | Good. Its hard rule is a full web interface with no command-line step after install, which the browser installer satisfies |
| **Coolify** | Compose file in the templates directory plus an entry in the service-template index | Pull request to the Coolify repository | Not confirmed | Good, low effort |
| **Cloudron** | `CloudronManifest.json` and a self-hosted `CloudronVersions.json` | No central queue; the version catalogue is self-hosted and optionally listed on the community store | The community store is explicitly not vetted | Good, and unusually self-directed |
| **Portainer** | A JSON app template | Self-hosted template URL; no confirmed path into the bundled catalogue | n/a | Publish our own template URL and document it |
| **YunoHost** | `manifest.toml` | Pull request adding the repository to the apps catalogue | Automated CI, and an app "level" assigned by a bot rather than self-declared | **Different packaging.** YunoHost installs natively on Debian, not from a container, so this one consumes the command-line install path rather than the image. PHP and MySQL applications are common there |
| **Elestio** | No public specification; they build and manage the deployment | Informal outreach | n/a | Revenue share of roughly twenty per cent is cited for participating authors |
| **PikaPods** | A container image on a single HTTPS port; they package it | Request or vote on their feedback page | Informal staff testing | Revenue share of up to roughly twenty per cent. Their bar excluding applications that compete with the author's own paid hosting **does not apply**: the owner confirmed on 21 September 2026 that PHP Ledger is self-hosted software and the project offers no hosted product |

Several fee and process details above could not be confirmed from a primary source, in particular
Coolify's canonical documentation, Portainer's third-party submission path, and the exact terms for
Elestio, PikaPods and CapRover. Confirm those directly before committing to any of them.

### Suggested order

1. **CasaOS, CapRover and Coolify** first. Each is a single file in a public repository, all three take
   the compose file that already exists, and none has an age or popularity bar. This is the cheapest
   real distribution the project can buy with a day's work.
2. **Cloudron and Portainer** next, because both are self-published and need no one's approval.
3. **Umbrel** once there are gallery screenshots, which the media kit already produces.
4. **Softaculous custom package** when the owner wants the shared-hosting market, plus the missing
   `upgrade.php`. The official library request runs in parallel and on its own clock.
5. **Elestio and PikaPods** last, as commercial conversations rather than packaging tasks.

**The plan's own business gate still applies and is the owner's call, not a technical one.** It phases
Softaculous, Installatron and the directory listings to the trader-complete release, on the reasoning
that a first impression is spent once. That reasoning applies with much less force to the container
catalogues, whose audience is technical, expects to read a limitations list, and can be reached again.
