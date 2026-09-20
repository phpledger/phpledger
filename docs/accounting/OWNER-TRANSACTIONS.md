# Owner and partner transactions — worked examples for review

**Status:** implemented on branch `m6/chart-codes-contra-owner` for PHP Ledger 1.2,
**pending accountant review** under owner decision B30. Owner decisions B61 (owner
capital, loans and drawings must be first class), A3 (real-time owner's equity;
AOP partners need capital accounts with profit-sharing ratios and drawings), B60
(contra accounts) and B54 (accounting standards prevail over the prototype)
govern what follows.

This document is written for the accountant, not for the developer. Every journal
below is what the application actually posts; the amounts are illustrative. The
currency is the book's functional currency and all figures are exact decimals.

## What the application does and does not do

* Every owner transaction is posted through the one central posting service that
  invoices, receipts and journals use. There is no second ledger, no separate
  owner ledger and no unposted balance.
* A posted owner transaction is immutable. A correction is a **linked reversal**
  on or after the original date, exactly as for any other posted journal.
* Nothing is netted, inferred or guessed. The Owner and partners screen and the
  balance sheet's equity section read posted journals only.

## 1. Capital introduced

The owner pays PKR 500,000 of personal money into the business bank account on
1 July 2026. It is not repayable.

| Account | Code | Debit | Credit |
|---|---|---:|---:|
| Cash and bank | `1-100-10001-00` | 500,000.00 | |
| Owner equity | `3-100-10001-00` | | 500,000.00 |

Capital introduced is equity, not income. It never appears on the profit and
loss account.

## 2. Owner loan to the business

On 15 August 2026 the owner lends the business PKR 200,000, to be repaid. The
business owes the money back, so it is a **liability**, not equity.

| Account | Code | Debit | Credit |
|---|---|---:|---:|
| Cash and bank | `1-100-10001-00` | 200,000.00 | |
| Owner's loan account | `2-110-10001-00` | | 200,000.00 |

The loan balance is presented in the liabilities section of the balance sheet.
It is repeated as a memorandum line in the equity section's owner's-equity
movements so the owner can see their whole position in one place; that line is a
disclosure, not a second posting, and it is not added into total equity.

**Open for review:** interest on an owner's loan is not modelled. If a partnership
deed or a loan agreement provides for interest, it is recorded today as an
ordinary expense journal against an interest account. Accrual, the tax treatment
of interest paid to a proprietor, and any deemed-interest rule are outside this
milestone.

## 3. Owner loan repayment

On 31 December 2026 the business repays PKR 50,000 of the loan.

| Account | Code | Debit | Credit |
|---|---|---:|---:|
| Owner's loan account | `2-110-10001-00` | 50,000.00 | |
| Cash and bank | `1-100-10001-00` | | 50,000.00 |

The remaining loan balance is PKR 150,000, still a liability. A repayment is not
an expense and does not touch the profit and loss account.

## 4. Drawings

On 20 November 2026 the owner withdraws PKR 40,000 for personal use.

| Account | Code | Debit | Credit |
|---|---|---:|---:|
| Owner drawings | `3-900-10001-00` | 40,000.00 | |
| Cash and bank | `1-100-10001-00` | | 40,000.00 |

Drawings are **not a business expense**. They are held in a separate
contra-equity account in the reserved contra group (`3-900`) rather than debited
straight to capital, so the year's withdrawals stay readable on their own line
and the capital account keeps showing what was actually introduced. On the
balance sheet, drawings are presented as a deduction inside the equity section:

```
Equity
  Capital introduced to date        500,000.00
  Less: drawings to date             40,000.00
  Earned profit to date             ...
  Total equity                      ...
```

**Open for review:** whether drawings should be closed to the capital account at
each year end, and if so by whom and at what date, is a period-closing policy.
The application does not close them automatically; nothing is posted without an
instruction.

## 5. Correcting an owner transaction

Suppose the drawings above were recorded against the wrong business. The
original journal is retained and a linked reversal is posted on or after its
date:

| Account | Code | Debit | Credit |
|---|---|---:|---:|
| Cash and bank | `1-100-10001-00` | 40,000.00 | |
| Owner drawings | `3-900-10001-00` | | 40,000.00 |

Both journals stay in the ledger, linked to each other and to the reason given.
The reversal is refused if it would fall in a closed period or before the
original date.

## 6. An AOP partner's share

A partnership (AOP) of two partners, A and B, sharing profits 60:40. Each
partner has their own capital account, and their own drawings account, in the
same groups:

| Partner | Capital account | Drawings account | Profit share |
|---|---|---|---:|
| Partner A | `3-100-10002-00` | `3-900-10002-00` | 0.600000 |
| Partner B | `3-100-10003-00` | `3-900-10003-00` | 0.400000 |

Partner A introduces PKR 600,000 and Partner B PKR 400,000:

| Account | Code | Debit | Credit |
|---|---|---:|---:|
| Cash and bank | `1-100-10001-00` | 1,000,000.00 | |
| Capital — Partner A | `3-100-10002-00` | | 600,000.00 |
| Capital — Partner B | `3-100-10003-00` | | 400,000.00 |

(The application posts one journal per partner; they are shown together here for
readability.)

Partner A then draws PKR 50,000:

| Account | Code | Debit | Credit |
|---|---|---:|---:|
| Drawings — Partner A | `3-900-10002-00` | 50,000.00 | |
| Cash and bank | `1-100-10001-00` | | 50,000.00 |

Partner A's position at that date is capital 600,000 less drawings 50,000, a net
550,000. The application shows this per partner without any allocation of
profit.

### What is implemented

* A partner record: name, capital account, optional drawings and loan accounts,
  a profit-sharing ratio and an active flag.
* The ratios of the active partners can never total more than 1. A total below 1
  is treated as incomplete, and the screen says so rather than inventing the
  remainder.
* Per-partner capital, drawings, loan balance and net capital, read from posted
  journals at any date.

### What is left for the accountant (owner decision B30)

**Profit allocation between partners is deliberately not posted.** The ratios are
recorded; nothing is allocated automatically. These questions need an accounting
decision before any allocation entry is written:

1. **Order of appropriation.** Are partners' salaries and interest on capital
   appropriated before the residual profit is shared in the ratio, or is the
   whole profit shared in the ratio? Both are common in Pakistan partnership
   deeds, and the answer changes every partner's balance.
2. **Interest on capital and on drawings.** At what rate, on what balance
   (opening, closing or weighted average), and is it charged even when the
   period makes a loss?
3. **Losses.** Are losses shared in the same ratio as profits, or in a separate
   loss-sharing ratio as some deeds provide?
4. **Timing.** Is the allocation posted at each year end only, or at each period
   close? Is it reversed and re-posted if the period is reopened?
5. **Admission, retirement and a change of ratio.** What happens to balances
   accrued under the previous ratio, and is goodwill recognised?
6. **Current accounts.** Should a partner have a fixed capital account plus a
   fluctuating current account (the more usual presentation for an AOP), rather
   than one account per partner as implemented here?

Until these are answered, an allocation is posted the same way any other
reviewed entry is: as a general journal, by a person who has decided the
treatment, with a reason recorded.

## 7. The conversion of existing account numbers

The accounts above use the structured codes introduced with this work
(`X-XXX-XXXXX-XX`: class, group, account, sub-account — owner decision B56,
issue #76). An existing chart is converted by its **current groups**, so accounts
that shared a group before still share one. Each account's old number is kept on
the account itself and in an immutable mapping table, and no account's identity,
classification or posted entries change. The conversion needs the accountant's
review before 1.2 ships; the mapping table is what that review reads.

## 8. Contra accounts in the reports

Contra accounts are presented as deductions **inside their own section**, never as
members of the opposite one:

| Contra account | Sits inside | Presented as |
|---|---|---|
| Accumulated depreciation (`1-900`) | Assets | deduction from assets |
| Provisions against assets (`1-910`) | Assets | deduction from assets |
| Drawings (`3-900`) | Equity | deduction from equity |
| Sales returns and discounts allowed (`4-900`, `4-910`) | Income | deduction from income |
| Purchase returns and discounts received (`5-900`, `5-910`) | Expenses | deduction from expenses |

A provision that is an obligation of the business (for example a provision for a
known liability) is an ordinary liability, not a contra account; the application
refuses to mark a liability as contra. The trial balance is unaffected: a contra
account keeps its natural debit or credit balance, so debits and credits still
agree.
