<?php
declare(strict_types=1);

/**
 * Interface copy for the help bubble on the group heading 5-900-00000-00.
 *
 * English source strings for pl_t(); see this directory's README for the shape. Written for a
 * reader who has never done bookkeeping: what the heading is, and what it is supposed to hold.
 * 'review' stays 'placeholder' until the guidance review (docs/accounting/guidance/CONCEPTS.md)
 * has passed the wording.
 */
return [
    'title' => 'Purchase Returns and Discounts Received',
    'explanation' => 'Amounts that reduce what was bought: goods sent back to a supplier, an allowance for a short or damaged delivery, and a discount a supplier gave for early payment. They are shown as a deduction inside the expense section rather than as income, so the report shows the real cost of buying.',
    'here' => 'These are contra accounts: they are presented as subtractions inside expenses, never as revenue.',
    'document' => ['label' => 'Read the full article', 'url' => 'https://phpledger.com/learn/purchase-returns'],
    'review' => 'placeholder',
];
