<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/www/phpledger/includes/functions/web_functions.php';

/**
 * Translation groundwork (release plan 1.2, milestone M2).
 *
 * The helper tests below cover placeholders, missing keys, plural forms, unknown locales and the
 * direction function. The sweep at the end renders the real routes through a throwaway fixture
 * under the pseudo-locale and checks that every page still renders and that the document's lang
 * and dir attributes follow the locale.
 *
 * The interface strings are NOT externalised yet: that is M11. So the sweep must not fail on
 * untranslated English. It counts the visible text runs that did not pass through pl_t() and
 * prints the number, and it holds a ceiling that can only be lowered, so M11 can drive the count
 * towards zero without this test having to be rewritten.
 */

/** Every locale change is undone: later suites and the sweep must start from English. */
function i18n_with_locale(string $locale, callable $action): mixed
{
    try {
        pl_set_locale($locale);
        return $action();
    } finally {
        pl_i18n_reset();
    }
}

function i18n_temporary_catalogue(string $locale, string $source): string
{
    $directory = sys_get_temp_dir() . '/phpledger-lang-' . bin2hex(random_bytes(6));
    if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Sample catalogue directory unavailable.');
    }
    file_put_contents($directory . '/' . $locale . '.php', $source);
    return $directory;
}

test('English is the source language: it returns its keys, loads no catalogue and reads left to right', function (): void {
    pl_i18n_reset();
    assert_same('en', pl_locale());
    assert_same('ltr', pl_text_direction());
    assert_same('Post the journal.', pl_t('Post the journal.'));
    assert_same('Posted 3 lines for Sample & Co.', pl_t('Posted {count} lines for {party}.', ['count' => 3, 'party' => 'Sample & Co']));
    assert_same('1 open item', pl_tn('{count} open item', '{count} open items', 1, ['count' => 1]));
    assert_same('4 open items', pl_tn('{count} open item', '{count} open items', 4, ['count' => 4]));
    // The helper returns plain text; escaping stays with the caller.
    assert_same('Sample &amp; Co', pl_e(pl_t('{party}', ['party' => 'Sample & Co'])));
    assert_same([], pl_i18n_catalogue('en'));
});

test('placeholders are named, never positional, and an unfilled one stays visible', function (): void {
    assert_same('Saved {reference} for Sample Co.', pl_t('Saved {reference} for {party}.', ['party' => 'Sample Co']));
    // A positional sprintf token is data, not a placeholder: it is passed through untouched.
    assert_same('Saved %s of %d.', pl_t('Saved %s of %d.', ['count' => 2]));
    assert_same('Balance 12.3400 on 2026-01-05.', pl_t('Balance {amount} on {date}.', ['amount' => '12.3400', 'date' => '2026-01-05']));
    assert_same('Value: .', pl_t('Value: {v}.', ['v' => null]));
    // An unsafe variable name cannot build a replacement pattern.
    assert_same('Keep {a.b}.', pl_t('Keep {a.b}.', ['a.b' => 'x']));
});

test('a catalogue translates by English key, inherits its base language and falls back per key', function (): void {
    $directory = i18n_temporary_catalogue('ur', "<?php return ['Save the invoice.' => 'invoice-ur', 'Modules' => 'modules-ur', '{count} line' => ['one' => 'line-ur-one', 'other' => 'line-ur-other']];");
    file_put_contents($directory . '/ur-pk.php', "<?php return ['Modules' => 'modules-ur-pk'];");
    try {
        pl_i18n_reset();
        pl_i18n_register_catalogue_directory($directory);
        pl_set_locale('ur-PK');
        assert_same('ur-pk', pl_locale());
        assert_same('rtl', pl_text_direction());
        assert_same('invoice-ur', pl_t('Save the invoice.'));       // inherited from the base language
        assert_same('modules-ur-pk', pl_t('Modules'));              // restated by the region file
        assert_same('An unwritten string.', pl_t('An unwritten string.'));
        assert_same('line-ur-one', pl_tn('{count} line', '{count} lines', 1));
        assert_same('line-ur-other', pl_tn('{count} line', '{count} lines', 7));
        assert_same('line-ur-other', pl_tn('{count} line', '{count} lines', 2));
    } finally {
        pl_i18n_reset();
        @unlink($directory . '/ur.php');
        @unlink($directory . '/ur-pk.php');
        @rmdir($directory);
    }
});

test('a malformed, unreadable or unknown catalogue still renders the English source', function (): void {
    $directory = i18n_temporary_catalogue('ur', "<?php return 'not a catalogue';");
    try {
        pl_i18n_reset();
        pl_i18n_register_catalogue_directory($directory);
        assert_same('Unchanged.', i18n_with_locale('ur', fn (): string => pl_t('Unchanged.')));
        pl_i18n_register_catalogue_directory($directory);
        file_put_contents($directory . '/ur.php', "<?php return ['Kept' => 'kept-ur', 'Dropped' => 123, '' => 'x', 'Plural' => ['nonsense' => 'y']];");
        pl_i18n_reset();
        pl_i18n_register_catalogue_directory($directory);
        pl_set_locale('ur');
        assert_same('kept-ur', pl_t('Kept'));
        assert_same('Dropped', pl_t('Dropped'));
        assert_same('Plural', pl_tn('Plural', 'Plurals', 1));
    } finally {
        pl_i18n_reset();
        @unlink($directory . '/ur.php');
        @rmdir($directory);
    }
    // An unknown but well-formed locale has no catalogue anywhere and falls back entirely.
    assert_same('Nothing is written in Zulu yet.', i18n_with_locale('zu', fn (): string => pl_t('Nothing is written in Zulu yet.')));
    assert_same('ltr', i18n_with_locale('zu', fn (): string => pl_text_direction()));
    // A locale identifier is validated, never turned into a path.
    foreach (['../../etc/passwd', 'en/../../x', 'e', 'toolongsubtagvalue', '', 'en-'] as $invalid) {
        assert_throws(fn () => pl_set_locale($invalid), DomainException::class);
    }
    assert_same('en', pl_locale());
});

test('the plural table covers the six CLDR forms and keeps Arabic addable without touching pl_tn', function (): void {
    foreach ([0 => 'other', 1 => 'one', 2 => 'other', 11 => 'other', 100 => 'other'] as $count => $form) {
        assert_same($form, pl_plural_form($count, 'en'));
        assert_same($form, pl_plural_form($count, 'ur'));
    }
    foreach ([0 => 'zero', 1 => 'one', 2 => 'two', 3 => 'few', 10 => 'few', 11 => 'many', 99 => 'many', 100 => 'other', 103 => 'few'] as $count => $form) {
        assert_same($form, pl_plural_form($count, 'ar'));
    }
    $table = pl_i18n_plural_table();
    assert_same(['one', 'other'], $table['en']['forms']);
    assert_same(['zero', 'one', 'two', 'few', 'many', 'other'], $table['ar']['forms']);
    assert_same('rtl', pl_text_direction('ar'));
    // An unknown locale selects with the English rule rather than failing.
    assert_same('one', pl_plural_form(1, 'zu'));
    assert_same('other', pl_plural_form(5, 'zu'));
    // A catalogue that omits the form a count selects still answers from 'other'.
    $directory = i18n_temporary_catalogue('ar', "<?php return ['{count} entry' => ['one' => 'one-ar', 'other' => 'other-ar']];");
    try {
        pl_i18n_reset();
        pl_i18n_register_catalogue_directory($directory);
        pl_set_locale('ar');
        assert_same('one-ar', pl_tn('{count} entry', '{count} entries', 1));
        assert_same('other-ar', pl_tn('{count} entry', '{count} entries', 3));
    } finally {
        pl_i18n_reset();
        @unlink($directory . '/ar.php');
        @rmdir($directory);
    }
});

test('the pseudo-locale transforms every translated string and leaves placeholders intact', function (): void {
    i18n_with_locale('qps', function (): void {
        assert_same('qps', pl_locale());
        assert_same('ltr', pl_text_direction());
        $text = pl_t('Trial balance');
        assert_true(str_starts_with($text, '⟦') && str_ends_with($text, '⟧'), 'The pseudo-locale does not bracket its strings.');
        assert_true(mb_strlen($text, 'UTF-8') > mb_strlen('Trial balance', 'UTF-8') + 2, 'The pseudo-locale does not lengthen its strings.');
        assert_same($text, pl_t('Trial balance'), 'The pseudo-locale is not deterministic.');
        assert_same('⟦Posted 3 lines.·······⟧', pl_t('Posted {count} lines.', ['count' => 3]));
        assert_true(str_contains(pl_tn('{count} line', '{count} lines', 2, ['count' => 2]), '2 lines'), 'The plural pseudo string lost its count.');
    });
    // It is generated, so it ships no catalogue file and cannot be published by accident.
    assert_true(!is_file(PL_ROOT . '/resources/lang/qps.php'), 'The pseudo-locale must not have a catalogue file.');
});

test('a module may declare a lang directory without any bundled manifest digest moving', function (): void {
    $registry = pl_module_registry();
    foreach ($registry as $id => $manifest) {
        assert_true(!array_key_exists('lang', $manifest), 'Bundled module ' . $id . ' declares lang: every enabled company would have to review a module upgrade.');
    }
    $base = ['id' => 'sample', 'name' => 'Sample', 'version' => '1.0.0', 'contract' => 1, 'optional' => true, 'requires' => [], 'history' => 'Sample.',
        'capabilities' => [], 'migrations' => [], 'routes' => [], 'permissions' => [], 'settings' => [], 'reports' => [], 'api_operations' => [], 'mcp_operations' => []];
    $core = $registry['core'];
    unset($core['digest']);
    $validate = static fn (array $sample): mixed => pl_validate_module_registry(['core' => $core, 'sample' => $sample]);
    $validate($base);
    $validate(['lang' => 'resources/lang/modules/sample'] + $base);
    foreach (['', '../secrets', '/etc', 'resources/lang/../..', 'Resources/Lang', 'resources/lang/sample.php', 42, str_repeat('a/', 40) . 'a'] as $bad) {
        assert_throws(fn () => $validate(['lang' => $bad] + $base), DomainException::class);
    }
    // The digest is the manifest's own data, so declaring lang later is a module version decision.
    $withLang = hash('sha256', json_encode(['lang' => 'resources/lang/modules/core'] + $core, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    assert_true($withLang !== $registry['core']['digest'], 'A lang declaration must be visible in the module digest.');
});

test('registered catalogue directories merge after the core one, and a path cannot escape', function (): void {
    $first = i18n_temporary_catalogue('ur', "<?php return ['Shared' => 'first', 'Only first' => 'first-only'];");
    $second = i18n_temporary_catalogue('ur', "<?php return ['Shared' => 'second'];");
    try {
        pl_i18n_reset();
        pl_i18n_register_catalogue_directory($first);
        pl_i18n_register_catalogue_directory($second);
        pl_set_locale('ur');
        assert_same('second', pl_t('Shared'));
        assert_same('first-only', pl_t('Only first'));
        assert_true(in_array(PL_ROOT . '/resources/lang', pl_i18n_catalogue_directories(), true), 'The core catalogue directory is not searched first.');
        assert_same(PL_ROOT . '/resources/lang', pl_i18n_catalogue_directories()[0]);
    } finally {
        pl_i18n_reset();
        @unlink($first . '/ur.php');
        @unlink($second . '/ur.php');
        @rmdir($first);
        @rmdir($second);
    }
    foreach (['', '..', 'a/../../b', '   '] as $bad) {
        assert_throws(fn () => pl_i18n_register_catalogue_directory($bad), DomainException::class);
    }
});

test('the formatting seams exist and leave todays written amounts and dates unchanged', function (): void {
    // pl_money and pl_date_label now read the seam, and the seam still returns todays rules.
    $rules = pl_number_format_rules();
    assert_same(['grouping' => [3], 'group' => ',', 'decimal' => '.'], $rules);
    assert_same('d M Y', pl_date_format_pattern());
    assert_same($rules, pl_number_format_rules('ur'));
    assert_same('d M Y', pl_date_format_pattern('ur'));
    foreach (['0' => '0.00', '0.5' => '0.50', '12.3400' => '12.34', '1234.5678' => '1,234.5678', '-9876543.21' => '-9,876,543.21',
        '123456789' => '123,456,789.00', '1000' => '1,000.00', '999' => '999.00', 'not-an-amount' => 'not-an-amount'] as $amount => $expected) {
        assert_same($expected, pl_money((string) $amount));
    }
    // The generic grouping reproduces the previous fixed three-digit regex exactly.
    foreach (['1', '12', '123', '1234', '12345', '123456', '1234567', '12345678', '1234567890123'] as $digits) {
        assert_same((string) preg_replace('/\B(?=([0-9]{3})+(?![0-9]))/', ',', $digits), pl_group_digits($digits, [3], ','));
    }
    // The seam is what M11 needs for South Asian grouping; no call site changes when it lands.
    assert_same('12,34,56,789', pl_group_digits('123456789', [3, 2], ','));
    assert_same('1,23,456', pl_group_digits('123456', [3, 2], ','));
    assert_same('123456789', pl_group_digits('123456789', [3], ''));
    // Accounting DATE values are written, never converted: the label never moves a day.
    assert_same('05 Jan 2026', pl_date_label('2026-01-05'));
    assert_same('31 Dec 2026', pl_date_label('2026-12-31'));
    assert_same('01 Mar 2027', pl_date_label('2027-03-01'));
    assert_same('not-a-date', pl_date_label('not-a-date'));
    $sweepStored = '9876543.2100';
    pl_money($sweepStored);
    assert_same('9876543.2100', $sweepStored, 'Formatting changed the value it was given.');
});

test('every route renders under the pseudo-locale and the document language follows the locale', function (): void {
    // Ceiling for the visible English text runs that have not been through pl_t(). The strings are
    // externalised in M11; this number is the backlog, it is printed on every run, and it may only
    // be lowered. It must never be raised to make a change pass.

    $suffix = bin2hex(random_bytes(6));
    $email = 'i18n-sweep-' . $suffix . '@example.test';
    $password = 'Sample-sweep-password-' . $suffix;
    $actor = pl_create_user($email, 'Sample sweep owner', $password);
    $fixture = pl_create_company($actor, 'Sample sweep company ' . $suffix, 'USD', '2026-01-01');
    pl_save_and_post_general_draft($actor, $fixture['company_id'], $fixture['book_id'], [
        'date' => '2026-01-12', 'reference' => 'Sample sweep journal', 'description' => 'Sample sweep general journal', 'creation_key' => 'i18n-sweep-' . $suffix,
        'lines' => [
            ['account_id' => $fixture['accounts']['5000'], 'debit' => '20.0000', 'credit' => '0.0000', 'description' => 'Sample sweep debit'],
            ['account_id' => $fixture['accounts']['1000'], 'debit' => '0.0000', 'credit' => '20.0000', 'description' => 'Sample sweep credit'],
        ],
    ]);

    $root = dirname(__DIR__) . '/www/phpledger/public';
    $port = random_int(20000, 50000);
    $base = 'http://127.0.0.1:' . $port;
    $log = sys_get_temp_dir() . '/phpledger-i18n-sweep-' . $suffix . '.log';
    // PL_LOCALE is the documented single lever for the interface locale until M11 resolves a
    // user/company preference; the sweep uses it to put the whole server into the pseudo-locale.
    $environment = array_replace(getenv(), ['PL_LOCALE' => 'qps', 'PL_SESSION_SECURE' => '0']);
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
    /**
     * Did any of this page's text go through pl_t()? The pseudo-locale brackets every translated
     * string, so one bracket proves the helper is live on the rendered page. Deliberately a
     * boolean and not a count: the rendered page also carries data (document numbers, party and
     * account names, rows that depend on the clock), which moves with the fixture and the day, so
     * counting it made this test fail at random. The backlog is counted from the source instead,
     * in "the untranslated interface text in the source only falls" below.
     */
    $usesHelper = static function (string $html): bool {
        $stripped = (string) preg_replace('#<(script|style)\\b[^>]*>.*?</\\1>#isu', ' ', $html);
        return str_contains($stripped, "\u{27e6}");
    };

    $routes = ['/login', '/', '/home', '/companies', '/accounts', '/transactions', '/transactions/new', '/general-journals',
        '/general-journals/new', '/reports', '/reports/trial-balance', '/reports/balance-sheet', '/reports/profit-loss',
        '/reports/cash-forecast', '/reports/ageing', '/periods', '/opening-balances', '/bank-reconciliation', '/parties',
        '/ar', '/ap', '/inventory', '/purchasing', '/tax', '/modules', '/connections', '/help', '/pos',
        // The 1.2 screens. Every one of these was absent from this sweep until 21 September 2026,
        // so nothing had ever requested them over HTTP. That is how two of them shipped unreachable:
        // a screen missing from the pl_render() allowlist answers "Unknown template" as a 500, which
        // this loop fails on, but only for a route it actually asks for. A new screen belongs here.
        '/owner', '/numbering', '/accounting-policies', '/company-profile', '/contra-review',
        '/reports/stock-by-location', '/tables', '/sample-guide',
        '/no-such-route'];
    $rendered = 0;
    $translated = 0;
    $statuses = [];
    try {
        for ($retry = 0; $retry < 100; $retry++) {
            [$status, $body] = $request('/login');
            if ($status) { break; }
            usleep(20000);
        }
        assert_same(200, $status, 'The sample server did not start: ' . substr((string) file_get_contents($log), -400));
        assert_true(str_contains($body, '<html lang="qps" dir="ltr"'), 'The sign-in document does not follow the locale.');
        preg_match('/name="csrf" value="([a-f0-9]+)"/', $body, $match);
        [$status] = $request('/login', http_build_query(['email' => $email, 'password' => $password, 'csrf' => $match[1] ?? '']));
        assert_true(in_array($status, [200, 302, 303], true), 'Sign-in did not complete: status ' . $status);
        [, $body] = $request('/companies');
        preg_match('/name="csrf" value="([a-f0-9]+)"/', $body, $match);
        [$status] = $request('/company/select', http_build_query(['company_id' => $fixture['company_id'], 'csrf' => $match[1] ?? '']));
        assert_true(in_array($status, [200, 302, 303], true), 'Company selection did not complete: status ' . $status);

        foreach ($routes as $route) {
            [$status, $body] = $request($route);
            $statuses[$route] = $status;
            // A dependency the test container does not provision (the OAuth signing keys behind
            // /connections) answers 503 with the shared unavailable document, which is itself a
            // page the sweep checks. An uncaught error would be 500, and that is a failure.
            assert_true($status !== 0 && $status !== 500, 'Route ' . $route . ' failed with status ' . $status . ': ' . substr((string) file_get_contents($log), -400));
            if ($status >= 300 && $status < 400) { continue; }
            if ($body === '' || !str_contains($body, '<html')) { continue; }
            ++$rendered;
            assert_true(str_contains($body, '<html lang="qps" dir="ltr"'), 'Route ' . $route . ' does not carry the active locale on its document element.');
            assert_true(!str_contains($body, 'lang="en"'), 'Route ' . $route . ' still hard-codes an English document language.');
            if ($usesHelper($body)) { ++$translated; }
        }
    } finally {
        proc_terminate($server);
        proc_close($server);
        @unlink($log);
    }
    assert_true($rendered >= 15, 'Only ' . $rendered . ' routes rendered a document; the sweep is not covering the screens.');
    echo 'i18n sweep: ' . $rendered . ' of ' . count($routes) . ' routes rendered under the pseudo-locale; '
        . $translated . " of them show translated text.\n";
    assert_true($translated >= 1, 'No rendered screen showed a translated string, so the helper is not reaching the interface at all.');
    assert_same(0, count(array_filter($statuses, static fn (int $status): bool => $status === 500)), 'A route answered with an uncaught error.');
});

/**
 * The backlog M11 has to clear, measured where it lives: literal interface text in the templates.
 * PHP blocks are removed first, so a string inside pl_t() does not count; what is left is text the
 * browser shows and no catalogue can reach. This reads files only, with no database, server or
 * fixture, so unlike a rendered-page count it returns the same number on every run and on every
 * machine.
 *
 * The ceiling may only be lowered. Raising it to make a change pass is the one thing this test
 * exists to prevent: a new screen written in bare English goes through pl_t() instead.
 */
test('the untranslated interface text in the source only falls', function (): void {
    $ceiling = 1700;
    $root = dirname(__DIR__) . '/www/phpledger/templates';
    $counts = [];
    $total = 0;
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') { continue; }
        $source = (string) file_get_contents((string) $file->getPathname());
        $markup = (string) preg_replace('#<\?(?:php|=).*?(?:\?>|$)#su', ' ', $source);
        $markup = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#isu', ' ', $markup);
        $shown = [];
        if (preg_match_all('/\b(?:placeholder|title|aria-label|alt)\s*=\s*"([^"]*)"/i', $markup, $attributes)) {
            $shown = $attributes[1];
        }
        $markup = (string) preg_replace('#<[^>]*>#u', "\n", $markup);
        $markup = html_entity_decode($markup, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $count = 0;
        foreach (array_merge($shown, preg_split('/\R+/u', $markup) ?: []) as $run) {
            $run = trim($run);
            // Two adjacent letters make it prose: stray punctuation, units and single letters are
            // not text a translator would carry.
            if ($run === '' || !preg_match('/\p{L}\p{L}/u', $run)) { continue; }
            ++$count;
        }
        if ($count > 0) {
            $counts[str_replace('\\', '/', substr((string) $file->getPathname(), strlen($root) + 1))] = $count;
            $total += $count;
        }
    }
    arsort($counts);
    $named = [];
    foreach (array_slice($counts, 0, 5, true) as $name => $count) { $named[] = $name . ' ' . $count; }
    echo 'i18n backlog: ' . $total . ' untranslated interface text runs in ' . count($counts)
        . ' template files (ceiling ' . $ceiling . '); most: ' . implode(', ', $named) . ".\n";
    assert_true($total <= $ceiling, 'Untranslated interface text rose to ' . $total . ', above the recorded ceiling of '
        . $ceiling . '. Put the new strings through pl_t() rather than raising the ceiling.');
});
