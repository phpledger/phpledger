<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/functions/web_functions.php';

// In an uploaded package the entry point is the package folder's index.php; send direct visits there.
if (!defined('PL_WEB_ADAPTER') && is_file(dirname(__DIR__, 3) . '/index.php') && is_file(dirname(__DIR__, 3) . '/PACKAGE-MANIFEST.json')
    && preg_match('~^(/[^?#]*?)?/www/phpledger/public/index\.php$~D', str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '')), $plPackageEntry)) {
    header('Location: ' . ($plPackageEntry[1] ?? '') . '/', true, 302);
    exit;
}

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
$path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$basePath = pl_base_path();
if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
    $path = substr($path, strlen($basePath)) ?: '/';
}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($path === '/install') {
    require_once dirname(__DIR__) . '/includes/functions/install_web_functions.php';
    pl_install_http();
}
if ($path === '/mcp' || str_starts_with($path, '/api/v1/') || in_array($path, ['/oauth/token','/oauth/register','/oauth/revoke'], true) || str_starts_with($path, '/.well-known/oauth-')) {
    require_once dirname(__DIR__) . '/includes/functions/integration_http_functions.php';
    pl_integration_http($path, $method);
}
if ($path === '/health') {
    header('Content-Type: application/json; charset=utf-8');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
    if ($method !== 'GET') {
        http_response_code(405);
        header('Allow: GET');
        echo json_encode(['error' => 'Method not allowed.']);
        exit;
    }
    try {
        require_once dirname(__DIR__) . '/includes/bootstrap.php';
        DB::queryFirstField('SELECT 1');
        echo json_encode(['status' => 'ok', 'stage' => 'working-accounting-preview']);
    } catch (Throwable $error) {
        http_response_code(503);
        error_log('PHP Ledger health dependency unavailable (' . get_class($error) . ').');
        echo json_encode(['status' => 'unavailable']);
    }
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'");
$routes = [
    '/tax' => ['GET','POST'], '/ar' => ['GET','POST'], '/ap' => ['GET','POST'], '/parties' => ['GET','POST'], '/inventory' => ['GET','POST'], '/purchasing' => ['GET','POST'], '/opening-conversion' => ['GET','POST'],
    '/' => ['GET'], '/home' => ['GET'], '/login' => ['GET', 'POST'], '/logout' => ['POST'], '/start' => ['POST'],
    // 1.2 M11: the language switch. POST only, like every other state change, and reachable
    // without a session so the sign-in page can be read in the reader's own language.
    '/locale' => ['POST'],
    '/companies' => ['GET'], '/company/select' => ['POST'], '/sample-chooser' => ['GET', 'POST'], '/onboarding' => ['GET', 'POST'],
    '/setup/review' => ['GET', 'POST'], '/transactions' => ['GET'], '/transactions/detail' => ['GET'],
    '/opening-balances' => ['GET', 'POST'], '/periods' => ['GET', 'POST'], '/bank-reconciliation' => ['GET', 'POST'],
    '/numbering' => ['GET', 'POST'], '/accounting-policies' => ['GET', 'POST'], '/company-profile' => ['GET', 'POST'],
    '/transactions/new' => ['GET'], '/transactions/edit' => ['GET'], '/transactions/save' => ['POST'],
    '/transactions/post' => ['POST'], '/transactions/reverse' => ['POST'],
    '/reports/trial-balance' => ['GET'], '/reports/account' => ['GET'], '/journals/detail' => ['GET'], '/reports/export' => ['GET'],
    '/reports' => ['GET'], '/reports/balance-sheet' => ['GET'], '/reports/profit-loss' => ['GET'], '/reports/cash-forecast' => ['GET', 'POST'],
    '/reports/ageing' => ['GET'],
    '/stock-documents' => ['GET', 'POST'], '/stock-documents/detail' => ['GET', 'POST'], '/stock-documents/settlement' => ['GET', 'POST'],
    '/reports/stock-by-location' => ['GET'],
    // 1.3 M14: the Fixed assets module.
    '/fixed-assets' => ['GET', 'POST'], '/fixed-assets/detail' => ['GET', 'POST'], '/fixed-assets/depreciation' => ['GET', 'POST'],
    '/reports/asset-register' => ['GET'],
    '/pos' => ['GET'], '/pos/review' => ['GET', 'POST'], '/pos/edit' => ['POST'], '/pos/checkout' => ['POST'], '/pos/retry' => ['POST'], '/pos/receipt' => ['GET'],
    // 1.2 M7: the Users module. /invitation and /reset-password are reachable without a session,
    // because they are how an invited person and a reset password get their first sign-in.
    '/users' => ['GET', 'POST'], '/roles' => ['GET', 'POST'], '/profile' => ['GET', 'POST'],
    '/cost-visibility' => ['GET', 'POST'], '/invitation' => ['GET', 'POST'], '/reset-password' => ['GET', 'POST'],
    '/sample-guide' => ['GET'], '/help' => ['GET'], '/modules' => ['GET', 'POST'], '/connections' => ['GET','POST'], '/oauth/authorize' => ['GET','POST'], '/tables' => ['GET'],
    // 1.2 M8: Admin > Packages. Visible to the workspace, read-only without installation.admin.
    '/packages' => ['GET', 'POST'],
    '/accounts' => ['GET'], '/accounts/save' => ['POST'], '/logo' => ['GET'],
    '/owner' => ['GET'], '/owner/post' => ['POST'], '/owner/reverse' => ['POST'],
    // 1.2 M8a: the ownership register (issue #92). Every screen here is also in the pl_render()
    // allowlist in web_functions.php and in the route sweep in tests/i18n_test.php.
    '/ownership' => ['GET', 'POST'], '/ownership/export' => ['GET'], '/reports/ownership' => ['GET'],
    '/contra-review' => ['GET'], '/contra-review/confirm' => ['POST'],
    '/general-journals' => ['GET'], '/general-journals/new' => ['GET'], '/general-journals/edit' => ['GET'],
    '/general-journals/detail' => ['GET'], '/general-journals/save' => ['POST'], '/general-journals/post' => ['POST'], '/general-journals/reverse' => ['POST'],
];
// /print/<type>/<id> is one read-only route for every registered print template; the
// template registry in print_functions.php decides which types and formats exist.
$printRequest = null;
if (preg_match('~^/print/([a-z0-9-]{1,40})/([0-9]{1,18})$~D', $path, $printPath)) {
    $printRequest = ['type' => $printPath[1], 'id' => (int) $printPath[2]];
}
if ($printRequest !== null ? $method !== 'GET' : (!isset($routes[$path]) || !in_array($method, $routes[$path], true))) {
    $allowed = $printRequest !== null ? ['GET'] : ($routes[$path] ?? null);
    http_response_code($allowed === null ? 404 : 405);
    if ($allowed !== null) {
        header('Allow: ' . implode(', ', $allowed));
    }
    pl_web_unavailable_page($allowed === null ? 404 : 405);
    exit;
}
// A freshly uploaded copy has no database settings yet: start the browser installer, as WordPress does.
if (pl_web_needs_installation()) {
    header('Location: ' . pl_url('/install'), true, 303);
    exit;
}
// The optional installation logo appears on the sign-in page, so it is served without a session.
if ($path === '/logo') {
    try {
        require_once dirname(__DIR__) . '/includes/bootstrap.php';
        pl_logo_http();
    } catch (Throwable $error) {
        error_log('PHP Ledger logo unavailable (' . get_class($error) . ').');
        http_response_code(404);
        exit;
    }
}

try {
    // Resolve the once-per-session country hint before acquiring the demo database lock.
    require_once dirname(__DIR__) . '/includes/functions/demo_functions.php';
    require_once dirname(__DIR__) . '/includes/functions/security_functions.php';
    require_once dirname(__DIR__) . '/includes/functions/regional_functions.php';
    $localDemoHttp = pl_web_local_demo_http($_SERVER);
    // A copy installed at a plain http:// address cannot carry a Secure cookie at all.
    pl_session_start(!pl_web_insecure_site() && !in_array(getenv('PL_ENV') ?: 'production', ['local', 'test'], true)
        && !$localDemoHttp && !pl_web_local_http($_SERVER));
    pl_regional_suggestion();
    require_once dirname(__DIR__) . '/includes/bootstrap.php';
    $actorId = pl_current_user_id();
    $user = $actorId ? DB::queryFirstRow('SELECT id, display_name, email, locale, must_change_password FROM pl_users WHERE id = %i', $actorId) : null;
    // 1.2 M11. Every document rendered from here on carries this locale in its lang/dir, so the
    // interface language is settled once, before any screen, notice or error message is written.
    pl_web_apply_locale($user);
    if ($path === '/') {
        pl_redirect($actorId ? (!empty($_SESSION['company_id']) ? '/home' : '/companies') : '/login');
    }
    if ($method === 'POST') {
        pl_require_post();
        pl_require_csrf(pl_web_text($_POST, 'csrf'));
    }
    // The language switch runs before the sign-in gate on purpose: a person who cannot read the
    // sign-in page has no way to sign in, so the one screen that must be switchable without an
    // account is exactly that one. It changes a display preference and nothing accounting.
    if ($path === '/locale') {
        $return = pl_web_safe_return_path(pl_web_text($_POST, 'return', '/'));
        try {
            pl_web_set_locale_preference($actorId, pl_web_text($_POST, 'locale'));
        } catch (DomainException $error) {
            pl_form_failure($return, [], $error->getMessage());
        }
        pl_redirect($return);
    }
    if ($path === '/oauth/authorize') {
        require_once dirname(__DIR__) . '/includes/functions/connection_web_functions.php';
        pl_web_oauth($actorId, $user, $method);
    }
    if (pl_demo_enabled() && in_array($path, ['/onboarding', '/sample-chooser', '/setup/review', '/company/select'], true)) {
        throw new DomainException('Business setup and administration are disabled in the public sample.');
    }
    if ($path === '/start') {
        $pendingOAuth = $_SESSION['oauth_pending'] ?? null;
        $visit = pl_demo_begin_visit(pl_web_text($_POST, 'csrf'), pl_web_text($_POST, 'currency', 'USD'), isset($_POST['sample_pack']) ? pl_web_text($_POST, 'sample_pack') : null);
        $_SESSION['company_id'] = $visit['company_id'];
        if (is_array($pendingOAuth)) { $_SESSION['oauth_pending'] = $pendingOAuth; pl_redirect('/oauth/authorize?resume=1'); }
        pl_redirect(isset($_POST['sample_pack']) ? '/sample-guide' : '/reports');
    }
    if ($path === '/invitation' || $path === '/reset-password') {
        if (pl_demo_enabled()) {
            throw new DomainException('Invitations and password resets are unavailable in the public sample.');
        }
        require_once dirname(__DIR__) . '/includes/functions/user_web_functions.php';
        pl_web_account_access($path, $method);
    }
    if ($path === '/login') {
        if ($actorId) {
            pl_redirect(pl_demo_enabled() ? '/transactions' : '/companies');
        }
        if (pl_demo_enabled()) {
            if ($method !== 'GET') {
                throw new DomainException('Use Start my sample to enter the public demo.');
            }
            pl_render('demo', ['title' => 'Try PHP Ledger']);
        }
        if ($method === 'POST') {
            $authenticated = pl_authenticate(pl_web_text($_POST, 'email'), is_string($_POST['password'] ?? null) ? $_POST['password'] : '', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
            if (!$authenticated) {
                pl_form_failure('/login', ['email' => pl_web_text($_POST, 'email')], 'We could not sign you in. Check your details, or wait a few minutes before trying again.');
            }
            $pendingOAuth = $_SESSION['oauth_pending'] ?? null;
            pl_login_session($authenticated);
            if (is_array($pendingOAuth)) { $_SESSION['oauth_pending'] = $pendingOAuth; pl_redirect('/oauth/authorize?resume=1'); }
            pl_redirect('/companies');
        }
        pl_render('login', ['title' => 'Sign in', 'form' => pl_form_state('/login')]);
    }
    if (!$actorId) {
        pl_notice('Please sign in to continue. Your session may have expired.');
        pl_redirect('/login');
    }
    if ($path === '/logout') {
        pl_logout_session();
        pl_redirect('/login');
    }
    // 1.2 M7: a forced password reset is not a suggestion. Until it is done, the only screens a
    // signed-in account reaches are its own profile, where the new password is set, and signing
    // out. /invitation and /reset-password are handled above, before this point, because they
    // are how an account gets here at all.
    if ($user !== null && !empty($user['must_change_password']) && $path !== '/profile') {
        pl_notice('An administrator asked you to set a new password before continuing.');
        pl_redirect('/profile');
    }
    if ($path === '/company/select') {
        $selected = pl_company_context($actorId, pl_web_id($_POST, 'company_id'));
        $_SESSION['company_id'] = (int) $selected['id'];
        pl_redirect('/home');
    }
    if ($path === '/companies') {
        if (pl_demo_enabled()) {
            pl_redirect('/transactions');
        }
        pl_render('companies', ['title' => 'Your businesses', 'user' => $user, 'companies' => pl_list_companies($actorId)]);
    }
    if ($path === '/sample-chooser') {
        if (!pl_sample_companies_allowed()) {
            throw new DomainException('The local sample chooser is unavailable in this environment.');
        }
        if ($method === 'POST') {
            try {
                $sampleId = pl_web_text($_POST, 'sample_pack');
                $pack = pl_demo_sample($sampleId);
                $currency = pl_web_text($_POST, 'currency', 'USD');
                if (!isset(pl_base_currency_options()[$currency])) {
                    throw new DomainException('Choose one of the supported sample currencies.');
                }
                $created = pl_setup_company($actorId, [
                    'name' => (string) $pack['name'] . ' — Local sample', 'currency' => $currency,
                    'start_date' => (string) $pack['start_date'], 'fiscal_year_end' => '12-31',
                    'start_mode' => 'sample', 'sample_pack' => $pack['id'],
                    'template_digest' => pl_starter_template()['digest'], 'zero_balances_confirmed' => false,
                ], 'local-sample:' . bin2hex(random_bytes(16)));
                $_SESSION['company_id'] = (int) $created['id'];
                pl_notice('Your separate local sample company is ready to explore.');
                pl_redirect('/sample-guide');
            } catch (DomainException $error) {
                pl_form_failure('/sample-chooser', $_POST, $error->getMessage());
            }
        }
        pl_render('sample-chooser', ['title' => 'Choose a sample company', 'user' => $user, 'form' => pl_form_state('/sample-chooser')]);
    }
    if ($path === '/onboarding') {
        // The five-stage wizard (B50). Its controller keeps the stage machine, the draft and
        // the skeleton import together in one file rather than in this router.
        require_once dirname(__DIR__) . '/includes/functions/onboarding_web_functions.php';
        pl_web_onboarding($actorId, $user, $method);
    }
    if ($path === '/profile') {
        require_once dirname(__DIR__) . '/includes/functions/user_web_functions.php';
        $profileCompany = null;
        if (!empty($_SESSION['company_id'])) {
            try { $profileCompany = pl_company_context($actorId, (int) $_SESSION['company_id']); }
            catch (DomainException) { /* Your own profile stays reachable if company access was revoked. */ }
        }
        pl_web_profile($actorId, $user, $profileCompany, $method);
    }
    if ($path === '/help') {
        $helpCompany = null;
        if (!empty($_SESSION['company_id'])) {
            try { $helpCompany = pl_company_context($actorId, (int) $_SESSION['company_id']); }
            catch (DomainException) { /* Help remains available if prior company access was revoked. */ }
        }
        pl_render('help', ['title' => 'Getting started', 'user' => $user, 'company' => $helpCompany]);
    }
    $company = pl_web_context($actorId);
    $companyId = (int) $company['id'];
    $bookId = (int) $company['book_id'];
    if ($printRequest !== null) {
        require_once dirname(__DIR__) . '/includes/functions/print_functions.php';
        pl_web_print($actorId, $company, $printRequest['type'], $printRequest['id'], pl_web_text($_GET, 'format'));
    }
    if ($path === '/home') {
        $overview = pl_home_overview($actorId, $companyId, $bookId, gmdate('Y-m-d'));
        pl_render('home', ['title' => 'Home', 'user' => $user, 'company' => $company, 'overview' => $overview]);
    }
    if ($path === '/sample-guide') {
        $pack = pl_company_demo_pack($actorId, $companyId, $bookId);
        if ($pack === null) { throw new DomainException('This guide belongs to a selected sample. Your existing company has not been changed.'); }
        $sourceIds = [];
        foreach (($pack['kind'] ?? '') === 'starter_playground' ? [] : ['receipt' => ['pl_documents', 'receipts-2026-01'], 'operations' => ['pl_general_drafts', 'operations-2025-12'], 'correction' => ['pl_general_drafts', 'wrong-cost']] as $key => [$table, $reference]) {
            $sourceIds[$key] = (int) DB::queryFirstField('SELECT id FROM %b WHERE company_id = %i AND book_id = %i AND reference = %s', $table, $companyId, $bookId, $pack['id'] . '/' . $reference);
        }
        pl_render('sample-guide', ['title' => 'Explore ' . $pack['name'], 'user' => $user, 'company' => $company,
            'pack' => $pack, 'sourceIds' => $sourceIds, 'accountIds' => pl_account_code_mapping($company['accounts'])]);
    }
    if ($path === '/tables') {
        require_once dirname(__DIR__) . '/includes/functions/table_web_functions.php';
        pl_web_table($actorId, $company);
    }
    if ($path === '/connections') {
        require_once dirname(__DIR__) . '/includes/functions/connection_web_functions.php';
        pl_web_connections($actorId, $user, $company, $method);
    }
    if ($path === '/reports/export') {
        $export = pl_export_report($actorId, $companyId, $bookId, pl_web_text($_GET, 'report'),
            pl_web_text($_GET, 'to', gmdate('Y-m-d')), pl_web_text($_GET, 'from') ?: null,
            pl_web_id($_GET, 'account_id') ?: null);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $export['filename'] . '"');
        echo $export['csv'];
        exit;
    }
    if (in_array($path, ['/ar','/ap','/parties','/inventory','/purchasing','/opening-conversion','/tax'], true)) {
        require_once dirname(__DIR__) . '/includes/functions/starter_web_functions.php';
        pl_web_starter($actorId,$companyId,$bookId,$user,$company,$path,$method);
    }
    if (str_starts_with($path, '/stock-documents')) {
        require_once dirname(__DIR__) . '/includes/functions/stock_document_web_functions.php';
        pl_web_stock_documents($actorId, $companyId, $bookId, $user, $company, $path, $method);
    }
    if ($path === '/reports/stock-by-location') {
        require_once dirname(__DIR__) . '/includes/functions/stock_document_web_functions.php';
        pl_web_stock_by_location($actorId, $companyId, $bookId, $user, $company);
    }
    if ($path === '/fixed-assets' || $path === '/fixed-assets/detail' || $path === '/fixed-assets/depreciation' || $path === '/reports/asset-register') {
        require_once dirname(__DIR__) . '/includes/functions/asset_web_functions.php';
        if ($path === '/fixed-assets') { pl_web_assets($actorId, $companyId, $bookId, $user, $company, $method); }
        if ($path === '/fixed-assets/detail') { pl_web_asset_detail($actorId, $companyId, $bookId, $user, $company, $method); }
        if ($path === '/fixed-assets/depreciation') { pl_web_asset_depreciation($actorId, $companyId, $bookId, $user, $company, $method); }
        pl_web_asset_register($actorId, $companyId, $bookId, $user, $company);
    }
    if ($path === '/modules') {
        require_once dirname(__DIR__) . '/includes/functions/module_web_functions.php';
        pl_web_modules($actorId, $companyId, $bookId, $user, $company, $method);
    }
    if ($path === '/packages') {
        require_once dirname(__DIR__) . '/includes/functions/package_web_functions.php';
        pl_web_packages($actorId, $companyId, $bookId, $user, $company, $method);
    }
    if ($path === '/users' || $path === '/roles' || $path === '/cost-visibility') {
        require_once dirname(__DIR__) . '/includes/functions/user_web_functions.php';
        if ($path === '/users') { pl_web_users($actorId, $companyId, $bookId, $user, $company, $method); }
        if ($path === '/roles') { pl_web_roles($actorId, $companyId, $bookId, $user, $company, $method); }
        pl_web_cost_visibility($actorId, $companyId, $bookId, $user, $company, $method);
    }
    if ($path === '/opening-balances') {
        require_once dirname(__DIR__) . '/includes/functions/opening_web_functions.php';
        pl_web_opening($actorId, $companyId, $bookId, $user, $company, $method);
    }
    if ($path === '/periods') {
        require_once dirname(__DIR__) . '/includes/functions/period_web_functions.php';
        pl_web_periods($actorId, $companyId, $bookId, $user, $company, $method);
    }
    if ($path === '/numbering') {
        require_once dirname(__DIR__) . '/includes/functions/document_series_web_functions.php';
        pl_web_numbering($actorId, $companyId, $bookId, $user, $company, $method);
    }
    if ($path === '/accounting-policies' || $path === '/company-profile') {
        require_once dirname(__DIR__) . '/includes/functions/trading_web_functions.php';
        if ($path === '/accounting-policies') {
            pl_web_accounting_policies($actorId, $companyId, $bookId, $user, $company, $method);
        }
        pl_web_company_profile($actorId, $companyId, $bookId, $user, $company, $method);
    }
    if ($path === '/bank-reconciliation') {
        require_once dirname(__DIR__) . '/includes/functions/reconciliation_web_functions.php';
        pl_web_reconciliation($actorId, $companyId, $bookId, $user, $company, $method);
    }
    // 1.2 M8a: the ownership register. The Open Cap Format export is a plain read of the
    // register, so it needs no more authority than opening the screen does.
    if ($path === '/ownership' || $path === '/reports/ownership') {
        require_once dirname(__DIR__) . '/includes/functions/ownership_web_functions.php';
        if ($path === '/ownership') {
            pl_web_ownership($actorId, $companyId, $bookId, $user, $company, $method);
        }
        pl_web_ownership_reports($actorId, $companyId, $bookId, $user, $company);
    }
    if ($path === '/ownership/export') {
        $export = pl_ownership_export_file($actorId, $companyId, pl_web_text($_GET, 'as_of', gmdate('Y-m-d')));
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $export['filename'] . '"');
        echo $export['json'];
        exit;
    }
    if ($path === '/owner/post' || $path === '/owner/reverse') {
        try {
            pl_web_assert_scope($company, $_POST);
            if ($path === '/owner/reverse') {
                pl_reverse_owner_transaction($actorId, $companyId, $bookId, pl_web_id($_POST, 'journal_id'), null, pl_web_text($_POST, 'reason'));
                pl_notice('Owner transaction reversed. The original journal is retained and linked to its reversal.');
            } else {
                $kind = pl_web_text($_POST, 'kind');
                $side = ['capital_introduced' => 'capital', 'owner_loan_received' => 'loan', 'owner_loan_repaid' => 'loan', 'drawings' => 'drawings'][$kind] ?? 'capital';
                pl_post_owner_transaction($actorId, $companyId, $bookId, [
                    'kind' => $kind, 'date' => pl_web_text($_POST, 'date'), 'amount' => pl_web_text($_POST, 'amount'),
                    'cash_account_id' => pl_web_id($_POST, 'cash_account_id') ?: null,
                    'owner_account_id' => pl_web_id($_POST, 'owner_account_' . $side) ?: null,
                    'partner_id' => pl_web_id($_POST, 'partner_id') ?: null,
                    'description' => pl_web_text($_POST, 'description') ?: (pl_owner_transaction_kinds()[$kind]['label'] ?? 'Owner transaction'),
                    'creation_key' => pl_web_text($_POST, 'creation_key'),
                ]);
                pl_notice('Owner transaction posted. Posted entries are immutable; use a linked reversal to correct one.');
            }
            pl_redirect(pl_url('/owner'));
        } catch (DomainException $error) { pl_form_failure(pl_url('/owner'), $_POST, $error->getMessage()); }
    }
    // The upgrade step a converted chart has to answer before the owner screens open
    // (internal review finding 5). A book born on 1.2 has no pending step and never sees it.
    if ($path === '/contra-review/confirm') {
        try {
            pl_web_assert_scope($company, $_POST);
            $chosen = $_POST['contra_account_ids'] ?? [];
            if (!is_array($chosen)) { throw new DomainException('Choose the contra accounts from the list on this screen.'); }
            $ids = [];
            foreach (array_values($chosen) as $value) {
                if (!is_scalar($value) || !ctype_digit((string) $value)) { throw new DomainException('Choose the contra accounts from the list on this screen.'); }
                $ids[] = (int) $value;
            }
            pl_confirm_contra_accounts($actorId, $companyId, $bookId, $ids, pl_web_text($_POST, 'reason'), pl_web_text($_POST, 'creation_key'));
            pl_notice('Contra accounts confirmed. The marking changes presentation only; no posted entry moved.');
            pl_redirect(pl_url('/contra-review'));
        } catch (DomainException $error) { pl_form_failure(pl_url('/contra-review'), $_POST, $error->getMessage()); }
    }
    if ($path === '/contra-review') {
        $form = pl_form_state(pl_url('/contra-review'));
        pl_render('contra-review', ['title' => 'Confirm your contra accounts', 'user' => $user, 'company' => $company,
            'review' => pl_contra_review($actorId, $companyId, $bookId),
            'form' => $form, 'input' => $form['input'] ?: ['creation_key' => bin2hex(random_bytes(16))]]);
    }
    if ($path === '/owner') {
        if (pl_contra_review_pending($companyId, $bookId)) { pl_redirect(pl_url('/contra-review')); }
        $asOf = pl_web_text($_GET, 'as_of', gmdate('Y-m-d'));
        pl_ledger_date($asOf);
        $form = pl_form_state(pl_url('/owner'));
        pl_render('owner', ['title' => 'Owner and partners', 'user' => $user, 'company' => $company, 'asOf' => $asOf,
            'movements' => pl_owner_equity_movements($actorId, $companyId, $bookId, $asOf),
            'accounts' => pl_owner_accounts($companyId, $bookId),
            'transactions' => pl_list_owner_transactions($actorId, $companyId, $bookId),
            'partners' => pl_list_owner_partners($actorId, $companyId, $bookId),
            'positions' => pl_owner_partner_positions($actorId, $companyId, $bookId, $asOf),
            'sharesComplete' => pl_owner_shares_complete($actorId, $companyId, $bookId),
            'form' => $form, 'input' => $form['input'] ?: ['creation_key' => bin2hex(random_bytes(16)), 'date' => $asOf]]);
    }
    if ($path === '/accounts/save') {
        $id = pl_web_id($_POST, 'id');
        $chartFilters=pl_return_list_filters($_POST,'accounts');
        $return = pl_url('/accounts', ($id ? ['id' => $id] : ['new' => '1'])+['return_filters'=>$chartFilters]);
        try {
            pl_web_assert_scope($company, $_POST);
            $account = pl_save_account($actorId, $companyId, $bookId, [
                'name' => pl_web_text($_POST, 'name'), 'code' => pl_web_text($_POST, 'code'),
                'type' => pl_web_text($_POST, 'type'), 'role' => pl_web_text($_POST, 'role') ?: null,
                'report_classification' => pl_web_text($_POST, 'report_classification') ?: null,
                'is_active' => pl_web_text($_POST, 'is_active') === '1', 'is_contra' => pl_web_text($_POST, 'is_contra') === '1',
                'reason' => pl_web_text($_POST, 'reason'),
                'creation_key' => pl_web_text($_POST, 'creation_key'),
            ], $id ?: null, $id ? pl_web_id($_POST, 'revision') : null);
            pl_notice('Account saved. Posted journal history is preserved.');
            pl_redirect(pl_url('/accounts', ['id' => $account['id'],'return_filters'=>$chartFilters]));
        } catch (DomainException $error) { pl_form_failure($return, $_POST, $error->getMessage()); }
    }
    if ($path === '/accounts') {
        $chartFilters=isset($_GET['return_filters'])?pl_return_list_filters($_GET,'accounts'):pl_list_filters($_GET,'accounts');
        $id = pl_web_id($_GET, 'id');
        $isNew = pl_web_text($_GET, 'new') === '1';
        $account = $id ? pl_get_account($actorId, $companyId, $bookId, $id) : null;
        $operationalWarning = $account !== null && (in_array($account['role'], ['cash_bank','receivables','payables'], true)
            || DB::queryFirstField('SELECT id FROM pl_tax_codes WHERE company_id=%i AND book_id=%i AND (sales_account_id=%i OR purchase_account_id=%i) LIMIT 1',$companyId,$bookId,$id,$id) !== null);
        $form = pl_form_state(pl_url('/accounts', ($id ? ['id' => $id] : ($isNew ? ['new' => '1'] : []))+(isset($_GET['return_filters'])?['return_filters'=>$chartFilters]:[])));
        $input = $form['input'] ?: ($account ?? ['code' => '', 'name' => '', 'type' => 'expense', 'role' => 'expense', 'is_active' => true, 'creation_key' => bin2hex(random_bytes(24))]);
        $balanceDate = gmdate('Y-m-d'); $balances = [];
        foreach (pl_trial_balance($actorId, $companyId, $bookId, $balanceDate)['accounts'] as $balanceRow) {
            $balances[$balanceRow['id']] = in_array($balanceRow['type'], ['asset', 'expense'], true)
                ? $balanceRow['balance'] : bcsub('0', $balanceRow['balance'], 4);
        }
        pl_render('accounts', ['title' => 'Chart of accounts', 'user' => $user, 'company' => $company, 'account' => $account, 'accountList'=>pl_list_query($actorId,$companyId,$bookId,'accounts',$chartFilters),'chartFilters'=>$chartFilters, 'isNew' => $isNew, 'input' => $input, 'form' => $form, 'balances' => $balances, 'balanceDate' => $balanceDate, 'operationalWarning'=>$operationalWarning, 'history' => $id ? pl_core_history($actorId, $companyId, $bookId, 'account', $id) : []]);
    }
    if (str_starts_with($path, '/general-journals')) {
        $id = pl_web_id($method === 'POST' ? $_POST : $_GET, 'id');
        $journalFilters = $method==='POST' || isset($_GET['return_filters']) ? pl_return_list_filters($method==='POST'?$_POST:$_GET,'general-journals') : pl_list_filters($_GET,'general-journals');
        if ($method === 'POST') {
            $return = $path === '/general-journals/save'
                ? ($id ? pl_workflow_url('/general-journals/edit', ['id' => $id,'return_filters'=>$journalFilters]) : pl_workflow_url('/general-journals/new',['return_filters'=>$journalFilters]))
                : pl_workflow_url('/general-journals/detail', ['id' => $id,'return_filters'=>$journalFilters]);
            try {
                pl_web_assert_scope($company, $_POST);
                if ($path === '/general-journals/save') {
                    if (pl_web_text($_POST, 'editor_action') === 'add_line' || isset($_POST['remove_line'])) {
                        if (!pl_can_write($company)) { throw new DomainException('Your role can read journals but cannot edit them.'); }
                        pl_form_failure($return, pl_web_journal_line_action($_POST), '', 200);
                    }
                    if (pl_web_text($_POST, 'editor_action') === 'post_reviewed_journal') {
                        $draft = pl_save_and_post_general_draft($actorId, $companyId, $bookId, pl_web_general_input($_POST), $id ?: null, $id ? pl_web_id($_POST, 'revision') : null);
                        pl_notice('General journal posted. Your account statements are updated.');
                        pl_redirect(pl_workflow_url('/general-journals/detail', ['id' => $draft['id'],'return_filters'=>$journalFilters]));
                    }
                    $draft = pl_save_general_draft($actorId, $companyId, $bookId, pl_web_general_input($_POST), $id ?: null, $id ? pl_web_id($_POST, 'revision') : null);
                    pl_notice('Draft saved. Review the journal before posting.');
                } elseif ($path === '/general-journals/post') {
                    if (pl_web_text($_POST, 'intent') !== 'post_reviewed_journal') { throw new DomainException('Review the saved journal and choose Post journal.'); }
                    $draft = pl_post_general_draft($actorId, $companyId, $bookId, $id, pl_web_id($_POST, 'revision'));
                    pl_notice('General journal posted. Your account statements are updated.');
                } else {
                    $draft = pl_reverse_general_draft($actorId, $companyId, $bookId, $id, pl_web_text($_POST, 'date'), pl_web_text($_POST, 'reason'));
                    pl_notice('Linked reversal posted. The original journal is preserved.');
                }
                pl_redirect(pl_workflow_url('/general-journals/detail', ['id' => $draft['id'],'return_filters'=>$journalFilters]));
            } catch (DomainException $error) { pl_form_failure($return, $_POST, $error->getMessage()); }
        }
        if ($path === '/general-journals') {
            pl_render('general-journals', ['title' => 'General journals', 'user' => $user, 'company' => $company, 'selection'=>pl_web_id($_GET,'select')?pl_get_general_draft($actorId,$companyId,$bookId,pl_web_id($_GET,'select')):null,'filters' => $journalFilters, 'list' => pl_list_query($actorId, $companyId, $bookId, 'general-journals', $_GET)]);
        }
        $draft = $id ? pl_get_general_draft($actorId, $companyId, $bookId, $id) : null;
        if ($path === '/general-journals/detail') {
            if (!$draft) { throw new DomainException('Choose a saved general journal.'); }
            pl_render('general-detail', ['title' => $draft['number'], 'user' => $user, 'company' => $company, 'draft' => $draft, 'filters'=>$journalFilters, 'form' => pl_form_state(pl_workflow_url('/general-journals/detail', ['id' => $id,'return_filters'=>$journalFilters])), 'history' => pl_core_history($actorId, $companyId, $bookId, 'general_journal', $id)]);
        }
        if (!pl_can_write($company)) { throw new DomainException('Your role can read journals but cannot edit them.'); }
        if ($draft && $draft['status'] !== 'draft') { pl_redirect(pl_workflow_url('/general-journals/detail', ['id' => $id,'return_filters'=>$journalFilters])); }
        $form = pl_form_state($id ? pl_workflow_url('/general-journals/edit', ['id' => $id,'return_filters'=>$journalFilters]) : pl_workflow_url('/general-journals/new',['return_filters'=>$journalFilters]));
        $input = $form['input'] ?: ($draft ? $draft + ['date' => $draft['document_date']] : ['date' => gmdate('Y-m-d'), 'reference' => '', 'description' => '', 'lines' => [], 'creation_key' => bin2hex(random_bytes(24))]);
        pl_render('general-editor', ['title' => $id ? 'Edit general journal' : 'New general journal', 'user' => $user, 'company' => $company, 'draft' => $draft, 'filters'=>$journalFilters, 'input' => $input, 'form' => $form]);
    }
    if (in_array($path, ['/pos', '/pos/review', '/pos/edit', '/pos/checkout', '/pos/retry', '/pos/receipt'], true)) {
        require_once dirname(__DIR__) . '/includes/functions/pos_functions.php';
        $recoveryKey = $companyId . ':' . $bookId;
        $recovery = $_SESSION['pos_recoveries'][$recoveryKey] ?? null;
        if ($path === '/pos/retry') {
            if ($recovery === null) { pl_redirect('/pos'); }
            pl_web_assert_scope($company, $_POST);
            pl_web_assert_scope($company, $recovery);
            if (pl_web_text($_POST, 'retry_intent') !== 'retry_original'
                || array_diff(array_keys($_POST), ['csrf', 'company_id', 'book_id', 'retry_intent']) !== []) {
                throw new DomainException('Use Retry original sale without changing its financial details.');
            }
            try {
                $receipt = pl_checkout_pos($actorId, $companyId, $bookId, $recovery['request']);
                unset($_SESSION['pos_recoveries'][$recoveryKey], $_SESSION['pos_review'], $_SESSION['pos_cart']);
                pl_notice('Sale confirmed. The original receipt and balanced journal are saved.');
                pl_redirect(pl_url('/pos/receipt', ['id' => $receipt['document_id']]));
            } catch (DomainException $error) {
                // A definite rejection rolls back; editing is safe again under the same key.
                unset($_SESSION['pos_recoveries'][$recoveryKey]);
                pl_form_failure('/pos', $recovery['request'] + ['company_id' => $companyId, 'book_id' => $bookId], $error->getMessage());
            } catch (Throwable $error) {
                error_log('PHP Ledger original checkout outcome unavailable (' . get_class($error) . ').');
                pl_redirect('/pos/review');
            }
        }
        // Keep unresolved financial inputs immutable, including requests from older tabs.
        if ($recovery !== null && $path !== '/pos/receipt') {
            pl_web_assert_scope($company, $recovery);
            pl_require_company_access($actorId, $companyId, true);
            if ($path !== '/pos/review' || $method !== 'GET') { pl_redirect('/pos/review'); }
            http_response_code(503);
            pl_render('pos', ['title' => 'Check sale outcome', 'user' => $user, 'company' => $company, 'recovery' => $recovery]);
        }
        if ($path !== '/pos/receipt') { pl_require_module($actorId, $companyId, $bookId, 'pos-showcase', $method === 'POST'); }
        $catalog = in_array($path, ['/pos/receipt', '/pos/checkout'], true) ? [] : pl_pos_catalog();
        if ($method === 'POST' && $path !== '/pos/checkout') {
            try {
                pl_web_assert_scope($company, $_POST);
                pl_require_company_access($actorId, $companyId, true);
                pl_require_book_ready($companyId);
                $intent = $path === '/pos/edit' ? 'edit_cart' : 'review_cart';
                if (pl_web_text($_POST, 'review_intent') !== $intent) {
                    throw new DomainException('Choose the cart action to continue.');
                }
                $cartInput = $_POST;
                unset($cartInput['csrf'], $cartInput['review_intent'], $cartInput['checkout_intent']);
                if ($path === '/pos/edit') {
                    $_SESSION['pos_cart'] = $cartInput;
                    pl_redirect('/pos');
                }
                $quoteInput = $cartInput;
                unset($quoteInput['company_id'], $quoteInput['book_id'], $quoteInput['cash_received']);
                pl_review_pos($actorId, $companyId, $bookId, $quoteInput);
                $_SESSION['pos_review'] = $cartInput;
                pl_redirect('/pos/review');
            } catch (DomainException $error) {
                pl_form_failure('/pos', $_POST, $error->getMessage());
            }
        }
        if ($path === '/pos/checkout') {
            try {
                if (pl_web_text($_POST, 'checkout_intent') !== 'record_cash_sale') {
                    throw new DomainException('Choose Record cash sale to confirm this cash sale.');
                }
                pl_web_assert_scope($company, $_POST);
                $checkoutInput = $_POST;
                unset($checkoutInput['csrf'], $checkoutInput['company_id'], $checkoutInput['book_id'], $checkoutInput['checkout_intent']);
                $originalCheckout = pl_pos_recovery($companyId, $bookId, $checkoutInput, $_SESSION['pos_review_quote'] ?? null);
                $_SESSION['pos_review'] = $_POST;
                unset($_SESSION['pos_review']['csrf']);
                $receipt = pl_checkout_pos($actorId, $companyId, $bookId, $checkoutInput);
                unset($_SESSION['pos_review'], $_SESSION['pos_review_quote'], $_SESSION['pos_cart']);
                pl_notice('Sale completed. Your receipt and balanced journal are saved.');
                pl_redirect(pl_url('/pos/receipt', ['id' => $receipt['document_id']]));
            } catch (DomainException $error) {
                pl_form_failure('/pos/review', $_POST, $error->getMessage());
            } catch (Throwable $error) {
                error_log('PHP Ledger checkout outcome unavailable (' . get_class($error) . ').');
                if (!isset($originalCheckout)) { throw $error; }
                $_SESSION['pos_recoveries'][$recoveryKey] = $originalCheckout;
                pl_redirect('/pos/review');
            }
        }
        $receipt = $path === '/pos/receipt' ? pl_get_pos_receipt($actorId, $companyId, $bookId, pl_web_id($_GET, 'id')) : null;
        $form = pl_form_state($path === '/pos/review' ? '/pos/review' : '/pos');
        $input = $form['input'];
        $quote = null;
        if ($path === '/pos/review') {
            $input = $input ?: ($_SESSION['pos_review'] ?? []);
            if ($input === []) { pl_redirect('/pos'); }
            try {
                pl_web_assert_scope($company, $input);
                pl_require_company_access($actorId, $companyId, true);
                pl_require_book_ready($companyId);
                $quoteInput = $input;
                unset($quoteInput['csrf'], $quoteInput['company_id'], $quoteInput['book_id'], $quoteInput['checkout_intent'], $quoteInput['review_intent'], $quoteInput['cash_received']);
                $quote = pl_review_pos($actorId, $companyId, $bookId, $quoteInput);
                $_SESSION['pos_review'] = $input;
                $_SESSION['pos_review_quote'] = $quote;
            } catch (DomainException $error) {
                unset($_SESSION['pos_review']);
                pl_form_failure('/pos', $input, $form['message'] !== '' ? (string) $form['message'] : $error->getMessage());
            }
        } elseif ($path === '/pos' && $input === [] && isset($_SESSION['pos_cart'])) {
            $saved = $_SESSION['pos_cart'];
            unset($_SESSION['pos_cart']);
            if (is_array($saved) && (int) ($saved['company_id'] ?? 0) === $companyId && (int) ($saved['book_id'] ?? 0) === $bookId) {
                $input = $saved;
            }
        }
        pl_render('pos', ['title' => $receipt ? 'Sale receipt' : ($quote ? 'Confirm cash sale' : 'Point of sale'), 'user' => $user, 'company' => $company, 'catalog' => $catalog, 'form' => $form, 'input' => $input, 'receipt' => $receipt, 'quote' => $quote]);
    }
    if ($path === '/reports') {
        $today = gmdate('Y-m-d');
        $periodFrom = min($company['start_date'], $today);
        $profit = pl_profit_loss($actorId, $companyId, $bookId, $periodFrom, $today);
        $balance = pl_balance_sheet($actorId, $companyId, $bookId, $today);
        $overview = ['as_of' => $today, 'period_from' => $periodFrom, 'cash' => pl_cash_balance($actorId, $companyId, $bookId, $today), 'income' => $profit['total_income'], 'expenses' => bcadd($profit['total_cost_of_sales'], $profit['total_expenses'], 4), 'profit' => $profit['net_profit'], 'assets' => $balance['total_assets'], 'liabilities' => $balance['total_liabilities'], 'equity' => $balance['total_equity']];
        pl_render('reports', ['title' => 'Your business in numbers', 'user' => $user, 'company' => $company, 'overview' => $overview]);
    }
    if ($path === '/reports/ageing') {
        $direction = pl_web_text($_GET, 'direction', 'receivable');
        $asOf = pl_web_text($_GET, 'as_of', gmdate('Y-m-d'));
        $report = pl_ar_ap_open_items($actorId, $companyId, $bookId, $direction, $asOf);
        // Unapplied credit is read separately and shown as its own section: it sits on the
        // advances control, not inside receivables, so the two are never netted (B59).
        $unapplied = pl_unapplied_credit($actorId, $companyId, $bookId, pl_advance_side_for_direction($direction), $asOf);
        pl_render('ageing', ['title'=>'Receivables & payables ageing','user'=>$user,'company'=>$company,'report'=>$report,'unapplied'=>$unapplied]);
    }
    if ($path === '/reports/balance-sheet') {
        $asOf = pl_web_text($_GET, 'as_of', gmdate('Y-m-d'));
        $report = pl_balance_sheet($actorId, $companyId, $bookId, $asOf);
        $depth = pl_report_depth($_GET);
        pl_render('balance-sheet', ['title' => 'Balance sheet', 'user' => $user, 'company' => $company, 'report' => $report, 'asOf' => $asOf,
            'depth' => $depth, 'trees' => array_map(static fn (array $tree): array => pl_report_tree_limit($tree, $depth), $report['trees'])]);
    }
    if ($path === '/reports/profit-loss') {
        $preset = pl_web_text($_GET, 'preset', 'custom');
        $period = pl_report_period($preset, gmdate('Y-m-d'));
        $to = $period['to'] ?? pl_web_text($_GET, 'to', gmdate('Y-m-d'));
        $from = $period['from'] ?? pl_web_text($_GET, 'from', $company['start_date']);
        $report = pl_profit_loss($actorId, $companyId, $bookId, $from, $to);
        $depth = pl_report_depth($_GET);
        pl_render('profit-loss', ['title' => 'Profit & loss', 'user' => $user, 'company' => $company, 'report' => $report, 'from' => $from, 'to' => $to, 'preset'=>$preset,
            'depth' => $depth, 'trees' => array_map(static fn (array $tree): array => pl_report_tree_limit($tree, $depth), $report['trees'])]);
    }
    if ($path === '/reports/cash-forecast') {
        $asOf = gmdate('Y-m-d');
        $opening = pl_cash_balance($actorId, $companyId, $bookId, $asOf);
        $input = $_SESSION['cash_scenarios'][$companyId] ?? ['weekly_in' => $company['is_sample'] ? '250.00' : '0.00', 'weekly_out' => $company['is_sample'] ? '175.00' : '0.00', 'weeks' => '12'];
        $form = ['message' => '', 'input' => []];
        if ($method === 'POST') {
            pl_web_assert_scope($company, $_POST);
            $input = ['weekly_in' => pl_web_text($_POST, 'weekly_in'), 'weekly_out' => pl_web_text($_POST, 'weekly_out'), 'weeks' => pl_web_text($_POST, 'weeks')];
        }
        try {
            $forecast = pl_cash_forecast($opening, $input['weekly_in'], $input['weekly_out'], pl_web_id($input, 'weeks'));
            $_SESSION['cash_scenarios'][$companyId] = $input;
        } catch (DomainException|InvalidArgumentException $error) {
            http_response_code(422);
            $form['message'] = $error->getMessage();
            $forecast = null;
        }
        pl_render('cash-forecast', ['title' => 'Cash forecast', 'user' => $user, 'company' => $company, 'asOf' => $asOf, 'opening' => $opening, 'forecast' => $forecast, 'input' => $input, 'form' => $form]);
    }
    if ($path === '/setup/review') {
        if ($method === 'POST') {
            try {
                pl_web_assert_scope($company, $_POST);
                $assignments = is_array($_POST['roles'] ?? null) ? $_POST['roles'] : [];
                $mapped = [];
                foreach ($assignments as $key => $value) {
                    if (is_string($key) && is_scalar($value) && ctype_digit((string) $value)) {
                        $mapped[$key] = (int) $value;
                    }
                }
                pl_confirm_existing_setup($actorId, $companyId, $bookId, $mapped, pl_web_text($_POST, 'reviewed') === '1');
                pl_notice('Your existing accounts and balances are preserved. Setup review is complete.');
                pl_redirect('/transactions');
            } catch (DomainException $error) {
                pl_form_failure('/setup/review', $_POST, $error->getMessage());
            }
        }
        pl_render('setup-review', ['title' => 'Review business setup', 'user' => $user, 'company' => $company, 'template' => pl_starter_template(), 'form' => pl_form_state('/setup/review')]);
    }
    if ($path === '/transactions/save') {
        $documentId = pl_web_id($_POST, 'id');
        $returnFilters = pl_return_list_filters($_POST, 'transactions');
        $return = $documentId ? pl_workflow_url('/transactions/edit', ['id' => $documentId]) : pl_workflow_url('/transactions/new');
        try {
            pl_web_assert_scope($company, $_POST);
            $input = pl_web_document_input($_POST);
            if (pl_web_text($_POST, 'editor_action') === 'preview') {
                if (!pl_can_write($company)) { throw new DomainException('Your role can read transactions but cannot edit them.'); }
                pl_preview_document($actorId, $companyId, $bookId, $input);
                pl_form_failure($return, $_POST, '', 200);
            }
            $saved = pl_save_document($actorId, $companyId, $bookId, $input, $documentId ?: null, $documentId ? pl_web_id($_POST, 'revision') : null);
            pl_notice('Draft saved. Your accounts have not changed.');
            pl_redirect(pl_workflow_url('/transactions', ['id' => $saved['id']] + $returnFilters));
        } catch (DomainException $error) {
            pl_form_failure($return, $_POST, $error->getMessage());
        }
    }
    if ($path === '/transactions/post' || $path === '/transactions/reverse') {
        $id = pl_web_id($_POST, 'id');
        $returnFilters = pl_return_list_filters($_POST, 'transactions');
        $return = pl_workflow_url('/transactions/detail', ['id' => $id] + $returnFilters);
        try {
            pl_web_assert_scope($company, $_POST);
            if ($path === '/transactions/post') {
                $saved = pl_post_document($actorId, $companyId, $bookId, $id, pl_web_id($_POST, 'revision'));
                pl_notice('Transaction posted. Its balanced journal is now included in your reports.');
            } else {
                $saved = pl_reverse_document($actorId, $companyId, $bookId, $id, pl_web_text($_POST, 'date'), pl_web_text($_POST, 'reason'));
                pl_notice('Reversal posted. The original transaction and its history are preserved.');
            }
            pl_redirect(pl_workflow_url('/transactions', ['id' => $id] + $returnFilters));
        } catch (DomainException $error) {
            pl_form_failure($return, $_POST, $error->getMessage());
        }
    }
    if ($path === '/transactions/new' || $path === '/transactions/edit') {
        if (!pl_can_write($company)) {
            throw new DomainException('Your role can view these books, but cannot edit transactions.');
        }
        $id = pl_web_id($_GET, 'id');
        $document = $path === '/transactions/edit' ? pl_get_document($actorId, $companyId, $bookId, $id) : null;
        if ($document && $document['status'] !== 'draft') {
            pl_redirect(pl_workflow_url('/transactions/detail', ['id' => $id]));
        }
        $form = pl_form_state($document ? pl_workflow_url('/transactions/edit', ['id' => $id]) : pl_workflow_url('/transactions/new'));
        $input = $form['input'] ?: ($document ?? ['kind' => pl_web_text($_GET, 'kind', 'expense'), 'date' => gmdate('Y-m-d'), 'creation_key' => bin2hex(random_bytes(24))]);
        $preview = null;
        if (pl_web_text($input, 'editor_action') === 'preview') {
            try { $preview = pl_preview_document($actorId, $companyId, $bookId, pl_web_document_input($input)); }
            catch (DomainException $error) { $form['message'] = $error->getMessage(); }
        }
        $returnFilters = pl_return_list_filters($form['input'] ?: $_GET, 'transactions');
        pl_render('editor', ['title' => $document ? 'Edit draft' : 'New transaction', 'user' => $user, 'company' => $company, 'document' => $document, 'input' => $input, 'form' => $form, 'preview'=>$preview, 'returnFilters'=>$returnFilters]);
    }
    if ($path === '/transactions' || $path === '/transactions/detail') {
        $filters = pl_list_filters($_GET, 'transactions');
        $list = pl_list_query($actorId, $companyId, $bookId, 'transactions', $_GET);
        $id = pl_web_id($_GET, 'id');
        if (!$id && $list['documents']) {
            $id = (int) $list['documents'][0]['id'];
        }
        $document = $id ? pl_get_document($actorId, $companyId, $bookId, $id) : null;
        $form = $id ? pl_form_state(pl_workflow_url('/transactions/detail', ['id' => $id] + $filters)) : ['message' => '', 'input' => []];
        pl_render('transactions', ['title' => 'Transactions', 'user' => $user, 'company' => $company, 'list' => $list, 'filters' => $filters, 'document' => $document, 'form' => $form, 'detailOnly' => $path === '/transactions/detail']);
    }
    if ($path === '/reports/trial-balance') {
        $asOf = pl_web_text($_GET, 'as_of', gmdate('Y-m-d'));
        $report = pl_trial_balance($actorId, $companyId, $bookId, $asOf);
        $depth = pl_report_depth($_GET);
        pl_render('trial-balance', ['title' => 'Trial balance', 'user' => $user, 'company' => $company, 'report' => $report, 'asOf' => $asOf,
            'depth' => $depth, 'tree' => pl_report_tree_limit($report['tree'], $depth)]);
    }
    if ($path === '/reports/account') {
        $asOf = pl_web_text($_GET, 'as_of', gmdate('Y-m-d'));
        $from = pl_web_text($_GET, 'from') ?: null;
        pl_ledger_date($asOf);
        if ($from !== null && (pl_ledger_date($from) > $asOf)) { throw new DomainException('The activity start date must be on or before its end date.'); }
        $accountId = pl_web_id($_GET, 'id');
        $activity = $accountId ? pl_list_query($actorId, $companyId, $bookId, 'account', $_GET) : null;
        pl_render('account', ['title' => $activity ? 'Account statement' : 'Account ledger', 'user' => $user, 'company' => $company, 'activity' => $activity, 'asOf' => $asOf, 'from' => $from, 'filters' => pl_list_filters($_GET, 'account') + ['id' => $accountId, 'as_of' => $asOf, 'from' => $from]]);
    }
    // The remaining method-checked route is /journals/detail.
    $journal = pl_get_journal($actorId, $companyId, $bookId, pl_web_id($_GET, 'id'));
    $commercialSource = DB::queryFirstRow('SELECT d.id,d.kind,d.document_number FROM pl_ar_document_revisions r JOIN pl_ar_documents d ON d.id=r.document_id AND d.company_id=r.company_id AND d.book_id=r.book_id WHERE r.company_id=%i AND r.book_id=%i AND r.journal_id=%i', $companyId, $bookId, $journal['reversal_of_id'] ?? $journal['id']);
    if ($commercialSource) { $commercialSource['number'] = pl_document_number_display($commercialSource['document_number'], (int)$commercialSource['id'], $commercialSource['kind']); }
    pl_render('journal', ['title' => 'Journal entry', 'user' => $user, 'company' => $company, 'journal' => $journal, 'commercialSource'=>$commercialSource]);
} catch (PlDemoUnavailable $error) {
    http_response_code(503);
    header('Retry-After: 10');
    pl_render('error', ['title' => 'Your sample will be ready shortly', 'message' => $error->getMessage(), 'user' => null, 'errorContext' => 'demo']);
} catch (DomainException $error) {
    http_response_code(403);
    pl_render('error', ['title' => $path === '/oauth/authorize' ? 'The connection request could not be completed' : 'This action is unavailable', 'message' => $error->getMessage(), 'user' => $user ?? null, 'errorContext' => $path === '/oauth/authorize' ? 'oauth' : 'general']);
} catch (Throwable $error) {
    http_response_code(503);
    error_log('PHP Ledger request unavailable (' . get_class($error) . ').');
    pl_web_unavailable_page(503);
}
