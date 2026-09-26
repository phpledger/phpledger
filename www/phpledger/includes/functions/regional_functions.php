<?php
declare(strict_types=1);

/** One shared choice list for base-currency validation and country/currency selectors. */
function pl_base_currency_options(): array
{
    return [
        'USD' => 'United States — USD',
        'EUR' => 'Euro area — EUR',
        'GBP' => 'United Kingdom — GBP',
        'PKR' => 'Pakistan — PKR',
        'INR' => 'India — INR',
        'MYR' => 'Malaysia — MYR',
        'BDT' => 'Bangladesh — BDT',
        'LKR' => 'Sri Lanka — LKR',
        'NPR' => 'Nepal — NPR',
        'SGD' => 'Singapore — SGD',
    ];
}

function pl_normalize_ip(string $address): ?string
{
    if (filter_var($address, FILTER_VALIDATE_IP) === false) {
        return null;
    }
    $binary = inet_pton($address);
    if ($binary === false) {
        return null;
    }
    if (strlen($binary) === 16 && substr($binary, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
        $binary = substr($binary, 12);
    }
    $normalized = inet_ntop($binary);
    return $normalized === false ? null : $normalized;
}

/** Return only a public address, trusting forwarded values solely behind configured exact proxies. */
function pl_country_lookup_ip(array $server, ?string $trustedProxyList = null): ?string
{
    $remote = pl_normalize_ip(is_string($server['REMOTE_ADDR'] ?? null) ? $server['REMOTE_ADDR'] : '');
    if ($remote === null) {
        return null;
    }
    $trusted = [];
    foreach (explode(',', $trustedProxyList ?? (getenv('PL_TRUSTED_PROXY_IPS') ?: '')) as $candidate) {
        $normalized = pl_normalize_ip(trim($candidate));
        if ($normalized !== null) {
            $trusted[] = $normalized;
        }
    }
    $address = $remote;
    if (in_array($remote, $trusted, true)) {
        if (!is_string($server['HTTP_X_FORWARDED_FOR'] ?? null) || strlen($server['HTTP_X_FORWARDED_FOR']) > 1024) {
            return null;
        }
        $chain = explode(',', $server['HTTP_X_FORWARDED_FOR']);
        if (count($chain) > 16) {
            return null;
        }
        $addresses = [];
        foreach ($chain as $candidate) {
            $normalized = pl_normalize_ip(trim($candidate));
            if ($normalized === null) {
                return null;
            }
            $addresses[] = $normalized;
        }
        // Nearest untrusted hop is the boundary; never accept an attacker-supplied leftmost address.
        $foundClient = false;
        foreach (array_reverse($addresses) as $candidate) {
            $address = $candidate;
            if (!in_array($candidate, $trusted, true)) {
                $foundClient = true;
                break;
            }
        }
        if (!$foundClient) {
            return null;
        }
    }
    return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false ? null : $address;
}

function pl_country_defaults(?string $countryCode): array
{
    $unknown = ['country_code' => null, 'country_name' => null, 'locale' => null, 'currency' => null, 'country_currency' => null, 'country_currencies' => [], 'currency_supported' => false];
    if ($countryCode === null || $countryCode === 'ZZ' || !preg_match('/^[A-Z]{2}$/D', $countryCode)) {
        return $unknown;
    }
    static $registry;
    if ($registry === null) {
        $source = file_get_contents(dirname(__DIR__, 4) . '/resources/locale/country-defaults-cldr48.json');
        if ($source === false) {
            return $unknown;
        }
        $registry = json_decode($source, true, 512, JSON_THROW_ON_ERROR);
    }
    $country = $registry['countries'][$countryCode] ?? null;
    if (!is_array($country)) {
        return $unknown;
    }
    $currencies = $country['currencies'];
    $single = count($currencies) === 1 ? $currencies[0] : null;
    $supported = is_string($single) && isset(pl_base_currency_options()[$single]);
    return ['country_code' => $countryCode, 'country_name' => $country['name'], 'locale' => $country['locale'],
        'currency' => $supported ? $single : null, 'country_currency' => $single, 'country_currencies' => $currencies, 'currency_supported' => $supported];
}

/**
 * Every country the CLDR registry names, code => name, sorted by name, for a select: the wizard
 * asks for the country of registration rather than a two-letter code (owner review, 26 September
 * 2026). Suggestions only; choosing a country enables no tax rule.
 *
 * @return array<string, string>
 */
function pl_country_options(): array
{
    static $options = null;
    if ($options !== null) {
        return $options;
    }
    $source = file_get_contents(dirname(__DIR__, 4) . '/resources/locale/country-defaults-cldr48.json');
    $registry = $source === false ? [] : json_decode($source, true, 512, JSON_THROW_ON_ERROR);
    $options = [];
    foreach ($registry['countries'] ?? [] as $code => $country) {
        if (preg_match('/^[A-Z]{2}$/D', (string) $code) && is_string($country['name'] ?? null) && $country['name'] !== '') {
            $options[(string) $code] = (string) $country['name'];
        }
    }
    asort($options, SORT_NATURAL | SORT_FLAG_CASE);
    return $options;
}

/** One bounded HTTPS lookup. No credentials, city/GPS fields, redirects, or raw-IP logging. */
function pl_fetch_country_code(string $publicIp): ?string
{
    $publicIp = pl_normalize_ip($publicIp) ?? '';
    if (filter_var($publicIp, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
        return null;
    }
    $context = stream_context_create([
        'http' => ['method' => 'GET', 'timeout' => 2.0, 'follow_location' => 0, 'ignore_errors' => true,
            'header' => "Accept: application/json\r\nUser-Agent: PHP-Ledger-country-suggestion/0.1\r\n"],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $stream = @fopen('https://api.country.is/' . rawurlencode($publicIp), 'rb', false, $context);
    if ($stream === false) {
        return null;
    }
    try {
        $metadata = stream_get_meta_data($stream);
        $status = $metadata['wrapper_data'][0] ?? '';
        if (!is_string($status) || !preg_match('/^HTTP\/\S+ 200(?: |$)/', $status)) {
            return null;
        }
        $body = stream_get_contents($stream, 8193);
        if (!is_string($body) || strlen($body) > 8192) {
            return null;
        }
        $data = json_decode($body, true);
        $code = is_array($data) ? ($data['country'] ?? null) : null;
        return is_string($code) && preg_match('/^[A-Z]{2}$/D', $code) ? $code : null;
    } finally {
        fclose($stream);
    }
}

/** Cache success, failure, or local-network skip for the browser session, including login rotation. */
function pl_regional_suggestion(?array $server = null, ?callable $fetcher = null): array
{
    pl_require_session();
    if (is_array($_SESSION['regional_suggestion'] ?? null)) {
        // Reapply local support metadata after an upgrade without repeating the country API call.
        $cachedCode = $_SESSION['regional_suggestion']['country_code'] ?? null;
        if (is_string($cachedCode)) {
            $_SESSION['regional_suggestion'] = array_replace($_SESSION['regional_suggestion'], pl_country_defaults($cachedCode));
        }
        return $_SESSION['regional_suggestion'];
    }
    $result = pl_country_defaults(null) + ['source' => 'country.is', 'status' => 'unavailable'];
    // Persist a fallback before the request so a recoverable API error never triggers per-page retries.
    $_SESSION['regional_suggestion'] = $result;
    $ip = pl_country_lookup_ip($server ?? $_SERVER);
    if ($ip === null) {
        $result['source'] = null;
        $result['status'] = 'not_public';
    } else {
        try {
            $code = ($fetcher ?? 'pl_fetch_country_code')($ip);
            $defaults = pl_country_defaults(is_string($code) ? $code : null);
            if ($defaults['country_code'] !== null) {
                $result = $defaults + ['source' => 'country.is', 'status' => 'detected'];
            }
        } catch (Throwable) {
            // Manual choices remain available. Never log the IP, API body, or transport failure URL.
        }
    }
    $_SESSION['regional_suggestion'] = $result;
    return $result;
}
