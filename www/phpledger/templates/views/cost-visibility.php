<?php
declare(strict_types=1);
/**
 * Admin > Cost visibility (owner decision B58): cost visibility is an Admin call that varies
 * report by report. The capability decides whether a person MAY see cost at all; this screen
 * decides whether a given report SHOWS it. Both must say yes.
 *
 * @var array $company @var array $user @var array $settings @var bool $canManage
 * @var bool $holdsCostView @var array $form @var array $input
 */
$costInput = $form['input'];
?>
<section class="flex flex-col gap-4 py-5" aria-labelledby="cost-visibility-title">
    <div class="page-header">
        <div>
            <p class="eyebrow"><?= pl_e(pl_t('Setup')) ?></p>
            <h1 class="page-title" id="cost-visibility-title"><?= pl_e(pl_t('Cost visibility')) ?></h1>
            <p class="muted"><?= pl_e(pl_t('Purchase cost and margin are a trade secret in many businesses. Choose which reports show them; nobody without the "See cost and margin" permission sees them anywhere.')) ?></p>
        </div>
        <div class="page-header-actions"><a class="btn btn-secondary" href="<?= pl_e(pl_url('/roles')) ?>"><?= pl_e(pl_t('Who holds the permission')) ?></a></div>
    </div>

    <?php if ($form['message'] !== ''): ?>
        <div class="alert alert-danger" role="alert" tabindex="-1" data-form-error><p><?= pl_e((string) $form['message']) ?></p></div>
    <?php endif; ?>

    <div class="rounded-panel border border-border bg-surface p-4">
        <h2 class="section-title mb-2"><?= pl_e(pl_t('How this is decided')) ?></h2>
        <p><?= pl_e(pl_t('A report shows cost only when both answers are yes: the person holds the "See cost and margin" permission, and the report below is set to show it. Turning a report off here hides cost on the screen and in the machine-readable read alike, so an API client cannot read what the screen hides.')) ?></p>
        <p class="muted"><?= pl_e($holdsCostView ? pl_t('You hold the "See cost and margin" permission in this business.') : pl_t('You do not hold the "See cost and margin" permission, so cost stays hidden from you whatever these settings say.')) ?></p>
    </div>

    <div class="rounded-panel border border-border bg-surface p-4">
        <h2 class="section-title mb-2"><?= pl_e(pl_t('Reports that can show cost')) ?></h2>
        <div class="table-wrap" tabindex="0" role="region" aria-label="<?= pl_e(pl_t('Report cost settings')) ?>">
            <table class="table">
                <thead><tr>
                    <th scope="col"><?= pl_e(pl_t('Report')) ?></th>
                    <th scope="col"><?= pl_e(pl_t('What cost means here')) ?></th>
                    <th scope="col"><?= pl_e(pl_t('Cost')) ?></th>
                    <th scope="col"><?= pl_e(pl_t('Margin')) ?></th>
                    <th scope="col"><?= pl_e(pl_t('Action')) ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($settings as $setting): $rowInput = pl_web_text($costInput, 'report_id') === $setting['report_id'] ? $costInput : []; ?>
                    <tr>
                        <td><?= pl_e((string) $setting['label']) ?></td>
                        <td class="text-ink-muted"><?= pl_e((string) $setting['cost']) ?></td>
                        <td><?= pl_e($setting['show_cost'] ? pl_t('Shown') : pl_t('Hidden')) ?></td>
                        <td><?= pl_e($setting['show_margin'] ? pl_t('Shown') : pl_t('Hidden')) ?></td>
                        <td>
                            <?php if (!$canManage): ?>
                                <span class="muted"><?= pl_e(pl_t('Read-only')) ?></span>
                            <?php else: ?>
                                <details data-confirmation<?= $rowInput !== [] ? ' open' : '' ?>>
                                    <summary class="btn btn-secondary btn-sm"><?= pl_e(pl_t('Change')) ?></summary>
                                    <div data-confirmation-body>
                                        <form method="post" action="<?= pl_e(pl_url('/cost-visibility')) ?>" class="grid grid-cols-1 gap-3 mt-3">
                                            <?= pl_csrf_field() ?><?= pl_scope_fields($company) ?>
                                            <input type="hidden" name="report_id" value="<?= pl_e((string) $setting['report_id']) ?>">
                                            <input type="hidden" name="revision" value="<?= (int) $setting['revision'] ?>">
                                            <p><label><input type="checkbox" name="show_cost" value="1"<?= ($rowInput !== [] ? pl_web_text($rowInput, 'show_cost') === '1' : $setting['show_cost']) ? ' checked' : '' ?>> <?= pl_e(pl_t('Show cost columns on this report')) ?></label></p>
                                            <p><label><input type="checkbox" name="show_margin" value="1"<?= ($rowInput !== [] ? pl_web_text($rowInput, 'show_margin') === '1' : $setting['show_margin']) ? ' checked' : '' ?>> <?= pl_e(pl_t('Show margin columns on this report')) ?></label></p>
                                            <div class="field"><label for="cost-reason-<?= pl_e((string) $setting['report_id']) ?>"><?= pl_e(pl_t('Reason for this change')) ?></label><input class="input" id="cost-reason-<?= pl_e((string) $setting['report_id']) ?>" name="reason" maxlength="500" required value="<?= pl_e(pl_web_text($rowInput, 'reason')) ?>"></div>
                                            <div><button class="btn btn-primary" type="submit"><?= pl_e(pl_t('Save setting')) ?></button></div>
                                        </form>
                                        <button type="button" class="btn btn-ghost mt-3" data-confirmation-cancel hidden><?= pl_e(pl_t('Cancel')) ?></button>
                                    </div>
                                </details>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
