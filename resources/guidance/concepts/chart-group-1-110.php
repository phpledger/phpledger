<?php
declare(strict_types=1);

/**
 * Interface copy for the help bubble on the group heading 1-110-00000-00.
 *
 * English source strings for pl_t(); see this directory's README for the shape. Written for a
 * reader who has never done bookkeeping: what the heading is, and what it is supposed to hold.
 * 'review' stays 'placeholder' until the guidance review (docs/accounting/guidance/CONCEPTS.md)
 * has passed the wording.
 */
return [
    'title' => 'Trade and Other Receivables',
    'explanation' => 'Money other people owe the business. Most of it is trade: customers invoiced for goods or services you have already given them, who have not paid yet. The rest is anything else owed to the business, such as a refund due from a supplier. It is an asset because the cash is expected.',
    'here' => 'Each customer\'s unpaid invoices are tracked as open items, so this control and the ageing report always agree.',
    'document' => ['label' => 'Cash versus accrual bookkeeping', 'url' => 'https://phpledger.com/learn/cash-vs-accrual/'],
    'review' => 'placeholder',
];
