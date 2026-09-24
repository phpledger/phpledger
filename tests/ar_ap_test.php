<?php
declare(strict_types=1);

function ar_ap_fixture(): array
{
    $f = ledger_fixture('USD');
    $party = pl_save_party($f['actor_id'], $f['company_id'], $f['book_id'], ['legal_name'=>'Sample starter party','entity_type'=>'private_company','country_code'=>'GB','is_customer'=>true,'is_vendor'=>true,'currency'=>'USD','request_key'=>bin2hex(random_bytes(16)),'reason'=>'Sample starter fixture']);
    return $f + ['party_id'=>$party['id']];
}

function ar_ap_input(array $f, string $kind = 'invoice', string $amount = '1000'): array
{
    return ['kind'=>$kind,'party_id'=>$f['party_id'],'date'=>'2026-01-05','due_date'=>'2026-02-04','currency'=>'USD','reference'=>'Sample reference','creation_key'=>bin2hex(random_bytes(16)),
        'lines'=>[['description'=>'Sample service','quantity'=>'1','unit_price'=>$amount,'account_id'=>$f['accounts'][in_array($kind,['bill','supplier_credit'],true) ? '5000' : '4000']]]];
}

function ar_ap_payment(array $f, string $amount, string $date): array
{
    return ['bank_account_id'=>$f['accounts']['1000'],'gain_account_id'=>$f['accounts']['4000'],'loss_account_id'=>$f['accounts']['5000'],'amount_fc'=>$amount,'date'=>$date,'description'=>'Sample allocated bank payment','idempotency_key'=>bin2hex(random_bytes(16))];
}

test('AR AP line pickers and services reject headings parents inactive foreign and wrong-role accounts', function (): void {
    require_once dirname(__DIR__) . '/www/phpledger/includes/functions/starter_web_functions.php';
    $f = ar_ap_fixture(); $other = ar_ap_fixture();
    $make = static fn(string $code, string $type, bool $active = true): array => pl_save_account($f['actor_id'], $f['company_id'], $f['book_id'], [
        'code'=>$code,'name'=>'Sample eligibility '.$code,'type'=>$type,'role'=>null,'is_active'=>$active,'reason'=>'Line account regression.','creation_key'=>bin2hex(random_bytes(16))]);
    foreach (['invoice'=>['4','income','4000'], 'bill'=>['5','expense','5000']] as $kind => [$class,$type,$default]) {
        $heading = $make($class.'-800-00000-00', $type);
        $parent = $make($class.'-800-00101-00', $type);
        $child = $make($class.'-800-00101-01', $type, false);
        $leaf = $make($class.'-800-00102-00', $type);
        $inactive = $make($class.'-800-00103-00', $type, false);
        $accounts = pl_starter_accounts($f['actor_id'], $f['company_id'], $f['book_id']);
        $allIds = array_map('intval', array_column($accounts, 'id'));
        assert_true(in_array($heading['id'], $allIds, true), 'General account lists retain headings.');
        $options = pl_starter_options(array_filter($accounts, static fn(array $a):bool=>pl_ar_line_account_eligible($a, $kind==='invoice')));
        assert_true(isset($options[$leaf['id']]), 'Eligible leaves remain selectable.');
        $classHeading = (int) DB::queryFirstField('SELECT id FROM pl_accounts WHERE book_id=%i AND code=%s', $f['book_id'], $class.'-000-00000-00');
        $badIds = [$classHeading, $heading['id'], $parent['id'], $child['id'], $inactive['id'], $other['accounts'][$default], $f['accounts']['1000'], $f['accounts'][$kind==='invoice'?'5000':'4000']];
        foreach ($badIds as $accountId) {
            assert_true(!isset($options[$accountId]), 'Ineligible account is absent from line options.');
            $input = ar_ap_input($f, $kind); $input['lines'][0]['account_id'] = $accountId;
            assert_throws(fn()=>pl_save_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$input), DomainException::class);
            assert_throws(fn()=>pl_preview_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$input), DomainException::class);
        }
        assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_ar_documents WHERE book_id=%i', $f['book_id']));
        assert_same(0, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id=%i', $f['book_id']));
    }
});

test('AR AP stale draft accounts are rechecked before preview edit and posting without losing the draft', function (): void {
    foreach (['invoice'=>'4', 'bill'=>'5'] as $kind=>$class) {
        $f=ar_ap_fixture(); $type=$kind==='invoice'?'income':'expense';
        $make=static fn(string $code):array=>pl_save_account($f['actor_id'],$f['company_id'],$f['book_id'],[
            'code'=>$code,'name'=>'Sample stale account','type'=>$type,'role'=>null,'is_active'=>true,'reason'=>'Stale draft regression.','creation_key'=>bin2hex(random_bytes(16))]);
        $parent=$make($class.'-800-00101-00'); $input=ar_ap_input($f,$kind); $input['lines'][0]['account_id']=$parent['id'];
        $draft=pl_save_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$input);
        pl_preview_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$input,$draft['id'],1);
        $child=$make($class.'-800-00101-01');
        assert_throws(fn()=>pl_save_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$input,$draft['id'],1),DomainException::class,'postable');
        assert_throws(fn()=>pl_preview_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$input,$draft['id'],1),DomainException::class,'postable');
        assert_throws(fn()=>pl_post_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$draft['id'],1),DomainException::class,'postable');
        $retained=pl_get_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$draft['id']);
        assert_same(1,$retained['revision']); assert_same('draft',$retained['status']); assert_same($parent['id'],$retained['lines'][0]['account_id']);
        $input['lines'][0]['account_id']=$child['id'];
        $repaired=pl_save_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$input,$draft['id'],1);
        $posted=pl_post_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$repaired['id'],2);
        assert_true($posted['journal_id']!==null);
    }
});

test('AR AP exact draft lines round once and prohibit quote kinds', function (): void {
    assert_same('0.0001',pl_ar_line_amount('0.0001','0.5'));
    assert_same('3.7035',pl_ar_line_amount('3','1.2345'));
    assert_throws(fn()=>pl_ar_line_amount('3','1.234567'),DomainException::class);
});

test('domestic settlement does not require unused realised FX accounts', function (): void {
    $f = ar_ap_fixture(); $draft = pl_save_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], ar_ap_input($f));
    $doc = pl_post_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], $draft['id'], 1);
    $payment = ar_ap_payment($f, '1000', '2026-01-06');
    unset($payment['gain_account_id'], $payment['loss_account_id']);
    $settled = pl_settle_ar_document($f['actor_id'], $f['company_id'], $f['book_id'], $doc['id'], $payment);
    assert_same('1000.0000', $settled['allocated_fc']);
    assert_same('1000.0000', $settled['settlement_base']);
});

test('AR AP shared starter supports invoice receipt credit final receipt and historical ageing', function (): void {
    $f=ar_ap_fixture(); $input=ar_ap_input($f);
    $draft=pl_save_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$input);
    assert_same($draft['id'],pl_save_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$input)['id']);
    assert_same(0,(int)DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id=%i',$f['book_id']));
    $doc=pl_post_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$draft['id'],1);
    assert_same('1000.0000',$doc['outstanding_fc']);
    assert_same($doc,pl_post_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$draft['id'],1));
    $payment=ar_ap_payment($f,'400','2026-02-10');
    $paid=pl_settle_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$doc['id'],$payment);
    assert_same($paid,pl_settle_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$doc['id'],$payment));
    $credit=ar_ap_input($f,'customer_credit','100'); $credit['original_document_id']=$doc['id']; $credit['date']='2026-02-11'; $credit['due_date']='2026-02-11';
    $creditDoc=pl_save_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$credit);
    $credited=pl_post_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$creditDoc['id'],1);
    assert_same('applied',$credited['payment_status']); assert_same($doc['open_item_id'],$credited['open_item_id']);
    $historical=pl_ar_ap_open_items($f['actor_id'],$f['company_id'],$f['book_id'],'receivable','2026-02-09');
    assert_same('1000.0000',$historical['total_base']); assert_same('1000.0000',$historical['buckets']['1_30']); assert_true($historical['reconciled']);
    $partial=pl_ar_ap_open_items($f['actor_id'],$f['company_id'],$f['book_id'],'receivable','2026-02-11');
    assert_same('500.0000',$partial['total_base']); assert_same('0.0000',$partial['difference_base']);
    pl_settle_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$doc['id'],ar_ap_payment($f,'500','2026-02-12'));
    assert_same('paid',pl_get_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$doc['id'])['payment_status']);
    assert_true(pl_ar_ap_open_items($f['actor_id'],$f['company_id'],$f['book_id'],'receivable','2026-02-12')['reconciled']);
    assert_same(1,(int)DB::queryFirstField('SELECT COUNT(*) FROM pl_open_items WHERE book_id=%i',$f['book_id']));
    $quote=$input; $quote['kind']='quote'; assert_throws(fn()=>pl_normalize_ar_document($quote),DomainException::class);
});

test('AP supplier bill partial payment and supplier credit use payable direction', function (): void {
    $f=ar_ap_fixture(); $d=pl_save_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],ar_ap_input($f,'bill'));
    $d=pl_post_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$d['id'],1);
    pl_settle_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$d['id'],ar_ap_payment($f,'400','2026-02-10'));
    $c=ar_ap_input($f,'supplier_credit','100'); $c['original_document_id']=$d['id']; $c['date']='2026-02-11'; $c['due_date']=$c['date'];
    $c=pl_save_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$c); pl_post_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$c['id'],1);
    pl_settle_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$d['id'],ar_ap_payment($f,'500','2026-02-12'));
    $report=pl_ar_ap_open_items($f['actor_id'],$f['company_id'],$f['book_id'],'payable','2026-02-12');
    assert_same('0.0000',$report['total_base']); assert_true($report['reconciled']);
});

test('AR AP rejects excessive credits and settled-document correction atomically', function (): void {
    $f=ar_ap_fixture(); $d=pl_save_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],ar_ap_input($f)); $d=pl_post_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$d['id'],1);
    pl_settle_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$d['id'],ar_ap_payment($f,'400','2026-02-10'));
    $c=ar_ap_input($f,'customer_credit','601'); $c['original_document_id']=$d['id']; $c['date']='2026-02-11'; $c['due_date']=$c['date'];
    $c=pl_save_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$c);
    assert_throws(fn()=>pl_post_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$c['id'],1),DomainException::class,'over-allocated');
    assert_same('draft',pl_get_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$c['id'])['status']);
    assert_throws(fn()=>pl_reverse_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$d['id'],null,'Wrong total'),DomainException::class,'allocations');
    assert_same('600.0000',pl_get_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$d['id'])['outstanding_fc']);
});

test('AR AP correction preserves source identity original rows and historical control totals', function (): void {
    $f=ar_ap_fixture(); $input=ar_ap_input($f); $draft=pl_save_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$input);
    $d=pl_post_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$draft['id'],1); $originalJournal=$d['journal_id'];
    $input['date']=gmdate('Y-m-d'); $input['due_date']=gmdate('Y-m-d'); $input['lines'][0]['unit_price']='1200'; $key=bin2hex(random_bytes(16));
    $corrected=pl_correct_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$d['id'],$input,1,$key,'Correct service price');
    assert_same($d['id'],$corrected['id']); assert_same($d['number'],$corrected['number']); assert_same(2,$corrected['revision']); assert_same('1200.0000',$corrected['outstanding_fc']);
    assert_same((string)$originalJournal,(string)DB::queryFirstField('SELECT journal_id FROM pl_ar_documents WHERE id=%i',$d['id']));
    assert_same('1000.0000',DB::queryFirstField('SELECT subtotal FROM pl_ar_documents WHERE id=%i',$d['id']));
    assert_same($corrected,pl_correct_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$d['id'],$input,1,$key,'Correct service price'));
    assert_same('1000.0000',pl_ar_ap_open_items($f['actor_id'],$f['company_id'],$f['book_id'],'receivable','2026-02-01')['total_base']);
    $current=pl_ar_ap_open_items($f['actor_id'],$f['company_id'],$f['book_id'],'receivable'); assert_same('1200.0000',$current['total_base']); assert_true($current['reconciled']);
});

test('AR AP posted source snapshots and lines reject edits inserts and deletes', function (): void {
    $f=ar_ap_fixture(); $d=pl_save_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],ar_ap_input($f)); $d=pl_post_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$d['id'],1);
    assert_throws(fn()=>DB::update('pl_ar_documents',['notes'=>'changed'],'id=%i',$d['id']),Throwable::class,'immutable');
    assert_throws(fn()=>DB::update('pl_ar_document_lines',['quantity'=>'2'],'document_id=%i',$d['id']),Throwable::class,'immutable');
    assert_throws(fn()=>DB::delete('pl_ar_document_revisions','document_id=%i',$d['id']),Throwable::class,'immutable');
    assert_throws(fn()=>DB::insert('pl_ar_document_lines',['document_id'=>$d['id'],'company_id'=>$f['company_id'],'book_id'=>$f['book_id'],'line_number'=>2,'description'=>'late line','quantity'=>'1','unit_price'=>'1','line_total'=>'1']),Throwable::class,'immutable');
});

test('AR AP posting rejects changed review closed periods invalid roles and foreign company access', function (): void {
    $f=ar_ap_fixture(); $input=ar_ap_input($f); $d=pl_save_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$input);
    assert_throws(fn()=>pl_post_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$d['id'],2),DomainException::class,'latest draft');
    DB::update('pl_periods',['status'=>'closed'],'id=%i',$f['period_id']);
    assert_throws(fn()=>pl_post_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$d['id'],1),DomainException::class,'open accounting period');
    DB::update('pl_periods',['status'=>'open'],'id=%i',$f['period_id']);
    $other=ar_ap_fixture(); assert_throws(fn()=>pl_get_ar_document($other['actor_id'],$other['company_id'],$other['book_id'],$d['id']),DomainException::class);
    $input['lines'][0]['account_id']=$f['accounts']['1000'];
    assert_throws(fn()=>pl_save_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$input,$d['id'],1),DomainException::class,'income line account');
    // A valid saved draft can become stale when its account is retired before posting.
    DB::update('pl_accounts',['is_active'=>0],'id=%i',$f['accounts']['4000']);
    assert_throws(fn()=>pl_post_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$d['id'],1),DomainException::class,'active postable');
    assert_same(0,(int)DB::queryFirstField('SELECT COUNT(*) FROM pl_open_items WHERE book_id=%i',$f['book_id']));
});

test('AR AP line FX rounding is explicit and final credits consume the exact carrying residual', function (): void {
    $f=ar_ap_fixture(); $input=ar_ap_input($f); $input['currency']='EUR'; $input['rounding_account_id']=$f['accounts']['5000'];
    $line=$input['lines'][0]; $line['unit_price']='1'; $input['lines']=[$line,$line,$line];
    $d=pl_save_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$input); $d=pl_post_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$d['id'],1,null,'0.333333333333');
    $j=pl_get_journal($f['actor_id'],$f['company_id'],$f['book_id'],$d['journal_id']); assert_same(5,count($j['lines'])); assert_same('Explicit currency rounding',$j['lines'][4]['description']);
    $payment=ar_ap_payment($f,'1','2026-02-10'); $payment['actual_rate']='0.4'; pl_settle_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$d['id'],$payment);
    $credit=ar_ap_input($f,'customer_credit','2'); $credit['original_document_id']=$d['id']; $credit['date']='2026-02-11'; $credit['due_date']=$credit['date']; $credit['currency']='EUR'; $credit['rounding_account_id']=$f['accounts']['5000'];
    $credit=pl_save_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$credit); pl_post_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$credit['id'],1);
    $item=pl_get_open_item($f['actor_id'],$f['company_id'],$f['book_id'],$d['open_item_id']); assert_same('0.0000',$item['remaining_fc']); assert_same('0.0000',$item['remaining_base']);
});

test('AR AP competing postings settlements and credits serialize across separate connections', function (): void {
    $f=ar_ap_fixture(); $d=pl_save_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],ar_ap_input($f));
    $job=['mode'=>'ar_post','fixture'=>$f,'document_id'=>$d['id'],'revision'=>1];
    $r=ledger_race([$job,$job]); assert_same($r[0]['id'],$r[1]['id']);
    assert_same(1,(int)DB::queryFirstField('SELECT COUNT(*) FROM pl_open_items WHERE book_id=%i',$f['book_id']));
    $payment=ar_ap_payment($f,'600','2026-02-10');
    $one=['mode'=>'ar_settle','fixture'=>$f,'document_id'=>$d['id'],'settlement_input'=>$payment,'allow_domain_failure'=>true];
    $two=$one; $two['settlement_input']['idempotency_key']=bin2hex(random_bytes(16));
    $r=ledger_race([$one,$two]); assert_same(1,count(array_filter($r,static fn(array $row):bool=>$row['id']>0)));
    $c=ar_ap_input($f,'customer_credit','300'); $c['original_document_id']=$d['id']; $c['date']='2026-02-11'; $c['due_date']=$c['date'];
    $first=pl_save_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$c); $c['creation_key']=bin2hex(random_bytes(16)); $second=pl_save_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$c);
    $one=['mode'=>'ar_credit_post','fixture'=>$f,'document_id'=>$first['id'],'revision'=>1,'allow_domain_failure'=>true]; $two=$one; $two['document_id']=$second['id'];
    $r=ledger_race([$one,$two]); assert_same(1,count(array_filter($r,static fn(array $row):bool=>$row['id']>0)));
    assert_same('100.0000',pl_get_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$d['id'])['outstanding_fc']);
});

function ar_ap_tax_fixture(): array
{
    $f=ar_ap_fixture();
    foreach (['sales'=>['2200','liability'],'purchase'=>['1400','asset']] as $name=>[$code,$type]) {
        $account=pl_save_account($f['actor_id'],$f['company_id'],$f['book_id'],['code'=>$code,'name'=>'Sample tax '.$name,'type'=>$type,'role'=>null,'is_active'=>true,'creation_key'=>bin2hex(random_bytes(16)),'reason'=>'Sample tax account']);
        $f[$name.'_tax_account_id']=$account['id'];
    }
    $code=pl_create_tax_code($f['actor_id'],$f['company_id'],$f['book_id'],['code'=>'SYN-TAX','name'=>'Sample sales tax','treatment'=>'standard','sales_account_id'=>$f['sales_tax_account_id'],'purchase_account_id'=>$f['purchase_tax_account_id'],'reason'=>'Sample tax configuration','idempotency_key'=>bin2hex(random_bytes(16))]);
    pl_enter_tax_rate($f['actor_id'],$f['company_id'],$f['book_id'],['tax_code_id'=>$code['id'],'effective_from'=>'2026-01-01','percentage'=>'10','reason'=>'Sample initial rate','idempotency_key'=>bin2hex(random_bytes(16))]);
    return $f+['tax_code_id'=>$code['id']];
}

test('AR AP mixed tax lines post gross controls and separate output and input taxes', function (): void {
    foreach (['invoice','bill'] as $kind) {
        $f=ar_ap_tax_fixture(); $input=ar_ap_input($f,$kind,'100'); $input['lines'][0]['tax_code_id']=$f['tax_code_id'];
        $untaxed=$input['lines'][0]; unset($untaxed['tax_code_id']); $untaxed['unit_price']='50'; $input['lines'][]=$untaxed;
        $d=pl_save_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$input); assert_same('150.0000',$d['subtotal']); assert_same('10.0000',$d['tax_total']); assert_same('160.0000',$d['total']);
        $d=pl_post_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$d['id'],1);
        $j=pl_get_journal($f['actor_id'],$f['company_id'],$f['book_id'],$d['journal_id']); assert_same(4,count($j['lines'])); assert_same('160.0000',$j['lines'][0]['amount_fc']);
        assert_same((string)$f[$kind==='invoice'?'sales_tax_account_id':'purchase_tax_account_id'],(string)$j['lines'][3]['account_id']); assert_same('10.0000',$j['lines'][3][$kind==='invoice'?'credit':'debit']);
        assert_true(pl_ar_ap_open_items($f['actor_id'],$f['company_id'],$f['book_id'],$kind==='invoice'?'receivable':'payable')['reconciled']);
    }
});

test('AR AP changed effective tax rate requires draft review and never restates posted tax', function (): void {
    $f=ar_ap_tax_fixture(); $input=ar_ap_input($f,'invoice','100'); $input['lines'][0]['tax_code_id']=$f['tax_code_id'];
    $draft=pl_save_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$input);
    pl_enter_tax_rate($f['actor_id'],$f['company_id'],$f['book_id'],['tax_code_id'=>$f['tax_code_id'],'effective_from'=>'2026-01-01','percentage'=>'12','reason'=>'Sample revised rate','idempotency_key'=>bin2hex(random_bytes(16))]);
    assert_throws(fn()=>pl_post_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$draft['id'],1),DomainException::class,'effective tax rate');
    $draft=pl_save_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$input,$draft['id'],1); assert_same('112.0000',$draft['total']);
    $posted=pl_post_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$draft['id'],2);
    pl_enter_tax_rate($f['actor_id'],$f['company_id'],$f['book_id'],['tax_code_id'=>$f['tax_code_id'],'effective_from'=>'2026-01-01','percentage'=>'15','reason'=>'Sample later correction','idempotency_key'=>bin2hex(random_bytes(16))]);
    $current=pl_get_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$posted['id']); assert_same('12.0000',$current['tax_total']); assert_same('112.0000',$current['outstanding_fc']);
});

test('AR AP tax credits preserve original tax snapshot and exact residual across partial credits', function (): void {
    $f=ar_ap_tax_fixture(); $input=ar_ap_input($f,'invoice','0.0005'); $input['lines'][0]['tax_code_id']=$f['tax_code_id'];
    $d=pl_save_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$input); $d=pl_post_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$d['id'],1); assert_same('0.0006',$d['total']);
    pl_enter_tax_rate($f['actor_id'],$f['company_id'],$f['book_id'],['tax_code_id'=>$f['tax_code_id'],'effective_from'=>'2026-01-01','percentage'=>'20','reason'=>'Sample rate after invoice','idempotency_key'=>bin2hex(random_bytes(16))]);
    foreach (['0.0002','0.0003'] as $amount) {
        $credit=ar_ap_input($f,'customer_credit',$amount); $credit['original_document_id']=$d['id']; $credit['date']='2026-02-10'; $credit['due_date']=$credit['date']; $credit['lines'][0]['original_line_number']=1;
        $credit=pl_save_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$credit); assert_same('10.000000',$credit['lines'][0]['tax_rate']);
        pl_post_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$credit['id'],1);
    }
    $current=pl_get_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$d['id']); assert_same('0.0000',$current['outstanding_fc']);
    $credit=ar_ap_input($f,'customer_credit','0.0001'); $credit['original_document_id']=$d['id']; $credit['lines'][0]['original_line_number']=1;
    assert_throws(fn()=>pl_save_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$credit),DomainException::class,'original line remaining');
});

test('AR AP inclusive setting freezes entered prices and original-mode credits retain gross totals', function (): void {
    $f=ar_ap_tax_fixture(); pl_set_tax_price_mode($f['actor_id'],$f['company_id'],$f['book_id'],'inclusive',0,'Sample inclusive pricing',bin2hex(random_bytes(16)));
    $input=ar_ap_input($f,'invoice','110'); $input['lines'][0]['tax_code_id']=$f['tax_code_id'];
    $d=pl_save_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$input);
    assert_same('inclusive',$d['price_mode']); assert_same('100.0000',$d['subtotal']); assert_same('10.0000',$d['tax_total']); assert_same('110.0000',$d['total']); assert_same('110.0000',$d['lines'][0]['unit_price']);
    pl_set_tax_price_mode($f['actor_id'],$f['company_id'],$f['book_id'],'exclusive',1,'Sample changed default',bin2hex(random_bytes(16)));
    $d=pl_post_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$d['id'],1); assert_same('110.0000',$d['outstanding_fc']);
    $credit=ar_ap_input($f,'customer_credit','55'); $credit['original_document_id']=$d['id']; $credit['date']='2026-02-10'; $credit['due_date']=$credit['date']; $credit['lines'][0]['original_line_number']=1;
    $wrong=$credit; $wrong['price_mode']='exclusive'; assert_throws(fn()=>pl_save_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$wrong),DomainException::class,'original document price mode');
    for ($i=0;$i<2;$i++) {
        $credit['creation_key']=bin2hex(random_bytes(16)); $c=pl_save_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$credit);
        assert_same('inclusive',$c['price_mode']); assert_same('50.0000',$c['subtotal']); assert_same('5.0000',$c['tax_total']); assert_same('55.0000',$c['total']);
        pl_post_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$c['id'],1);
    }
    assert_same('0.0000',pl_get_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$d['id'])['outstanding_fc']);
});

test('AR AP many small tax credits consume remaining tax without cumulative rounding over-credit', function (): void {
    $f=ar_ap_tax_fixture(); pl_enter_tax_rate($f['actor_id'],$f['company_id'],$f['book_id'],['tax_code_id'=>$f['tax_code_id'],'effective_from'=>'2026-01-01','percentage'=>'50','reason'=>'Sample rounding boundary','idempotency_key'=>bin2hex(random_bytes(16))]);
    $input=ar_ap_input($f,'invoice','0.0010'); $input['lines'][0]['tax_code_id']=$f['tax_code_id'];
    $d=pl_save_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$input); $d=pl_post_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$d['id'],1); assert_same('0.0015',$d['total']);
    $tax='0.0000';
    for ($i=0;$i<10;$i++) {
        $input=ar_ap_input($f,'customer_credit','0.0001'); $input['original_document_id']=$d['id']; $input['date']='2026-02-10'; $input['due_date']=$input['date']; $input['lines'][0]['original_line_number']=1;
        $credit=pl_save_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$input); $tax=bcadd($tax,$credit['tax_total'],4);
        pl_post_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$credit['id'],1);
    }
    assert_same('0.0005',$tax); assert_same('0.0000',pl_get_ar_document($f['actor_id'],$f['company_id'],$f['book_id'],$d['id'])['outstanding_fc']);
    assert_true(pl_ar_ap_open_items($f['actor_id'],$f['company_id'],$f['book_id'],'receivable')['reconciled']);
});
