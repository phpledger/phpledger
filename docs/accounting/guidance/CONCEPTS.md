# Concepts the help bubbles explain

**Research input for owner decisions B66 and B67. This is content research, not
implemented content, and it has not been reviewed by a qualified accountant
(owner rule B30).** The wording below is a draft for the keyed catalogue the
help-bubble component reads; the strings are interface text and go through
`pl_t()` like everything else.

Each entry is one bubble: a stable key, where it appears, and the text. Every
text is 40 to 70 words, which is what fits beside a field without becoming a
page of its own. The reader is a shopkeeper or a junior bookkeeper, not an
accountant, so no term is used without a gloss in the same breath.

**Every text below is jurisdiction-neutral on purpose.** Where local practice or
law would make a shared sentence wrong, the concept carries a *Local variation*
line pointing at [DIFFERENCES.md](DIFFERENCES.md); the per-country facts and
their sources are in [JURISDICTIONS.md](JURISDICTIONS.md) and
[SOURCES.md](SOURCES.md). Where a jurisdiction's rule was not researched, the
bubble says so rather than guessing — an unsourced local claim is worse than
none (B67).

These texts describe what this application does, drawn from the
[core rule register](../CORE_RULE_REGISTER.md),
[owner transactions](../OWNER-TRANSACTIONS.md),
[advances and refunds](../ADVANCES-AND-REFUNDS.md) and the
[trading-document policies](../examples/trading-document-policies.md). They are
not tax advice and they do not state a rule of law.

---

## The ledger

### Debit and credit

`concept.debit-and-credit` — beside the Debit and Credit column headings on the
journal, the voucher entry screen and the trial balance.

> Every entry has two sides that must match. A debit is the left side, a credit
> is the right. Money coming into an account you own is a debit; the source it
> came from is a credit. The words say which side, not good or bad. The two
> sides of one entry always add up to the same total.

*Local variation:* none. The rule is arithmetic, not law.

### The trial balance

`concept.trial-balance` — beside the trial balance report title and its column
totals.

> A list of every account with its balance, debits in one column and credits in
> the other. If the bookkeeping is sound the two column totals are equal. It
> proves the arithmetic, not the judgement: an amount posted to the wrong
> account still leaves the trial balance in agreement.

*Local variation:* none.

### A contra account

`concept.contra-account` — beside a contra account's row on the chart of
accounts and beside the deduction lines on the balance sheet and profit and
loss.

> An account that sits inside a section but is shown as a subtraction from it.
> Accumulated depreciation sits in assets and is deducted from them. Drawings
> sit in equity and are deducted from it. It keeps the original figure visible
> instead of quietly reducing it, so you can still see what was there before.

*Local variation:* none for the mechanism. Which figures a statutory statement
must show gross rather than net is a presentation rule of the local framework;
see [JURISDICTIONS.md](JURISDICTIONS.md).

### Accumulated depreciation

`concept.accumulated-depreciation` — beside the accumulated depreciation line in
the fixed-asset section of the balance sheet.

> Equipment wears out, so a part of its cost is charged to each year it is used.
> Accumulated depreciation is the running total of everything charged so far. It
> is never taken off the asset's original cost in the ledger; it is held beside
> it and shown as a deduction, so you see both cost and what is left.

*Local variation:* the rates and methods a tax authority accepts differ from the
rates used in the accounts. Not researched per jurisdiction in this pass; the
bubble must not name a rate.

### A reversal, not an edit

`concept.reversal-not-edit` — beside the Reverse action on a posted document and
on the reason field of a correction.

> Once an entry is posted it is never changed or deleted. A mistake is fixed by
> posting an opposite entry linked to the original, so both stay visible and
> anyone can see what happened and when. That is why a correction asks for a
> reason: the reason is part of the record, not a formality.

*Local variation:* an e-invoicing or fiscal-device regime can put a deadline on
cancelling or amending an issued invoice, and after it the correction must be a
credit note. See [DIFFERENCES.md](DIFFERENCES.md#4-correcting-an-issued-invoice).

---

## Customers, suppliers and documents

### A credit note

`concept.credit-note` — beside the Credit note action and on the credit note
entry screen.

> A document that reduces what a customer owes you: goods came back, you
> overcharged, or you granted a credit. It is not a deleted invoice. The
> original invoice stays exactly as it was and the credit note sits beside it,
> so the customer's history shows what was billed and what was given back.

*Local variation:* several jurisdictions require the credit note to carry the
original invoice's number and date, and some require it to be transmitted to the
authority like an invoice. See
[DIFFERENCES.md](DIFFERENCES.md#4-correcting-an-issued-invoice).

### An advance, or unapplied credit

`concept.advance-unapplied-credit` — beside the unapplied-credit section of the
ageing report, the customer statement's closing caption, and the remainder line
on a receipt.

> Money a customer paid that no invoice has claimed yet. You are holding their
> money, so you owe it back until you bill them: that makes it a liability, not
> a sale and not a minus figure inside sales. A customer owing 40,000 who has
> 10,000 on account still owes 40,000, and you owe them 10,000.

*Local variation:* whether receiving an advance is itself a taxable event, and
whether it needs its own receipt-voucher document, differs. Not researched for
every jurisdiction in this pass.

### Oldest-first allocation

`concept.oldest-first-allocation` — beside the proposed allocation grid on the
receipt and payment screens.

> When a payment does not name an invoice, the app proposes to clear the one due
> first, then the next, until the money runs out. Anything left over becomes
> credit on account. It is only a proposal: change any amount before posting to
> match what the customer says they paid for.

*Local variation:* none.

### A discount allowed

`concept.discount-allowed` — beside the Discounts allowed line on the profit and
loss account, and beside the discount field where the book posts gross.

> A reduction you give a customer after the sale is priced — for paying early,
> or as a goodwill gesture. It is a reduction of your income, never a cost you
> paid out. Recording it on its own line lets you see how much discount you gave
> in a year instead of losing it inside a smaller sales figure.

*Local variation:* whether the discount reduces the taxable value of the sale,
and whether it has to appear on the face of the invoice to do so, is a genuine
local difference. See
[DIFFERENCES.md](DIFFERENCES.md#2-a-discount-and-the-face-of-the-invoice).

### A trade discount

`concept.trade-discount` — beside the line discount percentage field on an
invoice.

> A reduction off the list price, given because of who the customer is or how
> much they buy. It is taken off before the invoice total is worked out, so the
> sale is simply recorded at the lower price. Nothing separate is posted: the
> discount never reaches the ledger, unlike a discount allowed after pricing.

*Local variation:* as above.

### The document number series

`concept.document-number-series` — beside the document number field and in
Admin, beside the series prefix and padding settings.

> Each kind of document has its own unbroken run of numbers — invoices in one
> series, receipts in another. The app gives out the next one and never reuses
> or skips a number, so a missing number is a question worth asking. You choose
> the prefix and the width once; changing them later does not renumber what is
> already issued.

*Local variation:* under an e-invoicing regime the authority issues its own
reference, which is additional to yours and must be printed. See
[DIFFERENCES.md](DIFFERENCES.md#3-what-the-invoice-must-carry).

---

## Stock

### Free goods and their cost

`concept.free-goods-cost` — beside a free-goods line on an invoice and beside
the promotional expense account setting.

> Goods you hand over without charging. The customer pays nothing, so there is
> no sale and nothing in the total. But the goods really left your store, so
> their cost must leave stock too, and it goes to promotion rather than cost of
> sales — you gave them away, you did not sell them.

*Local variation:* several jurisdictions treat a free supply as taxable at open
market value, and one of them makes you give back the input tax instead. This is
the sharpest difference in the set. See
[DIFFERENCES.md](DIFFERENCES.md#1-free-goods-and-open-market-value).

### A gate pass

`concept.gate-pass` — beside the Gate pass action and on the gate pass entry
screen.

> The paper the security desk keeps when something leaves or enters the gate. It
> records permission and who authorised it, and whether the item is expected
> back. It moves no stock and posts no entry on its own: the stock document it
> refers to does that. A gate pass without that document proves nothing was
> recorded.

*Local variation:* none found. It is a control document, not a statutory one, in
every jurisdiction researched — but a separate goods-movement document *is*
statutory in some places (India's e-way bill), and that is a different thing.
See [JURISDICTIONS.md](JURISDICTIONS.md).

### A stock issue

`concept.stock-issue` — beside the Stock issue document type and the
warehouse-to-van transfer screen.

> Goods leaving one place for another inside your own business — the warehouse
> loading a van in the morning, or one branch supplying another. Nothing is sold
> and no profit arises. The quantity and its cost simply move from one
> location's stock to the other's, and the total stock you own is unchanged.

*Local variation:* moving goods between your own separately registered branches
can be a taxable supply. See
[DIFFERENCES.md](DIFFERENCES.md#7-moving-your-own-goods-between-your-own-places).

### Carrying value

`concept.carrying-value` — beside the cost column on a stock document and the
stock valuation report.

> What an item is worth in your books: what you paid for it, not what you hope
> to sell it for. Stock is carried at cost until it is sold, and only then does
> the difference between that cost and the selling price become profit. Expected
> profit is never added to stock beforehand.

*Local variation:* none for the principle.

### Moving weighted average

`concept.moving-weighted-average` — beside the unit cost on the stock valuation
report and the costing method setting.

> When the same item is bought at different prices, the app does not track which
> box came from which delivery. It keeps one average cost per item and
> recalculates it after every purchase: total cost in stock divided by total
> quantity. Every sale then takes that average out. Buying dearer raises it,
> buying cheaper lowers it.

*Local variation:* which cost formulas a framework permits differs, and a tax
authority may require a different one from the accounts. Not researched per
jurisdiction in this pass.

---

## The owner

### Owner's capital

`concept.owner-capital` — beside the capital introduced line in the equity
section and on the owner transactions screen.

> Your own money put into the business and not expected back. It is not income
> and never appears in profit. It increases what the business owes you as its
> owner, which is what equity means: the part of the business funded by you
> rather than by a bank or a supplier.

*Local variation:* what the form of business makes of it differs — a company's
owner subscribes for shares rather than introducing capital. See
[JURISDICTIONS.md](JURISDICTIONS.md).

### An owner's loan

`concept.owner-loan` — beside the owner's loan account in the liabilities
section and on the owner transactions screen.

> Money you lend the business and expect back. Because it must be repaid it is a
> debt the business owes, listed with the other liabilities — not capital.
> Repaying it is not an expense and does not touch profit; it simply reduces the
> debt and the cash at the same time.

*Local variation:* interest paid to an owner may be restricted, and a loan in
the other direction — the business lending to its owner — can attract a charge.
See [DIFFERENCES.md](DIFFERENCES.md#5-money-taken-out-by-the-owner).

### Drawings

`concept.drawings` — beside the drawings line in the equity section and on the
drawings entry screen.

> Money or goods you take out of the business for yourself. It is not an
> expense: an expense is something the business spent to earn its income, and
> your household shopping did not earn anything. Drawings come out of your share
> of the business, so they are shown as a deduction from equity, not in profit.

*Local variation:* this is only safe for a sole proprietor or partner. For a
company owner the same withdrawal can be a salary, a dividend, a loan or a
deemed distribution, with tax attached. See
[DIFFERENCES.md](DIFFERENCES.md#5-money-taken-out-by-the-owner).

### Partner capital

`concept.partner-capital` — beside each partner's row on the owners and partners
screen.

> In a partnership each partner has their own capital account and their own
> drawings account, so you can always see what each one put in and took out.
> They are never merged into a single owner's figure. A partner's position is
> what they introduced, less what they drew, plus whatever profit has been
> credited to them.

*Local variation:* the fixed-capital-plus-current-account presentation is the
usual one in several jurisdictions and is an open question for the accountant
(see [OWNER-TRANSACTIONS.md](../OWNER-TRANSACTIONS.md) section 6).

### The profit-sharing ratio

`concept.profit-sharing-ratio` — beside the ratio field on a partner record and
the incomplete-ratio warning.

> The share of profit each partner is entitled to under the partnership
> agreement — 60:40, for example. The app records the ratios but shares nothing
> out by itself, because who gets what usually depends on salaries and interest
> agreed in the deed first. An allocation is entered deliberately, by someone
> who has decided the treatment.

*Local variation:* whether a partner's salary is an appropriation of profit or a
deductible expense of the firm is the difference that matters, and it is not the
same answer everywhere. See
[DIFFERENCES.md](DIFFERENCES.md#6-a-partners-salary).

---

## Starting and finishing

### Opening balances

`concept.opening-balances` — beside the opening balance entry screen and its
out-of-balance message.

> What the business already owned and owed on the day you started using this
> app. They are entered once, as a single balanced entry, so the accounts begin
> from the truth instead of from zero. They must agree with your last set of
> accounts; if they do not balance, something is missing rather than wrong by a
> little.

*Local variation:* none.

### Cutover

`concept.cutover` — beside the cutover date field in setup and on the unpaid
document import preview.

> The date you switch from your old books to this one. Everything before it
> stays in the old records and arrives here only as opening balances; everything
> after it is entered here. Unpaid invoices are listed individually so you can
> chase them, but their totals are already inside the opening balances and are
> not entered twice.

*Local variation:* none for the mechanism. A cutover date in the middle of a
statutory financial year is a reporting question for the local accountant, and
the financial year is not the same everywhere — see
[JURISDICTIONS.md](JURISDICTIONS.md).

### A period close

`concept.period-close` — beside the Close period action and the closed-period
refusal message.

> Locking a month or a year once you are satisfied with it, so nothing can be
> added or changed behind reports you have already given out. Closing does not
> calculate anything. If a mistake turns up later, the period is reopened
> deliberately or the correction is dated in the period that is still open.

*Local variation:* the statutory financial year end and the tax period end are
not always the same date, and neither is chosen by the app. See
[JURISDICTIONS.md](JURISDICTIONS.md).

---

## What the bubbles must never do

1. **Never state a tax rate without its source and the date it was checked.** A
   rate belongs to a jurisdiction and a date, and both move.
2. **Never give an entity its framework.** Currency, language or an IP guess
   does not choose a reporting framework (CORE-10).
3. **Never imply review.** Nothing here has passed the accountant's gate (B30).
4. **Say "not researched" out loud.** Where a jurisdiction note is missing, the
   bubble shows the shared explanation and says the local rule was not checked
   (B67).
