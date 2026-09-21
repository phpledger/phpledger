# Internal accounting review of the 1.2 behaviour (M3, M5, M6)

**This is not the independent accountant review. It does not satisfy gate G15 and must not be
recorded as if it did.** G15 asks for a dated statement from the accountant the owner commissions,
covering the October close and a second session on a real upgraded copy of the pilot's books, and
entered in [`docs/VALIDATION.md`](../VALIDATION.md). What follows is an internal reading of the code
and the migrations by a reviewer inside the project, written for the maintainer and as preparatory
material for that external accountant. Nothing here is professional sign-off, an opinion, an
assurance conclusion or a compliance statement, and nobody should quote it as one. Where a finding
says a treatment is right, it means the debits and credits are what I would expect from double-entry
and from the standards named — not that the accounting policy, the entity's framework eligibility,
or the Pakistani sales-tax position has been approved by anyone qualified to approve it.

Reviewed at `master` `fbf0fe4`, 21 September 2026. Read-only: no application code, migration or test
was changed. Journals shown below were produced by running the real services against a disposable
MySQL 8.4 schema under a separate Compose project (`phpledger-review`), which was destroyed
afterwards.

---

## Verdict

The core of the 1.2 accounting is sound. Every new posting path goes through the one posting service,
money stays in fixed-precision decimal strings, posted entries stay immutable, corrections are linked
reversals under the same document number, closed periods still refuse postings, and the trial balance
and balance sheet still balance with contra accounts and advances in play. The two structurally
important choices — unapplied credit held as a customer-advances **liability** control rather than
netted inside receivables, and the cash portion of an invoice recognised and settled in one atomic
action — are both right and both well defended in the accompanying documents. What I would not sign
off yet is the edge of those paths. Five findings are, in my reading, wrong accounting or a real
misstatement risk: the free-goods open-market-value output tax is computed on a tax-inclusive base
when the book prices inclusive of tax; an orphan credit note will accept almost any account as its
other side, including the sales account itself; owner transactions silently post to the first
matching account in the chart when none is named; two partners can share one drawings account and
each be shown the whole of it; and migration 036 converts an existing chart without marking any
account as contra, which on an upgraded book removes the drawings account entirely and feeds the
silent default above. None of these is in the main path a normal invoice or receipt takes, which is
why the test suite is green, and all five are small, bounded fixes.

**Findings by severity: 5 material, 7 policy, 5 presentation, 6 minor (23 in total).**

---

## Findings

Severity: **material** = wrong accounting or a misstatement risk; **policy** = a defensible choice the
owner should confirm; **presentation** = classification or disclosure; **minor** = everything else.

### 1. Free-goods output tax at open-market value ignores tax-inclusive pricing — **material**

**What the code does.** With `free_goods_output_tax = open_market_value`, the tax borne by the
business is `pl_tax_amount($openMarket, $rate)` in
[`ar_ap_functions.php:315-316`](../../www/phpledger/includes/functions/ar_ap_functions.php#L315) and
again in the posting plan at
[`ar_ap_functions.php:500-501`](../../www/phpledger/includes/functions/ar_ap_functions.php#L500).
`pl_tax_amount()` ([`tax_functions.php:11-14`](../../www/phpledger/includes/functions/tax_functions.php#L11))
treats its argument as **tax-exclusive**. The open-market value is `quantity × unit_price` taken
straight off the free line, and in a book set to `price_mode = inclusive` that unit price is entered
inclusive of tax. Every valued line in the same document is split correctly — the discount is put
through `pl_tax_split(..., 'inclusive')` two dozen lines earlier at
[`ar_ap_functions.php:522-524`](../../www/phpledger/includes/functions/ar_ap_functions.php#L522) — so
the free line is the only one in the document that is not.

**Run through the code (scenario S4: 10 units sold and 2 given away, prices entered inclusive of 17%):**

```
Invoice recognition                                              Debit        Credit
  1-110-10001-00  Accounts receivable                         250.0000
  4-100-10001-00  Sales and service income                                 213.6752
  2150            Output tax (charged to the customer)                      36.3248
  2150            Output tax on free goods                                   8.5000
  5300            Promotional goods                             8.5000
                                                              --------      --------
                                                              258.5000      258.5000

free_tax_total = 8.5000, i.e. 17% of the tax-INCLUSIVE 50.00
tax actually contained in an inclusive 50.00 = 7.2650
the sold line, by contrast, was split correctly: net 213.6752 + tax 36.3248
```

**Why it matters.** Output tax payable and promotional expense are each overstated by the rate —
1.2350 on a 50.00 supply, 17% too much. The overstatement is in a *tax control account*, which is
reconciled to a filed return; a difference there is not an internal presentation matter.

**Principle.** IAS 2.11 and the general rule that a tax control balance is the tax actually due:
the taxable base of a supply is the consideration excluding the tax itself. For Pakistani sales tax,
the value of a supply under section 2(46) of the Sales Tax Act is exclusive of the tax, and where
open-market value is substituted it is substituted for that exclusive value, not for a tax-inclusive
price.

**Recommendation.** Derive the free line's taxable base the same way a valued line derives its own:
when `price_mode === 'inclusive'`, take `pl_tax_split($openMarket, $rate, 'inclusive')['net']` as the
base before calling `pl_tax_amount()`, in both places. Add a test that posts the same free-goods
invoice under both price modes and asserts the same tax on the same economic supply.

---

### 2. An orphan credit note will accept almost any account as its other side — **material**

**What the code does.** `pl_recognize_unapplied_credit()` screens the offset account only for "not a
registered open-item control and not a bank"
([`advance_functions.php:272-275`](../../www/phpledger/includes/functions/advance_functions.php#L272)).
The error message names sales returns and purchase returns, and
[`ADVANCES-AND-REFUNDS.md`](ADVANCES-AND-REFUNDS.md) §5 says the credit is taken to contra-income,
but nothing enforces either. `pl_open_item_validate_direct_advance_basis()`
([`open_item_functions.php:363-369`](../../www/phpledger/includes/functions/open_item_functions.php#L363))
validates only line 0, the advance line; the offset line is never looked at again.

**Run through the code (scenario S12, customer side):**

```
customer orphan credit offset to the owner capital account (equity):   ALLOWED
customer orphan credit offset to the sales income account:             ALLOWED
customer orphan credit offset to the owner's loan account (liability): ALLOWED
customer orphan credit offset to an ordinary expense account:          ALLOWED
customer orphan credit offset to a bank account:                       refused
customer orphan credit offset to the receivables control:              refused

Last accepted posting:
  2-110-10001-00  Owner's loan account                          1.0000
  2-120-10001-00  Customer advances (unapplied credit)                       1.0000
```

**Why it matters.** Two of those are revenue misstatements rather than clerical oddities. Offsetting
to the **sales account** *credits* nothing and *debits* sales — which happens to be arithmetically
the same as a return, so it is merely a presentation loss; but the same unguarded field equally
accepts an **expense** account, which grosses the profit and loss account up on both sides: revenue
stays at its pre-credit figure and a cost appears that the business never incurred. Offsetting to
equity or to the owner's loan takes a trading adjustment out of profit altogether. The supplier side
is symmetrically open.

**Principle.** IFRS 15.70-72 (consideration payable to a customer reduces the transaction price
unless it is payment for a distinct good or service) and IAS 1.32 (no offsetting unless required or
permitted). A goodwill credit to a customer is a reduction of revenue, not a cost and certainly not a
movement in equity.

**Recommendation.** Constrain the offset by side: for `customer`, an `income`-type account (the
reserved contra-income group being the offered default); for `supplier`, an `expense`-type account.
If a business genuinely needs another account, make that an explicit, reasoned override rather than
the default permissiveness. Repeat the check in
`pl_open_item_validate_direct_advance_basis()` so the posting funnel enforces it too, since that is
where every other open-item rule lives.

---

### 3. Owner transactions silently choose an account when none is named — **material**

**What the code does.** `pl_owner_pick_account()`
([`owner_functions.php:129-139`](../../www/phpledger/includes/functions/owner_functions.php#L129))
returns `$choices[0]` — the first candidate by account code — whenever the caller passes no account,
and `pl_post_owner_transaction()` calls it that way for both the cash side and the owner side
([`owner_functions.php:107-108`](../../www/phpledger/includes/functions/owner_functions.php#L107)).
The candidate list for `capital` is *every* non-contra equity account
([`owner_functions.php:48`](../../www/phpledger/includes/functions/owner_functions.php#L48)), so a
revaluation reserve, a retained-earnings account, a share-premium account and a partner's capital
account are all interchangeable as far as the default is concerned.

**Run through the code (scenario S13: a book with a revaluation reserve at `3-050` and owner equity at
`3-100`, `owner_account_id` left null):**

```
Capital introduced                                              Debit        Credit
  1-100-10001-00  Cash and bank                             500000.0000
  3-050-10001-00  Revaluation reserve                                    500000.0000
```

The other three kinds behaved correctly in the same run (loan received `Dr cash / Cr 2-110`, loan
repaid `Dr 2-110 / Cr cash`, drawings `Dr 3-900 / Cr cash`).

**Why it matters.** Half a million rupees of proprietor's capital landed in a reserve because that
account happens to sort first. Nothing warned, nothing was refused, and the balance sheet still
balances, so no downstream check catches it. `AGENTS.md` states the rule this breaks in one line:
*"Do not silently guess missing accounting data."*

**Principle.** Project rule CORE-04 (stable classification, reviewed mapping changes) and, for a
company, the Companies Act 2017 third-schedule requirement that share capital and reserves be
separately stated. A partner's capital account under the Partnership Act 1932 is personal to that
partner; it is not a pool the software may pick from.

**Recommendation.** Refuse rather than default. If exactly one candidate exists, using it is
defensible; if more than one exists and none was named, throw and make the screen ask. At minimum
require `partner_id` or `owner_account_id` for `capital_introduced` and `drawings`.

---

### 4. Two partners may share one drawings or loan account, and each is shown the whole of it — **material**

**What the code does.** `pl_owner_partners` is unique on `capital_account_id` only
([`036_structured_account_codes.php:155`](../../www/phpledger/install/migrations/036_structured_account_codes.php#L155));
`drawings_account_id` and `loan_account_id` carry no uniqueness constraint, and
`pl_save_owner_partner()`
([`owner_functions.php:258-303`](../../www/phpledger/includes/functions/owner_functions.php#L258))
checks only that the account exists in the right candidate list.
`pl_owner_partner_positions()`
([`owner_functions.php:330-334`](../../www/phpledger/includes/functions/owner_functions.php#L330))
then reads the account balance by id, so a shared account is reported in full against every partner
that names it.

**Run through the code (scenario S14: two partners, one shared drawings account carrying 40,000):**

```
Partner A: share=0.600000  capital=0.0000  drawings=40000.0000  net_capital=-40000.0000
Partner B: share=0.400000  capital=0.0000  drawings=40000.0000  net_capital=-40000.0000
Actual posted drawings on that one account: 40000.0000
```

**Why it matters.** The partners' statement reports 80,000 of drawings against 40,000 posted. It does
not affect the ledger or the balance sheet — the equity total is right — but the partner-level figure
is exactly the figure a partner will rely on, and it is wrong by a factor of the number of partners
sharing the account.

**Principle.** Partnership Act 1932 s.13 and the ordinary requirement that each partner's account be
separately determinable. Section 4 of the Act makes the accounts of the partners the basis of
settlement between them.

**Recommendation.** Make `drawings_account_id` and `loan_account_id` unique per book in the schema
and refuse a duplicate in the service with a readable message. Consider also refusing a capital
account that is already another partner's drawings or loan account.

---

### 5. Migration 036 converts an existing chart without marking any account as contra — **material**

**What the code does.** Migration 036 adds `is_contra` with `DEFAULT 0`
([`036_structured_account_codes.php:29-32`](../../www/phpledger/install/migrations/036_structured_account_codes.php#L29))
and never sets it for any existing row. It also allocates groups strictly in the 100–899 band, so no
converted account lands in a reserved 900 group. The bundled starter chart
(`resources/coa/core-starter-1.1.0.json`) *does* carry `is_contra` on `1-900`, `3-900`, `4-900` and
`5-900`, so a book **born** on 1.2 is correct and a book **upgraded** to 1.2 is not.

**Why it matters.** On an upgraded book:

* `pl_owner_accounts()` classifies an equity account as drawings only when `is_contra` is true
  ([`owner_functions.php:48-49`](../../www/phpledger/includes/functions/owner_functions.php#L48)), so
  a converted chart has **no drawings account at all** and `pl_post_owner_transaction('drawings')`
  throws — drawings cannot be recorded through the new screen;
* the chart's existing "Drawings" account instead appears in the **capital** candidate list, where
  finding 3's silent default can pick it, so capital introduced can be credited to drawings;
* accumulated depreciation, sales returns and purchase returns lose their deduction presentation on
  every report, because `pl_balance_sheet()` and `pl_profit_loss()` carry `is_contra` through for
  display.

**Principle.** CORE-04 again: a conversion must not change what an account means, and here it
silently drops a presentation attribute that the same release then depends on.

**Recommendation.** Either extend 036 (or add a follow-up migration) to set `is_contra` from the
existing `semantic_key` where one is present, or — safer, because guessing from a name is exactly
what the project forbids — add an explicit, reviewed "confirm your contra accounts" step to the
upgrade, list the candidates, and refuse the owner screens until it has been answered. Whichever is
chosen, the upgrade notes must say that a converted chart starts with no contra accounts.

---

### 6. Free-goods carrying value and the output tax borne on them share one expense account — **policy**

`pl_ar_issue_invoice_stock()`
([`ar_ap_functions.php:634`](../../www/phpledger/includes/functions/ar_ap_functions.php#L634)) sends
the carrying value of the goods to `free_goods_account_id`, and the posting plan sends the
open-market-value output tax to the same account
([`ar_ap_functions.php:505`](../../www/phpledger/includes/functions/ar_ap_functions.php#L505)). The
policy document says so explicitly ("The promotional account carries 9.00: the 4.00 carrying value
plus this 5.00").

Verified in scenario S3 (exclusive prices, 17%): recognition credited output tax 8.50 and debited
promotional goods 8.50, and the stock journal debited promotional goods 4.00 against inventory 4.00.
Both are correct entries; the question is only whether they belong in one account.

The defensible options are (a) one promotional account, as built — simple, and the whole cost of the
promotion is in one place; or (b) two accounts, promotional goods for the carrying value and an
irrecoverable-sales-tax expense for the tax. (b) is what I would expect on a Pakistani general ledger,
because the tax figure has to be reconciled to the sales-tax return and the goods figure has to be
reconciled to the stock ledger, and those are two different reconciliations. Recommend offering a
separate `free_goods_tax_account_id` policy with (a) as the default.

---

### 7. A free line with no tax code produces no output tax even when the policy is on — **policy**

`pl_ar_price_document()` resolves the free line's tax code through `pl_tax_calculate()`, which returns
a zero rate for a null code ([`tax_functions.php:126`](../../www/phpledger/includes/functions/tax_functions.php#L126)),
and `pl_ar_validate_dimensions()`
([`ar_ap_functions.php:580-591`](../../www/phpledger/includes/functions/ar_ap_functions.php#L580))
requires the promotional expense account but not a tax code.

**Run through the code (scenario S21, `free_goods_output_tax = open_market_value`, free line with an
open-market value of 25.00 but no tax code):**

```
free_tax_total = 0.0000
  1-110-10001-00  Accounts receivable                           250.0000
  4-100-10001-00  Sales and service income                                  250.0000
```

The invoice posted silently with no output tax on the free supply. That is arguably right — an
exempt or zero-rated supply carries none — but the book has been told free supplies are taxable and
nothing says which reading applies. Recommend either requiring a tax code on a free line while the
policy is `open_market_value`, or stating on the editor that a free line without a tax code is being
treated as a non-taxable supply.

---

### 8. A trading-policy change between saving a draft and posting it is not part of the two-step review — **policy**

`pl_ar_posting_plan()` guards the frozen tax fields and `free_tax_total`
([`ar_ap_functions.php:459-467`](../../www/phpledger/includes/functions/ar_ap_functions.php#L459))
and its own comment says the policies are part of the reviewed plan
([`ar_ap_functions.php:553-555`](../../www/phpledger/includes/functions/ar_ap_functions.php#L553)).
That holds for the one-step editor route, which re-runs the preview and compares a hash of the whole
plan. `pl_post_ar_document()` — the draft-then-post route — checks only the revision.

**Run through the code (scenario S16: draft saved while the policy was `net`, policy switched to
`gross`, then the draft posted):**

```
Draft saved under discount_posting=net; total 225.0000
Posted after the policy changed                                 Debit        Credit
  1-110-10001-00  Accounts receivable                           225.0000
  4-100-10001-00  Sales and service income                                  250.0000
  4910            Discounts allowed                              25.0000
```

No misstatement: the receivable, the total and net revenue are identical under either policy, and
only the split inside income moves. But the entries differ from those the draft was reviewed under,
without a word, and the free-goods guard immediately beside it does exactly the opposite. Recommend
either recording `discount_posting` on the draft and comparing it at posting, or removing the comment
that claims a protection the route does not have.

---

### 9. `is_contra` can be flipped on an account that already has postings — **policy**

`pl_save_account()` fixes `code`, `type` and `role` on edit and refuses to change currency or monetary
classification once postings exist
([`core_functions.php:127-136`](../../www/phpledger/includes/functions/core_functions.php#L127)), but
`is_contra` is updated freely
([`core_functions.php:137`](../../www/phpledger/includes/functions/core_functions.php#L137)).
Scenario S20 flipped the flag on an income account carrying a 500.00 debit balance: **ALLOWED**, and
total income was unchanged at 2,500.00 afterwards — the arithmetic is sign-based and does not use the
flag, only the presentation does.

So nothing is misstated, but every prior report and every comparative silently changes shape. The
change is audited with a reason through `pl_core_audit()`, which is a real mitigation. Recommend
treating `is_contra` like currency: fixed once the account has postings, changeable only by creating
a new account and moving the balance with a reviewed journal. This is the owner's call, not mine.

---

### 10. Oldest-first is by due date, and an item with later activity is skipped rather than part-filled — **policy**

`pl_oldest_first_allocation()`
([`advance_functions.php:86-99`](../../www/phpledger/includes/functions/advance_functions.php#L86))
sorts on `[due_date, item_id]`, not on document date, and an item whose latest activity postdates the
payment is skipped entirely with a stated reason. Both are documented and both are defensible; they
are simply not the only defensible answers. Ordering by **invoice date** is the more common
convention for statutory ageing and for a customer's own "oldest first" expectation, and the two
diverge whenever credit terms differ between invoices. The residual arithmetic is exact — the last
item allocated takes `min(residual, remaining)` and the plan's allocations always sum to the amount
claimed, with no proportional spreading, so **no fraction can be stranded**; I checked this
specifically and it is right. Recommend the owner confirm due-date ordering, and that the screen say
"oldest due first" rather than "oldest first".

---

### 11. The 100-item allocation cap turns the excess into unapplied credit — **policy**

`pl_settlement_allocation_cap()` returns 100
([`settlement_functions.php:12-15`](../../www/phpledger/includes/functions/settlement_functions.php#L12))
and the planner stops there, setting `limited = true`
([`advance_functions.php:95`](../../www/phpledger/includes/functions/advance_functions.php#L95)).

**Run through the code (scenario S22):**

```
105 open items of 10.00, payment 1050.00
  -> allocations = 100, remainder = 50.0000, limited = true
```

The 50.00 becomes a customer advance held against a customer who still owes 50.00 on five invoices.
That is correct double entry and it is visible in both reports, but it is a confusing position to
leave a clerk in, and `pl_settle_open_items()` itself does not surface `limited`. Recommend that a
capped plan be refused at posting with "split this into more than one receipt" rather than quietly
becoming unapplied credit, or at minimum that `limited` be carried into the confirmation screen.

---

### 12. The reserved contra groups are a convention nothing enforces — **policy**

`pl_account_contra_groups()` and `pl_account_code_group_is_reserved()`
([`account_code_functions.php:58-84`](../../www/phpledger/includes/functions/account_code_functions.php#L58))
are referenced **only by `tests/account_code_test.php`**; no application path calls either. Scenario
S20 confirms the consequence:

```
a non-contra account created in the reserved 4-900 band:    ALLOWED
a contra account created outside the band (4-100):          ALLOWED
a contra liability:                                         refused (correctly)
```

and the resulting income tree puts a contra account inside the ordinary revenue group, where its
deduction is netted into that group's subtotal without a caption:

```
4                 Revenue                                     2500.0000
  4-100             Group 4-100                                 3000.0000
    4-100-10001-00    Sales and service income                  3000.0000
    4-100-10002-00    Contra income in a normal group              0.0000
  4-900             Group 4-900                                    0.0000
    4-900-10002-00    Ordinary income in the contra band           0.0000
```

The design is "a flag plus reserved code groups"; what exists is the flag, plus a documented
convention and a migration that politely avoids the band. Recommend the owner decide whether the band
should be enforced (refuse a non-contra account in 900-999, require the flag there) or whether the
code range should be described in the documentation as guidance only.

---

### 13. Tax-inclusive pricing breaks `gross = discount + net`, and the printed invoice mixes bases — **presentation**

`pl_trading_line_discount()` computes the discount on the entered gross, which under inclusive pricing
is tax-inclusive; `pl_tax_split(..., 'inclusive')` then reduces `line_total` to the tax-exclusive net
([`ar_ap_functions.php:323-332`](../../www/phpledger/includes/functions/ar_ap_functions.php#L323)).
`gross_amount` and `discount_amount` stay on the inclusive basis while `line_total` and `subtotal` move
to the exclusive one. `invoice-a4.php:82-88` then prints
`Subtotal = subtotal + discount_total`.

**Run through the code (scenario S2: 10 units at 25.00 inclusive of 17%, less 10%):**

```
Ledger (correct)                                                Debit        Credit
  1-110-10001-00  Accounts receivable                           225.0000
  4-100-10001-00  Sales and service income                                  213.6752
  4910            Discounts allowed                              21.3675
  2150            Output tax                                                  32.6923

Stored line: gross 250.0000, discount 25.0000, net 192.3077, tax 32.6923
  gross == discount + net ?  NO   (250.0000 vs 217.3077)

Printed invoice: Subtotal 217.3077, less discounts 25.0000, tax 32.6923, total 225.0000
True tax-exclusive gross of the line: 213.6752 (and the true discount is 21.3675)
```

The ledger is right — the posting plan splits the discount for the inclusive case at
[`ar_ap_functions.php:522-524`](../../www/phpledger/includes/functions/ar_ap_functions.php#L522). The
document is not: the customer's invoice overstates gross sales and the discount given by 1.6325 each,
the printed "Subtotal" of 217.3077 is a figure on no basis at all, and the line row shows a unit price
of 25.00 against a line amount of 192.3077 that the customer cannot reproduce. Any later "discount
given" analysis built on `discount_total` is on a different basis depending on the book's price mode.

**Principle.** IAS 1.15 and .17(c) — faithful presentation of a document the recipient acts on; and
the general rule that an invoice must show the taxable amount, the rate and the tax, each on its own
basis.

**Recommendation.** Store `gross_amount` and `discount_amount` on the same basis as `line_total` (i.e.
put both through `pl_tax_split()` under inclusive pricing) so the stored triple always reconciles, and
have the print show the line net and tax as the ledger holds them. Add a test asserting
`gross == discount + net` for both price modes.

---

### 14. A credit note debits income rather than sales returns, and never unwinds the discount — **presentation**

A credit note takes its posting account from its own line
([`ar_ap_functions.php:509-513`](../../www/phpledger/includes/functions/ar_ap_functions.php#L509));
any income account is accepted and the contra flag is not consulted, so nothing defaults to or
requires the reserved `4-900` sales-returns group. Separately, the residual a credit is capped
against is the original line's **net** `line_total`
([`ar_ap_functions.php:343-346`](../../www/phpledger/includes/functions/ar_ap_functions.php#L343)), so
the natural entry carries no discount percentage.

**Run through the code, gross policy, invoice of 10 units at 25.00 less 10%:**

*S18 — the natural entry, crediting 5 units at the net 22.50:*

```
  1-110-10001-00  Accounts receivable                                        112.5000
  4-100-10001-00  Sales and service income                      112.5000

Resulting balances: gross sales 137.5000, discounts allowed 25.0000, net revenue 112.5000
Crediting the same line at its gross 25.00 instead: refused - "This credit exceeds the
original line remaining amount."
```

*S17 — the same credit with the 10% discount re-entered:*

```
  1-110-10001-00  Accounts receivable                                        112.5000
  4-100-10001-00  Sales and service income                      125.0000
  4910            Discounts allowed                                           12.5000

Resulting balances: gross sales 125.0000, discounts allowed 12.5000, net revenue 112.5000
```

Net revenue is 112.50 either way, which is right. But in the natural entry, gross sales is 137.50 and
discounts allowed 25.00 against goods for which only 12.50 of discount was ever given — and the
operator is *forced* into it, because crediting at the gross price is refused and nothing tells them
to repeat the percentage. For a book that chose `gross` precisely so that discount is a readable
figure, the figure is wrong. In both cases the return is buried in the sales account rather than shown
as sales returns.

**Principle.** IFRS 15.55 (refund liabilities and returns presented separately from revenue) and
IAS 1.32. The reserved `4-900` group exists for exactly this and is not used by the path that should
use it.

**Recommendation.** Default a customer credit's line account to the reserved sales-returns
contra-income account (letting the operator override with a reason), and either carry the original
line's discount percentage onto the credit automatically or state on the screen that the credit is
entered at the net price and the discount will not be unwound.

---

### 15. Accumulated depreciation presents as a sibling group, not as a deduction within its asset class — **presentation**

`pl_balance_sheet()` signs a contra account naturally and `pl_report_tree()` attaches it to its own
code group ([`report_functions.php:97-113`](../../www/phpledger/includes/functions/report_functions.php#L97)),
and the reserved band puts accumulated depreciation at `1-900` — a different group from the
fixed-asset group it relates to.

**Run through the code (scenario S15: equipment 120,000, depreciation 24,000):**

```
1                 Assets                                      586000.0000
  1-100             Group 1-100                                490000.0000
    1-100-10001-00    Cash and bank                            490000.0000
  1-130             Group 1-130                                120000.0000
    1-130-10001-00    Office equipment at cost                 120000.0000
  1-900             Group 1-900                                -24000.0000
    1-900-10001-00    Accumulated depreciation                 -24000.0000

total assets 586000.0000, balanced = true
trial balance debit 674000.0000 = credit 674000.0000, balanced = true
```

The totals are right and the trial balance is unaffected, exactly as
[`OWNER-TRANSACTIONS.md`](OWNER-TRANSACTIONS.md) §8 claims. What the reader cannot obtain is the net
book value of a class of asset: cost and accumulated depreciation sit in different groups at the same
level, and a chart with several asset classes sharing one `1-900` group makes them unallocatable.
Note also the placeholder captions — "Group 1-900", "Group 1-130" — because the chart ships no
group-heading accounts for `pl_report_tree_node()` to name
([`report_functions.php:248-261`](../../www/phpledger/includes/functions/report_functions.php#L248)).
The same appears in the equity and liability trees.

**Principle.** IAS 16.73(d)-(e) requires gross carrying amount and accumulated depreciation to be
reconciled **for each class** of asset; IAS 1.54(a) presents property, plant and equipment as one
line.

**Recommendation.** For the accountant to settle: either a sub-account convention (accumulated
depreciation as `1-130-10001-01` under the asset it relates to) or a report-level pairing that pulls
a `1-900` account into the group it offsets. Separately, ship group-heading accounts in the starter
chart so reports stop printing "Group 2-120".

---

### 16. The starter chart is missing accounts the 1.2 policies and documents assume — **presentation**

`resources/coa/core-starter-1.1.0.json` contains 13 accounts. Against what 1.2 now needs:

| Assumed by | Present? |
|---|---|
| `4-910 Discounts allowed`, named in [`trading-document-policies.md`](examples/trading-document-policies.md) §1 and in `pl_account_contra_groups()` | **No** — `4-900` is a combined "Sales returns and discounts allowed" |
| `5-910 Discounts received` | **No** — combined into `5-900` |
| A realised FX gain account and a realised FX loss account | **No** — the only income account is `4-100 Sales and service income`, which is what [`ADVANCES-AND-REFUNDS.md`](ADVANCES-AND-REFUNDS.md) §3 uses in its worked example |
| Any property, plant and equipment account to depreciate | **No**, although `1-900 Accumulated depreciation` is shipped |
| Class and group heading accounts | **No** — every report prints "Group X-YYY" |

The FX one has teeth: `pl_settlement_plan()` and `pl_apply_unapplied_credit()` require an account of
type `income` for a realised gain, and in the shipped chart the only candidate is the sales account,
so a realised exchange gain becomes revenue. `pl_save_trading_policies()` also refuses `gross` discount
posting unless a contra-income account is named, and the only shipped candidate is the combined
returns-and-discounts account, which defeats the separation the policy document promises.

**Principle.** IAS 21.52(a) requires exchange differences recognised in profit or loss to be disclosed
as such; IAS 1.85 requires additional line items where relevant to understanding performance. Sales
revenue is not the place for an exchange gain.

**Recommendation.** Add `4-910`, `5-910`, a realised FX gain and a realised FX loss account, and the
class/group headings, to the starter chart. Correct the worked example in
[`ADVANCES-AND-REFUNDS.md`](ADVANCES-AND-REFUNDS.md) §3, which currently shows `4-100-10001-00` as the
FX gain account.

---

### 17. Cost-of-sales classification is not carried to the contra-expense group — **presentation**

`pl_profit_loss()` splits expenses into cost of sales and other expenses on
`report_classification === 'cost_of_sales'`
([`report_functions.php:73-79`](../../www/phpledger/includes/functions/report_functions.php#L73)), and
`pl_save_account()` accepts that classification on any expense account without reference to the contra
band. A book that classifies purchases as cost of sales but leaves `5-900 Purchase returns and
discounts received` unclassified will show gross profit **overstated** by the returns and operating
expenses **understated** by the same amount, while net profit is right.

**Principle.** IAS 1.99-103: an analysis of expenses by function requires each amount to be allocated
to the function it belongs to; a return reduces the cost it reversed.

**Recommendation.** When an expense account sits in a reserved contra group, require its
`report_classification` to be stated, and surface the pairing in Admin so the reader can see which
cost-of-sales figure a return is netting against.

---

### 18. Migration 036 derives the group from the first two characters of the old code — **minor**

`UPPER(LEFT(a.code, 2))` at
[`036_structured_account_codes.php:82-89`](../../www/phpledger/install/migrations/036_structured_account_codes.php#L82),
partitioned by book and class. For a numeric chart this reproduces the chart's own grouping and is a
good choice. For an alphanumeric one it does not: `CASH` and `CAPITAL` both key on `CA` and are merged
into one group, while a one-character code and a two-character code that differ only after position
two are split arbitrarily. Separately, group numbers are allocated `100 + 10 × (n − 1)`, so the
**81st** distinct prefix in a single class reaches 900 and violates
`ck_account_conversion_group`, aborting the migration mid-upgrade. The comment says this is
deliberate — "refuses a chart wide enough to reach the reserved band rather than silently colliding
with it" — and failing closed is the right instinct, but the failure surfaces as a raw SQL constraint
error, not as guidance.

**Recommendation.** Add a pre-flight check to the installer that counts distinct prefixes per class
and per book and reports, before anything is written, which books cannot be converted and why. State
in the upgrade notes how the group is derived, so an operator with an alphanumeric chart knows to
review the result.

---

### 19. Migration 039 silently skips books with no room for the advances controls — **minor**

The insert at
[`039_advances_and_refunds.php:118-144`](../../www/phpledger/install/migrations/039_advances_and_refunds.php#L118)
is guarded by `... + 10 <= 890`. A book whose highest structured group in that class is already 890 or
above gets **no** customer-advances or supplier-advances account, and nothing records that it was
skipped. The failure surfaces much later, as "Choose the customer advances control account; there must
be one unambiguous default" from `pl_advance_control()`
([`advance_functions.php:28`](../../www/phpledger/includes/functions/advance_functions.php#L28)), the
first time a customer overpays. Failing closed is right; failing silently is not. Recommend the
installer report the skipped books, and that the upgrade notes tell the operator to create the two
accounts by hand in that case.

---

### 20. A receipt whose advance was applied and then un-applied can only be reversed at today's date — **minor**

[`ADVANCES-AND-REFUNDS.md`](ADVANCES-AND-REFUNDS.md) §8 says: "Part of the remainder has already been
applied to an invoice → **The whole-receipt reversal is refused.** Reverse the application first, then
the receipt." Both halves of the block work, but the second one cannot be dated back.

**Run through the code (scenario S19: receipt 2026-03-01, application 2026-03-05, application
reversed 2026-03-05):**

```
receipt reversal dated at the receipt date (2026-03-01): refused -
    "A reversal cannot precede the latest open-item activity."
receipt reversal dated today (2026-09-21):               ALLOWED
```

The backdating rule
([`ledger_functions.php:241-246`](../../www/phpledger/includes/functions/ledger_functions.php#L241))
allows only today's date or the original posting date, and the original posting date now precedes the
application reversal. So the correction of a March receipt lands in September — a different quarter,
possibly a different financial year. That is a legitimate consequence of "a reversal cannot precede
the latest activity", but it is a period-allocation decision the document does not mention.

**Recommendation.** Say so in §8, and have the screen state the date the reversal will take before it
is confirmed.

---

### 21. Journal descriptions carry the pre-series document number — **minor**

`pl_ar_posting_plan()` builds `$description = $document['number'] . ' - ' . party`
([`ar_ap_functions.php:491`](../../www/phpledger/includes/functions/ar_ap_functions.php#L491)) before
`pl_document_series_allocate()` runs at
[`ar_ap_functions.php:713`](../../www/phpledger/includes/functions/ar_ap_functions.php#L713), and
`pl_ar_settle_invoice_cash()` does the same at
[`ar_ap_functions.php:745`](../../www/phpledger/includes/functions/ar_ap_functions.php#L745).

**Run through the code (scenario S5):**

```
allocated document_number = 'INV-2026-000001'
displayed number          =  INV-2026-000001
recognition       journal description = INV-000012 - Sample trading customer
cash settlement   journal description = Cash received on INV-000012
```

`INV-000012` is the row id in the legacy derived form and appears on no document. Somebody tracing
from the general ledger to the source document — which is the first thing an auditor does — is given a
reference that does not exist. The link itself is sound (the journal, the open item and the document
revision are all keyed properly), so this is a labelling defect, not a traceability failure.
Recommend allocating the series number before the plan is built, or restating the description after
allocation.

---

### 22. The settlement preview drops the exchange-difference line when no account is named — **minor**

`pl_settlement_plan()` throws only when `$posting` is true
([`settlement_functions.php:116-117`](../../www/phpledger/includes/functions/settlement_functions.php#L116));
during a preview it simply omits the line, so the previewed journal does not balance while the posted
one does. The reviewer sees a proposal that could not be posted, with no explanation. Recommend the
preview include a placeholder line marked "account not yet chosen" so the review screen still shows a
balanced entry.

---

### 23. `pl_account_code_group_is_reserved()` ignores its class argument — **minor**

[`account_code_functions.php:81-84`](../../www/phpledger/includes/functions/account_code_functions.php#L81)
takes `$class` and never reads it. Harmless today because the band is the same for every class and
nothing in the application calls the function (see finding 12), but it will mislead the next person to
use it. Recommend dropping the parameter or using it.

---

## What I checked and found correct

A review that lists only problems is not a review. These were checked specifically and are right.

**The invariants (review area 4).** Every new posting path in M3, M5 and M6 builds lines and hands
them to `pl_post_journal()` / `pl_post_journal_locked()`; I found no path in the new code that writes
`pl_journal_lines` directly. Money is `bcmath` decimal strings throughout the new files — no float
appears in any of them. The open-item funnel keeps its grip: `pl_open_item_validate_posting()` refuses
a tracked control line without an explicit open-item source, `pl_open_item_source_types()` now includes
the two new sources, and `pl_open_item_validate_application_basis()` refuses an application that
touches cash or an untracked account. Reversals stay one-deep — "A reversal cannot itself be reversed
in this foundation" was confirmed in scenario S8b — and the mirror map in
`pl_open_item_track_posting()` correctly pairs `application` with `application_reversal`. Every
scenario I posted kept the trial balance in balance (S15: 674,000 = 674,000) and the balance sheet
`balanced = true`.

**Correction under the same number (decision B7).** `pl_correct_ar_document()` reverses and re-posts
without calling `pl_document_series_allocate()` again, so the corrected document keeps its original
number and both journals stay linked. That is what B7 asks for and it is what the code does.

**Cash on the invoice.** Recognition and settlement are one action inside one transaction (scenario
S5): `Dr Receivables 250 / Cr Sales 250`, then `Dr Cash 100 / Cr Receivables 100`, leaving the invoice
`partially_paid` with 150.00 outstanding. The settlement goes through the ordinary open-item service,
so the allocation, the frozen carrying value and the open-item history are identical to a receipt
entered on the payments screen. The default cap is zero, so the feature is off until the owner turns
it on, and the ceiling is checked against both the cap and the invoice total. In-place correction of a
cash invoice is refused with a clear message — *"This invoice settled cash when it was posted. Reverse
it and enter a corrected invoice, so the cash and the receivable are released together"* — and the
reversal releases the allocation first and the recognition second, both in one transaction.

**The frozen tax snapshot against a changed discount.** `pl_ar_posting_plan()` reprices and compares
every tax field, including `tax_amount`, between the reviewed draft and the repriced document
([`ar_ap_functions.php:458-461`](../../www/phpledger/includes/functions/ar_ap_functions.php#L458)).
Because tax is computed on the **discounted** net, a changed discount moves `tax_amount` and the
posting is refused. The free-goods tax total is guarded separately and explicitly. This was the
question I most expected to find broken and it is not.

**Gross discount posting, tax-exclusive.** Exactly the documented entries (scenario S1):
`Dr Receivables 225 / Cr Sales 250 / Dr Discounts allowed 25`, with the stored line reconciling
`gross 250 = discount 25 + net 225`. Under inclusive pricing the **ledger** is also right (finding 13
is about the stored line and the print, not the posting). A discount on a supplier bill always posts
net whatever the sales policy says, which is correct — contra-income is a sales concept.

**Free goods carry no revenue and issue stock.** A free line produces no receivable, no revenue and no
customer tax; its carrying value leaves stock to the promotional expense account rather than to cost
of sales, which is right — nothing was sold. Scenario S3 confirmed both journals, including that the
sold units still went to cost of sales at 20.00 while the free units went to promotional goods at 4.00.
The service refuses to post a free-goods line until the promotional account is named rather than
guessing cost of sales. Charging the free-goods output tax to the business and keeping it out of the
customer's total is, in my view, the correct answer for Pakistan: the recipient of a free supply has
no consideration to bear tax on, and billing them would make the invoice total something they never
agreed to pay.

**Pack resolution.** A pack resolves to base units before pricing, tax and stock issue, and pack size
is frozen at creation by both the service and a database trigger. A posted line stores only the
resolved quantity, so history can never be restated by editing a pack. Correct, and the reasoning in
the code comment is the right reasoning.

**Advances as a liability control, and no offsetting.** This is the best piece of accounting in the
release. Unapplied customer credit is a `payable`-direction open item on a `customer_advances`
**liability** control; supplier advances mirror it as an asset. Receivables ageing filters advances
out (`COALESCE(i.nature,'document')='document'`) and `pl_unapplied_credit()` reports them separately
with its own control reconciliation, so the two reports together cover every open item exactly once
and no customer advance appears among payables. Scenario S11:

```
receivables ageing: items=0  total_base=0.0000   reconciled=true
payables ageing:    items=0  total_base=0.0000
unapplied credit:   items=3  total_base=16.0000  reconciled=true
balance sheet balanced=true  assets=23.0000  liabilities=16.0000  equity=7.0000
  assets       1-100-10001-00  Cash and bank                          23.0000
  liabilities  2-120-10001-00  Customer advances (unapplied credit)   16.0000
```

The customer's debt and the money held for them are presented separately at their gross amounts. That
satisfies IAS 1.32 and IAS 32.42 — there is no legally enforceable right of set-off being asserted and
none is needed, because nothing is being offset. The stated reasoning ("a customer with a 40,000
invoice and 10,000 on account does not owe 30,000") is correct and I would defend it. The database
enforces both halves of the tie: an advance item can only sit on a registered advances control (CHECK
plus foreign key) and a document item can never sit on one (BEFORE INSERT trigger).

**One voucher, one bank line.** A receipt with a remainder produces a single journal with a single bank
movement, so bank reconciliation sees one receipt (scenario S6):

```
  1-110-10001-00  Accounts receivable                                         10.0000
  1-110-10001-00  Accounts receivable                                         10.0000
  2-120-10001-00  Customer advances (unapplied credit)                         5.0000
  1-100-10001-00  Cash and bank                                  25.0000
```

**Applying credit moves no money.** Scenario S7: `Dr Customer advances 3.00 / Cr Receivables 3.00`,
with no bank line, and the validator refuses any line on a bank or untracked account. The realised
exchange difference is taken when two frozen rates meet, and I checked the sign logic in
`pl_apply_unapplied_credit()`
([`advance_functions.php:184-196`](../../www/phpledger/includes/functions/advance_functions.php#L184))
algebraically for all four combinations of side and sign: it is correct, and it agrees with the worked
example in §3 of the document.

**Refunds.** Scenario S9: `Dr Customer advances 2.00 / Cr Cash and bank 2.00`, posted through the
ordinary single-item settlement service so every settlement rule still applies. Refunding an invoice
open item is refused — *"Only unapplied credit is refunded this way. Reverse or credit a document
instead"* — and allocating an advance as if it were an invoice is refused too.

**The reversal rules, including the one the brief asked about.** Confirmed by running them:

* whole receipt reversed while the remainder is untouched — **allowed**, and it unwinds all three
  legs and the bank in one entry (scenario S8a);
* whole receipt reversed after the advance has been applied — **refused**: *"Reverse the active
  allocations explicitly before reversing this recognition"* (scenario S8b). This is
  `pl_open_item_assert_correction_allowed()`
  ([`open_item_functions.php:436-448`](../../www/phpledger/includes/functions/open_item_functions.php#L436))
  comparing the item's remaining balance with its recognition amount. It works;
* the application reversed on its own — **allowed**, and both sides return exactly to where they
  were (advance back to 5.0000 fc / 5.0000 base);
* a reversal reversed — **refused**.

**The orphan credit note's shape.** `Dr Sales returns and discounts allowed 12.00 / Cr Customer
advances 12.00` (scenario S10) is the right entry, and bank and control-account offsets are both
refused. Finding 2 is about everything else the offset field will accept, not about this.

**Owner transactions.** Three of the four are exactly right and were confirmed by posting them
(scenario S13): the owner's loan is a liability with a memorandum disclosure line in the equity
section rather than a second posting; repayment is not an expense; drawings debit a contra-equity
account rather than capital, so the year's withdrawals stay on their own readable line. Treating the
owner's loan as a liability is correct under both the Companies Act 2017 (a director's or member's
loan is a liability of the company) and the Partnership Act 1932 s.13(d), which distinguishes a
partner's advance from their capital. The equity total behaves: recorded equity 460,000 =
capital 500,000 less drawings 40,000, and the balance sheet balanced.

**Partners.** The register is honest about its limits. Ratios can never total more than 1, a total
below 1 is reported as incomplete rather than having the remainder invented, and **no profit
allocation is posted at all**. That restraint is right: the order of appropriation, interest on
capital, interest on drawings, loss sharing, and the fixed-capital-plus-current-account presentation
are deed questions, and the six open questions listed at the end of
[`OWNER-TRANSACTIONS.md`](OWNER-TRANSACTIONS.md) are the correct six questions. A contra **liability**
is refused, matching what that document claims.

**The code conversion preserves identity.** Migration 036 changes only `code` and adds `legacy_code`;
the primary key, type, role, currency, status and every posted journal line are untouched, the old
number is kept twice (on the row and in an immutable map with no-update and no-delete triggers), and
a reversal path is written down. `pl_account_code_mapping()` keeps fixtures and demo packs resolving by
either number. Headings are refused postings in the one central funnel by
`pl_account_is_postable()`, not per screen. A legacy-numbered account is never dropped from a report —
it hangs under its class heading (confirmed in scenario S20) — which is the right trade against
silently losing a balance.

**Contra presentation arithmetic.** `pl_trial_balance()` keeps every account's natural side, so debits
and credits still agree with contra accounts present. `pl_profit_loss()` computes income as
`credit − debit`, so a contra-income account reduces revenue (scenario S11 showed sales 20.00 less
sales returns 12.00 = income 8.00), and `pl_balance_sheet()` signs liabilities and equity as
`−balance`, so drawings reduce equity and accumulated depreciation reduces assets. The arithmetic is
right in every case; findings 15 and 17 are about where the figures are placed, not what they are.

---

## Questions that genuinely need the external accountant

1. **Is the promotional expense account the right home for the carrying value of free goods, or should
   it sit within cost of sales with separate analysis?** The code's reasoning ("nothing was sold, so
   nothing belongs in cost of sales") is coherent, but a distributor whose bonus schemes are a routine
   cost of selling may want them inside gross margin. This changes what gross profit means.
2. **Where a free supply is taxable in Pakistan, is open-market value the right base, and is it the
   selling price or the carrying value?** Finding 1 is about the arithmetic; this is about the base.
   The answer determines whether the `unit_price` on a free line is the right field to read at all.
3. **Should the output tax borne on a free supply be an irrecoverable-tax expense separate from
   promotional goods (finding 6)?** This is partly a reconciliation question and partly a
   sales-tax-return question.
4. **Is a credit note granted to a customer a reduction of revenue in every case relevant here, or can
   it be a distinct expense?** This decides how tightly finding 2's offset account should be
   constrained, and whether a settlement discount should be treated differently from a goodwill
   credit under IFRS 15.70-72.
5. **How should accumulated depreciation be presented (finding 15)?** A sub-account under the asset it
   relates to, or a report-level pairing? IAS 16.73 requires the reconciliation per class; the
   structure must be able to produce it.
6. **Should aged unapplied credit ever be written back to income, and after how long?** The
   application deliberately does not do this. Pakistani practice on unclaimed customer balances, and
   whether any statutory period applies, is outside what I can settle.
7. **Balance-sheet captions for advances.** "Advances from customers" within current liabilities, or an
   ordinary liability line? And should the supplier mirror be "Advances to suppliers" within current
   assets? This is the question the M5 document itself raises and it remains open.
8. **Is the profit-and-loss treatment of a realised exchange difference on applying a foreign advance
   correct, and which accounts should it default to?** Related to finding 16: the starter chart has no
   account for it.
9. **The six partnership questions in [`OWNER-TRANSACTIONS.md`](OWNER-TRANSACTIONS.md)** — order of
   appropriation, interest on capital and on drawings, loss sharing, timing, admission/retirement and
   goodwill, and fixed capital plus current accounts. Nothing should be posted for partners until
   these are answered; the code is right not to guess.
10. **Should drawings be closed to capital at each year end?** A period-closing policy the application
    deliberately does not perform.
11. **Does the unapplied-credit representation itself stand?** The customer-advances control is the
    recommendation and it is what is built. Confirming it closes the largest open structural question
    in M5.

## Questions the maintainer can settle without the accountant

* Findings 1, 2, 3, 4 and 5 are defects with a clear right answer; none needs an accounting opinion to
  fix, only to schedule.
* Finding 8: either record `discount_posting` on the draft and compare it at posting, or delete the
  comment that claims a protection the two-step route does not have. Both are cheap.
* Finding 11: whether a capped allocation should refuse or become unapplied credit. This is a usability
  and internal-control choice.
* Finding 12: whether the reserved 900 band is enforced or is documentation-only. Decide, then make the
  code and the documents agree.
* Findings 13 and 21: storing the discount on the same basis as the net, and using the allocated series
  number in journal descriptions. Both are internal consistency.
* Findings 18, 19 and 22: pre-flight reporting for the two migrations, and a placeholder line in the
  settlement preview.
* Finding 23: the unused parameter.
* Finding 10 is worth putting to the owner rather than the accountant: due-date ordering versus
  document-date ordering is a collections policy, and the screen should say which one it uses.

## How the journals above were produced

The real services were driven against a disposable MySQL 8.4 schema in a separate Compose project
(`docker compose -p phpledger-review --profile test`, its own subnet), using the existing test
fixtures for the chart, party, product and module set-up. Five scenario scripts were run from a
scratch directory outside the repository and the stack was destroyed afterwards. Nothing was written
to the shared development or test databases, and no repository file other than this one was created or
changed. The scenario numbers (S1 to S22) referenced throughout are the sections of those scripts; the
figures quoted are the actual output, not worked examples.
