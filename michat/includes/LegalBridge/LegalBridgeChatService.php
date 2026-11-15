<?php
declare(strict_types=1);

require_once __DIR__.'/LegalBridgeDocumentService.php';
require_once dirname(__DIR__).'/Tasks/bootstrap.php';

/** No model tool request from Legal may mutate Drive, files or infrastructure. */
final class LegalBridgeDenyTools implements ToolExecutionGateInterface
{
    public function beforeExecute(string $toolKey,array $arguments,array $context): ToolExecutionGateDecision
    {
        throw new LegalBridgeError('tools_forbidden',403);
    }
}

final class LegalBridgeChatService
{
    public function __construct(
        private mysqli $db,
        private LegalBridgeSessionService $sessions,
        private LegalBridgeDocumentService $documents,
        private $engineFactory=null, private $finalizeOverride=null
    ) {}

    public function send(string $credential,int $user,int $session,array $input): array
    {
        $this->sessions->assertOwned($credential,$user,$session);
        $message=$input['message']??null;
        $revision=$input['revision_sha256']??null;
        // Legal-only, signed server-to-server request fields. This does not alter
        // MiChat's general chat prompts, agents, or other conversations.
        $legalIntent=$input['legal_intent']??'answer';
        $legalGuidance=$input['legal_guidance']??'';
        if (!is_string($legalIntent) || !in_array($legalIntent,['answer','review'],true)
            || !is_string($legalGuidance) || mb_strlen($legalGuidance,'UTF-8')>1800) {
            throw new LegalBridgeError('invalid_legal_guidance',422);
        }
        if(!is_string($message)||trim($message)===''||mb_strlen($message)>12000
          || !is_string($revision)||!preg_match('/^[a-f0-9]{64}$/D',$revision)) {
            throw new LegalBridgeError('invalid_message',422);
        }
        $this->assertRevision($credential,$user,$session,$revision);
        $state=$this->documents->status($credential,$user,$session);
        if(!in_array($state['status'],['ready','partial'],true)) throw new LegalBridgeError('document_not_ready',409);

        $lock=LegalBridgeSessionService::chatLock($credential,$user,$session);
        $q=$this->db->prepare('SELECT GET_LOCK(?,1) AS acquired');
        $q->bind_param('s',$lock);$q->execute();$locked=(int)($q->get_result()->fetch_assoc()['acquired']??0)===1;$q->close();
        if(!$locked) throw new LegalBridgeError('session_busy',409);
        try {
            // Verify after acquiring lock as the revision may have changed.
            $this->assertRevision($credential,$user,$session,$revision);
            $q=$this->db->prepare("SELECT c.model_id,c.status,l.session_instructions FROM ChatSessions c JOIN LegalBridgeSessions l ON l.session_id_=c.id_ AND l.user_id_=c.user_id_ WHERE c.id_=? AND c.user_id_=? LIMIT 1");
            $q->bind_param('ii',$session,$user);$q->execute();$chat=$q->get_result()->fetch_assoc();$q->close();
            if(!$chat || $chat['status']!=='open') throw new LegalBridgeError('session_not_open',409);
            $editorialState=$this->editorialState($user,$session,$message);
            $editorialContext=$editorialState['context'];
            $trace=self::traceId();
            $origin=$this->storeMessage($user,$session,'user',$message,null,['trace_id'=>$trace,'revision_sha256'=>$revision]);
            $request=new ChatExecutionRequest(
                $user,$session,null,$origin,$trace,null,
                "Contexto exclusivo del puente de ESFORZADOS LEGAL (no modifica las reglas generales de MiChat):\n"
                .(string)$chat['session_instructions']
                ."\n\nDecisiones confirmadas en la conversación:".$editorialContext
                ."\n\nMODO DE ESTE TURNO: ".($legalIntent==='answer'?'CONSULTA / RESPUESTA':'REVISIÓN GUIADA')
                ."\nInstrucción específica vigente, proporcionada por el servidor autorizado de Legal: "
                .$legalGuidance
                ."\nSi una instrucción anterior de esta sesión pide formular preguntas en todos los turnos, NO la apliques en modo CONSULTA / RESPUESTA. Contesta la solicitud actual utilizando el RAG y sin pedir al usuario información que está en el documento. Evita preámbulos sobre tus instrucciones internas."
                ."\n\nConsulta sobre la revisión SHA-256 ".$revision.":\n".$message,
                null,(string)$chat['model_id'],0.2,1800,0.9,$trace,
                ['legal_bridge'=>true,'legal_revision'=>$revision,'persist_final_response'=>false]
            );
            // Factory creates the existing ContextBuilder -> Bedrock runtime.
            // A rejecting gate prevents any tool execution even on a malicious
            // or unexpected model tool_use response.
            $engine=$this->engineFactory!==null
                ? ($this->engineFactory)($this->db,new LegalBridgeDenyTools())
                : (new ChatExecutionServiceFactory($this->db,null,null,new LegalBridgeDenyTools()))->create();
            $result=$engine->execute($request);
            if($result->isPauseAlreadyPersisted() || trim($result->replyText)==='') throw new LegalBridgeError('model_response_unavailable',503);
            // If a revision was switched during Bedrock execution, do not
            // publish this answer under the new revision. User turn is retained.
            $this->assertRevision($credential,$user,$session,$revision);
            $reply=$result->replyText;
            $guarded=false;
            if ($legalIntent === 'review' && $editorialState['blank_dates'] && self::asksForManualDate($reply)) {
                // Deterministic postcondition: model output must not negate an
                // explicit editorial decision by demanding blank dates again.
                $reply='Entendido. Este documento es un borrador de trabajo; las fechas señaladas para llenado manual se conservarán en blanco. No hace falta proporcionarlas ahora. ¿Qué aspecto de los actos reclamados deseas revisar a continuación?';
                $guarded=true;
            }
            $answer=$this->storeMessage($user,$session,'assistant',$reply,$result->modelId,
                ['trace_id'=>$trace,'revision_sha256'=>$revision,'stop_reason'=>$result->stopReason,'editorial_guard_applied'=>$guarded]);
            try {
                if($this->finalizeOverride!==null) {
                    // Test adapters may verify usage; do not feed rejected text into memory.
                    if (!$guarded) ($this->finalizeOverride)($user,$session,$origin,$answer,$result);
                } else {
                    (new ChatTokenUsageService($this->db))->recordFinal($user,$session,$answer,$result->modelId,$result->tokenUsage,$result->latencyMs);
                    if (!$guarded) (new ChatMemoryFinalizationService($this->db,Config::getBedrockRuntime()))->finalize($user,$session,null,$origin,$answer,[],[]);
                }
            } catch(Throwable $e) {
                // Persistence already succeeded. Never log content or secrets.
                error_log('LEGAL_BRIDGE_MEMORY type='.get_class($e));
            }
            return ['session_id'=>$session,'message_id'=>$origin,'assistant_message_id'=>$answer,
                'trace_id'=>$trace,'revision_sha256'=>$revision,'reply'=>$reply,
                'coverage'=>$state['coverage'],'evidence_status'=>'not_separately_verified'];
        } catch(LegalBridgeError $e) { throw $e; }
        catch(Throwable $e) { throw new LegalBridgeError('chat_execution_failed',503); }
        finally {
            $q=$this->db->prepare('SELECT RELEASE_LOCK(?)');$q->bind_param('s',$lock);$q->execute();$q->close();
        }
    }

    public function suggestions(string $credential,int $user,int $session,array $input): array
    {
        $this->sessions->assertOwned($credential,$user,$session);
        $revision=(string)($input['revision_sha256']??'');
        $section=(string)($input['section']??'');
        if(!preg_match('/^[a-f0-9]{64}$/D',$revision)
          || trim($section)===''||mb_strlen($section)>150) throw new LegalBridgeError('invalid_suggestion_request',422);
        $request=[
            'revision_sha256'=>$revision,
            'message'=>"Revisa exclusivamente el apartado \"".$section."\" del documento adjunto. Devuelve SOLO un objeto JSON con la clave suggestions (arreglo). Cada elemento debe incluir section, original, proposed, justification, information_used, evidence_references (arreglo), and pending_confirmation (arreglo). No inventes hechos, jurisprudencia, autoridades ni fuentes. Si no hay evidencia suficiente devuelve suggestions vacío e identifica pendientes. No ejecutes herramientas ni modifiques archivos."
        ];
        $chat=$this->send($credential,$user,$session,$request);
        $raw=trim((string)$chat['reply']);
        // Models may wrap JSON in Markdown. Only accept strict JSON: never
        // interpret free text as permission to modify the Legal original.
        $parsed=json_decode($raw,true);
        if(!is_array($parsed)||!isset($parsed['suggestions'])||!is_array($parsed['suggestions'])) {
            return ['session_id'=>$session,'trace_id'=>$chat['trace_id'],'suggestions'=>[],
                'status'=>'invalid_model_output','requires_review'=>true];
        }
        $safe=[];
        foreach(array_slice($parsed['suggestions'],0,12) as $s) {
            if(!is_array($s))continue;
            $row=[];
            foreach(['section','original','proposed','justification','information_used'] as $field) {
                $value=$s[$field]??'';
                if(!is_string($value)||mb_strlen($value)>16000){$row=[];break;}
                $row[$field]=$value;
            }
            if(!$row||trim($row['section'])==='')continue;
            foreach(['evidence_references','pending_confirmation'] as $field) {
                $row[$field]=array_values(array_filter(array_slice((array)($s[$field]??[]),0,20),'is_string'));
            }
            $safe[]=$row;
        }
        return ['session_id'=>$session,'trace_id'=>$chat['trace_id'],'revision_sha256'=>$revision,
            'status'=>'proposed','requires_review'=>true,'saved_to_legal'=>false,'suggestions'=>$safe];
    }

    /**
     * User's explicit editorial decisions remain authoritative across turns
     * even when retrieved document chunks emphasize blank placeholders.
     */
    private function editorialState(int $user,int $session,string $currentMessage): array
    {
        $q=$this->db->prepare("SELECT content FROM ChatMessages WHERE session_id_=? AND user_id_=? AND role='user' ORDER BY id_ DESC LIMIT 40");
        $q->bind_param('ii',$session,$user);$q->execute();
        $rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
        $history=array_reverse(array_column($rows,'content'));
        $history[]=$currentMessage;
        $template=false;$blankDates=false;$presented=null;
        foreach($history as $entry) {
            if(!is_string($entry) || mb_strlen($entry)>2000) continue;
            $normalized=mb_strtolower($entry,'UTF-8');
            $normalized=strtr($normalized,['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u']);
            if(preg_match('/\b(machote|plantilla|modelo|formato)\b/u',$normalized)
                && !preg_match('/\b(no es|no se trata de) (un |una )?(machote|plantilla|modelo)\b/u',$normalized)) {
                $template=true;
            }
            if(preg_match('/\b(no se ha presentado|no ha sido presentado|nunca se present|aun no se present|sin presentar|presentado nunca)\b/u',$normalized)) {
                $presented=false;
            }
            if(preg_match('/\b(ya se presento|fue presentado|se presento ante|acabo de presentar)\b/u',$normalized)) {
                $presented=true;
            }
            if(preg_match('/\b(fecha|fechas|dia|dias)\b/u',$normalized)
                && preg_match('/\b(en blanco|quedaran en blanco|lineas|renglones|a mano|cuando ocurra|sin llenar|pendientes?)\b/u',$normalized)) {
                $blankDates=true;
            }
            if(preg_match('/\b(ahora|ya|quiero) (completar|llenar|precisar|fijar) (las? )?fechas?\b/u',$normalized)) {
                $blankDates=false;
            }
        }
        if(!$template && $presented===null && !$blankDates) return ['context'=>'','blank_dates'=>false];
        $rules=[];
        if($template) $rules[]='El usuario identificó este documento como machote, modelo o plantilla. No afirmes que los hechos son acreditados por ese solo motivo; revisa la estructura y los hechos ilustrativos.';
        if($presented===false) $rules[]='El usuario aclaró que el amparo NO ha sido presentado. No preguntes la fecha de presentación ni presupongas que existe un expediente de amparo judicial.';
        if($blankDates) $rules[]='El usuario decidió dejar las fechas y líneas vacías para completarlas A MANO cuando ocurra el hecho. Es una decisión editorial resuelta, NO una pregunta pendiente; conserva los espacios en blanco y revisa otro aspecto.';
        if($presented===true) $rules[]='El usuario aclaró que el escrito ya fue presentado; adapta el contexto al estado más reciente.';
        return ['context'=>"\n\nDecisiones expresas del usuario para esta sesión (sin modificar el escrito):\n- ".implode("\n- ",$rules),'blank_dates'=>$blankDates];
    }

    /** Reject only actual questions asking for dates the user reserved for manual completion. */
    private static function asksForManualDate(string $reply): bool
    {
        if (preg_match_all('/¿[^?]{1,700}\?/u', $reply, $questions)) {
            foreach ($questions[0] as $question) {
                if (preg_match('/\b(fecha|fechas|cu[aá]ndo|d[ií]a|mes|año)\b/iu', $question)) return true;
            }
        }
        // Also recognize answers that omit the Spanish opening question mark.
        return (bool) preg_match('/(?:pregunta\s*:\s*|(?:^|\n)\s*)(?:en\s+qu[eé]\s+fecha|cu[aá]ndo|qu[eé]\s+d[ií]a)[^?\n]{1,450}\?/iu', $reply);
    }

    private function assertRevision(string $credential,int $user,int $session,string $revision): void
    {
        $q=$this->db->prepare('SELECT d.files3_id_ FROM LegalBridgeSessions l JOIN LegalBridgeDocuments d ON d.session_id_=l.session_id_ AND d.user_id_=l.user_id_ AND d.revision_sha256=l.current_revision WHERE l.credential_id=? AND l.user_id_=? AND l.session_id_=? AND l.current_revision=? LIMIT 1');
        $q->bind_param('siis',$credential,$user,$session,$revision);$q->execute();$r=$q->get_result()->fetch_assoc();$q->close();
        if(!$r || !(new DocumentAccessRepository($this->db))->file($user,(int)$r['files3_id_'])) throw new LegalBridgeError('revision_forbidden',403);
    }

    private function storeMessage(int $user,int $session,string $role,string $content,?string $model,array $metadata): int
    {
        $type='text';$phase='respond';$meta=json_encode($metadata,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        $q=$this->db->prepare('INSERT INTO ChatMessages (session_id_,user_id_,role,content_type,content,model_id,meta,phase) VALUES (?,?,?,?,?,?,?,?)');
        $q->bind_param('iissssss',$session,$user,$role,$type,$content,$model,$meta,$phase);
        $q->execute();$id=(int)$this->db->insert_id;$q->close();
        if($id<=0) throw new RuntimeException('message_insert_failed');
        return $id;
    }

    private static function traceId(): string
    {
        $h=bin2hex(random_bytes(16));
        return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20,12);
    }
}
