"""Container image tag rules (1.2 M12, decision B42). No Docker or network use."""
import importlib.util
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("container_image_tags", ROOT / "tools/container-image-tags.py")
tags_module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(tags_module)


class ContainerTagTests(unittest.TestCase):
    def test_stable_release_gets_version_minor_major_and_latest(self):
        tags = tags_module.image_tags("1.2.1", ["ghcr.io/phpledger/phpledger"])
        self.assertEqual(
            tags,
            [
                "ghcr.io/phpledger/phpledger:1.2.1",
                "ghcr.io/phpledger/phpledger:1.2",
                "ghcr.io/phpledger/phpledger:1",
                "ghcr.io/phpledger/phpledger:latest",
            ],
        )

    def test_prerelease_gets_only_its_own_version_tag(self):
        for version in ("1.2.1-rc.1", "1.2.1-beta", "2.0.0-preview.4"):
            with self.subTest(version=version):
                tags = tags_module.image_tags(version, ["ghcr.io/phpledger/phpledger"])
                self.assertEqual(tags, [f"ghcr.io/phpledger/phpledger:{version}"])
                self.assertFalse(any(tag.endswith(":latest") for tag in tags), "latest must never be tagged for a prerelease")

    def test_latest_is_not_tagged_for_a_prerelease_even_alongside_a_stable_looking_major(self):
        # A prerelease of the same major/minor as an already-published stable release
        # must still never move latest or the floating major/minor tags.
        tags = tags_module.image_tags("1.2.2-rc.1", ["ghcr.io/phpledger/phpledger"])
        self.assertNotIn("ghcr.io/phpledger/phpledger:latest", tags)
        self.assertNotIn("ghcr.io/phpledger/phpledger:1.2", tags)
        self.assertNotIn("ghcr.io/phpledger/phpledger:1", tags)

    def test_every_configured_registry_gets_the_same_suffixes_in_order(self):
        tags = tags_module.image_tags("1.2.1", ["ghcr.io/phpledger/phpledger", "example/phpledger"])
        self.assertEqual(
            tags,
            [
                "ghcr.io/phpledger/phpledger:1.2.1",
                "ghcr.io/phpledger/phpledger:1.2",
                "ghcr.io/phpledger/phpledger:1",
                "ghcr.io/phpledger/phpledger:latest",
                "example/phpledger:1.2.1",
                "example/phpledger:1.2",
                "example/phpledger:1",
                "example/phpledger:latest",
            ],
        )

    def test_rejects_invalid_or_ambiguous_versions(self):
        for version in ("1.2", "v1.2.1", "01.2.1", "1.2.1+build", "1.2.1-"):
            with self.subTest(version=version):
                with self.assertRaises(ValueError):
                    tags_module.image_tags(version, ["ghcr.io/phpledger/phpledger"])

    def test_at_least_one_image_is_required(self):
        with self.assertRaises(ValueError):
            tags_module.image_tags("1.2.1", [])

    def test_cli_prints_one_tag_per_line(self):
        exit_code = tags_module.main(["container-image-tags.py", "1.2.1-rc.1", "ghcr.io/phpledger/phpledger"])
        self.assertEqual(exit_code, 0)


if __name__ == "__main__":
    unittest.main()
