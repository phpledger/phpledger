# Decisions embodied in the 1.2 trading forms &amp; reports mockups

Every question below is answered by what a frame shows. Format per owner rule
B53: **Taken** is the option the frame is built on; **Alternatives** are the
other options considered; **Reversal** says how costly it is to change later.
Questions marked **ask the owner** are hard to reverse once shipped (schema
shape, a numbering series once issued) and should be confirmed before code is
written, not discovered afterwards.

Frames: `invoice-editor.html`, `invoice-print-a4.html`, `receipt-print-80mm.html`,
`customer-statement.html`, `stock-issue-gate-pass.html`, `stock-by-location.html`,
`trial-balance-collapsible.html`, `receipt-on-account.html`.

---

## Invoice editor

**1. Document number format.**
Taken: a transaction-ID style series, `INV-2026-000123` (document type + year +
6-digit running number), reset per year. Shown on the frame with a fine-print
note giving the alternative for comparison.
Alternatives: (a) the app's current sequential series with no year, e.g.
`INV-000194` (as used throughout `docs/design/redesign-0.5/COMPONENTS.md`
sample data and the Awan prototype's plain `35586`); (b) a per-warehouse or
per-van prefixed series (e.g. `WH01-INV-000123`).
Reversal: **ask the owner.** A numbering scheme is very hard to change once
invoices exist under it — every printed copy, every customer's own filing, and
any cross-reference in a statement or ledger carries the number permanently.
Decide before 1.2 issues its first invoice.

**2. Line discount: percentage or amount.**
Taken: a `Disc %` input per line, with the amount computed from it.
Alternatives: a flat discount amount per line with the percentage computed
back (this is what the Awan prototype's sales-invoice line actually does —
see "Taken from the Awan prototype" below).
Reversal: cheap. Both fields can be shown together (one editable, one
computed) without a schema change — storing the line's discount amount is
suf­ficient either way; only the input's default focus changes.

**3. Free goods: a separate line, or a flag on a regular line.**
Taken: a separate zero-price "FREE" line (visually tinted) carrying only a
quantity, tied to the scheme by its description.
Alternatives: a `Sale Type` selector (`Regular` / `Scheme`) on the *same* line
as the paid goods, as in the Awan prototype — one line represents "5 cartons
sold, 1 free" instead of two rows.
Reversal: moderate. A separate line is simpler to explain on a printed
invoice (a customer sees "bonus" spelled out) but doubles row count for
scheme-heavy invoices; switching to a same-line flag later is a UI change, not
a posting-model change, since both approaches book the free quantity at zero
value.

**4. Pack and unit entry: two fields, or one combined field.**
Taken: two numeric fields, `Ctn` and `Units`, matching the pack size on the
item record (e.g. "5 ctn + 3 units" reads as two cells, not one string).
Alternatives: a single free-text field like `"3 cartons + 4"` parsed on blur.
Reversal: cheap to reverse in the UI; keep the underlying value as a
quantity-in-base-units regardless of which input style is shown, so this is a
front-end-only decision.

**5. Cash received: where it sits on the invoice.**
Taken: a labelled panel beside the totals block, with the resulting
"Balance receivable" folded into the totals column.
Alternatives: a single row inside the totals block itself (`Cash Received`
between `Total` and `Balance Due`), as in the Awan prototype — more compact,
less visually distinct from the rest of the totals.
Reversal: cheap; both are the same field, styled differently.

**6. Stock/balance readout above the lines table.**
Taken: adopted from the Awan prototype — a strip showing the customer's
running balance, the chosen van's stock, and the source warehouse's stock,
updating for "the current line" once a product is picked.
Alternatives: no readout at all (today's app); a readout that shows only
warehouse stock, omitting the van/salesman figure (relevant only once 1.2's
multi-warehouse/van model exists).
Reversal: cheap to omit or extend; it is a read-only display, not a posting
input.

## Invoice print (A4) and counter receipt (80mm)

**7. A4 layout: letterhead, then two-column parties, then lines, then a
tax-summary-plus-totals footer.**
Taken: as shown — letterhead across the top, bill-to/delivery-details as two
columns, a rate-grouped tax summary beside the totals block.
Alternatives: totals-only footer with no per-rate tax breakdown (simpler,
weaker for GST-registered customers who need the split).
Reversal: cheap; a layout/print-CSS change only.

**8. 80mm receipt: two-line items (quantity/rate, then description) vs
one-line items.**
Taken: two lines per item (name, then pack &times; rate = amount) so the
calculation is visible on a narrow roll.
Alternatives: a single line per item with the amount only, saving paper.
Reversal: cheap.

## Customer statement

**9. Unapplied credit: a highlighted row inside the activity table, or a
separate summary block.**
Taken: an inline row tagged "Unapplied", explained in the closing-balance
caption.
Alternatives: a separate "Advances on account" mini-table below the main
activity, kept apart from invoice/receipt rows entirely.
Reversal: cheap; a presentation choice over the same underlying ledger rows.

**10. Statement default range.**
Taken: current month to date, with explicit From/To fields for any other
range.
Alternatives: always-open-ended ("all activity since the account opened").
Reversal: cheap, a default-value change.

## Stock issue &amp; gate pass

**11. Stock-issue number format.**
Taken: `ISS-2026-000341`, the same transaction-ID shape as the invoice
series, for consistency across 1.2's document types.
Alternatives: the Awan prototype's plain sequential `Issue No. 2543` (no
type prefix beyond a two-letter code, no year).
Reversal: **ask the owner** together with question 1 — both are numbering
schemes and should be decided as one policy, not per document type.

**12. Gate pass: separate "Gate Pass" action, or automatic on save.**
Taken: a distinct `Gate Pass` button beside Save (adopted from the Awan
prototype), so the issue can be saved and reprinted independently of the
paper leaving the gate.
Alternatives: auto-open the print view the moment the issue is saved.
Reversal: cheap, a button-wiring change.

**13. Stock-effect note ("no ledger entry, Overall Stock unchanged").**
Taken: shown explicitly as an info alert, matching the accounting rule that
an internal transfer between company-owned locations is not a sale and must
not touch the sales/COGS accounts — only the eventual van sales invoice does.
Alternatives: none considered; this is a correctness requirement, not a
style choice.
Reversal: not applicable — this must stay true regardless of layout.

## Stock by location

**14. Grouping order: by warehouse first, then by van.**
Taken: warehouses listed before vans, in the order they were set up.
Alternatives: alphabetical; by total value descending; vans grouped under
their "home" warehouse as a sub-group.
Reversal: cheap, a sort-order change with no data-model impact.

**15. Aggregate (all-locations) column: shown per row, or only in a rollup
footer.**
Taken: an "Aggregate units" column on every item row, so a reader does not
have to add up locations by hand.
Alternatives: omit it and rely on a separate "Company-wide" summary view
(as the Awan prototype does with its own Stock Position vs Company-Wise
Summary as two reports).
Reversal: cheap; the figure is a sum of visible rows either way.

**16. Negative/low-stock flagging.**
Taken: an inline `Negative` or `Low` badge on the affected row, plus a
warning line item in the same table (no separate alert banner, unlike the
Awan prototype's page-level warning banner for negative stock).
Alternatives: adopt the Awan prototype's page-level `alert-warning` banner
above the table, listing every negative item with a link to its ledger.
Reversal: cheap; both read from the same negative-quantity condition.

**17. Cost-value visibility.**
Taken: `Value at cost` is shown to every viewer of this mockup, for
simplicity.
Alternatives: gate the cost column behind a "see purchase cost" permission,
as the Awan prototype itself flags as a proposed fix to its own legacy
report (which shows cost to anyone who can open it).
Reversal: **ask the owner** — whether cost visibility needs a permission is
a policy question, and retrofitting a permission check after the report ships
without one is more work than building it in from the start.

## Trial balance — collapsible, structured codes

**18. Account code shape: `X-XXX-XXXXX-XX` (class-group-account-sub).**
Taken: as specified for issue #76 — 1-digit class, 3-digit group, 5-digit
account, 2-digit sub-account, giving a genuine three-level (class &rarr; group
&rarr; account) hierarchy with the sub-account as an optional fourth,
finer-grained leaf (e.g. one sub-account per customer under a receivables
control account).
Alternatives: the Awan prototype's own flat 3-digit codes (`101`, `111`,
`116`&hellip;), which is what a legacy trading system actually used and is
*not* a structured hierarchy — see "Taken from the Awan prototype" below for
why this frame does not follow it.
Reversal: **ask the owner.** Account codes are structural: once accounts are
created and transactions posted against them, changing the code shape is a
migration, not a UI change. Confirm the digit widths and the sub-account's
meaning before #76 is built.

**19. Collapse default state.**
Taken: classes with balances the owner is most likely to check first
(Assets, Expenses) open by default; the rest (Liabilities, Equity, Revenue)
start collapsed. Groups inside an open class mostly start open too, except
one (`1-120 Inventory`) shown collapsed to demonstrate the collapsed state.
Alternatives: everything collapsed by default (fastest initial render, most
clicks to read); everything expanded by default (matches today's flat report
exactly, defeats the point of adding collapse).
Reversal: cheap; a default-`open`-attribute change per class/group, no data
impact.

**20. Depth control.**
Taken: a `Show` selector offering "Class &rarr; Group &rarr; Account" (full
depth, what's built) or "Class &rarr; Group only" (a coarser summary).
Alternatives: no depth control; the collapse mechanism alone is treated as
sufficient since a fully collapsed view already reads like a class/group-only
report.
Reversal: cheap; a display filter over the same rows.

## Receipt on account

**21. Allocation order: oldest invoice first, editable.**
Taken: the preview always fills the earliest unpaid invoice first, but every
"Allocated now" cell can be overwritten before posting to apply a different
split (e.g. to match a customer's own remittance advice).
Alternatives: strict oldest-first with no override (simpler, but forces a
correcting journal whenever a customer says which invoice they're actually
paying); manual-only allocation with no default (more clicks for the common
case).
Reversal: cheap; the default algorithm can change without touching the
posting model, since the stored fact is always "this amount applied to that
invoice."

**22. Unallocated remainder &rarr; customer advance.**
Taken: whatever is left after allocating to every open invoice is posted as
a credit against the customer (an advance), shown separately on the
statement (see decision 9) and offered first against their next invoice.
Alternatives: reject the receipt until it exactly matches open invoices
(no advances possible); or post the remainder to a generic "unapplied cash"
suspense account instead of the customer's own sub-ledger.
Reversal: **ask the owner** for the suspense-account alternative specifically
— that changes which account family holds unapplied cash and is a schema/
chart-of-accounts question, not just a UI one. The customer-advance approach
shown here is cheap to keep or drop since it uses the same customer
sub-account either way.

**23. Batch receipts: one voucher covering many customers, or one voucher
per customer.**
Taken: the batch screen is an *entry convenience* only — every customer row
still posts as its own linked receipt voucher, individually reversible, with
its own oldest-first allocation. A single Cash-in-Hand debit is not spread
across a multi-customer journal; see "Taken from the Awan prototype" below
for why.
Alternatives: the Awan prototype's actual model — one RV voucher with many
customer credit lines and a single auto-generated cash debit line.
Reversal: **ask the owner** if a single multi-customer voucher is wanted
instead — it is materially different for reversal (reversing one customer's
receipt would mean editing a shared voucher, not reversing an independent
one) and is closer to a schema/posting-engine decision than a display one.

## Cross-cutting

**24. Mobile / narrow-viewport behaviour.**
Taken: none of these frames address mobile layout. This follows the existing
scope decision recorded in `docs/design/redesign-0.5/BRIEF.md` ("Desktop +
tablet only (mobile ignored for now)"); these 1.2 trading-document mockups
are desktop-only design aids for the same reason, not a claim that 1.2 itself
excludes mobile.
Alternatives: design a stacked/scrollable mobile variant per frame now.
Reversal: cheap to defer; expensive to guess at without an owner decision on
whether counter/warehouse staff need a phone-width view at all (a POS
screen already exists as a separate pattern in COMPONENTS.md for that case).

---

## Taken from the Awan prototype

Per the owner's note (B54): the Awan prototype (`awan-prototype/
Awan-prototype.html`, an owner-supplied single-file mockup of "Awan Oil
Traders", a distributor rebuild with 123 screens) is a design aid for layout
and field sets — not a final authority. Adopted where it matched the task;
overridden where it conflicted with accounting principles or PHP Ledger's own
rules (immutable posted entries, linked reversals, reports reconciling to the
ledger, no unposted balance shown as a ledger figure).

**Adopted as-is or lightly adapted:**
- Stock-position report's grouping-with-subtotals-and-grand-total structure
  &rarr; `stock-by-location.html`, regrouped by warehouse/van instead of by
  brand/company.
- Stock-issue screen's split between "already on the van" stock and "issued
  now" stock, plus its packing-size reference column &rarr;
  `stock-issue-gate-pass.html`.
- The stock/balance readout strip above a document editor's lines (customer
  balance, van stock, warehouse stock) &rarr; `invoice-editor.html`.
- The receipt voucher's multi-row batch-entry grid (Previous Balance /
  Received / Closing Balance per customer) as the *data-entry* pattern for
  `receipt-on-account.html`'s second screen — see decision 23 for how the
  posting model deliberately differs.
- The "Gate Pass" as a distinct print action separate from Save (decision
  12).
- Discount-as-amount-with-computed-percentage was noted as the Awan
  prototype's actual behaviour and recorded as the alternative in decision 2,
  even though this task's frame took percentage-first instead.

**Deliberately different, and why:**
- **Account code shape (decision 18).** The Awan prototype uses flat legacy
  3-digit codes with no structural hierarchy (`101`, `116`, `301`&hellip;),
  because it is modelling what an old trading system actually stored, not
  issue #76's structured-code design. `trial-balance-collapsible.html`
  follows the accounting design for #76 instead.
- **Unbalanced vouchers.** The Awan prototype's trial balance carries a
  "Suspense &middot; Unbalanced legacy vouchers" line, because it is
  reproducing a real legacy dataset that contains 67 vouchers whose debits
  never equalled their credits. PHP Ledger refuses to post an unbalanced
  voucher at all, so no such line can ever appear in a real PHP Ledger trial
  balance; `trial-balance-collapsible.html` omits it entirely rather than
  design a state that the posting engine makes impossible.
- **Correcting a posted document.** Some Awan prototype screens (e.g. its
  sales-invoice detail) note, as their own `data-proposed` caveats, that the
  legacy system edits a posted invoice in place. None of these mockups show
  that: a posted document here is only ever corrected by a linked reversal
  plus a new document, per PHP Ledger's immutable-posted-entry rule.
- **Batch receipts posting model (decision 23).** The Awan prototype posts
  one receipt voucher with several customer credit lines and a single
  combined cash debit. That is efficient to enter but makes an individual
  customer's receipt inseparable from the batch for reversal purposes. This
  mockup keeps the batch *grid* for fast entry but posts each customer's
  receipt as its own independent voucher, so reversing one customer's
  receipt never touches another's.
- **Cost-value permission gating (decision 17).** The Awan prototype's own
  authors flagged, in their report, that showing purchase-rate cost to every
  viewer is a legacy gap they'd fix given the chance. This mockup keeps cost
  visible for simplicity but records the gap as an open question rather than
  silently inheriting it as accepted practice.
