<?php declare(strict_types=1); ?>
<section class="auth-panel" aria-label="<?= pl_e(pl_t('Your businesses')) ?>">
<?php pl_ui_page_header(pl_t('Your businesses'), pl_t('Choose the books you want to work with, or set up a separate business.')); ?>
<div class="flex flex-wrap gap-2 mb-4" data-fold="primary action">
<?php if (pl_sample_companies_allowed()): ?><a class="btn btn-secondary btn-sm" href="<?= pl_e(pl_url('/sample-chooser')) ?>"><?= pl_e(pl_t('Try a sample company')) ?></a><?php endif; ?>
<a class="btn btn-primary btn-sm" href="<?= pl_e(pl_url('/onboarding')) ?>"><?= pl_e(pl_t('Set up a business')) ?></a>
</div>
<?php if ($companies === []): ?>
<?php pl_ui_empty(pl_t('Start with your business or try a sample'), pl_t('Create a new set of books, prepare an existing business for its opening review, or explore a separate fictional sample company.')); ?>
<a class="link" href="<?= pl_e(pl_url('/help')) ?>"><?= pl_e(pl_t('Read the getting-started guide')) ?></a>
<?php else: ?>
<div class="flex flex-col gap-2.5">
<?php foreach ($companies as $business): ?>
<article class="biz-row" aria-labelledby="company-<?= (int)$business['id'] ?>">
<span class="biz-row-mark" aria-hidden="true"><?= pl_e(mb_strtoupper(mb_substr($business['name'],0,1))) ?></span>
<div class="min-w-0 flex-1">
<div class="flex flex-wrap items-center gap-1.5"><h2 class="text-sm font-semibold" id="company-<?= (int)$business['id'] ?>"><?= pl_e($business['name']) ?></h2><?php pl_ui_badge($business['is_sample'] ? 'sample' : 'draft', $business['is_sample'] ? pl_t('Sample company') : pl_t('Business')); pl_ui_badge('draft', ucfirst($business['role'])); ?></div>
<p class="mt-0.5 text-xs text-ink-muted"><?= pl_e($business['book_name']) ?> · <?= pl_e($business['currency']) ?></p>
<?php if ($business['setup_status'] === 'opening_required'): ?><p class="mt-1 text-xs text-warning"><?= pl_e(pl_t('Opening balances need reconciliation. Preview and confirm the opening cutover before posting.')) ?></p>
<?php elseif ($business['setup_status'] === 'review_required'): ?><p class="mt-1 text-xs text-warning"><?= pl_e(pl_t('Review your existing accounts and opening balances before recording more transactions.')) ?></p>
<?php else: ?><p class="mt-1 text-xs text-ink-muted"><?= pl_e($business['is_sample'] ? pl_t('Fictional records for exploring the accounting journey.') : pl_t('Ready for receipt and expense entry.')) ?></p><?php endif; ?>
</div>
<form action="<?= pl_e(pl_url('/company/select')) ?>" method="post"><?= pl_csrf_field() ?><?= pl_scope_fields($business) ?><button class="btn btn-secondary btn-sm" type="submit" aria-label="<?= pl_e(pl_t('Open {business}', ['business' => $business['name']])) ?>"><?= pl_e(pl_t('Open books')) ?></button></form>
</article>
<?php endforeach; ?>
</div>
<p class="mt-4 text-xs text-ink-muted"><?= pl_e(pl_t('Sample companies have separate books. Always check the business name before entering or posting a transaction.')) ?></p>
<?php endif; ?>
</section>
