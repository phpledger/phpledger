<?php
declare(strict_types=1);

function pl_payroll_kinds(): array
{
    return [
        'gross_pay' => ['label' => 'Salaries and wages', 'type' => 'expense', 'side' => 'debit'],
        'employer_contribution' => ['label' => 'Employer contributions', 'type' => 'expense', 'side' => 'debit'],
        'net_pay' => ['label' => 'Net salaries payable', 'type' => 'liability', 'side' => 'credit'],
        'withholding' => ['label' => 'Payroll withholding payable', 'type' => 'liability', 'side' => 'credit'],
        'social_security' => ['label' => 'Social security payable', 'type' => 'liability', 'side' => 'credit'],
        'pension' => ['label' => 'Pension or provident fund payable', 'type' => 'liability', 'side' => 'credit'],
        'staff_advance' => ['label' => 'Staff advances recovered', 'type' => 'asset', 'side' => 'credit'],
    ];
}

function pl_payroll_require(int $actor, int $company, bool $write = false): void
{
    pl_require_company_access($actor, $company, $write);
    if (!pl_user_can($actor, $company, 'payroll.view') || ($write && !pl_user_can($actor, $company, 'payroll.manage'))) {
        throw new DomainException('Your role cannot perform this payroll accounting action.');
    }
}

function pl_payroll_provision_accounts(int $actor, int $company, int $book): array
{
    pl_payroll_require($actor, $company, true);
    return pl_ledger_transaction(function () use ($actor, $company, $book): array {
        pl_ledger_book($company, $book, true); $result = [];
        foreach (pl_payroll_kinds() as $kind => $definition) {
            $key = 'core.payroll.' . $kind;
            $id = DB::queryFirstField('SELECT id FROM pl_accounts WHERE company_id=%i AND book_id=%i AND semantic_key=%s FOR UPDATE', $company, $book, $key);
            if ($id === null) {
                $group = match ($definition['type']) { 'expense' => 'core.group.expense.operating', 'liability' => 'core.group.liability.payables', default => 'core.group.asset.receivables' };
                $account = pl_save_account($actor, $company, $book, ['code' => pl_asset_next_account_code($company, $book, $definition['type'], false, $group),
                    'name' => $kind === 'staff_advance' ? 'Staff advances' : $definition['label'], 'type' => $definition['type'], 'role' => null,
                    'is_active' => true, 'creation_key' => 'payroll-account:' . $book . ':' . $kind, 'reason' => 'Provision neutral payroll accounting account.']);
                $id = $account['id'];
                DB::update('pl_accounts', ['semantic_key' => $key], 'id=%i AND company_id=%i AND book_id=%i', $id, $company, $book);
            }
            $result[$kind] = (int) $id;
        }
        return $result;
    });
}

function pl_preview_payroll(int $actor, int $company, int $bookId, array $input): array
{
    pl_payroll_require($actor, $company, true); $book = pl_ledger_book($company, $bookId);
    $from = pl_ledger_date(pl_ledger_text($input['period_from'] ?? null, 'Pay period start', 10));
    $to = pl_ledger_date(pl_ledger_text($input['period_to'] ?? null, 'Pay period end', 10));
    if ($from > $to) { throw new DomainException('Pay period end must follow its start.'); }
    $date = pl_ledger_date(pl_ledger_text($input['date'] ?? null, 'Posting date', 10));
    $reference = pl_ledger_text($input['external_reference'] ?? null, 'External reference', 120);
    $description = pl_ledger_text($input['description'] ?? 'Payroll ' . $from . ' to ' . $to, 'Description', 500);
    $elements = $input['elements'] ?? null;
    if (!is_array($elements) || !array_is_list($elements) || count($elements) < 2 || count($elements) > 99) { throw new DomainException('Enter two to 99 aggregate payroll elements.'); }
    $lines = []; $normalized = [];
    foreach ($elements as $element) {
        if (!is_array($element) || !is_string($element['kind'] ?? null) || !isset(pl_payroll_kinds()[$element['kind']])
            || !is_int($element['account_id'] ?? null) || !is_string($element['amount'] ?? null)) { throw new DomainException('Choose a payroll element, account and exact amount.'); }
        $kind = $element['kind']; $definition = pl_payroll_kinds()[$kind];
        $account = pl_get_account($actor, $company, $bookId, $element['account_id']);
        if (!$account['is_active'] || !$account['is_postable'] || $account['type'] !== $definition['type'] || $account['is_contra']
            || in_array($account['role'], ['receivables','payables','cash_bank','customer_advances','supplier_advances'], true)
            || ($account['currency'] !== null && $account['currency'] !== $book['currency'])) { throw new DomainException('Choose an active functional-currency, non-trade account of the payroll element type.'); }
        $amount = pl_amount($element['amount']);
        if (bccomp($amount, '0', 4) <= 0) { throw new DomainException('Payroll element amounts must be positive.'); }
        $normalized[] = ['kind' => $kind, 'account_id' => $account['id'], 'amount' => $amount];
        $lines[] = ['account_id' => $account['id'], 'debit' => $definition['side'] === 'debit' ? $amount : '0.0000',
            'credit' => $definition['side'] === 'credit' ? $amount : '0.0000', 'description' => $definition['label']];
    }
    $totals = pl_general_totals($lines);
    if (!$totals['balanced']) { throw new DomainException('Payroll aggregate debits and credits must balance exactly.'); }
    return ['period_from' => $from, 'period_to' => $to, 'date' => $date, 'external_reference' => $reference,
        'description' => $description, 'currency' => $book['currency'], 'elements' => $normalized, 'lines' => $lines, 'totals' => $totals];
}

function pl_post_payroll(int $actor, int $company, int $book, array $input): array
{
    $key = pl_request_key(pl_ledger_text($input['request_key'] ?? null, 'Request key', 128));
    $plan = pl_preview_payroll($actor, $company, $book, $input);
    $hash = hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR));
    return pl_ledger_transaction(function () use ($actor, $company, $book, $key, $hash, $plan): array {
        pl_payroll_require($actor, $company, true); pl_ledger_book($company, $book, true);
        $prior = DB::queryFirstRow('SELECT id,request_hash FROM pl_payroll_journals WHERE book_id=%i AND request_key=%s FOR UPDATE', $book, $key);
        if ($prior) {
            if (!hash_equals($prior['request_hash'], $hash)) { throw new DomainException('That payroll request key was already used for different totals.'); }
            return pl_get_payroll($actor, $company, $book, (int) $prior['id']);
        }
        if (DB::queryFirstField('SELECT id FROM pl_payroll_journals WHERE book_id=%i AND external_reference=%s FOR UPDATE', $book, $plan['external_reference']) !== null) {
            throw new DomainException('That external payroll reference is already recorded. Use a distinct correction reference.');
        }
        $recoveries=[];
        foreach ($plan['elements'] as $element) {
            if ($element['kind']==='staff_advance') { $recoveries[$element['account_id']]=bcadd($recoveries[$element['account_id']]??'0',$element['amount'],4); }
        }
        foreach ($recoveries as $account=>$recovery) {
            $available='0.0000';
            foreach (DB::query('SELECT l.debit,l.credit FROM pl_journal_lines l JOIN pl_journals j ON j.id=l.journal_id WHERE j.company_id=%i AND j.book_id=%i AND l.account_id=%i AND j.journal_date<=%s FOR SHARE',$company,$book,$account,$plan['date']) as $line) {
                $available=bcadd($available,bcsub($line['debit'],$line['credit'],4),4);
            }
            if (bccomp($recovery,$available,4)>0) { throw new DomainException('Staff-advance recovery exceeds the recorded advance balance at the posting date.'); }
        }
        DB::insert('pl_payroll_journals', ['company_id' => $company, 'book_id' => $book, 'period_from' => $plan['period_from'], 'period_to' => $plan['period_to'],
            'posting_date' => $plan['date'], 'external_reference' => $plan['external_reference'], 'description' => $plan['description'],
            'request_key' => $key, 'request_hash' => $hash, 'created_by' => $actor]);
        $id = (int) DB::insertId();
        foreach ($plan['elements'] as $element) { DB::insert('pl_payroll_elements', ['payroll_id' => $id, 'company_id' => $company, 'book_id' => $book,
            'element_kind' => $element['kind'], 'account_id' => $element['account_id'], 'amount' => $element['amount']]); }
        $journal = pl_post_journal($actor, $company, $book, ['date' => $plan['date'], 'currency' => $plan['currency'], 'source_type' => 'payroll',
            'source_reference' => 'payroll:' . $id, 'idempotency_key' => 'payroll:' . $id . ':post', 'description' => $plan['description'], 'lines' => $plan['lines']]);
        DB::update('pl_payroll_journals', ['journal_id' => $journal['id']], 'id=%i', $id);
        return pl_get_payroll($actor, $company, $book, $id);
    });
}

function pl_payroll_paid(int $elementId, ?string $asOf = null, bool $current = false): string
{
    $date = $asOf ?? '9999-12-31'; $total = '0.0000';
    // Under a book lock use current locking reads, not a caller-owned old snapshot.
    $rows = DB::query('SELECT l.amount FROM pl_payroll_payment_lines l JOIN pl_payroll_payments p ON p.id=l.payment_id
        JOIN pl_general_drafts d ON d.id=p.draft_id JOIN pl_journals j ON j.id=d.journal_id
        LEFT JOIN pl_journals r ON r.reversal_of_id=j.id AND r.journal_date<=%s
        WHERE l.element_id=%i AND j.journal_date<=%s AND r.id IS NULL' . ($current ? ' FOR SHARE' : ''), $date, $elementId, $date);
    foreach ($rows as $row) { $total = bcadd($total, $row['amount'], 4); }
    return $total;
}

function pl_get_payroll(int $actor, int $company, int $book, int $id, ?string $asOf = null): array
{
    pl_payroll_require($actor, $company); pl_ledger_book($company, $book);
    $row = DB::queryFirstRow('SELECT p.*,r.id AS reversal_id FROM pl_payroll_journals p LEFT JOIN pl_journals r ON r.reversal_of_id=p.journal_id AND r.journal_date<=%s
        WHERE p.id=%i AND p.company_id=%i AND p.book_id=%i FOR SHARE', $asOf ?? '9999-12-31', $id, $company, $book);
    if (!$row) { throw new DomainException('Payroll is not available in this company and book.'); }
    foreach (['id','company_id','book_id','created_by'] as $field) { $row[$field] = (int) $row[$field]; }
    $row['journal_id'] = $row['journal_id'] === null ? null : (int) $row['journal_id'];
    $row['reversal_id'] = $row['reversal_id'] === null ? null : (int) $row['reversal_id'];
    $row['elements'] = DB::query('SELECT e.*,a.name AS account_name,a.code AS account_code FROM pl_payroll_elements e JOIN pl_accounts a ON a.id=e.account_id WHERE e.payroll_id=%i ORDER BY e.id', $id);
    foreach ($row['elements'] as &$element) {
        foreach (['id','account_id','payroll_id'] as $field) { $element[$field] = (int) $element[$field]; }
        $element['paid'] = pl_payroll_paid($element['id'], $asOf);
        $element['remaining'] = bcsub($element['amount'], $element['paid'], 4);
        $element['settleable'] = pl_payroll_kinds()[$element['element_kind']]['type'] === 'liability';
    }
    unset($element, $row['request_key'], $row['request_hash']);
    return $row;
}

function pl_list_payroll(int $actor, int $company, int $book, string $from, string $to, ?string $kind = null): array
{
    pl_payroll_require($actor, $company); pl_ledger_book($company, $book); pl_ledger_date($from); pl_ledger_date($to);
    if ($from > $to || ($kind !== null && !isset(pl_payroll_kinds()[$kind]))) { throw new DomainException('Choose a valid payroll period and element.'); }
    $rows = DB::query('SELECT id FROM pl_payroll_journals WHERE company_id=%i AND book_id=%i AND period_to>=%s AND period_from<=%s ORDER BY period_from,id', $company,$book,$from,$to);
    $out = [];
    foreach ($rows as $row) {
        $payroll = pl_get_payroll($actor,$company,$book,(int)$row['id'], $to);
        if ($kind !== null) { $payroll['elements'] = array_values(array_filter($payroll['elements'], static fn(array $e): bool => $e['element_kind'] === $kind)); }
        $out[] = $payroll;
    }
    return $out;
}

function pl_payroll_payment_signature(array $draft): string
{
    $lines = [];
    foreach ($draft['lines'] as $line) { $lines[] = ['account_id'=>(int)$line['account_id'],'debit'=>(string)$line['debit'],'credit'=>(string)$line['credit'],'description'=>(string)$line['description']]; }
    return hash('sha256', json_encode([$draft['document_date'] ?? $draft['date'], $draft['description'], $lines], JSON_THROW_ON_ERROR));
}

function pl_prepare_payroll_payment(int $actor, int $company, int $book, int $payrollId, array $input): array
{
    pl_payroll_require($actor,$company,true);
    $key = pl_request_key(pl_ledger_text($input['creation_key'] ?? null,'Request key',128));
    $hash = hash('sha256', json_encode([$payrollId,$input], JSON_THROW_ON_ERROR));
    return pl_ledger_transaction(function () use ($actor,$company,$book,$payrollId,$input,$key,$hash): array {
        pl_ledger_book($company,$book,true);
        $prior = DB::queryFirstRow('SELECT draft_id,request_hash FROM pl_payroll_payments WHERE book_id=%i AND request_key=%s FOR UPDATE',$book,$key);
        if ($prior) {
            if (!hash_equals($prior['request_hash'],$hash)) { throw new DomainException('This payment request already has different allocations.'); }
            return pl_get_general_draft($actor,$company,$book,(int)$prior['draft_id']);
        }
        $payroll = pl_get_payroll($actor,$company,$book,$payrollId);
        if ($payroll['reversal_id'] !== null || $payroll['journal_id'] === null) { throw new DomainException('Only an unreversed posted payroll can be paid.'); }
        $date = pl_ledger_date(pl_ledger_text($input['date'] ?? null,'Payment date',10));
        if ($date < $payroll['posting_date']) { throw new DomainException('A payment cannot precede its payroll posting.'); }
        $byId = array_column($payroll['elements'],null,'id'); $debits=[]; $allocations=[];
        if (!is_array($input['allocations'] ?? null) || $input['allocations'] === []) { throw new DomainException('Choose payroll amounts to pay.'); }
        foreach ($input['allocations'] as $allocation) {
            if (!is_array($allocation) || !is_int($allocation['element_id'] ?? null) || !is_string($allocation['amount'] ?? null)) { throw new DomainException('Invalid payroll allocation.'); }
            $id=$allocation['element_id']; $element=$byId[$id] ?? null;
            $amount=pl_amount($allocation['amount']);
            if (!$element || !$element['settleable'] || isset($allocations[$id]) || bccomp($amount,'0',4)<=0 || bccomp($amount,$element['remaining'],4)>0) { throw new DomainException('Choose a payable element and an amount no greater than its outstanding balance.'); }
            $allocations[$id]=$amount;
            $debits[]=['account_id'=>$element['account_id'],'amount'=>$amount,'description'=>pl_payroll_kinds()[$element['element_kind']]['label']];
        }
        $draft=pl_account_payment_plan($actor,$company,$book,['date'=>$date,'bank_account_id'=>$input['bank_account_id'] ?? null,
            'description'=>'Payroll payment: '.$payroll['external_reference'],'reference'=>'payroll:'.$payrollId,'creation_key'=>$key,'debits'=>$debits]);
        DB::insert('pl_payroll_payments',['payroll_id'=>$payrollId,'company_id'=>$company,'book_id'=>$book,'draft_id'=>$draft['id'],'plan_hash'=>pl_payroll_payment_signature($draft),
            'request_key'=>$key,'request_hash'=>$hash,'created_by'=>$actor]); $payment=(int)DB::insertId();
        foreach ($allocations as $id=>$amount) { DB::insert('pl_payroll_payment_lines',['payment_id'=>$payment,'element_id'=>$id,'payroll_id'=>$payrollId,'company_id'=>$company,'book_id'=>$book,'amount'=>$amount]); }
        return $draft;
    });
}

/** Called under the central book lock for normal posts AND generic corrections. */
function pl_payroll_validate_general_payment(int $actor,int $company,int $book,array $draft): void
{
    $payment=DB::queryFirstRow('SELECT * FROM pl_payroll_payments WHERE company_id=%i AND book_id=%i AND draft_id=%i FOR UPDATE',$company,$book,$draft['id']);
    if (!$payment) { return; }
    pl_payroll_require($actor,$company,true);
    if (!hash_equals($payment['plan_hash'],pl_payroll_payment_signature($draft))) { throw new DomainException('This payroll payment differs from its allocation. Prepare a new payment instead of changing its journal.'); }
    $payroll=pl_get_payroll($actor,$company,$book,(int)$payment['payroll_id']);
    if ($payroll['reversal_id'] !== null) { throw new DomainException('This payroll was reversed.'); }
    if (DB::queryFirstField('SELECT journal_id FROM pl_general_drafts WHERE id=%i FOR UPDATE',$draft['id']) !== null) { return; }
    $byId=array_column($payroll['elements'],null,'id');
    foreach (DB::query('SELECT element_id,amount FROM pl_payroll_payment_lines WHERE payment_id=%i',$payment['id']) as $line) {
        if (bccomp($line['amount'],bcsub($byId[(int)$line['element_id']]['amount'],pl_payroll_paid((int)$line['element_id'],null,true),4),4)>0) { throw new DomainException('Another payment settled this payroll amount. Review the outstanding balance.'); }
    }
}

function pl_payroll_validate_posting(int $actor,int $company,int $book,array $payload,?int $reversalOf): void
{
    if ($reversalOf !== null) {
        if (DB::queryFirstField('SELECT p.id FROM pl_payroll_payments p JOIN pl_general_drafts d ON d.id=p.draft_id WHERE p.company_id=%i AND p.book_id=%i AND d.journal_id=%i FOR SHARE',$company,$book,$reversalOf) !== null) { pl_payroll_require($actor,$company,true); }
        $payroll=DB::queryFirstRow('SELECT id FROM pl_payroll_journals WHERE company_id=%i AND book_id=%i AND journal_id=%i FOR UPDATE',$company,$book,$reversalOf);
        if ($payroll) {
            pl_payroll_require($actor,$company,true);
            foreach (DB::query('SELECT id FROM pl_payroll_elements WHERE payroll_id=%i',$payroll['id']) as $element) {
                if (bccomp(pl_payroll_paid((int)$element['id'],null,true),'0',4)>0) { throw new DomainException('Reverse payroll payments before reversing their accrual.'); }
            }
        }
        return;
    }
    if ($payload['source_type']==='general_journal' && preg_match('/^general:([1-9][0-9]*)$/D',$payload['source_reference'],$m)) {
        $draft=$payload+['id'=>(int)$m[1]];
        if (DB::queryFirstField('SELECT id FROM pl_payroll_payments WHERE draft_id=%i AND company_id=%i AND book_id=%i FOR UPDATE',$draft['id'],$company,$book) !== null
            && $payload['idempotency_key'] !== 'general:'.$draft['id'].':post') { throw new DomainException('Reverse the payroll payment and prepare a new one; generic correction cannot replace its allocations.'); }
        pl_payroll_validate_general_payment($actor,$company,$book,$draft);
    }
    if ($payload['source_type']!=='payroll') { return; }
    pl_payroll_require($actor,$company,true);
    if (!preg_match('/^payroll:([1-9][0-9]*)$/D',$payload['source_reference'],$m)) { throw new DomainException('Use the payroll accounting service.'); }
    $row=DB::queryFirstRow('SELECT * FROM pl_payroll_journals WHERE id=%i AND company_id=%i AND book_id=%i FOR UPDATE',(int)$m[1],$company,$book);
    if (!$row || $payload['idempotency_key']!=='payroll:'.$row['id'].':post') { throw new DomainException('Use the payroll accounting service.'); }
    $elements=DB::query('SELECT element_kind AS kind,account_id,amount FROM pl_payroll_elements WHERE payroll_id=%i ORDER BY id',$row['id']);
    foreach ($elements as &$element) { $element['account_id']=(int)$element['account_id']; } unset($element);
    $expected=pl_preview_payroll($actor,$company,$book,['period_from'=>$row['period_from'],'period_to'=>$row['period_to'],'date'=>$row['posting_date'],
        'external_reference'=>$row['external_reference'],'description'=>$row['description'],'elements'=>$elements]);
    if (pl_payroll_payment_signature($expected)!==pl_payroll_payment_signature($payload)) { throw new DomainException('Payroll totals must match their immutable source.'); }
}

function pl_payroll_close_review(int $actor,int $company,int $book,string $from,string $to): array
{
    pl_require_company_access($actor,$company); pl_ledger_book($company,$book); pl_ledger_date($from); pl_ledger_date($to);
    return ['posted_count'=>(int)DB::queryFirstField('SELECT COUNT(*) FROM pl_payroll_journals p LEFT JOIN pl_journals r ON r.reversal_of_id=p.journal_id WHERE p.company_id=%i AND p.book_id=%i AND p.period_to>=%s AND p.period_from<=%s AND p.journal_id IS NOT NULL AND r.id IS NULL',$company,$book,$from,$to),
        'pending_payment_drafts'=>(int)DB::queryFirstField('SELECT COUNT(*) FROM pl_payroll_payments p JOIN pl_general_drafts d ON d.id=p.draft_id WHERE p.company_id=%i AND p.book_id=%i AND d.document_date BETWEEN %s AND %s AND d.journal_id IS NULL',$company,$book,$from,$to)];
}
