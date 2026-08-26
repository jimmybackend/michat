-- PRE-RUNNER MYSQL COMPATIBILITY CORRECTION:
-- MySQL 8.0 does not support conditional ADD COLUMN syntax. Keep this historical
-- migration re-runnable without changing the resulting column definition.
SET @michat_has_decision_accounted := (
 SELECT COUNT(*)
 FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME = 'NextWorkProposals'
   AND COLUMN_NAME = 'decision_accounted'
);
SET @michat_add_decision_accounted := IF(
 @michat_has_decision_accounted = 0,
 'ALTER TABLE `NextWorkProposals` ADD COLUMN `decision_accounted` tinyint(1) NOT NULL DEFAULT 0 AFTER `authorization_reason`',
 'SELECT 1'
);
PREPARE michat_fase11d_stmt FROM @michat_add_decision_accounted;
EXECUTE michat_fase11d_stmt;
DEALLOCATE PREPARE michat_fase11d_stmt;

-- Fase 11D: asociación explícita root/cycle y oportunidades post-terminal reclamables.
CREATE TABLE IF NOT EXISTS `ProjectAutonomyCycleTasks` (
 `id_` bigint UNSIGNED NOT NULL AUTO_INCREMENT, `cycle_id_` bigint UNSIGNED NOT NULL, `user_id_` int NOT NULL, `project_id_` int NOT NULL, `task_id_` bigint UNSIGNED NOT NULL, `depth` int UNSIGNED NOT NULL DEFAULT 0, `created_at` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY (`id_`), UNIQUE KEY `uq_autonomy_cycle_task` (`cycle_id_`,`task_id_`), UNIQUE KEY `uq_autonomy_task_cycle` (`task_id_`), KEY `idx_autonomy_cycle_task_owner` (`user_id_`,`project_id_`),
 CONSTRAINT `fk_autonomy_cycle_task_cycle` FOREIGN KEY (`cycle_id_`) REFERENCES `ProjectAutonomyCycles` (`id_`) ON DELETE CASCADE, CONSTRAINT `fk_autonomy_cycle_task_user` FOREIGN KEY (`user_id_`) REFERENCES `Users` (`id`) ON DELETE RESTRICT, CONSTRAINT `fk_autonomy_cycle_task_project` FOREIGN KEY (`project_id_`) REFERENCES `Projects` (`id_`) ON DELETE CASCADE, CONSTRAINT `fk_autonomy_cycle_task_task` FOREIGN KEY (`task_id_`) REFERENCES `Tasks` (`id_`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `PostTaskContinuations` (
 `id_` bigint UNSIGNED NOT NULL AUTO_INCREMENT, `public_id` char(36) NOT NULL, `user_id_` int NOT NULL, `project_id_` int NOT NULL, `source_task_id_` bigint UNSIGNED NOT NULL, `autonomy_cycle_id_` bigint UNSIGNED NOT NULL, `proposal_id_` bigint UNSIGNED DEFAULT NULL, `spawned_task_id_` bigint UNSIGNED DEFAULT NULL,
 `status` enum('pending','processing','completed','waiting_user','waiting_approval','failed') NOT NULL DEFAULT 'pending', `terminal_status` enum('completed','failed','cancelled') NOT NULL, `depth` int UNSIGNED NOT NULL, `decision_type` enum('stop','ask_user','propose_task') DEFAULT NULL, `decision_json` json DEFAULT NULL, `usage_json` json DEFAULT NULL, `reason_code` varchar(80) DEFAULT NULL, `public_reason` varchar(800) DEFAULT NULL, `question` varchar(800) DEFAULT NULL,
 `attempt_count` tinyint UNSIGNED NOT NULL DEFAULT 0, `next_attempt_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), `worker_id` varchar(128) DEFAULT NULL, `lease_token` char(36) DEFAULT NULL, `lease_expires_at` datetime(6) DEFAULT NULL, `started_at` datetime(6) DEFAULT NULL, `finished_at` datetime(6) DEFAULT NULL, `created_at` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), `updated_at` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
 PRIMARY KEY (`id_`), UNIQUE KEY `uq_post_task_continuation_public` (`public_id`), UNIQUE KEY `uq_post_task_continuation_logical` (`autonomy_cycle_id_`,`source_task_id_`), KEY `idx_post_task_continuation_claim` (`status`,`next_attempt_at`,`lease_expires_at`), KEY `idx_post_task_continuation_owner` (`user_id_`,`project_id_`,`status`),
 CONSTRAINT `fk_post_task_continuation_user` FOREIGN KEY (`user_id_`) REFERENCES `Users` (`id`) ON DELETE RESTRICT, CONSTRAINT `fk_post_task_continuation_project` FOREIGN KEY (`project_id_`) REFERENCES `Projects` (`id_`) ON DELETE CASCADE, CONSTRAINT `fk_post_task_continuation_source` FOREIGN KEY (`source_task_id_`) REFERENCES `Tasks` (`id_`) ON DELETE CASCADE, CONSTRAINT `fk_post_task_continuation_cycle` FOREIGN KEY (`autonomy_cycle_id_`) REFERENCES `ProjectAutonomyCycles` (`id_`) ON DELETE CASCADE, CONSTRAINT `fk_post_task_continuation_proposal` FOREIGN KEY (`proposal_id_`) REFERENCES `NextWorkProposals` (`id_`) ON DELETE SET NULL, CONSTRAINT `fk_post_task_continuation_spawned` FOREIGN KEY (`spawned_task_id_`) REFERENCES `Tasks` (`id_`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
