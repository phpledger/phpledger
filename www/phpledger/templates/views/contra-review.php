<?php
declare(strict_types=1);
/**
 * Confirm your contra accounts (internal review finding 5).
 *
 * A chart converted from old account numbers by migration 036 starts with no
 * contra accounts marked. Migration 040 sets the ones the starter purposes
 * identify; everything else is asked about here rather than guessed from a
 * name. The owner screens stay closed until this is answered.
 */
$canAnswer = pl_can_write($company) && !pl_demo_enabled();
$state = $review['state'];
$answered = $state !== null && $state['status'] === 'confirmed';
$hasFailure = pl_web_text($form, 'message') !== '';
$chosen = $input['contra_account_ids'] ?? [];
$chosen = is_array($chosen) ? array_map('strval', $chosen) : [];
$typeLabels = ['asset' => pl_t('Asset'), 'equity' => pl_t('Equity'), 'income' => pl_t('Income'), 'expense' => pl_t('Expense')];
?>
<div class="flex flex-col gap-4 py-5">
<?php pl_ui_page_header(pl_t('Confirm your contra accounts'), pl_t('{company} · {count} account(s) to review', ['company' => $company['name'], 'count' => count($review['candidates'])]), static function (): void { ?>
<a class="btn btn-secondary" href="<?= pl_e(pl_url('/accounts')) ?>"><?= pl_icon('file-text') ?> <?= pl_e(pl_t('Chart of accounts')) ?></a>
<?php }, 'contra-title'); ?>

<?php pl_ui_strip($answered
    ? pl_t('This step is answered. The owner and partner screens are open again.')
    : pl_t('This chart was converted from its old account numbers, and a converted chart starts with no contra accounts marked. Owner transactions and partners stay closed until you answer this once.'), $answered ? 'info' : 'warning'); ?>

<p class="text-xs text-ink-muted"><?= pl_e(pl_t('A contra account is presented as a deduction from the group it belongs to rather than as a balance of its own: accumulated depreciation against the assets it relates to, drawings against capital, sales returns against sales, purchase returns against purchases. Marking one changes how it is presented on the balance sheet and the profit and loss. It does not move, change or re-post a single journal line.')) ?></p>

<?php if ($hasFailure): ?><div class="alert alert-danger" role="alert" tabindex="-1" data-form-error><strong><?= pl_e(pl_t('Nothing was changed.')) ?></strong><p><?= pl_e(pl_web_text($form, 'message')) ?></p></div><?php endif; ?>

<?php if ($review['derived']): ?>
<section class="flex flex-col gap-2" aria-labelledby="contra-derived"><h2 class="section-title" id="contra-derived"><?= pl_e(pl_t('Already marked as contra accounts')) ?></h2>
<p class="text-xs text-ink-muted"><?= pl_e(pl_t('These carry one of the chart purposes that is defined as a contra account, so the upgrade marked them without asking. Nothing was deduced from their names.')) ?></p>
<div class="table-wrap" tabindex="0" role="region" aria-label="<?= pl_e(pl_t('Accounts already marked as contra accounts')) ?>"><table class="table">
<thead><tr><th scope="col"><?= pl_e(pl_t('Code')) ?></th><th scope="col"><?= pl_e(pl_t('Account')) ?></th><th scope="col"><?= pl_e(pl_t('Classification')) ?></th><th scope="col"><?= pl_e(pl_t('Purpose')) ?></th></tr></thead><tbody>
<?php foreach ($review['derived'] as $row): ?>
<tr><td><?= pl_e((string) $row['code']) ?></td><th scope="row"><?= pl_e((string) $row['name']) ?></th><td><?= pl_e($typeLabels[(string) $row['type']] ?? (string) $row['type']) ?></td><td class="text-ink-muted"><?= pl_e((string) ($row['semantic_key'] ?? '')) ?></td></tr>
<?php endforeach; ?>
</tbody></table></div></section>
<?php endif; ?>

<?php if ($answered): ?>
<section class="panel p-4 flex flex-col gap-2" aria-labelledby="contra-answer"><h2 class="section-title" id="contra-answer"><?= pl_e(pl_t('What was recorded')) ?></h2>
<p><?= pl_e(pl_tn('{count} account was marked as a contra account.', '{count} accounts were marked as contra accounts.', (int) count($state['answer']['marked'] ?? []), ['count' => count($state['answer']['marked'] ?? [])])) ?></p>
<?php if (($state['answer']['marked'] ?? []) !== []): ?>
<ul class="flex flex-col gap-1">
<?php foreach ($state['answer']['marked'] as $marked): ?>
<li><?= pl_e((string) $marked['code'] . ' · ' . (string) $marked['name']) ?></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<p class="text-xs text-ink-muted"><?= pl_e(pl_t('Reason: {reason}', ['reason' => (string) ($state['reason'] ?? '')])) ?></p>
<div class="panel-actions"><a class="btn btn-primary" href="<?= pl_e(pl_url('/owner')) ?>"><?= pl_e(pl_t('Owner and partners')) ?></a></div>
</section>
<?php elseif ($canAnswer): ?>
<section class="panel p-4 flex flex-col gap-3" aria-labelledby="contra-choose"><h2 class="section-title" id="contra-choose"><?= pl_e(pl_t('Which of these are contra accounts?')) ?></h2>
<form method="post" action="<?= pl_e(pl_url('/contra-review/confirm')) ?>" class="flex flex-col gap-3">
<?= pl_csrf_field() ?><?= pl_scope_fields($company) ?>
<input type="hidden" name="creation_key" value="<?= pl_e(pl_web_text($input, 'creation_key')) ?>">
<?php if ($review['candidates'] === []): ?>
<p><?= pl_e(pl_t('This chart has no account left to decide about. Confirm to reopen the owner screens.')) ?></p>
<?php else: ?>
<div class="table-wrap" tabindex="0" role="region" aria-label="<?= pl_e(pl_t('Accounts to review')) ?>"><table class="table">
<thead><tr><th scope="col"><?= pl_e(pl_t('Contra')) ?></th><th scope="col"><?= pl_e(pl_t('Code')) ?></th><th scope="col"><?= pl_e(pl_t('Old number')) ?></th><th scope="col"><?= pl_e(pl_t('Account')) ?></th><th scope="col"><?= pl_e(pl_t('Classification')) ?></th></tr></thead><tbody>
<?php foreach ($review['candidates'] as $row): ?>
<tr><td><label class="flex items-start gap-2 text-sm"><input type="checkbox" name="contra_account_ids[]" value="<?= (int) $row['id'] ?>" <?= in_array((string) $row['id'], $chosen, true) ? 'checked' : '' ?>><span class="sr-only"><?= pl_e(pl_t('Mark {account} as a contra account', ['account' => (string) $row['code'] . ' ' . (string) $row['name']])) ?></span></label></td>
<td><?= pl_e((string) $row['code']) ?></td><td class="text-ink-muted"><?= pl_e((string) ($row['legacy_code'] ?? '')) ?></td><th scope="row"><?= pl_e((string) $row['name']) ?></th><td><?= pl_e($typeLabels[(string) $row['type']] ?? (string) $row['type']) ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
<label class="field"><?= pl_e(pl_t('Reason for this change')) ?><input class="input" name="reason" maxlength="500" required value="<?= pl_e(pl_web_text($input, 'reason')) ?>" placeholder="<?= pl_e(pl_t('Who reviewed the chart, and against what')) ?>"><span class="field-hint"><?= pl_e(pl_t('Recorded against every account this marks, in the same audit trail a chart edit uses.')) ?></span></label>
<div class="panel-actions"><button class="btn btn-primary" type="submit" data-fold="primary action"><?= pl_e(pl_t('Confirm these contra accounts')) ?></button></div>
<p class="field-hint"><?= pl_e(pl_t('Leaving every box clear is a valid answer: it records that none of these is a contra account. You can still mark one later in the chart of accounts.')) ?></p>
</form>
</section>
<?php else: ?>
<?php pl_ui_strip(pl_demo_enabled() ? pl_t('The public demo cannot answer this step.') : pl_t('Your role can review this list but not answer it. An owner or accountant confirms the contra accounts.'), 'info'); ?>
<?php endif; ?>
</div>
