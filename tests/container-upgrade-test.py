"""PL_AUTO_MIGRATE upgrade check (1.2 M12, docs/CONTAINER.md).

Simulates "pull a newer image, restart with PL_AUTO_MIGRATE=1" using a single built
image (the only difference between "the installed version" and "the newer version" in
a real upgrade is which migrations are already applied, so this test creates that
same condition directly, the same way tests/update-migrate-test.php's fixture does):
seeds a populated installation whose schema stops one migration short of current -
migrated, an administrator created, and marked installed, but the most recent
migration genuinely never applied, not applied-then-reverted, which would leave
schema objects behind that a real re-run could collide with - then starts the image
normally with PL_AUTO_MIGRATE=1 against that same database and checks the pending
migration is applied and the container comes up healthy.

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


PHP_BOOT = (
    "require '/var/www/phpledger/www/phpledger/includes/bootstrap.php';"
    "require_once '/var/www/phpledger/www/phpledger/includes/functions/install_functions.php';"
    "require_once '/var/www/phpledger/www/phpledger/includes/functions/auth_functions.php';"
)

# Migrates up to (not including) the last migration, creates an administrator over
# that schema, and writes the installed.json receipt directly (install/complete.php
# refuses unless the schema is fully current, which is deliberately not true here).
PHP_SEED_PARTIAL_INSTALL = PHP_BOOT + (
    "$versions = pl_install_migration_versions();"
    "$pending = count($versions) - 1;"
    "pl_migrate($pending);"
    "$userId = pl_create_user(%(email)s, %(name)s, %(password)s);"
    "$operatorKey = pl_install_directory() . '/operator.key';"
    "if (!is_file($operatorKey)) { pl_install_write_private($operatorKey, bin2hex(random_bytes(32)) . \"\\n\", false); }"
    "pl_install_save_state(['format' => 1, 'initial_owner_id' => $userId, 'completed_at' => gmdate('c')], 'installed.json');"
    "echo end($versions) . ' pending of ' . count($versions) . ' total, owner ' . $userId;"
)

PHP_CHECK_LAST_STATUS = PHP_BOOT + (
    "$versions = pl_install_migration_versions();"
    "$last = end($versions);"
    "$status = DB::queryFirstField('SELECT status FROM pl_schema_migrations WHERE version = %s', $last);"
    "echo (string) $status;"
)


def php_literal(value: str) -> str:
    return "'" + value.replace("\\", "\\\\").replace("'", "\\'") + "'"


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
        "PL_INSTALL_DIRECTORY": "/var/lib/phpledger/installation",
    }
    try:
        archive = support.build_release_zip(build_dir)
        print(f"Built {archive.name} ({archive.stat().st_size} bytes)")
        support.build_release_image(archive)

        support.start_network_and_database()
        # The private volume needs its subdirectories before any bootstrap.php call
        # (including the seed step below) - normally the entrypoint's first action;
        # nothing has run it yet against this brand new volume.
        support.run_once_sh(
            support.IMAGE_TAG, support.NETWORK, {},
            "mkdir -p /var/lib/phpledger/installation /var/lib/phpledger/oauth",
        )

        # 1. A populated installation, one migration short of current - like a real
        #    deployment the moment before it pulls a newer image.
        seed_code = PHP_SEED_PARTIAL_INSTALL % {
            "email": php_literal(support.ADMIN_EMAIL),
            "name": php_literal(support.ADMIN_NAME),
            "password": php_literal(support.ADMIN_PASSWORD),
        }
        seeded = support.run_once_php(support.IMAGE_TAG, support.NETWORK, common_env, seed_code)
        check(" pending of " in seeded and seeded != "", f"seeded a populated installation one migration short of current ({seeded})")
        status_before = support.run_once_php(support.IMAGE_TAG, support.NETWORK, common_env, PHP_CHECK_LAST_STATUS)
        check(status_before == "", f"the most recent migration is genuinely pending, not applied ({status_before!r})")

        # 2. The pulled newer image, started normally (no admin env vars - installed.json
        #    already exists) with PL_AUTO_MIGRATE=1 against that same populated database.
        support.start_web({**common_env, "PL_AUTO_MIGRATE": "1"})
        health = support.wait_for_health(support.WEB_NAME)
        check(health.get("status") == "ok", "PL_AUTO_MIGRATE=1 comes up healthy against the populated database")

        status_after = support.run_once_php(support.IMAGE_TAG, support.NETWORK, common_env, PHP_CHECK_LAST_STATUS)
        check(status_after == "applied", f"PL_AUTO_MIGRATE=1 applied the previously pending migration (status {status_after!r})")
    finally:
        support.cleanup()
        # Reclaim before the image is removed: the reclaim runs in that image.
        if build_dir.exists():
            support.reclaim(build_dir)
        support.remove_test_image()
        if build_dir.exists():
            shutil.rmtree(build_dir)
    print("PL_AUTO_MIGRATE upgrade check passed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
