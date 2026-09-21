# create-phpledger

Scaffolds a Docker Compose deployment of [PHP Ledger](https://phpledger.com/), open-source
self-hosted double-entry accounting with a cash point of sale.

```bash
npm create phpledger@latest
```

It writes files and prints the command to run. **It installs nothing itself**, starts no container
and contacts no service other than the release feed.

## What it does

1. Reads the current stable version from `https://phpledger.com/releases/index.json`, so the
   deployment is pinned to a real release rather than a moving `latest` tag.
2. Asks for the target directory, the public URL, the port and the administrator's details.
3. Generates the database and administrator passwords with `node:crypto`. It never asks you to
   invent one and never puts a password on a command line.
4. Writes `compose.yaml`, a `.env` holding the generated secrets, a `.gitignore` that excludes it,
   and a `README.md` with the start and upgrade steps.

Pass `--yes` to take every default and finish setup in the browser instead. Pass `--force` to write
into a directory that is not empty.

## Why this is not an installer

PHP Ledger is a PHP application. npm cannot install it, and a package that claimed to would
misrepresent what it does. This package only prepares a deployment of the published container image
at `ghcr.io/phpledger/phpledger`, which is the supported container channel.

If you want the application itself, take the ZIP from the
[releases page](https://github.com/phpledger/phpledger/releases) and unzip it into a web folder, or
follow [docs/CONTAINER.md](https://github.com/phpledger/phpledger/blob/master/docs/CONTAINER.md).

## After it runs

```bash
cd <your directory>
docker compose up -d
```

The generated `.env` holds the administrator password. Keep it, and do not commit it.

Upgrading is documented in the generated `README.md` and in
[docs/CONTAINER.md](https://github.com/phpledger/phpledger/blob/master/docs/CONTAINER.md): pull the
new tag, then start once with `PL_AUTO_MIGRATE=1` so pending migrations apply. Back up the database
volume first.

## Requirements

Node 20 or newer to run this, and Docker with the Compose plugin to run what it writes.

## Licence

AGPL-3.0-or-later, the same as PHP Ledger. A commercial licence is available; see
[the pricing page](https://phpledger.com/pricing/).
