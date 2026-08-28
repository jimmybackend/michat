-- Fase 12B.6: DB-backed authorization role and canonical Task Planner.
-- Existing users are never promoted implicitly. A clean installation creates its first
-- superadmin through bin/create_first_user.php after migrations.
DROP PROCEDURE IF EXISTS migrate_fase12b_6_system_role_authorization;
DELIMITER $$
CREATE PROCEDURE migrate_fase12b_6_system_role_authorization()
BEGIN
  DECLARE v_table INT DEFAULT 0;
  DECLARE v_column INT DEFAULT 0;
  DECLARE v_type TEXT DEFAULT NULL;
  DECLARE v_nullable VARCHAR(3) DEFAULT NULL;
  DECLARE v_default TEXT DEFAULT NULL;

  SELECT COUNT(*) INTO v_table FROM information_schema.TABLES
   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Users' AND TABLE_TYPE='BASE TABLE';
  IF v_table<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='12B.6 PRECONDITION FAILED: Users table'; END IF;

  SELECT COUNT(*),MAX(COLUMN_TYPE),MAX(IS_NULLABLE),MAX(COLUMN_DEFAULT)
    INTO v_column,v_type,v_nullable,v_default
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Users' AND COLUMN_NAME='system_role';

  IF v_column=0 THEN
    ALTER TABLE Users
      ADD COLUMN system_role ENUM('user','admin','superadmin') NOT NULL DEFAULT 'user' AFTER role;
  ELSEIF v_column=1 THEN
    IF LOWER(v_type)<>'enum(''user'',''admin'',''superadmin'')'
       OR v_nullable<>'NO' OR v_default<>'user' THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='12B.6 PRECONDITION FAILED: incompatible Users.system_role';
    END IF;
  ELSE
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='12B.6 PRECONDITION FAILED: duplicate Users.system_role';
  END IF;

  SELECT COUNT(*) INTO v_table FROM information_schema.TABLES
   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='UserAIAgentConfigs' AND TABLE_TYPE='BASE TABLE';
  IF v_table<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='12B.6 PRECONDITION FAILED: UserAIAgentConfigs table'; END IF;

  INSERT INTO UserAIAgentConfigs
    (scope,user_id_,agent_key,agent_group,display_name,description,model_id,fallback_model_id,model_ladder_json,
     system_instruction,user_prompt_template,temperature,max_tokens_prompt,max_tokens_output,top_p,seed,max_attempts,
     extra_config,token_usage_phase,is_active,sort_order)
  VALUES
    ('global',NULL,'task_planner','task','Task Planner','Planificador server-side de Tasks de MiChat.','amazon.nova-pro-v1:0',NULL,NULL,
     'Eres el planificador de tareas de MiChat.\r\n\r\nTu única función es convertir un objetivo inmutable en el menor número de pasos ejecutables necesarios.\r\n\r\nDebes devolver únicamente JSON válido.\r\n\r\nCada paso debe usar exclusivamente los tipos permitidos:\r\nmodel, tool, approval, wait, validation, finalize.\r\n\r\nNo ejecutes herramientas.\r\nNo afirmes que una acción ya fue realizada.\r\nNo modifiques ownership.\r\nNo crees tareas hijas.\r\nNo solicites secretos.\r\nNo generes SQL administrativo.\r\nNo cambies políticas de autonomía.\r\n\r\nPrioriza planes cortos, deterministas y verificables.\r\nUsa pasos model para razonamiento o generación.\r\nUsa pasos tool sólo cuando una herramienta real sea necesaria.\r\nUsa approval antes de una acción sensible cuando corresponda.\r\nIncluye validation cuando el resultado deba comprobarse.\r\nTermina con finalize cuando corresponda.',
     NULL,0.10,NULL,1800,0.900,0,1,JSON_OBJECT('purpose','task_planner','max_steps',8,'output_format','json'),'plan',1,400)
  ON DUPLICATE KEY UPDATE
     agent_group=VALUES(agent_group),
     display_name=VALUES(display_name),
     description=VALUES(description),
     model_id=VALUES(model_id),
     fallback_model_id=VALUES(fallback_model_id),
     model_ladder_json=VALUES(model_ladder_json),
     system_instruction=VALUES(system_instruction),
     user_prompt_template=VALUES(user_prompt_template),
     temperature=VALUES(temperature),
     max_tokens_prompt=VALUES(max_tokens_prompt),
     max_tokens_output=VALUES(max_tokens_output),
     top_p=VALUES(top_p),
     seed=VALUES(seed),
     max_attempts=VALUES(max_attempts),
     extra_config=VALUES(extra_config),
     token_usage_phase=VALUES(token_usage_phase),
     is_active=VALUES(is_active),
     sort_order=VALUES(sort_order);
END$$
DELIMITER ;
CALL migrate_fase12b_6_system_role_authorization();
DROP PROCEDURE migrate_fase12b_6_system_role_authorization;
