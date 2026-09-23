# In-app accounting guidance — the help catalogue

Owner decisions **B66** (the application teaches accounting where the work happens) and **B67**
(the guidance is not Pakistan-only). This directory is the **interface copy** the help bubbles
render. The research that copy is drawn from lives in `docs/accounting/guidance/`; this is not a
second place to do research.

```
resources/guidance/
  concepts/<concept-id>.php        one shared explanation per concept
  jurisdictions/<CC>.php           local notes for one country, keyed by concept id
```

Both are plain `return [...]` PHP files with no logic, loaded on demand by
`www/phpledger/includes/functions/guidance_functions.php`. A screen with ten bubbles loads ten
small concept files and **one** jurisdiction file — the company's own. It never loads the other
twelve jurisdictions.

## A concept file

`concepts/debit-and-credit.php`:

```php
return [
    'title'       => 'Debit and credit',        // required, short
    'explanation' => '…',                       // required, 40–70 words, plain language
    'here'        => '…',                       // optional, ties it to what this screen does
    'document'    => ['label' => '…', 'href' => '/help'],   // optional, an in-app path
    'review'      => 'placeholder',             // 'placeholder' | 'reviewed'
];
```

`document` is the "read more" link under the explanation. It carries **either** an in-application
`href`, which must start with `/`, **or** an external `url`, which may only be a
`https://phpledger.com/learn/<slug>` article:

```php
    'document' => ['label' => 'How to structure a chart of accounts', 'url' => 'https://phpledger.com/learn/chart-of-accounts/'],
```

The slug must be one of the `learn-*.html` pages in `www/website/src/pages/`, which are served at
`/learn/<slug>/`. Where none of them fits, leave `document` out rather than reserving a slug for an
article that is yet to be written: a bubble with a good explanation and no link is fine, and a dead
link teaches the reader that the help is broken. Make the label the destination's own title so the
link says where it goes.

That allowlist is enforced in `pl_guidance_concept()`, not by review: a guidance file that could
name any destination would be a way to put an arbitrary link on every screen of the application.
Anything else — another host, a query string, a fragment, a port — is dropped and the bubble simply
has no link. An external article opens in its own tab with `rel="noopener noreferrer"`, so it gets
neither `window.opener` nor the reader's own installation URL. A concept that names both keeps the
in-application path.

`review` is `placeholder` until the guidance review has passed the wording; the bubble says so on
screen. Every string here is an English source string for `pl_t()` — the component translates it,
so keep it a complete sentence and do not concatenate.

## A jurisdiction file

`jurisdictions/PK.php`, keyed by the same concept ids:

```php
return [
    'unapplied-credit' => [
        'note'       => '…',                 // required
        'status'     => 'verified',          // 'verified' | 'unverified'
        'source'     => '…',                 // required when verified: the authority and paragraph
        'checked_on' => '2026-09-21',        // required when verified: YYYY-MM-DD
    ],
];
```

**Only `verified` notes with a source and a check date ever reach a screen.** The loader drops
everything else, so an unfinished or unsourced note is invisible rather than wrong — B67's rule
that an unsourced local claim is worse than none is enforced in code, not by review.

The thirteen jurisdictions are the countries behind `pl_base_currency_options()` (US, EU, GB, PK,
IN, MY, BD, LK, NP, SG) plus AE, SA and OM. `EU` is the euro area, which is not an ISO 3166
country; it is used because the application offers the euro as a base currency.

## Adding to it

See `docs/DEVELOPMENT.md`, "Adding a help concept or a jurisdiction note". New files must also be
added to `tools/package-files.json`, or they will not ship in the release archive.

## Page coverage

`pages.php` maps every application view to its contextual concept; `routes.php` lists browser page routes, including installation and account-access screens. POST actions and file downloads have no independent page header. Unknown extension views receive recovery guidance until their own coverage is registered. The guidance suite checks the view and route catalogues against the source. `help.js` provides optional dismissal; native details remain readable without JavaScript.

The 1.3 integration includes explicit coverage for payroll, employee links, recurring and financing schedules, year-end, installation updates, and the receipt/expense chooser and canonical editor routes. Payroll and scheduling guidance distinguishes review drafts and accounting entries from external payroll calculations or sending payments.
