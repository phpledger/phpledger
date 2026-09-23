# Experience and design direction

## 1.0.0 status

The published 1.0.0 interface implements the owner-approved Claude-calm direction: warm light canvas, navy #0C2052, blue #4656E8, local Inter and token-based components. It supersedes Review Console and the pending P0.4 shell alternatives. Desktop/tablet are primary; dark mode and mobile refinement are deferred. Full visual/accessibility acceptance remains open; see design/redesign-0.5/AUDIT-CLOSURE.md.

## Latest brand revision

The user supplied a book/P logo on 2026-09-14 and asked to see all six existing layouts using its navy and blue-violet identity. The original concepts below remain preserved. The new comparison and complete prompts are recorded in [the branded concept set](design/brand/README.md); [brand guidance](BRAND.md) records both supplied sources, the designer's exact main colors, font-review status, contrast calculations, and the proposed business-logo/custom-accent policy.

The branded images show the default PHP Ledger identity. A future business-branded mode is proposed to display the business logo in the header, retain a subtle PHP Ledger mark in the footer, and allow controlled accent changes while preserving layout and status meanings. It is not an implemented setting or a final product-owner policy decision.

The design objective is fast comprehension and confident daily use. The user initially rejected directions 1–3 because they looked AI-generated, then requested 4–6 informed by QuickBooks, Odoo, and Salesforce and subsequently all six in the PHP Ledger brand. Read [Design research](DESIGN_RESEARCH.md) before refining or implementing the selected direction.

## Previous direction: 6, Review Console

On **2026-09-14**, the user explicitly asked for an independent review and delegated the choice to the lead. Both reviews select **6, Review Console**, as the main application direction. Its persistent transaction list and adjacent record detail provide the clearest structure for repeated accounting work, with deliberate company/book context and a restrained place for the book/P identity. Direction 5 is the runner-up, especially for full-document editing. The [independent review](design/brand/REVIEW.md) records pros and cons for all six; this is visual judgment, not evidence of task-completion speed.

The user subsequently confirmed, **"I will go with your choice."** This explicitly approved direction **6, Review Console, with Inter for application typography** for the earlier 0.4-era working journey. On 2026-09-16, the product owner asked to return to the drawing board because the application has outgrown the top navigation and to explore a materially different shell informed by current market patterns. That request supersedes the 0.4-era implementation selection for the 0.5 redesign. Retain the book/P logo concept; final wordmark and production logo assets remain pending. Neither selection authorizes publication/deployment.

## 0.5 redesign reset

The current 0.5 workstream is evaluating three new candidate systems documented in the local release board: **Ledger Desk** (persistent work rail and Today dashboard), **Today First** (command search, daily composer and activity stream), and **Books & Workspaces** (workspace rail with focused setup and accounting areas). These are candidate directions, not approved screens. A product-owner selection is required before replacing the remaining grouped-navigation prototype across every active route.

Required refinements before the working journey is accepted:

1. Compress the header/navigation strips and keep company, book, and sample identity clear.
2. Separate current-row inspection, bulk checkboxes, and editing. Show Save draft only when editing; preserve unsaved values and handle record changes deliberately.
3. Remove repeated status and journal content. Give owners a focused first-expense path with optional journal detail within the same design system.
4. Specify desktop list/detail and mobile list-to-detail behavior, keyboard/focus paths, retained filters, validation recovery, and readable financial columns.
5. Extend the same system to onboarding, historical-import preview/validation/confirmation, and a report linked back to the source. Resolve opening-balance readiness explicitly.

The selection authorizes this direction for implementation and refinement; it does not establish a finished interface, approved final logo assets, measured usability, WCAG conformance, or a production release. Six static brand comparisons and review documentation are complete. [Sprint 02](SPRINT-02.md) now implements the working login, onboarding, receipt/expense draft, posting, trial-balance, and reversal journey. Historical-import execution is deferred from this sprint while its future design requirements remain documented.

The website is a separate public experience using the same identity and typography. Three visual compositions were prepared before code; the user selected **1, Field Notes**. Continue with meaningful international business photography, a genuine product walkthrough, and clear installation/support/contribution paths. The subsequently authorized `/demo` must make its sample, hourly-reset nature clear and keep unsupported/destructive actions out of its usable flow. Website screenshots and photographs must not imply completed product capabilities or customer endorsements.

## Rejected first set, preserved for reference

These concepts were generated with the built-in ImageGen tool on 2026-09-14. They are images, not implemented application screens. Preserve their numbering and files; do not treat them as approved implementation references.

| Direction | Visual and interaction approach | Intended strength |
|---|---|---|
| [1. Guided Start](design/01-guided-start.png) | Guided checklist, clear next action, friendly empty states, and contextual accounting explanations | Users moving from initial setup into a repeatable bookkeeping routine |
| [2. Ledger Desk](design/02-ledger-desk.png) | Compact tables, strong alignment, keyboard-first entry, and contextual detail | Accountants scanning, reconciling, and entering many records efficiently |
| [3. Calm Overview](design/03-calm-overview.png) | Generous spacing, restrained accents, readable balances, and a focused daily overview | Owners understanding their business and completing daily bookkeeping without overload |

## Second exploration: 4, 5, and 6

Generated on 2026-09-14 after inspection of the official product visuals documented in [Design research](DESIGN_RESEARCH.md). These are images, not working browser screens. Direction 6 has since been selected as recorded above. The [complete prompts](design/PROMPTS.md) preserve the brief and reference order for each independent generation.

| Direction | Working structure | Main question for review |
|---|---|---|
| [4. Transaction workbench](design/04-transaction-workbench.png) | Compact navigation and transaction table with one expense expanded inline; journal effect beside fields | Can an owner review and post a simple expense while staying in the list? |
| [5. Accounting records](design/05-accounting-records.png) | Small document list, full expense form, line items, total, status, and posting preview | Does one predictable document structure make entry and correction clear? |
| [6. Review console](design/06-review-console.png) | Persistent table beside selected-record details, edit action, and posting preview | Can a bookkeeper review records quickly without losing their place? |

All three show the same core task: Cedar Trading sample company, draft expense EXP-0018 for Harbor Office Supply, USD 125.00, with equal debit and credit in a journal preview. The four pending expense amounts sum to USD 465.00. No posting occurs in an image.

### Image review notes to resolve in implementation

- Direction 4 needs an explicit editable amount field and a corrected connectivity category for the Beacon Internet sample row. Secondary sample dates differ from directions 5/6; normalize the canonical fixture before interaction testing.
- Direction 5 repeats the amount at document and line level. The implementation must define one authoritative entry amount and derive totals; it must not allow conflicting editable totals. Avoid duplicating journal content across both a tab and an always-visible panel unless user testing supports it.
- Direction 6 is review mode; Save draft is redundant until edits exist. The implementation should show actions appropriate to the actual saved/dirty state. Its open-source tagline expresses product intent; public release still depends on the documented license decision.
- Generated attachment sizes and other incidental text are placeholders. Use real supported file metadata in the working prototype. All directions need keyboard, responsive, validation-recovery, and observed usability checks after implementation; the images prove none of those properties.

Use real product patterns from the documented research while keeping original PHP Ledger branding. Allocate most space to the task, show believable dense records with useful columns/actions, and use restrained typography, controls, borders, and spacing. Keep setup help compact and dismissible once complete. Avoid decorative hero greetings, repeated KPI cards, charts without a decision purpose, floating panels, and unsupported AI features.

All candidates must demonstrate the same content and journeys so the decision reflects task performance. Select one coherent system; do not assemble unrelated favorite sections into an inconsistent interface.

## Shared experience requirements

Sprint 02 applies the selected Review Console system to working onboarding, transaction review, reports, and a touch-friendly sample POS. The reports hub distinguishes posted figures from the user's explicit cash-scenario assumptions. POS provides searchable categories, quantity/removal before checkout, cash/change review, clear saved status, and receipt/source links. Keep receivables/payables/stock and the later Scan document flow in the roadmap until their underlying behavior exists. The current visual refinement raises dense text to a readable size and strengthens navigation, hierarchy, and action contrast; final browser evidence remains separate from this design intent.

- Separate installation from business onboarding. Let users explore sample data without exposing or mixing real company records.
- Begin with essential company information, an account template, and a start-fresh/import choice. Progressive disclosure reveals advanced accounting settings when relevant.
- Explain the next action in empty states and show exactly whether work is draft, saved, posted, reversed, or awaiting confirmation.
- Keep entered values on validation errors. Place plain-language errors at the relevant field and supply an accessible summary/focus path.
- Make receipts/expenses natural daily tasks and journal/report exploration efficient for accountants. Avoid making users learn internal architecture.
- Use consistent navigation, typography, spacing, buttons, forms, tables, number alignment, dates, and feedback. Never communicate status through color alone.
- Support keyboard entry, visible focus, sensible tab order, associated labels, and readable contrast. Respect reduced motion and touch targets.
- Make tables useful at narrow widths with intentional scrolling or responsive layouts; prevent whole-page overflow and retain context for amounts/actions.

## Prototype content and review

Use sample company names and transactions. Display a clear preview/sample notice and avoid implying that a candidate screen saves business data unless that behavior is implemented. The current image comparison covers the daily expense task and its journal preview. After selection, the working prototype must also include onboarding and a report with traceable transaction detail. Import examples must show mapping, validation errors, totals, and the confirmation boundary.

Review desktop, tablet, and mobile widths, keyboard navigation, form errors, empty/loading/success states, and the path back from a report to its source. Automated checks support this review; screenshots alone do not establish usable interaction or WCAG conformance.

## Acceptance targets

For a straightforward new company, target setup in five minutes after first login and a first transaction plus report lookup in ten minutes. Target unassisted completion by four of five participants in each initial owner/accountant group. Record server installation and complex data migration separately.

Target WCAG 2.2 AA and document outstanding gaps. The selection date, candidate, visual evidence, and required refinements are recorded above. Observed participant sessions, working responsive/keyboard/error-state checks, and any accepted exceptions remain pending. No release exceptions have been accepted through this static review.

## Local cash, party and contextual-help revision — 23 September 2026

Receipts and Expenses now have separate kind-filtered workspaces while `/transactions` retains the combined view. The editor selects an existing company party or explicitly creates one without losing the transaction inputs. New interactive transactions require a party; historical text-only drafts retain an explicit compatibility path. The picker uses a native select, server search and creation, and optional JavaScript filtering.

One page-help control covers all 68 application views, including authentication and installer screens. `resources/guidance/pages.php` and `routes.php` are the explicit coverage catalogues. Report heading help is independent of expansion, closes with Escape/outside click, uses a viewport-contained sheet on phones, and is omitted from print. Unstructured legacy accounts have their own labeled report group. New accounting help remains marked as draft wording pending accounting review.

This is local implementation in progress, not a published release. The owner approved explicit physical-cash/bank classification and a separate bank overdraft limit in the account currency. Banks default to a zero floor; a configured facility supplies the permitted negative floor. The interface must distinguish this recorded-balance control from bank clearance and available funds. Combined accounting validation is recorded in the local implementation receipt.


## 1.4.0 administrator choice for cash and bank controls

Admin > Accounting policies now offers **Warning only** and **Strict** for each book through the existing `policy.manage` permission. Warning only is the default on fresh installs and upgrades, preserving the ability to record transactions even with a shortfall or an unclassified money account. The receipt/expense preview still explains recorded balances and shortfalls. Strict requires explicit physical-cash/bank classification before money-out postings and enforces the physical-cash zero floor and any explicitly agreed bank overdraft limit. No separate loan or unused credit line is treated as funding.

Changes require a reason, revision and idempotency key and are audited through the existing accounting-policy service. Policy updates and central posting share the book lock; posting reads the effective choice afresh, including after an earlier caller snapshot. Reversals and atomic corrections follow the same selected mode. Neither mode edits historical journals or guarantees bank clearance. Migration 060 adds the warning-default policy field to the existing policy table; feature migrations are 057 document parties, 058 money-account classification and 059 bank facilities, after the published 1.3.0 chain through 056. This release supersedes the earlier always-strict local implementation; earlier validation receipts remain historical. No media kit is included by explicit owner instruction.
