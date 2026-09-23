<?php
declare(strict_types=1);
// Run only against the disposable db_test service, with a fixture CA mounted by the caller.
if (getenv('PL_ENV') !== 'test' || getenv('PL_DB_HOST') !== 'db_test' || !is_file($argv[1] ?? '')) {
    fwrite(STDERR, "Use the isolated test service and provide its fixture CA path.\n"); exit(2);
}
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/www/phpledger/includes/functions/database_platform_functions.php';
require dirname(__DIR__) . '/www/phpledger/includes/functions/database_functions.php';
$config = ['host'=>'db_test','port'=>3306,'database'=>'phpledger_test','user'=>'ledger_test','password'=>'local-test-only','db_ssl_ca'=>$argv[1],'db_ssl_verify'=>true];
pl_database_configure($config);
$status = DB::queryFirstRow("SHOW SESSION STATUS LIKE 'Ssl_cipher'");
if (($status['Value'] ?? '') === '') { throw new RuntimeException('TLS cipher missing'); }
echo "PASS verified CA and hostname establish encrypted MeekroDB connection\n";
$refused = false;
try { pl_database_configure(array_replace($config, ['host'=>gethostbyname('db_test')])); } catch (Throwable $error) { $refused = true; }
if (!$refused) { throw new RuntimeException('Mismatched server identity accepted'); }
echo "PASS certificate hostname mismatch is refused\n";
pl_database_configure($config);
echo "PASS failed TLS attempt does not prevent a fresh verified connection\n";
