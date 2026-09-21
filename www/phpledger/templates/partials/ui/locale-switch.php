<?php
declare(strict_types=1);
/*
 * The language switch (release plan 1.2, milestone M11).
 *
 * One partial, two places: the user menu in the workspace top bar, and the signed-out card that
 * carries sign-in, invitations and password resets. The signed-out one is not a nicety — a person
 * who cannot read the sign-in page has no way to reach the signed-in one.
 *
 * It POSTs with a CSRF token like every other state change here. There is no script: the page is
 * served under script-src 'self' with no inline JavaScript, so the choice is applied by a button
 * the person presses, not by a change handler.
 *
 * @var string $localeSwitchId     Unique element id suffix, because both copies can be on one page.
 * @var string $localeSwitchClass  Optional extra class for the placement.
 */
$localeSwitchId = $localeSwitchId ?? 'default';
$localeSwitchClass = $localeSwitchClass ?? '';
$localeSwitchCurrent = pl_locale();
$localeSwitchOffered = pl_i18n_offered_locales();
// The active locale is always one of the choices, even when it is not on the curated menu: a
// hosting default or a module-supplied catalogue is a real state and the control must show it
// rather than silently claim the interface is English.
$localeSwitchKnown = [];
foreach ($localeSwitchOffered as $localeSwitchTag => $localeSwitchEntry) {
    $localeSwitchKnown[strtolower((string) $localeSwitchTag)] = $localeSwitchEntry;
}
if (!isset($localeSwitchKnown[$localeSwitchCurrent])) {
    $localeSwitchOffered = [$localeSwitchCurrent => ['label' => $localeSwitchCurrent,
        'english' => $localeSwitchCurrent, 'review' => pl_locale_review_state($localeSwitchCurrent)]] + $localeSwitchOffered;
}
$localeSwitchReturn = pl_web_safe_return_path((string) ($_SERVER['REQUEST_URI'] ?? '/'));
?>
<form class="locale-switch <?= pl_e($localeSwitchClass) ?>" action="<?= pl_e(pl_url('/locale')) ?>" method="post">
    <?= pl_csrf_field() ?>
    <input type="hidden" name="return" value="<?= pl_e($localeSwitchReturn) ?>">
    <label class="locale-switch-label" for="locale-switch-<?= pl_e($localeSwitchId) ?>"><?= pl_e(pl_t('Language')) ?></label>
    <select class="input input-sm locale-switch-select" id="locale-switch-<?= pl_e($localeSwitchId) ?>" name="locale">
        <?php foreach ($localeSwitchOffered as $localeSwitchTag => $localeSwitchEntry): ?>
            <?php /* Each option states its own language and direction, so an Urdu name renders
                      right to left inside an English menu and an English one does not flip
                      inside an Urdu menu. */ ?>
            <option value="<?= pl_e($localeSwitchTag) ?>" lang="<?= pl_e($localeSwitchTag) ?>" dir="<?= pl_e(pl_text_direction($localeSwitchTag)) ?>"<?= strtolower((string) $localeSwitchTag) === $localeSwitchCurrent ? ' selected' : '' ?>><?= pl_e($localeSwitchEntry['review'] === 'draft'
                ? pl_t('{language} (draft translation)', ['language' => $localeSwitchEntry['label']])
                : $localeSwitchEntry['label']) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-secondary btn-sm"><?= pl_e(pl_t('Change language')) ?></button>
    <?php if (pl_locale_review_state() === 'draft'): ?>
        <p class="locale-switch-note"><?= pl_e(pl_t('This translation is an unreviewed draft. Anything not yet translated is shown in English.')) ?></p>
    <?php endif; ?>
</form>
