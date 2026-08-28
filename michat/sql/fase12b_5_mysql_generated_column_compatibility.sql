-- Fase 12B.5: reconcile generated-column storage with the production MySQL 8 contract.
-- Forward-only. No data truncation, no FK_CHECKS changes and no migration-history rewrites.
DROP PROCEDURE IF EXISTS migrate_fase12b_5_mysql_generated_column_compatibility;
DELIMITER $$
CREATE PROCEDURE migrate_fase12b_5_mysql_generated_column_compatibility()
main: BEGIN
  DECLARE v_count INT DEFAULT 0;
  DECLARE v_extra VARCHAR(255) DEFAULT '';
  DECLARE v_nullable VARCHAR(3) DEFAULT '';
  DECLARE v_expression LONGTEXT DEFAULT '';
  DECLARE v_bad BIGINT DEFAULT 0;
  DECLARE v_check INT DEFAULT 0;
  DECLARE v_unique INT DEFAULT 0;
  DECLARE v_fk INT DEFAULT 0;

  -- ProjectAutonomyCycles.active_project_id_: STORED and VIRTUAL contain the
  -- same deterministic value; production MySQL uses VIRTUAL.
  SELECT COUNT(*),COALESCE(MAX(EXTRA),''),COALESCE(MAX(GENERATION_EXPRESSION),'')
    INTO v_count,v_extra,v_expression
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ProjectAutonomyCycles' AND COLUMN_NAME='active_project_id_';
  IF v_count<>1 OR LOWER(v_expression) NOT LIKE '%status%' OR LOWER(v_expression) NOT LIKE '%project_id_%' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='12B.5 PRECONDITION FAILED: ProjectAutonomyCycles.active_project_id_';
  END IF;
  IF LOWER(v_extra) LIKE '%stored generated%' THEN
    ALTER TABLE ProjectAutonomyCycles
      DROP INDEX uq_project_autonomy_cycle_active,
      DROP COLUMN active_project_id_,
      ADD COLUMN active_project_id_ INT GENERATED ALWAYS AS
        (IF(status='active',project_id_,NULL)) VIRTUAL AFTER status,
      ADD UNIQUE KEY uq_project_autonomy_cycle_active (active_project_id_);
  ELSEIF LOWER(v_extra) NOT LIKE '%virtual generated%' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='12B.5 PRECONDITION FAILED: unsupported active_project_id_ storage';
  END IF;

  SELECT COUNT(*) INTO v_unique FROM (
    SELECT INDEX_NAME FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ProjectAutonomyCycles'
     GROUP BY INDEX_NAME
    HAVING MIN(NON_UNIQUE)=0
       AND GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX)='active_project_id_'
  ) matching_cycle_unique;
  IF v_unique<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='12B.5 PRECONDITION FAILED: active cycle unique'; END IF;

  -- 12B.2C/12B.4 are authoritative for legacy GLOBAL/USER conversion. 12B.5
  -- only reconciles their generated-column representation.
  SELECT COUNT(*) INTO v_count FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='UserAIAgentConfigs' AND COLUMN_NAME='scope';
  IF v_count<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='12B.5 PRECONDITION FAILED: run 12B.2C before 12B.5'; END IF;

  SELECT COUNT(*) INTO v_bad FROM UserAIAgentConfigs
   WHERE NOT ((scope='global' AND user_id_ IS NULL) OR (scope='user' AND user_id_ IS NOT NULL));
  IF v_bad<>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='12B.5 PRECONDITION FAILED: incoherent AI owners'; END IF;

  SELECT COUNT(*),COALESCE(MAX(EXTRA),''),COALESCE(MAX(IS_NULLABLE),''),COALESCE(MAX(GENERATION_EXPRESSION),'')
    INTO v_count,v_extra,v_nullable,v_expression
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='UserAIAgentConfigs' AND COLUMN_NAME='scope_owner_key';
  IF v_count<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='12B.5 PRECONDITION FAILED: scope_owner_key'; END IF;

  SELECT COUNT(*) INTO v_check FROM information_schema.TABLE_CONSTRAINTS
   WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='UserAIAgentConfigs'
     AND CONSTRAINT_NAME='chk_uac_scope_owner' AND CONSTRAINT_TYPE='CHECK';

  IF LOWER(v_extra) LIKE '%stored generated%' THEN
    IF v_check<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='12B.5 PRECONDITION FAILED: legacy scope CHECK missing'; END IF;
    ALTER TABLE UserAIAgentConfigs
      DROP CHECK chk_uac_scope_owner,
      DROP INDEX uq_uac_scope_owner_agent,
      DROP COLUMN scope_owner_key,
      ADD COLUMN scope_owner_key INT GENERATED ALWAYS AS
        (CASE
           WHEN scope='global' AND user_id_ IS NULL THEN 0
           WHEN scope='user' AND user_id_ IS NOT NULL THEN user_id_
           ELSE NULL
         END) VIRTUAL NOT NULL AFTER user_id_,
      ADD UNIQUE KEY uq_uac_scope_owner_agent (scope,scope_owner_key,agent_key);
  ELSEIF LOWER(v_extra) LIKE '%virtual generated%' THEN
    IF v_nullable<>'NO'
       OR LOWER(v_expression) NOT LIKE '%global%'
       OR LOWER(v_expression) NOT LIKE '%user_id_%' THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='12B.5 PRECONDITION FAILED: incompatible VIRTUAL scope_owner_key';
    END IF;
    IF v_check=1 THEN
      ALTER TABLE UserAIAgentConfigs DROP CHECK chk_uac_scope_owner;
    ELSEIF v_check<>0 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='12B.5 PRECONDITION FAILED: duplicate scope CHECK';
    END IF;
  ELSE
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='12B.5 PRECONDITION FAILED: unsupported scope_owner_key storage';
  END IF;

  SELECT COUNT(*) INTO v_unique FROM (
    SELECT INDEX_NAME FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='UserAIAgentConfigs'
     GROUP BY INDEX_NAME
    HAVING MIN(NON_UNIQUE)=0
       AND GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX)='scope,scope_owner_key,agent_key'
  ) matching_owner_unique;
  IF v_unique<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='12B.5 PRECONDITION FAILED: GLOBAL/USER unique'; END IF;

  SELECT COUNT(*) INTO v_fk
    FROM information_schema.KEY_COLUMN_USAGE k
    JOIN information_schema.REFERENTIAL_CONSTRAINTS r
      ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME
   WHERE k.CONSTRAINT_SCHEMA=DATABASE() AND k.TABLE_NAME='UserAIAgentConfigs'
     AND k.COLUMN_NAME='user_id_' AND k.REFERENCED_TABLE_NAME='Users'
     AND k.REFERENCED_COLUMN_NAME='id' AND r.DELETE_RULE='CASCADE';
  IF v_fk<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='12B.5 PRECONDITION FAILED: AI user FK'; END IF;
END$$
DELIMITER ;
CALL migrate_fase12b_5_mysql_generated_column_compatibility();
DROP PROCEDURE migrate_fase12b_5_mysql_generated_column_compatibility;
