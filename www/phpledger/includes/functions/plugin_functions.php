<?php
declare(strict_types=1);

/**
 * The plugin runtime: package manifests, the loader, activation state, options and plugin
 * migrations (release plan 1.2 M8; issue #73; decisions B44 to B47, B52, B77, B78 and B80).
 *
 * A plugin is a folder under pl_plugin_directory() holding `plugin.json` (the package manifest,
 * module contract 2) and the files that manifest lists with a SHA-256 each. Core records the
 * digest at installation and recomputes it on every boot: code loads only for an active plugin
 * whose files still match, and a mismatch deactivates it rather than running code nobody
 * reviewed.
 *
 * Four boundaries are deliberate and are what separates this from "run whatever is in the
 * folder":
 *  - **The loader never guesses.** A folder that is not recorded in `pl_packages` is ignored, and
 *    a recorded package whose files moved is deactivated with an audit row.
 *  - **A plugin's migrations are its own.** They run from the plugin's folder into
 *    `pl_plugin_migrations`, never into `pl_schema_migrations`, so removing a plugin can never
 *    break the core migration chain, and they may only touch tables carrying the plugin's prefix.
 *  - **A plugin never carries a second ledger.** Posting still goes through the core service;
 *    the hooks in hook_functions.php are how a plugin reaches the posting flow.
 *  - **A plugin never builds a second outbound queue** (B77). It registers a named consumer into
 *    the handler array `pl_dispatch_outbound_events()` already takes.
 */

/** Where packages live. Outside the public document root, like every other private path. */
function pl_plugin_directory(bool $create = false): string
{
    // dirname(), not PL_APP: that constant carries a '..' segment, and pl_install_private_path()
    // refuses a path with one, the same way pl_install_directory() resolves its own default.
    $path = pl_install_private_path((string) (getenv('PL_PLUGIN_DIRECTORY') ?: dirname(__DIR__, 2) . '/plugins'));
    if ($create && !is_dir($path)) {
        $mask = umask(0077);
        try {
            if (!mkdir($path, 0700, true) && !is_dir($path)) {
                throw new DomainException('Create a private writable package directory in your hosting panel.');
            }
        } finally {
            umask($mask);
        }
    }
    return $path;
}

/**
 * Safe mode. `PL_PLUGINS_DISABLED=1` in the environment, or the constant, stops every plugin from
 * loading without changing a single recorded state, so an operator can get a broken installation
 * back without a database. This is the switch a support answer points at.
 */
function pl_plugins_safe_mode(): bool
{
    if (defined('PL_PLUGINS_DISABLED') && constant('PL_PLUGINS_DISABLED') === true) {
        return true;
    }
    $value = (string) getenv('PL_PLUGINS_DISABLED');
    return $value !== '' && $value !== '0' && strtolower($value) !== 'false';
}

function pl_plugin_slug(string $slug): string
{
    if (!preg_match('/^[a-z][a-z0-9-]{0,59}$/D', $slug)) {
        throw new DomainException('A package slug is lower-case letters, digits and hyphens.');
    }
    return $slug;
}

/** Every table a plugin owns starts here; the later table-prefix work treats `pl_` as the prefix. */
function pl_plugin_table_prefix(string $slug): string
{
    return 'pl_' . str_replace('-', '_', pl_plugin_slug($slug)) . '_';
}

/** @return list<string> the folders under the package directory, in a stable order */
function pl_plugin_directory_slugs(): array
{
    $base = pl_plugin_directory();
    if (!is_dir($base)) {
        return [];
    }
    $slugs = [];
    foreach (scandir($base) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || !preg_match('/^[a-z][a-z0-9-]{0,59}$/D', $entry)) {
            continue;
        }
        if (is_dir($base . '/' . $entry) && !is_link($base . '/' . $entry) && is_file($base . '/' . $entry . '/plugin.json')) {
            $slugs[] = $entry;
        }
    }
    sort($slugs, SORT_STRING);
    return $slugs;
}

// --------------------------------------------------------------- the manifest (contract 2)

/**
 * Validate a package manifest against contract 2 and return it in canonical key order.
 *
 * Unknown keys are refused on purpose: the contract is a published promise (B78), and a manifest
 * that carries a key core silently ignores is a promise nobody is keeping. `requires` keeps the
 * exact-version rule the bundled modules already use (B47), and the descriptive card fields B52
 * asks for are required, not optional, because the Packages screen is a directory of cards and a
 * card with no description, author or licence tells a person nothing.
 *
 * @param array<array-key, mixed> $manifest
 * @return array<string, mixed>
 */
function pl_plugin_manifest_validate(array $manifest, string $slug): array
{
    $slug = pl_plugin_slug($slug);
    $known = ['type', 'slug', 'name', 'version', 'contract', 'api', 'description', 'author', 'author_url',
        'licence', 'homepage', 'adds', 'screenshots', 'requires', 'entry', 'capabilities', 'grants',
        'hooks', 'consumers', 'migrations', 'tables', 'options', 'routes', 'lang', 'files'];
    foreach (array_keys($manifest) as $key) {
        if (!is_string($key) || !in_array($key, $known, true)) {
            throw new DomainException('This package manifest carries a key contract ' . PL_PLUGIN_CONTRACT . ' does not define: ' . (is_string($key) ? $key : 'a non-string key') . '.');
        }
    }
    if (($manifest['type'] ?? null) !== 'plugin') {
        throw new DomainException('This runtime installs packages of type "plugin". A sample package carries no code and is installed by the sample importer.');
    }
    if (($manifest['slug'] ?? null) !== $slug) {
        throw new DomainException('The package slug must match its folder name.');
    }
    if (($manifest['contract'] ?? null) !== PL_PLUGIN_CONTRACT) {
        throw new DomainException('This copy supports package contract ' . PL_PLUGIN_CONTRACT . '. The package declares a different one.');
    }
    // B47: the API version is exact, not a range. A package written against a different published
    // surface is refused by name rather than half-working.
    if (($manifest['api'] ?? null) !== PL_PLUGIN_API_VERSION) {
        throw new DomainException('This package was built for plugin API ' . (is_string($manifest['api'] ?? null) ? $manifest['api'] : 'an unstated version')
            . '; this copy publishes ' . PL_PLUGIN_API_VERSION . '.');
    }
    $text = static function (mixed $value, string $field, int $limit, bool $required = true): string {
        if ($value === null && !$required) {
            return '';
        }
        if (!is_string($value) || ($required && trim($value) === '') || mb_strlen($value, 'UTF-8') > $limit) {
            throw new DomainException('A package manifest needs a ' . $field . ' of up to ' . $limit . ' characters.');
        }
        return trim($value);
    };
    $clean = [
        'type' => 'plugin',
        'slug' => $slug,
        'name' => $text($manifest['name'] ?? null, 'name', 160),
        'version' => $text($manifest['version'] ?? null, 'version', 32),
        'contract' => PL_PLUGIN_CONTRACT,
        'api' => PL_PLUGIN_API_VERSION,
        // B52's card fields. All four are required: a card is how a person decides.
        'description' => $text($manifest['description'] ?? null, 'description', 500),
        'author' => $text($manifest['author'] ?? null, 'author', 160),
        'licence' => $text($manifest['licence'] ?? null, 'licence', 80),
    ];
    if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $clean['version'])) {
        throw new DomainException('A package version is three numbers separated by dots.');
    }
    foreach (['author_url' => 'author_url', 'homepage' => 'homepage'] as $key => $field) {
        $url = $text($manifest[$key] ?? null, $field, 300, false);
        if ($url !== '' && !preg_match('#^https://[a-z0-9][a-z0-9.-]*\.[a-z]{2,}(?:/[^\s]*)?$#iD', $url)) {
            throw new DomainException('A package ' . $field . ' is an https address.');
        }
        $clean[$key] = $url;
    }
    // "What it adds", B52. One sentence per capability the card lists.
    $adds = $manifest['adds'] ?? null;
    if (!is_array($adds) || !array_is_list($adds) || $adds === [] || count($adds) > 12) {
        throw new DomainException('A package lists between one and twelve things it adds.');
    }
    $clean['adds'] = array_map(static fn (mixed $entry): string => $text($entry, 'entry in "adds"', 160), $adds);
    $clean['screenshots'] = pl_plugin_manifest_paths($manifest['screenshots'] ?? [], 'screenshots', 8);
    // B47: every requirement names an installed module or package and its exact version.
    $requires = $manifest['requires'] ?? null;
    if (!is_array($requires) || array_is_list($requires) && $requires !== []) {
        throw new DomainException('A package states its requirements as a map of module or package to exact version.');
    }
    $clean['requires'] = [];
    foreach ($requires as $dependency => $version) {
        if (!is_string($dependency) || !preg_match('/^[a-z][a-z0-9-]{0,59}$/D', $dependency) || $dependency === $slug
            || !is_string($version) || !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $version)) {
            throw new DomainException('Each requirement names a module or package and one exact version.');
        }
        $clean['requires'][$dependency] = $version;
    }
    $clean['entry'] = $text($manifest['entry'] ?? 'plugin.php', 'entry file', 120);
    foreach (['capabilities', 'hooks', 'consumers', 'migrations', 'tables', 'options', 'routes'] as $key) {
        $list = $manifest[$key] ?? [];
        if (!is_array($list) || !array_is_list($list) || count($list) > 200 || count($list) !== count(array_unique($list, SORT_REGULAR))) {
            throw new DomainException('A package declares "' . $key . '" as a list of unique strings.');
        }
        foreach ($list as $value) {
            if (!is_string($value) || $value === '' || strlen($value) > 120) {
                throw new DomainException('A package declares "' . $key . '" as a list of unique strings.');
            }
        }
        $clean[$key] = $list;
    }
    foreach ($clean['hooks'] as $hook) {
        if (!isset(pl_hook_points()[$hook])) {
            throw new DomainException('This package uses an extension point this copy does not publish: ' . $hook . '.');
        }
    }
    foreach ($clean['consumers'] as $consumer) {
        // The same shape pl_dispatch_outbound_events() validates, so a registration that passes
        // here cannot be refused by the dispatcher later.
        if (!preg_match('/^[a-z][a-z0-9_.-]{0,79}$/D', $consumer)) {
            throw new DomainException('An outbound consumer name is lower-case letters, digits, dots, hyphens and underscores.');
        }
    }
    foreach ($clean['migrations'] as $migration) {
        if (!preg_match('/^[0-9]{3}_[a-z0-9_]+$/D', $migration)) {
            throw new DomainException('A package migration is numbered like 001_initial.');
        }
    }
    $prefix = pl_plugin_table_prefix($slug);
    foreach ($clean['tables'] as $table) {
        if (!str_starts_with($table, $prefix) || !preg_match('/^[a-z0-9_]{1,64}$/D', $table)) {
            throw new DomainException('A package owns only tables named ' . $prefix . '*.');
        }
    }
    foreach ($clean['options'] as $option) {
        if (!preg_match('/^[a-z][a-z0-9_.]{0,119}$/D', $option)) {
            throw new DomainException('A package option name is lower-case letters, digits, dots and underscores.');
        }
    }
    foreach ($clean['routes'] as $route) {
        if (!preg_match('#^/[a-z0-9][a-z0-9/-]{0,80}$#D', $route)) {
            throw new DomainException('A package route is a plain lower-case path.');
        }
    }
    $clean['grants'] = pl_manifest_capability_grants($manifest);
    $lang = $text($manifest['lang'] ?? null, 'translation directory', 128, false);
    if ($lang !== '' && !preg_match('#^[a-z0-9][a-z0-9-]*(?:/[a-z0-9][a-z0-9-]*){0,4}$#D', $lang)) {
        throw new DomainException('A package translation directory is a plain relative path.');
    }
    $clean['lang'] = $lang;
    $files = $manifest['files'] ?? null;
    if (!is_array($files) || $files === [] || count($files) > 500) {
        throw new DomainException('A package lists between one and five hundred files with a SHA-256 each.');
    }
    $clean['files'] = [];
    foreach ($files as $path => $digest) {
        if (!is_string($path) || !is_string($digest) || !preg_match('/^[0-9a-f]{64}$/D', $digest)) {
            throw new DomainException('Every listed package file carries a lower-case SHA-256.');
        }
        $clean['files'][pl_plugin_manifest_path($path)] = $digest;
    }
    ksort($clean['files'], SORT_STRING);
    if (!isset($clean['files'][$clean['entry']])) {
        throw new DomainException('The package entry file must be one of its listed files.');
    }
    foreach ($clean['screenshots'] as $screenshot) {
        if (!isset($clean['files'][$screenshot])) {
            throw new DomainException('Every screenshot must be one of the package\'s listed files.');
        }
    }
    foreach ($clean['migrations'] as $migration) {
        if (!isset($clean['files']['migrations/' . $migration . '.php'])) {
            throw new DomainException('Each declared migration needs its file: migrations/' . $migration . '.php.');
        }
    }
    return $clean;
}

/** A listed path is relative, forward-slashed, and cannot climb out of the package folder. */
function pl_plugin_manifest_path(string $path): string
{
    $path = str_replace('\\', '/', $path);
    if ($path === '' || strlen($path) > 200 || str_starts_with($path, '/') || str_contains($path, "\0")
        || !preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]*$#D', $path) || in_array('..', explode('/', $path), true)
        || str_contains($path, '//')) {
        throw new DomainException('A package file path is a plain relative path inside the package.');
    }
    return $path;
}

/**
 * @param mixed $value
 * @return list<string>
 */
function pl_plugin_manifest_paths(mixed $value, string $field, int $limit): array
{
    if (!is_array($value) || !array_is_list($value) || count($value) > $limit) {
        throw new DomainException('A package declares "' . $field . '" as a list of up to ' . $limit . ' relative paths.');
    }
    $paths = [];
    foreach ($value as $entry) {
        if (!is_string($entry)) {
            throw new DomainException('A package declares "' . $field . '" as a list of up to ' . $limit . ' relative paths.');
        }
        $paths[] = pl_plugin_manifest_path($entry);
    }
    return $paths;
}

/** @return array<string, mixed> the validated manifest of a package present on disk */
function pl_plugin_read_manifest(string $slug): array
{
    $slug = pl_plugin_slug($slug);
    $path = pl_plugin_directory() . '/' . $slug . '/plugin.json';
    if (!is_file($path) || is_link($path)) {
        throw new DomainException('This package has no manifest in the package directory.');
    }
    $source = file_get_contents($path);
    if ($source === false) {
        throw new DomainException('This package manifest cannot be read.');
    }
    try {
        $manifest = json_decode($source, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new DomainException('This package manifest is not valid JSON.');
    }
    if (!is_array($manifest)) {
        throw new DomainException('This package manifest is not valid JSON.');
    }
    return pl_plugin_manifest_validate($manifest, $slug);
}

/** @param array<string, mixed> $manifest */
function pl_plugin_manifest_hash(array $manifest): string
{
    return hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
}

/**
 * Recompute the digest of what is on disk and refuse anything the manifest does not account for.
 *
 * Every listed file must exist and hash to its listed value, and — the half that matters — the
 * folder may hold nothing else. Without that, a file dropped in beside a listed one and required
 * from it would run with the digest still matching.
 *
 * @param array<string, mixed> $manifest
 */
function pl_plugin_files_digest(string $slug, array $manifest): string
{
    $base = pl_plugin_directory() . '/' . pl_plugin_slug($slug);
    /** @var array<string, string> $expected */
    $expected = $manifest['files'];
    $seen = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $entry) {
        /** @var SplFileInfo $entry */
        if ($entry->isLink()) {
            throw new DomainException('A package folder cannot contain a link: ' . $entry->getFilename() . '.');
        }
        if ($entry->isDir()) {
            continue;
        }
        $relative = str_replace('\\', '/', substr((string) $entry->getPathname(), strlen($base) + 1));
        if ($relative === 'plugin.json') {
            continue;
        }
        if (!$entry->isFile() || !isset($expected[$relative])) {
            throw new DomainException('The package folder holds a file its manifest does not list: ' . $relative . '.');
        }
        $actual = hash_file('sha256', (string) $entry->getPathname());
        if ($actual === false || !hash_equals($expected[$relative], $actual)) {
            throw new DomainException('A package file no longer matches its recorded checksum: ' . $relative . '.');
        }
        $seen[$relative] = $actual;
    }
    foreach ($expected as $relative => $digest) {
        if (!isset($seen[$relative])) {
            throw new DomainException('A file this package lists is missing: ' . $relative . '.');
        }
    }
    ksort($seen, SORT_STRING);
    $lines = [];
    foreach ($seen as $relative => $digest) {
        $lines[] = $relative . ':' . $digest;
    }
    return hash('sha256', implode("\n", $lines));
}

// ------------------------------------------------------------------------- recorded state

/** @return array<string, array<string, mixed>> every recorded package, keyed by slug */
function pl_plugin_records(): array
{
    $records = [];
    foreach (DB::query('SELECT * FROM pl_packages ORDER BY slug') as $row) {
        $row['manifest'] = json_decode((string) $row['manifest'], true, 32, JSON_THROW_ON_ERROR);
        $row['revision'] = (int) $row['revision'];
        $records[(string) $row['slug']] = $row;
    }
    return $records;
}

/** @return array<string, mixed>|null */
function pl_plugin_record(string $slug): ?array
{
    $row = DB::queryFirstRow('SELECT * FROM pl_packages WHERE slug = %s', pl_plugin_slug($slug));
    if (!$row) {
        return null;
    }
    $row['manifest'] = json_decode((string) $row['manifest'], true, 32, JSON_THROW_ON_ERROR);
    $row['revision'] = (int) $row['revision'];
    return $row;
}

/** Stamp the package row so Admin > Packages shows which plugin failed and when. */
function pl_plugin_record_failure(string $slug, string $message): void
{
    if (!preg_match('/^[a-z][a-z0-9-]{0,59}$/D', $slug)) {
        return;
    }
    DB::update('pl_packages', ['last_error' => mb_substr($message, 0, 500), 'last_error_at' => gmdate('Y-m-d H:i:s')], 'slug = %s', $slug);
}

/** Only an installation administrator changes what code runs here (B44). */
function pl_plugin_require_admin(int $actorId): void
{
    if (!pl_user_can($actorId, 0, 'installation.admin')) {
        throw new DomainException('Only an installation administrator can install, activate or remove packages.');
    }
}

/**
 * @param array<string, mixed>|null $before
 * @param array<string, mixed> $result
 * @param array<string, bool>|null $acknowledgements
 */
function pl_plugin_audit(?int $actorId, string $slug, string $action, string $trust, string $filesDigest, string $reason, ?array $before, array $result, ?array $acknowledgements = null, ?string $requestKey = null): void
{
    DB::insert('pl_package_actions', [
        'slug' => $slug,
        'actor_id' => $actorId,
        'request_key' => $requestKey,
        'action' => $action,
        'trust' => $trust,
        'files_digest' => $filesDigest,
        'reason' => mb_substr($reason, 0, 500),
        'acknowledgements' => $acknowledgements === null ? null : json_encode($acknowledgements, JSON_THROW_ON_ERROR),
        'before_state' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
        'result_json' => json_encode($result, JSON_THROW_ON_ERROR),
    ]);
}

/** @return list<array<string, mixed>> */
function pl_plugin_history(int $actorId, int $limit = 50): array
{
    pl_plugin_require_admin($actorId);
    $limit = max(1, min(200, $limit));
    return DB::query('SELECT a.slug, a.action, a.trust, a.reason, a.recorded_at, a.files_digest, u.display_name
        FROM pl_package_actions a LEFT JOIN pl_users u ON u.id = a.actor_id ORDER BY a.id DESC LIMIT %i', $limit);
}

// ------------------------------------------------------------------------------ the loader

/**
 * Load every active plugin whose files still match the digest recorded at installation, and
 * return the slugs that were loaded.
 *
 * The cost of this for an installation with no packages is one `is_dir()` and one `scandir()`,
 * which is why the folder is read before the database is asked anything.
 *
 * @return list<string>
 */
function pl_plugin_boot(): array
{
    try {
        if (pl_plugins_safe_mode() || pl_plugin_directory_slugs() === []) {
            return [];
        }
    } catch (Throwable $error) {
        // A package directory a host has made unreadable is a package problem, never a reason
        // for the accounting application to stop answering.
        error_log('PHP Ledger package directory unavailable (' . get_class($error) . ').');
        return [];
    }
    try {
        $active = DB::query("SELECT slug, manifest, files_digest FROM pl_packages WHERE type = 'plugin' AND status = 'active' ORDER BY slug");
    } catch (Throwable $error) {
        // A copy whose migrations have not run yet has no package table; that is not a fault and
        // must not stop the application from reaching its own installer.
        error_log('PHP Ledger package state unavailable (' . get_class($error) . ').');
        return [];
    }
    $loaded = [];
    foreach ($active as $row) {
        $slug = (string) $row['slug'];
        try {
            $manifest = pl_plugin_manifest_validate(json_decode((string) $row['manifest'], true, 32, JSON_THROW_ON_ERROR), $slug);
            $digest = pl_plugin_files_digest($slug, $manifest);
            if (!hash_equals((string) $row['files_digest'], $digest)) {
                throw new DomainException('This package\'s files no longer match the version that was reviewed and installed.');
            }
            pl_plugin_load($slug, $manifest);
            $loaded[] = $slug;
        } catch (Throwable $error) {
            pl_plugin_auto_deactivate($slug, $error instanceof DomainException
                ? $error->getMessage()
                : 'This package could not be loaded (' . get_class($error) . ').');
        }
    }
    return $loaded;
}

/**
 * Require a plugin's entry file behind a breadcrumb, so a fatal in plugin code is recognised as a
 * plugin fatal on the next request and the plugin is deactivated — not mistaken for a failed core
 * update. The core updater's own maintenance state is decided earlier, in
 * pl_update_application_guard(), and nothing here touches it.
 *
 * @param array<string, mixed> $manifest
 */
function pl_plugin_load(string $slug, array $manifest): void
{
    $breadcrumb = pl_plugin_breadcrumb_path($slug);
    if ($breadcrumb !== null && is_file($breadcrumb)) {
        @unlink($breadcrumb);
        throw new DomainException('This package stopped the application while it was loading and has been deactivated. Its code, not a core update, ended that request.');
    }
    if ($breadcrumb !== null) {
        @file_put_contents($breadcrumb, json_encode(['slug' => $slug, 'started_at' => gmdate('c')], JSON_THROW_ON_ERROR));
    }
    try {
        /** @var mixed $registration */
        $registration = require pl_plugin_directory() . '/' . $slug . '/' . $manifest['entry'];
    } finally {
        if ($breadcrumb !== null) {
            @unlink($breadcrumb);
        }
    }
    // A package may register at file scope, in the WordPress way, or return one callable that
    // core calls with its own context. Both are supported; neither is required.
    if (is_callable($registration)) {
        $registration(['slug' => $slug, 'version' => $manifest['version'], 'api' => PL_PLUGIN_API_VERSION,
            'table_prefix' => pl_plugin_table_prefix($slug)]);
    }
}

/** Null when the package directory is not writable; fatal recovery is then unavailable. */
function pl_plugin_breadcrumb_path(string $slug): ?string
{
    $directory = pl_plugin_directory() . '/.loading';
    if (!is_dir($directory)) {
        $mask = umask(0077);
        try {
            if (!@mkdir($directory, 0700, true) && !is_dir($directory)) {
                return null;
            }
        } finally {
            umask($mask);
        }
    }
    return is_writable($directory) ? $directory . '/' . pl_plugin_slug($slug) . '.json' : null;
}

/**
 * Deactivate a package the loader could not trust. This runs with no actor, in its own
 * transaction, and it never throws: the request that discovered the problem is still a request
 * somebody made, and it has to finish.
 */
function pl_plugin_auto_deactivate(string $slug, string $reason): void
{
    try {
        pl_ledger_transaction(function () use ($slug, $reason): void {
            $before = pl_plugin_record($slug);
            if ($before === null || $before['status'] !== 'active') {
                return;
            }
            DB::update('pl_packages', ['status' => 'failed', 'last_error' => mb_substr($reason, 0, 500),
                'last_error_at' => gmdate('Y-m-d H:i:s'), 'revision' => $before['revision'] + 1, 'updated_at' => gmdate('Y-m-d H:i:s')], 'slug = %s', $slug);
            pl_plugin_audit(null, $slug, 'auto_deactivated', (string) $before['trust'], (string) $before['files_digest'], $reason,
                ['status' => $before['status']], ['status' => 'failed']);
        });
    } catch (Throwable $error) {
        error_log('PHP Ledger could not record a package deactivation (' . get_class($error) . ').');
    }
    pl_remove_hooks($slug);
}

// ------------------------------------------------------------------------------- lifecycle

/**
 * Record a package that is present in the package directory. An unverified package — anything the
 * owner uploaded rather than the project signing — installs only with the three acknowledgements
 * the confirmation page collects, and the audit row keeps them with the digest (B51, issue #73).
 *
 * @param array<string, bool> $acknowledgements
 * @return array<string, mixed>
 */
function pl_plugin_install(int $actorId, string $slug, string $trust, string $reason, string $key, array $acknowledgements = []): array
{
    pl_demo_require_setup_action();
    $slug = pl_plugin_slug($slug);
    $key = pl_request_key($key);
    $reason = pl_ledger_text($reason, 'Reason', 500);
    if (!in_array($trust, ['verified', 'unverified'], true)) {
        throw new DomainException('A package is either verified by the project or unverified.');
    }
    if ($trust === 'unverified') {
        foreach (pl_plugin_acknowledgements() as $name => $statement) {
            if (($acknowledgements[$name] ?? false) !== true) {
                throw new DomainException('Confirm every statement on the review page before installing unverified code.');
            }
        }
    }
    $manifest = pl_plugin_read_manifest($slug);
    $digest = pl_plugin_files_digest($slug, $manifest);
    return pl_ledger_transaction(function () use ($actorId, $slug, $trust, $reason, $key, $manifest, $digest, $acknowledgements): array {
        pl_plugin_require_admin($actorId);
        if (pl_plugin_record($slug) !== null) {
            throw new DomainException('This package is already installed. Deactivate and remove it before installing another copy.');
        }
        $result = ['slug' => $slug, 'version' => $manifest['version'], 'status' => 'installed', 'trust' => $trust];
        DB::insert('pl_packages', [
            'slug' => $slug, 'type' => 'plugin', 'name' => $manifest['name'], 'version' => $manifest['version'],
            'contract' => PL_PLUGIN_CONTRACT, 'api_version' => PL_PLUGIN_API_VERSION, 'trust' => $trust, 'status' => 'installed',
            'manifest' => json_encode($manifest, JSON_THROW_ON_ERROR), 'manifest_hash' => pl_plugin_manifest_hash($manifest),
            'files_digest' => $digest, 'revision' => 1, 'installed_by' => $actorId, 'updated_by' => $actorId,
        ]);
        pl_plugin_audit($actorId, $slug, $trust === 'unverified' ? 'upload_accepted' : 'installed', $trust, $digest, $reason,
            null, $result, $trust === 'unverified' ? $acknowledgements : null, $key);
        return $result;
    });
}

/** The three statements an owner accepts before unverified code runs (issue #73, B51). */
function pl_plugin_acknowledgements(): array
{
    return [
        'unreviewed' => 'This package has not been reviewed by the PHP Ledger project.',
        'full_access' => 'It runs with full access to this application and its database, and may change what postings do.',
        'backups' => 'Backups and recovery are my responsibility, and support covers verified packages only.',
    ];
}

/**
 * Activate a package: run its own migrations, register the capabilities it grants, and record it
 * active. Migrations run before the state transaction because schema changes cannot be rolled
 * back; their receipts make a second attempt a no-op.
 *
 * @return array<string, mixed>
 */
function pl_plugin_activate(int $actorId, string $slug, string $reason, string $key): array
{
    pl_demo_require_setup_action();
    $slug = pl_plugin_slug($slug);
    $key = pl_request_key($key);
    $reason = pl_ledger_text($reason, 'Reason', 500);
    pl_plugin_require_admin($actorId);
    $record = pl_plugin_record($slug);
    if ($record === null) {
        throw new DomainException('Install this package before activating it.');
    }
    $manifest = pl_plugin_read_manifest($slug);
    $digest = pl_plugin_files_digest($slug, $manifest);
    if (!hash_equals((string) $record['files_digest'], $digest)) {
        throw new DomainException('This package\'s files changed since it was installed. Remove it and install the version you intend to run.');
    }
    pl_plugin_require_requirements($manifest);
    pl_plugin_migrate($slug, $manifest);
    return pl_ledger_transaction(function () use ($actorId, $slug, $reason, $key, $manifest, $digest): array {
        pl_plugin_require_admin($actorId);
        $before = pl_plugin_record($slug);
        if ($before === null) {
            throw new DomainException('Install this package before activating it.');
        }
        if ($before['status'] === 'active') {
            return ['slug' => $slug, 'version' => $manifest['version'], 'status' => 'active', 'trust' => $before['trust']];
        }
        pl_plugin_sync_capabilities($slug, $manifest);
        $result = ['slug' => $slug, 'version' => $manifest['version'], 'status' => 'active', 'trust' => $before['trust']];
        DB::update('pl_packages', ['status' => 'active', 'last_error' => '', 'last_error_at' => null,
            'revision' => $before['revision'] + 1, 'updated_by' => $actorId, 'updated_at' => gmdate('Y-m-d H:i:s')], 'slug = %s', $slug);
        pl_plugin_audit($actorId, $slug, 'activated', (string) $before['trust'], $digest, $reason, ['status' => $before['status']], $result, null, $key);
        pl_hook_after_commit('plugin.activated', [$slug, $result]);
        return $result;
    });
}

/** @return array<string, mixed> */
function pl_plugin_deactivate(int $actorId, string $slug, string $reason, string $key): array
{
    pl_demo_require_setup_action();
    $slug = pl_plugin_slug($slug);
    $key = pl_request_key($key);
    $reason = pl_ledger_text($reason, 'Reason', 500);
    $result = pl_ledger_transaction(function () use ($actorId, $slug, $reason, $key): array {
        pl_plugin_require_admin($actorId);
        $before = pl_plugin_record($slug);
        if ($before === null) {
            throw new DomainException('This package is not installed.');
        }
        // Anything that depends on this one stops first, exactly as a bundled module does.
        foreach (pl_plugin_records() as $dependent => $row) {
            if ($dependent !== $slug && $row['status'] === 'active' && isset($row['manifest']['requires'][$slug])) {
                throw new DomainException('Deactivate ' . $row['name'] . ' first: it requires this package.');
            }
        }
        $result = ['slug' => $slug, 'version' => (string) $before['version'], 'status' => 'installed', 'trust' => (string) $before['trust']];
        DB::update('pl_packages', ['status' => 'installed', 'revision' => $before['revision'] + 1,
            'updated_by' => $actorId, 'updated_at' => gmdate('Y-m-d H:i:s')], 'slug = %s', $slug);
        pl_plugin_audit($actorId, $slug, 'deactivated', (string) $before['trust'], (string) $before['files_digest'], $reason,
            ['status' => $before['status']], $result, null, $key);
        pl_hook_after_commit('plugin.deactivated', [$slug, $result]);
        return $result;
    });
    pl_remove_hooks($slug);
    return $result;
}

/**
 * Remove a package. Its data is kept unless the administrator asks for it to be deleted, and
 * "deleted" means the tables the manifest declares and the options under its slug — never a core
 * table, and never a row in the ledger.
 *
 * @return array<string, mixed>
 */
function pl_plugin_uninstall(int $actorId, string $slug, bool $deleteData, string $reason, string $key): array
{
    pl_demo_require_setup_action();
    $slug = pl_plugin_slug($slug);
    $key = pl_request_key($key);
    $reason = pl_ledger_text($reason, 'Reason', 500);
    pl_plugin_require_admin($actorId);
    $before = pl_plugin_record($slug);
    if ($before === null) {
        throw new DomainException('This package is not installed.');
    }
    if ($before['status'] === 'active') {
        throw new DomainException('Deactivate this package before removing it.');
    }
    /** @var array<string, mixed> $manifest */
    $manifest = $before['manifest'];
    if ($deleteData) {
        // DDL outside the state transaction: MySQL cannot roll a DROP back, so it must not sit
        // inside a transaction that might still fail afterwards.
        $prefix = pl_plugin_table_prefix($slug);
        foreach ((array) ($manifest['tables'] ?? []) as $table) {
            if (is_string($table) && str_starts_with($table, $prefix) && preg_match('/^[a-z0-9_]{1,64}$/D', $table)) {
                DB::query('DROP TABLE IF EXISTS %b', $table);
            }
        }
    }
    return pl_ledger_transaction(function () use ($actorId, $slug, $deleteData, $reason, $key, $before): array {
        pl_plugin_require_admin($actorId);
        $current = pl_plugin_record($slug);
        if ($current === null || $current['status'] === 'active') {
            throw new DomainException('This package changed while you were reading it. Reload Packages.');
        }
        if ($deleteData) {
            DB::delete('pl_plugin_options', 'slug = %s', $slug);
            DB::delete('pl_plugin_secrets', 'slug = %s', $slug);
            DB::delete('pl_plugin_migrations', 'slug = %s', $slug);
        }
        $result = ['slug' => $slug, 'status' => 'removed', 'data_deleted' => $deleteData];
        DB::delete('pl_packages', 'slug = %s', $slug);
        pl_plugin_audit($actorId, $slug, 'uninstalled', (string) $before['trust'], (string) $before['files_digest'], $reason,
            ['status' => $before['status'], 'version' => $before['version']], $result, null, $key);
        return $result;
    });
}

/**
 * Exact-version requirements (B47), checked against the bundled module registry first and the
 * installed packages second, so a plugin can depend on another plugin.
 *
 * @param array<string, mixed> $manifest
 */
function pl_plugin_require_requirements(array $manifest): void
{
    $modules = pl_module_registry();
    $packages = pl_plugin_records();
    /** @var array<string, string> $requires */
    $requires = $manifest['requires'];
    foreach ($requires as $dependency => $version) {
        if (isset($modules[$dependency])) {
            if ($modules[$dependency]['version'] !== $version) {
                throw new DomainException('This package needs the ' . $modules[$dependency]['name'] . ' module at exactly '
                    . $version . '; this copy has ' . $modules[$dependency]['version'] . '.');
            }
            pl_module_installed($modules[$dependency]);
            continue;
        }
        if (!isset($packages[$dependency])) {
            throw new DomainException('This package needs "' . $dependency . '" ' . $version . ', which is not installed here.');
        }
        if ((string) $packages[$dependency]['version'] !== $version || $packages[$dependency]['status'] !== 'active') {
            throw new DomainException('This package needs "' . $dependency . '" ' . $version . ' to be installed and active.');
        }
    }
}

/**
 * Register the capabilities a package grants (B44's catalogue, owner_type "plugin"). Nothing is
 * ever removed, for the same reason a module's grants are not: a withdrawn capability is a silent
 * permission change.
 *
 * @param array<string, mixed> $manifest
 */
function pl_plugin_sync_capabilities(string $slug, array $manifest): void
{
    /** @var array<string, string> $grants */
    $grants = $manifest['grants'];
    foreach ($grants as $capability => $label) {
        DB::insertUpdate('pl_capabilities', [
            'capability' => $capability, 'owner_type' => 'plugin', 'owner_id' => $slug,
            'scope' => 'company', 'label' => $label, 'description' => '',
        ], ['owner_type' => 'plugin', 'owner_id' => $slug, 'scope' => 'company', 'label' => $label]);
    }
    if ($grants !== []) {
        pl_capability_cache_reset();
    }
}

// --------------------------------------------------------------------- plugin migrations

/**
 * Run a package's own migrations into `pl_plugin_migrations`.
 *
 * This is a deliberate mirror of pl_migrate() rather than a branch inside it: the core chain is
 * the thing that must never be broken by a package that is removed later, so a plugin's receipts
 * live in their own table and a plugin's statements may only touch its own prefixed tables.
 * The statement check below is a boundary, not a sandbox — a plugin's PHP runs with the same
 * database connection as core, and the Unverified confirmation says so in plain words.
 *
 * @param array<string, mixed> $manifest
 * @return list<string> the migrations applied by this call
 */
function pl_plugin_migrate(string $slug, array $manifest): array
{
    $slug = pl_plugin_slug($slug);
    /** @var list<string> $declared */
    $declared = $manifest['migrations'];
    if ($declared === []) {
        return [];
    }
    // pl_install_tables() is the installer's, not the request's: a plugin activation is the only
    // place in a request that needs it, so it is loaded here rather than in the bootstrap.
    require_once __DIR__ . '/install_functions.php';
    $prefix = pl_plugin_table_prefix($slug);
    $base = pl_plugin_directory() . '/' . $slug . '/migrations';
    $lock = 'phpledger:plugin:' . substr(hash('sha256', $slug . '|' . (string) DB::queryFirstField('SELECT DATABASE()') . '|' . pl_database_prefix()), 0, 40);
    if ((int) DB::queryFirstField('SELECT GET_LOCK(%s, 10)', $lock) !== 1) {
        throw new DomainException('Another package operation is running. Try again after it finishes.');
    }
    $applied = [];
    try {
        $versions = $declared;
        sort($versions, SORT_STRING);
        foreach ($versions as $version) {
            $file = $base . '/' . $version . '.php';
            if (!is_file($file) || is_link($file)) {
                throw new DomainException('This package declares a migration it does not ship: ' . $version . '.');
            }
            $checksum = hash_file('sha256', $file);
            if ($checksum === false) {
                throw new DomainException('A package migration cannot be read: ' . $version . '.');
            }
            $receipt = DB::queryFirstRow('SELECT * FROM pl_plugin_migrations WHERE slug = %s AND version = %s', $slug, $version);
            if ($receipt && !hash_equals((string) $receipt['checksum'], $checksum)) {
                throw new DomainException('A package migration changed after it was applied: ' . $version . '.');
            }
            if ($receipt && $receipt['status'] === 'applied') {
                continue;
            }
            $statements = require $file;
            if (!is_array($statements) || $statements === []) {
                throw new DomainException('A package migration returns a non-empty list of SQL statements: ' . $version . '.');
            }
            $statements = array_values($statements);
            $completed = $receipt ? (int) ($receipt['statements_done'] ?? 0) : 0;
            if ($completed > count($statements)) {
                throw new DomainException('The recorded progress of ' . $version . ' does not match this package.');
            }
            // Every statement is checked before the first one runs and before any receipt is
            // written, so a migration that reaches outside its own tables leaves nothing at all
            // behind — not a half-applied schema and not an "applying" receipt to clean up.
            foreach ($statements as $statement) {
                if (!is_string($statement) || trim($statement) === '') {
                    throw new DomainException('A package migration statement is empty: ' . $version . '.');
                }
                pl_plugin_assert_statement($statement, $prefix, $version);
            }
            if (!$receipt) {
                DB::insert('pl_plugin_migrations', ['slug' => $slug, 'version' => $version, 'checksum' => $checksum, 'status' => 'applying', 'statements_done' => 0]);
            }
            $before = pl_install_tables();
            foreach ($statements as $index => $statement) {
                if ($index < $completed) {
                    continue;
                }
                DB::query((string) $statement);
                DB::update('pl_plugin_migrations', ['statements_done' => $index + 1], 'slug = %s AND version = %s', $slug, $version);
            }
            foreach (array_diff(pl_install_tables(), $before) as $created) {
                if (!str_starts_with((string) $created, $prefix)) {
                    throw new DomainException('A package migration created a table outside its own prefix: ' . $created . '.');
                }
            }
            DB::update('pl_plugin_migrations', ['status' => 'applied', 'applied_at' => gmdate('Y-m-d H:i:s')], 'slug = %s AND version = %s', $slug, $version);
            $applied[] = $version;
        }
    } finally {
        DB::queryFirstField('SELECT RELEASE_LOCK(%s)', $lock);
    }
    return $applied;
}

/**
 * A package migration statement is one of a small set of shapes, and its target carries the
 * package's table prefix. Everything else — a grant, a database-level change, a statement whose
 * target cannot be read — is refused before it runs.
 */
function pl_plugin_assert_statement(string $statement, string $prefix, string $version): void
{
    $normalized = trim(preg_replace('/\s+/', ' ', $statement) ?? $statement);
    $pattern = '/^(?:CREATE TABLE(?: IF NOT EXISTS)?|ALTER TABLE|DROP TABLE(?: IF EXISTS)?|TRUNCATE TABLE|'
        . 'INSERT(?: IGNORE)? INTO|REPLACE INTO|UPDATE|DELETE FROM|CREATE(?: UNIQUE)? INDEX [A-Za-z0-9_`]+ ON|DROP INDEX [A-Za-z0-9_`]+ ON)\s+`?([A-Za-z0-9_]+)`?/i';
    if (!preg_match($pattern, $normalized, $match)) {
        throw new DomainException('A package migration may only create or change its own tables: ' . $version . '.');
    }
    if (!str_starts_with(strtolower($match[1]), $prefix)) {
        throw new DomainException('A package migration may only touch tables named ' . $prefix . '*, not ' . $match[1] . ' (' . $version . ').');
    }
}

// -------------------------------------------------------------------------------- options

/**
 * One shared options table for every package (B46). `company_id` 0 is the installation scope, the
 * same convention pl_user_can($actor, 0, ...) already uses for installation capabilities; a
 * non-zero value is checked against a real company.
 *
 * A stored value is JSON, so a package keeps a list or a map without a table of its own. Secrets
 * do not belong here: this release has no reversible secret store, and B77 records that key
 * custody is an owner decision that has not been taken.
 */
function pl_plugin_option_scope(int $companyId): int
{
    if ($companyId === 0) {
        return 0;
    }
    if ($companyId < 0 || (int) DB::queryFirstField('SELECT id FROM pl_companies WHERE id = %i', $companyId) !== $companyId) {
        throw new DomainException('A package option belongs to the installation or to one existing business.');
    }
    return $companyId;
}

function pl_plugin_option_name(string $name): string
{
    if (!preg_match('/^[a-z][a-z0-9_.]{0,119}$/D', $name)) {
        throw new DomainException('A package option name is lower-case letters, digits, dots and underscores.');
    }
    return $name;
}

function pl_plugin_option_get(string $slug, string $name, int $companyId = 0, mixed $default = null): mixed
{
    $raw = DB::queryFirstField('SELECT option_value FROM pl_plugin_options WHERE slug = %s AND company_id = %i AND option_name = %s',
        pl_plugin_slug($slug), pl_plugin_option_scope($companyId), pl_plugin_option_name($name));
    if ($raw === null) {
        return $default;
    }
    return json_decode((string) $raw, true, 32, JSON_THROW_ON_ERROR);
}

/** @return array<string, mixed> */
function pl_plugin_option_all(string $slug, int $companyId = 0): array
{
    $options = [];
    foreach (DB::query('SELECT option_name, option_value FROM pl_plugin_options WHERE slug = %s AND company_id = %i ORDER BY option_name',
        pl_plugin_slug($slug), pl_plugin_option_scope($companyId)) as $row) {
        $options[(string) $row['option_name']] = json_decode((string) $row['option_value'], true, 32, JSON_THROW_ON_ERROR);
    }
    return $options;
}

function pl_plugin_option_set(int $actorId, string $slug, string $name, mixed $value, int $companyId = 0): void
{
    $slug = pl_plugin_slug($slug);
    $name = pl_plugin_option_name($name);
    $scope = pl_plugin_option_scope($companyId);
    $encoded = json_encode($value, JSON_THROW_ON_ERROR);
    if (strlen($encoded) > 262144) {
        throw new DomainException('A package option holds up to 256 KB. Larger data belongs in the package\'s own table.');
    }
    // An installation-scoped setting is an installation act; a company-scoped one belongs to
    // somebody who may administer that company's settings.
    if ($scope === 0) {
        pl_plugin_require_admin($actorId);
    } else {
        pl_require_capability($actorId, $scope, 'modules.manage', 'Your role cannot change package settings for this business.');
    }
    $record = pl_plugin_record($slug);
    if ($record === null) {
        throw new DomainException('This package is not installed.');
    }
    DB::insertUpdate('pl_plugin_options', [
        'slug' => $slug, 'company_id' => $scope, 'option_name' => $name, 'option_value' => $encoded,
        'updated_by' => $actorId, 'updated_at' => gmdate('Y-m-d H:i:s'),
    ], ['option_value' => $encoded, 'updated_by' => $actorId, 'updated_at' => gmdate('Y-m-d H:i:s')]);
}

function pl_plugin_option_delete(int $actorId, string $slug, string $name, int $companyId = 0): void
{
    $scope = pl_plugin_option_scope($companyId);
    if ($scope === 0) {
        pl_plugin_require_admin($actorId);
    } else {
        pl_require_capability($actorId, $scope, 'modules.manage', 'Your role cannot change package settings for this business.');
    }
    DB::delete('pl_plugin_options', 'slug = %s AND company_id = %i AND option_name = %s', pl_plugin_slug($slug), $scope, pl_plugin_option_name($name));
}

// -------------------------------------------------------------------------------- secrets

/**
 * A package's credentials (B83, B78): the exact same scope as an option (installation, or one
 * business — pl_plugin_option_scope() is reused rather than a second scoping rule), but the
 * stored value is authenticated ciphertext from secret_functions.php, never plaintext and never
 * JSON like an option's value can be. A secret is a single string a connector will replay to an
 * outside service — an SMTP password, an API token — not a structured setting.
 *
 * There is deliberately no `pl_plugin_secret_all()` returning every value the way
 * `pl_plugin_option_all()` does: decrypting a whole scope at once for a caller that only ever
 * needs one credential is exactly the kind of casual plaintext handling B83's rules exist to
 * prevent. pl_plugin_secret_names() answers "what is set" without decrypting anything, which is
 * what a settings screen needs to show which fields already hold a value.
 */
function pl_plugin_secret_name(string $name): string
{
    if (!preg_match('/^[a-z][a-z0-9_.]{0,119}$/D', $name)) {
        throw new DomainException('A package secret name is lower-case letters, digits, dots and underscores.');
    }
    return $name;
}

/**
 * The decrypted value, for a package's own server-side code to use — for example, inside the
 * outbound-consumer handler it registered, to authenticate with a mail provider. Never call this
 * from a code path that renders, JSON-encodes or logs the result: nothing downstream of it may
 * reach a browser.
 */
function pl_plugin_secret_get(string $slug, string $name, int $companyId = 0): ?string
{
    return pl_secret_locked(function () use ($slug, $name, $companyId): ?string {
        $slug = pl_plugin_slug($slug);
        $scope = pl_plugin_option_scope($companyId);
        $name = pl_plugin_secret_name($name);
        $raw = DB::queryFirstField('SELECT ciphertext FROM pl_plugin_secrets WHERE slug = %s AND company_id = %i AND secret_name = %s', $slug, $scope, $name);
        return $raw === null ? null : pl_secret_decrypt((string) $raw, pl_secret_context($slug, $scope, $name));
    });
}

/** @return list<string> which secrets are set for this scope, never their values — what a
 *  settings screen shows to say "a value is already saved" without ever decrypting one. */
function pl_plugin_secret_names(string $slug, int $companyId = 0): array
{
    $names = [];
    foreach (DB::query('SELECT secret_name FROM pl_plugin_secrets WHERE slug = %s AND company_id = %i ORDER BY secret_name',
        pl_plugin_slug($slug), pl_plugin_option_scope($companyId)) as $row) {
        $names[] = (string) $row['secret_name'];
    }
    return $names;
}

function pl_plugin_secret_set(int $actorId, string $slug, string $name, string $value, int $companyId = 0): void
{
    $slug = pl_plugin_slug($slug);
    $name = pl_plugin_secret_name($name);
    $scope = pl_plugin_option_scope($companyId);
    if ($value === '' || strlen($value) > 65536) {
        throw new DomainException('A package secret is a non-empty value of up to 64 KB.');
    }
    if ($scope === 0) {
        pl_plugin_require_admin($actorId);
    } else {
        pl_require_capability($actorId, $scope, 'modules.manage', 'Your role cannot change package settings for this business.');
    }
    if (pl_plugin_record($slug) === null) {
        throw new DomainException('This package is not installed.');
    }
    if (DB::transactionDepth() !== 0) { throw new LogicException('Secret writes cannot run inside a transaction.'); }
    pl_secret_locked(function () use ($slug, $scope, $name, $value, $actorId): void {
        $ciphertext = pl_secret_encrypt($value, pl_secret_context($slug, $scope, $name));
        pl_ledger_transaction(function () use ($slug, $scope, $name, $ciphertext, $actorId): void {
            DB::insertUpdate('pl_plugin_secrets', [
                'slug' => $slug, 'company_id' => $scope, 'secret_name' => $name, 'ciphertext' => $ciphertext,
                'updated_by' => $actorId, 'updated_at' => gmdate('Y-m-d H:i:s'),
            ], ['ciphertext' => $ciphertext, 'updated_by' => $actorId, 'updated_at' => gmdate('Y-m-d H:i:s')]);
        });
    });
}

function pl_plugin_secret_delete(int $actorId, string $slug, string $name, int $companyId = 0): void
{
    $scope = pl_plugin_option_scope($companyId);
    if ($scope === 0) {
        pl_plugin_require_admin($actorId);
    } else {
        pl_require_capability($actorId, $scope, 'modules.manage', 'Your role cannot change package settings for this business.');
    }
    DB::delete('pl_plugin_secrets', 'slug = %s AND company_id = %i AND secret_name = %s', pl_plugin_slug($slug), $scope, pl_plugin_secret_name($name));
}

// --------------------------------------------------------- the outbound registration point

/**
 * The handler array pl_dispatch_outbound_events() takes, after every active channel package has
 * registered its consumer (B77, B78).
 *
 * There is one outbound machine — `pl_outbound_events`, `pl_outbound_deliveries` and
 * `pl_outbound_attempts` from migration 014 — and this is the only way a package joins it. A
 * package that tried to build a second queue would be building it against the same tables with
 * none of the leasing, retry or dead-lettering this one already has.
 *
 * A registration is checked here for the same shape the dispatcher checks, and for one more
 * thing the dispatcher cannot know: the consumer name must be one the package's manifest
 * declares, so a package cannot quietly take over another package's deliveries.
 *
 * @return array<string, array{company_id:int, book_id:int, handler:callable}>
 */
function pl_plugin_outbound_handlers(int $companyId, int $bookId): array
{
    $declared = [];
    foreach (pl_plugin_records() as $slug => $record) {
        if ($record['status'] !== 'active') {
            continue;
        }
        foreach ((array) ($record['manifest']['consumers'] ?? []) as $consumer) {
            if (is_string($consumer)) {
                $declared[$consumer] = $slug;
            }
        }
    }
    /** @var mixed $handlers */
    $handlers = pl_apply_filters('outbound.consumers', [], [['company_id' => $companyId, 'book_id' => $bookId, 'declared' => $declared]]);
    if (!is_array($handlers)) {
        throw new DomainException('A package returned an outbound registration that is not a list of consumers.');
    }
    $registered = [];
    foreach ($handlers as $consumer => $registration) {
        if (!is_string($consumer) || !isset($declared[$consumer])) {
            throw new DomainException('A package registered an outbound consumer its manifest does not declare: '
                . (is_string($consumer) ? $consumer : 'an unnamed consumer') . '.');
        }
        if (!is_array($registration) || !is_callable($registration['handler'] ?? null)) {
            throw new DomainException('An outbound consumer registration needs a handler: ' . $consumer . '.');
        }
        $registered[$consumer] = ['company_id' => $companyId, 'book_id' => $bookId, 'handler' => $registration['handler']];
    }
    return $registered;
}

// ------------------------------------------------------------------------ the owner upload

/**
 * Stage an uploaded package ZIP into the package directory and return its manifest, without
 * recording anything: the confirmation page reads what this returns, and only
 * pl_plugin_install() writes state (issue #73, B51, onboarding decision 9).
 *
 * Every member is checked before a single byte is written, and ZipArchive::extractTo() is never
 * used, exactly as the core updater's stager already does.
 *
 * @return array{slug:string, manifest:array<string, mixed>, files_digest:string}
 */
function pl_plugin_stage_archive(int $actorId, string $archive): array
{
    pl_plugin_require_admin($actorId);
    if (!class_exists(ZipArchive::class)) {
        throw new DomainException('Enable PHP ZIP to upload a package.');
    }
    if (!is_file($archive) || is_link($archive)) {
        throw new DomainException('The uploaded package could not be read.');
    }
    $size = filesize($archive);
    if ($size === false || $size > 20000000) {
        throw new DomainException('A package archive is up to 20 MB.');
    }
    $zip = new ZipArchive();
    if ($zip->open($archive, ZipArchive::RDONLY) !== true) {
        throw new DomainException('The uploaded package is not a readable ZIP archive.');
    }
    try {
        if ($zip->numFiles < 1 || $zip->numFiles > 501) {
            throw new DomainException('A package archive holds between one and five hundred files.');
        }
        $first = $zip->statIndex(0);
        if (!$first || !preg_match('#^([a-z][a-z0-9-]{0,59})/#D', (string) $first['name'], $root)) {
            throw new DomainException('A package archive unpacks into one folder named after its slug.');
        }
        $slug = pl_plugin_slug($root[1]);
        $prefix = $slug . '/';
        $members = [];
        $expanded = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entry = $zip->statIndex($index);
            if (!$entry || !str_starts_with((string) $entry['name'], $prefix)) {
                throw new DomainException('Every file in a package archive sits under its own folder.');
            }
            $name = substr((string) $entry['name'], strlen($prefix));
            if ($name === '' || str_ends_with($name, '/')) {
                continue;
            }
            $zip->getExternalAttributesIndex($index, $system, $attributes);
            $type = ((int) $attributes >> 16) & 0170000;
            if (($type !== 0 && $type !== 0100000) || $entry['encryption_method'] !== 0) {
                throw new DomainException('A package archive cannot contain links, devices or encrypted members.');
            }
            $expanded += (int) $entry['size'];
            if ($expanded > 40000000) {
                throw new DomainException('The expanded package exceeds the supported size.');
            }
            $members[pl_plugin_manifest_path($name)] = $index;
        }
        if (!isset($members['plugin.json'])) {
            throw new DomainException('A package archive carries plugin.json beside its files.');
        }
        $base = pl_plugin_directory(true) . '/' . $slug;
        if (file_exists($base)) {
            throw new DomainException('A package folder named ' . $slug . ' is already staged here. Remove the installed copy first.');
        }
        foreach ($members as $name => $index) {
            $data = $zip->getFromIndex($index);
            if ($data === false) {
                throw new DomainException('A package archive member could not be read: ' . $name . '.');
            }
            pl_plugin_write_staged($base . '/' . $name, $data);
        }
    } finally {
        $zip->close();
    }
    try {
        $manifest = pl_plugin_read_manifest($slug);
        $digest = pl_plugin_files_digest($slug, $manifest);
    } catch (Throwable $error) {
        pl_plugin_remove_directory(pl_plugin_directory() . '/' . $slug);
        throw $error;
    }
    return ['slug' => $slug, 'manifest' => $manifest, 'files_digest' => $digest];
}

function pl_plugin_write_staged(string $path, string $bytes): void
{
    $directory = dirname($path);
    $mask = umask(0077);
    try {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new DomainException('The package directory is not writable. Check its hosting permissions.');
        }
        if (file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes)) {
            throw new DomainException('A package file could not be written.');
        }
    } finally {
        umask($mask);
    }
    @chmod($path, 0600);
}

/** Used to undo a staging that did not validate, and by an uninstall that may delete files. */
function pl_plugin_remove_directory(string $path): bool
{
    $base = pl_plugin_directory();
    $resolved = str_replace('\\', '/', (string) realpath($path));
    if ($resolved === '' || !str_starts_with($resolved, str_replace('\\', '/', $base) . '/') || !is_dir($resolved)) {
        return false;
    }
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) {
        /** @var SplFileInfo $entry */
        if ($entry->isDir() && !$entry->isLink()) {
            @rmdir((string) $entry->getPathname());
            continue;
        }
        @unlink((string) $entry->getPathname());
    }
    return @rmdir($resolved);
}

/**
 * The card list Admin > Packages renders: every bundled module and every recorded package in one
 * shape, with the descriptive fields B52 asks for (name, description, version, author, licence,
 * what it adds, requirements, screenshots) and the badges the frames draw.
 *
 * @return list<array<string, mixed>>
 */
function pl_plugin_cards(): array
{
    $cards = [];
    foreach (pl_module_registry() as $id => $manifest) {
        $problem = '';
        try {
            pl_module_installed($manifest);
        } catch (DomainException $error) {
            $problem = $error->getMessage();
        }
        $cards[] = [
            'kind' => 'module', 'slug' => $id, 'name' => $manifest['name'], 'version' => $manifest['version'],
            'description' => '', 'author' => 'PHP Ledger', 'licence' => 'AGPL-3.0-or-later', 'homepage' => '',
            'adds' => [], 'screenshots' => [], 'requires' => $manifest['requires'],
            'trust' => 'verified', 'status' => $manifest['optional'] ? 'optional' : 'required',
            'problem' => $problem, 'last_error' => '', 'revision' => 0, 'history' => $manifest['history'],
        ];
    }
    foreach (pl_plugin_records() as $slug => $record) {
        /** @var array<string, mixed> $manifest */
        $manifest = $record['manifest'];
        $problem = '';
        try {
            $digest = pl_plugin_files_digest($slug, pl_plugin_manifest_validate($manifest, $slug));
            if (!hash_equals((string) $record['files_digest'], $digest)) {
                $problem = 'This package\'s files no longer match the version that was installed.';
            }
        } catch (Throwable $error) {
            $problem = $error instanceof DomainException ? $error->getMessage() : 'This package could not be read from the package directory.';
        }
        $cards[] = [
            'kind' => 'plugin', 'slug' => $slug, 'name' => (string) $record['name'], 'version' => (string) $record['version'],
            'description' => (string) ($manifest['description'] ?? ''), 'author' => (string) ($manifest['author'] ?? ''),
            'licence' => (string) ($manifest['licence'] ?? ''), 'homepage' => (string) ($manifest['homepage'] ?? ''),
            'adds' => (array) ($manifest['adds'] ?? []), 'screenshots' => (array) ($manifest['screenshots'] ?? []),
            'requires' => (array) ($manifest['requires'] ?? []),
            'trust' => (string) $record['trust'], 'status' => (string) $record['status'],
            'problem' => $problem, 'last_error' => (string) $record['last_error'], 'revision' => (int) $record['revision'],
            'history' => '',
        ];
    }
    return $cards;
}

/**
 * The workspace navigation after every active package has had its say (`navigation.groups`).
 *
 * The sidebar is a hint and never a gate — each screen repeats its own capability check — so a
 * package adding an entry here grants nobody anything. What this does police is the shape: an
 * entry that is not [path, label, icon, views, visible] is dropped rather than rendered, because
 * a malformed one would otherwise break the whole sidebar for a mistake in somebody's plugin.
 *
 * @param array<string, list<array<int, mixed>>> $groups
 * @param array<string, mixed> $context
 * @return array<string, list<array{0:string,1:string,2:string,3:list<string>,4:bool}>>
 */
function pl_plugin_navigation_groups(array $groups, array $context): array
{
    if (pl_hook_callbacks('navigation.groups') === []) {
        /** @var array<string, list<array{0:string,1:string,2:string,3:list<string>,4:bool}>> $groups */
        return $groups;
    }
    try {
        /** @var mixed $filtered */
        $filtered = pl_apply_filters('navigation.groups', $groups, [$context]);
    } catch (Throwable $error) {
        error_log('PHP Ledger navigation filter failed (' . get_class($error) . ').');
        /** @var array<string, list<array{0:string,1:string,2:string,3:list<string>,4:bool}>> $groups */
        return $groups;
    }
    if (!is_array($filtered)) {
        /** @var array<string, list<array{0:string,1:string,2:string,3:list<string>,4:bool}>> $groups */
        return $groups;
    }
    $clean = [];
    foreach ($filtered as $group => $items) {
        if (!is_string($group) || mb_strlen($group, 'UTF-8') > 60 || !is_array($items)) {
            continue;
        }
        $entries = [];
        foreach ($items as $item) {
            if (!is_array($item) || !is_string($item[0] ?? null) || !is_string($item[1] ?? null) || !is_string($item[2] ?? null)
                || !is_array($item[3] ?? null) || !array_is_list($item[3])) {
                continue;
            }
            if (!preg_match('#^/[A-Za-z0-9][A-Za-z0-9/_?=&.-]{0,120}$#D', $item[0]) || mb_strlen($item[1], 'UTF-8') > 60) {
                continue;
            }
            $views = [];
            foreach ($item[3] as $view) {
                if (is_string($view) && preg_match('/^[a-z0-9-]{1,60}$/D', $view)) {
                    $views[] = $view;
                }
            }
            $entries[] = [$item[0], $item[1], $item[2], $views, (bool) ($item[4] ?? false)];
        }
        if ($entries !== []) {
            $clean[$group] = $entries;
        }
    }
    // A package that filtered the whole sidebar away has made a mistake, not a decision.
    /** @var array<string, list<array{0:string,1:string,2:string,3:list<string>,4:bool}>> $groups */
    return $clean === [] ? $groups : $clean;
}
