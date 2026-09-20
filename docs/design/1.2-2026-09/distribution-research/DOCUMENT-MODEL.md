# Proposed model: distribution documents over the existing services

Maps [RESEARCH.md](RESEARCH.md)'s document set onto `pl_inventory_movements` (`www/phpledger/includes/functions/inventory_functions.php`), AR documents (`ar_ap_functions.php`), warehouses, and the device sub-ledger (B22, [ARCHITECTURE.md](../../../ARCHITECTURE.md#queued-entry-and-synchronisation)). Implements B33 ("transfer documents" = the real stock-document family), B34 (multi-warehouse/multi-van simulation with counter POS), B35 (location and aggregate reports), B36 (warehouse on invoices/credits and API reads).

## 1. Numbered stock documents over `pl_inventory_movements`

Today `pl_inventory_movements` already carries `kind` (`receive`, `issue`, `transfer_out`, `transfer_in`, …), `warehouse_id`, `source_type`, `source_reference`, `source_document_id`. The proposal adds a thin **stock document** layer above the movement rows — one row per document (header) with its own number series, printable template and a set of movement rows it produced — the same relationship AR documents already have to journals.

| Real-world document | PHP Ledger kind | Movement effect | Number series | Print |
|---|---|---|---|---|
| Goods receipt note | `receive` | one `receive` leg into a warehouse | `GRN-{seq}` per warehouse | A4 receiving copy |
| Stock issue (to van) | `issue_to_van` (new movement kind, mechanically a `transfer_out`/`transfer_in` pair warehouse→van) | `transfer_out` (warehouse) + `transfer_in` (van) | `ISS-{seq}` per warehouse | A4 or 80mm load list |
| Re-issue | same kind, `original_movement_id` links to the day's first issue | same pair | same series, suffixed `-R1`, `-R2`... | same |
| Stock return (from van) | `van_return` (transfer pair van→warehouse) | `transfer_out` (van) + `transfer_in` (warehouse) | `RTN-{seq}` per warehouse | A4 return receipt |
| Stock return (from customer) | not a stock-document kind — an AR credit note's stock leg (`pl_inventory_receive` under the credit note's `source_type`) | `receive` | AR credit-note series | AR credit note print |
| Gate pass (in/out, returnable/non-returnable) | no movement row — a permissions/print record referencing a stock document's `source_reference` | none | `GP-{seq}` per warehouse | A4 or 80mm gate copy |
| Delivery challan | print variant of stock issue / stock transfer note, or a stand-alone paper-only record when no invoice exists yet | as the underlying stock document, or none | shares the issue/transfer series | A4 |
| Load sheet | print/report view of a stock issue to a van, grouped by route | (same as stock issue) | (same as stock issue) | A4 or 80mm |
| Settlement sheet | a report over the day's device-sub-ledger batch (see §3), not a movement-producing document | none directly | n/a | A4 |
| Stock transfer note (warehouse↔warehouse) | existing `pl_inventory_transfer()` | `transfer_out` + `transfer_in` | `TRF-{seq}` (already implemented) | A4 |
| Counter sale receipt | AR document, kind `pos_receipt`, or the existing shop POS checkout, warehouse-aware | `issue` via the AR document's stock leg | POS receipt series | 80mm receipt |
| Stock count sheet | not a posting document; feeds `pl_inventory_adjust()` (a needed addition, symmetric to receive/issue) | `adjust` | `ADJ-{seq}` per warehouse | A4 worksheet + adjustment |

Gate passes are deliberately **not** movement rows: they are a lightweight header table (`pl_gate_passes`: number, type in/out, returnable flag, warehouse, party, reference to the stock document, expected/actual return date, authoriser, security in/out timestamps) that references but never substitutes for the stock document that actually moves value. This matches RESEARCH.md's finding that gate passes are permission/paper only.

## 2. A van as a warehouse

`pl_inventory_warehouses` (code, name, `is_default`, `is_active`) already models an arbitrary stock location with its own moving-average cost via `pl_inventory_balance(..., $warehouseId)`. A van is simply a warehouse row with a `kind` discriminator (`fixed` vs `mobile`) added to the table, carrying a driver/vehicle reference and, later, a route. No new stock-valuation logic is required: `pl_inventory_transfer()` already computes carrying value moved warehouse→warehouse; warehouse→van uses the same function with the van as `to_warehouse_id`. The `pl_inventory_movement_warehouse()` guard that requires the `inventory-locations` module for a non-default warehouse extends unchanged to vans, since a van is never the book default.

Multiple concurrent vans and multiple fixed warehouses are the same table: B34's "multi-warehouse, multi-driver/van" scenario is modelled as N `pl_inventory_warehouses` rows of kind `fixed` and M of kind `mobile`, each with its own balance, its own transfer history to/from any other warehouse (fixed or mobile), and its own stock-by-location report row.

## 3. The driver's day through documents and B22 settlement

1. **Morning load.** Warehouse issues stock to the van: a `stock issue` document (kind `issue_to_van`) creates the `transfer_out`/`transfer_in` pair. A `gate pass` (outward, returnable) references it for the security desk. Optionally a `load sheet` print groups the issue by the day's planned route.
2. **On the road — sales.** Each counter/van sale is a queued event in the device sub-ledger (B22): item, quantity, price, cash or credit, customer if credit. The device holds an append-only, device-signed log with per-device sequence and both device and server-received time, exactly as ARCHITECTURE.md's existing description requires; PHP Ledger does not post per-sale in real time.
3. **On the road — mid-day re-issue.** If the van sells out, a second `stock issue` (re-issue) against the same driver/day adds another transfer pair, linked via `original_movement_id`.
4. **End of route — returns.** Unsold stock becomes a `stock return (from van)` document, transfer pair van→warehouse, gate-pass inward matching the morning's outward pass.
5. **Settlement.** The device closes its session; the batch of queued sale/collection/return events enters review exactly as ARCHITECTURE.md describes: the server rechecks parties, prices, stock, periods and duplicates, marks exceptions as flagged lines, and an authorised user resolves them. The **settlement sheet** is the report rendering that batch — opening load, sold, returned, expected cash/credit, actual cash — before the supervisor approves. On approval the batch posts as one set of journals through the ordinary posting service, with the batch identity as the idempotency key, business date the settlement date (never the device clock), and ledger document numbers assigned at settlement from the per-type series, while the device's own human-readable references (printed van sale slips) are kept as source references.
6. **Reconciliation identity.** Loaded quantity − sold quantity − returned quantity must equal zero per SKU per van per day; a non-zero result is a stock-count/adjustment case (stock count sheet → `pl_inventory_adjust()`), not silently absorbed into the settlement.

This flow requires no new posting primitive: it composes existing `pl_inventory_transfer()` calls (steps 1, 3, 4) with the existing device-sub-ledger batch-settlement contract (step 5), which already specifies exceptions, review and the settlement-date rule.

## 4. Counter sales POS

The existing `GET /pos`, `POST /pos/checkout` flow (ARCHITECTURE.md, "General-shop POS") already does atomic cash receipt/journal/item snapshot from a priced cart. For 1.2 it becomes warehouse-aware: checkout takes a `warehouse_id` (defaulting to the counter's fixed warehouse), stock-out happens from that warehouse's balance, and the printed receipt becomes the "counter sale receipt" of RESEARCH.md. Credit counter sales route through the AR document path instead of the cash-POS path (see DECISIONS.md).

## 5. Reports (B35, B36)

| Report | Basis |
|---|---|
| Stock value and items by location | `pl_inventory_balance()` grouped by `warehouse_id`, one row per warehouse (fixed and mobile) |
| Van stock (subset of the above) | Same query filtered to `kind = mobile` warehouses; a driver/route view |
| Aggregate valuation across locations | `pl_inventory_balance()` with `warehouse_id = NULL` (already supported: "a NULL warehouse filter means every warehouse") summed against the per-location report for a reconciliation check |
| Driver settlement report | The batch review/settlement record from §3: opening load, sales, returns, expected vs actual cash, exceptions |
| Sales by staff/area | AR document rows joined to the sales-staff and area dimensions already bundled in 1.2 item (b), summed by document date/warehouse |

## 6. What's genuinely new vs reused

- Reused unchanged: `pl_inventory_warehouses`, `pl_inventory_transfer()`, `pl_inventory_receive()`, `pl_inventory_issue()`, `pl_inventory_balance()`, AR document services, the device sub-ledger batch/settlement contract.
- New: a `kind` discriminator and driver/vehicle reference on `pl_inventory_warehouses`; a stock-document header table (number series + print + gate-pass linkage) over existing movement kinds; `pl_inventory_adjust()` for stock-count corrections (symmetric to `receive`/`issue`, currently absent); a `pl_gate_passes` table; the settlement-sheet report over the existing B22 batch.
