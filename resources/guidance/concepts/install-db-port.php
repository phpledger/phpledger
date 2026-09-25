<?php
declare(strict_types=1);
// Installer field help (owner review, 25-26 September 2026). Wording approved on the mockup; no accounting claim is made here.
return [
    'title' => 'Port',
    'explanation' => 'The door the database server listens on. MySQL and MariaDB answer on 3306 unless your host or your local stack says otherwise. Setup probes the usual ports on this computer and fills in the first one that answers, so leave it alone unless the database is on another server that uses a different port.',
    'here' => '',
    'document' => null,
    'review' => 'reviewed',
];
