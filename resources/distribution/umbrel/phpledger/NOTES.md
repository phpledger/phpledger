# Umbrel packaging notes

Prepared for issue #101. **Nothing here has been submitted, tested through
`umbrel-test-app`, or linted with `npm run lint:apps` - all real steps this package
still needs before a PR.**

## What is confirmed vs. inferred

Confirmed directly from `getumbrel/umbrel-apps`'s own current packaging skill
(`.claude/skills/umbrel-package-app/SKILL.md`, fetched for this task) and a real
shipped app (`akaunting/`, a comparable finance app):

- The manifest field order, meaning, and folding rules (`manifestVersion`, `id`,
  `port` vs. `app_proxy.environment.APP_PORT`, `path`, `defaultUsername`,
  `deterministicPassword`, `gallery: []`/omitted `icon` for new packages).
- The `app_proxy` wiring pattern (`APP_HOST: <app-id>_<service>_1`, `APP_PORT` as the
  container's real listen port, no raw `ports:` publish for the web UI).
- The image-pinning rule: every image `registry/repo:tag@sha256:<digest>`, using the
  multi-arch index digest, not an architecture-specific one - confirmed against the
  real GHCR/Docker Hub registries for both images here (`ghcr.io/phpledger/phpledger`
  builds `linux/amd64` and `linux/arm64` per `.github/workflows/container-image.yml`;
  `mysql:8.4` publishes both from Docker Hub).
- `derive_entropy`/`exports.sh` as the generated-secret mechanism (no `$SERVICE_*`-style
  generator syntax the way Coolify has).
- Hooks are the documented place for "ownership fixes... that cannot be handled cleanly
  by compose, templates, or committed `data/` scaffolding" - directly justifying
  `hooks/pre-start` below.

Inferred, not exhaustively confirmed:

- **`port: 8773`** (the app_proxy/App Store port `umbrel-app.yml` declares) has not
  been checked against the full current App Store for collisions - the skill says it
  "must be unique across the App Store," and this task did not enumerate all ~390
  packages' `port:` values to confirm no other app already uses it. Flagged here
  rather than silently assumed correct; verify with the repo's own linter
  (`npm run lint:apps -- phpledger`, which "catches literal ports") before opening a
  PR, which is also the point at which port collisions would be caught for real.
- **Gallery images.** Issue #101's own summary says Umbrel wants "three to five gallery
  images" and that they "exist in the media kit." The skill fetched here for the
  current App Store contract says the opposite for a package submission:
  `gallery: []` for new packages, and "Do not commit screenshots, gallery assets, or
  icon assets for official App Store submissions; the Umbrel team will create and host
  final App Store assets." This package follows the skill (the more specific,
  currently-fetched packaging contract) over the issue text, and leaves `gallery: []`
  and `icon` omitted rather than inventing gallery asset entries. Include the three
  verified preview screenshots in the pull request body instead, per the skill's "PR
  Readiness" section.

## The one real risk: `web` service ownership vs. `${APP_DATA_DIR}`

umbrelOS creates package directories under `${APP_DATA_DIR}` owned by UID/GID
`1000:1000`. The `web` service's image always runs as its own baked-in `www-data` user
(`docs/CONTAINER.md`, "Non-root and read-only") - there is no `PUID`/`PGID`-style
setting to make it run as `1000:1000` instead, and forcing `user: "1000:1000"` on it
(the way `db` conventionally would) would break Apache's already-non-root startup this
image is specifically built around.

`hooks/pre-start` widens permissions on the bind-mounted
`${APP_DATA_DIR}/data/private` directory (`chmod -R go+rwX`) before each start so
`www-data`, whatever its numeric UID turns out to be on this image, can still write to
it. This has not been exercised against a real umbrelOS install - `umbrel-test-app` is
the next real check.
