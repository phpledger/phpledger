<?php
declare(strict_types=1);
$visibility = pl_company_visibility((int)$user['id'], (int)$company['id']);
$moduleVisible = static fn (string $id): bool => pl_module_available((int)$user['id'], (int)$company['id'], (int)$company['book_id'], $id);
$navGroups = [
    'Daily work' => [
        ['/transactions', 'Receipts & expenses', 'receipt', ['transactions','editor'], true],
        ['/pos', 'Point of sale', 'receipt', ['pos'], $moduleVisible('pos-showcase')],
        ['/general-journals', 'Journals', 'book', ['general-journals','general-editor','general-detail'], true],
    ],
    'Sales' => [
        ['/ar', 'Invoices', 'file-text', ['ar'], $visibility['show_ar']],
        ['/parties?role=customer', 'Customers', 'building', ['parties'], $visibility['show_ar']],
    ],
    'Purchases' => [
        ['/ap', 'Bills', 'file-text', ['ap'], $visibility['show_ap']],
        ['/purchasing', 'Purchase orders', 'list', ['purchasing','goods-receipt'], $moduleVisible('purchasing')],
        ['/parties?role=vendor', 'Suppliers', 'building', [], $visibility['show_ap']],
    ],
    'Inventory' => [
        ['/inventory', 'Products & stock', 'list', ['inventory','stock-count'], $moduleVisible('inventory')],
        ['/stock-documents', 'Stock issues & returns', 'file-text', ['stock-documents','stock-document'], $moduleVisible('inventory-locations')],
        ['/stock-documents/settlement', 'Van settlement', 'calendar', ['van-settlement'], $moduleVisible('inventory-locations')],
        ['/reports/stock-by-location', 'Stock by location', 'book', ['stock-by-location'], $moduleVisible('inventory')],
    ],
    'Banking' => [['/bank-reconciliation', 'Bank reconciliation', 'building', ['bank-reconciliation'], !pl_demo_enabled()]],
    'Reports' => [
        ['/reports', 'All reports', 'book', ['reports'], true],
        ['/reports/profit-loss', 'Profit & loss', 'file-text', ['profit-loss'], true],
        ['/reports/ageing', 'Receivables & payables ageing', 'calendar', ['ageing'], true],
        ['/reports/balance-sheet', 'Balance sheet', 'file-text', ['balance-sheet'], true],
        ['/reports/trial-balance', 'Trial balance', 'list', ['trial-balance'], true],
        ['/reports/account', 'Account statement', 'file-text', ['account'], true],
        ['/reports/cash-forecast', 'Cash forecast', 'file-text', ['cash-forecast'], true],
    ],
    'Setup' => [
        ['/accounts', 'Chart of accounts', 'list', ['accounts'], true],
        ['/owner', 'Owner and partners', 'building', ['owner'], true],
        ['/tax', 'Tax codes', 'receipt', ['tax'], true],
        ['/opening-balances', 'Opening balances', 'book', ['opening-balances'], !pl_demo_enabled()],
        ['/opening-conversion', 'Opening documents', 'file-text', ['opening-conversion'], !pl_demo_enabled()],
        ['/periods', 'Periods', 'book', ['periods'], !pl_demo_enabled()],
        ['/numbering', 'Document numbering', 'list', ['numbering'], !pl_demo_enabled()],
        ['/accounting-policies', pl_t('Accounting policies'), 'adjustments-horizontal', ['accounting-policies'], !pl_demo_enabled()],
        ['/company-profile', pl_t('Company profile'), 'building', ['company-profile'], !pl_demo_enabled()],
        ['/modules', 'Modules', 'adjustments-horizontal', ['modules'], !pl_demo_enabled()],
        ['/connections', 'Connections & API', 'external-link', ['connections'], true],
    ],
];
$quickCreate = [
    ['/transactions/new?kind=expense', 'Expense', true],
    ['/transactions/new?kind=receipt', 'Receipt', true],
    ['/ar?new=1', 'Invoice', $visibility['show_ar']],
    ['/ap?new=1', 'Bill', $visibility['show_ap']],
    ['/general-journals/new', 'Journal entry', true],
    ['/purchasing?new=1', 'Purchase order', $moduleVisible('purchasing')],
    ['/parties?new=1', 'Customer or supplier', true],
    ['/inventory?new=1', 'Product', $moduleVisible('inventory')],
];
?>
<div data-shell>
<aside class="shell-sidebar" id="workspace-navigation" data-sidebar aria-label="Main navigation">
    <?php $customLogo = function_exists('pl_logo_current') ? pl_logo_current() : null; ?>
    <div class="shell-brand"><a class="shell-brand-link" href="<?= pl_e(pl_url('/companies')) ?>" aria-label="PHP Ledger businesses"><?php if ($customLogo !== null): ?><img class="shell-logo" src="<?= pl_e(pl_logo_url($customLogo)) ?>" alt="Business logo" width="<?= $customLogo['width'] ?>" height="<?= $customLogo['height'] ?>"><?php else: ?><img class="shell-logo" src="<?= pl_e(pl_url('/assets/brand/phpledger-horizontal.png')) ?>" alt="PHP Ledger" width="2172" height="724"><?php endif; ?><span class="shell-logo-compact" aria-hidden="true">P</span></a></div>
    <details class="company-switcher">
        <summary class="company-switcher-trigger" aria-label="Current business: <?= pl_e($company['name']) ?>. Switch business">
            <span class="company-switcher-mark" aria-hidden="true"><?= pl_e(mb_strtoupper(mb_substr($company['name'], 0, 1))) ?></span>
            <span class="company-switcher-info"><strong><?= pl_e($company['name']) ?></strong><span><?= pl_e($company['book_name']) ?> · <?= pl_e($company['currency']) ?></span><?php if ($company['is_sample']): pl_ui_badge('sample', 'Sample'); endif; ?></span>
            <?= pl_icon('chevron-down') ?>
        </summary>
        <div class="menu-panel company-switcher-panel menu-panel-wide">
            <p class="menu-label"><?= pl_e(ucfirst($company['role'])) ?></p>
            <a class="menu-item" href="<?= pl_e(pl_url('/companies')) ?>">Your businesses</a>
            <?php if (!pl_demo_enabled()): ?><a class="menu-item" href="<?= pl_e(pl_url('/onboarding')) ?>">Add a business</a><?php if (pl_can_write($company)): ?><a class="menu-item" href="<?= pl_e(pl_url('/setup/review')) ?>">Business setup</a><?php endif; endif; ?>
        </div>
    </details>
    <nav class="shell-nav" aria-label="Workspace">
        <a class="nav-item" href="<?= pl_e(pl_url('/home')) ?>" title="Home"<?= $view === 'home' ? ' aria-current="page"' : '' ?>><?= pl_icon('home') ?><span>Home</span></a>
        <?php foreach ($navGroups as $group => $items): ?>
            <?php $items = array_filter($items, static fn (array $item): bool => (bool)$item[4]); if ($items === []) { continue; } ?>
            <?php if ($group === 'Setup'): ?><details class="nav-group-collapsible"<?= in_array($view, ['accounts','tax','opening-balances','opening-conversion','periods','modules','connections'], true) ? ' open' : '' ?>><summary class="nav-group-summary"><span>Setup</span><?= pl_icon('chevron-down') ?></summary><div class="nav-group-body"><?php else: ?><p class="nav-group-label"><?= pl_e($group) ?></p><?php endif; ?>
            <?php foreach ($items as [$href, $label, $icon, $views]): ?>
                <a class="nav-item" href="<?= pl_e(pl_url($href)) ?>" title="<?= pl_e($label) ?>"<?= in_array($view, $views, true) ? ' aria-current="page"' : '' ?>><?= pl_icon($icon) ?><span><?= pl_e($label) ?></span></a>
            <?php endforeach; ?>
            <?php if ($group === 'Setup'): ?></div></details><?php endif; ?>
        <?php endforeach; ?>
    </nav>
    <div class="shell-sidebar-footer"><a class="nav-item" href="<?= pl_e(pl_url('/help')) ?>" title="Help"<?= $view === 'help' ? ' aria-current="page"' : '' ?>><?= pl_icon('info-circle') ?><span>Help</span></a><p class="shell-version"><?= pl_e(pl_app_version()) ?></p></div>
</aside>
<button class="shell-overlay" data-drawer-overlay aria-label="Close navigation" type="button" tabindex="-1"></button>
<div class="shell-body">
    <header class="shell-topbar">
        <button type="button" class="btn btn-ghost btn-icon" data-sidebar-toggle aria-label="Toggle navigation" aria-controls="workspace-navigation" aria-expanded="true" hidden><?= pl_icon('menu-2') ?></button>
        <ol class="crumbs" aria-label="Breadcrumb"><li class="crumb"><a href="<?= pl_e(pl_url('/companies')) ?>">Workspace</a></li><li class="crumb"><span aria-current="page"><?= pl_e($title) ?></span></li></ol>
        <div class="topbar-search"><button type="button" class="search-trigger" data-command-open aria-haspopup="dialog" hidden><?= pl_icon('search') ?><span>Search or jump to…</span><span class="kbd ms-auto">Ctrl K</span></button></div>
        <div class="topbar-actions">
            <?php if (pl_can_write($company)): ?><details class="menu"><summary class="btn btn-primary btn-sm"><?= pl_icon('plus') ?> New</summary><div class="menu-panel menu-panel-end"><p class="menu-label">Quick create</p><?php foreach ($quickCreate as [$href, $label, $visible]): if (!$visible) { continue; } ?><a class="menu-item" href="<?= pl_e(pl_url($href)) ?>"><?= pl_e($label) ?></a><?php endforeach; ?></div></details><?php endif; ?>
            <?php if ($company['is_sample'] && pl_company_demo_pack((int)$user['id'], (int)$company['id'], (int)$company['book_id']) !== null): ?><a class="sample-guide-link max-lg:hidden" href="<?= pl_e(pl_url('/sample-guide')) ?>">Sample guide <?= pl_icon('arrow-right') ?></a><?php endif; ?>
            <details class="menu"><summary class="user-menu-trigger" aria-label="User menu"><span class="avatar"><?= pl_e(mb_strtoupper(mb_substr($user['display_name'], 0, 1))) ?></span><?= pl_icon('chevron-down') ?></summary><div class="menu-panel menu-panel-end"><p class="menu-label"><?= pl_e($user['display_name']) ?></p><?php if (!pl_demo_enabled()): ?><p class="menu-item-static"><?= pl_e($user['email']) ?></p><a class="menu-item" href="<?= pl_e(pl_url('/companies')) ?>">Switch business</a><?php endif; ?><form action="<?= pl_e(pl_url('/logout')) ?>" method="post"><?= pl_csrf_field() ?><button type="submit" class="menu-item"><?= pl_icon('logout') ?> <?= pl_demo_enabled() ? 'Leave demo' : 'Sign out' ?></button></form></div></details>
        </div>
    </header>
