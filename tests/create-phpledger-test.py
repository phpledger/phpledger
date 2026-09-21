"""create-phpledger scaffolder checks (clients/js/create-phpledger). No Docker or
network use: the release feed is stubbed with PL_FEED_URL, a test-only variable
the scaffolder reads as a local file path instead of fetching (see the comment
above readFeed() in bin/create-phpledger.mjs).

    python3 tests/create-phpledger-test.py
"""
from __future__ import annotations

import json
import os
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
BIN = ROOT / "clients" / "js" / "create-phpledger" / "bin" / "create-phpledger.mjs"
NODE = shutil.which("node")


def run_scaffolder(work_dir: Path, target: Path, feed_version: str, extra_args: list[str] | None = None) -> subprocess.CompletedProcess:
    feed_path = work_dir / "feed.json"
    feed_path.write_text(json.dumps({"channels": {"stable": {"version": feed_version}}}), encoding="utf-8")
    env = {**os.environ, "PL_FEED_URL": str(feed_path)}
    args = [NODE, str(BIN), str(target), "--yes", *(extra_args or [])]
    return subprocess.run(args, env=env, capture_output=True, text=True, timeout=30)


def read_env(target: Path) -> dict[str, str]:
    values: dict[str, str] = {}
    for line in (target / ".env").read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, _, value = line.partition("=")
        values[key] = value
    return values


@unittest.skipIf(NODE is None, "node is not on PATH")
class CreatePhpledgerTests(unittest.TestCase):
    def setUp(self) -> None:
        self.work_dir = Path(tempfile.mkdtemp(prefix="create-phpledger-test-"))
        self.addCleanup(shutil.rmtree, self.work_dir, ignore_errors=True)

    def test_pins_a_concrete_version_not_latest(self) -> None:
        target = self.work_dir / "site"
        result = run_scaffolder(self.work_dir, target, "1.2.1")
        self.assertEqual(result.returncode, 0, result.stderr)

        compose = (target / "compose.yaml").read_text(encoding="utf-8")
        self.assertIn("PL_VERSION:-1.2.1}", compose)
        self.assertNotIn("PL_VERSION:-latest}", compose)

    def test_env_file_has_a_non_empty_db_password(self) -> None:
        target = self.work_dir / "site"
        result = run_scaffolder(self.work_dir, target, "1.2.1")
        self.assertEqual(result.returncode, 0, result.stderr)

        env_values = read_env(target)
        self.assertIn("PL_DB_PASSWORD", env_values)
        self.assertTrue(env_values["PL_DB_PASSWORD"], "PL_DB_PASSWORD must not be empty")

    def test_gitignore_lists_env(self) -> None:
        target = self.work_dir / "site"
        result = run_scaffolder(self.work_dir, target, "1.2.1")
        self.assertEqual(result.returncode, 0, result.stderr)

        gitignore_lines = (target / ".gitignore").read_text(encoding="utf-8").splitlines()
        self.assertIn(".env", gitignore_lines)

    def test_generated_passwords_differ_from_each_other(self) -> None:
        target = self.work_dir / "site"
        result = run_scaffolder(self.work_dir, target, "1.2.1")
        self.assertEqual(result.returncode, 0, result.stderr)

        env_values = read_env(target)
        passwords = {env_values["PL_DB_PASSWORD"], env_values["PL_DB_ROOT_PASSWORD"], env_values["PL_ADMIN_PASSWORD"]}
        self.assertEqual(len(passwords), 3, "the database, root and admin passwords must all differ")

    def test_rerun_without_force_refuses(self) -> None:
        target = self.work_dir / "site"
        first = run_scaffolder(self.work_dir, target, "1.2.1")
        self.assertEqual(first.returncode, 0, first.stderr)

        second = run_scaffolder(self.work_dir, target, "1.2.1")
        self.assertNotEqual(second.returncode, 0, "a second run without --force must refuse to overwrite")
        self.assertIn("--force", second.stderr)

    def test_rerun_with_force_succeeds(self) -> None:
        target = self.work_dir / "site"
        first = run_scaffolder(self.work_dir, target, "1.2.1")
        self.assertEqual(first.returncode, 0, first.stderr)

        second = run_scaffolder(self.work_dir, target, "1.2.1", extra_args=["--force"])
        self.assertEqual(second.returncode, 0, second.stderr)

    def test_falls_back_to_latest_when_the_feed_is_unreachable(self) -> None:
        target = self.work_dir / "site"
        env = {**os.environ, "PL_FEED_URL": str(self.work_dir / "missing-feed.json")}
        result = subprocess.run([NODE, str(BIN), str(target), "--yes"], env=env, capture_output=True, text=True, timeout=30)

        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("falling back to the \"latest\" image tag", result.stderr)
        compose = (target / "compose.yaml").read_text(encoding="utf-8")
        self.assertIn("PL_VERSION:-latest}", compose)


if __name__ == "__main__":
    unittest.main()
