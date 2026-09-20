## Current package: 1.1.3

**1.1.3**, published 20 September 2026, is the current package; see the [[1.1.3 release notes|Release-1.1.3]]. **1.1.0**, published 19 September 2026, installs like WordPress: upload the `phpledger` folder into any web folder and open its address, and the installer starts by itself. It adds MariaDB 10.4+, a chosen username, an optional logo, and the first signed update metadata. [Download 1.1.0](https://github.com/phpledger/phpledger/releases/tag/v1.1.0) and its [matching media kit](https://github.com/phpledger/phpledger/releases/download/v1.1.0/phpledger-1.1.0-media-kit.zip). [[Release notes|Release-1.1.0]]. **1.0.0**, published 18 September 2026, was the first stable release. Independent accounting review, independent security review, supervised pilots with a real month-end close and unfamiliar-operator installation observation have **not** happened; they continue as post-release commitments.

# Core accounting and optional modules

1.1.0 (like 1.0.0 before it) combines the required accounting core (chart of accounts, journals, AR/AP with manually configured tax) with optional bundled Purchasing and Inventory, a scoped read API/MCP with OAuth/Connections, browser installation and signed automatic updates. Only individually tested API/MCP clients enter the verified compatibility matrix; other clients remain pending.

The core is country-neutral and useful with optional modules disabled. Regional connectors, product workflows and client interfaces reuse the same identities, company/book permissions, fixed-precision money and posting service.

## Delivery order after 1.1.0

1. **1.1.1:** corrected demo labels, the owner's demo and installer feedback, and the first packaging step: demo packs leave the release ZIP and the core reads sample packages from a package directory.
2. **1.2 — stock locations, trading documents and plugins**, in this order: a bundled Stock locations module (warehouses and vans, transfers at carrying value, per-location moving weighted-average cost; work started 19 September 2026); a bundled trading-document module (product packs with pack-and-unit entry, line discounts and free-goods lines, per-type document number series, sales-staff and area dimensions, cash received on the invoice, printable invoice, receipt and statement templates); on-account receipts with oldest-first allocation, unapplied credit and batch receipts in core AR; the plugin runtime (hooks, manifest, loader, activation, plugin migrations with their own receipt table) with the phpledger.com package directory and in-app installer, a verified marketplace and owner uploads behind warnings; the Users module capability catalogue and per-company roles; reviewed Urdu/RTL; and the container image on the release feed.
3. **1.3:** reviewed Arabic/RTL; table prefix and MeekroORM models with portable SQL; the installation notice; cloud-hosted databases; Packagist, app catalogues and Softaculous/Installatron; demand-led reporting refinements. PostgreSQL follows 1.3; SQLite ships with the Windows bundle.
4. Regional tax/e-invoicing connector framework. Pakistan FBR is one planned connector alongside ZATCA, UAE Peppol PINT and Oman; enable applicable reviewed rules before affected production use.
5. Further stock and costing (batches, serials, expiry, landed cost); multiple locations and transfers ship in 1.2.
6. A stock/tax-integrated shop POS, then e-commerce/storefront. The bundled cash POS remains an illustrative demonstration, not a production retail module.
7. Controlled API/MCP write commands with explicit authority and durable retry receipts. 1.1.0 exposes read-only API/MCP access.
8. Restaurant POS, pharmacy POS, exporter, freelancer invoicing, distribution operations and other specialists as **directory plugins**, each with a paired sample company package, never inside the core package (owner decision of 19 September 2026, [package directory](https://github.com/phpledger/phpledger/blob/master/docs/strategy/PLATFORM-ROADMAP.md#package-directory-plugins-and-sample-companies)).

Owner/partner equity reporting and phone-friendly entry remain planned cross-cutting work. Native desktop/Android clients remain planned paid add-ons over the API/MCP contracts. Offline means queued drafts; only the server posts, controls periods and reverses.

The installer-created customer website is parked. Khata is a reserved, optional unposted-subledger concept; formalisation uses normal accounting services. No module may hide posted entries or create a second ledger. [[Licensing]] requires advance declaration of future commercial modules; none is declared by 1.0.0.

See the [detailed module roadmap](https://github.com/phpledger/phpledger/blob/master/docs/MODULE-ROADMAP.md), [installer plan](https://github.com/phpledger/phpledger/blob/master/docs/INSTALLER.md) and [[Roadmap]]. Accounting review, access isolation, exact reconciliation, installation/recovery and observed use remain acceptance gates for each future module.
