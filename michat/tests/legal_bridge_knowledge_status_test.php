<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/LegalBridge/LegalBridgeKnowledgeStatus.php';
function checkLegalStatus(bool $ok,string $name): void { if(!$ok){fwrite(STDERR,"FAIL $name\n");exit(1);} echo "PASS $name\n"; }
$base=['state'=>'processing','coverage'=>'text_extracted','chunks_total'=>4,'chunks_done'=>4,'chunks_with_embedding'=>0,'job'=>['status'=>'running','phase'=>'embeddings']];
checkLegalStatus(LegalBridgeKnowledgeStatus::map($base,'model-v1','model-v1')==='indexing','indexing while embeddings remain');
$ready=$base;$ready['state']='ready';$ready['chunks_with_embedding']=4;$ready['job']=['status'=>'completed','phase'=>'embeddings'];
checkLegalStatus(LegalBridgeKnowledgeStatus::map($ready,'model-v1','model-v1')==='ready','ready only with completed job and full embeddings');
checkLegalStatus(LegalBridgeKnowledgeStatus::map($ready,'model-v2','model-v1')!=='ready','stale model is never ready');
$partial=$ready;$partial['state']='partial';$partial['coverage']='partial';
checkLegalStatus(LegalBridgeKnowledgeStatus::map($partial,'model-v1','model-v1')==='partial','partial extraction never ready');
$failed=$base;$failed['job']=['status'=>'failed','phase'=>'extract'];
checkLegalStatus(LegalBridgeKnowledgeStatus::map($failed,'model-v1','model-v1')==='error','persisted job failure is error');
$extract=$base;$extract['chunks_total']=0;$extract['chunks_done']=0;$extract['job']=['status'=>'running','phase'=>'extract'];
checkLegalStatus(LegalBridgeKnowledgeStatus::map($extract,'model-v1','model-v1')==='extracting','extracting phase is reported');
$pending=$base;$pending['job']=['status'=>'pending','phase'=>''];$pending['chunks_total']=0;$pending['chunks_done']=0;
checkLegalStatus(LegalBridgeKnowledgeStatus::map($pending,'','')==='pending','non-indexed source is pending');
$blocked=$base;$blocked['job']=['status'=>'blocked','phase'=>'embeddings'];
checkLegalStatus(LegalBridgeKnowledgeStatus::map($blocked,'','model-v1')==='partial','blocked embeddings preserve partial coverage');
