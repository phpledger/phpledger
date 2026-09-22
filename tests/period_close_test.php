<?php
declare(strict_types=1);

test('close checklist hard computed facts cannot be waived and package filters cannot remove core items', function (): void {
    $f = ledger_fixture();
    DB::insert('pl_general_drafts', ['company_id'=>$f['company_id'],'book_id'=>$f['book_id'],'document_date'=>'2026-09-14','description'=>'Pending close work','reference'=>'pending','lines'=>'[]','creation_hash'=>str_repeat('a',64),'updated_by'=>$f['actor_id'],'created_by'=>$f['actor_id'],'creation_key'=>bin2hex(random_bytes(16))]);
    $check = pl_period_checklist($f['actor_id'],$f['company_id'],$f['book_id'],$f['period_id']);
    assert_true(!$check['ready']);
    pl_period_tick_item($f['actor_id'],$f['company_id'],$f['book_id'],$f['period_id'],'core.drafts','skipped','Must still block','waive-drafts');
    assert_throws(fn()=>pl_change_period_status($f['actor_id'],$f['company_id'],$f['book_id'],$f['period_id'],'closed',1,'Close with draft','close-draft'),DomainException::class,'required item');
    pl_add_filter('period.checklist', fn(array $items): array => []);
    try { assert_true(!pl_period_checklist($f['actor_id'],$f['company_id'],$f['book_id'],$f['period_id'])['ready']); }
    finally { pl_hook_reset(); }
});

test('company checklist attestation is audited, scoped, retry-safe and survives reopening', function (): void {
    $f=ledger_fixture();
    pl_period_save_checklist_item($f['actor_id'],$f['company_id'],$f['book_id'],['item_key'=>'company.review','label'=>'Accountant review','severity'=>'hard']);
    assert_throws(fn()=>pl_change_period_status($f['actor_id'],$f['company_id'],$f['book_id'],$f['period_id'],'closed',1,'Review','close'),DomainException::class,'Accountant review');
    $tick=pl_period_tick_item($f['actor_id'],$f['company_id'],$f['book_id'],$f['period_id'],'company.review','done','Accountant checked','tick');
    assert_same($tick,pl_period_tick_item($f['actor_id'],$f['company_id'],$f['book_id'],$f['period_id'],'company.review','done','Accountant checked','tick'));
    $close=pl_change_period_status($f['actor_id'],$f['company_id'],$f['book_id'],$f['period_id'],'closed',1,'Reviewed','close-ok');
    assert_throws(fn()=>pl_period_tick_item($f['actor_id'],$f['company_id'],$f['book_id'],$f['period_id'],'company.review','cleared','Rewrite closed','rewrite'),DomainException::class,'closed');
    pl_change_period_status($f['actor_id'],$f['company_id'],$f['book_id'],$f['period_id'],'open',2,'Correction','reopen');
    assert_true(isset(pl_period_ticks($f['company_id'],$f['book_id'],$f['period_id'])['company.review']));
    pl_period_tick_item($f['actor_id'],$f['company_id'],$f['book_id'],$f['period_id'],'company.review','cleared','New review','clear');
    assert_same($close,pl_change_period_status($f['actor_id'],$f['company_id'],$f['book_id'],$f['period_id'],'closed',1,'Reviewed','close-ok'));
});

test('scheduled reversals post on period creation and already-open periods without updating posted journals',function():void {
    $f=ledger_fixture();$j=pl_post_journal($f['actor_id'],$f['company_id'],$f['book_id'],ledger_payload($f));
    $scheduled=pl_schedule_journal_reversal($f['actor_id'],$f['company_id'],$f['book_id'],$j['id'],'2027-01-01','Accrual reversal');
    assert_same(null,$scheduled['reversal']);
    assert_throws(fn()=>DB::update('pl_journals',['description'=>'Rewrite'],'id=%i',$j['id']),Throwable::class,'immutable');
    $opened=pl_create_period($f['actor_id'],$f['company_id'],$f['book_id'],['start_date'=>'2027-01-01','end_date'=>'2027-12-31','reason'=>'Next year','request_key'=>'next-year']);
    assert_same(1,$opened['reversals_posted']);
    assert_same(1,(int)DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE reversal_of_id=%i',$j['id']));
    assert_same($opened,pl_create_period($f['actor_id'],$f['company_id'],$f['book_id'],['start_date'=>'2027-01-01','end_date'=>'2027-12-31','reason'=>'Next year','request_key'=>'next-year']));
    $j2=pl_post_journal($f['actor_id'],$f['company_id'],$f['book_id'],ledger_payload($f));
    $immediate=pl_schedule_journal_reversal($f['actor_id'],$f['company_id'],$f['book_id'],$j2['id'],'2027-02-01','Immediate reversal');
    assert_true($immediate['reversal']!==null);
    assert_same('2027-02-01',$immediate['reversal']['date']);
    assert_throws(fn()=>DB::query('DELETE FROM pl_journal_reversal_events WHERE journal_id=%i',$j2['id']),Throwable::class,'immutable');
});

test('scheduled reversal scope, closed target reopen and direct flag guard are enforced',function():void {
    $f=ledger_fixture();$other=ledger_fixture();$j=pl_post_journal($f['actor_id'],$f['company_id'],$f['book_id'],ledger_payload($f));
    assert_throws(fn()=>pl_schedule_journal_reversal($other['actor_id'],$f['company_id'],$f['book_id'],$j['id'],'2027-01-01','Wrong user'),DomainException::class);
    assert_throws(fn()=>pl_reverse_journal($f['actor_id'],$f['company_id'],$f['book_id'],$j['id'],'2026-09-15','forged-flag','Bypass',true),DomainException::class,'recorded schedule');
    $p=pl_create_period($f['actor_id'],$f['company_id'],$f['book_id'],['start_date'=>'2027-01-01','end_date'=>'2027-12-31','reason'=>'Next year','request_key'=>'next']);
    pl_change_period_status($f['actor_id'],$f['company_id'],$f['book_id'],$p['id'],'closed',1,'Review','close');
    pl_schedule_journal_reversal($f['actor_id'],$f['company_id'],$f['book_id'],$j['id'],'2027-01-01','On reopening');
    $opened=pl_change_period_status($f['actor_id'],$f['company_id'],$f['book_id'],$p['id'],'open',2,'Reopen year','reopen');
    assert_same(1,$opened['reversals_posted']);
});
