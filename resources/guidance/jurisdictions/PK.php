<?php
declare(strict_types=1);

/**
 * Pakistan. Local notes for the help bubbles, keyed by concept id.
 *
 * Nothing here reaches a screen yet: every entry is 'unverified', and pl_guidance_note() drops any
 * note that is not 'verified' with a source and a check date (owner decision B67 — an unsourced
 * local claim is worse than none). The guidance research in docs/accounting/guidance/ supplies the
 * wording, the authority and the date; changing 'status' to 'verified' is what publishes it.
 *
 * The repository's existing Pakistan material (docs/accounting/PAKISTAN_REPORTING_RESEARCH.md,
 * resources/tax/pakistan.json) is marked research_only and review_required, so it is a starting
 * point for that research, not a source that can be cited straight into a bubble.
 */
return [
    'unapplied-credit' => [
        'note' => 'Pending research: how an advance from a customer is described and treated locally, and what the sales-tax position is at the time the advance is received rather than at supply.',
        'status' => 'unverified',
        'source' => '',
        'checked_on' => '',
    ],
    'drawings' => [
        'note' => 'Pending research: the treatment of proprietor and AOP partner drawings, and the terms an accountant here expects to see on the statement of capital.',
        'status' => 'unverified',
        'source' => '',
        'checked_on' => '',
    ],
];
