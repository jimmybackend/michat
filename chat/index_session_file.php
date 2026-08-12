<?php
// index_session_file.php
// Indexa un adjunto de sesión (chat simple sin proyecto):
//  - Extrae texto de archivos planos, código, PDF, Office, OpenOffice, etc.
//  - Chunkea el texto (~2000 caracteres)
//  - Inserta bloques 'file_chunk' en SessionContextBlocks (is_locked=1)
//  - Encola EmbeddingJobs 'session_block' para el cron
//  - extracion en cascada
//      pdftotext instalado en el servidor.
//          smalot/pdfparser vía Composer.
//              AWS Textract como fallback.

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/app_bootstrap.php';
require_once __DIR__ . '/S3Manager.php';

define('IDX_MAX_EXTRACTED_CHARS', 200000);
define('IDX_CHUNK_MAX_LEN', 2000);

function jexit($arr, $code = 200) {
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

function next_id(mysqli $db, $table, $col) {
    $table = preg_replace('/[^A-Za-z0-9_]+/', '', $table);
    $col   = preg_replace('/[^A-Za-z0-9_]+/', '', $col);

    $rs = $db->query("SELECT COALESCE(MAX($col),0)+1 AS nxt FROM $table");
    if (!$rs) return 1;

    $row = $rs->fetch_assoc();
    return (int)($row['nxt'] ?? 1);
}

function idx_safe_filename(string $name): string {
    $base = basename($name);
    $base = preg_replace('/[^A-Za-z0-9._-]+/', '_', $base);
    return $base !== '' ? $base : 'archivo';
}

function idx_rrmdir(string $path): void {
    if (is_file($path)) {
        @unlink($path);
        return;
    }

    if (!is_dir($path)) return;

    $items = @scandir($path) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        idx_rrmdir($path . '/' . $item);
    }

    @rmdir($path);
}

function idx_normalize_extracted_text(string $text): string {
    if ($text === '') return '';

    if (!mb_check_encoding($text, 'UTF-8')) {
        $converted = @mb_convert_encoding($text, 'UTF-8', 'auto');
        if (is_string($converted)) $text = $converted;
    }

    $text = str_replace(["\r\n", "\r"], "\n", $text);

    $tmp = @preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
    $text = ($tmp === null) ? preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text) : $tmp;

    $tmp = @preg_replace('/[ \t]+/u', ' ', $text);
    $text = ($tmp === null) ? preg_replace('/[ \t]+/', ' ', $text) : $tmp;

    $tmp = @preg_replace('/ ?\n ?/u', "\n", $text);
    $text = ($tmp === null) ? preg_replace('/ ?\n ?/', "\n", $text) : $tmp;

    $tmp = @preg_replace('/\n{3,}/u', "\n\n", $text);
    $text = ($tmp === null) ? preg_replace('/\n{3,}/', "\n\n", $text) : $tmp;

    return trim($text);
}

function idx_xml_decode(string $s): string {
    $s = preg_replace('/<!\[CDATA\[(.*?)\]\]>/is', '$1', $s);
    return html_entity_decode($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function idx_shell_enabled(): bool {
    if (!function_exists('shell_exec') || !is_callable('shell_exec')) return false;

    $disabled = strtolower((string)ini_get('disable_functions'));
    if ($disabled !== '') {
        $disabledArr = array_map('trim', explode(',', $disabled));
        if (in_array('shell_exec', $disabledArr, true)) return false;
    }

    $suhosin = strtolower((string)ini_get('suhosin.executor.func.blacklist'));
    if ($suhosin !== '') {
        $suhosinArr = array_map('trim', explode(',', $suhosin));
        if (in_array('shell_exec', $suhosinArr, true)) return false;
    }

    return true;
}

function idx_command_exists(string $cmd): bool {
    if (!idx_shell_enabled()) return false;

    $out = @shell_exec('command -v ' . escapeshellarg($cmd) . ' 2>/dev/null');
    return is_string($out) && trim($out) !== '';
}

function idx_aws_credentials_or_throw(): array {
    $ak = getenv('AWS_ACCESS_KEY_ID') ?: (defined('Config::ACCESS_KEY') ? Config::ACCESS_KEY : '');
    $sk = getenv('AWS_SECRET_ACCESS_KEY') ?: (defined('Config::SECRET_KEY') ? Config::SECRET_KEY : '');

    $ak = is_string($ak) ? trim($ak) : '';
    $sk = is_string($sk) ? trim($sk) : '';

    if ($ak === '' || $sk === '') {
        throw new RuntimeException('Faltan credenciales AWS para usar Textract. Define AWS_ACCESS_KEY_ID/AWS_SECRET_ACCESS_KEY o Config::ACCESS_KEY/Config::SECRET_KEY.');
    }

    return ['key' => $ak, 'secret' => $sk];
}

function idx_textract_detect_text(string $bucket, string $s3Key): string {
    $creds = idx_aws_credentials_or_throw();

    $region = (class_exists('Config') && defined('Config::REGION') && Config::REGION)
        ? Config::REGION
        : 'us-east-1';

    $textract = new Aws\Textract\TextractClient([
        'region'      => $region,
        'version'     => 'latest',
        'credentials' => $creds,
        'http'        => ['connect_timeout' => 15, 'timeout' => 120],
    ]);

    $res = $textract->detectDocumentText([
        'Document' => [
            'S3Object' => [
                'Bucket' => $bucket,
                'Name'   => $s3Key,
            ]
        ]
    ]);

    $lines = [];
    foreach (($res['Blocks'] ?? []) as $b) {
        if (($b['BlockType'] ?? '') === 'LINE' && !empty($b['Text'])) {
            $lines[] = $b['Text'];
        }
    }

    return implode("\n", $lines);
}

function idx_extract_pdf(string $tmp, string $bucket, string $s3Key, string &$extractor, string &$error): string {
    // 1) Parser PHP opcional: composer require smalot/pdfparser
    if (class_exists('Smalot\PdfParser\Parser')) {
        try {
            $parser = new Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($tmp);
            $text = (string)$pdf->getText();

            if (trim($text) !== '') {
                $extractor = 'pdfparser-php';
                return $text;
            }
        } catch (Throwable $e) {
            $error = 'PDFParser PHP: ' . $e->getMessage();
        }
    }

    // 2) pdftotext / poppler-utils
    if (idx_command_exists('pdftotext')) {
        $out = $tmp . '.txt';

        @shell_exec('pdftotext -enc UTF-8 ' . escapeshellarg($tmp) . ' ' . escapeshellarg($out) . ' 2>/dev/null');

        if (is_file($out)) {
            $text = (string)file_get_contents($out);
            @unlink($out);

            if (trim($text) !== '') {
                $extractor = 'pdftotext';
                return $text;
            }
        }
    }

    // 3) AWS Textract
    if (class_exists('Aws\Textract\TextractClient')) {
        try {
            $text = idx_textract_detect_text($bucket, $s3Key);

            if (trim($text) !== '') {
                $extractor = 'aws-textract';
                return $text;
            }
        } catch (Throwable $e) {
            $error = 'Textract: ' . $e->getMessage();
        }
    }

    if ($error === '') {
        $error = 'No se pudo extraer texto del PDF. Instala pdftotext/poppler-utils o smalot/pdfparser, o habilita AWS Textract.';
    }

    return '';
}

function idx_extract_docx(string $tmp, string &$extractor, string &$error): string {
    if (!class_exists('ZipArchive')) {
        $error = 'ZipArchive no está disponible para leer DOCX.';
        return '';
    }

    $zip = new ZipArchive();

    if ($zip->open($tmp) !== true) {
        $error = 'No se pudo abrir el DOCX.';
        return '';
    }

    $documentXml = $zip->getFromName('word/document.xml');

    if ($documentXml === false) {
        $zip->close();
        $error = 'El DOCX no contiene word/document.xml.';
        return '';
    }

    $xmlParts = [$documentXml];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);

        if (preg_match('#^word/(header|footer)\d*\.xml$#', $name)) {
            $xml = $zip->getFromName($name);
            if ($xml !== false) $xmlParts[] = $xml;
        }
    }

    $zip->close();

    $lines = [];

    foreach ($xmlParts as $xml) {
        $xml = preg_replace('/<w:tab[^>]*\/>/u', "\t", $xml);
        $xml = preg_replace('/<w:br[^>]*\/>/u', "\n", $xml);

        $paragraphs = preg_split('/<\/w:p>/s', $xml);

        foreach ($paragraphs as $paragraph) {
            if (preg_match_all('/<w:t(?:\s[^>]*)?>(.*?)<\/w:t>/s', $paragraph, $m)) {
                $line = idx_xml_decode(implode('', $m[1]));
                $line = trim($line);

                if ($line !== '') $lines[] = $line;
            }
        }
    }

    $extractor = 'docx-xml';

    return implode("\n", $lines);
}

function idx_extract_pptx(string $tmp, string &$extractor, string &$error): string {
    if (!class_exists('ZipArchive')) {
        $error = 'ZipArchive no está disponible para leer PPTX.';
        return '';
    }

    $zip = new ZipArchive();

    if ($zip->open($tmp) !== true) {
        $error = 'No se pudo abrir el PPTX.';
        return '';
    }

    $slides = [];
    $notes = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);

        if (preg_match('#^ppt/slides/slide(\d+)\.xml$#', $name, $m)) {
            $slides[(int)$m[1]] = $name;
        } elseif (preg_match('#^ppt/notesSlides/notesSlide(\d+)\.xml$#', $name, $m)) {
            $notes[(int)$m[1]] = $name;
        }
    }

    ksort($slides);
    ksort($notes);

    $lines = [];

    foreach ($slides as $slideNumber => $slideName) {
        $xml = $zip->getFromName($slideName);
        if ($xml === false) continue;

        $lines[] = "[Slide {$slideNumber}]";

        $paragraphs = preg_split('/<\/a:p>/s', $xml);

        foreach ($paragraphs as $paragraph) {
            if (preg_match_all('/<a:t(?:\s[^>]*)?>(.*?)<\/a:t>/s', $paragraph, $m)) {
                $line = idx_xml_decode(implode('', $m[1]));
                $line = trim($line);

                if ($line !== '') $lines[] = $line;
            }
        }

        if (!empty($notes[$slideNumber])) {
            $noteXml = $zip->getFromName($notes[$slideNumber]);

            if ($noteXml !== false) {
                $noteLines = [];
                $noteParagraphs = preg_split('/<\/a:p>/s', $noteXml);

                foreach ($noteParagraphs as $paragraph) {
                    if (preg_match_all('/<a:t(?:\s[^>]*)?>(.*?)<\/a:t>/s', $paragraph, $m)) {
                        $line = idx_xml_decode(implode('', $m[1]));
                        $line = trim($line);

                        if ($line !== '') $noteLines[] = $line;
                    }
                }

                if (!empty($noteLines)) {
                    $lines[] = '[Notas]';
                    $lines = array_merge($lines, $noteLines);
                }
            }
        }
    }

    $zip->close();

    $extractor = 'pptx-xml';

    return implode("\n", $lines);
}

function idx_extract_xlsx(string $tmp, string &$extractor, string &$error): string {
    if (!class_exists('ZipArchive')) {
        $error = 'ZipArchive no está disponible para leer XLSX.';
        return '';
    }

    $zip = new ZipArchive();

    if ($zip->open($tmp) !== true) {
        $error = 'No se pudo abrir el XLSX.';
        return '';
    }

    $sharedStrings = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');

    if ($sharedXml !== false) {
        if (preg_match_all('/<si>(.*?)<\/si>/s', $sharedXml, $siMatches)) {
            foreach ($siMatches[1] as $siXml) {
                if (preg_match_all('/<t(?:\s[^>]*)?>(.*?)<\/t>/s', $siXml, $tMatches)) {
                    $sharedStrings[] = idx_xml_decode(implode('', $tMatches[1]));
                } else {
                    $sharedStrings[] = '';
                }
            }
        }
    }

    $sheets = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);

        if (preg_match('#^xl/worksheets/sheet(\d+)\.xml$#', $name, $m)) {
            $sheets[(int)$m[1]] = $name;
        }
    }

    ksort($sheets);

    $out = [];

    foreach ($sheets as $sheetNumber => $sheetName) {
        $xml = $zip->getFromName($sheetName);
        if ($xml === false) continue;

        $sheetLabel = basename($sheetName, '.xml');
        $out[] = "--- Hoja {$sheetLabel} ---";

        $rows = preg_split('/<\/row>/s', $xml);

        foreach ($rows as $rowXml) {
            if (trim($rowXml) === '') continue;

            $cells = [];

            if (preg_match_all('/<c(?:\s[^>]*)?>(?:.*?)<\/c>|<c(?:\s[^>]*)?\/>/s', $rowXml, $cellMatches)) {
                foreach ($cellMatches[0] as $cellXml) {
                    $value = '';

                    if (preg_match('/t="s"/', $cellXml) && preg_match('/<v>(.*?)<\/v>/s', $cellXml, $vM)) {
                        $idx = (int)$vM[1];
                        $value = $sharedStrings[$idx] ?? '';
                    } elseif (preg_match('/<is>(.*?)<\/is>/s', $cellXml, $isM)) {
                        if (preg_match_all('/<t(?:\s[^>]*)?>(.*?)<\/t>/s', $isM[1], $tM)) {
                            $value = idx_xml_decode(implode('', $tM[1]));
                        }
                    } elseif (preg_match('/<v>(.*?)<\/v>/s', $cellXml, $vM)) {
                        $value = idx_xml_decode($vM[1]);
                    }

                    $value = trim($value);
                    if ($value !== '') $cells[] = $value;
                }
            }

            if (!empty($cells)) {
                $out[] = implode(' | ', $cells);
            }
        }
    }

    $zip->close();

    $extractor = 'xlsx-xml';

    return implode("\n", $out);
}

function idx_extract_odf(string $tmp, string &$extractor, string &$error): string {
    if (!class_exists('ZipArchive')) {
        $error = 'ZipArchive no está disponible para leer ODT/ODS/ODP.';
        return '';
    }

    $zip = new ZipArchive();

    if ($zip->open($tmp) !== true) {
        $error = 'No se pudo abrir el archivo ODF.';
        return '';
    }

    $xml = $zip->getFromName('content.xml');
    $zip->close();

    if ($xml === false) {
        $error = 'El archivo ODF no contiene content.xml.';
        return '';
    }

    // Eliminar zonas que normalmente no aportan texto útil.
    $xml = preg_replace('/<(office:styles|office:automatic-styles|office:master-styles|script:script)[^>]*>.*?<\/\1>/s', '', $xml);

    // Convertir saltos y tabulaciones ODF.
    $xml = preg_replace('/<text:tab[^>]*\/>/u', "\t", $xml);
    $xml = preg_replace('/<text:s[^>]*\/>/u', ' ', $xml);
    $xml = preg_replace('/<text:line-break[^>]*\/>/u', "\n", $xml);

    // Marcar bloques como nuevas líneas.
    $xml = preg_replace('/<(?:text:p|text:h|text:list-item|table:table-row|draw:text-box|text:section)[^>]*>/u', "\n", $xml);
    $xml = preg_replace('/<\/(?:text:p|text:h|text:list-item|table:table-row|draw:text-box|text:section)>/u', "\n", $xml);

    $text = idx_xml_decode(strip_tags($xml));

    $extractor = 'odf-xml';

    return $text;
}

function idx_extract_rtf(string $tmp, string &$extractor, string &$error): string {
    $rtf = (string)file_get_contents($tmp);

    if ($rtf === '') {
        $error = 'El archivo RTF está vacío.';
        return '';
    }

    // Unicode RTF: \u12345
    $rtf = preg_replace_callback(
        '/\\\\u(-?\d+)\s?/',
        function ($m) {
            $code = (int)$m[1];
            if ($code < 0) $code += 65536;

            if (function_exists('mb_chr')) {
                return mb_chr($code, 'UTF-8');
            }

            return '';
        },
        $rtf
    );

    // Hex escapes: \'e1
    $rtf = preg_replace_callback(
        '/\\\\\'([0-9a-fA-F]{2})/',
        function ($m) {
            $code = hexdec($m[1]);
            if ($code < 32 || $code === 127) return '';
            return chr($code);
        },
        $rtf
    );

    // Saltos de párrafo/línea.
    $rtf = preg_replace('/\\\\par\b\s?/', "\n", $rtf);
    $rtf = preg_replace('/\\\\line\b\s?/', "\n", $rtf);

    // Eliminar controles RTF.
    $rtf = preg_replace('/\\\\[a-zA-Z]+-?\d*\s?/', '', $rtf);

    // Eliminar llaves.
    $rtf = str_replace(['{', '}'], '', $rtf);

    $extractor = 'rtf-basic';

    return $rtf;
}

function idx_extract_epub(string $tmp, string &$extractor, string &$error): string {
    if (!class_exists('ZipArchive')) {
        $error = 'ZipArchive no está disponible para leer EPUB.';
        return '';
    }

    $zip = new ZipArchive();

    if ($zip->open($tmp) !== true) {
        $error = 'No se pudo abrir el EPUB.';
        return '';
    }

    $out = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);

        if (strpos($name, 'META-INF/') === 0) continue;
        if (!preg_match('/\.(x?html?|xml)$/i', $name)) continue;

        $xml = $zip->getFromName($name);
        if ($xml === false) continue;

        $xml = preg_replace('/<(script|style)[^>]*>.*?<\/\1>/is', '', $xml);
        $xml = preg_replace('/<(\/?)(p|div|h[1-6]|li|br|tr|section|article)[^>]*>/i', "\n", $xml);

        $text = idx_xml_decode(strip_tags($xml));
        $text = trim($text);

        if ($text !== '') $out[] = $text;
    }

    $zip->close();

    $extractor = 'epub-html';

    return implode("\n\n", $out);
}

function idx_extract_legacy_office(string $tmp, string $ext, string $filename, string &$extractor, string &$error): string {
    if (!idx_shell_enabled()) {
        $error = "El formato antiguo .{$ext} requiere un conversor externo, pero shell_exec no está disponible.";
        return '';
    }

    // Conversores clásicos opcionales.
    if ($ext === 'doc') {
        if (idx_command_exists('antiword')) {
            $text = @shell_exec('antiword ' . escapeshellarg($tmp) . ' 2>/dev/null');
            if (trim((string)$text) !== '') {
                $extractor = 'antiword';
                return (string)$text;
            }
        }

        if (idx_command_exists('catdoc')) {
            $text = @shell_exec('catdoc ' . escapeshellarg($tmp) . ' 2>/dev/null');
            if (trim((string)$text) !== '') {
                $extractor = 'catdoc';
                return (string)$text;
            }
        }
    }

    if ($ext === 'xls') {
        if (idx_command_exists('xls2csv')) {
            $text = @shell_exec('xls2csv ' . escapeshellarg($tmp) . ' 2>/dev/null');
            if (trim((string)$text) !== '') {
                $extractor = 'xls2csv';
                return (string)$text;
            }
        }
    }

    if ($ext === 'ppt') {
        if (idx_command_exists('catppt')) {
            $text = @shell_exec('catppt ' . escapeshellarg($tmp) . ' 2>/dev/null');
            if (trim((string)$text) !== '') {
                $extractor = 'catppt';
                return (string)$text;
            }
        }
    }

    // Fallback genérico con LibreOffice headless.
    $soffice = null;

    if (idx_command_exists('soffice')) {
        $soffice = 'soffice';
    } elseif (idx_command_exists('libreoffice')) {
        $soffice = 'libreoffice';
    }

    if ($soffice !== null) {
        $outdir = dirname($tmp) . '/lo_' . bin2hex(random_bytes(4));
        @mkdir($outdir, 0775, true);

        $cmd = escapeshellarg($soffice)
            . ' --headless --norestore --convert-to txt --outdir '
            . escapeshellarg($outdir)
            . ' ' . escapeshellarg($tmp)
            . ' 2>/dev/null';

        @shell_exec($cmd);

        $base = pathinfo(basename($tmp), PATHINFO_FILENAME);
        $txt = $outdir . '/' . $base . '.txt';

        if (is_file($txt)) {
            $text = (string)file_get_contents($txt);
            idx_rrmdir($outdir);

            if (trim($text) !== '') {
                $extractor = 'libreoffice';
                return $text;
            }
        }

        idx_rrmdir($outdir);
    }

    $error = "No hay conversor disponible para .{$ext}. Instala antiword/catdoc/xls2csv/catppt/LibreOffice, o usa un formato moderno como docx/xlsx/pptx/odt.";

    return '';
}

function idx_extract_file(
    string $tmp,
    string $ext,
    string $filename,
    string $bucket,
    string $s3Key,
    string &$extractor,
    string &$error
): string {
    $extractor = '';
    $error = '';
    $text = '';

    switch ($ext) {
        case 'pdf':
            $text = idx_extract_pdf($tmp, $bucket, $s3Key, $extractor, $error);
            break;

        case 'docx':
        case 'dotx':
            $text = idx_extract_docx($tmp, $extractor, $error);
            break;

        case 'pptx':
        case 'ppsx':
            $text = idx_extract_pptx($tmp, $extractor, $error);
            break;

        case 'xlsx':
        case 'xlsm':
        case 'xltx':
        case 'xltm':
            $text = idx_extract_xlsx($tmp, $extractor, $error);
            break;

        case 'odt':
        case 'ods':
        case 'odp':
            $text = idx_extract_odf($tmp, $extractor, $error);
            break;

        case 'rtf':
            $text = idx_extract_rtf($tmp, $extractor, $error);
            break;

        case 'epub':
            $text = idx_extract_epub($tmp, $extractor, $error);
            break;

        case 'doc':
        case 'xls':
        case 'ppt':
            $text = idx_extract_legacy_office($tmp, $ext, $filename, $extractor, $error);
            break;

        default:
            $error = 'Extractor no soportado para extensión .' . $ext;
            return '';
    }

    if (trim($text) === '') {
        if ($error === '') {
            $error = 'El archivo no produjo texto extraíble.';
        }

        return '';
    }

    return idx_normalize_extracted_text($text);
}

function idx_chunk_text_preserve_lines(string $content, int $max_len): array {
    if (mb_strlen($content) <= $max_len) {
        return [$content];
    }

    $chunks = [];
    $cur = '';

    $lines = explode("\n", $content);

    foreach ($lines as $l) {
        if (mb_strlen($cur . "\n" . $l) > $max_len && trim($cur) !== '') {
            $chunks[] = trim($cur);
            $cur = $l;
        } else {
            $cur .= ($cur === '' ? '' : "\n") . $l;
        }
    }

    if (trim($cur) !== '') {
        $chunks[] = trim($cur);
    }

    return $chunks;
}

function idx_chunk_text_smart(string $content, int $maxLen): array {
    $content = trim($content);

    if ($content === '') return [];

    if (mb_strlen($content) <= $maxLen) {
        return [$content];
    }

    $separator = "\n\n";
    $pieces = @preg_split('/\n{2,}/u', $content);

    if (!is_array($pieces) || count($pieces) < 2) {
        $pieces = explode("\n", $content);
        $separator = "\n";
    }

    $chunks = [];
    $cur = '';

    foreach ($pieces as $piece) {
        $piece = trim($piece);
        if ($piece === '') continue;

        // Si la pieza es demasiado grande, dividirla por palabras.
        if (mb_strlen($piece) > $maxLen) {
            if ($cur !== '') {
                $chunks[] = $cur;
                $cur = '';
            }

            $words = preg_split('/\s+/u', $piece);
            $tmp = '';

            foreach ($words as $w) {
                $w = trim($w);
                if ($w === '') continue;

                if (mb_strlen($w) > $maxLen) {
                    if ($tmp !== '') {
                        $chunks[] = $tmp;
                        $tmp = '';
                    }

                    while (mb_strlen($w) > $maxLen) {
                        $chunks[] = mb_substr($w, 0, $maxLen);
                        $w = mb_substr($w, $maxLen);
                    }

                    if ($w !== '') $tmp = $w;
                    continue;
                }

                $candidate = ($tmp === '') ? $w : $tmp . ' ' . $w;

                if (mb_strlen($candidate) > $maxLen) {
                    $chunks[] = $tmp;
                    $tmp = $w;
                } else {
                    $tmp = $candidate;
                }
            }

            if ($tmp !== '') $cur = $tmp;
            continue;
        }

        $candidate = ($cur === '') ? $piece : $cur . $separator . $piece;

        if (mb_strlen($candidate) > $maxLen) {
            $chunks[] = $cur;
            $cur = $piece;
        } else {
            $cur = $candidate;
        }
    }

    if ($cur !== '') {
        $chunks[] = $cur;
    }

    $chunks = array_values(array_filter($chunks, function ($c) {
        return trim($c) !== '';
    }));

    return $chunks;
}

// =====================================================================
// PETICIÓN
// =====================================================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jexit(['ok' => false, 'error' => 'Método no permitido'], 405);
}

if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
    jexit(['ok' => false, 'error' => 'DB no disponible'], 500);
}

$userId = 0;

foreach (['user_id_', 'user_id', 'id_usuario', 'id_user', 'id'] as $k) {
    if (isset($_SESSION[$k]) && ctype_digit((string)$_SESSION[$k])) {
        $userId = (int)$_SESSION[$k];
        break;
    }
}

if ($userId <= 0) {
    jexit(['ok' => false, 'error' => 'Sesión inválida'], 401);
}

$file_id    = isset($_POST['file_id'])    ? (int)$_POST['file_id']    : 0;
$session_id = isset($_POST['session_id']) ? (int)$_POST['session_id'] : 0;

if ($file_id <= 0)    jexit(['ok' => false, 'error' => 'file_id inválido'], 400);
if ($session_id <= 0) jexit(['ok' => false, 'error' => 'session_id inválido'], 400);

// 1) La sesión debe ser del usuario.
$stmt = $db_connection->prepare("SELECT id_ FROM ChatSessions WHERE id_ = ? AND user_id_ = ? LIMIT 1");
$stmt->bind_param('ii', $session_id, $userId);
$stmt->execute();
$okSess = $stmt->get_result()->num_rows > 0;
$stmt->close();

if (!$okSess) {
    jexit(['ok' => false, 'error' => 'La sesión no existe o no es tuya'], 403);
}

// 2) El archivo debe ser del usuario.
$stmt = $db_connection->prepare("SELECT id_, Nombre, Encriptado, Ruta FROM FileS3 WHERE id_ = ? AND user_id_ = ? LIMIT 1");
$stmt->bind_param('ii', $file_id, $userId);
$stmt->execute();
$file = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$file) {
    jexit(['ok' => false, 'error' => 'El archivo no existe o no es tuyo'], 403);
}

// 3) Extensiones soportadas.
$TEXT_EXTS = [
    'php','phtml','inc','js','mjs','cjs','jsx','ts','tsx','css','scss','less',
    'html','htm','json','xml','yaml','yml','ini','conf','cfg','txt','md','markdown',
    'sql','sh','bash','zsh','bat','cmd','ps1','py','rb','java','c','h','cpp','hpp',
    'cs','go','rs','swift','kt','kts','vue','csv','tsv','log','srt','vtt','env',
    'gitignore','htaccess'
];

$EXTRACTABLE_EXTS = [
    'pdf',
    'docx','dotx',
    'xlsx','xlsm','xltx','xltm',
    'pptx','ppsx',
    'odt','ods','odp',
    'rtf',
    'epub',
    'doc','xls','ppt'
];

$ext = strtolower(pathinfo($file['Nombre'], PATHINFO_EXTENSION));

if (!in_array($ext, $TEXT_EXTS, true) && !in_array($ext, $EXTRACTABLE_EXTS, true)) {
    jexit([
        'ok' => false,
        'error' => 'Formato no soportado para indexación de texto: .' . $ext
    ], 422);
}

// 4) Clave S3 real.
$enc  = str_replace('\\', '/', trim((string)$file['Encriptado']));
$ruta = rtrim(str_replace('\\', '/', (string)$file['Ruta']), '/');

$s3Key = (strpos($enc, $ruta) === 0) ? $enc : ($ruta . '/' . ltrim($enc, '/'));
$s3Key = preg_replace('~^(Data\d*)/\1/~i', '$1/', $s3Key);

// 5) Descargar de S3 a archivo temporal.
$tmpDir = sys_get_temp_dir() . '/idx_sess_' . bin2hex(random_bytes(6));

if (!@mkdir($tmpDir, 0775, true)) {
    jexit(['ok' => false, 'error' => 'No se pudo crear directorio temporal de indexación'], 500);
}

$tmpFile = $tmpDir . '/' . idx_safe_filename($file['Nombre']);
$bucket = '';

try {
    $manager = new S3Manager($db_connection);
    $s3      = Config::getS3();
    $bucket  = $manager->getBucket();

    try {
        $s3->getObject([
            'Bucket' => $bucket,
            'Key'    => $s3Key,
            'SaveAs' => $tmpFile
        ]);
    } catch (Throwable $eSaveAs) {
        // Fallback por si el cliente/entorno no soporta SaveAs.
        $result  = $s3->getObject(['Bucket' => $bucket, 'Key' => $s3Key]);
        $body    = (string)($result['Body'] ?? '');
        file_put_contents($tmpFile, $body);
    }
} catch (Throwable $e) {
    idx_rrmdir($tmpDir);
    jexit(['ok' => false, 'error' => 'No se pudo leer el archivo de S3: ' . $e->getMessage()], 500);
}

if (!is_file($tmpFile) || filesize($tmpFile) <= 0) {
    idx_rrmdir($tmpDir);
    jexit(['ok' => false, 'error' => 'El archivo está vacío o no se descargó correctamente'], 422);
}

// 6) Extraer texto.
$extractor = 'raw_text';
$extractError = '';
$content = '';

try {
    if (in_array($ext, $TEXT_EXTS, true)) {
        $content = (string)file_get_contents($tmpFile);

        if (!mb_check_encoding($content, 'UTF-8')) {
            $converted = @mb_convert_encoding($content, 'UTF-8', 'auto');
            if (is_string($converted)) $content = $converted;
        }

        $extractor = 'raw_text';
    } else {
        $content = idx_extract_file(
            $tmpFile,
            $ext,
            $file['Nombre'],
            $bucket,
            $s3Key,
            $extractor,
            $extractError
        );
    }
} catch (Throwable $e) {
    idx_rrmdir($tmpDir);
    jexit(['ok' => false, 'error' => 'Error al extraer texto: ' . $e->getMessage()], 500);
}

idx_rrmdir($tmpDir);

if (trim($content) === '') {
    jexit([
        'ok' => false,
        'error' => $extractError ?: 'El archivo está vacío o no contiene texto extraíble'
    ], 422);
}

// 7) Limitar tamaño máximo indexable.
$truncated = false;

if (mb_strlen($content) > IDX_MAX_EXTRACTED_CHARS) {
    $content = trim(mb_substr($content, 0, IDX_MAX_EXTRACTED_CHARS));
    $truncated = true;
}

// 8) Chunking.
if (in_array($ext, $TEXT_EXTS, true)) {
    // Para código/texto plano conservamos mejor la estructura por líneas.
    $chunks = idx_chunk_text_preserve_lines($content, IDX_CHUNK_MAX_LEN);
} else {
    // Para documentos extraídos usamos chunking por párrafos/palabras.
    $chunks = idx_chunk_text_smart($content, IDX_CHUNK_MAX_LEN);
}

if (empty($chunks)) {
    jexit(['ok' => false, 'error' => 'El archivo no generó chunks indexables'], 422);
}

// 9) Borrar indexación previa de este archivo.
$stmt = $db_connection->prepare("
    DELETE FROM SessionContextBlocks
    WHERE session_id_ = ?
      AND s3_path = ?
      AND block_type IN ('file','file_chunk')
");
$stmt->bind_param('is', $session_id, $s3Key);
$stmt->execute();
$stmt->close();

// 10) Insertar bloques + encolar jobs.
$db_connection->begin_transaction();

try {
    foreach ($chunks as $i => $chunkText) {
        $blockId = next_id($db_connection, 'SessionContextBlocks', 'id_');

        $meta = json_encode([
            'filename'   => $file['Nombre'],
            'files3_id'  => $file_id,
            'chunk'      => ($i + 1),
            'total'      => count($chunks),
            'extractor'  => $extractor,
            'ext'        => $ext,
            'truncated'  => $truncated
        ], JSON_UNESCAPED_UNICODE);

        $tokens = (int)ceil(mb_strlen($chunkText) / 4);

        $stmt = $db_connection->prepare("
            INSERT INTO SessionContextBlocks (
                id_,
                session_id_,
                block_type,
                content_preview,
                s3_path,
                is_locked,
                source_ids,
                token_count
            ) VALUES (?, ?, 'file_chunk', ?, ?, 1, ?, ?)
        ");

        $stmt->bind_param('iisssi', $blockId, $session_id, $chunkText, $s3Key, $meta, $tokens);
        $stmt->execute();
        $stmt->close();

        $jobId = next_id($db_connection, 'EmbeddingJobs', 'id_');

        $stmt = $db_connection->prepare("
            INSERT INTO EmbeddingJobs (
                id_,
                target_type,
                target_id,
                model_id,
                status,
                attempts
            ) VALUES (?, 'session_block', ?, 'amazon.titan-embed-text-v2:0', 'pending', 0)
        ");

        $stmt->bind_param('ii', $jobId, $blockId);
        $stmt->execute();
        $stmt->close();
    }

    $db_connection->commit();
} catch (Throwable $e) {
    $db_connection->rollback();

    jexit([
        'ok' => false,
        'error' => 'Error al indexar: ' . $e->getMessage()
    ], 500);
}

jexit([
    'ok' => true,
    'mensaje' => 'Archivo procesado (' . $extractor . ') y dividido en ' . count($chunks) . ' chunk(s). Los embeddings se generan en segundo plano con el cron.',
    'chunks' => count($chunks),
    'extractor' => $extractor,
    'truncated' => $truncated
]);