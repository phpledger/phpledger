<?php
declare(strict_types=1);

/**
 * Interface copy for the help bubble on the group heading 2-100-00000-00.
 *
 * English source strings for pl_t(); see this directory's README for the shape. Written for a
 * reader who has never done bookkeeping: what the heading is, and what it is supposed to hold.
 * 'review' stays 'placeholder' until the guidance review (docs/accounting/guidance/CONCEPTS.md)
 * has passed the wording.
 */
return [
    'title' => 'Trade and Other Payables',
    'explanation' => 'What the business owes its suppliers and other short-term creditors: bills received for goods and services already taken but not yet paid, and smaller amounts owed for anything else. The cost is already in the profit and loss account; this group records that the money still has to go out.',
    'here' => 'Each supplier\'s unpaid bills are tracked as open items, so this control and the ageing report always agree.',
    'document' => ['label' => 'Cash versus accrual bookkeeping', 'url' => 'https://phpledger.com/learn/cash-vs-accrual/'],
    'review' => 'placeholder',
];
