# Installatron application package - spec quirks and gaps

Source read for this package (all fetched 2026-09-21, no login required
according to each page itself): the Installatron developer documentation
portal at <https://www.installatron.com/developer>, then
<https://www.installatron.com/developer/apps> (Application Packaging SDK
overview), <https://www.installatron.com/developer/apps/definitions>
(init.xml package definition schema, with verbatim `<info>`/`<requirement>`/
`<archive>`/`<file>`/`<table>` examples), and
<https://www.installatron.com/developer/apps/fields> (the `<fields>` block
and its internal field ids). Built for
[issue #101](https://github.com/phpledger/phpledger/issues/101), against
release `v1.2.1`.

**Nothing here has been submitted to Installatron.** Installatron's own
quickstart path is to log into their hosted "Installatron Installer Editor"
and register a package there; this directory is the source material for
that, kept local per this task's scope, not a submission.

## The real authoring tool is a hosted editor, not a checked-in file tree

Installatron's own docs say the normal way to start is "log into the
Installatron Installer Editor" and fill in a form, after which "all values
can be easily changed later except Script Name." The file-based layout this
package follows (`installers/_installerid_/init.xml`,
`_version#_/init.xml`, `locale_en.php`, image files) is what their docs
describe as the *result* of using that editor, not a documented alternative
upload format. This package therefore may need to be re-entered through
that editor rather than dropped in as files - that mechanic could not be
confirmed either way from the public pages.

## Two real, load-bearing gaps in the `<install>` step

The docs are detailed and internally consistent for almost everything -
package layout, the `<information>`/`<requirements>`/`<archives>`/
`<skeleton>`/`<fields>` schema, the internal field ids
(`version`/`email`/`login`/`passwd`/`sitetitle`/`sitedescription`/
`language`), and the PHP5-vs-PHP7 split (separate `install.php`/
`upgrade.php` files vs. code embedded in `<install>`/`<upgrade>` elements).

They stop short of two things this application's install absolutely needs,
and repeated, more targeted fetches of the same and adjacent pages did not
surface them:

1. **How `<install>`'s embedded PHP reads the assigned database
   host/name/user/password and the site's filesystem path.** The docs state
   only that "database credentials are made available" for install/upgrade
   code, without naming an object, superglobal, or function.
2. **How that code signals success or failure back to Installatron** (a
   return value, a thrown exception, a specific call) - not stated anywhere
   found.

Per this task's instruction not to invent an undocumented detail,
`phpledger/1.2.1/init.xml`'s `<install>` block is a heavily commented stub
that states exactly what real work it needs to do (write
`config.local.php`, create `storage/installation` at `0700`, run
`install/migrate.php` → `install/create-admin.php` → `install/complete.php`
with the password only via the child process's `PL_ADMIN_PASSWORD`
environment variable) and then deliberately throws, rather than emitting
plausible-looking code against a guessed API. This is the package's single
biggest open item; closing it needs either direct contact with Installatron
or inspection of a real accepted package inside the Installer Editor
(neither was available in this task).

## Root element name of the installer-level `init.xml` is a guess

The docs' fetched excerpts describe the `<info id="..." value="..."/>` lines
inside the installer-level `init.xml` verbatim, but never show the file's
outer wrapping element. `phpledger/init.xml` wraps them in `<installer>...
</installer>`, which is a reasonable but unconfirmed guess; flagged inline
in that file.

## `locale_en.php`'s internal structure is unconfirmed

Confirmed: the filename, its PHP format, and that it is the default
English-language file. Not confirmed: the variable name or array shape it
must return/define. `phpledger/locale_en.php` is a best-effort flat
associative array, clearly labelled as unverified in its own header
comment.

## Requirement ids beyond the one worked example

The docs' `<requirements>` example shows `itron`, `diskspace`, `php`,
`mysql`, and `php-bcmath` verbatim. The application also needs `pdo`,
`pdo_mysql`, `mbstring`, `session`, `curl`, `openssl`, and `fileinfo`;
their `php-<extension>` ids are inferred by extending the one confirmed
pattern (`php-bcmath`), not individually confirmed. Likewise the
`VERSION-RANGE-DEF` syntax for open-ended lower bounds (e.g. "8.2 or
newer") is only a documented placeholder name, with no worked example of
its actual text - `8.2-` and `8.4-` here are a best-effort guess, flagged
inline. There is no separate requirement id for MariaDB as an alternative
to MySQL in what was confirmed, so the application's MariaDB 10.4+ support
is not separately declared (rather than guessing an id).

## No MD5 for the release archive

`<archive>`'s `md5` attribute is documented as optional
(`[md5="{MD5}"]`). The release protocol publishes a SHA-256 checksum for
`phpledger-1.2.1.zip`, not an MD5, so the attribute is omitted rather than
computed ad hoc for this package alone (that would create a checksum
nobody else in the release process tracks or verifies).

## Images

Same four verified, publicly reachable assets referenced by the container
catalogue manifests are the intended source for this package's `icon.png`
(175x175, per Installatron's documented size), `icon64.png` (64x64),
`logo.png` (up to 400x150) and `button.png` (88x31) - none of which this
package includes as binary files, since none of the four source assets is
already sized to Installatron's exact required dimensions and inventing a
resized copy here would misrepresent what actually exists. This is left as
a TODO rather than a fabricated resize: whoever registers this package
through the Installer Editor should crop/resize from
`icon-512.png` (icon.png/icon64.png), `owner-overview-preview.webp` or
`cash-pos-click-preview.png` (logo.png/button.png/screenshots) at that
time.

## What is confirmed vs. not

Confirmed directly from the fetched, no-login developer docs: the package
directory layout and file names; the installer-level `<info id="..."
value="..."/>` lines; the version-level `<information>`, `<changelog>`,
`<links>`, `<requirements>` (partially - see above), `<archives>`,
`<skeleton>` verbatim tag shapes; the `<fields>` internal-field-id list;
the PHP5/PHP7 install-code split.

Not confirmed, and not guessed further than what is marked inline above:
the database-credential/site-path access mechanism and success/failure
signalling inside `<install>`/`<upgrade>`; the installer-level `init.xml`
root element name; `locale_en.php`'s structure; the top-level "Versions"
section's exact tag/attribute schema (the "Branches containing version
entries with upgrade/install attributes" line in the docs, with no
worked example); whether registering through the hosted Installer Editor
is mandatory rather than optional for a file-based submission; and the
full requirement-id vocabulary beyond the one worked example.


## 1.3.0 candidate metadata

Active unpinned template versions now target 1.3.0. Earlier 1.2.1 measurements and checks above remain historical. Recheck the exact final artifact and provider contract before submission; this metadata update does not establish provider acceptance or publication. The new phpledger/1.3.0/init.xml is a candidate; older version directories remain unchanged.
