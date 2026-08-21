<?php
declare(strict_types=1);

interface TaskToolRiskPolicyInterface{public function decide(string$toolKey):TaskToolRiskDecision;}
interface TaskToolApprovalStateReaderInterface{public function read(int$executionId):TaskToolApprovalState;}
interface TaskToolApprovalProposalFactoryInterface{public function create(string$toolKey,array$arguments,array$serverScope):TaskToolApprovalProposal;}
interface TaskToolApprovalPauseInterface{public function pause(int$executionId,array$arguments,TaskToolApprovalProposal$proposal):array;}
interface TaskToolApprovalConsumptionInterface{public function consume(int$executionId,string$toolKey,array$arguments):array;}
