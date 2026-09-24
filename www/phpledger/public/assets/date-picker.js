/* Shared progressive date input. Named source values remain ISO, display is DD/MM/YYYY. */
'use strict';
(() => {
    if (typeof HTMLDialogElement === 'undefined' || !HTMLDialogElement.prototype.showModal) return;
    let config = {};
    try { config = JSON.parse(document.getElementById('pl-date-picker-config')?.textContent || '{}'); } catch { return; }
    const labels = Object.assign({
        chooseDate: 'Choose date', close: 'Close', previous: 'Previous', next: 'Next',
        chooseYear: 'Choose year', chooseMonth: 'Choose month', today: 'Today', clear: 'Clear',
        invalidDate: 'Enter a valid date as DD/MM/YYYY.', outOfRange: 'Choose a date within the allowed range.',
        months: ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
        weekdays: ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']
    }, config.labels || {});
    const states = new WeakMap();
    const pad = value => String(value).padStart(2, '0');
    const iso = (year, month, day) => String(year).padStart(4, '0') + '-' + pad(month) + '-' + pad(day);
    const days = (year, month) => [31, (year % 4 === 0 && (year % 100 !== 0 || year % 400 === 0)) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31][month - 1];
    function parse(value, display = false) {
        const parts = value.match(display ? /^(\d{2})\/(\d{2})\/(\d{4})$/ : /^(\d{4})-(\d{2})-(\d{2})$/);
        if (!parts) return null;
        const year = Number(parts[display ? 3 : 1]), month = Number(parts[2]), day = Number(parts[display ? 1 : 3]);
        return year >= 1 && year <= 9999 && month >= 1 && month <= 12 && day >= 1 && day <= days(year, month) ? { year, month, day } : null;
    }
    function format(value) {
        const date = parse(value);
        return date ? pad(date.day) + '/' + pad(date.month) + '/' + String(date.year).padStart(4, '0') : '';
    }
    function today() {
        const date = new Date();
        return iso(date.getFullYear(), date.getMonth() + 1, date.getDate());
    }
    function serial(value) {
        const date = parse(value), utc = new Date(0);
        utc.setUTCFullYear(date.year, date.month - 1, date.day);
        return Math.floor(utc.getTime() / 86400000);
    }
    function allowed(source, value) {
        if (!parse(value)) return false;
        if (parse(source.min) && value < source.min || parse(source.max) && value > source.max) return false;
        const step = Number(source.step || 1);
        const originalDefault = source.dataset.dateDefaultValue ?? source.defaultValue;
        const base = parse(source.min) ? source.min : parse(originalDefault) ? originalDefault : '1970-01-01';
        return source.step === 'any' || !(step > 0) || Math.abs((serial(value) - serial(base)) / step - Math.round((serial(value) - serial(base)) / step)) < 1e-8;
    }
    function element(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }
    function button(text, action, className = '') {
        const node = element('button', className, text);
        node.type = 'button';
        node.addEventListener('click', action);
        return node;
    }
    let nextId = 0;
    function enhance(source) {
        if (states.has(source) || source.disabled || source.readOnly || source.closest('[data-date-picker="off"]')) return;
        const nativeValue = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
        const wrapper = element('span', 'pl-date-field');
        const display = element('input', source.className + ' pl-date-display');
        display.type = 'text';
        display.inputMode = 'numeric';
        display.placeholder = 'DD/MM/YYYY';
        display.autocomplete = 'off';
        display.dir = 'ltr';
        display.id = 'pl-date-display-' + (++nextId);
        display.setAttribute('aria-haspopup', 'dialog');
        for (const attribute of ['aria-label', 'aria-labelledby', 'aria-describedby', 'aria-invalid', 'form', 'title']) {
            if (source.hasAttribute(attribute)) display.setAttribute(attribute, source.getAttribute(attribute));
        }
        // A companion value lets the server re-render rejected/partial text without guessing a date.
        if (source.name) display.name = '_date_display[' + encodeURIComponent(source.name) + ']';
        const sourceLabels = Array.from(source.labels || []);
        sourceLabels.forEach(label => { if (label.htmlFor === source.id && source.id) label.htmlFor = display.id; });
        const opener = button('▦', () => open(), 'pl-date-open');
        if (config.iconUrl) {
            const icon = element('img'); icon.src = config.iconUrl; icon.alt = ''; icon.width = 18; icon.height = 18;
            opener.replaceChildren(icon);
        }
        opener.setAttribute('aria-label', labels.chooseDate);
        opener.setAttribute('aria-haspopup', 'dialog');
        const dialog = element('dialog', 'pl-date-dialog');
        dialog.id = 'pl-date-dialog-' + nextId;
        opener.setAttribute('aria-controls', dialog.id);
        display.setAttribute('aria-controls', dialog.id);
        const header = element('div', 'pl-date-header');
        const title = element('h2', '', labels.chooseDate);
        title.id = dialog.id + '-title';
        dialog.setAttribute('aria-labelledby', title.id);
        header.append(title, button('×', () => dialog.close(), 'pl-date-close'));
        header.lastChild.setAttribute('aria-label', labels.close);
        const nav = element('div', 'pl-date-nav');
        const body = element('div', 'pl-date-grid');
        const status = element('p', 'pl-date-status');
        status.setAttribute('aria-live', 'polite');
        const footer = element('div', 'pl-date-footer');
        const todayButton = button(labels.today, () => select(today()));
        const clearButton = button(labels.clear, () => select(''));
        footer.append(todayButton, clearButton);
        dialog.append(header, nav, status, body, footer);
        source.before(wrapper);
        wrapper.append(source, display, opener, dialog);
        // Keep the exact original DOM element, name, id and all dependency listeners.
        // A hidden type removes its native validation UI; the visible proxy owns validity.
        const initialValue = source.defaultValue;
        source.dataset.dateDefaultValue = initialValue;
        source.type = 'hidden';
        source.dataset.dateEnhanced = 'true';
        let syncing = false, view = 'days', shown = parse(source.value) || parse(today()), returnFocus = opener;
        const setSource = value => { syncing = true; nativeValue.set.call(source, value); syncing = false; };
        function emit(type) {
            syncing = true;
            source.dispatchEvent(new Event(type, { bubbles: true }));
            syncing = false;
        }
        function sync() {
            display.value = format(source.value);
            validate(false);
        }
        Object.defineProperty(source, 'value', {
            configurable: true,
            get() { return nativeValue.get.call(this); },
            set(value) { nativeValue.set.call(this, value); if (!syncing) sync(); }
        });
        // Existing error-summary links and workflow scripts address the original id.
        // Keep their focus calls useful after that element becomes the ISO carrier.
        source.focus = options => display.focus(options);
        function attributes() {
            display.required = source.required;
            display.disabled = source.disabled;
            display.readOnly = source.readOnly;
            opener.disabled = source.disabled || source.readOnly;
            clearButton.hidden = source.required;
            validate(false);
        }
        function validate(emitInput) {
            const raw = display.value.trim(), date = parse(raw, true);
            const value = date ? iso(date.year, date.month, date.day) : '';
            const message = raw && !date ? labels.invalidDate : value && !allowed(source, value) ? labels.outOfRange : '';
            display.setCustomValidity(message);
            display.setAttribute('aria-invalid', message ? 'true' : 'false');
            setSource(message ? '' : value);
            if (emitInput) emit('input');
            return !message && (!source.required || !!value);
        }
        const recovery = source.form?.hasAttribute('data-date-recovery') ? config.recovery || {} : {};
        const recovered = recovery[source.name] ?? recovery[encodeURIComponent(source.name)];
        display.value = source.hasAttribute('data-date-display') ? source.dataset.dateDisplay : typeof recovered === 'string' ? recovered : format(source.value);
        attributes();
        source.addEventListener('input', () => { if (!syncing) sync(); });
        source.addEventListener('change', () => { if (!syncing) sync(); });
        display.addEventListener('input', () => validate(true));
        display.addEventListener('change', () => { validate(false); emit('change'); });
        display.addEventListener('keydown', event => {
            if (event.key === 'ArrowDown' && event.altKey) { event.preventDefault(); open(); }
        });
        const observer = new MutationObserver(attributes);
        observer.observe(source, { attributes: true, attributeFilter: ['min', 'max', 'step', 'required', 'disabled', 'readonly'] });
        source.form?.addEventListener('reset', event => {
            setTimeout(() => { if (!event.defaultPrevented) { setSource(initialValue); sync(); } }, 0);
        });
        function select(value) {
            if (value && !allowed(source, value)) return;
            display.value = format(value);
            validate(false);
            emit('input'); emit('change');
            dialog.close();
        }
        function open() {
            if (source.disabled || source.readOnly) return;
            returnFocus = document.activeElement === display ? display : opener;
            shown = parse(source.value) || parse(today());
            const birth = source.dataset.dateKind === 'birth' || /(^|\[|_)(dob|birth_date|date_of_birth)(\]|$)/i.test(source.name);
            view = !source.value && birth ? 'years' : 'days';
            render();
            dialog.showModal();
            focusGrid();
        }
        dialog.addEventListener('close', () => returnFocus.focus());
        function focusGrid() { (body.querySelector('[tabindex="0"]') || body.querySelector('button:not(:disabled)') || header.lastChild).focus(); }
        function move(amount) {
            if (view === 'years') shown.year = Math.max(1, Math.min(9999, shown.year + amount * 12));
            else if (view === 'months') shown.year = Math.max(1, Math.min(9999, shown.year + amount));
            else {
                let index = shown.year * 12 + shown.month - 1 + amount;
                index = Math.max(12, Math.min(119999, index));
                shown.year = Math.floor(index / 12); shown.month = index % 12 + 1;
            }
            shown.day = Math.min(shown.day, days(shown.year, shown.month));
            render();
        }
        function render() {
            nav.replaceChildren(); body.replaceChildren();
            body.dataset.view = view;
            const previous = button('‹', () => { move(-1); nav.firstElementChild.focus(); });
            previous.setAttribute('aria-label', labels.previous);
            const next = button('›', () => { move(1); nav.lastElementChild.focus(); });
            next.setAttribute('aria-label', labels.next);
            const month = button(labels.months[shown.month - 1], () => { view = 'months'; render(); focusGrid(); });
            month.setAttribute('aria-label', labels.chooseMonth);
            const year = button(String(shown.year), () => { view = 'years'; render(); focusGrid(); });
            year.setAttribute('aria-label', labels.chooseYear);
            nav.append(previous, month, year, next);
            todayButton.disabled = !allowed(source, today());
            const selected = source.value;
            let focusValue;
            if (view === 'days') {
                status.textContent = labels.months[shown.month - 1] + ' ' + shown.year;
                labels.weekdays.forEach((day, index) => {
                    let short = day;
                    try { short = new Intl.DateTimeFormat(document.documentElement.lang || 'en', { weekday: 'short', timeZone: 'UTC' }).format(new Date(Date.UTC(2024, 0, 7 + index))); } catch { /* Keep the translated fallback. */ }
                    const heading = element('span', 'pl-date-weekday', short);
                    heading.title = day;
                    heading.setAttribute('aria-label', day);
                    body.append(heading);
                });
                const first = new Date(0); first.setUTCFullYear(shown.year, shown.month - 1, 1);
                for (let index = 0; index < first.getUTCDay(); index++) body.append(element('span'));
                for (let day = 1; day <= days(shown.year, shown.month); day++) {
                    const value = iso(shown.year, shown.month, day);
                    const cell = button(String(day), () => select(value));
                    cell.dataset.date = value;
                    cell.setAttribute('aria-label', format(value));
                    cell.setAttribute('aria-pressed', String(value === selected));
                    if (value === today()) cell.setAttribute('aria-current', 'date');
                    cell.disabled = !allowed(source, value);
                    body.append(cell);
                }
                focusValue = iso(shown.year, shown.month, shown.day);
            } else {
                const start = view === 'years' ? Math.max(1, Math.floor((shown.year - 1) / 12) * 12 + 1) : 1;
                const end = view === 'years' ? Math.min(9999, start + 11) : 12;
                status.textContent = view === 'years' ? start + ' – ' + end : String(shown.year);
                for (let value = start; value <= end; value++) {
                    const cell = button(view === 'years' ? String(value) : labels.months[value - 1], () => {
                        if (view === 'years') { shown.year = value; view = 'months'; }
                        else { shown.month = value; shown.day = Math.min(shown.day, days(shown.year, value)); view = 'days'; }
                        render(); focusGrid();
                    });
                    cell.dataset.date = String(value);
                    cell.setAttribute('aria-pressed', String(value === (view === 'years' ? shown.year : shown.month)));
                    body.append(cell);
                }
                focusValue = String(view === 'years' ? shown.year : shown.month);
            }
            const cells = Array.from(body.querySelectorAll('button:not(:disabled)'));
            const focused = cells.find(cell => cell.dataset.date === focusValue) || cells[0];
            cells.forEach(cell => { cell.tabIndex = cell === focused ? 0 : -1; });
        }
        body.addEventListener('keydown', event => {
            const keys = ['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End', 'PageUp', 'PageDown'];
            if (!keys.includes(event.key)) return;
            event.preventDefault();
            if (event.key === 'PageUp' || event.key === 'PageDown') { move(event.key === 'PageUp' ? -1 : 1); focusGrid(); return; }
            const cells = Array.from(body.querySelectorAll('button:not(:disabled)'));
            const position = cells.indexOf(document.activeElement);
            if (position < 0) return;
            const rtl = getComputedStyle(dialog).direction === 'rtl' ? -1 : 1;
            const width = view === 'days' ? 7 : 3;
            const offset = { ArrowLeft: -rtl, ArrowRight: rtl, ArrowUp: -width, ArrowDown: width }[event.key];
            if (view === 'days' && offset !== undefined) {
                let dayNumber = serial(cells[position].dataset.date) + offset;
                const direction = Math.sign(offset);
                const minimum = parse(source.min) ? serial(source.min) : serial('0001-01-01');
                const maximum = parse(source.max) ? serial(source.max) : serial('9999-12-31');
                // Skip step-disabled dates while retaining true seven-day vertical movement.
                for (let attempt = 0; attempt < 366 && dayNumber >= minimum && dayNumber <= maximum; attempt++, dayNumber += direction) {
                    const date = new Date(dayNumber * 86400000);
                    const value = iso(date.getUTCFullYear(), date.getUTCMonth() + 1, date.getUTCDate());
                    if (!allowed(source, value)) continue;
                    shown = parse(value); render(); focusGrid(); return;
                }
                return;
            }
            const target = event.key === 'Home' ? 0 : event.key === 'End' ? cells.length - 1 : position + offset;
            if (target >= 0 && target < cells.length) {
                cells[position].tabIndex = -1; cells[target].tabIndex = 0; cells[target].focus();
                if (view === 'days') shown.day = parse(cells[target].dataset.date).day;
            }
        });
        states.set(source, { display, sync });
    }
    function scan(root) {
        const clones = [];
        if (root.matches?.('input[data-date-enhanced]')) clones.push(root);
        root.querySelectorAll?.('input[data-date-enhanced]').forEach(source => clones.push(source));
        clones.forEach(source => {
            if (states.has(source)) return;
            const wrapper = source.closest('.pl-date-field');
            if (!wrapper) return;
            wrapper.replaceWith(source);
            source.type = 'date'; delete source.dataset.dateEnhanced;
            enhance(source);
        });
        if (root.matches?.('input[type="date"]')) enhance(root);
        root.querySelectorAll?.('input[type="date"]').forEach(enhance);
    }
    if (config.recoveryAction && config.recovery) {
        const candidates = Array.from(document.forms).filter(form => {
            if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post') return false;
            try {
                if (new URL(form.getAttribute('action') || document.URL, document.baseURI).pathname !== config.recoveryAction) return false;
            } catch { return false; }
            return Object.entries(config.recoveryIdentity || {}).every(([key, value]) => String(form.elements.namedItem(key)?.value ?? '') === String(value));
        });
        if (candidates.length === 1) candidates[0].setAttribute('data-date-recovery', '');
    }
    scan(document);
    new MutationObserver(records => records.forEach(record => {
        if (record.type === 'childList') record.addedNodes.forEach(scan);
        else if (record.target.matches('input[type="date"]')) enhance(record.target);
    })).observe(document.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['disabled', 'readonly', 'type'] });
})();
