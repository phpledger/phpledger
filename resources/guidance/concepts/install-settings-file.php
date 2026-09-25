<?php
declare(strict_types=1);
// Installer field help (owner review, 25-26 September 2026). Wording approved on the mockup; no accounting claim is made here.
return [
    'title' => 'Private settings file',
    'explanation' => 'It holds the database connection and this site\'s address, and it sits outside the public folder so a browser can never fetch it. Setup writes it for you wherever the server allows. When the host refuses, you place the file once by hand, then setup checks it and carries on. Setup never overwrites a settings file that already exists.',
    'here' => '',
    'document' => null,
    'review' => 'reviewed',
];
