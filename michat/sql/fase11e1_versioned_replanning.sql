-- Fase 11E.1: remaining-plan versionado. No borra Steps ni crea Tasks.
ALTER TABLE `Tasks`
  ADD COLUMN `mode` enum('supervised','automatic') NOT NULL DEFAULT 'supervised' AFTER `origin_type`;

ALTER TABLE `TaskReplanRequests`
  ADD COLUMN `revision_id_` bigint UNSIGNED DEFAULT NULL AFTER `source_step_id_`,
  ADD COLUMN `reservation_id_` bigint UNSIGNED DEFAULT NULL AFTER `revision_id_`,
  ADD COLUMN `next_attempt_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) AFTER `attempt_count`,
  ADD COLUMN `failure_reason` varchar(80) DEFAULT NULL AFTER `next_attempt_at`,
  ADD COLUMN `approved_at` datetime(6) DEFAULT NULL AFTER `lock_version`,
  ADD COLUMN `applied_at` datetime(6) DEFAULT NULL AFTER `approved_at`,
  ADD KEY `idx_task_replan_claim` (`status`,`next_attempt_at`,`lease_expires_at`),
  ADD KEY `idx_task_replan_revision` (`revision_id_`),
  ADD KEY `idx_task_replan_reservation` (`reservation_id_`),
  ADD CONSTRAINT `fk_task_replan_reservation` FOREIGN KEY (`reservation_id_`) REFERENCES `ProjectAutonomyReservations` (`id_`) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS `TaskPlanRevisions` (
 `id_` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
 `public_id` char(36) NOT NULL,
 `user_id_` int NOT NULL,
 `project_id_` int NOT NULL,
 `task_id_` bigint UNSIGNED NOT NULL,
 `replan_request_id_` bigint UNSIGNED DEFAULT NULL,
 `revision_number` int UNSIGNED NOT NULL,
 `source_revision` int UNSIGNED NOT NULL DEFAULT 0,
 `status` enum('historical','proposed','pending_approval','approved','applied','rejected','failed') NOT NULL,
 `proposed_plan_json` json NOT NULL,
 `planner_model` varchar(255) DEFAULT NULL,
 `usage_json` json DEFAULT NULL,
 `public_reason` varchar(500) NOT NULL,
 `lock_version` int UNSIGNED NOT NULL DEFAULT 0,
 `approved_at` datetime(6) DEFAULT NULL,
 `rejected_at` datetime(6) DEFAULT NULL,
 `applied_at` datetime(6) DEFAULT NULL,
 `created_at` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 `updated_at` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
 PRIMARY KEY (`id_`),
 UNIQUE KEY `uq_task_plan_revision_public` (`public_id`),
 UNIQUE KEY `uq_task_plan_revision_number` (`task_id_`,`revision_number`),
 UNIQUE KEY `uq_task_plan_revision_request` (`replan_request_id_`),
 KEY `idx_task_plan_revision_owner` (`user_id_`,`project_id_`,`task_id_`),
 CONSTRAINT `fk_task_plan_revision_user` FOREIGN KEY (`user_id_`) REFERENCES `Users` (`id`) ON DELETE RESTRICT,
 CONSTRAINT `fk_task_plan_revision_project` FOREIGN KEY (`project_id_`) REFERENCES `Projects` (`id_`) ON DELETE CASCADE,
 CONSTRAINT `fk_task_plan_revision_task` FOREIGN KEY (`task_id_`) REFERENCES `Tasks` (`id_`) ON DELETE CASCADE,
 CONSTRAINT `fk_task_plan_revision_request` FOREIGN KEY (`replan_request_id_`) REFERENCES `TaskReplanRequests` (`id_`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE `TaskReplanRequests`
  ADD CONSTRAINT `fk_task_replan_revision` FOREIGN KEY (`revision_id_`) REFERENCES `TaskPlanRevisions` (`id_`) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS `TaskPlanRevisionSteps` (
 `revision_id_` bigint UNSIGNED NOT NULL,
 `step_id_` bigint UNSIGNED NOT NULL,
 `logical_key` varchar(80) NOT NULL,
 `position_in_revision` smallint UNSIGNED NOT NULL,
 `created_at` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY (`revision_id_`,`step_id_`),
 UNIQUE KEY `uq_task_plan_revision_position` (`revision_id_`,`position_in_revision`),
 UNIQUE KEY `uq_task_plan_revision_logical` (`revision_id_`,`logical_key`),
 KEY `idx_task_plan_revision_step` (`step_id_`),
 CONSTRAINT `fk_task_plan_revision_steps_revision` FOREIGN KEY (`revision_id_`) REFERENCES `TaskPlanRevisions` (`id_`) ON DELETE CASCADE,
 CONSTRAINT `fk_task_plan_revision_steps_step` FOREIGN KEY (`step_id_`) REFERENCES `TaskSteps` (`id_`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
