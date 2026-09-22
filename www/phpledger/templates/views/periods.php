<?php
declare(strict_types=1);
$periodInput = $form['input'];
$canManagePeriods = pl_can_write($company) && !pl_demo_enabled();
$canReopenPeriods = ($company['role'] ?? '') === 'owner' && !pl_demo_enabled();
$nextStart = isset($periods[0]) && $periods[0]['end_date'] < '9998-12-31'
    ? (new DateTimeImmutable($periods[0]['end_date']))->modify('+1 day')->format('Y-m-d') : '';
$createInput = pl_web_text($periodInput, 'action') === 'create' ? $periodInput : [];
?>
<section class="flex flex-col gap-4 py-5" aria-labelledby="periods-title">
    <div class="page-header">
        <div><p class="eyebrow"><?= pl_e(pl_t('Books and controls')) ?></p><h1 class="page-title" id="periods-title"><?= pl_e(pl_t('Accounting periods')) ?></h1><p class="muted"><?= pl_e(pl_t('Create date ranges, close completed periods and review their history.')) ?></p></div>
        <a class="btn btn-secondary" href="<?= pl_e(pl_url('/reports')) ?>"><?= pl_e(pl_t('View reports')) ?></a>
    </div>
    <?php if ($form['message'] !== ''): ?>
        <div class="alert alert-danger" role="alert" tabindex="-1" data-form-error><h2 class="section-title mb-2"><?= pl_e(pl_t('Period action needs attention')) ?></h2><p><?= pl_e((string) $form['message']) ?></p><p><?= pl_e(pl_t('Your entered values are preserved where the action is still available. Review the current period before trying again.')) ?></p></div>
    <?php endif; ?>
    <div class="rounded-panel border border-border bg-surface p-4"><h2 class="section-title mb-2"><?= pl_e(pl_t('Close posting dates after review')) ?></h2><p><?= pl_e(pl_t('Closing prevents new entries and reversals dated within the period. Existing entries remain readable. Review drafts, reports and reconciliations before closing. A business owner can reopen a period with a recorded reason.')) ?></p><p class="muted"><?= pl_e(pl_t('Closing does not create year-end adjustment entries, transfer profit, or certify the accounts.')) ?></p></div>
    <?php if ($canManagePeriods): ?>
        <details class="rounded-panel border border-border bg-surface p-4"<?= $createInput !== [] ? ' open' : '' ?>><summary><strong><?= pl_e(pl_t('Create an accounting period')) ?></strong></summary>
            <form action="<?= pl_e(pl_url('/periods')) ?>" method="post" class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
                <?= pl_csrf_field() ?><?= pl_scope_fields($company) ?>
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="request_key" value="<?= pl_e(pl_web_text($createInput, 'request_key', bin2hex(random_bytes(16)))) ?>">
                <div class="field"><label for="period-start"><?= pl_e(pl_t('Start date')) ?></label><input class="input" id="period-start" type="date" name="start_date" value="<?= pl_e(pl_web_text($createInput, 'start_date', $nextStart)) ?>" required></div>
                <div class="field"><label for="period-end"><?= pl_e(pl_t('End date')) ?></label><input class="input" id="period-end" type="date" name="end_date" value="<?= pl_e(pl_web_text($createInput, 'end_date')) ?>" required></div>
                <div class="field sm:col-span-2"><label for="period-create-reason"><?= pl_e(pl_t('Reason')) ?></label><input class="input" id="period-create-reason" name="reason" maxlength="500" value="<?= pl_e(pl_web_text($createInput, 'reason')) ?>" required><p class="muted"><?= pl_e(pl_t('Dates include both endpoints and must not overlap another period.')) ?></p></div>
                <div class="flex gap-2 sm:col-span-2"><button class="btn btn-primary" type="submit"><?= pl_e(pl_t('Create open period')) ?></button></div>
            </form>
        </details>
    <?php elseif (pl_demo_enabled()): ?>
        <div class="rounded-panel border border-border bg-surface p-4"><p><?= pl_e(pl_t('Period administration is disabled in the public sample.')) ?></p></div>
    <?php else: ?>
        <div class="rounded-panel border border-border bg-surface p-4"><p><?= pl_e(pl_t('Your role can read periods and their history. An owner or accountant can create and close periods.')) ?></p></div>
    <?php endif; ?>
    <div class="rounded-panel border border-border bg-surface p-4"><h2 class="section-title mb-2"><?= pl_e(pl_t('Periods')) ?></h2>
        <div class="table-wrap" tabindex="0" role="region" aria-label="<?= pl_e(pl_t('Accounting periods')) ?>"><table class="table"><thead><tr><th scope="col"><?= pl_e(pl_t('Start date')) ?></th><th scope="col"><?= pl_e(pl_t('End date')) ?></th><th scope="col"><?= pl_e(pl_t('Status')) ?></th><th scope="col"><?= pl_e(pl_t('Action')) ?></th></tr></thead><tbody>
            <?php foreach ($periods as $period): ?>
                <?php
                $periodId = (int) $period['id'];
                $action = $period['status'] === 'open' ? 'close' : 'reopen';
                $rowInput = pl_web_id($periodInput, 'period_id') === $periodId && pl_web_text($periodInput, 'action') === $action ? $periodInput : [];
                ?>
                <tr><td><?= pl_e(pl_date_label($period['start_date'])) ?></td><td><?= pl_e(pl_date_label($period['end_date'])) ?></td><td><span class="badge <?= $period['status']==='open'?'badge-info':'badge-posted' ?>"><?= pl_e($period['status'] === 'open' ? pl_t('Open') : pl_t('Closed')) ?></span></td><td>
                    <?php if ($canManagePeriods && ($action === 'close' || $canReopenPeriods)): ?>
                        <details data-confirmation<?= $rowInput !== [] ? ' open' : '' ?>><summary class="btn btn-secondary btn-sm"><?= pl_e($action === 'close' ? pl_t('Close period') : pl_t('Reopen period')) ?></summary>
                            <div data-confirmation-body><h2 class="section-title"><?= $action === 'close' ? pl_e(pl_t('Close period · {range}', ['range' => pl_date_label($period['start_date']).' – '.pl_date_label($period['end_date'])])) : pl_e(pl_t('Reopen period · {range}', ['range' => pl_date_label($period['start_date']).' – '.pl_date_label($period['end_date'])])) ?></h2><p class="field-hint"><?= pl_e($action === 'close' ? pl_t('Prevents new entries and reversals dated in this period. Existing entries remain readable.') : pl_t('Allows new entries and reversals dated in this period again. Only the owner can reopen it.')) ?></p>
                            <?php if ($rowInput && $form['message']!==''): ?><p class="alert alert-danger" role="alert"><?= pl_e($form['message']) ?></p><?php endif; ?>
                            <form action="<?= pl_e(pl_url('/periods')) ?>" method="post" class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
                                <?= pl_csrf_field() ?><?= pl_scope_fields($company) ?>
                                <input type="hidden" name="action" value="<?= pl_e($action) ?>"><input type="hidden" name="period_id" value="<?= $periodId ?>"><input type="hidden" name="revision" value="<?= (int) $period['revision'] ?>">
                                <input type="hidden" name="request_key" value="<?= pl_e(bin2hex(random_bytes(16))) ?>">
                                <div class="field sm:col-span-2"><label for="period-reason-<?= $periodId ?>"><?= pl_e(pl_t('Reason for {action}', ['action' => $action === 'close' ? pl_t('closing') : pl_t('reopening')])) ?></label><input class="input" id="period-reason-<?= $periodId ?>" name="reason" maxlength="500" value="<?= pl_e(pl_web_text($rowInput, 'reason')) ?>" required></div>
                                <div class="flex gap-2 sm:col-span-2"><button class="btn btn-secondary" type="submit"><?= pl_e($action === 'close' ? pl_t('Confirm close') : pl_t('Confirm reopen')) ?></button></div>
                            </form><button type="button" class="btn btn-ghost mt-3" data-confirmation-cancel hidden><?= pl_e(pl_t('Cancel')) ?></button></div>
                        </details>
                    <?php else: ?><span class="muted"><?= pl_e($period['status'] === 'closed' ? pl_t('Owner can reopen') : pl_t('Read only')) ?></span><?php endif; ?>
                <details class="mt-3"><summary class="btn btn-ghost btn-sm"><?= pl_e(pl_t('Close checklist')) ?></summary>
                    <?php $checklist = $checklists[$periodId]; ?>
                    <p class="muted"><?= pl_e(pl_t('Required items block closing. Advisory items are warnings; record a reason when deliberately skipped.')) ?></p>
                    <ul class="flex flex-col gap-3 mt-3">
                    <?php foreach ($checklist['items'] as $item): ?>
                        <li><strong><?= pl_e(pl_t($item['label'])) ?></strong>
                        <p><?= pl_e(pl_t($item['resolved'] ? 'Complete' : ($item['severity'] === 'hard' ? 'Required: outstanding' : 'Advisory: outstanding'))) ?></p>
                        <p class="muted"><?= pl_e(pl_t($item['hint'])) ?></p>
                        <?php if ($item['detail'] !== []): ?><p><?= pl_e(pl_t('Outstanding records: {count}', ['count' => count($item['detail'])])) ?></p><?php endif; ?>
                        <?php if ($item['tick']): ?><p><?= pl_e($item['tick']['actor_name'] . ' � ' . $item['tick']['ticked_at'] . ' UTC � ' . $item['tick']['reason']) ?></p><?php endif; ?>
                        <?php if ($canManagePeriods && $period['status'] === 'open' && !($item['computed'] && $item['severity'] === 'hard')): ?>
                        <form method="post" action="<?= pl_e(pl_url('/periods')) ?>" class="grid grid-cols-1 gap-2">
                            <?= pl_csrf_field() ?><?= pl_scope_fields($company) ?>
                            <input type="hidden" name="action" value="tick"><input type="hidden" name="period_id" value="<?= $periodId ?>"><input type="hidden" name="item_key" value="<?= pl_e($item['key']) ?>"><input type="hidden" name="request_key" value="<?= pl_e(bin2hex(random_bytes(16))) ?>">
                            <label><?= pl_e(pl_t('Checklist action')) ?><select class="input" name="state"><option value="done"><?= pl_e(pl_t('Done')) ?></option><option value="skipped"><?= pl_e(pl_t('Skipped with reason')) ?></option><option value="cleared"><?= pl_e(pl_t('Clear attestation')) ?></option></select></label>
                            <label><?= pl_e(pl_t('Reason')) ?><input class="input" name="reason" maxlength="500" required></label><button class="btn btn-secondary btn-sm"><?= pl_e(pl_t('Record checklist action')) ?></button>
                        </form><?php endif; ?>
                        <?php if ($canManagePeriods && $period['status'] === 'open'): ?>
                        <details class="mt-2"><summary><?= pl_e(pl_t('Book checklist policy')) ?></summary>
                        <form method="post" action="<?= pl_e(pl_url('/periods')) ?>" class="flex flex-wrap gap-2">
                            <?= pl_csrf_field() ?><?= pl_scope_fields($company) ?><input type="hidden" name="action" value="severity"><input type="hidden" name="item_key" value="<?= pl_e($item['key']) ?>">
                            <label><?= pl_e(pl_t('Severity for this book')) ?><select class="input" name="severity"><option value="hard" <?= $item['severity'] === 'hard' ? 'selected' : '' ?>><?= pl_e(pl_t('Required')) ?></option><option value="soft" <?= $item['severity'] === 'soft' ? 'selected' : '' ?>><?= pl_e(pl_t('Advisory')) ?></option></select></label>
                            <button class="btn btn-secondary btn-sm"><?= pl_e(pl_t('Save book policy')) ?></button>
                        </form></details><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                </details>
                </td></tr>
            <?php endforeach; ?>
        </tbody></table></div>
    </div>
    <?php if ($canManagePeriods): ?>
    <details class="rounded-panel border border-border bg-surface p-4"><summary><?= pl_e(pl_t('Add a company close checklist item')) ?></summary>
        <form method="post" action="<?= pl_e(pl_url('/periods')) ?>" class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
            <?= pl_csrf_field() ?><?= pl_scope_fields($company) ?><input type="hidden" name="action" value="add_item">
            <label><?= pl_e(pl_t('Item key')) ?><input class="input" name="item_key" placeholder="company.tax_review" maxlength="60" required></label>
            <label><?= pl_e(pl_t('Label')) ?><input class="input" name="label" maxlength="200" required></label>
            <label><?= pl_e(pl_t('Severity')) ?><select class="input" name="severity"><option value="soft"><?= pl_e(pl_t('Advisory')) ?></option><option value="hard"><?= pl_e(pl_t('Required')) ?></option></select></label>
            <button class="btn btn-secondary"><?= pl_e(pl_t('Save checklist item')) ?></button>
        </form>
    </details><?php endif; ?>
    <a class="btn btn-ghost" href="<?= pl_e(pl_url('/cash-counts')) ?>"><?= pl_e(pl_t('Cash counts and history')) ?></a>
    <div class="rounded-panel border border-border bg-surface p-4"><h2 class="section-title mb-2"><?= pl_e(pl_t('Recent administration history')) ?></h2><p class="muted"><?= pl_e(pl_t('Latest 100 actions. Periods created during initial business setup have no separate administration action.')) ?></p>
        <?php if ($history === []): ?><p><?= pl_e(pl_t('No period administration actions have been recorded.')) ?></p>
        <?php else: ?><ol class="flex flex-col gap-3 border-s border-border ps-3" aria-label="<?= pl_e(pl_t('Period administration history')) ?>">
            <?php foreach ($history as $event): ?><li><div class="flex flex-wrap items-center gap-2"><?php pl_ui_badge($event['action']==='close'?'posted':'info',pl_t(['create'=>'Created open','close'=>'Closed','reopen'=>'Reopened','tick'=>'Checklist recorded','untick'=>'Checklist cleared'][$event['action']])); ?><p class="text-sm font-medium"><?= pl_e(pl_date_label($event['start_date']).' – '.pl_date_label($event['end_date'])) ?></p></div><p class="text-xs text-ink-muted mt-1"><?= pl_e($event['reason']) ?></p><p class="text-xs text-ink-muted"><time datetime="<?= pl_e(str_replace(' ', 'T', $event['created_at']) . 'Z') ?>" data-local-time><?= pl_e(pl_t('{when} UTC', ['when' => $event['created_at']])) ?></time> · <?= pl_e($event['actor_name']) ?></p></li><?php endforeach; ?>
        </ol><?php endif; ?>
    </div>
</section>
