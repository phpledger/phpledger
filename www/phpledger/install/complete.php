<?php
declare(strict_types=1);

// Marks a non-interactive installation complete: the CLI equivalent of the browser
// installer's own completion receipt (pl_install_create_account() in
// install_web_functions.php), for a deployment that configures its database and
// creates its administrator entirely from the command line instead of through /install.
// Used by the container image's entrypoint after install/migrate.php and
// install/create-admin.php succeed; equally usable by any other scripted ZIP or
// Composer deployment. Never accepts credentials; it only records that installation
// finished, the same fact pl_web_needs_installation() reads everywhere else.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['owner:']);
if (!is_array($options) || (isset($options['owner']) && !is_string($options['owner']))) {
    fwrite(STDERR, "Usage: php install/complete.php [--owner=<user id>]\n");
    fwrite(STDERR, "Without --owner, the oldest active administrator account is used.\n");
    exit(1);
}

try {
    require_once __DIR__ . '/preflight.php';
    pl_install_require_runtime();
    try {
        require dirname(__DIR__) . '/includes/bootstrap.php';
    } catch (Throwable $error) {
        throw new RuntimeException('Application configuration could not be loaded.');
    }
    if (pl_install_database_check()['status'] !== 'current') {
        throw new DomainException('Run the package migrations (install/migrate.php) before completing installation.');
    }
    if (is_file(pl_install_directory() . '/installed.json')) {
        fwrite(STDOUT, "Installation was already marked complete.\n");
        exit(0);
    }
    if (isset($options['owner'])) {
        if (!ctype_digit($options['owner'])) {
            throw new InvalidArgumentException('--owner must be a numeric user id.');
        }
        $ownerId = (int) $options['owner'];
        if (!DB::queryFirstRow('SELECT id FROM pl_users WHERE id = %i AND is_active = 1', $ownerId)) {
            throw new DomainException('The named owner account does not exist or is inactive.');
        }
    } else {
        $ownerId = (int) DB::queryFirstField('SELECT id FROM pl_users WHERE is_active = 1 ORDER BY id ASC LIMIT 1');
        if ($ownerId === 0) {
            throw new DomainException('Create the administrator account first (install/create-admin.php).');
        }
    }
    $operatorKey = pl_install_directory() . '/operator.key';
    if (!is_file($operatorKey)) {
        pl_install_write_private($operatorKey, bin2hex(random_bytes(32)) . "\n", false);
    }
    $receipt = [
        'format' => 1,
        'db_prefix' => pl_database_prefix(),
        'database_id' => hash('sha256', json_encode([DB::$host, (int) DB::$port, DB::$dbName, pl_database_prefix()], JSON_THROW_ON_ERROR)),
        'initial_owner_id' => $ownerId,
        'completed_at' => gmdate('c'),
        'schema_receipts' => (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_schema_migrations'),
    ];
    pl_install_save_state($receipt, 'installed.json');
    require_once dirname(__DIR__).'/includes/functions/installation_notice_functions.php';
    // Scripted hosts can opt out with PL_INSTALL_NOTICE=0. Named registration is
    // never inferred from the owner account or environment.
    pl_install_notice_after_setup(['installation_notice'=>getenv('PL_INSTALL_NOTICE')==='0'?'0':'1']);
    fwrite(STDOUT, "Installation marked complete (owner user {$ownerId}).\n");
} catch (InvalidArgumentException | DomainException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
} catch (Throwable $error) {
    fwrite(STDERR, "Could not mark installation complete. Check database setup and that an active administrator account exists.\n");
    exit(1);
}
