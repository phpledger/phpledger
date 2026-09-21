<?php
declare(strict_types=1);
/*
 * Onboard a business, laid out as the Workbench (owner decision B50; frames in
 * docs/design/1.2-2026-09/onboarding-and-packages). One decision per stage, sized for a laptop
 * without scrolling, and the tray at the top says how far along this is.
 *
 * Five stages, not the installer's six: onboarding has no database step, no schema build and no
 * server checks, and padding it out to match a slot count would copy the installer's shape
 * without its content.
 *
 * Every string here goes through pl_t(). The wizard is the first thing a new operator sees, so
 * it is the last screen that should be untranslatable.
 *
 * What the chosen source brings in arrives as $sourceView, not $view: pl_render() extracts the
 * route's data with EXTR_SKIP and already holds $view as the template's own name, so a data key
 * called `view` is silently dropped and the screen reads a string where it expects an array.
 */
/**
 * pl_render() extracts the route's data into this scope, so the draft is copied into a local
 * with a declared shape before anything reads it. Static analysis cannot see an extracted
 * variable, and a helper closing over one it believes is undefined reads every answer as null.
 *
 * @var array<string, mixed> $input
 */
$values = $input;
$field = static fn (string $name, string $default = ''): string => is_string($values[$name] ?? null) ? (string) $values[$name] : $default;
$startMode = $field('start_mode', 'fresh');
$source = $field('source', $startMode === 'sample' ? 'skeleton' : 'blank');
$samplePack = $field('sample_pack');
$tick = pl_icon('check');
$position = (int) array_search($stage, array_keys($stages), true);
$sourceLabels = [
    'blank' => pl_t('Blank business'),
    'skeleton' => pl_t('Skeleton from a sample'),
    'full' => pl_t('Full sample company'),
];
$startLabels = [
    'fresh' => pl_t('New business'),
    'existing' => pl_t('Bring past records'),
    'sample' => pl_t('Explore a sample company'),
];
?>
<section class="bench-stage" aria-labelledby="onboarding-title">
<ol class="bench-tray is-labelled" aria-label="<?= pl_e(pl_t('Business setup stages')) ?>">
<?php foreach (array_values($stages) as $index => $label): ?>
<li class="tray-slot <?= $index === $position ? 'is-current' : ($index < $position ? 'is-done' : 'is-pending') ?>"<?= $index === $position ? ' aria-current="step"' : '' ?>><?= $index < $position ? $tick : '' ?><span><?= pl_e(pl_t($label)) ?></span></li>
<?php endforeach; ?>
</ol>

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
        <p class="bench-sub"><?= pl_e($receipt === null
            ? pl_t('You are signed in as its owner. Nothing has been posted to these books yet.')
            : pl_t('Created from the {sample} structure, with none of its transactions. You are signed in as its owner.', ['sample' => (string) $receipt['sample_id']])) ?></p>
    </div>
    <ul class="manifest-card reveal">
    <?php foreach ($manifest as $line): ?>
        <li class="manifest-row"><?= $tick ?><?= pl_e($line) ?></li>
    <?php endforeach; ?>
    </ul>
    <ul class="next-row reveal">
        <li><a class="next-chip" href="<?= pl_e(pl_url('/accounts')) ?>"><?= pl_e(pl_t('Chart of accounts')) ?></a></li>
        <li><a class="next-chip" href="<?= pl_e(pl_url('/users')) ?>"><?= pl_e(pl_t('Invite a teammate')) ?></a></li>
        <li><a class="next-chip" href="<?= pl_e(pl_url('/modules')) ?>"><?= pl_e(pl_t('Modules')) ?></a></li>
    </ul>
</div></div>
<div class="bench-actions">
    <a class="btn btn-secondary" href="<?= pl_e(pl_url('/onboarding')) ?>"><?= pl_e(pl_t('Set up another business')) ?></a>
    <a class="btn btn-primary" href="<?= pl_e(pl_url($created['setup_status'] === 'opening_required' ? '/opening-balances' : '/home')) ?>"><?= pl_e(pl_t('Go to {business}', ['business' => (string) $created['name']])) ?></a>
</div>

<?php elseif ($stage === 'review'): $summary = $sourceView['summary']; ?>
<div class="bench-head bench-head-split">
    <div>
        <p class="eyebrow"><?= pl_e(pl_t('Business setup · Stage {n} of 5', ['n' => $position + 1])) ?></p>
        <h1 class="bench-heading" id="onboarding-title"><?= pl_e(pl_t('Check everything before it is created')) ?></h1>
        <p class="bench-sub"><?= pl_e(pl_t('This is the last chance to change anything. The chart snapshot below is recorded exactly as shown.')) ?></p>
    </div>
    <span class="badge badge-sample"><?= pl_e(pl_t('Not created yet')) ?></span>
</div>
<div class="bench-body"><div class="review-split">
<div class="min-w-0">
    <dl class="summary-grid">
        <div><dt><?= pl_e(pl_t('Starting point')) ?></dt><dd><?= pl_e($startLabels[$startMode] ?? $startMode) ?></dd></div>
        <div><dt><?= pl_e(pl_t('Source')) ?></dt><dd><?= pl_e($sourceLabels[$sourceView['source']] ?? $sourceView['source']) ?></dd></div>
        <div><dt><?= pl_e(pl_t('Business name')) ?></dt><dd><?= pl_e($field('name')) ?></dd></div>
        <div><dt><?= pl_e(pl_t('Base currency')) ?></dt><dd><?= pl_e($field('currency')) ?></dd></div>
        <div><dt><?= pl_e(pl_t('Business or entity type')) ?></dt><dd><?= pl_e(pl_setup_entity_type_options()[$field('entity_type', 'other')] ?? '') ?></dd></div>
        <div><dt><?= pl_e(pl_t('Accounting start')) ?></dt><dd><?= pl_e(pl_date_label($sourceView['source'] === 'full' && $sourceView['sample'] !== null ? (string) $sourceView['sample']['start_date'] : $field('start_date'))) ?></dd></div>
        <div><dt><?= pl_e(pl_t('Financial year end')) ?></dt><dd><?= pl_e(pl_fiscal_year_end_label($sourceView['source'] === 'full' ? '12-31' : $field('fiscal_year_end', '12-31'))) ?></dd></div>
        <?php if ($sourceView['sample'] !== null): ?>
        <div><dt><?= pl_e(pl_t('Sample company')) ?></dt><dd><?= pl_e((string) $sourceView['sample']['name']) ?> · <?= pl_e((string) $sourceView['sample']['business']) ?></dd></div>
        <?php endif; ?>
    </dl>
    <?php if ($sourceView['source'] === 'full'): ?>
    <p class="alert alert-warning" role="note"><?= pl_e(pl_t('A full sample replays a pinned fictional history, so its books begin where that history begins. The accounting start and year end above are the sample\'s own, not the ones you entered.')) ?></p>
    <?php endif; ?>
    <div class="table-wrap" tabindex="0" role="region" aria-label="<?= pl_e(pl_t('Chart of accounts that will be created')) ?>">
        <table class="table">
            <caption><?= pl_e($sourceView['accounts'] === []
                ? pl_t('{name}, version {version}: the accounts every new book is created with', ['name' => (string) $template['name'], 'version' => (string) $template['version']])
                : pl_t('The accounts this sample adds to the {name}', ['name' => (string) $template['name']])) ?></caption>
            <thead><tr><th scope="col"><?= pl_e(pl_t('Code')) ?></th><th scope="col"><?= pl_e(pl_t('Account')) ?></th><th scope="col"><?= pl_e(pl_t('Type')) ?></th></tr></thead>
            <tbody>
            <?php foreach (($sourceView['accounts'] !== [] ? $sourceView['accounts'] : $template['accounts']) as $definition): ?>
                <tr><td><?= pl_e((string) $definition['code']) ?></td><td><?= pl_e((string) $definition['name']) ?></td><td><?= pl_e(pl_t(ucfirst((string) $definition['type']))) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<div class="review-side">
    <?php if ($sourceView['source'] === 'skeleton' && $summary !== []): ?>
    <div class="contents-card">
        <h2><?= pl_e(pl_t('What the skeleton brings in')) ?></h2>
        <ul class="contents-list">
            <li class="is-in"><?= pl_e(pl_tn('{count} account', '{count} accounts', (int) $summary['accounts'], ['count' => (int) $summary['accounts']])) ?></li>
            <li class="is-in"><?= pl_e($summary['reports'] === [] ? pl_t('The four core reports') : pl_t('Reports: {reports}', ['reports' => implode(', ', $summary['reports'])])) ?></li>
            <li class="is-in"><?= pl_e($summary['series'] === [] ? pl_t('No extra document numbering') : pl_tn('Numbering for {count} document type', 'Numbering for {count} document types', count($summary['series']), ['count' => count($summary['series'])])) ?></li>
            <li class="is-in"><?= pl_e(pl_t('{parties} customer and supplier records, {products} products', ['parties' => (int) $summary['parties'], 'products' => (int) $summary['products']])) ?></li>
            <li class="is-out"><?= pl_e(pl_t('{journals} posted journals and {drafts} drafts from the sample', ['journals' => (int) $sourceView['sample']['journals'], 'drafts' => (int) $sourceView['sample']['drafts']])) ?></li>
            <li class="is-out"><?= pl_e(pl_t('Opening balances and stock')) ?></li>
        </ul>
        <p class="field-hint"><?= pl_e(pl_t('Nothing with a balance or a date moves over. These books start empty and balanced at zero.')) ?></p>
    </div>
    <div class="contents-card is-muted">
        <h2><?= pl_e(pl_t('Modules this source needs')) ?></h2>
        <?php if ($summary['modules'] === [] && $summary['packages'] === []): ?>
        <p class="field-hint"><?= pl_e(pl_t('None. This sample works with the accounting core alone.')) ?></p>
        <?php else: ?>
        <p class="field-hint"><?= pl_e(pl_t('Turned on automatically, because the structure depends on them. You can turn any of them off afterwards.')) ?></p>
        <ul class="contents-list">
        <?php foreach ($summary['modules'] as $module): ?>
            <li class="is-in"><?= pl_e(pl_module_label($module)) ?><span class="snip"><?= pl_e(pl_t('bundled')) ?></span></li>
        <?php endforeach; ?>
        <?php foreach ($summary['packages'] as $package): ?>
            <li class="is-in"><?= pl_e($package) ?><span class="snip"><?= pl_e(pl_t('package')) ?></span></li>
        <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
    <?php elseif ($sourceView['source'] === 'full'): ?>
    <div class="contents-card">
        <h2><?= pl_e(pl_t('What the full sample brings in')) ?></h2>
        <p class="field-hint"><?= pl_e(pl_t('The structure above and the sample\'s complete fictional history: {journals} posted journals and {drafts} open drafts. It is created as a separate, clearly marked sample company, and your other businesses are not changed.', ['journals' => (int) $sourceView['sample']['journals'], 'drafts' => (int) $sourceView['sample']['drafts']])) ?></p>
    </div>
    <?php elseif ($startMode === 'existing'): ?>
    <div class="contents-card">
        <h2><?= pl_e(pl_t('Next: the opening cutover')) ?></h2>
        <p class="field-hint"><?= pl_e(pl_t('This saves the business shell first. Then you map the existing chart and opening trial balance, reconcile unpaid invoices and bills, and confirm the cutover before anything is posted.')) ?></p>
    </div>
    <?php else: ?>
    <div class="contents-card">
        <h2><?= pl_e(pl_t('A blank business')) ?></h2>
        <p class="field-hint"><?= pl_e(pl_t('No prior balances and no unpaid documents are loaded. These books start empty.')) ?></p>
    </div>
    <?php endif; ?>
</div>
</div></div>
<div class="bench-actions">
    <span class="fine-print"><?= pl_e(pl_t('Confirming records this exact chart snapshot with the setup.')) ?></span>
    <form method="post" action="<?= pl_e(pl_url('/onboarding')) ?>" class="bench-action-pair">
        <?= pl_csrf_field() ?><input type="hidden" name="action" value="confirm">
        <a class="btn btn-secondary" href="<?= pl_e(pl_url('/onboarding', ['stage' => 'source'])) ?>"><?= pl_e(pl_t('Back')) ?></a>
        <button class="btn btn-primary" type="submit"><?= pl_e($sourceView['source'] === 'full' ? pl_t('Create the sample company') : pl_t('Create business and accounts')) ?></button>
    </form>
</div>

<?php else: ?>
<form action="<?= pl_e(pl_url('/onboarding')) ?>" method="post" id="stage-form">
<?= pl_csrf_field() ?>
<input type="hidden" name="action" value="next">
<input type="hidden" name="stage" value="<?= pl_e($stage) ?>">

<?php if ($stage === 'start'): ?>
<div class="bench-head">
    <p class="eyebrow"><?= pl_e(pl_t('Business setup · Stage {n} of 5', ['n' => $position + 1])) ?></p>
    <h1 class="bench-heading size-lg" id="onboarding-title"><?= pl_e(pl_t('What are you setting up?')) ?></h1>
    <p class="bench-sub"><?= pl_e(pl_t('This adds one business to your installation. Nothing is created until you confirm on the last stage, and you can leave and come back.')) ?></p>
</div>
<div class="bench-body">
<fieldset class="choice-grid">
    <legend class="sr-only"><?= pl_e(pl_t('Starting point')) ?></legend>
    <label class="choice-card"><input type="radio" name="start_mode" value="fresh"<?= $startMode === 'fresh' ? ' checked' : '' ?>>
        <span class="choice-body"><span class="choice-title"><?= pl_e($startLabels['fresh']) ?></span>
        <span class="choice-detail"><?= pl_e(pl_t('Begin with no opening balances and no unpaid invoices. You choose how much starting structure to bring in on the next stage: a blank chart, or a sample company\'s skeleton.')) ?></span>
        <span class="choice-tag"><?= pl_e(pl_t('Most common')) ?></span></span></label>
    <label class="choice-card"><input type="radio" name="start_mode" value="existing"<?= $startMode === 'existing' ? ' checked' : '' ?>>
        <span class="choice-body"><span class="choice-title"><?= pl_e($startLabels['existing']) ?></span>
        <span class="choice-detail"><?= pl_e(pl_t('Keep prior balances and open documents. This saves the business shell first, then walks through an explicit opening cutover: chart mapping, opening trial balance and unpaid-document reconciliation.')) ?></span>
        <span class="choice-tag"><?= pl_e(pl_t('Separate opening flow')) ?></span></span></label>
    <?php if (pl_sample_companies_allowed()): ?>
    <label class="choice-card"><input type="radio" name="start_mode" value="sample"<?= $startMode === 'sample' ? ' checked' : '' ?>>
        <span class="choice-body"><span class="choice-title"><?= pl_e($startLabels['sample']) ?></span>
        <span class="choice-detail"><?= pl_e(pl_t('Create a separate, clearly marked company with fictional records, isolated from your real businesses. You choose its depth, skeleton or full history, on the next stage.')) ?></span>
        <span class="choice-tag"><?= pl_e(pl_t('For exploring, not real records')) ?></span></span></label>
    <?php endif; ?>
</fieldset>
<p class="bench-note"><?= pl_e(pl_t('New in this release: a new business no longer has to start from the neutral chart alone. It can start from a sample company\'s skeleton, which is its chart of accounts, reports, document numbering and the modules it needs, with none of its transactions.')) ?></p>
</div>
<div class="bench-actions"><span class="fine-print"><?= pl_e(pl_t('Takes about two minutes.')) ?></span>
<span class="bench-action-pair"><a class="btn btn-secondary" href="<?= pl_e(pl_url('/companies')) ?>"><?= pl_e(pl_t('Cancel')) ?></a>
<button class="btn btn-primary" type="submit"><?= pl_e(pl_t('Continue')) ?></button></span></div>

<?php elseif ($stage === 'business'): $yearEndChoice = $field('fiscal_year_end_choice'); ?>
<?php if ($yearEndChoice === '') { $yearEndChoice = array_key_exists($field('fiscal_year_end'), pl_fiscal_year_end_options()) ? $field('fiscal_year_end') : (($regional['country_code'] ?? '') === 'PK' ? '06-30' : '12-31'); } ?>
<div class="bench-head">
    <p class="eyebrow"><?= pl_e(pl_t('Business setup · Stage {n} of 5', ['n' => $position + 1])) ?></p>
    <h1 class="bench-heading" id="onboarding-title"><?= pl_e(pl_t('Name this business and its financial year')) ?></h1>
    <p class="bench-sub"><?= pl_e(pl_t('One functional currency per book, one financial year end, and the first date these books cover. None of this activates a country tax rule.')) ?></p>
</div>
<div class="bench-body"><div class="bench-split">
<div class="bench-main">
    <div class="field"><label class="field-label" for="business-name"><?= pl_e(pl_t('Business name')) ?></label>
        <input class="input" id="business-name" name="name" type="text" maxlength="160" autocomplete="organization" required value="<?= pl_e($field('name')) ?>" aria-describedby="business-name-help">
        <p class="field-hint" id="business-name-help"><?= pl_e(pl_t('Use the name your team will recognise. You can change it later.')) ?></p></div>
    <div class="field-pair">
        <div class="field"><label class="field-label" for="base-currency"><?= pl_e(pl_t('Base currency')) ?></label>
            <select class="select" id="base-currency" name="currency" required>
            <?php foreach (pl_base_currency_options() as $code => $label): ?><option value="<?= pl_e($code) ?>"<?= $field('currency', (string) ($regional['currency'] ?? 'USD')) === $code ? ' selected' : '' ?>><?= pl_e($label) ?></option><?php endforeach; ?>
            </select></div>
        <div class="field"><label class="field-label" for="accounting-start"><?= pl_e(pl_t('Accounting start date')) ?></label>
            <input class="input" id="accounting-start" name="start_date" type="date" required value="<?= pl_e($field('start_date', gmdate('Y-m-d'))) ?>"></div>
    </div>
    <div class="field"><label class="field-label" for="entity-type"><?= pl_e(pl_t('Business or entity type')) ?></label>
        <select class="select" id="entity-type" name="entity_type" required aria-describedby="entity-type-help">
        <?php foreach (pl_setup_entity_type_options() as $key => $label): ?><option value="<?= pl_e($key) ?>"<?= $field('entity_type', 'other') === $key ? ' selected' : '' ?>><?= pl_e($label) ?></option><?php endforeach; ?>
        </select>
        <p class="field-hint" id="entity-type-help"><?= pl_e(pl_t('This guides the wording below only. It does not decide legal status and it does not activate tax rules.')) ?></p></div>
    <div class="field" data-fiscal-year-end>
        <label class="field-label" for="fiscal-year-end-choice"><?= pl_e(pl_t('Financial year end')) ?></label>
        <select class="select" id="fiscal-year-end-choice" name="fiscal_year_end_choice" required aria-describedby="fiscal-help" data-fiscal-year-end-choice data-fiscal-custom-target="#fiscal-year-end-custom">
        <?php foreach (pl_fiscal_year_end_options() as $key => $label): ?><option value="<?= pl_e($key) ?>"<?= $yearEndChoice === $key ? ' selected' : '' ?>><?= pl_e($label) ?></option><?php endforeach; ?>
        </select>
        <div class="fiscal-custom-group" data-fiscal-custom-group>
            <label class="field-label" for="fiscal-year-end-custom"><?= pl_e(pl_t('Custom year end')) ?></label>
            <input class="input" id="fiscal-year-end-custom" name="fiscal_year_end_custom" type="text" inputmode="numeric" maxlength="5" value="<?= pl_e($field('fiscal_year_end_custom', $field('fiscal_year_end'))) ?>" placeholder="<?= pl_e(pl_t('MM-DD')) ?>" aria-describedby="fiscal-help">
        </div>
        <p class="field-hint" id="fiscal-help"><?= pl_e(pl_t('This sets the book period boundary. The business type does not decide the period automatically; choose the one these books actually use.')) ?></p>
    </div>
</div>
<div class="bench-side">
    <?php require __DIR__ . '/../partials/regional.php'; ?>
    <div class="bench-aside">
        <p><strong><?= pl_e(pl_t('One book, one currency.')) ?></strong> <?= pl_e(pl_t('Amounts in other currencies are recorded against this one at the rate on the day.')) ?></p>
        <p><?= pl_e(pl_t('Accounting dates are written as they are entered. They never shift with a time zone.')) ?></p>
    </div>
</div>
</div></div>
<div class="bench-actions"><span class="fine-print"><?= pl_e(pl_t('Nothing is created yet.')) ?></span>
<span class="bench-action-pair"><a class="btn btn-secondary" href="<?= pl_e(pl_url('/onboarding', ['stage' => 'start'])) ?>"><?= pl_e(pl_t('Back')) ?></a>
<button class="btn btn-primary" type="submit"><?= pl_e(pl_t('Continue')) ?></button></span></div>

<?php else: $allowed = pl_onboarding_sources($startMode); if (!in_array($source, $allowed, true)) { $source = $allowed[0]; } ?>
<div class="bench-head">
    <p class="eyebrow"><?= pl_e(pl_t('Business setup · Stage {n} of 5', ['n' => $position + 1])) ?></p>
    <h1 class="bench-heading" id="onboarding-title"><?= pl_e(pl_t('How much starting structure?')) ?></h1>
    <p class="bench-sub"><?= pl_e(pl_t('A skeleton or a full sample is a complete accounting setup either way. Only the transactions differ.')) ?></p>
</div>
<div class="bench-body">
<fieldset class="choice-grid">
    <legend class="sr-only"><?= pl_e(pl_t('Starting structure')) ?></legend>
    <?php if (in_array('blank', $allowed, true)): ?>
    <label class="choice-card"><input type="radio" name="source" value="blank"<?= $source === 'blank' ? ' checked' : '' ?> data-source-choice>
        <span class="choice-body"><span class="choice-title"><?= pl_e($sourceLabels['blank']) ?></span>
        <span class="choice-detail"><?= pl_e(pl_t('The neutral starter chart and nothing else. No sample company is involved.')) ?></span>
        <span class="contents-list">
            <span class="is-in"><?= pl_e(pl_t('Neutral chart of accounts')) ?></span>
            <span class="is-out"><?= pl_e(pl_t('Reports beyond the core four')) ?></span>
            <span class="is-out"><?= pl_e(pl_t('Document numbering and modules')) ?></span>
            <span class="is-out"><?= pl_e(pl_t('Transactions')) ?></span>
        </span></span></label>
    <?php endif; ?>
    <?php if (in_array('skeleton', $allowed, true)): ?>
    <label class="choice-card"><input type="radio" name="source" value="skeleton"<?= $source === 'skeleton' ? ' checked' : '' ?> data-source-choice>
        <span class="choice-body"><span class="choice-title"><?= pl_e($sourceLabels['skeleton']) ?></span>
        <span class="choice-detail"><?= pl_e(pl_t('One sample company\'s structure, with none of its transactions. A real starting point, not a demonstration.')) ?></span>
        <span class="contents-list">
            <span class="is-in"><?= pl_e(pl_t('Its chart of accounts')) ?></span>
            <span class="is-in"><?= pl_e(pl_t('The reports its modules provide')) ?></span>
            <span class="is-in"><?= pl_e(pl_t('Document numbering, customers, suppliers and products')) ?></span>
            <span class="is-in"><?= pl_e(pl_t('The modules and packages it needs')) ?></span>
            <span class="is-out"><?= pl_e(pl_t('Transactions and opening balances')) ?></span>
        </span></span></label>
    <?php endif; ?>
    <?php if (in_array('full', $allowed, true)): ?>
    <label class="choice-card"><input type="radio" name="source" value="full"<?= $source === 'full' ? ' checked' : '' ?> data-source-choice>
        <span class="choice-body"><span class="choice-title"><?= pl_e($sourceLabels['full']) ?></span>
        <span class="choice-detail"><?= pl_e(pl_t('Everything in the skeleton, plus the sample\'s complete fictional transaction history. For exploring, never for real records.')) ?></span>
        <span class="contents-list">
            <span class="is-in"><?= pl_e(pl_t('Everything a skeleton brings in')) ?></span>
            <span class="is-in"><?= pl_e(pl_t('Two years of posted history and open practice drafts')) ?></span>
            <span class="is-in"><?= pl_e(pl_t('Its own pinned accounting start and year end')) ?></span>
            <span class="is-out"><?= pl_e(pl_t('Any connection to your real businesses')) ?></span>
        </span></span></label>
    <?php endif; ?>
</fieldset>
<?php if ($allowed !== ['blank']): ?>
<div class="picker-row">
    <div class="field"><label class="field-label" for="sample-pack"><?= pl_e(pl_t('Sample company')) ?></label>
        <select class="select" id="sample-pack" name="sample_pack" aria-describedby="sample-pack-help">
        <?php foreach ($sources as $id => $entry): ?>
            <option value="<?= pl_e($id) ?>"<?= $samplePack === $id ? ' selected' : '' ?>><?= pl_e($entry['name']) ?><?= $entry['business'] === '' ? '' : ' · ' . pl_e($entry['business']) ?><?= $entry['skeleton'] ? '' : ' · ' . pl_e(pl_t('full sample only')) ?></option>
        <?php endforeach; ?>
        </select></div>
    <p class="picker-hint" id="sample-pack-help"><?= pl_e(pl_tn('{count} bundled sample company. A sample marked “full sample only” does not publish a structure yet, so it cannot start a skeleton.', '{count} bundled sample companies. A sample marked “full sample only” does not publish a structure yet, so it cannot start a skeleton.', count($sources), ['count' => count($sources)])) ?></p>
</div>
<?php else: ?>
<p class="bench-note"><?= pl_e(pl_t('Past records bring their own chart of accounts through the opening cutover, so this path starts from the neutral chart and maps yours onto it afterwards.')) ?></p>
<?php endif; ?>
<?php if ($startMode === 'existing'): ?>
<fieldset class="field">
    <legend class="field-label"><?= pl_e(pl_t('Which chart starts this book?')) ?></legend>
    <label class="checkbox setup-option"><input type="radio" name="chart_choice" value="neutral"<?= $field('chart_choice', 'bring_own') === 'neutral' ? ' checked' : '' ?>> <span><b><?= pl_e(pl_t('Use the neutral starter chart')) ?></b><small><?= pl_e(pl_t('Start with the compact country-neutral accounts and review the exact names and codes next.')) ?></small></span></label>
    <label class="checkbox setup-option"><input type="radio" name="chart_choice" value="bring_own"<?= $field('chart_choice', 'bring_own') === 'bring_own' ? ' checked' : '' ?>> <span><b><?= pl_e(pl_t('Prepare to bring my existing chart')) ?></b><small><?= pl_e(pl_t('Keep the existing chart as the opening-conversion source and review its mapping after this setup.')) ?></small></span></label>
</fieldset>
<?php endif; ?>
<?php if ($startMode === 'fresh'): ?>
<div class="field"><label class="checkbox"><input type="checkbox" name="zero_balances_confirmed" value="1"<?= ($values['zero_balances_confirmed'] ?? null) === true || $field('zero_balances_confirmed') === '1' ? ' checked' : '' ?>> <?= pl_e(pl_t('I confirm this business has no opening balances and no unpaid invoices or bills to bring forward.')) ?></label>
<p class="field-hint"><?= pl_e(pl_t('A skeleton brings in structure only, so this stays true either way. Choose Bring past records instead if there are balances to carry over.')) ?></p></div>
<?php endif; ?>
</div>
<div class="bench-actions"><span class="fine-print"><?= pl_e(pl_t('You still confirm on the next stage before anything is created.')) ?></span>
<span class="bench-action-pair"><a class="btn btn-secondary" href="<?= pl_e(pl_url('/onboarding', ['stage' => 'business'])) ?>"><?= pl_e(pl_t('Back')) ?></a>
<button class="btn btn-primary" type="submit"><?= pl_e(pl_t('Review and continue')) ?></button></span></div>
<?php endif; ?>
</form>
<?php endif; ?>
</section>
