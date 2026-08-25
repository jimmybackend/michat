<?php
declare(strict_types=1);

final class ChatExecutionServiceFactory
{
    public function __construct(private mysqli $db,private ?ToolExecutionObserverInterface $toolObserver=null,private ?ToolRegistry $tools=null,private ?ToolExecutionGateInterface $toolGate=null,private ?TaskCancellationGuard $cancellations=null) {}
    public function create(): ChatExecutionService
    {
        require_once dirname(__DIR__).'/ai_agent_runtime.php';
        require_once dirname(__DIR__).'/MemoryContextRouter.php';
        require_once dirname(__DIR__).'/Memory/bootstrap.php';
        require_once dirname(__DIR__).'/Pipeline/PipelineFeatureFlags.php';
        require_once dirname(__DIR__).'/MemoryWrite/bootstrap.php';
        $bedrock=Config::getBedrockRuntime(['http'=>['connect_timeout'=>20,'timeout'=>240]]);
        $contexts=new ChatContextPreparationService($this->db,new MemoryContextRouter(),new ContextBuilder($this->db,$bedrock));
        $responses=new ChatResponsePersistenceService($this->db,new TaskRepository($this->db));
        $memory=new ChatMemoryFinalizationService($this->db,$bedrock);
        $tokens=new ChatTokenUsageService($this->db);
        $activity=new ChatActivityTelemetryService($this->db);
        $cancellations=$this->cancellations??new TaskCancellationGuard($this->db);
        $tools=$this->tools??(new ToolRegistryFactory($this->db,$cancellations))->create();
        return new ChatExecutionService(new BedrockChatRuntime($this->db,$tools,$cancellations,$this->toolObserver,$this->toolGate,null,$bedrock),$contexts,$responses,$memory,$tokens,$activity);
    }
}
