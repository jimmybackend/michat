<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../includes/Tools/ToolRegistryFactory.php';
$passed=0;$failed=0;$ok=function(bool$v,string$n)use(&$passed,&$failed):void{echo($v?'PASS ':'FAIL ').$n."\n";$v?$passed++:$failed++;};
$method=new ReflectionMethod(ToolRegistryFactory::class,'chunkArtifacts');$method->setAccessible(true);
$one=$method->invoke(null,[10]);$many=$method->invoke(null,[10,15,22]);$duplicates=$method->invoke(null,[10,15,10,22,15]);$empty=$method->invoke(null,[]);
$expectedOne=[['relation'=>'read','resource_type'=>'source_chunk','resource_id'=>10]];
$expectedMany=[['relation'=>'read','resource_type'=>'source_chunk','resource_id'=>10],['relation'=>'read','resource_type'=>'source_chunk','resource_id'=>15],['relation'=>'read','resource_type'=>'source_chunk','resource_id'=>22]];
foreach(['grep','search']as$tool){$ok($one===$expectedOne,"{$tool}: un resultado produce SourceChunk read");$ok($many===$expectedMany,"{$tool}: múltiples resultados conservan orden SQL");$ok($duplicates===$expectedMany,"{$tool}: IDs repetidos se deduplican por primera aparición");$ok($empty===[],"{$tool}: cero resultados produce artifacts vacíos");}
$factory=file_get_contents(__DIR__.'/../includes/Tools/ToolRegistryFactory.php');
$ok(str_contains($factory,'SELECT sc.id_ chunk_id')&&str_contains($factory,"\$readChunkIds[]=(int)\$row['chunk_id']"),'grep/search toman resource_id directamente de SourceChunks.id_ devuelto por SQL');
$helperStart=strpos($factory,'private static function chunkArtifacts');$helperEnd=strpos($factory,'private function strReplace',$helperStart);$helper=$helperStart===false||$helperEnd===false?'':substr($factory,$helperStart,$helperEnd-$helperStart);
$ok(!preg_match("/'(?:filename|source_id|project_id|name|content|excerpt|start_line|end_line|score|query|pattern|s3_key|s3_path|Ruta)'\\s*=>/",$helper),'artifacts contienen únicamente identidad mínima');
$ok(str_contains($factory,"['results'=>\$data]")&&str_contains($factory,'ps.filename')&&str_contains($factory,'LEFT(sc.content,1200) content'),'data conserva contrato funcional de resultados');
$ok(!str_contains($factory,'TaskArtifactRepository')&&!str_contains($factory,'INSERT INTO TaskArtifacts'),'read handlers no persisten TaskArtifacts directamente');
echo"Resultado: $passed passed, $failed failed\n";exit($failed?1:0);
