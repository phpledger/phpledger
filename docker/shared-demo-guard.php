<?php
declare(strict_types=1);

// Hosting-only auto_prepend_file: all PHP entry points share the reset lock.
if (PHP_SAPI === 'cli') {
    return;
}
$plSharedDemoUnavailable = static function (): never {
    http_response_code(503);
    header('Retry-After: 3');
    header('Cache-Control: no-store');
    header('Content-Type: text/plain; charset=utf-8');
    echo "The shared demo is refreshing. Reload in a few seconds to start the installer.\n";
    exit;
};
if (getenv('PL_ENV') !== 'demo-install') {
    $plSharedDemoUnavailable();
}
$plSharedDemoRequestLock = @fopen('/var/lib/phpledger/host/reset.lock', 'r');
if ($plSharedDemoRequestLock === false || !flock($plSharedDemoRequestLock, LOCK_SH | LOCK_NB)) {
    $plSharedDemoUnavailable();
}
// Keep this resource alive through application shutdown handlers and session writes.
// A registered unlock callback would run before callbacks registered by the app.
$plSharedDemoGeneration = json_decode((string) @file_get_contents('/var/lib/phpledger/host/generation.json'), true);
if (!is_array($plSharedDemoGeneration) || ($plSharedDemoGeneration['format'] ?? null) !== 1
    || !is_int($plSharedDemoGeneration['expires_at'] ?? null) || $plSharedDemoGeneration['expires_at'] <= time()) {
    $plSharedDemoUnavailable();
}
header('X-Robots-Tag: noindex, nofollow');
header('X-Demo-Expires: ' . gmdate('c', $plSharedDemoGeneration['expires_at']));
