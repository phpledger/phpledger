<?php
declare(strict_types=1);

require_once PL_APP . '/includes/functions/oauth_functions.php';
require_once PL_APP . '/includes/functions/client_functions.php';
require_once PL_APP . '/includes/functions/mcp_functions.php';
require_once PL_APP . '/includes/functions/integration_http_functions.php';
require_once PL_APP . '/includes/functions/web_functions.php';
require_once PL_APP . '/includes/functions/table_web_functions.php';
putenv('PL_PUBLIC_URL=http://127.0.0.1:18200');
putenv('PL_ALLOWED_ORIGINS=https://llm.bixisoft.com');

function connection_fixture(): array
{
    $f = ledger_fixture();
    $issued = pl_create_personal_token($f['actor_id'], 'Sample private agent', [['company_id' => $f['company_id'], 'book_id' => $f['book_id']]]);
    return $f + $issued;
}

function connection_request(string $path, string $method = 'GET', ?string $token = null, array $query = [], string $body = ''): Psr\Http\Message\ServerRequestInterface
{
    $headers = ['Accept' => 'application/json, text/event-stream', 'Content-Type' => 'application/json'];
    if ($token !== null) { $headers['Authorization'] = 'Bearer ' . $token; }
    return (new Nyholm\Psr7\ServerRequest($method, pl_connection_issuer() . $path, $headers, $body))->withQueryParams($query);
}

function connection_keys(): void
{
    static $ready = false;
    if ($ready) { return; }
    $directory = sys_get_temp_dir() . '/pl-oauth-test-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    putenv('PL_OAUTH_KEY_DIRECTORY=' . $directory);
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $private);
    file_put_contents($directory . '/private.key', $private);
    file_put_contents($directory . '/public.key', openssl_pkey_get_details($key)['key']);
    file_put_contents($directory . '/encryption.key', bin2hex(random_bytes(32)));
    foreach (['private.key','public.key','encryption.key'] as $file) { chmod($directory . '/' . $file, 0600); }
    register_shutdown_function(static function () use ($directory): void {
        foreach (['private.key','public.key','encryption.key'] as $file) { unlink($directory . '/' . $file); }
        rmdir($directory);
    });
    $ready = true;
}

function connection_oauth_code(array $f): array
{
    connection_keys();
    $client = pl_client_registration(['client_name' => 'Sample OAuth client', 'redirect_uris' => ['http://127.0.0.1:19999/callback']]);
    $verifier = bin2hex(random_bytes(32));
    $query = ['client_id' => $client['client_id'], 'redirect_uri' => $client['redirect_uris'][0], 'response_type' => 'code', 'scope' => 'ledger.read offline_access', 'state' => bin2hex(random_bytes(16)), 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256', 'resource' => pl_connection_resource()];
    $authorization = pl_oauth_authorize_validate($query);
    $connection = pl_create_connection($f['actor_id'], $client['client_id'], 'Sample OAuth grant', [['company_id' => $f['company_id'], 'book_id' => $f['book_id']]], 'oauth', 'ledger.read offline_access');
    $repo = new PlOAuthRepositories();
    $repo->connectionId = $connection['id'];
    $authorization->setUser(new PlOAuthUser($f['actor_id']));
    $authorization->setAuthorizationApproved(true);
    $response = pl_ledger_transaction(fn () => pl_oauth_server($repo)->completeAuthorizationRequest($authorization, new Nyholm\Psr7\Response()));
    parse_str(parse_url($response->getHeaderLine('Location'), PHP_URL_QUERY), $result);
    assert_true(isset($result['code']), 'OAuth did not issue an authorization code.');
    return ['connection' => $connection, 'code' => $result['code'], 'verifier' => $verifier, 'client_id' => $client['client_id'], 'redirect_uri' => $client['redirect_uris'][0], 'query' => $query];
}

function connection_exchange(array $code, array $replace = []): array
{
    $body = array_replace(['grant_type' => 'authorization_code', 'client_id' => $code['client_id'], 'redirect_uri' => $code['redirect_uri'], 'code' => $code['code'], 'code_verifier' => $code['verifier'], 'resource' => pl_connection_resource()], $replace);
    return json_decode((string) pl_oauth_token_response(connection_request('/oauth/token', 'POST')->withParsedBody($body))->getBody(), true, 16, JSON_THROW_ON_ERROR);
}

test('personal credentials are hashed scoped revocable and independent of browser sessions', function (): void {
    $f = connection_fixture();
    assert_true(str_starts_with($f['token'], 'plp_'));
    $stored = DB::queryFirstRow('SELECT * FROM pl_connection_tokens WHERE connection_id = %s', $f['connection']['id']);
    assert_true($stored['token_hash'] === hash('sha256', $f['token']) && !str_contains(json_encode($stored), $f['token']), 'Raw credential was stored.');
    $authenticated = pl_connection_authenticate(connection_request('/mcp', 'POST', $f['token']));
    assert_same($f['actor_id'], $authenticated['actor_id']);
    $companies = pl_read_operation($f['connection']['id'], 'companies', [])['data']['rows'];
    assert_same(1, count($companies));
    assert_same($f['company_id'], $companies[0]['company_id']);
    assert_same('USD', $companies[0]['currency']);
    $other = ledger_fixture();
    assert_throws(fn () => pl_read_operation($f['connection']['id'], 'trial_balance', ['company_id' => $other['company_id'], 'book_id' => $other['book_id'], 'as_of' => '2026-09-15']), DomainException::class);
    assert_throws(fn () => pl_create_personal_token($f['actor_id'], 'Unauthorized', [['company_id' => $other['company_id'], 'book_id' => $other['book_id']]]), DomainException::class);
    assert_throws(fn () => pl_revoke_connection($other['actor_id'], $f['connection']['id']), DomainException::class);
    pl_revoke_connection($f['actor_id'], $f['connection']['id']);
    assert_throws(fn () => pl_connection_authenticate(connection_request('/mcp', 'POST', $f['token'])), UnexpectedValueException::class);
});

test('report-only personal and OAuth grants enforce the same API and MCP operation boundary', function (): void {
    $f = connection_fixture(); $scope = ['company_id'=>$f['company_id'], 'book_id'=>$f['book_id']];
    $limited = pl_create_personal_token($f['actor_id'], 'Summary reports', [$scope], 'reports');
    $oauth = connection_oauth_code($f);
    DB::update('pl_connections', ['access_mode'=>'reports'], 'id=%s', $oauth['connection']['id']);
    $tokens = connection_exchange($oauth);
    foreach ([$limited['token'], $tokens['access_token']] as $token) {
        $response = pl_integration_response('/api/v1/trial-balance', connection_request('/api/v1/trial-balance', 'GET', $token, $scope + ['as_of'=>'2026-09-17']));
        assert_same(200, $response->getStatusCode());
        foreach (['accounts','transactions','general-journals'] as $operation) {
            assert_throws(fn()=>pl_integration_response('/api/v1/'.$operation, connection_request('/api/v1/'.$operation,'GET',$token,$scope)), DomainException::class, 'summary reports only');
        }
        $meta = ['io.modelcontextprotocol/protocolVersion'=>'2026-07-28','io.modelcontextprotocol/clientCapabilities'=>new stdClass(),'io.modelcontextprotocol/clientInfo'=>['name'=>'Sample scope verifier','version'=>'1']];
        $message = ['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/call','params'=>['_meta'=>$meta,'name'=>'ledger_accounts','arguments'=>$scope]];
        $request = connection_request('/mcp','POST',$token,[],json_encode($message))->withHeader('MCP-Protocol-Version','2026-07-28')->withHeader('Mcp-Method','tools/call')->withHeader('Mcp-Name','ledger_accounts');
        $body = json_decode((string)pl_integration_response('/mcp',$request)->getBody(),true);
        assert_true(isset($body['error']) || ($body['result']['isError'] ?? false), 'MCP accepted a record read for report-only access.');
    }
    $capabilities = pl_read_operation($limited['connection']['id'],'capabilities',$scope)['data']['read_operations'];
    assert_true(!in_array('accounts',$capabilities,true) && in_array('profit_loss',$capabilities,true));
    // A cached full-access connection object cannot bypass the current database grant.
    DB::update('pl_connections',['access_mode'=>'reports'],'id=%s',$f['connection']['id']);
    assert_throws(fn()=>pl_read_operation($f['connection']['id'],'accounts',$scope),DomainException::class);
    assert_throws(fn()=>pl_create_personal_token($f['actor_id'],'Invalid',[$scope],'write'),DomainException::class);
});

test('every advertised business operation returns an authorized source or report', function (): void {
    $f = connection_fixture();
    $scope = ['company_id' => $f['company_id'], 'book_id' => $f['book_id']];
    $draft = pl_save_general_draft($f['actor_id'], $f['company_id'], $f['book_id'], ['date' => '2026-09-14', 'reference' => 'CATALOG-SOURCE', 'description' => 'Sample catalog coverage', 'creation_key' => bin2hex(random_bytes(16)), 'lines' => [['account_id' => $f['accounts']['1000'], 'debit' => '12.3400', 'credit' => '0'], ['account_id' => $f['accounts']['4000'], 'debit' => '0', 'credit' => '12.3400']]]);
    $posted = pl_post_general_draft($f['actor_id'], $f['company_id'], $f['book_id'], $draft['id'], $draft['revision']);
    $year=pl_year_end_create($f['actor_id'],$f['company_id'],$f['book_id'],'2026-12-31',['treatment'=>'company','legal_basis'=>'Fictional company','review_note'=>'API fixture','destination_account_id'=>$f['accounts']['3000']],'catalog-year');
    foreach (pl_read_catalog() as $operation => $definition) {
        $args = $operation === 'companies' ? [] : $scope;
        foreach (['as_of' => '2026-09-15', 'from' => '2026-01-01', 'to' => '2026-09-15', 'account_id' => $f['accounts']['1000'], 'journal_id' => $posted['journal_id'], 'source_id' => $posted['id'], 'source_type' => 'general_journal', 'year_id'=>$year['year_id']] as $name => $value) {
            if (in_array($name, $definition['schema']['required'], true)) { $args[$name] = $value; }
        }
        $response = pl_integration_response('/api/v1/' . str_replace('_', '-', $operation), connection_request('/api/v1/' . str_replace('_', '-', $operation), 'GET', $f['token'], $args));
        assert_same(200, $response->getStatusCode(), $operation);
        $data = json_decode((string) $response->getBody(), true, 64, JSON_THROW_ON_ERROR);
        assert_true(isset($data['data'], $data['access_expires_at']), $operation . ' did not return its envelope.');
    }
});

test('machine reads match browser money and continue exact running balances across bounded pages', function (): void {
    $f = connection_fixture();
    for ($i = 0; $i < 32; ++$i) { pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], ledger_payload($f, '0.0001')); }
    $scope = ['company_id' => $f['company_id'], 'book_id' => $f['book_id']];
    // A book's chart is thirty-two rows now — thirteen postable accounts and the chart's nineteen
    // class and group headings — so the whole report needs more than one default page. The machine
    // read stays the flat, paginated report it was: the headings are rows in it like any other,
    // and they carry zero, so a consumer that sums a column is unaffected by them.
    $trial = pl_read_operation($f['connection']['id'], 'trial_balance', $scope + ['as_of' => '2026-09-15', 'page_size' => 100]);
    $browser = pl_trial_balance($f['actor_id'], $f['company_id'], $f['book_id'], '2026-09-15');
    assert_same($browser['accounts'], $trial['data']['accounts']['rows']);
    assert_same(count($browser['accounts']), $trial['data']['accounts']['pagination']['total']);
    assert_same(2, pl_read_operation($f['connection']['id'], 'trial_balance', $scope + ['as_of' => '2026-09-15'])['data']['accounts']['pagination']['pages']);
    assert_true(!array_key_exists('tree', $trial['data']));
    assert_same($browser['total_debit'], $trial['data']['total_debit']);
    foreach (['profit_loss','balance_sheet'] as $operation) {
        $args = $operation === 'profit_loss' ? ['from' => '2026-01-01', 'to' => '2026-09-15'] : ['as_of' => '2026-09-15'];
        $read = pl_read_operation($f['connection']['id'], $operation, $scope + $args)['data'];
        $expected = $operation === 'profit_loss' ? pl_profit_loss($f['actor_id'], $f['company_id'], $f['book_id'], $args['from'], $args['to']) : pl_balance_sheet($f['actor_id'], $f['company_id'], $f['book_id'], $args['as_of']);
        // The collapsible tree and the equity-movement summary are browser presentations of the
        // same rows (issue #77); the machine read stays the flat, paginated report.
        foreach (['tree', 'trees', 'equity_movements'] as $presentation) { assert_true(!array_key_exists($presentation, $read)); }
        foreach (array_diff_key($expected, array_flip(['tree', 'trees', 'equity_movements'])) as $key => $value) { assert_same($value, is_array($value) ? $read[$key]['rows'] : $read[$key]); }
    }
    $statement = $scope + ['account_id' => $f['accounts']['1000'], 'from' => '2026-01-01', 'as_of' => '2026-09-15'];
    $first = pl_read_operation($f['connection']['id'], 'account_statement', $statement)['data'];
    $second = pl_read_operation($f['connection']['id'], 'account_statement', $statement + ['page' => 2])['data'];
    assert_same(25, count($first['movements']));
    assert_same(7, count($second['movements']));
    assert_same('0.0025', $first['page_closing_balance']);
    assert_same('0.0025', $second['page_opening_balance']);
    assert_same('0.0032', $second['closing_balance']);
    $journal = pl_read_operation($f['connection']['id'], 'journal', $scope + ['journal_id' => $first['movements'][0]['journal_id']]);
    assert_same('sample-receipt', $journal['data']['source_reference']);
    assert_true(!str_contains(json_encode($journal), 'idempotency_key'));
    assert_throws(fn () => pl_read_operation($f['connection']['id'], 'execute_sql', $scope), DomainException::class);
    assert_throws(fn () => pl_read_operation($f['connection']['id'], 'trial_balance', $scope + ['as_of' => '2026-02-30']), DomainException::class);
    assert_throws(fn () => pl_read_operation($f['connection']['id'], 'accounts', $scope + ['page_size' => 100000]), DomainException::class);
});

test('membership expiry client revocation and generation binding deny previously valid credentials', function (): void {
    $f = connection_fixture();
    DB::update('pl_company_members', ['role_id' => DB::queryFirstField("SELECT id FROM pl_roles WHERE company_id IS NULL AND slug = 'viewer'")], 'company_id = %i AND user_id = %i', $f['company_id'], $f['actor_id']);
    assert_same($f['actor_id'], pl_connection_authenticate(connection_request('/mcp', 'POST', $f['token']))['actor_id']);
    DB::update('pl_users', ['is_active' => 0], 'id = %i', $f['actor_id']);
    assert_throws(fn () => pl_connection_authenticate(connection_request('/mcp', 'POST', $f['token'])), UnexpectedValueException::class);
    DB::update('pl_users', ['is_active' => 1], 'id = %i', $f['actor_id']);
    DB::update('pl_connections', ['demo_generation' => str_repeat('f', 64)], 'id = %s', $f['connection']['id']);
    assert_throws(fn () => pl_connection_require($f['connection']['id']), UnexpectedValueException::class);
    DB::update('pl_connections', ['demo_generation' => null, 'expires_at' => gmdate('Y-m-d H:i:s', time() - 1)], 'id = %s', $f['connection']['id']);
    assert_throws(fn () => pl_connection_require($f['connection']['id']), UnexpectedValueException::class);
});

test('OAuth S256 issues audience-bound credentials rotates refresh and rejects replay and bad verifier', function (): void {
    $f = ledger_fixture();
    $code = connection_oauth_code($f);
    assert_throws(fn () => connection_exchange($code, ['code_verifier' => str_repeat('x', 64)]), League\OAuth2\Server\Exception\OAuthServerException::class);
    assert_throws(fn () => connection_exchange($code, ['resource' => 'https://unrelated.example/mcp']), League\OAuth2\Server\Exception\OAuthServerException::class);
    $tokens = connection_exchange($code);
    assert_true(isset($tokens['access_token'], $tokens['refresh_token']));
    assert_true($tokens['expires_in'] > 0 && $tokens['expires_in'] <= 900);
    $authenticated = pl_connection_authenticate(connection_request('/mcp', 'POST', $tokens['access_token']));
    assert_same($f['actor_id'], $authenticated['actor_id']);
    $refreshBody = ['grant_type' => 'refresh_token', 'client_id' => $code['client_id'], 'refresh_token' => $tokens['refresh_token'], 'resource' => pl_connection_resource()];
    $renewed = json_decode((string) pl_oauth_token_response(connection_request('/oauth/token', 'POST')->withParsedBody($refreshBody))->getBody(), true);
    assert_true($renewed['refresh_token'] !== $tokens['refresh_token']);
    assert_throws(fn () => pl_connection_authenticate(connection_request('/mcp', 'POST', $tokens['access_token'])), UnexpectedValueException::class);
    assert_same($f['actor_id'], pl_connection_authenticate(connection_request('/mcp', 'POST', $renewed['access_token']))['actor_id']);
    assert_throws(fn () => pl_oauth_token_response(connection_request('/oauth/token', 'POST')->withParsedBody($refreshBody)), League\OAuth2\Server\Exception\OAuthServerException::class);
    assert_throws(fn () => pl_connection_authenticate(connection_request('/mcp', 'POST', $renewed['access_token'])), UnexpectedValueException::class);
    $next = connection_oauth_code($f);
    $issued = connection_exchange($next);
    assert_throws(fn () => connection_exchange($next), League\OAuth2\Server\Exception\OAuthServerException::class);
    assert_throws(fn () => pl_connection_authenticate(connection_request('/mcp', 'POST', $issued['access_token'])), UnexpectedValueException::class);
    assert_throws(fn () => pl_oauth_authorize_validate(array_replace($next['query'], ['code_challenge_method' => 'plain'])), DomainException::class);
    assert_throws(fn () => pl_oauth_authorize_validate(array_replace($next['query'], ['redirect_uri' => $next['redirect_uri'] . '?changed=1'])), DomainException::class);
});

test('metadata rejects private addresses and CORS allows only explicit origins and headers', function (): void {
    foreach (['127.0.0.1','10.1.2.3','172.16.0.1','192.168.1.1','169.254.169.254','100.64.0.1','::1','::ffff:127.0.0.1','fc00::1','2001:db8::1'] as $ip) { assert_same(false, pl_client_public_ip($ip), 'Unsafe metadata address: ' . $ip); }
    foreach (['http://example.com/client.json','https://127.0.0.1/client.json','https://example.com:8443/client.json','https://user@example.com/client.json'] as $url) { assert_throws(fn () => pl_client_metadata($url), DomainException::class); }
    $request = connection_request('/mcp', 'OPTIONS')->withHeader('Origin', 'https://llm.bixisoft.com')->withHeader('Access-Control-Request-Method', 'POST')->withHeader('Access-Control-Request-Headers', 'authorization,mcp-session-id,mcp-protocol-version,content-type');
    assert_same(204, pl_integration_response('/mcp', $request)->getStatusCode());
    assert_same(403, pl_integration_response('/mcp', $request->withHeader('Origin', 'https://unapproved.example'))->getStatusCode());
    assert_same(403, pl_integration_response('/mcp', $request->withHeader('Access-Control-Request-Headers', 'x-admin-token'))->getStatusCode());
    assert_same(403, pl_integration_response('/mcp', $request->withHeader('Host', 'attacker.example'))->getStatusCode());
    assert_same(405, pl_integration_response('/api/v1/trial-balance', connection_request('/api/v1/trial-balance', 'POST'))->getStatusCode());
});

test('MCP handshake discovers read tools and session IDs cannot cross connections', function (): void {
    $f = connection_fixture();
    $body = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => new stdClass(), 'clientInfo' => ['name' => 'PHP Ledger sample protocol test', 'version' => '1']]]);
    $response = pl_integration_response('/mcp', connection_request('/mcp', 'POST', $f['token'], [], $body));
    assert_same(200, $response->getStatusCode(), 'Handshake initialize: ' . substr((string) $response->getBody(), 0, 1200));
    $session = $response->getHeaderLine('Mcp-Session-Id');
    assert_true($session !== '', 'MCP initialize did not create a session.');
    $request = connection_request('/mcp', 'POST', $f['token'], [], json_encode(['jsonrpc' => '2.0','method' => 'notifications/initialized']))->withHeader('Mcp-Session-Id', $session)->withHeader('MCP-Protocol-Version','2025-11-25');
    assert_same(202, pl_integration_response('/mcp', $request)->getStatusCode());
    $response = pl_integration_response('/mcp', $request->withBody(Nyholm\Psr7\Stream::create(json_encode(['jsonrpc' => '2.0','id' => 2,'method' => 'tools/list']))));
    assert_same(200, $response->getStatusCode());
    assert_true(str_contains((string) $response->getBody(), 'ledger_trial_balance'), 'MCP did not discover tools.');
    $other = connection_fixture();
    assert_same(false, (new PlMcpSessions($other['connection']['id']))->exists(Symfony\Component\Uid\Uuid::fromString($session)));
    $foreign = pl_integration_response('/mcp', $request->withHeader('Authorization', 'Bearer ' . $other['token']));
    assert_true($foreign->getStatusCode() >= 400, 'A second connection reused the first session.');
});

test('MCP 2026 per-request discovery and tool reads agree with API and reject mixed versions', function (): void {
    $f = connection_fixture();
    pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], ledger_payload($f, '123.4567'));
    $meta = ['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => new stdClass(), 'io.modelcontextprotocol/clientInfo' => ['name' => 'Sample modern MCP test', 'version' => '1']];
    $message = ['jsonrpc' => '2.0', 'id' => 10, 'method' => 'server/discover', 'params' => ['_meta' => $meta]];
    $request = connection_request('/mcp', 'POST', $f['token'], [], json_encode($message))->withHeader('MCP-Protocol-Version', '2026-07-28')->withHeader('Mcp-Method', 'server/discover');
    $response = pl_integration_response('/mcp', $request);
    assert_same(200, $response->getStatusCode(), 'Modern discover: ' . substr((string) $response->getBody(), 0, 1200));
    assert_true(str_contains((string) $response->getBody(), 'PHP Ledger'));
    assert_same('', $response->getHeaderLine('Mcp-Session-Id'));
    $scope = ['company_id' => $f['company_id'], 'book_id' => $f['book_id'], 'as_of' => '2026-09-15'];
    $message['method'] = 'tools/call';
    $message['params'] = ['_meta' => $meta, 'name' => 'ledger_trial_balance', 'arguments' => $scope];
    $request = $request->withBody(Nyholm\Psr7\Stream::create(json_encode($message)))->withHeader('Mcp-Method', 'tools/call')->withHeader('Mcp-Name', 'ledger_trial_balance');
    $response = pl_integration_response('/mcp', $request);
    assert_same(200, $response->getStatusCode(), 'Modern tool read: ' . substr((string) $response->getBody(), 0, 1200));
    $body = json_decode((string) $response->getBody(), true);
    assert_true(isset($body['result']['structuredContent']), 'Modern MCP did not return structured tool results.');
    $api = json_decode((string) pl_integration_response('/api/v1/trial-balance', connection_request('/api/v1/trial-balance', 'GET', $f['token'], $scope))->getBody(), true);
    assert_same($api, $body['result']['structuredContent']);
    assert_same($api, json_decode($body['result']['content'][0]['text'], true));
    $mismatch = pl_integration_response('/mcp', $request->withHeader('MCP-Protocol-Version', '2025-11-25'));
    assert_true($mismatch->getStatusCode() >= 400, 'Mixed protocol headers were accepted.');
});

test('table filters never redefine balances and unsafe sorting and paging are rejected', function (): void {
    $f = ledger_fixture();
    for ($i = 0; $i < 31; ++$i) {
        $payload = ledger_payload($f, '1.0001');
        $payload['description'] = $i % 2 === 0 ? 'Visible movement' : 'Other movement';
        pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], $payload);
    }
    $ordinary = pl_account_activity($f['actor_id'], $f['company_id'], $f['book_id'], $f['accounts']['1000'], '2026-09-15');
    $filtered = pl_account_activity($f['actor_id'], $f['company_id'], $f['book_id'], $f['accounts']['1000'], '2026-09-15', 1, null, ['page_size' => 25, 'search' => 'Visible movement', 'sort' => 'balance', 'direction' => 'desc']);
    assert_same(31, $filtered['total']);
    assert_same(16, $filtered['filtered_total']);
    assert_same($ordinary['closing_balance'], $filtered['closing_balance']);
    $balances = array_column($ordinary['movements'], 'running_balance', 'journal_id');
    foreach ($filtered['movements'] as $row) { assert_same($balances[$row['journal_id']], $row['running_balance']); }
    assert_same('31.0031', $filtered['movements'][0]['running_balance']);
    foreach ([['length' => '-1'], ['length' => '10000'], ['start' => '1'], ['column' => '999'], ['direction' => 'DESC; DELETE FROM pl_users'], ['search' => str_repeat('x', 161)]] as $bad) { assert_throws(fn () => pl_table_request_options($bad, 'account'), DomainException::class); }
    assert_throws(fn () => pl_account_activity($f['actor_id'], $f['company_id'], $f['book_id'], $f['accounts']['1000'], null, 1, null, ['sort' => 'untrusted SQL', 'direction' => 'asc']), DomainException::class);
});

test('OAuth standard revocation binds the client and refresh scope can only narrow', function (): void {
    $f = ledger_fixture();
    $code = connection_oauth_code($f);
    $tokens = connection_exchange($code);
    $narrow = ['grant_type' => 'refresh_token', 'client_id' => $code['client_id'], 'refresh_token' => $tokens['refresh_token'], 'resource' => pl_connection_resource(), 'scope' => 'ledger.read'];
    $renewed = json_decode((string) pl_oauth_token_response(connection_request('/oauth/token', 'POST')->withParsedBody($narrow))->getBody(), true);
    assert_true(!isset($renewed['refresh_token']), 'Dropping offline_access still issued a refresh credential.');
    $wrongClient = connection_oauth_code($f);
    $request = connection_request('/oauth/revoke', 'POST')->withParsedBody(['client_id' => $wrongClient['client_id'], 'token' => $renewed['access_token']]);
    assert_throws(fn () => pl_oauth_revoke_response($request), League\OAuth2\Server\Exception\OAuthServerException::class);
    assert_same($f['actor_id'], pl_connection_authenticate(connection_request('/mcp', 'POST', $renewed['access_token']))['actor_id']);
    $body = ['client_id' => $code['client_id'], 'token' => $renewed['access_token'], 'token_type_hint' => 'refresh_token'];
    assert_same(200, pl_integration_response('/oauth/revoke', $request->withParsedBody($body))->getStatusCode());
    assert_throws(fn () => pl_connection_authenticate(connection_request('/mcp', 'POST', $renewed['access_token'])), UnexpectedValueException::class);
    assert_same(200, pl_oauth_revoke_response($request->withParsedBody($body))->getStatusCode());
    assert_same(200, pl_oauth_revoke_response($request->withParsedBody(array_replace($body, ['token' => 'unknown-token'])))->getStatusCode());
    $fresh = connection_oauth_code($f);
    $tokens = connection_exchange($fresh);
    DB::query('UPDATE pl_connection_tokens SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE connection_id = %s', $fresh['connection']['id']);
    assert_same(200, pl_oauth_revoke_response($request->withParsedBody(['client_id' => $fresh['client_id'], 'token' => $tokens['refresh_token']]))->getStatusCode());
    assert_true(DB::queryFirstField('SELECT revoked_at FROM pl_connections WHERE id = %s', $fresh['connection']['id']) !== null);
});

test('client metadata renewal never revives old grants and expiry caps new grants', function (): void {
    $f = ledger_fixture();
    $id = 'https://client.example/' . bin2hex(random_bytes(16)) . '.json';
    $metadata = ['client_id' => $id, 'client_name' => 'Sample metadata client', 'redirect_uris' => ['https://client.example/callback']];
    pl_client_registration($metadata, $id);
    DB::update('pl_connection_clients', ['expires_at' => gmdate('Y-m-d H:i:s', time() + 60)], 'client_id = %s', $id);
    $grant = pl_create_connection($f['actor_id'], $id, 'Metadata grant', [['company_id' => $f['company_id'], 'book_id' => $f['book_id']]], 'oauth');
    assert_true(strtotime($grant['expires_at'] . ' UTC') <= time() + 60);
    assert_throws(fn () => pl_client_registration($metadata, $id), DomainException::class);
    DB::query('UPDATE pl_connection_clients SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE client_id = %s', $id);
    assert_throws(fn () => pl_connection_require($grant['id']), UnexpectedValueException::class);
    pl_client_registration($metadata, $id);
    assert_throws(fn () => pl_connection_require($grant['id']), UnexpectedValueException::class);
    $fresh = pl_create_connection($f['actor_id'], $id, 'Fresh metadata grant', [['company_id' => $f['company_id'], 'book_id' => $f['book_id']]], 'oauth');
    DB::query('UPDATE pl_connection_clients SET revoked_at = UTC_TIMESTAMP() WHERE client_id = %s', $id);
    assert_throws(fn () => pl_connection_require($fresh['id']), UnexpectedValueException::class);
    assert_throws(fn () => pl_client_registration($metadata, $id), DomainException::class);
});

test('signed access credentials cannot substitute audience issuer actor or client claims', function (): void {
    $f = ledger_fixture();
    $code = connection_oauth_code($f);
    $tokens = connection_exchange($code);
    $original = (new Lcobucci\JWT\Token\Parser(new Lcobucci\JWT\Encoding\JoseEncoder()))->parse($tokens['access_token']);
    $claims = $original->claims()->all();
    $signer = new Lcobucci\JWT\Signer\Rsa\Sha256();
    $private = Lcobucci\JWT\Signer\Key\InMemory::file(pl_oauth_key_path('private.key'));
    $config = Lcobucci\JWT\Configuration::forAsymmetricSigner($signer, $private, Lcobucci\JWT\Signer\Key\InMemory::file(pl_oauth_key_path('public.key')));
    foreach (['aud' => ['https://foreign.example/mcp'], 'iss' => 'https://foreign.example', 'sub' => '999999999', 'client_id' => 'unrelated-client', 'connection_id' => str_repeat('f', 32)] as $name => $value) {
        $changed = array_replace($claims, [$name => $value]);
        $jwt = $config->builder()->issuedBy($changed['iss'])->permittedFor(...$changed['aud'])->identifiedBy($changed['jti'])->issuedAt($changed['iat'])->canOnlyBeUsedAfter($changed['nbf'])->expiresAt($changed['exp'])->relatedTo($changed['sub'])
            ->withClaim('client_id', $changed['client_id'])->withClaim('connection_id', $changed['connection_id'])->withClaim('scopes', $changed['scopes'])->getToken($signer, $private)->toString();
        assert_throws(fn () => pl_connection_authenticate(connection_request('/mcp', 'POST', $jwt)), UnexpectedValueException::class);
    }
    $parts = explode('.', $tokens['access_token']);
    $parts[2] = str_repeat('a', strlen($parts[2]));
    assert_throws(fn () => pl_connection_authenticate(connection_request('/mcp', 'POST', implode('.', $parts))), UnexpectedValueException::class);
    assert_same($f['actor_id'], pl_connection_authenticate(connection_request('/mcp', 'POST', $tokens['access_token']))['actor_id']);
});

test('concurrent refresh redemption issues once and revokes the replayed connection', function (): void {
    $f = ledger_fixture();
    $code = connection_oauth_code($f);
    $tokens = connection_exchange($code);
    $body = ['grant_type' => 'refresh_token', 'client_id' => $code['client_id'], 'refresh_token' => $tokens['refresh_token'], 'resource' => pl_connection_resource()];
    $php = '<?php require ' . var_export(PL_APP . '/includes/bootstrap.php', true) . '; require ' . var_export(PL_APP . '/includes/functions/oauth_functions.php', true) . ';'
        . '$body=' . var_export($body, true) . '; try { pl_oauth_token_response((new Nyholm\\Psr7\\ServerRequest("POST", pl_connection_issuer()."/oauth/token"))->withParsedBody($body)); echo "issued"; } catch (League\\OAuth2\\Server\\Exception\\OAuthServerException) { echo "denied"; }';
    $workers = [];
    try {
        for ($i = 0; $i < 2; ++$i) {
            $process = proc_open([PHP_BINARY], [['pipe','r'],['pipe','w'],['pipe','w']], $pipes);
            assert_true(is_resource($process));
            fwrite($pipes[0], $php); fclose($pipes[0]);
            $workers[] = [$process, $pipes];
        }
        $statuses = [];
        foreach ($workers as [$process, $pipes]) {
            $statuses[] = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            assert_same(0, proc_close($process));
            assert_same('', $error, 'Refresh worker emitted unexpected diagnostics.');
        }
        sort($statuses);
        assert_same(['denied','issued'], $statuses);
        assert_throws(fn () => pl_connection_require($code['connection']['id']), UnexpectedValueException::class);
    } finally { foreach ($workers as [$process]) { if (is_resource($process)) { proc_terminate($process); proc_close($process); } } }
});

test('all collection pagination clamps consistently and metadata blocks IPv6 transition networks', function (): void {
    foreach ([[], range(1, 32)] as $rows) {
        $result = pl_read_page($rows, 999, 25);
        assert_same($rows === [] ? 1 : 2, $result['pagination']['page']);
        assert_same($rows === [] ? 1 : 2, $result['pagination']['pages']);
        assert_same(null, $result['pagination']['next_page']);
        assert_same($rows === [] ? 0 : 7, count($result['rows']));
    }
    foreach (['2001:0000:1234::1','2001:0100::1','2002:0a00:0001::1','2001:0db8::1'] as $ip) { assert_same(false, pl_client_public_ip($ip)); }
    assert_same(true, pl_client_public_ip('2606:4700:4700::1111'));
});

test('demo grants expire at the reset boundary and reused actor and company IDs never restore access', function (): void {
    $f = ledger_fixture();
    $other = ledger_fixture();
    DB::update('pl_companies', ['is_sample' => 1], 'id = %i', $f['company_id']);
    $generation = bin2hex(random_bytes(32));
    DB::insert('pl_demo_state', ['id' => 1, 'generation' => $generation, 'reset_at' => gmdate('Y-m-d H:i:s'), 'next_reset_at' => gmdate('Y-m-d H:i:s', time() + 120)]);
    DB::insert('pl_demo_visitors', ['user_id' => $f['actor_id'], 'company_id' => $f['company_id'], 'generation' => $generation]);
    $environment = getenv('PL_ENV');
    $issuer = getenv('PL_PUBLIC_URL');
    putenv('PL_ENV=demo');
    putenv('PL_PUBLIC_URL=https://demo.example/demo');
    try {
        $pat = pl_create_personal_token($f['actor_id'], 'Demo private client', [['company_id' => $f['company_id'], 'book_id' => $f['book_id']]]);
        assert_same($generation, $pat['connection']['demo_generation']);
        assert_true(strtotime($pat['connection']['expires_at'] . ' UTC') <= time() + 120);
        $code = connection_oauth_code($f);
        $tokens = connection_exchange($code);
        assert_true($tokens['expires_in'] <= 120);
        assert_same($f['actor_id'], pl_connection_authenticate(connection_request('/mcp', 'POST', $tokens['access_token']))['actor_id']);
        assert_throws(fn () => pl_create_personal_token($other['actor_id'], 'Foreign', [['company_id' => $f['company_id'], 'book_id' => $f['book_id']]]), DomainException::class);
        DB::query('UPDATE pl_demo_state SET next_reset_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE id = 1');
        assert_throws(fn () => pl_connection_authenticate(connection_request('/mcp', 'POST', $pat['token'])), UnexpectedValueException::class);
        DB::update('pl_demo_state', ['generation' => bin2hex(random_bytes(32)), 'next_reset_at' => gmdate('Y-m-d H:i:s', time() + 3600)], 'id = 1');
        DB::query('UPDATE pl_demo_visitors SET generation = (SELECT generation FROM pl_demo_state WHERE id = 1) WHERE user_id = %i', $f['actor_id']);
        assert_throws(fn () => pl_connection_authenticate(connection_request('/mcp', 'POST', $pat['token'])), UnexpectedValueException::class);
        assert_throws(fn () => pl_connection_authenticate(connection_request('/mcp', 'POST', $tokens['access_token'])), UnexpectedValueException::class);
        assert_throws(fn () => pl_oauth_token_response(connection_request('/oauth/token', 'POST')->withParsedBody(['grant_type' => 'refresh_token', 'client_id' => $code['client_id'], 'refresh_token' => $tokens['refresh_token'], 'resource' => pl_connection_resource()])), League\OAuth2\Server\Exception\OAuthServerException::class);
    } finally {
        putenv('PL_ENV=' . $environment);
        putenv('PL_PUBLIC_URL=' . $issuer);
        DB::delete('pl_demo_visitors', 'user_id = %i', $f['actor_id']);
        DB::delete('pl_demo_state', 'id = 1');
    }
});

test('standalone STDIO bridge discovers tools reads both protocols and reports revocation', function (): void {
    $f = connection_fixture();
    $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:18200', '-t', PL_APP . '/public', PL_APP . '/public/index.php'], [['file','/dev/null','r'],['file','/dev/null','w'],['file','/dev/null','w']], $unused);
    assert_true(is_resource($server));
    $bridge = null;
    try {
        $ready = false;
        for ($i = 0; $i < 30; ++$i) {
            $socket = @fsockopen('127.0.0.1', 18200, $errno, $reason, 0.1);
            if ($socket) { fclose($socket); $ready = true; break; }
            usleep(100000);
        }
        assert_true($ready, 'Isolated bridge HTTP server did not start.');
        $environment = array_replace(getenv(), ['PL_MCP_URL' => pl_connection_resource(), 'PL_MCP_TOKEN' => $f['token'], 'PL_BRIDGE_ALLOW_LOOPBACK' => '1']);
        $bridge = proc_open([PHP_BINARY, PL_ROOT . '/tools/mcp-bridge.php'], [['pipe','r'],['pipe','w'],['pipe','w']], $pipes, null, $environment);
        assert_true(is_resource($bridge));
        stream_set_timeout($pipes[1], 15);
        $call = static function (array $message) use ($pipes): array {
            fwrite($pipes[0], json_encode($message, JSON_THROW_ON_ERROR) . "\n");
            $line = fgets($pipes[1]);
            assert_true(is_string($line), 'Bridge did not produce a JSON-RPC response.');
            if ($message['method'] === 'initialize') {
                $wire = json_decode($line, false, 64, JSON_THROW_ON_ERROR);
                assert_true(($wire->result->capabilities->tools ?? null) instanceof stdClass, 'Bridge changed the tools capability object into a JSON array.');
            }
            $response = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
            assert_same($message['id'], $response['id']);
            return $response;
        };
        $initialized = $call(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => new stdClass(), 'clientInfo' => ['name' => 'Sample bridge acceptance', 'version' => '1']]]);
        assert_same('2025-11-25', $initialized['result']['protocolVersion'] ?? null);
        fwrite($pipes[0], json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']) . "\n");
        $listed = $call(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);
        assert_same(count(pl_read_catalog()), count($listed['result']['tools'] ?? []));
        $scope = ['company_id' => $f['company_id'], 'book_id' => $f['book_id'], 'as_of' => '2026-09-15'];
        $read = $call(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'ledger_trial_balance', 'arguments' => $scope]]);
        assert_same(true, $read['result']['structuredContent']['data']['balanced'] ?? null);
        $meta = ['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => new stdClass()];
        $modern = $call(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'ledger_trial_balance', 'arguments' => $scope, '_meta' => $meta]]);
        assert_same($read['result']['structuredContent'], $modern['result']['structuredContent'] ?? null);
        $bad = $call(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => ['name' => 'ledger_execute_sql', 'arguments' => []]]);
        assert_true(isset($bad['error']), 'Unsupported write/SQL tool was accepted.');
        pl_revoke_connection($f['actor_id'], $f['connection']['id']);
        $denied = $call(['jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/list']);
        assert_true(str_contains($denied['error']['message'] ?? '', 'Reconnect'));
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        assert_same('', $stderr, 'Bridge wrote unexpected diagnostics.');
        assert_same(0, proc_close($bridge));
        $bridge = null;
    } finally {
        if (is_resource($bridge)) { proc_terminate($bridge); proc_close($bridge); }
        proc_terminate($server); proc_close($server);
    }
});
