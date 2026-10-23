<?php
declare(strict_types=1);
/** Deterministic OOXML fixture: prefixed XML, alternate part name and sparse cells. */
function arcadecloudXlsxFixture(bool $empty=false,bool $invalidReference=false): string
{
    $path=tempnam(sys_get_temp_dir(),'xlsx-fixture-');$z=new ZipArchive();$z->open($path,ZipArchive::OVERWRITE);
    try {
        $ns='http://purl.oclc.org/ooxml/spreadsheetml/main';
        $z->addFromString('[Content_Types].xml','<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/data-custom.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/></Types>');
        $z->addFromString('_rels/.rels','<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="root" Type="http://purl.oclc.org/ooxml/officeDocument/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $z->addFromString('xl/workbook.xml','<ss:workbook xmlns:ss="'.$ns.'" xmlns:r="http://purl.oclc.org/ooxml/officeDocument/relationships"><ss:sheets><ss:sheet name="Impuestos y cuotas" sheetId="1" r:id="sheetOne"/></ss:sheets></ss:workbook>');
        $z->addFromString('xl/_rels/workbook.xml.rels','<p:Relationships xmlns:p="http://schemas.openxmlformats.org/package/2006/relationships"><p:Relationship Id="sheetOne" Type="http://purl.oclc.org/ooxml/officeDocument/relationships/worksheet" Target="worksheets/data-custom.xml"/></p:Relationships>');
        $z->addFromString('xl/sharedStrings.xml','<ss:sst xmlns:ss="'.$ns.'"><ss:si><ss:r><ss:t>Impuesto </ss:t></ss:r><ss:r><ss:t>total</ss:t></ss:r></ss:si></ss:sst>');
        $cells=$empty?'':'<ss:row r="1"><ss:c r="A1" s="0"/><ss:c r="B1" t="s"><ss:v>'.($invalidReference?'99':'0').'</ss:v></ss:c></ss:row><ss:row r="7"><ss:c r="G7"><ss:v>88317</ss:v></ss:c></ss:row><ss:row r="25"><ss:c r="B25"><ss:f>A25*0.16</ss:f><ss:v>12345</ss:v></ss:c><ss:c r="C25"><ss:f t="shared" si="0"/><ss:v>321</ss:v></ss:c><ss:c r="Z25" t="inlineStr"><ss:is><ss:t>Nota verificable</ss:t></ss:is></ss:c></ss:row>';
        $z->addFromString('xl/worksheets/data-custom.xml','<ss:worksheet xmlns:ss="'.$ns.'"><ss:sheetData>'.$cells.'</ss:sheetData></ss:worksheet>');
    } finally {$z->close();}
    try {return file_get_contents($path);}finally{unlink($path);}
}
