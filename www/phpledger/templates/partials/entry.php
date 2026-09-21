<?php declare(strict_types=1); ?>
<div class="table-wrap"><table class="table entry-table">
    <caption class="sr-only"><?= pl_e($entryCaption ?? pl_t('Balanced journal lines')) ?></caption>
    <thead><tr><th scope="col"><?= pl_e(pl_t('Account')) ?></th><th scope="col" class="amount"><?= pl_e(pl_t('Debit ({currency})', ['currency' => $company['currency']])) ?></th><th scope="col" class="amount"><?= pl_e(pl_t('Credit ({currency})', ['currency' => $company['currency']])) ?></th></tr></thead>
    <tbody><?php foreach ($entryLines as $line): ?><tr><td><span class="account-code"><?= pl_e($line['code']) ?></span><?= pl_e($line['name']) ?></td><td class="amount"><?= pl_e(pl_money($line['debit'])) ?></td><td class="amount"><?= pl_e(pl_money($line['credit'])) ?></td></tr><?php endforeach; ?></tbody>
    <tfoot><tr><th scope="row"><?= pl_e(pl_t('Total')) ?></th><td class="amount"><?= pl_e(pl_money(array_reduce($entryLines, static fn (string $sum, array $line): string => bcadd($sum, $line['debit'], 4), '0.0000'))) ?></td><td class="amount"><?= pl_e(pl_money(array_reduce($entryLines, static fn (string $sum, array $line): string => bcadd($sum, $line['credit'], 4), '0.0000'))) ?></td></tr></tfoot>
</table></div>
