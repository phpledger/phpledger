# Proposed distribution channels — review, 21 September 2026

An owner proposal of 21 September 2026 listed six channels: a Homebrew tap, an npm package,
GitHub Pages, Cloudflare Pages, the awesome-selfhosted and awesome-php listings, and Softaculous.

This file reviews that proposal against the [distribution plan](DISTRIBUTION-PLAN.md), the
[release protocol](../RELEASE-PROTOCOL.md) and the code as it stands at `master` (1.2.1). It
proposes an action per channel. **Nothing here is a decision**; decisions belong in the
[decision register](DECISION-REGISTER.md) and are the owner's to record.

Five of the six channels are already in the plan with a recorded position. The review is mostly
about whether their gates are met today, not whether to pursue them.

## Summary

| Channel | Recorded position | Gate met today | Proposed action |
|---|---|---|---|
| Homebrew tap | Tier 3b step 4; needs SQLite | **No** | Defer; the blocker is a product gap, not packaging |
| npm | Tier 3b step 3, scaffolder only | **Yes** | Buildable now; sequencing is the only question |
| GitHub Pages | Not in the plan | n/a | Decline for the main site; see below |
| Cloudflare Pages | Not in the plan | n/a | Narrow use only: build previews |
| awesome-selfhosted / awesome-php | Tier 4 discovery | **No** | Ineligible until 18 January 2027; hold |
| Softaculous | Tier 1, decision B2 | **No** | Hold for the trader-complete release |

## Homebrew

**The proposal's formula cannot work as written.** It declares `bin.install_symlink libexec/"bin/phpledger"`.
There is no `bin/` directory and no command-line entry point in the application; the four scripts under
`www/phpledger/install/` are installer steps, not a launcher. `depends_on "php"` also installs nothing to run.

The deeper blocker is the database. A Homebrew formula cannot reasonably require the user to stand up
MySQL or MariaDB, which are the only engines the application supports. The distribution plan already
says this and gates the tap on SQLite support. SQLite is decision P2's third engine, sequenced after
PostgreSQL and tied to the Windows bundle, and no SQLite code exists in `www/phpledger/includes/` today.

**Nothing external blocks a tap.** A third-party tap is simply a repository named
`homebrew-<name>`, with no review and no acceptance criteria; the notability rules (roughly thirty
stars, ninety when the owner submits their own project) apply to `homebrew-core`, which is not the
plan. The blocker is entirely internal.

A tap published now would install a web application that cannot start. Defer.

## npm

The release protocol is explicit: **npm carries no application package**. `npx phpledger init` as an
installer contradicts that. The planned packages are `create-phpledger`, a scaffolder that writes a
production compose file for the published image, and `@phpledger/api-client`.

`create-phpledger` is the one channel in the proposal whose own prerequisite is met. Its gate is
"release feed live", and the feed has been live since the 1.1.0 website publication. The container
image it would scaffold is public at `ghcr.io/phpledger/phpledger` with tags `1.2.1`, `1.2`, `1` and
`latest`, and `compose.production.yaml` is on `master`.

The remaining question is sequencing, not feasibility: the plan places the scaffolder after the
DigitalOcean listing, which itself waits for the image to ship two releases. The image has shipped one.

The existing root `package.json` is the private Tailwind build manifest and must stay private; a
scaffolder belongs in its own package directory.

## GitHub Pages and Cloudflare Pages

Neither is in the distribution plan, and the main site should not move to either.

**phpledger.com is not only a marketing site.** It serves `/releases/index.json`, the release feed that
every installation polls; the URL is compiled into the application as `PL_RELEASE_FEED_URL` in
`www/phpledger/includes/functions/update_channel_functions.php`. The same origin also proxies the live
demo at `/demo/` to PHP containers. Static Pages hosting cannot proxy to a container, so moving the
origin would take the demo down, and moving the feed would strand installed copies.

The website generator does produce a fully static document root at `www/website/public`, so the static
half is portable in principle. The useful application is therefore narrow: **Cloudflare Pages build
previews for website pull requests**, which would give reviewers a real URL instead of a local build,
without touching production DNS. That is worth considering on its own merits and is a separate decision
from where the site is served.

On the free tiers, both would hold the site comfortably: Cloudflare Pages allows 500 builds a month,
unlimited preview deployments and unlimited static bandwidth, and GitHub Pages carries a soft 100 GB
monthly bandwidth limit. Capacity is not the deciding factor; the demo proxy and the feed are.

GitHub Pages adds nothing here that the existing hosting does not already do.

## Discovery listings

**awesome-selfhosted would reject a submission made today, and the proposal's instructions are out of date.**

Its data moved out of the markdown repository: entries are now a YAML file under `software/` in
[awesome-selfhosted-data](https://github.com/awesome-selfhosted/awesome-selfhosted-data), not a pull
request against the list itself. The category is **Money, Budgeting & Management**, not "Personal
Finance / Accounting".

Two checklist items decide this:

- **The first release must be more than four months old.** PHP Ledger 1.0.0 was published on
  18 September 2026, so the earliest eligible date is about **18 January 2027**.
- **The submission must be made by a human, not by a machine or a language model.** This is their
  rule, stated in the addition template. The owner files this one personally; an agent must not.

**awesome-php is a poor fit.** Its contributing rules ask for entries that are "established and
mature", its formatting examples are libraries, and it discourages self-promotion. PHP Ledger is a
full application, not a Composer component. Whether applications are in scope at all is not stated
either way. Skip it unless a genuine library is split out later.

## Softaculous

Decision B2 already put Softaculous on the roadmap, and the distribution plan phases it into Tier 1,
"when AR, AP and the FBR digital-invoicing client pass their gates".

The **custom package** format is documented and buildable whenever the owner wants it: `info.xml`,
`install.xml`, `install.php` with an `__install()` function, `install.js` with `formcheck()`,
`fileindex.php` and the zipped release, with optional `cust.sql` and `upgrade.*` files. That path
needs no approval and can be handed to an individual host directly.

The **official library** submission process could not be verified from a primary source. Softaculous
publishes developer documentation for custom packages but no public form or criteria for inclusion in
the library, so the plan's existing open question stands and the answer needs direct contact with them.

Submitting early spends the one first impression on an incomplete product. Hold both.

## Already done, for the record

Packagist is live at `phpledger/phpledger` and serving 1.2.1, and the repository's Packagist webhook
was created on 21 September 2026, so tags now publish automatically. The distribution plan still lists
Packagist as 1.3 work in the platform roadmap's sequence table; that row is out of date.
