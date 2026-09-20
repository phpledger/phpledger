<?php
declare(strict_types=1);
$profileInput = $form['input'];
$canEditProfile = ($company['role'] ?? '') === 'owner' && !pl_demo_enabled();
$profileFields = [
    'legal_name' => ['Registered name', 'The name printed at the top of a document. Left empty, the business name is used.', 200],
    'address_line1' => ['Address line 1', '', 200],
    'address_line2' => ['Address line 2', '', 200],
    'address_line3' => ['Address line 3', '', 200],
    'phone' => ['Phone', '', 80],
    'email' => ['Email', '', 190],
    'tax_registrations' => ['Tax registrations', 'As they must be printed, for example "NTN 3345678-9 · STRN 03-45-1234-567-89".', 300],
];
?>
<section class="flex flex-col gap-4 py-5" aria-labelledby="profile-title">
    <div class="page-header">
        <div><p class="eyebrow">Books and controls</p><h1 class="page-title" id="profile-title">Company profile</h1><p class="muted">The letterhead and footer of every printed document.</p></div>
        <a class="btn btn-secondary" href="<?= pl_e(pl_url('/accounting-policies')) ?>">Accounting policies</a>
    </div>
    <?php if ($form['message'] !== ''): ?>
        <div class="alert alert-danger" role="alert" tabindex="-1" data-form-error><h2 class="section-title mb-2">This profile change needs attention</h2><p><?= pl_e((string) $form['message']) ?></p><p>Your entered values are preserved. Review the current profile below before trying again.</p></div>
    <?php endif; ?>
    <div class="rounded-panel border border-border bg-surface p-4">
        <h2 class="section-title mb-2">What the profile is used for</h2>
        <p>Every field is optional and the profile starts empty. A printed invoice, counter receipt or statement shows only the lines that have been entered: nothing is invented, and an address or a tax registration never appears unless it was typed here.</p>
        <?php if ($profile['is_empty']): ?><p class="muted">This profile is empty, so printed documents currently show the business name, book and currency only.</p><?php endif; ?>
    </div>
    <?php if (!$canEditProfile): ?>
        <div class="rounded-panel border border-border bg-surface p-4"><p><?= pl_demo_enabled() ? 'Profile administration is disabled in the public sample.' : 'Your role can read the company profile. Only the business owner can change it.' ?></p></div>
    <?php else: ?>
    <div class="rounded-panel border border-border bg-surface p-4">
        <h2 class="section-title mb-2">Profile</h2>
        <form action="<?= pl_e(pl_url('/company-profile')) ?>" method="post" class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <?= pl_csrf_field() ?><?= pl_scope_fields($company) ?>
            <input type="hidden" name="revision" value="<?= (int) $profile['revision'] ?>">
            <input type="hidden" name="request_key" value="<?= pl_e((string) $requestKey) ?>">
            <?php foreach ($profileFields as $field => $meta): ?>
                <div class="field"><label for="profile-<?= pl_e($field) ?>"><?= pl_e($meta[0]) ?></label>
                    <input class="input" id="profile-<?= pl_e($field) ?>" name="<?= pl_e($field) ?>" maxlength="<?= (int) $meta[2] ?>" value="<?= pl_e(pl_web_text($profileInput, $field, (string) $profile[$field])) ?>">
                    <?php if ($meta[1] !== ''): ?><p class="muted"><?= pl_e($meta[1]) ?></p><?php endif; ?>
                </div>
            <?php endforeach; ?>
            <div class="field sm:col-span-2"><label for="profile-footer">Footer terms (optional)</label>
                <textarea class="input" id="profile-footer" name="footer_terms" rows="3" maxlength="2000"><?= pl_e(pl_web_text($profileInput, 'footer_terms', (string) $profile['footer_terms'])) ?></textarea>
                <p class="muted">Printed above the computer-generated note on every document.</p>
            </div>
            <div class="field sm:col-span-2"><label for="profile-reason">Reason for this change</label><input class="input" id="profile-reason" name="reason" maxlength="500" value="<?= pl_e(pl_web_text($profileInput, 'reason')) ?>" required></div>
            <div class="flex gap-2 sm:col-span-2"><button class="btn btn-primary" type="submit">Save profile</button></div>
        </form>
    </div>
    <?php endif; ?>
    <div class="rounded-panel border border-border bg-surface p-4">
        <h2 class="section-title mb-2">Change history</h2>
        <?php if ($history === []): ?><p class="muted">No policy or profile change has been recorded for this business.</p><?php else: ?>
        <div class="table-wrap" tabindex="0" role="region" aria-label="Company profile history"><table class="table">
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
