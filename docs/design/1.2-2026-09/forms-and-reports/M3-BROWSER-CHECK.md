# 1.2 M3 browser check: invoice editor and the three print templates

What was actually driven in a browser on the `m3/trading-documents` branch, at 1440, 768 and
390 CSS pixels. Every figure below was read off the running application, not from a mockup.

**Fixture.** `php tests/starter_browser_fixture.php --trading` in the test container against the
disposable `phpledger_test` database, served with PHP's built-in server. The fixture enables the
`trading-documents` module, creates the `CTN12` pack (12 base units), the `BILAL` salesman and the
`GULBERG` route, sets the B37 policies (net discounts, no free-goods output tax, a 50,000.00
cash-on-invoice cap and a promotional expense account), fills the B64 company profile, receives
400 units at 2.00 each, and posts `INV-2026-000001` carrying a pack line, a discounted line, a
free-goods line and 20,000.00 of cash on the invoice.

**Screenshots** were taken through the browser tool, which returns images into the session
transcript rather than to a file path, so the images live in this branch's session record and are
not committed here. This note records what each one showed.

## Invoice editor, `/ar?new=1` and `/ar?id=…&edit=1`

| Width | Result |
|---|---|
| 1440 | Header dimensions (Warehouse, Sales staff, Area / route) render as a third row of the detail grid. The cash panel renders with its cap stated. The readout strip shows three columns. The lines table shows Description, Product, Account, Pack, Packs, Units, Qty, Unit price, Disc %, Tax code, Free, Entered amount and the delete button, all within the sheet. No page-level horizontal scroll. |
| 768 | No page-level horizontal scroll (`documentElement.scrollWidth === clientWidth === 768`). Detail fields drop to two per row, the readout strip to two columns. The lines table scrolls sideways inside its own `.table-wrap` (`scrollWidth` 1024 vs `clientWidth` 661), which is the pre-existing pattern for this table below 1024. |
| 390 | No page-level horizontal scroll. Detail fields stack, the readout strip becomes one column, the cash panel is present and legible. The lines table again scrolls inside its wrap. |

**Behaviour driven, not just rendered.** Entering pack `CTN12`, 5 packs + 3 units at 1,180.00
with 5% discount showed `70623.0000` with a `less 3717.0000` note on the row — 63 base units
resolved live from the frozen pack size. Ticking Free on a second line tinted the row
(`doc-line-free`), zeroed its amount and showed "Bonus, not charged". "Update posting preview"
returned the server's plan: receivable 70,623.00, income 70,623.00, stock issues of −63 and −12
units, and totals Subtotal 74,340.00 / Line discounts −3,717.00 / Total 70,623.00 / Cash received
−20,000.00 / Balance receivable 50,623.00. Editing the discount to 10% afterwards recomputed the
live rows to −7,434.00 / 66,906.00 and removed the preview-only cash rows, because they described
the preview that edit had just invalidated.

**Three defects were found by this check and fixed on the branch:**

1. The free-goods checkbox was first written with its `<input>` inside its `<label>`. `app.js`
   renumbers a cloned row through each control's `previousElementSibling`, so this threw and left
   every added row still named `lines[0][…]` — a second line would have silently overwritten the
   first. The checkbox now has the same sr-only-label-then-control shape as every other cell.
2. `app.js` wrote the totals block by counting `<dd>` elements, so the new Line discounts, Cash
   received and Balance receivable rows were overwritten with the wrong numbers. The rows the
   script owns are now named (`data-total="net|discount|tax|total"`), and the rows that belong to
   the last server preview are removed on edit rather than overwritten.
3. The live row amount ignored packs, discounts and the free flag, so the typed figure disagreed
   with the posted one. It now resolves the pack, applies the discount and shows zero for a free
   line, from the same frozen pack sizes the server uses.

## Prints

| Template | 1440 | 768 | 390 |
|---|---|---|---|
| `/print/invoice/<id>` (A4) | No page scroll, table fits | No page scroll, table fits | No page scroll; the line table scrolls inside `.print-lines-scroll` and the letterhead stacks |
| `/print/invoice/<id>?format=80mm` | No page scroll | No page scroll, 302px sheet | No page scroll |
| `/print/statement/<party id>` (A4) | No page scroll | No page scroll | No page scroll |

Content verified on the A4 invoice: the B64 letterhead (name, two address lines, phone and email,
tax registrations), the pack columns (5.00 packs / 3.00 units), the discount column, the free-goods
row, the per-rate tax summary (74,340.00 taxable at 5% → 3,717.00), Subtotal 82,540.00, Line
discounts −410.00, Tax 3,717.00, Total 85,847.00, Cash received −20,000.00, Balance due 65,847.00,
and the profile's footer terms above the computer-generated note. The 80 mm roll showed the same
document condensed with two lines per item. The statement showed the opening balance, the period
movement and a closing balance equal to the invoice's outstanding 65,847.00.

**Two more defects found here and fixed:** at 390 the seven-column invoice table forced a
page-level horizontal scroll (now contained by a screen-only `.print-lines-scroll`, restored to
full width under `@media print`), and the statement's long reference — a party name plus a date
range — pushed the letterhead past the sheet (the reference now wraps, and below 30rem the A4
letterhead stacks instead of squeezing to one word per line).

## Not reachable from the screen

- **Van stock per salesman.** Frame decision 6 wants a van figure beside the warehouse figure.
  A van is a warehouse kind that M4 introduces and there is no salesman-to-van link in the schema,
  so the strip shows the warehouse and the whole book and says so in place of inventing a number.
- **Statement date range.** `/print/<type>/<id>` passes only a document id, by design, so the
  statement prints the current month to date. The frame's From/To controls are not wired; a range
  would need the print route to carry parameters, which is M2's contract and not this milestone's
  to change.
- **A pack column on a supplier bill.** The trading columns are switched on for customer documents
  only. Purchasing keeps the columns it had.
