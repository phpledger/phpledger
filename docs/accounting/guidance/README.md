# Accounting guidance research

This directory is the **research** behind the in-app help bubbles (owner decisions **B66** and
**B67**). It is not what the application shows.

| | Research | Interface copy |
|---|---|---|
| Lives in | `docs/accounting/guidance/` | `resources/guidance/` |
| Is | sources, paragraph references, dates, working notes, the reasoning | the sentences a bubble renders |
| Ships in a release | no | yes, through `tools/package-files.json` |
| Changes | when the law, the practice or the reading of it changes | when the research says the wording should |

A bubble never reads a file in this directory. The catalogue in `resources/guidance/` is the
interface copy, and these research files are its source: when a concept's wording or a country's
note is settled here, it is transcribed into the catalogue, with `review` moved from `placeholder`
to `reviewed` for a concept, and `status` moved to `verified` with its `source` and `checked_on`
for a jurisdiction note.

**Nothing local ships unsourced.** `pl_guidance_note()` drops any note that is not `verified` with
a source and a check date, so an unfinished entry is invisible rather than wrong. Where a
jurisdiction has no note, the bubble shows the shared explanation alone and adds nothing — it never
implies that the shared text is that country's law. That is B67's rule enforced in code: an
unsourced local claim is worse than none.

**Coverage.** Thirteen jurisdictions: the countries behind the base currencies the application
offers — United States, the euro area, United Kingdom, Pakistan, India, Malaysia, Bangladesh,
Sri Lanka, Nepal, Singapore — plus the United Arab Emirates, Saudi Arabia and Oman, which B67
treats as first-class rather than as a later addition.

**Status.** The catalogue currently carries six concepts, all marked `placeholder`, and no verified
jurisdiction note at all. The wording is engineering placeholder text written to prove the
mechanism; replacing it, and sourcing the local notes, is the work this directory exists for. The
repository's existing country material (`docs/accounting/PAKISTAN_REPORTING_RESEARCH.md`,
`docs/accounting/UK_UAE_REPORTING_RESEARCH.md`, `resources/tax/*.json`) is marked research-only and
review-required, so it is where that work starts, not a source that can be cited straight into a
bubble.

How to add a concept or a note, and the shape of both files: `docs/DEVELOPMENT.md`, "How to add a
help concept or a jurisdiction note". The file shapes are also summarised in
`resources/guidance/README.md`.
