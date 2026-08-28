<?php
declare(strict_types=1);

/** Canonical per-user defaults for a newly provisioned MiChat account. */
final class InitialUserProfile
{
    /** @var array<string,int> */
    private const FEATURES = [
        'prompt_compiler'=>0,
        'memory_router'=>1,
        'procedural_memory_read'=>1,
        'project_memory_read'=>1,
        'session_memory_read'=>1,
        'question_memory_read'=>1,
        'project_rag'=>1,
        'attachment_rag'=>1,
        'context_ranking'=>1,
        'memory_backfill'=>1,
        'project_tools'=>1,
        'memory_writer'=>1,
        'task_orchestrator'=>1,
        'task_auto_execute'=>0,
        'task_async_execute'=>1,
        'task_planner'=>1,
    ];

    public function __construct(private mysqli $db) {}

    public function apply(int $userId): void
    {
        if($userId<1)throw new InvalidArgumentException('user_id_invalid');
        $this->insertPreferences($userId);
        $this->insertFeatures($userId);
        // GLOBAL UserAIAgentConfigs are inherited dynamically. No per-user copies are seeded.
    }

    /** @return array<string,int> */
    public static function features(): array { return self::FEATURES; }

    private function insertPreferences(int $userId): void
    {
        $stmt=$this->db->prepare(
            "INSERT INTO UserPreferences
             (user_id_,model_id,seed,compile_temperature,compile_max_tokens,response_max_tokens,compile_top_p,question_memory_enabled,question_memory_scope,question_memory_max_candidates,question_memory_window_lines,theme_mode)
             VALUES (?,'amazon.nova-micro-v1:0',42,0.00,200,300,0.100,1,'session',20,5,'theme-light')
             ON DUPLICATE KEY UPDATE user_id_=VALUES(user_id_)"
        );
        if(!$stmt)throw new RuntimeException('initial_profile_preferences_unavailable');
        $stmt->bind_param('i',$userId);
        if(!$stmt->execute())throw new RuntimeException('initial_profile_preferences_failed');
        $stmt->close();
    }

    private function insertFeatures(int $userId): void
    {
        $stmt=$this->db->prepare("INSERT INTO UserPipelineFeatures(user_id_,feature_key,is_enabled,config_json) VALUES(?,?,?,NULL) ON DUPLICATE KEY UPDATE user_id_=VALUES(user_id_)");
        if(!$stmt)throw new RuntimeException('initial_profile_features_unavailable');
        foreach(self::FEATURES as$key=>$enabled){
            $stmt->bind_param('isi',$userId,$key,$enabled);
            if(!$stmt->execute()){ $stmt->close(); throw new RuntimeException('initial_profile_features_failed'); }
        }
        $stmt->close();
    }
}
