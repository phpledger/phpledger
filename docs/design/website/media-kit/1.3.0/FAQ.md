# PHP Ledger 1.3.0 FAQ

## What is new?

Aggregate payroll accounting, reviewed recurring/accrual/loan drafts, confidential employee links, period-close cash counts and fiscal-year close/reopen workflows. Hosting features include table prefixes, database TLS, MySQL 8.0.19+ support, draft Arabic RTL and optional installation notices. Multi-year sample histories are separate validated data-only packages.

## Does payroll calculate salaries or file returns?

No. It accounts for reviewed aggregate payroll totals, advances, deductions and partial settlements. Per-person pay runs, payslips and statutory calculation/filing are outside this release. Personal employee information remains protected by employee permissions; public ledger descriptions and read APIs do not receive per-person payroll amounts.

## Are scheduled entries posted automatically?

Recurring, accrual and loan workflows prepare ordinary reviewable drafts. Review dates, accounts and amounts before posting. Loan conventions are explicit and must be checked against the lender contract. Retry receipts preserve occurrence identity.

## Does employment make someone a related party?

No. Employment, ordinary trade association and explicit related-party designation are separate choices. Existing sales-staff/driver mappings are explicit and preserve historical labels; missing hire dates are not invented. Basic comparisons use only collected fields. Bank-account matching is unsupported.

## What does year-end closing decide?

The operator chooses a fiscal-year legal treatment, destination accounts and any reviewed partner ratios. The preview identifies the resulting accounting. Close and reopen remain traceable; performance-report comparatives are preserved. Independent accounting acceptance of your policy remains separate.

## Is the public demo private to each visitor?

No. It is one shared installation. Everyone uses fictional records and can join with demo / DemoLedger123! after setup. The real installer uses fixed host settings and labelled dummy database fields. Hourly reset removes the database contents and PHP sessions. Never enter personal records, real business information or private credentials.

## Do sample packages run code?

A sample package contains only validated JSON data and a manifest, with pinned hashes and exact requirements. Directory installs verify the publisher inventory and archive. Uploaded samples are labelled unverified. Required plugins must already be reviewed and installed; a sample never silently installs them. The shared demo uses verified read-only host preloads.

## What hosting is supported?

PHP 8.2+ (8.3 recommended), required extensions, and MySQL 8.0.19+ (8.4 recommended) or MariaDB 10.4+ with InnoDB. Use the exact package's installation instructions for web-server access rules, private paths, TLS and database privileges. Prefix/TLS support is not proof that every managed provider or panel configuration has been accepted.

## Where are the official images?

GHCR: ghcr.io/phpledger/phpledger. Docker Hub: phpledger/phpledger. Both are built from the same verified release ZIP. The publication receipt records verified tags and digests. Local assembly alone does not establish registry availability.

## What does the installation notice send?

New installers offer an optional anonymous technical notice enabled by default. Existing installations remain silent until an administrator chooses. It contains a random installation ID, event, app/PHP/database versions, OS family, deployment mode and timestamp; no financial records, database credentials or paths. Optional registration is off by default and adds only explicitly consented contact fields. Disabling notices never restricts the software. Read the published privacy notice for retention and deletion details.

## Is Arabic reviewed?

Arabic is a draft translation with RTL layout. Native language and accounting review remain pending. Do not describe it as certified or fully reviewed.

## What do the screenshots prove?

Only the route, artifact provenance and visual state recorded in their manifest. They use fictional data and retain exact image hashes. A screenshot does not establish accounting certification, every workflow, production deployment or customer adoption.

## How can I support the project?

Try a complete workflow, report a reproducible fictional example, improve a guide or translation, or contribute a reviewed fix. Project discussions are the contribution destination. No donation payment destination is supplied in this kit. Paid installation or support requires its own agreed scope.
