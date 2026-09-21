<?php
declare(strict_types=1);
/**
 * Reports > Ownership (1.2 M8a, issue #92). The snapshot, book value per share, the partner
 * capital account statement, the related-party transaction report and the director loan movement
 * report, all read-only and all authorised inside pl_web_ownership_reports().
 *
 * @var string $title @var array $user @var array $company @var string $asOf @var string $from
 * @var array<string,mixed> $snapshot @var array<string,mixed> $bookValue @var array<string,mixed> $capital
 * @var bool $mayReadRelated @var array<string,mixed>|null $related @var array<string,mixed>|null $directorLoans
 */
$dash = '—';
$percent = static fn (?string $value): string => $value === null ? $dash : $value . '%';
?>
<div class="flex flex-col gap-4 py-5">
<?php pl_ui_page_header(pl_t('Ownership reports'), pl_t('{company} · {from} to {asOf}', ['company' => $company['name'], 'from' => pl_date_label($from), 'asOf' => pl_date_label($asOf)]), static function () use ($asOf): void { ?>
<a class="btn btn-secondary" href="<?= pl_e(pl_url('/ownership')) ?>"><?= pl_icon('book') ?> <?= pl_e(pl_t('Ownership register')) ?></a>
<a class="btn btn-secondary" href="<?= pl_e(pl_url('/ownership/export', ['as_of' => $asOf])) ?>"><?= pl_icon('download') ?> <?= pl_e(pl_t('Export (Open Cap Format)')) ?></a>
<?php }, 'ownership-reports-title'); ?>

<form method="get" action="<?= pl_e(pl_url('/reports/ownership')) ?>" class="flex flex-wrap items-end gap-3">
<label class="field"><?= pl_e(pl_t('From')) ?><input class="input" type="date" name="from" value="<?= pl_e($from) ?>" required></label>
<label class="field"><?= pl_e(pl_t('As of')) ?><input class="input" type="date" name="as_of" value="<?= pl_e($asOf) ?>" required></label>
<button class="btn btn-primary" type="submit"><?= pl_e(pl_t('Update')) ?></button>
</form>

<!-- Ownership snapshot -->
<section class="flex flex-col gap-2" aria-labelledby="ownership-snapshot"><h2 class="section-title" id="ownership-snapshot"><?= pl_e(pl_t('Ownership snapshot')) ?></h2>
<div class="grid grid-cols-2 md:grid-cols-4 gap-px overflow-hidden rounded-panel border border-border bg-border">
<div class="bg-surface px-4 py-3"><p class="text-xs text-ink-muted"><?= pl_e(pl_t('Outstanding shares')) ?></p><p class="amount-lg mt-1"><?= pl_e($snapshot['outstanding_shares']) ?></p></div>
<div class="bg-surface px-4 py-3"><p class="text-xs text-ink-muted"><?= pl_e(pl_t('Issued shares (every class)')) ?></p><p class="amount-lg mt-1"><?= pl_e($snapshot['issued_shares']) ?></p></div>
<div class="bg-surface px-4 py-3"><p class="text-xs text-ink-muted"><?= pl_e(pl_t('Fully diluted shares')) ?></p><p class="amount-lg mt-1"><?= pl_e($snapshot['fully_diluted_shares']) ?></p></div>
<div class="bg-surface px-4 py-3"><p class="text-xs text-ink-muted"><?= pl_e(pl_t('Total votes')) ?></p><p class="amount-lg mt-1"><?= pl_e($snapshot['total_votes']) ?></p></div>
</div>
<p class="text-xs text-ink-muted"><?= pl_e(pl_t('Shares are counted, not money, and are printed exactly as the register holds them.')) ?></p>

<?php foreach ($snapshot['holdings'] as $entry): $class = $entry['class']; ?>
<h3 class="section-title"><?= pl_e($class['code'] . ' · ' . $class['name']) ?></h3>
<div class="table-wrap" tabindex="0" role="region" aria-label="<?= pl_e(pl_t('Holdings in {code}', ['code' => $class['code']])) ?>"><table class="table"><caption class="sr-only"><?= pl_e(pl_t('Holders of {code} at {asOf}', ['code' => $class['code'], 'asOf' => pl_date_label($asOf)])) ?></caption>
<thead><tr><th scope="col"><?= pl_e(pl_t('Holder')) ?></th><th scope="col" class="num"><?= pl_e(pl_t('Shares')) ?></th><th scope="col" class="num"><?= pl_e(pl_t('% of class')) ?></th><th scope="col" class="num"><?= pl_e(pl_t('% outstanding')) ?></th><th scope="col" class="num"><?= pl_e(pl_t('% fully diluted')) ?></th><th scope="col" class="num"><?= pl_e(pl_t('Votes')) ?></th><th scope="col" class="num"><?= pl_e(pl_t('% of votes')) ?></th></tr></thead><tbody>
<?php foreach ($entry['holders'] as $holder): ?>
<tr><th scope="row"><?= pl_e($holder['name']) ?></th><td class="num"><?= pl_e($holder['shares']) ?></td><td class="num"><?= pl_e($percent($holder['percent_of_class'])) ?></td><td class="num"><?= pl_e($percent($holder['percent_outstanding'])) ?></td><td class="num"><?= pl_e($percent($holder['percent_fully_diluted'])) ?></td><td class="num"><?= pl_e($holder['votes']) ?></td><td class="num"><?= pl_e($percent($holder['percent_votes'])) ?></td></tr>
<?php endforeach; ?>
<?php if ($entry['holders'] === []): ?><tr><td colspan="7"><?= pl_e(pl_t('Nobody holds shares in this class at this date.')) ?></td></tr><?php endif; ?>
</tbody></table></div>
<?php endforeach; ?>
</section>

<!-- Book value per share -->
<section class="flex flex-col gap-2" aria-labelledby="ownership-book-value"><h2 class="section-title" id="ownership-book-value"><?= pl_e(pl_t('Book value per share')) ?></h2>
<div class="grid grid-cols-2 md:grid-cols-4 gap-px overflow-hidden rounded-panel border border-border bg-border">
<div class="bg-surface px-4 py-3"><p class="text-xs text-ink-muted"><?= pl_e(pl_t('Total equity')) ?></p><p class="amount-lg mt-1"><?= pl_e(pl_money($bookValue['total_equity'])) ?></p></div>
<div class="bg-surface px-4 py-3"><p class="text-xs text-ink-muted"><?= pl_e(pl_t('Outstanding shares')) ?></p><p class="amount-lg mt-1"><?= pl_e($bookValue['outstanding_shares']) ?></p></div>
<div class="bg-surface px-4 py-3"><p class="text-xs text-ink-muted"><?= pl_e(pl_t('Book value per share')) ?></p><p class="amount-lg mt-1"><?= pl_e($bookValue['book_value_per_share'] !== null ? pl_money($bookValue['book_value_per_share']) : $dash) ?></p></div>
<div class="bg-surface px-4 py-3"><p class="text-xs text-ink-muted"><?= pl_e(pl_t('Book value per share, fully diluted')) ?></p><p class="amount-lg mt-1"><?= pl_e($bookValue['book_value_per_share_diluted'] !== null ? pl_money($bookValue['book_value_per_share_diluted']) : $dash) ?></p></div>
</div>
<p class="field-hint"><?= pl_e($bookValue['basis']) ?></p>
<?php if ($bookValue['has_preference_class']): ?>
<?php pl_ui_strip(pl_t('A preference class exists, so the ordinary-share figure above is overstated by any liquidation preference the preference class carries.'), 'warning'); ?>
<?php endif; ?>
</section>

<!-- Partner capital account statement -->
<section class="flex flex-col gap-2" aria-labelledby="ownership-capital-statement"><h2 class="section-title" id="ownership-capital-statement"><?= pl_e(pl_t('Partner capital account statement')) ?></h2>
<div class="table-wrap" tabindex="0" role="region" aria-label="<?= pl_e(pl_t('Partner capital account statement')) ?>"><table class="table"><caption class="sr-only"><?= pl_e(pl_t('Movement on each partner\'s capital, drawings and loan accounts')) ?></caption>
<thead><tr><th scope="col"><?= pl_e(pl_t('Partner')) ?></th><th scope="col"><?= pl_e(pl_t('Register person')) ?></th><th scope="col" class="num"><?= pl_e(pl_t('Profit share')) ?></th><th scope="col" class="num"><?= pl_e(pl_t('Capital opening')) ?></th><th scope="col" class="num"><?= pl_e(pl_t('Capital introduced')) ?></th><th scope="col" class="num"><?= pl_e(pl_t('Drawings')) ?></th><th scope="col" class="num"><?= pl_e(pl_t('Capital closing')) ?></th><th scope="col" class="num"><?= pl_e(pl_t('Fixed capital')) ?></th><th scope="col" class="num"><?= pl_e(pl_t('Fluctuating capital')) ?></th><th scope="col" class="num"><?= pl_e(pl_t('Loan opening')) ?></th><th scope="col" class="num"><?= pl_e(pl_t('Loan advanced')) ?></th><th scope="col" class="num"><?= pl_e(pl_t('Loan repaid')) ?></th><th scope="col" class="num"><?= pl_e(pl_t('Loan closing')) ?></th></tr></thead><tbody>
<?php foreach ($capital['partners'] as $row): ?>
<tr><th scope="row"><?= pl_e($row['name']) ?></th><td><?= pl_e($row['register_person'] ?? $dash) ?></td><td class="num"><?= pl_e($row['profit_share']) ?></td><td class="num"><?= pl_e(pl_money($row['capital_opening'])) ?></td><td class="num"><?= pl_e(pl_money($row['capital_introduced'])) ?></td><td class="num"><?= pl_e(pl_money($row['drawings_period'])) ?></td><td class="num"><?= pl_e(pl_money($row['capital_closing'])) ?></td><td class="num"><?= pl_e(pl_money($row['fixed_capital'])) ?></td><td class="num"><?= pl_e(pl_money($row['fluctuating_capital'])) ?></td><td class="num"><?= pl_e(pl_money($row['loan_opening'])) ?></td><td class="num"><?= pl_e(pl_money($row['loan_advanced'])) ?></td><td class="num"><?= pl_e(pl_money($row['loan_repaid'])) ?></td><td class="num"><?= pl_e(pl_money($row['loan_closing'])) ?></td></tr>
<?php endforeach; ?>
<?php if ($capital['partners'] === []): ?><tr><td colspan="13"><?= pl_e(pl_t('No partners are recorded for this book.')) ?></td></tr><?php else: ?>
<tr class="stmt-total"><th scope="row"><?= pl_e(pl_t('Total')) ?></th><td></td><td></td><td class="num"><?= pl_e(pl_money($capital['totals']['opening'])) ?></td><td class="num"><?= pl_e(pl_money($capital['totals']['introduced'])) ?></td><td class="num"><?= pl_e(pl_money($capital['totals']['drawings'])) ?></td><td class="num"><?= pl_e(pl_money($capital['totals']['closing'])) ?></td><td></td><td></td><td></td><td></td><td></td><td class="num"><?= pl_e(pl_money($capital['totals']['loan_closing'])) ?></td></tr>
<?php endif; ?>
</tbody></table></div>
<p class="field-hint"><?= pl_e($capital['note']) ?></p>
</section>

<!-- Related-party transactions -->
<?php if (!$mayReadRelated || $related === null): ?>
<?php pl_ui_strip(pl_t('Related-party information is restricted. Your role cannot read it.'), 'info'); ?>
<?php else: ?>
<section class="flex flex-col gap-3" aria-labelledby="ownership-related-transactions"><h2 class="section-title" id="ownership-related-transactions"><?= pl_e(pl_t('Related-party transactions')) ?></h2>
<?php foreach ($related['parties'] as $party): ?>
<div class="panel p-4 flex flex-col gap-2">
<h3 class="section-title"><?= pl_e($party['legal_name']) ?></h3>
<ul class="text-sm text-ink-muted">
<?php foreach ($party['relationships'] as $relationship): ?>
<li><?= pl_e($relationship['relationship_label']) ?> — <?= pl_e($relationship['subject_name']) ?><?php if ($relationship['subject_detail'] !== '') : ?> (<?= pl_e($relationship['subject_detail']) ?>)<?php endif; ?></li>
<?php endforeach; ?>
</ul>
<p class="text-sm"><?= pl_e(pl_t('Terms: {terms}', ['terms' => $party['terms'] !== '' ? $party['terms'] : pl_t('not recorded')])) ?></p>
<div class="grid grid-cols-2 md:grid-cols-4 gap-px overflow-hidden rounded-panel border border-border bg-border">
<div class="bg-surface px-4 py-3"><p class="text-xs text-ink-muted"><?= pl_e(pl_t('Opening balance')) ?></p><p class="amount-lg mt-1"><?= pl_e(pl_money($party['opening_balance'])) ?></p></div>
<div class="bg-surface px-4 py-3"><p class="text-xs text-ink-muted"><?= pl_e(pl_t('Invoiced')) ?></p><p class="amount-lg mt-1"><?= pl_e(pl_money($party['invoiced'])) ?></p></div>
<div class="bg-surface px-4 py-3"><p class="text-xs text-ink-muted"><?= pl_e(pl_t('Settled')) ?></p><p class="amount-lg mt-1"><?= pl_e(pl_money($party['settled'])) ?></p></div>
<div class="bg-surface px-4 py-3"><p class="text-xs text-ink-muted"><?= pl_e(pl_t('Closing balance')) ?></p><p class="amount-lg mt-1"><?= pl_e(pl_money($party['closing_balance'])) ?></p></div>
</div>
<?php if (bccomp((string) $party['unapplied'], '0', 4) !== 0): ?><p class="text-xs text-ink-muted"><?= pl_e(pl_t('Unapplied: {amount}', ['amount' => pl_money($party['unapplied'])])) ?></p><?php endif; ?>
<div class="table-wrap" tabindex="0" role="region" aria-label="<?= pl_e(pl_t('Transactions with {name}', ['name' => $party['legal_name']])) ?>"><table class="table table-dense"><caption class="sr-only"><?= pl_e(pl_t('Transactions with {name} in the period', ['name' => $party['legal_name']])) ?></caption>
<thead><tr><th scope="col"><?= pl_e(pl_t('Date')) ?></th><th scope="col"><?= pl_e(pl_t('Type')) ?></th><th scope="col"><?= pl_e(pl_t('Number')) ?></th><th scope="col" class="num"><?= pl_e(pl_t('Debit')) ?></th><th scope="col" class="num"><?= pl_e(pl_t('Credit')) ?></th><th scope="col" class="num"><?= pl_e(pl_t('Balance')) ?></th></tr></thead><tbody>
<?php foreach ($party['transactions'] as $line): ?>
<tr><td><?= pl_e(pl_date_label($line['date'])) ?></td><td><?= pl_e($line['type']) ?></td><td><?= pl_e($line['number']) ?></td><td class="num"><?= pl_e(pl_money($line['debit'])) ?></td><td class="num"><?= pl_e(pl_money($line['credit'])) ?></td><td class="num"><?= pl_e(pl_money($line['balance'])) ?></td></tr>
<?php endforeach; ?>
<?php if ($party['transactions'] === []): ?><tr><td colspan="6"><?= pl_e(pl_t('No transactions in this period.')) ?></td></tr><?php endif; ?>
</tbody></table></div>
</div>
<?php endforeach; ?>
<?php if ($related['parties'] === []): ?><p class="text-sm text-ink-muted"><?= pl_e(pl_t('No related party carried a transaction in this period.')) ?></p><?php endif; ?>

<div class="table-wrap" tabindex="0" role="region" aria-label="<?= pl_e(pl_t('Provision accounts')) ?>"><table class="table table-dense"><caption class="sr-only"><?= pl_e(pl_t('Contra-asset accounts that may carry a provision')) ?></caption>
<thead><tr><th scope="col"><?= pl_e(pl_t('Code')) ?></th><th scope="col"><?= pl_e(pl_t('Account')) ?></th><th scope="col" class="num"><?= pl_e(pl_t('Balance')) ?></th></tr></thead><tbody>
<?php foreach ($related['provision_accounts'] as $account): ?>
<tr><td><?= pl_e($account['code']) ?></td><td><?= pl_e($account['name']) ?></td><td class="num"><?= pl_e(pl_money($account['balance'])) ?></td></tr>
<?php endforeach; ?>
<?php if ($related['provision_accounts'] === []): ?><tr><td colspan="3"><?= pl_e(pl_t('This book has no contra-asset accounts.')) ?></td></tr><?php endif; ?>
</tbody></table></div>
<p class="field-hint"><?= pl_e($related['provision_note']) ?></p>
</section>
<?php endif; ?>

<!-- Director loan movements -->
<?php if ($mayReadRelated && $directorLoans !== null): ?>
<section class="flex flex-col gap-2" aria-labelledby="ownership-director-loans"><h2 class="section-title" id="ownership-director-loans"><?= pl_e(pl_t('Director loan movements')) ?></h2>
<div class="table-wrap" tabindex="0" role="region" aria-label="<?= pl_e(pl_t('Director loan movements')) ?>"><table class="table"><caption class="sr-only"><?= pl_e(pl_t('Opening, advanced, repaid and closing for each director')) ?></caption>
<thead><tr><th scope="col"><?= pl_e(pl_t('Director')) ?></th><th scope="col"><?= pl_e(pl_t('Role')) ?></th><th scope="col"><?= pl_e(pl_t('Direction')) ?></th><th scope="col"><?= pl_e(pl_t('Account')) ?></th><th scope="col" class="num"><?= pl_e(pl_t('Opening')) ?></th><th scope="col" class="num"><?= pl_e(pl_t('Advanced')) ?></th><th scope="col" class="num"><?= pl_e(pl_t('Repaid')) ?></th><th scope="col" class="num"><?= pl_e(pl_t('Closing')) ?></th></tr></thead><tbody>
<?php foreach ($directorLoans['directors'] as $row): ?>
<?php if ($row['account'] === null): ?>
<tr><th scope="row"><?= pl_e($row['name']) ?></th><td><?= pl_e($row['role']) ?></td><td colspan="6" class="text-ink-muted"><?= pl_e($row['reason']) ?></td></tr>
<?php else: ?>
<tr><th scope="row"><?= pl_e($row['name']) ?></th><td><?= pl_e($row['role']) ?></td><td><?= pl_e($row['direction_label']) ?></td><td><?= pl_e($row['account']['code'] . ' · ' . $row['account']['name']) ?></td><td class="num"><?= pl_e(pl_money($row['opening'])) ?></td><td class="num"><?= pl_e(pl_money($row['advanced'])) ?></td><td class="num"><?= pl_e(pl_money($row['repaid'])) ?></td><td class="num"><?= pl_e(pl_money($row['closing'])) ?></td></tr>
<?php endif; ?>
<?php endforeach; ?>
<?php if ($directorLoans['directors'] === []): ?><tr><td colspan="8"><?= pl_e(pl_t('Nobody holds a directorship in the period.')) ?></td></tr><?php else: ?>
<tr class="stmt-total"><th scope="row"><?= pl_e(pl_t('Total')) ?></th><td></td><td></td><td></td><td class="num"><?= pl_e(pl_money($directorLoans['totals']['opening'])) ?></td><td class="num"><?= pl_e(pl_money($directorLoans['totals']['advanced'])) ?></td><td class="num"><?= pl_e(pl_money($directorLoans['totals']['repaid'])) ?></td><td class="num"><?= pl_e(pl_money($directorLoans['totals']['closing'])) ?></td></tr>
<?php endif; ?>
</tbody></table></div>
<?php if (!$directorLoans['reconciles']): ?>
<?php pl_ui_strip(pl_t('The director loan movement does not reconcile: opening plus advanced less repaid does not equal closing. Check the dates and the accounts linked to each partner record.'), 'warning'); ?>
<?php endif; ?>
</section>
<?php endif; ?>
</div>
