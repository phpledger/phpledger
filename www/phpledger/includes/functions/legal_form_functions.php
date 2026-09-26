<?php
declare(strict_types=1);

/**
 * Legal forms in each country's own words (owner note of 26 September 2026; the data is
 * resources/locale/legal-forms.json, approved on the 1.4.5 mockup).
 *
 * The catalogue names, for seven countries and a neutral list for the rest, the forms a
 * registrar actually uses — "(Private) Limited" with SECP, "Ltd" at Companies House, an LLC with
 * the DED — with a one-line guide for each and the labels of the numbers that go on invoices.
 * Every local form maps to a canonical family, the keys pl_legal_forms() has always returned, so
 * the share ledger, the partner ratio and the owner-equity logic need no country of their own.
 *
 * A stored legal form is either a family key ('private_limited', what earlier releases wrote)
 * or a country-prefixed local key ('pk.pvt_ltd'). Both stay valid; the local key remembers the
 * country it was chosen in, which is how a company knows its country without a column for it.
 * Nothing here activates a tax rule.
 */

/** The catalogue, read once and checked against the families the application knows. */
function pl_legal_form_catalogue(): array
{
    static $catalogue = null;
    if ($catalogue !== null) {
        return $catalogue;
    }
    if (!function_exists('pl_legal_forms')) {
        require_once __DIR__ . '/ownership_functions.php';
    }
    $raw = file_get_contents(dirname(__DIR__, 4) . '/resources/locale/legal-forms.json');
    if ($raw === false) {
        throw new RuntimeException('The legal-form catalogue is unavailable.');
    }
    $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($data) || !is_array($data['countries'] ?? null) || !is_array($data['families'] ?? null) || !isset($data['countries']['ZZ'])) {
        throw new RuntimeException('The legal-form catalogue is invalid.');
    }
    $families = array_keys(pl_legal_forms());
    foreach ($data['countries'] as $code => $country) {
        if (!preg_match('/^[A-Z]{2}$/D', (string) $code) || !is_array($country['forms'] ?? null) || $country['forms'] === []
            || !is_string($country['name'] ?? null) || !is_string($country['default'] ?? null) || !is_array($country['labels'] ?? null)) {
            throw new RuntimeException('The legal-form catalogue is invalid for ' . $code . '.');
        }
        foreach ($country['forms'] as $form) {
            if (!is_string($form['key'] ?? null) || !preg_match('/^[a-z][a-z0-9_]{0,30}$/D', $form['key'])
                || !in_array($form['family'] ?? null, $families, true) || !is_string($form['name'] ?? null) || $form['name'] === '') {
                throw new RuntimeException('The legal-form catalogue names a form PHP Ledger does not know.');
            }
        }
    }
    return $catalogue = $data;
}

/** The countries the catalogue speaks for, ZZ last: the neutral list for any other country. @return array<string,string> */
function pl_legal_form_countries(): array
{
    $out = [];
    foreach (pl_legal_form_catalogue()['countries'] as $code => $country) {
        $out[(string) $code] = (string) $country['name'];
    }
    return $out;
}

/**
 * One country's entry: registrar, usual form, suggested currency and year end, the invoice-number
 * labels and the forms. A country the catalogue does not name gets the neutral list.
 */
function pl_legal_form_country_profile(?string $country): array
{
    $countries = pl_legal_form_catalogue()['countries'];
    $code = is_string($country) ? strtoupper($country) : '';
    $known = isset($countries[$code]) ? $code : 'ZZ';
    return ['code' => $known] + $countries[$known];
}

/** A country's forms as stored keys ('pk.pvt_ltd') => local names, in the registrar's order. @return array<string,string> */
function pl_legal_forms_for(?string $country): array
{
    $profile = pl_legal_form_country_profile($country);
    $prefix = pl_legal_form_key_prefix($country, $profile);
    $out = [];
    foreach ($profile['forms'] as $form) {
        $out[$prefix . $form['key']] = (string) $form['name'];
    }
    return $out;
}

/** The stored key of a country's usual form, for a picker nobody has touched yet. */
function pl_legal_form_default(?string $country): string
{
    $profile = pl_legal_form_country_profile($country);
    // The neutral list pre-selects nothing: a form nobody chose must not reach an invoice.
    return $profile['default'] === '' ? '' : pl_legal_form_key_prefix($country, $profile) . $profile['default'];
}

/** 'pk.' for a catalogued country; the neutral list's keys are the canonical family keys themselves. */
function pl_legal_form_key_prefix(?string $country, array $profile): string
{
    return $profile['code'] === 'ZZ' ? '' : strtolower((string) $profile['code']) . '.';
}

/** The catalogue row behind a stored key, with its country; null for a family key or an unknown one. */
function pl_legal_form_entry(string $form): ?array
{
    $countries = pl_legal_form_catalogue()['countries'];
    // A canonical family key is the neutral list's own entry: the country of registration is a
    // column on the profile (migration 061), never part of the key.
    if (isset(pl_legal_forms()[$form])) {
        foreach ($countries['ZZ']['forms'] as $entry) {
            if ($entry['key'] === $form) {
                return $entry + ['country' => 'ZZ'];
            }
        }
        return null;
    }
    if (!preg_match('/^([a-z]{2})\.([a-z][a-z0-9_]{0,30})$/D', $form, $match)) {
        return null;
    }
    $code = strtoupper($match[1]);
    if (!isset($countries[$code]) || $code === 'ZZ') {
        return null;
    }
    foreach ($countries[$code]['forms'] as $entry) {
        if ($entry['key'] === $match[2]) {
            return $entry + ['country' => $code];
        }
    }
    return null;
}

/** 'pk.pvt_ltd' remembers Pakistan; a family key, or the neutral list, remembers no country. */
function pl_legal_form_country(string $form): ?string
{
    $entry = pl_legal_form_entry($form);
    return $entry === null || $entry['country'] === 'ZZ' ? null : $entry['country'];
}

/** The canonical family behind a stored key, or '' when it is neither a family nor a catalogue form. */
function pl_legal_form_family(string $form): string
{
    if (isset(pl_legal_forms()[$form])) {
        return $form;
    }
    $entry = pl_legal_form_entry($form);
    return $entry === null ? '' : (string) $entry['family'];
}

function pl_legal_form_known(string $form): bool
{
    return $form !== '' && pl_legal_form_family($form) !== '';
}

/** What a screen or a printed document calls it: the local name where one was chosen, the family name otherwise. */
function pl_legal_form_label(string $form): string
{
    $entry = pl_legal_form_entry($form);
    if ($entry !== null) {
        return (string) $entry['name'];
    }
    return pl_legal_forms()[$form] ?? '';
}

/** The one-line guide under the picker: what the form is, who registers it and what it means for the books. */
function pl_legal_form_guide(string $form): string
{
    $entry = pl_legal_form_entry($form);
    if ($entry === null) {
        return '';
    }
    $family = pl_legal_form_catalogue()['families'][$entry['family']] ?? ['owners' => '', 'money_out' => ''];
    $parts = ['short' => (string) $entry['short'], 'registrar' => (string) $entry['registrar'], 'owners' => (string) $family['owners'],
        'money_out' => (string) $family['money_out'], 'numbers' => (string) $entry['numbers']];
    $sentence = '{short} Registered with {registrar}. Owners: {owners}. Money out as {money_out}. On invoices: {numbers}.';
    if (function_exists('pl_t')) {
        return pl_t($sentence, $parts);
    }
    return strtr($sentence, ['{short}' => $parts['short'], '{registrar}' => $parts['registrar'], '{owners}' => $parts['owners'],
        '{money_out}' => $parts['money_out'], '{numbers}' => $parts['numbers']]);
}

/**
 * The catalogue in the shape the business-details screen's script swaps the picker with when the
 * country changes. Without the script the same data renders server-side and a change of country
 * is a round trip.
 */
function pl_legal_form_catalogue_for_script(): array
{
    $out = [];
    foreach (pl_legal_form_catalogue()['countries'] as $code => $country) {
        $prefix = (string) $code === 'ZZ' ? '' : strtolower((string) $code) . '.';
        $forms = [];
        foreach ($country['forms'] as $form) {
            $forms[] = ['key' => $prefix . $form['key'], 'family' => $form['family'], 'name' => $form['name'], 'guide' => pl_legal_form_guide($prefix . $form['key'])];
        }
        $labels = [];
        foreach ($country['labels'] as $key => $label) {
            $labels[$key] = $label === '' || !function_exists('pl_t') ? (string) $label : pl_t((string) $label);
        }
        $out[(string) $code] = ['name' => $country['name'], 'registrar' => $country['registrar'],
            'default' => $country['default'] === '' ? '' : $prefix . $country['default'],
            'chip' => pl_legal_form_chip((string) $code, (string) $country['name']),
            'currency' => $country['currency'], 'fiscal_year_end' => $country['fiscal_year_end'], 'labels' => $labels,
            'placeholders' => $country['placeholders'], 'forms' => $forms];
    }
    return $out;
}

/** The small label beside the legal-form picker: whose names the list uses. */
function pl_legal_form_chip(string $code, string $countryName): string
{
    if ($code === 'ZZ') {
        return function_exists('pl_t') ? pl_t('General names') : 'General names';
    }
    return function_exists('pl_t') ? pl_t('Names as used in {country}', ['country' => $countryName]) : 'Names as used in ' . $countryName;
}
