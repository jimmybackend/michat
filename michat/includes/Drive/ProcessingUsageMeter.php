<?php
declare(strict_types=1);

/** Records actual SDK attempts. Unknown rates remain NULL, never "free". */
final class ProcessingUsageMeter
{
    private array $rates=[];
    private string $priceSource='unconfigured';
    public function __construct(private mysqli $db,private array $job,?string $catalog=null)
    {
        $catalog??=(string)(getenv('MICHAT_PROCESSING_PRICES_PATH')?:'');
        if($catalog!==''&&is_file($catalog)){
            $data=json_decode((string)file_get_contents($catalog),true);
            if(is_array($data['rates']??null)){$this->rates=$data['rates'];$this->priceSource=(string)($data['source_id']??basename($catalog));}
        }
    }
    public function wrap(object $client,string $service): object
    {
        return new class($client,$service,$this) {
            public function __construct(private object $client,private string $service,private ProcessingUsageMeter $meter){}
            public function __call(string $method,array $args): mixed{
                $request=$args[0]??[];$id=bin2hex(random_bytes(16));
                // Persist intent before the remote call; a crash leaves an unknown outcome.
                $this->meter->record($this->service,$method,[], 'attempted',$id);
                try{$r=$this->client->$method(...$args);}
                catch(Throwable $e){$this->meter->record($this->service,$method,[],'error',$id);throw $e;}
                $units=[];
                if($this->service==='S3'){
                    $name=['headObject'=>'head','getObject'=>'get','putObject'=>'put','copyObject'=>'copy'][$method]??null;
                    if($name)$units['s3.'.$name.'_request']=1;
                }elseif($this->service==='Bedrock'){
                    $data=$method==='invokeModel'?json_decode((string)($r['body']??''),true):$r;
                    $in=(int)($data['usage']['inputTokens']??$data['inputTextTokenCount']??0);
                    $out=(int)($data['usage']['outputTokens']??0);
                    $model=preg_replace('/[^a-z0-9_]+/','_',strtolower((string)($request['modelId']??'unknown')));
                    $units['bedrock.'.$model.'.input_token']=$in;
                    $units['bedrock.'.$model.'.output_token']=$out;
                    if($in+$out===0)$units['bedrock.usage_unavailable']=1;
                    // invokeModel body streams have been consumed; restore a decoded body.
                    if($method==='invokeModel'&&is_array($data))$r['body']=json_encode($data,JSON_THROW_ON_ERROR);
                }else $units[strtolower($this->service).'.'.$method.'_request']=1;
                if($this->service==='Textract'&&$method==='getDocumentTextDetection'&&!isset($request['NextToken'])&&in_array($r['JobStatus']??'',['SUCCEEDED','PARTIAL_SUCCESS'],true))$this->meter->record('Textract','document_pages',['textract.detect_document_text_page'=>(int)($r['DocumentMetadata']['Pages']??0)],'completed','textract-job-'.($request['JobId']??$id));
                $this->meter->record($this->service,$method,$units,'completed',$id);
                return $r;
            }
        };
    }
    public function record(string $service,string $operation,array $units,string $outcome,string $identity):void
    {
        $amount=0.0;$known=true;
        foreach($units as $unit=>$quantity){
            if((float)$quantity===0)continue;
            if(!isset($this->rates[$unit])||!is_numeric($this->rates[$unit])||(float)$this->rates[$unit]<0)$known=false;
            else $amount+=(float)$quantity*(float)$this->rates[$unit];
        }
        $priced=$known&&$units!==[]&&$outcome==='completed';$cost=$priced?$amount:null;
        $state=$priced?'estimated':'unpriced';
        $key=hash('sha256',$this->job['user_id_'].':'.$service.':'.$identity);
        $json=json_encode($units,JSON_THROW_ON_ERROR);
        $q=$this->db->prepare("INSERT INTO AttachmentProcessingCosts(operation_key,job_id,user_id_,session_id_,files3_id,service,operation,units_json,estimated_cost_usd,pricing_state,price_source,outcome) VALUES(?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE units_json=IF(outcome='attempted',VALUES(units_json),units_json),estimated_cost_usd=IF(outcome='attempted',VALUES(estimated_cost_usd),estimated_cost_usd),pricing_state=IF(outcome='attempted',VALUES(pricing_state),pricing_state),outcome=IF(outcome='attempted',VALUES(outcome),outcome)");
        $q->bind_param('siiiisssdsss',$key,$this->job['id_'],$this->job['user_id_'],$this->job['session_id_'],$this->job['files3_id'],$service,$operation,$json,$cost,$state,$this->priceSource,$outcome);
        $q->execute();$q->close();
    }
}
