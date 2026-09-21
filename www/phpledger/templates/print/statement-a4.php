<?php declare(strict_types=1);
/*
 * Party statement (1.2 M3; frame customer-statement.html).
 *
 * Opening balance, every movement in the range, and the closing balance — all read from
 * the party's control-account entries, so the statement reconciles to the ledger by
 * construction. Decision 9: money held with nothing left to apply it to is stated inline
 * in the closing-balance caption rather than in a separate table.
 *
 * @var array $document Read-only statement data from pl_trading_statement_print().
 */
$statement = $document;
$party = $statement['party'];
$addressLines = pl_print_party_lines($party['details']['addresses'] ?? null);
$receivable = $statement['direction'] === 'receivable';
?>
<section class="print-parties">
    <div class="print-party">
        <h2><?= $receivable ? 'Customer' : 'Supplier' ?></h2>
        <p class="print-party-name"><?= pl_e((string) $party['legal_name']) ?></p>
        <?php foreach ($addressLines as $line): ?><p><?= pl_e($line) ?></p><?php endforeach; ?>
    </div>
    <div class="print-party">
        <h2>Statement</h2>
        <dl class="print-facts">
            <dt>From</dt><dd><?= pl_e(pl_date_label((string) $statement['from'])) ?></dd>
            <dt>To</dt><dd><?= pl_e(pl_date_label((string) $statement['to'])) ?></dd>
            <dt>Currency</dt><dd><?= pl_e((string) $statement['currency']) ?></dd>
            <dt>Opening balance</dt><dd><?= pl_e(pl_money((string) $statement['opening_balance'])) ?></dd>
        </dl>
    </div>
</section>
<div class="print-lines-scroll">
<table class="print-lines">
    <caption>Activity <?= pl_e(pl_date_label((string) $statement['from'])) ?> to <?= pl_e(pl_date_label((string) $statement['to'])) ?></caption>
    <thead><tr>
        <th scope="col">Date</th><th scope="col">Type</th><th scope="col">Document</th>
        <th scope="col" class="print-num">Debit</th><th scope="col" class="print-num">Credit</th><th scope="col" class="print-num">Balance</th>
    </tr></thead>
    <tbody>
    <tr><th scope="row" colspan="5">Opening balance, <?= pl_e(pl_date_label((string) $statement['from'])) ?></th><td class="print-num"><?= pl_e(pl_money((string) $statement['opening_balance'])) ?></td></tr>
    <?php foreach ($statement['rows'] as $row): ?>
        <tr>
            <th scope="row"><?= pl_e(pl_date_label((string) $row['date'])) ?></th>
            <td><?= pl_e((string) $row['type']) ?></td>
            <td><?= pl_e((string) $row['number']) ?></td>
            <td class="print-num"><?= bccomp((string) $row['debit'], '0', 4) > 0 ? pl_e(pl_money((string) $row['debit'])) : '' ?></td>
            <td class="print-num"><?= bccomp((string) $row['credit'], '0', 4) > 0 ? pl_e(pl_money((string) $row['credit'])) : '' ?></td>
            <td class="print-num"><?= pl_e(pl_money((string) $row['balance'])) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr><th scope="row" colspan="5">Closing balance, <?= pl_e(pl_date_label((string) $statement['to'])) ?><?= bccomp((string) $statement['unapplied'], '0', 4) > 0 ? ' · includes ' . pl_e(pl_money((string) $statement['unapplied'])) . ' held as an unapplied advance' : '' ?></th><td class="print-num"><?= pl_e(pl_money((string) $statement['closing_balance'])) ?></td></tr>
    </tfoot>
</table>
</div>
<section class="print-totals">
    <div class="print-total-row"><span>Opening balance</span><span class="print-num"><?= pl_e(pl_money((string) $statement['opening_balance'])) ?></span></div>
    <div class="print-total-row"><span><?= $receivable ? 'Invoiced this period' : 'Billed this period' ?></span><span class="print-num"><?= pl_e(pl_money((string) $statement['invoiced'])) ?></span></div>
    <div class="print-total-row"><span><?= $receivable ? 'Received this period' : 'Paid this period' ?></span><span class="print-num"><?= pl_e(pl_money((string) $statement['received'])) ?></span></div>
    <div class="print-total-row print-total-grand"><span>Closing balance (<?= pl_e((string) $statement['currency']) ?>)</span><span class="print-num"><?= pl_e(pl_money((string) $statement['closing_balance'])) ?></span></div>
</section>
