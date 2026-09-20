# Distribution documents: how real warehouses and distributors work

Research for 1.2 items (a)/(b)/B33/B34/B35 (owner decisions, [DECISION-REGISTER.md](../../../strategy/DECISION-REGISTER.md) sections H, L). Sources: public GST/delivery-challan guidance (India, the closest documented practice to Pakistani distributor custom), gate-pass management vendor documentation, Odoo and ERPNext inventory documentation, and FMCG/pharma van-sales vendor material including a Pakistan-specific distributor system. Nothing here is confirmed against a specific Pakistani distributor's paper trail; it is the documented common practice this design should test against real users during the pilot.

## The document set

**Goods receipt note (GRN).** Raised by the warehouse/store keeper on receiving stock, from a purchase or an inter-warehouse transfer. Records item, quantity, condition and the receiving warehouse, checked against the purchase order or transfer note. Numbered per warehouse or company-wide series. Printed as a receiving copy, often signed by the driver/supplier as proof of delivery. **Moves stock in** (quantity and value) and is the trigger for a purchase invoice's stock leg; it does not itself post a journal in most systems — the purchase invoice/bill does. [ERPNext Purchase Receipt](https://docs.frappe.io/erpnext/stock-transactions), [Odoo two-step receipt](https://www.odoo.com/documentation/17.0/applications/inventory_and_mrp/inventory/shipping_receiving/daily_operations/receipts_delivery_two_steps.html).

**Stock issue (to van/driver).** Raised by the warehouse when loading a van or driver for the day's route, listing SKUs and quantities against a load plan or standing route list. Numbered per warehouse, often called a "load sheet" in FMCG (see below). Printed as the driver's loading list and a warehouse gate copy. **Moves stock** — value leaves the warehouse location and enters the van location — but does not post a journal by itself when van and warehouse are both company-owned stock locations: it is an internal transfer, not a sale. [Odoo internal transfers](https://www.dasolo.ai/blog/odoo-data-api-5/odoo-stock-picking-model-guide-161), [ERPNext Stock Entry](https://docs.frappe.io/erpnext/stock-transactions).

**Re-issue.** A second stock issue against the same route/driver during the day (a top-up when the van sells out) or a same-day correction to an incomplete first issue. Same numbering and mechanics as stock issue: it moves stock and value from warehouse to van, no separate journal.

**Stock return (from van).** Raised at end-of-route or end-of-day when the driver returns unsold stock to the warehouse. Lists item, quantity and condition (sellable vs damaged/expired). Numbered per warehouse. Printed as a return receipt co-signed by driver and store keeper, and is the counterpart entry that closes the day's load-sheet quantity. **Moves stock back**, van to warehouse; no journal if both are internal locations — the movement reconciles the driver's day (loaded − sold − returned should be zero).

**Stock return (from customer, i.e. sales return).** Raised when a customer returns goods already invoiced — spoilage, wrong item, short expiry. In GST/tax practice this is the "sales return challan" type of delivery challan, and if a tax invoice was already issued the correction is a credit note, not a plain challan. [Delivery challan types](https://www.mastersindia.co/blog/gst-delivery-challan/), [Refrens on GST delivery challans](https://www.refrens.com/grow/delivery-challan-gst/). **Moves stock in** and, because it reverses a sale, **moves value and posts a journal** — it is the AR credit note's stock leg, not a bare stock document.

**Gate pass (inward/outward, returnable/non-returnable).** A security/permission document authorising goods to physically cross the gate, independent of whether a stock document exists yet. An outward returnable gate pass covers goods leaving and expected back (van loads, demo stock, goods sent for repair); an outward non-returnable gate pass covers goods leaving for good (sales dispatch, scrap); inward returnable/non-returnable mirror this for goods entering. Typical fields: pass number, date/time, type, party, reference document, items, quantity, purpose, expected return date (mandatory if returnable), vehicle/transporter, authoriser, and a security-desk out/in checkbox. [Gate pass field list](https://www.usetouchpoint.com/non-returnable-gate-pass-management.html), [returnable vs non-returnable](https://www.cryotos.com/blog/material-gatepass-rgp-nrgp). **Gate passes are permission/paper only** — they do not move stock or post value themselves; they reference the stock document (issue, transfer, return) that does the actual movement, and exist so the gate guard can verify a movement is authorised without seeing the accounting record.

**Delivery challan.** The transport document accompanying goods in transit without necessarily being a sale — used for stock sent for job work, goods sent on approval, and inter-branch stock transfers, as well as immediate accompaniment of a tax invoice. It documents movement, not ownership transfer or tax liability by itself. [Delivery challan vs invoice](https://letstranzact.ai/blogs/delivery-challan-vs-invoice-guide), [gimbooks](https://www.gimbooks.com/blog/delivery-challan-and-format/). In distribution use it is often the same paper as the stock issue/load sheet, printed for the vehicle. **Moves stock** when it is the record of movement (e.g. inter-branch), but where a tax invoice already exists for the same goods the challan is a paper/logistics copy layered on a document that already posted.

**Load sheet.** FMCG/pharma-specific term for the driver's start-of-day stock issue document: every SKU and quantity loaded onto a specific van/driver for that route, usually generated from a standing route or beat plan. It is the same mechanics as "stock issue to van" above, given a trade name because van sales vendors treat it as the day's opening state. [FieldAssist DMS guide](https://www.fieldassist.com/blog/distribution-management-system-dms-guide-2025), [SAMS Online, Pakistan](https://www.sams.solutions/distributor-management-system-fmcg). **Moves stock**, no journal (internal transfer), unless the business model bills the van immediately as a sale (see Decisions).

**Settlement sheet.** The end-of-day reconciliation for one driver/van: opening load, quantities sold (against invoices/receipts raised during the day), quantities returned, expected cash and credit, and actual cash handed in. It is the record a supervisor checks before releasing the driver and is the natural trigger for the device sub-ledger settlement described in [ARCHITECTURE.md](../../../ARCHITECTURE.md#queued-entry-and-synchronisation) (decision B22): the day's queued sale/return/collection events post as one batch at settlement. [Route accounting / DSD](https://support.pepperi.com/hc/en-us/articles/221824427-Route-Accounting-Van-Sales-Multiple-Warehouses-), [SalesOn van sales](https://saleson.co.in/van-sales). **The settlement sheet itself is a reconciliation report, not a posting document** — the postings are the individual sale, return and receipt documents it summarises, each posted (or batch-posted) through the ordinary journal interface.

**Stock transfer note between warehouses.** A numbered document moving stock from one warehouse to another (not to a van), typically with an outbound leg at the source and an inbound leg at the destination, sometimes travelling in transit as its own state. [ERPNext material transfer](https://docs.frappe.io/erpnext/stock-transactions), [Odoo internal transfer picking, code 'internal'](https://www.dasolo.ai/blog/odoo-data-api-5/odoo-stock-picking-model-guide-161). **Moves stock and value** between locations at the existing per-location moving-average cost; no journal, because ownership does not change.

**Counter sale receipt.** The POS document for an over-the-counter cash sale at the warehouse or a fixed counter, distinct from van/route sales. Immediate stock-out and immediate payment in one document. **Moves stock, moves value, posts a journal** (cash/bank debit, revenue credit, COGS/inventory movement) at the moment of sale, same as any POS checkout.

**Stock count sheet.** The physical-count worksheet used for a cycle count or full stock take at a warehouse or van, listing system quantity vs counted quantity by SKU. **Does not move stock by itself** — it is the input to a stock reconciliation/adjustment document, which is what actually posts the correcting movement (and journal, if the adjustment has value). [ERPNext Stock Reconciliation](https://docs.frappe.io/erpnext/stock-reconciliation).

## Summary: which documents do what

| Document | Moves stock | Moves value/posts journal | Nature |
|---|---|---|---|
| Goods receipt note | Yes (in) | No (bill/invoice posts) | Stock document |
| Stock issue (to van) | Yes (out of warehouse) | No | Stock document (internal transfer) |
| Re-issue | Yes (out of warehouse) | No | Stock document (internal transfer) |
| Stock return (from van) | Yes (in to warehouse) | No | Stock document (internal transfer) |
| Stock return (from customer) | Yes (in) | Yes (credit note) | AR document with stock leg |
| Gate pass | No | No | Permission/paper only |
| Delivery challan | Sometimes (if the movement record) | No | Logistics/paper, or stock document if inter-branch |
| Load sheet | Yes (same as stock issue) | No | Stock document, FMCG name for the issue |
| Settlement sheet | No | No (summarises documents that do) | Reconciliation report |
| Stock transfer note | Yes (both legs) | No | Stock document (internal transfer) |
| Counter sale receipt | Yes (out) | Yes | AR/POS document |
| Stock count sheet | No (adjustment document does) | Sometimes (adjustment) | Worksheet feeding a stock document |

## What this confirms for PHP Ledger's existing services

`pl_inventory_transfer()` in `www/phpledger/includes/functions/inventory_functions.php` already implements exactly the "moves stock, no journal, carrying value follows" pattern common to internal transfers/issues/re-issues/returns-to-warehouse/stock-transfer-notes above, between two `pl_inventory_warehouses` rows. `pl_inventory_receive()` and `pl_inventory_issue()` implement the single-location in/out legs (goods receipt, counter sale stock-out). AR documents (`ar_ap_functions.php`) already carry the "posts a journal, has a party, has a number" behaviour needed for sales returns and counter-sale receipts. None of the researched documents requires a new accounting primitive; the gap is numbering, printing and permission semantics layered on the existing movement/document services — covered in [DOCUMENT-MODEL.md](DOCUMENT-MODEL.md).

## Sources

- [Delivery Challan under GST — Masters India](https://www.mastersindia.co/blog/gst-delivery-challan/)
- [Delivery Challan vs Invoice — Refrens](https://www.refrens.com/grow/delivery-challan-gst/)
- [Delivery Challan vs Invoice guide — Letstranzact](https://letstranzact.ai/blogs/delivery-challan-vs-invoice-guide)
- [Delivery Challan format — Gimbooks](https://www.gimbooks.com/blog/delivery-challan-and-format/)
- [Material gate pass fields — Touchpoint](https://www.usetouchpoint.com/non-returnable-gate-pass-management.html)
- [RGP/NRGP gate passes — Cryotos](https://www.cryotos.com/blog/material-gatepass-rgp-nrgp)
- [Odoo two-step receipt/delivery](https://www.odoo.com/documentation/17.0/applications/inventory_and_mrp/inventory/shipping_receiving/daily_operations/receipts_delivery_two_steps.html)
- [The stock.picking model — Dasolo](https://www.dasolo.ai/blog/odoo-data-api-5/odoo-stock-picking-model-guide-161)
- [ERPNext Stock Transactions](https://docs.frappe.io/erpnext/stock-transactions)
- [ERPNext Stock Reconciliation](https://docs.frappe.io/erpnext/stock-reconciliation)
- [ERPNext Material Transfer from Delivery Note](https://docs.erpnext.com/docs/v13/user/manual/en/stock/articles/material-transfer-from-delivery-note)
- [FieldAssist DMS guide 2025](https://www.fieldassist.com/blog/distribution-management-system-dms-guide-2025)
- [SAMS Online, Pakistan FMCG distribution](https://www.sams.solutions/distributor-management-system-fmcg)
- [Pepperi route accounting / van sales](https://support.pepperi.com/hc/en-us/articles/221824427-Route-Accounting-Van-Sales-Multiple-Warehouses-)
- [SalesOn van sales](https://saleson.co.in/van-sales)
- Repository: [ARCHITECTURE.md — Queued entry and synchronisation](../../../ARCHITECTURE.md#queued-entry-and-synchronisation), [DECISION-REGISTER.md sections H, L](../../../strategy/DECISION-REGISTER.md)
