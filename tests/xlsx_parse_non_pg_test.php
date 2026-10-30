<?php

declare(strict_types=1);

$root = getenv('SANDPACKAGE_TEST_ROOT') ?: dirname(__DIR__);
foreach (['ParseException', 'ParseDriver', 'ParseInput', 'ParsedSourceBlock', 'ParseResult', 'XlsxParseDriver', 'ParseDriverFactory'] as $name) {
    require $root . '/app/Infrastructure/Parsing/' . $name . '.php';
}
require $root . '/app/Api/Support/ApiProblem.php';
require $root . '/app/Infrastructure/Capability/CapabilityDriverRegistry.php';

use app\Infrastructure\Capability\CapabilityDriverRegistry;
use app\Infrastructure\Parsing\ParseDriverFactory;
use app\Infrastructure\Parsing\ParseException;
use app\Infrastructure\Parsing\ParseInput;
use app\Infrastructure\Parsing\XlsxParseDriver;

if (!function_exists('env')) {
    function env(string $name, mixed $default = null): mixed
    {
        return $default;
    }
}
$mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
$allowed = (require $root . '/config/sand_ai_file.php')['allowed']['xlsx'] ?? [];
$mediaTypes = CapabilityDriverRegistry::parseDriver('document_parse', 'native_document_parse')['media_types'] ?? [];
if (!in_array($mime, $allowed, true) || !in_array('application/zip', $allowed, true)
    || !in_array($mime, $mediaTypes, true) || !in_array('application/zip', $mediaTypes, true)) {
    throw new RuntimeException('XLSX upload or private parse capability is not registered');
}

$path = tempnam(sys_get_temp_dir(), 'sandai_xlsx_');
if ($path === false) {
    throw new RuntimeException('XLSX fixture could not be created');
}
$sheet2 = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>
<row r="2"><c r="A2" t="s"><v>0</v></c><c r="B2" t="inlineStr"><is><r><t>表</t></r><r><t>格</t></r></is></c><c r="C2"><v>12.5</v></c><c r="D2" t="b"><v>1</v></c></row>
<row r="5"><c r="A5" t="inlineStr"><is><t>完成</t></is></c></row>
</sheetData></worksheet>
XML;
$sheet1 = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="s"><v>1</v></c></row></sheetData></worksheet>
XML;
$relationships = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId7" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>
<Relationship Id="rId8" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
<Relationship Id="rId9" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet3.xml"/>
</Relationships>
XML;
$parts = [
    '[Content_Types].xml' => '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
    'xl/workbook.xml' => <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>
<sheet name="资料" sheetId="5" r:id="rId7"/><sheet name="隐藏" sheetId="6" state="hidden" r:id="rId9"/><sheet name="第二表" sheetId="7" r:id="rId8"/>
</sheets></workbook>
XML,
    'xl/_rels/workbook.xml.rels' => $relationships,
    'xl/sharedStrings.xml' => <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><r><t>合</t></r><r><t>同</t></r><rPh><t>拼音不应混入</t></rPh></si><si><t>证据</t></si></sst>
XML,
    'xl/worksheets/sheet1.xml' => $sheet1,
    'xl/worksheets/sheet2.xml' => $sheet2,
    'xl/worksheets/sheet3.xml' => '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1"><f>1+1</f><v>2</v></c></row></sheetData></worksheet>',
];
$write = static function (array $files) use ($path): void {
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('XLSX fixture archive could not be written');
    }
    foreach ($files as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->close();
};

try {
    $write($parts);
    $driver = ParseDriverFactory::forExtension('XLSX');
    if (!$driver instanceof XlsxParseDriver) {
        throw new RuntimeException('XLSX extension did not select the table parser');
    }
    $result = $driver->parse(new ParseInput($path, 'xlsx', $mime));
    if (count($result->blocks) !== 3
        || $result->blocks[0]->locator !== ['sheet' => '资料', 'row' => 2]
        || $result->blocks[1]->locator !== ['sheet' => '资料', 'row' => 5]
        || $result->blocks[2]->locator !== ['sheet' => '第二表', 'row' => 1]
        || json_decode($result->blocks[0]->content, true, 512, JSON_THROW_ON_ERROR) !== ['A2' => '合同', 'B2' => '表格', 'C2' => '12.5', 'D2' => 'TRUE']
        || json_decode($result->blocks[2]->content, true, 512, JSON_THROW_ON_ERROR) !== ['A1' => '证据']
        || $result->blocks[0]->locatorType !== 'table_row') {
        throw new RuntimeException('XLSX sheets, shared strings, values or coordinates were not preserved');
    }

    $formula = str_replace('<c r="C2"><v>12.5</v></c>', '<c r="C2"><f>1+1</f><v>2</v></c>', $sheet2);
    $write([...$parts, 'xl/worksheets/sheet2.xml' => $formula]);
    try {
        $driver->parse(new ParseInput($path, 'xlsx', $mime));
        throw new RuntimeException('Unverified XLSX formula result was accepted');
    } catch (ParseException $exception) {
        if ($exception->errorCode !== 'SAND_AI_PARSE_UNSUPPORTED' || $exception->retryable()) {
            throw $exception;
        }
    }
    if ((new ParseException('SAND_AI_OCR_UNAVAILABLE', 'test'))->retryable() !== true
        || (new ParseException('SAND_AI_PARSE_EMPTY', 'test'))->retryable() !== false) {
        throw new RuntimeException('Parse retry policy does not separate temporary and terminal input failures');
    }

    $external = str_replace('Target="worksheets/sheet2.xml"', 'Target="https://example.invalid/sheet.xml" TargetMode="External"', $relationships);
    $write([...$parts, 'xl/_rels/workbook.xml.rels' => $external]);
    try {
        $driver->parse(new ParseInput($path, 'xlsx', $mime));
        throw new RuntimeException('External XLSX worksheet relationship was accepted');
    } catch (ParseException $exception) {
        if ($exception->errorCode !== 'SAND_AI_PARSE_FAILED') {
            throw $exception;
        }
    }

    file_put_contents($path, 'not an XLSX archive');
    try {
        $driver->parse(new ParseInput($path, 'xlsx', $mime));
        throw new RuntimeException('Non-XLSX data was accepted');
    } catch (ParseException $exception) {
        if ($exception->errorCode !== 'SAND_AI_PARSE_FAILED') {
            throw $exception;
        }
    }

    echo "xlsx-parse 6/6\n";
} finally {
    unlink($path);
}
