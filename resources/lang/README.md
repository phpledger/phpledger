# Interface translation catalogues

This directory holds the interface translation catalogues read by
`www/phpledger/includes/functions/i18n_functions.php`. The loader was the groundwork delivered in
milestone M2 of [RELEASE-PLAN-1.2.md](../../docs/strategy/RELEASE-PLAN-1.2.md); M11 put the
existing interface text through `pl_t()` and added the first catalogue.

## What is here today

`ur.php` and `ar.php`, **machine-authored, unreviewed draft** Urdu and Arabic catalogues, and this file.

- **English (`en`) is the source language.** Its keys are its strings, so it has no catalogue
  file and loads none. `pl_t('Save the invoice.')` returns `Save the invoice.`
- **The pseudo-locale (`qps`) is generated, not stored.** `pl_i18n_pseudo()` brackets and
  lengthens every string it is given, so a test can see which text went through `pl_t()` and which
  is still hard-coded. It ships no catalogue and is never offered to a user.
- **Urdu (`ur`) is a draft and says so.** `pl_locale_review_state('ur')` answers `draft`, the
  language switch labels it "(draft translation)" and repeats the warning on screen, and the file
  itself is headed UNREVIEWED. It covers the vocabulary a person meets on every screen —
  navigation, buttons, column headings, statuses, form labels, months — and deliberately leaves
  the long explanatory sentences to fall back to English rather than guessing at them. Urdu also
  drives South Asian digit grouping (`1,23,45,678`) through `pl_number_format_rules()` and the
  `d F Y` date pattern with translated month names.
- **A language is not "supported" until a named person has reviewed its wording**, on real screens,
  with real figures and dates (decision B3). That review is a release gate this directory cannot
  satisfy by itself. Nothing in the application claims a reviewed translation today.
- **Arabic (`ar`) is a machine-authored draft in 1.3.** It covers common navigation, actions,
  document/accounting labels, Gregorian month names, and accounting-depth screens. It uses the
  existing six-form plural selector and RTL document direction. The explicit draft presentation
  uses Latin digits, three-digit grouping (`1,234,567.89`) and `d F Y` dates with Arabic month
  names; this is a product convention, not a claim about every Arabic-speaking jurisdiction.
  Regional tags such as `ar-SA` inherit these defaults and the base catalogue. There is no Hijri
  calendar conversion or localized financial-input parsing. Missing text stays English, and
  mixed-script values retain the existing bidirectional isolation. Native-language and accounting
  terminology review has **not** occurred; the selector and review-state API report `draft`.

## Adding a catalogue

Create `<locale>.php` here, returning an array keyed by the **English source string**:

```php
<?php
declare(strict_types=1);

return [
    'Save the invoice.' => 'انوائس محفوظ کریں۔',
    '{count} line'      => ['one' => '…', 'other' => '…'],
];
```

A plain string answers `pl_t()`. An array answers `pl_tn()` and is keyed by the CLDR plural forms
that locale uses (`zero`, `one`, `two`, `few`, `many`, `other`), which
`pl_i18n_plural_table()` declares. A key that is missing, empty or the wrong shape falls back to
the English source string: a screen never shows a raw key.

`ur-PK.php` is loaded on top of `ur.php`, so a region file states only what it changes.

The values are plain text, not HTML. Escaping stays with the caller, which writes
`pl_e(pl_t('…'))`. See "How to add a translatable string" in
[docs/DEVELOPMENT.md](../../docs/DEVELOPMENT.md).

## Module and plugin catalogues

A bundled module, and later a plugin, may declare a `lang` directory in its manifest:

```json
{ "id": "inventory", "lang": "resources/lang/modules/inventory" }
```

The value is a plain project-relative directory. Its `<locale>.php` files are merged **after** the
core catalogue, so a module may add keys and restate the wording of its own screens. No bundled
manifest declares one yet: adding the key to a manifest changes that module's digest, and every
company that has the module enabled would be asked to review the upgrade before its next
operation.

## Reviewing the Urdu draft

1. Read the header of `ur.php` first. The key is the English source string and must never be
   edited: editing a key orphans its translation silently, and the screen quietly falls back to
   English.
2. Switch the interface to اردو from the language menu (the user menu in the top bar, or the foot
   of the sign-in card) and walk the screens. A string still in English is a gap in this file, not
   a bug in the loader.
3. Check the figures, not only the words. Amounts must group as `1,23,45,678`, must keep their
   minus sign on the left, and must not reorder where they sit inside an Urdu sentence; dates must
   read `05 جنوری 2026`. All of that is `pl_money()`, `pl_date_label()` and `pl_bidi_isolate()`,
   not this file.
4. When the wording passes, change `ur`'s `review` in `pl_i18n_offered_locales()` from `draft` to
   `reviewed` **in the same change** that records who reviewed it, and update this file. The test
   `tests/i18n_test.php` asserts today that nothing claims `reviewed`; that assertion is what will
   tell you the claim has to be made deliberately.

## Reviewing the Arabic draft

Read the UNREVIEWED header in `ar.php`, then choose العربية from the existing language menu.
Review navigation, sign-in, document editing, reports, printing and the 1.3 financial screens at
desktop, tablet and phone widths. Verify six plural categories (0, 1, 2, 3–10, 11–99, and other),
named placeholders, negative amounts, account codes, dates and mixed Arabic/Latin party names.
Confirm that posted amounts and dates remain unchanged and that canonical inputs retain their
ordinary validation. Record the reviewer and any regional convention before changing Arabic's
review state to `reviewed`. Automated catalogue checks and screenshots cannot supply that sign-off.
