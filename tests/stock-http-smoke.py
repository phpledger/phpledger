"""Exercise the stock-document screens and prints through local HTTP (1.2 M4).

Covers the four screens this module adds — `/stock-documents`,
`/stock-documents/detail`, `/stock-documents/settlement` and
`/reports/stock-by-location` — plus the two print types registered for them, at
the HTTP level: who may read each screen, what a viewer sees, that cost columns
follow their own permission, that a gate pass raised over HTTP moves no stock
and posts nothing, that the report's rendered totals equal the service's, that
an id from another company is refused on every new route, and that print output
carries no application shell.

Only a loopback address and the local Compose database are used. Independent
owner, accountant and viewer identities and two isolated sample businesses are
created in memory; generated passwords stay in memory and stdin. Nothing is
reset or deleted, no payment, message or provider call is made, and no
production or customer book is touched.

    python tests/stock-http-smoke.py

The committed default target is http://127.0.0.1:18200 with the default Compose
project, exactly like the other smoke scripts. `--port` and `--project` exist
for an explicitly isolated local stack (a parallel worktree, as
`pos-recovery-http-smoke.py` documents for its own compose override); both stay
loopback-only and both refuse anything that is not 127.0.0.1.
"""
from __future__ import annotations

import argparse
import base64
import importlib.util
import json
from pathlib import Path
import secrets
import subprocess
import sys
import uuid
from urllib.parse import urljoin, urlparse

spec = importlib.util.spec_from_file_location("stock_accounting_http", Path(__file__).with_name("accounting-http-smoke.py"))
accounting = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = accounting
spec.loader.exec_module(accounting)
http = accounting.http

# The application shell must never appear in a print document.
SHELL_MARKERS = ["shell-sidebar", "shell-topbar", "shell-main", "shell-nav", "skip-link", "class=\"crumbs\"", "assets/app.js"]

COMPOSE: list[str] = ["docker", "compose"]


def pin_loopback_port(port: int) -> None:
    """Point the shared client at one loopback port and keep its non-local guard.

    The shared helper's committed target is 127.0.0.1:18200. A parallel worktree
    cannot bind that port, so this rebinds the origin for this process only. The
    replacement is still loopback-only: any host but 127.0.0.1, any scheme but
    http, or any other port is refused, for requests and for redirects alike.
    """
    origin = f"http://127.0.0.1:{port}"

    def local_url(value: str) -> str:
        result = urljoin(origin, value)
        parsed = urlparse(result)
        if (parsed.scheme, parsed.hostname, parsed.port) != ("http", "127.0.0.1", port):
            raise RuntimeError("HTTP smoke checks refuse non-local targets or redirects.")
        return result

    http.ORIGIN = origin
    http.local_url = local_url


def php_local(source: str) -> dict:
    """Run one read/fixture script inside the local web service of this stack."""
    result = subprocess.run(
        COMPOSE + ["exec", "-T", "web", "php"],
        input="<?php\nrequire 'www/phpledger/includes/bootstrap.php';\n"
        "if (getenv('PL_ENV') !== 'local' || getenv('PL_DB_NAME') !== 'phpledger' || getenv('PL_DB_HOST') !== 'db') { throw new RuntimeException('Local development database required.'); }\n"
        + source,
        text=True, cwd=http.REPO, capture_output=True, timeout=120,
    )
    if result.returncode != 0:
        # Do not surface submitted fixture credentials or server response bodies.
        raise RuntimeError("Local sample fixture/read check failed; verify migrations and local service logs.")
    return json.loads(result.stdout)


def payload(data: dict) -> str:
    return "$p = json_decode(base64_decode('" + base64.b64encode(json.dumps(data).encode()).decode("ascii") + "'), true, 512, JSON_THROW_ON_ERROR);\n"


def fixture(private: dict) -> dict:
    """One distribution business: a warehouse with stock, a van, an issue, a gate pass.

    Everything is created through the ordinary services, so this fixture proves
    nothing by itself; the HTTP checks below are what is being verified.
    """
    return php_local(payload(private) + r'''
$result = pl_ledger_transaction(function () use ($p): array {
    $owner = pl_create_user($p['owner_email'], 'Sample stock owner', $p['owner_password']);
    $accountant = pl_create_user($p['accountant_email'], 'Sample stock accountant', $p['accountant_password']);
    $viewer = pl_create_user($p['viewer_email'], 'Sample stock viewer', $p['viewer_password']);
    $company = pl_setup_company($owner, ['name' => $p['name'], 'currency' => 'USD', 'start_date' => '2026-01-01',
        'fiscal_year_end' => '12-31', 'start_mode' => 'existing', 'template_digest' => pl_starter_template()['digest']], bin2hex(random_bytes(20)));
    $other = pl_setup_company($owner, ['name' => $p['other_name'], 'currency' => 'USD', 'start_date' => '2026-01-01',
        'fiscal_year_end' => '12-31', 'start_mode' => 'existing', 'template_digest' => pl_starter_template()['digest']], bin2hex(random_bytes(20)));
    foreach ([$accountant => 'accountant', $viewer => 'viewer'] as $user => $role) {
        DB::insert('pl_company_members', ['company_id' => $company['id'], 'user_id' => $user, 'role' => $role]);
    }
    $companyId = (int) $company['id']; $bookId = (int) $company['book_id'];
    $accounts = [];
    foreach ($company['accounts'] as $account) { $accounts[$account['code']] = (int) $account['id']; }
    $preview = pl_preview_opening($owner, $companyId, $bookId, ['cutover_date' => '2026-01-01', 'source' => 'Sample stock acceptance',
        'balances' => [], 'unpaid_documents' => [], 'zero_confirmed' => true], 'stock-opening-' . bin2hex(random_bytes(8)));
    pl_confirm_opening($owner, $companyId, $bookId, (int) $preview['id'], $preview['payload_hash'], true);
    foreach (['inventory', 'inventory-locations'] as $module) {
        $manifest = pl_module_registry()[$module];
        pl_set_company_module($owner, $companyId, $module, true, 0, $manifest['digest'], 'Sample stock acceptance', bin2hex(random_bytes(16)));
    }
    $stockAccount = pl_save_account($owner, $companyId, $bookId, ['code' => '1-001-01300-00', 'name' => 'Sample stock on hand',
        'type' => 'asset', 'role' => null, 'is_active' => true, 'reason' => 'Sample stock acceptance', 'creation_key' => bin2hex(random_bytes(16))]);
    $grni = pl_save_account($owner, $companyId, $bookId, ['code' => '2-001-02100-00', 'name' => 'Sample goods received not billed',
        'type' => 'liability', 'role' => null, 'is_active' => true, 'reason' => 'Sample stock acceptance', 'creation_key' => bin2hex(random_bytes(16))]);
    $cogs = null;
    foreach ($company['accounts'] as $account) { if ($account['type'] === 'expense' && $cogs === null) { $cogs = (int) $account['id']; } }
    $sales = null;
    foreach ($company['accounts'] as $account) { if ($account['type'] === 'income' && $sales === null) { $sales = (int) $account['id']; } }
    $products = [];
    foreach ([['SMOKE-A', 'Sample carton item', '120'], ['SMOKE-B', 'Sample loose item', '45']] as [$sku, $label, $price]) {
        $product = pl_save_inventory_product($owner, $companyId, $bookId, ['sku' => $sku . '-' . bin2hex(random_bytes(3)), 'name' => $label,
            'kind' => 'stock', 'base_unit' => 'each', 'selling_price' => $price, 'is_active' => true,
            'inventory_account_id' => (int) $stockAccount['id'], 'cogs_account_id' => $cogs, 'sales_account_id' => $sales,
            'purchase_account_id' => $cogs, 'reason' => 'Sample stock acceptance', 'idempotency_key' => bin2hex(random_bytes(16))]);
        $products[] = ['id' => (int) $product['id'], 'name' => $product['name'], 'sku' => $product['sku']];
    }
    $default = pl_inventory_default_warehouse($owner, $companyId, $bookId);
    $van = pl_save_inventory_warehouse($owner, $companyId, $bookId, ['code' => 'VAN-1', 'name' => 'Sample van one', 'kind' => 'mobile', 'driver_employee_id' => pl_save_employee($owner,$companyId,['full_name'=>'Sample driver','employment_type'=>'full_time','employment_status'=>'active','hire_date'=>'2026-01-01','reason'=>'Explicit fictional HTTP sample'])['id'],
        'driver_name' => 'Sample driver', 'vehicle_reference' => 'SAMPLE-4471', 'route_name' => 'Sample route',
        'is_active' => true, 'reason' => 'Sample stock acceptance', 'idempotency_key' => bin2hex(random_bytes(16))]);
    foreach ($products as $index => $product) {
        pl_inventory_receive($owner, $companyId, $bookId, ['product_id' => $product['id'], 'quantity' => '100', 'amount_base' => (string) (1000 + $index * 100),
            'date' => '2026-01-02', 'offset_account_id' => (int) $grni['id'], 'source_type' => 'smoke_stock',
            'source_reference' => bin2hex(random_bytes(16)), 'reason' => 'Sample opening receipt', 'idempotency_key' => bin2hex(random_bytes(16))]);
    }
    $issue = pl_post_stock_document($owner, $companyId, $bookId, ['kind' => 'stock_issue', 'date' => '2026-01-06',
        'from_warehouse_id' => (int) $default['id'], 'to_warehouse_id' => (int) $van['id'], 'reference' => 'Morning load',
        'reason' => 'Sample morning load', 'idempotency_key' => bin2hex(random_bytes(16)),
        'lines' => [['product_id' => $products[0]['id'], 'quantity' => '20'], ['product_id' => $products[1]['id'], 'quantity' => '8']]]);
    $pass = pl_issue_gate_pass($owner, $companyId, $bookId, ['date' => '2026-01-06', 'warehouse_id' => (int) $default['id'],
        'direction' => 'out', 'is_returnable' => true, 'expected_return_date' => '2026-01-06', 'covers_document_id' => (int) $issue['id'],
        'party_name' => 'Sample carrier', 'vehicle_reference' => 'SAMPLE-4471', 'driver_name' => 'Sample driver',
        'purpose' => 'Sample morning gate pass', 'reason' => 'Sample gate pass', 'idempotency_key' => bin2hex(random_bytes(16))]);
    // A second business the same owner also belongs to, for the cross-company checks.
    $otherId = (int) $other['id']; $otherBook = (int) $other['book_id'];
    $otherPreview = pl_preview_opening($owner, $otherId, $otherBook, ['cutover_date' => '2026-01-01', 'source' => 'Sample stock acceptance',
        'balances' => [], 'unpaid_documents' => [], 'zero_confirmed' => true], 'stock-other-opening-' . bin2hex(random_bytes(8)));
    pl_confirm_opening($owner, $otherId, $otherBook, (int) $otherPreview['id'], $otherPreview['payload_hash'], true);
    return ['company_id' => $companyId, 'book_id' => $bookId, 'company_name' => $p['name'],
        'other_company_id' => $otherId, 'other_book_id' => $otherBook,
        'owner_id' => $owner, 'accountant_id' => $accountant, 'viewer_id' => $viewer,
        'default_warehouse_id' => (int) $default['id'], 'van_id' => (int) $van['id'],
        'issue_id' => (int) $issue['id'], 'issue_number' => $issue['document_number'],
        'pass_id' => (int) $pass['id'], 'pass_number' => $pass['document_number'],
        'products' => $products];
});
echo json_encode($result, JSON_THROW_ON_ERROR);
''')


def service_state(f: dict) -> dict:
    """What the services say, to compare the rendered screens against."""
    return php_local(payload(f) + r'''
$owner = $p['owner_id']; $company = $p['company_id']; $book = $p['book_id'];
$report = pl_stock_by_location($owner, $company, $book, '2026-01-06');
$aggregate = pl_stock_location_aggregate($owner, $company, $book, '2026-01-06');
$groups = [];
foreach ($report['groups'] as $group) {
    $groups[] = ['code' => $group['warehouse']['code'], 'kind' => $group['warehouse']['kind'],
        'quantity' => $group['totals']['quantity'], 'value_base' => $group['totals']['value_base'],
        'sale_value' => $group['totals']['sale_value'], 'items' => $group['totals']['items']];
}
echo json_encode([
    'journals' => (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id=%i', $book),
    'movements' => (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_inventory_movements WHERE book_id=%i', $book),
    'documents' => (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_stock_documents WHERE book_id=%i', $book),
    'passes' => (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_gate_passes WHERE book_id=%i', $book),
    'report' => ['quantity' => $report['totals']['quantity'], 'value_base' => $report['totals']['value_base'],
        'sale_value' => $report['totals']['sale_value'], 'items' => $report['totals']['items'],
        'locations' => $report['totals']['locations'], 'reconciles' => $report['reconciles'], 'groups' => $groups],
    'aggregate_value_base' => $aggregate['total_value_base'],
    'van_stock' => pl_van_stock_report($owner, $company, $book, '2026-01-06')['totals']['quantity'],
], JSON_THROW_ON_ERROR);
''')


def settlement_row(f: dict) -> dict:
    """The recorded settlement for this fixture's van and day, read directly."""
    return php_local(payload(f) + r'''
$row = DB::queryFirstRow('SELECT id, status FROM pl_van_settlements WHERE book_id=%i AND warehouse_id=%i AND settlement_date=%s',
    $p['book_id'], $p['van_id'], '2026-01-06');
echo json_encode(['id' => $row === null ? 0 : (int) $row['id'], 'status' => $row === null ? '' : (string) $row['status']], JSON_THROW_ON_ERROR);
''')


def money(value: str) -> str:
    """The screen's thousands-separated presentation of a four-decimal amount."""
    whole, _, fraction = value.partition(".")
    negative = whole.startswith("-")
    digits = whole.lstrip("-")
    grouped = "{:,}".format(int(digits))
    return ("-" if negative else "") + grouped + "." + (fraction + "00")[:2]


def run(port: int) -> dict:
    private = {
        "owner_email": f"stock-owner-{uuid.uuid4().hex}@example.test",
        "accountant_email": f"stock-accountant-{uuid.uuid4().hex}@example.test",
        "viewer_email": f"stock-viewer-{uuid.uuid4().hex}@example.test",
        "owner_password": secrets.token_urlsafe(32),
        "accountant_password": secrets.token_urlsafe(32),
        "viewer_password": secrets.token_urlsafe(32),
        "name": "Stock HTTP Acceptance " + uuid.uuid4().hex,
        "other_name": "Stock HTTP Unrelated " + uuid.uuid4().hex,
    }
    f = fixture(private)
    company, other_company = f["company_id"], f["other_company_id"]
    before = service_state(f)
    checks: list[str] = []

    def check(label, condition):
        if not condition:
            raise AssertionError(label)
        checks.append(label)
        print("PASS:", label, flush=True)

    def login(role, company_id=company):
        client = http.Session()
        page = client.request("/login")
        page = client.submit(page.markup.form_for("/login"), {"email": private[role + "_email"], "password": private[role + "_password"]})
        chosen = next(form for form in page.markup.forms
                      if urlparse(form.action).path == "/company/select" and form.fields.get("company_id") == str(company_id))
        client.submit(chosen)
        return client

    screens = {
        "/stock-documents": "Stock issues and returns",
        "/stock-documents/detail": "Stock issue",
        "/stock-documents/settlement": "Van settlement",
        "/reports/stock-by-location": "Stock by location",
    }
    detail = f"/stock-documents/detail?id={f['issue_id']}"
    settlement = f"/stock-documents/settlement?warehouse={f['van_id']}&date=2026-01-06"
    report = "/reports/stock-by-location?as_of=2026-01-06"
    paths = {"/stock-documents": "/stock-documents", "/stock-documents/detail": detail,
             "/stock-documents/settlement": settlement, "/reports/stock-by-location": report}

    anonymous = http.Session()
    for route, url in paths.items():
        check("Anonymous visitors are sent to sign in from " + route, urlparse(anonymous.request(url).url).path == "/login")

    owner, accountant, viewer = login("owner"), login("accountant"), login("viewer")

    # 1. Every new screen renders for the two roles the module's manifest permits.
    for role, client in (("owner", owner), ("accountant", accountant)):
        for route, heading in screens.items():
            page = client.request(paths[route])
            check(f"{role.capitalize()} reads {route}", page.status == 200 and heading in page.body)

    # 2. A viewer reads the screens and is offered no way to write on them.
    for route, heading in screens.items():
        page = viewer.request(paths[route])
        check("Viewer reads " + route, page.status == 200 and heading in page.body)
    listing = viewer.request("/stock-documents?new=stock_issue")
    check("Viewer is offered no stock-document editor",
          listing.status == 200 and not any(urlparse(form.action).path == "/stock-documents" and form.method.lower() == "post" for form in listing.markup.forms))
    check("Viewer is offered no gate-pass stamp",
          not any(urlparse(form.action).path.startswith("/stock-documents/detail") for form in viewer.request(detail).markup.forms))
    check("Viewer is offered no settlement review",
          "Review and approve" not in viewer.request(settlement).body)
    forged = viewer.request("/stock-documents", {"csrf": viewer.request("/stock-documents").markup.form_for("/logout").fields["csrf"],
                                                 "company_id": str(company), "book_id": str(f["book_id"]), "action": "stock_document",
                                                 "kind": "stock_issue", "date": "2026-01-07",
                                                 "from_warehouse_id": str(f["default_warehouse_id"]), "to_warehouse_id": str(f["van_id"]),
                                                 "reason": "Forged", "request_key": "viewer-forged-" + uuid.uuid4().hex,
                                                 "lines[0][product_id]": str(f["products"][0]["id"]), "lines[0][quantity]": "1"})
    check("A viewer's forged stock document is refused and records nothing",
          forged.status == 422 and service_state(f)["documents"] == before["documents"])
    check("A stock document POST without a valid token is refused",
          owner.request("/stock-documents", {"csrf": "invalid", "action": "stock_document"}).status == 403)
    check("A stock document POST for another company's scope is refused",
          owner.request("/stock-documents", owner.request("/stock-documents?new=stock_issue").markup.form_for("/stock-documents").fields
                        | {"company_id": str(other_company), "book_id": str(f["other_book_id"])}).status == 403)

    # 3. Cost columns follow their own permission, and are absent — not merely hidden.
    owner_report = owner.request(report)
    accountant_report = accountant.request(report)
    check("Owner sees the cost columns", "Value at cost (USD)" in owner_report.body)
    check("Accountant does not see the cost columns", "Value at cost (USD)" not in accountant_report.body)
    check("Accountant is told cost is a separate permission", "separate permission" in accountant_report.body)
    check("Viewer does not see the cost columns", "Value at cost (USD)" not in viewer.request(report).body)
    check("A withheld cost value is absent from the page, not styled away",
          money(before["report"]["value_base"]) not in accountant_report.body)
    check("Owner sees the aggregate reconciliation", "Aggregate across locations" in owner_report.body
          and money(before["aggregate_value_base"]) in owner_report.body)

    # 4. The report's rendered totals are the service's totals.
    check("Rendered grand-total quantity matches the service", before["report"]["quantity"] in owner_report.body)
    check("Rendered grand-total cost matches the service", money(before["report"]["value_base"]) in owner_report.body)
    check("Rendered grand-total sale value matches the service", money(before["report"]["sale_value"]) in owner_report.body)
    check("The report header states the service's location and item counts",
          f"{before['report']['locations']} locations" in owner_report.body and f"{before['report']['items']} item rows" in owner_report.body)
    for group in before["report"]["groups"]:
        check("Group " + group["code"] + " renders its own subtotal at the service's figures",
              group["quantity"] in owner_report.body and money(group["value_base"]) in owner_report.body)
    check("Warehouses are grouped before vans",
          owner_report.body.index("Default warehouse") < owner_report.body.index("Sample van one"))
    check("The van's driver and route appear on its group", "Sample driver" in owner_report.body and "Sample route" in owner_report.body)
    vans_only = owner.request(report + "&locations=mobile")
    check("Vans-only filters to the van and says it cannot tie to the ledger",
          vans_only.status == 200 and "Default warehouse" not in vans_only.body and before["van_stock"] in vans_only.body
          and "filtered view" in vans_only.body)
    check("An unsupported location filter is refused", owner.request(report + "&locations=orbit").status == 403)

    # 5. A gate pass raised over HTTP moves no stock and posts nothing.
    form = owner.request("/stock-documents?new=gate_pass").markup.form_for("/stock-documents")
    raised = owner.submit(form, {"date": "2026-01-06", "warehouse_id": str(f["default_warehouse_id"]), "direction": "out",
                                 "covers_document_id": str(f["issue_id"]), "is_returnable": "1",
                                 "expected_return_date": "2026-01-07", "party_name": "Sample second carrier",
                                 "vehicle_reference": "SAMPLE-9001", "driver_name": "Sample driver",
                                 "purpose": "Sample second gate pass", "reference": "Second pass",
                                 "reason": "Sample HTTP gate pass", "request_key": "smoke-pass-" + uuid.uuid4().hex})
    after = service_state(f)
    check("A gate pass raised over HTTP is recorded and numbered",
          raised.status == 200 and "GP-" in raised.body and after["passes"] == before["passes"] + 1)
    check("That gate pass moved no stock", after["movements"] == before["movements"])
    check("That gate pass posted no journal", after["journals"] == before["journals"])
    check("That gate pass created no document line", "Moves nothing" in owner.request("/stock-documents?kind=gate_pass").body)
    check("The gate pass screen says it moves nothing and posts nothing",
          "moves no stock and posts nothing" in raised.body)
    check("The report is unchanged by a gate pass",
          after["report"]["quantity"] == before["report"]["quantity"] and after["report"]["value_base"] == before["report"]["value_base"])

    # 6. The driver's day reconciles on screen and the settlement posts nothing.
    day = owner.request(settlement)
    check("The settlement sheet reconciles the loaded quantity", "This day reconciles" in day.body and "28.0000" in day.body)
    check("The settlement sheet says it posts nothing", "neither posts nor changes them" in day.body)
    # The screen carries the GET filter form and the POST review form on the same path.
    review_form = next(form for form in day.markup.forms
                       if urlparse(form.action).path == urlparse(settlement).path and form.method.lower() == "post")
    reviewed = owner.submit(review_form, {"reason": "Sample HTTP settlement review", "action": "review"})
    settled = service_state(f)
    check("Reviewing a settlement over HTTP posts nothing",
          reviewed.status == 200 and settled["journals"] == after["journals"] and settled["movements"] == after["movements"])
    check("The reviewed settlement is listed", "reviewed" in owner.request(settlement).body)
    settlement_id = settlement_row(f)["id"]
    approve = {"company_id": str(company), "book_id": str(f["book_id"]), "warehouse": str(f["van_id"]),
               "date": "2026-01-06", "action": "approve", "settlement_id": str(settlement_id),
               "reason": "Sample approval attempt", "request_key": "smoke-approve-" + uuid.uuid4().hex}
    refused = accountant.request(urlparse(settlement).path,
                                 approve | {"csrf": accountant.request(settlement).markup.form_for("/logout").fields["csrf"]})
    check("An accountant cannot approve a settlement",
          "settlement approval permission" in refused.body and settlement_row(f)["status"] == "reviewed")
    approved = owner.request(urlparse(settlement).path,
                             approve | {"csrf": owner.request(settlement).markup.form_for("/logout").fields["csrf"],
                                        "request_key": "smoke-approve-owner-" + uuid.uuid4().hex})
    check("The owner approves the settlement and it becomes immutable",
          approved.status == 200 and settlement_row(f)["status"] == "approved")
    after_approval = service_state(f)
    check("Approving a settlement posts nothing",
          after_approval["journals"] == after["journals"] and after_approval["movements"] == after["movements"])

    # 7. An id belonging to another company is refused on every new route.
    stranger = login("owner", other_company)
    for label, url in (("the document detail screen", detail),
                       ("the settlement screen", settlement),
                       ("the A4 stock print", f"/print/stock-issue/{f['issue_id']}"),
                       ("the 80 mm stock print", f"/print/stock-issue/{f['issue_id']}?format=80mm"),
                       ("the gate-pass print", f"/print/gate-pass/{f['pass_id']}")):
        check("Another company's scope is refused on " + label, stranger.request(url).status == 403)
    check("The report in the other company shows that company only",
          stranger.request(report).status == 200 and "Sample van one" not in stranger.request(report).body)
    check("An id outside this book is refused on the detail screen",
          owner.request(f"/stock-documents/detail?id={f['issue_id'] + 10 ** 6}").status == 403)
    check("A foreign warehouse is refused on the settlement screen",
          owner.request(f"/stock-documents/settlement?warehouse={f['van_id'] + 10 ** 6}&date=2026-01-06").status == 403)
    check("A fixed warehouse has no driver's day",
          owner.request(f"/stock-documents/settlement?warehouse={f['default_warehouse_id']}&date=2026-01-06").status == 403)

    # 8. Both new print types render, in every registered format, without the shell.
    printed = {
        "stock document A4": f"/print/stock-issue/{f['issue_id']}",
        "stock document 80 mm": f"/print/stock-issue/{f['issue_id']}?format=80mm",
        "gate pass A4": f"/print/gate-pass/{f['pass_id']}",
    }
    for label, url in printed.items():
        page = owner.request(url)
        check(f"The {label} print renders", page.status == 200 and f["company_name"] in page.body)
        check(f"The {label} print carries no application shell", not any(marker in page.body for marker in SHELL_MARKERS))
        check(f"The {label} print carries no form", "<form" not in page.body)
    a4 = owner.request(printed["stock document A4"])
    check("The stock document A4 print defaults to A4 and names its number",
          "paper-a4" in a4.body and "paper-80mm" not in a4.body and f["issue_number"] in a4.body)
    check("The stock document A4 print carries its gate pass and the internal-transfer notice",
          f["pass_number"] in a4.body and "not a sale" in a4.body)
    roll = owner.request(printed["stock document 80 mm"])
    check("The 80 mm load list renders on roll paper with the driver",
          "paper-80mm" in roll.body and "Sample driver" in roll.body)
    pass_print = owner.request(printed["gate pass A4"])
    check("The gate-pass print lists the items of the document it covers",
          f["issue_number"] in pass_print.body and f["products"][0]["name"] in pass_print.body)
    check("The gate-pass print states that it moves and posts nothing",
          "moves no stock and posts no accounting entry" in pass_print.body)
    check("An unregistered format is refused", owner.request(f"/print/gate-pass/{f['pass_id']}?format=80mm").status == 403)
    check("A stock document is not printable as a gate pass", owner.request(f"/print/gate-pass/{f['issue_id']}").status == 403)
    check("A gate pass is not printable as a stock document", owner.request(f"/print/stock-issue/{f['pass_id']}").status == 403)
    check("Accountant prints what the record screen shows", accountant.request(printed["stock document A4"]).status == 200)
    check("Viewer printing follows the record screen",
          viewer.request(printed["stock document A4"]).status == viewer.request(detail).status == 200)
    check("Printing accepts no POST", owner.request(printed["stock document A4"], {"csrf": "none"}).status == 405)

    final = service_state(f)
    check("Reading and printing left the ledger and the stock movements unchanged",
          final["journals"] == before["journals"] and final["movements"] == before["movements"])

    return {"passed": len(checks), "failed": 0, "target": http.ORIGIN, "company_id": company, "book_id": f["book_id"],
            "van_id": f["van_id"], "issue_number": f["issue_number"], "gate_passes": final["passes"],
            "external_calls": False, "production_changed": False}


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="Local stock-document HTTP acceptance checks.")
    parser.add_argument("--port", type=int, default=18200, help="Loopback port of the local web service (default 18200).")
    parser.add_argument("--project", default="", help="Compose project name, for an isolated parallel stack.")
    options = parser.parse_args()
    if not 1024 <= options.port <= 65535:
        raise SystemExit("Choose an unprivileged loopback port.")
    pin_loopback_port(options.port)
    if options.project:
        COMPOSE.extend(["-p", options.project])
    print(json.dumps(run(options.port), indent=2))
