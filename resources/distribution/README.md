# Distribution manifests

Source manifests for every one-click and hosting-panel channel tracked by
[issue #101](https://github.com/phpledger/phpledger/issues/101). Eleven platforms in
two groups, which behave very differently.

**Nothing here has been submitted anywhere.** These are local, reviewed source files.
Opening the pull requests and sending the requests is a separate, explicit step that
this directory existing does not imply.

## The two groups

**Container catalogues** wrap the published image, `ghcr.io/phpledger/phpledger`, the
way [`compose.production.yaml`](../../compose.production.yaml) already runs it. None of
them builds application code a different way, so [release protocol principle
2](../../docs/RELEASE-PROTOCOL.md) holds: they deploy the same artifact everyone else
installs.

**Hosting panels and native packagers** install the release ZIP into a web root and drive
the application's own command-line install path. They need nothing new in the
application: milestone M12 shipped that path in 1.2.1.

| Directory | Platform | Kind | Upstream destination |
|---|---|---|---|
| `casaos/` | CasaOS | container | `IceWhaleTech/CasaOS-AppStore`, `Apps/PHPLedger/docker-compose.yml` |
| `caprover/` | CapRover | container | `CapRover/one-click-apps`, `public/v4/apps/phpledger.yml` |
| `coolify/` | Coolify | container | `coollabsio/coolify`, `templates/compose/phpledger.yaml` |
| `cloudron/` | Cloudron | container | Self-hosted `CloudronVersions.json`; community store listing optional |
| `portainer/` | Portainer | container | Self-hosted template URL |
| `umbrel/` | Umbrel | container | `getumbrel/umbrel-apps`, `phpledger/` |
| `elestio/` | Elestio | container, managed | Outreach; no public schema |
| `pikapods/` | PikaPods | container, managed | Request on their feedback page; they package it |
| `yunohost/` | YunoHost | native Debian | `YunoHost/apps` catalogue, via its own app repository |
| `softaculous/` | Softaculous | hosting panel | Custom package now; official library by request |
| `installatron/` | Installatron | hosting panel | Application submission |

## The deployment contract

Every container manifest reproduces the same shape, and none of them diverges from
`compose.production.yaml` without that file changing first:

- Image `ghcr.io/phpledger/phpledger` pinned to a concrete version, never `latest`.
- Application on port 8080 internally, health probe at `/health`.
- MySQL 8.4 started with `--log-bin-trust-function-creators=1`.
- Private application state on a volume at `/var/lib/phpledger`.
- `PL_DB_HOST`, `PL_DB_PORT`, `PL_DB_NAME`, `PL_DB_USER`, `PL_DB_PASSWORD`,
  `PL_PUBLIC_URL`, `PL_AUTO_MIGRATE`, and `PL_ADMIN_EMAIL` / `PL_ADMIN_NAME` /
  `PL_ADMIN_USERNAME` / `PL_ADMIN_PASSWORD`, which install the owner on first start.

The panel and native packages instead drive this sequence, which is the same one the
container entrypoint runs:

1. Write `www/phpledger/includes/config.local.php`, a PHP file returning an array. It
   **overrides** environment variables.
2. Create `www/phpledger/storage/installation`, mode 0700. The bootstrap refuses every
   request until it exists.
3. `php www/phpledger/install/migrate.php`
4. `php www/phpledger/install/create-admin.php --email= --name= [--username=]`, with the
   password on stdin or in `PL_ADMIN_PASSWORD`, **never as an argument**.
5. `php www/phpledger/install/complete.php`

## Spec quirks worth knowing before you touch these

Each of these cost real time to find. They are recorded here so nobody pays twice.

**The release archive wraps everything in one directory.** `phpledger-1.2.1.zip` contains
a single top-level `phpledger/`. YunoHost's `ynh_setup_source` strips one wrapping
directory by default, which is correct. The Softaculous package archive must be built
with it stripped, so build it from the extracted tree rather than by renaming the release
ZIP. `softaculous/install.php` checks for `www/phpledger/install` first and stops with a
clear message if that step was missed.

**CasaOS forbids `:latest`.** Every shipped app in their store pins a version, so ours
does too.

**CapRover names a flat file per app** under `public/v4/apps/`, not a per-app directory
and not the `captain-definition-oneclick.yml` filename a reasonable guess produces.

**Coolify wants a leading `#` metadata comment block** and its own
`$SERVICE_PASSWORD_*` / `$SERVICE_USER_*` generator syntax rather than plain variables.

**Cloudron's MySQL addon cannot take our startup flag.** This is the one genuine contract
mismatch in the set and it is unresolved. The addon is one shared MySQL server for every
app on the box, not a per-app container, so `--log-bin-trust-function-creators=1` cannot
be passed. Our migrations create triggers and functions under binary logging, which MySQL
refuses without either that flag or every routine being marked `DETERMINISTIC`. The two
real options are named in `cloudron/NOTES.md`: mark the routines correctly in the
migrations, which is the proper fix and helps every engine, or ask a Cloudron operator to
set the flag server-wide, which a package cannot request. **Do not submit the Cloudron
package until one of those happens.** The package has also never been through
`cloudron build`, since the CLI was not available here.

**Portainer cannot pin a branch or tag.** Their documented template format accepts only
`url` and `stackfile`, so the stack file is read from the default branch. The version is
pinned instead through the template's own `PL_VERSION` default. A structural change to
`compose.production.yaml` on `master` therefore reaches existing template users
immediately, which is worth remembering when editing that file.

**Umbrel's compose does not validate on its own**, and should not. `app_proxy` has no
image until umbrelOS patches the file at install time, so `docker compose config` fails
with "neither an image nor a build context". Upstream's own shipped apps behave
identically. Strip `app_proxy` and supply their injected variables to validate the rest.
Umbrel is also the only manifest here pinning by digest as well as tag.

**Softaculous field names follow their own example**, so `admin_pass` rather than
`admin_password`, and there is no `softdbport` in `$__settings`, so the port is its own
field defaulting to 3306. The `<datadir>` semantics are undocumented, so the private
directory is created explicitly in PHP instead. No `cust.sql`: the schema belongs to the
versioned migrations, and a static dump would either drift from them or do nothing.
`upgrade.php` re-runs `migrate.php`, because the application has no upgrade entry point
of its own ([#100](https://github.com/phpledger/phpledger/issues/100)).

**Installatron's embedded PHP has no documented interface.** Their docs never state how
the `<install>` block receives database credentials or the site path, or how it signals
success or failure. That block is therefore a commented stub that throws, rather than an
invented API. Everything around it follows their published examples.

**Elestio and PikaPods publish no schema at all.** Each gets a working compose file plus a
`SUBMISSION.md` dossier a maintainer can act on. The revenue-share figures widely quoted
for both appear on neither company's own page, so they are recorded as unconfirmed rather
than stated.

## Secrets

**No manifest hard-codes a password.** Where a platform can generate one it does: Coolify's
`$SERVICE_PASSWORD_*`, Umbrel's `derive_entropy`, YunoHost's `ynh_string_random`.
Where it cannot, the value is a required operator-supplied variable with no default, so
the platform's own wizard forces it. CasaOS ships empty environment fields its UI prompts
for; CapRover uses `caproverOneClickApp.variables` with no defaults.

The administrator password never reaches a command line. Softaculous passes it through
`proc_open`'s environment array and YunoHost pipes it to `--password-stdin`, both because
`create-admin.php` refuses a password as an argument, and an argument would land in the
process list.

## Assets

Only these four, all verified publicly reachable, are referenced:

- `www/website/public/assets/brand/icon-512.png`
- `docs/repository/assets/owner-overview-preview.webp`
- `docs/repository/assets/expense-to-journal-preview.webp`
- `docs/repository/assets/cash-pos-click-preview.png`

Several catalogues conventionally want a dedicated icon committed inside their own app
folder rather than an external URL, and Coolify references a file in its own `svgs/`
directory. Those are left as named TODOs in the manifests rather than as invented URLs.
Umbrel's current packaging guidance asks for an empty gallery, which contradicts issue
#101's text; the fresher source was followed.

## The npm package

`create-phpledger` is published and is the quickest route to a container deployment:

```
npm create phpledger@latest
```

It lives at <https://www.npmjs.com/package/create-phpledger>. **Note it is not on the
organisation page** at <https://www.npmjs.com/org/phpledger>, because an unscoped package
is owned by the publishing user account. npm's own documentation states that an
organisation "can also use organizations to manage unscoped packages" and then gives no
mechanism for doing so, so check the package's own settings before assuming it can be
moved. The name must stay unscoped regardless: npm resolves `npm init foo` to `create-foo`,
so scoping it would change the published command to `npm create @phpledger`.

## Keeping these in step with a release

Bumping the image tag here is [release protocol step
10](../../docs/RELEASE-PROTOCOL.md). On every release:

- `casaos/docker-compose.yml`, `coolify/phpledger.yaml`, `elestio/compose.yaml`,
  `pikapods/compose.yaml`: bump the `image:` tag.
- `caprover/phpledger.yml`: bump `defaultValue` on `$$cap_phpledger_version`.
- `portainer/phpledger.json`: bump the `PL_VERSION` default in `env`.
- `cloudron/CloudronVersions.json`: add the new version entry.
- `umbrel/phpledger/docker-compose.yml`: bump the tag **and** the digest, and
  `umbrel-app.yml`'s `version`.
- `yunohost/manifest.toml`: bump `version`, the source `url` and its `sha256`.
- `softaculous/info.xml` and `installatron/phpledger/`: bump `<version>` and add a
  version directory.

## These files never ship

`resources/distribution/` is excluded from the release package, the same way
`docker/release/*` is. They describe how to deploy a release; they are never unzipped
into one. This is enforced, not merely observed:
`tests/package-builder-test.py` fails if the prefix appears in
`tools/package-files.json`.

## What is confirmed and what is not

Confirmed against each platform's own current sources on 21 September 2026: the CasaOS,
CapRover and Coolify file layouts and schemas; Cloudron's manifest fields and
`CLOUDRON_MYSQL_*` variable names, cross-checked against a real published
`CloudronVersions.json`; Portainer's v3 schema; Umbrel's current packaging guidance and
the `akaunting` app as a finance precedent; YunoHost's `manifest.toml` v2 schema and
helper syntax, cross-checked against `firefly-iii_ynh` as the closest analogue; PikaPods'
published application criteria. The 1.2.1 release checksum was verified against the
published checksum file and by hashing the download independently. The GHCR image digest
Umbrel pins was resolved live.

Not confirmed, and marked inline where it matters: whether Coolify's
`service-templates.json` index needs a manual entry; the contribution mechanics for
committing icons to CasaOS and CapRover; Softaculous's `fileindex.php` syntax, which is
nowhere shown verbatim; Installatron's embedded-PHP interface; Softaculous's official
library process, criteria and cost; Elestio's and PikaPods' exact commercial terms;
whether PikaPods accepts a bundled database service given their one-HTTPS-port rule;
Umbrel port 8773's uniqueness across their catalogue; and data-directory ownership
assumptions on Cloudron and Umbrel.

No package here has been installed on its real platform. They validate, and they follow
formats read from those platforms' own repositories, which is not the same thing.
