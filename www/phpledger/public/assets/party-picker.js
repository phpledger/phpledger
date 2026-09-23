'use strict';
// Progressive enhancement: a native select retains keyboard and no-JavaScript support.
document.querySelectorAll('[data-party-picker]').forEach((picker) => {
    const search = picker.querySelector('[data-party-search]');
    const select = picker.querySelector('[data-party-select]');
    if (!search || !select) return;
    const options = Array.from(select.options).map((option) => option.cloneNode(true));
    const status = picker.querySelector('[data-party-results]');
    const originalStatus = status?.textContent;
    search.addEventListener('input', () => {
        const selected = select.value;
        const query = search.value.trim().toLocaleLowerCase();
        select.replaceChildren(...options.filter((option) => !option.value || option.value === selected || option.textContent.toLocaleLowerCase().includes(query)).map((option) => option.cloneNode(true)));
        select.value = selected;
        if (status) status.textContent = select.options.length > 1 ? originalStatus : status.dataset.emptyMessage;
    });
});
document.querySelectorAll('[data-cash-preview]').forEach((preview) => {
    const form = preview.closest('form');
    if (!form) return;
    const markStale = () => {
        preview.hidden = true;
        const stale = form.querySelector('[data-cash-preview-stale]');
        if (stale) stale.hidden = false;
    };
    ['date', 'amount', 'money_account_id'].forEach((name) => {
        const field = form.elements.namedItem(name);
        if (!field) return;
        field.addEventListener('input', markStale);
    });
    // app.js supplies the browser's local date after this deferred script runs.
    document.addEventListener('DOMContentLoaded', () => {
        if (form.elements.namedItem('date')?.value !== preview.dataset.previewDate) markStale();
    }, { once: true });
});
