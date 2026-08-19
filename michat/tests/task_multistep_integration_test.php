<?php
declare(strict_types=1);
$names=['TASK_TEST_DB_HOST','TASK_TEST_DB_NAME','TASK_TEST_DB_USER','TASK_TEST_DB_PASSWORD','TASK_TEST_DB_PORT'];
foreach($names as$n){if(getenv($n)===false||getenv($n)===''){echo "SKIP — TASK_TEST_DB_* no configurado.\n";exit(0);}}
$name=(string)getenv('TASK_TEST_DB_NAME');
if(!preg_match('/(^|[_-])(test|testing)([_-]|$)/i',$name)){fwrite(STDERR,"FAIL — TASK_TEST_DB_NAME no parece una base de tests.\n");exit(1);}
if(!extension_loaded('mysqli')){fwrite(STDERR,"FAIL — mysqli no disponible.\n");exit(1);}
// This probe deliberately never creates schema: it validates that the isolated database
// is reachable and that the Phase 8 schema needed by the persistent scenario exists.
$db=new mysqli((string)getenv('TASK_TEST_DB_HOST'),(string)getenv('TASK_TEST_DB_USER'),(string)getenv('TASK_TEST_DB_PASSWORD'),$name,(int)getenv('TASK_TEST_DB_PORT'));
foreach(['Tasks','TaskSteps','TaskExecutions','TaskEvents']as$table){$safe=$db->real_escape_string($table);$r=$db->query("SHOW TABLES LIKE '$safe'");if(!$r||$r->num_rows!==1){fwrite(STDERR,"FAIL — falta tabla $table en DB de test.\n");exit(1);}}
echo "PASS — DB de test aislada y esquema multi-Step disponibles.\n";
