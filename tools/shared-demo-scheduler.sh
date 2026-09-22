#!/bin/sh
set -eu
while :; do
    if php /var/www/phpledger/tools/shared-demo-reset.php; then
        now=$(date -u +%s)
        sleep "$((3600 - now % 3600))"
    else
        echo 'Shared demo reset failed; retrying the guarded reset in 30 seconds.' >&2
        sleep 30
    fi
done
