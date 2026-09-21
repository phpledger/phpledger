<?php
declare(strict_types=1);

/**
 * Named class and group headings for a chart that already has structured codes.
 *
 * **The defect.** `pl_report_tree()` takes the name of a class or group from an
 * account row whose code is a heading code (`X-000-00000-00`, `X-GGG-00000-00`)
 * and, when there is none, falls back to `'Group ' . pl_account_code_short()`.
 * Migration 036 renumbered every existing chart into groups and created no
 * heading row for any of them, and the chart package shipped in 1.2.0 contained
 * none either, so **every** group of **every** book reads "Group 1-100" on
 * every report. The bundled chart is fixed by `core-starter-1.2.0.json`, which
 * only reaches a book created after it. This migration is the same fix for the
 * books that already exist.
 *
 * **What it is allowed to write.** New rows in `pl_accounts` whose code is a
 * heading code, and nothing else. It runs no UPDATE and no DELETE, so no
 * posting account's code, name, type, role, semantic key, currency, status or
 * `is_contra` is touched, no journal, line, open item or balance can move, and
 * `pl_core_audit` is not written to and its `entity_type` ENUM is not altered
 * (the bug migration 038 carried). A heading is not postable —
 * `pl_account_is_postable()` refuses a heading code before it ever reaches a
 * book — so the rows below cannot take a posting even by accident.
 *
 * **Idempotent, and safe to meet a book that already has them.** Every
 * statement is `INSERT ... SELECT ... FROM pl_books WHERE NOT EXISTS` on the
 * exact code it would write, so running it twice writes nothing the second
 * time, and a book created from `core-starter-1.2.0` — which already carries
 * all nineteen headings — is skipped entirely. `uq_accounts_code (book_id,
 * code)` is the backstop underneath that.
 *
 * **Every row it writes is marked.** `creation_key` is set to
 * `migration-045-<code>`, which is unique per book, so the rows this migration
 * created can be told apart from the ones the chart package installs and from
 * anything an owner adds. That is what makes the reversal exact:
 *
 *     DELETE FROM pl_accounts WHERE creation_key LIKE 'migration-045-%';
 *
 * Nothing references them: they hold no posting, they are not a party control,
 * no semantic key or role points at them, and `pl_owner_partners` cannot name
 * one because a heading carries no role. Deleting them restores the "Group
 * 1-100" labels and changes no figure.
 *
 * ---------------------------------------------------------------------------
 * How each name is arrived at, and where it deliberately stops
 * ---------------------------------------------------------------------------
 *
 * 1. **A class heading is deterministic.** The class digit *is* the
 *    classification — 1 asset, 2 liability, 3 equity, 4 income, 5 expense — so
 *    "Assets", "Liabilities", "Equity", "Revenue" and "Expenses" are the same
 *    five names `pl_account_code_class_label()` already returns at runtime.
 *    Nothing is inferred; the row is created for every book that has at least
 *    one structured account of that class.
 *
 * 2. **A group heading is taken from `semantic_key`, never from a name.**
 *    `semantic_key` is written in exactly two places — `pl_create_company()`
 *    from the bundled chart, and `pl_confirm_existing_setup()` where the owner
 *    maps each starter purpose onto an existing account — and
 *    `uq_account_semantic (book_id, semantic_key)` keeps one account per
 *    purpose per book. It is a recorded fact about an account, not a guess
 *    about it. The thirteen keys below are the thirteen the bundled chart
 *    defines, and each names the owner-approved group it belongs to (B60 keeps
 *    the 900 band of every class for contra groups, which is why `1-900` is
 *    "Accumulated Depreciation and Impairment" and not the asset heading
 *    "Property, Plant and Equipment").
 *
 *    Deriving from an account's *name* is forbidden — see migration 040's
 *    rejected alternatives, and `AGENTS.md` on not guessing missing accounting
 *    data. Nothing here reads a name.
 *
 * 3. **A group that holds two starter purposes is left alone.** After 036 a
 *    group is the chart's own group: the first two characters of the number the
 *    chart already used. A book that numbered cash `1000` and receivables
 *    `1010` has both in one group, and naming that group after either purpose
 *    would describe the other one wrongly. Each statement therefore refuses
 *    when a second starter-keyed account shares the group. Such a group keeps
 *    the fallback label until its owner names it, which is an ordinary, audited
 *    edit on the chart of accounts screen: a heading is a normal chart row and
 *    its name is editable.
 *
 * 4. **Every row carries a `semantic_key`, and that is how a module finds it.**
 *    Not the name, which is translated and which its owner may edit, and not
 *    the number, which 036 allocated from each chart's own groups and which
 *    therefore means different things in different books.
 *    `pl_account_heading_by_key()` is the one resolver and it reports whether it
 *    matched the key or fell back on the bundled chart's code, so a module never
 *    assumes silently. A heading written without a key would look like a
 *    successful migration and leave the fixed-asset register unable to find
 *    Property, Plant and Equipment.
 *
 *    A heading carries **no `role`**, deliberately. A role names where a posting
 *    goes; `pl_save_account()` already refuses one on a heading code, and a
 *    second active account holding `receivables`, `payables`,
 *    `customer_advances` or `supplier_advances` would leave `pl_ar_control()`
 *    and `pl_advance_control()` with no "one unambiguous default" and refuse
 *    every document and every receipt in the book.
 *
 * 5. **One group is added that an existing book does not have: `1-200 Property,
 *    Plant and Equipment`**, and only where the number provably means that — a
 *    book with no conversion receipt at all, whose `core.cash_bank` still sits
 *    at the bundled `1-100-10001-00`. A converted book is left without it,
 *    because 036 renumbered its groups and nothing here can say what an unused
 *    number in that chart would mean. Adding structure to somebody's chart on a
 *    guess is not a migration's business; adding back a group of the chart they
 *    were demonstrably created from is.
 *
 * A heading is a label. It changes no balance, no classification and no
 * posting, so a name that turns out not to suit a book is corrected in the
 * chart of accounts screen without touching a single figure.
 */

// The bundled chart's thirteen purposes, and for each one the owner-approved
// name of the group it sits in and that group heading's own semantic key.
// Frozen here on purpose: a migration's statements must not change with a
// resource file. `tests/chart_headings_test.php` asserts that this list and
// `resources/coa/core-starter-1.2.0.json` still agree, so the two cannot drift.
//
// The key is the point of the row, not decoration. `semantic_key` is how a
// module finds a group — `pl_account_heading_by_key()` — because a name is
// translated or edited and a group *number* was allocated by 036 from each
// chart's own groups. A heading written without a key would look like a
// successful migration and leave the fixed-asset register unable to find
// Property, Plant and Equipment. Every row below therefore carries one.
$groups = [
    'core.cash_bank' => ['Cash and Cash Equivalents', 'core.group.asset.cash_equivalents'],
    'core.receivables.trade' => ['Trade and Other Receivables', 'core.group.asset.receivables'],
    'core.asset.supplier_advances' => ['Prepayments and Advances', 'core.group.asset.prepayments'],
    'core.asset.accumulated_depreciation' => ['Accumulated Depreciation and Impairment', 'core.group.asset.accumulated_depreciation'],
    'core.payables.trade' => ['Trade and Other Payables', 'core.group.liability.payables'],
    'core.liability.owner_loan' => ['Loans and Borrowings', 'core.group.liability.borrowings'],
    'core.liability.customer_advances' => ['Contract Liabilities and Customer Advances', 'core.group.liability.contract_liabilities'],
    'core.equity.owner' => ['Capital and Reserves', 'core.group.equity.capital'],
    'core.equity.drawings' => ['Drawings', 'core.group.equity.drawings'],
    'core.income.sales' => ['Revenue', 'core.group.income.revenue'],
    'core.income.sales_returns' => ['Revenue Deductions', 'core.group.income.revenue_deductions'],
    'core.expense.general' => ['Operating Expenses', 'core.group.expense.operating'],
    'core.expense.purchase_returns' => ['Purchase Returns and Discounts Received', 'core.group.expense.purchase_returns'],
];

$classes = [
    1 => ['asset', 'Assets', 'core.class.asset'],
    2 => ['liability', 'Liabilities', 'core.class.liability'],
    3 => ['equity', 'Equity', 'core.class.equity'],
    4 => ['income', 'Revenue', 'core.class.income'],
    5 => ['expense', 'Expenses', 'core.class.expense'],
];

/** A structured code, and a structured code that is a posting account rather than a heading. */
$structured = "a.code LIKE '_-___-_____-__'";

$statements = [];

// 1. The five class headings. `ESCAPE '!'` is not needed: the patterns carry no
//    underscore that is meant literally, and every `_` below is the intended
//    single-character wildcard of the X-XXX-XXXXX-XX shape.
foreach ($classes as $digit => [$type, $name, $key]) {
    $code = $digit . '-000-00000-00';
    // Both NOT EXISTS clauses are needed. The code one keeps the statement
    // idempotent and skips a book that already carries the heading; the key one
    // is what `uq_account_semantic (book_id, semantic_key)` would otherwise
    // refuse, and it also declines to take a key an owner has already used.
    $statements[] = <<<SQL
INSERT INTO pl_accounts (company_id, book_id, code, name, type, role, semantic_key, is_active, is_contra, creation_key)
SELECT b.company_id, b.id, '{$code}', '{$name}', '{$type}', NULL, '{$key}', 1, 0, 'migration-045-{$code}'
FROM pl_books b
WHERE EXISTS (SELECT 1 FROM pl_accounts a WHERE a.book_id = b.id AND a.type = '{$type}' AND {$structured})
  AND NOT EXISTS (SELECT 1 FROM pl_accounts h WHERE h.book_id = b.id AND h.code = '{$code}')
  AND NOT EXISTS (SELECT 1 FROM pl_accounts s WHERE s.book_id = b.id AND s.semantic_key = '{$key}')
SQL;
}

// 2. One group heading per starter purpose the book actually holds. The code is
//    built from the keyed account's own code, so it names the group that
//    account is in rather than a group number assumed in advance: after 036 the
//    numbers were allocated from the chart's own groups and differ book by book.
$keyList = "'" . implode("','", array_keys($groups)) . "'";
foreach ($groups as $key => [$name, $headingKey]) {
    $escaped = str_replace("'", "''", $name);
    $statements[] = <<<SQL
INSERT INTO pl_accounts (company_id, book_id, code, name, type, role, semantic_key, is_active, is_contra, creation_key)
SELECT b.company_id, b.id, CONCAT(LEFT(k.code, 5), '-00000-00'), '{$escaped}', k.type, NULL, '{$headingKey}', 1, 0,
    CONCAT('migration-045-', LEFT(k.code, 5), '-00000-00')
FROM pl_books b
JOIN pl_accounts k ON k.book_id = b.id AND k.semantic_key = '{$key}' AND k.code LIKE '_-___-_____-__'
    AND k.code NOT LIKE '_-___-00000-__'
WHERE NOT EXISTS (
        SELECT 1 FROM pl_accounts h WHERE h.book_id = b.id AND h.code = CONCAT(LEFT(k.code, 5), '-00000-00')
    )
  AND NOT EXISTS (
        SELECT 1 FROM pl_accounts s WHERE s.book_id = b.id AND s.semantic_key = '{$headingKey}'
    )
  AND NOT EXISTS (
        SELECT 1 FROM pl_accounts o
        WHERE o.book_id = b.id AND o.id <> k.id AND LEFT(o.code, 5) = LEFT(k.code, 5)
          AND o.semantic_key IN ({$keyList})
    )
SQL;
}

// 3. Property, Plant and Equipment, and only where its number provably means
//    that. The fixed-asset register needs this group and finds it by
//    `core.group.asset.ppe`, so leaving every existing book without it would
//    leave that module with nothing to attach an asset to.
//
//    A book that was never converted has no rows in `pl_account_code_map` —
//    that table is written only by 036's conversion — so its numbering is the
//    bundled chart's own, and `core.cash_bank` sitting at `1-100-10001-00`
//    proves it: 1-200 is free in that chart and means exactly this. A converted
//    book is left alone, because 036 allocated its groups 100, 110, 120 … from
//    the chart's own groups and nothing here can say what an unused number in
//    that book would mean. Those books get no PPE group and
//    `pl_account_heading_by_key()` answers null for them, which is the module's
//    cue to ask their owner to name one rather than to stop working.
$statements[] = <<<'SQL'
INSERT INTO pl_accounts (company_id, book_id, code, name, type, role, semantic_key, is_active, is_contra, creation_key)
SELECT b.company_id, b.id, '1-200-00000-00', 'Property, Plant and Equipment', 'asset', NULL,
    'core.group.asset.ppe', 1, 0, 'migration-045-1-200-00000-00'
FROM pl_books b
JOIN pl_accounts c ON c.book_id = b.id AND c.semantic_key = 'core.cash_bank' AND c.code = '1-100-10001-00'
WHERE NOT EXISTS (SELECT 1 FROM pl_account_code_map m WHERE m.book_id = b.id)
  AND NOT EXISTS (SELECT 1 FROM pl_accounts h WHERE h.book_id = b.id AND h.code = '1-200-00000-00')
  AND NOT EXISTS (SELECT 1 FROM pl_accounts s WHERE s.book_id = b.id AND s.semantic_key = 'core.group.asset.ppe')
  AND NOT EXISTS (SELECT 1 FROM pl_accounts g WHERE g.book_id = b.id AND g.code LIKE '1-200-_____-__')
SQL;

return $statements;
