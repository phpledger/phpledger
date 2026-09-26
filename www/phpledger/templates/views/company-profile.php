<?php
declare(strict_types=1);
$profileInput = $form['input'];
$canEditProfile = ($company['role'] ?? '') === 'owner' && !pl_demo_enabled();
// The registration half of the profile (B63) in the words of the country of registration
// (owner review, 26 September 2026): the same catalogue and script as business setup. The
// country is not stored on its own; the legal-form key remembers it.
$legalForm = pl_web_text($profileInput, 'legal_form', (string) $profile['legal_form']);
$country = strtoupper(pl_web_text($profileInput, 'country_code', (string) $profile['country_code'] !== '' ? (string) $profile['country_code'] : (pl_legal_form_country($legalForm) ?? '')));
$countryProfile = pl_legal_form_country_profile($country);
$labels = $countryProfile['labels'];
$placeholders = $countryProfile['placeholders'];
$legalForms = pl_legal_forms_for($country);
if ($legalForm !== '' && !isset($legalForms[$legalForm])) {
    // A family key saved by an older release, or a form from another country, stays selectable.
    $legalForms = [$legalForm => pl_legal_form_label($legalForm)] + $legalForms;
}
// label, hint, maximum length, then optionally the country label key and the placeholder key.
$profileFields = [
    'legal_name' => [pl_t('Registered name'), pl_t('The name printed at the top of a document. Left empty, the business name is used.'), 200],
    'registration_number' => [pl_t((string) $labels['reg_number']), pl_t('As it appears on the registration certificate. Printed with the legal form.'), 80, 'reg_number', 'reg_number'],
    'registration_authority' => [pl_t((string) $labels['reg_authority']), pl_t('Printed as "Registered with ..." only when filled in.'), 160, 'reg_authority'],
    'address_line1' => [pl_t('Address line 1'), '', 200],
    'address_line2' => [pl_t('Address line 2'), '', 200],
    'address_line3' => [pl_t('Address line 3'), '', 200],
    'phone' => [pl_t('Phone'), '', 80],
    'email' => [pl_t('Email'), '', 190],
    'tax_registrations' => [pl_t('Tax registrations'), pl_t('As they must be printed, for example "NTN 3345678-9 · STRN 03-45-1234-567-89".'), 300],
];
?>
<section class="flex flex-col gap-4 py-5" aria-labelledby="profile-title">
    <div class="page-header">
        <div><p class="eyebrow"><?= pl_e(pl_t('Books and controls')) ?></p><h1 class="page-title" id="profile-title"><?= pl_e(pl_t('Company profile')) ?></h1><p class="muted"><?= pl_e(pl_t('The letterhead and footer of every printed document.')) ?></p></div>
        <a class="btn btn-secondary" href="<?= pl_e(pl_url('/accounting-policies')) ?>"><?= pl_e(pl_t('Accounting policies')) ?></a>
    </div>
    <?php if ($form['message'] !== ''): ?>
        <div class="alert alert-danger" role="alert" tabindex="-1" data-form-error><h2 class="section-title mb-2"><?= pl_e(pl_t('This profile change needs attention')) ?></h2><p><?= pl_e((string) $form['message']) ?></p><p><?= pl_e(pl_t('Your entered values are preserved. Review the current profile below before trying again.')) ?></p></div>
    <?php endif; ?>
    <div class="rounded-panel border border-border bg-surface p-4">
        <h2 class="section-title mb-2"><?= pl_e(pl_t('What the profile is used for')) ?></h2>
        <p><?= pl_e(pl_t('Every field is optional and the profile starts empty. A printed invoice, counter receipt or statement shows only the lines that have been entered: nothing is invented, and an address or a tax registration never appears unless it was typed here.')) ?></p>
        <?php if ($profile['is_empty']): ?><p class="muted"><?= pl_e(pl_t('This profile is empty, so printed documents currently show the business name, book and currency only.')) ?></p><?php endif; ?>
    </div>
    <?php if (!$canEditProfile): ?>
        <div class="rounded-panel border border-border bg-surface p-4"><p><?= pl_e(pl_demo_enabled() ? pl_t('Profile administration is disabled in the public sample.') : pl_t('Your role can read the company profile. Only the business owner can change it.')) ?></p></div>
    <?php else: ?>
    <div class="rounded-panel border border-border bg-surface p-4">
        <h2 class="section-title mb-2"><?= pl_e(pl_t('Profile')) ?></h2>
        <form action="<?= pl_e(pl_url('/company-profile')) ?>" method="post" class="grid grid-cols-1 sm:grid-cols-2 gap-3" data-legal-forms>
            <?= pl_csrf_field() ?><?= pl_scope_fields($company) ?>
            <input type="hidden" name="revision" value="<?= (int) $profile['revision'] ?>">
            <input type="hidden" name="request_key" value="<?= pl_e((string) $requestKey) ?>">
            <div class="field"><label for="country-code"><?= pl_e(pl_t('Country of registration')) ?></label>
                <select class="select" id="country-code" name="country_code"><option value=""><?= pl_e(pl_t('Not stated')) ?></option>
                <?php foreach (pl_country_options() as $code => $label): ?><option value="<?= pl_e($code) ?>"<?= $country === $code ? ' selected' : '' ?>><?= pl_e($label) ?></option><?php endforeach; ?>
                </select>
                <p class="muted"><?= pl_e(pl_t('Chooses the legal-form names and the registration labels below. It enables no tax rule.')) ?></p></div>
            <div class="field"><label for="legal-form"><?= pl_e(pl_t('Legal form')) ?> <span class="field-chip" data-country-registrar><?= pl_e(pl_legal_form_chip((string) $countryProfile['code'], (string) $countryProfile['name'])) ?></span></label>
                <select class="select" id="legal-form" name="legal_form"><option value=""><?= pl_e(pl_t('Not stated')) ?></option>
                <?php foreach ($legalForms as $key => $label): ?><option value="<?= pl_e($key) ?>"<?= $legalForm === $key ? ' selected' : '' ?>><?= pl_e($label) ?></option><?php endforeach; ?>
                </select>
                <p class="form-guide" data-form-guide><?= pl_e($legalForm === '' ? pl_t('Choose the form your registration papers use; the guide for it appears here.') : pl_legal_form_guide($legalForm)) ?></p></div>
            <?php foreach ($profileFields as $field => $meta): ?>
                <div class="field"><label for="profile-<?= pl_e($field) ?>"<?= isset($meta[3]) ? ' data-country-label="' . pl_e($meta[3]) . '"' : '' ?>><?= pl_e($meta[0]) ?></label>
                    <input class="input" id="profile-<?= pl_e($field) ?>" name="<?= pl_e($field) ?>" maxlength="<?= (int) $meta[2] ?>" value="<?= pl_e(pl_web_text($profileInput, $field, (string) $profile[$field])) ?>"<?= isset($meta[4]) ? ' data-country-placeholder="' . pl_e($meta[4]) . '" placeholder="' . pl_e((string) ($placeholders[$meta[4]] ?? '')) . '"' : '' ?><?= $field === 'registration_authority' ? ' data-country-authority placeholder="' . pl_e((string) preg_replace('/^the /', '', (string) $countryProfile['registrar'])) . '"' : '' ?>>
                    <?php if ($meta[1] !== ''): ?><p class="muted"><?= pl_e($meta[1]) ?></p><?php endif; ?>
                </div>
            <?php endforeach; ?>
            <div class="field sm:col-span-2"><label for="profile-footer"><?= pl_e(pl_t('Footer terms (optional)')) ?></label>
                <textarea class="input" id="profile-footer" name="footer_terms" rows="3" maxlength="2000"><?= pl_e(pl_web_text($profileInput, 'footer_terms', (string) $profile['footer_terms'])) ?></textarea>
                <p class="muted"><?= pl_e(pl_t('Printed above the computer-generated note on every document.')) ?></p>
            </div>
            <div class="field sm:col-span-2"><label for="profile-reason"><?= pl_e(pl_t('Reason for this change')) ?></label><input class="input" id="profile-reason" name="reason" maxlength="500" value="<?= pl_e(pl_web_text($profileInput, 'reason')) ?>" required></div>
            <div class="flex gap-2 sm:col-span-2"><button class="btn btn-primary" type="submit"><?= pl_e(pl_t('Save profile')) ?></button></div>
        </form>
        <script type="application/json" id="legal-forms-data"><?= json_encode(pl_legal_form_catalogue_for_script(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
    </div>
    <?php endif; ?>
    <div class="rounded-panel border border-border bg-surface p-4">
        <h2 class="section-title mb-2"><?= pl_e(pl_t('Change history')) ?></h2>
        <?php if ($history === []): ?><p class="muted"><?= pl_e(pl_t('No policy or profile change has been recorded for this business.')) ?></p><?php else: ?>
        <div class="table-wrap" tabindex="0" role="region" aria-label="<?= pl_e(pl_t('Company profile history')) ?>"><table class="table">
            <thead><tr><th scope="col"><?= pl_e(pl_t('Recorded')) ?></th><th scope="col"><?= pl_e(pl_t('What')) ?></th><th scope="col"><?= pl_e(pl_t('By')) ?></th><th scope="col"><?= pl_e(pl_t('Reason')) ?></th></tr></thead>
            <tbody>
            <?php foreach ($history as $entry): ?>
                <tr><th scope="row"><?= pl_e((string) $entry['recorded_at']) ?></th><td><?= pl_e($entry['scope'] === 'company_profile' ? pl_t('Company profile') : pl_t('Accounting policies')) ?></td><td><?= pl_e((string) $entry['display_name']) ?></td><td><?= pl_e((string) $entry['reason']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
</section>
