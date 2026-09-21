# Softaculous custom package - spec quirks

Source read for this package: <https://www.softaculous.com/docs/developers/making-custom-package/>,
plus <https://www.softaculous.com/docs/admin/scripts-requirements> (checked
for a requirements schema; it turned out to document an *admin-facing*
troubleshooting report, not an authoring-time requirements tag - see below).
Package built for [issue #101](https://github.com/phpledger/phpledger/issues/101),
against release `v1.2.1` (the first release with the complete non-interactive
install path, per the issue).

**Nothing here has been submitted to Softaculous.** This is a local, reviewed
"custom package" (their term) that needs no approval and is not the same as
the separate, slower "official library" listing, whose process is not
publicly documented.

## Filename and layout requirement

Files are meant to sit together in a single directory named after a
Softaculous-chosen "SOFTNAME" identifier (their docs show
`/var/softaculous/SOFTNAME/`), which is a panel-side install detail, not
something this repository can name in advance. This directory ships flat -
`info.xml`, `install.xml`, `install.php`, `install.js`, `fileindex.php`,
`upgrade.xml`, `upgrade.php`, `upgrade.js` all live beside each other, plus
the zipped release itself (named `SOFTNAME.zip`) which is uploaded to the
panel separately from this source directory rather than committed here.

## `admin_pass` field name, not `admin_password`

Softaculous's own documented example install.xml literally uses
`admin_username` / `admin_pass` / `admin_email` as field names. The docs are
explicit that `<handle>` is an *optional* vendor-defined callback, not a
required binding, so field names are otherwise arbitrary - but matching
their own example spelling (`admin_pass`, not `admin_password`) seemed safer
than inventing a different one. `admin_name`, which the application also
requires and Softaculous's example does not cover, was added as an ordinary
custom field.

## No documented masked/password input type

The documented input types are `text`, `checkbox`, `select`, `textarea`,
`hidden`. There is no dedicated masked-password type in what could be
confirmed from the docs page, so `admin_pass` is declared `type="text"`
(matching the documented example verbatim, which also uses `type="text"`).
Flagged as a TODO in `install.xml` to confirm with Softaculous support
whether a masked variant exists before wide release; this does not affect
the "no hardcoded secret" rule since the value is entered by the operator at
install time either way.

## No `softdbport` in `$__settings`

Softaculous supplies `softdomain`, `softdirectory`, `softpath`, `softurl`,
`softdb`, `softdbuser`, `softdbhost`, `softdbpass` automatically once
`<db>mysql</db>` is set, but the docs do not list a database *port* key.
This package therefore collects `db_port` as its own field, defaulted to
`3306`, since `config.local.php`'s `port` key is mandatory in the
application's contract.

## `<datadir>` semantics are underspecified

The docs describe `<datadir>` only as "Data directory specification" with no
worked example - not enough to trust it for the one directory this
application hard-requires (`www/phpledger/storage/installation`, mode
`0700`, or the application refuses every request). `install.xml` omits
`<datadir>` and `install.php` creates/chmods that directory itself with the
documented `smkdir()`/`schmod()` helpers instead.

## `fileindex.php` has no documented syntax

The docs describe its *purpose* ("only the files and folders present in this
file will be removed" on uninstall) but never show its PHP syntax. The
shipped `fileindex.php` is a best-effort `$fileindex = array(...)` listing
the application's actual top-level layout; the variable name and structure
are not confirmed against a primary source and are marked as a TODO in the
file itself.

## `<install>`/`<upgrade>` run inside the panel's own PHP - no CLI guarantee

Softaculous's `install.php`/`upgrade.php` execute as PHP inside the panel
process, which is not necessarily the same PHP environment as the eventual
hosting account's shell, and some hosts disable `exec()`/`proc_open()` via
`disable_functions`. `install.php` checks for both before shelling out to
the application's own `install/migrate.php`, `install/create-admin.php`, and
`install/complete.php` (as `AGENTS.md`/`AGENT_MESSAGES.MD` record was always
the intended design - "the Softaculous `install.php` will call your
non-interactive install path"); if shelling out is unavailable, it reports
the exact three commands the operator must run manually via SSH or a
one-time cron job, instead of silently leaving the site half-installed.

## Password handling

`install/create-admin.php` explicitly refuses a password passed as a CLI
argument. `install.php` passes it only via the `PL_ADMIN_PASSWORD`
environment variable of a `proc_open()` child process (never interpolated
into the command string, never `putenv()`'d into the parent's own
long-lived environment, never logged), matching the application's own
stated contract.

## `cust.sql` omitted

Not included. The application owns its schema entirely through
`install/migrate.php`'s versioned migrations; a `cust.sql` would either
duplicate that (drifting the moment a migration changes) or be a no-op.
Softaculous's own docs describe `cust.sql` as optional.

## Not confirmed from a primary source

- The exact nesting Softaculous expects under `info.xml`'s `<languages>`
  block (a language-code element wrapping each key, versus the reverse) -
  `info.xml` uses its best-effort reading and flags this inline.
- Whether `<softversion>4.0.6</softversion>` (the value used in Softaculous's
  own docs example) is actually the right minimum for this application, or
  whether a newer Softaculous baseline is now expected.
- The official-library submission process (cost, review criteria) -
  unrelated to this custom package, which needs no approval.

## The release archive wraps everything in one directory

`phpledger-1.2.1.zip` contains a single top-level `phpledger/` directory: every
path inside it is `phpledger/www/…`, `phpledger/index.php` and so on. Softaculous
extracts the package archive directly into `softpath`, and `install.php` resolves
its paths as `softpath` + `www/phpledger/…`.

**So the package archive must be built with that wrapping directory stripped**,
not by renaming the release ZIP. Build it as:

```
unzip phpledger-1.2.1.zip && cd phpledger && zip -r ../phpledger.zip .
```

`__install()` checks for `www/phpledger/install` before doing anything and stops
with "Package layout is missing www/phpledger/install" if that step was skipped,
so getting this wrong fails loudly at install time rather than producing a broken
installation. Verified against the published 1.2.1 asset: 1585 entries, all under
`phpledger/`, `sha256 e983add7d909daf2b78151f6bc1d2195c2603fd57a711bf88e1ffb42c9c214b7`.
