<?php
declare(strict_types=1);

/**
 * Interface translation groundwork (release plan 1.2, milestone M2; decisions B3 and B20).
 *
 * What this file is:
 *   - pl_t()/pl_tn() look an English source string up in the active locale's catalogue.
 *   - Catalogues are English-keyed PHP arrays in resources/lang/<locale>.php. English is the
 *     source language: it returns the key unchanged and loads no file at all.
 *   - A missing key, a missing catalogue and an unknown locale all fall back to the English
 *     source string. A screen never shows a key, a placeholder token or an empty string.
 *
 * Escaping is the caller's job. pl_t() and pl_tn() return PLAIN TEXT, never HTML, and they do
 * not escape anything: the interpolated values are frequently user data. Every HTML call site
 * writes pl_e(pl_t('...')), exactly as it writes pl_e() around any other text today. Escaping
 * inside the helper would double-escape the CLI, JSON, mail and header call sites and would hide
 * the escape from review at the place where the context is known.
 *
 * Placeholders are named: pl_t('Posted {count} lines for {party}.', ['count' => 3, 'party' => $name]).
 * Positional sprintf placeholders are deliberately unsupported: translators reorder clauses, and
 * %s carries no meaning to review against.
 *
 * Formatting and accounting values: nothing here converts, rounds or reformats a stored amount,
 * and nothing here touches an accounting DATE. pl_number_format_rules() and pl_date_format_pattern()
 * at the bottom of this file are presentation seams only (see their own comments).
 *
 * State: one static registry, in pl_i18n_registry(). The functions are otherwise independent of
 * request state: they read no session, no request parameter and no database. The active locale is
 * whatever pl_set_locale() was last given; until something sets it, it is read once from the
 * PL_LOCALE environment variable, and failing that it is English. Resolving a user's or company's
 * locale preference and calling pl_set_locale() from the bootstrap is M11 work.
 */

/** The source language. It has no catalogue file: its keys are its strings. */
const PL_LOCALE_SOURCE = 'en';

/** Non-shipping pseudo-locale for tests; see pl_i18n_pseudo(). */
const PL_LOCALE_PSEUDO = 'qps';

/**
 * The only state in this file: the active locale, the catalogue directories and the catalogues
 * already loaded. Returned by reference so callers mutate one registry rather than a global.
 *
 * @return array{locale: ?string, directories: list<string>, catalogues: array<string, array<string, mixed>>}
 */
function &pl_i18n_registry(): array
{
    static $registry = ['locale' => null, 'directories' => [], 'catalogues' => []];
    return $registry;
}

/** Forget the active locale, the registered directories and every loaded catalogue. */
function pl_i18n_reset(): void
{
    $registry = &pl_i18n_registry();
    $registry = ['locale' => null, 'directories' => [], 'catalogues' => []];
}

/**
 * A locale tag this application accepts: a language subtag with optional script/region subtags,
 * lower-cased for lookup. Anything else is rejected rather than turned into a file path.
 */
function pl_normalize_locale(string $locale): string
{
    $candidate = strtolower(trim(str_replace('_', '-', $locale)));
    if (!preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8}){0,2}$/D', $candidate)) {
        throw new DomainException('That is not a supported locale identifier.');
    }
    return $candidate;
}

/** The base language of a locale: ur-PK falls back to ur, and ur falls back to English. */
function pl_locale_language(string $locale): string
{
    return explode('-', $locale)[0];
}

/** The active locale. Defaults to the PL_LOCALE environment value, then to English. */
function pl_locale(): string
{
    $registry = &pl_i18n_registry();
    if ($registry['locale'] === null) {
        $configured = (string) (getenv('PL_LOCALE') ?: '');
        try {
            $registry['locale'] = $configured === '' ? PL_LOCALE_SOURCE : pl_normalize_locale($configured);
        } catch (DomainException) {
            // A malformed hosting value must not take the interface down; English still renders.
            $registry['locale'] = PL_LOCALE_SOURCE;
        }
    }
    return $registry['locale'];
}

/** Select the interface locale for the rest of this process. Returns the normalized tag. */
function pl_set_locale(string $locale): string
{
    $registry = &pl_i18n_registry();
    $registry['locale'] = pl_normalize_locale($locale);
    return $registry['locale'];
}

/**
 * Written direction of a locale, for the document's dir attribute.
 * The list is the right-to-left languages, not a claim that each one is translated or reviewed:
 * a language is supported only after its catalogue, font coverage and screens are reviewed.
 */
function pl_text_direction(?string $locale = null): string
{
    $rtl = ['ar', 'arc', 'ckb', 'dv', 'fa', 'he', 'ku', 'ps', 'sd', 'ug', 'ur', 'yi'];
    return in_array(pl_locale_language($locale === null ? pl_locale() : pl_normalize_locale($locale)), $rtl, true) ? 'rtl' : 'ltr';
}

/**
 * CLDR plural selection. Each locale names the forms it actually uses out of the six CLDR
 * categories (zero, one, two, few, many, other) and the rule that picks one for an integer count.
 * English and Urdu use one/other. Arabic is already in the table, with all six forms, so that the
 * table shape is proven before its catalogue exists: a catalogue is added by adding rows here and
 * a resources/lang file, never by changing pl_tn(). 'other' is always the last resort.
 *
 * @return array<string, array{forms: list<string>, rule: callable(int): string}>
 */
function pl_i18n_plural_table(): array
{
    $oneOther = static fn (int $count): string => $count === 1 ? 'one' : 'other';
    return [
        'en' => ['forms' => ['one', 'other'], 'rule' => $oneOther],
        'ur' => ['forms' => ['one', 'other'], 'rule' => $oneOther],
        PL_LOCALE_PSEUDO => ['forms' => ['one', 'other'], 'rule' => $oneOther],
        'ar' => ['forms' => ['zero', 'one', 'two', 'few', 'many', 'other'], 'rule' => static function (int $count): string {
            $hundred = $count % 100;
            return match (true) {
                $count === 0 => 'zero',
                $count === 1 => 'one',
                $count === 2 => 'two',
                $hundred >= 3 && $hundred <= 10 => 'few',
                $hundred >= 11 => 'many',   // CLDR: 11..99, the arms above have taken 0..10
                default => 'other',
            };
        }],
    ];
}

/** The CLDR plural form a count selects in a locale. Unknown locales use the English rule. */
function pl_plural_form(int $count, ?string $locale = null): string
{
    $language = pl_locale_language($locale === null ? pl_locale() : pl_normalize_locale($locale));
    $table = pl_i18n_plural_table();
    $rule = $table[$language]['rule'] ?? $table[PL_LOCALE_SOURCE]['rule'];
    return (string) $rule($count);
}

/**
 * Register a directory of catalogue files. Bundled modules and, later, plugins declare one
 * through the manifest `lang` key (pl_i18n_module_directories()); tests register their own.
 * Later directories win, so a module may add keys and override the core wording of its own
 * screens; the core catalogue is always loaded first.
 */
function pl_i18n_register_catalogue_directory(string $directory): void
{
    $registry = &pl_i18n_registry();
    $resolved = rtrim(trim(str_replace('\\', '/', $directory)), '/');
    if ($resolved === '' || str_contains($resolved, '..')) {
        throw new DomainException('A catalogue directory must be an explicit path.');
    }
    if (!in_array($resolved, $registry['directories'], true)) {
        $registry['directories'][] = $resolved;
        $registry['catalogues'] = [];
    }
}

/**
 * Catalogue directories declared by the bundled module manifests, in registry order.
 * A manifest's optional `lang` value is a project-relative directory; the manifest is metadata
 * from the reviewed allowlist, never an upload, and the validator keeps the value to a plain
 * relative path so it can only ever name a directory inside this checkout.
 *
 * @return list<string>
 */
function pl_i18n_module_directories(): array
{
    if (!function_exists('pl_module_registry') || !defined('PL_ROOT')) {
        return [];
    }
    try {
        $registry = pl_module_registry();
    } catch (Throwable) {
        // Translation must never be the reason a page fails; an unreadable manifest is reported
        // by the module services themselves, which run their own validation on every request.
        return [];
    }
    $directories = [];
    foreach ($registry as $manifest) {
        $declared = $manifest['lang'] ?? null;
        if (is_string($declared) && $declared !== '') {
            $directories[] = PL_ROOT . '/' . $declared;
        }
    }
    return $directories;
}

/**
 * Every catalogue directory in load order: the core one first, then the module ones, then any
 * directory registered at runtime.
 *
 * @return list<string>
 */
function pl_i18n_catalogue_directories(): array
{
    $registry = &pl_i18n_registry();
    // The trailing slash is deliberate. tests/package-builder-test.py treats a quoted resource
    // path without one as a single packaged file; this is a directory of catalogues, so the
    // slash tells it to look for packaged files underneath instead.
    $core = defined('PL_ROOT') ? [rtrim(PL_ROOT . '/resources/lang/', '/')] : [];
    return array_merge($core, pl_i18n_module_directories(), $registry['directories']);
}

/**
 * The merged catalogue for a locale. English and the pseudo-locale load no file. A locale with
 * region subtags loads its base language first, so ur-PK inherits every ur string it does not
 * restate. A malformed catalogue is ignored rather than fatal: the English source still renders.
 *
 * Catalogue shape (resources/lang/<locale>.php returns this array):
 *   'Save the invoice.' => 'انوائس محفوظ کریں۔',                      // pl_t()
 *   '{count} line'      => ['one' => '…', 'other' => '…'],            // pl_tn(), keyed by CLDR form
 *
 * @return array<string, mixed>
 */
function pl_i18n_catalogue(string $locale): array
{
    $locale = pl_normalize_locale($locale);
    $registry = &pl_i18n_registry();
    if (isset($registry['catalogues'][$locale])) {
        return $registry['catalogues'][$locale];
    }
    $catalogue = [];
    if ($locale !== PL_LOCALE_SOURCE && $locale !== PL_LOCALE_PSEUDO) {
        $language = pl_locale_language($locale);
        $tags = $language === $locale ? [$locale] : [$language, $locale];
        foreach (pl_i18n_catalogue_directories() as $directory) {
            foreach ($tags as $tag) {
                $catalogue = array_replace($catalogue, pl_i18n_catalogue_file($directory . '/' . $tag . '.php'));
            }
        }
    }
    $registry['catalogues'][$locale] = $catalogue;
    return $catalogue;
}

/**
 * Read one catalogue file. It must return an array of English keys mapped to strings or to
 * arrays of CLDR plural forms; anything else is dropped key by key.
 *
 * @return array<string, mixed>
 */
function pl_i18n_catalogue_file(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $loaded = require $path;
    if (!is_array($loaded)) {
        return [];
    }
    $catalogue = [];
    foreach ($loaded as $key => $value) {
        if (!is_string($key) || $key === '') {
            continue;
        }
        if (is_string($value)) {
            $catalogue[$key] = $value;
            continue;
        }
        if (is_array($value)) {
            $forms = [];
            foreach ($value as $form => $text) {
                if (is_string($form) && is_string($text) && in_array($form, ['zero', 'one', 'two', 'few', 'many', 'other'], true)) {
                    $forms[$form] = $text;
                }
            }
            if ($forms !== []) {
                $catalogue[$key] = $forms;
            }
        }
    }
    return $catalogue;
}

/**
 * The deterministic pseudo-locale transform. Every translatable string is bracketed and made
 * about a third longer, so a route sweep can see at a glance which text went through pl_t() and
 * which is still hard-coded English, and so truncation at longer word lengths shows up early.
 * It is generated, not a catalogue file: no pseudo translations ship in a release.
 */
function pl_i18n_pseudo(string $text): string
{
    $padding = max(1, (int) ceil(mb_strlen($text, 'UTF-8') / 3));
    return '⟦' . $text . str_repeat('·', $padding) . '⟧';
}

/**
 * Replace {name} placeholders. Values are stringified; a placeholder with no value is left as
 * written so the gap is visible in review instead of silently disappearing.
 *
 * @param array<string, scalar|Stringable|null> $vars
 */
function pl_i18n_interpolate(string $text, array $vars): string
{
    if ($vars === []) {
        return $text;
    }
    $replacements = [];
    foreach ($vars as $name => $value) {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name)) {
            $replacements['{' . $name . '}'] = $value === null ? '' : (string) $value;
        }
    }
    return $replacements === [] ? $text : strtr($text, $replacements);
}

/**
 * Translate one English source string into the active locale.
 * Returns plain text; escape it at the call site with pl_e().
 *
 * @param array<string, scalar|Stringable|null> $vars
 */
function pl_t(string $english, array $vars = []): string
{
    $locale = pl_locale();
    if ($locale === PL_LOCALE_SOURCE) {
        return pl_i18n_interpolate($english, $vars);
    }
    if ($locale === PL_LOCALE_PSEUDO) {
        return pl_i18n_interpolate(pl_i18n_pseudo($english), $vars);
    }
    $entry = pl_i18n_catalogue($locale)[$english] ?? null;
    $text = is_string($entry) && $entry !== '' ? $entry : $english;
    return pl_i18n_interpolate($text, $vars);
}

/**
 * Translate a counted English source string. The singular is the catalogue key; the catalogue
 * value is an array keyed by the CLDR plural forms of that locale. A missing form, a missing key
 * and the source language all fall back to the English pair, selected by the English rule.
 * {count} is not filled in automatically: pass it, so a string may count one thing and name
 * another, and so a formatted count can be passed instead of a bare integer.
 *
 * @param array<string, scalar|Stringable|null> $vars
 */
function pl_tn(string $one, string $other, int $count, array $vars = []): string
{
    $locale = pl_locale();
    $english = $count === 1 ? $one : $other;
    if ($locale === PL_LOCALE_SOURCE) {
        return pl_i18n_interpolate($english, $vars);
    }
    if ($locale === PL_LOCALE_PSEUDO) {
        return pl_i18n_interpolate(pl_i18n_pseudo($english), $vars);
    }
    $entry = pl_i18n_catalogue($locale)[$one] ?? null;
    if (is_array($entry)) {
        $form = pl_plural_form($count, $locale);
        $text = $entry[$form] ?? $entry['other'] ?? null;
        if (is_string($text) && $text !== '') {
            return pl_i18n_interpolate($text, $vars);
        }
    }
    return pl_i18n_interpolate($english, $vars);
}

/**
 * Presentation seam for grouped numbers (M11 extension point; see docs/ARCHITECTURE.md
 * "Locale and formatting preferences"). `grouping` is read right to left: [3] repeats groups of
 * three (123,456,789) and [3, 2] is South Asian grouping (12,34,56,789). Every locale returns
 * today's Western rules, so pl_money() output is unchanged; M11 adds rows here, and no call site
 * changes. This describes how an amount is WRITTEN. Stored exact amounts, the transaction and
 * base currency, posting precision and accounting DATE values are unaffected by anything here,
 * and parsing localized input back into an exact decimal is a separate, explicit step.
 *
 * @return array{grouping: list<int>, group: string, decimal: string}
 */
function pl_number_format_rules(?string $locale = null): array
{
    $language = pl_locale_language($locale === null ? pl_locale() : pl_normalize_locale($locale));
    /** @var array<string, array{grouping: list<int>, group: string, decimal: string}> $rules */
    $rules = [
        // 'ur' => ['grouping' => [3, 2], 'group' => ',', 'decimal' => '.'],   // M11, with reviewed screens
    ];
    return $rules[$language] ?? ['grouping' => [3], 'group' => ',', 'decimal' => '.'];
}

/**
 * Apply a grouping rule to the whole-number part of an already formatted decimal string.
 * String arithmetic only: no float ever touches an amount.
 *
 * @param list<int> $grouping
 */
function pl_group_digits(string $digits, array $grouping, string $separator): string
{
    if ($separator === '' || $grouping === [] || !preg_match('/^[0-9]+$/D', $digits)) {
        return $digits;
    }
    $groups = [];
    $remaining = $digits;
    $index = 0;
    while (true) {
        $size = $grouping[min($index, count($grouping) - 1)];
        if ($size < 1 || strlen($remaining) <= $size) {
            break;
        }
        $groups[] = substr($remaining, -$size);
        $remaining = substr($remaining, 0, -$size);
        ++$index;
    }
    $groups[] = $remaining;
    return implode($separator, array_reverse($groups));
}

/**
 * Presentation seam for a written date (M11 extension point). Every locale returns today's
 * 'd M Y' pattern, so pl_date_label() output is unchanged; M11 adds locale patterns and
 * translated month names here. The value being written is an accounting DATE: it is formatted
 * as given, never parsed through a timezone, never shifted into the neighbouring day, and never
 * changed for posting, period checks or reconciliation.
 */
function pl_date_format_pattern(?string $locale = null): string
{
    $language = pl_locale_language($locale === null ? pl_locale() : pl_normalize_locale($locale));
    /** @var array<string, string> $patterns */
    $patterns = [
        // 'ur' => 'd F Y',   // M11, once Urdu month names are reviewed
    ];
    return $patterns[$language] ?? 'd M Y';
}
