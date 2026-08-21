<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
$page=file_get_contents(__DIR__.'/../task_center.php');$js=file_get_contents(__DIR__.'/../js/task-center.js');$css=file_get_contents(__DIR__.'/../css/task-center.css');$chat=file_get_contents(__DIR__.'/../chat.php');
$checks=[
 str_contains($page,"['user_id']")&&str_contains($page,'csrf-token'),
 str_contains($js,"credentials:'same-origin'")&&str_contains($js,"'X-CSRF-Token':csrf"),
 str_contains($js,'public_id')&&!str_contains($js,'execution_id')&&!str_contains($js,'checkpoint_json'),
 str_contains($js,'approve_tool')&&str_contains($js,'approve_step')&&str_contains($js,'reject_step'),
 str_contains($js,'resume_tool')&&str_contains($js,'Reanudar')&&!str_contains($js,'execution_id'),
 str_contains($js,'chat_activity_viewer.php')&&str_contains($page,'Trace Explorer'),
 str_contains($css,'@media(max-width:760px)')&&str_contains($chat,'task_center.php'),
];foreach($checks as$i=>$ok)echo($ok?'PASS ':'FAIL ').'Task Center '.($i+1)."\n";exit(in_array(false,$checks,true)?1:0);
