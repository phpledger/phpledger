<?php
declare(strict_types=1);

// Docker HEALTHCHECK for the production image. Avoids depending on curl or wget,
// which the base php:8.3-apache image does not carry, and stays independent of the
// application's own bootstrap: it only needs to reach /health over the loopback
// interface on the container's own port.
$context = stream_context_create(['http' => ['timeout' => 4, 'ignore_errors' => true]]);
$body = @file_get_contents('http://127.0.0.1:8080/health', false, $context);
if ($body === false) {
    fwrite(STDERR, "PHP Ledger health check: the web server did not respond.\n");
    exit(1);
}
$status = null;
foreach ($http_response_header ?? [] as $header) {
    if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)) {
        $status = (int) $match[1];
    }
}
$decoded = json_decode($body, true);
if ($status !== 200 || !is_array($decoded) || ($decoded['status'] ?? null) !== 'ok') {
    fwrite(STDERR, 'PHP Ledger health check: unhealthy response (HTTP ' . ($status ?? 'none') . ', body ' . substr($body, 0, 200) . ").\n");
    exit(1);
}
exit(0);
