#!/usr/bin/env python3
"""Assemble versioned media copy only with operator-reviewed exact-artifact PNG evidence."""
import argparse
import hashlib
import io
import json
from pathlib import Path
import re
import struct
import zipfile

ROOT = Path(__file__).resolve().parents[1]
TEXT_FILES = ('README.md', 'ANNOUNCEMENT.md', 'SOCIAL.md', 'EMAIL.md', 'GUIDED-DEMO.md', 'FAQ.md')


def digest(data):
    return hashlib.sha256(data).hexdigest()


def source_files(version, source=None):
    if not re.fullmatch(r'[0-9]+\.[0-9]+\.0', version):
        raise ValueError('Media kits are required for x.y.0 releases; use an explicit stable version.')
    source = Path(source) if source else ROOT / 'docs/design/website/media-kit' / version
    result = {}
    for name in TEXT_FILES:
        data = (source / name).read_bytes().replace(b'\r\n', b'\n')
        text = data.decode('utf-8')
        if not text.strip() or '{{' in text:
            raise ValueError(f'{name}: empty copy or unresolved template token')
        result[name] = data
    return result


def checked_hash(value):
    if not isinstance(value, str) or not re.fullmatch(r'[a-f0-9]{64}', value):
        raise ValueError('Expected a lowercase SHA-256 digest.')
    return value


def bounded_bytes(path, limit):
    if path.is_symlink() or not path.is_file() or path.stat().st_size > limit:
        raise ValueError(f'Input must be a regular bounded file: {path.name}')
    return path.read_bytes()


def build(version, evidence_path, output, source=None):
    members = source_files(version, source)
    evidence_path = Path(evidence_path).resolve()
    evidence = json.loads(bounded_bytes(evidence_path, 1000000))
    if evidence.get('version') != version or evidence.get('fictional_sample_data') is not True:
        raise ValueError('Evidence version and fictional-data declaration are required.')
    archive = evidence.get('source_archive', {})
    archive_path = Path(archive.get('path', ''))
    if not archive_path.is_absolute():
        archive_path = evidence_path.parent / archive_path
    archive_bytes = bounded_bytes(archive_path, 150000000)
    archive_hash = checked_hash(archive.get('sha256'))
    if digest(archive_bytes) != archive_hash:
        raise ValueError('Application archive does not match the declared final artifact.')
    with zipfile.ZipFile(io.BytesIO(archive_bytes)) as app:
        candidates = [n for n in app.namelist() if n.endswith('/www/phpledger/VERSION')]
        if len(candidates) != 1 or app.read(candidates[0]).decode('ascii').strip() != version:
            raise ValueError('Application archive VERSION does not match the requested release.')
    shots = evidence.get('images')
    if not isinstance(shots, list) or not shots or len(shots) > 40:
        raise ValueError('Provide reviewed screenshot evidence; a capture plan cannot build a release kit.')
    identity = evidence.get('sample_identity')
    if not isinstance(identity, str) or not identity.strip():
        raise ValueError('Describe the fictional sample identity.')
    manifest = {'release': version, 'release_url': f'https://github.com/phpledger/phpledger/releases/tag/v{version}',
                'captured_from': {'file': f'phpledger-{version}.zip', 'bytes': len(archive_bytes), 'sha256': archive_hash},
                'sample_identity': identity, 'fictional_sample_data': True,
                'evidence_scope': 'Operator-reviewed local captures from the declared exact archive; no independent-review or live-publication claim.', 'images': []}
    seen = set()
    for shot in shots:
        name = shot.get('file', '')
        if not isinstance(name, str) or not re.fullmatch(r'[a-z0-9][a-z0-9-]*\.png', name) or name in seen:
            raise ValueError('Screenshot filenames must be unique safe PNG basenames.')
        seen.add(name)
        if shot.get('reviewed') is not True or shot.get('fictional_data') is not True or shot.get('source_archive_sha256') != archive_hash:
            raise ValueError(f'{name}: final artifact provenance and visual review are required.')
        for key in ('route', 'caption', 'alt', 'capture_context'):
            if not isinstance(shot.get(key), str) or not shot[key].strip() or len(shot[key]) > 2000:
                raise ValueError(f'{name}: missing or excessive {key}')
        if not shot['route'].startswith('/') or '?' in shot['route'] or '#' in shot['route']:
            raise ValueError('Use a route without query strings or session identifiers.')
        path = Path(shot.get('path', ''))
        if not path.is_absolute():
            path = evidence_path.parent / path
        data = bounded_bytes(path, 15000000)
        if len(data) < 24 or data[:8] != b'\x89PNG\r\n\x1a\n' or data[12:16] != b'IHDR':
            raise ValueError(f'{name}: original PNG required')
        width, height = struct.unpack('>II', data[16:24])
        if not (1 <= width <= 10000 and 1 <= height <= 30000):
            raise ValueError('Unsupported screenshot dimensions.')
        if shot.get('width') != width or shot.get('height') != height or checked_hash(shot.get('sha256')) != digest(data):
            raise ValueError(f'{name}: screenshot bytes or dimensions differ from reviewed evidence.')
        members['screenshots/' + name] = data
        manifest['images'].append({'file': 'screenshots/' + name, 'route': shot['route'], 'width': width, 'height': height,
                                   'bytes': len(data), 'sha256': digest(data), 'caption': shot['caption'], 'alt': shot['alt'],
                                   'capture_context': shot['capture_context'], 'transformation': 'None; original PNG bytes', 'reviewed': True})
    members['screenshots/manifest.json'] = (json.dumps(manifest, indent=2, ensure_ascii=False) + '\n').encode('utf-8')
    members['CHECKSUMS.txt'] = ''.join(f'{digest(data)}  {name}\n' for name, data in sorted(members.items())).encode('ascii')
    root_name = f'phpledger-{version}-media-kit'
    buffer = io.BytesIO()
    with zipfile.ZipFile(buffer, 'w', zipfile.ZIP_DEFLATED) as kit:
        for name, data in sorted(members.items()):
            info = zipfile.ZipInfo(root_name + '/' + name, (1980, 1, 1, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            info.create_system = 3
            info.external_attr = 0o100644 << 16
            kit.writestr(info, data, compresslevel=9)
    output = Path(output).resolve()
    output.mkdir(parents=True, exist_ok=True)
    data = buffer.getvalue()
    archive_out = output / (root_name + '.zip')
    checksum = f'{digest(data)}  {archive_out.name}\n'.encode('ascii')
    for path, content in ((archive_out, data), (Path(str(archive_out) + '.sha256'), checksum)):
        if path.exists() and path.read_bytes() != content:
            raise ValueError('Output already exists with different bytes; use a new output directory.')
    for path, content in ((archive_out, data), (Path(str(archive_out) + '.sha256'), checksum)):
        if not path.exists():
            with path.open('xb') as handle:
                handle.write(content)
    return archive_out, digest(data), len(shots)


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--version', required=True)
    parser.add_argument('--source', type=Path)
    parser.add_argument('--check-source', action='store_true')
    parser.add_argument('--evidence', type=Path)
    parser.add_argument('--output', type=Path)
    args = parser.parse_args()
    if args.check_source:
        print(f'Validated {len(source_files(args.version, args.source))} copy sources; no final media archive produced.')
    else:
        if args.evidence is None or args.output is None:
            parser.error('--evidence and --output are required for final assembly')
        archive, sha, count = build(args.version, args.evidence, args.output, args.source)
        print(f'{archive.name}: {count} reviewed screenshots; SHA-256 {sha}')
