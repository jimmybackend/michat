-- APLICADA EN PRODUCCIÓN: 2026-08-06
--
-- 002_params_hash_charset.sql
--
-- Añade la columna generada `ToolCalls.params_hash` (base de la detección de
-- bucles), unifica a utf8mb4 las tablas que guardan texto real de usuario y
-- arregla el UNIQUE global de FileS3.Encriptado, que impedía que dos usuarios
-- tuvieran el mismo s3_key.
--
-- Archivo histórico: NO se ejecuta desde PHP y NO hace falta re-aplicarlo.
-- El estado resultante vive en /schema.sql.

-- ---------------------------------------------------------------------
-- ToolCalls.params_hash — VIRTUAL, nunca se escribe, solo se compara.
--
-- MySQL normaliza el JSON al almacenarlo, así que el hash es estable aunque
-- PHP mande las claves en distinto orden: no hace falta ksort().
-- ---------------------------------------------------------------------
ALTER TABLE `ToolCalls`
  ADD COLUMN `params_hash` char(64)
      GENERATED ALWAYS AS (sha2(cast(`params` as char charset utf8mb4),256)) VIRTUAL
      COMMENT 'Hash de params para detectar llamadas idénticas repetidas'
      AFTER `target_path`;

ALTER TABLE `ToolCalls`
  ADD KEY `idx_tc_loop_detect` (`session_id_`,`tool`,`params_hash`,`created_at`);

-- ---------------------------------------------------------------------
-- Charset: a utf8mb4 las tablas con texto real de usuario.
--
-- Users, AccessControl y ChatSessions se quedan en utf8mb3 A PROPÓSITO: sus
-- columnas de texto real ya son utf8mb4 a nivel de columna y el resto son
-- identificadores ASCII.
-- ---------------------------------------------------------------------
ALTER TABLE `ChatMessages` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `S3Folders`    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;

-- FileS3 se convierte DESPUÉS de soltar el UNIQUE global sobre `Encriptado`:
-- con utf8mb4 ese índice de 255 caracteres se pasa del límite de bytes, y de
-- todos modos era incorrecto (impedía el mismo s3_key en dos usuarios).
ALTER TABLE `FileS3` DROP INDEX `Encriptado`;
ALTER TABLE `FileS3` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `FileS3` ADD UNIQUE KEY `uq_files3_user_key` (`user_id_`,`Encriptado`);

-- Índices por usuario que acompañan al cambio de unicidad.
ALTER TABLE `FileS3`
  ADD KEY `idx_files_user_found` (`user_id_`,`Found`),
  ADD KEY `idx_files_user_ruta` (`user_id_`,`Ruta`(191)),
  ADD KEY `idx_files_user_access_found` (`user_id_`,`AccessType`,`Found`);

ALTER TABLE `S3Folders`
  ADD KEY `idx_folders_user_found` (`user_id_`,`Found`);
