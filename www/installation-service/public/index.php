<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/receiver.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try {
    if (parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH)!=='/installations/notice') { throw new PLNRequestError(404,'not_found'); }
    if (($_SERVER['REQUEST_METHOD']??'')!=='POST') { header('Allow: POST'); throw new PLNRequestError(405,'method_not_allowed'); }
    if ((int)($_SERVER['CONTENT_LENGTH']??0)>PLN_MAX_BODY) { throw new PLNRequestError(413,'too_large'); }
    if (!preg_match('~^application/json(?:\s*;\s*charset=utf-8)?$~iD',trim($_SERVER['CONTENT_TYPE']??'')) || ($_SERVER['HTTP_CONTENT_ENCODING']??'')!=='') { throw new PLNRequestError(400,'invalid_content_type'); }
    $input=fopen('php://input','rb'); if ($input===false) { throw new RuntimeException('input'); }
    try { $body=stream_get_contents($input,PLN_MAX_BODY+1); } finally { fclose($input); }
    if ($body===false) { throw new PLNRequestError(400,'invalid_json'); }
    pln_accept(pln_payload($body),(string)($_SERVER['REMOTE_ADDR']??''),time());
    echo '{"accepted":true}';
} catch (PLNRequestError $error) {
    http_response_code($error->status); if ($error->status===429) { header('Retry-After: 60'); }
    echo json_encode(['error'=>$error->getMessage()],JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    http_response_code(503); echo '{"error":"unavailable"}';
}
