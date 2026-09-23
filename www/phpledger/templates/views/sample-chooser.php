<?php declare(strict_types=1); ?>
<section class="auth-panel sample-chooser" aria-labelledby="sample-chooser-title">
    <p class="eyebrow"><?= pl_e(pl_t('Local sample catalogue')) ?></p>
    <?php pl_ui_page_header(pl_t('Choose one sample company'), pl_t('Each choice creates one new, isolated company and book. Your existing businesses are never used as a target and are not changed.'), static function (): void { ?>
        <a class="btn btn-secondary" href="<?= pl_e(pl_url('/companies')) ?>"><?= pl_e(pl_t('Back to businesses')) ?></a>
    <?php }, 'sample-chooser-title'); ?>
    <?php if ($form['message'] !== ''): ?><div class="alert" role="alert" tabindex="-1" data-form-error><h2><?= pl_e(pl_t('Choose a sample')) ?></h2><p><?= pl_e((string) $form['message']) ?></p></div><?php endif; ?>
    <form method="post" action="<?= pl_e(pl_url('/sample-chooser')) ?>" class="form-grid">
        <?= pl_csrf_field() ?>
        <div class="field full-width">
            <label class="field-label" for="sample-pack"><?= pl_e(pl_t('Sample company')) ?></label>
            <select class="select" id="sample-pack" name="sample_pack" required>
                <?php foreach (pl_demo_sample_choices() as $entry): ?>
                    <?php $label = $entry['id'] === 'accounting-starter' ? (string) $entry['name'] : pl_t('{business} — {name}', ['business' => $entry['business'], 'name' => $entry['name']]); ?>
                    <option value="<?= pl_e((string) $entry['id']) ?>"<?= pl_web_text($form['input'], 'sample_pack') === $entry['id'] ? ' selected' : '' ?>><?= pl_e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="muted"><?= pl_e(pl_t('The historical companies contain pinned sample source records, closed 2024–2025 history, an open 2026 practice year and editable drafts. The starter playground begins at zero balances.')) ?></p>
        </div>
        <div class="field">
            <label class="field-label" for="sample-currency"><?= pl_e(pl_t('Functional currency')) ?></label>
            <select class="select" id="sample-currency" name="currency" required>
                <?php foreach (pl_base_currency_options() as $code => $label): ?><option value="<?= pl_e($code) ?>"<?= pl_web_text($form['input'], 'currency', 'USD') === $code ? ' selected' : '' ?>><?= pl_e($label) ?></option><?php endforeach; ?>
            </select>
            <p class="muted"><?= pl_e(pl_t('Amounts are illustrative. Country tax and statutory rules are not enabled by choosing a sample.')) ?></p>
        </div>
        <div class="field full-width">
            <p class="small muted"><?= pl_e(pl_t('Samples are sample teaching books. Provisioning uses the bundled, checksummed catalogue only and never sends messages, payments or provider requests.')) ?></p>
        </div>
        <div class="panel-actions full-width" data-fold="primary action"><button class="btn btn-primary" type="submit"><?= pl_e(pl_t('Create this separate sample')) ?></button><a class="btn btn-secondary" href="<?= pl_e(pl_url('/companies')) ?>"><?= pl_e(pl_t('Cancel')) ?></a></div>
    </form>
    <details class="rounded-panel border border-border p-4"><summary><?= pl_e(pl_t('Meet the fictional companies')) ?></summary>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
        <?php foreach (pl_demo_pack_catalog() as $entry): $preview = pl_demo_pack($entry['id']); $story = $preview['learning_story'] ?? null; if (!is_array($story)) { continue; } ?>
            <article><img src="<?= pl_e(pl_url($story['logo']['path'])) ?>" width="48" height="48" alt="<?= pl_e($preview['name'] . ' fictional company logo') ?>"><h2><?= pl_e($preview['name']) ?></h2><p><?= pl_e($story['origin']) ?></p><p class="small muted"><?= pl_e($story['status'] === 'complete_history' ? pl_t('Monthly 2024–2025 journey and separate 2026 practice chapter.') : $story['journey_note']) ?></p></article>
        <?php endforeach; ?>
        </div>
    </details>
</section>
