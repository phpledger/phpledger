"""Exercise opening cutover, accounting periods and bank reconciliation locally.

Uses only http://127.0.0.1:18200 and Docker's local web/database services. Creates
ordinary sample owner/viewer users and two isolated sample businesses;
generated passwords and cookies stay in memory. All financial workflow actions
use HTTP forms. Fixtures are retained for inspection; nothing is reset/deleted.
Apply local migrations before running: python tests/accounting-http-smoke.py
"""

from __future__ import annotations

import base64
from decimal import Decimal
import importlib.util
import json
from pathlib import Path
import secrets
import subprocess
import sys
from urllib.parse import parse_qs, urlparse
import uuid


spec = importlib.util.spec_from_file_location("accounting_local_http", Path(__file__).with_name("http-smoke.py"))
http = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = http
spec.loader.exec_module(http)


def php_local(source: str) -> dict:
    result = subprocess.run(
        ["docker", "compose", "exec", "-T", "web", "php"],
        input="<?php\nrequire 'www/phpledger/includes/bootstrap.php';\n"
        "if (getenv('PL_ENV') !== 'local' || getenv('PL_DB_NAME') !== 'phpledger' || getenv('PL_DB_HOST') !== 'db') { throw new RuntimeException('Local development database required.'); }\n"
        + source,
        text=True, cwd=http.REPO, capture_output=True, timeout=40,
    )
    if result.returncode != 0:
        # Do not surface submitted fixture credentials or server response bodies.
        raise RuntimeError("Local sample fixture/read check failed; verify migrations and local service logs.")
    return json.loads(result.stdout)


def fixture() -> tuple[dict, dict]:
    suffix = uuid.uuid4().hex
    private = {
        "owner_email": f"accounting-owner-{suffix}@example.test",
        "viewer_email": f"accounting-viewer-{suffix}@example.test",
        "owner_password": secrets.token_urlsafe(32),
        "viewer_password": secrets.token_urlsafe(32),
        "name": "Accounting HTTP Acceptance " + suffix,
        "other_name": "Accounting HTTP Unrelated " + suffix,
    }
    encoded = base64.b64encode(json.dumps(private).encode()).decode("ascii")
    result = php_local("$p = json_decode(base64_decode('" + encoded + "'), true, 512, JSON_THROW_ON_ERROR);\n" + r'''
$result = pl_ledger_transaction(function () use ($p): array {
    $owner = pl_create_user($p['owner_email'], 'Sample accounting owner', $p['owner_password']);
    $viewer = pl_create_user($p['viewer_email'], 'Sample accounting viewer', $p['viewer_password']);
    $company = pl_setup_company($owner, [
        'name' => $p['name'], 'currency' => 'USD', 'start_date' => '2026-01-01', 'fiscal_year_end' => '12-31',
        'start_mode' => 'existing', 'template_digest' => pl_starter_template()['digest'],
    ], bin2hex(random_bytes(20)));
    $other = pl_create_company($viewer, $p['other_name'], 'USD', '2026-01-01');
    DB::insert('pl_company_members', ['company_id' => $company['id'], 'user_id' => $viewer, 'role' => 'viewer']);
    $accounts = [];
    foreach ($company['accounts'] as $account) { $accounts[$account['code']] = $account['id']; }
    return ['company_id' => $company['id'], 'book_id' => $company['book_id'], 'company_name' => $p['name'],
        'other_company_id' => $other['company_id'], 'other_book_id' => $other['book_id'],
        'owner_id' => $owner, 'viewer_id' => $viewer, 'accounts' => $accounts];
});
echo json_encode($result, JSON_THROW_ON_ERROR);
''')
    return result, private


def snapshot(f: dict) -> dict:
    encoded = base64.b64encode(json.dumps(f).encode()).decode("ascii")
    return php_local("$f = json_decode(base64_decode('" + encoded + "'), true, 512, JSON_THROW_ON_ERROR);\n" + r'''
$company = DB::queryFirstRow('SELECT name, created_by, setup_status FROM pl_companies WHERE id = %i', $f['company_id']);
if (!$company || $company['name'] !== $f['company_name'] || !str_starts_with($company['name'], 'Accounting HTTP Acceptance ') || (int) $company['created_by'] !== $f['owner_id']) { throw new RuntimeException('Sample scope mismatch.'); }
pl_require_company_access($f['owner_id'], $f['company_id']);
pl_ledger_book($f['company_id'], $f['book_id']);
$counts = ['setup_status' => $company['setup_status']];
foreach (['pl_journals', 'pl_documents', 'pl_opening_previews', 'pl_opening_cutovers', 'pl_opening_documents', 'pl_periods', 'pl_period_actions', 'pl_bank_statements', 'pl_bank_statement_rows', 'pl_bank_matches'] as $table) {
    $counts[$table] = (int) DB::queryFirstField('SELECT COUNT(*) FROM %b WHERE company_id = %i AND book_id = %i', $table, $f['company_id'], $f['book_id']);
}
$counts['completed_statements'] = (int) DB::queryFirstField("SELECT COUNT(*) FROM pl_bank_statements WHERE company_id = %i AND book_id = %i AND status = 'completed'", $f['company_id'], $f['book_id']);
$counts['cancelled_statements'] = (int) DB::queryFirstField("SELECT COUNT(*) FROM pl_bank_statements WHERE company_id = %i AND book_id = %i AND status = 'cancelled'", $f['company_id'], $f['book_id']);
$counts['bank_match_events'] = (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_bank_match_events e JOIN pl_bank_statement_rows r ON r.id = e.row_id WHERE r.company_id = %i AND r.book_id = %i', $f['company_id'], $f['book_id']);
$counts['bank_source_hashes'] = [];
foreach (DB::query('SELECT id, reference, start_date, end_date, opening_balance, closing_balance FROM pl_bank_statements WHERE company_id = %i AND book_id = %i ORDER BY id', $f['company_id'], $f['book_id']) as $statement) {
    $rows = DB::query('SELECT line_number, transaction_date, reference, description, money_in, money_out FROM pl_bank_statement_rows WHERE statement_id = %i ORDER BY line_number', $statement['id']);
    $counts['bank_source_hashes'][(string) $statement['id']] = hash('sha256', json_encode([$statement, $rows], JSON_THROW_ON_ERROR));
}
echo json_encode($counts, JSON_THROW_ON_ERROR);
''')


def action_form(page, path: str, action: str, **fields):
    matches = [form for form in page.markup.forms if urlparse(form.action).path == path
               and form.fields.get("action") == action
               and all(form.fields.get(key) == str(value) for key, value in fields.items())]
    if len(matches) != 1:
        raise AssertionError(f"Expected one {action} form at {path}; received {len(matches)}.")
    return matches[0]


def has_action(page, path: str, action: str) -> bool:
    return any(urlparse(form.action).path == path and form.fields.get("action") == action for form in page.markup.forms)


def session_csrf(session) -> str:
    return session.request("/companies").markup.form_for("/logout").fields["csrf"]


def sign_in(session, email: str, password: str, company_id: int):
    page = session.request("/login")
    signed_in = session.submit(page.markup.form_for("/login"), {"email": email, "password": password})
    if signed_in.status != 200 or urlparse(signed_in.url).path != "/companies":
        raise AssertionError("Sample user could not sign in.")
    selects = [form for form in signed_in.markup.forms if urlparse(form.action).path == "/company/select"
               and form.fields.get("company_id") == str(company_id)]
    if len(selects) != 1 or session.submit(selects[0]).status != 200:
        raise AssertionError("Sample user could not select its permitted company.")


def run() -> dict:
    checks = []

    def check(condition: bool, name: str):
        if not condition:
            raise AssertionError(name)
        checks.append(name)
        print("PASS:", name, flush=True)

    def ok(page, name: str):
        check(page.status == 200, name)

    anon = http.Session()
    for path in ["/opening-balances", "/periods", "/bank-reconciliation"]:
        page = anon.request(path)
        check(page.status == 200 and urlparse(page.url).path == "/login", f"Authentication protects {path}")

    f, private = fixture()
    owner, viewer = http.Session(), http.Session()
    sign_in(owner, private["owner_email"], private["owner_password"], f["company_id"])
    sign_in(viewer, private["viewer_email"], private["viewer_password"], f["company_id"])
    private.clear()
    check(snapshot(f)["setup_status"] == "opening_required", "Existing-business fixture starts behind the opening-readiness gate")
    scope = {"company_id": str(f["company_id"]), "book_id": str(f["book_id"])}
    for path in ["/opening-balances", "/periods", "/bank-reconciliation"]:
        denied = owner.request(path, scope | {"action": "preview", "csrf": "invalid"})
        check(denied.status == 403, f"Invalid CSRF rejected at {path}")

    balances = "account_code,debit,credit\n1000,1000,0\n1100,100,0\n2000,0,50\n3000,0,1050\n"
    documents = "kind,account_code,party,reference,document_date,due_date,outstanding\nreceivable,1100,Sample customer,OPEN-AR-1,2025-12-15,2026-01-15,100\npayable,2000,Sample supplier,OPEN-AP-1,2025-12-16,2026-01-16,50\n"
    source = "Sample reviewed source <script>do-not-run</script>"
    values = {"cutover_date": "2026-01-01", "source": source, "balances_csv": balances, "documents_csv": documents}
    opening = owner.request("/opening-balances")
    prepare = action_form(opening, "/opening-balances", "preview")
    wrong_scope = owner.submit(prepare, values | {"book_id": str(f["other_book_id"])})
    check(wrong_scope.status == 422, "Opening preview rejects a foreign book in submitted scope")
    invalid = owner.submit(action_form(wrong_scope, "/opening-balances", "preview"), values | {"balances_csv": "wrong,headers\n1,2\n"})
    check(invalid.status == 422 and action_form(invalid, "/opening-balances", "preview").fields["source"] == source,
          "Invalid opening CSV preserves source and submitted values")
    check("<script>do-not-run</script>" not in invalid.body, "Opening error recovery escapes supplied text")
    mismatch = owner.submit(action_form(invalid, "/opening-balances", "preview"), values | {"documents_csv": documents.replace(",100\n", ",99\n")})
    check(mismatch.status == 422, "Opening preview rejects unpaid documents that differ from control balances")
    check(snapshot(f)["pl_journals"] == 0, "Rejected opening previews have no journal effect")

    viewer_opening = viewer.request("/opening-balances")
    check(viewer_opening.status == 200 and not has_action(viewer_opening, "/opening-balances", "preview"), "Viewer can inspect opening state without an edit form")
    denied = viewer.request("/opening-balances", prepare.fields | values | {"csrf": session_csrf(viewer)})
    check(denied.status == 422, "Forged viewer opening preview is rejected by the server")

    def save_draft(kind: str, amount: str, reference: str, date: str = "2026-01-02"):
        editor = owner.request("/receipts/new" if kind == "receipt" else "/expenses/new")
        form = editor.markup.form_for("/receipts/save" if kind == "receipt" else "/expenses/save")
        return owner.submit(form, {"kind": kind, "amount": amount, "date": date,
                                  "money_account_id": str(f["accounts"]["1000"]),
                                  "category_account_id": str(f["accounts"]["4000" if kind == "receipt" else "5000"]),
                                  "counterparty": "Sample accounting HTTP counterparty", "reference": reference,
                                  "memo": "Sample local acceptance"})

    gated = save_draft("receipt", "125", "GATED")
    check(gated.status == 422 and snapshot(f)["pl_documents"] == 0, "Unconfirmed opening blocks ordinary transaction creation")
    current = owner.request("/opening-balances")
    preview = owner.submit(action_form(current, "/opening-balances", "preview"), values)
    ok(preview, "Opening preview validates balanced accounts and two unpaid documents")
    check(snapshot(f)["pl_journals"] == 0 and "Unpaid documents (2)" in preview.body, "Valid preview keeps books unchanged and shows unpaid source detail")
    confirm = action_form(preview, "/opening-balances", "confirm")
    missing_ack = owner.submit(confirm)
    check(missing_ack.status == 422, "Opening confirmation requires explicit source review")
    confirmed = owner.submit(action_form(missing_ack, "/opening-balances", "confirm"), {"confirmed": "1"})
    ok(confirmed, "HTTP confirmation completes opening readiness")
    opening_journal = confirmed.markup.link_for("/journals/detail", "View journal")
    ok(owner.request(opening_journal), "Opening history links to its actual journal route")
    receipt = snapshot(f)
    check(receipt["setup_status"] == "ready" and receipt["pl_journals"] == 1 and receipt["pl_opening_documents"] == 2,
          "Opening confirmation posts once and retains two unpaid snapshots")
    retried = owner.submit(confirm, {"confirmed": "1"})
    check(retried.status == 200 and snapshot(f)["pl_journals"] == 1, "Repeated opening confirmation has one durable accounting effect")
    check(http.totals(owner.request("/reports/trial-balance?as_of=2026-01-01")) == (Decimal("1100"), Decimal("1100")),
          "Opening trial balance reconciles to the reviewed source totals")
    cutover_draft = save_draft("receipt", "1", "CUTOVER-DATE-REJECTION", "2026-01-01")
    cutover_post = owner.submit(cutover_draft.markup.form_for("/transactions/post"))
    check(cutover_post.status == 422 and snapshot(f)["pl_journals"] == 1,
          "Confirmed cutover rejects ordinary HTTP posting on its opening date")

    received = save_draft("receipt", "125", "BANK-IN-1")
    posted_receipt = owner.submit(received.markup.form_for("/transactions/post"))
    ok(posted_receipt, "Ready company can post a receipt through HTTP")
    expense = save_draft("expense", "25", "BANK-OUT-1")
    post_expense = expense.markup.form_for("/transactions/post")
    periods = owner.request("/periods")
    close = action_form(periods, "/periods", "close")
    main_period_id = close.fields["period_id"]
    denied = viewer.request("/periods", close.fields | {"csrf": session_csrf(viewer), "reason": "Viewer forged close"})
    check(denied.status == 422, "Forged viewer period close is rejected by the server")
    denied = owner.submit(close, {"reason": "Wrong scope", "book_id": str(f["other_book_id"])})
    check(denied.status == 422, "Period close rejects a foreign book scope")
    no_reason = owner.submit(close)
    check(no_reason.status == 422, "Period close requires a recorded reason")
    closed = owner.submit(action_form(no_reason, "/periods", "close", period_id=main_period_id), {"reason": "Sample review complete"})
    check(closed.status == 200 and has_action(closed, "/periods", "reopen"), "HTTP period close records closed state")
    rejected_post = owner.submit(post_expense)
    check(rejected_post.status == 422 and snapshot(f)["pl_journals"] == 2, "Closed period rejects HTTP posting and preserves the saved draft")
    reopened = owner.submit(action_form(closed, "/periods", "reopen", period_id=main_period_id), {"reason": "Sample owner approved correction"})
    check(reopened.status == 200 and has_action(reopened, "/periods", "close"), "Owner reopens period with a recorded reason")
    created_period = owner.submit(action_form(reopened, "/periods", "create"), {
        "start_date": "2027-01-01", "end_date": "2027-12-31", "reason": "Sample next year"})
    check(created_period.status == 200 and snapshot(f)["pl_periods"] == 2, "HTTP creates a nonoverlapping next accounting period")
    overlap = owner.submit(action_form(created_period, "/periods", "create"), {
        "start_date": "2027-06-01", "end_date": "2027-12-31", "reason": "Sample overlap"})
    check(overlap.status == 422 and snapshot(f)["pl_periods"] == 2, "Overlapping period creation is rejected without adding a period")
    posted_expense = owner.submit(rejected_post.markup.form_for("/transactions/post"))
    ok(posted_expense, "Reopened period accepts the preserved expense draft")

    bank_values = {"account_id": str(f["accounts"]["1000"]), "reference": "SAMPLE-STATEMENT-1",
                   "start_date": "2026-01-02", "end_date": "2026-01-31", "opening_balance": "1000", "closing_balance": "1100",
                   "baseline_confirmed": "1", "csv": "date,reference,description,money_in,money_out\n2026-01-02,BANK-IN-1,Sample receipt,125,0\n2026-01-02,BANK-OUT-1,Sample expense,0,25\n"}
    bank = owner.request("/bank-reconciliation")
    bank_prepare = action_form(bank, "/bank-reconciliation", "preview")
    denied = owner.submit(bank_prepare, bank_values | {"book_id": str(f["other_book_id"])})
    check(denied.status == 422, "Bank preview rejects a foreign book scope")
    denied = viewer.request("/bank-reconciliation", bank_prepare.fields | bank_values | {"csrf": session_csrf(viewer)})
    check(denied.status == 422, "Forged viewer bank import preview is rejected by the server")
    invalid = owner.submit(bank_prepare, bank_values | {"closing_balance": "1101"})
    check(invalid.status == 422 and action_form(invalid, "/bank-reconciliation", "preview").fields["csv"].strip() == bank_values["csv"].strip(),
          "Invalid bank closing balance preserves CSV and input")
    bank_preview = owner.submit(action_form(invalid, "/bank-reconciliation", "preview"), bank_values)
    ok(bank_preview, "Bank preview validates the statement and cleared opening baseline")
    check(snapshot(f)["pl_bank_statements"] == 0, "Bank preview does not import or post records")
    import_form = action_form(bank_preview, "/bank-reconciliation", "import")
    imported = owner.submit(import_form)
    ok(imported, "HTTP confirmation imports a statement with two rows")
    statement_id = int(parse_qs(urlparse(imported.url).query)["id"][0])
    repeated = owner.submit(import_form)
    check(repeated.status == 200 and snapshot(f)["pl_bank_statements"] == 1, "Repeated bank import returns one statement")
    premature = owner.submit(action_form(imported, "/bank-reconciliation", "complete"), {"review_confirmed": "1"})
    check(premature.status == 422 and snapshot(f)["completed_statements"] == 0, "Server rejects completion while statement rows remain unmatched")

    # Cancel a mistaken first import and retain its source while importing a correction.
    cancelled_statement_id = statement_id
    cancelled_url = f"/bank-reconciliation?id={cancelled_statement_id}"
    original_hash = snapshot(f)["bank_source_hashes"][str(cancelled_statement_id)]
    original_completion = action_form(imported, "/bank-reconciliation", "complete")
    cancel_without_reason = owner.submit(action_form(imported, "/bank-reconciliation", "cancel"))
    check(cancel_without_reason.status == 422 and snapshot(f)["cancelled_statements"] == 0,
          "Cancelling an imported draft requires a recorded reason")
    reason = "Sample import description requires correction"
    viewer_cancel = viewer.request("/bank-reconciliation", action_form(cancel_without_reason, "/bank-reconciliation", "cancel").fields
                                   | {"csrf": session_csrf(viewer), "reason": reason})
    check(viewer_cancel.status == 422 and snapshot(f)["cancelled_statements"] == 0,
          "Viewer cannot cancel a statement through a forged HTTP action")
    cancel_candidate_page = owner.request(cancel_without_reason.markup.link_for("/bank-reconciliation", "Review matches"))
    cancelled_match = action_form(cancel_candidate_page, "/bank-reconciliation", "match")
    cancelled_line_id = next(option["value"] for option in cancelled_match.options["line_id"] if option.get("value"))
    matched_mistake = owner.submit(cancelled_match, {"line_id": cancelled_line_id})
    matched_cancel = owner.submit(action_form(matched_mistake, "/bank-reconciliation", "cancel"), {"reason": reason})
    check(matched_cancel.status == 422 and snapshot(f)["pl_bank_matches"] == 1
          and action_form(matched_cancel, "/bank-reconciliation", "cancel").fields["reason"] == reason,
          "Cancellation refuses saved matches and retains the entered reason")
    cleared_mistake = owner.submit(action_form(matched_cancel, "/bank-reconciliation", "unmatch"))
    check(cleared_mistake.status == 200 and snapshot(f)["pl_bank_matches"] == 0,
          "Removing the mistaken draft match permits cancellation review")
    cancel_form = action_form(cleared_mistake, "/bank-reconciliation", "cancel")
    cancelled = owner.submit(cancel_form, {"reason": reason})
    cancelled_receipt = snapshot(f)
    check(cancelled.status == 200 and "Cancelled import." in cancelled.body and reason in cancelled.body
          and cancelled_receipt["cancelled_statements"] == 1,
          "HTTP cancellation records the reason and marks the original import cancelled")
    check(cancelled_receipt["bank_source_hashes"][str(cancelled_statement_id)] == original_hash
          and cancelled_receipt["pl_bank_statement_rows"] == 2 and cancelled_receipt["bank_match_events"] == 2,
          "Cancellation preserves original statement rows and prior match/unmatch audit")
    repeat_cancel = owner.submit(cancel_form, {"reason": reason})
    check(repeat_cancel.status == 200 and snapshot(f)["cancelled_statements"] == 1,
          "Repeated cancellation returns the retained cancellation receipt")
    altered_cancel = owner.submit(cancel_form, {"reason": "A different cancellation reason"})
    check(altered_cancel.status == 422 and reason in altered_cancel.body,
          "Cancellation retries cannot replace the original recorded reason")
    invalid_match = owner.submit(cancelled_match, {"line_id": cancelled_line_id})
    invalid_completion = owner.submit(original_completion, {"review_confirmed": "1"})
    check(invalid_match.status == 422 and invalid_completion.status == 422
          and snapshot(f)["pl_bank_matches"] == 0 and snapshot(f)["completed_statements"] == 0,
          "Cancelled statements reject forged matching and completion requests")
    cancelled_view = owner.request(cancelled_url)
    check(cancelled_view.status == 200 and all(not has_action(cancelled_view, "/bank-reconciliation", action)
                                              for action in ["match", "complete", "cancel"]),
          "Cancelled statement remains readable without mutation controls")
    corrected_values = bank_values | {"csv": bank_values["csv"].replace("Sample receipt", "Corrected sample receipt")}
    corrected_preview = owner.submit(action_form(owner.request("/bank-reconciliation"), "/bank-reconciliation", "preview"), corrected_values)
    ok(corrected_preview, "Corrected import can reuse cancelled statement and transaction references")
    imported = owner.submit(action_form(corrected_preview, "/bank-reconciliation", "import"))
    statement_id = int(parse_qs(urlparse(imported.url).query)["id"][0])
    corrected_receipt = snapshot(f)
    check(imported.status == 200 and statement_id != cancelled_statement_id
          and corrected_receipt["pl_bank_statements"] == 2 and corrected_receipt["pl_bank_statement_rows"] == 4
          and "Corrected sample receipt" in imported.body,
          "Corrected reimport creates separate retained statement rows using the same bank references")
    check(corrected_receipt["bank_source_hashes"][str(cancelled_statement_id)] == original_hash
          and corrected_receipt["pl_journals"] == 3,
          "Correction leaves cancelled source content and accounting journals unchanged")

    statement_url = f"/bank-reconciliation?id={statement_id}"
    for _ in range(2):
        statement = owner.request(statement_url)
        link = statement.markup.link_for("/bank-reconciliation", "Review matches")
        candidates = owner.request(link)
        match = action_form(candidates, "/bank-reconciliation", "match")
        options = [option["value"] for option in match.options["line_id"] if option.get("value")]
        check(len(options) == 1, "Exact signed bank amount identifies one sample candidate")
        matched = owner.submit(match, {"line_id": options[0]})
        ok(matched, "Explicit HTTP match records the reviewed ledger entry")
    matched = owner.request(statement_url)
    check(snapshot(f)["pl_bank_matches"] == 2, "Both bank statement rows are explicitly matched")
    first_unmatch = next(form for form in matched.markup.forms if form.fields.get("action") == "unmatch")
    removed = owner.submit(first_unmatch)
    check(removed.status == 200 and snapshot(f)["pl_bank_matches"] == 1, "Draft reconciliation can remove a match through HTTP")
    candidates = owner.request(removed.markup.link_for("/bank-reconciliation", "Review matches"))
    rematch = action_form(candidates, "/bank-reconciliation", "match")
    line_id = next(option["value"] for option in rematch.options["line_id"] if option.get("value"))
    rematched = owner.submit(rematch, {"line_id": line_id})
    completion = action_form(rematched, "/bank-reconciliation", "complete")
    missing_review = owner.submit(completion)
    check(missing_review.status == 422, "Reconciliation completion requires explicit reviewed confirmation")
    complete = owner.submit(action_form(missing_review, "/bank-reconciliation", "complete"), {"review_confirmed": "1"})
    check(complete.status == 200 and "Completed" in complete.body and snapshot(f)["completed_statements"] == 1,
          "HTTP completion freezes a reconciled statement")
    totals_rows = [row for table in complete.markup.tables for row in table]
    check(any(row == ["Difference", "0.00"] for row in totals_rows), "Completed statement shows zero reconciliation difference")
    repeated_completion = owner.submit(completion, {"review_confirmed": "1"})
    check(repeated_completion.status == 200 and snapshot(f)["completed_statements"] == 1, "Repeated completion returns the durable completed statement")
    locked_unmatch = owner.submit(first_unmatch)
    check(locked_unmatch.status == 422 and snapshot(f)["pl_bank_matches"] == 2, "Completed reconciliation rejects forged match removal")
    backdated_draft = save_draft("expense", "5", "BANK-BACKDATE-REJECTION", "2026-01-31")
    backdated_post = owner.submit(backdated_draft.markup.form_for("/transactions/post"))
    check(backdated_post.status == 422 and snapshot(f)["pl_journals"] == 3,
          "Completed reconciliation rejects backdated bank posting through HTTP")
    check(snapshot(f)["pl_journals"] == 3, "Bank import and reconciliation create no accounting journals")
    viewer_statement = viewer.request(statement_url)
    check(viewer_statement.status == 200 and not has_action(viewer_statement, "/bank-reconciliation", "complete"),
          "Viewer reads completed statement without mutation controls")
    forbidden_company = owner.request("/company/select", {"csrf": session_csrf(owner), "company_id": str(f["other_company_id"]), "book_id": str(f["other_book_id"])})
    check(forbidden_company.status == 403, "Owner cannot select another user's unrelated company")
    check(http.totals(owner.request("/reports/trial-balance?as_of=2026-01-31")) == (Decimal("1225"), Decimal("1225")),
          "Final trial balance reconciles after opening, receipt, expense and bank completion")
    return {"passed": len(checks), "failed": 0, "target": http.ORIGIN,
            "company_id": f["company_id"], "book_id": f["book_id"], "statement_id": statement_id,
            "cancelled_statement_id": cancelled_statement_id,
            "data": "Sample users and books retained; periods open, original statement cancelled and corrected statement completed.",
            "external_calls": False, "production_changed": False}


if __name__ == "__main__":
    try:
        print(json.dumps(run(), indent=2))
    except Exception as error:
        print(f"FAIL: {error}", file=sys.stderr)
        sys.exit(1)
