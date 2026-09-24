<?php
declare(strict_types=1);

/** Presentation only. Callbacks render trusted templates; all text is escaped here. */
function pl_ui_page_header(string $title, string $description = '', ?callable $actions = null, string $id = ''): void
{
    echo '<header class="page-header"><div><h1 class="page-title"' . ($id !== '' ? ' id="' . pl_e($id) . '"' : '') . '>' . pl_e($title) . '</h1>';
    if ($description !== '') { echo '<p class="text-sm text-ink-muted mt-1">' . pl_e($description) . '</p>'; }
    echo '</div><div class="page-header-actions" data-fold="primary actions">';
    if ($actions !== null) { $actions(); }
    echo '</div></header>';
}

function pl_ui_badge(string $status, ?string $label = null): void
{
    $allowed = ['draft','posted','reversed','unpaid','paid','partially-paid','due-soon','overdue','info','sample'];
    $kind = match ($status) { 'active','received'=>'posted','inactive','cancelled'=>'unpaid','ordered'=>'info','partially-received'=>'partially-paid',default=>in_array($status, $allowed, true) ? $status : 'draft' };
    echo '<span class="badge badge-' . $kind . '"><span class="badge-dot" aria-hidden="true"></span>'
        . pl_e($label ?? ucfirst(str_replace('-', ' ', $status))) . '</span>';
}

function pl_ui_empty(string $title, string $description, ?callable $action = null): void
{
    echo '<div class="empty-state"><h2>' . pl_e($title) . '</h2><p>' . pl_e($description) . '</p>';
    if ($action !== null) { $action(); }
    echo '</div>';
}

function pl_ui_strip(string $message, string $kind = 'info', ?callable $action = null): void
{
    $kind = in_array($kind, ['info','warning','sample'], true) ? $kind : 'info';
    echo '<div class="strip strip-' . $kind . '"><p>' . pl_e($message) . '</p>';
    if ($action !== null) { $action(); }
    echo '</div>';
}

/**
 * @param array<string,string> $rows  label => amount
 * @param array<string,string> $keys  label => row key, for the rows a live recalculation owns
 *                                    ("net", "tax", "total") and the rows that belong to the
 *                                    last server preview and go stale the moment it is edited.
 */
function pl_ui_totals(array $rows, array $keys = []): void
{
    echo '<dl class="doc-totals" data-fold="totals">';
    foreach ($rows as $label => $amount) {
        $key = $keys[$label] ?? '';
        echo '<div class="doc-totals-row"' . ($key === '' ? '' : ' data-total="' . pl_e($key) . '"') . '><dt>' . pl_e((string)$label) . '</dt><dd class="amount">' . pl_e((string)$amount) . '</dd></div>';
    }
    echo '</dl>';
}

function pl_ui_table(array $headings, callable $rows, string $caption): void
{
    echo '<div class="table-wrap" tabindex="0" role="region" aria-label="' . pl_e($caption) . '"><table class="table"><caption class="sr-only">' . pl_e($caption) . '</caption><thead><tr>';
    foreach ($headings as $heading) { echo '<th scope="col">' . pl_e((string)$heading) . '</th>'; }
    echo '</tr></thead><tbody>'; $rows(); echo '</tbody></table></div>';
}

function pl_ui_required_marker(): string
{
    return ' <span class="field-required" aria-hidden="true">*</span><span class="sr-only"> ' . pl_e(pl_t('Required')) . '</span>';
}

function pl_ui_required_legend(): void
{
    echo '<p class="field-hint"><span class="field-required" aria-hidden="true">*</span> ' . pl_e(pl_t('Required fields')) . '</p>';
}

function pl_ui_field(string $id, string $label, callable $control, string $hint = '', string $error = '', bool $required = false): void
{
    echo '<div class="field' . ($error !== '' ? ' field-error' : '') . '"><label class="field-label" for="' . pl_e($id) . '">' . pl_e($label) . ($required ? pl_ui_required_marker() : '') . '</label>';
    $control();
    if ($hint !== '') { echo '<p class="field-hint" id="' . pl_e($id) . '-hint">' . pl_e($hint) . '</p>'; }
    if ($error !== '') { echo '<p class="field-error-text" id="' . pl_e($id) . '-error">' . pl_e($error) . '</p>'; }
    echo '</div>';
}

function pl_ui_error_attributes(string $id, string $error = '', string $hint = ''): string
{
    $describedBy = array_filter([$hint !== '' ? $id.'-hint' : '', $error !== '' ? $id.'-error' : '']);
    return ($error !== '' ? ' aria-invalid="true"' : '')
        . ($describedBy !== [] ? ' aria-describedby="'.pl_e(implode(' ', $describedBy)).'"' : '');
}

/** Links are fixed control IDs declared by the current template. */
function pl_ui_error_summary(array $errors, array $fields): void
{
    if ($errors === []) { return; }
    echo '<div class="alert alert-danger" role="alert" tabindex="-1" data-form-error data-field-errors><strong>Check the highlighted fields.</strong><ul>';
    foreach ($errors as $name=>$message) {
        if (!isset($fields[$name])) { continue; }
        [$id,$label] = $fields[$name];
        echo '<li><a href="#'.pl_e($id).'">'.pl_e($label).': '.pl_e($message).'</a></li>';
    }
    echo '</ul></div>';
}

function pl_ui_account_return(?array $input = null): void
{
    $input ??= ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? $_POST : $_GET;
    foreach (pl_web_account_return($input) as $key=>$value) {
        echo '<input type="hidden" name="return_account['.pl_e((string)$key).']" value="'.pl_e((string)$value).'">';
    }
    foreach (pl_web_ageing_return($input) as $key=>$value) {
        echo '<input type="hidden" name="return_ageing['.pl_e((string)$key).']" value="'.pl_e((string)$value).'">';
    }
}

function pl_ui_stepper(array $steps, int $current): void
{
    echo '<ol class="stepper" aria-label="Progress">';
    foreach (array_values($steps) as $index => $label) {
        $number = $index + 1;
        echo '<li class="stepper-step' . ($number === $current ? ' is-current' : ($number < $current ? ' is-done' : '')) . '"'
            . ($number === $current ? ' aria-current="step"' : '') . '><span class="stepper-index">' . $number . '</span><span class="stepper-label">' . pl_e((string)$label) . '</span></li>';
    }
    echo '</ol>';
}

/** Native links keep all sections available without JavaScript. */
function pl_ui_tabs(array $links, string $current, bool $enhance = false): void
{
    echo '<nav class="tabs-underline" aria-label="Sections"' . ($enhance ? ' data-ui-tabs' : '') . '>';
    foreach ($links as $label => $href) {
        echo '<a class="tabs-underline-item" href="' . pl_e((string)$href) . '"' . ((string)$label === $current ? ' aria-current="page"' : '') . '>' . pl_e((string)$label) . '</a>';
    }
    echo '</nav>';
}

function pl_ui_document_header(string $title, string $status, callable $actions, string $id = ''): void
{
    echo '<header class="doc-header"><div class="doc-heading"><h1 class="doc-number"' . ($id !== '' ? ' id="' . pl_e($id) . '"' : '') . '>' . pl_e($title) . '</h1>';
    if ($status!=='') { pl_ui_badge($status); }
    echo '</div><div class="doc-actions" data-fold="primary actions">'; $actions(); echo '</div></header>';
}

function pl_ui_side_panel(string $title, callable $body): void
{
    echo '<section class="split-view-detail" aria-label="' . pl_e($title) . '">'; $body(); echo '</section>';
}

/** The confirmation is server-rendered; JavaScript may enhance its native details. */
function pl_ui_confirmation(string $title, string $consequence, callable $form): void
{
    echo '<details class="confirmation" data-confirmation><summary class="btn btn-secondary">' . pl_e($title) . '</summary><div data-confirmation-body><h2 class="section-title">' . pl_e($title) . '?</h2><p class="field-hint">' . pl_e($consequence) . '</p><div class="flex flex-wrap gap-2 mt-3">';
    $form(); echo '<button type="button" class="btn btn-ghost" data-confirmation-cancel hidden>Cancel</button></div></div></details>';
}

/** Native details becomes an accessible modal sheet when JS is available. */
function pl_ui_sheet(string $title,callable $body,bool $open=false,string $button='btn btn-secondary'): void
{
    echo '<details data-editor-sheet'.($open?' open':'').'><summary class="'.pl_e($button).'">'.pl_e($title).'</summary><div data-confirmation-body><div class="flex justify-between items-center gap-3 mb-4"><h2 class="section-title">'.pl_e($title).'</h2><button type="button" class="btn btn-ghost" data-confirmation-cancel hidden>Close</button></div>';
    $body(); echo '</div></details>';
}

/**
 * The in-app accounting guidance bubble (owner decisions B66 and B67).
 *
 * A question mark beside a label, a column heading or a total. Activating it opens a small bubble
 * with the shared explanation of that concept, the line tying it to what this screen does, and —
 * where the company's jurisdiction has a verified note — the local practice, labelled with the
 * country so nobody reads the shared text as local law.
 *
 * Native <details>/<summary>, so it works with no JavaScript, is reachable and operable from the
 * keyboard, announces its expanded state, and traps nothing. app.js adds Escape, click outside and
 * closing the others; none of that is required for the bubble to be readable.
 *
 * $placement picks the edge the bubble hangs from, because the CSP forbids an inline style and
 * therefore forbids positioning it from JavaScript: 'start' (default), 'end' for a trigger near
 * the inline-end edge of the screen, and 'sheet' for a trigger inside a scrolling region such as
 * .table-wrap, whose overflow would otherwise clip it.
 *
 * <details> is flow content: place the call beside a heading, a cell or a label, never inside a
 * <p>, a <label> or an <h1>-<h6>, which accept phrasing content only.
 */
function pl_ui_help(string $concept, string $placement = 'start'): void
{
    $entry = pl_guidance_entry($concept);
    $placement = in_array($placement, ['start', 'end', 'sheet'], true) ? $placement : 'start';
    $title = pl_t($entry['title']);
    echo '<details class="help" data-help><summary><span aria-hidden="true">?</span><span class="sr-only">'
        . pl_e(pl_t('Explain {concept}', ['concept' => $title])) . '</span></summary>'
        . '<div class="help-bubble help-bubble-' . $placement . '" role="note">'
        . '<p class="help-title">' . pl_e($title) . '</p>'
        . '<p class="help-text">' . pl_e(pl_t($entry['explanation'])) . '</p>';
    if ($entry['here'] !== '') {
        echo '<p class="help-text help-here">' . pl_e(pl_t($entry['here'])) . '</p>';
    }
    if ($entry['note'] !== null) {
        echo '<div class="help-local"><p class="help-local-label">'
            . pl_e(pl_t('In {country}', ['country' => $entry['note']['country_name']])) . '</p>'
            . '<p class="help-text">' . pl_e(pl_t($entry['note']['note'])) . '</p>'
            . '<p class="help-source">' . pl_e(pl_t('Source: {source}. Checked {date}.',
                ['source' => $entry['note']['source'], 'date' => pl_date_label($entry['note']['checked_on'])])) . '</p></div>';
    }
    if ($entry['document'] !== null) {
        // An external article opens in its own tab and is cut off from this one: `noopener` denies
        // it `window.opener`, and `noreferrer` keeps the reader's installation URL — which is often
        // a private host name — out of the request. `pl_guidance_concept()` has already held the
        // destination to https://phpledger.com/learn/<slug>, so this is presentation, not the gate.
        $external = $entry['document']['external'];
        echo '<a class="link" href="' . pl_e($external ? $entry['document']['href'] : pl_url($entry['document']['href'])) . '"'
            . ($external ? ' target="_blank" rel="noopener noreferrer"' : '') . '>'
            . pl_e(pl_t($entry['document']['label']))
            . ($external ? '<span class="sr-only"> ' . pl_e(pl_t('(opens phpledger.com in a new tab)')) . '</span>' : '')
            . '</a>';
    }
    if ($entry['review'] !== 'reviewed') {
        echo '<p class="help-draft">' . pl_e(pl_t('Draft wording, pending the accounting guidance review.')) . '</p>';
    }
    echo '</div></details>';
}

/**
 * The question mark beside a class or group heading, when the catalogue has words for it.
 *
 * The concept id is derived from the code (pl_account_heading_concept), so a screen that holds an
 * account row can ask for the explanation of the heading on it without knowing which chart package
 * the book was created from. A book may carry a heading nobody has written an explanation for —
 * one its owner added, or one from a chart package newer than this catalogue — and that heading
 * simply has no question mark: pl_ui_help() would throw for it, which is the right behaviour for a
 * screen that names a concept literally and the wrong one for a name read out of a book.
 */
function pl_ui_heading_help(string $code, string $placement = 'start'): void
{
    $concept = pl_account_heading_concept($code);
    if ($concept === null || pl_guidance_concept($concept) === null) { return; }
    pl_ui_help($concept, $placement);
}

function pl_ui_pagination(string $path, array $filters, int $page, int $pages): void
{
    $url=static fn(array $query):string=>in_array($path,['/transactions','/general-journals','/ar','/ap','/purchasing'],true)?pl_workflow_url($path,$query):pl_url($path,$query);
    echo '<nav class="pagination" aria-label="List pages">';
    if ($page > 1) { echo '<a class="btn btn-secondary btn-sm" href="' . pl_e($url(array_replace($filters, ['page' => $page - 1]))) . '">Previous</a>'; }
    echo '<span>Page ' . $page . ' of ' . max(1, $pages) . '</span>';
    if ($page < $pages) { echo '<a class="btn btn-secondary btn-sm" href="' . pl_e($url(array_replace($filters, ['page' => $page + 1]))) . '">Next</a>'; }
    echo '</nav>';
}

function pl_ui_connection_scope(string $id, string $value = 'reports'): void
{
    pl_ui_field($id, 'Access scope', static function () use ($id, $value): void {
        echo '<select class="select" id="' . pl_e($id) . '" name="access_mode" required><option value="reports">Report-only — summary financial reports</option><option value="full"' . ($value === 'full' ? ' selected' : '') . '>Full read — reports and individual records</option></select>';
    });
    echo '<p class="field-hint">Report-only includes company discovery, trial balance, profit and loss, and balance sheet. Full read also includes accounts, transactions, journals and account statements. Neither permits financial writes.</p>';
}

/** Canonical list filters, never a user-controlled redirect URL. */
function pl_ui_return_filters(array $filters): void
{
    pl_ui_account_return();
    foreach ($filters as $key=>$value) {
        echo '<input type="hidden" name="return_filters[' . pl_e((string)$key) . ']" value="' . pl_e((string)$value) . '">';
    }
}

function pl_ui_sort(string $path, array $filters, string $column, string $label): void
{
    $active = ($filters['sort'] ?? '') === $column;
    $direction = $active && ($filters['dir'] ?? '') === 'asc' ? 'desc' : 'asc';
    $query=array_replace($filters, ['sort' => $column, 'dir' => $direction, 'page' => 1]);
    $url=in_array($path,['/transactions','/general-journals','/ar','/ap','/purchasing'],true)?pl_workflow_url($path,$query):pl_url($path,$query);
    echo '<a href="' . pl_e($url) . '">' . pl_e($label)
        . ($active ? (($filters['dir'] ?? '') === 'asc' ? ' ↑' : ' ↓') : '') . '</a>';
}

function pl_ui_list_controls(array $filters): void
{
    pl_ui_account_return();
    echo '<label class="field"><span class="sr-only">Search records</span><input class="input" type="search" name="q" maxlength="160" value="' . pl_e($filters['q']) . '" placeholder="Search records"></label>';
    echo '<label class="field"><span class="sr-only">Rows per page</span><select class="select" name="per_page">';
    foreach ([25,50,100] as $size) { echo '<option value="' . $size . '"' . ($size === $filters['per_page'] ? ' selected' : '') . '>' . $size . ' per page</option>'; }
    echo '</select></label><input type="hidden" name="sort" value="' . pl_e($filters['sort']) . '"><input type="hidden" name="dir" value="' . pl_e($filters['dir']) . '">';
}

require_once __DIR__ . '/report-tree.php';

/** One compact page control, also available before a company is selected. */
function pl_ui_page_help(string $view): void
{
    if (!function_exists('pl_guidance_page')) { require_once dirname(__DIR__, 3) . '/includes/functions/guidance_functions.php'; }
    echo '<div class="page-help" data-page-help="' . pl_e($view) . '">';
    pl_ui_help(pl_guidance_page($view), 'sheet');
    echo '</div>';
}
