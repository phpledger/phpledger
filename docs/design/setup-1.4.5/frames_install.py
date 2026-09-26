"""Installer frames: I-1 database, I-2 build, I-3 settings by hand, I-4 account, I-5 complete."""
from ui import (INSTALL_STEPS, TICK, WIKI, card, field, help, icon, password_input, select, setup_page, text_input)

HOSTING = WIKI + 'Install-on-Shared-Hosting'
LOCAL = WIKI + 'Install-on-XAMPP-or-WAMP'
DOCKER = WIKI + 'Install-with-Docker-Desktop'


def i1_database():
    page_help = help('page', 'Connect your database',
                     'PHP Ledger keeps every transaction in one MySQL or MariaDB database. This step only checks that setup can reach it; nothing is written until the next step.',
                     'On your own computer setup creates the database for you. On shared hosting, create an empty database and a user in your control panel first, then copy the details here.',
                     ('Guide: install on shared hosting', HOSTING, True))
    form = ''.join([
        '<form class="wp-grid" id="database-form" method="post" action="/install" data-ready-button="#check-db">',
        field('public_url', "This site's address", text_input('public_url', 'https://localhost:18443', 'url', True), 12, required=True,
              help_html=help('site-address', "This site's address", 'The address people type to open PHP Ledger. It goes on sign-in links and printed documents.',
                             'Setup guessed it from the address you are using now. Change it only if you will open PHP Ledger from a different one.')),
        field('host', 'Database host', text_input('host', 'localhost', required=True), 6, required=True,
              help_html=help('db-host', 'Database host', 'Where MySQL runs. On your own computer it is localhost.',
                             'On shared hosting the control panel shows it under MySQL Databases; it is usually localhost there too.', ('Where to find it in cPanel', HOSTING, True))),
        field('port', 'Port', text_input('port', '3306', required=True, extra=' inputmode="numeric" pattern="[0-9]+"'), 3, required=True,
              help_html=help('db-port', 'Port', 'MySQL and MariaDB answer on 3306 unless your host or stack says otherwise. Setup found a database server on this computer at 3306.')),
        field('db_prefix', 'Table prefix', text_input('db_prefix', 'pl_', required=True, extra=' pattern="[a-z][a-z0-9_]{0,15}_" maxlength="17"'), 3, required=True,
              help_html=help('db-prefix', 'Table prefix', 'Every table name starts with this, so two installations can share one database.',
                             'Keep pl_ unless you need a second copy in the same database. It cannot be changed after installation.')),
        field('database', 'Database name', text_input('database', 'bixisoft_books', required=True), 6, required=True,
              help_html=help('db-name', 'Database name', 'On your own computer any name works: setup creates it if it does not exist.',
                             'On shared hosting use the exact name the panel shows, usually with your account name in front, like acme_books.')),
        field('user', 'Database user', text_input('user', 'root', required=True), 3, required=True,
              help_html=help('db-user', 'Database user', 'The account that may use this database. XAMPP, Laragon and MAMP use root.',
                             'On shared hosting it is the user you created next to the database and gave all privileges to.')),
        field('password', 'Database password', password_input('password', '', required=False), 3,
              help_html=help('db-password', 'Database password', 'The password of that user. Setup keeps it in a private file and never shows it again.',
                             'Local stacks ship root with no password, so leave it empty there.')),
        '<details class="wp-details span-12"><summary>' + icon('chevron-down') + 'Advanced: a database on another server<span class="optional">optional</span></summary>'
        '<div class="wp-details-body"><div class="wp-grid">'
        + field('db_ssl_ca', 'TLS CA certificate path', text_input('db_ssl_ca', ''), 12,
                help_html=help('db-tls', 'TLS CA certificate path', 'Only for a cloud database that requires an encrypted connection (PlanetScale, Aiven, DigitalOcean, Azure).',
                               'Enter the server-side path to the certificate file your provider gives you. The server identity is always verified; there is no switch to turn that off.'),
                hint='Leave empty for a database on this computer or on the same hosting account.')
        + '</div></div></details>',
        '</form>',
    ])
    body = (f'<p class="wp-note">{icon("info-circle")}<span><strong>Setting up on your own computer?</strong> XAMPP, Laragon and MAMP create a '
            f'<span class="snip">root</span> account with no password. Leave the password empty and setup does the rest, including creating the database itself. '
            f'A database server answered on this computer at port 3306.</span></p>' + form)
    actions = ('<span class="fine-print">MySQL 8.0+ or MariaDB 10.4+. No terminal needed. Server checks: all 14 passed.</span>'
               '<button class="btn btn-primary" id="check-db" type="submit" form="database-form">Check and install</button>')
    return setup_page('Connect your database', INSTALL_STEPS, 0,
                      card('Connect your database', 'Every transaction is stored here. Nothing is written until the check passes.', body, actions, page_help),
                      'I-1 database')


def i2_build():
    page_help = help('page', 'Preparing your database',
                     'Sixty-one numbered steps create the tables, reports and protective rules. Each one is checked on this server before it counts.',
                     'If the connection drops, reopen this page and enter the same database details: an interrupted step resumes where it stopped.')
    pins = []
    for cluster in range(9):
        cells = ''.join(f'<span class="pin {"is-done" if cluster * 7 + i < 24 else "is-current" if cluster * 7 + i == 24 else ""}"></span>'
                        for i in range(7) if cluster * 7 + i < 61)
        pins.append(f'<li class="pin-cluster">{cells}</li>')
    placed = ''.join(f'<li class="placed-chip">{TICK}{n}</li>' for n in ['Currencies and exchange rates', 'Customers and suppliers', 'Chart of accounts and journals'])
    body = (
        f'<div class="build-fact"><span class="reward-badge">{TICK}Connected</span><span><span class="snip">bixisoft_books</span> on <span class="snip">localhost:3306</span></span>'
        '<span class="muted">· created just now · signing in as root with no password, which is normal on your own computer</span></div>'
        '<div class="progress-wrap"><div><p class="install-progress-status" role="status"><span class="install-progress-step">Building the chart of accounts and journals</span>'
        '<span class="install-progress-count">Step 25 of 61 · 41%</span></p><div class="install-progress-track"><div class="install-progress-bar" id="install-progress-bar"></div></div></div>'
        f'<ul class="pin-strip">{"".join(pins)}</ul><div><p class="field-hint">Just placed</p><ul class="placed-row">{placed}</ul></div></div>')
    actions = '<span class="fine-print">Continuing automatically, nothing to click. Then setup saves its private settings and asks for your sign-in account.</span>'
    html = setup_page('Preparing your database', INSTALL_STEPS, 1, card('Preparing your database', '', body, actions, page_help), 'I-2 build')
    # The real page serves this width in a nonce-carrying <style>; an inline attribute is forbidden by the CSP.
    return html.replace('</head>', '<style>#install-progress-bar{width:41%}</style></head>')


def i3_settings_manual():
    page_help = help('page', 'Private settings file',
                     'It holds the database connection and this site\'s address. It sits outside public/, so a browser can never fetch it.',
                     'Setup writes it for you where the server allows. Here it could not, so you place the file once. Setup never overwrites an existing file.',
                     ('Guide: file permissions on shared hosting', HOSTING, True))
    facts = {
        'cpanel': ('Linux · Apache 2.4 · PHP 8.3 (FPM)', '/home/bixisoft/phpledger/www/phpledger/includes/config.local.php', '/home/bixisoft/public_html'),
        'xampp': ('Windows · Apache 2.4 (XAMPP) · PHP 8.3', 'C:\\xampp\\htdocs\\phpledger\\www\\phpledger\\includes\\config.local.php', 'C:\\xampp\\htdocs\\phpledger\\www\\phpledger\\public'),
        'docker': ('Linux container · Apache 2.4 · PHP 8.3', '/var/lib/phpledger/private/config.local.php (PL_INSTALL_DIRECTORY volume)', '/var/www/phpledger/www/phpledger/public'),
    }
    steps = {
        'cpanel': ['Choose <strong>Download private configuration</strong>. The file is named <span class="snip">config.local.php</span> and is complete.',
                   'Open <strong>File Manager</strong> in cPanel or Plesk, go to <span class="snip">phpledger/www/phpledger/includes/</span> and upload it there. Not inside <span class="snip">public/</span>.',
                   'Right-click the file → <strong>Change permissions</strong> → <span class="snip">600</span> (owner may read and write, nobody else).',
                   'Delete the downloaded copy from your computer, then choose <strong>I have placed it, check again</strong>.'],
        'xampp': ['Choose <strong>Download private configuration</strong>.',
                  'Save it as <span class="snip">C:\\xampp\\htdocs\\phpledger\\www\\phpledger\\includes\\config.local.php</span>. If Windows added <span class="snip">.txt</span>, remove it.',
                  'If Windows refuses, right-click the <span class="snip">includes</span> folder → Properties → Security and allow your user to write, or start XAMPP as your own user.',
                  'Choose <strong>I have placed it, check again</strong>.'],
        'docker': ['Setup writes this file into the private data volume (<span class="snip">PL_INSTALL_DIRECTORY</span>). The volume is read-only or owned by another user.',
                   'Give the volume back to the web server user, for example <span class="snip">docker compose exec phpledger chown -R www-data:www-data /var/lib/phpledger/private</span>, then check again.',
                   'Or download the file and copy it in with <span class="snip">docker cp config.local.php phpledger:/var/lib/phpledger/private/</span>.',
                   'Choose <strong>I have placed it, check again</strong>.'],
    }
    pick = ('<div class="env-pick"><span>Mockup only: the detected environment picks the instructions</span>'
            '<div class="tabs-seg" role="tablist" data-env-pick="cpanel">'
            '<button type="button" class="tabs-seg-item" role="tab" data-env="cpanel" aria-selected="true">cPanel or Plesk</button>'
            '<button type="button" class="tabs-seg-item" role="tab" data-env="xampp" aria-selected="false">XAMPP, WAMP or Laragon</button>'
            '<button type="button" class="tabs-seg-item" role="tab" data-env="docker" aria-selected="false">Docker Desktop</button></div></div>')
    dl = '<dl class="env-facts">'
    for key, (server, path, public) in facts.items():
        hidden = '' if key == 'cpanel' else ' hidden'
        dl += (f'<div data-env-fact="{key}"{hidden}><dt>This server</dt><dd>{server}</dd></div>'
               f'<div data-env-fact="{key}"{hidden}><dt>Private settings file</dt><dd><span class="snip">{path}</span></dd></div>'
               f'<div data-env-fact="{key}"{hidden}><dt>Folder served to visitors</dt><dd><span class="snip">{public}</span></dd></div>')
    dl += '</dl>'
    panels = ''
    for key, items in steps.items():
        lis = ''.join(f'<li class="fix-item"><span class="fix-num" aria-hidden="true">{i + 1}</span><span>{t}</span></li>' for i, t in enumerate(items))
        panels += f'<div data-env-panel="{key}"{" class=\"is-active\"" if key == "cpanel" else ""}><p class="fix-head">Place the file, then check again</p><ol class="fix-list">{lis}</ol></div>'
    body = pick + dl + panels
    actions = (f'<button class="btn btn-secondary" type="button">{icon("download")} Download private configuration</button>'
               '<button class="btn btn-primary" type="submit">I have placed it, check again</button>')
    sub = 'Setup keeps its settings in a private file outside the folder visitors can reach. This server refused to create it, so you place it once. Nothing else changes.'
    return setup_page('Place the private settings file', INSTALL_STEPS, 1, card('Your host does not let PHP write this file', sub, body, actions, page_help), 'I-3 settings by hand')


def i4_account():
    page_help = help('page', 'Your sign-in account',
                     'This account owns the installation: it creates businesses, invites users and changes settings. It is not your database user.',
                     'Keep the password somewhere safe. Until outgoing mail is configured there is no email reset; the operator key in your private folder is the recovery route.',
                     ('Guide: getting started', WIKI + 'Getting-Started', True))
    strength = ('<div class="pw-row"><span class="pw-strength" data-strength-for="#owner-password"><span class="meter"><span class="meter-fill"></span></span><span data-strength-label>Strong</span></span>'
                '<button type="button" class="btn btn-secondary btn-sm" data-generate-password="#owner-password, #owner-password-confirm">Generate a password</button></div>')
    form = ''.join([
        '<form class="wp-grid" id="account-form" method="post" action="/install" enctype="multipart/form-data" data-ready-button="#finish">',
        field('owner-name', 'Your name', text_input('owner-name', 'Rana Mansoor Akbar Khan', required=True), 6, required=True),
        field('owner-username', 'Username', text_input('owner-username', 'rana', required=True, extra=' minlength="3" maxlength="60" autocapitalize="none" spellcheck="false"'), 6, required=True,
              help_html=help('username', 'Username', '3 to 60 letters, numbers, dots, dashes or underscores, such as owner or ali.khan.', 'You sign in with this or with your email address.')),
        field('owner-email', 'Email address', text_input('owner-email', 'rana@example.com', 'email', True), 12, required=True,
              help_html=help('email', 'Email address', 'An alternative way to sign in and, later, where notifications go. Nothing is sent during setup.')),
        field('owner-password', 'Password', password_input('owner-password', 'bixi-books-2026'), 6, required=True,
              help_html=help('password', 'Password', 'At least 6 characters; longer is safer. The eye shows what you typed.',
                             'Generate a password fills both boxes and shows the result so you can copy it into your password manager.'),
              hint=strength),
        field('owner-password-confirm', 'Type it again', password_input('owner-password-confirm', 'bixi-books-2026'), 6, required=True),
        '<div class="field span-12"><div class="inline-heading"><label class="field-label" for="owner-logo">Your logo <span class="optional">(optional)</span></label>'
        + help('logo', 'Your logo', 'Replaces the PHP Ledger logo in the menu and on the sign-in page. Each business can still print its own logo on documents.')
        + f'</div><div class="dropzone"><span class="dropzone-preview">{icon("plus")}</span><span class="dropzone-text"><strong>Drop a PNG, JPEG or WebP here</strong> · up to 1 MB · a preview appears before you continue</span>'
        '<input class="sr-only" id="owner-logo" name="logo" type="file" accept="image/png,image/jpeg,image/webp"><label class="btn btn-secondary btn-sm" for="owner-logo">Choose file</label></div></div>',
        f'<p class="wp-note is-plain span-12">{icon("info-circle")}<span>This copy tells phpledger.com it was installed: the version, PHP and database engine and the operating system family. '
        'Nothing about your books, your users or your address. <a class="link" href="https://phpledger.com/privacy/" target="_blank" rel="noopener noreferrer">Privacy details</a></span></p>',
        '</form>',
    ])
    actions = ('<span class="fine-print">You will sign in with your username or your email.</span>'
               '<button class="btn btn-primary" id="finish" type="submit" form="account-form">Finish and create your business</button>')
    return setup_page('Create your sign-in account', INSTALL_STEPS, 2,
                      card('Create your sign-in account', 'The account that owns this installation. Your first business comes next.', form, actions, page_help), 'I-4 account')


def i5_complete():
    manifest = ''.join(f'<li class="manifest-row">{TICK}{t}</li>' for t in [
        '61 build steps, each checked on this server', '155 tables and views built', '190 protective database rules active',
        'Private settings saved outside public/', 'Signed in as rana'])
    signin = (
        '<div class="signin-card"><h2>Your sign-in details, to note down</h2><dl class="signin-grid">'
        '<dt>Sign-in address</dt><dd>https://localhost:18443/login</dd>'
        '<dt>Username</dt><dd>rana</dd>'
        '<dt>Email</dt><dd>rana@example.com</dd>'
        '<dt>Password</dt><dd><span id="done-password">•••••••••••••••</span><button type="button" class="btn btn-secondary btn-sm">Show</button></dd></dl>'
        f'<div class="signin-actions"><button type="button" class="btn btn-secondary btn-sm">{icon("copy")} Copy all details</button>'
        f'<button type="button" class="btn btn-secondary btn-sm">{icon("printer")} Print this page</button></div>'
        '<p class="keep-safe">Also keep safe: <span class="snip">storage/installation/operator.key</span>, the maintenance key for updates and recovery. '
        'It is not your password and it lives outside public/. Back it up with your database.</p></div>')
    body = (f'<div class="done-split"><ul class="manifest-card">{manifest}</ul>{signin}</div>')
    actions = '<span class="fine-print">Browser setup is now closed. Everything else happens inside PHP Ledger.</span><a class="btn btn-primary" href="/onboarding">Set up your first business</a>'
    inner = ('<section class="wp-card"><div class="bench-head wp-head"><div class="wp-title-row"><h1 class="bench-heading">Your installation is complete.</h1></div>'
             '<p class="bench-sub">Sign in with the details on the right. Next, set up your first business.</p></div>'
             f'<div class="bench-body">{body}</div><div class="bench-actions">{actions}</div></section>')
    return setup_page('Installation complete', INSTALL_STEPS, 4, inner, 'I-5 complete', step_text='Done')


FRAMES = [
    ('i1-database.html', 'I-1 Connect your database', i1_database),
    ('i2-build.html', 'I-2 Preparing your database', i2_build),
    ('i3-settings-manual.html', 'I-3 Place the private settings file', i3_settings_manual),
    ('i4-account.html', 'I-4 Create your sign-in account', i4_account),
    ('i5-complete.html', 'I-5 Installation complete', i5_complete),
]
