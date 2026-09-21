<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/www/phpledger/includes/functions/web_functions.php';

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

test('setup shell exposes six focused decisions and an explicit chart choice', function () use ($layout, $styles): void {
    $onboarding = file_get_contents(dirname(__DIR__) . '/www/phpledger/templates/views/onboarding.php');
    $controller = file_get_contents(dirname(__DIR__) . '/www/phpledger/public/index.php');
    $chooser = file_get_contents(dirname(__DIR__) . '/www/phpledger/templates/views/sample-chooser.php');
    // M11 externalised this label. It used to be built by concatenation, which is untranslatable
    // and comes apart in a right-to-left locale, and it is now one pl_t() string with a {step}
    // placeholder. The fact this assertion guards has not moved: the flow names six steps, and the
    // preview is the fifth of them. The rendered English is byte-for-byte what it was.
    assert_true(is_string($onboarding) && str_contains($onboarding, 'Step {step} of 6'), 'Onboarding is not a six-step flow.');
    assert_true(is_string($onboarding) && str_contains($onboarding, "'step' => \$preview ? 5 : \$activeStep"), 'Onboarding preview is not the fifth of six steps.');
    assert_true(is_string($onboarding) && str_contains($onboarding, 'name="chart_choice"'), 'Neutral/bring-your-own chart choice is missing.');
    assert_true(is_string($onboarding) && str_contains($onboarding, 'data-fiscal-year-end-choice') && str_contains($onboarding, 'data-fiscal-custom-group'), 'Fiscal year-end choices do not provide the progressive disclosure hooks.');
    assert_true(is_string($controller) && str_contains($controller, "if (\$action === 'next')"), 'Onboarding step transitions are not server handled.');
    assert_true(is_string($controller) && str_contains($controller, "'/sample-chooser' => ['GET', 'POST']"), 'Local sample chooser route is missing.');
    assert_true(is_string($controller) && str_contains($controller, "pl_demo_sample(\$sampleId)"), 'Sample selection does not resolve through the bundled catalogue.');
    assert_true(is_string($chooser) && str_contains($chooser, 'name="sample_pack"') && str_contains($chooser, 'pl_demo_sample_choices()'), 'Sample chooser does not expose the bundled selection contract.');
    // Same component, same step list, same active step; M11 put the labels through pl_t() on the
    // way in, because pl_ui_stepper() escapes but does not translate.
    assert_true(is_string($styles) && str_contains($styles, '.stepper-step') && str_contains($onboarding, "pl_ui_stepper(array_map('pl_t', \$steps), \$activeStep)"), 'Setup does not use the styled shared progress component.');
    ob_start();
    pl_ui_stepper(['Starting point','Business identity','Period and profile','Chart choice','Preview','Confirm'],5);
    $progress=(string)ob_get_clean();
    assert_same(6,substr_count($progress,'<li '));
    assert_same(1,substr_count($progress,'aria-current="step"'));
    assert_true(str_contains($progress,'stepper-label">Preview</span>'));
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
