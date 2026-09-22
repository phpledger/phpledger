# Payroll accounting and employee links (1.3)

This release records reviewed aggregate payroll totals. It does not calculate individual wages, payslips, tax rates or statutory filings. Keep the approved external payroll calculation as supporting evidence and enter its reference. Actual-book pilots and independent accountant sign-off remain the 2.0 gate (B81); the examples below are internal sample evidence.

## Accrual and payment

Open **Payroll accounting** (`/payroll`), set up missing payroll accounts if needed, choose the pay period and posting date, enter the external reference and balanced elements, preview and confirm. Account selection is explicit and company/book scoped. Amounts use four decimal places. Gross pay and employer contribution debit expenses; net salaries, withholding, social security and pension credit liabilities. Staff advance recovery credits the separately recorded staff-advance asset and cannot exceed its posted balance at that date.

For a fictional January period, enter gross wages 10,000 and employer contribution 1,000; credit net salaries 8,000, withholding 1,500 and social security 1,500. January expense is 11,000 and the unpaid liabilities total 11,000. In February prepare net-salary payment 8,000 and withholding payment 1,500. Each opens an ordinary general-journal draft for review and posting: debit the specified liability and credit the selected functional-currency bank. February creates no second payroll expense; social security remains payable at 1,500. Reports viewed as of January still show its full liabilities.

Partial payments are allowed. Competing drafts are checked again under the central posting lock; a second draft cannot overpay an element. Editing an allocated payment's financial lines, date or description is rejected when posted. Reverse the payment and prepare a new allocation instead of using generic correction. Reverse all active payments before reversing their payroll accrual. Ordinary closed-period, balance, membership and immutable journal controls apply. No payment instruction is transmitted to a bank.

A 500 staff advance is first an ordinary reviewed payment: debit Staff advances and credit Bank. In a later aggregate payroll, reduce net salaries by 500 and credit Staff advances by 500. The asset is separate from trade receivables; no customer/vendor open item is manufactured. Detailed employee balances and allocation of that asset to particular people remain outside this aggregate service.

`payroll.view` reads totals; `payroll.manage` plus the read grant writes. Owners receive the grants by default. The `payroll_journals` read operation uses the existing read-operation mechanism with required `from` and `to` pay-period filters and optional `element`; it contains aggregate source amounts, never employee pay records. The checklist includes a manual payroll reconciliation item; legitimate unpaid liabilities carry forward and unposted payment drafts remain subject to the general draft check.

## Explicit employee consolidation

`/employees/links` maps existing sales staff and mobile-location drivers to an existing employee. Migration 055 leaves old records visibly unmapped and never invents identities or hire dates. New sales/driver assignments require an explicitly chosen, currently employed person. The legacy label remains in storage. Operational reads use the employee's current name, while posted invoice and stock-document labels are captured immutably. For pre-upgrade documents the migration captures the label available at upgrade; it cannot reconstruct a name already changed before upgrade.

An ordinary employee-to-trade-party association is a separate, explicit one-to-one same-company link to an individual. It neither designates related parties nor nets payroll/trade balances. Read-only comparisons require both employee and related-party read grants, compare only collected names, national identifiers and address text, and require human confirmation. Employee bank details are not collected, so bank matching is unavailable. A match never writes a link or related-party marker. Unrelated roles can keep using operational names without reading employee salary or identity details.

Migration 053 adds payroll source headers, elements and payment allocations; migration 055 adds explicit personnel links and immutable operational-label snapshots. Both are versioned core migrations. HTTP forms use existing CSRF and company/book scope checks. The shared payment helper creates standard general-journal drafts and introduces no second ledger or posting system.
