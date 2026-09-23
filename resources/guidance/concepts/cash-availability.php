<?php
declare(strict_types=1);
return [
    'title'=>'Cash available for a payment',
    'explanation'=>'Physical cash cannot actually be negative. Admin > Accounting policies chooses Warning only (the default) or Strict balance controls. Warning only permits recording a payment despite a shortfall; Strict blocks payments that create or worsen it. The displayed balance comes from posted entries at the selected date; drafts do not reserve or provide money. A backdated payment also affects later balances. Record actual funding, correct the account or date, or keep a draft while you investigate.',
    'here'=>'In Strict mode, banks are checked against a zero balance unless an agreed overdraft limit is explicitly recorded in the account currency. That book-balance check does not predict clearance, pending transactions or holds; confirm available funds with the bank.',
    'document'=>['label'=>'Open the guide','href'=>'/help'],
    'review'=>'placeholder',
];
