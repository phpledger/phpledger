/*
 * Setup page behaviour. Everything degrades: without JavaScript the build continues from a
 * visible button, passwords stay masked, the primary button is simply navy, and the logo
 * shows no preview. Nothing here uses an inline handler or a style attribute; the page's
 * Content-Security-Policy forbids both.
 */
'use strict';

// Each request applies a batch of schema steps; while that runs by itself the button is hidden,
// because a visible button on a page that is already working reads as "waiting for you".
const installationForm = document.querySelector('form[data-install-continue]');
if (installationForm instanceof HTMLFormElement) {
  const automatic = installationForm.querySelector('[data-install-auto]');
  if (automatic instanceof HTMLButtonElement) {
    automatic.hidden = true;
  }
  window.setTimeout(() => installationForm.requestSubmit(), 120);
}

// The eye beside a password field shows what was typed, so a typo cannot lock anyone out.
document.querySelectorAll('[data-toggle-password]').forEach(button => {
  button.addEventListener('click', () => {
    const input = document.querySelector(button.getAttribute('data-toggle-password') || '');
    if (!(input instanceof HTMLInputElement)) return;
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    button.querySelectorAll(':scope > .icon, :scope > span').forEach(part => part.toggleAttribute('hidden'));
  });
});

// Generate a password into both fields and show it, so it can be copied into a password manager.
document.querySelectorAll('[data-generate-password]').forEach(button => {
  button.addEventListener('click', () => {
    const alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789-.';
    const bytes = new Uint8Array(14);
    crypto.getRandomValues(bytes);
    const value = Array.from(bytes, b => alphabet[b % alphabet.length]).join('');
    (button.getAttribute('data-generate-password') || '').split(',').forEach(selector => {
      const input = document.querySelector(selector.trim());
      if (input instanceof HTMLInputElement) {
        input.value = value;
        input.type = 'text';
        input.dispatchEvent(new Event('input', { bubbles: true }));
      }
    });
  });
});

// A length line, not a dictionary: the rule is six characters, the advice is longer.
document.querySelectorAll('[data-strength-for]').forEach(meter => {
  const input = document.querySelector(meter.getAttribute('data-strength-for') || '');
  const label = meter.querySelector('[data-strength-label]');
  if (!(input instanceof HTMLInputElement)) return;
  const update = () => {
    const n = input.value.length;
    meter.classList.toggle('is-strong', n >= 12);
    meter.classList.toggle('is-fair', n >= 8 && n < 12);
    if (label) {
      label.textContent = n === 0 ? 'At least 6 characters' : n < 6 ? (6 - n) + ' more to go' : n < 8 ? 'Allowed, but short' : n < 12 ? 'Fair' : 'Strong';
    }
  };
  input.addEventListener('input', update);
  update();
});

// The primary button turns green once every required field is valid.
document.querySelectorAll('form[data-ready-button]').forEach(form => {
  const button = document.querySelector(form.getAttribute('data-ready-button') || '');
  if (!(button instanceof HTMLButtonElement)) return;
  const update = () => button.classList.toggle('is-ready', form.checkValidity());
  form.addEventListener('input', update);
  form.addEventListener('change', update);
  update();
});

// The logo drop zone previews the chosen file; the CSP allows blob: images for exactly this.
document.querySelectorAll('[data-dropzone]').forEach(zone => {
  const input = zone.querySelector('input[type="file"]');
  const preview = zone.querySelector('[data-dropzone-preview]');
  const text = zone.querySelector('[data-dropzone-text]');
  if (!(input instanceof HTMLInputElement)) return;
  const show = () => {
    const file = input.files && input.files[0];
    if (!file || !preview) return;
    const image = document.createElement('img');
    image.src = URL.createObjectURL(file);
    image.alt = '';
    image.addEventListener('load', () => URL.revokeObjectURL(image.src));
    preview.replaceChildren(image);
    if (text) text.textContent = file.name + ' · ' + Math.max(1, Math.round(file.size / 1024)) + ' KB';
  };
  input.addEventListener('change', show);
  ['dragenter', 'dragover'].forEach(name => zone.addEventListener(name, event => { event.preventDefault(); zone.classList.add('is-over'); }));
  ['dragleave', 'drop'].forEach(name => zone.addEventListener(name, () => zone.classList.remove('is-over')));
  zone.addEventListener('drop', event => {
    event.preventDefault();
    if (event.dataTransfer && event.dataTransfer.files.length > 0) {
      input.files = event.dataTransfer.files;
      show();
    }
  });
});

// Copy the sign-in details (address, username, email) as plain text; the password is never in the page.
document.querySelectorAll('[data-copy-signin]').forEach(button => {
  button.addEventListener('click', async () => {
    const card = button.closest('[data-signin-card]');
    if (!card || !navigator.clipboard) return;
    const lines = Array.from(card.querySelectorAll('[data-signin]')).map(cell => cell.getAttribute('data-signin') + ': ' + (cell.textContent || '').trim());
    try {
      await navigator.clipboard.writeText(lines.join('\n'));
      button.textContent = 'Copied';
    } catch (error) {
      button.textContent = 'Select and copy the details above';
    }
  });
});
