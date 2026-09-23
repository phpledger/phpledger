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
    // M11 fills the seams in. English is untouched; every locale that was not given a row is
    // untouched; the locales that were given one are the ones a person chose deliberately.
    $southAsian = ['grouping' => [3, 2], 'group' => ',', 'decimal' => '.'];
    assert_same($southAsian, pl_number_format_rules('ur'));
    assert_same($southAsian, pl_number_format_rules('ur-PK'), 'A region must inherit its language rules.');
    assert_same($southAsian, pl_number_format_rules('en-PK'), 'English for a South Asian reader groups in lakhs.');
    assert_same($rules, pl_number_format_rules('en'));
    assert_same($rules, pl_number_format_rules('en-GB'), 'A region with no row of its own falls back to its language.');
    assert_same($rules, pl_number_format_rules('ar'), 'The Arabic draft explicitly uses Latin digits with three-digit grouping.');
    assert_same($rules, pl_number_format_rules('zu'));
    assert_same('d F Y', pl_date_format_pattern('ur'));
    assert_same('d F Y', pl_date_format_pattern('ur-PK'));
    assert_same('d M Y', pl_date_format_pattern('en-PK'), 'Only the digits change for en-PK, not the month.');
    assert_same('d M Y', pl_date_format_pattern('zu'));
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

test('a right-to-left locale groups in lakhs, writes its own month and isolates every figure', function (): void {
    // Deliberately no fixture catalogue: this runs against resources/lang/ur.php, the file that
    // actually ships, so the assertions below are about the release and not about a stub.
    try {
        pl_i18n_reset();
        pl_set_locale('ur');
        assert_same('rtl', pl_text_direction());
        // Grouped in the lakh/crore style and fenced off, so the sign, the separators and the
        // decimal point cannot be reordered by the Urdu text around them.
        assert_same("\u{2066}1,23,45,678.00\u{2069}", pl_money('12345678'));
        assert_same("\u{2066}-9,87,654.32\u{2069}", pl_money('-987654.32'));
        assert_same("\u{2066}0.50\u{2069}", pl_money('0.5'));
        // What cannot be read as an amount is returned exactly as given, isolated or not.
        assert_same('not-an-amount', pl_money('not-an-amount'));
        // The date is written with the locale's own pattern and its month is translated; no date
        // is parsed through a timezone and none moves a day.
        assert_same("\u{2066}05 جنوری 2026\u{2069}", pl_date_label('2026-01-05'));
        assert_same("\u{2066}31 دسمبر 2026\u{2069}", pl_date_label('2026-12-31'));
        assert_same('not-a-date', pl_date_label('not-a-date'));
        assert_same("\u{2066}4100\u{2069}", pl_ltr('4100'));
        assert_same('', pl_bidi_isolate(''), 'An empty string has nothing to isolate.');
        // The draft is a draft: what it has not reached renders as the English source string, per
        // key, and never as a key name, a placeholder token or an empty label.
        assert_same('An explanatory sentence this draft has not reached.', pl_t('An explanatory sentence this draft has not reached.'));
        assert_same('آزمائشی میزان', pl_t('Trial balance'), 'The shipped draft does not translate a term it lists.');
    } finally {
        pl_i18n_reset();
    }
    // English is byte-for-byte what it was before M11: an isolate is added in one direction only.
    assert_same('1,234.5678', pl_money('1234.5678'));
    assert_same('-9,876,543.21', pl_money('-9876543.21'));
    assert_same('05 Jan 2026', pl_date_label('2026-01-05'));
    assert_same('4100', pl_ltr('4100'));
    assert_same('x', pl_bidi_isolate('x', 'ltr'));
    assert_same("\u{2066}x\u{2069}", pl_bidi_isolate('x', 'rtl'));
});

test('the language switch offers a short honest list and says which wording is only a draft', function (): void {
    pl_i18n_reset();
    $offered = pl_i18n_offered_locales();
    assert_true(!array_key_exists(PL_LOCALE_PSEUDO, $offered), 'The pseudo-locale must never be offered to a person.');
    assert_same('source', $offered['en']['review']);
    assert_same('draft', $offered['ur']['review'], 'Urdu is an unreviewed draft until a named reviewer has passed it.');
    assert_same('draft', $offered['ar']['review'], 'Arabic must not claim native review.');
    foreach ($offered as $tag => $entry) {
        assert_same(strtolower((string) $tag), pl_normalize_locale((string) $tag), 'An offered locale must be a valid tag.');
        assert_true($entry['label'] !== '' && $entry['english'] !== '', 'Offered locale ' . $tag . ' has no name to show.');
        assert_true(in_array($entry['review'], ['source', 'draft', 'reviewed'], true), 'Offered locale ' . $tag . ' has no review state.');
    }
    // Nothing claims a reviewed translation. That claim belongs to a named person, not to a test.
    assert_same([], array_filter($offered, static fn (array $entry): bool => $entry['review'] === 'reviewed'));
    assert_same('draft', pl_locale_review_state('ur-PK'), 'A region is exactly as reviewed as its language.');
    assert_same('source', pl_locale_review_state('en-GB'));
    assert_same('unknown', pl_locale_review_state('zu'));
});

test('the Arabic draft preserves placeholders six plural forms regional fallback and exact financial presentation', function (): void {
    pl_i18n_reset();$catalogue=pl_i18n_catalogue('ar');assert_true(count($catalogue)>250);
    foreach($catalogue as $key=>$value) {
        preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',$key,$source);$expected=$source[1];sort($expected);
        if(is_array($value)) { assert_same(pl_i18n_plural_table()['ar']['forms'],array_keys($value)); }
        foreach(is_array($value)?$value:[$value] as $text) {
            assert_true(is_string($text) && trim($text)!=='' && !str_contains($text,'<'));
            preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',$text,$translated);$actual=$translated[1];sort($actual);assert_same($expected,$actual,'Arabic placeholders differ for '.$key);
        }
    }
    pl_set_locale('ar-SA');assert_same('draft',pl_locale_review_state());assert_same('rtl',pl_text_direction());assert_same('حفظ',pl_t('Save'));
    assert_same('Missing sample English key',pl_t('Missing sample English key'));
    assert_same("\u{2066}-1,234,567.89\u{2069}",pl_money('-1234567.89'));assert_same("\u{2066}05 يناير 2026\u{2069}",pl_date_label('2026-01-05'));
    foreach([0=>'zero',1=>'one',2=>'two',3=>'few',11=>'many',100=>'other'] as $count=>$form) { assert_same(str_replace('{count}',(string)$count,$catalogue['{count} item'][$form]),pl_tn('{count} item','{count} items',$count,['count'=>$count])); }
    assert_same('0.0001',pl_amount('0.0001'));assert_throws(fn()=>pl_amount('١٢٫٣٤'),DomainException::class);
    assert_true(str_contains((string)file_get_contents(PL_ROOT.'/resources/lang/ar.php'),'MACHINE-AUTHORED DRAFT, UNREVIEWED'));
    assert_true(str_contains((string)file_get_contents(PL_ROOT.'/tools/package-files.json'),'"resources/lang/ar.php"'));
    pl_i18n_reset();
});

test('the bundled Urdu draft is a well-formed catalogue that only uses the forms Urdu has', function (): void {
    pl_i18n_reset();
    $path = PL_ROOT . '/resources/lang/ur.php';
    assert_true(is_file($path), 'The draft Urdu catalogue is missing.');
    $catalogue = pl_i18n_catalogue('ur');
    assert_true(count($catalogue) > 100, 'The draft Urdu catalogue is too thin to be worth shipping: ' . count($catalogue) . ' keys.');
    $forms = pl_i18n_plural_table()['ur']['forms'];
    foreach ($catalogue as $key => $value) {
        assert_true($key !== '', 'The catalogue has an empty key.');
        if (is_array($value)) {
            assert_same($forms, array_keys($value), 'Plural key "' . $key . '" does not state exactly the forms Urdu uses.');
            foreach ($value as $text) {
                assert_true(is_string($text) && trim($text) !== '', 'Plural key "' . $key . '" has an empty form.');
            }
            continue;
        }
        assert_true(is_string($value) && trim($value) !== '', 'Key "' . $key . '" has an empty translation.');
    }
    // It is a draft, and the file says so where a translator and a packager will both see it.
    $source = (string) file_get_contents($path);
    assert_true(str_contains($source, 'UNREVIEWED'), 'The draft catalogue does not mark itself unreviewed.');
    // A shipped file that is not in the package manifest is not in the release (repository rule).
    $manifest = (string) file_get_contents(PL_ROOT . '/tools/package-files.json');
    foreach (['resources/lang/ur.php', 'www/phpledger/public/assets/fonts/NotoNaskhArabic-arabic.woff2',
        'www/phpledger/public/assets/fonts/NotoNaskhArabic-LICENSE.txt',
        'www/phpledger/templates/partials/ui/locale-switch.php'] as $shipped) {
        assert_true(str_contains($manifest, '"' . $shipped . '"'), $shipped . ' is not in tools/package-files.json.');
    }
    pl_i18n_reset();
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

    $routes = ['/login', '/', '/home', '/companies', '/accounts', '/transactions', '/transactions/new', '/expenses/new', '/receipts/new', '/general-journals',
        '/general-journals/new', '/reports', '/reports/trial-balance', '/reports/balance-sheet', '/reports/profit-loss',
        '/reports/cash-forecast', '/reports/ageing', '/periods', '/cash-counts', '/opening-balances', '/bank-reconciliation', '/parties',
        '/ar', '/ap', '/inventory', '/purchasing', '/tax', '/modules', '/connections', '/help', '/pos',
        // The 1.2 screens. Every one of these was absent from this sweep until 21 September 2026,
        // so nothing had ever requested them over HTTP. That is how two of them shipped unreachable:
        // a screen missing from the pl_render() allowlist answers "Unknown template" as a 500, which
        // this loop fails on, but only for a route it actually asks for. A new screen belongs here.
        '/owner', '/numbering', '/accounting-policies', '/company-profile', '/contra-review',
        '/reports/stock-by-location', '/tables', '/sample-guide',
        // 1.3 M14: the Fixed assets module's four screens. They render with the module
        // disabled, which is what this fixture's company is, and say so.
        '/fixed-assets', '/fixed-assets/detail', '/fixed-assets/depreciation', '/reports/asset-register',
        // 1.2 M7: the Users module's screens. /invitation and /reset-password render without a
        // token as the empty form they are, which is what the sweep needs to see.
        '/users', '/roles', '/cost-visibility', '/profile', '/invitation', '/reset-password',
        // 1.2 M11: the language switch. It is a POST, so a GET is a 405 carrying the shared
        // unavailable document — which is itself a page whose lang and dir this sweep checks.
        '/locale',
        // 1.2.1 M10: the redesigned business wizard (B50) and the sample chooser beside it.
        // Neither had ever been requested over HTTP by this sweep, which is the same hole that
        // shipped two screens unreachable in 1.2. A later stage redirects to the stage the
        // draft has actually reached, and the sweep follows no redirect, so /onboarding is what
        // proves the screen renders; tests/onboarding_skeleton_test.php walks all five stages.
        '/onboarding', '/onboarding?stage=start', '/sample-chooser',
        // 1.2 M8: Admin > Packages.
        '/packages',
        // 1.2.1 M8a: the ownership register and its reports.
        '/ownership', '/reports/ownership',
        // 1.3 M17: the employee master (issue #98's blocker).
        '/employees',
        '/recurring', '/schedules', '/loans', '/reports/schedules', '/reports/loans',
        // 1.2.1 M9: the counter till. /counter/receipt needs a posted sale, so the sweep asks
        // for the till itself, which is the screen with the new strings on it. A book with no
        // warehouse renders it as an empty state, which is what the sweep's fixture has.
        '/counter',
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
            // The language menu is the one place an element legitimately declares a language of
            // its own: each <option> states the language it offers, so an Urdu name renders right
            // to left inside an English menu. Those options are removed before the check, and
            // everything else on the page still has to be free of a hard-coded English language.
            $withoutLanguageMenu = (string) preg_replace('#<option\b[^>]*>#i', ' ', $body);
            assert_true(!str_contains($withoutLanguageMenu, 'lang="en"'), 'Route ' . $route . ' still hard-codes an English document language.');
            if ($usesHelper($body)) { ++$translated; }
        }

        // 1.2 M11. The switch is not a decoration: choosing Urdu must actually turn the next
        // document round, and clearing the choice must hand the interface back to the hosting
        // default. This is the whole resolution chain — form, CSRF, route, stored preference,
        // bootstrap — proved over HTTP rather than asserted about in isolation.
        [, $body] = $request('/home');
        assert_true(str_contains($body, 'name="locale"'), 'No language switch is reachable on a workspace screen.');
        preg_match('/name="csrf" value="([a-f0-9]+)"/', $body, $match);
        [$switched] = $request('/locale', http_build_query(['locale' => 'ur', 'return' => '/home', 'csrf' => $match[1] ?? '']));
        assert_true(in_array($switched, [200, 302, 303], true), 'The language switch did not complete: status ' . $switched);
        [, $body] = $request('/home');
        assert_true(str_contains($body, '<html lang="ur" dir="rtl"'), 'Choosing Urdu did not turn the document round: '
            . substr($body, 0, 200));
        preg_match('/name="csrf" value="([a-f0-9]+)"/', $body, $match);
        $request('/locale', http_build_query(['locale'=>'ar','return'=>'/home','csrf'=>$match[1]??'']));
        [, $body]=$request('/home');
        assert_true(str_contains($body,'<html lang="ar" dir="rtl"'));
        assert_true(str_contains($body,'لم تخضع للمراجعة'),'Arabic must visibly retain its unreviewed status.');
        foreach(['/transactions','/general-journals/new','/reports/trial-balance','/employees'] as $route) { [$status,$arabicBody]=$request($route);assert_same(200,$status,'Arabic route '.$route);assert_true(str_contains($arabicBody,'<html lang="ar" dir="rtl"')); }
        preg_match('/name="csrf" value="([a-f0-9]+)"/', $body, $match);
        $request('/locale', http_build_query(['locale' => '', 'return' => '/home', 'csrf' => $match[1] ?? '']));
        [, $body] = $request('/home');
        assert_true(str_contains($body, '<html lang="qps" dir="ltr"'), 'Clearing the language did not return to the hosting default.');
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
    // 1675 when M2 recorded this ratchet; 12 after M11 externalised the screens. The ceiling is
    // now the exact count, with no headroom, because every run still counted is known and none of
    // them is prose a translator would carry:
    //
    //   views/install.php          6  a document root, two configuration file paths, the database
    //                                 account name `root`, a key filename and an example URL,
    //                                 inside <code>. Translating a path would break the instruction.
    //   views/opening-balances.php 4  two CSV header lines and the two `receivable`/`payable`
    //                                 values the importer matches literally. Translating them
    //                                 would tell a person to type words the parser rejects.
    //   views/accounting-policies  1  a docs/ path in the repository.
    //   views/owner.php            1  a docs/ path in the repository.
    //
    // A new screen written in bare English therefore fails this test immediately, which is the
    // whole point of it. Put the strings through pl_t() rather than raising the number.
    $ceiling = 12;
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


test('separate expense and receipt labels use the shipped draft catalogues', function (): void {
    foreach (['ar','ur'] as $locale) {
        i18n_with_locale($locale, static function (): void {
            foreach (['Paid to','Received from','Paid from account','Received into account','Expense category','Income category'] as $key) {
                assert_true(pl_t($key) !== $key && pl_t($key) !== '', 'Missing transaction screen label: ' . $key);
            }
        });
    }
});
