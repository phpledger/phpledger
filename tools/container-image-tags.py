"""Compute container image tags for a release (1.2 M12, decision B42).

A stable version ("1.2.1") gets four tags: <version>, <major>.<minor>, <major> and
latest. A prerelease (any version with a hyphen, e.g. "1.2.1-rc.1") gets only its own
<version> tag - latest and the floating major/major.minor tags must never move for a
prerelease (docs/strategy/RELEASE-PLAN-1.2.md, M12). Used by
.github/workflows/container-image.yml so this rule is a tested function, not inline
shell arithmetic on a version string.

    python3 tools/container-image-tags.py 1.2.1 ghcr.io/phpledger/phpledger
    python3 tools/container-image-tags.py 1.2.1-rc.1 ghcr.io/phpledger/phpledger example/phpledger

Prints one "image:tag" per line, in the order registries were given.
"""
from __future__ import annotations

import re
import sys

VERSION_PATTERN = re.compile(r"^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(-[0-9A-Za-z.-]+)?$")


def tag_suffixes(version: str) -> list[str]:
    """The tag suffixes (without the image name) for one version."""
    match = VERSION_PATTERN.match(version)
    if not match:
        raise ValueError(f"Invalid semantic version: {version!r}")
    major, minor, _patch, prerelease = match.groups()
    if prerelease:
        return [version]
    return [version, f"{major}.{minor}", major, "latest"]


def image_tags(version: str, images: list[str]) -> list[str]:
    if not images:
        raise ValueError("At least one image is required.")
    suffixes = tag_suffixes(version)
    return [f"{image}:{suffix}" for image in images for suffix in suffixes]


def main(argv: list[str]) -> int:
    if len(argv) < 3:
        print("Usage: container-image-tags.py <version> <image> [<image> ...]", file=sys.stderr)
        return 2
    version, images = argv[1], argv[2:]
    try:
        for tag in image_tags(version, images):
            print(tag)
    except ValueError as error:
        print(str(error), file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
