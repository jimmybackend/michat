<?php
declare(strict_types=1);

/** Internal, server-owned link to the Execution that originated a proposal. */
final class TaskToolApprovalIdentity
{
    public function __construct(public readonly int $proposalExecutionId)
    {
        if($proposalExecutionId<1)throw new TaskValidationException('tool_approval_identity_context_invalid');
    }

    /** @return array{proposal_execution_id:int} */
    public function toArray():array{return['proposal_execution_id'=>$this->proposalExecutionId];}
    public static function fromArray(array$data):self{if(array_keys($data)!==['proposal_execution_id']||!is_int($data['proposal_execution_id']))throw new TaskValidationException('tool_approval_identity_context_invalid');return new self($data['proposal_execution_id']);}
}
