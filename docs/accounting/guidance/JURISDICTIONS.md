# Jurisdiction research for the help bubbles

Research behind decision **B67**. Read [`README.md`](README.md) first: nothing here is interface
copy, and nothing here ships. A note reaches a reader only when it is transcribed into
`resources/guidance/jurisdictions/<CC>.php` with `status: verified`, a source and a check date.

**Access date for every source below: 21 September 2026**, unless a line says otherwise.

## How to read the labels

| Label | Means |
|---|---|
| **Primary** | The revenue authority, standards board, registry, statute or official gazette, fetched and read. |
| **Primary, index-sourced** | The right publisher, but the page body would not render (403, 522, JavaScript-only), so the text came from the site's own indexed summary. Weaker. Re-check before citing. |
| **Commentary** | A firm, vendor, news or think-tank source. Never sufficient on its own for a bubble. |
| **Not researched** | Nobody looked, or looking failed. Written out deliberately so a gap stays visible instead of being filled from memory. |

A note may be transcribed into the catalogue only from **Primary**. This is B67's rule: an unsourced
local claim is worse than none.

## Status

| Jurisdiction | Researched | Notes written to the catalogue |
|---|---|---|
| United States | yes | no |
| Euro area / EU | yes | no |
| United Kingdom | yes | no |
| United Arab Emirates | yes | no |
| Saudi Arabia | yes | no |
| Oman | yes | no |
| Malaysia | yes | no |
| Singapore | yes | no |
| Sri Lanka | yes | no |
| Nepal | delivered separately, not yet folded in | no |
| Pakistan | yes | no |
| India | partly: host unreachable, several items open | no |
| Bangladesh | yes | no |

---

# The five questions that drive product behaviour

These are the questions the bubbles have to answer differently per country, because the application
behaves differently depending on the answer.

1. **Free goods** — is a give-away a taxable supply, and on what value?
2. **Discount** — must it appear on the face of the invoice?
3. **Partner's salary** — an expense of the firm, or an appropriation of profit?
4. **Drawings** — is there a deemed distribution, a withholding, or a charge on the company?
5. **Terminology** — what is the tax called, and what is the document called?

## Question 1 is where the design is most at risk

The internal accounting review's first material finding is about free-goods valuation, and this
research says the design assumption behind it is wrong in most of these countries.

| Jurisdiction | Value the tax is charged on |
|---|---|
| United Kingdom | **cost** of the goods, with a £50 per person per 12 months let-out |
| European Union | **cost**, with samples and gifts of small value excluded outright |
| United States | the giver's **purchase price**, as use tax on the giver as consumer |
| Singapore | **open market value**, unless cost is at most $200 or no input tax was claimed |
| Malaysia | a transaction-value hierarchy under its own regulations |
| Sri Lanka | **open market value** |
| UAE, Saudi Arabia, Oman | thresholds per recipient per year, differing by an order of magnitude |
| Pakistan | **no general charge** on free supplies to unrelated persons; open market price between associates |
| India | not an output charge at all: **input tax credit is blocked** on gifts and free samples |
| Bangladesh | **fair market price**, with a Tk 50,000 sample allowance per fiscal year |

**Open market value is not the common default.** Three of the largest jurisdictions charge on cost
or purchase price. Any help text presenting open market value as the normal rule is wrong for most
readers, and the `free_goods_output_tax` policy needs its alternatives documented accordingly.

## Question 3 flips outright between jurisdictions

| Jurisdiction | Partner's salary |
|---|---|
| United Kingdom | appropriation, **expressly not a business expense** |
| United States | a guaranteed payment, **deducted** by the firm on the partnership return |
| Germany | added back under the income tax act, so no net deduction |
| Singapore | appropriation: deducted to reach divisible profit, then taxed on the partner |
| Sri Lanka | **not deductible** by statute; the firm is taxed at entity level |
| Pakistan | **not deductible**; no equivalent of India's partner-remuneration allowance |
| Bangladesh | **not deductible**, even where the general deduction conditions are met |
| India | **not researched** — the allowance and its limits could not be verified |

A single jurisdiction-neutral article on partner salary cannot be written. The shared concept must
stop at "it is a way of splitting profit between partners before the balance is shared", and the
local note has to carry the treatment.

---

# United States

**No federal sales tax or VAT.** The most recent primary statement of the negative obtained is a
2011 Government Accountability Office testimony saying the United States is the only member of the
Organisation for Economic Co-operation and Development without a value added tax. No 2026-dated
primary statement of the negative was obtainable, and nothing indicating one was enacted was found.
Treat it as *none located*, not as a sourced negative.

Sales tax is **state and local, and layered**. California is 7.25% statewide with district taxes of
0.10% to 2.00% on top; Texas is 6.25% state with local up to 2%, capped at 8.25% combined; Colorado
is 2.9%, from a primary publisher whose pages all returned 403. The states with no state-level
general sales tax are **commentary only** here, with partial corroboration from the Streamlined
Sales Tax Governing Board.

**Economic nexus** comes from *South Dakota v. Wayfair*, 585 U.S. 162 (2018), decided 21 June 2018,
which overruled **two** cases, *National Bellas Hess* (1967) and *Quill* (1992). The South Dakota
thresholds at issue were more than $100,000 delivered into the state **or** 200 or more separate
transactions annually.

**There is no federal rule prescribing the contents of a commercial invoice.** The tax authority's
own guidance says the law does not require any specific kind of records. The one federal
invoice-content rule found is procurement-only: the prompt payment clause defines a "proper invoice"
submitted to the federal government under a federal contract. State record rules do bite —
California requires electronic records to carry the vendor, invoice date, description, quantity,
price, **tax amount** and **tax status**, kept not less than four years.

**Framework** is US GAAP, meaning the Accounting Standards Codification, authoritative since periods
ending after 15 September 2009. Accounting Standards Updates are not themselves authoritative; they
amend the Codification. A separate board covers state and local government. Registrants file an
annual report within 60, 75 or 90 days depending on filer status. Registration is triggered by
listing, or by more than $10 million in assets plus a class of equity held of record by 2,000 or more
persons, or 500 or more who are not accredited investors. "A private company has no filing
obligation" is not how the regulator phrases it, and the audit requirement in the accounting
regulation is **not researched**.

**Owner withdrawals.** A sole proprietor's draw is not a wage and not deductible — the business
schedule's instructions say plainly not to include amounts paid to yourself. Self-employment tax
applies to net earnings, not to draws, at 15.3% where net earnings reach $400. Partners are not
employees and get no wage statement; a **guaranteed payment is deductible** by the firm and ordinary
income to the partner, while ordinary distributions reduce basis and are taxable only above it, with
no withholding. An S corporation must treat distributions as wages to the extent they are reasonable
compensation for services. A C corporation faces the constructive-distribution rules, including
below-market shareholder loans. **There is no US equivalent of the UK director's loan charge.**

**E-invoicing: no federal mandate located.** A payments coalition convened an exchange pilot in
September 2021 with central bank support, and in 2023 the framework became available to all
businesses under a new governing alliance. Participation is voluntary.

**Terminology for product copy: use "invoice" or "sales invoice", and "sales tax" or "sales and use
tax". Avoid "tax invoice" and "VAT".** That "tax invoice" is not US usage is commentary only, but it
is consistent with the primary evidence.

---

# Euro area and the European Union

**These are different sets.** The Union has 27 member states; the euro area has 21 since Bulgaria
adopted the euro on 1 January 2026. **VAT is a Union system, not a euro-area one.**

The split of competence decides which questions have a Union-level answer at all. VAT is harmonised
by treaty. **Direct taxation is not**: income tax, distributions, business forms and drawings stay
national. So free goods, discounts and invoice contents have real Union answers, and partner salary
and drawings do not.

**Rates.** The directive fixes the standard rate at **at least 15%**, with no maximum, and reduced
rates not below 5%. The band in force runs **17% in Luxembourg to 27% in Hungary**, both verified
against the national authorities. Individual member-state effective dates are **not researched**.

**Invoice contents**: date of issue; a unique sequential number; the supplier's VAT identification
number; the customer's where liable; both full names and addresses; description and quantity; the
transaction date if different; **unit price exclusive of tax, discounts or rebates**; the rates
applied; the VAT payable; and a breakdown by rate or exemption. A simplified invoice needs only the
date, the supplier's VAT number, the type of supply and the VAT payable or the means to calculate
it. The verbatim numbered article text could not be rendered from the official repository; the
substance above is the Commission's own restatement, which is primary but not statutory wording.

**Financial statements** comprise, as a minimum, the balance sheet, the profit and loss account and
the notes. Union-endorsed international standards apply to the consolidated accounts of companies
listed on a regulated market. Publication is required within at most **12 months** of the balance
sheet date, through the national register. An audit is required for public-interest entities and
medium and large undertakings; **small undertakings are exempt**, because audit is a significant
administrative burden for that category.

Size thresholds, raised for financial years beginning on or after 1 January 2024 as a 25% inflation
adjustment:

| Category | Balance sheet total | Net turnover | Employees |
|---|---|---|---|
| Micro | EUR 450,000 | EUR 900,000 | 10 |
| Small | EUR 5,000,000 | EUR 10,000,000 | 50 |
| Medium | EUR 25,000,000 | EUR 50,000,000 | 250 |

**Free goods** are a deemed supply where the VAT was wholly or partly deductible, **except** that
goods applied as samples or as gifts of small value are not. **Discounts** are excluded from the
taxable amount where granted at the time of supply or before the invoice issues; the test is the
grant, not the appearance, but the unit price on the invoice must be stated net of them.

## VAT in the Digital Age, and the national e-invoicing mandates

The directive was adopted 11 March 2025 and came into force 14 April 2025.

| Date | What changes |
|---|---|
| 14 April 2025 | A member state may **require domestic e-invoicing without a Council derogation**, and may disapply the recipient-acceptance requirement. The most consequential near-term change. |
| 1 January 2027 | First tranche of measures; transposition by 31 December 2026. |
| 1 July 2028 | Platform-economy deemed-supplier rules, single VAT registration, mandatory reverse charge for non-established suppliers. |
| 1 July 2030 | **Digital reporting and intra-Union business-to-business e-invoicing.** Invoices must be issued as electronic invoices complying with the European standard. |
| 1 January 2035 | Convergence deadline, available only as a derogation to states that already had domestic real-time reporting on 1 January 2024. Cross-border reporting still applies from 1 July 2030 for everyone. |

National mandates in force or scheduled:

- **Italy** — mandatory since 1 January 2019, no postponement. Domestic invoices between established
  parties are issued **exclusively** through the national exchange system. Technical specification
  v1.9.1 was published 31 March 2026 and is usable from 15 May 2026.
- **France** — live. From **1 September 2026** every taxable business must be able to **receive**
  e-invoices, and large enterprises and mid-caps must issue and report. Smaller businesses follow on
  **1 September 2027**.
- **Germany** — the **receipt** obligation has applied since 1 January 2025 with no transitional
  relief. The **issuing** obligation bites **1 January 2027**, extended to 1 January 2028 for issuers
  whose prior-year turnover was at most EUR 800,000. Small-amount invoices up to EUR 250 and
  transport tickets are excluded.
- **Poland** — 1 February 2026 for large enterprises; 1 April 2026 for all others **except** those
  whose monthly invoiced sales do not exceed PLN 10,000; 1 January 2027 for that smallest group.
- **Belgium** — in force since 1 January 2026. An established taxable person must issue a
  **structured** e-invoice, transmitted over the European network unless the parties agree
  otherwise. Business-to-consumer is out of scope, but such a business must still be able to receive.
- **Spain — two regimes, routinely conflated, and the single most error-prone item in this file.**
  The billing-software and records regime governs **software**, not business-to-business
  e-invoicing; after two postponements it starts **1 January 2027** for corporate income tax payers
  and 1 July 2027 for everyone else. The business-to-business e-invoicing regime has its enabling
  decree, in force 20 April 2026, **but the clock runs from a ministerial order that had not been
  published as at 21 September 2026** — twelve months after it for businesses above EUR 8 million
  turnover, twenty-four for the rest. **The Spanish start dates are therefore not yet fixed and must
  not be stated.** The widely quoted October 2027 and October 2028 dates come from a draft.

---

# United Kingdom

**VAT at 20%**, reduced 5%, zero 0%. The standard rate rose to 20% on 4 January 2011. The
registration threshold is £90,000 of taxable turnover; the effective date of that figure is **not
researched**.

**Invoice contents** are prescribed by regulation: a sequential number uniquely identifying the
document; the time of supply; the date of issue; the supplier's name, address and registration
number; the customer's name and address; a description sufficient to identify what was supplied; per
description the quantity or extent, the rate and the amount payable excluding VAT; the gross total
excluding VAT; **the rate of any cash discount offered**; the total VAT chargeable expressed in
sterling; the unit price; and the margin-scheme, reverse-charge or free-zone reference where one
applies.

**The United Kingdom's requirement is unusually specific: the invoice must state the *rate* of any
cash discount offered**, not merely the discount itself. Several other jurisdictions require the
discount on the invoice in some form, so this is a difference of precision rather than a difference
in kind. On value, an unconditional discount reduces the tax value to the discounted amount;
a prompt-payment discount is based on the amount actually paid, but where VAT must be accounted for
before take-up is known, it is declared on the undiscounted price; a contingent discount leaves the
tax value at the full amount and is corrected later by credit note.

**Free goods** are charged on **cost**, not open market value, and there is a real let-out: no VAT is
due on business gifts to the same person so long as the total cost of all gifts to that person does
not exceed **£50 excluding VAT in any 12-month period**. Free samples meeting the definition are not
liable at all.

**Financial statements.** Directors must prepare accounts for each financial year, comprising a
balance sheet and a profit and loss account, each giving a true and fair view. The framework is UK
GAAP from the Financial Reporting Council.

> **Live change for any 2026 product.** The periodic review amendments are effective for accounting
> periods **beginning on or after 1 January 2026**: a rewritten leases section that puts most leases
> on the balance sheet as a right-of-use asset and a lease liability and removes the
> operating/finance distinction; a five-step revenue model; and a new fair-value measurement
> section.

**Small company** means meeting two or more of: turnover not more than **£15 million**; balance sheet
total not more than **£7.5 million**; not more than **50 employees** — raised from £10.2m and £5.1m
with effect from 6 April 2025. A small company is **exempt from audit**, subject to the members'
right to require one and the excluded-company rules.

**Coming, and the date is often misquoted: from 1 April 2028**, not 2027, small companies must
deliver a profit and loss account to the registry, abridged accounts are removed, and all filings
must be made by commercial software in inline XBRL.

**Owner withdrawals.** A sole trader's or partner's drawings attract no deemed distribution and no
withholding; they are simply not deductible, because a deduction needs an expense incurred wholly and
exclusively for the trade. A partner's salary is an **appropriation and expressly not a business
expense** — it is a share of net profit, normally shown as an extension of the profit and loss
account. For a company, money comes out as salary, dividends or a director's loan.

> **The director's loan charge rate changed on 6 April 2026 to 35.75%**, because the statute ties it
> to the dividend upper rate. **The consumer-facing government page still says 33.75% and is stale.**
> Cite the tax authority's company taxation manual, not that page. Relief is available when the loan
> is repaid, released or written off, immediately if repaid within nine months of the period end; a
> loan over £10,000 is also a benefit in kind.

**E-invoicing: announced, not yet designed.** The consultation response published 26 November 2025
says mandatory e-invoicing for all VAT invoices **from 2029**, covering domestic business-to-business
and business-to-government, with a roadmap at Budget 2026. Two corrections to what circulates: the
primary source says "from 2029", so **"April 2029" is commentary**; and **no technical standard has
been chosen**, so vendor claims that the European network is confirmed are contradicted by the
primary source. Real-time reporting is explicitly deferred until e-invoicing is well established.

Today's operative regime is **Making Tax Digital for VAT**: digital records in functional compatible
software and filing through software, for all VAT-registered businesses from their first VAT period
starting on or after 1 April 2022. **Digital links are mandatory and cut-and-paste is not an
acceptable link.**

**Terminology: the tax is VAT and the document is a "VAT invoice"**, with "simplified" and "modified"
as the defined variants. Do not use "sales tax" or "tax invoice" in United Kingdom copy.

**One more currency note.** A bill would have replaced the Financial Reporting Council with a new
regulator. On 20 January 2026 the government said it will not be consulting on audit reform
legislation, so **the existing council remains the standard-setter and regulator**.

---

# United Arab Emirates, Saudi Arabia and Oman

Treated as first-class under B67. Three findings matter more than the rest.

**Arabic is mandatory on the invoice in Saudi Arabia and Oman, and not in the United Arab
Emirates.** One invoice template cannot serve all three.

**Free-goods thresholds differ by an order of magnitude**, per recipient per year, each with its own
annual ceiling: **500 dirhams** in the United Arab Emirates, **200 riyals** in Saudi Arabia, **50
rials** in Oman. This bears directly on the free-goods feature.

**Showing a discount on the face of the invoice is a condition of the relief in Oman**, but merely an
invoicing particular in the United Arab Emirates and Saudi Arabia.

Two premises given to the research were corrected by it: the United Arab Emirates small-business
relief runs to **31 December 2029**, not 2026; and Oman's tax-invoice article is **144**, not 145.

E-invoicing: Saudi Arabia runs its mandate in waves; the United Arab Emirates is building on the
European network through its domestic tax exchange; **Oman currently has two conflicting official
timelines in circulation**, so no Omani date should be stated without re-checking.

---

# Malaysia

**Not a VAT.** Two separate single-stage taxes under two Acts, with no credit chain. **Sales tax** is
charged by registered manufacturers and on imports, at 5% or 10% on discretionary and non-essential
goods, unchanged for essential goods, effective 1 July 2025, with penalties held off until
31 December 2025. **Service tax** rose from six to **eight per cent on 1 March 2024**, keeping **6%**
for food and beverage, telecommunications, parking and logistics; the 1 July 2025 expansion added
leasing, construction, financial services, private healthcare, education and beauty services.

**The statutes say "invoice", not "tax invoice"** — "tax invoice" is the goods and services tax era
term, abolished in 2018. An invoice must carry the prescribed particulars in Malay or English.
**Both the sales tax and the service tax regulations mandate "any discount offered" on the invoice.**
Credit and debit notes must carry those words in a prominent place.

**Framework.** A private entity applies either the private entities reporting standard in its
entirety or the full financial reporting standards; everyone else applies the full standards. **The
2025 edition of the private entities standard applies for annual periods beginning on or after
1 January 2027**, and the 2016 edition is withdrawn from that date.

Accounts are lodged with the companies registry within 30 days of circulation. Audit exemption rises
in three phases, needing at least two of three criteria met in the current and the previous two
financial years:

| | FY from 1 Jan 2025 | FY from 1 Jan 2026 | FY from 1 Jan 2027 |
|---|---|---|---|
| Turnover | RM1,000,000 | RM2,000,000 | RM3,000,000 |
| Assets | RM1,000,000 | RM2,000,000 | RM3,000,000 |
| Employees | 10 | 20 | 30 |

**E-invoicing** is mandatory on a turnover-banded timetable fixed by reference to the 2022 accounts,
ending with **up to RM5 million from 1 January 2026**. New businesses that commenced between 2023 and
2025 with turnover at or above RM3,000,000 join on 1 July 2026.

> **The exemption threshold is RM3,000,000**, per the official guideline published 30 August 2026,
> and it does not apply where the taxpayer has a non-individual shareholder, holding company or
> related company with turnover at or above RM3,000,000. Widely repeated 2025-26 commentary says
> RM1 million, from a December 2025 cabinet decision. **Use the guideline figure.** The current
> guideline also contains no grace period, only a discretion for when the system itself is down.

**Free goods are taxed**: goods disposed of otherwise than by sale are brought in, valued by a
transaction-value hierarchy; a taxable service provided free must be declared and the tax paid.

Two Malaysian items are genuinely weak and **must not be published as they stand**: the partner's
salary tax treatment is **not researched**, because the revenue portal is down after a migration; and
the 2% dividend tax on individual dividend income above RM100,000 is **commentary only**.

---

# Singapore

**Goods and services tax at 9%** since 1 January 2024, having gone 7% to 8% on 1 January 2023 and 8%
to 9% a year later. Exports and international services are zero-rated. The **registration threshold
is not researched** — the $1 million figure in the general guide is the cash accounting scheme limit,
which is a different thing.

**Invoice contents**: the words **"tax invoice" in a prominent place**; an identifying number; the
date; the supplier's name, address and registration number; the customer's name and address; a
description sufficient to identify the supply and its type; per description the quantity or extent
and the amount excluding tax; **any cash discount offered**; the total excluding tax with the rate
and the tax as a separate amount; the total including tax; and a breakdown of exempt, zero-rated or
other supplies. Issue within 30 days of the time of supply.

A **simplified tax invoice** is available where the tax-inclusive total does not exceed **$1,000**,
carrying the supplier's details, a number, the date, a description, the total including tax and the
words "Price Payable includes GST". Above $1,000 no particular may be omitted. Only one original may
issue; reissues are marked "Copy" or "Duplicate". Foreign-currency invoices must also show the
tax-exclusive total, the tax and the tax-inclusive total in Singapore dollars.

**Free goods** are a deemed supply at open market value, **unless** the cost of the gift is not more
than **$200 excluding tax**, **or** no input tax was claimed. Those are alternatives, so claiming no
input tax defeats the charge even above $200.

**Audit exemption** for a small company: private throughout the year, plus two of revenue at most
$10 million, assets at most $10 million, and at most 50 full-time employees, for the previous two
consecutive financial years. **Audit exemption does not remove the filing obligation** — accounts
still go to the registry, in XBRL unless exempted.

**Partner's salary is an appropriation**: divisible profit is the adjusted profit less partners'
salaries, allowances, bonuses, pension contributions, interest on capital and expenses paid on their
behalf; the salary is then added back to that partner's own share. The partnership pays no tax
itself, and partners are assessed even where the divisible profit was retained.

**Drawings** carry no deemed distribution and no withholding: under the one-tier system, dividends
paid by a resident company are not taxable in the shareholder's hands, because the tax paid by the
company is final.

**E-invoicing** is built on the European network and phases in by total annual supplies:

| From | Who |
|---|---|
| 1 Nov 2025 | Newly incorporated companies registering for the tax voluntarily |
| 1 Apr 2026 | All new voluntary registrants |
| 1 Apr 2028 | All new compulsory registrants, and existing businesses with supplies at most $200,000 |
| 1 Apr 2029 | Existing businesses at most $1,000,000 |
| 1 Apr 2030 | Existing businesses at most $4,000,000 |
| 1 Apr 2031 | Existing businesses above $4,000,000 |

Invoice data covers sales invoices, tax invoices, simplified tax invoices, serially numbered
receipts, and debit and credit notes. It excludes sales orders, pro-forma invoices and statements of
account.

---

# Sri Lanka

**VAT at 18%** since 1 January 2024 and still current. Financial services move to 20.5% for taxable
periods commencing on or after 1 July 2026. The **registration threshold stays at Rs 15 million per
quarter and Rs 60 million per twelve months**; importers and exporters register regardless.

> A widely repeated Rs 36 million threshold for April 2026 was **abandoned**. The revenue
> department says so directly.

The social security contribution levy is a **separate levy, not part of VAT**, at 2.5% of liable
turnover above Rs 15,000,000 a quarter.

**Invoice contents** come in two layers. The Act requires the supplier's name, address and
registration number; the recipient's name and address; the date of issue and a serial number; the
date of supply and a description; the quantity or volume; the value, the tax charged and the
consideration; and **the words "TAX INVOICE" in a conspicuous place**. A non-conforming invoice is
not a valid tax invoice.

> **A gazette effective 1 July 2026 adds hard constraints on numbering that any invoice-numbering
> feature must respect**: both parties' nine-digit taxpayer identification numbers; a **mandatory
> serial format `YYMMM_QQQQ_XXXXX`**, at most 40 characters, with the numeric serial continuing month
> to month; dates in MM/DD/YYYY; values in rupees to two decimals showing net, tax and the inclusive
> total; and the rule that a tax invoice must contain **only** goods or services subject to the tax.

**Free goods** are valued at open market value through several routes: non-money consideration,
consideration that may not be less than open market value, a supply below that value to an
unregistered person, goods appropriated for personal or non-taxable use, and supplies for no value or
between associated persons.

**Discount** has **no statutory display requirement** — the word does not appear in the Act's
consolidation. But the gazette requires the stated value to be net of tax, so the tax is computed on
the net; a discount to an unregistered customer that falls below open market value is overridden; and
post-invoice reductions go through a tax credit note, with a six-month limit for input tax
adjustment.

**Partnerships are taxed at entity level**, separately from their partners, at 0% up to
Rs 1,000,000 and 6% above, with 8% withheld from each partner's share. **A partner's salary is not
deductible** by statute; it is taken into account in determining the partner's share instead. The
accounting treatment of capital and drawings is **not researched**.

Two findings contradict common belief and are worth carrying into the help content: **a private
limited company does not routinely file accounts** — only companies that are not private companies
must deliver them to the registrar, though the registrar may require a private company to do so on
notice; and **there is no small-company audit exemption at all**, because an auditor must be
appointed at every annual general meeting. A full-text search of the Companies Act for "small
company" returned nothing.

**No clearance-model e-invoicing mandate exists.** What exists is the invoice-format mandate above,
which is a format rule and not transmission to the revenue department, and a **legislated but dormant
point-of-sale requirement** whose specifications had not been published as at 21 September 2026.

The professional institute's own establishing statute **could not be verified** and must be
re-checked before publication.

---

# Nepal

Delivered as a separate piece of research and **not yet folded into this file**. Until it is, every
Nepali line — the tax rate, the current finance act, the invoice particulars, the reporting framework
and filing, the professional body and the central billing monitoring system — is **not researched**
for the purposes of this document, and no Nepali note may be transcribed into the catalogue.

---

# Pakistan

The primary jurisdiction. Statutes were read from the consolidated official texts, not from summaries.

**Sales tax on goods is federal, at 18%**, raised from seventeen by a supplementary act in February
2023. On top of that sits a **further tax of 4%** where a taxable supply is made to a person who has
no registration number or is not an active taxpayer. Zero-rating covers exports and specified goods;
there are exemption, reduced-rate, retail-price and e-commerce schedules, and the federal government
may notify other rates.

> **The revenue authority's own legacy page still says 16%, with some items at 18.5% or 21%. It is out
> of date and must not be cited.** The Act governs.

**Tax on services is provincial**, and the rates genuinely differ:

| Authority | Standard rate | Telecommunications |
|---|---|---|
| Punjab | 16% | 19.5% |
| Sindh | 15% | 19.5% |
| Balochistan | 15% | 19.5% |
| Khyber Pakhtunkhwa | **not verified** | — |

Khyber Pakhtunkhwa publishes rates service by service rather than a headline rate, and the most
recent schedule linked from its own site is the 2020-21 one, so a 15% standard rate there is
**commentary only**. The Islamabad Capital Territory position is **not researched**.

**Invoice contents** come from section 23 of the Sales Tax Act, as substituted by the Finance Act
2026. A registered person making a taxable **as well as an exempt** supply must issue a tax invoice,
including an advance receipt invoice, **bearing a verifiable and unique invoice number issued by the
revenue authority**, serially numbered, at the time of supply, **in Urdu or English**, carrying:
the supplier's name, address and registration number; the recipient's name, address and registration
number, and for supplies by a manufacturer or importer to an unregistered distributor that
distributor's national identity or tax number; the date of issue; the description and quantity, with
count, denier and construction for textile yarn and fabric; **the value excluding tax**; **the amount
of tax**; and **the value including tax**.

Not more than one tax invoice may issue per taxable supply. Where goods are transported, the invoice
must be generated and linked to the **e-Bilty**, the digital transport document generated through the
cargo tracking system. The unique-invoice-number condition applies from a date the authority
notifies.

**The digital invoice payload** is specified by the authority's own technical documentation. Per line
it carries the tariff code, description, rate, unit of measure, quantity, values excluding tax, fixed
or retail price where applicable, tax applicable, tax withheld at source, extra tax, further tax, the
notification reference, federal excise payable, **a discount field**, and the sale type. The
authority returns an invoice number. **The digital invoicing logo and a version 2.0 QR code of one
inch square must be printed on every invoice** issued by an integrated person.

**Financial statements** under the Companies Act 2017 comprise the statement of financial position,
the statement of profit or loss and other comprehensive income, the statement of changes in equity,
the statement of cash flows, the notes, comparatives, and anything else prescribed. They must be laid
before the annual general meeting **within 120 days** of the year end, extendable by up to 30 days.
**An audit is required, except for a private company with paid-up capital not exceeding Rs 1 million.**

Classification decides the framework, and is based on the previous year's audited statements,
changing only where the company falls outside the criteria for two consecutive years:

| Class | Broad criteria | Framework |
|---|---|---|
| Public interest | listed, public sector, financial institutions, insurers, exchanges, banks, all sugar producers | IFRS |
| Large | unlisted with paid-up capital at least Rs 200m, or turnover at least Rs 1bn, or more than 750 employees | IFRS |
| Medium | unlisted public below those; private above Rs 10m paid-up and Rs 100m turnover, 250 to 750 employees | Revised standards for small and sized entities |
| Small | private with paid-up capital at most Rs 10m, turnover at most Rs 100m, at most 250 employees | Revised standards for small and sized entities |

Filing with the registrar is **within 30 days** of adoption for a listed company and **within 15 days**
for any other, and **does not apply at all to a private company with paid-up capital not exceeding
Rs 10 million**.

**Partner's salary is an appropriation and never an expense.** The Income Tax Ordinance disallows any
profit on debt, brokerage, commission, salary or other remuneration paid by an association of persons
to a member. **Pakistan has no equivalent of India's allowance for partner remuneration.** This is the
sharpest contrast in the region, and it drives how the application must post such a payment: against
the partner's account, not to an expense.

**Drawings.** An association of persons is taxed separately from its members, and a member's share
out of taxed income is exempt in their hands, so a partner's drawing of a taxed share is an equity
withdrawal and not a separate taxable event. A **private company is different**: a payment by way of
**advance or loan to a shareholder**, or on their behalf or for their individual benefit, is a
**deemed dividend to the extent of accumulated profits**, with withholding on dividends at 15% in the
general case.

**Free goods.** Pakistan has **no general open-market-value charge on free supplies to unrelated
persons**. What it has is narrower and must be described precisely: putting goods produced in the
course of a taxable activity to private, business or non-business use other than making a taxable
supply is itself a supply; consideration in kind is valued at open market price; and **where supplier
and recipient are associated persons and the supply is for no consideration or below open market
price, the value is the open market price**. There is no express block on input tax for free samples
of the kind India has, only the general rule that input tax needs a taxable-supply purpose.

**Discount must be shown on the invoice, and there are two conditions.** For trade discounts the value
is the discounted price excluding tax, **provided the tax invoice shows the discounted price and the
related tax**, and **provided the discount allowed conforms to normal business practice**. Both limbs
matter, and the application's discount posting has to satisfy the first one on the printed document.

**E-invoicing is live and phased.** A 2025 notification substituted the licensing and integration
chapter of the Sales Tax Rules; the state automation company acts as a licensed integrator and
provides integration **free of charge** plus free downloadable software. A superseding notification of
1 August 2025 set the timetable: **all public companies, all other companies with turnover above
Rs 1 billion, and all importers register by 10 August 2025, test by 25 August and issue electronic
invoices from 1 September 2025**, with later rows stepping down by turnover through to December 2025
and a catch-all row registering on 10 November 2025. The full table, second pass:

| Category | Register | Test | Issue |
|---|---|---|---|
| Public companies; other companies above Rs 1bn turnover; all importers | 10 Aug 2025 | 25 Aug 2025 | 1 Sep 2025 |
| Companies above Rs 100m and up to Rs 1bn; individuals and associations above Rs 100m | 10 Sep 2025 | 30 Sep 2025 | 1 Oct 2025 |
| Companies up to Rs 100m | 10 Oct 2025 | 30 Oct 2025 | 1 Nov 2025 |
| All other registered persons | 10 Nov 2025 | 30 Nov 2025 | 1 Dec 2025 |

> **Two data-quality warnings.** The scan of the later rows of that notification interleaves its
> columns and must be re-checked against a clean copy before any of those dates is published. And a
> further notification said by commentary to have been issued on 24 September 2025 **could not be
> found** on the authority's own listing or the tax bar's index. **Do not publish it.**

A general order of 30 March 2026 allows a registered person to engage **more than one** licensed
integrator, and permits cancelling, deleting or editing a valid electronic invoice **only within 72
hours** of generation, and after that only with the prior approval of the Commissioner. **That 72-hour
window is a direct constraint on any correction workflow.**

Penalties are severe. Failure to integrate draws up to Rs 1 million, then up to Rs 5 million if it
continues beyond a month, **and the premises are liable to be sealed**. A separate escalating scale
runs from Rs 500,000 to Rs 3 million across four defaults, with a waiver of the first penalty if the
retailer integrates before the second is imposed.

> **The authority's own digital invoicing FAQ page is stale**: it still describes the April 2025
> notification and its June and July 2025 deadlines, although that notification was expressly
> superseded on 1 August 2025.

**Terminology.** "Sales tax" federally on goods, "sales tax on services" provincially. The document is
a **sales tax invoice** or **tax invoice**, and from the Finance Act 2026 it bears a verifiable and
unique authority-issued invoice number. Users expect the national tax number, the sales tax
registration number, the national identity number, "further tax", "e-Bilty", "Tier-1 retailer",
"licensed integrator" and the active taxpayer list.

---

# India

**Network limitation.** The Indian government tax hosts were unreachable from the research
environment, confirmed by re-testing. Several India items below are therefore **not researched**
because of that, not because nobody looked. Closing them needs a differently routed connection.

**The rate rationalisation took effect on 22 September 2025.** From that date the structure is **nil,
5%, 18% and 40%**, plus retained 0.25%, 1.5% and 3% for diamonds, precious stones and bullion, and a
residual 28% schedule. **There is no 12% slab.** The 40% band covers sugared and caffeinated
beverages, most motor cars, larger hybrids, motorcycles over 350cc, personal aircraft, yachts,
revolvers and pistols, smoking pipes, and specified actionable claims including betting, casinos,
gambling, horse racing, lottery and online money gaming.

**A second change took effect on 1 February 2026**: biris moved to 18%; pan masala, unmanufactured
tobacco, cigars and cigarettes, other manufactured tobacco and nicotine inhalation products moved to
40%; and **the 28% schedule was omitted entirely**. **Compensation cess was fully withdrawn from the
same date.**

So the position as at September 2026 is **two main slabs at 5% and 18%, a 40% demerit rate, and the
gem and bullion rates**. Council newsletters are published through April 2026 and show no further
slab change; May to August 2026 are not published, so that window is **not researched**.

**Invoice contents** come from rule 46 of the central rules. The particulars that constrain the
application most are: **a consecutive serial number not exceeding sixteen characters**, in one or
multiple series, of letters, numerals, hyphen and slash, **unique for a financial year**; the
recipient's registration number if registered; their name, address, **state and state code** where
unregistered and the supply is **Rs 50,000 or more**, or below that if they ask; the tariff code; the
quantity and unit code; the total value; **the taxable value taking into account discount or abatement
if any**; the rate and amount of each tax head; **the place of supply with the state name** for
inter-state supplies; whether tax is payable on reverse charge; a signature or digital signature; and
**a QR code with the embedded invoice reference number** where the invoice was issued under the
e-invoicing rule.

A signature is not required for an electronic invoice issued under the information technology
legislation. **An invoice issued in any other manner by a person required to use the reference-number
route is not an invoice at all.**

**Free goods.** Schedule I treats as a supply, even without consideration: permanent transfer or
disposal of business assets **where input tax credit was availed**; supply between related or distinct
persons in the course of business, **except gifts not exceeding Rs 50,000 in a financial year from an
employer to an employee**; principal-to-agent and agent-to-principal supplies; and imports of services
from a related person. Valuation falls to open market value, then like kind and quality, then the
residual rules, with an option to value at **90% of the price the recipient charges an unrelated
customer** where the goods are for further supply as such.

**Crucially, input tax credit is blocked on goods disposed of by way of gift or free samples.** That
is a different mechanism from charging output tax on the give-away, and the help text must not
conflate them.

**Discount** is excluded from value if given **before or at the time of supply and duly recorded in
the invoice**; or after the supply, if established by an agreement entered into at or before the time
of supply, **specifically linked to relevant invoices**, and the attributable input tax credit has
been reversed by the recipient.

> **A pending change to flag in product copy.** That second limb has been **substituted by the Finance
> Act 2026 but is not yet notified**, so it is enacted and dormant. The new test will be that a
> **credit note has been issued by the supplier** and the attributable credit reversed. Until
> notification, the agreement-plus-reversal test is the operative law.

Related and in force: a substituted credit-note provision from 1 October 2025 denies a reduction in
output tax where the recipient reverses credit and the incidence has not been passed on. A September
2025 circular on post-sale discounts holds that a recipient need **not** reverse credit for purely
financial or commercial credit notes, and that post-sale discounts to dealers are **not** consideration
for a separate service unless the agreement explicitly says so with a defined consideration. An
October 2025 circular **withdrew** the earlier evidence-of-compliance circular. **Circular withdrawal
is a live risk in this area**, so no Indian circular should be cited without re-checking.

**E-way bills** are required for movement of goods of **consignment value exceeding Rs 50,000**, with
the details furnished before movement. Principal-to-job-worker inter-state movement requires one
**irrespective of value**.

**Not researched, all blocked by the unreachable hosts**: the statutory financial statements regime,
the reporting-standard applicability thresholds, the audit and tax-audit thresholds, the business
forms and their capital and drawings treatment, **the partner remuneration limits**, the withholding
on partner remuneration, the current e-invoicing turnover threshold, and the reporting window for
obtaining an invoice reference number. Commentary puts the e-invoicing threshold at Rs 5 crore and
the reporting window at 30 days for larger taxpayers, but **neither is confirmed and neither may be
published**.

**Terminology.** The tax is **GST**, split into central, state, union territory and integrated tax,
and the rules require those exact labels on the invoice. **Tax invoice** is the statutory name. The
reference number obtained from the portal produces what practitioners call an **e-invoice**, and the
QR code is the one with that number embedded. Practitioners distinguish a statutory **credit note**
from a **commercial or financial credit note**, and the two are treated differently.

---

# Bangladesh

**Standard VAT is 15%** and is unchanged for the 2026-27 year. The government may fix a **reduced rate
or a specific amount of tax** for goods and services in the Third Schedule, and a registered person
may elect to pay at 15% instead.

> **The reduced-rate values in force could not be read.** The Third Schedule is published only as a
> Bangla document whose font maps both letters and digits to unusable glyphs, and the reduced rates
> are set by the annual finance act rather than by a separate notification. The mechanism is
> confirmed; **the percentages are not researched**. The same applies to the supplementary duty rates
> in the Second Schedule.

**Turnover tax changed on 1 July 2026.** The government may now fix a **sector-wise specific amount by
region, not exceeding Tk 200,000**; until it does, an enlisted person pays **4% of turnover**. An
enlisted person may take no input credit and make no decreasing adjustment.

> **The authority's own English compliance guide still prints 3% and is stale against the statute.**

**Both thresholds were cut, with retrospective effect from 9 January 2025**: registration at **Tk 50
lakh** per twelve months, down from Tk 3 crore; and turnover tax enlistment at **Tk 30 lakh**, down
from Tk 50 lakh. Certain persons must register regardless of turnover, including anyone supplying
against a tender, contract or work order.

**From 1 July 2026 a business identification number or proof of enlistment is required** for opening
or operating a bank account, taking a loan, **renewing a trade licence**, opening a mobile financial
services merchant account, taking or renewing trade-body membership, taking an electricity or gas
connection, and registering a vehicle in the business's name.

**Returns moved from monthly to quarterly on 1 July 2026**: filed within **15 days of the end of every
three tax periods**, or 20 days for government bodies, banks, insurers and nil filers.

**Invoice contents** are in the rules, not the Act. The invoice is **form Mushak-6.3**, issued against
each supply, carrying: the actual date and time of issuance; the supplier's name, address and
business identification number; **the purchaser's name, address and identification number where the
value of the supply exceeds Tk 25,000**; the description, amount, date and time of supply and the
nature and number of the transport; the value without VAT; the rate; the amount of VAT; the sum of the
two; **fiscal-year-wise serial numbering**; and separate serially numbered invoices per place of
business where there is more than one. At least **two copies**, and **the original must accompany the
vehicle while the goods are transported**.

> **A correction to the checklist that circulates: supplementary duty and a signature are NOT among
> the mandatory particulars.** Do not state that they are. A defective or incomplete invoice is not
> documentary evidence for credit or adjustment.

A registered person may use their **own format**, provided the form's name appears, all prescribed
information is included, and the minimum copies are issued. That matters for the application's print
templates: a compliant Bangladeshi invoice does not have to look like the official form.

**Free goods are charged at fair market price**, and this is the single most dangerous item in the
whole file.

> **The widely circulated 2013 unofficial English translation says the value of a taxable supply
> without consideration "shall be zero". The current Bangla statute says the opposite**: unless
> otherwise determined, the value of a taxable supply without consideration is its **fair market price
> less the tax fraction**. Anyone building from the old translation will under-charge tax on every
> free issue.

A supply to an **associate** is valued at fair market price less the tax fraction where it is without
consideration or below fair market price **and** the associate would not be entitled to full input
credit. There is a sample allowance: a registered person may make supplies of **at most Tk 50,000 as
samples in a fiscal year**, valued at fair market price, raised from Tk 20,000 with effect from
1 July 2025.

**Discount has no invoice-display requirement**, and this must be said plainly rather than assumed.
The mechanism sits in the definition of consideration, which excludes **any price discount given at
the time of the supply**. The statutory test is **timing**, not presentation, and the invoice form has
no discount field at all. The nearest adjacent obligation is that preserved records must include
records relating to the discount or rebate allowed, so a discount must be **evidenced**, which in
practice means the invoice or an accompanying document.

**Financial statements.** A balance sheet and a profit and loss account are laid before the annual
general meeting, made up to a date within **nine months** before it, or twelve where there are
interests outside Bangladesh. **They must be audited.** The financial year may be shorter or longer
than a calendar year but not more than fifteen months. Filing with the registrar is **within thirty
days** of the meeting, in three copies; a **private company files the balance sheet and the profit and
loss account separately**, and for a private company that is not a subsidiary of a public company,
**nobody but a member may inspect or copy the profit and loss account**.

**The standard-setter is the Financial Reporting Council, not the professional institute.** The
Council makes reporting standards consistent with international standards and auditing standards
consistent with the international auditing standards, for public interest entities, and **may make a
separate simplified framework for small and medium entities**. Standards adopted by the professional
body remain in force as if made under the Act until replaced. A public interest entity **may not file**
statements that were not prepared under the Council's standards. The Council's own page shows
reporting standards at **version 2026**.

**Partner's salary is not deductible.** No deduction is allowed for any interest, salary, commission
or remuneration paid by a firm or an association of persons to a partner or member, and that rule
applies **even where the general deduction conditions are satisfied**. It is an appropriation.

**Drawings.** A payment by a company out of accumulated profits as an **advance or loan to a
shareholder**, or on their behalf or for their individual benefit, is a deemed dividend. Two changes
matter: the word "private" was **deleted** with effect from 1 July 2024, so it is no longer confined to
private companies; and the words "natural person" were **inserted** with effect from 1 July 2026, so
from that date it bites **only on loans to natural-person shareholders**. Withholding on dividends to
residents from 1 July 2026 is **15% for a natural person and 20% otherwise**.

**Fiscal devices: the weakest item, and it should not ship on what is verified.** The rules let the
authority obtain information from point-of-sale machines, cash registers or software "provided that
necessary infrastructures have been in place", and from 1 July 2026 a registered person may keep
records on a server through enterprise or authority-prescribed software, with those records
admissible. But **no dedicated notification establishing the fiscal device regime was found** across
all 324 published VAT notifications, and the terms for fiscal device, electronic cash register and
the device controller do not appear anywhere in the authentic English text of the rules. The commonly
cited basis is a records-retention provision, **not** a device mandate. The rollout, the operator's
appointment and the installed count are **not researched**, and the authority's lottery-result archive
has not been updated since August 2024.

**Terminology.** The tax is **Musak**, the statutory abbreviation, and notification numbers end with
it. The document is a **kar challanpatra**, tax invoice, on **form Mushak-6.3**; users say "Mushak
challanpatra" or simply "challanpatra". The identifier is the **business identification number**. The
return is a **dakhilpatra**. Note that **"truncated" is not a statutory term** — the Act says "reduced
rate or specific amount of tax". Other forms users meet: the purchase and sales books, the
contract-manufacturing invoice, the transfer note between branches, the deduction-at-source
certificate, the credit and debit notes, the turnover tax invoice, and the input-output coefficient
declaration.

---

# What the product team should know before writing any copy

1. **Open market value is not the common rule for free goods.** The United Kingdom and the European
   Union charge on cost; the United States charges the giver use tax on purchase price. Singapore and
   Sri Lanka use open market value. Presenting open market value as the default is wrong for most
   readers.
2. **Partner's salary flips between jurisdictions** — an appropriation in the United Kingdom and
   Singapore, a deductible guaranteed payment in the United States, added back in Germany, not
   deductible by statute in Sri Lanka. The shared concept text must not state a treatment.
3. **Whether a discount must appear on the invoice splits the jurisdictions three ways, and there is
   no safe default.** Pakistan requires the discounted price **and the related tax** on the face of
   the invoice, and the discount must conform to normal business practice. India requires it duly
   recorded in the invoice when given at or before supply. Malaysia and Singapore mandate a discount
   particular outright, and the United Kingdom goes further and wants the **rate** of any cash
   discount. The European Union keys off whether the discount was granted rather than shown.
   Bangladesh and Sri Lanka have **no display requirement at all**. Oman makes display a condition of
   the relief.
4. **Arabic is mandatory on the invoice in Saudi Arabia and Oman but not in the United Arab
   Emirates.** One template cannot serve the Gulf.
5. **Three numbers in circulation are wrong.** Malaysia's e-invoice exemption is RM3,000,000, not
   RM1 million. Sri Lanka's tax threshold stays at Rs 15m and Rs 60m; the cut was abandoned. The
   United Kingdom's director's loan charge is 35.75% from 6 April 2026, and the consumer-facing
   government page still says 33.75%.
6. **Spain's business-to-business e-invoicing start dates are not yet fixed** and must not be stated.
   Any text saying October 2027 is repeating a draft.
7. **The United Kingdom's e-invoicing mandate is "from 2029" with no standard chosen.** "April 2029"
   and a confirmed network are both contradicted by the primary source.
8. **The rewritten leases and revenue sections of United Kingdom GAAP are effective for periods
   beginning on or after 1 January 2026** — live for those readers right now.
9. **United Kingdom profit and loss filing at the registry is April 2028, not 2027.**
10. **"Euro area" and "European Union" are different sets**, 21 and 27, and VAT is a Union rule set.

# Sources

Each jurisdiction's sources, with the access date and the primary or commentary label, belong in
[`SOURCES.md`](SOURCES.md), which is not yet written. A note may not be transcribed into the
catalogue until its source appears there with a check date.
