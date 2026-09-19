## Current package: 1.1.0

**1.1.0**, published 19 September 2026, is the current stable release; **1.0.0**, published 18 September 2026, was the first. [Download 1.1.0](https://github.com/phpledger/phpledger/releases/tag/v1.1.0) and its [matching media kit](https://github.com/phpledger/phpledger/releases/download/v1.1.0/phpledger-1.1.0-media-kit.zip); [[Release notes|Release-1.1.0]]. Automated test suites, fault-injection update/recovery tests, exact-artifact install/upgrade/recovery checks and developer-operated browser checks back these releases. Independent accounting review, independent security review, supervised pilots with a real month-end close, unfamiliar-operator installation observation and restricted shared-host recovery certification have **not** happened; these continue as post-release commitments. See [[Release 1.0.0|Release-1.0.0]].

# From a transaction to an explainable balance

Every posting uses one service that checks access, company/book scope, dates, currency, account ownership and balanced debit/credit totals. Financial decisions use exact decimals.

Open **Reports → Open account ledger** to choose any account, or follow the ledger links from Transactions and Journals. Mobile statements show debit, credit and running balance together for each movement.

## What 1.0.0 does

| Workflow | Present scope |
|---|---|
| Account statements | Any authorized account: opening balance, period debits/credits, running balances and closing balance, with journal/source links and pagination. |
| Chart management | Authorized owners/accountants create accounts and edit names or active status with an audit trail and stale-edit protection. Code, type and purpose remain fixed. |
| General journals | Save and reopen drafts, edit lines, review totals and explicitly post. Drafts may be unbalanced; posting must balance. |
| Receipts and expenses | Save recoverable drafts and explicitly post them through the same accounting services. |
| Corrections | Preserve the posted entry and create a linked reversal with a reason. |
| AR and AP (required core) | Invoices, bills, partial/final allocated payments, linked credits, historical ageing and control-account reconciliation, with manually configured tax. Owners can hide the navigation without disabling the underlying services or excluding balances from reports. |
| Purchasing and Inventory (optional, bundled) | Purchase orders, partial goods receipts, later matched AP bills, reviewed variances and returns; shared products, one stock location, moving weighted-average cost, stock invoice issues, counts and reviewed value adjustments. |
| Opening conversion | Reviewed opening party/product conversion ties existing balances to the shared ledgers without reposting the opening journal. |
| Bank reconciliation | CSV-based bank statement matching against posted transactions. |
| Trial balance | Read posted balances within the selected business/book and investigate the underlying accounts. |
| Profit and loss / balance sheet | Posted income/expense and position summaries, with cost-of-sales classification, gross profit and period presets. |
| Cash scenario | Project entered weekly cash in/out from posted opening cash; no forecast entries are posted. |

A statement's **opening balance** is the balance before its selected date range. This calculation alone does not establish a reconciled cutover; use the opening conversion workflow, including unpaid-document reconciliation where needed.

Posting checks the current saved draft; a changed draft must be reviewed again. Identical retries do not create duplicate journals, and closed periods reject new posting. Linked reversals preserve both sides of the correction. Technical checks support these controls but do not replace qualified accounting review.

The public demo keeps accounts read-only. Visitors can save/edit general drafts, post balanced entries and use linked reversals within their own temporary books and capacity limits.

## A complete statement needs more than a layout

Applicable accounting guidance governs recognition, measurement, classification, adjustments, closing and disclosures as well as presentation. Each regional profile uses its relevant statutory framework and professional guidance. Pakistan is one intended direction within a country-neutral core; it does not define the entire audience or a mandatory global rollout sequence. ICAP, ICMAP and ACCA references inform the research; they are not a product certification.

Core reports do not yet implement a complete country framework, statutory disclosures or comparatives. Tax regimes are separately researched and remain disabled; see [[Tax research|Tax-Research]]. No accounting sign-off or country certification is claimed for 1.0.0.

## What remains open

Independent accounting review has not happened. Advanced stock (multiple locations, batches, serials, expiry, landed cost), automated rate providers, period-end FX revaluation and group consolidation are not part of 1.1.0. Multiple stock locations and transfers are the first 1.2 module; batches, serials, expiry and landed cost come later. Country tax rules, withholding and statutory filing remain future work; see [[Module roadmap|Module-Roadmap]] for the sequence and acceptance gates.

[[Countries and currencies|Countries-and-Currencies]] · [[Architecture]] · [[First package|First-Package]]
