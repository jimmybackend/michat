<?php
declare(strict_types=1);

final class ChatExecutionServiceFactory
{
    public function __construct(private mysqli $db) {}
    public function create(): ChatExecutionService
    {
        require_once dirname(__DIR__).'/ai_agent_runtime.php';
        require_once dirname(__DIR__).'/MemoryContextRouter.php';
        require_once dirname(__DIR__).'/Memory/bootstrap.php';
        require_once dirname(__DIR__).'/Pipeline/PipelineFeatureFlags.php';
        $bedrock=Config::getBedrockRuntime(['http'=>['connect_timeout'=>20,'timeout'=>240]]);
        $contexts=new ChatContextPreparationService($this->db,new MemoryContextRouter(),new ContextBuilder($this->db,$bedrock));
        $responses=new ChatResponsePersistenceService($this->db,new TaskRepository($this->db));
        return new ChatExecutionService(new BedrockChatRuntime($this->db,(new ToolRegistryFactory($this->db))->create()),$contexts,$responses);
    }
}
