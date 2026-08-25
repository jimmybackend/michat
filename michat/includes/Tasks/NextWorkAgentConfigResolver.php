<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/ai_agent_runtime.php';
final class NextWorkAgentConfig{public function __construct(public readonly string$agentKey,public readonly string$model,public readonly string$systemInstruction,public readonly string$userTemplate,public readonly float$temperature,public readonly int$maxTokens,public readonly float$topP){}}
final class NextWorkAgentConfigResolver{
 public const AGENT_KEY='next_work_evaluator',FALLBACK_KEY='chat_main',MAX_SYSTEM_CHARS=12000,MAX_TEMPLATE_CHARS=6000,MAX_OUTPUT_TOKENS=3000;private$loader;
 /** @param callable(mysqli,int):array|null $loader */public function __construct(private mysqli$db,?callable$loader=null){$this->loader=$loader;}
 public function resolve(int$userId):NextWorkAgentConfig{$configs=$this->loader!==null?($this->loader)($this->db,$userId):loadDynamicAIAgentConfigs($this->db,$userId);$key=isset($configs[self::AGENT_KEY])?self::AGENT_KEY:self::FALLBACK_KEY;$cfg=$configs[$key]??null;if(!is_array($cfg)||(int)($cfg['is_active']??0)!==1)throw new TaskValidationException('next_work_agent_unavailable');$model=trim((string)($cfg['model_id']??''));if($model==='')throw new TaskValidationException('next_work_model_unavailable');$system=mb_substr((string)($cfg['system_instruction']??''),0,self::MAX_SYSTEM_CHARS);$template=mb_substr((string)($cfg['user_prompt_template']??'{{snapshot}}'),0,self::MAX_TEMPLATE_CHARS);if(trim($template)==='')$template='{{snapshot}}';$temperature=max(0.0,min(1.0,(float)($cfg['temperature']??0.0)));$tokens=max(1,min(self::MAX_OUTPUT_TOKENS,(int)($cfg['max_tokens_output']??1200)));$topP=max(0.01,min(1.0,(float)($cfg['top_p']??0.9)));return new NextWorkAgentConfig($key,$model,$system,$template,$temperature,$tokens,$topP);}
}
