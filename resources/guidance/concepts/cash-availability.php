<?php
declare(strict_types=1);
return [
    'title'=>'Cash available for a payment',
    'explanation'=>'Physical cash cannot actually be negative. Accounting policies defaults to Warning only, permitting payments despite shortfalls. Strict mode blocks payments that create or worsen shortfalls. Posted entries determine the balance at the selected date; drafts provide no money. Backdated payments affect later balances. Record actual funding, correct the account or date, or keep a draft while investigating.',
    'here'=>'In Strict mode, banks are checked against a zero balance unless an agreed overdraft limit is explicitly recorded in the account currency. That book-balance check does not predict clearance, pending transactions or holds; confirm available funds with the bank.',
    'document'=>['label'=>'Open the guide','href'=>'/help'],
    'review'=>'placeholder',
];
