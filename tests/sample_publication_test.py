"""Offline rejection tests for the finite sample publication gate; never invokes GitHub."""
import base64
import importlib.util
import io
import json
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch
import zipfile

from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import padding, rsa

SPEC = importlib.util.spec_from_file_location('publication', Path(__file__).resolve().parents[1] / 'tools/publish-sample-packages.py')
publication = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(publication)


class SamplePublicationTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='sample-publication-test-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.artifacts = self.root / 'artifacts'; self.artifacts.mkdir()
        self.preloads = self.root / 'preloads'; self.preloads.mkdir()
        key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
        self.key = self.root / 'resources/release/publisher-public.pem'; self.key.parent.mkdir(parents=True)
        self.key.write_bytes(key.public_key().public_bytes(serialization.Encoding.PEM, serialization.PublicFormat.SubjectPublicKeyInfo))
        self.root_patch = patch.object(publication, 'ROOT', self.root); self.root_patch.start()
        self.addCleanup(self.root_patch.stop)
        entries = []; signed = []
        for slug in publication.SLUGS:
            base = self.artifacts / slug / '1.0.0'; repo = base / 'repository'; repo.mkdir(parents=True)
            payload = {'pack.json': b'{"fictional":true}', 'structure.json': b'{"empty_balances":true}'}
            manifest = {'type': 'sample', 'contract': 1, 'slug': slug, 'version': '1.0.0', 'name': 'Sample',
                        'licence': 'CC0-1.0', 'homepage': 'https://phpledger.com/directory/' + slug + '/',
                        'files': {p: publication.sha(data) for p, data in payload.items()}}
            payload['package.json'] = json.dumps(manifest).encode()
            for name, data in payload.items(): (repo / name).write_bytes(data)
            (repo / 'LICENSE').write_text('CC0-1.0'); (repo / 'README.md').write_text('Fictional sample')
            archive = io.BytesIO()
            with zipfile.ZipFile(archive, 'w') as z:
                for name, data in payload.items(): z.writestr(slug + '/' + name, data)
            raw = archive.getvalue(); archive_name = slug + '-1.0.0.zip'; (base / archive_name).write_bytes(raw)
            inventory = {'schema': 1, 'type': 'sample', 'slug': slug, 'version': '1.0.0', 'archive_bytes': len(raw),
                         'archive_sha256': publication.sha(raw), 'manifest_sha256': publication.sha(payload['package.json']),
                         'files': manifest['files']}
            encoded = json.dumps(inventory).encode()
            envelope = json.dumps({'payload': base64.b64encode(encoded).decode(),
                'signature': base64.b64encode(key.sign(encoded, padding.PKCS1v15(), hashes.SHA256())).decode()}).encode()
            (base / 'sample-envelope.json').write_bytes(envelope)
            (base / 'sample-metadata.json').write_bytes(encoded)
            (base / 'package.json').write_bytes(payload['package.json'])
            (base / (archive_name + '.sha256')).write_text(inventory['archive_sha256'] + '  ' + archive_name + '\n')
            preload = self.preloads / slug; preload.mkdir()
            for name, data in payload.items(): (preload / name).write_bytes(data)
            (preload / 'sample-envelope.json').write_bytes(envelope)
            entries.append({'slug': slug, 'version': '1.0.0', 'manifest': manifest, 'inventory': inventory})
            signed.append({'slug': slug, 'version': '1.0.0', 'archive_sha256': inventory['archive_sha256'], 'envelope_sha256': publication.sha(envelope)})
        (self.artifacts / 'index.json').write_text(json.dumps({'schema': 1, 'packages': entries}))
        (self.artifacts / 'signing-receipt.json').write_text(json.dumps({'publisher_spki_sha256': publication.fingerprint(key.public_key()), 'samples': signed}))

    def audit(self):
        return publication.audit(self.artifacts, self.preloads, self.key)

    def test_valid_signed_inventory_produces_exact_source_and_asset_allowlists(self):
        plan, frozen = self.audit()
        self.assertEqual(11, len(plan['repositories']))
        for row in plan['repositories']:
            self.assertEqual(set(publication.SOURCE_FILES), set(frozen[row['slug']][0]))
            self.assertEqual(3, len(frozen[row['slug']][1]))

    def test_changed_archive_is_refused(self):
        slug = publication.SLUGS[0]
        (self.artifacts / slug / '1.0.0' / (slug + '-1.0.0.zip')).write_bytes(b'not the signed archive')
        with self.assertRaisesRegex(ValueError, 'archive hash differs'): self.audit()

    def test_changed_signature_is_refused(self):
        path = self.artifacts / publication.SLUGS[0] / '1.0.0/sample-envelope.json'
        envelope = json.loads(path.read_bytes()); envelope['signature'] = base64.b64encode(b'wrong').decode()
        path.write_text(json.dumps(envelope))
        with self.assertRaises(Exception): self.audit()

    def test_unsigned_extra_source_is_refused(self):
        (self.artifacts / publication.SLUGS[0] / '1.0.0/repository/unreviewed.txt').write_text('extra')
        with self.assertRaisesRegex(ValueError, 'unexpected repository file'): self.audit()

    def test_changed_readonly_preload_is_refused(self):
        (self.preloads / publication.SLUGS[0] / 'pack.json').write_bytes(b'changed')
        with self.assertRaisesRegex(ValueError, 'preload differs'): self.audit()

    def test_any_existing_repository_is_a_collision(self):
        plan, _ = self.audit()
        with patch.object(publication.subprocess, 'run', return_value=subprocess.CompletedProcess([], 0, '{}', '')) as mock:
            with self.assertRaisesRegex(RuntimeError, 'already exists'): publication.collision_check(plan)
            self.assertEqual(1, mock.call_count)
            self.assertEqual(['gh', 'api'], mock.call_args.args[0][:2])

    def test_network_or_permission_error_is_not_treated_as_absence(self):
        plan, _ = self.audit()
        with patch.object(publication.subprocess, 'run', return_value=subprocess.CompletedProcess([], 1, '', 'HTTP 403')):
            with self.assertRaisesRegex(ValueError, 'establish repository absence'): publication.collision_check(plan)

    def test_execute_refuses_inputs_changed_since_review_without_external_writes(self):
        plan = self.root / 'review.json'; plan.write_text('{"old":"review"}')
        argv = ['publish-sample-packages.py', '--artifacts', str(self.artifacts), '--preloads', str(self.preloads),
                '--plan', str(plan), '--receipt', str(self.root / 'receipt.json'), '--execute']
        with patch('sys.argv', argv), patch.object(publication, 'collision_check'), patch.object(publication, 'execute') as execute:
            with self.assertRaisesRegex(ValueError, 'differ from the reviewed plan'): publication.main()
            execute.assert_not_called()
        self.assertEqual({'old': 'review'}, json.loads(plan.read_text()))


if __name__ == '__main__': unittest.main()
