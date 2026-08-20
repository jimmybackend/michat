ALTER TABLE ToolCalls
  MODIFY COLUMN tool ENUM(
    'grep','view','search','str_replace','code_edit','list_dir','read_chunk','run_shell',
    'create_file','write_file','delete_file','move_file','lint','run_tests','preview_diff','restore_version'
  ) NOT NULL;
