<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
$page=file_get_contents(__DIR__.'/../task_center.php');$js=file_get_contents(__DIR__.'/../js/task-center.js');$operational=file_get_contents(__DIR__.'/../js/task-operational-context.js');$css=file_get_contents(__DIR__.'/../css/task-center.css');$chat=file_get_contents(__DIR__.'/../chat.php');$chatJs=file_get_contents(__DIR__.'/../js/chat.js');
$checks=[
 str_contains($page,"['user_id']")&&str_contains($page,'csrf-token'),
 str_contains($js,"credentials:'same-origin'")&&str_contains($js,"'X-CSRF-Token':csrf"),
 str_contains($js,'public_id')&&!str_contains($js,'execution_id')&&!str_contains($js,'checkpoint_json'),
 str_contains($js,'approve_tool')&&str_contains($js,'approve_step')&&str_contains($js,'reject_step'),
 str_contains($js,'resume_tool')&&str_contains($js,'Reanudar')&&!str_contains($js,'execution_id'),
 str_contains($js,'chat_activity_viewer.php')&&str_contains($page,'Trace Explorer'),
 str_contains($css,'@media(max-width:760px)')&&str_contains($chat,'task_center.php'),
 str_contains($page,'name="q"')&&str_contains($page,'name="priority"')&&str_contains($page,'name="project_id"')&&str_contains($page,'name="session_id"'),
 str_contains($js,"pageSize=25")&&str_contains($js,'offset:String')&&str_contains($js,'history.replaceState')&&str_contains($js,'controls.next'),
 str_contains($js,"projects.php?action=list")&&str_contains($js,"chat2_sessions.php")&&str_contains($js,'chat.php?session_id='),
 str_contains($chatJs,"new URLSearchParams(window.location.search).get('session_id')")&&str_contains($chatJs,'sessions.some'),
 str_contains($page,'task-operational-context.js')&&str_contains($page,'task-situation')&&str_contains($page,'task-dates'),
 str_contains($js,'current_step')&&str_contains($js,'scheduled_at')&&str_contains($js,'due_at')&&str_contains($js,'wait_until'),
 str_contains($js,"dependencyAction('add_dependency'")&&str_contains($js,"dependencyAction('remove_dependency'")&&str_contains($js,'depends_on_public_id'),
 str_contains($operational,"task.status==='waiting_user'")&&str_contains($operational,"current?.step_type==='wait'")&&str_contains($operational,'dependencySatisfied'),
 str_contains($page,'id="view-list"')&&str_contains($page,'id="view-board"')&&str_contains($page,'id="board"')&&str_contains($page,'board-card-template'),
 str_contains($js,"view=params.get('view')==='board'")&&str_contains($js,"params.set('view','board')")&&str_contains($js,'Tablero de la página actual:'),
 str_contains($js,'renderBoard(pageTasks)')&&str_contains($js,"node.onclick=()=>detail(task.public_id)")&&substr_count($js,'action=detail')===1,
 str_contains($js,'op.groupBoard(tasks)')&&!preg_match('/(?:set_status|change_status|update_status|UPDATE\\s+Tasks)/i',$js),
 str_contains($js,'controls.previous')&&str_contains($js,'controls.next')&&str_contains($js,'queryString()'),
 str_contains($css,'main.board-view')&&str_contains($css,'overflow-x:auto')&&str_contains($css,'scroll-snap-type'),
];foreach($checks as$i=>$ok)echo($ok?'PASS ':'FAIL ').'Task Center '.($i+1)."\n";exit(in_array(false,$checks,true)?1:0);
