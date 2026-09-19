## 1.1.0: install like WordPress, MariaDB, username and logo

Published 19 September 2026.

- **Install from any web folder.** The package unpacks to a folder named `phpledger/`. Upload it anywhere inside a website, such as `public_html/accounts` or XAMPP's `htdocs`, open its address, and the installer starts by itself. The package-root `index.php` and `.htaccess` send requests to `www/phpledger/public` and refuse every private path. Pointing a domain's document root at `www/phpledger/public` still works and remains the most secure layout.
- **No setup key on a normal install.** The installer opens without a key when the database is on the same server (`localhost`, `127.0.0.1` or `::1`). A remote database host needs a one-time code written to private storage, and an operator-supplied `PL_SETUP_KEY` or `setup.key` keeps the strict mode of 1.0.0.
- **MariaDB.** MariaDB 10.4 or newer works alongside MySQL 8.4. Three MySQL-only spellings (`FOR SHARE`, `SKIP LOCKED` and the `utf8mb4_0900_ai_ci` collation) are translated just before execution. Migration files and their checksums are unchanged.
- **Choose your username.** The first account chooses a username as well as an email and password, and can sign in with either (migration 032). Existing accounts keep signing in by email.
- **Your logo.** An optional installation logo appears in the menu and on the sign-in page (migration 033).
- **Local computers.** Plain `http://localhost` is accepted for trying PHP Ledger on your own computer, with an OpenSSL fallback for XAMPP.
- **Smaller package.** 1,500 files instead of 1,583, with one short `README.txt`. The full install and upgrade guides are online.
- **Signed updates from here on.** This is the first release with official signed update metadata (`phpledger-1.1.0.update.json`). Pin the publisher key listed in the [release signing guide](https://github.com/phpledger/phpledger/blob/master/docs/RELEASE-SIGNING.md#official-publisher-key) to use `/maintenance.php` for later updates.
- **Fixes.** Sample companies can no longer be started in production through the onboarding preview (only local, test and demo provisioning may create them). Two templates that showed a replacement character instead of a middle dot are fixed.
- **Release tooling.** `www/phpledger/VERSION` is the single version source, a reproducible release builder and a draft-only release workflow were added, and the [release protocol](https://github.com/phpledger/phpledger/blob/master/docs/RELEASE-PROTOCOL.md) documents each channel's upgrade path.

Upgrading from 1.0.0 is a manual upgrade, because 1.0.0 shipped without signed update metadata. See [UPGRADE.md](https://github.com/phpledger/phpledger/blob/master/resources/release/UPGRADE.md#from-100-to-110).

The assurance limits recorded for 1.0.0 below still apply. The installer was additionally checked on MySQL 8.4 and MariaDB 10.4, 10.6, 10.11 and 11.4, in the PHP 8.3 Apache image, and on the owner's XAMPP; a real shared host and an unfamiliar operator have not yet been observed.

### Verify the download

| File | SHA-256 |
|---|---|
| `phpledger-1.1.0.zip` (2,927,089 bytes) | `ba8de6cfca58505dda7b4d5b00e377512a9f134d06e007f97ba0ecfdd022135a` |
| `phpledger-1.1.0.update.json` | `10085caf22cbc7df42c2795eaf2bfc16c0608f06d4bedf81d028f100a7be764c` |
| `phpledger-1.1.0-media-kit.zip` | `e281008d6bc193f05453e6c9e8ec6ee7b28bf3158fe85d91c37fbd9df844571d` |

Publisher key fingerprint (SHA-256 of the DER SubjectPublicKeyInfo): `4e58a5f46b0538c9b37aaadbfced9a2d8ad2f7d413bc168a67b1b94feaa78e78`. See the [release](https://github.com/phpledger/phpledger/releases/tag/v1.1.0) and [RELEASE-SIGNING.md](https://github.com/phpledger/phpledger/blob/master/docs/RELEASE-SIGNING.md#official-publisher-key).
