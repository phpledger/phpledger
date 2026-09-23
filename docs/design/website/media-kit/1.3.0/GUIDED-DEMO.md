# Guided demo: install, record and review

Use fictional information only. Captions in screenshots/manifest.json identify the actual capture context; this guide is a workflow, not proof that any screenshot or deployment has already been verified.

## 1. Start with the shared installation

Open https://phpledger.com/demo/. If the hourly reset has returned it to setup, follow the actual installer stages: Start, Database, Build, Checks, Account and Ready. The demo database fields contain labelled dummy values. Its real database connection is fixed by the host and cannot be changed through the form.

The shared account is fixed: username **demo**, password **DemoLedger123!**. If another visitor already completed installation, use those details to sign in. These are intentionally public demo credentials, never a password to reuse on your own hosting. Multiple visitors share the same fictional records. All database contents and PHP sessions disappear at the hourly reset.

## 2. Choose a business starting point

Follow ordinary onboarding. Use the neutral starter for a blank business. The demo host can preload verified read-only sample packages. A sample structure starts a real business with zero balances; complete fictional history belongs in a separate practice company. The sample label is deliberate.

On your own installation, open Packages to review available samples. Directory refresh and installation are explicit actions; page loads do not download them. Exact dependencies must already be installed and reviewed. A sample never silently installs executable plugins. Removing a source package preserves the companies and journals already created from it.

## 3. Post a small fictional transaction

Choose the intended company and currency. From Home, choose Receipt for money received or Expense for money paid. Each opens its own form with matching payer/payee and category labels. Enter fictional amounts, inspect the accounts and balanced preview, then post. Open the resulting journal and account statement. Find the same amount in the trial balance. Do not assume an old screenshot's numbers will match your shared demo session.

Try a correction and inspect its linked reversal. Posted history remains traceable. If another visitor changes a shared record, reload and review the current state before proceeding.

## 4. Explore people and payroll accounting

Open Employees and review the confidential register using a fictional identity. Staff/driver links are explicit; no hire date or related-party flag is inferred. Ordinary employee/trade association is separate from related-party designation.

In Payroll, prepare aggregate reviewed totals for a fictional period, inspect the general-journal draft and post only after review. Prepare a partial settlement and inspect the remaining liability. A later settlement reduces the liability rather than creating payroll expense a second time. This is aggregate accounting, not per-person payroll calculation or a payslip service.

## 5. Review scheduled and loan drafts

Inspect Recurring, Schedules and Loans. A schedule creates ordinary drafts; review the occurrence date, accounts, amounts and any loan rate/day convention before posting. Existing funding or opening balances must be linked explicitly. The software does not establish that a chosen convention matches a real lender's contract.

## 6. Prepare a close

Review cash counts and the period-close checklist. For fiscal year end, select the legal treatment and destination accounts explicitly. Partnership allocations need reviewed ratios, not assumed ownership percentages. Inspect the preview and any outstanding items before closing. Close, roll-forward and reopen retain traceability; performance-report comparatives remain meaningful.

## 7. Move to your own hosting

Download the application ZIP and its checksum from the published 1.3.0 release. Check the artifact against the release receipt and the media manifest's source-archive hash. Use PHP 8.2+ with the required extensions and MySQL 8.0.19+ (8.4 recommended) or MariaDB 10.4+. Configure HTTPS, private storage, database TLS and a validated installation-specific table prefix where appropriate. Follow the exact packaged INSTALL and UPGRADE instructions.

Official container channels are ghcr.io/phpledger/phpledger and phpledger/phpledger on Docker Hub. Pin a verified release tag and preserve the database and private storage. Both images use the same verified application ZIP. Rehearse a matched backup and recovery before keeping real books.
