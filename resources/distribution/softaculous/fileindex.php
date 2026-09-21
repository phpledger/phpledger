<?php
/**
 * PHP Ledger - Softaculous root-level file index for uninstall.
 *
 * Spec: https://www.softaculous.com/docs/developers/making-custom-package/
 * The docs describe fileindex.php only in prose - "This file should contain
 * the files and folders which are present in the root of the installation
 * ... only the files and folders present in this file will be removed from
 * the root" - with no example of its PHP syntax. No primary source gives
 * the exact variable name or structure Softaculous reads.
 *
 * TODO: confirm the exact syntax (variable name, array vs. other structure)
 * against a real accepted Softaculous package or by asking Softaculous
 * support before relying on this for removal in production. What follows is
 * a best-effort array of the application's actual top-level layout after
 * unzip (see resources/distribution/README.md and the release archive),
 * not a verified schema.
 */

declare(strict_types=1);

$fileindex = array(
    'index.php',
    '.htaccess',
    'www',
    'resources',
    'tools',
    'LICENSE',
    'README.txt',
);
