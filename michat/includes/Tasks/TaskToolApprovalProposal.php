<?php
declare(strict_types=1);

/** Immutable, display-safe identity of a Tool operation awaiting a future decision. */
final class TaskToolApprovalProposal
{
    public function __construct(
        public readonly int $formatVersion,
        public readonly string $toolKey,
        public readonly string $effect,
        public readonly string $reasonCode,
        public readonly string $safeSummary,
        public readonly ?string $safeTarget,
        public readonly string $fingerprint
    ) {}

    /** @return array{format_version:int,tool_key:string,effect:string,reason_code:string,safe_summary:string,safe_target:?string,fingerprint:string} */
    public function toArray(): array
    {
        return [
            'format_version'=>$this->formatVersion,
            'tool_key'=>$this->toolKey,
            'effect'=>$this->effect,
            'reason_code'=>$this->reasonCode,
            'safe_summary'=>$this->safeSummary,
            'safe_target'=>$this->safeTarget,
            'fingerprint'=>$this->fingerprint,
        ];
    }

    public static function fromArray(array $data): self
    {
        $keys=['format_version','tool_key','effect','reason_code','safe_summary','safe_target','fingerprint'];
        if(count($data)!==count($keys)||array_diff($keys,array_keys($data))!==[]||$data['format_version']!==TaskToolApprovalFingerprint::FORMAT_VERSION
            ||!is_string($data['tool_key'])||preg_match('/^[a-z][a-z0-9_]*$/D',$data['tool_key'])!==1
            ||!is_string($data['effect'])||!in_array($data['effect'],['idempotent_write','non_idempotent'],true)
            ||$data['reason_code']!=='write_requires_approval'||!is_string($data['safe_summary'])||$data['safe_summary']===''||mb_strlen($data['safe_summary'])>255
            ||($data['safe_target']!==null&&(!is_string($data['safe_target'])||$data['safe_target']===''||mb_strlen($data['safe_target'])>255||basename(str_replace('\\','/',$data['safe_target']))!==$data['safe_target']))||!is_string($data['fingerprint'])
            ||preg_match('/^[a-f0-9]{64}$/D',$data['fingerprint'])!==1)throw new TaskValidationException('tool_approval_checkpoint_invalid');
        return new self($data['format_version'],$data['tool_key'],$data['effect'],$data['reason_code'],$data['safe_summary'],$data['safe_target'],$data['fingerprint']);
    }
}
