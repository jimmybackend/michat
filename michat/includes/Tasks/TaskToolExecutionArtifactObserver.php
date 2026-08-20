<?php
declare(strict_types=1);

/** Task-specific observer that persists provenance immediately after each model tool-use. */
final class TaskToolExecutionArtifactObserver implements ToolExecutionObserverInterface
{
    public function __construct(private TaskArtifactRepository $artifacts) {}

    public function observe(array $context, ToolExecutionResult $result): void
    {
        if ($result->artifacts === []) return;
        $executionId=(int)($context['execution_id']??0);
        if($executionId<1)throw new TaskValidationException('execution_id_invalid');
        if($result->toolCallId===null||$result->toolCallId<1)throw new TaskValidationException('tool_call_id_missing');
        foreach($result->artifacts as$artifact){
            if(!is_array($artifact)||count($artifact)!==3||array_diff(array_keys($artifact),['relation','resource_type','resource_id'])!==[]
                ||!array_key_exists('relation',$artifact)||!array_key_exists('resource_type',$artifact)||!array_key_exists('resource_id',$artifact)
                ||!is_string($artifact['relation'])||!is_string($artifact['resource_type'])||!is_int($artifact['resource_id'])){
                throw new TaskValidationException('tool_artifact_invalid');
            }
            $this->artifacts->record($executionId,$result->toolCallId,$artifact['relation'],$artifact['resource_type'],$artifact['resource_id']);
        }
    }
}
