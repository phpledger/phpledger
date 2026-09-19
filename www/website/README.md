# PHP Ledger marketing website

## Roadmap page update — 19 September 2026 (night)

`/roadmap/` now lists the owner's release order of 19 September 2026: the 1.1.1 patch, 1.2 (stock locations, trading documents, on-account receipts, plugin runtime, Users capabilities, Urdu, container image), 1.3 (Arabic, table prefix, installation notice, cloud databases, remaining channels), the supervised pilot from 1 October 2026 and the later capability releases. `/product/` notes that stock locations are the first 1.2 module. No release, download or demo fact changed.

Published as `website-1-1-0-roadmap-20260919-185525` from branch `docs/roadmap-surfaces` commit `6e7497b` through `.cache/publish-website-roadmap-20260919.py`, a copy of the 1.1.0 wrapper pointed at `.claude/worktrees/roadmap-surfaces` with the prefix `website-1-1-0-roadmap-`. The wrapper also asserts the live `/roadmap/` page carries the new 1.2 heading. Receipt: [roadmap-2026-09-19-publication.json](../../docs/design/website/qa/roadmap-2026-09-19-publication.json). To roll back, recreate that worktree at the same commit before running `--rollback`.

## Homepage SEOScore update — 19 September 2026

The homepage now aligns its title and H1, includes a product fact list, question-led workflow answers, a clearly fictional worked report example, a public-demo versus self-hosted comparison and numbered start steps. Release-pinned evidence links sit beside product claims, and the seven FAQ answers match their generated schema. The shared footer names the contact destination; the header fits 320 px. No application capability or release version changed.

Optional page front matter `datePublished` preserves a known original publication date independently of `lastmod`. The builder validates it and omits unknown WebPage publication dates instead of inventing them from edits; dated release articles retain their article publication dates. Use UTC dates for machine metadata. A next-day local date can be in the future for an external scanner.

This patch uses the source manifest and existing guarded publication lane in `.cache/seo-20260919/` and `.cache/publish-seo-20260919.py`. The helper freezes source and generated hashes and checks the live baseline before switching only the static root. It is a release-specific local dependency, not a generic updater. The original redesign wrapper remains pinned to its old artifact. See [SEOScore evidence](../../docs/design/website/qa/seoscore-2026-09-19.json) and [QA](design-qa.md) for measured results and remaining heuristic warnings.

## Live product-site redesign — 19 September 2026

The new product site from `master` commit `86a1eef35fd497c7f5f708a47af227657fd4a2ee` (rebuild `ac8fca3`) is live as `website-1-0-0-20260918-210112`, published at 21:02:58 UTC on 18 September / 02:02:58 PKT on 19 September. Its 295 static files match the reviewed build, with 70 HTML outputs, the new homepage, Pricing and Community. Both `/support` and `/support/` redirect to `/pricing/`. The demo proxy and containers were preserved. See the [publication receipt](../../docs/design/website/qa/live-1.0.0-product-publication.json).

**Choose the source by commit and design, not just its version label.** The initial `record-demo-cutover` branch had an older website also labelled 1.0.0. Claude merged the product source and deployment records into `master` at `75f85f7`; the main checkout now contains the correct design. The isolated `.cache/website-redesign-live` checkout of `86a1eef` remains a historical deployment snapshot. Keep it unchanged and use a freshly reviewed source manifest for later website patches. Check [AGENTS_SYNC.MD](../../AGENTS_SYNC.MD), [AGENT_MESSAGES.MD](../../AGENT_MESSAGES.MD) and the [operator runbook](../../docs/DEMO.md#operator-deployment-runbook-for-claude-and-codex) before another deployment.

On the configured Windows operator checkout, `python tools/hosting-status.py` reads live state using the saved Credential Manager identity and pinned host key. The reviewed `.cache/publish-product-website-1.0.0.py` wrapper supplies the selected source and the support redirects to the existing backup/verify/rollback lane. Its `--prepare`, `--publish <prepared-release-id>` and `--rollback <prepared-release-id>` commands use the existing credential without asking for or printing passwords. These local helpers are ignored operational dependencies, not part of a fresh clone. Future releases need a newly reviewed source pin and baseline; retain the assertions.

The earlier sections below record historical local refreshes and do not identify the current deployment source.


The deployable static document root is `www/website/public`. It is separate from the accounting application and never loads its bootstrap or serves the repository root.

## 1.0.0 product site rebuild — 18 September 2026

The site was rebuilt around the 1.0.0 stable release as a product-led, multi-page site. Status: built and checked locally, not published; the owner publishes, and the Nginx change below ships with it.

Navigation is Product, Point of sale, Pricing & licence, Community, Learn and Download, plus a "Try the live demo" button and a "Download 1.0.0" link. `/pricing/` (AGPL and commercial licence, services without prices, the enquiry form at `#enquiry`, which prepares a message for a new GitHub discussion) and `/community/` are new. `/support/` is retired: `src/static/support/index.html` is a `noindex` meta-refresh stub to `/pricing/#enquiry`, and `docker/website.conf` returns 301 for `/support` and `/support/`. Old `#enquiry` and `#enquiry-pilot` links resolve on `/pricing/`; the old home fragments `#features`, `#point-of-sale`, `#roadmap`, `#enterprise`, `#contribute`, `#docs`, `#faq` and `#pilot` still land on the matching home sections.

The design direction was explored in throwaway prototypes outside the repository; everything is implemented natively in this generator. Tokens live in `src/css/00-tokens.css` (navy `#0C2052`, blue `#4656E8`, canvas `#F7F5F0`, ink `#2A2F3A`, tint `#EEF0FF`; Manrope headings, Poppins body, Inter figures; 44 px touch targets). Components: sticky header with collapsing navigation, hero with desktop and phone captures, feature tour, navy demo band, start cards, community strip, maintainer note, FAQ accordions, pricing columns, release card, numbered steps, roadmap timeline and a six-column footer with community icons. The 47 long-form pages are restyled by the same stylesheets without markup changes, except that the FAQ blocks on about, news and roadmap now use the shared accordion markup.

Front matter gained `toc` (`false` suppresses the generated contents list, used on the six marketing pages). Contents lists are inserted after a `summary` or `callout` block when a page has one. Captures are the 66 WebP files under `public/assets/screens/v1.0.0/`, copied from `docs/design/website-1.0/web` (source manifest `docs/design/website-1.0/screens-manifest.json`). Social preview cards are generated by `tools/og-cards.py` (Pillow) into `src/static/assets/og/`. Copy rule: screens show "a fictional sample business, Willow Corner Shop" and pages say "sample data" or "sample records"; the word "synthetic" is not used on the site, and `check.mjs` requires the label on every page that shows captures.

SEO baseline: every title is 60 characters or fewer and every description 50–160; one H1 per page sharing terms with the title; FAQPage data on the main pages and HowTo on the download page; home `lastmod` equals the release date so WebPage `dateModified` is never earlier than the SoftwareApplication `datePublished`; a first-person maintainer note on the home page; the IndexNow key file is served. After publication, run seoscore.tools against the live pages (target 95 or higher) and fix what it reports.

## Current local website refresh: 0.6.0-preview

The 18 September 2026 refresh updates the homepage, product and POS walkthroughs, download illustration, three reporting guides, news index and a new `/news/0-6-0-preview/` article. Shared capability/limitation metadata and social previews describe the rebuilt workspace. This website refresh is local and has not been published.

Current screenshots live under `public/assets/screens/v0.6.0-preview/`. Its `manifest.json` records routes, sample identity, captions, alt text, dimensions and SHA-256 hashes for 18 actual application captures and their responsive WebP derivatives. They were captured from the local 0.6.0 runtime with fresh Willow Corner Shop and Cedar Studio samples; image pixels were not rewritten. The POS sequence uses a local synthetic USD 5.75 sale. The three Cedar Studio walkthroughs retain their worked figures with new interface captures. Historical release pages and their original screenshots remain intact.

Desktop/tablet are the application design targets. Mobile refinement, dark mode, full visual/accessibility acceptance, field-error consistency and nested report/source/action return behavior remain unfinished. Website layout checks do not close those application acceptance gates. No application release archive, version, migration or published media kit changes as part of this refresh. See `design-qa.md` for local checks and publication boundaries.

## Historical accounting starter 0.4.0 publication

The 0.4.0 website presented core AR/AP, optional Purchasing/Inventory, manual inclusive/exclusive tax, and five demo choices (one focused playground plus four existing multi-year histories). Its release article remains at `/news/0-4-0-preview/`. The approved website design and long-form library are preserved; dated release articles retain their original scope and screenshots.

Published as `website-redesign-20260915-233156` at **04:32 PKT on 16 September 2026** (23:32 UTC on 15 September). All 165 static files matched archive, host and public hashes. Demo identity, entry/health, noindex and existing headers were preserved. See [the static publication receipt](../../docs/design/website/qa/release040-live-publication.json). The separate application and media archives are published on [GitHub](https://github.com/phpledger/phpledger/releases/tag/v0.4.0-preview), with verified public downloads. The site builds 64 source pages into 65 HTML outputs and 63 sitemap URLs.

The [marketing handoff](../../docs/design/website/ACCOUNTING-STARTER-LAUNCH-KIT.md) contains final copy, evidence, actual synthetic screenshots and a downloadable media ZIP. No email or social post is sent by the static site or this publication.

## Source and build

The completed website-redesign work uses a dependency-free Node.js 18+ build:

```sh
node www/website/build.mjs --check
```

Edit `src/pages/*.html` (JSON front matter followed by page content), `src/partials/*.html`, `src/css/*.css`, `src/js/site.js` and `src/site.json`; then rebuild. The generator writes public HTML, versioned CSS/JS links, sitemap and the release RSS feed. `src/static` supplies the manifest, credits redirect and robots policy. Release facts and page metadata generate `llms.txt`, `/.well-known/ai.txt`, `/ai/summary.json` and `/ai/faq.json`; the former static discovery copies were removed. Valid `lastmod` is required on every page. WebPage/Person/breadcrumb data is emitted consistently, while explicit HowTo/ItemList metadata is validated. Generated contents use stable, collision-safe heading anchors. Commit source and generated output together. Do not hand-edit generated pages.

The public output needs no Node.js, PHP, database, analytics or third-party page assets. GitHub DNS/preconnect hints can establish a connection before you follow a download link. It uses locally served Manrope, Poppins and Inter fonts, the supplied brand, licensed photographs and actual synthetic application captures. Existing originals remain under `docs/design`. See [asset provenance](../../docs/design/website/SOURCES.md).

`tools/prepare-images.py` is the existing optional Pillow-based asset preparation tool; the prepared derivatives are already present. It is not part of normal page builds. The image manifests record source paths, dimensions, bytes and hashes.

## Local preview and routing

The existing website Compose service serves `public` at [http://127.0.0.1:18201/](http://127.0.0.1:18201/). A plain static server also works for directory pages, although Nginx is required to validate the configured HTTP redirects and custom 404 status.

| Path | Content |
|---|---|
| `/` | Product introduction, real screen captures, reports and start paths |
| `/product/` | Workflow-by-workflow tour of 1.0 with 23 captures, install steps, in/not-yet lists and FAQ |
| `/point-of-sale/` | Cash counter steps, desktop and phone captures, today/not-yet lists and FAQ |
| `/download/` | 1.0.0 release card with checksum, requirements, six browser-install steps, updates and recovery, FAQ |
| `/pricing/` | Licence (AGPL, commercial), services without prices, enquiry form (`#enquiry`) that prepares a GitHub discussion, licensing FAQ |
| `/community/` | Discussions, issues, feed, review and pilot invitations, contribution links |
| `/about/` | Project, maintainer and current scope |
| `/privacy/` | Sessions, country hints, sample reset and operational logs |
| `/terms/` | Synthetic demo use and preview/license boundaries |
| `/support/`, `/support` | Nginx 301 to `/pricing/`; the static stub meta-refreshes to `/pricing/#enquiry` |
| `/roadmap/` | Timeline from the 1.0.0 stable release to independently reviewed books, FAQ and evaluation table |
| `/news/` | Release index and RSS link |
| `/news/1-0-0/` | First stable release article |
| `/news/0-6-0-preview/` | Interface release, workflow changes and acceptance limits |
| `/news/0-4-0-preview/` | Historical starter release and fictional experiments |
| `/news/0-1-0-preview/` | Historical versioned release announcement |
| `/learn/`, `/guides/`, `/compare/`, `/for/`, `/self-hosting/`, `/research/`, `/glossary/` | Long-form library hubs; see the content register |
| `/credits/` | Photography, fonts, icons and company-mark provenance |
| `/404.html` | Internal custom error page used for missing URLs |

Nginx redirects `/credits.html` to `/credits/` and explicit `/index.html` to `/`. The latter tests the original request URI so the server's internal directory-index handling cannot loop. HTML has short caching; CSS/JS URLs use content hashes. Clear the local browser cache or disable it in QA after rebuilding.

Homepage fragments `#features`, `#point-of-sale`, `#roadmap`, `#enterprise`, `#contribute`, `#docs`, `#faq` and `#pilot` land on the matching sections of the rebuilt home page. The enquiry form lives on `/pricing/#enquiry`; `/support/` redirects there.

`/demo/` remains an external deployment routing contract for the separately maintained isolated PHP demo. The local static service does not proxy it. Never point it at legacy code. The redesign QA does not claim to revalidate or deploy the public demo.

## Interaction and privacy

The mobile menu supports Escape and retains usable navigation with JavaScript disabled. Screen captures open in a native keyboard-accessible dialog; Escape returns focus to the opener. Reduced-motion preferences disable smooth scrolling.

The enquiry form validates required entries, preserves correction values, copies an exact reviewable message and opens the GitHub "new discussion" chooser in a new tab; the script still supports a `data-mail-to` form if a project mailbox is ever added. It stores no visitor data and sends no message. Clipboard failure exposes a selected text preview. JavaScript-disabled visitors receive a direct link to Discussions and no active form. The site publishes no email address and no personal LinkedIn profile (owner instruction, 18 September 2026); `check.mjs` fails on `mailto:`, the old address or a LinkedIn profile URL.

## Validation and publication

Run the build checker before publication. It fails on missing navigation destinations, invalid local links/anchors, malformed metadata, missing assets, unsafe inline/external page resources, JavaScript syntax errors and page-budget overruns.

Reproducible local browser checks are in `tools/browser-smoke.cjs` and `tools/browser-interactions.cjs`, run with Playwright CLI's `run-code --filename` against the local website service. They are QA tools, excluded from the public document root. Read [the current QA record](design-qa.md) for commands, results and limitations.

The content guard is `node www/website/tools/aeo-geo-audit.mjs`. It reports page word counts, heading depth, figure/caption parity and brand mentions; it is a lightweight editorial regression check, not a search-ranking guarantee. The content inventory is [CONTENT-REGISTER.md](../../docs/design/website/CONTENT-REGISTER.md).

The approved **A, Workbench, with B's photo treatment** redesign was published to **https://phpledger.com/** on **15 September 2026 at 08:08 UTC (13:08 PKT)** as `website-redesign-20260915-080700`. The homepage uses the exact approved headline: “Double-entry accounting that runs on your own PHP and MySQL hosting”. At that publication, download copy used the independently verified 0.1.2-preview package; existing 0.1.0 screenshots and news remain explicitly historical.

Publication used the existing static-release hosting lane, with a private backup of the prior website/configuration and an atomic Nginx configuration switch. All 101 public files were verified; canonical redirects, custom 404/private-path rejection, security/cache headers and demo entry/health passed. The demo gained its planned `X-Robots-Tag: noindex, nofollow` header; its containers, proxy target, application and database were preserved. No Git push, application deployment or migration was needed. See [current publication QA](design-qa.md#live-publication-15-september-2026) and the [machine-readable receipt](../../docs/design/website/qa/live-20260915-publication.json).

Future website releases still require explicit publication authorization. The current source tree contains unrelated local accounting work: deploy only the reviewed static document root. Google/Bing verification/submissions, IndexNow, social handles and the wider campaign remain separate pending work. Shared social-preview artwork is present; additional per-page variants remain an editorial follow-up.

The 0.1.5 work adds six product FAQs and permits large image previews on indexable pages. The local Nginx example emits host-only HSTS on HTTPS and denies unused browser permissions; live configuration has a separate verified publication receipt. See [staged delivery](../../docs/repository/sprint-05/DELIVERY-0.1.5-0.2.1.md).

## Content library and publication — 16 September 2026

The owner authorized takeover, review and publication of the other website thread. The earlier completed collection had 63 source pages, 62 sitemap URLs and 64 HTML outputs including the redirect. It adds the glossary, ten lessons, six comparisons, six hosting guides, four research articles, audience pages and five additional walkthroughs. The [content register](../../docs/design/website/CONTENT-REGISTER.md) records page-level review and remaining professional gates.

Run `node www/website/build.mjs --check` and `node www/website/tools/aeo-geo-audit.mjs`. The latter reports main-content metrics, excluding shared footer/navigation. Browser QA must use a fresh context or cache-busting URL because local HTML uses short caching; verify content-hashed CSS URLs after rebuilding. Check desktop, tablet and mobile layouts, including grid children, long tables and code blocks.

Publish only the reviewed static archive through the existing immutable-release lane, with private prior-site/configuration backup, unchanged demo proxy and OAuth locations, Nginx validation, public byte/hash checks and rollback. A new manifest MIME location belongs in the HTTPS static server; HTTP remains a canonical redirect. Preserve ACME handling and demo noindex. After an explicitly authorized publication, IndexNow submission may be performed separately using the key file listed in robots.txt; no submission client, account creation or campaign is part of this release.
