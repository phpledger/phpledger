# PHP Ledger repository instructions

## Read first

Before each task, read [AGENTS_SYNC.MD](AGENTS_SYNC.MD) for shared state and ownership, then the latest entries in [AGENT_MESSAGES.MD](AGENT_MESSAGES.MD). Claude and Codex use these repository-only files to coordinate: record the intended scope before shared edits or deployment, preserve another agent's work, and append the outcome and evidence when handing off. These files are never web artifacts.

Read `README.md`, `docs/ARCHITECTURE.md`, and `docs/ROADMAP.md`, then the relevant product/design documentation and current code. `docs/LEGACY.md` preserves historical context, not current installation guidance. The user-approved roadmap permits new foundation schemas and versioned migrations; do not add unrelated schema changes.

## Working boundaries

- Work locally unless the user explicitly authorizes a live action. Do not push, deploy, publish, send messages, or collect payments merely because a design or implementation was approved.
- Preserve unrelated work. The owner removed the legacy application from the current tree on 15 September 2026; refer to Git history for research and do not restore it into the runtime. Never run historical SQL dumps against the new database. Never serve the repository root.
- The new application is `www/phpledger`; its only web document root is `www/phpledger/public`. Use the new shared bootstrap and existing helper interfaces. Do not load legacy root code into the new application.
- Retain BixiSoft's modular PHP/MeekroDB architecture. Do not add an alternative framework, ORM, authentication stack, router, or second database connection layer without an explicit design decision.
- Keep secrets and real customer data out of files, logs, fixtures, screenshots, commits, and responses. Use sample fixtures and local-only services for development.

## Accounting and security

- Use one central posting interface, fixed-precision money, transactions, duplicate prevention, and a durable source reference. Never write posted journal rows directly from a screen.
- Enforce company/book membership and action permissions on the server. Include CSRF protection on browser POST actions, contextual output escaping, and bound database parameters.
- Keep posted entries immutable. Corrections use traceable linked reversals; closed periods reject new postings. Reports and imports must use the same company/book scope as writes.
- Keep financial rules in small typed functions independent of request globals, HTML, and sessions.
- Historical imports must preview and validate before confirmation and must reconcile opening AR/AP with unpaid documents at the cutover date. Do not silently guess missing accounting data.
- Multiple accounting books require an approved definition and reconciliation rules before implementation; a company/book identifier in the foundation is not a completed multi-book feature.

## Design, documentation, and validation

- Use `docs/DESIGN.md` and the selected direction once approved. Before selection, identify screens as candidate directions or foundation previews.
- Update the existing docs when behavior, commands, routes, permissions, workflows, or deployment expectations change. Keep implemented facts distinct from targets and pending gates.
- Lint changed PHP and applicable JavaScript. Run relevant financial/access tests and fresh-install checks. Browser-check changed screens at desktop, tablet, and mobile widths where practical.
- State exactly what ran and what was skipped. Never equate a build, screenshot, or passing technical test with accounting review or observed usability success.
- Report changed files, tested workflows, references read, migration/schema status, secret exposure, external/live calls, production impact, test results, and remaining risks.

## Every release includes a media kit

- Every major release (`x.y.0`) must ship a matching versioned media kit alongside the application archive and checksum, and is incomplete without it. Minor, patch and preview releases carry no media kit (owner decision B29, 20 September 2026); their release notes say so.
- Attach the kit to the GitHub release and include its download link in the release notes and owner handoff. Include factual announcement/press copy, social and email drafts, a guided demo, FAQs and verified screenshots from that release with captions and alt text. Use sample data and distinguish shipped capabilities from limitations or planned work.
- Verify the public media-kit download and record its checksum in the publication receipt. Preparing or attaching the kit does not itself authorize sending campaigns to external recipients.

## Every release updates documentation and repository metadata

- Every release, including previews and patches, must review and update the GitHub Wiki, repository About description, website URL and topics, README, changelog/release notes, version manifests, package metadata, website download metadata and social/share metadata where affected. Keep unchanged facts unchanged; do not churn metadata merely to change a version.
- All release surfaces must describe the same shipped capabilities, version, download links and limitations. Preserve historical release records and distinguish future roadmap items from shipped behavior.
- Verify the published Wiki, About details and affected metadata after publication, and record updated or reviewed-unchanged status in the publication receipt and owner handoff. These checks are required alongside the application archive, checksum and media kit.

## Reference boundaries

BixiSoft conventions were consulted from local Agency75 architecture documentation. That project's CRM workflows, production settings, credentials, providers, and deployment arrangements are not PHP Ledger requirements. Required external references should be read if available; reference access does not authorize editing them.
