<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__.'/../includes/Tasks/TaskPublicApprovalPresenter.php';

$fingerprint=str_repeat('a',64);
$row=['checkpoint_json'=>json_encode(['tool_approval'=>[
    'identity'=>['proposal_execution_id'=>91,'arguments_hash'=>'secret'],
    'proposal'=>['fingerprint'=>$fingerprint,'safe_summary'=>'Update file','safe_target'=>'README.md','effect'=>'write','arguments'=>['content'=>'secret']],
    'decision'=>['status'=>'approved','consumer_execution_id'=>92,'consumed'=>false],
]])];
$dto=(new TaskPublicApprovalPresenter())->present($row);
$encoded=json_encode($dto);
$checks=[
    $dto['type']==='tool'&&$dto['status']==='approved'&&$dto['can_resume']===false,
    $dto['fingerprint']===$fingerprint&&$dto['safe_target']==='README.md',
    !str_contains($encoded,'execution_id')&&!str_contains($encoded,'arguments')&&!str_contains($encoded,'secret'),
    (new TaskPublicApprovalPresenter())->present(['checkpoint_json'=>'{}'])===null,
];
$resumable=$row;$resumable['status']='ready';$resumable['input_json']=json_encode(['execution_mode'=>'sync']);$resumeDto=(new TaskPublicApprovalPresenter())->present($resumable);$checks[]=$resumeDto['can_resume']===true;
foreach($checks as$i=>$ok)echo($ok?'PASS ':'FAIL ').'public approval DTO '.($i+1)."\n";
exit(in_array(false,$checks,true)?1:0);
