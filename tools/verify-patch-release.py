"""Run exact 1.4.0 -> 1.4.1 package gates on dedicated local pl141 services.

Requires a Docker network, a PHP test image, explicit MySQL/MariaDB container
hosts, and PL_PATCH_DB_PASSWORD in the parent environment. No signing, network
downloads, production access, or provider calls are performed by this harness.

Example (from the repository root, with a privately set test password):
python tools/verify-patch-release.py --baseline /path/to/phpledger-1.4.0.zip \
    --candidate /path/to/phpledger-1.4.1.zip --network pl141-test \
    --image codexm17integration-test:latest --mysql-host pl141-mysql \
    --maria-host pl141-maria --out .cache/patch-1.4.1-gates

The output directory must be new or empty. Only schemas with the unique run
identifier are cleaned up; all test services must be disposable local services.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import secrets
import shutil
import stat
import subprocess
import tempfile
import zipfile
from pathlib import Path

BASELINE_SHA256 = "53c7fc584bd47723a6f6008a8a951db9b3947dae956484c8bf3b889ce2fad4d2"
HERE = Path(__file__).resolve().parent
TEST = HERE.parent / "tests" / "patch-upgrade-test.php"


def inspect(archive_path: Path, version: str) -> dict:
    digest = hashlib.sha256(archive_path.read_bytes()).hexdigest()
    sidecar = archive_path.with_name(archive_path.name + ".sha256")
    if not sidecar.is_file() or sidecar.read_text(encoding="ascii").strip() != f"{digest}  {archive_path.name}":
        raise RuntimeError(f"Checksum sidecar missing or incorrect: {archive_path}")
    with zipfile.ZipFile(archive_path) as archive:
        infos = archive.infolist()
        names = [info.filename for info in infos]
        if len(names) != len(set(names)) or not names:
            raise RuntimeError("Duplicate or empty archive members")
        for info in infos:
            name = info.filename
            if not name.startswith("phpledger/") or name.startswith("/") or "\\" in name or ".." in name.split("/"):
                raise RuntimeError(f"Unsafe archive member: {name}")
            if stat.S_IFMT(info.external_attr >> 16) == stat.S_IFLNK:
                raise RuntimeError(f"Symlink in package: {name}")
        manifest = json.loads(archive.read("phpledger/PACKAGE-MANIFEST.json"))
        if manifest.get("version") != version or manifest.get("channel") != "stable":
            raise RuntimeError(f"Wrong packaged version/channel: {version}")
        if archive.read("phpledger/www/phpledger/VERSION").strip() != version.encode():
            raise RuntimeError("Packaged VERSION differs from manifest")
        files = manifest.get("files", [])
        listed = set()
        migrations = {}
        for entry in files:
            name = entry["path"]
            if name in listed or not re.fullmatch(r"[A-Za-z0-9_.\-/]+", name) or ".." in name.split("/"):
                raise RuntimeError(f"Unsafe or duplicate inventory entry: {name}")
            listed.add(name)
            data = archive.read("phpledger/" + name)
            if len(data) != entry["bytes"] or hashlib.sha256(data).hexdigest() != entry["sha256"]:
                raise RuntimeError(f"Inventory digest differs: {name}")
            if name.startswith("www/phpledger/install/migrations/"):
                migrations[name] = entry["sha256"]
        if set(names) != {"phpledger/" + name for name in listed} | {"phpledger/PACKAGE-MANIFEST.json"}:
            raise RuntimeError("Archive members differ from inventory")
        required = {"www/phpledger/install/upgrade.php", "vendor/autoload.php",
                    "resources/core-samples/core-accounting-1.0.0.json"}
        if not required.issubset(listed) or any(name.startswith("resources/demo-packs/") for name in listed):
            raise RuntimeError("#71 or #100 exact-package boundary failed")
        return {"sha256": digest, "source_commit": manifest.get("source_commit"),
                "members": len(names), "migrations": migrations}


def extract(source: Path, destination: Path) -> None:
    # inspect() validated every member and the inventory before extraction.
    with zipfile.ZipFile(source) as archive:
        archive.extractall(destination)
    if not (destination / "phpledger" / "PACKAGE-MANIFEST.json").is_file():
        raise RuntimeError("Package root missing after extraction")


def invoke(args: argparse.Namespace, scratch: Path, engine: str, host: str,
           label: str, command: list[str], run_id: str, database: str = "phpledger_test") -> str:
    env = os.environ.copy()
    env["PL_DB_PASSWORD"] = env["PL_PATCH_DB_PASSWORD"]
    private = scratch / f"private-{engine}"
    private.mkdir(exist_ok=True)
    docker = ["docker", "run", "--rm", "--network", args.network,
              "--mount", f"type=bind,source={scratch},target=/proof",
              "--mount", f"type=bind,source={TEST},target=/harness/patch-upgrade-test.php,readonly",
              "-e", "PL_DB_PASSWORD", "-e", "PL_ENV=test", "-e", f"PL_DB_HOST={host}",
              "-e", f"PL_DB_NAME={database}", "-e", "PL_DB_USER=root",
              "-e", f"PL_PATCH_RUN_ID={run_id}",
              "-e", f"PL_INSTALL_DIRECTORY=/proof/private-{engine}", args.image, *command]
    result = subprocess.run(docker, env=env, capture_output=True, text=True)
    log = args.out / f"{engine}-{label}.log"
    log.write_text(result.stdout + result.stderr, encoding="utf-8")
    if result.returncode:
        raise RuntimeError(f"{engine} {label} failed (exit {result.returncode}); inspect {log}")
    return result.stdout


def run_engine(args: argparse.Namespace, scratch: Path, engine: str, host: str) -> dict:
    receipt = scratch / f"receipt-{engine}.json"
    target = f"/proof/receipt-{engine}.json"
    baseline = "/proof/baseline/phpledger"
    candidate = "/proof/candidate/phpledger"
    steps = []
    database = None
    run_id = secrets.token_hex(4)
    try:
        invoke(args, scratch, engine, host, "fresh", ["php", "/harness/patch-upgrade-test.php", "fresh", candidate, target], run_id)
        steps.append("fresh_61_migrations_first_post")
        invoke(args, scratch, engine, host, "seed", ["php", "/harness/patch-upgrade-test.php", "seed", baseline, target], run_id)
        steps.append("populated_1_4_0_seed")
        database = json.loads(receipt.read_text(encoding="utf-8"))["database"]
        if not re.fullmatch(rf"pl141_patch_{run_id}_[a-f0-9]{{16}}", database):
            raise RuntimeError("Unsafe disposable schema name in fixture receipt")
        upgrade = ["php", candidate + "/www/phpledger/install/upgrade.php"]
        for number in (1, 2):
            output = invoke(args, scratch, engine, host, f"upgrade-{number}", upgrade, run_id, database)
            if "Already up to date; all migration checksums match." not in output:
                raise RuntimeError(f"{engine} packaged upgrade {number} was not an idempotent no-op")
            steps.append(f"packaged_upgrade_{number}_no_op")
        invoke(args, scratch, engine, host, "verify", ["php", "/harness/patch-upgrade-test.php", "verify", candidate, target], run_id, database)
        steps.append("historical_rows_and_balances_unchanged")
        return {"engine": engine, "host": host, "steps": steps}
    finally:
        # This run's unique schema prefix works even if seeding failed before writing a receipt.
        # Baseline code is used so cleanup still works if candidate bootstrap fails.
        invoke(args, scratch, engine, host, "cleanup", ["php", "/harness/patch-upgrade-test.php", "cleanup", baseline, target], run_id)
        receipt.unlink(missing_ok=True)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--baseline", required=True, type=Path)
    parser.add_argument("--candidate", required=True, type=Path)
    parser.add_argument("--network", required=True)
    parser.add_argument("--image", required=True, help="PHP test image with MySQL and MariaDB drivers")
    parser.add_argument("--mysql-host", required=True)
    parser.add_argument("--maria-host", required=True)
    parser.add_argument("--out", required=True, type=Path, help="New empty local evidence directory")
    args = parser.parse_args()
    if not re.fullmatch(r"pl141-[a-z0-9-]+", args.mysql_host) or not re.fullmatch(r"pl141-[a-z0-9-]+", args.maria_host):
        raise RuntimeError("Only explicit pl141 test database hosts are permitted")
    if not args.network.startswith("pl141-"):
        raise RuntimeError("Only a dedicated pl141 Docker network is permitted")
    if not os.environ.get("PL_PATCH_DB_PASSWORD"):
        raise RuntimeError("Set PL_PATCH_DB_PASSWORD privately for the disposable root account")
    if not TEST.is_file():
        raise RuntimeError("Tracked patch-upgrade-test.php is missing")
    baseline = inspect(args.baseline, "1.4.0")
    candidate = inspect(args.candidate, "1.4.1")
    if baseline["sha256"] != BASELINE_SHA256:
        raise RuntimeError("Baseline ZIP is not the published 1.4.0 artifact")
    if baseline["migrations"] != candidate["migrations"] or len(candidate["migrations"]) != 61:
        raise RuntimeError("Patch must preserve the exact 61 historical migration files")
    args.out.mkdir(parents=True, exist_ok=True)
    if any(args.out.iterdir()):
        raise RuntimeError("Evidence directory must be empty")
    with tempfile.TemporaryDirectory(prefix="pl141-exact-", dir=args.out) as temporary:
        scratch = Path(temporary)
        extract(args.baseline, scratch / "baseline")
        extract(args.candidate, scratch / "candidate")
        results = [run_engine(args, scratch, engine, host) for engine, host in
                   (("mysql", args.mysql_host), ("maria", args.maria_host))]
    summary = {"baseline_sha256": baseline["sha256"], "candidate_sha256": candidate["sha256"],
               "candidate_source_commit": candidate["source_commit"], "migration_files": 61,
               "candidate_members": candidate["members"], "results": results, "production_changed": False}
    (args.out / "receipt.json").write_text(json.dumps(summary, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(summary, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
