<?php
declare(strict_types=1);
final class NextWorkProposal{
 public const PENDING='pending_approval',AUTHORIZED='authorized',SPAWNING='spawning',SPAWNED='spawned',REJECTED='rejected',FAILED='failed';
 public function __construct(public readonly string$publicId,public readonly string$status,public readonly string$publicReason,public readonly ?string$proposedTitle,public readonly string$proposedObjective,public readonly array$evidence,public readonly string$authorizationReason,public readonly bool$decisionAccounted,public readonly ?string$reservationPublicId,public readonly ?string$spawnedTaskPublicId,public readonly int$lockVersion,public readonly ?string$approvedAt=null,public readonly ?string$rejectedAt=null,public readonly ?string$spawnedAt=null,public readonly ?string$createdAt=null,public readonly ?string$updatedAt=null){}
 public static function fromRow(array$r):self{$e=json_decode((string)$r['evidence_json'],true);return new self((string)$r['public_id'],(string)$r['status'],(string)$r['public_reason'],$r['proposed_title']===null?null:(string)$r['proposed_title'],(string)$r['proposed_objective'],is_array($e)?$e:[],(string)$r['authorization_reason'],(bool)($r['decision_accounted']??false),$r['reservation_public_id']??null,$r['spawned_task_public_id']??null,(int)$r['lock_version'],$r['approved_at']??null,$r['rejected_at']??null,$r['spawned_at']??null,$r['created_at']??null,$r['updated_at']??null);}
 public function toArray():array{return['public_id'=>$this->publicId,'status'=>$this->status,'public_reason'=>$this->publicReason,'proposed_title'=>$this->proposedTitle,'proposed_objective'=>$this->proposedObjective,'evidence'=>$this->evidence,'authorization_reason'=>$this->authorizationReason,'decision_accounted'=>$this->decisionAccounted,'reservation_public_id'=>$this->reservationPublicId,'spawned_task_public_id'=>$this->spawnedTaskPublicId,'lock_version'=>$this->lockVersion,'approved_at'=>$this->approvedAt,'rejected_at'=>$this->rejectedAt,'spawned_at'=>$this->spawnedAt,'created_at'=>$this->createdAt,'updated_at'=>$this->updatedAt];}
}
interface NextWorkProposalStoreInterface{
 public function resolveScope(int$userId,int$projectId,string$sourceTaskPublicId,string$cyclePublicId):array;
 public function createOrGet(array$scope,string$publicId,string$dedupeKey,string$payloadHash,NextWorkDecision$decision,string$status,string$reason,bool$decisionAccounted=false):array;
 public function findOwned(int$userId,string$publicId,bool$lock=false):?array;
 public function listApproved(int$limit):array;
 public function transition(int$userId,string$publicId,int$lockVersion,array$from,string$to,array$fields=[]):array;
 public function reservationId(int$userId,int$projectId,string$publicId):int;
 public function taskId(int$userId,int$projectId,string$publicId):int;
}
