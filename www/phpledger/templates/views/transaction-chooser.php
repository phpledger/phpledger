<?php declare(strict_types=1); ?>
<section class="py-5" aria-labelledby="transaction-chooser-title">
    <a class="back-link" href="<?= pl_e(pl_workflow_url('/transactions', $returnFilters)) ?>"><?= pl_icon('arrow-left') ?> <?= pl_e(pl_t('Back to transactions')) ?></a>
    <?php pl_ui_page_header(pl_t('New transaction'), pl_t('Choose the screen for the money you are recording.')); ?>
    <h2 id="transaction-chooser-title" class="field-label mb-3"><?= pl_e(pl_t('Transaction type')) ?></h2>
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <a class="choice-card" href="<?= pl_e(pl_workflow_url('/expenses/new', ['return_filters'=>$returnFilters])) ?>"><span class="choice-body"><span class="choice-title"><?= pl_e(pl_t('Money out · Expense')) ?></span><span class="choice-detail"><?= pl_e(pl_t('Record money paid from your cash or bank account.')) ?></span></span></a>
        <a class="choice-card" href="<?= pl_e(pl_workflow_url('/receipts/new', ['return_filters'=>$returnFilters])) ?>"><span class="choice-body"><span class="choice-title"><?= pl_e(pl_t('Money in · Receipt')) ?></span><span class="choice-detail"><?= pl_e(pl_t('Record money received into your cash or bank account.')) ?></span></span></a>
    </div>
</section>
