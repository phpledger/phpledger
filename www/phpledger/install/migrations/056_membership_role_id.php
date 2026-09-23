<?php
declare(strict_types=1);

/** Retire the ENUM authority; preserve its old values for the published updater verifier. */
return [
    // Old fixtures/imports could still insert a NULL role_id during 1.2. Resolve only those;
    // never overwrite an explicit custom assignment with its coarse legacy label.
    "UPDATE pl_company_members m JOIN pl_roles r ON r.company_id IS NULL AND r.is_system = 1 AND r.slug = m.role SET m.role_id = r.id WHERE m.role_id IS NULL",
    // The 1.2.1 copied updater selects every original column and verifies its old values.
    // Keep an inert string snapshot until a future updater contract permits removing it.
    // New memberships use the harmless default; runtime never reads or writes this snapshot.
    'ALTER TABLE pl_company_members MODIFY COLUMN role_id BIGINT UNSIGNED NOT NULL',
    "ALTER TABLE pl_company_members MODIFY COLUMN role VARCHAR(10) NOT NULL DEFAULT 'viewer'",
];
