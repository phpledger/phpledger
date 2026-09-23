# PikaPods submission dossier — PHP Ledger

PikaPods publishes no open packaging schema either (confirmed from
docs.pikapods.com — see `NOTES.md`). New applications are added after a
request or vote on their feedback page, and PikaPods' own staff package the
application into a pod. This dossier is written to be pasted into that
request, with `compose.yaml` attached as a working reference deployment.

## Description

PHP Ledger is self-hosted, open-source double-entry accounting software
written in PHP, built to run on ordinary shared or VPS PHP hosting as well as
containers. It covers the general ledger, accounts receivable and payable,
inventory, and reporting (trial balance, profit & loss, balance sheet), with a
non-interactive install path suited to one-click deployment: database
settings, schema and an administrator account are all set from environment
variables or a browser installer with no command-line step required.

## Category

Finance / Accounting

## Licence

AGPL-3.0-or-later

## Links

- Homepage: https://phpledger.com/
- Source: https://github.com/phpledger/phpledger
- Demo: https://phpledger.com/demo/

## Image location

`ghcr.io/phpledger/phpledger`, pinned to `1.3.0`. Never `:latest` — see
`compose.yaml`. Built from a real tagged release ZIP, not from a development
branch (`docker/release/Dockerfile` in this repository).

## Environment variables

| Variable | Required | Purpose |
|---|---|---|
| `PL_DB_HOST` | Yes | Database hostname (the `db` service in `compose.yaml`) |
| `PL_DB_PORT` | Yes | Database port (3306) |
| `PL_DB_NAME` | Yes | Database name (`phpledger`) |
| `PL_DB_USER` | Yes | Database user |
| `PL_DB_PASSWORD` | Yes, no default | Database password |
| `PL_PUBLIC_URL` | Yes | Public HTTPS URL the app is served at |
| `PL_AUTO_MIGRATE` | No, default `0` | Applies pending migrations on start; set to `1` only for the single start after a newer image tag is pulled |
| `PL_ADMIN_EMAIL` | No | Administrator email; if unset, finish setup at `/install` in the browser |
| `PL_ADMIN_NAME` | No | Administrator display name |
| `PL_ADMIN_USERNAME` | No | Administrator username |
| `PL_ADMIN_PASSWORD` | No | Administrator password |
| `PL_SETUP_KEY` | No | Optional key required before `/install` opens; recommended once reachable from the public internet |
| `PL_ALLOWED_ORIGINS` | No | CORS allow-list |
| `PL_DB_ROOT_PASSWORD` (on the `db` service) | Yes, no default | MySQL root password |

## Assets

- Icon: https://raw.githubusercontent.com/phpledger/phpledger/master/www/website/public/assets/brand/icon-512.png
- Screenshots: https://raw.githubusercontent.com/phpledger/phpledger/master/docs/repository/assets/owner-overview-preview.webp , https://raw.githubusercontent.com/phpledger/phpledger/master/docs/repository/assets/expense-to-journal-preview.webp , https://raw.githubusercontent.com/phpledger/phpledger/master/docs/repository/assets/cash-pos-click-preview.png

All four confirmed reachable (HTTP 200) at time of writing.

## Resource expectations

No load-tested figures exist for this repository to cite, so none are
claimed. The application is a single PHP/Apache process (`php:8.3-apache`
base) plus MySQL 8.4 — modest by design, not benchmarked against PikaPods'
own per-pod limits, which PikaPods staff are better placed to size than this
dossier.

## Health endpoint

`GET /health` returns `{"status":"ok"}` once the web server and its database
connection are reachable, independent of whether first-run installation has
completed. The image's own `HEALTHCHECK` already polls it; `compose.yaml`
exposes the same check.

## Upgrade procedure

1. Back up both volumes (`phpledger_data` for MySQL, `phpledger_private` for
   application state) before changing anything.
2. Pull the new image tag.
3. Start the container once with `PL_AUTO_MIGRATE=1` so pending migrations run.
4. Set `PL_AUTO_MIGRATE` back to `0` (or leave it unset) for ordinary restarts.

## Maintenance contact

TODO (owner to fill in): the maintenance/support contact PikaPods should list
for this listing.

## Limitations

PHP Ledger 1.0.0 was published 18 September 2026; the current release is
1.2.1. Development has been rapid and recent. As of this dossier's writing,
**no independent accounting review, no independent security review, and no
supervised third-party pilot have taken place.** Operators should treat this
as software from a young, actively developing project rather than an
established, externally-audited accounting product, and should verify its
output against their own accounting practice before relying on it for
statutory reporting.


## 1.3.0 candidate update

The image reference above follows the 1.3.0 release candidate. Older observations in this dossier describe their dated source and are not new provider acceptance. Verify the actual published tag/digest and repeat the provider checks before submission.
