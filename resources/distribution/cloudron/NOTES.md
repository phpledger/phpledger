# Cloudron packaging notes

Prepared for issue #101. **Nothing here has been built, published, or submitted.**
`Dockerfile`/`start.sh` have not been run through `cloudron build` or `cloudron install`
- that tooling was not available in this environment - so treat them as a reviewed
first draft, not a verified package.

## The mysql addon conflicts with our fixed `--log-bin-trust-function-creators=1` flag

`compose.production.yaml` starts its own `db` service with
`command: ["--log-bin-trust-function-creators=1"]`. That flag matters because our
migrations create triggers and functions under binary logging, which MySQL refuses
without an appropriate privilege or an operator-approved trust setting. Adding a `DETERMINISTIC` label does not solve trigger-creation privileges: `CREATE TRIGGER` has no such characteristic.

Cloudron's `mysql` addon is **one shared MySQL 8.4 server for every app on the box**,
not a per-app container we can pass a startup flag to. Cloudron's own addon
documentation (`docs.cloudron.io/packaging/addons/`) describes the addon as
pre-creating a database and credentials for the app to use; it does not document any
mechanism for an app to request a custom server flag or a session/global variable
change on that shared server, and there is no `SUPER`/`SYSTEM_VARIABLES_ADMIN`
grant documented for the per-app database user either.

**This means: as packaged, PHP Ledger's migrations may fail against the mysql addon's
server if that server was not itself started with `log_bin_trust_function_creators`
enabled by the Cloudron host administrator.** This is not worked around here. The host operator must review the applicable privilege and binary-log policy:

- Verify that the migration account can create the required triggers under the actual server policy. Function characteristics describe routine behavior; they are not a substitute for trigger privileges, and historical migration bytes must not be rewritten to add misleading labels.
- Ask a Cloudron server operator to enable `log_bin_trust_function_creators` globally
  via `my.cnf` on the box's shared MySQL server, which is outside a package's control
  and cannot be asserted or requested from `CloudronManifest.json`.

## Other things assumed, not confirmed

- **`/app/data` ownership at first start.** `start.sh` runs as the base image's
  `www-data` user (inherited, not changed by the wrapper `Dockerfile`) and does
  `mkdir -p /app/data/installation /app/data/oauth` without ever becoming root. This
  assumes Cloudron chowns `/app/data` to match the image's own runtime user before the
  container's first start, the way most non-root Cloudron packages rely on. Cloudron's
  own docs pages fetched for this task did not spell out that chowning behaviour
  explicitly enough to state it as confirmed.
- **Icon size.** Cloudron's manifest docs describe the icon as "256x256 square"; the
  only real, verified asset available is 512x512
  (`www/website/public/assets/brand/icon-512.png`). It is still square and referenced
  via `iconUrl`, which several published community packages also do at larger sizes, so
  this is used as-is rather than left as a TODO - but it has not been confirmed that
  Cloudron's own store tooling accepts it without resizing.
- **`mediaLinks` aspect ratio.** Cloudron's docs mention a 3:1 aspect ratio for these
  images; the three verified preview assets (`owner-overview-preview.webp`,
  `expense-to-journal-preview.webp`, `cash-pos-click-preview.png`) are ordinary product
  screenshots, and their exact aspect ratio against that 3:1 expectation was not
  checked. Used as-is per the same reasoning as the icon, not blocked on it.
- **`CloudronVersions.json`'s `dockerImage`.** Set to
  `ghcr.io/phpledger/phpledger-cloudron:1.2.1`, a placeholder tag for the wrapper image
  this `Dockerfile` describes. That image has not been built or pushed anywhere; it
  must exist at that reference before this `CloudronVersions.json` is actually usable.
  `publishState` is left as `"testing"` and `stable: false` for the same reason.
- **PL_ADMIN_\* and PL_SETUP_KEY.** Cloudron has no per-install operator-variable
  prompt comparable to CapRover's `$$cap_*` fields or Portainer's `env[]` entries, so
  `start.sh` leaves these unset by default and the manifest's `postInstallMessage`
  points the owner at the browser installer (`/install`) instead, consistent with the
  "leave blank to finish setup in the browser" path `docs/CONTAINER.md` already
  documents for every other channel.

## Confirmed vs. inferred

Confirmed from `docs.cloudron.io` (fetched for this task) and a real published
community `CloudronVersions.json` (`Tymeslot/tymeslot`, via `gh api`):

- `CloudronManifest.json` required fields (`id`, `httpPort`, `healthCheckPath`,
  `manifestVersion: 2`, `version`) and the optional fields used here.
- The `mysql` addon's injected variable names: `CLOUDRON_MYSQL_HOST`,
  `CLOUDRON_MYSQL_PORT`, `CLOUDRON_MYSQL_USERNAME`, `CLOUDRON_MYSQL_PASSWORD`,
  `CLOUDRON_MYSQL_DATABASE` (single-database mode, which is what an empty
  `"mysql": {}` addon declaration uses).
- `CloudronVersions.json`'s top-level `stable`/`versions` shape, and that each version
  entry nests a full manifest object plus `creationDate`, `ts`, and `publishState`
  (`published`/`testing`/`revoked`), including the `dockerImage` field inside that
  nested manifest - all cross-checked against Tymeslot's real, published file.

Inferred, not confirmed:

- The `/app/data` ownership/chown behaviour described above.
- Exact icon and `mediaLinks` dimension requirements (see above).

## Tracked

The addon/binary-logging mismatch above is [issue
#103](https://github.com/phpledger/phpledger/issues/103). **Do not submit this package until the actual host privilege/trust configuration is verified and installation succeeds.** The same constraint will apply to any managed or shared MySQL, including the
cloud database tiers decision P4 anticipates.


Reference: [MySQL 8.0 stored-program binary logging](https://dev.mysql.com/doc/refman/8.0/en/stored-programs-logging.html) distinguishes function characteristics from the trigger privilege requirement. No Cloudron host acceptance is claimed here.
