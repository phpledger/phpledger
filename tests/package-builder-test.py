"""Isolated package safety and reproducibility tests; no network or real data."""
import importlib.util
import json
import subprocess
import tempfile
import unittest
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("package_builder", ROOT / "tools/build-package.py")
builder = importlib.util.module_from_spec(spec)
spec.loader.exec_module(builder)


class PackageTests(unittest.TestCase):
    def setUp(self):
        self.base = ROOT / ".cache/package-tests"
        self.base.mkdir(parents=True, exist_ok=True)
        self.temporary = tempfile.TemporaryDirectory(dir=self.base)
        self.root = Path(self.temporary.name)
        self.source = self.root / "source"
        self.vendor = self.root / "vendor"
        self.source.mkdir()
        (self.vendor / "composer").mkdir(parents=True)
        self.package = {"name": "example/library", "version": "1.0.0", "source": {"reference": "fixture"}}
        (self.vendor / "autoload.php").write_text("<?php // sample loader", encoding="utf-8")
        (self.vendor / "composer/installed.json").write_text(json.dumps({"dev": False, "dev-package-names": [], "packages": [self.package]}), encoding="utf-8")
        # composer.lock and the deny rule are build inputs only; the rest are the package's own files.
        inputs = {"composer.json": "{}", "composer.lock": json.dumps({"packages": [self.package]}), "resources/release/deny.htaccess": "Require all denied\n"}
        files = {name: "Sample test fixture {{VERSION}} {{SOURCE_COMMIT}}" for name in ("README.txt", "LICENSE", "index.php", ".htaccess", "licenses/THIRD-PARTY-NOTICES.md", "www/phpledger/public/.htaccess")}
        for name, content in {**inputs, **files}.items():
            (self.source / name).parent.mkdir(parents=True, exist_ok=True)
            (self.source / name).write_text(content, encoding="utf-8")
        self.policy = {"project_license": "MIT", "files": [{"source": name, "destination": name} for name in files]}
        self.git("init", "-q")
        self.git("config", "user.name", "Package fixture")
        self.git("config", "user.email", "fixture@example.invalid")
        self.git("add", ".")
        self.git("commit", "-qm", "Sample package fixture")

    def tearDown(self):
        if not self.root.resolve().is_relative_to(self.base.resolve()):
            raise AssertionError("Refusing cleanup outside the isolated test workspace")
        self.temporary.cleanup()

    def git(self, *args):
        subprocess.run(["git", "-C", str(self.source), *args], check=True, capture_output=True)

    def build(self, output="one"):
        return builder.build(self.source, self.vendor, self.policy, self.root / output, "0.1.0-preview")

    def test_repeat_builds_are_identical_and_manifest_covers_files(self):
        first, second = self.build(), self.build("two")
        self.assertEqual(first.read_bytes(), second.read_bytes())
        self.assertEqual("phpledger-0.1.0-preview.zip", first.name)
        with zipfile.ZipFile(first) as archive:
            prefix = "phpledger/"
            self.assertTrue(all(name.startswith(prefix) for name in archive.namelist()))
            self.assertEqual("Sample test fixture 0.1.0-preview", archive.read(prefix + "README.txt").decode()[:33])
            manifest = json.loads(archive.read(prefix + "PACKAGE-MANIFEST.json"))
            self.assertEqual(len(archive.namelist()), len(manifest["files"]) + 1)
            for item in manifest["files"]:
                import hashlib
                self.assertEqual(hashlib.sha256(archive.read(prefix + item["path"])).hexdigest(), item["sha256"])
        with self.assertRaises(builder.PackageError):
            self.build()

    def test_current_runtime_services_views_and_migrations_are_in_the_allowlist(self):
        policy = json.loads((ROOT / "tools/package-files.json").read_text(encoding="utf-8"))
        sources = {entry["source"] for entry in policy["files"]}
        for folder in ("www/phpledger/includes/functions", "www/phpledger/templates/views", "www/phpledger/install/migrations"):
            for path in (ROOT / folder).glob("*.php"):
                source = path.relative_to(ROOT).as_posix()
                self.assertTrue(source in sources, "Runtime dependency omitted from package: " + source)
        for source in sources:
            self.assertTrue((ROOT / source).is_file(), "Missing allowlisted source: " + source)

    def test_dirty_source_is_rejected_before_output(self):
        (self.source / "README.md").write_text("changed", encoding="utf-8")
        with self.assertRaisesRegex(builder.PackageError, "clean"):
            self.build()
        self.assertFalse((self.root / "one").exists())

    def test_stable_and_candidate_channels_are_version_bound(self):
        for version, channel, status in (("1.0.0", "stable", "stable"), ("1.0.0-rc.1", "preview", "release-candidate"), ("0.9.0-beta", "preview", "development-preview")):
            with self.subTest(version=version):
                archive = builder.build(self.source, self.vendor, self.policy, self.root / version, version, channel)
                with zipfile.ZipFile(archive) as package:
                    manifest = json.loads(package.read("phpledger/PACKAGE-MANIFEST.json"))
                    self.assertEqual(channel, manifest["channel"])
                    self.assertEqual(status, manifest["status"])
        for version, channel in (("1.0.0-rc.1", "stable"), ("1.0.0", "preview")):
            with self.assertRaisesRegex(builder.PackageError, "channel"):
                builder.release_identity(version, channel)

    def test_ambiguous_or_unsafe_release_versions_are_rejected(self):
        for version in ("1.0", "01.0.0", "1.0.0-rc.01", "1.0.0-", "1.0.0-rc..1", "1.0.0/escape", "1.0.0+untracked"):
            with self.subTest(version=version), self.assertRaises(builder.PackageError):
                builder.release_identity(version)

    def test_untracked_release_input_requires_and_checks_sha256(self):
        local_doc = self.source / "docs/local-release.md"
        local_doc.parent.mkdir(exist_ok=True)
        local_doc.write_text("Sample local release input", encoding="utf-8")
        (self.source / ".git/info/exclude").write_text("/docs/local-release.md\n", encoding="utf-8")
        self.policy["files"].append({"source": "docs/local-release.md", "destination": "docs/local-release.md"})
        with self.assertRaisesRegex(builder.PackageError, "SHA-256 pin"):
            self.build()
        import hashlib
        self.policy["files"][-1]["sha256"] = hashlib.sha256(local_doc.read_bytes()).hexdigest()
        # The ignored/untracked file is accepted only after its exact bytes are pinned.
        self.assertTrue(self.build().is_file())

    def test_upstream_test_dump_is_excluded_but_runtime_remains(self):
        library = self.vendor / "sergeytsalkov/meekrodb"
        (library / "simpletest").mkdir(parents=True)
        (library / "simpletest/statements.sql").write_text("Sample upstream fixture")
        (library / "db.class.php").write_text("<?php // sample library")
        with zipfile.ZipFile(self.build()) as archive:
            names = archive.namelist()
            self.assertFalse(any(name.endswith("statements.sql") for name in names))
            self.assertTrue(any(name.endswith("db.class.php") for name in names))

    def test_unapproved_licence_and_development_vendor_are_rejected(self):
        self.policy["project_license"] = None
        with self.assertRaisesRegex(builder.PackageError, "licence"):
            self.build()
        self.policy["project_license"] = "MIT"
        (self.vendor / "composer/installed.json").write_text(json.dumps({"dev": True, "packages": [self.package]}), encoding="utf-8")
        with self.assertRaisesRegex(builder.PackageError, "production-only"):
            self.build()

    def test_private_folders_get_deny_rules_and_vendor_keeps_only_code_and_notices(self):
        (self.source / "tools").mkdir()
        (self.source / "tools/sample-tool.php").write_text("<?php // sample tool", encoding="utf-8")
        self.git("add", ".")
        self.git("commit", "-qm", "Sample private tool")
        self.policy["files"].append({"source": "tools/sample-tool.php", "destination": "tools/sample-tool.php"})
        library = self.vendor / "example/library"
        for name in ("src/Runtime.php", "LICENSE", "NOTICE", "README.md", "CHANGELOG.md", "adr/0001-decision.md", ".gitattributes", "phpdoc.dist.xml"):
            (library / name).parent.mkdir(parents=True, exist_ok=True)
            (library / name).write_text("Sample dependency file", encoding="utf-8")
        (self.vendor / "bin").mkdir()
        (self.vendor / "bin/sample-helper").write_text("Sample helper", encoding="utf-8")
        with zipfile.ZipFile(self.build()) as archive:
            names = set(archive.namelist())
        for kept in ("src/Runtime.php", "LICENSE", "NOTICE"):
            self.assertIn("phpledger/vendor/example/library/" + kept, names)
        for dropped in ("README.md", "CHANGELOG.md", "adr/0001-decision.md", ".gitattributes", "phpdoc.dist.xml"):
            self.assertNotIn("phpledger/vendor/example/library/" + dropped, names)
        self.assertNotIn("phpledger/vendor/bin/sample-helper", names)
        for folder in ("vendor", "tools", "licenses"):
            self.assertIn(f"phpledger/{folder}/.htaccess", names)
        self.assertNotIn("phpledger/composer.lock", names)

    def test_release_allowlist_is_minimal_and_web_ready(self):
        policy = json.loads((ROOT / "tools/package-files.json").read_text(encoding="utf-8"))
        destinations = {entry["destination"] for entry in policy["files"]}
        self.assertTrue(builder.REQUIRED_FILES.issubset(destinations), builder.REQUIRED_FILES - destinations)
        for forbidden in ("INSTALL.md", "UPGRADE.md", "RELEASE-NOTES.md", "README.md", "CLA.md", "composer.json", "composer.lock"):
            self.assertNotIn(forbidden, destinations)
        # resources/distribution/ holds catalogue and hosting-panel manifests. Like
        # docker/release/*, they describe how to deploy a release; they are never
        # unzipped into one, so an entry here would ship build tooling to operators.
        for prefix in ("docs/", "resources/distribution/", "resources/tax/", "resources/integrations/", "resources/sample-data/", "resources/ui/", "tests/"):
            self.assertFalse(any(name.startswith(prefix) for name in destinations), prefix)

    def test_recovery_loader_matches_the_published_release(self):
        # pl_update_begin() refuses signed browser updates that change this independent loader.
        import hashlib
        loader = (ROOT / "www/phpledger/public/maintenance.php").read_bytes().replace(b"\r\n", b"\n")
        self.assertEqual("a53b5bab377a3b4da98d6f98318f1526f773976d83c2edbd96fd43dee4fd19e5", hashlib.sha256(loader).hexdigest())

    def test_every_resource_read_at_runtime_is_packaged(self):
        policy = json.loads((ROOT / "tools/package-files.json").read_text(encoding="utf-8"))
        sources = {entry["source"] for entry in policy["files"]}
        import re
        for path in (ROOT / "www/phpledger").rglob("*.php"):
            for reference in re.findall(r"'(/resources/[^']+)'", path.read_text(encoding="utf-8", errors="replace")):
                resource = reference.lstrip("/")
                if resource.endswith("/"):
                    self.assertTrue(any(source.startswith(resource) for source in sources), f"{path.name} reads {resource}")
                else:
                    self.assertIn(resource, sources, f"{path.name} reads {resource}")

    def test_private_and_escaping_destinations_are_rejected(self):
        for name in ("../escape", "/absolute", "a/../../escape", ".env", "config.local.php", "storage/data", "database.sql"):
            with self.subTest(name=name):
                self.policy["files"][0]["destination"] = name
                with self.assertRaises(builder.PackageError):
                    self.build()


if __name__ == "__main__":
    unittest.main()
