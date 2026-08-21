<?php
declare(strict_types=1);

/** Builds a safe proposal without executing or persisting the exact operation. */
final class TaskToolApprovalProposalFactory
{
    public function __construct(private TaskToolRiskPolicy $risk,private TaskToolApprovalFingerprint $fingerprints) {}

    /** @param array<string,mixed> $arguments @param array<string,mixed> $serverScope */
    public function create(string $toolKey, array $arguments, array $serverScope): TaskToolApprovalProposal
    {
        return$this->build($toolKey,$arguments,$serverScope);
    }

    /** Recreates the original proposal identity while a later Execution continues the same owned Task/Step. */
    public function recreateForContinuation(string$toolKey,array$arguments,array$currentServerScope,TaskToolApprovalIdentity$identity):TaskToolApprovalProposal
    {
        $proposalScope=$currentServerScope;$proposalScope['execution_id']=$identity->proposalExecutionId;
        return$this->build($toolKey,$arguments,$proposalScope);
    }

    private function build(string $toolKey,array $arguments,array $serverScope):TaskToolApprovalProposal
    {
        $decision=$this->risk->decide($toolKey);
        if(!$decision->requiresApproval())throw new TaskValidationException('tool_approval_not_required');
        [$summary,$target]=$this->safeDisplay($toolKey,$arguments);
        return new TaskToolApprovalProposal(
            TaskToolApprovalFingerprint::FORMAT_VERSION,$toolKey,$decision->effect,'write_requires_approval',
            $summary,$target,$this->fingerprints->create($toolKey,$decision->effect,$arguments,$serverScope)
        );
    }

    /** @param array<string,mixed> $arguments @return array{string,?string} */
    private function safeDisplay(string $toolKey,array $arguments):array
    {
        if($toolKey==='code_edit'){
            $action=strtolower(trim(is_string($arguments['action']??null)?$arguments['action']:'write'));
            $summaries=['write'=>'Modificar archivo','delete'=>'Eliminar archivo','read'=>'Leer archivo'];
            if(!isset($summaries[$action]))throw new TaskValidationException('tool_approval_display_invalid');
            return[$summaries[$action],$this->safeFilename($arguments['target_filename']??null)];
        }
        if($toolKey==='str_replace')return['Reemplazar contenido en archivo',null];
        throw new TaskValidationException('tool_approval_display_unsupported');
    }

    private function safeFilename($value):string
    {
        if(!is_string($value))throw new TaskValidationException('tool_approval_target_invalid');
        $name=trim(str_replace('\\','/',$value));
        if($name===''||str_contains($name,"\0")||basename($name)!==$name||!preg_match('/^[A-Za-z0-9_.-]+$/D',$name))throw new TaskValidationException('tool_approval_target_invalid');
        return$name;
    }
}
