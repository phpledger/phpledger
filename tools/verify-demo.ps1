param()
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
Push-Location (Split-Path $PSScriptRoot -Parent)
try {
    $database = (& docker compose exec -T db_test printenv MYSQL_DATABASE).Trim()
    if ($LASTEXITCODE -ne 0 -or $database -ne 'phpledger_test') { throw 'Demo verification requires the disposable db_test service.' }
    $sql = @'
CREATE USER IF NOT EXISTS 'ledger_demo_test'@'%' IDENTIFIED BY 'local-demo-test-only';
GRANT SELECT, INSERT, UPDATE ON phpledger_demo.* TO 'ledger_demo_test'@'%';
'@
    $sql | & docker compose exec -T db_test sh -lc 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot'
    if ($LASTEXITCODE -ne 0) { throw 'Could not prepare the restricted local demo test account.' }
    $options = @('--profile','test','run','--rm','-e','PL_ENV=demo','-e','PL_DB_NAME=phpledger_demo')
    & docker compose @options -e PL_DB_USER=root -e PL_DB_PASSWORD=local-test-root-only -e PL_DEMO_RESET_MODE=1 test php tools/demo-reset.php --now
    if ($LASTEXITCODE -ne 0) { throw 'Initial isolated demo reset failed.' }
    $query = 'SELECT generation FROM phpledger_demo.pl_demo_state WHERE id = 1;'
    $before = $query | & docker compose exec -T db_test sh -lc 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot --batch --skip-column-names'
    & docker compose @options -e PL_DB_USER=ledger_demo_test -e PL_DB_PASSWORD=local-demo-test-only test php tests/demo_smoke.php
    if ($LASTEXITCODE -ne 0) { throw 'Restricted demo smoke failed.' }
    # The reset guard accepts a person a visitor's own sample created inside it (1.2.0 samples
    # seed a second person so the capability system is visible). Prove it still refuses anyone
    # else, before the reset that has to succeed with that person present.
    $mysql = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot phpledger_demo'
    "INSERT INTO phpledger_demo.pl_users (email, display_name, password_hash) VALUES ('stranger.none@example.invalid', 'Stranger', 'x');" | & docker compose exec -T db_test sh -lc $mysql
    & docker compose @options -e PL_DB_USER=root -e PL_DB_PASSWORD=local-test-root-only -e PL_DEMO_RESET_MODE=1 test php tools/demo-reset.php --now
    if ($LASTEXITCODE -eq 0) { throw 'The reset accepted a person with no company membership.' }
    "INSERT INTO phpledger_demo.pl_companies (name, currency, start_date, fiscal_year_end, created_by, functional_currency, presentation_currency, is_sample) SELECT 'Company no visitor holds', 'USD', '2026-01-01', '12-31', u.id, 'USD', 'USD', 1 FROM phpledger_demo.pl_users u WHERE u.email = 'stranger.none@example.invalid';" | & docker compose exec -T db_test sh -lc $mysql
    "INSERT INTO phpledger_demo.pl_company_members (company_id, user_id, role_id) SELECT c.id, u.id, r.id FROM phpledger_demo.pl_companies c, phpledger_demo.pl_users u, phpledger_demo.pl_roles r WHERE r.company_id IS NULL AND r.is_system = 1 AND r.slug = 'owner' AND c.name = 'Company no visitor holds' AND u.email = 'stranger.none@example.invalid';" | & docker compose exec -T db_test sh -lc $mysql
    & docker compose @options -e PL_DB_USER=root -e PL_DB_PASSWORD=local-test-root-only -e PL_DEMO_RESET_MODE=1 test php tools/demo-reset.php --now
    if ($LASTEXITCODE -eq 0) { throw 'The reset accepted a person in a company no isolated visitor holds.' }
    "DELETE m FROM phpledger_demo.pl_company_members m JOIN phpledger_demo.pl_users u ON u.id = m.user_id WHERE u.email = 'stranger.none@example.invalid';" | & docker compose exec -T db_test sh -lc $mysql
    "DELETE FROM phpledger_demo.pl_companies WHERE name = 'Company no visitor holds';" | & docker compose exec -T db_test sh -lc $mysql
    "DELETE FROM phpledger_demo.pl_users WHERE email = 'stranger.none@example.invalid';" | & docker compose exec -T db_test sh -lc $mysql
    & docker compose @options -e PL_DB_USER=root -e PL_DB_PASSWORD=local-test-root-only -e PL_DEMO_RESET_MODE=1 test php tools/demo-reset.php --now
    if ($LASTEXITCODE -ne 0) { throw 'Second isolated demo reset failed.' }
    $after = $query | & docker compose exec -T db_test sh -lc 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot --batch --skip-column-names'
    $empty = 'SELECT COUNT(*) FROM phpledger_demo.pl_demo_visitors;' | & docker compose exec -T db_test sh -lc 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot --batch --skip-column-names'
    if ($before -eq $after -or [int] $empty -ne 0) { throw 'Demo reset failed to change generation or remove prior visitors.' }
    Write-Output 'Demo reset verification passed: separate restricted web grants, two refused strangers, real generation replacement and empty visitor state; only phpledger_demo in db_test was reset.'
} finally {
    Pop-Location
}
