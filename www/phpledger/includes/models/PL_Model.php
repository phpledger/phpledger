<?php
declare(strict_types=1);

/**
 * The base for PHP Ledger's first MeekroORM records (release plan 1.2, milestone M7; B12).
 *
 * NO TABLE PREFIX, DELIBERATELY. A configurable table prefix is 1.3 work. Every model names its
 * table literally, exactly as the rest of the application does, and `_tablename()` consults no
 * setting. When the prefix lands, it is applied in ONE place — this class's `_tablename()` — and
 * every model inherits it. Until then `$_tablename` is always explicit, because a model whose
 * table name was inferred from its class name would be the one place that silently disagreed with
 * the prefix later.
 *
 * WHAT IT COSTS, MEASURED, NOT ASSUMED. MeekroORM discovers a model's columns by asking the
 * server: MeekroORMTable::table_struct() calls MeekroDB::columnList(), which on MySQL and MariaDB
 * is `SHOW COLUMNS FROM <table>`. That happens the first time a model class is used in a request.
 * It is cached for the rest of that request (MeekroORM::$_orm_struct, keyed by table name) and
 * NOT between requests: PHP Ledger targets shared hosting, where there is no persistent process
 * to cache it in and no opcode-cached array to read it from. So each model costs one extra
 * round trip on any request that touches it — three on Admin > Users, zero anywhere else.
 *
 * That is the finding the milestone asked for, and it is why the models are used ONLY on the
 * administration screens, where a page already runs dozens of queries and three more are
 * invisible. Nothing on a hot path — sign-in, pl_user_can(), pl_require_company_access(),
 * posting, reports, the read API — goes through them; those stay on plain MeekroDB. Both are
 * MeekroDB on one connection (AGENTS.md: no second database layer); the ORM is a convenience over
 * it, not a second stack.
 *
 * pl_model_metadata_probe() makes the cost measurable rather than remembered, and
 * tests/users_test.php uses it to assert the hot paths issue no metadata query at all.
 */
abstract class PL_Model extends MeekroORM
{
    /**
     * Resolve the table for a model. 1.2 has no table prefix; when 1.3 adds one, it is applied
     * here and nowhere else.
     */
    public static function _tablename()
    {
        $table = static::$_tablename;
        if (!is_string($table) || $table === '') {
            throw new MeekroORMException(static::class . ' must name its table explicitly: PHP Ledger does not infer table names.');
        }
        return $table;
    }

    /** The record as a plain array, for a template, a JSON read or an audit snapshot. */
    public function toArray(): array
    {
        $row = [];
        foreach (array_keys(DB::columnList(static::_tablename())) as $column) {
            $name = (string) $column;
            $row[$name] = $this->has($name) ? $this->get($name) : null;
        }
        return $row;
    }
}

/**
 * Run $work and return how many schema-metadata round trips it caused.
 *
 * This counts the real queries, through MeekroDB's own pre_run hook, rather than trusting a
 * counter the application increments: the point is to measure what the ORM does on its own.
 */
function pl_model_metadata_probe(callable $work): int
{
    $count = 0;
    $hook = DB::addHook('pre_run', static function (array $run) use (&$count): ?string {
        $query = ltrim((string) ($run['query'] ?? ''));
        if (stripos($query, 'SHOW COLUMNS') === 0 || stripos($query, 'DESCRIBE') === 0
            || stripos($query, 'SHOW FULL COLUMNS') === 0) {
            $count++;
        }
        return null;
    });
    try {
        $work();
    } finally {
        DB::removeHook('pre_run', $hook);
    }
    return $count;
}
