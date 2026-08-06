-- APLICADA EN PRODUCCIÓN: 2026-08-06
--
-- 003_index_gen_test_commands.sql
--
-- Añade `Projects.index_gen` (generación del índice, invalida el caché RAG) y
-- la tabla `ProjectTestCommands`, que es la lista blanca de comandos de test.
--
-- Archivo histórico: NO se ejecuta desde PHP y NO hace falta re-aplicarlo.
-- El estado resultante vive en /schema.sql.

-- ---------------------------------------------------------------------
-- Projects.index_gen
--
-- Va como columna propia y NO dentro de `meta` a propósito: projects.php
-- (action=update) sobrescribe `meta` con JSON del cliente, así que nada que
-- el servidor necesite conservar puede vivir ahí.
--
-- Se incrementa de forma atómica en los dos puntos de escritura a SourceChunks:
--   UPDATE Projects SET index_gen = index_gen + 1 WHERE id_ = ?
-- ---------------------------------------------------------------------
ALTER TABLE `Projects`
  ADD COLUMN `index_gen` int UNSIGNED NOT NULL DEFAULT '0'
      COMMENT 'Generación del índice. +1 en cada escritura a SourceChunks. Invalida el caché RAG. Fuera de meta a propósito: projects.php sobrescribe meta con JSON del cliente.'
      AFTER `budget_usd_per_edit`;

-- ---------------------------------------------------------------------
-- ProjectTestCommands — lista blanca de comandos de test.
--
-- El cliente solo puede enviar un `label`. Nunca un binario, un argumento ni
-- una ruta. El servidor resuelve `bin`, `args` y `cwd` desde esta fila y los
-- ejecuta con proc_open en forma de array, sin shell.
--
-- Esta tabla NO tiene endpoint de escritura, por diseño. Las filas se insertan
-- a mano. Si alguien añade un endpoint que escriba aquí, se reabre el agujero
-- de RCE que esta tabla existe para cerrar.
-- ---------------------------------------------------------------------
CREATE TABLE `ProjectTestCommands` (
  `id_` int NOT NULL AUTO_INCREMENT,
  `project_id_` int NOT NULL,
  `label` varchar(64) NOT NULL COMMENT 'Unico identificador que el cliente puede enviar',
  `bin` varchar(512) NOT NULL COMMENT 'Ruta ABSOLUTA al binario. Nunca del PATH.',
  `args` json NOT NULL COMMENT 'Array de argumentos fijos. proc_open en forma de array, sin shell.',
  `cwd` varchar(1024) DEFAULT NULL COMMENT 'Directorio de trabajo. NULL = el del proyecto.',
  `timeout_sec` smallint UNSIGNED NOT NULL DEFAULT '120',
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `created_by_user_id_` int DEFAULT NULL COMMENT 'Humano que autorizo este comando. SET NULL al borrar el usuario: se pierde el autor, no la fila.',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_`),
  UNIQUE KEY `uq_ptc_project_label` (`project_id_`,`label`),
  KEY `idx_ptc_project_enabled` (`project_id_`,`enabled`),
  KEY `fk_ptc_user` (`created_by_user_id_`),
  CONSTRAINT `fk_ptc_project` FOREIGN KEY (`project_id_`)
      REFERENCES `Projects` (`id_`) ON DELETE CASCADE,
  CONSTRAINT `fk_ptc_user` FOREIGN KEY (`created_by_user_id_`)
      REFERENCES `Users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
