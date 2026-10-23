<?php
declare(strict_types=1);
/**
 * Pruebas de extractor independientes de S3/Bedrock/MySQL.
 * Ejecutar: php michat/tests/multiformat_rag_extractor_test.php
 */
require_once dirname(__DIR__).'/includes/session_file_extractor.php';

$fail = [];
$check = static function(bool $ok, string $msg) use (&$fail): void {
    if (!$ok) $fail[] = $msg;
};

$check(in_array('db', idx_extractable_extensions(), true), 'Extensión db');
$check(in_array('sqlite3', idx_extractable_extensions(), true), 'Extensión sqlite3');
$check(in_array('pptx', idx_extractable_extensions(), true), 'Presentaciones');
$check(in_array('xlsx', idx_extractable_extensions(), true), 'Hojas de cálculo');
$check(in_array('sql', idx_text_extensions(), true), 'Volcados SQL');
$check(in_array('jsonl', idx_text_extensions(), true), 'JSON Lines');

$longCodeLine = str_repeat('A', 151);
$chunks = idx_chunk_text_preserve_lines($longCodeLine, 40);
$check(count($chunks) === 4, 'Línea de código larga se corta en cuatro chunks');
$check(implode('', $chunks) === $longCodeLine, 'No se pierde contenido al cortar línea');
foreach ($chunks as $c) $check(mb_strlen($c) <= 40, 'Ningún chunk supera su límite');

$pages = idx_pdf_page_labels("Portada\fResultados\fConclusiones");
$check(str_contains($pages, '[Página 1]') && str_contains($pages, '[Página 3]'),
    'Las páginas PDF conservan localizadores');

if (class_exists('ZipArchive')) {
    $xlsx = tempnam(sys_get_temp_dir(), 'rag_xlsx_');
    $z = new ZipArchive();
    if ($z->open($xlsx, ZipArchive::OVERWRITE) === true) {
        $z->addFromString('xl/sharedStrings.xml', '<sst><si><t>Ventas</t></si></sst>');
        $z->addFromString('xl/worksheets/sheet1.xml',
            '<worksheet><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1"><f>1+2</f><v>3</v></c></row></sheetData></worksheet>');
        $z->close();
        $extractor = ''; $error = '';
        $text = idx_extract_xlsx($xlsx, $extractor, $error);
        $check(str_contains($text, 'A1: Ventas'), 'XLSX conserva coordenadas');
        $check(str_contains($text, 'fórmula =1+2'), 'XLSX incluye fórmula');
    } else $check(false, 'Se pudo generar XLSX de prueba');
    @unlink($xlsx);
}

if (class_exists('SQLite3')) {
    $tmp = tempnam(sys_get_temp_dir(), 'rag_sqlite_');
    $db = new SQLite3($tmp, SQLITE3_OPEN_READWRITE);
    $db->exec('CREATE TABLE clientes (id INTEGER PRIMARY KEY, nombre TEXT, ciudad TEXT)');
    $db->exec("INSERT INTO clientes (nombre, ciudad) VALUES ('Ana', 'México')");
    $db->close();
    $extractor = ''; $error = '';
    $text = idx_extract_sqlite($tmp, $extractor, $error);
    $check($extractor === 'sqlite3-readonly-sample', 'SQLite se abre con extractor específico');
    $check(str_contains($text, '[Tabla: clientes]'), 'Esquema SQLite');
    $check(str_contains($text, 'nombre=Ana'), 'Datos SQLite');
    $check(str_contains($text, 'ciudad=México'), 'Texto UTF-8 SQLite');
    $db = new SQLite3($tmp, SQLITE3_OPEN_READONLY);
    $check((int)$db->querySingle('SELECT COUNT(*) FROM clientes') === 1, 'Base de datos intacta');
    $db->close();
    @unlink($tmp);

    $tmp = tempnam(sys_get_temp_dir(), 'rag_sqlite_');
    $db = new SQLite3($tmp, SQLITE3_OPEN_READWRITE);
    $db->exec('CREATE TABLE registros (valor TEXT)');
    for ($i = 0; $i < 45; $i++) $db->exec("INSERT INTO registros(valor) VALUES ('dato')");
    $db->close();
    $extractor = ''; $error = '';
    $text = idx_extract_sqlite($tmp, $extractor, $error);
    $check(str_contains($text, '[MUESTRA PARCIAL:'), 'SQLite informa datos no incluidos');
    @unlink($tmp);
}

if ($fail) {
    fwrite(STDERR, "FALLARON pruebas multiformato:\n - ".implode("\n - ", $fail)."\n");
    exit(1);
}
echo "Multiformato RAG extractor: OK\n";
