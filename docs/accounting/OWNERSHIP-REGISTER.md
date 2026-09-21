# The ownership register

Release 1.2, milestone M8a. Issue [#92](https://github.com/phpledger/phpledger/issues/92); owner decisions **B63** (the register is core, in 1.2), **B64** (the company profile it extends), **B72** (the related-party marker) as corrected by **B74**, **B76** (B71 amended) and **B58** (the authority sensitive data takes).

This is the implementation reference. It records what was built, what was deliberately not built, and the two worked examples the accountant's review gate asks for. **Nothing here has been through accounting review.** That is a separate gate (G15 of the 1.2 plan) and this document is the input to it, not the outcome.

---

## 1. Why the register is core

Decision A3 makes real-time owner's equity the headline and B61 already posts capital introduced, owner loans, repayments and drawings through the central posting service. What no table held was the record that makes the equity section complete and that company law requires a business to keep.

| Jurisdiction | What must be kept |
|---|---|
| Pakistan | Register of members and register of directors (Companies Act 2017) |
| United Kingdom | Registers of members, directors and secretaries, and of persons with significant control (Companies Act 2006, as amended by the Economic Crime and Corporate Transparency Act 2023) |
| Delaware | A stock ledger (DGCL §224) |
| A partnership or AOP | The partners, their profit-sharing ratio, and the capital and current accounts the 1.2 owner-transactions workflow already posts to |

B63 puts all of that in the core and leaves country company-secretarial packs — statutory forms, share certificates as documents, filing calendars, valuations — to plugins over the hook points in §8, the same way country tax rules are adapters. **A plugin never carries a second register.**

## 2. What was built

Migration **044** (042 and 043 were already taken when this branch rebased; numbers are assigned at merge in landing order per B62) adds nine things. The migration header carries the full reasoning and the complete reversal path; this is the summary.

| Table | What it holds |
|---|---|
| `pl_company_profile` *(altered)* | The B64 profile gains `legal_form`, `registration_number`, `registration_authority`, `incorporation_date` and the financial year end as a month/day pair |
| `pl_ownership_parties` | The person or entity records the two registers share |
| `pl_ownership_party_accounts` | One person's B61 partner record in one book |
| `pl_ownership_members` | The members register, effective dated |
| `pl_ownership_officers` | Directors and officers, with the significant-control flag and the nature of control |
| `pl_share_classes` | Share classes. **No issued count column** |
| `pl_share_events` | The append-only share ledger, with two triggers that refuse UPDATE and DELETE |
| `pl_related_party_markers` | The B72/B74 marker |
| `pl_ownership_audit` | The register's own immutable audit |

Two design choices are worth stating because a later change could undo them without noticing.

**The register's people are not `pl_parties`.** `pl_parties` carries `ck_party_roles`, which requires every row to be a customer or a vendor. A shareholder who never trades with the business is neither, so recording one there would mean inventing a trading role for them. B72 anticipated this: the marker points *from* a trade party *into* whichever core register holds the person.

**There is no issued-share count anywhere in the schema.** It is the ledger summed to a date, every time it is read. A stored count is a second source of ownership — which B63 forbids — and it is the field that goes stale first.

**The audit is its own table, and `pl_core_audit` was not touched.** `pl_core_audit.book_id` is `NOT NULL` and every fact in this register belongs to a company, not a book; migration 041 made the same choice for `pl_user_audit`. A happy consequence: migration 044 does not modify the `pl_core_audit` `entity_type` ENUM at all, so it cannot repeat the union bug migrations 037 and 038 collided over.

## 3. What posts, and what does not

| Event | Accounting effect |
|---|---|
| **Allotment** | Posts, or links an already posted journal. `Dr` cash or bank (the consideration) / `Cr` share capital (nominal) / `Cr` share premium (the excess) |
| **Bonus issue** | Posts, or links. `Dr` the reserve being capitalised (chosen by the operator) / `Cr` share capital (nominal) |
| **Transfer** | **Posts nothing, ever.** A sale of shares between two shareholders is a transaction between them; the company's own assets, liabilities and equity are unchanged. A schema CHECK refuses a journal on a transfer |
| **Cancellation** | Never posts from here. May link an already posted journal |
| **Re-designation** | Never posts from here. May link an already posted journal |

Cancellation and re-designation do not post because a capital reduction's accounting is jurisdiction specific and court- or solvency-gated, and a re-designation's treatment depends on the classes' terms. Choosing either would be the silent accounting guess `AGENTS.md` forbids.

Three refusals follow from the same principle and are worth knowing before you meet them:

- **No account is ever guessed.** The share capital account, the share premium account, the bank account and the reserve a bonus issue capitalises are all named explicitly. `pl_owner_pick_account()` lets a single candidate stand in for a choice where one account can only mean one thing; share capital and share premium are two different accounts and a chart with one equity account cannot supply both, so naming them is always required.
- **The nominal total is derived, never entered.** Share capital *is* the number of shares times their nominal value. If the multiplication does not come out to a whole amount of money, the event is refused rather than rounded, because the rounding would otherwise land in share capital and stop it reconciling to the register.
- **Shares at a discount are refused.** The consideration may not be below nominal. Where a jurisdiction permits a discount, post that journal where it belongs and link it to the event.

A correction is a **linked reversal**, as everywhere else in the core. The register correction takes the original event's own date — a share that was never validly allotted was never held, and a snapshot in between has to say so — while the reversing **journal** follows the ledger's rules, which date it today unless the person holds `journal.reverse_backdated`. The two dates can legitimately differ and that is not an oversight.

## 4. The related-party marker: B72 as corrected by B74

B72 approved the marker on the assumption that an employee is a related party. **B74 checked the standard instead of practice and found that assumption wrong.** IAS 24 relates a person through control, joint control, significant influence, or membership of key management personnel, which includes any director. A word-level check of the verbatim adoption finds "employee" never used as a class of related party, and IAS 24.11 says a customer or supplier is not related through economic dependence alone.

The implementation follows the correction exactly:

1. **An ordinary employee is not a related party**, and nothing in the code makes one.
2. **Every person defaults to *not* related.** There is no default-related state in the schema. A marker exists only because somebody with the authority affirmatively recorded one, on a date, with a reason. No role, no appointment, no employment link and no significant-control flag creates, implies or back-fills a marker. `tests/ownership_test.php` appoints a director who is also a supplier, changes nothing else, and asserts the related-party report is **empty**; if anybody ever makes a role imply a marker, that test fails.
3. **The flag is a separate attribute from any role or employment link.** It lives in its own table with its own two capabilities and its own effective dates.
4. **`relationship` carries only the three kinds IAS 24 names:** key management personnel, a close family member of one, and an entity controlled or jointly controlled by either.
5. **Reading a marker takes `relatedparty.view`**, the authority B58 reserves for sensitive data, so the register is invisible to whoever manages customers. The read and the write are separate capabilities, because the person who prepares the disclosure is usually not the person who decides who is key management personnel.

`related_register` names which core register holds the person and `related_id` is that register's own row id. There is no foreign key on `related_id` on purpose: it points into a different table per register, and the employee master is 1.3 work (B70). `pl_related_party_subject()` is the one place that resolves it and it refuses a register it does not know — which is what lets the employee register arrive **without a schema change**, exactly as B72 intended. Until it exists, a marker that names `employee` is refused with a message saying so.

### The cross-check, not a wall

B74 and B75 record that the ACFE and COSO anti-fraud tests match one register against another on name, tax identifier and bank account, and treat a hit as **a flag to investigate, not a forbidden state**. `pl_related_party_candidates()` is that test applied here: it lists trade parties that match a person in the officers or members register by normalised name or by a registered identifier, and that carry no marker. **It marks nobody.** It is also what makes an affirmative designation workable in practice — a director who is also a supplier is exactly the marker somebody forgets to record.

### The two artefacts it produces

- **`pl_related_party_transactions()`** — the IAS 24.18 artefact: every transaction with a marked party in the period, with the amounts, the outstanding balance, the terms and the relationship. The movement comes from `pl_party_statement()`, the same control-account movement the party's own statement shows; there is no second computation of a balance.
- **`pl_director_loan_movements()`** — opening, advanced, repaid, closing, per director. **One artefact satisfies two disclosures** (B74): Pakistan's Fourth Schedule requires a movement reconciliation of loans and advances to directors, and Companies Act 2006 s.413 requires advances and credits granted to directors in a note. The direction is read from the linked account's own type rather than assumed — PHP Ledger's B61 partner loan account is ordinarily a *liability*, money the director lent the business, while the disclosure both regimes are written around is the *asset* direction — and the report states which it is for each director instead of forcing one reading on the other.

### The honest gap

IAS 24.18(d) asks for the provision for doubtful debts on related-party balances. **PHP Ledger records no allowance against an individual party**, so the per-party provision is reported as `null` and the book's contra-asset accounts are listed with their balances for the preparer to allocate. Printing a figure nobody computed would be worse than saying the software does not hold one. This is a candidate for the accountant's review to rule on.

## 5. Worked example 1 — a private limited company, two periods

Chart: `Share capital` and `Share premium` are two ordinary equity accounts; `Bank` is the cash account. One class, **ORD**, nominal 10.00, authorised 100,000. Currency USD for the example; nothing here depends on the currency.

### Period 1 — year to 31 December, year one

| Date | Event | Journal |
|---|---|---|
| 1 Feb | 1,000 ORD allotted to Ayesha at nominal (10.00) | `Dr` Bank 10,000 / `Cr` Share capital 10,000 |
| 1 May | 500 ORD allotted to Bilal at 25.00 | `Dr` Bank 12,500 / `Cr` Share capital 5,000 / `Cr` Share premium 7,500 |

**Equity presentation at the end of period 1**

| | Amount |
|---|---|
| Called-up share capital (1,500 shares × 10.00) | 15,000 |
| Share premium | 7,500 |
| Retained earnings | — |
| **Total equity** | **22,500** |

Share capital **reconciles to the register**: 1,500 shares in issue × 10.00 nominal = 15,000. That reconciliation is the reason the nominal total is derived from the class rather than entered.

Book value per share = 22,500 ÷ 1,500 = **15.000000**.

### Period 2 — year to 31 December, year two

| Date | Event | Journal |
|---|---|---|
| 15 Mar | Ayesha transfers 200 ORD to Bilal | **none** |
| 15 Jun | Bonus issue of 150 ORD to Ayesha, capitalising share premium | `Dr` Share premium 1,500 / `Cr` Share capital 1,500 |

**Equity presentation at the end of period 2**

| | Period 1 | Period 2 |
|---|---|---|
| Called-up share capital (1,650 × 10.00) | 15,000 | **16,500** |
| Share premium | 7,500 | **6,000** |
| Retained earnings | — | — |
| **Total equity** | **22,500** | **22,500** |

**Neither event changes total equity.** The transfer changes who owns the company and nothing in its books; the bonus issue moves 1,500 from a distributable-ish reserve into called-up capital, which is precisely the point of a bonus issue and precisely why it must not be inferred from the event type alone — the reserve capitalised is the operator's choice.

**Register at the end of period 2**

| Holder | Shares | % of class |
|---|---|---|
| Ayesha | 1,000 − 200 + 150 = **950** | 57.575757 |
| Bilal | 500 + 200 = **700** | 42.424242 |
| **Total** | **1,650** | |

Book value per share = 22,500 ÷ 1,650 = **13.636363**. It fell although equity did not move, because there are more shares over the same equity — which is the whole substance of a bonus issue and the thing a reader of the figure has to understand. The report prints its `basis` beside the number for that reason, and warns where a preference class exists, because the figure applies no liquidation preference.

## 6. Worked example 2 — a partnership (AOP), two periods

Two partners with fixed profit-sharing ratios: **Aslam 0.6, Kamran 0.4**. Each has their own capital account and their own drawings account — migration 040 enforces one account per partner per role, because `pl_owner_partner_positions()` reads a position by account balance and a shared account reports its whole balance against both partners. Kamran also lends money to the firm, which uses the bundled chart's owner-loan account.

### Period 1 — year to 31 December, year one

| Date | Event | Journal |
|---|---|---|
| 15 Jan | Aslam introduces capital 60,000 | `Dr` Bank / `Cr` Capital – Aslam |
| 15 Jan | Kamran introduces capital 40,000 | `Dr` Bank / `Cr` Capital – Kamran |
| 30 Sep | Aslam draws 5,000 | `Dr` Drawings – Aslam / `Cr` Bank |

**Partner capital account statement, period 1**

| | Aslam | Kamran | Total |
|---|---|---|---|
| Capital, opening | — | — | — |
| Capital introduced | 60,000 | 40,000 | 100,000 |
| **Capital, closing (fixed presentation)** | **60,000** | **40,000** | **100,000** |
| Drawings in the period | 5,000 | — | 5,000 |
| **Capital less drawings (fluctuating presentation)** | **55,000** | **40,000** | **95,000** |

### Period 2 — year to 31 December, year two

| Date | Event | Journal |
|---|---|---|
| 15 Feb | Kamran introduces a further 10,000 of capital | `Dr` Bank / `Cr` Capital – Kamran |
| 15 Mar | Kamran lends the firm 20,000 | `Dr` Bank / `Cr` Owner loan – Kamran |
| 15 Aug | Aslam draws a further 3,000 | `Dr` Drawings – Aslam / `Cr` Bank |

**Partner capital account statement, period 2**

| | Aslam | Kamran | Total |
|---|---|---|---|
| Capital, opening | 60,000 | 40,000 | 100,000 |
| Capital introduced | — | 10,000 | 10,000 |
| **Capital, closing (fixed)** | **60,000** | **50,000** | **110,000** |
| Drawings, opening | 5,000 | — | 5,000 |
| Drawings in the period | 3,000 | — | 3,000 |
| Drawings, closing | 8,000 | — | 8,000 |
| **Capital less drawings (fluctuating)** | **52,000** | **50,000** | **102,000** |
| Owner loan, opening | — | — | — |
| Owner loan advanced | — | 20,000 | 20,000 |
| Owner loan repaid | — | — | — |
| **Owner loan, closing** | — | **20,000** | **20,000** |

**Both presentations are shown because which one a partnership uses is an agreement between the partners, not a setting in the software.** Under a fixed-capital method the capital account holds only introductions and withdrawals of capital, and everything else — drawings, salary, interest, the profit share — runs through a current account; under a fluctuating method it is one account. The statement gives the capital account on its own (fixed) and capital net of drawings (fluctuating), and the accountant chooses the presentation.

**Kamran's 20,000 is a liability, not equity** (B61, B60). The business owes it back, so it sits above the equity section and never inside it, and it is reported beside the capital account rather than in it.

**Profit for the period is deliberately not allocated.** Salary, interest on capital and the treatment of a loss are an agreement between the partners and an accounting policy decision (B30), and the statement says so on its face.

### The same loan, as the director disclosure

If Kamran is also recorded as a director in the officers register, the same account produces the movement reconciliation both regimes ask for:

| Director | Direction | Opening | Advanced | Repaid | Closing |
|---|---|---|---|---|---|
| Kamran | Owed by the company to the director | — | 20,000 | — | 20,000 |
| Aslam | — | — | — | — | — (no loan account linked) |

Aslam appears with a reason rather than being left out, because an empty row that says why is a finding and a missing row is not.

## 7. Open Cap Format export

`pl_ownership_export_ocf()` emits the Open Cap Table Coalition's shape for **the objects PHP Ledger holds**: the issuer, the stakeholders, the stock classes, and the issuance, transfer, cancellation and class-conversion transactions, with OCF object types, id prefixes and its `{amount, currency}` monetary shape.

**It is not a claim of full OCF conformance.** PHP Ledger has no stock plans, vesting terms, valuations, warrants or convertibles, so those collections are present and empty rather than fabricated.

The round trip is proved rather than asserted: `pl_ownership_ocf_holdings()` reads a document back into holdings and writes nothing, and the fixture test exports a register containing an allotment, a transfer, a cancellation, a re-designation and a corrected event, then rebuilds the holdings from the exported transactions alone and asserts they equal the register's own. A receiving tool has to be able to do exactly that with what we emit, so if the reader cannot reproduce the holdings, the export is wrong.

## 8. The seams for the plugin runtime (#73)

The plugin runtime is being built in parallel on `m8/plugin-runtime`, so this milestone depends on none of it. What it ships instead is the seam.

**Hook points**, all emitted after the write, with the return value discarded and a failing listener logged rather than rolled back:

| Hook | Carries |
|---|---|
| `ownership.share_event.recorded` | The event row, its class and both sides |
| `ownership.share_event.reversed` | The correction and the event it corrects |
| `ownership.officer.appointed` | The appointment |
| `ownership.officer.resigned` | The appointment, now carrying a resignation date |
| `ownership.member.recorded` | The membership interest opened or closed |
| `ownership.related_party.marked` | The marker recorded or ended |

**What M8 connects:** one listener registered through `pl_ownership_on('*', …)` that forwards to the runtime's dispatcher. No call site in `ownership_functions.php` changes, and the payload shapes above are the contract.

**Read API** (`pl_read_catalog()`): `ownership_snapshot` and `share_ledger`, both with explicit DTOs so a read grant sees the ownership facts and not the recorder, the revision or internal ids.

**The related-party markers and both related-party reports are deliberately absent from the read API.** Reading one takes the authority B58 reserves, and a read connection is a token, not a person with a role: exposing a marker there would put "this customer is a director's wife" behind an API key. A plugin that needs it asks a signed-in person. `tests/ownership_test.php` asserts no catalogue operation name contains `related` or `director_loan`, so it cannot be added by accident.

## 9. Deliberately not built

Statutory form generation, share certificates as documents, e-signature, filing calendars, valuations, option vesting and group structures across companies. These are plugins (B63, and the issue's own out-of-scope list).

Also not built, and worth recording as known limits:

- **The employee register** (B70, 1.3). `related_register` already allows it; a marker naming it is refused with a message until it exists.
- **A per-party provision for doubtful debts** — see §4.
- **Profit allocation between partners** — B30 leaves it to the accountant.
- **Reconciliation between `pl_ownership_members.profit_share` and `pl_owner_partners.profit_share`.** The register's ratio is effective-dated history; the operative ratio the posting service uses is the partner record's. Editing the partner's ratio on the Owner screen can therefore leave the two disagreeing, and nothing refuses that today. Whether the register should be the enforced source is a question for the accountant's review.
- **Liquidation preferences** are not modelled, so book value per share overstates what an ordinary share would receive on a winding up where a preference class exists. The report says so and warns when such a class is in issue.

## 10. For the accounting reviewer

The specific questions this milestone puts to review:

1. Is the equity presentation in §5 right for a private company under IFRS for SMEs and under the Pakistani Fourth/Fifth Schedule — in particular, share capital at nominal with the premium separate, and the bonus issue presented as a transfer within equity?
2. Is the fixed/fluctuating presentation in §6 complete enough without a current account as a distinct record, given that drawings already sit in their own contra-equity account per partner?
3. Should the related-party report carry a per-party provision at all, given §4, or is naming the chart's contra-asset accounts the right answer for a system that does not hold a per-party allowance?
4. Is "any director is key management personnel" the right population for the director loan report, or should it follow a broader key-management definition where the entity has one?
5. Should the members register's effective-dated profit share be the enforced source of the operative ratio, per §9?
