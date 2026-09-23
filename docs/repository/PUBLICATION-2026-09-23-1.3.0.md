# PHP Ledger 1.3.0 publication receipt — 23 September 2026

The application release, website, shared installer demo and installation-notice service are public. Publication and the scoped public acceptance checks are complete.

## Published application and media

- Release: <https://github.com/phpledger/phpledger/releases/tag/v1.3.0>, published 2026-09-23 at 02:03:09 UTC.
- Immutable application source and annotated tag: `9a4d2872545e232d5c94933e701e5c5ae8cd1de1`.
- `phpledger-1.3.0.zip`: SHA-256 `f2103a58bf3202f8e82bd974114b713b086b0e68825c5111442110bc87b97e12`; 3,521,783 bytes; 1,649 archive members including the manifest, 1,648 managed files.
- Official signed update envelope: SHA-256 `e6808a8bc20cbc5c6543d559b4005bc761b443fafde23f0a16bf17622d97f9ff`. Publisher SPKI SHA-256 `4e58a5f46b0538c9b37aaadbfced9a2d8ad2f7d413bc168a67b1b94feaa78e78`.
- [Versioned media kit](https://github.com/phpledger/phpledger/releases/download/v1.3.0/phpledger-1.3.0-media-kit.zip): SHA-256 `2b66042d9b219a18f55a647baf27c174eea6742c9580d527122d1df27c2d4bcf`, with 37 reviewed screenshots from the final artifact, captions, alt text, announcement drafts, guided demo and FAQs. Two builds were byte-identical.
- All five public release assets were downloaded anonymously and matched their expected hashes. The Git tag is annotated, not Git-signed; the official RSA-signed update envelope authenticates the release ZIP.

CI run [35807952814](https://github.com/phpledger/phpledger/actions/runs/35807952814) passed all nine jobs. Each of eight PHP/database jobs passed 658 tests with no failures. The reproducible release workflow [35807952923](https://github.com/phpledger/phpledger/actions/runs/35807952923) passed; its archive compression differs, but all 1,649 members match the published signed archive byte for byte. Fresh installation, published 1.2.1 CLI upgrade, officially signed HTTP update, deliberate post-migration recovery and a real 1.2.1 container upgrade passed against the final artifact. See [exact-artifact gates](RELEASE-1.3.0-RC4-GATES.md).

## Both official container registries

GHCR and Docker Hub publish `phpledger/phpledger:1.3.0` from the verified release ZIP. Their shared multi-architecture index is `sha256:5277187a140c459c852f6b1cb1b7649a1754c189ebaef426d289c94c2d890d73`.

- Linux amd64: `sha256:82af4cc738ae9df85073c652f467afc11ffe22428e70e1370de4545ae1378105`.
- Linux arm64: `sha256:0dcbc1cde6b52d0b8efe727de27f05a481887efb555b7ab86bfdaa96078748b4`.

Publication workflow [35808808018](https://github.com/phpledger/phpledger/actions/runs/35808808018) succeeded. Each registry/platform combination was pulled by immutable digest, and its manifest and all 1,648 managed files were compared with the signed ZIP. See [registry verification](RELEASE-1.3.0-REGISTRY-VERIFICATION.md). Umbrel's application pin, YunoHost's archive hash and Softaculous's file/size metadata match this release; provider submission or acceptance is not claimed.

Both registries' `latest` tags were independently resolved and match the same published 1.3.0 index.

## Documentation, discovery and optional sample packages

README, changelog, release notes, version manifests, affected distribution metadata and generated website metadata describe the same shipped features and limitations. The GitHub Wiki was published at `29467e17bff2429f7252f2d38019cf559664ded4`; anonymous checks matched the reviewed Home, Getting Started, Release 1.3.0 and Contributing/Support pages. Repository About was updated; the website URL and 20 topics were reviewed and retained.

Eleven public `phpledger/sample-*` repositories carry signed v1.0.0 data-only sample packages: distributor, jewelry studio, light manufacturing, membership club, pharmacy, restaurant, retail shop, seasonal business, service agency, service workshop and trader. All 33 public assets were downloaded and verified. The 44-file demo preload set and eleven website envelopes match these published artifacts. Sample histories remain optional and fictional.

Contribution and donation invitations use existing public maintainer contact/Discussion destinations. No payment destination was invented, and no announcement, email or campaign was sent.

## Hosting status and recovery

The website at source `e5e81eac8727d25e852427f19113650ca4921fcc` is active with 355 hash-verified staged public files. Its exact Git-blob archive SHA-256 is `8a5ec0cad7102cf93ea97913cf626fc30e45754add53a890fe5998426278dd59`. Build/check passed for 88 pages with no errors or warnings; AEO audit and 15 final local responsive states passed. Six public news/download/directory browser states also passed, with HTTP 200, no overflow and no console errors or warnings. Public verification matched 352 requested file routes directly. Three preserved routing behaviors were verified against their expected content: `/404.html` returns 404 with the exact reviewed error-page body, `/credits.html` redirects to the matching `/credits/` page, and `/support/` redirects to the matching `/pricing/` page. The public signed update, freshly downloaded ZIP, all eleven sample signatures and their package manifests passed verification.

The installation-notice receiver is active behind the exact `/installations/notice` location. Public GET returned 405; a fictional anonymous POST returned 200 and was deleted through the private CLI, with a second deletion confirming absence. No session cookie was issued. Receiver networking is internal, maintenance has no network, the gateway binds loopback only, and the services have read-only roots, dropped capabilities and no-new-privileges. The vhost suppresses access/error logging for that exact endpoint. No named registration or real customer data was submitted during acceptance.

The first new demo launch stopped during fresh MySQL initialization. The mode-0644 initializer was sourced, leaking `set -u` into MySQL's entrypoint; its subsequent `MYSQL_ONETIME_PASSWORD` read exited with code 1. The original demo was immediately restored, and public health returned 200 for version 1.1.0 at 02:40 UTC. Old volumes/network and failed-attempt resources were retained. No accounting production database was upgraded.

Hosting-only correction `070488f7acf6589bd3a346237a614d696e887f4b` wraps the initializer body in a subshell. A fresh real MySQL test with mode 0644 passed initialization and both dedicated accounts' database access. The initializer is excluded from the signed application ZIP and all verified production image payloads; the published tag and artifacts remain unchanged. The new immutable r2 hosting bundle launched successfully with isolated retry volumes/network, pinned restored-baseline evidence and independent rollback files. Live MySQL completed its first initialization with zero restarts. The old and failed-attempt volumes/networks remain retained.

Live browser acceptance then exposed an inaccurate HTTP warning behind the TLS proxy. Hosting-only correction `75b77ccf48418a8f3496c2e6c70e9ce4e2b020d7` maps HTTPS only from the exact configured proxy connection and exact `https` header. Seven real Apache/PHP tests passed, including untrusted peers and forged/multiple/missing headers. A separate immutable Apache mount was applied by recreating only the web container; database/scheduler identities, image, environment and all other mounts were preserved. The deployed LF-normalized Apache blob SHA-256 is `644d742492b8c06d3b04955c08306dd9a7f16d3311d3c20db9810612a5b2aba6`. Public installer acceptance subsequently reported all 14 checks passing and correctly recognized HTTPS.

The live scheduler reset at exactly 03:00:00 UTC and advertised the next reset at 04:00 UTC. Normal installation and fictional Cedar Workshop onboarding completed; a second independent browser signed in with the public credentials and opened the same book. Separate Expense/Receipt and Packages screens passed at 1440, 768 and 390 widths; all eleven read-only signed samples appeared. A deliberate reset at 03:08:43 returned an existing authenticated session to the first installer step. The complete installer/onboarding flow was repeated after the HTTPS correction, producing nine replacement captures with all 14 preflight checks passing. A final reset at 03:10:36 again invalidated the old session and left the demo at its first setup step. Both verification browser sessions were closed without submitting further setup forms.

Live demo acceptance includes two complete installer/onboarding passes, nine responsive route checks, eleven verified sample packages, an independent shared-login check and two observed existing-session invalidations. The final browser receipt SHA-256 is `dcd1f31fb9380464ed1e419a3bead4f4a8590334b9cc7e5435b4969a4d1aec99`. Hosted runtime status reported version 1.3.0, source `9a4d2872545e232d5c94933e701e5c5ae8cd1de1`, public health 200 and valid Nginx configuration. The two hosting fixes do not alter the application payload.

## UX, migration and assurance boundaries

Expense and Receipt have separate new/edit/save screens, fixed server-side kinds and matching payer/payee/category labels. There is no type toggle or browser draft conversion. Legacy links remain compatible. Forty-six HTTP checks, 16 i18n tests, package checks and nine responsive route states passed, including a balanced preview. The shared demo uses the ordinary six-stage installer and onboarding, read-only dummy database values and the protected public application identity. A local scheduled UTC-hour reset was observed at 02:00:04; the public scheduled and existing-session reset checks also passed as recorded above.

The release includes versioned migrations through 056; the exact-artifact 1.2.1 upgrade applied 14 migrations. The subsequent initializer/hosting correction introduces no migration or schema change. Raw secrets were not exposed. Public GitHub/registry publication and authorized hosting actions were performed; no business email, payment or campaign was sent. Main-checkout pre-existing generated-file changes were preserved separately from the integration checkout.

All eight pre-existing dirty files in the original main checkout were checked against the pre-integration backup and remain byte-identical. Release and hosting work was committed from the isolated integration checkout. No broad reset, cleanup or old-site snapshot replay was performed.

Technical tests and screenshots do not establish independent accounting/security certification, native Arabic/Urdu review, every hosting provider's acceptance or real-business month-end pilot completion. These remain explicit separate assurance work.
