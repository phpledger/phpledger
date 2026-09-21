<?php
declare(strict_types=1);
/**
 * Admin > Roles (release plan 1.2 M7). The Awan prototype's "What each role can do" matrix,
 * built for real: the three protected system roles are read-only, and a company may define its
 * own roles from the same capability catalogue.
 *
 * @var array $company @var array $user @var array $roles @var array $capabilities
 * @var array|null $selected @var bool $isNew @var bool $canManage @var array $form @var array $input
 */
$rolesInput = $form['input'];
$chosen = [];
if ($rolesInput !== [] && isset($rolesInput['capabilities']) && is_array($rolesInput['capabilities'])) {
    foreach ($rolesInput['capabilities'] as $capability) {
        if (is_string($capability)) { $chosen[$capability] = true; }
    }
} elseif ($selected !== null) {
    foreach ($selected['capabilities'] as $capability) { $chosen[$capability] = true; }
} else {
    $chosen['company.read'] = true;
}
$companyScoped = array_values(array_filter($capabilities, static fn (array $row): bool => $row['scope'] === 'company'));
$installationScoped = array_values(array_filter($capabilities, static fn (array $row): bool => $row['scope'] === 'installation'));
?>
<section class="flex flex-col gap-4 py-5" aria-labelledby="roles-title">
    <div class="page-header">
        <div>
            <p class="eyebrow"><?= pl_e(pl_t('Setup')) ?></p>
            <h1 class="page-title" id="roles-title"><?= pl_e(pl_t('Roles')) ?></h1>
            <p class="muted"><?= pl_e(pl_t('A role is a named set of permissions, held per business. Owner, Accountant and Viewer ship with PHP Ledger and cannot be edited; add your own beside them.')) ?></p>
        </div>
        <div class="page-header-actions">
            <a class="btn btn-secondary" href="<?= pl_e(pl_url('/users')) ?>"><?= pl_e(pl_t('People')) ?></a>
            <?php if ($canManage): ?><a class="btn btn-primary" href="<?= pl_e(pl_url('/roles', ['new' => '1'])) ?>"><?= pl_e(pl_t('New role')) ?></a><?php endif; ?>
        </div>
    </div>

    <?php if ($form['message'] !== ''): ?>
        <div class="alert alert-danger" role="alert" tabindex="-1" data-form-error><p><?= pl_e((string) $form['message']) ?></p></div>
    <?php endif; ?>
    <?php if (!$canManage): ?>
        <div class="rounded-panel border border-border bg-surface p-4"><p><?= pl_e(pl_t('Your role can read the permission matrix. Changing a role needs the "Manage roles" permission.')) ?></p></div>
    <?php endif; ?>

    <div class="rounded-panel border border-border bg-surface p-4">
        <h2 class="section-title mb-2"><?= pl_e(pl_t('What each role can do')) ?></h2>
        <div class="table-wrap" tabindex="0" role="region" aria-label="<?= pl_e(pl_t('Permission matrix')) ?>">
            <table class="table table-dense">
                <thead><tr>
                    <th scope="col"><?= pl_e(pl_t('Permission')) ?></th>
                    <?php foreach ($roles as $role): ?>
                        <th scope="col" class="text-center"><?= pl_e((string) $role['name']) ?><?php if ($role['is_system']): ?><span class="row-sub"><?= pl_e(pl_t('Protected')) ?></span><?php endif; ?></th>
                    <?php endforeach; ?>
                </tr></thead>
                <tbody>
                <?php foreach ($companyScoped as $capability): ?>
                    <tr>
                        <td><?= pl_e((string) $capability['label']) ?><span class="row-sub"><?= pl_e((string) $capability['capability']) ?><?php if ((string) $capability['owner_type'] !== 'core'): ?> · <?= pl_e(pl_t('from {owner}', ['owner' => (string) $capability['owner_id']])) ?><?php endif; ?></span></td>
                        <?php foreach ($roles as $role): ?>
                            <td class="text-center"><?= in_array($capability['capability'], $role['capabilities'], true) ? pl_e(pl_t('Yes')) : '<span class="text-ink-faint">&mdash;</span>' ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="mt-2 text-xs text-ink-muted"><?= pl_e(pl_t('A permission a module owns applies only while that module is enabled here. Turning the module off leaves the grant in place and simply stops it applying; turning it back on restores exactly what was configured.')) ?></p>
    </div>

    <?php if ($installationScoped !== []): ?>
        <div class="rounded-panel border border-border bg-surface p-4">
            <h2 class="section-title mb-2"><?= pl_e(pl_t('Installation permissions')) ?></h2>
            <p class="muted"><?= pl_e(pl_t('These belong to the whole installation rather than to one business, so they are held per person rather than through a role. Grant them in Admin > Users.')) ?></p>
            <ul class="mt-2 text-sm text-ink-muted">
                <?php foreach ($installationScoped as $capability): ?>
                    <li><?= pl_e((string) $capability['label']) ?> · <?= pl_e((string) $capability['description']) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="rounded-panel border border-border bg-surface p-4">
        <h2 class="section-title mb-2"><?= pl_e(pl_t('Roles in this business')) ?></h2>
        <div class="table-wrap" tabindex="0" role="region" aria-label="<?= pl_e(pl_t('Roles')) ?>">
            <table class="table">
                <thead><tr>
                    <th scope="col"><?= pl_e(pl_t('Role')) ?></th>
                    <th scope="col"><?= pl_e(pl_t('Scope')) ?></th>
                    <th scope="col"><?= pl_e(pl_t('People')) ?></th>
                    <th scope="col"><?= pl_e(pl_t('Permissions')) ?></th>
                    <th scope="col"><?= pl_e(pl_t('Action')) ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($roles as $role): ?>
                    <tr>
                        <td><span class="row-title"><?= pl_e((string) $role['name']) ?></span><span class="row-sub"><?= pl_e((string) $role['description']) ?></span></td>
                        <td><?= pl_e($role['company_id'] === null ? pl_t('Ships with PHP Ledger') : pl_t('This business')) ?></td>
                        <td class="tabular-nums"><?= (int) $role['member_count'] ?></td>
                        <td class="tabular-nums"><?= count($role['capabilities']) ?></td>
                        <td class="text-end">
                            <?php if ($role['is_system']): ?>
                                <span class="muted"><?= pl_e(pl_t('Read-only')) ?></span>
                            <?php elseif ($canManage): ?>
                                <a class="btn btn-ghost btn-sm" href="<?= pl_e(pl_url('/roles', ['id' => $role['id']])) ?>"><?= pl_e(pl_t('Edit')) ?></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($canManage && ($isNew || ($selected !== null && !$selected['is_system']))): ?>
        <div class="rounded-panel border border-border bg-surface p-4">
            <h2 class="section-title mb-2"><?= pl_e($selected === null ? pl_t('New role') : pl_t('Edit {name}', ['name' => (string) $selected['name']])) ?></h2>
            <form class="grid grid-cols-1 gap-3" method="post" action="<?= pl_e(pl_url('/roles')) ?>">
                <?= pl_csrf_field() ?><?= pl_scope_fields($company) ?>
                <?php if ($selected !== null): ?>
                    <input type="hidden" name="role_id" value="<?= (int) $selected['id'] ?>">
                    <input type="hidden" name="revision" value="<?= (int) $selected['revision'] ?>">
                <?php endif; ?>
                <div class="field"><label for="role-name"><?= pl_e(pl_t('Role name')) ?></label><input class="input" id="role-name" name="name" maxlength="80" required value="<?= pl_e(pl_web_text($rolesInput, 'name', $selected === null ? '' : (string) $selected['name'])) ?>"></div>
                <div class="field"><label for="role-description"><?= pl_e(pl_t('What this role is for')) ?></label><input class="input" id="role-description" name="description" maxlength="500" value="<?= pl_e(pl_web_text($rolesInput, 'description', $selected === null ? '' : (string) $selected['description'])) ?>"></div>
                <fieldset class="field">
                    <legend><?= pl_e(pl_t('Permissions')) ?></legend>
                    <p class="field-hint"><?= pl_e(pl_t('Every role can read the business it belongs to, so that one stays selected.')) ?></p>
                    <?php foreach ($companyScoped as $capability): $name = (string) $capability['capability']; ?>
                        <p><label><input type="checkbox" name="capabilities[]" value="<?= pl_e($name) ?>"<?= isset($chosen[$name]) ? ' checked' : '' ?><?= $name === 'company.read' ? ' required' : '' ?>> <?= pl_e((string) $capability['label']) ?></label><span class="row-sub"><?= pl_e((string) $capability['description']) ?></span></p>
                    <?php endforeach; ?>
                </fieldset>
                <div class="field"><label for="role-reason"><?= pl_e(pl_t('Reason for this change')) ?></label><input class="input" id="role-reason" name="reason" maxlength="500" required value="<?= pl_e(pl_web_text($rolesInput, 'reason')) ?>"></div>
                <div><button class="btn btn-primary" type="submit"><?= pl_e(pl_t('Save role')) ?></button></div>
            </form>
        </div>
    <?php endif; ?>
</section>
