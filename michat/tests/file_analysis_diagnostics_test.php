<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/Drive/FileKnowledgeState.php';
$n=0;$check=function(bool $ok,string $label)use(&$n){$n++;if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";};
foreach([
    ['AWS_S3_BUCKET no configurado','bucket'],
    ['Formato no soportado para extracción de texto: .xyz','formato'],
    ['Archivo excede 64 MiB; requiere procesamiento por lotes.','límite'],
    ['No se pudo crear directorio temporal de extracción.','temporal'],
    ['Textract: InvalidS3ObjectException at https://secret-bucket/object','Textract'],
    ['Call to undefined method Config::getTextract()','configuración'],
    ['ZipArchive no está instalado.','extensión'],
    ['Firma PDF inválida.','firma'],
]as[$raw,$term]){
    $state=FileKnowledgeState::failure(new RuntimeException($raw),'extraction_index');
    $check(mb_stripos($state['message'],$term)!==false&&!str_contains($state['message'],'secret-bucket'),'safe actionable failure '.$term);
}
$pending=FileKnowledgeState::reportFailure(new PdfOcrPendingException('OCR en proceso'),'extraction_index');
$check($pending['status']==='ocr_pending'&&!isset($pending['error_id']),'Pending OCR does not become an error');
$log=tempnam(sys_get_temp_dir(),'analysis-log-');$old=ini_get('error_log');ini_set('error_log',$log);
try{
    $state=FileKnowledgeState::reportFailure(new RuntimeException('unknown https://bucket/secret?token=private password=hidden'),'extraction_index',['user_id'=>1,'session_id'=>2,'file_id'=>3,'ignored'=>'never-log']);
    $contents=file_get_contents($log);
    $check(str_contains($contents,$state['error_id'])&&str_contains($state['message'],$state['error_id']),'same reference links safe response and server log');
    $check(!str_contains($contents,'https://bucket')&&!str_contains($contents,'password=hidden')&&!str_contains($contents,'never-log'),'technical log redacts URL and credentials and limits context');
    $check(str_contains($state['message'],'extracción e indexación')&&!str_contains($state['message'],'unknown'),'unknown internal failure exposes stage, not raw exception');
}finally{ini_set('error_log',(string)$old);unlink($log);}
echo "OK $n analysis diagnostics checks\n";
