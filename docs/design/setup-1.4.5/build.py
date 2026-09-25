"""Build the 1.4.5 setup redesign frames.

    python build.py            # writes *.html next to this file

View them with a static server that maps /assets/ to www/phpledger/public/assets and / to this
folder (the real compiled app.css, fonts, logos and sample-company logos are then served exactly as
the application serves them): python serve.py. Nothing here is loaded by the application.
"""
from html import escape as e
from pathlib import Path

import frames_app
import frames_install
import frames_onboarding
from ui import document

HERE = Path(__file__).parent

ANSWERS = [
    ('I-1', 'Help "?" beside every field and the title, bubble anchored to it · TLS under Advanced · smaller fields on one grid · required asterisks · green button when valid · above the fold'),
    ('I-2', 'No separate "Install database" click: the connection fact heads the progress screen'),
    ('I-3', 'File-not-writable is a full step with instructions for the detected environment'),
    ('I-4', 'Eye toggle, Generate, 6-character minimum · telemetry checkboxes gone, one sentence with a link · required asterisks · above the fold'),
    ('I-5', 'Sign-in details to note down, installation facts, operator key'),
    ('O-1', 'One starting question, no duplicate "how much structure" stage, no confirmation checkbox · sample gallery with logo, story, what it brings, Install in place'),
    ('O-2', "Country first: legal forms, registrar and invoice numbers in that country's own words, each with a one-line guide (try ?country=GB) · suggestions editable with a chip saying where they came from · start date explained · invoice details captured"),
    ('O-3', 'Owners and their shares · named bank, till and petty-cash accounts under the group · opening money as capital or owner loan'),
    ('O-4', 'Features as cards · account names editable before creation'),
    ('O-5', 'Review says exactly what will be created; no empty half page'),
    ('O-6', 'Ready screen hands over to Home'),
    ('H-1', 'Getting-started guide in place of the empty dashboard · Expense/Bill wait for money in · Help moved to the top bar, no pinned sidebar footer · distinct icons that take colour'),
    ('P-1', 'Packages as Installed / Sample companies / Directory / Upload tabs with per-business switches'),
]


def index(frames):
    rows = ''.join(f'<tr><td><a class="link" href="/{f}">{e(t)}</a></td><td>{e(dict(ANSWERS).get(t.split()[0], ""))}</td></tr>' for f, t in frames)
    main = (
        '<main class="mock-index"><h1>PHP Ledger 1.4.5 · installer, business setup and first Home</h1>'
        '<p>Static frames on the real compiled stylesheet. Each answers the owner\'s 25 September 2026 screenshot notes; the map is in README.md. '
        'Screens are sized for a 1366×768 laptop with nothing below the fold. Add <code>?help=db-host</code> to open a bubble, <code>?env=xampp</code> on I-3, '
        '<code>?country=GB</code> on O-2, <code>?tab=directory</code> on P-1.</p>'
        f'<table class="table"><thead><tr><th>Frame</th><th>What it answers</th></tr></thead><tbody>{rows}</tbody></table>'
        '<h2>Decisions the frames assume</h2><ul>'
        '<li>1.4.5 maintenance patch on codex/release-1.4.1.</li>'
        '<li>Installation notice is always sent (amends B16); registration stays opt-in and leaves the installer.</li>'
        '<li>New businesses get the strict cash-shortfall policy; money in is always possible, money out needs money in.</li>'
        '<li>"Cash and cash equivalents" is a group; the wizard creates named bank, till and petty-cash accounts under it.</li>'
        '<li>Legal forms, registrar and invoice numbers are shown in the words of the chosen country (Pakistan, UAE, India, Singapore, UK, US, Estonia; neutral list elsewhere).</li>'
        '<li>Password minimum 6 characters, with an eye and a generator.</li></ul>'
        '<h2>Open for the owner at review</h2><ul>'
        '<li>Icon set: Tabler inlined with colour (shown) or Lucide (needs one fetch from cdn.jsdelivr.net, ISC licence).</li>'
        '<li>Completion page: password masked with Show (shown) or printed in clear.</li>'
        '<li>Expense and Bill while cash is zero: muted with a note (shown) or hidden.</li></ul></main>')
    return document('Frame index', main, 'index')


def main():
    frames = frames_install.FRAMES + frames_onboarding.FRAMES + frames_app.FRAMES
    for name, title, render in frames:
        (HERE / name).write_text(render(), encoding='utf-8')
        print('wrote', name)
    (HERE / 'index.html').write_text(index([(n, t) for n, t, _ in frames]), encoding='utf-8')
    print('wrote index.html')


if __name__ == '__main__':
    main()
