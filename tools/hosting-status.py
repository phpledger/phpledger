"""Read the configured PHP Ledger host without prompting for or printing secrets.

Uses the established, machine-local Windows Credential Manager connection helper
and pinned SSH host key. It does not deploy, restart, migrate, or write host files.
Supports legacy application bind mounts and exact release images. Image layouts
read only PACKAGE-MANIFEST.json inside web/scheduler; hosting mount and manifest
hashes are reported alongside image IDs. No container environment is returned.
See CLAUDE.md, docs/DEMO.md and www/website/README.md for deployment procedures.
"""
from __future__ import annotations

import argparse
import importlib.util
import json
from pathlib import Path
import sys


ROOT = Path(__file__).resolve().parents[1]
HELPER = ROOT / ".cache/publish-website-1.0.0.py"

# All docker inspection output stays inside the remote process. Only the fields
# explicitly selected below are returned; Config.Env and credentials are omitted.
REMOTE_STATUS = r'''
from pathlib import Path
from datetime import datetime, timezone
import hashlib, json, re, subprocess, urllib.request

base = Path('/var/www/phpledger/data')
vhost = Path('/etc/nginx/fastpanel2-sites/phpledger/phpledger.com.conf')
def run(args):
    return subprocess.check_output(args, text=True, stderr=subprocess.PIPE, timeout=30)

nginx = run(['nginx', '-T'])
marker = '# configuration file ' + str(vhost) + ':'
if marker not in nginx:
    raise RuntimeError('Expected active Nginx configuration was not loaded')
loaded = nginx.split(marker, 1)[1].split('# configuration file ', 1)[0]
roots = re.findall(r'^\s*root\s+([^;]+);', loaded, re.M)
if len(roots) != 1 or not roots[0].startswith(str(base / 'releases') + '/') or not roots[0].endswith('/www/website/public'):
    raise RuntimeError('Unexpected active static document root')

containers = []
app_roots = []
manifests = []
layouts = []
for role in ['web', 'scheduler', 'db']:
    name = 'phpledger-demo-demo-' + role + '-1'
    item = json.loads(run(['docker', 'inspect', name]))[0]
    row = {'role': role, 'name': name, 'id': item['Id'][:12],
           'running': item['State']['Running'], 'image': item['Config']['Image'],
           'image_id': item['Image']}
    if role != 'db':
        roots_for_role = [m['Source'] for m in item['Mounts'] if m['Destination'] == '/var/www/phpledger/www/phpledger']
        if len(roots_for_role) > 1:
            raise RuntimeError('Unexpected application mounts')
        if roots_for_role:
            layout = 'application-bind'
            source = Path(roots_for_role[0]).parents[1]
            if source.parent != base / 'releases':
                raise RuntimeError('Application source outside release directory')
            manifest_bytes = (source / 'PACKAGE-MANIFEST.json').read_bytes()
        else:
            layout = 'release-image'
            relative, destination = {
                'web': ('docker/shared-demo-guard.php', '/opt/phpledger-demo/request-guard.php'),
                'scheduler': ('tools/shared-demo-scheduler.sh', '/var/www/phpledger/tools/shared-demo-scheduler.sh'),
            }[role]
            mounts = [m for m in item['Mounts'] if m['Destination'] == destination]
            if len(mounts) != 1 or mounts[0].get('Type') != 'bind' or mounts[0].get('RW') is not False:
                raise RuntimeError('Expected read-only hosting mount was not found')
            mount = mounts[0]
            mount_path = Path(mount['Source'])
            source = mount_path.parents[1]
            if mount_path != source / relative or source.parent != base / 'releases':
                raise RuntimeError('Hosting mount outside reviewed release directory')
            row['hosting_mount'] = {'source': str(mount_path), 'destination': destination,
                'sha256': hashlib.sha256(mount_path.read_bytes()).hexdigest()}
            # Read only the public package manifest, never environment/config files.
            manifest_bytes = run(['docker', 'exec', name, 'cat', '/var/www/phpledger/PACKAGE-MANIFEST.json']).encode()
        if source.parent != base / 'releases':
            raise RuntimeError('Application source outside release directory')
        manifest = json.loads(manifest_bytes)
        if not isinstance(manifest.get('version'), str) or not isinstance(manifest.get('source_commit'), str):
            raise RuntimeError('Invalid public package manifest')
        manifests.append(manifest)
        layouts.append(layout)
        app_roots.append(source)
        row['release'] = source.name
        row['layout'] = layout
        row['manifest_sha256'] = hashlib.sha256(manifest_bytes).hexdigest()
    containers.append(row)
if app_roots[0] != app_roots[1]:
    raise RuntimeError('Web and scheduler use different release roots')
manifest = manifests[0]
overlay_file = app_roots[0] / 'DEPLOYMENT-OVERLAY.json'
overlay = json.loads(overlay_file.read_text()) if overlay_file.is_file() else None
with urllib.request.urlopen('http://127.0.0.1:18202/demo/health', timeout=20) as response:
    health = {'http_status': response.status, 'status': json.load(response).get('status')}
problems = [row['role'] + '_not_running' for row in containers if not row['running']]
if health != {'http_status': 200, 'status': 'ok'}:
    problems.append('demo_health_failed')
if containers[0]['image_id'] != containers[1]['image_id']:
    problems.append('web_scheduler_image_mismatch')
if layouts[0] != layouts[1]:
    problems.append('web_scheduler_layout_mismatch')
if manifests[0] != manifests[1]:
    problems.append('web_scheduler_manifest_mismatch')
print(json.dumps({'ok': not problems, 'problems': problems,
    'checked_at': datetime.now(timezone.utc).isoformat(),
    'read_only': True, 'website': {'active_vhost': str(vhost),
    'vhost_is_symlink': vhost.is_symlink(), 'root': roots[0], 'nginx_validation': 'passed'},
    'demo': {'version': manifest['version'], 'source_commit': manifest['source_commit'],
    'release': app_roots[0].name, 'content_overlay': overlay is not None,
    'overlay_files': len(overlay['files']) if overlay else 0,
    'health': health, 'containers': containers}}, indent=2))
'''


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.parse_args()
    prerequisites = [HELPER, ROOT / ".cache/hosting.py", ROOT / ".cache/hosting_known_hosts"]
    missing = [str(path.relative_to(ROOT)) for path in prerequisites if not path.is_file()]
    if missing:
        print(json.dumps({"ok": False, "category": "local_setup_missing", "missing": missing,
            "action": "Use the configured Windows operator checkout. Do not request passwords in chat or invent a replacement host/key."}), file=sys.stderr)
        return 1
    sys.dont_write_bytecode = True
    client = None
    phase = "load_local_helper"
    try:
        spec = importlib.util.spec_from_file_location("phpledger_local_publisher", HELPER)
        publisher = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(publisher)
        phase = "connect_with_saved_credential_and_pinned_host_key"
        client = publisher.connect()
        phase = "read_host_status"
        result = publisher.remote(client, "python3 - <<'PY'\n" + REMOTE_STATUS + "\nPY\n", timeout=90)
        status = json.loads(result)
        print(json.dumps(status, indent=2))
        return 0 if status['ok'] else 2
    except Exception as error:
        # Exception strings from credential/SSH helpers may contain sensitive
        # details. Class and phase distinguish failure modes without leaking them.
        print(json.dumps({"ok": False, "phase": phase, "error_type": type(error).__name__,
            "action": "Check the deployment runbook for this failure phase; do not print credentials, disable host-key checks, or reset passwords."}), file=sys.stderr)
        return 1
    finally:
        if client is not None:
            client.close()


if __name__ == "__main__":
    raise SystemExit(main())
