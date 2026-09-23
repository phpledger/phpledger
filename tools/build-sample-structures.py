"""Build the bundled sample structure catalogue (owner decision B50).

A sample company carries two different things: what the business *is* and what it *did*.
`resources/sample-structures/<id>-<version>.json` is the first of those, written out on its own
so that starting a business from a sample reads a declared structure instead of filtering the
sample's history to guess at one.

What this reads from each pack, and nothing else:

  * `accounts`                                   -> the chart
  * `source_material.research_evidence.industry_profile.accounts`
                                                 -> the vertical chart this sample belongs to
  * `source_material.research_evidence.contacts` -> customers and suppliers, with no balance
  * `source_material.research_evidence.products` -> products, with no stock

`events`, `drafts`, `checkpoints` and `monthly_support` are the pack's history and are never
opened here. The module set per sample is an authoring decision and is stated in MODULES below,
where a reviewer can see it, rather than inferred from which kinds of event a pack happens to
contain.

Each file pins the sha256 of the pack it was built from. If a pack moves, the structure goes
stale rather than silently wrong: the application stops offering that sample as a skeleton and
`--check` names the file.

    python tools/build-sample-structures.py --check     # verify the committed files
    python tools/build-sample-structures.py --write     # regenerate them
"""
import argparse
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PACKS = ROOT / "resources" / "demo-packs"
DEST = ROOT / "resources" / "sample-structures"
CONTRACT = 1

# The optional modules each sample's structure needs to make sense of its own chart. An
# authoring decision per sample, reviewed here rather than derived from its transactions.
# Order does not matter: the importer enables them in registry (dependency) order.
MODULES = {
    "service-agency": [],
    "seasonal-business": [],
    "membership-club": [],
    "retail-shop": ["inventory", "pos-showcase", "trading-documents"],
    "restaurant": ["inventory", "pos-showcase"],
    "pharmacy": ["inventory", "pos-showcase", "trading-documents"],
    "jewelry-studio": ["inventory", "trading-documents"],
    "trader": ["inventory", "purchasing", "trading-documents"],
    "distributor": ["inventory", "inventory-locations", "purchasing", "trading-documents"],
    "light-manufacturing": ["inventory", "purchasing"],
    "service-workshop": ["inventory", "purchasing"],
}

# Which number series a sample provisions up front, by the modules it runs. A business that
# issues invoices gets its invoice series on day one instead of on its first posting.
SERIES_FOR_MODULE = {
    "trading-documents": ["invoice", "customer_credit"],
    "purchasing": ["bill", "supplier_credit"],
    "inventory-locations": ["stock_issue", "stock_reissue", "stock_return", "gate_pass"],
}
SERIES_PREFIX = {
    "invoice": "INV", "bill": "BILL", "customer_credit": "CR", "supplier_credit": "SC",
    "stock_issue": "ISS", "stock_reissue": "RISS", "stock_return": "RTN", "gate_pass": "GP",
}

# The starter chart every book is created with (resources/coa/core-starter-1.2.0.json), by the
# legacy number a pack refers to it by. A structure never recreates these, and it never carries
# the chart's class and group headings either: those come with the chart, not with a sample.
STARTER_CHART = json.loads((ROOT / "resources/coa/core-starter-1.2.0.json").read_text())
STARTER_ALIASES = {a["legacy_code"]: a["code"] for a in STARTER_CHART["accounts"] if a.get("legacy_code")}
STARTER_CODES = {a["code"] for a in STARTER_CHART["accounts"]}

# Where a semantic account the sample's own chart does not carry falls back to, by the role the
# pack gives it. Only the six purposes the starter chart actually has.
STARTER_FOR_ROLE = {
    "cash_on_hand": "1000", "bank_current": "1000", "accounts_receivable": "1100",
    "accounts_payable": "2000", "owner_equity": "3000",
    "sales_revenue": "4000", "service_revenue": "4000",
    "operating_expense": "5000", "expense": "5000", "cost_of_goods_sold": "5000",
}


def read_pack(path):
    raw = path.read_text(encoding="utf-8").replace("\r\n", "\n")
    return json.loads(raw), raw


def digest(raw):
    import hashlib
    return hashlib.sha256(raw.encode("utf-8")).hexdigest()


def evidence_of(pack):
    return pack.get("source_material", {}).get("research_evidence", {}) or {}


def chart(pack):
    """The sample's own chart: its declared accounts plus its vertical profile's accounts."""
    accounts, seen = [], set(STARTER_CODES)
    profile = evidence_of(pack).get("industry_profile", {}) or {}
    for definition in list(pack.get("account_headings", [])) + list(pack.get("accounts", [])) + list(profile.get("accounts", [])):
        code = str(definition["code"])
        if code in seen:
            continue
        seen.add(code)
        entry = {"code": code, "name": definition["name"], "type": definition["type"]}
        if definition.get("role"):
            entry["role"] = definition["role"]
        if definition.get("report_classification"):
            entry["report_classification"] = definition["report_classification"]
        if definition.get("money_kind"):
            entry["money_kind"] = definition["money_kind"]
        if definition.get("is_contra"):
            entry["is_contra"] = True
        # A vertical cost-of-goods account is presented under cost of sales, the way the
        # sample's own replay presents it.
        if definition.get("fixture_role") == "cost_of_goods_sold" and definition["type"] == "expense":
            entry["report_classification"] = "cost_of_sales"
        accounts.append(entry)
    return accounts


def semantic_codes(pack):
    """semantic key -> account code, from the sample's vertical profile and the starter chart.

    The keys are the ones the pack's own products and semantic-account list name. Nothing here
    looks at a journal line: a key resolves because the chart declares it, or it does not
    resolve at all.
    """
    vertical, starter = {}, {}
    profile = evidence_of(pack).get("industry_profile", {}) or {}
    for definition in profile.get("accounts", []):
        if definition.get("semantic_key"):
            vertical[definition["semantic_key"]] = str(definition["code"])
    for definition in evidence_of(pack).get("semantic_accounts", []) or []:
        key, role = definition.get("key"), definition.get("fixture_role")
        if key and role in STARTER_FOR_ROLE:
            starter[key] = STARTER_ALIASES[STARTER_FOR_ROLE[role]]
    return vertical, starter


def parties(pack):
    """Customers and suppliers, with no balance and no document behind them."""
    evidence = evidence_of(pack)
    result = []
    for contact in evidence.get("contacts", []) or []:
        role = contact.get("role", "")
        result.append({
            "legal_name": contact["name"],
            "entity_type": "private_company",
            "country_code": "ZZ",
            "is_customer": role == "customer",
            "is_vendor": role == "vendor",
            "ar_code": STARTER_ALIASES["1100"] if role == "customer" else None,
            "ap_code": STARTER_ALIASES["2000"] if role == "vendor" else None,
            "notes": "Brought in from a sample company's structure. No balance and no document was copied.",
        })
    for entry in result:
        for field in ("ar_code", "ap_code"):
            if entry[field] is None:
                del entry[field]
    return result


def products(pack, modules, chart_codes):
    """Products, with no opening stock. Only where the sample runs Inventory.

    Every account a product names is resolved through the sample's own semantic keys. A product
    whose stock or cost account cannot be resolved that way is left out rather than pointed at a
    guessed account: booking stock to whatever happens to sit at code 1300 is worse than not
    bringing the product in at all.
    """
    if "inventory" not in modules:
        return []
    vertical, starter = semantic_codes(pack)
    # Some packs name a product's accounts by a key their own vertical profile does not declare
    # (the distribution pack still names the wholesale keys), and some name none at all. The
    # profile itself still says which of its accounts holds stock, which carries cost of goods
    # sold and which takes income, so that is the fallback before the starter chart. Every step
    # of this reads the chart; none of it reads a transaction.
    roles = {}
    for definition in (evidence_of(pack).get("industry_profile", {}) or {}).get("accounts", []):
        roles.setdefault(definition.get("fixture_role"), str(definition["code"]))
    resolve = lambda key, role, default: (vertical.get(key) or roles.get(role)
                                          or starter.get(key) or default)
    result = []
    for product in evidence_of(pack).get("products", []) or []:
        stock = product.get("kind") not in ("service", "nonstock")
        sales = resolve(product.get("income_account_key"),
                        "sales_revenue" if stock else "service_revenue", None) \
            or roles.get("sales_revenue") or STARTER_ALIASES["4000"]
        cost = resolve(product.get("cost_account_key"), "cost_of_goods_sold", STARTER_ALIASES["5000"])
        inventory = resolve(product.get("inventory_account_key"), "inventory", None)
        if stock and (inventory is None or inventory not in chart_codes or cost not in chart_codes):
            continue
        if sales not in chart_codes:
            continue
        entry = {
            "sku": product["id"].upper(),
            "name": product["name"],
            "kind": "stock" if stock else "nonstock",
            "base_unit": product.get("unit", "each"),
            "selling_price": product.get("sale_price", "0.0000"),
            "sales_code": sales,
            # A purchase of a stock item is received into stock, not expensed, so the purchase
            # account is the general expense account, exactly as the starter playground sets it.
            "purchase_code": STARTER_ALIASES["5000"],
        }
        if stock:
            entry["inventory_code"] = inventory
            entry["cogs_code"] = cost
        result.append(entry)
    return result


def series(modules):
    result = []
    for module in ("trading-documents", "purchasing", "inventory-locations"):
        if module not in modules:
            continue
        for kind in SERIES_FOR_MODULE[module]:
            result.append({"type": kind, "prefix": SERIES_PREFIX[kind], "padding": 6,
                           "year_segment": True, "reset_rule": "yearly"})
    return result


def build(slug):
    entry = next(p for p in json.loads((PACKS / "catalog.json").read_text()) if p["id"] == slug)
    path = PACKS / entry["file"]
    pack, raw = read_pack(path)
    modules = MODULES[slug]
    accounts = chart(pack)
    codes = {entry["code"] for entry in accounts} | STARTER_CODES
    party_rows = parties(pack)
    product_rows = products(pack, modules, codes)
    open_items = []
    if any(row["is_customer"] for row in party_rows):
        open_items.append(STARTER_ALIASES["1100"])
    if any(row["is_vendor"] for row in party_rows):
        open_items.append(STARTER_ALIASES["2000"])
    structure = {
        "contract": CONTRACT,
        "id": pack["id"],
        "version": pack["version"],
        "name": pack["name"],
        "business": pack["business"],
        "source_digest": digest(raw),
        "note": ("The structure of this sample company: its chart, the modules it needs, its "
                 "numbering and its customers, suppliers and products. None of its transactions, "
                 "balances or stock is here, and none is created when it is brought in."),
        "accounts": accounts,
        "open_item_accounts": open_items,
        "money_account_kinds": pack.get("money_account_kinds", {}),
        "modules": modules,
        "packages": [],
        "number_series": series(modules),
        "policies": {"discount_posting": "net", "free_goods_output_tax": "none",
                     "cash_on_invoice_cap": "0.0000"} if "trading-documents" in modules else {},
        "parties": party_rows,
        "products": product_rows,
        "tax_codes": [],
    }
    return structure


def render(structure):
    return json.dumps(structure, indent=2, ensure_ascii=False) + "\n"


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--check", action="store_true", help="verify the committed files reproduce")
    parser.add_argument("--write", action="store_true", help="regenerate the committed files")
    arguments = parser.parse_args()
    if not arguments.check and not arguments.write:
        parser.error("choose --check or --write")
    DEST.mkdir(parents=True, exist_ok=True)
    failures = []
    for slug in sorted(MODULES):
        structure = build(slug)
        target = DEST / (slug + "-" + structure["version"] + ".json")
        rendered = render(structure)
        if arguments.write:
            target.write_text(rendered, encoding="utf-8", newline="\n")
            print("wrote %s (%d accounts, %d parties, %d products, modules %s)"
                  % (target.name, len(structure["accounts"]), len(structure["parties"]),
                     len(structure["products"]), ",".join(structure["modules"]) or "-"))
            continue
        if not target.exists():
            failures.append("%s is missing" % target.name)
        elif target.read_text(encoding="utf-8").replace("\r\n", "\n") != rendered:
            failures.append("%s does not match its pack; regenerate with --write" % target.name)
    # The empty starter has no history pack. Normalize its explicitly authored
    # predecessor with the same fixed mapping used by the PHP playground.
    from sample_pack_learning import remap
    starter = json.loads((DEST / "accounting-starter-1.0.0.json").read_text())
    starter_map = STARTER_ALIASES | {"1300":"1-130-21300-00","1350":"1-110-21350-00", "2100":"2-100-22100-00", "2150":"2-100-22150-00", "5100":"5-200-25100-00", "5200":"5-100-25200-00"}
    starter = remap(starter, starter_map)
    starter["version"] = "1.1.0"
    starter["money_account_kinds"] = {STARTER_ALIASES["1000"]: "bank"}
    starter["accounts"] = [{"code":"1-130-00000-00","name":"Inventory","type":"asset"}, {"code":"5-200-00000-00","name":"Cost of Sales","type":"expense"}] + starter["accounts"]
    target = DEST / "accounting-starter-1.1.0.json"
    if arguments.write:
        target.write_text(render(starter), encoding="utf-8", newline="\n")
    elif not target.exists() or target.read_text(encoding="utf-8") != render(starter):
        failures.append("accounting-starter-1.1.0.json does not match; regenerate with --write")
    if arguments.check:
        if failures:
            for failure in failures:
                print("FAIL " + failure)
            raise SystemExit(1)
        print("Sample structures: %d files match their packs." % len(MODULES))


if __name__ == "__main__":
    main()
