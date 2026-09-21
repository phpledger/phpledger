<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/www/phpledger/includes/functions/web_functions.php';
// The wizard's controller is loaded by its route, not by the bootstrap, so the shape assertions
// below have to ask for it themselves.
require_once dirname(__DIR__) . '/www/phpledger/includes/functions/onboarding_web_functions.php';

test('workspace shell preserves view data while rendering navigation', function (): void {
    if (session_status() !== PHP_SESSION_ACTIVE) { pl_session_start(false); }
    $suffix = bin2hex(random_bytes(8));
    $actor = pl_create_user('shell-' . $suffix . '@example.test', 'Sample shell owner', 'Sample-test-password-' . $suffix);
    $fixture = pl_create_company($actor, 'Sample shell company', 'USD', '2026-01-01');
    $company = pl_company_context($actor, $fixture['company_id']);
    $user = ['id' => $actor, 'display_name' => 'Sample shell owner', 'email' => 'shell-' . $suffix . '@example.test'];
    $view = 'error'; $title = 'Sample view title'; $message = 'Sample view message'; $notice = '';
    $items = [['reference' => 'A real view row']]; $label = 'View label'; $views = ['view data'];
    ob_start();
    try { require dirname(__DIR__) . '/www/phpledger/templates/layout.php'; }
    finally { ob_end_clean(); }
    assert_same([['reference' => 'A real view row']], $items);
    assert_same('View label', $label);
    assert_same(['view data'], $views);
    assert_same('Sample view title', $title);
});

    $layout = file_get_contents(dirname(__DIR__) . '/www/phpledger/templates/layout.php') . file_get_contents(dirname(__DIR__) . '/www/phpledger/templates/partials/ui/shell.php');
    $styles = file_get_contents(dirname(__DIR__) . '/www/phpledger/public/assets/app.css');
    $app = file_get_contents(dirname(__DIR__) . '/www/phpledger/public/assets/app.js');
    $guide = file_get_contents(dirname(__DIR__) . '/www/phpledger/templates/views/sample-guide.php');

test('shared shell exposes the candidate version and grouped navigation contract', function () use ($layout, $styles, $app, $guide): void {
    assert_true(is_string($layout) && str_contains($layout, 'pl_app_version()'), 'Layout does not use the shared version helper.');
    assert_true(is_string($layout) && substr_count($layout, 'pl_app_version()') >= 2, 'Version is not rendered in both workspace and focused layouts.');
    assert_true(is_string($layout) && str_contains($layout, 'class="shell-nav"'), 'Grouped navigation is missing.');
    assert_true(is_string($layout) && str_contains($layout, 'shell-sidebar'), 'Workspace sidebar hook is missing.');
    assert_true(is_string($layout) && !str_contains($layout, '<nav class="accounting-nav"'), 'The shell still renders a second administration navigation strip.');
    assert_true(is_string($layout) && str_contains($layout, 'pl_module_available'), 'Navigation lost server-side module checks.');
    assert_true(is_string($styles) && str_contains($styles, '.shell-version'), 'Brand version treatment is missing.');
    assert_true(is_string($styles) && str_contains($styles, '.menu-panel'), 'Responsive navigation panel treatment is missing.');
    assert_true(is_string($styles) && str_contains($styles, '.shell-sidebar'), 'Desktop workspace rail styling is missing.');
    assert_true(is_string($app) && str_contains($app, '[data-fiscal-year-end-choice]') && str_contains($app, 'customGroup.hidden = !isCustom'), 'Fiscal year-end progressive disclosure behavior is missing.');
    assert_true(is_string($guide) && str_contains($guide, 'sample-evidence'), 'Sample guide does not expose pinned research evidence.');
    assert_true(is_string($guide) && str_contains($guide, 'research_evidence'), 'Sample guide does not bind its evidence section to the pack contract.');
    assert_true(is_string($guide) && str_contains($guide, 'industry_profile'), 'Sample guide does not expose the selected vertical profile.');
});

/**
 * The wizard was six linear steps until 1.2.1. Owner decision B50 replaced it with five named
 * stages in the installer's own Workbench language, and added the Source stage, which is where
 * a new business chooses between a blank chart, a sample company's structure and a full sample.
 * These assertions describe that shape; the behaviour is proved in onboarding_skeleton_test.php.
 */
test('setup shell is five Workbench stages with an explicit source choice', function () use ($layout, $styles): void {
    $onboarding = (string) file_get_contents(dirname(__DIR__) . '/www/phpledger/templates/views/onboarding.php');
    $controller = (string) file_get_contents(dirname(__DIR__) . '/www/phpledger/public/index.php');
    $wizard = (string) file_get_contents(dirname(__DIR__) . '/www/phpledger/includes/functions/onboarding_web_functions.php');
    $chooser = (string) file_get_contents(dirname(__DIR__) . '/www/phpledger/templates/views/sample-chooser.php');
    assert_same(['start' => 'Start', 'business' => 'Business', 'source' => 'Source', 'review' => 'Review', 'ready' => 'Ready'],
        pl_onboarding_stages(), 'The wizard is not the five named stages B50 asked for.');
    // The same tray, slot states and manifest card the installer uses, not a second vocabulary.
    foreach (['bench-tray', 'tray-slot', 'is-current', 'is-done', 'is-pending', 'bench-head', 'bench-body', 'bench-actions', 'manifest-card'] as $shared) {
        assert_true(str_contains($onboarding, $shared), 'The wizard does not use the installer component ' . $shared . '.');
    }
    assert_true(str_contains($styles, '.tray-slot') && str_contains($styles, '.bench-tray.is-labelled'),
        'The labelled tray the wizard needs is not in the stylesheet.');
    assert_true(str_contains($onboarding, 'name="source"') && str_contains($onboarding, 'value="skeleton"') && str_contains($onboarding, 'value="full"'),
        'The Source stage does not offer blank, skeleton and full.');
    assert_true(str_contains($onboarding, 'name="sample_pack"'), 'The Source stage cannot choose a sample company.');
    assert_true(str_contains($onboarding, 'name="chart_choice"'), 'Neutral/bring-your-own chart choice is missing.');
    assert_true(str_contains($onboarding, 'data-fiscal-year-end-choice') && str_contains($onboarding, 'data-fiscal-custom-group'),
        'Fiscal year-end choices do not provide the progressive disclosure hooks.');
    assert_true(str_contains($wizard, "if (\$action === 'next')"), 'Onboarding stage transitions are not server handled.');
    assert_true(str_contains($wizard, 'pl_sample_structure_activate_packages'), 'The wizard does not reach the package seam.');
    assert_true(str_contains($controller, "'/sample-chooser' => ['GET', 'POST']"), 'Local sample chooser route is missing.');
    assert_true(str_contains($controller, 'pl_web_onboarding($actorId, $user, $method)'), 'The wizard route does not reach its controller.');
    assert_true(str_contains($chooser, 'name="sample_pack"') && str_contains($chooser, 'pl_demo_sample_choices()'),
        'Sample chooser does not expose the bundled selection contract.');
    // Nothing is created before the Review stage is confirmed, and the Review stage says so.
    assert_true(str_contains($onboarding, 'Not created yet') && str_contains($onboarding, 'value="confirm"'),
        'The Review stage does not hold the single point of creation.');
});

test('sample import has a bounded operational replay path', function (): void {
    $demo = file_get_contents(dirname(__DIR__) . '/www/phpledger/includes/functions/demo_pack_functions.php');
    assert_true(is_string($demo) && str_contains($demo, 'function pl_demo_operational_contract'), 'Operational contract validator is missing.');
    assert_true(is_string($demo) && str_contains($demo, 'function pl_demo_operational_replay'), 'Operational replay adapter is missing.');
    assert_true(is_string($demo) && str_contains($demo, 'pl_save_ar_document') && str_contains($demo, 'pl_settle_ar_document'), 'Replay does not use the AR/AP services.');
    assert_true(is_string($demo) && str_contains($demo, 'pl_save_purchase_order') && str_contains($demo, 'pl_receive_purchase_order'), 'Replay does not use the purchasing service.');
    assert_true(is_string($demo) && str_contains($demo, 'pl_inventory_issue'), 'Replay does not use the inventory service.');
    assert_true(is_string($demo) && str_contains($demo, 'pl_record_installation_history'), 'Replay receipt is not retained in immutable installation history.');
    assert_true(is_string($demo) && str_contains($demo, 'count($events) > 32') && str_contains($demo, '$seenReferences'), 'Operational admission is not bounded by durable source identity.');
    assert_true(is_string($demo) && str_contains($demo, "['deferred_revenue', 'customer_advance', 'store_credit_liability']"), 'Deferred revenue is not classified before income roles.');
});
