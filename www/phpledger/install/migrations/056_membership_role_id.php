<?php
declare(strict_types=1);

/** End the 1.2 compatibility mirror. Existing role_id and custom grants are authoritative. */
return [
    // Old fixtures/imports could still insert a NULL role_id during 1.2. Resolve only those;
    // never overwrite an explicit custom assignment with its coarse legacy label.
    "UPDATE pl_company_members m JOIN pl_roles r ON r.company_id IS NULL AND r.is_system = 1 AND r.slug = m.role SET m.role_id = r.id WHERE m.role_id IS NULL",
    // Refuse an unresolved membership before removing the only legacy information.
    'ALTER TABLE pl_company_members MODIFY COLUMN role_id BIGINT UNSIGNED NOT NULL, DROP COLUMN role',
];
