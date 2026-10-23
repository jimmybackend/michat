#!/usr/bin/env php
<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404);exit(1); }
try {
    if (count($argv)!==2 || !preg_match('/^--max-batches=([1-9][0-9]?)$/',$argv[1],$match)) throw new InvalidArgumentException('Uso: php document_knowledge_worker.php --max-batches=1..99');
    if (getenv('MICHAT_DOCUMENT_KNOWLEDGE_ENABLED')!=='1') throw new RuntimeException('El motor documental central no está habilitado');
    require_once dirname(__DIR__).'/app_bootstrap.php';
    require_once dirname(__DIR__).'/includes/Documents/DocumentKnowledgeService.php';
    require_once dirname(__DIR__).'/includes/Documents/BedrockDocumentKnowledgeRuntime.php';
    if (!isset($db_connection) || !$db_connection instanceof mysqli) throw new RuntimeException('Base de datos no disponible');
    $service=new DocumentKnowledgeService($db_connection,new BedrockDocumentKnowledgeRuntime($db_connection));
    $count=0;
    for ($i=0;$i<(int)$match[1];$i++) {
        $result=$service->tick();if ($result===null) break;$count++;
        echo json_encode(['job_id'=>$result['id_']??$result['job_id'],'phase'=>$result['phase']??null,'status'=>$result['status'],'chunks_done'=>(int)($result['chunks_done']??0),'chunks_total'=>(int)($result['chunks_total']??0)],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)."\n";
    }
    echo json_encode(['processed_batches'=>$count],JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $e) { fwrite(STDERR,'DOCUMENT_WORKER: '.$e->getMessage()."\n");exit(1); }
