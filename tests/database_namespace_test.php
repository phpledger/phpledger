<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/www/phpledger/includes/functions/update_database_functions.php';

test('namespace lexer preserves SQL literals, comments and identifiers', function (): void {
    $old = $GLOBALS['pl_database_prefix'];
    try {
        $GLOBALS['pl_database_prefix'] = 'alpha_';
        assert_same("SELECT 'pl_users', \"pl_users\", `alpha_users` FROM alpha_users /* pl_users */ -- pl_users\nWHERE note = ?", pl_database_namespace_sql("SELECT 'pl_users', \"pl_users\", `pl_users` FROM pl_users /* pl_users */ -- pl_users\nWHERE note = ?"));
        assert_same('CREATE TABLE alpha_x (CONSTRAINT plns_' . substr(hash('sha256', 'alpha_'), 0, 16) . '_fk_x FOREIGN KEY (id) REFERENCES alpha_users(id))', pl_database_namespace_sql('CREATE TABLE pl_x (CONSTRAINT fk_x FOREIGN KEY (id) REFERENCES pl_users(id))'));
        assert_same('SELECT * FROM `alpha_users`', pl_database_namespace_sql('SELECT * FROM {{users}}'));
        assert_same('alpha_users', PL_User::_tablename());
        assert_throws(fn() => pl_database_prefix('Bad_'), InvalidArgumentException::class);
    } finally { $GLOBALS['pl_database_prefix'] = $old; }
});

test('TLS configuration fails closed for invalid input and namespace mismatch', function (): void {
    foreach (['pl_fiscal_','pl_accounts_','pl_custom_'] as $prefix) { assert_throws(fn()=>pl_database_prefix($prefix),InvalidArgumentException::class,'reserved'); }
    assert_same('pl_',pl_database_prefix('pl_'));
    assert_throws(fn() => pl_database_configuration(['db_ssl_ca' => '/missing-ca-file']), InvalidArgumentException::class);
    assert_throws(fn() => pl_database_configuration(['db_ssl_cert' => '/missing-cert-file']), InvalidArgumentException::class);
    assert_throws(fn() => pl_database_configuration(['db_ssl_verify' => 'perhaps']), InvalidArgumentException::class);
    assert_same(true, pl_database_configuration([])['db_ssl_verify']);
    assert_same(false, pl_database_configuration(['db_ssl_verify'=>false])['db_ssl_verify']);
    assert_true(pl_database_platform('8.0.19')['supported']);
    assert_true(!pl_database_platform('8.0.18')['supported']);
});

test('two namespaces install and recover independently in one database', function (): void {
    $base = ['host' => getenv('PL_DB_HOST'), 'port' => 3306, 'database' => getenv('PL_DB_NAME'), 'user' => getenv('PL_DB_USER'), 'password' => getenv('PL_DB_PASSWORD')];
    $connect = static function (string $prefix) use ($base): void { DB::disconnect(); pl_database_configure($base + ['db_prefix' => $prefix]); DB::query("SET time_zone = '+00:00'"); };
    $alphaPrefix = 'na' . bin2hex(random_bytes(3)) . '_';
    $betaPrefix = 'nb' . bin2hex(random_bytes(3)) . '_';
    $snapshot = sys_get_temp_dir() . '/pl-prefix-' . bin2hex(random_bytes(5));
    mkdir($snapshot, 0700);
    try {
        $connect($alphaPrefix); pl_migrate();
        DB::insert('pl_users', ['email' => 'alpha@example.test', 'display_name' => 'pl_users literal', 'password_hash' => 'test-fixture-only']);
        $alpha = (int) DB::insertId();
        assert_same('pl_users literal', DB::queryFirstField('SELECT display_name FROM pl_users WHERE id=%i', $alpha));
        assert_same('pl_users literal', PL_User::load($alpha)->get('display_name'));
        $company = pl_create_company($alpha, 'Namespace accounting fixture', 'USD', '2026-01-01');
        $journal = pl_post_journal($alpha, $company['company_id'], $company['book_id'], [
            'date'=>'2026-01-02','currency'=>'USD','source_type'=>'receipt','source_reference'=>'pl_users',
            'description'=>'Namespace exact-money proof','idempotency_key'=>'namespace-posting',
            'lines'=>[['account_id'=>$company['accounts']['1000'],'debit'=>'12.3400','credit'=>'0'],
                ['account_id'=>$company['accounts']['4000'],'debit'=>'0','credit'=>'12.3400']]]);
        assert_same('12.3400', DB::queryFirstField('SELECT CAST(SUM(debit) AS CHAR) FROM pl_journal_lines'));
        assert_throws(fn() => DB::update('pl_journals', ['description'=>'Forbidden mutation'], 'id=%i', $journal['id']), MeekroDBException::class);
        assert_throws(fn() => DB::delete('pl_journal_lines', 'journal_id=%i', $journal['id']), MeekroDBException::class);

        $connect($betaPrefix); pl_migrate();
        DB::insert('pl_users', ['email' => 'beta@example.test', 'display_name' => 'Neighbor remains', 'password_hash' => 'test-fixture-only']);
        assert_same(1, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_users'));
        $neighbor = hash('sha256', json_encode(DB::query('SELECT * FROM pl_users'), JSON_THROW_ON_ERROR));
        $connect($alphaPrefix);
        DB::query('CREATE VIEW pl_cross_namespace AS SELECT id FROM %b', $betaPrefix . 'users');
        assert_throws(fn() => pl_update_database_inventory(), DomainException::class, 'cross-namespace');
        DB::query('DROP VIEW pl_cross_namespace');
        DB::query('CREATE TABLE pl_cross_namespace (id BIGINT UNSIGNED PRIMARY KEY, FOREIGN KEY(id) REFERENCES %b(id)) ENGINE=InnoDB', $betaPrefix . 'users');
        assert_throws(fn() => pl_update_database_inventory(), DomainException::class, 'cross-namespace');
        DB::query('DROP TABLE pl_cross_namespace');
        $receipt = null;
        for ($i=0; $i<1000 && $receipt===null; $i++) { $receipt = pl_update_database_backup($snapshot); }
        assert_true(is_array($receipt), 'Snapshot did not finish');
        DB::update('pl_users', ['display_name' => 'Changed after snapshot'], 'id=%i', $alpha);
        $finished = false;
        for ($i=0; $i<2000 && !$finished; $i++) { $finished = pl_update_database_restore($snapshot, $receipt); }
        assert_true($finished, 'Restore did not finish');
        assert_same('pl_users literal', DB::queryFirstField('SELECT display_name FROM pl_users WHERE id=%i', $alpha));
        assert_throws(fn() => DB::update('pl_journals', ['description'=>'Forbidden after recovery'], 'id=%i', $journal['id']), MeekroDBException::class);
        assert_same($alphaPrefix, pl_update_json($snapshot . '/manifest.json')['db_prefix']);
        $connect($betaPrefix);
        assert_same($neighbor, hash('sha256', json_encode(DB::query('SELECT * FROM pl_users'), JSON_THROW_ON_ERROR)));
        assert_throws(fn() => pl_update_database_restore($snapshot, $receipt), RuntimeException::class, 'namespace');
        assert_same('current', pl_install_database_check()['status']);
    } finally {
        foreach ([$alphaPrefix, $betaPrefix] as $prefix) {
            $connect($prefix); $objects = pl_update_database_inventory(false);
            DB::query('SET FOREIGN_KEY_CHECKS=0');
            foreach ($objects['views'] as $view) { DB::query('DROP VIEW %b', $view); }
            foreach ($objects['tables'] as $table) { DB::query('DROP TABLE %b', $table); }
            DB::query('SET FOREIGN_KEY_CHECKS=1');
        }
        $connect('pl_');
    }
});


test('installer identity, durable marker and shared demo retain their namespace', function (): void {
    require_once dirname(__DIR__) . '/www/phpledger/includes/functions/install_web_functions.php';
    $base = ['host'=>'db_test','port'=>3306,'database'=>'phpledger_test','user'=>'ledger_test','password'=>'local-test-only'];
    assert_true(pl_install_database_identity($base + ['db_prefix'=>'first_']) !== pl_install_database_identity($base + ['db_prefix'=>'second_']));
    $directory = sys_get_temp_dir() . '/pl-namespace-binding-' . bin2hex(random_bytes(5)); mkdir($directory, 0700);
    file_put_contents($directory . '/installed.json', json_encode(['db_prefix'=>'bound_'], JSON_THROW_ON_ERROR));
    try {
        assert_throws(fn() => pl_database_configure($base + ['db_prefix'=>'other_', 'installation_directory'=>$directory]), DomainException::class, 'bound');
        file_put_contents($directory . '/installed.json', '{}');
        assert_throws(fn() => pl_database_configure($base + ['db_prefix'=>'other_', 'installation_directory'=>$directory]), DomainException::class, 'bound');
    } finally { unlink($directory . '/installed.json'); rmdir($directory); }
    $environment = getenv('PL_ENV');
    $database = getenv('PL_DB_NAME');
    try {
        putenv('PL_ENV=demo-install');
        assert_throws(fn() => pl_install_database_input([]), DomainException::class, 'not configured');
        putenv('PL_DB_NAME=phpledger_demo');
        $demo = pl_install_database_input(['host'=>'attacker.invalid','database'=>'other','db_prefix'=>'other_','db_ssl_ca'=>'/missing','password'=>'ignored']);
        assert_same('pl_', $demo['db_prefix']);
        assert_same(getenv('PL_DB_HOST'), $demo['host']);
    } finally { putenv('PL_ENV=' . $environment); putenv('PL_DB_NAME=' . $database); }
});
