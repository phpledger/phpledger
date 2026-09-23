#!/usr/bin/env python3
"""Build deterministic CC0 sample packages and unsigned inventories for publisher signing."""
import argparse
import hashlib
import json
from pathlib import Path
import zipfile

ROOT = Path(__file__).resolve().parents[1]

def raw(path):
    return path.read_bytes().replace(b'\r\n', b'\n')

def digest(data):
    return hashlib.sha256(data).hexdigest()

def json_bytes(value):
    return (json.dumps(value, indent=2, ensure_ascii=False) + '\n').encode('utf-8')

def build(output):
    output.mkdir(parents=True, exist_ok=True)
    catalogue = json.loads(raw(ROOT / 'resources/demo-packs/catalog.json'))
    modules = {m['id']: m for p in (ROOT / 'resources/modules').glob('*.json') if (m := json.loads(raw(p)))}
    directory = {'schema': 1, 'packages': []}
    for entry in catalogue:
        slug = 'sample-' + entry['id']
        version = entry['version']
        pack = raw(ROOT / 'resources/demo-packs' / entry['file'])
        structure = raw(ROOT / 'resources/sample-structures' / f"{entry['id']}-{version}.json")
        data = json.loads(structure)
        if digest(pack) != entry['sha256'] or data['source_digest'] != entry['sha256']:
            raise ValueError(f'{slug}: regenerate sample sources and structures before packaging')
        if data.get('packages'):
            raise ValueError(f'{slug}: declare reviewed exact plugin dependency versions before publication')
        requires = {'core': modules['core']['version']}
        for module in data['modules']:
            requires[module] = modules[module]['version']
        manifest = {'type': 'sample', 'slug': slug, 'version': version, 'contract': 1,
                    'name': entry['name'], 'description': entry['business'] + '. ' + entry['capability_note'],
                    'author': 'PHP Ledger', 'licence': 'CC0-1.0',
                    'homepage': f'https://phpledger.com/directory/{slug}/',
                    'requires': dict(sorted(requires.items())), 'sample': entry,
                    'files': {'pack.json': digest(pack), 'structure.json': digest(structure)}}
        payload = {'package.json': json_bytes(manifest), 'pack.json': pack, 'structure.json': structure}
        target = output / slug / version
        target.mkdir(parents=True, exist_ok=True)
        archive = target / f'{slug}-{version}.zip'
        with zipfile.ZipFile(archive, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as z:
            for name, content in sorted(payload.items()):
                info = zipfile.ZipInfo(f'{slug}/{name}', (2026, 1, 1, 0, 0, 0))
                info.compress_type = zipfile.ZIP_DEFLATED
                info.external_attr = 0o100644 << 16
                z.writestr(info, content, compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
        inventory = {'schema': 1, 'type': 'sample', 'slug': slug, 'version': version,
                     'archive_bytes': archive.stat().st_size, 'archive_sha256': digest(archive.read_bytes()),
                     'manifest_sha256': digest(payload['package.json']), 'files': manifest['files']}
        (target / 'sample-metadata.json').write_bytes(json_bytes(inventory))
        (target / 'package.json').write_bytes(payload['package.json'])
        repository = target / 'repository'
        repository.mkdir(parents=True, exist_ok=True)
        for name, content in payload.items():
            (repository / name).write_bytes(content)
        (repository / 'LICENSE').write_text('SPDX-License-Identifier: CC0-1.0\n\nPHP Ledger dedicates this fictional sample data to the public domain under CC0 1.0 Universal.\nSee https://creativecommons.org/publicdomain/zero/1.0/legalcode.en for the complete legal terms.\n', encoding='utf-8', newline='\n')
        readme = f'''# {entry['name']}

Version {version}; data-only sample contract 1; CC0-1.0.

{manifest['description']}

This repository contains fictional practice data, not a statutory or industry compliance product. Install through PHP Ledger Packages or upload the release ZIP. Full history creates a separate sample company; the structure option starts a real business with zero balances. Dependencies are declared with exact versions in package.json and must be installed and reviewed separately.

## Contents and reproducible release

The runtime ZIP contains only `{slug}/package.json`, `{slug}/pack.json` and `{slug}/structure.json`. README and LICENSE are repository documentation, not executable package members. SHA-256 file pins are recorded in package.json. The signed publisher inventory is published at https://phpledger.com/directory/{slug}/{version}/sample-envelope.json. Download the versioned ZIP from https://github.com/phpledger/{slug}/releases/tag/v{version}.

Authoring source and deterministic builder: https://github.com/phpledger/phpledger/blob/master/tools/build-sample-packages.py. Regenerate from the reviewed main-repository sample sources, then copy this exact repository output and attach the generated ZIP. Do not edit a published version or reuse its version number after changing bytes. Bump the source catalogue/structure version and review every monthly reconciliation before a new release. Signing and publication require the project operator; no signing key belongs in this repository.

Submit corrections through pull requests with the source changes, changed hashes, dependency review, licence confirmation, and relevant accounting reconciliation evidence. Directory: https://phpledger.com/directory/{slug}/.
'''
        (repository / 'README.md').write_text(readme, encoding='utf-8', newline='\n')
        directory['packages'].append({**{key: manifest[key] for key in ['type', 'slug', 'version', 'name', 'description', 'author', 'licence', 'homepage', 'requires']}, 'manifest': manifest, 'inventory': inventory})
    (output / 'index.json').write_bytes(json_bytes(directory))
    return len(directory['packages'])

if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, default=ROOT / '.cache/sample-packages')
    parser.add_argument('--directory-source', type=Path, help='Write reviewed website directory build input')
    args = parser.parse_args()
    print(f'Built {build(args.output.resolve())} deterministic data-only sample packages and unsigned inventories.')
    if args.directory_source:
        args.directory_source.parent.mkdir(parents=True, exist_ok=True)
        args.directory_source.write_bytes((args.output.resolve() / 'index.json').read_bytes())
