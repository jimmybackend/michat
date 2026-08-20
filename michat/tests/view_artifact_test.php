<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
$passed=0;$failed=0;$ok=function(bool$v,string$n)use(&$passed,&$failed):void{echo($v?'PASS ':'FAIL ').$n."\n";$v?$passed++:$failed++;};
$factory=file_get_contents(__DIR__.'/../includes/Tools/ToolRegistryFactory.php');
$viewStart=strpos($factory,"if(\$tool==='view')");$viewEnd=strpos($factory,"else{\$term=",$viewStart);$viewCode=$viewStart===false||$viewEnd===false?'':substr($factory,$viewStart,$viewEnd-$viewStart);
$artifactStart=strpos($factory,"\$artifacts=\$tool==='view'");$returnAt=strpos($factory,'return new ToolExecutionResult',$artifactStart);$artifactCode=$artifactStart===false||$returnAt===false?'':substr($factory,$artifactStart,$returnAt-$artifactStart);
$ok(str_contains($viewCode,'sc.id_ resolved_chunk_id')&&str_contains($viewCode,'WHERE sc.id_=? AND sc.project_id_=?'),'view resuelve SourceChunks.id_ dentro del proyecto mediante query server-side');
$ok(str_contains($factory,"\$resolvedChunkId=(int)\$row['resolved_chunk_id']")&&str_contains($factory,"unset(\$row['resolved_chunk_id'])"),'ID de provenance se toma de la fila SQL y no se añade a data');
$ok(str_contains($artifactCode,"[['relation'=>'read','resource_type'=>'source_chunk','resource_id'=>\$resolvedChunkId]]"),'view emite exactamente SourceChunk read');
$ok(!preg_match("/'(?:filename|source_id|project_id|session_id|name|start_line|end_line|content|excerpt|s3_key|s3_path|Ruta)'\\s*=>/",$artifactCode),'artifact no contiene metadata ni rutas');
$ok(str_contains($artifactCode,"\$resolvedChunkId===null?[]")&&str_contains($factory,"if(\$id<1)throw new TaskValidationException('tool_argument_invalid')"),'input inválido o fila ausente no produce artifact');
$ok(str_contains($factory,"['results'=>\$data]")&&str_contains($viewCode,'sc.content')&&str_contains($viewCode,'ps.filename'),'data conserva contenido y metadata funcional previa');
$ok(!str_contains($factory,'TaskArtifactRepository')&&!str_contains($factory,'INSERT INTO TaskArtifacts'),'handler no persiste TaskArtifacts directamente');
echo"Resultado: $passed passed, $failed failed\n";exit($failed?1:0);
