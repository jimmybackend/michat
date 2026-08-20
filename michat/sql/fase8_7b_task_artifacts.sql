-- Fase 8.7B: procedencia mínima de recursos usados o producidos por una ejecución.
-- tool_call_id_ es una referencia histórica débil: ToolCalls se elimina en cascada
-- con Projects/ChatSessions y una FK rompería el borrado o perdería procedencia.
CREATE TABLE IF NOT EXISTS `TaskArtifacts` (
  `id_` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `execution_id_` bigint UNSIGNED NOT NULL,
  `tool_call_id_` bigint UNSIGNED DEFAULT NULL,
  `tool_call_identity` bigint UNSIGNED GENERATED ALWAYS AS (COALESCE(`tool_call_id_`,0)) STORED,
  `relation` enum('read','used','created','modified','generated') NOT NULL,
  `resource_type` enum('project_source','source_chunk','file_version','file_s3') NOT NULL,
  `resource_id` bigint UNSIGNED NOT NULL,
  `created_at` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id_`),
  UNIQUE KEY `uq_task_artifacts_identity` (`execution_id_`,`tool_call_identity`,`relation`,`resource_type`,`resource_id`),
  KEY `idx_task_artifacts_execution` (`execution_id_`,`id_`),
  KEY `idx_task_artifacts_tool_call` (`tool_call_id_`,`id_`),
  KEY `idx_task_artifacts_resource` (`resource_type`,`resource_id`,`id_`),
  KEY `idx_task_artifacts_relation` (`relation`,`id_`),
  CONSTRAINT `fk_task_artifacts_execution` FOREIGN KEY (`execution_id_`) REFERENCES `TaskExecutions` (`id_`) ON DELETE CASCADE,
  CONSTRAINT `chk_task_artifacts_resource_id` CHECK (`resource_id` > 0),
  CONSTRAINT `chk_task_artifacts_tool_call_id` CHECK (`tool_call_id_` IS NULL OR `tool_call_id_` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
