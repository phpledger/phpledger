<?php
declare(strict_types=1);

/** Prepare an ordinary reviewable general journal; this function never posts or sends money. */
function pl_account_payment_plan(int $actorId, int $companyId, int $bookId, array $input): array
{
    pl_require_company_access($actorId, $companyId, true);
    $book = pl_ledger_book($companyId, $bookId);
    $bankId = $input['bank_account_id'] ?? null;
    if (!is_int($bankId) || $bankId < 1) { throw new DomainException('Choose a cash or bank account.'); }
    $bank = pl_get_account($actorId, $companyId, $bookId, $bankId);
    if (!$bank['is_active'] || !$bank['is_postable'] || $bank['type'] !== 'asset' || $bank['role'] !== 'cash_bank'
        || ($bank['currency'] !== null && $bank['currency'] !== $book['currency'])) {
        throw new DomainException('Choose an active postable cash or bank account in the book currency.');
    }
    $debits = $input['debits'] ?? null;
    if (!is_array($debits) || !array_is_list($debits) || $debits === [] || count($debits) > 99) {
        throw new DomainException('An account payment needs one to 99 debit lines.');
    }
    $lines = []; $total = '0.0000';
    foreach ($debits as $debit) {
        if (!is_array($debit) || !is_int($debit['account_id'] ?? null) || !is_string($debit['amount'] ?? null)) {
            throw new DomainException('Each payment line needs an account and exact decimal amount.');
        }
        $account = pl_get_account($actorId, $companyId, $bookId, $debit['account_id']);
        if (!$account['is_active'] || !$account['is_postable'] || !in_array($account['type'], ['asset','liability','expense'], true)
            || in_array($account['role'], ['cash_bank','receivables','payables','customer_advances','supplier_advances'], true)
            || ($account['currency'] !== null && $account['currency'] !== $book['currency'])) {
            throw new DomainException('Payments here require active non-trade accounts in the book currency.');
        }
        $amount = pl_amount($debit['amount']);
        if (bccomp($amount, '0', 4) <= 0) { throw new DomainException('A payment amount must be positive.'); }
        $total = bcadd($total, $amount, 4);
        $lines[] = ['account_id' => $account['id'], 'debit' => $amount, 'credit' => '0.0000',
            'description' => pl_ledger_text($debit['description'] ?? '', 'Payment line description', 500, false)];
    }
    $lines[] = ['account_id' => $bankId, 'debit' => '0.0000', 'credit' => pl_amount($total), 'description' => 'Account payment'];
    return pl_save_general_draft($actorId, $companyId, $bookId, ['date' => $input['date'] ?? null,
        'reference' => $input['reference'] ?? '', 'description' => $input['description'] ?? null,
        'creation_key' => $input['creation_key'] ?? null, 'lines' => $lines]);
}
