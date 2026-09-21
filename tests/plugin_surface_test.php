<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/www/phpledger/includes/functions/web_functions.php';

/**
 * The surface snapshot (owner decision B80).
 *
 * B78 turned the extension points into a commercial surface: somebody's paid connector breaks
 * when a signature moves. B80 then decided that the document promising that surface to third
 * parties lives outside this repository, under the owner's control — which leaves the promise
 * unenforced unless something in the tree holds the code to it. This is that something.
 *
 * It records the published API version, the contract number, the extension points core raises,
 * and the parameter and return SHAPE of every function in pl_plugin_api_functions(), and fails
 * when any of it moves. It deliberately asserts nothing about wording: no message, no
 * description, no documentation text is read, so it gives nothing away and it does not fail on
 * a rewritten sentence. Default VALUES are not recorded either — only whether a parameter is
 * optional — because a caller's code breaks on a shape change, not on a new default.
 *
 * When it fails, the fix is one of two things and never a third: either the change was not meant
 * to be a contract change and the signature goes back, or it was, and PL_PLUGIN_API_VERSION is
 * bumped and this snapshot retaken from the printed value.
 */

/** @return array<string, mixed> */
function plugin_surface(): array
{
    $functions = [];
    foreach (pl_plugin_api_functions() as $name) {
        if (!function_exists($name)) {
            $functions[$name] = 'MISSING';
            continue;
        }
        $reflection = new ReflectionFunction($name);
        $parameters = [];
        foreach ($reflection->getParameters() as $parameter) {
            $type = $parameter->getType();
            $parameters[] = ($type === null ? 'mixed' : (string) $type)
                . ' ' . ($parameter->isPassedByReference() ? '&' : '') . ($parameter->isVariadic() ? '...' : '')
                . '$' . $parameter->getName() . ($parameter->isOptional() ? '=' : '');
        }
        $returns = $reflection->getReturnType();
        $functions[$name] = ['parameters' => $parameters, 'returns' => $returns === null ? 'mixed' : (string) $returns];
    }
    ksort($functions, SORT_STRING);
    $points = pl_hook_points();
    ksort($points, SORT_STRING);
    return [
        'api_version' => PL_PLUGIN_API_VERSION,
        'contract' => PL_PLUGIN_CONTRACT,
        'constants' => ['PL_PLUGIN_API_VERSION', 'PL_PLUGIN_CONTRACT'],
        'classes' => ['PL_Hook_Veto' => get_parent_class(new PL_Hook_Veto('')) ?: ''],
        'hook_points' => $points,
        'functions' => $functions,
    ];
}

test('the published plugin surface matches its recorded snapshot for this API version', function (): void {
    $snapshotPath = __DIR__ . '/fixtures/plugin-surface.json';
    $current = json_encode(plugin_surface(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    if (getenv('PL_PRINT_PLUGIN_SURFACE') === '1') {
        echo "----- BEGIN PLUGIN SURFACE -----\n" . $current . "----- END PLUGIN SURFACE -----\n";
    }
    assert_true(is_file($snapshotPath), 'The recorded surface snapshot is missing: ' . $snapshotPath);
    $recorded = (string) file_get_contents($snapshotPath);
    // Compare the decoded values, not the bytes, so a checkout's line endings never decide this.
    $expected = json_decode($recorded, true, 64, JSON_THROW_ON_ERROR);
    $actual = json_decode($current, true, 64, JSON_THROW_ON_ERROR);
    if ($expected !== $actual) {
        $differences = [];
        foreach (['api_version', 'contract', 'constants', 'classes'] as $key) {
            if (($expected[$key] ?? null) !== ($actual[$key] ?? null)) {
                $differences[] = $key;
            }
        }
        foreach (array_unique(array_merge(array_keys($expected['hook_points'] ?? []), array_keys($actual['hook_points'] ?? []))) as $point) {
            if (($expected['hook_points'][$point] ?? null) !== ($actual['hook_points'][$point] ?? null)) {
                $differences[] = 'hook point ' . $point;
            }
        }
        foreach (array_unique(array_merge(array_keys($expected['functions'] ?? []), array_keys($actual['functions'] ?? []))) as $function) {
            if (($expected['functions'][$function] ?? null) !== ($actual['functions'][$function] ?? null)) {
                $differences[] = $function;
            }
        }
        throw new RuntimeException('The published plugin surface moved without PL_PLUGIN_API_VERSION being bumped: '
            . implode(', ', $differences) . '. Either restore the signature, or bump the version and retake the snapshot with '
            . 'PL_PRINT_PLUGIN_SURFACE=1.');
    }
});

test('every published function exists and every published extension point is raised by core', function (): void {
    foreach (pl_plugin_api_functions() as $name) {
        assert_true(function_exists($name), 'The published surface names a function this copy does not define: ' . $name . '.');
    }
    assert_same(count(pl_plugin_api_functions()), count(array_unique(pl_plugin_api_functions())), 'The published surface names each function once.');
    // A declared extension point that nothing raises is a promise nobody is keeping. Each one is
    // looked for in the application source by name, so removing the call site fails this.
    $source = '';
    $root = dirname(__DIR__) . '/www/phpledger';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        /** @var SplFileInfo $file */
        if ($file->isFile() && $file->getExtension() === 'php' && $file->getFilename() !== 'hook_functions.php') {
            $source .= (string) file_get_contents((string) $file->getPathname());
        }
    }
    foreach (pl_hook_points() as $point => $definition) {
        assert_true(str_contains($source, "'" . $point . "'"),
            'Core publishes the extension point ' . $point . ' and never raises it.');
    }
});
