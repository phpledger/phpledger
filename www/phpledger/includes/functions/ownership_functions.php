<?php
declare(strict_types=1);

require_once __DIR__ . '/legal_form_functions.php';

/**
 * The ownership register (1.2 M8a, issue #92; owner decisions B63, B64, B71, B72, B74, B76).
 *
 * Decision A3 makes real-time owner's equity the headline and B61 already posts capital, owner
 * loans and drawings through the central posting service. What no service held was the record
 * that makes the equity section complete and that company law requires a business to keep: who
 * owns it, in what proportion, through which class of share, since when, and who its officers
 * are.
 *
 * Four rules govern everything below.
 *
 * 1. **One register, never two.** B63: the core keeps the register and a country
 *    company-secretarial plugin reads it through the hook points and the read API rather than
 *    carrying its own. Nothing here stores a figure that can be derived: a share class has no
 *    issued count column, because the issued count is the share ledger summed to a date.
 *
 * 2. **The share ledger is append-only and a correction is a linked reversal**, which is the
 *    same rule posted journals already follow. Two database triggers refuse an UPDATE and a
 *    DELETE, so it is not merely a convention of this file.
 *
 * 3. **Allotments post through `pl_post_journal()` and transfers post nothing.** A transfer is a
 *    transaction between two shareholders; the company's own assets, liabilities and equity are
 *    unchanged by it, and a schema CHECK enforces that a transfer carries no journal. There is
 *    no second posting path anywhere in this file.
 *
 * 4. **Nothing about a person is inferred.** The legal form never selects an accounting
 *    framework. A share capital account is never guessed from the chart. And the related-party
 *    marker, below, defaults every person to *not* related.
 *
 * ---------------------------------------------------------------------------------------------
 * The related-party marker: B72 as corrected by B74, which is the part worth reading twice.
 *
 * B72 approved a marker on the assumption that an employee is a related party. B74 checked the
 * standard instead of practice and found that assumption wrong. IAS 24 relates a person through
 * control, joint control, significant influence, or membership of **key management personnel**,
 * which includes any director; a word-level check of the verbatim adoption finds "employee"
 * never used as a class of related party, and IAS 24.11 says a customer or supplier is not
 * related through economic dependence alone. So:
 *
 *   - **An ordinary employee is not a related party**, and nothing in this file makes one.
 *   - **Every person defaults to not related.** A marker exists only because somebody recorded
 *     one. No role, no appointment, no employment link and no significant-control flag creates,
 *     implies or back-fills a marker. `pl_related_party_candidates()` exists precisely because
 *     the designation is affirmative: it *surfaces* a trade party that matches a person in a
 *     register so a human can decide, and it never marks anybody.
 *   - **The related-party flag is a separate attribute from any role or employment link.** It
 *     lives in its own table with its own capability and its own effective dates.
 *
 * `relationship` therefore carries exactly the three kinds IAS 24 names: key management
 * personnel, a close family member of one, and an entity controlled or jointly controlled by
 * either. Reading a marker takes `relatedparty.view`, which is the authority B58 reserves for
 * sensitive data, so the register stays invisible to whoever manages customers.
 *
 * Two reports come out of it. `pl_related_party_transactions()` is the IAS 24.18 artefact: every
 * transaction with a marked party in the period, with amounts, outstanding balances, terms and
 * what the chart records about provisions. `pl_director_loan_movements()` is the movement
 * reconciliation — opening, advanced, repaid, closing — that Pakistan's Fourth Schedule requires
 * for directors and that the UK requires in a note under Companies Act 2006 s.413; one artefact
 * satisfies both, which is exactly what B74 says it should.
 * ------------------------------------------------------------------------------------------ */

/* ============================================================ the registration profile (B63) */

/**
 * The legal forms the profile offers.
 *
 * A VARCHAR validated here rather than an ENUM, so a jurisdiction's form costs a line of PHP
 * rather than an ALTER on every installed database.
 *
 * **This list never selects an accounting framework.** `docs/ARCHITECTURE.md` and the accounting
 * profile work are explicit that a framework is chosen and never inferred: a private limited
 * company may report under full IFRS, IFRS for SMEs or a local standard, and the choice is the
 * preparer's. Nothing in this file reads the legal form to decide an accounting treatment.
 *
 * @return array<string,string> key => label
 */
function pl_legal_forms(): array
{
    return [
        'sole_proprietor' => 'Sole proprietor',
        'partnership' => 'Partnership or AOP',
        'llp' => 'Limited liability partnership',
        'private_limited' => 'Private limited company',
        'single_member_company' => 'Single member company',
        'public_limited' => 'Public limited company',
        'llc' => 'Limited liability company (LLC)',
        'corporation' => 'Corporation',
        'non_profit' => 'Non-profit or association',
        'other' => 'Other',
    ];
}

/** Does this legal form issue shares? Decides which half of the register a screen offers. */
function pl_legal_form_has_shares(string $form): bool
{
    // A country-prefixed key ('pk.pvt_ltd') answers through its family (legal_form_functions.php).
    return in_array(pl_legal_form_family($form) ?: $form, ['private_limited', 'single_member_company', 'public_limited', 'corporation'], true);
}

/** Is this a form whose owners are partners with profit-sharing ratios rather than shares? */
function pl_legal_form_has_partners(string $form): bool
{
    return in_array(pl_legal_form_family($form) ?: $form, ['partnership', 'llp'], true);
}

/**
 * Is this month/day pair a real financial year end?
 *
 * The schema CHECK holds the month to 1-12 and the day to 1-31, which neither MySQL nor MariaDB
 * can narrow per month portably. 29 February is refused as well as 30 February: a year end that
 * exists in three years out of four is a data-entry mistake, not a policy.
 */
function pl_financial_year_end_valid(int $month, int $day): bool
{
    if ($month < 1 || $month > 12 || $day < 1) { return false; }
    return $day <= (int) [1 => 31, 2 => 28, 3 => 31, 4 => 30, 5 => 31, 6 => 30, 7 => 31, 8 => 31, 9 => 30, 10 => 31, 11 => 30, 12 => 31][$month];
}

/** "30 June", for a profile that records one. */
function pl_financial_year_end_label(?int $month, ?int $day): string
{
    if ($month === null || $day === null) { return ''; }
    return $day . ' ' . [1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
        7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'][$month];
}

/* ================================================================= shared validation helpers */

/**
 * A share count. Unsigned, up to 18 whole digits and six decimal places.
 *
 * Six places because the Open Cap Format allows fractional shares and a bonus issue or a stock
 * split legitimately produces them; bcmath throughout, never a float, exactly as money is.
 */
function pl_share_quantity(string $quantity): string
{
    if (!preg_match('/^(?:0|[1-9][0-9]{0,17})(?:\.[0-9]{1,6})?$/D', $quantity)) {
        throw new DomainException('Enter a number of shares with up to 18 whole digits and six decimal places, without commas or a sign.');
    }
    return bcadd($quantity, '0', 6);
}

/** The register's immutable audit row. Company scoped, because none of these facts is a book's. */
function pl_ownership_audit(int $actorId, int $companyId, string $entity, int $id, string $action, string $reason, ?array $before, array $after): void
{
    DB::insert('pl_ownership_audit', [
        'company_id' => $companyId, 'actor_id' => $actorId, 'entity_type' => $entity, 'entity_id' => $id,
        'action' => $action, 'reason' => $reason,
        'before_state' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        'after_state' => json_encode($after, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
    ]);
}

/** The one authorisation question the maintaining services ask. */
function pl_ownership_require_manage(int $actorId, int $companyId): void
{
    if (!pl_user_can($actorId, $companyId, 'ownership.manage')) {
        throw new DomainException('Your role cannot maintain the ownership register.');
    }
}

/* ==================================================== hook points for the plugin runtime (#73)
 *
 * This milestone was built against a tree where the plugin runtime did not exist yet, so the
 * register publishes its own seam: a named list of hook points, a local registry, and one emit
 * function every write in this file calls. The runtime has since merged (1.2 M8), and the seam
 * now hands the emit to it rather than running a second hook system beside it.
 *
 * **The one thing the runtime taught this file.** Every write here emits from inside
 * `pl_ledger_transaction()`, and `pl_hook_assert_unlocked()` refuses to run an action while a
 * book row is locked — rightly, because an action that reports an already-committed change must
 * not see uncommitted state or hold a lock while a listener works. Core already has the answer:
 * `pl_hook_after_commit()` queues the action and `pl_ledger_transaction()` flushes the queue
 * after the outermost commit, dropping it entirely on a rollback. So `pl_ownership_emit()` hands
 * the payload to that queue whenever the runtime is loaded, and runs its own listeners directly
 * only when it is not — which is how `tests/ownership_test.php` exercises the seam without a
 * package.
 *
 * **What a package does with it.** `pl_add_action('ownership.share_event.recorded', $callback)`,
 * and the callback receives one argument: the payload documented below. Nothing needs bridging;
 * these are ordinary core actions on core's own queue. They are deliberately **not** added to
 * `pl_hook_points()` in this milestone, because that list is the published plugin surface with a
 * recorded snapshot owned by M8, and declaring a new surface belongs with that milestone's
 * contract rather than being slipped in beside it. Registering an undeclared hook is allowed and
 * works; what it does not yet get is a line in the published catalogue.
 */

/**
 * The hook points a country company-secretarial plugin needs to produce statutory forms,
 * certificates and a filing calendar without carrying a second register (B63).
 *
 * @return array<string,string> hook => what it carries
 */
function pl_ownership_hook_points(): array
{
    return [
        'ownership.share_event.recorded' => 'A share ledger event was recorded: the event row, its class and both sides.',
        'ownership.share_event.reversed' => 'A share ledger event was corrected by a linked reversal.',
        'ownership.officer.appointed' => 'An officer appointment was recorded.',
        'ownership.officer.resigned' => 'An officer resignation date was recorded.',
        'ownership.member.recorded' => 'A membership interest was opened or closed.',
        'ownership.related_party.marked' => 'A related-party marker was recorded or ended.',
    ];
}

/**
 * Register a listener on the register's own registry. A package uses `pl_add_action()` instead;
 * this exists so the seam is exercisable without the runtime, and `'*'` receives every point.
 *
 * @param callable(string, array<string,mixed>): void $listener
 */
function pl_ownership_on(string $hook, callable $listener): void
{
    if ($hook !== '*' && !isset(pl_ownership_hook_points()[$hook])) {
        throw new DomainException('Unknown ownership hook point: ' . $hook);
    }
    $registry = pl_ownership_listeners();
    $registry[$hook][] = $listener;
    pl_ownership_listeners($registry);
}

/**
 * The listener registry. Passing a value replaces it, which is also how a test clears it.
 *
 * @param array<string, list<callable>>|null $replacement
 * @return array<string, list<callable>>
 */
function pl_ownership_listeners(?array $replacement = null): array
{
    static $listeners = [];
    if ($replacement !== null) { $listeners = $replacement; }
    return $listeners;
}

/**
 * Emit a hook point. A listener may not change what happened: it is told after the write, its
 * return value is discarded, and a failure in one never rolls back the register.
 *
 * Where the plugin runtime is loaded this goes on core's after-commit queue, so a package's
 * callback runs once the outermost transaction has committed and every lock is released — and
 * never at all if that transaction rolls back. The register's own listeners run directly,
 * because they are a test seam rather than a package.
 *
 * @param array<string,mixed> $payload
 */
function pl_ownership_emit(string $hook, array $payload): void
{
    if (function_exists('pl_hook_after_commit')) {
        pl_hook_after_commit($hook, [$payload]);
    }
    $registry = pl_ownership_listeners();
    foreach (array_merge($registry[$hook] ?? [], $registry['*'] ?? []) as $listener) {
        try {
            $listener($hook, $payload);
        } catch (Throwable $error) {
            error_log('PHP Ledger ownership hook listener failed for ' . $hook . ' (' . get_class($error) . ').');
        }
    }
}

/* ======================================================= the people the two registers share */

/** @return array<string,string> */
function pl_ownership_party_kinds(): array
{
    return ['person' => 'A person', 'entity' => 'A company or other entity'];
}

/**
 * Why this is not `pl_parties`.
 *
 * `pl_parties` carries `ck_party_roles`, which requires every row to be a customer or a vendor.
 * A shareholder who never trades with the business is neither, so recording one there would mean
 * inventing a trading role for them — and B76 rule 2 is explicit that a link between a trade
 * party and a person is *recorded, never inferred*. The two registers therefore share this
 * record, and the related-party marker is what connects a trade party to it when the same human
 * genuinely appears on both sides.
 *
 * @return array<int,array<string,mixed>>
 */
function pl_list_ownership_parties(int $actorId, int $companyId, bool $activeOnly = false): array
{
    pl_require_company_access($actorId, $companyId);
    $rows = DB::query('SELECT * FROM pl_ownership_parties WHERE company_id = %i' . ($activeOnly ? ' AND is_active = 1' : '') . ' ORDER BY name', $companyId);
    return array_map('pl_ownership_party_view', $rows);
}

function pl_get_ownership_party(int $actorId, int $companyId, int $id): array
{
    pl_require_company_access($actorId, $companyId);
    $row = DB::queryFirstRow('SELECT * FROM pl_ownership_parties WHERE id = %i AND company_id = %i FOR SHARE', $id, $companyId);
    if (!$row) { throw new DomainException('This person or entity is not in this company\'s ownership register.'); }
    return pl_ownership_party_view($row);
}

/** @param array<string,mixed> $row */
function pl_ownership_party_view(array $row): array
{
    foreach (['id', 'company_id', 'revision', 'created_by'] as $field) { $row[$field] = (int) $row[$field]; }
    $row['is_active'] = (bool) $row['is_active'];
    return $row;
}

/**
 * Record a person or an entity in the register.
 *
 * @param array<string,mixed> $input untrusted request data
 */
function pl_save_ownership_party(int $actorId, int $companyId, array $input, ?int $id = null, ?int $revision = null): array
{
    pl_demo_require_setup_action();
    $kind = is_string($input['kind'] ?? null) ? $input['kind'] : '';
    if (!isset(pl_ownership_party_kinds()[$kind])) {
        throw new DomainException('Choose whether this is a person or an entity.');
    }
    $reason = pl_ledger_text($input['reason'] ?? null, 'Reason for this change', 500);
    $data = [
        'kind' => $kind,
        'name' => pl_ledger_text($input['name'] ?? null, 'Name', 160),
        'country_code' => strtoupper(pl_ledger_text($input['country_code'] ?? '', 'Country', 2, false)),
        'identifier' => pl_ledger_text($input['identifier'] ?? '', 'Identifier', 80, false),
        'address' => pl_ledger_text($input['address'] ?? '', 'Address', 400, false),
        'email' => pl_ledger_text($input['email'] ?? '', 'Email', 190, false),
        'note' => pl_ledger_text($input['note'] ?? '', 'Note', 1000, false),
    ];
    if ($data['country_code'] !== '' && !preg_match('/^[A-Z]{2}$/D', $data['country_code'])) {
        throw new DomainException('Enter a two-letter country code, or leave it empty.');
    }
    if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        throw new DomainException('Enter a valid email address, or leave it empty.');
    }
    if (!is_bool($input['is_active'] ?? null)) { throw new DomainException('Choose whether this record is active.'); }
    $data['is_active'] = $input['is_active'];
    return pl_ledger_transaction(function () use ($actorId, $companyId, $data, $id, $revision, $reason): array {
        pl_require_company_access($actorId, $companyId, true);
        pl_ownership_require_manage($actorId, $companyId);
        if ($id === null) {
            DB::insert('pl_ownership_parties', $data + ['company_id' => $companyId, 'created_by' => $actorId]);
            $id = (int) DB::insertId();
            $after = pl_get_ownership_party($actorId, $companyId, $id);
            pl_ownership_audit($actorId, $companyId, 'ownership_party', $id, 'created', $reason, null, $after);
            return $after;
        }
        $before = pl_get_ownership_party($actorId, $companyId, $id);
        if ($revision !== $before['revision']) {
            throw new DomainException('Someone changed this record. Reload the latest version before applying your changes.');
        }
        DB::update('pl_ownership_parties', $data + ['revision' => $before['revision'] + 1, 'updated_at' => gmdate('Y-m-d H:i:s')],
            'id = %i AND company_id = %i', $id, $companyId);
        $after = pl_get_ownership_party($actorId, $companyId, $id);
        pl_ownership_audit($actorId, $companyId, 'ownership_party', $id, 'updated', $reason, $before, $after);
        return $after;
    });
}

/**
 * Link one person in the register to their B61 partner record in one book, or clear the link.
 *
 * Ownership is a fact about the company and a capital account is a fact about a book, so this is
 * its own table rather than a column on either side. `pl_owner_partners` is not altered by this
 * milestone: the register points at it, and `owner_functions.php` keeps sole responsibility for
 * what the three accounts mean and what may be posted to them.
 */
function pl_link_ownership_partner(int $actorId, int $companyId, int $bookId, int $partyId, ?int $partnerId, string $reason): array
{
    pl_demo_require_setup_action();
    $reason = pl_ledger_text($reason, 'Reason for this change', 500);
    if ($partnerId !== null && $partnerId < 1) { throw new DomainException('Choose a partner record, or choose none.'); }
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $partyId, $partnerId, $reason): array {
        pl_require_company_access($actorId, $companyId, true);
        pl_ownership_require_manage($actorId, $companyId);
        pl_ledger_book($companyId, $bookId, true);
        $party = pl_get_ownership_party($actorId, $companyId, $partyId);
        $before = DB::queryFirstRow('SELECT * FROM pl_ownership_party_accounts WHERE book_id = %i AND ownership_party_id = %i FOR UPDATE', $bookId, $partyId);
        if ($partnerId === null) {
            DB::query('DELETE FROM pl_ownership_party_accounts WHERE book_id = %i AND ownership_party_id = %i', $bookId, $partyId);
            pl_ownership_audit($actorId, $companyId, 'partner_link', $partyId, 'unlinked', $reason,
                $before === null ? null : ['partner_id' => (int) $before['partner_id'], 'book_id' => $bookId],
                ['partner_id' => null, 'book_id' => $bookId]);
            return ['ownership_party_id' => $partyId, 'book_id' => $bookId, 'partner_id' => null];
        }
        // The partner must belong to this company and this book. pl_get_owner_partner() proves
        // both, and throws the same refusal the Owner screen throws.
        $partner = pl_get_owner_partner($actorId, $companyId, $bookId, $partnerId);
        $taken = DB::queryFirstRow('SELECT ownership_party_id FROM pl_ownership_party_accounts WHERE book_id = %i AND partner_id = %i FOR UPDATE', $bookId, $partnerId);
        if ($taken !== null && (int) $taken['ownership_party_id'] !== $partyId) {
            throw new DomainException('That partner record is already linked to another person in the register. One partner record belongs to one person.');
        }
        DB::insertUpdate('pl_ownership_party_accounts', ['company_id' => $companyId, 'book_id' => $bookId,
            'ownership_party_id' => $partyId, 'partner_id' => $partnerId, 'linked_by' => $actorId, 'linked_at' => gmdate('Y-m-d H:i:s')]);
        $after = ['ownership_party_id' => $partyId, 'book_id' => $bookId, 'partner_id' => $partnerId,
            'partner_name' => (string) $partner['name'], 'person' => (string) $party['name']];
        pl_ownership_audit($actorId, $companyId, 'partner_link', $partyId, 'linked', $reason,
            $before === null ? null : ['partner_id' => (int) $before['partner_id'], 'book_id' => $bookId], $after);
        return $after;
    });
}

/** @return array<int,int> ownership party id => partner id, for one book */
function pl_ownership_partner_links(int $companyId, int $bookId): array
{
    $links = [];
    foreach (DB::query('SELECT ownership_party_id, partner_id FROM pl_ownership_party_accounts WHERE company_id = %i AND book_id = %i', $companyId, $bookId) as $row) {
        $links[(int) $row['ownership_party_id']] = (int) $row['partner_id'];
    }
    return $links;
}

/* ================================================= the members register, effective dated (#92) */

/** @return array<int,array<string,mixed>> */
function pl_list_ownership_members(int $actorId, int $companyId, ?string $asOf = null): array
{
    pl_require_company_access($actorId, $companyId);
    $sql = 'SELECT m.*, p.name, p.kind, p.country_code FROM pl_ownership_members m
        JOIN pl_ownership_parties p ON p.id = m.ownership_party_id AND p.company_id = m.company_id
        WHERE m.company_id = %i';
    $args = [$companyId];
    if ($asOf !== null) {
        pl_ledger_date($asOf);
        $sql .= ' AND m.effective_from <= %s AND (m.effective_to IS NULL OR m.effective_to >= %s)';
        $args[] = $asOf;
        $args[] = $asOf;
    }
    $rows = DB::query($sql . ' ORDER BY p.name, m.effective_from', ...$args);
    return array_map('pl_ownership_member_view', $rows);
}

function pl_get_ownership_member(int $actorId, int $companyId, int $id): array
{
    pl_require_company_access($actorId, $companyId);
    $row = DB::queryFirstRow('SELECT m.*, p.name, p.kind, p.country_code FROM pl_ownership_members m
        JOIN pl_ownership_parties p ON p.id = m.ownership_party_id AND p.company_id = m.company_id
        WHERE m.id = %i AND m.company_id = %i FOR SHARE', $id, $companyId);
    if (!$row) { throw new DomainException('This membership record is not in this company\'s register.'); }
    return pl_ownership_member_view($row);
}

/** @param array<string,mixed> $row */
function pl_ownership_member_view(array $row): array
{
    foreach (['id', 'company_id', 'ownership_party_id', 'revision', 'created_by'] as $field) { $row[$field] = (int) $row[$field]; }
    $row['profit_share'] = $row['profit_share'] === null ? null : bcadd((string) $row['profit_share'], '0', 6);
    return $row;
}

/**
 * Open or amend a membership interest.
 *
 * A member row is closed with an `effective_to` date rather than deleted, because who was a
 * member during a period is exactly what a statutory register has to answer years afterwards.
 *
 * The profit share here is the **partnership** half of an interest and it is recorded history;
 * the operative ratio the posting service uses is still `pl_owner_partners.profit_share` (B61).
 * Where the person is linked to a partner record, a currently effective member row must agree
 * with it, so the two cannot drift silently. Where they are not linked, this is a record of what
 * was agreed and nothing posts from it.
 *
 * @param array<string,mixed> $input untrusted request data
 */
function pl_save_ownership_member(int $actorId, int $companyId, array $input, ?int $id = null, ?int $revision = null): array
{
    pl_demo_require_setup_action();
    $reason = pl_ledger_text($input['reason'] ?? null, 'Reason for this change', 500);
    $partyId = $input['ownership_party_id'] ?? null;
    if (!is_int($partyId) || $partyId < 1) { throw new DomainException('Choose the person or entity this interest belongs to.'); }
    $from = pl_ledger_date(pl_ledger_text($input['effective_from'] ?? null, 'Effective from', 10));
    $toRaw = pl_ledger_text($input['effective_to'] ?? '', 'Effective to', 10, false);
    $to = $toRaw === '' ? null : pl_ledger_date($toRaw);
    if ($to !== null && $to < $from) { throw new DomainException('An interest cannot end before it begins.'); }
    $shareRaw = pl_ledger_text($input['profit_share'] ?? '', 'Profit-sharing ratio', 12, false);
    $share = null;
    if ($shareRaw !== '') {
        if (!preg_match('/^(?:0(?:\.[0-9]{1,6})?|1(?:\.0{1,6})?)$/D', $shareRaw)) {
            throw new DomainException('Enter a profit-sharing ratio between 0 and 1, for example 0.5 for half.');
        }
        $share = bcadd($shareRaw, '0', 6);
    }
    $note = pl_ledger_text($input['note'] ?? '', 'Note', 500, false);
    return pl_ledger_transaction(function () use ($actorId, $companyId, $partyId, $from, $to, $share, $note, $id, $revision, $reason): array {
        pl_require_company_access($actorId, $companyId, true);
        pl_ownership_require_manage($actorId, $companyId);
        pl_get_ownership_party($actorId, $companyId, $partyId);
        pl_ownership_member_periods_disjoint($companyId, $partyId, $from, $to, $id);
        $data = ['ownership_party_id' => $partyId, 'effective_from' => $from, 'effective_to' => $to,
            'profit_share' => $share, 'note' => $note];
        if ($id === null) {
            DB::insert('pl_ownership_members', $data + ['company_id' => $companyId, 'created_by' => $actorId]);
            $id = (int) DB::insertId();
            $before = null;
        } else {
            $before = pl_get_ownership_member($actorId, $companyId, $id);
            if ($revision !== $before['revision']) {
                throw new DomainException('Someone changed this membership record. Reload the latest version before applying your changes.');
            }
            DB::update('pl_ownership_members', $data + ['revision' => $before['revision'] + 1], 'id = %i AND company_id = %i', $id, $companyId);
        }
        $after = pl_get_ownership_member($actorId, $companyId, $id);
        pl_ownership_audit($actorId, $companyId, 'ownership_member', $id, $before === null ? 'created' : 'updated', $reason, $before, $after);
        pl_ownership_emit('ownership.member.recorded', ['company_id' => $companyId, 'member' => $after]);
        return $after;
    });
}

/**
 * One person's interests may not overlap in time.
 *
 * Two open interests for the same person is not an amendment, it is two answers to the same
 * question, and a snapshot at a date would then have to choose between them.
 */
function pl_ownership_member_periods_disjoint(int $companyId, int $partyId, string $from, ?string $to, ?int $exceptId): void
{
    $rows = DB::query('SELECT id, effective_from, effective_to FROM pl_ownership_members WHERE company_id = %i AND ownership_party_id = %i FOR UPDATE', $companyId, $partyId);
    foreach ($rows as $row) {
        if ($exceptId !== null && (int) $row['id'] === $exceptId) { continue; }
        $otherFrom = (string) $row['effective_from'];
        $otherTo = $row['effective_to'] === null ? null : (string) $row['effective_to'];
        $startsBeforeOtherEnds = $otherTo === null || $from <= $otherTo;
        $endsAfterOtherStarts = $to === null || $to >= $otherFrom;
        if ($startsBeforeOtherEnds && $endsAfterOtherStarts) {
            throw new DomainException('This person already has an interest recorded over those dates (' . $otherFrom . ' to ' . ($otherTo ?? 'open') . '). End that one before opening another.');
        }
    }
}

/* ============================================ the directors and officers register with PSC (#92) */

/** @return array<string,string> */
function pl_officer_roles(): array
{
    return [
        'director' => 'Director',
        'managing_director' => 'Managing director',
        'chief_executive' => 'Chief executive',
        'company_secretary' => 'Company secretary',
        'chief_financial_officer' => 'Chief financial officer',
        'partner' => 'Partner',
        'other' => 'Other officer',
    ];
}

/**
 * The officer roles that are a directorship.
 *
 * IAS 24 makes **any director** key management personnel, and both the Pakistani Fourth Schedule
 * and Companies Act 2006 s.413 attach their loan disclosure to directors. This list is therefore
 * the population of `pl_director_loan_movements()` and of the candidates
 * `pl_related_party_candidates()` surfaces. It does not by itself mark anybody as related: the
 * designation stays affirmative (B74).
 *
 * @return array<int,string>
 */
function pl_officer_director_roles(): array
{
    return ['director', 'managing_director', 'chief_executive'];
}

/** @return array<int,array<string,mixed>> */
function pl_list_ownership_officers(int $actorId, int $companyId, ?string $asOf = null): array
{
    pl_require_company_access($actorId, $companyId);
    $sql = 'SELECT o.*, p.name, p.kind, p.country_code FROM pl_ownership_officers o
        JOIN pl_ownership_parties p ON p.id = o.ownership_party_id AND p.company_id = o.company_id
        WHERE o.company_id = %i';
    $args = [$companyId];
    if ($asOf !== null) {
        pl_ledger_date($asOf);
        $sql .= ' AND o.appointed_on <= %s AND (o.resigned_on IS NULL OR o.resigned_on >= %s)';
        $args[] = $asOf;
        $args[] = $asOf;
    }
    $rows = DB::query($sql . ' ORDER BY p.name, o.appointed_on', ...$args);
    return array_map('pl_ownership_officer_view', $rows);
}

function pl_get_ownership_officer(int $actorId, int $companyId, int $id): array
{
    pl_require_company_access($actorId, $companyId);
    $row = DB::queryFirstRow('SELECT o.*, p.name, p.kind, p.country_code FROM pl_ownership_officers o
        JOIN pl_ownership_parties p ON p.id = o.ownership_party_id AND p.company_id = o.company_id
        WHERE o.id = %i AND o.company_id = %i FOR SHARE', $id, $companyId);
    if (!$row) { throw new DomainException('This appointment is not in this company\'s register.'); }
    return pl_ownership_officer_view($row);
}

/** @param array<string,mixed> $row */
function pl_ownership_officer_view(array $row): array
{
    foreach (['id', 'company_id', 'ownership_party_id', 'revision', 'created_by'] as $field) { $row[$field] = (int) $row[$field]; }
    $row['has_significant_control'] = (bool) $row['has_significant_control'];
    $row['role_label'] = pl_officer_roles()[(string) $row['officer_role']] ?? (string) $row['officer_role'];
    $row['is_director'] = in_array((string) $row['officer_role'], pl_officer_director_roles(), true);
    return $row;
}

/**
 * Record an appointment or a resignation.
 *
 * `has_significant_control` is the person-with-significant-control / beneficial-owner flag that
 * Companies Act 2006 Part 21A and the 2023 amendments require, and the schema CHECK requires the
 * nature of control whenever the flag is set: a PSC entry that does not say *how* control is held
 * is not a register entry.
 *
 * The flag is **not** the related-party marker. Significant control makes a person related under
 * IAS 24 through control, but the marker is still recorded affirmatively and separately (B74),
 * because the marker names a *trade party* and this record names a person.
 *
 * @param array<string,mixed> $input untrusted request data
 */
function pl_save_ownership_officer(int $actorId, int $companyId, array $input, ?int $id = null, ?int $revision = null): array
{
    pl_demo_require_setup_action();
    $reason = pl_ledger_text($input['reason'] ?? null, 'Reason for this change', 500);
    $partyId = $input['ownership_party_id'] ?? null;
    if (!is_int($partyId) || $partyId < 1) { throw new DomainException('Choose the person this appointment belongs to.'); }
    $role = is_string($input['officer_role'] ?? null) ? $input['officer_role'] : '';
    if (!isset(pl_officer_roles()[$role])) { throw new DomainException('Choose the office this person holds.'); }
    $appointed = pl_ledger_date(pl_ledger_text($input['appointed_on'] ?? null, 'Appointed on', 10));
    $resignedRaw = pl_ledger_text($input['resigned_on'] ?? '', 'Resigned on', 10, false);
    $resigned = $resignedRaw === '' ? null : pl_ledger_date($resignedRaw);
    if ($resigned !== null && $resigned < $appointed) { throw new DomainException('An appointment cannot end before it begins.'); }
    if (!is_bool($input['has_significant_control'] ?? null)) {
        throw new DomainException('Choose whether this person holds significant control.');
    }
    $control = $input['has_significant_control'];
    $nature = pl_ledger_text($input['control_nature'] ?? '', 'Nature of control', 300, false);
    if ($control && $nature === '') {
        throw new DomainException('Say how significant control is held — the shareholding, the voting rights or the right to appoint the board. A register entry that does not say how is not an entry.');
    }
    if (!$control) { $nature = ''; }
    $data = ['ownership_party_id' => $partyId, 'officer_role' => $role, 'appointed_on' => $appointed,
        'resigned_on' => $resigned, 'has_significant_control' => $control, 'control_nature' => $nature,
        'role_title' => pl_ledger_text($input['role_title'] ?? '', 'Role title', 120, false),
        'note' => pl_ledger_text($input['note'] ?? '', 'Note', 500, false)];
    return pl_ledger_transaction(function () use ($actorId, $companyId, $data, $id, $revision, $reason): array {
        pl_require_company_access($actorId, $companyId, true);
        pl_ownership_require_manage($actorId, $companyId);
        pl_get_ownership_party($actorId, $companyId, (int) $data['ownership_party_id']);
        if ($id === null) {
            DB::insert('pl_ownership_officers', $data + ['company_id' => $companyId, 'created_by' => $actorId]);
            $id = (int) DB::insertId();
            $before = null;
        } else {
            $before = pl_get_ownership_officer($actorId, $companyId, $id);
            if ($revision !== $before['revision']) {
                throw new DomainException('Someone changed this appointment. Reload the latest version before applying your changes.');
            }
            DB::update('pl_ownership_officers', $data + ['revision' => $before['revision'] + 1], 'id = %i AND company_id = %i', $id, $companyId);
        }
        $after = pl_get_ownership_officer($actorId, $companyId, $id);
        pl_ownership_audit($actorId, $companyId, 'ownership_officer', $id, $before === null ? 'appointed' : 'updated', $reason, $before, $after);
        $resignedNow = $after['resigned_on'] !== null && ($before === null || $before['resigned_on'] === null);
        pl_ownership_emit($resignedNow ? 'ownership.officer.resigned' : 'ownership.officer.appointed',
            ['company_id' => $companyId, 'officer' => $after]);
        return $after;
    });
}

/* ================================================================ share classes and the ledger */

/** @return array<string,string> */
function pl_share_event_types(): array
{
    return [
        'allotment' => 'Allotment',
        'transfer' => 'Transfer',
        'cancellation' => 'Cancellation',
        'bonus_issue' => 'Bonus issue',
        'redesignation' => 'Re-designation',
    ];
}

/**
 * Which events may raise a journal, and how.
 *
 *  - `allotment` and `bonus_issue` post through `pl_post_journal()`, or carry a link to a journal
 *    posted elsewhere.
 *  - `transfer` posts nothing, ever. A sale of shares between two shareholders changes nothing in
 *    the company's own books; the schema CHECK refuses a journal on one.
 *  - `cancellation` and `redesignation` may only carry a link to an already posted journal. A
 *    capital reduction's accounting is jurisdiction specific and court- or solvency-gated, and a
 *    re-designation's treatment depends on the classes' terms. Choosing either here would be the
 *    silent accounting guess `AGENTS.md` forbids.
 *
 * @return array<string,string> event type => 'posts' | 'links' | 'never'
 */
function pl_share_event_posting(): array
{
    return ['allotment' => 'posts', 'bonus_issue' => 'posts', 'transfer' => 'never',
        'cancellation' => 'links', 'redesignation' => 'links'];
}

/** @return array<int,array<string,mixed>> */
function pl_list_share_classes(int $actorId, int $companyId, ?string $asOf = null): array
{
    pl_require_company_access($actorId, $companyId);
    $asOf = $asOf === null ? gmdate('Y-m-d') : pl_ledger_date($asOf);
    $issued = pl_share_issued_by_class($companyId, $asOf);
    $classes = [];
    foreach (DB::query('SELECT * FROM pl_share_classes WHERE company_id = %i ORDER BY code', $companyId) as $row) {
        $classes[] = pl_share_class_view($row) + ['issued_shares' => $issued[(int) $row['id']] ?? '0.000000'];
    }
    return $classes;
}

function pl_get_share_class(int $actorId, int $companyId, int $id): array
{
    pl_require_company_access($actorId, $companyId);
    $row = DB::queryFirstRow('SELECT * FROM pl_share_classes WHERE id = %i AND company_id = %i FOR SHARE', $id, $companyId);
    if (!$row) { throw new DomainException('This share class is not in this company\'s register.'); }
    return pl_share_class_view($row);
}

/** @param array<string,mixed> $row */
function pl_share_class_view(array $row): array
{
    foreach (['id', 'company_id', 'revision', 'created_by'] as $field) { $row[$field] = (int) $row[$field]; }
    $row['nominal_value'] = bcadd((string) $row['nominal_value'], '0', 4);
    $row['votes_per_share'] = bcadd((string) $row['votes_per_share'], '0', 4);
    $row['authorised_shares'] = $row['authorised_shares'] === null ? null : bcadd((string) $row['authorised_shares'], '0', 6);
    $row['is_option_pool'] = (bool) $row['is_option_pool'];
    $row['is_active'] = (bool) $row['is_active'];
    return $row;
}

/**
 * Record a share class.
 *
 * There is deliberately **no issued count**: it is the ledger summed to a date, every time it is
 * read. A stored count would be a second source of ownership, which B63 forbids, and it is the
 * field that goes stale first. `authorised_shares` is nullable for a jurisdiction that has no
 * authorised capital — the UK abolished it in 2006.
 *
 * @param array<string,mixed> $input untrusted request data
 */
function pl_save_share_class(int $actorId, int $companyId, array $input, ?int $id = null, ?int $revision = null): array
{
    pl_demo_require_setup_action();
    $reason = pl_ledger_text($input['reason'] ?? null, 'Reason for this change', 500);
    $code = strtoupper(pl_ledger_text($input['code'] ?? null, 'Class code', 20));
    if (!preg_match('/^[A-Z0-9][A-Z0-9 _-]{0,19}$/D', $code)) {
        throw new DomainException('A class code uses letters, digits, spaces, hyphens and underscores, for example ORD or A-PREF.');
    }
    $classType = is_string($input['class_type'] ?? null) ? $input['class_type'] : '';
    if (!in_array($classType, ['common', 'preferred'], true)) {
        throw new DomainException('Choose whether this class is ordinary or preference.');
    }
    $currency = strtoupper(pl_ledger_text($input['currency'] ?? null, 'Currency', 3));
    if (!preg_match('/^[A-Z]{3}$/D', $currency)) { throw new DomainException('Enter a three-letter currency code.'); }
    $nominal = pl_amount(is_string($input['nominal_value'] ?? null) ? $input['nominal_value'] : '');
    $votes = is_string($input['votes_per_share'] ?? null) ? $input['votes_per_share'] : '1';
    if (!preg_match('/^(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,4})?$/D', $votes)) {
        throw new DomainException('Enter the votes per share as a number with up to four decimal places.');
    }
    $votes = bcadd($votes, '0', 4);
    $authorisedRaw = pl_ledger_text($input['authorised_shares'] ?? '', 'Authorised shares', 32, false);
    $authorised = $authorisedRaw === '' ? null : pl_share_quantity($authorisedRaw);
    if ($authorised !== null && bccomp($authorised, '0', 6) <= 0) {
        throw new DomainException('Authorised shares must be greater than zero, or left empty where the jurisdiction has no authorised capital.');
    }
    foreach (['is_option_pool', 'is_active'] as $flag) {
        if (!is_bool($input[$flag] ?? null)) { throw new DomainException('Choose a yes or no for every option on this class.'); }
    }
    $data = ['code' => $code, 'name' => pl_ledger_text($input['name'] ?? null, 'Class name', 120),
        'class_type' => $classType, 'currency' => $currency, 'nominal_value' => $nominal,
        'votes_per_share' => $votes, 'authorised_shares' => $authorised,
        'is_option_pool' => $input['is_option_pool'], 'is_active' => $input['is_active'],
        'dividend_rights' => pl_ledger_text($input['dividend_rights'] ?? '', 'Dividend rights', 1000, false),
        'liquidation_rights' => pl_ledger_text($input['liquidation_rights'] ?? '', 'Liquidation rights', 1000, false)];
    return pl_ledger_transaction(function () use ($actorId, $companyId, $data, $id, $revision, $reason, $authorised): array {
        pl_require_company_access($actorId, $companyId, true);
        pl_ownership_require_manage($actorId, $companyId);
        if ($id === null) {
            DB::insert('pl_share_classes', $data + ['company_id' => $companyId, 'created_by' => $actorId]);
            $id = (int) DB::insertId();
            $before = null;
        } else {
            $before = pl_get_share_class($actorId, $companyId, $id);
            if ($revision !== $before['revision']) {
                throw new DomainException('Someone changed this share class. Reload the latest version before applying your changes.');
            }
            // Lowering the authorised capital below what is already issued would make the register
            // describe an impossible company.
            $issued = pl_share_issued_by_class($companyId, '9998-12-31')[$id] ?? '0.000000';
            if ($authorised !== null && bccomp($authorised, $issued, 6) < 0) {
                throw new DomainException('This class already has ' . $issued . ' shares issued, so the authorised number cannot be set below that.');
            }
            DB::update('pl_share_classes', $data + ['revision' => $before['revision'] + 1], 'id = %i AND company_id = %i', $id, $companyId);
        }
        $after = pl_get_share_class($actorId, $companyId, $id);
        pl_ownership_audit($actorId, $companyId, 'share_class', $id, $before === null ? 'created' : 'updated', $reason, $before, $after);
        return $after;
    });
}

/* ------------------------------------------------------------------- the ledger's arithmetic */

/**
 * What one event does to holdings, as a list of signed deltas.
 *
 * A pure function of the row: no database, no clock, no actor. Everything that reads the share
 * ledger — the snapshot, the issued counts, the export, the negative-holding check — goes through
 * this one place, so a new event type is defined once.
 *
 * @param array<string,mixed> $event
 * @return array<int,array{class_id:int, party_id:int, delta:string}>
 */
function pl_share_event_effects(array $event): array
{
    $class = (int) $event['share_class_id'];
    $quantity = bcadd((string) $event['quantity'], '0', 6);
    $from = $event['from_party_id'] === null ? null : (int) $event['from_party_id'];
    $to = $event['to_party_id'] === null ? null : (int) $event['to_party_id'];
    $target = $event['to_share_class_id'] === null ? null : (int) $event['to_share_class_id'];
    return match ((string) $event['event_type']) {
        'allotment', 'bonus_issue' => [['class_id' => $class, 'party_id' => (int) $to, 'delta' => $quantity]],
        'transfer' => [
            ['class_id' => $class, 'party_id' => (int) $from, 'delta' => bcsub('0', $quantity, 6)],
            ['class_id' => $class, 'party_id' => (int) $to, 'delta' => $quantity],
        ],
        'cancellation' => [['class_id' => $class, 'party_id' => (int) $from, 'delta' => bcsub('0', $quantity, 6)]],
        'redesignation' => [
            ['class_id' => $class, 'party_id' => (int) $from, 'delta' => bcsub('0', $quantity, 6)],
            ['class_id' => (int) $target, 'party_id' => (int) $to, 'delta' => $quantity],
        ],
        default => throw new DomainException('Unsupported share ledger event.'),
    };
}

/**
 * Every event in effect on or before a date, in ledger order, with reversals already applied.
 *
 * A reversal row carries no effect of its own: it removes the effect of the event it reverses,
 * from the reversal's own effective date. That is the same semantic `pl_reverse_journal()` has,
 * and it is why the ledger never needs an UPDATE.
 *
 * @return array<int,array{event:array<string,mixed>, effects:array<int,array{class_id:int, party_id:int, delta:string}>}>
 */
function pl_share_ledger_effects(int $companyId, string $asOf): array
{
    $rows = DB::query('SELECT * FROM pl_share_events WHERE company_id = %i AND effective_date <= %s ORDER BY effective_date, id', $companyId, $asOf);
    $byId = [];
    foreach ($rows as $row) { $byId[(int) $row['id']] = $row; }
    // A reversal may point at an event whose own date is inside the range even when the reversal
    // itself is not, so the target is read from the full set, not from the filtered one.
    $timeline = [];
    foreach ($rows as $row) {
        if ($row['reversal_of_id'] === null) {
            $timeline[] = ['event' => $row, 'effects' => pl_share_event_effects($row)];
            continue;
        }
        $targetId = (int) $row['reversal_of_id'];
        $target = $byId[$targetId] ?? DB::queryFirstRow('SELECT * FROM pl_share_events WHERE id = %i AND company_id = %i', $targetId, $companyId);
        if (!$target) { continue; }
        $negated = [];
        foreach (pl_share_event_effects($target) as $effect) {
            $effect['delta'] = bcsub('0', $effect['delta'], 6);
            $negated[] = $effect;
        }
        $timeline[] = ['event' => $row, 'effects' => $negated];
    }
    return $timeline;
}

/**
 * Holdings per class per holder at a date.
 *
 * @return array<int,array<int,string>> class id => [ownership party id => shares]
 */
function pl_share_positions(int $companyId, string $asOf): array
{
    $positions = [];
    foreach (pl_share_ledger_effects($companyId, $asOf) as $entry) {
        foreach ($entry['effects'] as $effect) {
            $current = $positions[$effect['class_id']][$effect['party_id']] ?? '0.000000';
            $positions[$effect['class_id']][$effect['party_id']] = bcadd($current, $effect['delta'], 6);
        }
    }
    return $positions;
}

/** @return array<int,string> class id => issued shares at that date */
function pl_share_issued_by_class(int $companyId, string $asOf): array
{
    $issued = [];
    foreach (pl_share_positions($companyId, $asOf) as $classId => $holders) {
        $total = '0.000000';
        foreach ($holders as $shares) { $total = bcadd($total, $shares, 6); }
        $issued[$classId] = $total;
    }
    return $issued;
}

/**
 * No holder may hold a negative number of shares at any moment in the register's history.
 *
 * Checked over the whole timeline, not only at the new event's date, because inserting a
 * back-dated transfer can make a later cancellation impossible — and a register that only
 * balances at the end is not a register.
 */
function pl_share_require_no_negative_holding(int $companyId): void
{
    $running = [];
    foreach (pl_share_ledger_effects($companyId, '9998-12-31') as $entry) {
        foreach ($entry['effects'] as $effect) {
            $current = bcadd($running[$effect['class_id']][$effect['party_id']] ?? '0.000000', $effect['delta'], 6);
            if (bccomp($current, '0', 6) < 0) {
                $holder = DB::queryFirstField('SELECT name FROM pl_ownership_parties WHERE id = %i AND company_id = %i', $effect['party_id'], $companyId);
                throw new DomainException('That would leave ' . (is_string($holder) ? $holder : 'a holder')
                    . ' holding fewer than nought shares on ' . (string) $entry['event']['effective_date']
                    . '. Check the dates and quantities: the register has to hold at every moment, not only at the end.');
            }
            $running[$effect['class_id']][$effect['party_id']] = $current;
        }
    }
}

/* ---------------------------------------------------------------- writing to the share ledger */

/** @return array<int,array<string,mixed>> */
function pl_list_share_events(int $actorId, int $companyId, int $limit = 200): array
{
    pl_require_company_access($actorId, $companyId);
    $limit = max(1, min(1000, $limit));
    $rows = DB::query('SELECT e.*, c.code AS class_code, c.name AS class_name,
            f.name AS from_name, t.name AS to_name, tc.code AS to_class_code,
            r.id AS reversed_by_id
        FROM pl_share_events e
        JOIN pl_share_classes c ON c.id = e.share_class_id AND c.company_id = e.company_id
        LEFT JOIN pl_ownership_parties f ON f.id = e.from_party_id AND f.company_id = e.company_id
        LEFT JOIN pl_ownership_parties t ON t.id = e.to_party_id AND t.company_id = e.company_id
        LEFT JOIN pl_share_classes tc ON tc.id = e.to_share_class_id AND tc.company_id = e.company_id
        LEFT JOIN pl_share_events r ON r.reversal_of_id = e.id
        WHERE e.company_id = %i ORDER BY e.effective_date DESC, e.id DESC LIMIT %i', $companyId, $limit);
    return array_map('pl_share_event_view', $rows);
}

function pl_get_share_event(int $actorId, int $companyId, int $id): array
{
    pl_require_company_access($actorId, $companyId);
    $row = DB::queryFirstRow('SELECT e.*, c.code AS class_code, c.name AS class_name,
            f.name AS from_name, t.name AS to_name, tc.code AS to_class_code, r.id AS reversed_by_id
        FROM pl_share_events e
        JOIN pl_share_classes c ON c.id = e.share_class_id AND c.company_id = e.company_id
        LEFT JOIN pl_ownership_parties f ON f.id = e.from_party_id AND f.company_id = e.company_id
        LEFT JOIN pl_ownership_parties t ON t.id = e.to_party_id AND t.company_id = e.company_id
        LEFT JOIN pl_share_classes tc ON tc.id = e.to_share_class_id AND tc.company_id = e.company_id
        LEFT JOIN pl_share_events r ON r.reversal_of_id = e.id
        WHERE e.id = %i AND e.company_id = %i FOR SHARE', $id, $companyId);
    if (!$row) { throw new DomainException('This share ledger event is not in this company\'s register.'); }
    return pl_share_event_view($row);
}

/** @param array<string,mixed> $row */
function pl_share_event_view(array $row): array
{
    foreach (['id', 'company_id', 'share_class_id', 'created_by'] as $field) { $row[$field] = (int) $row[$field]; }
    foreach (['from_party_id', 'to_party_id', 'to_share_class_id', 'book_id', 'journal_id', 'reversal_of_id', 'reversed_by_id'] as $field) {
        $row[$field] = $row[$field] === null ? null : (int) $row[$field];
    }
    $row['quantity'] = bcadd((string) $row['quantity'], '0', 6);
    foreach (['consideration_amount', 'nominal_total', 'premium_total'] as $field) {
        $row[$field] = $row[$field] === null ? null : bcadd((string) $row[$field], '0', 4);
    }
    $row['type_label'] = pl_share_event_types()[(string) $row['event_type']] ?? (string) $row['event_type'];
    $row['is_reversal'] = $row['reversal_of_id'] !== null;
    $row['status'] = $row['reversed_by_id'] !== null ? 'reversed' : ($row['is_reversal'] ? 'correction' : 'recorded');
    return $row;
}

/**
 * The equity accounts an allotment or a bonus issue may use, and the cash accounts it may debit.
 *
 * Deliberately no default. `pl_owner_pick_account()` allows a single candidate to stand in for a
 * choice, which is right where one account can only mean one thing; share capital and share
 * premium are two different accounts and a chart with one equity account cannot supply both, so
 * naming them is always required. Nothing here reads an account's *name* to decide what it is.
 *
 * @return array{cash:array<int,array<string,mixed>>, equity:array<int,array<string,mixed>>}
 */
function pl_share_posting_accounts(int $companyId, int $bookId): array
{
    $available = pl_owner_accounts($companyId, $bookId);
    return ['cash' => $available['cash'], 'equity' => $available['capital']];
}

/** Resolve one named account out of a candidate list, with no fallback. */
function pl_share_require_account(array $choices, mixed $requested, string $label): array
{
    if (!is_int($requested) || $requested < 1) {
        throw new DomainException('Choose the ' . $label . ' account. The register never picks an account for a posting.');
    }
    foreach ($choices as $choice) {
        if ((int) $choice['id'] === $requested) { return $choice; }
    }
    if ($choices === []) {
        throw new DomainException('This book has no ' . $label . ' account yet. Add one to the chart of accounts before recording an allotment that posts.');
    }
    throw new DomainException('Choose the ' . $label . ' account from this book\'s chart: ' . pl_owner_account_list($choices) . '.');
}

/**
 * Record one share ledger event, posting the allotment or bonus issue where one is asked for.
 *
 * The journal is posted **before** the event row is written, because the ledger is append-only in
 * the database: there is no later UPDATE that could attach a journal id, and there must not be.
 * Both live inside one `pl_ledger_transaction()`, so a refusal on either side leaves neither.
 *
 * @param array<string,mixed> $input untrusted request data
 */
function pl_record_share_event(int $actorId, int $companyId, ?int $bookId, array $input): array
{
    pl_demo_require_setup_action();
    $type = is_string($input['event_type'] ?? null) ? $input['event_type'] : '';
    if (!isset(pl_share_event_types()[$type])) { throw new DomainException('Choose what happened to the shares.'); }
    $date = pl_ledger_date(pl_ledger_text($input['effective_date'] ?? null, 'Effective date', 10));
    $quantity = pl_share_quantity(pl_ledger_text($input['quantity'] ?? null, 'Number of shares', 32));
    if (bccomp($quantity, '0', 6) <= 0) { throw new DomainException('Enter a number of shares greater than zero.'); }
    $key = pl_request_key(pl_ledger_text($input['creation_key'] ?? null, 'Request identity', 128));
    $classId = $input['share_class_id'] ?? null;
    if (!is_int($classId) || $classId < 1) { throw new DomainException('Choose the share class.'); }
    foreach (['from_party_id', 'to_party_id', 'to_share_class_id', 'journal_id'] as $optional) {
        $value = $input[$optional] ?? null;
        if ($value !== null && (!is_int($value) || $value < 1)) {
            throw new DomainException('Choose a valid ' . str_replace('_', ' ', $optional) . '.');
        }
    }
    $post = ($input['post'] ?? false) === true;
    $certificate = pl_ledger_text($input['certificate_reference'] ?? '', 'Certificate reference', 80, false);
    $reason = pl_ledger_text($input['reason'] ?? '', 'Reason', 500, false);
    $considerationRaw = pl_ledger_text($input['consideration_amount'] ?? '', 'Consideration', 32, false);
    return pl_ledger_transaction(function () use ($actorId, $companyId, $bookId, $type, $date, $quantity, $classId, $input, $post, $certificate, $reason, $considerationRaw, $key): array {
        pl_require_company_access($actorId, $companyId, true);
        pl_ownership_require_manage($actorId, $companyId);
        $prior = DB::queryFirstRow('SELECT id FROM pl_share_events WHERE company_id = %i AND request_key = %s FOR UPDATE', $companyId, $key);
        if ($prior) { return pl_get_share_event($actorId, $companyId, (int) $prior['id']); }
        $class = pl_get_share_class($actorId, $companyId, $classId);
        if (!$class['is_active']) { throw new DomainException('That share class is closed. Reopen it before recording an event against it.'); }

        $row = ['company_id' => $companyId, 'share_class_id' => $classId, 'event_type' => $type,
            'effective_date' => $date, 'quantity' => $quantity, 'certificate_reference' => $certificate,
            'reason' => $reason, 'request_key' => $key, 'created_by' => $actorId,
            'from_party_id' => null, 'to_party_id' => null, 'to_share_class_id' => null,
            'consideration_currency' => null, 'consideration_amount' => null,
            'nominal_total' => null, 'premium_total' => null, 'book_id' => null, 'journal_id' => null];

        // Which sides each event actually has. The schema CHECK carries the same rule; this is
        // the half that can explain itself to whoever is filling the form in.
        $from = $input['from_party_id'] ?? null;
        $to = $input['to_party_id'] ?? null;
        if (in_array($type, ['allotment', 'bonus_issue'], true)) {
            if (!is_int($to)) { throw new DomainException('Choose who the shares are being issued to.'); }
            pl_get_ownership_party($actorId, $companyId, $to);
            $row['to_party_id'] = $to;
        } elseif ($type === 'transfer') {
            if (!is_int($from) || !is_int($to)) { throw new DomainException('Choose who is transferring the shares and who is receiving them.'); }
            if ($from === $to) { throw new DomainException('A transfer needs two different holders.'); }
            pl_get_ownership_party($actorId, $companyId, $from);
            pl_get_ownership_party($actorId, $companyId, $to);
            $row['from_party_id'] = $from;
            $row['to_party_id'] = $to;
        } elseif ($type === 'cancellation') {
            if (!is_int($from)) { throw new DomainException('Choose whose shares are being cancelled.'); }
            pl_get_ownership_party($actorId, $companyId, $from);
            $row['from_party_id'] = $from;
        } else {
            $target = $input['to_share_class_id'] ?? null;
            if (!is_int($from) || !is_int($target)) { throw new DomainException('Choose whose shares are being re-designated and the class they move to.'); }
            if ($target === $classId) { throw new DomainException('A re-designation moves shares to a different class.'); }
            pl_get_ownership_party($actorId, $companyId, $from);
            $targetClass = pl_get_share_class($actorId, $companyId, $target);
            if (!$targetClass['is_active']) { throw new DomainException('The class the shares move to is closed.'); }
            $row['from_party_id'] = $from;
            $row['to_party_id'] = $from;
            $row['to_share_class_id'] = $target;
        }

        // Authorised capital, where the jurisdiction has it. Only the two events that create
        // shares can breach it; a transfer and a re-designation move existing ones.
        if (in_array($type, ['allotment', 'bonus_issue'], true) && $class['authorised_shares'] !== null) {
            $issued = pl_share_issued_by_class($companyId, '9998-12-31')[$classId] ?? '0.000000';
            if (bccomp(bcadd($issued, $quantity, 6), $class['authorised_shares'], 6) > 0) {
                throw new DomainException('This class is authorised for ' . $class['authorised_shares'] . ' shares and ' . $issued
                    . ' are already issued, so ' . $quantity . ' more cannot be allotted. Increase the authorised capital first.');
            }
        }

        // Money. The nominal total is derived from the class, never entered: share capital *is*
        // the number of shares times their nominal value, and letting the two disagree is how a
        // share capital account stops reconciling to the register.
        if (in_array($type, ['allotment', 'bonus_issue'], true)) {
            $nominal = bcmul($quantity, $class['nominal_value'], 4);
            if (bccomp(bcmul($quantity, $class['nominal_value'], 10), $nominal, 10) !== 0) {
                throw new DomainException('That number of shares times the class\'s nominal value does not come out to a whole amount of money. Adjust the quantity or the nominal value rather than let the rounding land in share capital.');
            }
            $row['nominal_total'] = $nominal;
            $row['consideration_currency'] = $class['currency'];
            if ($type === 'allotment') {
                $consideration = $considerationRaw === '' ? $nominal : pl_amount($considerationRaw);
                if (bccomp($consideration, $nominal, 4) < 0) {
                    throw new DomainException('The consideration is below the nominal value of the shares. Shares issued at a discount need an accounting treatment this register will not choose for you: post that journal where it belongs and link it here instead.');
                }
                $row['consideration_amount'] = $consideration;
                $row['premium_total'] = bcsub($consideration, $nominal, 4);
            } else {
                // A bonus issue capitalises a reserve; no consideration passes.
                $row['consideration_amount'] = '0.0000';
                $row['premium_total'] = '0.0000';
            }
        }

        // The journal, if there is one.
        $posting = pl_share_event_posting()[$type];
        $journalId = $input['journal_id'] ?? null;
        if ($posting === 'never' && ($post || $journalId !== null)) {
            throw new DomainException('A transfer of shares between two holders changes nothing in the company\'s own books, so it never carries a journal.');
        }
        if ($post && $posting === 'links') {
            throw new DomainException('This register does not choose the accounting for a ' . strtolower(pl_share_event_types()[$type])
                . '. Post that journal where it belongs and link it here.');
        }
        if (($post || $journalId !== null) && ($bookId === null || $bookId < 1)) {
            throw new DomainException('Choose the book this journal belongs to.');
        }
        if ($post) {
            $journal = pl_share_post_event($actorId, $companyId, (int) $bookId, $type, $date, $class, $row, $input, $key);
            $row['book_id'] = $bookId;
            $row['journal_id'] = (int) $journal['id'];
        } elseif ($journalId !== null) {
            pl_ledger_book($companyId, (int) $bookId, true);
            $linked = pl_get_journal($actorId, $companyId, (int) $bookId, $journalId);
            $row['book_id'] = $bookId;
            $row['journal_id'] = (int) $linked['id'];
        }

        DB::insert('pl_share_events', $row);
        $id = (int) DB::insertId();
        pl_share_require_no_negative_holding($companyId);
        $after = pl_get_share_event($actorId, $companyId, $id);
        pl_ownership_audit($actorId, $companyId, 'share_event', $id, 'recorded', $reason !== '' ? $reason : 'Share ledger event recorded.', null, $after);
        pl_ownership_emit('ownership.share_event.recorded', ['company_id' => $companyId, 'event' => $after]);
        return $after;
    });
}

/**
 * The journal behind an allotment or a bonus issue. One call to `pl_post_journal()`, nothing else.
 *
 *   allotment    Dr cash or bank   consideration
 *                  Cr share capital  nominal
 *                  Cr share premium  premium        (omitted when there is no premium)
 *
 *   bonus issue  Dr the reserve being capitalised   nominal
 *                  Cr share capital                   nominal
 *
 * The reserve a bonus issue capitalises is chosen by the operator, never by this function: share
 * premium, retained earnings and a revaluation reserve are all lawful sources in different
 * jurisdictions and the choice changes what is distributable afterwards.
 *
 * @param array<string,mixed> $class
 * @param array<string,mixed> $row
 * @param array<string,mixed> $input
 */
function pl_share_post_event(int $actorId, int $companyId, int $bookId, string $type, string $date, array $class, array $row, array $input, string $key): array
{
    $book = pl_ledger_book($companyId, $bookId, true);
    if ((string) $book['currency'] !== (string) $class['currency']) {
        throw new DomainException('This share class is denominated in ' . (string) $class['currency'] . ' and the book is kept in '
            . (string) $book['currency'] . '. Post the journal in the book\'s own currency and link it to this event instead.');
    }
    $accounts = pl_share_posting_accounts($companyId, $bookId);
    $capital = pl_share_require_account($accounts['equity'], $input['share_capital_account_id'] ?? null, 'share capital');
    $nominal = (string) $row['nominal_total'];
    $lines = [];
    if ($type === 'allotment') {
        $consideration = (string) $row['consideration_amount'];
        if (bccomp($consideration, '0', 4) <= 0) {
            throw new DomainException('There is nothing to post: this allotment records no consideration. Record it without a journal, or link the journal that carries the consideration.');
        }
        $cash = pl_share_require_account($accounts['cash'], $input['debit_account_id'] ?? null, 'cash or bank');
        $lines[] = ['account_id' => (int) $cash['id'], 'debit' => $consideration, 'credit' => '0', 'description' => 'Subscription money received'];
        $lines[] = ['account_id' => (int) $capital['id'], 'debit' => '0', 'credit' => $nominal, 'description' => 'Share capital at nominal value'];
        $premium = (string) $row['premium_total'];
        if (bccomp($premium, '0', 4) > 0) {
            $premiumAccount = pl_share_require_account($accounts['equity'], $input['share_premium_account_id'] ?? null, 'share premium');
            if ((int) $premiumAccount['id'] === (int) $capital['id']) {
                throw new DomainException('Share capital and share premium are two different accounts. The premium is not part of called-up capital and is not distributable in the same way.');
            }
            $lines[] = ['account_id' => (int) $premiumAccount['id'], 'debit' => '0', 'credit' => $premium, 'description' => 'Share premium'];
        }
    } else {
        $source = pl_share_require_account($accounts['equity'], $input['source_account_id'] ?? null, 'reserve being capitalised');
        if ((int) $source['id'] === (int) $capital['id']) {
            throw new DomainException('A bonus issue capitalises a reserve into share capital, so the two accounts cannot be the same one.');
        }
        $lines[] = ['account_id' => (int) $source['id'], 'debit' => $nominal, 'credit' => '0', 'description' => 'Reserve capitalised on a bonus issue'];
        $lines[] = ['account_id' => (int) $capital['id'], 'debit' => '0', 'credit' => $nominal, 'description' => 'Share capital at nominal value'];
    }
    return pl_post_journal($actorId, $companyId, $bookId, [
        'date' => $date, 'currency' => (string) $book['currency'],
        'source_type' => 'share_event', 'source_reference' => $type . ':' . $key,
        'idempotency_key' => 'share:' . $key,
        'description' => pl_share_event_types()[$type] . ' of ' . (string) $row['quantity'] . ' ' . (string) $class['code'] . ' shares',
        'lines' => $lines,
    ]);
}

/**
 * Correct an event with a linked reversal, which is the only correction the ledger has.
 *
 * Where the original raised a journal, the journal is reversed through `pl_reverse_journal()` and
 * the reversal row links to the reversing journal, so the register and the ledger stay in step.
 *
 * The two dates can legitimately differ, and that is not an oversight. The **register** correction
 * takes the original event's own date unless one is given, because a share that was never validly
 * allotted was never held, and a snapshot at any date in between has to say so. The **journal**
 * reversal follows the ledger's rules, which default it to today and require the
 * `journal.reverse_backdated` authority to put it back on the original date. So a person without
 * that authority still corrects the register truthfully and still reverses the money, with the
 * reversing journal dated when they made the correction — which is what an auditor expects to see.
 */
function pl_reverse_share_event(int $actorId, int $companyId, int $eventId, string $reason, string $key, ?string $date = null): array
{
    pl_demo_require_setup_action();
    $reason = pl_ledger_text($reason, 'Reason for this correction', 500);
    $key = pl_request_key(pl_ledger_text($key, 'Request identity', 128));
    if ($date !== null) { pl_ledger_date($date); }
    return pl_ledger_transaction(function () use ($actorId, $companyId, $eventId, $reason, $key, $date): array {
        pl_require_company_access($actorId, $companyId, true);
        pl_ownership_require_manage($actorId, $companyId);
        $prior = DB::queryFirstRow('SELECT id FROM pl_share_events WHERE company_id = %i AND request_key = %s FOR UPDATE', $companyId, $key);
        if ($prior) { return pl_get_share_event($actorId, $companyId, (int) $prior['id']); }
        $original = pl_get_share_event($actorId, $companyId, $eventId);
        if ($original['is_reversal']) { throw new DomainException('This row is already a correction. Record the corrected event instead of reversing a reversal.'); }
        if ($original['reversed_by_id'] !== null) { throw new DomainException('This event has already been corrected.'); }
        $row = ['company_id' => $companyId, 'share_class_id' => $original['share_class_id'],
            'event_type' => $original['event_type'], 'effective_date' => $date ?? (string) $original['effective_date'],
            'from_party_id' => $original['from_party_id'], 'to_party_id' => $original['to_party_id'],
            'to_share_class_id' => $original['to_share_class_id'], 'quantity' => $original['quantity'],
            'consideration_currency' => $original['consideration_currency'], 'consideration_amount' => $original['consideration_amount'],
            'nominal_total' => $original['nominal_total'], 'premium_total' => $original['premium_total'],
            'certificate_reference' => (string) $original['certificate_reference'], 'reason' => $reason,
            'reversal_of_id' => $original['id'], 'request_key' => $key, 'created_by' => $actorId,
            'book_id' => null, 'journal_id' => null];
        if ($original['journal_id'] !== null && $original['book_id'] !== null) {
            $reversal = pl_reverse_journal($actorId, $companyId, (int) $original['book_id'], (int) $original['journal_id'], $date, 'share:' . $key . ':reverse', $reason);
            $row['book_id'] = $original['book_id'];
            $row['journal_id'] = (int) $reversal['id'];
        }
        DB::insert('pl_share_events', $row);
        $id = (int) DB::insertId();
        pl_share_require_no_negative_holding($companyId);
        $after = pl_get_share_event($actorId, $companyId, $id);
        pl_ownership_audit($actorId, $companyId, 'share_event', $id, 'reversed', $reason, $original, $after);
        pl_ownership_emit('ownership.share_event.reversed', ['company_id' => $companyId, 'event' => $after, 'reversed' => $original]);
        return $after;
    });
}

/* ================================================================================== the reports */

/**
 * Who owns what at a date: holdings and percentages per member per class, fully diluted where an
 * option pool exists as a class, plus the partnership ratios in effect on the same day.
 *
 * Percentages are bcmath throughout and are reported to six places; they are derived for display
 * and nothing posts from them.
 */
function pl_ownership_snapshot(int $actorId, int $companyId, string $asOf): array
{
    pl_require_company_access($actorId, $companyId);
    pl_ledger_date($asOf);
    $classes = pl_list_share_classes($actorId, $companyId, $asOf);
    $positions = pl_share_positions($companyId, $asOf);
    $people = [];
    foreach (pl_list_ownership_parties($actorId, $companyId) as $party) { $people[$party['id']] = $party; }

    $outstanding = '0.000000';      // issued shares in classes that are not an option pool
    $issuedAll = '0.000000';        // issued shares in every class
    $poolUnissued = '0.000000';     // authorised but unissued shares reserved in an option pool
    $totalVotes = '0.000000';
    foreach ($classes as $class) {
        $issued = (string) $class['issued_shares'];
        $issuedAll = bcadd($issuedAll, $issued, 6);
        if ($class['is_option_pool']) {
            if ($class['authorised_shares'] !== null) {
                $poolUnissued = bcadd($poolUnissued, bcsub((string) $class['authorised_shares'], $issued, 6), 6);
            }
        } else {
            $outstanding = bcadd($outstanding, $issued, 6);
        }
        $totalVotes = bcadd($totalVotes, bcmul($issued, (string) $class['votes_per_share'], 6), 6);
    }
    $fullyDiluted = bcadd($issuedAll, $poolUnissued, 6);

    $holdings = [];
    foreach ($classes as $class) {
        $rows = [];
        $classIssued = (string) $class['issued_shares'];
        foreach ($positions[$class['id']] ?? [] as $partyId => $shares) {
            if (bccomp($shares, '0', 6) === 0) { continue; }
            $rows[] = [
                'ownership_party_id' => $partyId,
                'name' => (string) ($people[$partyId]['name'] ?? 'Unknown holder'),
                'shares' => $shares,
                'percent_of_class' => pl_ownership_percent($shares, $classIssued),
                'percent_outstanding' => $class['is_option_pool'] ? null : pl_ownership_percent($shares, $outstanding),
                'percent_fully_diluted' => pl_ownership_percent($shares, $fullyDiluted),
                'votes' => bcmul($shares, (string) $class['votes_per_share'], 6),
                'percent_votes' => pl_ownership_percent(bcmul($shares, (string) $class['votes_per_share'], 6), $totalVotes),
            ];
        }
        // Largest holding first, then by name so the order is stable between runs.
        usort($rows, static function (array $a, array $b): int {
            $bySize = bccomp((string) $b['shares'], (string) $a['shares'], 6);
            return $bySize !== 0 ? $bySize : ((string) $a['name'] <=> (string) $b['name']);
        });
        $holdings[] = ['class' => $class, 'holders' => $rows];
    }
    return [
        'as_of' => $asOf, 'classes' => $classes, 'holdings' => $holdings,
        'outstanding_shares' => $outstanding, 'issued_shares' => $issuedAll,
        'fully_diluted_shares' => $fullyDiluted, 'pool_unissued_shares' => $poolUnissued, 'total_votes' => $totalVotes,
        'members' => pl_list_ownership_members($actorId, $companyId, $asOf),
        'officers' => pl_list_ownership_officers($actorId, $companyId, $asOf),
    ];
}

/** A percentage to six places, with nothing divided by nought. */
function pl_ownership_percent(string $part, string $whole): ?string
{
    if (bccomp($whole, '0', 6) === 0) { return null; }
    return bcmul(bcdiv($part, $whole, 10), '100', 6);
}

/**
 * Book value per share: the equity on the balance sheet at a date over the shares outstanding at
 * the same date.
 *
 * **What this is not.** It is not a valuation and it is not a price. It also does not model
 * class-specific liquidation preferences: where a preference class would take a fixed amount
 * ahead of the ordinary shares, the figure below overstates what an ordinary share would receive
 * on a winding up. The result says so in `basis`, because a number on a screen with no basis
 * beside it is how a book value becomes a price.
 */
function pl_book_value_per_share(int $actorId, int $companyId, int $bookId, string $asOf): array
{
    pl_require_company_access($actorId, $companyId);
    $book = pl_ledger_book($companyId, $bookId);
    $sheet = pl_balance_sheet($actorId, $companyId, $bookId, $asOf);
    $snapshot = pl_ownership_snapshot($actorId, $companyId, $asOf);
    $shares = (string) $snapshot['outstanding_shares'];
    $equity = (string) $sheet['total_equity'];
    $hasPreference = false;
    foreach ($snapshot['classes'] as $class) {
        if ((string) $class['class_type'] === 'preferred' && bccomp((string) $class['issued_shares'], '0', 6) > 0) { $hasPreference = true; }
    }
    return [
        'as_of' => $asOf, 'currency' => (string) $book['currency'],
        'total_equity' => $equity, 'recorded_equity' => (string) $sheet['recorded_equity'],
        'earned_profit' => (string) $sheet['earned_profit'],
        'outstanding_shares' => $shares,
        'fully_diluted_shares' => (string) $snapshot['fully_diluted_shares'],
        'book_value_per_share' => bccomp($shares, '0', 6) === 0 ? null : bcdiv($equity, $shares, 6),
        'book_value_per_share_diluted' => bccomp((string) $snapshot['fully_diluted_shares'], '0', 6) === 0
            ? null : bcdiv($equity, (string) $snapshot['fully_diluted_shares'], 6),
        'has_preference_class' => $hasPreference,
        'basis' => 'Equity on the balance sheet at this date divided by the shares outstanding at this date, including the result for the unclosed period. It is not a valuation and it does not apply any class\'s liquidation preference.',
    ];
}

/**
 * Movement on one account over a period, credit-positive.
 *
 * @return array{opening:string, debit:string, credit:string, closing:string}
 */
function pl_ownership_account_movement(int $companyId, int $bookId, int $accountId, string $from, string $to): array
{
    $row = DB::queryFirstRow('SELECT
            COALESCE(SUM(CASE WHEN j.journal_date < %s THEN l.credit - l.debit ELSE 0 END), 0) AS opening,
            COALESCE(SUM(CASE WHEN j.journal_date BETWEEN %s AND %s THEN l.debit ELSE 0 END), 0) AS debit,
            COALESCE(SUM(CASE WHEN j.journal_date BETWEEN %s AND %s THEN l.credit ELSE 0 END), 0) AS credit
        FROM pl_journal_lines l
        JOIN pl_journals j ON j.id = l.journal_id AND j.company_id = l.company_id AND j.book_id = l.book_id
        WHERE l.company_id = %i AND l.book_id = %i AND l.account_id = %i AND j.journal_date <= %s',
        $from, $from, $to, $from, $to, $companyId, $bookId, $accountId, $to);
    $opening = bcadd((string) ($row['opening'] ?? '0'), '0', 4);
    $debit = bcadd((string) ($row['debit'] ?? '0'), '0', 4);
    $credit = bcadd((string) ($row['credit'] ?? '0'), '0', 4);
    return ['opening' => $opening, 'debit' => $debit, 'credit' => $credit,
        'closing' => bcadd($opening, bcsub($credit, $debit, 4), 4)];
}

/**
 * The partner capital account statement: one partner, one period, the movement on each of the
 * three B61 accounts.
 *
 * Presented credit-positive, which is how a capital account reads: an introduction is a credit,
 * drawings are a debit, and the closing capital is what the business owes that partner in equity.
 * Fixed and fluctuating presentations are both visible here — the capital account on its own is
 * the fixed figure, and capital less drawings is the fluctuating one — because which of the two a
 * partnership uses is an agreement between the partners, not a setting in the software.
 *
 * Profit allocation is deliberately absent, exactly as it is from the Owner screen: the treatment
 * of salary, interest on capital and a loss is an accounting policy decision (B30).
 */
function pl_partner_capital_statement(int $actorId, int $companyId, int $bookId, string $from, string $to): array
{
    pl_require_company_access($actorId, $companyId);
    $book = pl_ledger_book($companyId, $bookId);
    pl_ledger_date($from);
    pl_ledger_date($to);
    if ($from > $to) { throw new DomainException('The statement must begin on or before it ends.'); }
    $links = pl_ownership_partner_links($companyId, $bookId);
    $byPartner = array_flip($links);
    $people = [];
    foreach (pl_list_ownership_parties($actorId, $companyId) as $party) { $people[$party['id']] = $party; }
    $rows = [];
    $totals = ['opening' => '0.0000', 'introduced' => '0.0000', 'drawings' => '0.0000', 'closing' => '0.0000', 'loan_closing' => '0.0000'];
    foreach (pl_list_owner_partners($actorId, $companyId, $bookId) as $partner) {
        $capital = pl_ownership_account_movement($companyId, $bookId, (int) $partner['capital_account_id'], $from, $to);
        $drawings = $partner['drawings_account_id'] === null
            ? ['opening' => '0.0000', 'debit' => '0.0000', 'credit' => '0.0000', 'closing' => '0.0000']
            : pl_ownership_account_movement($companyId, $bookId, (int) $partner['drawings_account_id'], $from, $to);
        $loan = $partner['loan_account_id'] === null
            ? ['opening' => '0.0000', 'debit' => '0.0000', 'credit' => '0.0000', 'closing' => '0.0000']
            : pl_ownership_account_movement($companyId, $bookId, (int) $partner['loan_account_id'], $from, $to);
        // Drawings sit in a contra-equity account and carry a debit balance, so the amount
        // withdrawn is the negative of the credit-positive figure above.
        $drawingsOpening = bcsub('0', $drawings['opening'], 4);
        $drawingsPeriod = bcsub($drawings['debit'], $drawings['credit'], 4);
        $drawingsClosing = bcsub('0', $drawings['closing'], 4);
        $personId = $byPartner[(int) $partner['id']] ?? null;
        $row = [
            'partner_id' => (int) $partner['id'], 'name' => (string) $partner['name'],
            'profit_share' => (string) $partner['profit_share'],
            'register_person' => $personId === null ? null : (string) ($people[$personId]['name'] ?? ''),
            'ownership_party_id' => $personId,
            'capital_opening' => $capital['opening'],
            'capital_introduced' => bcsub($capital['credit'], $capital['debit'], 4),
            'capital_closing' => $capital['closing'],
            'drawings_opening' => $drawingsOpening,
            'drawings_period' => $drawingsPeriod,
            'drawings_closing' => $drawingsClosing,
            // The fixed presentation: the capital account alone. The fluctuating presentation:
            // capital net of what has been withdrawn against it.
            'fixed_capital' => $capital['closing'],
            'fluctuating_capital' => bcsub($capital['closing'], $drawingsClosing, 4),
            'loan_opening' => $loan['opening'],
            'loan_advanced' => $loan['credit'],
            'loan_repaid' => $loan['debit'],
            'loan_closing' => $loan['closing'],
        ];
        $rows[] = $row;
        $totals['opening'] = bcadd($totals['opening'], $row['capital_opening'], 4);
        $totals['introduced'] = bcadd($totals['introduced'], $row['capital_introduced'], 4);
        $totals['drawings'] = bcadd($totals['drawings'], $row['drawings_period'], 4);
        $totals['closing'] = bcadd($totals['closing'], $row['capital_closing'], 4);
        $totals['loan_closing'] = bcadd($totals['loan_closing'], $row['loan_closing'], 4);
    }
    return ['from' => $from, 'to' => $to, 'currency' => (string) $book['currency'], 'partners' => $rows, 'totals' => $totals,
        'note' => 'Profit for the period is not allocated between partners here. Salary, interest on capital and the treatment of a loss are an agreement between the partners and an accounting policy decision.'];
}

/* ====================================== the related-party marker (B72 as narrowed by B74, B58) */

/** @return array<string,string> */
function pl_related_party_registers(): array
{
    return ['officer' => 'The directors and officers register', 'owner' => 'The members register',
        'employee' => 'The employee master'];
}

/**
 * The three relationships IAS 24 actually names.
 *
 * There is no "employee" here and there never will be. IAS 24 relates a person through control,
 * joint control, significant influence or membership of key management personnel, which includes
 * any director; a customer or a supplier is not related through economic dependence alone
 * (IAS 24.11), and ordinary employees are not a class of related party at all (B74).
 *
 * @return array<string,string>
 */
function pl_related_party_relationships(): array
{
    return [
        'key_management' => 'Key management personnel, which includes any director',
        'close_family_member' => 'A close family member of key management personnel',
        'controlled_entity' => 'An entity controlled or jointly controlled by either',
    ];
}

/**
 * The authority B58 reserves for sensitive data.
 *
 * A marker says that a named customer or supplier is a director, a director's spouse or a company
 * a director controls. That is exactly the kind of fact B58 keeps away from whoever manages
 * customers, so reading the register — and every report derived from it — asks for it.
 */
function pl_related_party_require_view(int $actorId, int $companyId): void
{
    pl_require_company_access($actorId, $companyId);
    if (!pl_user_can($actorId, $companyId, 'relatedparty.view')) {
        throw new DomainException('Related-party information is restricted. Your role cannot read it.');
    }
}

/**
 * Resolve which person a marker points at, per register.
 *
 * `related_id` has no foreign key: it points into a different table per register. This is the
 * one place that resolves it, and an unknown register is refused rather than guessed — which is
 * what let the employee register arrive without a schema change, exactly as B72 intended: the
 * `employee` branch below is 1.3 M17's entire change to this function, and to the related-party
 * mechanism as a whole. It reads `pl_employees`; it does not read employment status to decide
 * who is related, because nothing does that — a marker is only ever the affirmative act
 * `pl_save_related_party_marker()` records.
 *
 * @return array{name:string, detail:string}|null null when the register has no such row
 */
function pl_related_party_subject(int $companyId, string $register, int $relatedId, ?int $actorId = null): ?array
{
    if ($register === 'officer') {
        $row = DB::queryFirstRow('SELECT o.officer_role, o.appointed_on, o.resigned_on, p.name FROM pl_ownership_officers o
            JOIN pl_ownership_parties p ON p.id = o.ownership_party_id AND p.company_id = o.company_id
            WHERE o.id = %i AND o.company_id = %i', $relatedId, $companyId);
        if (!$row) { return null; }
        return ['name' => (string) $row['name'], 'detail' => (pl_officer_roles()[(string) $row['officer_role']] ?? (string) $row['officer_role'])
            . ', appointed ' . (string) $row['appointed_on'] . ($row['resigned_on'] === null ? '' : ', resigned ' . (string) $row['resigned_on'])];
    }
    if ($register === 'owner') {
        $row = DB::queryFirstRow('SELECT m.effective_from, m.effective_to, p.name FROM pl_ownership_members m
            JOIN pl_ownership_parties p ON p.id = m.ownership_party_id AND p.company_id = m.company_id
            WHERE m.id = %i AND m.company_id = %i', $relatedId, $companyId);
        if (!$row) { return null; }
        return ['name' => (string) $row['name'], 'detail' => 'Member from ' . (string) $row['effective_from']
            . ($row['effective_to'] === null ? '' : ' to ' . (string) $row['effective_to'])];
    }
    if ($register === 'employee') {
        $row = DB::queryFirstRow('SELECT full_name, job_title, employment_status, hire_date, termination_date FROM pl_employees
            WHERE id = %i AND company_id = %i', $relatedId, $companyId);
        if (!$row) { return null; }
        if ($actorId === null || !pl_user_can($actorId, $companyId, 'employee.view')) {
            return ['name' => (string) $row['full_name'], 'detail' => ''];
        }
        $status = pl_employment_statuses()[(string) $row['employment_status']] ?? (string) $row['employment_status'];
        $detail = ((string) $row['job_title'] !== '' ? (string) $row['job_title'] . ', ' : '') . $status
            . ' since ' . (string) $row['hire_date'] . ($row['termination_date'] === null ? '' : ', ended ' . (string) $row['termination_date']);
        return ['name' => (string) $row['full_name'], 'detail' => $detail];
    }
    throw new DomainException('Unknown related-party register: ' . $register);
}

/**
 * Every marker, with the person each one points at.
 *
 * @return array<int,array<string,mixed>>
 */
function pl_list_related_party_markers(int $actorId, int $companyId, ?string $asOf = null): array
{
    pl_related_party_require_view($actorId, $companyId);
    $sql = 'SELECT k.*, p.legal_name, p.trading_name, p.is_customer, p.is_vendor FROM pl_related_party_markers k
        JOIN pl_parties p ON p.id = k.party_id AND p.company_id = k.company_id
        WHERE k.company_id = %i';
    $args = [$companyId];
    if ($asOf !== null) {
        pl_ledger_date($asOf);
        $sql .= ' AND k.effective_from <= %s AND (k.effective_to IS NULL OR k.effective_to >= %s)';
        $args[] = $asOf;
        $args[] = $asOf;
    }
    $rows = DB::query($sql . ' ORDER BY p.legal_name, k.effective_from', ...$args);
    $markers = [];
    foreach ($rows as $row) {
        foreach (['id', 'company_id', 'party_id', 'related_id', 'revision', 'created_by'] as $field) { $row[$field] = (int) $row[$field]; }
        $row['is_customer'] = (bool) $row['is_customer'];
        $row['is_vendor'] = (bool) $row['is_vendor'];
        $row['relationship_label'] = pl_related_party_relationships()[(string) $row['relationship']] ?? (string) $row['relationship'];
        $row['register_label'] = pl_related_party_registers()[(string) $row['related_register']] ?? (string) $row['related_register'];
        $subject = pl_related_party_subject($companyId, (string) $row['related_register'], $row['related_id'], $actorId);
        $row['subject_name'] = $subject['name'] ?? '';
        $row['subject_detail'] = $subject['detail'] ?? '';
        $markers[] = $row;
    }
    return $markers;
}

/**
 * Record or amend a marker. This is the affirmative designation B74 requires.
 *
 * Nothing else in this file writes to `pl_related_party_markers`. No appointment, no
 * significant-control flag, no membership interest and no future employment link creates one, and
 * there is no default-related state anywhere in the schema: a person is related because somebody
 * with the authority said so, on a date, with a reason recorded.
 *
 * @param array<string,mixed> $input untrusted request data
 */
function pl_save_related_party_marker(int $actorId, int $companyId, array $input, ?int $id = null, ?int $revision = null): array
{
    pl_demo_require_setup_action();
    $reason = pl_ledger_text($input['reason'] ?? null, 'Reason for this change', 500);
    $partyId = $input['party_id'] ?? null;
    if (!is_int($partyId) || $partyId < 1) { throw new DomainException('Choose the customer or supplier this marker is about.'); }
    $register = is_string($input['related_register'] ?? null) ? $input['related_register'] : '';
    if (!isset(pl_related_party_registers()[$register])) { throw new DomainException('Choose which register holds the person.'); }
    $relatedId = $input['related_id'] ?? null;
    if (!is_int($relatedId) || $relatedId < 1) { throw new DomainException('Choose the person in that register.'); }
    $relationship = is_string($input['relationship'] ?? null) ? $input['relationship'] : '';
    if (!isset(pl_related_party_relationships()[$relationship])) {
        throw new DomainException('Choose how this party is related. An ordinary employee is not a related party; only key management personnel, a close family member of one, or an entity either controls.');
    }
    $from = pl_ledger_date(pl_ledger_text($input['effective_from'] ?? null, 'Related from', 10));
    $toRaw = pl_ledger_text($input['effective_to'] ?? '', 'Related to', 10, false);
    $to = $toRaw === '' ? null : pl_ledger_date($toRaw);
    if ($to !== null && $to < $from) { throw new DomainException('A relationship cannot end before it begins.'); }
    $note = pl_ledger_text($input['note'] ?? '', 'Note', 500, false);
    return pl_ledger_transaction(function () use ($actorId, $companyId, $partyId, $register, $relatedId, $relationship, $from, $to, $note, $id, $revision, $reason): array {
        pl_require_company_access($actorId, $companyId, true);
        if (!pl_user_can($actorId, $companyId, 'relatedparty.manage')) {
            throw new DomainException('Your role cannot record a related-party marker.');
        }
        $party = DB::queryFirstRow('SELECT id, legal_name FROM pl_parties WHERE id = %i AND company_id = %i FOR SHARE', $partyId, $companyId);
        if (!$party) { throw new DomainException('That customer or supplier is not in this company.'); }
        $subject = pl_related_party_subject($companyId, $register, $relatedId);
        if ($subject === null) { throw new DomainException('That person is not in the register you chose.'); }
        $data = ['party_id' => $partyId, 'related_register' => $register, 'related_id' => $relatedId,
            'relationship' => $relationship, 'effective_from' => $from, 'effective_to' => $to, 'note' => $note];
        if ($id === null) {
            DB::insert('pl_related_party_markers', $data + ['company_id' => $companyId, 'created_by' => $actorId]);
            $id = (int) DB::insertId();
            $before = null;
        } else {
            $existing = DB::queryFirstRow('SELECT * FROM pl_related_party_markers WHERE id = %i AND company_id = %i FOR UPDATE', $id, $companyId);
            if (!$existing) { throw new DomainException('That marker is not in this company\'s register.'); }
            if ($revision !== (int) $existing['revision']) {
                throw new DomainException('Someone changed this marker. Reload the latest version before applying your changes.');
            }
            $before = $existing;
            DB::update('pl_related_party_markers', $data + ['revision' => (int) $existing['revision'] + 1], 'id = %i AND company_id = %i', $id, $companyId);
        }
        $after = DB::queryFirstRow('SELECT * FROM pl_related_party_markers WHERE id = %i AND company_id = %i', $id, $companyId) ?? [];
        pl_ownership_audit($actorId, $companyId, 'related_party_marker', $id, $before === null ? 'marked' : 'updated', $reason, $before, $after
            + ['party' => (string) $party['legal_name'], 'subject' => $subject['name']]);
        pl_ownership_emit('ownership.related_party.marked', ['company_id' => $companyId, 'marker' => $after]);
        return $after;
    });
}

/**
 * Trade parties that look like a person in a register and carry no marker.
 *
 * This is the cross-check B74 and B75 call for, applied here rather than to the employee master
 * that does not exist yet: the ACFE and COSO anti-fraud tests match one register against another
 * on name, identifier and address and treat a hit as **a flag to investigate, not a forbidden
 * state**. It is also what makes an affirmative designation workable — a director who is also a
 * supplier is exactly the marker somebody forgets to record.
 *
 * It marks nothing. It returns candidates for a human to accept or dismiss.
 *
 * @return array<int,array<string,mixed>>
 */
function pl_related_party_candidates(int $actorId, int $companyId): array
{
    pl_related_party_require_view($actorId, $companyId);
    $marked = [];
    foreach (DB::query('SELECT party_id FROM pl_related_party_markers WHERE company_id = %i', $companyId) as $row) {
        $marked[(int) $row['party_id']] = true;
    }
    $normalise = static fn (string $value): string => (string) preg_replace('/[^a-z0-9]+/', '', mb_strtolower(trim($value), 'UTF-8'));
    $register = [];
    foreach (pl_list_ownership_officers($actorId, $companyId) as $officer) {
        $register[] = ['register' => 'officer', 'related_id' => $officer['id'], 'name' => (string) $officer['name'],
            'detail' => (string) $officer['role_label'], 'party_id' => $officer['ownership_party_id']];
    }
    foreach (pl_list_ownership_members($actorId, $companyId) as $member) {
        $register[] = ['register' => 'owner', 'related_id' => $member['id'], 'name' => (string) $member['name'],
            'detail' => 'Member', 'party_id' => $member['ownership_party_id']];
    }
    $identifiers = [];
    foreach (DB::query('SELECT id, identifier, address FROM pl_ownership_parties WHERE company_id = %i', $companyId) as $row) {
        $identifiers[(int) $row['id']] = ['identifier' => $normalise((string) $row['identifier']), 'address' => $normalise((string) $row['address'])];
    }
    // Every registered identifier a trade party carries — a tax number, a national identity
    // number, a company number — normalised the same way the register's own identifier is. This
    // is the "tax identifier" leg of the ACFE test; the address leg is the third.
    $partyIdentifiers = [];
    foreach (DB::query('SELECT party_id, normalized_value FROM pl_party_identifiers WHERE company_id = %i', $companyId) as $row) {
        $partyIdentifiers[(int) $row['party_id']][] = $normalise((string) $row['normalized_value']);
    }
    $candidates = [];
    foreach (DB::query('SELECT id, legal_name, trading_name, is_customer, is_vendor FROM pl_parties WHERE company_id = %i ORDER BY legal_name', $companyId) as $party) {
        $partyId = (int) $party['id'];
        if (isset($marked[$partyId])) { continue; }
        $names = [$normalise((string) $party['legal_name']), $normalise((string) $party['trading_name'])];
        foreach ($register as $entry) {
            $attributes = $identifiers[$entry['party_id']] ?? ['identifier' => '', 'address' => ''];
            $matched = null;
            if ($normalise($entry['name']) !== '' && in_array($normalise($entry['name']), $names, true)) { $matched = 'name'; }
            if ($matched === null && $attributes['identifier'] !== ''
                && in_array($attributes['identifier'], $partyIdentifiers[$partyId] ?? [], true)) {
                $matched = 'identifier';
            }
            if ($matched === null) { continue; }
            $candidates[] = [
                'party_id' => $partyId, 'legal_name' => (string) $party['legal_name'],
                'is_customer' => (bool) $party['is_customer'], 'is_vendor' => (bool) $party['is_vendor'],
                'related_register' => $entry['register'], 'related_id' => $entry['related_id'],
                'subject_name' => $entry['name'], 'subject_detail' => $entry['detail'], 'matched_on' => $matched,
            ];
        }
    }
    return $candidates;
}

/**
 * The IAS 24.18 artefact: every transaction with a marked party in the period, with the amounts,
 * the outstanding balance, the terms and what the chart records about provisions.
 *
 * The movement itself comes from `pl_party_statement()`, which is the same control-account
 * movement the party's own statement shows; there is no second computation of a balance here.
 *
 * **Provisions are reported honestly.** PHP Ledger has no per-party allowance for doubtful debts,
 * so the per-party provision is `null` and the chart's contra-asset accounts are listed instead,
 * with their balances, for the preparer to allocate. Inventing a per-party figure would be worse
 * than saying the software does not hold one.
 */
function pl_related_party_transactions(int $actorId, int $companyId, int $bookId, string $from, string $to): array
{
    pl_related_party_require_view($actorId, $companyId);
    $book = pl_ledger_book($companyId, $bookId);
    pl_ledger_date($from);
    pl_ledger_date($to);
    if ($from > $to) { throw new DomainException('The report must begin on or before it ends.'); }
    $markers = DB::query('SELECT * FROM pl_related_party_markers WHERE company_id = %i
        AND effective_from <= %s AND (effective_to IS NULL OR effective_to >= %s) ORDER BY party_id', $companyId, $to, $from);
    $parties = [];
    foreach ($markers as $marker) {
        $partyId = (int) $marker['party_id'];
        $register = (string) $marker['related_register'];
        $subject = pl_related_party_subject($companyId, $register, (int) $marker['related_id'], $actorId);
        $parties[$partyId]['relationships'][] = [
            'relationship' => (string) $marker['relationship'],
            'relationship_label' => pl_related_party_relationships()[(string) $marker['relationship']] ?? (string) $marker['relationship'],
            'register' => $register, 'subject_name' => $subject['name'] ?? '', 'subject_detail' => $subject['detail'] ?? '',
            'effective_from' => (string) $marker['effective_from'], 'effective_to' => $marker['effective_to'] === null ? null : (string) $marker['effective_to'],
            'note' => (string) $marker['note'],
        ];
    }
    $rows = [];
    $totals = ['movement' => '0.0000', 'outstanding' => '0.0000'];
    foreach ($parties as $partyId => $detail) {
        $statement = pl_party_statement($actorId, $companyId, $bookId, $partyId, $from, $to);
        $terms = DB::queryFirstField('SELECT payment_terms FROM pl_party_financial_profiles WHERE party_id = %i AND book_id = %i', $partyId, $bookId);
        $movement = '0.0000';
        foreach ($statement['rows'] as $line) { $movement = bcadd($movement, (string) $line['amount_base'], 4); }
        $rows[] = [
            'party_id' => $partyId,
            'legal_name' => (string) $statement['party']['legal_name'],
            'direction' => (string) $statement['direction'],
            'relationships' => $detail['relationships'],
            'opening_balance' => (string) $statement['opening_balance'],
            'closing_balance' => (string) $statement['closing_balance'],
            'invoiced' => (string) $statement['invoiced'],
            'settled' => (string) $statement['received'],
            'unapplied' => (string) $statement['unapplied'],
            'movement' => $movement,
            'transactions' => $statement['rows'],
            'terms' => is_string($terms) && $terms !== '' ? $terms : '',
            // IAS 24.18(d) asks for the provision for doubtful debts on related-party balances.
            // Nothing in PHP Ledger records an allowance against one party, so this is null and
            // says why, rather than being a figure nobody computed.
            'provision' => null,
        ];
        $totals['movement'] = bcadd($totals['movement'], $movement, 4);
        $totals['outstanding'] = bcadd($totals['outstanding'], (string) $statement['closing_balance'], 4);
    }
    usort($rows, static fn (array $a, array $b): int => $a['legal_name'] <=> $b['legal_name']);
    return ['from' => $from, 'to' => $to, 'currency' => (string) $book['currency'], 'parties' => $rows, 'totals' => $totals,
        'provision_accounts' => pl_ownership_provision_accounts($actorId, $companyId, $bookId, $to),
        'provision_note' => 'PHP Ledger records no allowance against an individual party, so no per-party provision is shown. The contra-asset accounts in this book are listed with their balances so the preparer can state the provision the disclosure asks for.'];
}

/**
 * The contra-asset accounts a book carries, with their balances. These are where a provision for
 * doubtful debts lives if the chart has one.
 *
 * @return array<int,array<string,mixed>>
 */
function pl_ownership_provision_accounts(int $actorId, int $companyId, int $bookId, string $asOf): array
{
    $accounts = [];
    foreach (pl_trial_balance($actorId, $companyId, $bookId, $asOf)['accounts'] as $account) {
        if ((string) $account['type'] !== 'asset' || !(bool) ($account['is_contra'] ?? false)) { continue; }
        $accounts[] = ['id' => (int) $account['id'], 'code' => (string) $account['code'], 'name' => (string) $account['name'],
            'balance' => bcsub('0', (string) $account['balance'], 4)];
    }
    return $accounts;
}

/**
 * The director loan movement report: opening, advanced, repaid, closing.
 *
 * One artefact satisfies two disclosures (B74). Pakistan's Fourth Schedule requires a movement
 * reconciliation of loans and advances to directors; Companies Act 2006 s.413 requires advances
 * and credits granted to directors to be disclosed in a note. The direction is read from the
 * account's own type rather than assumed, because PHP Ledger's B61 partner loan account is
 * ordinarily a liability — money the *director* lent the business — while the disclosure both
 * regimes are written around is the asset direction. The report states which it is for each
 * director instead of forcing one reading on the other.
 *
 * The population is the officers register, filtered to directorships, over the period; the
 * account is the loan account on the B61 partner record the person is linked to in this book.
 * A director with no linked partner record is listed with no figures and a reason, because an
 * empty row that says why is a finding and a missing row is not.
 */
function pl_director_loan_movements(int $actorId, int $companyId, int $bookId, string $from, string $to): array
{
    pl_related_party_require_view($actorId, $companyId);
    $book = pl_ledger_book($companyId, $bookId);
    pl_ledger_date($from);
    pl_ledger_date($to);
    if ($from > $to) { throw new DomainException('The report must begin on or before it ends.'); }
    $links = pl_ownership_partner_links($companyId, $bookId);
    $partners = [];
    foreach (pl_list_owner_partners($actorId, $companyId, $bookId) as $partner) { $partners[(int) $partner['id']] = $partner; }
    $rows = [];
    $totals = ['opening' => '0.0000', 'advanced' => '0.0000', 'repaid' => '0.0000', 'closing' => '0.0000'];
    foreach (DB::query('SELECT o.*, p.name FROM pl_ownership_officers o
        JOIN pl_ownership_parties p ON p.id = o.ownership_party_id AND p.company_id = o.company_id
        WHERE o.company_id = %i AND o.officer_role IN %ls AND o.appointed_on <= %s AND (o.resigned_on IS NULL OR o.resigned_on >= %s)
        ORDER BY p.name', $companyId, pl_officer_director_roles(), $to, $from) as $officer) {
        $personId = (int) $officer['ownership_party_id'];
        $partnerId = $links[$personId] ?? null;
        $partner = $partnerId === null ? null : ($partners[$partnerId] ?? null);
        $loanAccountId = $partner === null ? null : ($partner['loan_account_id'] === null ? null : (int) $partner['loan_account_id']);
        if ($loanAccountId === null) {
            $rows[] = ['name' => (string) $officer['name'], 'role' => pl_officer_roles()[(string) $officer['officer_role']] ?? (string) $officer['officer_role'],
                'account' => null, 'direction' => null, 'opening' => null, 'advanced' => null, 'repaid' => null, 'closing' => null,
                'reason' => $partner === null
                    ? 'This director is not linked to a partner record in this book, so no loan account is known for them.'
                    : 'This director\'s partner record has no loan account, so no loan movement can be reported.'];
            continue;
        }
        $account = pl_get_account($actorId, $companyId, $bookId, $loanAccountId);
        $movement = pl_ownership_account_movement($companyId, $bookId, $loanAccountId, $from, $to);
        $liability = (string) $account['type'] === 'liability';
        // Credit-positive throughout the helper. A liability is owed *to* the director, so its
        // credit balance is the amount outstanding; an asset is owed *by* the director, so the
        // outstanding amount is the debit balance, which is the negative of the same figure.
        $sign = static fn (string $value): string => $liability ? $value : bcsub('0', $value, 4);
        $rows[] = [
            'name' => (string) $officer['name'],
            'role' => pl_officer_roles()[(string) $officer['officer_role']] ?? (string) $officer['officer_role'],
            'account' => ['id' => $loanAccountId, 'code' => (string) $account['code'], 'name' => (string) $account['name'], 'type' => (string) $account['type']],
            'direction' => $liability ? 'owed_to_director' : 'owed_by_director',
            'direction_label' => $liability ? 'Owed by the company to the director' : 'Owed by the director to the company',
            'opening' => $sign($movement['opening']),
            'advanced' => $liability ? $movement['credit'] : $movement['debit'],
            'repaid' => $liability ? $movement['debit'] : $movement['credit'],
            'closing' => $sign($movement['closing']),
            'reason' => null,
        ];
        $totals['opening'] = bcadd($totals['opening'], $sign($movement['opening']), 4);
        $totals['advanced'] = bcadd($totals['advanced'], $liability ? $movement['credit'] : $movement['debit'], 4);
        $totals['repaid'] = bcadd($totals['repaid'], $liability ? $movement['debit'] : $movement['credit'], 4);
        $totals['closing'] = bcadd($totals['closing'], $sign($movement['closing']), 4);
    }
    return ['from' => $from, 'to' => $to, 'currency' => (string) $book['currency'], 'directors' => $rows, 'totals' => $totals,
        'reconciles' => bccomp($totals['closing'], bcadd($totals['opening'], bcsub($totals['advanced'], $totals['repaid'], 4), 4), 4) === 0];
}

/* ============================================================== the Open Cap Format export (#92)
 *
 * The Open Cap Table Coalition's format so the register can move to or from other tools.
 *
 * Honest scope: this emits the OCF *objects PHP Ledger holds* — the issuer, the stakeholders, the
 * stock classes and the issuance, transfer, cancellation and class-conversion transactions — in
 * the OCF shape, with OCF object types, id prefixes and its `{amount, currency}` monetary shape.
 * It is not a claim of full OCF conformance: PHP Ledger has no stock plans, vesting terms,
 * valuations, warrants or convertibles to emit, so those collections are present and empty rather
 * than fabricated. `pl_ownership_ocf_holdings()` reads the document back, and the round-trip test
 * proves the exported transactions reproduce the exported holdings exactly.
 */

/** @return array<string,mixed> */
function pl_ownership_export_ocf(int $actorId, int $companyId, string $asOf): array
{
    pl_require_company_access($actorId, $companyId);
    pl_ledger_date($asOf);
    $company = pl_company_context($actorId, $companyId);
    $profile = pl_company_profile($actorId, $companyId);
    $classes = pl_list_share_classes($actorId, $companyId, $asOf);
    $classById = [];
    foreach ($classes as $class) { $classById[$class['id']] = $class; }
    $stakeholders = [];
    foreach (pl_list_ownership_parties($actorId, $companyId) as $party) {
        $stakeholders[] = [
            'id' => 'stakeholder-' . $party['id'],
            'object_type' => 'STAKEHOLDER',
            'name' => ['legal_name' => (string) $party['name']],
            'stakeholder_type' => $party['kind'] === 'person' ? 'INDIVIDUAL' : 'INSTITUTION',
            'issuer_assigned_id' => (string) $party['identifier'],
            'primary_contact' => $party['email'] === '' ? null : ['email_address' => (string) $party['email']],
        ];
    }
    $stockClasses = [];
    foreach ($classes as $class) {
        $stockClasses[] = [
            'id' => 'stock-class-' . $class['id'],
            'object_type' => 'STOCK_CLASS',
            'name' => (string) $class['name'],
            'class_type' => strtoupper((string) $class['class_type']),
            'default_id_prefix' => (string) $class['code'],
            'initial_shares_authorized' => $class['authorised_shares'] === null ? 'UNLIMITED' : (string) $class['authorised_shares'],
            'votes_per_share' => (string) $class['votes_per_share'],
            'par_value' => ['amount' => (string) $class['nominal_value'], 'currency' => (string) $class['currency']],
            'seniority' => (string) $class['class_type'] === 'preferred' ? 1 : 2,
        ];
    }
    $transactions = [];
    foreach (pl_share_ledger_effects($companyId, $asOf) as $entry) {
        $event = $entry['event'];
        // A reversal removes its target's effect; neither the target nor the reversal is a
        // transaction that happened, so the export carries the register as it stands.
        if ($event['reversal_of_id'] !== null) { continue; }
        if (pl_share_event_reversed_by($companyId, (int) $event['id'], $asOf)) { continue; }
        $transactions[] = pl_ownership_ocf_transaction($event, $classById);
    }
    return [
        'ocf_version' => '1.0.0',
        'generated_at' => gmdate('c'),
        'as_of' => $asOf,
        'scope' => 'The issuer, stakeholders, stock classes and share ledger PHP Ledger holds. Stock plans, vesting terms, valuations, warrants and convertibles are not modelled and are exported empty rather than invented.',
        'issuer' => [
            'id' => 'issuer-' . $companyId,
            'object_type' => 'ISSUER',
            'legal_name' => (string) ($profile['legal_name'] !== '' ? $profile['legal_name'] : $company['name']),
            'country_of_formation' => (string) ($company['country_code'] ?? ''),
            'formation_date' => $profile['incorporation_date'] ?? null,
            'initial_shares_authorized' => null,
            'legal_form' => (string) $profile['legal_form'],
            'registration_number' => (string) $profile['registration_number'],
            'registration_authority' => (string) $profile['registration_authority'],
        ],
        'stakeholders' => $stakeholders,
        'stock_classes' => $stockClasses,
        'stock_plans' => [],
        'stock_legend_templates' => [],
        'vesting_terms' => [],
        'valuations' => [],
        'transactions' => $transactions,
    ];
}

/** Has this event been corrected by a reversal that is itself in effect on or before a date? */
function pl_share_event_reversed_by(int $companyId, int $eventId, string $asOf): bool
{
    return DB::queryFirstField('SELECT id FROM pl_share_events WHERE company_id = %i AND reversal_of_id = %i AND effective_date <= %s LIMIT 1',
        $companyId, $eventId, $asOf) !== null;
}

/**
 * One share ledger event as an OCF transaction object.
 *
 * @param array<string,mixed> $event
 * @param array<int,array<string,mixed>> $classById
 * @return array<string,mixed>
 */
function pl_ownership_ocf_transaction(array $event, array $classById): array
{
    $id = (int) $event['id'];
    $classId = (int) $event['share_class_id'];
    $quantity = bcadd((string) $event['quantity'], '0', 6);
    $base = ['id' => 'tx-' . $id, 'date' => (string) $event['effective_date'], 'quantity' => $quantity];
    $currency = (string) ($classById[$classId]['currency'] ?? 'USD');
    return match ((string) $event['event_type']) {
        'allotment', 'bonus_issue' => $base + [
            'object_type' => 'TX_STOCK_ISSUANCE',
            'stock_class_id' => 'stock-class-' . $classId,
            'stakeholder_id' => 'stakeholder-' . (int) $event['to_party_id'],
            'share_price' => ['amount' => bccomp($quantity, '0', 6) === 0 ? '0.0000'
                : bcdiv((string) ($event['consideration_amount'] ?? '0'), $quantity, 6), 'currency' => $currency],
            'consideration_text' => (string) $event['event_type'] === 'bonus_issue' ? 'Bonus issue: a reserve capitalised, no consideration passed' : '',
            'security_id' => 'security-' . $id,
            'custom_id' => (string) $event['certificate_reference'],
        ],
        'transfer' => $base + [
            'object_type' => 'TX_STOCK_TRANSFER',
            'stock_class_id' => 'stock-class-' . $classId,
            'stakeholder_id' => 'stakeholder-' . (int) $event['from_party_id'],
            'resulting_stakeholder_id' => 'stakeholder-' . (int) $event['to_party_id'],
            'security_id' => 'security-' . $id,
            'balance_security_id' => null,
        ],
        'cancellation' => $base + [
            'object_type' => 'TX_STOCK_CANCELLATION',
            'stock_class_id' => 'stock-class-' . $classId,
            'stakeholder_id' => 'stakeholder-' . (int) $event['from_party_id'],
            'security_id' => 'security-' . $id,
            'reason_text' => (string) $event['reason'],
        ],
        'redesignation' => $base + [
            'object_type' => 'TX_STOCK_CLASS_CONVERSION',
            'stock_class_id' => 'stock-class-' . $classId,
            'resulting_stock_class_id' => 'stock-class-' . (int) $event['to_share_class_id'],
            'stakeholder_id' => 'stakeholder-' . (int) $event['from_party_id'],
            'security_id' => 'security-' . $id,
        ],
        default => throw new DomainException('Unsupported share ledger event.'),
    };
}

/**
 * Read an Open Cap Format document back into holdings, so the export can be proved rather than
 * asserted.
 *
 * Deliberately a reader and not an importer: it writes nothing, so a round-trip test can run it
 * against an untrusted fixture. It is also what a receiving tool has to be able to do with what
 * we emit, so if this cannot reproduce the holdings, the export is wrong.
 *
 * @param array<string,mixed> $document
 * @return array<string,array<string,string>> stock class id => [stakeholder id => shares]
 */
function pl_ownership_ocf_holdings(array $document): array
{
    $positions = [];
    $transactions = $document['transactions'] ?? [];
    if (!is_array($transactions)) { throw new DomainException('This document carries no transactions array.'); }
    $apply = static function (array &$positions, string $class, string $holder, string $delta): void {
        $positions[$class][$holder] = bcadd($positions[$class][$holder] ?? '0.000000', $delta, 6);
    };
    foreach ($transactions as $transaction) {
        if (!is_array($transaction)) { throw new DomainException('Every transaction must be an object.'); }
        $type = (string) ($transaction['object_type'] ?? '');
        $quantity = bcadd((string) ($transaction['quantity'] ?? '0'), '0', 6);
        $class = (string) ($transaction['stock_class_id'] ?? '');
        $holder = (string) ($transaction['stakeholder_id'] ?? '');
        switch ($type) {
            case 'TX_STOCK_ISSUANCE':
                $apply($positions, $class, $holder, $quantity);
                break;
            case 'TX_STOCK_TRANSFER':
                $apply($positions, $class, $holder, bcsub('0', $quantity, 6));
                $apply($positions, $class, (string) ($transaction['resulting_stakeholder_id'] ?? ''), $quantity);
                break;
            case 'TX_STOCK_CANCELLATION':
                $apply($positions, $class, $holder, bcsub('0', $quantity, 6));
                break;
            case 'TX_STOCK_CLASS_CONVERSION':
                $apply($positions, $class, $holder, bcsub('0', $quantity, 6));
                $apply($positions, (string) ($transaction['resulting_stock_class_id'] ?? ''), $holder, $quantity);
                break;
            default:
                throw new DomainException('Unsupported Open Cap Format transaction type: ' . $type);
        }
    }
    foreach ($positions as $class => $holders) {
        foreach ($holders as $holder => $shares) {
            if (bccomp($shares, '0', 6) === 0) { unset($positions[$class][$holder]); }
        }
        if ($positions[$class] === []) { unset($positions[$class]); }
    }
    return $positions;
}

/** The export as a downloadable file. */
function pl_ownership_export_file(int $actorId, int $companyId, string $asOf): array
{
    $document = pl_ownership_export_ocf($actorId, $companyId, $asOf);
    $json = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    return ['filename' => 'phpledger-ownership-ocf-company-' . $companyId . '-' . $asOf . '.json', 'json' => $json];
}
