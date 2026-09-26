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
    $modules = [];
    foreach (pl_module_registry() as $manifest) {
        if (!$manifest['optional']) { continue; }
        $state = pl_module_state($companyId, $manifest['id']);
        $problem = '';
        try { pl_module_installed($manifest); } catch (DomainException $error) { $problem = $error->getMessage(); }
        $modules[] = ['manifest' => $manifest, 'state' => $state, 'problem' => $problem,
            'current' => $state['enabled'] && $state['version'] === $manifest['version'] && $state['manifest_hash'] === $manifest['digest']];
    }
    pl_render('modules', ['title' => 'Modules', 'user' => $user, 'company' => $company, 'modules' => $modules,
        'returnTo' => pl_web_text($_GET, 'return') === 'onboarding' ? pl_url('/onboarding', ['stage' => 'features']) : '',
        'form' => pl_form_state(pl_url('/modules')), 'history' => pl_module_history($actorId, $companyId),
        'visibility' => pl_company_visibility($actorId,$companyId)]);
}
