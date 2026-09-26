"""Shared markup for the 1.4.5 setup mockup frames.

The frames use the real compiled stylesheet (/assets/app.css) and the real class vocabulary
(install-frame, bench-*, tray-slot, field, input, help, shell-*), plus mock.css for the parts
that do not exist yet. Nothing here is served by the application; build.py writes static HTML.
"""
from html import escape as e

LOGO = '/assets/brand/phpledger-horizontal.png'
WIKI = 'https://github.com/phpledger/phpledger/wiki/'
TICK = ('<svg class="icon" viewBox="0 0 16 16" aria-hidden="true"><path d="M3.5 8.3l3.1 3.1L12.5 5" '
        'fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"/></svg>')


def icon(name, cls='icon', extra=''):
    return f'<svg class="{cls}" aria-hidden="true"{extra}><use href="/sprite.svg#{name}"/></svg>'


def help(id, title, text, here='', link=None, placement='start'):
    """A question mark whose bubble hangs from the trigger. link = (label, href, external)."""
    out = [f'<details class="help" data-help id="help-{id}"><summary><span aria-hidden="true">?</span>'
           f'<span class="sr-only">Explain {e(title)}</span></summary>',
           f'<div class="help-bubble help-bubble-{placement}" role="note"><p class="help-title">{e(title)}</p>'
           f'<p class="help-text">{e(text)}</p>']
    if here:
        out.append(f'<p class="help-text help-here">{e(here)}</p>')
    if link:
        label, href, external = link
        attrs = ' target="_blank" rel="noopener noreferrer"' if external else ''
        out.append(f'<a class="link" href="{href}"{attrs}>{e(label)}</a>')
    out.append('</div></details>')
    return ''.join(out)


def label(for_id, text, required=False, optional=False, help_html='', chip=''):
    req = ' <span class="required-marker" aria-hidden="true">*</span>' if required else ''
    opt = ' <span class="optional">(optional)</span>' if optional else ''
    chip_html = f'<span class="field-chip">{e(chip)}</span>' if chip else ''
    return (f'<div class="inline-heading"><label class="field-label" for="{for_id}">{e(text)}{req}{opt}</label>'
            f'{help_html}{chip_html}</div>')


def field(id, text, control, span=6, required=False, optional=False, help_html='', hint='', chip=''):
    hint_html = f'<p class="field-hint">{hint}</p>' if hint else ''
    return (f'<div class="field span-{span}">{label(id, text, required, optional, help_html, chip)}'
            f'{control}{hint_html}</div>')


def text_input(id, value='', type='text', required=False, placeholder='', extra=''):
    req = ' required' if required else ''
    ph = f' placeholder="{e(placeholder)}"' if placeholder else ''
    return f'<input class="input" id="{id}" name="{id}" type="{type}" value="{e(value)}"{req}{ph}{extra}>'


def password_input(id, value='', required=True):
    req = ' required' if required else ''
    return (f'<div class="input-affix"><input class="input" id="{id}" name="{id}" type="password" value="{e(value)}"{req} '
            f'autocomplete="new-password" minlength="6">'
            f'<button type="button" class="affix-btn" data-toggle-password="#{id}" aria-label="Show password">'
            f'{icon("eye")}{icon("eye-off", extra=" hidden")}</button></div>')


def select(id, options, current=None, required=False):
    req = ' required' if required else ''
    opts = ''.join(f'<option value="{e(v)}"{" selected" if v == current else ""}>{e(t)}</option>' for v, t in options)
    return f'<select class="select" id="{id}" name="{id}"{req}>{opts}</select>'


def tray(steps, current):
    slots = []
    for i, name in enumerate(steps):
        state = 'is-done' if i < current else 'is-current' if i == current else 'is-pending'
        aria = ' aria-current="step"' if i == current else ''
        slots.append(f'<li class="tray-slot {state}"{aria}>{TICK if i < current else ""}<span>{e(name)}</span></li>')
    return f'<ol class="bench-tray is-labelled" aria-label="Steps">{"".join(slots)}</ol>'


def document(title, main, note, body_class=''):
    return (f'<!doctype html>\n<html lang="en" dir="ltr"><head><meta charset="utf-8">'
            f'<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light">\n'
            f'<title>{e(title)} · PHP Ledger 1.4.5 mockup</title>\n'
            f'<link rel="stylesheet" href="/assets/app.css"><link rel="stylesheet" href="/mock.css">'
            f'<script src="/mock.js" defer></script>\n</head><body class="{body_class}">{main}'
            f'<aside class="mock-note"><a href="/">{e(note)}</a></aside></body></html>\n')


def setup_page(title, steps, current, body, note, wide=False, step_text=None, chrome_end=None):
    step = chrome_end if chrome_end is not None else f'<span class="wp-step">{e(step_text or f"Step {current + 1} of {len(steps)}")}</span>'
    chrome = (f'<div class="bench-chrome wp-chrome"><img class="bench-logo" src="{LOGO}" alt="PHP Ledger" width="2172" height="724">'
              f'{tray(steps, current)}{step}</div>')
    main = f'<main class="install-frame wp-frame"><div class="wp-shell{" is-wide" if wide else ""}">{chrome}{body}</div></main>'
    return document(title, main, note)


def card(heading, sub, body, actions, help_html='', eyebrow=''):
    eyebrow_html = f'<p class="eyebrow">{e(eyebrow)}</p>' if eyebrow else ''
    sub_html = f'<p class="bench-sub">{sub}</p>' if sub else ''
    actions_html = f'<div class="bench-actions">{actions}</div>' if actions else ''
    return (f'<section class="wp-card">{eyebrow_html}<div class="bench-head wp-head"><div class="wp-title-row">'
            f'<h1 class="bench-heading">{e(heading)}</h1>{help_html}</div>{sub_html}</div>'
            f'<div class="bench-body">{body}</div>{actions_html}</section>')


INSTALL_STEPS = ['Database', 'Build', 'Account', 'Done']
ONBOARD_STEPS = ['Start', 'Business', 'Owners & money', 'Features & accounts', 'Review']


# ---------- application shell (Home, Packages) ----------

NAV = [
    (None, [('/home', 'Home', 'home', 'home')]),
    ('Daily work', [('/transactions?kind=receipt', 'Receipts', 'receipt', 'receipts'),
                    ('/transactions?kind=expense', 'Expenses', 'receipt-2', 'expenses'),
                    ('/general-journals', 'Journals', 'book-2', 'journals')]),
    ('Sales', [('/ar', 'Invoices', 'file-invoice', 'ar'), ('/parties?role=customer', 'Customers', 'users', 'customers')]),
    ('Purchases', [('/ap', 'Bills', 'file-dollar', 'ap'), ('/parties?role=vendor', 'Suppliers', 'truck', 'suppliers')]),
    ('Banking', [('/bank-reconciliation', 'Bank reconciliation', 'building-bank', 'bank')]),
    ('Reports', [('/reports', 'All reports', 'report', 'reports'), ('/reports/profit-loss', 'Profit & loss', 'chart-line', 'pl'),
                 ('/reports/balance-sheet', 'Balance sheet', 'scale', 'bs'), ('/reports/trial-balance', 'Trial balance', 'list-details', 'tb')]),
]
SETUP_NAV = [('/accounts', 'Chart of accounts', 'list-details'), ('/ownership', 'Owners and shares', 'users'),
             ('/company-profile', 'Company profile', 'building'), ('/modules', 'Modules', 'adjustments-horizontal'),
             ('/packages', 'Packages', 'package'), ('/users', 'Users', 'user-plus'), ('/updates', 'Updates and privacy', 'refresh')]


def shell_page(title, view, main_html, note, crumb, setup_open=False):
    groups = []
    for group, items in NAV:
        links = ''.join(
            f'<a class="nav-item" href="{href}" title="{e(label_)}"{" aria-current=\"page\"" if key == view else ""}>'
            f'{icon(ic)}<span>{e(label_)}</span></a>' for href, label_, ic, key in items)
        groups.append((f'<p class="nav-group-label">{e(group)}</p>' if group else '') + links)
    setup_links = ''.join(
        f'<a class="nav-item" href="{href}" title="{e(label_)}"{" aria-current=\"page\"" if view == "packages" and href == "/packages" else ""}>'
        f'{icon(ic)}<span>{e(label_)}</span></a>' for href, label_, ic in SETUP_NAV)
    setup = (f'<details class="nav-group-collapsible"{" open" if setup_open else ""}><summary class="nav-group-summary"><span>Setup</span>'
             f'{icon("chevron-down")}</summary><div class="nav-group-body">{setup_links}</div></details>')
    sidebar = (
        '<aside class="shell-sidebar" id="workspace-navigation" aria-label="Main navigation">'
        f'<div class="shell-brand"><a class="shell-brand-link" href="/companies" aria-label="PHP Ledger businesses">'
        f'<img class="shell-logo" src="{LOGO}" alt="PHP Ledger" width="2172" height="724"></a></div>'
        '<details class="company-switcher"><summary class="company-switcher-trigger" aria-label="Current business: BixiSoft Pvt. Ltd. Switch business">'
        '<span class="company-switcher-mark" aria-hidden="true">B</span><span class="company-switcher-info"><strong>BixiSoft Pvt. Ltd</strong>'
        f'<span>Primary book · PKR</span></span>{icon("chevron-down", "icon company-switcher-caret")}</summary>'
        '<div class="menu-panel company-switcher-panel menu-panel-wide"><p class="menu-label">Owner</p>'
        '<a class="menu-item" href="/companies">Your businesses</a><a class="menu-item" href="/onboarding">Add a business</a></div></details>'
        f'<nav class="shell-nav" aria-label="Workspace">{"".join(groups)}{setup}</nav>'
        '</aside>')
    page_help = help('page', 'This page', 'Help for this screen opens here, beside the button, with a link to the guide.',
                     link=('Open the guide for Home', WIKI + 'Getting-Started', True), placement='end')
    topbar = (
        '<header class="shell-topbar">'
        f'<ol class="crumbs" aria-label="Breadcrumb"><li class="crumb"><a href="/companies">Workspace</a></li><li class="crumb"><span aria-current="page">{e(crumb)}</span></li></ol>'
        f'<div class="topbar-search"><button type="button" class="search-trigger">{icon("search")}<span>Search or jump to…</span><span class="kbd ms-auto">Ctrl K</span></button></div>'
        '<div class="topbar-actions">'
        f'<details class="help page-help" data-help id="help-page"><summary class="topbar-help">{icon("help-circle")}<span>Help</span></summary>'
        '<div class="help-bubble help-bubble-end" role="note"><p class="help-title">Help for this screen</p>'
        '<p class="help-text">What this page does, in plain words, and one link to the guide. The bubble opens beside the button, never at the bottom corner of the screen.</p>'
        f'<a class="link" href="{WIKI}Getting-Started" target="_blank" rel="noopener noreferrer">Open the guide (wiki)</a></div></details>'
        f'<details class="menu"><summary class="btn btn-primary btn-sm">{icon("plus")} New</summary><div class="menu-panel menu-panel-end"><p class="menu-label">Quick create</p>'
        '<a class="menu-item" href="/receipts/new">Receipt</a><a class="menu-item" href="/ar?new=1">Invoice</a><a class="menu-item" href="/general-journals/new">Journal entry</a>'
        '<a class="menu-item" href="/parties?new=1">Customer or supplier</a></div></details>'
        f'<details class="menu"><summary class="user-menu-trigger" aria-label="User menu"><span class="avatar">R</span>{icon("chevron-down")}</summary>'
        '<div class="menu-panel menu-panel-end"><p class="menu-label">Rana Mansoor Akbar Khan</p><p class="menu-item-static">rana@example.com</p>'
        '<a class="menu-item" href="/profile">Your profile</a><a class="menu-item" href="/companies">Switch business</a>'
        '<p class="menu-item-static">PHP Ledger 1.4.5</p><a class="menu-item" href="/logout">Sign out</a></div></details>'
        '</div></header>')
    main = (f'<div data-shell class="mock-shell">{sidebar}<div class="shell-body">{topbar}'
            f'<main class="shell-main"><div class="shell-main-inner">{main_html}</div></main></div></div>')
    return document(title, main, note)
