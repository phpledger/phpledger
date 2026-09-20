<?php
declare(strict_types=1);
$numberingInput = $form['input'];
$canEditNumbering = ($company['role'] ?? '') === 'owner' && !pl_demo_enabled();
?>
<section class="flex flex-col gap-4 py-5" aria-labelledby="numbering-title">
    <div class="page-header">
        <div><p class="eyebrow">Books and controls</p><h1 class="page-title" id="numbering-title">Document numbering</h1><p class="muted">One running series per document type, in this book.</p></div>
        <a class="btn btn-secondary" href="<?= pl_e(pl_url('/ar')) ?>">View invoices</a>
    </div>
    <?php if ($form['message'] !== ''): ?>
        <div class="alert alert-danger" role="alert" tabindex="-1" data-form-error><h2 class="section-title mb-2">Numbering change needs attention</h2><p><?= pl_e((string) $form['message']) ?></p><p>Your entered values are preserved. Review the current series below before trying again.</p></div>
    <?php endif; ?>
    <div class="rounded-panel border border-border bg-surface p-4"><h2 class="section-title mb-2">How a number is issued</h2><p>A draft has no number. The number is allocated when the document is posted, and it is never issued twice or released again. The prefix, width, year segment and reset rule can change at any time; the next number can only be raised, never lowered, because a lowered number would be issued to a second document.</p><p class="muted">Numbering is set per document type for the whole book. There is no separate series per warehouse or location.</p></div>
    <?php if (!$canEditNumbering): ?>
        <div class="rounded-panel border border-border bg-surface p-4"><p><?= pl_demo_enabled() ? 'Numbering administration is disabled in the public sample.' : 'Your role can read the number series. Only the business owner can change them.' ?></p></div>
    <?php endif; ?>
    <div class="rounded-panel border border-border bg-surface p-4"><h2 class="section-title mb-2">Series</h2>
        <div class="table-wrap" tabindex="0" role="region" aria-label="Document number series"><table class="table"><thead><tr><th scope="col">Document type</th><th scope="col">Prefix</th><th scope="col">Width</th><th scope="col">Year segment</th><th scope="col">Reset</th><th scope="col">Next number</th><th scope="col">Action</th></tr></thead><tbody>
            <?php foreach ($series as $entry): ?>
                <?php $rowInput = pl_web_text($numberingInput, 'document_type') === $entry['document_type'] ? $numberingInput : []; ?>
                <tr>
                    <td><?= pl_e($entry['label']) ?><span class="row-sub"><?= pl_e($entry['example']) ?></span></td>
                    <td><?= pl_e($entry['prefix']) ?></td>
                    <td><?= (int) $entry['padding'] ?></td>
                    <td><?= $entry['year_segment'] ? 'Yes' : 'No' ?></td>
                    <td><?= $entry['reset_rule'] === 'yearly' ? 'Every year' : 'Never' ?></td>
                    <td><?= (int) $entry['next_number'] ?><?php if ($entry['reset_rule'] === 'yearly' && $entry['series_year'] > 0): ?><span class="row-sub">in <?= (int) $entry['series_year'] ?></span><?php elseif ($entry['reset_rule'] === 'yearly'): ?><span class="row-sub">no document issued yet</span><?php endif; ?></td>
                    <td>
                        <?php if ($canEditNumbering): ?>
                            <details data-confirmation<?= $rowInput !== [] ? ' open' : '' ?>><summary class="btn btn-secondary btn-sm">Change numbering</summary>
                            <div data-confirmation-body><h2 class="section-title"><?= pl_e($entry['label']) ?> numbering</h2><p class="field-hint">Currently <?= pl_e($entry['example']) ?>. Documents already issued keep their numbers.</p>
                            <?php if ($rowInput && $form['message'] !== ''): ?><p class="alert alert-danger" role="alert"><?= pl_e($form['message']) ?></p><?php endif; ?>
                            <form action="<?= pl_e(pl_url('/numbering')) ?>" method="post" class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
                                <?= pl_csrf_field() ?><?= pl_scope_fields($company) ?>
                                <input type="hidden" name="document_type" value="<?= pl_e($entry['document_type']) ?>">
                                <input type="hidden" name="revision" value="<?= (int) $entry['revision'] ?>">
                                <div class="field"><label for="numbering-prefix-<?= pl_e($entry['document_type']) ?>">Prefix</label><input class="input" id="numbering-prefix-<?= pl_e($entry['document_type']) ?>" name="prefix" maxlength="10" value="<?= pl_e(pl_web_text($rowInput, 'prefix', $entry['prefix'])) ?>" required><p class="muted">One to ten capital letters or digits, starting with a letter.</p></div>
                                <div class="field"><label for="numbering-padding-<?= pl_e($entry['document_type']) ?>">Running-number width</label><input class="input" id="numbering-padding-<?= pl_e($entry['document_type']) ?>" name="padding" type="number" min="1" max="12" value="<?= pl_e(pl_web_text($rowInput, 'padding', (string) $entry['padding'])) ?>" required></div>
                                <div class="field"><label for="numbering-year-<?= pl_e($entry['document_type']) ?>">Year segment</label><select class="input" id="numbering-year-<?= pl_e($entry['document_type']) ?>" name="year_segment">
                                    <?php $yearValue = pl_web_text($rowInput, 'year_segment', $entry['year_segment'] ? '1' : '0'); ?>
                                    <option value="1"<?= $yearValue === '1' ? ' selected' : '' ?>>Include the year</option>
                                    <option value="0"<?= $yearValue === '1' ? '' : ' selected' ?>>Omit the year</option>
                                </select></div>
                                <div class="field"><label for="numbering-reset-<?= pl_e($entry['document_type']) ?>">Reset rule</label><select class="input" id="numbering-reset-<?= pl_e($entry['document_type']) ?>" name="reset_rule">
                                    <?php $resetValue = pl_web_text($rowInput, 'reset_rule', $entry['reset_rule']); ?>
                                    <option value="yearly"<?= $resetValue === 'yearly' ? ' selected' : '' ?>>Restart at 1 every year</option>
                                    <option value="never"<?= $resetValue === 'never' ? ' selected' : '' ?>>Never reset</option>
                                </select><p class="muted">A yearly reset needs the year segment.</p></div>
                                <div class="field"><label for="numbering-next-<?= pl_e($entry['document_type']) ?>">Next number</label><input class="input" id="numbering-next-<?= pl_e($entry['document_type']) ?>" name="next_number" type="number" min="<?= (int) $entry['next_number'] ?>" value="<?= pl_e(pl_web_text($rowInput, 'next_number', (string) $entry['next_number'])) ?>" required><p class="muted">May be raised to skip numbers; it can never be lowered.</p></div>
                                <div class="field sm:col-span-2"><label for="numbering-reason-<?= pl_e($entry['document_type']) ?>">Reason for this change</label><input class="input" id="numbering-reason-<?= pl_e($entry['document_type']) ?>" name="reason" maxlength="500" value="<?= pl_e(pl_web_text($rowInput, 'reason')) ?>" required></div>
                                <div class="flex gap-2 sm:col-span-2"><button class="btn btn-primary" type="submit">Save numbering</button></div>
                            </form><button type="button" class="btn btn-ghost mt-3" data-confirmation-cancel hidden>Cancel</button></div>
                            </details>
                        <?php else: ?><span class="muted">Owner only</span><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody></table></div>
    </div>
</section>
