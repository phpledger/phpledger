# Strategy: single reference point

Everything from the 15 September 2026 strategy review lives here. Start with the decision register; the other documents are the evidence behind it. None of these documents changes the roadmap by itself — roadmap and product-brief edits follow only after the owner approves the specific decisions.

| Document | What it is | Read it when |
|---|---|---|
| [Decision register](DECISION-REGISTER.md) | Every decision from the review — decided, open, or recommended — with evidence and the action each unblocks; immediate actions at the end. | You need to know what has been decided, what is still open, and who owns it. |
| [Competitive landscape](COMPETITIVE-LANDSCAPE.md) | The global open-source and commercial field: Akaunting (BSL), Bigcapital, FrontAccounting, Odoo, ERPNext, Dolibarr, invoicing tools, personal ledgers, SaaS incumbents; the gaps they leave. | Positioning, licence choice, "why not just use X". |
| [Pakistan market](PAKISTAN-MARKET.md) | The three-tier Pakistani market, local vendors and prices, the FBR compliance forcing functions, who actually buys and on what device, and what it means for PHP Ledger. | Segment choice, feature priority, pricing, channel. |
| [Distribution plan](DISTRIBUTION-PLAN.md) | Channels ranked by fit for the accountant/software-house installer, the phase order (developer channels now; panels, Windows bundle and directories at the trader-complete release; partner programme after five real installs), measurement without phone-home, owners and costs. | Planning the distribution milestone (B2) or any listing, packaging or partner outreach. |
| [1.2 release plan](RELEASE-PLAN-1.2.md) | Where each of the seven 1.2 items stands in the code (20 September 2026), the 1.1.1 record corrections, the shape (1.1.2 patch, three signed previews, one stable with a media kit), milestones and calendar, the publication walk, gates, dated owner decisions, risks and scope. | Planning or doing any 1.2 work, cutting 1.1.2, or answering "when is 1.2 and what is in it". |
| [Owner questions dashboard and release marketing](OWNER-QUESTIONS-DASHBOARD-AND-RELEASE-MARKETING.md) | Six-question owner dashboard, implementation sequence, release acceptance gates, and the press/social pack required for every release. | Planning dashboard reporting, AR/AP, inventory, tax, customer documents, or release marketing. |
| [Multi-currency & consolidation](MULTI-CURRENCY.md) | Storage shape for FX/multi-currency and group consolidation; blocking items that must land before AR/AP schemas freeze; 15 worked test-suite cases. | Before AR/AP schema freeze; designing group reporting. |
| [AR/AP parties & vetting](AR-AP-PARTIES-AND-VETTING.md) | The party/contact master-data model (customer+vendor as one party), optional vetting workflow, and the A75 CRM connector contract. | Before AR/AP schema freeze; designing the FBR DI client's party inputs. |
| [ERPNext review](ERPNEXT-REVIEW.md) | What to adopt, adapt and avoid from ERPNext's accounting core, AR/AP, inventory and interface/platform, mapped against PHP Ledger's own decisions (cancellation/amendment model, open-item ledger, Urdu/RTL, deployment footprint). | Before building AR/AP or the Pakistan tax adapter; when deciding the cancellation/amendment model; when scoping settings, inventory or localisation. |
| [Research: vendors](research/pakistan-vendor-landscape.md) | Full vendor-by-vendor table with prices, FBR claims, adoption signals and weaknesses; international products as seen in Pakistan; candidates that could not be confirmed. | You need the detail behind a competitor line. |
| [Research: FBR regime](research/pakistan-fbr-einvoicing-regime.md) | Digital invoicing timeline and legal basis, Tier-1 POS, licensed-integrator requirements, the DI API (endpoints, auth, payload, scenarios), returns and withholding, small-trader income tax, barriers and opportunities. | Designing the Pakistan tax adapter, the FBR client, or the integrator application. |
| [Research: bookkeeping reality](research/pakistan-sme-bookkeeping-reality.md) | Khata apps, retail-tech graveyard, payments/fintech, the paper-and-munshi status quo, digitisation statistics, pricing sensitivity. | Deciding UX (phone vs desktop, Urdu, offline), buyer (accountant vs owner), and price. |

## How to use this folder

- Decisions are made in the register and recorded there with a date. Evidence documents are not edited to fit a decision; if the evidence changes, add a dated note.
- Research is point-in-time (15 September 2026). Star counts, prices, SRO status and app figures go stale; re-verify before quoting externally. Items marked UNVERIFIED were not confirmed by a primary or reputable secondary source.
- Nothing here is a claim about a PHP Ledger capability. The [README](../../README.md), [module roadmap](../MODULE-ROADMAP.md) and release receipts remain the only sources for what is built.
- Related existing research: [Pakistan tax rules](../tax/PAKISTAN.md), [Pakistan reporting formats](../accounting/PAKISTAN_REPORTING_RESEARCH.md), [UK/UAE reporting](../accounting/UK_UAE_REPORTING_RESEARCH.md), [licence review](../LICENSE_REVIEW.md), [funding](../FUNDING.md).

## Open verifications

Tracked in the decision register §E: PRAL IP-whitelisting at scale; SRO 288(I)/2026 status; small-shopkeeper turnover threshold; OraSoft vendor URL; Middle East e-invoicing dates.
