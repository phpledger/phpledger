# A workspace for the business and its books

PHP Ledger aims to make the first useful accounting task straightforward while preserving the detail accountants need. An owner should understand what changed; an accountant should be able to inspect the source, accounts and journal behind that change.

## Who it is for

| Person | What the experience should make easier |
|---|---|
| Business owner | Record daily activity, understand cash and expenses, and see a clear next action. |
| Accountant or bookkeeper | Enter and review transactions efficiently, investigate balances, reconcile records and close periods. |
| Shop operator | Build a sale quickly, confirm it deliberately and find its receipt and accounting effect. |

## The first useful journey

1. Try the shared installer demo, then create a business or an isolated practice company on your own installation.
2. Enter the business name, base currency, accounting start date and fiscal year.
3. Review the accounts and open a statement to inspect its balances.
4. Save a receipt, expense or general journal as a draft and correct any errors.
5. Post it and inspect its balanced journal.
6. Find the report effect and return to the source transaction.

The current application implements that core journey, including opening conversion and bank reconciliation. A business must not be treated as ready simply because its name and currency have been entered.

## Experience standards

Clear draft/posted states, recoverable errors, keyboard-friendly entry, sensible defaults and source links matter more than a dashboard full of decorative numbers. The design should work on practical mobile workflows while retaining efficient desktop tables. Financial figures use Inter with aligned numerals.

The initial goals are simple-company setup within five minutes and a first useful transaction/report outcome within ten minutes, after login. These are usability targets to measure with representative users, not results already achieved. Server installation and historical migration are measured separately.

## Payroll, people and the close

Aggregate payroll accounting records reviewed accruals, staff advances, deductions and partial settlements without exposing per-person pay through the ledger or read API. The employee register is confidential; sales-staff and driver links are explicit and preserve historical names. Employment and ordinary trade association do not imply a related-party designation.

Recurring, accrual and loan schedules produce ordinary reviewable drafts. Loan screens disclose their selected conventions. Period close includes cash counts and a checklist; fiscal-year close requires explicit legal treatment, destination accounts and any reviewed partner ratios. Reopen and reversals remain traceable. None of this supplies statutory payroll, payslips or lender-contract certification.

Optional CC0 samples are separate validated data-only packages. Arabic is a draft RTL translation. Table prefixes and explicit database TLS support installations on compatible hosting; provider acceptance remains a separate check.

## What comes next

The product follows a [[core-first module roadmap|Module-Roadmap]]. Established invoices, stock, reconciliation and reports now sit alongside the reviewed payroll, scheduling and close workflows above. See [published releases](https://github.com/phpledger/phpledger/releases) for the exact current scope; [[First package|First-Package]] preserves the 1.0.0 history. Industry research covers restaurants, clubs, pharmacies, traders, distributors, shops and workshops; sample scenarios and disabled [[tax candidates|Tax-Research]] are not installed industry modules.

[[Getting started|Getting-Started]] · [[Accounting and reports|Accounting-and-Reports]] · [[POS showcase|POS-Showcase]]
