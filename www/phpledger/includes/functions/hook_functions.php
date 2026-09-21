<?php
declare(strict_types=1);

/**
 * The hook mechanism (release plan 1.2 M8, decision B45 and plan decision 15).
 *
 * Actions and filters in the WordPress style, with priorities and a per-request registry, so a
 * plugin may change default core behaviour rather than only observe it. Three rules separate this
 * from WordPress, and all three exist because a hook here can run inside an accounting
 * transaction:
 *
 *  1. **No hook runs inside a locked section.** `pl_ledger_book($company, $book, true)` takes the
 *     book row FOR UPDATE; from that moment until the outermost transaction ends,
 *     pl_do_action() and pl_apply_filters() throw a LogicException rather than call anybody.
 *     The guard is a runtime check, not a convention, and `plugin_test.php` proves it fires.
 *  2. **Filters run in the validation phase**, before the book row is locked, and may veto or
 *     alter the draft. A filter that throws aborts the posting before the lock is taken, so a
 *     failing plugin cannot leave a half-posted entry and cannot hold the book against other
 *     writers while its own code runs.
 *  3. **Actions that report a completed change run after the commit.** pl_hook_after_commit()
 *     queues them; pl_ledger_transaction() flushes the queue once the outermost transaction has
 *     committed, outside every lock. A queued action that throws is recorded against its plugin
 *     and swallowed: the journal is already posted, and a plugin cannot un-post it.
 *
 * A plugin's callbacks are registered under its slug as the owner, so deactivating it removes
 * them in one call. Core's own registrations use the owner 'core'.
 */

/** A plugin refusing an operation on purpose. Its message is shown to the person. */
class PL_Hook_Veto extends DomainException
{
}

/**
 * The published extension-point contract version (B80). The contract document itself is not in
 * this repository; `tests/plugin_surface_test.php` records the exported surface against this
 * number and fails when the surface moves without it. A plugin manifest names the version it was
 * written against and must match exactly (B47).
 */
const PL_PLUGIN_API_VERSION = '1.0.0';

/** The contract number a plugin package manifest must declare. Bundled modules are contract 1. */
const PL_PLUGIN_CONTRACT = 2;

/**
 * The extension points core actually fires, with the kind and the argument shape of each. This is
 * the list `tests/plugin_surface_test.php` snapshots, and every entry below is fired by code in
 * this tree; nothing is declared here that core does not raise.
 *
 * @return array<string, array{kind:string, args:list<string>, returns:string, phase:string}>
 */
function pl_hook_points(): array
{
    return [
        // Validation phase of the central posting service, before the book row is locked.
        // The value is the normalized journal payload; core re-normalizes whatever comes back,
        // so a filter cannot smuggle an unbalanced or malformed journal past the core rules.
        'journal.validate' => ['kind' => 'filter', 'args' => ['array payload', 'array context'], 'returns' => 'array', 'phase' => 'pre-lock'],
        // After the outermost commit. The journal is posted and immutable by the time this runs.
        'journal.posted' => ['kind' => 'action', 'args' => ['array journal', 'array context'], 'returns' => 'void', 'phase' => 'after-commit'],
        // After the outermost commit of a period close.
        'period.closed' => ['kind' => 'action', 'args' => ['array period', 'array context'], 'returns' => 'void', 'phase' => 'after-commit'],
        // The workspace navigation groups, so a plugin can add its own screens to the sidebar.
        'navigation.groups' => ['kind' => 'filter', 'args' => ['array groups', 'array context'], 'returns' => 'array', 'phase' => 'request'],
        // B77 and B78: the registration point for a channel plugin. The value is the handler array
        // pl_dispatch_outbound_events() takes, keyed by consumer name. There is one outbound
        // queue and this is how a plugin joins it; no plugin builds a second one.
        'outbound.consumers' => ['kind' => 'filter', 'args' => ['array handlers', 'array context'], 'returns' => 'array', 'phase' => 'cli'],
        // Package lifecycle, after the outermost commit of the lifecycle change.
        'plugin.activated' => ['kind' => 'action', 'args' => ['string slug', 'array package'], 'returns' => 'void', 'phase' => 'after-commit'],
        'plugin.deactivated' => ['kind' => 'action', 'args' => ['string slug', 'array package'], 'returns' => 'void', 'phase' => 'after-commit'],
    ];
}

function pl_hook_name_valid(string $hook): bool
{
    return (bool) preg_match('/^[a-z][a-z0-9_]{0,39}(?:\.[a-z][a-z0-9_]{0,39}){0,3}$/D', $hook);
}

/**
 * The per-request registry. Callbacks are held as
 * [hook][] => ['kind'=>..,'priority'=>..,'sequence'=>..,'owner'=>..,'callback'=>..].
 *
 * @return array<string, list<array{kind:string, priority:int, sequence:int, owner:string, callback:callable}>>
 */
function &pl_hook_registry(): array
{
    static $registry = [];
    return $registry;
}

function pl_hook_register(string $kind, string $hook, callable $callback, int $priority, string $owner): void
{
    if (!pl_hook_name_valid($hook)) {
        throw new DomainException('A hook name is lower-case words separated by dots.');
    }
    if ($priority < 0 || $priority > 1000) {
        throw new DomainException('A hook priority is between 0 and 1000.');
    }
    if (!preg_match('/^[a-z][a-z0-9-]{0,59}$/D', $owner)) {
        throw new DomainException('A hook owner is a package slug or "core".');
    }
    $known = pl_hook_points();
    if (isset($known[$hook]) && $known[$hook]['kind'] !== $kind) {
        throw new DomainException('This extension point is a ' . $known[$hook]['kind'] . ', not a ' . $kind . '.');
    }
    static $sequence = 0;
    $registry = &pl_hook_registry();
    $registry[$hook][] = ['kind' => $kind, 'priority' => $priority, 'sequence' => $sequence++, 'owner' => $owner, 'callback' => $callback];
}

/** Register an action. Lower priorities run first; equal priorities keep registration order. */
function pl_add_action(string $hook, callable $callback, int $priority = 10, string $owner = 'core'): void
{
    pl_hook_register('action', $hook, $callback, $priority, $owner);
}

/** Register a filter. The callback receives the value first, then the hook's arguments. */
function pl_add_filter(string $hook, callable $callback, int $priority = 10, string $owner = 'core'): void
{
    pl_hook_register('filter', $hook, $callback, $priority, $owner);
}

/** Drop every callback an owner registered. Called when a plugin is deactivated. */
function pl_remove_hooks(string $owner): int
{
    $registry = &pl_hook_registry();
    $removed = 0;
    foreach ($registry as $hook => $entries) {
        $kept = array_values(array_filter($entries, static fn (array $entry): bool => $entry['owner'] !== $owner));
        $removed += count($entries) - count($kept);
        if ($kept === []) {
            unset($registry[$hook]);
        } else {
            $registry[$hook] = $kept;
        }
    }
    return $removed;
}

/** Forget every registration and every queued action. Tests and the CLI worker use this. */
function pl_hook_reset(): void
{
    $registry = &pl_hook_registry();
    $registry = [];
    pl_hook_queue(true);
    pl_hook_failures(true);
    pl_hook_lock_reset();
}

/** @return list<array{kind:string, priority:int, sequence:int, owner:string, callback:callable}> */
function pl_hook_callbacks(string $hook): array
{
    $registry = pl_hook_registry();
    $entries = $registry[$hook] ?? [];
    usort($entries, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority'] ?: $a['sequence'] <=> $b['sequence']);
    return $entries;
}

// ---------------------------------------------------------------- the locked-section guard
//
// pl_ledger_book(company, book, true) takes the book row FOR UPDATE. Every posting path holds
// that row for the rest of its transaction, so from the moment it is taken until the outermost
// transaction ends, no plugin code may run: a plugin that blocked there would hold the book
// against every other writer, and a plugin that threw there would have to unwind a partly
// written journal. Plan decision 15 makes that a rule; this makes it a runtime check.

/** @return list<string> the "company:book" pairs locked in the current transaction */
function &pl_hook_locks(): array
{
    static $locks = [];
    return $locks;
}

/** Recorded by pl_ledger_book() the moment a book row is taken FOR UPDATE. */
function pl_hook_lock_acquired(int $companyId, int $bookId): void
{
    $locks = &pl_hook_locks();
    $key = $companyId . ':' . $bookId;
    if (!in_array($key, $locks, true)) {
        $locks[] = $key;
    }
}

function pl_hook_lock_held(): bool
{
    return pl_hook_locks() !== [];
}

/** Cleared when the outermost transaction commits or rolls back. */
function pl_hook_lock_reset(): void
{
    $locks = &pl_hook_locks();
    $locks = [];
}

function pl_hook_assert_unlocked(string $hook): void
{
    if (pl_hook_lock_held()) {
        throw new LogicException('A hook cannot run while a book row is locked (' . $hook
            . '). Raise it in the validation phase before the lock, or queue it for after the commit.');
    }
}

// -------------------------------------------------------------------------- firing hooks

/**
 * Run every action registered for this hook, in priority order.
 *
 * A throwing action aborts the caller: an action raised in the request phase is part of the work
 * the caller is doing. Actions that report an already-committed change go through
 * pl_hook_after_commit() instead, which cannot abort anything.
 *
 * @param list<mixed> $args
 */
function pl_do_action(string $hook, array $args = []): void
{
    pl_hook_assert_unlocked($hook);
    foreach (pl_hook_callbacks($hook) as $entry) {
        try {
            ($entry['callback'])(...$args);
        } catch (PL_Hook_Veto $veto) {
            throw $veto;
        } catch (Throwable $error) {
            throw pl_hook_failure($entry['owner'], $hook, $error);
        }
    }
}

/**
 * Pass a value through every filter registered for this hook, in priority order. Each filter
 * receives the running value first and the hook's arguments after it, and returns the value.
 *
 * @param list<mixed> $args
 */
function pl_apply_filters(string $hook, mixed $value, array $args = []): mixed
{
    pl_hook_assert_unlocked($hook);
    foreach (pl_hook_callbacks($hook) as $entry) {
        try {
            $value = ($entry['callback'])($value, ...$args);
        } catch (PL_Hook_Veto $veto) {
            throw $veto;
        } catch (Throwable $error) {
            throw pl_hook_failure($entry['owner'], $hook, $error);
        }
    }
    return $value;
}

/**
 * A plugin's uncaught failure becomes one refusal naming the plugin, never a raw internal error
 * on the screen. The original is kept as the previous exception for the log.
 */
function pl_hook_failure(string $owner, string $hook, Throwable $error): Throwable
{
    pl_hook_record_failure($owner, $hook, $error);
    // Core's own registrations are core code: their failures keep their own message and class,
    // exactly as they would without a hook in between. A plugin's failure becomes one refusal
    // naming the plugin, so an internal error from somebody else's code never reaches the screen.
    return $owner === 'core' ? $error : new RuntimeException('The "' . $owner
        . '" package stopped this operation at ' . $hook . '. Deactivate it in Packages and try again.', 0, $error);
}

/**
 * Failures are kept for the request so a test and a screen can see them, written to the error log
 * for the operator, and stamped on the package row so Admin > Packages shows which plugin failed
 * and when.
 */
function pl_hook_record_failure(string $owner, string $hook, Throwable $error): void
{
    $failures = &pl_hook_failures();
    $failures[] = ['owner' => $owner, 'hook' => $hook, 'error' => $error->getMessage(), 'class' => get_class($error)];
    error_log('PHP Ledger hook failure in ' . $owner . ' at ' . $hook . ' (' . get_class($error) . ').');
    if ($owner === 'core' || !function_exists('pl_plugin_record_failure')) {
        return;
    }
    // A failure raised inside a transaction that is about to roll back cannot be stamped on the
    // package row, because the stamp rolls back with it. Nothing is lost that matters: that
    // failure reaches the person who asked for the operation, as a refusal naming the package.
    // The ones that need recording are the after-commit ones, which nobody would otherwise see,
    // and those run with no transaction open.
    try {
        pl_plugin_record_failure($owner, $hook . ': ' . $error->getMessage());
    } catch (Throwable) {
        // Recording a failure must never replace the failure it is recording.
    }
}

/**
 * @return list<array{owner:string, hook:string, error:string, class:string}>
 */
function &pl_hook_failures(bool $reset = false): array
{
    static $failures = [];
    if ($reset) {
        $failures = [];
    }
    return $failures;
}

// ------------------------------------------------------------------- after-commit actions

/**
 * @return list<array{hook:string, args:list<mixed>}>
 */
function &pl_hook_queue(bool $reset = false): array
{
    static $queue = [];
    if ($reset) {
        $queue = [];
    }
    return $queue;
}

/**
 * Queue an action to run once the outermost transaction has committed. Outside a transaction it
 * runs immediately, because there is nothing to wait for.
 *
 * @param list<mixed> $args
 */
function pl_hook_after_commit(string $hook, array $args = []): void
{
    if (!pl_hook_name_valid($hook)) {
        throw new DomainException('A hook name is lower-case words separated by dots.');
    }
    if (DB::transactionDepth() < 1) {
        pl_hook_run_committed($hook, $args);
        return;
    }
    $queue = &pl_hook_queue();
    $queue[] = ['hook' => $hook, 'args' => $args];
}

/**
 * Run the queued actions. Called by pl_ledger_transaction() after the outermost commit, when
 * every lock this transaction held has been released.
 *
 * Nothing here may throw: the work these actions describe is already committed and a plugin
 * cannot be allowed to turn a completed posting into a failed request.
 */
function pl_hook_flush_committed(): void
{
    $queue = &pl_hook_queue();
    // Take the queue before running it: an action that posts something of its own would
    // otherwise append to the list being iterated.
    $pending = $queue;
    $queue = [];
    foreach ($pending as $entry) {
        pl_hook_run_committed($entry['hook'], $entry['args']);
    }
}

/** @param list<mixed> $args */
function pl_hook_run_committed(string $hook, array $args): void
{
    pl_hook_assert_unlocked($hook);
    foreach (pl_hook_callbacks($hook) as $callback) {
        try {
            ($callback['callback'])(...$args);
        } catch (Throwable $error) {
            pl_hook_record_failure($callback['owner'], $hook, $error);
        }
    }
}

/** Called when the outermost transaction rolls back: its queued actions never happened. */
function pl_hook_discard_committed(): void
{
    pl_hook_queue(true);
}

/**
 * Called by pl_ledger_transaction() when the outermost transaction ends. On a commit the queued
 * actions run; on a rollback they are dropped. Either way the lock record is cleared, because the
 * database released those rows.
 */
function pl_hook_transaction_ended(bool $committed): void
{
    pl_hook_lock_reset();
    if ($committed) {
        pl_hook_flush_committed();
        return;
    }
    pl_hook_discard_committed();
}

/**
 * The published extension-point surface (B80).
 *
 * The plugin contract document is not kept in this repository; what is kept here is the thing
 * that makes the promise checkable. This list names every function a third-party package may
 * call, and `tests/plugin_surface_test.php` records each one's parameter and return shape
 * against PL_PLUGIN_API_VERSION. Changing a signature, adding a function to this list, or
 * removing one, fails that test until the version is bumped and the snapshot is retaken. It
 * asserts nothing about wording and ships no contract text.
 *
 * @return list<string>
 */
function pl_plugin_api_functions(): array
{
    return [
        // The hook mechanism.
        'pl_add_action', 'pl_add_filter', 'pl_do_action', 'pl_apply_filters', 'pl_remove_hooks',
        'pl_hook_after_commit', 'pl_hook_points', 'pl_hook_callbacks',
        // Package identity and settings (B46).
        'pl_plugin_table_prefix', 'pl_plugin_option_get', 'pl_plugin_option_all',
        'pl_plugin_option_set', 'pl_plugin_option_delete',
        // The outbound machine and its registration point (B77, B78). These four stopped being
        // internal helpers the moment a third party could sell a connector over them.
        'pl_enqueue_outbound_event', 'pl_outbound_claim', 'pl_outbound_acknowledge',
        'pl_dispatch_outbound_events', 'pl_plugin_outbound_handlers',
        // The posting service and fixed-precision money. A package posts through these or not
        // at all; it never writes a journal row.
        'pl_ledger_transaction', 'pl_post_journal', 'pl_get_journal', 'pl_reverse_journal',
        'pl_trial_balance', 'pl_amount', 'pl_ledger_date', 'pl_ledger_text',
        // Authorisation. A package asks these; it never invents a permission check.
        'pl_require_company_access', 'pl_user_can', 'pl_require_capability', 'pl_require_module',
        // Presentation.
        'pl_t', 'pl_tn', 'pl_e', 'pl_url',
    ];
}
