# Pilot findings register and October procedure — pilot #1

Opened 20 September 2026 under decision B23 (the owner's own company is the first supervised pilot with a real month-end close) and gate G13 of the [1.2 release plan](../strategy/RELEASE-PLAN-1.2.md). Rows are added by the pilot, the accountant, the Urdu reviewer, the security reviewer and preview testers; engineering owns the file.

- **Pilot:** the owner's own company, first supervised pilot (B23).
- **Reviewed version:** 1.1.2 (once published; until then the pilot is not live). If a 1.1.3 fixes-only patch is applied to the pilot, this line is updated to name it and, if the fix touches posting, the affected acceptance period restarts with the accountant informed.
- **Start:** 1 October 2026. **First month-end close:** 31 October 2026. The pilot stays on 1.1.x through the October, November and December closes and cuts over to 1.2.0 as a dated, backed-up event after the accountant's dated decision (decision 2).
- **Artifacts:** testers use published artifacts only, the public demo and published releases (B25). Nobody on the pilot runs a source build, a checkout or a preview.

## Findings register

| # | Date | Reporter | Version and environment | Scenario | Expected | Actual | Severity | Evidence | Owner | Resolution | Retest |
|---|---|---|---|---|---|---|---|---|---|---|---|
| 0 | 2026-09-20 | engineering | 1.1.1 published | Release record | Notes say no migration | Package ships migration 034 and the Stock locations module unannounced; no signed update metadata | material | docs/repository/PUBLICATION-2026-09-20-1.1.1.md errata (receipt backfilled in M0) | engineering | corrected in 1.1.2 | after 1.1.2 publishes |

Reporter is one of: pilot, accountant, Urdu reviewer, security reviewer, preview tester, engineering. Severity is one of: blocking, material, minor, question. Rows are appended, never rewritten; a correction to an earlier row is a new row that names it.

## October procedure for the pilot on 1.1.x

Written for the bookkeeper. It says what 1.1.x cannot do yet and what to do instead. Each item names the screen and the behaviour as read from the 1.1.x code; where a point could not be checked on a running copy it says "to be confirmed".

### (a) Customer advances and unapplied credit

What 1.1.x does: a receipt (Record receipt on the Receivables screen) settles only specific open invoices. The receipt is allocated across one to thirty open items of one customer, the allocations must equal the payment exactly, and the receipt cannot be dated before the latest activity on any invoice it settles. The account the money lands in must be an active account whose purpose is cash/bank. There is no place to hold money that has no invoice yet or that exceeds the invoices; that is 1.2 item (c).

Set up once, before 1 October, in Chart of accounts:

1. A liability account named **Customer advances** (classification liability, no purpose). Code of your choice, for example `2150`.
2. An asset account named **Advances clearing** with purpose **cash/bank** (classification asset). Code of your choice, for example `1090`. It must be a cash/bank account because a receipt can only be funded from one. It will appear in the Bank reconciliation account list; there is never a statement to import for it, and its balance should be zero after every step below.

To be confirmed on the pilot installation before go-live: that the general-journal account picker offers both new accounts (posting rules only restrict the receivables control account, so this is expected to work).

When money arrives before an invoice exists, or in excess of the invoices:

1. Post a general journal (Journals › New general journal): debit the real bank or cash account, credit **Customer advances**, with the customer's name and the payment reference in the description. Do not touch the receivables control account in a general journal; 1.1.x refuses it.
2. Add a line to the advances list kept beside the books: date, customer, amount, general journal id. The 1.2 conversion tool turns these into advance open items after the upgrade (release plan M4), and it needs this list.

When the invoice is issued and posted:

1. On the invoice record, Record receipt, dated on or after the invoice date, funded from **Advances clearing**, allocating exactly the amount being applied (the whole invoice, or the advance if smaller). This closes the customer's open item.
2. The same day, post a general journal: debit **Customer advances**, credit **Advances clearing**, for the same amount, naming the customer and the invoice number. Advances clearing returns to zero.
3. Note the invoice number and the two journal ids on the advances list. Any part of the advance not yet applied stays in Customer advances.

At month end, the balance of Customer advances must equal the sum of the unapplied lines on the advances list, and Advances clearing must be zero. Record both checks in the reconciliation evidence for the close.

### (b) Printing and statements

1.1.x has one printable document: the invoice record with its Print button, which uses the browser's own print command (the page says so). Print to PDF from the browser to keep a copy. Invoice numbers are `INV-` followed by the six-digit record id; there is no configurable number series and no separate receipt or credit-note numbering beyond the same pattern (`CR-`).

There is no customer statement template. The Account statement report (Reports › Account statement) is per ledger account, not per customer: it shows the receivables control account with every entry and a running balance. For one customer, use the Ageing report (Reports › Ageing), which lists each open document by customer with its due date, age and balance, and the invoice records themselves. Print those to PDF if a customer asks for a statement. Statement, receipt and A4/80 mm templates arrive with 1.2 item (b).

### (c) Stock locations

The optional Stock locations module is inside the 1.1.1 and 1.1.2 packages but is undocumented until 1.2 and off by default. The pilot leaves it disabled unless the owner decides otherwise and records that decision in the register. Do not enable it "to have a look" on the live installation: enabling and later disabling modules with data present is part of the 1.2 compatibility matrix that has not been published yet.

### (d) When something is wrong

- Add a row to the register above the same day, with the screen, what you expected, what happened and a screenshot or export as evidence. A question is a valid row.
- Never edit a posted entry. A correction is a dated reversal (Reverse on the journal or document record; it cannot be dated before the original, and a reversal cannot itself be reversed) followed by a fresh, correct posting. Receipt journals are reversed from the invoice record; credits are reversed through their own document.
- Keep a private database and file backup before any upgrade, taken the way `UPGRADE.md` describes, and keep the previous package. A 1.1.3 patch is applied only after the register records the decision and the backup.
- Never install a preview on the pilot's live installation. Previews carry migrations that cannot be reverted; they are for disposable copies only (B25, release plan "Shape"). The pilot stays on the stable channel.

## Accountant engagement (decision 4)

The owner engages the accountant early for a short policy consultation before the October close, in addition to the review commissioned on the close itself (B23). The four questions are decisions 7 and 8 of the release plan, and the answers are recorded in the [decision register](../strategy/DECISION-REGISTER.md) before the affected migration is written (gate G12).

1. **Discount posting** (decision 7): net to income, or gross plus a discounts-allowed account? Planner's recommendation: net to income.
2. **Free goods** (decision 7): carrying value to cost of sales or to a promotional expense account; output tax at open-market value or none? Recommendation: a promotional expense account with no output tax by default and a documented policy flag.
3. **Number series** (decision 7): format, padding, continuity and which document types? Recommendation: continuous series with a prefix per type and six-digit padding for invoice, credit note, receipt and transfer.
4. **Customer advances** (decision 8): representation of unapplied credit as a customer-advances liability control with advance open items, or as a credit balance inside receivables; the oldest-first ordering key (due date then id, or document date); behaviour above the 30-item cap; batch shape (one journal with one bank line, or one journal per customer); and how October advances are recorded on 1.1.2 and converted. Recommendation: the liability control with advance open items; due date then id with manual override always available; raise the cap to 100 with the planner splitting beyond it; one journal with one bank line; October advances by general journal to a manual liability account, converted by the M4 tool (the procedure in (a) above).

Worked examples under `docs/accounting` (journals, open-item entries, correction under B7) follow in the 1.2 M2 milestone and are sent to the accountant the same week. Decision 7 is needed by 5 October and decision 8 by 23 October; the October procedure above stands regardless of the answers.

The second session, in early December (1–11 December per M6), reviews the new behaviour on a disposable upgraded copy of the pilot's October books: exported after the 31 October close, hosted privately, upgraded to 1.2.0-preview.2 with the advances conversion run, and walked through the scenario script (receipt with remainder, application, batch receipt, discounted invoice, free-goods line, numbered invoice, cash on invoice, statement). That copy never enters the repository. The accountant's dated statement from each session goes in `VALIDATION.md` and in this register (gate G15).
