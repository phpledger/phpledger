<?php
declare(strict_types=1);
/**
 * Admin > Ownership register (1.2 M8a, issue #92). People, members, officers, share classes, the
 * share ledger and the related-party markers, all authorised inside pl_web_ownership().
 *
 * @var string $title @var array $user @var array $company @var string $asOf @var array $profile
 * @var array<int,array<string,mixed>> $people @var array<int,array<string,mixed>> $members
 * @var array<int,array<string,mixed>> $officers @var array<int,array<string,mixed>> $classes
 * @var array<int,array<string,mixed>> $events @var array<int,array<string,mixed>> $partners
 * @var array<int,int> $partnerLinks @var array{cash:array<int,array<string,mixed>>,equity:array<int,array<string,mixed>>} $accounts
 * @var array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int} $parties
 * @var bool $mayReadRelated @var bool $mayManage
 * @var array<int,array<string,mixed>> $markers @var array<int,array<string,mixed>> $candidates
 * @var array $form @var array $input
 */
$canManage = $mayManage && pl_can_write($company) && !pl_demo_enabled();
$hasFailure = pl_web_text($form, 'message') !== '';
$partyKinds = pl_ownership_party_kinds();
$officerRoles = pl_officer_roles();
$shareEventTypes = pl_share_event_types();
$sharePosting = pl_share_event_posting();
$relatedRegisters = pl_related_party_registers();
$relatedRelationships = pl_related_party_relationships();
$partnersById = [];
foreach ($partners as $partnerRow) { $partnersById[$partnerRow['id']] = $partnerRow; }
$selectedEventType = pl_web_text($input, 'event_type') ?: 'allotment';
?>
<div class="flex flex-col gap-4 py-5">
<?php pl_ui_page_header(pl_t('Ownership register'), pl_t('{company} · As of {asOf}', ['company' => $company['name'], 'asOf' => pl_date_label($asOf)]), static function () use ($asOf): void { ?>
<a class="btn btn-secondary" href="<?= pl_e(pl_url('/reports/ownership')) ?>"><?= pl_icon('report') ?> <?= pl_e(pl_t('Ownership reports')) ?></a>
<a class="btn btn-secondary" href="<?= pl_e(pl_url('/ownership/export', ['as_of' => $asOf])) ?>"><?= pl_icon('download') ?> <?= pl_e(pl_t('Export (Open Cap Format)')) ?></a>
<?php }, 'ownership-title'); ?>

<?php if ($profile['legal_form'] === ''): ?>
<?php pl_ui_strip(pl_t('The legal form has not been recorded yet. Recording it never selects an accounting framework — that stays a deliberate choice on the company profile.'), 'info', static function (): void { ?>
<a class="btn btn-secondary btn-sm" href="<?= pl_e(pl_url('/company-profile')) ?>"><?= pl_e(pl_t('Record it')) ?></a>
<?php }); ?>
<?php else: ?>
<?php pl_ui_strip(pl_t('{form} · Registration {number} at {authority} · Incorporated {date} · Financial year end {yearEnd}', [
    'form' => $profile['legal_form_label'],
    'number' => $profile['registration_number'] !== '' ? $profile['registration_number'] : pl_t('not recorded'),
    'authority' => $profile['registration_authority'] !== '' ? $profile['registration_authority'] : pl_t('not recorded'),
    'date' => $profile['incorporation_date'] !== null ? pl_date_label($profile['incorporation_date']) : pl_t('not recorded'),
    'yearEnd' => $profile['financial_year_end'] !== '' ? $profile['financial_year_end'] : pl_t('not recorded'),
]), 'info', static function (): void { ?>
<a class="btn btn-secondary btn-sm" href="<?= pl_e(pl_url('/company-profile')) ?>"><?= pl_e(pl_t('Edit')) ?></a>
<?php }); ?>
<?php endif; ?>

<?php if ($hasFailure): ?><div class="alert alert-danger" role="alert" tabindex="-1" data-form-error><strong><?= pl_e(pl_t('Nothing was saved.')) ?></strong><p><?= pl_e(pl_web_text($form, 'message')) ?></p><p><?= pl_e(pl_t('Your submitted values are retained.')) ?></p></div><?php endif; ?>

<!-- People in the register -->
<section class="panel p-4 flex flex-col gap-3" aria-labelledby="ownership-people"><h2 class="section-title" id="ownership-people"><?= pl_e(pl_t('People in the register')) ?></h2>
<div class="table-wrap" tabindex="0" role="region" aria-label="<?= pl_e(pl_t('People and entities in the ownership register')) ?>"><table class="table"><caption class="sr-only"><?= pl_e(pl_t('People and entities recorded in the ownership register')) ?></caption>
<thead><tr><th scope="col"><?= pl_e(pl_t('Name')) ?></th><th scope="col"><?= pl_e(pl_t('Kind')) ?></th><th scope="col"><?= pl_e(pl_t('Country')) ?></th><th scope="col"><?= pl_e(pl_t('Identifier')) ?></th><th scope="col"><?= pl_e(pl_t('Status')) ?></th></tr></thead><tbody>
<?php foreach ($people as $row): ?>
<tr><th scope="row"><?= pl_e($row['name']) ?></th><td><?= pl_e($partyKinds[$row['kind']] ?? $row['kind']) ?></td><td><?= pl_e($row['country_code'] !== '' ? $row['country_code'] : '—') ?></td><td><?= pl_e($row['identifier'] !== '' ? $row['identifier'] : '—') ?></td><td><?php pl_ui_badge($row['is_active'] ? 'active' : 'inactive', $row['is_active'] ? pl_t('Active') : pl_t('Inactive')); ?></td></tr>
<?php endforeach; ?>
<?php if ($people === []): ?><tr><td colspan="5"><?= pl_e(pl_t('Nobody is recorded in the ownership register yet.')) ?></td></tr><?php endif; ?>
</tbody></table></div>
<?php if ($canManage): ?>
<form method="post" action="<?= pl_e(pl_url('/ownership')) ?>" class="flex flex-col gap-3">
<?= pl_csrf_field() ?><?= pl_scope_fields($company) ?><input type="hidden" name="action" value="person">
<div class="grid grid-cols-2 gap-3">
<label class="field"><?= pl_e(pl_t('Kind')) ?><select class="select" name="kind" required><?php foreach ($partyKinds as $value => $label): ?><option value="<?= pl_e($value) ?>" <?= pl_web_text($input, 'kind') === $value ? 'selected' : '' ?>><?= pl_e($label) ?></option><?php endforeach; ?></select></label>
<label class="field"><?= pl_e(pl_t('Name')) ?><input class="input" name="name" maxlength="160" required value="<?= pl_e(pl_web_text($input, 'name')) ?>"></label>
<label class="field"><?= pl_e(pl_t('Country')) ?><input class="input" name="country_code" maxlength="2" placeholder="<?= pl_e(pl_t('Two-letter code')) ?>" value="<?= pl_e(pl_web_text($input, 'country_code')) ?>"></label>
<label class="field"><?= pl_e(pl_t('Identifier')) ?><input class="input" name="identifier" maxlength="80" value="<?= pl_e(pl_web_text($input, 'identifier')) ?>"><span class="field-hint"><?= pl_e(pl_t('A tax number, national identity number or company number.')) ?></span></label>
<label class="field"><?= pl_e(pl_t('Email')) ?><input class="input" type="email" name="email" maxlength="190" value="<?= pl_e(pl_web_text($input, 'email')) ?>"></label>
<label class="field"><?= pl_e(pl_t('Active')) ?><select class="select" name="is_active"><option value="1" <?= pl_web_text($input, 'is_active') !== '0' ? 'selected' : '' ?>><?= pl_e(pl_t('Active')) ?></option><option value="0" <?= pl_web_text($input, 'is_active') === '0' ? 'selected' : '' ?>><?= pl_e(pl_t('Inactive')) ?></option></select></label>
<label class="field col-span-2"><?= pl_e(pl_t('Address')) ?><input class="input" name="address" maxlength="400" value="<?= pl_e(pl_web_text($input, 'address')) ?>"></label>
<label class="field col-span-2"><?= pl_e(pl_t('Note')) ?><input class="input" name="note" maxlength="1000" value="<?= pl_e(pl_web_text($input, 'note')) ?>"></label>
<label class="field col-span-2"><?= pl_e(pl_t('Reason')) ?><input class="input" name="reason" maxlength="500" required value="<?= pl_e(pl_web_text($input, 'reason')) ?>"></label>
</div>
<div class="panel-actions"><button class="btn btn-primary" type="submit"><?= pl_e(pl_t('Add a person or entity')) ?></button></div>
</form>
<?php else: ?>
<?php pl_ui_strip(pl_demo_enabled() ? pl_t('People cannot be added to the ownership register in the public demo.') : pl_t('Your role can review the ownership register but not maintain it.'), 'info'); ?>
<?php endif; ?>
</section>

<!-- Capital accounts -->
<section class="panel p-4 flex flex-col gap-3" aria-labelledby="ownership-capital"><h2 class="section-title" id="ownership-capital"><?= pl_e(pl_t('Capital accounts')) ?></h2>
<p class="field-hint"><?= pl_e(pl_t('Links a person in the register to their capital, drawings and loan accounts in this book. The partner record and its accounts are unchanged by the link.')) ?></p>
<div class="table-wrap" tabindex="0" role="region" aria-label="<?= pl_e(pl_t('Capital account links')) ?>"><table class="table"><caption class="sr-only"><?= pl_e(pl_t('Which partner record each registered person is linked to')) ?></caption>
<thead><tr><th scope="col"><?= pl_e(pl_t('Person')) ?></th><th scope="col"><?= pl_e(pl_t('Linked partner')) ?></th><?php if ($canManage): ?><th scope="col"><?= pl_e(pl_t('Action')) ?></th><?php endif; ?></tr></thead><tbody>
<?php foreach ($people as $row): $linkedId = $partnerLinks[$row['id']] ?? null; $linked = $linkedId !== null ? ($partnersById[$linkedId] ?? null) : null; ?>
<tr><th scope="row"><?= pl_e($row['name']) ?></th><td><?= pl_e($linked !== null ? $linked['name'] : pl_t('Not linked')) ?></td>
<?php if ($canManage): ?><td><form method="post" action="<?= pl_e(pl_url('/ownership')) ?>" class="inline flex flex-wrap items-end gap-2">
<?= pl_csrf_field() ?><?= pl_scope_fields($company) ?><input type="hidden" name="action" value="partner_link"><input type="hidden" name="ownership_party_id" value="<?= (int) $row['id'] ?>">
<label class="field"><span class="sr-only"><?= pl_e(pl_t('Partner')) ?></span><select class="select" name="partner_id"><option value=""><?= pl_e(pl_t('Not linked')) ?></option><?php foreach ($partners as $partner): ?><option value="<?= (int) $partner['id'] ?>" <?= $linkedId === $partner['id'] ? 'selected' : '' ?>><?= pl_e($partner['name']) ?></option><?php endforeach; ?></select></label>
<label class="field"><span class="sr-only"><?= pl_e(pl_t('Reason')) ?></span><input class="input" name="reason" maxlength="500" required placeholder="<?= pl_e(pl_t('Reason')) ?>"></label>
<button class="btn btn-ghost btn-sm" type="submit"><?= pl_e(pl_t('Save link')) ?></button>
</form></td><?php endif; ?></tr>
<?php endforeach; ?>
<?php if ($people === []): ?><tr><td colspan="3"><?= pl_e(pl_t('There is nobody in the register to link yet.')) ?></td></tr><?php endif; ?>
</tbody></table></div>
</section>

<!-- Members register -->
<section class="panel p-4 flex flex-col gap-3" aria-labelledby="ownership-members"><h2 class="section-title" id="ownership-members"><?= pl_e(pl_t('Members register')) ?></h2>
<div class="table-wrap" tabindex="0" role="region" aria-label="<?= pl_e(pl_t('Members register')) ?>"><table class="table"><caption class="sr-only"><?= pl_e(pl_t('Membership interests, effective dated')) ?></caption>
<thead><tr><th scope="col"><?= pl_e(pl_t('#')) ?></th><th scope="col"><?= pl_e(pl_t('Name')) ?></th><th scope="col"><?= pl_e(pl_t('From')) ?></th><th scope="col"><?= pl_e(pl_t('To')) ?></th><th scope="col" class="num"><?= pl_e(pl_t('Profit share')) ?></th><th scope="col"><?= pl_e(pl_t('Note')) ?></th></tr></thead><tbody>
<?php foreach ($members as $row): ?>
<tr><td class="tabular-nums text-ink-muted"><?= (int) $row['id'] ?></td><th scope="row"><?= pl_e($row['name']) ?></th><td><?= pl_e(pl_date_label($row['effective_from'])) ?></td><td><?= pl_e($row['effective_to'] !== null ? pl_date_label($row['effective_to']) : pl_t('Open')) ?></td><td class="num"><?= pl_e($row['profit_share'] ?? '—') ?></td><td class="text-ink-muted"><?= pl_e($row['note']) ?></td></tr>
<?php endforeach; ?>
<?php if ($members === []): ?><tr><td colspan="6"><?= pl_e(pl_t('Nobody has a membership interest recorded yet.')) ?></td></tr><?php endif; ?>
</tbody></table></div>
<?php if ($canManage): ?>
<form method="post" action="<?= pl_e(pl_url('/ownership')) ?>" class="flex flex-col gap-3">
<?= pl_csrf_field() ?><?= pl_scope_fields($company) ?><input type="hidden" name="action" value="member">
<div class="grid grid-cols-2 gap-3">
<label class="field col-span-2"><?= pl_e(pl_t('Person or entity')) ?><select class="select" name="ownership_party_id" required><option value=""><?= pl_e(pl_t('Choose')) ?></option><?php foreach ($people as $row): ?><option value="<?= (int) $row['id'] ?>"><?= pl_e($row['name']) ?></option><?php endforeach; ?></select></label>
<label class="field"><?= pl_e(pl_t('Effective from')) ?><input class="input" type="date" name="effective_from" required value="<?= pl_e(pl_web_text($input, 'effective_from')) ?>"></label>
<label class="field"><?= pl_e(pl_t('Effective to')) ?><input class="input" type="date" name="effective_to" value="<?= pl_e(pl_web_text($input, 'effective_to')) ?>"><span class="field-hint"><?= pl_e(pl_t('Leave open unless this interest has ended.')) ?></span></label>
<label class="field"><?= pl_e(pl_t('Profit share')) ?><input class="input" name="profit_share" maxlength="12" placeholder="0.5" value="<?= pl_e(pl_web_text($input, 'profit_share')) ?>"><span class="field-hint"><?= pl_e(pl_t('Between 0 and 1. This is a record of what was agreed; the posting service still uses the partner record\'s own ratio.')) ?></span></label>
<label class="field"><?= pl_e(pl_t('Note')) ?><input class="input" name="note" maxlength="500" value="<?= pl_e(pl_web_text($input, 'note')) ?>"></label>
<label class="field col-span-2"><?= pl_e(pl_t('Reason')) ?><input class="input" name="reason" maxlength="500" required value="<?= pl_e(pl_web_text($input, 'reason')) ?>"></label>
</div>
<div class="panel-actions"><button class="btn btn-primary" type="submit"><?= pl_e(pl_t('Record a membership interest')) ?></button></div>
</form>
<?php else: ?>
<?php pl_ui_strip(pl_demo_enabled() ? pl_t('Membership interests cannot be recorded in the public demo.') : pl_t('Your role can review the members register but not maintain it.'), 'info'); ?>
<?php endif; ?>
</section>

<!-- Directors and officers -->
<section class="panel p-4 flex flex-col gap-3" aria-labelledby="ownership-officers"><h2 class="section-title" id="ownership-officers"><?= pl_e(pl_t('Directors and officers')) ?></h2>
<div class="table-wrap" tabindex="0" role="region" aria-label="<?= pl_e(pl_t('Directors and officers')) ?>"><table class="table"><caption class="sr-only"><?= pl_e(pl_t('Appointments, resignations and significant control')) ?></caption>
<thead><tr><th scope="col"><?= pl_e(pl_t('#')) ?></th><th scope="col"><?= pl_e(pl_t('Name')) ?></th><th scope="col"><?= pl_e(pl_t('Office')) ?></th><th scope="col"><?= pl_e(pl_t('Appointed')) ?></th><th scope="col"><?= pl_e(pl_t('Resigned')) ?></th><th scope="col"><?= pl_e(pl_t('Significant control')) ?></th></tr></thead><tbody>
<?php foreach ($officers as $row): ?>
<tr><td class="tabular-nums text-ink-muted"><?= (int) $row['id'] ?></td><th scope="row"><?= pl_e($row['name']) ?><?php if ($row['role_title'] !== '') : ?><span class="row-sub"><?= pl_e($row['role_title']) ?></span><?php endif; ?></th><td><?= pl_e($row['role_label']) ?></td><td><?= pl_e(pl_date_label($row['appointed_on'])) ?></td><td><?= pl_e($row['resigned_on'] !== null ? pl_date_label($row['resigned_on']) : '—') ?></td><td><?= pl_e($row['has_significant_control'] ? ($row['control_nature'] !== '' ? $row['control_nature'] : pl_t('Yes')) : pl_t('No')) ?></td></tr>
<?php endforeach; ?>
<?php if ($officers === []): ?><tr><td colspan="6"><?= pl_e(pl_t('Nobody has an appointment recorded yet.')) ?></td></tr><?php endif; ?>
</tbody></table></div>
<?php if ($canManage): ?>
<form method="post" action="<?= pl_e(pl_url('/ownership')) ?>" class="flex flex-col gap-3">
<?= pl_csrf_field() ?><?= pl_scope_fields($company) ?><input type="hidden" name="action" value="officer">
<div class="grid grid-cols-2 gap-3">
<label class="field"><?= pl_e(pl_t('Person')) ?><select class="select" name="ownership_party_id" required><option value=""><?= pl_e(pl_t('Choose')) ?></option><?php foreach ($people as $row): ?><option value="<?= (int) $row['id'] ?>"><?= pl_e($row['name']) ?></option><?php endforeach; ?></select></label>
<label class="field"><?= pl_e(pl_t('Office')) ?><select class="select" name="officer_role" required><?php foreach ($officerRoles as $value => $label): ?><option value="<?= pl_e($value) ?>" <?= pl_web_text($input, 'officer_role') === $value ? 'selected' : '' ?>><?= pl_e($label) ?></option><?php endforeach; ?></select></label>
<label class="field"><?= pl_e(pl_t('Role title')) ?><input class="input" name="role_title" maxlength="120" value="<?= pl_e(pl_web_text($input, 'role_title')) ?>"></label>
<label class="field"><?= pl_e(pl_t('Appointed on')) ?><input class="input" type="date" name="appointed_on" required value="<?= pl_e(pl_web_text($input, 'appointed_on')) ?>"></label>
<label class="field"><?= pl_e(pl_t('Resigned on')) ?><input class="input" type="date" name="resigned_on" value="<?= pl_e(pl_web_text($input, 'resigned_on')) ?>"></label>
<label class="field"><?= pl_e(pl_t('Significant control')) ?><select class="select" name="has_significant_control" required><option value="0" <?= pl_web_text($input, 'has_significant_control') !== '1' ? 'selected' : '' ?>><?= pl_e(pl_t('No')) ?></option><option value="1" <?= pl_web_text($input, 'has_significant_control') === '1' ? 'selected' : '' ?>><?= pl_e(pl_t('Yes, they hold significant control')) ?></option></select><span class="field-hint"><?= pl_e(pl_t('Choosing yes requires the nature of control below — a shareholding, voting rights, or the right to appoint the board.')) ?></span></label>
<label class="field"><?= pl_e(pl_t('Nature of control')) ?><input class="input" name="control_nature" maxlength="300" value="<?= pl_e(pl_web_text($input, 'control_nature')) ?>"><span class="field-hint"><?= pl_e(pl_t('Required when significant control is yes. This is not the related-party marker: recording a directorship never makes anybody a related party on its own.')) ?></span></label>
<label class="field"><?= pl_e(pl_t('Note')) ?><input class="input" name="note" maxlength="500" value="<?= pl_e(pl_web_text($input, 'note')) ?>"></label>
<label class="field col-span-2"><?= pl_e(pl_t('Reason')) ?><input class="input" name="reason" maxlength="500" required value="<?= pl_e(pl_web_text($input, 'reason')) ?>"></label>
</div>
<div class="panel-actions"><button class="btn btn-primary" type="submit"><?= pl_e(pl_t('Record an appointment')) ?></button></div>
</form>
<?php else: ?>
<?php pl_ui_strip(pl_demo_enabled() ? pl_t('Appointments cannot be recorded in the public demo.') : pl_t('Your role can review the officers register but not maintain it.'), 'info'); ?>
<?php endif; ?>
</section>

<!-- Share classes -->
<section class="panel p-4 flex flex-col gap-3" aria-labelledby="ownership-classes"><h2 class="section-title" id="ownership-classes"><?= pl_e(pl_t('Share classes')) ?></h2>
<div class="table-wrap" tabindex="0" role="region" aria-label="<?= pl_e(pl_t('Share classes')) ?>"><table class="table"><caption class="sr-only"><?= pl_e(pl_t('Share classes and their issued shares')) ?></caption>
<thead><tr><th scope="col"><?= pl_e(pl_t('Code')) ?></th><th scope="col"><?= pl_e(pl_t('Name')) ?></th><th scope="col"><?= pl_e(pl_t('Type')) ?></th><th scope="col"><?= pl_e(pl_t('Currency')) ?></th><th scope="col" class="num"><?= pl_e(pl_t('Nominal value')) ?></th><th scope="col" class="num"><?= pl_e(pl_t('Votes/share')) ?></th><th scope="col" class="num"><?= pl_e(pl_t('Authorised')) ?></th><th scope="col" class="num"><?= pl_e(pl_t('Issued')) ?></th><th scope="col"><?= pl_e(pl_t('Status')) ?></th></tr></thead><tbody>
<?php foreach ($classes as $row): ?>
<tr><th scope="row"><?= pl_e($row['code']) ?></th><td><?= pl_e($row['name']) ?><?php if ($row['is_option_pool']): ?><span class="row-sub"><?= pl_e(pl_t('Option pool')) ?></span><?php endif; ?></td><td><?= pl_e($row['class_type'] === 'preferred' ? pl_t('Preference') : pl_t('Ordinary')) ?></td><td><?= pl_e($row['currency']) ?></td><td class="num"><?= pl_e(pl_money($row['nominal_value'])) ?></td><td class="num"><?= pl_e($row['votes_per_share']) ?></td><td class="num"><?= pl_e($row['authorised_shares'] !== null ? $row['authorised_shares'] : pl_t('Unlimited')) ?></td><td class="num"><?= pl_e($row['issued_shares']) ?></td><td><?php pl_ui_badge($row['is_active'] ? 'active' : 'inactive', $row['is_active'] ? pl_t('Active') : pl_t('Closed')); ?></td></tr>
<?php endforeach; ?>
<?php if ($classes === []): ?><tr><td colspan="9"><?= pl_e(pl_t('No share class has been recorded yet.')) ?></td></tr><?php endif; ?>
</tbody></table></div>
<p class="field-hint"><?= pl_e(pl_t('The issued count is read from the share ledger below and is never stored on the class.')) ?></p>
<?php if ($canManage): ?>
<form method="post" action="<?= pl_e(pl_url('/ownership')) ?>" class="flex flex-col gap-3">
<?= pl_csrf_field() ?><?= pl_scope_fields($company) ?><input type="hidden" name="action" value="share_class">
<div class="grid grid-cols-2 gap-3">
<label class="field"><?= pl_e(pl_t('Class code')) ?><input class="input" name="code" maxlength="20" required placeholder="<?= pl_e(pl_t('ORD')) ?>" value="<?= pl_e(pl_web_text($input, 'code')) ?>"></label>
<label class="field"><?= pl_e(pl_t('Class name')) ?><input class="input" name="name" maxlength="120" required value="<?= pl_e(pl_web_text($input, 'name')) ?>"></label>
<label class="field"><?= pl_e(pl_t('Type')) ?><select class="select" name="class_type" required><option value="common" <?= pl_web_text($input, 'class_type') !== 'preferred' ? 'selected' : '' ?>><?= pl_e(pl_t('Ordinary')) ?></option><option value="preferred" <?= pl_web_text($input, 'class_type') === 'preferred' ? 'selected' : '' ?>><?= pl_e(pl_t('Preference')) ?></option></select></label>
<label class="field"><?= pl_e(pl_t('Currency')) ?><input class="input" name="currency" maxlength="3" required value="<?= pl_e(pl_web_text($input, 'currency') ?: $company['currency']) ?>"></label>
<label class="field"><?= pl_e(pl_t('Nominal value')) ?><input class="input" name="nominal_value" inputmode="decimal" required value="<?= pl_e(pl_web_text($input, 'nominal_value')) ?>"></label>
<label class="field"><?= pl_e(pl_t('Votes per share')) ?><input class="input" name="votes_per_share" maxlength="14" value="<?= pl_e(pl_web_text($input, 'votes_per_share') ?: '1') ?>"></label>
<label class="field"><?= pl_e(pl_t('Authorised shares')) ?><input class="input" name="authorised_shares" maxlength="32" value="<?= pl_e(pl_web_text($input, 'authorised_shares')) ?>"><span class="field-hint"><?= pl_e(pl_t('Leave empty where the jurisdiction has no authorised capital. Empty means unlimited, never zero.')) ?></span></label>
<label class="field"><?= pl_e(pl_t('Option pool')) ?><select class="select" name="is_option_pool"><option value="0" <?= pl_web_text($input, 'is_option_pool') !== '1' ? 'selected' : '' ?>><?= pl_e(pl_t('No')) ?></option><option value="1" <?= pl_web_text($input, 'is_option_pool') === '1' ? 'selected' : '' ?>><?= pl_e(pl_t('Yes')) ?></option></select></label>
<label class="field"><?= pl_e(pl_t('Active')) ?><select class="select" name="is_active"><option value="1" <?= pl_web_text($input, 'is_active') !== '0' ? 'selected' : '' ?>><?= pl_e(pl_t('Active')) ?></option><option value="0" <?= pl_web_text($input, 'is_active') === '0' ? 'selected' : '' ?>><?= pl_e(pl_t('Closed')) ?></option></select></label>
<label class="field col-span-2"><?= pl_e(pl_t('Dividend rights')) ?><input class="input" name="dividend_rights" maxlength="1000" value="<?= pl_e(pl_web_text($input, 'dividend_rights')) ?>"></label>
<label class="field col-span-2"><?= pl_e(pl_t('Liquidation rights')) ?><input class="input" name="liquidation_rights" maxlength="1000" value="<?= pl_e(pl_web_text($input, 'liquidation_rights')) ?>"></label>
<label class="field col-span-2"><?= pl_e(pl_t('Reason')) ?><input class="input" name="reason" maxlength="500" required value="<?= pl_e(pl_web_text($input, 'reason')) ?>"></label>
</div>
<div class="panel-actions"><button class="btn btn-primary" type="submit"><?= pl_e(pl_t('Add a share class')) ?></button></div>
</form>
<?php else: ?>
<?php pl_ui_strip(pl_demo_enabled() ? pl_t('Share classes cannot be added in the public demo.') : pl_t('Your role can review share classes but not maintain them.'), 'info'); ?>
<?php endif; ?>
</section>

<!-- Share ledger -->
<section class="panel p-4 flex flex-col gap-3" aria-labelledby="ownership-ledger"><h2 class="section-title" id="ownership-ledger"><?= pl_e(pl_t('Share ledger')) ?></h2>
<div class="table-wrap" tabindex="0" role="region" aria-label="<?= pl_e(pl_t('Share ledger')) ?>"><table class="table"><caption class="sr-only"><?= pl_e(pl_t('Every allotment, transfer, cancellation, bonus issue and re-designation')) ?></caption>
<thead><tr><th scope="col"><?= pl_e(pl_t('Date')) ?></th><th scope="col"><?= pl_e(pl_t('Type')) ?></th><th scope="col"><?= pl_e(pl_t('Class')) ?></th><th scope="col" class="num"><?= pl_e(pl_t('Quantity')) ?></th><th scope="col"><?= pl_e(pl_t('From')) ?></th><th scope="col"><?= pl_e(pl_t('To')) ?></th><th scope="col" class="num"><?= pl_e(pl_t('Consideration')) ?></th><th scope="col"><?= pl_e(pl_t('Status')) ?></th><th scope="col"></th></tr></thead><tbody>
<?php foreach ($events as $row): ?>
<tr><td><?= pl_e(pl_date_label($row['effective_date'])) ?></td><td><?= pl_e($row['type_label']) ?><?php if ($row['certificate_reference'] !== '') : ?><span class="row-sub"><?= pl_e($row['certificate_reference']) ?></span><?php endif; ?></td><td><?= pl_e($row['class_code']) ?><?php if ($row['to_class_code'] !== null): ?> → <?= pl_e($row['to_class_code']) ?><?php endif; ?></td><td class="num"><?= pl_e($row['quantity']) ?></td><td><?= pl_e($row['from_name'] ?? '—') ?></td><td><?= pl_e($row['to_name'] ?? '—') ?></td><td class="num"><?= pl_e($row['consideration_amount'] !== null ? pl_money($row['consideration_amount']) : '—') ?></td>
<td><?php pl_ui_badge($row['status'], match ($row['status']) { 'recorded' => pl_t('Recorded'), 'reversed' => pl_t('Reversed'), default => pl_t('Correction') }); ?></td>
<td class="whitespace-nowrap">
<?php if ($row['journal_id'] !== null): ?><a class="link" href="<?= pl_e(pl_url('/journals/detail', ['id' => $row['journal_id']])) ?>"><?= pl_e(pl_t('Journal')) ?></a><?php endif; ?>
<?php if ($canManage && $row['status'] === 'recorded'): ?>
<form method="post" action="<?= pl_e(pl_url('/ownership')) ?>" class="inline flex items-end gap-1">
<?= pl_csrf_field() ?><?= pl_scope_fields($company) ?><input type="hidden" name="action" value="share_event_reverse"><input type="hidden" name="event_id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="creation_key" value="<?= pl_e(bin2hex(random_bytes(16))) ?>">
<input class="input" name="reason" maxlength="500" required placeholder="<?= pl_e(pl_t('Reason')) ?>">
<button class="btn btn-ghost btn-sm" type="submit"><?= pl_e(pl_t('Reverse')) ?></button>
</form>
<?php endif; ?>
</td></tr>
<?php endforeach; ?>
<?php if ($events === []): ?><tr><td colspan="9"><?= pl_e(pl_t('Nothing has been recorded in the share ledger yet.')) ?></td></tr><?php endif; ?>
</tbody></table></div>
<?php if ($canManage): ?>
<form method="post" action="<?= pl_e(pl_url('/ownership')) ?>" class="flex flex-col gap-3">
<?= pl_csrf_field() ?><?= pl_scope_fields($company) ?><input type="hidden" name="action" value="share_event"><input type="hidden" name="creation_key" value="<?= pl_e(pl_web_text($input, 'creation_key')) ?>">
<div class="grid grid-cols-2 gap-3">
<label class="field"><?= pl_e(pl_t('What happened')) ?><select class="select" name="event_type" required><?php foreach ($shareEventTypes as $value => $label): ?><option value="<?= pl_e($value) ?>" <?= $selectedEventType === $value ? 'selected' : '' ?>><?= pl_e($label) ?></option><?php endforeach; ?></select><span class="field-hint"><?= pl_e(match ($sharePosting[$selectedEventType] ?? 'never') {
    'posts' => pl_t('May post a journal through the accounts below, or link one posted elsewhere.'),
    'links' => pl_t('This register does not choose the accounting for this event. Post the journal where it belongs, then link it here.'),
    default => pl_t('A transfer between two holders changes nothing in the company\'s own books, so it never carries a journal.'),
}) ?></span></label>
<label class="field"><?= pl_e(pl_t('Share class')) ?><select class="select" name="share_class_id" required><?php foreach ($classes as $row): ?><option value="<?= (int) $row['id'] ?>" <?= pl_web_text($input, 'share_class_id') === (string) $row['id'] ? 'selected' : '' ?>><?= pl_e($row['code'] . ' · ' . $row['name']) ?></option><?php endforeach; ?></select></label>
<label class="field"><?= pl_e(pl_t('Effective date')) ?><input class="input" type="date" name="effective_date" required value="<?= pl_e(pl_web_text($input, 'effective_date') ?: $asOf) ?>"></label>
<label class="field"><?= pl_e(pl_t('Number of shares')) ?><input class="input" name="quantity" maxlength="32" inputmode="decimal" required value="<?= pl_e(pl_web_text($input, 'quantity')) ?>"></label>
<label class="field"><?= pl_e(pl_t('From (holder giving up shares)')) ?><select class="select" name="from_party_id"><option value=""><?= pl_e(pl_t('Not applicable')) ?></option><?php foreach ($people as $row): ?><option value="<?= (int) $row['id'] ?>" <?= pl_web_text($input, 'from_party_id') === (string) $row['id'] ? 'selected' : '' ?>><?= pl_e($row['name']) ?></option><?php endforeach; ?></select></label>
<label class="field"><?= pl_e(pl_t('To (holder receiving shares)')) ?><select class="select" name="to_party_id"><option value=""><?= pl_e(pl_t('Not applicable')) ?></option><?php foreach ($people as $row): ?><option value="<?= (int) $row['id'] ?>" <?= pl_web_text($input, 'to_party_id') === (string) $row['id'] ? 'selected' : '' ?>><?= pl_e($row['name']) ?></option><?php endforeach; ?></select></label>
<label class="field"><?= pl_e(pl_t('Class the shares move to')) ?><select class="select" name="to_share_class_id"><option value=""><?= pl_e(pl_t('Not applicable')) ?></option><?php foreach ($classes as $row): ?><option value="<?= (int) $row['id'] ?>" <?= pl_web_text($input, 'to_share_class_id') === (string) $row['id'] ? 'selected' : '' ?>><?= pl_e($row['code']) ?></option><?php endforeach; ?></select><span class="field-hint"><?= pl_e(pl_t('Only used for a re-designation.')) ?></span></label>
<label class="field"><?= pl_e(pl_t('Consideration')) ?><input class="input" name="consideration_amount" inputmode="decimal" value="<?= pl_e(pl_web_text($input, 'consideration_amount')) ?>"><span class="field-hint"><?= pl_e(pl_t('An allotment only. Defaults to nominal value when left empty.')) ?></span></label>
<label class="field"><?= pl_e(pl_t('Certificate reference')) ?><input class="input" name="certificate_reference" maxlength="80" value="<?= pl_e(pl_web_text($input, 'certificate_reference')) ?>"></label>
<label class="field flex-row items-center gap-2"><input type="checkbox" name="post" value="1" <?= pl_web_text($input, 'post') === '1' ? 'checked' : '' ?>> <?= pl_e(pl_t('Post a journal for this event')) ?></label>
<label class="field"><?= pl_e(pl_t('Existing journal to link (optional)')) ?><input class="input" name="journal_id" inputmode="numeric" value="<?= pl_e(pl_web_text($input, 'journal_id')) ?>"><span class="field-hint"><?= pl_e(pl_t('A cancellation or re-designation can only link a journal that was posted elsewhere; this register never chooses that accounting.')) ?></span></label>
<label class="field"><?= pl_e(pl_t('Cash or bank account')) ?><select class="select" name="debit_account_id"><option value=""><?= pl_e(pl_t('Choose')) ?></option><?php foreach ($accounts['cash'] as $row): ?><option value="<?= (int) $row['id'] ?>" <?= pl_web_text($input, 'debit_account_id') === (string) $row['id'] ? 'selected' : '' ?>><?= pl_e($row['code'] . ' · ' . $row['name']) ?></option><?php endforeach; ?></select></label>
<label class="field"><?= pl_e(pl_t('Share capital account')) ?><select class="select" name="share_capital_account_id"><option value=""><?= pl_e(pl_t('Choose')) ?></option><?php foreach ($accounts['equity'] as $row): ?><option value="<?= (int) $row['id'] ?>" <?= pl_web_text($input, 'share_capital_account_id') === (string) $row['id'] ? 'selected' : '' ?>><?= pl_e($row['code'] . ' · ' . $row['name']) ?></option><?php endforeach; ?></select></label>
<label class="field"><?= pl_e(pl_t('Share premium account')) ?><select class="select" name="share_premium_account_id"><option value=""><?= pl_e(pl_t('Choose')) ?></option><?php foreach ($accounts['equity'] as $row): ?><option value="<?= (int) $row['id'] ?>" <?= pl_web_text($input, 'share_premium_account_id') === (string) $row['id'] ? 'selected' : '' ?>><?= pl_e($row['code'] . ' · ' . $row['name']) ?></option><?php endforeach; ?></select><span class="field-hint"><?= pl_e(pl_t('An allotment above nominal value only. Never the same account as share capital.')) ?></span></label>
<label class="field"><?= pl_e(pl_t('Reserve being capitalised')) ?><select class="select" name="source_account_id"><option value=""><?= pl_e(pl_t('Choose')) ?></option><?php foreach ($accounts['equity'] as $row): ?><option value="<?= (int) $row['id'] ?>" <?= pl_web_text($input, 'source_account_id') === (string) $row['id'] ? 'selected' : '' ?>><?= pl_e($row['code'] . ' · ' . $row['name']) ?></option><?php endforeach; ?></select><span class="field-hint"><?= pl_e(pl_t('A bonus issue only. The operator chooses the reserve; it is never guessed.')) ?></span></label>
<label class="field col-span-2"><?= pl_e(pl_t('Reason')) ?><input class="input" name="reason" maxlength="500" value="<?= pl_e(pl_web_text($input, 'reason')) ?>"></label>
</div>
<div class="panel-actions"><button class="btn btn-primary" type="submit"><?= pl_e(pl_t('Record the share ledger event')) ?></button></div>
<p class="field-hint"><?= pl_e(pl_t('The share ledger is append-only. A correction is a linked reversal, never an edit.')) ?></p>
</form>
<?php else: ?>
<?php pl_ui_strip(pl_demo_enabled() ? pl_t('The share ledger cannot be changed in the public demo.') : pl_t('Your role can review the share ledger but not maintain it.'), 'info'); ?>
<?php endif; ?>
</section>

<!-- Related parties -->
<?php if (!$mayReadRelated): ?>
<?php pl_ui_strip(pl_t('Related-party information is restricted. Your role cannot read it.'), 'info'); ?>
<?php else: ?>
<section class="panel p-4 flex flex-col gap-3" aria-labelledby="ownership-related"><h2 class="section-title" id="ownership-related"><?= pl_e(pl_t('Related parties')) ?></h2>
<div class="table-wrap" tabindex="0" role="region" aria-label="<?= pl_e(pl_t('Related-party markers')) ?>"><table class="table"><caption class="sr-only"><?= pl_e(pl_t('Trade parties recorded as related, and to whom')) ?></caption>
<thead><tr><th scope="col"><?= pl_e(pl_t('Trade party')) ?></th><th scope="col"><?= pl_e(pl_t('Relationship')) ?></th><th scope="col"><?= pl_e(pl_t('Related to')) ?></th><th scope="col"><?= pl_e(pl_t('From')) ?></th><th scope="col"><?= pl_e(pl_t('To')) ?></th><th scope="col"><?= pl_e(pl_t('Note')) ?></th></tr></thead><tbody>
<?php foreach ($markers as $row): ?>
<tr><th scope="row"><?= pl_e($row['legal_name']) ?></th><td><?= pl_e($row['relationship_label']) ?></td><td><?= pl_e($row['subject_name']) ?><?php if ($row['subject_detail'] !== '') : ?><span class="row-sub"><?= pl_e($row['subject_detail']) ?></span><?php endif; ?><span class="row-sub"><?= pl_e($row['register_label']) ?></span></td><td><?= pl_e(pl_date_label($row['effective_from'])) ?></td><td><?= pl_e($row['effective_to'] !== null ? pl_date_label($row['effective_to']) : pl_t('Open')) ?></td><td class="text-ink-muted"><?= pl_e($row['note']) ?></td></tr>
<?php endforeach; ?>
<?php if ($markers === []): ?><tr><td colspan="6"><?= pl_e(pl_t('Nobody is marked as a related party. Every person defaults to not related until somebody says so.')) ?></td></tr><?php endif; ?>
</tbody></table></div>

<?php if ($candidates !== []): ?>
<h3 class="section-title"><?= pl_e(pl_t('Possible markers to review')) ?></h3>
<p class="field-hint"><?= pl_e(pl_t('These trade parties match a name or identifier in the officers or members register. This is a flag to review, not a marker: nobody is related until somebody says so.')) ?></p>
<div class="table-wrap" tabindex="0" role="region" aria-label="<?= pl_e(pl_t('Related-party candidates')) ?>"><table class="table"><caption class="sr-only"><?= pl_e(pl_t('Trade parties that may need a related-party marker')) ?></caption>
<thead><tr><th scope="col"><?= pl_e(pl_t('Trade party')) ?></th><th scope="col"><?= pl_e(pl_t('Matches')) ?></th><th scope="col"><?= pl_e(pl_t('Matched on')) ?></th><?php if ($canManage): ?><th scope="col"><?= pl_e(pl_t('Action')) ?></th><?php endif; ?></tr></thead><tbody>
<?php foreach ($candidates as $row): ?>
<tr><th scope="row"><?= pl_e($row['legal_name']) ?><?php if ($row['is_customer']): ?><span class="row-sub"><?= pl_e(pl_t('Customer')) ?></span><?php endif; ?><?php if ($row['is_vendor']): ?><span class="row-sub"><?= pl_e(pl_t('Vendor')) ?></span><?php endif; ?></th><td><?= pl_e($row['subject_name']) ?><span class="row-sub"><?= pl_e($row['subject_detail']) ?></span></td><td><?= pl_e($row['matched_on'] === 'identifier' ? pl_t('Identifier') : pl_t('Name')) ?></td>
<?php if ($canManage): ?><td><form method="post" action="<?= pl_e(pl_url('/ownership')) ?>" class="inline flex flex-wrap items-end gap-2">
<?= pl_csrf_field() ?><?= pl_scope_fields($company) ?><input type="hidden" name="action" value="marker">
<input type="hidden" name="party_id" value="<?= (int) $row['party_id'] ?>"><input type="hidden" name="related_register" value="<?= pl_e($row['related_register']) ?>"><input type="hidden" name="related_id" value="<?= (int) $row['related_id'] ?>">
<label class="field"><span class="sr-only"><?= pl_e(pl_t('Relationship')) ?></span><select class="select" name="relationship" required><option value=""><?= pl_e(pl_t('Choose')) ?></option><?php foreach ($relatedRelationships as $value => $label): ?><option value="<?= pl_e($value) ?>"><?= pl_e($label) ?></option><?php endforeach; ?></select></label>
<label class="field"><span class="sr-only"><?= pl_e(pl_t('Related from')) ?></span><input class="input" type="date" name="effective_from" required value="<?= pl_e($asOf) ?>"></label>
<label class="field"><span class="sr-only"><?= pl_e(pl_t('Reason')) ?></span><input class="input" name="reason" maxlength="500" required placeholder="<?= pl_e(pl_t('Reason')) ?>"></label>
<button class="btn btn-ghost btn-sm" type="submit"><?= pl_e(pl_t('Mark as related')) ?></button>
</form></td><?php endif; ?></tr>
<?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>

<?php if ($canManage): ?>
<form method="post" action="<?= pl_e(pl_url('/ownership')) ?>" class="flex flex-col gap-3">
<?= pl_csrf_field() ?><?= pl_scope_fields($company) ?><input type="hidden" name="action" value="marker">
<p class="field-hint"><?= pl_e(pl_t('An ordinary employee is not a related party. Only key management personnel, which includes any director, a close family member of one, or an entity either controls, is related.')) ?></p>
<div class="grid grid-cols-2 gap-3">
<label class="field col-span-2"><?= pl_e(pl_t('Trade party (customer or supplier)')) ?><select class="select" name="party_id" required><option value=""><?= pl_e(pl_t('Choose')) ?></option><?php foreach ($parties['rows'] as $row): ?><option value="<?= (int) $row['id'] ?>" <?= pl_web_text($input, 'party_id') === (string) $row['id'] ? 'selected' : '' ?>><?= pl_e($row['legal_name']) ?></option><?php endforeach; ?></select></label>
<label class="field"><?= pl_e(pl_t('Register')) ?><select class="select" name="related_register" required><?php foreach ($relatedRegisters as $value => $label): ?><option value="<?= pl_e($value) ?>" <?= pl_web_text($input, 'related_register') === $value ? 'selected' : '' ?>><?= pl_e($label) ?></option><?php endforeach; ?></select></label>
<label class="field"><?= pl_e(pl_t('Person\'s # in that register')) ?><input class="input" name="related_id" inputmode="numeric" required value="<?= pl_e(pl_web_text($input, 'related_id')) ?>"><span class="field-hint"><?= pl_e(pl_t('The # shown against them in the directors/officers or members table above.')) ?></span></label>
<label class="field"><?= pl_e(pl_t('Relationship')) ?><select class="select" name="relationship" required><option value=""><?= pl_e(pl_t('Choose')) ?></option><?php foreach ($relatedRelationships as $value => $label): ?><option value="<?= pl_e($value) ?>" <?= pl_web_text($input, 'relationship') === $value ? 'selected' : '' ?>><?= pl_e($label) ?></option><?php endforeach; ?></select></label>
<label class="field"><?= pl_e(pl_t('Related from')) ?><input class="input" type="date" name="effective_from" required value="<?= pl_e(pl_web_text($input, 'effective_from') ?: $asOf) ?>"></label>
<label class="field"><?= pl_e(pl_t('Related to')) ?><input class="input" type="date" name="effective_to" value="<?= pl_e(pl_web_text($input, 'effective_to')) ?>"><span class="field-hint"><?= pl_e(pl_t('Leave open unless the relationship has ended.')) ?></span></label>
<label class="field col-span-2"><?= pl_e(pl_t('Note')) ?><input class="input" name="note" maxlength="500" value="<?= pl_e(pl_web_text($input, 'note')) ?>"></label>
<label class="field col-span-2"><?= pl_e(pl_t('Reason')) ?><input class="input" name="reason" maxlength="500" required value="<?= pl_e(pl_web_text($input, 'reason')) ?>"></label>
</div>
<div class="panel-actions"><button class="btn btn-primary" type="submit"><?= pl_e(pl_t('Record a related-party marker')) ?></button></div>
</form>
<?php else: ?>
<?php pl_ui_strip(pl_demo_enabled() ? pl_t('A related-party marker cannot be recorded in the public demo.') : pl_t('Your role can read related-party information but not record a marker.'), 'info'); ?>
<?php endif; ?>
</section>
<?php endif; ?>
</div>
