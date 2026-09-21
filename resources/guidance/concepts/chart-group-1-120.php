<?php
declare(strict_types=1);

/**
 * Interface copy for the help bubble on the group heading 1-120-00000-00.
 *
 * English source strings for pl_t(); see this directory's README for the shape. Written for a
 * reader who has never done bookkeeping: what the heading is, and what it is supposed to hold.
 * 'review' stays 'placeholder' until the guidance review (docs/accounting/guidance/CONCEPTS.md)
 * has passed the wording.
 */
return [
    'title' => 'Prepayments and Advances',
    'explanation' => 'Money already paid out for something the business has not had yet: a supplier paid before delivery, a year of insurance paid in one go, rent paid for months still to come. It stays an asset, not a cost, until the goods arrive or the time it covers has actually passed.',
    'here' => 'A supplier advance waits here until a bill is entered against it. Only then does it become a cost.',
    'document' => ['label' => 'Read the full article', 'url' => 'https://phpledger.com/learn/prepayments-and-advances'],
    'review' => 'placeholder',
];
