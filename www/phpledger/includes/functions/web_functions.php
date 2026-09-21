<?php
declare(strict_types=1);

require_once __DIR__ . '/i18n_functions.php';

/** Web presentation helpers. Financial rules remain in the existing services. */
function pl_web_unavailable_page(int $status): void
{
    // Route and dependency failures must render without opening a database/session.
    require_once __DIR__ . '/security_functions.php';
    require_once dirname(__DIR__, 2) . '/templates/partials/ui/components.php';
    $title = match ($status) {
        404 => "We couldn't find that page.",
        405 => 'That action needs a different request.',
        default => 'PHP Ledger is temporarily unavailable.',
    };
    $message = match ($status) {
        404 => 'It may have moved, or the link may be out of date. Nothing was changed in your books.',
        405 => "This link only works when it is submitted from its own form. Go back and use the on-screen button instead of visiting this address directly. Nothing was saved.",
        default => 'Your request could not be completed. Please try again. If this is a new installation, check its setup and migration status.',
    };
    require dirname(__DIR__, 2) . '/templates/partials/ui/unavailable.php';
}

function pl_web_text(array $source, string $key, string $default = ''): string
{
    return isset($source[$key]) && is_string($source[$key]) ? trim($source[$key]) : $default;
}

/**
 * The application version is a shared presentation value, not a user-controlled setting.
 * www/phpledger/VERSION is the single source; the release builder, the package manifest,
 * the Git tag and the release feed must all carry the same string.
 */
function pl_app_version(): string
{
    static $version = null;
    if ($version === null) {
        $raw = @file_get_contents(dirname(__DIR__, 2) . '/VERSION');
        $candidate = is_string($raw) ? trim($raw) : '';
        $version = preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?$/D', $candidate) ? $candidate : 'unknown';
    }
    return $version;
}

function pl_web_id(array $source, string $key, int $default = 0): int
{
    $value = $source[$key] ?? null;
    return is_scalar($value) && ctype_digit((string) $value) ? (int) $value : $default;
}

function pl_url(string $path, array $query = []): string
{
    $base = pl_base_path();
    if ($base !== '' && $path !== $base && !str_starts_with($path, $base . '/')) {
        $path = $base . $path;
    }
    $query = array_filter($query, static fn ($v): bool => $v !== '' && $v !== null);
    return $path . ($query === [] ? '' : '?' . http_build_query($query));
}

/**
 * A host-provided PL_BASE_PATH wins. Otherwise the package-root entry point
 * supplies the folder it was uploaded to, such as /accounts or /phpledger-1.1.0.
 */
function pl_base_path(): string
{
    $base = (string) (getenv('PL_BASE_PATH') ?: (defined('PL_WEB_BASE_PATH') ? PL_WEB_BASE_PATH : ''));
    $base = rtrim($base, '/');
    if ($base !== '' && !preg_match('#^(?:/[a-zA-Z0-9_~-][a-zA-Z0-9._~-]*)+$#D', $base)) {
        throw new RuntimeException('Invalid application base path.');
    }
    return $base;
}

/**
 * Plain HTTP from a browser on this same computer, as with XAMPP at
 * http://localhost/. Proxied, LAN and public requests never qualify.
 */
function pl_web_local_http(array $server): bool
{
    if ((!empty($server['HTTPS']) && strtolower((string) $server['HTTPS']) !== 'off') || (int) ($server['SERVER_PORT'] ?? 0) === 443) {
        return false;
    }
    foreach (['HTTP_FORWARDED', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED_HOST', 'HTTP_X_FORWARDED_PROTO', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP'] as $header) {
        if (isset($server[$header])) {
            return false;
        }
    }
    $host = strtolower((string) parse_url('http://' . (string) ($server['HTTP_HOST'] ?? ''), PHP_URL_HOST));
    if (!in_array($host, ['localhost', '127.0.0.1', '[::1]'], true) && !preg_match('/^[a-z0-9-]+(?:\.[a-z0-9-]+)*\.localhost$/D', $host)) {
        return false;
    }
    $peer = @inet_pton((string) ($server['REMOTE_ADDR'] ?? ''));
    if ($peer === false) {
        return false;
    }
    if (strlen($peer) === 16 && substr($peer, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
        $peer = substr($peer, 12);
    }
    return strlen($peer) === 4 ? ord($peer[0]) === 127 : $peer === str_repeat("\0", 15) . "\1";
}

/** A copy without database settings belongs in the browser installer. */
function pl_web_needs_installation(): bool
{
    if (getenv('PL_ENV') === 'demo' || (string) getenv('PL_DB_PASSWORD') !== '') {
        return false;
    }
    require_once __DIR__ . '/installation_state_functions.php';
    try {
        return !is_file(pl_install_config_path()) && !is_file(pl_install_directory() . '/installed.json');
    } catch (Throwable $error) {
        return false;
    }
}

/**
 * The address this copy was installed at. The private configuration holds it when
 * the host sets no PL_PUBLIC_URL, and the session cookie has to be decided before
 * the bootstrap copies that value into the environment.
 */
function pl_web_public_url(): string
{
    $url = (string) getenv('PL_PUBLIC_URL');
    if ($url !== '') {
        return $url;
    }
    require_once __DIR__ . '/installation_state_functions.php';
    try {
        $path = pl_install_config_path();
        $configuration = is_file($path) ? require $path : null;
    } catch (Throwable $error) {
        return '';
    }
    return is_array($configuration) && is_string($configuration['public_url'] ?? null) ? $configuration['public_url'] : '';
}

/**
 * PHP Ledger runs over plain HTTP, but everything that depends on a certificate
 * does not. Setup warns and continues instead of refusing (owner decision,
 * 20 September 2026, issue #84), and every screen repeats what is unavailable.
 */
function pl_web_insecure_site(): bool
{
    return str_starts_with(strtolower(pl_web_public_url()), 'http://');
}

/** The wording shown on every screen of such a copy, or null for an HTTPS site. */
function pl_web_insecure_site_notice(): ?string
{
    if (!pl_web_insecure_site()) {
        return null;
    }
    $host = strtolower((string) parse_url(pl_web_public_url(), PHP_URL_HOST));
    return in_array($host, ['127.0.0.1', 'localhost', '[::1]'], true) || str_ends_with($host, '.localhost')
        ? 'Local test over plain HTTP. Connections (the API, MCP and app integrations) need an HTTPS address, so they stay unavailable here.'
        : "This site is not using HTTPS. Sign-in details and accounting data travel unencrypted, and Connections (the API, MCP and app integrations) stay unavailable. Turn on SSL in your hosting panel, for example AutoSSL or Let's Encrypt, then reopen this site with https://.";
}

/** The insecure cookie exception is limited to an explicitly enabled, local demo. */
function pl_web_local_demo_http(array $server): bool
{
    if (getenv('PL_ENV') !== 'demo' || getenv('PL_DEMO_LOCAL_HTTP') !== '1') {
        return false;
    }
    $host = parse_url('http://' . ($server['HTTP_HOST'] ?? ''), PHP_URL_HOST);
    $peer = pl_normalize_ip((string) ($server['REMOTE_ADDR'] ?? ''));
    if (!in_array($host, ['127.0.0.1', 'localhost', '[::1]'], true) || $peer === null) {
        return false;
    }
    $packed = inet_pton($peer);
    if ($packed === false) {
        return false;
    }
    if (strlen($packed) === 4) {
        $first = ord($packed[0]);
        $second = ord($packed[1]);
        return $first === 127 || $first === 10 || ($first === 172 && $second >= 16 && $second <= 31)
            || ($first === 192 && $second === 168);
    }
    return $peer === '::1' || (ord($packed[0]) & 0xfe) === 0xfc;
}

function pl_redirect(string $path): never
{
    header('Location: ' . pl_url($path), true, 303);
    exit;
}

function pl_notice(string $message): void
{
    $_SESSION['notice'] = $message;
}

function pl_form_failure(string $path, array $input, string $message, int $status = 422): never
{
    unset($input['password'], $input['csrf']);
    $_SESSION['form_failure'] = ['path' => $path, 'input' => $input, 'message' => $message, 'status' => $status === 200 ? 200 : 422];
    pl_redirect($path);
}

function pl_form_state(string $path): array
{
    $failure = $_SESSION['form_failure'] ?? null;
    if (is_array($failure) && ($failure['path'] ?? null) === $path) {
        unset($_SESSION['form_failure']);
        http_response_code(($failure['status'] ?? 422) === 200 ? 200 : 422);
        return $failure;
    }
    return ['input' => [], 'message' => ''];
}

/**
 * Read and validate the one-page onboarding preview form. A sample start is accepted only
 * where sample companies are allowed (pl_sample_companies_allowed), as in the wizard.
 */
function pl_onboarding_preview_input(array $post, string $templateDigest): array
{
    $input = [
        'name' => pl_web_text($post, 'name'), 'currency' => pl_web_text($post, 'currency'),
        'start_date' => pl_web_text($post, 'start_date'),
        'entity_type' => pl_web_text($post, 'entity_type', 'other'),
        'fiscal_year_end_choice' => pl_web_text($post, 'fiscal_year_end_choice'),
        'fiscal_year_end_custom' => pl_web_text($post, 'fiscal_year_end_custom'),
        'chart_choice' => pl_web_text($post, 'chart_choice', 'neutral'),
        'start_mode' => pl_web_text($post, 'start_mode', 'fresh'),
        'template_digest' => $templateDigest,
        'zero_balances_confirmed' => pl_web_text($post, 'zero_balances_confirmed') === '1',
    ];
    if (!array_key_exists($input['entity_type'], pl_setup_entity_type_options())) {
        throw new DomainException('Choose the type of business or organisation you are setting up.');
    }
    $yearEndChoice = $input['fiscal_year_end_choice'];
    if ($yearEndChoice === 'custom') {
        $input['fiscal_year_end'] = $input['fiscal_year_end_custom'];
    } elseif ($yearEndChoice !== '') {
        if (!array_key_exists($yearEndChoice, pl_fiscal_year_end_options()) || $yearEndChoice === 'custom') {
            throw new DomainException('Choose a listed year-end option or enter a custom year end.');
        }
        $input['fiscal_year_end'] = $yearEndChoice;
    } else {
        // Preserve compatibility with existing non-browser callers using fiscal_year_end.
        $input['fiscal_year_end'] = pl_web_text($post, 'fiscal_year_end');
        $input['fiscal_year_end_choice'] = array_key_exists($input['fiscal_year_end'], pl_fiscal_year_end_options())
            ? $input['fiscal_year_end'] : 'custom';
        if ($input['fiscal_year_end_choice'] === 'custom') {
            $input['fiscal_year_end_custom'] = $input['fiscal_year_end'];
        }
    }
    if (!in_array($input['start_mode'], ['fresh', 'existing', 'sample'], true)) {
        throw new DomainException('Choose how you want to start.');
    }
    if ($input['start_mode'] === 'sample') {
        pl_require_sample_companies_allowed();
        $input['name'] = 'Core accounting sample';
        $input['zero_balances_confirmed'] = true;
    }
    pl_ledger_text($input['name'], 'Business name', 160);
    pl_ledger_date($input['start_date']);
    if (!isset(pl_base_currency_options()[$input['currency']])) {
        throw new DomainException('Choose one of the supported base currencies.');
    }
    pl_ledger_date('2001-' . $input['fiscal_year_end']);
    if ($input['start_mode'] === 'fresh' && !$input['zero_balances_confirmed']) {
        throw new DomainException('Confirm that this business starts with no prior balances, or choose Bring past records.');
    }
    return $input;
}

function pl_web_context(int $actorId): array
{
    $id = (int) ($_SESSION['company_id'] ?? 0);
    if ($id < 1) {
        pl_redirect('/companies');
    }
    return pl_company_context($actorId, $id);
}

function pl_web_assert_scope(array $company, array $input): void
{
    if (pl_web_id($input, 'company_id') !== (int) $company['id']
        || pl_web_id($input, 'book_id') !== (int) $company['book_id']) {
        throw new DomainException('Your company changed in another tab. Reopen this record in the intended company.');
    }
}

function pl_filters(array $input): array
{
    return [
        'status' => pl_web_text($input, 'status', 'draft'),
        'kind' => pl_web_text($input, 'kind', 'all'),
        'search' => mb_substr(pl_web_text($input, 'search'), 0, 160),
        'from' => pl_web_text($input, 'from'),
        'to' => pl_web_text($input, 'to'),
        'page' => max(1, pl_web_id($input, 'page', 1)),
    ];
}

/** Canonical bookmarkable list input; never pass a browser column name to SQL. */
function pl_list_filters(array $input, string $screen): array
{
    $sorts = match ($screen) {
        'transactions' => ['date','name','amount','status'],
        'general-journals' => ['date','description','status'],
        'account' => ['date','journal','description','source','debit','credit','balance'],
        'accounts' => ['code','name'],
        'bank' => ['date','reference','money_in','money_out','match'],
        'ar','ap' => ['date','name','amount','due'],
        'parties' => ['name','country'],
        'inventory' => ['name','sku'],
        'purchasing' => ['date','name','amount'],
        default => throw new DomainException('Unknown list.'),
    };
    $page = $input['page'] ?? '1';
    $size = $input['per_page'] ?? '25';
    foreach ([$page, $size] as $number) {
        if ((!is_int($number) && !is_string($number)) || !preg_match('/^[1-9][0-9]{0,5}$/D', (string)$number)) {
            throw new DomainException('Choose a valid list page and page size.');
        }
    }
    if ((int)$page > 100000 || !in_array((int)$size, [25,50,100], true)) { throw new DomainException('Choose 25, 50 or 100 rows per page.'); }
    $sort = $input['sort'] ?? ($screen==='accounts'?'code':(in_array($screen,['parties','inventory'],true) ? 'name' : 'date'));
    $direction = $input['dir'] ?? (in_array($screen,['account','accounts','parties','inventory'],true) ? 'asc' : 'desc');
    if (!is_string($sort) || !in_array($sort, $sorts, true) || !in_array($direction, ['asc','desc'], true)) {
        throw new DomainException('Unsupported list order.');
    }
    $query = $input['q'] ?? $input['search'] ?? '';
    if (!is_string($query) || mb_strlen($query) > 160) { throw new DomainException('Search must be text of up to 160 characters.'); }
    $filters = ['page' => (int)$page, 'per_page' => (int)$size, 'q' => trim($query), 'sort' => $sort, 'dir' => $direction];
    if ($screen==='accounts') {
        $type=$input['type']??'all'; $status=$input['status']??'all';
        if (!in_array($type,['all','asset','liability','equity','income','expense'],true) || !in_array($status,['all','active','inactive'],true)) { throw new DomainException('Choose valid account classification and status filters.'); }
        $filters+=['type'=>$type,'status'=>$status];
    }
    if ($screen==='account' && ($input['return_report']??'')!=='') {
        $report=$input['return_report'];
        if (!in_array($report,['profit-loss','balance-sheet','trial-balance'],true)) { throw new DomainException('Choose a valid return report.'); }
        $to=$input['return_to']??$input['as_of']??gmdate('Y-m-d');
        $from=$input['return_from']??($report==='profit-loss'?($input['from']??''):'');
        $preset=$input['return_preset']??'custom';
        if (!is_string($to) || !is_string($from) || !in_array($preset,['month','last_month','quarter','year','custom'],true)) { throw new DomainException('Choose valid return report dates.'); }
        $filters+=['return_report'=>$report,'return_to'=>pl_ledger_date($to),'return_from'=>$from===''?'':pl_ledger_date($from),'return_preset'=>$preset];
        if ($from!=='' && $from>$to) { throw new DomainException('The return report start date must be on or before its end date.'); }
    }
    if ($screen==='parties') {
        $role=$input['role']??'all';
        if (!in_array($role,['all','customer','vendor'],true)) { throw new DomainException('Choose customers, suppliers or all parties.'); }
        $filters['role']=$role;
    }
    if ($screen==='inventory') {
        $kind=$input['kind']??'all'; $status=$input['status']??'all'; $date=$input['as_of']??gmdate('Y-m-d');
        if (!in_array($kind,['all','stock','nonstock'],true) || !in_array($status,['all','active','inactive'],true) || !is_string($date)) { throw new DomainException('Choose valid product filters.'); }
        $filters+=['kind'=>$kind,'status'=>$status,'as_of'=>pl_ledger_date($date)];
    }
    if ($screen==='purchasing') {
        $status=$input['status']??'all'; $date=$input['as_of']??gmdate('Y-m-d');
        if (!in_array($status,['all','draft','ordered','partial','received','cancelled'],true) || !is_string($date)) { throw new DomainException('Choose valid purchase order filters.'); }
        $filters+=['status'=>$status,'as_of'=>pl_ledger_date($date)];
    }
    if (in_array($screen, ['transactions','general-journals'], true)) {
        $status = $input['status'] ?? ($screen === 'transactions' ? 'draft' : 'all');
        if (!in_array($status, ['all','draft','posted','reversed'], true)) { throw new DomainException('Choose a valid status.'); }
        $filters['status'] = $status;
    }
    if ($screen === 'transactions') {
        $kind = $input['kind'] ?? 'all';
        if (!in_array($kind, ['all','receipt','expense'], true)) { throw new DomainException('Choose a valid transaction type.'); }
        $filters['kind'] = $kind;
        foreach (['from','to'] as $key) {
            $value = $input[$key] ?? '';
            if (!is_string($value)) { throw new DomainException('Choose a valid filter date.'); }
            $filters[$key] = $value === '' ? '' : pl_ledger_date($value);
        }
    }
    if (in_array($screen,['ar','ap'],true)) {
        $status=$input['status']??'all';
        if (!in_array($status,['all','draft','unpaid','overdue','paid','reversed'],true)) { throw new DomainException('Choose a valid document status.'); }
        $filters['status']=$status;
        foreach (['from','to'] as $key) {
            $value=$input[$key]??'';
            if (!is_string($value)) { throw new DomainException('Choose a valid filter date.'); }
            $filters[$key]=$value===''?'':pl_ledger_date($value);
        }
        if ($filters['from']!=='' && $filters['to']!=='' && $filters['from']>$filters['to']) { throw new DomainException('The beginning of the date range must be on or before its end.'); }
    }
    return $filters;
}

/** Carry only validated list state through editor submissions and record actions. */
function pl_return_list_filters(array $input, string $screen): array
{
    $filters = $input['return_filters'] ?? [];
    if (!is_array($filters)) { throw new DomainException('Invalid list return filters.'); }
    return pl_list_filters($filters, $screen);
}

/** Reuse the existing scoped, counted LIMIT/OFFSET services, including running balances. */
function pl_list_query(int $actorId, int $companyId, int $bookId, string $screen, array $input): array
{
    $filters = pl_list_filters($input, $screen);
    $options = $filters + ['page_size' => $filters['per_page'], 'search' => $filters['q'], 'direction' => $filters['dir']];
    return match ($screen) {
        'transactions' => pl_list_documents($actorId, $companyId, $bookId, $options),
        'general-journals' => pl_list_general_drafts($actorId, $companyId, $bookId, $filters['page'], $options),
        'account' => pl_account_activity($actorId, $companyId, $bookId, pl_web_id($input, 'id'), pl_web_text($input, 'as_of', gmdate('Y-m-d')), $filters['page'], pl_web_text($input, 'from') ?: null, $options),
        'accounts' => pl_page_accounts($actorId,$companyId,$bookId,$filters),
        'bank' => pl_bank_get_statement($actorId, $companyId, $bookId, pl_web_id($input, 'statement_id'), $options),
        'ar','ap' => pl_page_ar_documents($actorId,$companyId,$bookId,$screen,$filters),
        'parties' => pl_page_parties($actorId,$companyId,$bookId,$filters),
        'inventory' => pl_page_inventory_products($actorId,$companyId,$bookId,$filters),
        'purchasing' => pl_page_purchase_orders($actorId,$companyId,$bookId,$filters),
        default => throw new DomainException('Unknown list.'),
    };
}

/**
 * Write an exact decimal amount for display. The separators and the digit grouping come from
 * pl_number_format_rules(), which returns today's Western rules for every locale, so this output
 * is unchanged; South Asian grouping (12,34,56,789) arrives in M11 by adding a row there, with no
 * call site touched. The string arithmetic is deliberate: the stored exact amount, the
 * transaction and base currency and the posting precision are not changed by writing it down.
 */
function pl_money(string $amount): string
{
    if (!preg_match('/^(-?)([0-9]+)(?:\.([0-9]{1,4}))?$/D', $amount, $match)) {
        return $amount;
    }
    $decimals = str_pad($match[3] ?? '', 4, '0');
    $decimals = substr($decimals, 2) === '00' ? substr($decimals, 0, 2) : $decimals;
    $rules = pl_number_format_rules();
    return $match[1] . pl_group_digits($match[2], $rules['grouping'], $rules['group']) . $rules['decimal'] . $decimals;
}

/**
 * Write an accounting DATE for display. The pattern comes from pl_date_format_pattern(), which
 * returns today's 'd M Y' for every locale, so this output is unchanged; M11 adds locale patterns
 * and translated month names there. The value is a business calendar DATE: it is parsed with a
 * fixed Y-m-d pattern and reset ('!'), so no timezone arithmetic can move it into the
 * neighbouring day, and the date used for posting, period checks and reconciliation is untouched.
 */
function pl_date_label(string $date): string
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed ? $parsed->format(pl_date_format_pattern()) : $date;
}

function pl_icon(string $name): string
{
    $allowed = ['search', 'plus', 'chevron-down', 'chevron-left', 'chevron-right', 'arrow-left',
        'arrow-right', 'logout', 'building', 'check', 'x', 'info-circle', 'alert-circle',
        'file-text', 'book', 'adjustments-horizontal', 'external-link', 'arrow-back-up', 'receipt', 'list', 'menu-2',
        'alert-triangle', 'arrows-shuffle', 'book-2', 'briefcase', 'building-bank', 'building-cog', 'building-store',
        'calendar', 'cash', 'cash-register', 'chart-line', 'chef-hat', 'circle-check', 'clipboard-check', 'clipboard-list',
        'copy', 'diamond', 'dots-vertical', 'download', 'file-check', 'file-dollar', 'file-invoice', 'filter', 'flag-2',
        'flask', 'git-branch', 'grid-dots', 'help-circle', 'history', 'home', 'key', 'layout-sidebar-left-collapse', 'link',
        'list-check', 'list-details', 'lock', 'package', 'pencil', 'printer', 'receipt-2', 'receipt-refund', 'receipt-tax',
        'refresh', 'report', 'rocket', 'scale', 'shopping-bag', 'stethoscope', 'trash', 'trending-up', 'truck', 'truck-delivery', 'user-plus', 'users'];
    if (!in_array($name, $allowed, true)) {
        return '';
    }
    return '<img class="icon" src="' . pl_e(pl_url('/assets/icons/' . $name . '.svg')) . '" alt="" width="20" height="20">';
}

function pl_csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . pl_e(pl_csrf_token()) . '">';
}

function pl_scope_fields(array $company): string
{
    return '<input type="hidden" name="company_id" value="' . (int) $company['id']
        . '"><input type="hidden" name="book_id" value="' . (int) $company['book_id'] . '">';
}

function pl_can_write(array $company): bool
{
    return in_array($company['role'] ?? '', ['owner', 'accountant'], true);
}

/** Convert browser rows to service input; blank spare rows are not accounting entries. */
/** A no-JavaScript line action preserves incomplete fields without saving a draft. */
function pl_web_journal_line_action(array $input): array
{
    return pl_web_line_action($input);
}

function pl_web_line_action(array $input,int $limit=100): array
{
    if ($limit<1 || $limit>500) { throw new DomainException('Invalid line limit.'); }
    $lines = $input['lines'] ?? [];
    if (!is_array($lines) || count($lines) > $limit || count(array_filter($lines, 'is_array')) !== count($lines)) {
        throw new DomainException('Use up to '.$limit.' document lines.');
    }
    $lines = array_values($lines);
    if (pl_web_text($input, 'editor_action') === 'add_line') {
        if (count($lines) >= $limit) { throw new DomainException('A document supports up to '.$limit.' lines.'); }
        $lines[] = [];
    } elseif (isset($input['remove_line'])) {
        $index = pl_web_text($input, 'remove_line');
        if ($index === '' || !ctype_digit($index) || !array_key_exists((int) $index, $lines)) { throw new DomainException('Choose a current document line.'); }
        array_splice($lines, (int) $index, 1);
    } else { throw new DomainException('Choose an add or remove line action.'); }
    $input['lines'] = $lines ?: [[]];
    unset($input['editor_action'], $input['remove_line']);
    return $input;
}

function pl_web_document_input(array $input): array
{
    return [
        'kind'=>pl_web_text($input,'kind'), 'date'=>pl_web_text($input,'date'),
        'amount'=>pl_web_text($input,'amount'), 'money_account_id'=>pl_web_id($input,'money_account_id'),
        'category_account_id'=>pl_web_id($input,'category_account_id'), 'counterparty'=>pl_web_text($input,'counterparty'),
        'reference'=>pl_web_text($input,'reference'), 'memo'=>pl_web_text($input,'memo'), 'creation_key'=>pl_web_text($input,'creation_key'),
    ];
}

/** Calendar presets resolve on the server too, including without JavaScript. */
function pl_report_period(string $preset, string $today): ?array
{
    $date = new DateTimeImmutable(pl_ledger_date($today));
    $quarterMonth = intdiv((int)$date->format('n') - 1, 3) * 3 + 1;
    $start = match ($preset) {
        'month' => $date->modify('first day of this month'),
        'last_month' => $date->modify('first day of last month'),
        'quarter' => $date->setDate((int)$date->format('Y'), $quarterMonth, 1),
        'year' => $date->setDate((int)$date->format('Y'), 1, 1),
        'custom' => null,
        default => throw new DomainException('Choose a supported report period.'),
    };
    if ($start === null) { return null; }
    $end = $preset === 'last_month' ? $date->modify('last day of last month') : $date;
    return ['from'=>$start->format('Y-m-d'), 'to'=>$end->format('Y-m-d')];
}

function pl_web_general_input(array $input): array
{
    $rows = $input['lines'] ?? [];
    if (!is_array($rows) || count($rows) > 100) { throw new DomainException('Use up to 100 journal lines.'); }
    $lines = [];
    foreach ($rows as $row) {
        if (!is_array($row)) { throw new DomainException('A journal line is invalid.'); }
        foreach (['account_id', 'debit', 'credit', 'description'] as $field) {
            if (isset($row[$field]) && !is_string($row[$field])) { throw new DomainException('Journal fields must contain text or decimal amounts.'); }
        }
        $id = pl_web_id($row, 'account_id');
        $debit = pl_web_text($row, 'debit'); $credit = pl_web_text($row, 'credit');
        $description = pl_web_text($row, 'description');
        if (!$id && $debit === '' && $credit === '' && $description === '') { continue; }
        $lines[] = ['account_id' => $id, 'debit' => $debit === '' ? '0' : $debit, 'credit' => $credit === '' ? '0' : $credit, 'description' => $description];
    }
    return ['date' => pl_web_text($input, 'date'), 'reference' => pl_web_text($input, 'reference'), 'description' => pl_web_text($input, 'description'), 'creation_key' => pl_web_text($input, 'creation_key'), 'lines' => $lines];
}

/** Fixed account-statement destination; a browser may supply filters, never a URL. */
function pl_web_account_return(array $input): array
{
    $source=$input['return_account']??[];
    if (!is_array($source)) { throw new DomainException('Invalid account return filters.'); }
    if ($source===[]) { return []; }
    $id=pl_web_id($source,'id'); $from=pl_web_text($source,'from'); $to=pl_web_text($source,'as_of');
    if ($id<1 || $to==='') { throw new DomainException('Choose a valid account return destination.'); }
    $filters=pl_list_filters($source,'account')+['id'=>$id,'as_of'=>pl_ledger_date($to),'from'=>$from===''?'':pl_ledger_date($from)];
    if ($from!=='' && $from>$to) { throw new DomainException('The account return start date must be on or before its end date.'); }
    return $filters;
}

/** Preserve the ageing report's accounting date and direction through document actions. */
function pl_web_ageing_return(array $input, string $path = ''): array
{
    $source=$input['return_ageing']??[];
    if ($source===[] && ($input['return_report']??'')==='ageing' && in_array($path,['/ar','/ap'],true)) {
        $source=['direction'=>$path==='/ar'?'receivable':'payable','as_of'=>pl_web_text($input,'as_of')];
    }
    if (!is_array($source)) { throw new DomainException('Invalid ageing return filters.'); }
    if ($source===[]) { return []; }
    $direction=$source['direction']??'';
    if (!in_array($direction,['receivable','payable'],true)) { throw new DomainException('Choose a valid ageing return direction.'); }
    return ['direction'=>$direction,'as_of'=>pl_ledger_date(pl_web_text($source,'as_of'))];
}

/** A workflow keeps structured, validated report filters, never a caller's redirect URL. */
function pl_workflow_url(string $path, array $query = [], ?array $input = null): string
{
    $allowed = ['/transactions','/transactions/new','/transactions/edit','/transactions/detail','/transactions/save','/transactions/post','/transactions/reverse',
        '/general-journals','/general-journals/new','/general-journals/edit','/general-journals/detail','/general-journals/save','/general-journals/post','/general-journals/reverse',
        '/journals/detail','/ar','/ap','/purchasing'];
    if (!in_array($path, $allowed, true)) { throw new DomainException('Unsupported workflow destination.'); }
    $input ??= ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? array_replace($_GET, $_POST) : $_GET;
    $context = pl_web_account_return($input);
    if ($context !== []) { $query['return_account'] = $context; }
    $ageing = pl_web_ageing_return($input, $path);
    if ($ageing !== []) { $query['return_ageing'] = $ageing; }
    return pl_url($path, $query);
}

/** Field hints for a rejected editor; the existing services remain authoritative. */
function pl_web_editor_errors(array $input, string $screen): array
{
    if (!in_array($screen, ['transaction','journal','ar','purchase'], true)) { throw new LogicException('Unknown document editor.'); }
    $errors = [];
    $check = static function (string $field, callable $validate) use (&$errors): void {
        try { $validate(); } catch (DomainException $error) { $errors[$field] = $error->getMessage(); }
    };
    $check('date', static fn () => pl_ledger_date(pl_web_text($input, 'date')));
    $textFields = match ($screen) {
        'transaction' => ['counterparty'=>['Paid to / received from',160,true], 'reference'=>['Reference',120,false], 'memo'=>['Memo',500,false]],
        'journal' => ['description'=>['Journal description',500,true], 'reference'=>['Reference',120,false]],
        default => ['reference'=>['Reference',120,false]],
    };
    foreach ($textFields as $field => [$label,$limit,$required]) {
        $check($field, static fn () => pl_ledger_text($input[$field] ?? '', $label, $limit, $required));
    }
    $positive = static function (string $value): string {
        $amount = pl_amount($value);
        if (bccomp($amount, '0', 4) <= 0) { throw new DomainException('Enter an amount greater than zero.'); }
        return $amount;
    };
    if ($screen === 'transaction') {
        $check('amount', static fn () => $positive(pl_web_text($input,'amount')));
        foreach (['money_account_id'=>'cash or bank account','category_account_id'=>'category'] as $field=>$label) {
            if (pl_web_id($input,$field) < 1) { $errors[$field] = 'Choose a '.$label.'.'; }
        }
        return $errors;
    }
    if ($screen !== 'journal') {
        if (pl_web_id($input,'party_id') < 1) { $errors['party_id'] = 'Choose a customer or supplier.'; }
        if (!preg_match('/^[A-Z]{3}$/D',strtoupper(pl_web_text($input,'currency')))) { $errors['currency']='Enter a three-letter currency code.'; }
        if ($screen === 'ar') {
            $check('due_date', static fn () => pl_ledger_date(pl_web_text($input,'due_date')));
            if (!isset($errors['date']) && !isset($errors['due_date']) && pl_web_text($input,'due_date') < pl_web_text($input,'date')) { $errors['due_date']='The due date cannot precede the document date.'; }
        }
    }
    $rows = is_array($input['lines'] ?? null) ? array_values($input['lines']) : [];
    foreach ($rows ?: [[]] as $index=>$row) {
        if (!is_array($row)) { continue; }
        $prefix='lines.'.$index.'.';
        if ($screen === 'journal') {
            $debit=pl_web_text($row,'debit'); $credit=pl_web_text($row,'credit');
            if (pl_web_id($row,'account_id')===0 && $debit==='' && $credit==='' && pl_web_text($row,'description')==='') { continue; }
            if (pl_web_id($row,'account_id') < 1) { $errors[$prefix.'account_id']='Choose an account for this line.'; }
            foreach (['debit'=>$debit,'credit'=>$credit] as $field=>$value) { $check($prefix.$field, static fn () => pl_amount($value === '' ? '0' : $value)); }
            if (!isset($errors[$prefix.'debit']) && !isset($errors[$prefix.'credit'])) {
                $hasDebit=bccomp($debit ?: '0','0',4)>0; $hasCredit=bccomp($credit ?: '0','0',4)>0;
                if ($hasDebit === $hasCredit) { $errors[$prefix.'debit']='Enter one positive debit or credit, not both.'; }
            }
        } else {
            $check($prefix.'description', static fn () => pl_ledger_text($row['description'] ?? '', 'Line description', $screen === 'purchase' ? 300 : 500, $screen !== 'purchase'));
            foreach (['quantity','unit_price'] as $field) { $check($prefix.$field, static fn () => $positive(pl_web_text($row,$field))); }
            if ($screen === 'purchase' && pl_web_id($row,'product_id') < 1) { $errors[$prefix.'product_id']='Choose a product for this line.'; }
        }
    }
    return $errors;
}

function pl_render(string $view, array $data = []): never
{
    $allowed = ['home','ar','ap','parties','inventory','purchasing','tax','opening-conversion','login', 'companies', 'sample-chooser', 'onboarding', 'setup-review', 'transactions', 'editor',
        'trial-balance', 'account', 'journal', 'help', 'error', 'demo', 'reports', 'balance-sheet', 'profit-loss', 'cash-forecast', 'pos', 'ageing', 'settlement', 'stock-count', 'goods-receipt',
        'accounts', 'general-journals', 'general-editor', 'general-detail', 'modules', 'opening-balances', 'periods', 'bank-reconciliation', 'connections', 'oauth-consent', 'sample-guide',
        // 1.2 M4 stock documents and their reports.
        'stock-documents', 'stock-document', 'van-settlement', 'stock-by-location',
        // Also 1.2: the M2 numbering screen and the M6 owner screen were added to their
        // routes but never to this allowlist, so both answered 503. Found by
        // tests/stock-http-smoke.py, which is why it drives every screen over HTTP.
        'numbering', 'owner'];
    if (!in_array($view, $allowed, true)) {
        throw new LogicException('Unknown template.');
    }
    $accountReturn=in_array($view,['journal','general-detail','general-editor','transactions','editor','ar','ap','settlement','purchasing'],true)?pl_web_account_return($_GET):[];
    $ageingReturn=in_array($view,['journal','ar','ap','settlement','purchasing'],true)?pl_web_ageing_return($_GET,'/'.$view):[];
    extract($data, EXTR_SKIP);
    $title = $data['title'] ?? 'PHP Ledger';
    $company = $data['company'] ?? null;
    $user = $data['user'] ?? null;
    $regional = $_SESSION['regional_suggestion'] ?? [];
    $notice = $_SESSION['notice'] ?? '';
    unset($_SESSION['notice']);
    require PL_APP . '/templates/layout.php';
    exit;
}
