<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
$repository=file_get_contents(__DIR__.'/../includes/Tasks/TaskRepository.php');
$service=file_get_contents(__DIR__.'/../includes/Tasks/TaskApplicationService.php');
$checks=[
    str_contains($repository,"\$where=\$prefix.'user_id_=?'")&&str_contains($repository,"title LIKE CONCAT('%',?,'%')")&&str_contains($repository,"objective LIKE CONCAT('%',?,'%')"),
    str_contains($repository,'$needle=str_replace')&&str_contains($repository,'$v[]=$needle'),
    str_contains($repository,"ownedFilters(\$u,\$f,'Tasks.')")&&str_contains($repository,'ownedFilters($u,$f)'),
    str_contains($service,"\$f['q']=\$search")&&str_contains($service,"mb_strlen(\$search)>200")&&str_contains($service,"search_invalid"),
    str_contains($service,"['project_id'=>'project_id_','session_id'=>'session_id_']")&&str_contains($service,"['low','normal','high','urgent']"),
    str_contains($service,'$this->tasks->countOwned($u,$f)')&&str_contains($service,"'total'=>"),
];
foreach($checks as$i=>$ok)echo($ok?'PASS ':'FAIL ').'Task discovery contract '.($i+1)."\n";
exit(in_array(false,$checks,true)?1:0);
