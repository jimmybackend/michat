-- Fase 11F.2B: respuesta humana durable para continuations ask_user.
ALTER TABLE `PostTaskContinuations`
  ADD COLUMN `answer` varchar(2000) DEFAULT NULL AFTER `question`,
  ADD COLUMN `answered_at` datetime(6) DEFAULT NULL AFTER `answer`,
  ADD COLUMN `answered_by_user_id_` int DEFAULT NULL AFTER `answered_at`,
  ADD KEY `idx_post_task_continuation_answered_by` (`answered_by_user_id_`),
  ADD CONSTRAINT `fk_post_task_continuation_answered_by` FOREIGN KEY (`answered_by_user_id_`) REFERENCES `Users` (`id`) ON DELETE RESTRICT;
