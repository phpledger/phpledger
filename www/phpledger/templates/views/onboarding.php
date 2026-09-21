<?php
declare(strict_types=1);

$startMode = pl_web_text($input, 'start_mode', 'fresh');
$businessName = pl_web_text($input, 'name');
$baseCurrency = pl_web_text($input, 'currency', $regional['currency'] ?? 'USD');
$startDate = pl_web_text($input, 'start_date', gmdate('Y-m-d'));
$defaultFiscalYearEnd = ($regional['country_code'] ?? '') === 'PK' ? '06-30' : '12-31';
$fiscalYearEnd = pl_web_text($input, 'fiscal_year_end', $defaultFiscalYearEnd);
$entityType = pl_web_text($input, 'entity_type', 'other');
$entityTypes = pl_setup_entity_type_options();
$entityYearEndGuidance = match ($entityType) {
    'individual' => '30 June is a common Pakistan year end for an individual or sole proprietor. Confirm the period actually used by these books.',
    'aop' => '30 June is a common Pakistan year end for an AOP, partnership or association. Confirm the period actually used by these books.',
    'company' => 'A company may use 30 June or another permitted year end. Select the period actually used by these books.',
    default => 'Choose the year end used by these books. The business label does not decide the period automatically.',
};
$fiscalYearEndChoice = pl_web_text($input, 'fiscal_year_end_choice');
$fiscalYearEndCustom = pl_web_text($input, 'fiscal_year_end_custom');
if ($fiscalYearEndChoice === '') {
    $fiscalYearEndChoice = array_key_exists($fiscalYearEnd, pl_fiscal_year_end_options()) ? $fiscalYearEnd : 'custom';
}
if ($fiscalYearEndChoice === 'custom' && $fiscalYearEndCustom === '') {
    $fiscalYearEndCustom = $fiscalYearEnd;
}
$chartChoice = pl_web_text($input, 'chart_choice', $startMode === 'existing' ? 'bring_own' : 'neutral');
$zeroConfirmed = ($input['zero_balances_confirmed'] ?? false) === true || pl_web_text($input, 'zero_balances_confirmed') === '1';
$startLabels = ['fresh' => 'Start a new business', 'existing' => 'Bring past records', 'sample' => 'Explore a sample company'];
$steps = [1 => 'Starting point', 2 => 'Business identity', 3 => 'Period and profile', 4 => 'Chart choice', 5 => 'Preview', 6 => 'Confirm'];
$activeStep = $preview ? 5 : max(1, min(4, (int) ($wizard_step ?? 1)));
?>
<section class="auth-panel setup-wizard" aria-labelledby="onboarding-title">
    <p class="eyebrow">Business setup · Step <?= $preview ? '5 of 6' : $activeStep . ' of 6' ?></p>
    <?php pl_ui_page_header($preview ? 'Preview your setup' : ($steps[$activeStep]), $preview ? 'Check the business identity, period and chart choice before anything is created.' : 'Set up the decisions that shape these books, one focused step at a time.', static function (): void { ?>
        <a class="btn btn-secondary" href="<?= pl_e(pl_url('/companies')) ?>">Back to businesses</a>
    <?php }, 'onboarding-title'); ?>

    <div class="ui-wizard-grid"><aside><?php pl_ui_stepper($steps, $activeStep); ?></aside><div class="min-w-0">


    <?php if ($form['message'] !== ''): ?>
        <div class="alert alert-warning" role="alert" tabindex="-1" id="setup-error" data-form-error>
            <h2>Check this step</h2>
            <p><?= pl_e((string) $form['message']) ?></p>
            <p>Your setup details have been kept so you can correct them.</p>
        </div>
    <?php endif; ?>

    <?php if (!$preview && $activeStep === 3): ?>
        <?php require __DIR__ . '/../partials/regional.php'; ?>
    <?php endif; ?>

    <?php if (!$preview && $activeStep <= 4): ?>
        <form action="<?= pl_e(pl_url('/onboarding')) ?>" method="post" class="form-grid setup-step-panel">
            <?= pl_csrf_field() ?>
            <input type="hidden" name="action" value="next">
            <input type="hidden" name="wizard_step" value="<?= $activeStep ?>">

            <?php if ($activeStep === 1): ?>
                <fieldset class="field full-width">
                    <legend>What are you setting up?</legend>
                    <label class="checkbox setup-option"><input type="radio" name="start_mode" value="fresh"<?= $startMode === 'fresh' ? ' checked' : '' ?>> <span><b>Start a new business</b><small>Begin with no opening balances or unpaid invoices.</small></span></label>
                    <label class="checkbox setup-option"><input type="radio" name="start_mode" value="existing"<?= $startMode === 'existing' ? ' checked' : '' ?>> <span><b>Bring past records</b><small>Keep prior balances and prepare an explicit opening cutover.</small></span></label>
                    <?php if (pl_sample_companies_allowed()): ?><label class="checkbox setup-option"><input type="radio" name="start_mode" value="sample"<?= $startMode === 'sample' ? ' checked' : '' ?>> <span><b>Explore a sample company</b><small>Choose one company in the separate sample chooser; it will be isolated from your businesses.</small></span></label><?php endif; ?>
                </fieldset>
            <?php elseif ($activeStep === 2): ?>
                <div class="field full-width"><label class="field-label" for="business-name">Business name</label><input class="input" id="business-name" name="name" type="text" maxlength="160" autocomplete="organization" required value="<?= pl_e($businessName) ?>" aria-describedby="business-name-help"><p class="muted" id="business-name-help">Use the name your team will recognise. A sample company receives a separate fictional name.</p></div>
                <div class="field"><label class="field-label" for="base-currency">Base currency</label><select class="select" id="base-currency" name="currency" required aria-describedby="currency-help"><?php foreach (pl_base_currency_options() as $code => $label): ?><option value="<?= pl_e($code) ?>"<?= $baseCurrency === $code ? ' selected' : '' ?>><?= pl_e($label) ?></option><?php endforeach; ?></select><p class="muted" id="currency-help">This book uses one functional currency. Currency selection does not activate country tax rules.</p></div>
                <div class="field"><label class="field-label" for="accounting-start">Accounting start date</label><input class="input" id="accounting-start" name="start_date" type="date" required value="<?= pl_e($startDate) ?>" aria-describedby="start-date-help"><p class="muted" id="start-date-help">The first date for these books. Accounting dates do not shift with time zones.</p></div>
            <?php elseif ($activeStep === 3): ?>
                <div class="field full-width"><label class="field-label" for="entity-type">Business or entity type</label><select class="select" id="entity-type" name="entity_type" required aria-describedby="entity-type-help"><?php foreach ($entityTypes as $key => $label): ?><option value="<?= pl_e($key) ?>"<?= $entityType === $key ? ' selected' : '' ?>><?= pl_e($label) ?></option><?php endforeach; ?></select><p class="muted" id="entity-type-help">This guides the period explanation only. It does not decide legal status or activate tax rules.</p></div>
                <div class="field full-width" data-fiscal-year-end>
                    <label class="field-label" for="fiscal-year-end-choice">Financial year end</label>
                    <select class="select" id="fiscal-year-end-choice" name="fiscal_year_end_choice" required aria-describedby="fiscal-help" data-fiscal-year-end-choice data-fiscal-custom-target="#fiscal-year-end-custom">
                        <?php foreach (pl_fiscal_year_end_options() as $key => $label): ?><option value="<?= pl_e($key) ?>"<?= $fiscalYearEndChoice === $key ? ' selected' : '' ?>><?= pl_e($label) ?></option><?php endforeach; ?>
                    </select>
                    <div class="fiscal-custom-group" data-fiscal-custom-group>
                        <label class="field-label" for="fiscal-year-end-custom">Custom year end</label>
                        <input class="input" id="fiscal-year-end-custom" name="fiscal_year_end_custom" type="text" inputmode="numeric" maxlength="5" value="<?= pl_e($fiscalYearEndCustom) ?>" placeholder="MM-DD" aria-describedby="fiscal-help">
                    </div>
                    <p class="muted" id="fiscal-help"><?= pl_e($entityYearEndGuidance) ?> This sets the book period boundary; it does not activate country tax rules.</p>
                </div>
            <?php else: ?>
                <fieldset class="field full-width">
                    <legend>Which chart starts this book?</legend>
                    <label class="checkbox setup-option"><input type="radio" name="chart_choice" value="neutral"<?= $chartChoice === 'neutral' ? ' checked' : '' ?>> <span><b>Use the neutral starter chart</b><small>Start with PHP Ledger’s compact country-neutral accounts and review the exact names and codes next.</small></span></label>
                    <?php if ($startMode === 'existing'): ?>
                        <label class="checkbox setup-option"><input type="radio" name="chart_choice" value="bring_own"<?= $chartChoice === 'bring_own' ? ' checked' : '' ?>> <span><b>Prepare to bring my existing chart</b><small>Keep the existing chart as the opening-conversion source; review its mapping after this setup.</small></span></label>
                    <?php else: ?>
                        <p class="muted">A bring-your-own chart belongs to the past-records opening workflow. This starting point uses the neutral chart.</p>
                    <?php endif; ?>
                </fieldset>
                <div class="field full-width"><label class="checkbox"><input type="checkbox" name="zero_balances_confirmed" value="1"<?= $zeroConfirmed ? ' checked' : '' ?>> For a new business, I confirm there are no opening balances or unpaid invoices/bills to bring forward.</label><p class="muted">Leave this unchecked when bringing past records. Sample companies do not require this confirmation.</p></div>
            <?php endif; ?>

            <div class="panel-actions full-width" data-fold="primary action">
                <?php if ($activeStep > 1): ?><a class="btn btn-secondary" href="<?= pl_e(pl_url('/onboarding?step=' . ($activeStep - 1))) ?>">Back</a><?php endif; ?>
                <button class="btn btn-primary" type="submit"><?= $activeStep === 4 ? 'Preview chart and setup' : 'Continue' ?></button>
                <?php if ($activeStep === 1): ?><a class="btn btn-secondary" href="<?= pl_e(pl_url('/companies')) ?>">Cancel</a><?php endif; ?>
            </div>
        </form>
    <?php elseif ($preview): ?>
        <div class="setup-preview-panel">
            <div class="section-heading"><div><p class="eyebrow">Step 5 of 6</p><h2>Everything is ready for your confirmation</h2></div><span class="badge">Not created yet</span></div>
            <dl class="form-grid setup-summary">
                <div><dt>Starting point</dt><dd><?= pl_e($startLabels[$startMode] ?? $startMode) ?></dd></div>
                <div><dt>Business or entity type</dt><dd><?= pl_e($entityTypes[$entityType] ?? $entityType) ?></dd></div>
                <div><dt>Base currency</dt><dd><?= pl_e($baseCurrency) ?></dd></div>
                <div><dt>Accounting start</dt><dd><?= pl_e(pl_date_label($startDate)) ?></dd></div>
                <div><dt>Financial year end</dt><dd><?= pl_e(pl_fiscal_year_end_label($fiscalYearEnd)) ?></dd></div>
                <div><dt>Chart choice</dt><dd><?= $chartChoice === 'bring_own' ? 'Prepare to bring my existing chart' : 'Neutral starter chart' ?></dd></div>
            </dl>
            <?php if ($startMode === 'existing'): ?><div class="alert alert-warning"><h3>Next: opening conversion</h3><p>This saves the business shell first. Then map the existing chart and opening trial balance, reconcile unpaid invoices/bills and confirm cutover before posting.</p></div><?php elseif ($startMode === 'sample'): ?><p class="alert alert-warning">This creates a separate, clearly marked sample company with fictional records. Existing companies are not changed.</p><?php else: ?><p>No prior balances or unpaid documents will be loaded. These books start empty.</p><?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($activeStep >= 4 || $preview): ?>
        <section class="setup-chart-panel" aria-labelledby="starter-title">
            <p class="eyebrow">Step 4 · Chart preview</p>
            <h2 id="starter-title"><?= pl_e((string) $template['name']) ?></h2>
            <p><?= pl_e((string) $template['notice']) ?></p>
            <p class="muted">Version <?= pl_e((string) $template['version']) ?>. This exact chart snapshot is recorded with the setup.</p>
            <?php if ($chartChoice === 'bring_own'): ?><p class="alert alert-warning">Your existing chart is the opening-conversion source. The neutral chart below is the temporary setup shell; no existing account is reclassified or imported on this confirmation.</p><?php endif; ?>
            <div class="table-wrap" tabindex="0" role="region" aria-label="Starter chart of accounts"><table class="table"><caption><?= $chartChoice === 'bring_own' ? 'Temporary setup chart snapshot' : 'Accounts that will be created for this business' ?></caption><thead><tr><th scope="col">Code</th><th scope="col">Account</th><th scope="col">Type</th></tr></thead><tbody><?php foreach ($template['accounts'] as $definition): ?><tr><td><?= pl_e((string) $definition['code']) ?></td><td><?= pl_e((string) $definition['name']) ?></td><td><?= pl_e(ucfirst((string) $definition['type'])) ?></td></tr><?php endforeach; ?></tbody></table></div>
        </section>
    <?php endif; ?>

    <?php if ($preview): ?>
        <form action="<?= pl_e(pl_url('/onboarding')) ?>" method="post" class="panel-actions setup-confirm-actions" data-fold="primary action">
            <?= pl_csrf_field() ?><input type="hidden" name="action" value="confirm">
            <button class="btn btn-primary" type="submit"><?= $startMode === 'sample' ? 'Step 6: Create separate sample company' : ($startMode === 'existing' ? 'Step 6: Save and prepare opening conversion' : 'Step 6: Create business and accounts') ?></button>
            <a class="btn btn-secondary" href="<?= pl_e(pl_url('/onboarding?step=4')) ?>">Edit chart choice</a>
        </form>
    <?php endif; ?>
</div></div>
</section>
