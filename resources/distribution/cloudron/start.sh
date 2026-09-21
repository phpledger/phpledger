#!/bin/bash
# Cloudron entrypoint wrapper. Runs as the base image's own `www-data` user (no root
# step here - see NOTES.md for what that assumes about /app/data ownership).
set -eu

# Cloudron's mysql addon (declared in CloudronManifest.json's "addons") injects
# CLOUDRON_MYSQL_*; map those onto the PL_DB_* names the image and
# compose.production.yaml both expect (docs/CONTAINER.md, "Environment variables").
export PL_DB_HOST="${CLOUDRON_MYSQL_HOST}"
export PL_DB_PORT="${CLOUDRON_MYSQL_PORT}"
export PL_DB_NAME="${CLOUDRON_MYSQL_DATABASE}"
export PL_DB_USER="${CLOUDRON_MYSQL_USERNAME}"
export PL_DB_PASSWORD="${CLOUDRON_MYSQL_PASSWORD}"

# NOTES.md: the shared mysql addon server cannot be started with our fixed
# --log-bin-trust-function-creators=1 flag the way compose.production.yaml's own `db`
# service is. Left unresolved here, not worked around silently - migrations that create
# triggers or functions may fail against this addon under binary logging.

# Cloudron gives every app its own subdomain; use it as this installation's own address.
export PL_PUBLIC_URL="https://${CLOUDRON_APP_DOMAIN}"

# Only /app/data survives a `cloudron update` or restore. Point the three paths the
# image otherwise nests under /var/lib/phpledger at the persistent volume instead of
# trying to relocate /var/lib/phpledger itself (which is a plain container path here,
# not a volume Cloudron manages).
mkdir -p /app/data/installation /app/data/oauth
export PL_INSTALL_DIRECTORY=/app/data/installation
export PL_OAUTH_KEY_DIRECTORY=/app/data/oauth
export PL_INSTALL_CONFIG_PATH=/app/data/config.local.php

# Left unset on purpose: Cloudron has no per-install operator-variable prompt the way
# CapRover's $$cap_* fields or Portainer's env[] entries do, so by default the owner
# finishes setup in the browser at https://<domain>/install instead (docs/CONTAINER.md,
# "In the browser"). Set real values here (and rebuild) to install from the environment
# instead, per docs/CONTAINER.md, "From the environment". Do the same for PL_SETUP_KEY.
export PL_ADMIN_EMAIL="${PL_ADMIN_EMAIL:-}"
export PL_ADMIN_NAME="${PL_ADMIN_NAME:-}"
export PL_ADMIN_USERNAME="${PL_ADMIN_USERNAME:-}"
export PL_ADMIN_PASSWORD="${PL_ADMIN_PASSWORD:-}"
export PL_SETUP_KEY="${PL_SETUP_KEY:-}"

# 0 by default; bump the image tag in the Dockerfile above and set this to 1 for one
# `cloudron update` run per docs/CONTAINER.md, "Upgrade", then leave it either way.
export PL_AUTO_MIGRATE="${PL_AUTO_MIGRATE:-0}"

exec /usr/local/bin/phpledger-entrypoint apache2-foreground
