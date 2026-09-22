<?php
declare(strict_types=1);
if (getenv('PL_ENV') !== 'test' || getenv('PL_DB_NAME') !== 'phpledger_test') {
    exit(2);
}
require_once dirname(__DIR__) . '/www/phpledger/includes/bootstrap.php';
$input = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$deadline = microtime(true) + 15;
while (!is_file($input['barrier'])) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Concurrent test barrier timed out.');
    }
    usleep(10000);
}
$fixture = $input['fixture'];
try {
    if ($input['mode'] === 'year_close') {
        try {
            $result=pl_year_end_change($fixture['actor_id'],$fixture['company_id'],$fixture['book_id'],$input['year_id'],'close',$input['revision'],'Concurrent reviewed close',$input['key'],$input['close_input']);
            $journal=['id'=>$result['journal_id']??0];
        } catch (DomainException $e) { if(!($input['allow_domain_failure']??false)){throw $e;} $journal=['id'=>0]; }
    } elseif ($input['mode']==='loan_revise') {
        $result=pl_revise_loan($fixture['actor_id'],$fixture['company_id'],$fixture['book_id'],$input['loan_id'],$input['revision'],$input['plan']);$journal=['id'=>$result['version']];
    } elseif ($input['mode']==='schedule_dispatch') {
        pl_recurring_due($fixture['company_id'],$fixture['book_id'],$input['as_of'],100);
        $result=pl_scheduler_dispatch($fixture['company_id'],$fixture['book_id'],100);
        $journal=['id'=>$result['succeeded']];
    } elseif (in_array($input['mode'], ['purchase_receive','purchase_bill','opening_convert'], true)) {
        try {
            $result=match ($input['mode']) {
                'purchase_receive'=>pl_receive_purchase_order($fixture['actor_id'],$fixture['company_id'],$fixture['book_id'],$input['order_id'],$input['receipt_input']),
                'purchase_bill'=>pl_bill_purchase_receipts($fixture['actor_id'],$fixture['company_id'],$fixture['book_id'],$input['bill_input']),
                'opening_convert'=>pl_confirm_opening_conversion($fixture['actor_id'],$fixture['company_id'],$fixture['book_id'],$input['cutover_id'],$input['mappings'],$input['expected_hash'],true,$input['key'],$input['reason']),
            };
            $journal=['id'=>$result['receipt_id']??$result['journal_id']??$result['bill_id']??$result['cutover_id']??1];
        } catch (DomainException $error) {
            if (!($input['allow_domain_failure']??false)) { throw $error; }
            $journal=['id'=>0];
        }
    } elseif (in_array($input['mode'], ['ar_post','ar_settle','ar_credit_post'], true)) {
        try {
            $result = $input['mode'] === 'ar_settle'
                ? pl_settle_ar_document($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $input['document_id'], $input['settlement_input'])
                : pl_post_ar_document($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $input['document_id'], $input['revision']);
            $journal = ['id' => $result['journal_id']];
        } catch (DomainException $error) {
            if (!($input['allow_domain_failure'] ?? false)) { throw $error; }
            $journal = ['id' => 0];
        }
    } elseif ($input['mode'] === 'inventory_issue') {
        try {
            $result = pl_inventory_issue($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $input['inventory_input']);
            $journal = ['id' => $result['movement_id']];
        } catch (DomainException $error) {
            if (!($input['allow_domain_failure'] ?? false)) { throw $error; }
            $journal = ['id' => 0];
        }
    } elseif (in_array($input['mode'], ['open_item_settle','open_items_settle','batch_receipts','advance_apply'], true)) {
        try {
            $result = match ($input['mode']) {
                'open_items_settle' => pl_settle_open_items($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $input['settlement_input']),
                // One voucher per customer row (B57): the batch result reports each of them.
                'batch_receipts' => ['journal_id' => (int) pl_post_batch_receipts($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $input['settlement_input'])['receipts'][0]['journal_id']],
                'advance_apply' => pl_apply_unapplied_credit($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $input['settlement_input']),
                default => pl_settle_open_item($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $input['settlement_input']),
            };
            $journal = ['id' => $result['journal_id']];
        } catch (DomainException $error) {
            if (!($input['allow_domain_failure'] ?? false)) { throw $error; }
            $journal = ['id' => 0];
        }
    } elseif ($input['mode'] === 'source_correct') {
        try {
            $result = pl_correct_source($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], 'general_journal', $input['source_id'], $input['revision'], $input['correction_input'], null, $input['key'], 'Concurrent correction');
            $journal = ['id' => $result['journal_id']];
        } catch (DomainException $error) {
            if (!($input['allow_domain_failure'] ?? false)) { throw $error; }
            $journal = ['id' => 0];
        }
    } elseif ($input['mode'] === 'module_set') {
        $module = pl_set_company_module($fixture['actor_id'], $fixture['company_id'], 'pos-showcase', $input['enabled'], $input['revision'], pl_module_registry()['pos-showcase']['digest'], 'Sample concurrent decision', $input['key']);
        $journal = ['id' => $module['revision']];
    } elseif ($input['mode'] === 'general_save') {
        $draft = pl_save_general_draft($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $input['general_input'], $input['draft_id'], $input['revision']);
        $journal = ['id' => $draft['id']];
    } elseif ($input['mode'] === 'account_update') {
        $account = pl_save_account($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $input['account_input'], $input['account_id'], $input['revision']);
        $journal = ['id' => $account['id']];
    } elseif ($input['mode'] === 'general_post') {
        $draft = pl_post_general_draft($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $input['draft_id'], $input['revision']);
        $journal = ['id' => $draft['journal_id']];
    } elseif ($input['mode'] === 'account_create') {
        $account = pl_save_account($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $input['account_input']);
        $journal = ['id' => $account['id']];
    } elseif ($input['mode'] === 'pos_checkout') {
        $sale = pl_checkout_pos($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $input['pos_input']);
        $journal = ['id' => $sale['document_id']];
    } elseif ($input['mode'] === 'setup') {
        $company = pl_setup_company($fixture['actor_id'], $input['setup_input'], $input['key']);
        $journal = ['id' => $company['id']];
    } elseif ($input['mode'] === 'opening_confirm') {
        $cutover = pl_confirm_opening($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $input['preview_id'], $input['hash'], true);
        $journal = ['id' => $cutover['id']];
    } elseif ($input['mode'] === 'bank_import') {
        $statement = pl_bank_import_statement($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $input['bank_input'], $input['key'], $input['digest']);
        $journal = ['id' => $statement['id']];
    } elseif ($input['mode'] === 'bank_complete') {
        $statement = pl_bank_complete_statement($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $input['statement_id'], $input['revision'], $input['key']);
        $journal = ['id' => $statement['id']];
    } elseif ($input['mode'] === 'document_create') {
        $document = pl_save_document($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $input['document_input']);
        $journal = ['id' => $document['id']];
    } elseif ($input['mode'] === 'document_post') {
        $document = pl_post_document($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $input['document_id'], $input['revision']);
        $journal = ['id' => $document['journal_id']];
    } else {
        $journal = $input['mode'] === 'reverse'
        ? pl_reverse_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $input['journal_id'], gmdate('Y-m-d'), $input['key'], 'Concurrent correction proof')
            : pl_post_journal($fixture['actor_id'], $fixture['company_id'], $fixture['book_id'], $input['payload']);
    }
    echo json_encode(['id' => $journal['id']], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage());
    exit(1);
}
