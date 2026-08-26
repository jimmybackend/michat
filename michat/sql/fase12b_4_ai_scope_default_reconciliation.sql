-- Fase 12B.4: reconcile the GLOBAL/USER scope default without rewriting 12B.2C.
-- MySQL 8.0.16+. The procedure validates the published schema and fails closed.
DROP PROCEDURE IF EXISTS migrate_fase12b_4_ai_scope_default_reconciliation;
DELIMITER $$
CREATE PROCEDURE migrate_fase12b_4_ai_scope_default_reconciliation()
BEGIN
  DECLARE v_count INT DEFAULT 0;
  DECLARE v_default TEXT DEFAULT NULL;
  DECLARE v_type TEXT DEFAULT NULL;
  DECLARE v_nullable VARCHAR(3) DEFAULT NULL;

  SELECT COUNT(*) INTO v_count FROM information_schema.TABLES
   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='UserAIAgentConfigs' AND TABLE_TYPE='BASE TABLE';
  IF v_count <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='12B.4 PRECONDITION FAILED: UserAIAgentConfigs table'; END IF;

  SELECT COUNT(*), MAX(COLUMN_TYPE), MAX(IS_NULLABLE), MAX(COLUMN_DEFAULT)
    INTO v_count, v_type, v_nullable, v_default
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='UserAIAgentConfigs' AND COLUMN_NAME='scope';
  IF v_count <> 1 OR LOWER(v_type) <> 'enum(''global'',''user'')' OR v_nullable <> 'NO' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='12B.4 PRECONDITION FAILED: incompatible scope column';
  END IF;

  SELECT COUNT(*) INTO v_count FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='UserAIAgentConfigs' AND COLUMN_NAME='user_id_' AND IS_NULLABLE='YES';
  IF v_count <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='12B.4 PRECONDITION FAILED: nullable user owner'; END IF;

  SELECT COUNT(*) INTO v_count FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='UserAIAgentConfigs' AND COLUMN_NAME='scope_owner_key'
     AND LOWER(EXTRA) LIKE '%stored generated%' AND LOWER(GENERATION_EXPRESSION) LIKE '%scope%'
     AND LOWER(GENERATION_EXPRESSION) LIKE '%user_id_%';
  IF v_count <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='12B.4 PRECONDITION FAILED: generated scope owner'; END IF;

  SELECT COUNT(*) INTO v_count FROM (
    SELECT INDEX_NAME FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='UserAIAgentConfigs'
     GROUP BY INDEX_NAME
    HAVING MIN(NON_UNIQUE)=0
       AND GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX)='scope,scope_owner_key,agent_key'
  ) AS matching_unique;
  IF v_count <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='12B.4 PRECONDITION FAILED: GLOBAL/USER unique'; END IF;

  SELECT COUNT(*) INTO v_count
    FROM information_schema.KEY_COLUMN_USAGE k
    JOIN information_schema.REFERENTIAL_CONSTRAINTS r
      ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME
   WHERE k.CONSTRAINT_SCHEMA=DATABASE() AND k.TABLE_NAME='UserAIAgentConfigs' AND k.COLUMN_NAME='user_id_'
     AND k.REFERENCED_TABLE_NAME='Users' AND k.REFERENCED_COLUMN_NAME='id' AND r.DELETE_RULE='CASCADE';
  IF v_count <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='12B.4 PRECONDITION FAILED: nullable user FK'; END IF;

  SELECT COUNT(*) INTO v_count
    FROM information_schema.TABLE_CONSTRAINTS t
    JOIN information_schema.CHECK_CONSTRAINTS c
      ON c.CONSTRAINT_SCHEMA=t.CONSTRAINT_SCHEMA AND c.CONSTRAINT_NAME=t.CONSTRAINT_NAME
   WHERE t.CONSTRAINT_SCHEMA=DATABASE() AND t.TABLE_NAME='UserAIAgentConfigs'
     AND t.CONSTRAINT_NAME='chk_uac_scope_owner' AND t.CONSTRAINT_TYPE='CHECK'
     AND LOWER(c.CHECK_CLAUSE) LIKE '%scope%' AND LOWER(c.CHECK_CLAUSE) LIKE '%global%'
     AND LOWER(c.CHECK_CLAUSE) LIKE '%user%' AND LOWER(c.CHECK_CLAUSE) LIKE '%user_id_%';
  IF v_count <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='12B.4 PRECONDITION FAILED: scope coherence CHECK'; END IF;

  SELECT COUNT(*) INTO v_count FROM UserAIAgentConfigs
   WHERE NOT ((scope='global' AND user_id_ IS NULL) OR (scope='user' AND user_id_ IS NOT NULL));
  IF v_count <> 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='12B.4 PRECONDITION FAILED: incoherent scope rows'; END IF;

  IF v_default = 'user' THEN
    ALTER TABLE UserAIAgentConfigs ALTER COLUMN scope DROP DEFAULT;
  ELSEIF v_default IS NOT NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='12B.4 PRECONDITION FAILED: unexpected scope default';
  END IF;
END$$
DELIMITER ;
CALL migrate_fase12b_4_ai_scope_default_reconciliation();
DROP PROCEDURE migrate_fase12b_4_ai_scope_default_reconciliation;
