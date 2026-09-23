"""Real Apache/PHP regression for the demo's exact trusted TLS proxy.

Uses an already available release image, a network-disabled disposable container,
and synthetic loopback requests. No host ports, database, or external requests.
"""
import argparse
import hashlib
import json
from pathlib import Path
import subprocess
import tempfile
import time
import uuid


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--image', default='phpledger-release:1.3.0-rc4')
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    config = root / 'docker/demo-apache.conf'
    name = 'phpledger-proxy-https-test-' + uuid.uuid4().hex[:12]
    created = False

    def run(command, timeout=30):
        result = subprocess.run(command, capture_output=True, text=True, timeout=timeout)
        if result.returncode:
            raise RuntimeError('Fixture command failed: ' + ' '.join(command[:3])
                               + '\n' + result.stderr[-4000:])
        return result.stdout

    client = r'''
$case = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
$context = stream_context_create(['socket' => ['bindto' => $case['peer'] . ':0']]);
$socket = @stream_socket_client('tcp://127.0.0.1:8080', $errno, $message, 3,
    STREAM_CLIENT_CONNECT, $context);
if (!$socket) { fwrite(STDERR, "Apache not ready\n"); exit(2); }
stream_set_timeout($socket, 3);
$request = "GET /proxy-https-fixture.php HTTP/1.0\r\nHost: localhost\r\nConnection: close\r\n";
foreach ($case['headers'] as $key => $value) { $request .= $key . ': ' . $value . "\r\n"; }
fwrite($socket, $request . "\r\n");
$response = stream_get_contents($socket);
fclose($socket);
[$headers, $body] = explode("\r\n\r\n", $response, 2);
if (!preg_match('~^HTTP/1\.[01] 200 ~', $headers)) {
    fwrite(STDERR, "Unexpected fixture HTTP status\n"); exit(3);
}
json_decode($body, true, flags: JSON_THROW_ON_ERROR);
echo $body;
'''
    cases = [
        ('trusted_https', '127.0.0.1', {'X-Forwarded-Proto': 'https'}, 'on'),
        ('trusted_http', '127.0.0.1', {'X-Forwarded-Proto': 'http'}, None),
        ('trusted_missing', '127.0.0.1', {}, None),
        ('untrusted_https', '127.0.0.2', {'X-Forwarded-Proto': 'https'}, None),
        ('untrusted_forged_forwarding', '127.0.0.2', {
            'X-Forwarded-Proto': 'https', 'X-Forwarded-For': '127.0.0.1',
            'Forwarded': 'for=127.0.0.1;proto=https'}, None),
        ('trusted_multiple_schemes', '127.0.0.1', {'X-Forwarded-Proto': 'https,http'}, None),
        ('trusted_uppercase_scheme', '127.0.0.1', {'X-Forwarded-Proto': 'HTTPS'}, None),
    ]
    with tempfile.TemporaryDirectory() as directory:
        probe = Path(directory) / 'probe.php'
        probe.write_text("<?php header('Content-Type: application/json'); "
                         "echo json_encode(['https' => $_SERVER['HTTPS'] ?? null, "
                         "'peer' => $_SERVER['REMOTE_ADDR'] ?? null]);\n", encoding='utf-8')
        try:
            image_id = run(['docker', 'image', 'inspect', '--format', '{{.Id}}', args.image]).strip()
            run(['docker', 'run', '-d', '--name', name, '--pull', 'never', '--network', 'none',
                 '--memory', '256m', '-e', 'PL_TRUSTED_PROXY_IPS=127.0.0.1',
                 '--mount', 'type=bind,source=' + str(config) + ',target=/etc/apache2/sites-available/000-default.conf,readonly',
                 '--mount', 'type=bind,source=' + str(probe) + ',target=/var/www/phpledger/www/phpledger/public/proxy-https-fixture.php,readonly',
                 '--entrypoint', 'apache2-foreground', image_id])
            created = True
            run(['docker', 'exec', name, 'apache2ctl', '-t'])
            for attempt in range(20):
                result = subprocess.run(['docker', 'exec', name, 'php', '-r', client,
                                         json.dumps({'peer': '127.0.0.1', 'headers': {}})],
                                        capture_output=True, text=True, timeout=10)
                if result.returncode == 0:
                    break
                if result.returncode != 2 or attempt == 19:
                    raise AssertionError('Apache fixture readiness failed: ' + result.stderr[-2000:])
                time.sleep(0.25)
            for label, peer, headers, expected in cases:
                response = json.loads(run(['docker', 'exec', name, 'php', '-r', client,
                                           json.dumps({'peer': peer, 'headers': headers})]))
                assert response['peer'] == peer, (label, 'source binding was not exercised', response)
                assert response['https'] == expected, (label, response)
                print('PASS ' + label + ': PHP HTTPS=' + str(response['https']))
            print('PASS 7 real Apache/PHP cases; image=' + image_id)
            print('Config SHA256 ' + hashlib.sha256(config.read_bytes()).hexdigest())
        except Exception:
            if created:
                result = subprocess.run(['docker', 'logs', '--tail', '60', name],
                                        capture_output=True, text=True, timeout=15)
                print((result.stdout + result.stderr)[-6000:])
            raise
        finally:
            if created:
                run(['docker', 'rm', '-f', '-v', name])


if __name__ == '__main__':
    main()
