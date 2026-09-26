<?php
declare(strict_types=1);
// Installer field help (owner review, 25-26 September 2026). Wording approved on the mockup; no accounting claim is made here.
return [
    'title' => 'Installing PHP Ledger',
    'explanation' => 'Setup runs in four stages: it connects to your database, builds the tables and protective rules, saves its private settings and creates your sign-in account. Nothing is written until the database check passes, and no business or transaction is created here. An installation that already exists must use the upgrade procedure; setup never replaces or bypasses existing accounting history.',
    'here' => 'Each step fits one screen. A question mark beside a label explains that field in plain words; the wiki\'s install guides cover XAMPP, Docker Desktop and shared hosting.',
    'document' => null,
    'review' => 'reviewed',
];
