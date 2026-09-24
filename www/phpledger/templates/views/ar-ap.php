<?php declare(strict_types=1);
$isAr=$normalKind==='invoice'; $editing=isset($_GET['new']) || isset($_GET['edit']) || isset($_GET['correct']) || ($original!==null && $document===null) || ($form['input'] && in_array($form['input']['action']??'',['save','correct'],true));
$v=$form['input']?:($document??($original?['party_id'=>$original['party_id'],'currency'=>$original['currency'],'original_document_id'=>$original['id'],'price_mode'=>$original['price_mode'],'kind'=>$creditKind]:[]));
$correct=isset($_GET['correct']) || ($v['action']??'')==='correct';
$kind=$v['kind']??$document['kind']??$normalKind;
$accountOptions=pl_starter_options(array_filter($accounts,fn($a)=>pl_ar_line_account_eligible($a,$isAr)));
$failedAction=$form['input']['action']??'';
$paymentInput=$failedAction==='settle'?$form['input']:[];
$reverseInput=$failedAction==='reverse'?$form['input']:[];
$postInput=$failedAction==='post'?$form['input']:[];
if ($editing && pl_can_write($company)) { require dirname(__DIR__).'/partials/ui/ar-document-editor.php'; return; }
if ($document && !$editing) { require dirname(__DIR__).'/partials/ui/ar-document-record.php'; return; }
require dirname(__DIR__).'/partials/ui/ar-document-list.php';
