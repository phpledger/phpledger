<?php
declare(strict_types=1);

// Owner-approved explicit classification. No name/code inference and no historical backfill.
// Existing combined cash/bank accounts remain unclassified until an audited owner decision.
return [
    "ALTER TABLE pl_accounts ADD money_kind ENUM('physical','bank') NULL AFTER role,
        ADD CONSTRAINT ck_account_money_kind CHECK (money_kind IS NULL OR (role IS NOT NULL AND role='cash_bank' AND type='asset'))",
];
