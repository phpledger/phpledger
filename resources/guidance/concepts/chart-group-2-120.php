<?php
declare(strict_types=1);

/**
 * Interface copy for the help bubble on the group heading 2-120-00000-00.
 *
 * English source strings for pl_t(); see this directory's README for the shape. Written for a
 * reader who has never done bookkeeping: what the heading is, and what it is supposed to hold.
 * 'review' stays 'placeholder' until the guidance review (docs/accounting/guidance/CONCEPTS.md)
 * has passed the wording.
 */
return [
    'title' => 'Contract Liabilities and Customer Advances',
    'explanation' => 'Money a customer has paid before you have delivered. It is not earned yet, so it is not revenue: until the goods or the service are supplied, the business owes that customer either delivery or a refund. The same group holds a receipt that has not yet been matched to an invoice.',
    'here' => 'Unapplied credit from a receipt is held here and applied to a later invoice with no bank line at all.',
    'document' => ['label' => 'Cash versus accrual bookkeeping', 'url' => 'https://phpledger.com/learn/cash-vs-accrual/'],
    'review' => 'placeholder',
];
