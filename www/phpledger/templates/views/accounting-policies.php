<?php
declare(strict_types=1);
$policyInput = $form['input'];
$canEditPolicies = ($company['role'] ?? '') === 'owner' && !pl_demo_enabled();
$value = static fn (string $field, string $fallback): string => pl_web_text($policyInput, $field, $fallback);
?>
<section class="flex flex-col gap-4 py-5" aria-labelledby="policies-title">
    <div class="page-header">
        <div><p class="eyebrow">Books and controls</p><h1 class="page-title" id="policies-title">Accounting policies</h1><p class="muted">How this book posts trading discounts, free goods and cash taken on an invoice.</p></div>
        <a class="btn btn-secondary" href="<?= pl_e(pl_url('/company-profile')) ?>">Company profile</a>
    </div>
    <?php if ($form['message'] !== ''): ?>
        <div class="alert alert-danger" role="alert" tabindex="-1" data-form-error><h2 class="section-title mb-2">This policy change needs attention</h2><p><?= pl_e((string) $form['message']) ?></p><p>Your entered values are preserved. Review the current policies below before trying again.</p></div>
    <?php endif; ?>
    <div class="rounded-panel border border-border bg-surface p-4">
        <h2 class="section-title mb-2">What these policies decide</h2>
        <p>Each policy is decided for this book and recorded with a reason. A change never restates a document already posted: the entries a document carries are the entries the policy produced on the day it was posted, and the history below shows every change.</p>
        <p class="muted">The worked examples for each value are in <code>docs/accounting/examples/trading-document-policies.md</code>.</p>
    </div>
    <?php if (!$canEditPolicies): ?>
        <div class="rounded-panel border border-border bg-surface p-4"><p><?= pl_demo_enabled() ? 'Policy administration is disabled in the public sample.' : 'Your role can read these policies. Only the business owner can change them.' ?></p></div>
    <?php endif; ?>
    <div class="rounded-panel border border-border bg-surface p-4">
        <h2 class="section-title mb-2">Current policies</h2>
        <dl class="print-facts">
            <dt>Line discounts</dt><dd><?= $policies['discount_posting'] === 'gross' ? 'Gross to income, discount shown as contra-income' : 'Net to income (recommended)' ?></dd>
            <dt>Free goods, output tax</dt><dd><?= $policies['free_goods_output_tax'] === 'open_market_value' ? 'Charged at open-market value and borne by the business' : 'None (recommended)' ?></dd>
            <dt>Cash on an invoice</dt><dd><?= bccomp((string) $policies['cash_on_invoice_cap'], '0', 4) === 0 ? 'Not accepted (recommended default)' : 'Up to ' . pl_e(pl_money((string) $policies['cash_on_invoice_cap'])) . ' per invoice' ?></dd>
        </dl>
    </div>
    <?php if ($canEditPolicies): ?>
    <div class="rounded-panel border border-border bg-surface p-4">
        <h2 class="section-title mb-2">Change the policies</h2>
        <form action="<?= pl_e(pl_url('/accounting-policies')) ?>" method="post" class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <?= pl_csrf_field() ?><?= pl_scope_fields($company) ?>
            <input type="hidden" name="revision" value="<?= (int) $policies['revision'] ?>">
            <input type="hidden" name="request_key" value="<?= pl_e((string) $requestKey) ?>">
            <div class="field"><label for="policy-discount">Line discount posting</label>
                <?php $discountPosting = $value('discount_posting', (string) $policies['discount_posting']); ?>
                <select class="input" id="policy-discount" name="discount_posting">
                    <option value="net"<?= $discountPosting === 'net' ? ' selected' : '' ?>>Net to income (recommended)</option>
                    <option value="gross"<?= $discountPosting === 'gross' ? ' selected' : '' ?>>Gross to income, discount as contra-income</option>
                </select>
                <p class="muted">Net keeps the discount out of the ledger. Gross recognises the undiscounted sale and shows the reduction as a contra-income figure on the profit and loss account.</p>
            </div>
            <div class="field"><label for="policy-discount-account">Discounts allowed account</label>
                <?php $discountAccount = $value('discount_account_id', (string) ($policies['discount_account_id'] ?? '')); ?>
                <select class="input" id="policy-discount-account" name="discount_account_id">
                    <option value="">Not selected</option>
                    <?php foreach ($accounts['discount'] as $account): ?>
                        <option value="<?= (int) $account['id'] ?>"<?= $discountAccount === (string) $account['id'] ? ' selected' : '' ?>><?= pl_e($account['code'] . ' — ' . $account['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="muted">A contra-income account. Group 4-910 of every class is reserved for discounts allowed.</p>
            </div>
            <div class="field"><label for="policy-free-tax">Free goods, output tax</label>
                <?php $freeTax = $value('free_goods_output_tax', (string) $policies['free_goods_output_tax']); ?>
                <select class="input" id="policy-free-tax" name="free_goods_output_tax">
                    <option value="none"<?= $freeTax === 'none' ? ' selected' : '' ?>>No output tax (recommended)</option>
                    <option value="open_market_value"<?= $freeTax === 'open_market_value' ? ' selected' : '' ?>>Output tax at open-market value</option>
                </select>
                <p class="muted">Where a tax authority treats a free supply as taxable, the tax is charged on the open-market value entered on the free line and borne by the business, never billed to the customer.</p>
            </div>
            <div class="field"><label for="policy-free-account">Free goods expense account</label>
                <?php $freeAccount = $value('free_goods_account_id', (string) ($policies['free_goods_account_id'] ?? '')); ?>
                <select class="input" id="policy-free-account" name="free_goods_account_id">
                    <option value="">Not selected</option>
                    <?php foreach ($accounts['free_goods'] as $account): ?>
                        <option value="<?= (int) $account['id'] ?>"<?= $freeAccount === (string) $account['id'] ? ' selected' : '' ?>><?= pl_e($account['code'] . ' — ' . $account['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="muted">The carrying value of goods given away goes here rather than to cost of sales, because nothing was sold.</p>
            </div>
            <div class="field"><label for="policy-cash-cap">Cash-on-invoice cap</label>
                <input class="input" id="policy-cash-cap" name="cash_on_invoice_cap" inputmode="decimal" value="<?= pl_e($value('cash_on_invoice_cap', (string) $policies['cash_on_invoice_cap'])) ?>" required>
                <p class="muted">The most cash one invoice may record. Zero — the recommended default — means an invoice takes no cash at all and every receipt is entered on the payments screen.</p>
            </div>
            <div class="field sm:col-span-2"><label for="policy-reason">Reason for this change</label><input class="input" id="policy-reason" name="reason" maxlength="500" value="<?= pl_e(pl_web_text($policyInput, 'reason')) ?>" required></div>
            <div class="flex gap-2 sm:col-span-2"><button class="btn btn-primary" type="submit">Save policies</button></div>
        </form>
    </div>
    <?php endif; ?>
    <div class="rounded-panel border border-border bg-surface p-4">
        <h2 class="section-title mb-2">Change history</h2>
        <?php if ($history === []): ?><p class="muted">No policy or profile change has been recorded for this business.</p><?php else: ?>
        <div class="table-wrap" tabindex="0" role="region" aria-label="Accounting policy history"><table class="table">
            <thead><tr><th scope="col">Recorded</th><th scope="col">What</th><th scope="col">By</th><th scope="col">Reason</th></tr></thead>
            <tbody>
            <?php foreach ($history as $entry): ?>
                <tr><th scope="row"><?= pl_e((string) $entry['recorded_at']) ?></th><td><?= $entry['scope'] === 'company_profile' ? 'Company profile' : 'Accounting policies' ?></td><td><?= pl_e((string) $entry['display_name']) ?></td><td><?= pl_e((string) $entry['reason']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
</section>
