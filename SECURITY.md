# Security policy

PHP Ledger is open-source, self-hosted double-entry accounting software for small businesses, running on PHP 8.2+ with MySQL 8.0.19+ or MariaDB 10.4+. MySQL 8.4 is recommended. If you find a security problem, please report it privately rather than in a public issue, discussion or pull request.

## Scope

In scope:

- The modern application in `www/phpledger`.
- The latest stable [release package](https://github.com/phpledger/phpledger/releases/latest), signed update metadata and official container images.
- The website at https://phpledger.com/ and the public demo at https://phpledger.com/demo/.

Out of scope:

- The 2015 application preserved in Git history. It is unmaintained and absent from the modern runtime. Do not deploy it, and do not report problems in it here.
- Third-party dependencies. Report those to their own projects; a report that PHP Ledger pins a vulnerable version is welcome.
- Hosting you control (web server, TLS, operating system, database server), unless the project's installation documentation is what is wrong.

## How to report

- Preferred: [open a private vulnerability report on GitHub](https://github.com/phpledger/phpledger/security/advisories/new).
- Alternatively, email [rmak78@gmail.com](mailto:rmak78@gmail.com) with the subject "PHP Ledger security".

Please do not post exploit details, screenshots of real data or credentials anywhere public.

## What to include

- The affected component and its exact version, tag or commit.
- Steps to reproduce with sample data only.
- The impact you observed or expect, for example data exposure, unauthorized posting or reversal, privilege escalation, or bypass of company and book permissions.
- Your environment: PHP and MySQL versions, web server, and browser if the problem is in the interface.
- Sanitized logs or screenshots. Never send passwords, tokens, session cookies, real business records or database backups.
- Whether you want to be credited, and under which name.

## What to expect

We aim to acknowledge reports within five working days. This is a best-effort statement from a small project, not a guarantee. There is no bug bounty and no payment for reports.

## Coordinated disclosure

Please give the project a reasonable period to investigate and prepare a fix or mitigation before publishing details, and tell us if you intend to publish. The intention is to keep you informed of progress, agree a disclosure date with you once a fix is available, and credit you if you wish.

## Supported versions

| Version | Status |
|---|---|
| Latest stable release | Best-effort security fixes; no guaranteed support period. |
| Older releases and previews | Upgrade to the latest stable release; no separate maintenance commitment. |

Published stable versions do not imply independent security certification. Evaluate updates with sample data and a verified backup before applying them to your own installation.

## Public demo

The 1.3 demo at https://phpledger.com/demo/ is one shared installation with a fixed public application login. Visitors complete the actual installer and onboarding, and can see or change the shared fictional data. Hosting keeps real database settings private and resets the database, configuration and sessions every hour. Do not enter real records or private credentials. Do not run load tests, destructive infrastructure tests or automated scanning against the public service; use your own installation for security testing. See the [privacy policy](https://phpledger.com/privacy/) for optional installation notices and retention.
