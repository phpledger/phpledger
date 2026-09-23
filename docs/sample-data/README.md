# Business sample data

Seven original, sample demonstration packs have been authored for the planned **Explore a sample business** journey. They are versioned JSON fixtures with expected accounting results, not executable seeders or installed business modules. No records have been loaded into an application database by this work.

The user requested restaurants, clubs, pharmacy, traders, distributors, shops and workshops. Repeated mentions of clubs are represented by one membership-club pack. A club here is a member-funded recreation business; no charity, tax exemption or particular legal form is assumed.

## Available packs

| Business | Pack | What the example teaches |
|---|---|---|
| Restaurant | [restaurant.json](../../resources/sample-data/restaurant.json) | Food/beverage income, catering receivables, ingredients, a return and a proposed table/KOT journey |
| Membership club | [membership-club.json](../../resources/sample-data/membership-club.json) | Outstanding dues, prepaid annual memberships, monthly income recognition and event tickets |
| Pharmacy | [pharmacy.json](../../resources/sample-data/pharmacy.json) | Lot/expiry traceability using clearly marked non-medicinal training products |
| Trader | [trader.json](../../resources/sample-data/trader.json) | Wholesale quantities, credit sales, supplier payments and customer credits |
| Distributor | [distributor.json](../../resources/sample-data/distributor.json) | Van stock loading, deliveries, returns, cash retained/banked and an unpaid customer invoice |
| Retail shop | [retail-shop.json](../../resources/sample-data/retail-shop.json) | Counter sales, customer credit, stock, a credit-note return and counted cash |
| Service workshop | [service-workshop.json](../../resources/sample-data/service-workshop.json) | Separate labour/parts income, a linked estimate/job, and customer property held for repair |

Across the packs there are **28 sample contacts, 16 products/services, 42 opening/current documents, and 77 events**. The workshop also contains one embedded nonposting estimate and a customer-owned bicycle reference. These latter scenario fixtures are not included in the document count. Financial and stock quantities are intentionally small enough to check by hand. They represent a teaching month, not forecasts or realistic revenue claims for any industry.

## Installation and onboarding contract

An administrator first installs PHP Ledger. Business onboarding should then offer **Explore a sample business** separately from **Set up my business**. Selecting a business type for a real company must select an appropriate reviewed account template; it must never add these sample customers, stock or transactions to that real company.

A future demo loader must:

1. Create a new, explicitly isolated demo company/book. Reject an existing real-company target. Show a persistent sample banner and never send notifications, payments or provider requests from demo contacts.
2. Let the user choose one pack and base currency before creation. The current files use USD merely as a demonstration label, with no country selected. Re-labeling the sample fixture before creation is not currency conversion and must never modify an existing book's currency or posted data.
3. Pin the pack version and reviewed chart-template version. Resolve semantic keys to company-owned account IDs before using the one central posting interface. Review the mapping and all totals before applying it. These keys are currently research candidates, not the six-account runtime proof's installed catalog.
4. Import opening information through one approved cutover strategy, then add the sample period's events in date order, keeping stable source references and a durable import receipt. Retry must not duplicate records or postings.
5. Compare the resulting trial balance, open-document totals, stock and deferred-income balances with `expected_reports`. A partial loader must clearly disclose unsupported scenarios and cannot claim it loaded the complete pack.
6. Offer reset only for the explicitly selected isolated demo company, subject to permission checks. Never clear a real company's data to make room for examples. Reset/public demo/session expiry mechanics are later implementation work.

**Opening data is declarative, not two sets of posting instructions.** `opening.journal` describes the complete expected opening trial balance, including AR/AP. `opening.documents` explains those same receivable/payable control balances; it is not extra revenue, expense or a second AR/AP posting. The proposed importer in [the account-template model](../coa/TEMPLATE_MODEL.md) posts outstanding documents and the remaining opening position through a clearing account. A loader following that method must exclude duplicate AR/AP from the remaining opening journal, clear the bridge, and reconcile to the full expected `opening.journal`. It must never post both this full opening journal and those documents as additional financial entries.

Opening stock and prepaid contracts similarly explain their opening control balances. Do not receive that stock or collect those prepayments again. The fixture files are not production import spreadsheets, and none is a shortcut around preview, validation and confirmation.

## Data contract, version 1.0.0

| Field | Meaning |
|---|---|
| `schema_version`, `pack_version`, `pack_id` | Exact version and stable business identity; do not silently alter a loaded snapshot |
| `demo_only`, `sample`, `status`, `isolation` | Explicit candidate/demo boundary and prohibition on merging into real companies |
| `business` | Display label, configurable starting currency, fixed cutover/report dates and fiscal-year end |
| `accounting_assumptions`, `country_variations` | Omissions and review status, including no activated jurisdiction or tax regime |
| `accounts` | Stable semantic `key`, canonical type, readable label and internal `fixture_role` used for reconciliation; mapping is subject to accountant review |
| `contacts`, `items`, `locations` | Original sample references. Contact addresses end in reserved `example.invalid`; no phone numbers, real addresses or patient data are supplied |
| `opening` | Complete expected opening position plus explanatory unpaid-document, stock and prepaid-contract details |
| `documents` | New sample-period invoices, bills, credit notes, cash sales and prepayments, with linked IDs and fixed-precision line totals |
| `events` | Ordered business events, stable duplicate-prevention keys and expected journal lines; stock transfers have no financial journal |
| `industry_scenarios` | Explicitly future UI/module scenarios, including their own nonposting example entities and acceptance checks |
| `expected_reports` | Full trial balance, movement totals, income/result, accounting equation, cash/bank, AR/AP by document and stock by item/location/lot |
| `source_ids` | Public inspiration references in [SOURCES.md](SOURCES.md); all names, quantities, amounts, IDs and scenarios were authored originally |

IDs are unique within a pack; durable external identity is `(pack_id, pack_version, entity_type, id)`. They are not global database IDs. A future importer must use its own company/book-scoped ID mapping. Dates are deterministic in September 2026. Reports and expiry alerts use `2026-09-30` rather than the changing system date. Money, price and quantity values are four-decimal strings, never floating-point numbers.

Tax is **not modelled**. A numerical tax amount of zero must not display as zero-rated, exempt or compliant. Inventory uses the same fixture cost for every purchase of a given SKU so the sample arithmetic does not choose FIFO versus weighted average. FEFO in the pharmacy scenario describes which lot to pick, not financial inventory valuation. Financial costing policy and country adapters still require review. Depreciation, payroll, bank fees, foreign exchange, prescriptions and tax returns are outside these small examples.

## Validation

From the repository root, run:

```text
php tools/validate-sample-data.php --self-test
```

The command reads JSON only. It does not load application configuration, open a database, write files or start services. It validates identifiers, dates, fixed-precision arithmetic, balanced journals and their declared business effects, opening AR/AP reconciliation, document allocations and returns, stock quantities/value, original/reversal links, prepaid membership recognition, and ending reports. It also checks demo markers and rejects eight deliberately corrupted in-memory fixtures.

Local PHP 8.5.5 syntax and data checks passed on 2026-09-14: seven packs, 77 events, 42 documents, 16 items; all eight corruption checks rejected. A separate agent independently recalculated the financial data with exact decimal arithmetic and reviewed account keys, van transfers and deferred dues. The main foundation validation report records target-runtime checks separately. This is arithmetic/provenance review; it is not accountant sign-off, a working importer or observed UI usability evidence.

See [SCENARIOS.md](SCENARIOS.md) for the walk-throughs and expected totals, [SOURCES.md](SOURCES.md) for marketplace evidence/limits, and [account candidates](../coa/ACCOUNT_CANDIDATES.md) for the unreviewed chart keys. No Google Drive documents were read for this subtask. No migrations, schema, production or external accounts changed.

## Separate signed package publication (1.3)

The eleven versioned demo packs and sample structures are built by `tools/build-sample-packages.py`; they are separate from the seven early arithmetic fixtures described above. [Issue 72](https://github.com/phpledger/phpledger/issues/72) places each sample in its own `phpledger/sample-<slug>` repository. The repository contains exactly `LICENSE`, `README.md`, `package.json`, `pack.json`, and `structure.json`. A release carries only its ZIP, ZIP `.sha256` sidecar and `sample-envelope.json`. The application ZIP does not acquire these samples.

`tools/publish-sample-packages.py` is an operator gate, not a signing tool. It uses Python `cryptography` and the pinned public key to verify all eleven signatures, signed archive sizes/hashes, three-member data-only ZIP inventories, directory metadata, five-file source trees, signing receipts and byte-equivalent four-file read-only preloads. Its default mode makes only read-only GitHub API calls and writes a local review plan. An existing target repository, including an empty one, is a collision; authentication/network failures never count as absence.

```text
python tools/publish-sample-packages.py --artifacts <signed-package-output> --preloads <signed-readonly-preloads> --plan <review-plan.json>
python tests/sample_publication_test.py
```

Review that exact plan before execution. The script checks fresh inputs against it, freezes only the allowed source/asset bytes, and checks every repository for collisions again before the first external write. `--execute --receipt <new-receipt.json>` explicitly creates public repositories and draft releases; adding `--publish` publishes the drafts only after all eleven drafts exist and their downloaded assets match the expected bytes. Execution requires authenticated `gh`, configured Git author identity, and owner authorization. Do not run either write mode while publication is on hold.

The execution receipt records each repository commit, tag, release URL, asset hash and last completed step. Existing receipts are never overwritten at the start. A partial failure requires operator inspection using that receipt; there is no force-push, overwrite, deletion or automatic recovery of a colliding repository. Release publication does not deploy the website directory or send announcements. Those remain separately verified coordinator actions.
