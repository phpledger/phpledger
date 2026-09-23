"""Versioned sample chart normalization and explicitly fictional learning content.

The old generator remains the accounting baseline. Code normalization is a pure
rename, independently testable before Cedar's separately identified enrichment.
"""
import calendar
import copy
from decimal import Decimal as D

VERSION = "1.1.0"
CLASS = {"asset": "1", "liability": "2", "equity": "3", "income": "4", "expense": "5"}
GROUP_NAMES = {"1-130": "Inventory", "4-200": "Other Income", "5-200": "Cost of Sales"}


def account_group(definition):
    kind, code = definition["type"], definition["code"]
    role = definition.get("fixture_role", "")
    if definition.get("is_contra"):
        return "900"
    if kind == "asset":
        if code in {"1010", "1020", "1030"} or role in {"cash_on_hand", "bank_current", "route_cash"}:
            return "100"
        if code == "1300":
            return "200"
        if code in {"1200", "1500"} or "prepaid" in role or "advance" in role:
            return "120"
        if code == "1400" or "inventory" in role:
            return "130"
        return "110"
    if kind == "liability":
        return "110" if code == "2200" else "120" if code == "2400" or role in {"deferred_revenue", "store_credit_liability"} else "100"
    if kind == "income":
        return "200" if code == "4800" else "100"
    if kind == "expense":
        return "200" if code == "5700" or role == "cost_of_goods_sold" else "100"
    return "100"


def remap(value, mapping):
    if isinstance(value, dict):
        return {mapping.get(k, k): remap(v, mapping) for k, v in value.items()}
    if isinstance(value, list):
        return [remap(v, mapping) for v in value]
    return mapping.get(value, value) if isinstance(value, str) else value


def normalize_pack(pack, starter):
    pack = copy.deepcopy(pack)
    mapping = {a["legacy_code"]: a["code"] for a in starter["accounts"] if a.get("legacy_code")}
    for definition in pack["accounts"]:
        mapping[definition["code"]] = f'{CLASS[definition["type"]]}-{account_group(definition)}-{20000 + int(definition["code"]):05d}-00'
    # Industry codes are a separate namespace: e.g. 2310 means withholding in the
    # ledger baseline, but a retainer in Cedar's researched vocabulary.
    material = pack.pop("source_material")
    pack = remap(pack, mapping)
    for profile in [material.get("industry_profile"), (material.get("research_evidence") or {}).get("industry_profile")]:
        if not profile:
            continue
        for definition in profile.get("accounts", []):
            if "-" in definition["code"]:
                continue
            definition["code"] = f'{CLASS[definition["type"]]}-{account_group(definition)}-{30000 + int(definition["code"]):05d}-00'
            definition["heading_code"] = definition["code"][:5] + "-00000-00"
            if definition.get("fixture_role") == "route_cash":
                definition["role"] = "cash_bank"
                definition["money_kind"] = "physical"
    pack["source_material"] = material
    pack["money_account_kinds"] = {mapping[code]: kind for code, kind in {"1000": "bank", "1010": "bank", "1020": "physical", "1030": "physical"}.items()}
    pack["version"] = VERSION
    pack["account_code_format"] = "structured"
    pack["code_aliases"] = mapping
    for definition in pack["accounts"]:
        definition["heading_code"] = definition["code"][:5] + "-00000-00"
        if definition["code"].startswith("5-200-"):
            definition["report_classification"] = "cost_of_sales"
        if definition["code"] in pack["money_account_kinds"]:
            definition["money_kind"] = pack["money_account_kinds"][definition["code"]]
    used = {a["code"][:5] for a in pack["accounts"]}
    used.update(a["code"][:5] for a in (material.get("research_evidence") or {}).get("industry_profile", {}).get("accounts", []))
    pack["account_headings"] = [{"code": group + "-00000-00", "name": name,
                                  "type": next(k for k, v in CLASS.items() if v == group[0])}
                                 for group, name in GROUP_NAMES.items() if group in used]
    pack["validation_issues"] = operational_cash_issues(material.get("research_evidence") or {}, pack["id"])
    return pack


# These source records already remain unposted in the existing replay because
# their opening-detail/invoice/prepayment prerequisites are not imported. The
# listed bank flows must not fund or consume later practice payments. This is an
# explicit reviewed fixture contract, checked against runtime receipts below;
# it does not invent opening balances or duplicate the AR/inventory services.
BANK_PROJECTION_EXCLUSIONS = {
    "retail-shop": ["retail-shop-event-04"],
    "distributor": ["distributor-event-05"],
    "trader": ["trader-event-04"],
    "membership-club": ["membership-club-event-01", "membership-club-event-04", "membership-club-event-05"],
}


def operational_cash_issues(evidence, slug):
    """Retain unfunded practice events as evidence; no invented cash or facility.

    Operational balances begin at zero: opening_evidence is deliberately not
    imported. Stage an unfunded outflow and its unposted-source reversal before
    considering later events, so neither can manufacture the next balance.
    """
    key_roles = {a.get("key", a.get("semantic_key", "")): a.get("fixture_role", "")
                 for a in evidence.get("semantic_accounts", [])}
    exclusions = BANK_PROJECTION_EXCLUSIONS.get(slug, [])
    evidence["bank_projection_excludes_unposted_sources"] = exclusions
    balances, issues, staged = {"cash_on_hand": D(0), "bank_current": D(0)}, [], set()
    for event in sorted(evidence.get("operational_event_contract", []), key=lambda e: (e["date"], e["id"])):
        if event["id"] in exclusions:
            staged.add(event["id"])
            continue
        changes = {role: sum((D(row["debit"]) - D(row["credit"]) for row in event.get("expected_journal", [])
                             if row.get("account_role", key_roles.get(row.get("account_key"))) == role), D(0)) for role in balances}
        issue = None
        if event.get("reverses_event_id") in staged:
            issue = {"code": "source_not_posted", "event_id": event["id"], "date": event["date"],
                     "related_event_id": event["reverses_event_id"], "status": "staged_not_posted",
                     "reason": "The original practice expense was not posted because it was unfunded. Its reversal is also unposted and cannot add money to the bank."}
        else:
            for role, change in changes.items():
                balance = balances[role]
                if change >= 0 or balance + change >= 0:
                    continue
                bank = role == "bank_current"
                consequence = ("The supplier bill remains unpaid; the payment has no effect on the bank or the payable."
                               if event["kind"] == "supplier_payment" else
                               "The expense and bank payment remain unposted." if event["kind"] in {"expense", "expense_bill"}
                               else "No transfer is recorded between the accounts.")
                issue = {"code": "bank_shortfall" if bank else "cash_shortfall", "event_id": event["id"], "date": event["date"],
                         "available": f"{balance:.4f}", "required": f"{-change:.4f}",
                         "shortfall": f"{-(balance + change):.4f}", "status": "staged_not_posted",
                         "reason": ("The authored payment exceeds the operational bank balance. No agreed overdraft facility is recorded, so its limit is zero. " + consequence + " No funding or credit facility has been invented."
                                    if bank else "The authored till deposit exceeds the cash recorded in this operational scenario. It remains unposted evidence; no funding has been invented.")}
                if bank:
                    issue["overdraft_limit"] = "0.0000"
                    issue["accounting_consequence"] = consequence
                break
        if issue is not None:
            event["validation_issue"] = issue
            issues.append(issue)
            staged.add(event["id"])
            continue
        for role, change in changes.items():
            balances[role] += change
    return issues


def enrich_cedar(slug, events, monthly, line):
    """Carve real invoices/receipts/expenses out of summaries: no double counting."""
    if slug != "service-agency":
        return
    by_key = {e["key"]: e for e in events}
    for year in (2024, 2025):
        for quarter in range(1, 5):
            month = 1 + (quarter - 1) * 3
            ym = f"{year}-{month:02d}"
            key = f"story-project-{year}-q{quarter}"
            by_key[f"receipts-{ym}"]["amount"] = f'{D(by_key[f"receipts-{ym}"]["amount"]) - D(100):.4f}'
            events.extend([
                {"key": key, "kind": "story_invoice", "date": ym + "-08", "amount": "100.0000",
                 "party_key": "design-client", "reference": slug + "/" + key,
                 "description": "Juniper Foods commissions a separately invoiced design review; removed from the monthly receipt summary.",
                 "lines": [line("1150", 100), line("4000", -100)]},
                {"key": key + "-collection", "kind": "story_collection", "date": ym + "-12", "amount": "100.0000",
                 "invoice_key": key, "party_key": "design-client", "reference": slug + "/" + key + "-collection",
                 "description": "Juniper pays the design-review invoice; collecting a receivable does not earn the income twice.",
                 "lines": [line("1000", 100), line("1150", -100)]},
            ])
            expense_month = f"{year}-{month + 1:02d}"
            operations = by_key[f"operations-{expense_month}"]
            # The original utilities support is 100: 90 bank plus 10 petty cash.
            # Separate a 30 bank payment and retain the remaining 70 in support.
            for row in operations["lines"]:
                if row["code"] == "5300" and row["debit"] == "100.0000":
                    row["debit"] = "70.0000"
                elif row["code"] == "1000" and row["credit"] == "90.0000":
                    row["credit"] = "60.0000"
            events.append({"key": f"story-utilities-{year}-q{quarter}", "kind": "expense",
                           "date": expense_month + "-18", "amount": "30.0000", "money_code": "1000",
                           "category_code": "5300", "party_key": "utilities", "counterparty": "Brook Utilities (Sample)",
                           "reference": f"{slug}/story-utilities-{year}-q{quarter}",
                           "description": "Pay Brook Utilities for the studio connection. This 30 is removed from the monthly utilities support.",
                           "lines": [line("5300", 30), line("1000", -30)]})
            for row in monthly:
                if row["month"] == ym:
                    row["summary_bank_receipts"] = f'{D(row["bank_receipts"]) - D(100):.4f}'
                    row["separate_invoice_collections"] = "100.0000"
                if row["month"] == expense_month:
                    row["summary_utilities"] = "70.0000"
                    row["separate_utilities_payment"] = "30.0000"


IDENTITIES = {
    "service-agency": ("Mira Vale", "Noel Reed", "Mira and Noel opened a two-person design studio above a former print shop. Mira looks after client projects; Noel keeps the monthly records. They want stable retainers without confusing deposits, earned fees and cash.", "#24574c", "cedar"),
    "retail-shop": ("Ada Willow", "Jon Bell", "Ada turned a neighbourhood kiosk into a small stationery shop. Jon counts the till and checks deliveries. Their story follows stock, customer returns and the difference between a busy counter and a profitable month.", "#855032", "willow"),
    "seasonal-business": ("Leah Dawn", "Owen Moss", "Leah started with one garden-maintenance round; Owen now schedules the crew. Quiet winters and busy summers make cash planning and equipment upkeep as important as the next planting job.", "#9b6310", "sunrise"),
    "distributor": ("Ravi Harbor", "Tessa Lane", "Ravi supplies independent shops while Tessa reconciles route collections. The fictional business teaches distribution bookkeeping without pretending that the sample provides route planning or proof of delivery.", "#295775", "harbor"),
    "trader": ("Elin Quay", "Sam Rowan", "Elin built a small wholesale desk matching local retailers with suppliers. Sam checks purchase evidence and customer credit. Their books separate stock on hand, supplier obligations and cash collected.", "#4b557e", "trade"),
    "restaurant": ("Nia Cedar", "Ben Spoon", "Nia and Ben opened a neighbourhood cafe with a short seasonal menu. Nia buys ingredients and Ben closes the counter. The sample follows bookkeeping only, with kitchen operations deliberately outside its claims.", "#974c37", "table"),
    "membership-club": ("Iris Brook", "Leo Marsh", "Iris coordinates a fictional community club and Leo keeps its books. Members fund shared activities, so the example distinguishes earned dues, future-period receipts and the cost of running events.", "#36695f", "river"),
    "pharmacy": ("Asha Meadow", "Finn Hale", "Asha and Finn run a training shop using non-medicinal practice goods. Their fictional counter teaches stock and sales records; it contains no patients, dispensing, medicines or claims of pharmacy compliance.", "#577240", "meadow"),
    "jewelry-studio": ("Lena Finch", "Theo Ember", "Lena designs small collections and Theo maintains the workshop records. Their studio separates owned materials, finished pieces and workmanship; specialist precious-metal controls remain future work.", "#896021", "finch"),
    "light-manufacturing": ("Maya Maple", "Eli Bench", "Maya and Eli make small furniture runs for local interiors. Their fictional records introduce materials and finished goods without claiming to run bills of materials, production orders or factory costing.", "#805848", "bench"),
    "service-workshop": ("Kit Wheel", "Robin Spoke", "Kit opened a bicycle repair workshop and Robin keeps parts and payments organised. They distinguish parts owned by the workshop from customers' bicycles, which never become the workshop's inventory.", "#405b78", "wheel"),
}

MONTHS = [
    ("Opening the studio", "Mira and Noel contribute their agreed capital, establish a counted till and petty-cash float, and buy annual insurance.", "Owner funding is equity, the bank-to-cash transfer earns no income, and insurance starts as a prepayment."),
    ("Paying for a working studio", "Noel checks the first equipment depreciation and separates Brook Utilities' payment from the monthly support.", "A paid operating cost reduces the bank; depreciation reduces profit without another cash payment."),
    ("Borrowing with a purpose", "The studio reviews its term-loan funding before accepting a larger project.", "Borrowed principal is a liability rather than revenue. Interest and principal have different accounting effects."),
    ("A client invoice, then collection", "Mira invoices Juniper for a design review; Noel matches the later bank receipt to that invoice.", "The invoice earns income and creates a receivable. Its collection clears the receivable, not another sale."),
    ("Separating payment from profit", "Noel checks the named utility payment against the remaining monthly support.", "The separate payment is carved out of the summary so the same expense is never counted twice."),
    ("The half-year pause", "Mira and Noel compare the first six months and check what remains in cash, prepayments and equipment.", "Profit is not the bank balance: non-cash charges, loan principal and capital movements explain differences."),
    ("A support promise", "A fresh design review is invoiced and collected while the studio reviews support work promised to clients.", "Earned project fees are income; advance support receipts remain liabilities until earned."),
    ("Checking every correction", "Noel checks cost evidence and the utility payment before the monthly review.", "Keep mistakes and their linked reversals visible; a corrected cost must not remain counted twice."),
    ("People and the monthly close", "Mira checks staff cost and Noel reconciles what was paid or still owed.", "Recognising staff cost and settling the resulting payable are separate steps; sample payroll amounts are illustrative."),
    ("Reviewing useful equipment", "Another client review is invoiced while Noel checks the equipment support schedule.", "Compare cost, accumulated depreciation and disposal evidence; cash proceeds alone do not measure a disposal gain."),
    ("Owners and business money", "The partners examine drawings alongside the named utility payment.", "Personal withdrawals reduce equity rather than operating profit. Keep partner identities and capital accounts separate."),
    ("Closing with evidence", "The partners review outstanding items, insurance releases, loan principal and counted cash before the year closes.", "A closed period blocks new postings. It does not certify statements or transfer the year's earnings automatically."),
]



DECISIONS = [
    "Keep partner capital separate, move an actual float from bank to petty cash, and spread the insurance cost over its coverage period.",
    "Record the utility supplier separately and recognise equipment use without pretending depreciation is another payment.",
    "Separate borrowed principal from income and reserve part of future cash receipts for repayment.",
    "Invoice the named customer first, then allocate its payment to that invoice instead of entering a second receipt as revenue.",
    "Remove the separately entered utility payment from the monthly summary before posting the remaining support.",
    "Review the half-year statements together with cash movements and the partner-loan evidence before taking money out.",
    "Distinguish a completed design review from support promised for a future period before deciding what has been earned.",
    "Retain the mistaken source and use its linked reversal; post the supported replacement with its own date and reference.",
    "Reconcile staff-cost support, deductions and unpaid amounts before accepting the month's figures.",
    "Review equipment cost and accumulated depreciation together before recording proceeds or deciding a disposal gain or loss.",
    "Record personal withdrawals against the named partner's drawings account rather than hiding them in business costs.",
    "Resolve outstanding items and reconcile the closing balances before locking the historical year.",
]


def learning_story(pack):
    first, second, origin, color, mark = IDENTITIES[pack["id"]]
    story = {"version": VERSION, "fictional": True, "origin": origin,
             "characters": [{"name": first, "role": "Business lead"}, {"name": second, "role": "Records and operations"}],
             "logo": {"color": color, "mark": mark, "path": f'/assets/sample-companies/{pack["id"]}.svg'},
             "status": "complete_history" if pack["id"] == "service-agency" else "profile_only",
             "notice": "Fictional background is storytelling. Only linked recorded events change the sample books. Amounts are illustrative base-currency units; no country tax rules are implied.",
             "chapters": []}
    if pack["id"] != "service-agency":
        story["journey_note"] = "Profile and existing teaching scenario available. Enriched monthly chapters are not yet complete."
        return story
    for year in (2024, 2025):
        for month in range(1, 13):
            ym = f"{year}-{month:02d}"
            title, happened, treatment = MONTHS[month - 1]
            if year == 2025 and month == 1:
                happened = "Noel renews the annual insurance and pays the prior year's accrued bonus while Mira reviews another client invoice."
                treatment = "The renewal is a new prepayment. Paying an accrued bonus clears the liability without charging the expense a second time."
            elif year == 2025 and month == 3:
                happened = "Mira and Noel review their continuing loan and compare this March with the studio's first spring."
            elif year == 2025 and month == 8:
                happened = "Noel reverses the mistaken 25.00 cost and records its 45.00 replacement on the following day, keeping all three entries visible."
                treatment = "A linked reversal removes the mistake; the replacement earns its own source identity. The corrected cost is 45.00, not 70.00."
            elif year == 2025 and month == 10:
                happened = "Noel records the display-counter disposal while Mira checks the separate Juniper invoice and collection."
                treatment = "Remove cost of 600.00 and accumulated depreciation of 200.00. Proceeds of 350.00 leave a disposal loss of 50.00; no depreciation is charged in the disposal month."
            events = [e for e in pack["events"] if e["date"].startswith(ym)]
            story["chapters"].append({"id": ym, "month": ym, "period": "history", "title": f"{ym} · {title}",
                "happened": happened, "decision": DECISIONS[month - 1],
                "accounting": treatment, "inspect": "Follow the recorded sources, then compare this month's profit and loss with the month-end trial balance.",
                "events": [{"key": e["key"], "reference": e["reference"], "kind": e["kind"], "date": e["date"], "description": e["description"],
                            **({"amount": e["amount"]} if "amount" in e else {})} for e in events],
                "reports": {"from": ym + "-01", "to": f"{ym}-{calendar.monthrange(year, month)[1]}"}})
    story["chapters"].append({"id": "practice-2026", "period": "practice", "title": "2026 · Your practice year",
        "happened": "January contains seeded support and a bank receipt. February's receipt and expenses remain editable drafts.",
        "decision": "Choose or explicitly create the payer or payee, check the payment date and posted balance, and review before posting.",
        "accounting": "A draft changes no balance. Fund petty cash with a real transfer before paying from it; do not record invented income to cover a shortfall. Use invoice settlement when paying an existing open item.",
        "inspect": "Compare petty cash before and after posting; trace the linked party and journal. Practice changes belong only to your isolated company.",
        "events": [], "reports": {"from": "2026-01-01", "to": "2026-12-31"}})
    return story


def attach_parties(pack):
    pack["document_parties"] = [
        {"key": "monthly-customers", "name": "Fictional monthly customer receipts", "role": "customer"},
        {"key": "practice-customer", "name": "Fictional new customer", "role": "customer"},
        {"key": "office-supply", "name": "Harbor Office Supply", "role": "vendor"},
        {"key": "stationery", "name": "Fictional local stationery", "role": "vendor"},
        {"key": "design-client", "name": "Juniper Foods (Sample)", "role": "customer"},
        {"key": "utilities", "name": "Brook Utilities (Sample)", "role": "vendor"},
    ]
    for event in pack["events"]:
        if event["kind"] == "receipt":
            event["party_key"] = "monthly-customers"
    for event in pack["drafts"]:
        event["party_key"] = {"practice-receipt": "practice-customer", "practice-expense": "office-supply", "practice-petty": "stationery"}[event["key"]]
    return pack
