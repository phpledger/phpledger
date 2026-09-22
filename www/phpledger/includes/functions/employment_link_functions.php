<?php
declare(strict_types=1);

/** Assignments expose only the operational name; salary and private identity fields stay restricted. */
function pl_employee_assignment(int $actor,int $company,int $employeeId): array
{
    pl_employee_require_view($actor,$company);pl_employee_require_manage($actor,$company);
    $employee=pl_get_employee($actor,$company,$employeeId);
    $status=pl_employee_status_at($actor,$company,$employeeId,gmdate('Y-m-d'));
    if ($status===null || $status['employment_status']==='terminated') { throw new DomainException('Choose a currently employed person for a new assignment.'); }
    return ['id'=>$employee['id'],'name'=>$employee['full_name']];
}

function pl_link_employee_reference(int $actor,int $company,int $book,string $kind,int $record,int $employeeId,int $revision,string $reason): array
{
    $reason=pl_ledger_text($reason,'Reason',500);
    return pl_ledger_transaction(function()use($actor,$company,$book,$kind,$record,$employeeId,$revision,$reason):array{
        pl_require_company_access($actor,$company,true);pl_ledger_book($company,$book,true);
        $employee=pl_employee_assignment($actor,$company,$employeeId);
        pl_require_module($actor,$company,$book,$kind==='sales_staff'?'trading-documents':'inventory-locations');
        if (!in_array($kind,['sales_staff','driver'],true)) { throw new DomainException('Choose a sales-staff or driver record.'); }
        $table=$kind==='sales_staff'?'pl_sales_staff':'pl_inventory_warehouses';$field=$kind==='sales_staff'?'employee_id':'driver_employee_id';
        $row=DB::queryFirstRow('SELECT * FROM '.$table.' WHERE id=%i AND company_id=%i AND book_id=%i FOR UPDATE',$record,$company,$book);
        if (!$row || ($kind==='driver' && $row['kind']!=='mobile')) { throw new DomainException('That operational record is not in this company and book.'); }
        if ((int)$row['revision']!==$revision) { throw new DomainException('This assignment changed. Reload its current revision.'); }
        DB::update($table,[$field=>$employeeId,'revision'=>$revision+1],'id=%i',$record);
        $after=DB::queryFirstRow('SELECT * FROM '.$table.' WHERE id=%i',$record);
        pl_core_audit($actor,$company,$book,$kind==='sales_staff'?'sales_staff':'warehouse',$record,'employee_linked',$reason,$row,$after);
        return $after;
    });
}

function pl_link_employee_trade_party(int $actor,int $company,int $employeeId,?int $partyId,int $revision,string $reason): array
{
    $reason=pl_ledger_text($reason,'Reason',500);
    return pl_ledger_transaction(function()use($actor,$company,$employeeId,$partyId,$revision,$reason):array{
        pl_require_company_access($actor,$company,true);pl_employee_require_view($actor,$company);pl_employee_require_manage($actor,$company);
        DB::queryFirstField('SELECT id FROM pl_companies WHERE id=%i FOR UPDATE',$company);
        DB::queryFirstField('SELECT id FROM pl_employees WHERE id=%i AND company_id=%i FOR UPDATE',$employeeId,$company);
        $employee=pl_get_employee($actor,$company,$employeeId);
        if ($employee['revision']!==$revision) { throw new DomainException('This employee changed. Reload its current revision.'); }
        if ($partyId!==null) {
            $party=DB::queryFirstRow('SELECT id,entity_type FROM pl_parties WHERE id=%i AND company_id=%i FOR SHARE',$partyId,$company);
            if (!$party || $party['entity_type']!=='individual') { throw new DomainException('Choose a person trading in this company.'); }
            $other=DB::queryFirstField('SELECT id FROM pl_employees WHERE company_id=%i AND trade_party_id=%i AND id<>%i FOR UPDATE',$company,$partyId,$employeeId);
            if ($other!==null) { throw new DomainException('That trade party is already linked to another employee.'); }
        }
        DB::update('pl_employees',['trade_party_id'=>$partyId,'revision'=>$revision+1,'updated_at'=>gmdate('Y-m-d H:i:s')],'id=%i AND company_id=%i',$employeeId,$company);
        $after=pl_get_employee($actor,$company,$employeeId);
        pl_employee_audit($actor,$company,$employeeId,'trade_linked',$reason,$employee,$after);
        pl_employee_emit('employee.updated',['company_id'=>$company,'employee'=>$after]);
        return $after;
    });
}

/** Read-only comparison of collected fields. A match never creates a link or a marker. */
function pl_employee_trade_candidates(int $actor,int $company): array
{
    pl_employee_require_view($actor,$company);pl_related_party_require_view($actor,$company);
    $normalize=static fn(string $value):string=>(string)preg_replace('/[^\p{L}\p{N}]+/u','',mb_strtolower(trim($value),'UTF-8'));
    $identifiers=[];
    foreach(DB::query('SELECT party_id,normalized_value FROM pl_party_identifiers WHERE company_id=%i',$company) as $id){$identifiers[(int)$id['party_id']][]=$normalize($id['normalized_value']);}
    $parties=DB::query('SELECT p.id,p.legal_name,p.trading_name,d.addresses FROM pl_parties p LEFT JOIN pl_party_details d ON d.party_id=p.id AND d.company_id=p.company_id WHERE p.company_id=%i',$company);
    $matches=[];
    foreach(pl_list_employees($actor,$company) as $employee){
        foreach($parties as $party){
            $reasons=[];$name=$normalize($employee['full_name']);$identifier=$normalize((string)$employee['national_identifier']);$address=$normalize((string)$employee['address']);
            if($name!=='' && in_array($name,[$normalize($party['legal_name']),$normalize((string)$party['trading_name'])],true)){$reasons[]='name';}
            if($identifier!=='' && in_array($identifier,$identifiers[(int)$party['id']]??[],true)){$reasons[]='identifier';}
            foreach(json_decode($party['addresses']??'[]',true,64,JSON_THROW_ON_ERROR) as $entry){
                if(!is_array($entry)){continue;}$values=[];foreach($entry as $key=>$value){if($key!=='role' && is_string($value)){$values[]=$value;}}
                if($address!=='' && $normalize(implode(' ',$values))===$address){$reasons[]='address';break;}
            }
            if($reasons!==[]){$matches[]=['employee_id'=>$employee['id'],'employee_name'=>$employee['full_name'],'party_id'=>(int)$party['id'],'party_name'=>$party['legal_name'],'matched_on'=>$reasons,'linked'=>(int)($employee['trade_party_id']??0)===(int)$party['id']];}
        }
    }
    return $matches;
}

/** Posted documents retain their captured operational labels after personnel changes. */
function pl_employee_document_label(string $kind,int $id,int $company,int $book): ?array
{
    return DB::queryFirstRow('SELECT staff_name,from_driver_name,to_driver_name FROM pl_employee_document_labels WHERE source_kind=%s AND source_id=%i AND company_id=%i AND book_id=%i',$kind,$id,$company,$book) ?: null;
}
function pl_employee_invoice_staff(int $actor,int $company,int $book,array $document): ?array
{
    if ($document['sales_staff_id']===null) { return null; }
    $staff=pl_get_sales_staff($actor,$company,$book,(int)$document['sales_staff_id']);
    $labels=pl_employee_document_label('invoice',(int)$document['id'],$company,$book);
    if ($labels!==null) { $staff['name']=$labels['staff_name']; }
    return $staff;
}
