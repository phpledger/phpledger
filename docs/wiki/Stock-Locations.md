# Stock locations

Stock locations is a bundled optional module (`resources/modules/inventory-locations.json`, migrations `034_inventory_locations` and `038_stock_documents`) that extends the shared [[Inventory|Accounting-and-Reports]] service with further warehouses and vans, per-location balances, transfers between them, and — from 1.2 — a numbered stock-document family, a van settlement review and the stock-by-location reports. It shipped in 1.1.1 (unannounced; corrected in 1.1.2); the 1.2 additions are described under [What 1.2 adds](#what-12-adds).

## What the module does

**A permanent default warehouse per book.** Every book carries one `DEFAULT` warehouse, provisioned automatically the first time Inventory records a movement. It exists whether or not Stock locations is enabled, so historical stock never loses its location. The default warehouse's `is_default` flag is immutable; it cannot be renamed away from being the default or deactivated.

**Warehouses and vans.** With the module enabled, the owner or accountant can add further warehouses (a second store, a van, a consignment location) through **Modules → Inventory → Locations**. Each has a code, a name and an active flag; codes are permanent identity once saved, edits go through the same revision-checked, idempotent pattern as other masters, and only company members with write access can create or edit them.

**Transfers at carrying value, no journal.** A transfer moves a quantity of one product from one active warehouse to another as a linked pair of movements (an issue at the source, a receipt at the destination) at the source's current weighted-average carrying value. Because the value leaving one location equals the value arriving at the other, a transfer does not post a general-ledger journal — the total inventory valuation and its GL reconciliation are unaffected. Transfers are idempotent, immutable once recorded, require an open accounting period and both warehouses active, and are rejected between a warehouse and itself.

**Per-location balance, history, valuation and counts.** Every inventory read that accepts a warehouse — balance, movement history, valuation and stock counts — can be scoped to one warehouse or left aggregated across all of a company's warehouses. A stock count or value adjustment posted against a warehouse only reconciles that warehouse's expected quantity/value; the aggregate valuation-to-ledger reconciliation still ties to the single inventory account.

**Returns pinned to the original warehouse.** A stock return (a purchase return, or an AR document return/credit through `pl_inventory_return`) must reference the same warehouse as the original movement it returns against. The service rejects a return submitted for a different warehouse rather than silently moving stock.

**Behaviour when disabled with non-default stock.** Disabling the module blocks new operations against any warehouse other than the default — creating or editing a warehouse, and any receipt, issue, transfer, return, count or value adjustment addressed at a non-default warehouse, is refused with a "disabled" error. Movements already recorded against non-default warehouses remain fully readable (balance, history, valuation) and keep their original warehouse on the record; nothing is deleted, hidden or reassigned to the default warehouse. Operations that omit a warehouse continue to use the default warehouse whether or not the module is enabled. Re-enabling the module restores write access to every existing warehouse exactly as it was left.

## Enabling it

1. Sign in as the company owner (only the owner can change modules).
2. Go to **Modules**.
3. Confirm the **Inventory** module is enabled — Stock locations depends on it.
4. Enable **Stock locations**, giving a reason for the audit trail.
5. Add warehouses or vans under the new **Locations** panel on the Inventory screen.

Disabling follows the same screen and keeps every historical record described above.

## What 1.2 adds

Migration `038_stock_documents` builds a document layer over the movements already described above. Nothing about transfers, carrying value or the default warehouse changes; what is new is numbering, printing and paperwork.

### A van is a location with a driver

Every stock location now has a **kind**: a *warehouse or store* (`fixed`) or a *van or mobile location* (`mobile`). A van names the driver or salesman who carries its stock, and optionally the vehicle registration and the route it runs. The kind is permanent, exactly as the code is: a building never becomes a van, and a van never becomes a building — create a separate location instead. The book's default warehouse is always a building, so everything that relied on the default warehouse behaves exactly as before.

A van's stock is still **your** stock. Issuing goods to a van does not reduce what the business owns, and it writes no ledger entry; only a sale from the van does.

### Numbered stock documents

| Document | What it does | Number |
|---|---|---|
| **Stock issue** | Loads a van from a warehouse. One transfer pair per line, at the warehouse's average cost. No journal. | `ISS-2026-000001` |
| **Stock re-issue** | A mid-day top-up for the same van, linked to the morning's issue. | `RISS-2026-000001` |
| **Stock return from van** | Unsold stock going back to the warehouse at the end of the route. | `RTN-2026-000001` |
| **Gate pass** | A permission record for the security desk. **Moves no stock and posts nothing.** | `GP-2026-000001` |

Each kind has its own running number, allocated from the same series machinery as invoices and bills. Set the prefix, the width, the year segment and the reset rule per business and book in **Admin → Numbering**, alongside the trading documents. A number is allocated once, recorded immutably, and never reused — and the document, its lines and its gate pass cannot be edited or deleted afterwards. Corrections are recorded as the opposite document, not as a change to the original.

A **warehouse-to-warehouse** move remains the plain transfer on the Products & stock screen; the document family is for the van side of the business.

### Gate passes

A gate pass is paper, not bookkeeping. It names the stock document that actually moved the goods, states whether the goods are expected back (a returnable pass must say when), and carries the party, the vehicle, the driver and the purpose. The security desk stamps it out of the gate and back in, once each. It creates no stock movement, no journal and no document line — so the gate can verify a load without being shown the accounting record.

Print it on its own A4 gate copy, which lists the items of the document it covers.

### The driver's day

1. **Morning load** — a stock issue from the warehouse to the van, with an outward returnable gate pass.
2. **On the road** — the van's sales reduce its stock through the ordinary sale documents. A customer return in sellable condition goes straight back into van stock; damaged or expired stock comes back to the warehouse at the end of the day.
3. **Mid-day re-issue** — a second issue against the same van, linked to the morning's load.
4. **End of route** — a stock return from the van for whatever is left, matching the morning's outward pass.
5. **Settlement** — **Inventory → Van settlement** shows one van and one day: opening, loaded, sold, customer returns, returned, the closing stock those explain, and the actual closing stock. Where they differ, the sheet says so per item.

The settlement **posts nothing**. It reconciles the goods; the money is carried by the sale documents the van raised, which post through the ordinary posting service as they always did. A day that does not reconcile cannot be approved: record the stock count or adjustment that explains the difference first. A settlement is never allowed to absorb a difference silently.

**Approving a settlement is a separate permission from recording one.** Until user permissions ship, only the business owner can approve; an accountant can record stock documents and review the day but not approve it. An approved settlement is immutable.

### Reports

**Inventory → Stock by location** (also on the All reports screen) lists every location — warehouses first, then vans — with its own subtotal, the aggregate quantity the business holds of each item everywhere, and a grand total. Filter it to a date, to warehouses or vans only, or to one item. Below the groups, the across-locations aggregate is reconciled to the grouped totals and to the inventory control accounts; only the unfiltered, all-locations view ties to the ledger, and the report says so when you filter it.

*Low* marks a location holding less than a tenth of what all locations hold of that item. It is a derived attention flag: 1.2 has no reorder-level field, and the report does not invent one.

**Cost columns are a separate permission.** Until user permissions ship, only the business owner sees value at cost and the ledger reconciliation; everyone else sees quantities and value at sale price. The cost figures are withheld by the service, not merely hidden on screen, so a machine read cannot see them either.

### Printing

- **Stock document, A4** — the warehouse's copy, with the gate pass alongside the items and signature lines for the store keeper, the driver and gate security.
- **Stock document, 80 mm** — the van-facing load list, for the portable printer that travels with the driver.
- **Gate pass, A4** — the gate copy.

### Reading it from outside

An authorised full-access connection can read `warehouses` (including each van's driver, vehicle and route) and `stock_transfers` (matched out/in pairs at carrying value, with the stock document number when one raised them) over the same API and MCP contract as every other read. A report-only connection cannot: these are transaction-level records. Both reads stay available when the module is disabled, like every other stock read.

### When the module is disabled with van stock present

Nothing is deleted, hidden or moved to the default warehouse. Existing stock documents, gate passes, settlements, van balances and the by-location report all stay readable. New stock documents, gate passes and settlement reviews are refused until the owner enables the module again, and the number series pick up exactly where they left off.

## Still open

- Van sales support both a cash receipt and a credit invoice, chosen per sale, and credit limits are checked at settlement rather than on the road (research decisions 3 and 4). The sale documents themselves are the trading-document work, not this module.
- A standalone gate pass with no stock document behind it (a demo unit going out on loan) is not supported; the research recorded it as a gap for the pilot to confirm.
- Automatic settlement for a batch with no exceptions stays out of 1.2 (research decision 6).
- **Warehouse selection on an invoice** (owner decision B36) is already honoured on the stock side: an invoice line's `warehouse_id`, or the document's, decides which location the goods leave. The field on the invoice itself belongs to the trading-document work, which owns the document shape and its editor.

See the [1.2 release plan](../strategy/RELEASE-PLAN-1.2.md), the [distribution research](../design/1.2-2026-09/distribution-research/RESEARCH.md) and its [document model](../design/1.2-2026-09/distribution-research/DOCUMENT-MODEL.md), and the [[module roadmap|Module-Roadmap]] for the full sequence and gates.
