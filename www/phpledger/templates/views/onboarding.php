<?php
declare(strict_types=1);
/*
 * Onboard a business, in the installer's own column (owner review of 25-26 September 2026,
 * frames in docs/design/setup-1.4.5). One question per stage, sized for a laptop without
 * scrolling; the tray says how far along this is; every "?" opens beside the thing it explains.
 *
 * Every string here goes through pl_t(). The wizard is the first thing a new operator sees, so
 * it is the last screen that should be untranslatable.
 *
 * What the chosen source brings in arrives as $sourceView, not $view: pl_render() extracts the
 * route's data with EXTR_SKIP and already holds $view as the template's own name.
 */
/** @var array<string, mixed> $input */
$values = $input;
$field = static fn (string $name, string $default = ''): string => is_string($values[$name] ?? null) ? (string) $values[$name] : $default;
$tick = pl_icon('check');
$stageKeys = array_keys($stages);
$position = (int) array_search($stage, $stageKeys, true);
$required = '<span class="required-marker" aria-hidden="true">*</span>';
$optional = '<span class="optional">' . pl_e(pl_t('(optional)')) . '</span>';
$currency = $field('currency', 'USD');
$startChoices = pl_onboarding_start_choices();
$startChoice = $field('start_choice', 'fresh');
$formHelp = static function (string $concept, string $placement = 'start'): void { pl_ui_help($concept, $placement); };
?>
<section class="bench-stage wp-card" aria-labelledby="onboarding-title">
<?php if ($stage !== 'ready'): ?>
<div class="bench-chrome wp-chrome">
<ol class="bench-tray is-labelled" aria-label="<?= pl_e(pl_t('Business setup stages')) ?>">
<?php foreach (array_slice($stageKeys, 0, 5) as $index => $key): ?>
<li class="tray-slot <?= $index === $position ? 'is-current' : ($index < $position ? 'is-done' : 'is-pending') ?>"<?= $index === $position ? ' aria-current="step"' : '' ?>><?= $index < $position ? $tick : '' ?><span><?= pl_e(pl_t($stages[$key])) ?></span></li>
<?php endforeach; ?>
</ol>
<span class="wp-step"><?= pl_e(pl_t('Stage {n} of 5', ['n' => $position + 1])) ?></span>
</div>
<?php endif; ?>

<?php if ($form['message'] !== ''): ?>
<div class="alert alert-warning" role="alert" tabindex="-1" id="setup-error" data-form-error>
    <div><h2><?= pl_e(pl_t('Check this stage')) ?></h2><p><?= pl_e((string) $form['message']) ?></p>
    <p><?= pl_e(pl_t('What you entered has been kept, so you can correct it.')) ?></p></div>
</div>
<?php endif; ?>

<?php if ($stage === 'ready'): ?>
<div class="bench-body"><div class="done-body">
    <div>
        <h1 class="bench-heading size-lg" id="onboarding-title"><?= pl_e(pl_t('{business} is ready.', ['business' => (string) $created['name']])) ?></h1>
        <p class="bench-sub"><?php if ($created['is_sample']): ?><?= pl_e(pl_t('This sample company includes fictional history. The recorded totals are shown below.')) ?><?php elseif ($receipt !== null): ?><?= pl_e(pl_t('Created from the {sample} structure, with none of its transactions. You are signed in as its owner; Home shows what to do next.', ['sample' => (string) (pl_demo_sample((string) $receipt['sample_id'])['name'] ?? $receipt['sample_id'])])) ?><?php else: ?><?= pl_e(pl_t('You are signed in as its owner. Home shows what to do next.')) ?><?php endif; ?></p>
    </div>
    <ul class="manifest-card reveal">
    <?php foreach ($manifest as $line): ?>
        <li class="manifest-row"><?= $tick ?><?= pl_e($line) ?></li>
    <?php endforeach; ?>
    </ul>
</div></div>
<div class="bench-actions">
    <a class="btn btn-secondary" href="<?= pl_e(pl_url('/onboarding')) ?>"><?= pl_e(pl_t('Set up another business')) ?></a>
    <a class="btn btn-primary" href="<?= pl_e(pl_url($created['setup_status'] === 'opening_required' ? '/opening-balances' : '/home')) ?>"><?= pl_e(pl_t('Open {business}', ['business' => (string) $created['name']])) ?></a>
</div>

<?php elseif ($stage === 'review'): $summary = $sourceView['summary']; $owners = is_array($values['owners'] ?? null) ? $values['owners'] : []; $money = is_array($values['money_accounts'] ?? null) ? $values['money_accounts'] : []; $profile = is_array($values['profile'] ?? null) ? $values['profile'] : []; $chosen = is_array($values['features'] ?? null) ? $values['features'] : []; $openingTotal = '0.0000'; foreach ($money as $row) { if (($row['opening_amount'] ?? '') !== '') { $openingTotal = bcadd($openingTotal, (string) $row['opening_amount'], 4); } } ?>
<div class="bench-head wp-head"><div class="wp-title-row"><h1 class="bench-heading" id="onboarding-title"><?= pl_e(pl_t('Check everything before it is created')) ?></h1><?php pl_ui_help('onboarding-review', 'start'); ?></div>
    <p class="bench-sub"><?= pl_e(pl_t('The last chance to change anything: Back keeps what you typed. Confirming records exactly what is listed here.')) ?></p></div>
<div class="bench-body"><div class="review-split">
<div class="min-w-0">
    <dl class="summary-grid">
        <div><dt><?= pl_e(pl_t('Business')) ?></dt><dd><?= pl_e($field('name')) ?></dd></div>
        <div><dt><?= pl_e(pl_t('Legal form')) ?></dt><dd><?= pl_e($field('legal_form') === '' ? pl_t('Chosen later') : pl_legal_form_label($field('legal_form'))) ?></dd></div>
        <div><dt><?= pl_e(pl_t('Country')) ?></dt><dd><?= pl_e($field('country_code') === '' ? pl_t('Not stated') : (pl_country_options()[$field('country_code')] ?? $field('country_code'))) ?></dd></div>
        <div><dt><?= pl_e(pl_t('Currency')) ?></dt><dd><?= pl_e($currency) ?></dd></div>
        <div><dt><?= pl_e(pl_t('Financial year end')) ?></dt><dd><?= pl_e(pl_fiscal_year_end_label($sourceView['source'] === 'full' ? '12-31' : $field('fiscal_year_end', '12-31'))) ?></dd></div>
        <div><dt><?= pl_e(pl_t('Accounting start')) ?></dt><dd><?= pl_e(pl_date_label($sourceView['source'] === 'full' && $sourceView['sample'] !== null ? (string) $sourceView['sample']['start_date'] : $field('start_date'))) ?></dd></div>
        <div><dt><?= pl_e(pl_t('Starting point')) ?></dt><dd><?= pl_e(match ($sourceView['source']) { 'skeleton' => pl_t('{sample}\'s structure', ['sample' => (string) ($sourceView['sample']['name'] ?? '')]), 'full' => pl_t('Full sample: {sample}', ['sample' => (string) ($sourceView['sample']['name'] ?? '')]), default => ($field('start_mode') === 'existing' ? pl_t('Past records: opening cutover follows') : pl_t('New business, starts at zero')) }) ?></dd></div>
        <div><dt><?= pl_e(pl_t('Features')) ?></dt><dd><?= pl_e($chosen === [] ? pl_t('Accounting core only') : implode(', ', array_map('pl_module_label', $chosen))) ?></dd></div>
        <?php if (($profile['legal_name'] ?? '') !== '' || ($profile['tax_registrations'] ?? '') !== ''): ?>
        <div><dt><?= pl_e(pl_t('On invoices')) ?></dt><dd><?= pl_e(trim(($profile['legal_name'] ?? '') . ' · ' . ($profile['tax_registrations'] ?? ''), ' ·')) ?></dd></div>
        <?php endif; ?>
    </dl>
    <?php if ($money !== []): ?>
    <h2 class="section-title mt-3 mb-2"><?= pl_e(pl_t('Bank and cash accounts')) ?></h2>
    <div class="table-wrap" tabindex="0"><table class="table"><thead><tr><th><?= pl_e(pl_t('Account')) ?></th><th><?= pl_e(pl_t('Kind')) ?></th><th class="amount"><?= pl_e(pl_t('Opening ({currency})', ['currency' => $currency])) ?></th><th><?= pl_e(pl_t('From')) ?></th></tr></thead><tbody>
    <?php foreach ($money as $row): ?>
        <tr><td><?= pl_e((string) $row['name']) ?><?= ($row['custodian'] ?? '') !== '' && stripos((string) $row['name'], (string) $row['custodian']) === false ? ' — ' . pl_e((string) $row['custodian']) : '' ?></td><td><?= pl_e(match ($row['kind']) { 'bank' => pl_t('Bank account'), 'till' => pl_t('Cash till'), default => pl_t('Petty cash') }) ?></td><td class="amount"><?= pl_e(($row['opening_amount'] ?? '') === '' ? '0.00' : pl_money((string) $row['opening_amount'])) ?></td><td><?= pl_e(($row['opening_source'] ?? '') === '' ? '—' : pl_owner_transaction_kinds()[$row['opening_source']]['label']) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
    <?php if ($owners !== []): ?>
    <p class="field-hint"><?= pl_e(pl_t('Owners: {owners}', ['owners' => implode(', ', array_map(static fn (array $owner): string => $owner['name'] . (($owner['share'] ?? '') !== '' ? ' ' . $owner['share'] . '%' : ''), $owners))])) ?></p>
    <?php endif; ?>
    <?php if ($sourceView['source'] === 'full'): ?>
    <p class="alert alert-warning" role="note"><?= pl_e(pl_t('A full sample replays a pinned fictional history, so its books begin where that history begins. The accounting start and year end above are the sample\'s own, not the ones you entered.')) ?></p>
    <?php endif; ?>
</div>
<div class="review-side">
    <div class="contents-card">
        <h2><?= pl_e(pl_t('When you confirm')) ?></h2>
        <ul class="will-do">
            <li><?= pl_icon('circle-check') ?><span><?= pl_e(pl_t('{business} and its primary book in {currency}, starting {date}', ['business' => $field('name'), 'currency' => $currency, 'date' => pl_date_label($sourceView['source'] === 'full' && $sourceView['sample'] !== null ? (string) $sourceView['sample']['start_date'] : $field('start_date'))])) ?></span></li>
            <li><?= pl_icon('circle-check') ?><span><?= pl_e($sourceView['source'] === 'skeleton' && $summary !== []
                ? pl_t('The neutral chart plus {count} accounts, {parties} customer and supplier records and {products} products from {sample}, all at zero', ['count' => (int) $summary['accounts'], 'parties' => (int) $summary['parties'], 'products' => (int) $summary['products'], 'sample' => (string) $sourceView['sample']['name']])
                : pl_t('The neutral chart: {count} accounts every new book is created with', ['count' => count($template['accounts'])])) ?></span></li>
            <?php if ($money !== []): ?><li><?= pl_icon('circle-check') ?><span><?= pl_e(pl_tn('{count} named bank or cash account under the "Cash and cash equivalents" group; the group itself never takes an entry', '{count} named bank and cash accounts under the "Cash and cash equivalents" group; the group itself never takes an entry', count($money), ['count' => count($money)])) ?></span></li><?php endif; ?>
            <?php if ($owners !== []): ?><li><?= pl_icon('circle-check') ?><span><?= pl_e(pl_tn('{count} owner in the ownership register', '{count} owners in the ownership register', count($owners), ['count' => count($owners)])) ?></span></li><?php endif; ?>
            <?php if (bccomp($openingTotal, '0', 4) > 0): ?><li><?= pl_icon('circle-check') ?><span><?= pl_e(pl_t('Opening money of {currency} {amount} posted on {date} as capital or owner loans, into the accounts named above', ['currency' => $currency, 'amount' => pl_money($openingTotal), 'date' => pl_date_label($field('start_date'))])) ?></span></li><?php endif; ?>
            <?php if (($profile['legal_name'] ?? '') !== '' || ($profile['address_line1'] ?? '') !== ''): ?><li><?= pl_icon('circle-check') ?><span><?= pl_e(pl_t('Invoice details saved to Company profile')) ?></span></li><?php endif; ?>
            <?php if ($field('start_mode') !== 'sample'): ?><li><?= pl_icon('circle-check') ?><span><?= pl_e(pl_t('Cash policy strict: money leaves an account only when it is there. Change it later in Accounting policies.')) ?></span></li><?php endif; ?>
        </ul>
    </div>
    <div class="contents-card is-muted">
        <h2><?= pl_e(pl_t('Not created')) ?></h2>
        <p class="field-hint"><?= pl_e(match (true) {
            $sourceView['source'] === 'skeleton' => pl_t('No invoices, bills, stock or transactions. The sample\'s history stays out; only its structure comes across, at zero.'),
            $sourceView['source'] === 'full' => pl_t('Nothing else: the sample brings its complete fictional history into a separate, clearly marked company.'),
            $field('start_mode') === 'existing' => pl_t('No balances yet. After this you map your chart, enter the opening trial balance and reconcile unpaid documents in the opening cutover.'),
            default => pl_t('No invoices, bills, stock or transactions. These books start empty.'),
        }) ?></p>
    </div>
</div>
</div></div>
<div class="bench-actions">
    <span class="fine-print"><?= pl_e(pl_t('Confirming records this exact setup.')) ?></span>
    <form method="post" action="<?= pl_e(pl_url('/onboarding')) ?>" class="bench-action-pair">
        <?= pl_csrf_field() ?><input type="hidden" name="action" value="confirm">
        <a class="btn btn-secondary" href="<?= pl_e(pl_url('/onboarding', ['stage' => 'features'])) ?>"><?= pl_e(pl_t('Back')) ?></a>
        <button class="btn btn-primary" type="submit"><?= pl_e($sourceView['source'] === 'full' ? pl_t('Create the sample company') : pl_t('Create {business}', ['business' => $field('name')])) ?></button>
    </form>
</div>

<?php else: ?>
<form action="<?= pl_e(pl_url('/onboarding')) ?>" method="post" id="stage-form" data-ready-button="#stage-continue"<?= $stage === 'business' ? ' data-legal-forms' : '' ?>>
<?= pl_csrf_field() ?>
<input type="hidden" name="action" value="next">
<input type="hidden" name="stage" value="<?= pl_e($stage) ?>">

<?php if ($stage === 'start'): $structureChosen = $startChoice === 'structure' || $startChoice === 'sample'; ?>
<div class="bench-head wp-head"><div class="wp-title-row"><h1 class="bench-heading" id="onboarding-title"><?= pl_e(pl_t('What are you starting from?')) ?></h1><?php pl_ui_help('onboarding-start', 'start'); ?></div>
    <p class="bench-sub"><?= pl_e(pl_t('Nothing is created until the last stage. You can leave and come back.')) ?></p></div>
<div class="bench-body">
<fieldset class="choice-grid is-four">
    <legend class="sr-only"><?= pl_e(pl_t('Starting point')) ?></legend>
    <?php foreach ([
        'fresh' => [pl_t('New business, starts at zero'), pl_t('No balances, no unpaid documents. Choosing this confirms it.'), pl_t('Most common')],
        'structure' => [pl_t('A sample company\'s structure'), pl_t('Its chart, modules, contacts and products. Zero balances.'), pl_t('Choose the company below')],
        'existing' => [pl_t('Bring past records'), pl_t('Balances and open documents come in afterwards.'), pl_t('Opening cutover follows')],
        'sample' => [pl_t('Explore a full sample'), pl_t('Fictional history in a separate company.'), pl_t('Practice only')],
    ] as $key => [$label, $detail, $tag]): if (!isset($startChoices[$key])) { continue; } ?>
    <label class="choice-card<?= $key === 'sample' ? ' is-quiet' : '' ?>"><input type="radio" name="start" value="<?= pl_e($key) ?>"<?= $startChoice === $key ? ' checked' : '' ?> data-start-choice="<?= $key === 'structure' || $key === 'sample' ? '1' : '0' ?>">
        <span class="choice-body"><span class="choice-title"><?= pl_e($label) ?></span><span class="choice-detail"><?= pl_e($detail) ?></span><span class="choice-tag"><?= pl_e($tag) ?></span></span></label>
    <?php endforeach; ?>
</fieldset>
<div data-gallery<?= $structureChosen ? '' : ' hidden' ?>>
<div class="gallery-head"><h2><?= pl_e(pl_t('Choose the company whose structure you want')) ?></h2>
<?php $installedCount = count(array_filter($gallery, static fn (array $card): bool => $card['installed'])); $missingCount = count($gallery) - $installedCount; $canInstall = $administers && !pl_sample_packages_readonly() && extension_loaded('curl'); ?>
<p><?= pl_e(pl_tn('{count} company', '{count} companies', count($gallery), ['count' => count($gallery)])) ?> · <?php if ($missingCount === 0): ?><?= pl_e(pl_t('all installed on this copy')) ?><?php elseif ($canInstall): ?><?= pl_e(pl_t('{count} installed', ['count' => $installedCount])) ?> · <?= pl_e(pl_t('the rest install here in one click as signed data-only packages (CC0) from phpledger.com and bring you straight back')) ?><?php else: ?><?= pl_e(pl_t('{count} installed', ['count' => $installedCount])) ?> · <?= pl_e(pl_t('an installation administrator installs the rest from phpledger.com')) ?><?php endif; ?> · <a class="link" href="https://phpledger.com/directory/" target="_blank" rel="noopener noreferrer"><?= pl_e(pl_t('browse the directory')) ?></a></p>
<fieldset class="sample-gallery"><legend class="sr-only"><?= pl_e(pl_t('Sample company')) ?></legend>
<?php foreach ($gallery as $card): $selectable = $card['installed'] && ($startChoice !== 'structure' || $card['skeleton']); ?>
    <label class="sample-card<?= $selectable ? '' : ' is-quiet' ?>"><?php if ($selectable): ?><input type="radio" name="sample_pack" value="<?= pl_e($card['id']) ?>"<?= $field('sample_pack') === $card['id'] ? ' checked' : '' ?>><?php endif; ?>
        <span class="sample-card-top"><?php if ($card['logo'] !== ''): ?><img src="<?= pl_e(pl_url($card['logo'])) ?>" alt="" width="26" height="26"><?php endif; ?><span><span class="sample-card-name"><?= pl_e($card['name']) ?></span><?php if ($card['business'] !== ''): ?><br><span class="sample-card-kind"><?= pl_e($card['business']) ?></span><?php endif; ?></span></span>
        <?php if ($card['story'] !== ''): ?><p class="sample-card-story"><?= pl_e($card['story']) ?></p><?php endif; ?>
        <?php if ($card['accounts'] > 0 && (!$card['installed'] || $card['skeleton'])): ?><p class="sample-card-brings"><?= pl_e(pl_t('{accounts} accounts · {parties} contacts', ['accounts' => $card['accounts'], 'parties' => $card['parties']])) ?></p><?php elseif ($card['installed']): ?><p class="sample-card-brings"><?= pl_e(pl_t('Full sample only')) ?></p><?php endif; ?>
        <span class="sample-card-state">
        <?php if ($card['installed']): ?><span class="badge badge-posted"><span class="badge-dot"></span><?= pl_e(pl_t('Installed')) ?></span>
        <?php else: ?><span class="badge badge-unpaid"><?= pl_e(pl_t('On phpledger.com')) ?></span>
            <?php if (!empty($card['installable'])): ?><button class="btn btn-secondary" type="submit" form="install-<?= pl_e($card['id']) ?>"><?= pl_e(pl_t('Install {version}', ['version' => $card['version']])) ?></button><?php endif; ?>
        <?php endif; ?>
        </span></label>
<?php endforeach; ?>
</fieldset>
<?php if ($canInstall): ?><p class="field-hint"><?= pl_e(pl_t('Install works from here and brings you straight back. Refresh the directory to see newly published samples; refreshing and installing contact phpledger.com, opening this page does not.')) ?> <button class="link" type="submit" form="refresh-directory"><?= pl_e(pl_t('Refresh directory')) ?></button></p><?php endif; ?>
</div>
</div>
<div class="bench-actions"><span class="fine-print"><?= pl_e(pl_t('Nothing is created yet.')) ?></span>
<span class="bench-action-pair"><a class="btn btn-secondary" href="<?= pl_e(pl_url('/companies')) ?>"><?= pl_e(pl_t('Cancel')) ?></a>
<button class="btn btn-primary" id="stage-continue" type="submit"><?= pl_e(pl_t('Continue')) ?></button></span></div>

<?php elseif ($stage === 'business'): $country = strtoupper($field('country_code')); $countryProfile = pl_legal_form_country_profile($country); $labels = $countryProfile['labels']; $placeholders = $countryProfile['placeholders']; $legalForm = $field('legal_form'); if ($legalForm === '' && !array_key_exists('legal_form', $values)) { $legalForm = pl_legal_form_default($country); } $profile = is_array($values['profile'] ?? null) ? $values['profile'] : []; $pf = static fn (string $key): string => is_string($values[$key] ?? null) ? (string) $values[$key] : (string) ($profile[$key] ?? ''); $yearEndChoice = $field('fiscal_year_end_choice'); if ($yearEndChoice === '') { $yearEndChoice = array_key_exists($field('fiscal_year_end'), pl_fiscal_year_end_options()) ? $field('fiscal_year_end') : ($countryProfile['fiscal_year_end'] ?? '12-31'); } $suggestedCurrency = $field('currency', (string) ($countryProfile['currency'] ?? ($regional['currency'] ?? 'USD'))); ?>
<div class="bench-head wp-head"><div class="wp-title-row"><h1 class="bench-heading" id="onboarding-title"><?= pl_e(pl_t('Name this business')) ?></h1><?php pl_ui_help('onboarding-business', 'start'); ?></div>
    <p class="bench-sub"><?= pl_e(pl_t('Country first: the legal forms, the registrar and the invoice numbers are shown in that country\'s own words. Every suggestion can be changed.')) ?></p></div>
<div class="bench-body"><div class="o2-columns">
<div class="o2-main"><div class="wp-grid">
    <div class="field span-6"><div class="inline-heading"><label class="field-label" for="business-name"><?= pl_e(pl_t('Business name')) ?> <?= $required ?></label><?php pl_ui_help('onboarding-business-name', 'start'); ?></div>
        <input class="input" id="business-name" name="name" type="text" maxlength="160" autocomplete="organization" required value="<?= pl_e($field('name')) ?>"></div>
    <div class="field span-6"><div class="inline-heading"><label class="field-label" for="country-code"><?= pl_e(pl_t('Country')) ?></label><?php pl_ui_help('onboarding-country', 'start'); ?><?php if (($regional['status'] ?? '') === 'detected' && $regional['country_code'] === $country): ?><span class="field-chip"><?= pl_e(pl_t('Suggested')) ?></span><?php endif; ?></div>
        <select class="select" id="country-code" name="country_code"><option value=""><?= pl_e(pl_t('Choose later')) ?></option>
        <?php foreach ($countries as $code => $label): ?><option value="<?= pl_e($code) ?>"<?= $country === $code ? ' selected' : '' ?>><?= pl_e($label) ?></option><?php endforeach; ?>
        </select></div>
    <div class="field span-12"><div class="inline-heading"><label class="field-label" for="legal-form"><?= pl_e(pl_t('Legal form')) ?></label><?php pl_ui_help('onboarding-legal-form', 'start'); ?><span class="field-chip" data-country-registrar><?= pl_e(pl_legal_form_chip((string) $countryProfile['code'], (string) $countryProfile['name'])) ?></span></div>
        <select class="select" id="legal-form" name="legal_form"><option value=""><?= pl_e(pl_t('Choose later')) ?></option>
        <?php foreach ($legalForms as $key => $label): ?><option value="<?= pl_e($key) ?>"<?= $legalForm === $key ? ' selected' : '' ?>><?= pl_e($label) ?></option><?php endforeach; ?>
        </select>
        <p class="form-guide" data-form-guide><?= pl_e($legalForm === '' ? pl_t('Choose the form your registration papers use; the guide for it appears here.') : pl_legal_form_guide($legalForm)) ?></p></div>
    <div class="field span-4"><div class="inline-heading"><label class="field-label" for="base-currency"><?= pl_e(pl_t('Currency')) ?> <?= $required ?></label><?php pl_ui_help('onboarding-currency', 'start'); ?></div>
        <select class="select" id="base-currency" name="currency" required>
        <?php foreach (pl_base_currency_options() as $code => $label): ?><option value="<?= pl_e($code) ?>"<?= $suggestedCurrency === $code ? ' selected' : '' ?>><?= pl_e($label) ?></option><?php endforeach; ?>
        </select></div>
    <div class="field span-4" data-fiscal-year-end><div class="inline-heading"><label class="field-label" for="fiscal-year-end-choice"><?= pl_e(pl_t('Financial year end')) ?> <?= $required ?></label><?php pl_ui_help('onboarding-year-end', 'start'); ?></div>
        <select class="select" id="fiscal-year-end-choice" name="fiscal_year_end_choice" required data-fiscal-year-end-choice data-fiscal-custom-target="#fiscal-year-end-custom">
        <?php foreach (pl_fiscal_year_end_options() as $key => $label): ?><option value="<?= pl_e($key) ?>"<?= $yearEndChoice === $key ? ' selected' : '' ?>><?= pl_e($label) ?></option><?php endforeach; ?>
        </select>
        <div class="fiscal-custom-group" data-fiscal-custom-group><label class="field-label" for="fiscal-year-end-custom"><?= pl_e(pl_t('Custom year end')) ?></label>
            <input class="input" id="fiscal-year-end-custom" name="fiscal_year_end_custom" type="text" inputmode="numeric" maxlength="5" value="<?= pl_e($field('fiscal_year_end_custom', $field('fiscal_year_end'))) ?>" placeholder="<?= pl_e(pl_t('MM-DD')) ?>"></div></div>
    <div class="field span-4"><div class="inline-heading"><label class="field-label" for="accounting-start"><?= pl_e(pl_t('Accounting start date')) ?> <?= $required ?></label><?php pl_ui_help('onboarding-start-date', 'end'); ?></div>
        <input class="input" id="accounting-start" name="start_date" type="date" required value="<?= pl_e($field('start_date', gmdate('Y-m-d'))) ?>"></div>
</div></div>
<div class="o2-side"><div class="side-panel"><div class="side-panel-head"><h2><?= pl_e(pl_t('Printed on invoices and receipts')) ?></h2><span class="optional"><?= pl_e(pl_t('optional · editable later in Company profile')) ?></span></div><div class="wp-grid">
    <div class="field span-6"><div class="inline-heading"><label class="field-label" for="legal-name"><?= pl_e(pl_t('Legal name')) ?></label></div><input class="input" id="legal-name" name="legal_name" maxlength="200" value="<?= pl_e($pf('legal_name')) ?>"></div>
    <div class="field span-6"><div class="inline-heading"><label class="field-label" for="registration-number" data-country-label="reg_number"><?= pl_e(pl_t((string) $labels['reg_number'])) ?></label></div><input class="input" id="registration-number" name="registration_number" maxlength="80" value="<?= pl_e($pf('registration_number')) ?>" placeholder="<?= pl_e((string) ($placeholders['reg_number'] ?? '')) ?>" data-country-placeholder="reg_number"></div>
    <div class="field span-6"><div class="inline-heading"><label class="field-label" for="registration-authority" data-country-label="reg_authority"><?= pl_e(pl_t((string) $labels['reg_authority'])) ?></label></div><input class="input" id="registration-authority" name="registration_authority" maxlength="160" value="<?= pl_e($pf('registration_authority')) ?>" placeholder="<?= pl_e((string) preg_replace('/^the /', '', (string) $countryProfile['registrar'])) ?>" data-country-authority></div>
    <div class="field span-3"><div class="inline-heading"><label class="field-label" for="tax1" data-country-label="tax1"><?= pl_e(pl_t((string) $labels['tax1'])) ?></label><?php pl_ui_help('onboarding-tax-numbers', 'start'); ?></div><input class="input" id="tax1" name="tax1" maxlength="80" value="<?= pl_e($pf('tax1')) ?>" placeholder="<?= pl_e((string) ($placeholders['tax1'] ?? '')) ?>" data-country-placeholder="tax1"></div>
    <div class="field span-3" data-country-field="tax2"<?= (string) $labels['tax2'] === '' ? ' hidden' : '' ?>><div class="inline-heading"><label class="field-label" for="tax2" data-country-label="tax2"><?= pl_e((string) $labels['tax2'] === '' ? '' : pl_t((string) $labels['tax2'])) ?></label></div><input class="input" id="tax2" name="tax2" maxlength="80" value="<?= pl_e($pf('tax2')) ?>" placeholder="<?= pl_e((string) ($placeholders['tax2'] ?? '')) ?>" data-country-placeholder="tax2"></div>
    <div class="field span-12"><div class="inline-heading"><label class="field-label" for="address-line1"><?= pl_e(pl_t('Address')) ?></label></div><input class="input" id="address-line1" name="address_line1" maxlength="200" value="<?= pl_e($pf('address_line1')) ?>"></div>
    <div class="field span-6"><div class="inline-heading"><label class="field-label" for="profile-phone"><?= pl_e(pl_t('Phone')) ?></label></div><input class="input" id="profile-phone" name="phone" type="tel" maxlength="80" value="<?= pl_e($pf('phone')) ?>"></div>
    <div class="field span-6"><div class="inline-heading"><label class="field-label" for="profile-email"><?= pl_e(pl_t('Email on documents')) ?></label></div><input class="input" id="profile-email" name="email" type="email" maxlength="190" value="<?= pl_e($pf('email')) ?>"></div>
</div></div></div>
</div>
<script type="application/json" id="legal-forms-data"><?= $legalFormsJson ?></script>
</div>
<div class="bench-actions"><span class="fine-print"><?= pl_e(pl_t('Nothing is created yet.')) ?></span>
<span class="bench-action-pair"><button class="btn btn-secondary" type="submit" name="action" value="back" formnovalidate><?= pl_e(pl_t('Back')) ?></button>
<button class="btn btn-primary" id="stage-continue" type="submit"><?= pl_e(pl_t('Continue')) ?></button></span></div>

<?php elseif ($stage === 'owners'): $legalFamily = pl_legal_form_family($field('legal_form')); $owners = is_array($values['owners'] ?? null) ? $values['owners'] : []; if (is_array($values['owner_name'] ?? null)) { $owners = []; foreach ($values['owner_name'] as $i => $n) { $owners[] = ['name' => (string) $n, 'kind' => (string) ($values['owner_kind'][$i] ?? 'person'), 'role' => (string) ($values['owner_role'][$i] ?? ''), 'share' => (string) ($values['owner_share'][$i] ?? '')]; } } $money = is_array($values['money_accounts'] ?? null) ? $values['money_accounts'] : []; if (is_array($values['money_name'] ?? null)) { $money = []; foreach ($values['money_name'] as $i => $n) { $money[] = ['kind' => (string) ($values['money_kind'][$i] ?? 'bank'), 'code' => (string) ($values['money_code'][$i] ?? ''), 'name' => (string) $n, 'custodian' => (string) ($values['money_custodian'][$i] ?? ''), 'opening_amount' => (string) ($values['money_amount'][$i] ?? ''), 'opening_source' => (string) ($values['money_source'][$i] ?? '')]; } } if ($money === []) { foreach ($moneyExisting as $existing) { $money[] = ['kind' => $existing['kind'], 'code' => $existing['code'], 'name' => $existing['name'], 'custodian' => '', 'opening_amount' => '', 'opening_source' => '']; } } $ownerRows = max(2, count($owners) + 1); $moneyRows = max(3, count($money) + 1); $roles = pl_legal_form_has_partners($legalFamily) ? ['partner' => pl_t('Partner')] : (pl_legal_form_has_shares($legalFamily) ? ['director_shareholder' => pl_t('Director & shareholder'), 'shareholder' => pl_t('Shareholder'), 'director' => pl_t('Director')] : ['owner' => pl_t('Owner'), 'partner' => pl_t('Partner'), 'director' => pl_t('Director')]); ?>
<div class="bench-head wp-head"><div class="wp-title-row"><h1 class="bench-heading" id="onboarding-title"><?= pl_e(pl_t('Owners, bank and cash')) ?></h1><?php pl_ui_help('onboarding-owners', 'start'); ?></div>
    <p class="bench-sub"><?= pl_e($field('source') === 'full' ? pl_t('Who owns {business}. A full sample brings its own bank and cash accounts and its own history.', ['business' => $field('name')]) : pl_t('Who owns {business}, and where its money sits. At least one bank or cash account is needed; opening amounts are optional.', ['business' => $field('name')])) ?></p></div>
<div class="bench-body"><div class="two-up">
<div class="sub-panel"><h2><?= pl_e(pl_t('Owners')) ?> <?php pl_ui_help('onboarding-owner-list', 'start'); ?></h2>
    <table class="mini-table owners"><thead><tr><th><?= pl_e(pl_t('Name')) ?></th><th><?= pl_e(pl_t('Role')) ?></th><th><?= pl_e(pl_t('Share %')) ?></th></tr></thead><tbody data-rows="owners">
    <?php for ($i = 0; $i < $ownerRows; $i++): $owner = $owners[$i] ?? ['name' => '', 'kind' => 'person', 'role' => '', 'share' => '']; ?>
    <tr><td><input class="input" name="owner_name[]" maxlength="160" value="<?= pl_e((string) $owner['name']) ?>" placeholder="<?= pl_e($i === 0 ? pl_t('Full name') : '') ?>" aria-label="<?= pl_e(pl_t('Owner name')) ?>"><input type="hidden" name="owner_kind[]" value="<?= pl_e(($owner['kind'] ?? 'person') === 'entity' ? 'entity' : 'person') ?>"></td>
        <td><select class="select" name="owner_role[]" aria-label="<?= pl_e(pl_t('Role')) ?>"><?php foreach ($roles as $key => $label): ?><option value="<?= pl_e($label) ?>"<?= (string) $owner['role'] === $label || ((string) $owner['role'] === '' && array_key_first($roles) === $key) ? ' selected' : '' ?>><?= pl_e($label) ?></option><?php endforeach; ?></select></td>
        <td class="is-amount"><input class="input" name="owner_share[]" inputmode="decimal" maxlength="12" value="<?= pl_e((string) $owner['share']) ?>" aria-label="<?= pl_e(pl_t('Share percent')) ?>"></td></tr>
    <?php endfor; ?>
    </tbody></table>
    <div><button type="button" class="btn btn-secondary btn-sm" data-add-row="owners"><?= pl_icon('plus') ?> <?= pl_e(pl_t('Add owner')) ?></button></div>
    <p class="field-hint"><?= pl_e(pl_legal_form_has_shares($legalFamily) ? pl_t('A company: the percentages are a note until shares are issued in the share ledger after setup.') : (pl_legal_form_has_partners($legalFamily) ? pl_t('A partnership: the shares become the partners\' profit-sharing ratio.') : pl_t('Leave the share empty for a sole owner.'))) ?></p></div>
<div class="sub-panel"<?= $field('source') === 'full' ? ' hidden' : '' ?>><h2><?= pl_e(pl_t('Bank and cash accounts')) ?> <?php pl_ui_help('onboarding-money-accounts', 'start'); ?></h2>
    <table class="mini-table money"><thead><tr><th><?= pl_e(pl_t('Kind')) ?></th><th><?= pl_e(pl_t('Name')) ?></th><th><?= pl_e(pl_t('Custodian')) ?></th><th><?= pl_e(pl_t('Opening amount ({currency})', ['currency' => $currency])) ?></th><th><?= pl_e(pl_t('From')) ?></th></tr></thead><tbody data-rows="money">
    <?php for ($i = 0; $i < $moneyRows; $i++): $row = $money[$i] ?? ['kind' => 'bank', 'code' => '', 'name' => '', 'custodian' => '', 'opening_amount' => '', 'opening_source' => '']; ?>
    <tr><td><select class="select" name="money_kind[]" aria-label="<?= pl_e(pl_t('Kind')) ?>"><?php foreach (['bank' => pl_t('Bank account'), 'till' => pl_t('Cash till'), 'petty' => pl_t('Petty cash')] as $key => $label): ?><option value="<?= $key ?>"<?= ($row['kind'] ?? 'bank') === $key ? ' selected' : '' ?>><?= pl_e($label) ?></option><?php endforeach; ?></select><input type="hidden" name="money_code[]" value="<?= pl_e((string) ($row['code'] ?? '')) ?>"></td>
        <td><input class="input" name="money_name[]" maxlength="120" value="<?= pl_e((string) $row['name']) ?>" placeholder="<?= pl_e($i === 0 ? pl_t('e.g. Meezan Bank — current 0123') : ($i === 1 ? pl_t('e.g. Shop till') : '')) ?>" aria-label="<?= pl_e(pl_t('Account name')) ?>"></td>
        <td><input class="input" name="money_custodian[]" maxlength="80" value="<?= pl_e((string) ($row['custodian'] ?? '')) ?>" placeholder="—" aria-label="<?= pl_e(pl_t('Custodian')) ?>"></td>
        <td class="is-amount"><input class="input" name="money_amount[]" inputmode="decimal" maxlength="24" value="<?= pl_e((string) ($row['opening_amount'] ?? '')) ?>" aria-label="<?= pl_e(pl_t('Opening amount')) ?>"<?= $field('start_mode') === 'existing' ? ' disabled' : '' ?>></td>
        <td><select class="select" name="money_source[]" aria-label="<?= pl_e(pl_t('From')) ?>"<?= $field('start_mode') === 'existing' ? ' disabled' : '' ?>><option value="">—</option><?php foreach (['capital_introduced', 'owner_loan_received'] as $kind): ?><option value="<?= $kind ?>"<?= ($row['opening_source'] ?? '') === $kind ? ' selected' : '' ?>><?= pl_e(pl_owner_transaction_kinds()[$kind]['label']) ?></option><?php endforeach; ?></select></td></tr>
    <?php endfor; ?>
    </tbody></table>
    <div><button type="button" class="btn btn-secondary btn-sm" data-add-row="money"><?= pl_icon('plus') ?> <?= pl_e(pl_t('Add account')) ?></button></div>
    <pre class="tree-note"><span class="is-group">1-100  <?= pl_e(pl_t('Cash and cash equivalents')) ?>        <?= pl_e(pl_t('group · never receives an entry')) ?></span>
<?php $shown = 0; foreach ($money as $row): if (($row['name'] ?? '') === '') { continue; } $shown++; ?>  <?= $shown === count(array_filter($money, static fn (array $r): bool => ($r['name'] ?? '') !== '')) ? '└' : '├' ?> <strong><?= pl_e((string) $row['name']) ?></strong>   <?= pl_e(($row['kind'] ?? 'bank') === 'bank' ? pl_t('bank') : pl_t('cash')) ?><?= ($row['custodian'] ?? '') !== '' ? ' · ' . pl_e((string) $row['custodian']) : '' ?>
<?php endforeach; ?><?php if ($shown === 0): ?>  └ <?= pl_e(pl_t('the accounts you name above')) ?>
<?php endif; ?></pre>
    <?php if ($field('start_mode') === 'existing'): ?><p class="field-hint"><?= pl_e(pl_t('Past records bring their balances through the opening cutover after this, so the opening amounts stay empty here.')) ?></p><?php endif; ?></div>
</div></div>
<div class="bench-actions"><span class="fine-print"><?= pl_e(pl_t('Nothing is created yet.')) ?></span>
<span class="bench-action-pair"><button class="btn btn-secondary" type="submit" name="action" value="back" formnovalidate><?= pl_e(pl_t('Back')) ?></button>
<button class="btn btn-primary" id="stage-continue" type="submit"><?= pl_e(pl_t('Continue')) ?></button></span></div>

<?php else: $renames = is_array($values['account_names'] ?? null) ? $values['account_names'] : (is_array($values['account_name'] ?? null) ? $values['account_name'] : []); ?>
<div class="bench-head wp-head"><div class="wp-title-row"><h1 class="bench-heading" id="onboarding-title"><?= pl_e(pl_t('Features and accounts')) ?></h1><?php pl_ui_help('onboarding-features', 'start'); ?></div>
    <p class="bench-sub"><?= pl_e(pl_t('Turn on what {business} needs; everything else can be switched on later in Modules. Rename accounts now if you like; codes and classifications stay.', ['business' => $field('name')])) ?></p></div>
<div class="bench-body">
<fieldset class="feature-grid"><legend class="sr-only"><?= pl_e(pl_t('Features')) ?></legend>
    <label class="choice-card"><input type="checkbox" checked disabled><span class="choice-body"><span class="choice-title"><?= pl_e(pl_t('Accounting core')) ?></span><span class="choice-detail"><?= pl_e(pl_t('Journals, reports, periods. Always on.')) ?></span></span></label>
    <?php foreach ($features as $feature): ?>
    <label class="choice-card"><input type="checkbox" name="features[]" value="<?= pl_e($feature['id']) ?>"<?= $feature['checked'] ? ' checked' : '' ?><?= $feature['required'] || $feature['problem'] !== '' ? ' disabled' : '' ?>><?php if ($feature['required']): ?><input type="hidden" name="features[]" value="<?= pl_e($feature['id']) ?>"><?php endif; ?>
        <span class="choice-body"><span class="choice-title"><?= pl_e($feature['name']) ?></span><span class="choice-detail"><?= pl_e($feature['required'] ? pl_t('Required by the sample structure') : ($feature['problem'] !== '' ? $feature['problem'] : ($feature['description'] !== '' ? $feature['description'] : ($feature['requires'] === [] ? '' : pl_t('Needs {modules}', ['modules' => implode(', ', array_map('pl_module_label', array_filter($feature['requires'], static fn (string $id): bool => $id !== 'core')))]))))) ?></span></span></label>
    <?php endforeach; ?>
</fieldset>
<?php $family = pl_legal_form_family($field('legal_form')); $countryName = $field('country_code') === '' ? '' : (pl_country_options()[$field('country_code')] ?? ''); if ($family !== '' && $countryName !== ''): ?>
<p class="field-hint"><?= pl_e(pl_legal_form_has_shares($family) ? pl_t('{form} in {country}: after setup, register share capital in the share ledger and check the numbers printed on invoices. These choices activate no tax rule.', ['form' => pl_legal_form_label($field('legal_form')), 'country' => $countryName]) : (pl_legal_form_has_partners($family) ? pl_t('{form} in {country}: after setup, record each partner\'s capital under Owner and partners. These choices activate no tax rule.', ['form' => pl_legal_form_label($field('legal_form')), 'country' => $countryName]) : pl_t('{form} in {country}: capital you put in is recorded as capital introduced. These choices activate no tax rule.', ['form' => pl_legal_form_label($field('legal_form')), 'country' => $countryName]))) ?></p>
<?php endif; ?>
<div class="gallery-head"><h2><?= pl_e(pl_tn('Chart of accounts · {count} account', 'Chart of accounts · {count} accounts', count(array_filter($chart, static fn (array $row): bool => !$row['heading'])), ['count' => count(array_filter($chart, static fn (array $row): bool => !$row['heading']))])) ?></h2><p><?= pl_e(pl_t('Rename anything now. Codes, classifications and purposes are fixed in this release; unused accounts can be deactivated later without losing history.')) ?></p></div>
<div class="chart-edit" tabindex="0"><table class="table"><thead><tr><th><?= pl_e(pl_t('Code')) ?></th><th><?= pl_e(pl_t('Name')) ?></th><th><?= pl_e(pl_t('Classification')) ?></th></tr></thead><tbody>
<?php foreach ($chart as $row): ?>
    <?php if ($row['heading']): ?><tr class="is-group"><td><?= pl_e($row['code']) ?></td><td><?= pl_e($row['name']) ?></td><td><?= pl_e(pl_t('Group')) ?></td></tr>
    <?php elseif ($row['money'] ?? false): ?><tr><td><?= pl_e($row['code']) ?></td><td><?= pl_e($row['name']) ?> <span class="muted"><?= pl_e(pl_t('named on the Owners stage')) ?></span></td><td><?= pl_e(pl_t(ucfirst($row['type']))) ?></td></tr>
    <?php else: ?><tr><td><?= pl_e($row['code']) ?></td><td><input class="input" name="account_name[<?= pl_e($row['code']) ?>]" maxlength="120" required value="<?= pl_e((string) ($renames[$row['code']] ?? $row['name'])) ?>" aria-label="<?= pl_e(pl_t('Name for {code}', ['code' => $row['code']])) ?>"></td><td><?= pl_e(pl_t(ucfirst($row['type']))) ?></td></tr><?php endif; ?>
<?php endforeach; ?>
</tbody></table></div>
</div>
<div class="bench-actions"><span class="fine-print"><?= pl_e(pl_t('Nothing is created yet.')) ?></span>
<span class="bench-action-pair"><button class="btn btn-secondary" type="submit" name="action" value="back" formnovalidate><?= pl_e(pl_t('Back')) ?></button>
<button class="btn btn-primary" id="stage-continue" type="submit"><?= pl_e(pl_t('Review and continue')) ?></button></span></div>
<?php endif; ?>
</form>
<?php if ($stage === 'start'): ?>
<?php foreach ($gallery as $card): if (empty($card['installable'])) { continue; } ?>
<form method="post" action="<?= pl_e(pl_url('/onboarding')) ?>" id="install-<?= pl_e($card['id']) ?>" class="hidden"><?= pl_csrf_field() ?><input type="hidden" name="action" value="sample_install"><input type="hidden" name="slug" value="<?= pl_e($card['slug']) ?>"><input type="hidden" name="request_key" value="<?= pl_e(bin2hex(random_bytes(16))) ?>"></form>
<?php endforeach; ?>
<?php if ($administers && !pl_sample_packages_readonly()): ?><form method="post" action="<?= pl_e(pl_url('/onboarding')) ?>" id="refresh-directory" class="hidden"><?= pl_csrf_field() ?><input type="hidden" name="action" value="sample_refresh"></form><?php endif; ?>
<?php endif; ?>
<?php endif; ?>
</section>
