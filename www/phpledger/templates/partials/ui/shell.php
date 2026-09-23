<?php
declare(strict_types=1);
$visibility = pl_company_visibility((int)$user['id'], (int)$company['id']);
$moduleVisible = static fn (string $id): bool => pl_module_available((int)$user['id'], (int)$company['id'], (int)$company['book_id'], $id);
$transactionWorkspace = $view === 'editor' ? ($input['kind'] ?? '') : ($filters['kind'] ?? $returnFilters['kind'] ?? $_GET['kind'] ?? '');
$navGroups = [
    'Daily work' => [
        ['/transactions', pl_t('Receipts & expenses'), 'receipt', ($transactionWorkspace === '' || $view === 'transaction-chooser') ? ['transactions','transaction-chooser'] : [], true],
        ['/transactions?kind=receipt', pl_t('Receipts'), 'receipt', $transactionWorkspace === 'receipt' ? ['transactions','editor'] : [], true],
        ['/transactions?kind=expense', pl_t('Expenses'), 'receipt', $transactionWorkspace === 'expense' ? ['transactions','editor'] : [], true],
        ['/counter', pl_t('Counter sale'), 'cash-register', ['counter','counter-receipt'], $visibility['show_ar'] && $moduleVisible('inventory')],
        ['/pos', pl_t('Point of sale (sample)'), 'receipt', ['pos'], $moduleVisible('pos-showcase')],
        ['/general-journals', pl_t('Journals'), 'book', ['general-journals','general-editor','general-detail'], true],
    ],
    'Sales' => [
        ['/ar', pl_t('Invoices'), 'file-text', ['ar'], $visibility['show_ar']],
        ['/parties?role=customer', pl_t('Customers'), 'building', ['parties'], $visibility['show_ar']],
    ],
    'Purchases' => [
        ['/ap', pl_t('Bills'), 'file-text', ['ap'], $visibility['show_ap']],
        ['/purchasing', pl_t('Purchase orders'), 'list', ['purchasing','goods-receipt'], $moduleVisible('purchasing')],
        ['/parties?role=vendor', pl_t('Suppliers'), 'building', [], $visibility['show_ap']],
    ],
    'Inventory' => [
        ['/inventory', pl_t('Products & stock'), 'list', ['inventory','stock-count'], $moduleVisible('inventory')],
        ['/stock-documents', pl_t('Stock issues & returns'), 'file-text', ['stock-documents','stock-document'], $moduleVisible('inventory-locations')],
        ['/stock-documents/settlement', pl_t('Van settlement'), 'calendar', ['van-settlement'], $moduleVisible('inventory-locations')],
        ['/reports/stock-by-location', pl_t('Stock by location'), 'book', ['stock-by-location'], $moduleVisible('inventory')],
    ],
    'Fixed assets' => [
        ['/fixed-assets', pl_t('Asset register'), 'list', ['assets','asset-detail'], $moduleVisible('fixed-assets')],
        ['/fixed-assets/depreciation', pl_t('Depreciation run'), 'calendar', ['asset-depreciation'], $moduleVisible('fixed-assets')],
        ['/reports/asset-register', pl_t('Fixed-asset register'), 'book', ['asset-register'], $moduleVisible('fixed-assets')],
    ],
    'Banking' => [['/bank-reconciliation', pl_t('Bank reconciliation'), 'building', ['bank-reconciliation'], !pl_demo_enabled()]],
    'Reports' => [
        ['/reports', pl_t('All reports'), 'book', ['reports'], true],
        ['/reports/profit-loss', pl_t('Profit & loss'), 'file-text', ['profit-loss'], true],
        ['/reports/ageing', pl_t('Receivables & payables ageing'), 'calendar', ['ageing'], true],
        ['/reports/balance-sheet', pl_t('Balance sheet'), 'file-text', ['balance-sheet'], true],
        ['/reports/trial-balance', pl_t('Trial balance'), 'list', ['trial-balance'], true],
        ['/reports/account', pl_t('Account statement'), 'file-text', ['account'], true],
        ['/reports/cash-forecast', pl_t('Cash forecast'), 'file-text', ['cash-forecast'], true],
    ],
    'Setup' => [
        ['/accounts', pl_t('Chart of accounts'), 'list', ['accounts'], true],
        ['/owner', pl_t('Owner and partners'), 'building', ['owner'], true],
        // 1.3 M17: the employee master (issue #98's blocker). The nav is a hint, never the
        // gate: the screen repeats its own employee.view check.
        ['/payroll', pl_t('Payroll accounting'), 'book', ['payroll'], pl_user_can((int)$user['id'], (int)$company['id'], 'payroll.view')],
        ['/employees', pl_t('Employees'), 'briefcase', ['employees'], pl_user_can((int)$user['id'], (int)$company['id'], 'employee.view')],
        ['/recurring', pl_t('Recurring documents'), 'calendar', ['scheduling'], pl_user_can((int)$user['id'], (int)$company['id'], 'schedules.view')],
        ['/schedules', pl_t('Release schedules'), 'calendar', ['scheduling'], pl_user_can((int)$user['id'], (int)$company['id'], 'schedules.view')],
        ['/loans', pl_t('Loans'), 'briefcase', ['scheduling'], pl_user_can((int)$user['id'], (int)$company['id'], 'loans.view')],
        ['/tax', pl_t('Tax codes'), 'receipt', ['tax'], true],
        ['/opening-balances', pl_t('Opening balances'), 'book', ['opening-balances'], !pl_demo_enabled()],
        ['/opening-conversion', pl_t('Opening documents'), 'file-text', ['opening-conversion'], !pl_demo_enabled()],
        ['/year-end', pl_t('Year-end close'), 'book', ['year-end'], !pl_demo_enabled() && pl_user_can((int)$user['id'], (int)$company['id'], 'cost.view')],
        ['/periods', pl_t('Periods'), 'book', ['periods'], !pl_demo_enabled()],
        ['/numbering', pl_t('Document numbering'), 'list', ['numbering'], !pl_demo_enabled()],
        ['/accounting-policies', pl_t('Accounting policies'), 'adjustments-horizontal', ['accounting-policies'], !pl_demo_enabled()],
        ['/company-profile', pl_t('Company profile'), 'building', ['company-profile'], !pl_demo_enabled()],
        ['/modules', pl_t('Modules'), 'adjustments-horizontal', ['modules'], !pl_demo_enabled()],
        // 1.2 M7. The nav is a hint, never the gate: each screen repeats its own capability check.
        ['/users', pl_t('Users'), 'users', ['users'], !pl_demo_enabled() && pl_user_can((int)$user['id'], (int)$company['id'], 'users.manage')],
        ['/roles', pl_t('Roles'), 'key', ['roles'], !pl_demo_enabled() && pl_user_can((int)$user['id'], (int)$company['id'], 'roles.manage')],
        ['/cost-visibility', pl_t('Cost visibility'), 'lock', ['cost-visibility'], !pl_demo_enabled() && pl_user_can((int)$user['id'], (int)$company['id'], 'reports.cost_settings.manage')],
        // 1.2 M8. Packages is read-only for a business owner without `installation.admin`
        // (B44, onboarding decision 10), so the item is shown and the controls are what the
        // capability gates on the screen itself.
        ['/packages', pl_t('Packages'), 'adjustments-horizontal', ['packages'], !pl_demo_enabled()],
        ['/updates', pl_t('Updates and privacy'), 'adjustments-horizontal', ['updates'], pl_user_can((int)$user['id'],0,'installation.admin')],
        ['/connections', pl_t('Connections & API'), 'external-link', ['connections'], true],
    ],
];
// 1.2 M8: an active package adds its own screens to the sidebar here. The nav is a hint, never
// a gate; every screen repeats its own capability check, so a filtered entry grants nothing.
if (function_exists('pl_plugin_navigation_groups')) {
    $navGroups = pl_plugin_navigation_groups($navGroups, ['actor_id' => (int) $user['id'], 'company_id' => (int) $company['id'], 'view' => $view]);
}
$quickCreate = [
    ['/expenses/new', pl_t('Expense'), true],
    ['/receipts/new', pl_t('Receipt'), true],
    ['/ar?new=1', pl_t('Invoice'), $visibility['show_ar']],
    ['/ap?new=1', pl_t('Bill'), $visibility['show_ap']],
    ['/general-journals/new', pl_t('Journal entry'), true],
    ['/purchasing?new=1', pl_t('Purchase order'), $moduleVisible('purchasing')],
    ['/parties?new=1', pl_t('Customer or supplier'), true],
    ['/inventory?new=1', pl_t('Product'), $moduleVisible('inventory')],
];
?>
<div data-shell>
<aside class="shell-sidebar" id="workspace-navigation" data-sidebar aria-label="<?= pl_e(pl_t('Main navigation')) ?>">
    <?php $customLogo = function_exists('pl_logo_current') ? pl_logo_current() : null; ?>
    <div class="shell-brand"><a class="shell-brand-link" href="<?= pl_e(pl_url('/companies')) ?>" aria-label="<?= pl_e(pl_t('PHP Ledger businesses')) ?>"><?php if ($customLogo !== null): ?><img class="shell-logo" src="<?= pl_e(pl_logo_url($customLogo)) ?>" alt="<?= pl_e(pl_t('Business logo')) ?>" width="<?= $customLogo['width'] ?>" height="<?= $customLogo['height'] ?>"><?php else: ?><img class="shell-logo" src="<?= pl_e(pl_url('/assets/brand/phpledger-horizontal.png')) ?>" alt="<?= pl_e(pl_t('PHP Ledger')) ?>" width="2172" height="724"><?php endif; ?><span class="shell-logo-compact" aria-hidden="true">P</span></a></div>
    <details class="company-switcher">
        <summary class="company-switcher-trigger" aria-label="<?= pl_e(pl_t('Current business: {business}. Switch business', ['business' => $company['name']])) ?>">
            <span class="company-switcher-mark" aria-hidden="true"><?= pl_e(mb_strtoupper(mb_substr($company['name'], 0, 1))) ?></span>
            <span class="company-switcher-info"><strong><?= pl_e($company['name']) ?></strong><span><?= pl_e($company['book_name']) ?> · <?= pl_e($company['currency']) ?></span><?php if ($company['is_sample']): pl_ui_badge('sample', pl_t('Sample')); endif; ?></span>
            <?= pl_icon('chevron-down') ?>
        </summary>
        <div class="menu-panel company-switcher-panel menu-panel-wide">
            <p class="menu-label"><?= pl_e(pl_t(ucfirst($company['role']))) ?></p>
            <a class="menu-item" href="<?= pl_e(pl_url('/companies')) ?>"><?= pl_e(pl_t('Your businesses')) ?></a>
            <?php if (!pl_demo_enabled()): ?><a class="menu-item" href="<?= pl_e(pl_url('/onboarding')) ?>"><?= pl_e(pl_t('Add a business')) ?></a><?php if (pl_can_write($company)): ?><a class="menu-item" href="<?= pl_e(pl_url('/setup/review')) ?>"><?= pl_e(pl_t('Business setup')) ?></a><?php endif; endif; ?>
        </div>
    </details>
    <nav class="shell-nav" aria-label="<?= pl_e(pl_t('Workspace')) ?>">
        <a class="nav-item" href="<?= pl_e(pl_url('/home')) ?>" title="<?= pl_e(pl_t('Home')) ?>"<?= $view === 'home' ? ' aria-current="page"' : '' ?>><?= pl_icon('home') ?><span><?= pl_e(pl_t('Home')) ?></span></a>
        <?php foreach ($navGroups as $group => $items): ?>
            <?php $items = array_filter($items, static fn (array $item): bool => (bool)$item[4]); if ($items === []) { continue; } ?>
            <?php if ($group === 'Setup'): ?><details class="nav-group-collapsible"<?= in_array($view, ['accounts','tax','opening-balances','opening-conversion','periods','modules','packages','connections','users','roles','cost-visibility','employees','payroll'], true) ? ' open' : '' ?>><summary class="nav-group-summary"><span><?= pl_e(pl_t('Setup')) ?></span><?= pl_icon('chevron-down') ?></summary><div class="nav-group-body"><?php else: ?><p class="nav-group-label"><?= pl_e(pl_t($group)) ?></p><?php endif; ?>
            <?php foreach ($items as [$href, $label, $icon, $views]): ?>
                <a class="nav-item" href="<?= pl_e(pl_url($href)) ?>" title="<?= pl_e($label) ?>"<?= in_array($view, $views, true) ? ' aria-current="page"' : '' ?>><?= pl_icon($icon) ?><span><?= pl_e($label) ?></span></a>
            <?php endforeach; ?>
            <?php if ($group === 'Setup'): ?></div></details><?php endif; ?>
        <?php endforeach; ?>
    </nav>
    <div class="shell-sidebar-footer"><a class="nav-item" href="<?= pl_e(pl_url('/help')) ?>" title="<?= pl_e(pl_t('Help')) ?>"<?= $view === 'help' ? ' aria-current="page"' : '' ?>><?= pl_icon('info-circle') ?><span><?= pl_e(pl_t('Help')) ?></span></a><p class="shell-version"><?= pl_e(pl_app_version()) ?></p></div>
</aside>
<button class="shell-overlay" data-drawer-overlay aria-label="<?= pl_e(pl_t('Close navigation')) ?>" type="button" tabindex="-1"></button>
<div class="shell-body">
    <header class="shell-topbar">
        <button type="button" class="btn btn-ghost btn-icon" data-sidebar-toggle aria-label="<?= pl_e(pl_t('Toggle navigation')) ?>" aria-controls="workspace-navigation" aria-expanded="true" hidden><?= pl_icon('menu-2') ?></button>
        <ol class="crumbs" aria-label="<?= pl_e(pl_t('Breadcrumb')) ?>"><li class="crumb"><a href="<?= pl_e(pl_url('/companies')) ?>"><?= pl_e(pl_t('Workspace')) ?></a></li><li class="crumb"><span aria-current="page"><?= pl_e($title) ?></span></li></ol>
        <div class="topbar-search"><button type="button" class="search-trigger" data-command-open aria-haspopup="dialog" hidden><?= pl_icon('search') ?><span><?= pl_e(pl_t('Search or jump to…')) ?></span><span class="kbd ms-auto"><?= pl_e(pl_t('Ctrl K')) ?></span></button></div>
        <div class="topbar-actions">
            <?php pl_ui_page_help($view); ?>
            <?php if (pl_can_write($company)): ?><details class="menu"><summary class="btn btn-primary btn-sm"><?= pl_icon('plus') ?> <?= pl_e(pl_t('New')) ?></summary><div class="menu-panel menu-panel-end"><p class="menu-label"><?= pl_e(pl_t('Quick create')) ?></p><?php foreach ($quickCreate as [$href, $label, $visible]): if (!$visible) { continue; } ?><a class="menu-item" href="<?= pl_e(pl_url($href)) ?>"><?= pl_icon((str_starts_with($href,'/transactions') || str_starts_with($href,'/receipts') || str_starts_with($href,'/expenses')) ? 'receipt' : (str_starts_with($href,'/parties') ? 'building' : 'file-text')) ?> <?= pl_e($label) ?></a><?php endforeach; ?></div></details><?php endif; ?>
            <?php if ($company['is_sample'] && pl_company_demo_pack((int)$user['id'], (int)$company['id'], (int)$company['book_id']) !== null): ?><a class="sample-guide-link max-lg:hidden" href="<?= pl_e(pl_url('/sample-guide')) ?>"><?= pl_e(pl_t('Sample guide')) ?> <?= pl_icon('arrow-right') ?></a><?php endif; ?>
            <details class="menu"><summary class="user-menu-trigger" aria-label="<?= pl_e(pl_t('User menu')) ?>"><span class="avatar"><?= pl_e(mb_strtoupper(mb_substr($user['display_name'], 0, 1))) ?></span><?= pl_icon('chevron-down') ?></summary><div class="menu-panel menu-panel-end"><p class="menu-label"><?= pl_e($user['display_name']) ?></p><?php if (!pl_demo_enabled()): ?><p class="menu-item-static"><?= pl_e($user['email']) ?></p><a class="menu-item" href="<?= pl_e(pl_url('/profile')) ?>"><?= pl_e(pl_t('Your profile')) ?></a><a class="menu-item" href="<?= pl_e(pl_url('/companies')) ?>"><?= pl_e(pl_t('Switch business')) ?></a><?php endif; ?><?php $localeSwitchId = 'topbar'; $localeSwitchClass = 'locale-switch-menu'; require __DIR__ . '/locale-switch.php'; ?><form action="<?= pl_e(pl_url('/logout')) ?>" method="post"><?= pl_csrf_field() ?><button type="submit" class="menu-item"><?= pl_icon('logout') ?> <?= pl_e(pl_demo_enabled() ? pl_t('Leave demo') : pl_t('Sign out')) ?></button></form></div></details>
        </div>
    </header>
