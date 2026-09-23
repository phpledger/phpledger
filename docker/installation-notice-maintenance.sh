#!/bin/sh
set -eu
while :; do
    php /opt/installation-service/summary.php --prune
    sleep 3600
done
