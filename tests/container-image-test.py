"""Container image install, health and non-root checks (1.2 M12, docs/CONTAINER.md).

Builds docker/release/Dockerfile from a release ZIP packaged from the current,
clean worktree, starts it against a disposable MySQL database, and checks:

  1. The entrypoint installs from PL_ADMIN_EMAIL/PL_ADMIN_NAME/PL_ADMIN_PASSWORD and
     /health answers {"status": "ok"} once it has.
  2. The container refuses to run as root - the process inside it is www-data.

Uses its own Docker network and container names (PL_CONTAINER_TEST_PREFIX), and its
own Compose project for the disposable vendor build (PL_CONTAINER_TEST_COMPOSE_PROJECT
/ PL_CONTAINER_TEST_SUBNET) so it does not collide with another session's containers.
Removes everything it created, including the built image, when done.

    python3 tests/container-image-test.py
"""
from __future__ import annotations

import shutil
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import _container_support as support  # noqa: E402


def check(condition: bool, label: str) -> None:
    if not condition:
        raise AssertionError(label)
    print(f"PASS {label}")


def main() -> int:
    support.cleanup()
    support.remove_test_image()
    build_dir = support.ROOT / "build" / f"{support.PREFIX}-scratch"
    if build_dir.exists():
        shutil.rmtree(build_dir)
    build_dir.mkdir(parents=True)
    try:
        archive = support.build_release_zip(build_dir)
        print(f"Built {archive.name} ({archive.stat().st_size} bytes)")
        support.build_release_image(archive)

        support.start_network_and_database()
        support.start_web(
            {
                "PL_ADMIN_EMAIL": support.ADMIN_EMAIL,
                "PL_ADMIN_NAME": support.ADMIN_NAME,
                "PL_ADMIN_PASSWORD": support.ADMIN_PASSWORD,
            }
        )
        health = support.wait_for_health(support.WEB_NAME)
        check(health.get("status") == "ok", "the image installs from the environment and /health answers ok")

        user = support.container_user(support.WEB_NAME)
        check(user == "www-data" and user != "root", "the container refuses to run as root (runs as " + user + ")")

        uid = support.run(["docker", "exec", support.WEB_NAME, "id", "-u"], capture_output=True, text=True).stdout.strip()
        check(uid != "0", "the effective uid is not 0")
    finally:
        support.cleanup()
        support.remove_test_image()
        if build_dir.exists():
            shutil.rmtree(build_dir)
    print("Container image install, health and non-root checks passed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
