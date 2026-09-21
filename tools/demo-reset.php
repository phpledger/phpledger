<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('PL_ENV') !== 'demo' || getenv('PL_DB_NAME') !== 'phpledger_demo' || getenv('PL_DEMO_RESET_MODE') !== '1') {
    fwrite(STDERR, "Demo reset requires CLI, PL_ENV=demo, PL_DB_NAME=phpledger_demo and scheduler-only PL_DEMO_RESET_MODE=1 credentials.\n");
    exit(2);
}
try {
    require dirname(__DIR__) . '/www/phpledger/includes/bootstrap.php';
    require dirname(__DIR__) . '/www/phpledger/install/migrate.php';
    // Bootstrap holds the same advisory lock as every demo HTTP request, on information_schema.
    $exists = (int) DB::queryFirstField("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'phpledger_demo'") === 1;
    if ($exists) {
        $tables = (int) DB::queryFirstField("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'phpledger_demo'");
        if ($tables > 0) {
            $marker = (int) DB::queryFirstField("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'phpledger_demo' AND TABLE_NAME = 'pl_demo_state'");
            if ($marker !== 1) {
                throw new DomainException('Refusing reset: the database contains records without a valid demo marker. Inspect it manually.');
            }
            $state = DB::queryFirstRow('SELECT * FROM phpledger_demo.pl_demo_state WHERE id = 1');
            if (!$state || !preg_match('/^[a-f0-9]{64}$/D', $state['generation'])
                || (int) DB::queryFirstField('SELECT COUNT(*) FROM phpledger_demo.pl_companies WHERE is_sample <> 1') !== 0
                // Every account here is either a visitor, or a person a visitor's own sample
                // created inside it: 1.2.0 samples seed a second person so the capability
                // system is visible, and that person belongs to nothing else. Anyone with no
                // membership at all, or with one company that is not an isolated visitor's
                // sample, is still a stranger and still stops the reset.
                || (int) DB::queryFirstField(
                    'SELECT COUNT(*) FROM phpledger_demo.pl_users u
                     LEFT JOIN phpledger_demo.pl_demo_visitors v ON v.user_id = u.id
                     WHERE v.user_id IS NULL AND (
                       (SELECT COUNT(*) FROM phpledger_demo.pl_company_members m WHERE m.user_id = u.id) = 0
                       OR (SELECT COUNT(*) FROM phpledger_demo.pl_company_members m2
                           LEFT JOIN phpledger_demo.pl_companies c ON c.id = m2.company_id AND c.is_sample = 1
                           LEFT JOIN phpledger_demo.pl_demo_visitors d ON d.company_id = m2.company_id
                           WHERE m2.user_id = u.id AND (c.id IS NULL OR d.company_id IS NULL)) > 0
                     )') !== 0) {
                throw new DomainException('Refusing reset: every user and company must be an explicitly isolated demo visitor, or a person one of their samples created inside it.');
            }
            if (!in_array('--now', $_SERVER['argv'] ?? [], true) && strtotime($state['next_reset_at'] . ' UTC') > time()) {
                echo "Demo reset is not due; no records changed.\n";
                exit(0);
            }
        }
    }
    // Literal target only: never substitute an environment-derived database identifier here.
    DB::query('DROP DATABASE IF EXISTS phpledger_demo');
    DB::query('CREATE DATABASE phpledger_demo CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
    DB::useDB('phpledger_demo');
    pl_migrate();
    $now = time();
    DB::insert('pl_demo_state', ['id' => 1, 'generation' => bin2hex(random_bytes(32)), 'reset_at' => gmdate('Y-m-d H:i:s', $now), 'next_reset_at' => gmdate('Y-m-d H:i:s', (intdiv($now, 3600) + 1) * 3600)]);
    echo "Isolated demo refreshed. All prior visitor sessions are invalid; the next automatic refresh is at the next UTC hour.\n";
} catch (Throwable $error) {
    fwrite(STDERR, ($error instanceof DomainException ? $error->getMessage() : 'Demo reset failed. Inspect the isolated demo database and migration receipts; do not bypass its safety checks.') . "\n");
    exit(1);
}
