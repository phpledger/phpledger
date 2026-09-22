"""Build original, sample demo histories with independent Decimal checkpoints.

No database access. --check verifies that the pinned fixtures are reproducible.
Amounts are illustrative base-currency units, with no tax/payroll jurisdiction.
"""
import argparse
import calendar
from collections import defaultdict
from decimal import Decimal
import hashlib
import json
from pathlib import Path
from datetime import date, timedelta

ROOT = Path(__file__).resolve().parents[1]
DEST = ROOT / "resources" / "demo-packs"
PROFILE_CATALOG = json.loads((ROOT / "resources" / "coa" / "industry-profiles-0.5.0.json").read_text(encoding="utf-8"))
STARTER_PROVISIONS = json.loads((ROOT / "resources" / "coa" / "core-starter-1.2.0.json").read_text(encoding="utf-8")).get("provisions", [])
D = Decimal

# The one month every pack runs payroll by element rather than as a single salary line (#98).
PAYROLL_MONTH = "2025-09"
# The month the fictional display counter leaves the books (#95); monthly depreciation drops after it.
DISPOSAL_DATE = "2025-10-31"
# Deferred income is released a month at a time over the plan's coverage (#94).
DEFERRED_MONTHS = ["2024-%02d" % m for m in range(7, 13)] + ["2025-%02d" % m for m in range(1, 7)]


def money(value):
    return format(D(value), ".4f")


def industry_profile_for(slug):
    for profile in PROFILE_CATALOG.get("profiles", []):
        if slug in profile.get("sample_ids", []):
            return profile
    raise ValueError(f"No vertical COA profile is mapped to sample {slug}")


def line(code, signed, description=""):
    value = D(signed)
    assert value != 0
    return {"code": code, "debit": money(max(value, 0)),
            "credit": money(max(-value, 0)), "description": description}


def scenario_for(slug):
    scenarios = {
        "service-agency": {"id": "agency-month-end", "title": "Review project income and operating costs", "goal": "Follow a professional-services month from client receipts and cash sales to staff, rent, insurance and a corrected expense.", "steps": ["Trace a monthly client receipt and separate cash activity in the bank and till accounts.", "Review recurring staff, rent, utilities and insurance entries in the month-end journal.", "Follow the year-end customer collection without recording income twice.", "Review the mistaken cost, linked reversal and corrected replacement."], "checks": ["Staff entries are illustrative support schedules, not payroll, withholding or employment compliance.", "Client work is represented by general-ledger examples, not project management or time billing.", "The open 2026 drafts are separate practice records and do not alter closed history."]},
        "seasonal-business": {"id": "seasonal-cash-cycle", "title": "Compare a seasonal cash cycle", "goal": "Compare monthly receipts across a seasonal service year while keeping operating costs, prepayments and settlements traceable.", "steps": ["Compare low-season and peak-season monthly receipt summaries.", "Trace recurring operating costs and insurance release through the general ledger.", "Review the cross-year customer collection and supplier payment.", "Use the open 2026 drafts to test a new seasonal entry without changing history."], "checks": ["The receipt curve is a sample teaching pattern, not a forecast or business benchmark.", "No workforce scheduling, weather model, contract pipeline or seasonal tax treatment is implemented.", "Profit and cash are reviewed through the existing statements and account movements."]},
        "trader": {"id": "trade-pricing", "title": "Review a trade sale and settlement", "goal": "Follow stock purchase, customer credit sale, partial collection, return and till-to-bank transfer.", "steps": ["Receive the boxed supply purchase at its recorded unit cost.", "Post the trade-customer invoice and inspect the stock issue and receivable.", "Apply the named customer receipt, then review the remaining balance and return credit.", "Compare the cash transfer with the cash-on-hand and bank accounts."], "checks": ["A return is linked to the source invoice and does not create a second sale.", "Customer and supplier balances remain in their respective control accounts.", "The pack uses one stock location and does not claim pricing-list or sales-order functionality."]},
        "restaurant": {"id": "table-kot", "title": "Trace a table order through the books", "goal": "Use a restaurant bookkeeping example to compare a cash sale, stock movement, supplier bill and correction.", "steps": ["Review the food and beverage items and the opening stock position.", "Trace the table/KOT example to its cash sale and cost of goods sold.", "Review the supplier purchase, payment and returned goods as separate accounting events.", "Inspect the daily expense correction and its linked reversal."], "checks": ["The sample does not implement tables, kitchen production, recipes, modifiers or service workflow.", "Food and beverage labels are sample teaching data, not food-safety or tax guidance.", "Inventory and cash totals reconcile to the posted ledger events."]},
        "membership-club": {"id": "membership-dues", "title": "Separate membership income from cash timing", "goal": "Follow a fictional club prepayment, recognition schedule, invoice, payment and expense bill.", "steps": ["Review the opening receivable and payable detail without reposting opening balances.", "Trace a membership prepayment into deferred revenue.", "Inspect the monthly recognition entry and compare it with the cash receipt.", "Review the supplier bill, payment and the club's period result."], "checks": ["Deferred revenue is not recognised before the stated coverage period.", "The club scenario does not claim charity, nonprofit, member administration or renewal compliance.", "Opening AR/AP detail remains evidence reconciled to the opening control journal."]},
        "pharmacy": {"id": "lot-expiry", "title": "Review a lot-tracked training stock example", "goal": "Trace non-medicinal training stock through lot identity, expiry metadata, sale, return and supplier settlement.", "steps": ["Review the training items, lot identifiers and stated expiry dates.", "Trace the purchase and receipt into the stock and payable accounts.", "Review the sale, returned quantity and linked customer credit.", "Check the supplier payment and the remaining lot quantity/value."], "checks": ["All products are non-medicinal sample training items.", "No dispensing, patient, prescription, controlled-substance or pharmacy compliance workflow is implemented.", "Expiry metadata is illustrative fixture data, not a regulatory control."]},
        "service-workshop": {"id": "job-card", "title": "Keep customer property separate from workshop stock", "goal": "Follow parts, labour, customer-owned property and settlement examples without treating a job card as a posted module.", "steps": ["Review the opening workshop stock and the separate customer-owned bicycle reference.", "Trace a parts purchase and a labour/service sale through the ledger.", "Review the customer receipt, supplier payment and returned part.", "Confirm that customer-owned property does not enter the inventory valuation."], "checks": ["Customer-owned property is excluded from stock balances and valuation.", "The pack does not implement job cards, custody, scheduling or work-in-progress costing.", "Parts and service income remain distinguishable in the teaching scenario."]},
        "jewelry-studio": {"id": "jewelry-materials", "title": "Separate studio sales from material costs", "goal": "Use a jewelry-studio bookkeeping example to distinguish finished-goods sales, material purchases, customer deposits and workshop costs.", "steps": ["Review the sample material and finished-piece accounts used by the example.", "Trace a purchase and sale through the inventory, revenue and cost accounts.", "Review a customer return or correction and its source link.", "Compare the month-end result with the remaining material value."], "checks": ["Metal, gemstone and finished-piece amounts are illustrative and do not claim commodity or fair-value accounting.", "Customer deposits, hallmarking, consignment, appraisal and workshop job costing are not implemented.", "The pack is a bookkeeping preview; specialist treatment and runtime replay are outside this candidate contract."]},
        "light-manufacturing": {"id": "workshop-conversion", "title": "Review a simple materials-to-sales example", "goal": "Use a light-manufacturing bookkeeping example to inspect material purchases, finished-goods movement, sales and operating costs.", "steps": ["Review the sample material and finished-goods balances at the start of the example.", "Trace purchases and stock issues to their ledger accounts.", "Review the finished-goods sale, customer settlement and any returned quantity.", "Compare the period result and closing stock with the stated fixture checkpoints."], "checks": ["This does not implement bills of material, production orders, work-in-progress or overhead absorption.", "The conversion amounts are fixed teaching data, not a production-costing policy.", "The pack is a bookkeeping preview; production costing and conversion controls are outside the current module."]},
        "retail-shop": {"id": "register-close", "title": "Check a sale, return and counted till", "goal": "Compare a small retail sale, return, till deposit, stock balance and customer settlement.", "steps": ["Review the opening till, bank, receivable, payable and stock evidence.", "Trace the stationery cash sale and its cost of goods sold.", "Review the customer invoice, receipt and linked return credit.", "Compare the counted till transfer with the cash and bank account movements."], "checks": ["A credit-note return does not create a second sale or cash receipt.", "SKU identity survives the sale, return and stock reports.", "Barcode scanning and register sessions remain future UI features."]},
        "distributor": {"id": "route-settlement", "title": "Follow a distribution route settlement", "goal": "Review a distributor's stock, customer invoice, supplier bill, settlement and route-level bookkeeping evidence.", "steps": ["Review the opening stock, customer receivable and supplier payable evidence.", "Trace stock receipt and a credit sale through inventory, cost and control accounts.", "Apply the named customer and supplier settlements and inspect residuals.", "Review the route and cash-transfer notes as bookkeeping references only."], "checks": ["The distribution scenario does not implement route planning, dispatch, delivery proof or fleet accounting.", "Inventory uses one location and the shared moving-average service.", "Customer and supplier settlement remains separate from operational delivery status."]},
    }
    return scenarios.get(slug, {"id": "bookkeeping-review", "title": "Review the monthly bookkeeping trail", "goal": "Follow fixed sample receipts, expenses, corrections and stock support schedules through the general ledger.", "steps": ["Inspect the monthly receipt and expense sources.", "Compare a closed-period report with its source journal.", "Review an open practice draft before posting.", "Follow a correction through its linked reversal."], "checks": ["This is a bookkeeping teaching example, not a complete industry operations module."]})


def generated_candidate_material(slug):
    """Provide a clearly generated candidate contract where no authored pack exists.

    These are sample planning fixtures derived from the matrix family names.
    They are intentionally not treated as published source data or runtime seed
    instructions until the service replay and release checks have completed.
    """
    candidates = {
        "service-agency": {
            "display_name": "Cedar Studio (candidate)",
            "business_type": "professional-services-agency",
            "description": "Small design and web-services studio with retainers, milestone billing and subcontractor costs.",
            "contacts": [
                {"id": "client-retainer", "name": "Northstar Foods (Sample)", "role": "customer", "email": "cedar-studio.client@example.invalid", "sample": True},
                {"id": "contractor", "name": "Blue Kite Copywriting (Sample)", "role": "vendor", "email": "cedar-studio.vendor@example.invalid", "sample": True},
            ],
            "products": [
                {"id": "monthly-retainer", "name": "Monthly design retainer (Sample)", "kind": "service", "unit": "month", "sale_price": "1800.0000"},
                {"id": "campaign-sprint", "name": "Campaign sprint (Sample)", "kind": "service", "unit": "project", "sale_price": "950.0000"},
            ],
            "events": [
                {"id": "agency-invoice-2026-09", "date": "2026-09-05", "kind": "invoice", "source_reference": "candidate:cedar-studio:invoice:2026-09", "amount": "1800.0000", "expected_journal": [{"account_role": "accounts_receivable", "debit": "1800.0000", "credit": "0.0000"}, {"account_role": "sales_revenue", "debit": "0.0000", "credit": "1800.0000"}]},
                {"id": "agency-receipt-partial", "date": "2026-09-15", "kind": "receipt", "source_reference": "candidate:cedar-studio:receipt:partial", "amount": "1000.0000", "expected_journal": [{"account_role": "bank_current", "debit": "1000.0000", "credit": "0.0000"}, {"account_role": "accounts_receivable", "debit": "0.0000", "credit": "1000.0000"}]},
                {"id": "agency-credit", "date": "2026-09-20", "kind": "customer_credit", "source_reference": "candidate:cedar-studio:credit:2026-09", "amount": "200.0000", "expected_journal": [{"account_role": "sales_revenue", "debit": "200.0000", "credit": "0.0000"}, {"account_role": "accounts_receivable", "debit": "0.0000", "credit": "200.0000"}]},
                {"id": "agency-receipt-final", "date": "2026-09-25", "kind": "receipt", "source_reference": "candidate:cedar-studio:receipt:final", "amount": "600.0000", "expected_journal": [{"account_role": "bank_current", "debit": "600.0000", "credit": "0.0000"}, {"account_role": "accounts_receivable", "debit": "0.0000", "credit": "600.0000"}]},
                {"id": "agency-contractor-bill", "date": "2026-09-28", "kind": "bill", "source_reference": "candidate:cedar-studio:bill:contractor", "amount": "750.0000", "expected_journal": [{"account_role": "contractor_expense", "debit": "750.0000", "credit": "0.0000"}, {"account_role": "accounts_payable", "debit": "0.0000", "credit": "750.0000"}]},
                {"id": "agency-supplier-payment", "date": "2026-09-30", "kind": "supplier_payment", "source_reference": "candidate:cedar-studio:payment:contractor", "amount": "750.0000", "expected_journal": [{"account_role": "accounts_payable", "debit": "750.0000", "credit": "0.0000"}, {"account_role": "bank_current", "debit": "0.0000", "credit": "750.0000"}]},
                {"id": "agency-cash-sprint", "date": "2026-08-08", "kind": "cash_sale", "source_reference": "candidate:cedar-studio:cash-sale:sprint", "amount": "450.0000", "expected_journal": [{"account_role": "cash_on_hand", "debit": "450.0000", "credit": "0.0000"}, {"account_role": "sales_revenue", "debit": "0.0000", "credit": "450.0000"}]},
                {"id": "agency-office-cost", "date": "2026-09-10", "kind": "expense", "source_reference": "candidate:cedar-studio:expense:office", "amount": "180.0000", "expected_journal": [{"account_role": "operating_expense", "debit": "180.0000", "credit": "0.0000"}, {"account_role": "bank_current", "debit": "0.0000", "credit": "180.0000"}]},
                {"id": "agency-office-correction", "date": "2026-09-29", "kind": "reversal", "source_reference": "candidate:cedar-studio:reversal:office", "amount": "180.0000", "reverses_event_id": "agency-office-cost", "expected_journal": [{"account_role": "operating_expense", "debit": "0.0000", "credit": "180.0000"}, {"account_role": "bank_current", "debit": "180.0000", "credit": "0.0000"}]},
                {"id": "agency-office-corrected", "date": "2026-09-29", "kind": "expense", "source_reference": "candidate:cedar-studio:expense:office-corrected", "amount": "165.0000", "linked_event_id": "agency-office-correction", "expected_journal": [{"account_role": "operating_expense", "debit": "165.0000", "credit": "0.0000"}, {"account_role": "bank_current", "debit": "0.0000", "credit": "165.0000"}]},
            ],
            "industry_scenarios": [{"id": "retainer-billing", "status": "future_module_scenario_not_implemented", "description": "Retainers, time capture, milestone billing and subcontractor approval need a reviewed service workflow."}],
        },
        "seasonal-business": {
            "display_name": "Sunrise Garden Services (candidate)",
            "business_type": "seasonal-landscape-services",
            "description": "Seasonal garden maintenance service with spring demand, recurring customers and a supplier payable.",
            "contacts": [
                {"id": "garden-client", "name": "Greenbank Residence (Sample)", "role": "customer", "email": "sunrise-garden.client@example.invalid", "sample": True},
                {"id": "nursery-vendor", "name": "Riverbend Nursery (Sample)", "role": "vendor", "email": "sunrise-garden.vendor@example.invalid", "sample": True},
            ],
            "products": [
                {"id": "maintenance-visit", "name": "Garden maintenance visit (Sample)", "kind": "service", "unit": "visit", "sale_price": "275.0000"},
                {"id": "planting-project", "name": "Seasonal planting project (Sample)", "kind": "service", "unit": "project", "sale_price": "2200.0000"},
            ],
            "events": [
                {"id": "seasonal-invoice", "date": "2026-04-05", "kind": "invoice", "source_reference": "candidate:sunrise-garden:invoice:2026-04", "amount": "2200.0000", "expected_journal": [{"account_role": "accounts_receivable", "debit": "2200.0000", "credit": "0.0000"}, {"account_role": "sales_revenue", "debit": "0.0000", "credit": "2200.0000"}]},
                {"id": "seasonal-receipt-partial", "date": "2026-04-20", "kind": "receipt", "source_reference": "candidate:sunrise-garden:receipt:partial", "amount": "1200.0000", "expected_journal": [{"account_role": "bank_current", "debit": "1200.0000", "credit": "0.0000"}, {"account_role": "accounts_receivable", "debit": "0.0000", "credit": "1200.0000"}]},
                {"id": "seasonal-receipt-final", "date": "2026-05-05", "kind": "receipt", "source_reference": "candidate:sunrise-garden:receipt:final", "amount": "1000.0000", "expected_journal": [{"account_role": "bank_current", "debit": "1000.0000", "credit": "0.0000"}, {"account_role": "accounts_receivable", "debit": "0.0000", "credit": "1000.0000"}]},
                {"id": "seasonal-supplier-bill", "date": "2026-04-22", "kind": "bill", "source_reference": "candidate:sunrise-garden:bill:nursery", "amount": "400.0000", "expected_journal": [{"account_role": "supplies_expense", "debit": "400.0000", "credit": "0.0000"}, {"account_role": "accounts_payable", "debit": "0.0000", "credit": "400.0000"}]},
                {"id": "seasonal-supplier-payment", "date": "2026-05-10", "kind": "supplier_payment", "source_reference": "candidate:sunrise-garden:payment:nursery", "amount": "400.0000", "expected_journal": [{"account_role": "accounts_payable", "debit": "400.0000", "credit": "0.0000"}, {"account_role": "bank_current", "debit": "0.0000", "credit": "400.0000"}]},
                {"id": "seasonal-peak-invoice", "date": "2026-08-05", "kind": "invoice", "source_reference": "candidate:sunrise-garden:invoice:2026-08", "amount": "1650.0000", "expected_journal": [{"account_role": "accounts_receivable", "debit": "1650.0000", "credit": "0.0000"}, {"account_role": "sales_revenue", "debit": "0.0000", "credit": "1650.0000"}]},
                {"id": "seasonal-peak-receipt", "date": "2026-08-20", "kind": "receipt", "source_reference": "candidate:sunrise-garden:receipt:peak-partial", "amount": "825.0000", "expected_journal": [{"account_role": "bank_current", "debit": "825.0000", "credit": "0.0000"}, {"account_role": "accounts_receivable", "debit": "0.0000", "credit": "825.0000"}]},
                {"id": "seasonal-insurance", "date": "2026-04-30", "kind": "expense", "source_reference": "candidate:sunrise-garden:expense:insurance", "amount": "150.0000", "expected_journal": [{"account_role": "insurance_expense", "debit": "150.0000", "credit": "0.0000"}, {"account_role": "bank_current", "debit": "0.0000", "credit": "150.0000"}]},
                {"id": "seasonal-fuel", "date": "2026-07-12", "kind": "expense", "source_reference": "candidate:sunrise-garden:expense:fuel", "amount": "225.0000", "expected_journal": [{"account_role": "vehicle_expense", "debit": "225.0000", "credit": "0.0000"}, {"account_role": "bank_current", "debit": "0.0000", "credit": "225.0000"}]},
            ],
            "industry_scenarios": [{"id": "seasonal-cycle", "status": "future_module_scenario_not_implemented", "description": "Seasonal scheduling, weather exposure, workforce costing and contract forecasting need separate reviewed workflows."}],
        },
        "jewelry-studio": {
            "display_name": "Lantern Finch Jewelry Studio (candidate)",
            "business_type": "jewelry-studio",
            "description": "Small studio selling finished pieces while tracking sample metal, stone and workshop inputs by teaching quantity.",
            "contacts": [
                {"id": "jewelry-customer", "name": "Aster Gallery (Sample)", "role": "customer", "email": "lantern-finch.client@example.invalid", "sample": True},
                {"id": "jewelry-supplier", "name": "North Loom Materials (Sample)", "role": "vendor", "email": "lantern-finch.vendor@example.invalid", "sample": True},
            ],
            "products": [
                {"id": "silver-pendant", "name": "Sterling pendant teaching unit (Sample)", "kind": "stock", "unit": "piece", "sale_price": "900.0000", "fixture_unit_cost": "450.0000"},
                {"id": "studio-repair", "name": "Studio repair labour (Sample)", "kind": "service", "unit": "job", "sale_price": "180.0000"},
            ],
            "locations": [{"id": "studio", "name": "Studio stock (Sample)"}],
            "events": [
                {"id": "jewelry-material-receipt", "date": "2026-06-03", "kind": "purchase", "source_reference": "candidate:lantern-finch:receipt:materials", "amount": "900.0000", "expected_journal": [{"account_role": "inventory", "debit": "900.0000", "credit": "0.0000"}, {"account_role": "accounts_payable", "debit": "0.0000", "credit": "900.0000"}], "stock_movements": [{"item_id": "silver-pendant", "location_id": "studio", "quantity_delta": "2.0000", "unit_cost": "450.0000"}]},
                {"id": "jewelry-sale", "date": "2026-06-15", "kind": "invoice", "source_reference": "candidate:lantern-finch:invoice:gallery", "amount": "1800.0000", "expected_journal": [{"account_role": "accounts_receivable", "debit": "1800.0000", "credit": "0.0000"}, {"account_role": "sales_revenue", "debit": "0.0000", "credit": "1800.0000"}, {"account_role": "cost_of_goods_sold", "debit": "900.0000", "credit": "0.0000"}, {"account_role": "inventory", "debit": "0.0000", "credit": "900.0000"}], "stock_movements": [{"item_id": "silver-pendant", "location_id": "studio", "quantity_delta": "-2.0000", "unit_cost": "450.0000"}]},
                {"id": "jewelry-receipt", "date": "2026-06-30", "kind": "receipt", "source_reference": "candidate:lantern-finch:receipt:gallery", "amount": "900.0000", "expected_journal": [{"account_role": "bank_current", "debit": "900.0000", "credit": "0.0000"}, {"account_role": "accounts_receivable", "debit": "0.0000", "credit": "900.0000"}]},
                {"id": "jewelry-return", "date": "2026-07-03", "kind": "customer_credit", "source_reference": "candidate:lantern-finch:credit:return", "amount": "900.0000", "expected_journal": [{"account_role": "sales_revenue", "debit": "900.0000", "credit": "0.0000"}, {"account_role": "accounts_receivable", "debit": "0.0000", "credit": "900.0000"}], "stock_movements": [{"item_id": "silver-pendant", "location_id": "studio", "quantity_delta": "2.0000", "unit_cost": "450.0000"}]},
                {"id": "jewelry-supplier-payment", "date": "2026-07-08", "kind": "supplier_payment", "source_reference": "candidate:lantern-finch:payment:materials", "amount": "900.0000", "expected_journal": [{"account_role": "accounts_payable", "debit": "900.0000", "credit": "0.0000"}, {"account_role": "bank_current", "debit": "0.0000", "credit": "900.0000"}]},
                {"id": "jewelry-repair-sale", "date": "2026-07-10", "kind": "invoice", "source_reference": "candidate:lantern-finch:invoice:repair", "amount": "180.0000", "expected_journal": [{"account_role": "accounts_receivable", "debit": "180.0000", "credit": "0.0000"}, {"account_role": "service_revenue", "debit": "0.0000", "credit": "180.0000"}]},
                {"id": "jewelry-repair-receipt", "date": "2026-07-20", "kind": "receipt", "source_reference": "candidate:lantern-finch:receipt:repair", "amount": "180.0000", "expected_journal": [{"account_role": "bank_current", "debit": "180.0000", "credit": "0.0000"}, {"account_role": "accounts_receivable", "debit": "0.0000", "credit": "180.0000"}]},
                {"id": "jewelry-studio-expense", "date": "2026-07-31", "kind": "expense", "source_reference": "candidate:lantern-finch:expense:workshop", "amount": "120.0000", "expected_journal": [{"account_role": "workshop_expense", "debit": "120.0000", "credit": "0.0000"}, {"account_role": "bank_current", "debit": "0.0000", "credit": "120.0000"}]},
                {"id": "jewelry-stock-count", "date": "2026-07-31", "kind": "stock_count", "source_reference": "candidate:lantern-finch:count:2026-07", "amount": "0.0000", "expected_journal": []},
            ],
            "industry_scenarios": [{"id": "jewelry-materials", "status": "future_module_scenario_not_implemented", "description": "Weight, purity, stones, hallmarking, consignment, deposits and commodity valuation are outside the current inventory contract."}],
        },
        "light-manufacturing": {
            "display_name": "Maple Bench Works (candidate)",
            "business_type": "light-manufacturing",
            "description": "Small workshop buying timber and hardware, assembling simple benches and selling finished units.",
            "contacts": [
                {"id": "manufacturing-customer", "name": "Civic Studio Interiors (Sample)", "role": "customer", "email": "maple-bench.client@example.invalid", "sample": True},
                {"id": "manufacturing-supplier", "name": "Mill Road Timber (Sample)", "role": "vendor", "email": "maple-bench.vendor@example.invalid", "sample": True},
            ],
            "products": [
                {"id": "bench-finished", "name": "Finished bench teaching unit (Sample)", "kind": "stock", "unit": "piece", "sale_price": "650.0000", "fixture_unit_cost": "300.0000"},
                {"id": "bench-service", "name": "Bench finishing service (Sample)", "kind": "service", "unit": "job", "sale_price": "120.0000"},
            ],
            "locations": [{"id": "workshop", "name": "Workshop stock (Sample)"}],
            "events": [
                {"id": "manufacturing-material-receipt", "date": "2026-07-02", "kind": "purchase", "source_reference": "candidate:maple-bench:receipt:materials", "amount": "1200.0000", "expected_journal": [{"account_role": "inventory", "debit": "1200.0000", "credit": "0.0000"}, {"account_role": "accounts_payable", "debit": "0.0000", "credit": "1200.0000"}], "stock_movements": [{"item_id": "bench-finished", "location_id": "workshop", "quantity_delta": "4.0000", "unit_cost": "300.0000"}]},
                {"id": "manufacturing-sale", "date": "2026-07-20", "kind": "invoice", "source_reference": "candidate:maple-bench:invoice:interiors", "amount": "1950.0000", "expected_journal": [{"account_role": "accounts_receivable", "debit": "1950.0000", "credit": "0.0000"}, {"account_role": "sales_revenue", "debit": "0.0000", "credit": "1950.0000"}, {"account_role": "cost_of_goods_sold", "debit": "900.0000", "credit": "0.0000"}, {"account_role": "inventory", "debit": "0.0000", "credit": "900.0000"}], "stock_movements": [{"item_id": "bench-finished", "location_id": "workshop", "quantity_delta": "-3.0000", "unit_cost": "300.0000"}]},
                {"id": "manufacturing-receipt", "date": "2026-08-05", "kind": "receipt", "source_reference": "candidate:maple-bench:receipt:partial", "amount": "1000.0000", "expected_journal": [{"account_role": "bank_current", "debit": "1000.0000", "credit": "0.0000"}, {"account_role": "accounts_receivable", "debit": "0.0000", "credit": "1000.0000"}]},
                {"id": "manufacturing-count", "date": "2026-08-31", "kind": "stock_count", "source_reference": "candidate:maple-bench:count:2026-08", "amount": "0.0000", "expected_journal": []},
                {"id": "manufacturing-supplier-payment", "date": "2026-07-15", "kind": "supplier_payment", "source_reference": "candidate:maple-bench:payment:timber", "amount": "1200.0000", "expected_journal": [{"account_role": "accounts_payable", "debit": "1200.0000", "credit": "0.0000"}, {"account_role": "bank_current", "debit": "0.0000", "credit": "1200.0000"}]},
                {"id": "manufacturing-final-receipt", "date": "2026-08-20", "kind": "receipt", "source_reference": "candidate:maple-bench:receipt:balance", "amount": "300.0000", "expected_journal": [{"account_role": "bank_current", "debit": "300.0000", "credit": "0.0000"}, {"account_role": "accounts_receivable", "debit": "0.0000", "credit": "300.0000"}]},
                {"id": "manufacturing-workshop-expense", "date": "2026-08-25", "kind": "expense", "source_reference": "candidate:maple-bench:expense:workshop", "amount": "250.0000", "expected_journal": [{"account_role": "workshop_expense", "debit": "250.0000", "credit": "0.0000"}, {"account_role": "bank_current", "debit": "0.0000", "credit": "250.0000"}]},
                {"id": "manufacturing-customer-return", "date": "2026-08-28", "kind": "customer_credit", "source_reference": "candidate:maple-bench:credit:return", "amount": "650.0000", "expected_journal": [{"account_role": "sales_revenue", "debit": "650.0000", "credit": "0.0000"}, {"account_role": "accounts_receivable", "debit": "0.0000", "credit": "650.0000"}, {"account_role": "inventory", "debit": "300.0000", "credit": "0.0000"}, {"account_role": "cost_of_goods_sold", "debit": "0.0000", "credit": "300.0000"}], "stock_movements": [{"item_id": "bench-finished", "location_id": "workshop", "quantity_delta": "1.0000", "unit_cost": "300.0000"}]},
            ],
            "industry_scenarios": [{"id": "workshop-conversion", "status": "future_module_scenario_not_implemented", "description": "Bills of material, production orders, work in progress, labour absorption and finished-goods conversion need a separate reviewed module."}],
        },
    }

    def enrich_generated_candidate(candidate_slug, candidate):
        """Add the operational evidence that makes a candidate teachable.

        The hand-authored candidates intentionally start small, but every
        generated successor should still expose the same traceable workflow:
        linked documents, partial settlement, a correction chain, a bank
        transfer and (for stock) a counted moving-average valuation.
        """
        events = candidate["events"]
        by_id = {event["id"]: event for event in events}

        for event in events:
            if event["kind"] in {"invoice", "credit_sale", "bill", "purchase"}:
                event.setdefault("document_id", event["id"])

        invoice_events = [event for event in events if event["kind"] in {"invoice", "credit_sale"}]
        credit_events = [event for event in events if event["kind"] in {"customer_credit", "sales_return"}]
        for credit in credit_events:
            earlier = [event for event in invoice_events if event["date"] <= credit["date"]]
            if earlier:
                original = earlier[-1]
                credit["original_event_id"] = original["id"]
                credit["related_document_id"] = original.get("document_id", original["id"])

        if candidate_slug == "seasonal-business":
            original = by_id["seasonal-peak-invoice"]
            events.append({
                "id": "seasonal-peak-credit",
                "date": "2026-09-02",
                "kind": "customer_credit",
                "source_reference": "candidate:sunrise-garden:credit:peak-adjustment",
                "amount": "225.0000",
                "original_event_id": original["id"],
                "related_document_id": original["document_id"],
                "expected_journal": [
                    {"account_role": "sales_revenue", "debit": "225.0000", "credit": "0.0000"},
                    {"account_role": "accounts_receivable", "debit": "0.0000", "credit": "225.0000"},
                ],
            })
            by_id["seasonal-peak-credit"] = events[-1]

        for receipt in (event for event in events if event["kind"] == "receipt"):
            earlier = [event for event in invoice_events if event["date"] <= receipt["date"]]
            if earlier:
                original = earlier[-1]
                receipt["allocations"] = [{"document_id": original.get("document_id", original["id"]), "amount": receipt["amount"]}]

        purchase_events = [event for event in events if event["kind"] in {"purchase", "bill"}]
        for payment in (event for event in events if event["kind"] == "supplier_payment"):
            earlier = [event for event in purchase_events if event["date"] <= payment["date"]]
            if earlier:
                original = earlier[-1]
                payment["allocations"] = [{"document_id": original.get("document_id", original["id"]), "amount": payment["amount"]}]

        def payment_event(event_id, event_date, reference, amount, document_id):
            return {
                "id": event_id,
                "date": event_date,
                "kind": "supplier_payment",
                "source_reference": reference,
                "amount": money(amount),
                "expected_journal": [
                    {"account_role": "accounts_payable", "debit": money(amount), "credit": "0.0000"},
                    {"account_role": "bank_current", "debit": "0.0000", "credit": money(amount)},
                ],
                "allocations": [{"document_id": document_id, "amount": money(amount)}],
            }

        payment_splits = {
            "service-agency": ("agency-supplier-payment", "2026-09-30", "2026-09-30", D("450.0000"), D("300.0000")),
            "seasonal-business": ("seasonal-supplier-payment", "2026-05-10", "2026-05-17", D("250.0000"), D("150.0000")),
            "jewelry-studio": ("jewelry-supplier-payment", "2026-07-08", "2026-07-15", D("600.0000"), D("300.0000")),
            "light-manufacturing": ("manufacturing-supplier-payment", "2026-07-15", "2026-07-22", D("800.0000"), D("400.0000")),
        }
        if candidate_slug in payment_splits:
            payment_id, first_date, final_date, first_amount, final_amount = payment_splits[candidate_slug]
            payment = by_id.get(payment_id)
            if payment is not None:
                purchase = [event for event in purchase_events if event["date"] <= payment["date"]][-1]
                document_id = purchase.get("document_id", purchase["id"])
                payment.update(payment_event(payment_id, first_date, payment["source_reference"], first_amount, document_id))
                final_id = payment_id.replace("payment", "payment-final")
                events.append(payment_event(final_id, final_date, payment["source_reference"] + ":final", final_amount, document_id))

        correction_targets = {
            "seasonal-business": ("seasonal-fuel", "2026-07-13", "2026-07-14", D("210.0000")),
            "jewelry-studio": ("jewelry-studio-expense", "2026-08-01", "2026-08-02", D("105.0000")),
            "light-manufacturing": ("manufacturing-workshop-expense", "2026-08-26", "2026-08-27", D("235.0000")),
        }
        if candidate_slug in correction_targets:
            target_id, reversal_date, corrected_date, corrected_amount = correction_targets[candidate_slug]
            target = by_id.get(target_id)
            if target is not None:
                reversal_id = target_id + "-reversal"
                corrected_id = target_id + "-corrected"
                if reversal_id not in by_id:
                    reversed_journal = [{**line, "debit": line["credit"], "credit": line["debit"]} for line in target["expected_journal"]]
                    events.append({"id": reversal_id, "date": reversal_date, "kind": "reversal", "source_reference": target["source_reference"] + ":reversal", "amount": target["amount"], "reverses_event_id": target_id, "expected_journal": reversed_journal})
                    corrected_role = next((line["account_role"] for line in target["expected_journal"] if line["account_role"] not in {"bank_current", "cash_on_hand"}), "operating_expense")
                    events.append({"id": corrected_id, "date": corrected_date, "kind": "expense", "source_reference": target["source_reference"] + ":corrected", "amount": money(corrected_amount), "linked_event_id": target_id, "expected_journal": [{"account_role": corrected_role, "debit": money(corrected_amount), "credit": "0.0000"}, {"account_role": "bank_current", "debit": "0.0000", "credit": money(corrected_amount)}]})
                    by_id[reversal_id] = events[-2]
                    by_id[corrected_id] = events[-1]

        if not any(event["kind"] == "cash_transfer" for event in events):
            transfer_dates = {"service-agency": "2026-09-29", "seasonal-business": "2026-08-25", "jewelry-studio": "2026-07-25", "light-manufacturing": "2026-08-10"}
            transfer_amount = D("250.0000")
            events.append({"id": candidate_slug + "-cash-transfer", "date": transfer_dates[candidate_slug], "kind": "cash_transfer", "source_reference": "candidate:" + candidate_slug + ":bank:cash-transfer", "amount": money(transfer_amount), "expected_journal": [{"account_role": "bank_current", "debit": money(transfer_amount), "credit": "0.0000"}, {"account_role": "cash_on_hand", "debit": "0.0000", "credit": money(transfer_amount)}]})

        stock_counts = [event for event in events if event["kind"] == "stock_count"]
        if stock_counts:
            quantities = defaultdict(lambda: {"quantity": D("0.0000"), "value": D("0.0000")})
            for event in sorted(events, key=lambda item: (item["date"], item["id"])):
                if event["kind"] == "stock_count":
                    continue
                for movement in event.get("stock_movements", []):
                    key = movement["item_id"] + "|" + movement["location_id"]
                    quantity = D(movement["quantity_delta"])
                    quantities[key]["quantity"] += quantity
                    quantities[key]["value"] += quantity * D(movement.get("unit_cost", "0.0000"))
            for count in stock_counts:
                count["valuation"] = {"method": "moving_weighted_average", "quantities": {key: {"quantity": money(value["quantity"]), "value": money(value["value"])} for key, value in quantities.items()}}

        return candidate

    candidate = candidates.get(slug)
    if candidate is None:
        return None
    enrich_generated_candidate(slug, candidate)
    validate_generated_candidate(slug, candidate)
    profile = industry_profile_for(slug)
    return {
        "source_pack_id": slug + "-generated-candidate",
        "source_pack_version": "0.1.0",
        "source_status": "generated_candidate_not_runtime_seed",
        "source_created_on": "2026-09-16",
        "review_status": "generated_candidate",
        "business_profile": {key: candidate.get(key) for key in ("display_name", "business_type", "description")},
        "semantic_accounts": [{"fixture_role": role, "mapping_status": "requires_template_snapshot"} for role in sorted({line["account_role"] for event in candidate["events"] for line in event["expected_journal"]})],
        "contacts": candidate["contacts"],
        "products": candidate["products"],
        "locations": candidate.get("locations", []),
        "opening_evidence": {"date": "2026-09-01", "status": "candidate_not_posted", "notes": "Opening quantities and balances require independent reconciliation before runtime import."},
        "operational_event_contract": candidate["events"],
        "expected_reports": candidate_expected_reports(candidate),
        "industry_scenarios": candidate["industry_scenarios"],
        "industry_profile_id": profile["id"],
        "industry_profile": profile,
    }


def validate_generated_candidate(slug, candidate):
    """Validate generated evidence before it is embedded in a successor pack.

    This is deliberately a structural/accounting-contract check, not an
    application-service replay. It prevents a candidate from being presented
    with duplicate identities, malformed dates, unbalanced expected journals,
    or unusable stock quantities.
    """
    event_ids = set()
    source_references = set()
    ar_balance = D(0)
    ap_balance = D(0)
    for event in sorted(candidate["events"], key=lambda item: (item["date"], item["id"])):
        event_id = event.get("id")
        source_reference = event.get("source_reference")
        if not isinstance(event_id, str) or not event_id or event_id in event_ids:
            raise ValueError(f"{slug}: generated event IDs must be non-empty and unique")
        if not isinstance(source_reference, str) or not source_reference or source_reference in source_references:
            raise ValueError(f"{slug}: generated source references must be non-empty and unique")
        event_ids.add(event_id)
        source_references.add(source_reference)
        try:
            event_date = date.fromisoformat(event["date"])
        except (KeyError, TypeError, ValueError) as exc:
            raise ValueError(f"{slug}: generated event {event_id} has an invalid ISO date") from exc
        if event_date.year != 2026:
            raise ValueError(f"{slug}: generated event {event_id} must remain in the open 2026 practice year")
        amount_text = event.get("amount", "0")
        amount = D(amount_text)
        if not isinstance(amount_text, str) or money(amount) != amount_text:
            raise ValueError(f"{slug}: generated event {event_id} amount must use four decimal places")
        if amount < 0:
            raise ValueError(f"{slug}: generated event {event_id} has a negative amount")
        debits = D(0)
        credits = D(0)
        control_amounts = []
        for row in event.get("expected_journal", []):
            debit = D(row.get("debit", "0"))
            credit = D(row.get("credit", "0"))
            if debit < 0 or credit < 0 or (debit != 0 and credit != 0) or debit == credit == 0:
                raise ValueError(f"{slug}: generated event {event_id} has an invalid journal line")
            debits += debit
            credits += credit
            if row.get("account_role") in {"accounts_receivable", "accounts_payable", "bank_current", "cash_on_hand"}:
                control_amounts.append(debit + credit)
            if row.get("account_role") == "accounts_receivable":
                ar_balance += debit - credit
            if row.get("account_role") == "accounts_payable":
                ap_balance += credit - debit
        if debits != credits:
            raise ValueError(f"{slug}: generated event {event_id} is not balanced")
        if event.get("kind") != "stock_count" and amount <= 0:
            raise ValueError(f"{slug}: generated event {event_id} must have a positive amount")
        if event.get("kind") != "stock_count" and control_amounts and amount not in control_amounts:
            raise ValueError(f"{slug}: generated event {event_id} amount does not match a control-account line")
        if ar_balance < 0 or ap_balance < 0:
            raise ValueError(f"{slug}: generated event {event_id} creates a negative running AR/AP balance")
        for movement in event.get("stock_movements", []):
            quantity = D(movement.get("quantity_delta", "0"))
            if quantity == 0 or not movement.get("item_id") or not movement.get("location_id"):
                raise ValueError(f"{slug}: generated event {event_id} has an invalid stock movement")
            if "unit_cost" in movement and D(movement["unit_cost"]) < 0:
                raise ValueError(f"{slug}: generated event {event_id} has a negative stock unit cost")
    return candidate


def candidate_expected_reports(candidate):
    """Project independent report expectations from a candidate event stream.

    The projection is intentionally separate from PHP posting/report code. It
    is a bounded fixture expectation used to compare a future service replay;
    it is not itself a posting implementation or a statutory report.
    """
    months = {}
    annual = {"2026": {"income": D(0), "expenses": D(0), "cash_change": D(0)}}
    ar_balance = D(0)
    ap_balance = D(0)
    cash_balance = D(0)
    inventory = defaultdict(lambda: {"quantity": D(0), "value": D(0)})
    documents = []
    document_states = {}
    as_of_text = "2026-09-30"
    revenue_roles = {"sales_revenue", "service_revenue"}
    expense_roles = {"contractor_expense", "supplies_expense", "insurance_expense", "vehicle_expense", "workshop_expense", "operating_expense"}
    for event in sorted(candidate["events"], key=lambda item: (item["date"], item["id"])):
        if event["date"] > as_of_text:
            continue
        month = event["date"][:7]
        snapshot = months.setdefault(month, {"income": D(0), "expenses": D(0), "cash_change": D(0), "receivable_balance": D(0), "payable_balance": D(0), "inventory": {}})
        kind = event["kind"]
        if kind in {"invoice", "credit_sale"}:
            document_id = event.get("document_id", event["id"])
            document_states.setdefault(document_id, {"kind": "invoice", "date": event["date"], "outstanding": D(0)})
            document_states[document_id]["outstanding"] += D(event["amount"])
        elif kind in {"bill", "purchase", "expense_bill"}:
            document_id = event.get("document_id", event["id"])
            document_states.setdefault(document_id, {"kind": "bill", "date": event["date"], "outstanding": D(0)})
            document_states[document_id]["outstanding"] += D(event["amount"])
        elif kind in {"customer_credit", "sales_return"}:
            document_id = event.get("related_document_id") or event.get("original_event_id")
            if document_id in document_states:
                document_states[document_id]["outstanding"] -= D(event["amount"])
        elif kind == "receipt":
            for allocation in event.get("allocations", []):
                document_id = allocation.get("document_id")
                if document_id in document_states:
                    document_states[document_id]["outstanding"] -= D(allocation["amount"])
        elif kind == "supplier_payment":
            for allocation in event.get("allocations", []):
                document_id = allocation.get("document_id")
                if document_id in document_states:
                    document_states[document_id]["outstanding"] -= D(allocation["amount"])
        for row in event.get("expected_journal", []):
            debit = D(row.get("debit", "0"))
            credit = D(row.get("credit", "0"))
            role = row.get("account_role")
            if role in revenue_roles:
                snapshot["income"] += credit - debit
            if role in expense_roles or role == "cost_of_goods_sold":
                snapshot["expenses"] += debit - credit
            if role in {"bank_current", "cash_on_hand"}:
                snapshot["cash_change"] += debit - credit
            if role == "accounts_receivable":
                ar_balance += debit - credit
            if role == "accounts_payable":
                ap_balance += credit - debit
        for movement in event.get("stock_movements", []):
            key = movement["item_id"] + "|" + movement["location_id"]
            quantity = D(movement["quantity_delta"])
            unit_cost = D(movement.get("unit_cost", "0"))
            inventory[key]["quantity"] += quantity
            inventory[key]["value"] += quantity * unit_cost
        snapshot["receivable_balance"] = ar_balance
        snapshot["payable_balance"] = ap_balance
        for key, balance in inventory.items():
            snapshot["inventory"][key] = {"quantity": balance["quantity"], "value": balance["value"]}
        annual["2026"]["income"] += snapshot["income"] - (months[month].get("income_before", D(0)))
        annual["2026"]["expenses"] += snapshot["expenses"] - (months[month].get("expenses_before", D(0)))
        annual["2026"]["cash_change"] += snapshot["cash_change"] - (months[month].get("cash_before", D(0)))
        snapshot["income_before"] = snapshot["income"]
        snapshot["expenses_before"] = snapshot["expenses"]
        snapshot["cash_before"] = snapshot["cash_change"]
        if event["kind"] in {"invoice", "credit_sale", "bill", "purchase", "expense_bill", "customer_credit", "sales_return", "supplier_credit"}:
            documents.append({"event_id": event["id"], "date": event["date"], "kind": event["kind"], "source_reference": event["source_reference"], "amount": D(event["amount"]), "receivable_balance_after": ar_balance, "payable_balance_after": ap_balance})
    for snapshot in months.values():
        snapshot["profit"] = snapshot["income"] - snapshot["expenses"]
        snapshot.pop("income_before", None)
        snapshot.pop("expenses_before", None)
        snapshot.pop("cash_before", None)
    annual["2026"].update({"profit": annual["2026"]["income"] - annual["2026"]["expenses"], "receivable_balance": ar_balance, "payable_balance": ap_balance, "inventory": inventory})
    ageing = {"receivables": {"current": D(0), "1_30": D(0), "31_60": D(0), "61_90": D(0), "91_plus": D(0), "total": D(0)}, "payables": {"current": D(0), "1_30": D(0), "31_60": D(0), "61_90": D(0), "91_plus": D(0), "total": D(0)}}
    as_of = date.fromisoformat(as_of_text)
    for state in document_states.values():
        balance = max(D(0), state["outstanding"])
        if balance == 0:
            continue
        days_overdue = (as_of - (date.fromisoformat(state["date"]) + timedelta(days=30))).days
        bucket = "current" if days_overdue <= 0 else ("1_30" if days_overdue <= 30 else ("31_60" if days_overdue <= 60 else ("61_90" if days_overdue <= 90 else "91_plus")))
        ageing["receivables" if state["kind"] == "invoice" else "payables"][bucket] += balance
        ageing["receivables" if state["kind"] == "invoice" else "payables"]["total"] += balance
    def serialise(value):
        if isinstance(value, Decimal):
            return money(value)
        if isinstance(value, dict):
            return {key: serialise(child) for key, child in value.items()}
        if isinstance(value, list):
            return [serialise(child) for child in value]
        return value
    return serialise({"status": "candidate_balanced_event_contract_not_runtime_replayed", "as_of_date": as_of_text, "monthly": months, "annual": annual, "ar_ap": {"receivable_balance": ar_balance, "payable_balance": ap_balance, "documents": documents}, "ageing": ageing, "inventory": inventory})


def ageing_from_authored_source(source):
    """Derive exact open-item ageing buckets from authored document balances."""
    reports = source.get("expected_reports", {})
    as_of_text = reports.get("as_of_date", source.get("business", {}).get("as_of_date", "2026-09-30"))
    as_of = date.fromisoformat(as_of_text)
    documents = {}
    documents.update({document.get("id"): document for document in source.get("opening", {}).get("documents", []) if document.get("id")})
    documents.update({document.get("id"): document for document in source.get("documents", []) if document.get("id")})
    ageing = {"receivables": {"current": D(0), "1_30": D(0), "31_60": D(0), "61_90": D(0), "91_plus": D(0), "total": D(0)}, "payables": {"current": D(0), "1_30": D(0), "31_60": D(0), "61_90": D(0), "91_plus": D(0), "total": D(0)}}
    for row in reports.get("document_balances", []):
        balance = D(row.get("outstanding", "0.0000"))
        if balance <= 0:
            continue
        document = documents.get(row.get("document_id"), {})
        due_text = document.get("due_on", as_of_text)
        days_overdue = (as_of - date.fromisoformat(due_text)).days
        bucket = "current" if days_overdue <= 0 else ("1_30" if days_overdue <= 30 else ("31_60" if days_overdue <= 60 else ("61_90" if days_overdue <= 90 else "91_plus")))
        side = "receivables" if row.get("kind") in {"invoice", "credit_note"} else "payables"
        ageing[side][bucket] += balance
        ageing[side]["total"] += balance
    return {side: {key: money(value) for key, value in buckets.items()} for side, buckets in ageing.items()}


def enrich_authored_material(slug, source):
    """Add explicitly derived evidence without changing the authored source.

    Counts are evidence-only until a reviewed count service exists. Keeping
    them in the successor contract makes the expected stock valuation visible
    without inventing a second posting or mutating the original source pack.
    """
    events = [dict(event) for event in source.get("events", [])]
    items = source.get("items", [])
    stock_items = [item for item in items if isinstance(item, dict) and item.get("kind") == "stock"]
    if stock_items and not any(event.get("kind") == "stock_count" for event in events):
        reports = source.get("expected_reports", {})
        events.append({
            "id": slug + "-stock-count-2026-09-30",
            "date": "2026-09-30",
            "kind": "stock_count",
            "description": "Counted closing stock against the pinned September valuation.",
            "runtime_status": "derived_evidence_only",
            "idempotency_key": "derived:" + slug + ":stock-count:2026-09-30",
            "expected_journal": [],
            "counted_stock": reports.get("stock_on_hand", []),
            "valuation": {"method": "moving_weighted_average", "total_value": reports.get("inventory_value", "0.0000")},
        })
    if slug == "membership-club" and not any(event.get("kind") == "reversal" for event in events):
        target = next((event for event in events if event.get("kind") == "expense"), None)
        if target is not None:
            reversed_journal = [{**line, "debit": line["credit"], "credit": line["debit"]} for line in target["expected_journal"]]
            events.extend([
                {"id": "membership-club-event-07-reversal", "date": "2026-09-19", "kind": "reversal", "description": "Reverse the club utility entry for source correction review.", "runtime_status": "derived_evidence_only", "idempotency_key": "derived:membership-club:correction:reversal", "expected_journal": reversed_journal, "reverses_event_id": target["id"]},
                {"id": "membership-club-event-07-corrected", "date": "2026-09-19", "kind": "expense", "description": "Re-enter the verified club utility amount after correction review.", "runtime_status": "derived_evidence_only", "idempotency_key": "derived:membership-club:correction:corrected", "expected_journal": target["expected_journal"], "linked_event_id": target["id"]},
            ])
    return events


def authored_material(slug):
    """Copy authored sample research into the successor pack as research evidence.

    The runtime history remains the existing supported journal/document path. These
    fields preserve the deeper business scenario without silently pretending that
    future vertical documents are already implemented by that path.
    """
    path = ROOT / "resources" / "sample-data" / f"{slug}.json"
    if not path.exists():
        return generated_candidate_material(slug)
    source = json.loads(path.read_text(encoding="utf-8"))
    business = source.get("business", {})
    opening = source.get("opening", {})
    profile = industry_profile_for(slug)
    expected_reports = dict(source.get("expected_reports", {}))
    expected_reports["ageing"] = ageing_from_authored_source(source)
    return {
        "source_pack_id": source.get("pack_id"),
        "source_pack_version": source.get("pack_version"),
        "source_status": source.get("status"),
        "source_created_on": source.get("created_on"),
        "review_status": source.get("accounting_assumptions", {}).get("fixture_status", "sample_candidate"),
        "business_profile": {
            "display_name": business.get("display_name"),
            "business_type": business.get("business_type"),
            "description": business.get("description"),
            "base_currency": business.get("base_currency"),
            "accounting_start_date": business.get("accounting_start_date"),
            "as_of_date": business.get("as_of_date"),
        },
        "semantic_accounts": source.get("accounts", []),
        "contacts": source.get("contacts", []),
        "products": source.get("items", []),
        "locations": source.get("locations", []),
        "opening_evidence": opening,
        "operational_event_contract": enrich_authored_material(slug, source),
        "expected_reports": expected_reports,
        "industry_scenarios": source.get("industry_scenarios", []),
        "industry_profile_id": profile["id"],
        "industry_profile": profile,
    }


def company_profile_for(slug, name):
    """A complete, entirely fictional seller block so printed documents are not blank (B64).

    Every address, telephone number, mailbox and registration below is invented. The
    country marker ZZ is not assigned to any country, the telephone numbers are in the
    reserved 555-01xx range, and `example.invalid` can never resolve.
    """
    streets = {
        "service-agency": "12 Lantern Yard", "retail-shop": "3 Willow Corner",
        "seasonal-business": "Sunrise Depot, Fern Lane", "distributor": "Unit 9, Harbor Reach Estate",
        "trader": "Warehouse 4, Quayside Row", "restaurant": "18 Cedar Walk",
        "membership-club": "Riverside Pavilion, Mill Path", "pharmacy": "27 Meadow Parade",
        "jewelry-studio": "Studio 6, Finch Court", "light-manufacturing": "Bench Works, Maple Trading Estate",
        "service-workshop": "5 Spoke Alley",
    }
    return {
        "legal_name": f"{name} (Sample)",
        "address_line1": streets[slug],
        "address_line2": "Northbank, Sample County",
        "address_line3": "ZZ-0001 (fictional address, country marker ZZ)",
        "phone": "+99 555 0142",
        "email": f"accounts@{slug}.example.invalid",
        "tax_registrations": "Sample registration SAMPLE-TRN-0000000. Invented for this demonstration; it is not a real tax registration and no country tax rule is applied.",
        "footer_terms": "Payment due 30 days from the invoice date. Goods remain the property of the seller until paid in full. Every figure, party and registration on this document belongs to a fictional demonstration company.",
    }


def build(slug, name, business, revenue, inventory, capability_note=None, status="released_demo_only", partners=None):
    accounts = [
        ("1010", "Reserve bank", "asset", "cash_bank"),
        ("1020", "Cash till", "asset", "cash_bank"),
        ("1030", "Petty cash", "asset", "cash_bank"),
        ("1200", "Prepaid insurance", "asset", None),
        ("1300", "Equipment at cost", "asset", None),
        ("1390", "Accumulated depreciation", "asset", None, True),
        ("1500", "Staff advances", "asset", None),
        ("2100", "Accrued staff bonus", "liability", None),
        ("2200", "Term loan", "liability", None),
        ("2300", "Net salaries payable", "liability", None),
        ("2310", "Withholding tax payable", "liability", None),
        ("2320", "Social security payable", "liability", None),
        ("2330", "Provident fund payable", "liability", None),
        ("2400", "Deferred support income", "liability", None),
        ("5100", "Fictional staff salaries", "expense", "expense"),
        ("5150", "Employer contributions", "expense", "expense"),
        ("5200", "Rent", "expense", "expense"),
        ("5300", "Utilities", "expense", "expense"),
        ("5400", "Insurance expense", "expense", "expense"),
        ("5500", "Depreciation expense", "expense", "expense"),
        ("5600", "Loan interest", "expense", "expense"),
        ("4800", "Gain or loss on asset disposal", "income", None),
    ]
    if inventory:
        accounts += [("1400", "Stock - manual support schedule", "asset", None),
                     ("5700", "Cost of sales - manual schedule", "expense", "expense")]
    for partner in (partners or []):
        accounts += [(partner["capital_code"], f"Partner capital - {partner['name']}", "equity", "owner_equity"),
                     (partner["drawings_code"], f"Partner drawings - {partner['name']}", "equity", None, True)]
    definitions = [{"code": a[0], "name": a[1], "type": a[2], "role": a[3],
                    **({"is_contra": True} if len(a) > 4 and a[4] else {})} for a in accounts]
    # Starter-chart accounts keep the numbers they carried before the structured-code conversion
    # (B56); the accounts added for contra presentation and owner transactions (B60, B61) never
    # had an older number, so a checkpoint names them by their structured code.
    OWNER_LOAN, DRAWINGS = "2-110-10001-00", "3-900-10001-00"
    types = {"1000": "asset", "1100": "asset", "2000": "liability", "3000": "equity",
             "4000": "income", "5000": "expense",
             # The advances controls (037) and the contra groups (B60) are in every starter
             # chart, so a checkpoint has to name them even while they are still empty: the
             # reconcile compares the whole trial balance, not the accounts this pack uses.
             "1-120-10001-00": "asset", "2-120-10001-00": "liability",
             "1-900-10001-00": "asset", OWNER_LOAN: "liability", DRAWINGS: "equity",
             "4-900-10001-00": "income", "5-900-10001-00": "expense",
             # Postable chart provisions must also reconcile explicitly, even when unused.
             **{a["code"]: a["type"] for a in STARTER_PROVISIONS},
             **{a[0]: a[2] for a in accounts}}
    events = []

    def journal(key, date, description, entries, reverse=False):
        rows = [line(*entry) for entry in entries]
        assert sum(D(r["debit"]) - D(r["credit"]) for r in rows) == 0, key
        # pl_ledger_text() caps a journal description at 500 characters, so a pack that
        # exceeds it builds cleanly and then fails at seed time in every pack at once, with
        # "Journal description is missing or too long" and no clue which journal. Fail here,
        # naming it, where the person writing the sentence is looking.
        assert len(description) <= 500, f"{key}: description is {len(description)} characters, over the 500 the ledger accepts"
        events.append({"key": key, "kind": "general_journal", "date": date,
                       "reference": f"{slug}/{key}", "description": description,
                       "lines": rows, "reverse": reverse})

    def owner(key, date, owner_kind, description, amount, money_code="1000", capital_code="3000",
              drawings_code=DRAWINGS, partner=None):
        """An owner movement recorded through the owner-transactions service (B61)."""
        plan = {"capital_introduced": (money_code, capital_code), "owner_loan_received": (money_code, OWNER_LOAN),
                "owner_loan_repaid": (OWNER_LOAN, money_code), "drawings": (drawings_code, money_code)}
        debit_code, credit_code = plan[owner_kind]
        owner_code = credit_code if debit_code == money_code else debit_code
        event = {"key": key, "kind": "owner_transaction", "owner_kind": owner_kind, "date": date,
                 "reference": f"{slug}/{key}", "description": description, "amount": money(D(amount)),
                 "money_code": money_code, "owner_code": owner_code,
                 "lines": [line(debit_code, amount), line(credit_code, -D(amount))]}
        if partner is not None:
            event["partner_key"] = partner
        events.append(event)

    if partners:
        # A partnership keeps a capital and a drawings account per partner (B61); no account
        # may serve two partners or two roles, so each one is separate and named.
        for partner in partners:
            owner(f"capital-{partner['key']}", "2024-01-01",
                  "capital_introduced", f"Fictional partner {partner['name']} introduces capital",
                  partner["capital"], capital_code=partner["capital_code"], partner=partner["key"])
        owner("owner-loan", "2024-06-03", "owner_loan_received", "Fictional partner lends the firm working capital, repayable", 3000)
        owner("owner-loan-repayment", "2025-06-03", "owner_loan_repaid", "Repay part of the fictional partner's loan", 1000)
        for partner in partners:
            owner(f"drawings-{partner['key']}", "2025-11-25", "drawings",
                  f"Fictional partner {partner['name']} withdraws cash for personal use",
                  partner["drawings"], drawings_code=partner["drawings_code"], partner=partner["key"])
    else:
        owner("capital", "2024-01-01", "capital_introduced", "Fictional owner introduces starting capital", 25000)
        owner("owner-loan", "2024-06-03", "owner_loan_received", "Fictional owner lends the business working capital, repayable", 3000)
        owner("owner-loan-repayment", "2025-06-03", "owner_loan_repaid", "Repay part of the fictional owner's loan", 1000)
        owner("drawings", "2025-11-25", "drawings", "Fictional owner withdraws cash for personal use", 800)
    journal("reserve-transfer", "2024-01-02", "Move funds between primary and reserve banks", [("1010", 12000), ("1000", -12000)])
    journal("cash-float", "2024-01-03", "Fund the cash till", [("1020", 600), ("1000", -600)])
    journal("petty-float", "2024-01-03", "Establish a separately counted petty cash float", [("1030", 500), ("1000", -500)])
    journal("equipment", "2024-02-01", "Equipment: 2400 cost, no residual, 60-month illustrative life from February 2024", [("1300", 2400), ("1000", -2400)])
    for year in (2024, 2025, 2026):
        journal(f"insurance-{year}", f"{year}-01-01", f"Prepay {year} insurance: 1200, released at 100 per month", [("1200", 1200), ("1000", -1200)])
    journal("loan-advance", "2024-03-01", "Fictional term-loan advance; separate principal and interest support", [("1000", 6000), ("2200", -6000)])
    for year in (2024, 2025):
        journal(f"loan-payment-{year}", f"{year}-12-20", "Annual loan instalment: principal 1200 and illustrative interest 300", [("2200", 1200), ("5600", 300), ("1000", -1500)])
    journal("bonus-accrual", "2024-12-31", "Accrue fictional staff bonus: Mira Vale 168 and Noel Reed 252; paid next January", [("5100", 420), ("2100", -420)])
    journal("bonus-payment", "2025-01-10", "Settle the prior-year staff bonus; no second salary expense", [("2100", 420), ("1000", -420)])
    journal("outstanding-sale", "2024-12-29", "Completed work for fictional Alder Customer: 800 outstanding at year end; manual receivable support", [("1100", 800), ("4000", -800)])
    journal("collect-prior-sale", "2025-02-10", "Collect Alder Customer's prior-year 800; no second income entry", [("1000", 800), ("1100", -800)])
    journal("outstanding-bill", "2025-12-29", "Fictional Harbor maintenance: 550 expense incurred, unpaid at year end; manual payable support", [("5000", 550), ("2000", -550)])
    journal("pay-prior-bill", "2026-01-10", "Pay Harbor's prior-year maintenance bill; no second expense", [("2000", 550), ("1000", -550)])
    journal("wrong-cost", "2025-08-20", "Correction exercise: 25 recorded instead of 45; this entry is reversed in full", [("5000", 25), ("1000", -25)], reverse=True)
    journal("correct-cost", "2025-08-21", "Replacement for wrong-cost: correct maintenance cost 45, original and reversal retained", [("5000", 45), ("1000", -45)])
    for year, amount in ((2024, 2500), (2025, 3000)):
        journal(f"cash-deposit-{year}", f"{year}-12-30", "Deposit counted till cash into primary bank; transfer has no income effect", [("1000", amount), ("1020", -amount)])
    # Contra presentation (B60): the reserved contra groups exist in every chart but no
    # sample used to post to them, so sales returns and purchase returns read as ordinary
    # income and expense. These two entries make the deduction visible on the statements.
    journal("sales-return", "2025-03-18", "Fictional customer returns goods and is refunded in cash: recorded in sales returns and allowances, a deduction from income rather than an expense",
            [("4-900-10001-00", 180, "Sales return, presented as a deduction from income"), ("1000", -180)])
    journal("purchase-return", "2025-04-22", "Return faulty goods to a fictional supplier and receive a cash refund: recorded in purchase returns and allowances, a deduction from purchases rather than income",
            [("1000", 120), ("5-900-10001-00", -120, "Purchase return, presented as a deduction from purchases")])
    # Anticipates 1.3. Nothing below is produced by a schedule, register or close screen;
    # each is an ordinary journal that gives the unbuilt feature something real to act on.
    journal("support-plan-advance", "2024-07-01", "Annual support plan collected a year in advance: 1200 held as deferred income and released at 100 a month to June 2025. Anticipates the deferred-income schedule planned for 1.3 (issue #94); nothing releases it automatically today",
            [("1000", 1200), ("2400", -1200, "Deferred income, released monthly by journal")])
    journal("equipment-disposal", DISPOSAL_DATE, "Sell the fictional display counter: cost 600, accumulated depreciation 200 at disposal, proceeds 350 in cash, loss on disposal 50 to non-operating income. No depreciation is charged in the month an asset leaves, so its last charge is September 2025 and the monthly charge falls from 40 to 30 from October. Anticipates the fixed-asset register in 1.3 (issue #95); nothing replays a pack through that module yet",
            [("1000", 350, "Disposal proceeds"), ("1390", 200, "Accumulated depreciation removed with the asset"),
             ("4800", 50, "Loss on disposal"), ("1300", -600, "Cost of the disposed asset")])
    journal("staff-advance-2025-09", "2025-09-05", "Advance 200 to fictional staff against September pay; recovered in full in the September payroll run",
            [("1500", 200, "Staff advance, recovered from September net pay"), ("1000", -200)])
    journal(f"payroll-{PAYROLL_MONTH}", f"{PAYROLL_MONTH}-30", "September payroll as totals per element: gross pay, employer contributions, each deduction on its own payable account and the net owed to staff. The core keeps totals only and never an employee. Anticipates the payroll journal type planned for 1.3 (issue #98)",
            [("5100", 1500, "Gross salaries and wages for the period"),
             ("5150", 120, "Employer contributions for the period"),
             ("2310", -90, "Withholding tax deducted from pay"),
             ("2320", -45, "Social security payable, employer share"),
             ("2330", -150, "Provident fund payable, employee 75 and employer 75"),
             ("1500", -200, "Staff advance recovered from net pay"),
             ("2300", -1135, "Net salaries payable to staff")])
    journal("payroll-net-payment-2025-10", "2025-10-05", "Pay the September net salaries; no second salary expense",
            [("2300", 1135), ("1000", -1135)])
    journal("payroll-deductions-2025-10", "2025-10-15", "Pay over the September payroll deductions and employer contributions element by element",
            [("2310", 90), ("2320", 45), ("2330", 150), ("1000", -285)])
    monthly_schedule = []
    for year, month in [(y, m) for y in (2024, 2025) for m in range(1, 13)] + [(2026, 1)]:
        ym = f"{year}-{month:02d}"
        gross = D(revenue[month - 1] if isinstance(revenue, list) else revenue)
        bank = gross * D("0.9")
        events.append({"key": f"receipts-{ym}", "kind": "receipt", "date": f"{ym}-15",
                       "reference": f"{slug}/receipts-{ym}", "description": f"{business}: monthly bank receipt summary; cash sales are in the separate operating schedule",
                       "amount": money(bank), "money_code": "1000", "category_code": "4000",
                       "counterparty": "Fictional monthly customer receipts"})
        entries = [("1020", gross - bank, "Cash sales per manual daily summaries"), ("4000", bank - gross)]
        # September 2025 pays its people through the payroll journal instead (#98).
        if ym != PAYROLL_MONTH:
            entries += [("5100", 600, "Mira Vale, fictional staff: illustrative salary"), ("5100", 900, "Noel Reed, fictional staff: illustrative salary"),
                        ("1000", -1500)]
        entries += [("5200", 400), ("1010", -400), ("5300", 100),
                    ("1000", -90), ("1030", -10), ("5400", 100), ("1200", -100)]
        release = 100 if ym in DEFERRED_MONTHS else 0
        if release:
            entries += [("2400", release, "Support plan earned this month"), ("4000", -release)]
        # Whole months: a full month in the month an asset enters service and none in the
        # month it leaves, so the counter is charged February 2024 to September 2025 and
        # October 2025 already carries the reduced 30.
        depreciation = 0 if ym == "2024-01" else (30 if ym >= DISPOSAL_DATE[:7] else 40)
        if depreciation:
            entries += [("5500", depreciation), ("1390", -depreciation)]
        purchases, cost, units_in, units_out, unit_cost = inventory or (0, 0, 0, 0, 0)
        if inventory:
            entries += [("1400", purchases, "Manual stock purchases"), ("1000", -purchases),
                        ("5700", cost, "Manual units sold at pinned cost"), ("1400", -cost)]
        journal(f"operations-{ym}", f"{ym}-{calendar.monthrange(year, month)[1]}",
                f"{ym} manual cash, staff, rent, utilities, prepayment and depreciation support" + ("; includes stock and cost-of-sales schedule" if inventory else ""), entries)
        monthly_schedule.append({"month": ym, "gross_receipts": money(gross), "bank_receipts": money(bank),
                                 "cash_receipts": money(gross-bank), "salaries": "1500.0000", "rent": "400.0000",
                                 "utilities": "100.0000", "insurance_release": "100.0000", "depreciation": money(depreciation),
                                 "deferred_income_release": money(release),
                                 "salary_source": "payroll_journal_by_element" if ym == PAYROLL_MONTH else "single_monthly_line",
                                 "stock_purchases": money(purchases), "cost_of_sales": money(cost),
                                 "units_in": units_in, "units_out": units_out, "unit_cost": money(unit_cost)})
    events.sort(key=lambda e: (e["date"], e["key"]))
    # Expand the authored sources independently of PHP's posting/report implementation.
    postings = []
    for event in events:
        rows = event.get("lines") or [line(event["money_code"], event["amount"]), line(event["category_code"], -D(event["amount"]))]
        postings.append((event["date"], rows))
        if event.get("reverse"):
            postings.append((event["date"], [{**r, "debit": r["credit"], "credit": r["debit"]} for r in rows]))
    checkpoints = []
    for year in (2024, 2025, 2026):
        for month in range(1, 13):
            start = f"{year}-{month:02d}-01"
            end = f"{year}-{month:02d}-{calendar.monthrange(year, month)[1]}"
            balances = defaultdict(Decimal, {code: D(0) for code in types})
            income, expense = D(0), D(0)
            for date, rows in postings:
                if date <= end:
                    for row in rows:
                        signed = D(row["debit"]) - D(row["credit"])
                        balances[row["code"]] += signed
                        if start <= date:
                            if types[row["code"]] == "income": income -= signed
                            if types[row["code"]] == "expense": expense += signed
            assert sum(balances.values()) == 0
            for code in ("1000", "1010", "1020", "1030", "1100", "1200", "1300", "1400"):
                assert balances[code] >= 0, (slug, end, code, balances[code])
            # Only chart accounts enter the checkpoint; stock is absent in service packs.
            checkpoints.append({"from": start, "to": end, "balances": {code: money(balances[code]) for code in sorted(types)},
                                "income": money(income), "expenses": money(expense), "profit": money(income-expense)})
    drafts = [{"key": "practice-receipt", "kind": "receipt", "date": "2026-02-02", "amount": "225.0000", "counterparty": "Fictional new customer", "reference": "PRACTICE-RECEIPT", "memo": "Editable practice receipt. Review before posting."},
              {"key": "practice-expense", "kind": "expense", "date": "2026-02-03", "amount": "65.0000", "counterparty": "Harbor Office Supply", "reference": "PRACTICE-EXPENSE", "memo": "Editable practice expense. No effect on books until posted."},
              {"key": "practice-petty", "kind": "expense", "date": "2026-02-04", "amount": "12.5000", "money_code": "1030", "counterparty": "Fictional local stationery", "reference": "PRACTICE-PETTY", "memo": "Compare the petty cash statement before and after posting."}]
    # 85 sources for a sole trader; a partnership splits capital and drawings per partner.
    assert len(events) + len(drafts) == 85 + len(partners or []), len(events) + len(drafts)
    # Everything below anticipates a 1.3 feature that does not exist yet. Each block is
    # derived from the same postings the checkpoints reconcile, so it can never drift from
    # the ledger, and each one says in its own words that no screen produces it today.
    year_totals = defaultdict(Decimal)
    for date, rows in postings:
        if date.startswith("2025-"):
            for row in rows:
                year_totals[row["code"]] += D(row["debit"]) - D(row["credit"])
    year_income = -sum(v for c, v in year_totals.items() if types[c] == "income")
    year_expense = sum(v for c, v in year_totals.items() if types[c] == "expense")
    closing = [{"code": c, "type": types[c], "debit": money(max(-v, 0)), "credit": money(max(v, 0))}
               for c, v in sorted(year_totals.items()) if types[c] in ("income", "expense") and v != 0]
    appropriation = [{"partner": p["name"], "profit_share": p["profit_share"],
                      "share_of_result": money((year_income - year_expense) * D(p["profit_share"]))}
                     for p in (partners or [])]
    anticipated = {
        "notice": "Authored so the 1.3 features below have real history to act on the day they land. None of this is produced by a screen, a register or a scheduler in 1.2.0; every figure here was posted by an ordinary journal in the history above.",
        "status": "future_feature_data_not_implemented",
        "schedules": {"issue": "#94", "status": "future_feature_data_not_implemented",
                      "prepaid": {"account": "1200", "instrument": "Annual insurance premium", "paid": "1200.0000",
                                  "release_per_period": "100.0000", "periods": 12,
                                  "starts": ["2024-01-01", "2025-01-01", "2026-01-01"]},
                      "accrual": {"account": "2100", "instrument": "Staff bonus accrued at 2024 year end",
                                  "accrued": "420.0000", "accrued_on": "2024-12-31", "settled_on": "2025-01-10"},
                      "deferred_income": {"account": "2400", "instrument": "Annual support plan collected in advance",
                                          "collected": "1200.0000", "collected_on": "2024-07-01",
                                          "release_per_period": "100.0000", "periods": 12,
                                          "first_release": "2024-07-31", "final_release": "2025-06-30"}},
        "asset_register": {"issue": "#95", "status": "future_feature_data_not_implemented",
                           # The 1.3 module exists, but nothing replays this block through it:
                           # the journals below still carry the figures. The shared marker means
                           # "groundwork data, not a working screen", which is still exactly true,
                           # so it stays uniform across every block and the nuance goes in `note`.
                           "convention": "A full month in the month an asset enters service, and none in the month it is disposed of",
                           "gain_loss_account_is_non_operating_income": True,
                           "note": "The 1.3 fixed-assets module posts an acquisition, each period's depreciation and a disposal itself. Replaying this block through it needs a pack-format asset section and an importer step; until that exists the journals above carry the same figures.",
                           "assets": [
                               {"reference": "SAMPLE-ASSET-1", "description": "Fictional shop fittings and equipment",
                                "cost_account": "1300", "accumulated_depreciation_account": "1390",
                                "expense_account": "5500", "acquired_on": "2024-02-01", "cost": "1800.0000",
                                "life_months": 60, "method": "straight_line", "monthly_charge": "30.0000",
                                "status": "in_service"},
                               {"reference": "SAMPLE-ASSET-2", "description": "Fictional display counter",
                                "cost_account": "1300", "accumulated_depreciation_account": "1390",
                                "expense_account": "5500", "acquired_on": "2024-02-01", "cost": "600.0000",
                                "life_months": 60, "method": "straight_line", "monthly_charge": "10.0000",
                                "disposed_on": DISPOSAL_DATE, "accumulated_at_disposal": "200.0000",
                                "proceeds": "350.0000", "result_on_disposal": "-50.0000",
                                "result_account": "4800", "status": "disposed"}]},
        "loan_schedule": {"issue": "#96", "status": "future_feature_data_not_implemented",
                          "liability_account": "2200", "interest_account": "5600", "bank_account": "1000",
                          "principal": "6000.0000", "advanced_on": "2024-03-01", "method": "flat_rate",
                          "instalments": [{"number": n, "due_on": f"{2024 + n - 1}-12-20", "principal": "1200.0000",
                                           "interest": "300.0000", "total": "1500.0000",
                                           "closing_balance": money(D(6000) - D(1200) * n),
                                           "posted": n <= 2} for n in range(1, 6)],
                          "note": "Two instalments are posted in the history. The remaining three are schedule data only; nothing generates them today."},
        "payroll": {"issue": "#98", "status": "future_feature_data_not_implemented",
                    "period": PAYROLL_MONTH, "posted_on": f"{PAYROLL_MONTH}-30", "detail_held": "totals_only_never_an_employee",
                    "elements": [{"element": "Gross salaries and wages", "account": "5100", "debit": "1500.0000"},
                                 {"element": "Employer contributions", "account": "5150", "debit": "120.0000"},
                                 {"element": "Withholding tax payable", "account": "2310", "credit": "90.0000"},
                                 {"element": "Social security payable", "account": "2320", "credit": "45.0000"},
                                 {"element": "Provident fund payable", "account": "2330", "credit": "150.0000"},
                                 {"element": "Staff advance recovered", "account": "1500", "credit": "200.0000"},
                                 {"element": "Net salaries payable", "account": "2300", "credit": "1135.0000"}],
                    "settlements": [{"date": "2025-10-05", "element": "Net salaries payable", "account": "2300", "amount": "1135.0000"},
                                    {"date": "2025-10-15", "element": "Deductions and contributions", "accounts": ["2310", "2320", "2330"], "amount": "285.0000"}]},
        "year_end": {"issue": "#97", "status": "future_feature_data_not_implemented",
                     "fiscal_year": {"from": "2025-01-01", "to": "2025-12-31"},
                     "year_state": "closeable_but_not_closed",
                     "legal_form": "partnership" if partners else "sole_trader",
                     "income": money(year_income), "expenses": money(year_expense),
                     "net_result": money(year_income - year_expense),
                     "closing_journal_preview": closing,
                     "result_goes_to": ([{"partner": p["name"], "current_account": p["capital_code"],
                                          "profit_share": p["profit_share"]} for p in partners]
                                        if partners else [{"account": "3000", "basis": "sole trader capital"}]),
                     "partner_appropriation": appropriation,
                     "drawings_to_close": ([{"partner": p["name"], "account": p["drawings_code"], "amount": money(D(p["drawings"]))}
                                            for p in partners]
                                           if partners else [{"account": DRAWINGS, "amount": "800.0000"}]),
                     "note": "The 2025 periods are closed, which blocks backdated posting. No closing journal has been posted and no year is locked: 1.2.0 has neither, and the balances above are what a year-end close would have to work from."},
        "period_close": {"issue": "#93", "status": "future_feature_data_not_implemented",
                         "reversing_journal_candidate": {"original": "bonus-accrual", "posted_on": "2024-12-31",
                                                         "would_reverse_on": "2025-01-01",
                                                         "settled_instead_by": "bonus-payment"},
                         "cash_count_candidates": [{"account": "1020", "name": "Cash till"},
                                                   {"account": "1030", "name": "Petty cash"}],
                         "note": "There is no reverse-on flag and no cash-count document in 1.2.0; these name the history a close checklist would ask about."},
    }
    if partners:
        anticipated["partners"] = [{"key": p["key"], "name": p["name"], "profit_share": p["profit_share"],
                                    "capital_account": p["capital_code"], "drawings_account": p["drawings_code"],
                                    "capital_introduced": money(D(p["capital"])), "drawings": money(D(p["drawings"]))}
                                   for p in partners]
        anticipated["partners_note"] = "The partner register and the per-partner capital and drawings accounts are real 1.2.0 features and are created by the loader. Only the appropriation of the year's result between them is future data."
    authored = authored_material(slug)
    profile = industry_profile_for(slug)
    runtime_note = "The pinned ledger history below is reconciled through the current supported general-journal and cash-document services. On isolated sample creation, the operational contract is replayed through the existing AR/AP, Purchasing, Inventory and general-journal services; unsupported vertical operations remain explicitly staged."
    is_generated_candidate = authored is not None and authored.get("source_status") == "generated_candidate_not_runtime_seed"
    if authored and not is_generated_candidate:
        runtime_note += " The authored contract is retained as the sample source record and its supported bookkeeping events are replayable; location transfers, advanced revenue timing and other specialist controls remain evidence-only."
    elif is_generated_candidate:
        runtime_note += " This generated candidate contract is sample source material; specialist operational controls remain outside the accounting replay."
    else:
        runtime_note += " No operational source pack is currently available for this successor; the successor remains a generic history-only sample."
    return {"id": slug, "version": "1.0.0", "name": name, "business": business, "demo_only": True,
            "status": status, "sample": True,
            "capability_note": capability_note or "This sample demonstrates bookkeeping examples only; it is not an implementation of the named industry's operational, regulatory or compliance systems.",
            "source_material": {"description": "Reconciled successor of sample teaching material; no real customer data.",
                                "runtime_note": runtime_note,
                                "industry_profile_id": profile["id"],
                                "industry_profile": profile,
                                "research_status": ("available" if authored.get("source_status") != "generated_candidate_not_runtime_seed" else "generated_candidate") if authored else "missing_authored_source_pack",
                                "research_evidence": authored},
            "scenario": scenario_for(slug),
            "notice": "Original sample general-ledger examples. Manual staff, loan, outstanding-item and stock schedules do not implement payroll, AR/AP, inventory or tax modules. Period closure blocks posting; it is not statutory financial-statement approval or an earnings-transfer journal.",
            "start_date": "2024-01-01", "history_end": "2025-12-31", "practice_end": "2026-12-31",
            "source_count": len(events) + len(drafts), "journal_count": len(postings), "draft_count": len(drafts),
            "company_profile": company_profile_for(slug, name),
            "partners": [{"key": p["key"], "name": p["name"], "profit_share": p["profit_share"],
                          "capital_code": p["capital_code"], "drawings_code": p["drawings_code"],
                          "capital_introduced": money(D(p["capital"])), "drawings": money(D(p["drawings"]))}
                         for p in (partners or [])],
            "anticipated_1_3": anticipated,
            "accounts": definitions, "events": events, "drafts": drafts, "monthly_support": monthly_schedule,
            "checkpoints": checkpoints}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--check", action="store_true")
    args = parser.parse_args()
    # Cedar Studio is the partnership: two partners, a recorded 60/40 profit share, and a
    # separate capital and drawings account each (B61). Every other sample is a sole trader.
    cedar_partners = [
        {"key": "mira-vale", "name": "Mira Vale (Sample)", "profit_share": "0.600000",
         "capital_code": "3100", "drawings_code": "3910", "capital": 15000, "drawings": 480},
        {"key": "noel-reed", "name": "Noel Reed (Sample)", "profit_share": "0.400000",
         "capital_code": "3110", "drawings_code": "3920", "capital": 10000, "drawings": 320},
    ]
    packs = [build("service-agency", "Cedar Studio", "Professional services / agency", 3000, None, partners=cedar_partners),
             build("retail-shop", "Willow Corner Shop", "Retail shop", 4500, (1050, 1000, 210, 200, 5)),
             build("seasonal-business", "Sunrise Garden Services", "Seasonal business", [1200, 1200, 2500, 4500, 5500, 6000, 6000, 5500, 4500, 2500, 1200, 1200], None),
             build("distributor", "Harbor Supply Company", "Distribution", 6000, (1700, 1600, 170, 160, 10)),
             build("trader", "Harbour Trade", "Wholesale / trader", 5200, (1200, 1100, 120, 110, 10), status="preview_only"),
             build("restaurant", "Cedar Table", "Restaurant / cafe", 4100, (650, 600, 65, 60, 10), "Restaurant and catering bookkeeping example; tables, recipes, kitchen production and service operations are not implemented.", "preview_only"),
             build("membership-club", "Riverside Community Club", "Membership club", 2800, None, "Membership-income bookkeeping example; membership administration, renewals, charity status and dues compliance are not implemented.", "preview_only"),
             build("pharmacy", "Meadow Training Pharmacy", "Non-medicinal training inventory", 3600, (900, 850, 90, 85, 10), "Non-medicinal training products only; no medicines, dispensing, patient records, expiry controls or pharmacy compliance are implemented.", "preview_only"),
             build("jewelry-studio", "Lantern Finch Jewelry Studio", "Jewelry studio", 3300, (700, 650, 70, 65, 10), "Jewelry bookkeeping example; precious-metal, gemstone, job-costing and commodity accounting are not implemented.", "preview_only"),
             build("light-manufacturing", "Maple Bench Works", "Light manufacturing", 4700, (1100, 1000, 110, 100, 10), "Light-manufacturing bookkeeping example; bills of material, production planning, work orders and recipes are not implemented.", "preview_only"),
             build("service-workshop", "Wheel & Spoke Workshop", "Service workshop", 3100, (500, 450, 50, 45, 10), "Workshop bookkeeping example; customer-owned property is not inventory, and job cards, parts custody and workshop scheduling are not implemented.", "preview_only")]
    files = {}
    catalog = []
    for pack in packs:
        filename = f"{pack['id']}-{pack['version']}.json"
        content = json.dumps(pack, indent=2, ensure_ascii=False) + "\n"
        files[filename] = content
        catalog.append({k: pack[k] for k in ("id", "version", "name", "business", "source_count", "journal_count", "draft_count", "status", "capability_note", "scenario")} | {"file": filename, "sha256": hashlib.sha256(content.encode()).hexdigest()})
    files["catalog.json"] = json.dumps(catalog, indent=2) + "\n"
    DEST.mkdir(parents=True, exist_ok=True)
    for filename, content in files.items():
        path = DEST / filename
        if args.check:
            if not path.exists() or path.read_text(encoding="utf-8") != content:
                raise SystemExit(f"Fixture differs: {path.relative_to(ROOT)}")
        else:
            path.write_text(content, encoding="utf-8", newline="\n")
    print(f"{'Verified' if args.check else 'Built'} {len(packs)} original packs: " + ", ".join(f"{p['id']} {p['source_count']} sources / {len(p['checkpoints'])} month checkpoints" for p in packs))


if __name__ == "__main__":
    main()
