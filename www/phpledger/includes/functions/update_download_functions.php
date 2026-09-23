<?php
declare(strict_types=1);

/** Publisher downloads are fixed-origin; users never supply an arbitrary URL. */
function pl_update_download_url_allowed(string $url): bool
{
    $parts = parse_url($url);
    return is_array($parts) && ($parts['scheme'] ?? '') === 'https'
        && in_array(strtolower($parts['host'] ?? ''), ['github.com', 'release-assets.githubusercontent.com', 'objects.githubusercontent.com', 'github-releases.githubusercontent.com'], true)
        && (!isset($parts['port']) || $parts['port'] === 443)
        && !isset($parts['user']) && !isset($parts['pass'])
        && !isset($parts['fragment']) && !preg_match('/[\x00-\x20\\\\]/', $url);
}

/**
 * Caller verifies signed metadata and installation-operator authority first.
 * No provider request runs on page load; this is an explicit update POST only.
 */
function pl_update_download_official(array $metadata, string $directory): string
{
    return pl_download_verified_archive($metadata, $directory, 'https://github.com/phpledger/phpledger/releases/download/v' . ($metadata['version'] ?? '') . '/phpledger-' . ($metadata['version'] ?? '') . '.zip');
}

/** Download bytes already authenticated by a pinned publisher envelope. */
function pl_download_verified_archive(array $metadata, string $directory, string $initialUrl): string
{
    $version = $metadata['version'] ?? '';
    $expectedSize = $metadata['archive_bytes'] ?? 0;
    $expectedHash = $metadata['archive_sha256'] ?? '';
    if (!extension_loaded('curl') || !is_string($version)
        || !preg_match('/^\d+\.\d+\.\d+(?:-(?:preview|beta|rc)(?:\.[0-9]+)?)?$/D', $version)
        || !is_int($expectedSize) || $expectedSize < 1 || $expectedSize > 100000000
        || !is_string($expectedHash) || !preg_match('/^[a-f0-9]{64}$/D', $expectedHash)
        || !is_dir($directory) || is_link($directory)) {
        throw new DomainException('A verified release and private writable installation directory are required.');
    }
    $path = $directory . '/download-' . bin2hex(random_bytes(16)) . '.zip';
    $mask = umask(0077);
    try { $file = @fopen($path, 'x+b'); } finally { umask($mask); }
    if ($file === false) { throw new RuntimeException('Private download storage is not writable.'); }
    $success = false;
    try {
        $url = $initialUrl;
        for ($redirects = 0; $redirects <= 5; $redirects++) {
            if (!pl_update_download_url_allowed($url)) { throw new DomainException('Release download redirected outside the supported publisher hosts.'); }
            if (!ftruncate($file, 0) || !rewind($file)) { throw new RuntimeException('Private download could not be reset.'); }
            $headers = []; $received = 0; $status = 0;
            $request = curl_init($url);
            if ($request === false) { throw new RuntimeException('Release download is unavailable.'); }
            try {
                curl_setopt_array($request, [
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 120,
                    CURLOPT_USERAGENT => 'PHP-Ledger-Operator-Updater/1',
                    CURLOPT_HTTPHEADER => ['Accept: application/octet-stream'],
                    CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers, &$status): int {
                        if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $match)) { $status = (int) $match[1]; $headers = []; }
                        $colon = strpos($line, ':');
                        if ($colon !== false) { $headers[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1)); }
                        return strlen($line);
                    },
                    CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use ($file, &$received, &$status, $expectedSize): int {
                        $received += strlen($chunk);
                        if ($received > ($status === 200 ? $expectedSize : 65536)) { return 0; }
                        return fwrite($file, $chunk) ?: 0;
                    },
                ]);
                if (curl_exec($request) !== true) { throw new RuntimeException('Release download failed or exceeded the signed size. Upload the verified release files instead.'); }
                $code = (int) curl_getinfo($request, CURLINFO_RESPONSE_CODE);
            } finally { curl_close($request); }
            if (in_array($code, [301, 302, 303, 307, 308], true)) {
                $url = $headers['location'] ?? '';
                continue;
            }
            if ($code !== 200 || $received !== $expectedSize || !fflush($file)
                || !hash_equals($expectedHash, (string) hash_file('sha256', $path))) {
                throw new DomainException('Downloaded bytes do not match the signed release. The application was not changed.');
            }
            $success = true;
            return $path;
        }
        throw new DomainException('Too many publisher redirects. Upload the verified release files instead.');
    } finally {
        fclose($file);
        if (!$success && is_file($path)) { unlink($path); }
    }
}
