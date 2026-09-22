# Period close, reversing journals and cash counts

Local M17 implementation for issue #93, owner decision B89. This is unreleased source; it does not assert accountant acceptance, live use or deployment. Migration `047_period_close` is additive. It preserves immutable posted journals and existing period action history.

## Period close

`/periods` retains creation, closing and owner-authorized reopening and adds a checklist for each period. Core computes unposted general/AR/AP drafts, pending scheduled reversals, the opening-cutover gap and unreconciled cash/bank accounts. Drafts, pending reversals and an incomplete confirmed opening cutover are required by default; unreconciled money accounts are advisory. Required computed items cannot be cleared by an attestation. An owner/accountant can add company attestations as required or advisory. `pl_period_set_item_severity()` sets a book-specific severity policy. Closing rechecks the core facts while holding the shared posting service's exclusive book lock.

A stock count that agrees currently creates no stock movement. For this reason the location checklist is explicitly an attestation, not a claim that the system proved a physical count. No inventory count document or shift management was added. A tick records actor, UTC time, state and reason in the existing immutable period action log; reopening preserves ticks. Editing the checklist of a closed period is refused. Advisory outstanding items remain visible and do not refuse close.

The `period.checklist` pre-lock filter lets packages append computed items with namespaced keys, labels, hints, severity, boolean `satisfied` and optional detail. It cannot remove or alter existing core/company entries. Plugin facts are evaluated before the transaction under the existing hook lock contract; core facts are rechecked under lock. Plugin authors must not treat their pre-lock observation as atomic with closing. `period.closed` runs after commit and receives the reviewed checklist in its context. The combined M17 integration updates the plugin API version and snapshot for this additive hook.

## Reversing journals

The posted journal detail screen offers a reasoned schedule for standalone journals. `pl_schedule_journal_reversal()` stores `reverse_on` in `pl_journal_reversal_schedules`, keyed and foreign-key-scoped to the original journal, with an immutable event trail. This corrects the abandoned WIP approach of updating `pl_journals`: the posted-header immutability trigger is unchanged. Clearing a pending date is also audited. Document-specific commercial, opening, stock, settlement and cash-count sources keep their own correction workflows.

B89 has two synchronous triggers: creation/reopening of the target period, and recording a schedule when that period is already open. Both use the existing linked reversal and central posting services under the book lock. The due date must be after the original date. Recording a past due date requires the backdated-reversal permission. A previously recorded schedule can fire when a period opens late; the posting guard verifies the stored schedule rather than accepting a boolean bypass alone. No background worker, scheduler or second reversal posting service was added.

A closed, reconciled, invalid-account or source-dependent refusal cannot force an invalid journal through. Period opening records refused reversals in its durable receipt and leaves them as outstanding checklist items; the web notice names them. Opening retries return the original receipt. A linked reversal remains unique even when requests race. For a refused schedule, resolve the underlying issue and submit the schedule again while the period is open, or reopen the target period after an authorized correction.

## Cash counts

`GET /cash-counts` shows history, an optional account filter and a count detail. `POST /cash-counts` records the count and any difference atomically; it enforces CSRF, selected company/book and the existing company-write permission. Counts may include denomination evidence and always preserve zero-difference counts. The history totals describe the displayed latest 100 records, not an unbounded lifetime aggregate.

The account must be an active, postable `cash_bank` asset in the selected book, with no designated foreign currency. Foreign-currency counts are excluded from selection and refused even if the count would agree; FX counting is outside this implementation. This is the existing shared money role; there is no independent cash-versus-bank classification. The count date must have an open accounting period even when the count agrees. Amounts use decimal strings and BCMath; physical cash and denominations require whole minor units. Denomination extensions must exactly equal the counted amount. A valid UTC timestamp records the physical observation. The book balance is the dated ledger balance as it exists while recording, including entries on or before the count date; later or backdated entries do not rewrite the count.

Overages debit the cash account and credit `core.expense.cash_over_short`; shortages do the reverse. The current starter chart seeds this separate expense account, migration 047 backfills it where the corresponding code is free, and an existing book without one provisions it through the existing account-code allocator. Earlier chart package bytes remain unchanged. The additional provision stays outside the thirteen opening-account mapping purposes. Accountant review of the over/short presentation and the severity defaults remains an acceptance gate.

Counts and denomination rows are immutable. A repeated request identity must match the original actor and normalized payload; otherwise it is refused. Correct an erroneous count by recording a fresh count with a reason; the new difference adjusts the book from its current dated balance. Any linked reversal remains visible through journal history.

## Validation and boundaries

Targeted suite: `php tests/run.php --suite=period-close`. It covers hard blockers, package removal attempts, audit/retry behavior, immutable original journals, both B89 triggers, account/company isolation, exact over/short/agreed counts, closed-period rejection, denomination validation, concurrent cash-count retries and rollback on audit failure. It also includes HTTP sign-in/company selection, GET rendering, CSRF/scope rejection, count posting and schedule submission.

The coordinator owns the combined MySQL/MariaDB full suite, extracted-package install and upgrade checks, and desktop/tablet/mobile browser evidence. Local technical tests do not constitute accounting review or observed usability acceptance. No production, external-provider or stakeholder action is part of this implementation.

Lane evidence on 23 September 2026: PHP 8.3 / MySQL 8.4 isolated temporary database, **36 targeted tests, zero failures**, changed PHP lint and diff check clean. This includes real HTTP route workflows and old-snapshot/concurrent retry regressions. Full dual-engine and package acceptance remain the coordinator's combined gate.
