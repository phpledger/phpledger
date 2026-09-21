"""Shared helpers for the container image tests (1.2 M12). Not a test on its own.

Packages a real release ZIP from the current worktree (which must be a clean,
committed git tree - the same requirement tools/build-package.py itself enforces),
builds docker/release/Dockerfile from it, and drives it with plain `docker` commands
against a disposable MySQL database on its own Docker network. Every name is derived
from PL_CONTAINER_TEST_PREFIX so more than one of these can run at once without
colliding; PL_CONTAINER_TEST_COMPOSE_PROJECT controls which isolated Compose project
supplies the `test` image used to build the production vendor tree (see
docs/DEVELOPMENT.md and AGENT_MESSAGES.MD on running an isolated project/subnet
alongside other concurrent sessions).
"""
from __future__ import annotations

import json
import os
import subprocess
import sys
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PREFIX = os.environ.get("PL_CONTAINER_TEST_PREFIX", "phpledger-container-test")
COMPOSE_PROJECT = os.environ.get("PL_CONTAINER_TEST_COMPOSE_PROJECT", PREFIX)
COMPOSE_SUBNET = os.environ.get("PL_CONTAINER_TEST_SUBNET")
# The network this test's own containers talk over - separate from COMPOSE_SUBNET,
# which is only for the disposable Compose project that builds the vendor tree.
# Left unset, Docker assigns one from its own pool; set it to avoid a chance
# collision with another session's explicitly pinned subnet.
NETWORK_SUBNET = os.environ.get("PL_CONTAINER_TEST_NETWORK_SUBNET")
NETWORK = f"{PREFIX}-net"
DB_NAME = f"{PREFIX}-db"
WEB_NAME = f"{PREFIX}-web"
PRIVATE_VOLUME = f"{PREFIX}-private"
IMAGE_TAG = f"{PREFIX}-image:local"
DB_PASSWORD = "container-test-only"
DB_ROOT_PASSWORD = "container-test-root-only"
ADMIN_EMAIL = "owner@example.invalid"
ADMIN_NAME = "Container Test Owner"
ADMIN_PASSWORD = "Container-test-password-1!"


class ContainerTestError(RuntimeError):
    pass


def run(cmd: list[str], **kwargs) -> subprocess.CompletedProcess:
    print("+", " ".join(cmd), file=sys.stderr)
    return subprocess.run(cmd, check=True, **kwargs)


def try_run(cmd: list[str]) -> None:
    subprocess.run(cmd, capture_output=True)


def cleanup() -> None:
    try_run(["docker", "rm", "-f", WEB_NAME])
    try_run(["docker", "rm", "-f", DB_NAME])
    try_run(["docker", "network", "rm", NETWORK])
    try_run(["docker", "volume", "rm", "-f", PRIVATE_VOLUME])


def remove_test_image() -> None:
    try_run(["docker", "rmi", "-f", IMAGE_TAG])


def build_release_zip(build_dir: Path) -> Path:
    """A real release ZIP from the current worktree, production-only vendor tree."""
    status = subprocess.run(["git", "-C", str(ROOT), "status", "--porcelain"], capture_output=True, text=True, check=True)
    if status.stdout.strip():
        raise ContainerTestError(
            "The worktree has uncommitted changes; tools/build-package.py packages only a clean "
            "git tree. Commit first, then run this test."
        )
    vendor = build_dir / "vendor"
    vendor.mkdir(parents=True)
    env = {**os.environ, "PL_DB_PASSWORD": "unused-vendor-build", "PL_DB_ROOT_PASSWORD": "unused-vendor-build"}
    if COMPOSE_SUBNET:
        env["PL_DOCKER_SUBNET"] = COMPOSE_SUBNET
    run(["docker", "compose", "-p", COMPOSE_PROJECT, "--profile", "test", "build", "test"], cwd=ROOT, env=env)
    run(
        [
            "docker", "compose", "-p", COMPOSE_PROJECT, "--profile", "test", "run", "--rm", "--no-deps",
            "-v", f"{vendor}:/build", "-w", "/build", "test",
            "composer", "install", "--no-dev", "--prefer-dist", "--no-interaction", "--no-scripts",
        ],
        cwd=ROOT, env=env,
    )
    if not (vendor / "autoload.php").is_file():
        raise ContainerTestError("Vendor build did not produce vendor/autoload.php")
    version = (ROOT / "www/phpledger/VERSION").read_text(encoding="ascii").strip()
    package_dir = build_dir / "package"
    package_dir.mkdir()
    run(
        [
            sys.executable, str(ROOT / "tools/build-package.py"),
            "--source", str(ROOT), "--vendor", str(vendor), "--output", str(package_dir), "--version", version,
        ],
        cwd=ROOT,
    )
    archives = list(package_dir.glob("phpledger-*.zip"))
    if len(archives) != 1:
        raise ContainerTestError(f"Expected exactly one built archive, found {archives}")
    return archives[0]


def build_release_image(zip_path: Path) -> None:
    relative = zip_path.relative_to(ROOT)
    run(
        [
            "docker", "build",
            "-f", str(ROOT / "docker/release/Dockerfile"),
            "--build-arg", f"RELEASE_ZIP={relative.as_posix()}",
            "-t", IMAGE_TAG,
            ".",
        ],
        cwd=ROOT,
    )


def start_network_and_database() -> None:
    try_run(["docker", "network", "rm", NETWORK])
    network_cmd = ["docker", "network", "create"]
    if NETWORK_SUBNET:
        network_cmd += ["--subnet", NETWORK_SUBNET]
    network_cmd.append(NETWORK)
    run(network_cmd)
    try_run(["docker", "volume", "rm", "-f", PRIVATE_VOLUME])
    run(["docker", "volume", "create", PRIVATE_VOLUME])
    run(
        [
            "docker", "run", "-d", "--name", DB_NAME, "--network", NETWORK,
            "-e", f"MYSQL_DATABASE=phpledger",
            "-e", f"MYSQL_USER=phpledger",
            "-e", f"MYSQL_PASSWORD={DB_PASSWORD}",
            "-e", f"MYSQL_ROOT_PASSWORD={DB_ROOT_PASSWORD}",
            "mysql:8.4", "--log-bin-trust-function-creators=1",
        ]
    )
    deadline = time.time() + 90
    while time.time() < deadline:
        probe = subprocess.run(
            ["docker", "exec", DB_NAME, "mysqladmin", "ping", "-h", "127.0.0.1", "-u", "root", f"--password={DB_ROOT_PASSWORD}", "--silent"],
            capture_output=True,
        )
        if probe.returncode == 0:
            return
        time.sleep(2)
    raise ContainerTestError("The disposable database did not become healthy in time.")


def start_web(extra_env: dict[str, str], *, name: str | None = None) -> str:
    container = name or WEB_NAME
    try_run(["docker", "rm", "-f", container])
    env_args = []
    base_env = {
        "PL_DB_HOST": DB_NAME,
        "PL_DB_NAME": "phpledger",
        "PL_DB_USER": "phpledger",
        "PL_DB_PASSWORD": DB_PASSWORD,
        "PL_PUBLIC_URL": "http://127.0.0.1:8080",
    }
    base_env.update(extra_env)
    for key, value in base_env.items():
        env_args += ["-e", f"{key}={value}"]
    run([
        "docker", "run", "-d", "--name", container, "--network", NETWORK,
        "-v", f"{PRIVATE_VOLUME}:/var/lib/phpledger",
        *env_args, IMAGE_TAG,
    ])
    return container


def wait_for_health(container: str, *, timeout: float = 90) -> dict:
    deadline = time.time() + timeout
    last_error: Exception | None = None
    while time.time() < deadline:
        state = subprocess.run(["docker", "inspect", "-f", "{{.State.Running}}", container], capture_output=True, text=True)
        if state.returncode != 0 or state.stdout.strip() != "true":
            time.sleep(1)
            continue
        try:
            body = subprocess.run(
                ["docker", "exec", container, "php", "-r",
                 "echo @file_get_contents('http://127.0.0.1:8080/health');"],
                capture_output=True, text=True, timeout=10,
            )
            if body.returncode == 0 and body.stdout.strip():
                decoded = json.loads(body.stdout.strip())
                if decoded.get("status") == "ok":
                    return decoded
                last_error = ContainerTestError(f"/health reported {decoded!r}")
        except Exception as error:  # noqa: BLE001 - retry loop, report the last failure
            last_error = error
        time.sleep(2)
    logs = subprocess.run(["docker", "logs", container], capture_output=True, text=True)
    raise ContainerTestError(f"/health did not become healthy in time ({last_error}).\n--- container logs ---\n{logs.stdout}\n{logs.stderr}")


def container_user(container: str) -> str:
    result = run(["docker", "exec", container, "id", "-un"], capture_output=True, text=True)
    return result.stdout.strip()


def exec_php(container: str, code: str) -> str:
    result = run(["docker", "exec", container, "php", "-r", code], capture_output=True, text=True)
    return result.stdout.strip()


def run_once_php(image: str, network: str, env: dict[str, str], code: str) -> str:
    # Mounts the same private volume the entrypoint prepared, so bootstrap.php's
    # application guard sees the PL_INSTALL_DIRECTORY it already created (bug 3 of
    # docs/CONTAINER.md's "three installer behaviours this image needed fixed").
    env_args = []
    for key, value in env.items():
        env_args += ["-e", f"{key}={value}"]
    result = run([
        "docker", "run", "--rm", "--network", network,
        "-v", f"{PRIVATE_VOLUME}:/var/lib/phpledger",
        *env_args, "--entrypoint", "php", image, "-r", code,
    ], capture_output=True, text=True)
    return result.stdout.strip()
