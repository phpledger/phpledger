<?php
declare(strict_types=1);

/**
 * Interface copy for the help bubble on the group heading 4-900-00000-00.
 *
 * English source strings for pl_t(); see this directory's README for the shape. Written for a
 * reader who has never done bookkeeping: what the heading is, and what it is supposed to hold.
 * 'review' stays 'placeholder' until the guidance review (docs/accounting/guidance/CONCEPTS.md)
 * has passed the wording.
 */
return [
    'title' => 'Revenue Deductions',
    'explanation' => 'Amounts taken back off what was earned: goods a customer returned, an allowance given for a damaged delivery, and discounts allowed for early payment. They belong inside revenue as a subtraction rather than among the expenses, so the top line shows what was really kept and why it was reduced.',
    'here' => 'These are contra accounts: the profit and loss account shows them as deductions inside the income section.',
    'document' => ['label' => 'Read the full article', 'url' => 'https://phpledger.com/learn/revenue-deductions'],
    'review' => 'placeholder',
];
