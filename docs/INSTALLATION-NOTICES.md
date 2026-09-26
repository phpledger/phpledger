# Installation notices

Every PHP Ledger installation sends an anonymous installation notice, independent of accounting records (owner decision, 26 September 2026, amending B16; until 1.4.1 it was a default that an administrator could switch off). It is sent when setup completes, when an administrator checks for updates and when registration details change; an installation that predates the notice sends it on its next update check. There is no switch for the anonymous notice, in the installer, in the settings or in the environment. Named registration is off unless an administrator explicitly chooses it under Updates and privacy, and can be removed there. An unavailable receiver never restricts installation, updates or accounting: the attempt is bounded and its failure is only recorded locally. The sender and its preferences belong to the application; this document also describes the separate receiver service.

## Data contract and privacy

The fixed HTTPS endpoint is `https://phpledger.com/installations/notice`. It accepts POST JSON up to 8 KiB with exactly these required fields: `schema` (1), `installation_id` (32 lowercase hexadecimal characters), `event` (`install`, `update-check`, or `preferences`), `version`, `channel` (`stable` or `preview`), `php_version`, `database_engine` (`mysql` or `mariadb`), `database_version`, `os_family`, `mode` (`managed`, `container`, `composer`, or `panel`), and `at` (ISO 8601 timestamp). Version/channel consistency is checked. Duplicate JSON keys, unknown fields, invalid types and invalid values are rejected. The optional `registration` object accepts only `name`, `email`, `site`, and `company`; name and email are required when this object is present. Site URLs cannot contain credentials, queries or fragments.

No journals, balances, accounts, customer records, database credentials or installation URL are part of anonymous notices. The stable random installation identifier is pseudonymous, not proof of a person or unique organization. Statistics are self-reported and unauthenticated; they are not verified deployment counts.

Only the latest received notice per installation is kept, for 90 days after receipt. A later anonymous notice replaces and removes an earlier registration. Removing registration sends an anonymous notice that replaces the named record; nothing else deletes a record before its retention ends. For deletion sooner, use the private contact route in `SECURITY.md`, providing the installation identifier privately when available; do not publish personal details in a GitHub issue. The operator can delete that record with the CLI below. Optional registration remains private and has no public listing.

The receiver transiently uses the request IP for abuse limits. It stores a daily HMAC of the binary IP, not the raw IP; rate metadata expires after 48 hours. The HMAC key is private to the service. The receiver and its gateway disable access/error logs. The outer HTTPS host may have its own access logs and retention: deployment must review those separately and must not claim they are erased by receiver cleanup.

## Storage, limits and isolation

There is no database or application bootstrap in this service. Private filesystem storage is outside the web root, mode 0700, with mode 0600 files. A global exclusive lock serializes rate updates, record replacement, deletion, aggregation and cleanup. Writes use exclusive temporary files and atomic rename. Symlinks, hard links and unsafe existing file permissions fail closed. Storage is partitioned into 256 buckets with at most 128 files per bucket for each of records and rate data; capacity exhaustion returns 429. Every accepted request rotates one bounded cleanup bucket. The maintenance container runs a complete bounded cleanup at startup and hourly, so normal expired-data removal is within the following maintenance interval. Failed maintenance must be monitored and repaired; no exact deletion timing is promised during outages.

The receiver permits 60 accepted notices per IP per minute and 600 per hour using fixed windows. Invalid bodies are rejected before storage. Lock contention and capacity limits can also return 429. Responses are `200 {"accepted":true}`, 400 invalid input, 413 oversized body, 405 wrong method, 429 rate/capacity/busy, and 503 unavailable storage. Other paths return 404. No session cookie is issued.

## Hosting

`compose.notices.yaml` builds a dedicated Apache/PHP service from the existing release runtime image, using `docker/installation-notice.Dockerfile`. Set `PL_NOTICE_BASE_IMAGE` to an available reviewed release image if changing the default; set `PL_NOTICE_IMAGE` for a prebuilt receiver image. This does not use the PHP development server. It neither loads the accounting application nor shares the demo database or volume.

The PHP receiver has only an internal Docker network and no default outbound route. The maintenance container has no network. A small nginx gateway exposes only `127.0.0.1:18302`; the public host terminates HTTPS and forwards only the exact notice path to this loopback port. The gateway is on an edge bridge and is not the PHP processor. Containers use read-only roots, dropped capabilities and separate temporary directories. Persistent data uses the dedicated `notice_records` volume. Check subnet `10.219.68.0/24` for conflicts before deploying; if changed, update the gateway address trusted by Apache too.

The public HTTPS proxy MUST overwrite `X-Real-IP` with its actual trusted client address (for nginx, `proxy_set_header X-Real-IP $remote_addr;`) and must not forward an arbitrary client-provided value. Apache trusts only the private gateway address. Never publish port 18302 on a non-loopback address. Configure TLS and outer-host request/log policies before exposing the endpoint. Deployment is separate from preparing these files.

```sh
docker compose -f compose.notices.yaml build
docker compose -f compose.notices.yaml up -d
# Private operator commands; never expose these through HTTP.
docker compose -f compose.notices.yaml exec receiver php /opt/installation-service/summary.php --summary
docker compose -f compose.notices.yaml exec receiver php /opt/installation-service/summary.php --prune
docker compose -f compose.notices.yaml exec receiver php /opt/installation-service/summary.php --delete=0123456789abcdef0123456789abcdef
```

The summary contains aggregate counts by version, channel, engine, OS and mode, plus registered and recently seen counts. It excludes identifiers, names, email addresses, websites and company names. Access requires private filesystem/container privileges. No public summary endpoint exists. Backups, if an operator adds them, require their own deletion/retention policy; none are configured by this service.

## Local validation

Use only a disposable dedicated project whose name starts with `codex`, with the receiver listening on local port 18302. Run `python tests/installation-notice-receiver-test.py --project codex13notice --port 18302`. The test verifies its Docker project before creating sample notices; it never calls the public endpoint. Coverage includes strict payloads, HTTP methods and limits, private registration replacement, atomic concurrent writes, rate metadata privacy, retention/deletion, symlink/hardlink refusal and the receiver's absence of an outbound route. Public HTTPS routing, host-proxy header policy, host logs and live maintenance monitoring require separate deployment evidence.
