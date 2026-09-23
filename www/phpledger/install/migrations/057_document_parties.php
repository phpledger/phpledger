<?php
declare(strict_types=1);

// Existing text-only sources and immutable snapshots remain untouched.
$sql = ['ALTER TABLE pl_documents ADD party_id BIGINT UNSIGNED NULL AFTER counterparty, ADD KEY ix_document_party (party_id,company_id), ADD CONSTRAINT fk_document_party FOREIGN KEY (party_id,company_id) REFERENCES pl_parties(id,company_id)'];
$columns = ['d.id','d.company_id','d.book_id'];
foreach (['kind','document_date','amount','money_account_id','category_account_id','counterparty','party_id','reference','memo'] as $field) {
    $extract = "JSON_UNQUOTE(JSON_EXTRACT(v.source_snapshot, '$.{$field}'))";
    if ($field === 'party_id') { $extract = "NULLIF({$extract}, 'null')"; }
    if ($field === 'amount') { $extract = "CAST({$extract} AS DECIMAL(20,4))"; }
    if (str_ends_with($field, '_id')) { $extract = "CAST({$extract} AS UNSIGNED)"; }
    if ($field === 'document_date') { $extract = "CAST({$extract} AS DATE)"; }
    $columns[] = $field === 'party_id'
        ? "CASE WHEN JSON_CONTAINS_PATH(v.source_snapshot,'one','$.party_id') THEN {$extract} ELSE d.party_id END AS party_id"
        : "COALESCE({$extract},d.`{$field}`) AS `{$field}`";
}
array_push($columns,'COALESCE(v.revision,d.revision) AS revision','d.creation_key','d.creation_hash','COALESCE(v.journal_id,d.journal_id) AS journal_id','d.journal_id AS original_journal_id','d.created_by','COALESCE(v.actor_id,d.updated_by) AS updated_by','d.created_at','COALESCE(v.recorded_at,d.updated_at) AS updated_at');
$sql[] = 'CREATE OR REPLACE VIEW pl_effective_documents AS SELECT '.implode(', ',$columns)
    .' FROM pl_documents d LEFT JOIN pl_posting_identities i ON i.company_id=d.company_id AND i.book_id=d.book_id AND i.source_type=d.kind AND i.source_id=d.id'
    .' LEFT JOIN pl_posting_revisions v ON v.identity_id=i.id AND v.revision=(SELECT MAX(v2.revision) FROM pl_posting_revisions v2 WHERE v2.identity_id=i.id)';
return $sql;
