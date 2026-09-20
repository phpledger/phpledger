<?php
declare(strict_types=1);
$canManage = pl_can_write($company) && !pl_demo_enabled();
$types = ['asset' => 'Asset', 'liability' => 'Liability', 'equity' => 'Equity', 'income' => 'Income', 'expense' => 'Expense'];
$roles = ['' => 'No operational purpose', 'cash_bank' => 'Cash / bank — Asset', 'receivables' => 'Receivables — Asset', 'payables' => 'Payables — Liability', 'owner_equity' => 'Owner equity — Equity', 'income' => 'Income — Income', 'expense' => 'Expense — Expense'];
$hasFailure = pl_web_text($form, 'message') !== '';
$isActive = $hasFailure ? pl_web_text($input, 'is_active') === '1' : (bool) ($input['is_active'] ?? true);
$isContra = $hasFailure ? pl_web_text($input, 'is_contra') === '1' : (bool) ($input['is_contra'] ?? false);
$levels = ['class' => 'Class heading', 'group' => 'Group heading', 'account' => 'Account', 'sub_account' => 'Sub-account'];
?>
<div class="flex flex-col gap-4 py-5">
<?php pl_ui_page_header('Chart of accounts',count($company['accounts']).' accounts · '.$company['currency'],static function () use ($canManage,$chartFilters):void { if ($canManage): ?><a class="btn btn-primary" href="<?= pl_e(pl_url('/accounts',['new'=>'1','return_filters'=>$chartFilters])) ?>"><?= pl_icon('plus') ?> New account</a><?php endif; },'accounts-title'); ?>
<div class="split-view">
<section class="split-view-list <?= $account || $isNew?'has-detail':'' ?>" aria-labelledby="accounts-title">
<form class="filter-bar flex-wrap" method="get" action="<?= pl_e(pl_url('/accounts')) ?>">
<label class="sr-only" for="chart-search">Search accounts</label><input class="input" id="chart-search" name="q" value="<?= pl_e($chartFilters['q']) ?>" placeholder="Code or account name" maxlength="160">
<label class="sr-only" for="chart-type">Filter by classification</label><select class="select" id="chart-type" name="type"><option value="all">All classifications</option><?php foreach ($types as $value=>$label): ?><option value="<?= pl_e($value) ?>" <?= $chartFilters['type']===$value?'selected':'' ?>><?= pl_e($label) ?></option><?php endforeach; ?></select>
<label class="sr-only" for="chart-status">Account status</label><select class="select" id="chart-status" name="status"><?php foreach (['all'=>'All statuses','active'=>'Active','inactive'=>'Inactive'] as $value=>$label): ?><option value="<?= pl_e($value) ?>" <?= $chartFilters['status']===$value?'selected':'' ?>><?= pl_e($label) ?></option><?php endforeach; ?></select>
<label class="sr-only" for="chart-size">Accounts per page</label><select class="select" id="chart-size" name="per_page"><?php foreach ([25,50,100] as $size): ?><option value="<?= $size ?>" <?= $chartFilters['per_page']===$size?'selected':'' ?>><?= $size ?> per page</option><?php endforeach; ?></select>
<input type="hidden" name="sort" value="<?= pl_e($chartFilters['sort']) ?>"><input type="hidden" name="dir" value="<?= pl_e($chartFilters['dir']) ?>"><button class="btn btn-secondary">Filter</button><a class="btn btn-ghost" href="<?= pl_e(pl_url('/accounts')) ?>">Clear</a>
</form><p class="text-xs text-ink-muted mb-2"><?= (int)$accountList['total'] ?> matching accounts · Sort within classification: <?php pl_ui_sort('/accounts',$chartFilters,'code','Code'); ?> · <?php pl_ui_sort('/accounts',$chartFilters,'name','Name'); ?></p>
<div class="table-wrap max-h-[560px] overflow-auto" tabindex="0" role="region" aria-label="Accounts">
<table class="table"><caption class="sr-only">Chart of accounts and posted balances through <?= pl_e(pl_date_label($balanceDate)) ?>. Debit balances for assets and expenses; credit balances for other classifications.</caption><thead><tr><th scope="col">Code / account</th><th scope="col">Purpose</th><th scope="col">Status</th><th scope="col" class="num">Balance</th></tr></thead><tbody>
<?php foreach ($types as $type=>$typeLabel): $group=array_filter($accountList['rows'],static fn(array $row):bool=>$row['type']===$type); if (!$group) { continue; } ?>
<tr class="bg-surface-subtle"><th scope="rowgroup" colspan="4" class="text-xs uppercase tracking-wide text-ink-muted"><?= pl_e($typeLabel) ?></th></tr>
<?php foreach ($group as $row): $selected=$account && (int)$account['id']===(int)$row['id']; $balance=$balances[(int)$row['id']]??'0.0000'; ?>
<tr<?= $selected?' aria-selected="true"':'' ?>><th scope="row"><a class="row-title link" href="<?= pl_e(pl_url('/accounts',['id'=>$row['id'],'return_filters'=>$chartFilters])) ?>"<?= $selected?' aria-current="true"':'' ?>><?= pl_e($row['name']) ?></a><span class="row-sub"><?= pl_e($row['code']) ?></span></th><td class="text-xs text-ink-muted"><?= pl_e($row['role']?ucfirst(str_replace('_',' / ',$row['role'])):($levels[$row['level']]??'—')) ?><?= $row['is_contra']?' · Contra':'' ?></td><td><?php pl_ui_badge($row['is_active']?'active':'inactive'); ?></td><td class="num"><a class="link" href="<?= pl_e(pl_url('/reports/account',['id'=>$row['id'],'as_of'=>$balanceDate])) ?>" aria-label="<?= pl_e('Statement for '.$row['name'].': '.$company['currency'].' '.pl_money($balance)) ?>"><?= pl_e(pl_money($balance)) ?></a></td></tr>
<?php endforeach; endforeach; ?>
</tbody></table></div>
<?php if (!$accountList['total']) { pl_ui_empty('No matching accounts','Clear or change the filters to see other accounts.'); } ?>
<?php pl_ui_pagination('/accounts',$chartFilters,$accountList['page'],$accountList['pages']); ?>
<p class="text-xs text-ink-muted mt-3">Posted balances through <?= pl_e(pl_date_label($balanceDate)) ?>. Inactive accounts retain their balances and history.</p>
</section>
<section class="split-view-detail p-4 gap-3" aria-labelledby="account-detail-title">
    <a class="btn btn-ghost self-start" href="<?= pl_e(pl_url('/accounts',$chartFilters)) ?>"><?= pl_icon('arrow-left') ?> Back to accounts</a>
    <?php if ($hasFailure): ?><div class="alert alert-danger" role="alert" tabindex="-1" data-form-error><strong>Your account was not saved.</strong><p><?= pl_e(pl_web_text($form, 'message')) ?></p><p>Your submitted values are retained.<?php if ($account): ?> <a href="<?= pl_e(pl_url('/accounts', ['id' => $account['id'],'return_filters'=>$chartFilters])) ?>">Reload the latest saved version</a> before resolving a revision conflict.<?php endif; ?></p></div><?php endif; ?>
    <?php if ($account || ($isNew && $canManage)): ?>
    <div class="section-heading"><h2 class="section-title" id="account-detail-title"><?= $account ? pl_e($account['name']) : 'New account' ?></h2><?php if ($canManage): ?><button class="btn btn-primary" type="submit" form="account-editor" data-fold="primary action"><?= $account ? 'Save account' : 'Create account' ?></button><?php elseif ($account): ?><span class="muted small">Revision <?= (int)$account['revision'] ?></span><?php endif; ?></div>
    <?php if ($canManage): ?>
    <form id="account-editor" method="post" action="<?= pl_e(pl_url('/accounts/save')) ?>" class="flex flex-col gap-3">
        <?php pl_ui_return_filters($chartFilters); ?>
        <?= pl_csrf_field() ?><?= pl_scope_fields($company) ?>
        <input type="hidden" name="creation_key" value="<?= pl_e(pl_web_text($input, 'creation_key')) ?>">
        <?php if ($account): ?><input type="hidden" name="id" value="<?= (int) $account['id'] ?>"><input type="hidden" name="revision" value="<?= pl_web_id($input, 'revision') ?>">
        <dl class="record-details"><div><dt>Code</dt><dd><?= pl_e($account['code']) ?><?= $account['legacy_code'] ? ' <span class="text-xs text-ink-muted">was ' . pl_e($account['legacy_code']) . '</span>' : '' ?></dd></div><div><dt>Level</dt><dd><?= pl_e($levels[$account['level']] ?? 'Account') ?><?= $account['is_postable'] ? '' : ' · aggregates the accounts below it' ?></dd></div><div><dt>Classification</dt><dd><?= pl_e($types[$account['type']] ?? $account['type']) ?><?= $account['is_contra'] ? ' · contra' : '' ?></dd></div><div><dt>Purpose</dt><dd><?= pl_e($roles[$account['role'] ?? ''] ?? 'No operational purpose') ?></dd></div></dl>
        <?php foreach (['code', 'type', 'role'] as $fixedField): ?><input type="hidden" name="<?= pl_e($fixedField) ?>" value="<?= pl_e((string) ($account[$fixedField] ?? '')) ?>"><?php endforeach; ?>
        <p class="muted small">Code, classification and purpose are fixed. Use a new account and a reviewed correction for a classification change.</p>
        <?php else: ?>
        <div class="grid grid-cols-2 gap-3">
            <label class="field">Account code<input class="input" name="code" value="<?= pl_e(pl_web_text($input, 'code')) ?>" maxlength="20" pattern="[A-Za-z0-9][A-Za-z0-9._-]{0,19}" required autocomplete="off"><span class="field-hint">Structured code <code>X-XXX-XXXXX-XX</code>: class, group, account, sub-account. <code>3-000-00000-00</code> is the Equity heading, <code>3-100-00000-00</code> a group heading, <code>3-100-10001-00</code> an account and <code>3-100-10001-01</code> a sub-account under it. A heading is not posted to; postings belong on the lowest account. Group numbers 900 and above are reserved for contra accounts.</span></label>
            <label class="field">Classification<select class="select" name="type" required><?php foreach ($types as $value => $label): ?><option value="<?= pl_e($value) ?>" <?= pl_web_text($input, 'type') === $value ? 'selected' : '' ?>><?= pl_e($label) ?></option><?php endforeach; ?></select></label>
            <label class="field col-span-2">Operational purpose<select class="select" name="role"><?php foreach ($roles as $value => $label): ?><option value="<?= pl_e($value) ?>" <?= pl_web_text($input, 'role') === $value ? 'selected' : '' ?>><?= pl_e($label) ?></option><?php endforeach; ?></select><span class="field-hint">Choose a purpose with the same classification, or no operational purpose.</span></label>
        </div>
        <?php endif; ?>
        <p class="field-hint" data-account-consequence><?= in_array(pl_web_text($input,'type'),['income','expense'],true)?'This account appears on Profit & loss.':'This account appears on the Balance sheet.' ?> Assets, liabilities and equity appear on the Balance sheet; income and expenses appear on Profit & loss.</p>
        <?php if (!$account || $account['type']==='expense'): ?><div class="field"><label for="account-report-section">Profit &amp; loss section</label><select id="account-report-section" class="select" name="report_classification" aria-describedby="classification-hint"><option value="">Unclassified — keep existing expense presentation</option><option value="cost_of_sales" <?= pl_web_text($input,'report_classification')==='cost_of_sales'?'selected':'' ?>>Cost of sales — expense accounts only</option></select></div><p id="classification-hint" class="field-hint">Changing this section affects reports for every period. Posted amounts and net profit stay unchanged.</p><?php endif; ?>
        <label class="field">Account name<input class="input" name="name" value="<?= pl_e(pl_web_text($input, 'name')) ?>" maxlength="120" required></label>
        <?php if ($operationalWarning): ?><?php pl_ui_strip('This account supports cash/bank, receivable, payable or tax operations. Deactivating it prevents new entries that need it. Existing balances and history remain; review affected workflows before saving.', 'warning'); ?><?php endif; ?>
        <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="is_active" value="1" <?= $isActive ? 'checked' : '' ?>><span>Active — available for new entries</span></label>
        <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="is_contra" value="1" <?= $isContra ? 'checked' : '' ?>><span>Contra account — presented as a deduction inside its own section<span class="block text-xs text-ink-muted">Accumulated depreciation and provisions against assets, sales returns and discounts allowed, purchase returns and discounts received, and drawings. The flag changes presentation only: no posted entry moves, and clearing it restores the earlier presentation exactly.</span></span></label>
        <label class="field">Reason <?= $account ? 'for this change' : 'for adding this account' ?><textarea name="reason" rows="2" maxlength="500" required><?= pl_e(pl_web_text($input, 'reason')) ?></textarea></label>
        <div class="panel-actions"><?php if ($account): ?><a class="btn btn-secondary" href="<?= pl_e(pl_url('/reports/account', ['id' => $account['id']])) ?>">Open statement</a><?php endif; ?></div>
    </form>
    <?php else: ?>
    <dl class="record-details"><div><dt>Code</dt><dd><?= pl_e($account['code']) ?></dd></div><div><dt>Classification</dt><dd><?= pl_e($types[$account['type']] ?? $account['type']) ?></dd></div><div><dt>Purpose</dt><dd><?= pl_e($roles[$account['role'] ?? ''] ?? 'No operational purpose') ?></dd></div><div><dt>Status</dt><dd><?= $account['is_active'] ? 'Active' : 'Inactive' ?></dd></div></dl>
    <a class="btn btn-secondary" href="<?= pl_e(pl_url('/reports/account', ['id' => $account['id']])) ?>">Open statement</a>
    <p class="muted small core-footnote"><?= pl_demo_enabled() ? 'Account changes are unavailable in the public demo.' : 'Your role can view accounts and their history.' ?></p>
    <?php endif; ?>
    <?php if ($account): ?><details class="text-xs border-t border-border pt-3"><summary>Account history <span class="muted">· Latest 50 changes</span></summary><?php if (!$history): ?><p class="muted small">No account-management changes have been recorded.</p><?php endif; ?><ol><?php foreach ($history as $event): ?><li><strong><?= pl_e(ucfirst(str_replace('_', ' ', $event['action']))) ?></strong> · <?= pl_e($event['display_name']) ?><p><?= pl_e($event['reason']) ?></p><time data-local-time datetime="<?= pl_e(str_replace(' ', 'T', $event['recorded_at']) . 'Z') ?>"><?= pl_e($event['recorded_at']) ?> UTC</time></li><?php endforeach; ?></ol></details><?php endif; ?>
    <?php else: ?><div class="empty-state"><h2 class="section-title" id="account-detail-title"><?= $isNew ? 'Account changes unavailable' : 'Choose an account' ?></h2><p><?= $isNew ? 'Account changes are available to authorised administrators outside the public demo.' : 'Inspect its classification, open its statement or review its history.' ?></p></div><?php endif; ?>
</section></div>
</div>
