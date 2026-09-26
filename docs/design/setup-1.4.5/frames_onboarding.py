"""Business onboarding frames: O-1 start, O-2 business, O-3 owners and money, O-4 features and accounts, O-5 review, O-6 ready."""
import json
from html import escape as e
from pathlib import Path

from ui import (ONBOARD_STEPS, TICK, WIKI, field, help, icon, select, setup_page, text_input)
from legal_forms import COUNTRIES, as_json, guide_text, options_html

ROOT = Path(__file__).resolve().parents[3]
PACKS = ROOT / 'resources' / 'demo-packs'
INSTALLED = {'service-agency', 'retail-shop', 'trader'}


def samples():
    """Real names, verticals, stories and counts from the shipped sample packs."""
    out = []
    for entry in json.loads((PACKS / 'catalog.json').read_text(encoding='utf-8')):
        pack = json.loads((PACKS / entry['file']).read_text(encoding='utf-8'))
        story = pack.get('learning_story', {})
        out.append({'id': entry['id'], 'name': pack['name'], 'kind': entry['business'], 'origin': story.get('origin', ''),
                    'logo': story.get('logo', {}).get('path', ''), 'accounts': len(pack.get('accounts', [])),
                    'parties': len(pack.get('document_parties', [])), 'version': entry['version']})
    return out


def chrome_end(current=0):
    stage = 'Done' if current >= 5 else f'Stage {current + 1} of 5'
    return f'<span class="wp-step">{stage} · <a class="link" href="#">English</a></span>'


def stage(title, sub, body, actions, help_html='', eyebrow='', current=0):
    eyebrow_html = f'<p class="eyebrow">{e(eyebrow)}</p>' if eyebrow else ''
    return (f'<section class="wp-card bench-stage"><div class="bench-head wp-head">{eyebrow_html}'
            f'<div class="wp-title-row"><h1 class="bench-heading">{e(title)}</h1>{help_html}</div>'
            f'{f"<p class=\"bench-sub\">{sub}</p>" if sub else ""}</div><div class="bench-body">{body}</div>'
            f'{f"<div class=\"bench-actions\">{actions}</div>" if actions else ""}</section>')


def back_next(next_label='Continue', note='Nothing is created yet.', back=True):
    back_html = '<a class="btn btn-secondary" href="#">Back</a>' if back else '<a class="btn btn-secondary" href="/companies">Cancel</a>'
    return f'<span class="fine-print">{e(note)}</span><div class="bench-action-pair">{back_html}<button class="btn btn-primary" type="submit">{e(next_label)}</button></div>'


def o1_start():
    page_help = help('page', 'Starting points',
                     'New business: empty books from the start date. Sample structure: a fictional company\'s chart, modules, customers, suppliers and products, with none of its transactions. '
                     'Past records: a business that already has balances and unpaid documents; they come in through the opening cutover after creation. Full sample: practice only.',
                     link=('Guide: your first business', WIKI + 'Getting-Started', True))
    choices = [
        ('fresh', 'New business, starts at zero', 'No balances, no unpaid documents. Choosing this confirms it.', 'Most common', False),
        ('skeleton', 'A sample company\'s structure', 'Its chart, modules, contacts and products. Zero balances.', 'Choose the company below', True),
        ('existing', 'Bring past records', 'Balances and open documents come in afterwards.', 'Opening cutover follows', False),
        ('sample', 'Explore a full sample', 'Fictional history in a separate company.', 'Practice only', False),
    ]
    cards = ''.join(
        f'<label class="choice-card{" is-quiet" if key == "sample" else ""}"><input type="radio" name="start_mode" value="{key}"{" checked" if checked else ""}>'
        f'<span class="choice-body"><span class="choice-title">{e(title)}</span><span class="choice-detail">{e(detail)}</span><span class="choice-tag">{e(tag)}</span></span></label>'
        for key, title, detail, tag, checked in choices)
    gallery = []
    for i, s in enumerate(samples()):
        installed = s['id'] in INSTALLED
        state = (f'<span class="badge badge-posted"><span class="badge-dot"></span>Installed</span>' if installed
                 else f'<span class="badge badge-unpaid">On phpledger.com</span>')
        button = ('' if installed else f'<button type="button" class="btn btn-secondary">Install {e(s["version"])}</button>')
        gallery.append(
            f'<label class="sample-card"><input type="radio" name="sample_pack" value="{e(s["id"])}"{" checked" if s["id"] == "service-agency" else ""}>'
            f'<span class="sample-card-top"><img src="{e(s["logo"])}" alt="" width="28" height="28"><span><span class="sample-card-name">{e(s["name"])}</span><br><span class="sample-card-kind">{e(s["kind"])}</span></span></span>'
            f'<p class="sample-card-story">{e(s["origin"])}</p>'
            f'<p class="sample-card-brings">{s["accounts"]} accounts · {s["parties"]} contacts</p>'
            f'<span class="sample-card-state">{state}{button}</span></label>')
    body = (
        f'<fieldset class="choice-grid is-four"><legend class="sr-only">Starting point</legend>{cards}</fieldset>'
        '<div class="gallery-head"><h2>Choose the company whose structure you want</h2>'
        '<p>11 companies · 3 installed · the rest install here in one click as signed data-only packages (CC0) from phpledger.com and bring you straight back · '
        '<a class="link" href="https://phpledger.com/directory/" target="_blank" rel="noopener noreferrer">browse the directory</a></p></div>'
        f'<fieldset class="sample-gallery"><legend class="sr-only">Sample company</legend>{"".join(gallery)}</fieldset>'
        '')
    return setup_page('Start a business', ONBOARD_STEPS, 0,
                      stage('What are you starting from?', 'Nothing is created until the last stage. You can leave and come back.',
                            body, back_next('Continue', back=False), page_help, current=0),
                      'O-1 start', wide=True, chrome_end=chrome_end(0))


def o2_business():
    page_help = help('page', 'Business details',
                     'Country comes first: the legal-form list, the registrar and the numbers printed on invoices all follow it. Country, currency and time zone are suggestions you can change; none of them activates a tax rule.',
                     'The invoice details are optional now and editable later in Company profile.')
    countries = [(code, c['name']) for code, c in COUNTRIES.items()]
    currencies = [('PKR', 'PKR — Pakistani rupee'), ('AED', 'AED — UAE dirham'), ('INR', 'INR — Indian rupee'), ('SGD', 'SGD — Singapore dollar'),
                  ('GBP', 'GBP — Pound sterling'), ('USD', 'USD — US dollar'), ('EUR', 'EUR — Euro')]
    year_ends = [('06-30', '30 June — common in Pakistan'), ('12-31', '31 December — calendar year'), ('03-31', '31 March'), ('09-30', '30 September'), ('custom', 'Another date…')]
    zones = [('Asia/Karachi', 'Asia/Karachi (PKT, UTC+5)'), ('Asia/Dubai', 'Asia/Dubai'), ('Asia/Kolkata', 'Asia/Kolkata'), ('Asia/Singapore', 'Asia/Singapore'),
             ('Europe/London', 'Europe/London'), ('Europe/Tallinn', 'Europe/Tallinn'), ('America/New_York', 'America/New_York'), ('UTC', 'UTC')]
    pk = COUNTRIES['PK']
    form_help = help('legal-form', 'Legal form', 'Choose the form your registration papers use. The names differ by country, so pick the country first and this list follows it.',
                     'It decides who the owners are (owner, partners, members or shareholders) and how money comes out (drawings, partner shares or dividends). It does not decide legal status and activates no tax rule.')
    legal_form = (f'<div class="field span-12"><div class="inline-heading"><label class="field-label" for="legal_form">Legal form <span class="required-marker" aria-hidden="true">*</span></label>{form_help}'
                  f'<span class="field-chip" data-country-registrar>Names as used in Pakistan</span></div>'
                  f'<select class="select" id="legal_form" name="legal_form" required>{options_html("PK", "pvt_ltd")}</select>'
                  f'<p class="form-guide" data-form-guide>{guide_text("PK", "pvt_ltd")}</p></div>')
    left = ''.join([
        '<div class="wp-grid">',
        field('name', 'Business name', text_input('name', 'BixiSoft Pvt. Ltd', required=True), 6, required=True,
              help_html=help('biz-name', 'Business name', 'The name your team will recognise in the menu. It can be changed later.')),
        field('country_code', 'Country', select('country_code', countries, 'PK'), 6, chip='Suggested',
              help_html=help('country', 'Country', 'Where the business is registered. It picks the legal-form names, the registrar and the numbers on invoices, and suggests a currency.',
                             'Detected once per session from your connection; not stored anywhere else. Change it freely.')),
        legal_form,
        field('currency', 'Currency', select('currency', currencies, 'PKR', required=True), 4, required=True, chip='Suggested',
              help_html=help('currency', 'Currency', 'One book, one currency. Amounts in other currencies are recorded against this one at the rate on the day.', 'It cannot be changed once anything is posted.')),
        field('fiscal_year_end', 'Financial year end', select('fiscal_year_end', year_ends, '06-30', required=True), 4, required=True,
              help_html=help('fye', 'Financial year end', 'The last day of your accounting year. Reports and the year-end close follow it.', 'Pakistan companies usually close on 30 June; many others use 31 December.')),
        field('start_date', 'Accounting start date', text_input('start_date', '2026-09-25', 'date', True), 4, required=True,
              help_html=help('start-date', 'Accounting start date', 'The first date these books cover. Nothing can be dated before it, and the first period runs from here to the year end.',
                             'For a new business use today or the day you started trading. Bringing past records: the day after your last closed period.')),
        field('business_timezone', 'Time zone', select('business_timezone', zones, 'Asia/Karachi'), 4, chip='From device',
              help_html=help('timezone', 'Time zone', 'Used for reminders and recurring schedules. Accounting dates are written as entered and never shift.')),
        '</div>',
    ])
    labels, ph = pk['labels'], pk['placeholders']
    right = ''.join([
        '<div class="side-panel"><div class="side-panel-head"><h2>Printed on invoices and receipts</h2><span class="optional">optional · editable later in Company profile</span></div><div class="wp-grid">',
        field('legal_name', 'Legal name', text_input('legal_name', 'BixiSoft (Private) Limited'), 6),
        field('trading_name', 'Trading name', text_input('trading_name', 'BixiSoft'), 6),
        f'<div class="field span-6"><div class="inline-heading"><label class="field-label" for="registration_number" data-country-label="reg_number">{labels["reg_number"]}</label></div>'
        f'<input class="input" id="registration_number" name="registration_number" value="{ph["reg_number"]}" data-country-placeholder="reg_number"></div>',
        f'<div class="field span-6"><div class="inline-heading"><label class="field-label" for="registration_authority" data-country-label="reg_authority">{labels["reg_authority"]}</label></div>'
        f'<input class="input" id="registration_authority" name="registration_authority" value="SECP" data-country-authority></div>',
        f'<div class="field span-6"><div class="inline-heading"><label class="field-label" for="tax1" data-country-label="tax1">{labels["tax1"]}</label>'
        + help('tax-ids', 'Tax numbers on invoices', 'The numbers your customers expect to see on an invoice in this country. Leave one empty if you are not registered for it; nothing is filed from here.')
        + f'</div><input class="input" id="tax1" name="tax1" value="{ph["tax1"]}" data-country-placeholder="tax1"></div>',
        f'<div class="field span-6" data-country-field="tax2"><div class="inline-heading"><label class="field-label" for="tax2" data-country-label="tax2">{labels["tax2"]}</label></div>'
        f'<input class="input" id="tax2" name="tax2" value="" placeholder="{ph["tax2"]}" data-country-placeholder="tax2"></div>',
        field('address_line1', 'Address', text_input('address_line1', 'Office 12, Software Technology Park, Islamabad'), 12),
        field('phone', 'Phone', text_input('phone', '+92 51 1234567', 'tel'), 6),
        field('email', 'Email on documents', text_input('email', 'accounts@example.com', 'email'), 6),
        '<div class="field span-12"><div class="inline-heading"><label class="field-label" for="doc-logo">Logo on documents</label></div>'
        f'<div class="dropzone"><span class="dropzone-preview">{icon("building")}</span><span class="dropzone-text"><strong>Uses your installation logo</strong> · drop another one here to print a different logo</span>'
        '<input class="sr-only" id="doc-logo" type="file"><label class="btn btn-secondary btn-sm" for="doc-logo">Change</label></div></div>',
        '</div></div>',
    ])
    grid = (f'<form class="o2-columns" id="stage-form" method="post" action="/onboarding" data-ready-button="#continue" data-legal-forms>'
            f'<div class="o2-main">{left}</div><div class="o2-side">{right}</div></form>'
            f'<script type="application/json" id="legal-forms-data">{as_json()}</script>')
    actions = ('<span class="fine-print">Nothing is created yet. Mockup: change the country to see the list, the guidance and the invoice numbers follow it.</span><div class="bench-action-pair"><a class="btn btn-secondary" href="#">Back</a>'
               '<button class="btn btn-primary" id="continue" type="submit" form="stage-form">Continue</button></div>')
    return setup_page('Business details', ONBOARD_STEPS, 1,
                      stage('Name this business', 'Country first: the legal forms, the registrar and the invoice numbers are shown in that country\'s own words.', grid, actions, page_help, current=1),
                      'O-2 business', wide=True, chrome_end=chrome_end(1))


def o3_owners_money():
    page_help = help('page', 'Owners, bank and cash',
                     'Who owns the business and where its money sits. Every posting needs a specific account; the group "Cash and cash equivalents" itself never takes an entry.',
                     'Opening money is optional. It is posted on the start date as capital introduced (equity) or an owner loan (a liability the business repays).')
    owners_help = help('owners', 'Owners', 'People or entities with a stake. For a company they are shareholders and the share ledger records the actual shares after setup. '
                       'For a partnership the profit share becomes the partners\' ratio.')
    money_help = help('money', 'Bank and cash accounts', 'One row per real place money sits: each bank account, each till drawer, each person\'s petty cash. '
                      'Postings go to these, never to the group above them.', 'The first row renames the starter account, so nothing posts to a vague "Cash and bank".')
    roles = [('director_shareholder', 'Director & shareholder'), ('shareholder', 'Shareholder'), ('partner', 'Partner'), ('owner', 'Owner')]
    owners = ''.join(
        f'<tr><td><input class="input" value="{e(n)}" aria-label="Owner name"></td><td>{select(f"role-{i}", roles, r)}</td>'
        f'<td class="is-amount"><input class="input" value="{s}" aria-label="Share percent"></td><td><button type="button" class="btn btn-ghost btn-icon" aria-label="Remove">{icon("x")}</button></td></tr>'
        for i, (n, r, s) in enumerate([('Rana Mansoor Akbar Khan', 'director_shareholder', '60'), ('Sara Khan', 'shareholder', '40')]))
    kinds = [('bank', 'Bank account'), ('till', 'Cash till'), ('petty', 'Petty cash')]
    sources = [('', '—'), ('capital_introduced', 'Capital introduced'), ('owner_loan_received', 'Owner loan')]
    money_rows = [('bank', 'Meezan Bank — current 0123', '', '1,500,000', 'capital_introduced'),
                  ('till', 'Shop till', 'Ahmed Raza', '0', ''),
                  ('petty', 'Petty cash — Ali Khan', 'Ali Khan', '20,000', 'owner_loan_received')]
    money = ''.join(
        f'<tr><td>{select(f"kind-{i}", kinds, k)}</td><td><input class="input" value="{e(n)}" aria-label="Account name"></td>'
        f'<td><input class="input" value="{e(c)}" aria-label="Custodian" placeholder="—"></td><td class="is-amount"><input class="input" value="{a}" aria-label="Opening amount"></td>'
        f'<td>{select(f"source-{i}", sources, s)}</td><td><button type="button" class="btn btn-ghost btn-icon" aria-label="Remove">{icon("x")}</button></td></tr>'
        for i, (k, n, c, a, s) in enumerate(money_rows))
    tree = ('<pre class="tree-note"><span class="is-group">1-100  Cash and cash equivalents        group · never receives an entry</span>\n'
            '  ├ 1-100-10001  <strong>Meezan Bank — current 0123</strong>   bank\n'
            '  ├ 1-100-10002  <strong>Shop till</strong>                    cash · Ahmed Raza\n'
            '  └ 1-100-10003  <strong>Petty cash — Ali Khan</strong>        cash · Ali Khan</pre>')
    body = (
        '<div class="two-up">'
        f'<div class="sub-panel"><h2>Owners {owners_help}</h2><table class="mini-table owners"><thead><tr><th>Name</th><th>Role</th><th>Share %</th><th></th></tr></thead><tbody>{owners}</tbody></table>'
        f'<div><button type="button" class="btn btn-secondary btn-sm">{icon("plus")} Add owner</button></div>'
        '<p class="field-hint">Private limited company: the percentages are a note until shares are issued in the share ledger after setup.</p></div>'
        f'<div class="sub-panel"><h2>Bank and cash accounts {money_help}</h2><table class="mini-table money"><thead><tr><th>Kind</th><th>Name</th><th>Custodian</th><th>Opening amount (PKR)</th><th>From</th><th></th></tr></thead><tbody>{money}</tbody></table>'
        f'<div><button type="button" class="btn btn-secondary btn-sm">{icon("plus")} Add account</button></div>{tree}</div></div>')
    return setup_page('Owners, bank and cash', ONBOARD_STEPS, 2,
                      stage('Owners, bank and cash', 'Who owns BixiSoft, and where its money sits. At least one bank or cash account is needed; opening amounts are optional.',
                            body, back_next(), page_help, current=2),
                      'O-3 owners and money', wide=True, chrome_end=chrome_end(2))


def o4_features_accounts():
    page_help = help('page', 'Features and accounts',
                     'Turn on what this business needs; anything else can be switched on later in Modules. Names of accounts can be edited now; codes and classifications stay in this release.',
                     'Private limited company in Pakistan: after setup, register share capital in the share ledger and add NTN/STRN to documents. These choices activate no tax rule.')
    features = [
        ('core', 'Accounting core', 'Journals, reports, periods. Always on.', True, True),
        ('ar', 'Invoices (receivables)', 'Customers, invoices, receipts against them.', True, False),
        ('ap', 'Bills (payables)', 'Suppliers, bills, payments against them.', True, False),
        ('inventory', 'Inventory', 'Products, stock on hand, cost of sales.', True, False),
        ('inventory-locations', 'Stock locations', 'Warehouses and vans. Needs Inventory.', False, False),
        ('purchasing', 'Purchasing', 'Purchase orders and goods receipts. Needs Bills and Inventory.', False, False),
        ('trading', 'Trading documents', 'Pack, discount, free goods, sales staff on documents. Needs Invoices and Inventory.', False, False),
        ('fixed-assets', 'Fixed assets', 'Asset register and depreciation runs.', False, False),
        ('pos-showcase', 'Cash POS showcase', 'Illustration only; not for real sales.', False, False),
    ]
    cards = ''.join(
        f'<label class="choice-card"><input type="checkbox" name="selected_features[]" value="{k}"{" checked" if on else ""}{" disabled" if locked else ""}>'
        f'<span class="choice-body"><span class="choice-title">{e(t)}</span><span class="choice-detail">{e(d)}</span></span></label>'
        for k, t, d, on, locked in features)
    rows = [
        ('1-100-00000-00', 'Cash and cash equivalents', 'Group', True), ('1-100-10001-00', 'Meezan Bank — current 0123', 'Asset · bank', False),
        ('1-100-10002-00', 'Shop till', 'Asset · cash', False), ('1-100-10003-00', 'Petty cash — Ali Khan', 'Asset · cash', False),
        ('1-110-00000-00', 'Trade and other receivables', 'Group', True), ('1-110-10001-00', 'Accounts receivable', 'Asset · receivables', False),
        ('1-120-10001-00', 'Supplier advances (prepayments)', 'Asset', False), ('2-100-00000-00', 'Trade and other payables', 'Group', True),
        ('2-100-10001-00', 'Accounts payable', 'Liability · payables', False), ('2-110-10001-00', "Owner's loan account", 'Liability', False),
        ('3-100-10001-00', 'Share capital', 'Equity · owner equity', False), ('4-100-10001-00', 'Sales and service income', 'Income', False),
        ('5-100-10001-00', 'General expenses', 'Expense', False),
    ]
    table = ''.join(
        (f'<tr class="is-group"><td>{c}</td><td>{e(n)}</td><td>{t}</td></tr>' if g
         else f'<tr><td>{c}</td><td><input class="input" value="{e(n)}" aria-label="Name for {c}"></td><td>{t}</td></tr>') for c, n, t, g in rows)
    body = (
        f'<fieldset class="feature-grid"><legend class="sr-only">Features</legend>{cards}</fieldset>'
        '<div class="gallery-head"><h2>Chart of accounts · 24 accounts, 19 headings</h2><p>Rename anything now. Codes, classifications and purposes are fixed in this release; deactivate unused accounts later without losing history.</p></div>'
        f'<div class="chart-edit" tabindex="0"><table class="table"><thead><tr><th>Code</th><th>Name</th><th>Classification</th></tr></thead><tbody>{table}'
        '<tr><td colspan="3" class="muted">… 11 more accounts</td></tr></tbody></table></div>')
    return setup_page('Features and accounts', ONBOARD_STEPS, 3,
                      stage('Features and accounts', 'Invoices, bills and inventory are on for a trading company. Everything else stays off until you need it.',
                            body, back_next('Review and continue'), page_help, current=3),
                      'O-4 features and accounts', wide=True, chrome_end=chrome_end(3))


def o5_review():
    page_help = help('page', 'Review and create', 'Confirming records exactly what is listed here. Nothing else is posted, imported or sent.')
    summary = ''.join(f'<div><dt>{e(k)}</dt><dd>{e(v)}</dd></div>' for k, v in [
        ('Business', 'BixiSoft Pvt. Ltd'), ('Legal form', 'Private limited company — (Private) Limited, SECP'), ('Country', 'Pakistan'),
        ('Currency', 'PKR'), ('Financial year end', '30 June'), ('Accounting start', '25 Sep 2026'),
        ('Time zone', 'Asia/Karachi'), ('Starting point', "Cedar Studio's structure"), ('Features', 'Invoices, Bills, Inventory')])
    money = ''.join(f'<tr><td>{e(n)}</td><td>{k}</td><td class="amount">{a}</td><td>{s}</td></tr>' for n, k, a, s in [
        ('Meezan Bank — current 0123', 'Bank', '1,500,000', 'Capital introduced'), ('Shop till', 'Cash · Ahmed Raza', '0', '—'),
        ('Petty cash — Ali Khan', 'Cash · Ali Khan', '20,000', 'Owner loan')])
    will = ''.join(f'<li>{icon("circle-check")}<span>{t}</span></li>' for t in [
        '<strong>BixiSoft Pvt. Ltd</strong> and its primary book in PKR, starting 25 Sep 2026, year end 30 June',
        '<strong>24 accounts</strong>: 21 from the neutral chart plus the 3 bank and cash accounts you named, under their groups',
        'Invoice details saved to <strong>Company profile</strong> (legal name, SECP number, NTN, address)',
        '<strong>2 owners</strong> registered: Rana Mansoor Akbar Khan 60%, Sara Khan 40%',
        '<strong>2 opening journals</strong> dated 25 Sep 2026: capital introduced <span class="num">PKR 1,500,000</span> into Meezan Bank; owner loan <span class="num">PKR 20,000</span> into Petty cash — Ali Khan',
        'Cash policy <strong>strict</strong>: money leaves an account only when it is there. Change it later in Accounting policies.'])
    body = (
        '<div class="review-split"><div>'
        f'<dl class="summary-grid">{summary}</dl>'
        '<h2 class="section-title mt-3 mb-2">Bank and cash accounts</h2>'
        f'<table class="table"><thead><tr><th>Account</th><th>Kind</th><th class="amount">Opening (PKR)</th><th>From</th></tr></thead><tbody>{money}</tbody></table>'
        '</div><div class="review-side">'
        f'<div class="contents-card"><h2>When you confirm</h2><ul class="will-do">{will}</ul></div>'
        '<div class="contents-card is-muted"><h2>Not created</h2><p class="field-hint">No invoices, bills, stock or transactions. Cedar Studio\'s history stays out; only its structure comes across, at zero.</p></div>'
        '</div></div>')
    return setup_page('Review and create', ONBOARD_STEPS, 4,
                      stage('Check everything before it is created', 'The last chance to change anything: Back keeps what you typed.', body,
                            back_next('Create BixiSoft Pvt. Ltd', note='Confirming records this exact setup.'), page_help, current=4),
                      'O-5 review', wide=True, chrome_end=chrome_end(4))


def o6_ready():
    manifest = ''.join(f'<li class="manifest-row">{TICK}{t}</li>' for t in [
        '24 accounts created', '2 owners registered', '3 bank and cash accounts', 'PKR 1,520,000 opening money posted in 2 journals',
        'Financial year ends 30 June · first period open from 25 Sep 2026'])
    inner = ('<section class="wp-card"><div class="done-body"><div class="bench-head"><h1 class="bench-heading size-lg">BixiSoft Pvt. Ltd is ready.</h1>'
             '<p class="bench-sub">You are signed in as its owner. Home shows what to do next.</p></div>'
             f'<ul class="manifest-card">{manifest}</ul></div>'
             '<div class="bench-actions"><a class="btn btn-secondary" href="/onboarding">Set up another business</a><a class="btn btn-primary" href="/home">Open BixiSoft Pvt. Ltd</a></div></section>')
    return setup_page('Business ready', ONBOARD_STEPS, 5, inner, 'O-6 ready', wide=False, step_text='Done')


FRAMES = [
    ('o1-start.html', 'O-1 What are you starting from?', o1_start),
    ('o2-business.html', 'O-2 Name this business', o2_business),
    ('o3-owners-money.html', 'O-3 Owners, bank and cash', o3_owners_money),
    ('o4-features-accounts.html', 'O-4 Features and accounts', o4_features_accounts),
    ('o5-review.html', 'O-5 Check everything before it is created', o5_review),
    ('o6-ready.html', 'O-6 Business ready', o6_ready),
]
