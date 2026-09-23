"""Local fake-host fixtures; no SSH, Docker or HTTP requests are made."""
import contextlib
import copy
import importlib.util
import io
import json
from pathlib import Path, PurePosixPath
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('hosting_status', Path(__file__).parents[1] / 'tools/hosting-status.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
ROOT = '/var/www/phpledger/data/releases/1.3.0-reviewed'
MANIFEST = {'version': '1.3.0', 'source_commit': 'a' * 40, 'files': []}

class Fixtures(unittest.TestCase):
    def run_status(self, layout='release-image', mutate=None):
        files = {ROOT + '/PACKAGE-MANIFEST.json': json.dumps(MANIFEST).encode(),
                 ROOT + '/docker/shared-demo-guard.php': b'guard fixture',
                 ROOT + '/tools/shared-demo-scheduler.sh': b'scheduler fixture'}
        items = {}
        for role in ['web', 'scheduler', 'db']:
            mounts = []
            if role != 'db':
                if layout == 'application-bind':
                    mounts = [{'Source': ROOT + '/www/phpledger', 'Destination': '/var/www/phpledger/www/phpledger'}]
                else:
                    source, target = ('docker/shared-demo-guard.php', '/opt/phpledger-demo/request-guard.php') if role == 'web' else ('tools/shared-demo-scheduler.sh', '/var/www/phpledger/tools/shared-demo-scheduler.sh')
                    mounts = [{'Source': ROOT + '/' + source, 'Destination': target, 'Type': 'bind', 'RW': False}]
            items[role] = {'Id': role * 12, 'State': {'Running': True}, 'Config': {'Image': 'fixture:1.3.0', 'Env': ['SECRET=must-never-appear']}, 'Image': 'sha256:' + 'b' * 64, 'Mounts': mounts}
        image_manifests = {'web': copy.deepcopy(MANIFEST), 'scheduler': copy.deepcopy(MANIFEST)}
        if mutate:
            mutate(items, image_manifests)
        class FakePath(PurePosixPath):
            def read_bytes(self):
                return files[str(self)]
            def read_text(self):
                return self.read_bytes().decode()
            def is_file(self):
                return str(self) in files
            def is_symlink(self):
                return False
        calls = []
        def run(args, **kwargs):
            calls.append(args)
            if args == ['nginx', '-T']:
                return '# configuration file /etc/nginx/fastpanel2-sites/phpledger/phpledger.com.conf:\n root ' + ROOT + '/www/website/public;\n'
            role = args[2].removeprefix('phpledger-demo-demo-').removesuffix('-1')
            if args[:2] == ['docker', 'inspect']:
                return json.dumps([items[role]])
            self.assertEqual(['docker', 'exec', args[2], 'cat', '/var/www/phpledger/PACKAGE-MANIFEST.json'], args)
            return json.dumps(image_manifests[role])
        class Response(io.StringIO):
            status = 200
        output = io.StringIO()
        with patch('pathlib.Path', FakePath), patch('subprocess.check_output', run), patch('urllib.request.urlopen', return_value=Response('{"status":"ok"}')), contextlib.redirect_stdout(output):
            exec(compile(module.REMOTE_STATUS, '<remote-status-fixture>', 'exec'), {})
        self.assertNotIn('must-never-appear', output.getvalue())
        self.assertNotIn('Config', output.getvalue())
        return json.loads(output.getvalue()), calls

    def test_legacy_bind_layout(self):
        result, calls = self.run_status('application-bind')
        self.assertTrue(result['ok'])
        self.assertEqual('1.3.0', result['demo']['version'])
        self.assertEqual('application-bind', result['demo']['containers'][0]['layout'])
        self.assertFalse(any(call[:2] == ['docker', 'exec'] for call in calls))

    def test_release_image_manifest_and_mount_hashes(self):
        result, calls = self.run_status()
        self.assertTrue(result['ok'])
        self.assertEqual('a' * 40, result['demo']['source_commit'])
        self.assertEqual(2, sum(call[:2] == ['docker', 'exec'] for call in calls))
        for row in result['demo']['containers'][:2]:
            self.assertEqual(64, len(row['manifest_sha256']))
            self.assertEqual(64, len(row['hosting_mount']['sha256']))

    def test_mismatched_image_or_manifest_reported(self):
        def mutate(items, manifests):
            items['scheduler']['Image'] = 'different'
            manifests['scheduler']['version'] = '1.2.1'
        result, _ = self.run_status(mutate=mutate)
        self.assertFalse(result['ok'])
        self.assertIn('web_scheduler_image_mismatch', result['problems'])
        self.assertIn('web_scheduler_manifest_mismatch', result['problems'])

    def test_mounts_outside_release_or_writable_refused(self):
        for field, value in [('Source', '/tmp/docker/shared-demo-guard.php'), ('RW', True), ('Type', 'volume')]:
            with self.subTest(field=field), self.assertRaises(RuntimeError):
                self.run_status(mutate=lambda items, manifests: items['web']['Mounts'][0].update({field: value}))

    def test_missing_mount_refused(self):
        with self.assertRaises(RuntimeError):
            self.run_status(mutate=lambda items, manifests: items['web'].update({'Mounts': []}))

if __name__ == '__main__':
    unittest.main()
