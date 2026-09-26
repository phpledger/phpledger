'use strict';
// Mockup behaviour only: what install.js / app.js would gain. No inline handlers (CSP).
document.documentElement.classList.add('js');
const params = new URLSearchParams(location.search);

// Eye toggle on a password field.
document.querySelectorAll('[data-toggle-password]').forEach(button => {
    button.addEventListener('click', () => {
        const input = document.querySelector(button.getAttribute('data-toggle-password'));
        if (!input) return;
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        button.querySelectorAll('.icon').forEach(icon => icon.toggleAttribute('hidden'));
    });
});

// Generate a password into both fields, shown so it can be copied.
document.querySelectorAll('[data-generate-password]').forEach(button => {
    button.addEventListener('click', () => {
        const alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789-.';
        const bytes = new Uint8Array(14);
        crypto.getRandomValues(bytes);
        const value = Array.from(bytes, b => alphabet[b % alphabet.length]).join('');
        button.getAttribute('data-generate-password').split(',').forEach(selector => {
            const input = document.querySelector(selector.trim());
            if (input) { input.value = value; input.type = 'text'; input.dispatchEvent(new Event('input', { bubbles: true })); }
        });
    });
});

// Strength line: length only, no dictionary. The rule is six characters; the advice is longer.
document.querySelectorAll('[data-strength-for]').forEach(meter => {
    const input = document.querySelector(meter.getAttribute('data-strength-for'));
    const label = meter.querySelector('[data-strength-label]');
    const update = () => {
        const n = input.value.length;
        meter.classList.toggle('is-strong', n >= 12);
        meter.classList.toggle('is-fair', n >= 8 && n < 12);
        if (label) label.textContent = n === 0 ? 'At least 6 characters' : n < 6 ? (6 - n) + ' more to go' : n < 8 ? 'Allowed, but short' : n < 12 ? 'Fair' : 'Strong';
    };
    input.addEventListener('input', update); update();
});

// The primary button turns green when every required field is valid.
document.querySelectorAll('form[data-ready-button]').forEach(form => {
    const button = document.querySelector(form.getAttribute('data-ready-button'));
    const update = () => button && button.classList.toggle('is-ready', form.checkValidity());
    form.addEventListener('input', update); form.addEventListener('change', update); update();
});

// Segmented tabs (Packages); ?tab=directory opens one for a screenshot.
document.querySelectorAll('[data-tabs]').forEach(tabs => {
    const items = tabs.querySelectorAll('[role="tab"]');
    const apply = key => {
        items.forEach(item => item.setAttribute('aria-selected', item.getAttribute('data-tab') === key ? 'true' : 'false'));
        document.querySelectorAll('[data-tab-panel]').forEach(panel => panel.classList.toggle('is-active', panel.getAttribute('data-tab-panel') === key));
    };
    items.forEach(item => item.addEventListener('click', () => apply(item.getAttribute('data-tab'))));
    apply(params.get('tab') || tabs.getAttribute('data-tabs'));
});

// Mockup-only environment picker on the "place the file by hand" step; ?env=xampp for screenshots.
document.querySelectorAll('[data-env-pick]').forEach(pick => {
    const items = pick.querySelectorAll('[role="tab"]');
    const apply = key => {
        items.forEach(item => item.setAttribute('aria-selected', item.getAttribute('data-env') === key ? 'true' : 'false'));
        document.querySelectorAll('[data-env-panel]').forEach(panel => panel.classList.toggle('is-active', panel.getAttribute('data-env-panel') === key));
        document.querySelectorAll('[data-env-fact]').forEach(el => el.toggleAttribute('hidden', el.getAttribute('data-env-fact') !== key));
    };
    items.forEach(item => item.addEventListener('click', () => apply(item.getAttribute('data-env'))));
    apply(params.get('env') || pick.getAttribute('data-env-pick'));
});

// Business details: the legal-form list, its guidance and the invoice-number labels follow the country.
// In the product the same data file renders server-side; this script only saves the round trip.
document.querySelectorAll('form[data-legal-forms]').forEach(form => {
    const data = JSON.parse(document.getElementById('legal-forms-data').textContent);
    const country = form.querySelector('#country_code');
    const legal = form.querySelector('#legal_form');
    const guide = form.querySelector('[data-form-guide]');
    const registrarChip = form.querySelector('[data-country-registrar]');
    const authority = form.querySelector('[data-country-authority]');
    const showGuide = () => {
        const c = data[country.value] || data.ZZ;
        const entry = c.forms.find(f => f.key === legal.value) || c.forms[0];
        if (guide) guide.textContent = entry.guide;
    };
    const applyCountry = () => {
        const c = data[country.value] || data.ZZ;
        // Keep the same family when the country changes, so "Ltd" becomes "Pvt Ltd" rather than resetting.
        // A form the person chose keeps its family across countries ("Ltd" becomes "Pvt Ltd"); an untouched
        // picker takes the country's usual form instead (an LLC in the UAE, an OU in Estonia).
        const previous = (data[country.dataset.previous] || data.ZZ).forms.find(f => f.key === legal.value);
        const family = previous ? previous.family : null;
        legal.replaceChildren(...c.forms.map(f => { const o = document.createElement('option'); o.value = f.key; o.textContent = f.name; return o; }));
        const match = (legal.dataset.touched && family && c.forms.find(f => f.family === family)) || c.forms.find(f => f.key === c.default) || c.forms[0];
        legal.value = match.key;
        // Currency and year end are suggestions: they follow the country until the person edits them.
        const currency = form.querySelector('#currency');
        if (currency && !currency.dataset.touched && [...currency.options].some(o => o.value === c.currency)) currency.value = c.currency;
        const fye = form.querySelector('#fiscal_year_end');
        if (fye && !fye.dataset.touched && [...fye.options].some(o => o.value === c.fiscal_year_end)) fye.value = c.fiscal_year_end;
        if (registrarChip) registrarChip.textContent = 'Names as used in ' + c.name;
        if (authority) authority.value = c.registrar.replace(/^the /, '');
        Object.entries(c.labels).forEach(([key, text]) => {
            form.querySelectorAll(`[data-country-label="${key}"]`).forEach(el => { el.textContent = text; });
        });
        form.querySelectorAll('[data-country-field]').forEach(el => el.toggleAttribute('hidden', !c.labels[el.getAttribute('data-country-field')]));
        form.querySelectorAll('[data-country-placeholder]').forEach(el => {
            const key = el.getAttribute('data-country-placeholder');
            if (country.dataset.previous && country.dataset.previous !== country.value) el.value = '';
            el.placeholder = c.placeholders[key] || '';
        });
        country.dataset.previous = country.value;
        showGuide();
    };
    country.dataset.previous = country.value;
    country.addEventListener('change', applyCountry);
    legal.addEventListener('change', () => { legal.dataset.touched = '1'; showGuide(); });
    ['#currency', '#fiscal_year_end'].forEach(sel => { const el = form.querySelector(sel); if (el) el.addEventListener('change', () => { el.dataset.touched = '1'; }); });
    const wanted = params.get('country');
    if (wanted && data[wanted]) { country.value = wanted; applyCountry(); } else { showGuide(); }
});

// Help bubbles: one open at a time; Escape and click-outside close (same as help.js).
document.querySelectorAll('details[data-help]').forEach(help => {
    help.addEventListener('toggle', () => { if (help.open) document.querySelectorAll('details[data-help][open]').forEach(o => { if (o !== help) o.open = false; }); });
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') document.querySelectorAll('details[data-help][open]').forEach(h => { h.open = false; }); });
document.addEventListener('click', e => { document.querySelectorAll('details[data-help][open]').forEach(h => { if (!h.contains(e.target)) h.open = false; }); });

// Open a named help bubble from the URL so a screenshot can show it: ?help=db-host
const wantedHelp = params.get('help');
if (wantedHelp) { const h = document.getElementById('help-' + wantedHelp); if (h) h.open = true; }
