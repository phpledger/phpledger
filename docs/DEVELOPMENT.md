# Local revival development

## Stable-path installation and signed updates

Browser installation and signed updates are published in **1.0.0**. The current [roadmap](ROADMAP.md#current-delivery-contract-first-stable-10) and [validation receipt](VALIDATION.md#100-publication--18-september-2026) separate implemented/tested behavior from independent review and pilot acceptance, which remain open post-release commitments. Browser setup shares the CLI migration/preflight service; installation state lives in private files, without a new accounting schema. The independent maintenance loader and its copied recovery worker run without the application version being replaced. Tests use random disposable databases and sample signing material.

`tools/build-package.py` accepts stable versions and explicit `--channel stable|preview`, retaining the clean committed-source and explicit file allowlist requirements. After building a reviewed package, a publisher can create the updater envelope with:

```sh
php tools/sign-update.php --archive=/private/release/phpledger-VERSION.zip --key=/private/signing/publisher.pem --output=/private/release/phpledger-VERSION.update.json
```

This is a publisher command, not a customer installation step. It requires PHP ZIP/OpenSSL and an external RSA private key of at least 3072 bits, validates the complete package inventory, and refuses to overwrite an existing output. An encrypted key may use the host-provided `PL_RELEASE_KEY_PASSPHRASE`; never put a passphrase in arguments or commit a key. Tests generate temporary sample keys; no official release identity has been provisioned by this implementation.

The operator pins the independently authenticated publisher public key outside the public root. A key inside a downloaded package cannot establish that trust. Production key custody, distribution of its fingerprint and an authenticated rotation/revocation procedure must be accepted before publishing signed updates. Publish the signed envelope alongside the ZIP, SHA-256 file and matching media kit under the normal explicit release authorization. A local package proof is not a published release.

Standalone checks are `tests/browser_installer_test.php`, `tests/release-signing-test.php`, `tests/update-recovery-test.php`, `tests/update-database-test.php`, `tests/update-fullschema-test.php`, `tests/update-http-test.php` and `tests/update-migrate-test.php` (the real migrate and verify phases under the copied recovery runtime, issue #90). The CI matrix runs these alongside the existing accounting suite on PHP 8.2/8.3/8.4. A configured workflow is not evidence of a completed remote CI run. Keep the package and updater limits in `resources/release/UPGRADE.md` visible, including the pinned maintenance loader and current schema-owning database identity requirement.

## 0.6 interface development

The approved interface is maintained on `codex/ui-redesign-0.6` for the preview release. The source stylesheet is `resources/ui/app.css`; run
`npm ci` then `npm run build:css` and commit the generated
`www/phpledger/public/assets/app.css`. Tailwind 4.3.3 is development-only.
Packages include compiled CSS, the existing local Inter font and the Tailwind
licence, and exclude npm dependencies, Tailwind sources and prototype files.
The legacy stylesheet has been removed. Remaining acceptance gaps are tracked in the redesign audit closure checklist.

Shared presentation functions live in `templates/partials/ui/components.php`,
with the workspace shell in `templates/partials/ui/shell.php`. All rendering
continues through the existing PHP layout and posting services. CSP is unchanged.

List pages use `pl_list_filters()` and `pl_list_query()` over the existing scoped
services. GET parameters are `page`, `per_page` (25, 50, 100), `q` (160 characters),
`sort` from the screen's allow-list and `dir` (`asc`/`desc`). Transaction status,
kind and date filters remain in the URL; journals now accept status and text.
Invoices and bills also use this contract, with current-revision dates/amounts,
paid/unpaid/overdue status and filter-preserving record/editor return links.
Sort keys select complete fixed SQL order clauses; no column name or direction
from the request is assembled into SQL. All filter values and page bounds are bound.
The helper uses the existing counted LIMIT/OFFSET queries. Statement running
balances still come from the existing accounting service. `/tables` retains its
original JSON contract for API consumers; pages no longer load DataTables.
Run `docker compose --profile test run --rm test php tests/run.php --suite=lists`
for the targeted list tests, and `--suite=ar-lists` for current-revision AR/AP lists.
`docker compose --profile test run --rm test php tools/verify-list-capacity.php`
seeds 5,000 sample receipt drafts, posted journals and bank rows using normal
services on the isolated test database, then records bounded query plans/timings.
Use `--measure-only` to reuse that fixture. Bank statements retain their 500-row
limit; the fixture has ten separate statements/accounts. Migration 031 indexes
the source lookup used by effective receipt/expense correction history.
Local before/after evidence is in `docs/design/redesign-0.5/evidence-0.6.0/`.

## Document printing (1.2 M2)

`GET /print/<type>/<id>` renders one shell-less print document: no sidebar, topbar, context strips, notices or scripts, only the letterhead, the document and on-screen format links that `@media print` hides. An optional `format` parameter selects the paper; it defaults to A4 (`PL_PRINT_DEFAULT_FORMAT`). The route is read-only — it accepts GET only, posts nothing and writes nothing — and it is resolved after the session company context, so the company/book scope and the role rule of the document's own record screen apply unchanged. An id from another company is not found in this scope and is refused; an unknown type or an unregistered format is refused rather than guessed.

`pl_print_templates()` in `includes/functions/print_functions.php` is the registry, keyed by document type and then by format. Each type carries a human label, the read-only loader `(actor, company, book, id)` that returns its data, the function that derives the printed reference, and the record screen to return to. Each format carries a human label, its view file under `templates/print` and the paper class the frame applies. The first registered type is the settlement receipt (`settlement`), in `a4` and `80mm`, read by `pl_settlement_receipt()` in `settlement_functions.php`.

1.2 M4 registers two more through the same registry, without touching the route: `stock-issue` (`a4` with the gate pass alongside the items, `80mm` as the van-facing load list the driver takes on the road) and `gate-pass` (`a4` gate copy, which lists the items of the document it covers). This follows the distribution research's decision 7 — A4 for the documents that stay at a warehouse desk, 80 mm for the ones that travel with the van. Their loaders are `pl_stock_document_print()` and `pl_gate_pass_print()`, both of which read through `pl_get_stock_document()`, so the record screen's own access rule is defined once.

**To add a document type or format, do not touch the route.** Add the entry to `pl_print_templates()`; add the view file under `www/phpledger/templates/print/`; add the loader beside the existing service for that document, calling the same scoped read the record screen calls, so the access rule is defined once. Views receive `$document` (the loader's result), `$letterhead`, `$company`, `$template`, `$reference`, `$formats` and `$recordId`, and must render fragments only — the standalone HTML document is `templates/print/frame.php`. `tests/print_test.php` then checks the new pair automatically: every registered format must have its view file, a human label and a default A4 entry.

The letterhead uses only identity the schema already has: the optional installation logo (migration 033), the company name, its book and its functional currency. There is **no** company address or tax-registration column — only parties carry addresses and registrations (`pl_party_details`) — so the letterhead states none and the A4 receipt prints the counterparty's stored address and registration lines instead. Adding a company profile is an owner decision and a migration, not a print change.

Print styles live at the end of `resources/ui/app.css` (an `@media print` block plus the `.paper-a4` and `.paper-80mm` classes). They use design tokens and logical properties only, because the 1.2 Urdu work flips the inline axis; `tests/print_test.php` fails on a hard-coded colour or a physical `left`/`right` property in that block. Rebuild and commit the compiled stylesheet with `npm ci` then `npm run build:css`, as in the 0.6 interface section above; the same test fails if `www/phpledger/public/assets/app.css` is missing the print rules.

```powershell
docker compose --profile test run --rm test php tests/run.php --suite=print
python tests/print-http-smoke.py
```

`tests/print-http-smoke.py` posts one ordinary customer receipt through the settlement service on the local Compose stack, then checks over HTTP that an anonymous visitor is sent to sign in, that owner, accountant and viewer all print, that the rendered output carries no shell markup, that both formats render, and that an id outside the book, another company's scope, an unknown type, an unknown format and any POST are refused.

## Accounting starter — current local service and route contract

The current source adds AR, AP, Purchasing, Inventory and the configurable core tax engine. The published package and hosted demo remain **0.3.0-preview** until a separately recorded release. Read the [starter scope and validation record](repository/sprint-06/ACCOUNTING-STARTER.md) before implementation or testing; the historical foundation section below describes the published prerequisite release.

AR and AP have separate internal module ownership and are required parts of the accounting core. They cannot be disabled. Owner-controlled visibility settings hide navigation without changing permissions, accounting balances or access from another module. Inventory is optional; Purchasing is optional and depends on Inventory and AP. Enable Inventory before Purchasing through `/modules`. Disabling an optional module blocks new operations while preserving authorised access to its history. Quotes remain outside this build on the preserved plugin branch.

| Route | Development workflow |
|---|---|
| `/parties` | Maintain the shared customer/vendor party and contacts; both roles may belong to one party. |
| `/ar`, `/ap` | Save and review invoices/bills, post explicitly, allocate partial/final payments, issue linked credits, inspect source revisions and historical ageing/control reconciliation. |
| `/purchasing` | Save/confirm orders, receive partial quantities, preview/confirm later supplier bills, record linked returns and reconcile received-but-unbilled amounts. |
| `/inventory` | Maintain stock/non-stock products, inspect one-location quantity/value, record scoped stock movements/counts and review opening stock conversion. |
| `/opening-conversion` | Preview explicit party mappings, confirm reconciled opening debt allocations and record subsequent payments. |
| `/tax` | Configure tax codes and dated rates manually; choose the company's default exclusive/inclusive price entry. |
| `/modules` | Review optional module activation and owner-controlled AR/AP navigation preferences. |
| `/owner` | Record capital introduced, an owner loan and its repayment, and drawings; review owner's-equity movements, partner capital and profit-sharing ratios. `POST /owner/post` and `POST /owner/reverse` carry CSRF and company/book scope. |

Keep browser adapters in the existing front controller, shared bootstrap and `starter_*_web_functions.php` helpers. Each browser POST retains CSRF and company/book scope checks, and every service repeats authorisation. IDs are integers; money and quantities are decimal strings with up to four places, FX rates up to twelve and tax percentages up to six. Never convert financial input through a PHP float. Financial mutations use the shared book transaction, durable request keys and central journal posting service; templates and vertical modules do not insert journals or maintain separate payable/receivable balances.

### Internal service entry points

- **AR/AP:** `pl_save_ar_document(actor, company, book, input, id?, expectedRevision?)`, `pl_post_ar_document(..., id, revision, offsetAccountId?, rate?)`, `pl_settle_ar_document(..., id, input)` and the existing reversal/correction functions. Documents accept `invoice`, `bill`, `customer_credit` or `supplier_credit`; lines identify posting accounts, optional products and tax codes. Credits link their original document and, for taxed lines, the original line number. A stock invoice issues goods through Inventory atomically with financial posting. Outstanding amounts come from the authoritative open-item entries. Posting also allocates the document's permanent number from its type's series.
- **Trading documents (1.2 M3):** `trading_functions.php`. Policies (B37): `pl_trading_policies(actor, company, book)` and `pl_save_trading_policies(actor, company, book, input)` (owner-only; `discount_posting` `net`|`gross`, `discount_account_id`, `free_goods_account_id`, `free_goods_output_tax` `none`|`open_market_value`, `cash_on_invoice_cap`, `revision`, `reason`, `idempotency_key`), with `pl_trading_policy_account_options()` for the accounts each may name and `pl_trading_policy_history(actor, company)` for the immutable trail. Company profile (B64): `pl_company_profile(actor, company)`, `pl_company_profile_fields()` and `pl_save_company_profile(actor, company, input)`; per company, Admin-editable, empty by default, and used by `pl_print_letterhead(company, profile?)`. Reference data: `pl_save_product_pack`/`pl_get_product_pack`/`pl_list_product_packs`, `pl_save_sales_staff`/`pl_get_sales_staff`/`pl_list_sales_staff`, `pl_save_area`/`pl_get_area`/`pl_list_areas` — each creates or updates one row against its revision, with a `pl_core_audit` entry; a pack's product, code and size and a reference row's code are immutable in the service and again in a database trigger. Arithmetic: `pl_trading_pack_quantity(packQuantity, unitQuantity, unitsPerPack)`, `pl_trading_line_discount(gross, percent)` and `pl_trading_discount_percent(percent)` are pure functions. Reads: `pl_party_statement(actor, company, book, partyId, from?, to?)` builds the statement from the party's control-account entries so it reconciles to the ledger by construction; `pl_trading_invoice_print(...)` and `pl_trading_statement_print(...)` are the read-only print loaders behind the M2 registry, with `pl_print_invoice_reference()` and `pl_print_statement_reference()`. A policy change never restates a posted document; see [the worked examples](accounting/examples/trading-document-policies.md).
- **AR trading editor (1.2 M3):** `pl_trading_editor_context(actor, company, book)` returns the active packs (also grouped by product), sales staff, areas and warehouses plus the policies in force, or empty lists and the recommended defaults when the module is off, so the screen never offers an entry the service would refuse. `pl_trading_editor_readout(actor, company, book, partyId?, productId?, warehouseId?)` is the read-only balance and stock strip of frame decision 6: the party's balance through `pl_party_statement()` and stock through `pl_inventory_balance()`, with `van_stock` permanently null until a van is a warehouse kind. `pl_ui_commercial_lines(rows, options, credit, purchase, errors, trading)` renders the pack, pack/unit, discount and free-goods columns when `trading` is supplied and is unchanged for every other caller; `pl_ui_totals(rows, keys)` names the rows a live recalculation owns (`net`, `discount`, `tax`, `total`) and the rows that belong to the last server preview (`preview`, dropped on edit). The editor publishes the frozen pack sizes to the browser as `data-pack-sizes`, so the typed figure resolves the same base quantity the server will.
- **AR trading fields (1.2 M3):** `pl_normalize_ar_document(input, packSizes = [])` takes the frozen pack sizes read by `pl_trading_document_pack_sizes(actor, company, book, input)`, so the normaliser stays a pure function of its input and a resolved base quantity is part of the draft's identity hash. Header input adds `sales_staff_id`, `area_id`, `warehouse_id`, `cash_received` and `cash_account_id`; line input adds `pack_id`, `pack_quantity`, `unit_quantity`, `discount_percent` and `is_free_goods`. Every one is optional and neutral, so a caller written against 1.1 produces the document it produced before. `pl_ar_validate_dimension_references()` refuses a selection outside the book or a retired one where the draft is saved; `pl_ar_validate_dimensions()` adds the policy-dependent checks in the posting plan. `pl_ar_issue_invoice_stock()` sends valued lines to the inventory module's own AR issue service and issues free-goods lines with the promotional offset account, using the same source type, document, journal, reference and idempotency key so existing reversal and credit-allocation readers find them unchanged. `pl_ar_settle_invoice_cash()` settles the cash portion inside the posting transaction and returns its journal id; `pl_ar_assert_correctable()` sends a posted cash invoice to the reversal path.
- **Advances, refunds and unapplied credit (1.2):** `pl_normalize_settlement()` now accepts a remainder: allocations may sum to less than `amount_fc`, and `advance_account_id` names the control the remainder is held on (it is refused if a remainder exists without one, and over-allocation is still refused). `pl_settlement_plan()` returns `remainder_fc`, `remainder_base` and `advance`; `pl_settle_open_items()` creates the advance open item and posts it in the same journal, so a receipt with a remainder is one voucher with one bank line. `pl_oldest_first_allocation(items, amount, date, cap?)` is a **pure** function in `advance_functions.php` — no database, no clock — ordering by due date then id, giving the last item the exact residual and skipping items whose latest activity is later than the payment; `pl_plan_settlement_allocation(actor, company, book, direction, partyId, currency, amount, date)` reads a party's items and calls it. The plan is editable and still goes through `pl_preview_settlement()`, `pl_settlement_review_hash()` and `pl_confirm_settlement()`. `pl_apply_unapplied_credit(actor, company, book, input)` (`advance_item_id`, `allocations`, `date`, `description`, optional FX accounts, `idempotency_key`) posts the application with no bank line; `pl_refund_unapplied_credit(actor, company, book, input)` wraps `pl_settle_open_item()` for a cash refund; `pl_recognize_unapplied_credit(actor, company, book, input)` (`side`, `party_id`, `offset_account_id`, `currency`, `amount_fc`, `date`, `source_reference`) posts a credit note that has no original invoice; its offset must be an `income` account on the customer side and an `expense` account on the supplier side, never a bank or an open-item control, unless the owner passes `allow_other_offset_account` with an `offset_override_reason` (`pl_advance_offset_contract()`, `pl_advance_assert_offset_account()` and `pl_advance_offset_override_claimed()`, enforced again in `pl_open_item_validate_direct_advance_basis()`; the reasoning is in `docs/accounting/ADVANCES-AND-REFUNDS.md` section 5). `pl_post_batch_receipts(actor, company, book, input)` posts one voucher per `rows` entry (B57) in one transaction under one batch receipt. `pl_unapplied_credit(actor, company, book, side = 'customer', asOf?, partyId?)` is the read for the ageing section, the statement and the `unapplied_credit` API operation. Supporting helpers: `pl_advance_control()`, `pl_advance_open_item()`, `pl_advance_role_contract()`, `pl_advance_side_for_direction()`, `pl_settlement_allocation_cap()`, `pl_open_item_increasing_kinds()`, `pl_open_item_relieving_kinds()`, `pl_open_item_control_roles()` and `pl_open_item_source_types()`. The allocation cap is 100.
- **Document numbering:** `pl_list_document_series(actor, company, book)`, `pl_save_document_series(actor, company, book, type, input)` (owner-only; `prefix`, `padding`, `year_segment`, `reset_rule`, `next_number`, `revision`, `reason`), `pl_document_series_allocate(actor, company, book, type, documentId, date)`, `pl_document_series_format(series, year, number)`, `pl_document_number_lookup(type, documentId)` and `pl_document_number_display(stored, documentId, type)`. One series per company, book and document type (`invoice`, `bill`, `customer_credit`, `supplier_credit`); there is no per-warehouse override (B55). Allocation happens inside the posting transaction, under the book lock and with `FOR UPDATE` on the series row, and writes an immutable `pl_document_numbers` row, so numbers are never reused and a rolled-back posting leaves no gap. Drafts are unnumbered. The prefix, padding, year segment and reset rule may change; the next number may only be raised, in the service and again in a database trigger. A yearly series adopts the year of its first document and then only moves forward: a document dated in an earlier year is refused with the reversal path named (set the reset rule to `never`). Documents posted before migration `035_document_series` keep their derived `INV-<id>` form through the display fallback.
- **Purchasing:** `pl_save_purchase_order`, `pl_confirm_purchase_order`, `pl_receive_purchase_order`, `pl_preview_purchase_bill`, `pl_bill_purchase_receipts` and `pl_return_purchase_receipt`. Orders are non-posting commitments. Goods receipts recognise stock and received-but-unbilled value; matched AP bills clear the receipt basis. Order prices are explicitly net of tax. Bill input may be inclusive or exclusive; net matching remains separate from input tax. Posting rechecks the bill preview digest. Price/rate differences and fractional return carrying differences require explicit variance review; they never overwrite receipt history.
- **Inventory:** `pl_save_inventory_product`, `pl_inventory_receive`, `pl_inventory_issue`, `pl_inventory_return`, `pl_inventory_adjust_count` and `pl_inventory_value_adjustment`. Use the product's single stock location and moving weighted-average basis. Negative stock and movements dated before later product activity are rejected. A value adjustment supplies the expected quantity and carrying value so a stale review cannot silently change stock value. Returns retain their source movement and its remaining historical cost.
- **Stock documents (1.2 M4):** `pl_post_stock_document(actor, company, book, input)` records one numbered `stock_issue`, `stock_reissue` or `stock_return`; `input` is `kind`, `date`, `from_warehouse_id`, `to_warehouse_id`, an `original_document_id` for a re-issue, `reference`, `reason`, `lines` (`product_id`, `quantity`) and a durable `idempotency_key`. Each line becomes one `pl_inventory_transfer()` pair, so value follows the goods at the source location's average cost and **no journal is written**: a van and a warehouse are both the company's own stock. `pl_issue_gate_pass(actor, company, book, input)` records a numbered gate pass that creates no line, no movement and no journal; it names the moving document it covers, and `pl_stamp_gate_pass(..., documentId, 'out'|'in')` records the security desk's one-time stamp. Reads: `pl_stock_document_summary`, `pl_get_stock_document`, `pl_list_stock_documents(..., filters)` and `pl_list_stock_transfers(..., filters)`. Direction is enforced by warehouse kind: an issue and a re-issue load a `mobile` location from a `fixed` one, a return goes the other way; a warehouse-to-warehouse move stays the plain `pl_inventory_transfer()`. Every header, line and gate pass is immutable in the service and again in a database trigger; only a `NULL` document number may become a number, once.
- **Warehouse kinds (1.2 M4):** `pl_save_inventory_warehouse()` additionally accepts `kind` (`fixed` or `mobile`, from `pl_inventory_warehouse_kinds()`), `driver_name`, `vehicle_reference` and `route_name`. A van is a `mobile` warehouse and names its driver; a `fixed` one carries none of the three. The kind is permanent, like the code, and the book's default warehouse is always `fixed`, so the existing default-warehouse rule is unchanged.
- **Van settlement (1.2 M4):** `pl_van_day(actor, company, book, warehouseId, date)` builds the driver's day from the movements themselves — opening, loaded, sold, sellable customer returns, returned to the warehouse, closing — and flags each product where `opening + loaded + customer_returns − sold − returned` does not equal the closing stock. `pl_review_van_settlement(..., warehouseId, date, reason, key)` records that sheet; `pl_approve_van_settlement(..., id, reason, key)` approves it and refuses a day that does not reconcile or that changed since it was reviewed. Neither posts anything: the cash and credit belong to the sale documents the van raised. **Approval is a distinct permission.** Until the Users module lands, `pl_van_settlement_require_approver()` is the seam and holds the interim owner check; replacing it with the `settlement.approve` capability is a one-line change.
- **Stock screens and prints (1.2 M4):** four browser routes — `/stock-documents` (list and editor), `/stock-documents/detail`, `/stock-documents/settlement` and `/reports/stock-by-location`, the route the `inventory-locations` manifest declares for its `stock-by-location` report — plus the `stock-issue` and `gate-pass` print types. `python tests/stock-http-smoke.py` is their HTTP-level acceptance script (definition-of-done item 2); see [Verify changes](#verify-changes).
- **Stock reports (1.2 M4, B35):** `pl_stock_by_location(actor, company, book, asOf?, locations = 'all'|'fixed'|'mobile', search?)` groups warehouses then vans, each with its own subtotal, an aggregate quantity per item and a grand total reconciled against `pl_inventory_valuation()`. `pl_van_stock_report()` is the same report filtered to `mobile`. `pl_stock_location_aggregate()` is the across-locations counterpart, which is the only view that ties to the inventory control accounts. Cost columns follow `pl_stock_cost_visible()` — the interim `cost.view` seam, owner-only until user permissions ship — and are returned as `null`, not merely hidden in the template.
- **Opening conversion:** `pl_preview_opening_conversion(..., cutoverId, mappings)` and `pl_confirm_opening_conversion(..., cutoverId, mappings, expectedHash, confirmed, key, reason)` require explicit party mapping and exact control reconciliation. `pl_preview_inventory_opening(..., input)` and `pl_confirm_inventory_opening(..., previewId, expectedHash, confirmed, key)` provide the separate product/quantity review. Both link allocations to existing opening journal amounts without posting those balances again. Ambiguous, already-converted or otherwise-used bases fail explicitly.
- **Core tax:** `pl_create_tax_code`, `pl_enter_tax_rate` and `pl_tax_calculate` use manually selected code/account/date inputs. `pl_set_tax_price_mode(actor, company, book, mode, revision, reason, key)` is owner-only; `pl_tax_price_mode` reads the default. Modes are `exclusive` and `inclusive`, with exclusive as the initial default. Each document freezes its reviewed mode and tax snapshot. Inclusive entry preserves the entered gross; both modes display net, tax and total. Later rate/default changes do not rewrite posted documents, and credits retain their original source basis.

- **Structured account codes (1.2):** `pl_account_code_parse(code)`, `pl_account_code_format(class, group, account, sub = 0)`, `pl_account_code_is_valid(code)`, `pl_account_code_level(code)`, `pl_account_code_parent(code)`, `pl_account_code_ancestors(code)`, `pl_account_code_short(code)`, `pl_account_code_class_for_type(type)` and `pl_account_code_mapping(accounts)` are pure functions in `account_code_functions.php`; they never touch the database, the request or HTML. `pl_account_is_postable(company, book, code)` and `pl_account_require_parent(company, book, code, type)` are the database-aware chart rules. `pl_save_account()` additionally accepts `is_contra`; code, classification and purpose stay immutable after creation, and the contra flag is an audited presentation change.
- **Report trees:** `pl_report_tree(rows, measures)` returns the class → group → account → sub-account tree with each measure in `measures` aggregated at every level; `pl_report_tree_limit(nodes, depth)` applies the depth control (1 classes only … 4 with sub-accounts) and `pl_report_tree_rows(nodes)` flattens one for CSV and print. `pl_trial_balance()` returns `tree`; `pl_profit_loss()` and `pl_balance_sheet()` return `trees` keyed by section, and `pl_balance_sheet()` also returns `equity_movements`. The machine-read API omits all three.
- **Owner transactions:** `pl_post_owner_transaction(actor, company, book, input)` where `input` is `kind` (`capital_introduced`, `owner_loan_received`, `owner_loan_repaid`, `drawings`), `date`, `amount`, optional `cash_account_id`, `owner_account_id` and `partner_id`, a `description` and a durable `creation_key`. `pl_reverse_owner_transaction(actor, company, book, journalId, date?, reason)` posts the linked reversal. `pl_list_owner_transactions(actor, company, book, limit = 50)`, `pl_owner_accounts(company, book)` and `pl_owner_equity_movements(actor, company, book, asOf)` are the reads. Partners: `pl_save_owner_partner(actor, company, book, input, id?, expectedRevision?)`, `pl_list_owner_partners`, `pl_get_owner_partner`, `pl_owner_shares_complete` and `pl_owner_partner_positions(actor, company, book, asOf)`. No profit allocation is posted; see [the worked examples](accounting/OWNER-TRANSACTIONS.md).

The packaged country tax catalogs remain disabled research references. The core engine does not infer country rates, applicability, withholding, recovery restrictions or filing rules from those catalogs. Public API/MCP financial write commands and external sends are not introduced by these internal service interfaces.

### Shared bank matching and correction limits

Receipts and supplier payments create ordinary bank-account journal lines through the same posting funnel. Match those lines in the existing `/bank-reconciliation` CSV workflow: one statement row matches one journal line with the same signed amount. Reconciliation creates no second payment or journal. Split/aggregate matching and bank feeds remain deferred.

Keep same-identity reversal/repost separate from a commercial credit. Reverse dependent payments/credits explicitly before correcting their source, subject to period and completed bank-reconciliation restrictions. Purchasing matches, stock movements and converted opening bases cannot be detached through a standalone journal reversal. The existing outgoing foreign-currency-bank restriction remains in force. See the [starter record](repository/sprint-06/ACCOUNTING-STARTER.md) for the bounded return, credit, inventory and tax policies.

### Starter migration and validation commands

Nine migrations extend the published 0.3.0 chain: `017_ar_ap_documents`, `018_inventory`, `019_purchasing`, `020_opening_conversion`, `021_module_visibility`, `022_tax_engine`, `023_inventory_product_audit`, `024_opening_allocation_guard` and `025_tax_price_mode`. The complete chain has **26 receipts**, including both historical `006_*` filenames. Preserve all applied names and checksums; do not use the unpublished quote worktree as an upgrade baseline.

Back up the matched code/database and stop application and worker writes before applying the complete existing migration command. MySQL schema changes do not roll back as one application transaction. Keep deployment-specific migration and restore evidence separate from local test results.

```powershell
docker compose exec -T web php www/phpledger/install/migrate.php
docker compose --profile test run --rm test php tests/run.php --suite=starter
docker compose --profile test run --rm test composer check
docker compose --profile test run --rm -e PL_DB_USER=root -e PL_DB_PASSWORD=local-test-root-only test php tools/verify-starter-upgrade.php
```

`verify-starter-upgrade.php` is **disposable-test-only**. It rejects non-CLI use, non-test environments and any effective connection other than the local `db_test` service, `phpledger_test` database and its test root account. It creates a randomly named `phpledger_starter_verify_*` schema, reconstructs a sample published 0.3.0 baseline through migration 016, checks original rows/receipts during upgrade and exercises subsequent posting/settlement. Its fixture-only historical inserts are not an application posting interface. Cleanup removes only that run's random schema and preserves `phpledger_test` and browser fixtures. The literal password above is the existing disposable test credential, not a deployment credential.

Run financial suites serially against the shared test service. Run backup/restore verification after other writers finish. Follow [validation evidence](VALIDATION.md) and the [starter record](repository/sprint-06/ACCOUNTING-STARTER.md) for commands actually executed, browser viewports and unresolved review gates; a passing suite is not accounting or tax approval.

## Historical 0.3.0 AR/AP foundations — service and upgrade contract

Read [foundation notes](strategy/AR-AP-FOUNDATIONS-NOTES.md) before using migrations 013–016. These prerequisites are included in 0.3.0-preview, without invoice/bill UI or public write endpoints. Keep the existing MeekroDB bootstrap and use the central posting functions.

- `pl_currency_rate_enter(actor, company, book, input)` accepts decimal-string manual spot/actual rates, dated provenance, reason, request key and an optional superseded row. `pl_currency_rate_lookup(..., type, source)` selects the newest applicable date/revision from that exact source. Six rate types are reserved in schema; later types have no active calculation workflow.
- `php tools/currency-rates.php ACTOR_ID COMPANY_ID BOOK_ID INPUT.json` records a manual rate and prints only its ID/revision. Keep private input outside the web root; use sample data during development.
- `pl_save_party` and `pl_save_contact` use company/book scope, request receipts and optimistic revisions. Party tax identifiers use jurisdiction/scheme/value, and phone duplicates require acknowledgement with a reason. Bank/tag/attachment/status-transition fields cannot be mutated through generic party entry.
- `pl_activate_open_item_account(actor, company, book, account, reason)` is owner-only and rejects used or currency-designated controls. `pl_open_item_recognize(..., input)` accepts party/control/offset account IDs, currency, amount_fc, date, source_reference, description, optional rate/rate_source_id and idempotency_key.
- `pl_settle_open_item(..., input)` accepts item/bank/gain/loss account IDs, amount_fc, date, description, actual_rate or rate_source_id and idempotency_key. Exact historical basis is read from the ledger; the command receipt retains the actual settlement rate even when bank lines are in functional currency. Partial settlement, final residual and allocation reversal remain atomic with journal posting. Outgoing foreign-bank payments and activity dated before the latest item event are rejected.
- `pl_correct_source(actor, company, book, type, sourceId, expectedRevision, sourceInput, reversalDate, key, reason)` supports receipts, expenses and general journals. Pass null for UTC-today reversal; an owner may select the original date while open. Preserve source reference. `pl_source_posting_history` returns scoped immutable revisions. No correction UI is added.
- `php tools/dispatch-outbound-events.php` runs the bounded queue. The default registry is empty and makes no delivery. Tests pass explicit fake handlers; no endpoint, connector, secret or scheduled task is installed.

For a populated upgrade, back up the database and stop application/cron writers before running the existing migration command. Migration 013 temporarily exchanges blanket line-update protection for a restrictive migration-lock-owned initial metadata backfill guard; original amounts and identities cannot change. It restores blanket protection before completion. An interrupted `applying` receipt must remain blocked: restore the verified backup, or review the exact completed statements and finish under the existing migration lock. Never modify migration checksums or mark an incomplete upgrade applied.

When restoring to a different database or database account, verify that the effective source views reference the restored tables and that their retained SQL definers have the required read permission. The disposable backup verifier checks definitions, dependency schemas, effective row digests and current/original source links as well as base tables and triggers.

Focused validation: `docker compose --profile test run --rm test php tests/run.php --suite=foundations`. The complete `composer check` includes these suites. Upgrade verification accepts `fresh`, `foundation`, `core-0.1.2`, `opening-local` and `preview-0.2.1`. Run `tools/verify-currency-upgrade.php` with the same disposable-test root invocation as the existing upgrade verifier to exercise interrupted backfill, second-connection guards, recovery and legacy request replay. Only randomly created databases in `db_test` are touched by those upgrade verifiers.

These instructions apply to the modern source containing `compose.yaml`, `composer.json` and `www/phpledger`. The current source tree contains the modern application; historical code remains only in Git history. Use the public [Wiki](https://github.com/phpledger/phpledger/wiki) for visitor documentation and package availability. The published [0.3.0 foundation package](https://github.com/phpledger/phpledger/releases/tag/v0.3.0-preview) includes production dependencies; this page covers development from source.

## Start the verified environment

Use Docker Compose for PHP 8.2+ and MySQL 8.4. Preserve any existing `.env`. For a fresh checkout, copy `.env.example` to `.env` and privately set independent random development database passwords.

```powershell
docker compose up -d --build
docker compose exec -T web php www/phpledger/install/migrate.php
```

Open the local sign-in screen at `http://127.0.0.1:18200/login`. Serve only `www/phpledger/public`, never the repository root. Historical installation dumps are not part of the revival; never run them against the modern database. Use sample data and a separate development database.

Create the first administrator through the controlled command. Supply its password through standard input or a private `PL_ADMIN_PASSWORD` variable; never put the password in command arguments or committed files.

```powershell
docker compose exec -T -e PL_ADMIN_PASSWORD web php www/phpledger/install/create-admin.php --email=owner@example.test --name=Owner
```

Clear the private shell variable after use. Sign in, create a business or isolated sample, and follow setup through the first receipt or expense. Existing businesses require reviewed opening balances before posting. Installation, business onboarding and historical-data cutover are separate workflows.

## Verify changes

The Release B candidate adds scoped API/MCP and Connections. Configure OAuth private storage and `PL_PUBLIC_URL` using [Integrations](INTEGRATIONS.md). Local Compose supplies the loopback public URL and a separate private volume; keys are generated once and mounted read-only into the web runtime. Rebuild the PHP images after changing `composer.lock`. Run `php tests/run.php --suite=connections` inside the isolated test service for the focused API/OAuth/bridge suite. `php tools/export-openapi.php` writes the independent OpenAPI description using the configured application URL.

```powershell
docker compose --profile test run --rm test composer check
docker compose --profile test run --rm test composer validate --no-interaction
docker compose --profile test run --rm test composer audit --no-interaction
./tools/verify-backup-restore.ps1
```

The test profile uses `phpledger_test` in its separate `db_test` service. The restore check creates and removes only its own isolated test database. See [validation receipts](VALIDATION.md) for exact executed checks and remaining limits.

**MariaDB.** The same suites run on MariaDB by choosing the test database image, for example:

```powershell
$env:PL_TEST_DB_IMAGE = 'mariadb:10.11'
docker compose --profile test run --rm test php tests/run.php
docker compose --profile test run --rm test php tests/browser_installer_keyless_test.php
```

CI runs MariaDB 10.6, 10.11 and 11.4. Write SQL for MySQL 8.4 as before. The MeekroDB `pre_run` hook in `database_platform_functions.php` translates `FOR SHARE`, `SKIP LOCKED` and `utf8mb4_0900_ai_ci` on MariaDB. Test fixtures that configure `DB::` themselves must call `pl_database_use_dialect()`, as the update tests do.

**Release vendor.** Build the production `vendor/` for a package from the release commit's lockfile in the PHP test image, then pass it to `tools/build-package.py --vendor`:

```sh
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-scripts
```

The builder drops dependency documentation, fixtures and `vendor/bin` while keeping every licence and NOTICE file. It adds deny-all `.htaccess` files to the private folders and writes the archive with a single `phpledger/` root.

Optional browser-facing acceptance scripts are [core HTTP](../tests/http-smoke.py), [POS HTTP](../tests/pos-http-smoke.py), [demo HTTP](../tests/demo-http-smoke.py), [print HTTP](../tests/print-http-smoke.py) and [stock HTTP](../tests/stock-http-smoke.py). They use local sample records; read each script's scope and required private inputs before running it. Do not broaden their targets to production or real customer books.

**These HTTP acceptance scripts are not part of the GitHub workflow.** `.github/workflows/foundation.yml` builds the isolated `test` profile only; every `tests/*-http-smoke.py` script needs the local `web` and `db` services, so all of them — including the two 1.2 scripts — are run locally against a live Compose stack and their results recorded in [Validation](VALIDATION.md). Adding a web stack to CI is a separate change; do not describe these scripts as CI-enforced until it lands.

### Stock documents and their reports (1.2 M4)

`tests/stock-http-smoke.py` is the HTTP-level acceptance script for the four screens the Stock locations module adds in 1.2 and for the two print types registered for them. It builds one distribution business through the ordinary services — a stocked warehouse, a van with a driver, a stock issue and a gate pass — then checks over HTTP that anonymous visitors are sent to sign in; that owner and accountant read every screen; that a viewer reads them but is offered no editor, no gate-pass stamp and no settlement review, and that a forged write records nothing; that cost columns are present for the owner and **absent from the page** for everyone else; that the report's rendered grand total, per-location subtotals, location and item counts equal the service's figures and that warehouses group before vans; that a gate pass raised over HTTP is numbered but creates no stock movement and no journal; that reviewing and approving a settlement post nothing and that an accountant cannot approve one; that an id from another company, a foreign warehouse and a fixed warehouse are refused on every new route; and that all three print outputs render shell-less and form-less, with the A4 stock document carrying its gate pass.

```powershell
docker compose up -d web db
docker compose exec -T web php www/phpledger/install/migrate.php
python tests/stock-http-smoke.py
```

The committed default target is `http://127.0.0.1:18200` with the default Compose project, as in every other smoke script. `--port` and `--project` exist for an explicitly isolated stack in a parallel worktree, the way `pos-recovery-http-smoke.py` documents its own compose override; both stay loopback-only and refuse any host but `127.0.0.1`.

It does not cover desktop and phone widths. That half of definition-of-done item 2 still needs a browser fixture.

## Opening cutover, periods and bank reconciliation

These existing core workflows are also used by the accounting starter. Back up existing development data, then apply the complete versioned migration chain with `docker compose exec -T web php www/phpledger/install/migrate.php`. Local source changes do not update a published package or hosted demo.

| Route | Workflow |
|---|---|
| `/opening-balances` | Existing-business setup → balances/manual or CSV → saved validated preview → explicit confirmation → ready after cutover. Source evidence and correction history remain visible. |
| `/periods` | List periods/history, create a nonoverlapping range, close with reason, owner-only reopen. A fresh revision prevents stale changes; retries have durable receipts. |
| `/bank-reconciliation` | Preview/import statement, choose each match, inspect outstanding entries, then explicitly complete with zero adjusted difference. Consecutive statements carry the prior closing balance. |

An existing company's accounting start date is its cutover **closing date**. Bring reviewed balances through that day and date ordinary transactions afterward. This also lets a first bank statement begin the next day with the confirmed cash opening. The unpaid register is cutover evidence. In the starter, review its explicit party mappings through `/opening-conversion` before settling those debts through the shared open-item ledger; do not recreate them as newly posted invoices or bills.

CSV is UTF-8 with comma separators, a header in the exact order below, ISO `YYYY-MM-DD` dates, and decimal amounts without grouping separators/exponents. Limits are 500 data rows and 512 KiB per CSV. Zero-side amounts must be `0`; each nonzero balance/transaction uses only one side. Imports reject malformed quoting, unknown/duplicate account codes, missing data, wrong precision, repeated document/transaction identities and unbalanced totals. XLSX and automatic column/bank-format guessing are not implemented.

```csv
account_code,debit,credit
1000,1000,0
1100,300,0
2000,0,200
3000,0,1100
```

```csv
kind,account_code,party,reference,document_date,due_date,outstanding
receivable,1100,Sample customer,INV-01,2026-08-15,2026-09-15,300
payable,2000,Sample supplier,BILL-01,2026-08-20,2026-09-20,200
```

The two examples reconcile an opening cutover at 2026-09-01: cash 1,000 + receivables 300 = payables 200 + equity 1,100. Unpaid amounts are source evidence for those control balances and are never posted a second time. This opening register supports positive unpaid invoices/bills; importing opening credits or advances remains deferred. Opening stock quantities and value use the separate reviewed Inventory conversion. Use the actual scoped account codes, which may differ from the example.

```csv
date,reference,description,money_in,money_out
2026-09-02,BANK-001,Sample receipt,125,0
2026-09-03,BANK-002,Sample expense,0,25
```

For that bank example, enter opening 1,000 and closing 1,100 and explicitly confirm the first cleared baseline. The corresponding receipt/expense must already be posted through the normal services before matching; import/reconciliation creates no financial entries. Review bank-only fees or other missing transactions and record them through the normal accounting workflow before matching. A first baseline with unresolved earlier outstanding items is rejected; resolve or reconcile the earlier history first. Completion protects history from backdated postings even if a period is reopened.

An incorrectly imported draft can be cancelled with a reason after explicitly removing its matches. The original statement, rows and match/unmatch history remain; a corrected import may reuse its bank references. Completed statements remain immutable. Split or aggregate matches are not supported: each bank row matches one posted line with the same signed amount.

```powershell
docker compose --profile test run --rm test composer check
python tests/accounting-http-smoke.py
docker compose --profile test run --rm -e PL_DB_USER=root -e PL_DB_PASSWORD=local-test-root-only test php tools/verify-upgrade.php
./tools/verify-backup-restore.ps1
node www/website/build.mjs
node www/website/check.mjs
```

`accounting-http-smoke.py` is hard-limited to `http://127.0.0.1:18200`, creates isolated sample owner/viewer companies and keeps random credentials in memory/stdin. It retains its sample data for inspection. Unit/financial tests use only `db_test`; upgrade/restore scripts create, validate and remove their own randomly named databases in that disposable test service. The literal root password shown is solely the documented disposable test credential.

## Company modules

Open `/modules` in an installation to inspect required AR/AP, optional Inventory/Purchasing, the cash POS showcase and recent changes. An owner enters a reason to enable, disable or apply a reviewed optional-module version. Accountants/viewers may inspect status/history; the public demo cannot administer modules. Ordinary companies default to optional modules disabled, including after upgrade. Inventory must be enabled before Purchasing. Explicit new sample-company provisioning enables its POS showcase through the same audited service. Core accounting, AR/AP and tax configuration remain available with every optional module off; AR/AP navigation visibility is a separate owner preference.

Run the existing preflight/migrations before using new source. The original `010_module_lifecycle` adds state/audit tables without activating existing companies; the starter extends the supplied registry and adds audited visibility preferences. Retain both `006_*` migrations unchanged. A changed optional-module manifest requires owner review, and missing or mismatched migration receipts block operation. Disabling preserves source/journal history; new requests, including retries, still pass the current service gate. See [the original lifecycle contract](repository/sprint-05/MODULE-FOUNDATION.md) and the [current starter dependencies](repository/sprint-06/ACCOUNTING-STARTER.md).

Run `python tests/module-http-smoke.py` for the existing local-only HTTP assertions with sample owner/viewer books. Run `composer check` through the test container for service, concurrency and rollback tests; `./tools/verify-demo.ps1` verifies isolated sample provisioning/reset in `db_test`. Existing read API/MCP remains available; the starter adds no public financial write endpoint or machine credential.

## Definition of done for a bundled module

Adopted for the 1.2 module set (M1, [release plan](strategy/RELEASE-PLAN-1.2.md)) and applied to every bundled module from Stock locations onward, written once here rather than restated per module. A module is not ready to ship until all of the following hold:

1. **Suites green in `composer check` on MySQL 8.4 and one MariaDB image.** Run the full suite (or at minimum its own test file plus its dependency chain) against both; see [Verify changes](#verify-changes) for the `PL_TEST_DB_IMAGE` switch. A module that only passes on one engine is not done.
2. **A browser fixture or HTTP-level test per new screen.** Every route or panel the module adds needs either a `tests/*-http-smoke.py`-style script or a browser fixture (see `tests/module-http-smoke.py`, `tests/redesign_browser_fixture.php`) exercising it at desktop and phone widths, not only its underlying PHP service test.
3. **Fresh install from the exact built ZIP.** Build the package with `tools/build-package.py`, install it as `tools/verify-upgrade.php fresh` does — a clean database through the complete migration chain, a new company, and the module's own first operation — not a checkout running against a live-edited source tree.
4. **Upgrade from a populated previous-release database.** Seed data shaped like the last stable release (see the `tools/verify-upgrade.php` baselines below) and apply the module's migration; historical records, balances and reconciliation must be byte-for-byte unchanged and the migration must be a no-op on replay.
5. **Disable and re-enable with data present.** Through `pl_set_company_module`, prove that disabling with the module's data already recorded blocks new writes but keeps every historical read (balance, history, valuation, audit) intact, and that re-enabling restores write access without altering what was recorded while disabled. `tests/module_test.php`'s generic module-matrix test automates the enable/disable/re-enable half of this for every optional manifest; the module's own suite still needs the "operations with data present" half.
6. **A manifest with exact `requires`.** Pin dependency versions exactly (`pl_validate_module_registry` rejects anything else); do not use ranges. Capabilities, migrations, routes, permissions, settings, reports and API/MCP operations must all be declared, unique and validated by `pl_validate_module_registry`.
7. **A row in the compatibility matrix.** Add the module to the table in [Module roadmap](MODULE-ROADMAP.md#module-compatibility-matrix) with its `requires` and the combinations covered by the generic matrix test, before claiming it is interchangeable with core-only operation.
8. **A test for the module-side checksum-mismatch branch.** `pl_module_installed()` refuses to treat a module as usable when its migration's stored checksum does not match the file on disk (a changed or reverted migration file). Exercise that branch explicitly — see the checksum assertions in `module_test.php`'s manifest-validation test for the pattern — rather than relying on the manifest-validation test alone.

None of this is new application behaviour; it is the acceptance bar the M1 documentation, tests and `tools/verify-upgrade.php` baseline satisfy for the already-shipped Stock locations module, restated so the next 1.2 module (trading documents, M2) is held to the same bar from its first commit.

## How to add a translatable string

The translation helpers landed with M2 of the [1.2 release plan](strategy/RELEASE-PLAN-1.2.md)
(decision B3: Urdu first, then Arabic, English as the fallback catalogue). The interface strings
themselves are still hard-coded English: externalising them is M11, so most screens today have no
`pl_t()` call at all. Any new string should use the helpers.

**Write the call.** `www/phpledger/includes/functions/i18n_functions.php` is loaded by the
bootstrap and by `web_functions.php`, so `pl_t()` is available wherever a screen is rendered.

```php
<p><?= pl_e(pl_t('Nothing was posted. Your books are unchanged.')) ?></p>
<p><?= pl_e(pl_t('Posted {count} lines for {party}.', ['count' => $lines, 'party' => $party])) ?></p>
<p><?= pl_e(pl_tn('{count} open item', '{count} open items', $open, ['count' => $open])) ?></p>
```

The rules the helper enforces, and the reasons:

- **The English string is the key.** There is no message-id table to keep in step, and a string
  with no catalogue entry renders as itself. A screen never shows a key or an empty label.
- **Escaping stays with the caller.** `pl_t()` and `pl_tn()` return plain text and escape nothing,
  because the values they interpolate are usually user data and because the same string is also
  written to the CLI, to JSON and to mail. Write `pl_e(pl_t('…'))` in HTML, exactly as you already
  write `pl_e()` around any other text.
- **Placeholders are named, `{like_this}`.** Positional `%s` is not supported: translators reorder
  clauses, and a reviewer cannot tell what `%s` was meant to be. Pass `count` yourself in
  `pl_tn()` — the helper never fills it in, so a string may count one thing and name another.
- **Never build a sentence by concatenation.** `pl_t('Saved {name}.', …)` is translatable;
  `pl_t('Saved ') . $name` is not, and it breaks outright in a right-to-left locale.
- **Keep accounting values out of the sentence structure.** Format the amount with `pl_money()`
  and the date with `pl_date_label()`, then pass the formatted string in as a placeholder value.

**Add a catalogue entry.** Catalogues are English-keyed PHP arrays in `resources/lang/<locale>.php`
— see [resources/lang/README.md](../resources/lang/README.md) for the file shape, the plural-form
arrays, the `ur-PK` on top of `ur` inheritance, and the manifest `lang` directory a bundled module
or plugin uses to ship its own catalogue. English has no file: it is the source language. A locale
whose plural rules are not yet in `pl_i18n_plural_table()` needs a row there first; English and
Urdu use `one`/`other`, and Arabic's six CLDR forms are already in the table.

**Check it.** `php tests/run.php --suite=i18n` in the test container runs `tests/i18n_test.php`:
the helper tests, and a sweep that renders the routes under the `qps` pseudo-locale, which brackets
and lengthens every string that went through `pl_t()`. The sweep asserts that each page still
renders and that its `<html lang>`/`dir` follow the locale, and it **counts** the visible text runs
that are still hard-coded English and prints them:

```
i18n sweep: 27 of 29 routes rendered under the pseudo-locale; 2188 untranslated visible text runs (ceiling 2400), 0 already translated.
```

That count is the M11 backlog. The ceiling in the test may be lowered as strings are externalised;
raising it to make a change pass is not an acceptable fix.

**Setting the locale.** `pl_set_locale()` selects the locale for the rest of the process, and
`pl_locale()` reports it, defaulting to the `PL_LOCALE` environment value and then to English.
Nothing in the application calls `pl_set_locale()` yet: resolving a user's or company's stored
preference, and the screen to choose one, are M11.

The translation backlog is measured by `tests/i18n_test.php` from the template sources, not from rendered pages: a rendered page also carries data (document numbers, party and account names, rows that depend on the day), which no catalogue translates and which moved the number run to run. The source count needs no database or server, returns the same number on every machine, and its ceiling may only be lowered. A new screen written in bare English raises it, which is the failure; put the strings through `pl_t()` instead.

## How to add a help concept or a jurisdiction note

The in-app accounting guidance landed with M2b of the [1.2 release plan](strategy/RELEASE-PLAN-1.2.md)
(decisions **B66** — the application teaches accounting where the work happens, no long text blocks
on the page — and **B67** — the guidance covers the Middle East and Asia, not only Pakistan). The
component is `pl_ui_help()`; the content is `resources/guidance/`; the reader is
`www/phpledger/includes/functions/guidance_functions.php`.

**Add the concept.** One file per concept, named after its id, in `resources/guidance/concepts/`:

```php
// resources/guidance/concepts/unapplied-credit.php
return [
    'title'       => 'Unapplied credit',
    'explanation' => 'Money received that no invoice has claimed yet is not income …',
    'here'        => 'Whatever is left after this payment is allocated is held on …',
    'document'    => ['label' => 'Open the guide', 'href' => '/help'],
    'review'      => 'placeholder',
];
```

- **`explanation` is 40 to 70 words.** The test enforces both ends. Under 40 it explains nothing;
  over 70 it is the long text block on the page that B66 exists to remove. `here` is optional, at
  most 40 words, and says what *this screen* does with the concept.
- **Every string is an English source string for `pl_t()`.** The component translates and escapes
  it; the file itself never calls either. Write whole sentences and never concatenate — see "How to
  add a translatable string" above.
- **`review` stays `placeholder`** until the guidance review has passed the wording, and the bubble
  says so on screen. Change it to `reviewed` in the same change that lands the reviewed copy.
- `document` is optional and must be an in-application path; a link into `docs/` would be dead in a
  browser.

**Wire it to a screen.** `pl_ui_help('unapplied-credit')` beside the label, column heading or total
it explains. Two placement rules, both of which exist because the application's CSP forbids inline
styles and therefore forbids positioning the bubble from JavaScript:

- `pl_ui_help($id)` hangs the bubble from the inline-start edge, `pl_ui_help($id, 'end')` from the
  inline-end edge — use `end` when the trigger sits near the end of the screen — and
  `pl_ui_help($id, 'sheet')` pins it to the corner of the viewport, which is what a trigger
  **inside a `.table-wrap` or any other scrolling region** needs, because that ancestor's overflow
  would otherwise clip an absolutely positioned bubble. Under 30rem every bubble becomes a sheet
  anyway, so it cannot run off either edge of a 390px screen.
- The component renders `<details>`, which is flow content: put the call beside a heading, a cell,
  a `<div>` or a `<dt>`, and **never inside a `<p>`, a `<label>` or an `<h1>`–`<h6>`**, which take
  phrasing content only and which the browser will silently close around it.

**Add a jurisdiction note.** One file per country in `resources/guidance/jurisdictions/`, named
`PK.php`, `AE.php` and so on, keyed by the same concept ids:

```php
return ['unapplied-credit' => [
    'note'       => 'One or two sentences of local practice.',
    'status'     => 'verified',
    'source'     => 'The authority, the instrument and the paragraph.',
    'checked_on' => '2026-09-21',
]];
```

**Only a `verified` note with a source and a check date ever reaches a screen.** Anything else is
dropped by `pl_guidance_note()`, so an unfinished or unsourced note is invisible rather than wrong:
B67's rule that an unsourced local claim is worse than none is enforced in code, not left to
review. Where a jurisdiction has no note, the bubble shows the shared explanation and nothing else,
rather than implying that the shared text is that country's law. The thirteen jurisdictions are the
countries behind `pl_base_currency_options()` plus AE, SA and OM; `pl_guidance_countries()` is the
list, and a file named for anything else fails the test.

**Which jurisdiction a reader gets.** `pl_render()` calls `pl_guidance_use_company()` once per
request. The company record carries no country today, so the base currency chosen when the books
were created selects the notes; a `country_code` on the company row wins as soon as the B63/B64
registration profile provides one, and `pl_guidance_company_country()` is the only place that then
has to change. An installation can add notes the shipped catalogue does not have by pointing
`PL_GUIDANCE_PATH` at a directory of the same shape: it is searched first, and the tests use it.

**Check it.** `php tests/run.php` in the test container runs `tests/guidance_test.php`: the length
and shape of every entry, that every concept a screen asks for exists (and that asking for one that
does not throws), that jurisdiction files are keyed by a covered country, that an unverified or
unsourced note is dropped, and — over a real HTTP request — that the bubble renders, reads with
JavaScript disabled and keeps its local note to its own jurisdiction. New files under
`resources/guidance/` must also be added to `tools/package-files.json`, or they will not ship;
`python tests/package-builder-test.py` checks that.

## Repository working boundaries

### Core CSV exports

**Account ledger entry point:** `/reports/account` without an account ID now opens the company-scoped account chooser. Reports, Transactions and Journals link directly to it. Choose an account and optional date range; the existing statement service supplies opening, debit/credit movement, running and closing balances across all pages. On phones, each table row lays out its date/source, debit, credit and running balance without horizontal scrolling. Draft document totals are not account balances; statement calculations continue to include only posted journal lines. Existing `id`, `as_of`, `from` and `page` links remain supported and authorized on the server.

`GET /reports/export?report=trial-balance|account|profit-loss|balance-sheet&to=YYYY-MM-DD` downloads a CSV through the existing signed-in company/book scope. Account statements accept `account_id` and optional `from`; profit and loss requires `from`. Each report screen links the current date selection to its export. Viewers may export their authorized books. All statement pages are included, with a 10,000-movement limit that rejects oversized exports before sending any CSV. A shared book transaction keeps pages and totals coherent. CSV preserves four-place decimal strings, business dates, scope, readiness and source references; potentially executable spreadsheet text receives an apostrophe prefix. The CSV is a management preview, not an issued statutory statement.

### Consolidated installation checks

`tools/verify-upgrade.php` covers the existing historical baselines; `tools/verify-starter-upgrade.php` specifically exercises the published 0.3.0-to-starter transition. Run them with the disposable-test root invocation above. Each verifier creates/removes its own random database and preserves existing test/development data. The two `006_*` files have distinct full migration identities from separate branches; preserve both names and original checksums. The migration runner uses full filenames, not just numeric prefixes. The current starter chain ends at `025_tax_price_mode`.

The PowerShell restoration check explicitly uses UTF-8 for native process input/output so non-ASCII descriptions survive dump/import. Run restore checks after the test suite completes, without concurrent database writes.

Read [architecture](ARCHITECTURE.md), [contribution guidance](../CONTRIBUTING.md) and [repository instructions](../AGENTS.md). Reuse the bootstrap, MeekroDB helpers, explicit routes and central posting service. Preserve historical files and migration receipts. Configuration, dependencies and storage remain outside the public document root.

The [demo runbook](DEMO.md) covers separate sample storage, restricted runtime permissions, hourly UTC reset and deployment. The [roadmap](ROADMAP.md) preserves future language/formatting/FX, imports, regional accounting, inventory, production POS and industry modules. Development checks are not accounting sign-off, observed usability evidence or a stable-release claim.

### Published 0.5-to-0.6 data verification

`tools/verify-preview-upgrade.php` has `seed` and `check` phases. Run both in the
same disposable test container using the local test root account. Before `seed`,
extract `git archive v0.5.0-preview www/phpledger resources tests` to the container's
`/tmp/phpledger-preview-05` and link its `vendor` directory to the test image's
installed vendor directory. `seed` uses the published bootstrap and fixture
builders; `check` uses the current checkout. Both phases reject non-test effective
configuration. The script creates its own random schema and removes it after the
check; do not run it against a hosted database. See the local redesign upgrade
receipt for fixture coverage and limitations. The new migration chain currently
continues through `031_posting_source_lookup`; historical migration checksums stay
unchanged.
