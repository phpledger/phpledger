'use strict';
document.querySelectorAll('details[data-help]').forEach(help => {
    const summary = help.querySelector('summary');
    help.addEventListener('toggle', () => {
        if (!help.open) return;
        document.querySelectorAll('details[data-help][open]').forEach(other => { if (other !== help) other.open = false; });
    });
    help.addEventListener('keydown', event => {
        if (event.key !== 'Escape' || !help.open) return;
        event.stopPropagation();
        help.open = false;
        summary?.focus();
    });
});
document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    document.querySelectorAll('details[data-help][open]').forEach(help => { help.open = false; });
});
document.addEventListener('click', event => {
    document.querySelectorAll('details[data-help][open]').forEach(help => {
        if (!help.contains(event.target)) help.open = false;
    });
});
