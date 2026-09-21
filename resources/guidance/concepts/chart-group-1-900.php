<?php
declare(strict_types=1);

/**
 * Interface copy for the help bubble on the group heading 1-900-00000-00.
 *
 * English source strings for pl_t(); see this directory's README for the shape. Written for a
 * reader who has never done bookkeeping: what the heading is, and what it is supposed to hold.
 * 'review' stays 'placeholder' until the guidance review (docs/accounting/guidance/CONCEPTS.md)
 * has passed the wording.
 */
return [
    'title' => 'Accumulated Depreciation and Impairment',
    'explanation' => 'The running total of the value already used up on the equipment above. The wear is collected here instead of being taken off the asset itself, so a reader can still see both what was paid for something and how much of that has been charged to profit so far.',
    'here' => 'It is a contra account: it is shown as a deduction inside the asset section, never as a liability.',
    'document' => ['label' => 'Read the full article', 'url' => 'https://phpledger.com/learn/accumulated-depreciation'],
    'review' => 'placeholder',
];
