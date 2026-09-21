<?php
declare(strict_types=1);

/** Display preferences never participate in accounting permission or posting decisions. */
function pl_company_visibility(int $actorId, int $companyId): array
{
    pl_require_company_access($actorId, $companyId);
    $row = DB::queryFirstRow('SELECT show_ar,show_ap,revision FROM pl_company_visibility WHERE company_id=%i FOR SHARE', $companyId);
    return ['show_ar' => $row ? (bool)$row['show_ar'] : true, 'show_ap' => $row ? (bool)$row['show_ap'] : true,
        'revision' => $row ? (int)$row['revision'] : 0];
}

function pl_set_company_visibility(int $actorId, int $companyId, bool $showAr, bool $showAp, int $revision, string $reason, string $key): array
{
    pl_demo_require_setup_action();
    $reason = pl_ledger_text($reason, 'Reason', 500);
    $key = pl_request_key($key);
    $hash = hash('sha256', json_encode([$actorId,$companyId,$showAr,$showAp,$revision,$reason], JSON_THROW_ON_ERROR));
    return pl_ledger_transaction(function () use ($actorId,$companyId,$showAr,$showAp,$revision,$reason,$key,$hash): array {
        pl_require_company_access($actorId,$companyId,true);
        if (!pl_user_can($actorId,$companyId,'navigation.manage')) { throw new DomainException('Your role cannot change navigation for this company.'); }
        DB::queryFirstField('SELECT id FROM pl_companies WHERE id=%i FOR UPDATE', $companyId);
        $prior = DB::queryFirstRow('SELECT payload_hash,result_json FROM pl_visibility_actions WHERE company_id=%i AND request_key=%s FOR UPDATE', $companyId,$key);
        if ($prior) {
            if (!hash_equals($prior['payload_hash'],$hash)) { throw new DomainException('Navigation request already used with different content.'); }
            return json_decode($prior['result_json'],true,512,JSON_THROW_ON_ERROR);
        }
        $before = pl_company_visibility($actorId,$companyId);
        if ($revision !== $before['revision']) { throw new DomainException('Navigation changed. Reload before saving.'); }
        $result = ['show_ar'=>$showAr,'show_ap'=>$showAp,'revision'=>$revision+1];
        DB::insertUpdate('pl_company_visibility',['company_id'=>$companyId,'show_ar'=>(int)$showAr,'show_ap'=>(int)$showAp,'revision'=>$revision+1,'updated_by'=>$actorId]);
        DB::insert('pl_visibility_actions',['company_id'=>$companyId,'actor_id'=>$actorId,'request_key'=>$key,'payload_hash'=>$hash,
            'result_json'=>json_encode($result,JSON_THROW_ON_ERROR),'reason'=>$reason]);
        return $result;
    });
}
