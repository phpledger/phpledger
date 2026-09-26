# 1.4.5 setup redesign — mockup

Static frames for the owner's review of the installer, business onboarding, first Home, shell and Packages, built on the real compiled stylesheet (`www/phpledger/public/assets/app.css`) so they look like the product. Nothing here is loaded by the application. Approval of these frames is the gate for the 1.4.5 implementation.

## Build and view

```
python docs/design/setup-1.4.5/build.py
```

Serve with any static server that maps `/assets/` to `www/phpledger/public/assets` and `/` to this folder, then open `/` for the index. Query switches: `?help=<id>` opens a help bubble (`db-host`, `start-date`, `page` …), `?env=cpanel|xampp|docker` on I-3, `?tab=installed|samples|directory|upload` on P-1.

Files: `ui.py` (layouts, help bubble, tray, shell), `frames_install.py`, `frames_onboarding.py`, `frames_app.py`, `build.py`, `mock.css` (the CSS to port into `resources/ui/ext/setup.css` and `app.css`), `mock.js` (the behaviour to port into `install.js`/`app.js`), `sprite.svg` (the shipped Tabler icons as `<symbol>`s plus eye/eye-off).

## The owner's notes and where each is answered

| Owner note (25 Sep 2026) | Frame |
|---|---|
| "?" location should be consistent; open the bubble next to it | every frame: the "?" sits beside the title and beside each label, bubble anchored to it (`help-bubble-start/end`, never `sheet`) |
| Empty space; whole page above the fold, no scrolling | I-1…I-5, O-1…O-6, H-1 sized for 1366×768 |
| Tip box "should be at top" | I-1: slim note above the form |
| TLS CA path confusing for an ordinary user | I-1: under "Advanced: a database on another server" |
| Smaller database-name field | I-1: twelve-column grid, host/port/prefix on one row |
| Eye icon on password | I-1, I-4 |
| Turn the button green when all required fields are filled | I-1, I-4, O-2 (`.btn-primary.is-ready`) |
| Progress boxes should say what is left | labelled tray + "Step n of 4" |
| Why the extra "Install database" click | I-2: merged into the build screen |
| File-not-writable deserves its own step with instructions per environment | I-3 |
| Standard password with show and generate; allow 6 characters | I-4 |
| Remove the telemetry block; send anyway | I-4: one sentence, no checkbox (amends B16) |
| Public site address: guess first | I-1 |
| Company name field not needed | removed with the registration block |
| Better logo UI | I-4, O-2 drop zone with preview |
| Required fields marked with * | all forms |
| Completion page should show installation details and the sign-in details | I-5 |
| Onboarding promises skeletons but offers one; directory link with no way to install | O-1 gallery with Install in place, P-1 Directory tab |
| Layout and spacing of onboarding | O-1…O-6 |
| Navigation loses your place when you visit Modules/Packages | drafts autosave, `return=` brings you back (behaviour, noted on O-1) |
| Business type should bring owners, equity, banks, cash | O-3 |
| Display sample companies as cards with backstory and logo | O-1 |
| Why the accounting start date | O-2 "?" on the field |
| "How much starting structure" duplicates "What are you setting up" | O-1 is the only starting question |
| Zero-balance confirmation checkbox | gone: the "starts at zero" card is the confirmation |
| Review with a chart I cannot edit | O-4 edits names; O-5 lists exactly what will be created |
| Regional suggestions not editable | O-2 every suggestion is a normal control with a chip saying where it came from |
| Zero balance, what do I do first | H-1 getting-started guide; Expense and Bill wait for money in |
| Icons need improvement | H-1 (Tabler, inlined, coloured, distinct per item; Lucide variant on request) |
| Help pinned at the bottom of the sidebar | H-1: Help in the top bar, no sidebar footer, version in the user menu |
| Packages page nothing like a plugin directory; no way to activate | P-1 tabs and per-business switches |

## Not in 1.4.5 (would be labelled "Later" in the product)

Account reclassification and role edits before creation (1.5 setup lane), country tax profiles, a phpledger.com dashboard for received notices, PostgreSQL/SQLite in the database step.

## To confirm at implementation

The Docker instruction on I-3 quotes a private-directory path; take the real `PL_INSTALL_DIRECTORY` from `compose.desktop.yaml` before shipping the wording.

## Country nomenclature for legal forms (owner note, 26 Sep 2026)

"We are just showing Pvt Ltd etc. Guide people." O-2 now asks for the country first and shows the legal forms in that country's own words, each with a one-line guide (what it is, who registers it, who the owners are, how money comes out, which numbers go on invoices), and relabels the invoice fields (SECP registration number / NTN / STRN in Pakistan, Company number / UTR / VAT in the UK, State entity number / EIN in the US, Trade licence / TRN in the UAE, CIN / PAN / GSTIN in India, UEN / GST in Singapore, Registry code / VAT (KMKR) in Estonia). Any other country gets a neutral list. The data lives in `legal_forms.py` here and would ship as `resources/locale/legal-forms.json`; every local form maps to a canonical family PHP Ledger already knows (`pl_legal_forms()`), so the share ledger, partner ratio and owner-equity behaviour need no change, and no migration is needed: the country-specific key is stored in the existing `legal_form` text column with the family resolved in code. Try `o2-business.html?country=GB` or change the country on the page.
