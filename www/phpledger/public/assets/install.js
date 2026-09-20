/*
 * Each request applies one versioned schema step; the server stops on any error.
 * While that runs by itself the button is hidden, because a visible button on a
 * page that is already working reads as "waiting for you". Without JavaScript it
 * stays visible and the noscript paragraph explains it.
 */
'use strict';
const installationForm = document.querySelector('form[data-install-continue]');
if (installationForm instanceof HTMLFormElement) {
  const automatic = installationForm.querySelector('[data-install-auto]');
  if (automatic instanceof HTMLButtonElement) {
    automatic.hidden = true;
  }
  window.setTimeout(() => installationForm.requestSubmit(), 120);
}
