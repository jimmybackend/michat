<?php
declare(strict_types=1);

/** Deterministic SHA-256 identity for an exact Tool operation and server-owned scope. */
final class TaskToolApprovalFingerprint
{
    public const FORMAT_VERSION = 1;

    /** @param array<string,mixed> $arguments @param array<string,mixed> $serverScope */
    public function create(string $toolKey, string $effect, array $arguments, array $serverScope): string
    {
        if ($toolKey === '' || $effect === '') throw new TaskValidationException('tool_approval_identity_invalid');
        $material = $this->canonicalJson([
            'format_version'=>self::FORMAT_VERSION,
            'tool_key'=>$toolKey,
            'effect'=>$effect,
            'arguments'=>$arguments,
            'scope'=>$this->scope($serverScope),
        ]);
        return hash('sha256', $material);
    }

    /** @param array<string,mixed> $scope @return array<string,int|null> */
    private function scope(array $scope): array
    {
        $required=['task_id','step_id','execution_id','user_id','project_id','session_id'];
        if(array_diff($required,array_keys($scope))!==[])throw new TaskValidationException('tool_approval_scope_invalid');
        $out=[];
        foreach($required as$key){
            $value=$scope[$key];
            if($key==='project_id'&&$value===null){$out[$key]=null;continue;}
            if(!is_int($value)||$value<1)throw new TaskValidationException('tool_approval_scope_invalid');
            $out[$key]=$value;
        }
        return$out;
    }

    private function canonicalJson($value): string
    {
        $normalized=$this->normalize($value);
        try{return json_encode($normalized,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);}
        catch(JsonException){throw new TaskValidationException('tool_approval_identity_invalid');}
    }

    private function normalize($value): array
    {
        if($value===null)return['type'=>'null'];
        if(is_bool($value))return['type'=>'bool','value'=>$value];
        if(is_int($value))return['type'=>'int','value'=>$value];
        if(is_float($value)){if(!is_finite($value))throw new TaskValidationException('tool_approval_identity_invalid');return['type'=>'float','value'=>$value];}
        if(is_string($value)){if(!mb_check_encoding($value,'UTF-8'))throw new TaskValidationException('tool_approval_identity_invalid');return['type'=>'string','value'=>$value];}
        if(!is_array($value))throw new TaskValidationException('tool_approval_identity_invalid');
        if(array_is_list($value))return['type'=>'list','value'=>array_map(fn($item)=>$this->normalize($item),$value)];
        $map=[];foreach($value as$key=>$item){if(!is_string($key)&&!is_int($key))throw new TaskValidationException('tool_approval_identity_invalid');$map[(string)$key]=$this->normalize($item);}ksort($map,SORT_STRING);
        return['type'=>'map','value'=>$map];
    }
}
