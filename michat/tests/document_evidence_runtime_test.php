<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/session_file_extractor.php';
require_once dirname(__DIR__).'/includes/Memory/AttachmentEvidence.php';
$checks=0;
function evidenceCheck(bool $ok,string $label): void { global $checks; $checks++; if (!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
$tmp=tempnam(sys_get_temp_dir(),'evidence_');
try {
    file_put_contents($tmp,"<?php\r\n\r\n    function impuesto() {\r\n      return 41837;\r\n    }\r\n");
    $raw=idx_read_safe_text($tmp);
    evidenceCheck(str_contains($raw,"\n\n    function"),'Preserves indentation and empty source lines');
    $chunks=idx_located_chunks($raw,'php',32);
    $found=array_values(array_filter($chunks,static fn($c)=>str_contains($c['content'],'41837')))[0];
    evidenceCheck($found['locator']['line_start']<=4 && $found['locator']['line_end']>=4,'True source line 4 survives chunking');
    $long=idx_located_chunks(str_repeat("línea extensa\n",20000).'FINAL_88317','txt',2000);
    evidenceCheck(str_contains(end($long)['content'],'FINAL_88317'),'Large code/text chunker retains final content beyond 200000 chars');
    foreach ($long as $chunk) evidenceCheck(mb_strlen($chunk['content'])<=2000,'Chunk budget');
    $paged=idx_pdf_page_labels("Primera\f".str_repeat('contenido ',500)."\fTercera\f");
    $chunks=idx_located_chunks($paged,'pdf',120);
    $page2=array_values(array_filter($chunks,static fn($c)=>($c['locator']['page']??0)===2));
    evidenceCheck(count($page2)>4 && str_contains(end($page2)['content'],'contenido'),'Mid-page chunks retain page 2');
    evidenceCheck(str_contains(idx_pdf_page_labels("Digital\f\fFinal\f"),'CONTENIDO NO EXTRAÍDO'),'Mixed digital/scanned pages report extraction gap');
    file_put_contents($tmp,"\x89PNG\0binary");
    $rejected=false;try{idx_read_safe_text($tmp);}catch(RuntimeException $e){$rejected=true;}
    evidenceCheck($rejected,'Binary disguised as source text rejected');
    file_put_contents($tmp,"\xFF\xFE".mb_convert_encoding("uno\ndos",'UTF-16LE','UTF-8'));
    evidenceCheck(idx_read_safe_text($tmp)==="uno\ndos",'UTF16 BOM decoded safely');
    $z=new ZipArchive();$z->open($tmp,ZipArchive::CREATE|ZipArchive::OVERWRITE);
    $z->addFromString('xl/sharedStrings.xml','<sst><si><t>Impuesto</t></si></sst>');
    $z->addFromString('xl/worksheets/sheet1.xml','<worksheet><sheetData><row r="25"><c r="B25"><f>A25*0.16</f><v>41837</v></c><c r="Z25" t="s"><v>0</v></c></row></sheetData></worksheet>');$z->close();
    idx_validate_archive($tmp);$extractor=$error='';$text=idx_extract_xlsx($tmp,$extractor,$error);
    evidenceCheck(str_contains($text,'B25: 41837 [fórmula =A25*0.16]')&&str_contains($text,'Z25: Impuesto'),'Real XLSX sparse cells and formula, without evaluating');
    $chunks=idx_located_chunks($text,'xlsx',48);
    evidenceCheck((end($chunks)['locator']['sheet']??'')==='sheet1','Worksheet locator persists across chunks');
    $z=new ZipArchive();$z->open($tmp,ZipArchive::CREATE|ZipArchive::OVERWRITE);
    $z->addFromString('ppt/slides/slide1.xml','<p:sld><a:p><a:r><a:t>Contrato 41837</a:t></a:r></a:p></p:sld>');
    $z->addFromString('ppt/notesSlides/notesSlide1.xml','<p:notes><a:p><a:r><a:t>Nota verificable 88317</a:t></a:r></a:p></p:notes>');$z->close();
    $text=idx_extract_pptx($tmp,$extractor,$error);$chunks=idx_located_chunks($text,'pptx',40);
    evidenceCheck(str_contains($text,'88317')&&(end($chunks)['locator']['slide_part']??'')==='slide1.xml','Minimal PPTX notes cite internal part without inventing slide order');
    $z=new ZipArchive();$z->open($tmp,ZipArchive::CREATE|ZipArchive::OVERWRITE);
    $z->addFromString('ppt/presentation.xml','<p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><p:sldIdLst><p:sldId id="9" r:id="rId9"/><p:sldId id="1" r:id="rId1"/></p:sldIdLst></p:presentation>');
    $z->addFromString('ppt/_rels/presentation.xml.rels','<Relationships><Relationship Id="rId9" Target="slides/slide9.xml"/><Relationship Id="rId1" Target="slides/slide1.xml"/></Relationships>');
    $z->addFromString('ppt/slides/slide9.xml','<p:sld><a:p><a:r><a:t>Primera visible 99123</a:t></a:r></a:p></p:sld>');
    $z->addFromString('ppt/slides/slide1.xml','<p:sld><a:p><a:r><a:t>Segunda visible</a:t></a:r></a:p></p:sld>');
    $z->addFromString('ppt/slides/_rels/slide9.xml.rels','<Relationships><Relationship Id="notes" Target="../notesSlides/notesSlide7.xml"/></Relationships>');
    $z->addFromString('ppt/notesSlides/notesSlide7.xml','<p:notes><a:p><a:r><a:t>Nota real de la primera</a:t></a:r></a:p></p:notes>');$z->close();
    $text=idx_extract_pptx($tmp,$extractor,$error);$chunks=idx_located_chunks($text,'pptx',100);
    evidenceCheck(strpos($text,'Primera visible')<strpos($text,'Segunda visible'),'PPTX follows presentation order rather than slide part number');
    $note=array_values(array_filter($chunks,static fn($p)=>str_contains($p['content'],'Nota real')))[0];
    evidenceCheck(($note['locator']['slide']??0)===1,'PPTX notes relationships preserve real displayed slide');
    // A two-page real PDF with a verifiable figure on page 2 (Poppler integration).
    if (idx_command_exists('pdftotext')) {
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R 4 0 R] /Count 2 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 6 0 R >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 7 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        foreach (['Portada','Impuesto exacto 41837'] as $page) {
            $stream='BT /F1 12 Tf 72 720 Td ('.$page.') Tj ET';
            $objects[]='<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream";
        }
        $pdf="%PDF-1.4\n";$offsets=[0];
        foreach ($objects as $i=>$object) { $offsets[]=strlen($pdf);$pdf.=($i+1)." 0 obj\n".$object."\nendobj\n"; }
        $xref=strlen($pdf);$pdf.="xref\n0 8\n0000000000 65535 f \n";
        foreach (array_slice($offsets,1) as $offset) $pdf.=sprintf('%010d 00000 n ',$offset)."\n";
        $pdf.="trailer\n<< /Size 8 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";
        file_put_contents($tmp,$pdf);$extractor=$error='';
        $digital=idx_extract_pdf($tmp,'','',$extractor,$error);
        evidenceCheck(str_contains($digital,'[Página 2]')&&str_contains($digital,'41837'),'Actual PDF extraction via Poppler, original page 2');
        $parts=idx_located_chunks($digital,'pdf',100);
        $hit=array_values(array_filter($parts,static fn($p)=>str_contains($p['content'],'41837')))[0];
        evidenceCheck(($hit['locator']['page']??0)===2,'PDF evidence locator matches page containing figure');
    } else echo "SKIP PDF Poppler: pdftotext unavailable\n";
    evidenceCheck(AttachmentEvidence::lexical('99123',$text)>0,'Exact numeric lookup without embeddings');
    evidenceCheck(AttachmentEvidence::cosine([1,0],[1,0])===1.0,'Valid semantic similarity');
    evidenceCheck(AttachmentEvidence::cosine([1,0],[1])===null,'Mismatched vectors rejected');
    $citation=AttachmentEvidence::citation(['id_'=>17,'block_type'=>'file_chunk','source_ids'=>json_encode(['filename'=>'contrato.pdf','chunk'=>8,'locator'=>['page'=>2],'truncated'=>true])]);
    evidenceCheck(str_contains($citation,'SCB:17')&&str_contains($citation,'página 2')&&str_contains($citation,'PARCIAL'),'Verifiable citation and coverage warning');
} finally { @unlink($tmp); }
echo "Document evidence runtime: $checks checks passed\n";
