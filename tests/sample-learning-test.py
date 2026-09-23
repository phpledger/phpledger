"""Independent source/content checks for the 1.1 sample learning series."""
import calendar
import json
from collections import defaultdict
from decimal import Decimal as D
from pathlib import Path
import sys
import unittest

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "tools"))
from sample_pack_learning import normalize_pack


class SampleLearningTest(unittest.TestCase):
    def setUp(self):
        self.catalog = json.loads((ROOT / "resources/demo-packs/catalog.json").read_text())
        self.packs = [json.loads((ROOT / "resources/demo-packs" / p["file"]).read_text()) for p in self.catalog]

    def test_code_correction_is_a_pure_rename(self):
        starter = json.loads((ROOT / "resources/coa/core-starter-1.2.0.json").read_text())
        for entry in self.catalog:
            old = json.loads((ROOT / "resources/demo-packs" / (entry["id"] + "-1.0.0.json")).read_text())
            renamed = normalize_pack(old, starter)
            self.assertEqual([(e["key"], e["date"], e["reference"]) for e in old["events"]],
                             [(e["key"], e["date"], e["reference"]) for e in renamed["events"]])
            for before, after in zip(old["checkpoints"], renamed["checkpoints"]):
                self.assertEqual((before["income"], before["expenses"], before["profit"]),
                                 (after["income"], after["expenses"], after["profit"]))
                self.assertEqual(sorted(before["balances"].values()), sorted(after["balances"].values()))

    def test_enrichment_preserves_monthly_totals(self):
        for pack in self.packs:
            old = json.loads((ROOT / "resources/demo-packs" / (pack["id"] + "-1.0.0.json")).read_text())
            for before, after in zip(old["checkpoints"], pack["checkpoints"]):
                for field in ("income", "expenses", "profit"):
                    self.assertEqual(before[field], after[field], (pack["id"], before["to"], field))
                for code, amount in before["balances"].items():
                    self.assertEqual(amount, after["balances"][pack["code_aliases"].get(code, code)])

    def test_chapters_resolve_authored_sources_and_cover_quarters(self):
        cedar = next(p for p in self.packs if p["id"] == "service-agency")
        story = cedar["learning_story"]
        self.assertEqual(25, len(story["chapters"]))
        self.assertEqual([f"{y}-{m:02d}" for y in (2024, 2025) for m in range(1, 13)],
                         [c["id"] for c in story["chapters"] if c["period"] == "history"])
        events = {e["key"]: e for e in cedar["events"]}
        quarter = defaultdict(int)
        for chapter in story["chapters"]:
            for source in chapter["events"]:
                event = events[source["key"]]
                self.assertEqual(event["reference"], source["reference"])
                self.assertEqual(event["description"], source["description"])
                self.assertTrue(event["date"].startswith(chapter["month"]))
                if "amount" in source:
                    self.assertEqual(event["amount"], source["amount"])
                if source["key"].startswith("story-"):
                    quarter[(event["date"][:4], (int(event["date"][5:7]) - 1) // 3)] += 1
        self.assertEqual(8, len(quarter))
        self.assertTrue(all(n >= 2 for n in quarter.values()))

    def test_chart_headings_and_empty_structures(self):
        starter = json.loads((ROOT / "resources/coa/core-starter-1.2.0.json").read_text())
        headings = {a["code"] for a in starter["headings"]}
        for pack in self.packs:
            structure = json.loads((ROOT / "resources/sample-structures" / (pack["id"] + "-" + pack["version"] + ".json")).read_text())
            known_headings = headings | {a["code"] for a in pack["account_headings"]}
            self.assertFalse(set(structure) & {"events", "drafts", "checkpoints", "journals", "documents"})
            for account in structure["accounts"]:
                self.assertRegex(account["code"], r"^[1-5]-[0-9]{3}-[0-9]{5}-[0-9]{2}$")
                self.assertIn(account["code"][:5] + "-00000-00", known_headings)
                self.assertFalse(set(account) & {"balance", "opening_balance", "debit", "credit"})

    def test_explicit_parties_and_original_profiles(self):
        self.assertEqual(11, len(self.packs))
        self.assertEqual(11, len({p["learning_story"]["logo"]["mark"] for p in self.packs}))
        for pack in self.packs:
            parties = {p["key"] for p in pack["document_parties"]}
            for event in pack["events"] + pack["drafts"]:
                if event["kind"] in {"receipt", "expense", "story_invoice", "story_collection"}:
                    self.assertIn(event["party_key"], parties)
            self.assertTrue(pack["learning_story"]["fictional"])
            self.assertEqual("complete_history" if pack["id"] == "service-agency" else "profile_only", pack["learning_story"]["status"])

    def test_authored_money_classification_survives_structure_import(self):
        for pack in self.packs:
            expected = {pack["code_aliases"][code]: kind for code, kind in
                        {"1000": "bank", "1010": "bank", "1020": "physical", "1030": "physical"}.items()}
            self.assertEqual(expected, pack["money_account_kinds"])
            structure = json.loads((ROOT / "resources/sample-structures" / (pack["id"] + "-" + pack["version"] + ".json")).read_text())
            self.assertEqual(expected, structure["money_account_kinds"])
            for account in structure["accounts"]:
                if account.get("role") == "cash_bank":
                    self.assertIn(account.get("money_kind"), {"physical", "bank"})

    def test_daily_cash_timeline_not_just_month_end(self):
        for pack in self.packs:
            cash_codes = {pack["code_aliases"]["1020"], pack["code_aliases"]["1030"]}
            dates = defaultdict(lambda: defaultdict(D))
            for event in pack["events"]:
                for line in event.get("lines", []):
                    if line["code"] in cash_codes and not event.get("reverse"):
                        dates[event["date"]][line["code"]] += D(line["debit"]) - D(line["credit"])
            balances = defaultdict(D)
            for date, changes in sorted(dates.items()):
                for code, change in changes.items():
                    balances[code] += change
                    self.assertGreaterEqual(balances[code], 0, (pack["id"], date, code, balances[code]))

    def test_six_unfunded_operational_transfers_are_visible_unposted_evidence(self):
        expected = {"retail-shop": "76.0000", "seasonal-business": "250.0000", "pharmacy": "70.0000",
                    "jewelry-studio": "250.0000", "light-manufacturing": "250.0000", "service-workshop": "45.0000"}
        found = {}
        for pack in self.packs:
            for issue in pack["validation_issues"]:
                if issue["code"] != "cash_shortfall":
                    continue
                self.assertEqual("staged_not_posted", issue["status"])
                self.assertEqual("cash_shortfall", issue["code"])
                self.assertEqual(D(issue["required"]) - D(issue["available"]), D(issue["shortfall"]))
                event = next(e for e in pack["source_material"]["research_evidence"]["operational_event_contract"] if e["id"] == issue["event_id"])
                self.assertEqual(issue, event["validation_issue"])
                self.assertEqual("cash_transfer", event["kind"])
                found[pack["id"]] = issue["shortfall"]
        self.assertEqual(expected, found)

    def test_zero_bank_floor_stages_sixteen_outflows_and_three_dependent_reversals(self):
        expected_counts = {"service-agency": 1, "retail-shop": 4, "distributor": 2, "trader": 2,
                           "restaurant": 1, "membership-club": 2, "pharmacy": 1, "light-manufacturing": 2, "service-workshop": 1}
        counts, reversals = {}, []
        for pack in self.packs:
            old = json.loads((ROOT / "resources/demo-packs" / (pack["id"] + "-1.0.0.json")).read_text(encoding="utf-8"))
            old_events = {e["id"]: e for e in old["source_material"]["research_evidence"]["operational_event_contract"]}
            for issue in pack["validation_issues"]:
                if issue["code"] == "source_not_posted":
                    reversals.append((pack["id"], issue["event_id"], issue["related_event_id"]))
                if issue["code"] != "bank_shortfall":
                    continue
                self.assertEqual("0.0000", issue["overdraft_limit"])
                self.assertEqual("staged_not_posted", issue["status"])
                self.assertGreater(D(issue["required"]), D(issue["available"]))
                event = next(e for e in pack["source_material"]["research_evidence"]["operational_event_contract"] if e["id"] == issue["event_id"])
                self.assertEqual(old_events[event["id"]]["expected_journal"], event["expected_journal"])
                self.assertEqual(old_events[event["id"]].get("source_reference"), event.get("source_reference"))
                counts[pack["id"]] = counts.get(pack["id"], 0) + 1
        self.assertEqual(expected_counts, counts)
        self.assertEqual([("service-agency", "agency-office-correction", "agency-office-cost"),
                          ("retail-shop", "retail-shop-event-10", "retail-shop-event-09"),
                          ("membership-club", "membership-club-event-07-reversal", "membership-club-event-07")], reversals)


if __name__ == "__main__":
    unittest.main()
