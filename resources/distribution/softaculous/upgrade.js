/*
 * PHP Ledger - Softaculous custom package upgrade form validation.
 * Spec: https://www.softaculous.com/docs/developers/making-custom-package/
 * ("upgrade.js" must define formcheck()").
 *
 * upgrade.xml collects no input, so there is nothing to validate; a
 * function still has to exist because Softaculous calls it unconditionally
 * before submitting the upgrade form.
 */

function formcheck() {
    return true;
}
