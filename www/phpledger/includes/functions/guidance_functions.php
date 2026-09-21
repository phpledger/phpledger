<?php
declare(strict_types=1);

/**
 * In-app accounting guidance — the help catalogue (owner decisions B66 and B67).
 *
 * B66: the application teaches accounting where the work happens. A question mark beside a label,
 * a column heading or a total opens a bubble that explains the concept in plain language, instead
 * of a wall of text on the screen or a manual to read first.
 *
 * B67: the guidance is not Pakistan-only. A concept carries one shared explanation plus an
 * optional note per jurisdiction, for the countries behind pl_base_currency_options() and for AE,
 * SA and OM.
 *
 * What this file is:
 *   - A reader for two directories of plain data files (resources/guidance/concepts and
 *     resources/guidance/jurisdictions). It contains no copy of its own.
 *   - Lazy by construction. pl_guidance_concept() reads one small file per concept the screen
 *     actually asks for, and pl_guidance_note() reads at most ONE jurisdiction file — the
 *     company's own. A screen with ten bubbles never loads the other twelve jurisdictions.
 *   - The gate on local claims. A jurisdiction note reaches a screen only when it is 'verified'
 *     with a source and a check date; anything else is dropped here rather than in a template, so
 *     an unfinished or unsourced note is invisible rather than wrong.
 *
 * What this file is NOT: it does not escape and it does not translate. The strings it returns are
 * English source strings, exactly as pl_t() expects them; pl_ui_help() translates and escapes them
 * at the point of rendering, where the context is known.
 */

/** Matches a concept id, and therefore also a file name: never a path. */
const PL_GUIDANCE_ID = '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D';

/**
 * The only state in this file: the concept files and the one jurisdiction file already read.
 * Returned by reference so callers share one cache rather than a global, as pl_i18n_registry() does.
 *
 * @return array{concepts: array<string, array<string, mixed>|null>, notes: array<string, array<string, mixed>>, country: ?string}
 */
function &pl_guidance_cache(): array
{
    static $cache = ['concepts' => [], 'notes' => [], 'country' => null];
    return $cache;
}

/** Forget every loaded file and the request's jurisdiction. For the tests and the CLI. */
function pl_guidance_reset(): void
{
    $cache = &pl_guidance_cache();
    $cache = ['concepts' => [], 'notes' => [], 'country' => null];
}

/**
 * Directories searched for guidance data, nearest first.
 *
 * PL_GUIDANCE_PATH is an optional overlay for an installation (and for the tests) that carries
 * notes the shipped catalogue does not: it is searched before the bundled directory, so one extra
 * jurisdiction file needs no change to the application. An unreadable or relative value is ignored.
 *
 * @return list<string>
 */
function pl_guidance_roots(): array
{
    $roots = [];
    $overlay = getenv('PL_GUIDANCE_PATH');
    if (is_string($overlay) && $overlay !== '') {
        $resolved = realpath($overlay);
        if ($resolved !== false && is_dir($resolved)) { $roots[] = rtrim($resolved, '/\\') . '/'; }
    }
    // Every root ends in a separator, and the whole directory is packaged: the release policy test
    // reads this literal and checks that what the application loads at runtime actually ships.
    $roots[] = dirname(__DIR__, 4) . '/resources/guidance/';
    return $roots;
}

/**
 * The first readable data file for this relative path, or null.
 *
 * @return array<string, mixed>|null
 */
function pl_guidance_read(string $relative): ?array
{
    foreach (pl_guidance_roots() as $root) {
        $path = $root . $relative;
        if (is_file($path)) {
            $data = require $path;
            return is_array($data) ? $data : null;
        }
    }
    return null;
}

/**
 * The one public site a concept's longer article may point at.
 *
 * An allowlist, not a URL validator. A guidance file is interface copy, and copy that can name any
 * destination is a way to put an arbitrary link on every screen of the application; the only link
 * a concept is allowed to carry off the installation is the project's own `/learn/` article for it.
 * The slug shape is fixed here too, so a `document` entry can never carry a query string, a
 * fragment, credentials, a port or a different host.
 */
const PL_GUIDANCE_ARTICLE = '#^https://phpledger\.com/learn/[a-z0-9]+(?:-[a-z0-9]+)*/?$#D';

/**
 * The shared explanation for one concept, or null when the catalogue has no such entry.
 *
 * A concept's `document` is the "read more" link under the explanation. It carries EITHER an
 * in-application `href` — a path this installation serves, which is what the 1.2.0 concepts use —
 * OR an external `url`, which may only be a `https://phpledger.com/learn/<slug>` article. An entry
 * that names both keeps the in-application path: a link the installation serves itself is always
 * the safer of the two, and a concept file that names both is a mistake rather than a choice.
 * `external` tells the component which it got, so the renderer does not have to re-parse the URL
 * to decide whether the link leaves the application.
 *
 * @return array{id: string, title: string, explanation: string, here: string, document: array{label: string, href: string, external: bool}|null, review: string}|null
 */
function pl_guidance_concept(string $id): ?array
{
    $cache = &pl_guidance_cache();
    if (!preg_match(PL_GUIDANCE_ID, $id)) { return null; }
    if (array_key_exists($id, $cache['concepts'])) { return $cache['concepts'][$id]; }
    $data = pl_guidance_read('concepts/' . $id . '.php');
    $title = is_string($data['title'] ?? null) ? trim($data['title']) : '';
    $explanation = is_string($data['explanation'] ?? null) ? trim($data['explanation']) : '';
    if ($title === '' || $explanation === '') { return $cache['concepts'][$id] = null; }
    $document = null;
    $link = $data['document'] ?? null;
    if (is_array($link) && is_string($link['label'] ?? null) && $link['label'] !== '') {
        if (is_string($link['href'] ?? null) && str_starts_with($link['href'], '/')) {
            $document = ['label' => $link['label'], 'href' => $link['href'], 'external' => false];
        } elseif (is_string($link['url'] ?? null) && preg_match(PL_GUIDANCE_ARTICLE, $link['url'])) {
            $document = ['label' => $link['label'], 'href' => $link['url'], 'external' => true];
        }
    }
    return $cache['concepts'][$id] = [
        'id' => $id,
        'title' => $title,
        'explanation' => $explanation,
        'here' => is_string($data['here'] ?? null) ? trim($data['here']) : '',
        'document' => $document,
        'review' => ($data['review'] ?? '') === 'reviewed' ? 'reviewed' : 'placeholder',
    ];
}

/** Concept ids present in the catalogue, for the tests and for documentation. @return list<string> */
function pl_guidance_concept_ids(): array
{
    $ids = [];
    foreach (pl_guidance_roots() as $root) {
        foreach (glob($root . 'concepts/*.php') ?: [] as $path) {
            $id = basename($path, '.php');
            if (preg_match(PL_GUIDANCE_ID, $id)) { $ids[$id] = true; }
        }
    }
    $ids = array_keys($ids);
    sort($ids);
    return $ids;
}

/**
 * The jurisdictions the guidance covers: the countries behind the base currencies the application
 * offers, plus the Gulf states A7 names (B67). EU is the euro area, which is not an ISO 3166
 * country; it is here because the application offers the euro as a base currency.
 *
 * @return array<string, string> country code => base currency ('' where none is offered)
 */
function pl_guidance_countries(): array
{
    $byCurrency = ['USD' => 'US', 'EUR' => 'EU', 'GBP' => 'GB', 'PKR' => 'PK', 'INR' => 'IN',
        'MYR' => 'MY', 'BDT' => 'BD', 'LKR' => 'LK', 'NPR' => 'NP', 'SGD' => 'SG'];
    $countries = [];
    foreach (array_keys(pl_base_currency_options()) as $currency) {
        if (isset($byCurrency[$currency])) { $countries[$byCurrency[$currency]] = $currency; }
    }
    foreach (['AE', 'SA', 'OM'] as $gulf) { $countries += [$gulf => '']; }
    return $countries;
}

/** The reader-facing name of a guidance jurisdiction. CLDR holds the countries; the euro area is not one. */
function pl_guidance_country_name(string $country): string
{
    if ($country === 'EU') { return 'the euro area'; }
    $name = pl_country_defaults($country)['country_name'];
    return is_string($name) && $name !== '' ? $name : $country;
}

/**
 * The jurisdiction a company's guidance should use, or null when it cannot be told.
 *
 * The company record does not carry a country today: its base currency is the only jurisdiction
 * signal it holds, so the currency chosen when the books were created is what selects the notes. A
 * country_code on the company row wins as soon as one exists — the B63/B64 registration profile is
 * where that will come from — and this is the only place that has to change when it does.
 *
 * @param array<string, mixed>|null $company
 */
function pl_guidance_company_country(?array $company): ?string
{
    if ($company === null) { return null; }
    $explicit = $company['country_code'] ?? null;
    if (is_string($explicit) && preg_match('/^[A-Z]{2}$/D', $explicit)) { return $explicit; }
    $currency = $company['currency'] ?? null;
    if (!is_string($currency)) { return null; }
    foreach (pl_guidance_countries() as $country => $base) {
        if ($base !== '' && $base === $currency) { return $country; }
    }
    return null;
}

/**
 * The local note for one concept in one jurisdiction, or null.
 *
 * Reads at most one jurisdiction file, and only the company's own. A note that is not 'verified'
 * with a source and a check date is dropped here: B67's rule is that an unsourced local claim is
 * worse than none, so the reader sees the shared explanation alone rather than a guess dressed up
 * as local law.
 *
 * @return array{country: string, country_name: string, note: string, source: string, checked_on: string}|null
 */
function pl_guidance_note(string $concept, ?string $country): ?array
{
    $cache = &pl_guidance_cache();
    if ($country === null || !preg_match('/^[A-Z]{2}$/D', $country) || !preg_match(PL_GUIDANCE_ID, $concept)) { return null; }
    if (!array_key_exists($country, $cache['notes'])) {
        $cache['notes'][$country] = pl_guidance_read('jurisdictions/' . $country . '.php') ?? [];
    }
    $entry = $cache['notes'][$country][$concept] ?? null;
    if (!is_array($entry) || ($entry['status'] ?? '') !== 'verified') { return null; }
    $note = is_string($entry['note'] ?? null) ? trim($entry['note']) : '';
    $source = is_string($entry['source'] ?? null) ? trim($entry['source']) : '';
    $checked = is_string($entry['checked_on'] ?? null) ? trim($entry['checked_on']) : '';
    if ($note === '' || $source === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $checked)) { return null; }
    return ['country' => $country, 'country_name' => pl_guidance_country_name($country),
        'note' => $note, 'source' => $source, 'checked_on' => $checked];
}

/**
 * The company whose jurisdiction the bubbles on this request use. pl_render() sets it once per
 * request; a template never passes a company to a bubble.
 *
 * @param array<string, mixed>|null $company
 */
function pl_guidance_use_company(?array $company): void
{
    $cache = &pl_guidance_cache();
    $cache['country'] = pl_guidance_company_country($company);
}

/** The jurisdiction in force for this request. Set it with pl_guidance_use_company(). */
function pl_guidance_request_country(): ?string
{
    $cache = &pl_guidance_cache();
    return $cache['country'];
}

/**
 * Everything one bubble renders: the shared explanation, and the local note when the company's
 * jurisdiction has a verified one. Still English source strings; pl_ui_help() translates them.
 *
 * @return array{id: string, title: string, explanation: string, here: string, document: array{label: string, href: string, external: bool}|null, review: string, note: array{country: string, country_name: string, note: string, source: string, checked_on: string}|null}
 */
function pl_guidance_entry(string $id): array
{
    $concept = pl_guidance_concept($id);
    if ($concept === null) {
        // Loudly, and at the first render: a screen asking for a concept the catalogue does not
        // have is a wiring mistake, and the catalogue test fails on it before it can ship.
        throw new LogicException('Unknown help concept: ' . $id);
    }
    return $concept + ['note' => pl_guidance_note($id, pl_guidance_request_country())];
}

/** Words as the catalogue counts them, so the bound in the test and the one in the docs agree. */
function pl_guidance_word_count(string $text): int
{
    $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
    return $words === false ? 0 : count($words);
}
