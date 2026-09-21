<?php
/**
 * PHP Ledger - Installatron package locale file (English).
 *
 * Installatron's docs confirm this file's name and location
 * (installers/_installerid_/locale_en.php, PHP format) but the fetched
 * developer documentation did not show its required internal structure
 * (variable name, array shape, or key naming convention for string IDs
 * referenced from init.xml). TODO: confirm the exact expected structure
 * against the Installatron Installer Editor or direct developer support
 * before this file is relied on; what follows is a best-effort, clearly
 * labelled guess in the same "flat associative array" shape most panel
 * locale files use, not a verified schema.
 */

declare(strict_types=1);

return array(
    'name' => 'PHP Ledger',
    'description' => 'Self-hosted double-entry accounting: sales, purchases, cash and the general ledger, on plain PHP and MySQL.',
    'field_email_label' => 'Administrator email',
    'field_login_label' => 'Administrator username',
    'field_passwd_label' => 'Administrator password',
    'field_adminname_label' => 'Administrator name',
);
