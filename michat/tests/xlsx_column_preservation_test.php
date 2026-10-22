<?php
declare(strict_types=1);

// Standalone XLSX regression test. Uses real ZipArchive when available and a
// narrow archive stub otherwise, so the extractor can be tested on minimal CI.
if (!class_exists('ZipArchive')) {
    class ZipArchive {
        public int $numFiles = 0;
        private array $entries = [];

        public function open(string $path): bool {
            $json = @file_get_contents($path);
            $entries = is_string($json) ? json_decode($json, true) : null;
            if (!is_array($entries)) return false;
            $this->entries = $entries;
            $this->numFiles = count($entries);
            return true;
        }

        public function getFromName(string $name): string|false {
            return $this->entries[$name] ?? false;
        }

        public function getNameIndex(int $index): string|false {
            return array_keys($this->entries)[$index] ?? false;
        }

        public function close(): bool {
            return true;
        }
    }
}

require_once __DIR__ . '/../includes/session_file_extractor.php';

$entries = [
    'xl/sharedStrings.xml' => '<sst><si><t>Debit</t></si><si><t>Credit</t></si><si><t>Description</t></si></sst>',
    'xl/worksheets/sheet1.xml' => '<worksheet><sheetData>'
        . '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c></row>'
        . '<row r="2"><c r="A2"><v>100</v></c><c r="C2" t="inlineStr"><is><t>Rent</t></is></c></row>'
        . '<row r="3"><c r="B3"><v>100</v></c><c r="C3" t="inlineStr"><is><t>Refund</t></is></c></row>'
        . '<row r="4"><c r="A4"><v>0</v></c><c r="B4"><v>0</v></c></row>'
        . '<row r="6"><c r="AA6"><v>42</v></c><c r="AB6"><v>43</v></c></row>'
        . '<row r="7"><c><v>5</v></c><c/><c><v>9</v></c></row>'
        . '</sheetData></worksheet>',
];

$tmp = tempnam(sys_get_temp_dir(), 'michat-xlsx-');
if ($tmp === false) {
    fwrite(STDERR, "FAIL unable to create temporary fixture\n");
    exit(1);
}
try {
    if (extension_loaded('zip')) {
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create XLSX fixture.');
        }
        foreach ($entries as $name => $xml) {
            if (!$zip->addFromString($name, $xml)) {
                throw new RuntimeException('Unable to add XLSX fixture entry.');
            }
        }
        $zip->close();
    } else {
        file_put_contents($tmp, json_encode($entries, JSON_THROW_ON_ERROR));
    }

    $extractor = '';
    $error = '';
    $actual = idx_extract_xlsx($tmp, $extractor, $error);
    $expected = implode("\n", [
        '--- Hoja sheet1 ---',
        'A1: Debit | B1: Credit | C1: Description',
        'A2: 100 | C2: Rent',
        'B3: 100 | C3: Refund',
        'A4: 0 | B4: 0',
        'AA6: 42 | AB6: 43',
        'A7: 5 | C7: 9',
    ]);

    if ($extractor !== 'xlsx-xml' || $error !== '' || $actual !== $expected) {
        fwrite(STDERR, "FAIL XLSX positional extraction\nExpected:\n{$expected}\nActual:\n{$actual}\nError: {$error}\n");
        exit(1);
    }
    echo "PASS XLSX columns, sparse cells, headers, zeroes and row coordinates\n";
} finally {
    @unlink($tmp);
}
