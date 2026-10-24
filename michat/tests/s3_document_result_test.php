<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/aws_result.php';
require_once dirname(__DIR__).'/includes/session_file_extractor.php';
require_once dirname(__DIR__).'/includes/Documents/PdfTextractReader.php';
final class Config {
    public static object $s3;
    public static function getS3(): object {return self::$s3;}
    public static function getBucket(): string {return 'private-test-bucket';}
}
$bytes="<?php\nthrow new RuntimeException('NEVER EXECUTE');\n// Importe verificable 41837\n";
Config::$s3=new class($bytes) {
    public array $requests=[];public bool $saveAs=false;
    public function __construct(public string $bytes) {}
    public function headObject(array $p): ArrayAccess {return documentSdkResult(['ContentLength'=>strlen($this->bytes),'ETag'=>'"pinned"','VersionId'=>'original-version']);}
    public function getObject(array $p): ArrayAccess {
        $this->requests[]=$p;
        if($this->saveAs) file_put_contents($p['SaveAs'],$this->bytes);
        $body=fopen('php://temp','w+b');fwrite($body,$this->bytes);rewind($body);
        return documentSdkResult(['Body'=>$body]);
    }
};
$n=0;function resultCheck(bool $ok,string $label): void {global $n;$n++;if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
foreach([false,true] as $saveAs) {
    Config::$s3->saveAs=$saveAs;
    $result=idx_extract_s3_text('Data/Chat/Uploads/1/2026/10/24/6/encrypted.php','encrypted.php');
    resultCheck(str_contains($result['text']??$result['content']??'','41837'),'Real SDK-shaped HeadObject reaches PHP extraction, SaveAs='.(int)$saveAs);
    resultCheck(Config::$s3->requests[array_key_last(Config::$s3->requests)]['IfMatch']==='"pinned"','Download retains the exact ETag condition');
}
$client=new class {
    public array $start=[];
    public function startDocumentTextDetection(array $p): ArrayAccess {$this->start=$p;return documentSdkResult(['JobId'=>'fixture-job']);}
    public function getDocumentTextDetection(array $p): ArrayAccess {return documentSdkResult(['JobStatus'=>'SUCCEEDED','DocumentMetadata'=>['Pages'=>1],'Blocks'=>[['BlockType'=>'LINE','Page'=>1,'Text'=>'OCR 41837']]]);}
};
$ocr=(new PdfTextractReader($client))->read('private-test-bucket','Data/scan.pdf',documentSdkResult(['ETag'=>'"pinned"','VersionId'=>'revision-1']));
resultCheck(str_contains($ocr,'41837'),'OCR accepts SDK object metadata and preserves extracted text');
resultCheck(($client->start['DocumentLocation']['S3Object']['Version']??'')==='revision-1','OCR remains pinned to the original version');
echo 'OK '.$n.' S3 Result extraction checks ('.get_class(documentSdkResult([])).")\n";
