<?php
declare(strict_types=1);

function pl_web_modules(int $actorId, int $companyId, int $bookId, array $user, array $company, string $method): never
{
    if (pl_demo_enabled()) { throw new DomainException('Module administration is unavailable in the public sample.'); }
    if ($method === 'POST') {
        try {
            pl_web_assert_scope($company, $_POST);
            if (pl_web_text($_POST, 'action') === 'visibility') {
                foreach (['show_ar','show_ap'] as $field) {
                    if (isset($_POST[$field]) && pl_web_text($_POST,$field)!=='1') { throw new DomainException('Choose whether to show receivables and payables.'); }
                }
                pl_set_company_visibility($actorId,$companyId,isset($_POST['show_ar']),isset($_POST['show_ap']),
                    pl_web_id($_POST,'revision'),pl_web_text($_POST,'reason'),pl_web_text($_POST,'request_key'));
                pl_notice('Navigation updated. Records and authorised services remain available.');
                pl_redirect('/modules');
            }
            $enable = pl_web_text($_POST, 'enabled');
            if (!in_array($enable, ['0', '1'], true)) { throw new DomainException('Choose enable or disable.'); }
            $result = pl_set_company_module($actorId, $companyId, pl_web_text($_POST, 'module_id'), $enable === '1',
                pl_web_id($_POST, 'revision'), pl_web_text($_POST, 'digest'), pl_web_text($_POST, 'reason'), pl_web_text($_POST, 'request_key'));
            pl_notice($result['enabled'] ? 'Module enabled. Its current version is ready.' : 'Module disabled. Posted history remains available.');
            pl_redirect('/modules');
        } catch (DomainException $error) {
            $input=array_map(static fn(mixed $value): string=>is_scalar($value)?(string)$value:'',$_POST);
            pl_form_failure(pl_url('/modules'), $input, $error->getMessage());
        }
    }
    $modules = array_values(pl_web_module_states($companyId));
    pl_render('modules', ['title' => 'Modules', 'user' => $user, 'company' => $company, 'modules' => $modules,
        'returnTo' => pl_web_text($_GET, 'return') === 'onboarding' ? pl_url('/onboarding', ['stage' => 'features']) : '',
        'form' => pl_form_state(pl_url('/modules')), 'history' => pl_module_history($actorId, $companyId),
        'visibility' => pl_company_visibility($actorId,$companyId)]);
}

/**
 * The optional modules with their state for one business, keyed by module id. Shared by the
 * Modules screen and the Packages page (1.4.5, frame P-1), so a switch on either calls the same
 * service with the same revision and digest.
 *
 * @return array<string, array{manifest: array<string, mixed>, state: array<string, mixed>, problem: string, current: bool}>
 */
function pl_web_module_states(int $companyId): array
{
    $modules = [];
    foreach (pl_module_registry() as $manifest) {
        if (!$manifest['optional']) { continue; }
        $state = pl_module_state($companyId, $manifest['id']);
        $problem = '';
        try { pl_module_installed($manifest); } catch (DomainException $error) { $problem = $error->getMessage(); }
        $modules[$manifest['id']] = ['manifest' => $manifest, 'state' => $state, 'problem' => $problem,
            'current' => $state['enabled'] && $state['version'] === $manifest['version'] && $state['manifest_hash'] === $manifest['digest']];
    }
    return $modules;
}

/** One plain sentence per bundled module for a card (frame P-1); the manifest's history is the longer story. */
function pl_web_module_description(string $id): string
{
    return match ($id) {
        'core' => pl_t('Journals, reports, periods and every protection.'),
        'ar' => pl_t('Customers, invoices and receipts against them.'),
        'ap' => pl_t('Suppliers, bills and payments against them.'),
        'inventory' => pl_t('Products, stock on hand and cost of sales. Historical documents stay readable when it is off.'),
        'inventory-locations' => pl_t('Warehouses and vans, transfers at carrying value, van settlement.'),
        'purchasing' => pl_t('Purchase orders and goods receipts, with the supplier bill later.'),
        'trading-documents' => pl_t('Pack, discount, free goods, sales staff and cash-on-invoice on documents.'),
        'fixed-assets' => pl_t('Asset register, depreciation runs and disposals as a subsidiary ledger.'),
        'pos-showcase' => pl_t('An illustration of a cash counter. Sample companies only; not for real sales.'),
        default => '',
    };
}
