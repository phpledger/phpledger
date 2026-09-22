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
// 1.2 M8: the hook mechanism is loaded before the services that raise hooks, because
// ledger_functions.php marks the book lock and queues the after-commit actions through it.
require_once __DIR__ . '/functions/hook_functions.php';
require_once __DIR__ . '/functions/currency_functions.php';
require_once __DIR__ . '/functions/account_code_functions.php';
require_once __DIR__ . '/functions/ledger_functions.php';
require_once __DIR__ . '/functions/setup_functions.php';
require_once __DIR__ . '/functions/document_functions.php';
require_once __DIR__ . '/functions/core_functions.php';
require_once __DIR__ . '/functions/regional_functions.php';
require_once __DIR__ . '/functions/guidance_functions.php';
require_once __DIR__ . '/functions/report_functions.php';
require_once __DIR__ . '/functions/owner_functions.php';
require_once __DIR__ . '/functions/export_functions.php';
require_once __DIR__ . '/functions/pos_functions.php';
require_once __DIR__ . '/functions/opening_functions.php';
require_once __DIR__ . '/functions/period_functions.php';
require_once __DIR__ . '/functions/period_close_functions.php';
require_once __DIR__ . '/functions/year_end_functions.php';
require_once __DIR__ . '/functions/cash_count_functions.php';
require_once __DIR__ . '/functions/reconciliation_functions.php';
require_once __DIR__ . '/functions/module_functions.php';
require_once __DIR__ . '/functions/capability_functions.php';
require_once __DIR__ . '/functions/user_functions.php';
// The first MeekroORM records (1.2 M7). They are used on administration screens only;
// see PL_Model's class comment for the per-request metadata cost that decides that.
require_once __DIR__ . '/models/PL_Model.php';
require_once __DIR__ . '/models/PL_User.php';
require_once __DIR__ . '/models/PL_Role.php';
require_once __DIR__ . '/models/PL_Capability.php';
require_once __DIR__ . '/functions/connection_functions.php';
require_once __DIR__ . '/functions/read_functions.php';
require_once __DIR__ . '/functions/demo_pack_functions.php';
require_once __DIR__ . '/functions/outbound_functions.php';
require_once __DIR__ . '/functions/party_functions.php';
require_once __DIR__ . '/functions/open_item_functions.php';
require_once __DIR__ . '/functions/settlement_functions.php';
require_once __DIR__ . '/functions/advance_functions.php';
require_once __DIR__ . '/functions/correction_functions.php';
require_once __DIR__ . '/functions/module_visibility_functions.php';
require_once __DIR__ . '/functions/inventory_functions.php';
require_once __DIR__ . '/functions/tax_functions.php';
require_once __DIR__ . '/functions/document_series_functions.php';
require_once __DIR__ . '/functions/trading_functions.php';
require_once __DIR__ . '/functions/stock_document_functions.php';
require_once __DIR__ . '/functions/asset_functions.php';
require_once __DIR__ . '/functions/ar_ap_functions.php';
// 1.2 M9: the money side of a driver's day and the counter till. Both compose the AR
// document services above, so they are loaded after them.
require_once __DIR__ . '/functions/distribution_functions.php';
require_once __DIR__ . '/functions/counter_pos_functions.php';
require_once __DIR__ . '/functions/purchasing_functions.php';
require_once __DIR__ . '/functions/opening_conversion_functions.php';
// 1.2.1 M10 (B50): a sample company's structure, read separately from its history, so a new
// business can start from it. Loaded after the services it composes.
require_once __DIR__ . '/functions/sample_structure_functions.php';
require_once __DIR__ . '/functions/branding_functions.php';
// 1.3 M17: the employee master (B70). Loaded before the ownership register because
// ownership_functions.php's pl_related_party_subject() resolves a marker naming the employee
// register and calls into this file to do it; load order does not matter to PHP itself since
// both are pure function declarations, but this keeps the dependency reading top to bottom.
require_once __DIR__ . '/functions/employee_functions.php';
require_once __DIR__ . '/functions/employment_link_functions.php';
require_once __DIR__ . '/functions/account_payment_functions.php';
require_once __DIR__ . '/functions/payroll_functions.php';
// 1.2.1 M8a: the ownership register. It loads before the plugin runtime because a package may
// register a listener on its hook points while it boots, so pl_ownership_on() has to exist by
// then; and because a country company-secretarial package is exactly the caller B63 designed
// those hook points for.
require_once __DIR__ . '/functions/ownership_functions.php';
// 1.3 M17: the secret store (B83). Loaded before plugin_functions.php, whose
// pl_plugin_secret_* wrappers call into it; update_functions.php (required at the very top,
// before the database connects) already gives it pl_update_directory() and pl_update_write().
require_once __DIR__ . '/functions/secret_functions.php';
require_once __DIR__ . '/functions/scheduler_functions.php';
require_once __DIR__ . '/functions/recurring_functions.php';
require_once __DIR__ . '/functions/account_payment_functions.php';
require_once __DIR__ . '/functions/loan_functions.php';
// 1.2 M8: the plugin runtime loads last, so every core service a package may call already
// exists, and so a package can never shadow one. pl_plugin_boot() costs one is_dir() and one
// scandir() on an installation with no packages, which is every installation until one is
// installed; it touches the database only when the package directory holds something.
require_once __DIR__ . '/functions/plugin_functions.php';
pl_plugin_boot();
