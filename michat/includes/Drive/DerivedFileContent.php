<?php
declare(strict_types=1);
require_once __DIR__.'/AnalysisSidecarStore.php';

final class AttachmentProviderPending extends RuntimeException {}

/** Revision-bound derived information; never treats media bytes as plain text. */
final class DerivedFileContent
{
    public const MAX_BYTES=16777216;
    private array $jsonBytes=[];
    public function __construct(private mysqli $db,private object $s3,private string $bucket,private $clientFactory=null){}
    public static function key(array $file):string{return idx_build_s3_key($file).'.michat-u'.(int)$file['user_id_'].'.content.v1.json';}
    public function read(array $original,array $identity):?array
    {
        $doc=$this->json(self::key($original));
        if(!$doc)return null;
        if(($doc['format']??'')!=='michat.derived-content'||($doc['source']??null)!==$identity)return null;
        if(!is_array($doc['extracted']??null)||!is_string($doc['extracted']['content']??null))throw new RuntimeException('JSON derivado inválido');
        (new GeneratedFileCatalog($this->db))->derived($identity,self::key($original),$this->jsonBytes[self::key($original)],'content');
        if(($doc['evidence_kind']??'')==='audio_transcript'&&!empty($doc['raw_json_key'])){
            $name='michat-u'.$identity['owner_id'].'-f'.$identity['file_id'].'-'.substr(hash('sha256',$identity['etag']),0,32);
            $allowed=[$identity['key'].'.michat-u'.$identity['owner_id'].'.'.$name.'.json',dirname($identity['key']).'/'.pathinfo($identity['key'],PATHINFO_FILENAME).'.json'];
            if(!in_array($doc['raw_json_key'],$allowed,true))throw new RuntimeException('La clave de transcripción guardada no corresponde a la convención del origen');
            $this->catalogExisting($identity,$doc['raw_json_key'],'transcription');
        }
        return $doc;
    }
    /** Repair known result bindings on reuse, without bucket listing or a new provider job. */
    public function catalogWorking(array $source,array $working):void
    {
        $this->catalogExisting($source,self::key($working),'content');
        $this->catalogExisting($source,idx_build_s3_key($working).'.transcript.json','transcription_copy');
    }
    private function catalogExisting(array $source,string $key,string $kind):void
    {
        try{$head=$this->s3->headObject(['Bucket'=>$this->bucket,'Key'=>$key]);}
        catch(Throwable $e){$code=method_exists($e,'getAwsErrorCode')?$e->getAwsErrorCode():'';if(in_array($code,['NoSuchKey','NotFound','404'],true))return;throw $e;}
        if(!isset($head['ContentLength']))throw new RuntimeException('Falta tamaño S3 verificable para el catálogo');
        (new GeneratedFileCatalog($this->db))->derived($source,$key,(int)$head['ContentLength'],$kind);
    }
    public function save(array $original,array $identity,array $doc,array $working):void
    {
        $head=$this->s3->headObject(['Bucket'=>$this->bucket,'Key'=>$identity['key']]);
        if(!hash_equals($identity['etag'],(string)($head['ETag']??'')))throw new RuntimeException('El original cambió durante el análisis');
        $doc['format']='michat.derived-content';$doc['version']=1;$doc['source']=$identity;
        $body=json_encode($doc,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        if(strlen($body)>self::MAX_BYTES)throw new RuntimeException('JSON derivado excede 16 MiB; no se truncó');
        foreach(array_unique([self::key($original),self::key($working)]) as $key){
            $this->s3->putObject(['Bucket'=>$this->bucket,'Key'=>$key,'Body'=>$body,'ContentType'=>'application/json','CacheControl'=>'private, no-store']);
            (new GeneratedFileCatalog($this->db))->derived($identity,$key,strlen($body),'content');
        }
    }
    public function extract(array $original,array $working,array $source):array
    {
        $cached=$this->read($original,$source);if($cached)return $cached+['reused'=>true];
        $ext=strtolower(pathinfo($original['Nombre'],PATHINFO_EXTENSION));
        if(in_array($ext,['mp3','mp4','wav','flac','ogg','amr','webm','m4a','opus'],true)){
            return $this->transcribe($original,$working,$source,$ext);
        }
        if(in_array($ext,['mov','avi','mkv'],true))throw new RuntimeException('Este contenedor necesita conversión multimedia en ArcadeCloud; no se interpretaron sus bytes como texto');
        // Reuse an existing MiChat analysis only when all chunks are verified and complete.
        $analysis=(new AnalysisSidecarStore($this->s3,$this->bucket,$this->db))->read($original,$source)['analysis'];
        if(is_array($analysis)&&($analysis['coverage']??'')!=='partial'&&!empty($analysis['chunks'])){
            $chunks=$analysis['chunks'];usort($chunks,static fn($a,$b)=>($a['meta']['chunk']??0)<=>($b['meta']['chunk']??0));$texts=[];
            foreach($chunks as $chunk){$m=$chunk['meta']??[];$text=$chunk['content']??'';if(($m['source_etag']??'')!==$source['etag']||(int)($m['files3_id']??0)!==$source['file_id']||!hash_equals((string)($chunk['sha256']??''),hash('sha256',$text)))throw new RuntimeException('El JSON de análisis contiene evidencia de otra revisión');$texts[]=$text;}
            return ['extracted'=>['content'=>implode("\n",$texts),'ext'=>$ext,'extractor'=>'verified-analysis-json','source_etag'=>$source['etag'],'source_sha256'=>null,'truncated'=>false],'evidence_kind'=>'document_text','summary'=>$analysis['summary']??'','vectors'=>[],'reused'=>true];
        }
        $textract=$ext==='pdf'?new class($this){public function __construct(private DerivedFileContent $source){}public function __call(string $name,array $args):mixed{return $this->source->client('Textract')->$name(...$args);}}:null;
        $extracted=idx_extract_s3_text($source['key'],$source['filename'],PHP_INT_MAX,$this->s3,$textract);
        if(strlen($extracted['content'])>8388608)throw new RuntimeException('Extracción excede 8 MiB; requiere un adaptador por lotes adicional');
        return ['extracted'=>$extracted,'evidence_kind'=>str_contains($extracted['extractor'],'textract')?'ocr':'document_text','reused'=>false,'summary'=>'','vectors'=>[]];
    }
    public function transcribe(array $original,array $working,array $source,string $ext):array
    {
        $client=$this->client('Transcribe');
        $base=pathinfo($source['key'],PATHINFO_FILENAME);
        $canonical=dirname($source['key']).'/'.$base.'.json';
        $head=$this->s3->headObject(['Bucket'=>$this->bucket,'Key'=>$source['key']]);
        // Exact ArcadeCloud sibling convention, not a bucket-wide scan.
        $raw=$this->json($canonical);
        if($raw&&isset($raw['results'],$raw['jobName'])){
            $q=$this->db->prepare("SELECT id_,Metadatos FROM FileS3 WHERE user_id_=? AND Found=1 AND AccessType<>'secure' AND (Encriptado=? OR CONCAT(TRIM(TRAILING '/' FROM Ruta),'/',Encriptado)=?) LIMIT 1");
            $q->bind_param('iss',$source['owner_id'],$canonical,$canonical);$q->execute();$row=$q->get_result()->fetch_assoc();$q->close();
            $meta=json_decode((string)($row['Metadatos']??''),true)?:[];
            if($row&&($meta['origen_encriptado']??'')===$source['key']){
                $job=$client->getTranscriptionJob(['TranscriptionJobName'=>(string)$raw['jobName']])['TranscriptionJob']??[];
                if($this->matchesJob($job,$source,$head)&&($job['TranscriptionJobStatus']??'')==='COMPLETED')
                    return $this->transcript($raw,$canonical,true,$source,$working);
            }
        }
        $name='michat-u'.$source['owner_id'].'-f'.$source['file_id'].'-'.substr(hash('sha256',$source['etag']),0,32);
        $output=$source['key'].'.michat-u'.$source['owner_id'].'.'.$name.'.json';
        try{$job=$client->getTranscriptionJob(['TranscriptionJobName'=>$name])['TranscriptionJob']??[];}
        catch(Throwable $e){
            $code=method_exists($e,'getAwsErrorCode')?$e->getAwsErrorCode():'';
            if(!in_array($code,['NotFoundException','BadRequestException'],true)||($code==='BadRequestException'&&!preg_match('/not found|couldn.t be found|does not exist/i',$e->getMessage())))throw $e;
            try{$job=$client->startTranscriptionJob(['TranscriptionJobName'=>$name,'Media'=>['MediaFileUri'=>'s3://'.$this->bucket.'/'.$source['key']],'MediaFormat'=>$ext==='opus'?'ogg':$ext,'IdentifyLanguage'=>true,'OutputBucketName'=>$this->bucket,'OutputKey'=>$output])['TranscriptionJob']??[];}
            catch(Throwable $start){if(!method_exists($start,'getAwsErrorCode')||$start->getAwsErrorCode()!=='ConflictException')throw $start;$job=$client->getTranscriptionJob(['TranscriptionJobName'=>$name])['TranscriptionJob']??[];}
        }
        if(!$this->matchesJob($job,$source,$head,false))throw new RuntimeException('El trabajo de transcripción no corresponde al original autorizado');
        $status=$job['TranscriptionJobStatus']??'';
        if(in_array($status,['QUEUED','IN_PROGRESS'],true))throw new AttachmentProviderPending('Amazon Transcribe está procesando el audio; se consultará el mismo trabajo sin reenviarlo');
        if($status!=='COMPLETED')throw new RuntimeException('Amazon Transcribe no completó el trabajo: '.(string)($job['FailureReason']??$status));
        $raw=$this->json($output);if(!$raw)throw new AttachmentProviderPending('AWS completó la transcripción; esperando el JSON en S3');
        return $this->transcript($raw,$output,false,$source,$working);
    }
    private function matchesJob(array $job,array $source,array|ArrayAccess $head,bool $checkTime=true):bool
    {
        if(($job['Media']['MediaFileUri']??'')!=='s3://'.$this->bucket.'/'.$source['key'])return false;
        if(!$checkTime)return true; // deterministic name contains current ETag for our jobs
        $started=$job['StartTime']??null;$modified=$head['LastModified']??null;
        $time=static fn($v)=>$v instanceof DateTimeInterface?$v->getTimestamp():(is_string($v)?strtotime($v):false);
        return $time($started)!==false&&$time($modified)!==false&&$time($started)>=$time($modified);
    }
    private function transcript(array $raw,string $rawKey,bool $reused,array $source,array $working):array
    {
        (new GeneratedFileCatalog($this->db))->derived($source,$rawKey,$this->jsonBytes[$rawKey]??throw new RuntimeException('Falta el tamaño verificado del JSON de transcripción'),'transcription');
        $segments=[];$duration=0.0;
        foreach((array)($raw['results']['audio_segments']??[]) as $segment){
            $start=(float)($segment['start_time']??0);$end=(float)($segment['end_time']??$start);$text=trim((string)($segment['transcript']??''));
            if($start<0||$end<$start||!is_finite($end))throw new RuntimeException('Tiempos de transcripción inválidos');
            if($text!=='')$segments[]=['text'=>$text,'start_seconds'=>$start,'end_seconds'=>$end];$duration=max($duration,$end);
        }
        if(!$segments){
            $parts=[];$start=null;$end=0.0;
            foreach((array)($raw['results']['items']??[]) as $item){
                $word=(string)($item['alternatives'][0]['content']??'');
                if(isset($item['start_time'])){$start??=(float)$item['start_time'];$end=(float)($item['end_time']??$start);$duration=max($duration,$end);}
                $parts[]=$word;
                if(count($parts)>=40){$segments[]=['text'=>implode(' ',$parts),'start_seconds'=>$start,'end_seconds'=>$end];$parts=[];$start=null;}
            }
            if($parts)$segments[]=['text'=>implode(' ',$parts),'start_seconds'=>$start,'end_seconds'=>$end];
        }
        if(!$segments){$text=trim((string)($raw['results']['transcripts'][0]['transcript']??''));if($text!=='')$segments[]=['text'=>$text,'start_seconds'=>null,'end_seconds'=>null];}
        if(!$segments)throw new RuntimeException('El JSON de AWS no contiene transcripción utilizable');
        $segments=array_map(static function($s){$s['text']=str_replace(["\r","\n"],' ',$s['text']);return $s;},$segments);
        $content=implode("\n",array_map(static fn($s)=>$s['text'],$segments));
        if(strlen($content)>8388608)throw new RuntimeException('Transcripción excede 8 MiB; no se guardó un prefijo');
        // Keep the raw AWS result beside the chat media as well.
        $transcriptKey=idx_build_s3_key($working).'.transcript.json';$transcriptBody=json_encode($raw,JSON_THROW_ON_ERROR);
        $this->s3->putObject(['Bucket'=>$this->bucket,'Key'=>$transcriptKey,'Body'=>$transcriptBody,'ContentType'=>'application/json','CacheControl'=>'private, no-store']);
        (new GeneratedFileCatalog($this->db))->derived($source,$transcriptKey,strlen($transcriptBody),'transcription_copy');
        return ['extracted'=>['content'=>$content,'ext'=>'txt','extractor'=>'amazon-transcribe-json','source_etag'=>$source['etag'],'source_sha256'=>null,'derived_sha256'=>hash('sha256',$content),'truncated'=>false,'s3_key'=>$source['key']],
            'segments'=>$segments,'duration_seconds'=>$duration,'raw_json_key'=>$rawKey,'evidence_kind'=>'audio_transcript','video_frames_analyzed'=>false,'provider_started_here'=>!$reused,'reused'=>$reused,'summary'=>'','vectors'=>[]];
    }
    public function json(string $key):?array
    {
        try{$head=$this->s3->headObject(['Bucket'=>$this->bucket,'Key'=>$key]);}
        catch(Throwable $e){$code=method_exists($e,'getAwsErrorCode')?$e->getAwsErrorCode():'';if(in_array($code,['NoSuchKey','NotFound','404'],true))return null;throw $e;}
        if((int)($head['ContentLength']??PHP_INT_MAX)>self::MAX_BYTES)throw new RuntimeException('JSON excede el límite seguro de lectura');
        $object=$this->s3->getObject(['Bucket'=>$this->bucket,'Key'=>$key,'IfMatch'=>$head['ETag']]);$body=(string)$object['Body'];
        if(strlen($body)>self::MAX_BYTES)throw new RuntimeException('JSON excede el límite seguro');
        $this->jsonBytes[$key]=strlen($body);
        $json=json_decode($body,true,64,JSON_THROW_ON_ERROR);if(!is_array($json))throw new RuntimeException('JSON inválido');return $json;
    }
    public function client(string $service):object
    {
        if(is_callable($this->clientFactory))return ($this->clientFactory)($service);
        $method='get'.$service;if(method_exists('Config',$method))return Config::$method();
        if(!method_exists('Config','awsClientConfig'))throw new RuntimeException('Falta el cliente AWS '.$service.' en la configuración privada');
        $class=['Transcribe'=>'Aws\\TranscribeService\\TranscribeServiceClient'][$service]??'';
        if($class===''||!class_exists($class))throw new RuntimeException('Cliente AWS no disponible: '.$service);
        return new $class(Config::awsClientConfig(['http'=>['connect_timeout'=>15,'timeout'=>120]]));
    }
}
