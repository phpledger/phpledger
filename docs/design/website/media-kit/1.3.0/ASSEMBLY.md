# Assemble the 1.3.0 media kit

This is release-operator source, not a finished media archive. The six Markdown copy files follow the 1.0/1.1 media-kit layout. `CAPTURE-PLAN.json` is a suggestion list, not evidence. No earlier screenshot has been relabelled as 1.3.0.

## Source checks

```text
python tools/build-media-kit.py --version 1.3.0 --check-source
python tests/media-kit-test.py
```

The tests use synthetic temporary ZIP/PNG fixtures. They do not supply product screenshots or release acceptance evidence.

## Supply final artifact evidence

1. Freeze the final application ZIP after the release gates. Keep its exact bytes and SHA-256.
2. Install that ZIP into the capture environment. Use the final shared-demo policy when showing the installer, dummy database fields or fixed public account. Do not use a source bind mount and call it an exact-package capture.
3. Capture original PNG screenshots using fictional records. The capture plan covers installer/login/onboarding, payroll, loans, year end, packages and draft Arabic at desktop/tablet/mobile sizes; use actual observed routes. Check the complete visual result and captions. A route list or screenshot alone does not establish usability acceptance.
4. Save the evidence JSON outside the published kit. For each image, record its hash, dimensions, observed route, truthful caption, alt text, capture context and explicit visual-review declaration. The application archive hash must be the same for every image. If the application artifact changes, recapture or independently re-establish exact-artifact evidence before rebuilding.

Example schema (replace all illustrative paths and digest tokens; do not mark unreviewed material reviewed):

```json
{
  "version": "1.3.0",
  "fictional_sample_data": true,
  "sample_identity": "Fictional companies prepared for this release capture",
  "source_archive": {
    "path": "../artifacts/phpledger-1.3.0.zip",
    "sha256": "LOWERCASE_SHA256_OF_FINAL_APPLICATION_ZIP"
  },
  "images": [
    {
      "file": "installer-database-1440.png",
      "path": "captures/installer-database-1440.png",
      "route": "/install",
      "caption": "Describe the actual observed installer state and labelled dummy fields.",
      "alt": "Describe the meaningful visible content for a reader who cannot see the image.",
      "capture_context": "Local exact-ZIP installation with shared-demo policy; fictional records only.",
      "reviewed": true,
      "fictional_data": true,
      "source_archive_sha256": "LOWERCASE_SHA256_OF_FINAL_APPLICATION_ZIP",
      "sha256": "LOWERCASE_SHA256_OF_ORIGINAL_PNG",
      "width": 1440,
      "height": 1000
    }
  ]
}
```

Paths may be absolute or relative to the evidence JSON. Local paths are not copied into the published manifest. Routes exclude query strings and fragments to keep sessions and request parameters out of media metadata. Only original PNG bytes are supported. The assembler checks the application ZIP's VERSION, all hashes, dimensions, filenames and review declarations. It cannot independently prove what process generated a screenshot; the release operator owns that provenance and visual review.

## Assemble and publish

```text
python tools/build-media-kit.py --version 1.3.0 --evidence .cache/release-evidence/media/evidence.json --output .cache/release-evidence/media-final
```

The command emits `phpledger-1.3.0-media-kit.zip` and its `.sha256` file. The ZIP has one versioned root, six copy files, original screenshots, `screenshots/manifest.json` and `CHECKSUMS.txt`. Sorted entries, fixed timestamps, fixed permissions and compression parameters make repeated assembly byte-identical in the same runtime. A different compression library can change ZIP bytes; always publish the checksum of the actual archive. Existing differing output is refused; use a new output directory rather than overwriting another candidate.

Run assembly twice into separate output directories and compare hashes. Inspect the archive and manifest; keep its application-source hash aligned with the release artifact. The assembler refuses missing images or capture plans, but the operator must still choose adequate coverage and verify captions, fonts, crop, responsive layout and absence of private information.

Parent release coordination owns signing, final screenshots, final public URLs, media-kit attachment, release-note download link, website metadata and anonymous download/checksum verification. Record the media archive hash and public verification in the publication receipt. Preparing this kit authorizes no social post, email or external campaign. Do not call local assembly a completed 1.3.0 release.
