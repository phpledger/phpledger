# Container image

**Installing on your Windows computer?** Follow the [Docker Desktop walkthrough](wiki/Install-with-Docker-Desktop.md) with the ready-made [desktop setup file](../compose.desktop.yaml). It takes you through the first account without Git, Node, Composer or a source build. The recipe selects the 1.4.5 image; its first-install check is recorded separately after image publication.

| Setup file | Use |
|---|---|
| [compose.desktop.yaml](../compose.desktop.yaml) | Local Docker Desktop installation with a database, persistent records and loopback-only access. |
| [compose.production.yaml](../compose.production.yaml) | Server deployment reference; configure passwords, HTTPS and network access. |
| [compose.yaml](../compose.yaml) | Source development and tests; follow [DEVELOPMENT.md](DEVELOPMENT.md). |

Pulling the application image alone does not supply its database or persistent storage. The desktop recipe starts both services.

The 1.3 release publishes official images to **ghcr.io/phpledger/phpledger** and **phpledger/phpledger** on Docker Hub. The owner restored both channels for this release; earlier GHCR-only policy remains historical. Both images must be built from the same verified release ZIP. Use the publication receipt for the exact available tags and digests; this guide does not itself prove a registry push. See the [release protocol](RELEASE-PROTOCOL.md).

The image is built from the published release ZIP, never from the working tree (`docker/release/Dockerfile`): it is the same file set the ZIP and Composer channels install, repackaged, not a separately built application (release protocol principle 2). It runs a single `php:8.3-apache` base as a non-root process on port 8080.

## Install

### The quick way

```
npm create phpledger@latest
```

[`create-phpledger`](../clients/js/create-phpledger/README.md) writes the compose file and a `.env`
for you, pinned to the current stable release rather than to `latest`, with the database and
administrator passwords generated rather than chosen. It starts nothing and installs nothing itself;
it prints the `docker compose up -d` line to run next. Node 20 or newer.

### By hand

```
mkdir phpledger && cd phpledger
curl -O https://raw.githubusercontent.com/phpledger/phpledger/master/compose.production.yaml
cat > .env <<'EOF'
PL_DB_PASSWORD=choose-a-strong-password
PL_DB_ROOT_PASSWORD=choose-a-different-strong-password
PL_PUBLIC_URL=https://accounts.example.com
EOF
docker compose -f compose.production.yaml up -d
```

This starts MySQL 8.4 and the application, with private application state (the installation receipt, the setup key file if any, OAuth keys) on the `phpledger_private` volume and the database on `phpledger_data`. Two ways to finish setup:

- **From the environment.** Add `PL_ADMIN_EMAIL`, `PL_ADMIN_NAME` and `PL_ADMIN_PASSWORD` (and optionally `PL_ADMIN_USERNAME`) to `.env` before the first `up`. The entrypoint applies the migrations, creates that administrator account and marks installation complete before Apache starts. Sign in, then create your first company from the ordinary onboarding screen - the entrypoint does not guess a company's name, currency or fiscal year for you.
- **In the browser.** Leave those three variables unset and open `http://<host>:8080/install`. The same WordPress-style installer the ZIP channel uses runs inside the container (`docs/INSTALLER.md`); enter `db` (this compose file's database service name) as the host, and the password from `PL_DB_PASSWORD`. Set `PL_SETUP_KEY` first if this port is reachable from the public internet before you finish setup.

Either way, `/health` answers `{"status":"ok"}` once the web server and its database connection are up, whether or not installation itself has completed yet - it is a readiness probe for the container, not an installation-complete flag. The image's own `HEALTHCHECK` polls it.

## Upgrade

1. Back up the `phpledger_data` and `phpledger_private` volumes.
2. Pull the new tag: `docker compose -f compose.production.yaml pull web`.
3. Restart with `PL_AUTO_MIGRATE=1` set for this run (in `.env` or `docker compose run -e PL_AUTO_MIGRATE=1`), so the entrypoint applies pending migrations against the existing database before Apache starts serving the new code. Recreate the container: `docker compose -f compose.production.yaml up -d web`.
4. Once the upgrade has run once, `PL_AUTO_MIGRATE` can be left set (later starts with nothing pending are a fast no-op) or turned back off until the next upgrade - either is safe.

This installation never replaces its own files in place: `pl_update_mode()` reports `container` here, and the maintenance page at `/maintenance.php` (reached only by an in-app update attempt, which does not apply to this channel) shows the pull-and-restart steps above instead of a file-upload form. `public/maintenance.php` itself is never changed by this: its bytes stay pinned to what the ZIP updater ships, and the mode check lives in `update_web_functions.php`, which is copied into the update recovery runtime alongside it.

## Environment variables

| Variable | Meaning |
|---|---|
| `PL_DB_HOST`, `PL_DB_PORT`, `PL_DB_NAME`, `PL_DB_USER`, `PL_DB_PASSWORD` | Database connection. `compose.production.yaml` wires these to its own `db` service. |
| `PL_ADMIN_EMAIL`, `PL_ADMIN_NAME`, `PL_ADMIN_PASSWORD`, `PL_ADMIN_USERNAME` (optional) | Supply all three of the first group to install from the environment on first start. `PL_ADMIN_PASSWORD` is read directly from the environment by `install/create-admin.php`, and is never accepted as a command-line argument or logged. |
| `PL_AUTO_MIGRATE` | `1` applies pending migrations on start against an already-installed database (a pulled newer image); the default `0` leaves that to an explicit upgrade step. |
| `PL_PUBLIC_URL` | This installation's own address, shown on-screen and used for links; set it to the real `https://` address before exposing this beyond your own machine. |
| `PL_SETUP_KEY` | A private key the browser installer demands before `/install` opens at all (`docs/INSTALLER.md`). Recommended once the port is reachable from the public internet and environment installation is not used. |
| `PL_UPDATE_MODE` | Defaults to `container` in this image (`update_channel_functions.php`); only `managed` installations ever replace their own files, so leave this as the image sets it. |
| `PL_ALLOWED_ORIGINS` | Passed straight through to the application, as in the other channels. |

## Non-root and read-only

The image runs Apache entirely as `www-data`, listening on 8080 (not 80, an unprivileged port), and its application code is copied in world-readable but owned by nobody the running process can write as. Apache's prefork MPM only tries to drop root privileges when its master process starts as root; starting it already as `www-data` (the Dockerfile's `USER` directive) is what makes an unprivileged Apache work on an unprivileged port. The only writable path is the `/var/lib/phpledger` volume, which holds the installation receipt, the setup key file if one exists, and OAuth keys - never application code.

## Registries

Published by `.github/workflows/container-image.yml` when a GitHub Release is published (after the owner has attached the signed archive and metadata and published the release - see the release protocol's per-release sequence). Tags:

| Release | Tags |
|---|---|
| Stable (`1.3.0`) | `1.3.0`, `1.3`, `1`, `latest`, on both registries after verified publication |
| Prerelease (`1.3.0-rc.1`) | `1.3.0-rc.1` only. `latest` never moves for a prerelease. |

GHCR authenticates with the workflow's own `GITHUB_TOKEN`. Docker Hub needs the owner's account: repository secrets `DOCKERHUB_NAMESPACE` and `DOCKERHUB_TOKEN`. Both official channels are release requirements. Missing Docker Hub credentials are a publication blocker to report, not proof that the second channel shipped. Record each pushed digest and verify the installed version from both registries.

## Three installer behaviours this image needed fixed

The browser installer and the "already installed" check were both written before any deployment configured its database purely through the environment while genuinely not yet being installed (`www/phpledger/includes/functions/`):

- `pl_web_needs_installation()` (`web_functions.php`) used to treat any supplied `PL_DB_PASSWORD` as proof of a finished installation. That is still correct for local development and the test containers, which are migrated out of band and never write the browser installer's completion receipt (`docs/DEVELOPMENT.md`); it was wrong for a fresh container, which always has a database password and yet may not be installed at all. The check now trusts only the installer's own private configuration file or its `installed.json` receipt, with the development/test shortcut kept exactly where it already applied.
- Entering a compose service hostname such as `db` into the browser installer's database step used to demand the same remote-database setup-code proof a genuinely unfamiliar external host would (`install_web_functions.php`). `pl_install_trusted_environment_host()` now recognises the literal host this image's own `PL_DB_HOST` configured, but only when `PL_UPDATE_MODE=container` - a managed ZIP install pointed at any other host still gets the setup-code prompt.
- `/health` loaded the full application bootstrap, whose `pl_update_application_guard()` refuses every request when `PL_INSTALL_DIRECTORY` is configured but the directory does not exist yet - correct on a shared host where that means a misconfiguration, wrong on a fresh named volume where it just means nobody has created it yet. The entrypoint now creates `PL_INSTALL_DIRECTORY` and `PL_OAUTH_KEY_DIRECTORY` before Apache ever starts, so `/health` answers correctly from the container's first request.

None of the three changes touch a ZIP or Composer installation's own behaviour: the first two only widen an existing environment-specific exception, and the third is entirely inside the image's own entrypoint.
