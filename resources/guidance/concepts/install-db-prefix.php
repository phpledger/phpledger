<?php
declare(strict_types=1);
// Installer field help (owner review, 25-26 September 2026). Wording approved on the mockup; no accounting claim is made here.
return [
    'title' => 'Table prefix',
    'explanation' => 'Every table PHP Ledger creates starts with this short prefix, which is how two installations can share one database without touching each other\'s tables. Keep the default pl_ unless you need a second copy in the same database. It cannot be changed after installation, because every table, view and protective rule is named with it.',
    'here' => '',
    'document' => null,
    'review' => 'reviewed',
];
