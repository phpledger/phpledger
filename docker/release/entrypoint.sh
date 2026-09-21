#!/bin/sh
set -eu

# Container entrypoint (1.2 M12, docs/CONTAINER.md).
#
# 1. Creates the private storage directories the image's environment configures.
#    update_functions.php's application guard (pl_update_application_guard, loaded by
#    every request through includes/bootstrap.php, including /health) refuses to serve
#    ANY request when PL_INSTALL_DIRECTORY is configured but the directory does not yet
#    exist - a real host misconfiguration on shared hosting, but on a fresh named
#    volume it just means nobody has created it yet. Doing that here, before Apache
#    ever starts, is what keeps /health answering correctly from the container's very
#    first request instead of 503-ing until an operator notices.
# 2. Waits for the configured database to become reachable.
# 3. Installs from the environment when full administrator credentials are supplied
#    and installation has not already completed, otherwise leaves the browser
#    installer at /install to finish setup (docs/INSTALLER.md's WordPress-style flow).
# 4. Applies pending migrations on an already-installed database when
#    PL_AUTO_MIGRATE=1 is set, so a pulled newer image can upgrade on start.
#
# Every step is idempotent: a restarted or recreated container repeats it safely.

APP_ROOT=/var/www/phpledger
INSTALL_SCRIPTS="$APP_ROOT/www/phpledger/install"
INSTALL_DIR="${PL_INSTALL_DIRECTORY:-/var/lib/phpledger/installation}"
OAUTH_DIR="${PL_OAUTH_KEY_DIRECTORY:-/var/lib/phpledger/oauth}"

mkdir -p "$INSTALL_DIR" "$OAUTH_DIR"

if [ -n "${PL_DB_HOST:-}" ]; then
    echo "phpledger-entrypoint: waiting for the database at ${PL_DB_HOST}:${PL_DB_PORT:-3306}..."
    attempt=0
    until php "$INSTALL_SCRIPTS/preflight.php" >/tmp/phpledger-preflight.log 2>&1; do
        attempt=$((attempt + 1))
        if [ "$attempt" -ge 60 ]; then
            echo 'phpledger-entrypoint: the database did not become reachable in time. Last check:' >&2
            cat /tmp/phpledger-preflight.log >&2
            exit 1
        fi
        sleep 2
    done
fi

if [ -f "$INSTALL_DIR/installed.json" ]; then
    if [ "${PL_AUTO_MIGRATE:-0}" = '1' ]; then
        echo 'phpledger-entrypoint: applying pending migrations (PL_AUTO_MIGRATE=1)...'
        php "$INSTALL_SCRIPTS/migrate.php"
    fi
elif [ -n "${PL_ADMIN_EMAIL:-}" ] && [ -n "${PL_ADMIN_NAME:-}" ] && [ -n "${PL_ADMIN_PASSWORD:-}" ]; then
    echo 'phpledger-entrypoint: installing from the environment...'
    php "$INSTALL_SCRIPTS/migrate.php"
    if [ -n "${PL_ADMIN_USERNAME:-}" ]; then
        php "$INSTALL_SCRIPTS/create-admin.php" --email="$PL_ADMIN_EMAIL" --name="$PL_ADMIN_NAME" --username="$PL_ADMIN_USERNAME"
    else
        php "$INSTALL_SCRIPTS/create-admin.php" --email="$PL_ADMIN_EMAIL" --name="$PL_ADMIN_NAME"
    fi
    php "$INSTALL_SCRIPTS/complete.php"
    echo 'phpledger-entrypoint: environment installation complete. Sign in and create your first company.'
else
    echo 'phpledger-entrypoint: PL_ADMIN_EMAIL, PL_ADMIN_NAME and PL_ADMIN_PASSWORD were not all supplied; open /install to finish setup in the browser.'
fi

exec "$@"
