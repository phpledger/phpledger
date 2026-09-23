"""Exercise the local browser routes using an isolated sample company.

Requires PL_HTTP_EMAIL and PL_HTTP_PASSWORD in the caller's environment. Uses only
http://127.0.0.1:18200, keeps cookies in memory, and leaves its sample company
for inspection. The optional period check changes only that company's period and
reopens it in a finally block; it never resets or deletes any database.
"""

from __future__ import annotations

import argparse
import base64
from dataclasses import dataclass, field
from datetime import datetime, timezone
from decimal import Decimal
from html.parser import HTMLParser
from http.cookiejar import CookieJar
import json
import os
from pathlib import Path
import subprocess
import sys
import uuid
from urllib.error import HTTPError
from urllib.parse import parse_qs, urlencode, urljoin, urlparse
from urllib.request import HTTPCookieProcessor, HTTPRedirectHandler, Request, build_opener


ORIGIN = "http://127.0.0.1:18200"
REPO = Path(__file__).resolve().parent.parent


def local_url(value: str) -> str:
    result = urljoin(ORIGIN, value)
    parsed = urlparse(result)
    if (parsed.scheme, parsed.hostname, parsed.port) != ("http", "127.0.0.1", 18200):
        raise RuntimeError("HTTP smoke checks refuse non-local targets or redirects.")
    return result


class LocalRedirects(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return super().redirect_request(req, fp, code, msg, headers, local_url(newurl))


@dataclass
class Form:
    action: str
    method: str
    fields: dict[str, str] = field(default_factory=dict)
    options: dict[str, list[dict[str, str | None]]] = field(default_factory=dict)


class Markup(HTMLParser):
    def __init__(self, html: str):
        super().__init__(convert_charrefs=True)
        self.forms: list[Form] = []
        self.links: list[tuple[str, str]] = []
        self.tables: list[list[list[str]]] = []
        self.form: Form | None = None
        self.select = None
        self.option = None
        self.textarea = None
        self.anchor = None
        self.table = None
        self.row = None
        self.cell = None
        self.feed(html)

    def handle_starttag(self, tag, attrs):
        attributes = dict(attrs)
        if tag == "form":
            self.form = Form(attributes.get("action", ""), attributes.get("method", "get"))
            self.forms.append(self.form)
        if tag == "input" and self.form is not None and attributes.get("name"):
            kind = attributes.get("type", "text")
            if "disabled" not in attributes and (kind not in {"radio", "checkbox"} or "checked" in attributes):
                self.form.fields[attributes["name"]] = attributes.get("value", "")
        if tag == "select" and self.form is not None:
            self.select = attributes.get("name")
            self.form.options[self.select] = []
        if tag == "option" and self.form is not None and self.select:
            self.option = attributes | {"text": ""}
            self.form.options[self.select].append(self.option)
        if tag == "textarea" and self.form is not None:
            self.textarea = attributes.get("name")
            self.form.fields[self.textarea] = ""
        if tag == "a":
            self.anchor = [attributes.get("href", ""), ""]
        if tag == "table":
            self.table = []
            self.tables.append(self.table)
        if tag == "tr" and self.table is not None:
            self.row = []
            self.table.append(self.row)
        if tag in {"th", "td"} and self.row is not None:
            self.cell = ""

    def handle_data(self, data):
        if self.textarea and self.form is not None:
            self.form.fields[self.textarea] += data
        if self.option is not None:
            self.option["text"] += data
        if self.anchor is not None:
            self.anchor[1] += data
        if self.cell is not None:
            self.cell += data

    def handle_endtag(self, tag):
        if tag == "form":
            self.form = None
        if tag == "option":
            self.option = None
        if tag == "select" and self.form is not None and self.select:
            options = self.form.options[self.select]
            chosen = next((o for o in options if "selected" in o), options[0] if options else {})
            self.form.fields[self.select] = chosen.get("value", "")
            self.select = None
        if tag == "textarea":
            self.textarea = None
        if tag == "a" and self.anchor is not None:
            self.links.append((self.anchor[0], " ".join(self.anchor[1].split())))
            self.anchor = None
        if tag in {"td", "th"} and self.cell is not None and self.row is not None:
            self.row.append(" ".join(self.cell.split()))
            self.cell = None
        if tag == "tr":
            self.row = None
        if tag == "table":
            self.table = None

    def form_for(self, path: str) -> Form:
        matches = [form for form in self.forms if urlparse(form.action).path == path]
        if len(matches) != 1:
            raise AssertionError(f"Expected one form for {path}; found {len(matches)}.")
        return matches[0]

    def link_for(self, path: str, text: str | None = None) -> str:
        matches = [href for href, label in self.links if urlparse(href).path == path and (text is None or text in label)]
        if not matches:
            raise AssertionError(f"Expected a rendered link to {path}.")
        return matches[0]


@dataclass
class Page:
    status: int
    url: str
    headers: object
    body: str

    @property
    def markup(self) -> Markup:
        return Markup(self.body)


class Session:
    def __init__(self):
        self.cookies = CookieJar()
        self.opener = build_opener(LocalRedirects(), HTTPCookieProcessor(self.cookies))

    def request(self, path: str, fields: dict[str, str] | None = None) -> Page:
        payload = None if fields is None else urlencode(fields).encode("utf-8")
        request = Request(local_url(path), data=payload, headers={"User-Agent": "PHP-Ledger-local-HTTP-smoke/1"})
        try:
            response = self.opener.open(request, timeout=20)
        except HTTPError as error:
            response = error
        with response:
            return Page(response.status, response.geturl(), response.headers, response.read().decode("utf-8"))

    def submit(self, form: Form, changes: dict[str, str] | None = None) -> Page:
        if form.method.lower() != "post":
            raise AssertionError("Expected a POST form.")
        return self.request(form.action, form.fields | (changes or {}))


def totals(page: Page) -> tuple[Decimal, Decimal]:
    for table in page.markup.tables:
        for row in table:
            if row and row[0] == "Total":
                return tuple(Decimal(value.replace(",", "")) for value in row[-2:])
    raise AssertionError("Trial balance totals were not rendered.")


def set_own_period(company_id: int, book_id: int, company_name: str, date: str, status: str) -> None:
    if status not in {"open", "closed"} or not company_name.startswith("HTTP Acceptance "):
        raise RuntimeError("Period check refused an unrecognized sample scope.")
    name64 = base64.b64encode(company_name.encode()).decode("ascii")
    php = f'''<?php
require 'www/phpledger/includes/bootstrap.php';
if (getenv('PL_ENV') !== 'local' || getenv('PL_DB_NAME') !== 'phpledger' || getenv('PL_DB_HOST') !== 'db') {{ throw new RuntimeException('Local development database required.'); }}
$companyId = {company_id}; $bookId = {book_id}; $expectedName = base64_decode('{name64}');
pl_ledger_transaction(function () use ($companyId, $bookId, $expectedName): void {{
    $company = DB::queryFirstRow('SELECT id, name, created_by, is_sample FROM pl_companies WHERE id = %i', $companyId);
    if (!$company || $company['name'] !== $expectedName || (int) $company['is_sample'] !== 0) {{ throw new RuntimeException('Sample company scope mismatch.'); }}
    pl_require_company_access((int) $company['created_by'], $companyId, true);
    pl_ledger_book($companyId, $bookId, true);
    $period = DB::queryFirstRow('SELECT id, status FROM pl_periods WHERE company_id = %i AND book_id = %i AND start_date <= %s AND end_date >= %s FOR UPDATE', $companyId, $bookId, '{date}', '{date}');
    if (!$period) {{ throw new RuntimeException('Sample period not found.'); }}
    DB::update('pl_periods', ['status' => '{status}'], 'id = %i AND company_id = %i AND book_id = %i', $period['id'], $companyId, $bookId);
}});
echo 'Scoped sample period {status}.';
'''
    result = subprocess.run(["docker", "compose", "exec", "-T", "web", "php"], input=php, text=True, cwd=REPO, capture_output=True, timeout=30)
    if result.returncode != 0:
        raise RuntimeError(f"Scoped sample period {status} command failed; review the local service logs.")


def run(skip_period_lock: bool) -> dict:
    email = os.environ.get("PL_HTTP_EMAIL", "")
    password = os.environ.get("PL_HTTP_PASSWORD", "")
    if not email or not password:
        raise RuntimeError("Set PL_HTTP_EMAIL and PL_HTTP_PASSWORD privately before running this local check.")
    session = Session()
    checks: list[str] = []

    def check(condition: bool, name: str):
        if not condition:
            raise AssertionError(name)
        checks.append(name)
        print("PASS:", name, flush=True)

    def require_ok(page: Page, context: str):
        if page.status != 200:
            raise AssertionError(f"{context}: expected HTTP 200, received {page.status} at {urlparse(page.url).path}.")

    health = session.request("/health")
    check(health.status == 200 and "application/json" in health.headers.get("Content-Type", "") and json.loads(health.body) == {"status": "ok", "stage": "working-accounting-preview"}, "Health returns working-preview JSON")
    rejected_health = session.request("/health", {})
    check(rejected_health.status == 405 and rejected_health.headers.get("Allow") == "GET", "Health rejects POST")
    login = session.request("/login")
    check(login.status == 200 and "text/html" in login.headers.get("Content-Type", ""), "Login returns HTML")
    denied = session.request("/login", {"email": email, "password": password})
    check(denied.status == 403, "Missing login CSRF is rejected")
    login = session.request("/login")
    signed_in = session.submit(login.markup.form_for("/login"), {"email": email, "password": password})
    require_ok(signed_in, "Login")
    check(urlparse(signed_in.url).path == "/companies", "Valid login reaches businesses")

    now = datetime.now(timezone.utc)
    date = now.date().isoformat()
    company_name = "HTTP Acceptance " + now.strftime("%Y%m%d-%H%M%S") + "-" + uuid.uuid4().hex[:6]
    onboarding = session.request("/onboarding")
    require_ok(onboarding, "Onboarding")
    preview = session.submit(onboarding.markup.form_for("/onboarding"), {"action": "preview", "name": company_name, "currency": "USD", "start_date": f"{now.year}-01-01", "fiscal_year_end": "12-31", "start_mode": "fresh", "zero_balances_confirmed": "1"})
    require_ok(preview, "Setup preview")
    check(company_name in preview.body and "Neutral starter chart" in preview.body, "Setup previews the business and starter chart")
    created = session.submit(preview.markup.form_for("/onboarding"))
    require_ok(created, "Setup confirmation")
    check(urlparse(created.url).path == "/transactions" and company_name in created.body, "Setup creates an isolated fresh company")

    editor = session.request("/expenses/new")
    require_ok(editor, "New expense")
    draft_form = editor.markup.form_for("/expenses/save")
    company_id = int(draft_form.fields["company_id"])
    book_id = int(draft_form.fields["book_id"])
    expense_account = next(option["value"] for option in draft_form.options["category_account_id"] if option.get("data-category-kind") == "expense")
    injected_name = "HTTP <script>alert('sample')</script>"
    values = {"kind": "expense", "date": date, "amount": "not-a-number", "category_account_id": expense_account, "counterparty": injected_name, "reference": "HTTP-" + uuid.uuid4().hex[:8], "memo": "Preserve this sample input after validation."}
    invalid = session.submit(draft_form, values)
    invalid_form = invalid.markup.form_for("/expenses/save")
    check(invalid.status == 422 and all(invalid_form.fields.get(key) == value for key, value in values.items()), "Invalid save preserves entered values")
    check("<script>alert('sample')</script>" not in invalid.body, "Retained untrusted text is escaped")
    saved = session.submit(invalid_form, {"amount": "125.50"})
    require_ok(saved, "Save draft")
    post_form = saved.markup.form_for("/transactions/post")
    document_id = int(post_form.fields["id"])
    check(totals(session.request("/reports/trial-balance?as_of=" + date)) == (Decimal("0"), Decimal("0")), "Saved draft does not change trial balance")
    denied_post = session.submit(post_form, {"csrf": "invalid-local-test-token"})
    check(denied_post.status == 403, "Posting rejects invalid CSRF")
    wrong_scope = session.submit(post_form, {"book_id": str(book_id + 1000000)})
    check(wrong_scope.status == 422 and wrong_scope.markup.form_for("/transactions/post").fields["id"] == str(document_id), "Posting rejects changed company/book scope and preserves draft")

    current = session.request(f"/transactions/detail?id={document_id}")
    post_form = current.markup.form_for("/transactions/post")
    posted = session.submit(post_form)
    require_ok(posted, "Post expense")
    check("status=posted" in posted.url, "Expense posts successfully")
    posted_again = session.submit(post_form)
    require_ok(posted_again, "Repeat posting")
    check(posted.markup.link_for("/journals/detail") == posted_again.markup.link_for("/journals/detail"), "Repeated posting returns the same durable journal")
    report = session.request("/reports/trial-balance?as_of=" + date)
    check(totals(report) == (Decimal("125.50"), Decimal("125.50")), "Repeated posting has one balanced report effect")
    activity = session.request(report.markup.link_for("/reports/account", "General expenses"))
    require_ok(activity, "Account activity")
    source = session.request(activity.markup.link_for("/transactions/detail"))
    check(parse_qs(urlparse(source.url).query).get("id") == [str(document_id)], "Report drills down to the source transaction")
    journal = session.request(activity.markup.link_for("/journals/detail"))
    require_ok(journal, "Journal")
    check(parse_qs(urlparse(journal.markup.link_for("/transactions/detail")).query).get("id") == [str(document_id)], "Posted journal links to its source")
    original_journal_id = parse_qs(urlparse(journal.url).query)["id"]

    reversal_form = source.markup.form_for("/transactions/reverse")
    bad_reversal = session.submit(reversal_form, {"date": "2000-01-01", "reason": "Preserve this reversal explanation."})
    retry_reversal = bad_reversal.markup.form_for("/transactions/reverse")
    check(bad_reversal.status == 422 and retry_reversal.fields.get("date") == "2000-01-01" and retry_reversal.fields.get("reason") == "Preserve this reversal explanation.", "Invalid reversal retains its date and reason")
    reversed_page = session.submit(retry_reversal, {"date": date, "reason": "Sample HTTP acceptance correction."})
    require_ok(reversed_page, "Reversal")
    check("status=reversed" in reversed_page.url, "Linked reversal preserves the original transaction")
    reversal_journal = session.request(reversed_page.markup.link_for("/journals/detail", "linked reversal"))
    require_ok(reversal_journal, "Reversal journal")
    check(parse_qs(urlparse(reversal_journal.markup.link_for("/journals/detail", "original")).query).get("id") == original_journal_id, "Reversal journal links to the original")
    check(totals(session.request("/reports/trial-balance?as_of=" + date)) == (Decimal("0"), Decimal("0")), "Original and reversal reconcile to zero net balances")

    if not skip_period_lock:
        next_editor = session.request("/expenses/new")
        next_form = next_editor.markup.form_for("/expenses/save")
        next_saved = session.submit(next_form, values | {"amount": "7.25", "counterparty": "HTTP Closed Period", "memo": "Preserve this draft during period rejection."})
        require_ok(next_saved, "Closed-period draft save")
        next_post = next_saved.markup.form_for("/transactions/post")
        period_changed = False
        try:
            period_changed = True
            set_own_period(company_id, book_id, company_name, date, "closed")
            rejected = session.submit(next_post)
            retained_post = rejected.markup.form_for("/transactions/post")
            check(rejected.status == 422 and retained_post.fields["id"] == next_post.fields["id"] and retained_post.fields["revision"] == next_post.fields["revision"] and "7.25" in rejected.body, "Closed period rejects posting and preserves saved draft/revision")
            check(totals(session.request("/reports/trial-balance?as_of=" + date)) == (Decimal("0"), Decimal("0")), "Closed-period rejection leaves reports unchanged")
        finally:
            if period_changed:
                set_own_period(company_id, book_id, company_name, date, "open")
        check(period_changed, "Only the acceptance company's period was closed and reopened")

    companies = session.request("/companies")
    logout = session.submit(companies.markup.form_for("/logout"))
    check(logout.status == 200 and urlparse(logout.url).path == "/login", "Logout returns to sign-in")
    protected = session.request("/transactions")
    check(urlparse(protected.url).path == "/login", "Logged-out session cannot read transactions")
    return {"passed": len(checks), "failed": 0, "company_id": company_id, "book_id": book_id, "company_name": company_name, "document_id": document_id, "period_lock_checked": not skip_period_lock, "target": ORIGIN, "data": "Sample company retained; its tested period is open."}


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--skip-period-lock", action="store_true", help="Skip the scoped Docker CLI period check.")
    options = parser.parse_args()
    try:
        print(json.dumps(run(options.skip_period_lock), indent=2))
    except Exception as error:
        print(f"FAIL: {error}", file=sys.stderr)
        sys.exit(1)
