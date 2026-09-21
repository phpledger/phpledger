"""PL_AUTO_MIGRATE upgrade check (1.2 M12, docs/CONTAINER.md).

Simulates "pull a newer image, restart with PL_AUTO_MIGRATE=1" using a single built
image (the only difference between "the installed version" and "the newer version" in
a real upgrade is which migrations are already applied, so this test creates that
same condition directly): installs from the environment against a disposable database
(a populated installation), removes the most recent migration's applied receipt the
same way tests/update-migrate-test.php's fixture does, then starts a fresh container
of the same image with PL_AUTO_MIGRATE=1 against that same database and checks the
pending migration is applied and the container comes up healthy.

    python3 tests/container-upgrade-test.py
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


PHP_LAST_VERSION = (
    "require '/var/www/phpledger/www/phpledger/includes/bootstrap.php';"
    "require_once '/var/www/phpledger/www/phpledger/includes/functions/install_functions.php';"
    "$versions = pl_install_migration_versions();"
    "echo end($versions);"
)

PHP_REVERT_LAST = (
    "require '/var/www/phpledger/www/phpledger/includes/bootstrap.php';"
    "require_once '/var/www/phpledger/www/phpledger/includes/functions/install_functions.php';"
    "$versions = pl_install_migration_versions();"
    "$last = end($versions);"
    "DB::query('DELETE FROM pl_schema_migrations WHERE version = %s', $last);"
    "echo 'reverted ' . $last;"
)

PHP_CHECK_APPLIED = (
    "require '/var/www/phpledger/www/phpledger/includes/bootstrap.php';"
    "require_once '/var/www/phpledger/www/phpledger/includes/functions/install_functions.php';"
    "$versions = pl_install_migration_versions();"
    "$last = end($versions);"
    "$status = DB::queryFirstField('SELECT status FROM pl_schema_migrations WHERE version = %s', $last);"
    "echo $status;"
)


def main() -> int:
    support.cleanup()
    support.remove_test_image()
    build_dir = support.ROOT / "build" / f"{support.PREFIX}-scratch"
    if build_dir.exists():
        shutil.rmtree(build_dir)
    build_dir.mkdir(parents=True)
    common_env = {
        "PL_DB_HOST": support.DB_NAME,
        "PL_DB_NAME": "phpledger",
        "PL_DB_USER": "phpledger",
        "PL_DB_PASSWORD": support.DB_PASSWORD,
    }
    try:
        archive = support.build_release_zip(build_dir)
        print(f"Built {archive.name} ({archive.stat().st_size} bytes)")
        support.build_release_image(archive)

        support.start_network_and_database()

        # 1. A populated installation, exactly like an existing deployment before a pull.
        support.start_web(
            {
                "PL_ADMIN_EMAIL": support.ADMIN_EMAIL,
                "PL_ADMIN_NAME": support.ADMIN_NAME,
                "PL_ADMIN_PASSWORD": support.ADMIN_PASSWORD,
            }
        )
        support.wait_for_health(support.WEB_NAME)
        last_version = support.run_once_php(support.IMAGE_TAG, support.NETWORK, common_env, PHP_LAST_VERSION)
        check(last_version != "", "the populated installation has at least one migration recorded")

        # 2. Make that installation look like it is one migration behind the image.
        support.try_run(["docker", "rm", "-f", support.WEB_NAME])
        reverted = support.run_once_php(support.IMAGE_TAG, support.NETWORK, common_env, PHP_REVERT_LAST)
        check(reverted == f"reverted {last_version}", "the most recent migration's receipt was removed")
        status_before = support.run_once_php(support.IMAGE_TAG, support.NETWORK, common_env, PHP_CHECK_APPLIED)
        check(status_before == "", f"migration {last_version} is now pending, not applied ({status_before!r})")

        # 3. A fresh container of "the pulled newer image" against the same, now-behind database.
        support.start_web({**common_env, "PL_AUTO_MIGRATE": "1"}, name=support.WEB_NAME)
        health = support.wait_for_health(support.WEB_NAME)
        check(health.get("status") == "ok", "PL_AUTO_MIGRATE=1 comes up healthy against the populated database")

        status_after = support.run_once_php(support.IMAGE_TAG, support.NETWORK, common_env, PHP_CHECK_APPLIED)
        check(status_after == "applied", f"PL_AUTO_MIGRATE=1 applied the pending migration {last_version} (status {status_after!r})")
    finally:
        support.cleanup()
        support.remove_test_image()
        if build_dir.exists():
            shutil.rmtree(build_dir)
    print("PL_AUTO_MIGRATE upgrade check passed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
