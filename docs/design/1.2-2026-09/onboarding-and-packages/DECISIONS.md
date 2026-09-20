# Onboarding + Packages — open UI/UX questions

Frames: `onboarding-1-start.html` … `onboarding-5-ready.html`, `packages-installed.html`,
`packages-directory.html`, `packages-upload-confirm.html`, `index.html`. Source decisions:
`docs/strategy/DECISION-REGISTER.md` section L (B33–B43) and section M (B44–B52), 21 September 2026.

Per the owner's rule recorded as **B53**: every question below states the option **TAKEN**
(the one the frames are built on), the alternatives considered, and how to reverse it — a
setting, a migration-free switch, or the bounded rework needed. The few genuinely hard-to-reverse
choices are marked **Ask the owner** instead of guessing a reversal path.

---

## Onboarding wizard

### 1. How many stages, and do they have to be six to "match" the installer?

The Workbench installer (`docs/design/installer-2026-09/direction-b-workbench/`) has six tray
compartments: Start, Database, Build, Checks, Account, Ready. B50 says onboarding should "match
the Workbench installer," but onboarding has no database step, no schema build and no server
checks — those are the installer's job, already done. A six-slot tray with two slots doing
nothing onboarding-specific would copy the installer's *shape* without its *content*.

- **Option A — five stages, one per requested frame** (Start, Business, Source, Review, Ready).
- Option B — six stages, padding in an extra step (e.g. splitting Business into two, or adding
  a standalone "Plugins" stage before Review) to match the installer's slot count exactly.
- Option C — keep the current six *linear* steps from `onboarding.php`, only restyled with the
  tray widget, without consolidating Business+Period into one stage.

**TAKEN: Option A.** Five stages named after what onboarding actually decides, styled with the
same tray/tile visual language (rounded slots, done/current/pending states, checkmarks) as the
installer, rather than forcing a sixth. `onboarding-1-start.html` through `-5-ready.html` show
this tray at the top of every frame.

**Reversal path:** cosmetic. The tray is a `<div class="bench-tray">` of `.tray-slot` elements
with a label each; adding, removing or renaming a slot is a template change with no data model
behind it (unlike the installer's tray, which reflects real completed work). Low cost either way.

### 2. Does "Explore a sample company" reuse the same Source screen as "New business"?

Screen 1 offers three top-level starts (unchanged from today's `start_mode`): New business,
Bring past records, Explore a sample company. Screen 3 (Source) as drawn offers blank / skeleton
/ full sample, which is really about the "New business" and "Explore a sample" paths converging
on one question — how much of a sample to bring in.

- **Option A — one shared Source screen**, with "Blank business" simply disabled/hidden when the
  Start choice was "Explore a sample company" (since exploring implies *some* sample).
- Option B — two different screens: a narrower "which sample" picker for Explore, and a
  three-way blank/skeleton/full picker only for New business.
- Option C — fold the skeleton/full choice into screen 1 itself, dropping a separate Source
  stage (contradicts the five-stage layout in question 1).

**TAKEN: Option A**, as drawn in `onboarding-3-source.html`. One screen, one mental model buys
consistency at a small cost: "Blank business" needs a disabled state text explaining why it is
unavailable when arriving from "Explore a sample company," which the current frame does not
render (it always shows "Blank business" selected, since it is drawn as if arrived at from
"New business").

**Reversal path:** template branch on the incoming `start_mode`; no schema or backend change,
since `pl_setup_company()`'s `chart_choice`/`sample_pack` inputs already carry enough information
to hide a card client-side. Cheap to change either way.

### 3. What exactly does "skeleton" include, and do customer/supplier master records count?

B50's own wording: "chart of accounts, reports, forms and plugins" — it does not mention
customer, supplier or product master records, which real sample packs also carry (e.g. Harbour
Trade's customers and suppliers in `resources/demo-packs/trader-1.0.0.json`). A skeleton that
drops customer/supplier records is cheaper to define (it is exactly "structure minus the
`events`/`drafts` arrays" in the pack format) but less useful as a real business starting point;
one that keeps them is more useful but blurs "no transactions" — a customer record with a running
balance is itself derived from transactions, so an empty-balance customer list is needed either
way.

- **Option A — literal B50 wording only**: chart of accounts, report configuration, form/document
  layouts, and the plugins the sample depends on. No customers, suppliers or products.
- **Option B — structure plus zero-balance masters**: adds customer, supplier and product records
  from the sample, all with no opening balance and no linked history.
- Option C — let the user pick which master-record categories to bring in, as extra checkboxes
  on the Source screen.

**TAKEN: Option B**, shown in `onboarding-4-review.html`'s "What the skeleton brings in" panel
(it lists "Customer & supplier records" alongside the chart, reports, forms and plugins). It
reads as a genuinely useful starting business, not a bare chart; the boundary stays simple
("nothing with a balance or a date moves over") rather than needing a second per-category picker.

**Reversal path:** this is a data-contract question, not a display one — it decides what
`pl_seed_demo_pack()`'s future skeleton-only branch actually copies. Reversing from B to A after
the importer is built would mean dropping fields from an already-shipped skeleton snapshot format,
which is a compatibility change, not a switch. **Ask the owner** to confirm before the importer's
data contract is designed, since this is the one item in this section that is expensive to reverse
once real skeleton snapshots have been created and possibly relied on by real businesses.

### 4. Is the "country package" field real, or does it overstate 1.2's scope?

`onboarding-2-business.html` shows a Country / Industry / Groups selector sourced from
`docs/coa/regional-program/CATALOG-AND-INSTALLATION-CONTRACT.md`, whose own header says "proposed
design for owner, UX and accounting review... No application, migration, catalogue release... is
implemented by this document." Nothing in decisions B44–B52 authorizes building it for 1.2.

- **Option A — include it, clearly labelled "Proposed — not yet built."**
- Option B — omit it entirely; keep onboarding's Business stage to name, currency, start date and
  financial year only, matching what `pl_setup_company()` actually accepts today.
- Option C — include it as if shipped, to make the mockup look more complete.

**TAKEN: Option A.** The field is shown with a visible "Proposed — not yet built" badge and a
comment block naming its source document, so a reviewer sees the idea without mistaking it for
committed 1.2 scope. Option C was rejected outright — it would misrepresent the release; the
Awan-prototype clarification (B54) reinforces this: adopt useful shells and fields, but never in
a way that overstates what the accounting core actually does.

**Reversal path:** delete the `.pkg-tip` block and its two-column layout collapses to the left
column only (business identity + financial year); no other frame references it. Trivial either
way, since it is presentation-only and not wired to any confirmed data contract yet.

### 5. Do skeleton/full-sample plugins install and activate automatically?

The skeleton for Harbour Trade needs Inventory and Purchasing active to make sense of its chart.
B44 says an `installation.admin` *may* install and activate plugins — it does not say setup does
this *for* them.

- **Option A — automatic**, since a skeleton without its dependency plugins would show routes and
  reports that go nowhere (a "Purchasing" report referencing a module that is not installed).
- Option B — install but leave inactive, requiring an explicit activation step after onboarding.
- Option C — list the required plugins on the Review stage and ask for one explicit confirmation
  covering all of them, rather than silently doing it.

**TAKEN: Option A**, shown in `onboarding-4-review.html`'s "Plugins this source needs" panel,
which states they install and activate automatically because the skeleton depends on them, and
that they can be deactivated afterward from Packages. This keeps the skeleton usable immediately
rather than half-wired.

**Reversal path:** a single boolean at the point the skeleton importer runs (auto-activate vs.
install-only); changing it later does not touch existing businesses, only future onboarding runs.
Easy to reverse.

### 6. Financial-year defaults on the Business stage

Today `setup_functions.php` defaults `fiscal_year_end` to `06-30` for `country_code === 'PK'` and
`12-31` otherwise, with a free-text `fiscal_year_end_choice`/`_custom` pair. The mockup keeps a
select control (30 June / 31 December / Custom…) rather than the current two-field
choice+custom-text combination, to fit the denser two-column Business stage layout.

- **Option A — single select with an inline "Custom…" option** (as drawn).
- Option B — keep today's exact two-control pattern (dropdown + conditional text input) unchanged.

**TAKEN: Option A**, `onboarding-2-business.html`. Slightly denser, same information.

**Reversal path:** trivial template swap; the underlying `fiscal_year_end` value and its
regional-default logic in `setup_functions.php` do not change either way.

---

## Packages screen

### 7. Card density — how much detail per card in a 1280×720 frame?

B52 lists eight metadata fields per card (name, description, version, author, licence, what it
adds, requirements, screenshots) plus Verified/Unverified and Installed/Active. Shown in full for
every card, three across, a row would need roughly 260px of height, leaving room for only 2–3 rows
before scrolling — acceptable, but dense.

- **Option A — full card, every field, as drawn**, with a shorter card style for the three
  required/always-on modules (Accounting core, AR, AP) that collapses "what it adds" to one line
  since they have no Update/auto-update controls to show.
- Option B — two-tier: a compact card (name, version, badges, one-line description) that expands
  to the full field set on click.
- Option C — a dense table view (one row per package, columns for each field) as an alternative
  to cards, toggled by the user.

**TAKEN: Option A**, `packages-installed.html`. Required modules get the shorter "Required —
always active" row; optional installed and directory packages get the full card. This favours
scanability for the common case (a handful of packages) over supporting hundreds of directory
entries without pagination, which 1.2 does not need yet.

**Reversal path:** Option B is a pure client-side interaction change (a `<details>`-style
expand/collapse) layered onto the same markup — no data change. Option C would need a second
template but the same underlying package list. Easy to add either later without touching Option A.

### 8. Where does the auto-update switch live?

B51: "Admin > Packages offers Update now per plugin and an auto-update switch." Two placements are
plausible: per-card (one switch per package) or a single global switch with per-package overrides.

- **Option A — per-card switch**, as drawn on the Inventory and Purchasing cards in
  `packages-installed.html`, disabled (greyed) on inactive/Unverified packages with an explanatory
  label.
- Option B — one global "Auto-update Verified packages" switch in the page header, with no
  per-card control.

**TAKEN: Option A.** B51's wording ("per plugin") reads as per-package control, and a single
global switch could not express "Verified packages update automatically, Unverified only by
click" (B51's own split) without a second global switch anyway — so per-card is both more literal
and no more complex.

**Reversal path:** replacing N per-card switches with one global switch is a straightforward
template simplification with no data-model change (`pl_plugin_options`, per B46, can hold either
a single flag or per-package flags equally well). Easy to reverse.

### 9. Dialog or full page for the Unverified upload confirmation?

The Awan prototype's own pattern for a comparable "are you sure" moment (`Close year…`,
`Reset password`) is a `<dialog class="dialog">` modal. The task specifically asked for this as
its own file (`packages-upload-confirm.html`), implying a distinct, linkable page.

- **Option A — full page**, as built, with room for the manifest table, three separate
  acknowledgement checks and a details-first layout that a modal's fixed size would cramp.
- Option B — a `dialog.sheet` modal matching the prototype's own `company-sheet`/`fy-close`
  pattern exactly, for shell consistency.

**TAKEN: Option A**, with the difference called out in the frame's own header comment. Reasoning:
installing unverified code that can read/write company data (B45's hooks/filters) is a heavier
decision than closing a financial year or resetting a password; a page that can be reloaded,
bookmarked mid-review or linked to in a support conversation resists a "click through fast" pattern
that a modal invites more than a page does.

**Reversal path:** the confirmation's content (manifest panel, three checks, actions) already
maps directly onto the prototype's `dialog-body`/`dialog-footer` structure; wrapping it in a
`<dialog class="dialog sheet">` instead of a full page is a container swap, not a content rewrite.
Easy to reverse.

### 10. What does an Admin see versus what an ordinary owner sees?

B44: "an installation Admin may install and activate plugins" via an `installation.admin` role,
distinct from a business's `owner`/`accountant`/`viewer` roles (the actual roles in
`resources/modules/*.json`, not the Awan prototype's own Owner/Manager/Cashier/Store vocabulary —
see the Awan section below). The frames as drawn show the full Admin view throughout.

- **Option A — Packages is Admin-only**; a business owner without `installation.admin` never sees
  the nav item or screen at all.
- Option B — a business owner can view Packages read-only (see what is installed/active for their
  business) but cannot install, activate or upload; only Install/Activate/Upload controls are
  gated.
- Option C — no distinction shown in these frames; document it as a follow-up design pass.

**TAKEN: Option B.** An owner reasonably wants to see what a plugin does to their business (its
"what it adds," badges, active state) without being able to change it — read access is safer to
grant broadly than write access, and matches how the rest of PHP Ledger already separates "can
see" from "can post/change" by role. These frames do not yet draw the read-only variant (all three
Packages frames show the full Admin view with every control enabled); that is the one visible gap
against Option B.

**Reversal path:** Option A (hide entirely) is a stricter subset of Option B (hide the controls,
keep the view) — moving from B to A only removes the nav item's visibility for non-Admin sessions,
no data change. Moving from A to B is equally cheap. **Follow-up, not a blocker**: a fourth frame
(`packages-installed-readonly.html` or similar) showing the owner's view with Install/Activate/
Update/Upload controls removed would close this gap; it was not in the requested file list for
this task.

### 11. What may a public-demo visitor install? (B49)

B49 makes Packages live in the public demo and says plugins run there, and separately notes "a
demo policy for what visitors may install is needed." These frames do not draw a demo-specific
variant.

- **Option A — demo visitors get the same Packages screen, scoped to the demo's existing hourly
  reset** (anything installed vanishes on reset, same as any other demo data).
- Option B — demo visitors see Packages read-only (can browse the Directory, cannot install or
  upload).
- Option C — demo visitors cannot open Packages at all.

**Not taken here** — this is flagged, not decided, in `packages-directory.html`'s side panel
("see DECISIONS.md for the open question on what visitors may install"). **Ask the owner**: this
depends on the demo's isolation and restricted-grant mechanics (per B49's own wording), which is
outside a UI mockup's authority to settle, and the wrong choice is expensive to reverse after the
demo has been running with uploaded plugin code from anonymous visitors.

---

## Taken from the Awan prototype

Per the owner's note ("use this where needed") and the follow-up clarification (B54: the
prototype is a design aid, not a final authority — adopt its shell and field choices where useful,
but follow `AGENTS.md`'s accounting/access rules where they conflict).

**Read:** `docs/design/1.2-2026-09/awan-prototype/Awan-prototype.html`, specifically the `Home`,
`Users & roles`, `Companies (brands)`, `Financial year`, `Backup & restore`, `Help`, `All screens`
templates and the shared shell markup (`<nav class="shell-nav">`, `<header class="shell-topbar">`)
near the top of the file.

**Adopted, in `packages-installed.html`, `packages-directory.html`, `packages-upload-confirm.html`:**

- The app shell shape: fixed-width left sidebar with grouped nav items (`nav-group-label`,
  `nav-item`, `aria-current="page"`), a topbar with breadcrumbs and a search trigger, and a
  scrollable main content area under a sticky `page-header`/`page-title`/`page-header-actions`.
  Packages now reads as a sibling of the prototype's own Users & roles / Financial year / Backup &
  restore screens (all under its "Setup" nav group), which is exactly where B44/B52 place it.
- The `tabs-seg` segmented-control pattern (`role="tablist"`, `.tabs-seg-item[aria-selected]`) for
  the Installed / Directory / Upload tabs, instead of inventing a new tab component.
- The badge vocabulary and its actual colour bindings: `badge-info` (blue, used for "Verified"),
  `badge-posted` (green, used for "Active"), `badge-unpaid` (neutral grey, used for "Not
  installed"/"Inactive"), `badge-sample` (indigo). A `badge-warn` (amber) was added for
  "Unverified," since the prototype has no existing amber badge class to reuse.
  `packages-directory.html` and `packages-upload-confirm.html` use this set.
  Their `alert`/`alert-warning`/`alert-title` component was reused for the unverified-code warnings
  on both those pages, matching how the prototype flags its own "Proposed" and risk-carrying items.
- The `role="tablist"`/`data-proposed`-style discipline of never presenting a not-yet-built idea as
  if shipped: every proposed or fictional element in these frames (the country-package selector,
  the Pharmacy POS plugin, the Pakistan pharmacy sample) carries an explicit "Proposed"/"Unverified,
  not shipped" label, the same instinct the prototype applies with its `data-proposed` attribute and
  "Proposed" badges throughout (e.g. its Financial year and Users & roles screens).

**Considered and set aside, with reasons:**

- The prototype's own `option-card`/`option-card-body`/`-title`/`-desc` component (used for its
  "New user" role picker) is functionally identical to the `.choice-card` component already built
  for the onboarding frames from the Workbench installer's own visual language. Onboarding was
  drawn first from the installer prototype per the task's explicit instruction to match that
  installer; renaming `.choice-card` to `.option-card` across five already-built onboarding files
  for a cosmetic class-name match was not judged worth the churn. Both are radio-button cards with
  a title, a description and a selected state — a future implementation can standardise on either
  name freely.
- **Colour tokens: the prototype's own `--border-strong` (`#d6d3cc`) and `--ink-faint` (`#9a968e`)
  are lighter than the real values in `www/phpledger/public/assets/app.css`
  (`#8c8880` and `#706d66`).** Per the task's instruction to "keep the real app tokens," every frame
  in this folder uses the `app.css` values, not the prototype's softer variant. This is the one
  place the prototype's own numbers were read and deliberately not copied.
- **Role vocabulary.** The prototype's Users & roles screen invents Owner/Manager/Sales
  entry/Cashier/Store roles specific to a distribution business, and marks its entire permission
  model "Proposed" (its own annotation: "today every user can open every screen, passwords are
  stored in clear text"). PHP Ledger's real roles are `owner`/`accountant`/`viewer` per
  `resources/modules/*.json`, plus the `installation.admin` role from B44 — a real, already-decided
  model, not a proposal. Per B54, this is exactly the kind of conflict where `AGENTS.md`'s existing
  access rule ("Enforce company/book membership and action permissions on the server") and PHP
  Ledger's real role model take precedence: these frames name only `installation.admin` and never
  borrow the prototype's fictional role names or its "passwords in clear text" framing.
- The prototype's `dialog`/`dialog-header`/`dialog-body`/`dialog-footer` modal pattern was read and
  is a reasonable default for short confirmations (see question 9 above), but was deliberately not
  used for the Unverified upload confirmation — see question 9's reversal note for why a full page
  was chosen instead and how to switch back.
- The prototype is a distributor's operational system (sales/purchase vouchers, salesmen, routes,
  vans) with no onboarding, setup wizard or plugin/package concept of its own — there was no
  equivalent screen to align the onboarding-3-source.html skeleton/full-sample decision against,
  so that frame remains built entirely from the Workbench installer, `onboarding.php` and the demo
  pack functions, as originally planned.
