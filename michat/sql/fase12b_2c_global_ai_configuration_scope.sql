-- Fase 12B.2C Parte 2A: GLOBAL/USER AI configuration scope.
-- Forward-only, fail-closed and safe to execute again after a complete migration.
DELIMITER $$

DROP PROCEDURE IF EXISTS `michat_fase12b2c_global_ai_scope`$$
CREATE PROCEDURE `michat_fase12b2c_global_ai_scope`()
main: BEGIN
  DECLARE v_table INT DEFAULT 0;
  DECLARE v_scope INT DEFAULT 0;
  DECLARE v_owner INT DEFAULT 0;
  DECLARE v_user_nullable VARCHAR(3) DEFAULT '';
  DECLARE v_owner_extra VARCHAR(255) DEFAULT '';
  DECLARE v_check INT DEFAULT 0;
  DECLARE v_unique INT DEFAULT 0;
  DECLARE v_fk INT DEFAULT 0;
  DECLARE v_bad BIGINT DEFAULT 0;
  DECLARE v_name VARCHAR(64);
  DECLARE v_done INT DEFAULT 0;

  DECLARE legacy_indexes CURSOR FOR
    SELECT s.INDEX_NAME
      FROM information_schema.STATISTICS s
     WHERE s.TABLE_SCHEMA = DATABASE() AND s.TABLE_NAME = 'UserAIAgentConfigs'
     GROUP BY s.INDEX_NAME
    HAVING MIN(s.NON_UNIQUE) = 0
       AND GROUP_CONCAT(s.COLUMN_NAME ORDER BY s.SEQ_IN_INDEX) = 'user_id_,agent_key';
  DECLARE legacy_fks CURSOR FOR
    SELECT k.CONSTRAINT_NAME
      FROM information_schema.KEY_COLUMN_USAGE k
     WHERE k.CONSTRAINT_SCHEMA = DATABASE() AND k.TABLE_NAME = 'UserAIAgentConfigs'
       AND k.COLUMN_NAME = 'user_id_' AND k.REFERENCED_TABLE_NAME IS NOT NULL;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;

  SELECT COUNT(*) INTO v_table FROM information_schema.TABLES
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'UserAIAgentConfigs' AND TABLE_TYPE = 'BASE TABLE';
  IF v_table <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fase12b2c: UserAIAgentConfigs table is required'; END IF;

  SELECT COUNT(*) INTO v_scope FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'UserAIAgentConfigs' AND COLUMN_NAME = 'scope';
  SELECT COUNT(*) INTO v_owner FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'UserAIAgentConfigs' AND COLUMN_NAME = 'scope_owner_key';

  -- A complete target schema is an explicit no-op; anything half-created fails closed.
  IF v_scope = 1 AND v_owner = 1 THEN
    SELECT IS_NULLABLE INTO v_user_nullable FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'UserAIAgentConfigs' AND COLUMN_NAME = 'user_id_';
    SELECT EXTRA INTO v_owner_extra FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'UserAIAgentConfigs' AND COLUMN_NAME = 'scope_owner_key';
    SELECT COUNT(*) INTO v_check FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'UserAIAgentConfigs'
       AND CONSTRAINT_TYPE = 'CHECK' AND CONSTRAINT_NAME = 'chk_uac_scope_owner';
    SELECT COUNT(*) INTO v_unique FROM (
      SELECT INDEX_NAME FROM information_schema.STATISTICS
       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'UserAIAgentConfigs'
       GROUP BY INDEX_NAME
      HAVING MIN(NON_UNIQUE) = 0
         AND GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) = 'scope,scope_owner_key,agent_key'
    ) target_unique;
    SELECT COUNT(*) INTO v_fk
      FROM information_schema.KEY_COLUMN_USAGE k
      JOIN information_schema.REFERENTIAL_CONSTRAINTS r
        ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
       AND r.TABLE_NAME = k.TABLE_NAME
     WHERE k.CONSTRAINT_SCHEMA = DATABASE() AND k.TABLE_NAME = 'UserAIAgentConfigs'
       AND k.COLUMN_NAME = 'user_id_' AND k.REFERENCED_TABLE_NAME = 'Users'
       AND k.REFERENCED_COLUMN_NAME = 'id' AND r.DELETE_RULE = 'CASCADE';
    SELECT COUNT(*) INTO v_bad FROM UserAIAgentConfigs
     WHERE NOT ((scope = 'global' AND user_id_ IS NULL) OR (scope = 'user' AND user_id_ IS NOT NULL));
    IF v_user_nullable = 'YES' AND v_owner_extra LIKE '%STORED GENERATED%'
       AND v_check = 1 AND v_unique = 1 AND v_fk >= 1 AND v_bad = 0 THEN
      LEAVE main;
    END IF;
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fase12b2c: incompatible partially migrated schema';
  END IF;
  IF v_scope <> 0 OR v_owner <> 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fase12b2c: incompatible partially migrated schema';
  END IF;

  SELECT COUNT(*) INTO v_bad FROM (
    SELECT user_id_, agent_key FROM UserAIAgentConfigs GROUP BY user_id_, agent_key HAVING COUNT(*) > 1
  ) duplicates;
  IF v_bad > 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fase12b2c: duplicate legacy owner and agent_key'; END IF;
  SELECT COUNT(*) INTO v_bad FROM UserAIAgentConfigs c
   LEFT JOIN Users u ON u.id = c.user_id_ WHERE c.user_id_ <> 1 AND u.id IS NULL;
  IF v_bad > 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fase12b2c: orphan legacy user override'; END IF;

  -- Audit and remove real legacy FK/index names rather than assuming dump names.
  SET v_done = 0;
  OPEN legacy_fks;
  fk_loop: LOOP
    FETCH legacy_fks INTO v_name;
    IF v_done = 1 THEN LEAVE fk_loop; END IF;
    SET @fase12_sql = CONCAT('ALTER TABLE `UserAIAgentConfigs` DROP FOREIGN KEY `', REPLACE(v_name,'`','``'), '`');
    PREPARE michat_fase12_stmt FROM @fase12_sql; EXECUTE michat_fase12_stmt; DEALLOCATE PREPARE michat_fase12_stmt;
  END LOOP;
  CLOSE legacy_fks;

  SET v_done = 0;
  OPEN legacy_indexes;
  index_loop: LOOP
    FETCH legacy_indexes INTO v_name;
    IF v_done = 1 THEN LEAVE index_loop; END IF;
    SET @fase12_sql = CONCAT('ALTER TABLE `UserAIAgentConfigs` DROP INDEX `', REPLACE(v_name,'`','``'), '`');
    PREPARE michat_fase12_stmt FROM @fase12_sql; EXECUTE michat_fase12_stmt; DEALLOCATE PREPARE michat_fase12_stmt;
  END LOOP;
  CLOSE legacy_indexes;

  ALTER TABLE UserAIAgentConfigs
    ADD COLUMN scope ENUM('global','user') NOT NULL DEFAULT 'user' AFTER id_,
    MODIFY COLUMN user_id_ INT NULL;
  UPDATE UserAIAgentConfigs
     SET scope = IF(user_id_ = 1, 'global', 'user'),
         user_id_ = IF(user_id_ = 1, NULL, user_id_);
  ALTER TABLE UserAIAgentConfigs
    ADD COLUMN scope_owner_key INT GENERATED ALWAYS AS
      (CASE WHEN scope = 'global' THEN 0 ELSE user_id_ END) STORED AFTER user_id_,
    ADD CONSTRAINT chk_uac_scope_owner CHECK (
      (scope = 'global' AND user_id_ IS NULL) OR
      (scope = 'user' AND user_id_ IS NOT NULL)
    ),
    ADD UNIQUE KEY uq_uac_scope_owner_agent (scope, scope_owner_key, agent_key),
    ADD CONSTRAINT fk_uac_user FOREIGN KEY (user_id_) REFERENCES Users (id) ON DELETE CASCADE;
END$$

CALL `michat_fase12b2c_global_ai_scope`()$$
DROP PROCEDURE `michat_fase12b2c_global_ai_scope`$$
DELIMITER ;
