<?php
declare(strict_types=1);

/** MariaDB/MySQL integration with an in-memory S3 fake; never contacts AWS. */
function runLegalBridgeDocumentMysqlContracts(mysqli $db,callable $check,int $session,int $otherSession): void
{
    require_once dirname(__DIR__).'/includes/LegalBridge/LegalBridgeDocumentService.php';
    $old=getenv('MICHAT_DOCUMENT_KNOWLEDGE_ENABLED');
    putenv('MICHAT_DOCUMENT_KNOWLEDGE_ENABLED=1');
    $objects=[];$uploadCount=0;$enqueueCount=0;$failQueue=false;
    $legalOriginal="ORIGINAL LEGAL\nNo debe cambiar ni un byte.";
    $originalDigest=hash('sha256',$legalOriginal);
    $uploader=function(string $tmp,string $name,string $prefix,int $owner,string $mime,int $size)
        use($db,&$objects,&$uploadCount): array {
        $bytes=(string)file_get_contents($tmp);
        if(strlen($bytes)!==$size||$mime!=='text/plain; charset=utf-8') throw new RuntimeException('mock_upload_invalid');
        $key=$prefix.'mock-'.bin2hex(random_bytes(7)).'.txt';
        $objects[$key]=['body'=>$bytes,'acl'=>'private','owner'=>$owner];
        $meta=json_encode(['hash_sha256'=>hash('sha256',$bytes)],JSON_THROW_ON_ERROR);
        $q=$db->prepare("INSERT INTO FileS3 (Nombre,Encriptado,Tamano,Metadatos,Ruta,Found,AccessType,user_id_) VALUES (?,?,?,?,?,1,'normal',?)");
        $q->bind_param('ssissi',$name,$key,$size,$meta,$prefix,$owner);
        $q->execute();$id=(int)$db->insert_id;$q->close();
        $uploadCount++;
        return ['id'=>$id,'key_s3'=>$key];
    };
    $enqueuer=function(int $user,int $sid,int $fid)use(&$enqueueCount,&$failQueue):array {
        $enqueueCount++;
        if($failQueue)throw new RuntimeException('fixture_queue_unavailable');
        return ['ok'=>true,'file_id'=>$fid];
    };
    try {
        $sessions=new LegalBridgeSessionService($db);
        $documents=new LegalBridgeDocumentService($db,$sessions,$uploader,$enqueuer);
        $content="BORRADOR v1\nHecho ficticio.";
        $sha=hash('sha256',$content);
        $request=['revision_sha256'=>$sha,'text'=>$content];
        try{$documents->attach('legal-hostgator',900001,$session,['revision_sha256'=>str_repeat('0',64),'text'=>$content]);$check(false,'legal forged digest rejected');}
        catch(LegalBridgeError $e){$check($e->httpStatus===422,'legal forged digest rejected before S3');}
        $check($uploadCount===0,'legal invalid source has no upload side effects');
        $one=$documents->attach('legal-hostgator',900001,$session,$request);
        $check($one['created']===true&&$one['index_enqueue']==='accepted'&&$uploadCount===1,'legal private copy uploaded and queued');
        $check(count($objects)===1&&reset($objects)['acl']==='private','legal S3 fake has private ACL');
        $check(idx_file_belongs_to_chat_session((new DocumentAccessRepository($db))->file(900001,(int)$one['file_id']),900001,$session),'legal FileS3 ownership and session path verified');
        $two=$documents->attach('legal-hostgator',900001,$session,$request);
        $check($two['created']===false && $two['file_id']===$one['file_id'] && $uploadCount===1,'legal duplicate revision idempotent without second S3 upload');
        $check($enqueueCount===2,'legal duplicate revision can retry index enqueue');

        $newText="BORRADOR v2\nEl hecho ficticio cambió.";
        $newHash=hash('sha256',$newText);
        $new=$documents->attach('legal-hostgator',900001,$session,['revision_sha256'=>$newHash,'text'=>$newText]);
        $check($new['created']===true&&$new['file_id']!==$one['file_id']&&$uploadCount===2,'legal new revision creates separate immutable object');
        $access=new DocumentAccessRepository($db);
        $s=$access->session(900001,$session);
        $check(DocumentAccessRepository::detached($s,(int)$one['file_id']) && DocumentAccessRepository::linked($s,(int)$new['file_id']),'legal old revision detached from active session');
        try {$access->requireFile(900001,$session,(int)$one['file_id']);$check(false,'legal stale retrieval blocked');}
        catch(DocumentAccessDenied $e) {$check(true,'legal stale file denied by real RAG access repository');}
        $files=$access->conversationFiles(900001,$session);
        $ids=array_map(static fn($f)=>(int)$f['id_'],$files);
        $check(in_array((int)$new['file_id'],$ids,true)&&!in_array((int)$one['file_id'],$ids,true),'legal catalog exposes current revision only');
        try {$documents->attach('legal-hostgator',900002,$session,$request);$check(false,'legal cross user attachment');}
        catch(LegalBridgeError $e) {$check($e->httpStatus===404,'legal cross-user attachment denied');}
        $check($uploadCount===2,'legal cross-user rejection has no S3 side effects');

        $status=$documents->status('legal-hostgator',900001,$session);
        $check($status['status']==='pending' && $status['coverage']==='none' && $status['revision_sha256']===$newHash,'legal missing index never claims ready');
        $failQueue=true;
        $reused=$documents->attach('legal-hostgator',900001,$session,['revision_sha256'=>$newHash,'text'=>$newText]);
        $check($reused['created']===false&&$reused['index_enqueue']==='failed'&&$uploadCount===2,'legal queue failure is reported without duplicating S3 object');
        $check(hash('sha256',$legalOriginal)===$originalDigest&&count($objects)===2,'legal untouched original retains byte-for-byte hash');
        $check($enqueueCount===4,'legal retries never silently discard enqueue attempts');
        require_once __DIR__.'/legal_bridge_chat_mysql_contract.php';
        runLegalBridgeChatMysqlContracts($db,$check,$session,$otherSession);
    } finally {
        if($old===false)putenv('MICHAT_DOCUMENT_KNOWLEDGE_ENABLED');else putenv('MICHAT_DOCUMENT_KNOWLEDGE_ENABLED='.$old);
    }
}
