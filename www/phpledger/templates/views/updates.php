<?php declare(strict_types=1);
/** @var array $state */
$registration=$state['registration']??['name'=>'','email'=>'','site'=>'','company'=>''];
?>
<section class="flex flex-col gap-4 py-5">
<div class="page-header"><div><h1 class="page-title"><?= pl_e(pl_t('Updates and privacy')) ?></h1><p class="muted"><?= pl_e(pl_t('Check for releases and choose what this installation shares with the project.')) ?></p></div></div>
<p><?= pl_e(pl_t('Installed version')) ?>: <strong><?= pl_e(pl_app_version()) ?></strong> · <?= pl_e(pl_t('Installation mode')) ?>: <?= pl_e($mode) ?></p>
<?php if ($disabled): ?><p class="alert alert-info"><?= pl_e(pl_t('Project contact is disabled in the shared demo.')) ?></p><?php endif; ?>
<form method="post"><?= pl_csrf_field() ?><button class="btn btn-primary" name="action" value="check" <?= $disabled?'disabled':'' ?>><?= pl_e(pl_t('Check for updates')) ?></button></form>
<?php if (is_array($available)): ?><p><?= pl_e(pl_t('Available version')) ?>: <?= pl_e((string)$available['version']) ?>. <a href="https://phpledger.com/download/"><?= pl_e(pl_t('Download and upgrade instructions')) ?></a></p><?php endif; ?>
<p><a href="<?= pl_e(pl_url('/maintenance.php')) ?>"><?= pl_e(pl_t('Open installation maintenance')) ?></a></p>
<form method="post" class="flex flex-col gap-4 rounded-panel border border-border bg-surface p-4">
<?= pl_csrf_field() ?><fieldset class="flex flex-col gap-3" <?= $disabled?'disabled':'' ?>><legend class="section-title"><?= pl_e(pl_t('Project contact preferences')) ?></legend>
<label><input type="checkbox" name="enabled" value="1" <?= $state['enabled']?'checked':'' ?>> <?= pl_e(pl_t('Tell phpledger.com this copy was installed and when I check for updates.')) ?></label>
<p class="muted"><?= pl_e(pl_t('Sends a random installation ID, application version and channel, PHP version, database engine and version, operating-system family, installation mode, event and time. No database address, credentials, account records or financial data are sent.')) ?></p>
<label><input type="checkbox" name="register" value="1" <?= $state['registration']!==null?'checked':'' ?>> <?= pl_e(pl_t('Also register these details for release announcements and support.')) ?></label>
<?php foreach (['name'=>'Your name','email'=>'Email address','site'=>'Public site address','company'=>'Company name (optional)'] as $key=>$label): ?>
<label class="field"><?= pl_e(pl_t($label)) ?><input class="input" name="<?= $key==='company'?'registration_company':pl_e($key) ?>" type="<?= $key==='email'?'email':($key==='site'?'url':'text') ?>" value="<?= pl_e((string)$registration[$key]) ?>" maxlength="<?= match($key){'name'=>120,'email'=>254,'site'=>480,default=>160} ?>"></label>
<?php endforeach; ?>
<p class="muted"><?= pl_e(pl_t('Turning contact off stops future notices; it does not delete previous submissions. See the privacy page for retention and deletion requests.')) ?> <a href="https://phpledger.com/privacy/"><?= pl_e(pl_t('Privacy')) ?></a></p>
<button class="btn btn-primary" name="action" value="preferences"><?= pl_e(pl_t('Save preferences')) ?></button></fieldset></form>
<section><h2 class="section-title"><?= pl_e(pl_t('Last submission')) ?></h2><p><?= pl_e((string)$state['status']) ?> · <?= pl_e((string)($state['last_sent']??'Nothing sent')) ?></p>
<?php if (is_array($state['last_payload'])): ?><pre class="overflow-x-auto rounded-panel border border-border p-4"><?= pl_e(json_encode($state['last_payload'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)) ?></pre><?php endif; ?></section>
</section>
