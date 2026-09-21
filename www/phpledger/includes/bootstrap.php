<?php
declare(strict_types=1);

const PL_ROOT = __DIR__ . '/../../..';
const PL_APP = __DIR__ . '/..';

require_once __DIR__ . '/functions/runtime_functions.php';
pl_require_runtime(PHP_VERSION_ID);
require_once __DIR__ . '/functions/update_functions.php';
pl_update_application_guard(PL_ROOT);
require_once PL_ROOT . '/vendor/autoload.php';
date_default_timezone_set('UTC');

$plEnvironment = getenv('PL_ENV') ?: 'production';
$plConfig = [
    'host' => getenv('PL_DB_HOST') ?: '127.0.0.1',
    'port' => (int) (getenv('PL_DB_PORT') ?: 3306),
    'database' => getenv('PL_DB_NAME') ?: 'phpledger',
    'user' => getenv('PL_DB_USER') ?: 'phpledger',
    'password' => getenv('PL_DB_PASSWORD') ?: '',
];
require_once __DIR__ . '/functions/installation_state_functions.php';
$plLocalConfig = pl_install_config_path();
if (is_file($plLocalConfig)) {
    $plOverride = require $plLocalConfig;
    if (!is_array($plOverride)) {
        throw new RuntimeException('Invalid local configuration.');
    }
    $plConfig = array_replace($plConfig, $plOverride);
}
// Browser installation stores host settings in the existing private config.
// Explicit hosting environment values take precedence for integration settings.
foreach (['public_url' => 'PL_PUBLIC_URL', 'oauth_key_directory' => 'PL_OAUTH_KEY_DIRECTORY'] as $plSetting => $plEnvironmentName) {
    if (!getenv($plEnvironmentName) && isset($plConfig[$plSetting]) && is_string($plConfig[$plSetting])) {
        putenv($plEnvironmentName . '=' . $plConfig[$plSetting]);
    }
}
require_once __DIR__ . '/functions/database_platform_functions.php';
// A database account on this same server may have no password: that is what XAMPP,
// Laragon and MAMP install, and the owner asked for it in issue #84. Credentials for
// a database on another server cross the network, so there one stays required.
if ($plConfig['password'] === '' && !pl_database_local_host((string) $plConfig['host'])) {
    throw new RuntimeException('A database password must be configured for a database on another server.');
}
require_once __DIR__ . '/functions/demo_functions.php';
pl_demo_validate_configuration($plConfig);
DB::$host = $plConfig['host'];
DB::$port = $plConfig['port'];
DB::$dbName = pl_demo_reset_process() ? 'information_schema' : $plConfig['database'];
DB::$user = $plConfig['user'];
DB::$password = $plConfig['password'];
DB::$encoding = 'utf8mb4';
DB::$nested_transactions = true;
pl_database_use_dialect();
// Persist DATETIME/TIMESTAMP events in UTC; business accounting DATE values stay unchanged.
DB::query("SET time_zone = '+00:00'");
pl_demo_acquire_maintenance_lock();

require_once __DIR__ . '/functions/security_functions.php';
require_once __DIR__ . '/functions/i18n_functions.php';
require_once __DIR__ . '/functions/auth_functions.php';
require_once __DIR__ . '/functions/currency_functions.php';
require_once __DIR__ . '/functions/account_code_functions.php';
require_once __DIR__ . '/functions/ledger_functions.php';
require_once __DIR__ . '/functions/setup_functions.php';
require_once __DIR__ . '/functions/document_functions.php';
require_once __DIR__ . '/functions/core_functions.php';
require_once __DIR__ . '/functions/regional_functions.php';
require_once __DIR__ . '/functions/report_functions.php';
require_once __DIR__ . '/functions/owner_functions.php';
require_once __DIR__ . '/functions/export_functions.php';
require_once __DIR__ . '/functions/pos_functions.php';
require_once __DIR__ . '/functions/opening_functions.php';
require_once __DIR__ . '/functions/period_functions.php';
require_once __DIR__ . '/functions/reconciliation_functions.php';
require_once __DIR__ . '/functions/module_functions.php';
require_once __DIR__ . '/functions/connection_functions.php';
require_once __DIR__ . '/functions/read_functions.php';
require_once __DIR__ . '/functions/demo_pack_functions.php';
require_once __DIR__ . '/functions/outbound_functions.php';
require_once __DIR__ . '/functions/party_functions.php';
require_once __DIR__ . '/functions/open_item_functions.php';
require_once __DIR__ . '/functions/settlement_functions.php';
require_once __DIR__ . '/functions/correction_functions.php';
require_once __DIR__ . '/functions/module_visibility_functions.php';
require_once __DIR__ . '/functions/inventory_functions.php';
require_once __DIR__ . '/functions/tax_functions.php';
require_once __DIR__ . '/functions/document_series_functions.php';
require_once __DIR__ . '/functions/trading_functions.php';
require_once __DIR__ . '/functions/stock_document_functions.php';
require_once __DIR__ . '/functions/ar_ap_functions.php';
require_once __DIR__ . '/functions/purchasing_functions.php';
require_once __DIR__ . '/functions/opening_conversion_functions.php';
require_once __DIR__ . '/functions/branding_functions.php';
