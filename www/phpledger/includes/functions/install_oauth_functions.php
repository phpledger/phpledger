<?php
declare(strict_types=1);

require_once __DIR__ . '/installation_state_functions.php';

/**
 * Validate the explicit public address the owner confirms; never infer it from an
 * untrusted Host header. Its only allowed path is the folder this copy runs in.
 */
function pl_install_public_url(string $input): string
{
    $url = rtrim(trim($input), '/');
    $parts = parse_url($url);
    $local = (in_array(getenv('PL_ENV'), ['local', 'test'], true) && getenv('PL_INSTALL_ALLOW_HTTP') === '1')
        || (function_exists('pl_web_local_http') && pl_web_local_http($_SERVER));
    $base = function_exists('pl_base_path') ? pl_base_path() : '';
    $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
    $localHost = in_array($host, ['127.0.0.1', 'localhost', '[::1]'], true) || str_ends_with($host, '.localhost');
    // A site with no certificate yet keeps its own plain address (issue #84). Only the exact
    // address the owner is using is accepted here, never some other http:// site, and the
    // features that need a certificate still report themselves unavailable.
    $ownAddress = function_exists('pl_install_suggested_public_url') ? pl_install_suggested_public_url($_SERVER) : '';
    $ownPlainAddress = $ownAddress !== '' && str_starts_with($ownAddress, 'http://') && strcasecmp($url, $ownAddress) === 0;
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
        || ($parts['path'] ?? '') !== $base || strlen($url) > 480 || preg_match('/[\x00-\x20\x7f]/', $url)
        || ($parts['scheme'] !== 'https' && !$ownPlainAddress && !($local && $parts['scheme'] === 'http' && $localHost))) {
        throw new InvalidArgumentException('Enter this site\'s public HTTPS address, such as https://books.example.com' . ($base === '' ? '' : ' or https://example.com' . $base) . ', without a query or sign-in details. If this site has no certificate yet, enter exactly the address shown in your browser.');
    }
    return $url;
}

/** Create the existing RSA/encryption key format atomically. Existing keys are never rotated. */
function pl_install_oauth_keys(string $directory): bool
{
    $directory = pl_install_private_path($directory);
    $names = ['private.key', 'public.key', 'encryption.key'];
    // An operator who pre-created an empty key folder, or pointed PL_OAUTH_KEY_DIRECTORY
    // at one, has given setup a destination rather than an interrupted installation. Only
    // a folder holding some of the keys is refused, because that is the state worth keeping.
    $destination = is_dir($directory) && !is_link($directory);
    $present = [];
    if ($destination) {
        foreach ($names as $name) {
            if (file_exists($directory . '/' . $name)) {
                $present[] = $name;
            }
        }
    }
    if (file_exists($directory) && ($present !== [] || !$destination)) {
        if (!$destination) {
            throw new DomainException('The OAuth key directory needs operator review. Existing state was preserved.');
        }
        foreach ($names as $name) {
            $path = $directory . '/' . $name;
            if (!is_file($path) || is_link($path) || !is_readable($path)) {
                throw new DomainException('OAuth key creation was interrupted or the key directory is incomplete. Preserve it and restore the matching keys before continuing.');
            }
            if ($name !== 'public.key' && PHP_OS_FAMILY !== 'Windows' && ((int) fileperms($path) & 0007) !== 0) {
                throw new DomainException('Restrict private OAuth key permissions in your hosting panel before continuing.');
            }
        }
        $private = openssl_pkey_get_private((string) file_get_contents($directory . '/private.key'));
        $public = openssl_pkey_get_public((string) file_get_contents($directory . '/public.key'));
        $privateDetails = $private ? openssl_pkey_get_details($private) : false;
        $publicDetails = $public ? openssl_pkey_get_details($public) : false;
        if (!$privateDetails || !$publicDetails || $privateDetails['type'] !== OPENSSL_KEYTYPE_RSA || $privateDetails['bits'] < 3072
            || !hash_equals($privateDetails['key'], $publicDetails['key'])
            || !preg_match('/^[a-f0-9]{64}$/D', trim((string) file_get_contents($directory . '/encryption.key')))) {
            throw new DomainException('The existing OAuth keys do not form a valid matching set. Existing keys were preserved.');
        }
        return false;
    }
    $mask = umask(0077);
    $staging = $directory . '.new-' . bin2hex(random_bytes(12));
    try {
        if (!is_dir(dirname($directory)) && !mkdir(dirname($directory), 0700, true) && !is_dir(dirname($directory))) {
            throw new DomainException('Create a writable private parent directory for OAuth keys in your hosting panel.');
        }
        if (!mkdir($staging, 0700)) {
            throw new RuntimeException('Private OAuth staging could not be created.');
        }
        $options = ['private_key_bits' => 3072, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $key = @openssl_pkey_new($options);
        if ($key === false) {
            // Some Windows stacks (XAMPP) point OpenSSL at a missing configuration file. Key generation
            // needs no settings from it, so retry with the minimal file shipped with the installer.
            while (openssl_error_string() !== false) {
            }
            $options['config'] = dirname(__DIR__, 2) . '/install/openssl.cnf';
            $key = openssl_pkey_new($options);
        }
        $exportOptions = isset($options['config']) ? ['config' => $options['config']] : [];
        if (!$key || !openssl_pkey_export($key, $private, null, $exportOptions)) {
            throw new RuntimeException('Could not create the private OAuth signing key.');
        }
        while (openssl_error_string() !== false) {
        }
        $details = openssl_pkey_get_details($key);
        if (!$details) {
            throw new RuntimeException('Could not obtain the OAuth public key.');
        }
        foreach (['private.key' => $private, 'public.key' => $details['key'], 'encryption.key' => bin2hex(random_bytes(32))] as $name => $contents) {
            pl_install_write_private($staging . '/' . $name, $contents, false);
        }
        if ($destination) {
            foreach ($names as $name) {
                if (file_exists($directory . '/' . $name) || !rename($staging . '/' . $name, $directory . '/' . $name)) {
                    throw new DomainException('The OAuth key destination changed. Existing keys were preserved.');
                }
            }
        } elseif (file_exists($directory) || !rename($staging, $directory)) {
            throw new DomainException('The OAuth key destination changed. Existing keys were preserved.');
        }
        return true;
    } finally {
        if (is_dir($staging)) {
            foreach (['private.key', 'public.key', 'encryption.key'] as $name) {
                if (is_file($staging . '/' . $name)) {
                    unlink($staging . '/' . $name);
                }
            }
            @rmdir($staging);
        }
        umask($mask);
    }
}
