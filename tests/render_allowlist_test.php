<?php
declare(strict_types=1);

/**
 * pl_render() keeps an explicit template allowlist, and a routed screen that is missing from it
 * fails at request time with "Unknown template" rather than at build time. Two screens shipped that
 * way in 1.2 (the Admin numbering screen and the owner-transactions screen), because no suite
 * rendered a screen it had just routed. This test closes that hole for every screen at once: it
 * reads every pl_render('...') call in the application and asserts the allowlist covers it.
 *
 * It is a source check, not an HTTP check: it needs no database and no web server, so it runs in
 * the ordinary suite and fails the moment a branch adds a screen without its allowlist entry.
 */

function render_allowlist(): array
{
    $source = (string) file_get_contents(dirname(__DIR__) . '/www/phpledger/includes/functions/web_functions.php');
    $start = strpos($source, 'function pl_render(');
    assert_true($start !== false, 'pl_render() is where the template allowlist lives.');
    $body = substr($source, (int) $start);
    // Strip // comments first: the list carries explanatory lines, and a quoted word inside one
    // would otherwise read as an allowed template name.
    $body = (string) preg_replace('~//.*~', '', $body);
    assert_true((bool) preg_match('/\$allowed = \[(.*?)\];/s', $body, $match), 'pl_render() declares $allowed.');
    $names = [];
    foreach (explode(',', (string) preg_replace('/\s+/', ' ', $match[1])) as $entry) {
        $entry = trim($entry, " '\"");
        if ($entry !== '') { $names[] = $entry; }
    }
    return $names;
}

/** @return array<string, list<string>> view name => files that render it */
function render_calls(): array
{
    $calls = [];
    $root = dirname(__DIR__) . '/www/phpledger';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') { continue; }
        $path = str_replace('\\', '/', (string) $file->getPathname());
        if (str_contains($path, '/templates/')) { continue; }
        $text = (string) file_get_contents($path);
        if (preg_match_all("/pl_render\(\s*'([a-z0-9-]+)'/", $text, $matches)) {
            foreach ($matches[1] as $view) {
                $calls[$view][] = substr($path, strlen(str_replace('\\', '/', dirname(__DIR__))) + 1);
            }
        }
    }
    return $calls;
}

test('every screen the application renders is in the template allowlist and has its view file', function (): void {
    $allowed = render_allowlist();
    $calls = render_calls();
    assert_true($calls !== [], 'The scan found pl_render() calls to check.');
    $missing = [];
    foreach ($calls as $view => $files) {
        if (!in_array($view, $allowed, true)) {
            $missing[] = $view . ' (rendered by ' . implode(', ', array_unique($files)) . ')';
        }
    }
    assert_same([], $missing, 'A routed screen missing from the allowlist answers "Unknown template" at request time.');
    foreach (array_keys($calls) as $view) {
        $file = dirname(__DIR__) . '/www/phpledger/templates/views/' . $view . '.php';
        assert_true(is_file($file), 'The view file for ' . $view . ' exists.');
    }
});

test('the template allowlist names no screen the application never renders', function (): void {
    // A stale entry is not a request-time failure, so this is a tidiness check with a named
    // exception: 'ar' and 'ap' are rendered through the starter adapters' own dispatch, not by a
    // literal pl_render('ar') call, so the scan cannot see them.
    $dispatched = ['ar', 'ap'];
    $calls = render_calls();
    $stale = [];
    foreach (render_allowlist() as $view) {
        if (!isset($calls[$view]) && !in_array($view, $dispatched, true)) { $stale[] = $view; }
    }
    assert_same([], $stale, 'The allowlist carries no screen that nothing renders.');
});
