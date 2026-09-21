<?php
declare(strict_types=1);

/**
 * The collapsible class -> group -> account report body (issue #77).
 *
 * Built on native <details>/<summary>, so it expands and collapses with the
 * keyboard and on a phone with no JavaScript at all, and a collapsed branch is
 * genuinely absent from the page — which is also why the print view respects
 * whatever the reader has expanded: the browser prints what is open.
 *
 * Presentation only. Every figure comes from the report service; nothing here
 * adds, nets or re-signs an amount.
 */

/** The classes the owner is most likely to read first open by default (frame decision 19). */
function pl_ui_report_open_by_default(string $code): bool
{
    return in_array(substr($code, 0, 1), ['1', '5'], true);
}

/**
 * @param array<int,array<string,mixed>> $nodes tree from pl_report_tree()
 * @param array<int,array{key:string,label:string}> $columns money columns, in order
 * @param array{caption:string, link?:callable, empty?:string} $options
 */
function pl_ui_report_tree(array $nodes, array $columns, array $options): void
{
    echo '<div class="report-tree" role="group" aria-label="' . pl_e($options['caption']) . '">';
    echo '<div class="report-tree-head"><span>Code</span><span>Account</span>';
    foreach ($columns as $column) { echo '<span class="num">' . pl_e($column['label']) . '</span>'; }
    echo '</div>';
    if ($nodes === []) {
        echo '<p class="muted small">' . pl_e($options['empty'] ?? 'No accounts carry a balance in this report.') . '</p>';
    }
    foreach ($nodes as $node) { pl_ui_report_tree_node($node, $columns, $options); }
    echo '</div>';
}

function pl_ui_report_tree_node(array $node, array $columns, array $options): void
{
    $children = $node['children'] ?? [];
    $level = (string) ($node['level'] ?? 'account');
    if ($children === []) {
        pl_ui_report_tree_row($node, $columns, $options);
        return;
    }
    $open = $level !== 'class' || pl_ui_report_open_by_default((string) $node['code']);
    echo '<details class="report-tree-branch report-tree-' . pl_e($level) . '"' . ($open ? ' open' : '') . '>';
    echo '<summary><span class="report-tree-code"><span class="report-tree-chevron" aria-hidden="true">&#9656;</span>'
        . pl_e((string) ($node['short_code'] ?? $node['code'])) . '</span>'
        . '<span class="report-tree-name">' . pl_e((string) ($node['label'] ?? $node['name'] ?? '')) . '</span>';
    foreach ($columns as $column) {
        echo '<span class="num">' . pl_e(pl_money((string) ($node[$column['key']] ?? '0.0000'))) . '</span>';
    }
    echo '</summary>';
    pl_ui_report_tree_note($node);
    foreach ($children as $child) { pl_ui_report_tree_node($child, $columns, $options); }
    echo '</details>';
}

/**
 * The plain-words explanation of one class or group, under the heading it explains.
 *
 * A heading now carries a name — "Cash and Cash Equivalents", not "Group 1-100" — and this is the
 * line that says what that name is supposed to hold, for a reader who has never done bookkeeping.
 * It is skipped silently for a heading the catalogue has no words for, so a group an owner added
 * reads exactly as it did before.
 */
function pl_ui_report_tree_note(array $node): void
{
    $code = (string) ($node['code'] ?? '');
    $concept = pl_account_heading_concept($code);
    if ($concept === null || pl_guidance_concept($concept) === null) { return; }
    echo '<div class="report-tree-note"><span>'
        . pl_e(pl_t('What belongs in {group}', ['group' => (string) ($node['label'] ?? $node['name'] ?? '')]))
        . '</span>';
    pl_ui_help($concept);
    echo '</div>';
}

function pl_ui_report_tree_row(array $node, array $columns, array $options): void
{
    $isHeading = (bool) ($node['is_heading'] ?? false);
    $contra = (bool) ($node['is_contra'] ?? false);
    echo '<div class="report-tree-row' . ($isHeading ? ' report-tree-row-empty' : '') . ($contra ? ' report-tree-row-contra' : '') . '">';
    echo '<span class="report-tree-code">' . pl_e((string) ($node['short_code'] ?? $node['code'])) . '</span>';
    echo '<span class="report-tree-name">';
    $label = (string) ($node['label'] ?? $node['name'] ?? '');
    // A contra account is a deduction inside its own section, never a member of the opposite one.
    if ($contra) { echo '<span class="report-tree-contra-tag">Less:</span> '; }
    if (!$isHeading && isset($options['link']) && isset($node['id'])) {
        echo '<a class="link" href="' . pl_e($options['link']((int) $node['id'])) . '">' . pl_e($label) . '</a>';
    } else {
        echo pl_e($label);
    }
    echo '</span>';
    foreach ($columns as $column) {
        echo '<span class="num">' . pl_e(pl_money((string) ($node[$column['key']] ?? '0.0000'))) . '</span>';
    }
    echo '</div>';
    // A heading reaches this function when the depth control has cut its children off. It is still
    // the heading of a group, so it still gets the line that says what belongs in it.
    if ($isHeading) { pl_ui_report_tree_note($node); }
}
