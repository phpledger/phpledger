<?php
declare(strict_types=1);
$canWrite = pl_can_write($company);
$reversalHistory = $reversalHistory ?? [];
$scheduleForm = $scheduleForm ?? ['message' => '', 'input' => []];
$documentSource = null;
$generalSource = null;
if (preg_match('/^document:([1-9][0-9]*)$/D', (string) $journal['source_reference'], $sourceMatch)) {
    $documentSource = $sourceMatch[1];
}
if ($journal['source_type'] === 'general_journal' && preg_match('/^general:([1-9][0-9]*)$/D', (string) $journal['source_reference'], $sourceMatch)) {
    $generalSource = $sourceMatch[1];
}
?>
<section class="flex flex-col gap-4 my-5 rounded-panel border border-border bg-surface" aria-labelledby="journal-title">
<?php pl_ui_document_header($journal['reference'],'posted',static function (): void { ?><a class="btn btn-secondary" href="<?= pl_e(pl_url('/reports/trial-balance')) ?>"><?= pl_e(pl_t('Trial balance')) ?></a><?php },'journal-title'); ?>
<p class="text-sm text-ink-muted px-5"><?= pl_e($company['name'].' · '.$journal['currency'].' · '.pl_date_label($journal['journal_date'])) ?></p>
    <div class="doc-body">
        <h2 class="section-title"><?= pl_e(pl_t('Entry details')) ?></h2>
        <p><?= pl_e((string) $journal['description']) ?></p>
        <dl class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div><dt><?= pl_e(pl_t('Source type')) ?></dt><dd><?= pl_e(ucfirst((string) $journal['source_type'])) ?></dd></div>
            <div><dt><?= pl_e(pl_t('Source reference')) ?></dt><dd><?= pl_e((string) $journal['source_reference']) ?></dd></div>
            <div><dt><?= pl_e(pl_t('Posted at')) ?></dt><dd><time datetime="<?= pl_e(str_replace(' ', 'T', (string) $journal['posted_at']) . 'Z') ?>" data-local-time><?= pl_e(pl_t('{time} UTC', ['time' => (string) $journal['posted_at']])) ?></time></dd></div>
        </dl>
        <div class="flex flex-wrap gap-2">
            <?php if (!empty($commercialSource)): ?><a class="btn btn-secondary" href="<?= pl_e(pl_workflow_url(in_array($commercialSource['kind'],['invoice','customer_credit'],true)?'/ar':'/ap',['id'=>$commercialSource['id']])) ?>"><?= pl_e(pl_t('View source {number}', ['number' => $commercialSource['number']])) ?></a><?php endif; ?>
            <?php if (in_array($journal['source_type'], ['open_item_settlement', 'open_item_batch_settlement'], true)): ?><a class="btn btn-secondary" href="<?= pl_e(pl_url('/print/settlement/' . $journal['id'])) ?>"><?= pl_icon('printer') ?> <?= pl_e(pl_t('Print receipt')) ?></a><?php endif; ?>
            <?php if ($documentSource !== null): ?><a class="btn btn-secondary" href="<?= pl_e(pl_workflow_url('/transactions/detail', ['id' => $documentSource,'return_account'=>$accountReturn])) ?>"><?= pl_e(pl_t('View source transaction')) ?></a><?php endif; ?>
            <?php if ($journal['source_type'] === 'opening_balance'): ?><a class="btn btn-secondary" href="<?= pl_e(pl_url('/opening-balances')) ?>"><?= pl_e(pl_t('View opening cutover')) ?></a><?php endif; ?>
            <?php if ($generalSource !== null): ?><a class="btn btn-secondary" href="<?= pl_e(pl_workflow_url('/general-journals/detail', ['id' => $generalSource,'return_account'=>$accountReturn])) ?>"><?= pl_e(pl_t('View source general journal')) ?></a><?php endif; ?>
            <?php if ($journal['reversal_of_id'] !== null): ?><a class="btn btn-secondary" href="<?= pl_e(pl_workflow_url('/journals/detail', ['id' => $journal['reversal_of_id'],'return_account'=>$accountReturn])) ?>"><?= pl_e(pl_t('View original journal')) ?></a><?php endif; ?>
        </div>
        <?php if ($journal['reversal_of_id'] !== null): ?><p class="badge"><?= pl_e(pl_t('Linked reversal')) ?></p><?php endif; ?>
        <p class="text-xs text-ink-muted"><?= pl_e(pl_t('Select an account name below to see its running balance. Posted entries are preserved; corrections use a linked reversal.')) ?></p>
    </div>
    <div class="table-wrap mx-5 mb-5" tabindex="0" role="region" aria-label="<?= pl_e(pl_t('Posted journal lines')) ?>">
        <table class="table">
            <caption><?= pl_e(pl_t('Journal lines in {currency}', ['currency' => (string) $journal['currency']])) ?></caption>
            <thead><tr><th scope="col"><?= pl_e(pl_t('Account')) ?></th><th scope="col"><?= pl_e(pl_t('Description')) ?></th><th scope="col" class="amount"><?= pl_e(pl_t('Debit')) ?></th><th scope="col" class="amount"><?= pl_e(pl_t('Credit')) ?></th></tr></thead>
            <tbody>
                <?php foreach ($journal['lines'] as $line): ?>
                    <tr><th scope="row"><a href="<?= pl_e(pl_url('/reports/account', ['id' => $line['account_id'], 'as_of' => $journal['journal_date']])) ?>"><?= pl_e($line['code'] . ' — ' . $line['name']) ?></a></th><td><?= pl_e((string) $line['description']) ?></td><td class="amount"><?= pl_e(pl_money((string) $line['debit'])) ?></td><td class="amount"><?= pl_e(pl_money((string) $line['credit'])) ?></td></tr>
                <?php endforeach; ?>
            </tbody><?php $totals=pl_general_totals($journal['lines']); ?><tfoot><tr><th scope="row" colspan="2"><?= pl_e(pl_t('Total')) ?></th><td class="num"><?= pl_e(pl_money($totals['debit'])) ?></td><td class="num"><?= pl_e(pl_money($totals['credit'])) ?></td></tr></tfoot>
        </table>
    </div>
</section>
<?php if ($canWrite && $journal['reversal_of_id'] === null && in_array($journal['source_type'], ['general','general_journal','receipt','payment','adjustment'], true)): ?>
<section class="rounded-panel border border-border bg-surface p-4 my-4"><h2 class="section-title"><?= pl_e(pl_t('Scheduled reversal')) ?></h2>
<p><?= pl_e(pl_t('The linked reversal posts when its period opens. If that period is already open, saving the schedule posts it immediately. Leave the date blank to clear a pending schedule.')) ?></p>
<?php if ($scheduleForm['message'] !== ''): ?><p class="alert alert-danger" role="alert"><?= pl_e($scheduleForm['message']) ?></p><?php endif; ?>
<form method="post" action="<?= pl_e(pl_url('/periods/schedule-reversal')) ?>" class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
<?= pl_csrf_field() ?><?= pl_scope_fields($company) ?><input type="hidden" name="journal_id" value="<?= (int) $journal['id'] ?>">
<label><?= pl_e(pl_t('Reverse on')) ?><input class="input" type="date" name="reverse_on" value="<?= pl_e(pl_web_text($scheduleForm['input'],'reverse_on')) ?>"></label>
<label><?= pl_e(pl_t('Reason')) ?><input class="input" name="reason" maxlength="400" required></label><button class="btn btn-secondary"><?= pl_e(pl_t('Save reversal schedule')) ?></button>
</form></section>
<?php endif; ?>
<?php if ($reversalHistory !== []): ?><section class="rounded-panel border border-border bg-surface p-4 my-4"><h2 class="section-title"><?= pl_e(pl_t('Reversal schedule history')) ?></h2><ul><?php foreach ($reversalHistory as $event): ?><li><?= pl_e($event['event'] . ' · ' . ($event['reverse_on'] ?? '') . ' · ' . $event['actor_name'] . ' · ' . $event['created_at'] . ' UTC · ' . $event['reason']) ?></li><?php endforeach; ?></ul></section><?php endif; ?>
