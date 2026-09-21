# Container catalogue manifests

This directory holds the source manifests for the "container catalogue" one-click
channels tracked by [issue #101](https://github.com/phpledger/phpledger/issues/101):
CasaOS, CapRover and Coolify. Each one wraps the same published image,
`ghcr.io/phpledger/phpledger`, the way `compose.production.yaml` (repository root)
already runs it, so none of them builds application code a different way
(`docs/RELEASE-PROTOCOL.md` principle 2).

**Nothing here has been submitted anywhere yet.** These are local, reviewed source
files kept in this repository. Opening the actual pull requests against each
upstream catalogue is a separate, explicit step - not implied by this directory
existing.

## Layout and where each one goes

| Path | Upstream repository | Real path there |
|---|---|---|
| `casaos/docker-compose.yml` | [`IceWhaleTech/CasaOS-AppStore`](https://github.com/IceWhaleTech/CasaOS-AppStore) | `Apps/PHPLedger/docker-compose.yml` (one folder per app, confirmed from their `Apps/` tree) |
| `caprover/phpledger.yml` | [`CapRover/one-click-apps`](https://github.com/CapRover/one-click-apps) | `public/v4/apps/phpledger.yml` (flat file per app, confirmed from their `public/v4/apps/` tree - **not** the `captain-definition-oneclick.yml` name a naive guess would use) |
| `coolify/phpledger.yaml` | [`coollabsio/coolify`](https://github.com/coollabsio/coolify) | `templates/compose/phpledger.yaml`, plus an entry added to `templates/compose/service-templates.json` (confirmed file convention; the JSON-index update step is inferred, see below) |

Each manifest keeps the same shape `compose.production.yaml` defines: the app on
port 8080 internally, `PL_DB_HOST`/`PL_DB_PORT`/`PL_DB_NAME`/`PL_DB_USER`/`PL_DB_PASSWORD`,
`PL_PUBLIC_URL`, the private volume mounted at `/var/lib/phpledger`, a MySQL 8.4
database with `--log-bin-trust-function-creators=1`, and the
`PL_ADMIN_EMAIL`/`PL_ADMIN_NAME`/`PL_ADMIN_USERNAME`/`PL_ADMIN_PASSWORD` variables
that install the owner on first start (`docs/CONTAINER.md`).

## Keeping these in step with the release

Bumping the image tag in each manifest here is [release protocol step
10](../../docs/RELEASE-PROTOCOL.md#the-per-release-sequence): *"Bump the image tag
in each catalogue manifest under `resources/distribution/` and open the catalogue
update requests."* Concretely, on every release:

- `casaos/docker-compose.yml`: bump the `image:` tag on the `web` service. CasaOS
  forbids `:latest`, so this must always be a concrete version (currently `1.2.1`).
- `caprover/phpledger.yml`: bump `defaultValue` on the `$$cap_phpledger_version`
  variable.
- `coolify/phpledger.yaml`: bump the `image:` tag on the `phpledger` service.

None of the three ever changes `PL_DB_*` shape, the volume paths, or the MySQL
service definition without a corresponding change to `compose.production.yaml`
first - these manifests follow that file, they do not diverge from it.

## Secrets

No manifest hard-codes a password. Coolify's generator syntax
(`$SERVICE_PASSWORD_*`, `$SERVICE_USER_*`) is used where the platform supports it.
CasaOS and CapRover have no equivalent generator: CasaOS ships the password fields
as empty strings that the app's environment-editing UI (`x-casaos.envs`) prompts
the installer to fill in before first start; CapRover exposes them as required
`caproverOneClickApp.variables` entries with no default, which the panel's
install wizard forces the operator to fill in.

## Assets

Real, already-published assets are used everywhere one was available:

- Icon (CasaOS `x-casaos.icon`, CapRover `logoUrl`): the existing 512x512
  `www/website/public/assets/brand/icon-512.png`, referenced via
  `raw.githubusercontent.com`. This is a placeholder for the PR, not the final
  choice: both CasaOS and CapRover conventionally want a contributor to add a
  dedicated icon file inside their own repository's app folder alongside the
  manifest (documented as a `TODO` comment in each manifest, naming that exact
  path), and CapRover marks the app `isOfficial: false` until that happens.
- Screenshots (CasaOS `x-casaos.screenshot_link`): the existing
  `docs/repository/assets/owner-overview-preview.webp`,
  `expense-to-journal-preview.webp` and `cash-pos-click-preview.png`.
- Coolify's `# logo:` header comment points at `svgs/phpledger.svg`, matching
  their own templates' convention of referencing a file inside their `svgs/`
  directory rather than an external URL - that file does not exist yet in the
  Coolify repository and would need to be contributed with the PR (left as a
  comment, not a working URL, since inventing one would be worse than leaving it
  named).

## What is confirmed vs. inferred

Confirmed directly from each upstream repository's own current tree and file
contents (via `gh api`, 21 September 2026):

- CasaOS: `Apps/<Name>/docker-compose.yml` layout, the full `x-casaos` schema
  (`id`, `architectures`, `main`, `category`, `developer`, `author`, `tagline`,
  `description`, `title`, `icon`, `screenshot_link`, `thumbnail`, `scheme`,
  `port_map`, `index`), the per-service `x-casaos.ports`/`volumes`/`envs` blocks,
  and that `:latest` is never used in a shipped app (every inspected app pins a
  version).
- CapRover: `public/v4/apps/<kebab-name>.yml` layout (flat, one file per app,
  no per-app subdirectory or `-oneclick` suffix), `captainVersion: 4`, the
  `$$cap_*` variable substitution syntax, and the `caproverOneClickApp` schema
  (`variables`, `instructions.start`/`end`, `displayName`, `isOfficial`,
  `description`, `documentation`).
- Coolify: `templates/compose/<name>.yaml` layout, the leading `#` metadata
  comment block (`documentation`, `slogan`, `category`, `tags`, `logo`, `port`),
  and the `$SERVICE_PASSWORD_*`/`$SERVICE_USER_*`/`$SERVICE_URL_*` generator
  syntax (cross-checked against `wordpress-with-mariadb.yaml`, `paperless.yaml`
  and `plunk.yaml`).

Inferred, not confirmed against upstream process docs:

- Whether Coolify's `templates/compose/service-templates.json` index needs a
  manual entry alongside a new compose file, or is generated from the directory
  automatically - issue #101 lists Coolify's review process as "not confirmed"
  and this manifest does not resolve that.
- The exact contribution mechanics for adding a per-app icon/screenshot file to
  CasaOS-AppStore or a logo to CapRover's `one-click-apps` (both clearly expect
  one, per their existing apps, but neither's CONTRIBUTING docs were read here -
  only their shipped app files).
