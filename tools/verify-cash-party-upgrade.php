<?php
declare(strict_types=1);

/** Two-process upgrade proof: seed with archived baseline code, check with this checkout. */
if (PHP_SAPI !== 'cli' || getenv('PL_ENV') !== 'test' || !in_array(getenv('PL_DB_HOST'), ['db_test','maria_test'], true)
    || getenv('PL_DB_NAME') !== 'phpledger_test' || getenv('PL_DB_USER') !== 'root') {
    fwrite(STDERR,"Requires PL_ENV=test, disposable db_test/maria_test host, phpledger_test connection and local test root.\n"); exit(2);
}
$mode=$argv[1]??'';
if (!in_array($mode,['seed','check'],true)) { throw new DomainException('Usage: seed BASELINE_ROOT STATE_FILE | check STATE_FILE'); }
$root=$mode==='seed'?realpath($argv[2]??''):dirname(__DIR__);
$stateFile=$argv[$mode==='seed'?3:2]??'';
$tempRoot=realpath(sys_get_temp_dir());
if (!$root || realpath(dirname($stateFile))!==$tempRoot || !preg_match('/^phpledger-cash-party-upgrade-[a-z0-9-]+\.json$/D',basename($stateFile))) {
    throw new DomainException('Use a real baseline root and a phpledger-cash-party-upgrade-*.json file directly in the temporary directory.');
}
if ($mode==='seed' && is_file($stateFile)) { throw new RuntimeException('Refusing to overwrite an earlier upgrade probe state.'); }
require $root.'/www/phpledger/includes/bootstrap.php';
require $root.'/www/phpledger/install/migrate.php';

function cash_party_probe_assert(bool $condition,string $message): void { if (!$condition) { throw new RuntimeException($message); } }
function cash_party_probe_document(array $scope,string $kind,string $amount,string $key): array {
    return ['kind'=>$kind,'date'=>$scope['date'],'amount'=>$amount,'money_account_id'=>$scope['cash_id'],
        'category_account_id'=>$scope[$kind==='receipt'?'income_id':'expense_id'],'counterparty'=>'null',
        'reference'=>'Upgrade '.$key,'memo'=>'Fictional upgrade proof','creation_key'=>$key];
}
function cash_party_probe_snapshot(): array {
    $snapshot=[];
    foreach (['pl_accounts','pl_documents','pl_journals','pl_journal_lines','pl_posting_identities','pl_posting_revisions','pl_correction_actions','pl_party_actions','pl_outbound_events','pl_schema_migrations'] as $table) {
        $columns=DB::queryFirstColumn('SHOW COLUMNS FROM %b',$table);
        $order=$table==='pl_schema_migrations'?'version':'id';
        $snapshot[$table]=['columns'=>$columns,'order'=>$order,'rows'=>DB::query('SELECT * FROM %b ORDER BY %b',$table,$order)];
    }
    return $snapshot;
}
function cash_party_probe_preserved(array $snapshot): void {
    foreach ($snapshot as $table=>$entry) {
        $rows=DB::query('SELECT * FROM %b ORDER BY %b',$table,$entry['order']);
        if ($table==='pl_schema_migrations') {
            $versions=array_column($entry['rows'],'version');
            $rows=array_values(array_filter($rows,static fn(array $row):bool=>in_array($row['version'],$versions,true)));
        }
        $rows=array_map(static fn(array $row):array=>array_intersect_key($row,array_flip($entry['columns'])),$rows);
        cash_party_probe_assert($rows===$entry['rows'],'Historical values changed in '.$table);
    }
}

if ($mode==='seed') {
    $database='phpledger_cash_party_upgrade_'.bin2hex(random_bytes(12));
    cash_party_probe_assert((int)DB::queryFirstField('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=%s',$database)===0,'Random schema unexpectedly exists.');
    DB::query('CREATE DATABASE %b CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',$database);
    echo 'Created isolated probe schema '.$database."\n";
    DB::useDB($database); pl_migrate();
    cash_party_probe_assert(!DB::queryFirstField("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME='pl_documents' AND COLUMN_NAME='party_id'",$database),'Seed must use the pre-change baseline.');
    $actor=pl_create_user('upgrade-cash-party@example.invalid','Fictional upgrade owner',bin2hex(random_bytes(32)));
    $company=pl_create_company($actor,'Fictional cash party upgrade','USD',gmdate('Y').'-01-01');
    $cash=pl_save_account($actor,$company['company_id'],$company['book_id'],['name'=>'Petty cash','code'=>'1099','type'=>'asset','role'=>'cash_bank','is_active'=>true,'reason'=>'Fictional legacy petty cash','creation_key'=>'upgrade-petty']);
    $scope=['actor_id'=>$actor,'company_id'=>$company['company_id'],'book_id'=>$company['book_id'],'cash_id'=>$cash['id'],
        'income_id'=>$company['accounts']['4000'],'expense_id'=>$company['accounts']['5000'],'date'=>gmdate('Y-m-d')];
    $receiptInput=cash_party_probe_document($scope,'receipt','5','legacy-receipt');
    $receipt=pl_save_document($actor,$scope['company_id'],$scope['book_id'],$receiptInput);
    pl_post_document($actor,$scope['company_id'],$scope['book_id'],$receipt['id'],1);
    $expenseInput=cash_party_probe_document($scope,'expense','20','legacy-expense');
    $expense=pl_save_document($actor,$scope['company_id'],$scope['book_id'],$expenseInput);
    pl_post_document($actor,$scope['company_id'],$scope['book_id'],$expense['id'],1);
    $correction=$receiptInput; $correction['amount']='6';
    pl_correct_source($actor,$scope['company_id'],$scope['book_id'],'receipt',$receipt['id'],1,$correction,null,'legacy-correction','Fictional corrected amount with literal null name');
    $party=pl_save_party($actor,$scope['company_id'],$scope['book_id'],['legal_name'=>'null','entity_type'=>'business','country_code'=>'US','currency'=>'USD','is_customer'=>true,'is_vendor'=>true,'request_key'=>'legacy-party','reason'=>'Matching name must not infer document links']);
    $balance=(string)DB::queryFirstField('SELECT SUM(debit-credit) FROM pl_journal_lines WHERE account_id=%i',$cash['id']);
    cash_party_probe_assert(bccomp($balance,'-14',4)===0,'Baseline did not seed the genuine negative balance.');
    $state=['host'=>getenv('PL_DB_HOST'),'database'=>$database,'scope'=>$scope,'receipt_id'=>$receipt['id'],'expense_id'=>$expense['id'],'party_id'=>$party['id'],
        'receipt_input'=>$receiptInput,'expense_input'=>$expenseInput,'snapshot'=>cash_party_probe_snapshot()];
    $handle=fopen($stateFile,'x'); if (!$handle) { throw new RuntimeException('Cannot exclusively create state file; probe schema retained.'); }
    fwrite($handle,json_encode($state,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)); fclose($handle); chmod($stateFile,0600);
    echo 'Baseline seeded: negative petty cash -14.0000, two posted text-only sources, correction, literal null name, matching unlinked party and immutable snapshots. State: '.$stateFile."\n";
    exit(0);
}

$state=json_decode((string)file_get_contents($stateFile),true,512,JSON_THROW_ON_ERROR);
$database=$state['database']??'';
cash_party_probe_assert(($state['host']??'')===getenv('PL_DB_HOST') && preg_match('/^phpledger_cash_party_upgrade_[a-f0-9]{24}$/D',$database)===1,'Probe state host/schema does not match this disposable service.');
cash_party_probe_assert((int)DB::queryFirstField('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=%s',$database)===1,'Probe schema is unavailable.');
DB::useDB($database);
$migrated=pl_migrate();
cash_party_probe_assert(in_array('050_document_parties',$migrated['applied'],true) && in_array('051_money_account_kind',$migrated['applied'],true) && in_array('052_bank_overdraft_limits',$migrated['applied'],true),'Expected all three additive migrations to apply.');
cash_party_probe_preserved($state['snapshot']);
cash_party_probe_assert((int)DB::queryFirstField('SELECT COUNT(*) FROM pl_documents WHERE party_id IS NOT NULL')===0,'Migration guessed a historical party link.');
cash_party_probe_assert((int)DB::queryFirstField('SELECT COUNT(*) FROM pl_accounts WHERE money_kind IS NOT NULL')===0,'Migration inferred cash or bank from historical codes/names.');
cash_party_probe_assert((int)DB::queryFirstField('SELECT COUNT(*) FROM pl_accounts WHERE overdraft_enabled <> 0 OR overdraft_limit <> 0')===0,'Migration invented an overdraft facility.');
$f=$state['scope']; $actor=$f['actor_id']; $company=$f['company_id']; $book=$f['book_id'];
$old=pl_get_document($actor,$company,$book,$state['receipt_id']);
cash_party_probe_assert($old['counterparty']==='null' && $old['party_id']===null && $old['amount']==='6.0000','Corrected historical literal null name or identity changed.');
cash_party_probe_assert(pl_save_document($actor,$company,$book,$state['receipt_input'])['id']===$state['receipt_id'],'Old receipt creation-key retry failed.');
cash_party_probe_assert(pl_save_document($actor,$company,$book,$state['expense_input'])['id']===$state['expense_id'],'Old expense creation-key retry failed.');
$cash=pl_get_account($actor,$company,$book,$f['cash_id']);
$classified=pl_save_account($actor,$company,$book,['name'=>$cash['name'],'code'=>$cash['code'],'type'=>$cash['type'],'role'=>$cash['role'],'is_active'=>true,'money_kind'=>'physical','reason'=>'Explicit owner classification in upgrade proof'],$cash['id'],$cash['revision']);
cash_party_probe_assert($classified['money_kind']==='physical','Explicit physical-cash classification failed.');
$funding=cash_party_probe_document($f,'receipt','2','fund-old-deficit'); $funding['party_id']=$state['party_id'];
$funded=pl_save_document($actor,$company,$book,$funding); pl_post_document($actor,$company,$book,$funded['id'],1);
cash_party_probe_assert(pl_get_document($actor,$company,$book,$funded['id'])['party_id']===$state['party_id'],'New linked receipt lost its identity.');
cash_party_probe_assert(bccomp((string)DB::queryFirstField('SELECT SUM(debit-credit) FROM pl_journal_lines WHERE account_id=%i',$f['cash_id']),'-12',4)===0,'Funding did not improve the existing deficit.');
$spend=cash_party_probe_document($f,'expense','1','worsen-old-deficit'); $spend['party_id']=$state['party_id'];
$draft=pl_save_document($actor,$company,$book,$spend); $rejected=false;
try { pl_post_document($actor,$company,$book,$draft['id'],1); } catch (DomainException $error) { $rejected=str_contains($error->getMessage(),'cash') || str_contains($error->getMessage(),'Cash'); }
cash_party_probe_assert($rejected && pl_get_document($actor,$company,$book,$draft['id'])['status']==='draft','Worsening an old deficit was not rejected atomically.');
cash_party_probe_assert(pl_migrate()['applied']===[],'Migration replay was not a no-op.');
echo 'PASS '.$state['host'].': historical values and receipts preserved; nullable classifications/links; explicit cash classification; improving funding; rejected worsening payment; linked name snapshot; no-op replay.' . "\n";
// Cleanup is intentionally success-only and restricted to this random, state-matched schema.
DB::useDB('phpledger_test'); DB::query('DROP DATABASE %b',$database); unlink($stateFile);
echo 'Removed only '.$database.' and its temporary probe state.' . "\n";
