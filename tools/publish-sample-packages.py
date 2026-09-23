#!/usr/bin/env python3
"""Audit eleven signed samples and prepare issue #72 publication; writes require --execute.

Default: local cryptographic/archive/preload audit and read-only GitHub collision checks.
Execution creates only the eleven named repositories, five source files each, and draft
releases with ZIP/checksum/envelope assets. --publish additionally publishes those drafts.
No overwrite, force push, asset clobber, deletion, or automatic collision recovery exists.
Requires Python cryptography, git and authenticated gh. No private signing key is used.
"""
from __future__ import annotations

import argparse
import base64
import hashlib
import io
import json
import re
import stat
import subprocess
import tempfile
from pathlib import Path

from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import padding, rsa

ROOT = Path(__file__).resolve().parents[1]
SLUGS = tuple('sample-' + name for name in (
    'distributor', 'jewelry-studio', 'light-manufacturing', 'membership-club',
    'pharmacy', 'restaurant', 'retail-shop', 'seasonal-business',
    'service-agency', 'service-workshop', 'trader',
))
SOURCE_FILES = ('LICENSE', 'README.md', 'package.json', 'pack.json', 'structure.json')
RUNTIME_FILES = ('package.json', 'pack.json', 'structure.json')


def require(condition: bool, message: str) -> None:
    if not condition:
        raise ValueError(message)


def sha(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def read(path: Path) -> bytes:
    require(path.is_file() and not path.is_symlink(), f'Expected regular file: {path.name}')
    return path.read_bytes()


def names(directory: Path) -> set[str]:
    require(directory.is_dir() and not directory.is_symlink(), 'Expected ordinary directory')
    children = list(directory.iterdir())
    require(not any(p.is_symlink() for p in children), 'Symlink refused')
    return {p.name for p in children}


def public_key(path: Path):
    key = serialization.load_pem_public_key(read(path))
    require(isinstance(key, rsa.RSAPublicKey) and key.key_size >= 2048, 'Expected RSA publisher public key')
    return key


def fingerprint(key) -> str:
    return sha(key.public_bytes(serialization.Encoding.DER, serialization.PublicFormat.SubjectPublicKeyInfo))


def audit(artifacts: Path, preloads: Path, key_path: Path) -> tuple[dict, dict]:
    import zipfile
    key = public_key(key_path)
    require(fingerprint(key) == fingerprint(public_key(ROOT / 'resources/release/publisher-public.pem')),
            'Publisher differs from the reviewed project pin')
    index = json.loads(read(artifacts / 'index.json'))
    entries = {p['slug']: p for p in index['packages']}
    require(len(index['packages']) == 11 and set(entries) == set(SLUGS), 'Expected exactly eleven directory packages')
    require(names(artifacts) == set(SLUGS) | {'index.json', 'signing-receipt.json'}, 'Unexpected artifact-root entry')
    require(names(preloads) == set(SLUGS), 'Read-only preload set differs')
    signing = json.loads(read(artifacts / 'signing-receipt.json'))
    require(signing['publisher_spki_sha256'] == fingerprint(key), 'Signing receipt pin differs')
    signed_rows = {p['slug']: p for p in signing['samples']}
    require(len(signing['samples']) == 11 and set(signed_rows) == set(SLUGS), 'Signing receipt set differs')
    plan = {'schema': 1, 'issue': 'https://github.com/phpledger/phpledger/issues/72',
            'publisher_spki_sha256': fingerprint(key), 'repositories': []}
    frozen = {}
    for slug in SLUGS:
        entry = entries[slug]
        version = entry['version']
        require(re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+', version) is not None, 'Invalid version')
        base = artifacts / slug / version
        require(names(artifacts / slug) == {version}, f'{slug}: unexpected version directory')
        archive_name = f'{slug}-{version}.zip'
        require(names(base) == {'repository', 'package.json', 'sample-metadata.json', 'sample-envelope.json',
                               archive_name, archive_name + '.sha256'}, f'{slug}: unexpected artifact entry')
        source = {name: read(base / 'repository' / name) for name in SOURCE_FILES}
        require(names(base / 'repository') == set(SOURCE_FILES), f'{slug}: unexpected repository file')
        assets = {name: read(base / name) for name in (archive_name, archive_name + '.sha256', 'sample-envelope.json')}
        envelope = json.loads(assets['sample-envelope.json'])
        require(set(envelope) == {'payload', 'signature'}, f'{slug}: unexpected envelope field')
        payload = base64.b64decode(envelope['payload'], validate=True)
        key.verify(base64.b64decode(envelope['signature'], validate=True), payload, padding.PKCS1v15(), hashes.SHA256())
        inventory = json.loads(payload)
        require(inventory == json.loads(read(base / 'sample-metadata.json')) == entry['inventory'], f'{slug}: signed inventory differs')
        require(inventory['schema'] == 1 and inventory['type'] == 'sample' and inventory['slug'] == slug
                and inventory['version'] == version, f'{slug}: signed identity differs')
        require(inventory['archive_bytes'] == len(assets[archive_name])
                and inventory['archive_sha256'] == sha(assets[archive_name]), f'{slug}: archive hash differs')
        checksum = assets[archive_name + '.sha256'].decode('ascii').strip().split()
        require(checksum == [inventory['archive_sha256'], archive_name], f'{slug}: checksum file differs')
        with zipfile.ZipFile(io.BytesIO(assets[archive_name])) as archive:
            files = archive.infolist()
            require(len(files) == 3 and {p.filename for p in files} == {slug + '/' + p for p in RUNTIME_FILES},
                    f'{slug}: unexpected or duplicate archive member')
            for member in files:
                require(not member.is_dir() and not stat.S_ISLNK(member.external_attr >> 16), f'{slug}: nonregular archive member')
                name = member.filename.split('/')[1]
                require(archive.read(member) == source[name], f'{slug}: archive/source mismatch')
        manifest = json.loads(source['package.json'])
        require(manifest == entry['manifest'] == json.loads(read(base / 'package.json')),
                f'{slug}: directory manifest differs')
        require(manifest['type'] == 'sample' and manifest['contract'] == 1 and manifest['slug'] == slug
                and manifest['version'] == version and manifest['licence'] == 'CC0-1.0', f'{slug}: manifest identity differs')
        require(inventory['manifest_sha256'] == sha(source['package.json'])
                and inventory['files'] == manifest['files'] == {p: sha(source[p]) for p in ('pack.json', 'structure.json')},
                f'{slug}: payload hashes differ')
        require(names(preloads / slug) == set(RUNTIME_FILES) | {'sample-envelope.json'}, f'{slug}: unexpected preload file')
        for name in RUNTIME_FILES:
            require(read(preloads / slug / name) == source[name], f'{slug}: preload differs from signed archive')
        require(read(preloads / slug / 'sample-envelope.json') == assets['sample-envelope.json'], f'{slug}: preload envelope differs')
        require(signed_rows[slug]['archive_sha256'] == inventory['archive_sha256']
                and signed_rows[slug]['envelope_sha256'] == sha(assets['sample-envelope.json'])
                and signed_rows[slug]['version'] == version, f'{slug}: signing receipt differs')
        frozen[slug] = (source, assets)
        plan['repositories'].append({'repository': 'phpledger/' + slug, 'slug': slug, 'version': version,
            'tag': 'v' + version, 'name': manifest['name'], 'homepage': manifest['homepage'],
            'source_sha256': {p: sha(data) for p, data in source.items()},
            'assets_sha256': {p: sha(data) for p, data in assets.items()}})
    return plan, frozen


def command(args: list[str], cwd: Path | None = None) -> str:
    result = subprocess.run(args, cwd=cwd, text=True, capture_output=True, timeout=180)
    if result.returncode:
        raise RuntimeError(f'{args[0]} {args[1]} failed: {result.stderr.strip()}')
    return result.stdout.strip()


def collision_check(plan: dict) -> None:
    # Refuse even an empty repository: an operator must inspect ownership and previous work.
    # Repository absence also proves no colliding tag/release/assets can exist there.
    for row in plan['repositories']:
        result = subprocess.run(['gh', 'api', 'repos/' + row['repository']], text=True,
                                capture_output=True, timeout=30)
        if result.returncode == 0:
            raise RuntimeError(f'Collision: {row["repository"]} already exists; no writes performed')
        require('HTTP 404' in result.stderr, 'Could not establish repository absence; refusing publication')


def save(path: Path, value: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(value, indent=2) + '\n', encoding='utf-8')


def execute(plan: dict, frozen: dict, receipt: Path, publish: bool) -> None:
    collision_check(plan)  # Recheck immediately before any external write.
    journal = {'schema': 1, 'plan_sha256': sha(json.dumps(plan, sort_keys=True).encode()), 'repositories': []}
    save(receipt, journal)
    with tempfile.TemporaryDirectory(prefix='phpledger-sample-publish-') as temporary:
        for row in plan['repositories']:
            slug = row['slug']; repository = row['repository']; tag = row['tag']
            source, assets = frozen[slug]
            directory = Path(temporary) / slug; directory.mkdir()
            for name, data in source.items():
                (directory / name).write_bytes(data)
            command(['git', 'init', '-b', 'main'], directory)
            command(['git', 'config', 'core.autocrlf', 'false'], directory)
            command(['git', 'add', '--', *SOURCE_FILES], directory)
            command(['git', 'commit', '-m', f'Release reviewed fictional sample {row["version"]}'], directory)
            commit = command(['git', 'rev-parse', 'HEAD'], directory)
            command(['git', 'tag', '-a', tag, '-m', f'Reviewed sample {row["version"]}'], directory)
            record = {'repository': repository, 'commit': commit, 'tag': tag, 'state': 'prepared'}
            journal['repositories'].append(record); save(receipt, journal)
            command(['gh', 'repo', 'create', repository, '--public', '--source', str(directory),
                     '--remote', 'origin', '--push', '--description', f'{row["name"]}: fictional CC0 PHP Ledger sample data',
                     '--homepage', row['homepage']])
            record['state'] = 'repository_created'; save(receipt, journal)
            command(['git', 'push', 'origin', 'refs/tags/' + tag], directory)
            asset_dir = Path(temporary) / (slug + '-assets'); asset_dir.mkdir()
            for name, data in assets.items():
                (asset_dir / name).write_bytes(data)
            notes = asset_dir / 'release-notes.txt'
            notes.write_text(f'{row["name"]} {row["version"]}: fictional practice data, CC0-1.0, data-only sample contract 1.\n\n'
                'Install through PHP Ledger Packages or upload this release ZIP. Full history creates a separate sample company; '
                'structure starts an empty real business. Exact dependencies and content hashes are in package.json. '
                'The signed publisher inventory and SHA-256 checksum are attached. This is not a statutory or industry compliance product.\n', encoding='utf-8')
            command(['gh', 'release', 'create', tag, '--repo', repository, '--verify-tag', '--draft',
                     '--title', row['name'] + ' ' + row['version'], '--notes-file', str(notes),
                     *(str(asset_dir / name) for name in assets)])
            remote = json.loads(command(['gh', 'api', f'repos/{repository}/git/ref/heads/main']))
            require(remote['object']['sha'] == commit, 'Remote source commit differs')
            download = asset_dir / 'verified-download'; download.mkdir()
            patterns = [part for name in assets for part in ('--pattern', name)]
            command(['gh', 'release', 'download', tag, '--repo', repository, '--dir', str(download), *patterns])
            require(names(download) == set(assets), 'Draft release asset set differs')
            for name, expected in assets.items():
                require(read(download / name) == expected, 'Downloaded release asset differs')
            record.update(state='draft_verified', release_url=f'https://github.com/{repository}/releases/tag/{tag}',
                          assets_sha256=row['assets_sha256'])
            save(receipt, journal)
        if publish:
            for record in journal['repositories']:
                command(['gh', 'release', 'edit', record['tag'], '--repo', record['repository'], '--draft=false', '--latest'])
                published = json.loads(command(['gh', 'api', f'repos/{record["repository"]}/releases/tags/{record["tag"]}']))
                require(not published['draft'] and not published['prerelease'], 'Release did not publish as stable')
                require({asset['name'] for asset in published['assets']} == set(record['assets_sha256']),
                        'Published asset inventory differs')
                record['state'] = 'published'; save(receipt, journal)
    print(f'Wrote execution receipt: {receipt}')


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--artifacts', required=True, type=Path)
    parser.add_argument('--preloads', required=True, type=Path)
    parser.add_argument('--public-key', type=Path, default=ROOT / 'resources/release/publisher-public.pem')
    parser.add_argument('--plan', required=True, type=Path)
    parser.add_argument('--receipt', type=Path)
    parser.add_argument('--local-only', action='store_true', help='Audit without even read-only GitHub checks')
    parser.add_argument('--execute', action='store_true', help='Create the reviewed repositories and draft releases')
    parser.add_argument('--publish', action='store_true', help='Also publish all drafts after every draft is created')
    args = parser.parse_args()
    require(not args.publish or args.execute, '--publish requires --execute')
    require(not args.execute or (args.receipt is not None and not args.local_only), 'Execution requires receipt and collision checks')
    require(not args.execute or not args.receipt.exists(), 'Refusing to overwrite an execution receipt')
    require(args.receipt is None or args.receipt.resolve() != args.plan.resolve(), 'Plan and receipt must differ')
    plan, frozen = audit(args.artifacts.resolve(), args.preloads.resolve(), args.public_key.resolve())
    if not args.local_only:
        collision_check(plan)
    plan['collision_check'] = 'not_requested' if args.local_only else 'all_eleven_repositories_absent'
    if args.execute:
        require(args.plan.is_file() and json.loads(read(args.plan)) == plan,
                'Current inputs differ from the reviewed plan; regenerate and review before execution')
    else:
        save(args.plan, plan)
    print('PASS eleven official signatures, ZIP inventories/checksums, five-file sources and equivalent signed preloads')
    if args.execute:
        execute(plan, frozen, args.receipt, args.publish)
    else:
        print(f'Plan only; no external writes. Review {args.plan}')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
