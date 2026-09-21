# Trading-document accounting policies: worked examples for review

**Original documentation fixture only — not installed sample data, a working report export, a real business, a tax calculation, or a compliance claim.** Every figure below is a specified example checked for arithmetic consistency. The applicable accounting framework, entity profile, country requirements and policy choices still need review by a qualified accountant (owner rule B30).

Owner decision **B37** says the trading-document accounting policies are configurable in Admin, decided report by report. This page is the material for that decision: for each policy it states the choice the application ships with, the entries each value produces, the alternatives, and what it costs to change the choice later (owner rule **B53**).

The policies live in **Admin › Accounting policies**, per company and book. Each is saved with a reason, one revision at a time, and every change is kept in an immutable history. **A policy change never restates a document already posted**: the entries a document carries are the entries its policy produced on the day it was posted.

Common example throughout: a customer buys **10 units at 25.00 each**, and the goods carry **2.00 each** in stock.

---

## 1. Line discount posting

A line discount is entered as a percentage, and the resulting amount is stored on the line (frame decision 2). The customer owes the discounted amount under either policy; what differs is how the sale is shown inside income.

### Value `net` — net to income (shipped default, recommended)

| Account | Debit | Credit |
|---|---:|---:|
| Trade receivables | 225.00 | |
| Sales | | 225.00 |

The discount never enters the ledger. The profit and loss account shows revenue of 225.00.

### Value `gross` — gross to income, discount as contra-income

| Account | Debit | Credit |
|---|---:|---:|
| Trade receivables | 225.00 | |
| Sales | | 250.00 |
| Discounts allowed (contra-income) | 25.00 | |

The customer still owes 225.00. Revenue reads 250.00 with a 25.00 deduction beside it, so *how much discount was given* is itself a figure on the report. The contra account must be an active contra-income account; the reserved group `4-910 Discounts allowed` exists for exactly this (owner decision B60, migration 036).

**Why the recommendation.** `net` is the smaller ledger and the one a reader unfamiliar with contra accounts interprets correctly without help. `gross` is the right choice for a business that manages discount as a controlled cost and wants it on the face of the accounts.

**Alternatives considered.** Posting the discount to a general expense account rather than contra-income: rejected, because a price reduction is a reduction of revenue, not a cost incurred. Storing the discount only as a printed line with no ledger effect under either policy: that is what `net` already is.

**Reversal path.** Cheap. Both values read the same stored `discount_amount` on the line, so switching the policy changes only documents posted afterwards. To restate documents already posted, reverse each one and enter a replacement; nothing in the schema changes.

### Supplier bills

Contra-income is a sales concept. A discount on a supplier bill always posts net, whatever this book's sales policy says.

---

## 2. Free goods

A free-goods line is a separate zero-value line carrying a quantity (frame decision 3). It is never billed to the customer: it does not appear in the subtotal, the tax total or the document total. Its **carrying value still leaves stock**, because the goods physically left the warehouse.

Example: the sale above plus **2 units given away**, carrying 2.00 each.

### Carrying value

Nothing was sold, so nothing belongs in cost of sales. The carrying value goes to the promotional expense account named in the policy:

| Account | Debit | Credit |
|---|---:|---:|
| Promotional goods (expense) | 4.00 | |
| Inventory | | 4.00 |

The ten units actually sold move to cost of sales as they always did. An invoice carrying a free-goods line cannot be posted until the promotional expense account is named; the application refuses rather than guessing cost of sales.

### Output tax, value `none` (shipped default, recommended)

No output tax arises on the free supply. The customer's invoice total is unchanged.

### Output tax, value `open_market_value`

Where a tax authority treats a free supply as taxable, the tax is calculated on the **open-market value** entered on the free line and **borne by the business**, never billed to the customer. The taxable base is that value **excluding the tax itself**: where the book prices inclusive of tax the entered open-market value already contains its own tax, so the free line is split exactly as every valued line on the same document is before the tax borne is worked out. With an open-market value of 25.00 per unit and a 10% rate, prices entered exclusive of tax:

| Account | Debit | Credit |
|---|---:|---:|
| Promotional goods (expense) | 5.00 | |
| Output tax | | 5.00 |

The customer's invoice total stays 275.00 (250.00 plus their own 25.00 of tax). The output tax account carries 30.00 in all: 25.00 charged to the customer, 5.00 the business bears. The promotional account carries 9.00: the 4.00 carrying value plus this 5.00.

The same supply priced inclusive of tax reaches the same figures. Two bonus units entered at 27.50 each in a book pricing inclusive of 10% have an open-market value of 55.00 that contains 5.00 of tax, so the base is the same 50.00 and the business bears the same 5.00. The tax borne is a property of the supply, not of how the price was typed.

**Why the recommendation.** `none` is correct wherever a free supply is not a taxable supply, which is the common case for the businesses 1.2 targets, and it is the value that requires no judgement about what an open-market value is. `open_market_value` is available for the jurisdictions that require it and should be turned on only on advice.

**Alternatives considered.** Charging the free-goods output tax to the customer: rejected, because the customer was not sold anything and the invoice total would no longer be what they agreed to pay. Posting the free-goods output tax to cost of sales: rejected for the same reason the carrying value is not cost of sales.

**Reversal path.** Cheap for the tax value: it is read at posting time from the policy and recorded on the document as `free_tax_total`, so a change affects later documents only. Changing the promotional expense account is equally cheap, and both values are preserved on the posted revision so a report can always tell which account a given document used.

---

## 3. Cash received on an invoice

A counter or van sale is often paid as the goods are handed over. The invoice records the cash itself, and posting it **recognises the receivable and settles the cash portion in one action**: a single transaction, two linked journals. A counter sale can therefore never leave a receivable standing against money already in the till.

Example: the 250.00 sale with 100.00 taken in cash.

| Journal | Account | Debit | Credit |
|---|---|---:|---:|
| Recognition | Trade receivables | 250.00 | |
| Recognition | Sales | | 250.00 |
| Settlement | Cash in hand | 100.00 | |
| Settlement | Trade receivables | | 100.00 |

The invoice then shows 150.00 outstanding and a payment status of *partially paid*. The settlement is an ordinary open-item allocation: the same entries, the same frozen carrying value and the same open-item history as a receipt entered on the payments screen. The only difference is that this one cannot be forgotten.

### The cap

`cash_on_invoice_cap` is the most cash one invoice may record.

- **`0.0000` (shipped default, recommended)** — an invoice takes no cash at all, and every receipt is entered on the payments screen where it is reviewed as a payment. A book that never opens this screen behaves exactly as 1.1 did.
- **A positive amount** — cash up to that amount may be recorded on an invoice. The owner sets it deliberately, which is also the control over how much money one person at a counter can record without a separate reviewed receipt.

Cash is also refused above the invoice total: an overpayment is an advance on account, not an invoice line.

**Why the recommendation.** Zero is the conservative default: a business opts in to counter cash, rather than discovering it is on. The cap is the owner's own control and belongs with them, not in the code.

**Alternatives considered.** No cap at all (a boolean on/off): rejected, because the cap is doing useful work as an internal control and a boolean throws that away. A per-salesman or per-warehouse cap: deferred — it is a larger authorisation model, and B55 already rules out per-warehouse variation in the neighbouring numbering decision.

**Reversal path for a posted cash invoice.** Reverse the invoice: the allocation is released first and the recognition second, in one transaction, so cash and receivable are released together. Then enter a corrected invoice. The **in-place correction path refuses a cash invoice** and says so, because its own reviewed preview asserts that no allocation is outstanding and the cash on the invoice is exactly such an allocation. Teaching the correction path to unwind and replay its own settlement is a deeper change to that preview's reviewed basis and belongs in its own milestone.

---

## 4. Packs

A pack resolves to a base quantity **before** pricing, tax and stock issue: `5 cartons + 3 units` of a 12-unit carton is 63 base units, and 63 is what the line stores, what the tax engine sees and what the stock ledger issues (frame decision 4).

A pack's product, code and size are **frozen at creation** — the service refuses a change and a database trigger refuses it again. A posted line stores only its resolved base quantity, so a restated pack size would silently restate stock history. A different size is a different pack.

**Reversal path.** A pack may be renamed or retired at any time; historical documents keep the quantities they resolved to. There is nothing to reverse, because nothing about a posted document depends on the pack row after posting.

---

## Questions for the accountant

1. Should `gross` discount posting be the recommended default for a distribution business, rather than `net`?
2. Where a free supply is taxable, is the open-market value of the goods the correct tax base here, and should it be the selling price or the carrying value?
3. Is the promotional expense account the right home for free-goods carrying value, or should it sit in cost of sales with a separate analysis?
4. Is a per-invoice cash cap sufficient as an internal control, or does counter cash need a separate authorisation?
