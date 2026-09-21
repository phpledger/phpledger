<?php
declare(strict_types=1);

/**
 * United Arab Emirates. Local notes for the help bubbles, keyed by concept id.
 *
 * First-class from the start under owner decision B67, not a later addition. Nothing here reaches a
 * screen yet: every entry is 'unverified', and pl_guidance_note() drops any note that is not
 * 'verified' with a source and a check date. resources/tax/united-arab-emirates.json is
 * research_only and review_required, so it is where the research starts, not a citable source.
 */
return [
    'unapplied-credit' => [
        'note' => 'Pending research: whether a payment received before supply triggers a tax point here, and what the invoice must show when it does.',
        'status' => 'unverified',
        'source' => '',
        'checked_on' => '',
    ],
    'free-goods' => [
        'note' => 'Pending research: the local treatment of goods supplied free of charge, and whether output tax is due on them.',
        'status' => 'unverified',
        'source' => '',
        'checked_on' => '',
    ],
];
