#!/usr/bin/env python3
"""Local-only notice receiver tests against an explicitly named disposable Compose project."""
import argparse
import concurrent.futures
import datetime
import hashlib
import json
import subprocess
import time
import unittest
import urllib.error
import urllib.request
import uuid

parser = argparse.ArgumentParser()
parser.add_argument('--project', default='codex13notice')
parser.add_argument('--port', type=int, default=18302)
args, remaining = parser.parse_known_args()
if not args.project.startswith('codex') or not args.project.replace('-', '').isalnum():
    raise SystemExit('Use a disposable codex-prefixed Compose project only.')
CONTAINER = args.project + '-receiver-1'
BASE = f'http://127.0.0.1:{args.port}'
SERVICE = '/opt/installation-service'
ROOT = '/var/lib/phpledger-notices'


def docker(*parts):
    return subprocess.run(['docker', *parts], check=True, text=True, capture_output=True).stdout


def php(code):
    return docker('exec', CONTAINER, 'php', '-r', f"require '{SERVICE}/includes/receiver.php'; " + code)


def payload(identity=None):
    return {'schema': 1, 'installation_id': identity or uuid.uuid4().hex, 'event': 'install',
            'version': '1.3.0', 'channel': 'stable', 'php_version': '8.3.33',
            'database_engine': 'mysql', 'database_version': '8.4.7', 'os_family': 'Linux',
            'mode': 'managed', 'at': datetime.datetime.now(datetime.timezone.utc).isoformat(timespec='seconds')}


def request(data=None, *, raw=None, method='POST', path='/installations/notice', ip='192.0.2.10', content_type='application/json'):
    body = raw if raw is not None else (json.dumps(data).encode() if data is not None else None)
    req = urllib.request.Request(BASE + path, data=body, method=method,
                                 headers={'Content-Type': content_type, 'X-Real-IP': ip})
    try:
        response = urllib.request.urlopen(req, timeout=6)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        return response.status, response.read(), dict(response.headers)


def record_path(identity):
    digest = hashlib.sha256(identity.encode()).hexdigest()
    return f'{ROOT}/records/{digest[:2]}/{digest}.json'


class ReceiverTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        state = json.loads(docker('inspect', CONTAINER))[0]
        if state['Config']['Labels'].get('com.docker.compose.project') != args.project:
            raise RuntimeError('Disposable project identity mismatch')
        cls.identities = []

    def notice(self):
        notice = payload()
        self.identities.append(notice['installation_id'])
        return notice

    @classmethod
    def tearDownClass(cls):
        # Delete only the exact random IDs created by this test invocation.
        identities = json.dumps(cls.identities)
        php("$ids=json_decode(" + json.dumps(identities) + ",true); pln_locked(function($root)use($ids){foreach($ids as $id){$h=hash('sha256',$id);$p=$root.'/records/'.substr($h,0,2).'/'.$h.'.json';if(pln_file($p)!==null){unlink($p);}}});")

    def test_01_accepts_only_documented_fields(self):
        notice = self.notice()
        status, body, headers = request(notice)
        self.assertEqual((200, {'accepted': True}), (status, json.loads(body)))
        self.assertEqual('no-store', headers.get('Cache-Control'))
        self.assertNotIn('Set-Cookie', headers)
        stored = json.loads(php(f"echo json_encode(pln_read('{record_path(notice['installation_id'])}'));"))
        self.assertEqual(notice, stored['notice'])
        for key in ['database_password', 'accounts', 'journals', 'database_host', 'unknown']:
            with self.subTest(key=key):
                self.assertEqual(400, request(notice | {key: 'must-not-store'})[0])
        self.assertEqual(400, request(notice | {'schema': '1'})[0])
        self.assertEqual(400, request(notice | {'channel': 'preview'})[0])
        self.assertEqual(400, request(notice | {'at': '2026-02-31T12:00:00+00:00'})[0])
        self.assertEqual(400, request(notice | {'os_family': 'hidden-hostname'})[0])
        self.assertEqual(400, request(notice | {'mode': 'arbitrary'})[0])
        self.assertEqual(400, request(raw=b'[]')[0])
        self.assertEqual(400, request(raw=b'{')[0])
        duplicate = json.dumps(notice)[:-1] + ',"schema":1}'
        self.assertEqual(400, request(raw=duplicate.encode())[0])
        escaped = json.dumps(notice)[:-1] + ',"sche\\u006da":1}'
        self.assertEqual(400, request(raw=escaped.encode())[0])
        self.assertEqual(400, request(notice, content_type='text/plain')[0])

    def test_02_method_size_and_private_routes(self):
        for method in ['GET', 'PUT', 'DELETE', 'OPTIONS', 'HEAD']:
            self.assertEqual(405, request(method=method)[0])
        self.assertEqual(413, request(raw=b' ' * 8193)[0])
        for path in ['/', '/summary', '/index.php', '/includes/receiver.php', '/summary.php', '/records']:
            self.assertEqual(404, request(method='GET', path=path)[0])

    def test_03_registration_is_explicit_replaceable_and_not_in_summary(self):
        notice = self.notice()
        named = notice | {'registration': {'name': 'Sample registrant', 'email': 'sample-registrant@example.invalid',
                                          'site': 'https://example.invalid/books', 'company': 'Sample optional company'}}
        self.assertEqual(200, request(named, ip='192.0.2.11')[0])
        summary = docker('exec', CONTAINER, 'php', SERVICE + '/summary.php', '--summary')
        self.assertNotIn('Sample registrant', summary)
        self.assertNotIn('sample-registrant@example.invalid', summary)
        self.assertNotIn(notice['installation_id'], summary)
        for bad in [{'name': 'Someone', 'email': 'bad'}, {'name': 'Someone', 'email': 'a@example.invalid', 'accounts': []},
                    {'name': 'Someone', 'email': 'a@example.invalid', 'site': 'https://user:password@example.invalid'},
                    {'name': 'Someone', 'email': 'a@example.invalid', 'site': 'https://example.invalid/?secret=1'}]:
            self.assertEqual(400, request(notice | {'registration': bad}, ip='192.0.2.11')[0])
        self.assertEqual(200, request(notice | {'event': 'preferences'}, ip='192.0.2.11')[0])
        stored = json.loads(php(f"echo json_encode(pln_read('{record_path(notice['installation_id'])}'));"))
        self.assertNotIn('registration', stored['notice'])

    def test_04_concurrent_same_installation_writes_are_atomic(self):
        notice = self.notice()
        def send(index):
            return request(notice | {'version': f'1.3.{index}'}, ip='192.0.2.12')[0]
        with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
            self.assertEqual([200] * 16, list(pool.map(send, range(16))))
        stored = json.loads(php(f"echo json_encode(pln_read('{record_path(notice['installation_id'])}'));"))
        self.assertEqual(notice['installation_id'], stored['notice']['installation_id'])
        self.assertIn(stored['notice']['version'], [f'1.3.{i}' for i in range(16)])

    def test_05_rate_limit_and_no_raw_ip_storage(self):
        # Only reserved fixture addresses in this verified disposable project.
        php("pln_locked(function($root){$key=pln_read($root.'/rate-key.json')['key'];foreach(['198.51.100.20','198.51.100.21']as$ip){$h=hash_hmac('sha256',gmdate('Y-m-d').\"\\0\".inet_pton($ip),$key);$p=$root.'/rates/'.substr($h,0,2).'/'.$h.'.json';if(pln_file($p)!==null){unlink($p);}}});")
        if time.time() % 60 > 54:
            time.sleep(61 - time.time() % 60)
        notice = self.notice()
        statuses = [request(notice, ip='198.51.100.20')[0] for _ in range(61)]
        self.assertEqual([200] * 60 + [429], statuses)
        self.assertEqual(200, request(notice, ip='198.51.100.21')[0])
        scan = php("$bad=false;foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(pln_root(),FilesystemIterator::SKIP_DOTS))as$f){if($f->isFile()&&str_contains(file_get_contents($f->getPathname()),'198.51.100.20')){$bad=true;}}echo $bad?'bad':'clean';")
        self.assertEqual('clean', scan)

    def test_06_retention_and_owner_delete(self):
        notice = self.notice()
        self.assertEqual(200, request(notice, ip='192.0.2.13')[0])
        path = record_path(notice['installation_id'])
        php(f"pln_locked(function(){{$p='{path}';$r=pln_read($p);$r['received_at']=time()-PLN_RETENTION-1;pln_write($p,$r);}});")
        result = json.loads(docker('exec', CONTAINER, 'php', SERVICE + '/summary.php', '--prune'))
        self.assertGreaterEqual(result['expired_removed']['records'], 1)
        self.assertEqual('null', php(f"echo json_encode(pln_read('{path}'));"))
        self.assertEqual(200, request(notice, ip='192.0.2.13')[0])
        result = json.loads(docker('exec', CONTAINER, 'php', SERVICE + '/summary.php', '--delete=' + notice['installation_id']))
        self.assertTrue(result['deleted'])

    def test_07_symlinks_and_hardlinks_fail_closed(self):
        notice = self.notice()
        path = record_path(notice['installation_id'])
        sentinel = '/var/lib/phpledger-notices/pln-sentinel-' + uuid.uuid4().hex
        php(f"pln_directory(dirname('{path}'));file_put_contents('{sentinel}','preserve-this');chmod('{sentinel}',0600);symlink('{sentinel}','{path}');")
        try:
            self.assertEqual(503, request(notice, ip='192.0.2.14')[0])
            self.assertEqual('preserve-this', php(f"echo file_get_contents('{sentinel}');"))
            php(f"unlink('{path}');link('{sentinel}','{path}');")
            self.assertEqual(503, request(notice, ip='192.0.2.14')[0])
            self.assertEqual('preserve-this', php(f"echo file_get_contents('{sentinel}');"))
        finally:
            php(f"unlink('{path}');unlink('{sentinel}');")
        link = '/tmp/pln-root-link-' + uuid.uuid4().hex
        php(f"symlink(pln_root(),'{link}');")
        try:
            result = subprocess.run(['docker', 'exec', '-e', 'PL_NOTICE_DIRECTORY=' + link, CONTAINER, 'php', SERVICE + '/summary.php'], capture_output=True)
            self.assertEqual(1, result.returncode)
        finally:
            php(f"unlink('{link}');")

    def test_08_isolated_runtime_has_no_outbound_route(self):
        state = json.loads(docker('inspect', CONTAINER))[0]
        self.assertTrue(state['HostConfig']['ReadonlyRootfs'])
        self.assertIn('ALL', state['HostConfig']['CapDrop'])
        self.assertEqual([args.project + '_private'], list(state['NetworkSettings']['Networks']))
        network = json.loads(docker('network', 'inspect', args.project + '_private'))[0]
        self.assertTrue(network['Internal'])
        routes = docker('exec', CONTAINER, 'cat', '/proc/net/route').splitlines()[1:]
        self.assertFalse(any(line.split()[1] == '00000000' for line in routes))
        self.assertIn('www-data', state['Config']['User'])


if __name__ == '__main__':
    unittest.main(argv=['receiver-test', *remaining], verbosity=2)
