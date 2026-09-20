<?php
declare(strict_types=1);

/**
 * Structured account codes, `X-XXX-XXXXX-XX` (owner decision B56, issue #76).
 *
 * The four segments are class, group, account and sub-account. The code is the
 * hierarchy: a code's ancestors are obtained by zeroing the trailing segments,
 * so no parent identifier is stored on the row and a parent can never disagree
 * with the code printed on a report.
 *
 *   3-000-00000-00  class header   (Equity)
 *   3-100-00000-00  group header   (Owner's capital)
 *   3-100-10001-00  account        (Capital — Mansoor)
 *   3-100-10001-01  sub-account    (Capital introduced 2026)
 *
 * Only a leaf receives postings; every ancestor aggregates its descendants.
 * These are small typed functions with no database, request or HTML access.
 */

/** Class digit for each root accounting type. */
function pl_account_code_classes(): array
{
    return ['asset' => 1, 'liability' => 2, 'equity' => 3, 'income' => 4, 'expense' => 5];
}

function pl_account_code_class_for_type(string $type): int
{
    $classes = pl_account_code_classes();
    if (!isset($classes[$type])) {
        throw new DomainException('Choose an account classification.');
    }
    return $classes[$type];
}

function pl_account_code_type_for_class(int $class): string
{
    $type = array_search($class, pl_account_code_classes(), true);
    if ($type === false) {
        throw new DomainException('An account code carries an unsupported class digit.');
    }
    return $type;
}

function pl_account_code_class_label(int $class): string
{
    return [1 => 'Assets', 2 => 'Liabilities', 3 => 'Equity', 4 => 'Revenue', 5 => 'Expenses'][$class]
        ?? throw new DomainException('An account code carries an unsupported class digit.');
}

/**
 * Group numbers 900 to 999 of every class are reserved for contra accounts
 * (B60). The conversion migration never allocates them, so a chart converted
 * from legacy numbers always has room for these groups afterwards.
 *
 * @return array<int, array<int, string>> class digit => group number => purpose
 */
function pl_account_contra_groups(): array
{
    return [
        1 => [900 => 'Accumulated depreciation', 910 => 'Provisions against assets'],
        2 => [],
        3 => [900 => 'Drawings'],
        4 => [900 => 'Sales returns and allowances', 910 => 'Discounts allowed'],
        5 => [900 => 'Purchase returns and allowances', 910 => 'Discounts received'],
    ];
}

/** The first group number a normal (non-contra) chart group may use. */
function pl_account_code_first_group(): int
{
    return 100;
}

/** The last group number the conversion may allocate before the contra band. */
function pl_account_code_last_group(): int
{
    return 899;
}

function pl_account_code_group_is_reserved(int $class, int $group): bool
{
    return $group > pl_account_code_last_group();
}

/**
 * Parse a structured code into its four integer segments.
 *
 * @return array{code:string, class:int, group:int, account:int, sub:int}
 */
function pl_account_code_parse(string $code): array
{
    if (!preg_match('/^([1-9])-([0-9]{3})-([0-9]{5})-([0-9]{2})$/D', $code, $match)) {
        throw new DomainException('Use an account code in the form X-XXX-XXXXX-XX, for example 1-100-10001-00.');
    }
    $class = (int) $match[1];
    $group = (int) $match[2];
    $account = (int) $match[3];
    $sub = (int) $match[4];
    pl_account_code_type_for_class($class);
    if ($group === 0 && ($account !== 0 || $sub !== 0)) {
        throw new DomainException('An account code cannot name an account inside group 000; group 000 is the class heading itself.');
    }
    if ($account === 0 && $sub !== 0) {
        throw new DomainException('A sub-account needs an account segment; 00000 is the group heading itself.');
    }
    return ['code' => $code, 'class' => $class, 'group' => $group, 'account' => $account, 'sub' => $sub];
}

function pl_account_code_is_valid(string $code): bool
{
    try {
        pl_account_code_parse($code);
        return true;
    } catch (DomainException) {
        return false;
    }
}

function pl_account_code_format(int $class, int $group, int $account, int $sub = 0): string
{
    if ($class < 1 || $class > 9 || $group < 0 || $group > 999 || $account < 0 || $account > 99999 || $sub < 0 || $sub > 99) {
        throw new DomainException('An account code segment is outside the range the X-XXX-XXXXX-XX shape allows.');
    }
    $code = $class . '-' . str_pad((string) $group, 3, '0', STR_PAD_LEFT)
        . '-' . str_pad((string) $account, 5, '0', STR_PAD_LEFT)
        . '-' . str_pad((string) $sub, 2, '0', STR_PAD_LEFT);
    pl_account_code_parse($code);
    return $code;
}

/** 'class', 'group', 'account' or 'sub_account'. */
function pl_account_code_level(string $code): string
{
    $parts = pl_account_code_parse($code);
    if ($parts['group'] === 0) { return 'class'; }
    if ($parts['account'] === 0) { return 'group'; }
    return $parts['sub'] === 0 ? 'account' : 'sub_account';
}

function pl_account_code_depth(string $code): int
{
    return ['class' => 0, 'group' => 1, 'account' => 2, 'sub_account' => 3][pl_account_code_level($code)];
}

/** A heading aggregates other accounts and never receives a posting itself. */
function pl_account_code_is_heading(string $code): bool
{
    return in_array(pl_account_code_level($code), ['class', 'group'], true);
}

function pl_account_code_parent(string $code): ?string
{
    $parts = pl_account_code_parse($code);
    return match (pl_account_code_level($code)) {
        'class' => null,
        'group' => pl_account_code_format($parts['class'], 0, 0, 0),
        'account' => pl_account_code_format($parts['class'], $parts['group'], 0, 0),
        default => pl_account_code_format($parts['class'], $parts['group'], $parts['account'], 0),
    };
}

/** Every ancestor from the class heading down to the immediate parent. */
function pl_account_code_ancestors(string $code): array
{
    $ancestors = [];
    for ($parent = pl_account_code_parent($code); $parent !== null; $parent = pl_account_code_parent($parent)) {
        $ancestors[] = $parent;
    }
    return array_reverse($ancestors);
}

/** The code of the level a row belongs to, used as the tree key at that depth. */
function pl_account_code_at_depth(string $code, int $depth): string
{
    $parts = pl_account_code_parse($code);
    return match ($depth) {
        0 => pl_account_code_format($parts['class'], 0, 0, 0),
        1 => pl_account_code_format($parts['class'], $parts['group'], 0, 0),
        2 => pl_account_code_format($parts['class'], $parts['group'], $parts['account'], 0),
        default => $code,
    };
}

/** The label shown beside a heading code, for example "1-100". */
function pl_account_code_short(string $code): string
{
    $parts = pl_account_code_parse($code);
    return match (pl_account_code_level($code)) {
        'class' => (string) $parts['class'],
        'group' => $parts['class'] . '-' . str_pad((string) $parts['group'], 3, '0', STR_PAD_LEFT),
        default => $code,
    };
}

/**
 * The legacy chart's group of a code, used by the conversion: the first two
 * characters of the old number ("10" of "1000"), which is what an existing
 * chart actually uses as its group (B56).
 */
function pl_account_legacy_group_key(string $legacyCode): string
{
    return strtoupper(substr($legacyCode, 0, 2));
}

/**
 * Map a chart's accounts by code, with each account's pre-conversion number
 * kept as an alias. Demo packs, sample data and fixtures written against the
 * old numbers keep resolving after the conversion (B56) without either number
 * being guessed at the point of use.
 *
 * @param array<int,array<string,mixed>> $accounts rows with id, code and legacy_code
 * @return array<string,int>
 */
function pl_account_code_mapping(array $accounts): array
{
    $mapping = [];
    foreach ($accounts as $row) {
        $mapping[(string) $row['code']] = (int) $row['id'];
    }
    foreach ($accounts as $row) {
        $legacy = (string) ($row['legacy_code'] ?? '');
        if ($legacy !== '' && !isset($mapping[$legacy])) {
            $mapping[$legacy] = (int) $row['id'];
        }
    }
    return $mapping;
}

/** Is this code one the conversion could have produced for this type? */
function pl_account_code_matches_type(string $code, string $type): bool
{
    return pl_account_code_parse($code)['class'] === pl_account_code_class_for_type($type);
}
