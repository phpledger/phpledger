# Stock locations

Stock locations is a bundled optional module (`resources/modules/inventory-locations.json`, migration `034_inventory_locations`) that extends the shared [[Inventory|Accounting-and-Reports]] service with further warehouses and vans, per-location balances and transfers between them. It shipped in 1.1.1 (unannounced; corrected in 1.1.2) and is documented here for 1.2.

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

## What is planned in 1.2

- **Stock documents.** Transfers grow from a linked movement pair into a numbered stock-document family (stock issue, re-issue, stock return and gate passes), sharing the number series and print pipeline being built for trading documents (owner decision B33).
- **Per-location reports.** The manifest's `stock-by-location` report — stock value and items by location, and aggregate reports across locations — is implemented, not removed (owner decision B35).
- **Van distribution.** 1.2 must be able to simulate a multi-warehouse, multi-driver/van distribution business, including a POS for counter sales, so the module roadmap's later route/van/settlement plugin work moves into 1.2 as bundled behaviour over Stock locations (owner decision B34).
- Warehouse selection on invoices and credits, and API/MCP read exposure of warehouses and transfers, move from 1.3 into 1.2 (owner decision B36).

See the [1.2 release plan](../strategy/RELEASE-PLAN-1.2.md) and the [[module roadmap|Module-Roadmap]] for the full sequence and gates.
