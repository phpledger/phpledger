<?php
declare(strict_types=1);

// Existing books retain warning-only posting until their administrator opts into strict controls.
return [
    "ALTER TABLE pl_trading_policies ADD cash_shortfall_policy ENUM('warning','strict') NOT NULL DEFAULT 'warning'",
];
