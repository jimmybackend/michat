-- APLICADA EN PRODUCCIÓN: 2026-08-06
--
-- 001_indices_enums_toolcalls_filevers.sql
--
-- Amplía ToolCalls y FileVersions para que la edición quirúrgica sea
-- auditable, extiende los ENUM de fase a las 11 fases reales del pipeline,
-- crea PhaseCache y añade los topes de gasto por proyecto.
--
-- Archivo histórico: NO se ejecuta desde PHP y NO hace falta re-aplicarlo.
-- El estado resultante vive en /schema.sql.

-- ---------------------------------------------------------------------
-- ToolCalls: proyecto, ruta destino y las 15 herramientas reales
-- ---------------------------------------------------------------------
ALTER TABLE `ToolCalls`
  ADD COLUMN `project_id_` int DEFAULT NULL AFTER `session_id_`,
  ADD COLUMN `target_path` varchar(1024) DEFAULT NULL
      COMMENT 'Archivo o prefijo sobre el que operó la herramienta' AFTER `params`,
  MODIFY COLUMN `tool` enum(
      'grep','view','search','str_replace','list_dir','read_chunk','run_shell',
      'create_file','write_file','delete_file','move_file','lint','run_tests',
      'preview_diff','restore_version'
  ) NOT NULL;

ALTER TABLE `ToolCalls`
  ADD KEY `idx_tc_project` (`project_id_`),
  ADD CONSTRAINT `fk_tc_project` FOREIGN KEY (`project_id_`)
      REFERENCES `Projects` (`id_`) ON DELETE CASCADE;

-- ---------------------------------------------------------------------
-- FileVersions: ciclo de vida de la escritura + trazabilidad del cambio
--
-- `status` es del sistema (draft -> committed | failed | rolled_back).
-- `is_stable` sigue siendo del humano. Son conceptos distintos.
-- ---------------------------------------------------------------------
ALTER TABLE `FileVersions`
  ADD COLUMN `status` enum('draft','committed','failed','rolled_back')
      NOT NULL DEFAULT 'draft' AFTER `is_stable`,
  ADD COLUMN `sha256_before` char(64) DEFAULT NULL AFTER `status`,
  ADD COLUMN `sha256_after`  char(64) DEFAULT NULL AFTER `sha256_before`,
  ADD COLUMN `bytes_before`  bigint   DEFAULT NULL AFTER `sha256_after`,
  ADD COLUMN `bytes_after`   bigint   DEFAULT NULL AFTER `bytes_before`,
  ADD COLUMN `model_used`    varchar(120) DEFAULT NULL AFTER `bytes_after`,
  ADD COLUMN `error_message` text     DEFAULT NULL AFTER `model_used`;

ALTER TABLE `FileVersions`
  ADD KEY `idx_fv_status` (`status`),
  ADD KEY `idx_fv_project_file_id` (`project_id_`,`original_filename`,`id_`);

-- ---------------------------------------------------------------------
-- Fases del pipeline: 11 valores, iguales en las dos tablas
-- ---------------------------------------------------------------------
ALTER TABLE `TokenUsage`
  MODIFY COLUMN `phase` enum(
      'compile','respond','lint_fix','embedding','classify','scout',
      'plan','rag','edit','summarize','review'
  ) NOT NULL;

ALTER TABLE `ChatMessages`
  MODIFY COLUMN `phase` enum(
      'compile','respond','lint_fix','embedding','classify','scout',
      'plan','rag','edit','summarize','review'
  ) NOT NULL DEFAULT 'respond'
  COMMENT 'Fase del pipeline en la que se generó este mensaje';

ALTER TABLE `TokenUsage`
  ADD KEY `idx_tu_phase` (`phase`);

-- ---------------------------------------------------------------------
-- PhaseCache: resultados cacheados por fase (ver includes/PhaseCache.php)
-- ---------------------------------------------------------------------
CREATE TABLE `PhaseCache` (
  `id_` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `cache_key` char(64) NOT NULL,
  `project_id_` int NOT NULL,
  `phase` varchar(32) NOT NULL,
  `payload` json NOT NULL COMMENT 'Resultado cacheado de la fase',
  `hit_count` int UNSIGNED NOT NULL DEFAULT '0',
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_`),
  UNIQUE KEY `uq_phase_cache` (`cache_key`),
  KEY `idx_pcache_project` (`project_id_`),
  KEY `idx_pcache_expires` (`expires_at`),
  CONSTRAINT `fk_pcache_project` FOREIGN KEY (`project_id_`)
      REFERENCES `Projects` (`id_`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------
-- Projects: topes de gasto + un root_prefix por usuario
-- ---------------------------------------------------------------------
ALTER TABLE `Projects`
  ADD COLUMN `budget_usd_monthly` decimal(10,4) NOT NULL DEFAULT '25.0000'
      COMMENT 'Tope de gasto en modelos por mes; 0 = sin límite' AFTER `status`,
  ADD COLUMN `budget_usd_per_edit` decimal(10,6) NOT NULL DEFAULT '0.250000'
      COMMENT 'Tope por operación individual de edición' AFTER `budget_usd_monthly`;

ALTER TABLE `Projects`
  ADD UNIQUE KEY `uq_projects_user_rootprefix` (`user_id_`,`root_prefix`(255));

-- ---------------------------------------------------------------------
-- Índices de apoyo para las consultas del editor
-- ---------------------------------------------------------------------
ALTER TABLE `ProjectSources`
  ADD KEY `idx_ps_project_filename` (`project_id_`,`filename`);

ALTER TABLE `SourceChunks`
  ADD KEY `idx_chunks_project_name` (`project_id_`,`name`(191));
