# PHP Ledger 1.4.6: the sample gallery, partner capital and readable statements

Prepared from the versioned release notes. Publication status, exact artifacts and completed verification gates are established by the [published release](https://github.com/phpledger/phpledger/releases) and its receipt under `docs/repository/`; this page describes what the release contains.

A patch release correcting three things the owner rejected in 1.4.5, found on review on 26 September 2026.

- **Business setup, Start stage:** the sample gallery lists all eleven sample companies on every copy, each with its logo, its story, its business kind and what its structure brings. A company already on the copy shows "Installed" and can be chosen; the others show "On phpledger.com" and, for an installation administrator, an install button that fetches the signed package and returns to Start. A bundled catalogue snapshot ships with the application, so the gallery needs nothing fetched to be complete, and the Packages page's Directory tab lists the same companies the same way.
- **Partnerships:** when the legal form is a partnership or an LLP, business setup creates a capital account and a drawings account for each partner, links each owner in the ownership register to their partner record, and posts the opening capital introduced split by the partners' ratio (equal shares when none was entered; a half-entered set is refused). The starter's Owner equity and Owner drawings accounts become the first partner's, so the balance sheet shows each partner's capital instead of one pooled account.
- **Statements:** the balance sheet, profit and loss and trial balance use the standard layout: an open group heading carries no amount, its accounts follow, and one "Total ..." line closes the group. The class row is not repeated inside a balance sheet or profit and loss section, and an account or group at zero is not listed. The balance sheet no longer repeats capital introduced and drawings as memo lines under equity; the owner-loan memo line appears only when a loan is outstanding.

The installer now pins the publisher public key that ships inside the package, and the person who installed the copy administers its packages whenever Start or Packages opens, so the one-click install works on a fresh copy.

No migration. Upgrade from 1.4.5 by replacing the files as usual. No media kit, per the patch-release policy. See [[Getting started|Getting-Started]] and the [[Roadmap]].
