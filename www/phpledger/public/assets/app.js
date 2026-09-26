'use strict';

document.querySelectorAll('[data-copy-from]').forEach(button => {
    const source = document.getElementById(button.dataset.copyFrom);
    if (!source || !navigator.clipboard?.writeText) return;
    button.hidden = false;
    button.addEventListener('click', async () => {
        const status = button.parentElement.querySelector('[data-copy-status]');
        try {
            await navigator.clipboard.writeText('value' in source ? source.value : source.textContent.trim());
            if (status) status.textContent = 'Copied.';
        } catch {
            if (status) status.textContent = 'Select the text and copy it manually.';
        }
    });
});

document.querySelectorAll('[data-report-period]').forEach(form => {
    const preset = form.elements.namedItem('preset');
    const from = form.elements.namedItem('from');
    const to = form.elements.namedItem('to');
    preset.addEventListener('change', () => {
        const option = preset.selectedOptions[0];
        if (option.dataset.from) { from.value = option.dataset.from; to.value = option.dataset.to; }
    });
    [from, to].forEach(input => input.addEventListener('input', () => { preset.value = 'custom'; }));
});

document.querySelectorAll('.core-account-form').forEach(form => {
    const type = form.elements.namedItem('type');
    const hint = form.querySelector('[data-account-consequence]');
    if (!type || !hint) return;
    type.addEventListener('change', () => {
        hint.textContent = ['income', 'expense'].includes(type.value)
            ? 'This account appears on Profit & loss.' : 'This account appears on the Balance sheet.';
    });
});

// Native details preserves an explicit review step without JavaScript.
document.querySelectorAll('[data-confirmation], [data-editor-sheet]').forEach((details, index) => {
    if (typeof HTMLDialogElement === 'undefined') return;
    const summary = details.querySelector('summary');
    const body = details.querySelector('[data-confirmation-body]');
    const dialog = document.createElement('dialog');
    const sheet = details.hasAttribute('data-editor-sheet');
    dialog.className = sheet ? 'dialog sheet' : 'dialog';
    const title = body.querySelector('h2');
    title.id = 'confirmation-title-' + index;
    dialog.setAttribute('aria-labelledby', title.id);
    body.classList.add('dialog-body');
    body.replaceWith(dialog);
    dialog.append(body);
    const cancel = body.querySelector('[data-confirmation-cancel]');
    cancel.hidden = false;
    cancel.addEventListener('click', () => dialog.close());
    summary.addEventListener('click', event => {
        event.preventDefault();
        details.open = true;
        dialog.showModal();
        (sheet ? body.querySelector('input:not([type="hidden"]), select, button') : cancel)?.focus();
    });
    dialog.addEventListener('close', () => { details.open = false; summary.focus(); });
    if (sheet && details.open) dialog.showModal();
});

// Local section links remain ordinary anchors when JavaScript is unavailable.
document.querySelectorAll('[data-ui-tabs]').forEach(nav => {
    const tabs = [...nav.querySelectorAll('a[href^="#"]')];
    const panels = tabs.map(tab => document.getElementById(tab.hash.slice(1)));
    if (!tabs.length || panels.some(panel => !panel?.hasAttribute('data-ui-tab-panel'))) return;
    nav.setAttribute('role', 'tablist');
    const activate = (index, focus = false) => {
        tabs.forEach((tab, i) => {
            tab.setAttribute('aria-selected', String(i === index));
            tab.removeAttribute('aria-current');
            tab.tabIndex = i === index ? 0 : -1;
            panels[i].hidden = i !== index;
        });
        if (focus) tabs[index].focus();
    };
    tabs.forEach((tab, index) => {
        tab.id = panels[index].id + '-tab';
        tab.setAttribute('role', 'tab');
        tab.setAttribute('aria-controls', panels[index].id);
        panels[index].setAttribute('role', 'tabpanel');
        panels[index].setAttribute('aria-labelledby', tab.id);
        panels[index].tabIndex = 0;
        tab.addEventListener('click', event => { event.preventDefault(); activate(index); });
        tab.addEventListener('keydown', event => {
            let next;
            const forward = getComputedStyle(nav).direction === 'rtl' ? -1 : 1;
            if (event.key === 'ArrowRight') next = (index + tabs.length + forward) % tabs.length;
            if (event.key === 'ArrowLeft') next = (index + tabs.length - forward) % tabs.length;
            if (event.key === 'Home') next = 0;
            if (event.key === 'End') next = tabs.length - 1;
            if (next !== undefined) { event.preventDefault(); activate(next, true); }
        });
    });
    const initial = tabs.findIndex(tab => tab.hash === location.hash);
    activate(initial < 0 ? 0 : initial);
});

// Shared workspace controls enhance ordinary links and native details menus.
(() => {
    const shell = document.querySelector('[data-shell]');
    if (!shell) return;
    document.documentElement.dataset.uiReady = 'true';
    const sidebar = shell.querySelector('[data-sidebar]');
    const toggle = shell.querySelector('[data-sidebar-toggle]');
    const drawerMedia = matchMedia('(max-width: 899px)');
    const railMedia = matchMedia('(max-width: 1180px)');
    let returnFocus = null;
    const sync = () => {
        const open = shell.dataset.drawerOpen === 'true';
        sidebar.inert = drawerMedia.matches && !open;
        toggle.setAttribute('aria-expanded', String(drawerMedia.matches ? open : shell.dataset.collapsed === 'false' || (!railMedia.matches && shell.dataset.collapsed !== 'true')));
    };
    const closeDrawer = () => {
        shell.dataset.drawerOpen = 'false';
        sync();
        returnFocus?.focus();
        returnFocus = null;
    };
    toggle.hidden = false;
    toggle.addEventListener('click', () => {
        if (drawerMedia.matches) {
            if (shell.dataset.drawerOpen === 'true') { closeDrawer(); return; }
            returnFocus = document.activeElement;
            shell.dataset.drawerOpen = 'true';
            sync();
            sidebar.querySelector('a, summary, button')?.focus();
        } else {
            const expanded = toggle.getAttribute('aria-expanded') === 'true';
            shell.dataset.collapsed = String(expanded);
            sync();
        }
    });
    shell.querySelector('[data-drawer-overlay]').addEventListener('click', closeDrawer);
    drawerMedia.addEventListener('change', () => { shell.dataset.drawerOpen = 'false'; sync(); });
    railMedia.addEventListener('change', sync);
    sync();
    const active = sidebar.querySelector('[aria-current="page"]');
    if (active) {
        const group = active.closest('details');
        if (group) group.open = true;
        const nav = shell.querySelector('.shell-nav');
        const bounds = active.getBoundingClientRect();
        const container = nav.getBoundingClientRect();
        if (bounds.bottom > container.bottom) nav.scrollTop += bounds.bottom - container.bottom;
        else if (bounds.top < container.top) nav.scrollTop -= container.top - bounds.top;
    }

    const palette = document.createElement('dialog');
    palette.className = 'dialog command-palette';
    palette.setAttribute('aria-label', 'Search or jump to');
    const header = document.createElement('div');
    header.className = 'dialog-header';
    const input = document.createElement('input');
    input.type = 'search';
    input.className = 'input';
    input.placeholder = 'Find a page…';
    input.setAttribute('aria-label', 'Find a page');
    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'btn btn-ghost';
    close.textContent = 'Close';
    close.addEventListener('click', () => palette.close());
    header.append(input, close);
    const results = document.createElement('nav');
    results.className = 'dialog-body command-palette-list';
    results.setAttribute('aria-label', 'Matching pages');
    const entries = [...shell.querySelectorAll('.shell-nav a, .shell-sidebar-footer a')].map(link => ({text: link.textContent.trim(), href: link.href}));
    const render = () => {
        results.replaceChildren();
        const query = input.value.trim().toLowerCase();
        entries.filter(entry => entry.text.toLowerCase().includes(query)).forEach(entry => {
            const link = document.createElement('a');
            link.className = 'menu-item';
            link.href = entry.href;
            link.textContent = entry.text;
            results.append(link);
        });
        if (!results.childElementCount) results.textContent = 'No matching pages.';
    };
    input.addEventListener('input', render);
    input.addEventListener('keydown', event => {
        if (event.key === 'ArrowDown') { event.preventDefault(); results.querySelector('a')?.focus(); }
        if (event.key === 'Enter') { event.preventDefault(); results.querySelector('a')?.click(); }
    });
    palette.append(header, results);
    document.body.append(palette);
    const openPalette = () => { input.value = ''; render(); palette.showModal(); input.focus(); };
    shell.querySelectorAll('[data-command-open]').forEach(button => { button.hidden = false; button.addEventListener('click', openPalette); });
    document.addEventListener('keydown', event => {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') { event.preventDefault(); if (!palette.open) openPalette(); }
        if (event.key === 'Escape') {
            if (palette.open) { event.preventDefault(); palette.close(); return; }
            if (shell.dataset.drawerOpen === 'true') closeDrawer();
            shell.querySelectorAll('details.menu[open], details.company-switcher[open]').forEach(menu => { menu.open = false; menu.querySelector('summary').focus(); });
        }
        if (event.key === 'Tab' && drawerMedia.matches && shell.dataset.drawerOpen === 'true' && !palette.open) {
            const controls = [...sidebar.querySelectorAll('a, button, summary')].filter(el => el.getClientRects().length);
            const first = controls[0], last = controls.at(-1);
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
        }
    });
    document.addEventListener('click', event => {
        shell.querySelectorAll('details.menu[open], details.company-switcher[open]').forEach(menu => { if (!menu.contains(event.target)) menu.open = false; });
    });
})();

document.querySelectorAll('[data-demo-expiry]').forEach((element) => {
    const expires = Date.parse(element.dataset.demoExpiry);
    if (!Number.isFinite(expires)) return;
    const update = () => {
        const minutes = Math.max(0, Math.ceil((expires - Date.now()) / 60000));
        element.textContent = minutes > 0 ? `${minutes} minute${minutes === 1 ? '' : 's'} remaining` : 'Sample expired. Start a new sample and reconnect your clients.';
    };
    update();
    const timer = setInterval(() => { update(); if (Date.now() >= expires) clearInterval(timer); }, 15000);
});

// Server-rendered routes and forms remain usable without JavaScript.
// After a rejected submission, move keyboard focus to the preserved error summary.
document.querySelector('[data-form-error]')?.focus();

// Error links work as ordinary anchors without JS; enhancement also opens folded fields.
document.querySelectorAll('[data-field-errors] a').forEach(link => link.addEventListener('click', event => {
    const control = document.getElementById(link.hash.slice(1));
    if (!control) return;
    event.preventDefault();
    let parent = control.parentElement;
    while (parent) { if (parent instanceof HTMLDetailsElement) parent.open = true; parent = parent.parentElement; }
    control.focus();
}));
function clearServerFieldError(control) {
    if (!control.id || !control.hasAttribute('aria-invalid')) return;
    const errorId = control.id + '-error';
    document.getElementById(errorId)?.remove();
    control.removeAttribute('aria-invalid');
    const descriptions = (control.getAttribute('aria-describedby') || '').split(' ').filter(id => id && id !== errorId);
    if (descriptions.length) control.setAttribute('aria-describedby', descriptions.join(' '));
    else control.removeAttribute('aria-describedby');
    control.closest('.field-error')?.classList.remove('field-error');
    document.querySelectorAll('[data-field-errors] a').forEach(link => {
        if (link.hash === '#' + control.id) link.closest('li').remove();
    });
    document.querySelectorAll('[data-field-errors]').forEach(summary => { summary.hidden = !summary.querySelector('li'); });
}
document.addEventListener('input', event => { if (event.target instanceof HTMLElement) clearServerFieldError(event.target); });
document.addEventListener('change', event => { if (event.target instanceof HTMLElement) clearServerFieldError(event.target); });

document.querySelectorAll('[data-dismiss]').forEach(button => {
        button.addEventListener('click', () => button.closest('[data-dismissible], .notice')?.remove());
});

// In-app accounting guidance (B66). The bubble is a native <details>: it already opens, closes and
// reads without any of this. Everything here is comfort on top — Escape, clicking elsewhere, and
// only one bubble open at a time. Nothing moves focus into the bubble, so focus is never trapped
// and the reading order after the question mark is the explanation itself.
const terminalZone = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
document.querySelectorAll('[data-timezone]').forEach(node => { node.textContent = terminalZone; });
document.querySelectorAll('time[data-local-time]').forEach(node => {
    const instant = new Date(node.dateTime);
    if (!Number.isNaN(instant.getTime())) {
        node.title = node.textContent;
        node.textContent = new Intl.DateTimeFormat('en', {
            dateStyle: 'medium', timeStyle: 'short', timeZone: terminalZone
        }).format(instant) + ' · ' + terminalZone;
    }
});

// Only new, untouched date defaults use the terminal day. Stored accounting dates never shift.
const today = new Date();
const localDay = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
document.querySelectorAll('[data-local-today]').forEach(input => { input.value = localDay; });

// Keep the common year-end choices compact while retaining a no-JavaScript fallback.
// The server remains authoritative for the selected period and MM-DD validation.
document.querySelectorAll('[data-fiscal-year-end-choice]').forEach(select => {
    const targetSelector = select.dataset.fiscalCustomTarget;
    const customInput = targetSelector ? document.querySelector(targetSelector) : null;
    const customGroup = customInput?.closest('[data-fiscal-custom-group]');
    if (!customInput || !customGroup) return;
    const sync = () => {
        const isCustom = select.value === 'custom';
        customGroup.hidden = !isCustom;
        customInput.disabled = !isCustom;
        customInput.setAttribute('aria-hidden', isCustom ? 'false' : 'true');
    };
    select.addEventListener('change', sync);
    sync();
});

document.querySelectorAll('[data-record-link]').forEach(link => {
    if (window.matchMedia('(min-width: 900px)').matches) {
        const url = new URL(link.href);
        url.pathname = url.pathname.replace(/\/transactions\/detail$/, '/transactions');
        link.href = url.toString();
    }
});
document.querySelectorAll('[data-open-details]').forEach(link => {
    link.addEventListener('click', () => {
        const details = document.getElementById(link.hash.slice(1));
        if (details instanceof HTMLDetailsElement) details.open = true;
    });
});

const documentForm = document.querySelector('[data-document-form]');
if (documentForm) {
    let changed = false;
    documentForm.addEventListener('input', () => { changed = true; });
    documentForm.addEventListener('input', () => {
        const preview = documentForm.querySelector('[data-server-preview]');
        if (preview && !preview.hidden) {
            preview.hidden = true;
            const stale = document.createElement('p');
            stale.className = 'text-xs text-warning mt-2';
            stale.setAttribute('role', 'status');
            stale.textContent = 'Fields changed. Update the posting preview to see the current journal lines.';
            preview.after(stale);
        }
    });
    documentForm.addEventListener('submit', () => { changed = false; });
    window.addEventListener('beforeunload', event => {
        if (changed) { event.preventDefault(); event.returnValue = ''; }
    });
}

// The plotted points are presentation only. Exact scenario amounts are calculated on the server.
document.querySelectorAll('[data-forecast-chart]').forEach(canvas => {
    const values = JSON.parse(canvas.dataset.values).map(Number);
    const context = canvas.getContext('2d');
    if (!context || !values.length || values.some(value => !Number.isFinite(value))) return;
    canvas.hidden = false;
    const tokens = getComputedStyle(document.documentElement);
    const color = name => tokens.getPropertyValue(name).trim();
    const width = canvas.width;
    const height = canvas.height;
    const pad = {left:70, right:22, top:30, bottom:38};
    const low = Math.min(0, ...values);
    const high = Math.max(1, ...values);
    const range = high - low || 1;
    const x = index => pad.left + index * (width-pad.left-pad.right) / Math.max(1, values.length-1);
    const y = value => height-pad.bottom - (value-low) / range * (height-pad.top-pad.bottom);
    context.font = '12px "Inter Variable", sans-serif';
    context.textBaseline = 'middle';
    for (let i=0; i<=4; i++) {
        const value = low + range * i / 4;
        context.strokeStyle = color('--border');
        context.beginPath(); context.moveTo(pad.left, y(value)); context.lineTo(width-pad.right,y(value)); context.stroke();
        context.fillStyle = color('--ink-muted'); context.textAlign='right';
        context.fillText(new Intl.NumberFormat('en',{notation:'compact',maximumFractionDigits:1}).format(value),pad.left-12,y(value));
    }
    context.beginPath();
    context.moveTo(x(0),y(values[0])); values.forEach((value,index)=>context.lineTo(x(index),y(value)));
    context.lineTo(x(values.length-1),height-pad.bottom);context.lineTo(x(0),height-pad.bottom);context.closePath();
    context.fillStyle=color('--brand-selected');context.fill();
    context.beginPath(); values.forEach((value,index)=>index?context.lineTo(x(index),y(value)):context.moveTo(x(index),y(value)));
    context.strokeStyle=color('--brand-blue');context.lineWidth=3;context.lineJoin='round';context.stroke();
    context.fillStyle=color('--ink-muted');context.textAlign='center';
    [0,Math.floor((values.length-1)/2),values.length-1].forEach(index=>context.fillText(index===0?'Today':`Week ${index}`,x(index),height-13));
});

// Journal editor: exact decimal display totals and line controls.
'use strict';

(() => {
    const form = document.querySelector('[data-core-journal]');
    if (!form) return;
    const body = form.querySelector('[data-journal-rows]');
    const add = form.querySelector('[data-add-journal-row]');
    const message = form.querySelector('[data-journal-message]');
    const totals = form.querySelector('[data-journal-totals]');
    const rows = () => Array.from(body.querySelectorAll('[data-journal-row]'));
    const template = rows()[0]?.cloneNode(true);
    if (!template) return;
    let dirty = false;

    const amount = (text) => {
        const value = text.trim();
        if (value === '') return 0n;
        if (!/^[0-9]+(?:\.[0-9]{1,4})?$/.test(value)) return null;
        const [whole, fraction = ''] = value.split('.');
        return BigInt(whole) * 10000n + BigInt(fraction.padEnd(4, '0'));
    };
    const format = (value) => {
        const sign = value < 0n ? '-' : '';
        const absolute = value < 0n ? -value : value;
        const whole = (absolute / 10000n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        const fraction = (absolute % 10000n).toString().padStart(4, '0');
        return sign + whole + '.' + (fraction.endsWith('00') ? fraction.slice(0, 2) : fraction);
    };
    const refreshTotals = () => {
        let debit = 0n;
        let credit = 0n;
        let active = 0;
        let invalid = false;
        for (const row of rows()) {
            const fields = Array.from(row.querySelectorAll('input, select'));
            if (fields.every((field) => field.value.trim() === '')) continue;
            active++;
            const dr = amount(row.querySelector('[data-journal-amount="debit"]').value);
            const cr = amount(row.querySelector('[data-journal-amount="credit"]').value);
            if (dr === null || cr === null) {
                invalid = true;
                continue;
            }
            debit += dr;
            credit += cr;
            if ((dr > 0n) === (cr > 0n) || !row.querySelector('[data-journal-account]').value) invalid = true;
        }
        form.querySelector('[data-journal-total="debit"]').textContent = format(debit);
        form.querySelector('[data-journal-total="credit"]').textContent = format(credit);
        const balance = form.querySelector('[data-journal-balance]');
        balance.classList.toggle('core-unbalanced', invalid || debit !== credit);
        balance.textContent = invalid
            ? 'Check each account and enter a debit or credit on every used line.'
            : active === 0
                ? 'Enter journal lines to preview the balance.'
                : debit === credit
                    ? 'Balance preview: Balanced. Save to review the entry.'
                    : 'Difference (debit minus credit): ' + format(debit - credit);
    };
    const numberRows = () => {
        rows().forEach((row, index) => {
            row.querySelector('[data-line-number]').textContent = String(index + 1);
            row.querySelectorAll('[name]').forEach((field) => {
                field.name = field.name.replace(/^lines\[[^\]]*\]/, 'lines[' + index + ']');
                const name = /\[([a-z_]+)\]$/.exec(field.name)?.[1];
                if (name) field.id = 'journal-line-' + index + '-' + name;
            });
            row.querySelectorAll('[data-line-label]').forEach((label) => {
                label.textContent = label.dataset.lineLabel + ', line ' + (index + 1);
                label.parentElement.querySelector('input, select')?.setAttribute('aria-label', label.textContent);
            });
            const remove = row.querySelector('[data-remove-journal-row]');
            remove.hidden = false;
            remove.value = String(index);
            remove.setAttribute('aria-label', 'Remove line ' + (index + 1));
        });
        add.disabled = rows().length >= 100;
    };
    const clearRow = (row) => {
        row.querySelectorAll('.field-error-text').forEach(error => error.remove());
        row.querySelectorAll('[aria-invalid]').forEach(field => { field.removeAttribute('aria-invalid'); field.removeAttribute('aria-describedby'); });
        row.querySelectorAll('input').forEach((field) => {
            field.value = '';
            field.removeAttribute('value');
        });
        row.querySelectorAll('select').forEach((select) => {
            select.querySelectorAll('option[data-unavailable-account]').forEach((option) => option.remove());
            select.querySelectorAll('option').forEach((option) => option.removeAttribute('selected'));
            select.value = '';
        });
    };
    add.hidden = false;
    totals.hidden = false;
    add.addEventListener('click', (event) => {
        event.preventDefault();
        if (rows().length >= 100) return;
        body.querySelectorAll('[aria-invalid]').forEach(clearServerFieldError);
        dirty = true;
        const row = template.cloneNode(true);
        clearRow(row);
        body.append(row);
        numberRows();
        refreshTotals();
        row.querySelector('[data-journal-account]').focus();
        message.textContent = 'Line ' + rows().length + ' added.';
    });
    body.addEventListener('click', (event) => {
        const remove = event.target.closest('[data-remove-journal-row]');
        if (!remove) return;
        event.preventDefault();
        dirty = true;
        body.querySelectorAll('[aria-invalid]').forEach(clearServerFieldError);
        const row = remove.closest('[data-journal-row]');
        const currentRows = rows();
        const index = currentRows.indexOf(row);
        if (currentRows.length === 1) {
            clearRow(row);
            row.querySelector('[data-journal-account]').focus();
        } else {
            row.remove();
            const next = rows()[Math.min(index, rows().length - 1)];
            next.querySelector('[data-journal-account]').focus();
        }
        numberRows();
        refreshTotals();
        message.textContent = 'Line ' + (index + 1) + ' removed from this draft.';
    });
    form.addEventListener('input', () => { dirty = true; refreshTotals(); });
    form.addEventListener('submit', () => { dirty = false; });
    window.addEventListener('beforeunload', event => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
    form.addEventListener('change', refreshTotals);
    numberRows();
    refreshTotals();
})();

// Point of sale: existing isolated cart and receipt behavior.
'use strict';
(() => {
    const root = document.querySelector('[data-pos-root]');
    if (!root) return;
    root.querySelector('[data-pos-print]')?.addEventListener('click', () => window.print());
    const money = text => /^(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,4})?$/.test(text)
        ? BigInt(text.split('.')[0]) * 10000n + BigInt((text.split('.')[1] || '').padEnd(4, '0')) : null;
    const formatted = value => {
        const whole = (value / 10000n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        const fraction = (value % 10000n).toString().padStart(4, '0');
        return whole + '.' + (fraction.endsWith('00') ? fraction.slice(0, 2) : fraction);
    };
    // Inputs edit values; only a deliberately activated submit button continues.
    const protectInputs = form => form.addEventListener('keydown', event => {
        if (event.key === 'Enter' && event.target instanceof HTMLInputElement) event.preventDefault();
    });
    const payment = root.querySelector('[data-pos-payment]');
    if (payment) {
        const cash = payment.querySelector('[data-pos-cash]');
        const checkout = payment.querySelector('[data-pos-checkout]');
        const edit = payment.querySelector('[data-pos-edit]');
        const unavailable = checkout.disabled;
        const total = money(payment.dataset.total);
        let submitting = false;
        const update = () => {
            const received = money(cash.value);
            // Cash exists in whole minor units; this scale is 1e4, so one minor unit is 100n.
            const tenderable = received !== null && received % 100n === 0n;
            const enough = tenderable && received >= total;
            payment.querySelector('[data-pos-change]').textContent = received === null ? 'Enter cash received to see change.' : !tenderable ? 'Cash is counted in notes and coins — use at most two decimal places.' : enough ? 'Change: ' + root.dataset.currency + ' ' + formatted(received - total) : 'Amount still due: ' + root.dataset.currency + ' ' + formatted(total - received);
            checkout.disabled = unavailable || !enough;
        };
        cash.addEventListener('input', update);
        payment.querySelector('[data-pos-exact]').addEventListener('click', () => {
            cash.value = payment.dataset.total; update(); cash.focus();
        });
        protectInputs(payment);
        payment.addEventListener('submit', event => {
            if (submitting || (event.submitter !== checkout && event.submitter !== edit)) { event.preventDefault(); return; }
            if (event.submitter === edit) return;
            update();
            if (checkout.disabled) { event.preventDefault(); return; }
            submitting = true;
            checkout.setAttribute('aria-disabled', 'true');
            checkout.textContent = 'Recording sale…';
            edit.disabled = true;
        });
        window.addEventListener('pageshow', () => { submitting = false; edit.disabled = false; checkout.removeAttribute('aria-disabled'); checkout.textContent = 'Record cash sale'; update(); });
        update();
    }
    const form = root.querySelector('[data-pos-form]');
    if (!form) return;
    root.classList.add('pos-enhanced');
    const products = [...form.querySelectorAll('[data-pos-product]')];
    const cart = form.querySelector('[data-pos-cart]');
    const review = form.querySelector('[data-pos-review]');
    const unavailable = review.disabled;
    const feedback = form.querySelector('[data-pos-feedback]');
    let valid = false;
    const button = (label, text, action) => {
        const node = document.createElement('button'); node.type = 'button'; node.textContent = text;
        node.setAttribute('aria-label', label); node.addEventListener('click', action); node.disabled = unavailable; return node;
    };
    const focusCart = (sku, action) => cart.querySelector('[data-cart-sku="' + sku + '"] [data-cart-action="' + action + '"]')?.focus();
    const change = (product, delta, fromCart = false) => {
        const input = product.querySelector('[data-pos-quantity]');
        const current = /^(?:0|[1-9][0-9]?)$/.test(input.value) ? Number(input.value) : 0;
        if (current + delta > 99) { feedback.textContent = 'Maximum 99 of ' + product.dataset.name + ' per sale.'; return; }
        input.value = String(Math.max(0, current + delta));
        update();
        feedback.textContent = product.dataset.name + ': ' + input.value + ' in cart.';
        if (fromCart) {
            if (Number(input.value) > 0) focusCart(product.dataset.sku, delta > 0 ? 'increase' : 'decrease');
            else product.querySelector('[data-pos-add]').focus();
        }
    };
    const update = (renderCart = true) => {
        if (renderCart) cart.replaceChildren();
        let total = 0n, units = 0, quantitiesValid = true;
        products.forEach(product => {
            const input = product.querySelector('[data-pos-quantity]');
            const acceptable = /^(?:0|[1-9][0-9]?)$/.test(input.value);
            const quantity = acceptable ? Number(input.value) : 0;
            if (!acceptable) quantitiesValid = false;
            product.classList.toggle('is-selected', quantity > 0);
            product.querySelector('[data-pos-selected]').textContent = quantity > 0 ? String(quantity) : '+';
            units += quantity;
            const lineTotal = money(product.dataset.price) * BigInt(quantity); total += lineTotal;
            if (!renderCart) {
                const amount = cart.querySelector('[data-cart-sku="' + product.dataset.sku + '"] > .amount');
                if (amount) amount.textContent = formatted(lineTotal);
                return;
            }
            if (quantity === 0 && acceptable) return;
            const row = document.createElement('div'); row.className = 'pos-cart-row'; row.dataset.cartSku = product.dataset.sku;
            const info = document.createElement('div');
            const name = document.createElement('strong'); name.textContent = product.dataset.name;
            const each = document.createElement('small'); each.textContent = formatted(money(product.dataset.price)) + ' each'; info.append(name, each);
            const amount = document.createElement('strong'); amount.className = 'amount'; amount.textContent = formatted(lineTotal);
            const controls = document.createElement('div'); controls.className = 'pos-quantity-controls';
            const minus = button('Decrease ' + product.dataset.name, '−', () => change(product, -1, true)); minus.dataset.cartAction = 'decrease';
            const plus = button('Increase ' + product.dataset.name, '+', () => change(product, 1, true)); plus.dataset.cartAction = 'increase';
            const editor = document.createElement('input'); editor.type = 'text'; editor.inputMode = 'numeric'; editor.maxLength = 2; editor.value = input.value; editor.disabled = unavailable;
            editor.setAttribute('aria-label', product.dataset.name + ' quantity'); editor.dataset.cartAction = 'quantity';
            // Update totals without replacing a control the user is typing in or about to click.
            editor.addEventListener('input', () => { input.value = editor.value; update(false); });
            editor.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); update(false); } });
            controls.append(minus, editor, plus);
            const remove = button('Remove ' + product.dataset.name, 'Remove', () => { input.value = '0'; update(); product.querySelector('[data-pos-add]').focus(); }); remove.className = 'pos-cart-remove';
            row.append(info, amount, controls, remove); cart.append(row);
        });
        if (!cart.children.length) { const empty = document.createElement('p'); empty.className = 'muted pos-cart-empty'; empty.textContent = 'Your cart is empty. Tap a product to begin.'; cart.append(empty); }
        form.querySelector('[data-pos-total]').textContent = formatted(total);
        form.querySelector('[data-pos-count]').textContent = units + (units === 1 ? ' item' : ' items');
        root.querySelector('[data-pos-mobile-count]').textContent = units + (units === 1 ? ' item' : ' items') + ' · ' + formatted(total);
        feedback.textContent = !quantitiesValid ? 'Use whole quantities from 0 to 99.' : units > 200 ? 'Use no more than 200 units in one sale.' : 'Prices are verified when you review the sale.';
        valid = quantitiesValid && units > 0 && units <= 200;
        review.disabled = unavailable || !valid;
    };
    products.forEach(product => {
        product.querySelector('[data-pos-quantity]').addEventListener('input', update);
        product.querySelector('[data-pos-add]').addEventListener('click', () => change(product, 1));
    });
    let category = 'all';
    const search = form.querySelector('[data-pos-search]');
    const filter = () => {
        const term = search.value.trim().toLocaleLowerCase(); let count = 0;
        products.forEach(product => {
            const visible = (category === 'all' || product.dataset.category === category) && (product.dataset.name + ' ' + product.dataset.sku).toLocaleLowerCase().includes(term);
            product.hidden = !visible; if (visible) count++;
        });
        form.querySelector('[data-pos-no-results]').hidden = count > 0;
    };
    search.addEventListener('input', filter);
    form.querySelectorAll('[data-pos-category]').forEach(button => button.addEventListener('click', () => {
        category = button.dataset.posCategory;
        form.querySelectorAll('[data-pos-category]').forEach(other => other.setAttribute('aria-pressed', String(other === button))); filter();
    }));
    protectInputs(form);
    form.addEventListener('submit', event => { update(); if (event.submitter !== review || !valid || unavailable) event.preventDefault(); });
    window.addEventListener('pageshow', update);
    update();
})();
document.querySelectorAll('[data-print-document]').forEach(button => {
    button.hidden = false;
    button.addEventListener('click', () => window.print());
});
document.querySelectorAll('[data-commercial-form]').forEach(form => {
    const body = form.querySelector('[data-commercial-rows]');
    if (!body) return;
    const template = body.firstElementChild.cloneNode(true);
    const parse = value => {
        const match = /^(0|[1-9]\d{0,15})(?:\.(\d{1,4}))?$/.exec(value.trim());
        return match ? BigInt(match[1]) * 10000n + BigInt((match[2] || '').padEnd(4, '0')) : null;
    };
    const format = value => `${value / 10000n}.${(value % 10000n).toString().padStart(4, '0')}`;
    const taxContext = form.dataset.taxContext ? JSON.parse(form.dataset.taxContext) : null;
    // Pack id => units per pack, in ledger units (x10000). Frozen at pack creation, so this map
    // can never disagree with the base quantity the server resolves from the same selection.
    const packSizes = Object.fromEntries(Object.entries(form.dataset.packSizes ? JSON.parse(form.dataset.packSizes) : {})
        .map(([id, size]) => [id, BigInt(String(size).replace('.', '').padEnd(String(size).split('.')[0].length + 4, '0'))]));
    const roundedRatio = (numerator, denominator) => (numerator * 2n + denominator) / (denominator * 2n);
    let dirty = false;
    const update = () => {
        let total = 0n, valid = true;
        let netTotal = 0n, taxTotal = 0n, taxValid = true, grossTotal = 0n, discountTotal = 0n;
        const inclusive = form.elements.namedItem('price_mode')?.value === 'inclusive';
        const date = form.elements.namedItem('date')?.value || '';
        const remaining = Object.fromEntries(Object.entries(taxContext?.sources || {}).map(([key, source]) => [key, {...source, net: parse(source.net), tax: parse(source.tax)}]));
        [...body.children].forEach((row, index) => {
            row.querySelectorAll('[data-commercial-field]').forEach(control => {
                const field = control.dataset.commercialField;
                control.name = `lines[${index}][${field}]`;
                control.id = `commercial-${index}-${field}`;
                const label = control.previousElementSibling;
                label.htmlFor = control.id;
                label.textContent = `${label.dataset.commercialLabel}, line ${index + 1}`;
            });
            const remove = row.querySelector('[data-remove-commercial-row]');
            remove.value = index; remove.setAttribute('aria-label', `Remove line ${index + 1}`);
            const packSelect = row.querySelector('[data-commercial-field=pack_id]');
            const packSize = packSelect && packSelect.value ? packSizes[packSelect.value] : null;
            let quantity = parse(row.querySelector('[data-commercial-field=quantity]').value);
            if (packSize) {
                const packs = parse(row.querySelector('[data-commercial-field=pack_quantity]').value || '0');
                const units = parse(row.querySelector('[data-commercial-field=unit_quantity]').value || '0');
                quantity = packs === null || units === null ? null : (packs * packSize) / 10000n + units;
            }
            const free = row.querySelector('[data-commercial-flag]')?.checked === true;
            const price = free ? 0n : parse(row.querySelector('[data-commercial-field=unit_price]').value);
            const empty = [...row.querySelectorAll('[data-commercial-field]')].every(control => control.type === 'checkbox' ? !control.checked : control.value === '');
            let amount = quantity === null || price === null ? null : (quantity * price + 5000n) / 10000n;
            let discount = 0n;
            if (amount !== null && !free) {
                const percent = parse(row.querySelector('[data-commercial-field=discount_percent]')?.value || '0');
                if (percent === null) amount = null;
                else if (percent > 0n) { discount = (amount * percent + 500000n) / 1000000n; if (discount > amount) discount = amount; amount -= discount; }
            }
            if (free) { amount = 0n; }
            if (amount !== null && !free) { grossTotal += amount + discount; discountTotal += discount; }
            const amountCell = row.querySelector('[data-commercial-amount]');
            amountCell.textContent = amount === null ? '—' : format(amount);
            row.classList.toggle('doc-line-free', free);
            if (free || discount > 0n) {
                const note = document.createElement('span');
                note.className = 'row-sub';
                note.textContent = free ? 'Bonus, not charged' : `less ${format(discount)}`;
                amountCell.append(note);
            }
            if (amount !== null) total += amount; else if (!empty) valid = false;
            if (taxContext && amount !== null && !free) {
                const code = row.querySelector('[data-commercial-field=tax_code_id]').value;
                let net = amount, tax = 0n;
                if (taxContext.credit) {
                    const number = row.querySelector('[data-commercial-field=original_line_number]')?.value || '';
                    const source = remaining[number];
                    if (number && source) {
                        const basis = inclusive ? source.net + source.tax : source.net;
                        if (basis <= 0n || amount > basis || (code && code !== String(source.tax_code_id))) taxValid = false;
                        else {
                            tax = amount === basis ? source.tax : roundedRatio(source.tax * amount, basis);
                            net = inclusive ? amount - tax : amount;
                            source.net -= net; source.tax -= tax;
                        }
                    } else if (number || taxContext.requires_line || code) taxValid = false;
                } else if (code) {
                    const rate = taxContext.rates.find(rate => String(rate.tax_code_id) === code && rate.effective_from <= date);
                    if (!rate) taxValid = false;
                    else {
                        const percentage = BigInt(rate.percentage.replace('.', ''));
                        if (inclusive) { net = roundedRatio(amount * 100000000n, 100000000n + percentage); tax = amount - net; }
                        else tax = roundedRatio(amount * percentage, 100000000n);
                    }
                }
                if (net <= 0n) taxValid = false;
                netTotal += net; taxTotal += tax;
            }
        });
        const message = form.querySelector('[data-commercial-total]');
        message.textContent = valid ? `Entered total ${format(total)} · Server validation applies when saving.` : 'Complete each entered quantity and unit price to calculate the total.';
        const grand = form.querySelector('[data-commercial-grand] dd');
        if (grand) grand.textContent = valid ? format(total) : '—';
        const label = form.querySelector('[data-commercial-grand] dt');
        if (label) label.textContent = `Total order value (${form.elements.namedItem('currency').value.toUpperCase()})`;
        const taxTotals = form.querySelector('[data-commercial-tax-totals]');
        if (taxTotals) {
            // Only the three rows this recalculation actually owns are written, by name. The
            // other rows come from the last server preview and are removed on edit, never
            // overwritten with a number this script did not compute.
            const amounts = {net: grossTotal, discount: discountTotal, tax: taxTotal, total: netTotal + taxTotal};
            Object.entries(amounts).forEach(([key, value]) => {
                const cell = taxTotals.querySelector(`[data-total="${key}"] dd`);
                if (!cell) return;
                const negative = key === 'discount';
                cell.textContent = valid && taxValid ? (negative && value > 0n ? `−${format(value)}` : format(value)) : '—';
            });
            const totalLabel = taxTotals.querySelector('[data-total="total"] dt');
            if (totalLabel) totalLabel.textContent = `Total (${form.elements.namedItem('currency').value.toUpperCase()})`;
            if (!taxValid) message.textContent = 'Update the posting preview to resolve the tax date, original line or remaining credit amount.';
            else if (valid) message.textContent = 'Display totals use the selected date and tax mode. The server recomputes them when you preview or save.';
        }
    };
    form.addEventListener('click', event => {
        const add = event.target.closest('[data-add-commercial-row]');
        const remove = event.target.closest('[data-remove-commercial-row]');
        if (!add && !remove) return;
        event.preventDefault();
        if (add && body.children.length >= 100) { form.querySelector('[data-commercial-total]').textContent = 'A document supports up to 100 lines.'; return; }
        body.querySelectorAll('[aria-invalid]').forEach(clearServerFieldError);
        if (remove) remove.closest('[data-commercial-row]').remove();
        if (add || body.children.length === 0) {
            const row = template.cloneNode(true);
            row.querySelectorAll('.field-error-text').forEach(error => error.remove());
            row.querySelectorAll('[aria-invalid]').forEach(control => { control.removeAttribute('aria-invalid'); control.removeAttribute('aria-describedby'); });
            row.querySelectorAll('input,select').forEach(control => { if (control.type === 'checkbox') control.checked = false; else control.value = ''; });
            row.classList.remove('doc-line-free');
            body.append(row); update(); row.querySelector('input,select').focus();
        } else { update(); (body.children[Math.min(Number(remove.value), body.children.length - 1)].querySelector('input,select')).focus(); }
        dirty = true;
    });
    form.addEventListener('input', () => {
        dirty = true;
        // A preview-only total describes the reviewed document, not the one being typed.
        form.querySelectorAll('[data-commercial-tax-totals] [data-total="preview"]').forEach(row => row.remove());
        update();
    });
    form.addEventListener('submit', () => { dirty = false; });
    window.addEventListener('beforeunload', event => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
    update();
});

document.querySelectorAll('[data-reviewed-form]').forEach(form => {
    form.addEventListener('input', event => {
        if (event.target.name === 'confirmed') return;
        form.querySelectorAll('[data-review-preview]').forEach(preview => { preview.hidden = true; });
        [...form.elements].filter(control => control.hasAttribute('data-review-confirm')).forEach(control => { control.disabled = true; });
        const message = form.querySelector('[data-review-message]');
        if (message) message.textContent = 'Values changed. Update the preview before confirming.';
    });
});

// Informational allocation totals; the server validates exact amounts again at posting.
document.querySelectorAll('[data-settlement-form]').forEach(form => {
    const amount = value => {
        const match = /^(\d{1,16})(?:\.(\d{1,4}))?$/.exec(value.trim());
        return match ? BigInt(match[1]) * 10000n + BigInt((match[2] || '').padEnd(4, '0')) : null;
    };
    const format = value => `${value < 0n ? '-' : ''}${(value < 0n ? -value : value) / 10000n}.${((value < 0n ? -value : value) % 10000n).toString().padStart(4, '0')}`;
    const output = form.querySelector('[data-allocation-total]');
    const update = () => {
        const payment = amount(form.elements.namedItem('amount_fc').value);
        const allocations = [...form.querySelectorAll('[data-allocation-amount]')].map(input => input.value.trim() === '' ? 0n : amount(input.value));
        if (payment === null || allocations.some(value => value === null)) {
            output.textContent = 'Enter valid amounts with up to four decimal places.';
            return;
        }
        const total = allocations.reduce((sum, value) => sum + value, 0n);
        const remainder = payment - total;
        // A remainder is not an error since 1.2: it is held as unapplied credit. Only
        // over-allocation is refused, and the server checks both again at posting.
        output.textContent = remainder < 0n
            ? `Allocated ${format(total)} · Over-allocated by ${format(-remainder)} — reduce an amount`
            : `Allocated ${format(total)} · Held on account ${format(remainder)}`;
    };
    form.addEventListener('input', event => {
        update();
        if (['gain_account_id', 'loss_account_id'].includes(event.target.name)) return;
        const preview = form.querySelector('[data-settlement-preview]');
        if (preview) {
            preview.hidden = true;
            const message = document.createElement('p');
            message.textContent = 'Payment changed. Update the preview before confirming.';
            preview.replaceWith(message);
        }
    });
    update();
});

// Business setup (owner review of 25-26 September 2026). Everything below is comfort on top of
// plain forms: without it the gallery is always shown, rows are what the server rendered, a
// change of country is a round trip and the primary button is simply navy.
document.querySelectorAll('[data-start-choice]').forEach(radio => {
    const gallery = document.querySelector('[data-gallery]');
    if (!gallery) return;
    const sync = () => { const chosen = document.querySelector('[data-start-choice]:checked'); gallery.hidden = !(chosen && chosen.getAttribute('data-start-choice') === '1'); };
    radio.addEventListener('change', sync);
    sync();
});
document.querySelectorAll('[data-add-row]').forEach(button => {
    button.addEventListener('click', () => {
        const body = document.querySelector('[data-rows="' + button.getAttribute('data-add-row') + '"]');
        const last = body && body.querySelector('tr:last-child');
        if (!last) return;
        const row = last.cloneNode(true);
        row.querySelectorAll('input').forEach(input => { if (input.type === 'hidden' && input.name !== 'owner_kind[]') { input.value = ''; } else if (input.type !== 'hidden') { input.value = ''; input.removeAttribute('placeholder'); } });
        row.querySelectorAll('select').forEach(select => { select.selectedIndex = 0; });
        body.appendChild(row);
        const first = row.querySelector('input:not([type="hidden"])');
        if (first) first.focus();
    });
});
document.querySelectorAll('form[data-ready-button]').forEach(form => {
    const button = document.querySelector(form.getAttribute('data-ready-button') || '');
    if (!(button instanceof HTMLButtonElement)) return;
    const update = () => button.classList.toggle('is-ready', form.checkValidity());
    form.addEventListener('input', update);
    form.addEventListener('change', update);
    update();
});
// The legal-form list, its guide and the invoice-number labels follow the country. The same
// catalogue renders server-side (resources/locale/legal-forms.json); this only saves the round trip.
document.querySelectorAll('form[data-legal-forms]').forEach(form => {
    const source = document.getElementById('legal-forms-data');
    if (!source) return;
    let data;
    try { data = JSON.parse(source.textContent || '{}'); } catch (error) { return; }
    const country = form.querySelector('#country-code');
    const legal = form.querySelector('#legal-form');
    const guide = form.querySelector('[data-form-guide]');
    const registrarChip = form.querySelector('[data-country-registrar]');
    const authority = form.querySelector('[data-country-authority]');
    if (!country || !legal) return;
    // A country outside the catalogue uses the neutral list, whose keys are the canonical family
    // keys; the country itself is saved on the profile.
    const profileFor = () => data[country.value] || data.ZZ;
    const currentForms = () => profileFor().forms;
    const familyOf = key => { const entry = Object.values(data).flatMap(p => p.forms).find(f => f.key === key); return entry ? entry.family : null; };
    const showGuide = () => {
        const entry = currentForms().find(f => f.key === legal.value);
        if (guide) guide.textContent = entry ? entry.guide : guide.getAttribute('data-empty') || '';
    };
    const applyCountry = () => {
        const c = profileFor();
        const forms = currentForms();
        const previousFamily = familyOf(legal.value);
        const keep = legal.querySelector('option[value=""]');
        legal.replaceChildren(...(keep ? [keep] : []), ...forms.map(f => { const o = document.createElement('option'); o.value = f.key; o.textContent = f.name; return o; }));
        const match = (legal.dataset.touched && previousFamily && forms.find(f => f.family === previousFamily)) || (c.default ? forms.find(f => f.key === c.default) : null);
        legal.value = match ? match.key : '';
        if (registrarChip && c.chip) registrarChip.textContent = c.chip;
        // The registrar is a hint, never a value: only what the owner types is saved and printed.
        if (authority) authority.placeholder = c.registrar.replace(/^the /, '');
        Object.entries(c.labels).forEach(([key, text]) => { form.querySelectorAll('[data-country-label="' + key + '"]').forEach(el => { el.textContent = text; }); });
        form.querySelectorAll('[data-country-field]').forEach(el => el.toggleAttribute('hidden', !c.labels[el.getAttribute('data-country-field')]));
        form.querySelectorAll('[data-country-placeholder]').forEach(el => { el.placeholder = c.placeholders[el.getAttribute('data-country-placeholder')] || ''; });
        const currency = form.querySelector('#base-currency');
        if (currency && !currency.dataset.touched && [...currency.options].some(o => o.value === c.currency)) currency.value = c.currency;
        const fye = form.querySelector('#fiscal-year-end-choice');
        if (fye && !fye.dataset.touched && [...fye.options].some(o => o.value === c.fiscal_year_end)) { fye.value = c.fiscal_year_end; fye.dispatchEvent(new Event('change')); }
        showGuide();
    };
    if (guide) guide.setAttribute('data-empty', guide.textContent || '');
    country.addEventListener('change', applyCountry);
    // Only the owner's own edits count as "touched"; the script's own change events do not.
    legal.addEventListener('change', event => { if (event.isTrusted) legal.dataset.touched = '1'; showGuide(); });
    ['#base-currency', '#fiscal-year-end-choice'].forEach(sel => { const el = form.querySelector(sel); if (el) el.addEventListener('change', event => { if (event.isTrusted) el.dataset.touched = '1'; }); });
});
