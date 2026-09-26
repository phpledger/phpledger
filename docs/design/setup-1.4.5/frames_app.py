"""Application frames: H-1 first Home with the getting-started guide, P-1 Packages with tabs."""
from html import escape as e

from ui import TICK, icon, shell_page

SAMPLES = [('service-agency', 'Cedar Studio', 'Professional services / agency'), ('retail-shop', 'Willow Corner Shop', 'Retail shop'), ('trader', 'Harbour Trade', 'Wholesale / trader')]
DIRECTORY = [('seasonal-business', 'Sunrise Garden Services', 'Seasonal business'), ('distributor', 'Harbor Supply Company', 'Distribution'),
             ('restaurant', 'Cedar Table', 'Restaurant / cafe'), ('membership-club', 'Riverside Community Club', 'Membership club'),
             ('pharmacy', 'Meadow Training Pharmacy', 'Training inventory'), ('jewelry-studio', 'Lantern Finch Jewelry Studio', 'Jewelry studio'),
             ('light-manufacturing', 'Maple Bench Works', 'Light manufacturing'), ('service-workshop', 'Wheel & Spoke Workshop', 'Service workshop')]


def h1_home():
    steps = [
        ('done', 'Invoice details saved', 'Legal name, SECP number, NTN · <a class="link" href="/company-profile">Company profile</a>'),
        ('done', 'Owners registered', 'Rana 60% · Sara 40% · <a class="link" href="/ownership">Owners and shares</a>'),
        ('done', 'Bank and cash accounts', 'Meezan Bank — current 0123, Shop till, Petty cash — Ali Khan'),
        ('current', 'Put money in', 'Every account is at zero. Record what the owners put in as capital, or lend to the business.'),
        ('pending', 'Add customers and suppliers', 'Needed before the first invoice or bill. Cedar Studio\'s 6 came across with no balances.'),
        ('pending', 'Record your first sale or expense', 'Receipts and invoices work now. Expenses and bills open once an account has money in it.'),
    ]
    items = ''
    for i, (state, title, detail) in enumerate(steps):
        num = TICK if state == 'done' else str(i + 1)
        action = ('<div class="gs-actions"><a class="btn btn-primary btn-sm" href="/owner">Record capital or an owner loan</a>'
                  '<a class="link" href="/bank-reconciliation">Import a bank statement instead</a></div>' if state == 'current' else '')
        items += (f'<li class="gs-step is-{state}"><span class="gs-num" aria-hidden="true">{num}</span><span class="gs-body">'
                  f'<span class="gs-title">{e(title)}</span><span class="gs-detail">{detail}</span>{action}</span></li>')
    quick = ''.join(f'<a class="btn btn-secondary btn-sm{" is-waiting" if waiting else ""}" href="{href}"{" aria-disabled=\"true\"" if waiting else ""}>{icon(ic)}{e(label)}</a>'
                    for href, label, ic, waiting in [('/receipts/new', 'Receipt', 'receipt', False), ('/ar?new=1', 'Invoice', 'file-invoice', False),
                                                     ('/general-journals/new', 'Journal', 'book-2', False), ('/expenses/new', 'Expense', 'receipt-2', True), ('/ap?new=1', 'Bill', 'file-dollar', True)])
    balances = ''.join(f'<li><span>{e(n)}</span><span class="num">PKR {a}</span></li>' for n, a in [('Meezan Bank — current 0123', '0.00'), ('Shop till', '0.00'), ('Petty cash — Ali Khan', '0.00')])
    main = (
        '<div class="home-head"><div><h1>Home</h1><p>BixiSoft Pvt. Ltd · 25 Sep 2026 · private limited company, Pakistan</p></div>'
        f'<a class="btn btn-primary" href="/transactions/new">{icon("plus")} New transaction</a></div>'
        '<section class="getting-started" aria-labelledby="gs-title"><div class="gs-head"><h2 id="gs-title">Getting started</h2><p>3 of 6 done · this guide goes away by itself when the last step is complete</p></div>'
        f'<ol class="gs-steps">{items}</ol></section>'
        f'<nav class="quick-actions" aria-label="Quick actions">{quick}<span class="quick-note">Expense and Bill unlock when a bank or cash account has a balance.</span></nav>'
        '<section aria-labelledby="home-attention"><h2 class="home-section-title" id="home-attention">Needs attention</h2>'
        f'<p class="empty-line">{icon("circle-check")}Nothing needs your attention yet.</p></section>'
        '<section aria-labelledby="home-balances"><h2 class="home-section-title" id="home-balances">Cash &amp; bank, and what\'s due</h2>'
        '<div class="grid grid-cols-1 gap-4 min-[900px]:grid-cols-3">'
        f'<div class="panel"><h3 class="text-sm font-medium text-ink-muted">Cash &amp; bank</h3><p class="amount-lg text-brand">PKR 0.00</p><ul class="balance-accounts">{balances}</ul></div>'
        '<div class="panel"><h3 class="text-sm font-medium text-ink-muted">Receivable</h3><p class="amount-lg">PKR 0.00</p><p class="text-xs text-ink-muted">Nothing invoiced yet</p></div>'
        '<div class="panel"><h3 class="text-sm font-medium text-ink-muted">Payable</h3><p class="amount-lg">PKR 0.00</p><p class="text-xs text-ink-muted">No bills yet</p></div></div></section>'
        '<section aria-labelledby="home-recent"><h2 class="home-section-title" id="home-recent">Recent activity</h2>'
        f'<p class="empty-line">{icon("history")}Saved documents and journals will appear here.</p></section>')
    return shell_page('Home', 'home', main, 'H-1 first Home', 'Home')


def p1_packages():
    def card(name, version, desc, badges, switch, logo=''):
        img = f'<img src="/assets/sample-companies/{logo}.svg" alt="" width="24" height="24">' if logo else ''
        return (f'<article class="pkg-card"><div class="pkg-card-head"><h3>{img}{e(name)}</h3><span class="pkg-meta">{e(version)}</span></div>'
                f'<p class="pkg-desc">{desc}</p><div class="pkg-foot"><div class="pkg-badges">{badges}</div>{switch}</div></article>')

    def badge(kind, text):
        return f'<span class="badge badge-{kind}"><span class="badge-dot"></span>{e(text)}</span>'

    def switch(on, disabled=False, label='On for BixiSoft'):
        return (f'<label class="switch"><input type="checkbox"{" checked" if on else ""}{" disabled" if disabled else ""}><span>{e(label)}</span></label>')

    required = ''.join(f'<div class="pkg-required"><strong>{e(n)}</strong><span>{e(d)}</span><span class="pkg-meta">{e(v)} · Required · Verified</span></div>' for n, d, v in [
        ('Accounting core', 'Journals, reports, periods and every protection.', '1.0.0'),
        ('Invoices (receivables)', 'Customers, invoices and receipts against them.', '1.0.0'),
        ('Bills (payables)', 'Suppliers, bills and payments against them.', '1.0.0')])
    optional = ''.join([
        card('Inventory', '1.0.0 · PHP Ledger', 'Products, stock on hand and cost of sales. Historical documents stay readable when it is off. <a class="link" href="#">More</a>',
             badge('posted', 'Active') + badge('info', 'Verified'), switch(True)),
        card('Stock locations', '1.1.0 · PHP Ledger', 'Warehouses and vans, transfers at carrying value, van settlement. Needs Inventory. <a class="link" href="#">More</a>',
             badge('unpaid', 'Inactive') + badge('info', 'Verified'), switch(False)),
        card('Purchasing', '1.0.0 · PHP Ledger', 'Purchase orders and goods receipts. Needs Bills and Inventory. <a class="link" href="#">More</a>',
             badge('unpaid', 'Inactive') + badge('info', 'Verified'), switch(False)),
        card('Trading documents', '1.0.0 · PHP Ledger', 'Pack, discount, free goods, sales staff and cash-on-invoice on documents. Needs Invoices and Inventory. <a class="link" href="#">More</a>',
             badge('unpaid', 'Inactive') + badge('info', 'Verified'), switch(False)),
        card('Fixed assets', '1.0.0 · PHP Ledger', 'Asset register, depreciation runs and disposals as a subsidiary ledger. <a class="link" href="#">More</a>',
             badge('unpaid', 'Inactive') + badge('info', 'Verified'), switch(False)),
        card('Cash POS showcase', '1.0.0 · PHP Ledger', 'An illustration of a cash counter. Sample companies only; not for real sales.',
             badge('unpaid', 'Inactive') + badge('info', 'Verified'), switch(False, disabled=True, label='Sample companies only')),
    ])
    installed_panel = (
        '<div class="pkg-toolbar"><p class="muted">Bundled modules and installed packages. The switch enables a module for the business you are in; it installs nothing and grants no user permission.</p></div>'
        f'<div class="pkg-stack">{required}</div><div class="pkg-grid">{optional}</div>'
        '<h2 class="section-title">Recent package changes</h2>'
        f'<p class="empty-line">{icon("history")}No package has been installed on this copy yet.</p>')
    samples = ''.join(card(n, '1.1.0 · PHP Ledger · CC0', f'{e(k)}. Fictional practice history or a zero-balance structure for a new business. Data only; never runs code.',
                           badge('posted', 'Installed') + badge('info', 'Verified'), '<a class="btn btn-secondary btn-sm" href="/onboarding?start=skeleton">Use in business setup</a>', logo=s)
                      for s, n, k in SAMPLES)
    samples_panel = (
        '<div class="pkg-toolbar"><p class="muted">Sample companies installed on this copy. Business setup offers their structure; Explore a full sample creates a separate practice company.</p></div>'
        f'<div class="pkg-grid">{samples}</div>')
    directory = ''.join(card(n, '1.1.0 · phpledger.com · CC0', f'{e(k)}. Signed data-only package; installing fetches it from phpledger.com and verifies the signature first.',
                             badge('unpaid', 'Not installed') + badge('info', 'Signed'), f'<button type="button" class="btn btn-secondary btn-sm">{icon("download")} Install</button>', logo=s)
                        for s, n, k in DIRECTORY)
    directory_panel = (
        f'<div class="pkg-toolbar"><p class="muted">Refreshed 25 Sep 2026 14:10 · Refresh and Install contact phpledger.com; opening this page does not.</p>'
        f'<button type="button" class="btn btn-secondary btn-sm">{icon("refresh")} Refresh directory</button></div>'
        f'<div class="pkg-grid">{directory}</div>')
    upload_panel = (
        '<div class="pkg-toolbar"><p class="muted">A package you upload has not been reviewed by the project. Uploading unpacks it and shows what it says about itself; nothing is installed and no code runs until you confirm on the next page.</p></div>'
        f'<div class="dropzone"><span class="dropzone-preview">{icon("package")}</span><span class="dropzone-text"><strong>Drop a package ZIP here</strong> · or choose a file · plugins and data-only samples</span>'
        '<input class="sr-only" id="package-zip" type="file" accept=".zip"><label class="btn btn-secondary btn-sm" for="package-zip">Choose file</label></div>'
        '<div><button type="button" class="btn btn-primary">Unpack and review</button></div>')
    main = (
        '<div class="page-header"><div><p class="eyebrow">Setup</p><h1 class="page-title">Packages</h1><p class="muted">What runs on this copy, what is switched on for BixiSoft, and what you can add.</p></div></div>'
        '<div class="tabs-seg" role="tablist" aria-label="Packages view" data-tabs="installed">'
        '<button type="button" class="tabs-seg-item" role="tab" data-tab="installed" aria-selected="true">Installed · 9</button>'
        '<button type="button" class="tabs-seg-item" role="tab" data-tab="samples" aria-selected="false">Sample companies · 3</button>'
        '<button type="button" class="tabs-seg-item" role="tab" data-tab="directory" aria-selected="false">Directory · 8 more</button>'
        '<button type="button" class="tabs-seg-item" role="tab" data-tab="upload" aria-selected="false">Upload</button></div>'
        f'<div data-tab-panel="installed" class="is-active">{installed_panel}</div><div data-tab-panel="samples">{samples_panel}</div>'
        f'<div data-tab-panel="directory">{directory_panel}</div><div data-tab-panel="upload">{upload_panel}</div>')
    return shell_page('Packages', 'packages', main, 'P-1 packages', 'Packages', setup_open=True)


FRAMES = [
    ('h1-home.html', 'H-1 First Home with the getting-started guide', h1_home),
    ('p1-packages.html', 'P-1 Packages with tabs and per-business switches', p1_packages),
]
