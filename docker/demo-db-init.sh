#!/bin/sh
set -eu
# Generated hexadecimal passwords avoid SQL interpolation ambiguities.
for value in "$PL_DEMO_WEB_PASSWORD" "$PL_DEMO_RESET_PASSWORD"; do
    case "$value" in ''|*[!a-f0-9]*) echo 'Demo passwords must be random lowercase hexadecimal.' >&2; exit 1;; esac
    [ "${#value}" -ge 48 ] || exit 1
done
MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --protocol=socket -u root <<SQL
CREATE DATABASE IF NOT EXISTS phpledger_demo CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE USER 'ledger_demo_web'@'%' IDENTIFIED BY '${PL_DEMO_WEB_PASSWORD}';
-- The real installer needs DDL on its one disposable database. No global grants.
GRANT ALL PRIVILEGES ON phpledger_demo.* TO 'ledger_demo_web'@'%';
CREATE USER 'ledger_demo_reset'@'%' IDENTIFIED BY '${PL_DEMO_RESET_PASSWORD}';
GRANT ALL PRIVILEGES ON phpledger_demo.* TO 'ledger_demo_reset'@'%';
SQL
