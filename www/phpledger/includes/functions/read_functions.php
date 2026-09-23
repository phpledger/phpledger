<?php
declare(strict_types=1);

function pl_table_size(mixed $size): int
{
    if (!is_int($size) || !in_array($size, [25, 50, 100], true)) {
        throw new DomainException('Choose 25, 50 or 100 rows per page.');
    }
    return $size;
}

/** Complete SQL order clauses are fixed source constants, selected by validated keys. */
function pl_table_order(array $options, string $screen): string
{
    $orders = [
        'transactions' => ['default' => 'd.document_date DESC, d.id ASC',
            'date' => ['asc' => 'd.document_date ASC, d.id ASC', 'desc' => 'd.document_date DESC, d.id ASC'],
            'name' => ['asc' => 'd.counterparty ASC, d.id ASC', 'desc' => 'd.counterparty DESC, d.id ASC'],
            'amount' => ['asc' => 'd.amount ASC, d.id ASC', 'desc' => 'd.amount DESC, d.id ASC'],
            'status' => ['asc' => 'CASE WHEN d.journal_id IS NULL THEN \'draft\' WHEN r.id IS NOT NULL THEN \'reversed\' ELSE \'posted\' END ASC, d.id ASC', 'desc' => 'CASE WHEN d.journal_id IS NULL THEN \'draft\' WHEN r.id IS NOT NULL THEN \'reversed\' ELSE \'posted\' END DESC, d.id ASC'],
        ],
        'general-journals' => ['default' => 'd.document_date DESC, d.id DESC',
            'date' => ['asc' => 'd.document_date ASC, d.id DESC', 'desc' => 'd.document_date DESC, d.id DESC'],
            'description' => ['asc' => 'd.description ASC, d.id DESC', 'desc' => 'd.description DESC, d.id DESC'],
            'status' => ['asc' => 'CASE WHEN d.journal_id IS NULL THEN \'draft\' WHEN r.id IS NOT NULL THEN \'reversed\' ELSE \'posted\' END ASC, d.id DESC', 'desc' => 'CASE WHEN d.journal_id IS NULL THEN \'draft\' WHEN r.id IS NOT NULL THEN \'reversed\' ELSE \'posted\' END DESC, d.id DESC'],
        ],
        'account' => ['default' => 'q.date, q.journal_id, q.line_number',
            'date' => ['asc' => 'q.date ASC, q.date, q.journal_id, q.line_number', 'desc' => 'q.date DESC, q.date, q.journal_id, q.line_number'],
            'journal' => ['asc' => 'q.journal_id ASC, q.date, q.journal_id, q.line_number', 'desc' => 'q.journal_id DESC, q.date, q.journal_id, q.line_number'],
            'description' => ['asc' => 'q.description ASC, q.date, q.journal_id, q.line_number', 'desc' => 'q.description DESC, q.date, q.journal_id, q.line_number'],
            'source' => ['asc' => 'q.source_reference ASC, q.date, q.journal_id, q.line_number', 'desc' => 'q.source_reference DESC, q.date, q.journal_id, q.line_number'],
            'debit' => ['asc' => 'q.debit ASC, q.date, q.journal_id, q.line_number', 'desc' => 'q.debit DESC, q.date, q.journal_id, q.line_number'],
            'credit' => ['asc' => 'q.credit ASC, q.date, q.journal_id, q.line_number', 'desc' => 'q.credit DESC, q.date, q.journal_id, q.line_number'],
            'balance' => ['asc' => 'q.running_movement ASC, q.date, q.journal_id, q.line_number', 'desc' => 'q.running_movement DESC, q.date, q.journal_id, q.line_number'],
        ],
        'bank' => ['default' => 'r.line_number',
            'date' => ['asc' => 'r.transaction_date ASC, r.line_number', 'desc' => 'r.transaction_date DESC, r.line_number'],
            'reference' => ['asc' => 'r.reference ASC, r.line_number', 'desc' => 'r.reference DESC, r.line_number'],
            'money_in' => ['asc' => 'r.money_in ASC, r.line_number', 'desc' => 'r.money_in DESC, r.line_number'],
            'money_out' => ['asc' => 'r.money_out ASC, r.line_number', 'desc' => 'r.money_out DESC, r.line_number'],
            'match' => ['asc' => 'm.journal_line_id ASC, r.line_number', 'desc' => 'm.journal_line_id DESC, r.line_number'],
        ],
    ];
    if (!isset($orders[$screen])) { throw new DomainException('Unknown list order.'); }
    if (!isset($options['sort'])) { return $orders[$screen]['default']; }
    $sort=$options['sort']; $direction=$options['direction']??'';
    if (!is_string($sort) || !is_string($direction) || $sort==='default' || !isset($orders[$screen][$sort][$direction])) { throw new DomainException('Unsupported table order.'); }
    return $orders[$screen][$sort][$direction];
}

/** The same allowlist drives API validation, MCP schemas and the independent OpenAPI file. */
function pl_read_catalog(): array
{
    $id = ['type' => 'integer', 'minimum' => 1, 'maximum' => 9007199254740991];
    $date = ['type' => 'string', 'format' => 'date', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$', 'maxLength' => 10];
    $scope = ['company_id' => $id, 'book_id' => $id];
    $paging = ['page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100000, 'default' => 1], 'page_size' => ['type' => 'integer', 'enum' => [25, 50, 100], 'default' => 25]];
    $definitions = [
        'companies' => ['List the company/book pairs explicitly authorized by this connection.', [], [], true],
        'capabilities' => ['Read available reporting and module capabilities for a selected book.', [], [], false],
        'accounts' => ['Read the chart of accounts. Amounts in reports use exact decimal strings.', [], [], true],
        'transactions' => ['Read receipt and expense sources, including unposted drafts. Only posted sources affect reports.', ['from' => $date, 'to' => $date, 'status' => ['type' => 'string', 'enum' => ['all','draft','posted','reversed']], 'kind' => ['type' => 'string', 'enum' => ['all','receipt','expense']], 'search' => ['type' => 'string', 'maxLength' => 160]], [], true],
        'general_journals' => ['Read general journal source summaries; follow an id with source_detail.', [], [], true],
        'journal' => ['Read an immutable posted journal and its source reference.', ['journal_id' => $id], ['journal_id'], true],
        'source_detail' => ['Read a receipt, expense or general journal source, including its posted journal link.', ['source_id' => $id, 'source_type' => ['type' => 'string', 'enum' => ['transaction','general_journal']]], ['source_id','source_type'], true],
        'trial_balance' => ['Read the trial balance at a business date. Totals cover the whole report on every page.', ['as_of' => $date], ['as_of'], true],
        'profit_loss' => ['Read posted income, expenses and net profit for an inclusive period. Totals cover all pages.', ['from' => $date, 'to' => $date], ['from','to'], true],
        'balance_sheet' => ['Read assets, liabilities and equity at a date, with unclosed earnings shown separately. Totals cover all pages.', ['as_of' => $date], ['as_of'], true],
        'account_statement' => ['Read opening, debit, credit, running and closing balances with journal/source links. Running balances follow canonical date/journal/line order before pagination.', ['account_id' => $id, 'from' => $date, 'as_of' => $date], ['account_id','from','as_of'], true],
        // Stock locations (owner decision B36). Reads only, and, like every other stock read,
        // available whether or not the optional locations module is currently enabled.
        'warehouses' => ['Read stock locations: warehouses and vans, with the van\'s driver, vehicle and route. Includes inactive locations so historical stock stays readable.', [], [], true],
        'stock_transfers' => ['Read stock transfers between locations as matched out/in pairs at carrying value, with the stock document number when one raised them. Transfers post no journal.', ['warehouse_id' => $id, 'from' => $date, 'to' => $date], [], true],
        'unapplied_credit' => ['Read unapplied customer or supplier credit (advances) at a date, with the advances control it reconciles to. Never netted against receivables or payables.', ['side' => ['type' => 'string', 'enum' => ['customer','supplier'], 'default' => 'customer'], 'as_of' => $date, 'party_id' => $id], [], true],
        // The ownership register (B63, issue #92). These two are the read surface a country
        // company-secretarial plugin needs to produce statutory forms and a filing calendar
        // without carrying a second register.
        //
        // The related-party markers and the related-party and director loan reports are
        // deliberately **absent** from this catalogue. Reading one takes the authority B58
        // reserves for sensitive data, and a read connection is a token, not a person with a
        // role: exposing a marker here would put "this customer is a director's wife" behind an
        // API key. A plugin that needs it asks a signed-in person through the hook points.
        'ownership_snapshot' => ['Read who owns the company at a date: share classes, holdings and percentages per holder, votes, fully diluted totals, the members register and the officers register.', ['as_of' => $date], ['as_of'], false],
        'share_ledger' => ['Read the append-only share ledger: allotments, transfers, cancellations, bonus issues and re-designations, with their corrections. Transfers never carry a journal.', [], [], true],
    ];
    $catalog = [];
    foreach ($definitions as $name => [$description, $properties, $required, $paginated]) {
        $catalog[$name] = ['description' => $description, 'schema' => ['type' => 'object', 'properties' => ($name === 'companies' ? [] : $scope) + $properties + ($paginated ? $paging : []), 'required' => array_merge($name === 'companies' ? [] : array_keys($scope), $required), 'additionalProperties' => false]];
    }
    return $catalog;
}

function pl_read_arguments(string $operation, array $arguments, bool $query = false): array
{
    $schema = pl_read_catalog()[$operation]['schema'] ?? null;
    if (!$schema) {
        throw new DomainException('Unsupported operation. This interface provides authorized reads only.');
    }
    if (array_diff(array_keys($arguments), array_keys($schema['properties'])) !== []) {
        throw new DomainException('Unknown arguments are not accepted.');
    }
    foreach ($schema['required'] as $name) {
        if (!array_key_exists($name, $arguments)) {
            throw new DomainException('Required argument: ' . $name . '.');
        }
    }
    foreach ($schema['properties'] as $name => $rule) {
        if (!array_key_exists($name, $arguments)) {
            if (isset($rule['default'])) { $arguments[$name] = $rule['default']; }
            continue;
        }
        $value = $arguments[$name];
        if ($rule['type'] === 'integer') {
            if ($query && is_string($value) && preg_match('/^[1-9][0-9]{0,15}$/D', $value)) { $value = (int) $value; }
            if (!is_int($value) || $value < ($rule['minimum'] ?? 1) || $value > ($rule['maximum'] ?? 9007199254740991)) {
                throw new DomainException($name . ' must be a bounded positive integer.');
            }
        } elseif (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > ($rule['maxLength'] ?? 160)) {
            throw new DomainException($name . ' must be a bounded UTF-8 string.');
        }
        if (isset($rule['enum']) && !in_array($value, $rule['enum'], true)) { throw new DomainException('Unsupported value for ' . $name . '.'); }
        if (($rule['format'] ?? '') === 'date') { pl_ledger_date($value); }
        $arguments[$name] = $value;
    }
    return $arguments;
}

/** Page metadata always refers to one named collection; totals are never page subtotals. */
function pl_read_page(array $rows, int $page, int $size): array
{
    $total = count($rows);
    $page = min($page, max(1, (int) ceil($total / $size)));
    return ['rows' => array_slice($rows, ($page - 1) * $size, $size), 'pagination' => pl_read_pagination($total, $page, $size)];
}

function pl_read_pagination(int $total, int $page, int $size): array
{
    return ['page' => $page, 'page_size' => $size, 'total' => $total, 'pages' => max(1, (int) ceil($total / $size)), 'next_page' => $page * $size < $total ? $page + 1 : null];
}

/** Explicit DTO fields avoid leaking service internals or future private columns. */
function pl_read_fields(array $row, array $fields): array
{
    $result = array_intersect_key($row, array_flip($fields));
    foreach ($result as $key => &$value) {
        if ($value !== null && ($key === 'id' || str_ends_with($key, '_id'))) { $value = (int) $value; }
        if ($key === 'overdraft_enabled') { $value = (bool) $value; }
        if ($value !== null && str_ends_with($key, '_at')) { $value = str_replace(' ', 'T', $value) . 'Z'; }
    }
    unset($value);
    return $result;
}

function pl_read_journal(array $row, int $page, int $size): array
{
    return pl_read_fields($row, ['id','company_id','book_id','reference','journal_date','description','currency','source_type','source_reference','reversal_of_id','posted_at'])
        + ['lines' => pl_read_page(array_map(static fn (array $line): array => pl_read_fields($line, ['account_id','code','name','description','debit','credit','currency','amount_fc','rate','rate_type','rate_source_id','amount_base','rate_is_stale','ic_counterparty_entity_id']), $row['lines']), $page, $size)];
}

function pl_read_source(array $row, string $type, int $page, int $size): array
{
    $result = pl_read_fields($row, ['id','company_id','book_id','number','date','document_date','kind','status','reference','counterparty','party_id','memo','description','amount','money_account_id','category_account_id','journal_id','original_journal_id','reversal_journal_id','revision','created_at','updated_at']);
    $history = array_map(static fn (array $revision): array => pl_read_fields($revision, ['revision','journal_id','reversal_journal_id','actor_id','recorded_at']) + ['source_snapshot' => $revision['source_snapshot']], $row['posting_history'] ?? []);
    $result['posting_history'] = pl_read_page($history, $page, $size);
    if ($type === 'general_journal') {
        $result['totals'] = $row['totals'];
        $result['lines'] = pl_read_page($row['lines'], $page, $size);
    } else {
        $result['journal'] = isset($row['journal']) ? pl_read_journal($row['journal'], $page, $size) : null;
    }
    return $result;
}

/**
 * The ownership snapshot as an explicit DTO.
 *
 * The service returns the full register rows, which carry the recorder, the revision and the
 * internal ids of every person; a read grant sees the ownership facts and nothing else. The
 * related-party markers are not reachable from here at all — they are not in the catalogue.
 *
 * @return array<string,mixed>
 */
function pl_read_ownership_snapshot(int $actorId, int $companyId, string $asOf): array
{
    $snapshot = pl_ownership_snapshot($actorId, $companyId, $asOf);
    $classes = array_map(static fn (array $class): array => pl_read_fields($class, ['id','code','name','class_type','currency','nominal_value','votes_per_share','authorised_shares','issued_shares','is_option_pool','is_active']), $snapshot['classes']);
    $holdings = [];
    foreach ($snapshot['holdings'] as $entry) {
        $holdings[] = [
            'stock_class' => pl_read_fields($entry['class'], ['id','code','name','class_type','issued_shares']),
            'holders' => array_map(static fn (array $holder): array => pl_read_fields($holder, ['ownership_party_id','name','shares','percent_of_class','percent_outstanding','percent_fully_diluted','votes','percent_votes']), $entry['holders']),
        ];
    }
    return [
        'as_of' => $snapshot['as_of'],
        'outstanding_shares' => $snapshot['outstanding_shares'],
        'issued_shares' => $snapshot['issued_shares'],
        'fully_diluted_shares' => $snapshot['fully_diluted_shares'],
        'total_votes' => $snapshot['total_votes'],
        'stock_classes' => $classes,
        'holdings' => $holdings,
        'members' => array_map(static fn (array $row): array => pl_read_fields($row, ['id','ownership_party_id','name','kind','effective_from','effective_to','profit_share']), $snapshot['members']),
        'officers' => array_map(static fn (array $row): array => pl_read_fields($row, ['id','ownership_party_id','name','officer_role','role_label','role_title','appointed_on','resigned_on','has_significant_control','control_nature']), $snapshot['officers']),
    ];
}

/** Report-only grants exclude transaction-level records and account statements. */
function pl_connection_read_operations(array $connection): array
{
    return match ($connection['access_mode'] ?? null) {
        'reports' => ['companies', 'capabilities', 'trial_balance', 'profit_loss', 'balance_sheet'],
        'full' => array_keys(pl_read_catalog()),
        default => throw new DomainException('This connection has an unsupported access scope.'),
    };
}

/** One scoped business interface for HTTP and MCP; only existing accounting services compute money. */
function pl_read_operation(string $connectionId, string $operation, array $input): array
{
    $args = pl_read_arguments($operation, $input);
    return pl_ledger_transaction(function () use ($connectionId, $operation, $args): array {
        $connection = pl_connection_require($connectionId);
        if (!in_array($operation, pl_connection_read_operations($connection), true)) {
            throw new DomainException('This connection permits summary reports only. Create a full read connection to read individual records.');
        }
        $actor = $connection['actor_id'];
        $page = $args['page'] ?? 1;
        $size = $args['page_size'] ?? 25;
        if ($operation === 'companies') {
            $rows = [];
            foreach ($connection['books'] as $pair) {
                $row = DB::queryFirstRow('SELECT c.id AS company_id, c.name, b.id AS book_id, c.currency FROM pl_companies c JOIN pl_books b ON b.company_id = c.id WHERE c.id = %i AND b.id = %i', $pair['company_id'], $pair['book_id']);
                $rows[] = pl_read_fields($row, ['company_id','name','book_id','currency']);
            }
            return ['data' => pl_read_page($rows, $page, $size), 'access_expires_at' => str_replace(' ', 'T', $connection['expires_at']) . 'Z'];
        }
        $company = $args['company_id'];
        $book = $args['book_id'];
        pl_connection_scope($connection, $company, $book);
        $bookInfo = pl_ledger_book($company, $book);
        $data = match ($operation) {
            'capabilities' => ['read_operations' => pl_connection_read_operations($connection), 'financial_writes' => false, 'enabled_modules' => array_values(array_filter(array_keys(pl_module_registry()), static fn (string $id): bool => pl_module_available($actor, $company, $book, $id)))],
            'accounts' => pl_read_page(array_map(static fn (array $row): array => pl_read_fields($row, ['id','code','name','type','role','is_active','money_kind','currency','overdraft_enabled','overdraft_limit','facility_currency']), DB::query('SELECT a.id,a.code,a.name,a.type,a.role,a.is_active,a.money_kind,a.currency,a.overdraft_enabled,a.overdraft_limit,COALESCE(a.currency,b.functional_currency) AS facility_currency FROM pl_accounts a JOIN pl_books b ON b.id=a.book_id AND b.company_id=a.company_id WHERE a.company_id = %i AND a.book_id = %i ORDER BY a.code,a.id', $company, $book)), $page, $size),
            'trial_balance' => pl_trial_balance($actor, $company, $book, $args['as_of']),
            'profit_loss' => pl_profit_loss($actor, $company, $book, $args['from'], $args['to']),
            'balance_sheet' => pl_balance_sheet($actor, $company, $book, $args['as_of']),
            'journal' => pl_read_journal(pl_get_journal($actor, $company, $book, $args['journal_id']), $page, $size),
            'source_detail' => pl_read_source($args['source_type'] === 'transaction' ? pl_get_document($actor, $company, $book, $args['source_id']) : pl_get_general_draft($actor, $company, $book, $args['source_id']), $args['source_type'], $page, $size),
            'transactions' => pl_list_documents($actor, $company, $book, array_diff_key($args, array_flip(['company_id','book_id']))),
            'general_journals' => pl_list_general_drafts($actor, $company, $book, $page, ['page_size' => $size]),
            'account_statement' => pl_account_activity($actor, $company, $book, $args['account_id'], $args['as_of'], $page, $args['from'], ['page_size' => $size]),
            'warehouses' => pl_read_page(array_map(static fn (array $row): array => pl_read_fields($row, ['id','code','name','kind','driver_name','vehicle_reference','route_name','is_default','is_active']),
                pl_list_inventory_warehouses($actor, $company, $book)), $page, $size),
            'stock_transfers' => pl_read_page(array_map(static fn (array $row): array => pl_read_fields($row, ['out_movement_id','in_movement_id','movement_date','product_id','sku','product_name','quantity','value_base','from_warehouse_id','to_warehouse_id','document_id','document_kind','document_number']),
                pl_list_stock_transfers($actor, $company, $book, array_diff_key($args, array_flip(['company_id','book_id','page','page_size'])))), $page, $size),
            'unapplied_credit' => pl_unapplied_credit($actor, $company, $book, $args['side'], $args['as_of'] ?? null, $args['party_id'] ?? null),
            'ownership_snapshot' => pl_read_ownership_snapshot($actor, $company, $args['as_of']),
            'share_ledger' => pl_read_page(array_map(static fn (array $row): array => pl_read_fields($row, ['id','effective_date','event_type','type_label','class_code','class_name','quantity','from_name','to_name','to_class_code','consideration_currency','consideration_amount','nominal_total','premium_total','certificate_reference','journal_id','reversal_of_id','status']),
                pl_list_share_events($actor, $company, 1000)), $page, $size),
            default => throw new LogicException('Read operation is not implemented.'),
        };
        if ($operation === 'unapplied_credit') {
            // Explicit DTO: an open-item row carries service internals a read grant never shows.
            $data['items'] = array_map(static fn (array $row): array => pl_read_fields($row, ['id','party_id','legal_name','control_account_id','currency','number','received_date','age_days','remaining_fc','remaining_base']), $data['items']);
        }
        foreach (match ($operation) { 'trial_balance' => ['accounts'], 'profit_loss' => ['income','cost_of_sales','expenses'], 'balance_sheet' => ['assets','liabilities','equity'], 'unapplied_credit' => ['items'], default => [] } as $field) {
            $data[$field] = pl_read_page($data[$field], $page, $size);
            // The collapsible class/group tree (issue #77) is a presentation of these same rows.
            $data = array_diff_key($data, array_flip(['tree', 'trees', 'equity_movements']));
        }
        if (in_array($operation, ['transactions','general_journals','account_statement'], true)) {
            $key = match ($operation) { 'transactions' => 'documents', 'general_journals' => 'rows', default => 'movements' };
            if ($operation !== 'account_statement') {
                $data[$key] = array_map(static fn (array $row): array => pl_read_fields($row, ['id','number','date','document_date','kind','status','reference','counterparty','party_id','memo','description','amount','totals','journal_id','reversal_journal_id']), $data[$key]);
            }
            $data['pagination'] = pl_read_pagination($data['total'], $data['page'], $size);
            unset($data['page'], $data['pages']);
        }
        $result = ['company_id' => $company, 'book_id' => $book, 'currency' => $bookInfo['currency'], 'data' => $data, 'access_expires_at' => str_replace(' ', 'T', $connection['expires_at']) . 'Z'];
        if (strlen(json_encode($result, JSON_THROW_ON_ERROR)) > 240000) { throw new LengthException('Response is too large. Reduce the page size or narrow the period.'); }
        return $result;
    });
}
