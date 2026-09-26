<?php
declare(strict_types=1);

/**
 * 1.4.6: the bundled sample catalogue (approved frame o1-start.html, owner correction of
 * 26 September 2026). Every copy shows the eleven companies on the Start stage and the Packages
 * Directory tab, whether or not it has ever contacted phpledger.com; an installation administrator
 * installs one from its card in a click; the snapshot is exactly what the authoring packs and
 * structures say, so it cannot drift from what phpledger.com publishes.
 */

require_once dirname(__DIR__) . '/www/phpledger/includes/functions/sample_package_functions.php';
require_once dirname(__DIR__) . '/www/phpledger/includes/functions/sample_structure_functions.php';
require_once dirname(__DIR__) . '/www/phpledger/includes/functions/demo_pack_functions.php';
require_once dirname(__DIR__) . '/www/phpledger/includes/functions/onboarding_web_functions.php';

test('the bundled catalogue is the eleven authored companies, exactly what the packs and structures say', function (): void {
    $catalogue = pl_sample_catalogue();
    $authored = json_decode((string) file_get_contents(PL_ROOT . '/resources/demo-packs/catalog.json'), true, 32, JSON_THROW_ON_ERROR);
    assert_same(array_column($authored, 'id'), array_keys($catalogue), 'The snapshot lists different companies, or in a different order, than the authoring catalogue.');
    foreach ($authored as $entry) {
        $snapshot = $catalogue[$entry['id']];
        $pack = json_decode(str_replace("\r\n", "\n", (string) file_get_contents(PL_ROOT . '/resources/demo-packs/' . $entry['file'])), true, 64, JSON_THROW_ON_ERROR);
        $structure = json_decode(str_replace("\r\n", "\n", (string) file_get_contents(PL_ROOT . '/resources/sample-structures/' . $entry['id'] . '-' . $entry['version'] . '.json')), true, 64, JSON_THROW_ON_ERROR);
        assert_same($entry['version'], $snapshot['version']);
        assert_same($entry['name'], $snapshot['name']);
        assert_same($entry['business'], $snapshot['business']);
        assert_same('sample-' . $entry['id'], $snapshot['slug']);
        assert_same((string) ($pack['learning_story']['origin'] ?? ''), $snapshot['story'], $entry['id'] . ': the story differs from the pack.');
        assert_same((string) ($pack['learning_story']['logo']['path'] ?? ''), $snapshot['logo']);
        assert_same(count($structure['accounts']), $snapshot['accounts']);
        assert_same(count($structure['parties']), $snapshot['parties']);
        assert_same(array_values($structure['modules']), $snapshot['modules']);
        assert_true($snapshot['story'] !== '' && $snapshot['logo'] !== '', $entry['id'] . ': a card needs a story and a logo.');
        assert_true(is_file(PL_ROOT . '/www/phpledger/public' . $snapshot['logo']), $entry['id'] . ': the logo file is not shipped.');
    }
    // A shipped file that is not in the package manifest is not in the release (repository rule).
    $manifest = (string) file_get_contents(PL_ROOT . '/tools/package-files.json');
    assert_true(str_contains($manifest, '"resources/sample-catalogue.json"'), 'The snapshot is not in tools/package-files.json, so it would not ship.');
});

test('a fresh production copy shows every company: the eleven on the Start stage, installable by an administrator, and the Directory tab lists them', function (): void {
    $previous = (string) getenv('PL_ENV');
    $previousRoot = getenv('PL_SAMPLE_PACKAGE_DIRECTORY');
    $root = sys_get_temp_dir() . '/sample-catalogue-test-' . bin2hex(random_bytes(8));
    mkdir($root, 0700);
    try {
        putenv('PL_SAMPLE_PACKAGE_DIRECTORY=' . $root . '/samples');
        putenv('PL_ENV=production');
        assert_same([], pl_demo_pack_catalog(), 'A production copy without installed packages has no sample packs.');
        $visitor = pl_onboarding_gallery(false);
        assert_same(11, count($visitor), 'The Start stage does not show the eleven companies.');
        assert_same(array_keys(pl_sample_catalogue()), array_column($visitor, 'id'), 'The cards are not in the catalogue\'s order.');
        assert_same([], array_filter($visitor, static fn (array $card): bool => $card['installed'] || !empty($card['installable'])), 'Nothing is installed, and a visitor cannot install.');
        assert_same([], array_filter($visitor, static fn (array $card): bool => $card['story'] === '' || $card['logo'] === '' || $card['accounts'] < 1 || $card['parties'] < 1 || $card['name'] === '' || $card['business'] === ''),
            'Every card carries its story, logo, kind and counts before anything is installed.');
        $admin = pl_onboarding_gallery(true);
        assert_same(extension_loaded('curl') && !pl_sample_packages_readonly(), (bool) ($admin[0]['installable'] ?? false), 'An administrator installs from the card whenever the copy can download.');
        assert_same('sample-' . $admin[0]['id'], $admin[0]['slug']);
        $offer = pl_sample_directory_offer();
        assert_same(11, count($offer));
        assert_true(isset($offer['sample-retail-shop']) && $offer['sample-retail-shop']['bundled'] === true, 'The offer does not come from the bundled snapshot.');
        assert_same('https://phpledger.com/directory/sample-retail-shop/1.1.0/sample-envelope.json', $offer['sample-retail-shop']['metadata_url']);
        assert_same('https://phpledger.com/directory/sample-retail-shop/', $offer['sample-retail-shop']['homepage']);
    } finally {
        putenv('PL_ENV=' . $previous);
        putenv($previousRoot === false ? 'PL_SAMPLE_PACKAGE_DIRECTORY' : 'PL_SAMPLE_PACKAGE_DIRECTORY=' . $previousRoot);
        if (is_dir($root . '/samples')) { @rmdir($root . '/samples'); }
        @rmdir($root);
    }
});

test('with every sample present the cards are all installed and selectable, and nothing offers an install', function (): void {
    $cards = pl_onboarding_gallery(true);
    assert_same(11, count($cards));
    assert_same([], array_filter($cards, static fn (array $card): bool => !$card['installed'] || !$card['skeleton'] || !empty($card['installable'])));
    assert_true(in_array('Willow Corner Shop', array_column($cards, 'name'), true));
    assert_true(!in_array('accounting-starter', array_column($cards, 'id'), true), 'The neutral starter is the blank start\'s chart, not a company card.');
});
