<?php
declare(strict_types=1);

/** Task-public-id command boundary for the Phase 11F Task Center controls. */
final class TaskCenterAutonomyControlService
{
    public function __construct(private mysqli $db,private TaskRepository $tasks,private AutonomyPolicyService $policies,private AutonomyBudgetService $budgets,private PostTaskContinuationService $continuations,private NextWorkProposalService $proposals,private TaskReplanService $replans){}

    public function updatePolicy(int $userId,array $input):array
    {
        [$task,$project]=$this->scope($userId,$input);$lock=$this->lock($input);$mode=(string)($input['mode']??'');$limits=$this->limits($input['limits']??null);$this->assertNotBelowUsage($userId,$project,$limits);
        return ['policy'=>$this->policies->update($userId,$project,$lock,$mode,$limits)->toArray()];
    }

    public function transition(int $userId,array $input,string $action):array
    {
        [, $project]=$this->scope($userId,$input);$lock=$this->lock($input);
        $policy=match($action){'autonomy_pause'=>$this->policies->pause($userId,$project,$lock),'autonomy_resume'=>$this->policies->resume($userId,$project,$lock),'autonomy_stop'=>$this->policies->stop($userId,$project,$lock,(string)($input['reason']??'')),default=>throw new TaskValidationException('autonomy_action_invalid')};
        return ['policy'=>$policy->toArray()];
    }

    public function startCycle(int $userId,array $input):array
    {
        [, $project]=$this->scope($userId,$input);return ['cycle'=>$this->budgets->startCycle($userId,$project)];
    }

    public function enrollRoot(int $userId,array $input):array
    {
        [$task,$project]=$this->scope($userId,$input);$cycle=$this->publicId($input['cycle_public_id']??'');return ['enrollment'=>$this->continuations->enrollRoot($userId,$project,(string)$task['public_id'],$cycle)];
    }


    public function answerContinuation(int $userId,array $input):array
    { [$task,$project]=$this->scope($userId,$input);$public=$this->publicId($input['continuation_public_id']??'');return ['continuation'=>$this->continuations->answer($userId,$project,(string)$task['public_id'],$public,(string)($input['answer']??''))]; }
    public function decideProposal(int $userId,array $input,bool $approve):array
    { [, $project]=$this->scope($userId,$input);$public=$this->publicId($input['proposal_public_id']??'');$lock=$this->lock($input);$proposal=$approve?$this->proposals->approve($userId,$project,$public,$lock,true):$this->proposals->reject($userId,$project,$public,$lock);return ['proposal'=>$proposal->toArray()]; }
    public function decideReplan(int $userId,array $input,bool $approve):array
    { $task=$this->scope($userId,$input)[0];$public=$this->publicId($input['replan_public_id']??'');$this->assertReplanTask($userId,(int)$task['id_'],$public);$lock=$this->lock($input);if(!$approve)return ['replan'=>$this->replans->rejectReplan($userId,$public,$lock)];$revision=$this->replans->approveReplan($userId,$public,$lock);return ['replan'=>['public_id'=>$public,'status'=>'approved','revision_public_id'=>$revision->publicId,'revision_number'=>$revision->revisionNumber,'lock_version'=>$revision->lockVersion]]; }

    private function scope(int $userId,array $input):array
    {
        $public=$this->publicId($input['task_public_id']??'');$task=$this->tasks->findOwnedByPublicId($public,$userId);if(!$task||$task['project_id_']===null)throw new TaskNotFoundException('not_found');return[$task,(int)$task['project_id_']];
    }
    private function lock(array $input):int{$value=$input['lock_version']??null;if(!is_int($value)||$value<0)throw new TaskValidationException('lock_version_invalid');return$value;}
    private function publicId(mixed $value):string{$id=trim((string)$value);if(!TaskPublicId::isValid($id))throw new TaskValidationException('public_id_invalid');return$id;}
    private function limits(mixed $raw):array
    {
        if(!is_array($raw))throw new TaskValidationException('autonomy_limits_invalid');$out=[];foreach($raw as$key=>$value){if(!array_key_exists((string)$key,AutonomyPolicy::DEFAULTS)||!is_int($value)||$value<1||$value>AutonomyPolicy::CEILINGS[(string)$key])throw new TaskValidationException('autonomy_limit_invalid');$out[(string)$key]=$value;}if(count($out)!==count(AutonomyPolicy::DEFAULTS))throw new TaskValidationException('autonomy_limits_incomplete');return$out;
    }
    private function assertReplanTask(int $userId,int $taskId,string $public):void{$s=$this->db->prepare('SELECT r.public_id FROM TaskReplanRequests r JOIN Tasks t ON t.id_=r.task_id_ AND t.user_id_=r.user_id_ WHERE r.user_id_=? AND r.task_id_=? AND r.public_id=? LIMIT 1');if(!$s)throw new RuntimeException('database_error');$s->bind_param('iis',$userId,$taskId,$public);if(!$s->execute()){$s->close();throw new RuntimeException('database_error');}$row=$s->get_result()->fetch_assoc();$s->close();if(!$row)throw new TaskNotFoundException('not_found');}
    private function assertNotBelowUsage(int $userId,int $projectId,array $limits):void
    {
        $s=$this->db->prepare("SELECT tasks_consumed,decisions_consumed,replans_consumed,runtime_seconds_consumed,input_tokens_consumed,output_tokens_consumed,tool_calls_consumed,write_tool_calls_consumed,(SELECT COALESCE(MAX(ct.depth),0) FROM ProjectAutonomyCycleTasks ct WHERE ct.cycle_id_=ProjectAutonomyCycles.id_) max_depth_consumed FROM ProjectAutonomyCycles WHERE user_id_=? AND project_id_=? AND status='active' LIMIT 1");if(!$s)throw new RuntimeException('database_error');$s->bind_param('ii',$userId,$projectId);if(!$s->execute()){ $s->close();throw new RuntimeException('database_error');}$usage=$s->get_result()->fetch_assoc();$s->close();if(!$usage)return;$map=['max_tasks_per_cycle'=>'tasks_consumed','max_decisions_per_cycle'=>'decisions_consumed','max_replans_per_cycle'=>'replans_consumed','max_runtime_seconds'=>'runtime_seconds_consumed','max_input_tokens'=>'input_tokens_consumed','max_output_tokens'=>'output_tokens_consumed','max_tool_calls'=>'tool_calls_consumed','max_write_tool_calls'=>'write_tool_calls_consumed','max_descendant_depth'=>'max_depth_consumed'];foreach($map as$limit=>$used)if($limits[$limit]<(int)$usage[$used])throw new TaskValidationException('autonomy_limit_below_usage');
    }
}
