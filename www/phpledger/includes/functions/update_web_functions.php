<?php
declare(strict_types=1);

require_once __DIR__ . '/update_probe_functions.php';
// Copied into the recovery runtime alongside this file (pl_update_begin()) so an
// installation that already runs in a non-file-owning mode never offers, mid-update,
// the very upload form that mode must refuse.
require_once __DIR__ . '/update_channel_functions.php';

/** Minimal independent operator interface. No financial actor/session is consulted. */
function pl_update_web(string $root): never
{
    ignore_user_abort(true);
    header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: no-referrer');
    $nonce = base64_encode(random_bytes(18));
    header("Content-Security-Policy: default-src 'none'; script-src 'nonce-$nonce'; style-src 'nonce-$nonce'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'POST'], true)) { http_response_code(405); header('Allow: GET, POST'); exit; }
    $local = in_array(getenv('PL_ENV'), ['local', 'test'], true) && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
    if (!$local && !pl_update_secure_request($_SERVER)) { http_response_code(403); echo 'Installation operations require HTTPS.'; exit; }
    ini_set('session.use_strict_mode', '1'); ini_set('session.use_only_cookies', '1');
    session_name('phpledger_install_operator');
    session_set_cookie_params(['httponly' => true, 'secure' => !$local, 'samesite' => 'Strict', 'path' => '/']);
    session_start();
    $_SESSION['update_csrf'] ??= bin2hex(random_bytes(32));
    $csrf = $_SESSION['update_csrf']; $error = ''; $state = null; $authorized = false;
    // Only a managed (ZIP, Windows bundle) installation owns its files. Container,
    // Composer and panel installations (docs/RELEASE-PROTOCOL.md) are never modified in
    // place; this page tells them how to upgrade on their own channel instead. Safe
    // defaults here mean an unreadable PL_UPDATE_MODE falls through the ordinary error
    // handling below instead of a raw fatal error before the page can render at all.
    $mode = 'managed'; $fileOwned = true;
    try {
        $mode = pl_update_mode();
        $fileOwned = pl_update_mode_replaces_files($mode);
        $directory = pl_update_directory($root);
        $keyFile = $directory . '/operator.key';
        $fingerprint = is_file($keyFile) ? hash_file('sha256', $keyFile) : '';
        $authorized = $fingerprint !== '' && isset($_SESSION['update_operator'], $_SESSION['update_until'])
            && hash_equals($fingerprint, $_SESSION['update_operator']) && $_SESSION['update_until'] > time();
        if ($method === 'POST') {
            if (!is_string($_POST['csrf'] ?? null) || !hash_equals($csrf, $_POST['csrf'])) { throw new DomainException('This form expired. Reload the installation page.'); }
            if (isset($_POST['operator_key']) && is_string($_POST['operator_key']) && $_POST['operator_key'] !== '') {
                pl_update_operator_attempt($directory, $_POST['operator_key'], time());
                session_regenerate_id(true); $_SESSION['update_operator'] = $fingerprint; $_SESSION['update_until'] = time() + 900;
                $authorized = true;
            }
            if (!$authorized) { throw new DomainException('Reauthenticate using the host installation operator key.'); }
            $action = $_POST['action'] ?? '';
            if ($action === 'begin') {
                if (!$fileOwned) { throw new DomainException('This installation runs in ' . $mode . ' mode and does not own its files. See the upgrade steps below instead of uploading a release here.'); }
                // Beginning a new operation always requires fresh proof, even with a session.
                pl_update_operator_attempt($directory, (string) ($_POST['operator_key'] ?? ''), time());
                $archive = $_FILES['archive'] ?? []; $metadata = $_FILES['metadata'] ?? [];
                $download = ($_POST['source'] ?? 'upload') === 'official';
                foreach ($download ? [$metadata] : [$archive, $metadata] as $file) {
                    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) { throw new DomainException('Upload both the release ZIP and signed release metadata.'); }
                }
                if (filesize($metadata['tmp_name']) > 4000000) { throw new DomainException('Signed metadata is too large.'); }
                $envelope = (string) file_get_contents($metadata['tmp_name']); $channel = (string) ($_POST['channel'] ?? 'stable');
                $downloaded = null;
                try {
                    if ($download) {
                        require_once __DIR__ . '/update_download_functions.php';
                        $installed = pl_update_json($root . '/PACKAGE-MANIFEST.json');
                        $keyPath = getenv('PL_UPDATE_PUBLIC_KEY') ?: $directory . '/publisher.pem';
                        if (!is_file($keyPath)) { throw new DomainException('Pin the publisher public key in private host configuration first.'); }
                        $verified = pl_update_verify_metadata($envelope, (string) file_get_contents($keyPath), $channel, (string) ($installed['version'] ?? ''));
                        $downloaded = pl_update_download_official($verified, $directory);
                    }
                    $state = pl_update_begin($root, $downloaded ?? $archive['tmp_name'], $envelope, $channel);
                } finally { if ($downloaded !== null && is_file($downloaded)) { unlink($downloaded); } }
            } elseif ($action === 'continue') {
                session_write_close(); // Do not block status polling behind a database restore.
                $state = pl_update_step($root);
            } elseif ($action !== 'authenticate') { throw new DomainException('Unknown installation operation.'); }
        }
        if ($authorized && $state === null && is_file($directory . '/updates/active.json')) {
            $id = pl_update_json($directory . '/updates/active.json')['id'] ?? '';
            if (is_string($id) && preg_match('/^[a-f0-9]{32}$/D', $id)) { $state = pl_update_json($directory . '/updates/' . $id . '/state.json'); }
        }
        if ($authorized && $state === null && is_file($directory . '/updates/last.json')) { $state = pl_update_json($directory . '/updates/last.json'); }
    } catch (Throwable $failure) {
        http_response_code(422);
        $error = $failure instanceof DomainException ? $failure->getMessage() : 'Installation operation is unavailable. Maintenance remains active if recovery is pending. Check private storage and database availability, then retry.';
    }
    $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    // A terminal checkpoint can precede marker removal if the worker is interrupted.
    // Keep Continue available until the durable marker is actually finalized.
    $active = $authorized && $state !== null && isset($directory) && is_file($directory . '/updates/active.json');
    // The recovery runtime is a fixed, copied set of update files and does not carry
    // i18n_functions.php; the maintenance page therefore asks for the locale only when the
    // application's own helpers happen to be loaded, and otherwise stays the English source.
    $locale = function_exists('pl_locale') ? pl_locale() : 'en';
    $direction = function_exists('pl_text_direction') ? pl_text_direction() : 'ltr';
    echo '<!doctype html><html lang="' . $escape($locale) . '" dir="' . $escape($direction) . '"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>PHP Ledger installation maintenance</title>';
    echo '<style nonce="' . $nonce . '">body{font:1rem system-ui;line-height:1.6;margin:2rem auto;padding:0 1rem;max-width:46rem;color:#172336}label{display:block;margin-top:1rem}input,select,button{font:inherit;max-width:100%;padding:.55rem}button{margin:1rem 0}input[type=password]{width:95%}[role=alert]{background:#fff1ed;padding:1rem}fieldset{margin:1rem 0}</style><body><main><h1>Installation maintenance</h1>';
    echo '<p>This operation affects every business. Use the private host installation operator key and the publisher-signed release files.</p>';
    if ($error !== '') { echo '<p role="alert">' . $escape($error) . '</p>'; }
    if ($authorized && $state) {
        echo '<p role="status">Release ' . $escape($state['version']) . ': <strong>' . $escape($state['phase']) . '</strong>.</p>';
        if ($state['phase'] === 'restored') { echo '<p>The update failed. The previous code, private configuration, keys and database were automatically restored and verified.</p>'; }
        if ($state['phase'] === 'recovering') { echo '<p>Recovery remains pending. Application access stays closed until the matched backup is restored and verified. Continue when storage and the database are reachable.</p>'; }
    }
    if ($active) {
        echo '<form method="post" id="continue"><input type="hidden" name="csrf" value="' . $escape($csrf) . '"><input type="hidden" name="action" value="continue"><button>Continue update or recovery</button></form><p>Progress continues while this page is open. Reopen this page after a host interruption to resume safely.</p>';
        if ($error === '') { echo '<script nonce="' . $nonce . '">setTimeout(()=>document.getElementById("continue").requestSubmit(),1200);</script>'; }
    } elseif (!$fileOwned) {
        echo '<p>This installation runs in <strong>' . $escape($mode) . '</strong> mode: it does not own its files, so a release is never installed from this page.</p>';
        echo '<p>Back up the database and the private data directory first.</p>';
        echo match ($mode) {
            'container' => '<p>Pull the new tag of <code>' . $escape(PL_RELEASE_IMAGE) . '</code> and restart the container. It applies pending migrations automatically when <code>PL_AUTO_MIGRATE=1</code> is set; otherwise run <code>php www/phpledger/install/migrate.php</code> in the container first.</p>',
            'composer' => '<p>Run <code>composer create-project phpledger/phpledger</code> at the new version into a fresh folder, copy your private configuration and storage folder across, point the web root at its <code>www/phpledger/public</code> folder, then run <code>php www/phpledger/install/migrate.php</code>.</p>',
            'panel' => '<p>Use your hosting panel\'s upgrade button for PHP Ledger.</p>',
            default => '',
        };
        echo '<form method="post"><input type="hidden" name="csrf" value="' . $escape($csrf) . '"><label>Host installation operator key<input type="password" name="operator_key" required autocomplete="off" maxlength="256"></label><button name="action" value="authenticate">Check update or recovery status</button></form>';
    } else {
        echo '<form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="' . $escape($csrf) . '"><label>Host installation operator key<input type="password" name="operator_key" required autocomplete="off" maxlength="256"></label>';
        echo '<button name="action" value="authenticate">Check update or recovery status</button><fieldset><legend>Start a signed update</legend><label>Release channel<select name="channel"><option value="stable">Stable</option><option value="preview">Preview, beta and release candidate</option></select></label><label>Package source<select name="source"><option value="upload">Upload ZIP</option><option value="official">Download official GitHub release</option></select></label><label>Release ZIP (upload source)<input type="file" name="archive" accept=".zip"></label><label>Signed metadata<input type="file" name="metadata" accept=".json"></label><button name="action" value="begin">Verify release and begin update</button></fieldset></form>';
    }
    echo '</main></body></html>'; exit;
}
