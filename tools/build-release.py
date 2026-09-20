"""Build a release archive for PHP Ledger from a committed revision.

Tracked successor of the one-off ``.cache/release-1.0.0/build-release.py``.
Step 3 of docs/RELEASE-PROTOCOL.md runs it in CI; step 4 runs it again locally
and compares the result with ``--compare``.

1. Checks out ``--commit`` as a detached, clean Git worktree (the main checkout
   is never touched, and the worktree is removed and pruned afterwards).
2. Reads ``www/phpledger/VERSION`` from that snapshot. The version comes from
   the source; ``--version`` is only accepted when it matches, and the channel
   is derived from the version (a hyphen means preview).
3. Builds a production-only Composer vendor tree inside the project's ``test``
   Docker image.
4. Runs ``tools/build-package.py`` to produce the ZIP and its ``.sha256``.
5. Re-extracts the ZIP and re-verifies every ``PACKAGE-MANIFEST.json`` entry,
   then writes ``receipt.json``.
6. With ``--compare OTHER.zip``, accepts either a byte-identical archive or one
   whose member set and per-member SHA-256 match (compression can differ); the
   receipt's ``reproduction`` field records which ("bytes" or "members"). Fails
   only when the archive members themselves differ.

It never publishes, uploads, tags or signs anything.

    python tools/build-release.py --commit v1.0.1 --out build/release
    python tools/build-release.py --commit v1.0.1 --out build/check --compare build/release/phpledger-1.0.1.zip
"""
from __future__ import annotations

import argparse
import datetime
import hashlib
import json
import os
import re
import shutil
import stat
import subprocess
import sys
import zipfile
from pathlib import Path, PurePosixPath

ROOT = Path(__file__).resolve().parents[1]
COMPOSE_PROJECT = "plrelease"
COMPOSE_SUBNET = "10.203.93.0/24"
VERSION_PATTERN = re.compile(r"^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-[0-9A-Za-z]+(?:\.[0-9A-Za-z]+)*)?$")


class BuildError(Exception):
    pass


def _rmtree(path: Path) -> None:
    """Remove a tree, clearing read-only bits Git and Docker leave on Windows."""

    def _on_error(func, target, _exc_info):
        try:
            os.chmod(target, stat.S_IWRITE)
            func(target)
        except OSError:
            pass

    shutil.rmtree(path, onerror=_on_error)


def _run(cmd: list[str], cwd: Path | None = None, env: dict | None = None) -> subprocess.CompletedProcess:
    result = subprocess.run(cmd, cwd=str(cwd) if cwd else None, capture_output=True, text=True, env=env)
    if result.returncode:
        raise BuildError(f"Command failed ({' '.join(cmd)}):\nSTDOUT:\n{result.stdout}\nSTDERR:\n{result.stderr}")
    return result


def git(*args: str, cwd: Path = ROOT) -> str:
    result = subprocess.run(["git", "-C", str(cwd), *args], capture_output=True)
    if result.returncode:
        raise BuildError(result.stderr.decode(errors="replace"))
    return result.stdout.decode(errors="replace")


def resolve_commit(commit: str) -> str:
    return git("rev-parse", "--verify", commit + "^{commit}").strip()


def channel_for(version: str) -> str:
    if not VERSION_PATTERN.match(version):
        raise BuildError(f"Invalid semantic version: {version!r}")
    return "preview" if "-" in version else "stable"


def export_snapshot(commit: str, destination: Path) -> None:
    if destination.exists():
        raise BuildError(f"Snapshot destination already exists: {destination}")
    destination.parent.mkdir(parents=True, exist_ok=True)
    git("-c", "core.autocrlf=false", "worktree", "add", "--detach", str(destination), commit)
    if git("status", "--porcelain", cwd=destination).strip():
        raise BuildError("Exported worktree is not clean")
    if git("rev-parse", "HEAD", cwd=destination).strip() != commit:
        raise BuildError("Worktree HEAD does not match the requested commit")


def remove_snapshot(snapshot: Path, scratch: Path) -> None:
    if snapshot.exists():
        subprocess.run(["git", "-C", str(ROOT), "worktree", "remove", "--force", str(snapshot)], capture_output=True)
    if scratch.exists():
        _rmtree(scratch)
    subprocess.run(["git", "-C", str(ROOT), "worktree", "prune"], capture_output=True)


def source_version(snapshot: Path, requested: str | None) -> str:
    path = snapshot / "www" / "phpledger" / "VERSION"
    if not path.is_file():
        raise BuildError("The source has no www/phpledger/VERSION file")
    version = path.read_text(encoding="ascii").strip()
    channel_for(version)
    if requested is not None and requested != version:
        raise BuildError(f"--version {requested} does not match www/phpledger/VERSION ({version}). Update VERSION and commit first.")
    return version


def build_vendor(snapshot: Path, vendor_out: Path, subnet: str) -> str:
    """Production-only vendor tree built inside the disposable test image."""
    if vendor_out.exists():
        raise BuildError(f"Vendor output already exists: {vendor_out}")
    vendor_out.mkdir(parents=True)
    lock_hash = hashlib.sha256((snapshot / "composer.lock").read_bytes()).hexdigest()
    shutil.copyfile(snapshot / "composer.json", vendor_out / "composer.json")
    shutil.copyfile(snapshot / "composer.lock", vendor_out / "composer.lock")
    # compose.yaml requires these for its other services; the vendor build never uses them.
    env = {
        "PL_DB_PASSWORD": "release-build-unused",
        "PL_DB_ROOT_PASSWORD": "release-build-unused",
        **os.environ,
        "PL_DOCKER_SUBNET": subnet,
    }
    _run(
        [
            "docker", "compose", "-p", COMPOSE_PROJECT, "--profile", "test", "run", "--rm", "--no-deps",
            "-v", f"{vendor_out}:/build", "-w", "/build", "test",
            "composer", "install", "--no-dev", "--prefer-dist", "--no-interaction", "--no-scripts",
        ],
        cwd=ROOT,
        env=env,
    )
    if not (vendor_out / "vendor" / "autoload.php").is_file():
        raise BuildError("Vendor build did not produce vendor/autoload.php")
    return lock_hash


def run_build_package(snapshot: Path, vendor: Path, output: Path, version: str, channel: str) -> Path:
    result = _run(
        [
            sys.executable, str(snapshot / "tools" / "build-package.py"),
            "--source", str(snapshot), "--vendor", str(vendor), "--output", str(output),
            "--version", version, "--channel", channel,
        ],
        cwd=snapshot,
    )
    sys.stdout.write(result.stdout)
    archive = output / f"phpledger-{version}.zip"
    if not archive.is_file():
        raise BuildError(f"Expected archive was not created: {archive}")
    return archive


def verify_archive(archive: Path, version: str) -> dict:
    """Re-verify every manifest entry independently of the package builder."""
    with zipfile.ZipFile(archive) as package:
        names = package.namelist()
        if not names or len(names) != len(set(names)):
            raise BuildError("Empty archive or duplicate archive paths")
        roots = {name.split("/", 1)[0] for name in names}
        # The installer branch moves the archive root from phpledger-<version>/ to phpledger/.
        if len(roots) != 1 or next(iter(roots)) not in (f"phpledger-{version}", "phpledger"):
            raise BuildError(f"Unexpected archive root: {sorted(roots)}")
        root_name = next(iter(roots))
        for name in names:
            path = PurePosixPath(name)
            if path.is_absolute() or ".." in path.parts or "\\" in name:
                raise BuildError(f"Unsafe archive path: {name}")
        manifest = json.loads(package.read(f"{root_name}/PACKAGE-MANIFEST.json"))
        if manifest.get("version") != version:
            raise BuildError("Manifest version does not match www/phpledger/VERSION")
        expected = set()
        for entry in manifest["files"]:
            path = PurePosixPath(entry["path"])
            if path.is_absolute() or ".." in path.parts:
                raise BuildError(f"Unsafe manifest path: {entry['path']}")
            expected.add(entry["path"])
            data = package.read(f"{root_name}/{entry['path']}")
            if len(data) != entry["bytes"] or hashlib.sha256(data).hexdigest() != entry["sha256"]:
                raise BuildError(f"Manifest mismatch: {entry['path']}")
        actual = {name[len(root_name) + 1:] for name in names if not name.endswith("/")}
        if actual != expected | {"PACKAGE-MANIFEST.json"}:
            raise BuildError("Archive contains unlisted files, or is missing manifest entries")
        if "www/phpledger/VERSION" in expected and package.read(f"{root_name}/www/phpledger/VERSION").decode("ascii").strip() != version:
            raise BuildError("Packaged VERSION does not match the manifest")
    return {
        "archive_root": root_name,
        "manifest_channel": manifest.get("channel"),
        "manifest_status": manifest.get("status"),
        "manifest_source_commit": manifest.get("source_commit"),
        "manifest_files_verified": len(expected),
    }


def member_digests(archive: Path) -> dict[str, str]:
    """Map each ZIP member's name to the SHA-256 of its decompressed bytes."""
    digests = {}
    with zipfile.ZipFile(archive) as package:
        for name in package.namelist():
            digests[name] = hashlib.sha256(package.read(name)).hexdigest()
    return digests


def checksum(archive: Path) -> str:
    digest = hashlib.sha256(archive.read_bytes()).hexdigest()
    checksum_path = archive.with_name(archive.name + ".sha256")
    if not checksum_path.is_file():
        checksum_path.write_text(f"{digest}  {archive.name}\n", encoding="ascii", newline="\n")
    if checksum_path.read_text(encoding="ascii").split()[0].lower() != digest:
        raise BuildError("Checksum file does not match the built archive")
    return digest


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--commit", required=True, help="Git commit-ish to package, usually the release tag")
    parser.add_argument("--version", help="Optional; must equal www/phpledger/VERSION in that commit")
    parser.add_argument("--out", required=True, type=Path, help="Empty output directory for the ZIP, checksum and receipt")
    parser.add_argument("--compare", type=Path, help="Fail unless the built ZIP matches this archive (byte-identical, or member-identical with different compression)")
    parser.add_argument("--subnet", default=COMPOSE_SUBNET, help="Docker network subnet for the isolated compose project")
    args = parser.parse_args()

    out = args.out.resolve()
    out.mkdir(parents=True, exist_ok=True)
    if any(out.iterdir()):
        print(f"Release build failed: output directory must be empty: {out}", file=sys.stderr)
        return 1
    scratch = out.parent / (out.name + "-scratch")
    if scratch.exists():
        print(f"Release build failed: remove the scratch directory first: {scratch}", file=sys.stderr)
        return 1
    snapshot = scratch / "source"
    try:
        commit = resolve_commit(args.commit)
        export_snapshot(commit, snapshot)
        version = source_version(snapshot, args.version)
        channel = channel_for(version)
        print(f"Packaging {version} ({channel}) from {commit}")
        lock_hash = build_vendor(snapshot, scratch / "vendor", args.subnet)
        archive = run_build_package(snapshot, scratch / "vendor" / "vendor", out, version, channel)
        verification = verify_archive(archive, version)
        digest = checksum(archive)
        reproduced = None
        reproduction = None
        if args.compare is not None:
            other = hashlib.sha256(args.compare.read_bytes()).hexdigest()
            if other == digest:
                reproduction = "bytes"
                print(f"Reproducible: byte-identical to {args.compare}")
            else:
                ours = member_digests(archive)
                theirs = member_digests(args.compare)
                if ours == theirs:
                    reproduction = "members"
                    print(f"Reproducible: member-identical (compression differs) with {args.compare}")
                else:
                    raise BuildError(f"Not reproducible: {archive.name} is {digest}, {args.compare} is {other}, and archive members differ")
            reproduced = str(args.compare)
        receipt = {
            "built_at_utc": datetime.datetime.now(datetime.timezone.utc).isoformat(),
            "commit": commit,
            "commit_requested_as": args.commit,
            "version": version,
            "channel": channel,
            "archive": archive.name,
            "archive_bytes": archive.stat().st_size,
            "archive_sha256": digest,
            "vendor_composer_lock_sha256": lock_hash,
            "reproduced_against": reproduced,
            "reproduction": reproduction,
            "published": False,
            **verification,
        }
        (out / "receipt.json").write_text(json.dumps(receipt, indent=2, sort_keys=True) + "\n", encoding="utf-8")
        print(json.dumps(receipt, indent=2, sort_keys=True))
    except BuildError as error:
        print(f"Release build failed: {error}", file=sys.stderr)
        return 1
    finally:
        remove_snapshot(snapshot, scratch)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
