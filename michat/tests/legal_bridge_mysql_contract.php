<?php
declare(strict_types=1);

/**
 * Called by migration_runner_mysql_test.php only against its disposable DB.
 * Uses fictional principals, messages and traces; never contacts AWS.
 */
function runLegalBridgeMysqlContracts(mysqli $db, callable $check): void
{
    require_once dirname(__DIR__).'/includes/LegalBridge/LegalBridgeAuth.php';
    require_once dirname(__DIR__).'/includes/LegalBridge/LegalBridgeSessionService.php';
    $old=[];
    foreach(['LEGAL_BRIDGE_ENABLED','LEGAL_BRIDGE_KEY_ID','LEGAL_BRIDGE_SECRET'] as $k) $old[$k]=getenv($k);
    try {
        putenv('LEGAL_BRIDGE_ENABLED=true');
        putenv('LEGAL_BRIDGE_KEY_ID=legal-hostgator');
        $secret=str_repeat('c',64);
        putenv('LEGAL_BRIDGE_SECRET='.$secret);
        foreach([900001,900002] as $id) {
            $email='fake'.$id.'@example.invalid';$curp='FICTICIO'.str_pad((string)$id,10,'0');$gender='Otro';$role='Otros';$status='Activo';$name='Ficticio';$last='Legal';$password='not-a-real-password';$chat=1;$birth='2000-01-01';
            $q=$db->prepare('INSERT INTO Users (id,firstname,lastname,curp,gender,birthdate,email,password,role,chat,userstatus) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
            $q->bind_param('issssssssis',$id,$name,$last,$curp,$gender,$birth,$email,$password,$role,$chat,$status);
            $q->execute();$q->close();
            $model='amazon.nova-micro-v1:0';
            $q=$db->prepare('INSERT INTO UserPreferences (user_id_,model_id) VALUES (?,?)');
            $q->bind_param('is',$id,$model);$q->execute();$q->close();
        }
        $q=$db->prepare("INSERT INTO LegalBridgeClients (credential_id,user_id_,enabled) VALUES ('legal-hostgator',900001,1)");
        $q->execute();$q->close();
        foreach(['legal-a'=>900001,'legal-b'=>900002] as $subject=>$id){
            $q=$db->prepare("INSERT INTO LegalBridgePrincipals (credential_id,external_subject,user_id_,enabled) VALUES ('legal-hostgator',?,?,1)");
            $q->bind_param('si',$subject,$id);$q->execute();$q->close();
        }
        $auth=new LegalBridgeAuth($db);
        $request=function(string $subject,string $nonce)use($auth,$secret) {
            $body=json_encode(['external_subject'=>$subject,'operation'=>'health','data'=>new stdClass()],JSON_THROW_ON_ERROR);
            $ts=(string)time();$kid='legal-hostgator';
            $sig=hash_hmac('sha256',LegalBridgeAuth::canonical('POST','/api/legal-bridge.php',$body,$ts,$nonce,$kid),$secret);
            return $auth->verify('POST','/api/legal-bridge.php',$body,['x-legal-key-id'=>$kid,'x-legal-timestamp'=>$ts,'x-legal-nonce'=>$nonce,'x-legal-signature'=>$sig],true);
        };
        $a=$request('legal-a',str_repeat('a',32));
        $check($a['user_id']===900001,'legal signed identity A resolves only authorized principal');
        $b=$request('legal-b',str_repeat('b',32));
        $check($b['user_id']===900002,'legal signed identity B resolves different user');
        try { $request('legal-a',str_repeat('a',32));$check(false,'legal nonce replay rejected'); }
        catch (LegalBridgeError $e) { $check($e->getMessage()==='replayed_nonce','legal nonce replay rejected atomically'); }
        try { $request('unknown-user',str_repeat('d',32));$check(false,'unknown legal principal'); }
        catch (LegalBridgeError $e) { $check($e->httpStatus===403,'unknown legal principal denied'); }
        $svc=new LegalBridgeSessionService($db);
        $ref=['external_system'=>'esforzados-legal','external_document_type'=>'amparo_indirecto','external_document_id'=>'4','external_revision'=>str_repeat('e',64),'title'=>'Prueba ficticia'];
        $first=$svc->open('legal-hostgator',900001,$ref);
        $again=$svc->open('legal-hostgator',900001,$ref);
        $check($first['created']===true && $again['created']===false && $first['session_id']===$again['session_id'],'legal idempotent same-owner session');
        $other=$svc->open('legal-hostgator',900002,$ref);
        $check($other['created']===true && $other['session_id']!==$first['session_id'],'legal same external document ID is isolated by user');
        try {$svc->assertOwned('legal-hostgator',900002,$first['session_id']);$check(false,'foreign session');}
        catch (LegalBridgeError $e) {$check($e->httpStatus===404,'foreign session access rejected');}
        $sid=$first['session_id'];
        $q=$db->prepare("INSERT INTO ChatMessages (session_id_,user_id_,role,content_type,content) VALUES (?,900001,'user','text','Mensaje ficticio')");
        $q->bind_param('i',$sid);$q->execute();$q->close();
        $history=$svc->history('legal-hostgator',900001,$sid,0,10);
        $check(count($history['messages'])===1 && $history['messages'][0]['content']==='Mensaje ficticio','authorized history paginates real messages');
        $historyOther=$svc->history('legal-hostgator',900002,$other['session_id'],0,10);
        $check(count($historyOther['messages'])===0,'foreign messages omitted');
        $q=$db->prepare("INSERT INTO ChatActivityEvents (trace_id,session_id_,user_id_,phase,event_key,status,title,summary,details_json) VALUES ('11111111-2222-3333-4444-555555555555',?,900001,'respond','test','info','Ficticio','sin datos privados',JSON_OBJECT('secret','not-for-client'))");
        $q->bind_param('i',$sid);$q->execute();$q->close();
        $activity=$svc->activity('legal-hostgator',900001,$sid,0,10);
        $check(count($activity['events'])===1 && !array_key_exists('details_json',$activity['events'][0]),'activity exposes only whitelisted operational fields');
        require_once __DIR__.'/legal_bridge_document_mysql_contract.php';
        runLegalBridgeDocumentMysqlContracts($db,$check,$sid,(int)$other['session_id']);

        // Archived / trashed sessions are the user's decision: restart
        // the same Legal document from a NEW empty chat without resurrecting
        // history or the old document revision attachment.
        (new SessionLifecycleService($db))->archive(900001,$sid);
        $archived=$svc->open('legal-hostgator',900001,$ref);
        $check($archived['created']===true && $archived['session_id']!==$sid
            && $archived['status']==='open','legal archived session rotates to new open conversation');
        $q=$db->prepare('SELECT status FROM ChatSessions WHERE id_=? AND user_id_=900001');
        $q->bind_param('i',$sid);$q->execute();
        $oldStatus=$q->get_result()->fetch_assoc()['status']??null;$q->close();
        $check($oldStatus==='archived','legal rotation leaves archived chat unchanged');
        $newId=(int)$archived['session_id'];
        $check(count($svc->history('legal-hostgator',900001,$newId,0,10)['messages'])===0,
            'legal restarted chat has no inherited old messages');
        try {$svc->assertOwned('legal-hostgator',900001,$sid);$check(false,'old binding remains');}
        catch(LegalBridgeError $e){$check($e->httpStatus===404,'old archived session no longer used by Legal bridge');}
        $q=$db->prepare('SELECT COUNT(*) total FROM LegalBridgeDocuments WHERE session_id_=? AND user_id_=900001');
        $q->bind_param('i',$sid);$q->execute();
        $oldCopies=(int)$q->get_result()->fetch_assoc()['total'];$q->close();
        $check($oldCopies>=2,'legal old S3 document references are preserved');
        $againFresh=$svc->open('legal-hostgator',900001,$ref);
        $check($againFresh['created']===false && $againFresh['session_id']===$newId,
            'legal repeated open reuses only the active replacement');
        (new SessionLifecycleService($db))->trash(900001,$newId);
        $trashed=$svc->open('legal-hostgator',900001,$ref);
        $check($trashed['created']===true && $trashed['session_id']!==$newId
            && $trashed['status']==='open','legal trashed session rotates to another fresh chat');
        $q=$db->prepare('SELECT status FROM ChatSessions WHERE id_=? AND user_id_=900001');
        $q->bind_param('i',$newId);$q->execute();
        $trashedStatus=$q->get_result()->fetch_assoc()['status']??null;$q->close();
        $check($trashedStatus==='closed','legal rotation leaves trashed session closed');

    } finally {
        foreach($old as $k=>$v) {if($v===false)putenv($k);else putenv($k.'='.$v);}
    }
}
