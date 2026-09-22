<?php declare(strict_types=1);
$employeeOptions=[''=>'Select employee'];foreach($employees as $employee){$employeeOptions[(string)$employee['id']]='#'.$employee['id'].' '.$employee['full_name'];}
?>
<h1 class="page-title"><?= pl_e(pl_t('Employee links')) ?></h1>
<p><?= pl_e(pl_t('Link existing records explicitly. Names and dates are never inferred. Posted documents keep their recorded names. An ordinary trade link never marks somebody as related or combines balances.')) ?></p>
<?php if ($form['message'] ?? ''): ?><p role="alert"><?= pl_e($form['message']) ?></p><?php endif; ?>
<?php foreach (['sales_staff'=>$staff,'driver'=>array_filter($warehouses,static fn(array $w):bool=>$w['kind']==='mobile')] as $kind=>$rows): ?>
<h2><?= pl_e($kind==='sales_staff'?pl_t('Sales assignments'):pl_t('Driver assignments')) ?></h2>
<?php foreach($rows as $row): $linked=$row[$kind==='sales_staff'?'employee_id':'driver_employee_id']; ?>
<div class="rounded-panel border border-border p-4 my-3"><p><?= pl_e($row['code'].' - '.($kind==='sales_staff'?$row['name']:$row['driver_name'])) ?> - <?= pl_e($linked===null?pl_t('Unmapped: choose an employee'):('#'.$linked)) ?></p>
<?php if($manage): ?><form method="post" class="grid md:grid-cols-3 gap-3"><?php pl_starter_form($company,$kind,['record_id'=>$row['id'],'revision'=>$row['revision']]);pl_starter_select(pl_t('Employee'),'employee_id',$employeeOptions,$linked??'');pl_starter_field(pl_t('Reason'),'reason','');?><button class="btn btn-secondary"><?= pl_e(pl_t('Save assignment')) ?></button></form><?php endif; ?></div>
<?php endforeach; endforeach; ?>
<?php if($manage): ?><h2><?= pl_e(pl_t('New sales assignment')) ?></h2><form method="post" class="grid md:grid-cols-4 gap-3"><?php pl_starter_form($company,'new_sales');pl_starter_field(pl_t('Code'),'code','');pl_starter_select(pl_t('Employee'),'employee_id',$employeeOptions,'');pl_starter_field(pl_t('Reason'),'reason','');?><button class="btn btn-primary"><?= pl_e(pl_t('Add assignment')) ?></button></form><?php endif; ?>
<h2><?= pl_e(pl_t('Ordinary trade association')) ?></h2>
<?php $partyOptions=[''=>'No trade association'];foreach($parties as $party){$partyOptions[(string)$party['id']]='#'.$party['id'].' '.$party['legal_name'];}foreach($employees as $employee): ?>
<p><?= pl_e('#'.$employee['id'].' '.$employee['full_name']) ?></p>
<?php if($manage): ?><form method="post" class="grid md:grid-cols-3 gap-3"><?php pl_starter_form($company,'trade',['employee_id'=>$employee['id'],'revision'=>$employee['revision']]);pl_starter_select(pl_t('Trade party'),'party_id',$partyOptions,$employee['trade_party_id']??'');pl_starter_field(pl_t('Reason'),'reason','');?><button class="btn btn-secondary"><?= pl_e(pl_t('Save trade link')) ?></button></form><?php endif; endforeach; ?>
<h2><?= pl_e(pl_t('Identity comparisons for review')) ?></h2><p><?= pl_e(pl_t('Only collected names, identifiers and addresses are compared. Bank matching is unavailable because employee bank details are not collected. Matches need human review and never create links or related-party markers.')) ?></p>
<?php foreach($candidates as $candidate): ?><p><?= pl_e($candidate['employee_name'].' / '.$candidate['party_name'].' - '.implode(', ',$candidate['matched_on'])) ?> - <?= pl_e($candidate['linked']?pl_t('Linked'):pl_t('Review required')) ?></p><?php endforeach; ?>
