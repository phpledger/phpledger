# PHP Ledger 1.4.1 patch implementation plan

Owner-approved local implementation plan, 24 September 2026, for a bounded patch to the published 1.4.0 behavior. Baseline: `f325d02d`; isolated branch: `codex/release-1.4.1`. The owner separately authorized publication after the local gates passed. This document retains the implementation plan and records the public outcome below; it does not authorize unrelated credential or provider work.

## Published outcome and remaining acceptance

The [v1.4.1 release](https://github.com/phpledger/phpledger/releases/tag/v1.4.1), website and hosted demo cutover were verified on 24 September 2026; the [publication receipt](../repository/PUBLICATION-2026-09-24-1.4.1.md) records exact artifact and host evidence. Public verification passed 415 website paths, the signed release ZIP and eleven signed sample packages. Live browser work completed Cedar's 119 posted journals and three drafts across reload, and installer setup reported all 61 migration checksums and six passing checks. #71, #100, #102, #105, #106 and #107 were closed after release-specific evidence; #104 had already closed for shipped 1.4.0 behavior. #95 received a scope clarification without closure; the other deferred issues remain open in the [issue audit](../repository/RELEASE-1.4.1-ISSUES.md).

**R14-02 remains partly open.** Cash warnings, expense detail content and Edit draft/Post expense actions fit at 320, 360 and 390 px without document-wide horizontal overflow. The workspace header's New expense action still ends at x=423.531 at all three widths and clips beyond the viewport. The ignored `live-expense-detail-390.png` in `.cache/release-1.4.1-operator/` records the visible gap. A focused header-action layout correction and browser recheck at all three widths remain follow-up work; the accepted application ZIP is unchanged. The next hosted hourly reset has not been observed. A passing local reset does not stand in for that scheduled live observation.

## Scope and implementation record

| Work item | Implementation and acceptance boundary |
|---|---|
| Shared date selectors | Implemented locally: progressive DD/MM/YYYY entry and keyboard-accessible calendars across date fields; retain ISO submission, native fallback and authoritative server validation. Verify ordinary, dynamically added and invalid form values at desktop/tablet/phone sizes. |
| Employee DOB, #105 | Implemented locally: optional DOB may not follow today or hire date on create/edit. Existing rows remain unchanged until explicit edit. Service and HTTP regression coverage added; targeted database suites passed; full combined gates are recorded separately. |
| Invoice/bill line accounts, #106 | Implemented locally: shared active/eligible/postable policy for line choices, draft save, preview and final posting. Derive leaf status using all book codes, including inactive children; leave general nonposting lists intact. Regression coverage added; targeted database suites passed; full combined gates are recorded separately. |
| Required inputs, #107 | Implemented locally: consistent visible and assistive required indicators using existing field requirements. Preserve optional inputs and retained validation values. |
| Sample completion | Local correction: distinguish full fictional history from structure-only setup and show actual scoped journal/draft counts. Acceptance must cover both paths and revisiting completion after activity. |
| Receipt/expense navigation | Local correction: remove the duplicate combined navigation destination and retain separate Receipt/Expense workflows and legacy route compatibility. Selection, quick actions, validation recovery and edit/return context passed focused browser checks. |
| Phone layouts | Implemented locally: bounded form/report layout fixes, with usable actions and confined report scrolling. Cash warning/detail checks passed at 320/360/390 px. No claim of universal device or independent accessibility acceptance. |
| Credential documentation, #102 | Local guidance in `docs/CREDENTIALS.md`, linked from security/release instructions. Explain provider revocation, secure replacement, artifact/access review and private incident records; make no live rotation or remediation claim. |
| Prior issues #71, #100, #104 | Acceptance candidates only. Use the [issue audit](../repository/RELEASE-1.4.1-ISSUES.md) to verify each specific requirement and publication boundary; do not close an issue on implementation claims alone. |

## Delivery gates

1. Integrate partitioned patches and review shared template/service changes. Preserve existing permissions, CSRF, company/book scope, accounting rules and immutable posted history. No new schema or migrations; no new public API contract.
2. Run changed-file lint and targeted component/service/HTTP tests. Prove rejected DOB/account submissions retain recoverable values and create no unwanted records; prove stale account changes are rechecked at posting.
3. Exercise desktop, tablet and phone workflows, keyboard calendar operation and no-JavaScript forms. Validate current code rather than relying on historical screenshots. Resolve consequential review findings before combined gates.
4. Run full `composer check` on disposable MySQL and MariaDB. Build the exact application archive; verify fresh install, populated 1.4.0 upgrade, migration receipt stability, package inventory and checksums. Record what ran and any unverified limits in the release receipt.
5. Prepare consistent README, release notes, version/package metadata, website/download/social metadata, Wiki and repository About review. Patch releases carry **no media kit**. Keep historical release records intact.
6. Publication and website/demo deployment required separate release authorization. That authorization and bounded verification are recorded in the publication receipt; issue closure followed relevant acceptance. Campaigns and unrelated provider actions remain outside this plan.

## Later work stays separate

The owner has preserved **1.5 maintenance → 1.6 stabilization → 1.7 deferred feature delivery**, with Academy foundations and guided practice at **2.0 or later**. The expanded setup/chart, feature-choice enforcement, Freelancer/country-profile, FX/inventory-cost-method and database-parity scope remains outside 1.4.1. This patch does not resume the paused feature lanes or rewrite their existing plans.

## Historical evidence at preparation time

The shared date component, scoped browser recovery/navigation, resource generators, website and exact-package installation/upgrade gates passed. Full application checks passed 699/0 on each database engine; isolated demo and signed preload verification passed within their recorded scopes. At this preparation checkpoint, no patch publication or production change was claimed. Follow the [exact-artifact gate record](../repository/RELEASE-1.4.1-GATES.md) for those local results and the later [publication receipt](../repository/PUBLICATION-2026-09-24-1.4.1.md) for public verification and the narrowed phone/reset limits.
