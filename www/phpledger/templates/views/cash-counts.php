<?php declare(strict_types=1); $input = $form['input']; $canWrite = pl_can_write($company); ?>
<section class="flex flex-col gap-4 py-4">
<?php pl_ui_page_header(pl_t('Cash counts'), pl_t('Record a physical count and post its difference against the cash account balance.')); ?>
<p class="muted"><?= pl_e(pl_t('The balance includes entries dated on or before the count date as recorded now. The timestamp is evidence of when cash was counted, not a separate intraday ledger.')) ?></p>
<?php if ($form['message'] !== ''): ?><p class="alert alert-danger" role="alert"><?= pl_e($form['message']) ?></p><?php endif; ?>
<?php if ($count): ?>
<div class="rounded-panel border border-border bg-surface p-4">
<h2 class="section-title"><?= pl_e($count['reference'] . ' · ' . $count['account_name']) ?></h2>
<p><?= pl_e(pl_t('Counted {counted}; book balance {balance}; difference {difference}.', ['counted'=>pl_money($count['counted_amount']), 'balance'=>pl_money($count['book_balance']), 'difference'=>pl_money($count['difference'])])) ?></p>
<p><?= pl_e($count['counted_by_name'] . ' · ' . $count['counted_at'] . ' UTC · ' . $count['note']) ?></p>
<?php if ($count['journal_id']): ?><a class="btn btn-ghost" href="<?= pl_e(pl_url('/journals/detail', ['id'=>$count['journal_id']])) ?>"><?= pl_e(pl_t('View posted difference')) ?></a><?php else: ?><p><?= pl_e(pl_t('Count agreed; no journal was needed.')) ?></p><?php endif; ?>
<?php foreach ($count['lines'] as $line): ?><p><?= pl_e(pl_money($line['denomination']) . ' × ' . $line['quantity'] . ' = ' . pl_money($line['amount'])) ?></p><?php endforeach; ?>
</div>
<?php endif; ?>
<?php if ($canWrite): ?>
<details class="rounded-panel border border-border bg-surface p-4" <?= $input !== [] ? 'open' : '' ?>><summary class="btn btn-primary"><?= pl_e(pl_t('Record a cash count')) ?></summary>
<form method="post" action="<?= pl_e(pl_url('/cash-counts')) ?>" class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
<?= pl_csrf_field() ?><?= pl_scope_fields($company) ?><input type="hidden" name="creation_key" value="<?= pl_e(pl_web_text($input, 'creation_key') ?: bin2hex(random_bytes(16))) ?>">
<label><?= pl_e(pl_t('Cash account')) ?><select class="input" name="account_id" required><option value=""><?= pl_e(pl_t('Choose an account')) ?></option><?php foreach ($accounts as $account): ?><option value="<?= $account['id'] ?>" <?= pl_web_id($input, 'account_id') === $account['id'] ? 'selected' : '' ?>><?= pl_e($account['code'] . ' · ' . $account['name']) ?></option><?php endforeach; ?></select></label>
<label><?= pl_e(pl_t('Count date')) ?><input class="input" type="date" name="count_date" value="<?= pl_e(pl_web_text($input,'count_date') ?: gmdate('Y-m-d')) ?>" required></label>
<label><?= pl_e(pl_t('Time counted (UTC)')) ?><input class="input" type="datetime-local" name="counted_at" value="<?= pl_e(pl_web_text($input,'counted_at') ?: gmdate('Y-m-d\TH:i')) ?>" required></label>
<label><?= pl_e(pl_t('Counted amount')) ?><input class="input" name="counted_amount" inputmode="decimal" value="<?= pl_e(pl_web_text($input,'counted_amount')) ?>" required></label>
<label class="sm:col-span-2"><?= pl_e(pl_t('Note')) ?><input class="input" name="note" maxlength="500" value="<?= pl_e(pl_web_text($input,'note')) ?>"></label>
<details class="sm:col-span-2"><summary><?= pl_e(pl_t('Optional notes and coins breakdown')) ?></summary><p class="muted"><?= pl_e(pl_t('The breakdown must equal the counted amount. Leave unused rows blank.')) ?></p>
<?php for ($i=0;$i<8;$i++): ?><div class="grid grid-cols-2 gap-2"><label><?= pl_e(pl_t('Denomination')) ?><input class="input" name="denomination[]" inputmode="decimal" value="<?= pl_e((string) ($input['denomination'][$i] ?? '')) ?>"></label><label><?= pl_e(pl_t('Quantity')) ?><input class="input" name="quantity[]" type="number" min="1" step="1" value="<?= pl_e((string) ($input['quantity'][$i] ?? '')) ?>"></label></div><?php endfor; ?></details>
<p class="muted sm:col-span-2"><?= pl_e(pl_t('Submitting records an immutable count and posts any overage or shortage. Correct a mistaken count with a new count and a reason.')) ?></p>
<button class="btn btn-primary" type="submit"><?= pl_e(pl_t('Record count and post difference')) ?></button>
</form></details>
<?php endif; ?>
<div class="rounded-panel border border-border bg-surface p-4"><h2 class="section-title"><?= pl_e(pl_t('Count history')) ?></h2>
<form method="get" action="<?= pl_e(pl_url('/cash-counts')) ?>" class="flex flex-wrap gap-2"><label><?= pl_e(pl_t('Filter by account')) ?><select class="input" name="account_id"><option value=""><?= pl_e(pl_t('All cash accounts')) ?></option><?php foreach ($accounts as $account): ?><option value="<?= $account['id'] ?>" <?= $selectedAccount === $account['id'] ? 'selected' : '' ?>><?= pl_e($account['name']) ?></option><?php endforeach; ?></select></label><button class="btn btn-secondary"><?= pl_e(pl_t('Filter')) ?></button></form>
<p class="muted"><?= pl_e(pl_t('Latest {count} counts; {agreed} agreed; net difference {net}; total absolute difference {gross}.', ['count'=>$history['total_counts'],'agreed'=>$history['agreed_counts'],'net'=>pl_money($history['net_difference']),'gross'=>pl_money($history['gross_difference'])])) ?></p>
<div class="table-wrap" tabindex="0" role="region" aria-label="<?= pl_e(pl_t('Cash count history')) ?>"><table class="table"><thead><tr><?php foreach (['Reference','Date','Account','Counted','Book balance','Difference','Counted by'] as $heading): ?><th scope="col"><?= pl_e(pl_t($heading)) ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach ($history['counts'] as $row): ?><tr><td><a href="<?= pl_e(pl_url('/cash-counts',['id'=>$row['id']])) ?>"><?= pl_e($row['reference']) ?></a></td><td><?= pl_e(pl_date_label($row['count_date'])) ?></td><td><?= pl_e($row['account_name']) ?></td><td class="amount"><?= pl_e(pl_money($row['counted_amount'])) ?></td><td class="amount"><?= pl_e(pl_money($row['book_balance'])) ?></td><td class="amount"><?= pl_e(pl_money($row['difference'])) ?></td><td><?= pl_e($row['counted_by_name']) ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
</section>
