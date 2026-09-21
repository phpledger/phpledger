<?php
declare(strict_types=1);

/**
 * TEST FIXTURE ONLY — not shipped, not research, and not a statement about Pakistani practice.
 *
 * The shipped catalogue carries no verified jurisdiction note yet: the guidance research in
 * docs/accounting/guidance/ has not landed, and B67 forbids shipping an unsourced local claim. So
 * the tests prove the jurisdiction path with an obviously fictional note, loaded through
 * PL_GUIDANCE_PATH, the same overlay an installation would use. The wording is nonsense on
 * purpose: if it ever reaches a real screen, it is unmistakable.
 */
return [
    'debit-and-credit' => [
        'note' => 'Sample fixture note for the automated tests. It is not a statement of local practice and never ships.',
        'status' => 'verified',
        'source' => 'PHP Ledger test fixture',
        'checked_on' => '2026-09-21',
    ],
    'contra-account' => [
        'note' => 'Sample fixture note that is deliberately not verified, so the loader must drop it.',
        'status' => 'unverified',
        'source' => 'PHP Ledger test fixture',
        'checked_on' => '2026-09-21',
    ],
    'drawings' => [
        'note' => 'Sample fixture note marked verified but carrying no source, so the loader must drop it too.',
        'status' => 'verified',
        'source' => '',
        'checked_on' => '2026-09-21',
    ],
];
