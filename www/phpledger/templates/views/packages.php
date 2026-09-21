<?php
declare(strict_types=1);
/**
 * Admin > Packages (release plan 1.2 M8). One card layout for bundled modules and installed
 * packages (B52, onboarding decision 7), read-only without `installation.admin` (B44, onboarding
 * decision 10), and a full-page confirmation before unverified code is installed (decision 9).
 *
 * @var array $company @var array $user @var bool $administers @var array $cards @var array $staged
 * @var array|null $review @var array $acknowledgements @var bool $safe_mode @var array $history
 * @var array $form @var array $input
 */
$packagesInput = $form['input'];
$badge = static function (string $trust, string $status): void {
    if ($status === 'required') { pl_ui_badge('info', pl_t('Required')); }
    elseif ($status === 'active') { pl_ui_badge('posted', pl_t('Active')); }
    elseif ($status === 'failed') { pl_ui_badge('overdue', pl_t('Stopped')); }
    else { pl_ui_badge('unpaid', pl_t('Inactive')); }
    pl_ui_badge($trust === 'verified' ? 'info' : 'due-soon', $trust === 'verified' ? pl_t('Verified') : pl_t('Unverified'));
};
?>
<section class="flex flex-col gap-4 py-5" aria-labelledby="packages-title">
    <div class="page-header">
        <div>
            <p class="eyebrow"><?= pl_e(pl_t('Setup')) ?></p>
            <h1 class="page-title" id="packages-title"><?= pl_e(pl_t('Packages')) ?></h1>
            <p class="muted"><?= pl_e($administers
                ? pl_t('The modules bundled with this copy and the packages installed on it. A package runs with full access to this application, so only an installation administrator can install or activate one.')
                : pl_t('The modules bundled with this copy and the packages installed on it. Changing what code runs here is an installation administrator\'s decision, so this page is read-only for you.')) ?></p>
        </div>
        <div class="page-header-actions">
            <a class="btn btn-secondary" href="<?= pl_e(pl_url('/modules')) ?>"><?= pl_e(pl_t('Modules for this business')) ?></a>
        </div>
    </div>

    <?php if ($form['message'] !== ''): ?>
        <div class="alert alert-danger" role="alert" tabindex="-1" data-form-error><?= pl_e($form['message']) ?></div>
    <?php endif; ?>

    <?php if ($safe_mode): ?>
        <div class="alert alert-warning" role="status">
            <p><strong><?= pl_e(pl_t('Safe mode is on.')) ?></strong>
            <?= pl_e(pl_t('PL_PLUGINS_DISABLED is set in this installation\'s environment, so no package code is loading. Nothing recorded below has changed; remove that setting to load active packages again.')) ?></p>
        </div>
    <?php endif; ?>

<?php if ($review !== null): /* ----------------------------------- the full-page confirmation */ ?>
    <?php $manifest = $review['manifest']; ?>
    <section class="rounded-panel border border-border bg-surface p-4 text-sm" aria-labelledby="package-review-title">
        <div class="alert alert-warning" role="alert">
            <p class="alert-title"><?= pl_e(pl_t('This package has not been reviewed by the PHP Ledger project.')) ?></p>
            <p><?= pl_e(pl_t('Once you activate it, its code runs on every request with full access to this application and its database. It can change what a posting does, read every business on this installation, and break an upgrade. Read what it says about itself below and decide.')) ?></p>
        </div>
        <h2 class="section-title" id="package-review-title"><?= pl_e((string) $manifest['name']) ?> <?= pl_e((string) $manifest['version']) ?></h2>
        <p><?= pl_e((string) $manifest['description']) ?></p>
        <div class="table-wrap" tabindex="0" role="region" aria-label="<?= pl_e(pl_t('What this package declares')) ?>">
            <table class="table"><tbody>
                <tr><th scope="row"><?= pl_e(pl_t('Author')) ?></th><td><?= pl_e((string) $manifest['author']) ?><?php if ($manifest['author_url'] !== ''): ?> · <?= pl_e((string) $manifest['author_url']) ?><?php endif; ?></td></tr>
                <tr><th scope="row"><?= pl_e(pl_t('Licence')) ?></th><td><?= pl_e((string) $manifest['licence']) ?></td></tr>
                <tr><th scope="row"><?= pl_e(pl_t('Homepage')) ?></th><td><?= pl_e($manifest['homepage'] === '' ? pl_t('Not stated') : (string) $manifest['homepage']) ?></td></tr>
                <tr><th scope="row"><?= pl_e(pl_t('What it adds')) ?></th><td><?php foreach ((array) $manifest['adds'] as $adds): ?><span class="block"><?= pl_e((string) $adds) ?></span><?php endforeach; ?></td></tr>
                <tr><th scope="row"><?= pl_e(pl_t('Requirements')) ?></th><td>
                    <?php if ($review['requirements'] === []): ?><?= pl_e(pl_t('None beyond this release.')) ?><?php endif; ?>
                    <?php foreach ($review['requirements'] as $requirement): ?>
                        <span class="block"><?= pl_e($requirement['name'] . ' ' . $requirement['version']) ?> —
                        <?= pl_e($requirement['satisfied'] ? pl_t('satisfied') : pl_t('not satisfied here ({present})', ['present' => $requirement['present'] === '' ? pl_t('not installed') : $requirement['present']])) ?></span>
                    <?php endforeach; ?>
                </td></tr>
                <tr><th scope="row"><?= pl_e(pl_t('Extension points it uses')) ?></th><td><?= pl_e($manifest['hooks'] === [] ? pl_t('None declared.') : implode(', ', (array) $manifest['hooks'])) ?></td></tr>
                <tr><th scope="row"><?= pl_e(pl_t('Message channels it registers')) ?></th><td><?= pl_e($manifest['consumers'] === [] ? pl_t('None declared.') : implode(', ', (array) $manifest['consumers'])) ?></td></tr>
                <tr><th scope="row"><?= pl_e(pl_t('Tables it creates')) ?></th><td><?= pl_e($manifest['tables'] === [] ? pl_t('None declared.') : implode(', ', (array) $manifest['tables'])) ?></td></tr>
                <tr><th scope="row"><?= pl_e(pl_t('Permissions it adds')) ?></th><td><?= pl_e($manifest['grants'] === [] ? pl_t('None declared.') : implode(', ', array_keys((array) $manifest['grants']))) ?></td></tr>
                <tr><th scope="row"><?= pl_e(pl_t('Files')) ?></th><td><?= pl_e(pl_tn('{count} file', '{count} files', count((array) $manifest['files']), ['count' => count((array) $manifest['files'])])) ?></td></tr>
                <tr><th scope="row"><?= pl_e(pl_t('Digest recorded with your decision')) ?></th><td><code><?= pl_e($review['files_digest']) ?></code></td></tr>
            </tbody></table>
        </div>
        <form class="flex flex-col gap-3 border-t border-border pt-3" method="post" action="<?= pl_e(pl_url('/packages')) ?>">
            <?= pl_csrf_field() ?><?= pl_scope_fields($company) ?>
            <input type="hidden" name="action" value="confirm_upload">
            <input type="hidden" name="slug" value="<?= pl_e($review['slug']) ?>">
            <input type="hidden" name="request_key" value="<?= pl_e($packagesInput['request_key'] ?? bin2hex(random_bytes(20))) ?>">
            <?php foreach ($acknowledgements as $name => $statement): ?>
                <p><label><input type="checkbox" name="ack_<?= pl_e((string) $name) ?>" value="1" required> <?= pl_e((string) $statement) ?></label></p>
            <?php endforeach; ?>
            <label class="field"><?= pl_e(pl_t('Why are you installing this?')) ?>
                <input class="input" name="reason" required maxlength="500" value="<?= pl_e($packagesInput['reason'] ?? '') ?>"></label>
            <div class="flex flex-wrap gap-2">
                <button class="btn btn-primary"><?= pl_e(pl_t('Install as Unverified')) ?></button>
                <a class="btn btn-secondary" href="<?= pl_e(pl_url('/packages')) ?>"><?= pl_e(pl_t('Cancel')) ?></a>
            </div>
        </form>
    </section>
<?php else: /* ----------------------------------------------------------- the installed list */ ?>

    <?php if ($staged !== []): ?>
    <section class="rounded-panel border border-border bg-surface p-4 text-sm">
        <h2 class="section-title"><?= pl_e(pl_t('Waiting for your decision')) ?></h2>
        <p class="muted"><?= pl_e(pl_t('These folders are in the package directory and nothing has recorded them, so none of their code has run.')) ?></p>
        <?php foreach ($staged as $item): ?>
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border pt-3">
                <div><strong><?= pl_e($item['name']) ?></strong> <span class="muted"><?= pl_e($item['version']) ?></span>
                <?php if ($item['problem'] !== ''): ?><p class="alert alert-danger"><?= pl_e($item['problem']) ?></p><?php endif; ?></div>
                <div class="flex flex-wrap gap-2">
                    <?php if ($item['problem'] === ''): ?><a class="btn btn-primary" href="<?= pl_e(pl_url('/packages', ['confirm' => $item['slug']])) ?>"><?= pl_e(pl_t('Review and install')) ?></a><?php endif; ?>
                    <form method="post" action="<?= pl_e(pl_url('/packages')) ?>">
                        <?= pl_csrf_field() ?><?= pl_scope_fields($company) ?>
                        <input type="hidden" name="action" value="discard"><input type="hidden" name="slug" value="<?= pl_e($item['slug']) ?>">
                        <button class="btn btn-secondary"><?= pl_e(pl_t('Delete these files')) ?></button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <?php foreach ($cards as $card): $cardInput = ($packagesInput['slug'] ?? '') === $card['slug'] ? $packagesInput : []; ?>
    <section class="rounded-panel border border-border bg-surface p-4 text-sm" aria-labelledby="package-<?= pl_e($card['slug']) ?>">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h2 class="section-title" id="package-<?= pl_e($card['slug']) ?>"><?= pl_e($card['name']) ?></h2>
                <p class="muted"><?= pl_e(pl_t('Version {version}', ['version' => $card['version']])) ?><?php if ($card['author'] !== ''): ?> · <?= pl_e($card['author']) ?><?php endif; ?><?php if ($card['licence'] !== ''): ?> · <?= pl_e($card['licence']) ?><?php endif; ?></p>
            </div>
            <div class="flex flex-wrap gap-2"><?php $badge($card['trust'], $card['status']); ?></div>
        </div>
        <?php if ($card['description'] !== ''): ?><p><?= pl_e($card['description']) ?></p><?php endif; ?>
        <?php if ($card['history'] !== ''): ?><p class="muted"><?= pl_e($card['history']) ?></p><?php endif; ?>
        <?php if ($card['adds'] !== []): ?>
            <p class="muted"><?= pl_e(pl_t('What it adds')) ?></p>
            <ul><?php foreach ($card['adds'] as $adds): ?><li><?= pl_e((string) $adds) ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
        <?php if ($card['requires'] !== []): ?>
            <p class="muted"><?= pl_e(pl_t('Requires {list}', ['list' => implode(', ', array_map(static fn (string $name, string $version): string => $name . ' ' . $version, array_keys($card['requires']), array_values($card['requires'])))])) ?></p>
        <?php endif; ?>
        <?php if ($card['problem'] !== ''): ?><p class="alert alert-danger"><?= pl_e($card['problem']) ?></p><?php endif; ?>
        <?php if ($card['last_error'] !== ''): ?><p class="alert alert-warning"><?= pl_e($card['last_error']) ?></p><?php endif; ?>
        <?php if ($card['kind'] === 'module'): ?>
            <p class="muted"><?= pl_e($card['status'] === 'required'
                ? pl_t('Bundled with this copy and always available.')
                : pl_t('Bundled with this copy. Switch it on for a business in Modules.')) ?></p>
        <?php elseif (!$administers): ?>
            <p class="muted"><?= pl_e(pl_t('An installation administrator decides whether this package runs.')) ?></p>
        <?php else: ?>
        <form class="flex flex-wrap items-end gap-3 border-t border-border pt-3" method="post" action="<?= pl_e(pl_url('/packages')) ?>">
            <?= pl_csrf_field() ?><?= pl_scope_fields($company) ?>
            <input type="hidden" name="slug" value="<?= pl_e($card['slug']) ?>">
            <input type="hidden" name="request_key" value="<?= pl_e($cardInput['request_key'] ?? bin2hex(random_bytes(20))) ?>">
            <div class="field flex-1 min-w-48 max-w-sm">
                <label for="package-reason-<?= pl_e($card['slug']) ?>"><?= pl_e(pl_t('Reason for this change')) ?></label>
                <input class="input" id="package-reason-<?= pl_e($card['slug']) ?>" name="reason" maxlength="500" required value="<?= pl_e($cardInput['reason'] ?? '') ?>">
            </div>
            <div class="flex flex-wrap items-end gap-2">
                <?php if ($card['status'] !== 'active'): ?>
                    <button class="btn btn-primary" name="action" value="activate"><?= pl_e($card['status'] === 'failed' ? pl_t('Activate again') : pl_t('Activate')) ?></button>
                <?php else: ?>
                    <?php pl_ui_confirmation(pl_t('Deactivate this package'), pl_t('Its code stops loading on the next request. Its tables, settings and anything it has already posted are kept, and nothing in your books changes.'), static function (): void { ?>
                        <button class="btn btn-danger" name="action" value="deactivate"><?= pl_e(pl_t('Confirm deactivate')) ?></button>
                    <?php }); ?>
                <?php endif; ?>
                <?php if ($card['status'] !== 'active'): ?>
                    <?php pl_ui_confirmation(pl_t('Remove this package'), pl_t('Its record is removed. Its own tables and settings are kept unless you tick the box, and nothing it has already posted is ever removed.'), static function (): void { ?>
                        <p><label><input type="checkbox" name="delete_data" value="1"> <?= pl_e(pl_t('Also delete this package\'s own tables and settings')) ?></label></p>
                        <label class="field"><?= pl_e(pl_t('Type delete to confirm deleting its data')) ?><input class="input" name="confirm" maxlength="20"></label>
                        <button class="btn btn-danger" name="action" value="uninstall"><?= pl_e(pl_t('Confirm remove')) ?></button>
                    <?php }); ?>
                <?php endif; ?>
            </div>
        </form>
        <?php endif; ?>
    </section>
    <?php endforeach; ?>

    <?php if ($administers): ?>
    <section class="rounded-panel border border-border bg-surface p-4 text-sm" aria-labelledby="packages-upload-title">
        <h2 class="section-title" id="packages-upload-title"><?= pl_e(pl_t('Upload a package')) ?></h2>
        <p><?= pl_e(pl_t('A package you upload has not been reviewed by the project. Uploading unpacks it and shows you what it says about itself; nothing is installed and no code runs until you confirm on the next page.')) ?></p>
        <form class="flex flex-wrap items-end gap-3" method="post" action="<?= pl_e(pl_url('/packages')) ?>" enctype="multipart/form-data">
            <?= pl_csrf_field() ?><?= pl_scope_fields($company) ?>
            <input type="hidden" name="action" value="upload">
            <label class="field"><?= pl_e(pl_t('Package ZIP')) ?><input class="input" type="file" name="package" accept=".zip,application/zip" required></label>
            <button class="btn btn-secondary"><?= pl_e(pl_t('Unpack and review')) ?></button>
        </form>
    </section>

    <h2 class="section-title"><?= pl_e(pl_t('Recent package changes')) ?></h2>
    <div class="table-wrap" tabindex="0" role="region" aria-label="<?= pl_e(pl_t('Package changes; scroll horizontally on small screens')) ?>">
        <table class="table">
            <thead><tr><th><?= pl_e(pl_t('Package')) ?></th><th><?= pl_e(pl_t('Change')) ?></th><th><?= pl_e(pl_t('Trust')) ?></th><th><?= pl_e(pl_t('Reason')) ?></th><th><?= pl_e(pl_t('By')) ?></th><th><?= pl_e(pl_t('Recorded')) ?></th></tr></thead>
            <tbody>
            <?php foreach ($history as $event): ?>
                <tr><td><?= pl_e((string) $event['slug']) ?></td><td><?= pl_e(str_replace('_', ' ', (string) $event['action'])) ?></td>
                <td><?= pl_e((string) $event['trust']) ?></td><td><?= pl_e((string) $event['reason']) ?></td>
                <td><?= pl_e($event['display_name'] === null ? pl_t('This installation') : (string) $event['display_name']) ?></td>
                <td><time data-local-time datetime="<?= pl_e(str_replace(' ', 'T', (string) $event['recorded_at']) . 'Z') ?>"><?= pl_e((string) $event['recorded_at']) ?> UTC</time></td></tr>
            <?php endforeach; ?>
            <?php if ($history === []): ?><tr><td colspan="6"><?= pl_e(pl_t('No package has been installed on this copy yet.')) ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
<?php endif; ?>
</section>
