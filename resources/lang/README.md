# Interface translation catalogues

This directory holds the interface translation catalogues read by
`www/phpledger/includes/functions/i18n_functions.php`. It is the groundwork delivered in
milestone M2 of [RELEASE-PLAN-1.2.md](../../docs/strategy/RELEASE-PLAN-1.2.md); the interface
strings themselves are still hard-coded English and are externalised in M11.

## What is here today

Nothing but this file, deliberately.

- **English (`en`) is the source language.** Its keys are its strings, so it has no catalogue
  file and loads none. `pl_t('Save the invoice.')` returns `Save the invoice.`
- **The pseudo-locale (`qps`) is generated, not stored.** `pl_i18n_pseudo()` brackets and
  lengthens every string it is given, so a test can see which text went through `pl_t()` and which
  is still hard-coded. It ships no catalogue and is never offered to a user.
- **Urdu and Arabic** (decision B3) get their catalogues when the strings exist and the screens,
  font coverage, dates and numbers have been reviewed. A language is not "supported" before that.

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
