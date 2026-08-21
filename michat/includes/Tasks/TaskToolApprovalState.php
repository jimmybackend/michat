<?php
declare(strict_types=1);

/** Immutable internal classification of a persisted Tool approval checkpoint. */
final class TaskToolApprovalState
{
    public const NONE='none',PENDING='pending',APPROVED='approved',CONSUMED='consumed';

    private function __construct(
        public readonly string $status,
        public readonly ?TaskToolApprovalIdentity $identity=null,
        public readonly ?TaskToolApprovalProposal $proposal=null,
        public readonly ?int $consumerExecutionId=null
    ){}

    public static function fromCheckpoint(?string$json):self
    {
        if($json===null||trim($json)==='')return new self(self::NONE);
        try{$checkpoint=json_decode($json,true,32,JSON_THROW_ON_ERROR);}catch(JsonException){throw self::invalid();}
        if(!is_array($checkpoint)||array_is_list($checkpoint))throw self::invalid();
        if(!array_key_exists('tool_approval',$checkpoint))return new self(self::NONE);
        $approval=$checkpoint['tool_approval'];
        if(!is_array($approval)||array_is_list($approval)||($approval['format_version']??null)!==1
            ||!is_array($approval['identity']??null)||!is_array($approval['proposal']??null))throw self::invalid();
        $allowed=['format_version','identity','proposal','decision'];
        if(array_diff(array_keys($approval),$allowed)!==[])throw self::invalid();
        try{$identity=TaskToolApprovalIdentity::fromArray($approval['identity']);$proposal=TaskToolApprovalProposal::fromArray($approval['proposal']);}
        catch(TaskValidationException){throw self::invalid();}
        if(!array_key_exists('decision',$approval))return new self(self::PENDING,$identity,$proposal);
        $decision=$approval['decision'];
        if(!is_array($decision)||array_is_list($decision)||($decision['status']??null)!=='approved'
            ||!is_string($decision['fingerprint']??null)||preg_match('/^[a-f0-9]{64}$/D',$decision['fingerprint'])!==1
            ||!hash_equals($proposal->fingerprint,$decision['fingerprint'])||!is_bool($decision['consumed']??null))throw self::invalid();
        if($decision['consumed']===false){
            $keys=['status','fingerprint','consumed'];if(count($decision)!==count($keys)||array_diff($keys,array_keys($decision))!==[])throw self::invalid();
            return new self(self::APPROVED,$identity,$proposal);
        }
        $keys=['status','fingerprint','consumed','consumer_execution_id','consumed_at'];
        if(count($decision)!==count($keys)||array_diff($keys,array_keys($decision))!==[]
            ||!is_int($decision['consumer_execution_id'])||$decision['consumer_execution_id']<1
            ||!is_string($decision['consumed_at'])||!self::validTimestamp($decision['consumed_at']))throw self::invalid();
        return new self(self::CONSUMED,$identity,$proposal,$decision['consumer_execution_id']);
    }

    private static function validTimestamp(string$value):bool
    {
        $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u',$value,new DateTimeZone('UTC'));
        return$date!==false&&$date->format('Y-m-d H:i:s.u')===$value;
    }
    private static function invalid():TaskValidationException{return new TaskValidationException('tool_approval_state_invalid');}
}
