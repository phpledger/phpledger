# PHP Ledger 1.4.1 patch implementation plan

Owner-approved local implementation, 24 September 2026. This is a bounded patch to the published 1.4.0 behavior. Baseline: `f325d02d`; isolated branch: `codex/release-1.4.1`. Preserve the main checkout's planning changes and paused feature lanes. Local implementation approval does not authorize publication, deployment or changes to live credentials.

## Scope and current status

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
6. Publication and website/demo deployment require separate release authorization. Once authorized and verified, record actual source/artifact identity and public acceptance before issue closure. Do not send campaigns or perform unrelated provider actions.

## Later work stays separate

The owner has preserved **1.5 maintenance → 1.6 stabilization → 1.7 deferred feature delivery**, with Academy foundations and guided practice at **2.0 or later**. The expanded setup/chart, feature-choice enforcement, Freelancer/country-profile, FX/inventory-cost-method and database-parity scope remains outside 1.4.1. This patch does not resume the paused feature lanes or rewrite their existing plans.

## Evidence at preparation time

The shared date component, scoped browser recovery/navigation, resource generators, website and exact-package installation/upgrade gates passed. Full application checks passed 699/0 on each database engine; isolated demo and signed preload verification passed within their recorded scopes. Follow the [exact-artifact gate record](../repository/RELEASE-1.4.1-GATES.md) for final results and outstanding acceptance. No patch publication or production change is claimed. Historical release receipts do not establish this candidate's acceptance.
