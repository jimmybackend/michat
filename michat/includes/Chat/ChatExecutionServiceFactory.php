<?php
declare(strict_types=1);

final class ChatExecutionServiceFactory
{
    public function __construct(private mysqli $db) {}
    public function create(): ChatExecutionService
    {
        require_once dirname(__DIR__).'/ai_agent_runtime.php';
        return new ChatExecutionService(new BedrockChatRuntime($this->db, (new ToolRegistryFactory($this->db))->create()));
    }
}
