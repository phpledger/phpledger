# Release signing

## Purpose

Starting with 1.0.0, every published `phpledger-<version>.zip` release archive is
accompanied by a signed metadata file that the browser updater
(`/maintenance.php`, `tools/resume-update.php`) verifies before it stages any
code change. The signature binds the exact version, channel, archive size/hash
and every file's path/size/hash, so an installation only ever applies a release
that a private key the operator has independently pinned actually produced.
This document describes key custody, the exact signing/verification commands
and what an operator must check before trusting a signature.

## Key custody

- The private signing key is generated and held by the project owner, **outside
  this repository and outside any customer installation**. See
  `.cache/release-1.0.0/signing/gen-publisher-key.md` for the exact one-line
  generation command (RSA-4096, above the code's enforced 3072-bit minimum).
- Recommended storage: offline (a disconnected drive, a hardware security
  module, or an access-controlled machine not used for anything else),
  encrypted at rest, with a passphrase supplied only through the
  `PL_RELEASE_KEY_PASSPHRASE` environment variable at signing time — never as a
  command-line argument, never committed, never logged.
- `tools/sign-update.php` and `pl_update_verify_metadata()` both reject any RSA
  key under 3072 bits, so an official 4096-bit key satisfies the code's
  requirement with margin.
- **Rotation/revocation procedure:** if the key is retired or suspected
  compromised, the owner generates a new key (same procedure), publishes the
  new public key and fingerprint in this file, in `README.md`, on the
  phpledger.com website and on the GitHub Wiki, and ships the new fingerprint
  in the next release's documentation so operators can compare it out of band
  before re-pinning. There is no in-band key-rotation message inside a signed
  update: a compromised key cannot be trusted to announce its own replacement.
  Operators re-pin `publisher.pem` (or `PL_UPDATE_PUBLIC_KEY`) manually after
  verifying the new fingerprint through a channel they trust independently of
  the ZIP itself.

## Official publisher key

```
-----BEGIN PUBLIC KEY-----
MIICIjANBgkqhkiG9w0BAQEFAAOCAg8AMIICCgKCAgEA4Jfmz2dc6O6sPjF/Tp+O
5yzba40ebGovHNtabVlhJMG7a8QA8oOz0FyEaUtElY1wxBAIqWwWLCJyFgW10k8m
O7IiI74KPYzGLHQlrFkLuG5CkXvpUowX3bUQcHjV+QBjn3DW3NRsNK1SHu6DViHG
o+6DlLU4eAR/fsY+otvWt9u48JJtjKGKRQB5/Ky1K+ZZV26Bfjap3kGcJdRPD8jt
J9jSECjk4C+JyHhjIofp/pfz37tDvyjSzu0ApY+mwl90KkOwIvCaXdfhrNNxPwWm
VKB4yzL77Kebq/eYvrEF0jvWNzOeXdg0xXvY6ZKqV3nlSR7L36YdqAZOXmGgiM/L
gfmhnzAGVdf/wHzjY9dFJAkvnShD4vXjrbndEe05sFxowNswpDKg9HZiE/TTKq5p
HsOgJlaN66qFLRMawGYYE5fz436f+GgFu9C9tNUMleGQkHLA1mgXidu98kIiF2NK
tFv6Wn8WXu35UD7OOw6l+boOx8/tK6MT9xJ9DNWrybYvsL8GThz8qryKz/v4x5Zr
a61RL0Lr03lbbK/+7JBOalATIXtYYA7OmotpaLL4oKLkpV5djfe8YJDIgo34OCa3
oGd4rnZrcZ63y+DffYN+/5XiN4X07FUSCtD7vsPX3blTIVhfwpgM2M6+25yU0Jw0
WpR95oV9TCcPCP2aRVB1A7cCAwEAAQ==
-----END PUBLIC KEY-----
```

SHA-256 fingerprint (of the DER-encoded SubjectPublicKeyInfo — see
"Computing the fingerprint" below):

```
4e58a5f46b0538c9b37aaadbfced9a2d8ad2f7d413bc168a67b1b94feaa78e78
```

RSA-4096, generated 19 September 2026 on the owner's machine. The private key and its passphrase stay
there; this repository holds only the public key, also at
[`resources/release/publisher-public.pem`](../resources/release/publisher-public.pem). SHA-256 of that PEM file:
`85ad4ac665961fbf290067edf0d010145310e8c07d05097626e285a4cba4a257`.

Confirm the fingerprint from a second place before pinning it: the GitHub release notes for
v1.1.0 and <https://phpledger.com/download/> publish the same value. Then save the PEM as
`publisher.pem` in the private installation directory (or point `PL_UPDATE_PUBLIC_KEY` at it).
1.1.0 is the first release signed with this key; 1.0.0 carries no signed metadata.

## Signing a release (publisher only)

Requires PHP with the `zip` and `openssl` extensions (available inside this
repository's `test` Docker service) and the private key on a path the owner
controls, never inside `C:\phpledger`.

```sh
php tools/sign-update.php \
  --archive=/private/release/phpledger-1.0.0.zip \
  --key=/private/signing/publisher.pem \
  --output=/private/release/phpledger-1.0.0.update.json
```

An encrypted key's passphrase is supplied via `PL_RELEASE_KEY_PASSPHRASE`, not
as an argument. The command refuses to overwrite an existing output file and
validates the complete package inventory (every file's path/size/hash) before
signing, so a tampered or incomplete ZIP is rejected before a signature is ever
produced.

To run this inside the project's own Docker test image without ever writing
the private key into the repository, use
`.cache/release-1.0.0/signing/sign-release.sh` (documented there), which
bind-mounts only the key's own directory, read-only, into a disposable
`docker compose run --rm` container:

```sh
.cache/release-1.0.0/signing/sign-release.sh \
  --archive /path/to/phpledger-1.0.0.zip \
  --key /path/to/publisher.pem \
  --output /path/to/phpledger-1.0.0.update.json \
  --public-out /path/to/publisher-public-key.pem
```

The script also verifies the resulting metadata with the application's own
`pl_update_verify_metadata()` and prints the archive's version, channel,
byte size, SHA-256 and both public-key fingerprint conventions before you
publish anything.

## Metadata file naming

Publish `phpledger-<version>.update.json` next to `phpledger-<version>.zip` and
its `.sha256` file as GitHub release assets, for example:

```
phpledger-1.0.0.zip
phpledger-1.0.0.zip.sha256
phpledger-1.0.0.update.json
```

The updater's own inventory (embedded in the signed payload) already commits
to the archive's exact bytes; the separate `.sha256` file remains for operators
who want to verify the download without invoking PHP.

## Computing the fingerprint

The canonical fingerprint published in this file, on the website and on the
Wiki is the SHA-256 hash of the **DER-encoded SubjectPublicKeyInfo** — the same
convention used for TLS/SSH public-key pinning, and independent of PEM
line-ending/whitespace differences:

```sh
openssl pkey -pubin -in publisher-public.pem -outform DER | openssl dgst -sha256
```

A secondary reference — the SHA-256 of the raw PEM file bytes — may also be
published for operators who prefer to compare the file directly:

```sh
openssl dgst -sha256 publisher-public.pem
```

If both are published, the DER-based value is the one to treat as
authoritative in case of any discrepancy.

## Verifying signed metadata (what the code does)

`pl_update_verify_metadata()` in
`www/phpledger/includes/functions/update_functions.php` is the sole verifier.
It:

- Requires the public key to be RSA, at least 3072 bits (rejects the metadata
  outright otherwise).
- Verifies the RSA-SHA256 signature over the payload with `openssl_verify()`.
- Requires `schema === 1`, a valid semantic version, and `channel` to exactly
  match the channel the operator selected (`stable` or `preview`); a
  preview/RC version cannot claim the `stable` channel or vice versa.
- Requires the signed version to be strictly newer than the installation's
  current version (`version_compare($version, $current, '>')`) — downgrades
  are rejected.
- Requires the signed `min_php` to be satisfied by the running PHP version.
- If present, enforces `expires_at` (rejects if in the past) and `issued_at`
  (rejects if more than 5 minutes in the future) — **`expires_at` is optional
  per-signature**; the signing tool does not currently set one, so operators
  should not assume every signed release carries an expiry unless a future
  publisher process adds it deliberately.
- Requires every file entry to have a valid 64-character hex SHA-256 and a
  bounded byte count, rejects duplicate (case-folded) paths, and requires the
  five files the updater depends on for recovery
  (`PACKAGE-MANIFEST.json`, `vendor/autoload.php`,
  `www/phpledger/includes/bootstrap.php`, `www/phpledger/public/index.php`,
  `www/phpledger/public/maintenance.php`) to be present in the signed
  inventory.

The public key itself is never taken from the archive or the signed message:
`pl_update_begin()` reads it only from `PL_UPDATE_PUBLIC_KEY` (an environment
variable pointing at a private, host-controlled path) or, if that is unset,
from `<private installation directory>/publisher.pem`. **A key found only
inside a downloaded ZIP establishes no trust and must never be used as the
pinned key.**

## Operator instructions

1. Obtain the publisher's public key and fingerprint from at least one source
   *other than the release ZIP itself* — this document in the repository, the
   phpledger.com website, or the GitHub Wiki. Compare the fingerprint across
   at least two of these independent surfaces before trusting it.
2. Pin the key either as `publisher.pem` inside the private installation
   directory (`PL_INSTALL_DIRECTORY`, default
   `www/phpledger/storage/installation`) or via the `PL_UPDATE_PUBLIC_KEY`
   environment variable pointing at a private, host-controlled file path.
3. Re-verify the fingerprint (Computing the fingerprint, above) any time you
   re-pin the key, and again after any announced rotation.
4. Never copy a `publisher.pem` or public key that arrived only inside a
   downloaded release archive or a `.update.json` file — it may have been
   substituted by whoever produced that archive.
5. Confirm `channel` (`stable` or `preview`) matches your installation's
   intended release track before starting an update.

## GitHub release asset naming

Each GitHub release attaches, at minimum:

```
phpledger-<version>.zip
phpledger-<version>.zip.sha256
phpledger-<version>.update.json          (once an official key is published)
phpledger-<version>-media-kit.zip
phpledger-<version>-media-kit.zip.sha256
```

## Current status

1.1.0 (19 September 2026) is the first release with official signed metadata,
`phpledger-1.1.0.update.json`, signed with the key above. 1.0.0 shipped without it, so an installation
on 1.0.0 upgrades to 1.1.0 manually (see `resources/release/UPGRADE.md`) and can use `/maintenance.php`
for later releases after pinning the key.

1.1.1 (20 September 2026) was published without signed metadata, an omission corrected by 1.1.2
(20 September 2026), which is signed with the same key; its envelope verifies as an upgrade from both
1.1.0 and 1.1.1 because the verifier requires only a newer version. 1.1.1 is not signed retroactively.
Media kits accompany major releases only (owner decision B29); signed metadata accompanies every release,
including previews.
