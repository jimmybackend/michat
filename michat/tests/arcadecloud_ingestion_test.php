<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/session_file_extractor.php';
require_once __DIR__.'/fixtures/arcadecloud_xlsx.php';
$n=0;$check=static function(bool $ok,string $label)use(&$n){$n++;if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";};
$tmp=tempnam(sys_get_temp_dir(),'ingest-');
try {
    file_put_contents($tmp,arcadecloudXlsxFixture());$reader=$error='';
    $text=idx_extract_xlsx($tmp,$reader,$error);
    $check(str_contains($text,'--- Hoja Impuestos y cuotas ---'),'Strict OOXML workbook relationship keeps actual worksheet name');
    $check(str_contains($text,'G7: 88317')&&str_contains($text,'B1: Impuesto total'),'Prefixed sparse and rich shared-string cells are read');
    $check(!str_contains($text,'A1: Impuesto'),'Empty self-closing cell does not swallow populated B1');
    $check(str_contains($text,'B25: 12345 [fórmula =A25*0.16]'),'Formula and cached value retained without evaluating formula');
    $check(str_contains($text,'C25: 321 [fórmula compartida: expresión no incluida'),'Missing shared-formula expression is explicit');
    $check(str_contains($text,'Z25: Nota verificable'),'Prefixed inline string preserved at original address');
    $check(idx_detect_document_format($tmp,'sin extensión','Data/object')==='xlsx','Actual ZIP parts identify renamed extensionless workbook');
    $check(idx_detect_document_format($tmp,'legacy.xls','Data/legacy.xls')==='xlsx','Mislabeled XLS with XLSX content uses actual container');
    $located=idx_located_chunks($text,'xlsx',70);
    $check(count($located)>1&&count(array_filter($located,fn($c)=>($c['locator']['sheet']??'')!=='Impuestos y cuotas'))===0,'Worksheet locator survives multiple chunks');
    file_put_contents($tmp,arcadecloudXlsxFixture(true));
    try {idx_extract_xlsx($tmp,$reader,$error);throw new RuntimeException('Empty workbook indexed headers');}
    catch(DocumentProcessingException $e){$check($e->reason==='empty_spreadsheet','Empty worksheet is explicit, never fake evidence');}
    file_put_contents($tmp,arcadecloudXlsxFixture(false,true));
    try {idx_extract_xlsx($tmp,$reader,$error);throw new RuntimeException('Invalid shared string accepted');}
    catch(DocumentProcessingException $e){$check($e->reason==='invalid_shared_string','Missing referenced string rejects silent incomplete extraction');}
    file_put_contents($tmp,"\xD0\xCF\x11\xE0binary");
    try {idx_detect_document_format($tmp,'locked.xlsx','x');throw new RuntimeException('OLE read as XLSX');}
    catch(DocumentProcessingException $e){$check($e->reason==='encrypted_office','Binary/encrypted OOXML produces a precise format error');}
    $z=new ZipArchive();$z->open($tmp,ZipArchive::OVERWRITE);$z->addFromString('xl/worksheets/sheet1.xml','<!DOCTYPE x [<!ENTITY leak SYSTEM "file:///etc/passwd">]><worksheet><sheetData/></worksheet>');$z->close();
    try {idx_extract_xlsx($tmp,$reader,$error);throw new RuntimeException('DTD accepted');}
    catch(DocumentProcessingException $e){$check($e->reason==='invalid_xml','External entities rejected');}
    $z=new ZipArchive();$z->open($tmp,ZipArchive::OVERWRITE);$z->addFromString('xl/worksheets/sheet1.xml','<worksheet><sheetData><row r="7"><c r="G7"><v>88317</v></c></row></sheetData>');$z->close();
    try {idx_extract_xlsx($tmp,$reader,$error);throw new RuntimeException('Broken XML tail accepted');}
    catch(DocumentProcessingException $e){$check($e->reason==='invalid_xml','Malformed trailing XML rejects seemingly valid leading rows');}
    if(class_exists('XMLReader')) {
        $rows=[];for($i=1;$i<=22000;$i++)$rows[]='<row r="'.$i.'"><c r="G'.$i.'"><v>'.($i===22000?'99931':'1').'</v></c></row>';
        $z=new ZipArchive();$z->open($tmp,ZipArchive::OVERWRITE);$z->addFromString('xl/worksheets/sheet1.xml','<worksheet><sheetData>'.implode('',$rows).'</sheetData></worksheet>');$z->close();unset($rows);
        $large=idx_extract_xlsx($tmp,$reader,$error);
        $check(str_contains($large,'G22000: 99931'),'Streaming worksheet reader reaches final row 22000 without regex truncation');
    }
    foreach(['G7'=>'G0','r="7"'=>'r="1048577"'] as $from=>$to) {
        file_put_contents($tmp,arcadecloudXlsxFixture());$z=new ZipArchive();$z->open($tmp);$xml=$z->getFromName('xl/worksheets/data-custom.xml');$z->addFromString('xl/worksheets/data-custom.xml',str_replace($from,$to,$xml));$z->close();
        try {idx_extract_xlsx($tmp,$reader,$error);throw new RuntimeException('Invalid address accepted');}
        catch(DocumentProcessingException $e){$check($e->reason==='invalid_cell','Invalid original reference rejected '.$to);}
    }
    $head=['ContentLength'=>6,'ETag'=>'"v1"'];
    foreach(['string','resource','stream','partial-save'] as $mode){
        @unlink($tmp);
        $client=new class($mode){public function __construct(private string $mode){}public function getObject(array $p):array{
            if($this->mode==='partial-save')file_put_contents($p['SaveAs'],'ab');
            if($this->mode==='resource'){$b=fopen('php://temp','w+b');fwrite($b,'abcdef');rewind($b);return ['Body'=>$b];}
            if($this->mode==='stream')return ['Body'=>new class {private int $p=0;public function read(int $n):string{$s=substr('abcdef',$this->p,2);$this->p+=strlen($s);return $s;}public function eof():bool{return $this->p>=6;}public function close():void{}}];
            return ['Body'=>'abcdef'];
        }};
        S3DocumentDownload::save($client,'bucket','exact/key',$head,$tmp);
        $check(file_get_contents($tmp)==='abcdef','Complete Body fallback '.$mode);
    }
    @unlink($tmp);$bad=new class{public function getObject(array $p):array{return ['Body'=>'abc'];}};
    try {S3DocumentDownload::save($bad,'bucket','key',$head,$tmp);throw new RuntimeException('Partial downloaded');}
    catch(DocumentProcessingException $e){$check($e->reason==='incomplete_download','Short download cannot reach the extractor');}
    $retry=new class{public int $calls=0;public array $params=[];public function getObject(array $p):array{$this->calls++;$this->params[]=$p;if($this->calls<3)throw new class extends RuntimeException{public function getStatusCode():int{return 503;}};return ['Body'=>'abcdef'];}};
    @unlink($tmp);S3DocumentDownload::save($retry,'bucket','exact/key',$head,$tmp);
    $check($retry->calls===3&&count(array_filter($retry->params,fn($p)=>$p['Key']!=='exact/key'||$p['IfMatch']!=='"v1"'))===0,'Bounded transient retries keep exact authorized key and revision');
    $denied=new class{public int $calls=0;public function getObject(array $p):array{$this->calls++;throw new class extends RuntimeException{public function getStatusCode():int{return 403;}};}};
    try{S3DocumentDownload::save($denied,'bucket','key',$head,$tmp);}catch(RuntimeException){}
    $check($denied->calls===1,'Access denial never retried or bypassed');
    echo "OK $n ArcadeCloud ingestion checks\n";
}finally{@unlink($tmp);}
