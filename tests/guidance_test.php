<?php
declare(strict_types=1);

/**
 * The in-app accounting guidance catalogue and its bubble (owner decisions B66 and B67).
 *
 * Two kinds of check live here. The first group reads the catalogue files and the templates, with
 * no database and no server, so it fails the moment a branch wires a bubble to a concept nobody
 * wrote or writes an explanation nobody can read. The last one starts a server and looks at the
 * HTML a browser would actually be sent, because the promises that matter — it renders, it works
 * with JavaScript disabled, and a local note only appears in its own jurisdiction — are promises
 * about the document, not about the functions underneath it.
 */

/** Concept ids the screens ask for: pl_ui_help('...') anywhere the application renders. */
function guidance_wired_concepts(): array
{
    $wired = [];
    $root = dirname(__DIR__) . '/www/phpledger';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') { continue; }
        $path = str_replace('\\', '/', (string) $file->getPathname());
        $text = (string) file_get_contents($path);
        if (preg_match_all("/pl_ui_help\(\s*'([^']*)'/", $text, $matches)) {
            foreach ($matches[1] as $concept) {
                $wired[$concept][] = substr($path, strlen(str_replace('\\', '/', dirname(__DIR__))) + 1);
            }
        }
    }
    return $wired;
}

test('every help concept carries a title and a plain-language explanation within its bounds', function (): void {
    $ids = pl_guidance_concept_ids();
    assert_true(count($ids) >= 4, 'The catalogue is empty; there is nothing for a bubble to say.');
    foreach ($ids as $id) {
        $entry = pl_guidance_concept($id);
        assert_true($entry !== null, 'Concept ' . $id . ' did not load.');
        assert_true($entry['title'] !== '' && mb_strlen($entry['title']) <= 60, 'Concept ' . $id . ' needs a short title.');
        $words = pl_guidance_word_count($entry['explanation']);
        // A bubble is not a chapter. Under 40 words it explains nothing; over 70 it is the long
        // text block on the page that B66 exists to remove.
        assert_true($words >= 40 && $words <= 70, 'Concept ' . $id . ' explains itself in ' . $words . ' words; the bound is 40 to 70.');
        assert_true($entry['here'] === '' || pl_guidance_word_count($entry['here']) <= 40, 'The "how it works here" line for ' . $id . ' is too long to sit under the explanation.');
        assert_true(in_array($entry['review'], ['placeholder', 'reviewed'], true), 'Concept ' . $id . ' has an unknown review state.');
        if ($entry['document'] !== null) {
            assert_true($entry['document']['label'] !== '', 'The longer-document link on ' . $id . ' has no label.');
            // Either an in-application path this installation serves, or the project's own
            // /learn/ article. Nothing else may reach a screen: a guidance file that could name
            // any destination would be a way to put an arbitrary link on every screen.
            assert_true($entry['document']['external']
                ? (bool) preg_match(PL_GUIDANCE_ARTICLE, $entry['document']['href'])
                : str_starts_with($entry['document']['href'], '/'),
                'The longer-document link on ' . $id . ' is neither an in-application path nor a phpledger.com/learn article.');
        }
    }
});

test('the built stylesheet and script still carry what the bubble promises', function (): void {
    // The bubble's promises are in the stylesheet, not in the markup: out of flow so opening it
    // moves nothing, a sheet under 30rem so it cannot run off a 390px screen, and fixed for a
    // trigger inside a scrolling region so an ancestor's overflow cannot clip it. A rebuild that
    // dropped the ext import would leave the markup intact and quietly break all three.
    $styles = (string) file_get_contents(dirname(__DIR__) . '/www/phpledger/public/assets/app.css');
    foreach (['.help-bubble', '.help-bubble-start', '.help-bubble-end', '.help-bubble-sheet', '.help-local'] as $class) {
        assert_true(str_contains($styles, $class), 'The built stylesheet has no ' . $class . '; rebuild it with npm run build:css.');
    }
    assert_true(str_contains($styles, '30rem'), 'The built stylesheet lost the narrow-screen sheet placement.');
    $script = (string) file_get_contents(dirname(__DIR__) . '/www/phpledger/public/assets/help.js');
    assert_true(str_contains($script, 'details[data-help]'), 'app.js lost the bubble enhancement.');
    // Nothing may move focus into the bubble: <details> is not a dialog and must not become one.
    assert_true(!preg_match('/details\[data-help\][^\n]*showModal/', $script), 'The bubble must not open as a modal dialog.');
});

test('every concept a screen asks for exists, and asking for one that does not fails loudly', function (): void {
    $wired = guidance_wired_concepts();
    assert_true($wired !== [], 'No screen wires a help bubble, so the component is not proving anything.');
    $known = pl_guidance_concept_ids();
    $missing = [];
    foreach ($wired as $concept => $files) {
        if (!in_array($concept, $known, true)) { $missing[] = $concept . ' (asked for by ' . implode(', ', array_unique($files)) . ')'; }
    }
    assert_same([], $missing, 'Screens ask for help concepts the catalogue does not have: ' . implode('; ', $missing));
    assert_throws(static fn () => pl_guidance_entry('no-such-concept-exists'), LogicException::class, 'Unknown help concept');
    // A path, an empty id or a traversal attempt is not a concept id and must not become a file read.
    foreach (['', '../../etc/passwd', 'Debit', 'debit_and_credit'] as $rejected) {
        assert_true(pl_guidance_concept($rejected) === null, 'The catalogue accepted "' . $rejected . '" as a concept id.');
    }
});

test('the guidance covers every offered currency’s country plus the Gulf states, and its files are keyed by them', function (): void {
    $countries = pl_guidance_countries();
    foreach (['US', 'EU', 'GB', 'PK', 'IN', 'MY', 'BD', 'LK', 'NP', 'SG', 'AE', 'SA', 'OM'] as $expected) {
        assert_true(isset($countries[$expected]), 'The guidance does not cover ' . $expected . ' (B67 names it).');
    }
    assert_same(13, count($countries), 'The jurisdiction list changed; B67 names ten base-currency countries plus AE, SA and OM.');
    foreach (array_keys($countries) as $country) {
        assert_true((bool) preg_match('/^[A-Z]{2}$/D', $country), $country . ' is not a two-letter country code.');
        assert_true(pl_guidance_country_name($country) !== '', 'No reader-facing name for ' . $country . '.');
    }
    foreach (glob(dirname(__DIR__) . '/resources/guidance/jurisdictions/*.php') ?: [] as $path) {
        $code = basename($path, '.php');
        assert_true(isset($countries[$code]), 'Jurisdiction file ' . $code . '.php is not one of the countries the guidance covers.');
        $notes = require $path;
        assert_true(is_array($notes), $code . '.php does not return an array of notes.');
        foreach ($notes as $concept => $note) {
            assert_true(is_string($concept) && pl_guidance_concept($concept) !== null, $code . '.php has a note for unknown concept "' . $concept . '".');
            assert_true(is_array($note) && is_string($note['note'] ?? null) && ($note['note'] ?? '') !== '', $code . '/' . $concept . ' has no note text.');
            assert_true(in_array($note['status'] ?? '', ['verified', 'unverified'], true), $code . '/' . $concept . ' has an unknown status.');
            // A shipped note is either properly sourced, and then it shows, or explicitly
            // unverified, and then it does not. "Verified" with nothing behind it is the failure
            // this catches: it would be silently dropped and nobody would know the bubble is empty.
            if (($note['status'] ?? '') === 'verified') {
                assert_true(pl_guidance_note($concept, $code) !== null,
                    $code . '/' . $concept . ' is marked verified but has no source or check date, so it is dropped and never reaches a reader.');
            }
        }
    }
});

test('only a verified, sourced and dated note reaches a screen', function (): void {
    // The shipped catalogue has no verified note yet: the research has not landed and B67 forbids
    // an unsourced local claim. The fixture overlay is how an installation adds one, and it carries
    // one good note and two that must be dropped.
    $before = getenv('PL_GUIDANCE_PATH');
    putenv('PL_GUIDANCE_PATH=' . dirname(__DIR__) . '/tests/fixtures/guidance');
    pl_guidance_reset();
    try {
        $note = pl_guidance_note('debit-and-credit', 'PK');
        assert_true($note !== null, 'A verified, sourced and dated note did not reach the bubble.');
        assert_same('Pakistan', $note['country_name']);
        assert_same('2026-09-21', $note['checked_on']);
        assert_true(pl_guidance_note('contra-account', 'PK') === null, 'An unverified note reached the bubble.');
        assert_true(pl_guidance_note('drawings', 'PK') === null, 'A note with no source reached the bubble.');
        assert_true(pl_guidance_note('debit-and-credit', 'SG') === null, 'A note appeared for a jurisdiction that has no file.');
        assert_true(pl_guidance_note('debit-and-credit', null) === null, 'A note appeared with no jurisdiction at all.');
        assert_true(pl_guidance_note('debit-and-credit', 'pk') === null, 'A lower-case code was accepted as a country.');
        // The overlay adds jurisdictions; it does not hide the bundled concepts behind them.
        assert_true(pl_guidance_concept('debit-and-credit') !== null, 'The overlay lost the bundled concept files.');
    } finally {
        putenv('PL_GUIDANCE_PATH' . (is_string($before) && $before !== '' ? '=' . $before : ''));
        pl_guidance_reset();
    }
});

test('the jurisdiction follows the company record, and an unknown one shows nothing local', function (): void {
    assert_same('PK', pl_guidance_company_country(['currency' => 'PKR']));
    assert_same('SG', pl_guidance_company_country(['currency' => 'SGD']));
    assert_same('EU', pl_guidance_company_country(['currency' => 'EUR']));
    // A country on the company row wins as soon as the registration profile carries one (B63/B64).
    assert_same('AE', pl_guidance_company_country(['currency' => 'USD', 'country_code' => 'AE']));
    assert_true(pl_guidance_company_country(['currency' => 'JPY']) === null);
    assert_true(pl_guidance_company_country(['currency' => 'USD', 'country_code' => 'zz']) === 'US');
    assert_true(pl_guidance_company_country(null) === null);
    pl_guidance_use_company(['currency' => 'PKR']);
    assert_same('PK', pl_guidance_request_country());
    pl_guidance_use_company(null);
    assert_true(pl_guidance_request_country() === null);
    assert_true(pl_guidance_entry('debit-and-credit')['note'] === null, 'A bubble showed a local note with no company jurisdiction.');
});

test('a help bubble renders on a screen, reads with JavaScript disabled, and keeps its local note to its own jurisdiction', function (): void {
    $suffix = bin2hex(random_bytes(6));
    $email = 'guidance-' . $suffix . '@example.test';
    $password = 'Sample-guidance-password-' . $suffix;
    $actor = pl_create_user($email, 'Sample guidance owner', $password);
    $pakistan = pl_create_company($actor, 'Sample guidance PK ' . $suffix, 'PKR', '2026-01-01');
    $singapore = pl_create_company($actor, 'Sample guidance SG ' . $suffix, 'SGD', '2026-01-01');

    $root = dirname(__DIR__) . '/www/phpledger/public';
    $port = random_int(20000, 50000);
    $base = 'http://127.0.0.1:' . $port;
    $log = sys_get_temp_dir() . '/phpledger-guidance-' . $suffix . '.log';
    // The overlay an installation would use, carrying the fixture's obviously fictional note.
    $environment = array_replace(getenv(), ['PL_SESSION_SECURE' => '0',
        'PL_GUIDANCE_PATH' => dirname(__DIR__) . '/tests/fixtures/guidance']);
    $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, dirname(__DIR__), $environment);
    if (!is_resource($server)) {
        throw new RuntimeException('Sample HTTP server unavailable.');
    }
    fclose($pipes[0]);
    $cookie = '';
    $request = static function (string $path, ?string $body = null) use ($base, &$cookie): array {
        $headers = ['Connection: close'];
        if ($cookie !== '') { $headers[] = 'Cookie: ' . $cookie; }
        if ($body !== null) { $headers[] = 'Content-Type: application/x-www-form-urlencoded'; }
        $context = stream_context_create(['http' => ['method' => $body === null ? 'GET' : 'POST', 'header' => implode("\r\n", $headers),
            'content' => $body ?? '', 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 20]]);
        $response = @file_get_contents($base . $path, false, $context);
        $responseHeaders = $http_response_header ?? [];
        foreach ($responseHeaders as $header) {
            if (preg_match('/^Set-Cookie: ([^;]+)/i', $header, $match)) { $cookie = $match[1]; }
        }
        preg_match('/\s(\d{3})\s/', $responseHeaders[0] ?? '', $status);
        return [(int) ($status[1] ?? 0), $response === false ? '' : $response];
    };
    /** @return list<string> the bubble markup on a page, so the checks below look at it and not at the rest of the screen. */
    $bubbles = static function (string $html): array {
        preg_match_all('#<details class="help".*?</details>#su', $html, $matches);
        return $matches[0];
    };
    $select = static function (int $companyId) use ($request): string {
        [, $body] = $request('/companies');
        preg_match('/name="csrf" value="([a-f0-9]+)"/', $body, $match);
        [$status] = $request('/company/select', http_build_query(['company_id' => $companyId, 'csrf' => $match[1] ?? '']));
        assert_true(in_array($status, [200, 302, 303], true), 'Company selection did not complete: status ' . $status);
        [$status, $page] = $request('/reports/trial-balance');
        assert_same(200, $status, 'The trial balance did not render.');
        return $page;
    };
    try {
        $status = 0;
        for ($retry = 0; $retry < 100; $retry++) {
            [$status, $body] = $request('/login');
            if ($status) { break; }
            usleep(20000);
        }
        assert_same(200, $status, 'The sample server did not start: ' . substr((string) file_get_contents($log), -400));
        preg_match('/name="csrf" value="([a-f0-9]+)"/', $body, $match);
        [$status] = $request('/login', http_build_query(['email' => $email, 'password' => $password, 'csrf' => $match[1] ?? '']));
        assert_true(in_array($status, [200, 302, 303], true), 'Sign-in did not complete: status ' . $status);

        $page = $select((int) $pakistan['company_id']);
        $found = $bubbles($page);
        assert_true(count($found) >= 2, 'The trial balance rendered ' . count($found) . ' help bubbles; it wires two.');
        $markup = implode('', $found);
        // With JavaScript disabled the explanation is still in the document and still operable: the
        // trigger is a native <summary>, nothing is hidden behind a script, and nothing is open,
        // so opening one cannot have pushed the page around.
        assert_true(str_contains($markup, '<summary>'), 'The question mark is not a native summary, so it needs JavaScript to open.');
        assert_true(str_contains($markup, 'they are only the two columns a balanced entry fills'), 'The explanation is not in the served document.');
        assert_true(!str_contains($markup, '<details class="help" open') && !str_contains($markup, ' open>'), 'A bubble is open before anyone asked for it.');
        assert_true(!str_contains($markup, 'style='), 'A bubble carries an inline style, which this application’s CSP forbids.');
        assert_true(str_contains($markup, 'class="sr-only"'), 'The question mark has no accessible name.');
        // The company keeps its books in rupees, so Pakistan is its jurisdiction and its note shows.
        assert_true(str_contains($markup, 'In Pakistan'), 'The local note is not labelled with its country.');
        assert_true(str_contains($markup, 'Sample fixture note for the automated tests'), 'The jurisdiction note did not reach the bubble.');
        assert_true(str_contains($markup, 'Checked 21 Sep 2026'), 'The local note does not show when it was checked.');
        assert_true(!str_contains($markup, 'deliberately not verified'), 'An unverified note reached a screen.');
        assert_true(!str_contains($markup, 'carrying no source'), 'A note with no source reached a screen.');

        $page = $select((int) $singapore['company_id']);
        $markup = implode('', $bubbles($page));
        assert_true(str_contains($markup, 'they are only the two columns a balanced entry fills'), 'The shared explanation vanished for a company elsewhere.');
        assert_true(!str_contains($markup, 'In Pakistan') && !str_contains($markup, 'Sample fixture note'),
            'A company in Singapore was shown Pakistan’s local note.');
        assert_true(!str_contains($markup, 'In Singapore'), 'A jurisdiction with no note was given one anyway.');
    } finally {
        proc_terminate($server);
        proc_close($server);
        @unlink($log);
    }
});


require_once dirname(__DIR__) . '/www/phpledger/includes/functions/web_functions.php';

test('every application view has useful page guidance including signed-out setup', function (): void {
    $pages = pl_guidance_pages();
    foreach (glob(dirname(__DIR__) . '/www/phpledger/templates/views/*.php') ?: [] as $file) {
        $view = basename($file, '.php');
        assert_true(isset($pages[$view]), 'Missing page guidance for ' . $view);
        assert_true(pl_guidance_concept(pl_guidance_page($view)) !== null);
    }
    require_once dirname(__DIR__) . '/www/phpledger/templates/partials/ui/components.php';
    // The installer hangs the bubble from the question mark beside its title (owner review,
    // 25 September 2026); the application shell keeps the viewport sheet for now.
    foreach (['install' => 'start', 'login' => 'sheet', 'editor' => 'sheet', 'transactions' => 'sheet', 'reports' => 'sheet'] as $view => $placement) {
        ob_start(); pl_ui_page_help($view, $placement); $html = (string) ob_get_clean();
        assert_same(1, substr_count($html, 'data-page-help='));
        assert_true(str_contains($html, 'help-bubble-' . $placement));
        assert_true(!str_contains($html, '<details class="help" data-help open'));
    }
});

test('report help is outside the independent expand summary and adds no explanatory row', function (): void {
    require_once dirname(__DIR__) . '/www/phpledger/templates/partials/ui/components.php';
    require_once dirname(__DIR__) . '/www/phpledger/templates/partials/ui/report-tree.php';
    $tree = pl_report_tree([['code'=>'1-100-10001-00','name'=>'Sample cash','type'=>'asset','amount'=>'1.0000']], ['amount']);
    ob_start(); pl_ui_report_tree($tree, [['key'=>'amount','label'=>'Amount']], ['caption'=>'Sample']); $html=(string)ob_get_clean();
    assert_true(!str_contains($html, 'What belongs in'));
    assert_true(!str_contains($html, 'report-tree-note'));
    preg_match_all('~<summary>(.*?)</summary>~s', $html, $summaries);
    foreach ($summaries[1] as $summary) { assert_true(!str_contains($summary, '<details')); }
    assert_true(str_contains($html, 'report-tree-heading-help'));
});


test('every registered GET page has an explicit route-to-help entry', function (): void {
    $routes = pl_guidance_read('routes.php') ?? [];
    $source = (string) file_get_contents(dirname(__DIR__) . '/www/phpledger/public/index.php');
    $start = strpos($source, '$routes = [');
    $end = strpos($source, '// /print/', $start);
    preg_match_all("~'([^']+)'\\s*=>\\s*\\['GET'~", substr($source, $start, $end - $start), $matches);
    foreach ($matches[1] as $route) {
        if (in_array($route, ['/logo','/reports/export','/ownership/export'], true)) { continue; }
        assert_true(isset($routes[$route]), 'Missing guidance route ' . $route);
        assert_true(isset(pl_guidance_pages()[$routes[$route]]));
    }
    assert_same('install', $routes['/install']);
});
