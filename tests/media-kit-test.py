#!/usr/bin/env python3
"""Media assembly tests use synthetic PNG/ZIP fixtures, never release evidence."""
import base64
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
import zipfile

spec = importlib.util.spec_from_file_location('media_builder', Path(__file__).resolve().parents[1] / 'tools/build-media-kit.py')
builder = importlib.util.module_from_spec(spec)
spec.loader.exec_module(builder)


class MediaKitTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.image = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j4qQAAAAASUVORK5CYII=')
        (self.root / 'fixture.png').write_bytes(self.image)
        app = self.root / 'fixture.zip'
        with zipfile.ZipFile(app, 'w') as z:
            z.writestr('phpledger/www/phpledger/VERSION', '1.3.0\n')
        sha = builder.digest(app.read_bytes())
        self.evidence = {'version': '1.3.0', 'fictional_sample_data': True, 'sample_identity': 'Synthetic unit test only',
                         'source_archive': {'path': 'fixture.zip', 'sha256': sha}, 'images': [{
                             'file': 'test-only.png', 'path': 'fixture.png', 'route': '/home', 'caption': 'Unit test fixture, not a product screenshot.',
                             'alt': 'Synthetic one-pixel test image.', 'capture_context': 'Unit test only; not release evidence.', 'reviewed': True,
                             'fictional_data': True, 'source_archive_sha256': sha, 'sha256': builder.digest(self.image), 'width': 1, 'height': 1}]}

    def tearDown(self):
        self.temp.cleanup()

    def build(self, folder='output'):
        path = self.root / 'evidence.json'
        path.write_text(json.dumps(self.evidence), encoding='utf-8')
        return builder.build('1.3.0', path, self.root / folder)

    def test_deterministic_original_bytes_and_no_local_paths(self):
        first, sha, count = self.build()
        second, same, _ = self.build('second')
        self.assertEqual(sha, same)
        self.assertEqual(first.read_bytes(), second.read_bytes())
        self.assertEqual(count, 1)
        with zipfile.ZipFile(first) as z:
            prefix = 'phpledger-1.3.0-media-kit/'
            self.assertEqual(z.read(prefix + 'screenshots/test-only.png'), self.image)
            manifest = z.read(prefix + 'screenshots/manifest.json').decode()
            self.assertNotIn(str(self.root), manifest)
            for row in z.read(prefix + 'CHECKSUMS.txt').decode().splitlines():
                digest, name = row.split('  ', 1)
                self.assertEqual(digest, builder.digest(z.read(prefix + name)))

    def test_artifact_provenance_and_review_are_required(self):
        original = json.loads(json.dumps(self.evidence))
        for kind in ('archive', 'capture', 'review', 'version'):
            with self.subTest(kind=kind):
                self.evidence = json.loads(json.dumps(original))
                if kind == 'archive': self.evidence['source_archive']['sha256'] = '0' * 64
                if kind == 'capture': self.evidence['images'][0]['source_archive_sha256'] = '0' * 64
                if kind == 'review': self.evidence['images'][0]['reviewed'] = False
                if kind == 'version': self.evidence['version'] = '1.2.0'
                with self.assertRaises(ValueError): self.build()
                self.assertFalse((self.root / 'output').exists())

    def test_image_mutation_and_unsafe_names_refused(self):
        original = json.loads(json.dumps(self.evidence))
        for key, value in [('sha256', '0' * 64), ('width', 2), ('file', '../escape.png'), ('route', '/home?session=fixture')]:
            with self.subTest(key=key):
                self.evidence = json.loads(json.dumps(original))
                self.evidence['images'][0][key] = value
                with self.assertRaises(ValueError): self.build()

    def test_capture_plan_cannot_publish_and_existing_output_preserved(self):
        original = self.evidence['images']
        self.evidence['images'] = []
        with self.assertRaises(ValueError): self.build()
        self.evidence['images'] = original
        archive, _, _ = self.build()
        before = archive.read_bytes()
        self.evidence['images'][0]['caption'] = 'Changed reviewed copy'
        with self.assertRaises(ValueError): self.build()
        self.assertEqual(before, archive.read_bytes())


if __name__ == '__main__':
    unittest.main()
