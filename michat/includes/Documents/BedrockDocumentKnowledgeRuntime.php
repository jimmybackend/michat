<?php
declare(strict_types=1);
require_once __DIR__.'/DocumentKnowledgeRuntime.php';
require_once dirname(__DIR__).'/SessionAttachmentKnowledgeService.php';

final class BedrockDocumentKnowledgeRuntime implements DocumentKnowledgeRuntime
{
    public function __construct(private mysqli $db) {}
    public function describe(array $file): array
    {
        $r=Config::getS3()->headObject(['Bucket'=>Config::getBucket(),'Key'=>idx_build_s3_key($file)]);
        if ((int)($r['ContentLength']??0)>64*1024*1024) throw new RuntimeException('Objeto supera el presupuesto actual de 64 MiB');
        return ['ETag'=>(string)($r['ETag']??''),'ContentLength'=>(int)($r['ContentLength']??0)];
    }
    public function extract(array $file): array
    {
        // CLI extraction is not the legacy 200,000-character prefix. Resource excess fails explicitly.
        return idx_extract_files3_text($file,PHP_INT_MAX);
    }
    public function embeddingModel(int $userId): string
    {
        aiRuntimeLoad($this->db,$userId);
        if (!aiAgentActive('embedding_main',false)) return '';
        $model=(string)aiAgentModel('embedding_main','');
        if ($model==='') throw new RuntimeException('embedding_main está activo sin modelo configurado');
        return $model;
    }
    public function embed(int $userId,string $content,string $expectedModel): array
    {
        return (new SessionAttachmentKnowledgeService($this->db))->vectorizeDocumentChunk($userId,$content,$expectedModel);
    }
}
