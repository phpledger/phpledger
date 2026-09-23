<?php
declare(strict_types=1);

/** Resolve host-controlled private paths before using installer/update state. */
function pl_install_private_path(string $path): string
{
    $path = str_replace('\\', '/', $path);
    if ($path === '' || (!str_starts_with($path, '/') && !preg_match('~^[A-Za-z]:/~D', $path))
        || in_array('..', explode('/', $path), true) || str_contains($path, "\0")) {
        throw new DomainException('The installation storage path must be an absolute private path.');
    }
    $existing = $path;
    $tail = [];
    while (!file_exists($existing)) {
        $parent = dirname($existing);
        if ($parent === $existing) {
            throw new DomainException('The installation storage parent is unavailable.');
        }
        array_unshift($tail, basename($existing));
        $existing = $parent;
    }
    $resolved = realpath($existing);
    $public = realpath(dirname(__DIR__, 2) . '/public');
    if ($resolved === false || $public === false) {
        throw new DomainException('The installation storage path could not be verified.');
    }
    $resolved = rtrim(str_replace('\\', '/', $resolved), '/') . ($tail === [] ? '' : '/' . implode('/', $tail));
    $public = rtrim(str_replace('\\', '/', $public), '/');
    if (strtolower($resolved) === strtolower($public) || str_starts_with(strtolower($resolved), strtolower($public) . '/')) {
        throw new DomainException('Installation storage must be outside the public document root.');
    }
    return $resolved;
}

function pl_install_directory(bool $create = false): string
{
    $path = pl_install_private_path((string) (getenv('PL_INSTALL_DIRECTORY') ?: dirname(__DIR__, 2) . '/storage/installation'));
    if ($create && !is_dir($path)) {
        $mask = umask(0077);
        try {
            if (!mkdir($path, 0700, true) && !is_dir($path)) {
                throw new DomainException('Create a private writable installation directory in your hosting panel.');
            }
        } finally {
            umask($mask);
        }
    }
    return $path;
}

function pl_install_config_path(): string
{
    return pl_install_private_path((string) (getenv('PL_INSTALL_CONFIG_PATH') ?: dirname(__DIR__) . '/config.local.php'));
}

/** The caller holds its operation lock. Files never contain raw diagnostic errors. */
function pl_install_write_private(string $path, string $contents, bool $replace = true): void
{
    $path = pl_install_private_path($path);
    if (!$replace && file_exists($path)) {
        throw new DomainException('An existing private file was preserved.');
    }
    $temporary = $path . '.' . bin2hex(random_bytes(12)) . '.tmp';
    $mask = umask(0077);
    try {
        $handle = @fopen($temporary, 'xb');
        if ($handle === false) {
            throw new DomainException('Private installation storage is not writable. Check its hosting permissions.');
        }
        try {
            if (fwrite($handle, $contents) !== strlen($contents) || !fflush($handle)) {
                throw new RuntimeException('Private installation state could not be saved.');
            }
        } finally {
            fclose($handle);
        }
        @chmod($temporary, 0600);
        if ($replace) {
            $published = @rename($temporary, $path);
        } else {
            // Hard-link publication is atomic and cannot replace an operator-created file.
            $published = function_exists('link') && @link($temporary, $path);
            if (!$published && !file_exists($path)) {
                // Some shared hosts disable link(); exclusive creation keeps the no-replace guarantee.
                $published = pl_install_create_exclusive($path, $contents);
            }
        }
        if (!$published) {
            throw new DomainException('Private installation state could not be published. The existing file was preserved.');
        }
    } finally {
        if (is_file($temporary)) {
            @unlink($temporary);
        }
        umask($mask);
    }
}

/** Create a new private file, failing if any file already exists at the path. */
function pl_install_create_exclusive(string $path, string $contents): bool
{
    $handle = @fopen($path, 'xb');
    if ($handle === false) {
        return false;
    }
    $written = false;
    try {
        $written = fwrite($handle, $contents) === strlen($contents) && fflush($handle);
    } finally {
        fclose($handle);
        if (!$written) {
            @unlink($path);
        }
    }
    @chmod($path, 0600);
    return $written;
}

/** @return array<string, mixed> */
function pl_install_read_state(string $name = 'setup.json'): array
{
    if (!in_array($name, ['setup.json', 'installed.json', 'attempts.json', 'notice.json'], true)) {
        throw new InvalidArgumentException('Unknown installation state.');
    }
    $path = pl_install_directory() . '/' . $name;
    if (!is_file($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    $state = $raw === false ? null : json_decode($raw, true);
    if (!is_array($state)) {
        throw new DomainException('Private installation state needs operator review.');
    }
    return $state;
}

/** @param array<string, mixed> $state */
function pl_install_save_state(array $state, string $name = 'setup.json'): void
{
    if (!in_array($name, ['setup.json', 'installed.json', 'attempts.json', 'notice.json'], true)) {
        throw new InvalidArgumentException('Unknown installation state.');
    }
    pl_install_write_private(pl_install_directory(true) . '/' . $name, json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n");
}

/** @return resource */
function pl_install_operation_lock(string $name = 'install.lock')
{
    if (!in_array($name, ['install.lock', 'application.lock', 'notice.lock'], true)) {
        throw new InvalidArgumentException('Unknown installation lock.');
    }
    $mask = umask(0077);
    try {
        $handle = @fopen(pl_install_directory(true) . '/' . $name, 'c+b');
    } finally {
        umask($mask);
    }
    if ($handle === false) {
        throw new DomainException('Private installation storage is not writable.');
    }
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        throw new DomainException('Another installation or application operation is running. Try again after it finishes.');
    }
    return $handle;
}
