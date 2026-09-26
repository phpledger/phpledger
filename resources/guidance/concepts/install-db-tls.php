<?php
declare(strict_types=1);
// Installer field help (owner review, 25-26 September 2026). Wording approved on the mockup; no accounting claim is made here.
return [
    'title' => 'TLS CA certificate path',
    'explanation' => 'Only for a database on another server that requires an encrypted connection, as PlanetScale, Aiven, DigitalOcean and Azure do. Enter the server-side path to the certificate authority file your provider gives you, for example /home/acme/ca.pem. The server\'s identity is always verified against that file; there is no switch to turn verification off. Leave it empty otherwise.',
    'here' => '',
    'document' => null,
    'review' => 'reviewed',
];
