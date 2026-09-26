<?php
declare(strict_types=1);

// The module switch on a package card shares the Modules screen's state reader and service glue.
require_once __DIR__ . '/module_web_functions.php';

/**
 * Admin > Packages (release plan 1.2 M8; frames `packages-installed.html` and
 * `packages-upload-confirm.html`; decisions B44, B51, B52 and onboarding decision 10).
 *
 * The screen is visible to anyone who can open the workspace and read-only without
 * `installation.admin`: a business owner reasonably wants to see what a package does to their
 * business without being able to change what code runs here. Nothing on this page decides a
 * permission; every service call below authorises itself.
 *
 * The executable-plugin directory and automatic updates of B51 remain separate work — they
 * need the phpledger.com feed, which is a separate milestone. Data-only sample discovery uses explicit network actions; executable packages retain Installed and
 * Upload, and an upload is always Unverified, because nothing in this release signs a package.
 */
function pl_web_packages(int $actorId, int $companyId, int $bookId, array $user, ?array $company, string $method): never
{
    if (pl_demo_enabled()) {
        throw new DomainException(pl_t('Package administration is unavailable in the public sample.'));
    }
    pl_plugin_bootstrap_initial_owner($actorId);
    pl_plugin_bootstrap_initial_owner($actorId);
    $administers = pl_user_can($actorId, 0, 'installation.admin');
    if ($method === 'POST') {
        pl_web_packages_post($actorId, $company ?? ['id'=>0,'book_id'=>0]);
    }
    $form = pl_form_state(pl_url('/packages'));
    // Arrived from business setup: say so, and offer the way back (owner note, 25 September 2026).
    $returnTo = pl_web_text($_GET, 'return') === 'onboarding' ? pl_url('/onboarding', ['stage' => 'start']) : '';
    // The full-page confirmation (onboarding decision 9): installing code that can change what a
    // posting does is a heavier decision than a modal invites, so it is its own reloadable page.
    $confirm = pl_web_text($_GET, 'confirm');
    $review = null;
    if ($confirm !== '' && $administers) {
        try {
            $manifest = pl_plugin_read_manifest($confirm);
            $review = ['slug' => $confirm, 'manifest' => $manifest,
                'files_digest' => pl_plugin_files_digest($confirm, $manifest),
                'requirements' => pl_web_package_requirements($manifest)];
        } catch (DomainException $error) {
            pl_notice($error->getMessage());
            pl_redirect('/packages');
        }
    }
    // One page, four tabs (frame P-1, 1.4.5): the tab is part of the address so a link from
    // business setup, a redirect after an action and the back button all land on the right one.
    $tab = pl_web_text($_GET, 'tab');
    if (!in_array($tab, ['installed', 'samples', 'directory', 'upload'], true) || ($tab === 'upload' && !$administers)) { $tab = 'installed'; }
    pl_render('packages', [
        'title' => pl_t('Packages'), 'user' => $user, 'company' => $company, 'packageScope'=>$company ?? ['id'=>0,'book_id'=>0],
        'administers' => $administers, 'tab' => $tab,
        'moduleStates' => $company !== null && (int) ($company['id'] ?? 0) > 0 ? pl_web_module_states((int) $company['id']) : [],
        // The demo was refused above, so only the business owner's role decides.
        'canSwitch' => $company !== null && (int) ($company['id'] ?? 0) > 0 && ($company['role'] ?? '') === 'owner',
        'cards' => pl_plugin_cards(),
        'samplePackages' => pl_sample_installed_packages(), 'sampleDirectory'=>['schema'=>1,'packages'=>array_values(pl_sample_directory_offer())], 'samplePresent'=>array_map(static fn (string $id): string => 'sample-' . $id, array_map('strval', array_keys(pl_demo_pack_catalog()))), 'sampleReadonly'=>pl_sample_packages_readonly(),
        'staged' => $administers ? pl_web_packages_staged() : [],
        'review' => $review,
        'acknowledgements' => pl_plugin_acknowledgements(),
        'safe_mode' => pl_plugins_safe_mode(),
        'history' => $administers ? pl_plugin_history($actorId) : [],
        'form' => $form, 'input' => $form['input'], 'returnTo' => $returnTo,
    ]);
}

/**
 * Folders sitting in the package directory that nothing has recorded: a ZIP just uploaded, or a
 * folder an operator copied in over SFTP. Neither has been reviewed, so both reach the same
 * confirmation page before a line of their code runs.
 *
 * @return list<array{slug:string, name:string, version:string, problem:string}>
 */
function pl_web_packages_staged(): array
{
    $recorded = pl_plugin_records();
    $staged = [];
    foreach (pl_plugin_directory_slugs() as $slug) {
        if (isset($recorded[$slug])) {
            continue;
        }
        try {
            $manifest = pl_plugin_read_manifest($slug);
            $staged[] = ['slug' => $slug, 'name' => (string) $manifest['name'], 'version' => (string) $manifest['version'], 'problem' => ''];
        } catch (DomainException $error) {
            $staged[] = ['slug' => $slug, 'name' => $slug, 'version' => '', 'problem' => $error->getMessage()];
        }
    }
    return $staged;
}

/**
 * Each requirement with whether this copy satisfies it, so the confirmation page names the
 * missing one rather than failing on submit (B47, issue #72).
 *
 * @param array<string, mixed> $manifest
 * @return list<array{name:string, version:string, satisfied:bool, present:string}>
 */
function pl_web_package_requirements(array $manifest): array
{
    $modules = pl_module_registry();
    $packages = pl_plugin_records();
    $requirements = [];
    /** @var array<string, string> $requires */
    $requires = $manifest['requires'];
    foreach ($requires as $dependency => $version) {
        $present = '';
        if (isset($modules[$dependency])) {
            $present = (string) $modules[$dependency]['version'];
        } elseif (isset($packages[$dependency])) {
            $present = (string) $packages[$dependency]['version'] . ' (' . (string) $packages[$dependency]['status'] . ')';
        }
        $requirements[] = ['name' => $dependency, 'version' => $version,
            'satisfied' => isset($modules[$dependency]) ? $modules[$dependency]['version'] === $version
                : (isset($packages[$dependency]) && (string) $packages[$dependency]['version'] === $version && $packages[$dependency]['status'] === 'active'),
            'present' => $present];
    }
    return $requirements;
}

function pl_web_packages_post(int $actorId, array $company): void
{
    $return = pl_web_text($_POST, 'return') === 'onboarding' ? pl_url('/onboarding', ['stage' => 'start']) : pl_url('/packages');
    try {
        pl_web_assert_scope($company, $_POST);
        $action = pl_web_text($_POST, 'action');
        $slug = pl_web_text($_POST, 'slug');
        $reason = pl_web_text($_POST, 'reason');
        $key = pl_web_text($_POST, 'request_key');
        $tabbed = static fn (string $tab): string => $return === pl_url('/packages') ? pl_url('/packages', ['tab' => $tab]) : $return;
        if ($action === 'module_toggle') {
            // The switch on a module card (frame P-1): the same service, revision and digest as
            // Modules, with a standard reason so the audit row still says who and when.
            if ((int) ($company['id'] ?? 0) < 1) { throw new DomainException(pl_t('Choose a business before switching a module on or off.')); }
            $enable = pl_web_text($_POST, 'enabled');
            if (!in_array($enable, ['0', '1'], true)) { throw new DomainException(pl_t('Choose enable or disable.')); }
            $moduleId = pl_web_text($_POST, 'module_id');
            $why = $reason !== '' ? $reason : ($enable === '1' ? pl_t('Switched on from Packages') : pl_t('Switched off from Packages'));
            $result = pl_set_company_module($actorId, (int) $company['id'], $moduleId, $enable === '1', pl_web_id($_POST, 'revision'), pl_web_text($_POST, 'digest'), $why, $key);
            pl_notice($result['enabled'] ? pl_t('{module} is on for {company}.', ['module' => (string) (pl_module_registry()[$moduleId]['name'] ?? $moduleId), 'company' => (string) $company['name']])
                : pl_t('{module} is off for {company}. Posted history remains available.', ['module' => (string) (pl_module_registry()[$moduleId]['name'] ?? $moduleId), 'company' => (string) $company['name']]));
            pl_redirect($tabbed('installed'));
        }
        if ($action === 'sample_refresh') { pl_sample_directory_refresh($actorId); pl_notice(pl_t('Sample directory refreshed.')); pl_redirect($tabbed('directory')); }
        if ($action === 'sample_install') { pl_sample_directory_install($actorId,$slug,$key); pl_notice(pl_t('Sample installed. It is available in business setup.')); pl_redirect($tabbed('samples')); }
        if ($action === 'sample_remove') { pl_sample_package_remove($actorId,$slug,$reason,$key); pl_notice(pl_t('Sample package removed. Existing businesses and their history are unchanged.')); pl_redirect($tabbed('samples')); }
        if ($action === 'upload') {
            $key = pl_request_key($key);
            $staged = pl_web_packages_upload($actorId);
            if (($staged['type'] ?? null) === 'sample') { pl_sample_package_install($actorId,$staged['slug'],'Owner uploaded data-only sample',$key); pl_notice(pl_t('Data-only sample installed. It is available in business setup.')); pl_redirect($return); }
            pl_notice(pl_t('{name} was unpacked and has not been installed yet. Read what it says about itself, then confirm below.', ['name' => $staged['manifest']['name']]));
            pl_redirect(pl_url('/packages', ['confirm' => $staged['slug']]));
        }
        if ($action === 'confirm_upload') {
            $acknowledged = [];
            foreach (array_keys(pl_plugin_acknowledgements()) as $name) {
                $acknowledged[$name] = pl_web_text($_POST, 'ack_' . $name) === '1';
            }
            $result = pl_plugin_install($actorId, $slug, 'unverified', $reason, $key, $acknowledged);
            pl_notice(pl_t('{slug} is installed and marked Unverified. It is not running yet: activate it when you are ready.', ['slug' => $result['slug']]));
            pl_redirect($return);
        }
        if ($action === 'discard') {
            pl_plugin_require_mutation($actorId);
            if (pl_plugin_record($slug) !== null) {
                throw new DomainException(pl_t('This package is installed. Remove it from its card instead.'));
            }
            pl_plugin_remove_directory(pl_plugin_directory() . '/' . pl_plugin_slug($slug));
            pl_notice(pl_t('The unpacked files were deleted. Nothing was installed and nothing ran.'));
            pl_redirect($return);
        }
        if ($action === 'activate') {
            pl_plugin_activate($actorId, $slug, $reason, $key);
            pl_notice(pl_t('{slug} is active. Its code now loads on every request while its files match what you installed.', ['slug' => $slug]));
            pl_redirect($return);
        }
        if ($action === 'deactivate') {
            pl_plugin_deactivate($actorId, $slug, $reason, $key);
            pl_notice(pl_t('{slug} is deactivated. Its data and settings are kept.', ['slug' => $slug]));
            pl_redirect($return);
        }
        if ($action === 'uninstall') {
            $deleteData = pl_web_text($_POST, 'delete_data') === '1';
            if ($deleteData && pl_web_text($_POST, 'confirm') !== 'delete') {
                throw new DomainException(pl_t('Type delete to confirm removing this package\'s data. This cannot be undone.'));
            }
            pl_plugin_uninstall($actorId, $slug, $deleteData, $reason, $key);
            pl_notice($deleteData
                ? pl_t('{slug} was removed with its own tables and settings. Nothing in your books changed.', ['slug' => $slug])
                : pl_t('{slug} was removed. Its tables and settings were kept, so reinstalling it finds its data.', ['slug' => $slug]));
            pl_redirect($return);
        }
        throw new DomainException(pl_t('Choose a valid action.'));
    } catch (DomainException|JsonException $error) {
        $input = array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $_POST);
        pl_form_failure($return, $input, $error->getMessage());
    }
}

/**
 * Read the uploaded archive and stage it. Nothing is recorded and no plugin code runs here; the
 * confirmation page is next.
 *
 * @return array{slug:string, manifest:array<string, mixed>, files_digest:string, type?:string}
 */
function pl_web_packages_upload(int $actorId): array
{
    pl_plugin_require_admin($actorId);
    $upload = $_FILES['package'] ?? null;
    if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($upload['tmp_name'] ?? null)) {
        throw new DomainException(pl_t('Choose a package ZIP to upload. Very large files may also be refused by this server before they reach the application.'));
    }
    if (!is_uploaded_file($upload['tmp_name'])) {
        throw new DomainException(pl_t('That upload could not be read.'));
    }
    return pl_plugin_stage_archive($actorId, $upload['tmp_name']);
}
