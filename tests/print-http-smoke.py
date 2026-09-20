"""Exercise the shell-less document print route through local HTTP (1.2 M2).

Only http://127.0.0.1:18200 and its existing local Compose database are used.
Creates independent owner/accountant/viewer identities in memory and one posted
customer receipt through the ordinary settlement service; printing itself is a
read-only GET, so nothing is posted by this check. No payment, message or
provider call is made.
"""
from __future__ import annotations

import base64
import importlib.util
import json
from pathlib import Path
import secrets
import sys
import uuid
from urllib.parse import urlparse

spec = importlib.util.spec_from_file_location("print_accounting_http", Path(__file__).with_name("accounting-http-smoke.py"))
accounting = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = accounting
spec.loader.exec_module(accounting)
http = accounting.http

SHELL_MARKERS = ["shell-sidebar", "shell-topbar", "shell-main", "shell-nav", "skip-link", "class=\"crumbs\"", "assets/app.js"]


def settlement(fixture: dict, private: dict) -> dict:
    """Post one customer receipt and add an accountant, through the ordinary services."""
    payload = base64.b64encode(json.dumps(fixture | private).encode()).decode("ascii")
    return accounting.php_local("$p = json_decode(base64_decode('" + payload + "'), true, 512, JSON_THROW_ON_ERROR);\n" + r'''
$result = pl_ledger_transaction(function () use ($p): array {
    $accountant = pl_create_user($p['accountant_email'], 'Sample print accountant', $p['accountant_password']);
    DB::insert('pl_company_members', ['company_id' => $p['company_id'], 'user_id' => $accountant, 'role' => 'accountant']);
    $party = pl_save_party($p['owner_id'], $p['company_id'], $p['book_id'], ['legal_name' => 'Sample print customer',
        'entity_type' => 'private_company', 'country_code' => 'GB', 'is_customer' => true, 'is_vendor' => false,
        'currency' => 'USD', 'request_key' => bin2hex(random_bytes(16)), 'reason' => 'Sample print fixture']);
    $control = (int) DB::queryFirstField('SELECT id FROM pl_accounts WHERE book_id = %i AND role = %s', $p['book_id'], 'receivables');
    pl_activate_open_item_account($p['owner_id'], $p['company_id'], $p['book_id'], $control, 'Sample print fixture activation');
    $item = pl_open_item_recognize($p['owner_id'], $p['company_id'], $p['book_id'], ['party_id' => $party['id'],
        'control_account_id' => $control, 'offset_account_id' => $p['accounts']['4000'], 'currency' => 'USD',
        'amount_fc' => '150', 'date' => '2026-02-02', 'rate' => '1', 'source_reference' => 'print-smoke-' . bin2hex(random_bytes(8)),
        'description' => 'Sample recognised receivable', 'idempotency_key' => bin2hex(random_bytes(16))]);
    $posted = pl_settle_open_items($p['owner_id'], $p['company_id'], $p['book_id'], ['party_id' => $party['id'],
        'direction' => 'receivable', 'date' => '2026-02-20', 'bank_account_id' => $p['accounts']['1000'],
        'amount_fc' => '150', 'description' => 'Sample customer receipt for printing',
        'idempotency_key' => bin2hex(random_bytes(16)), 'allocations' => [['item_id' => $item['item_id'], 'amount_fc' => '150']]]);
    return ['journal_id' => (int) $posted['journal_id'], 'accountant_id' => $accountant];
});
echo json_encode($result, JSON_THROW_ON_ERROR);
''')


def run() -> dict:
    fixture, private = accounting.fixture()
    actor, company, book = (fixture[k] for k in ("owner_id", "company_id", "book_id"))
    accounting.php_local(
        f"$p=pl_preview_opening({actor},{company},{book}, ['cutover_date'=>'2026-01-01','source'=>'Sample print acceptance',"
        "'balances'=>[],'unpaid_documents'=>[],'zero_confirmed'=>true], 'print-opening');"
        f"pl_confirm_opening({actor},{company},{book}, (int)$p['id'], $p['payload_hash'],true);echo json_encode(['confirmed'=>true]);"
    )
    private["accountant_email"] = f"print-accountant-{uuid.uuid4().hex}@example.test"
    private["accountant_password"] = secrets.token_urlsafe(32)
    posted = settlement(fixture, private)
    journal, other_company = posted["journal_id"], fixture["other_company_id"]
    checks = []

    def check(label, condition):
        if not condition:
            raise AssertionError(label)
        checks.append(label)
        print("PASS:", label, flush=True)

    def login(role):
        client = http.Session()
        page = client.request("/login")
        page = client.submit(page.markup.form_for("/login"), {"email": private[role + "_email"], "password": private[role + "_password"]})
        chosen = next(f for f in page.markup.forms if urlparse(f.action).path == "/company/select" and f.fields.get("company_id") == str(company))
        client.submit(chosen)
        return client

    printed = f"/print/settlement/{journal}"
    anonymous = http.Session()
    check("Printing requires authentication", urlparse(anonymous.request(printed).url).path == "/login")

    owner, accountant, viewer = login("owner"), login("accountant"), login("viewer")
    page = owner.request(printed)
    check("Owner prints the settlement receipt", page.status == 200 and "Payment receipt" in page.body and "Sample print customer" in page.body)
    check("The default format is A4", 'paper-a4' in page.body and 'paper-80mm' not in page.body)
    check("The print carries the company letterhead", fixture["company_name"] in page.body)
    check("The print shows the allocated amount", "150.00" in page.body)
    check("Rendered print output carries no application shell", not any(marker in page.body for marker in SHELL_MARKERS))
    check("The record screen stays one click away", page.markup.link_for("/journals/detail") is not None)

    roll = owner.request(printed + "?format=80mm")
    check("The 80 mm roll format renders", roll.status == 200 and "paper-80mm" in roll.body)
    check("The 80 mm roll carries no application shell", not any(marker in roll.body for marker in SHELL_MARKERS))

    check("Accountant prints the same receipt", accountant.request(printed).status == 200)
    check("Viewer printing follows the record screen", viewer.request(printed).status == owner.request(f"/journals/detail?id={journal}").status == 200)

    check("An id outside this company's book is refused", owner.request(f"/print/settlement/{journal + 10**6}").status == 403)
    stranger = http.Session()
    strangers_page = stranger.request("/login")
    stranger.submit(strangers_page.markup.form_for("/login"), {"email": private["viewer_email"], "password": private["viewer_password"]})
    switched = next(f for f in stranger.request("/companies").markup.forms if urlparse(f.action).path == "/company/select" and f.fields.get("company_id") == str(other_company))
    stranger.submit(switched)
    check("The same id in another company's scope is refused", stranger.request(printed).status == 403)

    check("An unknown document type is refused", owner.request("/print/invoice/1").status == 403)
    check("An unknown paper format is refused", owner.request(printed + "?format=a3").status == 403)
    check("A type outside the route shape is not found", owner.request("/print/Settlement/1").status == 404)
    check("A non-settlement journal is refused", owner.request("/print/settlement/1").status in (403, 404))
    check("Printing accepts no POST", owner.request(printed, {"csrf": "none"}).status == 405)

    return {"passed": len(checks), "failed": 0, "target": http.ORIGIN, "company_id": company, "book_id": fixture["book_id"],
            "journal_id": journal, "print_url": printed, "external_calls": False, "production_changed": False}


if __name__ == "__main__":
    print(json.dumps(run(), indent=2))
