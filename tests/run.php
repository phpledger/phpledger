<?php
declare(strict_types=1);
ob_start();

if (getenv('PL_ENV') !== 'test' || getenv('PL_DB_NAME') !== 'phpledger_test') {
    fwrite(STDERR, "Tests require PL_ENV=test and the isolated phpledger_test database.\n");
    exit(2);
}
require_once dirname(__DIR__) . '/www/phpledger/includes/bootstrap.php';
require_once dirname(__DIR__) . '/www/phpledger/install/migrate.php';
pl_migrate();

$results = [];
/** Explicit fictional employment for test operational assignments; never imports real identities. */
function sample_assignment_employee(int $actor,int $company,string $name='Sample driver'): int
{
    return pl_save_employee($actor,$company,['full_name'=>$name,'employment_type'=>'full_time','employment_status'=>'active','hire_date'=>'2026-01-01','reason'=>'Explicit fictional employment fixture'])['id'];
}

function test(string $name, callable $action): void
{
    global $results;
    try {
        $action();
        $results[] = ['name' => $name, 'passed' => true];
    } catch (Throwable $error) {
        $results[] = ['name' => $name, 'passed' => false, 'error' => $error->getMessage()];
    }
}
function assert_true(bool $actual, string $message = ''): void
{
    if (!$actual) {
        throw new RuntimeException($message ?: 'Expected condition to be true.');
    }
}
function assert_same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(($message ?: 'Values differ.') . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}
function assert_throws(callable $action, string $class = Throwable::class, ?string $messageContains = null): void
{
    try {
        $action();
    } catch (Throwable $error) {
        if (!$error instanceof $class || ($messageContains !== null && !str_contains($error->getMessage(), $messageContains))) {
            throw new RuntimeException('Unexpected exception: ' . get_class($error) . ': ' . $error->getMessage());
        }
        return;
    }
    throw new RuntimeException('Expected exception was not thrown.');
}

$suites = ['auth_test.php', 'ledger_test.php', 'concurrency_test.php', 'document_test.php', 'regional_test.php', 'report_test.php', 'pos_test.php', 'core_test.php', 'opening_test.php', 'period_test.php', 'reconciliation_test.php', 'core_completion_test.php', 'module_test.php', 'installer_test.php', 'connection_test.php', 'demo_pack_test.php'];
$suites = array_merge($suites, ['currency_test.php', 'party_test.php', 'outbound_test.php', 'open_item_test.php', 'correction_test.php']);
$suites = array_merge($suites, ['ar_ap_test.php','inventory_test.php','inventory_location_test.php','purchasing_test.php','opening_conversion_test.php','tax_test.php','starter_module_test.php','starter_demo_test.php','shell_test.php']);
$suites[] = 'list_test.php';
$suites[] = 'home_test.php';
$suites[] = 'editor_test.php';
$suites[] = 'settlement_test.php';
$suites[] = 'advance_test.php';
$suites[] = 'stock_preview_test.php';
$suites[] = 'ar_preview_test.php';
$suites[] = 'ar_list_test.php';
$suites[] = 'print_test.php';
$suites[] = 'document_series_test.php';
$suites[] = 'trading_documents_test.php';
// Translation groundwork and the pseudo-locale route sweep run last: the sweep starts its own
// HTTP server and the helper tests restore English before any other suite could observe a locale.
$suites[] = 'i18n_test.php';
$suites[] = 'render_allowlist_test.php';
$suites[] = 'account_code_test.php';
$suites[] = 'report_tree_test.php';
$suites[] = 'owner_test.php';
// 1.3 M15: the chart's class and group names, and the guard that no report node falls back to
// "Group 1-100" again. It replays migration 045's statements, so it runs after the suites whose
// own fixtures create books, and it removes the rows it wrote.
$suites[] = 'chart_headings_test.php';
$suites[] = 'stock_document_test.php';
// 1.2 M9: the counter till over real stock, and the distributor's day end to end. They run
// after the stock documents and the trading documents whose fixtures and services they use.
$suites[] = 'counter_pos_test.php';
$suites[] = 'distribution_simulation_test.php';
// Reads the catalogue and the templates, then starts its own server for the rendered bubble.
$suites[] = 'guidance_test.php';
// 1.2 M7: the Users module and the authorisation equivalence proof. They run after the
// module suites because the inert-grant test enables and disables a real module.
$suites[] = 'capability_equivalence_test.php';
$suites[] = 'users_test.php';
// 1.2.1 M10 (B50): starting a business from a sample company's structure. It runs after the
// module and inventory suites because a skeleton turns real modules on, and its last test
// starts its own HTTP server to walk the five wizard stages.
$suites[] = 'onboarding_skeleton_test.php';
// 1.2.1 M8a: the ownership register. After the Users suite, because every write in it is behind
// a capability and the fixture grants them through the same role machinery.
$suites[] = 'ownership_test.php';
$suites[] = 'employee_test.php';
$suites[] = 'payroll_test.php';
// 1.2 M8: the plugin runtime. It runs last because it writes packages into a temporary package
// directory of its own and points PL_PLUGIN_DIRECTORY at it; the last test clears both, so no
// other suite and no child process the sweep starts ever sees a fixture package.
$suites[] = 'plugin_test.php';
$suites[] = 'secret_store_test.php';
$suites[] = 'schedules_test.php';
$suites[] = 'plugin_surface_test.php';
// 1.3 M14: the Fixed assets module.
$suites[] = 'asset_test.php';
$suites[] = 'year_end_test.php';
$suites[] = 'period_close_test.php';
$suites[] = 'cash_count_test.php';
if (($argv[1] ?? '') === '--suite=period-close') {
    $suites = ['ledger_test.php', 'period_test.php', 'period_close_test.php', 'cash_count_test.php'];
}
if (($argv[1] ?? '') === '--suite=ar-lists') {
    $suites = ['ledger_test.php','concurrency_test.php','ar_ap_test.php','ar_list_test.php','reconciliation_test.php','list_test.php','document_series_test.php'];
}
if (($argv[1] ?? '') === '--suite=numbering') {
    $suites = ['ledger_test.php','concurrency_test.php','ar_ap_test.php','document_series_test.php'];
}
if (($argv[1] ?? '') === '--suite=ar-editors') {
    $suites = ['ledger_test.php','concurrency_test.php','ar_ap_test.php','inventory_test.php','purchasing_test.php','tax_test.php','ar_preview_test.php'];
}
if (($argv[1] ?? '') === '--suite=stock-previews') {
    $suites = ['ledger_test.php','concurrency_test.php','ar_ap_test.php','inventory_test.php','purchasing_test.php','stock_preview_test.php'];
}
if (($argv[1] ?? '') === '--suite=settlements') {
    $suites = ['ledger_test.php','concurrency_test.php','open_item_test.php','settlement_test.php','advance_test.php'];
}
if (($argv[1] ?? '') === '--suite=advances') {
    $suites = ['ledger_test.php','concurrency_test.php','ar_ap_test.php','open_item_test.php','settlement_test.php','advance_test.php'];
}
if (($argv[1] ?? '') === '--suite=print') {
    $suites = ['ledger_test.php','concurrency_test.php','open_item_test.php','settlement_test.php','print_test.php'];
}
if (($argv[1] ?? '') === '--suite=trading') {
    $suites = ['ledger_test.php','concurrency_test.php','ar_ap_test.php','inventory_test.php','tax_test.php','open_item_test.php','settlement_test.php','print_test.php','trading_documents_test.php'];
}
if (($argv[1] ?? '') === '--suite=editors') {
    $suites = ['ledger_test.php', 'concurrency_test.php', 'core_test.php', 'editor_test.php'];
}
if (($argv[1] ?? '') === '--suite=pos') {
    $suites = ['ledger_test.php', 'concurrency_test.php', 'document_test.php', 'pos_test.php'];
}
if (($argv[1] ?? '') === '--suite=counter') {
    $suites = ['ledger_test.php','concurrency_test.php','ar_ap_test.php','inventory_test.php','inventory_location_test.php','purchasing_test.php','tax_test.php','open_item_test.php','settlement_test.php','document_series_test.php','trading_documents_test.php','stock_document_test.php','counter_pos_test.php','distribution_simulation_test.php'];
}
if (($argv[1] ?? '') === '--suite=reports') {
    $suites = ['ledger_test.php', 'concurrency_test.php', 'document_test.php', 'report_test.php', 'account_code_test.php', 'report_tree_test.php', 'owner_test.php', 'chart_headings_test.php'];
}
if (($argv[1] ?? '') === '--suite=home') {
    $suites = ['ledger_test.php', 'concurrency_test.php', 'document_test.php', 'reconciliation_test.php', 'home_test.php'];
}
if (($argv[1] ?? '') === '--suite=starter') {
    $suites = ['ledger_test.php','concurrency_test.php','ar_ap_test.php','inventory_test.php','purchasing_test.php','opening_test.php','opening_conversion_test.php','tax_test.php','starter_module_test.php','starter_demo_test.php'];
}
if (($argv[1] ?? '') === '--suite=foundations') {
    $suites = ['ledger_test.php', 'concurrency_test.php', 'document_test.php', 'core_test.php', 'currency_test.php', 'party_test.php', 'outbound_test.php', 'open_item_test.php', 'correction_test.php', 'account_code_test.php', 'owner_test.php'];
}
if (($argv[1] ?? '') === '--suite=installer') {
    $suites = ['installer_test.php'];
}
if (($argv[1] ?? '') === '--suite=connections') {
    $suites = ['ledger_test.php', 'connection_test.php'];
}
if (($argv[1] ?? '') === '--suite=demo-packs') {
    $suites = ['ledger_test.php', 'demo_pack_test.php'];
}
if (($argv[1] ?? '') === '--suite=shell') {
    $suites = ['shell_test.php'];
}
if (($argv[1] ?? '') === '--suite=lists') {
    $suites = ['ledger_test.php', 'concurrency_test.php', 'reconciliation_test.php', 'list_test.php'];
}
if (($argv[1] ?? '') === '--suite=i18n') {
    $suites = ['ledger_test.php', 'i18n_test.php'];
}
if (($argv[1] ?? '') === '--suite=schedules') {
    $suites=['ledger_test.php','concurrency_test.php','core_test.php','document_test.php','pos_test.php','module_test.php','schedules_test.php'];
}
if (($argv[1] ?? '') === '--suite=plugins') {
    $suites = ['ledger_test.php', 'concurrency_test.php', 'core_test.php', 'document_test.php', 'pos_test.php',
        'module_test.php', 'outbound_test.php', 'capability_equivalence_test.php', 'plugin_test.php', 'secret_store_test.php', 'plugin_surface_test.php'];
}
if (($argv[1] ?? '') === '--suite=payroll') { $suites = ['employee_test.php','payroll_test.php']; }
if (($argv[1] ?? '') === '--suite=employees') {
    $suites = ['employee_test.php'];
}
if (($argv[1] ?? '') === '--suite=assets') {
    $suites = ['ledger_test.php', 'concurrency_test.php', 'core_test.php', 'document_test.php', 'pos_test.php', 'module_test.php', 'asset_test.php'];
}
if (($argv[1] ?? '') === '--suite=users') {
    $suites = ['ledger_test.php', 'concurrency_test.php', 'core_test.php', 'document_test.php', 'pos_test.php', 'module_test.php', 'capability_equivalence_test.php', 'users_test.php'];
}
if (($argv[1] ?? '') === '--suite=modules') {
    $suites = ['ledger_test.php', 'concurrency_test.php', 'core_test.php', 'document_test.php', 'ar_ap_test.php', 'inventory_test.php', 'inventory_location_test.php', 'purchasing_test.php', 'pos_test.php', 'module_test.php'];
}
if (($argv[1] ?? '') === '--suite=onboarding') {
    $suites = ['ledger_test.php', 'concurrency_test.php', 'core_test.php', 'document_test.php', 'pos_test.php',
        'module_test.php', 'inventory_test.php', 'purchasing_test.php', 'tax_test.php', 'document_series_test.php',
        'onboarding_skeleton_test.php'];
}
if (($argv[1] ?? '') === '--suite=owner') {
    $suites = ['ledger_test.php', 'concurrency_test.php', 'core_test.php', 'account_code_test.php', 'report_tree_test.php', 'owner_test.php'];
}
if (($argv[1] ?? '') === '--suite=stock-documents') {
    $suites = ['ledger_test.php', 'concurrency_test.php', 'ar_ap_test.php', 'inventory_test.php', 'inventory_location_test.php', 'stock_document_test.php'];
}
if (($argv[1] ?? '') === '--suite=year-end') { $suites = ['ledger_test.php','concurrency_test.php','year_end_test.php']; }
if (($argv[1] ?? '') === '--suite=i18n') {
    $suites = ['i18n_test.php'];
}
foreach ($suites as $suite) {
    if (is_file(__DIR__ . '/' . $suite)) {
        require __DIR__ . '/' . $suite;
    }
}
// Keep all output until session tests are complete; printing earlier would prevent session rotation.
foreach ($results as $result) {
    echo ($result['passed'] ? 'PASS ' : 'FAIL ') . $result['name'];
    if (!$result['passed']) {
        echo ': ' . $result['error'];
    }
    echo "\n";
}
$failed = count(array_filter($results, static fn (array $result): bool => !$result['passed']));
echo count($results) . " tests, {$failed} failures.\n";
exit($failed === 0 && count($results) > 0 ? 0 : 1);
