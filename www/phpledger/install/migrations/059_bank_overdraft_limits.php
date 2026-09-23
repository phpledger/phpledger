<?php
declare(strict_types=1);

// No existing bank is granted credit. A facility is an explicit, audited account decision.
// Its amount is denominated in the account currency, or the book currency when undesignated.
return [
    "ALTER TABLE pl_accounts
        ADD overdraft_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER money_kind,
        ADD overdraft_limit DECIMAL(20,4) NOT NULL DEFAULT 0.0000 AFTER overdraft_enabled,
        ADD CONSTRAINT ck_account_overdraft CHECK (
            (overdraft_enabled=0 AND overdraft_limit=0) OR
            (overdraft_enabled=1 AND overdraft_limit>0 AND money_kind IS NOT NULL AND money_kind='bank' AND role IS NOT NULL AND role='cash_bank' AND type='asset')
        )",
];
