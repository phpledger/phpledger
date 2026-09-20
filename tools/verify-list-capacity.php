<?php
declare(strict_types=1);

// Sample data only, on the disposable Compose database. No historical SQL imports.
if (PHP_SAPI!=='cli' || getenv('PL_ENV')!=='test' || getenv('PL_DB_HOST')!=='db_test' || getenv('PL_DB_NAME')!=='phpledger_test') {
    fwrite(STDERR,"List capacity verification requires the isolated db_test/phpledger_test service.\n"); exit(2);
}
require dirname(__DIR__).'/www/phpledger/includes/bootstrap.php';
require dirname(__DIR__).'/www/phpledger/includes/functions/web_functions.php';
if (DB::$host!=='db_test' || DB::$dbName!=='phpledger_test') { throw new RuntimeException('Effective database must remain isolated.'); }
$name='Sample UI list capacity 0.6';
$measureOnly=($argv[1]??'')==='--measure-only';
$company=DB::queryFirstRow('SELECT c.id,b.id AS book_id,c.created_by FROM pl_companies c JOIN pl_books b ON b.company_id=c.id WHERE c.name=%s',$name);
if ($measureOnly && !$company) { throw new RuntimeException('Seed the sample fixture before measuring.'); }
if ($company) {
    $actor=(int)$company['created_by']; $companyId=(int)$company['id']; $bookId=(int)$company['book_id'];
    $accounts=pl_account_code_mapping(DB::query('SELECT id,code,legacy_code FROM pl_accounts WHERE company_id=%i AND book_id=%i',$companyId,$bookId));
} else {
    $suffix=bin2hex(random_bytes(8));
    $actor=pl_create_user('list-capacity-'.$suffix.'@example.test','Sample list capacity tester','Sample-only-'.$suffix);
    $f=pl_create_company($actor,$name,'USD','2026-01-01','12-31');
    $companyId=$f['company_id']; $bookId=$f['book_id']; $accounts=$f['accounts'];
}
for ($start=0;!$measureOnly && $start<5000;$start+=100) {
    pl_ledger_transaction(function () use ($actor,$companyId,$bookId,$accounts,$start): void {
        for ($i=$start;$i<$start+100;$i++) {
            pl_save_document($actor,$companyId,$bookId,['kind'=>'receipt','date'=>'2026-09-14','amount'=>'1','money_account_id'=>$accounts['1000'],'category_account_id'=>$accounts['4000'],
                'counterparty'=>'Sample capacity customer','reference'=>'CAPACITY-'.$i,'memo'=>'Sample paged-list evidence','creation_key'=>'list-capacity-06:receipt:'.$i]);
            $draft=pl_save_general_draft($actor,$companyId,$bookId,['date'=>'2026-09-14','description'=>'Sample capacity journal','reference'=>'CAPACITY-'.$i,'creation_key'=>'list-capacity-06:journal:'.$i,
                'lines'=>[['account_id'=>$accounts['1000'],'debit'=>'1','credit'=>'0','description'=>'Sample cash'],['account_id'=>$accounts['3000'],'debit'=>'0','credit'=>'1','description'=>'Sample equity']]]);
            if ($draft['status']==='draft') { pl_post_general_draft($actor,$companyId,$bookId,$draft['id'],$draft['revision']); }
        }
    });
    if (($start+100)%500===0) { fwrite(STDERR,'Seeded '.($start+100)." sources of each type.\n"); }
}
$statementId=$measureOnly?(int)DB::queryFirstField('SELECT id FROM pl_bank_statements WHERE company_id=%i AND book_id=%i ORDER BY id DESC LIMIT 1',$companyId,$bookId):0;
for ($batch=0;!$measureOnly && $batch<10;$batch++) {
    $code='19'.str_pad((string)$batch,2,'0',STR_PAD_LEFT);
    $account=pl_save_account($actor,$companyId,$bookId,['code'=>$code,'name'=>'Sample capacity bank '.$batch,'type'=>'asset','role'=>'cash_bank','currency'=>'USD','is_active'=>true,'reason'=>'Sample list capacity','creation_key'=>'list-capacity-06:bank:'.$batch]);
    $rows=[];
    for ($i=0;$i<500;$i++) { $rows[]=['date'=>'2026-09-14','reference'=>'CAPACITY-BANK-'.$batch.'-'.$i,'description'=>'Sample bank row','money_in'=>'1','money_out'=>'0']; }
    $input=['account_id'=>$account['id'],'reference'=>'CAPACITY-STATEMENT-'.$batch,'start_date'=>'2026-01-01','end_date'=>'2026-09-30','opening_balance'=>'0','closing_balance'=>'500','baseline_confirmed'=>true,'rows'=>$rows];
    $existing=DB::queryFirstField('SELECT id FROM pl_bank_statements WHERE company_id=%i AND book_id=%i AND account_id=%i AND reference=%s',$companyId,$bookId,$account['id'],$input['reference']);
    if ($existing) { $statementId=(int)$existing; continue; }
    $preview=pl_bank_preview_statement($actor,$companyId,$bookId,$input);
    $statement=pl_bank_import_statement($actor,$companyId,$bookId,$input,'list-capacity-06:statement:'.$batch,$preview['digest']);
    $statementId=$statement['id'];
}
$cases=[
    'transactions'=>['status'=>'all'],
    'general-journals'=>['status'=>'all'],
    'account'=>['id'=>$accounts['1000'],'from'=>'2026-01-01','as_of'=>'2026-12-31'],
    'bank'=>['statement_id'=>$statementId],
];
if ((int)DB::queryFirstField('SELECT COUNT(*) FROM pl_bank_statement_rows WHERE company_id=%i AND book_id=%i',$companyId,$bookId)!==5000) { throw new RuntimeException('Expected 5000 sample bank rows.'); }
$evidence=[];
$firstJournal=(int)DB::queryFirstField('SELECT journal_id FROM pl_general_drafts WHERE company_id=%i AND book_id=%i ORDER BY id LIMIT 1',$companyId,$bookId);
foreach ($cases as $screen=>$base) {
    $search=match($screen){'bank'=>'CAPACITY-BANK-9-49','account'=>'PL-'.str_pad((string)$firstJournal,8,'0',STR_PAD_LEFT),default=>'CAPACITY-49'};
    foreach (['first'=>[],'last'=>['page'=>'100000'],'search'=>['q'=>$search]] as $case=>$extra) {
        $queries=[];
        $hook=DB::addHook('post_run',static function (array $query) use (&$queries): void {
            if (str_starts_with($query['query'],'SELECT') && str_contains($query['query'],' LIMIT ')) { $queries[]=$query; }
        });
        $start=microtime(true);
        try { $result=pl_list_query($actor,$companyId,$bookId,$screen,$base+$extra); }
        finally { DB::removeHook('post_run',$hook); }
        $elapsed=round((microtime(true)-$start)*1000,2);
        $plans=[];
        foreach ($queries as $query) {
            // Instrument the existing MeekroDB PDO connection; no new connection or runtime API.
            $explain=DB::get()->prepare('EXPLAIN FORMAT=JSON '.$query['query']);
            foreach ($query['params'] as $index=>$value) { $explain->bindValue($index+1,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR); }
            $explain->execute();
            $plans[]=['sql'=>$query['query'],'query_ms'=>(float)$query['runtime'],'plan'=>json_decode((string)$explain->fetchColumn(),true,512,JSON_THROW_ON_ERROR)];
        }
        $collection=match($screen){'transactions'=>'documents','general-journals','bank'=>'rows',default=>'movements'};
        $total=in_array($screen,['bank','account'],true)?$result['filtered_total']:$result['total'];
        if ($case!=='search' && $total!==($screen==='bank'?500:5000)) { throw new RuntimeException('Unexpected source count for '.$screen); }
        if ($case==='search' && $total!==match($screen){'bank'=>11,'account'=>1,default=>111}) { throw new RuntimeException('Unexpected filtered source count for '.$screen); }
        if (count($result[$collection])>25 || $plans===[]) { throw new RuntimeException('Missing bounded query for '.$screen); }
        if ($screen==='account' && $result['closing_balance']!=='5000.0000') { throw new RuntimeException('Account closing balance changed under display filters.'); }
        $evidence[$screen][$case]=['service_ms'=>$elapsed,'total'=>$total,'page'=>$result['page'],'returned'=>count($result[$collection]),'queries'=>$plans];
        fwrite(STDERR,$screen.' '.$case.': '.$elapsed." ms\n");
    }
}
echo json_encode(['sample'=>true,'company_id'=>$companyId,'book_id'=>$bookId,'source_rows'=>5000,'bank_statement_rows'=>500,'bank_book_rows'=>5000,'bank_limit_note'=>'The unchanged service limit is 500 rows per statement; ten statements on separate bank accounts provide 5000 book rows.','checks'=>$evidence],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
