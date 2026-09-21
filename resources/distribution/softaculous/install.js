/*
 * PHP Ledger - Softaculous custom package client-side validation.
 * Spec: https://www.softaculous.com/docs/developers/making-custom-package/
 * ("install.js" must define formcheck(); Softaculous calls it before
 * submitting the install form and expects a boolean return, matching each
 * field by the "name" attribute declared in install.xml").
 */

function formcheck() {
    var adminEmail = document.getElementsByName('admin_email')[0];
    var adminName = document.getElementsByName('admin_name')[0];
    var adminPass = document.getElementsByName('admin_pass')[0];
    var dbPort = document.getElementsByName('db_port')[0];

    if (!adminEmail || !adminName || !adminPass) {
        // Fields missing from the rendered form is a packaging bug, not a
        // user error; do not block submission on it.
        return true;
    }

    var emailValue = adminEmail.value.replace(/^\s+|\s+$/g, '');
    var emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailPattern.test(emailValue)) {
        alert('Enter a valid administrator email address.');
        return false;
    }

    if (adminName.value.replace(/^\s+|\s+$/g, '') === '') {
        alert('Enter an administrator name.');
        return false;
    }

    if (adminPass.value.length < 8) {
        alert('The administrator password must be at least 8 characters.');
        return false;
    }

    if (dbPort) {
        var portValue = dbPort.value.replace(/^\s+|\s+$/g, '');
        var portNumber = parseInt(portValue, 10);
        if (portValue === '' || isNaN(portNumber) || portNumber < 1 || portNumber > 65535) {
            alert('Enter a valid database port (1-65535).');
            return false;
        }
    }

    return true;
}
