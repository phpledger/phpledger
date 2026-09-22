# Fiscal-year closing

The `/year-end` screen registers a fiscal year, records its reviewed accounting policy, previews the closing journal, posts final-day adjustments, closes or locks the year, and reopens it with a linked closing reversal. Migration **054** supplies fiscal years, immutable action receipts and exact-payload posting intents. It does not change posted journal headers or lines.

## Accounting policy and review

The selected legal form does not automatically select an accounting treatment. The reviewer explicitly records the legal/agreement basis, treatment and destinations for each year:

- Company earnings close to the selected retained-earnings equity account. This is not a dividend or distribution.
- Sole-trader earnings and drawings close to selected owner capital.
- Fixed-capital partnership earnings and drawings close to each partner's selected current account; capital stays unchanged.
- Fluctuating-capital partnership earnings and drawings close to the registered capital accounts.

Partner names, account IDs and six-decimal ratios are snapshotted. Ratios must total exactly one. Statutory ownership membership ratios and dates do not allocate profit. Reviewers must select the agreed full-year allocation; the application does not invent midyear weighting, salary/interest allowances, tax or distributions. Record any agreed adjustments separately before closing. Foreign-currency destinations, account headings, inactive accounts, cross-company accounts and unassigned drawings balances are refused.

Profit/loss is split in exact four-decimal units. Fractions use the largest-remainder method, with ascending partner ID breaking equal remainders. Losses mirror positive allocations. A zero-net-profit year still zeros every nonzero income/expense account. Only a completely empty closing entry creates a no-journal receipt.

## Workflow and correction

1. Create the year using the company fiscal calendar. The company start date truncates the first year. A conflicting company-profile year end must be resolved first.
2. Periods must cover the year exactly, with no gaps or straddling boundaries. Earlier periods must be closed. Starting closing explicitly reopens a closed final period with an audit record.
3. If operational work remains, use the audited **Return to preparation** action. It returns a closing year to open without changing its posted adjustments, so depreciation and schedule services can finish. Closed and locked years still require the reversal-based reopen workflow. During closing, ordinary journal postings are refused. Use the final-day adjustment form. Correct an adjustment with its linked reversal action, then post a replacement.
4. Review the generated closing lines and allocation. Computed period blockers cannot be overridden; outstanding depreciation and due schedule releases block closing. Stock valuation, provisions, adjustment completion and payroll are explicit attestations, with a review note explaining any not-applicable item. Outstanding legitimate payroll or loan liabilities are not required to be zero.
5. Submit the reviewed preview. The service checks its fingerprint, current journal set, balances, policy and checklist under the book lock; stale previews are refused. The closing journal, final-period closure and immutable receipt commit together.
6. Lock a closed year if desired. Both closed and locked years prevent ordinary postings and direct period reopening.
7. Reopen the latest closed year first. Reopening requires year administration, period reopening and backdated reversal authority. It opens the final period and reverses the original closing journal on the original year-end date, preserving the original receipt and policy. Corrections require a fresh preview and new close cycle. Generic reversal cannot bypass this workflow.

All actions require company/book membership and server-side authority. `year_end.manage` is owner-only by default; financial reads additionally require `cost.view`. Browser writes use CSRF and explicit scope checks. Immutable request receipts allow identical retries while refusing changed inputs. Posting intents authorize only the exact reviewed normalized payload within the same transaction; they cannot grant arbitrary use of reserved journal sources.

## Reports and opening continuity

Performance P&L excludes closing entries and their linked reversals. The full trial balance and balance sheet include both, so earnings appear once as unclosed earnings or recorded equity. Pre/post-adjustment trial balances exclude this year's closing cycles; the pre-adjustment version additionally excludes this year's dedicated adjustments and linked reversals. Prior-year entries remain part of opening balances. Fiscal-year comparatives use registered prior-year boundaries. Reports reflect all postings currently recorded, rather than an assertion that a printed copy is a frozen historical report.

Next-year balances continue from the ledger. **No opening journal is generated.** Read API/MCP operations `fiscal_years` and `year_end` expose scoped states, policy, report values and audit history through a full read grant. They do not expose request keys or posting-intent hashes. These operations remain subject to the creating user's current access.

## Worked internal accounting review

### Private company

Cash service income 1,000: debit cash 1,000, credit income 1,000. Final-day expense 400: debit expense 400, credit cash 400. Profit and net assets are 600.

Closing: debit income 1,000, credit expense 400, credit retained earnings 600. Income and expense ledger balances become zero. Cash/net assets remain 600; retained earnings is a credit of 600. P&L remains income 1,000 less expenses 400 = 600. There is no payment or dividend.

Reopening reverses those exact three lines. A further expense of 100 reduces profit to 500. Reclosing produces retained earnings 500, with both prior close and reversal still visible. The automated example verifies immutable receipts, repeat submissions and balance-sheet continuity.

### Two fixed-capital partners, 60/40

Profit 1,000 allocates 600 to A's current account and 400 to B's current account. A has drawings 100; B has drawings 50. Closing credits current accounts by net 500 and 350, respectively, and credits drawings 100 and 50 to zero them. Both capital accounts remain unchanged. This is appropriation within equity, not an additional expense and not a new cash distribution. P&L remains 1,000. For a loss, the current-account appropriation reverses direction; fixed capital still remains unchanged.

The arithmetic, double-entry effects, independent balance-sheet/P&L behavior, immutability and correction cycle are internal review evidence. Under owner decision B81, independent real-book pilot/accountant acceptance is a 2.0 gate, not a claim made by these automated examples.

## Validation

The focused suite is `php tests/run.php --suite=year-end`. It covers the worked company and partnership, exact residuals, stale preview, reserved-source refusal, immutable audit, same-key concurrency, old-snapshot retry, atomic rollback, correction/locking/next-year continuity and actual HTTP forms with CSRF/scope rejection. Final lane receipts record the exact passing counts and limitations. Combined MySQL/MariaDB, package upgrade/fresh-install and responsive browser checks are owned by the release coordinator.
