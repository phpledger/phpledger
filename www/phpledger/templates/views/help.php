<?php declare(strict_types=1); ?>
<section class="flex flex-col gap-4 py-5" aria-labelledby="help-title">
    <?php pl_ui_page_header(pl_t('From first entry to a clear report'), pl_t('Choose a workflow and follow its records into your reports.'), static function (): void { ?>
        <a class="btn btn-secondary" href="<?= pl_e(pl_url('/companies')) ?>"><?= pl_e(pl_t('Your businesses')) ?></a>
    <?php }, 'help-title'); ?>
    <section class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 mb-5" data-fold="task cards" aria-label="<?= pl_e(pl_t('Choose a task')) ?>">
        <?php
        $tasks = [
            [pl_demo_enabled() ? '/sample-guide' : '/onboarding', 'Choose your starting point', 'New business, existing books, or a separate sample company.'],
            ['/transactions', 'Record a receipt or expense', 'Save a draft, review the journal effect, then post when it is correct.'],
            ['/ar', 'Invoice a customer', 'Build an invoice with lines and tax, then track it to payment.'],
            ['/opening-balances', 'Bring an existing business forward', 'Preview a reviewed trial balance and unpaid documents before confirming cutover.'],
            ['/sample-guide', 'Try the sample company', 'Explore posted history and editable drafts in separate fictional books.'],
            ['/pos', 'Try the sample shop (POS)', 'Record a cash sale that posts through the same ledger.'],
        ];
        foreach ($tasks as [$path, $label, $description]):
            if (pl_demo_enabled() && $path === '/opening-balances') { continue; }
            if ($path === '/pos' && $company && !pl_module_available((int) $user['id'], (int) $company['id'], (int) $company['book_id'], 'pos-showcase')) { continue; }
        ?>
        <a class="task-card no-underline" href="<?= pl_e(pl_url($path)) ?>"><span class="task-card-title"><?= pl_e(pl_t($label)) ?></span><span class="task-card-desc"><?= pl_e(pl_t($description)) ?></span></a>
        <?php endforeach; ?>
    </section>
    <div class="rounded-panel border border-border bg-surface p-4">
        <h2 class="section-title mb-2"><?= pl_e(pl_t('Correct a posted transaction')) ?></h2>
        <p><?= pl_e(pl_t('Open the transaction and create a linked reversal with a date and a clear reason. The reversal must fall in an open period and cannot predate the original. Both entries remain visible. Create a new correct transaction when needed.')) ?></p>
        <p><?= pl_e(pl_t('If a draft changed in another tab or by another person, compare your preserved entries with the latest saved version before saving again. Repeated posting must never be used to create a second copy.')) ?></p>
    </div>
    <details class="rounded-panel border border-border bg-surface"><summary class="cursor-pointer px-4 py-3 text-sm font-semibold"><?= pl_e(pl_t('What this release includes')) ?></summary><div class="border-t border-border p-4 text-sm">
        <p><?= pl_e(pl_t('Essential setup, a country-neutral account template, one functional currency per company, receipts and expenses, general journals, invoices and bills with settlement and ageing, purchase orders and receiving, inventory, cash POS, reports, linked reversals, opening cutover, period administration, and bank CSV reconciliation.')) ?></p>
        <p><?= pl_e(pl_t('Detailed historical journal imports, XLSX import, foreign-currency revaluation, country-specific tax filing, and production retail or restaurant features remain future work. Sample histories are sample scenarios; they do not certify suitability for an industry or jurisdiction.')) ?></p>
        <p class="muted"><?= pl_e(pl_t('If an installation already contains foundation records, an owner or accountant can review the existing account mappings and balances. That review preserves the old records and cannot be used to skip missing opening data.')) ?></p>
    </div></details>
    <details class="rounded-panel border border-border bg-surface"><summary class="cursor-pointer px-4 py-3 text-sm font-semibold"><?= pl_e(pl_t('Choose the right starting point')) ?></summary><div class="border-t border-border p-4 text-sm">
        <ul>
            <li><strong><?= pl_e(pl_t('New business:')) ?></strong> <?= pl_e(pl_t('confirm there are no prior balances or unpaid documents, review the neutral account template, and create empty books.')) ?></li>
            <li><strong><?= pl_e(pl_t('Sample company:')) ?></strong> <?= pl_e(pl_t('explore fictional posted entries and editable drafts in a separate, clearly marked company.')) ?></li>
            <li><strong><?= pl_e(pl_t('Existing business:')) ?></strong> <?= pl_e(pl_t('save setup, then open')) ?> <strong><?= pl_e(pl_t('Opening balances')) ?></strong><?= pl_e(pl_t('. Enter balances or upload/paste the documented CSV, include remaining unpaid invoices/bills, and review the balanced preview. Confirmation brings forward balances at the close of your accounting start date; new transactions start the next day.')) ?></li>
        </ul>
        <?php if (!pl_demo_enabled()): ?><a class="btn btn-primary" href="<?= pl_e(pl_url('/onboarding')) ?>"><?= pl_e(pl_t('Set up or explore a business')) ?></a><?php else: ?><p class="alert alert-info"><?= pl_e(pl_t('In this public demo your sample is created for you and resets hourly. Business administration, imports, deletion and period changes are disabled.')) ?></p><?php endif; ?>
    </div></details>
    <details class="rounded-panel border border-border bg-surface"><summary class="cursor-pointer px-4 py-3 text-sm font-semibold"><?= pl_e(pl_t('Record a receipt or expense')) ?></summary><div class="border-t border-border p-4 text-sm">
        <ol>
            <li><?= pl_e(pl_t('Open the intended business and check its name and currency.')) ?></li>
            <li><?= pl_e(pl_t('Create a receipt for money coming in or an expense for money going out. Select or create the payer or payee, then enter the date, amount, cash/bank account, category, and a useful description.')) ?></li>
            <li><strong><?= pl_e(pl_t('Save draft')) ?></strong> <?= pl_e(pl_t('to keep your work. A saved draft does not affect the books.')) ?></li>
            <li><?= pl_e(pl_t('Review the saved details and journal effect, then')) ?> <strong><?= pl_e(pl_t('Post')) ?></strong> <?= pl_e(pl_t('when they are correct.')) ?></li>
            <li><?= pl_e(pl_t('Open the trial balance, select the affected account, and follow its activity back to the source transaction.')) ?></li>
        </ol>
        <p><?= pl_e(pl_t('Owners and accountants can write; viewers can inspect the books and reports. A saved draft is editable. A posted transaction is preserved.')) ?></p>
    </div></details>
    <div class="rounded-panel border border-border bg-surface p-4">
        <h2 class="section-title"><?= pl_e(pl_t('Cash available and the right payment workflow')) ?></h2>
        <p><?= pl_e(pl_t('A balanced journal does not prove that cash was available. Physical cash payments cannot create or worsen a cash shortfall, including on later dates affected by a backdated entry. Drafts do not reserve cash. Record actual funding or correct the payment account before posting; bank payments are checked against zero or an explicitly configured agreed overdraft limit. A bank book balance and this limit check do not confirm bank clearance or available funds.')) ?></p>
        <p><?= pl_e(pl_t('An overdraft permits an agreed negative bank balance. A separate loan or credit line is a liability: record an actual drawdown into the bank account before spending it. An unused credit limit is not a receipt and does not increase the bank balance. Record interest and charges separately from loan principal.')) ?></p>
        <p><?= pl_e(pl_t('If a supplier has not been paid, record a bill instead of a cash expense. If an owner paid personally, use the appropriate owner funding or loan workflow. For a bank-to-cash transfer, record the actual transfer; do not record it as income.')) ?></p>
        <p><?= pl_e(pl_t('Selecting a party identifies who paid or received the money. It does not settle an invoice or bill. Use Receivables or Payables for settlements and supported advances so income or expense is not recorded twice.')) ?></p>
    </div>
    <details class="rounded-panel border border-border bg-surface"><summary class="cursor-pointer px-4 py-3 text-sm font-semibold"><?= pl_e(pl_t('Try the sample shop')) ?></summary><div class="border-t border-border p-4 text-sm">
        <p><?= pl_e(pl_t('The point-of-sale showcase has six fictional products. Add quantities, review the cart, enter cash received, and record the sale. Its receipt links to the same accounting journal and reports as other receipts.')) ?></p>
        <p><?= pl_e(pl_t('Check the business name before checkout: the sale is recorded in those books. No actual payment is collected. Inventory, cost of goods sold, tax, discounts, credit sales, and restaurant operations are not included.')) ?></p>
        <a class="btn btn-secondary" href="<?= pl_e(pl_url('/pos')) ?>"><?= pl_e(pl_t('Open point of sale')) ?></a>
    </div></details>
    <details class="rounded-panel border border-border bg-surface"><summary class="cursor-pointer px-4 py-3 text-sm font-semibold"><?= pl_e(pl_t('Close periods and reconcile a bank account')) ?></summary><div class="border-t border-border p-4 text-sm"><p><?= pl_e(pl_t('Use')) ?> <strong><?= pl_e(pl_t('Periods')) ?></strong> <?= pl_e(pl_t('to create a date range or close it with a reason. Closing stops new postings in that range and preserves existing entries. Only the owner can reopen it, with a recorded reason. Closing does not create year-end profit-transfer entries or accounting statements.')) ?></p><p><?= pl_e(pl_t('Use')) ?> <strong><?= pl_e(pl_t('Bank reconciliation')) ?></strong> <?= pl_e(pl_t('to preview a statement CSV and confirm its import. Check opening and closing balances, then explicitly match each bank row to a posted line. Amounts must agree; multiple suggestions need your choice. Complete reconciliation only when all bank rows are matched and the adjusted bank balance equals the ledger. Unmatched ledger payments/deposits carry forward as outstanding.')) ?></p><p><?= pl_e(pl_t('The first statement requires you to confirm that all earlier bank entries have cleared and its opening balance equals the ledger before its start. Later statements must be consecutive. Completed reconciliations preserve their totals and prevent backdated changes to that bank account; later correcting entries remain traceable.')) ?></p></div></details>
</section>
