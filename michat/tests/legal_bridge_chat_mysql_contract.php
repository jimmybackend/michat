<?php
declare(strict_types=1);

/** Isolated chat and suggestion authorization with real conversation DB and fake Bedrock. */
function runLegalBridgeChatMysqlContracts(mysqli $db,callable $check,int $session,int $foreignSession):void
{
    require_once dirname(__DIR__).'/includes/LegalBridge/LegalBridgeChatService.php';
    $q=$db->prepare('SELECT current_revision FROM LegalBridgeSessions WHERE session_id_=?');
    $q->bind_param('i',$session);$q->execute();$revision=(string)$q->get_result()->fetch_assoc()['current_revision'];$q->close();
    $sessions=new LegalBridgeSessionService($db);
    $status=function()use($revision):array{return ['status'=>'ready','coverage'=>'complete','revision_sha256'=>$revision,'chunks_total'=>2,'chunks_done'=>2,'chunks_with_embedding'=>2];};
    $documents=new LegalBridgeDocumentService($db,$sessions,null,null,$status);
    $bedrockCalls=0;$throwBedrock=false;$jsonMode=false;$finalized=0;
    $factory=function(mysqli $connection,ToolExecutionGateInterface $gate)use(&$bedrockCalls,&$throwBedrock,&$jsonMode):ChatExecutionService{
        $runtime=new CallableChatRuntime(function(ChatExecutionRequest $request)use(&$bedrockCalls,&$throwBedrock,&$jsonMode):ChatExecutionResult{
            $bedrockCalls++;
            if($throwBedrock)throw new RuntimeException('simulated AWS failure, no real model');
            $reply=$jsonMode?json_encode(['suggestions'=>[['section'=>'A','original'=>'Texto ficticio','proposed'=>'Texto propuesto','justification'=>'Verificar','information_used'=>'Copia simulada','evidence_references'=>[],'pending_confirmation'=>['Hecho no confirmado']]]],JSON_THROW_ON_ERROR):'Respuesta ficticia sin acceso a AWS';
            return new ChatExecutionResult($reply,null,'amazon.nova-micro-v1:0',$request->traceId,['prompt_tokens'=>1,'completion_tokens'=>2]);
        });
        return new ChatExecutionService($runtime);
    };
    $finish=function()use(&$finalized):void{$finalized++;};
    $chat=new LegalBridgeChatService($db,$sessions,$documents,$factory,$finish);
    $request=['message'=>'Resume los hechos','revision_sha256'=>$revision];
    try{$chat->send('legal-hostgator',900002,$session,$request);$check(false,'legal foreign user cannot ask Bedrock');}
    catch(LegalBridgeError $e){$check($e->httpStatus===404,'legal foreign user denied before Bedrock');}
    $check($bedrockCalls===0,'legal foreign request triggers zero model calls');
    try{$chat->suggestions('legal-hostgator',900002,$session,['revision_sha256'=>$revision,'section'=>'A']);$check(false,'foreign legal suggestions');}
    catch(LegalBridgeError $e){$check($e->httpStatus===404,'legal unauthorized suggestions blocked');}
    $check($bedrockCalls===0,'legal unauthorized proposal triggers zero model calls');
    try{$chat->send('legal-hostgator',900001,$session,['message'=>'x','revision_sha256'=>str_repeat('f',64)]);$check(false,'stale legal revision');}
    catch(LegalBridgeError $e){$check($e->httpStatus===403,'legal stale revision denied before model');}
    $check($bedrockCalls===0,'legal stale revision triggers zero model calls');
    $gate=new LegalBridgeDenyTools();
    try{$gate->beforeExecute('file_delete',['path'=>'fake'],['user_id'=>900001]);$check(false,'tool execution');}
    catch(LegalBridgeError $e){$check($e->httpStatus===403,'legal dangerous tool execution denied');}
    $response=$chat->send('legal-hostgator',900001,$session,$request);
    $check($bedrockCalls===1 && $response['reply']==='Respuesta ficticia sin acceso a AWS','legal shared ChatExecutionService runtime persisted answer');
    $check((int)$response['assistant_message_id']>0 && $finalized===1,'legal conversation persisted with existing ChatMessages schema');
    $row=$db->query('SELECT role,meta FROM ChatMessages WHERE id_='.(int)$response['assistant_message_id'])->fetch_assoc();
    $meta=json_decode((string)$row['meta'],true);
    $check($row['role']==='assistant'&&($meta['revision_sha256']??'')===$revision,'legal assistant message records exact active revision');
    // Source-derived memory from an older revision must not enter the new prompt.
    $staleRevision=str_repeat('a',64);
    $staleMeta=json_encode(['revision_sha256'=>$staleRevision],JSON_THROW_ON_ERROR);
    $role='user';$type='text';$oldText='ANTIGUA REVISION NO USAR';
    $q=$db->prepare("INSERT INTO ChatMessages (session_id_,user_id_,role,content_type,content,meta) VALUES (?,900001,?,?,?,?)");
    $q->bind_param('issss',$session,$role,$type,$oldText,$staleMeta);$q->execute();$staleMessage=(int)$db->insert_id;$q->close();
    $oldBlock='MEMORIA VIEJA, EXCLUIR';
    $currentBlock='MEMORIA ACTUAL, PERMITIR';
    $typeBlock='level_0';
    $q=$db->prepare('INSERT INTO SessionContextBlocks (session_id_,block_type,question_msg_id,content_preview) VALUES (?,?,?,?)');
    $q->bind_param('isis',$session,$typeBlock,$staleMessage,$oldBlock);$q->execute();$q->close();
    $currentMessage=(int)$response['message_id'];
    $q=$db->prepare('INSERT INTO SessionContextBlocks (session_id_,block_type,question_msg_id,content_preview) VALUES (?,?,?,?)');
    $q->bind_param('isis',$session,$typeBlock,$currentMessage,$currentBlock);$q->execute();$q->close();
    $memory=new SessionMemoryRepository($db);
    $recent=$memory->recentMessages(900001,$session,20,$revision);
    $contents=implode(' | ',array_map(static fn($x)=>$x->content,$recent));
    $check(!str_contains($contents,$oldText)&&str_contains($contents,'Resume los hechos'),'legal revision filter excludes stale messages without fabricated context');
    $retrieved=$memory->retrieve(900001,$session,'Resume la conversacion',30,null,$revision);
    $check(str_contains($retrieved['context'],$currentBlock)&&!str_contains($retrieved['context'],$oldBlock),'legal revision-bound memory excludes stale SessionContextBlocks');
    $before=(int)$db->query('SELECT COUNT(*) AS n FROM ChatMessages WHERE session_id_='.$session." AND role='assistant'")->fetch_assoc()['n'];
    $throwBedrock=true;
    try{$chat->send('legal-hostgator',900001,$session,$request);$check(false,'simulated Bedrock failure');}
    catch(LegalBridgeError $e){$check($e->httpStatus===503,'legal simulated Bedrock failure is fail closed');}
    $after=(int)$db->query('SELECT COUNT(*) AS n FROM ChatMessages WHERE session_id_='.$session." AND role='assistant'")->fetch_assoc()['n'];
    $check($before===$after,'legal Bedrock failure never writes a fabricated assistant message');
    $throwBedrock=false;$jsonMode=true;
    $suggestions=$chat->suggestions('legal-hostgator',900001,$session,['revision_sha256'=>$revision,'section'=>'A']);
    $check(count($suggestions['suggestions'])===1&&$suggestions['saved_to_legal']===false&&$suggestions['requires_review']===true,'legal suggestions are structured and require review');
    $check($finalized===2,'legal suggestions use same chat execution lifecycle');
}
