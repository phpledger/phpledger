<?php
declare(strict_types=1);
ob_start();
if (PHP_SAPI !== 'cli' || getenv('PL_ENV') !== 'demo' || getenv('PL_DB_HOST') !== 'db_test' || getenv('PL_DB_NAME') !== 'phpledger_demo' || getenv('PL_DB_USER') !== 'ledger_demo_test') {
    throw new RuntimeException('This smoke check requires only the restricted isolated demo account in db_test.');
}
require dirname(__DIR__) . '/www/phpledger/includes/bootstrap.php';
function demo_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function demo_denied(callable $action): void
{
    try {
        $action();
    } catch (DomainException | MeekroDBException) {
        return;
    }
    throw new RuntimeException('An expected demo denial did not occur.');
}
pl_session_start(false);
$pipes = [];
$probe = proc_open([PHP_BINARY, __DIR__ . '/demo_lock_probe.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($probe)) {
    throw new RuntimeException('Could not start the independent demo lock probe.');
}
fclose($pipes[0]);
$probeOutput = stream_get_contents($pipes[1]);
$probeError = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
demo_check(proc_close($probe) === 0 && trim($probeOutput) === 'busy' && $probeError === '', 'Concurrent demo admission bypassed the maintenance lock.');
// A busy bootstrap must still expose the retry response to an approved browser client.
$serverEnvironment = array_replace(getenv(), ['PL_PUBLIC_URL' => 'http://127.0.0.1:18203', 'PL_DEMO_LOCAL_HTTP' => '1', 'PL_ALLOWED_ORIGINS' => 'https://llm.bixisoft.com']);
$httpServer = proc_open([PHP_BINARY, '-S', '127.0.0.1:18203', '-t', PL_APP . '/public', PL_APP . '/public/index.php'], [['file','/dev/null','r'],['file','/dev/null','w'],['file','/dev/null','w']], $unused, null, $serverEnvironment);
demo_check(is_resource($httpServer), 'Could not start isolated busy-response HTTP probe.');
try {
    $ready = false;
    for ($attempt = 0; $attempt < 30; ++$attempt) {
        $socket = @fsockopen('127.0.0.1', 18203, $errno, $reason, 0.1);
        if ($socket) { fclose($socket); $ready = true; break; }
        usleep(100000);
    }
    demo_check($ready, 'Busy-response HTTP probe did not start.');
    $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 8, 'header' => "Origin: https://llm.bixisoft.com\r\n"]]);
    $busy = file_get_contents('http://127.0.0.1:18203/mcp', false, $context);
    $headers = implode("\n", $http_response_header ?? []);
    demo_check(is_string($busy) && str_contains($busy, 'demo_refresh') && str_contains($headers, '503'), 'Busy probe did not receive the expected bounded denial.');
    demo_check(str_contains(strtolower($headers), 'access-control-allow-origin: https://llm.bixisoft.com') && str_contains(strtolower($headers), 'retry-after:'), 'Busy response lost approved CORS or retry headers.');
} finally {
    proc_terminate($httpServer);
    proc_close($httpServer);
}
$_SERVER['REQUEST_METHOD'] = 'POST';
demo_denied(static fn () => pl_demo_begin_visit(str_repeat('0', 64)));
$first = pl_demo_begin_visit(pl_csrf_token());
$f = $first['user']['id'];
$company = pl_company_context($f, $first['company_id']);
demo_check($company['is_sample'] && pl_current_user_id() === $f, 'Visitor did not get its own active sample context.');
putenv('PL_PUBLIC_URL=https://demo.example/demo');
require_once PL_APP . '/includes/functions/oauth_functions.php';
require_once PL_APP . '/includes/functions/client_functions.php';
require_once PL_APP . '/includes/functions/mcp_functions.php';
require_once PL_APP . '/includes/functions/integration_http_functions.php';
$machine = pl_create_personal_token($f, 'Restricted demo smoke', [['company_id' => $first['company_id'], 'book_id' => $first['book_id']]]);
$machineRequest = new Nyholm\Psr7\ServerRequest('GET', pl_connection_issuer() . '/api/v1/companies', ['Authorization' => 'Bearer ' . $machine['token']]);
demo_check(pl_integration_response('/api/v1/companies', $machineRequest)->getStatusCode() === 200, 'Restricted demo runtime cannot read through the machine boundary.');
$store = new PlMcpSessions($machine['connection']['id']);
$sessionId = Symfony\Component\Uid\Uuid::v4();
demo_check($store->write($sessionId, '{}') && $store->exists($sessionId), 'Restricted demo cannot create MCP sessions.');
demo_check($store->destroy($sessionId) && !$store->exists($sessionId), 'Restricted demo session expiry needs forbidden delete privileges.');
$store->gc();
pl_revoke_connection($f, $machine['connection']['id']);
try { pl_connection_authenticate($machineRequest); throw new RuntimeException('Revoked demo machine access remained valid.'); }
catch (UnexpectedValueException) {}
demo_check(pl_demo_begin_visit(pl_csrf_token())['company_id'] === $first['company_id'], 'Repeated start duplicated a visitor.');
demo_denied(static fn () => pl_demo_begin_visit(pl_csrf_token(), 'CAD'));
demo_check(pl_demo_begin_visit(pl_csrf_token(), 'PKR')['company_id'] === $first['company_id'] && pl_company_context($f, $first['company_id'])['currency'] === 'USD', 'An existing sample currency was silently changed.');
$draft = pl_list_documents($f, $first['company_id'], $first['book_id'], ['status' => 'draft'])['documents'][0];
$posted = pl_post_document($f, $first['company_id'], $first['book_id'], $draft['id'], $draft['revision']);
demo_check($posted['status'] === 'posted', 'Demo posting did not work.');
demo_check(pl_reverse_document($f, $first['company_id'], $first['book_id'], $draft['id'], $draft['date'], 'Sample demo correction')['status'] === 'reversed', 'Traceable demo correction did not work.');
demo_denied(static fn () => pl_create_user('blocked@example.invalid', 'Blocked', 'Sample blocked password'));
demo_denied(static fn () => pl_create_company($f, 'Blocked extra company', 'USD', gmdate('Y-m-d')));
demo_denied(static fn () => pl_setup_company($f, [], 'blocked-setup'));
demo_denied(static fn () => pl_confirm_existing_setup($f, $first['company_id'], $first['book_id'], [], true));
demo_denied(static fn () => DB::delete('pl_documents', 'id = %i', $draft['id']));
demo_denied(static fn () => DB::update('pl_periods', ['status' => 'closed'], 'book_id = %i', $first['book_id']));
demo_denied(static fn () => DB::update('pl_documents', ['amount' => '999'], 'id = %i', $draft['id']));
$coreAccounts = array_column($company['accounts'], 'id', 'semantic_key');
$general = pl_save_general_draft($f, $first['company_id'], $first['book_id'], ['date' => gmdate('Y-m-d'), 'reference' => 'DEMO-GENERAL', 'description' => 'Sample capital entry', 'creation_key' => 'demo-general', 'lines' => [
    ['account_id' => $coreAccounts['core.cash_bank'], 'debit' => '25.0001', 'credit' => '0'],
    ['account_id' => $coreAccounts['core.equity.owner'], 'debit' => '0', 'credit' => '25.0001'],
]]);
demo_check(pl_post_general_draft($f, $first['company_id'], $first['book_id'], $general['id'], 1)['status'] === 'posted', 'Demo general posting failed.');
demo_check(pl_reverse_general_draft($f, $first['company_id'], $first['book_id'], $general['id'], gmdate('Y-m-d'), 'Sample general correction')['status'] === 'reversed', 'Demo general linked reversal failed.');
demo_denied(static fn () => pl_save_account($f, $first['company_id'], $first['book_id'], []));
demo_denied(static fn () => DB::delete('pl_general_drafts', 'id = %i', $general['id']));
demo_denied(static fn () => DB::update('pl_general_drafts', ['description' => 'Overwritten'], 'id = %i', $general['id']));
$oldGeneration = $_SESSION['demo_generation'];
$_SESSION['demo_generation'] = str_repeat('0', 64);
demo_check(pl_current_user_id() === null, 'A stale generation retained a reused numeric identity.');
$second = pl_demo_begin_visit(pl_csrf_token(), 'PKR');
demo_check(pl_company_context($second['user']['id'], $second['company_id'])['currency'] === 'PKR', 'Explicit sample currency choice was ignored.');
demo_check($first['company_id'] !== $second['company_id'] && $f !== $second['user']['id'], 'Visitors shared a company or account.');
demo_denied(static fn () => pl_get_document($second['user']['id'], $first['company_id'], $first['book_id'], $draft['id']));
demo_denied(static fn () => pl_get_general_draft($second['user']['id'], $first['company_id'], $first['book_id'], $general['id']));
demo_check(pl_list_documents($second['user']['id'], $second['company_id'], $second['book_id'])['total'] === 6, 'The other visitor inherited edited records.');
putenv('PL_DEMO_MAX_DOCUMENTS=10');
$accounts = array_column($company['accounts'], 'id', 'semantic_key');
$input = ['kind' => 'expense', 'date' => gmdate('Y-m-d'), 'amount' => '1', 'money_account_id' => $accounts['core.cash_bank'], 'category_account_id' => $accounts['core.expense.general'], 'counterparty' => 'Sample capacity test', 'memo' => '', 'reference' => ''];
for ($i = 0; $i < 3; $i++) {
    pl_save_document($f, $first['company_id'], $first['book_id'], $input + ['creation_key' => 'capacity:' . $i]);
}
demo_denied(static fn () => pl_save_document($f, $first['company_id'], $first['book_id'], $input + ['creation_key' => 'capacity:overflow']));
putenv('PL_DEMO_MAX_VISITORS=2');
pl_logout_session();
demo_denied(static fn () => pl_demo_begin_visit(pl_csrf_token()));
demo_check((int) DB::queryFirstField('SELECT COUNT(*) FROM pl_demo_visitors') === 2, 'Visitor capacity was bypassed.');
demo_check(is_string($oldGeneration) && strlen($oldGeneration) === 64, 'A generation marker was not recorded.');
putenv('PL_DEMO_MAX_VISITORS=100');
putenv('PL_DEMO_MAX_DOCUMENTS');
$priorPack = null;
foreach (array_keys(pl_demo_pack_catalog()) as $packId) {
    pl_logout_session();
    $rich = pl_demo_begin_visit(pl_csrf_token(), 'USD', $packId);
    $actor = $rich['user']['id']; $cid = $rich['company_id']; $bid = $rich['book_id'];
    $periods = pl_list_periods($actor, $cid, $bid);
    demo_check(count($periods) === 14 && count(array_filter($periods, static fn (array $p): bool => $p['status'] === 'closed')) === 13, 'Historical periods were not closed during restricted provisioning.');
    demo_check(pl_company_demo_pack($actor, $cid, $bid)['id'] === $packId, 'The selected private pack was not pinned.');
    demo_denied(static fn () => pl_change_period_status($actor, $cid, $bid, (int) $periods[0]['id'], 'closed', 1, 'Forbidden visitor close', 'forbidden-close'));
    demo_denied(static fn () => DB::update('pl_periods', ['status' => 'closed'], 'id = %i', $periods[0]['id']));
    // Even an internal caller cannot reuse provisioning to alter an assigned company.
    demo_denied(static fn () => pl_demo_provisioning(static fn () => pl_change_period_status($actor, $cid, $bid, (int) $periods[0]['id'], 'closed', 1, 'Forbidden provisioning reuse', 'forbidden-provision')));
    demo_denied(static fn () => pl_demo_provisioning(static fn () => pl_create_period($actor, $cid, $bid, ['start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'reason' => 'Forbidden assigned sample action', 'request_key' => 'forbidden-create'])));
    demo_check(count(pl_list_periods($actor, $cid, $bid)) === 14, 'Rejected period action left a partial period.');
    if ($priorPack !== null) { demo_denied(static fn () => pl_company_demo_pack($actor, $priorPack['company_id'], $priorPack['book_id'])); }
    demo_check(pl_demo_document_limit() - pl_demo_document_count($cid, $bid) >= 100, 'The default sample capacity leaves fewer than 100 practice records for ' . $packId . '.');
    $context = pl_company_context($actor, $cid); $codes = pl_account_code_mapping($context['accounts']);
    $capacityInput = ['kind' => 'expense', 'date' => '2026-02-05', 'amount' => '1.0000', 'money_account_id' => $codes['1000'],
        'category_account_id' => $codes['5000'], 'counterparty' => 'Sample visitor practice', 'reference' => '', 'memo' => 'Capacity verification'];
    for ($i = 0; $i < 20; $i++) {
        $practice = pl_save_document($actor, $cid, $bid, $capacityInput + ['creation_key' => 'rich-capacity:' . $i]);
        pl_post_document($actor, $cid, $bid, $practice['id'], $practice['revision']);
    }
    demo_check(pl_trial_balance($actor, $cid, $bid)['balanced'], 'Practice capacity did not preserve balanced journals.');
    $priorPack = $rich;
}
echo "Demo smoke passed: concurrent maintenance-lock denial, CSRF start, private visitor sessions, idempotent start, scoped posting/reversal, cross-visitor denial, setup/admin/delete/period/posted-edit denial, stale generation expiry, document and visitor limits.\n";
echo "Eleven historical packs passed under restricted grants: 396 checkpoints, closed historical periods, assigned-company trigger protection, source isolation and twenty extra posted visitor records per company.\n";
