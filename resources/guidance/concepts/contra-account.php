<?php
declare(strict_types=1);

/** Interface copy for the help bubble; see resources/guidance/README.md. */
return [
    'title' => 'Contra account',
    'explanation' => 'A contra account sits inside a section but points the other way, so it is shown as a deduction rather than moved to the opposite side. Accumulated depreciation reduces the assets it belongs to; drawings reduce owner capital. Keeping it in its own section is what lets the report show both the original figure and what is left.',
    'here' => 'A row marked “Less:” is a contra account. It is subtracted inside its own class, which is why the debit and credit totals still agree.',
    'review' => 'placeholder',
];
