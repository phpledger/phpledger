# Core accounting and optional modules

Use the [published release](https://github.com/phpledger/phpledger/releases/latest) and its verification receipt for the version available now. This page describes the 1.3 release line; earlier [[1.1.3 notes|Release-1.1.3]], [[1.1.0 notes|Release-1.1.0]] and [[first stable package|First-Package]] remain historical records.

PHP Ledger combines the required chart, journals, AR/AP and manually configured tax with optional purchasing, inventory, stock locations and a stock-connected counter till. Permissions, exact money, company/book scope and the posting service are shared across workflows. API/MCP financial access remains read-only; only tested clients belong in the published compatibility matrix.

## Implemented workflows in the 1.3 line

- Trading documents, pack/unit entry, discounts and free-goods lines, document numbering, advances, unapplied amounts and reviewed allocation workflows.
- Plugin lifecycle, hooks, scoped capabilities and a private encrypted plugin secret store. These are foundations, not proof that a provider connector has shipped.
- A confidential employee register with explicit sales-staff/driver links and preserved historical labels. Employment, ordinary trade association and related-party designation are separate choices.
- Aggregate payroll accruals, advances and partial settlement through reviewed journal drafts. No per-person pay-run, payslip or statutory payroll service.
- Recurring, accrual and loan schedules that prepare drafts. Loan conventions are disclosed and require review against the actual contract.
- Period-close checklists, cash counts, dated reversals and fiscal-year close/roll-forward/reopen with explicit legal treatment, destination accounts and any reviewed partner ratios.
- Browser installation, publisher-verified releases, installation-specific table prefixes, database TLS and MySQL 8.0.19+ (8.4 recommended) or MariaDB 10.4+. Arabic is a draft RTL translation pending native language and accounting review.
- Optional technical installation notices with user control; named registration is a separate opt-in. Existing installations remain silent until an administrator chooses.
- Separate validated CC0 sample packages. The neutral starter remains in core. Directory refresh/install is explicit; dependencies are never silently installed. The shared demo uses verified read-only preloads.
- Official GHCR and Docker Hub image channels built from the same verified application ZIP, with final tags/digests and shared-installer demo checks recorded during publication.

The public demo is one shared disposable installation, not private books for each visitor. Its fixed public login is **demo / DemoLedger123!**. Database fields show labelled dummy values; real host settings are protected. The hourly reset removes fictional records and PHP sessions.

## Future work and independent acceptance

Regional tax/e-invoicing connectors, statutory payroll, advanced batches/serials/expiry, full manufacturing, e-commerce, controlled financial API/MCP writes, native/offline clients and specialist industry plugins need their own implementation and acceptance evidence. A sample labelled pharmacy, restaurant or manufacturing is teaching data, not a complete industry product or certification.

PostgreSQL, SQLite/Windows packaging and additional managed-provider/catalogue acceptance remain separate work; do not treat table-prefix/TLS support as acceptance of every provider. No future ordering here is a delivery-date promise.

Independent accounting and security review, supervised real-business pilots, a real month-end close, native Arabic review, unfamiliar-operator installation observation and restricted-host recovery qualification remain explicit gates. Published automated or developer-operated tests do not replace them.

The installer-created customer website remains parked. Khata remains an optional unposted-subledger concept. No module may hide posted entries or create a second ledger. See [[Licensing]], the [detailed module roadmap](https://github.com/phpledger/phpledger/blob/master/docs/MODULE-ROADMAP.md), [installer guide](https://github.com/phpledger/phpledger/blob/master/docs/INSTALLER.md) and [[Roadmap]].
