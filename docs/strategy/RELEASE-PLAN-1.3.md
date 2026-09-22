# 1.3.0 completion and publication plan — 23 September 2026

The owner explicitly requested completion and release of 1.3.0, including its matching public demo and website changes. This authorizes the normal release publication, repository metadata/Wiki updates and coordinated demo/website deployment once their gates pass. It does not authorize sending campaigns, collecting payments or changing other projects.

## Baseline and sequencing

- Start from local `1647c96` (M17 complete, 597 tests on each of MySQL/MariaDB), merge the two upstream workflow commits without discarding them, and work on `codex/release-1.3.0` in an isolated checkout. Preserve the main checkout's eight existing generated website files and the verified original-lane backups.
- Read and reconcile the latest owner decisions against issue bodies; B70 overrides the old employee exclusion, B81 moves real-business pilot/accountant-on-real-books gates to 2.0, and B89 makes period-opening reversals synchronous. Internal accounting worked examples and technical upgrade/recovery gates remain required.
- Accounting implementation proceeds now. The discrepancy between the older broad platform/language list and the newer accounting roadmap is raised with the owner; absent a narrower choice, retain the recorded commitments and report any genuine external review or service-access gate explicitly.
- Execute bounded phases: contract and ownership; implementation and focused tests; independent review and combined verification; release artifact/signature/media kit; publication and sequential demo/website rollout with rollback; public evidence and final receipt. Two failed attempts at the same correction trigger diagnosis/escalation, not blind repetition.

## Parallel ownership and contracts

1. Scheduler/recurring/loans lane owns migrations **050–052** and new services, screens, reports and tests. Generalize the existing outbox lease/attempt machine; internal tasks never broadcast to ordinary subscribers. Generate ordinary reviewable drafts, with durable occurrence receipts and renewed permission checks. Preserve anchored recurrence dates and exact decimal release totals. Loan previews explicitly state the selected convention; monthly nominal annual rate / 12, Actual/365 accrual and custom schedules are supported deliberately, never implied to match every lender. Existing funding/opening postings must be linked explicitly.
2. Payroll/employee lane owns **053** and **055**, aggregate payroll accounting, partial settlements and the shared non-trade account-payment seam. No per-person payroll amounts enter the ledger/read API. Existing salesman/driver identities require explicit employee mapping; preserve old references and historical labels and never infer hire dates. Related-party designation remains a separate explicit decision. Compare only attributes actually collected; do not claim bank-account matching without bank data.
3. Year-end lane owns **054**, fiscal-year policy, preview, close, roll-forward and reopen. Require explicit legal treatment, destination accounts and any partner-ratio snapshot. Never infer posting ratios from ownership membership. Closing and closing reversals affect the balance sheet but must not zero the performance-report comparatives. Produce private-company and 60/40 fixed-partnership worked examples, with immutable corrections and idempotent retry proofs.
4. Coordinator owns integration registration, release/platform work, generated resources, public site/demo, signing/publication and final evidence. Shared bootstrap/router/capability/manifest/test/package/API changes are integrated serially from lane patches. Chart supplements trigger both demo-pack and sample-structure regeneration.

## Gates and publication deliverables

- Meaningful financial/scope/permission/CSRF/concurrency/rollback tests and browser checks at desktop, tablet and phone widths; full supported PHP/database matrix; fixed fixtures must preserve strict reconciliation and historical migration checksums.
- Fresh installation from the exact release ZIP, populated prior-release migration, real in-app signed update/recovery and every actually supported channel's upgrade proof. Both generated-resource checks, package allowlist, plugin API snapshot and internal accounting review must pass.
- Verify release claims against the merged tree. Set the single version source to 1.3.0; synchronize README, changelog/release/upgrade documents, package/container metadata, Wiki/About/topics, website download/feed/share metadata and demo identity.
- Produce the 1.3.0 media kit: factual announcement/press copy, social/email drafts (not sent), guided demo, FAQ and verified release screenshots with captions/alt text. Archive and checksum it beside the application and signed update.
- Sign using the existing external publisher key and credential-safe process; never print/export its passphrase. Verify archive/signature/public-key fingerprint and reproduce the CI package before publication.
- Deploy demo and website sequentially through current reviewed, baseline-checked operator paths. Preserve rollback releases, verify the active Nginx vhost and public routes/content/version, and record downloaded asset hashes, image digest, feed and metadata receipts.

The roadmap and this document describe intended work until the publication receipt records each completed gate. No 1.3.0 release or new live deployment is claimed yet.
