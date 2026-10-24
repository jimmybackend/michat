<?php
declare(strict_types=1);
final class PdfOcrPendingException extends RuntimeException {}

/** Async PDF OCR; AWS's idempotency token resumes a job without resubmitting analysis. */
final class PdfTextractReader
{
    public function __construct(private object $client) {}
    public function read(string $bucket,string $key,array|ArrayAccess $head,?int $epoch=null): string
    {
        $object=['Bucket'=>$bucket,'Name'=>$key];
        if (!empty($head['VersionId']) && $head['VersionId']!=='null') $object['Version']=(string)$head['VersionId'];
        if (empty($head['ETag'])) throw new RuntimeException('No se pudo identificar la revisión PDF para OCR');
        // Tokens expire after seven days. A six-day window avoids resuming expired results.
        $token=hash('sha256',json_encode([$bucket,$key,$head['ETag'],$object['Version'] ?? null,intdiv($epoch ?? time(),518400)],JSON_THROW_ON_ERROR));
        $start=$this->client->startDocumentTextDetection(['DocumentLocation'=>['S3Object'=>$object],'ClientRequestToken'=>$token]);
        $job=(string)($start['JobId'] ?? '');
        if ($job==='') throw new RuntimeException('Textract no devolvió un trabajo OCR');
        $pages=[];$seen=[];$next=null;$bytes=0;$partial=false;$pageCount=0;
        do {
            $args=['JobId'=>$job,'MaxResults'=>1000];if($next!==null)$args['NextToken']=$next;
            $result=$this->client->getDocumentTextDetection($args);
            $status=(string)($result['JobStatus'] ?? '');
            if ($status==='IN_PROGRESS') throw new PdfOcrPendingException('OCR del PDF en proceso. El archivo está vinculado; reintenta en unos segundos para recuperar el resultado.');
            if (!in_array($status,['SUCCEEDED','PARTIAL_SUCCESS'],true)) throw new RuntimeException('Textract no completó el OCR del PDF; comprueba si está protegido, dañado o no es compatible.');
            $partial=$partial || $status==='PARTIAL_SUCCESS' || !empty($result['Warnings']);
            $pageCount=max($pageCount,(int)($result['DocumentMetadata']['Pages'] ?? 0));
            if($pageCount>3000) throw new RuntimeException('El PDF excede el presupuesto OCR de 3000 páginas');
            foreach (($result['Blocks'] ?? []) as $block) {
                if (($block['BlockType'] ?? '')!=='LINE' || trim((string)($block['Text'] ?? ''))==='') continue;
                $page=(int)($block['Page'] ?? 0);
                if ($page<1 || $page>3000) throw new RuntimeException('Textract devolvió una página no verificable');
                $text=(string)$block['Text'];$bytes+=strlen($text)+1;
                if($bytes>8388608) throw new RuntimeException('OCR supera 8 MiB de texto; no se guardó un prefijo incompleto');
                $pages[$page][]=$text;
            }
            $next=isset($result['NextToken'])?(string)$result['NextToken']:null;
            if ($next!==null) {
                if ($next==='' || isset($seen[$next]) || count($seen)>=1000) throw new RuntimeException('Paginación OCR inválida o excedida; no se marcó como completa');
                $seen[$next]=true;
            }
        } while ($next!==null);
        if (!$pages) throw new RuntimeException('OCR no encontró texto legible en el PDF');
        $pageCount=max($pageCount,max(array_keys($pages)));$out=[];
        for($page=1;$page<=$pageCount;$page++) $out[]='[Página '.$page."]\n".(isset($pages[$page])?implode("\n",$pages[$page]):'[CONTENIDO NO EXTRAÍDO: página sin texto OCR]');
        if($partial)$out[]='[CONTENIDO NO EXTRAÍDO: Textract informó resultados OCR parciales o advertencias]';
        return implode("\n\n",$out);
    }
}
