# Decisions the owner must take: distribution documents and UX

**By rule B53 (DECISION-REGISTER.md), this document defaults every recommendation to TAKEN, retains alternatives, and states reversal paths.**  
**Decision date: 21 September 2026.**

Each item is one question with options and a recommendation, following B37 ("trading-document accounting policies are configurable in the Admin area, decided report by report" and "the owner leans toward transaction IDs as the numbering basis"). See [DOCUMENT-MODEL.md](DOCUMENT-MODEL.md) for the mechanics each option maps to.

## 1. Numbering scheme per document type

**Options:** (a) one global running number across all stock-document kinds; (b) a separate series per kind per warehouse (`GRN-WH1-000042`, `ISS-VAN3-000017`); (c) transaction-ID-based, i.e. the number is derived from the underlying row ID with a kind prefix, no separate series table.

**Taken:** (b) with the prefix format itself configurable in Admin, consistent with B37's "series format" settings screen — matches how real warehouses number GRNs and gate passes per site, and lets a van's issue series stay meaningful even with many vans. Where the owner's transaction-ID lean (B37) is preferred for simplicity, fall back to (c) as the default and let (b) be the configured override.

**Reversal path:** Change the prefix format or default series mode via Admin settings; the series table schema persists, allowing the owner to reconfigure without rebuilding the document layer.

## 2. Do gate passes post anything?

**Options:** (a) gate passes never post or move stock — pure permission/paper record referencing a stock document; (b) a gate pass can exist standalone (e.g. sending a demo unit out with no linked stock document) and does move a placeholder quantity.

**Taken:** (a). RESEARCH.md found gate passes are universally paper/permission documents in the sources reviewed; keeping them non-posting avoids a second bookkeeping surface for the same movement and matches the existing single-stock-funnel design (`pl_inventory_record()`). A standalone "demo/loan" gate pass with no company stock document is a documented gap for the pilot to flag if it turns out to matter.

**Reversal path:** Ask the owner before building if standalone gate passes need to post; the change affects the settlement contract and stock-record logic, requiring re-specification and schema review.

## 3. Is a van sale an invoice or a POS receipt?

**Options:** (a) always an AR invoice (posts to AR, requires a customer, supports credit natively); (b) always a POS-style immediate cash receipt (posts cash/revenue directly, no AR leg); (c) both, selected per sale — cash sales use the POS receipt path, credit sales use the AR invoice path, both queued through the same device sub-ledger and settled together.

**Taken:** (c). Van sales are mixed cash/credit in the researched practice (route accounting, secondary sales tracking); forcing one path either loses same-day cash simplicity or loses credit tracking. The device log already carries a cash/credit flag per event (§3 of DOCUMENT-MODEL.md); settlement posts the correct document type per line.

**Reversal path:** Change via the settlement rule for determining cash vs. credit posting; the per-line flag is already captured, allowing rule reconfiguration without schema rework.

## 4. Credit vs cash on the road

**Options:** (a) no credit allowed from a van — all van sales are cash, credit sales route through a separate order/invoice workflow back at the office; (b) credit allowed on the road up to a configured per-customer or per-driver limit, checked at settlement (not in real time, since the device is offline-capable); (c) credit allowed with no limit check.

**Taken:** (b). Matches Pakistani distributor reality (traders extend informal credit routinely — see [pakistan-sme-bookkeeping-reality.md](../../../strategy/research/pakistan-sme-bookkeeping-reality.md)) while keeping the check where it belongs: at settlement/review, per B22's existing "the server rechecks parties, prices, stock, periods and duplicates" contract, not trusted to the offline device.

**Reversal path:** Enable or disable credit limits via Admin settings per customer, driver, or company; set limits to zero to disallow credit, or remove enforcement to allow unlimited credit.

## 5. Returns to van vs warehouse

**Options:** (a) a customer return during the route goes back into the van's own stock (sellable) or is flagged damaged and held on the van until end-of-day; (b) a customer return is always routed to the fixed warehouse, never re-added to van stock same-day.

**Taken:** (a) for sellable-condition returns re-added to van stock (matches driver reality — a returned case is still sellable to the next stop), with damaged/expired returns flagged and moved to the warehouse only at end-of-day settlement via the "stock return (from van)" document. This needs a condition field on the return event, which the device log should already carry per §3.

**Reversal path:** Change via the settlement workflow for return-handling rules or condition flags; the device log structure persists, allowing reconfiguration of how returns are routed without re-instrumentation.

## 6. Who approves a settlement?

**Options:** (a) any user with warehouse write access; (b) a distinct `settlement.approve` permission distinct from ordinary posting rights, required before a device batch converts to journals; (c) automatic settlement with no human approval when the batch has zero flagged exceptions.

**Taken:** (b), with (c) as a later optimisation once the pilot has evidence that "zero exceptions" reliably means "safe to auto-post." ARCHITECTURE.md already specifies that "an authorised user resolves the flags" before the batch posts — this decision only names the permission and whether it can ever be skipped. Recommend never skipping it in 1.2; B22 explicitly says this form "does not bypass the review policy."

**Reversal path:** Enable automatic settlement in Admin once pilot evidence justifies it; disable human approval by removing the permission requirement or setting a zero-exception auto-post flag.

## 7. Print formats: A4 or 80mm

**Options:** (a) every stock document (GRN, issue, return, transfer note, gate pass) is A4 only; (b) every document has both an A4 and an 80mm variant, selectable per company/warehouse; (c) A4 for warehouse-to-warehouse and office documents, 80mm only for van-facing documents (load sheet, gate pass, counter/van sale receipt).

**Taken:** (c). A4 fits desk printing for GRNs/transfer notes/gate passes at a fixed warehouse; 80mm thermal fits a van's or counter's portable printer for the load sheet and sale receipt, matching the printable-templates item already bundled in 1.2(b).

**Reversal path:** Add or change print formats per document type or warehouse in the Admin print-settings configuration; new templates can be registered without schema changes.

## 8. What the counter POS screen shows

**Options:** (a) the existing generic sample-cart POS unchanged, just made warehouse-aware; (b) a distributor-specific counter screen showing the selected warehouse's real stock (with a "van" warehouse excludable from the counter's product list), barcode/pack-and-unit entry from 1.2(b), and a running cash/credit toggle per sale.

**Taken:** (b). B34 explicitly asks for a counter-sales POS "over real stock," not the existing illustrative cash-POS sample cart (ROADMAP.md/MODULE-ROADMAP.md both flag the current POS as non-production/illustrative); this is the one POS change 1.2 needs beyond making warehouse selection possible.

**Reversal path:** Ask the owner before building if a change is needed; reverting to (a) affects the 1.2 scope, device-ledger integration, and settlement post-rules, requiring bounded rework.

## Headline decision list

1. Numbering: per-kind-per-warehouse series (Admin-configurable format), recommended over pure transaction-ID numbers.
2. Gate passes never post or move stock — reference only.
3. Van sales support both a POS-style cash receipt and an AR invoice, chosen per sale line.
4. Credit on the road is allowed up to a limit, checked at settlement, not in real time.
5. Sellable returns can re-enter van stock same-day; damaged/expired returns move to the warehouse at settlement.
6. Settlement requires a distinct approval permission; no auto-post in 1.2.
7. A4 for warehouse documents, 80mm for van-facing documents.
8. The counter POS becomes a real-stock, warehouse-aware distributor screen, not the illustrative sample cart.
