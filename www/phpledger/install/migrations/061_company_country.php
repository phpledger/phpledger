<?php
declare(strict_types=1);

// 1.4.5 (owner decision, 26 September 2026): the country of registration is a fact of the
// entity, recorded beside its legal form rather than inferred from the legal-form key. Empty
// means not stated. Nothing reads it to select a tax rule or an accounting treatment (B63).
return [
    "ALTER TABLE pl_company_profile ADD COLUMN country_code CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' AFTER legal_form",
];
