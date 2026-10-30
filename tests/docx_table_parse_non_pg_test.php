<?php

declare(strict_types=1);

$root = getenv('SANDPACKAGE_TEST_ROOT') ?: dirname(__DIR__);
foreach ([
    'ParseDriver.php', 'ParseInput.php', 'ParseResult.php',
    'ParsedSourceBlock.php', 'ParseException.php', 'DocxParseDriver.php',
] as $file) {
    $class = 'app\\Infrastructure\\Parsing\\' . substr($file, 0, -4);
    if (!class_exists($class) && !interface_exists($class)) {
        require $root . '/app/Infrastructure/Parsing/' . $file;
    }
}

use app\Infrastructure\Parsing\DocxParseDriver;
use app\Infrastructure\Parsing\ParseException;
use app\Infrastructure\Parsing\ParseInput;

$paragraph = static fn (string $text): string => '<w:p><w:r><w:t>' . $text . '</w:t></w:r></w:p>';
$table = '<w:tbl>'
    . '<w:tr><w:tc>' . $paragraph('姓名') . '</w:tc><w:tc>' . $paragraph('金额') . '</w:tc></w:tr>'
    . '<w:tr><w:tc>' . $paragraph('甲') . '</w:tc><w:tc>' . $paragraph('100') . '</w:tc></w:tr>'
    . '</w:tbl>';
$parse = static function (string $body): \app\Infrastructure\Parsing\ParseResult {
    $path = tempnam(sys_get_temp_dir(), 'sand-ai-docx-');
    if ($path === false) {
        throw new RuntimeException('Could not create DOCX fixture');
    }
    $zip = new ZipArchive();
    $zipOpen = false;
    try {
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not open DOCX fixture');
        }
        $zipOpen = true;
        $zip->addFromString('word/document.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:body>' . $body . '</w:body></w:document>');
        $zip->close();
        $zipOpen = false;
        return (new DocxParseDriver())->parse(new ParseInput($path, 'docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
    } finally {
        if ($zipOpen) {
            $zip->close();
        }
        unlink($path);
    }
};

$result = $parse($paragraph('前言') . $table . $paragraph('结尾'));
if (array_map(static fn ($block): string => $block->locatorType, $result->blocks)
    !== ['paragraph', 'table_row', 'table_row', 'paragraph']) {
    throw new RuntimeException('DOCX body order or table rows were lost');
}
if ($result->blocks[0]->content !== '前言'
    || $result->blocks[1]->locator !== ['table' => 1, 'row' => 1]
    || json_decode($result->blocks[1]->content, true, 512, JSON_THROW_ON_ERROR) !== ['A1' => '姓名', 'B1' => '金额']
    || json_decode($result->blocks[2]->content, true, 512, JSON_THROW_ON_ERROR) !== ['A2' => '甲', 'B2' => '100']
    || $result->blocks[3]->content !== '结尾') {
    throw new RuntimeException('DOCX table locations or text were incorrect');
}
$onlyTable = $parse($table);
if (count($onlyTable->blocks) !== 2 || $onlyTable->blocks[0]->locatorType !== 'table_row') {
    throw new RuntimeException('A table-only DOCX was treated as empty');
}
try {
    $parse('<w:tbl><w:tr><w:tc>' . $paragraph('外层') . $table . '</w:tc></w:tr></w:tbl>');
    throw new RuntimeException('Nested DOCX table content was silently omitted');
} catch (ParseException $exception) {
    if ($exception->errorCode !== 'SAND_AI_PARSE_UNSUPPORTED') {
        throw $exception;
    }
}

echo 'docx-table-parse 4/4' . PHP_EOL;
