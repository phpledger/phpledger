# Advances, refunds and orphan credit notes — worked examples for review

**Status:** implemented on branch `m5/advances-refunds` for PHP Ledger 1.2,
**pending accountant review** under owner decision B30. Owner decisions B39
(nothing is deferred: supplier advances, cash refunds of unapplied credit and
credit notes without an original invoice are all in 1.2), B57 (a batch of
receipts posts one voucher per customer, never a shared multi-customer
voucher), B59 (unapplied credit is held on a customer-advances control, not in a
suspense account) and B54 (accounting standards prevail over any prototype)
govern what follows.

This document is written for the accountant, not for the developer. Every
journal below is what the application actually posts; the amounts are
illustrative. Unless a line says otherwise the currency is the book's functional
currency and all figures are exact decimals. Account codes are the ones the
bundled chart uses; a converted chart keeps its own numbers.

## The representation, in one paragraph

When a customer pays more than their open invoices, the extra is **not** a
credit balance sitting inside receivables and it is **not** parked in a suspense
account. It is an **advance received from that customer**: an ordinary liability
on its own control account, `2-120-10001-00 Customer advances (unapplied
credit)`. The mirror for suppliers, `1-120-10001-00 Supplier advances
(prepayments)`, is an asset. Each advance is tracked as its own open item, so it
keeps a party, a currency, a frozen exchange rate, an age and a full audit trail
— exactly as an invoice does — and the ageing report reconciles the control
account to the advances outstanding on it.

**Why not net it inside receivables.** A customer with a PKR 40,000 unpaid
invoice and PKR 10,000 on account does not owe PKR 30,000: they owe PKR 40,000
and the business owes them PKR 10,000. The balance sheet says so, the ageing
says so, and the statement shows both. Netting would understate both the
receivable and the obligation, and would hide the credit from the person chasing
the invoice.

**Why not a suspense account.** Suspense is for money whose owner is unknown.
This money's owner *is* known — that is the only reason it can be applied to
their next invoice. Putting it in suspense would lose the party and make the
balance unattributable at the year end.

**The alternatives and how to get back.** The credit-balance-inside-receivables
and suspense-account alternatives were both considered and are recorded in
decision 9 of the [1.2 release plan](../strategy/RELEASE-PLAN-1.2.md#decisions).
The account roles are chart data, so reversing this choice is a new migration
plus bounded rework — not a rewrite of posted history. Nothing below is netted,
inferred or guessed.

## What the application does and does not do

* Every journal here goes through the one central posting service that invoices,
  receipts and general journals use. There is no second ledger, no separate
  advances ledger and no unposted balance shown as a figure.
* A posted entry is immutable. A correction is a **linked reversal** on or after
  the original date, exactly as for any other posted journal.
* An advance open item can only exist on an advances control, and a document open
  item can never sit on one. The database enforces both halves (migration
  `037_advances_and_refunds`).
* The direction of an open item stays what it always was — `receivable` is
  debit-normal, `payable` is credit-normal. A customer advance is credit-normal
  and so is a `payable` item on the customer-advances control; a supplier advance
  is debit-normal and so is a `receivable` item on the supplier-advances control.

---

## 1. A receipt with an unallocated remainder

Al-Shifa Pharmacy owes PKR 20,000 on two invoices and pays PKR 25,000 on
1 March 2026. The oldest-first plan fills INV-2026-000107 (due first) and then
INV-2026-000098; PKR 5,000 is left over.

**One voucher, one bank line:**

| Account | Code | Debit | Credit |
|---|---|---:|---:|
| Accounts receivable — INV-2026-000107 | `1-110-10001-00` | | 10,000.00 |
| Accounts receivable — INV-2026-000098 | `1-110-10001-00` | | 10,000.00 |
| Customer advances (unapplied credit) | `2-120-10001-00` | | 5,000.00 |
| Cash and bank | `1-100-10001-00` | 25,000.00 | |

The remainder is recognised **in the same journal** as the receipt, not in a
second entry posted afterwards. There is exactly one bank line, matching one
bank movement, so bank reconciliation sees one receipt of PKR 25,000.

A receipt may also be **entirely** on account: a customer with no open invoice
who pays a deposit produces two lines, Dr Cash and bank, Cr Customer advances.

**Allocation order.** The plan fills the earliest **due date** first, breaking a
tie by the order the documents were recorded. It skips an item whose latest
activity is dated after the payment, because the ledger refuses a settlement
that precedes an item's own last movement, and it says which items it skipped.
The last item allocated takes the exact residual, so the allocations always sum
to the amount they claim. **The plan is a proposal:** every allocated amount can
be edited before posting, to match a customer's own remittance advice (design
frame decision 21). What is stored is always "this amount was applied to that
invoice", never a rule.

## 2. Applying unapplied credit later

On 5 March the same customer is invoiced again and the PKR 5,000 is applied.

| Account | Code | Debit | Credit |
|---|---|---:|---:|
| Customer advances (unapplied credit) | `2-120-10001-00` | 5,000.00 | |
| Accounts receivable — INV-2026-000131 | `1-110-10001-00` | | 5,000.00 |

**There is no bank line and no cash moves.** The obligation to the customer is
discharged by cancelling an equal part of their debt. The application is
refused if it would touch cash, another party, another currency, or the other
side of the ledger (customer credit against a supplier's bill).

## 3. The same, in a foreign currency

The advance and the invoice were each frozen at their own rate, so applying one
to the other realises the difference between those two rates. Suppose USD 5 of
advance was received at 282 and the invoice it is applied to was raised at 280.

| Account | Code | Debit | Credit |
|---|---|---:|---:|
| Customer advances — USD 5 at 282 | `2-120-10001-00` | 1,410.00 | |
| Accounts receivable — USD 5 at 280 | `1-110-10001-00` | | 1,400.00 |
| Realised FX gain | `4-100-10001-00` | | 10.00 |

Each side is relieved at its own historic carrying value; neither is restated.
The gain or loss is realised on application, the same way a bank settlement
realises it on payment. The account is chosen on the review screen, and the
posting is refused if the difference has no account named for it.

## 4. Cash refund of unapplied credit

On 10 March PKR 9,000 of the credit is refunded to the customer.

| Account | Code | Debit | Credit |
|---|---|---:|---:|
| Customer advances (unapplied credit) | `2-120-10001-00` | 9,000.00 | |
| Cash and bank | `1-100-10001-00` | | 9,000.00 |

A refund is the ordinary settlement of the advance open item against the bank,
so it obeys every settlement rule already in force: it cannot exceed what is
left, it cannot precede the advance's last movement, and it needs an open
period. A refund can never be aimed at an invoice, and an invoice can never be
refunded this way — a wrongly raised invoice is corrected by a credit note or a
reversal, not by paying the customer.

## 5. A credit note with no original invoice

A goodwill credit of PKR 12,000 is granted on 2 March with no invoice behind it.

| Account | Code | Debit | Credit |
|---|---|---:|---:|
| Sales returns and discounts allowed | `4-900-10001-00` | 12,000.00 | |
| Customer advances (unapplied credit) | `2-120-10001-00` | | 12,000.00 |

The contra-income account is the one reserved under B60, so the credit reduces
reported sales as a visible deduction rather than being buried in a net figure.
From this point the PKR 12,000 behaves like any other unapplied credit: it is
applied to the customer's next invoice, or refunded. The other side of such a
credit is never a bank account and never a control account; the application
refuses both.

**The supplier mirror** debits `1-120-10001-00 Supplier advances` and credits
`5-900-10001-00 Purchase returns and discounts received`.

## 6. Supplier advances

The supplier side is the same mechanism with the signs the other way round. A
payment of PKR 25,000 against PKR 10,000 of bills:

| Account | Code | Debit | Credit |
|---|---|---:|---:|
| Accounts payable — BILL-2026-000044 | `2-100-10001-00` | 10,000.00 | |
| Supplier advances (prepayments) | `1-120-10001-00` | 15,000.00 | |
| Cash and bank | `1-100-10001-00` | | 25,000.00 |

and the later application against a bill:

| Account | Code | Debit | Credit |
|---|---|---:|---:|
| Accounts payable — BILL-2026-000051 | `2-100-10001-00` | 10,000.00 | |
| Supplier advances (prepayments) | `1-120-10001-00` | | 10,000.00 |

A supplier's refund of a prepayment debits the bank and credits supplier
advances.

## 7. Batch receipts across customers (B57)

A salesman's end-of-day cash covers many customers. The grid is an **entry
convenience only**: each row posts its **own** voucher, with its own bank line,
its own allocations and its own remainder, and can be reversed on its own. A
single shared voucher was considered and rejected — reversing one customer's
receipt would then mean editing a voucher that belongs to several customers.

One customer's PKR 12,000 and another's PKR 7,000 produce two separate
vouchers, not one journal with two customer lines. The batch is written in one
database transaction, so it is all-or-nothing, and it carries one request
receipt, so re-submitting the page cannot post a second set.

## 8. Corrections (decision B7)

| Situation | What the application allows |
|---|---|
| A receipt was entered in error and its remainder is untouched | Reverse the whole receipt. Every allocation and the advance are undone together. |
| Part of the remainder has already been applied to an invoice | **The whole-receipt reversal is refused.** Reverse the application first, then the receipt. |
| Credit was applied to the wrong invoice | Reverse the application on its own. Both sides return to exactly what they were. |
| The advance has been reversed | It can never be applied or refunded again, and it is never "un-reversed". A reversal cannot itself be reversed; if the money really was received, record it again as a new receipt. |

Every correction is a linked reversal on or after the original date; nothing is
edited in place and no history is deleted.

## 9. Ageing and the customer statement

Unapplied credit is a **separate section**, not a negative line inside the
ageing. The section lists each advance with its party, reference, date, age and
balance, and reconciles the advances control account's posted ledger balance to
the advances outstanding on it — the same reconciliation the receivables ageing
already does for its own controls. A book's receivables ageing plus the
unapplied-credit section together cover every open item exactly once, with no
double counting and no customer advance appearing among payables.

The customer statement shows unapplied credit as its own tagged row inside the
activity, explained in the closing-balance caption (design frame decision 9).

## What is left for the accountant to decide

1. **The representation itself.** The customer-advances control is the
   recommendation and it is what is built; confirm it, or ask for the
   credit-balance-inside-receivables alternative, which needs a new migration.
2. **Where an orphan credit note's other side belongs.** The bundled chart's
   contra-income account (sales returns and discounts allowed) is the default
   offered. If a particular business should use a different income or expense
   account, that is a chart choice, not a code change.
3. **Whether an aged unapplied credit should be written back to income**, and
   after how long. The application does not do this and will not do it
   automatically; it would be a reviewed journal.
4. **Presentation on the balance sheet.** Customer advances currently sit as an
   ordinary liability and supplier advances as an ordinary asset. Confirm
   whether either should be presented under a specific caption (for example
   "Advances from customers" within current liabilities) for the jurisdictions
   in scope.
5. **Profit and loss effect of a foreign application.** The realised difference
   between the advance's rate and the invoice's rate is taken to realised FX
   gain or loss on application. Confirm that this is the treatment wanted, and
   which accounts it should use by default.
